<?php
/**
 * A492: wrongly typed e-mail fields (arrays) never end in a TypeError – the REST schema checks the type
 * and every entrance sanitises through Http::email(). A493: valid e-mail addresses longer than the
 * varchar(190) columns are refused at the entrance (400) instead of a paid order without tickets or an
 * endless 503; stored attendee data that does not fit no longer blocks the issuance.
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Http;

require_once dirname( __DIR__ ) . '/channels.php';

global $wpdb;
$p = $wpdb->prefix;

/** Runs $fn and returns [result, list of TypeErrors/warnings]. */
function a492_run( callable $fn ) {
	$issues = array();
	set_error_handler( static function ( $no, $str ) use ( &$issues ) { $issues[] = $no . ': ' . $str; return true; } );
	try {
		$result = $fn();
	} catch ( \Throwable $e ) {
		$result = null;
		$issues[] = get_class( $e ) . ': ' . $e->getMessage();
	}
	restore_error_handler();
	return array( $result, $issues );
}
function a492_emails( $order_id ) { global $wpdb; return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT attendee_email FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $order_id ) ) ); }
function a492_status( $r ) { return is_wp_error( $r ) ? (int) ( ( (array) $r->get_error_data() )['status'] ?? 0 ) : 200; }
function a492_paid_order( $type, $key ) {
	global $wpdb;
	$o = Orders::create( array( 'email' => $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => $key ) );
	Orders::confirm_manual_payment( $o['order_id'] );
	$token = bin2hex( random_bytes( 32 ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $o['order_id'] ) );
	$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,public_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", $o['order_id'] ) );
	return array( Orders::get( $o['order_id'] ), $token, $ticket );
}
// 60 + 1 + 60 + 1 + 50 + 1 + n + 4: valid labels (≤ 63), valid local part (≤ 64).
function a493_long_email( $length ) { return str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 50 ) . '.' . str_repeat( 'd', $length - 177 ) . '.com'; }

echo "A492: e-mail fields with arrays never cause a TypeError\n";
check( 'A492: Http::email() turns arrays, objects, booleans and null into ""', '' === Http::email( array( 'a@b.de' ) ) && '' === Http::email( (object) array() ) && '' === Http::email( true ) && '' === Http::email( null ) && 'a@example.com' === Http::email( ' a@example.com ' ) );
PassCaptain\Rest\Controller::register_routes();
$a492_args = $GLOBALS['wpcap_test_routes']['pass-captain/v1/checkout']['args']['email'] ?? array();
check( 'A492: REST checkout declares email as string with a type validation before sanitising', 'string' === ( $a492_args['type'] ?? '' ) && 'rest_validate_request_arg' === ( $a492_args['validate_callback'] ?? '' ) && is_callable( $a492_args['sanitize_callback'] ?? null ) && '' === call_user_func( $a492_args['sanitize_callback'], array( 'x@example.com' ) ) );

$a492_type = make_type( 50, 10, 0 );
list( $r, $issues ) = a492_run( static fn() => Orders::create( array( 'email' => 'a492-att@example.com', 'items' => array( array( 'ticket_type_id' => $a492_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a492-att-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $a492_type, 'name' => array( 'x' ), 'email' => array( 'evil@example.com' ) ) ) ) ) );
check( 'A492: checkout with attendee name/e-mail as arrays: no TypeError, order without the e-mail', ! $issues && ! is_wp_error( $r ) && array( '' ) === a492_emails( $r['order_id'] ) );
list( $r, $issues ) = a492_run( static fn() => Orders::create( array( 'email' => array( 'a492@example.com' ), 'items' => array( array( 'ticket_type_id' => $a492_type, 'quantity' => 1 ) ) ) ) );
check( 'A492: buyer e-mail as array: 400 without TypeError', ! $issues && is_wp_error( $r ) && 400 === a492_status( $r ) );

$a492_paid = make_type( 50, 10, 1500 );
list( $a492_order, $a492_token, $a492_ticket ) = a492_paid_order( $a492_paid, 'a492-tr-' . uniqid() );
list( $r, $issues ) = a492_run( static fn() => Orders::transfer_ticket( $a492_order->public_id, $a492_token, $a492_ticket->public_id, array( 'friend@example.com' ) ) );
check( 'A492: transfer with recipient_email as array: 400 without TypeError', ! $issues && is_wp_error( $r ) && 'wpcap_transfer_invalid' === $r->get_error_code() );
list( $r, $issues ) = a492_run( static fn() => Orders::transfer_ticket( array( 'x' ), array( 'y' ), $a492_ticket->public_id, 'friend@example.com' ) );
check( 'A492: transfer with order id/access token as arrays: 400 without warnings', ! $issues && is_wp_error( $r ) && 400 === a492_status( $r ) );
list( $r, $issues ) = a492_run( static fn() => PassCaptain\TicketAssignments::assign( $a492_order->public_id, array( 'x' ), $a492_ticket->public_id, array( 'Name' ), array( 'x@example.com' ) ) );
check( 'A492: assignment with token, name and e-mail as arrays: 400 without warnings', ! $issues && is_wp_error( $r ) && 400 === a492_status( $r ) );
list( $r, $issues ) = a492_run( static fn() => PassCaptain\ServiceBookings::reserve( 1, array( 'x@example.com' ), 1, 0, 10, array( 'k' ) ) );
check( 'A492: service booking with e-mail/key as arrays: error without TypeError', ! $issues && is_wp_error( $r ) && 'wpcap_booking_invalid' === $r->get_error_code() );
list( $r, $issues ) = a492_run( static fn() => PassCaptain\Waitlists::join( 1, 0, 0, array( 'x@example.com' ) ) );
check( 'A492: waitlist with e-mail as array: 400 without TypeError', ! $issues && is_wp_error( $r ) && 400 === a492_status( $r ) );

