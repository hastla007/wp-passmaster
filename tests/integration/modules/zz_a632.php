<?php
/**
 * A632: an order with a voucher expires (reservation released, amount due stays reduced). A late payment
 * of the reduced amount (webhook, manual confirmation) reserves the voucher again before it is booked;
 * if that fails no tickets are issued (needs_review / paid_unallocated, reason voucher_released).
 */

// phpcs:ignoreFile

use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as A632Checkout;
use PassCaptain\Orders;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

final class A632_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public $reserve_error = null;
	public function quote( array $context ) { return array( 'amount_minor' => min( 1500, (int) $context['amount_minor'] ), 'currency' => $context['currency'] ); }
	public function reserve( array $quote, $key ) {
		$this->calls[] = array( 'reserve', $key );
		if ( $this->reserve_error && false !== strpos( $key, '-late-' ) ) { return $this->reserve_error; }
		return array( 'reservation' => 'r-' . $key, 'amount_minor' => $quote['amount_minor'] );
	}
	public function commit( array $reservation, $key ) { $this->calls[] = array( 'commit', $key ); return array( 'redemption' => 'd-' . $key ); }
	public function release( array $reservation, $key ) { $this->calls[] = array( 'release', $key ); return true; }
	public function refund( array $redemption, $amount, $key ) { $this->calls[] = array( 'refund', $amount, $key ); return true; }
	public function keys( $kind, $public_id ) { return array_values( array_map( static fn( $c ) => $c[1], array_filter( $this->calls, static fn( $c ) => $kind === $c[0] && false !== strpos( (string) $c[1], $public_id ) ) ) ); }
}

$a632_previous_filter = $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ?? null;
$a632_provider = new A632_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $a632_provider;
$a632_settings = array( 'late_payment_auto_refund' => Settings::get( 'late_payment_auto_refund' ), 'voucher_default_purpose' => Settings::get( 'voucher_default_purpose' ) );
Settings::update( array( 'late_payment_auto_refund' => 0, 'voucher_default_purpose' => 'multi' ) );
// The plugin bootstrap registers these hooks (Plugin::init()); the test harness does not, so they are
// added here and removed again at the end of this file.
$a632_hooks = array(
	array( 'wpcap_order_paid', array( A632Checkout::class, 'commit' ), 10 ),
	array( 'wpcap_order_cancelled', array( A632Checkout::class, 'release' ), 10 ),
	array( 'wpcap_before_payment_confirm', array( A632Checkout::class, 'prepare_late_payment' ), 10 ),
	array( 'wpcap_order_recovering', array( A632Checkout::class, 'recovering' ), 10 ),
	array( 'wpcap_capacity_released', array( A632Checkout::class, 'on_capacity_released' ), 10 ),
);
foreach ( $a632_hooks as $a632_hook ) { add_filter( $a632_hook[0], $a632_hook[1], $a632_hook[2], 4 ); }
check( 'A632: Checkout::register() adds the late payment hooks', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Integrations/Vouchers/Checkout.php' ), "add_action( 'wpcap_before_payment_confirm', array( __CLASS__, 'prepare_late_payment' ) );" ) );

/** Order (2 × 10,00 €) with a 15,00 € voucher whose hold lapsed and was expired by the cron. */
function a632_expired_order( $type, $email, $provider = 'manual' ) {
	global $wpdb;
	$GLOBALS['wpcap_test_transients'] = array();
	$o = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'a632-' . uniqid() ), array( 'payment_provider' => $provider, 'hold_minutes' => 30 ) );
	A632Checkout::apply( $o['order_id'], 'A632-CODE-' . substr( $email, 0, 4 ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $o['order_id'] ) );
	Orders::expire_holds();
	return $o;
}
function a632_state( $order_id ) { return (string) ( A632Checkout::voucher_state( $order_id )['status'] ?? '' ); }

echo "A632: late payment of an expired voucher order\n";
$a632_type = make_type( 20, 10, 1000 );
$a = a632_expired_order( $a632_type, 'a632-webhook@example.com', 'stripe' );
$a_row = Orders::get( $a['order_id'] );
check( 'A632: expiry releases the voucher, the amount due stays reduced', 'expired' === $a_row->payment_status && 'released' === a632_state( $a['order_id'] ) && 500 === (int) $a_row->total_minor );

