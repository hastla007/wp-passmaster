<?php
/**
 * Z2: residual payment/box office points from the audit panel.
 *
 * - PAY-V2: provider refund retries use a new idempotency key only after a final decline (4xx /
 *   refund object failed); network errors and 5xx keep the key (no second payout).
 * - PAY-V3: card-terminal contract 1.1 – a charged amount/currency reported by the device must match
 *   the order, else the sale ends as amount_mismatch and nothing is booked.
 * - PAY-V4: partial refunds of box office orders reduce the expected cash of the register session;
 *   refunds after the close go to the open session of the same register or are recorded unassigned.
 * - record_external(): no zero refund row when nothing is left to book.
 *
 * Included from run.php (uses check(), make_type(), tickets(), http fakes from payments.php and the box
 * office helpers wpb_key()/wpb_occurrence_of()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\DeviceGatewayInterface;
use PassCaptain\BoxOffice\Devices;
use PassCaptain\BoxOffice\Service as Z2Pos;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Refunds;
use PassCaptain\Settings;

require_once WPCAP_PATH . 'src/functions.php'; // wpcap_confirm_pos_payment() (public add-on API).
global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $action = -1 ) { return 'n'; } }
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$z2_settings_before = array(
	'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ),
	'require_2fa_for_refunds'   => Settings::get( 'require_2fa_for_refunds' ),
	'boxoffice_terminals'       => Settings::get( 'boxoffice_terminals' ),
);
Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0, 'stripe_secret_key' => 'sk_test_123', 'boxoffice_terminals' => '' ) );
Registry::reset();

/** Paid order with $qty tickets of a fresh type, optionally switched to a provider afterwards. */
function z2_paid_order( $qty, $price, $provider = '', $reference = '' ) {
	global $wpdb;
	$type = make_type( 50, 10, $price );
	$r = Orders::create( array( 'email' => 'z2-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'z2-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
	Orders::confirm_manual_payment( $r['order_id'] );
	if ( '' !== $provider ) {
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payment_provider' => $provider, 'provider_reference' => $reference ), array( 'order_id' => (int) $r['order_id'] ) );
	}
	return (int) $r['order_id'];
}
function z2_refund_rows( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", $order_id ) );
}
function z2_header( $request, $name ) {
	foreach ( (array) ( $request['headers'] ?? array() ) as $k => $v ) { if ( strtolower( $k ) === strtolower( $name ) ) { return (string) $v; } }
	return '';
}

/* ------------------------------------------------------------------------------------------ */
echo "Z2: record_external() writes no zero refund row\n";
$z2_o = z2_paid_order( 2, 1000 );
$z2_noop = Refunds::record_external( $z2_o, array(), 0, 'extern', 'z2-zero-' . uniqid() );
check( 'record_external: amount 0 without tickets is a no-op (false, no refund row, order unchanged)', false === $z2_noop && array() === z2_refund_rows( $z2_o ) && 0 === (int) Orders::get( $z2_o )->refunded_minor );
$z2_neg = Refunds::record_external( $z2_o, array(), -500, 'extern', 'z2-neg-' . uniqid() );
check( 'record_external: a negative amount without tickets is a no-op', false === $z2_neg && array() === z2_refund_rows( $z2_o ) );
$z2_full = Refunds::record_external( $z2_o, array(), 2000, 'extern', 'z2-full-' . uniqid() );
$z2_late = Refunds::record_external( $z2_o, array(), 500, 'extern', 'z2-late-' . uniqid() );
// Merged design (A346): a positive amount that finds nothing left is answered with wpcap_refund_nothing (409).
check( 'record_external: a refund that finds nothing left under the order lock (race) writes no 0.00 row', is_array( $z2_full ) && 2000 === (int) $z2_full['amount_minor'] && is_wp_error( $z2_late ) && 'wpcap_refund_nothing' === $z2_late->get_error_code() && 1 === count( z2_refund_rows( $z2_o ) ) && 2000 === (int) Orders::get( $z2_o )->refunded_minor );
$z2_free = z2_paid_order( 1, 0 );
$z2_free_t = tickets( $z2_free );
$z2_free_r = Refunds::record_external( $z2_free, array( (int) $z2_free_t[0]->ticket_id ), 0, 'extern', 'z2-free-' . uniqid() );
check( 'record_external: a zero amount with tickets (free ticket) is still recorded and revokes the ticket', is_array( $z2_free_r ) && 0 === (int) $z2_free_r['amount_minor'] && 'revoked' === tickets( $z2_free )[0]->status );

/* ------------------------------------------------------------------------------------------ */
echo "Z2 PAY-V2: idempotency key of provider refund retries\n";
check( 'is_final_decline: 4xx and failed refund objects are final; 409, 429, 5xx and network errors are not', Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_api', 'x', array( 'status' => 400 ) ) ) && Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_api', 'x', array( 'status' => 402 ) ) ) && Refunds::is_final_decline( new WP_Error( 'wpcap_paypal_api', 'x', array( 'status' => 422, 'issue' => 'REFUND_FAILED_INSUFFICIENT_FUNDS' ) ) ) && Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_refund_failed', 'x' ) ) && Refunds::is_final_decline( new WP_Error( 'wpcap_mollie_refund_failed', 'x' ) ) && ! Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_api', 'x', array( 'status' => 409 ) ) ) && ! Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_api', 'x', array( 'status' => 429 ) ) ) && ! Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_api', 'x', array( 'status' => 503 ) ) ) && ! Refunds::is_final_decline( new WP_Error( 'wpcap_stripe_http', 'timeout' ) ) );
check( 'provider_key: attempt 0 keeps the historic key, later attempts are numbered', 'wpcap-refund-7' === Refunds::provider_key( 7 ) && 'wpcap-refund-7-2' === Refunds::provider_key( 7, 2 ) );

