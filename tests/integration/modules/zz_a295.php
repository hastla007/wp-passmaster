<?php
/**
 * A295: the Stripe/PayPal sections list the webhook events to select, name the missing fields in
 * German and offer "Verbindung testen" (credentials, webhook URL and events, read-only).
 * Included from run.php (uses check(); HTTP fakes from payments.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Menu;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';

echo "A295: webhook setup with event list, missing fields and connection test\n";
$a295_saved = get_option( Settings::OPTION, array() );
$GLOBALS['wpcap_test_http_queue'] = array();
Settings::update( array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'paypal_client_id' => '', 'paypal_client_secret' => '', 'paypal_webhook_id' => '', 'paypal_mode' => 'sandbox' ) );

ob_start(); SettingsPage::render( 'payments', 'stripe' ); $a295_stripe = ob_get_clean();
check( 'Stripe section lists every webhook event to select', ! array_filter( StripeGateway::WEBHOOK_EVENTS, static fn( $e ) => false === strpos( $a295_stripe, '<code>' . $e . '</code>' ) ) && false !== strpos( $a295_stripe, Webhooks::endpoint_url( 'stripe' ) ) );
check( 'Stripe section names the missing fields with German labels', false !== strpos( $a295_stripe, 'Es fehlen: Geheimer API-Schlüssel (Secret Key), Webhook-Signaturgeheimnis (Signing Secret)' ) && false !== strpos( $a295_stripe, 'whsec_' ) );
check( 'Stripe section has the "Verbindung testen" form outside the settings form', 1 === preg_match( '#</form>\s*<h2 id="wpcap-connection-test">#', $a295_stripe ) && false !== strpos( $a295_stripe, 'value="' . SettingsPage::TEST_ACTION . '"' ) && false !== strpos( $a295_stripe, 'name="provider" value="stripe"' ) );
ob_start(); SettingsPage::render( 'payments', 'paypal' ); $a295_paypal = ob_get_clean();
check( 'PayPal section lists its events and names the missing fields', ! array_filter( PayPalGateway::WEBHOOK_EVENTS, static fn( $e ) => false === strpos( $a295_paypal, '<code>' . $e . '</code>' ) ) && false !== strpos( $a295_paypal, 'Es fehlen: Client-ID, Client-Geheimnis (Secret), Webhook-ID' ) );
ob_start(); SettingsPage::render( 'payments', 'manual' ); $a295_manual = ob_get_clean();
check( 'no connection test for bank transfer', false === strpos( $a295_manual, SettingsPage::TEST_ACTION ) );
check( 'connection test handler is registered', isset( Menu::handlers()[ SettingsPage::TEST_ACTION ] ) );

// --- Stripe connection test -----------------------------------------------------------------------
$a295_test = static fn( $provider ) => SettingsPage::test_connection( array( 'provider' => $provider ) );
$a295_types = static fn( $r ) => array_column( $r['messages'], 0 );
$a295_text = static fn( $r ) => implode( ' | ', array_column( $r['messages'], 1 ) );
$a295_r = $a295_test( 'stripe' );
check( 'Stripe without key: error naming the secret key, no request', false === $a295_r['ok'] && array( 'error' ) === $a295_types( $a295_r ) && false !== strpos( $a295_text( $a295_r ), 'Secret Key' ) );

Settings::update( array( 'stripe_secret_key' => 'sk_test_a295', 'stripe_webhook_secret' => 'whsec_a295' ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/account', 'code' => 401, 'body' => array( 'error' => array( 'message' => 'Invalid API Key provided' ) ) );
$a295_r = $a295_test( 'stripe' );
check( 'Stripe with a wrong key: error with Stripe\'s message', false === $a295_r['ok'] && false !== strpos( $a295_text( $a295_r ), 'Invalid API Key provided' ) );

$a295_before = count( $GLOBALS['wpcap_test_http_requests'] );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/account', 'body' => array( 'id' => 'acct_A295' ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/webhook_endpoints', 'body' => array( 'data' => array( array( 'url' => Webhooks::endpoint_url( 'stripe' ), 'status' => 'enabled', 'enabled_events' => array( 'checkout.session.completed', 'charge.refunded' ) ) ) ) );
$a295_r = $a295_test( 'stripe' );
$a295_reqs = array_slice( $GLOBALS['wpcap_test_http_requests'], $a295_before );
check( 'Stripe: connection ok, missing events of the endpoint are named', true === $a295_r['ok'] && false !== strpos( $a295_text( $a295_r ), 'acct_A295' ) && false !== strpos( $a295_text( $a295_r ), 'checkout.session.expired' ) && false === strpos( $a295_text( $a295_r ), 'checkout.session.completed,' ) && in_array( 'warning', $a295_types( $a295_r ), true ) );
check( 'Stripe test only reads (GET requests)', 2 === count( $a295_reqs ) && array( 'GET' ) === array_values( array_unique( array_column( $a295_reqs, 'method' ) ) ) );

$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/account', 'body' => array( 'id' => 'acct_A295' ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/webhook_endpoints', 'body' => array( 'data' => array( array( 'url' => 'https://other.example/hook', 'enabled_events' => array( '*' ) ) ) ) );
$a295_r = $a295_test( 'stripe' );
check( 'Stripe: no endpoint with this site\'s URL is reported', false !== strpos( $a295_text( $a295_r ), 'Kein Webhook-Endpunkt mit der URL' ) );

$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/account', 'body' => array( 'id' => 'acct_A295' ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/webhook_endpoints', 'body' => array( 'data' => array( array( 'url' => Webhooks::endpoint_url( 'stripe' ), 'status' => 'enabled', 'enabled_events' => StripeGateway::WEBHOOK_EVENTS ) ) ) );
$a295_r = $a295_test( 'stripe' );
check( 'Stripe: complete setup reports success only', true === $a295_r['ok'] && array( 'success', 'success' ) === $a295_types( $a295_r ) );

// --- PayPal connection test -----------------------------------------------------------------------
Settings::update( array( 'paypal_client_id' => 'cid', 'paypal_client_secret' => 'csecret', 'paypal_webhook_id' => 'WH-A295' ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/oauth2/token', 'body' => array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/notifications/webhooks/WH-A295', 'code' => 404, 'body' => array( 'name' => 'INVALID_RESOURCE_ID', 'message' => 'not found' ) );
$a295_r = $a295_test( 'paypal' );
check( 'PayPal: unknown webhook id is reported (sandbox/live mix-up)', true === $a295_r['ok'] && false !== strpos( $a295_text( $a295_r ), 'Sandbox' ) && false !== strpos( $a295_text( $a295_r ), 'unbekannt' ) );

$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/oauth2/token', 'body' => array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/notifications/webhooks/WH-A295', 'body' => array( 'id' => 'WH-A295', 'url' => Webhooks::endpoint_url( 'paypal' ), 'event_types' => array( array( 'name' => 'PAYMENT.CAPTURE.COMPLETED' ) ) ) );
$a295_r = $a295_test( 'paypal' );
check( 'PayPal: missing events of the webhook are named', false !== strpos( $a295_text( $a295_r ), 'CHECKOUT.ORDER.APPROVED' ) && false !== strpos( $a295_text( $a295_r ), 'PAYMENT.CAPTURE.REVERSED' ) );

$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/oauth2/token', 'code' => 401, 'body' => array( 'error' => 'invalid_client', 'error_description' => 'Client Authentication failed' ) );
$a295_r = $a295_test( 'paypal' );
check( 'PayPal: wrong credentials are an error', false === $a295_r['ok'] && false !== strpos( $a295_text( $a295_r ), 'Client Authentication failed' ) );

// --- Handler and permissions ----------------------------------------------------------------------
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'connection test needs manage_options', is_wp_error( $a295_test( 'stripe' ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'unknown provider / provider without test is refused', is_wp_error( $a295_test( 'manual' ) ) && is_wp_error( $a295_test( 'nope' ) ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/account', 'body' => array( 'id' => 'acct_A295' ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => '/v1/webhook_endpoints', 'body' => array( 'data' => array() ) );
$_POST = array( 'action' => SettingsPage::TEST_ACTION, 'provider' => 'stripe', '_wpnonce' => 'n' );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$a295_redirect = '';
try { SettingsPage::handle_test_connection(); } catch ( Wpcap_Test_Redirect $e ) { $a295_redirect = $e->getMessage(); }
$_POST = array();
$a295_flash = $GLOBALS['wpcap_test_transients'][ 'wpcap_admin_notices_' . get_current_user_id() ] ?? array();
check( 'handler checks the nonce, flashes one notice per finding and returns to the Stripe section', in_array( SettingsPage::TEST_ACTION, $GLOBALS['wpcap_test_nonce_checks'], true ) && 2 === count( $a295_flash ) && 'success' === $a295_flash[0]['type'] && 'warning' === $a295_flash[1]['type'] && false !== strpos( $a295_redirect, 'section=stripe' ) );

$GLOBALS['wpcap_test_http_queue'] = array();
$GLOBALS['wpcap_test_transients'] = array();
Settings::update( array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'paypal_client_secret' => '' ) );
update_option( Settings::OPTION, $a295_saved );
Settings::flush();
