<?php
// phpcs:ignoreFile
/**
 * A655: the fiskaltrust adapter never falls back to the sandbox silently: without endpoint_url/base_url
 * and without environment a fiskaltrust call is refused (no request), the sandbox is used only with
 * environment = "sandbox"/"test". HTTP is faked.
 *
 * Merged design: the former Fiscalization\Providers\HttpClient (THEIRS) is gone; every adapter builds
 * its endpoint itself and sends through Fiscalization\ProviderHttp (OURS). The fiskaltrust adapter id
 * is fiskaltrust_pos with the configuration keys credentials.cashbox_id/access_token, pos_system_id,
 * receipt_case, charge_item_case, payment_cases. efsta EFR has no implicit local default any more
 * (local services need the fiscal_allow_local_service opt-in), so a missing endpoint is an error there too.
 */
use PassCaptain\Fiscalization\Providers\EfstaEfrAdapter;
use PassCaptain\Fiscalization\Providers\FiskaltrustAdapter;
use PassCaptain\Fiscalization\ProviderHttp;

echo "A655: fiscal providers without silent sandbox fallback\n";
$a655_cfg = array( 'credentials' => array( 'cashbox_id' => 'cb-a655', 'access_token' => 'tok-a655' ), 'pos_system_id' => 'pos-a655' );
$a655_ft = new FiskaltrustAdapter();

http_reset();
$a655_efsta = ( new EfstaEfrAdapter() )->health_check( array( 'seller_id' => 1 ), array() );
check( 'A655: efsta without endpoint is an error (no implicit default, nothing sent)', is_wp_error( $a655_efsta ) && 'efsta_endpoint_missing' === $a655_efsta->get_error_code() && ! http_requests() );
$a655_local = ProviderHttp::validate_endpoint( 'http://127.0.0.1:5618' );
check( 'A655: a local EFR endpoint needs the explicit opt-in', is_wp_error( $a655_local ) );
add_filter( 'wpcap_fiscal_allow_local_service', $wpcap_fiscal_local_on = static function () { return true; } );
check( 'A655: with the opt-in the local EFR endpoint is accepted', true === ProviderHttp::validate_endpoint( 'http://127.0.0.1:5618' ) );
remove_filter( 'wpcap_fiscal_allow_local_service', $wpcap_fiscal_local_on );
check( 'A655: a public https endpoint is accepted', true === ProviderHttp::validate_endpoint( 'https://fiscal.example/v2/' ) );

http_reset();
$a655_r = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg );
check( 'A655: fiskaltrust without endpoint sends nothing (no sandbox)', is_wp_error( $a655_r ) && 'fiskaltrust_endpoint_missing' === $a655_r->get_error_code() && ! http_requests() );
$a655_doc = array( 'idempotency_key' => 'a655-doc', 'items' => array( array( 'tax_rate_bp' => 1000, 'quantity' => 1, 'gross_minor' => 1100 ) ), 'gross_minor' => 1100, 'payment_method' => 'card' );
$a655_submit = $a655_ft->submit( $a655_doc, array( 'seller_id' => 1 ), $a655_cfg + array( 'receipt_case' => 1, 'charge_item_case' => 2, 'payment_cases' => array( 'card' => 4 ) ) );
check( 'A655: a receipt is never signed against the sandbox by accident', is_wp_error( $a655_submit ) && 'fiskaltrust_endpoint_missing' === $a655_submit->get_error_code() && ! http_requests() );

http_reset();
http_push( array( 'Message' => 'ok' ), 200, 'possystem-api-sandbox.fiskaltrust.eu' );
$a655_s = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg + array( 'environment' => 'sandbox' ) );
check( 'A655: the sandbox only with environment = sandbox', ! is_wp_error( $a655_s ) && 1 === count( http_requests( 'possystem-api-sandbox.fiskaltrust.eu/v2/echo' ) ) );
http_reset();
http_push( array( 'Message' => 'ok' ), 200, 'fiscal-a655.example' );
$a655_p = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg + array( 'base_url' => 'https://fiscal-a655.example/v2' ) );
check( 'A655: a configured base_url is used', ! is_wp_error( $a655_p ) && 1 === count( http_requests( 'https://fiscal-a655.example/v2/echo' ) ) && ! http_requests( 'sandbox' ) );
http_reset();
http_push( array( 'Message' => 'ok' ), 200, 'possystem-api.fiskaltrust.eu' );
$a655_prod = $a655_ft->health_check( array( 'seller_id' => 1 ), $a655_cfg + array( 'environment' => 'production' ) );
check( 'A655: environment = production uses the production API, never the sandbox', ! is_wp_error( $a655_prod ) && 1 === count( http_requests( 'possystem-api.fiskaltrust.eu/v2/echo' ) ) && ! http_requests( 'sandbox' ) );
http_reset();
