<?php
/**
 * A446: pages showing an event are purged from page caches when the event changes.
 *
 * - Index of posts with the events/calendar shortcode or block (save_post, has_shortcode/has_block):
 *   event/occurrence attributes, full listings and calendars; drafts and deleted posts leave it.
 * - `wpcap_purge_page_cache( $event_id, $urls, $context )` once per event at the end of the request
 *   after event, occurrence and ticket type (price, sales window) changes, legal texts (event 0),
 *   sold out / available again – not for sales that leave the availability state unchanged.
 * - Single cron purges at sales start and sales end.
 * - Adapters (WP Rocket, LiteSpeed, W3TC, WP Super Cache, Cloudflare plugin, Varnish via the
 *   OutboundHttp guard) only run when their plugin is active; Varnish is off by default.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\PageCache;
use PassCaptain\Orders;
use PassCaptain\Admin\EventsPage;

if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id = 0 ) { return 'https://example.test/?page_id=' . (int) $id; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ); return 1; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ?? false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] = (int) $ts; return true; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['wpcap_test_http_requests'][] = array( 'method' => strtoupper( $args['method'] ?? 'GET' ), 'url' => $url, 'headers' => $args['headers'] ?? array(), 'args' => $args );
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		return false !== $pre ? $pre : new WP_Error( 'http_request_failed', 'no stub' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? (string) ( $r['headers'][ $h ] ?? '' ) : ''; } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A446: page cache purge on event changes\n";

// Registered once for the whole run (zz_a283_a508.php may have done it already).
$wpa446_registered = false;
foreach ( $GLOBALS['wpcap_test_hooks'][ PageCache::ACTION ] ?? array() as $wpa446_cbs ) {
	foreach ( $wpa446_cbs as $wpa446_cb ) { if ( array( PageCache::class, 'purge_adapters' ) === $wpa446_cb[0] ) { $wpa446_registered = true; } }
}
if ( ! $wpa446_registered ) { PageCache::register(); }
check( 'A446 the plugin registers the page cache integration and unschedules its cron on deactivation', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), 'PageCache::register();' ) && in_array( PageCache::SALES_HOOK, PassCaptain\Plugin::cron_hooks(), true ) );

/** Event with one occurrence and one ticket type; returns [event, occurrence, type]. */
function wpa446_event( $capacity = 10, array $type = array() ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'WPA446 Konzert', 'slug' => 'wpa446-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", $type + array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( $event, $occ, (int) $wpdb->insert_id );
}
function wpa446_post( $id, $content, $status = 'publish' ) {
	return (object) array( 'ID' => $id, 'post_status' => $status, 'post_type' => 'page', 'post_content' => $content );
}

list( $e1, $o1, $t1 ) = wpa446_event( 2 );
list( $e2, $o2, $t2 ) = wpa446_event( 10 );
list( $e3, $o3, $t3 ) = wpa446_event( 10 );
list( $e4, $o4, $t4 ) = wpa446_event( 10 );

// ---- Index ------------------------------------------------------------------------------------
update_option( PageCache::INDEX_OPTION, array( 'v' => PageCache::INDEX_VERSION, 'posts' => array() ) );
PageCache::reset();
$wpa446_posts = array(
	9101 => "<p>Tickets</p>[wpcap_events event=\"$e1\"]",
	9102 => "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->\n<!-- wp:wp-pass-captain/events {\"occurrence\":$o2} /-->",
	9103 => '[wp_pass_captain_events limit="10"]',
	9104 => '<!-- wp:group --><div><!-- wp:wp-pass-captain/calendar {"view":"list"} /--></div><!-- /wp:group -->',
	9105 => "[wpcap_calendar view='list' event='$e2']",
	9106 => "[wpcap_events event=\"$e1\"]",
	9107 => '<p>Impressum</p>',
);
foreach ( $wpa446_posts as $id => $content ) {
	do_action( 'save_post', $id, wpa446_post( $id, $content, 9106 === $id ? 'draft' : 'publish' ), true );
}
$wpa446_index = get_option( PageCache::INDEX_OPTION )['posts'];
check( 'A446 index: shortcode/block posts with their attributes, drafts and unrelated posts left out ' . json_encode( $wpa446_index ),
	array( 9101, 9102, 9103, 9104, 9105 ) === array_keys( $wpa446_index )
	&& array( 'all' => 0, 'events' => array( $e1 ), 'occurrences' => array() ) === $wpa446_index[9101]
	&& array( 'all' => 0, 'events' => array(), 'occurrences' => array( $o2 ) ) === $wpa446_index[9102]
	&& 1 === $wpa446_index[9103]['all'] && 1 === $wpa446_index[9104]['all']
	&& array( 'all' => 0, 'events' => array( $e2 ), 'occurrences' => array() ) === $wpa446_index[9105] );
