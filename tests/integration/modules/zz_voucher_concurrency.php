<?php
/**
 * Voucher snapshot concurrency, voucher reconciliation and invoices after a partial refund:
 * - orders.voucher_snapshot is only changed with a version check / claim (commit, release, refund,
 *   refund_plan); race "hold expiry vs. late payment": the expiry releases the voucher while the late
 *   payment is booked – the voucher ends committed exactly once, never released after a commit.
 * - Reconciliation report (Integrations\Vouchers\ReconciliationReport) with and without Voucher Captain's side (stub).
 * - Invoice issued after a partial refund with a multi-purpose voucher (auto_invoice off): the earlier
 *   refund gets its correction document (cash + voucher part).
 */

// phpcs:ignoreFile

use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as VccCheckout;
use PassCaptain\Integrations\Vouchers\ReconciliationReport as VccRecon;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

/** Stateful stub of Voucher Captain: reservations and redemptions by key, callbacks inside the calls. */
final class VCC_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	/** reservation id => reserved|committed|released */
	public array $state = array();
	/** refund key => amount (each key refunds once) */
	public array $refunds = array();
	public array $errors = array();
	/** One-shot callbacks run inside the provider call: 'release' | 'commit' | 'refund' => callable. */
	public array $during = array();
	public function quote( array $context ) { return array( 'amount_minor' => min( 1500, (int) $context['amount_minor'] ), 'currency' => $context['currency'] ); }
	public function reserve( array $quote, $key ) {
		$this->calls[] = array( 'reserve', $key );
		$id = 'r-' . $key;
		if ( ! isset( $this->state[ $id ] ) ) { $this->state[ $id ] = 'reserved'; }
		return array( 'reservation' => $id, 'amount_minor' => $quote['amount_minor'] );
	}
	public function commit( array $reservation, $key ) {
		$this->calls[] = array( 'commit', $key );
		$this->run( 'commit' );
		$id = (string) ( $reservation['reservation'] ?? '' );
		if ( 'committed' === ( $this->state[ $id ] ?? '' ) ) { return array( 'redemption' => 'd-' . $id ); }
		if ( 'reserved' !== ( $this->state[ $id ] ?? '' ) ) { return new WP_Error( 'vcap_reservation_missing', 'missing', array( 'status' => 409 ) ); }
		$this->state[ $id ] = 'committed';
		return array( 'redemption' => 'd-' . $id );
	}
	public function release( array $reservation, $key ) {
		$this->calls[] = array( 'release', $key );
		$this->run( 'release' );
		$id = (string) ( $reservation['reservation'] ?? '' );
		if ( 'committed' === ( $this->state[ $id ] ?? '' ) ) {
			$this->errors[] = 'release_after_commit:' . $id;
			return new WP_Error( 'vcap_already_committed', 'committed', array( 'status' => 409 ) );
		}
		$this->state[ $id ] = 'released';
		return true;
	}
	public function refund( array $redemption, $amount, $key ) {
		$this->calls[] = array( 'refund', $key );
		$this->run( 'refund' );
		if ( ! isset( $this->refunds[ $key ] ) ) { $this->refunds[ $key ] = (int) $amount; }
		return true;
	}
	private function run( $kind ) {
		if ( empty( $this->during[ $kind ] ) ) { return; }
		$callback = $this->during[ $kind ];
		unset( $this->during[ $kind ] );
		$callback();
	}
	public function count( $kind, $needle ) { return count( array_filter( $this->calls, static fn( $c ) => $kind === $c[0] && false !== strpos( (string) $c[1], $needle ) ) ); }
	public function committed() { return count( array_filter( $this->state, static fn( $s ) => 'committed' === $s ) ); }
}

$vcc_previous_filter = $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ?? null;
$vcc = new VCC_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $vcc;
$vcc_settings = array();
foreach ( array( 'late_payment_auto_refund', 'voucher_default_purpose', 'auto_invoice', 'refund_approval_threshold', 'seller_name' ) as $vcc_key ) { $vcc_settings[ $vcc_key ] = Settings::get( $vcc_key ); }
Settings::update( array( 'late_payment_auto_refund' => 0, 'voucher_default_purpose' => 'multi', 'auto_invoice' => 0, 'refund_approval_threshold' => 0, 'seller_name' => 'Kulturverein' ) );
$vcc_hooks = array(
	array( 'wpcap_order_paid', array( VccCheckout::class, 'commit' ) ),
	array( 'wpcap_order_cancelled', array( VccCheckout::class, 'release' ) ),
	array( 'wpcap_before_payment_confirm', array( VccCheckout::class, 'prepare_late_payment' ) ),
	array( 'wpcap_order_recovering', array( VccCheckout::class, 'recovering' ) ),
	array( 'wpcap_capacity_released', array( VccCheckout::class, 'on_capacity_released' ) ),
);
foreach ( $vcc_hooks as $vcc_hook ) { add_filter( $vcc_hook[0], $vcc_hook[1], 10, 4 ); }

