<?php
/**
 * Full-page caches and CDNs (A446, A283, A508).
 *
 * Purge: every change that alters what a public page shows (event, occurrence, ticket type, price,
 * sales window, legal texts, sold out / available again) queues the event; at the end of the
 * request the action `wpcap_purge_page_cache( $event_id, $urls, $context )` fires once per event
 * (event 0 = every page with a listing). The URLs are the permalinks of the posts that show the
 * event: posts with the events shortcode/block for that event or one of its occurrences, plus every
 * post with the full listing or a calendar. The index of these posts is kept in an option and
 * maintained on `save_post` (has_shortcode()/has_block()). Adapters for WP Rocket, LiteSpeed Cache,
 * W3 Total Cache, WP Super Cache, Cloudflare (official plugin, APO) and a generic Varnish PURGE
 * (off by default, through OutboundHttp) listen to the action, each only when its plugin is active.
 * Sales start and sales end are scheduled as single cron events that purge the event's pages.
 *
 * No store: personal capability pages (ticket, order portal, waitlist offer/unsubscribe, payment
 * return, invoice download, web scanner) call no_store() before any output, also in their 404
 * branches.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PageCache {
	/** Central purge action: ( int $event_id, string[] $urls, array $context ). */
	const ACTION = 'wpcap_purge_page_cache';
	/** Option holding the index of posts that show events (not autoloaded). */
	const INDEX_OPTION = 'wpcap_page_cache_index';
	const INDEX_VERSION = 1;
	/** Single cron event at a sales start/end: args ( event_id, unix timestamp ). */
	const SALES_HOOK = 'wpcap_page_cache_sales_window';
	/** Upcoming sales-window purges scheduled per event (the next ones follow when one fires). */
	const SALES_LOOKAHEAD = 50;

	const EVENT_SHORTCODES = array( 'wp_pass_captain_events', 'wpcap_events' );
	const CALENDAR_SHORTCODES = array( 'wp_pass_captain_calendar', 'wpcap_calendar' );
	const SCANNER_SHORTCODES = array( 'wp_pass_captain_scanner', 'wpcap_scanner' );
	const EVENT_BLOCK = 'wp-pass-captain/events';
	const CALENDAR_BLOCK = 'wp-pass-captain/calendar';

	/** Query parameters of personal pages that must never be stored by a page cache. */
	const NO_STORE_PARAMS = array(
		'wpcap_ticket' => 'ticket',
		'wpcap_order' => 'portal',
		'wpcap_waitlist_accept' => 'waitlist_offer',
		'wpcap_waitlist_unsubscribe' => 'waitlist_unsubscribe',
		'wpcap_invoice' => 'invoice',
		'wpcap_invoice_pdf' => 'invoice_pdf',
		'wpcap_payment' => 'payment_return',
		'wpcap_paypal_return' => 'payment_return',
		'wpcap_mollie_return' => 'payment_return',
	);

	/** Queued purges: blog id => event id => [reasons => [..], occurrences => [..]]. */
	private static $queue = array();
	private static $shutdown_hooked = false;
	/** Index read in this request (null = not loaded). */
	private static $index = null;

	public static function register() {
		add_action( 'save_post', array( __CLASS__, 'index_post' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'forget_post' ), 10, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_store_request' ), 0 );
		add_action( 'wpcap_occurrence_changed', array( __CLASS__, 'occurrence_changed' ), 30, 1 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_changed' ), 30, 1 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_changed' ), 30, 1 );
		add_action( 'wpcap_capacity_increased', array( __CLASS__, 'capacity_increased' ), 30, 2 );
		add_action( self::SALES_HOOK, array( __CLASS__, 'sales_window_reached' ), 10, 2 );
		add_action( self::ACTION, array( __CLASS__, 'purge_adapters' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * No store (A283, A508).
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Marks the current response as personal: DONOTCACHEPAGE for page-cache plugins (WP Rocket,
	 * LiteSpeed, W3TC, WP Super Cache), nocache_headers() for proxies and CDNs (Cloudflare, Varnish)
	 * and LiteSpeed's own no-cache control. Call it before any output.
	 *
	 * @param string $context Page kind (ticket, portal, waitlist_offer, …).
	 */
	public static function no_store( $context = '' ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) { nocache_headers(); }
		do_action( 'litespeed_control_set_nocache', 'pass-captain ' . (string) $context );
		/**
		 * A personal page of the plugin is being sent and must not be cached (A283).
		 *
		 * @param string $context ticket, portal, waitlist_offer, waitlist_unsubscribe, waitlist_result,
		 *                        payment_return, invoice_pdf, scanner.
		 */
		do_action( 'wpcap_page_no_store', (string) $context );
	}

	/**
	 * Context of a front-end request that must not be cached ('' when it may be cached): one of the
	 * capability query parameters, or a post containing the web scanner shortcode.
	 *
	 * @param array  $query        Query parameters ($_GET).
	 * @param string $post_content Content of the queried singular post ('' otherwise).
	 */
	public static function no_store_context( array $query, $post_content = '' ) {
		foreach ( self::NO_STORE_PARAMS as $param => $context ) {
			if ( isset( $query[ $param ] ) && '' !== Http::str( $query[ $param ] ) ) { return $context; }
		}
		return self::has_scanner( (string) $post_content ) ? 'scanner' : '';
	}

	/** template_redirect (priority 0): no_store() for capability links and scanner pages, before any output. */
	public static function maybe_no_store_request() {
		$content = '';
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post = get_queried_object();
			$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
		}
		$context = self::no_store_context( wp_unslash( $_GET ), $content ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks whether parameters are present.
		if ( '' !== $context ) { self::no_store( $context ); }
	}

	/** Whether content contains the web scanner shortcode. */
	public static function has_scanner( $content ) {
		foreach ( self::SCANNER_SHORTCODES as $tag ) {
			if ( self::contains_shortcode( $content, $tag ) ) { return true; }
		}
		return false;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Index of posts that show events.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * What a post shows: null when it has no events shortcode/block, otherwise
	 * all (full listing or calendar), events and occurrences (ids named in attributes).
	 *
	 * @return array|null
	 */
	public static function analyse( $content ) {
		$content = (string) $content;
		$all = false;
		$events = array();
		$occurrences = array();
		$found = false;
		foreach ( array_merge( self::EVENT_SHORTCODES, self::CALENDAR_SHORTCODES ) as $tag ) {
			if ( ! self::contains_shortcode( $content, $tag ) ) { continue; }
			if ( ! preg_match_all( '/\[' . preg_quote( $tag, '/' ) . '(\s[^\]]*)?\]/', $content, $matches ) ) { continue; }
			foreach ( $matches[1] as $raw ) {
				$found = true;
				$atts = self::shortcode_atts_of( (string) $raw );
				$event = absint( $atts['event'] ?? 0 );
				$occurrence = in_array( $tag, self::EVENT_SHORTCODES, true ) ? absint( $atts['occurrence'] ?? 0 ) : 0;
				self::collect( $event, $occurrence, $all, $events, $occurrences );
			}
		}
		foreach ( array( self::EVENT_BLOCK => array( 'event', 'occurrence' ), self::CALENDAR_BLOCK => array( 'eventId', '' ) ) as $block => $keys ) {
			if ( ! self::contains_block( $content, $block ) ) { continue; }
			if ( ! preg_match_all( '/<!--\s+wp:' . preg_quote( $block, '/' ) . '(\s+\{.*?\})?\s+\/?-->/s', $content, $matches ) ) { continue; }
			foreach ( $matches[1] as $raw ) {
				$found = true;
				$attrs = '' !== trim( (string) $raw ) ? json_decode( trim( (string) $raw ), true ) : array();
				$attrs = is_array( $attrs ) ? $attrs : array();
				self::collect( absint( $attrs[ $keys[0] ] ?? 0 ), '' !== $keys[1] ? absint( $attrs[ $keys[1] ] ?? 0 ) : 0, $all, $events, $occurrences );
			}
		}
		if ( ! $found ) { return null; }
		return array( 'all' => $all ? 1 : 0, 'events' => array_values( array_unique( $events ) ), 'occurrences' => array_values( array_unique( $occurrences ) ) );
	}

	private static function collect( $event, $occurrence, &$all, array &$events, array &$occurrences ) {
		if ( $event ) { $events[] = $event; }
		if ( $occurrence ) { $occurrences[] = $occurrence; }
		if ( ! $event && ! $occurrence ) { $all = true; }
	}

	private static function contains_shortcode( $content, $tag ) {
		if ( function_exists( 'has_shortcode' ) && function_exists( 'shortcode_exists' ) && shortcode_exists( $tag ) ) {
			return has_shortcode( $content, $tag );
		}
		return false !== strpos( $content, '[' . $tag );
	}

	private static function contains_block( $content, $block ) {
		return function_exists( 'has_block' ) ? has_block( $block, $content ) : false !== strpos( $content, '<!-- wp:' . $block );
	}

	private static function shortcode_atts_of( $raw ) {
		if ( function_exists( 'shortcode_parse_atts' ) ) {
			$atts = shortcode_parse_atts( $raw );
			return is_array( $atts ) ? $atts : array();
		}
		$atts = array();
		if ( preg_match_all( '/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) { $atts[ strtolower( $pair[1] ) ] = $pair[2] . ( $pair[3] ?? '' ) . ( $pair[4] ?? '' ); }
		}
		return $atts;
	}

	/** The index: [ 'v' => version, 'posts' => post id => analyse() result ]. Built once when missing. */
	public static function index() {
		if ( null !== self::$index ) { return self::$index; }
		$stored = get_option( self::INDEX_OPTION, null );
		if ( ! is_array( $stored ) || self::INDEX_VERSION !== (int) ( $stored['v'] ?? 0 ) || ! is_array( $stored['posts'] ?? null ) ) {
			$stored = self::rebuild_index();
		}
		self::$index = $stored;
		return $stored;
	}

	/** Scans all published posts once (first use, version change). */
	public static function rebuild_index() {
		global $wpdb;
		$index = array( 'v' => self::INDEX_VERSION, 'posts' => array() );
		if ( ! empty( $wpdb->posts ) ) {
			$like = array();
			foreach ( array_merge( self::EVENT_SHORTCODES, self::CALENDAR_SHORTCODES ) as $tag ) { $like[] = '%[' . $wpdb->esc_like( $tag ) . '%'; }
			foreach ( array( self::EVENT_BLOCK, self::CALENDAR_BLOCK ) as $block ) { $like[] = '%wp:' . $wpdb->esc_like( $block ) . '%'; }
			$where = implode( ' OR ', array_fill( 0, count( $like ), 'post_content LIKE %s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_type,post_content FROM {$wpdb->posts} WHERE post_status='publish' AND ($where)", ...$like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
			foreach ( (array) $rows as $row ) {
				$entry = self::viewable( (string) $row->post_type ) ? self::analyse( (string) $row->post_content ) : null;
				if ( $entry ) { $index['posts'][ (int) $row->ID ] = $entry; }
			}
		}
		update_option( self::INDEX_OPTION, $index, false );
		self::$index = $index;
		return $index;
	}

	private static function viewable( $post_type ) {
		if ( in_array( $post_type, array( 'revision', 'nav_menu_item', 'customize_changeset' ), true ) ) { return false; }
		return function_exists( 'is_post_type_viewable' ) ? (bool) is_post_type_viewable( $post_type ) : true;
	}

	/** save_post: (re)indexes a post; drafts, private and trashed posts leave the index. */
	public static function index_post( $post_id, $post = null ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) { return; }
		if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) || ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) ) { return; }
		if ( ! is_object( $post ) && function_exists( 'get_post' ) ) { $post = get_post( $post_id ); }
		$entry = is_object( $post ) && 'publish' === ( $post->post_status ?? '' ) && self::viewable( (string) ( $post->post_type ?? 'post' ) ) ? self::analyse( (string) ( $post->post_content ?? '' ) ) : null;
		$index = self::index();
		$before = $index['posts'][ $post_id ] ?? null;
		if ( $entry ) { $index['posts'][ $post_id ] = $entry; } else { unset( $index['posts'][ $post_id ] ); }
		if ( $before !== $entry ) { self::save_index( $index ); }
	}

	/** deleted_post: removes a post from the index. */
	public static function forget_post( $post_id ) {
		$index = self::index();
		if ( ! isset( $index['posts'][ absint( $post_id ) ] ) ) { return; }
		unset( $index['posts'][ absint( $post_id ) ] );
		self::save_index( $index );
	}

	private static function save_index( array $index ) {
		update_option( self::INDEX_OPTION, $index, false );
		self::$index = $index;
	}

	/** Forgets the in-request copy of the index (tests, after switch_to_blog()). */
	public static function reset() {
		self::$index = null;
		self::$queue = array();
	}

	/**
	 * Post ids and URLs showing an event (0: every indexed post).
	 *
	 * @return array [ post_ids => int[], urls => string[] ]
	 */
	public static function targets( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$occurrences = $event_id ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d", $event_id ) ) ) : array();
		$post_ids = array();
		foreach ( self::index()['posts'] as $post_id => $entry ) {
			if ( ! $event_id || ! empty( $entry['all'] ) || in_array( $event_id, (array) ( $entry['events'] ?? array() ), true ) || array_intersect( $occurrences, (array) ( $entry['occurrences'] ?? array() ) ) ) {
				$post_ids[] = (int) $post_id;
			}
		}
		sort( $post_ids, SORT_NUMERIC );
		$urls = array();
		foreach ( $post_ids as $post_id ) {
			$url = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
			if ( is_string( $url ) && '' !== $url ) { $urls[] = $url; }
		}
		/**
		 * URLs to purge for an event (0 = every page with a listing), e.g. archive or landing pages
		 * that embed the listing through a template or pattern the index does not see.
		 *
		 * @param string[] $urls     Permalinks of the indexed posts.
		 * @param int      $event_id Event id (0 = all).
		 * @param int[]    $post_ids Indexed posts.
		 */
		$urls = (array) apply_filters( 'wpcap_page_cache_urls', $urls, $event_id, $post_ids );
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ), static function ( $url ) { return (bool) preg_match( '~^https?://~i', $url ); } ) ) );
		return array( 'post_ids' => $post_ids, 'urls' => $urls );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Change detection and the purge queue.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Queues a purge of the pages showing an event; sent at the end of the request (flush()).
	 *
	 * @param int    $event_id      Event (0 = every page with a listing, e.g. legal texts).
	 * @param string $reason        event, occurrence, ticket_type, legal, availability, sales_window, …
	 * @param int    $occurrence_id Affected occurrence, if any.
	 */
	public static function event_changed( $event_id, $reason = 'event', $occurrence_id = 0 ) {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$event_id = absint( $event_id );
		self::$queue[ $blog ][ $event_id ]['reasons'][ sanitize_key( (string) $reason ) ] = true;
		if ( $occurrence_id ) { self::$queue[ $blog ][ $event_id ]['occurrences'][ absint( $occurrence_id ) ] = true; }
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5, 0 );
		}
	}

	/** Every page with a listing (legal texts, site-wide settings). */
	public static function all_changed( $reason ) {
		self::event_changed( 0, $reason );
	}

	/** Occurrence created, changed, rescheduled or cancelled. */
	public static function occurrence_changed( $occurrence_id, $reason = 'occurrence' ) {
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		$event_id = $occurrence_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) ) : 0;
		if ( $event_id ) { self::event_changed( $event_id, is_string( $reason ) && '' !== $reason ? $reason : 'occurrence', $occurrence_id ); }
	}

	/** Ticket type created or changed (price, sales window, status, capacity). */
	public static function ticket_type_changed( $ticket_type_id, $reason = 'ticket_type' ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT o.event_id,o.occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", absint( $ticket_type_id ) ) );
		if ( $row ) { self::event_changed( (int) $row->event_id, $reason, (int) $row->occurrence_id ); }
	}

	/** `wpcap_capacity_increased`: places freed or added – a sold-out type may be available again. */
	public static function capacity_increased( $ticket_type_id = 0, $occurrence_id = 0 ) {
		if ( $ticket_type_id ) {
			self::ticket_type_changed( $ticket_type_id, 'availability' );
		} elseif ( $occurrence_id ) {
			self::occurrence_changed( $occurrence_id, 'availability' );
		}
	}

	/**
	 * Called by CapacityCounters::add() after counter deltas were applied: a ticket type or an
	 * occurrence that became sold out or available again changes the listing.
	 *
	 * @param array $deltas ticket_type_id => {sold, held}.
	 * @param array $occ_of ticket_type_id => occurrence_id.
	 */
	public static function note_capacity( array $deltas, array $occ_of ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array();
		$occ_delta = array();
		foreach ( $deltas as $type_id => $delta ) {
			$units = (int) ( $delta['sold'] ?? 0 ) + (int) ( $delta['held'] ?? 0 );
			if ( ! $units ) { continue; }
			$ids[ (int) $type_id ] = $units;
			$occ_id = (int) ( $occ_of[ $type_id ] ?? 0 );
			if ( $occ_id ) { $occ_delta[ $occ_id ] = ( $occ_delta[ $occ_id ] ?? 0 ) + $units; }
		}
		if ( ! $ids ) { return; }
		$rows = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.capacity,tt.sold_qty,tt.held_qty,o.occurrence_id,o.event_id,o.capacity AS occ_capacity,o.sold_qty AS occ_sold,o.held_qty AS occ_held FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id IN (" . implode( ',', array_map( 'intval', array_keys( $ids ) ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$seen_occ = array();
		foreach ( (array) $rows as $row ) {
			$crossed = (int) $row->capacity > 0 && self::crossed( (int) $row->capacity - (int) $row->sold_qty - (int) $row->held_qty, $ids[ (int) $row->ticket_type_id ] );
			$occ_id = (int) $row->occurrence_id;
			if ( ! $crossed && ! isset( $seen_occ[ $occ_id ] ) && (int) $row->occ_capacity > 0 && isset( $occ_delta[ $occ_id ] ) ) {
				$crossed = self::crossed( (int) $row->occ_capacity - (int) $row->occ_sold - (int) $row->occ_held, $occ_delta[ $occ_id ] );
			}
			$seen_occ[ $occ_id ] = true;
			if ( $crossed ) { self::event_changed( (int) $row->event_id, 'availability', $occ_id ); }
		}
	}

	/** Whether the remaining units crossed zero (sold out ↔ available) by taking $units. */
	private static function crossed( $remaining_after, $units ) {
		return ( $remaining_after + $units > 0 ) !== ( $remaining_after > 0 );
	}

	/** Settings::update(): legal texts or their requirement changed → every listing page. */
	public static function settings_changed( array $old, array $new ) {
		foreach ( array( 'terms_text', 'consumer_info_text', 'require_terms', 'legal_texts' ) as $key ) {
			if ( array_key_exists( $key, $new ) && ( $old[ $key ] ?? null ) != $new[ $key ] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 0 vs '0' after form input.
				self::all_changed( 'legal' );
				return;
			}
		}
	}

	/** Cron at a sales start/end: purge the event's pages (and schedule the next windows). */
	public static function sales_window_reached( $event_id, $timestamp = 0 ) {
		self::event_changed( absint( $event_id ), 'sales_window' );
	}

	/**
	 * Fires `wpcap_purge_page_cache` for every queued event (shutdown). A queued site-wide purge
	 * (event 0) replaces the per-event purges of the same site. Then schedules the event's next
	 * sales-window purges.
	 */
	public static function flush() {
		$queue = self::$queue;
		self::$queue = array();
		$current = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		foreach ( $queue as $blog => $events ) {
			$switched = $blog !== $current && function_exists( 'switch_to_blog' );
			if ( $switched ) { switch_to_blog( $blog ); self::$index = null; }
			if ( isset( $events[0] ) ) {
				$all = $events[0];
				unset( $events[0] );
				self::fire( 0, $all, array_keys( $events ) );
			} else {
				foreach ( $events as $event_id => $info ) { self::fire( (int) $event_id, $info, array() ); }
			}
			foreach ( array_keys( $events ) as $event_id ) { self::schedule_sales_windows( (int) $event_id ); }
			if ( $switched ) { restore_current_blog(); self::$index = null; }
		}
	}

	private static function fire( $event_id, array $info, array $events ) {
		$targets = self::targets( $event_id );
		$context = array(
			'post_ids' => $targets['post_ids'],
			'reasons' => array_keys( (array) ( $info['reasons'] ?? array() ) ),
			'occurrence_ids' => array_keys( (array) ( $info['occurrences'] ?? array() ) ),
			'events' => array_map( 'intval', $events ),
		);
		/**
		 * Pages showing an event must be purged from page caches and CDNs (A446). Fired once per
		 * event and request, after the change was saved.
		 *
		 * @param int      $event_id Event id; 0 = every page with a listing (e.g. legal texts changed).
		 * @param string[] $urls     Permalinks of the posts showing the event (filter `wpcap_page_cache_urls`).
		 * @param array    $context  post_ids, reasons, occurrence_ids, events (further events covered by a 0 purge).
		 */
		do_action( self::ACTION, (int) $event_id, $targets['urls'], $context );
	}

	/**
	 * Schedules single cron purges at the upcoming sales starts and sales ends of an event's
	 * ticket types (the first SALES_LOOKAHEAD distinct times; each run schedules the next ones).
	 * A purge at sales end runs one second later, when the listing no longer shows the type.
	 *
	 * @return int[] Scheduled timestamps.
	 */
	public static function schedule_sales_windows( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! $event_id || ! function_exists( 'wp_schedule_single_event' ) ) { return array(); }
		$p = $wpdb->prefix;
		$now = gmdate( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.sales_start,tt.sales_end FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND o.status='scheduled' AND tt.status='active' AND (tt.sales_start>%s OR tt.sales_end>=%s)", $event_id, $now, $now ) );
		$times = array();
		foreach ( (array) $rows as $row ) {
			foreach ( array( 'sales_start' => 0, 'sales_end' => 1 ) as $field => $offset ) {
				$value = (string) ( $row->$field ?? '' );
				$ts = '' !== $value ? strtotime( $value . ' UTC' ) + $offset : 0;
				if ( $ts > time() ) { $times[ $ts ] = true; }
			}
		}
		$times = array_keys( $times );
		sort( $times, SORT_NUMERIC );
		$times = array_slice( $times, 0, self::SALES_LOOKAHEAD );
		foreach ( $times as $ts ) {
			$args = array( $event_id, (int) $ts );
			if ( ! wp_next_scheduled( self::SALES_HOOK, $args ) ) { wp_schedule_single_event( (int) $ts, self::SALES_HOOK, $args ); }
		}
		return $times;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Adapters (each only when its plugin is active).
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * `wpcap_purge_page_cache` (priority 10): purges the URLs in the active cache plugins.
	 *
	 * @param int      $event_id Event id (0 = all).
	 * @param string[] $urls     URLs.
	 * @param array    $context  post_ids, …
	 * @return string[] Adapters that ran.
	 */
	public static function purge_adapters( $event_id, $urls = array(), $context = array() ) {
		$urls = array_values( array_filter( array_map( 'strval', (array) $urls ) ) );
		$post_ids = array_values( array_filter( array_map( 'absint', (array) ( $context['post_ids'] ?? array() ) ) ) );
		if ( ! $urls && ! $post_ids ) { return array(); }
		/**
		 * Adapters used for the purge (remove one to purge that cache yourself).
		 *
		 * @param string[] $adapters wp_rocket, litespeed, w3tc, wp_super_cache, cloudflare, varnish.
		 * @param int      $event_id Event id.
		 */
		$enabled = (array) apply_filters( 'wpcap_page_cache_adapters', array( 'wp_rocket', 'litespeed', 'w3tc', 'wp_super_cache', 'cloudflare', 'varnish' ), (int) $event_id );
		$ran = array();
		if ( in_array( 'wp_rocket', $enabled, true ) && ( function_exists( 'rocket_clean_post' ) || function_exists( 'rocket_clean_files' ) ) ) {
			if ( function_exists( 'rocket_clean_post' ) ) { foreach ( $post_ids as $post_id ) { rocket_clean_post( $post_id ); } }
			if ( function_exists( 'rocket_clean_files' ) && $urls ) { rocket_clean_files( $urls ); }
			$ran[] = 'wp_rocket';
		}
		if ( in_array( 'litespeed', $enabled, true ) && ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_url' ) ) ) {
			foreach ( $post_ids as $post_id ) { do_action( 'litespeed_purge_post', $post_id ); }
			foreach ( $urls as $url ) { do_action( 'litespeed_purge_url', $url ); }
			$ran[] = 'litespeed';
		}
		if ( in_array( 'w3tc', $enabled, true ) && ( function_exists( 'w3tc_flush_post' ) || function_exists( 'w3tc_flush_url' ) ) ) {
			if ( function_exists( 'w3tc_flush_post' ) ) { foreach ( $post_ids as $post_id ) { w3tc_flush_post( $post_id ); } }
			if ( function_exists( 'w3tc_flush_url' ) ) { foreach ( $urls as $url ) { w3tc_flush_url( $url ); } }
			$ran[] = 'w3tc';
		}
		if ( in_array( 'wp_super_cache', $enabled, true ) && function_exists( 'wpsc_delete_url_cache' ) ) {
			foreach ( $urls as $url ) { wpsc_delete_url_cache( $url ); }
			$ran[] = 'wp_super_cache';
		}
		if ( in_array( 'cloudflare', $enabled, true ) && $post_ids && self::cloudflare_purge( $post_ids, $urls ) ) {
			$ran[] = 'cloudflare';
		}
		if ( in_array( 'varnish', $enabled, true ) && self::varnish_purge( $urls ) ) {
			$ran[] = 'varnish';
		}
		return $ran;
	}

	/**
	 * Cloudflare (official plugin, also APO): Hooks::purgeCacheByRelevantURLs() with our URLs added
	 * through its filter `cloudflare_purge_by_url`. The plugin itself decides whether purging is on.
	 */
	private static function cloudflare_purge( array $post_ids, array $urls ) {
		$class = '\\CF\\WordPress\\Hooks';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'purgeCacheByRelevantURLs' ) ) { return false; }
		$add = static function ( $list ) use ( $urls ) { return array_values( array_unique( array_merge( (array) $list, $urls ) ) ); };
		add_filter( 'cloudflare_purge_by_url', $add, 10, 1 );
		try {
			( new $class() )->purgeCacheByRelevantURLs( $post_ids );
		} catch ( \Throwable $e ) {
			remove_filter( 'cloudflare_purge_by_url', $add, 10 );
			return false;
		}
		remove_filter( 'cloudflare_purge_by_url', $add, 10 );
		return true;
	}

	/**
	 * Generic Varnish/HTTP purge (off by default): one PURGE request per URL through the
	 * OutboundHttp guard. Configure with the filter `wpcap_page_cache_varnish`:
	 * enabled (bool), endpoint (e.g. "http://127.0.0.1:6081": requests go there with the page's
	 * Host header; the endpoint origin is passed to the guard as an explicit exception), method
	 * (PURGE), headers, timeout (s). Each request can be changed with `wpcap_page_cache_varnish_request`.
	 */
	private static function varnish_purge( array $urls ) {
		$config = apply_filters( 'wpcap_page_cache_varnish', array( 'enabled' => false, 'endpoint' => '', 'method' => 'PURGE', 'headers' => array(), 'timeout' => 3 ) );
		if ( ! is_array( $config ) || empty( $config['enabled'] ) || ! $urls ) { return false; }
		$endpoint = trim( (string) ( $config['endpoint'] ?? '' ) );
		$ep = '' !== $endpoint ? wp_parse_url( $endpoint ) : null;
		$origin = '';
		if ( is_array( $ep ) && ! empty( $ep['host'] ) ) {
			$scheme = strtolower( (string) ( $ep['scheme'] ?? 'http' ) );
			$port = (int) ( $ep['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
			$origin = $scheme . '://' . $ep['host'] . ':' . $port;
		}
		foreach ( $urls as $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { continue; }
			$headers = (array) ( $config['headers'] ?? array() );
			$target = $url;
			if ( '' !== $origin ) {
				$target = $origin . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
				$headers['Host'] = $parts['host'];
			}
			$args = array(
				'method' => strtoupper( sanitize_key( (string) ( $config['method'] ?? 'PURGE' ) ) ) ?: 'PURGE',
				'headers' => $headers,
				'timeout' => max( 1, min( 10, (int) ( $config['timeout'] ?? 3 ) ) ),
			);
			/** One purge request (HTTP request args: method, headers, timeout); return an empty array to skip it. */
			$args = apply_filters( 'wpcap_page_cache_varnish_request', $args, $url, $target );
			if ( ! is_array( $args ) || ! $args ) { continue; }
			OutboundHttp::request( $target, $args, array( 'context' => 'page_cache_varnish', 'exceptions' => '' !== $origin ? array( $origin ) : array() ) );
		}
		return true;
	}
}
