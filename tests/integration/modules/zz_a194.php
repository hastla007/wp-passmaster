<?php
/**
 * A194: secret redaction and input validation under error conditions.
 *
 * Every provider secret of src/Secrets.php (plus a webhook signing secret, a full card number, an
 * IBAN and a buyer e-mail) is stored as a unique canary. Then errors are forced – payment provider
 * API and transport errors, webhook signature failures, SMS/fiscal/wallet provider failures, database
 * errors, an uncaught exception inside a REST callback and failed outgoing webhooks – and every output
 * an operator or client sees is searched for the canaries: the PHP error log, audit rows, REST error
 * responses (message and data), admin notices, mails, Site Health/operations texts and the error
 * columns of the queues (jobs, SMS, webhooks, mails, refunds).
 * Then the main public REST inputs (checkout, check-in, waitlist, partner API) are fuzzed with
 * malformed, oversized, wrongly typed and unicode edge-case values: each answer must be a clean 4xx
 * WP_Error without PHP warning/notice/deprecation, output or stack trace.
 *
 * Included from run.php (uses check(), make_type(), http_push() of payments.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Forms;
use PassCaptain\Audit;
use PassCaptain\Jobs;
use PassCaptain\Operations\Monitor;
use PassCaptain\Operations\Queues;
use PassCaptain\Operations\SiteHealth;
use PassCaptain\Orders;
use PassCaptain\Partners\Api as PartnerApi;
use PassCaptain\Partners\Keys as PartnerKeys;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Sales as PartnerSales;
use PassCaptain\Redact;
use PassCaptain\Rest\Controller;
use PassCaptain\Secrets;
use PassCaptain\Settings;
use PassCaptain\Webhooks\Dispatcher;

if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }

global $wpdb;
$p = $wpdb->prefix;
echo "A194: canaries\n";

$a194_options_before = $GLOBALS['wpcap_test_options'];
$a194_user_before = $GLOBALS['wpcap_test_user_id'] ?? null;
$a194_caps_before = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.194';
$a194_audit_start = (int) $wpdb->get_var( "SELECT COALESCE(MAX(audit_id),0) FROM {$p}wpcap_audit_logs" );
$a194_mail_start = count( $GLOBALS['wpcap_test_mails'] );

// Unique, never-guessable canary core per secret (alphanumeric, so URL/JSON encoding keeps it intact).
$a194_canaries = array();
foreach ( Secrets::KEYS as $a194_key ) { $a194_canaries[ $a194_key ] = 'Cnry' . bin2hex( random_bytes( 8 ) ); }
$a194_pem = static function ( $core ) { return "-----BEGIN PRIVATE KEY-----\nMIIEv" . $core . str_repeat( 'Q', 40 ) . "\n" . str_repeat( 'A', 20 ) . $core . "==\n-----END PRIVATE KEY-----"; };
$a194_values = array(
	'stripe_secret_key'             => 'sk_live_' . $a194_canaries['stripe_secret_key'],
	'stripe_webhook_secret'         => 'whsec_' . $a194_canaries['stripe_webhook_secret'],
	'paypal_client_secret'          => 'EPp-' . $a194_canaries['paypal_client_secret'],
	'mollie_api_key'                => 'live_' . $a194_canaries['mollie_api_key'],
	'twilio_auth_token'             => $a194_canaries['twilio_auth_token'],
	'sms_http_secret'               => 'gw-' . $a194_canaries['sms_http_secret'],
	'sms_http_webhook_secret'       => 'hook-' . $a194_canaries['sms_http_webhook_secret'],
	'apple_pass_cert'               => "-----BEGIN CERTIFICATE-----\nMIIF" . $a194_canaries['apple_pass_cert'] . "\n-----END CERTIFICATE-----",
	'apple_pass_key'                => $a194_pem( $a194_canaries['apple_pass_key'] ),
	'apple_pass_key_password'       => 'pw-' . $a194_canaries['apple_pass_key_password'],
	'apple_wwdr_cert'               => "-----BEGIN CERTIFICATE-----\nMIIE" . $a194_canaries['apple_wwdr_cert'] . "\n-----END CERTIFICATE-----",
	'google_wallet_service_account' => json_encode( array( 'type' => 'service_account', 'client_email' => 'pc@example.iam.gserviceaccount.com', 'private_key_id' => 'kid' . $a194_canaries['google_wallet_service_account'], 'private_key' => $a194_pem( $a194_canaries['google_wallet_service_account'] ), 'token_uri' => 'https://oauth2.googleapis.com/token' ) ),
);
$a194_stored_before = array();
foreach ( $a194_values as $a194_key => $a194_value ) {
	$a194_stored_before[ $a194_key ] = Secrets::stored( $a194_key );
	Secrets::set( $a194_key, $a194_value );
}
Redact::reset();
// Personal data that is masked by design in operator outputs.
$a194_card = '4111 1111 1111 1111';
$a194_iban = 'AT61 1904 3002 3457 3201';
$a194_buyer = 'leak.' . bin2hex( random_bytes( 4 ) ) . '@buyer-example.com';
$a194_webhook_core = '';

check( 'A194: every Secrets::KEYS value is set and readable', count( array_filter( Secrets::KEYS, static fn( $k ) => Secrets::get( $k ) === $a194_values[ $k ] ) ) === count( Secrets::KEYS ) );

/** Canary cores (and masked-by-design values) found in a text. */
function a194_found( $text ) {
	$text = is_string( $text ) ? $text : json_encode( $text );
	$found = array();
	foreach ( $GLOBALS['a194_canaries'] as $key => $core ) {
		if ( false !== strpos( (string) $text, $core ) ) { $found[] = $key; }
	}
	if ( '' !== $GLOBALS['a194_webhook_core'] && false !== strpos( (string) $text, $GLOBALS['a194_webhook_core'] ) ) { $found[] = 'webhook_secret'; }
	foreach ( array( 'card' => array( '4111111111111111', '4111 1111 1111 1111' ), 'iban' => array( 'AT611904300234573201', 'AT61 1904 3002 3457 3201' ), 'buyer_email' => array( $GLOBALS['a194_buyer'] ) ) as $label => $needles ) {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( (string) $text, $needle ) ) { $found[] = $label; break; }
		}
	}
	return array_values( array_unique( $found ) );
}

