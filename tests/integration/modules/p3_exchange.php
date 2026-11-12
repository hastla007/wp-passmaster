<?php
/**
 * Integration tests for ticket exchange (module "Umtausch"). Included from run.php. Also runnable
 * as a concurrency worker:
 *   php p3_exchange.php --exchange-child <ticket_id> <target_type_id>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Exchange\Policy;
use PassCaptain\Exchange\Portal;
use PassCaptain\Exchange\Service as Exchange;
use PassCaptain\Modules\Exchange as ExchangeModule;
use PassCaptain\Modules\Seating;
use PassCaptain\Orders;
use PassCaptain\Seating\AdminPage as SeatingAdmin;
use PassCaptain\Seating\Layouts;

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--exchange-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	ExchangeModule::register();
	usleep( 20000 );
	$r = Exchange::request( (int) $argv[2], (int) $argv[3], array( 'channel' => 'admin', 'idempotency_key' => 'child-' . getmypid() ) );
	echo is_wp_error( $r ) ? 'ERR:' . ( $r->get_error_data()['reason'] ?? $r->get_error_code() ) : 'OK:' . $r['status'];
	exit;
}

if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/wp-pass-captain/' ); }

ExchangeModule::register();
Seating::register();
global $wpdb;
$p = $wpdb->prefix;

function ex_event( $name = 'Festival' ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => $name, 'slug' => 'ex' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	return (int) $wpdb->insert_id;
}
function ex_occ( $event, $capacity = 0, $hours = 168 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $hours * 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => $capacity, 'status' => 'scheduled' ) );
	return (int) $wpdb->insert_id;
}
function ex_type( $occ, $name, $price, $capacity = 0, $rate = 1000 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'tax_rate_bp' => $rate, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
/** Buys and pays $qty tickets; returns [order_id, ticket ids]. */
function ex_buy( $type, $qty = 1, $email = 'ex@example.com' ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'ex-' . uniqid( '', true ) ) );
	if ( is_wp_error( $r ) ) { return array( 0, array() ); }
	if ( 'paid' !== $r['payment_status'] ) { Orders::confirm_external_payment( $r['order_id'], 'test' ); }
	return array( (int) $r['order_id'], array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $r['order_id'], 'valid' ) ) );
}
function ex_ticket( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $id ) );
}
function ex_item( $order_id, $type_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT SUM(quantity) AS quantity,SUM(refunded_quantity) AS refunded,SUM(line_total_minor) AS line_total FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d", $order_id, $type_id ) );
}
function ex_mails( $type_word ) {
	return array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( (string) $m['subject'], $type_word ) ) );
}
function ex_policy( array $values ) {
	Policy::save_global( array_merge( Policy::defaults(), $values ) );
}

$GLOBALS['ex_events'] = array();
add_action( 'wpcap_ticket_exchanged', static function ( $old, $new, $id ) { $GLOBALS['ex_events'][] = array( 'exchanged', (int) $old, (int) $new, (int) $id ); }, 10, 3 );
add_action( 'wpcap_ticket_revoked', static function ( $id, $reason = '' ) { $GLOBALS['ex_events'][] = array( 'revoked', (int) $id, (string) $reason ); }, 99, 2 );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0 ) );
ex_policy( array() );

