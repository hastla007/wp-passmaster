<?php
/**
 * Reverse direction of the Voucher Captain bridge: a redeemed voucher is turned into Pass Captain tickets.
 *
 * Voucher Captain (or any other voucher system) gets this service through the versioned filter
 * `wp_pass_captain_ticket_service_v1` and calls issue_tickets() after it has reserved the voucher. The service
 * creates a free order ("paid by voucher") through the normal checkout path, so capacity, holds, attendee
 * fields and ticket mails are exactly those of a regular order. The caller's idempotency key makes every call
 * repeatable: the same key always answers with the same order and tickets, and a failed call can simply be
 * retried. Only after issue_tickets() confirmed the tickets does the voucher system commit the redemption.
 *
 * Errors are WP_Error with data `retryable` (true for temporary failures such as a busy database).
 *
 * VAT treatment by voucher purpose (Checkout::purpose(): source data, filter `wpcap_voucher_purpose`,
 * setting `voucher_default_purpose`):
 * - multi-purpose: the tickets carry their full price (or the redeemed voucher amount `amount_minor` from
 *   the source, as consideration) and full VAT; the voucher is recorded as payment (voucher snapshot
 *   `amount_paid_by_voucher`), the amount due is 0. Reports, settlements and invoices show the revenue.
 * - single-purpose: VAT was due when the voucher was sold, so the tickets are issued at price 0 / VAT 0;
 *   the voucher value (source `amount_minor`/`value_minor`, else the catalogue price) is kept in the
 *   voucher snapshot as `face_value_minor` for organizer settlements (see settlement_value_minor()).
 */
namespace PassCaptain\Integrations\Vouchers;

