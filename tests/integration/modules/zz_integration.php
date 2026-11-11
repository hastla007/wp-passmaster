<?php
/** Cross-module integration checks run after all module tests. */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Webhooks\Dispatcher;

echo "Integration: full refunds emit order.refunded once\n";
\PassCaptain\Modules\Webhooks::register();
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$zz_sub = Dispatcher::create_subscription( 'https://zz.hooks.example/in', array( 'order.refunded' ) );
$zz_count = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d", $zz_sub['subscription_id'] ) );
$zt = make_type( 10, 10, 1000 );
$zo = Orders::create( array( 'email' => 'zz@example.com', 'items' => array( array( 'ticket_type_id' => $zt, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-1' ) );
Orders::confirm_manual_payment( $zo['order_id'] );
Orders::mark_refunded( $zo['order_id'], 'woocommerce_refunded' );
Orders::mark_refunded( $zo['order_id'], 'woocommerce_refunded' );
check( 'external full refund emits exactly one order.refunded', 1 === $zz_count() );
$zo2 = Orders::create( array( 'email' => 'zz2@example.com', 'items' => array( array( 'ticket_type_id' => $zt, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-2' ) );
Orders::confirm_manual_payment( $zo2['order_id'] );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
PassCaptain\Refunds::request( $zo2['order_id'], array(), 'Test', 'zz-r2' );
check( 'native full refund is reported once (per refund), not twice', 2 === $zz_count() );
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
