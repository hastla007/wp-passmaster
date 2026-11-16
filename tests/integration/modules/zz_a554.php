<?php
/**
 * A554: hosted payment pages (Stripe, PayPal, Mollie) send the buyer back to the checkout page
 * instead of the home page, the return notice names the order number, and after an aborted payment
 * the buyer can continue the payment or cancel the order, which releases the hold at once. Uses
 * the HTTP mocks of payments.php (http_push/http_requests/provider_order). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\ReturnNotice;
use PassCaptain\Payments\StripeGateway;

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }

echo "A554: return to the checkout page, continue or cancel after an aborted payment\n";
PassCaptain\Settings::update( array( 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test', 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1' ) );
Registry::reset();

// 1. Only URLs of this site become a return base; the return arguments are stripped.
check( 'A554 base: a page of this site keeps path and query, without return arguments and fragment', 'https://example.test/tickets/?lang=de' === ReturnNotice::base( 'https://example.test/tickets/?lang=de&wpcap_payment=cancel&order=abc&wpcap_rk=x#wpcap-occ-3' ) );
check( 'A554 base: other hosts, other schemes and user info fall back to the home page', 'https://example.test/' === ReturnNotice::base( 'https://evil.test/x' ) && 'https://example.test/' === ReturnNotice::base( 'javascript:alert(1)' ) && 'https://example.test/' === ReturnNotice::base( 'https://u:p@example.test/x' ) && 'https://example.test/' === ReturnNotice::base( '' ) && 'https://example.test/' === ReturnNotice::base( '//example.test.evil.test/' ) );

// 2. Stripe session: success/cancel URL on the checkout page, with order and key.
http_reset();
$a554_type = make_type( 10, 10, 2000 );
$a554_order = provider_order( 'stripe', $a554_type, 2, 'a554-stripe-' . uniqid() );
http_push( array( 'id' => 'cs_a554', 'url' => 'https://checkout.stripe.com/c/pay/cs_a554', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$a554_pay = Registry::get( 'stripe' )->create_payment( array( 'return_base' => 'https://example.test/konzert/?lang=de' ) + $a554_order, order_items_array( $a554_order['order_id'] ) );
$a554_form = form_body( http_requests( 'checkout/sessions' )[0] ?? array( 'body' => '' ) );
$a554_key = ReturnNotice::key( $a554_order['public_id'] );
check( 'A554 Stripe success_url returns to the checkout page with order and key', 0 === strpos( (string) ( $a554_form['success_url'] ?? '' ), 'https://example.test/konzert/?lang=de&wpcap_payment=success&order=' . $a554_order['public_id'] . '&wpcap_rk=' . $a554_key ) );
check( 'A554 Stripe cancel_url as well', 0 === strpos( (string) ( $a554_form['cancel_url'] ?? '' ), 'https://example.test/konzert/?lang=de&wpcap_payment=cancel&order=' . $a554_order['public_id'] ) );
check( 'A554 without a return page: home page (as before)', 0 === strpos( StripeGateway::return_url( 'success', 'abc' ), 'https://example.test/?wpcap_payment=success&order=abc' ) );
Orders::set_provider_reference( $a554_order['order_id'], 'cs_a554' );

// 3. PayPal: approval return and cancel URL on the checkout page.
http_reset();
$a554_pp_order = provider_order( 'paypal', $a554_type, 1, 'a554-paypal-' . uniqid() );
http_push( array( 'access_token' => 'A21-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'PAYA554', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYA554' ) ) ), 200, '/v2/checkout/orders' );
Registry::get( 'paypal' )->create_payment( array( 'return_base' => 'https://example.test/konzert/' ) + $a554_pp_order, order_items_array( $a554_pp_order['order_id'] ) );
$a554_pp_body = json_decode( (string) ( http_requests( '/v2/checkout/orders' )[0]['body'] ?? '' ), true );
$a554_ctx = $a554_pp_body['payment_source']['paypal']['experience_context'] ?? array();
check( 'A554 PayPal return_url and cancel_url point to the checkout page', 0 === strpos( (string) ( $a554_ctx['return_url'] ?? '' ), 'https://example.test/konzert/?wpcap_paypal_return=1&order=' ) && 0 === strpos( (string) ( $a554_ctx['cancel_url'] ?? '' ), 'https://example.test/konzert/?wpcap_payment=cancel&order=' ) );
Orders::cancel_order( $a554_pp_order['order_id'], 'a554_cleanup' );

// 4. Return notice after an aborted payment: order number, reserved until, continue / cancel.
$a554_html = ReturnNotice::html( 'cancel', $a554_order['public_id'], $a554_key );
$a554_ref = PassCaptain\Frontend\Format::order_reference( $a554_order['public_id'] );
check( 'A554 notice names the order number', false !== strpos( $a554_html, 'Bestellnummer: ' . $a554_ref ) );
check( 'A554 notice offers "Zahlung fortsetzen" and "Bestellung stornieren" for an open order', false !== strpos( $a554_html, 'value="resume"' ) && false !== strpos( $a554_html, 'value="cancel"' ) && false !== strpos( $a554_html, 'name="wpcap_rk" value="' . $a554_key . '"' ) );
check( 'A554 without the key: order number only, no actions', false === strpos( ReturnNotice::html( 'cancel', $a554_order['public_id'], str_repeat( '0', 32 ) ), 'value="cancel"' ) );
check( 'A554 success notice has no actions', false === strpos( ReturnNotice::html( 'success', $a554_order['public_id'], $a554_key ), 'value="cancel"' ) );

// 5. Wrong key: nothing happens.
$a554_wrong = ReturnNotice::perform_action( array( 'order' => $a554_order['public_id'], 'wpcap_rk' => str_repeat( 'a', 32 ), 'decision' => 'cancel', 'back' => 'https://example.test/konzert/' ) );
check( 'A554 a wrong key cancels nothing', 'pending' === Orders::get( $a554_order['order_id'] )->payment_status && false !== strpos( $a554_wrong, 'wpcap_payment=failed' ) );

// 6. Continue: the still open Stripe session again.
http_reset();
// Merged (Y2 QA-1): an open session is reused only for the order's current amount and currency.
http_push( array( 'id' => 'cs_a554', 'url' => 'https://checkout.stripe.com/c/pay/cs_a554', 'status' => 'open', 'amount_total' => (int) Orders::get( $a554_order['order_id'] )->total_minor, 'currency' => strtolower( (string) Orders::get( $a554_order['order_id'] )->currency ) ), 200, '/v1/checkout/sessions/cs_a554' );
$a554_resume = ReturnNotice::perform_action( array( 'order' => $a554_order['public_id'], 'wpcap_rk' => $a554_key, 'decision' => 'resume', 'back' => 'https://example.test/konzert/' ) );
check( 'A554 "Zahlung fortsetzen" leads to the open payment session of the same order (no new order)', 'https://checkout.stripe.com/c/pay/cs_a554' === $a554_resume && 'pending' === Orders::get( $a554_order['order_id'] )->payment_status && 0 === count( http_requests( '/v1/checkout/sessions' ) ) - count( http_requests( '/v1/checkout/sessions/cs_a554' ) ) );

// 7. Cancel: Stripe session expired, order cancelled, hold released.
Orders::flush_availability_cache();
$a554_held = Orders::availability( $a554_type );
http_reset();
http_push( array( 'id' => 'cs_a554', 'status' => 'expired' ), 200, '/v1/checkout/sessions/cs_a554/expire' );
$a554_cancel = ReturnNotice::perform_action( array( 'order' => $a554_order['public_id'], 'wpcap_rk' => $a554_key, 'decision' => 'cancel', 'back' => 'https://example.test/konzert/' ) );
Orders::flush_availability_cache();
$a554_after = Orders::get( $a554_order['order_id'] );
check( 'A554 "Bestellung stornieren" cancels the order and frees the seats at once', 'cancelled' === $a554_after->status && $a554_held + 2 === Orders::availability( $a554_type ) );
check( 'A554 the open Stripe session is expired', 1 === count( http_requests( '/v1/checkout/sessions/cs_a554/expire' ) ) && 'POST' === ( http_requests( '/v1/checkout/sessions/cs_a554/expire' )[0]['method'] ?? '' ) );
check( 'A554 back on the checkout page with the "storniert" notice', 0 === strpos( $a554_cancel, 'https://example.test/konzert/?wpcap_payment=cancelled&order=' . $a554_order['public_id'] ) && false !== strpos( ReturnNotice::html( 'cancelled', $a554_order['public_id'], $a554_key ), 'wieder frei' ) );
check( 'A554 a cancelled order offers no actions any more', false === strpos( ReturnNotice::html( 'cancel', $a554_order['public_id'], $a554_key ), 'value="resume"' ) );
