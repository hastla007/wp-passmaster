<?php
/**
 * A584: a rotated salt / changed WPCAP_WALLET_ENCRYPTION_KEY must not void every wallet pass, and
 * administrators are warned (Site Health + notice). Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\WalletPasses\GoogleProvider;
use PassCaptain\WalletPasses\Health;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;
use PassCaptain\WalletPasses\WebService;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A584: undecryptable wallet secrets are not voided passes; key warnings\n";
global $wpdb;
$p = $wpdb->prefix;
$a584 = wpr7_wallet_enable();
$a584_type = make_type( 10, 10, 0 );
$a584_r = Orders::create( array( 'email' => 'a584@example.com', 'items' => array( array( 'ticket_type_id' => $a584_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a584-1' ) );
$a584_ticket = tickets( $a584_r['order_id'] )[0];
$a584_tid = (int) $a584_ticket->ticket_id;
$a584_mail = wpr7_ticket_mail( 'a584@example.com' );
$a584_pj = json_decode( wpr7_unzip( $a584_mail['files'][ 'ticket-' . substr( $a584_ticket->public_id, 0, 12 ) . '.pkpass' ] ?? '' )['pass.json'] ?? '{}', true );
$a584_auth = (string) ( $a584_pj['authenticationToken'] ?? '' );
Service::flush();
check( 'A584 setup: pass issued, key fingerprint remembered', '' !== $a584_auth && '' !== (string) get_option( Store::KEY_CHECK_OPTION, '' ) && ! Store::key_changed() );

$a584_issue_keys = static function () { return array_column( Health::issues(), 'status', 'key' ); };
check( 'A584: without WPCAP_WALLET_ENCRYPTION_KEY Site Health recommends an own key', 'recommended' === ( $a584_issue_keys()['key_from_salt'] ?? '' ) && 'salt' === Store::key_source() );
check( 'A584: Site Health test is registered and reports the recommendation', isset( Health::register_tests( array() )['direct']['wpcap_wallet'] ) && 'recommended' === Health::test()['status'] );

// Simulate a rotated salt: the stored box was encrypted with another key.
$a584_other = hash_hmac( 'sha256', 'wpcap-wallet-secretbox-v1', 'old-salt-value', true );
$a584_nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
$a584_box = 'v1:' . base64_encode( $a584_nonce . sodium_crypto_secretbox( json_encode( array( 'code' => 'x', 'auth' => 'y' ) ), $a584_nonce, $a584_other ) );
$wpdb->update( "{$p}wpcap_wallet_state", array( 'secret' => $a584_box ), array( 'ticket_id' => $a584_tid ) );
update_option( Store::KEY_CHECK_OPTION, 'deadbeefdeadbeef', false );
$a584_t = Service::ticket( $a584_tid );
check( 'A584: undecryptable secret is reported as such, the ticket is not voided', true === $a584_t['secret_unreadable'] && false === $a584_t['voided'] && Service::code_unavailable( $a584_t ) );
check( 'A584: changed key is critical in Site Health and shown as admin notice', 'critical' === ( $a584_issue_keys()['key_changed'] ?? '' ) && 'critical' === Health::test()['status'] );
ob_start(); Health::admin_notice(); $a584_notice = ob_get_clean();
check( 'A584: admin notice names the remedy', false !== strpos( $a584_notice, 'WPCAP_WALLET_ENCRYPTION_KEY' ) && false !== strpos( $a584_notice, 'notice-error' ) );

// A change (e.g. new location) bumps the tag; the device must keep its valid pass instead of a voided one.
$a584_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a584_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Anderswo' WHERE occurrence_id=$a584_occ" );
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
do_action( 'wpcap_occurrence_changed', $a584_occ, array( 'location' => array( 'Wien', 'Anderswo' ) ), 2 );
Service::flush();
$a584_req = new WP_REST_Request( 'GET' );
$a584_req->set_param( 'pass_type', 'pass.test.wpcap' );
$a584_req->set_param( 'serial', $a584_ticket->public_id );
$a584_req->set_header( 'Authorization', 'ApplePass ' . $a584_auth );
$a584_res = WebService::latest_pass( $a584_req );
check( 'A584: device fetch answers 304 (keeps the last valid pass) instead of a voided pass', ! is_wp_error( $a584_res ) && 304 === $a584_res->get_status() );
check( 'A584: wallet_passes rows stay active', 'active' === Store::passes( $a584_tid, 'apple' )[0]->status && 'active' === Store::passes( $a584_tid, 'google' )[0]->status );
$a584_patch = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
$a584_body = json_decode( $a584_patch[0]['body'] ?? '{}', true );
check( 'A584: Google object PATCH keeps state and barcode (neither INACTIVE nor removed)', $a584_patch && ! isset( $a584_body['state'] ) && ! isset( $a584_body['barcode'] ) );
http_reset();
http_push( array(), 404, 'eventTicketObject/' );
$a584_g = new GoogleProvider();
check( 'A584: missing Google object is not created without its code', true === $a584_g->update_pass( Service::ticket( $a584_tid ), (array) Store::passes( $a584_tid, 'google' )[0] ) && 0 === count( array_filter( http_requests(), static fn( $q ) => 'POST' === $q['method'] ) ) );
$a584_dl = WebService::download( ( static function () use ( $a584_ticket ) { $r = new WP_REST_Request( 'GET' ); $r->set_param( 'serial', $a584_ticket->public_id ); $r->set_param( 'sig', Service::download_sig( $a584_ticket->public_id, Store::state( (int) $a584_ticket->ticket_id ) ) ); return $r; } )() );
check( 'A584: download link answers 503 with a hint instead of a voided pass', is_wp_error( $a584_dl ) && 503 === ( $a584_dl->get_error_data()['status'] ?? 0 ) );

// A revoked ticket is still voided even though the code cannot be read.
PassCaptain\Admin\TicketsPage::revoke_ticket( array( 'ticket_id' => $a584_tid, 'reason' => 'Test' ) );
$a584_t = Service::ticket( $a584_tid );
check( 'A584: revocation still voids the pass', true === $a584_t['voided'] && ! Service::code_unavailable( $a584_t ) );
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
Service::flush();

update_option( Store::KEY_CHECK_OPTION, Store::key_check(), false );
wpr7_wallet_disable();
