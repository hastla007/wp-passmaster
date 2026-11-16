<?php
/**
 * A583: short Google save links (objects inserted on click, JWT with IDs only, < 1,800 characters).
 * A580: ticket mail size – split PDFs within an attachment budget, "add all" wallet links for large
 * orders instead of one .pkpass and link per ticket. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Jwt;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;
use PassCaptain\WalletPasses\WebService;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A583/A580: short Google links, mail size limits, add-all wallet links\n";
global $wpdb;
$p = $wpdb->prefix;
$a583 = wpr7_wallet_enable();
$a583_req = static function ( $url ) {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	$r = new WP_REST_Request( 'GET' );
	foreach ( $q as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
};
$a583_links = static function ( $body, $route ) { preg_match_all( '#(https://example\.test/wp-json/pass-captain/v1/wallet/' . $route . '\S+)#', $body, $m ); return $m[1]; };
$a583_jwt = static function ( $location ) {
	$jwt = substr( (string) $location, strlen( 'https://pay.google.com/gp/v/save/' ) );
	return array( $jwt, json_decode( Jwt::b64url_decode( explode( '.', $jwt . '..' )[1] ), true ) );
};

/* ---- A583: one ticket ---- */
$a583_type = make_type( 100, 100, 0 );
http_reset();
$a583_r = Orders::create( array( 'email' => 'a583@example.com', 'items' => array( array( 'ticket_type_id' => $a583_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a583-1' ) );
$a583_mail = wpr7_ticket_mail( 'a583@example.com' );
$a583_g = $a583_links( $a583_mail['body'], 'google/save' );
check( 'A583: ticket mail carries one short Google link per ticket and no JWT', 2 === count( $a583_g ) && false === strpos( $a583_mail['body'], 'pay.google.com' ) && max( array_map( 'strlen', $a583_g ) ) < 300 );
check( 'A583: nothing is sent to Google while the mail is built', 0 === count( http_requests( 'walletobjects' ) ) );
http_reset();
http_push( array(), 200, 'walletobjects/v1/eventTicketClass' );
http_push( array(), 200, 'walletobjects/v1/eventTicketObject' );
$a583_res = WebService::google_save( $a583_req( $a583_g[0] ) );
list( $a583_token, $a583_claims ) = $a583_jwt( is_wp_error( $a583_res ) ? '' : $a583_res->get_data()['headers']['Location'] ?? '' );
$a583_objects = $a583_claims['payload']['eventTicketObjects'] ?? array();
$a583_posted = json_decode( http_requests( 'walletobjects/v1/eventTicketObject' )[0]['body'] ?? '{}', true );
check( 'A583: click inserts class and object through the API, then 302 to Google', ! is_wp_error( $a583_res ) && 302 === $a583_res->get_status() && 2 === count( array_filter( http_requests( 'walletobjects' ), static fn( $q ) => 'POST' === $q['method'] ) ) && 'ACTIVE' === ( $a583_posted['state'] ?? '' ) && '' !== ( $a583_posted['barcode']['value'] ?? '' ) );
check( 'A583: save JWT only carries the object ID (no class, no barcode) and is far below 1,800 characters (measured: ' . strlen( $a583_token ) . ')', 1 === count( $a583_objects ) && array( 'id' ) === array_keys( $a583_objects[0] ) && ! isset( $a583_claims['payload']['eventTicketClasses'] ) && strlen( $a583_token ) < 1800 && $a583_objects[0]['id'] === $a583_posted['id'] );
http_reset();
http_push( array(), 409, 'walletobjects/v1/eventTicketClass' );
http_push( array(), 200, 'eventTicketClass/' );
http_push( array(), 409, 'walletobjects/v1/eventTicketObject' );
http_push( array(), 200, 'eventTicketObject/' );
$a583_again = WebService::google_save( $a583_req( $a583_g[0] ) );
check( 'A583: second click (object exists, 409) updates it and still redirects', ! is_wp_error( $a583_again ) && 302 === $a583_again->get_status() && 2 === count( array_filter( http_requests( 'walletobjects' ), static fn( $q ) => 'PATCH' === $q['method'] ) ) );
$a583_bad = WebService::google_save( $a583_req( preg_replace( '/sig=[0-9a-f]{4}/', 'sig=0000', $a583_g[0] ) ) );
check( 'A583: tampered link → 404', is_wp_error( $a583_bad ) && 404 === ( $a583_bad->get_error_data()['status'] ?? 0 ) );
http_reset();
$a583_down = WebService::google_save( $a583_req( $a583_g[1] ) );
list( , $a583_fat ) = $a583_jwt( is_wp_error( $a583_down ) ? '' : $a583_down->get_data()['headers']['Location'] ?? '' );
check( 'A583: API unreachable → single ticket falls back to the full JWT', ! is_wp_error( $a583_down ) && isset( $a583_fat['payload']['eventTicketClasses'][0], $a583_fat['payload']['eventTicketObjects'][0]['barcode'] ) );
http_reset();
for ( $i = 0; $i < 12; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
Orders::resend_tickets( $a583_r['order_id'] );
Service::flush();
$a583_old = WebService::google_save( $a583_req( $a583_g[0] ) );
check( 'A583: after a code rotation the old link is invalid (404)', is_wp_error( $a583_old ) && 404 === ( $a583_old->get_error_data()['status'] ?? 0 ) );

/* ---- A580: large order ---- */
$a580_count = 25;
http_reset();
$a580_r = Orders::create( array( 'email' => 'a580@example.com', 'items' => array( array( 'ticket_type_id' => $a583_type, 'quantity' => $a580_count ) ), 'idempotency_key' => 'a580-1' ) );
$a580_mail = wpr7_ticket_mail( 'a580@example.com' );
$a580_files = $a580_mail['files'] ?? array();
$a580_apple = $a583_links( $a580_mail['body'], 'apple/bundle' );
$a580_google = $a583_links( $a580_mail['body'], 'google/save' );
$a580_size = array_sum( array_map( 'strlen', $a580_files ) );
check( "A580: $a580_count tickets → no .pkpass per ticket, 3 Apple bundle links (10 each) and 4 Google links (8 each)", ! array_filter( array_keys( $a580_files ), static fn( $n ) => str_ends_with( $n, '.pkpass' ) ) && 3 === count( $a580_apple ) && 4 === count( $a580_google ) && false !== strpos( $a580_mail['body'], 'Tickets 21–25 zu Apple Wallet hinzufügen' ) && false !== strpos( $a580_mail['body'], 'Ticket 25 in Google Wallet speichern' ) );
check( 'A580: no per-ticket download links in the mail', false === strpos( $a580_mail['body'], '/wallet/apple/download/' ) && false === strpos( $a580_mail['body'], 'google/save?t=' ) );
$a580_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_passes w INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=w.ticket_id WHERE t.order_id=" . (int) $a580_r['order_id'] );
check( 'A580: passes and codes are stored for every ticket (rebuild on demand)', 2 * $a580_count === $a580_rows );
$a580_bundle = WebService::bundle( $a583_req( $a580_apple[2] ) );
$a580_zip = is_wp_error( $a580_bundle ) ? array() : wpr7_unzip( $a580_bundle->get_data()['__wpcap_raw'] );
$a580_first = json_decode( wpr7_unzip( reset( $a580_zip ) ?: '' )['pass.json'] ?? '{}', true );
check( 'A580: Apple bundle link serves a .pkpasses with the batch (5 passes, valid codes)', ! is_wp_error( $a580_bundle ) && 'application/vnd.apple.pkpasses' === $a580_bundle->get_data()['headers']['Content-Type'] && 5 === count( $a580_zip ) && ! empty( $a580_first['barcodes'][0]['message'] ) );
http_reset();
for ( $i = 0; $i < 11; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a580_gres = WebService::google_save( $a583_req( $a580_google[0] ) );
list( $a580_jwt, $a580_claims ) = $a583_jwt( is_wp_error( $a580_gres ) ? '' : $a580_gres->get_data()['headers']['Location'] ?? '' );
check( 'A580/A583: one Google link saves 8 tickets with a JWT below 1,800 characters (measured: ' . strlen( $a580_jwt ) . ')', 8 === count( $a580_claims['payload']['eventTicketObjects'] ?? array() ) && strlen( $a580_jwt ) < 1800 );
check( 'A580: wrong order signature → 404', is_wp_error( WebService::bundle( $a583_req( preg_replace( '/sig=[0-9a-f]{4}/', 'sig=0000', $a580_apple[0] ) ) ) ) );
// A ticket passed on to someone else is not part of the buyer's "add all" links.
$a580_t = tickets( $a580_r['order_id'] );
// (A581: the code went to that address – an attendee address alone leaves the code with the buyer.)
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => 'other@example.com' ) + ( PassCaptain\TicketCodes::holder_ready() ? array( 'code_with_attendee' => 1 ) : array() ), array( 'ticket_id' => (int) $a580_t[0]->ticket_id ) );
$a580_order = Orders::get( $a580_r['order_id'] );
check( 'A580: tickets held by another person are left out of the order links', $a580_count - 1 === count( Service::order_ticket_ids( $a580_order, 'apple' ) ) && ! in_array( (int) $a580_t[0]->ticket_id, Service::order_ticket_ids( $a580_order, 'google' ), true ) );

/* ---- A580: PDF split within the attachment budget ---- */
$a580_pdf_one = strlen( PassCaptain\TicketPdf::render( array( array( 'event' => 'Konzert', 'ticket' => 'Standard', 'when' => '01.01.2027 20:00', 'location' => 'Wien', 'token' => str_repeat( 'a', 64 ), 'help_code' => 'ABCD-EFGH' ) ) ) );
add_filter( 'wpcap_ticket_pdf_chunk_size', $a580_chunk = static fn() => 10 );
add_filter( 'wpcap_ticket_mail_attachment_budget', $a580_budget = static fn() => (int) ( 22 * $a580_pdf_one ) );
http_reset();
Orders::create( array( 'email' => 'a580b@example.com', 'items' => array( array( 'ticket_type_id' => $a583_type, 'quantity' => $a580_count ) ), 'idempotency_key' => 'a580-2' ) );
remove_filter( 'wpcap_ticket_pdf_chunk_size', $a580_chunk );
remove_filter( 'wpcap_ticket_mail_attachment_budget', $a580_budget );
$a580_mail2 = wpr7_ticket_mail( 'a580b@example.com' );
$a580_pdfs = array_filter( $a580_mail2['files'] ?? array(), static fn( $bytes, $name ) => str_ends_with( $name, '.pdf' ) && str_starts_with( $name, 'tickets-' ), ARRAY_FILTER_USE_BOTH );
$a580_pdf_size = array_sum( array_map( 'strlen', $a580_pdfs ) );
check( 'A580: PDFs split into chunks; only what fits the budget is attached (measured ~' . round( $a580_pdf_one / 1024 ) . ' KB per ticket)', 2 === count( $a580_pdfs ) && $a580_pdf_size <= 22 * $a580_pdf_one && isset( $a580_pdfs[ array_key_first( $a580_pdfs ) ] ) && str_ends_with( array_key_first( $a580_pdfs ), '-1.pdf' ) );
check( 'A580: mail says which tickets the PDFs contain and where the rest is', false !== strpos( $a580_mail2['body'], 'die ersten 20 von 25 Tickets' ) && false !== strpos( $a580_mail2['body'], 'Bestellportal' ) );
check( 'A580: default budget 7 MB, 50 tickets per PDF', 7 * 1024 * 1024 === Orders::attachment_budget() && 50 === Orders::ticket_pdf_chunk_size() );

wpr7_wallet_disable();
