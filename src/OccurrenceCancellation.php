<?php
/**
 * Consequences of cancelling a single occurrence (wpcap_occurrence_cancelled):
 * open orders are cancelled and their holds released, valid tickets are revoked, ticket holders
 * are informed, and – when `auto_refund_cancelled` is enabled – paid native orders are refunded
 * for the affected tickets. WooCommerce orders get an order note; the refund happens in WooCommerce.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OccurrenceCancellation {
	public static function register() {
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'handle' ), 10, 1 );
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
		$emails = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", $occurrence_id ) );

		// 1. Unpaid orders touching this occurrence: cancel (releases holds, campaigns, vouchers).
		$pending = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='pending'", $occurrence_id ) );
		foreach ( $pending as $order_id ) {
			Orders::cancel_order( (int) $order_id, 'occurrence_cancelled' );
			++$summary['cancelled_orders'];
		}

		// 2. Paid orders: optional automatic refund of the affected tickets.
		$paid = $wpdb->get_results( $wpdb->prepare( "SELECT ord.order_id,ord.payment_provider,GROUP_CONCAT(t.ticket_id) AS ticket_ids FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in') GROUP BY ord.order_id,ord.payment_provider", $occurrence_id ) );
		foreach ( $paid as $row ) {
			if ( 'woocommerce' === $row->payment_provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, $occurrence_id );
				continue;
			}
			if ( ! Settings::get( 'auto_refund_cancelled' ) ) { continue; }
			$result = Refunds::request( (int) $row->order_id, array_map( 'absint', explode( ',', $row->ticket_ids ) ), __( 'Termin abgesagt', 'wp-pass-captain' ), 'occurrence-cancelled-' . $occurrence_id );
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) ) {
				++$summary['refund_errors'];
				Audit::record( 'occurrence_auto_refund_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : ( $result['status'] ?? '' ) ) );
			} else {
				++$summary['refunds'];
			}
		}

		// 3. Remaining valid tickets become invalid for admission.
		$summary['revoked'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id SET t.status='revoked' WHERE tt.occurrence_id=%d AND t.status='valid'", $occurrence_id ) );

		// 4. Inform holders.
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$when = wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz );
		$refund_note = Settings::get( 'auto_refund_cancelled' ) ? __( 'Der Ticketpreis wird automatisch erstattet.', 'wp-pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'wp-pass-captain' );
		/* translators: 1: event, 2: date and time, 3: refund note */
		$body = sprintf( __( "Der Termin „%1\$s“ am %2\$s wurde abgesagt. Dein Ticket ist dafür nicht mehr gültig.\n\n%3\$s", 'wp-pass-captain' ), $occ->event_name, $when, $refund_note );
		foreach ( $emails as $email ) {
			if ( is_email( $email ) && wp_mail( $email, sprintf( /* translators: %s: event */ __( 'Absage: %s', 'wp-pass-captain' ), $occ->event_name ), $body ) ) { ++$summary['notified']; }
		}
		Audit::record( 'occurrence_cancellation_processed', 'occurrence', $occurrence_id, $summary );
		return $summary;
	}
}
