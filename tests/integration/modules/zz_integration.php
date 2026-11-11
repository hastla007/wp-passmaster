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

echo "Integration: payment webhook robustness\n";
$wt = make_type( 10, 10, 2000 );
$mk = static function ( $key ) use ( $wt ) { return Orders::create( array( 'email' => 'wh@example.com', 'items' => array( array( 'ticket_type_id' => $wt, 'quantity' => 1 ) ), 'idempotency_key' => 'zzwh-' . $key ), array( 'payment_provider' => 'stripe' ) ); };
$w1 = $mk( 'wh-1' );
PassCaptain\Orders::set_provider_reference( $w1['order_id'], 'cs_new' );
$stale = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_old_fail', 'type' => 'failed', 'order_public_id' => $w1['public_id'], 'provider_reference' => 'cs_old', 'related_references' => array( 'cs_old' ) ) );
check( 'failure of an older payment attempt does not cancel the order', 'stale_attempt' === $stale && 'pending' === Orders::get( $w1['order_id'] )->payment_status );
$paid = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_new_paid', 'type' => 'paid', 'order_public_id' => $w1['public_id'], 'provider_reference' => 'pi_new', 'related_references' => array( 'cs_new', 'pi_new' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$double = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_other_paid', 'type' => 'paid', 'order_public_id' => $w1['public_id'], 'provider_reference' => 'pi_other', 'related_references' => array( 'cs_old', 'pi_other' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'second successful payment is flagged as double payment and the admin alerted', 'paid' === $paid && 'double_payment' === $double && 'pi_new' === Orders::get( $w1['order_id'] )->provider_reference && 1 === count( array_slice( $GLOBALS['wpcap_test_mails'], $before ) ) );
$w2 = $mk( 'wh-2' );
Orders::cancel_order( $w2['order_id'], 'test' );
$closed = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_closed', 'type' => 'paid', 'order_public_id' => $w2['public_id'], 'provider_reference' => 'pi_c', 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'payment for a cancelled order is recorded and alerted, not silently kept', 'paid_for_closed_order' === $closed );
$wpdb->insert( "{$p}wpcap_payment_events", array( 'provider' => 'stripe', 'provider_event_id' => 'evt_stuck', 'event_type' => 'paid', 'status' => 'processing', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
$w3 = $mk( 'wh-3' );
$stuck = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_stuck', 'type' => 'paid', 'order_public_id' => $w3['public_id'], 'provider_reference' => 'pi_3', 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'a claim stuck in processing is taken over after 10 minutes', 'paid' === $stuck && 'paid' === Orders::get( $w3['order_id'] )->payment_status );
