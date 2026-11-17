<?php
/**
 * A432: the admin ticket list reads the last check-in only for the tickets of the page (no correlated
 * subquery per ticket) and pages on the tickets' primary key. A433: the check-in log counts and pages
 * on wpcap_checkins alone (KEY created), and its result filter is a fixed list (no DISTINCT scan).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Queries;
use PassCaptain\Installer;

global $wpdb;
$p = $wpdb->prefix;

echo "A432/A433: ticket list and check-in log at scale\n";
$a432_cols = static fn( $table, $key ) => array_map( static fn( $r ) => $r->Column_name, (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_$table WHERE Key_name='$key'" ) );
check( 'schema 3.10.23: checkins KEY ticket_id (ticket_id, result, created_at) and KEY created (created_at, checkin_id)',
	version_compare( Installer::DB_VERSION, '3.10.18', '>=' )
	&& array( 'ticket_id', 'result', 'created_at' ) === $a432_cols( 'checkins', 'ticket_id' )
	&& array( 'created_at', 'checkin_id' ) === $a432_cols( 'checkins', 'created' ) );

// One event with 3,000 tickets of one order and 6,000 check-ins over a year.
$a432_type = make_type( 0 );
$a432_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a432_type" );
$a432_now = time();
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => md5( 'a432' ), 'idempotency_key' => hash( 'sha256', 'a432' ), 'customer_email' => 'a432@example.com', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'EUR', 'price_snapshot' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a432_order = (int) $wpdb->insert_id;
for ( $chunk = 0; $chunk < 3; $chunk++ ) {
	$values = array();
	for ( $i = 0; $i < 1000; $i++ ) {
		$n = $chunk * 1000 + $i;
		$values[] = $wpdb->prepare( '(%s,%d,%d,%s,%s,%s)', md5( 'a432-t' . $n ), $a432_order, $a432_type, hash( 'sha256', 'a432-t' . $n ), 0 === $n % 2 ? 'checked_in' : 'valid', gmdate( 'Y-m-d H:i:s' ) );
	}
	$wpdb->query( "INSERT INTO {$p}wpcap_tickets (public_id,order_id,ticket_type_id,token_hash,status,issued_at) VALUES " . implode( ',', $values ) );
}
$a432_tickets = array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=$a432_order ORDER BY ticket_id" ) );
for ( $chunk = 0; $chunk < 6; $chunk++ ) {
	$values = array();
	for ( $i = 0; $i < 1000; $i++ ) {
		$n = $chunk * 1000 + $i;
		$values[] = $wpdb->prepare( '(%d,%d,1,%s,%s)', $a432_tickets[ $n % 3000 ], $a432_event, 0 === $n % 5 ? 'already_checked_in' : 'checked_in', gmdate( 'Y-m-d H:i:s', $a432_now - 400 * DAY_IN_SECONDS + $n * 5000 ) );
	}
	$wpdb->query( "INSERT INTO {$p}wpcap_checkins (ticket_id,event_id,user_id,result,created_at) VALUES " . implode( ',', $values ) );
}
// The newest ticket: two admissions and a later undo; the one before: only a rejected scan.
$a432_last = end( $a432_tickets );
$a432_prev = $a432_tickets[ count( $a432_tickets ) - 2 ];
$wpdb->query( "DELETE FROM {$p}wpcap_checkins WHERE ticket_id IN ($a432_last,$a432_prev)" );
foreach ( array( array( $a432_last, 'checked_in', -3000 ), array( $a432_last, 'checked_in', -2000 ), array( $a432_last, 'undone', -1000 ), array( $a432_prev, 'already_checked_in', -500 ) ) as $c ) {
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $c[0], 'event_id' => $a432_event, 'user_id' => 1, 'result' => $c[1], 'created_at' => gmdate( 'Y-m-d H:i:s', $a432_now + $c[2] ) ) );
}
$wpdb->query( "ANALYZE TABLE {$p}wpcap_tickets, {$p}wpcap_checkins" );

$a432_log = array();
$a432_spy = static function ( $sql ) use ( &$a432_log ) { $a432_log[] = $sql; return $sql; };
$a432_plans = static function ( array $log, $table ) use ( $wpdb, $p ) {
	$plans = array();
	foreach ( $log as $sql ) {
		if ( ! preg_match( '/^\s*SELECT\b/i', $sql ) || false === strpos( $sql, "{$p}wpcap_$table" ) ) { continue; }
		foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql ) as $row ) {
			$plans[] = array( 'sql' => substr( $sql, 0, 160 ), 'table' => (string) $row->table, 'type' => (string) $row->type, 'key' => (string) $row->key, 'extra' => (string) $row->Extra );
		}
	}
	return $plans;
};

// A432: unfiltered first page (the admin list with "all tickets").
add_filter( 'query', $a432_spy );
$a432_page = Queries::search_tickets( array(), null, 1, 30 );
remove_filter( 'query', $a432_spy );
$a432_checkin_sql = array_values( array_filter( $a432_log, static fn( $sql ) => false !== strpos( $sql, "{$p}wpcap_checkins" ) ) );
check( 'A432: no correlated check-in subquery; one check-in query for the ids of the page', 1 === count( $a432_checkin_sql ) && ! preg_match( '/\(SELECT MAX\(/i', implode( ' ', $a432_log ) ) && preg_match( '/ticket_id IN \(([\d,]+)\)/', $a432_checkin_sql[0], $a432_m ) && 30 >= count( explode( ',', $a432_m[1] ) ) );
check( 'A432: unfiltered count reads only wpcap_tickets', (bool) array_filter( $a432_log, static fn( $sql ) => (bool) preg_match( '/^SELECT COUNT\(\*\) FROM \S+wpcap_tickets t WHERE 1=1$/', trim( $sql ) ) ) );
$a432_t_plans = array_filter( $a432_plans( $a432_log, 'tickets' ), static fn( $pl ) => 't' === $pl['table'] );
$a432_c_plans = $a432_plans( $a432_log, 'checkins' );
if ( array_filter( array_merge( $a432_t_plans, $a432_c_plans ), static fn( $pl ) => 'ALL' === $pl['type'] || false !== strpos( $pl['extra'], 'filesort' ) ) ) { fwrite( STDERR, 'A432 plans: ' . json_encode( array_values( array_merge( $a432_t_plans, $a432_c_plans ) ) ) . "\n" ); }
check( 'A432: page and details without table scan or filesort; last check-ins by index range',
	$a432_t_plans && ! array_filter( $a432_t_plans, static fn( $pl ) => 'ALL' === $pl['type'] || false !== strpos( $pl['extra'], 'filesort' ) )
	&& 1 === count( $a432_c_plans ) && 'range' === $a432_c_plans[0]['type'] && 'ticket_id' === $a432_c_plans[0]['key'] );

$a432_mine = Queries::search_tickets( array( 'event_id' => $a432_event ), null, 1, 30 );
$a432_rows = array_column( $a432_mine['rows'], null, 'ticket_id' );
check( 'A432: total and order unchanged (newest first, event filter)', 3000 === $a432_mine['total'] && 30 === count( $a432_mine['rows'] ) && $a432_last === (int) $a432_mine['rows'][0]->ticket_id && 'Konzert' === $a432_mine['rows'][0]->event_name );
check( 'A432: last check-in is the latest admission (undo and rejected scans do not count)',
	gmdate( 'Y-m-d H:i:s', $a432_now - 2000 ) === $a432_rows[ $a432_last ]->last_checkin_at && null === $a432_rows[ $a432_prev ]->last_checkin_at && null !== $a432_rows[ $a432_tickets[2971] ]->last_checkin_at );
$a432_status = Queries::search_tickets( array( 'event_id' => $a432_event, 'status' => 'checked_in' ), null, 2, 30 );
check( 'A432: status filter and second page', 1500 === $a432_status['total'] && 30 === count( $a432_status['rows'] ) && ! array_filter( $a432_status['rows'], static fn( $r ) => 'checked_in' !== $r->status ) );
check( 'A432: search still finds a ticket by number prefix', 1 === Queries::search_tickets( array( 'search' => substr( md5( 'a432-t7' ), 0, 12 ) ), null, 1, 30 )['total'] );

// A433: check-in log.
$a432_log = array();
$a432_q = $wpdb->num_queries;
$a433_results = Queries::checkin_results();
check( 'A433: result filter values without a query (fixed list)', $wpdb->num_queries === $a432_q && in_array( 'checked_in', $a433_results, true ) && in_array( 'undone', $a433_results, true ) && in_array( 'already_checked_in', $a433_results, true ) );
add_filter( 'query', $a432_spy );
$a433_page = Queries::checkin_log( array(), null, 1, 50 );
remove_filter( 'query', $a432_spy );
check( 'A433: count without joins', (bool) array_filter( $a432_log, static fn( $sql ) => (bool) preg_match( '/^SELECT COUNT\(\*\) FROM \S+wpcap_checkins c WHERE 1=1$/', trim( $sql ) ) ) );
$a433_c_plans = array_filter( $a432_plans( $a432_log, 'checkins' ), static fn( $pl ) => 'c' === $pl['table'] );
if ( array_filter( $a433_c_plans, static fn( $pl ) => 'ALL' === $pl['type'] || false !== strpos( $pl['extra'], 'filesort' ) ) ) { fwrite( STDERR, 'A433 plans: ' . json_encode( array_values( $a433_c_plans ) ) . "\n" ); }
check( 'A433: page by KEY created (newest first) without scan or filesort, details by primary key',
	// Merged schema: one index on (created_at,checkin_id), KEY created (the duplicate created_checkin is dropped in 3.18.0).
	(bool) array_filter( $a433_c_plans, static fn( $pl ) => in_array( $pl['key'], array( 'created', 'created_checkin' ), true ) && false === strpos( $pl['extra'], 'filesort' ) )
	&& ! array_filter( $a433_c_plans, static fn( $pl ) => 'ALL' === $pl['type'] || false !== strpos( $pl['extra'], 'filesort' ) ) );
$a433_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins" );
$a433_newest = $wpdb->get_row( "SELECT checkin_id,created_at FROM {$p}wpcap_checkins ORDER BY created_at DESC, checkin_id DESC LIMIT 1" );
check( 'A433: total and newest entry unchanged, ticket and event names present', $a433_total === $a433_page['total'] && 50 === count( $a433_page['rows'] ) && (int) $a433_newest->checkin_id === (int) $a433_page['rows'][0]->checkin_id && $a433_page['rows'][0]->created_at >= $a433_page['rows'][49]->created_at );
$a433_mine = Queries::checkin_log( array( 'event_id' => $a432_event, 'result' => 'already_checked_in', 'date_from' => gmdate( 'Y-m-d H:i:s', $a432_now - 100 * DAY_IN_SECONDS ) ), null, 1, 50 );
$a433_expect = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE event_id=%d AND result='already_checked_in' AND created_at >= %s", $a432_event, gmdate( 'Y-m-d H:i:s', $a432_now - 100 * DAY_IN_SECONDS ) ) );
check( 'A433: event, result and date filters', $a433_expect > 50 && $a433_expect === $a433_mine['total'] && ! array_filter( $a433_mine['rows'], static fn( $r ) => 'already_checked_in' !== $r->result || (int) $r->event_id !== $a432_event ) && 'Konzert' === $a433_mine['rows'][0]->event_name && null !== $a433_mine['rows'][0]->ticket_public_id );
check( 'A433: empty scope lists nothing', 0 === Queries::checkin_log( array(), array(), 1, 50 )['total'] );

$wpdb->query( "DELETE FROM {$p}wpcap_checkins WHERE event_id=$a432_event" );
$wpdb->query( "DELETE FROM {$p}wpcap_tickets WHERE order_id=$a432_order" );
$wpdb->query( "DELETE FROM {$p}wpcap_orders WHERE order_id=$a432_order" );
