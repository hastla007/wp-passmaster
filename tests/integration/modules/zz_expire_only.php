<?php
/**
 * Orders::expire_holds() restricted to given orders: Waitlists::sync_lapsed() expires the orders of
 * its lapsed offers even when more older lapsed orders wait than one batch takes (CI showed an
 * expired offer staying open behind 200+ older lapsed orders). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

echo "expire_holds: restricted run\n";
global $wpdb;
$eo_type = make_type( 50, 10 );
$eo_ids = array();
foreach ( array( 'a', 'b' ) as $eo_k ) {
	$eo_r = Orders::create( array( 'email' => "eo-$eo_k@example.com", 'items' => array( array( 'ticket_type_id' => $eo_type, 'quantity' => 1 ) ), 'idempotency_key' => 'eo-' . $eo_k . uniqid() ) );
	$eo_ids[ $eo_k ] = is_wp_error( $eo_r ) ? 0 : (int) $eo_r['order_id'];
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 120 ), $eo_ids[ $eo_k ] ) );
}
$eo_batch = static function () { return 1; };
add_filter( 'wpcap_expire_holds_batch', $eo_batch );
$eo_expired = Orders::expire_holds( 200, array( $eo_ids['b'] ) );
remove_filter( 'wpcap_expire_holds_batch', $eo_batch );
check( 'restricted to the newer order: exactly it expires, the older lapsed order is left to the regular run (batch size 1)', $eo_ids['a'] && $eo_ids['b'] && 1 === $eo_expired && 'expired' === Orders::get( $eo_ids['b'] )->status && 'pending_payment' === Orders::get( $eo_ids['a'] )->status );
Orders::expire_holds();
check( 'the regular run expires the older one afterwards', 'expired' === Orders::get( $eo_ids['a'] )->status );
$eo_src = (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' );
check( 'Waitlists::sync_lapsed passes the orders of its lapsed offers to expire_holds()', false !== strpos( $eo_src, 'Orders::expire_holds( 200, $lapsed )' ) );
