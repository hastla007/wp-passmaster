<?php
/**
 * R7 (audit round 2, expert #12): UTM/referrer attribution on orders (reduced to safe characters, public
 * checkout only) and its retention; JS events in tests/js/tracking.test.js.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders as R7gOrders;
use PassCaptain\Privacy\Retention as R7gRetention;

global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();

echo "R7 attribution: UTM on orders, cleared after the retention period\n";
check( 'attribution is reduced to known keys and safe characters', 'source=newsletterscript;medium=e-mail;ref=www.partner.example' === R7gOrders::attribution( array( 'source' => 'NewsLetter<script>', 'medium' => 'e-mail', 'campaign' => array( 'x' ), 'ref' => 'www.partner.example', 'email' => 'anna@example.com' ) ) && '' === R7gOrders::attribution( 'source=x' ) );
$r7g_type = make_type( 20, 10, 1000 );
$r7g = R7gOrders::create( array( 'email' => 'r7g@example.com', 'items' => array( array( 'ticket_type_id' => $r7g_type, 'quantity' => 1 ) ), 'attribution' => array( 'source' => 'newsletter', 'campaign' => 'sommer' ), 'idempotency_key' => 'r7g-' . microtime() ), array( 'skip_rate_limit' => true ) );
$r7g_id = is_wp_error( $r7g ) ? 0 : (int) $r7g['order_id'];
check( 'public checkout stores the attribution', $r7g_id && 'source=newsletter;campaign=sommer' === R7gOrders::get( $r7g_id )->attribution );
$r7g_pos = R7gOrders::create( array( 'email' => 'r7g-pos@example.com', 'items' => array( array( 'ticket_type_id' => $r7g_type, 'quantity' => 1 ) ), 'attribution' => array( 'source' => 'x' ), 'idempotency_key' => 'r7g-pos-' . microtime() ), array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true ) );
check( 'channel orders (box office) store none', ! is_wp_error( $r7g_pos ) && '' === (string) R7gOrders::get( $r7g_pos['order_id'] )->attribution );
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ), array( 'order_id' => $r7g_id ) );
$r7g_counts = R7gRetention::run();
check( 'retention clears attribution after 90 days', '' === (string) R7gOrders::get( $r7g_id )->attribution && ( $r7g_counts['attribution'] ?? 0 ) >= 1 );
