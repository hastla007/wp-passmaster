<?php
/**
 * A634: unpaid bank transfer orders (manual) hold at most `manual_hold_max_percent` of a ticket type and
 * of a date with capacity (at least `wpcap_manual_hold_min_units`), checked atomically in Orders::create().
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a634_percent = Settings::get( 'manual_hold_max_percent' );

function a634_order( $type, $qty, $provider = 'manual' ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'a634-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'a634-' . uniqid() ), array( 'payment_provider' => $provider, 'hold_minutes' => 72 * 60 ) );
}
function a634_reason( $result ) { return is_wp_error( $result ) ? (string) ( $result->get_error_data()['reason'] ?? $result->get_error_code() ) : 'ok'; }

echo "A634: upper bound for units held by unpaid bank transfer orders\n";
check( 'A634: default bound is 50 % with a floor of 10 units', 50 === (int) Settings::get( 'manual_hold_max_percent' ) && 15 === Orders::manual_hold_cap( 30 ) && 10 === Orders::manual_hold_cap( 8 ) && null === Orders::manual_hold_cap( 0 ) );

$t = make_type( 30, 30, 1000 );
$first = a634_order( $t, 15 );
$over = a634_order( $t, 1 );
check( 'A634: unpaid manual orders may hold half of a ticket type, not more', ! is_wp_error( $first ) && 'manual_hold_limit' === a634_reason( $over ) && 409 === ( $over->get_error_data()['status'] ?? 0 ) );
check( 'A634: an immediate payment method is not affected', 'ok' === a634_reason( a634_order( $t, 2, 'stripe' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$a634_staff = Orders::create( array( 'email' => 'a634-staff@example.com', 'items' => array( array( 'ticket_type_id' => $t, 'quantity' => 1 ) ), 'idempotency_key' => 'a634-staff-' . uniqid() ), array( 'source' => 'admin', 'key_namespace' => 'admin', 'skip_rate_limit' => true ) );
check( 'A634: trusted staff channels are not limited', 'ok' === a634_reason( $a634_staff ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $first['order_id'] ) );
check( 'A634: a lapsed hold no longer counts', 'ok' === a634_reason( a634_order( $t, 10 ) ) );
Orders::confirm_manual_payment( $first['order_id'] );

// Date capacity: ticket types without own capacity on a date with 40 places.
$u = make_type( 0, 30, 1000, 40 );
check( 'A634: the bound also applies per date', 'ok' === a634_reason( a634_order( $u, 20 ) ) && 'manual_hold_limit' === a634_reason( a634_order( $u, 1 ) ) );

// Small events keep selling by bank transfer (floor), and 0 switches the bound off.
$v = make_type( 8, 10, 1000 );
check( 'A634: small capacities are not limited below the floor', 'ok' === a634_reason( a634_order( $v, 8 ) ) );
Settings::update( array( 'manual_hold_max_percent' => 0 ) );
$w = make_type( 30, 30, 1000 );
check( 'A634: 0 % switches the bound off', 'ok' === a634_reason( a634_order( $w, 25 ) ) && 'ok' === a634_reason( a634_order( $w, 5 ) ) );
Settings::update( array( 'manual_hold_max_percent' => $a634_percent ) );
check( 'A634: the checkout message names the reason', false !== strpos( (string) $over->get_error_message(), 'Überweisung' ) );
