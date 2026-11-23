<?php
/**
 * Refund requests after a postponed occurrence.
 *
 * When an occurrence is moved (wpcap_occurrence_rescheduled) its `rescheduled_at` is stored. Buyers of
 * orders placed before that moment can request a refund of their still valid tickets for that
 * occurrence in the order portal until the deadline (new start minus setting
 * `reschedule_refund_hours`). The request goes through Refunds::request() as a customer request: it
 * always waits for a staff approval (refund page), nothing is paid out automatically. The reschedule
 * mail and SMS carry a configurable note with the deadline ({refund_note}).
 *
 * Whether and when buyers are entitled to a refund after a postponement depends on the contract and
 * the applicable law; the plugin only offers the workflow. Setting `reschedule_refund_request`.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RescheduleRefunds {
	/** Whether the portal request is offered. */
	public static function enabled() {
		return (bool) Settings::get( 'reschedule_refund_request' );
	}

	/** Remembers that the occurrence was moved now (orders placed before may request a refund). */
	public static function mark( $occurrence_id ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_occurrences', array( 'rescheduled_at' => current_time( 'mysql', true ) ), array( 'occurrence_id' => absint( $occurrence_id ) ) );
	}

	/** Deadline (UTC timestamp) for a request: new start minus the configured hours. */
	public static function deadline( $starts_at_utc ) {
		$start = strtotime( (string) $starts_at_utc . ' UTC' );
		return $start ? $start - max( 0, (int) Settings::get( 'reschedule_refund_hours' ) ) * HOUR_IN_SECONDS : 0;
	}

	/** Deadline as local date/time of the occurrence ("d.m.Y H:i"). */
	public static function deadline_label( $starts_at_utc, $timezone ) {
		try {
			$tz = new \DateTimeZone( (string) $timezone ?: 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		return wp_date( 'd.m.Y H:i', self::deadline( $starts_at_utc ), $tz );
	}

	/**
	 * Note for the reschedule mail: the configured text with {refund_deadline}, or – when the request is
	 * switched off or the text is empty – the previous hint to contact the organizer.
	 */
	public static function mail_note( $starts_at_utc, $timezone ) {
		$text = trim( (string) Settings::get( 'reschedule_refund_note' ) );
		if ( ! self::enabled() || '' === $text ) {
			return __( 'Bei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.', 'pass-captain' );
		}
		return str_replace( '{refund_deadline}', self::deadline_label( $starts_at_utc, $timezone ), $text );
	}

	/** Short SMS note (leading space) or '' when the request is switched off. */
	public static function sms_note( $starts_at_utc, $timezone ) {
		if ( ! self::enabled() ) { return ''; }
		/* translators: %s: deadline */
		return ' ' . sprintf( __( 'Erstattung auf Antrag bis %s über deinen Bestelllink.', 'pass-captain' ), self::deadline_label( $starts_at_utc, $timezone ) );
	}

	/**
	 * Valid, not refunded tickets of the order for occurrences moved after the order was placed whose
	 * deadline has not passed yet.
	 *
	 * @return object[] Rows (ticket_id, occurrence_id, starts_at, timezone, event_name).
	 */
	public static function eligible_tickets( $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! self::enabled() || ! $order || 'paid' !== (string) $order->payment_status || 'woocommerce' === (string) $order->payment_provider ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,o.occurrence_id,o.starts_at,o.timezone,e.name AS event_name FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status='valid' AND t.refunded=0 AND o.status='scheduled' AND o.rescheduled_at IS NOT NULL AND o.rescheduled_at>=%s ORDER BY t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) $order->order_id, (string) $order->created_at ) );
		$now = time();
		return array_values( array_filter( $rows, static function ( $row ) use ( $now ) { return self::deadline( $row->starts_at ) > $now; } ) );
	}

	/**
	 * Buyer requests the refund of all eligible tickets (order portal). Idempotent per order and ticket
	 * set; always pending approval.
	 *
	 * @return array|\WP_Error Refund row.
	 */
	public static function request( $order ) {
		$tickets = self::eligible_tickets( $order );
		if ( ! $tickets ) {
			return new \WP_Error( 'wpcap_reschedule_refund_unavailable', __( 'Für diese Bestellung kann derzeit keine Erstattung beantragt werden (keine verschobenen Termine oder die Frist ist abgelaufen).', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$ids = array_map( static function ( $row ) { return (int) $row->ticket_id; }, $tickets );
		$result = Refunds::request( (int) $order->order_id, $ids, __( 'Erstattungsantrag nach Terminverschiebung (Bestellportal)', 'pass-captain' ), 'reschedule-' . implode( '-', $ids ), array( 'customer' => true, 'service_fee' => 'refund' ) ); // A425: caused by the organizer's postponement.
		if ( ! is_wp_error( $result ) ) {
			Audit::record( 'reschedule_refund_requested', 'order', (int) $order->order_id, array( 'refund_id' => (int) ( $result['refund_id'] ?? 0 ), 'tickets' => count( $ids ) ) );
		}
		return $result;
	}
}