do_action( 'save_post', 9101, wpa446_post( 9101, $wpa446_posts[9101], 'draft' ), true );
$wpa446_unpublished = ! isset( get_option( PageCache::INDEX_OPTION )['posts'][9101] );
do_action( 'save_post', 9101, wpa446_post( 9101, $wpa446_posts[9101] ), true );
do_action( 'save_post', 9107, wpa446_post( 9107, '[wpcap_events]' ), true );
$wpa446_added = isset( get_option( PageCache::INDEX_OPTION )['posts'][9107] );
do_action( 'deleted_post', 9107 );
check( 'A446 index follows unpublishing, new shortcodes and deletion', $wpa446_unpublished && $wpa446_added && ! isset( get_option( PageCache::INDEX_OPTION )['posts'][9107] ) && isset( get_option( PageCache::INDEX_OPTION )['posts'][9101] ) );

$url = static function ( $id ) { return 'https://example.test/?page_id=' . $id; };
$t = PageCache::targets( $e1 );
check( 'A446 targets of an event: its pages plus all full listings and calendars', array( 9101, 9103, 9104 ) === $t['post_ids'] && array( $url( 9101 ), $url( 9103 ), $url( 9104 ) ) === $t['urls'] );
$t = PageCache::targets( $e2 );
check( 'A446 targets resolve occurrence attributes and event calendars', array( 9102, 9103, 9104, 9105 ) === $t['post_ids'] );

// ---- Purge on change -------------------------------------------------------------------------
$wpa446_fired = array();
$wpa446_rec = static function ( $event_id, $urls, $context ) use ( &$wpa446_fired ) { $wpa446_fired[] = array( 'event' => (int) $event_id, 'urls' => $urls, 'context' => $context ); };
add_action( PageCache::ACTION, $wpa446_rec, 99, 3 );
$wpa446_flush = static function () use ( &$wpa446_fired ) { $wpa446_fired = array(); PageCache::flush(); return $wpa446_fired; };
PageCache::reset();
$wpa446_flush();

$in = array( 'ticket_type_id' => $t1, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '12,50', 'tax_rate' => '20', 'max_per_order' => 10, 'status' => 'active', 'capacity' => 2 );
$saved = EventsPage::save_ticket_type( $in );
$saved2 = EventsPage::save_ticket_type( $in + array() );
$fired = $wpa446_flush();
check( 'A446 price change: one purge per event and request with the event pages ' . json_encode( $fired ),
	! is_wp_error( $saved ) && ! is_wp_error( $saved2 ) && 1 === count( $fired ) && $e1 === $fired[0]['event'] && array( $url( 9101 ), $url( 9103 ), $url( 9104 ) ) === $fired[0]['urls']
	&& in_array( 'ticket_type', $fired[0]['context']['reasons'], true ) && array( 9101, 9103, 9104 ) === $fired[0]['context']['post_ids'] );
check( 'A446 the queue is empty after the flush', array() === $wpa446_flush() );