// Final decline (Stripe 400, e.g. insufficient balance), then a retry after the cause was fixed.
$z2_so = z2_paid_order( 2, 1500, 'stripe', 'pi_z2decline' );
$z2_st = tickets( $z2_so );
http_reset();
http_push( array( 'error' => array( 'message' => 'Insufficient available balance', 'type' => 'invalid_request_error' ) ), 400, '/v1/refunds' );
$z2_rf = Refunds::request( $z2_so, array( (int) $z2_st[0]->ticket_id ), 'z2', 'z2-decline-' . uniqid() );
$z2_row = z2_refund_rows( $z2_so )[0] ?? null;
$z2_req = http_requests( '/v1/refunds' );
check( 'final decline: refund failed, attempt counter 1, first request used the historic key', is_wp_error( $z2_rf ) && $z2_row && 'failed' === $z2_row->status && 1 === (int) $z2_row->provider_attempt && 1 === count( $z2_req ) && 'wpcap-refund-' . $z2_row->refund_id === z2_header( $z2_req[0], 'Idempotency-Key' ) );
http_reset();
http_push( array( 'id' => 're_z2ok', 'status' => 'succeeded' ), 200, '/v1/refunds' );
$z2_retry = Refunds::execute( (int) $z2_row->refund_id );
$z2_req = http_requests( '/v1/refunds' );
check( 'retry after a final decline uses a new key (Stripe would replay the stored error for 24 h) and completes', is_array( $z2_retry ) && 'completed' === $z2_retry['status'] && 're_z2ok' === $z2_retry['provider_reference'] && 1 === count( $z2_req ) && 'wpcap-refund-' . $z2_row->refund_id . '-1' === z2_header( $z2_req[0], 'Idempotency-Key' ) && 1500 === (int) Orders::get( $z2_so )->refunded_minor );

// Network error and 5xx: the key must stay (the first request may have paid out). Merged design (A272): an
// unclear outcome is never re-sent blindly – the refund stays "processing" (outcome_unknown) and is settled by
// looking it up at the provider by its key (job refund_reconcile, webhook, admin), so no second payout.
$z2_no = z2_paid_order( 1, 1500, 'stripe', 'pi_z2network' );
http_reset(); // No scripted response: the fake answers with a transport error.
$z2_rf = Refunds::request( $z2_no, array(), 'z2', 'z2-net-' . uniqid() );
$z2_row = z2_refund_rows( $z2_no )[0] ?? null;
http_push( array( 'error' => array( 'message' => 'Internal error' ) ), 500, '/v1/refunds' );
$z2_rf2 = Refunds::execute( (int) $z2_row->refund_id );
$z2_posts = array_values( array_filter( http_requests( '/v1/refunds' ), static fn( $r ) => 'POST' === $r['method'] ) );
http_reset();
http_push( array( 'data' => array( array( 'id' => 're_z2net', 'status' => 'succeeded', 'amount' => 1500, 'metadata' => array( 'wpcap_refund' => 'wpcap-refund-' . $z2_row->refund_id ) ) ), 'has_more' => false ), 200, '/v1/refunds?' );
$z2_rf3 = Refunds::reconcile( (int) $z2_row->refund_id );
$z2_done = Refunds::get( (int) $z2_row->refund_id );
check( 'network error and 5xx keep the same key on every retry (no second payout), counter stays 0', is_wp_error( $z2_rf ) && 'wpcap_refund_outcome_unknown' === $z2_rf->get_error_code() && is_array( $z2_rf2 ) && 'processing' === $z2_rf2['status'] && 1 === count( $z2_posts ) && 'wpcap-refund-' . $z2_row->refund_id === z2_header( $z2_posts[0], 'Idempotency-Key' ) && 'completed' === $z2_rf3 && 'completed' === $z2_done['status'] && 're_z2net' === $z2_done['provider_reference'] && 0 === (int) $z2_done['provider_attempt'] );

