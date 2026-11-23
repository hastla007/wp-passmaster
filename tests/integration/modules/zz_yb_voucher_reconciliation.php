<?php
/**
 * Read-only reconciliation listing for the voucher system (Integrations\Vouchers\Reconciliation, docs/VOUCHERS.md):
 * versioned contract, rows of checkout and ticket-service orders, cursor paging, lookup by redemption key.
 * Port of the Voucher Captain branch (99cd25a8). Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as YbrCheckout;
use PassCaptain\Integrations\Vouchers\Reconciliation;
use PassCaptain\Integrations\Vouchers\TicketService as YbrTicketService;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;

echo "Voucher reconciliation listing\n";

final class YB_Reconciliation_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public function quote( array $context ) { return array( 'amount_minor' => min( 1000, (int) $context['amount_minor'] ), 'currency' => $context['currency'] ); }
	public function reserve( array $quote, $key ) { return array( 'redemption_key' => 'ybr-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { return array( 'redemption_key' => $reservation['redemption_key'] ); }
	public function release( array $reservation, $key ) { return true; }
	public function refund( array $redemption, $amount, $key ) { return true; }
}

check( 'yb: the reconciliation contract is versioned for integrations', class_exists( Reconciliation::class ) && defined( Reconciliation::class . '::VERSION' ) && 1 <= Reconciliation::VERSION && 500 === Reconciliation::MAX_LIMIT );

// A checkout order paid partly by voucher, a ticket-service order and an order without voucher.
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = new YB_Reconciliation_Provider();
$ybr_type = make_type( 20, 10, 2500 );
$ybr_checkout = Orders::create( array( 'email' => 'ybr-c@example.com', 'items' => array( array( 'ticket_type_id' => $ybr_type, 'quantity' => 1 ) ), 'idempotency_key' => 'ybr-checkout' ), array( 'skip_rate_limit' => true ) );
$ybr_applied = YbrCheckout::apply( $ybr_checkout['order_id'], 'YBR-CODE-0001' );
Orders::confirm_manual_payment( $ybr_checkout['order_id'] );
YbrCheckout::commit( $ybr_checkout['order_id'] );
unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] );
$ybr_plain = Orders::create( array( 'email' => 'ybr-p@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'ybr-plain' ), array( 'skip_rate_limit' => true ) );
$ybr_exchange = YbrTicketService::service( null, 1 )->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 777, 'redemption_key' => 'ybr-exchange-key', 'code_hint' => 'EXCH' ), 'ybr-exchange', array( 'ticket_type_id' => $ybr_type, 'quantity' => 1 ), array( 'email' => 'ybr-e@example.com' ) );
check( 'yb: reconciliation fixtures – voucher applied, free order and tickets exchanged', is_array( $ybr_applied ) && is_array( $ybr_plain ) && 1000 === (int) $ybr_applied['applied_minor'] && is_array( $ybr_exchange ) && 'issued' === $ybr_exchange['status'] );

if ( class_exists( Reconciliation::class ) && is_array( $ybr_exchange ) ) {
	$ybr_first = min( (int) $ybr_checkout['order_id'], (int) $ybr_exchange['order_id'] ) - 1;
	$ybr_rows = Reconciliation::orders( array( 'after_id' => $ybr_first, 'limit' => 500 ) );
	$ybr_by_id = array();
	foreach ( $ybr_rows as $ybr_row ) { $ybr_by_id[ $ybr_row['order_id'] ] = $ybr_row; }
	$ybr_c = $ybr_by_id[ (int) $ybr_checkout['order_id'] ] ?? null;
	$ybr_e = $ybr_by_id[ (int) $ybr_exchange['order_id'] ] ?? null;
	check( 'yb: only orders with a voucher are listed', $ybr_c && $ybr_e && ! isset( $ybr_by_id[ (int) $ybr_plain['order_id'] ] ) );
	check( 'yb: checkout row – order data and the stored voucher share', $ybr_c && $ybr_c['public_id'] === Orders::get( $ybr_checkout['order_id'] )->public_id && 'paid' === $ybr_c['payment_status'] && 'EUR' === $ybr_c['currency'] && '' === $ybr_c['exchange_key']
		&& 'checkout' === $ybr_c['voucher']['source'] && 'ybr-wpcap-order-' . $ybr_c['public_id'] === $ybr_c['voucher']['redemption_key'] && 'committed' === $ybr_c['voucher']['status'] && 1000 === $ybr_c['voucher']['amount_minor'] && 1000 === $ybr_c['voucher']['amount_paid_by_voucher'] && 0 === $ybr_c['voucher']['refunded_minor'] && 'multi' === $ybr_c['voucher']['purpose'] );
	check( 'yb: ticket-service row – exchange key and external voucher state', $ybr_e && 'ybr-exchange-key' === $ybr_e['exchange_key'] && 'voucher:ybr-exchange-key' === $ybr_e['provider_reference'] && 'ticket_service' === $ybr_e['voucher']['source'] && 'ybr-exchange-key' === $ybr_e['voucher']['redemption_key'] && 'external' === $ybr_e['voucher']['status'] );
	check( 'yb: rows carry UTC timestamps and no internal snapshot data', $ybr_c && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ybr_c['created_at'] ) && '' !== $ybr_c['updated_at'] && ! array_key_exists( 'voucher_snapshot', $ybr_c ) && ! array_key_exists( 'quote', (array) $ybr_c['voucher'] ) && ! array_key_exists( 'reservation', (array) $ybr_c['voucher'] ) );

	// Cursor paging, both directions, and the limit cap.
	$ybr_page1 = Reconciliation::orders( array( 'after_id' => $ybr_first, 'limit' => 1 ) );
	$ybr_page2 = $ybr_page1 ? Reconciliation::orders( array( 'after_id' => $ybr_page1[0]['order_id'], 'limit' => 1 ) ) : array();
	check( 'yb: after_id pages forward one order at a time', 1 === count( $ybr_page1 ) && 1 === count( $ybr_page2 ) && $ybr_page2[0]['order_id'] > $ybr_page1[0]['order_id'] );
	$ybr_newest = Reconciliation::orders( array( 'order' => 'desc', 'limit' => 2 ) );
	$ybr_older = $ybr_newest ? Reconciliation::orders( array( 'order' => 'desc', 'before_id' => end( $ybr_newest )['order_id'], 'limit' => 2 ) ) : array();
	check( 'yb: order desc lists the newest first; before_id pages back', 2 === count( $ybr_newest ) && $ybr_newest[0]['order_id'] > $ybr_newest[1]['order_id'] && ( ! $ybr_older || $ybr_older[0]['order_id'] < $ybr_newest[1]['order_id'] ) );
	$ybr_flood = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE (voucher_snapshot IS NOT NULL AND voucher_snapshot <> '') OR provider_reference LIKE 'voucher:%'" );
	check( 'yb: at most 500 rows per call, at least one', count( Reconciliation::orders( array( 'limit' => 100000 ) ) ) === min( 500, $ybr_flood ) && 1 === count( Reconciliation::orders( array( 'limit' => 0 ) ) ) );

	// Lookup by redemption key (checkout and ticket service), exact match only.
	$ybr_by_key = Reconciliation::orders( array( 'redemption_key' => 'ybr-wpcap-order-' . $ybr_c['public_id'] ) );
	$ybr_by_exchange = Reconciliation::orders( array( 'redemption_key' => 'ybr-exchange-key' ) );
	check( 'yb: redemption_key finds the checkout order and the ticket-service order', 1 === count( $ybr_by_key ) && (int) $ybr_checkout['order_id'] === $ybr_by_key[0]['order_id'] && 1 === count( $ybr_by_exchange ) && (int) $ybr_exchange['order_id'] === $ybr_by_exchange[0]['order_id'] );
	check( 'yb: an unknown, partial or wildcard redemption key finds nothing', array() === Reconciliation::orders( array( 'redemption_key' => 'ybr-exchange' ) ) && array() === Reconciliation::orders( array( 'redemption_key' => 'ybr-%' ) ) && array() === Reconciliation::orders( array( 'redemption_key' => 'ybr-exchange-ke_' ) ) );
}
$GLOBALS['wpcap_test_transients'] = array();