use PassCaptain\Audit;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketService {
	const FILTER = 'wp_pass_captain_ticket_service_v1';
	const VERSION = 1;
	/** Key namespace of voucher orders (keyed hash, so a public checkout can never claim such a key). */
	const NAMESPACE_KEY = 'voucher';
	const PROVIDER = 'voucher';
	/** Fired when tickets of an exchange are revoked or refunded in Pass Captain (not by the voucher system). */
	const REVOKED_ACTION = 'wp_pass_captain_voucher_tickets_revoked_v1';

	/** True while the voucher system itself revokes an exchange (no notification back). */
	private static $revoking = false;

	public static function register() {
		add_filter( self::FILTER, array( __CLASS__, 'service' ), 10, 2 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'order_closed' ), 30, 1 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'order_closed' ), 30, 1 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'ticket_revoked' ), 30, 1 );
	}

	public static function ticket_revoked( $ticket_id ) {
		global $wpdb;
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( $order_id ) { self::order_closed( $order_id ); }
	}

	/**
	 * Tells the voucher system that tickets of an exchange were revoked in Pass Captain (order cancelled or
	 * refunded, date or event cancelled), so it can give the voucher back. Fires on every change; `remaining`
	 * counts tickets that are still valid or were used (checked in). Listeners must be idempotent.
	 *
	 * Action `wp_pass_captain_voucher_tickets_revoked_v1` with array: redemption_key, order_public_id,
	 * remaining (int), revoked (int), reason.
	 */
	public static function order_closed( $order_id ) {
		if ( self::$revoking ) { return; }
		global $wpdb;
		$order = Orders::get( absint( $order_id ) );
		if ( ! $order || self::PROVIDER !== $order->payment_provider || 0 !== strpos( (string) $order->provider_reference, 'voucher:' ) ) { return; }
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status IN ('valid','checked_in') AND refunded=0) AS remaining,SUM(status NOT IN ('valid','checked_in') OR refunded=1) AS revoked FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", (int) $order->order_id ) );
		$revoked = (int) ( $counts->revoked ?? 0 );
		if ( $revoked < 1 && ! in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) { return; }
		do_action( self::REVOKED_ACTION, array(
			'redemption_key' => substr( (string) $order->provider_reference, strlen( 'voucher:' ) ),
			'order_public_id' => (string) $order->public_id,
			'remaining' => (int) ( $counts->remaining ?? 0 ),
			'revoked' => $revoked,
			'reason' => (string) $order->status,
		) );
	}

	const ALIASES_OPTION = 'wpcap_ticket_name_aliases';

	/**
	 * Remembers that a ticket type of an event was renamed, so vouchers sold for the old name ("customer
	 * chooses the date" with a ticket name) still find the dates under the new name.
	 */
	public static function remember_rename( $event_id, $old_name, $new_name ) {
		$event_id = absint( $event_id );
		$old_name = trim( (string) $old_name );
		$new_name = trim( (string) $new_name );
		if ( ! $event_id || '' === $old_name || '' === $new_name || 0 === strcasecmp( $old_name, $new_name ) ) { return; }
		$aliases = get_option( self::ALIASES_OPTION, array() );
		$aliases = is_array( $aliases ) ? $aliases : array();
		$aliases[ $event_id ][ self::name_key( $old_name ) ][] = $new_name;
		$aliases[ $event_id ][ self::name_key( $old_name ) ] = array_values( array_unique( $aliases[ $event_id ][ self::name_key( $old_name ) ] ) );
		update_option( self::ALIASES_OPTION, $aliases, false );
	}

	/** The name and every later name of it (renames can chain: A → B → C). */
	public static function names_for( $event_id, $name ) {
		$aliases = get_option( self::ALIASES_OPTION, array() );
		$map = is_array( $aliases ) && $event_id && isset( $aliases[ $event_id ] ) ? (array) $aliases[ $event_id ] : array();
		$names = array( $name );
		for ( $i = 0; $i < count( $names ) && $i < 50; $i++ ) {
			foreach ( (array) ( $map[ self::name_key( $names[ $i ] ) ] ?? array() ) as $next ) {
				if ( ! in_array( $next, $names, true ) ) { $names[] = (string) $next; }
			}
		}
		return $names;
	}

	private static function name_key( $name ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $name ) ) : strtolower( trim( (string) $name ) );
	}

	/** Filter callback: the service for contract version 1 (another implementation registered earlier wins). */
	public static function service( $service = null, $version = self::VERSION ) {
		if ( null !== $service || self::VERSION !== (int) $version ) { return $service; }
		return new self();
	}

	/**
	 * Issues tickets for a redeemed voucher.
	 *
	 * @param array  $source          Where the tickets come from, stored for the audit trail: provider (e.g.
	 *                                "voucher-captain"), voucher_id, redemption_key, code_hint (last characters).
	 *                                Optional: purpose ('single'|'multi', see Checkout::purpose_from_data()) and
	 *                                amount_minor (voucher value redeemed for these tickets, minor units).
	 * @param string $idempotency_key Stable key of this exchange (e.g. "vcap-voucher-123"); max. 191 characters.
	 * @param array  $target          ticket_type_id (int) and quantity (1–10). For a voucher valid for any date of
	 *                                an event also event_id (int) and optionally ticket_name (string): the chosen
	 *                                ticket type must then belong to an upcoming date of that event (and carry that
	 *                                name); see options().
	 * @param array  $recipient       email (required), name, attendees (list as in the public checkout, for
	 *                                ticket types with required attendee fields).
	 * @return array|\WP_Error order_id, order_public_id, ticket_ids (public ids), status "issued", reused (bool).
	 */
	public function issue_tickets( array $source, $idempotency_key, array $target, array $recipient ) {
		$key = sanitize_text_field( (string) $idempotency_key );
		$type_id = absint( $target['ticket_type_id'] ?? 0 );
		$quantity = absint( $target['quantity'] ?? 1 );
		$email = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 191 || ! $type_id || $quantity < 1 || $quantity > 10 || ! is_email( $email ) ) {
			return self::error( 'wpcap_ticket_request_invalid', __( 'Ticketart, Anzahl, Schlüssel und eine gültige E-Mail-Adresse sind erforderlich.', 'wp-pass-captain' ), 400 );
		}
		$existing = self::order_for_key( $key );
		if ( $existing ) {
			return self::complete( $existing, $source, true );
		}
		if ( ! empty( $target['event_id'] ) ) {
			$allowed = array_column( $this->targets( array( 'event_id' => $target['event_id'], 'ticket_name' => $target['ticket_name'] ?? '' ) ), 'ticket_type_id' );
			if ( ! in_array( $type_id, $allowed, true ) ) {
				return self::error( 'wpcap_ticket_choice_invalid', __( 'Dieser Termin kann für den Gutschein nicht gewählt werden.', 'wp-pass-captain' ), 400 );
			}
		}
		$purpose = Checkout::purpose( $source, array( 'source' => 'ticket_service', 'ticket_type_id' => $type_id, 'quantity' => $quantity ) );
		$value = self::voucher_value( $source );
		$internal = array(
			'external' => true,
			'payment_provider' => self::PROVIDER,
			'key_namespace' => self::NAMESPACE_KEY,
			'source' => 'voucher',
			'skip_rate_limit' => true,
		);
		if ( Checkout::PURPOSE_MULTI === $purpose ) {
			// Means of payment: full consideration and VAT; the redeemed voucher amount wins over the list price.
			if ( null !== $value ) {
				$internal['unit_prices'] = array( $type_id => intdiv( $value, $quantity ) );
				$internal['line_totals'] = array( $type_id => $value );
			}
		} else {
			// Single-purpose: VAT was paid when the voucher was sold, the redemption is not taxed again.
			$internal['unit_prices'] = array( $type_id => 0 );
			$internal['line_totals'] = array( $type_id => 0 );
			$internal['line_taxes'] = array( $type_id => 0 );
		}
		$order = Orders::create(
			array(
				'email' => $email,
				'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $quantity ) ),
				'attendees' => is_array( $recipient['attendees'] ?? null ) ? $recipient['attendees'] : array(),
				'idempotency_key' => $key,
			),
			$internal
		);
		if ( is_wp_error( $order ) ) { return $order; }
		self::record_voucher( (int) $order['order_id'], $purpose, $value, $source, $type_id, $quantity );
		$row = Orders::get( (int) $order['order_id'] );
		return $row ? self::complete( $row, $source, ! empty( $order['reused'] ) ) : self::error( 'wpcap_ticket_issue_failed', __( 'Die Tickets konnten nicht ausgestellt werden.', 'wp-pass-captain' ), 503, true );
	}

	/** Current state of an exchange (null when the key is unknown). */
	public function get( $idempotency_key ) {
		$order = self::order_for_key( sanitize_text_field( (string) $idempotency_key ) );
		return $order ? self::data( $order, false ) : null;
	}

	/**
	 * Revokes the tickets of an exchange (e.g. the voucher purchase was refunded). Checked-in tickets stay valid.
	 * Idempotent; unknown keys are answered with true.
	 */
	public function revoke_tickets( $idempotency_key, $reason = '' ) {
		$order = self::order_for_key( sanitize_text_field( (string) $idempotency_key ) );
		if ( ! $order ) { return true; }
		self::$revoking = true;
		try {
			$cancelled = Orders::cancel_order( (int) $order->order_id, '' !== (string) $reason ? (string) $reason : 'voucher_revoked' );
		} finally {
			self::$revoking = false;
		}
		return is_wp_error( $cancelled ) ? $cancelled : true;
	}

	/**
	 * Ticket types that can be issued for vouchers (upcoming occurrences, active types), for admin selects.
	 *
	 * @param array $filter Optional event_id (int), ticket_name (string, name of the ticket type; with event_id
	 *                      also its later names after a rename) and
	 *                      quantity (int: leave out ticket types with fewer free places).
	 * @return array<int, array{ticket_type_id:int,event_id:int,occurrence_id:int,label:string,date_label:string,name:string,event:string,starts_at:string,currency:string,price_minor:int}>
	 */
	public function targets( array $filter = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '';
		$args = array( current_time( 'mysql', true ) );
		if ( ! empty( $filter['event_id'] ) ) {
			$where .= ' AND e.event_id=%d';
			$args[] = absint( $filter['event_id'] );
		}
		if ( '' !== (string) ( $filter['ticket_name'] ?? '' ) ) {
			// Vouchers sold for "Stehplatz" keep working after the ticket type was renamed (see remember_rename()).
			$names = self::names_for( absint( $filter['event_id'] ?? 0 ), sanitize_text_field( (string) $filter['ticket_name'] ) );
			$where .= ' AND tt.name IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')';
			$args = array_merge( $args, $names );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.name,tt.currency,tt.price_minor,e.event_id,e.name AS event_name,o.occurrence_id,o.starts_at,o.timezone FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.status='active' AND o.status<>'cancelled' AND o.starts_at>=%s{$where} ORDER BY o.starts_at,e.name,tt.ticket_type_id LIMIT 500", $args ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			try { $tz = new \DateTimeZone( $row->timezone ?: 'UTC' ); } catch ( \Exception $e ) { $tz = new \DateTimeZone( 'UTC' ); }
			$local = ( new \DateTimeImmutable( $row->starts_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$out[] = array(
				'ticket_type_id' => (int) $row->ticket_type_id,
				'event_id' => (int) $row->event_id,
				'occurrence_id' => (int) $row->occurrence_id,
				/* translators: 1: date, 2: ticket type */
				'date_label' => sprintf( __( '%1$s – %2$s', 'wp-pass-captain' ), $local->format( 'd.m.Y H:i' ), $row->name ),
				'name' => (string) $row->name,
				/* translators: 1: event, 2: date, 3: ticket type */
				'label' => sprintf( __( '%1$s – %2$s – %3$s', 'wp-pass-captain' ), $row->event_name, $local->format( 'd.m.Y H:i' ), $row->name ),
				'event' => (string) $row->event_name,
				'starts_at' => (string) $row->starts_at,
				'currency' => (string) $row->currency,
				'price_minor' => (int) $row->price_minor,
				// Free places right now (null = unlimited); a hint, the order itself re-checks under lock.
				'available' => Orders::availability( (int) $row->ticket_type_id ),
			);
		}
		$need = absint( $filter['quantity'] ?? 0 );
		if ( $need ) {
			$out = array_values( array_filter( $out, static function ( $t ) use ( $need ) { return null === $t['available'] || $t['available'] >= $need; } ) );
		}
		return $out;
	}

	/**
	 * The dates a customer may choose for a voucher valid for any date of an event (target event_id and optional
	 * ticket_name), soonest first: ticket_type_id, occurrence_id, date_label (date and ticket type), starts_at.
	 */
	public function options( array $target ) {
		if ( empty( $target['event_id'] ) ) { return array(); }
		return $this->targets( array( 'event_id' => $target['event_id'], 'ticket_name' => $target['ticket_name'] ?? '', 'quantity' => max( 1, absint( $target['quantity'] ?? 1 ) ) ) );
	}

	/**
	 * Attendee fields of a ticket type (as in the public checkout). Pass the answers per ticket to
	 * issue_tickets() as recipient attendees: [ [ 'ticket_type_id' => …, 'answers' => [ key => value ] ], … ].
	 *
	 * @return array<int, array{key:string,label:string,type:string,required:bool}>
	 */
	public function fields( $ticket_type_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT field_key,label,field_type,required FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", absint( $ticket_type_id ) ) );
		return array_map( static function ( $f ) {
			return array( 'key' => (string) $f->field_key, 'label' => (string) $f->label, 'type' => (string) $f->field_type, 'required' => (bool) (int) $f->required );
		}, (array) $rows );
	}

	/**
	 * Events with at least one upcoming date, for "customer chooses the date" vouchers in admin selects.
	 *
	 * @return array<int, array{event_id:int,label:string,dates:int,ticket_names:string[]}>
	 */
	public function events() {
		$events = array();
		foreach ( $this->targets() as $t ) {
			$id = $t['event_id'];
			if ( ! isset( $events[ $id ] ) ) {
				$events[ $id ] = array( 'event_id' => $id, 'label' => $t['event'], 'dates' => array(), 'ticket_names' => array() );
			}
			$events[ $id ]['dates'][ $t['occurrence_id'] ] = true;
			$events[ $id ]['ticket_names'][ $t['name'] ] = true;
		}
		return array_values( array_map( static function ( $e ) {
			$e['dates'] = count( $e['dates'] );
			$e['ticket_names'] = array_keys( $e['ticket_names'] );
			return $e;
		}, $events ) );
	}

	/** Confirms the free order (issues and mails the tickets) and answers with the order data. */
	private static function complete( $order, array $source, $reused ) {
		if ( in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
			return self::error( 'wpcap_ticket_exchange_revoked', __( 'Die Tickets dieser Einlösung wurden bereits widerrufen.', 'wp-pass-captain' ), 409 );
		}
		if ( 'paid' !== $order->payment_status ) {
			$reference = 'voucher:' . sanitize_text_field( (string) ( $source['redemption_key'] ?? ( $source['voucher_id'] ?? '' ) ) );
			$confirmed = Orders::confirm_external_payment( (int) $order->order_id, $reference );
			if ( is_wp_error( $confirmed ) ) {
				$data = (array) $confirmed->get_error_data();
				$retryable = ! empty( $data['retryable'] ) || 503 === (int) ( $data['status'] ?? 0 );
				return new \WP_Error( $confirmed->get_error_code(), $confirmed->get_error_message(), array( 'status' => $retryable ? 503 : 409, 'retryable' => $retryable ) );
			}
			Orders::set_provider_reference( (int) $order->order_id, mb_substr( $reference, 0, 191 ) );
			Audit::record(
				'voucher_tickets_issued',
				'order',
				(int) $order->order_id,
				array(
					'provider' => sanitize_key( (string) ( $source['provider'] ?? '' ) ),
					'voucher_id' => absint( $source['voucher_id'] ?? 0 ),
					'redemption_key' => sanitize_text_field( (string) ( $source['redemption_key'] ?? '' ) ),
					'code_hint' => mb_substr( sanitize_text_field( (string) ( $source['code_hint'] ?? '' ) ), -4 ),
				)
			);
			$order = Orders::get( (int) $order->order_id );
		} else {
			// Tickets of a paid order are issued idempotently (fills gaps after an interrupted issuance).
			Orders::issue_and_email( (int) $order->order_id );
		}
		return self::data( $order, $reused );
	}

	/** Voucher value redeemed for the tickets from the source data (minor units), null when not given. */
	private static function voucher_value( array $source ) {
		foreach ( array( 'amount_minor', 'value_minor', 'face_value_minor' ) as $key ) {
			if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) && (int) $source[ $key ] > 0 ) { return (int) $source[ $key ]; }
		}
		return null;
	}

	/**
	 * Stores the voucher snapshot of an exchange order (once, before it is confirmed). Multi-purpose: the
	 * whole order total is paid by the voucher, so the amount due (total_minor) becomes 0. Single-purpose:
	 * the voucher value is kept for settlements. Status `external`: Voucher Captain commits the
	 * redemption itself (Checkout::commit()/release()/refund() leave it alone).
	 */
	private static function record_voucher( $order_id, $purpose, $value, array $source, $type_id, $quantity ) {
		global $wpdb;
		$order = Orders::get( $order_id );
		if ( ! $order || ! empty( $order->voucher_snapshot ) || 'pending' !== $order->payment_status ) { return; }
		$snapshot = array(
			'code_hint' => mb_substr( sanitize_text_field( (string) ( $source['code_hint'] ?? '' ) ), -4 ),
			'purpose' => $purpose,
			'status' => 'external',
			'source' => array(
				'provider' => sanitize_key( (string) ( $source['provider'] ?? '' ) ),
				'voucher_id' => absint( $source['voucher_id'] ?? 0 ),
				'redemption_key' => sanitize_text_field( (string) ( $source['redemption_key'] ?? '' ) ),
			),
			'refunded_minor' => 0,
			'refunded_tickets' => 0,
		);
		$row = array();
		if ( Checkout::PURPOSE_MULTI === $purpose ) {
			$gross = (int) $order->total_minor;
			$snapshot += array( 'amount_minor' => $gross, 'amount_paid_by_voucher' => $gross, 'gross_minor' => $gross, 'gross_tax_minor' => (int) $order->tax_minor, 'amount_due_minor' => 0 );
			$row['total_minor'] = 0;
		} else {
			$list = (int) $wpdb->get_var( $wpdb->prepare( "SELECT price_minor FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", absint( $type_id ) ) ) * max( 1, (int) $quantity );
			$face = null !== $value ? (int) $value : $list;
			$snapshot += array( 'amount_minor' => $face, 'amount_paid_by_voucher' => 0, 'face_value_minor' => $face, 'face_value_source' => null !== $value ? 'voucher' : 'list_price' );
		}
		$row['voucher_snapshot'] = wp_json_encode( $snapshot );
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', $row, array( 'order_id' => absint( $order_id ), 'payment_status' => 'pending' ) );
	}

	/**
	 * Value of an exchange order for organizer settlements: the full gross amount for multi-purpose vouchers
	 * (also on the order lines), the stored voucher value for single-purpose vouchers (lines are 0).
	 */
	public static function settlement_value_minor( $order_id ) {
		$order = Orders::get( absint( $order_id ) );
		if ( ! $order || empty( $order->voucher_snapshot ) ) { return 0; }
		$snapshot = (array) json_decode( (string) $order->voucher_snapshot, true );
		if ( Checkout::PURPOSE_MULTI === Checkout::snapshot_purpose( $snapshot ) ) { return Checkout::gross_minor( $order ); }
		return (int) ( $snapshot['face_value_minor'] ?? 0 );
	}

	private static function data( $order, $reused ) {
		global $wpdb;
		$tickets = Orders::tickets_for_order( (int) $order->order_id );
		$valid = array_values( array_filter( (array) $tickets, static function ( $t ) { return 'valid' === $t->status || 'checked_in' === $t->status; } ) );
		$revoked = in_array( $order->status, array( 'cancelled', 'refunded' ), true );
		$first = $wpdb->get_row( $wpdb->prepare(
			"SELECT e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d ORDER BY t.ticket_id LIMIT 1",
			(int) $order->order_id
		) );
		return array(
			'order_id' => (int) $order->order_id,
			'order_public_id' => (string) $order->public_id,
			'ticket_ids' => array_map( static function ( $t ) { return (string) $t->public_id; }, $valid ),
			'status' => $revoked ? 'revoked' : ( 'paid' === $order->payment_status ? 'issued' : 'pending' ),
			'reused' => (bool) $reused,
			// Since 1.5: what the customer got, for "2 tickets for … on …, sent to …" in the voucher system.
			'event' => $first ? (string) $first->event_name : '',
			'ticket_name' => $first ? (string) $first->ticket_name : '',
			'starts_at' => $first ? Orders::format_start( $first->starts_at, $first->timezone ) : '',
			'email' => (string) $order->customer_email,
		);
	}

	/**
	 * Sends the tickets of an exchange again (since 1.5). Codes are stored hashed, so they are renewed (the
	 * old ones stop working), exactly like the resend in the order portal; at most 3 times per order and hour
	 * (filter `wpcap_portal_resend_limit`).
	 *
	 * @return array|\WP_Error email (where the tickets went).
	 */
	public function resend_tickets( $idempotency_key ) {
		$order = self::order_for_key( sanitize_text_field( (string) $idempotency_key ) );
		if ( ! $order ) {
			return self::error( 'wpcap_ticket_exchange_unknown', __( 'Zu diesem Gutschein wurden keine Tickets gefunden.', 'wp-pass-captain' ), 404 );
		}
		$limit = max( 1, (int) apply_filters( 'wpcap_portal_resend_limit', 3 ) );
		if ( ! \PassCaptain\RateLimit::allow( 'portal-resend|' . $order->public_id, $limit, HOUR_IN_SECONDS ) ) {
			/* translators: %d: allowed resends per hour */
			return self::error( 'wpcap_resend_rate_limited', sprintf( _n( 'Die Tickets können höchstens %d-mal pro Stunde erneut gesendet werden. Bitte versuche es später noch einmal.', 'Die Tickets können höchstens %d-mal pro Stunde erneut gesendet werden. Bitte versuche es später noch einmal.', $limit, 'wp-pass-captain' ), $limit ), 429 );
		}
		$result = Orders::resend_tickets( (int) $order->order_id );
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_code(), $result->get_error_message(), 409, 'wpcap_mail_failed' === $result->get_error_code() );
		}
		Audit::record( 'voucher_tickets_resent', 'order', (int) $order->order_id, array() );
		return array( 'email' => (string) $order->customer_email );
	}

	private static function order_for_key( $key ) {
		global $wpdb;
		if ( '' === $key ) { return null; }
		$hash = hash_hmac( 'sha256', $key, 'wpcap-channel-' . self::NAMESPACE_KEY );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE idempotency_key=%s", $hash ) );
	}

	private static function error( $code, $message, $status, $retryable = false ) {
		return new \WP_Error( $code, $message, array( 'status' => $status, 'retryable' => $retryable ) );
	}
}
