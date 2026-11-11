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
		$emails = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", absint( $occurrence_id ) ) );
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$old = wp_date( 'd.m.Y H:i', strtotime( $old_starts_at . ' UTC' ), $tz );
		$new = wp_date( 'd.m.Y H:i', strtotime( $new_starts_at . ' UTC' ), $tz );
		/* translators: 1: event, 2: old date, 3: new date, 4: timezone, 5: location */
		$body = sprintf( __( "Der Termin für „%1\$s“ wurde verschoben.\n\nBisher: %2\$s\nNeu: %3\$s (%4\$s)\nOrt: %5\$s\n\nDein Ticket bleibt gültig. Bei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.", 'wp-pass-captain' ), $occ->event_name, $old, $new, $occ->timezone, $occ->location );
		$sent = 0;
		foreach ( $emails as $email ) {
			if ( is_email( $email ) && wp_mail( $email, sprintf( /* translators: %s: event */ __( 'Terminänderung: %s', 'wp-pass-captain' ), $occ->event_name ), $body ) ) { ++$sent; }
		}
		Audit::record( 'occurrence_rescheduled', 'occurrence', absint( $occurrence_id ), array( 'old' => $old_starts_at, 'new' => $new_starts_at, 'notified' => $sent ) );
		return $sent;
	}
}