$wpa446_start = gmdate( 'Y-m-d H:i', time() + 5 * 86400 );
$wpa446_end = gmdate( 'Y-m-d H:i', time() + 20 * 86400 );
$saved = EventsPage::save_ticket_type( array( 'ticket_type_id' => $t2, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '10', 'tax_rate' => '20', 'max_per_order' => 10, 'status' => 'active', 'capacity' => 10, 'sales_start' => $wpa446_start, 'sales_end' => $wpa446_end ) );
$fired = $wpa446_flush();
$wpa446_type2 = $wpdb->get_row( "SELECT sales_start,sales_end FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t2" );
$ts_start = strtotime( $wpa446_type2->sales_start . ' UTC' );
$ts_end = strtotime( $wpa446_type2->sales_end . ' UTC' ) + 1;
check( 'A446 sales window change purges the event pages (occurrence attribute included)', ! is_wp_error( $saved ) && 1 === count( $fired ) && $e2 === $fired[0]['event'] && in_array( $url( 9102 ), $fired[0]['urls'], true ) && in_array( $url( 9105 ), $fired[0]['urls'], true ) && ! in_array( $url( 9101 ), $fired[0]['urls'], true ) );
check( 'A446 single cron purges are scheduled at sales start and one second after sales end',
	$ts_start === wp_next_scheduled( PageCache::SALES_HOOK, array( $e2, $ts_start ) ) && $ts_end === wp_next_scheduled( PageCache::SALES_HOOK, array( $e2, $ts_end ) ) );
do_action( PageCache::SALES_HOOK, $e2, $ts_start );
$fired = $wpa446_flush();
check( 'A446 the sales-start cron purges the event pages', 1 === count( $fired ) && $e2 === $fired[0]['event'] && array( 'sales_window' ) === $fired[0]['context']['reasons'] );

$occ_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=$o3" );
$local = ( new DateTimeImmutable( $occ_row->starts_at . ' UTC' ) )->setTimezone( new DateTimeZone( $occ_row->timezone ) )->modify( '+1 hour' )->format( 'Y-m-d H:i' );
$saved = EventsPage::save_occurrence( array( 'occurrence_id' => $o3, 'timezone' => $occ_row->timezone, 'starts_at' => $local, 'capacity' => 0, 'status' => 'scheduled', 'location' => 'Graz' ) );
$updated = EventsPage::update_event( array( 'event_id' => $e3, 'name' => 'WPA446 Neuer Name', 'status' => 'published' ) );
$fired = $wpa446_flush();
check( 'A446 occurrence and event changes in one request: one purge for the event ' . json_encode( $fired ), ! is_wp_error( $saved ) && ! is_wp_error( $updated ) && 1 === count( $fired ) && $e3 === $fired[0]['event'] && in_array( 'occurrence', $fired[0]['context']['reasons'], true ) && in_array( 'event', $fired[0]['context']['reasons'], true ) && in_array( $o3, $fired[0]['context']['occurrence_ids'], true ) );

do_action( 'wpcap_occurrence_cancelled', $o3, 0 );
$fired = $wpa446_flush();
check( 'A446 a cancelled occurrence purges its event', 1 === count( $fired ) && $e3 === $fired[0]['event'] );

// Legal texts: every listing page (event 0); unrelated settings do not purge.
PassCaptain\Settings::update( array( 'attendee_edit_hours' => (int) PassCaptain\Settings::get( 'attendee_edit_hours' ) ) );
$fired_none = $wpa446_flush();
PassCaptain\Settings::update( array( 'terms_text' => 'WPA446 AGB ' . uniqid() ) );
$fired = $wpa446_flush();
check( 'A446 changed legal texts purge every page with a listing (event 0); other settings do not ' . json_encode( array( $fired_none, $fired ) ), array() === $fired_none && 1 === count( $fired ) && 0 === $fired[0]['event'] && array( 'legal' ) === $fired[0]['context']['reasons'] && array( 9101, 9102, 9103, 9104, 9105 ) === $fired[0]['context']['post_ids'] );
PassCaptain\Settings::update( array( 'terms_text' => '' ) );
$wpa446_flush();

