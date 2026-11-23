<?php
// phpcs:ignoreFile
/**
 * A648: wallet hardening – a device registration changes the push token of that serial only; raw
 * output (serve_raw) only for the module's own RawResponse, never for a "__wpcap_raw" key in another
 * response; signed wallet links use their own HMAC key (links signed before stay valid).
 */
use PassCaptain\WalletPasses\RawResponse;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;
use PassCaptain\WalletPasses\WebService;

echo "A648: wallet push tokens, raw responses, link key\n";
global $wpdb;
$p = $wpdb->prefix;
$a648_dev = 'a648dev' . substr( md5( uniqid() ), 0, 8 );
Store::register_device( $a648_dev, 'pass.test.wpcap', 'A648SERIALONE', 'tokenone00000001', hash( 'sha256', 'auth-one' ) );
Store::register_device( $a648_dev, 'pass.test.wpcap', 'A648SERIALTWO', 'tokentwo00000002', hash( 'sha256', 'auth-two' ) );
$a648_again = Store::register_device( $a648_dev, 'pass.test.wpcap', 'A648SERIALTWO', 'tokenthree000003', hash( 'sha256', 'auth-two' ) );
$a648_tokens = array_column( (array) $wpdb->get_results( $wpdb->prepare( "SELECT serial_number, push_token FROM {$p}wpcap_wallet_registrations WHERE device_library_id=%s ORDER BY serial_number", $a648_dev ) ), null, 'serial_number' );
check( 'A648: re-registering one serial updates its push token', false === $a648_again && 'tokenthree000003' === $a648_tokens['A648SERIALTWO']->push_token );
check( 'A648: the push token of the device\'s other passes is not changed', 'tokenone00000001' === $a648_tokens['A648SERIALONE']->push_token );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_wallet_registrations WHERE device_library_id=%s", $a648_dev ) );

// serve_raw: only RawResponse is sent raw.
$a648_foreign = new WP_REST_Response( array( '__wpcap_raw' => 'evil', 'headers' => array( 'Location' => 'https://evil.example/' ) ), 200 );
ob_start();
$a648_served = WebService::serve_raw( false, $a648_foreign );
$a648_out = ob_get_clean();
check( 'A648: a foreign response with a "__wpcap_raw" key is not served raw', false === $a648_served && '' === $a648_out );
$a648_raw = new RawResponse( "PK\x03\x04bytes", array( 'Content-Type' => 'application/vnd.apple.pkpass', "X-Bad\r\nInjected" => "a\r\nSet-Cookie: x=1" ), 200 );
ob_start();
$a648_served = WebService::serve_raw( false, $a648_raw );
$a648_out = ob_get_clean();
check( 'A648: the module\'s RawResponse is served raw', true === $a648_served && "PK\x03\x04bytes" === $a648_out && 200 === $a648_raw->get_status() );
check( 'A648: header names and values of a RawResponse carry no line breaks', array( 'Content-Type' => 'application/vnd.apple.pkpass', 'X-BadInjected' => 'aSet-Cookie: x=1' ) === $a648_raw->raw_headers() );

// Link signatures: own HMAC key, legacy signatures still accepted.
$a648_state = (object) array( 'auth_token_hash' => hash( 'sha256', 'a648-token' ) );
$a648_payload = 'dl|A648SERIAL|' . $a648_state->auth_token_hash;
$a648_legacy = hash_hmac( 'sha256', $a648_payload, Store::key() );
check( 'A648: the link key differs from the encryption key', Store::link_key() !== Store::key() && 32 === strlen( Store::link_key() ) );
check( 'A648: new download links are signed with the link key', hash_hmac( 'sha256', $a648_payload, Store::link_key() ) === Service::download_sig( 'A648SERIAL', $a648_state ) && $a648_legacy !== Service::download_sig( 'A648SERIAL', $a648_state ) );
check( 'A648: links signed before (encryption key) stay valid, forged ones do not', Service::download_sig_valid( 'A648SERIAL', $a648_state, $a648_legacy ) && Service::download_sig_valid( 'A648SERIAL', $a648_state, Service::download_sig( 'A648SERIAL', $a648_state ) ) && ! Service::download_sig_valid( 'A648SERIAL', $a648_state, str_repeat( '0', 64 ) ) && ! Service::download_sig_valid( 'A648SERIAL', $a648_state, '' ) );
