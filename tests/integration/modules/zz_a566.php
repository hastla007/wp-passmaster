<?php
/**
 * A566: Google Wallet only receives ticket data after the buyer used the save link: syncs never
 * send or create objects that were not saved (no POST on 404), class updates only for dates with a
 * saved object; an invalid ticket's object is replaced without barcode, holder name and seat
 * (revocation, privacy erasure) and names cleared by the retention run are cleared at Google too.
 * Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;
use PassCaptain\WalletPasses\WebService;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A566: Google Wallet data only after the buyer saves; erasure clears name and barcode\n";
global $wpdb;
$p = $wpdb->prefix;
$a566 = wpr7_wallet_enable();
$a566_type = make_type( 10, 10, 0 );
$a566_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a566_type" );
http_reset();
$a566_r = Orders::create( array( 'email' => 'a566@example.com', 'items' => array( array( 'ticket_type_id' => $a566_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a566-1' ) );
$a566_mail = wpr7_ticket_mail( 'a566@example.com' );
$a566_tid = (int) tickets( $a566_r['order_id'] )[0]->ticket_id;
Service::flush();
$a566_pass = Store::passes( $a566_tid, 'google' )[0] ?? null;
check( 'A566: schema has wallet_passes.remote_at; a new Google pass is not held by Google', Store::remote_ready() && $a566_pass && null === $a566_pass->remote_at && ! Store::is_remote( $a566_pass ) );
check( 'A566: issuing the ticket sends nothing to Google', 0 === count( http_requests( 'walletobjects' ) ) );

// A change before the buyer saved: nothing goes to Google (no PATCH with the data, no class, no POST).
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 404, 'walletobjects' ); }
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Neuer Ort' WHERE occurrence_id=$a566_occ" );
do_action( 'wpcap_occurrence_changed', $a566_occ, array( 'location' => array( 'Wien', 'Neuer Ort' ) ), 2 );
Service::flush();
check( 'A566: sync of a never-saved pass sends no ticket data and creates no object', 0 === count( http_requests( 'walletobjects' ) ) );
$a566_g = Service::provider( 'google' );
http_reset();
http_push( array(), 404, 'eventTicketObject/' );
check( 'A566: update_pass on a never-saved pass is a no-op', true === $a566_g->update_pass( Service::ticket( $a566_tid ), (array) Store::passes( $a566_tid, 'google' )[0] ) && 0 === count( http_requests() ) );

// The buyer uses the save link: the object is inserted on the click and marked as held by Google.
preg_match( '#(https://example\.test/wp-json/pass-captain/v1/wallet/google/save\S+)#', $a566_mail['body'], $a566_m );
parse_str( (string) parse_url( $a566_m[1] ?? '', PHP_URL_QUERY ), $a566_q );
$a566_req = new WP_REST_Request( 'GET' );
foreach ( $a566_q as $k => $v ) { $a566_req->set_param( $k, $v ); }
http_reset();
http_push( array(), 200, 'walletobjects/v1/eventTicketClass' );
http_push( array(), 200, 'walletobjects/v1/eventTicketObject' );
$a566_save = WebService::google_save( $a566_req );
check( 'A566: save link inserts the object and marks it as saved', ! is_wp_error( $a566_save ) && 302 === $a566_save->get_status() && null !== Store::passes( $a566_tid, 'google' )[0]->remote_at );

// From now on changes reach the saved object; a missing object is never re-created by a sync.
http_reset();
http_push( array(), 200, 'eventTicketClass/' );
http_push( array(), 404, 'eventTicketObject/' );
http_push( array(), 200, 'walletobjects/v1/eventTicketClass' );
http_push( array(), 200, 'walletobjects/v1/eventTicketObject' );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Dritter Ort' WHERE occurrence_id=$a566_occ" );
do_action( 'wpcap_occurrence_changed', $a566_occ, array( 'location' => array( 'Neuer Ort', 'Dritter Ort' ) ), 3 );
Service::flush();
$a566_patch = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
check( 'A566: saved object is PATCHed; on 404 nothing is POSTed (object deleted by the buyer stays deleted)', 1 === count( $a566_patch ) && 0 === count( array_filter( http_requests( 'walletobjects' ), static fn( $q ) => 'POST' === $q['method'] ) ) && 1 === count( array_filter( http_requests( 'eventTicketClass/' ), static fn( $q ) => 'PATCH' === $q['method'] ) ) );

// Attendee name: set, then cleared by the retention run → cleared at Google too.
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $a566_tid, 'buyer_email' => 'a566@example.com', 'attendee_email' => 'a566@example.com', 'attendee_name' => 'Alex Muster', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
http_reset();
http_push( array(), 200, 'eventTicketObject/' );
do_action( 'wpcap_ticket_attendee_updated', $a566_tid, 'assign' );
Service::flush();
$a566_named = json_decode( http_requests( 'eventTicketObject/' )[0]['body'] ?? '{}', true );
check( 'A566 setup: holder name reaches the saved object', 'Alex Muster' === ( $a566_named['ticketHolderName'] ?? '' ) );
$a566_heard = array();
add_action( 'wpcap_privacy_attendees_cleared', $a566_listener = static function ( $ids ) use ( &$a566_heard ) { $a566_heard = array_merge( $a566_heard, (array) $ids ); } );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS ) . "', ends_at=NULL WHERE occurrence_id=$a566_occ" );
$a566_step = new ReflectionMethod( PassCaptain\Privacy\Retention::class, 'step_attendees' );
$a566_step->setAccessible( true );
http_reset();
http_push( array(), 200, 'eventTicketObject/' );
http_push( array(), 200, 'eventTicketClass/' );
$a566_step->invoke( null, time(), microtime( true ) + 10 );
remove_filter( 'wpcap_privacy_attendees_cleared', $a566_listener );
Service::flush();
$a566_cleared = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
$a566_cleared_body = json_decode( $a566_cleared[0]['body'] ?? '{}', true );
check( 'A566: retention clearing the name fires wpcap_privacy_attendees_cleared', in_array( $a566_tid, array_map( 'intval', $a566_heard ), true ) );
check( 'A566: the name is cleared in the saved Google object as well', $a566_cleared && '' === ( $a566_cleared_body['ticketHolderName'] ?? 'missing' ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) . "' WHERE occurrence_id=$a566_occ" );

// Privacy erasure: the ticket is revoked and the saved object replaced without code and name.
$wpdb->update( "{$p}wpcap_ticket_assignments", array( 'attendee_name' => 'Alex Muster' ), array( 'ticket_id' => $a566_tid ) );
$GLOBALS['wpcap_test_filters']['wpcap_privacy_defer_upcoming'] = false;
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
PassCaptain\Privacy::erase( 'a566@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_privacy_defer_upcoming'] );
Service::flush();
$a566_put = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PUT' === $q['method'] ) );
$a566_put_body = json_decode( $a566_put[0]['body'] ?? '{}', true );
check( 'A566: erasure replaces the saved object (PUT) as INACTIVE without barcode, holder name and seat', $a566_put && 'INACTIVE' === ( $a566_put_body['state'] ?? '' ) && ! isset( $a566_put_body['barcode'] ) && ! isset( $a566_put_body['ticketHolderName'] ) && ! isset( $a566_put_body['seatInfo'] ) && false === strpos( (string) ( $a566_put[0]['body'] ?? '' ), 'Alex Muster' ) );

// Older generations after a code rotation are retired without data, only when they were saved.
$a566_r2 = Orders::create( array( 'email' => 'a566b@example.com', 'items' => array( array( 'ticket_type_id' => $a566_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a566-2' ) );
wpr7_ticket_mail( 'a566b@example.com' );
$a566_t2 = (int) tickets( $a566_r2['order_id'] )[0]->ticket_id;
Service::flush();
http_reset();
for ( $i = 0; $i < 6; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
Orders::resend_tickets( $a566_r2['order_id'] );
Service::flush();
check( 'A566: rotation of a never-saved pass sends nothing to Google', 0 === count( http_requests( 'walletobjects' ) ) && 'retired' === Store::passes( $a566_t2, 'google' )[0]->status );

// Legacy passes from before schema 3.10.18 keep being updated (never created).
$wpdb->query( "UPDATE {$p}wpcap_wallet_passes SET remote_at=NULL WHERE provider='google'" );
$a566_marked = PassCaptain\Installer::mark_legacy_google_passes( '3.10.17' );
check( 'A566: upgrade marks existing Google passes as possibly saved (they stay in sync)', $a566_marked > 0 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_passes WHERE provider='google' AND remote_at IS NULL" ) && 0 === PassCaptain\Installer::mark_legacy_google_passes( '3.10.18' ) );

wpr7_wallet_disable();