function vcc_order( $type, $email, $code = 'VCC-CODE-0001' ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$o = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'vcc-' . uniqid() ), array( 'payment_provider' => 'manual', 'hold_minutes' => 30 ) );
	VccCheckout::apply( $o['order_id'], $code );
	return $o;
}
function vcc_snap( $order_id ) { return (array) VccCheckout::voucher_state( $order_id ); }
function vcc_job( $order_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT job_id,status FROM {$wpdb->prefix}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'voucher-commit-' . (int) $order_id ) ) );
}

$vcc_type = make_type( 50, 10, 1000 );

echo "Voucher snapshot: version check\n";
$v = vcc_order( $vcc_type, 'vcc-cas@example.com' );
$vcc_swap = new ReflectionMethod( VccCheckout::class, 'swap' );
$vcc_raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT voucher_snapshot FROM {$p}wpcap_orders WHERE order_id=%d", $v['order_id'] ) );
$vcc_first = json_decode( $vcc_raw, true );
$vcc_second = $vcc_first;
$vcc_first['note'] = 'first';
$vcc_second['note'] = 'second';
$vcc_ok1 = $vcc_swap->invokeArgs( null, array( $v['order_id'], &$vcc_first, $vcc_raw ) );
$vcc_ok2 = $vcc_swap->invokeArgs( null, array( $v['order_id'], &$vcc_second, $vcc_raw ) );
check( 'VCC: a snapshot write based on a stale read is refused (version check)', true === $vcc_ok1 && false === $vcc_ok2 && 'first' === ( vcc_snap( $v['order_id'] )['note'] ?? '' ) && (int) vcc_snap( $v['order_id'] )['rev'] >= 1 );
check( 'VCC: the snapshot is no longer written without a version check', ! preg_match( '/->update\(\s*\$wpdb->prefix \. \'wpcap_orders\', array\( \'voucher_snapshot\'/', (string) file_get_contents( WPCAP_PATH . 'src/Integrations/Vouchers/Checkout.php' ) ) );

echo "Voucher snapshot: race hold expiry vs. late payment\n";
$a = vcc_order( $vcc_type, 'vcc-race@example.com' );
expire_holds_of( $a['order_id'] );
// The late payment arrives while the expiry is releasing the voucher (inside the provider's release call).
$vcc_late = null;
$vcc->during['release'] = static function () use ( $a, &$vcc_late ) { $vcc_late = Orders::confirm_external_payment( $a['order_id'], 'late-transfer' ); };
Orders::expire_holds( 200, array( $a['order_id'] ) );
$a_row = Orders::get( $a['order_id'] );
$a_snap = vcc_snap( $a['order_id'] );
$a_job = vcc_job( $a['order_id'] );
check( 'VCC race: the late payment is booked and issues the tickets', true === $vcc_late && 'paid' === $a_row->payment_status && 2 === count( tickets( $a['order_id'], 'valid' ) ) );
check( 'VCC race: the commit waits for the running release (no commit during the release, retry queued)', 0 === $vcc->count( 'commit', $a['public_id'] ) && 1 === $vcc->count( 'release', $a['public_id'] ) && $a_job && 'released' === ( $a_snap['status'] ?? '' ) && empty( $a_snap['claim'] ) );
$a_done = VccCheckout::handle_job( false, VccCheckout::JOB, array( 'order_id' => $a['order_id'] ), $a_job ? (int) $a_job->job_id : 0 );
$a_snap = vcc_snap( $a['order_id'] );
check( 'VCC race: the retry reserves the voucher again and commits it exactly once', true === $a_done && 'committed' === ( $a_snap['status'] ?? '' ) && ! empty( $a_snap['late_reservation'] ) && 1 === $vcc->count( 'commit', $a['public_id'] ) && 'committed' === ( $vcc->state[ 'r-wpcap-order-late-' . $a['public_id'] ] ?? '' ) && 'released' === ( $vcc->state[ 'r-wpcap-order-' . $a['public_id'] ] ?? '' ) );
VccCheckout::release( $a['order_id'] );
VccCheckout::commit( $a['order_id'] );
check( 'VCC race: repeated expiry/payment hooks change nothing (no double release, no double commit)', 1 === $vcc->count( 'release', $a['public_id'] ) && 1 === $vcc->count( 'commit', $a['public_id'] ) && 'committed' === vcc_snap( $a['order_id'] )['status'] && ! $vcc->errors );

