<?php
/**
 * A440: the scanner app's stats endpoint counts the device's own check-ins over KEY
 * device_event_result and keeps the occurrence-wide counters in the object cache for a few
 * seconds (filter wpcap_scanner_stats_cache_ttl), instead of counting everything on every poll.
 * Included from run.php (uses check(), make_type(), app_call() from mobile.php).
 */
// phpcs:ignoreFile
use PassCaptain\Mobile\Devices;

global $wpdb;
$p = $wpdb->prefix;
echo "A440: scanner app stats – device index and short cache\n";

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { $found = array_key_exists( "$group:$key", $GLOBALS['pf_cache'] ?? array() ); return $found ? $GLOBALS['pf_cache'][ "$group:$key" ] : false; }
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) { $GLOBALS['pf_cache'][ "$group:$key" ] = $value; $GLOBALS['pf_cache_ttl'][ "$group:$key" ] = $ttl; return true; }
}

$x2_a440_cols = array();
foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_checkins WHERE Key_name='device_event_result'" ) as $x2_a440_r ) { $x2_a440_cols[ (int) $x2_a440_r->Seq_in_index ] = $x2_a440_r->Column_name; }
ksort( $x2_a440_cols );
check( 'A440: wpcap_checkins has KEY device_event_result (device_id, event_id, result)', array( 'device_id', 'event_id', 'result' ) === array_values( $x2_a440_cols ) );
$x2_a440_explain = (array) $wpdb->get_row( "EXPLAIN SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE device_id=3 AND event_id=5 AND result='checked_in'", ARRAY_A );
check( 'A440: the device counter can use that index', false !== strpos( (string) ( $x2_a440_explain['possible_keys'] ?? '' ), 'device_event_result' ) );
check( 'A440: the schema version was raised for the new index', version_compare( PassCaptain\Installer::DB_VERSION, '3.10.26', '>=' ) );

$x2_a440_type = make_type( 10, 10, 0 );
$x2_a440_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$x2_a440_type" );
$x2_a440_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x2_a440_type" );
$x2_a440_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 7;
$x2_a440_pairing = Devices::create_pairing( array( $x2_a440_event ), 'A440', 12 );
$GLOBALS['wpcap_test_user_id'] = 0;
$x2_a440_token = Devices::pair( $x2_a440_pairing['code'], 'A440 Gerät', 'ios', '1.0.0' )['token'] ?? '';

$x2_a440_ticket_queries = static function ( callable $run ) {
	$GLOBALS['wpcap_test_query_log'] = array();
	$result = $run();
	$n = count( array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $q ) => false !== strpos( $q, 'GROUP BY t.status' ) ) );
	unset( $GLOBALS['wpcap_test_query_log'] );
	return array( $n, $result );
};
$GLOBALS['pf_cache'] = array();
list( $x2_a440_first ) = $x2_a440_ticket_queries( static fn() => app_call( 'stats', 'GET', $x2_a440_token, array( 'occurrence_id' => $x2_a440_occ ) ) );
list( $x2_a440_second, $x2_a440_stats ) = $x2_a440_ticket_queries( static fn() => app_call( 'stats', 'GET', $x2_a440_token, array( 'occurrence_id' => $x2_a440_occ ) ) );
check( 'A440: a second poll within the TTL reuses the occurrence counters', 1 === $x2_a440_first && 0 === $x2_a440_second && is_array( $x2_a440_stats ) && array_key_exists( 'checked_in_by_this_device', $x2_a440_stats ) && array_key_exists( 'device_area_id', $x2_a440_stats ) && array_key_exists( 'by_area', $x2_a440_stats ) );
check( 'A440: the cache TTL is short (default 10 s)', 10 === ( $GLOBALS['pf_cache_ttl'][ 'wpcap:scanner_stats:' . $p . $x2_a440_occ ] ?? null ) );
$GLOBALS['wpcap_test_filters']['wpcap_scanner_stats_cache_ttl'] = 0;
list( $x2_a440_off ) = $x2_a440_ticket_queries( static fn() => app_call( 'stats', 'GET', $x2_a440_token, array( 'occurrence_id' => $x2_a440_occ ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_scanner_stats_cache_ttl'] );
check( 'A440: TTL 0 counts on every call', 1 === $x2_a440_off );

$GLOBALS['pf_cache'] = array();
if ( null === $x2_a440_user ) { unset( $GLOBALS['wpcap_test_user_id'] ); } else { $GLOBALS['wpcap_test_user_id'] = $x2_a440_user; }
