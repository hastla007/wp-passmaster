<?php
/**
 * A443: the offline package refresh checks its throttle (refreshed_at) again after taking the row
 * lock, so a request that waited behind a parallel refresh does not rebuild the snapshot at once.
 * The parallel refresh is simulated by a second connection right before the lock is taken.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\OfflineScanner\Package;

global $wpdb;
$p = $wpdb->prefix;
echo "A443: offline package throttle re-checked under the lock\n";

// Earlier test files switch the throttle off (interval 0); this test needs the default 10 s.
$x2_a443_interval = $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = 10;
$x2_a443_type = make_type( 5 );
$x2_a443_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x2_a443_type" );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_offline_state (occurrence_id,epoch,version,refreshed_at) VALUES (%d,%s,3,%s)", $x2_a443_occ, 'abcdefabcdef0123', gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );

$x2_a443_other = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$x2_a443_parallel = static function ( $sql ) use ( $x2_a443_other, $p, $x2_a443_occ ) {
	// Table names may be backtick-quoted (prepared with %i).
	if ( preg_match( "/^INSERT IGNORE INTO `?{$p}wpcap_offline_state\\b/", (string) $sql ) ) {
		// Another request finished its refresh just now (version 4).
		$x2_a443_other->query( "UPDATE {$p}wpcap_offline_state SET version=4, refreshed_at='" . gmdate( 'Y-m-d H:i:s' ) . "' WHERE occurrence_id=$x2_a443_occ" );
	}
	return $sql;
};
add_filter( 'query', $x2_a443_parallel );
$GLOBALS['wpcap_test_query_log'] = array();
$x2_a443_state = Package::refresh( $x2_a443_occ, false );
$x2_a443_rebuilt = (bool) array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $q ) => false !== strpos( str_replace( '`', '', $q ), 'FROM ' . $GLOBALS['wpdb']->prefix . 'wpcap_offline_entries WHERE occurrence_id' ) );
unset( $GLOBALS['wpcap_test_query_log'] );
remove_filter( 'query', $x2_a443_parallel );
$x2_a443_other->close();
check( 'A443: after a parallel refresh the waiting request returns the fresh state without rebuilding', ! $x2_a443_rebuilt && 4 === (int) $x2_a443_state->version );

// Forced refreshes and expired throttles still rebuild.
$wpdb->query( "UPDATE {$p}wpcap_offline_state SET refreshed_at='" . gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "' WHERE occurrence_id=$x2_a443_occ" );
$GLOBALS['wpcap_test_query_log'] = array();
Package::refresh( $x2_a443_occ, false );
$x2_a443_rebuilt = (bool) array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $q ) => false !== strpos( str_replace( '`', '', $q ), 'FROM ' . $GLOBALS['wpdb']->prefix . 'wpcap_offline_entries WHERE occurrence_id' ) );
unset( $GLOBALS['wpcap_test_query_log'] );
check( 'A443: an expired snapshot is still rebuilt', $x2_a443_rebuilt );
$wpdb->query( "DELETE FROM {$p}wpcap_offline_state WHERE occurrence_id=$x2_a443_occ" );
$wpdb->query( "DELETE FROM {$p}wpcap_offline_entries WHERE occurrence_id=$x2_a443_occ" );
if ( null === $x2_a443_interval ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = $x2_a443_interval; }
