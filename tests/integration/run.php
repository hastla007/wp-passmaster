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

echo "Security: customer input cannot use trusted adapter options\n";
$type = make_type( 5, 10, 3000 );
$evil = Orders::create( array( 'email' => 'evil@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'evil-1', 'unit_prices' => array( $type => 0 ), 'external' => true, 'hold_minutes' => 99999999, 'currency' => 'XXX' ) );
check( 'injected unit_prices/external are ignored', ! is_wp_error( $evil ) && 6000 === (int) $evil['total_minor'] && 'pending' === $evil['payment_status'] && 10 === $evil['hold_minutes'] && 'EUR' === $evil['currency'] );
check( 'no tickets issued for injected zero price', ! tickets( $evil['order_id'] ) );
$hijack = Orders::create( array( 'email' => 'evil@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => '777-1' ) );
$channel = Orders::create( array( 'email' => 'shop@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => '777-1' ), array( 'key_namespace' => 'woocommerce', 'external' => true ) );
check( 'public idempotency key cannot claim a channel key', ! is_wp_error( $channel ) && empty( $channel['reused'] ) && $channel['order_id'] !== $hijack['order_id'] );

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

echo "Review fixes\n";
$type = make_type( 2 );
$wc11 = make_wc_order( 11, $type, 2 );
$core11 = Woo::reserve( $wc11 );
Woo::sync_paid_order( 11 );
$refund = new WC_Order( 9002 );
$ri = new WC_Order_Item_Product( 9102, $wc11->items[0]->get_product(), -1, '-25.00' );
$ri->update_meta_data( '_refunded_item_id', $wc11->items[0]->get_id() );
$refund->items[] = $ri;
Woo::sync_refund( 11, 9002 );
check( 'partially refunded ticket frees its capacity', 1 === Orders::availability( $type ) );

$type = make_type( 10 );
$wc12 = make_wc_order( 12, $type, 3, '10.00' );
$core12 = Woo::reserve( $wc12 );
check( 'uneven Woo line total is stored exactly', 1000 === (int) $wpdb->get_var( "SELECT line_total_minor FROM {$p}wpcap_order_items WHERE order_id=$core12" ) && 1000 === (int) Orders::get( $core12 )->total_minor );
$before = count( $GLOBALS['wpcap_test_mails'] );
Woo::sync_paid_order( 12 );
Woo::sync_paid_order( 12 );
$new = array_slice( $GLOBALS['wpcap_test_mails'], $before );
check( 'one ticket mail and one access link per paid order', 2 === count( $new ) );

$type = make_type( 1 );
$wc13 = make_wc_order( 13, $type, 1 );
$core13 = Woo::reserve( $wc13 );
expire_holds_of( $core13 );
$wc14 = make_wc_order( 14, $type, 1 );
Woo::reserve( $wc14 );
$before = count( $GLOBALS['wpcap_test_mails'] );
Woo::sync_paid_order( 13 );
Woo::sync_paid_order( 13 );
Woo::sync_paid_order( 13 );
$admin = array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $before ), static fn( $m ) => 'admin@example.com' === $m['to'] );
check( 'needs_review alert is sent once despite repeated hooks', 1 === count( $admin ) && 'needs_review' === Orders::get( $core13 )->status );

$type = make_type( 5, 10, 1000 );
$paid = Orders::create( array( 'email' => 'paid-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'inv-1' ) );
Orders::confirm_manual_payment( $paid['order_id'] );
$first = PassCaptain\Invoices::issue_for_order( $paid['order_id'] );
$again = PassCaptain\Invoices::issue_for_order( $paid['order_id'] );
$unpaid = Orders::create( array( 'email' => 'unpaid@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'inv-2' ) );
PassCaptain\Invoices::issue_for_order( $unpaid['order_id'] );
$paid2 = Orders::create( array( 'email' => 'paid2@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'inv-3' ) );
Orders::confirm_manual_payment( $paid2['order_id'] );
$second = PassCaptain\Invoices::issue_for_order( $paid2['order_id'] );
$n1 = (int) substr( $first['invoice_number'], -6 );
$n2 = (int) substr( $second['invoice_number'], -6 );
check( 'invoice numbers have no gaps after reuse/failed attempts', ! empty( $again['reused'] ) && $n2 === $n1 + 1 );

$event_id = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$type" );
$wpdb->query( "UPDATE {$p}wpcap_orders SET status='expired',payment_status='expired' WHERE order_id=" . (int) $unpaid['order_id'] );
$before = count( $GLOBALS['wpcap_test_mails'] );
Orders::cancel_event( $event_id );
$to = array_map( static fn( $m ) => $m['to'], array_slice( $GLOBALS['wpcap_test_mails'], $before ) );
check( 'event cancellation mails only paid/open buyers', in_array( 'paid-buyer@example.com', $to, true ) && in_array( 'paid2@example.com', $to, true ) && ! in_array( 'unpaid@example.com', $to, true ) );

echo "Open points: waitlist, manual payment, rate limits\n";
$type = make_type( 1 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
$j1 = PassCaptain\Waitlists::join( $event, $occ, $type, 'wait@example.com' );
$j2 = PassCaptain\Waitlists::join( $event, $occ, $type, 'wait@example.com' );
check( 'waitlist join response does not reveal existing entries', $j1 === $j2 && ! isset( $j1['position'] ) && ! isset( $j1['waitlist_id'] ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$offer = PassCaptain\Waitlists::offer_next( $event, $type );
$mail = end( $GLOBALS['wpcap_test_mails'] );
preg_match( '/token=([a-f0-9]{64})/', $mail['body'], $m );
$accepted = PassCaptain\Waitlists::accept_offer( $offer['waitlist_id'], $m[1] ?? '' );
check( 'accepting a waitlist offer creates an order with a hold', ! is_wp_error( $accepted ) && ! empty( $accepted['order']['order_id'] ) && true === $accepted['inventory_reserved'] && 0 === Orders::availability( $type ) );
$again = PassCaptain\Waitlists::accept_offer( $offer['waitlist_id'], $m[1] ?? '' );
check( 'an offer can only be accepted once', is_wp_error( $again ) );

$type = make_type( 3, 10, 1500 );
$manual = Orders::create( array( 'email' => 'transfer@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'manual-late' ) );
expire_holds_of( $manual['order_id'] );
Orders::expire_holds();
$ok = Orders::confirm_manual_payment( $manual['order_id'] );
check( 'manual payment after hold expiry is confirmed when capacity is free', true === $ok && 1 === count( tickets( $manual['order_id'] ) ) );
check( 'confirming twice is rejected', is_wp_error( Orders::confirm_manual_payment( $manual['order_id'] ) ) );

$GLOBALS['wpcap_test_transients'] = array();
$type = make_type( 1000 );
$limited = 0;
for ( $i = 0; $i < 35; $i++ ) {
	$r = Orders::create( array( 'email' => "rot$i@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => "rot-$i" ) );
	if ( is_wp_error( $r ) && 'wpcap_rate_limited' === $r->get_error_code() ) { $limited++; }
}
check( "rotating emails hits the per-IP checkout limit ($limited of 35 blocked)", 5 === $limited );
$GLOBALS['wpcap_test_transients'] = array();

echo "Native refunds, tax, attendees, terms\n";
$type = make_type( 10, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$type" );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $type, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
$missing = Orders::create( array( 'email' => 'att@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'att-0' ) );
check( 'required attendee field is enforced', is_wp_error( $missing ) && 'wpcap_attendee_field_required' === $missing->get_error_code() );
$att = Orders::create( array( 'email' => 'att@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'att-1', 'attendees' => array(
	array( 'ticket_type_id' => $type, 'name' => 'Ada', 'email' => 'ada@example.com', 'answers' => array( 'company' => 'ACME', 'unknown' => 'dropped' ) ),
	array( 'ticket_type_id' => $type, 'name' => 'Bob', 'answers' => array( 'company' => 'Initech' ) ),
) ) );
check( 'VAT is calculated per line from gross prices', ! is_wp_error( $att ) && 380 === (int) Orders::get( $att['order_id'] )->tax_minor );
Orders::confirm_manual_payment( $att['order_id'] );
$answers = $wpdb->get_col( "SELECT a.answer FROM {$p}wpcap_participant_answers a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=" . (int) $att['order_id'] . " ORDER BY a.answer" );
check( 'attendee answers stored per ticket, unknown fields dropped', array( 'ACME', 'Initech' ) === $answers && null === Orders::get( $att['order_id'] )->attendee_data );
$all = tickets( $att['order_id'] );
$r1 = PassCaptain\Refunds::request( $att['order_id'], array( (int) $all[0]->ticket_id ), 'Kunde krank', 'r-1' );
$r1b = PassCaptain\Refunds::request( $att['order_id'], array( (int) $all[0]->ticket_id ), 'Kunde krank', 'r-1' );
check( 'partial native refund completes for manual payments, idempotent', 'completed' === $r1['status'] && 1190 === (int) $r1['amount_minor'] && $r1b['refund_id'] === $r1['refund_id'] && 1 === count( tickets( $att['order_id'], 'revoked' ) ) );
check( 'refunded ticket frees capacity', 9 === Orders::availability( $type ) );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 500 ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$r2 = PassCaptain\Refunds::request( $att['order_id'], array(), 'Rest', 'r-2' );
check( 'refund above threshold waits for approval', 'pending_approval' === $r2['status'] && 1 === count( tickets( $att['order_id'], 'valid' ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$self = PassCaptain\Refunds::approve( $r2['refund_id'] );
check( 'requester cannot approve own refund', is_wp_error( $self ) );
$GLOBALS['wpcap_test_user_id'] = 2;
$r2b = PassCaptain\Refunds::approve( $r2['refund_id'] );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'second approver completes full refund', 'completed' === $r2b['status'] && 'refunded' === Orders::get( $att['order_id'] )->status && 2380 === (int) Orders::get( $att['order_id'] )->refunded_minor );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'require_terms' => 1, 'terms_text' => 'AGB v1' ) );
$noterms = Orders::create( array( 'email' => 't@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'terms-0', 'attendees' => array( array( 'ticket_type_id' => $type, 'answers' => array( 'company' => 'X' ) ) ) ) );
$terms = Orders::create( array( 'email' => 't@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'terms-1', 'accept_terms' => true, 'attendees' => array( array( 'ticket_type_id' => $type, 'answers' => array( 'company' => 'X' ) ) ) ) );
check( 'terms acceptance required and version stored', is_wp_error( $noterms ) && ! is_wp_error( $terms ) && PassCaptain\Settings::terms_version() === Orders::get( $terms['order_id'] )->terms_version && '' !== Orders::get( $terms['order_id'] )->terms_version );
PassCaptain\Settings::update( array( 'require_terms' => 0, 'terms_text' => '' ) );

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

require __DIR__ . '/finance.php';
require __DIR__ . '/payments.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/mobile.php';
require __DIR__ . '/affiliate.php';
foreach ( glob( __DIR__ . '/modules/*.php' ) as $module_test ) { require $module_test; }
echo "\n$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