echo "Exchange: same price into another occurrence\n";
$event = ex_event();
$occ_a = ex_occ( $event );
$occ_b = ex_occ( $event, 0, 192 );
$std_a = ex_type( $occ_a, 'Standard', 2500, 5 );
$std_b = ex_type( $occ_b, 'Standard', 2500, 5 );
list( $order, $ids ) = ex_buy( $std_a, 2 );
$old = $ids[0];
$GLOBALS['wpcap_test_mails'] = array();
$quote = Exchange::quote( $old, $std_b );
check( 'quote: same price, nothing due', ! is_wp_error( $quote ) && 0 === $quote['difference_minor'] && 0 === $quote['due_minor'] && 0 === $quote['refund_minor'] );
$opts = Exchange::options( $old );
check( 'options list the other occurrence but not the own type', in_array( $std_b, array_column( $opts, 'ticket_type_id' ), true ) && ! in_array( $std_a, array_column( $opts, 'ticket_type_id' ), true ) );
$x = Exchange::request( $old, $std_b, array( 'channel' => 'admin', 'idempotency_key' => 'same-1' ) );
check( 'exchange completes at once', ! is_wp_error( $x ) && 'completed' === $x['status'] && (int) $x['new_ticket_id'] > 0 );
$new = (int) $x['new_ticket_id'];
check( 'old ticket revoked, new ticket valid in the same order', 'revoked' === ex_ticket( $old )->status && 'valid' === ex_ticket( $new )->status && $order === (int) ex_ticket( $new )->order_id && $std_b === (int) ex_ticket( $new )->ticket_type_id );
check( 'new code differs from the old one', ex_ticket( $new )->token_hash !== ex_ticket( $old )->token_hash );
check( 'order lines moved one unit (value carried)', 1 === (int) ex_item( $order, $std_a )->quantity && 1 === (int) ex_item( $order, $std_b )->quantity && 2500 === (int) ex_item( $order, $std_b )->line_total && 5000 === (int) ex_item( $order, $std_a )->line_total + (int) ex_item( $order, $std_b )->line_total );
check( 'capacity released on the old type and used on the target', 4 === Orders::availability( $std_a ) && 4 === Orders::availability( $std_b ) );
check( 'wpcap_ticket_revoked fired with reason exchange', in_array( array( 'revoked', $old, 'exchange' ), $GLOBALS['ex_events'], true ) );
check( 'wpcap_ticket_exchanged fired with old/new ids', in_array( array( 'exchanged', $old, $new, (int) $x['exchange_id'] ), $GLOBALS['ex_events'], true ) );
$mail = ex_mails( 'umgetauscht' );
check( 'ticket_exchanged mail with new link sent to buyer', 1 === count( $mail ) && 'ex@example.com' === $mail[0]['to'] && false !== strpos( $mail[0]['body'], 'wpcap_ticket=' ) && false !== strpos( $mail[0]['body'], 'Bisher:' ) );
check( 'audit trail written', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='ticket_exchanged' AND object_id=%d", $old ) ) );
Orders::issue_and_email( $order );
check( 're-running core issuance creates no extra ticket', 3 === count( tickets( $order ) ) );

echo "Exchange: idempotency (double submit)\n";
$again = Exchange::request( $old, $std_b, array( 'channel' => 'admin', 'idempotency_key' => 'same-1' ) );
check( 'same key returns the same exchange', ! is_wp_error( $again ) && (int) $again['exchange_id'] === (int) $x['exchange_id'] && ! empty( $again['reused'] ) );
$again2 = Exchange::request( $old, $std_b, array( 'channel' => 'admin' ) );
check( 'resubmit without key after completion reports the exchange, no second ticket', ! is_wp_error( $again2 ) && (int) $again2['exchange_id'] === (int) $x['exchange_id'] && 3 === count( tickets( $order ) ) );
$other = Exchange::request( $ids[1], $std_b, array( 'channel' => 'admin', 'idempotency_key' => 'same-1' ) );
check( 'key is scoped per ticket', ! is_wp_error( $other ) && (int) $other['exchange_id'] !== (int) $x['exchange_id'] && 'completed' === $other['status'] );
$rerun = Exchange::request( $new, $std_a, array( 'channel' => 'admin' ) );
check( 'a new ticket can be exchanged again (chain)', ! is_wp_error( $rerun ) && 'completed' === $rerun['status'] && (int) $rerun['root_ticket_id'] === $old );

echo "Exchange: upgrade with supplementary payment\n";
$vip_b = ex_type( $occ_b, 'VIP', 4000, 1 );
list( $order2, $ids2 ) = ex_buy( $std_a, 1, 'up@example.com' );
$GLOBALS['wpcap_test_mails'] = array();
$up = Exchange::request( $ids2[0], $vip_b, array( 'channel' => 'admin', 'idempotency_key' => 'up-1' ) );
$supp = ! is_wp_error( $up ) ? (int) $up['supplementary_order_id'] : 0;
check( 'upgrade waits for payment of the difference', ! is_wp_error( $up ) && 'pending_payment' === $up['status'] && 1500 === (int) $up['due_minor'] && $supp > 0 );
check( 'supplementary order: pending, difference only, linked', 'pending' === Orders::get( $supp )->payment_status && 1500 === (int) Orders::get( $supp )->total_minor && $order2 === (int) Exchange::for_supplement( $supp )['order_id'] && false !== strpos( Orders::get( $supp )->price_snapshot, Orders::get( $order2 )->public_id ) );
check( 'old ticket stays valid, no new ticket yet', 'valid' === ex_ticket( $ids2[0] )->status && 1 === count( tickets( $order2 ) ) && ! tickets( $supp ) );
check( 'target capacity is held meanwhile', 0 === Orders::availability( $vip_b ) );
check( 'buyer gets the access link of the supplementary order', (bool) array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'up@example.com' === $m['to'] && false !== strpos( $m['body'], Orders::get( $supp )->public_id ) ) );
$dup = Exchange::request( $ids2[0], $vip_b, array( 'channel' => 'admin' ) );
check( 'double submit returns the pending exchange', ! is_wp_error( $dup ) && (int) $dup['exchange_id'] === (int) $up['exchange_id'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_ticket_exchanges WHERE old_ticket_id=%d", $ids2[0] ) ) );
$conflict = Exchange::request( $ids2[0], $std_b, array( 'channel' => 'admin' ) );
check( 'a different exchange is refused while one is pending', is_wp_error( $conflict ) && 'exchange_pending' === $conflict->get_error_data()['reason'] );
Orders::issue_and_email( $supp );
check( 'core issuance ignores the unpaid supplement', ! tickets( $supp ) );
$GLOBALS['wpcap_test_mails'] = array();
$paid = Orders::confirm_external_payment( $supp, 'test-payment' );
$done = Exchange::get( (int) $up['exchange_id'] );
check( 'payment completes the exchange', true === $paid && 'completed' === $done['status'] && (int) $done['new_ticket_id'] > 0 );
check( 'ticket issued in the original order only', 'revoked' === ex_ticket( $ids2[0] )->status && $order2 === (int) ex_ticket( (int) $done['new_ticket_id'] )->order_id && ! tickets( $supp ) );
check( 'capacity counted once (unit moved to the original order)', 0 === Orders::availability( $vip_b ) && 1 === (int) ex_item( $supp, $vip_b )->refunded && 1 === (int) ex_item( $order2, $vip_b )->quantity );
check( 'exchange mail mentions the paid difference', 1 === count( ex_mails( 'umgetauscht' ) ) && false !== strpos( ex_mails( 'umgetauscht' )[0]['body'], 'Aufpreis bezahlt: 15,00 EUR' ) );
$inv = PassCaptain\Invoices::issue_for_order( $supp );
$lines = ! is_wp_error( $inv ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", $inv['invoice_id'] ) ) : array();
check( 'normal invoice for the supplementary order', 1 === count( $lines ) && 1500 === (int) $lines[0]->total_minor && false !== strpos( $lines[0]->description, 'Aufpreis Umtausch Standard' ) );
$GLOBALS['wpcap_test_mails'] = array();
Orders::confirm_external_payment( $supp, 'test-payment' );
check( 'repeated payment callback changes nothing', 0 === count( ex_mails( 'umgetauscht' ) ) && 2 === count( tickets( $order2 ) ) );

$wpdb->update( "{$p}wpcap_ticket_types", array( 'capacity' => 0 ), array( 'ticket_type_id' => $std_a ) ); // Source type of the following scenarios.
echo "Exchange: hold expiry releases the target\n";
$vip_c = ex_type( $occ_b, 'Loge', 6000, 1 );
list( $order3, $ids3 ) = ex_buy( $std_a, 1, 'late@example.com' );
$pend = Exchange::request( $ids3[0], $vip_c, array( 'channel' => 'admin' ) );
check( 'pending upgrade holds the last unit', ! is_wp_error( $pend ) && 'pending_payment' === $pend['status'] && 0 === Orders::availability( $vip_c ) );
expire_holds_of( (int) $pend['supplementary_order_id'] );
Orders::expire_holds();
check( 'expired hold frees the target and ends the exchange', 1 === Orders::availability( $vip_c ) && 'expired' === Exchange::get( (int) $pend['exchange_id'] )['status'] && 'expired' === Orders::get( (int) $pend['supplementary_order_id'] )->status );
check( 'old ticket still valid after expiry', 'valid' === ex_ticket( $ids3[0] )->status && 1 === count( tickets( $order3 ) ) );
list( $order3b, $ids3b ) = ex_buy( $std_a, 1, 'late2@example.com' );
$latex = Exchange::request( $ids3b[0], $vip_c, array( 'channel' => 'admin' ) );
expire_holds_of( (int) $latex['supplementary_order_id'] );
Orders::expire_holds();
Orders::confirm_external_payment( (int) $latex['supplementary_order_id'], 'late-pay' );
check( 'late payment after expiry still completes when the unit is free', 'completed' === Exchange::get( (int) $latex['exchange_id'] )['status'] && 0 === Orders::availability( $vip_c ) );
$back3 = Exchange::request( (int) Exchange::get( (int) $latex['exchange_id'] )['new_ticket_id'], $std_a, array( 'channel' => 'admin', 'refund_policy' => 'none' ) );
check( 'exchanging back frees the unit again', ! is_wp_error( $back3 ) && 1 === Orders::availability( $vip_c ) );
$retry = Exchange::request( $ids3[0], $vip_c, array( 'channel' => 'admin', 'waive' => true ) );
check( 'goodwill (Kulanz) exchanges without supplement', ! is_wp_error( $retry ) && 'completed' === $retry['status'] && 1 === (int) $retry['waived'] && 0 === (int) $retry['due_minor'] && 0 === Orders::availability( $vip_c ) );

echo "Exchange: downgrade refund\n";
$cheap_b = ex_type( $occ_b, 'Ermäßigt', 1500, 10 );
list( $order4, $ids4 ) = ex_buy( $std_a, 2, 'down@example.com' );
$inv4 = PassCaptain\Invoices::issue_for_order( $order4 );
$GLOBALS['wpcap_test_mails'] = array();
$down = Exchange::request( $ids4[0], $cheap_b, array( 'channel' => 'admin' ) );
$refund = ! is_wp_error( $down ) ? PassCaptain\Refunds::get( (int) $down['refund_id'] ) : null;
check( 'cheaper target refunds the difference', ! is_wp_error( $down ) && 'completed' === $down['status'] && 1000 === (int) $down['refund_minor'] && $refund && 'completed' === $refund['status'] && 1000 === (int) $refund['amount_minor'] );
check( 'partial refund keeps tickets, order refunded_minor updated', 1000 === (int) Orders::get( $order4 )->refunded_minor && 'complete' === Orders::get( $order4 )->status && 2 === count( tickets( $order4, 'valid' ) ) );
$cn4 = PassCaptain\Invoices::credit_note_for_refund( (int) $down['refund_id'] ); // Hooked to wpcap_refund_completed in production.
check( 'Rechnungskorrektur for the refunded difference', ! is_wp_error( $inv4 ) && is_array( $cn4 ) && 1000 === (int) $cn4['total_minor'] && 91 === (int) $cn4['tax_minor'] );
check( 'value of the new ticket is the cheaper price', 1500 === (int) $down['new_value_minor'] && 1500 === (int) ex_item( $order4, $cheap_b )->line_total );
$full = PassCaptain\Refunds::request( $order4, array(), 'Rest', 'down-rest' );
check( 'later full refund pays out only the rest', ! is_wp_error( $full ) && 4000 === (int) $full['amount_minor'] && 5000 === (int) Orders::get( $order4 )->refunded_minor );
ex_policy( array( 'refund_policy' => 'none' ) );
list( $order5, $ids5 ) = ex_buy( $std_a, 1, 'none@example.com' );
$keep = Exchange::request( $ids5[0], $cheap_b, array( 'channel' => 'admin' ) );
check( 'policy "none": no refund, ticket keeps its value', ! is_wp_error( $keep ) && 'completed' === $keep['status'] && 0 === (int) $keep['refund_minor'] && 0 === (int) Orders::get( $order5 )->refunded_minor && 2500 === (int) $keep['new_value_minor'] );
ex_policy( array() );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 500 ) );
list( $order6, $ids6 ) = ex_buy( $std_a, 1, 'appr@example.com' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$appr = Exchange::request( $ids6[0], $cheap_b, array( 'channel' => 'admin' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'refund above the approval threshold waits for approval', ! is_wp_error( $appr ) && 'completed' === $appr['status'] && 'pending_approval' === $appr['refund_status'] && 0 === (int) Orders::get( $order6 )->refunded_minor );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

echo "Exchange: fee\n";
ex_policy( array( 'fee_minor' => 300 ) );
list( $order7, $ids7 ) = ex_buy( $std_a, 1, 'fee@example.com' );
$fee = Exchange::request( $ids7[0], $std_b, array( 'channel' => 'admin' ) );
$fsupp = ! is_wp_error( $fee ) ? (int) $fee['supplementary_order_id'] : 0;
check( 'same price with fee needs a supplementary payment of the fee', ! is_wp_error( $fee ) && 'pending_payment' === $fee['status'] && 300 === (int) $fee['due_minor'] && 300 === (int) Orders::get( $fsupp )->total_minor );
check( 'fee is an own line without inventory', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_order_items WHERE order_id=%d", $fsupp ) ) && 300 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT line_total_minor FROM {$p}wpcap_order_items WHERE order_id=%d AND quantity=0", $fsupp ) ) );
Orders::confirm_external_payment( $fsupp, 'fee-paid' );
$finv = PassCaptain\Invoices::issue_for_order( $fsupp );
$flines = ! is_wp_error( $finv ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d ORDER BY invoice_line_id", $finv['invoice_id'] ) ) : array();
check( 'invoice shows the fee line "Umtauschgebühr"', 2 === count( $flines ) && false !== strpos( $flines[1]->description, 'Umtauschgebühr' ) && 1 === (int) $flines[1]->quantity && 300 === (int) $flines[1]->total_minor && 300 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT total_minor FROM {$p}wpcap_invoices WHERE invoice_id=%d", $finv['invoice_id'] ) ) );
check( 'fee exchange completes after payment', 'completed' === Exchange::get( (int) $fee['exchange_id'] )['status'] );
list( $order8, $ids8 ) = ex_buy( $std_a, 1, 'fee2@example.com' );
$fee_up = Exchange::quote( $ids8[0], $vip_b );
$fee_down = Exchange::quote( $ids8[0], $cheap_b );
check( 'upgrade: difference plus fee; downgrade: fee withheld from refund', 1800 === (int) $fee_up['due_minor'] && 0 === (int) $fee_down['due_minor'] && 700 === (int) $fee_down['refund_minor'] );
$fw = Exchange::request( $ids8[0], $std_b, array( 'channel' => 'admin', 'waive' => true ) );
check( 'goodwill waives the fee', ! is_wp_error( $fw ) && 'completed' === $fw['status'] && 0 === (int) $fw['fee_minor'] );
$wpdb->insert( "{$p}wpcap_exchange_policies", array( 'event_id' => $event, 'fee_minor' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
check( 'event policy overrides the global fee', 0 === Policy::for_event( $event )['fee_minor'] && 300 === Policy::global_policy()['fee_minor'] );
$wpdb->query( "DELETE FROM {$p}wpcap_exchange_policies" );
ex_policy( array() );

echo "Exchange: capacity\n";
$full_type = ex_type( $occ_b, 'Ausverkauft', 2500, 1 );
ex_buy( $full_type, 1, 'first@example.com' );
list( $order9, $ids9 ) = ex_buy( $std_a, 1, 'refused@example.com' );
$sold = Exchange::request( $ids9[0], $full_type, array( 'channel' => 'admin' ) );
check( 'sold-out target is refused', is_wp_error( $sold ) && 'sold_out' === $sold->get_error_data()['reason'] );
check( 'refused exchange changes nothing', 'valid' === ex_ticket( $ids9[0] )->status && 1 === (int) ex_item( $order9, $std_a )->quantity && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_ticket_exchanges WHERE old_ticket_id=%d", $ids9[0] ) ) );
$tight_event = ex_event( 'Kleiner Saal' );
$tight = ex_occ( $tight_event, 2 );
$tight_a = ex_type( $tight, 'Sitz', 2000 );
$tight_b = ex_type( $tight, 'Sitz Mitte', 2000 );
list( $order10, $ids10 ) = ex_buy( $tight_a, 2, 'tight@example.com' );
$other_occ = ex_occ( $tight_event, 0 );
$other_type = ex_type( $other_occ, 'Sitz', 2000, 0 );
check( 'occurrence is sold out', 0 === Orders::availability( $tight_b ) );
$inside = Exchange::request( $ids10[0], $tight_b, array( 'channel' => 'admin' ) );
check( 'exchange inside a sold-out occurrence works (own unit is released first)', ! is_wp_error( $inside ) && 'completed' === $inside['status'] && 0 === Orders::availability( $tight_a ) );
$GLOBALS['wpcap_test_transients'] = array();
$blocked = Orders::create( array( 'email' => 'x@example.com', 'items' => array( array( 'ticket_type_id' => $tight_b, 'quantity' => 1 ) ), 'idempotency_key' => 'tight-x' ) );
check( 'occurrence capacity still holds after the exchange', is_wp_error( $blocked ) );
$tight_vip = ex_type( $tight, 'Sitz Premium', 3000 );
$tight_up = Exchange::request( $ids10[1], $tight_vip, array( 'channel' => 'admin' ) );
check( 'upgrade inside a sold-out occurrence is held', ! is_wp_error( $tight_up ) && 'pending_payment' === $tight_up['status'] );
Orders::confirm_external_payment( (int) $tight_up['supplementary_order_id'], 'tight-pay' );
check( 'and completes after payment without overbooking', 'completed' === Exchange::get( (int) $tight_up['exchange_id'] )['status'] && 0 === Orders::availability( $tight_a ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", $tight ) ) );

echo "Exchange: state checks\n";
list( $order11, $ids11 ) = ex_buy( $std_a, 3, 'state@example.com' );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $ids11[0] ) );
$ci = Exchange::request( $ids11[0], $std_b, array( 'channel' => 'admin' ) );
check( 'checked-in ticket is refused', is_wp_error( $ci ) && 'wpcap_exchange_ticket_state' === $ci->get_error_code() );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $ids11[1], 'event_id' => $event, 'user_id' => 1, 'result' => 'checked_in', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$ci2 = Exchange::request( $ids11[1], $std_b, array( 'channel' => 'admin' ) );
check( 'ticket with an admission scan (multi-entry) is refused', is_wp_error( $ci2 ) && 'wpcap_exchange_ticket_state' === $ci2->get_error_code() );
$rf = PassCaptain\Refunds::request( $order11, array( $ids11[2] ), 'x', 'state-rf' );
$rfx = Exchange::request( $ids11[2], $std_b, array( 'channel' => 'admin' ) );
check( 'refunded ticket is refused', ! is_wp_error( $rf ) && is_wp_error( $rfx ) );
$foreign = ex_type( ex_occ( ex_event( 'Anderes Event' ) ), 'Standard', 2500 );
list( , $ids12 ) = ex_buy( $std_a, 1, 'foreign@example.com' );
$fx = Exchange::request( $ids12[0], $foreign, array( 'channel' => 'admin' ) );
check( 'other event (no shared series) is refused', is_wp_error( $fx ) && 'wpcap_exchange_other_event' === $fx->get_error_code() );
ex_policy( array( 'enabled' => 0 ) );
$off = Exchange::request( $ids12[0], $std_b, array( 'channel' => 'admin' ) );
check( 'disabled policy refuses', is_wp_error( $off ) && 'wpcap_exchange_disabled' === $off->get_error_code() );
ex_policy( array() );
$pend2 = Exchange::request( $ids12[0], ex_type( $occ_b, 'Galerie', 5000, 3 ), array( 'channel' => 'admin' ) );
do_action( 'wpcap_ticket_checked_in', $ids12[0], array() );
check( 'check-in of the old ticket cancels a pending upgrade', ! is_wp_error( $pend2 ) && 'cancelled' === Exchange::get( (int) $pend2['exchange_id'] )['status'] && 'cancelled' === Orders::get( (int) $pend2['supplementary_order_id'] )->status );

echo "Exchange: seated target\n";
$seat_event = ex_event( 'Theater' );
$seat_occ = ex_occ( $seat_event );
$stand = ex_type( $seat_occ, 'Stehplatz', 2000 );
$parkett = ex_type( $seat_occ, 'Parkett', 2000 );
$layout = SeatingAdmin::create( array( 'event_id' => $seat_event, 'name' => 'Saal' ) );
$draft = array( 'width' => 800, 'height' => 500, 'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett', 'color' => '#2271b1' ) ), 'categories' => array(), 'groups' => array( array( 'key' => 'ga', 'type' => 'row', 'label' => '' ) ), 'seats' => array() );
foreach ( array( 1, 2, 3 ) as $n ) { $draft['seats'][] = array( 'key' => 'p' . $n, 'group' => 'ga', 'row' => 'Reihe A', 'label' => (string) $n, 'x' => 40 + 30 * $n, 'y' => 60, 'zone' => 'parkett' ); }
$pub = SeatingAdmin::save( array( 'layout_id' => $layout['layout_id'], 'draft' => json_encode( $draft ), 'do' => 'publish', 'upgrade_links' => '1' ) );
$link = SeatingAdmin::link( array( 'occurrence_id' => $seat_occ, 'version_id' => $pub['version_id'], 'zones' => array( $parkett => array( 'parkett' ) ) ) );
check( 'seat map linked to the target type', ! is_wp_error( $pub ) && ! is_wp_error( $link ) && Exchange::is_seated( $seat_occ, $parkett ) );
list( $order13, $ids13 ) = ex_buy( $stand, 3, 'seat@example.com' );
$seat_ids = array_keys( Exchange::free_seats( $seat_occ, $parkett ) );
$auto = Exchange::request( $ids13[0], $parkett, array( 'channel' => 'admin' ) );
check( 'seated target gets the best free seat', ! is_wp_error( $auto ) && 'completed' === $auto['status'] && 'Reihe A, Platz 1' === PassCaptain\Seating\Reservations::seat_label( '', (int) $auto['new_ticket_id'] ) );
check( 'exchange mail names the seat', false !== strpos( ex_mails( 'umgetauscht' ) ? end( $GLOBALS['wpcap_test_mails'] )['body'] : '', 'Sitzplatz: Reihe A, Platz 1' ) );
$pick = Exchange::request( $ids13[1], $parkett, array( 'channel' => 'admin', 'seat_id' => $seat_ids[2] ) );
check( 'admin can pick a seat', ! is_wp_error( $pick ) && 'Reihe A, Platz 3' === PassCaptain\Seating\Reservations::seat_label( '', (int) $pick['new_ticket_id'] ) );
$taken = Exchange::request( $ids13[2], $parkett, array( 'channel' => 'admin', 'seat_id' => $seat_ids[2] ) );
check( 'a taken seat is refused', is_wp_error( $taken ) && 'valid' === ex_ticket( $ids13[2] )->status );
check( 'free seats reflect the exchanges', array( $seat_ids[1] ) === array_keys( Exchange::free_seats( $seat_occ, $parkett ) ) );
$back = Exchange::request( (int) $auto['new_ticket_id'], $stand, array( 'channel' => 'admin' ) );
check( 'exchanging away from a seat frees it', ! is_wp_error( $back ) && in_array( $seat_ids[0], array_keys( Exchange::free_seats( $seat_occ, $parkett ) ), true ) );
$cheap_seat = ex_type( $seat_occ, 'Hörplatz', 1000 );
list( $order13b, $ids13b ) = ex_buy( $cheap_seat, 1, 'seatup@example.com' );
$sup = Exchange::request( $ids13b[0], $parkett, array( 'channel' => 'admin' ) );
check( 'seated upgrade holds a seat for the supplementary order', ! is_wp_error( $sup ) && 'pending_payment' === $sup['status'] && ! in_array( $seat_ids[0], array_keys( Exchange::free_seats( $seat_occ, $parkett ) ), true ) );
Orders::confirm_external_payment( (int) $sup['supplementary_order_id'], 'seat-pay' );
$sup_done = Exchange::get( (int) $sup['exchange_id'] );
check( 'after payment the held seat belongs to the new ticket', 'completed' === $sup_done['status'] && 'Reihe A, Platz 1' === PassCaptain\Seating\Reservations::seat_label( '', (int) $sup_done['new_ticket_id'] ) && $order13b === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . Layouts::table( 'ticket_seats' ) . " WHERE ticket_id=%d AND status='assigned'", (int) $sup_done['new_ticket_id'] ) ) );
$wrong = Exchange::request( $ids13[2], $stand, array( 'channel' => 'admin', 'seat_id' => $seat_ids[1] ) );
check( 'same type is refused', is_wp_error( $wrong ) );

// The seating test (runs later) counts all seat claims of the database: leave none behind.
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Layouts::table( 'claims' ) . ' WHERE occurrence_id=%d', $seat_occ ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Layouts::table( 'ticket_seats' ) . ' WHERE occurrence_id=%d', $seat_occ ) );

