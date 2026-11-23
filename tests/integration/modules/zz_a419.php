<?php
// phpcs:ignoreFile
/**
 * A419: a won Stripe chargeback (charge.dispute.funds_reinstated) is evaluated: audit entry
 * chargeback_reversed and an admin mail; the booked chargeback (order refunded, tickets revoked) is not
 * undone automatically. HTTP is faked, no provider is called.
 */

use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a419_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test', 'refund_approval_threshold' => 0 ) );
Registry::reset();
$a419_stripe = Registry::get( 'stripe' );
$a419_type = make_type( 10, 10, 2000 );

echo "A419: won Stripe chargeback\n";
check( 'A419: funds_reinstated is one of the required Stripe webhook events', in_array( 'charge.dispute.funds_reinstated', StripeGateway::WEBHOOK_EVENTS, true ) );
http_reset();
$a419_o = provider_order( 'stripe', $a419_type, 1, 'a419-' . uniqid() );
$a419_paid = Webhooks::process( 'stripe', array( 'event_id' => 'evt_a419_paid_' . uniqid(), 'type' => 'paid', 'order_public_id' => $a419_o['public_id'], 'provider_reference' => 'pi_a419', 'related_references' => array( 'pi_a419' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
$a419_dispute = array( 'id' => 'dp_a419', 'object' => 'dispute', 'amount' => 2000, 'currency' => 'eur', 'charge' => 'ch_a419', 'payment_intent' => 'pi_a419', 'metadata' => array( 'wpcap_order' => $a419_o['public_id'] ) );
$a419_lost = stripe_deliver( $a419_stripe, array( 'id' => 'evt_a419_dw_' . uniqid(), 'type' => 'charge.dispute.funds_withdrawn', 'data' => array( 'object' => $a419_dispute + array( 'status' => 'needs_response' ) ) ) );
check( 'A419: setup – the chargeback was booked as a refund', 'paid' === $a419_paid && 'refunded' === $a419_lost && 'refunded' === Orders::get( $a419_o['order_id'] )->status );

$a419_mails = count( $GLOBALS['wpcap_test_mails'] );
$a419_won = stripe_deliver( $a419_stripe, array( 'id' => 'evt_a419_dr_' . uniqid(), 'type' => 'charge.dispute.funds_reinstated', 'data' => array( 'object' => $a419_dispute + array( 'status' => 'won' ) ) ) );
$a419_audit = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='chargeback_reversed' AND object_id=%d", $a419_o['order_id'] ) );
$a419_mail = end( $GLOBALS['wpcap_test_mails'] );
check( 'A419: the won chargeback is recognised', 'chargeback_reversed' === $a419_won );
check( 'A419: audit entry with amount and the booked chargeback', $a419_audit && false !== strpos( $a419_audit, '"amount_minor":2000' ) && false !== strpos( $a419_audit, '"chargeback_booked":true' ) );
check( 'A419: the admin gets a mail asking for a decision', count( $GLOBALS['wpcap_test_mails'] ) === $a419_mails + 1 && false !== strpos( (string) $a419_mail['body'], 'zu deinen Gunsten' ) && false !== strpos( (string) $a419_mail['body'], 'Tickets neu ausstellen' ) );
check( 'A419: the order and its refund are not changed automatically', 'refunded' === Orders::get( $a419_o['order_id'] )->status && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $a419_o['order_id'] ) ) );

$a419_again = stripe_deliver( $a419_stripe, array( 'id' => 'evt_a419_dr_' . uniqid(), 'type' => 'charge.dispute.funds_reinstated', 'data' => array( 'object' => array_merge( $a419_dispute, array( 'id' => 'dp_a419_other' ) ) ) ) );
check( 'A419: a won chargeback without a booked chargeback says so', 'chargeback_reversed' === $a419_again && false !== strpos( (string) end( $GLOBALS['wpcap_test_mails'] )['body'], 'keine Erstattung gebucht' ) );

update_option( Settings::OPTION, $a419_saved );
Settings::flush();
Registry::reset();
http_reset();