// Availability: sold out and available again (capacity 2), but not every sale.
$r1 = Orders::create( array( 'email' => 'wpa446-a@example.com', 'items' => array( array( 'ticket_type_id' => $t4, 'quantity' => 1 ) ), 'idempotency_key' => 'wpa446-a-' . uniqid() ) );
$fired_sale = $wpa446_flush();
$r2 = Orders::create( array( 'email' => 'wpa446-b@example.com', 'items' => array( array( 'ticket_type_id' => $t1, 'quantity' => 2 ) ), 'idempotency_key' => 'wpa446-b-' . uniqid() ) );
$fired_sold_out = $wpa446_flush();
$cancel = Orders::cancel_order( is_array( $r2 ) ? $r2['order_id'] : 0, 'wpa446' );
$fired_again = $wpa446_flush();
check( 'A446 a sale that leaves tickets available does not purge ' . json_encode( $fired_sale ), is_array( $r1 ) && array() === $fired_sale );
check( 'A446 sold out purges the event pages', is_array( $r2 ) && 1 === count( $fired_sold_out ) && $e1 === $fired_sold_out[0]['event'] && in_array( 'availability', $fired_sold_out[0]['context']['reasons'], true ) );
check( 'A446 available again purges the event pages', ! is_wp_error( $cancel ) && 1 === count( $fired_again ) && $e1 === $fired_again[0]['event'] && in_array( 'availability', $fired_again[0]['context']['reasons'], true ) );
remove_action( PageCache::ACTION, $wpa446_rec, 99 );

// ---- Adapters --------------------------------------------------------------------------------
$wpa446_urls = array( $url( 9101 ), $url( 9103 ) );
$wpa446_ctx = array( 'post_ids' => array( 9101, 9103 ) );
$ran_none = PageCache::purge_adapters( $e1, $wpa446_urls, $wpa446_ctx );
$wpa446_http = count( $GLOBALS['wpcap_test_http_requests'] ?? array() );
check( 'A446 no adapter runs without its plugin, Varnish is off by default', array() === $ran_none && $wpa446_http === count( $GLOBALS['wpcap_test_http_requests'] ?? array() ) );

