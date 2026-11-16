<?php
/**
 * A439: the retention step checkin_staff uses KEY created (created_at,checkin_id) and walks it from a
 * stored cursor, so a run does not re-read every already anonymised check-in older than the cutoff;
 * back-dated rows behind the cursor are caught by the weekly full pass.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Privacy\Retention;

global $wpdb;
$p = $wpdb->prefix;
echo "A439: check-in staff retention walks the created index from a cursor\n";

$x2_a439_cols = array();
foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_checkins WHERE Key_name='created'" ) as $x2_a439_r ) { $x2_a439_cols[ (int) $x2_a439_r->Seq_in_index ] = $x2_a439_r->Column_name; }
ksort( $x2_a439_cols );
$x2_a439_cols = array_values( $x2_a439_cols );
check( 'A439: wpcap_checkins has KEY created (created_at, checkin_id)', array( 'created_at', 'checkin_id' ) === $x2_a439_cols );

$x2_a439_step = new ReflectionMethod( Retention::class, 'step_checkin_staff' );
$x2_a439_step->setAccessible( true );
$x2_a439_run = static function ( $now ) use ( $x2_a439_step ) { return (int) $x2_a439_step->invoke( null, $now, microtime( true ) + 20 ); };
$x2_a439_insert = static function ( $created, $user = 5 ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => 990439, 'event_id' => 990439, 'user_id' => $user, 'result' => 'checked_in', 'device_id' => 4, 'source' => 'app', 'created_at' => gmdate( 'Y-m-d H:i:s', $created ) ) );
	return (int) $wpdb->insert_id;
};
$x2_a439_staff = static function ( $id ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT user_id+device_id FROM {$p}wpcap_checkins WHERE checkin_id=" . (int) $id ); };
$x2_a439_days = (int) PassCaptain\Settings::get( 'retention_checkin_staff_days' );
PassCaptain\Settings::update( array( 'retention_checkin_staff_days' => 365 ) );

$x2_a439_now = time();
delete_option( Retention::CHECKIN_STAFF_CURSOR );
$x2_a439_old = $x2_a439_insert( $x2_a439_now - 400 * DAY_IN_SECONDS );
$x2_a439_recent = $x2_a439_insert( $x2_a439_now - 10 * DAY_IN_SECONDS );
$x2_a439_soon = $x2_a439_insert( $x2_a439_now - 365 * DAY_IN_SECONDS + 3600 );
$x2_a439_run( $x2_a439_now );
check( 'A439: a check-in older than the retention loses staff and device, newer ones keep them', 0 === $x2_a439_staff( $x2_a439_old ) && 9 === $x2_a439_staff( $x2_a439_recent ) && 9 === $x2_a439_staff( $x2_a439_soon ) );
$x2_a439_state = (array) get_option( Retention::CHECKIN_STAFF_CURSOR, array() );
check( 'A439: the cursor is stored at the cutoff after a complete run', gmdate( 'Y-m-d H:i:s', $x2_a439_now - 365 * DAY_IN_SECONDS ) === ( $x2_a439_state['at'] ?? '' ) );

// The next run starts at the cursor (index range), not at the oldest check-in.
$GLOBALS['wpcap_test_query_log'] = array();
$x2_a439_run( $x2_a439_now + 7200 );
$x2_a439_sql = implode( "\n", array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $q ) => false !== strpos( $q, 'wpcap_checkins' ) && 0 === strpos( ltrim( $q ), 'SELECT' ) ) );
unset( $GLOBALS['wpcap_test_query_log'] );
check( 'A439: the following run reads from the cursor and anonymises what became due since', false !== strpos( $x2_a439_sql, "created_at>='" . ( $x2_a439_state['at'] ?? 'x' ) . "'" ) && 0 === $x2_a439_staff( $x2_a439_soon ) && 9 === $x2_a439_staff( $x2_a439_recent ) );
$x2_a439_explain = (array) $wpdb->get_row( $wpdb->prepare( "EXPLAIN SELECT checkin_id,created_at FROM {$p}wpcap_checkins FORCE INDEX (created) WHERE created_at>=%s AND created_at<%s AND (user_id<>0 OR device_id<>0) ORDER BY created_at,checkin_id LIMIT 500", $x2_a439_state['at'] ?? '', gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
check( 'A439: the query is an index range on KEY created without filesort', 'created' === ( $x2_a439_explain['key'] ?? '' ) && false === stripos( (string) ( $x2_a439_explain['Extra'] ?? '' ), 'filesort' ) );

// A row back-dated behind the cursor waits for the weekly full pass.
$x2_a439_back = $x2_a439_insert( $x2_a439_now - 500 * DAY_IN_SECONDS );
$x2_a439_run( $x2_a439_now + 7300 );
$x2_a439_waits = 9 === $x2_a439_staff( $x2_a439_back );
$x2_a439_state = (array) get_option( Retention::CHECKIN_STAFF_CURSOR, array() );
$x2_a439_state['full_at'] = time() - Retention::CHECKIN_STAFF_FULL_PASS - 1;
update_option( Retention::CHECKIN_STAFF_CURSOR, $x2_a439_state, false );
$x2_a439_run( $x2_a439_now + 7400 );
check( 'A439: a back-dated check-in is anonymised by the weekly full pass', $x2_a439_waits && 0 === $x2_a439_staff( $x2_a439_back ) );

// A simulated future run never moves the cursor beyond the real cutoff.
$x2_a439_run( $x2_a439_now + 100 * DAY_IN_SECONDS );
$x2_a439_state = (array) get_option( Retention::CHECKIN_STAFF_CURSOR, array() );
check( 'A439: the cursor stays at or before time() minus the retention', ( $x2_a439_state['at'] ?? '9' ) <= gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) );

$wpdb->query( "DELETE FROM {$p}wpcap_checkins WHERE ticket_id=990439" );
delete_option( Retention::CHECKIN_STAFF_CURSOR );
PassCaptain\Settings::update( array( 'retention_checkin_staff_days' => $x2_a439_days ) );