echo "A493: e-mail addresses longer than the columns are refused at the entrance\n";
$a493_long = a493_long_email( 194 );
check( 'A493: fixture is a valid address longer than 190 characters', 194 === strlen( $a493_long ) && false !== is_email( $a493_long ) && Http::email_too_long( $a493_long ) && ! Http::valid_email( $a493_long ) );
$a493_orders_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
list( $r, $issues ) = a492_run( static fn() => Orders::create( array( 'email' => $a493_long, 'items' => array( array( 'ticket_type_id' => $a492_paid, 'quantity' => 1 ) ), 'idempotency_key' => 'a493-buyer-' . uniqid() ) ) );
check( 'A493: buyer e-mail of 194 characters: 400 email_too_long (not 503 db_busy), no order', ! $issues && is_wp_error( $r ) && 400 === a492_status( $r ) && 'email_too_long' === ( $r->get_error_data()['reason'] ?? '' ) && false === ( $r->get_error_data()['retryable'] ?? false ) && $a493_orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) );
$a493_free = make_type( 50, 10, 0 );
list( $r, $issues ) = a492_run( static fn() => Orders::create( array( 'email' => 'a493-att@example.com', 'items' => array( array( 'ticket_type_id' => $a493_free, 'quantity' => 1 ) ), 'idempotency_key' => 'a493-att-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $a493_free, 'name' => 'Erika', 'email' => a493_long_email( 200 ) ) ) ) ) );
check( 'A493: attendee e-mail of 200 characters: 400 before any order exists (no paid order without tickets)', ! $issues && is_wp_error( $r ) && 'wpcap_attendees_invalid' === $r->get_error_code() && 400 === a492_status( $r ) && 0 === ( $r->get_error_data()['index'] ?? -1 ) && $a493_orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) );
$r = Orders::create( array( 'email' => 'a493-ok@example.com', 'items' => array( array( 'ticket_type_id' => $a493_free, 'quantity' => 1 ) ), 'idempotency_key' => 'a493-ok-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $a493_free, 'name' => 'Erika', 'email' => a493_long_email( 190 ) ) ) ) );
check( 'A493: an attendee e-mail of exactly 190 characters is stored', ! is_wp_error( $r ) && array( a493_long_email( 190 ) ) === a492_emails( $r['order_id'] ) );

// An order created before the entrance checks: its stored attendee data no longer blocks the issuance.
$a493_legacy = Orders::create( array( 'email' => 'a493-legacy@example.com', 'items' => array( array( 'ticket_type_id' => $a492_paid, 'quantity' => 1 ) ), 'idempotency_key' => 'a493-legacy-' . uniqid() ) );
$wpdb->update( "{$p}wpcap_orders", array( 'attendee_data' => wp_json_encode( array( array( 'ticket_type_id' => $a492_paid, 'name' => str_repeat( 'Ä', 250 ), 'email' => a493_long_email( 200 ), 'answers' => array() ) ) ) ), array( 'order_id' => $a493_legacy['order_id'] ) );
Orders::confirm_manual_payment( $a493_legacy['order_id'] );
$a493_t = tickets( $a493_legacy['order_id'] );
$a493_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", $a493_t ? (int) $a493_t[0]->ticket_id : 0 ) );
check( 'A493: stored overlong attendee data (older order): tickets are issued, e-mail dropped, name shortened', array( '' ) === a492_emails( $a493_legacy['order_id'] ) && 190 === mb_strlen( $a493_name ) );

list( $a493_order, $a493_token, $a493_ticket ) = a492_paid_order( $a492_paid, 'a493-tr-' . uniqid() );
$r = Orders::transfer_ticket( $a493_order->public_id, $a493_token, $a493_ticket->public_id, $a493_long );
check( 'A493: transfer to an address of 194 characters: 400 email_too_long, ticket unchanged', is_wp_error( $r ) && 400 === a492_status( $r ) && 'email_too_long' === ( $r->get_error_data()['reason'] ?? '' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT transfer_count FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a493_ticket->ticket_id ) ) );
$r = PassCaptain\TicketAssignments::assign( $a493_order->public_id, $a493_token, $a493_ticket->public_id, 'Max', $a493_long );
check( 'A493: assignment to an address of 194 characters: 400 email_too_long', is_wp_error( $r ) && 400 === a492_status( $r ) && 'email_too_long' === ( $r->get_error_data()['reason'] ?? '' ) );
$r = PassCaptain\Waitlists::join( 1, 0, 0, $a493_long );
check( 'A493: waitlist with an address of 194 characters: 400', is_wp_error( $r ) && 400 === a492_status( $r ) );

check( 'A493: database "value does not fit" errors are recognised (strict mode and wpdb field check)', Orders::is_data_error( "Data too long for column 'customer_email' at row 1" ) && Orders::is_data_error( "Incorrect string value: '\\xC3' for column 'reason'" ) && Orders::is_data_error( 'WordPress database error: Processing the value for the following field failed: reason. The supplied value may be too long or contains invalid data.' ) && ! Orders::is_data_error( 'Deadlock found when trying to get lock; try restarting transaction' ) );
