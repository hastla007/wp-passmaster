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
	 * @return int Number of notified recipients.
	 */
	public static function occurrence_rescheduled( $occurrence_id, $old_starts_at, $new_starts_at ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
		if ( ! $occ || $old_starts_at === $new_starts_at ) { return 0; }
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' GROUP BY email", absint( $occurrence_id ) ) );
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$old = wp_date( 'd.m.Y H:i', strtotime( $old_starts_at . ' UTC' ), $tz );
		$new = wp_date( 'd.m.Y H:i', strtotime( $new_starts_at . ' UTC' ), $tz );
		$vars = array( 'event_name' => (string) $occ->event_name, 'old_date' => $old, 'event_date' => $new, 'timezone' => (string) $occ->timezone, 'event_location' => (string) $occ->location );
		$sent = 0;
		foreach ( $recipients as $recipient ) {
			if ( is_email( $recipient->email ) && Mail\Mailer::send( 'occurrence_rescheduled', $recipient->email, $vars, array(), (string) $recipient->locale ) ) { ++$sent; }
		}
		Audit::record( 'occurrence_rescheduled', 'occurrence', absint( $occurrence_id ), array( 'old' => $old_starts_at, 'new' => $new_starts_at, 'notified' => $sent ) );
		return $sent;
	}
}
