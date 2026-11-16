<?php
/**
 * A358: a paid order without tickets (`needs_review`/`paid_unallocated`) can be closed by the admin –
 * refunded through the provider or recorded as refunded outside the plugin.
 *
 * Included from run.php (uses check(), make_type(), tickets(), expire_holds_of() and the HTTP fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }

global $wpdb;
$p = $wpdb->prefix;

Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_a358', 'stripe_webhook_secret' => 'whsec_test', 'enabled_providers' => array( 'manual', 'stripe' ), 'late_payment_auto_refund' => 0 ) );
Registry::reset();
$a358_unallocated = static function ( $provider, $key ) use ( $wpdb, $p ) {
	$type = make_type( 10, 10, 2200 );
	$r = Orders::create( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => $key ), array( 'payment_provider' => $provider, 'hold_minutes' => 30 ) );
	if ( 'stripe' === $provider ) { Orders::set_provider_reference( $r['order_id'], 'pi_' . str_replace( '-', '', $key ) ); }
	expire_holds_of( $r['order_id'] );
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.status='cancelled' WHERE tt.ticket_type_id=%d", $type ) );
	Orders::confirm_external_payment( $r['order_id'], $provider . ':late' );
	return (int) $r['order_id'];
};
$a358_state = static function ( $order_id ) { $o = Orders::get( $order_id ); return $o->status . '/' . $o->payment_status; };

echo "A358: refund a paid order without tickets through the provider\n";
$a358_s = $a358_unallocated( 'stripe', 'a358-stripe' );
check( 'A358: setup – needs_review / paid_unallocated, no tickets', 'needs_review/paid_unallocated' === $a358_state( $a358_s ) && ! tickets( $a358_s ) );
check( 'A358: the regular refund still refuses it (no ticket, not "paid")', is_wp_error( Refunds::request( $a358_s, array(), 'x', 'a358-regular' ) ) );

// The order page renders these forms in the needs_review notice (render_detail()).
$a358_render = new ReflectionMethod( OrdersPage::class, 'render_settle_unallocated' );
$a358_render->setAccessible( true );
ob_start();
$a358_render->invoke( null, Orders::get( $a358_s ) );
$a358_html = ob_get_clean();
check( 'A358: the order page offers both actions', false !== strpos( $a358_html, 'wpcap_settle_unallocated' ) && false !== strpos( $a358_html, 'über' ) && false !== strpos( $a358_html, 'Extern erstattet – abschließen' ) );

$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$a358_denied = OrdersPage::settle_unallocated( array( 'order_id' => $a358_s, 'mode' => 'provider', 'reason' => 'Ausverkauft' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A358: only refund approvers may close it', is_wp_error( $a358_denied ) && 'needs_review/paid_unallocated' === $a358_state( $a358_s ) );
check( 'A358: a reason is required', is_wp_error( OrdersPage::settle_unallocated( array( 'order_id' => $a358_s, 'mode' => 'provider', 'reason' => '' ) ) ) );

http_reset();
http_push( array( 'error' => array( 'message' => 'Stripe down' ) ), 500, '/v1/refunds' );
$a358_fail = OrdersPage::settle_unallocated( array( 'order_id' => $a358_s, 'mode' => 'provider', 'reason' => 'Ausverkauft' ) );
check( 'A358: a failed provider refund leaves the order for review', is_wp_error( $a358_fail ) && 'needs_review/paid_unallocated' === $a358_state( $a358_s ) );
http_reset();
http_push( array( 'id' => 're_a358', 'status' => 'succeeded' ), 200, '/v1/refunds' );
$a358_ok = OrdersPage::settle_unallocated( array( 'order_id' => $a358_s, 'mode' => 'provider', 'reason' => 'Ausverkauft' ) );
$a358_req = http_requests( '/v1/refunds' )[0] ?? array( 'headers' => array(), 'body' => '' );
$a358_body = form_body( $a358_req );
check( 'A358: full amount refunded at Stripe with the key of the automatic late refund', ! is_wp_error( $a358_ok ) && '4400' === (string) ( $a358_body['amount'] ?? '' ) && 'wpcap-late-refund-' . $a358_s === ( $a358_req['headers']['Idempotency-Key'] ?? '' ) );
$a358_o = Orders::get( $a358_s );
check( 'A358: order refunded, refunded amount = total', 'refunded/refunded' === $a358_state( $a358_s ) && (int) $a358_o->total_minor === (int) $a358_o->refunded_minor );
check( 'A358: audited with mode and reference', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='unallocated_settled' AND object_id=%d AND details LIKE %s", $a358_s, '%re_a358%' ) ) > 0 );
check( 'A358: a second submit is refused (state), nothing refunded again', is_wp_error( OrdersPage::settle_unallocated( array( 'order_id' => $a358_s, 'mode' => 'provider', 'reason' => 'x' ) ) ) && 1 === count( http_requests( '/v1/refunds' ) ) );

echo "A358: refunded outside the plugin – close\n";
$a358_m = $a358_unallocated( 'manual', 'a358-manual' );
check( 'A358: setup – bank transfer paid late, needs_review', 'needs_review/paid_unallocated' === $a358_state( $a358_m ) );
check( 'A358: a bank transfer cannot be refunded through the provider', is_wp_error( Orders::settle_unallocated( $a358_m, 'provider', 'x' ) ) && 'needs_review/paid_unallocated' === $a358_state( $a358_m ) );
http_reset();
$a358_ext = OrdersPage::settle_unallocated( array( 'order_id' => $a358_m, 'mode' => 'external', 'reason' => 'Rücküberweisung 12.11.' ) );
$a358_mo = Orders::get( $a358_m );
check( 'A358: "Extern erstattet" closes the order without any provider call', ! is_wp_error( $a358_ext ) && 'refunded/refunded' === $a358_state( $a358_m ) && (int) $a358_mo->total_minor === (int) $a358_mo->refunded_minor && ! $GLOBALS['wpcap_test_http_requests'] );
check( 'A358: unknown mode is refused', is_wp_error( Orders::settle_unallocated( $a358_unallocated( 'manual', 'a358-mode' ), 'bogus' ) ) );
http_reset();
