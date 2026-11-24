<?php
// phpcs:ignoreFile
/**
 * Splitting the Orders class (TODO "Gott-Klasse Orders"): availability, attendee handling and the core capacity
 * check live in @internal classes under src/Orders/; Orders keeps thin delegators with the former signatures.
 * Behaviour is covered by the existing order/checkout modules (orders, finance, p3_performance, …); this module
 * only pins the facade shape and the delegation.
 */

use PassCaptain\Orders;

echo "Orders split: facade delegators and @internal parts\n";

$os_sig = static function ( $class, $method ) {
	$r = new ReflectionMethod( $class, $method );
	return array(
		$r->isPublic() ? 'public' : ( $r->isPrivate() ? 'private' : 'protected' ),
		$r->isStatic(),
		array_map( static fn( $p ) => $p->getName() . ( $p->isDefaultValueAvailable() ? '=' . var_export( $p->getDefaultValue(), true ) : '' ), $r->getParameters() ),
	);
};
$os_expected = array(
	'availability'             => array( 'public', true, array( 'ticket_type_id' ) ),
	'availability_many'        => array( 'public', true, array( 'ticket_type_ids', "rows=array (\n)" ) ),
	'flush_availability_cache' => array( 'public', true, array() ),
	'site_switched'            => array( 'public', true, array() ),
	'note_query'               => array( 'public', true, array( 'query' ) ),
	'validate_attendees'       => array( 'public', true, array( 'lines', 'attendees', "options=array (\n)", 'skip_required=false' ) ),
	'attendee_name_mode'       => array( 'public', true, array( 'value' ) ),
	'field_options'            => array( 'public', true, array( 'field' ) ),
	'clean_answer'             => array( 'public', true, array( 'field', 'value' ) ),
	'capacity_error'           => array( 'private', true, array( 'type', 'occurrence', 'quantity', 'exclude_order_id=0', 'occurrence_quantity=NULL' ) ),
	'store_attendee'           => array( 'private', true, array( 'ticket_id', 'order', 'attendee' ) ),
);
$os_bad = array();
foreach ( $os_expected as $os_m => $os_want ) {
	if ( ! method_exists( Orders::class, $os_m ) || $os_sig( Orders::class, $os_m ) !== $os_want ) { $os_bad[] = $os_m; continue; }
	$os_r = new ReflectionMethod( Orders::class, $os_m );
	if ( $os_r->getEndLine() - $os_r->getStartLine() > 3 ) { $os_bad[] = $os_m . ' (not a thin delegator)'; }
}
check( 'Orders split: the facade keeps every moved method with the former visibility and signature, as a thin delegator' . ( $os_bad ? ': ' . implode( ', ', $os_bad ) : '' ), ! $os_bad );

$os_parts = array( Orders\Availability::class, Orders\Attendees::class, Orders\Capacity::class );
$os_bad_parts = array_values( array_filter( $os_parts, static function ( $c ) { $r = new ReflectionClass( $c ); return ! $r->isFinal() || false === strpos( (string) $r->getDocComment(), '@internal' ); } ) );
check( 'Orders split: Availability, Attendees and Capacity are final and @internal', ! $os_bad_parts );

// The query filter keeps its callback identity (listeners and other tests remove/restore Orders::note_query).
Orders::availability( 0 );
$os_query_cbs = array();
foreach ( $GLOBALS['wpcap_test_hooks']['query'] ?? array() as $os_entries ) { foreach ( $os_entries as $os_entry ) { $os_query_cbs[] = $os_entry[0]; } }
check( 'Orders split: the availability memo hooks the query filter with Orders::note_query (unchanged callback)', in_array( array( Orders::class, 'note_query' ), $os_query_cbs, true ) && ! in_array( array( Orders\Availability::class, 'note_query' ), $os_query_cbs, true ) );

// Delegation returns the same values as before (pure helpers, no DB).
check( 'Orders split: attendee_name_mode() and clean_answer() answer as before', 'optional' === Orders::attendee_name_mode( 'x' ) && 'off' === Orders::attendee_name_mode( 'off' ) && null === Orders::clean_answer( (object) array( 'field_type' => 'radio', 'options' => "A\nB" ), 'C' ) && 'B' === Orders::clean_answer( (object) array( 'field_type' => 'radio', 'options' => "A\nB" ), 'B' ) );
check( 'Orders split: field_options() reads both stored formats', array( 'A', 'B' ) === Orders::field_options( (object) array( 'options' => '["A","B","A"]' ) ) && array( 'A', 'B' ) === Orders::field_options( array( 'options' => "A\r\n\nB" ) ) );
