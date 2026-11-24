<?php
/**
 * Wallet passes for tickets issued before the wallet module was switched on (TODO P1 Wallet, "Pässe für
 * Tickets, die vor dem Einschalten des Moduls ausgestellt wurden"): Service::backfill() creates them with the
 * stored current code (TicketCodes, A586) – no new code, no mail – and the order portal shows them.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\TicketCodes;
use PassCaptain\WalletPasses\AdminPage;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "Wallet: passes for tickets issued before the module was switched on\n";
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
// TicketCodes is registered by Plugin::boot(), which the test runtime does not run (as in zz_a586).
$p5w_tc_registered = false;
foreach ( $GLOBALS['wpcap_test_hooks']['wpcap_ticket_mail'] ?? array() as $p5w_cbs ) { foreach ( $p5w_cbs as $p5w_cb ) { $p5w_tc_registered = $p5w_tc_registered || array( TicketCodes::class, 'capture' ) === $p5w_cb[0]; } }
if ( ! $p5w_tc_registered ) { TicketCodes::register(); }

$p5w_run = static function () {
	$total = array( 'queued' => 0, 'skipped' => 0, 'batches' => 0 );
	$after = 0;
	do {
		$r = Service::backfill( $after );
		if ( is_wp_error( $r ) ) { return $r; }
		$total['queued'] += $r['queued'];
		$total['skipped'] += $r['skipped'];
		++$total['batches'];
		$after = $r['last_id'];
	} while ( $r['more'] && $total['batches'] < 1000 );
	return $total;
};
$p5w_state_count = static function ( array $ids ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_state WHERE ticket_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
};

// Tickets issued while the module is off.
wpr7_wallet_disable();
$p5w_type = make_type( 100, 100, 0 );
wpcap_test_reset_transients();
$p5w_order = Orders::create( array( 'email' => 'p5w@example.com', 'items' => array( array( 'ticket_type_id' => $p5w_type, 'quantity' => 3 ) ), 'idempotency_key' => 'p5w-' . uniqid() ) );
$p5w_tickets = is_array( $p5w_order ) ? Orders::tickets_for_order( (int) $p5w_order['order_id'] ) : array();
$p5w_ids = array_map( static fn( $t ) => (int) $t->ticket_id, $p5w_tickets );
check( 'P5-W1: three tickets issued without wallet passes, each with a stored code', 3 === count( $p5w_ids ) && 0 === $p5w_state_count( $p5w_ids ) && 3 === count( array_filter( $p5w_ids, static fn( $id ) => '' !== TicketCodes::get( $id ) ) ) );
check( 'P5-W2: without a configured provider nothing is created (error)', is_wp_error( Service::backfill() ) );

// One ticket lost its stored code, one is revoked.
$wpdb->update( "{$p}wpcap_tickets", array( 'code_secret' => null ), array( 'ticket_id' => $p5w_ids[2] ) );
$p5w_revoked_type = make_type( 100, 100, 0 );
wpcap_test_reset_transients();
$p5w_rev_order = Orders::create( array( 'email' => 'p5w-rev@example.com', 'items' => array( array( 'ticket_type_id' => $p5w_revoked_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p5w-rev-' . uniqid() ) );
$p5w_rev_id = (int) ( Orders::tickets_for_order( (int) $p5w_rev_order['order_id'] )[0]->ticket_id ?? 0 );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => $p5w_rev_id ) );

$p5w_hashes = $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $p5w_ids ) . ') ORDER BY ticket_id' );
$p5w_codes = array( TicketCodes::get( $p5w_ids[0] ), TicketCodes::get( $p5w_ids[1] ) );
wpr7_wallet_enable();
$p5w_without = Service::backfill_without_code();
check( 'P5-W3: the admin page counts tickets without a stored code (they need "Tickets erneut senden")', $p5w_without >= 1 );
$GLOBALS['wpcap_test_mails'] = array();
$p5w_result = $p5w_run();
check( 'P5-W4: backfill stores wallet state for the tickets with a stored code', ! is_wp_error( $p5w_result ) && $p5w_result['queued'] >= 2 && null !== Store::state( $p5w_ids[0] ) && null !== Store::state( $p5w_ids[1] ) );
check( 'P5-W5: tickets without a stored code and revoked tickets are left alone', null === Store::state( $p5w_ids[2] ) && null === Store::state( $p5w_rev_id ) );
$p5w_hashes_after = $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $p5w_ids ) . ') ORDER BY ticket_id' );
$p5w_ticket = Service::ticket( $p5w_ids[0] );
check( 'P5-W6: no new code – the hashes are unchanged and the pass carries the mailed code', $p5w_hashes === $p5w_hashes_after && is_array( $p5w_ticket ) && $p5w_codes[0] === $p5w_ticket['code'] );
check( 'P5-W7: no mail goes to the buyer', ! array_filter( (array) ( $GLOBALS['wpcap_test_mails'] ?? array() ), static fn( $m ) => false !== strpos( (string) ( $m['to'] ?? '' ), 'p5w@example.com' ) ) );

// The pass rows follow through the issue job (like batch print).
$p5w_jobs = $wpdb->get_results( $wpdb->prepare( "SELECT job_id,payload FROM {$p}wpcap_jobs WHERE job_type=%s AND status='queued'", Service::ISSUE_JOB ) );
$p5w_job_ids = array();
foreach ( $p5w_jobs as $p5w_job ) { $p5w_job_ids = array_merge( $p5w_job_ids, (array) ( json_decode( $p5w_job->payload, true )['ticket_ids'] ?? array() ) ); }
check( 'P5-W8: the pass rows are queued as wallet_issue jobs', in_array( $p5w_ids[0], array_map( 'intval', $p5w_job_ids ), true ) && in_array( $p5w_ids[1], array_map( 'intval', $p5w_job_ids ), true ) );
foreach ( $p5w_jobs as $p5w_job ) {
	Service::handle_job( false, Service::ISSUE_JOB, json_decode( $p5w_job->payload, true ) );
	$wpdb->update( "{$p}wpcap_jobs", array( 'status' => 'complete' ), array( 'job_id' => (int) $p5w_job->job_id ) );
}
check( 'P5-W9: after the job the tickets have Apple and Google pass rows', Store::passes( $p5w_ids[0], 'apple' ) && Store::passes( $p5w_ids[1], 'google' ) );
$p5w_order_row = Orders::get( (int) $p5w_order['order_id'] );
$p5w_portal = Service::portal_section( '', $p5w_order_row );
check( 'P5-W10: the order portal now shows the wallet buttons of the earlier tickets', 2 === substr_count( $p5w_portal, 'wpcap-wallet-apple' ) && 2 === substr_count( $p5w_portal, 'wpcap-wallet-google' ) );

$p5w_again = $p5w_run();
check( 'P5-W11: a second run finds nothing new for these tickets (idempotent)', ! is_wp_error( $p5w_again ) && 1 === count( Store::passes( $p5w_ids[0], 'apple' ) ) && null === Store::state( $p5w_ids[2] ) );

// Further batches go through the job queue.
check( 'P5-W12: the job type continues a batch and is handled', true === Service::handle_job( false, Service::BACKFILL_JOB, array( 'after' => 0 ) ) );

// Admin action.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$p5w_denied = AdminPage::backfill();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'P5-W13: the admin action needs manage_options', is_wp_error( $p5w_denied ) && 403 === ( $p5w_denied->get_error_data()['status'] ?? 0 ) );
$p5w_admin = AdminPage::backfill();
check( 'P5-W14: the admin action runs a batch and is audited', is_array( $p5w_admin ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='wallet_backfill_started'" ) >= 1 );
check( 'P5-W15: the notice names the queued, following and skipped tickets', false !== strpos( AdminPage::backfill_message( array( 'queued' => 2, 'skipped' => 1, 'more' => true, 'last_id' => 9 ) ), '2 bestehende Tickets' ) && false !== strpos( AdminPage::backfill_message( array( 'queued' => 2, 'skipped' => 1, 'more' => true, 'last_id' => 9 ) ), 'Hintergrund' ) && false !== strpos( AdminPage::backfill_message( array( 'queued' => 1, 'skipped' => 1, 'more' => false, 'last_id' => 9 ) ), 'nicht lesbar' ) );
check( 'P5-W16: the admin-post handler is registered', isset( \PassCaptain\Modules\Wallet::admin_handlers()['wpcap_wallet_backfill'] ) );
ob_start();
AdminPage::render();
$p5w_html = (string) ob_get_clean();
check( 'P5-W17: the Wallet page offers the button and explains tickets without a stored code', false !== strpos( $p5w_html, 'wpcap_wallet_backfill' ) && false !== strpos( $p5w_html, 'Pässe für bestehende Tickets erzeugen' ) && false !== strpos( $p5w_html, 'keinen gespeicherten Code' ) );
wpr7_wallet_disable();
if ( ! $p5w_tc_registered ) {
	remove_filter( 'wpcap_ticket_mail', array( TicketCodes::class, 'capture' ), 0 );
	remove_action( 'wpcap_ticket_code_rotated', array( TicketCodes::class, 'remember' ), 5 );
	remove_action( 'wpcap_ticket_code_issued', array( TicketCodes::class, 'remember' ), 5 );
	remove_action( 'wpcap_ticket_revoked', array( TicketCodes::class, 'forget' ), 5 );
	remove_filter( 'wpcap_ticket_known_code', array( TicketCodes::class, 'known_code' ), 5 );
}
