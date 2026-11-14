<?php
/**
 * A5: the core plugin works alone – without any add-on and without network access.
 *
 * Setup:
 * - "No add-on": only hook callbacks implemented in src/ stay registered (every callback a test or
 *   an add-on registered is removed for the duration of this test), test filter overrides are
 *   cleared, and the add-on contracts answer with their defaults.
 * - "No network": a pre_http_request filter blocks every outbound request (WP_Error) and records
 *   it; the test fails if any request is attempted (licence server or any other host).
 * - Leftover queue rows of earlier tests (jobs, messages, webhook deliveries, SMS) are parked, and
 *   webhook subscriptions of earlier tests are disabled, so the cron runs below only see what these
 *   core flows create – like a fresh site. Everything is restored afterwards.
 *
 * Flows: event create (admin), free checkout, checkout with manual payment + confirmation, ticket
 * issue and mail, check-in, manual refund, cron (jobs, messages), reports + CSV, privacy export.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Checkin;
use PassCaptain\Communications;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\Refunds;
use PassCaptain\Reports\Data;

global $wpdb;
$p = $wpdb->prefix;
echo "A5: core works alone (no add-on, no network)\n";

/* ---- isolate: core callbacks only ---- */
$a5_src = realpath( WPCAP_PATH . 'src' ) . DIRECTORY_SEPARATOR;
$a5_is_core = static function ( $cb ) use ( $a5_src ) {
	try {
		if ( is_array( $cb ) && 2 === count( $cb ) ) {
			$r = new ReflectionMethod( $cb[0], $cb[1] );
		} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
			$r = new ReflectionMethod( $cb );
		} elseif ( $cb instanceof Closure || is_string( $cb ) ) {
			$r = new ReflectionFunction( $cb );
		} elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
			$r = new ReflectionMethod( $cb, '__invoke' );
		} else {
			return false;
		}
		$file = $r->getFileName();
		return $file && 0 === strpos( (string) realpath( $file ), $a5_src );
	} catch ( Throwable $e ) {
		return false;
	}
};
$a5_saved_hooks = $GLOBALS['wpcap_test_hooks'] ?? array();
$a5_saved_overrides = $GLOBALS['wpcap_test_filters'] ?? array();
$GLOBALS['wpcap_test_filters'] = array();
$a5_removed = 0;
foreach ( $GLOBALS['wpcap_test_hooks'] as $hook => $priorities ) {
	foreach ( $priorities as $priority => $callbacks ) {
		foreach ( $callbacks as $i => $entry ) {
			if ( ! $a5_is_core( $entry[0] ) ) {
				unset( $GLOBALS['wpcap_test_hooks'][ $hook ][ $priority ][ $i ] );
				++$a5_removed;
			}
		}
	}
}
$a5_foreign = 0;
foreach ( $GLOBALS['wpcap_test_hooks'] as $priorities ) { foreach ( $priorities as $callbacks ) { foreach ( $callbacks as $entry ) { if ( ! $a5_is_core( $entry[0] ) ) { ++$a5_foreign; } } } }
check( "isolation: only core callbacks remain ($a5_removed test/add-on callbacks removed)", 0 === $a5_foreign );
PassCaptain\Payments\Registry::reset();
$a5_core_gateways = array( 'manual', 'stripe', 'paypal', 'mollie' );
$a5_gateways = array_keys( PassCaptain\Payments\Registry::all() );
check( 'no add-on: module list is the core list, no foreign gateways, connectors or providers', array() === array_filter( PassCaptain\Modules::classes(), static fn( $c ) => 0 !== strpos( $c, 'PassCaptain\\Modules\\' ) ) && array() === array_filter( $a5_gateways, static fn( $g ) => ! in_array( $g, $a5_core_gateways, true ) && 0 !== strpos( $g, 'pos_' ) ) && array() === apply_filters( 'wp_pass_captain_connectors_v1', array() ) && null === apply_filters( 'wp_pass_captain_voucher_provider_v1', null, 1 ) && array() === apply_filters( 'wp_pass_captain_scanner_adapters_v1', array() ) );

