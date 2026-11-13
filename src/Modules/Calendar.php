<?php
/**
 * Event calendar: shortcode [wp_pass_captain_calendar] / block wp-pass-captain/calendar (month and
 * list view of upcoming published occurrences), public iCalendar downloads (?wpcap_ics=occurrence&id=N),
 * per-event feeds (?wpcap_ics=event&id=N, webcal-compatible) and an .ics attachment per occurrence
 * in the ticket mail.
 */
namespace PassCaptain\Modules;

use PassCaptain\Admin\Forms;
use PassCaptain\Calendar\Ics;
use PassCaptain\Frontend\EventCard;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Calendar {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-calendar';

	public static function register() {
		add_shortcode( 'wp_pass_captain_calendar', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'wpcap_calendar', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_ics' ), 1 );
		add_filter( 'wpcap_ticket_mail', array( __CLASS__, 'attach_ics' ), 20, 3 );
		add_filter( 'wpcap_event_card_html', array( __CLASS__, 'add_card_anchor' ), 5, 3 );
	}

	public static function settings_defaults() {
		return array(
			// Array setting: kept out of the generic settings form (which truncates text fields).
			'calendar' => array( 'checkout_url' => '', 'attach_ics' => 1 ),
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'Kalender', 'wp-pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_calendar_save' => array( __CLASS__, 'handle_save' ) );
	}

	public static function register_block() {
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type( WPCAP_PATH . 'src/blocks/calendar' );
		}
	}

	/* ------------------------------------------------------------------ iCalendar endpoints */

	public static function maybe_serve_ics() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only download.
		if ( empty( $_GET['wpcap_ics'] ) ) { return; }
		$kind = sanitize_key( wp_unslash( $_GET['wpcap_ics'] ) );
		$id = absint( $_GET['id'] ?? 0 );
		// phpcs:enable
		$body = self::ics_for( $kind, $id );
		if ( null === $body ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'Kalendereintrag nicht gefunden.', 'wp-pass-captain' );
			exit;
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( 'event' === $kind ? 'inline' : 'attachment' ) . '; filename="' . ( 'event' === $kind ? 'event-' : 'termin-' ) . $id . '.ics"' );
		header( 'Cache-Control: public, max-age=900' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body, values escaped per RFC 5545.
		exit;
	}

	/** Body for a public request or null (unknown, unpublished). */
	public static function ics_for( $kind, $id ) {
		if ( 'occurrence' === $kind ) { return Ics::occurrence_calendar( $id ); }
		if ( 'event' === $kind ) { return Ics::event_feed( $id ); }
		return null;
	}

	/** wpcap_ticket_mail: one .ics file per occurrence of the issued tickets. */
	public static function attach_ics( $mail, $order = null, $issued = array() ) {
		$settings = (array) Settings::get( 'calendar' );
		if ( empty( $settings['attach_ics'] ) || ! is_array( $mail ) ) { return $mail; }
		$seen = array();
		foreach ( (array) $issued as $ticket ) {
			$occ = (int) ( $ticket['occurrence_id'] ?? 0 );
			if ( ! $occ || isset( $seen[ $occ ] ) ) { continue; }
			$seen[ $occ ] = true;
			$ics = Ics::occurrence_calendar( $occ );
			if ( null === $ics ) { continue; }
			$file = trailingslashit( get_temp_dir() ) . 'termin-' . $occ . '-' . bin2hex( random_bytes( 8 ) ) . '.ics';
			if ( false !== file_put_contents( $file, $ics ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				// Core (Orders::issue_and_email) deletes every file in $mail['attachments'] after wp_mail();
				// the shutdown cleanup covers a later filter dropping the file from the list.
				$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), array( $file ) );
				self::track_temp_file( $file );
			}
		}
		return $mail;
	}

	/** Temporary files created during this request. */
	private static $temp_files = array();

	private static function track_temp_file( $file ) {
		if ( ! self::$temp_files ) { add_action( 'shutdown', array( __CLASS__, 'cleanup_temp_files' ) ); }
		self::$temp_files[] = $file;
	}

	/** Removes .ics files of this request that still exist. */
	public static function cleanup_temp_files() {
		foreach ( self::$temp_files as $file ) {
			if ( file_exists( $file ) ) { wp_delete_file( $file ); }
		}
		self::$temp_files = array();
	}

	/** Gives each event card an anchor (#wpcap-occ-{id}) the calendar can link to. */
	public static function add_card_anchor( $html, $event = null, $types = null ) {
		if ( ! is_object( $event ) || empty( $event->occurrence_id ) || false !== strpos( (string) $html, 'id="wpcap-occ-' ) ) { return $html; }
		return preg_replace( '/<article class="wpcap-event"/', '<article id="wpcap-occ-' . absint( $event->occurrence_id ) . '" class="wpcap-event"', (string) $html, 1 );
	}

	/* ------------------------------------------------------------------ Shortcode / block */

	/**
	 * Upcoming occurrences of published events between two UTC datetimes: scheduled ones, plus
	 * cancelled ones with $with_cancelled (badge "Abgesagt"). Rows carry the full occurrence (status,
	 * capacity counters, doors_open_at), the event name and rescheduled_at (last reschedule or NULL).
	 */
	public static function occurrences( $from_utc, $to_utc, $event_id = 0, $limit = 200, $with_cancelled = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$status = $with_cancelled ? "o.status IN ('scheduled','cancelled')" : "o.status='scheduled'";
		$sql = "SELECT o.*,e.name AS event_name," . EventCard::rescheduled_sql( 'o' ) . " AS rescheduled_at FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE e.status='published' AND $status AND o.starts_at>=%s AND o.starts_at<%s";
		$args = array( $from_utc, $to_utc );
		if ( $event_id ) { $sql .= ' AND o.event_id=%d'; $args[] = absint( $event_id ); }
		$sql .= ' ORDER BY o.starts_at,o.occurrence_id LIMIT %d';
		$args[] = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals.
	}

	public static function render_shortcode( $atts = array() ) {
		$atts = function_exists( 'shortcode_atts' ) ? shortcode_atts( array( 'view' => 'month', 'event' => 0, 'limit' => 50 ), (array) $atts, 'wp_pass_captain_calendar' ) : array_merge( array( 'view' => 'month', 'event' => 0, 'limit' => 50 ), (array) $atts );
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'wpcap-calendar', WPCAP_URL . 'assets/calendar/calendar.css', array(), WPCAP_VERSION );
		}
		$view = 'list' === $atts['view'] ? 'list' : 'month';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$month = isset( $_GET['wpcap_month'] ) ? sanitize_text_field( wp_unslash( $_GET['wpcap_month'] ) ) : '';
		return 'list' === $view ? self::render_list( absint( $atts['event'] ), absint( $atts['limit'] ) ) : self::render_month( $month, absint( $atts['event'] ) );
	}

	/** Local date/time of an occurrence in its own timezone. */
	private static function local( $row, $format ) {
		try {
			$tz = new \DateTimeZone( $row->timezone ?: 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		$dt = new \DateTime( $row->starts_at, new \DateTimeZone( 'UTC' ) );
		$dt->setTimezone( $tz );
		return $dt->format( $format );
	}

	private static function link_for( $row ) {
		$url = Ics::checkout_url( (int) $row->occurrence_id );
		return '' !== $url ? $url : '#wpcap-occ-' . (int) $row->occurrence_id;
	}

	/** Whether cancelled upcoming dates stay visible (unlinked, badge "Abgesagt"). */
	private static function show_cancelled() {
		return (bool) apply_filters( 'wpcap_calendar_show_cancelled', true );
	}

	/**
	 * Badges per occurrence row (Nächster Termin, Abgesagt, Verschoben, Ausverkauft, Wenige Plätze,
	 * Einlass ab …): one ticket-type query plus availability for all rows.
	 *
	 * @return array occurrence_id => badge list
	 */
	public static function badges_for( array $rows ) {
		$available = EventCard::availability_for( $rows );
		$next = EventCard::next_occurrences( $rows, $available );
		$out = array();
		foreach ( $rows as $row ) {
			$id = (int) $row->occurrence_id;
			$out[ $id ] = EventCard::badges( $row, array_key_exists( $id, $available ) ? $available[ $id ] : false, array( 'next' => ! empty( $next[ $id ] ), 'view' => 'calendar' ) );
		}
		return $out;
	}

	public static function render_list( $event_id = 0, $limit = 50 ) {
		$rows = self::occurrences( current_time( 'mysql', true ), '9999-12-31 00:00:00', $event_id, $limit ?: 50, self::show_cancelled() );
		$out = '<div class="wpcap-calendar wpcap-calendar-list">';
		if ( ! $rows ) {
			return $out . '<p>' . esc_html__( 'Aktuell sind keine Termine geplant.', 'wp-pass-captain' ) . '</p></div>';
		}
		$badges = self::badges_for( $rows );
		$out .= '<ul class="wpcap-cal-items">';
		foreach ( $rows as $row ) {
			$iso = self::local( $row, 'c' );
			$cancelled = 'cancelled' === $row->status;
			$out .= '<li class="wpcap-cal-item' . ( $cancelled ? ' wpcap-cal-cancelled' : '' ) . '"><time datetime="' . esc_attr( $iso ) . '">' . esc_html( self::local( $row, 'd.m.Y H:i' ) . ' ' . $row->timezone ) . '</time> ';
			$out .= $cancelled ? '<span class="wpcap-cal-link">' . esc_html( $row->event_name ) . '</span>' : '<a class="wpcap-cal-link" href="' . esc_url( self::link_for( $row ) ) . '">' . esc_html( $row->event_name ) . '</a>';
			if ( '' !== (string) $row->location ) { $out .= ' <span class="wpcap-cal-location">' . esc_html( $row->location ) . '</span>'; }
			$out .= EventCard::render_badges( $badges[ (int) $row->occurrence_id ] ?? array() );
			/* translators: %s: event name */
			$out .= $cancelled ? '' : ' <a class="wpcap-cal-ics" href="' . esc_url( Ics::occurrence_url( $row->occurrence_id ) ) . '" aria-label="' . esc_attr( sprintf( __( '%s zum Kalender hinzufügen (.ics)', 'wp-pass-captain' ), $row->event_name ) ) . '">' . esc_html__( 'Zum Kalender (.ics)', 'wp-pass-captain' ) . '</a>';
			$out .= '</li>';
		}
		return $out . '</ul></div>';
	}

	/** Accessible month grid (table with caption, column headers and per-day lists). */
	public static function render_month( $month = '', $event_id = 0 ) {
		$tz_name = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		try {
			$tz = new \DateTimeZone( $tz_name ?: 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		$now = new \DateTime( 'now', $tz );
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $month ) ) { $month = $now->format( 'Y-m' ); }
		$first = new \DateTime( $month . '-01 00:00:00', $tz );
		$next = ( clone $first )->modify( '+1 month' );
		$prev = ( clone $first )->modify( '-1 month' );
		$from = ( clone $first )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$from = max( $from, current_time( 'mysql', true ) ); // Upcoming only.
		$to = ( clone $next )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$by_day = array();
		$rows = self::occurrences( $from, $to, $event_id, 500, self::show_cancelled() );
		$badges = $rows ? self::badges_for( $rows ) : array();
		foreach ( $rows as $row ) {
			$dt = new \DateTime( $row->starts_at, new \DateTimeZone( 'UTC' ) );
			$dt->setTimezone( $tz );
			$by_day[ $dt->format( 'Y-m-d' ) ][] = $row;
		}
		$months = array( 1 => __( 'Januar', 'wp-pass-captain' ), __( 'Februar', 'wp-pass-captain' ), __( 'März', 'wp-pass-captain' ), __( 'April', 'wp-pass-captain' ), __( 'Mai', 'wp-pass-captain' ), __( 'Juni', 'wp-pass-captain' ), __( 'Juli', 'wp-pass-captain' ), __( 'August', 'wp-pass-captain' ), __( 'September', 'wp-pass-captain' ), __( 'Oktober', 'wp-pass-captain' ), __( 'November', 'wp-pass-captain' ), __( 'Dezember', 'wp-pass-captain' ) );
		$days = array( __( 'Montag', 'wp-pass-captain' ), __( 'Dienstag', 'wp-pass-captain' ), __( 'Mittwoch', 'wp-pass-captain' ), __( 'Donnerstag', 'wp-pass-captain' ), __( 'Freitag', 'wp-pass-captain' ), __( 'Samstag', 'wp-pass-captain' ), __( 'Sonntag', 'wp-pass-captain' ) );
		$title = $months[ (int) $first->format( 'n' ) ] . ' ' . $first->format( 'Y' );
		$base = function_exists( 'get_permalink' ) ? (string) get_permalink() : home_url( '/' );
		$out = '<div class="wpcap-calendar wpcap-calendar-month">';
		$out .= '<nav class="wpcap-cal-nav" aria-label="' . esc_attr__( 'Monat wechseln', 'wp-pass-captain' ) . '">';
		$out .= '<a href="' . esc_url( add_query_arg( 'wpcap_month', $prev->format( 'Y-m' ), $base ) ) . '" rel="prev">' . esc_html__( '← Vorheriger Monat', 'wp-pass-captain' ) . '</a> ';
		$out .= '<a href="' . esc_url( add_query_arg( 'wpcap_month', $next->format( 'Y-m' ), $base ) ) . '" rel="next">' . esc_html__( 'Nächster Monat →', 'wp-pass-captain' ) . '</a></nav>';
		$out .= '<table class="wpcap-cal-grid"><caption>' . esc_html( $title ) . '</caption><thead><tr>';
		foreach ( $days as $day ) {
			$out .= '<th scope="col"><abbr title="' . esc_attr( $day ) . '">' . esc_html( function_exists( 'mb_substr' ) ? mb_substr( $day, 0, 2 ) : substr( $day, 0, 2 ) ) . '</abbr></th>';
		}
		$out .= '</tr></thead><tbody><tr>';
		$offset = (int) $first->format( 'N' ) - 1;
		for ( $i = 0; $i < $offset; $i++ ) { $out .= '<td class="wpcap-cal-empty" aria-hidden="true"></td>'; }
		$count = (int) $first->format( 't' );
		for ( $d = 1; $d <= $count; $d++ ) {
			$date = $first->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$items = $by_day[ $date ] ?? array();
			$out .= '<td' . ( $items ? ' class="wpcap-cal-has-events"' : '' ) . '><span class="wpcap-cal-day"><time datetime="' . esc_attr( $date ) . '">' . (int) $d . '</time></span>';
			if ( $items ) {
				$out .= '<ul>';
				foreach ( $items as $row ) {
					/* translators: 1: event name, 2: time */
					$label = sprintf( __( '%1$s um %2$s', 'wp-pass-captain' ), $row->event_name, self::local( $row, 'H:i' ) );
					$text = esc_html( self::local( $row, 'H:i' ) . ' ' . $row->event_name );
					$out .= 'cancelled' === $row->status ? '<li class="wpcap-cal-cancelled"><span>' . $text . '</span>' : '<li><a href="' . esc_url( self::link_for( $row ) ) . '" aria-label="' . esc_attr( $label . ', ' . self::local( $row, 'd.m.Y' ) ) . '">' . $text . '</a>';
					$out .= EventCard::render_badges( $badges[ (int) $row->occurrence_id ] ?? array() ) . '</li>';
				}
				$out .= '</ul>';
			}
			$out .= '</td>';
			if ( 0 === ( $offset + $d ) % 7 && $d < $count ) { $out .= '</tr><tr>'; }
		}
		$rest = ( 7 - ( $offset + $count ) % 7 ) % 7;
		for ( $i = 0; $i < $rest; $i++ ) { $out .= '<td class="wpcap-cal-empty" aria-hidden="true"></td>'; }
		$out .= '</tr></tbody></table>';
		if ( ! $by_day ) { $out .= '<p>' . esc_html__( 'In diesem Monat sind keine Termine geplant.', 'wp-pass-captain' ) . '</p>'; }
		return $out . '</div>';
	}

	/* ------------------------------------------------------------------ Admin */

	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$url = esc_url_raw( trim( (string) ( $in['checkout_url'] ?? '' ) ) );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			return Forms::error( 'wpcap_calendar_url', __( 'Bitte eine vollständige URL angeben.', 'wp-pass-captain' ) );
		}
		$values = array( 'checkout_url' => $url, 'attach_ics' => Forms::flag( $in, 'attach_ics' ) ? 1 : 0 );
		Settings::update( array( 'calendar' => $values ) );
		return array( 'saved' => $values );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_calendar_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$s = (array) Settings::get( 'calendar' );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Kalender', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Shortcode: [wp_pass_captain_calendar view="month"] oder view="list"; alternativ der Block „WP Pass Captain Kalender“.', 'wp-pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_calendar_save' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-cal-url">' . esc_html__( 'Seite mit dem Ticket-Shortcode', 'wp-pass-captain' ) . '</label></th><td><input type="url" id="wpcap-cal-url" name="checkout_url" class="regular-text" value="' . esc_attr( (string) ( $s['checkout_url'] ?? '' ) ) . '"><p class="description">' . esc_html( sprintf( /* translators: %s: anchor format, e.g. #wpcap-occ-{Termin-ID} */ __( 'Kalendereinträge verlinken auf diese Seite (Anker %s). Leer: nur Anker auf derselben Seite.', 'wp-pass-captain' ), '#wpcap-occ-{' . /* translators: placeholder name for the numeric occurrence ID in an anchor */ __( 'Termin-ID', 'wp-pass-captain' ) . '}' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Ticket-E-Mail', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="attach_ics" value="1"' . checked( ! empty( $s['attach_ics'] ), true, false ) . '> ' . esc_html__( 'Kalenderdatei (.ics) je Termin anhängen', 'wp-pass-captain' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Speichern', 'wp-pass-captain' ) );
		echo '</form></div>';
	}
}
