<?php
/**
 * Consequences of cancelling a single occurrence (wpcap_occurrence_cancelled):
 * open and expired unpaid orders are cancelled and their holds released, valid tickets are revoked, ticket holders
 * are informed, and – when `auto_refund_cancelled` is enabled – paid native orders are refunded
 * for the affected tickets. WooCommerce orders get an order note; the refund happens in WooCommerce.
 * Without automatic refund, paid tickets are revoked but stay refundable natively
 * (Orders::refundable_tickets(): revoked + not refunded + occurrence cancelled).
 * Whole-event cancellations (Orders::cancel_event) run this per occurrence inside an "event batch",
 * where the per-occurrence mail is replaced by one event cancellation mail.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OccurrenceCancellation {
	/** Nesting depth of an event cancellation in progress (suppresses per-occurrence mails). */
	private static $event_batch = 0;

	/** Summary of the last handle() run per occurrence id (request scope). */
	private static $summaries = array();

	public static function register() {
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'handle' ), 10, 1 );
	}

	/** Marks the start of a whole-event cancellation: holders get the event mail instead. */
	public static function begin_event_batch() {
		++self::$event_batch;
	}

	public static function end_event_batch() {
		self::$event_batch = max( 0, self::$event_batch - 1 );
	}

	/** Summary counters of the last cancellation handled for an occurrence in this request. */
	public static function last_summary( $occurrence_id ) {
		return self::$summaries[ absint( $occurrence_id ) ] ?? array();
	}

	/** @return array Summary counters. */
	public static function handle( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
		if ( ! $occ ) { return array(); }
		$summary = array( 'cancelled_orders' => 0, 'refunds' => 0, 'refund_errors' => 0, 'revoked' => 0, 'notified' => 0 );

		// Recipients first, while tickets are still valid.
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' GROUP BY email", $occurrence_id ) );

		// 1. Unpaid orders touching this occurrence: cancel (releases holds, campaigns, vouchers). Expired
		// orders too (A273): a payment that still arrives for them (hosted page left open, delayed method)
		// then meets a cancelled order and issues no tickets (webhook result `paid_for_closed_order`).
		$pending = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status IN ('pending','expired')", $occurrence_id ) );
		foreach ( $pending as $order_id ) {
			if ( true === Orders::cancel_order( (int) $order_id, 'occurrence_cancelled' ) ) {
				++$summary['cancelled_orders'];
			} // Else a retry job finishes it (Orders::cancel_order).
		}

		// 2. Paid orders: optional automatic refund of the affected tickets (before they are revoked).
		$refunded = self::refund_paid( $occurrence_id, 'occurrence-cancelled-' . $occurrence_id );
		$summary['refunds'] = $refunded['refunds'];
		$summary['refund_errors'] = $refunded['refund_errors'];

		// 3. Remaining valid tickets become invalid for admission.
		$revoked_ids = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", $occurrence_id ) ) );
		$summary['revoked'] = count( $revoked_ids );
		Orders::fire_revoked( $revoked_ids, 'occurrence_cancelled' );

		// 4. Inform holders.
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$when = wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz );
		$auto_refund = (bool) Settings::get( 'auto_refund_cancelled' );
		$vars = array(
			'event_name' => (string) $occ->event_name,
			'event_date' => $when,
			'event_location' => (string) $occ->location,
			'timezone' => (string) $occ->timezone,
			// Translated in the recipient's locale.
			'refund_note' => static function () use ( $auto_refund ) { return $auto_refund ? __( 'Der Ticketpreis wird automatisch erstattet.', 'pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'pass-captain' ); },
		);
		// A140: the cancelled .ics (same UID, higher SEQUENCE, STATUS:CANCELLED) updates imported entries.
		$files = ! self::$event_batch && $recipients ? Notifications::ics_file( $occurrence_id ) : array();
		try {
			foreach ( self::$event_batch ? array() : $recipients as $recipient ) {
				if ( is_email( $recipient->email ) && Mail\Mailer::send( 'occurrence_cancelled', $recipient->email, $vars, $files, (string) $recipient->locale ) ) { ++$summary['notified']; }
			}
		} finally {
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) { wp_delete_file( $file ); }
			}
		}
		Audit::record( 'occurrence_cancellation_processed', 'occurrence', $occurrence_id, $summary );
		self::$summaries[ $occurrence_id ] = $summary;
		return $summary;
	}

	/**
	 * Refunds the still open tickets of an occurrence in paid native orders when `auto_refund_cancelled` is
	 * enabled (WooCommerce orders get the `wpcap_occurrence_cancelled_woocommerce_order` signal instead).
	 * Must run before the tickets are revoked: revoked tickets can no longer be refunded.
	 *
	 * @return int[] ['refunds' => n, 'refund_errors' => n]
	 */
	public static function refund_paid( $occurrence_id, $key ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$counts = array( 'refunds' => 0, 'refund_errors' => 0 );
		// Ticket ids are read per order (GROUP_CONCAT would be truncated for large orders).
		$paid = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT ord.order_id,ord.payment_provider FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in')", $occurrence_id ) );
		foreach ( $paid as $row ) {
			$row->ticket_ids = implode( ',', $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND tt.occurrence_id=%d AND t.refunded=0 AND t.status IN ('valid','checked_in')", (int) $row->order_id, $occurrence_id ) ) );
		}
		foreach ( $paid as $row ) {
			if ( 'woocommerce' === $row->payment_provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, $occurrence_id );
				continue;
			}
			if ( ! Settings::get( 'auto_refund_cancelled' ) ) { continue; }
			$result = Refunds::request( (int) $row->order_id, array_map( 'absint', explode( ',', $row->ticket_ids ) ), __( 'Termin abgesagt', 'pass-captain' ), $key );
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) ) {
				++$counts['refund_errors'];
				Audit::record( 'occurrence_auto_refund_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : ( $result['status'] ?? '' ) ) );
			} else {
				++$counts['refunds'];
			}
		}
		return $counts;
	}
}
