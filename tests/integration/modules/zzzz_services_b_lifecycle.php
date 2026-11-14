<?php
/**
 * Service bookings, part B: payment confirmation, cancellation with refund (incl. voucher share),
 * rebooking with atomic capacity move and price difference, partner confirmation, no-show policy,
 * buyer access (order token, manage token, portal) – with fault injection (provider and database
 * failures) and idempotency checks.
 *
 * Included from run.php after zzzz_services_a_rules.php (svc_* helpers).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\Services\Api as SvcApi;
use PassCaptain\Services\Portal as SvcPortal;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

final class Svc_Refund_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static int $calls = 0;
	public static bool $fail = false;
	public function id() { return 'svc_fake'; }
	public function title() { return 'Svc Fake'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) { return array(); }
	public function verify_webhook( \WP_REST_Request $request ) { return null; }
	public function supports_refunds() { return true; }
	public function refund( array $order, $amount_minor, $idempotency_key ) {
		self::$calls++;
		return self::$fail ? new WP_Error( 'svc_provider_down', 'Provider down' ) : 'svcref_' . $idempotency_key;
	}
}
/** $wpdb stand-in failing selected statements (pattern, times). */
final class Svc_Fault_WPDB {
	public array $rules = array();
	public function __construct( public object $inner ) {}
	public function fail( $pattern, $times = 1 ) { $this->rules[] = compact( 'pattern', 'times' ); return $this; }
	public function __get( $name ) { return $this->inner->$name ?? null; }
	public function __set( $name, $value ) { $this->inner->$name = $value; }
	public function __isset( $name ) { return isset( $this->inner->$name ); }
	public function __call( $name, $args ) { return $this->inner->$name( ...$args ); }
	private function hit( $sql ) {
		foreach ( $this->rules as $i => $rule ) {
			if ( $rule['times'] > 0 && preg_match( $rule['pattern'], (string) $sql ) ) { $this->rules[ $i ]['times']--; $this->inner->last_error = 'Injected fault'; return true; }
		}
		return false;
	}
	public function prepare( $query, ...$args ) { return $this->inner->prepare( $query, ...$args ); }
	public function query( $sql ) { return $this->hit( $sql ) ? false : $this->inner->query( $sql ); }
	public function get_results( $sql, $output = OBJECT ) { return $this->hit( $sql ) ? array() : $this->inner->get_results( $sql, $output ); }
	public function get_row( $sql, $output = OBJECT ) { return $this->hit( $sql ) ? null : $this->inner->get_row( $sql, $output ); }
	public function get_col( $sql ) { return $this->hit( $sql ) ? array() : $this->inner->get_col( $sql ); }
	public function get_var( $sql ) { return $this->hit( $sql ) ? null : $this->inner->get_var( $sql ); }
	public function insert( $table, $data, $format = null ) { return $this->hit( "INSERT INTO $table" ) ? false : $this->inner->insert( $table, $data, $format ); }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return $this->hit( "UPDATE $table SET " . implode( ',', array_keys( $data ) ) ) ? false : $this->inner->update( $table, $data, $where, $format, $where_format ); }
	public function delete( $table, $where, $format = null ) { return $this->hit( "DELETE FROM $table" ) ? false : $this->inner->delete( $table, $where, $format ); }
}
$svc_gateways = static function ( $list ) { $list[] = new Svc_Refund_Gateway(); return $list; };
add_filter( 'wpcap_payment_gateways', $svc_gateways );
PassCaptain\Payments\Registry::reset();
$svc_real_wpdb = $wpdb;

