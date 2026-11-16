<?php
/**
 * A526: prorated refund proposal for a pass after partial use.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Passes\Cancellation;
use PassCaptain\Passes\Definitions;

echo "A526: prorated pass refund\n";
global $wpdb;
$p = $wpdb->prefix;
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Festival A526', 'slug' => 'a526-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$a526_event = (int) $wpdb->insert_id;
$a526_occ = array();
for ( $i = 1; $i <= 4; $i++ ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a526_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $i * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a526_occ[] = (int) $wpdb->insert_id;
}
$a526_pass = Definitions::create( array( 'occurrence_id' => $a526_occ[0], 'name' => 'Festivalpass A526', 'price_minor' => 8000, 'occurrence_ids' => $a526_occ ) );
check( 'A526: pass type created', ! is_wp_error( $a526_pass ) && (int) $a526_pass > 0 );
if ( ! is_wp_error( $a526_pass ) ) {
	$a526_order = PassCaptain\Orders::create( array( 'email' => 'a526-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => (int) $a526_pass, 'quantity' => 1 ) ), 'idempotency_key' => 'a526-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
	$a526_ticket = is_wp_error( $a526_order ) ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", (int) $a526_order['order_id'] ) );
	if ( ! is_wp_error( $a526_order ) && ! $a526_ticket ) {
		PassCaptain\Orders::confirm_external_payment( (int) $a526_order['order_id'], 'a526' );
		$a526_ticket = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", (int) $a526_order['order_id'] ) );
	}
	check( 'A526: fixture ticket', $a526_ticket > 0 );
	$a526_none = Cancellation::prorated( $a526_ticket, 8000 );
	check( 'A526: unused pass – full value proposed', $a526_none && 4 === $a526_none['total'] && 0 === $a526_none['used'] && 8000 === $a526_none['suggested_minor'] );
	$wpdb->insert( "{$p}wpcap_admission_entries", array( 'ticket_id' => $a526_ticket, 'occurrence_id' => $a526_occ[0], 'area_id' => 0, 'service_date' => gmdate( 'Y-m-d' ), 'direction' => 'in', 'undone' => 0, 'entered_at' => $now, 'created_at' => $now ) );
	$a526_one = Cancellation::prorated( $a526_ticket, 8000 );
	check( 'A526: one of four dates used – three quarters proposed', $a526_one && 1 === $a526_one['used'] && 6000 === $a526_one['suggested_minor'] );
}
$a526_plain = make_type( 10, 10, 1000 );
$a526_order2 = PassCaptain\Orders::create( array( 'email' => 'a526b-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a526_plain, 'quantity' => 1 ) ), 'idempotency_key' => 'a526b-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
if ( ! is_wp_error( $a526_order2 ) ) { PassCaptain\Orders::confirm_external_payment( (int) $a526_order2['order_id'], 'a526b' ); }
$a526_t2 = is_wp_error( $a526_order2 ) ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", (int) $a526_order2['order_id'] ) );
check( 'A526: ordinary tickets get no proposal', null === Cancellation::prorated( $a526_t2, 1000 ) );
check( 'A526: the refund form shows the proposal', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' ), "\$preview['prorated']" ) );
