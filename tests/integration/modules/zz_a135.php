<?php
/**
 * A135: service booking flow end-to-end – confirm (free / paid through the checkout), payment and
 * order hooks, rebook, cancel with refund by deadline, notifications, booking token (REST), order
 * portal self-service, staff actions and concurrency (parallel processes and a deterministic
 * interleaving inside the rebooking transaction). Included from run.php (helpers from
 * p3_exchange.php; worker mode `--service-child` in run.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceBookingFlow;
use PassCaptain\ServiceProviders;
use PassCaptain\Admin\ServiceSlotsPage;
use PassCaptain\Admin\Menu;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }

global $wpdb;
$p = $wpdb->prefix;
$a135_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a135_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$a135_saved_hooks = $GLOBALS['wpcap_test_hooks'];
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.135';
ServiceBookings::flush_schema_cache();
ServiceBookingFlow::register();

function a135_slot( $resource, $minutes, $capacity = 2 ) {
	global $wpdb;
	$start = time() + $minutes * 60;
	$wpdb->insert( "{$wpdb->prefix}wpcap_service_slots", array( 'resource_id' => $resource, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + 3600 ), 'capacity' => $capacity, 'status' => 'open' ) );
	return (int) $wpdb->insert_id;
}
/** Reserves; returns [booking_id, token] or the error. */
function a135_book( $slot, $email, $qty = 1 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = ServiceBookings::reserve( $slot, $email, $qty, 0, 10, 'a135-' . uniqid( '', true ) );
	return is_array( $r ) ? array( (int) $r['booking_id'], (string) ( $r['token'] ?? '' ) ) : $r;
}
function a135_req( $booking_id, array $body = array(), $method = 'POST' ) {
	$r = new WP_REST_Request( $method );
	$r->set_param( 'booking_id', $booking_id );
	$r->set_body( wp_json_encode( $body ) );
	return $r;
}
function a135_status( $booking_id ) { return (string) ServiceBookings::booking( $booking_id )->status; }
function a135_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function a135_mails_to( $to, $needle ) {
	foreach ( $GLOBALS['wpcap_test_mails'] as $mail ) {
		if ( false !== strpos( (string) $mail['to'], $to ) && false !== strpos( $mail['subject'] . $mail['body'], $needle ) ) { return true; }
	}
	return false;
}
function a135_refunds( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d", $order_id ) );
}
/** Runs workers in parallel; returns their outputs. */
function a135_parallel( array $jobs, $lock_wait = 0 ) {
	$start = sprintf( '%.3f', microtime( true ) + 1.5 );
	$procs = array();
	foreach ( $jobs as $job ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( WPCAP_PATH . 'tests/integration/run.php' ) . ' --service-child ' . implode( ' ', array_map( 'escapeshellarg', array_merge( $job, array( $start ) ) ) ) . ( $lock_wait ? ' ' . (int) $lock_wait : '' );
		$procs[] = popen( $cmd, 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	return $out;
}

echo "A135: setup (schema, price, routes)\n";
check( 'schema 3.10.4: booking token, updated_at, resource ticket type', version_compare( PassCaptain\Installer::DB_VERSION, '3.10.4', '>=' ) && ServiceBookings::flow_ready() );
$a135_event = ex_event( 'A135 Bootsschule' );
$a135_occ = ex_occ( $a135_event, 0, 30 * 24 );
$a135_type = ex_type( $a135_occ, 'Praxisstunde', 4000 );
$a135_type2 = ex_type( $a135_occ, 'Theoriestunde', 2500 );
$a135_paid = ServiceSlotsPage::save_resource( array( 'event_id' => $a135_event, 'name' => 'Motorboot 1', 'capacity' => '2', 'ticket_type_id' => (string) $a135_type, 'rebook_hours' => '24', 'cancel_hours' => '24' ) );
$a135_paid2 = ServiceBookings::create_resource( $a135_event, array( 'name' => 'Motorboot 2', 'capacity' => 2, 'ticket_type_id' => $a135_type, 'rebook_hours' => 24, 'cancel_hours' => 24 ) );
$a135_other_price = ServiceBookings::create_resource( $a135_event, array( 'name' => 'Theorieraum', 'capacity' => 5, 'ticket_type_id' => $a135_type2 ) );
$a135_free = ServiceBookings::create_resource( $a135_event, array( 'name' => 'Schnuppersegeln', 'capacity' => 3 ) );
check( 'resource stores the ticket type of its event as price', is_int( $a135_paid ) && $a135_type === (int) ServiceBookings::resource( $a135_paid )->ticket_type_id && 0 === (int) ServiceBookings::resource( $a135_free )->ticket_type_id );
$a135_foreign_type = ex_type( ex_occ( ex_event( 'A135 Fremd' ) ), 'Fremd', 100 );
check( 'ticket type of another event refused', 'wpcap_resource_ticket_type' === a135_code( ServiceBookings::create_resource( $a135_event, array( 'name' => 'X', 'ticket_type_id' => $a135_foreign_type ) ) ) );
$a135_saved_routes = $GLOBALS['wpcap_test_routes'] ?? array();
$GLOBALS['wpcap_test_routes'] = array();
PassCaptain\Rest\Controller::register_routes();
$a135_routes = $GLOBALS['wpcap_test_routes'];
$GLOBALS['wpcap_test_routes'] = $a135_saved_routes;
$a135_route_ok = true;
foreach ( array( '', '/confirm', '/rebook', '/cancel' ) as $a135_suffix ) {
	$a135_r = $a135_routes[ 'pass-captain/v1/service-bookings/(?P<booking_id>\d+)' . $a135_suffix ] ?? null;
	$a135_route_ok = $a135_route_ok && $a135_r && array( ServiceBookingFlow::class, 'token_permission' ) === $a135_r['permission_callback'];
}
check( 'REST routes for booking self-service, guarded by the booking token', $a135_route_ok );

echo "A135: booking token\n";
$a135_fs = a135_slot( $a135_free, 3 * 1440 );
list( $a135_fb, $a135_ftoken ) = a135_book( $a135_fs, 'a135-free@example.com' );
$a135_hash = (string) $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_service_bookings WHERE booking_id=$a135_fb" );
check( 'reservation returns a 64-hex token, only its hash is stored', 64 === strlen( $a135_ftoken ) && hash( 'sha256', $a135_ftoken ) === $a135_hash && false === strpos( $a135_hash, $a135_ftoken ) );
$GLOBALS['wpcap_test_transients'] = array();
$a135_get = ServiceBookingFlow::rest_get( a135_req( $a135_fb, array(), 'GET' ) );
check( 'token permission: valid token passes, wrong token 403', true === ServiceBookingFlow::token_permission( a135_req( $a135_fb, array( 'token' => $a135_ftoken ) ) ) && 'wpcap_booking_access_denied' === a135_code( ServiceBookingFlow::token_permission( a135_req( $a135_fb, array( 'token' => str_repeat( 'a', 64 ) ) ) ) ) && 'held' === $a135_get->get_data()['status'] && false === strpos( wp_json_encode( $a135_get->get_data() ), 'a135-free@' ) );
for ( $i = 0; $i < 10; $i++ ) { ServiceBookingFlow::token_permission( a135_req( $a135_fb, array( 'token' => str_repeat( 'b', 64 ) ) ) ); }
check( 'after 10 wrong tokens the booking answers 429, even for the right token', 'wpcap_booking_rate' === a135_code( ServiceBookingFlow::token_permission( a135_req( $a135_fb, array( 'token' => $a135_ftoken ) ) ) ) );
$GLOBALS['wpcap_test_transients'] = array();

echo "A135: confirm a free booking\n";
$GLOBALS['wpcap_test_mails'] = array();
$a135_fc = ServiceBookingFlow::rest_confirm( a135_req( $a135_fb, array( 'token' => $a135_ftoken ) ) );
check( 'free booking confirmed at once via REST', 'confirmed' === ( $a135_fc->get_data()['status'] ?? '' ) && 'confirmed' === a135_status( $a135_fb ) && null === ServiceBookings::booking( $a135_fb )->expires_at );
check( 'customer and team are notified', a135_mails_to( 'a135-free@example.com', 'Buchung bestätigt' ) && a135_mails_to( 'admin@example.com', 'Servicebuchung #' . $a135_fb ) );

echo "A135: confirm with payment via the checkout\n";
$a135_s1 = a135_slot( $a135_paid, 5 * 1440 );
list( $a135_b1, $a135_t1 ) = a135_book( $a135_s1, 'a135-pay@example.com', 2 );
$GLOBALS['wpcap_test_transients'] = array();
$a135_c1 = ServiceBookings::confirm( $a135_b1, array( 'provider' => 'manual' ) );
$a135_row1 = ServiceBookings::booking( $a135_b1 );
$a135_order1 = $a135_row1 ? Orders::get( (int) $a135_row1->order_id ) : null;
$a135_items1 = $a135_order1 ? Orders::items_for_order( $a135_order1->order_id ) : array();
check( 'paid resource: order created through the checkout, booking awaits payment', is_array( $a135_c1 ) && 'pending_payment' === $a135_c1['status'] && $a135_order1 && 'pending' === $a135_order1->payment_status && 8000 === (int) $a135_order1->total_minor && 1 === count( $a135_items1 ) && $a135_type === (int) $a135_items1[0]->ticket_type_id && 2 === (int) $a135_items1[0]->quantity && ! empty( $a135_c1['order']['public_id'] ) );
check( 'awaiting payment still occupies the places, hold capped at the slot start', 2 === ServiceBookings::booked( $a135_s1 ) && $a135_row1->expires_at <= $a135_row1->starts_at && 'pending_payment' === ServiceBookings::booking_status( $a135_row1 ) );
$GLOBALS['wpcap_test_transients'] = array();
$a135_again = ServiceBookings::confirm( $a135_b1, array( 'provider' => 'manual' ) );
check( 'second confirm: no second order', true === ( $a135_again['already'] ?? false ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email=%s", 'a135-pay@example.com' ) ) );
$a135_threw = false;
try { ServiceBookingFlow::link_order( 999999, array(), array(), array( 'service_booking_id' => $a135_b1, 'hold_minutes' => 10 ) ); } catch ( RuntimeException $e ) { $a135_threw = 'service_booking_unavailable' === $e->getMessage(); }
check( 'linking a second order to the booking aborts that order', $a135_threw && (int) $a135_order1->order_id === (int) ServiceBookings::booking( $a135_b1 )->order_id );
$GLOBALS['wpcap_test_mails'] = array();
Orders::confirm_manual_payment( (int) $a135_order1->order_id );
check( 'payment confirms the booking (wpcap_order_paid), tickets issued, customer notified', 'confirmed' === a135_status( $a135_b1 ) && 2 === count( tickets( (int) $a135_order1->order_id, 'valid' ) ) && a135_mails_to( 'a135-pay@example.com', 'Buchung bestätigt' ) && a135_mails_to( 'a135-pay@example.com', 'Bestellportal' ) );

echo "A135: unpaid order expires\n";
$a135_s2 = a135_slot( $a135_paid, 6 * 1440 );
list( $a135_b2 ) = a135_book( $a135_s2, 'a135-late@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b2, array( 'provider' => 'manual' ) );
$a135_o2 = (int) ServiceBookings::booking( $a135_b2 )->order_id;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $a135_o2 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $a135_b2 ) );
Orders::expire_holds();
check( 'order expires → booking expired, places free', 'expired' === Orders::get( $a135_o2 )->status && 'expired' === a135_status( $a135_b2 ) && 0 === ServiceBookings::booked( $a135_s2 ) );

echo "A135: late payment\n";
$a135_s3 = a135_slot( $a135_paid, 7 * 1440, 1 );
list( $a135_b3 ) = a135_book( $a135_s3, 'a135-late2@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b3, array( 'provider' => 'manual' ) );
$a135_o3 = (int) ServiceBookings::booking( $a135_b3 )->order_id;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $a135_b3 ) );
Orders::confirm_manual_payment( $a135_o3 );
check( 'payment after the hold lapsed, slot still free: booking confirmed', 'confirmed' === a135_status( $a135_b3 ) && 1 === ServiceBookings::booked( $a135_s3 ) );
$a135_s4 = a135_slot( $a135_paid, 8 * 1440, 1 );
list( $a135_b4 ) = a135_book( $a135_s4, 'a135-late3@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b4, array( 'provider' => 'manual' ) );
$a135_o4 = (int) ServiceBookings::booking( $a135_b4 )->order_id;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $a135_b4 ) );
list( $a135_taker ) = a135_book( $a135_s4, 'a135-taker@example.com' );
$GLOBALS['wpcap_test_mails'] = array();
Orders::confirm_manual_payment( $a135_o4 );
check( 'payment after the slot was taken: booking unallocated, no double booking, refund requested, team informed', is_int( $a135_taker ) && 'unallocated' === a135_status( $a135_b4 ) && 1 === ServiceBookings::booked( $a135_s4 ) && 1 === count( a135_refunds( $a135_o4 ) ) && a135_mails_to( 'admin@example.com', 'Termin nicht mehr frei' ) && a135_mails_to( 'a135-late3@example.com', 'erstattet' ) );

echo "A135: rebook\n";
$a135_t_same = a135_slot( $a135_paid2, 9 * 1440 );
$a135_t_other = a135_slot( $a135_other_price, 9 * 1440 + 120 );
check( 'paid booking cannot move to a resource with another price', 'wpcap_booking_rebook_price' === a135_code( ServiceBookingFlow::rest_rebook( a135_req( $a135_b1, array( 'token' => $a135_t1, 'slot_id' => $a135_t_other ) ) ) ) );
$GLOBALS['wpcap_test_mails'] = array();
$a135_rb = ServiceBookingFlow::rest_rebook( a135_req( $a135_b1, array( 'token' => $a135_t1, 'slot_id' => $a135_t_same ) ) );
check( 'customer rebooks to the same price on another resource (REST): moved, old places free, order unchanged', ! is_wp_error( $a135_rb ) && $a135_t_same === (int) ServiceBookings::booking( $a135_b1 )->slot_id && 0 === ServiceBookings::booked( $a135_s1 ) && 2 === ServiceBookings::booked( $a135_t_same ) && 'confirmed' === a135_status( $a135_b1 ) && (int) $a135_order1->order_id === (int) ServiceBookings::booking( $a135_b1 )->order_id );
check( 'rebooking notifies customer (with the previous date) and team', a135_mails_to( 'a135-pay@example.com', 'Buchung umgebucht' ) && a135_mails_to( 'a135-pay@example.com', 'Bisheriger Termin' ) && a135_mails_to( 'admin@example.com', 'umgebucht' ) );
check( 'rebooking to a slot without room refused', 'wpcap_slot_full' === a135_code( ServiceBookings::rebook( $a135_b1, $a135_s4 ) ) );

echo "A135: cancel with refund according to the deadline\n";
$GLOBALS['wpcap_test_mails'] = array();
$a135_cancel = ServiceBookingFlow::rest_cancel( a135_req( $a135_b1, array( 'token' => $a135_t1 ) ) );
$a135_ref = a135_refunds( (int) $a135_order1->order_id );
check( 'customer cancels before the deadline: places free, full refund (source service_booking)', ! is_wp_error( $a135_cancel ) && 'cancelled' === a135_status( $a135_b1 ) && 0 === ServiceBookings::booked( $a135_t_same ) && 1 === count( $a135_ref ) && 8000 === (int) $a135_ref[0]->amount_minor && ( ! isset( $a135_ref[0]->source ) || 'service_booking' === $a135_ref[0]->source ) && 'completed' === ( $a135_cancel->get_data()['refund']['status'] ?? '' ) );
check( 'tickets revoked, order refunded; customer and team notified', 0 === count( tickets( (int) $a135_order1->order_id, 'valid' ) ) && 'refunded' === Orders::get( (int) $a135_order1->order_id )->status && a135_mails_to( 'a135-pay@example.com', 'Buchung storniert' ) && a135_mails_to( 'admin@example.com', 'storniert' ) );
// Inside the deadline (24 h): booking 20 hours ahead.
$a135_s5 = a135_slot( $a135_paid, 20 * 60 );
list( $a135_b5, $a135_t5 ) = a135_book( $a135_s5, 'a135-short@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b5, array( 'provider' => 'manual' ) );
$a135_o5 = (int) ServiceBookings::booking( $a135_b5 )->order_id;
Orders::confirm_manual_payment( $a135_o5 );
check( 'customer cancellation after the deadline refused (REST), no refund', 'wpcap_booking_cancel_deadline' === a135_code( ServiceBookingFlow::rest_cancel( a135_req( $a135_b5, array( 'token' => $a135_t5 ) ) ) ) && 'confirmed' === a135_status( $a135_b5 ) && 0 === count( a135_refunds( $a135_o5 ) ) );
check( 'customer rebooking after the deadline refused', 'wpcap_booking_rebook_deadline' === a135_code( ServiceBookingFlow::rest_rebook( a135_req( $a135_b5, array( 'token' => $a135_t5, 'slot_id' => $a135_t_same ) ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_refund', 'wpcap_approve_refunds' );
check( 'staff without refund rights cannot cancel with refund', 'wpcap_refund_forbidden' === a135_code( ServiceBookings::cancel( $a135_b5, array( 'actor' => 'admin', 'refund' => true ) ) ) && 'confirmed' === a135_status( $a135_b5 ) );
$a135_norefund = ServiceBookings::cancel( $a135_b5, array( 'actor' => 'admin', 'refund' => false, 'reason' => 'Kulanz ohne Erstattung' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'staff cancels after the deadline without refund', 'cancelled' === ( $a135_norefund['status'] ?? '' ) && null === $a135_norefund['refund'] && 0 === count( a135_refunds( $a135_o5 ) ) && 'paid' === Orders::get( $a135_o5 )->payment_status );
// Cancelling while the payment is open cancels the order.
$a135_s6 = a135_slot( $a135_paid, 10 * 1440 );
list( $a135_b6, $a135_t6 ) = a135_book( $a135_s6, 'a135-open@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b6, array( 'provider' => 'manual' ) );
$a135_o6 = (int) ServiceBookings::booking( $a135_b6 )->order_id;
ServiceBookingFlow::rest_cancel( a135_req( $a135_b6, array( 'token' => $a135_t6 ) ) );
check( 'cancel while the payment is open: order cancelled, no refund', 'cancelled' === a135_status( $a135_b6 ) && 'cancelled' === Orders::get( $a135_o6 )->status && 0 === count( a135_refunds( $a135_o6 ) ) );
// Refund made elsewhere (order admin / provider dashboard) cancels the booking.
list( $a135_b7 ) = a135_book( a135_slot( $a135_paid, 11 * 1440 ), 'a135-ext@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b7, array( 'provider' => 'manual' ) );
$a135_o7 = (int) ServiceBookings::booking( $a135_b7 )->order_id;
Orders::confirm_manual_payment( $a135_o7 );
$GLOBALS['wpcap_test_mails'] = array();
Orders::mark_refunded( $a135_o7, 'dashboard' );
check( 'order refunded elsewhere → confirmed booking cancelled and customer informed', 'cancelled' === a135_status( $a135_b7 ) && a135_mails_to( 'a135-ext@example.com', 'Buchung storniert' ) );

echo "A135: order portal self-service\n";
list( $a135_b8 ) = a135_book( a135_slot( $a135_paid, 12 * 1440 ), 'a135-portal@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
ServiceBookings::confirm( $a135_b8, array( 'provider' => 'manual' ) );
$a135_o8 = Orders::get( (int) ServiceBookings::booking( $a135_b8 )->order_id );
Orders::confirm_manual_payment( (int) $a135_o8->order_id );
$a135_o8 = Orders::get( (int) $a135_o8->order_id );
$a135_portal_target = a135_slot( $a135_paid2, 13 * 1440 );
$a135_html = ServiceBookingFlow::portal_html( $a135_o8 );
check( 'portal card lists the booking with rebook options (same price only) and cancel', false !== strpos( $a135_html, 'Deine Termine' ) && false !== strpos( $a135_html, 'Motorboot 1' ) && false !== strpos( $a135_html, 'value="' . $a135_portal_target . '"' ) && false === strpos( $a135_html, 'value="' . $a135_t_other . '"' ) && false !== strpos( $a135_html, 'wpcap_service_op' ) && false !== strpos( $a135_html, 'Termin stornieren' ) );
check( 'portal: wrong nonce refused', 'wpcap_service_nonce' === a135_code( ServiceBookingFlow::handle_portal_request( $a135_o8, array( 'wpcap_service_op' => 'cancel', 'booking_id' => $a135_b8, '_wpcap_service_nonce' => 'x' ) ) ) );
check( 'portal: another order of a different buyer cannot touch the booking', 'wpcap_booking_not_found' === a135_code( ServiceBookingFlow::handle_portal_request( Orders::get( $a135_o6 ), array( 'wpcap_service_op' => 'cancel', 'booking_id' => $a135_b8, '_wpcap_service_nonce' => 'n' ) ) ) && 'confirmed' === a135_status( $a135_b8 ) );
check( 'portal: rebook as the customer', 'rebooked' === ServiceBookingFlow::handle_portal_request( $a135_o8, array( 'wpcap_service_op' => 'rebook', 'booking_id' => $a135_b8, 'slot_id' => $a135_portal_target, '_wpcap_service_nonce' => 'n' ) ) && $a135_portal_target === (int) ServiceBookings::booking( $a135_b8 )->slot_id );
check( 'portal: cancel as the customer (refund), result flags', 'cancelled' === ServiceBookingFlow::handle_portal_request( $a135_o8, array( 'wpcap_service_op' => 'cancel', 'booking_id' => $a135_b8, '_wpcap_service_nonce' => 'n' ) ) && 1 === count( a135_refunds( (int) $a135_o8->order_id ) ) && 'deadline' === ServiceBookingFlow::result_flag( new WP_Error( 'wpcap_booking_cancel_deadline', 'x' ) ) && 'unavailable' === ServiceBookingFlow::result_flag( new WP_Error( 'wpcap_slot_full', 'x' ) ) );
check( 'portal card after the cancel shows the notice and no active booking', false !== strpos( ServiceBookingFlow::portal_html( $a135_o8, 'cancelled' ), 'storniert' ) && '' === ServiceBookingFlow::portal_html( $a135_o8 ) );
$a135_portal_src = (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' );
check( 'order portal wires the card and the POST handler', false !== strpos( $a135_portal_src, 'ServiceBookingFlow::portal_html' ) && false !== strpos( $a135_portal_src, 'ServiceBookingFlow::handle_portal_request' ) );

echo "A135: staff actions on the admin page\n";
list( $a135_b9 ) = a135_book( a135_slot( $a135_free, 14 * 1440 ), 'a135-staff@example.com' );
$a135_free_target = a135_slot( $a135_free, 15 * 1440 );
check( 'handler registered', isset( Menu::handlers()['wpcap_service_booking_action'] ) );
check( 'staff confirm, rebook and cancel', 'confirmed' === ( ServiceSlotsPage::booking_action( array( 'booking_id' => $a135_b9, 'op' => 'confirm' ) )['status'] ?? '' ) && $a135_free_target === ( ServiceSlotsPage::booking_action( array( 'booking_id' => $a135_b9, 'op' => 'rebook', 'slot_id' => $a135_free_target ) )['slot_id'] ?? 0 ) && 'cancelled' === ( ServiceSlotsPage::booking_action( array( 'booking_id' => $a135_b9, 'op' => 'cancel', 'reason' => 'Sturm' ) )['status'] ?? '' ) );
list( $a135_b10 ) = a135_book( a135_slot( $a135_free, 16 * 1440 ), 'a135-staff2@example.com' );
$a135_saved_get = $_GET;
$_GET = array( 'event_id' => (string) $a135_event );
ob_start();
try { ServiceSlotsPage::render(); $a135_page = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $a135_page = 'EXCEPTION ' . $e->getMessage(); }
$_GET = $a135_saved_get;
check( 'booking list shows actions, the order link and the price field', false !== strpos( $a135_page, 'wpcap_service_booking_action' ) && false !== strpos( $a135_page, 'Buchung stornieren' ) && false !== strpos( $a135_page, 'name="ticket_type_id"' ) );
check( 'admin page renders without errors', false === strpos( $a135_page, 'EXCEPTION' ) && false !== strpos( $a135_page, 'name="booking_id" value="' . $a135_b10 . '"' ) );
$GLOBALS['wpcap_test_user_id'] = 4135;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=4135" );
check( 'staff without event rights: no booking actions', is_wp_error( ServiceSlotsPage::booking_action( array( 'booking_id' => $a135_b10, 'op' => 'cancel' ) ) ) && 'held' === a135_status( $a135_b10 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A135: concurrency – parallel processes\n";
$a135_race_res = ServiceBookings::create_resource( $a135_event, array( 'name' => 'Rennboot', 'capacity' => 1 ) );
$a135_race = a135_slot( $a135_race_res, 20 * 1440, 1 );
$a135_out = a135_parallel( array_map( static function ( $i ) use ( $a135_race ) { return array( 'reserve', (string) $a135_race, "a135-race$i@example.com" ); }, range( 1, 8 ) ) );
check( 'parallel reserve of one place: exactly one of 8 wins (' . implode( ',', $a135_out ) . ')', 1 === count( array_keys( $a135_out, 'OK', true ) ) && 1 === ServiceBookings::booked( $a135_race ) );
$a135_skipper = ServiceProviders::save( array( 'name' => 'Skipper Race' ) );
$a135_jobs = array();
$a135_rows = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$a135_r = ServiceBookings::create_resource( $a135_event, array( 'name' => "Jolle R$i", 'capacity' => 1, 'buffer_after' => 15 ) );
	ServiceProviders::assign( $a135_r, array( $a135_skipper ) );
	$a135_rows[] = a135_slot( $a135_r, 21 * 1440 + $i * 5, 1 );
	$a135_jobs[] = array( 'reserve', (string) end( $a135_rows ), "a135-prov$i@example.com" );
}
$a135_out = a135_parallel( $a135_jobs );
$a135_total = array_sum( array_map( array( ServiceBookings::class, 'booked' ), $a135_rows ) );
check( 'parallel reserve on 6 resources sharing one provider (overlapping): exactly one wins (' . implode( ',', $a135_out ) . ')', 1 === count( array_keys( $a135_out, 'OK', true ) ) && 1 === $a135_total );
$a135_src = a135_slot( $a135_race_res, 22 * 1440, 1 );
list( $a135_mover ) = a135_book( $a135_src, 'a135-mover@example.com' );
$a135_dst = a135_slot( $a135_race_res, 23 * 1440, 1 );
$a135_jobs = array( array( 'rebook', (string) $a135_mover, (string) $a135_dst ) );
for ( $i = 1; $i <= 4; $i++ ) { $a135_jobs[] = array( 'reserve', (string) $a135_dst, "a135-rr$i@example.com" ); }
$a135_out = a135_parallel( $a135_jobs );
$a135_moved = $a135_dst === (int) ServiceBookings::booking( $a135_mover )->slot_id;
check( 'parallel rebook vs. 4 reservations of the last place: exactly one wins, never two (' . implode( ',', $a135_out ) . ')', 1 === count( array_keys( $a135_out, 'OK', true ) ) && 1 === ServiceBookings::booked( $a135_dst ) && ( $a135_moved ? 'OK' === $a135_out[0] && 0 === ServiceBookings::booked( $a135_src ) : 1 === ServiceBookings::booked( $a135_src ) ) );

echo "A135: concurrency – deterministic interleaving\n";
// A reservation started while a rebooking holds its locks (after its checks, before the move) must
// wait for it; it gives up after 1 s (retryable) instead of reading the old state. After the commit
// the target is full.
$a135_src2 = a135_slot( $a135_race_res, 24 * 1440, 1 );
list( $a135_mover2 ) = a135_book( $a135_src2, 'a135-mover2@example.com' );
$a135_dst2 = a135_slot( $a135_race_res, 25 * 1440, 1 );
$a135_inside = array();
$a135_cb = static function ( $booking_id, $slot_id ) use ( &$a135_inside, $a135_dst2 ) {
	$a135_inside = a135_parallel( array( array( 'reserve', (string) $a135_dst2, 'a135-interleave@example.com' ) ), 1 );
};
add_action( 'wpcap_service_booking_rebooking', $a135_cb, 10, 2 );
$a135_det = ServiceBookings::rebook( $a135_mover2, $a135_dst2 );
remove_action( 'wpcap_service_booking_rebooking', $a135_cb, 10 );
check( 'reservation during the rebooking transaction waits for its locks (gives up retryable)', ! is_wp_error( $a135_det ) && array( 'wpcap_db_busy' ) === $a135_inside );
$a135_after = a135_book( $a135_dst2, 'a135-interleave@example.com' );
check( 'after the rebooking committed the target is full; source free', 'wpcap_slot_full' === a135_code( $a135_after ) && 1 === ServiceBookings::booked( $a135_dst2 ) && 0 === ServiceBookings::booked( $a135_src2 ) );

echo "A135: privacy\n";
list( $a135_b11, $a135_t11 ) = a135_book( a135_slot( $a135_free, 17 * 1440 ), 'a135-erase@example.com' );
PassCaptain\Privacy::erase( 'a135-erase@example.com' );
check( 'privacy erasure removes the booking token', '' === (string) $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_service_bookings WHERE booking_id=$a135_b11" ) && 'wpcap_booking_access_denied' === a135_code( ServiceBookingFlow::verify_token( $a135_b11, $a135_t11 ) ) );
check( 'documented (OPERATIONS.md flow, HOOKS.md hooks, SECURITY.md routes)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/OPERATIONS.md' ), 'Bestellportal' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' ), 'wpcap_service_booking_changed' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/SECURITY.md' ), '/service-bookings/' ) );

// Leave the shared test runtime as it was.
$GLOBALS['wpcap_test_hooks'] = $a135_saved_hooks;
$GLOBALS['wpcap_test_user_id'] = $a135_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $a135_saved_denied;
$GLOBALS['wpcap_test_transients'] = array();
