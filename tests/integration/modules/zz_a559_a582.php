<?php
/**
 * A559/A582: wallet passes follow location, end, doors open, event/ticket type name and attendee
 * name corrections (not only a moved start or a cancellation). Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A559/A582: wallet passes follow occurrence, event, type and attendee changes\n";
global $wpdb;
$p = $wpdb->prefix;
$a559 = wpr7_wallet_enable();
$a559_type = make_type( 10, 10, 0 );
$a559_occ = $wpdb->get_row( "SELECT o.* FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id WHERE tt.ticket_type_id=$a559_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='2027-05-01 17:00:00', location='Alte Halle' WHERE occurrence_id=" . (int) $a559_occ->occurrence_id );
$a559_r = Orders::create( array( 'email' => 'a559@example.com', 'items' => array( array( 'ticket_type_id' => $a559_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a559-1' ) );
$a559_ticket = tickets( $a559_r['order_id'] )[0];
$a559_tid = (int) $a559_ticket->ticket_id;
Service::flush();
check( 'A559 setup: passes issued for the ticket', 2 === count( Store::passes( $a559_tid ) ) && wpr7_served_pass( $a559_tid ) );
$a559_tag = static function () use ( $a559_tid ) { return (int) Store::state( $a559_tid )->update_tag; };
$a559_aux = static function ( $pj, $group = 'secondaryFields' ) { return array_column( $pj['eventTicket'][ $group ] ?? array(), 'value', 'key' ); };

// Location + end + doors open through the admin form (no start change: only wpcap_occurrence_changed fires).
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a559['apns']->calls = array();
$a559_before = $a559_tag();
$a559_saved = EventsPage::save_occurrence( array( 'occurrence_id' => (int) $a559_occ->occurrence_id, 'timezone' => 'Europe/Vienna', 'starts_at' => '2027-05-01T19:00', 'ends_at' => '2027-05-01T22:30', 'doors_open_at' => '2027-05-01T18:15', 'capacity' => 0, 'status' => 'scheduled', 'location' => 'Neue Halle' ) );
Service::flush();
$a559_pj = wpr7_served_pass( $a559_tid );
$a559_sec = $a559_aux( $a559_pj );
check( 'A559: saving location/end/doors bumps the pass update tag', ! is_wp_error( $a559_saved ) && $a559_tag() > $a559_before );
check( 'A559: pass shows the new location and the doors-open time', 'Neue Halle' === ( $a559_sec['location'] ?? '' ) && '2027-05-01T18:15:00+02:00' === ( $a559_sec['doors'] ?? '' ) );
check( 'A559: expiry follows the new end time', '2027-05-02T10:30:00+02:00' === ( $a559_pj['expirationDate'] ?? '' ) );
$a559_class = array_values( array_filter( http_requests( 'eventTicketClass/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
$a559_class_body = json_decode( $a559_class[0]['body'] ?? '{}', true );
check( 'A559: Google class PATCHed with venue, end and doors open', 'Neue Halle' === ( $a559_class_body['venue']['name']['defaultValue']['value'] ?? '' ) && '2027-05-01T22:30:00+02:00' === ( $a559_class_body['dateTime']['end'] ?? '' ) && '2027-05-01T18:15:00+02:00' === ( $a559_class_body['dateTime']['doorsOpen'] ?? '' ) );
$a559_obj = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
check( 'A559: Google object updated too', 1 === count( $a559_obj ) );

// Event rename.
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a559_before = $a559_tag();
EventsPage::update_event( array( 'event_id' => (int) $a559_occ->event_id, 'name' => 'Sommerkonzert', 'status' => 'published' ) );
Service::flush();
$a559_pj = wpr7_served_pass( $a559_tid );
$a559_class = array_values( array_filter( http_requests( 'eventTicketClass/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
check( 'A582: event rename reaches the Apple pass', $a559_tag() > $a559_before && 'Sommerkonzert' === ( $a559_pj['eventTicket']['primaryFields'][0]['value'] ?? '' ) );
check( 'A582: event rename PATCHes the Google class name', 'Sommerkonzert' === ( json_decode( $a559_class[0]['body'] ?? '{}', true )['eventName']['defaultValue']['value'] ?? '' ) );
$a559_before = $a559_tag();
EventsPage::update_event( array( 'event_id' => (int) $a559_occ->event_id, 'name' => 'Sommerkonzert', 'status' => 'published' ) );
Service::flush();
check( 'A582: saving the event unchanged does not touch the passes', $a559_tag() === $a559_before );

// Ticket type rename.
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a559_before = $a559_tag();
EventsPage::save_ticket_type( array( 'ticket_type_id' => $a559_type, 'name' => 'Stehplatz', 'currency' => 'EUR', 'price' => '0', 'tax_rate' => '0', 'max_per_order' => 10, 'status' => 'active', 'capacity' => 10 ) );
Service::flush();
$a559_pj = wpr7_served_pass( $a559_tid );
$a559_obj = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
check( 'A582: ticket type rename reaches Apple and Google', $a559_tag() > $a559_before && 'Stehplatz' === ( $a559_aux( $a559_pj, 'auxiliaryFields' )['category'] ?? '' ) && 'Stehplatz' === ( json_decode( $a559_obj[0]['body'] ?? '{}', true )['ticketType']['defaultValue']['value'] ?? '' ) );

// Attendee name correction by staff (same holder, code unchanged).
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a559_before = $a559_tag();
$a559_gen = (int) Store::state( $a559_tid )->generation;
$a559_fix = TicketsPage::correct_attendee( array( 'ticket_id' => $a559_tid, 'attendee_name' => 'Erika Mustermann', 'attendee_email' => '', 'reason' => 'Tippfehler' ) );
Service::flush();
$a559_pj = wpr7_served_pass( $a559_tid );
check( 'A582: corrected attendee name is on the pass, same generation (code kept)', ! is_wp_error( $a559_fix ) && $a559_tag() > $a559_before && 'Erika Mustermann' === ( $a559_aux( $a559_pj, 'auxiliaryFields' )['attendee'] ?? '' ) && $a559_gen === (int) Store::state( $a559_tid )->generation && ! empty( $a559_pj['barcodes'] ) );

// Attendee name correction by the buyer in the order portal (no new holder → no rotation).
$a559_token = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $a559_token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $a559_r['order_id'] ) );
$a559_public = $wpdb->get_var( 'SELECT public_id FROM ' . $p . 'wpcap_orders WHERE order_id=' . (int) $a559_r['order_id'] );
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a559_before = $a559_tag();
$a559_assign = PassCaptain\TicketAssignments::assign( $a559_public, $a559_token, $a559_ticket->public_id, 'Erika M. Mustermann', '' );
Service::flush();
$a559_pj = wpr7_served_pass( $a559_tid );
check( 'A582: portal name correction reaches the pass without a new code', ! is_wp_error( $a559_assign ) && false === $a559_assign['code_rotated'] && $a559_tag() > $a559_before && 'Erika M. Mustermann' === ( $a559_aux( $a559_pj, 'auxiliaryFields' )['attendee'] ?? '' ) );

wpr7_wallet_disable();
