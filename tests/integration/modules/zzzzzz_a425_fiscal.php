<?php
/**
 * A425: the service fee is part of the fiscal receipt of the seller of its ticket line (receipt total =
 * order total). Uses the scriptable fiscal adapter and helpers of zzzzz_x5_fiscal_reconcile.php.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Modules\Fiscalization as Fiscal;
use PassCaptain\Orders;
use PassCaptain\Settings;

echo "A425: service fee on the fiscal receipt\n";
if ( ! function_exists( 'x5_seller' ) || ! function_exists( 'x5_profile' ) || ! function_exists( 'x5_rest' ) ) {
	check( 'A425 fiscal: needs zzzzz_x5_fiscal_reconcile.php (full run)', true );
	return;
}
global $wpdb;
// The fiscal module hooks into the order transaction (registered once by fiscalization.php in a full run).
$a425f_registered = false;
foreach ( $GLOBALS['wpcap_test_hooks']['wpcap_order_creating'] ?? array() as $a425f_cbs ) {
	foreach ( $a425f_cbs as $a425f_cb ) { if ( array( Fiscal::class, 'snapshot_order' ) === $a425f_cb[0] ) { $a425f_registered = true; } }
}
if ( ! $a425f_registered ) { Fiscal::register(); }
$a425f_saved = Settings::all();
$a425f_seller = x5_seller( 'A425 Gebühr GmbH' );
x5_profile( $a425f_seller, 'native' );
Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $a425f_seller ), array( 'provider_id' => 'x5_test', 'enabled' => true, 'configuration' => array( 'region' => 'eu' ) ) ) );
$a425f_type = make_type( 50, 10, 1000 );
$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", array( 'tax_rate_bp' => 1000 ), array( 'ticket_type_id' => $a425f_type ) );
$a425f_assigned = Fiscal::assign_ticket_type( $a425f_type, $a425f_seller, 'platform_seller' );
Settings::update( array( 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200 ) );
$GLOBALS['wpcap_test_transients'] = array();
$a425f_order = Orders::create( array( 'email' => 'a425f-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a425f_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a425f-' . wp_generate_uuid4() ) );
$a425f_doc = is_wp_error( $a425f_order ) ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d ORDER BY document_id LIMIT 1", (int) $a425f_order['order_id'] ) );
$a425f_snap = $a425f_doc ? json_decode( (string) $a425f_doc->snapshot, true ) : array();
$a425f_items = (array) ( $a425f_snap['items'] ?? array() );
$a425f_fee_items = array_values( array_filter( $a425f_items, static fn( $i ) => false !== strpos( (string) $i['description'], 'Servicegebühr' ) ) );
check( 'A425 fiscal: fixture order with a mapped seller and a fee', true === $a425f_assigned && ! is_wp_error( $a425f_order ) && 2000 + 300 + 200 === (int) $a425f_order['total_minor'] && null !== $a425f_doc );
check( 'A425 fiscal: the receipt total equals the order total (fees included)', $a425f_doc && (int) $a425f_order['total_minor'] === (int) $a425f_doc->gross_minor && (int) Orders::get( $a425f_order['order_id'] )->tax_minor === (int) $a425f_doc->tax_minor );
check( 'A425 fiscal: fee positions on the receipt (per ticket ×2, per order ×1) with the ticket rate', 2 === count( $a425f_fee_items ) && 2 === (int) $a425f_fee_items[0]['quantity'] && 300 === (int) $a425f_fee_items[0]['gross_minor'] && 1 === (int) $a425f_fee_items[1]['quantity'] && 1000 === (int) $a425f_fee_items[1]['tax_rate_bp'] );
$a425f_rows = $a425f_doc ? $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,quantity,gross_minor,tax_minor FROM {$wpdb->prefix}wpcap_fiscal_document_items WHERE document_id=%d", (int) $a425f_doc->document_id ) ) : array();
check( 'A425 fiscal: one document row per ticket type carries the fee amounts (refund adjustments per type), quantity = tickets', 1 === count( $a425f_rows ) && 2 === (int) $a425f_rows[0]->quantity && 2500 === (int) $a425f_rows[0]->gross_minor && (int) $a425f_doc->tax_minor === (int) $a425f_rows[0]->tax_minor );
update_option( Settings::OPTION, $a425f_saved );
Settings::flush();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_ticket_sellers WHERE ticket_type_id=%d", $a425f_type ) );
