<?php
// phpcs:ignoreFile
/**
 * A331: extension points – wpcap_checkout_validate (veto before the order transaction),
 * wpcap_order_created (after the commit, once per new order), wpcap_mail_recipients (to/cc/bcc),
 * wpcap_ticket_pdf_data / wpcap_ticket_pdf_layout (code and help code fixed) and the additive
 * wpcap_rest_prepare_* filters (contract fields cannot be removed or changed).
 */

use PassCaptain\Orders;
use PassCaptain\TicketPdf;
use PassCaptain\Mail\Mailer;
use PassCaptain\Rest\Controller;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

$a331_hooks_before = $GLOBALS['wpcap_test_hooks'];
$a331_type = make_type( 10, 10, 0 );

// wpcap_checkout_validate: a veto refuses the checkout before anything is written.
$a331_seen = array();
add_filter( 'wpcap_checkout_validate', static function ( $ok, $context ) use ( &$a331_seen ) {
	$a331_seen[] = $context;
	return 'blocked@example.com' === $context['email'] ? new WP_Error( 'addon_rule', 'Nicht erlaubt' ) : $ok;
}, 10, 2 );
$a331_orders_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
$a331_veto = Orders::create( array( 'email' => 'blocked@example.com', 'items' => array( array( 'ticket_type_id' => $a331_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a331-veto' ) );
check( 'A331: wpcap_checkout_validate refuses a checkout with its WP_Error (status 400 added)', is_wp_error( $a331_veto ) && 'addon_rule' === $a331_veto->get_error_code() && 400 === ( $a331_veto->get_error_data()['status'] ?? 0 ) );
check( 'A331: a refused checkout writes no order', $a331_orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) );
check( 'A331: the validator sees email, items (type => quantity), source and the public payload', isset( $a331_seen[0] ) && array( $a331_type => 1 ) === $a331_seen[0]['items'] && 'native' === $a331_seen[0]['source'] && false === $a331_seen[0]['external'] && 'a331-veto' === $a331_seen[0]['payload']['idempotency_key'] );

// wpcap_order_created: once per new order, after the commit (the row is visible), before order paid.
$a331_created = array();
add_action( 'wpcap_order_created', static function ( $order_id, $context ) use ( &$a331_created ) {
	global $wpdb;
	$a331_created[] = array( $order_id, $context, (string) $wpdb->get_var( $wpdb->prepare( "SELECT payment_status FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", $order_id ) ), did_action( 'wpcap_order_paid' ) );
}, 10, 2 );
$GLOBALS['wpcap_test_actions'] = array();
$a331_ok = Orders::create( array( 'email' => 'a331@example.com', 'items' => array( array( 'ticket_type_id' => $a331_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a331-ok' ) );
$a331_actions = $GLOBALS['wpcap_test_actions'];
check( 'A331: wpcap_order_created fires once with the committed order and its context', ! is_wp_error( $a331_ok ) && 1 === count( $a331_created ) && (int) $a331_ok['order_id'] === (int) $a331_created[0][0] && 'native' === $a331_created[0][1]['source'] && false === $a331_created[0][1]['pending_payment'] && 0 === $a331_created[0][1]['total_minor'] );
check( 'A331: wpcap_order_created runs before wpcap_order_paid of a free order', array_search( 'wpcap_order_created', $a331_actions, true ) < array_search( 'wpcap_order_paid', $a331_actions, true ) );
Orders::create( array( 'email' => 'a331@example.com', 'items' => array( array( 'ticket_type_id' => $a331_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a331-ok' ) );
check( 'A331: a reused idempotency key does not fire wpcap_order_created again', 1 === count( $a331_created ) );

// wpcap_mail_recipients: copy to the organiser (Bcc), invalid addresses dropped.
$GLOBALS['wpcap_test_mails'] = array();
add_filter( 'wpcap_mail_recipients', static function ( $r, $message ) {
	if ( 'a331_test' !== ( $message['type'] ?? '' ) ) { return $r; }
	$r['bcc'] = array( 'org@example.com', 'not-an-address' );
	$r['cc'] = 'copy@example.com';
	return $r;
}, 10, 2 );
Mailer::deliver( 'buyer331@example.com', array( 'subject' => 'Hallo', 'body' => 'Text', 'format' => 'text', 'type' => 'a331_test' ) );
$a331_mail = end( $GLOBALS['wpcap_test_mails'] );
$a331_headers = implode( "\n", (array) ( $a331_mail['headers'] ?? array() ) );
check( 'A331: wpcap_mail_recipients adds Cc/Bcc headers, keeps the recipient and drops invalid addresses', 'buyer331@example.com' === $a331_mail['to'] && false !== strpos( $a331_headers, 'Bcc: org@example.com' ) && false !== strpos( $a331_headers, 'Cc: copy@example.com' ) && false === strpos( $a331_headers, 'not-an-address' ) );
Mailer::deliver( 'buyer331@example.com', array( 'subject' => 'Hallo', 'body' => 'Text', 'format' => 'text', 'type' => 'other' ) );
$a331_mail = end( $GLOBALS['wpcap_test_mails'] );
check( 'A331: mails the filter does not touch are unchanged', false === strpos( implode( "\n", (array) $a331_mail['headers'] ), 'Bcc:' ) );

// PDF: data and layout filters; the code and help code stay as issued.
$a331_entry = array( 'event' => 'Konzert', 'ticket' => 'Standard', 'when' => 'Morgen', 'location' => 'Wien', 'token' => str_repeat( 'a', 64 ), 'help_code' => 'H-1234', 'ticket_id' => 7 );
$a331_plain = TicketPdf::render( array( $a331_entry ) );
add_filter( 'wpcap_ticket_pdf_data', static function ( $t ) { $t['event'] = 'Konzert VIP'; $t['token'] = str_repeat( 'b', 64 ); $t['help_code'] = 'X'; $t['sponsor'] = 'Sponsor AG'; return $t; } );
add_filter( 'wpcap_ticket_pdf_layout', static function ( $lines, $t ) { $lines[] = array( 'text' => $t['sponsor'] ?? '', 'size' => 99, 'gap' => 500 ); $lines[] = 'junk'; return $lines; }, 10, 2 );
$a331_data = TicketPdf::data( $a331_entry );
check( 'A331: wpcap_ticket_pdf_data changes display fields but never the code or help code', 'Konzert VIP' === $a331_data['event'] && str_repeat( 'a', 64 ) === $a331_data['token'] && 'H-1234' === $a331_data['help_code'] );
$a331_layout = TicketPdf::layout( $a331_data );
$a331_last = end( $a331_layout );
check( 'A331: wpcap_ticket_pdf_layout adds lines, clamped (size ≤ 40, gap ≤ 80), invalid lines dropped', 'Sponsor AG' === $a331_last['text'] && 40 === $a331_last['size'] && 80 === $a331_last['gap'] && 6 === count( $a331_layout ) );
$a331_pdf = TicketPdf::render( array( $a331_entry ) );
check( 'A331: the rendered PDF shows the filtered data and keeps the issued code', false !== strpos( $a331_pdf, 'Konzert VIP' ) && false !== strpos( $a331_pdf, 'Sponsor AG' ) && false !== strpos( $a331_pdf, str_repeat( 'a', 32 ) ) && false === strpos( $a331_pdf, str_repeat( 'b', 32 ) ) && $a331_pdf !== $a331_plain );

// REST: additive prepare filters.
add_filter( 'wpcap_rest_prepare_event', static function ( $item ) { $item['addon_badge'] = 'neu'; unset( $item['name'] ); $item['event_id'] = 'x'; return $item; } );
add_filter( 'wpcap_rest_prepare_order', static function ( $item ) { $item['addon_points'] = 5; $item['status'] = 'hacked'; return $item; } );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET e.name='A331 Event' WHERE tt.ticket_type_id=%d", $a331_type ) );
$a331_events = Controller::events( new WP_REST_Request( 'GET', '/pass-captain/v1/events' ) );
$a331_events = $a331_events instanceof WP_REST_Response ? $a331_events->get_data() : $a331_events;
$a331_item = null;
foreach ( (array) $a331_events as $e ) { if ( 'A331 Event' === ( $e['name'] ?? '' ) ) { $a331_item = $e; } }
check( 'A331: wpcap_rest_prepare_event adds fields; contract fields cannot be removed or changed', is_array( $a331_item ) && 'neu' === $a331_item['addon_badge'] && is_int( $a331_item['event_id'] ) );
$a331_order = $wpdb->get_row( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=%d", $a331_ok['order_id'] ) );
$a331_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a331_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a331_ok['order_id'] ) );
$a331_req = new WP_REST_Request( 'GET', '/pass-captain/v1/orders/' . $a331_order->public_id );
$a331_req['public_id'] = $a331_order->public_id;
$a331_req->set_param( 'access_token', $a331_token );
$a331_status = Controller::order_status( $a331_req );
$a331_status = $a331_status instanceof WP_REST_Response ? $a331_status->get_data() : $a331_status;
check( 'A331: wpcap_rest_prepare_order adds fields but keeps the status of the contract', is_array( $a331_status ) && 5 === $a331_status['addon_points'] && 'complete' === $a331_status['status'] && ! isset( $a331_status['access_token_hash'] ) );

$GLOBALS['wpcap_test_hooks'] = $a331_hooks_before;