echo "Exchange: order portal self-service\n";
$portal_event = ex_event( 'Portal' );
$po_a = ex_occ( $portal_event );
$po_b = ex_occ( $portal_event, 0, 240 );
$po_soon = ex_occ( $portal_event, 0, 12 );
$pt_a = ex_type( $po_a, 'Standard', 2500 );
$pt_b = ex_type( $po_b, 'Standard', 2500 );
$pt_soon = ex_type( $po_soon, 'Standard', 2500 );
list( $order14, $ids14 ) = ex_buy( $pt_a, 1, 'portal@example.com' );
$token = str_repeat( 'ab', 32 );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $order14 ) );
$o14 = Orders::get( $order14 );
$t14 = ex_ticket( $ids14[0] );
$px = Portal::exchange( $o14->public_id, $token, array( 'ticket_public_id' => $t14->public_id, 'ticket_type_id' => $pt_b, 'idempotency_key' => 'p-1' ) );
check( 'portal exchange is off by default', is_wp_error( $px ) && 403 === $px->get_error_data()['status'] );
ex_policy( array( 'portal_enabled' => 1, 'portal_hours' => 24, 'portal_max_per_ticket' => 1 ) );
$bad = Portal::exchange( $o14->public_id, str_repeat( 'cd', 32 ), array( 'ticket_public_id' => $t14->public_id, 'ticket_type_id' => $pt_b ) );
check( 'wrong access token is refused', is_wp_error( $bad ) && 403 === $bad->get_error_data()['status'] );
$html = Portal::ticket_html( '', (object) array( 'ticket_id' => $ids14[0], 'public_id' => $t14->public_id, 'status' => 'valid' ), $o14, $token );
check( 'portal shows the exchange form with allowed targets only', false !== strpos( $html, 'wpcap-exchange-form' ) && false !== strpos( $html, 'value="' . $pt_b . '"' ) && false === strpos( $html, 'value="' . $pt_soon . '"' ) );
$late = Portal::exchange( $o14->public_id, $token, array( 'ticket_public_id' => $t14->public_id, 'ticket_type_id' => $pt_soon, 'idempotency_key' => 'p-2' ) );
check( 'target within the deadline is refused', is_wp_error( $late ) && 'wpcap_exchange_too_late' === $late->get_error_code() );
$px = Portal::exchange( $o14->public_id, $token, array( 'ticket_public_id' => $t14->public_id, 'ticket_type_id' => $pt_b, 'idempotency_key' => 'p-3' ) );
check( 'portal exchange works when enabled', ! is_wp_error( $px ) && 'completed' === $px['status'] && ! isset( $px['exchange_id'] ) );
$px_again = Portal::exchange( $o14->public_id, $token, array( 'ticket_public_id' => $t14->public_id, 'ticket_type_id' => $pt_b, 'idempotency_key' => 'p-3' ) );
check( 'portal double submit is idempotent', ! is_wp_error( $px_again ) && ! empty( $px_again['reused'] ) && 2 === count( tickets( $order14 ) ) );
$new14 = ex_ticket( (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'", $order14 ) ) );
$limit = Portal::exchange( $o14->public_id, $token, array( 'ticket_public_id' => $new14->public_id, 'ticket_type_id' => $pt_a, 'idempotency_key' => 'p-4' ) );
check( 'max exchanges per ticket is enforced in the portal', is_wp_error( $limit ) && 'wpcap_exchange_limit' === $limit->get_error_code() );
$staff = Exchange::request( (int) $new14->ticket_id, $pt_a, array( 'channel' => 'admin' ) );
check( 'staff can still exchange beyond the portal limit', ! is_wp_error( $staff ) && 'completed' === $staff['status'] );
$cross = Exchange::request( (int) $staff['new_ticket_id'], $std_b, array( 'channel' => 'portal' ) );
check( 'portal only within the same event', is_wp_error( $cross ) );
ex_policy( array( 'portal_enabled' => 1, 'portal_hours' => 24, 'portal_max_per_ticket' => 5 ) );
list( $order15, $ids15 ) = ex_buy( $pt_a, 1, 'portal2@example.com' );
$vip15 = ex_type( $po_b, 'VIP Portal', 4000, 5 );
$up15 = Exchange::request( $ids15[0], $vip15, array( 'channel' => 'portal', 'waive' => true, 'refund_policy' => 'refund' ) );
check( 'portal upgrade always needs payment (waive ignored)', ! is_wp_error( $up15 ) && 'pending_payment' === $up15['status'] && 1500 === (int) $up15['due_minor'] && ! empty( $up15['payment'] ) );
ex_policy( array() );

echo "Exchange: paid supplement but old ticket gone\n";
list( $order17, $ids17 ) = ex_buy( $std_a, 1, 'review@example.com' );
$rev = Exchange::request( $ids17[0], ex_type( $occ_b, 'Empore', 3000, 5 ), array( 'channel' => 'admin' ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $ids17[0] ) );
$GLOBALS['wpcap_test_mails'] = array();
Orders::confirm_external_payment( (int) $rev['supplementary_order_id'], 'review-pay' );
check( 'exchange is flagged for review and the operator notified', 'needs_review' === Exchange::get( (int) $rev['exchange_id'] )['status'] && 1 === count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'admin@example.com' === $m['to'] ) ) && 1 === count( tickets( $order17 ) ) );

