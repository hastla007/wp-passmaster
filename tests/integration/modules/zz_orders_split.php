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

// Second step (p13): transactions, payment confirmation and cancellation.
echo "Orders split: Transaction, Payment and Cancellation\n";
$os2_expected = array(
	'begin'                       => array( 'private', true, array() ),
	'in_transaction'              => array( 'private', true, array() ),
	'capacity_released'           => array( 'private', true, array( 'order_id', 'reason', "details=array (\n)" ) ),
	'transaction_alive'           => array( 'public', true, array() ),
	'reset_transaction_tracking'  => array( 'public', true, array() ),
	'reserve_capacity'            => array( 'private', true, array( 'demand', 'context' ) ),
	'confirm_external_payment'    => array( 'public', true, array( 'order_id', "reference=''" ) ),
	'confirm_manual_payment'      => array( 'public', true, array( 'order_id' ) ),
	'late_payment_reason'         => array( 'public', true, array( 'code' ) ),
	'schedule_late_refund'        => array( 'public', true, array( 'order', 'why' ) ),
	'cancel_order'                => array( 'public', true, array( 'order_id', "reason=''", "only_payment_status=array (\n)" ) ),
	'mark_refunded'               => array( 'public', true, array( 'order_id', "reason=''" ) ),
	'cancel_event'                => array( 'public', true, array( 'event_id', "reason=''" ) ),
	'resume_event_cancellation'   => array( 'public', true, array( 'event_id' ) ),
);
$os2_bad = array();
foreach ( $os2_expected as $os_m => $os_want ) {
	if ( ! method_exists( Orders::class, $os_m ) || $os_sig( Orders::class, $os_m ) !== $os_want ) { $os2_bad[] = $os_m; continue; }
	$os_r = new ReflectionMethod( Orders::class, $os_m );
	if ( $os_r->getEndLine() - $os_r->getStartLine() > 3 ) { $os2_bad[] = $os_m . ' (not a thin delegator)'; }
}
check( 'Orders split (2): payment, cancellation and transaction methods stay on the facade with visibility and signature, as thin delegators' . ( $os2_bad ? ': ' . implode( ', ', $os2_bad ) : '' ), ! $os2_bad );

$os2_parts = array(
	Orders\Transaction::class  => array( 'begin', 'capacity_released', 'in_transaction', 'reset_transaction_tracking', 'transaction_alive' ),
	Orders\Payment::class      => array( 'confirm_external_payment', 'confirm_manual_payment', 'late_payment_reason', 'schedule_late_refund' ),
	Orders\Cancellation::class => array( 'cancel_event', 'cancel_order', 'mark_refunded', 'resume_event_cancellation' ),
);
$os2_bad_parts = array();
foreach ( $os2_parts as $os_c => $os_public ) {
	$os_rc = new ReflectionClass( $os_c );
	$os_have = array_map( static fn( $m ) => $m->getName(), $os_rc->getMethods( ReflectionMethod::IS_PUBLIC ) );
	sort( $os_have );
	if ( ! $os_rc->isFinal() || false === strpos( (string) $os_rc->getDocComment(), '@internal' ) || $os_have !== $os_public ) { $os2_bad_parts[] = $os_c . ' (' . implode( ',', $os_have ) . ')'; }
}
check( 'Orders split (2): Transaction, Payment and Cancellation are final, @internal and expose only the delegated methods' . ( $os2_bad_parts ? ': ' . implode( '; ', $os2_bad_parts ) : '' ), ! $os2_bad_parts );
$os2_cap = new ReflectionMethod( Orders\Capacity::class, 'reserve' );
check( 'Orders split (2): the wpcap_reserve_capacity call lives in Orders\\Capacity::reserve() (public static, used by checkout, exchange and late payments)', $os2_cap->isPublic() && $os2_cap->isStatic() );

