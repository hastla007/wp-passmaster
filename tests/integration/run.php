<?php
/**
 * Integration tests for order holds, payment confirmation and the WooCommerce adapter.
 *
 * Usage: php tests/integration/run.php   (requires a disposable MySQL/MariaDB database)
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

use PassCaptain\Orders;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

$failures = 0;
$passes   = 0;
function check( $name, $condition ) {
	global $failures, $passes;
	if ( $condition ) { $passes++; echo "  ok   $name\n"; } else { $failures++; echo "  FAIL $name\n"; }
}

function make_type( $capacity, $max = 10, $price = 2500, $occurrence_capacity = 0 ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Konzert', 'slug' => 'e' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => $occurrence_capacity, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => $max, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}

function make_wc_order( $id, $type_id, $qty, $line_total = '50.00', $tax = '0' ) {
	static $item_id = 1000, $product_id = 500;
	$product = new WC_Product( ++$product_id );
	$product->update_meta_data( Woo::PRODUCT_META, $type_id );
	$GLOBALS['wpcap_test_products'][ $product_id ] = $product;
	$order = new WC_Order( $id );
	$order->items[] = new WC_Order_Item_Product( ++$item_id, $product, $qty, $line_total, $tax );
	return $order;
}

function tickets( $core_id, $status = null ) {
	return array_values( array_filter( Orders::tickets_for_order( $core_id ), static fn( $t ) => null === $status || $t->status === $status ) );
}

function expire_holds_of( $core_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $core_id ) );
}

if ( in_array( '--child', $argv, true ) ) {
	// Concurrency worker: buy one ticket of the given type and print the result.
	$type = (int) $argv[2];
	$r = Orders::create( array( 'email' => 'c' . getmypid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'child-' . getmypid() ) );
	echo is_wp_error( $r ) ? 'ERR' : 'OK';
	exit;
}

wpcap_test_install();
global $wpdb;
$p = $wpdb->prefix;

echo "Native checkout\n";
$type = make_type( 5 );
$r = Orders::create( array( 'email' => 'native@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'native-1' ) );
check( 'paid native order is created with a hold', ! is_wp_error( $r ) && 'pending' === $r['payment_status'] && 10 === $r['hold_minutes'] );
check( 'availability reflects the hold', 3 === Orders::availability( $type ) );
$again = Orders::create( array( 'email' => 'native@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'native-1' ) );
check( 'same idempotency key reuses the order', ! is_wp_error( $again ) && ! empty( $again['reused'] ) && $again['order_id'] === $r['order_id'] );
$free = make_type( 2, 10, 0 );
$fr = Orders::create( array( 'email' => 'free@example.com', 'items' => array( array( 'ticket_type_id' => $free, 'quantity' => 2 ) ), 'idempotency_key' => 'free-1' ) );
check( 'free native order issues tickets immediately', ! is_wp_error( $fr ) && 2 === count( tickets( $fr['order_id'], 'valid' ) ) );

echo "WooCommerce: hold, payment, idempotent issuance\n";
$type = make_type( 3, 4 );
$wc = make_wc_order( 1, $type, 2, '40.00', '8.00' );
$core = Woo::reserve( $wc );
check( 'hold placed at checkout', is_int( $core ) && $core > 0 && 1 === Orders::availability( $type ) );
check( 'core order stays pending until paid', 'pending' === Orders::get( $core )->payment_status && ! tickets( $core ) );
check( 'Woo price incl. tax is snapshotted', 2400 === (int) $wpdb->get_var( "SELECT unit_price_minor FROM {$p}wpcap_order_items WHERE order_id=$core" ) );
check( 'no access-link mail before payment', 0 === count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'buyer@example.com' === $m['to'] ) ) );
Woo::sync_paid_order( 1 );
Woo::sync_paid_order( 1 );
Woo::sync_paid_order( 1 );
check( 'exactly one ticket per unit after repeated paid hooks', 2 === count( tickets( $core ) ) );
check( 'order marked paid', 'paid' === Orders::get( $core )->payment_status );
check( 'sold units still counted', 1 === Orders::availability( $type ) );
$buyer_mails = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'buyer@example.com' === $m['to'] ) );
check( 'ticket mail and access link sent once each', 2 === count( $buyer_mails ) );

echo "WooCommerce: cart validation and oversell protection\n";
check( 'cart check rejects quantity above availability', '' !== Woo::quantity_error( $type, 2 ) );
check( 'cart check accepts remaining quantity', '' === Woo::quantity_error( $type, 1 ) );
check( 'cart check enforces max per order', '' !== Woo::quantity_error( make_type( 100, 4 ), 5 ) );
$wc2 = make_wc_order( 2, $type, 2 );
$threw = false;
try { Woo::reserve_classic( 2, array(), $wc2 ); } catch ( Exception $e ) { $threw = true; }
check( 'classic checkout aborts when tickets are gone', $threw && ! $wc2->get_meta( Woo::ORDER_META ) );

echo "WooCommerce: checkout retry does not double-hold\n";
$type = make_type( 2 );
$wc3 = make_wc_order( 3, $type, 2 );
$first = Woo::reserve( $wc3 );
$second = Woo::reserve( $wc3 );
check( 'retry creates a new attempt', is_int( $second ) && $second !== $first );
check( 'previous attempt cancelled, capacity held once', 'cancelled' === Orders::get( $first )->status && 0 === Orders::availability( $type ) );

echo "WooCommerce: late payment recovery\n";
$type = make_type( 2 );
$wc4 = make_wc_order( 4, $type, 1 );
$core4 = Woo::reserve( $wc4 );
expire_holds_of( $core4 );
Woo::sync_paid_order( 4 );
check( 'expired hold re-allocated when capacity is free', 1 === count( tickets( $core4 ) ) && 'paid' === Orders::get( $core4 )->payment_status );
$wc5 = make_wc_order( 5, $type, 1 );
$core5 = Woo::reserve( $wc5 );
expire_holds_of( $core5 );
$wc6 = make_wc_order( 6, $type, 1 );
$core6 = Woo::reserve( $wc6 ); // takes the last ticket while order 5 is unpaid and expired
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
Woo::sync_paid_order( 5 );
check( 'late payment without capacity is flagged, not overbooked', 'needs_review' === Orders::get( $core5 )->status && ! tickets( $core5 ) );
check( 'admin is notified about unallocated payment', count( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'admin@example.com' === $m['to'] ) ) === 1 );
check( 'order note explains missing tickets', (bool) preg_grep( '/Keine Tickets/', $wc5->notes ) );
Woo::sync_paid_order( 6 );
check( 'the in-time order still gets its ticket', 1 === count( tickets( $core6 ) ) && 0 === Orders::availability( $type ) );

echo "WooCommerce: on-hold, failed and cancelled\n";
$type = make_type( 5 );
$wc7 = make_wc_order( 7, $type, 1 );
$core7 = Woo::reserve( $wc7 );
Woo::sync_on_hold_order( 7 );
$exp = $wpdb->get_var( "SELECT expires_at FROM {$p}wpcap_reservations WHERE order_id=$core7 AND status='active'" );
check( 'on-hold extends the hold to days', strtotime( $exp . ' UTC' ) > time() + 2 * 86400 );
Woo::sync_cancelled_order( 7 );
check( 'failed/cancelled releases the hold', 'cancelled' === Orders::get( $core7 )->status && 5 === Orders::availability( $type ) );
Woo::sync_paid_order( 7 );
$core7b = absint( $wc7->get_meta( Woo::ORDER_META ) );
check( 'payment after failed attempt re-reserves and issues', $core7b !== $core7 && 1 === count( tickets( $core7b, 'valid' ) ) );

echo "WooCommerce: refunds\n";
$type = make_type( 10 );
$wc8 = make_wc_order( 8, $type, 3, '75.00' );
$core8 = Woo::reserve( $wc8 );
Woo::sync_paid_order( 8 );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE order_id=$core8 ORDER BY ticket_id ASC LIMIT 1" );
$refund = new WC_Order( 9001 );
$ri = new WC_Order_Item_Product( 9101, $wc8->items[0]->get_product(), -1, '-25.00' );
$ri->update_meta_data( '_refunded_item_id', $wc8->items[0]->get_id() );
$refund->items[] = $ri;
Woo::sync_refund( 8, 9001 );
Woo::sync_refund( 8, 9001 );
check( 'partial refund revokes exactly the refunded quantity (idempotent)', 1 === count( tickets( $core8, 'revoked' ) ) && 1 === count( tickets( $core8, 'valid' ) ) );
Woo::sync_fully_refunded_order( 8 );
check( 'full refund revokes remaining valid tickets, keeps check-in history', 2 === count( tickets( $core8, 'revoked' ) ) && 1 === count( tickets( $core8, 'checked_in' ) ) );
check( 'full refund frees capacity', 'refunded' === Orders::get( $core8 )->status && 10 === Orders::availability( $type ) );

echo "Admin-created Woo order without checkout hold\n";
$type = make_type( 5 );
$wc10 = make_wc_order( 10, $type, 2 );
Woo::sync_paid_order( 10 );
$core10 = absint( $wc10->get_meta( Woo::ORDER_META ) );
check( 'paid order without hold reserves and issues', $core10 && 2 === count( tickets( $core10 ) ) );

echo "Concurrency: parallel checkouts never oversell\n";
$type = make_type( 5 );
$procs = array();
for ( $i = 0; $i < 20; $i++ ) {
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child ' . $type, 'r' );
}
$ok = 0;
foreach ( $procs as $proc ) { $ok += 'OK' === trim( stream_get_contents( $proc ) ) ? 1 : 0; pclose( $proc ); }
$held = (int) $wpdb->get_var( "SELECT SUM(quantity) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type AND status='active'" );
check( "exactly 5 of 20 parallel buyers get a hold (got $ok, held $held)", 5 === $ok && 5 === $held );

echo "WP Affiliate Captain adapter (contract v1)\n";
$GLOBALS['wpac_test_version'] = '1.1.0';
$GLOBALS['wpac_test_sales']   = array();
$GLOBALS['wpac_test_refunds'] = array();
$GLOBALS['wpac_test_touch']   = null;
function wpac_contract_version() { return $GLOBALS['wpac_test_version']; }
function wpac_record_sale( array $payload ) { $GLOBALS['wpac_test_sales'][] = $payload; return array( 'status' => 'created' ); }
function wpac_record_refund( array $payload ) { $GLOBALS['wpac_test_refunds'][] = $payload; return array( 'status' => 'adjusted' ); }
function wpac_resolve_partner_code( $code, array $context = array() ) { return 'NORD-1' === strtoupper( $code ) ? array( 'affiliate_id' => 7, 'code_id' => 3, 'code' => 'NORD-1' ) : null; }
function wpac_current_touch() { return $GLOBALS['wpac_test_touch']; }
use PassCaptain\Integrations\AffiliateCaptain\Adapter as AffiliateAdapter;
AffiliateAdapter::register();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

check( 'adapter is available for contract 1.x', AffiliateAdapter::available() );
$GLOBALS['wpac_test_version'] = '2.0.0';
check( 'adapter refuses an incompatible major contract', ! AffiliateAdapter::available() );
$GLOBALS['wpac_test_version'] = '1.1.0';
check( 'integration is listed on the Affiliate Captain status page', isset( apply_filters( 'wpac_registered_integrations', array() )['pass-captain'] ) );
check( 'checkout shows the partner code field', true === apply_filters( 'wpcap_checkout_partner_field', false ) );
check( 'valid partner code passes the checkout check', true === apply_filters( 'wpcap_validate_partner_code', true, 'nord-1' ) );
$invalid = apply_filters( 'wpcap_validate_partner_code', true, 'NOPE' );
check( 'unknown partner code is rejected with 400', is_wp_error( $invalid ) && 'wpcap_partner_code_invalid' === $invalid->get_error_code() );
for ( $i = 0; $i < 12; $i++ ) { $last = apply_filters( 'wpcap_validate_partner_code', true, 'NOPE' . $i ); }
check( 'partner code guessing is rate limited', is_wp_error( $last ) && 'wpcap_rate_limited' === $last->get_error_code() );

function aff_order( $type, $qty, $source, $extra = array() ) {
	return Orders::create( array_merge( array( 'email' => 'aff' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'aff-' . uniqid(), 'source' => $source ), $extra ) );
}

$type = make_type( 50, 10, 2500 );
$GLOBALS['wpac_test_touch'] = array( 'code' => 'NORD-1', 'method' => 'cookie', 'campaign' => 'sommer', 'content_id' => 42 );
$o = aff_order( $type, 2, 'native', array( 'partner_code' => 'nord-1' ) );
$snap = json_decode( $wpdb->get_var( "SELECT price_snapshot FROM {$p}wpcap_orders WHERE order_id=" . (int) $o['order_id'] ), true );
check( 'referral (code + touch) is stored with the native order', 'NORD-1' === ( $snap['affiliate_referral']['code'] ?? '' ) && 'cookie' === ( $snap['affiliate_referral']['touch']['method'] ?? '' ) && 'native' === $snap['source'] );
check( 'no sale is reported before payment', 0 === count( $GLOBALS['wpac_test_sales'] ) );
$GLOBALS['wpac_test_touch'] = null; // Payment is confirmed later by staff: their request has no referral.
Orders::confirm_manual_payment( $o['order_id'] );
$sale = $GLOBALS['wpac_test_sales'][0] ?? array();
$occ_start = strtotime( $wpdb->get_var( "SELECT o.starts_at FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types t ON t.occurrence_id=o.occurrence_id WHERE t.ticket_type_id=$type" ) . ' UTC' );
check( 'paid native order is reported once as pass-captain sale', 1 === count( $GLOBALS['wpac_test_sales'] ) && 'pass-captain' === $sale['source'] && (string) $o['order_id'] === $sale['order_id'] );
check( 'sale carries the stored code and touch', 'NORD-1' === $sale['partner_code'] && 'code' === $sale['partner_code_method'] && 'sommer' === $sale['touch']['campaign'] && 42 === $sale['touch']['content_id'] );
check( 'sale line: net 5000, quantity 2, SKU for rules, no internal keys', 5000 === $sale['items'][0]['net_minor'] && 2 === $sale['items'][0]['quantity'] && 0 === strpos( $sale['items'][0]['sku'], 'PASS-E' ) && ! isset( $sale['items'][0]['_event_id'] ) );
check( 'commission is held until the event date', gmdate( 'Y-m-d H:i:s', $occ_start ) === $sale['hold_until'] );
check( 'customer e-mail is passed for the self-referral check only', is_email( $sale['customer']['email'] ) );

Orders::confirm_external_payment( $o['order_id'] );
check( 'repeated payment confirmation does not report again', 1 === count( $GLOBALS['wpac_test_sales'] ) );

$now = gmdate( 'Y-m-d' );
$wpdb->insert( "{$p}wpcap_tax_profiles", array( 'country_code' => 'DE', 'tax_class' => 'standard', 'rate_basis_points' => 1900, 'version' => 't', 'effective_from' => '2020-01-01', 'effective_until' => null, 'status' => 'active', 'created_at' => $now ) );
$o2 = aff_order( $type, 2, 'native' );
Orders::confirm_manual_payment( $o2['order_id'] );
$sale2 = end( $GLOBALS['wpac_test_sales'] );
check( 'VAT of the active tax profile is removed (5000 gross → 4202 net)', 4202 === $sale2['items'][0]['net_minor'] && ! isset( $sale2['partner_code'] ) && ! isset( $sale2['touch'] ) );

$GLOBALS['wpac_test_touch'] = array( 'code' => 'STAFF', 'method' => 'cookie', 'campaign' => '', 'content_id' => 0 );
$pos = aff_order( $type, 1, 'pos' );
Orders::confirm_manual_payment( $pos['order_id'] );
$sale3 = end( $GLOBALS['wpac_test_sales'] );
check( 'POS sale is reported without the staff member\'s referral', (string) $pos['order_id'] === $sale3['order_id'] && ! isset( $sale3['touch'] ) );
$GLOBALS['wpac_test_touch'] = null;

$before = count( $GLOBALS['wpac_test_sales'] );
$woo = Orders::create( array( 'email' => 'woo@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'woo-aff-1', 'external' => true, 'source' => 'woocommerce', 'currency' => 'EUR' ) );
Orders::confirm_external_payment( $woo['order_id'], 'woocommerce:1' );
check( 'WooCommerce-backed orders are not reported (Affiliate Captain records the Woo order)', $before === count( $GLOBALS['wpac_test_sales'] ) );

$free_type = make_type( 10, 10, 0 );
$free = aff_order( $free_type, 1, 'native' );
check( 'free orders are not reported', 'complete' === $free['status'] && $before === count( $GLOBALS['wpac_test_sales'] ) );

$pending = aff_order( $type, 1, 'native' );
Orders::cancel_order( $pending['order_id'], 'test' );
check( 'cancelling an unpaid order reverses nothing', 0 === count( $GLOBALS['wpac_test_refunds'] ) );
Orders::cancel_order( $o2['order_id'], 'customer' );
$ref = $GLOBALS['wpac_test_refunds'][0] ?? array();
check( 'cancelling a paid order reverses the full commission', 'cancellation' === $ref['type'] && true === $ref['full'] && (string) $o2['order_id'] === $ref['order_id'] );
Orders::mark_refunded( $pos['order_id'], 'refund' );
$ref = end( $GLOBALS['wpac_test_refunds'] );
check( 'full refund reverses the commission', 'refund' === $ref['type'] && 'refund-' . $pos['order_id'] === $ref['refund_id'] );
Orders::mark_refunded( $pos['order_id'], 'refund' );
check( 'repeated refund is not reported twice', 2 === count( $GLOBALS['wpac_test_refunds'] ) );
$event_id = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types t ON t.occurrence_id=o.occurrence_id WHERE t.ticket_type_id=$type" );
Orders::cancel_event( $event_id );
$ref = end( $GLOBALS['wpac_test_refunds'] );
check( 'event cancellation reverses the event lines of paid orders', 'event-cancelled-' . $event_id === $ref['refund_id'] && (string) $o['order_id'] === $ref['order_id'] && 5000 === $ref['items'][0]['net_minor'] );
check( 'event cancellation skips refunded and cancelled orders', 3 === count( $GLOBALS['wpac_test_refunds'] ) );

echo "\n$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