echo "Voucher snapshot: commit and release never overlap\n";
$b = vcc_order( $vcc_type, 'vcc-overlap@example.com' );
$vcc_inner = array();
// While the payment's commit runs: the expiry hook and a second commit (retry job) arrive.
$vcc->during['commit'] = static function () use ( $b, &$vcc_inner ) {
	VccCheckout::release( $b['order_id'] );
	$vcc_inner[] = VccCheckout::commit( $b['order_id'] );
};
Orders::confirm_manual_payment( $b['order_id'] );
$b_snap = vcc_snap( $b['order_id'] );
check( 'VCC: a release or a second commit during a running commit does nothing', array( false ) === $vcc_inner && 0 === $vcc->count( 'release', $b['public_id'] ) && 1 === $vcc->count( 'commit', $b['public_id'] ) && 'committed' === ( $b_snap['status'] ?? '' ) && empty( $b_snap['claim'] ) && ! $vcc->errors );
$b_job = vcc_job( $b['order_id'] );
$b_done = VccCheckout::handle_job( false, VccCheckout::JOB, array( 'order_id' => $b['order_id'] ), $b_job ? (int) $b_job->job_id : 0 );
check( 'VCC: the queued retry finds the voucher committed and does nothing', true === $b_done && 1 === $vcc->count( 'commit', $b['public_id'] ) );

// An abandoned claim (crashed request) does not block for good.
$c = vcc_order( $vcc_type, 'vcc-stale@example.com' );
$c_snap = vcc_snap( $c['order_id'] );
$c_snap['claim'] = array( 'op' => 'release', 'token' => 'abandoned', 'at' => time() - 10 * VccCheckout::CLAIM_TTL );
$wpdb->update( "{$p}wpcap_orders", array( 'voucher_snapshot' => wp_json_encode( $c_snap ) ), array( 'order_id' => $c['order_id'] ) );
Orders::confirm_manual_payment( $c['order_id'] );
check( 'VCC: a claim older than CLAIM_TTL is taken over', 'committed' === vcc_snap( $c['order_id'] )['status'] && 1 === $vcc->count( 'commit', $c['public_id'] ) );

echo "Voucher snapshot: parallel refund runs book the voucher share once\n";
$b_tickets = tickets( $b['order_id'] );
$b_refund = Refunds::request( $b['order_id'], array( (int) $b_tickets[0]->ticket_id ), 'Storno', 'vcc-b-r1-' . uniqid() );
$vcc_nested = null;
$vcc->during['refund'] = static function () use ( $b, $b_refund, &$vcc_nested ) { $vcc_nested = VccCheckout::refund( $b_refund['refund_id'], $b['order_id'], true ); };
$b_outer = VccCheckout::refund( $b_refund['refund_id'], $b['order_id'] );
$b_snap = vcc_snap( $b['order_id'] );
$b_plan = VccCheckout::refund_plan( $b_refund['refund_id'] );
check( 'VCC: hook and retry job refunding at the same time book the share once', true === $b_outer && true === $vcc_nested && 750 === (int) ( $b_plan['voucher_minor'] ?? 0 ) && 750 === (int) ( $b_snap['refunded_minor'] ?? 0 ) && array( (int) $b_refund['refund_id'] ) === array_map( 'intval', (array) $b_snap['refund_ids'] ) && 1 === (int) $b_snap['refunded_tickets'] && array( 'wpcap-refund-' . $b_refund['refund_id'] => 750 ) === $vcc->refunds );

