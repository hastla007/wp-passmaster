<?php
/** Customer notifications for schedule changes. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Notifications {
	public static function register() {
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), 10, 3 );
	}

	/**
	 * Informs holders of valid tickets (attendee e-mail if set, otherwise buyer) about a new date.
	 * Each recipient gets one message per change; refunds are not implied.
	 *
	 * The mails go through the message queue (one row per recipient, dedupe key
	 * resched|occurrence|new start|e-mail): a repeated hook or double submit sends nothing twice, each
	 * recipient is retried on its own, and an admin request with thousands of holders only queues them.
	 * The first ones are sent right away (Communications::$inline_seconds), the rest by the worker.
	 * Progress: Communications::progress( 'resched|<occurrence id>|<new start>' ).
	 *
	 * @return int Number of recipients queued (or already queued for this change).
	 */
	public static function occurrence_rescheduled( $occurrence_id, $old_starts_at, $new_starts_at ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $occurrence_id ) ) );
		if ( ! $occ || $old_starts_at === $new_starts_at ) { return 0; }
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' GROUP BY email", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', absint( $occurrence_id ) ) );
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$old = wp_date( 'd.m.Y H:i', strtotime( $old_starts_at . ' UTC' ), $tz );
		$new = wp_date( 'd.m.Y H:i', strtotime( $new_starts_at . ' UTC' ), $tz );
		$vars = array( 'event_name' => (string) $occ->event_name, 'old_date' => $old, 'event_date' => $new, 'timezone' => (string) $occ->timezone, 'event_location' => (string) $occ->location );
		$ref = 'resched|' . absint( $occurrence_id ) . '|' . $new_starts_at;
		$keys = array();
		foreach ( $recipients as $recipient ) {
			if ( ! is_email( $recipient->email ) ) { continue; }
			$key = $ref . '|' . strtolower( (string) $recipient->email );
			if ( true === Communications::enqueue_mail( $key, (string) $recipient->email, 'occurrence_rescheduled', $vars, (string) $recipient->locale, $ref ) ) { $keys[] = $key; }
		}
		$inline = Communications::run_now( $keys, microtime( true ) + max( 0, (int) Communications::$inline_seconds ) );
		if ( $inline < count( $keys ) ) { Jobs\TimeBudget::continue_later( 'wpcap_process_messages' ); }
		Audit::record( 'occurrence_rescheduled', 'occurrence', absint( $occurrence_id ), array( 'old' => $old_starts_at, 'new' => $new_starts_at, 'notified' => count( $keys ), 'sent_inline' => $inline ) );
		return count( $keys );
	}
}
