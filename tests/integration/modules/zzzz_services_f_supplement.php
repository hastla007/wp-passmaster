<?php
/**
 * Service bookings, part F: rebooking to a dearer slot with a supplementary payment – linked
 * supplementary order through the normal payment flow, the target place held until the supplement
 * is paid or expires, atomic move on payment, expiry (release only), late payment (moved while free,
 * refunded when the place is gone), cancellation while pending, refunds of a moved booking (both
 * orders) and parallel supplements for the last place.
 *
 * Included from run.php after zzzz_services_a_rules.php, _b_lifecycle.php (svc_paid, gateways) and
 * _e_concurrency.php (svc_parallel).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\Services\Portal as SvcPortal;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

final class Svc_Supp_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static int $payments = 0;
	public static int $refunds = 0;
	public function id() { return 'svc_supp'; }
	public function title() { return 'Svc Supplement'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) { self::$payments++; return array( 'provider' => 'svc_supp', 'status' => 'pending', 'redirect_url' => 'https://pay.example.test/s/' . $order['public_id'], 'provider_reference' => 'supp_' . $order['public_id'] ); }
	public function verify_webhook( \WP_REST_Request $request ) { return null; }
	public function supports_refunds() { return true; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { self::$refunds++; return 'suppref_' . $idempotency_key; }
}
add_filter( 'wpcap_payment_gateways', static function ( $list ) { $list[] = new Svc_Supp_Gateway(); return $list; } );
PassCaptain\Payments\Registry::reset();
$sp_providers_before = PassCaptain\Settings::get( 'enabled_providers' );
PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual', 'svc_supp' ) ) );

function sp_supp_row( $booking_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_service_bookings WHERE supplement_for=%d ORDER BY booking_id DESC LIMIT 1", $booking_id ) ); }
function sp_lapse( $supp ) {
	global $wpdb;
	$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", $past, (int) $supp->order_id ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", $past, (int) $supp->booking_id ) );
}

echo "Service bookings: dearer rebooking with supplementary payment\n";
list( $sp_event, $sp_type ) = svc_event( 3000 );
$sp_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$sp_type" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $sp_occ, 'name' => 'Premium', 'price_minor' => 5000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$sp_dear_type = (int) $wpdb->insert_id;
$sp_res = svc_resource( $sp_event, array( 'ticket_type_id' => $sp_type, 'capacity' => 4, 'rules' => array( 'price_policy' => 'refund_difference' ) ) );
$sp_dear = svc_resource( $sp_event, array( 'ticket_type_id' => $sp_dear_type, 'capacity' => 4 ) );
$sp_src = svc_slot( $sp_res, 200 );
$sp_dst = svc_slot( $sp_dear, 210, 60, 2 );
list( $sb, $so, $stok ) = svc_paid( $sp_src, $sp_type, 'supp@svc.test', 2 );
$sp_old_tickets = svc_tickets_of( $sb );
$sp_public = (string) Orders::get( $so )->public_id;
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$sp_portal = array( 'do' => 'order_rebook', 'id' => $sb, 'public_id' => $sp_public, 'access_token' => $stok, 'slot_id' => $sp_dst, 'provider' => 'svc_supp', '_wpnonce' => 'sp-1' );
$r = SvcPortal::perform( $sp_portal );
$supp = sp_supp_row( $sb );
$supp_order = $supp ? Orders::get( (int) $supp->order_id ) : null;
$supp_items = $supp ? Orders::items_for_order( (int) $supp->order_id ) : array();
check( 'dearer target: a supplementary order for the difference (2 × 20 €) is created through the payment flow and its payment page is returned', ! is_wp_error( $r ) && 4000 === (int) ( $r['supplement']['amount_minor'] ?? 0 ) && str_contains( (string) ( $r['supplement']['payment']['redirect_url'] ?? '' ), (string) ( $supp_order->public_id ?? 'x' ) ) && $supp_order && 'pending' === $supp_order->payment_status && 4000 === (int) $supp_order->total_minor && 'svc_supp' === $supp_order->payment_provider && 1 === count( $supp_items ) && $sp_dear_type === (int) $supp_items[0]->ticket_type_id && 2 === (int) $supp_items[0]->quantity );
check( 'until payment the booking stays in its slot and the target place is held by the supplement', $sp_src === (int) ServiceBookings::get( $sb )->slot_id && 'confirmed' === svc_status( $sb ) && 'held' === $supp->status && $sp_dst === (int) $supp->slot_id && $supp->expires_at > gmdate( 'Y-m-d H:i:s' ) && 0 === svc_free( $sp_dst ) && 2 === ServiceResources::booked_quantity( $sp_src ) );
check( 'buyer gets portal access to the supplementary order; audit entry', (bool) array_filter( svc_mails_to( 'supp@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['body'], (string) $supp_order->public_id ) ) && svc_audit( 'service_booking_supplement_created', $sb ) );
$again = SvcPortal::perform( $sp_portal );
check( 'repeating the dearer rebooking reuses the open supplement (no second order)', ! is_wp_error( $again ) && (int) $again['supplement']['order_id'] === (int) $supp->order_id && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_service_bookings WHERE supplement_for=$sb" ) );
$sp_other = svc_slot( $sp_dear, 240, 60, 2 );
$sp_same = svc_slot( $sp_res, 250 );
$other_dear = ServiceBookings::rebook( $sb, $sp_other, array( 'actor' => 'customer', 'idempotency_key' => 'sp-other' ) );
$other_same = ServiceBookings::rebook( $sb, $sp_same, array( 'actor' => 'customer', 'idempotency_key' => 'sp-same' ) );
check( 'while a supplement is open, other rebookings of the booking are refused', is_wp_error( $other_dear ) && 'wpcap_rebook_supplement_open' === $other_dear->get_error_code() && is_wp_error( $other_same ) && 'wpcap_rebook_supplement_open' === $other_same->get_error_code() );
$view = ServiceBookings::public_view( ServiceBookings::get( $sb ) );
$section = SvcPortal::order_section( '', Orders::get( $so ), $stok );
$pay = SvcPortal::perform( array( 'do' => 'order_supplement_pay', 'id' => $sb, 'public_id' => $sp_public, 'access_token' => $stok ) );
$pay_denied = SvcPortal::perform( array( 'do' => 'order_supplement_pay', 'id' => $sb, 'public_id' => $sp_public, 'access_token' => str_repeat( 'a', 64 ) ) );
check( 'portal shows the open supplement with a pay button; paying restarts the provider payment; a wrong token is refused', 4000 === (int) ( $view['supplement']['amount_minor'] ?? 0 ) && str_contains( $section, 'Zuzahlung jetzt bezahlen' ) && ! is_wp_error( $pay ) && 'supplement_pending' === $pay['status'] && str_starts_with( (string) $pay['supplement']['payment']['redirect_url'], 'https://pay.example.test/s/' ) && is_wp_error( $pay_denied ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
Orders::confirm_external_payment( (int) $supp->order_id, 'supp-pay-1' );
$moved = ServiceBookings::get( $sb );
$new_tickets = svc_tickets_of( $sb );
$new_types = array_map( 'intval', $new_tickets ? $wpdb->get_col( "SELECT DISTINCT ticket_type_id FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $new_tickets ) . ')' ) : array() );
check( 'payment moves the booking atomically: target slot, supplementary order, value 60 € + 40 €', $sp_dst === (int) $moved->slot_id && 'confirmed' === $moved->status && (int) $supp->order_id === (int) $moved->order_id && $so === (int) $moved->origin_order_id && 6000 === (int) $moved->origin_minor && 10000 === (int) $moved->amount_minor && 1 === (int) $moved->rebook_count );
check( 'the supplement row is merged; the old place is free and the new place counted once', 'merged' === ServiceBookings::get( (int) $supp->booking_id )->status && 0 === ServiceResources::booked_quantity( $sp_src ) && 2 === ServiceResources::booked_quantity( $sp_dst ) );
check( 'old tickets are revoked and replaced by the supplementary order\'s tickets of the target type', 'revoked' === svc_ticket_status( $sp_old_tickets[0] ) && 'revoked' === svc_ticket_status( $sp_old_tickets[1] ) && 2 === count( $new_tickets ) && array( $sp_dear_type ) === $new_types && 'valid' === svc_ticket_status( $new_tickets[0] ) );
check( 'rebooking mail and audit with the supplement', (bool) array_filter( svc_mails_to( 'supp@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'umgebucht' ) ) && str_contains( (string) svc_audit( 'service_booking_rebooked', $sb )->details, 'supplement_order_id' ) );
ServiceBookings::on_order_paid( (int) $supp->order_id );
check( 'repeated payment hooks change nothing', $sp_dst === (int) ServiceBookings::get( $sb )->slot_id && 2 === ServiceResources::booked_quantity( $sp_dst ) && 'merged' === ServiceBookings::get( (int) $supp->booking_id )->status );
$sp_supp_refunds_before = Svc_Supp_Gateway::$refunds;
$cancelled = ServiceBookings::cancel( $sb, array( 'actor' => 'customer' ) );
check( 'cancelling the moved booking refunds both payments: the supplement (tickets) and the first payment (amount)', ! is_wp_error( $cancelled ) && 'cancelled' === $cancelled['status'] && 4000 === (int) Orders::get( (int) $supp->order_id )->refunded_minor && 6000 === (int) Orders::get( $so )->refunded_minor && 'revoked' === svc_ticket_status( $new_tickets[0] ) && $sp_supp_refunds_before + 1 === Svc_Supp_Gateway::$refunds );

echo "Service bookings: supplementary payment expiry and late payment\n";
$ex_src = svc_slot( $sp_res, 260 );
$ex_dst = svc_slot( $sp_dear, 270, 60, 1 );
list( $eb ) = svc_paid( $ex_src, $sp_type, 'expire@svc.test', 1 );
$eb_tickets = svc_tickets_of( $eb );
$er = ServiceBookings::rebook( $eb, $ex_dst, array( 'actor' => 'customer', 'idempotency_key' => 'ex-1', 'provider' => 'svc_supp' ) );
$es = sp_supp_row( $eb );
check( 'supplement holds the last target place', ! is_wp_error( $er ) && 2000 === (int) $er['supplement']['amount_minor'] && 0 === svc_free( $ex_dst ) );
$taken = svc_book( $ex_dst, 'other@svc.test' );
check( 'a third party cannot take the held place', is_wp_error( $taken ) && 'wpcap_slot_full' === $taken->get_error_code() );
sp_lapse( $es );
Orders::expire_holds();
$es_after = ServiceBookings::get( (int) $es->booking_id );
check( 'unpaid supplement expires with its order: target place released, booking and tickets unchanged', 'expired' === $es_after->status && 'expired' === Orders::get( (int) $es->order_id )->payment_status && 1 === svc_free( $ex_dst ) && $ex_src === (int) ServiceBookings::get( $eb )->slot_id && 'confirmed' === svc_status( $eb ) && 'valid' === svc_ticket_status( $eb_tickets[0] ) && $eb_tickets === svc_tickets_of( $eb ) );
$er2 = ServiceBookings::rebook( $eb, $ex_dst, array( 'actor' => 'customer', 'idempotency_key' => 'ex-2', 'provider' => 'svc_supp' ) );
$es2 = sp_supp_row( $eb );
check( 'after expiry a new supplement can be started (new order)', ! is_wp_error( $er2 ) && (int) $es2->order_id !== (int) $es->order_id && 'held' === $es2->status );
sp_lapse( $es2 );
Orders::confirm_external_payment( (int) $es2->order_id, 'late-free' );
check( 'late payment of a lapsed supplement still moves the booking while the place is free', $ex_dst === (int) ServiceBookings::get( $eb )->slot_id && 'merged' === ServiceBookings::get( (int) $es2->booking_id )->status && 'revoked' === svc_ticket_status( $eb_tickets[0] ) && 1 === ServiceResources::booked_quantity( $ex_dst ) );
// Late payment after the place was taken.
$lt_src = svc_slot( $sp_res, 280 );
$lt_dst = svc_slot( $sp_dear, 290, 60, 1 );
list( $lb, $lo ) = svc_paid( $lt_src, $sp_type, 'late@svc.test', 1 );
ServiceBookings::rebook( $lb, $lt_dst, array( 'actor' => 'customer', 'idempotency_key' => 'lt-1', 'provider' => 'svc_supp' ) );
$ls = sp_supp_row( $lb );
sp_lapse( $ls );
$thief = svc_book( $lt_dst, 'thief@svc.test' );
Orders::confirm_external_payment( (int) $ls->order_id, 'late-taken' );
$ls_after = ServiceBookings::get( (int) $ls->booking_id );
check( 'late payment after the place was taken: no overbooking, booking stays, supplement refunded in full', ! is_wp_error( $thief ) && 'cancelled' === $ls_after->status && 'supplement_capacity_lost' === $ls_after->cancel_reason && $lt_src === (int) ServiceBookings::get( $lb )->slot_id && 'confirmed' === svc_status( $lb ) && 1 === ServiceResources::booked_quantity( $lt_dst ) && 2000 === (int) Orders::get( (int) $ls->order_id )->refunded_minor && 0 === (int) Orders::get( $lo )->refunded_minor && (int) $ls_after->refund_id > 0 );
ServiceBookings::on_order_paid( (int) $ls->order_id );
check( 'repeated hooks do not refund the supplement twice', 1 === count( Refunds::for_order( (int) $ls->order_id ) ) );
// Booking cancelled while its supplement is open.
$cx_src = svc_slot( $sp_res, 300 );
$cx_dst = svc_slot( $sp_dear, 310, 60, 1 );
list( $cb ) = svc_paid( $cx_src, $sp_type, 'cxl@svc.test', 1 );
ServiceBookings::rebook( $cb, $cx_dst, array( 'actor' => 'customer', 'idempotency_key' => 'cx-1', 'provider' => 'svc_supp' ) );
$cs = sp_supp_row( $cb );
ServiceBookings::cancel( $cb, array( 'actor' => 'customer' ) );
check( 'cancelling the booking drops its open supplement and the unpaid supplementary order', 'cancelled' === ServiceBookings::get( (int) $cs->booking_id )->status && 'cancelled' === Orders::get( (int) $cs->order_id )->status && 1 === svc_free( $cx_dst ) );
// Policy and scope.
$np_res = svc_resource( $sp_event, array( 'ticket_type_id' => $sp_type, 'capacity' => 2 ) );
list( $nb ) = svc_paid( svc_slot( $np_res, 320 ), $sp_type, 'nopolicy@svc.test', 1 );
$np = ServiceBookings::rebook( $nb, svc_slot( $sp_dear, 330, 60, 1 ), array( 'actor' => 'customer', 'idempotency_key' => 'np-1' ) );
check( 'price_policy same_price still refuses dearer targets', is_wp_error( $np ) && 'wpcap_rebook_price_higher' === $np->get_error_code() );
$bad_provider = ServiceBookings::rebook( $lb, svc_slot( $sp_dear, 335, 60, 1 ), array( 'actor' => 'customer', 'idempotency_key' => 'bp-1', 'provider' => 'nope' ) );
check( 'a supplement needs an enabled payment provider', is_wp_error( $bad_provider ) && 'wpcap_provider_unavailable' === $bad_provider->get_error_code() && 'cancelled' === sp_supp_row( $lb )->status );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $sp_occ, 'name' => 'Deluxe', 'price_minor' => 8000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$sp_deluxe = svc_resource( $sp_event, array( 'ticket_type_id' => (int) $wpdb->insert_id, 'capacity' => 2 ) );
ServiceResources::save_resource( array( 'name' => 'Premium', 'rules' => array( 'price_policy' => 'refund_difference' ) ), $sp_dear );
$second = ServiceBookings::rebook( $eb, svc_slot( $sp_deluxe, 338, 60, 1 ), array( 'actor' => 'customer', 'idempotency_key' => 'sec-1', 'provider' => 'svc_supp' ) );
check( 'an already moved booking (first payment on another order) cannot get a second supplement', is_wp_error( $second ) && 'wpcap_rebook_price_higher' === $second->get_error_code() );

echo "Concurrency: parallel supplements for the last dearer place\n";
$cc_src_a = svc_slot( $sp_res, 340 );
$cc_src_b = svc_slot( $sp_res, 350 );
$cc_dst = svc_slot( $sp_dear, 360, 60, 1 );
list( $ca ) = svc_paid( $cc_src_a, $sp_type, 'para@svc.test', 1 );
list( $cbb ) = svc_paid( $cc_src_b, $sp_type, 'parb@svc.test', 1 );
$out = svc_parallel( array( array( 'rebook', $ca, $cc_dst ), array( 'rebook', $cbb, $cc_dst ) ) );
$held = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_service_bookings WHERE slot_id=$cc_dst AND status='held'" );
$open_orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT r.order_id) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.ticket_type_id=$sp_dear_type AND r.status='active' AND o.status='pending_payment' AND o.customer_email IN ('para@svc.test','parb@svc.test')" );
check( 'two parallel dearer rebookings into the last place: one supplement, the loser gets "full" and its order is cancelled', 1 === svc_ok( $out ) && 1 === count( array_filter( $out, static fn( $o ) => 'ERR:wpcap_slot_full' === $o ) ) && 1 === $held && 1 === $open_orders && 0 === svc_free( $cc_dst ) );
$winner = sp_supp_row( $ca ) ?: sp_supp_row( $cbb );
$winner_booking = (int) $winner->supplement_for;
$pay_procs = array();
for ( $i = 0; $i < 2; $i++ ) { Orders::confirm_external_payment( (int) $winner->order_id, 'par-pay-' . $i ); }
check( 'repeated payment confirmations move the winner exactly once', $cc_dst === (int) ServiceBookings::get( $winner_booking )->slot_id && 1 === (int) ServiceBookings::get( $winner_booking )->rebook_count && 1 === ServiceResources::booked_quantity( $cc_dst ) );

PassCaptain\Settings::update( array( 'enabled_providers' => $sp_providers_before ) );
