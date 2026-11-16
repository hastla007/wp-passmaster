<?php
/**
 * A441: the admin ticket search looks every field up on its own index (number/e-mail prefix, name
 * prefix or word start on KEY attendee_name) instead of six LIKE '%x%' over a six-table join.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\Queries;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
echo "A441: indexed ticket search\n";

$x2_a441_type = make_type( 10, 10, 0 );
$x2_a441_mail = 'x2-a441-' . substr( md5( uniqid() ), 0, 6 ) . '@example.org';
$x2_a441_order = Orders::create( array( 'email' => $x2_a441_mail, 'items' => array( array( 'ticket_type_id' => $x2_a441_type, 'quantity' => 2 ) ), 'idempotency_key' => 'x2-a441-' . uniqid() ) );
Orders::confirm_manual_payment( $x2_a441_order['order_id'] );
$x2_a441_tickets = Orders::tickets_for_order( $x2_a441_order['order_id'] );
$x2_a441_tid = (int) $x2_a441_tickets[0]->ticket_id;
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_name=VALUES(attendee_name),attendee_email=VALUES(attendee_email)", $x2_a441_tid, $x2_a441_mail, 'gast-a441@example.net', 'Zorana Quellenbrück-X', gmdate( 'Y-m-d H:i:s' ) ) );

$x2_a441_find = static function ( $search ) {
	$GLOBALS['wpcap_test_query_log'] = array();
	$r = Queries::search_tickets( array( 'search' => $search ), null, 1, 50 );
	$log = $GLOBALS['wpcap_test_query_log'];
	unset( $GLOBALS['wpcap_test_query_log'] );
	return array( array_map( static fn( $t ) => (int) $t->ticket_id, $r['rows'] ), $log, $r );
};

list( $x2_a441_ids, $x2_a441_log ) = $x2_a441_find( 'Quellenbrück' );
check( 'A441: a last name (later word of the name) is still found', array( $x2_a441_tid ) === $x2_a441_ids );
list( $x2_a441_ids ) = $x2_a441_find( 'zorana' );
check( 'A441: the beginning of the name is found (case-insensitive)', array( $x2_a441_tid ) === $x2_a441_ids );
list( $x2_a441_ids ) = $x2_a441_find( substr( $x2_a441_mail, 0, 14 ) );
check( 'A441: the beginning of the buyer e-mail finds all tickets of the order', 2 === count( $x2_a441_ids ) && in_array( $x2_a441_tid, $x2_a441_ids, true ) );
list( $x2_a441_ids ) = $x2_a441_find( 'gast-a441@example.net' );
check( 'A441: the full attendee e-mail finds the ticket', array( $x2_a441_tid ) === $x2_a441_ids );
list( $x2_a441_ids ) = $x2_a441_find( substr( (string) $x2_a441_tickets[1]->public_id, 0, 10 ) );
check( 'A441: a ticket number prefix finds that ticket', array( (int) $x2_a441_tickets[1]->ticket_id ) === $x2_a441_ids );

// No leading-wildcard LIKE over joins: only the name-word lookup on wpcap_ticket_assignments has one.
list( , $x2_a441_log ) = $x2_a441_find( 'example' );
$x2_a441_bad = array_values( array_filter( $x2_a441_log, static function ( $q ) use ( $p ) {
	if ( ! preg_match( "/LIKE '%/", $q ) ) { return false; }
	return ! preg_match( "/^SELECT ticket_id FROM {$p}wpcap_ticket_assignments WHERE attendee_name LIKE '% /", $q );
} ) );
$x2_a441_join = array_values( array_filter( $x2_a441_log, static fn( $q ) => false !== stripos( $q, ' LIKE ' ) && substr_count( strtoupper( $q ), ' JOIN ' ) > 1 ) );
check( 'A441: no LIKE \'%x%\' over the big tables and no LIKE over a multi-table join' . ( $x2_a441_bad ? ' – ' . substr( $x2_a441_bad[0], 0, 160 ) : '' ), array() === $x2_a441_bad && array() === $x2_a441_join );

foreach ( array( 'attendee_name' => 'attendee_name', 'attendee_email' => 'attendee_email' ) as $x2_a441_key => $x2_a441_col ) {
	$x2_a441_cols = array();
	foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_ticket_assignments WHERE Key_name='$x2_a441_key'" ) as $x2_a441_r ) { $x2_a441_cols[ (int) $x2_a441_r->Seq_in_index ] = $x2_a441_r->Column_name; }
	ksort( $x2_a441_cols );
	check( "A441: wpcap_ticket_assignments has KEY $x2_a441_key ($x2_a441_col, ticket_id)", array( $x2_a441_col, 'ticket_id' ) === array_values( $x2_a441_cols ) );
}
$x2_a441_explain = (array) $wpdb->get_row( "EXPLAIN SELECT ticket_id FROM {$p}wpcap_ticket_assignments WHERE attendee_name LIKE '% quel%' LIMIT 1001", ARRAY_A );
check( 'A441: the word lookup reads only the name index (covering)', 'attendee_name' === ( $x2_a441_explain['key'] ?? '' ) && false !== stripos( (string) ( $x2_a441_explain['Extra'] ?? '' ), 'using index' ) );

// A too broad search says so instead of scanning on.
$x2_a441_cap = new ReflectionClassConstant( Queries::class, 'TICKET_SEARCH_MAX' );
check( 'A441: matches per field are capped', $x2_a441_cap->getValue() >= 100 );