// Refund object returned with status "failed" (asynchronous decline reported synchronously).
$z2_fo = z2_paid_order( 1, 1500, 'stripe', 'pi_z2failed' );
http_reset();
http_push( array( 'id' => 're_z2failed', 'status' => 'failed' ), 200, '/v1/refunds' );
$z2_rf = Refunds::request( $z2_fo, array(), 'z2', 'z2-failed-' . uniqid() );
check( 'a refund object with status failed counts as final decline (counter 1)', is_wp_error( $z2_rf ) && 'wpcap_stripe_refund_failed' === $z2_rf->get_error_code() && 1 === (int) z2_refund_rows( $z2_fo )[0]->provider_attempt );
// PayPal and Mollie use the same execute() path with their request-id / Idempotency-Key header.
$z2_mo = z2_paid_order( 1, 1500, 'mollie', 'tr_z2mollie' );
Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'a', 30 ) ) );
Registry::reset();
http_reset();
http_push( array( 'status' => 422, 'detail' => 'The amount is higher than the refundable amount' ), 422, 'payments/tr_z2mollie/refunds' );
$z2_rf = Refunds::request( $z2_mo, array(), 'z2', 'z2-mollie-' . uniqid() );
$z2_mrow = z2_refund_rows( $z2_mo )[0];
http_push( array( 'id' => 're_z2m', 'status' => 'pending' ), 201, 'payments/tr_z2mollie/refunds' );
$z2_rf2 = Refunds::execute( (int) $z2_mrow->refund_id );
$z2_keys = array_map( static fn( $r ) => z2_header( $r, 'Idempotency-Key' ), http_requests( 'payments/tr_z2mollie/refunds' ) );
check( 'Mollie: 422 is a final decline, the retry gets a new Idempotency-Key', is_wp_error( $z2_rf ) && is_array( $z2_rf2 ) && 'completed' === $z2_rf2['status'] && array( 'wpcap-refund-' . $z2_mrow->refund_id, 'wpcap-refund-' . $z2_mrow->refund_id . '-1' ) === $z2_keys );
http_reset();