// One transaction state: the tracking properties moved to Orders\Transaction, and the facade reset clears them.
$os2_orders = new ReflectionClass( Orders::class );
check( 'Orders split (2): Orders keeps no transaction tracking state of its own', ! $os2_orders->hasProperty( 'tracks_transactions' ) && ! $os2_orders->hasProperty( 'marker_ready' ) && ! $os2_orders->hasProperty( 'marker_active' ) );
$os2_prop = new ReflectionProperty( Orders\Transaction::class, 'tracks_transactions' );
$os2_prop->setAccessible( true );
Orders::transaction_alive(); // Detects the server once.
$os2_detected = null !== $os2_prop->getValue();
Orders::reset_transaction_tracking();
check( 'Orders split (2): Orders::reset_transaction_tracking() resets the detection in Orders\\Transaction', $os2_detected && null === $os2_prop->getValue() );

// The private facade transaction helpers reach the same state as the part.
$os2_begin = new ReflectionMethod( Orders::class, 'begin' );
$os2_begin->setAccessible( true );
$os2_in = new ReflectionMethod( Orders::class, 'in_transaction' );
$os2_in->setAccessible( true );
$os2_started = $os2_begin->invoke( null );
$os2_inside = $os2_in->invoke( null );
$os2_inside_part = Orders\Transaction::in_transaction();
$wpdb->query( 'ROLLBACK' );
$os2_after = Orders\Transaction::in_transaction();
check( 'Orders split (2): Orders::begin()/in_transaction() and Orders\\Transaction share one transaction state', false !== $os2_started && true === $os2_inside && true === $os2_inside_part && false === $os2_after && false === Orders::transaction_alive() );
$os2_seen = array();
$os2_hook = static function ( $order_id, $reason ) use ( &$os2_seen ) { $os2_seen[] = array( $order_id, $reason ); };
add_action( 'wpcap_capacity_released', $os2_hook, 10, 2 );
$os2_rel = new ReflectionMethod( Orders::class, 'capacity_released' );
$os2_rel->setAccessible( true );
$os2_rel->invoke( null, 0, 'p13_split_probe' );
remove_action( 'wpcap_capacity_released', $os2_hook, 10 );
check( 'Orders split (2): Orders::capacity_released() still fires wpcap_capacity_released (through Orders\\Transaction)', array( array( 0, 'p13_split_probe' ) ) === $os2_seen );

// Delegation answers as before (no order/event with id 0).
check( 'Orders split (2): late_payment_reason() gives the same texts through the facade and the part', Orders::late_payment_reason( 'buyer_limit' ) === Orders\Payment::late_payment_reason( 'allocation_buyer_limit' ) && Orders::late_payment_reason( 'nope' ) === Orders\Payment::late_payment_reason( '' ) && Orders::late_payment_reason( 'occurrence_ended' ) !== Orders::late_payment_reason( 'nope' ) );
$os2_missing = Orders::confirm_external_payment( 0, 'p13' );
$os2_manual = Orders::confirm_manual_payment( 0 );
check( 'Orders split (2): payment confirmation of a missing order fails as before (wpcap_order_missing / wpcap_order_state)', is_wp_error( $os2_missing ) && 'wpcap_order_missing' === $os2_missing->get_error_code() && is_wp_error( $os2_manual ) && 'wpcap_order_state' === $os2_manual->get_error_code() );
check( 'Orders split (2): schedule_late_refund() queues nothing for an order without amount', false === Orders::schedule_late_refund( (object) array( 'order_id' => 0, 'total_minor' => 0, 'payment_provider' => 'stripe' ), 'p13' ) );
$os2_event = Orders::cancel_event( 0 );
$os2_resume = Orders::resume_event_cancellation( 0 );
check( 'Orders split (2): cancellation of a missing order/event answers as before', true === Orders::cancel_order( 0, 'p13' ) && true === Orders::mark_refunded( 0, 'p13' ) && is_wp_error( $os2_event ) && 'wpcap_event_not_cancellable' === $os2_event->get_error_code() && is_wp_error( $os2_resume ) && 'wpcap_event_not_cancelled' === $os2_resume->get_error_code() );

// The moved bodies are gone from Orders.php.
$os2_src = (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' );
check( 'Orders split (2): no transaction marker, savepoint or event cancellation run left in Orders.php', false === strpos( $os2_src, 'wpcap_trx_marker' ) && false === strpos( $os2_src, 'SAVEPOINT' ) && false === strpos( $os2_src, 'START TRANSACTION' ) && false === strpos( $os2_src, 'BulkRuns::' ) && false === strpos( $os2_src, "apply_filters( 'wpcap_reserve_capacity'" ) );
