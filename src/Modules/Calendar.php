<?php
/**
 * Event calendar: shortcode [wp_pass_captain_calendar] / block wp-pass-captain/calendar (month and
 * list view of upcoming published occurrences), public iCalendar downloads (?wpcap_ics=occurrence&id=N),
 * per-event feeds (?wpcap_ics=event&id=N, webcal-compatible) and an .ics attachment per occurrence
 * in the ticket mail. Every copy uses the same UID per occurrence and the occurrence's revision as
 * SEQUENCE (A140, Calendar\Revisions), so a later download or the .ics of a change or cancellation
 * mail (Notifications, OccurrenceCancellation) updates the imported entry.
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
		add_filter( 'wpcap_ticket_resend_attachments', array( __CLASS__, 'resend_ics' ), 20, 3 );
		add_filter( 'wpcap_event_card_html', array( __CLASS__, 'add_card_anchor' ), 5, 3 );
	}

	public static function settings_defaults() {
		return array(
			// Array setting: kept out of the generic settings form (which truncates text fields).
			'calendar' => array( 'checkout_url' => '', 'attach_ics' => 1 ),
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'Kalender', 'pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_calendar_save' => array( __CLASS__, 'handle_save' ) );
	}

	public static function register_block() {
		if ( function_exists( 'register_block_type' ) ) {
			\PassCaptain\PublicEvents::register_styles(); // block.json "style" (A391).
			$block = register_block_type( WPCAP_PATH . 'src/blocks/calendar' );
			\PassCaptain\Localization::block_translations( $block );
			// A502: the event selection of the block (published events with upcoming dates).
			if ( $block && ! empty( $block->editor_script_handles ) && is_admin() ) {
				add_action( 'enqueue_block_editor_assets', static function () use ( $block ) {
					wp_add_inline_script( $block->editor_script_handles[0], 'window.WPCAP_BLOCK_CALENDAR=' . wp_json_encode( self::block_choices() ) . ';', 'before' );
				} );
			}
		}
	}

	/** Events for the calendar block's selection (A502): id and label as in the events block. */
	public static function block_choices() {
		$events = array();
		foreach ( (array) ( \PassCaptain\PublicEvents::block_choices()['events'] ?? array() ) as $event ) {
			$events[] = array( 'id' => (int) $event['id'], 'label' => (string) $event['label'] );
		}
		return array( 'events' => $events );
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
			echo esc_html__( 'Kalendereintrag nicht gefunden.', 'pass-captain' );
			\PassCaptain\Http::end();
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( 'event' === $kind ? 'inline' : 'attachment' ) . '; filename="' . ( 'event' === $kind ? 'event-' : 'termin-' ) . $id . '.ics"' );
		header( 'Cache-Control: public, max-age=900' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body, values escaped per RFC 5545.
		\PassCaptain\Http::end();
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
			if ( \PassCaptain\TempFiles::put( $file, $ics ) ) {
				// Core (Orders::issue_and_email) deletes every file in $mail['attachments'] after wp_mail();
				// the shutdown cleanup covers a later filter dropping the file from the list.
				$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), array( $file ) );
				self::track_temp_file( $file );
			}
		}
		return $mail;
	}

	/** A621: wpcap_ticket_resend_attachments – the same .ics files for a resent ticket mail. */
	public static function resend_ics( $files, $order = null, $tickets = array() ) {
		$mail = self::attach_ics( array( 'attachments' => (array) $files ), $order, (array) $tickets );
		return is_array( $mail ) ? (array) ( $mail['attachments'] ?? $files ) : $files;
	}

	/** Temporary files created during this request. */
	private static $temp_files = array();

	private static function track_temp_file( $file ) {
		if ( ! self::$temp_files ) { add_action( 'shutdown', array( __CLASS__, 'cleanup_temp_files' ) ); }
		self::$temp_files[] = $file;
	}

	/** Removes .ics files of this request that still exist. */
	public static function cleanup_temp_files() {
		// A626: kept for mail queue plugins until the cleanup sweep (Mail\TempFiles), unless the TTL is 0.
		\PassCaptain\Mail\TempFiles::release( self::$temp_files );
		self::$temp_files = array();
	}

	/** Gives each event card an anchor (#wpcap-occ-{id}) the calendar can link to; only the first card of a date (A308). */
	public static function add_card_anchor( $html, $event = null, $types = null ) {
		if ( ! is_object( $event ) || empty( $event->occurrence_id ) || false !== strpos( (string) $html, 'id="wpcap-occ-' ) ) { return $html; }
		if ( preg_match( '/-i\d+$/', \PassCaptain\Frontend\EventCard::form_id_in( $html ) ) ) { return $html; }
		// A504: only once per page, also across listings (another card may carry it as a date anchor).
		if ( ! \PassCaptain\Frontend\EventCard::claim_anchor( 'wpcap-occ-' . absint( $event->occurrence_id ) ) ) { return $html; }
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
		if ( (int) $event_id < 0 ) { return array(); } // A503: invalid event attribute – no dates rather than all.
		$p = $wpdb->prefix;
		$status = $with_cancelled ? "o.status IN ('scheduled','cancelled')" : "o.status='scheduled'";
		$sql = "SELECT o.*,e.name AS event_name," . EventCard::rescheduled_sql( 'o' ) . " AS rescheduled_at FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE e.status='published' AND $status AND o.starts_at>=%s AND o.starts_at<%s";
		$args = array( $from_utc, $to_utc );
		if ( $event_id ) { $sql .= ' AND o.event_id=%d'; $args[] = absint( $event_id ); }
		$sql .= ' ORDER BY o.starts_at,o.occurrence_id LIMIT %d';
		$args[] = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals.
	}

	/**
	 * [wpcap_calendar view="month|list" event="12" limit="50"]. A503: the attribute filter runs under
	 * the tag used (shortcode_atts_wpcap_calendar, plus the long name), an invalid event shows no
	 * dates instead of all events, and editors see a hint for every invalid attribute.
	 */
	public static function render_shortcode( $atts = array(), $content = '', $tag = '' ) {
		$raw = is_array( $atts ) ? $atts : array();
		$defaults = array( 'view' => 'month', 'event' => 0, 'limit' => 50 );
		$atts = function_exists( 'shortcode_atts' ) ? \PassCaptain\PublicEvents::shortcode_atts( $defaults, $raw, $tag, 'wp_pass_captain_calendar' ) : array_merge( $defaults, $raw );
		$problems = \PassCaptain\PublicEvents::invalid_shortcode_atts( $atts, array( 'view' => array( 'month', 'list' ), 'event' => 'id', 'limit' => 'count' ), $raw );
		$event = isset( $problems['event'] ) ? -1 : absint( $atts['event'] );
		$limit = isset( $problems['limit'] ) ? 50 : absint( $atts['limit'] );
		$html = self::render_view( 'list' === strtolower( trim( (string) $atts['view'] ) ) ? 'list' : 'month', $event, $limit, false );
		if ( ! $problems && $event > 0 && \PassCaptain\PublicEvents::can_see_shortcode_notice() && ! self::event_is_public( $event ) ) { $problems['event'] = (string) $event; }
		return \PassCaptain\PublicEvents::shortcode_notice( $problems, '' !== (string) $tag ? (string) $tag : 'wp_pass_captain_calendar' ) . $html;
	}

	/** Whether an event is published (A503 hint for a calendar of an unknown event). */
	private static function event_is_public( $event_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d AND status='published'", absint( $event_id ) ) );
	}

	/**
	 * Render callback of the block wp-pass-captain/calendar (render.php, A449): the block wrapper
	 * attributes (block class, custom CSS class, alignment, spacing) go on the root element.
	 */
	public static function render_block( array $attributes ) {
		// A502: limit (1–200, list view) is set in the block.
		$limit = max( 1, min( 200, absint( $attributes['limit'] ?? 50 ) ?: 50 ) );
		return self::render_view( ( $attributes['view'] ?? '' ) === 'list' ? 'list' : 'month', absint( $attributes['eventId'] ?? 0 ), $limit, true );
	}

	/** Month or list view of the shortcode or block ($block: with the block wrapper attributes). */
	private static function render_view( $view, $event_id, $limit, $block ) {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			\PassCaptain\PublicEvents::register_styles();
			wp_enqueue_style( 'wpcap-calendar' );
		}
		// A499: in feeds and REST responses the month grid (navigation, table) becomes the list.
		if ( \PassCaptain\PublicEvents::slim_output( 'calendar' ) ) { $view = 'list'; }
		$wrapper = $block && function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'wpcap-calendar wpcap-calendar-' . $view ) ) : '';
		if ( 'list' === $view ) { return self::with_requested_date( self::render_list( $event_id, $limit, $wrapper ) ); }
		// A452: every month calendar of the page has its own parameter (wpcap_month, wpcap_month_2, …),
		// so two calendars page independently and keep each other's month.
		$instance = ++self::$month_instances;
		$param = self::month_param( $instance );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$month = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
		return self::with_requested_date( self::render_month( $month, $event_id, $wrapper, $instance ) );
	}

	/** @var bool The requested date's card was already put in front of a calendar in this request. */
	private static $requested_shown = false;

	/**
	 * A calendar link on a page without the events shortcode/block (no checkout page configured or
	 * found) opens the date on the calendar page itself (?wpcap_occurrence= or ?wpcap_occ=): its card
	 * with the order form goes in front of the (first) calendar.
	 */
	private static function with_requested_date( $calendar ) {
		if ( self::$requested_shown || \PassCaptain\PublicEvents::slim_output( 'calendar' ) ) { return $calendar; }
		$occurrence = absint( wp_unslash( $_GET[ \PassCaptain\PublicEvents::OCC_PARAM ] ?? 0 ) ) ?: \PassCaptain\PublicEvents::requested_occurrence(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public, read-only view; absint.
		if ( ! $occurrence || self::page_has_events() ) { return $calendar; }
		self::$requested_shown = true;
		return \PassCaptain\PublicEvents::render_shortcode( array( 'occurrence' => $occurrence ) ) . $calendar;
	}

	/** Month calendars rendered in this request (numbering of their navigation parameters). */
	private static $month_instances = 0;

	/** Query parameter of the n-th month calendar of a page: wpcap_month, then wpcap_month_2, … */
	public static function month_param( $instance ) {
		return (int) $instance > 1 ? 'wpcap_month_' . (int) $instance : 'wpcap_month';
	}

	/**
	 * Link of the month navigation (A452): the URL of the current request (not the permalink of the
	 * global post, which is wrong in widgets, template parts, archives and query loops) with only
	 * this calendar's parameter replaced; the other query parameters, including the months of
	 * other calendars on the page, stay. The fragment jumps back to this calendar's navigation.
	 * During REST rendering (editor preview) the permalink of the edited post is the base.
	 */
	public static function nav_url( $param, $value, $fragment = '' ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only rebuilt into a relative URL, escaped on output.
		// A499: feeds link to the post (the request URL is the feed's) like REST responses.
		$rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_feed' ) && is_feed() );
		if ( $rest || '' === $uri || '/' !== $uri[0] ) {
			$uri = function_exists( 'get_permalink' ) ? (string) get_permalink() : '';
			if ( '' === $uri ) { $uri = home_url( '/' ); }
		} else {
			// Never let the path become protocol-relative (//host/…) or a backslash variant of it.
			$uri = '/' . ltrim( $uri, '/\\' );
		}
		$uri = explode( '#', $uri, 2 )[0];
		$parts = explode( '?', $uri, 2 );
		$pairs = array();
		foreach ( isset( $parts[1] ) && '' !== $parts[1] ? explode( '&', $parts[1] ) : array() as $pair ) {
			if ( '' === $pair || urldecode( explode( '=', $pair, 2 )[0] ) === $param ) { continue; }
			$pairs[] = $pair;
		}
		$pairs[] = rawurlencode( $param ) . '=' . rawurlencode( (string) $value );
		return $parts[0] . '?' . implode( '&', $pairs ) . ( '' !== $fragment ? '#' . rawurlencode( $fragment ) : '' );
	}

	/** Opening tag of the root element: block wrapper attributes or the plain classes. */
	private static function open_root( $wrapper, $view ) {
		return '<div ' . ( '' !== $wrapper ? $wrapper : 'class="wpcap-calendar wpcap-calendar-' . $view . '"' ) . '>';
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

	/** Link of a calendar entry: checkout URL, else the single-occurrence view on this page (rendered above the calendar). */
	private static function link_for( $row ) {
		$url = Ics::checkout_url( (int) $row->occurrence_id );
		// Same page: the listing there shows the date's card when asked with ?wpcap_occurrence= (A342).
		return '' !== $url ? $url : \PassCaptain\PublicEvents::occurrence_link( (int) $row->occurrence_id );
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

	/** Whether the current post shows the events shortcode or block itself. */
	private static function page_has_events() {
		$post = function_exists( 'get_post' ) ? get_post() : null;
		if ( ! $post ) { return false; }
		$content = (string) $post->post_content;
		return ( function_exists( 'has_shortcode' ) && ( has_shortcode( $content, 'wp_pass_captain_events' ) || has_shortcode( $content, 'wpcap_events' ) ) ) || false !== strpos( $content, '<!-- wp:wp-pass-captain/events' );
	}

	public static function render_list( $event_id = 0, $limit = 50, $wrapper = '' ) {
		$rows = self::occurrences( current_time( 'mysql', true ), '9999-12-31 00:00:00', $event_id, $limit ?: 50, self::show_cancelled() );
		$out = self::open_root( (string) $wrapper, 'list' );
		if ( ! $rows ) {
			return $out . '<p>' . esc_html__( 'Aktuell sind keine Termine geplant.', 'pass-captain' ) . '</p></div>';
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
			// A393: the accessible name starts with the visible link text and names the event and date.
			/* translators: 1: visible link text "Zum Kalender (.ics)", 2: event name, 3: date */
			$ics_label = sprintf( __( '%1$s: %2$s am %3$s', 'pass-captain' ), __( 'Zum Kalender (.ics)', 'pass-captain' ), $row->event_name, self::local( $row, 'd.m.Y' ) );
			$out .= $cancelled ? '' : ' <a class="wpcap-cal-ics" href="' . esc_url( Ics::occurrence_url( $row->occurrence_id ) ) . '" aria-label="' . esc_attr( $ics_label ) . '">' . esc_html__( 'Zum Kalender (.ics)', 'pass-captain' ) . '</a>';
			$out .= '</li>';
		}
		return $out . '</ul></div>';
	}

	/** Site timezone (Settings → General), UTC when unset or invalid. */
	private static function site_timezone() {
		$name = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
		try {
			return new \DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Start time of an occurrence in its own timezone ("20:00"); when that zone has another UTC
	 * offset than the site at that moment, with the zone abbreviation ("20:00 EST"), A313.
	 */
	public static function time_label( $row, ?\DateTimeZone $site = null ) {
		$site = $site ? $site : self::site_timezone();
		$time = self::local( $row, 'H:i' );
		$at = new \DateTime( (string) $row->starts_at, new \DateTimeZone( 'UTC' ) );
		$own = (int) self::local( $row, 'Z' );
		return $own === $site->getOffset( $at ) ? $time : $time . ' ' . self::local( $row, 'T' );
	}

	/**
	 * Accessible month grid (table with caption, column headers and per-day lists).
	 *
	 * A313: every occurrence is shown on the day and with the time of its own timezone – the date
	 * and time the event card, ticket, mail and .ics show (a time whose zone differs from the site's
	 * carries the zone abbreviation). The month, "today" and the navigation follow the site
	 * timezone. Only upcoming dates are listed, so the current month (and earlier ones) has no
	 * "previous month" link; every other month links back to the current one.
	 *
	 * A394: on narrow screens the table is replaced by an agenda (.wpcap-cal-agenda, hidden on wide
	 * screens): the month as title and a list of the days with dates, each with weekday, day and
	 * month ("Montag, 16. November") and its entries – a real list instead of table cells without
	 * column headers.
	 */
	public static function render_month( $month = '', $event_id = 0, $wrapper = '', $instance = 1 ) {
		$tz = self::site_timezone();
		$now = new \DateTime( 'now', $tz );
		$current = $now->format( 'Y-m' );
		$today = $now->format( 'Y-m-d' );
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $month ) ) { $month = $current; }
		$first = new \DateTime( $month . '-01 00:00:00', $tz );
		$next = ( clone $first )->modify( '+1 month' );
		$prev = ( clone $first )->modify( '-1 month' );
		// The local dates of this month, in any timezone (UTC-12 … UTC+14), lie inside this UTC window.
		$utc = new \DateTimeZone( 'UTC' );
		$from = ( new \DateTime( $month . '-01 00:00:00', $utc ) )->modify( '-14 hours' )->format( 'Y-m-d H:i:s' );
		$from = max( $from, current_time( 'mysql', true ) ); // Upcoming only.
		$to = ( new \DateTime( $next->format( 'Y-m-d' ) . ' 00:00:00', $utc ) )->modify( '+12 hours' )->format( 'Y-m-d H:i:s' );
		$by_day = array();
		$rows = array();
		foreach ( self::occurrences( $from, $to, $event_id, 500, self::show_cancelled() ) as $row ) {
			$day = self::local( $row, 'Y-m-d' );
			if ( 0 !== strpos( $day, $month . '-' ) ) { continue; } // Another month in its own timezone.
			$by_day[ $day ][] = $row;
			$rows[] = $row;
		}
		$badges = $rows ? self::badges_for( $rows ) : array();
		$months = array( 1 => __( 'Januar', 'pass-captain' ), __( 'Februar', 'pass-captain' ), __( 'März', 'pass-captain' ), __( 'April', 'pass-captain' ), __( 'Mai', 'pass-captain' ), __( 'Juni', 'pass-captain' ), __( 'Juli', 'pass-captain' ), __( 'August', 'pass-captain' ), __( 'September', 'pass-captain' ), __( 'Oktober', 'pass-captain' ), __( 'November', 'pass-captain' ), __( 'Dezember', 'pass-captain' ) );
		$days = array( __( 'Montag', 'pass-captain' ), __( 'Dienstag', 'pass-captain' ), __( 'Mittwoch', 'pass-captain' ), __( 'Donnerstag', 'pass-captain' ), __( 'Freitag', 'pass-captain' ), __( 'Samstag', 'pass-captain' ), __( 'Sonntag', 'pass-captain' ) );
		$short = array_map( static function ( $day ) { return function_exists( 'mb_substr' ) ? mb_substr( $day, 0, 2 ) : substr( $day, 0, 2 ); }, $days );
		$title = $months[ (int) $first->format( 'n' ) ] . ' ' . $first->format( 'Y' );
		$instance = max( 1, (int) $instance );
		$param = self::month_param( $instance );
		$nav_id = 'wpcap-cal-nav-' . $instance;
		$out = self::open_root( (string) $wrapper, 'month' );
		$out .= '<nav class="wpcap-cal-nav" id="' . esc_attr( $nav_id ) . '" aria-label="' . esc_attr__( 'Monat wechseln', 'pass-captain' ) . '">';
		if ( $month > $current ) {
			$out .= '<a href="' . esc_url( self::nav_url( $param, $prev->format( 'Y-m' ), $nav_id ) ) . '" rel="prev">' . esc_html__( '← Vorheriger Monat', 'pass-captain' ) . '</a> ';
		}
		if ( $month !== $current ) {
			$out .= '<a class="wpcap-cal-current" href="' . esc_url( self::nav_url( $param, $current, $nav_id ) ) . '">' . esc_html__( 'Aktueller Monat', 'pass-captain' ) . '</a> ';
		}
		$out .= '<a href="' . esc_url( self::nav_url( $param, $next->format( 'Y-m' ), $nav_id ) ) . '" rel="next">' . esc_html__( 'Nächster Monat →', 'pass-captain' ) . '</a></nav>';
		$out .= '<table class="wpcap-cal-grid"><caption>' . esc_html( $title ) . '</caption><thead><tr>';
		foreach ( $days as $i => $day ) {
			$out .= '<th scope="col"><abbr title="' . esc_attr( $day ) . '">' . esc_html( $short[ $i ] ) . '</abbr></th>';
		}
		$out .= '</tr></thead><tbody><tr>';
		$agenda = '';
		$offset = (int) $first->format( 'N' ) - 1;
		for ( $i = 0; $i < $offset; $i++ ) { $out .= '<td class="wpcap-cal-empty" aria-hidden="true"></td>'; }
		$count = (int) $first->format( 't' );
		for ( $d = 1; $d <= $count; $d++ ) {
			$date = $first->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$items = $by_day[ $date ] ?? array();
			$classes = array_filter( array( $items ? 'wpcap-cal-has-events' : '', $date === $today ? 'wpcap-cal-today' : '' ) );
			$out .= '<td' . ( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' ) . ( $date === $today ? ' aria-current="date"' : '' ) . '>';
			$out .= '<span class="wpcap-cal-day"><time datetime="' . esc_attr( $date ) . '">' . (int) $d . '</time></span>';
			if ( $items ) {
				$entries = '<ul>';
				foreach ( $items as $row ) {
					$visible = self::time_label( $row, $tz ) . ' ' . $row->event_name;
					$text = esc_html( $visible );
					// A393: the accessible name starts with the visible text (WCAG 2.5.3) and adds the date.
					$entries .= 'cancelled' === $row->status ? '<li class="wpcap-cal-cancelled"><span>' . $text . '</span>' : '<li><a href="' . esc_url( self::link_for( $row ) ) . '" aria-label="' . esc_attr( $visible . ', ' . self::local( $row, 'd.m.Y' ) ) . '">' . $text . '</a>';
					$entries .= EventCard::render_badges( $badges[ (int) $row->occurrence_id ] ?? array() ) . '</li>';
				}
				$entries .= '</ul>';
				$out .= $entries;
				/* translators: 1: weekday, 2: day of the month, 3: month name */
				$day_label = sprintf( __( '%1$s, %2$d. %3$s', 'pass-captain' ), $days[ ( $offset + $d - 1 ) % 7 ], $d, $months[ (int) $first->format( 'n' ) ] );
				$agenda .= '<li class="wpcap-cal-agenda-day wpcap-cal-has-events' . ( $date === $today ? ' wpcap-cal-today' : '' ) . '"' . ( $date === $today ? ' aria-current="date"' : '' ) . '><p class="wpcap-cal-agenda-date"><time datetime="' . esc_attr( $date ) . '">' . esc_html( $day_label ) . '</time></p>' . $entries . '</li>';
			}
			$out .= '</td>';
			if ( 0 === ( $offset + $d ) % 7 && $d < $count ) { $out .= '</tr><tr>'; }
		}
		$rest = ( 7 - ( $offset + $count ) % 7 ) % 7;
		for ( $i = 0; $i < $rest; $i++ ) { $out .= '<td class="wpcap-cal-empty" aria-hidden="true"></td>'; }
		$out .= '</tr></tbody></table>';
		// A394: the list for narrow screens (CSS shows either the table or this list).
		$out .= '<div class="wpcap-cal-agenda"><p class="wpcap-cal-agenda-title">' . esc_html( $title ) . '</p>' . ( '' !== $agenda ? '<ol class="wpcap-cal-agenda-days">' . $agenda . '</ol>' : '' ) . '</div>';
		if ( ! $by_day ) { $out .= '<p>' . esc_html__( 'In diesem Monat sind keine Termine geplant.', 'pass-captain' ) . '</p>'; }
		return $out . '</div>';
	}

	/* ------------------------------------------------------------------ Admin */

	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$url = esc_url_raw( trim( (string) ( $in['checkout_url'] ?? '' ) ) );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			return Forms::error( 'wpcap_calendar_url', __( 'Bitte eine vollständige URL angeben.', 'pass-captain' ) );
		}
		$values = array( 'checkout_url' => $url, 'attach_ics' => Forms::flag( $in, 'attach_ics' ) ? 1 : 0 );
		Settings::update( array( 'calendar' => $values ) );
		return array( 'saved' => $values );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_calendar_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$s = (array) Settings::get( 'calendar' );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Kalender', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Shortcode: [wp_pass_captain_calendar view="month"] oder view="list"; alternativ der Block „Pass Captain Kalender“.', 'pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_calendar_save' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-cal-url">' . esc_html__( 'Seite mit dem Ticket-Shortcode', 'pass-captain' ) . '</label></th><td><input type="url" id="wpcap-cal-url" name="checkout_url" class="regular-text" value="' . esc_attr( (string) ( $s['checkout_url'] ?? '' ) ) . '"><p class="description">' . esc_html( sprintf( /* translators: %s: anchor format, e.g. #wpcap-occ-{Termin-ID} */ __( 'Kalendereinträge verlinken auf den Termin auf dieser Seite (?wpcap_occurrence=…, Anker %s). Leer: die veröffentlichte Seite mit dem Event-Shortcode bzw. -Block wird automatisch verwendet; ohne eine solche Seite öffnet der Kalender den Termin auf der Kalenderseite selbst.', 'pass-captain' ), '#wpcap-occ-{' . _x( 'Termin-ID', 'placeholder name for the numeric occurrence ID in an anchor', 'pass-captain' ) . '}' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Ticket-E-Mail', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="attach_ics" value="1"' . checked( ! empty( $s['attach_ics'] ), true, false ) . '> ' . esc_html__( 'Kalenderdatei (.ics) je Termin anhängen', 'pass-captain' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Speichern', 'pass-captain' ) );
		echo '</form></div>';
	}
}
