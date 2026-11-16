<?php
// phpcs:ignoreFile
/**
 * A423: the cached Google Wallet access token is bound to the service account (client_email,
 * token_uri, private key): a changed account fetches its own token; 401 and 403 drop the token.
 * Google is faked (HTTP queue).
 */
use PassCaptain\Secrets;
use PassCaptain\WalletPasses\GoogleProvider;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A423: Google Wallet token bound to the credentials\n";
wpr7_wallet_enable();
$a423_g = new GoogleProvider();
$a423_sa = Secrets::get( 'google_wallet_service_account' );
$a423_tokens = static fn() => count( http_requests( 'oauth2.googleapis.com' ) );

http_reset();
check( 'A423: the token of the current account is used from the cache', 'ya29.wpr7' === $a423_g->access_token() && 0 === $a423_tokens() );

$a423_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $a423_key, $a423_pem );
$a423_old_key = GoogleProvider::token_cache_key();
Secrets::set( 'google_wallet_service_account', json_encode( array( 'client_email' => 'other@test.iam.gserviceaccount.com', 'private_key' => $a423_pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ) ) );
check( 'A423: another service account has another cache key', GoogleProvider::token_cache_key() !== $a423_old_key );
http_reset();
http_push( array( 'access_token' => 'ya29.other', 'expires_in' => 3600 ), 200, 'oauth2.googleapis.com' );
check( 'A423: a changed service account never uses the previous token', 'ya29.other' === $a423_g->access_token() && 1 === $a423_tokens() );
$a423_assertion = form_body( http_requests( 'oauth2.googleapis.com' )[0] )['assertion'] ?? '';
check( 'A423: the new token is requested for the new account', false !== strpos( (string) base64_decode( strtr( explode( '.', $a423_assertion )[1] ?? '', '-_', '+/' ) ), 'other@test.iam.gserviceaccount.com' ) );

http_reset();
http_push( array( 'error' => array( 'code' => 403 ) ), 403, 'walletobjects.googleapis.com' );
$a423_res = $a423_g->api( 'GET', 'eventTicketClass/a423', array() );
http_push( array( 'access_token' => 'ya29.fresh', 'expires_in' => 3600 ), 200, 'oauth2.googleapis.com' );
check( 'A423: 403 drops the cached token, the next call fetches a new one', 403 === $a423_res['code'] && 'ya29.fresh' === $a423_g->access_token() && 1 === $a423_tokens() );

Secrets::set( 'google_wallet_service_account', (string) $a423_sa );
check( 'A423: switching back finds the first account\'s token again', 'ya29.wpr7' === $a423_g->access_token() );
wpr7_wallet_disable();
http_reset();