/* ---- block the network ---- */
$a5_http = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$a5_http ) {
	$a5_http[] = (string) $url;
	return new WP_Error( 'a5_network_blocked', 'Outbound HTTP is blocked in the core-alone test: ' . $url );
}, 1, 3 );
$a5_http_fake_before = count( $GLOBALS['wpcap_test_http_requests'] ?? array() );
$a5_licence_options_before = array_filter( array_keys( $GLOBALS['wpcap_test_options'] ), static fn( $k ) => (bool) preg_match( '/licen[cs]e|wplc|entitlement/i', (string) $k ) );

/* ---- park leftovers of earlier tests ---- */
$a5_park = array(
	"{$p}wpcap_jobs"              => array( 'run_after', "status='queued'" ),
	"{$p}wpcap_message_queue"     => array( 'send_at', "status='queued'" ),
	"{$p}wpcap_webhook_deliveries" => array( 'next_attempt_at', "status IN ('pending','retry','failed')" ),
	"{$p}wpcap_sms_messages"      => array( 'send_at', "status='queued'" ),
);
$a5_parked = array();
foreach ( $a5_park as $table => list( $col, $where ) ) {
	if ( null === $wpdb->get_var( "SHOW TABLES LIKE '" . addcslashes( $table, '_' ) . "'" ) ) { continue; }
	$pk = $wpdb->get_var( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_KEY='PRI' LIMIT 1" );
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT $pk FROM $table WHERE $where" ) );
	if ( $ids ) { $wpdb->query( "UPDATE $table SET $col=DATE_ADD($col, INTERVAL 50 YEAR) WHERE $pk IN (" . implode( ',', $ids ) . ')' ); }
	$a5_parked[ $table ] = array( $pk, $col, $ids );
}
$a5_subs = array();
if ( null !== $wpdb->get_var( "SHOW TABLES LIKE '" . addcslashes( "{$p}wpcap_webhook_subscriptions", '_' ) . "'" ) ) {
	$a5_subs = array_map( 'intval', $wpdb->get_col( "SELECT subscription_id FROM {$p}wpcap_webhook_subscriptions WHERE status='active'" ) );
	if ( $a5_subs ) { $wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='disabled' WHERE subscription_id IN (" . implode( ',', $a5_subs ) . ')' ); }
}

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0, 'require_terms' => 0, 'enabled_providers' => array( 'manual' ) ) );
$a5_mails_before = count( $GLOBALS['wpcap_test_mails'] );
$a5_errors = array();
set_error_handler( static function ( $no, $str, $file, $line ) use ( &$a5_errors ) { $a5_errors[] = "$str ($file:$line)"; return false; }, E_WARNING | E_NOTICE | E_USER_WARNING | E_DEPRECATED );

/* ---- flows ---- */
try {
	$future = gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS );
	$event = EventsPage::create_event( array( 'name' => 'A5 Konzert', 'description' => 'Ohne Add-on', 'starts_at' => $future . 'T19:30', 'ends_at' => $future . 'T22:00', 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'ticket_name' => 'Stehplatz', 'price' => '20,00', 'currency' => 'EUR', 'tax_rate' => '10', 'ticket_capacity' => '50', 'status' => 'published' ) );
	check( 'event create (admin) works', ! is_wp_error( $event ) && ! empty( $event['event_id'] ) );
	$event_id = (int) ( $event['event_id'] ?? 0 );
	$paid_type = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tt.ticket_type_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d ORDER BY tt.ticket_type_id LIMIT 1", $event_id ) );
	$occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $paid_type ) );
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Freikarte', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 4, 'status' => 'active' ) );
	$free_type = (int) $wpdb->insert_id;

	$free = Orders::create( array( 'email' => 'a5-free@example.com', 'items' => array( array( 'ticket_type_id' => $free_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a5-free-' . uniqid() ) );
	check( 'free checkout issues tickets immediately', ! is_wp_error( $free ) && 2 === count( array_filter( Orders::tickets_for_order( $free['order_id'] ), static fn( $t ) => 'valid' === $t->status ) ) );

	$GLOBALS['wpcap_test_transients'] = array();
	$paid = Orders::create( array( 'email' => 'a5-paid@example.com', 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a5-paid-' . uniqid(), 'payment_provider' => 'manual' ) );
	check( 'checkout with manual payment creates a pending order with a hold', ! is_wp_error( $paid ) && 'pending' === $paid['payment_status'] );
	$mails = count( $GLOBALS['wpcap_test_mails'] );
	$confirmed = Orders::confirm_manual_payment( $paid['order_id'] );
	$order = Orders::get( $paid['order_id'] );
	$ticket_mail = null;
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $mails ) as $m ) { if ( 'a5-paid@example.com' === $m['to'] && preg_match( '/wpcap_ticket=([a-f0-9]{64})/', $m['body'], $mm ) ) { $ticket_mail = $mm[1]; } }
	check( 'manual payment confirmation marks the order paid and issues tickets', ! is_wp_error( $confirmed ) && 'paid' === $order->payment_status && 2 === count( Orders::tickets_for_order( $paid['order_id'] ) ) );
	check( 'ticket mail with ticket codes is sent locally', null !== $ticket_mail );

	$checkin = Checkin::perform( (string) $ticket_mail, array( 'idempotency_key' => 'a5-scan-' . uniqid(), 'source' => 'web' ) );
	check( 'check-in works', is_array( $checkin ) && 'checked_in' === $checkin['status'] );

	$refund = Refunds::request( $paid['order_id'], array(), 'A5 manuelle Erstattung', 'a5-refund-' . uniqid() );
	check( 'manual refund completes and refunds the order', is_array( $refund ) && 'completed' === $refund['status'] && 'refunded' === Orders::get( $paid['order_id'] )->payment_status );

	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE status='queued' AND run_after < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 YEAR)" );
	Jobs::process_due( 50 );
	Communications::process_due();
	do_action( 'wpcap_process_jobs' );
	do_action( 'wpcap_process_messages' );
	do_action( 'wpcap_cleanup_reservations' );
	check( 'cron runs (jobs, messages, cleanup) finish without network', true );

	$today = gmdate( 'Y-m-d' );
	$report = Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $event_id ), null );
	$csv = PassCaptain\Reports\Scope::csv( Data::csv_rows( $report ) );
	check( 'reports and CSV export work', is_array( $report ) && 4 === (int) $report['total']['tickets'] && false !== strpos( $csv, 'A5 Konzert' ) );

	$export = Privacy::export( 'a5-paid@example.com' );
	check( 'privacy export works', is_array( $export ) && ! empty( $export['data'] ) && true === $export['done'] );
} finally {
	restore_error_handler();
}

