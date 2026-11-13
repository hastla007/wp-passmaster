<?php
/**
 * Customer notifications for schedule and detail changes of an occurrence.
 *
 * - `wpcap_occurrence_rescheduled` (start moved, tickets issued): mail type `occurrence_rescheduled`.
 * - `wpcap_occurrence_changed` (A140; start, end, doors open, location or venue country changed):
 *   one mail per recipient and revision. A pure time shift keeps the `occurrence_rescheduled`
 *   text; any other change, alone or together with a new time, is sent as one combined
 *   `occurrence_changed` mail. Both carry the updated .ics (attachment and link) whose SEQUENCE is
 *   the new revision. When the save path fires both actions, the reschedule mail is deferred to
 *   the change handler, so time + location changed in one save produce exactly one mail.
 * - Per-event opt-out: events.notify_changes = 0 suppresses both change mails (not cancellations).
 */
namespace PassCaptain;

use PassCaptain\Calendar\Ics;
use PassCaptain\Calendar\Revisions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Notifications {
	/** Occurrences whose reschedule mail is handled by the following `wpcap_occurrence_changed`. */
	private static $deferred = array();

	public static function register() {
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), 10, 3 );
		add_action( 'wpcap_occurrence_changed', array( __CLASS__, 'occurrence_changed' ), 10, 3 );
	}

	/**
	 * Fires `wpcap_occurrence_rescheduled` (when $reschedule) and `wpcap_occurrence_changed` for a
	 * saved occurrence, with the reschedule mail folded into the change mail.
	 *
	 * @param int   $occurrence_id
	 * @param array $changes    field => [old, new] (any fields; only relevant ones are passed on).
	 * @param int   $revision   Revision after the change.
	 * @param bool  $reschedule Whether to fire `wpcap_occurrence_rescheduled` (start moved, tickets issued).
	 */
	public static function after_save( $occurrence_id, array $changes, $revision, $reschedule ) {
		$occurrence_id = absint( $occurrence_id );
		$notify = Revisions::notifiable( $changes );
		if ( $reschedule && isset( $changes['starts_at'] ) ) {
			if ( $notify ) { self::$deferred[ $occurrence_id ] = true; }
			try {
				do_action( 'wpcap_occurrence_rescheduled', $occurrence_id, $changes['starts_at'][0], $changes['starts_at'][1] );
			} finally {
				unset( self::$deferred[ $occurrence_id ] );
			}
		}
		$relevant = Revisions::relevant( $changes );
		if ( $relevant ) {
			/**
			 * After a calendar-relevant field of an occurrence changed (A140): start, end, doors open,
			 * location, venue country or status. Fired once per save, after `wpcap_occurrence_rescheduled`.
			 *
			 * @param int   $occurrence_id
			 * @param array $changes  field => [old, new] (UTC datetimes for times).
			 * @param int   $revision New revision (iCalendar SEQUENCE); 0 before the 3.9.9 upgrade.
			 */
			do_action( 'wpcap_occurrence_changed', $occurrence_id, $relevant, (int) $revision );
		}
	}

	/**
	 * Informs holders of valid tickets (attendee e-mail if set, otherwise buyer) about a new date.
	 * Each recipient gets one message per change; refunds are not implied. The audit entry (basis of
	 * the "Verschoben" badge) is always written; the mail is skipped when the change handler sends
	 * a combined mail or the event opted out.
	 *
	 * @return int Number of notified recipients.
	 */
	public static function occurrence_rescheduled( $occurrence_id, $old_starts_at, $new_starts_at ) {
		$occ = self::occurrence( $occurrence_id );
		if ( ! $occ || $old_starts_at === $new_starts_at ) { return 0; }
		$sent = 0;
		$deferred = isset( self::$deferred[ absint( $occurrence_id ) ] );
		if ( ! $deferred && Revisions::event_notifies( $occ->event_id ) ) {
			$sent = self::send_all( $occ, 'occurrence_rescheduled', self::rescheduled_vars( $occ, $old_starts_at, $new_starts_at ), true );
		}
		Audit::record( 'occurrence_rescheduled', 'occurrence', absint( $occurrence_id ), array( 'old' => $old_starts_at, 'new' => $new_starts_at, 'notified' => $sent ) + ( $deferred ? array( 'combined' => true ) : array() ) );
		return $sent;
	}

	/**
	 * Change mail for a revision (A140): idempotent per occurrence and revision, only for scheduled
	 * occurrences of events that did not opt out, only to holders of valid tickets.
	 *
	 * @return int Number of notified recipients.
	 */
	public static function occurrence_changed( $occurrence_id, $changes = array(), $revision = 0 ) {
		$notify = Revisions::notifiable( (array) $changes );
		if ( ! $notify ) { return 0; }
		$occ = self::occurrence( $occurrence_id );
		if ( ! $occ || 'scheduled' !== $occ->status || ! Revisions::event_notifies( $occ->event_id ) ) { return 0; }
		if ( ! self::recipients( $occ->occurrence_id ) ) { return 0; }
		if ( ! Revisions::claim_notification( $occ->occurrence_id, (int) $revision ) ) { return 0; }
		// A pure time shift keeps the established reschedule text; anything else is one combined mail.
		$time_only = isset( $notify['starts_at'] ) && ! array_diff_key( $notify, array_flip( array( 'starts_at', 'ends_at', 'doors_open_at' ) ) );
		if ( $time_only ) {
			$type = 'occurrence_rescheduled';
			$vars = self::rescheduled_vars( $occ, (string) $notify['starts_at'][0], (string) $notify['starts_at'][1] );
		} else {
			$type = 'occurrence_changed';
			$vars = self::changed_vars( $occ, $notify );
		}
		$sent = self::send_all( $occ, $type, $vars, true );
		Audit::record( 'occurrence_change_notified', 'occurrence', (int) $occ->occurrence_id, array( 'revision' => (int) $revision, 'mail_type' => $type, 'fields' => array_keys( $notify ), 'notified' => $sent ) );
		return $sent;
	}

	/** Occurrence with event name, or null. */
	private static function occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
	}

	/** Distinct recipients (attendee e-mail, otherwise buyer) of valid tickets with their locale. */
	private static function recipients( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' GROUP BY email", absint( $occurrence_id ) ) );
	}

	/** Local "d.m.Y H:i" of a UTC datetime in the occurrence's timezone ('' for empty). */
	private static function local( $occ, $utc ) {
		if ( null === $utc || '' === (string) $utc ) { return ''; }
		try {
			$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		return wp_date( 'd.m.Y H:i', strtotime( $utc . ' UTC' ), $tz );
	}

	private static function rescheduled_vars( $occ, $old_starts_at, $new_starts_at ) {
		return array(
			'event_name' => (string) $occ->event_name,
			'old_date' => self::local( $occ, $old_starts_at ),
			'event_date' => self::local( $occ, $new_starts_at ),
			'timezone' => (string) $occ->timezone,
			'event_location' => (string) $occ->location,
			'ics_url' => self::ics_link( $occ->occurrence_id ),
		);
	}

	private static function changed_vars( $occ, array $notify ) {
		return array(
			'event_name' => (string) $occ->event_name,
			'event_date' => self::local( $occ, $occ->starts_at ),
			'old_date' => self::local( $occ, isset( $notify['starts_at'] ) ? $notify['starts_at'][0] : $occ->starts_at ),
			'timezone' => (string) $occ->timezone,
			'event_location' => (string) $occ->location,
			'ics_url' => self::ics_link( $occ->occurrence_id ),
			// Built in the recipient's locale.
			'changes' => static function () use ( $occ, $notify ) { return self::change_lines( $occ, $notify ); },
		);
	}

	/** One line per changed detail: "Label: old → new". */
	public static function change_lines( $occ, array $notify ) {
		$labels = array(
			'starts_at' => __( 'Beginn', 'pass-captain' ),
			'ends_at' => __( 'Ende', 'pass-captain' ),
			'doors_open_at' => __( 'Einlass', 'pass-captain' ),
			'location' => __( 'Ort', 'pass-captain' ),
			'venue_country' => __( 'Veranstaltungsland', 'pass-captain' ),
		);
		$empty = __( 'nicht angegeben', 'pass-captain' );
		$lines = array();
		foreach ( $labels as $field => $label ) {
			if ( ! isset( $notify[ $field ] ) ) { continue; }
			list( $old, $new ) = $notify[ $field ];
			if ( in_array( $field, array( 'starts_at', 'ends_at', 'doors_open_at' ), true ) ) {
				$old = self::local( $occ, $old );
				$new = self::local( $occ, $new );
			}
			$old = '' === (string) $old ? $empty : (string) $old;
			$new = '' === (string) $new ? $empty : (string) $new;
			/* translators: 1: changed detail (e.g. "Ort"), 2: previous value, 3: new value */
			$lines[] = sprintf( __( '%1$s: %2$s → %3$s', 'pass-captain' ), $label, $old, $new );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Sends one mail type to all recipients of valid tickets, optionally with the current .ics of
	 * the occurrence attached (one temporary file, removed afterwards).
	 */
	private static function send_all( $occ, $type, array $vars, $attach ) {
		$recipients = self::recipients( $occ->occurrence_id );
		if ( ! $recipients ) { return 0; }
		$files = $attach ? self::ics_file( (int) $occ->occurrence_id ) : array();
		$sent = 0;
		try {
			foreach ( $recipients as $recipient ) {
				if ( is_email( $recipient->email ) && Mail\Mailer::send( $type, $recipient->email, $vars, $files, (string) $recipient->locale ) ) { ++$sent; }
			}
		} finally {
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) { wp_delete_file( $file ); }
			}
		}
		return $sent;
	}

	/**
	 * Temporary .ics file with the current state of an occurrence (empty list when the event is not
	 * public). File names follow the ticket mail: termin-{id}-{random}.ics.
	 *
	 * @return string[]
	 */
	public static function ics_file( $occurrence_id ) {
		$settings = (array) Settings::get( 'calendar' );
		if ( ! self::calendar_enabled() || empty( $settings['attach_ics'] ) ) { return array(); }
		$ics = Ics::occurrence_calendar( $occurrence_id );
		if ( null === $ics ) { return array(); }
		$file = trailingslashit( get_temp_dir() ) . 'termin-' . absint( $occurrence_id ) . '-' . bin2hex( random_bytes( 8 ) ) . '.ics';
		return false !== file_put_contents( $file, $ics ) ? array( $file ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** Download link of the current .ics (placeholder {ics_url}); '' without the calendar module. */
	private static function ics_link( $occurrence_id ) {
		return self::calendar_enabled() ? Mail\Mailer::link( Ics::occurrence_url( $occurrence_id ) ) : '';
	}

	/** Whether the calendar module serves the public .ics downloads. */
	private static function calendar_enabled() {
		return Modules::enabled( Modules\Calendar::class );
	}
}
