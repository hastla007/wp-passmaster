<?php
/**
 * Reminder messages X hours before an occurrence starts (several offsets possible).
 *
 * Scheduling runs on the wpcap_process_messages cron right before the core message queue is
 * processed. For each upcoming occurrence only the current stage (the smallest offset whose time has
 * come) is queued, so a late buyer gets one reminder rather than all of them. E-mails go through
 * the core queue (Communications::enqueue) with the dedupe key
 * reminder|{occurrence}|{starts_at}|{offset}|{recipient}: one message per ticket holder,
 * occurrence, start time and offset. Revoked/refunded tickets, unpaid orders and cancelled
 * occurrences are skipped, and queued reminders that are no longer valid are cancelled before sending.
 * Optional SMS reminders go to consenting buyers (one per order).
 */
namespace PassCaptain\Modules;

use PassCaptain\Admin\Forms;
use PassCaptain\Communications;
use PassCaptain\Reminders\Placeholders;
use PassCaptain\Settings;
use PassCaptain\Sms\Service as SmsService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reminders {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-reminders';
	const TEMPLATE = 'wpcap_reminder';

	public static function register() {
		add_action( 'wpcap_process_messages', array( __CLASS__, 'schedule_due' ), 1, 0 );
		add_action( 'wpcap_process_messages', array( __CLASS__, 'purge_stale' ), 5, 0 );
		add_filter( 'wpcap_email_template', array( __CLASS__, 'render_template' ), 10, 3 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'purge_stale' ), 20, 0 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'purge_stale' ), 20, 0 );
	}

	public static function settings_defaults() {
		return array(
			'reminders' => array(
				'enabled' => 0,
				'offsets' => array( 24 ),
				'subject' => __( 'Erinnerung: {event} am {date}', 'wp-pass-captain' ),
				'body' => __( "Hallo,\n\nin {hours} Stunden geht es los: „{event}“ am {date} um {time} Uhr ({timezone}).\nOrt: {location}\nTickets: {tickets}\n\nTermin in den Kalender übernehmen: {ics_url}\n\nBitte bring dein Ticket (ausgedruckt oder auf dem Smartphone) mit.\n\n{site}", 'wp-pass-captain' ),
				'sms' => 0,
				'sms_body' => __( 'Erinnerung: {event} am {date} um {time}, {location}.', 'wp-pass-captain' ),
			),
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'Erinnerungen', 'wp-pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_reminders_save' => array( __CLASS__, 'handle_save' ) );
	}

	public static function config() {
		$c = array_merge( self::settings_defaults()['reminders'], (array) Settings::get( 'reminders' ) );
		$offsets = array_values( array_unique( array_filter( array_map( 'absint', (array) $c['offsets'] ), static function ( $h ) { return $h >= 1 && $h <= 720; } ) ) );
		sort( $offsets );
		$c['offsets'] = $offsets;
		return $c;
	}

	/**
	 * Current stage of an occurrence: smallest offset whose send time has passed, or 0.
	 *
	 * @param int   $starts  Start timestamp (UTC).
	 * @param int[] $offsets Ascending offsets in hours.
	 */
	public static function stage( $starts, array $offsets, $now = null ) {
		$now = $now ?? time();
		if ( $starts <= $now ) { return 0; }
		foreach ( $offsets as $hours ) {
			if ( $starts - $hours * HOUR_IN_SECONDS <= $now ) { return (int) $hours; }
		}
		return 0;
	}

	/** Queues due reminders. Returns the number of newly queued e-mails and SMS. */
	public static function schedule_due( $now = null ) {
		global $wpdb;
		$c = self::config();
		if ( empty( $c['enabled'] ) || ! $c['offsets'] ) { return 0; }
		$p = $wpdb->prefix;
		$now = $now ?? time();
		$occurrences = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.timezone,o.location,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.status='scheduled' AND e.status='published' AND o.starts_at>%s AND o.starts_at<=%s ORDER BY o.starts_at LIMIT 200", gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + max( $c['offsets'] ) * HOUR_IN_SECONDS ) ) );
		$queued = 0;
		foreach ( $occurrences as $occ ) {
			$stage = self::stage( (int) strtotime( $occ->starts_at . ' UTC' ), $c['offsets'], $now );
			if ( ! $stage ) { continue; }
			$holders = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(COALESCE(NULLIF(t.attendee_email,''),ord.customer_email)) AS email,COUNT(*) AS tickets FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' GROUP BY email", (int) $occ->occurrence_id ) );
			foreach ( $holders as $holder ) {
				if ( ! is_email( $holder->email ) ) { continue; }
				$key = 'reminder|' . (int) $occ->occurrence_id . '|' . $occ->starts_at . '|' . $stage . '|' . $holder->email;
				if ( self::already_queued( $key ) ) { continue; }
				$result = Communications::enqueue( $key, $holder->email, self::TEMPLATE, array( 'occurrence_id' => (int) $occ->occurrence_id, 'starts_at' => $occ->starts_at, 'hours' => $stage, 'tickets' => (int) $holder->tickets ) );
				if ( true === $result ) { ++$queued; }
			}
			if ( ! empty( $c['sms'] ) && '' !== trim( (string) $c['sms_body'] ) && class_exists( SmsService::class ) ) {
				$orders = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT t.order_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid'", (int) $occ->occurrence_id ) );
				$body = Placeholders::render( $c['sms_body'], array_merge( Placeholders::occurrence_vars( $occ ), array( 'hours' => $stage ) ) );
				foreach ( $orders as $order_id ) {
					if ( true === SmsService::enqueue( 'sms|reminder|' . (int) $occ->occurrence_id . '|' . $occ->starts_at . '|' . $stage . '|' . (int) $order_id, (int) $order_id, (int) $occ->occurrence_id, 'reminder', $body ) ) { ++$queued; }
				}
			}
		}
		return $queued;
	}

	private static function already_queued( $key ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM {$wpdb->prefix}wpcap_message_queue WHERE dedupe_key=%s", hash( 'sha256', $key ) ) );
	}

	/** Whether a queued reminder is still valid (occurrence scheduled at the same time, holder has valid tickets). */
	public static function still_valid( $recipient, array $payload ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.starts_at,o.status,e.status AS event_status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", (int) ( $payload['occurrence_id'] ?? 0 ) ) );
		if ( ! $occ || 'scheduled' !== $occ->status || 'published' !== $occ->event_status || $occ->starts_at !== ( $payload['starts_at'] ?? '' ) || strtotime( $occ->starts_at . ' UTC' ) <= time() ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' AND LOWER(COALESCE(NULLIF(t.attendee_email,''),ord.customer_email))=%s", (int) $payload['occurrence_id'], strtolower( (string) $recipient ) ) );
	}

	/** Cancels queued reminders that are no longer valid. Returns the number cancelled. */
	public static function purge_stale() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT message_id,recipient,payload FROM $table WHERE status='queued' AND template_key=%s LIMIT 500", self::TEMPLATE ) );
		$cancelled = 0;
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			if ( is_array( $payload ) && self::still_valid( $row->recipient, $payload ) ) { continue; }
			$cancelled += (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='cancelled',last_error='reminder_not_valid' WHERE message_id=%d AND status='queued'", (int) $row->message_id ) );
		}
		return $cancelled;
	}

	/** wpcap_email_template: renders the reminder with the current texts and occurrence data. */
	public static function render_template( $template, $key = '', $payload = array() ) {
		if ( self::TEMPLATE !== $key || ! is_array( $payload ) ) { return $template; }
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.timezone,o.location,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", (int) ( $payload['occurrence_id'] ?? 0 ) ) );
		if ( ! $occ ) { return $template; }
		$c = self::config();
		$vars = array_merge( Placeholders::occurrence_vars( $occ ), array( 'hours' => (int) ( $payload['hours'] ?? 0 ), 'tickets' => (int) ( $payload['tickets'] ?? 0 ) ) );
		$body = Placeholders::render( $c['body'], $vars );
		// The reminder editor is plain text: in HTML mode the rendered text is escaped as a whole
		// (values included), URLs become links and the layout adds the plain-text alternative.
		$html = 'html' === \PassCaptain\Mail\Templates::default_format( 'reminder' );
		return array( 'subject' => Placeholders::render( $c['subject'], $vars ), 'body' => $html ? \PassCaptain\Mail\Mailer::text_to_html( $body ) : $body, 'format' => $html ? 'html' : 'text' );
	}

	/* ------------------------------------------------------------------ Admin */

	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$offsets = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) ( is_scalar( $in['offsets'] ?? null ) ? $in['offsets'] : '' ) ) ), static function ( $h ) { return $h >= 1 && $h <= 720; } ) ) );
		sort( $offsets );
		$values = array(
			'enabled' => Forms::flag( $in, 'enabled' ) ? 1 : 0,
			'offsets' => array_slice( $offsets, 0, 5 ),
			'subject' => Forms::text( $in, 'subject', 190 ),
			'body' => Forms::textarea( $in, 'body', 5000 ),
			'sms' => Forms::flag( $in, 'sms' ) ? 1 : 0,
			'sms_body' => Forms::textarea( $in, 'sms_body', 640 ),
		);
		if ( $values['enabled'] && ( ! $values['offsets'] || '' === $values['subject'] || '' === $values['body'] ) ) {
			return Forms::error( 'wpcap_reminders_invalid', __( 'Bitte mindestens einen Zeitpunkt (1–720 Stunden), einen Betreff und einen Text angeben.', 'wp-pass-captain' ) );
		}
		Settings::update( array( 'reminders' => $values ) );
		return array( 'saved' => $values );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_reminders_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$c = self::config();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Erinnerungen', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		Forms::open_form( 'wpcap_reminders_save' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Aktiv', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $c['enabled'] ), true, false ) . '> ' . esc_html__( 'Erinnerungs-E-Mails an Ticketinhaber:innen senden', 'wp-pass-captain' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-rem-offsets">' . esc_html__( 'Zeitpunkte (Stunden vor Beginn)', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-rem-offsets" name="offsets" value="' . esc_attr( implode( ', ', $c['offsets'] ) ) . '"><p class="description">' . esc_html__( 'Kommagetrennt, z. B. 48, 2 (max. 5 Werte). Pro Termin und Zeitpunkt erhält jede Person höchstens eine Nachricht.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-rem-subject">' . esc_html__( 'Betreff', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-rem-subject" name="subject" class="large-text" value="' . esc_attr( $c['subject'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-rem-body">' . esc_html__( 'Text', 'wp-pass-captain' ) . '</label></th><td><textarea id="wpcap-rem-body" name="body" class="large-text" rows="10">' . esc_textarea( $c['body'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'SMS', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="sms" value="1"' . checked( ! empty( $c['sms'] ), true, false ) . '> ' . esc_html__( 'Zusätzlich per SMS erinnern (nur mit Einwilligung, siehe SMS-Einstellungen; kostenpflichtig)', 'wp-pass-captain' ) . '</label><textarea name="sms_body" class="large-text" rows="2" aria-label="' . esc_attr__( 'SMS-Text', 'wp-pass-captain' ) . '">' . esc_textarea( $c['sms_body'] ) . '</textarea></td></tr>';
		echo '</tbody></table><h2>' . esc_html__( 'Platzhalter', 'wp-pass-captain' ) . '</h2><ul>';
		foreach ( Placeholders::available() as $placeholder => $label ) { echo '<li><code>' . esc_html( $placeholder ) . '</code> – ' . esc_html( $label ) . '</li>'; }
		echo '</ul>';
		submit_button( __( 'Speichern', 'wp-pass-captain' ) );
		echo '</form></div>';
	}
}