/* ------------------------------------------------------------------------------------------ */
echo "Z2 PAY-V3: terminal contract 1.1 – charged amount is checked against the order\n";
final class Z2_Device implements DeviceGatewayInterface {
	public $answer = array();
	public $last_sale = array();
	public function label(): string { return 'Z2 Terminal'; }
	public function start( array $sale ) { $this->last_sale = $sale; return array( 'reference' => 'z2tx_' . substr( $sale['sale_key'], 0, 8 ), 'status' => 'pending' ); }
	public function poll( string $reference ) { return $this->answer + array( 'status' => 'pending' ); }
	public function cancel( string $reference ) { return true; }
}
$GLOBALS['z2_device'] = new Z2_Device();
$GLOBALS['z2_device_on'] = true;
add_filter( Devices::FILTER, static function ( $devices ) { if ( $GLOBALS['z2_device_on'] ) { $devices['z2'] = $GLOBALS['z2_device']; } return $devices; }, 10, 2 );
$z2_failed_hooks = array();
add_action( 'wp_pass_captain_pos_device_payment_failed_v1', static function ( $sale_id, $status ) use ( &$z2_failed_hooks ) { $z2_failed_hooks[] = $status; }, 10, 2 );
// The back-office refunds of PAY-V4 are requested by user 1, who must not have an open shift of their own
// (in a full run earlier modules may have left one open, also blocking its register): close it first.
$z2_user = $GLOBALS['wpcap_test_user_id'];
$GLOBALS['wpcap_test_user_id'] = 1;
for ( $z2_i = 0; $z2_i < 5 && ( $z2_own = PassCaptain\BoxOffice\Shifts::current_for_user( 1 ) ); $z2_i++ ) {
	Z2Pos::close_session( (int) $z2_own->session_id, Z2Pos::session_totals( (int) $z2_own->session_id )['cash']['expected'] ?? 0, 'Z2: Schicht eines früheren Tests' );
}
$GLOBALS['wpcap_test_user_id'] = $z2_user;
$GLOBALS['wpcap_test_user_id'] = 7201;
$z2_type = make_type( 30, 10, 1200 );
$z2_occ = wpb_occurrence_of( $z2_type );
$z2_session = Z2Pos::open_session( 5000, 'EUR', '', 31, 'Z2-BOX' );
$z2_sell = static function ( $method, $qty = 1, $device = 'z2' ) use ( $z2_occ, $z2_type ) {
	return Z2Pos::sell( array( 'occurrence_id' => $z2_occ, 'quantities' => array( $z2_type => $qty ), 'method' => $method, 'device' => $device, 'form_key' => wpb_key() ) );
};
$z2_dev = $GLOBALS['z2_device'];
$z2_mails = count( $GLOBALS['wpcap_test_mails'] ?? array() );
$z2_s = $z2_sell( 'card_device' );
check( 'start payload announces contract 1.1 (version stays 1)', is_array( $z2_s ) && 1 === $z2_dev->last_sale['version'] && '1.1' === $z2_dev->last_sale['contract'] && 1 === DeviceGatewayInterface::VERSION );
$z2_sale = $z2_s['sale'];
$z2_dev->answer = array( 'status' => 'succeeded', 'provider_reference' => 'ch_z2a', 'amount_minor' => 1100, 'currency' => 'EUR' );
$z2_state = Z2Pos::poll_device_payment( $z2_sale->sale_id );
$z2_sale = Z2Pos::sale( $z2_sale->sale_id );
check( 'poll succeeded with another amount: not confirmed, sale failed as amount_mismatch, order cancelled, no tickets', is_array( $z2_state ) && 'amount_mismatch' === $z2_state['status'] && 'failed' === $z2_sale->status && 'amount_mismatch' === $z2_sale->device_status && 'paid' !== Orders::get( $z2_sale->order_id )->payment_status && 'cancelled' === Orders::get( $z2_sale->order_id )->status && 0 === count( tickets( $z2_sale->order_id ) ) && null === $z2_sale->receipt_number );
check( 'amount mismatch: alert mail, audit entry and failed_v1 with status amount_mismatch', 1 === count( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $z2_mails ), static fn( $m ) => str_contains( (string) $m['subject'], 'Terminalzahlung prüfen' ) ) ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_device_amount_mismatch' AND object_id=" . (int) $z2_sale->order_id ) && in_array( 'amount_mismatch', $z2_failed_hooks, true ) );
$z2_late = Z2Pos::confirm_device_payment( (string) $z2_sale->order_id, 'ch_z2a' );
check( 'a later confirmation of the mismatched sale is never booked', is_wp_error( $z2_late ) && 'wpcap_pos_device_not_pending' === $z2_late->get_error_code() );

$z2_s = $z2_sell( 'card_device' );
$z2_sale = $z2_s['sale'];
$z2_wrong_cur = Z2Pos::confirm_device_payment( $z2_sale->form_key_hash, 'ch_z2b', array( 'amount_minor' => 1200, 'currency' => 'chf' ) );
check( 'webhook confirmation with another currency: 409 amount_mismatch, nothing booked', is_wp_error( $z2_wrong_cur ) && 'wpcap_pos_device_amount_mismatch' === $z2_wrong_cur->get_error_code() && 409 === $z2_wrong_cur->get_error_data()['status'] && 'amount_mismatch' === Z2Pos::sale( $z2_sale->sale_id )->device_status && 0 === count( tickets( $z2_sale->order_id ) ) );
$z2_s = $z2_sell( 'card_device' );
$z2_bad = Z2Pos::confirm_device_payment( (string) $z2_s['sale']->order_id, 'ch_z2c', array( 'amount_minor' => '12.00' ) );
check( 'malformed amount (not a whole number of minor units) is refused, not ignored', is_wp_error( $z2_bad ) && 'wpcap_pos_device_amount_mismatch' === $z2_bad->get_error_code() );

