<?php
/**
 * Box office: fast sales, receipts and storno. Registers, shifts (the "cash sessions"), counts,
 * handover and Z reports live in Registers, Shifts and ZReport; the session functions here are
 * kept as wrappers for existing callers.
 *
 * Sales use Orders::create() (same capacity locks as the online checkout, so quotas are shared) with
 * the trusted channel options external/payment_provider pos_*, key_namespace "pos", and are then
 * confirmed with Orders::confirm_external_payment(). Receipt numbers (series "receipt") are reserved
 * inside the transaction that stores the sale, so they are gapless. The form key makes a submit
 * idempotent: repeating it returns the existing sale.
 *
 * Without a buyer e-mail the order gets the placeholder boxoffice+<hash>@invalid.example; mails to
 * that reserved domain are suppressed (pre_wp_mail).
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Audit;
use PassCaptain\Invoices;
use PassCaptain\Log;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const CAP = 'wpcap_manage_orders';
	const PLACEHOLDER_DOMAIN = '@invalid.example';

	/** Payment methods: key => provider id and label. */
	public static function methods() {
		return array(
			'cash'    => array( 'provider' => 'pos_cash', 'label' => __( 'Bar', 'pass-captain' ) ),
			'card'    => array( 'provider' => 'pos_card', 'label' => __( 'Karte (Terminal)', 'pass-captain' ) ),
			'free'    => array( 'provider' => 'pos_free', 'label' => __( 'Freikarte', 'pass-captain' ) ),
			'invoice' => array( 'provider' => 'pos_invoice', 'label' => __( 'Rechnung', 'pass-captain' ) ),
		);
	}

	/** Method of asynchronous card-terminal payments (only offered when an add-on registers a device, see Devices). */
	const DEVICE_METHOD = 'card_device';

	/** methods() plus the card-terminal method (for validation, labels and the refund gateways). */
	public static function all_methods() {
		return self::methods() + array(
			self::DEVICE_METHOD => array( 'provider' => 'pos_card_device', 'label' => __( 'Karte am Terminal (automatisch)', 'pass-captain' ) ),
		);
	}

	/** Free tickets at the counter are comps: same right as the guest list approval. */
	public static function can_give_free() {
		return current_user_can( 'wpcap_approve_comps' );
	}

	/** Payment methods that need a certified fiscal solution (cash register law) in DE/AT. */
	const FISCAL_METHODS = array( 'cash', 'card', self::DEVICE_METHOD );

	/**
	 * Whether cash and card sales are blocked: the platform is in Germany or Austria, where cash register law
	 * (KassenSichV/TSE, RKSV) requires signed receipts, Pass Captain has no certified fiscal solution, and the
	 * operator has not confirmed that these sales are recorded in a certified cash register of their own.
	 * Filter `wpcap_boxoffice_fiscal_ready` (true = a fiscal integration signs the receipts); its default
	 * is pos_fiscalization_ready().
	 */
	public static function fiscal_block() {
		$country = \PassCaptain\Partners\SalesModel::platform_seller()['country'];
		if ( ! in_array( $country, array( 'DE', 'AT' ), true ) || Settings::get( 'boxoffice_cash_confirmed' ) ) { return false; }
		return ! apply_filters( 'wpcap_boxoffice_fiscal_ready', self::pos_fiscalization_ready(), $country );
	}

	/**
	 * Whether the built-in fiscalization (Modules\Fiscalization) signs box office receipts: the module is
	 * active and an active legal seller has an active production fiscal profile for the POS channel.
	 * Sales of ticket types without such a profile are then gated per order by the fiscal module itself.
	 */
	public static function pos_fiscalization_ready() {
		if ( ! class_exists( \PassCaptain\Modules\Fiscalization::class ) || ! in_array( 'PassCaptain\\Modules\\Fiscalization', \PassCaptain\Modules::classes(), true ) ) { return false; }
		global $wpdb;
		$p = $wpdb->prefix;
		$suppress = $wpdb->suppress_errors( true );
		$now = current_time( 'mysql', true );
		$ready = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i fp INNER JOIN %i s ON s.seller_id=fp.seller_id AND s.status='active' AND s.country_code=fp.country_code WHERE fp.channel='pos' AND fp.environment='production' AND fp.status='active' AND (fp.effective_from IS NULL OR fp.effective_from<=%s) AND (fp.effective_until IS NULL OR fp.effective_until>=%s) LIMIT 1", $p . 'wpcap_fiscal_profiles', $p . 'wpcap_legal_sellers', $now, $now ) );
		$wpdb->suppress_errors( $suppress );
		return $ready;
	}

	/** Methods offered for a new sale (cash/card left out while fiscal_block()). */
	public static function sale_methods() {
		$methods = self::methods();
		return self::fiscal_block() ? array_diff_key( $methods, array_flip( self::FISCAL_METHODS ) ) : $methods;
	}

	public static function method_label( $method ) {
		$methods = self::all_methods();
		return $methods[ $method ]['label'] ?? $method;
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function receipt_prefix() {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) Settings::get( 'boxoffice_receipt_prefix' ) ) ?: 'BON';
	}

	public static function is_placeholder_email( $email ) {
		return self::PLACEHOLDER_DOMAIN === strtolower( substr( (string) $email, -strlen( self::PLACEHOLDER_DOMAIN ) ) );
	}

	/** pre_wp_mail: drop mails to box office placeholder addresses. */
	public static function suppress_placeholder_mail( $return, $atts = array() ) {
		$to = $atts['to'] ?? array();
		$to = is_array( $to ) ? $to : explode( ',', (string) $to );
		if ( $to && ! array_filter( $to, static function ( $r ) { return ! self::is_placeholder_email( trim( (string) $r ) ); } ) ) {
			return true;
		}
		return $return;
	}

	/* ------------------------------------------------------------------ sessions (shifts) */

	public static function session( $session_id ) {
		return Shifts::get( $session_id );
	}

	/** Open shift the user sells in (as opener or added cashier); null while counting or closed. */
	public static function open_session_for( $user_id ) {
		return Shifts::current_for_user( $user_id, array( 'open' ) );
	}

	/**
	 * Opens a shift for the current user (legacy API without register choice): returns the user's
	 * active shift, else opens one on a free register. With a location/fiscal register (TerminalRegistry)
	 * a free register assigned to it is preferred; a register without assignment gets it for this shift.
	 */
	public static function open_session( $float_minor, $currency = 'EUR', $note = '', $location_id = 0, $register_id = '' ) {
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$existing = Shifts::current_for_user( get_current_user_id() );
		if ( $existing ) { return $existing; }
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$location_id = absint( $location_id );
		$register_id = (string) $register_id;
		if ( strlen( $register_id ) > 64 || ! preg_match( '/^[A-Za-z0-9_.:-]*$/', $register_id ) ) { return self::error( 'wpcap_pos_register_invalid', __( 'Kassen-ID ist ungültig.', 'pass-captain' ) ); }
		if ( ! TerminalRegistry::allows( $location_id, $register_id ) ) { return self::error( 'wpcap_pos_terminal', __( 'Standort und Kasse sind nicht als Register freigegeben.', 'pass-captain' ), 403 ); }
		$default = Registers::default_id( 3 === strlen( $currency ) ? $currency : 'EUR' );
		$available = Registers::available();
		$matching = array_values( array_filter( $available, static function ( $r ) use ( $location_id, $register_id ) { return Registers::terminal_scope( $r ) === array( 'location_id' => $location_id, 'fiscal_register_id' => $register_id ); } ) );
		$candidates = array_values( array_unique( array_merge( array_map( static function ( $r ) { return (int) $r->register_id; }, $matching ), array( $default ), array_map( static function ( $r ) { return (int) $r->register_id; }, $available ) ) ) );
		foreach ( $candidates as $candidate ) {
			if ( Shifts::open_for_register( $candidate ) || Registers::pending_handover( $candidate ) ) { continue; }
			$scope = Registers::terminal_scope( Registers::get( $candidate ) );
			// A register assigned to another location/fiscal register is not used for this request.
			if ( ( $scope['location_id'] || '' !== $scope['fiscal_register_id'] ) && $scope !== array( 'location_id' => $location_id, 'fiscal_register_id' => $register_id ) ) { continue; }
			return Shifts::open( $candidate, array( 'float_minor' => max( 0, (int) $float_minor ), 'note' => $note, 'location_id' => $location_id, 'fiscal_register_id' => $register_id ) );
		}
		return self::error( 'wpcap_pos_no_register', __( 'Keine freie Kasse verfügbar.', 'pass-captain' ), 409 );
	}

	/** Sale states that count in session totals (pending/failed rows have no receipt yet). */
	const BOOKED_STATES = array( 'completed', 'voided', 'storno_pending' );

	/**
	 * Totals of a shift (see ZReport::compute(); the legacy keys methods[m].count/gross/voided_count/
	 * voided/net, expected_cash, net_total, sales, voided and currency are included).
	 */
	public static function session_totals( $session_id ) {
		return ZReport::compute( $session_id );
	}

	/**
	 * Earlier API (one development line booked refunds of box office orders into wpcap_boxoffice_refunds):
	 * books the refund through Shifts::book_refund() (movement in the target shift, or an open box
	 * office payout for back-office refunds, A488).
	 *
	 * @return int Shift the refund was booked into (0 = none yet / open payout), -1 when it is not a box office refund.
	 */
	public static function book_refund( $refund_id ) {
		$result = Shifts::book_refund( $refund_id, false );
		if ( null === $result ) {
			global $wpdb;
			$refund = Refunds::get( $refund_id );
			$is_pos = $refund && $wpdb->get_var( $wpdb->prepare( 'SELECT sale_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_boxoffice_sales', (int) $refund['order_id'] ) );
			return $is_pos ? 0 : -1;
		}
		return ( ! is_wp_error( $result ) && isset( $result->shift_id ) ) ? (int) $result->shift_id : 0;
	}

	/**
	 * Refunds of box office orders booked into a shift: refund movements of the shift, plus rows of the
	 * earlier wpcap_boxoffice_refunds table (installs of the other development line).
	 */
	public static function session_refunds( $session_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.refund_id,m.sale_id,m.shift_id AS session_id,m.method AS payment_method,-m.amount_minor AS amount_minor,m.created_at AS booked_at,s.receipt_number,s.session_id AS sale_session_id FROM %i m INNER JOIN %i s ON s.sale_id=m.sale_id WHERE m.shift_id=%d AND m.kind='refund' ORDER BY m.movement_id", $p . 'wpcap_boxoffice_movements', $p . 'wpcap_boxoffice_sales', absint( $session_id ) ) );
		$suppress = $wpdb->suppress_errors( true );
		$legacy = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT br.*,s.receipt_number,s.session_id AS sale_session_id FROM %i br INNER JOIN %i s ON s.sale_id=br.sale_id WHERE br.session_id=%d AND br.session_id>0 ORDER BY br.refund_id', $p . 'wpcap_boxoffice_refunds', $p . 'wpcap_boxoffice_sales', absint( $session_id ) ) );
		$wpdb->suppress_errors( $suppress );
		return array_merge( $rows, $legacy );
	}

	/**
	 * Counts and closes a shift in one step (legacy API, no card terminal count): the note is the
	 * reason for a cash difference. Only cashiers of the shift (or an administrator).
	 */
	public static function close_session( $session_id, $counted_minor, $note = '' ) {
		$shift = Shifts::get( $session_id );
		if ( $shift && 'open' === $shift->status && Shifts::can_operate( $shift ) ) {
			$blocked = Shifts::close_blockers( $shift );
			if ( is_wp_error( $blocked ) ) { return $blocked; }
		}
		if ( $shift && 'open' === $shift->status && '' === trim( (string) $note ) && Shifts::can_operate( $shift ) && max( 0, (int) $counted_minor ) !== ZReport::compute( $session_id )['cash']['expected'] ) {
			// Refuse before counting, so the shift stays open for sales.
			return self::error( 'wpcap_pos_difference_reason', __( 'Es gibt eine Kassendifferenz. Bitte einen Grund angeben.', 'pass-captain' ) );
		}
		$count = Shifts::submit_count( $session_id, array( 'cash_minor' => max( 0, (int) $counted_minor ) ) );
		if ( is_wp_error( $count ) ) { return $count; }
		return Shifts::close( $session_id, array( 'reason' => $note, 'note' => $note ) );
	}

	/* ------------------------------------------------------------------ sale */

	/**
	 * Ticket types of an occurrence with live availability (null = unlimited), the active participant
	 * fields (same definition as the public checkout: key, label, type, required, purpose) and whether
	 * seats come from a seat map (the box office cannot pick seats: best available ones are assigned).
	 */
	public static function ticket_types( $occurrence_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp,tax_class,status,sales_start,sales_end FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id", absint( $occurrence_id ) ) );
		$seated = self::seated_types( $occurrence_id );
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		// Display batch: capacity, holds, pools and rules of all types in grouped queries (PERF-1).
		return \PassCaptain\DisplayBatch::run( array_map( static function ( $r ) { return (int) $r->ticket_type_id; }, $rows ), static function () use ( $rows, $seated, $occurrence ) {
			return self::ticket_type_rows( $rows, $seated, $occurrence );
		} );
	}

	/** Output rows of ticket_types() (runs inside the display batch). */
	private static function ticket_type_rows( array $rows, array $seated, $occurrence ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'ticket_type_id' => (int) $r->ticket_type_id,
				'name' => $r->name,
				// Effective server-side price (series price tiers); sales without a price override charge it.
				'price_minor' => Orders::unit_price( (int) $r->ticket_type_id, null, 1, array( 'source' => 'pos' ) ),
				'currency' => $r->currency,
				// Rate a sale now gets (country tax profile or the ticket type's own rate, A115).
				'tax_rate_bp' => (int) \PassCaptain\Tax\Profiles::resolve( $r, null, '', 'pos' )['rate_bp'],
				'max_per_order' => (int) $r->max_per_order,
				'available' => Orders::availability( $r->ticket_type_id ),
				'fields' => self::participant_fields( $r->ticket_type_id ),
				'seated' => isset( $seated[ (int) $r->ticket_type_id ] ),
				// Why this price applies (automatic price rule, same explanation as the online checkout); '' = stored price.
				'price_rule' => class_exists( \PassCaptain\Sales\PriceRules::class ) ? \PassCaptain\Sales\PriceRules::explanation_for( $r, $occurrence, array( 'source' => 'pos' ) ) : '',
			);
		}
		return $out;
	}

	/** Active participant fields of a ticket type in checkout order (same columns as the public checkout). */
	public static function participant_fields( $ticket_type_id ) {
		global $wpdb;
		// SELECT *: the column set differs between schema versions (A680 options/help/scope, consent texts).
		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", absint( $ticket_type_id ) ), ARRAY_A );
		return array_map( static function ( $f ) {
			// A680: same definition as the public checkout (type, choices, help text, scope).
			$out = array( 'required' => (bool) (int) $f['required'] ) + \PassCaptain\ParticipantFields::public_definition( $f );
			$raw_type = (string) ( $f['field_type'] ?? '' );
			if ( 'consent' === $raw_type ) {
				// Consent fields: the text the attendee agrees to (shown next to the checkbox; logged by Sales\Consents).
				$out['type'] = 'consent';
				$out += array( 'consent_text' => (string) ( $f['consent_text'] ?? '' ), 'consent_version' => (string) ( $f['consent_version'] ?? '' ) );
			} elseif ( 'radio' === $raw_type ) {
				$out['type'] = 'radio';
				$out['options'] = \PassCaptain\ParticipantFields::options( $f );
			}
			return $out;
		}, (array) $fields );
	}

	/** Ticket types of an occurrence that are sold with seats from a seat map (ticket_type_id => true). */
	private static function seated_types( $occurrence_id ) {
		if ( ! class_exists( '\\PassCaptain\\Seating\\Layouts' ) || ! in_array( 'PassCaptain\\Modules\\Seating', \PassCaptain\Modules::classes(), true ) ) { return array(); }
		global $wpdb;
		$table = \PassCaptain\Seating\Layouts::table( 'links' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) || ! \PassCaptain\Seating\Layouts::link( $occurrence_id ) ) { return array(); }
		return array_fill_keys( array_keys( array_filter( \PassCaptain\Seating\Layouts::type_zones( $occurrence_id ) ) ), true );
	}

	/**
	 * Attendee list for Orders::create() (format of the public checkout: ticket_type_id, name, email,
	 * answers{field_key: value}) from the POS input. $attendees_in: {type_id: [{name, email, answers}, ...]};
	 * $names_in (legacy): {type_id: [name, ...]} used where no attendee entry is given. Empty entries
	 * are skipped; Orders::validate_attendees() enforces required fields for every ticket.
	 */
	public static function build_attendees( $type_id, $qty, $attendees_in, $names_in ) {
		$entries = is_array( $attendees_in[ $type_id ] ?? null ) ? array_values( $attendees_in[ $type_id ] ) : array();
		$names = $names_in[ $type_id ] ?? array();
		$names = is_array( $names ) ? $names : preg_split( '/\r\n|\r|\n/', (string) $names );
		$names = array_values( array_filter( array_map( static function ( $n ) { return is_scalar( $n ) ? trim( sanitize_text_field( (string) $n ) ) : ''; }, $names ), 'strlen' ) );
		$out = array();
		for ( $i = 0; $i < $qty; $i++ ) {
			$entry = is_array( $entries[ $i ] ?? null ) ? $entries[ $i ] : array();
			$name = is_scalar( $entry['name'] ?? null ) ? trim( sanitize_text_field( (string) $entry['name'] ) ) : '';
			if ( '' === $name && ! $entries ) { $name = (string) ( $names[ $i ] ?? '' ); }
			$email = is_scalar( $entry['email'] ?? null ) ? trim( sanitize_email( (string) $entry['email'] ) ) : '';
			$answers = array();
			foreach ( is_array( $entry['answers'] ?? null ) ? $entry['answers'] : array() as $key => $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { $answers[ sanitize_key( (string) $key ) ] = (string) $value; }
			}
			if ( '' === $name && '' === $email && ! $answers ) { continue; }
			$out[] = array( 'ticket_type_id' => (int) $type_id, 'name' => mb_substr( $name, 0, 190 ), 'email' => $email, 'answers' => $answers );
		}
		return $out;
	}

	public static function sale( $sale_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE sale_id=%d", absint( $sale_id ) ) );
	}

	private static function sale_by_key( $key_hash ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE form_key_hash=%s", $key_hash ) );
	}

	/** Result for a form key that already has a sale row (replay, resume or failure). */
	private static function existing_result( $existing ) {
		if ( (int) $existing->cashier_id !== get_current_user_id() ) { return self::error( 'wpcap_pos_form_key', __( 'Das Formular ist ungültig. Bitte neu laden.', 'pass-captain' ) ); }
		// A637: a replayed form key is checked against the cashier's event scope as it is now (a scope
		// removed since the sale must not resume, book or reveal it).
		$occurrence_id = (int) ( $existing->occurrence_id ?? 0 );
		$order_id = (int) ( $existing->order_id ?? 0 );
		$in_scope = ( ! $occurrence_id || Scope::can_occurrence( $occurrence_id, self::CAP ) ) && ( ! $order_id || Scope::can_order( $order_id, self::CAP ) );
		if ( ! $in_scope || ( ! $occurrence_id && ! $order_id ) ) {
			return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 );
		}
		if ( 'pending' === $existing->status ) {
			if ( ! $existing->order_id ) { return self::error( 'wpcap_pos_in_progress', __( 'Dieser Verkauf wird gerade verarbeitet.', 'pass-captain' ), 409 ); }
			if ( self::DEVICE_METHOD === $existing->payment_method && 'succeeded' !== $existing->device_status ) {
				// Terminal payment not confirmed yet: resume the start (idempotent per sale key) or report the wait.
				return '' === (string) $existing->device_status ? self::start_device_payment( (int) $existing->sale_id ) : array( 'sale' => $existing, 'reused' => true, 'device_pending' => true );
			}
			return self::finalize( $existing->sale_id );
		}
		if ( 'failed' === $existing->status ) { return self::error( 'wpcap_pos_sale_failed', __( 'Dieser Verkauf wurde nicht gebucht. Bitte neu erfassen.', 'pass-captain' ), 409 ); }
		return array( 'sale' => $existing, 'reused' => true );
	}

	/**
	 * Sells tickets at the box office.
	 *
	 * Flow: a "pending" sale row is stored first (unique form key), then the order is created and
	 * confirmed, then finalize() books it (receipt number) under a lock on the session row. If anything
	 * fails after the order exists, the pending row keeps the link to the order; resubmitting the same
	 * form key or "Nachbuchen" resumes it, and closing the session is refused while it is paid but unbooked.
	 *
	 * @param array $input occurrence_id, quantities {type_id: qty}, names {type_id: [name, ...]}, email,
	 *                     method (cash|card|free|invoice), tendered_minor, reference, form_key,
	 *                     billing (optional: company, first_name, last_name, street, postcode, city,
	 *                     country, vat_id, po_number; see Billing::validate()).
	 * @return array|\WP_Error ['sale' => row, 'reused' => bool]
	 */
	public static function sell( array $input ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$form_key = (string) ( $input['form_key'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9-]{16,64}$/', $form_key ) ) { return self::error( 'wpcap_pos_form_key', __( 'Das Formular ist ungültig. Bitte neu laden.', 'pass-captain' ) ); }
		$key_hash = hash( 'sha256', 'pos-sale|' . $form_key );
		$existing = self::sale_by_key( $key_hash );
		// A pending claim without order (the order creation failed and the claim could not be released,
		// e.g. connection lost) is resumed: the order is created with the same idempotency key, so a
		// concurrent double submit still ends with one order.
		$resume = $existing && 'pending' === $existing->status && ! $existing->order_id && (int) $existing->cashier_id === get_current_user_id();
		if ( $existing && ! $resume ) { return self::existing_result( $existing ); }
		$session = self::open_session_for( get_current_user_id() );
		if ( ! $session || 'open' !== $session->status ) { return self::error( 'wpcap_pos_no_session', __( 'Bitte zuerst eine Kassensitzung öffnen.', 'pass-captain' ), 409 ); }
		if ( ! TerminalRegistry::allows( (int) ( $session->location_id ?? 0 ), (string) ( $session->fiscal_register_id ?? '' ) ) ) { return self::error( 'wpcap_pos_terminal', __( 'Dieses Kassenregister ist nicht mehr freigegeben. Bitte Sitzung schließen und ein freigegebenes Register öffnen.', 'pass-captain' ), 409 ); }

		$occurrence_id = absint( $input['occurrence_id'] ?? 0 );
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id || ! Scope::can_occurrence( $occurrence_id, self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$register = Registers::get( $session->register_id );
		if ( $register && (int) $register->event_id && (int) $register->event_id !== $event_id ) { return self::error( 'wpcap_pos_register_event', __( 'Diese Kasse verkauft nur Tickets für ein anderes Event.', 'pass-captain' ), 409 ); }
		$methods = self::all_methods();
		$method = (string) ( $input['method'] ?? '' );
		if ( in_array( $method, self::FISCAL_METHODS, true ) && self::fiscal_block() ) {
			return self::error( 'wpcap_pos_fiscal_required', __( 'Bar- und Kartenzahlungen an der Kasse brauchen in Deutschland und Österreich eine zertifizierte Kassenlösung (TSE/RKSV). Pass Captain signiert keine Belege. Bitte Freikarte oder Rechnung wählen, oder in den Belegeinstellungen bestätigen, dass diese Verkäufe in einer zertifizierten Kasse erfasst werden.', 'pass-captain' ), 409 );
		}
		if ( ! isset( $methods[ $method ] ) ) { return self::error( 'wpcap_pos_method', __( 'Bitte eine Zahlart wählen.', 'pass-captain' ) ); }
		if ( 'free' === $method && ! self::can_give_free() ) {
			return self::error( 'wpcap_pos_free_forbidden', __( 'Freikarten an der Kasse brauchen die Berechtigung zur Freigabe von Freikarten.', 'pass-captain' ), 403 );
		}
		$device_id = '';
		if ( self::DEVICE_METHOD === $method ) {
			$devices = Devices::for_session( $session );
			if ( ! $devices ) { return self::error( 'wpcap_pos_method', __( 'Bitte eine Zahlart wählen.', 'pass-captain' ) ); }
			$device_id = (string) ( $input['device'] ?? '' );
			if ( '' === $device_id && 1 === count( $devices ) ) { $device_id = (string) array_key_first( $devices ); }
			if ( ! isset( $devices[ $device_id ] ) ) { return self::error( 'wpcap_pos_device', __( 'Bitte ein Kartenterminal wählen.', 'pass-captain' ) ); }
		}

		$types = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,currency,price_minor FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence_id ) ) as $t ) { $types[ (int) $t->ticket_type_id ] = $t; }
		$items = array();
		$attendees = array();
		$names_in = is_array( $input['names'] ?? null ) ? $input['names'] : array();
		$attendees_in = is_array( $input['attendees'] ?? null ) ? $input['attendees'] : array();
		foreach ( (array) ( $input['quantities'] ?? array() ) as $type_id => $qty ) {
			$type_id = absint( $type_id );
			$qty = absint( $qty );
			if ( ! $qty ) { continue; }
			if ( ! isset( $types[ $type_id ] ) ) { return self::error( 'wpcap_pos_item', __( 'Eine Ticketart gehört nicht zu diesem Termin.', 'pass-captain' ) ); }
			if ( strtoupper( $types[ $type_id ]->currency ) !== $session->currency ) { return self::error( 'wpcap_pos_currency', __( 'Die Währung der Ticketart passt nicht zur Kassensitzung.', 'pass-captain' ) ); }
			$items[] = array( 'ticket_type_id' => $type_id, 'quantity' => $qty );
			$attendees = array_merge( $attendees, self::build_attendees( $type_id, $qty, $attendees_in, $names_in ) );
		}
		if ( ! $items ) { return self::error( 'wpcap_pos_empty', __( 'Bitte mindestens ein Ticket wählen.', 'pass-captain' ) ); }

		$email = \PassCaptain\Http::optional_email( $input['email'] ?? '' );
		if ( false === $email || self::is_placeholder_email( (string) $email ) ) { return self::error( 'wpcap_pos_email', __( 'Die E-Mail-Adresse ist ungültig.', 'pass-captain' ) ); }
		if ( 'invoice' === $method && '' === $email ) { return self::error( 'wpcap_pos_email', __( 'Für Zahlung auf Rechnung ist die E-Mail-Adresse des Käufers erforderlich.', 'pass-captain' ) ); }
		$buyer_email = '' !== $email ? $email : 'boxoffice+' . substr( $key_hash, 0, 16 ) . self::PLACEHOLDER_DOMAIN;
		// Optional "Rechnung auf Firma": company, name, address, VAT ID for the invoice.
		$billing = \PassCaptain\Billing::validate( $input['billing'] ?? null );
		if ( is_wp_error( $billing ) ) { return $billing; }

		// 1. Pending sale row: claims the form key before any order exists.
		$tendered = isset( $input['tendered_minor'] ) && null !== $input['tendered_minor'] && 'cash' === $method ? max( 0, (int) $input['tendered_minor'] ) : null;
		$row = array(
			'session_id' => (int) $session->session_id,
			'order_id' => null,
			'form_key_hash' => $key_hash,
			'payment_method' => $method,
			'device_id' => $device_id,
			'currency' => $session->currency,
			'tendered_minor' => $tendered,
			'occurrence_id' => $occurrence_id,
			'cashier_id' => get_current_user_id(),
			'status' => 'pending',
			'created_at' => current_time( 'mysql', true ),
		);
		$saved = $resume ? 1 : $wpdb->insert( "{$p}wpcap_boxoffice_sales", $row );
		if ( false === $saved && ! self::sale_by_key( $key_hash ) && \PassCaptain\Modules\BoxOffice::migrate() ) {
			// Legacy schema (order_id NOT NULL, a stale row with order_id 0): repaired, try once more.
			$saved = $wpdb->insert( "{$p}wpcap_boxoffice_sales", $row );
		}
		if ( false === $saved ) {
			$existing = self::sale_by_key( $key_hash );
			return $existing ? self::existing_result( $existing ) : self::error( 'wpcap_pos_save', __( 'Der Verkauf konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
		}
		$sale_id = $resume ? (int) $existing->sale_id : (int) $wpdb->insert_id;

		// 2. Order through the shared checkout path (same capacity locks as online).
		$internal = array(
			'external' => true,
			// An invoice above the threshold needs the buyer's name and address (DE §14 UStG / AT §11 UStG).
			'require_billing' => 'invoice' === $method,
			'payment_provider' => $methods[ $method ]['provider'],
			'key_namespace' => 'pos',
			'source' => 'pos',
			'fiscal_location_id' => (int) ( $session->location_id ?? 0 ),
			'fiscal_register_id' => (string) ( $session->fiscal_register_id ?? '' ),
			'hold_minutes' => 15,
			'skip_rate_limit' => true,
		);
		// Fiscal documents describe a terminal payment as card payment.
		if ( self::DEVICE_METHOD === $method ) { $internal['fiscal_payment_method'] = 'card'; }
		if ( 'free' === $method ) {
			foreach ( $items as $item ) {
				$internal['unit_prices'][ $item['ticket_type_id'] ] = 0;
				$internal['line_totals'][ $item['ticket_type_id'] ] = 0;
				$internal['line_taxes'][ $item['ticket_type_id'] ] = 0;
			}
		}
		$order = Orders::create( array( 'email' => $buyer_email, 'items' => $items, 'attendees' => $attendees, 'idempotency_key' => $form_key, 'billing' => $billing ? array( 'enabled' => true ) + $billing : null ), $internal );
		if ( is_wp_error( $order ) ) {
			// No order was created: release the form key claim.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_boxoffice_sales WHERE sale_id=%d AND status='pending' AND (order_id IS NULL OR order_id=0)", $sale_id ) );
			if ( 'wpcap_billing_required' === $order->get_error_code() ) {
				return self::error( 'wpcap_billing_required', __( 'Für eine Rechnung über diesem Betrag sind Name und Anschrift des Käufers nötig. Bitte unter „Rechnung auf Firma/Adresse“ ausfüllen.', 'pass-captain' ), 400 );
			}
			return $order;
		}
		$order_id = (int) $order['order_id'];
		$linked = $wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'order_id' => $order_id ), array( 'sale_id' => $sale_id ) );
		if ( false === $linked ) {
			// Payment is only confirmed for an order the sale row points to; resubmitting the form resumes.
			return self::error( 'wpcap_pos_save', __( 'Der Verkauf konnte nicht gespeichert werden. Bitte erneut absenden.', 'pass-captain' ), 503 );
		}

		// 3a. Card terminal: the payment is started and confirmed asynchronously (poll or webhook);
		// the sale stays pending with the order hold until then.
		if ( self::DEVICE_METHOD === $method ) { return self::start_device_payment( $sale_id ); }

		// 3. A489: the shift must still be open (not counting/closed) right before the payment is
		// confirmed and the tickets are issued – checked under the shift lock that counting and closing
		// take, too. A count/close that comes later sees this pending sale as "in progress" and waits.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$locked_shift = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_boxoffice_sessions WHERE session_id=%d FOR UPDATE", (int) $session->session_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::busy(); }
		$locked_sale = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_boxoffice_sales WHERE sale_id=%d FOR UPDATE", $sale_id ) );
		// A transient database error is not "shift closed": the sale stays pending and resubmitting resumes it (W6).
		if ( '' !== (string) $wpdb->last_error ) { return self::busy(); }
		if ( ! $locked_shift || 'open' !== $locked_shift->status || ! $locked_sale || 'pending' !== $locked_sale->status ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_boxoffice_sales SET status='failed' WHERE sale_id=%d AND status='pending'", $sale_id ) );
			$wpdb->query( 'COMMIT' );
			Orders::cancel_order( $order_id, 'pos_session_closed', array( 'pending' ) );
			Audit::record( 'pos_sale_failed', 'order', $order_id, array( 'sale_id' => $sale_id, 'reason' => 'session_not_open' ) );
			return self::error( 'wpcap_pos_session_closed', __( 'Die Kassenschicht wird gerade gezählt oder wurde geschlossen. Der Verkauf wurde nicht gebucht; es wurden keine Tickets ausgestellt.', 'pass-captain' ), 409 );
		}
		$wpdb->query( 'COMMIT' );

		// 4. Payment confirmation and issuance, then booking.
		$reference = mb_substr( sanitize_text_field( (string) ( $input['reference'] ?? '' ) ), 0, 100 );
		$confirmed = Orders::confirm_external_payment( $order_id, 'pos:' . $method . ( '' !== $reference ? ':' . $reference : '' ) );
		if ( is_wp_error( $confirmed ) ) { return $confirmed; }
		if ( '' !== $reference ) { Orders::set_provider_reference( $order_id, $reference ); }
		return self::finalize( $sale_id );
	}

	/** Rolls back and returns a retryable error; the sale stays pending and resubmitting/"Nachbuchen" resumes it. */
	private static function busy() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return self::error( 'wpcap_pos_busy', __( 'Der Verkauf konnte gerade nicht gebucht werden. Bitte erneut absenden.', 'pass-captain' ), 503 );
	}

	/**
	 * Books an already created order (e.g. the supplement of a box office exchange) as a sale of the
	 * current user's open shift: receipt number, Z report, later refunds/stornos. Idempotent per
	 * $key. Cash and card need an open shift; without one a WP_Error is returned.
	 *
	 * @param int    $order_id      Paid (or pending POS) order.
	 * @param string $method        cash|card|free|invoice.
	 * @param string $key           Idempotency key (e.g. 'exchange-<id>').
	 * @param int    $occurrence_id Occurrence shown on the receipt.
	 * @return array|\WP_Error|null ['sale' => row, 'reused' => bool]; null when not needed (free/invoice without shift).
	 */
	public static function book_order( $order_id, $method, $key, $occurrence_id = 0 ) {
		global $wpdb;
		$key_hash = hash( 'sha256', 'pos-order|' . (string) $key );
		$existing = self::sale_by_key( $key_hash );
		if ( $existing ) { return 'pending' === $existing->status ? self::finalize( $existing->sale_id ) : array( 'sale' => $existing, 'reused' => true ); }
		$session = self::open_session_for( get_current_user_id() );
		if ( ! $session || 'open' !== $session->status ) {
			return Shifts::needs_shift( $method ) ? self::error( 'wpcap_pos_no_session', __( 'Bitte zuerst eine Kassensitzung öffnen.', 'pass-captain' ), 409 ) : null;
		}
		$order = Orders::get( $order_id );
		if ( ! $order ) { return self::error( 'wpcap_pos_sale', __( 'Verkauf nicht gefunden.', 'pass-captain' ), 404 ); }
		$saved = $wpdb->insert( "{$wpdb->prefix}wpcap_boxoffice_sales", array(
			'session_id' => (int) $session->session_id,
			'order_id' => (int) $order_id,
			'form_key_hash' => $key_hash,
			'payment_method' => $method,
			'currency' => (string) $order->currency,
			'tendered_minor' => null,
			'occurrence_id' => absint( $occurrence_id ),
			'cashier_id' => get_current_user_id(),
			'status' => 'pending',
			'created_at' => current_time( 'mysql', true ),
		) );
		if ( false === $saved ) {
			$existing = self::sale_by_key( $key_hash );
			return $existing ? self::finalize( $existing->sale_id ) : self::error( 'wpcap_pos_save', __( 'Der Verkauf konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
		}
		return self::finalize( (int) $wpdb->insert_id );
	}

	/** Paid order with all tickets issued? Re-issues missing tickets once (idempotent core issuance). */
	private static function order_ready( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = Orders::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status || 'complete' !== $order->status ) { return false; }
		// Exchange supplement: money only, its ticket is issued on the original order.
		if ( \PassCaptain\Exchange\Service::is_supplement( (int) $order_id ) ) { return true; }
		$expected = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(quantity),0) FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', $order_id ) );
		$count = static function () use ( $wpdb, $p, $order_id ) { return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', $p . 'wpcap_tickets', $order_id ) ); };
		if ( $count() < $expected ) { Orders::issue_and_email( $order_id ); }
		return $expected > 0 && $count() >= $expected;
	}

	/**
	 * Books a pending sale: verifies the order is paid with all tickets, then reserves the receipt
	 * number under a lock on the session row (the session must still be open). Idempotent.
	 * If the session was closed meanwhile, the order is cancelled and the sale marked failed.
	 *
	 * @return array|\WP_Error ['sale' => row, 'reused' => bool]
	 */
	public static function finalize( $sale_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sale = self::sale( $sale_id );
		if ( ! $sale ) { return self::error( 'wpcap_pos_sale', __( 'Verkauf nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( 'pending' !== $sale->status ) { return 'failed' === $sale->status ? self::error( 'wpcap_pos_sale_failed', __( 'Dieser Verkauf wurde nicht gebucht. Bitte neu erfassen.', 'pass-captain' ), 409 ) : array( 'sale' => $sale, 'reused' => true ); }
		$order = $sale->order_id ? Orders::get( $sale->order_id ) : null;
		if ( ! $order ) { return self::error( 'wpcap_pos_in_progress', __( 'Dieser Verkauf wird gerade verarbeitet.', 'pass-captain' ), 409 ); }
		// A terminal payment is only booked after the device (or the add-on's webhook) confirmed it.
		if ( self::DEVICE_METHOD === $sale->payment_method && 'succeeded' !== $sale->device_status ) {
			return self::error( 'wpcap_pos_device_pending', __( 'Die Zahlung am Kartenterminal ist noch nicht bestätigt. Der Verkauf wird erst danach gebucht.', 'pass-captain' ), 409 );
		}
		// "expired": the sale was interrupted before the payment was confirmed and the 15-minute hold
		// lapsed meanwhile; confirm_external_payment() re-checks capacity under locks (late recovery).
		if ( in_array( $order->payment_status, array( 'pending', 'expired' ), true ) ) {
			$confirmed = Orders::confirm_external_payment( $order->order_id, 'pos:' . $sale->payment_method );
			if ( is_wp_error( $confirmed ) ) { return $confirmed; }
		}
		if ( ! self::order_ready( $order->order_id ) ) {
			Audit::record( 'pos_sale_unbooked', 'order', (int) $order->order_id, array( 'sale_id' => (int) $sale->sale_id ) );
			// Fiscal gating (default outage policy stop_sale): no receipt number and no tickets until the
			// provider confirmed the fiscal document. The sale stays pending; "Nachbuchen" resumes it.
			$fiscal = class_exists( \PassCaptain\Modules\Fiscalization::class ) ? \PassCaptain\Modules\Fiscalization::blocking_status( (int) $order->order_id ) : '';
			if ( 'failed' === $fiscal ) {
				return self::error( 'wpcap_pos_fiscal_failed', __( 'Der Fiskalbeleg konnte nicht erstellt werden. Es wurden keine Tickets ausgegeben und der Verkauf wurde nicht gebucht. Bitte unter Fiskalisierung prüfen und erneut versuchen, dann „Nachbuchen“.', 'pass-captain' ), 409 );
			}
			if ( 'pending' === $fiscal ) {
				return self::error( 'wpcap_pos_fiscal_pending', __( 'Der Fiskalbeleg ist noch nicht vom Anbieter bestätigt. Tickets werden erst danach ausgegeben; der Verkauf bleibt offen. Bitte später „Nachbuchen“.', 'pass-captain' ), 409 );
			}
			return self::error( 'wpcap_pos_not_ready', __( 'Die Bestellung ist nicht bezahlt oder es wurden nicht alle Tickets ausgestellt. Der Verkauf wurde nicht gebucht; bitte die Bestellung prüfen.', 'pass-captain' ), 409 );
		}
		$order = Orders::get( $order->order_id );

		if ( ! $order ) { return self::busy(); }
		// A failed locking read must not look like "session closed" (that cancels the paid order).
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$session = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_boxoffice_sessions WHERE session_id=%d FOR UPDATE", $sale->session_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::busy(); }
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_boxoffice_sales WHERE sale_id=%d FOR UPDATE", $sale->sale_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::busy(); }
		if ( ! $locked || 'pending' !== $locked->status ) {
			$wpdb->query( 'COMMIT' );
			return self::finalize( $sale->sale_id ); // Booked or failed by a concurrent request.
		}
		if ( ! $session || 'open' !== $session->status ) {
			if ( false === $wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'status' => 'failed' ), array( 'sale_id' => (int) $sale->sale_id ) ) || false === $wpdb->query( 'COMMIT' ) ) { return self::busy(); }
			Orders::cancel_order( $order->order_id, 'pos_session_closed' );
			Audit::record( 'pos_sale_failed', 'order', (int) $order->order_id, array( 'sale_id' => (int) $sale->sale_id, 'reason' => 'session_closed' ) );
			return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung wurde inzwischen geschlossen. Der Verkauf wurde nicht gebucht und die Tickets wurden storniert.', 'pass-captain' ), 409 );
		}
		$number = Invoices::next_document_number( 'receipt', self::receipt_prefix(), true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
		$updated = $wpdb->update( "{$p}wpcap_boxoffice_sales", array(
			'receipt_number' => $number,
			'currency' => $order->currency,
			'total_minor' => (int) $order->total_minor,
			'tax_minor' => (int) $order->tax_minor,
			'status' => 'completed',
		), array( 'sale_id' => (int) $sale->sale_id, 'status' => 'pending' ) );
		if ( ! $updated ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_pos_save', __( 'Der Verkauf konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return self::busy(); }
		if ( 'invoice' === $sale->payment_method ) { Invoices::issue_for_order( $order->order_id ); }
		if ( ! self::is_placeholder_email( $order->customer_email ) ) { Orders::rotate_access_link( $order->order_id ); }
		Audit::record( 'pos_sale', 'order', (int) $order->order_id, array( 'sale_id' => (int) $sale->sale_id, 'receipt' => $number, 'method' => $sale->payment_method, 'total_minor' => (int) $order->total_minor ) );
		return array( 'sale' => self::sale( $sale->sale_id ), 'reused' => false );
	}

	/* ------------------------------------------------------------------ card terminal (asynchronous) */

	/** Gateway of a sale's device in the sale's session context; null when no longer registered. */
	private static function device_of( $sale ) {
		$session = self::session( $sale->session_id );
		return $session ? Devices::find( $session, $sale->device_id ) : null;
	}

	/**
	 * Starts the terminal payment of a pending sale (exactly once per sale: the device state is claimed
	 * first, so a resubmitted form or a parallel request does not start a second payment).
	 *
	 * @return array|\WP_Error ['sale' => row, 'reused' => bool, 'device_pending' => true] while the
	 *                         terminal is pending; the booked sale when the device answered "succeeded" at once.
	 */
	private static function start_device_payment( $sale_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sale = self::sale( $sale_id );
		if ( ! $sale || 'pending' !== $sale->status || ! $sale->order_id ) { return self::error( 'wpcap_pos_sale', __( 'Verkauf nicht gefunden.', 'pass-captain' ), 404 ); }
		$order = Orders::get( $sale->order_id );
		$device = self::device_of( $sale );
		if ( ! $order || ! $device ) { return self::fail_device( $sale, 'failed', __( 'Das Kartenterminal ist nicht mehr verfügbar.', 'pass-captain' ) ); }
		if ( (int) $order->total_minor <= 0 ) { return self::fail_device( $sale, 'failed', __( 'Für einen Betrag von 0,00 bitte „Freikarte“ wählen.', 'pass-captain' ) ); }
		$claimed = $wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'device_status' => 'pending', 'device_started_at' => current_time( 'mysql', true ) ), array( 'sale_id' => (int) $sale->sale_id, 'status' => 'pending', 'device_status' => '' ) );
		if ( ! $claimed ) {
			$fresh = self::sale( $sale->sale_id );
			if ( $fresh && 'pending' === $fresh->status && 'pending' === $fresh->device_status ) { return array( 'sale' => $fresh, 'reused' => true, 'device_pending' => true ); }
			return self::error( 'wpcap_pos_busy', __( 'Der Verkauf konnte gerade nicht gebucht werden. Bitte erneut absenden.', 'pass-captain' ), 503 );
		}
		$session = self::session( $sale->session_id );
		$payload = array(
			'version' => DeviceGatewayInterface::VERSION,
			'contract' => DeviceGatewayInterface::VERSION . '.' . DeviceGatewayInterface::REVISION,
			'sale_key' => (string) $sale->form_key_hash,
			'sale_id' => (int) $sale->sale_id,
			'order_id' => (int) $order->order_id,
			'order_public_id' => (string) $order->public_id,
			'amount_minor' => (int) $order->total_minor,
			'currency' => (string) $order->currency,
			/* translators: %s: order number (shortened) */
			'description' => sprintf( __( 'Bestellung %s', 'pass-captain' ), substr( (string) $order->public_id, 0, 12 ) ),
			'device_id' => (string) $sale->device_id,
			'session_id' => (int) $sale->session_id,
			'location_id' => $session ? (int) ( $session->location_id ?? 0 ) : 0,
			// Fiscal register ID string of the TerminalRegistry (device contract v1 key "register_id").
			'register_id' => $session ? (string) ( $session->fiscal_register_id ?? '' ) : '',
			'cashier_id' => (int) $sale->cashier_id,
		);
		$started = Devices::start( $device, $payload );
		if ( is_wp_error( $started ) ) { return self::fail_device( self::sale( $sale->sale_id ), 'failed', $started->get_error_message() ); }
		$wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'device_reference' => $started['reference'], 'device_message' => $started['message'] ), array( 'sale_id' => (int) $sale->sale_id ) );
		Audit::record( 'pos_device_payment_started', 'order', (int) $order->order_id, array( 'sale_id' => (int) $sale->sale_id, 'device_id' => (string) $sale->device_id, 'reference' => $started['reference'], 'amount_minor' => (int) $order->total_minor ) );
		do_action( 'wp_pass_captain_pos_device_payment_started_v1', (int) $sale->sale_id, (string) $sale->device_id, $started['reference'], $payload );
		$sale = self::sale( $sale->sale_id );
		if ( 'succeeded' === $started['status'] ) {
			$confirmed = self::confirm_device( $sale, '' !== $started['provider_reference'] ? $started['provider_reference'] : $started['reference'], 'start', $started );
			return is_wp_error( $confirmed ) ? $confirmed : self::finalize( $sale->sale_id );
		}
		if ( in_array( $started['status'], array( 'failed', 'cancelled' ), true ) ) { return self::fail_device( $sale, $started['status'], $started['message'] ); }
		return array( 'sale' => $sale, 'reused' => false, 'device_pending' => true );
	}

	/**
	 * Confirms the terminal payment of a box office sale, e.g. from the add-on's verified webhook.
	 * Idempotent and audited: only a sale waiting for its terminal payment is confirmed and then booked
	 * through the same path as cash/card sales (fiscal gating applies; while the fiscal receipt is
	 * pending the sale stays open without tickets and is booked by "Nachbuchen"/the next poll). A
	 * repeated confirmation with a known reference returns true without booking twice.
	 *
	 * @param string $sale_key_or_order_id `sale_key` from start() (64 hex) or the order ID.
	 * @param string $provider_reference   Payment reference of the provider (stored on the order).
	 * @param array  $payment              Optional (contract 1.1): amount_minor and currency actually
	 *                                     charged. When given, they must match the order, else the sale
	 *                                     ends as amount_mismatch and nothing is booked.
	 * @return true|\WP_Error 404 unknown sale, 400 empty reference, 409 no terminal payment expected any
	 *                        more (cancelled/failed/timed out: refund it on the terminal), a different
	 *                        reference after a confirmed payment (second payment: refund it) or a
	 *                        different amount/currency (wpcap_pos_device_amount_mismatch: refund it).
	 */
	public static function confirm_device_payment( $sale_key_or_order_id, $provider_reference, $payment = array() ) {
		global $wpdb;
		$key = trim( (string) $sale_key_or_order_id );
		$sale = null;
		if ( '' !== $key && ctype_digit( $key ) ) {
			$sale = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE order_id=%d", (int) $key ) );
		} elseif ( preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			$sale = self::sale_by_key( $key );
		}
		if ( ! $sale || self::DEVICE_METHOD !== $sale->payment_method ) { return self::error( 'wpcap_pos_device_sale', __( 'Kein Kassenverkauf mit Terminalzahlung gefunden.', 'pass-captain' ), 404 ); }
		return self::confirm_device( $sale, (string) $provider_reference, 'webhook', Devices::amount_of( $payment ) );
	}

	/**
	 * confirm_device_payment() for a sale row; $source poll|webhook|start (audit). $payment may carry the
	 * charged amount_minor/currency (Devices::amount_of()); a difference to the order is never confirmed.
	 */
	private static function confirm_device( $sale, $reference, $source, array $payment = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$reference = mb_substr( sanitize_text_field( (string) $reference ), 0, 191 );
		if ( '' === $reference ) { return self::error( 'wpcap_pos_device_reference', __( 'Die Zahlungsreferenz fehlt.', 'pass-captain' ) ); }
		if ( 'succeeded' === $sale->device_status ) {
			$order = Orders::get( $sale->order_id );
			$known = array_filter( array( (string) $sale->device_reference, $order ? (string) $order->provider_reference : '' ), static function ( $ref ) { return '' !== $ref; } );
			if ( ! in_array( $reference, $known, true ) ) {
				Audit::record( 'pos_device_payment_conflict', 'order', (int) $sale->order_id, array( 'sale_id' => (int) $sale->sale_id, 'reference' => $reference, 'source' => $source ) );
				return self::error( 'wpcap_pos_device_conflict', __( 'Dieser Verkauf wurde bereits mit einer anderen Terminalzahlung bestätigt. Die zweite Zahlung bitte am Terminal erstatten.', 'pass-captain' ), 409 );
			}
			if ( 'pending' === $sale->status ) { self::finalize( (int) $sale->sale_id ); } // Resume an unbooked sale; never books twice.
			return true;
		}
		if ( 'pending' !== $sale->device_status || 'pending' !== $sale->status ) {
			Audit::record( 'pos_device_payment_late', 'order', (int) $sale->order_id, array( 'sale_id' => (int) $sale->sale_id, 'reference' => $reference, 'device_status' => (string) $sale->device_status, 'source' => $source ) );
			return self::error( 'wpcap_pos_device_not_pending', __( 'Für diesen Verkauf wird keine Terminalzahlung mehr erwartet (abgebrochen, fehlgeschlagen oder abgelaufen). Eine dennoch erfolgte Zahlung bitte am Terminal erstatten.', 'pass-captain' ), 409 );
		}
		$mismatch = self::amount_mismatch( $sale, $payment );
		if ( '' !== $mismatch ) {
			Audit::record( 'pos_device_amount_mismatch', 'order', (int) $sale->order_id, array( 'sale_id' => (int) $sale->sale_id, 'reference' => $reference, 'source' => $source, 'charged_minor' => $payment['amount_minor'] ?? null, 'charged_currency' => (string) ( $payment['currency'] ?? '' ) ) );
			$order = Orders::get( $sale->order_id );
			wp_mail(
				get_option( 'admin_email' ),
				__( 'Pass Captain: Terminalzahlung prüfen', 'pass-captain' ),
				/* translators: 1: order id, 2: terminal reference, 3: description of the difference */
				sprintf( __( "Bestellung %1\$s (Terminal-Referenz %2\$s): %3\$s\n\nDer Verkauf wurde nicht gebucht. Bitte den am Terminal belasteten Betrag dort erstatten und den Verkauf neu erfassen.", 'pass-captain' ), $order ? $order->public_id : (string) $sale->order_id, $reference, $mismatch )
			);
			return self::fail_device( $sale, 'amount_mismatch', $mismatch );
		}
		// Exactly one request wins the transition pending → succeeded (poll, webhook and cancel race here).
		$won = $wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'device_status' => 'succeeded' ), array( 'sale_id' => (int) $sale->sale_id, 'status' => 'pending', 'device_status' => 'pending' ) );
		if ( ! $won ) {
			$fresh = self::sale( $sale->sale_id );
			if ( $fresh && 'pending' !== $fresh->device_status ) { return self::confirm_device( $fresh, $reference, $source ); }
			return self::error( 'wpcap_pos_busy', __( 'Der Verkauf konnte gerade nicht gebucht werden. Bitte erneut absenden.', 'pass-captain' ), 503 );
		}
		$confirmed = Orders::confirm_external_payment( (int) $sale->order_id, 'pos:' . self::DEVICE_METHOD . ':' . $reference );
		Orders::set_provider_reference( (int) $sale->order_id, $reference );
		Audit::record( 'pos_device_payment_confirmed', 'order', (int) $sale->order_id, array( 'sale_id' => (int) $sale->sale_id, 'device_id' => (string) $sale->device_id, 'reference' => $reference, 'source' => $source, 'order_confirmed' => ! is_wp_error( $confirmed ) ) );
		do_action( 'wp_pass_captain_pos_device_payment_completed_v1', (int) $sale->sale_id, (int) $sale->order_id, $reference, (string) $sale->device_id );
		// Booking (receipt number, tickets after the fiscal gate). Errors leave the sale pending for "Nachbuchen".
		if ( ! is_wp_error( $confirmed ) ) { self::finalize( (int) $sale->sale_id ); }
		return true;
	}

	/**
	 * Difference between the amount a terminal reports as charged and the order (empty when it matches
	 * or the device reported no amount – contract 1.0).
	 *
	 * @param array $payment amount_minor (int|null), currency (string) from Devices::amount_of().
	 */
	private static function amount_mismatch( $sale, array $payment ) {
		$amount = $payment['amount_minor'] ?? null;
		$currency = (string) ( $payment['currency'] ?? '' );
		if ( null === $amount && '' === $currency ) { return ''; }
		$order = Orders::get( $sale->order_id );
		if ( ! $order ) { return ''; } // Nothing to compare with; confirm_external_payment() decides.
		$parts = array();
		if ( null !== $amount && (int) $amount !== (int) $order->total_minor ) {
			/* translators: 1: charged amount, 2: order amount */
			$parts[] = sprintf( __( 'Am Terminal wurde %1$s belastet, die Bestellung beträgt %2$s.', 'pass-captain' ), $amount < 0 ? '?' : Scope::money( (int) $amount, '' !== $currency && 'XXX' !== $currency ? $currency : $order->currency ), Scope::money( (int) $order->total_minor, $order->currency ) );
		}
		if ( '' !== $currency && strtoupper( (string) $order->currency ) !== $currency ) {
			/* translators: 1: charged currency, 2: order currency */
			$parts[] = sprintf( __( 'Das Terminal meldet die Währung %1$s statt %2$s.', 'pass-captain' ), $currency, strtoupper( (string) $order->currency ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Ends a pending terminal payment without payment: the sale is marked failed and the order (hold)
	 * cancelled, so the cashier can sell again with another method. $status failed|cancelled|timeout|
	 * amount_mismatch (the terminal charged another amount: never booked, refund it on the terminal).
	 */
	private static function fail_device( $sale, $status, $message ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$texts = array(
			'failed' => __( 'Die Zahlung am Terminal ist fehlgeschlagen. Der Verkauf wurde nicht gebucht; bitte erneut versuchen oder eine andere Zahlart wählen.', 'pass-captain' ),
			'cancelled' => __( 'Die Zahlung am Terminal wurde abgebrochen. Der Verkauf wurde nicht gebucht; bitte erneut versuchen oder eine andere Zahlart wählen.', 'pass-captain' ),
			'timeout' => __( 'Zeitüberschreitung: Am Terminal wurde keine Zahlung bestätigt. Der Verkauf wurde nicht gebucht; bitte erneut versuchen oder eine andere Zahlart wählen.', 'pass-captain' ),
			'amount_mismatch' => __( 'Der am Terminal belastete Betrag stimmt nicht mit der Bestellung überein. Der Verkauf wurde nicht gebucht; bitte die Zahlung am Terminal erstatten und den Verkauf neu erfassen.', 'pass-captain' ),
		);
		$status = isset( $texts[ $status ] ) ? $status : 'failed';
		$message = mb_substr( sanitize_text_field( (string) $message ), 0, 255 );
		if ( ! $sale ) { return self::error( 'wpcap_pos_device_' . $status, $texts[ $status ], 409 ); }
		// Compare-and-set on the device state read before ('' before the start, else 'pending').
		$done = in_array( (string) $sale->device_status, array( '', 'pending' ), true ) ? $wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'status' => 'failed', 'device_status' => $status, 'device_message' => $message ), array( 'sale_id' => (int) $sale->sale_id, 'status' => 'pending', 'device_status' => (string) $sale->device_status ) ) : 0;
		if ( ! $done ) {
			$fresh = self::sale( $sale->sale_id );
			// Confirmed concurrently (or a database error): report the actual state instead.
			if ( $fresh && 'failed' !== $fresh->status ) { return 'succeeded' === $fresh->device_status ? self::finalize( (int) $fresh->sale_id ) : self::error( 'wpcap_pos_busy', __( 'Der Verkauf konnte gerade nicht gebucht werden. Bitte erneut absenden.', 'pass-captain' ), 503 ); }
		} else {
			if ( $sale->order_id ) { Orders::cancel_order( (int) $sale->order_id, 'pos_device_' . $status, true ); }
			Audit::record( 'pos_device_payment_failed', 'pos_sale', (int) $sale->sale_id, array( 'order_id' => (int) $sale->order_id, 'device_id' => (string) $sale->device_id, 'status' => $status, 'message' => $message ) );
			do_action( 'wp_pass_captain_pos_device_payment_failed_v1', (int) $sale->sale_id, $status, $message, (string) $sale->device_id );
		}
		return self::error( 'wpcap_pos_device_' . $status, $texts[ $status ] . ( '' !== $message ? ' (' . $message . ')' : '' ), 409 );
	}

	/** Seconds since the terminal payment was started. */
	private static function device_elapsed( $sale ) {
		$started = $sale->device_started_at ? strtotime( $sale->device_started_at . ' UTC' ) : false;
		return false === $started ? 0 : max( 0, time() - $started );
	}

	/**
	 * Polls a pending terminal payment once and applies the answer: succeeded → confirmation and
	 * booking, failed/cancelled → released, no answer past the timeout → cancelled on the terminal
	 * (best effort) and released as "timeout". Errors of the device count as "still pending".
	 *
	 * @return array|\WP_Error See device_state().
	 */
	public static function poll_device_payment( $sale_id ) {
		$sale = self::sale( $sale_id );
		if ( ! $sale || self::DEVICE_METHOD !== $sale->payment_method ) { return self::error( 'wpcap_pos_device_sale', __( 'Kein Kassenverkauf mit Terminalzahlung gefunden.', 'pass-captain' ), 404 ); }
		if ( 'pending' === $sale->status && 'pending' === $sale->device_status ) {
			$device = self::device_of( $sale );
			$reference = (string) $sale->device_reference;
			$state = $device && '' !== $reference ? Devices::poll( $device, $reference ) : new \WP_Error( 'wpcap_pos_device_missing', __( 'Das Kartenterminal ist nicht mehr verfügbar.', 'pass-captain' ) );
			$status = is_wp_error( $state ) ? 'pending' : $state['status'];
			if ( 'succeeded' === $status ) {
				self::confirm_device( $sale, '' !== $state['provider_reference'] ? $state['provider_reference'] : $reference, 'poll', $state );
			} elseif ( 'failed' === $status || 'cancelled' === $status ) {
				self::fail_device( $sale, $status, $state['message'] );
			} elseif ( self::device_elapsed( $sale ) >= Devices::timeout( $sale->device_id ) ) {
				if ( $device && '' !== $reference ) { Devices::cancel( $device, $reference ); }
				self::fail_device( $sale, 'timeout', '' );
			} else {
				return self::device_state( $sale, is_wp_error( $state ) ? $state->get_error_message() : $state['message'] );
			}
			$sale = self::sale( $sale_id );
		}
		return self::device_state( $sale );
	}

	/**
	 * Cancels a pending terminal payment (cashier). When the terminal refuses (card already presented)
	 * the sale stays pending and the current state decides.
	 *
	 * @return array|\WP_Error See device_state().
	 */
	public static function cancel_device_payment( $sale_id ) {
		$sale = self::sale( $sale_id );
		if ( ! $sale || self::DEVICE_METHOD !== $sale->payment_method ) { return self::error( 'wpcap_pos_device_sale', __( 'Kein Kassenverkauf mit Terminalzahlung gefunden.', 'pass-captain' ), 404 ); }
		if ( 'pending' !== $sale->status || 'pending' !== $sale->device_status ) { return self::device_state( $sale ); }
		$device = self::device_of( $sale );
		if ( $device && '' !== (string) $sale->device_reference ) {
			$cancelled = Devices::cancel( $device, $sale->device_reference );
			if ( is_wp_error( $cancelled ) ) {
				$state = self::poll_device_payment( $sale->sale_id );
				if ( is_array( $state ) && 'pending' !== $state['status'] ) { return $state; }
				return self::error( 'wpcap_pos_device_cancel', __( 'Die Zahlung konnte am Terminal nicht abgebrochen werden (z. B. Karte bereits vorgelegt). Bitte das Ergebnis am Terminal abwarten.', 'pass-captain' ), 409 );
			}
		}
		self::fail_device( $sale, 'cancelled', '' );
		return self::device_state( self::sale( $sale->sale_id ) );
	}

	/**
	 * State of a terminal sale for the POS: status pending|succeeded|unbooked|failed|cancelled|timeout,
	 * message, sale_id, receipt (number once booked), elapsed and timeout (seconds).
	 * "unbooked": the payment is confirmed but the sale is not booked yet (e.g. fiscal receipt pending);
	 * each call retries the booking.
	 */
	public static function device_state( $sale, $message = '' ) {
		$status = 'pending';
		if ( 'failed' === $sale->status ) {
			$status = in_array( $sale->device_status, array( 'cancelled', 'timeout', 'amount_mismatch' ), true ) ? $sale->device_status : 'failed';
			$texts = array(
				'amount_mismatch' => __( 'Der am Terminal belastete Betrag stimmt nicht mit der Bestellung überein. Bitte die Zahlung am Terminal erstatten und den Verkauf neu erfassen.', 'pass-captain' ),
				'failed' => __( 'Die Zahlung am Terminal ist fehlgeschlagen. Bitte erneut versuchen oder eine andere Zahlart wählen.', 'pass-captain' ),
				'cancelled' => __( 'Die Zahlung am Terminal wurde abgebrochen. Bitte erneut versuchen oder eine andere Zahlart wählen.', 'pass-captain' ),
				'timeout' => __( 'Zeitüberschreitung: Am Terminal wurde keine Zahlung bestätigt. Bitte erneut versuchen oder eine andere Zahlart wählen.', 'pass-captain' ),
			);
			$message = $texts[ $status ] . ( '' !== (string) $sale->device_message ? ' (' . $sale->device_message . ')' : '' );
		} elseif ( 'pending' === $sale->status && 'succeeded' === $sale->device_status ) {
			$booked = self::finalize( (int) $sale->sale_id );
			if ( is_wp_error( $booked ) ) {
				$status = 'unbooked';
				$message = __( 'Die Kartenzahlung ist bestätigt.', 'pass-captain' ) . ' ' . $booked->get_error_message();
			} else {
				$status = 'succeeded';
				$sale = $booked['sale'];
			}
		} elseif ( 'pending' !== $sale->status ) {
			$status = 'succeeded';
		}
		return array(
			'status' => $status,
			'message' => (string) $message,
			'sale_id' => (int) $sale->sale_id,
			'receipt' => self::is_booked( $sale ) ? (string) $sale->receipt_number : '',
			'elapsed' => self::device_elapsed( $sale ),
			'timeout' => Devices::timeout( $sale->device_id ),
		);
	}

	/** Unfinished sales of a session (order exists but no receipt yet). */
	public static function unfinished_sales( $session_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE session_id=%d AND status='pending' ORDER BY sale_id", absint( $session_id ) ) );
	}

	/* ------------------------------------------------------------------ storno */

	/** Refund idempotency key of a storno attempt (a new attempt after a rejected one gets a new key). */
	public static function storno_key( $sale_id, $attempt = 0 ) {
		return 'pos-storno-' . absint( $sale_id ) . ( $attempt ? '-' . absint( $attempt ) : '' );
	}

	/** Whether the current user may storno checked-in tickets (with a reason). */
	public static function can_override_checkin() {
		return current_user_can( Shifts::OVERRIDE_CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Storno of a booked sale (whole sale or single tickets) through Refunds via the pos_* gateway.
	 * Requires the refund capability (Refunds enforces it, incl. 2FA and the approval threshold).
	 *
	 * The payout is booked as a negative movement with its own storno receipt number into the
	 * sale's shift while it is open, otherwise into the currently open shift (of the user or of the
	 * sale's register). Cash and card stornos need such an open shift. Checked-in tickets are only
	 * refunded with the override capability and a reason. After a rejected storno the sale is
	 * "completed" again and a new storno can be requested.
	 *
	 * @param int[]  $ticket_ids      Tickets to storno; empty = all tickets not yet refunded.
	 * @param string $override_reason Reason for refunding checked-in tickets (override capability).
	 * @return object|\WP_Error Sale row.
	 */
	public static function storno( $sale_id, $reason = '', array $ticket_ids = array(), $override_reason = '' ) {
		global $wpdb;
		$sale = self::sale( $sale_id );
		if ( ! $sale || ! $sale->order_id || in_array( $sale->status, array( 'pending', 'failed' ), true ) ) { return self::error( 'wpcap_pos_sale', __( 'Verkauf nicht gefunden.', 'pass-captain' ), 404 ); }
		$session = self::session( $sale->session_id );
		if ( ! $session ) { return self::error( 'wpcap_pos_session', __( 'Kassensitzung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Scope::can_order( $sale->order_id, self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$target = Shifts::target_shift_for_sale( $sale );
		$user_id = get_current_user_id();
		$allowed = Shifts::is_cashier( $session, $user_id ) || current_user_can( 'manage_options' ) || current_user_can( Refunds::APPROVE_CAP )
			|| ( $target && (int) $target->register_id === (int) $session->register_id && Shifts::is_cashier( $target, $user_id ) );
		if ( ! $allowed ) { return self::error( 'wpcap_forbidden', __( 'Nur Verkäufe der eigenen Kassensitzung können storniert werden.', 'pass-captain' ), 403 ); }
		if ( 'voided' === $sale->status ) { return $sale; }
		if ( 'storno_pending' === $sale->status ) {
			self::reset_rejected_stornos( $sale->session_id );
			$sale = self::sale( $sale->sale_id );
			if ( 'storno_pending' === $sale->status ) { return $sale; }
		}
		// Tickets: the requested ones must be open (not refunded) tickets of the order.
		$refundable = array_map( static function ( $t ) { return (int) $t->ticket_id; }, Orders::refundable_tickets( (int) $sale->order_id ) );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		$targets = $requested ? $requested : $refundable;
		if ( ! $targets || array_diff( $targets, $refundable ) ) { return self::error( 'wpcap_pos_storno_tickets', __( 'Die gewählten Tickets gehören nicht zu diesem Verkauf oder wurden bereits storniert.', 'pass-captain' ), 409 ); }
		// Used = checked in, or admitted before (re-entry, multi-entry and pass tickets stay "valid").
		$checked_in = Orders::used_ticket_ids( $targets );
		$override_reason = trim( sanitize_text_field( (string) $override_reason ) );
		if ( $checked_in && ( ! self::can_override_checkin() || '' === $override_reason ) ) {
			return self::error( 'wpcap_pos_checked_in', self::can_override_checkin()
				? __( 'Mindestens ein Ticket wurde bereits eingecheckt. Für den Storno bitte einen Grund für die Ausnahme angeben.', 'pass-captain' )
				: __( 'Mindestens ein Ticket wurde bereits eingecheckt und kann an der Kasse nicht storniert werden.', 'pass-captain' ), 409 );
		}
		if ( ! $target && Shifts::needs_shift( $sale->payment_method ) ) {
			return self::error( 'wpcap_pos_no_shift', __( 'Für die Auszahlung muss eine Kassenschicht geöffnet sein. Bitte zuerst eine Schicht öffnen.', 'pass-captain' ), 409 );
		}
		$partial = count( $targets ) < count( $refundable );
		sort( $targets );
		$key = $partial ? 'pos-storno-' . (int) $sale->sale_id . '-t' . substr( md5( implode( ',', $targets ) ), 0, 12 ) . '-' . (int) $sale->storno_attempt : self::storno_key( $sale->sale_id, (int) $sale->storno_attempt );
		$text = 'POS-Storno ' . $sale->receipt_number . ( '' !== (string) $reason ? ': ' . $reason : '' ) . ( $checked_in ? ' [' . __( 'eingecheckt, Ausnahme:', 'pass-captain' ) . ' ' . $override_reason . ']' : '' );
		Shifts::$storno_override = $checked_in ? $override_reason : '';
		Shifts::$at_till = true;
		// The box office checked its own exception (reason + capability) above.
		$refund = Refunds::request( (int) $sale->order_id, $partial ? $targets : array(), $text, $key, $checked_in ? array( 'allow_used' => true ) : array() );
		if ( ! is_wp_error( $refund ) && 'completed' === $refund['status'] ) {
			self::on_refund_completed( (int) $refund['refund_id'], (int) $sale->order_id );
		}
		Shifts::$storno_override = '';
		Shifts::$at_till = false;
		if ( is_wp_error( $refund ) ) { return $refund; }
		if ( $checked_in ) {
			Audit::record( 'pos_storno_override', 'order', (int) $sale->order_id, array( 'sale_id' => (int) $sale->sale_id, 'ticket_ids' => $checked_in, 'reason' => $override_reason, 'refund_id' => (int) $refund['refund_id'] ) );
		}
		if ( 'completed' === $refund['status'] ) {
			// Booked above.
		} elseif ( 'pending_approval' === $refund['status'] ) {
			if ( ! $partial ) {
				$wpdb->update( "{$wpdb->prefix}wpcap_boxoffice_sales", array( 'status' => 'storno_pending', 'storno_refund_id' => (int) $refund['refund_id'] ), array( 'sale_id' => (int) $sale->sale_id, 'status' => 'completed' ) );
			}
		} else {
			// Rejected/failed refund for this key: allow a fresh attempt next time.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_boxoffice_sales SET storno_attempt=storno_attempt+1,storno_refund_id=0 WHERE sale_id=%d AND status='completed'", $sale->sale_id ) );
			return self::error( 'wpcap_pos_storno_failed', __( 'Der Storno wurde abgelehnt oder ist fehlgeschlagen. Bitte erneut versuchen.', 'pass-captain' ), 409 );
		}
		return self::sale( $sale->sale_id );
	}

	/** Puts sales whose storno refund was rejected back to "completed" (new attempt key). */
	public static function reset_rejected_stornos( $session_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.sale_id FROM {$p}wpcap_boxoffice_sales s INNER JOIN {$p}wpcap_refunds r ON r.refund_id=s.storno_refund_id WHERE s.session_id=%d AND s.status='storno_pending' AND r.status IN ('rejected','failed')", absint( $session_id ) ) );
		foreach ( $rows as $row ) { self::reset_storno( (int) $row->sale_id ); }
		return count( $rows );
	}

	private static function reset_storno( $sale_id ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_boxoffice_sales SET status='completed',storno_refund_id=0,storno_attempt=storno_attempt+1 WHERE sale_id=%d AND status='storno_pending'", absint( $sale_id ) ) );
		if ( $done ) { Audit::record( 'pos_storno_rejected', 'pos_sale', absint( $sale_id ) ); }
	}

	/** wpcap_refund_rejected: a rejected storno refund puts the sale back to "completed". */
	public static function on_refund_rejected( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$sale_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT sale_id FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE storno_refund_id=%d AND status='storno_pending'", absint( $refund_id ) ) );
		if ( $sale_id ) { self::reset_storno( $sale_id ); }
	}

	/** Approver rejects a pending storno (e.g. to close the session). */
	public static function reject_storno( $sale_id, $reason = '' ) {
		$sale = self::sale( $sale_id );
		if ( ! $sale || 'storno_pending' !== $sale->status ) { return self::error( 'wpcap_pos_sale', __( 'Für diesen Verkauf wartet kein Storno.', 'pass-captain' ), 409 ); }
		if ( ! Scope::can_order( $sale->order_id, self::CAP ) && ! current_user_can( 'manage_options' ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$result = Refunds::reject( (int) $sale->storno_refund_id, '' !== (string) $reason ? $reason : 'POS-Storno verworfen' );
		if ( is_wp_error( $result ) ) {
			// Already decided elsewhere: resync with the refund state.
			self::reset_rejected_stornos( $sale->session_id );
			return $result;
		}
		self::on_refund_rejected( (int) $sale->storno_refund_id );
		return self::sale( $sale_id );
	}

	/**
	 * wpcap_refund_completed for box office orders (any refund, also from the order screen):
	 * makes sure the payout is booked into a shift (Shifts::book_refund(); cash/card payouts were
	 * already booked by the gateway) and marks the sale voided once no ticket is left, with the
	 * storno receipt number of the payout (or a new one if no shift was open). Idempotent.
	 */
	public static function on_refund_completed( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund = Refunds::get( $refund_id );
		if ( ! $refund ) { return; }
		$sale = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE order_id=%d", (int) $refund['order_id'] ) );
		if ( ! $sale || ! self::is_booked( $sale ) ) { return; }
		$movement = Shifts::book_refund( (int) $refund['refund_id'], false );
		// An open payout (A488) has no movement (and no payout receipt) yet.
		$movement = is_wp_error( $movement ) || ! isset( $movement->movement_id, $movement->receipt_number ) ? null : $movement;
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0", (int) $refund['order_id'] ) );
		if ( $remaining > 0 ) { return; }
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE sale_id=%d FOR UPDATE", $sale->sale_id ) );
		if ( ! $locked || ! in_array( $locked->status, array( 'completed', 'storno_pending' ), true ) ) { $wpdb->query( 'COMMIT' ); return; }
		$number = $movement && $movement->receipt_number ? $movement->receipt_number : Invoices::next_document_number( 'receipt', self::receipt_prefix(), true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return; }
		$wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'status' => 'voided', 'storno_refund_id' => (int) $refund_id, 'storno_receipt_number' => $number, 'voided_at' => current_time( 'mysql', true ), 'voided_by' => get_current_user_id() ), array( 'sale_id' => (int) $sale->sale_id ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'pos_storno', 'order', (int) $refund['order_id'], array( 'sale_id' => (int) $sale->sale_id, 'refund_id' => (int) $refund_id, 'receipt' => $number, 'movement_id' => $movement ? (int) $movement->movement_id : 0 ) );
	}

	/* ------------------------------------------------------------------ receipt */

	/**
	 * Cashier as printed on customer receipts (setting boxoffice_receipt_cashier): operator number
	 * "Bediener 12" (default – no full name on paper handed to customers), initials or the full name.
	 */
	public static function receipt_cashier( $user_id ) {
		$mode = (string) Settings::get( 'boxoffice_receipt_cashier' );
		if ( 'name' === $mode ) { return self::user_name( $user_id ); }
		if ( 'initials' === $mode ) {
			$parts = preg_split( '/\s+/u', trim( self::user_name( $user_id ) ) );
			$initials = implode( '', array_map( static function ( $part ) { return mb_strtoupper( mb_substr( (string) $part, 0, 1 ) ); }, array_filter( (array) $parts, static function ( $part ) { return '' !== $part && '#' !== $part[0]; } ) ) );
			if ( '' !== $initials ) { return $initials; }
		}
		/* translators: %d: user ID */
		return sprintf( __( 'Bediener %d', 'pass-captain' ), absint( $user_id ) );
	}

	public static function user_name( $user_id ) {
		$user = function_exists( 'get_userdata' ) ? get_userdata( absint( $user_id ) ) : null;
		return $user && ! empty( $user->display_name ) ? $user->display_name : '#' . absint( $user_id );
	}

	/** Register name of a shift (for receipts). */
	public static function register_label( $session_id ) {
		$shift = Shifts::get( $session_id );
		$register = $shift ? Registers::get( $shift->register_id ) : null;
		return $register ? $register->name : '–';
	}

	/** Whether a sale was booked (has a receipt number); only booked sales have a receipt. */
	public static function is_booked( $sale ) {
		return $sale && '' !== (string) $sale->receipt_number && in_array( $sale->status, self::BOOKED_STATES, true );
	}

	/** Receipt data of a booked sale (also used for the storno receipt); null for unbooked sales. */
	public static function receipt( $sale_id ) {
		$sale = self::sale( $sale_id );
		if ( ! self::is_booked( $sale ) ) { return null; }
		$order = Orders::get( $sale->order_id );
		$tz = Scope::site_timezone();
		$items = array();
		$vat = array();
		foreach ( Orders::items_for_order( $sale->order_id ) as $item ) {
			$gross = (int) $item->line_total_minor;
			$tax = (int) $item->tax_minor;
			$rate = (int) $item->tax_rate_bp;
			$items[] = array( 'name' => $item->name, 'event' => $item->event_name, 'quantity' => (int) $item->quantity, 'unit_minor' => (int) $item->unit_price_minor, 'total_minor' => $gross, 'tax_rate_bp' => $rate );
			if ( ! isset( $vat[ $rate ] ) ) { $vat[ $rate ] = array( 'gross' => 0, 'tax' => 0, 'net' => 0 ); }
			$vat[ $rate ]['gross'] += $gross;
			$vat[ $rate ]['tax'] += $tax;
			$vat[ $rate ]['net'] += $gross - $tax;
		}
		ksort( $vat );
		$discount = $order ? (int) $order->discount_minor : 0;
		return array(
			'order_id' => (int) $sale->order_id,
			'sale_id' => (int) $sale->sale_id,
			'number' => $sale->receipt_number,
			'storno_number' => (string) $sale->storno_receipt_number,
			'status' => $sale->status,
			'date' => \PassCaptain\Frontend\Format::local( $sale->created_at, $tz ),
			'voided_date' => $sale->voided_at ? \PassCaptain\Frontend\Format::local( $sale->voided_at, $tz ) : '',
			'seller' => array( 'name' => (string) Settings::get( 'seller_name' ), 'address' => (string) Settings::get( 'seller_address' ), 'vat_id' => (string) Settings::get( 'seller_vat_id' ) ),
			'items' => $items,
			'discount_minor' => $discount,
			'vat' => $vat,
			'total_minor' => (int) $sale->total_minor,
			'tax_minor' => (int) $sale->tax_minor,
			'currency' => $sale->currency,
			'method' => $sale->payment_method,
			'method_label' => self::method_label( $sale->payment_method ),
			'tendered_minor' => null === $sale->tendered_minor ? null : (int) $sale->tendered_minor,
			'change_minor' => null === $sale->tendered_minor ? null : max( 0, (int) $sale->tendered_minor - (int) $sale->total_minor ),
			'cashier' => self::receipt_cashier( $sale->cashier_id ),
			'session_id' => (int) $sale->session_id,
			'register' => self::register_label( $sale->session_id ),
			'order' => $order ? $order->public_id : '',
			'footer' => (string) Settings::get( 'boxoffice_receipt_footer' ),
		);
	}

	/** 80 mm thermal printer friendly receipt (HTML, auto print). $storno prints the storno receipt. */
	public static function receipt_html( $sale_id, $storno = false ) {
		$r = self::receipt( $sale_id );
		if ( ! $r ) { return ''; }
		$storno = $storno && '' !== $r['storno_number'];
		$sign = $storno ? '-' : '';
		$m = static function ( $minor ) use ( $r ) { return \PassCaptain\Money::format( $minor, $r['currency'] ); };
		$h = '<!doctype html><html ' . \PassCaptain\Frontend\Format::html_lang_attributes() . '><head><meta charset="utf-8"><title>' . esc_html( $storno ? $r['storno_number'] : $r['number'] ) . '</title>'
			. '<style>@page{size:80mm auto;margin:0}*{box-sizing:border-box}body{width:80mm;margin:0;padding:3mm 4mm;font:12px/1.3 "DejaVu Sans Mono",monospace;color:#000}h1{font-size:15px;margin:0 0 2mm;text-align:center}p{margin:0 0 1.5mm}.c{text-align:center}table{width:100%;border-collapse:collapse}td{padding:.4mm 0;vertical-align:top}td.n{text-align:right;white-space:nowrap}.sep{border-top:1px dashed #000;margin:2mm 0}.big td{font-size:15px;font-weight:bold}.small{font-size:10px}@media print{.noprint{display:none}}</style></head><body data-wpcap-autoprint>';
		$h .= '<p class="c"><strong>' . esc_html( $r['seller']['name'] ) . '</strong><br>' . nl2br( esc_html( $r['seller']['address'] ) ) . ( $r['seller']['vat_id'] ? '<br>' . esc_html( __( 'USt-IdNr.:', 'pass-captain' ) . ' ' . $r['seller']['vat_id'] ) : '' ) . '</p>';
		$h .= '<h1>' . esc_html( $storno ? __( 'STORNOBELEG', 'pass-captain' ) : __( 'Beleg', 'pass-captain' ) ) . '</h1>';
		$h .= '<p>' . esc_html( __( 'Beleg-Nr.:', 'pass-captain' ) . ' ' . ( $storno ? $r['storno_number'] : $r['number'] ) ) . '<br>';
		if ( $storno ) { $h .= esc_html( __( 'Storno zu Beleg', 'pass-captain' ) . ' ' . $r['number'] ) . '<br>'; }
		$h .= esc_html( __( 'Datum:', 'pass-captain' ) . ' ' . ( $storno ? $r['voided_date'] : $r['date'] ) ) . '<br>' . esc_html( __( 'Kasse/Schicht/Bediener:', 'pass-captain' ) . ' ' . $r['register'] . ' / ' . $r['session_id'] . ' / ' . $r['cashier'] ) . '</p><div class="sep"></div><table>';
		foreach ( $r['items'] as $i ) {
			$h .= '<tr><td colspan="2">' . esc_html( $i['event'] ) . '<br>' . esc_html( $i['name'] ) . '</td></tr><tr><td>' . esc_html( $i['quantity'] . ' x ' . $m( $i['unit_minor'] ) . ' (' . number_format_i18n( $i['tax_rate_bp'] / 100, 2 ) . ' %)' ) . '</td><td class="n">' . esc_html( $sign . $m( $i['total_minor'] ) ) . '</td></tr>';
		}
		if ( $r['discount_minor'] ) { $h .= '<tr><td>' . esc_html__( 'Rabatt', 'pass-captain' ) . '</td><td class="n">' . esc_html( ( $storno ? '' : '-' ) . $m( $r['discount_minor'] ) ) . '</td></tr>'; }
		$h .= '</table><div class="sep"></div><table class="big"><tr><td>' . esc_html__( 'SUMME', 'pass-captain' ) . '</td><td class="n">' . esc_html( $sign . $m( $r['total_minor'] ) ) . '</td></tr></table><table class="small">';
		foreach ( $r['vat'] as $rate => $v ) {
			$h .= '<tr><td>' . esc_html( sprintf( /* translators: 1: VAT rate in percent, 2: net amount */ __( 'USt %1$s %%: Netto %2$s', 'pass-captain' ), number_format_i18n( $rate / 100, 2 ), $sign . $m( $v['net'] ) ) ) . '</td><td class="n">' . esc_html( $sign . $m( $v['tax'] ) ) . '</td></tr>';
		}
		$h .= '</table><div class="sep"></div><p>' . esc_html( __( 'Zahlart:', 'pass-captain' ) . ' ' . $r['method_label'] ) . '</p>';
		if ( ! $storno && null !== $r['tendered_minor'] ) {
			$h .= '<table><tr><td>' . esc_html__( 'Gegeben', 'pass-captain' ) . '</td><td class="n">' . esc_html( $m( $r['tendered_minor'] ) ) . '</td></tr><tr><td>' . esc_html__( 'Rückgeld', 'pass-captain' ) . '</td><td class="n">' . esc_html( $m( $r['change_minor'] ) ) . '</td></tr></table>';
		}
		$h .= '<p class="small">' . esc_html( __( 'Bestellung:', 'pass-captain' ) . ' ' . substr( $r['order'], 0, 12 ) ) . '</p>';
		$h .= ( ! $storno ) ? \PassCaptain\Fiscalization\ReceiptRenderer::render( (int) $r['order_id'] ) : '';
		if ( '' !== $r['footer'] ) { $h .= '<p class="c small">' . nl2br( esc_html( $r['footer'] ) ) . '</p>'; }
		$h .= '<p class="noprint c">' . \PassCaptain\Frontend\PrintButton::html( __( 'Drucken', 'pass-captain' ) ) . '</p></body></html>';
		return $h;
	}
}
