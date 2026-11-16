<?php
// phpcs:ignoreFile
/**
 * A655: the fiscal HttpClient/fiskaltrust adapter never falls back to the sandbox silently: without
 * base_url a fiskaltrust call is refused (no request), the sandbox is used only with environment =
 * "sandbox". The efsta adapter keeps its documented local EFR default. HTTP is faked.
 */
use PassCaptain\Fiscalization\Providers\FiskaltrustAdapter;
use PassCaptain\Fiscalization\Providers\HttpClient;

if ( ! interface_exists( 'PassCaptain\\Fiscalization\\AdapterInterface' ) ) {
	eval( 'namespace PassCaptain\\Fiscalization; interface AdapterInterface { const VERSION = "1.0"; }' );
}
echo "A655: fiscal providers without silent sandbox fallback\n";
$a655_cfg = array( 'credentials' => array( 'cashbox_id' => 'cb-a655', 'cashbox_access_token' => 'tok-a655' ), 'possystem_id' => 'pos-a655' );
$a655_ft = new FiskaltrustAdapter();

$a655_err = HttpClient::endpoint( array(), '', 'sign' );
check( 'A655: endpoint() without base_url and without default is an error', is_wp_error( $a655_err ) && 'provider_base_url_missing' === $a655_err->get_error_code() );
check( 'A655: request() passes that error on without sending', is_wp_error( HttpClient::request( $a655_err, 'GET' ) ) );
check( 'A655: a documented default (efsta local EFR) is still used', 'http://127.0.0.1:5618/state' === HttpClient::endpoint( array(), 'http://127.0.0.1:5618', 'state' ) );
check( 'A655: a configured base_url wins over the default', 'https://fiscal.example/v2/sign' === HttpClient::endpoint( array( 'base_url' => 'https://fiscal.example/v2/' ), 'http://127.0.0.1:5618', 'sign' ) );

http_reset();
$a655_r = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg );
check( 'A655: fiskaltrust without base_url sends nothing (no sandbox)', is_wp_error( $a655_r ) && 'provider_base_url_missing' === $a655_r->get_error_code() && ! http_requests() );
$a655_doc = array( 'idempotency_key' => 'a655-doc', 'items' => array( array( 'tax_rate_bp' => 1000, 'quantity' => 1, 'gross_minor' => 1100 ) ), 'gross_minor' => 1100, 'payment_method' => 'card' );
$a655_submit = $a655_ft->submit( $a655_doc, array( 'seller_id' => 1 ), $a655_cfg + array( 'ft_receipt_case' => 1, 'ft_charge_item_case' => 2, 'tax_cases' => array( '1000' => 3 ), 'payment_cases' => array( 'default' => 4 ) ) );
check( 'A655: a receipt is never signed against the sandbox by accident', is_wp_error( $a655_submit ) && 'provider_base_url_missing' === $a655_submit->get_error_code() && ! http_requests() );

http_reset();
http_push( array( 'Message' => 'ok' ), 200, 'possystem-api-sandbox.fiskaltrust.eu' );
$a655_s = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg + array( 'environment' => 'sandbox' ) );
check( 'A655: the sandbox only with environment = sandbox', ! is_wp_error( $a655_s ) && 1 === count( http_requests( 'possystem-api-sandbox.fiskaltrust.eu/v2/echo' ) ) );
http_reset();
http_push( array( 'Message' => 'ok' ), 200, 'fiscal-a655.example' );
$a655_p = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg + array( 'base_url' => 'https://fiscal-a655.example/v2' ) );
check( 'A655: a configured base_url is used', ! is_wp_error( $a655_p ) && 1 === count( http_requests( 'https://fiscal-a655.example/v2/echo' ) ) && ! http_requests( 'sandbox' ) );
http_reset();
