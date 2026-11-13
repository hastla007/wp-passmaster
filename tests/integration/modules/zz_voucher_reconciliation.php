<?php
/**
 * Read-only reconciliation listing for the voucher system (Integrations\Vouchers\Reconciliation, docs/VOUCHERS.md):
 * versioned contract, rows of checkout and ticket-service orders, cursor paging, lookup by redemption key.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as VoucherCheckout;
use PassCaptain\Integrations\Vouchers\Reconciliation;
use PassCaptain\Integrations\Vouchers\TicketService;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;

echo "Voucher reconciliation listing\n";

final class VR_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public function quote( array $context ) { return array( 'amount_minor' => min( 1000, (int) $context['amount_minor'] ), 'currency' => $context['currency'] ); }
	public function reserve( array $quote, $key ) { return array( 'redemption_key' => 'vr-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { return array( 'redemption_key' => $reservation['redemption_key'] ); }
	public function release( array $reservation, $key ) { return true; }
	public function refund( array $redemption, $amount, $key ) { return true; }
}

check( 'the reconciliation contract is versioned for integrations', defined( Reconciliation::class . '::VERSION' ) && 1 <= Reconciliation::VERSION && 500 === Reconciliation::MAX_LIMIT );

// A checkout order paid partly by voucher, a ticket-service order and an order without voucher.
$GLOBALS['wpcap_test_transients'] = array(); // Checkout rate limits of earlier modules.
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = new VR_Voucher_Provider();
$vr_type = make_type( 20, 10, 2500 );
$vr_checkout = Orders::create( array( 'email' => 'vr-c@example.com', 'items' => array( array( 'ticket_type_id' => $vr_type, 'quantity' => 1 ) ), 'idempotency_key' => 'vr-checkout' ) );
$vr_applied = VoucherCheckout::apply( $vr_checkout['order_id'], 'VR-CODE-0001' );
Orders::confirm_manual_payment( $vr_checkout['order_id'] );
VoucherCheckout::commit( $vr_checkout['order_id'] );
unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] );
$vr_plain = Orders::create( array( 'email' => 'vr-p@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'vr-plain' ) );
$vr_exchange = TicketService::service( null, 1 )->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 777, 'redemption_key' => 'vr-exchange-key', 'code_hint' => 'EXCH' ), 'vr-exchange', array( 'ticket_type_id' => $vr_type, 'quantity' => 1 ), array( 'email' => 'vr-e@example.com' ) );
check( 'reconciliation fixtures: voucher applied, free order and tickets exchanged', is_array( $vr_applied ) && is_array( $vr_plain ) && 1000 === (int) $vr_applied['applied_minor'] && is_array( $vr_exchange ) && 'issued' === $vr_exchange['status'] );

$vr_first = min( (int) $vr_checkout['order_id'], (int) $vr_exchange['order_id'] ) - 1;
$vr_rows = Reconciliation::orders( array( 'after_id' => $vr_first, 'limit' => 500 ) );
$vr_by_id = array();
foreach ( $vr_rows as $vr_row ) { $vr_by_id[ $vr_row['order_id'] ] = $vr_row; }
$vr_c = $vr_by_id[ (int) $vr_checkout['order_id'] ] ?? null;
$vr_e = $vr_by_id[ (int) $vr_exchange['order_id'] ] ?? null;
check( 'only orders with a voucher are listed', $vr_c && $vr_e && ! isset( $vr_by_id[ (int) $vr_plain['order_id'] ] ) );
check( 'checkout row: order data and the stored voucher share', $vr_c && $vr_c['public_id'] === Orders::get( $vr_checkout['order_id'] )->public_id && 'paid' === $vr_c['payment_status'] && 'EUR' === $vr_c['currency'] && '' === $vr_c['exchange_key']
	&& 'checkout' === $vr_c['voucher']['source'] && 'vr-wpcap-order-' . $vr_c['public_id'] === $vr_c['voucher']['redemption_key'] && 'committed' === $vr_c['voucher']['status'] && 1000 === $vr_c['voucher']['amount_minor'] && 0 === $vr_c['voucher']['refunded_minor'] && in_array( $vr_c['voucher']['purpose'], array( 'single', 'multi' ), true ) );
check( 'ticket-service row: exchange key and external voucher state', $vr_e && 'vr-exchange-key' === $vr_e['exchange_key'] && 'voucher:vr-exchange-key' === $vr_e['provider_reference'] && 'ticket_service' === $vr_e['voucher']['source'] && 'vr-exchange-key' === $vr_e['voucher']['redemption_key'] && 'external' === $vr_e['voucher']['status'] );
check( 'rows carry UTC timestamps and no internal snapshot data', $vr_c && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $vr_c['created_at'] ) && '' !== $vr_c['updated_at'] && ! array_key_exists( 'voucher_snapshot', $vr_c ) && ! array_key_exists( 'quote', (array) $vr_c['voucher'] ) );

// Cursor paging, both directions, and the limit cap.
$vr_page1 = Reconciliation::orders( array( 'after_id' => $vr_first, 'limit' => 1 ) );
$vr_page2 = $vr_page1 ? Reconciliation::orders( array( 'after_id' => $vr_page1[0]['order_id'], 'limit' => 1 ) ) : array();
check( 'after_id pages forward one order at a time', 1 === count( $vr_page1 ) && 1 === count( $vr_page2 ) && $vr_page2[0]['order_id'] > $vr_page1[0]['order_id'] );
$vr_newest = Reconciliation::orders( array( 'order' => 'desc', 'limit' => 2 ) );
$vr_older = $vr_newest ? Reconciliation::orders( array( 'order' => 'desc', 'before_id' => end( $vr_newest )['order_id'], 'limit' => 2 ) ) : array();
check( 'order desc lists the newest first; before_id pages back', 2 === count( $vr_newest ) && $vr_newest[0]['order_id'] > $vr_newest[1]['order_id'] && ( ! $vr_older || $vr_older[0]['order_id'] < $vr_newest[1]['order_id'] ) );
$vr_flood = $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE (voucher_snapshot IS NOT NULL AND voucher_snapshot <> '') OR provider_reference LIKE 'voucher:%'" );
check( 'at most 500 rows per call', count( Reconciliation::orders( array( 'limit' => 100000 ) ) ) === min( 500, (int) $vr_flood ) && 1 === count( Reconciliation::orders( array( 'limit' => 0 ) ) ) );

// Lookup by redemption key (checkout and ticket service), exact match only.
$vr_by_key = Reconciliation::orders( array( 'redemption_key' => 'vr-wpcap-order-' . $vr_c['public_id'] ) );
$vr_by_exchange = Reconciliation::orders( array( 'redemption_key' => 'vr-exchange-key' ) );
check( 'redemption_key finds the checkout order and the ticket-service order', 1 === count( $vr_by_key ) && (int) $vr_checkout['order_id'] === $vr_by_key[0]['order_id'] && 1 === count( $vr_by_exchange ) && (int) $vr_exchange['order_id'] === $vr_by_exchange[0]['order_id'] );
check( 'an unknown or partial redemption key finds nothing', array() === Reconciliation::orders( array( 'redemption_key' => 'vr-exchange' ) ) && array() === Reconciliation::orders( array( 'redemption_key' => 'nope-%' ) ) );
