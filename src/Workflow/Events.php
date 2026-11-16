<?php
/**
 * Post-commit domain events of the workflow contract (A674, PASS-WF-02): maps the internal hooks to
 * outbox events (Outbox::record()) with stable ids, timestamps and schema version – never ticket codes or
 * attendee/buyer personal data. Registered by the core (not by the Webhooks module), so the outbox
 * is filled whether or not webhooks are subscribed or the Webhooks module is switched off.
 *
 * Also builds the refund description shared with the webhook payloads (A675): a refund is described per
 * order, per order line and per ticket (kind tickets | amount | full).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Workflow;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Events {
	/** Hooks run after the core and module listeners of the same hook (priority 60). */
	const PRIORITY = 60;

	public static function register() {
		$p = self::PRIORITY;
		add_action( 'wpcap_order_paid', array( __CLASS__, 'order_paid' ), $p, 1 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'order_cancelled' ), $p, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund_completed' ), $p, 2 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'order_refunded' ), $p, 2 );
		add_action( 'wpcap_refund_reversed', array( __CLASS__, 'refund_reversed' ), $p, 2 );
		add_action( 'wpcap_tickets_issued', array( __CLASS__, 'tickets_issued' ), $p, 2 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'ticket_revoked' ), $p, 2 );
		add_action( 'wpcap_ticket_transferred', array( __CLASS__, 'ticket_transferred' ), $p, 2 );
		add_action( 'wpcap_ticket_exchanged', array( __CLASS__, 'ticket_exchanged' ), $p, 4 );
		add_action( 'wpcap_ticket_checked_in', array( __CLASS__, 'ticket_checked_in' ), $p, 2 );
		add_action( 'wpcap_ticket_checked_out', array( __CLASS__, 'ticket_checked_out' ), $p, 2 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), $p, 3 );
		add_action( 'wpcap_occurrence_changed', array( __CLASS__, 'occurrence_changed' ), $p, 3 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_cancelled' ), $p, 2 );
		add_action( 'wpcap_event_cancelled', array( __CLASS__, 'event_cancelled' ), $p, 2 );
	}

	/* ------------------------------------------------------------------ Objects (no PII) */

	private static function iso( $utc ) {
		return null === $utc || '' === (string) $utc ? null : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $utc . ' UTC' ) );
	}

	/** Order lines: line id, ticket type, occurrence, event, quantity, refunded quantity. */
	public static function lines( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT oi.item_id,oi.ticket_type_id,oi.quantity,oi.refunded_quantity,tt.occurrence_id,o.event_id FROM {$p}wpcap_order_items oi LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d ORDER BY oi.item_id", absint( $order_id ) ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array( 'line_id' => (int) $row->item_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'occurrence_id' => (int) $row->occurrence_id, 'event_id' => (int) $row->event_id, 'quantity' => (int) $row->quantity, 'refunded_quantity' => (int) $row->refunded_quantity );
		}
		return $out;
	}

	/** Order object of the contract; null when the order does not exist. */
	public static function order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status,currency,total_minor,refunded_minor,payment_provider FROM {$p}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $o ) { return null; }
		$lines = self::lines( (int) $o->order_id );
		$issued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d", (int) $o->order_id ) );
		return array(
			'order_id' => (string) $o->public_id,
			'status' => (string) $o->status,
			'payment_status' => (string) $o->payment_status,
			'currency' => (string) $o->currency,
			'total_minor' => (int) $o->total_minor,
			'refunded_minor' => (int) $o->refunded_minor,
			'payment_provider' => (string) $o->payment_provider,
			'ticket_count' => max( $issued, (int) array_sum( array_column( $lines, 'quantity' ) ) ),
			'event_ids' => array_values( array_unique( array_filter( array_column( $lines, 'event_id' ) ) ) ),
			'lines' => $lines,
		);
	}

	/** Ticket object of the contract (public ticket id – not the ticket code); null when unknown. */
	public static function ticket( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$t = $wpdb->get_row( $wpdb->prepare( "SELECT t.order_id AS order_pk,t.public_id,t.status,t.refunded,t.ticket_type_id,ord.public_id AS order_public_id,tt.occurrence_id,o.event_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $t ) { return null; }
		return array(
			'ticket_id' => (string) $t->public_id,
			'order_id' => (string) $t->order_public_id,
			'line_id' => self::line_of( (int) $t->order_pk, (int) $t->ticket_type_id ),
			'ticket_type_id' => (int) $t->ticket_type_id,
			'occurrence_id' => (int) $t->occurrence_id,
			'event_id' => (int) $t->event_id,
			'status' => (string) $t->status,
			'refunded' => (bool) $t->refunded,
		);
	}

	/** Order line of a ticket (lines are per ticket type; 0 when the line is gone). */
	private static function line_of( $order_id, $ticket_type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT item_id FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", $order_id, $ticket_type_id ) );
	}

	public static function occurrence( $occurrence_id ) {
		global $wpdb;
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		if ( ! $o ) { return null; }
		return array( 'occurrence_id' => (int) $o->occurrence_id, 'event_id' => (int) $o->event_id, 'starts_at' => (string) self::iso( $o->starts_at ), 'ends_at' => self::iso( $o->ends_at ?? null ), 'timezone' => (string) $o->timezone, 'status' => (string) $o->status, 'revision' => (int) ( $o->revision ?? 0 ) );
	}

	public static function event( $event_id ) {
		global $wpdb;
		$e = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $event_id ) ) );
		if ( ! $e ) { return null; }
		$occurrences = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY occurrence_id", (int) $e->event_id ) ) );
		return array( 'event_id' => (int) $e->event_id, 'status' => (string) $e->status, 'occurrence_ids' => $occurrences );
	}

	/**
	 * Refund per order, line and ticket (A675). kind: tickets (these tickets were refunded), amount (an
	 * amount without tickets) or full (the whole order outside the native refund flow).
	 *
	 * @return array|null refund_id, order_id, kind, amount_minor, currency, source, status, ticket_ids (public), lines.
	 */
	public static function refund( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.currency,r.ticket_ids,r.status,r.source,o.public_id FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.refund_id=%d", absint( $refund_id ) ) );
		if ( ! $r ) { return null; }
		$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) $r->ticket_ids, true ) ) ) );
		list( $public, $lines ) = self::ticket_lines( (int) $r->order_id, $ids );
		return array(
			'refund_id' => (int) $r->refund_id,
			'order_id' => (string) $r->public_id,
			'kind' => $ids ? 'tickets' : 'amount',
			'amount_minor' => (int) $r->amount_minor,
			'currency' => (string) $r->currency,
			'source' => sanitize_key( (string) $r->source ),
			'status' => sanitize_key( (string) $r->status ),
			'ticket_ids' => $public,
			'lines' => $lines,
		);
	}

	/** Full refund outside the native flow (Orders::mark_refunded()): every line and ticket of the order. */
	public static function full_refund( $order_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,currency FROM {$p}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $o ) { return null; }
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $o->order_id ) ) );
		list( $public ) = self::ticket_lines( (int) $o->order_id, $ids );
		$lines = array();
		foreach ( self::lines( (int) $o->order_id ) as $line ) { $lines[] = array( 'line_id' => $line['line_id'], 'ticket_type_id' => $line['ticket_type_id'], 'quantity' => $line['quantity'] ); }
		return array( 'refund_id' => null, 'order_id' => (string) $o->public_id, 'kind' => 'full', 'amount_minor' => null, 'currency' => (string) $o->currency, 'source' => sanitize_key( (string) $reason ), 'status' => 'completed', 'ticket_ids' => $public, 'lines' => $lines );
	}

	/** Public ids of tickets and their grouping per order line: [public ids, lines]. */
	private static function ticket_lines( $order_id, array $ticket_ids ) {
		global $wpdb;
		if ( ! $ticket_ids ) { return array( array(), array() ); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,public_id,ticket_type_id FROM ' . $wpdb->prefix . 'wpcap_tickets WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_map( 'absint', $ticket_ids ) ) . ') ORDER BY ticket_id', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		$public = array();
		$per_type = array();
		foreach ( (array) $rows as $row ) {
			$public[] = (string) $row->public_id;
			$per_type[ (int) $row->ticket_type_id ] = ( $per_type[ (int) $row->ticket_type_id ] ?? 0 ) + 1;
		}
		$lines = array();
		foreach ( $per_type as $type_id => $quantity ) { $lines[] = array( 'line_id' => self::line_of( $order_id, $type_id ), 'ticket_type_id' => $type_id, 'quantity' => $quantity ); }
		return array( $public, $lines );
	}

	/* ------------------------------------------------------------------ Hook handlers */

	public static function order_paid( $order_id ) {
		$o = self::order( $order_id );
		return $o ? Outbox::record( 'order.paid', 'order|' . (int) $order_id, $o['order_id'], $o ) : false;
	}

	public static function order_cancelled( $order_id ) {
		$o = self::order( $order_id );
		return $o ? Outbox::record( 'order.cancelled', 'order|' . (int) $order_id, $o['order_id'], $o ) : false;
	}

	public static function refund_completed( $refund_id, $order_id = 0 ) {
		$o = self::order( $order_id );
		$r = self::refund( $refund_id );
		return $o && $r ? Outbox::record( 'order.refunded', 'refund|' . (int) $refund_id, $o['order_id'], $o + array( 'refund' => $r ) ) : false;
	}

	/** Full refunds outside the native flow; native refunds are recorded per refund above. */
	public static function order_refunded( $order_id, $reason = '' ) {
		if ( 0 === strpos( (string) $reason, 'native_refund_' ) ) { return false; }
		$o = self::order( $order_id );
		$r = self::full_refund( $order_id, $reason );
		return $o && $r ? Outbox::record( 'order.refunded', 'order-full|' . (int) $order_id, $o['order_id'], $o + array( 'refund' => $r ) ) : false;
	}

	/** Storno of a refund: the provider failed it afterwards (A348); tickets stay revoked. */
	public static function refund_reversed( $refund_id, $order_id = 0 ) {
		$o = self::order( $order_id );
		$r = self::refund( $refund_id );
		return $o && $r ? Outbox::record( 'refund.reversed', 'refund-reversed|' . (int) $refund_id, $o['order_id'], $o + array( 'refund' => $r ) ) : false;
	}

	/** One ticket.issued per ticket (stable public ticket id); repeated issuance hooks record nothing new. */
	public static function tickets_issued( $order_id, $count = 0 ) {
		global $wpdb;
		$recorded = 0;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", absint( $order_id ) ) ) as $ticket_id ) {
			$t = self::ticket( $ticket_id );
			if ( $t && Outbox::record( 'ticket.issued', 'ticket|' . (int) $ticket_id, $t['ticket_id'], $t ) ) { ++$recorded; }
		}
		return $recorded;
	}

	public static function ticket_revoked( $ticket_id, $reason = '' ) {
		$t = self::ticket( $ticket_id );
		return $t ? Outbox::record( 'ticket.revoked', 'revoked|' . (int) $ticket_id, $t['ticket_id'], $t + array( 'reason' => sanitize_key( (string) $reason ) ) ) : false;
	}

	public static function ticket_transferred( $ticket_id, $context = array() ) {
		$t = self::ticket( $ticket_id );
		$context = is_array( $context ) ? $context : array();
		$changes = (int) ( $context['holder_changes'] ?? 0 );
		$source = sanitize_key( (string) ( $context['source'] ?? 'portal' ) );
		return $t ? Outbox::record( 'ticket.transferred', 'transferred|' . (int) $ticket_id . '|' . $source . '|' . $changes, $t['ticket_id'], $t + array( 'source' => $source, 'holder_changes' => $changes ) ) : false;
	}

	public static function ticket_exchanged( $old_ticket_id, $new_ticket_id, $exchange_id = 0, $exchange = array() ) {
		global $wpdb;
		$t = self::ticket( $new_ticket_id );
		if ( ! $t ) { return false; }
		$exchange = (array) $exchange;
		$previous = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $old_ticket_id ) ) );
		return Outbox::record( 'ticket.exchanged', 'exchanged|' . absint( $exchange_id ), $t['ticket_id'], $t + array(
			'previous_ticket_id' => $previous,
			'exchange' => array(
				'exchange_id' => absint( $exchange_id ),
				'from_ticket_type_id' => (int) ( $exchange['from_type_id'] ?? 0 ),
				'to_ticket_type_id' => (int) ( $exchange['to_type_id'] ?? 0 ),
				'channel' => sanitize_key( (string) ( $exchange['channel'] ?? '' ) ),
				'currency' => (string) ( $exchange['currency'] ?? '' ),
				'difference_minor' => (int) ( $exchange['difference_minor'] ?? 0 ),
				'fee_minor' => (int) ( $exchange['fee_minor'] ?? 0 ),
				'refund_minor' => (int) ( $exchange['refund_minor'] ?? 0 ),
				'due_minor' => (int) ( $exchange['due_minor'] ?? 0 ),
			),
		) );
	}

	/**
	 * ticket.checked_in per admission (stable per check-in row) and attendance.confirmed once per ticket
	 * at its first admission (the attendance the contract confirms: a real check-in).
	 */
	public static function ticket_checked_in( $ticket_id, $context = array() ) {
		$t = self::ticket( $ticket_id );
		if ( ! $t ) { return false; }
		$context = is_array( $context ) ? $context : array();
		$checkin_id = absint( $context['checkin_id'] ?? 0 );
		$source = sanitize_key( (string) ( $context['source'] ?? 'web' ) );
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$recorded = Outbox::record( 'ticket.checked_in', 'checkin|' . ( $checkin_id ? $checkin_id : (int) $ticket_id . '|' . wp_generate_uuid4() ), $t['ticket_id'], $t + array( 'checked_in_at' => $now, 'source' => $source, 'area_id' => absint( $context['area_id'] ?? 0 ), 'checkin_id' => $checkin_id ) );
		Outbox::record( 'attendance.confirmed', 'attendance|' . (int) $ticket_id, $t['ticket_id'], $t + array( 'confirmed_at' => $now, 'method' => 'check_in', 'source' => $source ) );
		return $recorded;
	}

	public static function ticket_checked_out( $ticket_id, $context = array() ) {
		$t = self::ticket( $ticket_id );
		if ( ! $t ) { return false; }
		$context = is_array( $context ) ? $context : array();
		$checkin_id = absint( $context['checkin_id'] ?? 0 );
		return Outbox::record( 'ticket.checked_out', 'checkout|' . ( $checkin_id ? $checkin_id : (int) $ticket_id . '|' . wp_generate_uuid4() ), $t['ticket_id'], $t + array( 'checked_out_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'source' => sanitize_key( (string) ( $context['source'] ?? 'web' ) ), 'area_id' => absint( $context['area_id'] ?? 0 ), 'checkin_id' => $checkin_id ) );
	}

	public static function occurrence_rescheduled( $occurrence_id, $old = '', $new = '' ) {
		$o = self::occurrence( $occurrence_id );
		return $o ? Outbox::record( 'occurrence.rescheduled', 'rescheduled|' . (int) $occurrence_id . '|' . $old . '|' . $new, (string) $o['occurrence_id'], $o + array( 'previous_starts_at' => self::iso( $old ) ) ) : false;
	}

	/** occurrence.changed: names of the changed fields only (values may be addresses of venues). */
	public static function occurrence_changed( $occurrence_id, $changes = array(), $revision = 0 ) {
		$o = self::occurrence( $occurrence_id );
		$fields = array_values( array_map( 'sanitize_key', array_keys( (array) $changes ) ) );
		$key = (int) $revision > 0 ? (string) (int) $revision : md5( (string) wp_json_encode( $changes ) );
		return $o ? Outbox::record( 'occurrence.changed', 'changed|' . (int) $occurrence_id . '|' . $key, (string) $o['occurrence_id'], $o + array( 'changed_fields' => $fields ) ) : false;
	}

	public static function occurrence_cancelled( $occurrence_id, $issued = 0 ) {
		$o = self::occurrence( $occurrence_id );
		return $o ? Outbox::record( 'occurrence.cancelled', 'cancelled|' . (int) $occurrence_id, (string) $o['occurrence_id'], $o + array( 'issued_tickets' => (int) $issued ) ) : false;
	}

	public static function event_cancelled( $event_id, $context = array() ) {
		$e = self::event( $event_id );
		return $e ? Outbox::record( 'event.cancelled', 'event-cancelled|' . (int) $event_id, (string) $e['event_id'], $e ) : false;
	}
}
