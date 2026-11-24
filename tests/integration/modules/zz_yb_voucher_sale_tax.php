<?php
/**
 * Single-purpose vouchers only for the VAT of their sale; promotion codes as plain discounts (port of the
 * Voucher Captain branch, docs/VOUCHERS.md "Prüfung des Einzweckgutscheins" and "Rabattcodes").
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as YbVoucherCheckout;
use PassCaptain\Integrations\Vouchers\TicketService as YbTicketService;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

echo "Vouchers: single-purpose only for the VAT of its sale; promotion codes\n";

final class YB_Sale_Tax_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $extra = array();
	public function quote( array $context ) {
		return array_merge( array( 'amount_minor' => min( 1500, (int) $context['amount_minor'] ), 'currency' => $context['currency'] ), (array) ( $this->extra[ $context['code'] ] ?? array() ) );
	}
	public function reserve( array $quote, $key ) { return array( 'reservation' => 'r-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { return array( 'redemption' => 'd-' . $key ); }
	public function release( array $reservation, $key ) { return true; }
	public function refund( array $redemption, $amount, $key ) { return true; }
}
$yb_provider = new YB_Sale_Tax_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $yb_provider;
$GLOBALS['wpcap_test_transients'] = array();
Settings::update( array( 'seller_country' => 'DE', 'voucher_default_purpose' => 'multi', 'auto_invoice' => 0 ) );
$yb_sale = static fn( int $bp, string $cc ) => array( 'purpose' => 'single', 'sale_tax' => array( 'rate_bp' => $bp, 'country' => $cc ) );
$yb_line = static fn( int $bp, string $cc = 'DE' ) => array( 'rate_bp' => $bp, 'country' => $cc );

// Purpose rules.
check( 'yb: promotion codes are their own purpose "discount" (treatment and spellings)', 'discount' === YbVoucherCheckout::purpose( array( 'treatment' => 'discount' ) ) && 'discount' === YbVoucherCheckout::normalize_purpose( 'Rabattaktion' ) && 'single' === YbVoucherCheckout::purpose( array( 'treatment' => 'single' ) ) );
check( 'yb: single-purpose with the VAT of the tickets stays single-purpose', array( 'single', '' ) === YbVoucherCheckout::effective_purpose( 'single', $yb_sale( 700, 'DE' ), array( $yb_line( 700 ), $yb_line( 700 ) ) ) );
check( 'yb: single-purpose sold with another rate is treated as multi-purpose', array( 'multi', 'single_rate' ) === YbVoucherCheckout::effective_purpose( 'single', array( 'quote' => $yb_sale( 700, 'DE' ) ), array( $yb_line( 1900 ) ) ) );
check( 'yb: single-purpose sold in another country is treated as multi-purpose (place of supply of the line)', array( 'multi', 'single_country' ) === YbVoucherCheckout::effective_purpose( 'single', $yb_sale( 2000, 'AT' ), array( $yb_line( 2000, 'DE' ) ) ) && array( 'single', '' ) === YbVoucherCheckout::effective_purpose( 'single', $yb_sale( 2000, 'AT' ), array( $yb_line( 2000, 'AT' ) ) ) );
check( 'yb: a line without country is compared with the seller country', array( 'multi', 'single_country' ) === YbVoucherCheckout::effective_purpose( 'single', $yb_sale( 2000, 'AT' ), array( $yb_line( 2000, '' ) ) ) );
check( 'yb: without sale data or for other purposes nothing changes', array( 'single', '' ) === YbVoucherCheckout::effective_purpose( 'single', array( 'purpose' => 'single' ), array( $yb_line( 700 ) ) ) && array( 'discount', '' ) === YbVoucherCheckout::effective_purpose( 'discount', $yb_sale( 700, 'AT' ), array( $yb_line( 2000 ) ) ) && array( 'multi', '' ) === YbVoucherCheckout::effective_purpose( 'multi', $yb_sale( 700, 'AT' ), array( $yb_line( 2000 ) ) ) );

// Checkout: a single-purpose voucher sold at 7 % for 19 % tickets is a means of payment (full VAT).
$yb_type = make_type( 30, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$yb_type" );
$yb_provider->extra['YB-SPV-RATE-1111'] = $yb_sale( 700, 'DE' );
$yb_sr = Orders::create( array( 'email' => 'yb-spv-rate@example.com', 'items' => array( array( 'ticket_type_id' => $yb_type, 'quantity' => 3 ) ), 'idempotency_key' => 'yb-sr1' ), array( 'skip_rate_limit' => true ) );
$yb_sr_tax = (int) Orders::get( $yb_sr['order_id'] )->tax_minor;
$yb_sr_applied = YbVoucherCheckout::apply( $yb_sr['order_id'], 'YB-SPV-RATE-1111' );
$yb_sr_row = Orders::get( $yb_sr['order_id'] );
$yb_sr_snap = YbVoucherCheckout::voucher_state( $yb_sr['order_id'] );
check( 'yb: checkout – single-purpose voucher sold at 7 % for 19 % tickets is redeemed as means of payment (full VAT)', is_array( $yb_sr_applied ) && 'multi' === $yb_sr_applied['purpose'] && 'single_rate' === ( $yb_sr_snap['purpose_changed'] ?? '' ) && $yb_sr_tax > 0 && $yb_sr_tax === (int) $yb_sr_row->tax_minor && 1500 === (int) $yb_sr_row->total_minor && 1500 === YbVoucherCheckout::voucher_payment_minor( $yb_sr['order_id'] ) );
// The same voucher sold at 19 % stays single-purpose (price reduction).
$yb_provider->extra['YB-SPV-SAME-2222'] = $yb_sale( 1900, 'DE' );
$yb_ss = Orders::create( array( 'email' => 'yb-spv-same@example.com', 'items' => array( array( 'ticket_type_id' => $yb_type, 'quantity' => 3 ) ), 'idempotency_key' => 'yb-ss1' ), array( 'skip_rate_limit' => true ) );
$yb_ss_applied = YbVoucherCheckout::apply( $yb_ss['order_id'], 'YB-SPV-SAME-2222' );
check( 'yb: checkout – single-purpose voucher with the VAT of the tickets reduces the price', is_array( $yb_ss_applied ) && 'single' === $yb_ss_applied['purpose'] && 1500 === (int) Orders::get( $yb_ss['order_id'] )->discount_minor && ! isset( YbVoucherCheckout::voucher_state( $yb_ss['order_id'] )['purpose_changed'] ) );
// Venue in Austria, voucher sold with German VAT.
$yb_at_type = make_type( 30, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$yb_at_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.venue_country='AT' WHERE tt.ticket_type_id=$yb_at_type" );
$yb_provider->extra['YB-SPV-LAND-3333'] = $yb_sale( 1900, 'DE' );
$yb_sc = Orders::create( array( 'email' => 'yb-spv-land@example.com', 'items' => array( array( 'ticket_type_id' => $yb_at_type, 'quantity' => 2 ) ), 'idempotency_key' => 'yb-sc1' ), array( 'skip_rate_limit' => true ) );
$yb_sc_country = (string) $wpdb->get_var( $wpdb->prepare( "SELECT tax_country FROM {$p}wpcap_order_items WHERE order_id=%d", $yb_sc['order_id'] ) );
$yb_sc_applied = YbVoucherCheckout::apply( $yb_sc['order_id'], 'YB-SPV-LAND-3333' );
check( 'yb: checkout – single-purpose voucher with German VAT for an event in Austria is a means of payment', 'AT' === $yb_sc_country && is_array( $yb_sc_applied ) && 'multi' === $yb_sc_applied['purpose'] && 'single_country' === ( YbVoucherCheckout::voucher_state( $yb_sc['order_id'] )['purpose_changed'] ?? '' ) );

// Promotion code: price and VAT reduced, invoice without the single-purpose statement, no voucher-seller claim.
$yb_provider->extra['YB-PROMO-7777'] = array( 'treatment' => 'discount' );
$yb_pr = Orders::create( array( 'email' => 'yb-promo@example.com', 'items' => array( array( 'ticket_type_id' => $yb_type, 'quantity' => 3 ) ), 'idempotency_key' => 'yb-pr1' ), array( 'skip_rate_limit' => true ) );
$yb_pr_applied = YbVoucherCheckout::apply( $yb_pr['order_id'], 'YB-PROMO-7777' );
$yb_pr_row = Orders::get( $yb_pr['order_id'] );
check( 'yb: checkout – promotion code reduces price and VAT', is_array( $yb_pr_applied ) && 'discount' === $yb_pr_applied['purpose'] && 1500 === (int) $yb_pr_row->total_minor && 1500 === (int) $yb_pr_row->discount_minor && Orders::gross_tax( 1500, 1900 ) === (int) $yb_pr_row->tax_minor && 0 === YbVoucherCheckout::voucher_payment_minor( $yb_pr['order_id'] ) );
Orders::confirm_manual_payment( $yb_pr['order_id'] );
YbVoucherCheckout::commit( $yb_pr['order_id'] );
check( 'yb: a promotion code is no claim against a voucher seller', 0 === YbVoucherCheckout::single_purpose_minor( $yb_pr['order_id'] ) );
$yb_pr_inv = Invoices::issue_for_order( $yb_pr['order_id'] );
$yb_pr_html = is_wp_error( $yb_pr_inv ) ? '' : Invoices::render_html( $yb_pr_inv['invoice_id'] );
check( 'yb: invoice of a promotion code says "Rabattcode", never "Umsatzsteuer bereits beim Verkauf abgerechnet"', str_contains( $yb_pr_html, 'Rabattcode …7777' ) && ! str_contains( $yb_pr_html, 'bereits beim Verkauf' ) && ! str_contains( $yb_pr_html, 'Einzweckgutschein' ) );

// A promotion code changes the price as well: refused once a fiscal document was (possibly) sent.
$yb_fd = Orders::create( array( 'email' => 'yb-promo-fiscal@example.com', 'items' => array( array( 'ticket_type_id' => $yb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'yb-fd1' ), array( 'skip_rate_limit' => true ) );
$wpdb->insert( "{$p}wpcap_fiscal_documents", array( 'order_id' => (int) $yb_fd['order_id'], 'seller_id' => 1, 'document_key' => hash( 'sha256', 'yb-fd' . $yb_fd['order_id'] ), 'country_code' => 'AT', 'provider_id' => 'test', 'status' => 'retry', 'attempts' => 1, 'currency' => 'EUR', 'gross_minor' => 1000, 'tax_minor' => 160, 'snapshot' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$yb_provider->extra['YB-PROMO-8888'] = array( 'treatment' => 'discount' );
$yb_fd_applied = YbVoucherCheckout::apply( $yb_fd['order_id'], 'YB-PROMO-8888' );
check( 'yb: a promotion code is refused once a fiscal document was (possibly) sent', is_wp_error( $yb_fd_applied ) && 'wpcap_voucher_fiscal_locked' === $yb_fd_applied->get_error_code() && empty( Orders::get( $yb_fd['order_id'] )->voucher_snapshot ) );

// Ticket service: a single-purpose voucher (19 %) for a 7 % ticket is handled as multi-purpose.
$yb_service = YbTicketService::service( null, 1 );
$yb_ts_type = make_type( 40, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=700 WHERE ticket_type_id=$yb_ts_type" );
$yb_ts = $yb_service->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 9911, 'redemption_key' => 'yb-ts-rate-key', 'code_hint' => '9911', 'amount_minor' => 1000 ) + $yb_sale( 1900, 'DE' ), 'yb-ts-rate', array( 'ticket_type_id' => $yb_ts_type, 'quantity' => 1 ), array( 'email' => 'yb-ts-rate@example.com' ) );
$yb_ts_snap = is_array( $yb_ts ) && ! empty( $yb_ts['order_id'] ) ? YbVoucherCheckout::voucher_state( $yb_ts['order_id'] ) : array();
$yb_ts_row = is_array( $yb_ts ) && ! empty( $yb_ts['order_id'] ) ? Orders::get( $yb_ts['order_id'] ) : null;
check( 'yb: ticket exchange – single-purpose voucher (19 %) for a 7 % ticket is handled as multi-purpose (full VAT)', 'multi' === ( $yb_ts_snap['purpose'] ?? '' ) && 'single_rate' === ( $yb_ts_snap['purpose_changed'] ?? '' ) && $yb_ts_row && Orders::gross_tax( 1000, 700 ) === (int) $yb_ts_row->tax_minor );
$yb_ts2 = $yb_service->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 9912, 'redemption_key' => 'yb-ts-same-key', 'code_hint' => '9912', 'amount_minor' => 1000 ) + $yb_sale( 700, 'DE' ), 'yb-ts-same', array( 'ticket_type_id' => $yb_ts_type, 'quantity' => 1 ), array( 'email' => 'yb-ts-same@example.com' ) );
$yb_ts2_snap = is_array( $yb_ts2 ) && ! empty( $yb_ts2['order_id'] ) ? YbVoucherCheckout::voucher_state( $yb_ts2['order_id'] ) : array();
check( 'yb: ticket exchange – matching VAT keeps the single-purpose voucher (free tickets, value kept)', 'single' === ( $yb_ts2_snap['purpose'] ?? '' ) && '' === ( $yb_ts2_snap['purpose_changed'] ?? '' ) && 1000 === YbTicketService::settlement_value_minor( $yb_ts2['order_id'] ) );
$yb_ts3 = $yb_service->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 9913, 'redemption_key' => 'yb-ts-promo-key', 'code_hint' => '9913', 'treatment' => 'discount' ), 'yb-ts-promo', array( 'ticket_type_id' => $yb_ts_type, 'quantity' => 1 ), array( 'email' => 'yb-ts-promo@example.com' ) );
check( 'yb: ticket exchange – a promotion code gives free tickets without a settlement claim', is_array( $yb_ts3 ) && 'discount' === ( YbVoucherCheckout::voucher_state( $yb_ts3['order_id'] )['purpose'] ?? '' ) && 0 === (int) Orders::get( $yb_ts3['order_id'] )->total_minor && 0 === YbTicketService::settlement_value_minor( $yb_ts3['order_id'] ) );

unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] );
$GLOBALS['wpcap_test_transients'] = array();