// PHP error log (debug.log with WP_DEBUG_LOG) of this test.
$a194_log = sys_get_temp_dir() . '/wpcap-a194-' . getmypid() . '.log';
@unlink( $a194_log );
$a194_log_ini = array( 'error_log' => ini_get( 'error_log' ), 'log_errors' => ini_get( 'log_errors' ) );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $a194_log );
// A fatal error would land in the redirected log: print it, so the run shows why it stopped.
register_shutdown_function( static function () { if ( empty( $GLOBALS['a194_done'] ) && is_file( $GLOBALS['a194_log'] ) ) { fwrite( STDERR, file_get_contents( $GLOBALS['a194_log'] ) ); } } );
function a194_log() { return is_file( $GLOBALS['a194_log'] ) ? (string) file_get_contents( $GLOBALS['a194_log'] ) : ''; }

/**
 * Runs a registered plugin route like WP_REST_Server: rest_endpoints (Redact::guard_endpoints), permission
 * callback, callback, rest_request_after_callbacks (Redact::rest_after_callbacks). Records PHP
 * warnings/notices/deprecations and printed output.
 */
function a194_rest( $route, $method, array $params = array(), $body = null, array $headers = array() ) {
	$def = $GLOBALS['wpcap_test_routes'][ 'pass-captain/v1' . $route ] ?? null;
	if ( ! $def ) { return array( 'res' => new WP_Error( 'no_route', $route, array( 'status' => 404 ) ), 'status' => 0, 'issues' => array( 'route missing' ), 'output' => '' ); }
	$handlers = isset( $def['callback'] ) ? array( $def ) : array_values( array_filter( $def, 'is_array' ) );
	$endpoints = Redact::guard_endpoints( array( '/pass-captain/v1' . $route => $handlers ) );
	$handler = null;
	foreach ( $endpoints[ '/pass-captain/v1' . $route ] as $h ) {
		if ( in_array( $method, array_map( 'trim', explode( ',', (string) $h['methods'] ) ), true ) ) { $handler = $h; }
	}
	if ( ! $handler ) { return array( 'res' => new WP_Error( 'no_method', $method, array( 'status' => 405 ) ), 'status' => 0, 'issues' => array( 'method missing' ), 'output' => '' ); }
	$req = new WP_REST_Request( $method, '/pass-captain/v1' . $route );
	foreach ( $headers as $k => $v ) { $req->set_header( $k, $v ); }
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	if ( null !== $body ) { $req->set_body( is_string( $body ) ? $body : json_encode( $body ) ); }
	$issues = array();
	set_error_handler( static function ( $no, $str, $file, $line ) use ( &$issues ) { $issues[] = $no . ': ' . $str . ' @' . basename( $file ) . ':' . $line; return true; } );
	ob_start();
	$perm = call_user_func( $handler['permission_callback'], $req );
	if ( true !== $perm ) {
		$res = is_wp_error( $perm ) ? $perm : new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 401 ) );
	} else {
		$res = call_user_func( $handler['callback'], $req );
	}
	$res = Redact::rest_after_callbacks( $res, $handler, $req );
	$output = (string) ob_get_clean();
	restore_error_handler();
	$status = $res instanceof WP_Error ? (int) ( ( (array) $res->get_error_data() )['status'] ?? 500 ) : ( $res instanceof WP_REST_Response ? (int) $res->get_status() : 200 );
	return array( 'res' => $res, 'status' => $status, 'issues' => $issues, 'output' => $output );
}
function a194_text( $res ) {
	if ( $res instanceof WP_Error ) { return $res->get_error_code() . ' ' . $res->get_error_message() . ' ' . json_encode( $res->get_error_data() ); }
	return json_encode( $res instanceof WP_REST_Response ? $res->get_data() : $res );
}

Controller::register_routes();
PartnerApi::register_routes();
PartnerSales::register();

/* ================================================================================== Redact helper */
echo "A194: central redaction helper\n";
$a194_mixed = 'Auth failed for ' . $a194_values['stripe_secret_key'] . ' / Authorization: Bearer abcDEF1234567890xyz / https://u:' . $a194_canaries['sms_http_secret'] . '@gw.example/x?api_key=' . $a194_canaries['mollie_api_key'] . '&to=1 / card ' . $a194_card . ' / iban ' . $a194_iban . ' / ' . $a194_buyer . ' / ' . $a194_values['apple_pass_key'];
$a194_red = Redact::text( $a194_mixed );
check( 'Redact::text removes known secrets, PEM keys, Bearer tokens, URL credentials, key=value secrets, cards, IBANs and e-mails', array() === a194_found( $a194_red ) && false === strpos( $a194_red, 'abcDEF1234567890xyz' ) && false !== strpos( $a194_red, '**** 1111' ) && false !== strpos( $a194_red, 'AT** **** 3201' ) && false !== strpos( $a194_red, 'Auth failed for' ) );
check( 'Redact::text keeps ordinary text, order numbers and short numbers', 'Bestellung 12345 (Ticket ABC-77) ist bezahlt: 2026-11-13 12:00, Betrag 49,90 EUR' === Redact::text( 'Bestellung 12345 (Ticket ABC-77) ist bezahlt: 2026-11-13 12:00, Betrag 49,90 EUR' ) && '1234 5678 9012 3456' === Redact::text( '1234 5678 9012 3456' ) );
check( 'Redact::text encodes nothing back: JSON- and URL-encoded secrets are removed too', array() === a194_found( Redact::text( json_encode( array( 'k' => $a194_values['google_wallet_service_account'] ) ) . rawurlencode( $a194_values['apple_pass_key_password'] ) ) ) );
$a194_struct = Redact::value( array( 'client_secret' => 'abc12345678', 'nested' => array( 'password' => 'hunter2hunter2', 'note' => 'key=' . $a194_canaries['twilio_auth_token'] ), 'tickets' => 3, 'iban' => $a194_iban, 'keys' => array( 'stripe_secret_key' ) ), false );
check( 'Redact::value masks secret-named keys and keeps harmless structure', Redact::MASK === $a194_struct['client_secret'] && Redact::MASK === $a194_struct['nested']['password'] && array() === a194_found( $a194_struct ) && 3 === $a194_struct['tickets'] && array( 'stripe_secret_key' ) === $a194_struct['keys'] );
$a194_e = Redact::error( new WP_Error( 'x', 'token=' . $a194_canaries['mollie_api_key'], array( 'status' => 502, 'raw' => $a194_values['paypal_client_secret'] ) ) );
check( 'Redact::error redacts message and data and keeps status', 502 === $a194_e->get_error_data()['status'] && array() === a194_found( a194_text( $a194_e ) ) );
$a194_ex = Redact::exception( new RuntimeException( 'boom ' . $a194_values['stripe_secret_key'] ) );
check( 'Redact::exception has no trace and no secret', array() === a194_found( $a194_ex ) && false === strpos( $a194_ex, '#0' ) && 0 === strpos( $a194_ex, 'RuntimeException: boom' ) );

