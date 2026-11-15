<?php
/**
 * Box office: cash sessions, fast sales, receipts and storno.
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

	public static function method_label( $method ) {
		$methods = self::all_methods();
		return $methods[ $method ]['label'] ?? $method;
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function receipt_prefix() {
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

	/* ------------------------------------------------------------------ sessions */

	public static function session( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sessions WHERE session_id=%d", absint( $session_id ) ) );
	}

	public static function open_session_for( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sessions WHERE open_key=%d AND status='open'", absint( $user_id ) ) );
	}

	/** Opens the cash session of the current user (one open session per cashier). */
	public static function open_session( $float_minor, $currency = 'EUR', $note = '', $location_id = 0, $register_id = '' ) {
		global $wpdb;
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$user_id = get_current_user_id();
		$existing = self::open_session_for( $user_id );
		if ( $existing ) { return $existing; }
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$location_id = absint( $location_id );
		$register_id = (string) $register_id;
		if ( strlen( $register_id ) > 64 || ! preg_match( '/^[A-Za-z0-9_.:-]*$/', $register_id ) ) { return self::error( 'wpcap_pos_register_invalid', __( 'Kassen-ID ist ungültig.', 'pass-captain' ) ); }
		if ( ! TerminalRegistry::allows( $location_id, $register_id ) ) { return self::error( 'wpcap_pos_terminal', __( 'Standort und Kasse sind nicht als Register freigegeben.', 'pass-captain' ), 403 ); }
		$wpdb->insert( $wpdb->prefix . 'wpcap_boxoffice_sessions', array(
			'user_id' => $user_id,
			'open_key' => $user_id,
			'currency' => 3 === strlen( $currency ) ? $currency : 'EUR',
			'location_id' => $location_id,
			'register_id' => $register_id,
			'opening_float_minor' => max( 0, (int) $float_minor ),
			'note' => mb_substr( sanitize_text_field( (string) $note ), 0, 255 ),
			'status' => 'open',
			'opened_at' => current_time( 'mysql', true ),
		) );
		$session = self::open_session_for( $user_id );
		if ( $session ) {
			Audit::record( 'pos_session_opened', 'pos_session', (int) $session->session_id, array( 'float_minor' => (int) $session->opening_float_minor, 'location_id' => (int) $session->location_id, 'register_id' => (string) $session->register_id ) );
		}
		return $session ? $session : self::error( 'wpcap_pos_session', __( 'Die Kassensitzung konnte nicht geöffnet werden.', 'pass-captain' ), 500 );
	}

	/** Sale states that count in session totals (pending/failed rows have no receipt yet). */
	const BOOKED_STATES = array( 'completed', 'voided', 'storno_pending' );

	/**
	 * Totals of a session.
	 *
	 * Per method: count/gross (booked sales), voided_count/voided (stornos: the amount of the storno
	 * refund, i.e. what was still left after earlier partial refunds), refunded_count/refunded (other
	 * refunds of box office orders booked into this session, see book_refund()) and
	 * net = gross − voided − refunded. expected_cash = opening float + net of cash.
	 *
	 * @return array{methods:array,expected_cash:int,net_total:int,sales:int,voided:int,refunds:int,currency:string}
	 */
	public static function session_totals( $session_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$session = self::session( $session_id );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.payment_method,s.status,COUNT(*) AS n,COALESCE(SUM(s.total_minor),0) AS amount,COALESCE(SUM(CASE WHEN s.status='voided' THEN COALESCE(r.amount_minor,s.total_minor) ELSE 0 END),0) AS voided_amount FROM %i s LEFT JOIN %i r ON r.refund_id=s.storno_refund_id AND s.storno_refund_id>0 AND r.status='completed' WHERE s.session_id=%d AND s.status IN ('completed','voided','storno_pending') GROUP BY s.payment_method,s.status", $p . 'wpcap_boxoffice_sales', $p . 'wpcap_refunds', absint( $session_id ) ) );
		$empty = array( 'count' => 0, 'gross' => 0, 'voided_count' => 0, 'voided' => 0, 'refunded_count' => 0, 'refunded' => 0, 'net' => 0 );
		$methods = array();
		foreach ( array_keys( self::methods() ) as $m ) { $methods[ $m ] = $empty; }
		$sales = 0;
		$voided = 0;
		foreach ( $rows as $r ) {
			$m = $r->payment_method;
			if ( ! isset( $methods[ $m ] ) ) { $methods[ $m ] = $empty; }
			$methods[ $m ]['count'] += (int) $r->n;
			$methods[ $m ]['gross'] += (int) $r->amount;
			$sales += (int) $r->n;
			if ( 'voided' === $r->status ) {
				$methods[ $m ]['voided_count'] += (int) $r->n;
				$methods[ $m ]['voided'] += (int) $r->voided_amount;
				$voided += (int) $r->n;
			}
		}
		$refunds = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT payment_method,COUNT(*) AS n,COALESCE(SUM(amount_minor),0) AS amount FROM %i WHERE session_id=%d AND session_id>0 GROUP BY payment_method', $p . 'wpcap_boxoffice_refunds', absint( $session_id ) ) ) as $r ) {
			$m = $r->payment_method;
			if ( ! isset( $methods[ $m ] ) ) { $methods[ $m ] = $empty; }
			$methods[ $m ]['refunded_count'] += (int) $r->n;
			$methods[ $m ]['refunded'] += (int) $r->amount;
			$refunds += (int) $r->n;
		}
		$net_total = 0;
		foreach ( $methods as $m => $t ) {
			$methods[ $m ]['net'] = $t['gross'] - $t['voided'] - $t['refunded'];
			$net_total += $methods[ $m ]['net'];
		}
		return array(
			'methods' => $methods,
			'expected_cash' => ( $session ? (int) $session->opening_float_minor : 0 ) + $methods['cash']['net'],
			'net_total' => $net_total,
			'sales' => $sales,
			'voided' => $voided,
			'refunds' => $refunds,
			'currency' => $session ? $session->currency : 'EUR',
		);
	}

	/**
	 * Books a completed refund of a box office order that is not a storno (e.g. a partial refund on the
	 * refund page) into a register session, so the expected cash balance follows the money paid out:
	 * - the sale's session while it is open;
	 * - after it was closed: the currently open session of the same register (same location and a
	 *   non-empty register ID; the most recently opened one);
	 * - otherwise none: the refund is recorded with session 0 and audited as `pos_refund_unassigned`;
	 *   the cash is paid out and booked outside a register session (see docs/OPERATIONS.md).
	 * Runs under the session row lock, so it serialises with close_session(). Idempotent per refund.
	 *
	 * @return int Session the refund was booked into (0 = none), -1 when it is not a box office refund.
	 */
	public static function book_refund( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund = Refunds::get( $refund_id );
		if ( ! $refund || 'completed' !== $refund['status'] || (int) $refund['amount_minor'] <= 0 ) { return -1; }
		$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT sale_id,session_id,payment_method,storno_refund_id,storno_attempt FROM %i WHERE order_id=%d', $p . 'wpcap_boxoffice_sales', (int) $refund['order_id'] ) );
		if ( ! $sale || (int) $sale->storno_refund_id === (int) $refund['refund_id'] || self::is_storno_refund( $refund, $sale ) ) { return -1; }
		$known = $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE refund_id=%d', $p . 'wpcap_boxoffice_refunds', (int) $refund['refund_id'] ) );
		if ( null !== $known ) { return (int) $known; }
		$wpdb->query( 'START TRANSACTION' );
		$origin = $wpdb->get_row( $wpdb->prepare( 'SELECT session_id,status,location_id,register_id FROM %i WHERE session_id=%d FOR UPDATE', $p . 'wpcap_boxoffice_sessions', (int) $sale->session_id ) );
		$target = 0;
		if ( $origin && 'open' === $origin->status ) {
			$target = (int) $origin->session_id;
		} elseif ( $origin && '' !== (string) $origin->register_id ) {
			$target = (int) $wpdb->get_var( $wpdb->prepare( "SELECT session_id FROM %i WHERE status='open' AND location_id=%d AND register_id=%s ORDER BY opened_at DESC, session_id DESC LIMIT 1 FOR UPDATE", $p . 'wpcap_boxoffice_sessions', (int) $origin->location_id, (string) $origin->register_id ) );
		}
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (refund_id,sale_id,session_id,payment_method,amount_minor,booked_at) VALUES (%d,%d,%d,%s,%d,%s)', $p . 'wpcap_boxoffice_refunds', (int) $refund['refund_id'], (int) $sale->sale_id, $target, (string) $sale->payment_method, (int) $refund['amount_minor'], current_time( 'mysql', true ) ) );
		if ( false === $inserted ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			Log::error( 'pos_refund_book_failed', sprintf( 'refund %d of box office sale %d could not be booked into a register session: %s', (int) $refund['refund_id'], (int) $sale->sale_id, $error ) );
			return 0;
		}
		$wpdb->query( 'COMMIT' );
		$details = array( 'sale_id' => (int) $sale->sale_id, 'refund_id' => (int) $refund['refund_id'], 'session_id' => $target, 'method' => (string) $sale->payment_method, 'amount_minor' => (int) $refund['amount_minor'], 'sale_session_id' => (int) $sale->session_id );
		Audit::record( $target ? 'pos_refund_booked' : 'pos_refund_unassigned', 'order', (int) $refund['order_id'], $details );
		return $target;
	}

	/** Whether a refund row is the storno refund of a sale (idempotency key of any storno attempt). */
	private static function is_storno_refund( array $refund, $sale ) {
		for ( $attempt = 0; $attempt <= (int) $sale->storno_attempt; $attempt++ ) {
			if ( hash_equals( hash( 'sha256', 'refund|' . (int) $refund['order_id'] . '|' . self::storno_key( $sale->sale_id, $attempt ) ), (string) $refund['idempotency_key'] ) ) { return true; }
		}
		return false;
	}

	/** Refunds of box office orders booked into a session (session overview). */
	public static function session_refunds( $session_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT br.*,s.receipt_number,s.session_id AS sale_session_id FROM %i br INNER JOIN %i s ON s.sale_id=br.sale_id WHERE br.session_id=%d AND br.session_id>0 ORDER BY br.refund_id', $p . 'wpcap_boxoffice_refunds', $p . 'wpcap_boxoffice_sales', absint( $session_id ) ) );
	}

	/**
	 * Closes a session with the counted cash. Only the cashier (or an administrator).
	 *
	 * Refused while a storno waits for approval or a paid sale is not yet booked (see finalize()).
	 * Unpaid unfinished sales of the session are marked failed.
	 */
	public static function close_session( $session_id, $counted_minor, $note = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$session = self::session( $session_id );
		if ( ! $session ) { return self::error( 'wpcap_pos_session', __( 'Kassensitzung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( (int) $session->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) { return self::error( 'wpcap_forbidden', __( 'Nur die eigene Kassensitzung kann geschlossen werden.', 'pass-captain' ), 403 ); }
		if ( 'open' !== $session->status ) { return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung ist bereits geschlossen.', 'pass-captain' ), 409 ); }
		self::reset_rejected_stornos( $session->session_id );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE session_id=%d AND status='storno_pending'", $session->session_id ) );
		if ( $pending ) { return self::error( 'wpcap_pos_storno_pending', __( 'Ein Storno wartet noch auf Freigabe. Bitte freigeben oder ablehnen lassen, dann die Kasse abschließen.', 'pass-captain' ), 409 ); }
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_boxoffice_sessions WHERE session_id=%d FOR UPDATE", $session->session_id ) );
		if ( ! $locked || 'open' !== $locked->status ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung ist bereits geschlossen.', 'pass-captain' ), 409 ); }
		$unfinished = $wpdb->get_results( $wpdb->prepare( "SELECT s.sale_id,s.device_status,ord.payment_status FROM {$p}wpcap_boxoffice_sales s LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=s.order_id WHERE s.session_id=%d AND s.status='pending' FOR UPDATE", $session->session_id ) );
		foreach ( $unfinished as $row ) {
			if ( 'pending' === $row->device_status ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wpcap_pos_device_pending', __( 'Eine Kartenzahlung am Terminal läuft noch. Bitte abwarten oder abbrechen, dann die Kasse abschließen.', 'pass-captain' ), 409 );
			}
			if ( 'paid' === $row->payment_status || 'succeeded' === $row->device_status ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wpcap_pos_unbooked', __( 'Ein bezahlter Verkauf ist noch nicht gebucht. Bitte zuerst „Nachbuchen“ ausführen.', 'pass-captain' ), 409 );
			}
		}
		foreach ( $unfinished as $row ) {
			$wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'status' => 'failed' ), array( 'sale_id' => (int) $row->sale_id, 'status' => 'pending' ) );
		}
		$totals = self::session_totals( $session->session_id );
		$counted = max( 0, (int) $counted_minor );
		$wpdb->update( "{$p}wpcap_boxoffice_sessions", array(
			'status' => 'closed',
			'open_key' => null,
			'closing_count_minor' => $counted,
			'expected_cash_minor' => $totals['expected_cash'],
			'difference_minor' => $counted - $totals['expected_cash'],
			'totals' => wp_json_encode( $totals ),
			'closing_note' => mb_substr( sanitize_text_field( (string) $note ), 0, 255 ),
			'closed_at' => current_time( 'mysql', true ),
			'closed_by' => get_current_user_id(),
		), array( 'session_id' => (int) $session->session_id ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'pos_session_closed', 'pos_session', (int) $session->session_id, array( 'expected_cash_minor' => $totals['expected_cash'], 'counted_minor' => $counted, 'failed_unfinished' => count( $unfinished ) ) );
		return self::session( $session->session_id );
	}

	/* ------------------------------------------------------------------ sale */

	/**
	 * Ticket types of an occurrence with live availability (null = unlimited), the active participant
	 * fields (same definition as the public checkout: key, label, type, required, purpose) and whether
	 * seats come from a seat map (the box office cannot pick seats: best available ones are assigned).
	 */
	public static function ticket_types( $occurrence_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp,status,sales_start,sales_end FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id", absint( $occurrence_id ) ) );
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
				'tax_rate_bp' => (int) $r->tax_rate_bp,
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
		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_key AS `key`,label,field_type AS type,required,purpose,consent_text,consent_version FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", absint( $ticket_type_id ) ), ARRAY_A );
		return array_map( static function ( $f ) {
			$out = array( 'key' => (string) $f['key'], 'label' => (string) $f['label'], 'type' => (string) $f['type'], 'required' => (bool) (int) $f['required'], 'purpose' => (string) ( $f['purpose'] ?? '' ) );
			// Consent fields: the text the attendee agrees to (shown next to the checkbox; logged by Sales\Consents).
			if ( 'consent' === $out['type'] ) { $out += array( 'consent_text' => (string) ( $f['consent_text'] ?? '' ), 'consent_version' => (string) ( $f['consent_version'] ?? '' ) ); }
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
		if ( ! TerminalRegistry::allows( $session->location_id, $session->register_id ) ) { return self::error( 'wpcap_pos_terminal', __( 'Dieses Kassenregister ist nicht mehr freigegeben. Bitte Sitzung schließen und ein freigegebenes Register öffnen.', 'pass-captain' ), 409 ); }

		$occurrence_id = absint( $input['occurrence_id'] ?? 0 );
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id || ! Scope::can_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$methods = self::all_methods();
		$method = (string) ( $input['method'] ?? '' );
		if ( ! isset( $methods[ $method ] ) ) { return self::error( 'wpcap_pos_method', __( 'Bitte eine Zahlart wählen.', 'pass-captain' ) ); }
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

		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' !== $email && ( ! is_email( $email ) || self::is_placeholder_email( $email ) ) ) { return self::error( 'wpcap_pos_email', __( 'Die E-Mail-Adresse ist ungültig.', 'pass-captain' ) ); }
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
			'payment_provider' => $methods[ $method ]['provider'],
			'key_namespace' => 'pos',
			'source' => 'pos',
			'fiscal_location_id' => (int) $session->location_id,
			'fiscal_register_id' => (string) $session->register_id,
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

		// 3. Payment confirmation and issuance, then booking.
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

	/** Paid order with all tickets issued? Re-issues missing tickets once (idempotent core issuance). */
	private static function order_ready( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = Orders::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status || 'complete' !== $order->status ) { return false; }
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
			'location_id' => $session ? (int) $session->location_id : 0,
			'register_id' => $session ? (string) $session->register_id : '',
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

	/**
	 * Voids a sale of an open session through Refunds (full refund via the pos_* gateway).
	 * Requires the refund capability (Refunds enforces it, incl. 2FA and the approval threshold).
	 * After a rejected storno the sale is "completed" again and a new storno can be requested.
	 */
	public static function storno( $sale_id, $reason = '' ) {
		$sale = self::sale( $sale_id );
		if ( ! $sale || ! $sale->order_id || in_array( $sale->status, array( 'pending', 'failed' ), true ) ) { return self::error( 'wpcap_pos_sale', __( 'Verkauf nicht gefunden.', 'pass-captain' ), 404 ); }
		$session = self::session( $sale->session_id );
		if ( ! $session ) { return self::error( 'wpcap_pos_session', __( 'Kassensitzung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Scope::can_order( $sale->order_id, self::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		if ( (int) $session->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) && ! current_user_can( Refunds::APPROVE_CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Nur Verkäufe der eigenen Kassensitzung können storniert werden.', 'pass-captain' ), 403 ); }
		if ( 'voided' === $sale->status ) { return $sale; }
		if ( 'storno_pending' === $sale->status ) {
			self::reset_rejected_stornos( $sale->session_id );
			$sale = self::sale( $sale->sale_id );
			if ( 'storno_pending' === $sale->status ) { return $sale; }
		}
		if ( 'open' !== $session->status ) { return self::error( 'wpcap_pos_session_closed', __( 'Storno ist nur bis zum Kassenabschluss möglich. Danach bitte eine Erstattung über die Bestellung anlegen.', 'pass-captain' ), 409 ); }
		$refund = Refunds::request( (int) $sale->order_id, array(), 'POS-Storno ' . $sale->receipt_number . ( '' !== (string) $reason ? ': ' . $reason : '' ), self::storno_key( $sale->sale_id, (int) $sale->storno_attempt ) );
		if ( is_wp_error( $refund ) ) { return $refund; }
		global $wpdb;
		if ( 'completed' === $refund['status'] ) {
			self::on_refund_completed( (int) $refund['refund_id'], (int) $sale->order_id );
		} elseif ( 'pending_approval' === $refund['status'] ) {
			$wpdb->update( "{$wpdb->prefix}wpcap_boxoffice_sales", array( 'status' => 'storno_pending', 'storno_refund_id' => (int) $refund['refund_id'] ), array( 'sale_id' => (int) $sale->sale_id, 'status' => 'completed' ) );
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
	 * wpcap_refund_completed: a storno refund marks the sale voided and allocates the storno receipt
	 * number (idempotent); any other refund of a box office order is booked into a register session
	 * (book_refund(), PAY-V4).
	 */
	public static function on_refund_completed( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund = Refunds::get( $refund_id );
		if ( ! $refund ) { return; }
		$sale = $wpdb->get_row( $wpdb->prepare( "SELECT sale_id,storno_attempt FROM {$p}wpcap_boxoffice_sales WHERE order_id=%d", (int) $refund['order_id'] ) );
		if ( ! $sale ) { return; }
		if ( ! hash_equals( hash( 'sha256', 'refund|' . (int) $refund['order_id'] . '|' . self::storno_key( $sale->sale_id, (int) $sale->storno_attempt ) ), (string) $refund['idempotency_key'] ) ) {
			self::book_refund( (int) $refund_id );
			return;
		}
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE sale_id=%d FOR UPDATE", $sale->sale_id ) );
		if ( ! $locked || ! in_array( $locked->status, array( 'completed', 'storno_pending' ), true ) ) { $wpdb->query( 'COMMIT' ); return; }
		$number = Invoices::next_document_number( 'receipt', self::receipt_prefix(), true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return; }
		$wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'status' => 'voided', 'storno_refund_id' => (int) $refund_id, 'storno_receipt_number' => $number, 'voided_at' => current_time( 'mysql', true ), 'voided_by' => get_current_user_id() ), array( 'sale_id' => (int) $sale->sale_id ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'pos_storno', 'order', (int) $refund['order_id'], array( 'sale_id' => (int) $sale->sale_id, 'refund_id' => (int) $refund_id, 'receipt' => $number ) );
	}

	/* ------------------------------------------------------------------ receipt */

	public static function user_name( $user_id ) {
		$user = function_exists( 'get_userdata' ) ? get_userdata( absint( $user_id ) ) : null;
		return $user && ! empty( $user->display_name ) ? $user->display_name : '#' . absint( $user_id );
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
			'date' => Scope::local( $sale->created_at, $tz, 'd.m.Y H:i' ),
			'voided_date' => $sale->voided_at ? Scope::local( $sale->voided_at, $tz, 'd.m.Y H:i' ) : '',
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
			'cashier' => self::user_name( $sale->cashier_id ),
			'session_id' => (int) $sale->session_id,
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
		$m = static function ( $minor ) use ( $r ) { return \PassCaptain\Localization::money_number( $minor, $r['currency'] ) . ' ' . $r['currency']; };
		$h = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html( $storno ? $r['storno_number'] : $r['number'] ) . '</title>'
			. '<style>@page{size:80mm auto;margin:0}*{box-sizing:border-box}body{width:80mm;margin:0;padding:3mm 4mm;font:12px/1.3 "DejaVu Sans Mono",monospace;color:#000}h1{font-size:15px;margin:0 0 2mm;text-align:center}p{margin:0 0 1.5mm}.c{text-align:center}table{width:100%;border-collapse:collapse}td{padding:.4mm 0;vertical-align:top}td.n{text-align:right;white-space:nowrap}.sep{border-top:1px dashed #000;margin:2mm 0}.big td{font-size:15px;font-weight:bold}.small{font-size:10px}@media print{.noprint{display:none}}</style></head><body onload="window.print()">';
		$h .= '<p class="c"><strong>' . esc_html( $r['seller']['name'] ) . '</strong><br>' . nl2br( esc_html( $r['seller']['address'] ) ) . ( $r['seller']['vat_id'] ? '<br>' . esc_html( __( 'USt-IdNr.:', 'pass-captain' ) . ' ' . $r['seller']['vat_id'] ) : '' ) . '</p>';
		$h .= '<h1>' . esc_html( $storno ? __( 'STORNOBELEG', 'pass-captain' ) : __( 'Beleg', 'pass-captain' ) ) . '</h1>';
		$h .= '<p>' . esc_html( __( 'Beleg-Nr.:', 'pass-captain' ) . ' ' . ( $storno ? $r['storno_number'] : $r['number'] ) ) . '<br>';
		if ( $storno ) { $h .= esc_html( __( 'Storno zu Beleg', 'pass-captain' ) . ' ' . $r['number'] ) . '<br>'; }
		$h .= esc_html( __( 'Datum:', 'pass-captain' ) . ' ' . ( $storno ? $r['voided_date'] : $r['date'] ) ) . '<br>' . esc_html( __( 'Kasse/Bediener:', 'pass-captain' ) . ' ' . $r['session_id'] . ' / ' . $r['cashier'] ) . '</p><div class="sep"></div><table>';
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
		$h .= '<p class="noprint c"><button onclick="window.print()">' . esc_html__( 'Drucken', 'pass-captain' ) . '</button></p></body></html>';
		return $h;
	}
}
