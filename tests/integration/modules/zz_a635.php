<?php
/**
 * A635 (Sicherheitsaudit 14.11. #8): the public assignment route has an args schema, a rate limit per
 * client and per order, and the attendee name is limited to 190 characters without links (it is put
 * into a mail to a freely chosen address).
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\TicketAssignments;

require_once dirname( __DIR__ ) . '/channels.php';

global $wpdb;
$p = $wpdb->prefix;

echo "A635: assignment route: schema, rate limit, name limit\n";
PassCaptain\Rest\Controller::register_routes();
$a635_args = $GLOBALS['wpcap_test_routes']['pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/assign']['args'] ?? array();
check( 'A635: route declares typed, length-limited args validated before the callback', 190 === ( $a635_args['attendee_name']['maxLength'] ?? 0 ) && 'string' === ( $a635_args['attendee_name']['type'] ?? '' ) && 190 === ( $a635_args['attendee_email']['maxLength'] ?? 0 ) && 64 === ( $a635_args['access_token']['maxLength'] ?? 0 ) && 32 === ( $a635_args['ticket_public_id']['maxLength'] ?? 0 ) && 'object' === ( $a635_args['answers']['type'] ?? '' ) && 'rest_validate_request_arg' === ( $a635_args['attendee_name']['validate_callback'] ?? '' ) );

$a635_type = make_type( 50, 10, 1500 );
function a635_order( $type ) {
	global $wpdb;
	$key = 'a635-' . bin2hex( random_bytes( 6 ) );
	$o = Orders::create( array( 'email' => $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => $key ) );
	Orders::confirm_manual_payment( $o['order_id'] );
	$token = bin2hex( random_bytes( 32 ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $o['order_id'] ) );
	return array( Orders::get( $o['order_id'] ), $token, $wpdb->get_col( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $o['order_id'] ) ) );
}
function a635_rest( $public_id, array $body ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/orders/' . $public_id . '/assign' );
	$req->set_body( wp_json_encode( $body ) );
	$req['public_id'] = $public_id;
	return PassCaptain\Rest\Controller::assign_ticket( $req );
}
function a635_name( $ticket_public_id ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT a.attendee_name FROM {$wpdb->prefix}wpcap_ticket_assignments a JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.public_id=%s", $ticket_public_id ) );
}

list( $o, $tok, $tickets ) = a635_order( $a635_type );
$GLOBALS['wpcap_test_transients'] = array();
$long = str_repeat( 'Jürgen 😀 ', 40 ); // 360 characters, 4-byte characters at the boundary.
$r = TicketAssignments::assign( $o->public_id, $tok, $tickets[0], $long, '' );
check( 'A635: a name longer than 190 characters is cut to 190 characters (valid UTF-8), not a 500', ! is_wp_error( $r ) && 190 === mb_strlen( a635_name( $tickets[0] ) ) && mb_check_encoding( a635_name( $tickets[0] ), 'UTF-8' ) );
foreach ( array( 'Max https://spam.example/x', 'Besuch www.spam.example', 'Gewinn bei spam.example/gratis', 'ftp://x.example' ) as $i => $spam ) {
	$r = TicketAssignments::assign( $o->public_id, $tok, $tickets[1], $spam, 'neu' . $i . '-a635@example.com' );
	if ( ! is_wp_error( $r ) || 'name_link' !== ( $r->get_error_data()['reason'] ?? '' ) ) { break; }
}
check( 'A635: names with a web address are refused (400 name_link)', is_wp_error( $r ) && 400 === ( $r->get_error_data()['status'] ?? 0 ) && 'name_link' === ( $r->get_error_data()['reason'] ?? '' ) && 3 === $i );
$r = TicketAssignments::assign( $o->public_id, $tok, $tickets[1], 'Dr. Anna-Lena O’Brien-Müller Jr.', '' );
check( 'A635: ordinary names with dots, apostrophes and hyphens stay allowed', ! is_wp_error( $r ) && 'Dr. Anna-Lena O’Brien-Müller Jr.' === a635_name( $tickets[1] ) );

// Rate limit per order (with a valid link): 20 per hour (filter), then 429. The test renames one ticket
// many times: the name/holder change limits of A569 are lifted here (they are tested in zz_a569_a570).
$GLOBALS['wpcap_test_filters']['wpcap_max_attendee_name_changes'] = 1000;
$GLOBALS['wpcap_test_filters']['wpcap_max_holder_changes'] = 1000;
$GLOBALS['wpcap_test_transients'] = array();
$limited = 0;
for ( $n = 0; $n < 22; $n++ ) {
	$res = a635_rest( $o->public_id, array( 'access_token' => $tok, 'ticket_public_id' => $tickets[0], 'attendee_name' => 'Name ' . $n ) );
	if ( is_wp_error( $res ) && 'wpcap_rate_limited' === $res->get_error_code() ) { ++$limited; }
}
check( 'A635: per order at most 20 assignments per hour through the route (2 of 22 refused with 429)', 2 === $limited );
// A guessed order id with a wrong token does not use up the buyer's budget; the client limit still applies.
$GLOBALS['wpcap_test_transients'] = array();
for ( $n = 0; $n < 29; $n++ ) { a635_rest( $o->public_id, array( 'access_token' => str_repeat( 'b', 64 ), 'ticket_public_id' => $tickets[0], 'attendee_name' => 'X' ) ); }
$ok = a635_rest( $o->public_id, array( 'access_token' => $tok, 'ticket_public_id' => $tickets[0], 'attendee_name' => 'Noch erlaubt' ) );
$blocked = a635_rest( $o->public_id, array( 'access_token' => $tok, 'ticket_public_id' => $tickets[0], 'attendee_name' => 'Zu viel' ) );
check( 'A635: per client at most 30 requests per hour (wrong tokens count, the order budget does not)', ! is_wp_error( $ok ) && is_wp_error( $blocked ) && 'wpcap_rate_limited' === $blocked->get_error_code() && 'Noch erlaubt' === a635_name( $tickets[0] ) );
$GLOBALS['wpcap_test_transients'] = array();
unset( $GLOBALS['wpcap_test_filters']['wpcap_max_attendee_name_changes'], $GLOBALS['wpcap_test_filters']['wpcap_max_holder_changes'] );