echo "Voucher reconciliation report\n";
// Mismatch candidates on Pass Captain's side.
$d = vcc_order( $vcc_type, 'vcc-recon-released@example.com' );
Orders::confirm_manual_payment( $d['order_id'] );
$d_snap = vcc_snap( $d['order_id'] );
$d_snap['status'] = 'released';
$wpdb->update( "{$p}wpcap_orders", array( 'voucher_snapshot' => wp_json_encode( $d_snap ) ), array( 'order_id' => $d['order_id'] ) );
$e = vcc_order( $vcc_type, 'vcc-recon-failed@example.com' );
Orders::confirm_manual_payment( $e['order_id'] );
$e_snap = vcc_snap( $e['order_id'] );
$e_snap['failed_refund_ids'] = array( 999001 );
$wpdb->update( "{$p}wpcap_orders", array( 'voucher_snapshot' => wp_json_encode( $e_snap ) ), array( 'order_id' => $e['order_id'] ) );
$f = vcc_order( $vcc_type, 'vcc-recon-open@example.com' );
Orders::confirm_manual_payment( $f['order_id'] );
$f_tickets = tickets( $f['order_id'] );
$f_refund = Refunds::request( $f['order_id'], array( (int) $f_tickets[0]->ticket_id ), 'Storno', 'vcc-f-r1-' . uniqid() ); // Voucher share not refunded (hook not run).
$g = vcc_order( $vcc_type, 'vcc-recon-reserved@example.com' );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_status' => 'paid', 'status' => 'complete' ), array( 'order_id' => $g['order_id'] ) ); // Paid, commit never ran.
PassCaptain\CapacityCounters::sync_order_now( $g['order_id'] );

$vcc_mine = static function ( array $report ) use ( $a, $b, $d, $e, $f, $g ) {
	$ids = array( $a['order_id'], $b['order_id'], $d['order_id'], $e['order_id'], $f['order_id'], $g['order_id'] );
	$out = array();
	foreach ( $report['issues'] as $issue ) {
		if ( in_array( $issue['order_id'], $ids, true ) ) { $out[ $issue['order_id'] ][] = $issue['code']; }
	}
	return $out;
};
$r1 = VccRecon::report( array( 'limit' => 100000 ) );
$m1 = $vcc_mine( $r1 );
check( 'VCC recon: without Voucher Captain data only Pass Captain\'s side is checked', false === $r1['provider'] && ! array_filter( $r1['issues'], static fn( $i ) => 0 === strpos( $i['code'], 'provider_' ) ) );
check( 'VCC recon: paid order with a released voucher', array( 'paid_released' ) === ( $m1[ $d['order_id'] ] ?? null ) );
check( 'VCC recon: failed voucher reversal', array( 'reversal_failed' ) === ( $m1[ $e['order_id'] ] ?? null ) );
check( 'VCC recon: completed refund whose voucher share is still open', ! is_wp_error( $f_refund ) && array( 'reversal_open' ) === ( $m1[ $f['order_id'] ] ?? null ) );
check( 'VCC recon: paid order whose voucher is only reserved', array( 'reserved_paid' ) === ( $m1[ $g['order_id'] ] ?? null ) );
check( 'VCC recon: consistent orders (race, overlap, booked refund) have no findings', ! isset( $m1[ $a['order_id'] ] ) && ! isset( $m1[ $b['order_id'] ] ) );
check( 'VCC recon: summary counts per snapshot status', ( $r1['summary']['committed']['count'] ?? 0 ) >= 4 && ( $r1['summary']['released']['count'] ?? 0 ) >= 1 && ( $r1['summary']['committed']['refunded_minor'] ?? 0 ) >= 750 );
check( 'VCC recon: the report changes nothing', 'released' === vcc_snap( $d['order_id'] )['status'] && 'reserved' === vcc_snap( $g['order_id'] )['status'] );

// Voucher Captain's side through the optional filter (stub).
$vcc_states = static function ( $states, $redemptions ) use ( $a, $b, $d ) {
	$out = array();
	foreach ( $redemptions as $order_id => $r ) { $out[ $order_id ] = array( 'status' => $r['status'], 'refunded_minor' => 0 ); }
	$out[ $a['order_id'] ] = array( 'status' => 'released' );                        // PC committed, VC released.
	$out[ $b['order_id'] ] = array( 'status' => 'partially_refunded', 'refunded_minor' => 500 ); // Amount differs.
	unset( $out[ $d['order_id'] ] );                                                // Released: missing is fine.
	return $out;
};
add_filter( 'wpcap_voucher_reconcile_states', $vcc_states, 10, 2 );
$r2 = VccRecon::report( array( 'limit' => 100000 ) );
$m2 = $vcc_mine( $r2 );
remove_filter( 'wpcap_voucher_reconcile_states', $vcc_states, 10 );
check( 'VCC recon: with Voucher Captain data the states are compared', true === $r2['provider'] && array( 'provider_status' ) === ( $m2[ $a['order_id'] ] ?? null ) && array( 'provider_refunded' ) === ( $m2[ $b['order_id'] ] ?? null ) && array( 'paid_released' ) === ( $m2[ $d['order_id'] ] ?? null ) );
$vcc_missing = VccRecon::provider_finding( array( 'status' => 'committed', 'amount_minor' => 100 ), null );
check( 'VCC recon: a redemption Voucher Captain does not know is flagged, a charged one after commit_failed too', 'provider_missing' === ( $vcc_missing[0] ?? '' ) && 'provider_status' === ( VccRecon::provider_finding( array( 'status' => 'commit_failed', 'amount_minor' => 100 ), array( 'status' => 'committed' ) )[0] ?? '' ) && null === VccRecon::provider_finding( array( 'status' => 'released', 'amount_minor' => 100 ), null ) );