/** Paid booking: hold → order (fake provider) → attach → payment. Returns [booking_id, order_id, access_token]. */
function svc_paid( $slot_id, $type_id, $email, $qty = 1 ) {
	global $wpdb;
	$b = svc_book( $slot_id, $email, $qty );
	if ( is_wp_error( $b ) ) { return array( $b, 0, '' ); }
	$before = count( $GLOBALS['wpcap_test_mails'] );
	$o = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $qty ) ), 'idempotency_key' => 'svcpaid-' . uniqid( '', true ) ) );
	if ( is_wp_error( $o ) ) { return array( $o, 0, '' ); }
	$token = '';
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $m ) { if ( preg_match( '/wpcap_access=([a-f0-9]{64})/', $m['body'], $mm ) ) { $token = $mm[1]; } }
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_orders SET payment_provider='svc_fake' WHERE order_id=" . (int) $o['order_id'] );
	ServiceBookings::attach_order( $b['booking_id'], $o['order_id'] );
	Orders::confirm_external_payment( $o['order_id'], 'svc-pay-' . $o['order_id'] );
	return array( (int) $b['booking_id'], (int) $o['order_id'], $token );
}
function svc_tickets_of( $booking_id ) { return array_map( 'intval', (array) json_decode( (string) ServiceBookings::get( $booking_id )->ticket_ids, true ) ); }
function svc_ticket_status( $ticket_id ) { global $wpdb; return (string) $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id ); }
function svc_jobs( $type ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_jobs WHERE job_type=%s AND status='queued'", $type ) ); }
function svc_slot_past( $slot_id, $hours_ago_end = 2 ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_slots SET starts_at=%s,ends_at=%s WHERE slot_id=%d", gmdate( 'Y-m-d H:i:s', time() - ( $hours_ago_end + 1 ) * 3600 ), gmdate( 'Y-m-d H:i:s', time() - $hours_ago_end * 3600 ), $slot_id ) );
}

echo "Service bookings: payment confirmation\n";
list( $sb_event, $sb_type ) = svc_event( 3000 );
$sb_res = svc_resource( $sb_event, array( 'ticket_type_id' => $sb_type, 'capacity' => 3 ) );
$sb_slot = svc_slot( $sb_res, 72 );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
list( $b1, $o1, $t1 ) = svc_paid( $sb_slot, $sb_type, 'buyer1@svc.test', 2 );
$b1_row = ServiceBookings::get( $b1 );
check( 'payment confirms the held booking and links its tickets and amount', 'confirmed' === $b1_row->status && 2 === count( svc_tickets_of( $b1 ) ) && 6000 === (int) $b1_row->amount_minor && 'EUR' === $b1_row->currency && null === $b1_row->expires_at );
check( 'confirmation mail (German default) and audit', (bool) array_filter( svc_mails_to( 'buyer1@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'Buchung bestätigt' ) && str_contains( $m['body'], 'Plätze: 2' ) ) && svc_audit( 'service_booking_confirmed', $b1 ) );
ServiceBookings::on_order_paid( $o1 );
check( 'repeated payment hooks are idempotent', 'confirmed' === svc_status( $b1 ) && 2 === count( svc_tickets_of( $b1 ) ) );
$unpaid = svc_book( $sb_slot, 'unpaid@svc.test' );
check( 'a paid resource cannot be confirmed without payment', is_wp_error( ServiceBookings::confirm( $unpaid['booking_id'] ) ) && is_wp_error( ServiceBookings::confirm_free( $unpaid['booking_id'], $unpaid['manage_token'] ) ) );
$small = Orders::create( array( 'email' => 'unpaid@svc.test', 'items' => array( array( 'ticket_type_id' => $sb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'svc-small-' . uniqid() ) );
$foreign = Orders::create( array( 'email' => 'someone@svc.test', 'items' => array( array( 'ticket_type_id' => $sb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'svc-foreign-' . uniqid() ) );
$taken = ServiceBookings::attach_order( $unpaid['booking_id'], $o1 );
check( 'attach_order refuses orders of other people and orders whose places are used', is_wp_error( ServiceBookings::attach_order( $unpaid['booking_id'], $foreign['order_id'] ) ) && is_wp_error( $taken ) );
check( 'attach_order links a matching pending order without confirming it', is_array( ServiceBookings::attach_order( $unpaid['booking_id'], $small['order_id'] ) ) && 'held' === svc_status( $unpaid['booking_id'] ) && (int) $small['order_id'] === (int) ServiceBookings::get( $unpaid['booking_id'] )->order_id );
Orders::cancel_order( $small['order_id'], 'test' );
check( 'an unpaid order that is cancelled releases the held booking and queues the waitlist', 'expired' === svc_status( $unpaid['booking_id'] ) && svc_jobs( ServiceBookings::JOB_SLOT_FREED ) > 0 );
// Free resource: confirm with the manage token.
list( $sf_event ) = svc_event();
$sf_res = svc_resource( $sf_event, array( 'capacity' => 2 ) );
$sf_slot = svc_slot( $sf_res, 60 );
$sf = svc_book( $sf_slot, 'free@svc.test' );
check( 'free booking: wrong token refused, manage token confirms', is_wp_error( ServiceBookings::confirm_free( $sf['booking_id'], str_repeat( 'a', 64 ) ) ) && is_array( ServiceBookings::confirm_free( $sf['booking_id'], $sf['manage_token'] ) ) && 'confirmed' === svc_status( $sf['booking_id'] ) );
// Late payment after the hold lapsed and the place was taken: cancelled + refunded, never overbooked.
$sl_slot = svc_slot( $sb_res, 100, 60, 1 );
$late = svc_book( $sl_slot, 'late@svc.test' );
$late_order = Orders::create( array( 'email' => 'late@svc.test', 'items' => array( array( 'ticket_type_id' => $sb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'svc-late-' . uniqid() ) );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='svc_fake' WHERE order_id=" . (int) $late_order['order_id'] );
ServiceBookings::attach_order( $late['booking_id'], $late_order['order_id'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $late['booking_id'] ) );
list( $winner ) = svc_paid( $sl_slot, $sb_type, 'winner@svc.test', 1 );
$calls_before = Svc_Refund_Gateway::$calls;
Orders::confirm_external_payment( $late_order['order_id'], 'svc-late-pay' );
$late_row = ServiceBookings::get( $late['booking_id'] );
check( 'late payment without a free place: booking cancelled (capacity_lost) and refunded, slot not overbooked', 'confirmed' === svc_status( $winner ) && 'cancelled' === $late_row->status && 'capacity_lost' === $late_row->cancel_reason && (int) $late_row->refund_id > 0 && 'completed' === Refunds::get( $late_row->refund_id )['status'] && $calls_before + 1 === Svc_Refund_Gateway::$calls && 1 === ServiceResources::booked_quantity( $sl_slot ) );

echo "Service bookings: cancellation and refund\n";
$c_tickets = svc_tickets_of( $b1 );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$calls_before = Svc_Refund_Gateway::$calls;
$jobs_before = svc_jobs( ServiceBookings::JOB_SLOT_FREED );
$cancel = ServiceBookings::cancel( $b1, array( 'actor' => 'customer' ) );
$b1_row = ServiceBookings::get( $b1 );
$c_refund = Refunds::get( (int) $b1_row->refund_id );
check( 'buyer cancellation within the deadline: cancelled, refund completed via provider, tickets revoked', ! is_wp_error( $cancel ) && 'cancelled' === $b1_row->status && 'completed' === $c_refund['status'] && 6000 === (int) $c_refund['amount_minor'] && $calls_before + 1 === Svc_Refund_Gateway::$calls && 'revoked' === svc_ticket_status( $c_tickets[0] ) && 'revoked' === svc_ticket_status( $c_tickets[1] ) );
check( 'capacity released, waitlist job queued, mail with refund amount, audit', 0 === ServiceResources::booked_quantity( $sb_slot ) - 0 && $jobs_before + 1 === svc_jobs( ServiceBookings::JOB_SLOT_FREED ) && (bool) array_filter( svc_mails_to( 'buyer1@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'storniert' ) && str_contains( $m['body'], '60,00 EUR' ) ) && svc_audit( 'service_booking_cancelled', $b1 ) );
$again = ServiceBookings::cancel( $b1, array( 'actor' => 'customer' ) );
check( 'cancellation is idempotent (no second payout)', ! is_wp_error( $again ) && (int) $again['refund_id'] === (int) $b1_row->refund_id && $calls_before + 1 === Svc_Refund_Gateway::$calls && 1 === count( Refunds::for_order( $o1 ) ) );
$sd_slot = svc_slot( $sb_res, 12 );
list( $bd ) = svc_paid( $sd_slot, $sb_type, 'deadline@svc.test' );
$too_late = ServiceBookings::cancel( $bd, array( 'actor' => 'customer' ) );
check( 'buyer cancellation after the deadline is refused', is_wp_error( $too_late ) && 'wpcap_booking_cancel_deadline' === $too_late->get_error_code() && 'confirmed' === svc_status( $bd ) );
$bd_tickets = svc_tickets_of( $bd );
$no_refund = ServiceBookings::cancel( $bd, array( 'actor' => 'admin', 'refund' => false ) );
check( 'staff may cancel after the deadline without refund; the tickets are revoked anyway', ! is_wp_error( $no_refund ) && 'admin_no_refund' === ServiceBookings::get( $bd )->cancel_reason && 0 === (int) ServiceBookings::get( $bd )->refund_id && 'revoked' === svc_ticket_status( $bd_tickets[0] ) );
// Fault injection: provider refund fails → booking stays cancelled, refund retried by the job exactly once.
list( $bf ) = svc_paid( $sb_slot, $sb_type, 'fault@svc.test' );
Svc_Refund_Gateway::$fail = true;
$calls_before = Svc_Refund_Gateway::$calls;
$fault_cancel = ServiceBookings::cancel( $bf, array( 'actor' => 'customer' ) );
$bf_row = ServiceBookings::get( $bf );
check( 'provider failure: booking cancelled, refund row failed, retry job queued, buyer told it is in progress', 'cancelled' === $bf_row->status && 'failed' === Refunds::get( $bf_row->refund_id )['status'] && 'svc_provider_down' === ( $fault_cancel['refund_error'] ?? '' ) && 1 === svc_jobs( ServiceBookings::JOB_REFUND ) && svc_audit( 'service_booking_refund_failed', $bf ) && str_contains( end( $GLOBALS['wpcap_test_mails'] )['body'], 'wird gerade bearbeitet' ) );
Svc_Refund_Gateway::$fail = false;
$job_ok = ServiceBookings::handle_job( false, ServiceBookings::JOB_REFUND, array( 'booking_id' => $bf ) );
$job_again = ServiceBookings::handle_job( false, ServiceBookings::JOB_REFUND, array( 'booking_id' => $bf ) );
check( 'retry job completes the same refund once (no double payout)', true === $job_ok && true === $job_again && 'completed' === Refunds::get( $bf_row->refund_id )['status'] && $calls_before + 2 === Svc_Refund_Gateway::$calls && 1 === count( Refunds::for_order( (int) $bf_row->order_id ) ) );
// Fault injection: database failure while cancelling → nothing changes, nothing is paid out.
list( $bx ) = svc_paid( $sb_slot, $sb_type, 'dbfault@svc.test' );
$calls_before = Svc_Refund_Gateway::$calls;
$wpdb = ( new Svc_Fault_WPDB( $svc_real_wpdb ) )->fail( '/UPDATE \S+wpcap_service_bookings SET status/' );
$db_cancel = ServiceBookings::cancel( $bx, array( 'actor' => 'customer' ) );
$wpdb = $svc_real_wpdb;
check( 'database failure during cancellation: booking stays confirmed, no refund, retryable error', is_wp_error( $db_cancel ) && 'wpcap_db_busy' === $db_cancel->get_error_code() && 'confirmed' === svc_status( $bx ) && 0 === (int) ServiceBookings::get( $bx )->refund_id && $calls_before === Svc_Refund_Gateway::$calls );
check( 'the retried cancellation then succeeds', ! is_wp_error( ServiceBookings::cancel( $bx, array( 'actor' => 'customer' ) ) ) && 'completed' === Refunds::get( ServiceBookings::get( $bx )->refund_id )['status'] );
// Approval threshold: buyer refunds above it wait for staff approval.
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 1000 ) );
list( $ba ) = svc_paid( $sb_slot, $sb_type, 'approval@svc.test' );
ServiceBookings::cancel( $ba, array( 'actor' => 'customer' ) );
check( 'buyer refund above the approval threshold waits for approval', 'pending_approval' === Refunds::get( ServiceBookings::get( $ba )->refund_id )['status'] && 'cancelled' === svc_status( $ba ) );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

echo "Service bookings: voucher share goes back through the voucher bridge\n";
final class Svc_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public function quote( array $context ) { return array( 'amount_minor' => min( 1000, (int) $context['amount_minor'] ), 'currency' => $context['currency'] ); }
	public function reserve( array $quote, $key ) { return array( 'reservation' => 'r-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { return array( 'redemption' => 'd-' . $key ); }
	public function release( array $reservation, $key ) { return true; }
	public function refund( array $redemption, $amount, $key ) { $this->calls[] = array( $amount, $key ); return true; }
}
$svc_vp = new Svc_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = $svc_vp;
add_action( 'wpcap_refund_completed', array( PassCaptain\Integrations\Vouchers\Checkout::class, 'refund' ), 20, 2 );
$sv_slot = svc_slot( $sb_res, 130 );
$sv_b = svc_book( $sv_slot, 'voucher@svc.test' );
$sv_o = Orders::create( array( 'email' => 'voucher@svc.test', 'items' => array( array( 'ticket_type_id' => $sb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'svc-voucher-' . uniqid() ) );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='svc_fake' WHERE order_id=" . (int) $sv_o['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::apply( $sv_o['order_id'], 'SVC-VOUCHER-1' );
ServiceBookings::attach_order( $sv_b['booking_id'], $sv_o['order_id'] );
Orders::confirm_external_payment( $sv_o['order_id'], 'svc-voucher-pay' );
PassCaptain\Integrations\Vouchers\Checkout::commit( $sv_o['order_id'] );
$calls_before = Svc_Refund_Gateway::$calls;
ServiceBookings::cancel( $sv_b['booking_id'], array( 'actor' => 'customer' ) );
$sv_refund = Refunds::get( ServiceBookings::get( $sv_b['booking_id'] )->refund_id );
check( 'cancelling a voucher-paid booking: cash part via provider, voucher part back to the voucher', 'completed' === $sv_refund['status'] && 2000 === (int) $sv_refund['amount_minor'] && $calls_before + 1 === Svc_Refund_Gateway::$calls && 1 === count( $svc_vp->calls ) && 1000 === (int) $svc_vp->calls[0][0] );
remove_action( 'wpcap_refund_completed', array( PassCaptain\Integrations\Vouchers\Checkout::class, 'refund' ), 20 );
unset( $GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] );

echo "Service bookings: rebooking\n";
$rb_from = svc_slot( $sb_res, 150, 60, 2 );
$rb_to = svc_slot( $sb_res, 160, 60, 2 );
list( $rb ) = svc_paid( $rb_from, $sb_type, 'rebook@svc.test', 2 );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$calls_before = Svc_Refund_Gateway::$calls;
$moved = ServiceBookings::rebook( $rb, $rb_to, array( 'actor' => 'customer', 'idempotency_key' => 'rb-1' ) );
check( 'same-price rebooking moves the places atomically (old slot freed, new slot taken), no money moves', ! is_wp_error( $moved ) && (int) $rb_to === (int) ServiceBookings::get( $rb )->slot_id && 0 === ServiceResources::booked_quantity( $rb_from ) && 2 === ServiceResources::booked_quantity( $rb_to ) && 0 === $moved['difference_minor'] && $calls_before === Svc_Refund_Gateway::$calls && 1 === (int) ServiceBookings::get( $rb )->rebook_count );
check( 'rebooking mail with old and new date; audit', (bool) array_filter( svc_mails_to( 'rebook@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'umgebucht' ) && str_contains( $m['body'], 'Bisher:' ) ) && svc_audit( 'service_booking_rebooked', $rb ) );
$repeat = ServiceBookings::rebook( $rb, $rb_to, array( 'actor' => 'customer', 'idempotency_key' => 'rb-1' ) );
check( 'rebooking is idempotent per key', ! empty( $repeat['reused'] ) && 1 === (int) ServiceBookings::get( $rb )->rebook_count );
$rb_full = svc_slot( $sb_res, 170, 60, 1 );
$full_move = ServiceBookings::rebook( $rb, $rb_full, array( 'actor' => 'customer', 'idempotency_key' => 'rb-2' ) );
check( 'rebooking into a slot without enough places is refused and changes nothing', is_wp_error( $full_move ) && 'wpcap_slot_full' === $full_move->get_error_code() && (int) $rb_to === (int) ServiceBookings::get( $rb )->slot_id && 2 === ServiceResources::booked_quantity( $rb_to ) );
// Fault injection: the booking UPDATE fails → rollback, places stay where they were.
$wpdb = ( new Svc_Fault_WPDB( $svc_real_wpdb ) )->fail( '/UPDATE \S+wpcap_service_bookings SET slot_id/' );
$fault_move = ServiceBookings::rebook( $rb, $rb_from, array( 'actor' => 'customer', 'idempotency_key' => 'rb-3' ) );
$wpdb = $svc_real_wpdb;
check( 'database failure during rebooking: nothing moved', is_wp_error( $fault_move ) && (int) $rb_to === (int) ServiceBookings::get( $rb )->slot_id && 0 === ServiceResources::booked_quantity( $rb_from ) && 2 === ServiceResources::booked_quantity( $rb_to ) );
$wpdb = ( new Svc_Fault_WPDB( $svc_real_wpdb ) )->fail( '/^COMMIT/' );
$fault_commit = ServiceBookings::rebook( $rb, $rb_from, array( 'actor' => 'customer', 'idempotency_key' => 'rb-4' ) );
$wpdb = $svc_real_wpdb;
check( 'failed commit during rebooking: nothing moved', is_wp_error( $fault_commit ) && (int) $rb_to === (int) ServiceBookings::get( $rb )->slot_id && 2 === ServiceResources::booked_quantity( $rb_to ) );
$rb_soon = svc_slot( $sb_res, 10, 60, 2 );
list( $rb2 ) = svc_paid( $rb_soon, $sb_type, 'rebook2@svc.test' );
$late_move = ServiceBookings::rebook( $rb2, $rb_from, array( 'actor' => 'customer', 'idempotency_key' => 'rb-5' ) );
$admin_move = ServiceBookings::rebook( $rb2, $rb_from, array( 'actor' => 'admin', 'idempotency_key' => 'rb-6' ) );
check( 'rebooking deadline binds buyers, not staff', is_wp_error( $late_move ) && 'wpcap_rebook_deadline' === $late_move->get_error_code() && ! is_wp_error( $admin_move ) && (int) $rb_from === (int) ServiceBookings::get( $rb2 )->slot_id );
// Price difference.
$cheap_type = (int) $wpdb->get_var( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $sb_type );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$sb_type" ), 'name' => 'Günstig', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$cheap_type = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$sb_type" ), 'name' => 'Teuer', 'price_minor' => 5000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$dear_type = (int) $wpdb->insert_id;
$cheap_res = svc_resource( $sb_event, array( 'ticket_type_id' => $cheap_type, 'capacity' => 5 ) );
$dear_res = svc_resource( $sb_event, array( 'ticket_type_id' => $dear_type, 'capacity' => 5 ) );
$cheap_slot = svc_slot( $cheap_res, 90 );
$dear_slot = svc_slot( $dear_res, 90 );
list( $pd ) = svc_paid( svc_slot( $sb_res, 180 ), $sb_type, 'price@svc.test', 2 );
$same_only = ServiceBookings::rebook( $pd, $cheap_slot, array( 'actor' => 'customer', 'idempotency_key' => 'pd-1' ) );
check( 'policy same_price refuses a cheaper target', is_wp_error( $same_only ) && 'wpcap_rebook_price' === $same_only->get_error_code() );
ServiceResources::save_resource( array( 'name' => 'Boot PD', 'rules' => array( 'price_policy' => 'refund_difference' ) ), $sb_res );
$options = array_map( static fn( $s ) => (int) $s->slot_id, ServiceBookings::rebook_options( ServiceBookings::get( $pd ) ) );
check( 'rebook options list the cheaper slot but never a dearer or full one', in_array( $cheap_slot, $options, true ) && ! in_array( $dear_slot, $options, true ) && ! in_array( $rb_full, $options, true ) );
$dear_move = ServiceBookings::rebook( $pd, $dear_slot, array( 'actor' => 'customer', 'idempotency_key' => 'pd-2' ) );
check( 'a dearer target is refused (supplementary payment: cancel and rebook)', is_wp_error( $dear_move ) && 'wpcap_rebook_price_higher' === $dear_move->get_error_code() );
$calls_before = Svc_Refund_Gateway::$calls;
$cheap_move = ServiceBookings::rebook( $pd, $cheap_slot, array( 'actor' => 'customer', 'idempotency_key' => 'pd-3' ) );
$pd_order = (int) ServiceBookings::get( $pd )->order_id;
$pd_refunds = Refunds::for_order( $pd_order );
check( 'policy refund_difference: cheaper target moves and the difference (2 × 10 €) is refunded as an amount-only refund', ! is_wp_error( $cheap_move ) && 2000 === $cheap_move['difference_minor'] && 1 === count( $pd_refunds ) && 2000 === (int) $pd_refunds[0]['amount_minor'] && '[]' === $pd_refunds[0]['ticket_ids'] && 'completed' === $pd_refunds[0]['status'] && $calls_before + 1 === Svc_Refund_Gateway::$calls && 4000 === (int) ServiceBookings::get( $pd )->amount_minor );
ServiceBookings::cancel( $pd, array( 'actor' => 'customer' ) );
check( 'a later cancellation refunds only what is left (no over-refund)', 6000 === (int) Orders::get( $pd_order )->refunded_minor );

echo "Service bookings: partner confirmation\n";
$pc_partner = PassCaptain\Partners\Repository::save( array( 'name' => 'Bootsverleih West', 'contact_email' => 'west@svc.test', 'currency' => 'EUR' ) );
$pc_res = svc_resource( $sb_event, array( 'ticket_type_id' => $sb_type, 'capacity' => 4, 'partner_id' => $pc_partner, 'rules' => array( 'requires_confirmation' => 1, 'confirmation_hours' => 6 ) ) );
$pc_slot = svc_slot( $pc_res, 96 );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
list( $pc1 ) = svc_paid( $pc_slot, $sb_type, 'pending@svc.test' );
$pc1_row = ServiceBookings::get( $pc1 );
check( 'paid booking waits for the partner with a deadline; the place stays held', 'pending_confirmation' === $pc1_row->status && abs( strtotime( $pc1_row->confirm_by . ' UTC' ) - ( time() + 6 * 3600 ) ) < 120 && 1 === ServiceResources::booked_quantity( $pc_slot ) );
check( 'buyer is told, partner contact gets the confirmation request', (bool) array_filter( svc_mails_to( 'pending@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'wartet auf Bestätigung' ) ) && (bool) array_filter( svc_mails_to( 'west@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'Neue Buchung zur Bestätigung' ) && str_contains( $m['body'], 'page=wpcap-services' ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
PassCaptain\Partners\Repository::set_users( $pc_partner, array( 5151 ) );
$GLOBALS['wpcap_test_user_id'] = 5151;
$pc_req = new WP_REST_Request( 'POST' );
$pc_req->set_param( 'booking_id', $pc1 );
$pc_req->set_param( 'decision', 'confirm' );
$pc_allowed = SvcApi::staff_permission( $pc_req );
$pc_decided = SvcApi::decide( $pc_req );
$GLOBALS['wpcap_test_user_id'] = 7777;
$pc_req2 = new WP_REST_Request( 'POST' );
$pc_req2->set_param( 'booking_id', $pc1 );
$pc_stranger = SvcApi::staff_permission( $pc_req2 );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'the organizer\'s user confirms via REST; strangers are not allowed', true === $pc_allowed && false === $pc_stranger && 'confirmed' === svc_status( $pc1 ) && svc_audit( 'service_booking_partner_confirmed', $pc1 ) );
list( $pc2 ) = svc_paid( $pc_slot, $sb_type, 'declined@svc.test' );
$calls_before = Svc_Refund_Gateway::$calls;
$declined = ServiceBookings::decide( $pc2, 'decline', 'Boot in Reparatur' );
check( 'decline: booking declined, full refund, mail with the reason', ! is_wp_error( $declined ) && 'declined' === svc_status( $pc2 ) && 'completed' === Refunds::get( ServiceBookings::get( $pc2 )->refund_id )['status'] && $calls_before + 1 === Svc_Refund_Gateway::$calls && str_contains( end( $GLOBALS['wpcap_test_mails'] )['body'], 'Boot in Reparatur' ) );
list( $pc3 ) = svc_paid( $pc_slot, $sb_type, 'timeout@svc.test' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET confirm_by=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $pc3 ) );
$sweep = ServiceBookings::sweep();
check( 'no decision in time: the sweep declines and refunds automatically', 1 <= $sweep['confirmation_timeouts'] && 'declined' === svc_status( $pc3 ) && 'confirmation_timeout' === ServiceBookings::get( $pc3 )->cancel_reason && 'completed' === Refunds::get( ServiceBookings::get( $pc3 )->refund_id )['status'] );

echo "Service bookings: no-show policy and check-in\n";
$ns_res = svc_resource( $sb_event, array( 'ticket_type_id' => $sb_type, 'capacity' => 4, 'rules' => array( 'noshow_policy' => 'forfeit', 'noshow_fee_minor' => 1500, 'noshow_block_after' => 1, 'noshow_grace_minutes' => 30 ) ) );
$ns_slot = svc_slot( $ns_res, 200 );
list( $ns1 ) = svc_paid( $ns_slot, $sb_type, 'noshow@svc.test' );
list( $ns2, $ns2_order ) = svc_paid( $ns_slot, $sb_type, 'present@svc.test' );
$ns2_tickets = svc_tickets_of( $ns2 );
do_action( 'wpcap_ticket_checked_in', $ns2_tickets[0], array() );
check( 'a ticket check-in marks the linked booking as checked in', 'checked_in' === svc_status( $ns2 ) );
svc_slot_past( $ns_slot, 1 );
$calls_before = Svc_Refund_Gateway::$calls;
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$sweep = ServiceBookings::sweep();
$ns1_row = ServiceBookings::get( $ns1 );
check( 'after slot end + grace the sweep records the no-show with the fee (forfeit: no refund); checked-in stays', 'no_show' === $ns1_row->status && 1500 === (int) $ns1_row->fee_minor && 0 === (int) $ns1_row->refund_id && $calls_before === Svc_Refund_Gateway::$calls && 'checked_in' === svc_status( $ns2 ) && (bool) array_filter( svc_mails_to( 'noshow@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['body'], '15,00 EUR' ) ) );
$ns_next = svc_slot( $ns_res, 220 );
$blocked = svc_book( $ns_next, 'noshow@svc.test' );
check( 'after N no-shows new bookings of the person are blocked; others can book', is_wp_error( $blocked ) && 'wpcap_booking_blocked' === $blocked->get_error_code() && ! is_wp_error( svc_book( $ns_next, 'present@svc.test' ) ) );
$grace_res = svc_resource( $sb_event, array( 'capacity' => 4, 'rules' => array( 'noshow_policy' => 'mark', 'noshow_grace_minutes' => 600 ) ) );
$grace_slot = svc_slot( $grace_res, 50 );
$grace = svc_book( $grace_slot, 'grace@svc.test' );
ServiceBookings::confirm_free( $grace['booking_id'], $grace['manage_token'] );
svc_slot_past( $grace_slot, 1 );
ServiceBookings::sweep();
check( 'within the grace period nothing is marked; policy none never marks', 'confirmed' === svc_status( $grace['booking_id'] ) );

echo "Service bookings: buyer access (order token, manage token, portal)\n";
$ba_slot = svc_slot( $sb_res, 240, 60, 3 );
$ba_to = svc_slot( $sb_res, 250, 60, 3 );
list( $ba, $ba_order, $ba_token ) = svc_paid( $ba_slot, $sb_type, 'portal@svc.test' );
$ba_public = Orders::get( $ba_order )->public_id;
$req = new WP_REST_Request( 'GET' );
$req->set_param( 'public_id', $ba_public );
$req->set_param( 'access_token', $ba_token );
$list = SvcApi::order_bookings( $req );
$list_data = $list instanceof WP_REST_Response ? $list->get_data() : $list;
$req_bad = new WP_REST_Request( 'GET' );
$req_bad->set_param( 'public_id', $ba_public );
$req_bad->set_param( 'access_token', str_repeat( 'b', 64 ) );
check( 'order token lists the bookings with deadlines and actions; a wrong token gets 403', is_array( $list_data ) && 1 === count( $list_data ) && true === $list_data[0]['can_cancel'] && true === $list_data[0]['can_rebook'] && ! isset( $list_data[0]['customer_email'] ) && is_wp_error( SvcApi::order_bookings( $req_bad ) ) && 403 === SvcApi::order_bookings( $req_bad )->get_error_data()['status'] );
$other_booking = svc_book( $ba_slot, 'stranger@svc.test' );
check( 'an order token cannot reach bookings of other orders', null === ServiceBookings::for_order_access( $ba_public, $ba_token, $other_booking['booking_id'] ) );
$section = SvcPortal::order_section( '', Orders::get( $ba_order ), $ba_token );
check( 'order portal section shows rebook and cancel forms (nonce, no JS)', str_contains( $section, 'Terminbuchungen' ) && str_contains( $section, 'name="do" value="order_rebook"' ) && str_contains( $section, 'name="do" value="order_cancel"' ) && str_contains( $section, '_wpnonce' ) );
$portal_move = SvcPortal::perform( array( 'do' => 'order_rebook', 'id' => $ba, 'public_id' => $ba_public, 'access_token' => $ba_token, 'slot_id' => $ba_to, '_wpnonce' => 'n' ) );
$portal_denied = SvcPortal::perform( array( 'do' => 'order_cancel', 'id' => $ba, 'public_id' => $ba_public, 'access_token' => str_repeat( 'c', 64 ) ) );
check( 'portal: rebook with the order token works, a wrong token is denied', ! is_wp_error( $portal_move ) && (int) $ba_to === (int) ServiceBookings::get( $ba )->slot_id && is_wp_error( $portal_denied ) );
$rest_cancel = new WP_REST_Request( 'POST' );
$rest_cancel->set_param( 'public_id', $ba_public );
$rest_cancel->set_param( 'booking_id', $ba );
$rest_cancel->set_param( 'access_token', $ba_token );
SvcApi::order_cancel( $rest_cancel );
check( 'REST: buyer cancels with the order token', 'cancelled' === svc_status( $ba ) && 'completed' === Refunds::get( ServiceBookings::get( $ba )->refund_id )['status'] );
$mt = svc_book( $sf_slot, 'manage@svc.test' );
$page_cancel_wrong = SvcPortal::perform( array( 'do' => 'booking_cancel', 'id' => $mt['booking_id'], 'token' => str_repeat( 'd', 64 ) ) );
$page_confirm = SvcPortal::perform( array( 'do' => 'booking_confirm', 'id' => $mt['booking_id'], 'token' => $mt['manage_token'] ) );
$page_cancel = SvcPortal::perform( array( 'do' => 'booking_cancel', 'id' => $mt['booking_id'], 'token' => $mt['manage_token'] ) );
check( 'manage-token page: confirm and cancel a free booking; wrong token denied', is_wp_error( $page_cancel_wrong ) && 'confirmed' === ( $page_confirm['status'] ?? '' ) && 'cancelled' === ( $page_cancel['status'] ?? '' ) );
$GLOBALS['wpcap_test_routes'] = array();
SvcApi::register_routes();
check( 'REST routes are registered (buyer, staff, waitlist, passes)', isset( $GLOBALS['wpcap_test_routes']['wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/service-bookings/(?P<booking_id>\d+)/rebook'], $GLOBALS['wpcap_test_routes']['wp-pass-captain/v1/service-bookings/(?P<booking_id>\d+)/decide'], $GLOBALS['wpcap_test_routes']['wp-pass-captain/v1/service-waitlist/(?P<entry_id>\d+)/accept'], $GLOBALS['wpcap_test_routes']['wp-pass-captain/v1/member-passes/redeem-service'] ) );

remove_filter( 'wpcap_payment_gateways', $svc_gateways );
PassCaptain\Payments\Registry::reset();
