<?php
// phpcs:ignoreFile
/**
 * A619: an older Google generation that is missing at Google (the buyer opened an older save link –
 * fallback JWT with the full object – but never finished it) is inserted as an empty INACTIVE object
 * when it is revoked, so that old link can no longer create an ACTIVE pass with the old code. The
 * same for an invalid ticket; a valid object is still never re-created (A566). Google is faked.
 */
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A619: revoked Google generation missing at Google becomes INACTIVE\n";
global $wpdb;
$p = $wpdb->prefix;
wpr7_wallet_enable();
$a619_type = make_type( 10, 10, 0 );
http_reset();
$a619_r = Orders::create( array( 'email' => 'a619@example.com', 'items' => array( array( 'ticket_type_id' => $a619_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a619-1' ) );
Service::flush();
$a619_tid = (int) tickets( $a619_r['order_id'] )[0]->ticket_id;
$a619_pass = Store::passes( $a619_tid, 'google' )[0];
// The fallback JWT was handed out (Service::google_save_url() marks the object as held by Google).
Store::mark_remote( 'google', $a619_pass->serial_number );
$a619_g = Service::provider( 'google' );
$a619_posts = static fn() => array_values( array_filter( http_requests( 'eventTicketObject' ), static fn( $q ) => 'POST' === $q['method'] ) );

http_reset();
http_push( array(), 404, 'eventTicketObject/' );
http_push( array( 'id' => $a619_pass->serial_number ), 200, 'walletobjects/v1/eventTicketObject' );
$a619_ok = $a619_g->revoke_pass( (array) Store::passes( $a619_tid, 'google' )[0] );
$a619_post = $a619_posts();
$a619_body = json_decode( $a619_post[0]['body'] ?? '{}', true );
check( 'A619: revoking a missing object inserts it as INACTIVE', true === $a619_ok && 1 === count( $a619_post ) && 'INACTIVE' === ( $a619_body['state'] ?? '' ) && $a619_pass->serial_number === ( $a619_body['id'] ?? '' ) && (string) $a619_pass->provider_ref === ( $a619_body['classId'] ?? '' ) );
check( 'A619: the inserted object carries no barcode and no personal data', ! isset( $a619_body['barcode'] ) && ! isset( $a619_body['ticketHolderName'] ) && ! isset( $a619_body['seatInfo'] ) );

http_reset();
http_push( array(), 404, 'eventTicketObject/' );
http_push( array(), 409, 'walletobjects/v1/eventTicketObject' );
check( 'A619: an object inserted meanwhile (409) counts as done', true === $a619_g->revoke_pass( (array) Store::passes( $a619_tid, 'google' )[0] ) );

http_reset();
http_push( array(), 404, 'eventTicketObject/' );
http_push( array(), 503, 'walletobjects/v1/eventTicketObject' );
check( 'A619: a failed insert is retried by the sync', false === $a619_g->revoke_pass( (array) Store::passes( $a619_tid, 'google' )[0] ) );

// An invalid (revoked) ticket missing at Google: also inserted INACTIVE.
$a619_ticket = Service::ticket( $a619_tid );
$a619_ticket['voided'] = true;
http_reset();
http_push( array(), 404, 'eventTicketObject/' );
http_push( array(), 200, 'walletobjects/v1/eventTicketObject' );
check( 'A619: an invalid ticket missing at Google is inserted INACTIVE', true === $a619_g->update_pass( $a619_ticket, (array) Store::passes( $a619_tid, 'google' )[0] ) && 'INACTIVE' === ( json_decode( $a619_posts()[0]['body'] ?? '{}', true )['state'] ?? '' ) );

// A valid ticket missing at Google is still never re-created (A566).
http_reset();
http_push( array(), 404, 'eventTicketObject/' );
check( 'A619/A566: a valid object missing at Google is not re-created', true === $a619_g->update_pass( Service::ticket( $a619_tid ), (array) Store::passes( $a619_tid, 'google' )[0] ) && ! $a619_posts() );

wpr7_wallet_disable();
http_reset();