$z2_s = $z2_sell( 'card_device' );
$z2_ok = wpcap_confirm_pos_payment( (int) $z2_s['sale']->order_id, 'ch_z2d', array( 'amount_minor' => 1200, 'currency' => 'EUR' ) );
check( 'matching amount and currency: confirmed and booked', true === $z2_ok && 'completed' === Z2Pos::sale( $z2_s['sale']->sale_id )->status && 1 === count( tickets( $z2_s['sale']->order_id, 'valid' ) ) );
$z2_s = $z2_sell( 'card_device' );
$z2_dev->answer = array( 'status' => 'succeeded', 'provider_reference' => 'ch_z2e' );
$z2_state = Z2Pos::poll_device_payment( $z2_s['sale']->sale_id );
check( 'contract 1.0 answer without amount: booked as before (no check)', 'succeeded' === $z2_state['status'] && 'completed' === Z2Pos::sale( $z2_s['sale']->sale_id )->status );
$GLOBALS['z2_device_on'] = false;

/* ------------------------------------------------------------------------------------------ */
echo "Z2 PAY-V4: refunds of box office orders in the expected cash balance\n";
// Merged design (shifts, A488): a refund of a cash sale is booked as a negative "refund" movement into the
// shift of whoever pays it out – the refunding cashier's own open shift, else it waits as an open box office
// payout until a cashier pays it out at the till. Z-report keys: methods[m].refund_amount/refund_count,
// counts.refunds/stornos.
$z2_movements = static function ( $refund_id ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_movements WHERE refund_id=%d", $refund_id ) ); };
$z2_before = Z2Pos::session_totals( $z2_session->session_id );
$z2_cash = $z2_sell( 'cash', 2 );
$z2_cash_sale = $z2_cash['sale'];
$z2_ct = tickets( $z2_cash_sale->order_id );
$z2_part = Refunds::request( (int) $z2_cash_sale->order_id, array( (int) $z2_ct[0]->ticket_id ), 'Teilerstattung an der Kasse', 'z2-pos-part-' . uniqid() );
$z2_after = Z2Pos::session_totals( $z2_session->session_id );
check( 'partial refund of a cash sale (refund page) reduces the expected cash of the open session', is_array( $z2_part ) && 'completed' === $z2_part['status'] && 1200 === (int) $z2_part['amount_minor'] && $z2_before['expected_cash'] + 2400 - 1200 === $z2_after['expected_cash'] && 1200 === $z2_after['methods']['cash']['refund_amount'] && 1 === $z2_after['methods']['cash']['refund_count'] && 1 === $z2_after['counts']['refunds'] );
$GLOBALS['wpcap_test_user_id'] = 1;
Z2Pos::on_refund_completed( (int) $z2_part['refund_id'], (int) $z2_cash_sale->order_id );
check( 'booking the refund is idempotent (hook fired twice → one row)', 1 === $z2_movements( (int) $z2_part['refund_id'] ) );
$GLOBALS['wpcap_test_user_id'] = 7201;
$z2_mv_receipt = (string) $wpdb->get_var( $wpdb->prepare( "SELECT receipt_number FROM {$p}wpcap_boxoffice_movements WHERE refund_id=%d", (int) $z2_part['refund_id'] ) );
$z2_html = ( static function () use ( $z2_session ) { $_GET = array( 'shift_id' => (int) $z2_session->session_id ); ob_start(); try { PassCaptain\BoxOffice\ShiftPage::render_shift(); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); } $_GET = array(); return (string) ob_get_clean(); } )();
check( 'session overview shows the refund (column and list with receipt number)', '' !== $z2_mv_receipt && str_contains( $z2_html, 'Einlagen, Entnahmen und Auszahlungen' ) && str_contains( $z2_html, 'Teilerstattung an der Kasse' ) && str_contains( $z2_html, $z2_mv_receipt ) && ! str_contains( $z2_html, 'ERR ' ) );
$z2_storno = Z2Pos::storno( (int) $z2_cash_sale->sale_id, 'Rest' );
$z2_after2 = Z2Pos::session_totals( $z2_session->session_id );
$z2_storno_amount = ! is_wp_error( $z2_storno ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT amount_minor FROM {$p}wpcap_boxoffice_movements WHERE refund_id=%d", (int) $z2_storno->storno_refund_id ) ) : 0;
check( 'storno after a partial refund voids only the rest: no double deduction (sale back to zero)', ! is_wp_error( $z2_storno ) && 'voided' === $z2_storno->status && -1200 === $z2_storno_amount && 2400 === $z2_after2['methods']['cash']['refund_amount'] && $z2_before['expected_cash'] === $z2_after2['expected_cash'] && $z2_before['methods']['cash']['net'] === $z2_after2['methods']['cash']['net'] );
check( 'the storno refund itself is not booked as a partial refund', 1 === $z2_after2['counts']['refunds'] && 1 === $z2_after2['counts']['stornos'] );

// Refund after the close, requested in the back office: an open payout until a cashier pays it out.
$z2_cash2 = $z2_sell( 'cash', 2 );
$z2_c2t = tickets( $z2_cash2['sale']->order_id );
$z2_closed = Z2Pos::close_session( $z2_session->session_id, Z2Pos::session_totals( $z2_session->session_id )['expected_cash'] );
check( 'session closes with the expected cash incl. the partial refund (difference 0)', ! is_wp_error( $z2_closed ) && 0 === (int) $z2_closed->difference_minor );
$GLOBALS['wpcap_test_user_id'] = 7202;
$z2_next = Z2Pos::open_session( 3000, 'EUR', '', 31, 'Z2-BOX' );
$GLOBALS['wpcap_test_user_id'] = 1;
$z2_late_rf = Refunds::request( (int) $z2_cash2['sale']->order_id, array( (int) $z2_c2t[0]->ticket_id ), 'nach Abschluss', 'z2-pos-late-' . uniqid() );
$z2_late_payout = is_array( $z2_late_rf ) ? PassCaptain\BoxOffice\Shifts::payout_for_refund( (int) $z2_late_rf['refund_id'] ) : null;
$z2_waiting_cash = Z2Pos::session_totals( $z2_next->session_id )['expected_cash'];
$GLOBALS['wpcap_test_user_id'] = 7202;
$z2_paid_out = $z2_late_payout ? PassCaptain\BoxOffice\Shifts::pay_out( (int) $z2_late_payout->payout_id, (int) $z2_next->session_id ) : null;
$GLOBALS['wpcap_test_user_id'] = 1;
$z2_next_totals = Z2Pos::session_totals( $z2_next->session_id );
$z2_closed_totals = Z2Pos::session_totals( $z2_session->session_id );
check( 'refund after the close waits as an open payout and is booked into the paying cashier\'s open shift (expected cash −12.00), the closed session stays as counted', is_array( $z2_late_rf ) && 'completed' === $z2_late_rf['status'] && $z2_late_payout && 'open' === $z2_late_payout->status && 3000 === $z2_waiting_cash && ! is_wp_error( $z2_paid_out ) && is_object( $z2_paid_out ) && 3000 - 1200 === $z2_next_totals['expected_cash'] && 1200 === $z2_next_totals['methods']['cash']['refund_amount'] && (int) $z2_closed->expected_cash_minor === $z2_closed_totals['expected_cash'] && 'paid' === PassCaptain\BoxOffice\Shifts::payout_for_refund( (int) $z2_late_rf['refund_id'] )->status );
$GLOBALS['wpcap_test_user_id'] = 7202;
$z2_next_closed = Z2Pos::close_session( $z2_next->session_id, 1800 );
$GLOBALS['wpcap_test_user_id'] = 1;
$z2_orphan = Refunds::request( (int) $z2_cash2['sale']->order_id, array( (int) $z2_c2t[1]->ticket_id ), 'ohne Sitzung', 'z2-pos-orphan-' . uniqid() );
$z2_orphan_payout = is_array( $z2_orphan ) ? PassCaptain\BoxOffice\Shifts::payout_for_refund( (int) $z2_orphan['refund_id'] ) : null;
check( 'without an open shift the refund waits as an open payout (no shift, no movement) and is audited', ! is_wp_error( $z2_next_closed ) && 0 === (int) $z2_next_closed->difference_minor && is_array( $z2_orphan ) && 'completed' === $z2_orphan['status'] && $z2_orphan_payout && 'open' === $z2_orphan_payout->status && 0 === (int) $z2_orphan_payout->shift_id && 0 === $z2_movements( (int) $z2_orphan['refund_id'] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_payout_open' AND object_id=%d", (int) $z2_orphan['refund_id'] ) ) );
check( 'online orders are not booked into register sessions', -1 === Z2Pos::book_refund( (int) z2_refund_rows( $z2_so )[0]->refund_id ) );

$GLOBALS['wpcap_test_user_id'] = 1;
Settings::update( $z2_settings_before );
Registry::reset();
http_reset();
