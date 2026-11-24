<?php
// phpcs:ignoreFile
/**
 * Splitting the Orders class, next step (TODO "Gott-Klasse Orders"): bank transfer holds and hold deadlines
 * live in the @internal class Orders\Holds; Orders keeps thin delegators with the former signatures. The
 * behaviour stays covered by zz_manual_holds, zz_a634, zz_audit3 (A002) and the delayed payment tests (A577);
 * this module pins the facade shape, the delegation and the hold deadline helpers on the database.
 */

use PassCaptain\Clock;
use PassCaptain\Orders;
use PassCaptain\Settings;

echo "Orders split: Orders\\Holds (bank transfer holds, hold deadlines)\n";

$p5h_sig = static function ( $class, $method ) {
	$r = new ReflectionMethod( $class, $method );
	return array(
		$r->isPublic() ? 'public' : ( $r->isPrivate() ? 'private' : 'protected' ),
		$r->isStatic(),
		array_map( static fn( $p ) => $p->getName() . ( $p->isDefaultValueAvailable() ? '=' . var_export( $p->getDefaultValue(), true ) : '' ), $r->getParameters() ),
	);
};
$p5h_expected = array(
	'manual_hold_minutes'             => array( 'public', true, array( 'type_ids' ) ),
	'manual_hold_cap'                 => array( 'public', true, array( 'capacity' ) ),
	'manual_hold_error'               => array( 'private', true, array( 'lines', 'types', 'occurrences', 'now' ) ),
	'manual_event_hold_cap'           => array( 'public', true, array() ),
	'manual_held_for_event'           => array( 'public', true, array( 'event_id', 'now=NULL', 'occurrence_id=0' ) ),
	'manual_holds_exhausted'          => array( 'public', true, array( 'event_id', 'occurrence_id' ) ),
	'manual_hold_until'               => array( 'public', true, array( 'start_ts', 'minutes=NULL' ) ),
	'extend_hold'                     => array( 'public', true, array( 'order_id', 'minutes' ) ),
	'extend_hold_to'                  => array( 'public', true, array( 'order_id', 'until_ts' ) ),
	'hold_end'                        => array( 'public', true, array( 'order_id' ) ),
	'extend_hold_for_delayed_payment' => array( 'public', true, array( 'order_id' ) ),
);
$p5h_bad = array();
foreach ( $p5h_expected as $p5h_m => $p5h_want ) {
	if ( ! method_exists( Orders::class, $p5h_m ) || $p5h_sig( Orders::class, $p5h_m ) !== $p5h_want ) { $p5h_bad[] = $p5h_m; continue; }
	$p5h_r = new ReflectionMethod( Orders::class, $p5h_m );
	if ( $p5h_r->getEndLine() - $p5h_r->getStartLine() > 3 || false === strpos( implode( '', array_slice( file( $p5h_r->getFileName() ), $p5h_r->getStartLine() - 1, $p5h_r->getEndLine() - $p5h_r->getStartLine() + 1 ) ), 'Orders\\Holds::' ) ) { $p5h_bad[] = $p5h_m . ' (not a thin delegator to Orders\\Holds)'; }
}
check( 'Orders\\Holds: the facade keeps every moved method with the former visibility and signature, as a thin delegator' . ( $p5h_bad ? ': ' . implode( ', ', $p5h_bad ) : '' ), ! $p5h_bad );