$GLOBALS['wpa446_calls'] = array();
if ( ! function_exists( 'rocket_clean_post' ) ) { function rocket_clean_post( $id ) { $GLOBALS['wpa446_calls'][] = array( 'rocket_post', $id ); } }
if ( ! function_exists( 'rocket_clean_files' ) ) { function rocket_clean_files( $urls ) { $GLOBALS['wpa446_calls'][] = array( 'rocket_files', $urls ); } }
if ( ! function_exists( 'w3tc_flush_post' ) ) { function w3tc_flush_post( $id ) { $GLOBALS['wpa446_calls'][] = array( 'w3tc_post', $id ); } }
if ( ! function_exists( 'w3tc_flush_url' ) ) { function w3tc_flush_url( $u ) { $GLOBALS['wpa446_calls'][] = array( 'w3tc_url', $u ); } }
if ( ! function_exists( 'wpsc_delete_url_cache' ) ) { function wpsc_delete_url_cache( $u ) { $GLOBALS['wpa446_calls'][] = array( 'wpsc', $u ); } }
if ( ! class_exists( 'CF\\WordPress\\Hooks' ) ) {
	eval( 'namespace CF\\WordPress; class Hooks { public function purgeCacheByRelevantURLs( $ids ) { $ids = (array) $ids; $GLOBALS["wpa446_calls"][] = array( "cloudflare", $ids, apply_filters( "cloudflare_purge_by_url", array( "https://example.test/" ), $ids[0] ) ); } }' );
}
$wpa446_ls_post = static function ( $id ) { $GLOBALS['wpa446_calls'][] = array( 'ls_post', $id ); };
$wpa446_ls_url = static function ( $u ) { $GLOBALS['wpa446_calls'][] = array( 'ls_url', $u ); };
add_action( 'litespeed_purge_post', $wpa446_ls_post, 10, 1 );
add_action( 'litespeed_purge_url', $wpa446_ls_url, 10, 1 );
$ran = PageCache::purge_adapters( $e1, $wpa446_urls, $wpa446_ctx );
$calls = $GLOBALS['wpa446_calls'];
check( 'A446 adapters: WP Rocket, LiteSpeed, W3TC, WP Super Cache, Cloudflare ' . json_encode( $ran ), array( 'wp_rocket', 'litespeed', 'w3tc', 'wp_super_cache', 'cloudflare' ) === $ran );
check( 'A446 WP Rocket cleans the posts and the URLs', in_array( array( 'rocket_post', 9101 ), $calls, true ) && in_array( array( 'rocket_post', 9103 ), $calls, true ) && in_array( array( 'rocket_files', $wpa446_urls ), $calls, true ) );
check( 'A446 LiteSpeed purges posts and URLs', in_array( array( 'ls_post', 9101 ), $calls, true ) && in_array( array( 'ls_url', $url( 9103 ) ), $calls, true ) );
check( 'A446 W3TC flushes posts and URLs; WP Super Cache deletes the URLs', in_array( array( 'w3tc_post', 9103 ), $calls, true ) && in_array( array( 'w3tc_url', $url( 9101 ) ), $calls, true ) && in_array( array( 'wpsc', $url( 9101 ) ), $calls, true ) && in_array( array( 'wpsc', $url( 9103 ) ), $calls, true ) );
$cf = array_values( array_filter( $calls, static function ( $c ) { return 'cloudflare' === $c[0]; } ) );
check( 'A446 Cloudflare plugin purges the posts with our URLs added to its list', 1 === count( $cf ) && array( 9101, 9103 ) === $cf[0][1] && array( 'https://example.test/', $url( 9101 ), $url( 9103 ) ) === $cf[0][2] && ! wpa446_has_cb( 'cloudflare_purge_by_url' ) );
remove_action( 'litespeed_purge_post', $wpa446_ls_post, 10 );
remove_action( 'litespeed_purge_url', $wpa446_ls_url, 10 );

$GLOBALS['wpcap_test_filters']['wpcap_page_cache_adapters'] = array( 'varnish' );
$wpa446_varnish = static function ( $c ) { return array( 'enabled' => true, 'endpoint' => 'http://127.0.0.1:6081' ) + $c; };
add_filter( 'wpcap_page_cache_varnish', $wpa446_varnish );
$wpa446_pre = static function ( $pre, $args, $u ) { return 0 === strpos( $u, 'http://127.0.0.1:6081/' ) ? array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) : $pre; };
add_filter( 'pre_http_request', $wpa446_pre, 10, 3 );
$before = count( $GLOBALS['wpcap_test_http_requests'] ?? array() );
$ran = PageCache::purge_adapters( $e1, array( 'https://shop.example.com/tickets/?x=1' ), array( 'post_ids' => array( 9101 ) ) );
$reqs = array_slice( $GLOBALS['wpcap_test_http_requests'] ?? array(), $before );
check( 'A446 Varnish (opt-in): PURGE to the configured endpoint with the page Host header through the guard ' . json_encode( $reqs ),
	array( 'varnish' ) === $ran && 1 === count( $reqs ) && 'PURGE' === $reqs[0]['method'] && 'http://127.0.0.1:6081/tickets/?x=1' === $reqs[0]['url'] && 'shop.example.com' === ( $reqs[0]['headers']['Host'] ?? '' ) && 0 === ( $reqs[0]['args']['redirection'] ?? null ) );
remove_filter( 'wpcap_page_cache_varnish', $wpa446_varnish );
remove_filter( 'pre_http_request', $wpa446_pre, 10 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_page_cache_adapters'] );

/** Whether any callback is registered for a hook (the stub has_filter ignores the callback). */
function wpa446_has_cb( $hook ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() as $cbs ) { if ( $cbs ) { return true; } }
	return false;
}
PageCache::reset();
