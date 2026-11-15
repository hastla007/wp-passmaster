<?php
/**
 * Event detail page with a shareable URL: /{base}/{slug}/ (base from the setting event_base, default
 * "events"), rendered inside the theme with templates/event-single.php (overridable as
 * wp-pass-captain/event-single.php in the theme). The page carries title, canonical URL, Open Graph
 * tags and schema.org/Event JSON-LD (one Event per upcoming date with offers, availability,
 * eventStatus, image and category) for rich results and social previews.
 *
 * Rewrite rules are flushed only when needed: on activation and when the base or the switch changes
 * (option wpcap_rewrite_flush, handled on the next init) – never on every request.
 *
 * Ticket selection: the page renders the events shortcode with the event attribute
 * ([wp_pass_captain_events event="ID"]) when the shortcode accepts attributes, otherwise the event
 * cards of this event directly (PublicEvents::render_cards()).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Frontend;

use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EventPage {
	const QUERY_VAR = 'wpcap_event';
	const FLUSH_OPTION = 'wpcap_rewrite_flush';
	/** Dates in the JSON-LD at most. */
	const MAX_DATES = 20;

	/** @var object|null Event of the current request. */
	private static $event = null;

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_rules' ), 20, 0 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 5, 0 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 50 );
	}

	/** Whether event pages are switched on. */
	public static function enabled() {
		return (bool) Settings::get( 'event_pages' );
	}

	/** URL base, sanitised (letters, digits, "-"; default "events"). */
	public static function base() {
		$base = self::sanitize_base( (string) Settings::get( 'event_base' ) );
		return '' === $base ? 'events' : $base;
	}

	/** Settings sanitiser for event_base; schedules a rewrite flush when it changed. */
	public static function sanitize_base( $value, $current = null ) {
		$base = trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $value ) : (string) $value ) ), '-' );
		$base = substr( $base, 0, 40 );
		if ( null !== $current && $base !== (string) $current ) { self::schedule_flush(); }
		return $base;
	}

	/** Settings sanitiser for the event_pages switch. */
	public static function sanitize_switch( $value, $current = null ) {
		$value = empty( $value ) ? 0 : 1;
		if ( null !== $current && (int) $current !== $value ) { self::schedule_flush(); }
		return $value;
	}

	/** Rules are flushed once on the next init (activation, base or switch changed). */
	public static function schedule_flush() {
		update_option( self::FLUSH_OPTION, 1, false );
	}

	public static function add_rules() {
		if ( self::enabled() ) {
			add_rewrite_rule( '^' . preg_quote( self::base(), '/' ) . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Public URL of an event ('' when event pages are off). Plain permalinks use ?wpcap_event=slug. */
	public static function url( $slug ) {
		if ( ! self::enabled() || '' === (string) $slug ) { return ''; }
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, rawurlencode( (string) $slug ), home_url( '/' ) );
		}
		return home_url( '/' . self::base() . '/' . rawurlencode( (string) $slug ) . '/' );
	}

	/** Published event by slug. */
	public static function event_by_slug( $slug ) {
		global $wpdb;
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE slug=%s AND status IN ('published','cancelled')", $wpdb->prefix . 'wpcap_events', $slug ) );
	}

	/** template_redirect: load the event or answer 404; head output for SEO/social. */
	public static function resolve() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( '' === (string) $slug || ! self::enabled() ) { return; }
		self::$event = self::event_by_slug( $slug );
		if ( ! self::$event ) {
			global $wp_query;
			if ( $wp_query ) { $wp_query->set_404(); }
			status_header( 404 );
			return;
		}
		status_header( 200 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		remove_action( 'wp_head', 'rel_canonical' );
	}

	public static function document_title( $title ) {
		return self::$event ? wp_strip_all_tags( (string) self::$event->name ) . ' – ' . get_bloginfo( 'name' ) : $title;
	}

	/** Canonical, Open Graph and JSON-LD. */
	public static function head() {
		$event = self::$event;
		if ( ! $event ) { return; }
		$url = self::url( $event->slug );
		$description = self::excerpt( (string) $event->description );
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( wp_strip_all_tags( (string) $event->name ) ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		if ( ! empty( $event->image_url ) ) { echo '<meta property="og:image" content="' . esc_url( (string) $event->image_url ) . '">' . "\n"; }
		$data = self::json_ld( $event );
		if ( $data ) {
			// JSON_HEX_TAG keeps "</script>" in event texts from closing the element.
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
		}
	}

	private static function excerpt( $html ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		return mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 197 ) . '…' : $text;
	}

	/** ISO 8601 with the occurrence's offset. */
	private static function iso( $utc, $timezone ) {
		try {
			$zone = new \DateTimeZone( $timezone ? (string) $timezone : 'UTC' );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		return ( new \DateTimeImmutable( $utc . ' UTC' ) )->setTimezone( $zone )->format( 'c' );
	}

	/**
	 * schema.org/Event objects for the upcoming (or cancelled) dates of an event: name, dates, status,
	 * place, image, category (keywords), offers with lowest public price, availability and sales start.
	 * Hidden and code-only ticket types are left out.
	 *
	 * @return array[] List of Event objects ([] without dates).
	 */
	public static function json_ld( $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrences = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id=%d AND starts_at>=%s AND status IN ('scheduled','cancelled') ORDER BY starts_at LIMIT %d", $p . 'wpcap_occurrences', (int) $event->event_id, current_time( 'mysql', true ), self::MAX_DATES ) );
		if ( ! $occurrences ) { return array(); }
		$occ_ids = array_map( static function ( $o ) { return (int) $o->occurrence_id; }, $occurrences );
		$types = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status='active' AND occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_ticket_types' ), $occ_ids ) ) ) as $type ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
			if ( 'public' !== \PassCaptain\Sales\Visibility::of( $type ) ) { continue; }
			$types[ (int) $type->occurrence_id ][] = $type;
		}
		$url = self::url( $event->slug );
		$organizer = (string) Settings::get( 'seller_name' );
		$out = array();
		foreach ( $occurrences as $occ ) {
			$item = array(
				'@context' => 'https://schema.org',
				'@type' => 'Event',
				'name' => wp_strip_all_tags( (string) $event->name ),
				'startDate' => self::iso( $occ->starts_at, $occ->timezone ),
				'eventStatus' => 'cancelled' === $occ->status || 'cancelled' === $event->status ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
				'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			);
			if ( $occ->ends_at ) { $item['endDate'] = self::iso( $occ->ends_at, $occ->timezone ); }
			$description = self::excerpt( (string) $event->description );
			if ( '' !== $description ) { $item['description'] = $description; }
			if ( '' !== (string) $occ->location ) { $item['location'] = array( '@type' => 'Place', 'name' => (string) $occ->location, 'address' => (string) $occ->location ); }
			if ( ! empty( $event->image_url ) ) { $item['image'] = array( (string) $event->image_url ); }
			if ( ! empty( $event->category ) ) { $item['keywords'] = (string) $event->category; }
			if ( '' !== $url ) { $item['url'] = $url; }
			if ( '' !== $organizer ) { $item['organizer'] = array( '@type' => 'Organization', 'name' => $organizer, 'url' => home_url( '/' ) ); }
			$offers = array();
			foreach ( $types[ (int) $occ->occurrence_id ] ?? array() as $type ) {
				$available = Orders::availability( (int) $type->ticket_type_id );
				$offer = array(
					'@type' => 'Offer',
					'name' => (string) $type->name,
					'price' => \PassCaptain\Localization::money_decimal( Orders::unit_price( $type, $occ, 1 ), (string) $type->currency ),
					'priceCurrency' => (string) $type->currency,
					'availability' => 'scheduled' !== $occ->status ? 'https://schema.org/Discontinued' : ( null !== $available && $available < 1 ? 'https://schema.org/SoldOut' : ( $type->sales_start && $type->sales_start > current_time( 'mysql', true ) ? 'https://schema.org/PreOrder' : 'https://schema.org/InStock' ) ),
				);
				if ( $type->sales_start ) { $offer['validFrom'] = self::iso( $type->sales_start, $occ->timezone ); }
				if ( '' !== $url ) { $offer['url'] = $url; }
				$offers[] = $offer;
			}
			if ( $offers ) { $item['offers'] = $offers; }
			$out[] = $item;
		}
		return $out;
	}

	/** template_include: our template inside the theme for event requests. */
	public static function template( $template ) {
		if ( ! self::$event ) { return $template; }
		$theme = locate_template( 'wp-pass-captain/event-single.php' );
		return $theme ? $theme : WPCAP_PATH . 'templates/event-single.php';
	}

	/** Event of the current request (templates). */
	public static function current() {
		return self::$event;
	}

	/** Ticket area of an event: shortcode with event attribute if supported, else this event's cards. */
	public static function tickets_html( $event ) {
		$accepts_atts = ( new \ReflectionMethod( PublicEvents::class, 'render_shortcode' ) )->getNumberOfParameters() > 0;
		if ( $accepts_atts ) {
			return do_shortcode( '[wp_pass_captain_events event="' . (int) $event->event_id . '"]' );
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location FROM %i e INNER JOIN %i o ON o.event_id=e.event_id WHERE e.event_id=%d AND e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 50", $wpdb->prefix . 'wpcap_events', $wpdb->prefix . 'wpcap_occurrences',
			(int) $event->event_id,
			current_time( 'mysql', true )
		) );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		return PublicEvents::render_cards( $rows );
	}
}
