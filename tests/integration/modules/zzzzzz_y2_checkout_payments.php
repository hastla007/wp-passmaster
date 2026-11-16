<?php
/**
 * Package Y2 (audit panel 2026-11-15) – checkout and payment flow:
 * QA-1  a retried checkout key with other provider/items/e-mail/codes answers 409; gateways reuse an
 *       open session/payment only for the current amount; PayPal request ids carry the amount.
 * QA-6  the Stripe session never outlives the hold (minimum hold, extension on a late retry);
 *       delayed methods (Stripe SEPA, Mollie bank transfer) extend the hold.
 * PAY-1 a late payment after the hold lapsed takes its released discount code back only below the
 *       usage limit, else needs_review.
 * PAY-2 dashboard refunds (full and partial) are booked in the refund ledger, idempotently.
 * PAY-8 Stripe disputes / PayPal reversals are booked like refunds; dispute.created alerts.
 * PAY-7 an invoice issued after a refund gets the missing credit note.
 * PAY-10 PayPal does not capture for a cancelled order.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Campaigns;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Rest\Controller;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
Settings::update( array(
	'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test',
	'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1',
	'mollie_api_key' => 'test_' . str_repeat( 'a', 30 ),
	'enabled_providers' => array( 'manual', 'stripe', 'paypal', 'mollie' ),
	'require_terms' => 0, 'hold_minutes' => 10, 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0, 'auto_invoice' => 0,
) );
Registry::reset();
$y2_stripe = Registry::get( 'stripe' );
$y2_paypal = Registry::get( 'paypal' );
$y2_mollie = Registry::get( 'mollie' );

$y2_checkout = static function ( array $body ) {
	wpcap_test_reset_transients(); // Rate limit per IP: many checkouts in one test run.
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body + array( 'accept_terms' => true ) ) );
	return Controller::checkout( $r );
};
// The checkout response carries only the short reference (audit SEC-4); resolve the order for the assertions.
$y2_data = static function ( $res ) use ( $wpdb, $p ) {
	$data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
	if ( is_array( $data ) && ! isset( $data['order_id'] ) && ! empty( $data['reference'] ) ) {
		$prefix = strtolower( str_replace( '-', '', (string) $data['reference'] ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, public_id FROM {$p}wpcap_orders WHERE public_id LIKE %s ORDER BY order_id DESC LIMIT 1", addcslashes( $prefix, '_%\\' ) . '%' ) );
		if ( $row ) { $data['order_id'] = (int) $row->order_id; $data['public_id'] = $row->public_id; }
	}
	return $data;
};
$y2_hold_until = static function ( $order_id ) use ( $wpdb, $p ) {
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active'", $order_id ) );
	return $v ? strtotime( $v . ' UTC' ) : 0;
};
$y2_paid = static function ( $provider, $type, $qty, $key ) use ( $wpdb, $p ) {
	$r = Orders::create( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => $key ), array( 'payment_provider' => $provider, 'hold_minutes' => 60 ) );
	$o = Orders::get( $r['order_id'] );
	Webhooks::process( $provider, array( 'event_id' => 'paid-' . $key, 'type' => 'paid', 'order_public_id' => $o->public_id, 'provider_reference' => 'pi_' . $key, 'amount_minor' => (int) $o->total_minor, 'currency' => $o->currency ) );
	return Orders::get( $r['order_id'] );
};
$y2_refund_rows = static function ( $order_id ) use ( $wpdb, $p ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", $order_id ) );
};

/* ------------------------------------------------------------------------------------------ */
echo "Y2 QA-1: a reused idempotency key must belong to the same checkout attempt\n";
$type = make_type( 50, 10, 2500 );
$key = 'y2-qa1-' . uniqid();
$first = $y2_data( $y2_checkout( array( 'email' => 'Qa1@Example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'provider' => 'manual', 'idempotency_key' => $key ) ) );
check( 'first attempt creates a manual order', is_array( $first ) && empty( $first['reused'] ) && 'pending' === $first['payment_status'] && ! isset( $first['request_fingerprint'] ) );
http_reset();
http_push( array( 'id' => 'cs_y2_qa1', 'url' => 'https://checkout.stripe.com/c/pay/cs_y2_qa1', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$switched = $y2_checkout( array( 'email' => 'qa1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'provider' => 'stripe', 'idempotency_key' => $key ) );
check( 'same key with another provider: 409, no Stripe session for the manual order', is_wp_error( $switched ) && 'wpcap_idempotency_mismatch' === $switched->get_error_code() && 409 === $switched->get_error_data()['status'] && array() === http_requests( 'checkout/sessions' ) && 'manual' === Orders::get( $first['order_id'] )->payment_provider );
$more = $y2_checkout( array( 'email' => 'qa1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 3 ) ), 'provider' => 'manual', 'idempotency_key' => $key ) );
$other_mail = $y2_checkout( array( 'email' => 'someone@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'provider' => 'manual', 'idempotency_key' => $key ) );
$code = $y2_checkout( array( 'email' => 'qa1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'campaign_codes' => array( 'NEU' ), 'provider' => 'manual', 'idempotency_key' => $key ) );
$voucher = $y2_checkout( array( 'email' => 'qa1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'voucher_code' => 'GUT-1', 'provider' => 'manual', 'idempotency_key' => $key ) );
// Merged design: Orders::create() compares the cart of a reused key first (A420, wpcap_idempotency_conflict); the
// REST fingerprint check (wpcap_idempotency_mismatch) still catches a provider switch. Both answer 409 without booking.
$y2_conflict = static fn( $r ) => is_wp_error( $r ) && 'wpcap_idempotency_conflict' === $r->get_error_code() && 409 === ( $r->get_error_data()['status'] ?? 0 );
check( 'same key with other quantity, e-mail, discount code or voucher: 409 each', $y2_conflict( $more ) && $y2_conflict( $other_mail ) && $y2_conflict( $code ) && $y2_conflict( $voucher ) );
$same = $y2_data( $y2_checkout( array( 'email' => ' QA1@example.COM ', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ), array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => $key ) ) );
check( 'identical retry (e-mail case, split lines) reuses the order', is_array( $same ) && ! empty( $same['reused'] ) && (int) $same['order_id'] === (int) $first['order_id'] && ! isset( $same['request_fingerprint'], $same['payment_provider'] ) );
check( 'fingerprint stored on the order (DB 3.12.0)', 64 === strlen( (string) Orders::get( $first['order_id'] )->request_fingerprint ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.12.0', '>=' ) );
// Orders created before the upgrade have no fingerprint: only the provider is compared.
$wpdb->update( "{$p}wpcap_orders", array( 'request_fingerprint' => null ), array( 'order_id' => $first['order_id'] ) );
$legacy = $y2_data( $y2_checkout( array( 'email' => 'qa1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'provider' => 'manual', 'idempotency_key' => $key ) ) );
$legacy_switch = $y2_checkout( array( 'email' => 'qa1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'provider' => 'stripe', 'idempotency_key' => $key ) );
check( 'order without fingerprint: same provider reused, other provider 409', is_array( $legacy ) && ! empty( $legacy['reused'] ) && is_wp_error( $legacy_switch ) && 'wpcap_idempotency_mismatch' === $legacy_switch->get_error_code() );

echo "Y2 QA-1: gateways reuse an open payment only for the current amount\n";
http_reset();
$so = provider_order( 'stripe', $type, 2, 'y2-stripe-reuse-' . uniqid() );
Orders::set_provider_reference( $so['order_id'], 'cs_y2_old' );
$so = (array) Orders::get( $so['order_id'] );
http_push( array( 'id' => 'cs_y2_old', 'url' => 'https://checkout.stripe.com/c/pay/cs_y2_old', 'status' => 'open', 'amount_total' => 9900, 'currency' => 'eur' ), 200, '/v1/checkout/sessions/cs_y2_old' );
http_push( array( 'id' => 'cs_y2_old', 'status' => 'expired' ), 200, '/v1/checkout/sessions/cs_y2_old/expire' );
http_push( array( 'id' => 'cs_y2_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_y2_new', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$pay = $y2_stripe->create_payment( $so, order_items_array( $so['order_id'] ) );
$expire_calls = array_values( array_filter( http_requests( 'cs_y2_old/expire' ), static fn( $r ) => 'POST' === $r['method'] ) );
check( 'Stripe: open session for another amount is expired and a new one created', ! is_wp_error( $pay ) && 'cs_y2_new' === $pay['provider_reference'] && 1 === count( $expire_calls ) );
http_reset();
http_push( array( 'id' => 'cs_y2_old', 'url' => 'https://checkout.stripe.com/c/pay/cs_y2_old', 'status' => 'open', 'amount_total' => (int) $so['total_minor'], 'currency' => 'eur' ), 200, '/v1/checkout/sessions/cs_y2_old' );
$pay = $y2_stripe->create_payment( $so, order_items_array( $so['order_id'] ) );
check( 'Stripe: open session for the same amount is reused', ! is_wp_error( $pay ) && 'cs_y2_old' === $pay['provider_reference'] && 1 === count( http_requests() ) );
http_reset();
$mo = provider_order( 'mollie', $type, 2, 'y2-mollie-reuse-' . uniqid() );
Orders::set_provider_reference( $mo['order_id'], 'tr_Y2old1' );
$mo = (array) Orders::get( $mo['order_id'] );
http_push( array( 'id' => 'tr_Y2old1', 'status' => 'open', 'isCancelable' => true, 'amount' => array( 'value' => '99.00', 'currency' => 'EUR' ), '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/old' ) ) ), 200, '/v2/payments/tr_Y2old1' );
http_push( array( 'id' => 'tr_Y2old1', 'status' => 'canceled' ), 200, '/v2/payments/tr_Y2old1' );
http_push( array( 'id' => 'tr_Y2new1', 'status' => 'open', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/new' ) ) ), 201, '/v2/payments' );
$mpay = $y2_mollie->create_payment( $mo, order_items_array( $mo['order_id'] ) );
$deletes = array_values( array_filter( http_requests( 'tr_Y2old1' ), static fn( $r ) => 'DELETE' === $r['method'] ) );
check( 'Mollie: open payment for another amount is cancelled and not reused', ! is_wp_error( $mpay ) && 'tr_Y2new1' === $mpay['provider_reference'] && 1 === count( $deletes ) );

/* ------------------------------------------------------------------------------------------ */
echo "Y2 QA-6: the Stripe session never outlives the hold\n";
http_reset();
http_push( array( 'id' => 'cs_y2_hold', 'url' => 'https://checkout.stripe.com/c/pay/cs_y2_hold', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$hk = 'y2-hold-' . uniqid();
$res = $y2_data( $y2_checkout( array( 'email' => 'hold@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'stripe', 'idempotency_key' => $hk ) ) );
$hold = is_array( $res ) ? $y2_hold_until( $res['order_id'] ) : 0;
$session = form_body( http_requests( 'checkout/sessions' )[0] ?? array( 'body' => '' ) );
$expires = (int) ( $session['expires_at'] ?? 0 );
check( 'Stripe checkout holds at least 36 minutes although hold_minutes is 10', is_array( $res ) && $hold >= time() + 35 * 60 && 36 === (int) $res['hold_minutes'] );
check( 'session ends before the hold and lives at least Stripe\'s 30 minutes', $expires > 0 && $expires <= $hold - 4 * 60 && $expires >= time() + 30 * 60 );
// Late retry (hold ends in 10 minutes, old session expired): the hold is stretched for the new session.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() + 600 ), $res['order_id'] ) );
http_reset();
http_push( array( 'id' => 'cs_y2_hold', 'status' => 'expired', 'url' => '' ), 200, '/v1/checkout/sessions/cs_y2_hold' );
http_push( array( 'id' => 'cs_y2_hold2', 'url' => 'https://checkout.stripe.com/c/pay/cs_y2_hold2', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$retry = $y2_data( $y2_checkout( array( 'email' => 'hold@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'stripe', 'idempotency_key' => $hk ) ) );
$hold2 = $y2_hold_until( $res['order_id'] );
$posts = array_values( array_filter( http_requests( 'checkout/sessions' ), static fn( $r ) => 'POST' === $r['method'] ) );
$expires2 = (int) ( form_body( $posts[0] ?? array( 'body' => '' ) )['expires_at'] ?? 0 );
check( 'late retry extends the hold and the new session still ends inside it', is_array( $retry ) && ! empty( $retry['reused'] ) && $hold2 >= time() + 35 * 60 && $expires2 > 0 && $expires2 <= $hold2 - 4 * 60 );
// Lapsed hold: no new payment page.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $res['order_id'] ) );
http_reset();
http_push( array( 'id' => 'cs_y2_hold2', 'status' => 'expired', 'url' => '' ), 200, '/v1/checkout/sessions/cs_y2_hold2' );
$lapsed = $y2_checkout( array( 'email' => 'hold@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'stripe', 'idempotency_key' => $hk ) );
check( 'retry after the hold lapsed: 409, no new session', is_wp_error( $lapsed ) && 'wpcap_checkout_closed' === $lapsed->get_error_code() && array() === array_filter( http_requests( 'checkout/sessions' ), static fn( $r ) => 'POST' === $r['method'] ) );
check( 'session expiry from the hold end (direct call)', StripeGateway::session_expiry( array( 'hold_expires_at' => time() + 3600 ) ) <= time() + 3600 - 5 * 60 );

echo "Y2 QA-6: delayed methods keep the hold\n";
$dk = provider_order( 'stripe', $type, 1, 'y2-sepa-' . uniqid() );
Orders::set_provider_reference( $dk['order_id'], 'cs_test_' . $dk['public_id'] );
$sepa = stripe_deliver( $y2_stripe, stripe_session_event( 'evt_y2_sepa', 'checkout.session.completed', $dk, array( 'payment_status' => 'unpaid' ) ) );
// Merged design (A577): a delayed method keeps the tickets like a bank transfer hold (manual_hold_hours).
$y2_delayed_until = time() + (int) PassCaptain\Settings::get( 'manual_hold_hours' ) * HOUR_IN_SECONDS;
check( 'Stripe session completed with a delayed method extends the hold like a bank transfer hold', 'hold_extended' === $sepa && abs( $y2_hold_until( $dk['order_id'] ) - $y2_delayed_until ) < 120 && 'pending' === Orders::get( $dk['order_id'] )->payment_status );
http_reset();
$bk = provider_order( 'mollie', $type, 1, 'y2-bank-' . uniqid() );
Orders::set_provider_reference( $bk['order_id'], 'tr_Y2bank1' );
http_push( array( 'id' => 'tr_Y2bank1', 'status' => 'open', 'method' => 'banktransfer', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $bk['public_id'] ) ), 200, '/v2/payments/tr_Y2bank1' );
$state = PassCaptain\Modules\Mollie::handle_return( $bk['public_id'] );
check( 'Mollie bank transfer chosen: return extends the hold', 'pending' === $state && abs( $y2_hold_until( $bk['order_id'] ) - $y2_delayed_until ) < 120 );

/* ------------------------------------------------------------------------------------------ */
echo "Y2 PAY-1: a single-use code is not redeemed again by late payments of expired orders\n";
$type1 = make_type( 50, 10, 2500 );
$camp_code = 'Y2ONCE' . strtoupper( substr( uniqid(), -5 ) );
Campaigns::create( array( 'code' => $camp_code, 'kind' => 'percent', 'value' => 5000, 'currency' => 'EUR', 'usage_limit' => 1 ) );
$late_ids = array();
for ( $i = 0; $i < 3; $i++ ) {
	$lr = Orders::create( array( 'email' => "y2late$i@example.com", 'items' => array( array( 'ticket_type_id' => $type1, 'quantity' => 1 ) ), 'campaign_codes' => array( $camp_code ), 'idempotency_key' => 'y2-late-' . $i . uniqid() ), array( 'payment_provider' => 'stripe' ) );
	if ( is_wp_error( $lr ) ) { continue; }
	$late_ids[] = $lr['order_id'];
	expire_holds_of( $lr['order_id'] );
	Orders::expire_holds();
}
$late_res = array();
foreach ( $late_ids as $id ) { $late_res[] = Orders::confirm_external_payment( $id, 'stripe:evt-late-' . $id ); }
$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT used_count FROM {$p}wpcap_campaigns WHERE code=%s", $camp_code ) );
check( 'three orders with the limit-1 code were created (each after the previous hold lapsed)', 3 === count( $late_ids ) );
check( 'first late payment completes and takes the code again', true === ( $late_res[0] ?? null ) && 'complete' === Orders::get( $late_ids[0] )->status );
check( 'further late payments: needs_review (campaign exhausted), no tickets', isset( $late_res[2] ) && is_wp_error( $late_res[1] ) && 'wpcap_needs_review' === $late_res[1]->get_error_code() && 'campaign_exhausted' === ( $late_res[1]->get_error_data()['reason'] ?? '' ) && 'needs_review' === Orders::get( $late_ids[2] )->status && array() === tickets( $late_ids[1] ) );
check( 'usage count stays at the limit', 1 === $used && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_campaign_uses u INNER JOIN {$p}wpcap_campaigns c ON c.campaign_id=u.campaign_id WHERE c.code=%s AND u.status='committed'", $camp_code ) ) );

/* ------------------------------------------------------------------------------------------ */
echo "Y2 PAY-2: provider dashboard refunds are booked in the refund ledger\n";
// Merged result labels of Webhooks::process(): partial_refund / already_booked (formerly partial_refund_booked / refund_already_booked).
Invoices::register();
$type2 = make_type( 50, 10, 10000 );
$ro = $y2_paid( 'stripe', $type2, 2, 'y2-dash-' . uniqid() );
Invoices::issue_for_order( $ro->order_id );
$part = Webhooks::process( 'stripe', array( 'event_id' => 'evt_y2_p1', 'type' => 'refunded', 'refund_scope' => 'single', 'refund_id' => 're_y2_1', 'order_public_id' => $ro->public_id, 'provider_reference' => 'ch_1', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
$again = Webhooks::process( 'stripe', array( 'event_id' => 'evt_y2_p1b', 'type' => 'refunded', 'refund_scope' => 'single', 'refund_id' => 're_y2_1', 'order_public_id' => $ro->public_id, 'provider_reference' => 'ch_1', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
$o = Orders::get( $ro->order_id );
$rows = $y2_refund_rows( $ro->order_id );
check( 'partial dashboard refund: refund row, refunded_minor, order stays complete', 'partial_refund' === $part && 5000 === (int) $o->refunded_minor && 'complete' === $o->status && 1 === count( $rows ) && 'completed' === $rows[0]->status && 5000 === (int) $rows[0]->amount_minor );
check( 'same refund via another event is not booked twice', ! is_wp_error( $again ) && 1 === count( $y2_refund_rows( $ro->order_id ) ) && 5000 === (int) Orders::get( $ro->order_id )->refunded_minor );
check( 'credit note for the dashboard refund', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_credit_notes WHERE refund_id=%d", $rows[0]->refund_id ) ) );
$full = Webhooks::process( 'stripe', array( 'event_id' => 'evt_y2_full', 'type' => 'refunded', 'refund_scope' => 'full', 'order_public_id' => $ro->public_id, 'provider_reference' => 'ch_1', 'amount_minor' => 20000, 'currency' => 'EUR' ) );
$o = Orders::get( $ro->order_id );
$rows = $y2_refund_rows( $ro->order_id );
check( 'full refund books the rest and marks the order refunded', 'refunded' === $full && 'refunded' === $o->status && 20000 === (int) $o->refunded_minor && 2 === count( $rows ) && 15000 === (int) $rows[1]->amount_minor && array() === tickets( $ro->order_id, 'valid' ) );
// Real Stripe payload: charge.refunded with a partial dashboard refund.
$so2 = $y2_paid( 'stripe', $type2, 1, 'y2-dash2-' . uniqid() );
// The charge belongs to the order's booked payment (A640: refunds of another payment are not booked on the order).
$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => 'pi_y2_2' ), array( 'order_id' => $so2->order_id ) );
$charge = array( 'id' => 'ch_y2_2', 'object' => 'charge', 'amount' => 10000, 'amount_refunded' => 2500, 'refunded' => false, 'currency' => 'eur', 'metadata' => array( 'wpcap_order' => $so2->public_id ), 'payment_intent' => 'pi_y2_2', 'refunds' => array( 'data' => array( array( 'id' => 're_y2_2', 'amount' => 2500, 'metadata' => array() ) ) ) );
$res2 = stripe_deliver( $y2_stripe, array( 'id' => 'evt_y2_ch2', 'type' => 'charge.refunded', 'data' => array( 'object' => $charge ) ) );
check( 'Stripe charge.refunded (dashboard, partial) is booked', 'partial_refund' === $res2 && 2500 === (int) Orders::get( $so2->order_id )->refunded_minor );
// Mollie reports the running total of external refunds.
$mo2 = $y2_paid( 'mollie', $type2, 1, 'y2-mol-' . uniqid() );
$m1 = Webhooks::process( 'mollie', array( 'event_id' => 'mollie-x-refunded-3000', 'type' => 'refunded', 'refund_scope' => 'cumulative', 'order_public_id' => $mo2->public_id, 'provider_reference' => 'tr_x', 'amount_minor' => 3000, 'currency' => 'EUR' ) );
$m2 = Webhooks::process( 'mollie', array( 'event_id' => 'mollie-x-refunded-5000', 'type' => 'refunded', 'refund_scope' => 'cumulative', 'order_public_id' => $mo2->public_id, 'provider_reference' => 'tr_x', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
check( 'Mollie cumulative refunds: only the difference is booked (3000 + 2000)', 'partial_refund' === $m1 && 'partial_refund' === $m2 && 5000 === (int) Orders::get( $mo2->order_id )->refunded_minor && 2 === count( $y2_refund_rows( $mo2->order_id ) ) );
// A plugin refund still being paid out is not external money.
$po2 = $y2_paid( 'stripe', $type2, 1, 'y2-proc-' . uniqid() );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $po2->order_id, 'idempotency_key' => hash( 'sha256', 'y2-proc' . uniqid() ), 'amount_minor' => 10000, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'reason' => 'x', 'status' => 'processing', 'provider_reference' => '', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
Webhooks::process( 'stripe', array( 'event_id' => 'evt_y2_proc', 'type' => 'refunded', 'refund_scope' => 'full', 'order_public_id' => $po2->public_id, 'provider_reference' => 'ch_p', 'amount_minor' => 10000, 'currency' => 'EUR' ) );
check( 'full refund event while the plugin refund is processing books no external refund', 1 === count( $y2_refund_rows( $po2->order_id ) ) && 0 === (int) Orders::get( $po2->order_id )->refunded_minor );

/* ------------------------------------------------------------------------------------------ */
echo "Y2 PAY-8: chargebacks and disputes\n";
$do = $y2_paid( 'stripe', $type2, 1, 'y2-disp-' . uniqid() );
$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => 'pi_y2_d' ), array( 'order_id' => $do->order_id ) ); // The disputed payment is the booked one (A640).
$GLOBALS['wpcap_test_mails'] = array();
http_reset();
http_push( array( 'id' => 'pi_y2_d', 'metadata' => array( 'wpcap_order' => $do->public_id ) ), 200, '/v1/payment_intents/pi_y2_d' );
$dispute = array( 'id' => 'dp_y2_1', 'object' => 'dispute', 'amount' => 10000, 'currency' => 'eur', 'charge' => 'ch_y2_d', 'payment_intent' => 'pi_y2_d', 'status' => 'needs_response' );
$opened = stripe_deliver( $y2_stripe, array( 'id' => 'evt_y2_d1', 'type' => 'charge.dispute.created', 'data' => array( 'object' => $dispute ) ) );
check( 'dispute.created: admin alert, nothing booked, tickets stay valid', 'dispute_alerted' === $opened && 1 === count( $GLOBALS['wpcap_test_mails'] ) && array() === $y2_refund_rows( $do->order_id ) && 1 === count( tickets( $do->order_id, 'valid' ) ) );
http_push( array( 'id' => 'pi_y2_d', 'metadata' => array( 'wpcap_order' => $do->public_id ) ), 200, '/v1/payment_intents/pi_y2_d' );
$withdrawn = stripe_deliver( $y2_stripe, array( 'id' => 'evt_y2_d2', 'type' => 'charge.dispute.funds_withdrawn', 'data' => array( 'object' => $dispute ) ) );
http_push( array( 'id' => 'pi_y2_d', 'metadata' => array( 'wpcap_order' => $do->public_id ) ), 200, '/v1/payment_intents/pi_y2_d' );
$lost = stripe_deliver( $y2_stripe, array( 'id' => 'evt_y2_d3', 'type' => 'charge.dispute.closed', 'data' => array( 'object' => array_merge( $dispute, array( 'status' => 'lost' ) ) ) ) );
$drows = $y2_refund_rows( $do->order_id );
check( 'funds_withdrawn books the chargeback as refund; closed(lost) does not book it again', 'refunded' === $withdrawn && 'refunded' === $lost && 1 === count( $drows ) && 10000 === (int) $drows[0]->amount_minor && 'stripe_chargeback' === $drows[0]->reason && 'refunded' === Orders::get( $do->order_id )->status && array() === tickets( $do->order_id, 'valid' ) );
$pp = $y2_paid( 'paypal', $type2, 1, 'y2-rev-' . uniqid() );
http_reset();
http_push( array( 'access_token' => 'Y2-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$rev = $y2_paypal->verify_webhook( paypal_request( array( 'id' => 'WH-Y2-REV', 'event_type' => 'PAYMENT.CAPTURE.REVERSED', 'resource' => array( 'id' => 'REV-Y2', 'status' => 'COMPLETED', 'custom_id' => $pp->public_id, 'amount' => array( 'currency_code' => 'EUR', 'value' => '100.00' ) ) ) ) );
$rev_res = is_wp_error( $rev ) ? $rev : Webhooks::process( 'paypal', $rev );
$prows = $y2_refund_rows( $pp->order_id );
check( 'PayPal PAYMENT.CAPTURE.REVERSED is booked like a refund', 'refunded' === $rev_res && 1 === count( $prows ) && 'paypal_chargeback' === $prows[0]->reason && 'refunded' === Orders::get( $pp->order_id )->status );
$pd = $y2_paid( 'paypal', $type2, 1, 'y2-ppd-' . uniqid() );
$GLOBALS['wpcap_test_mails'] = array();
http_reset();
http_push( array( 'access_token' => 'Y2-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
http_push( array( 'id' => 'CAPY2D', 'custom_id' => $pd->public_id ), 200, '/v2/payments/captures/CAPY2D' );
$pdis = $y2_paypal->verify_webhook( paypal_request( array( 'id' => 'WH-Y2-DIS', 'event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => array( 'dispute_id' => 'PP-D-1', 'dispute_amount' => array( 'currency_code' => 'EUR', 'value' => '100.00' ), 'disputed_transactions' => array( array( 'seller_transaction_id' => 'CAPY2D' ) ) ) ) ) );
$pdis_res = is_wp_error( $pdis ) ? $pdis : Webhooks::process( 'paypal', $pdis );
check( 'PayPal CUSTOMER.DISPUTE.CREATED alerts the admin', 'dispute_alerted' === $pdis_res && 1 === count( $GLOBALS['wpcap_test_mails'] ) && 'complete' === Orders::get( $pd->order_id )->status );

/* ------------------------------------------------------------------------------------------ */
echo "Y2 PAY-7: invoice issued after a refund gets the credit note\n";
$type7 = make_type( 50, 10, 10000 );
$r7 = Orders::create( array( 'email' => 'y2inv@example.com', 'items' => array( array( 'ticket_type_id' => $type7, 'quantity' => 2 ) ), 'idempotency_key' => 'y2-inv-' . uniqid() ) );
Orders::confirm_manual_payment( $r7['order_id'] );
$t7 = array_values( Orders::tickets_for_order( $r7['order_id'] ) );
$rf7 = Refunds::request( $r7['order_id'], array( (int) $t7[0]->ticket_id ), 'x', 'y2-ir-' . uniqid() );
$inv7 = Invoices::issue_for_order( $r7['order_id'] );
$cn7 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", is_array( $rf7 ) ? (int) $rf7['refund_id'] : 0 ) );
check( 'credit note created for the earlier refund when the invoice is issued', is_array( $rf7 ) && 'completed' === $rf7['status'] && is_array( $inv7 ) && $cn7 && (int) $cn7->invoice_id === (int) $inv7['invoice_id'] && 10000 === (int) $cn7->total_minor );
$inv7b = Invoices::issue_for_order( $r7['order_id'] );
check( 'issuing again creates no second credit note', ! empty( $inv7b['reused'] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_credit_notes WHERE refund_id=%d", (int) $rf7['refund_id'] ) ) );

/* ------------------------------------------------------------------------------------------ */
echo "Y2 PAY-10: PayPal does not capture for a closed order\n";
http_reset();
$cp = provider_order( 'paypal', $type7, 1, 'y2-ppclosed-' . uniqid() );
Orders::set_provider_reference( $cp['order_id'], 'PAYY2CLOSED' );
Orders::cancel_order( $cp['order_id'], 'test' );
http_push( array( 'id' => 'PAYY2CLOSED', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAPY2C', 'status' => 'COMPLETED', 'custom_id' => $cp['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '100.00' ) ) ) ) ) ) ), 201, '/v2/checkout/orders/PAYY2CLOSED/capture' );
$ret = PayPalGateway::handle_return( $cp['public_id'], 'PAYY2CLOSED' );
check( 'return for a cancelled order: no capture call', is_wp_error( $ret ) && 'wpcap_paypal_order_closed' === $ret->get_error_code() && array() === http_requests( '/capture' ) );
http_reset();
http_push( array( 'access_token' => 'Y2-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$appr = $y2_paypal->verify_webhook( paypal_request( array( 'id' => 'WH-Y2-APPR', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => array( 'id' => 'PAYY2CLOSED', 'purchase_units' => array( array( 'custom_id' => $cp['public_id'] ) ) ) ) ) );
check( 'CHECKOUT.ORDER.APPROVED for a cancelled order: ignored, no capture call', is_array( $appr ) && 'ignored' === $appr['type'] && array() === http_requests( '/capture' ) );

http_reset();
Settings::update( array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'paypal_client_id' => '', 'paypal_client_secret' => '', 'paypal_webhook_id' => '', 'mollie_api_key' => '', 'enabled_providers' => array( 'manual' ), 'hold_minutes' => 10 ) );
Registry::reset();