echo "Exchange: admin action\n";
list( $order18, $ids18 ) = ex_buy( $std_a, 1, 'admin@example.org' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$denied = PassCaptain\Exchange\AdminPage::exchange( array( 'ticket_id' => $ids18[0], 'ticket_type_id' => $std_b ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin exchange needs order rights', is_wp_error( $denied ) );
$adm = PassCaptain\Exchange\AdminPage::exchange( array( 'ticket_id' => $ids18[0], 'ticket_type_id' => $cheap_b, 'refund_policy' => 'none', 'idempotency_key' => 'adm-1', 'note' => 'Kunde ruft an' ) );
check( 'admin exchange with policy override', ! is_wp_error( $adm ) && 'completed' === $adm['status'] && 'none' === $adm['refund_policy'] && 0 === (int) $adm['refund_minor'] && 'Kunde ruft an' === $adm['note'] );
ob_start();
PassCaptain\Exchange\AdminPage::order_section( Orders::get( $order18 ), PassCaptain\Admin\Queries::order_tickets( $order18 ) );
$section = ob_get_clean();
check( 'order detail lists the exchange', false !== strpos( $section, 'Umgetauscht' ) && false !== strpos( $section, 'Standard' ) );

echo "Exchange: box office\n";
$pos_type = ex_type( $occ_a, 'Kasse', 2500 );
list( $order16, $ids16 ) = ex_buy( $pos_type, 1, 'pos@example.com' );
$vip16 = ex_type( $occ_b, 'VIP Kasse', 3500, 2 );
$pos = Exchange::box_office_exchange( ex_ticket( $ids16[0] )->public_id, $vip16, array( 'pos_method' => 'cash', 'idempotency_key' => 'pos-1' ) );
check( 'box office upgrade is paid at the counter and completes at once', ! is_wp_error( $pos ) && 'completed' === $pos['status'] && 'pos_cash' === Orders::get( (int) $pos['supplementary_order_id'] )->payment_provider && 'paid' === Orders::get( (int) $pos['supplementary_order_id'] )->payment_status );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$posd = Exchange::box_office_exchange( (int) $pos['new_ticket_id'], $std_b );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'box office exchange needs order rights', is_wp_error( $posd ) && 403 === $posd->get_error_data()['status'] );

echo "Exchange: concurrency (two exchanges into the last unit)\n";
$last = ex_type( $occ_b, 'Letzter Platz', 2500, 1 );
$race_type = ex_type( $occ_a, 'Race', 2500 );
$racers = array();
for ( $i = 0; $i < 4; $i++ ) { list( , $r_ids ) = ex_buy( $race_type, 1, 'race' . $i . '@example.com' ); $racers[] = $r_ids[0]; }
$procs = array();
foreach ( $racers as $ticket_id ) {
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --exchange-child ' . $ticket_id . ' ' . $last, 'r' );
}
$results = array();
foreach ( $procs as $proc ) { $results[] = trim( stream_get_contents( $proc ) ); pclose( $proc ); }
$ok = count( array_filter( $results, static fn( $r ) => 'OK:completed' === $r ) );
$sold_last = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_type_id=%d AND status='valid'", $last ) );
check( "exactly one of four parallel exchanges gets the last unit (results: " . implode( ',', $results ) . ')', 1 === $ok && 1 === $sold_last && 0 === Orders::availability( $last ) );
check( 'losers keep their valid ticket', 3 === count( array_filter( $racers, static fn( $id ) => 'valid' === ex_ticket( $id )->status ) ) );