// Voucher used up meanwhile: a late webhook over the reduced amount must not issue tickets.
$a632_provider->reserve_error = new WP_Error( 'vcap_voucher_used', 'Gutschein verbraucht', array( 'status' => 409 ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$a_result = Webhooks::process( 'stripe', array( 'event_id' => 'evt_a632_' . uniqid(), 'type' => 'paid', 'order_public_id' => $a['public_id'], 'provider_reference' => 'pi_a632_a', 'amount_minor' => 500, 'currency' => 'EUR' ) );
$a_row = Orders::get( $a['order_id'] );
$a_alert = array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => false !== strpos( (string) $m['subject'], 'Bezahlte Bestellung ohne Tickets' ) );
check( 'A632: a late webhook over the reduced amount without a voucher issues no tickets', 'needs_review' === $a_result && 'needs_review' === $a_row->status && 'paid_unallocated' === $a_row->payment_status && ! tickets( $a['order_id'] ) );
check( 'A632: the voucher was reserved again before the payment was booked', in_array( 'wpcap-order-late-' . $a['public_id'], $a632_provider->keys( 'reserve', $a['public_id'] ), true ) );
check( 'A632: …snapshot stays released, no commit, reason in the audit log and the admin mail', 'released' === a632_state( $a['order_id'] ) && ! $a632_provider->keys( 'commit', $a['public_id'] ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='payment_unallocated' AND object_id=%d AND details LIKE %s", $a['order_id'], '%voucher_released%' ) ) && 1 === count( $a_alert ) && false !== strpos( (string) array_values( $a_alert )[0]['body'], 'Gutschein' ) );

// The voucher is available again: "Zuteilung erneut versuchen" reserves, books and commits it.
$a632_provider->reserve_error = null;
$a_retry = Orders::confirm_external_payment( $a['order_id'], 'retry:user-1' );
check( 'A632: a retried allocation reserves the voucher again, issues the tickets and commits the voucher', true === $a_retry && 'paid' === Orders::get( $a['order_id'] )->payment_status && 2 === count( tickets( $a['order_id'], 'valid' ) ) && 'committed' === a632_state( $a['order_id'] ) && 1 === count( $a632_provider->keys( 'commit', $a['public_id'] ) ) );

// Manual confirmation of an expired bank transfer order: same rule.
$b = a632_expired_order( $a632_type, 'a632-manual@example.com' );
$a632_provider->reserve_error = new WP_Error( 'vcap_voucher_voided', 'Gutschein storniert', array( 'status' => 409 ) );
$b_result = Orders::confirm_manual_payment( $b['order_id'] );
check( 'A632: manually confirming an expired voucher order without the voucher issues no tickets', is_wp_error( $b_result ) && 'wpcap_needs_review' === $b_result->get_error_code() && 'voucher_released' === ( $b_result->get_error_data()['reason'] ?? '' ) && ! tickets( $b['order_id'] ) && 'paid_unallocated' === Orders::get( $b['order_id'] )->payment_status );
$a632_provider->reserve_error = null;

// The voucher is reserved again, but the late payment fails for another reason (sold out): the new
// reservation is given back, and the next attempt uses a new key.
$c_type = make_type( 2, 10, 1000 );
$c = a632_expired_order( $c_type, 'a632-soldout@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
$c_block = Orders::create( array( 'email' => 'a632-other@example.com', 'items' => array( array( 'ticket_type_id' => $c_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a632-block-' . uniqid() ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 30 ) );
$c_result = Orders::confirm_external_payment( $c['order_id'], 'late-transfer' );
check( 'A632: a late payment that fails on capacity gives the re-reserved voucher back', is_wp_error( $c_result ) && 'needs_review' === Orders::get( $c['order_id'] )->status && 'released' === a632_state( $c['order_id'] ) && in_array( 'wpcap-order-late-' . $c['public_id'], $a632_provider->keys( 'reserve', $c['public_id'] ), true ) && 2 === count( $a632_provider->keys( 'release', $c['public_id'] ) ) );
Orders::cancel_order( $c_block['order_id'], 'test' );
$c_retry = Orders::confirm_external_payment( $c['order_id'], 'retry:user-1' );
$c_reserves = $a632_provider->keys( 'reserve', $c['public_id'] );
check( 'A632: the next attempt reserves with a new key and completes', true === $c_retry && 2 === count( tickets( $c['order_id'], 'valid' ) ) && 'committed' === a632_state( $c['order_id'] ) && 'wpcap-order-late-' . $c['public_id'] . '-2' === end( $c_reserves ) );

// Order view: an unpaid order with a released voucher says so and names the amount without voucher.
$d = a632_expired_order( $a632_type, 'a632-view@example.com' );
$a632_html = PassCaptain\Admin\OrdersPage::voucher_released_notice( Orders::get( $d['order_id'] ) );
check( 'A632: the order view explains the released voucher and the amount without it', false !== strpos( $a632_html, 'wurde mit Ablauf bzw. Storno der Bestellung freigegeben' ) && false !== strpos( $a632_html, '20,00' ) && '' === PassCaptain\Admin\OrdersPage::voucher_released_notice( Orders::get( $a['order_id'] ) ) );

Settings::update( $a632_settings );
if ( null === $a632_previous_filter ) { unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ); } else { $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $a632_previous_filter; }
foreach ( $a632_hooks as $a632_hook ) { remove_filter( $a632_hook[0], $a632_hook[1], $a632_hook[2] ); }