/* ============================================================================ Payment provider errors */
echo "A194: payment provider errors\n";
$a194_type = make_type( 500, 10, 2500 );
$a194_settings_providers = (array) Settings::get( 'enabled_providers' );
Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe', 'paypal' ), 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client-a194', 'paypal_webhook_id' => 'WH-A194' ) );
$a194_outputs = array();
$a194_checkout = static function ( $provider, $key ) use ( $a194_type ) {
	return a194_rest( '/checkout', 'POST', array(), array( 'email' => $GLOBALS['a194_buyer'], 'items' => array( array( 'ticket_type_id' => $a194_type, 'quantity' => 1 ) ), 'provider' => $provider, 'idempotency_key' => $key, 'accept_terms' => true ) );
};
http_reset();
http_push( array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: ' . $a194_values['stripe_secret_key'] . ' (card ' . $a194_card . ', customer ' . $a194_buyer . ')' ) ), 401, 'api.stripe.com' );
$a194_r = $a194_checkout( 'stripe', 'a194-stripe-401' );
$a194_outputs['stripe api error'] = a194_text( $a194_r['res'] );
check( 'Stripe API error: checkout answers 502 without key, card or buyer e-mail', 502 === $a194_r['status'] && 'wpcap_stripe_api' === $a194_r['res']->get_error_code() && array() === a194_found( $a194_outputs['stripe api error'] ) && ! $a194_r['issues'] );
http_reset();
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out; headers Authorization: Bearer ' . $a194_values['stripe_secret_key'] ), 'match' => 'api.stripe.com' );
$a194_r = $a194_checkout( 'stripe', 'a194-stripe-timeout' );
$a194_outputs['stripe transport'] = a194_text( $a194_r['res'] );
check( 'Stripe transport error: message is kept, the key is not', 502 === $a194_r['status'] && false !== strpos( $a194_outputs['stripe transport'], 'Operation timed out' ) && array() === a194_found( $a194_outputs['stripe transport'] ) );
http_reset();
http_push( array( 'error' => 'invalid_client', 'error_description' => 'Client Authentication failed for secret ' . $a194_values['paypal_client_secret'] ), 401, '/v1/oauth2/token' );
$a194_r = $a194_checkout( 'paypal', 'a194-paypal-auth' );
$a194_outputs['paypal auth'] = a194_text( $a194_r['res'] );
check( 'PayPal OAuth error: checkout answer without client secret', $a194_r['status'] >= 500 && array() === a194_found( $a194_outputs['paypal auth'] ) );
http_reset();
http_push( array( 'access_token' => 'A21-a194', 'expires_in' => 3600 ), 200, '/v1/oauth2/token' );
http_push( array( 'name' => 'INVALID_REQUEST', 'message' => 'bad', 'details' => array( array( 'issue' => 'PAYEE_ACCOUNT_INVALID', 'description' => 'payee ' . $a194_buyer . ' iban ' . $a194_iban . ' client_secret=' . $a194_canaries['paypal_client_secret'] ) ) ), 422, '/v2/checkout/orders' );
$a194_r = $a194_checkout( 'paypal', 'a194-paypal-422' );
$a194_outputs['paypal api'] = a194_text( $a194_r['res'] );
check( 'PayPal API error: issue code kept, secret/IBAN/e-mail removed', false !== strpos( $a194_outputs['paypal api'], 'PAYEE_ACCOUNT_INVALID' ) && array() === a194_found( $a194_outputs['paypal api'] ) );
Settings::update( array( 'mollie_profile_id' => 'pfl_a194' ) );
http_reset();
http_push( array( 'status' => 401, 'title' => 'Unauthorized', 'detail' => 'Invalid API key: ' . $a194_values['mollie_api_key'] ), 401, 'api.mollie.com' );
http_push( array( 'status' => 401, 'title' => 'Unauthorized', 'detail' => 'Invalid API key: ' . $a194_values['mollie_api_key'] ), 401, 'api.mollie.com' );
$a194_mollie_order = (array) Orders::get( Orders::create( array( 'email' => 'mollie-a194@example.com', 'items' => array( array( 'ticket_type_id' => $a194_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a194-mollie' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 30 ) )['order_id'] );
$a194_m = ( new PassCaptain\Mollie\MollieGateway() )->create_payment( $a194_mollie_order, array_map( static fn( $r ) => (array) $r, Orders::items_for_order( $a194_mollie_order['order_id'] ) ) );
$a194_outputs['mollie api'] = a194_text( $a194_m );
check( 'Mollie API error without API key', is_wp_error( $a194_m ) && 'wpcap_mollie_api' === $a194_m->get_error_code() && array() === a194_found( $a194_outputs['mollie api'] ) );
Orders::cancel_order( $a194_mollie_order['order_id'], 'a194' );

// Refund failure: provider error is stored on the refund row, audited and shown to the admin.
http_reset();
$a194_paid = (array) Orders::get( Orders::create( array( 'email' => $a194_buyer, 'items' => array( array( 'ticket_type_id' => $a194_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a194-refund' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 30 ) )['order_id'] );
$a194_stripe = PassCaptain\Payments\Registry::get( 'stripe' );
$a194_ev = stripe_session_event( 'evt_a194_paid', 'checkout.session.completed', $a194_paid );
$a194_ev = PassCaptain\Payments\Webhooks::process( 'stripe', $a194_stripe->verify_webhook( stripe_request( $a194_ev, $a194_values['stripe_webhook_secret'] ) ) );
http_push( array( 'error' => array( 'message' => 'No such payment_intent; key ' . $a194_values['stripe_secret_key'] . ' card ' . $a194_card ) ), 400, '/v1/refunds' );
$a194_rf = PassCaptain\Refunds::request( $a194_paid['order_id'], array(), 'a194', 'a194-refund-1' );
$a194_outputs['refund error'] = a194_text( $a194_rf );
$a194_outputs['refund rows'] = $wpdb->get_col( $wpdb->prepare( "SELECT error_message FROM {$p}wpcap_refunds WHERE order_id=%d", $a194_paid['order_id'] ) );
check( 'Stripe refund error: paid order set up, refund fails without leaking into the error or the refund row', ! is_wp_error( $a194_ev ) && 'paid' === Orders::get( $a194_paid['order_id'] )->payment_status && is_wp_error( $a194_rf ) && $a194_outputs['refund rows'] && array() === a194_found( $a194_outputs['refund error'] ) && array() === a194_found( $a194_outputs['refund rows'] ) );

/* ============================================================================ Webhook signature errors */
echo "A194: webhook signature failures\n";
$a194_wh = stripe_request( stripe_session_event( 'evt_a194_bad', 'checkout.session.completed', $a194_paid ), 'whsec_wrong_' . $a194_canaries['stripe_webhook_secret'] );
$a194_wh->set_param( 'provider', 'stripe' );
$a194_whr = a194_rest( '/payments/(?P<provider>[a-z0-9_-]+)/webhook', 'POST', array( 'provider' => 'stripe' ), $a194_wh->get_body(), array( 'Stripe-Signature' => $a194_wh->get_header( 'Stripe-Signature' ) ) );
$a194_outputs['stripe signature'] = a194_text( $a194_whr['res'] );
check( 'Stripe webhook with a wrong signature: 4xx without the secret or the presented signature', $a194_whr['status'] >= 400 && $a194_whr['status'] < 500 && array() === a194_found( $a194_outputs['stripe signature'] ) && ! $a194_whr['issues'] );
http_reset();
http_push( array( 'access_token' => 'A21-a194b', 'expires_in' => 3600 ), 200, '/v1/oauth2/token' );
http_push( array( 'name' => 'VALIDATION_ERROR', 'message' => 'webhook_id invalid for client secret ' . $a194_values['paypal_client_secret'] ), 400, 'verify-webhook-signature' );
$a194_pp = paypal_request( array( 'id' => 'WH-a194', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => array( 'id' => 'CAP-a194' ) ) );
$a194_ppr = a194_rest( '/payments/(?P<provider>[a-z0-9_-]+)/webhook', 'POST', array( 'provider' => 'paypal' ), $a194_pp->get_body(), array( 'PAYPAL-TRANSMISSION-ID' => 'tid', 'PAYPAL-TRANSMISSION-TIME' => gmdate( 'c' ), 'PAYPAL-TRANSMISSION-SIG' => 'sig' . $a194_canaries['paypal_client_secret'], 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA' ) );
$a194_outputs['paypal verify'] = a194_text( $a194_ppr['res'] );
check( 'PayPal webhook verification failure: error answer without client secret', is_wp_error( $a194_ppr['res'] ) && array() === a194_found( $a194_outputs['paypal verify'] ) && ! $a194_ppr['issues'] );

/* ======================================================================= SMS / fiscal / wallet errors */
echo "A194: SMS, fiscal and wallet provider failures\n";
PassCaptain\Modules\Sms::register();
$a194_sms_before = Settings::get( 'sms' );
Settings::update( array( 'twilio_account_sid' => 'AC' . str_repeat( 'c3', 16 ), 'sms' => array_merge( PassCaptain\Modules\Sms::settings_defaults()['sms'], array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49' ) ) ) );
$a194_sms_order = Orders::create( array( 'email' => 'sms-a194@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 10, 10, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'a194-sms', 'sms_consent' => true, 'sms_phone' => '+4915133334444' ) );
PassCaptain\Sms\Service::enqueue( 'a194-sms-1', $a194_sms_order['order_id'], 0, 'reschedule', 'Hallo' );
PassCaptain\Sms\Service::enqueue( 'a194-sms-2', $a194_sms_order['order_id'], 0, 'reschedule', 'Hallo 2' );
http_reset();
http_push( array( 'code' => 20003, 'message' => 'Authenticate: token ' . $a194_values['twilio_auth_token'] . ' rejected' ), 401, '/Messages.json' );
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'cURL error 35: SSL connect error, Authorization: Basic ' . base64_encode( 'AC:' . $a194_values['twilio_auth_token'] ) . ' token=' . $a194_canaries['twilio_auth_token'] ), 'match' => '/Messages.json' );
PassCaptain\Sms\Service::process_due();
$a194_outputs['sms last_error'] = $wpdb->get_col( $wpdb->prepare( "SELECT last_error FROM {$p}wpcap_sms_messages WHERE order_id=%d", $a194_sms_order['order_id'] ) );
check( 'Twilio API and transport errors: SMS last_error filled, without auth token', 2 === count( array_filter( $a194_outputs['sms last_error'] ) ) && array() === a194_found( $a194_outputs['sms last_error'] ) );
Settings::update( array( 'sms' => array_merge( (array) $a194_sms_before, array( 'provider' => 'http', 'http_endpoint' => 'https://sms.gw.example/send?api_key=' . $a194_canaries['sms_http_secret'] ) ) ) );
PassCaptain\Sms\Service::enqueue( 'a194-sms-3', $a194_sms_order['order_id'], 0, 'reschedule', 'Hallo 3' );
http_reset();
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'Could not resolve https://sms.gw.example/send?api_key=' . $a194_canaries['sms_http_secret'] . ' header Authorization: ' . $a194_values['sms_http_secret'] ), 'match' => 'sms.gw.example' );
PassCaptain\Sms\Service::process_due();
$a194_outputs['sms http last_error'] = $wpdb->get_col( $wpdb->prepare( "SELECT last_error FROM {$p}wpcap_sms_messages WHERE order_id=%d", $a194_sms_order['order_id'] ) );
if ( getenv( 'A194_DEBUG' ) ) { fwrite( STDERR, print_r( $a194_outputs, true ) ); }
check( 'HTTP SMS gateway transport error: endpoint key and header secret are redacted', array() === a194_found( $a194_outputs['sms http last_error'] ) && in_array( true, array_map( static fn( $e ) => false !== strpos( (string) $e, 'Could not resolve' ), $a194_outputs['sms http last_error'] ), true ) );
Settings::update( array( 'sms' => $a194_sms_before ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='failed' WHERE order_id=%d", $a194_sms_order['order_id'] ) );

if ( ! interface_exists( 'PassCaptain\\Fiscalization\\AdapterInterface' ) ) {
	// The adapter contract ships with the fiscal bridge; a minimal stand-in suffices for the transport errors.
	eval( 'namespace PassCaptain\\Fiscalization; interface AdapterInterface { const VERSION = "1.0"; }' );
}
$a194_fiscal = new PassCaptain\Fiscalization\Providers\FiskaltrustAdapter();
http_reset();
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'x-cashbox-accesstoken: ' . $a194_canaries['sms_http_webhook_secret'] ) );
$a194_f = $a194_fiscal->health_check( array( 'seller_id' => 1 ), array( 'credentials' => array( 'cashbox_id' => 'cb-1', 'cashbox_access_token' => 'tok' . $a194_canaries['sms_http_webhook_secret'] ), 'possystem_id' => 'pos-1', 'base_url' => 'https://fiscal.example' ) );
http_push( array( 'error' => 'denied for token tok' . $a194_canaries['sms_http_webhook_secret'] ), 403, 'fiscal.example' );
$a194_f2 = $a194_fiscal->health_check( array( 'seller_id' => 1 ), array( 'credentials' => array( 'cashbox_id' => 'cb-1', 'cashbox_access_token' => 'tok' . $a194_canaries['sms_http_webhook_secret'] ), 'possystem_id' => 'pos-1', 'base_url' => 'https://fiscal.example' ) );
$a194_outputs['fiscal'] = a194_text( $a194_f ) . a194_text( $a194_f2 );
check( 'fiscal provider transport/HTTP failures: generic errors without the cashbox token', is_wp_error( $a194_f ) && is_wp_error( $a194_f2 ) && array() === a194_found( $a194_outputs['fiscal'] ) );

PassCaptain\WalletPasses\Service::log( 'issue_failed', 'openssl_pkcs7_sign(): key ' . $a194_values['apple_pass_key'] . ' password ' . $a194_values['apple_pass_key_password'] );
PassCaptain\WalletPasses\Service::log( 'push_failed', 'google: ' . $a194_values['google_wallet_service_account'] );
$a194_outputs['wallet log'] = get_option( PassCaptain\WalletPasses\Service::LOG_OPTION, array() );
check( 'wallet provider failures: wallet log keeps the code, not key/password/service account', array() === a194_found( $a194_outputs['wallet log'] ) && false !== strpos( json_encode( $a194_outputs['wallet log'] ), 'issue_failed' ) );

/* ============================================================================ Outgoing webhooks / mails */
echo "A194: failed outbound HTTP (webhooks, mail)\n";
$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='disabled'" );
$a194_sub = Dispatcher::create_subscription( 'https://hooks.a194.example/in?token=' . $a194_canaries['sms_http_secret'], array( 'order.paid' ) );
$a194_webhook_core = substr( (string) $a194_sub['secret'], 6 );
Redact::reset();
Dispatcher::emit( 'order.paid', 'a194-wh-1', array( 'id' => 'a194', 'email' => $a194_buyer ) );
http_reset();
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host for https://hooks.a194.example/in?token=' . $a194_canaries['sms_http_secret'] . ' X-WPCAP-Signature secret ' . $a194_sub['secret'] ) );
Dispatcher::process();
$a194_outputs['webhook last_error'] = $wpdb->get_col( $wpdb->prepare( "SELECT last_error FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d", $a194_sub['subscription_id'] ) );
check( 'failed outgoing webhook: last_error kept, URL token and signing secret redacted', array_filter( $a194_outputs['webhook last_error'] ) && array() === a194_found( $a194_outputs['webhook last_error'] ) && false !== strpos( implode( ' ', $a194_outputs['webhook last_error'] ), 'Could not resolve host' ) );
Dispatcher::set_status( $a194_sub['subscription_id'], false );

Monitor::record_mail_failure( 'ticket_mail', 'wp_mail_failed', 'SMTP Error: Could not authenticate with password=' . $a194_canaries['apple_pass_key_password'] . ' for ' . $a194_buyer );
$a194_outputs['mail failures'] = get_option( Monitor::MAIL_OPTION, array() );
check( 'mail failure record: SMTP password and recipient are not stored', array() === a194_found( $a194_outputs['mail failures'] ) );

/* ============================================================================ Database errors */
echo "A194: database errors\n";
$a194_break = static function ( $sql ) {
	if ( preg_match( '/^\s*INSERT INTO \S+wpcap_waitlist\b/i', $sql ) || preg_match( '/^\s*INSERT INTO \S+wpcap_orders\b/i', $sql ) ) {
		// The failing statement names the canary and the buyer: MySQL echoes both in its error text.
		return "INSERT INTO a194_missing_{$GLOBALS['a194_canaries']['stripe_secret_key']} (x) VALUES ('" . $GLOBALS['a194_buyer'] . "')";
	}
	return $sql;
};
add_filter( 'query', $a194_break );
$a194_wl_type = make_type( 0, 10, 2500 );
$a194_wl_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a194_wl_type" );
$a194_wl_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a194_wl_type" );
$a194_db1 = a194_rest( '/events/(?P<event_id>\d+)/waitlist', 'POST', array( 'event_id' => $a194_wl_event, 'occurrence_id' => $a194_wl_occ, 'ticket_type_id' => $a194_wl_type, 'email' => $a194_buyer ) );
$a194_db2 = a194_rest( '/checkout', 'POST', array(), array( 'email' => $a194_buyer, 'items' => array( array( 'ticket_type_id' => $a194_type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'a194-db-' . uniqid() ) );
remove_filter( 'query', $a194_break );
$a194_outputs['db errors'] = a194_text( $a194_db1['res'] ) . a194_text( $a194_db2['res'] );
check( 'database error in waitlist join and checkout: clean error answer without SQL error text, secrets or buyer e-mail', is_wp_error( $a194_db1['res'] ) && is_wp_error( $a194_db2['res'] ) && $a194_db1['status'] >= 400 && $a194_db2['status'] >= 400 && array() === a194_found( $a194_outputs['db errors'] ) && false === stripos( $a194_outputs['db errors'], 'a194_missing' ) && ! $a194_db1['issues'] && ! $a194_db2['issues'] );

/* ============================================================================ Uncaught exceptions */
echo "A194: uncaught exceptions in REST callbacks and jobs\n";
$GLOBALS['wpcap_test_routes']['pass-captain/v1/a194-boom'] = array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static function ( $request ) {
	$secret = $GLOBALS['a194_values']['stripe_secret_key'];
	( static function ( $key ) { throw new RuntimeException( 'Provider rejected key ' . $key . ' for ' . $GLOBALS['a194_buyer'] ); } )( $secret );
} );
$a194_boom = a194_rest( '/a194-boom', 'POST' );
$a194_outputs['boom'] = a194_text( $a194_boom['res'] );
check( 'exception in a REST callback: generic 500 with reference, nothing printed', 500 === $a194_boom['status'] && 'wpcap_internal_error' === $a194_boom['res']->get_error_code() && '' !== (string) ( $a194_boom['res']->get_error_data()['reference'] ?? '' ) && '' === $a194_boom['output'] && array() === a194_found( $a194_outputs['boom'] ) && false === strpos( $a194_outputs['boom'], 'RuntimeException' ) );
$a194_thrower = static function () { throw new LogicException( 'payload filter crashed with ' . $GLOBALS['a194_values']['mollie_api_key'] ); };
add_filter( 'wpcap_checkout_payload_keys', $a194_thrower );
$a194_boom2 = a194_rest( '/checkout', 'POST', array(), array( 'email' => 'x@example.com', 'items' => array() ) );
remove_filter( 'wpcap_checkout_payload_keys', $a194_thrower );
check( 'exception thrown inside the real checkout callback is caught the same way', 500 === $a194_boom2['status'] && 'wpcap_internal_error' === $a194_boom2['res']->get_error_code() && array() === a194_found( a194_text( $a194_boom2['res'] ) ) );
$a194_log_text = a194_log();
check( 'error log names the exception class and reference, without secrets or stack trace', false !== strpos( $a194_log_text, 'RuntimeException' ) && false !== strpos( $a194_log_text, (string) $a194_boom['res']->get_error_data()['reference'] ) && false === strpos( $a194_log_text, '#0 ' ) && false === stripos( $a194_log_text, 'stack trace' ) && array() === a194_found( $a194_log_text ) );
$a194_endpoints = Redact::guard_endpoints( array( '/pass-captain/v1/x' => array( array( 'methods' => 'GET', 'callback' => 'strlen', 'permission_callback' => '__return_true' ), 'namespace' => 'pass-captain/v1' ), '/wp/v2/posts' => array( array( 'methods' => 'GET', 'callback' => 'strlen' ) ) ) );
check( 'rest_endpoints guard wraps only the plugin namespace and keeps route options', $a194_endpoints['/pass-captain/v1/x'][0]['callback'] instanceof Closure && 'strlen' === $a194_endpoints['/wp/v2/posts'][0]['callback'] && 'pass-captain/v1' === $a194_endpoints['/pass-captain/v1/x']['namespace'] );

$a194_job_filter = static function ( $handled, $type ) {
	if ( 'a194_crash' === $type ) { throw new RuntimeException( 'job crashed: ' . $GLOBALS['a194_values']['twilio_auth_token'] ); }
	if ( 'a194_fail' === $type ) { return new WP_Error( 'a194_fail', 'gateway said Bearer ' . $GLOBALS['a194_canaries']['mollie_api_key'] . 'abcdef' ); }
	return $handled;
};
add_filter( 'wpcap_process_job', $a194_job_filter, 10, 2 );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2099-01-01 00:00:00' WHERE status='queued'" ); // Only the jobs of this test run now.
Jobs::enqueue( 'a194-crash', 'a194_crash', array() );
Jobs::enqueue( 'a194-fail', 'a194_fail', array() );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET attempts=7 WHERE job_type IN ('a194_crash','a194_fail')" );
Jobs::process_due( 5 );
remove_filter( 'wpcap_process_job', $a194_job_filter, 10 );
$a194_jobs = $wpdb->get_results( "SELECT job_type,status,last_error FROM {$p}wpcap_jobs WHERE job_type IN ('a194_crash','a194_fail')" );
$a194_outputs['jobs'] = $a194_jobs;
check( 'crashing and failing jobs reach dead_letter with a redacted last_error (no stuck claim)', 2 === count( $a194_jobs ) && 2 === count( array_filter( $a194_jobs, static fn( $j ) => 'dead_letter' === $j->status && '' !== (string) $j->last_error ) ) && array() === a194_found( $a194_jobs ) && false !== strpos( json_encode( $a194_jobs ), 'RuntimeException' ) );

/* ============================================================================ Collect all outputs */
echo "A194: no canary in any output\n";
Forms::flash( 'error', 'Erstattung fehlgeschlagen: ' . $a194_outputs['refund error'] . ' raw ' . $a194_values['stripe_secret_key'] );
$a194_notices = json_encode( get_transient( 'wpcap_admin_notices_1' ) );
ob_start();
Secrets::admin_notice();
SiteHealth::admin_notice();
$a194_notices .= (string) ob_get_clean();
check( 'admin notices: flashed provider error is redacted', array() === a194_found( $a194_notices ) );
$a194_audit = implode( "\n", $wpdb->get_col( "SELECT CONCAT(action,' ',details) FROM {$p}wpcap_audit_logs WHERE audit_id>$a194_audit_start" ) );
Audit::record( 'a194_direct', 'order', 1, array( 'secret' => $a194_values['stripe_webhook_secret'], 'message' => 'failed with ' . $a194_values['mollie_api_key'], 'card' => $a194_card ) );
$a194_direct = (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='a194_direct' ORDER BY audit_id DESC LIMIT 1" );
check( 'audit rows of all forced errors carry no canary; a direct Audit::record() with secrets is redacted', array() === a194_found( $a194_audit ) && array() === a194_found( $a194_direct ) && false !== strpos( $a194_direct, 'failed with' ) );
$a194_mails = json_encode( array_slice( $GLOBALS['wpcap_test_mails'], $a194_mail_start ) );
$a194_mail_secret_hits = array_diff( a194_found( $a194_mails ), array( 'buyer_email' ) ); // The buyer may receive own mails.
check( 'mail bodies sent during the forced errors carry no secret', array() === $a194_mail_secret_hits );
$a194_health = json_encode( SiteHealth::run_all() ) . json_encode( SiteHealth::debug_information( array() ) ) . json_encode( Secrets::debug_information( array() ) );
foreach ( array_keys( Queues::available() ) as $a194_queue ) { $a194_health .= json_encode( Queues::failed_items( $a194_queue, 100 ) ); }
check( 'Site Health tests/info and the operations queue lists carry no canary', array() === a194_found( $a194_health ) );
$a194_columns = '';
foreach ( array( 'wpcap_jobs' => 'last_error', 'wpcap_sms_messages' => 'last_error', 'wpcap_webhook_deliveries' => 'last_error', 'wpcap_refunds' => 'error_message', 'wpcap_messages' => 'last_error' ) as $a194_table => $a194_col ) {
	if ( PassCaptain\Privacy::has_table( $a194_table ) ) { $a194_columns .= implode( "\n", $wpdb->get_col( "SELECT $a194_col FROM {$p}{$a194_table} WHERE $a194_col IS NOT NULL AND $a194_col<>''" ) ); }
}
check( 'job/dead-letter, SMS, webhook, message and refund error columns carry no canary', array() === a194_found( $a194_columns ) );
$a194_log_text = a194_log();
check( 'PHP error log of the whole run carries no canary', array() === a194_found( $a194_log_text ) );
check( 'collected REST/provider error outputs carry no canary', array() === a194_found( $a194_outputs ) );

/* ============================================================================ Fuzzing */
echo "A194: fuzzing the public REST inputs\n";
$GLOBALS['wpcap_test_transients'] = array();
$a194_long = str_repeat( 'A', 70000 );
$a194_fuzz = array(
	'null' => null, 'true' => true, 'false' => false, 'zero' => 0, 'negative' => -1, 'int max' => PHP_INT_MAX, 'float' => 1.5, 'nan string' => 'NaN',
	'empty' => '', 'space' => ' ', 'oversized' => $a194_long, 'nul byte' => "a\0b", 'invalid utf8' => "\xC3\x28\xFF", 'emoji' => "\u{1F600}\u{1F468}\u{200D}\u{1F469}",
	'rtl override' => "\u{202E}gnp.exe", 'combining' => "e\u{0301}\u{0301}\u{0301}", 'sql' => "' OR 1=1 -- ", 'html' => '<script>alert(1)</script>',
	'list' => array( 1, 2, 3 ), 'map' => array( 'a' => array( 'b' => array( 'c' => 'd' ) ) ), 'deep' => json_decode( str_repeat( '[', 200 ) . str_repeat( ']', 200 ), true ) ?? array(), 'json string' => '{"x":1}', 'many' => array_fill( 0, 5000, array( 'ticket_type_id' => 1, 'quantity' => 1 ) ),
);
$a194_fuzz_targets = array(
	'checkout' => static function ( $field, $value ) use ( $a194_type ) {
		$body = array( 'email' => 'fuzz' . ( ++$GLOBALS['a194_seq'] ) . '@example.com', 'items' => array( array( 'ticket_type_id' => $a194_type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'fz-' . uniqid(), 'accept_terms' => true );
		if ( 'items.0' === $field ) { $body['items'] = array( $value ); } elseif ( 'items.0.quantity' === $field ) { $body['items'][0]['quantity'] = $value; } elseif ( 'items.0.ticket_type_id' === $field ) { $body['items'][0]['ticket_type_id'] = $value; } else { $body[ $field ] = $value; }
		return a194_rest( '/checkout', 'POST', array(), $body );
	},
	'check-in' => static function ( $field, $value ) {
		$params = array( 'token' => 'fuzz-token', 'source' => 'web' );
		$params[ $field ] = $value;
		return a194_rest( '/tickets/check-in', 'POST', $params );
	},
	'waitlist' => static function ( $field, $value ) use ( $a194_wl_event, $a194_wl_occ, $a194_wl_type ) {
		$params = array( 'event_id' => $a194_wl_event, 'occurrence_id' => $a194_wl_occ, 'ticket_type_id' => $a194_wl_type, 'email' => 'wl' . ( ++$GLOBALS['a194_seq'] ) . '@example.com', 'quantity' => 1 );
		$params[ $field ] = $value;
		return a194_rest( '/events/(?P<event_id>\d+)/waitlist', 'POST', $params );
	},
	'waitlist accept' => static function ( $field, $value ) {
		$params = array( 'waitlist_id' => 999999, 'token' => 'x', 'provider' => 'manual' );
		$params[ $field ] = $value;
		return a194_rest( '/waitlist/(?P<waitlist_id>\d+)/accept', 'POST', $params );
	},
);
$a194_partner = Partners::save( array( 'name' => 'A194 Partner', 'currency' => 'EUR', 'role' => 'organizer', 'settlement_start' => gmdate( 'Y-m' ) . '-01' ) );
Partners::set_events( $a194_partner, array( (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a194_type" ) ) );
$a194_partner_key = PartnerKeys::issue( $a194_partner, array( 'orders:write' ) );
if ( is_array( $a194_partner_key ) ) {
	$a194_fuzz_targets['partner orders'] = static function ( $field, $value ) use ( $a194_type, $a194_partner_key ) {
		$body = array( 'mode' => 'reserve', 'items' => array( array( 'ticket_type_id' => $a194_type, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'p@example.com' ) );
		if ( 'items.0.quantity' === $field ) { $body['items'][0]['quantity'] = $value; } elseif ( 'buyer.email' === $field ) { $body['buyer']['email'] = $value; } else { $body[ $field ] = $value; }
		$GLOBALS['wpcap_test_transients'] = array(); // Rate limits are not under test here.
		return a194_rest( '/partner/orders', 'POST', array(), $body, array( 'Authorization' => 'Bearer ' . $a194_partner_key['key'], 'Idempotency-Key' => 'fz-' . uniqid() ) );
	};
}
check( 'fuzz setup: partner key issued', is_array( $a194_partner_key ) );
$a194_seq = 0;
// Optional free-text fields may accept a fuzz string (2xx); missing-equivalent values (null, '', ' ')
// may fall back to the default everywhere. All other fuzz values must be refused with a 4xx.
$a194_tolerant = array(
	'checkout' => array( 'idempotency_key', 'attendees', 'campaign_codes', 'code', 'voucher_code', 'billing', 'locale', 'terms_version' ),
	'partner orders' => array( 'external_reference' ),
);
$a194_fields = array(
	'checkout' => array( 'email', 'items', 'items.0', 'items.0.quantity', 'items.0.ticket_type_id', 'provider', 'idempotency_key', 'attendees', 'campaign_codes', 'code', 'voucher_code', 'billing', 'locale', 'terms_version' ),
	'check-in' => array( 'token', 'source', 'idempotency_key', 'occurrence_id' ),
	'waitlist' => array( 'event_id', 'occurrence_id', 'ticket_type_id', 'email', 'quantity' ),
	'waitlist accept' => array( 'waitlist_id', 'token', 'provider', 'accept_terms' ),
	'partner orders' => array( 'mode', 'items', 'items.0.quantity', 'buyer', 'buyer.email', 'external_reference' ),
);
$a194_bad = array();
$a194_calls = 0;
foreach ( $a194_fuzz_targets as $a194_target => $a194_call ) {
	foreach ( $a194_fields[ $a194_target ] as $a194_field ) {
		foreach ( $a194_fuzz as $a194_label => $a194_value ) {
			$GLOBALS['wpcap_test_transients'] = array();
			$a194_r = $a194_call( $a194_field, $a194_value );
			++$a194_calls;
			$a194_ok = $a194_r['res'] instanceof WP_Error && $a194_r['status'] >= 400 && $a194_r['status'] < 500;
			if ( ! $a194_ok && ! $a194_r['res'] instanceof WP_Error && $a194_r['status'] < 300 && ( in_array( $a194_value, array( null, '', ' ' ), true ) || in_array( $a194_field, $a194_tolerant[ $a194_target ] ?? array(), true ) ) ) { $a194_ok = true; }
			$a194_text = a194_text( $a194_r['res'] );
			$a194_clean = ! $a194_r['issues'] && '' === $a194_r['output'] && false === strpos( $a194_text, '#0 ' ) && false === stripos( $a194_text, 'stack trace' ) && false === stripos( $a194_text, '.php' ) && strlen( $a194_text ) < 4000;
			if ( ! $a194_ok || ! $a194_clean ) {
				$a194_bad[] = "$a194_target.$a194_field=$a194_label → " . $a194_r['status'] . ' ' . substr( $a194_text, 0, 140 ) . ( $a194_r['issues'] ? ' [' . substr( $a194_r['issues'][0], 0, 160 ) . ']' : '' ) . ( '' !== $a194_r['output'] ? ' [output]' : '' );
			}
		}
	}
}
// The harness reaches the business logic: the unmodified request of each target succeeds.
$a194_base = array(
	'checkout' => $a194_fuzz_targets['checkout']( 'provider', 'manual' ),
	'waitlist' => $a194_fuzz_targets['waitlist']( 'quantity', 1 ),
	'partner orders' => isset( $a194_fuzz_targets['partner orders'] ) ? $a194_fuzz_targets['partner orders']( 'mode', 'reserve' ) : array( 'status' => 0, 'issues' => array() ),
);
check( 'fuzz baseline: valid checkout, waitlist and partner requests succeed (' . implode( ', ', array_map( static fn( $r ) => $r['status'], $a194_base ) ) . ')', 201 === $a194_base['checkout']['status'] && $a194_base['waitlist']['status'] < 300 && ! is_wp_error( $a194_base['waitlist']['res'] ) && 201 === $a194_base['partner orders']['status'] && ! array_filter( array_column( $a194_base, 'issues' ) ) );
$a194_check_in = $a194_fuzz_targets['check-in']( 'token', str_repeat( 'ab', 32 ) );
check( 'fuzz baseline: unknown ticket code at check-in is a clean 404', 404 === $a194_check_in['status'] && ! $a194_check_in['issues'] );
// Orders that fuzz values for optional fields (and the baselines) created must not keep their holds.
foreach ( $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE (customer_email LIKE 'fuzz%@example.com' OR customer_email='p@example.com') AND status NOT IN ('cancelled','expired')" ) as $a194_oid ) { Orders::cancel_order( (int) $a194_oid, 'a194' ); }
if ( getenv( 'A194_DEBUG' ) ) { fwrite( STDERR, implode( "\n", $a194_bad ) . "\n" ); }
check( "fuzzing: $a194_calls malformed requests all end in a clean 4xx WP_Error (no warning, notice, output or trace)" . ( $a194_bad ? ' – ' . count( $a194_bad ) . ' bad: ' . implode( ' | ', array_slice( $a194_bad, 0, 12 ) ) : '' ), ! $a194_bad );
$a194_json = a194_rest( '/checkout', 'POST', array(), '{"email": "x@example.com", "items": [' );
check( 'checkout with a truncated JSON body: clean 4xx', $a194_json['res'] instanceof WP_Error && $a194_json['status'] >= 400 && $a194_json['status'] < 500 && ! $a194_json['issues'] );
check( 'fuzzing left the PHP error log free of secrets', array() === a194_found( a194_log() ) );

/* ============================================================================ Restore */
foreach ( $a194_stored_before as $a194_key => $a194_value ) { Secrets::set( $a194_key, false === $a194_value ? '' : (string) $a194_value ); }
$GLOBALS['wpcap_test_options'] = $a194_options_before;
Redact::reset();
$GLOBALS['wpcap_test_user_id'] = $a194_user_before;
$GLOBALS['wpcap_test_denied_caps'] = $a194_caps_before;
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
ini_set( 'error_log', (string) $a194_log_ini['error_log'] );
ini_set( 'log_errors', (string) $a194_log_ini['log_errors'] );
$a194_done = true;
@unlink( $a194_log );
unset( $GLOBALS['wpcap_test_routes']['pass-captain/v1/a194-boom'] );