// Placed in the existing "Betrieb" tab of the system status screen, run on request.
$_GET['wpcap_voucher_recon'] = '1';
ob_start();
PassCaptain\Admin\OperationsPage::render_voucher_reconciliation();
$vcc_html = (string) ob_get_clean();
unset( $_GET['wpcap_voucher_recon'] );
ob_start();
PassCaptain\Admin\OperationsPage::render_voucher_reconciliation();
$vcc_idle = (string) ob_get_clean();
check( 'VCC recon: section "Gutschein-Abgleich" in the Betrieb tab lists the findings', false !== strpos( $vcc_html, 'id="wpcap-voucher-recon"' ) && false !== strpos( $vcc_html, 'data-code="paid_released"' ) && false !== strpos( $vcc_html, 'nicht verfügbar' ) && false !== strpos( $vcc_idle, 'Abgleich ausführen' ) && false === strpos( $vcc_idle, 'data-code=' ) );
check( 'VCC recon: the Betrieb tab calls the section', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/OperationsPage.php' ), 'self::render_voucher_reconciliation();' ) );

echo "Invoice after a partial refund with a multi-purpose voucher (auto_invoice off)\n";
$h_type = make_type( 20, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=$h_type" );
$GLOBALS['wpcap_test_transients'] = array();
$h = Orders::create( array( 'email' => 'vcc-invoice@example.com', 'items' => array( array( 'ticket_type_id' => $h_type, 'quantity' => 3 ) ), 'idempotency_key' => 'vcc-inv-' . uniqid() ), array( 'payment_provider' => 'manual' ) );
VccCheckout::apply( $h['order_id'], 'VCC-INV-1234' );
Orders::confirm_manual_payment( $h['order_id'] );
$h_tickets = tickets( $h['order_id'] );
$h_refund = Refunds::request( $h['order_id'], array( (int) $h_tickets[0]->ticket_id ), 'Storno', 'vcc-h-r1-' . uniqid() );
VccCheckout::refund( $h_refund['refund_id'], $h['order_id'] );
$h_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_invoices WHERE order_id=%d", $h['order_id'] ) );
$h_inv = Invoices::issue_for_order( $h['order_id'] );
$h_inv_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoices WHERE order_id=%d", $h['order_id'] ) );
$h_cn = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", (int) $h_refund['refund_id'] ) );
check( 'VCC invoice: no invoice before (auto_invoice off), the refund was cash 5,00 + voucher 5,00', 0 === $h_before && 'completed' === $h_refund['status'] && 500 === (int) $h_refund['amount_minor'] && 500 === (int) ( VccCheckout::refund_plan( $h_refund['refund_id'] )['voucher_minor'] ?? 0 ) );
check( 'VCC invoice: the later invoice shows the full consideration and the earlier refund gets its correction (cash + voucher part, full VAT)', is_array( $h_inv ) && 3000 === (int) $h_inv_row->total_minor && 500 === (int) $h_inv_row->tax_minor && $h_cn && 1000 === (int) $h_cn->total_minor && 167 === (int) $h_cn->tax_minor && (int) $h_cn->invoice_id === (int) $h_inv_row->invoice_id );
check( 'VCC invoice: invoice minus corrections = consideration after the refund (20,00)', 2000 === (int) $h_inv_row->total_minor - (int) $h_cn->total_minor );

Settings::update( $vcc_settings );
if ( null === $vcc_previous_filter ) { unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ); } else { $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $vcc_previous_filter; }
foreach ( $vcc_hooks as $vcc_hook ) { remove_filter( $vcc_hook[0], $vcc_hook[1], 10 ); }