$p5h_class = new ReflectionClass( Orders\Holds::class );
check( 'Orders\\Holds: final and @internal', $p5h_class->isFinal() && false !== strpos( (string) $p5h_class->getDocComment(), '@internal' ) );
$p5h_src = (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' );
check( 'Orders\\Holds: the moved bodies are gone from Orders.php (no reservation SQL for manual holds left there)', false === strpos( $p5h_src, "o.payment_provider='manual' AND o.payment_status='pending' AND" ) && false === strpos( $p5h_src, 'SET expires_at=GREATEST(expires_at,%s)' ) );

// Pure helpers answer as before.
$p5h_saved = array();
foreach ( array( 'manual_hold_hours', 'manual_cutoff_hours', 'manual_hold_max_percent', 'manual_hold_max_event_tickets' ) as $p5h_key ) { $p5h_saved[ $p5h_key ] = Settings::get( $p5h_key ); }
Settings::update( array( 'manual_hold_hours' => 72, 'manual_cutoff_hours' => 24, 'manual_hold_max_percent' => 50, 'manual_hold_max_event_tickets' => 7 ) );
check( 'Orders\\Holds: manual_hold_until() caps at the start and refuses within the cutoff', null === Orders::manual_hold_until( Clock::now() + 3600 ) && 72 * 60 === Orders::manual_hold_until( Clock::now() + 30 * 86400 ) && 72 * 60 === Orders::manual_hold_until( 0 ) && 30 === Orders::manual_hold_until( Clock::now() + 30 * 86400, 30 ) );
check( 'Orders\\Holds: manual_hold_cap() and manual_event_hold_cap() read the settings', 15 === Orders::manual_hold_cap( 30 ) && 10 === Orders::manual_hold_cap( 8 ) && null === Orders::manual_hold_cap( 0 ) && 7 === Orders::manual_event_hold_cap() );
check( 'Orders\\Holds: manual_hold_minutes() without ticket types is the configured hold', 72 * 60 === Orders::manual_hold_minutes( array() ) && 72 * 60 === Orders::manual_hold_minutes( array( 0, -3 ) ) );

// Hold deadlines on the database.
global $wpdb;
Settings::update( array( 'manual_hold_max_percent' => 0, 'manual_hold_max_event_tickets' => 0 ) );
wpcap_test_reset_transients();
$p5h_type = make_type( 0, 10, 1000 );
$p5h_order = Orders::create( array( 'email' => 'p5h-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $p5h_type, 'quantity' => 2 ) ), 'idempotency_key' => 'p5h-' . uniqid() ), array( 'payment_provider' => 'manual', 'hold_minutes' => 60 ) );
$p5h_id = is_array( $p5h_order ) ? (int) $p5h_order['order_id'] : 0;
$p5h_end = $p5h_id ? Orders::hold_end( $p5h_id ) : 0;
check( 'Orders\\Holds: hold_end() is the end of the new hold (about 60 minutes)', $p5h_id > 0 && abs( $p5h_end - ( Clock::now() + 3600 ) ) <= 120 );
Orders::extend_hold_to( $p5h_id, Clock::now() + 600 );
check( 'Orders\\Holds: extend_hold_to() never shortens a hold', Orders::hold_end( $p5h_id ) === $p5h_end );
Orders::extend_hold( $p5h_id, 180 );
check( 'Orders\\Holds: extend_hold() extends the active holds', abs( Orders::hold_end( $p5h_id ) - ( Clock::now() + 180 * 60 ) ) <= 120 );
$p5h_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d", $p5h_type ) );
check( 'Orders\\Holds: manual_held_for_event() counts the unpaid bank transfer tickets of the event', 2 === Orders::manual_held_for_event( $p5h_event ) && ! Orders::manual_holds_exhausted( $p5h_event, 0 ) );
$p5h_until = Orders::extend_hold_for_delayed_payment( $p5h_id );
check( 'Orders\\Holds: extend_hold_for_delayed_payment() extends to the bank transfer hold (72 h, the date is 7 days ahead)', is_int( $p5h_until ) && abs( $p5h_until - ( Clock::now() + 72 * 3600 ) ) <= 120 && abs( Orders::hold_end( $p5h_id ) - $p5h_until ) <= 1 );
check( 'Orders\\Holds: hold_end() is 0 for an order without holds', 0 === Orders::hold_end( 0 ) );
Orders::cancel_order( $p5h_id, 'test' );
Settings::update( $p5h_saved );