check( 'no PHP warnings or notices during the core flows' . ( $a5_errors ? ': ' . implode( ' | ', array_slice( $a5_errors, 0, 3 ) ) : '' ), array() === $a5_errors );
check( 'no outbound HTTP request was attempted (licence server or any host)' . ( $a5_http ? ': ' . implode( ', ', array_unique( $a5_http ) ) : '' ), array() === $a5_http && count( $GLOBALS['wpcap_test_http_requests'] ?? array() ) === $a5_http_fake_before );
$a5_licence_options = array_filter( array_keys( $GLOBALS['wpcap_test_options'] ), static fn( $k ) => (bool) preg_match( '/licen[cs]e|wplc|entitlement/i', (string) $k ) );
check( 'no licence state is read or written', array_values( $a5_licence_options_before ) === array_values( $a5_licence_options ) && array() === $a5_licence_options );
check( 'customer mails were delivered locally (no external mail service)', count( $GLOBALS['wpcap_test_mails'] ) > $a5_mails_before );

// Self-test of the network trap: a request is caught and fails without reaching the network.
$a5_probe = wp_remote_post( 'https://licence.example.invalid/wplc/v1/check', array( 'body' => array() ) );
check( 'network trap self-test: an attempted request is detected and blocked', is_wp_error( $a5_probe ) && 'a5_network_blocked' === $a5_probe->get_error_code() && in_array( 'https://licence.example.invalid/wplc/v1/check', $a5_http, true ) );

/* ---- restore ---- */
foreach ( $a5_parked as $table => list( $pk, $col, $ids ) ) {
	if ( $ids ) { $wpdb->query( "UPDATE $table SET $col=DATE_SUB($col, INTERVAL 50 YEAR) WHERE $pk IN (" . implode( ',', $ids ) . ')' ); }
}
if ( $a5_subs ) { $wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='active' WHERE subscription_id IN (" . implode( ',', $a5_subs ) . ')' ); }
$GLOBALS['wpcap_test_hooks'] = $a5_saved_hooks;
$GLOBALS['wpcap_test_filters'] = $a5_saved_overrides;
PassCaptain\Payments\Registry::reset();
