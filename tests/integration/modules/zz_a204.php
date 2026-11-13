<?php
/**
 * A204: data diagnosis and reconciliation (Operations\Doctor, `wp pass-captain doctor`, section
 * "Datenkonsistenz" on the "Betrieb" page). Every inconsistency is seeded directly in the database,
 * detected, and – where a repair exists – previewed (nothing changes), repaired (audited) and gone.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Operations\Doctor;
use PassCaptain\Admin\OperationsPage;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( 'wp_die: ' . $m ); } }
if ( ! class_exists( 'WP_CLI' ) ) {
	/** Records WP-CLI output instead of printing/exiting. */
	class WP_CLI {
		public static $out = array();
		public static $halt = null;
		public static function line( $m = '' ) { self::$out[] = (string) $m; }
		public static function success( $m ) { self::$out[] = 'Success: ' . $m; }
		public static function warning( $m ) { self::$out[] = 'Warning: ' . $m; }
		public static function error( $m ) { self::$out[] = 'Error: ' . $m; }
		public static function halt( $code ) { self::$halt = (int) $code; }
	}
}

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

/** Finding keys of one check (newest first, generous limit). */
function wpa204_keys( $check ) {
	$report = Doctor::run( array( $check ), 1000 );
	return array_column( $report[ $check ]['items'] ?? array(), 'key' );
}
function wpa204_has( $check, $key ) { return in_array( $key, wpa204_keys( $check ), true ); }
function wpa204_paid_order( $type, $qty, $tag ) {
	$r = Orders::create( array( 'email' => "wpa204-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => "wpa204-$tag-" . uniqid() ) );
	Orders::confirm_manual_payment( $r['order_id'] );
	return (int) $r['order_id'];
}
function wpa204_audits( $action, $since_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND audit_id>%d ORDER BY audit_id", $action, $since_id ) );
}
function wpa204_last_audit_id() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT MAX(audit_id) FROM {$wpdb->prefix}wpcap_audit_logs" );
}
/** Fingerprint of the tables the doctor looks at (read-only check). */
function wpa204_fingerprint() {
	global $wpdb;
	$p = $wpdb->prefix;
	$out = array();
	foreach ( array( 'wpcap_tickets' => 'status', 'wpcap_orders' => 'status', 'wpcap_reservations' => 'status', 'wpcap_jobs' => 'status', 'wpcap_service_bookings' => 'status', 'wpcap_seat_holds' => 'status', 'wpcap_refunds' => 'status' ) as $table => $col ) {
		$out[ $table ] = $wpdb->get_results( "SELECT $col,COUNT(*) AS n FROM {$p}$table GROUP BY $col ORDER BY $col", ARRAY_A );
	}
	$out['counters'] = $wpdb->get_var( "SELECT CONCAT(SUM(sold_qty),'/',SUM(held_qty)) FROM {$p}wpcap_ticket_types" );
	$out['audit'] = wpa204_last_audit_id();
	return md5( serialize( $out ) );
}

echo "A204: diagnosis – checks, registration, read-only\n";
$a204_checks = Doctor::checks();
check( 'all required checks exist', array( 'tickets_without_order', 'tickets_of_closed_orders', 'paid_orders_without_tickets', 'refunds_exceed_payment', 'duplicate_provider_refunds', 'stale_queue_claims', 'expired_holds', 'capacity_counter_drift', 'capacity_ticket_drift', 'document_number_gaps', 'service_bookings', 'audit_gaps' ) === array_keys( $a204_checks ) );
$a204_rep_ok = true;
foreach ( Doctor::repairs() as $a204_id => $a204_r ) { $a204_rep_ok = $a204_rep_ok && isset( $a204_checks[ $a204_r['check'] ] ) && $a204_id === $a204_checks[ $a204_r['check'] ]['repair']; }
check( 'every repair belongs to exactly one check and only to checks that offer it', $a204_rep_ok && 5 === count( Doctor::repairs() ) );
// WP_CLI is not defined in the test process (defining it would change other code paths): the
// registration is checked in the source, the command itself through Doctor::cli().
check( 'WP-CLI command "wp pass-captain doctor" is registered under WP-CLI', is_callable( array( Doctor::class, 'cli' ) ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Operations/Doctor.php' ), "\\WP_CLI::add_command( 'pass-captain doctor', array( __CLASS__, 'cli' ) );" ) );
check( 'admin-post handler for repairs is registered; Doctor is registered by the plugin', isset( PassCaptain\Admin\Menu::handlers()[ OperationsPage::DOCTOR_ACTION ] ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' ), 'Operations\\Doctor::register();' ) );

$a204_type = make_type( 40, 10, 1000 );
$a204_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a204_type" );
$a204_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a204_occ" );
$a204_now = gmdate( 'Y-m-d H:i:s' );
$a204_past = gmdate( 'Y-m-d H:i:s', time() - 3600 );

echo "A204: tickets without order / of closed orders\n";
$wpdb->insert( "{$p}wpcap_tickets", array( 'public_id' => md5( 'wpa204-orphan' . uniqid() ), 'order_id' => 987654321, 'ticket_type_id' => $a204_type, 'token_hash' => hash( 'sha256', 'wpa204-orphan' . uniqid() ), 'status' => 'valid', 'issued_at' => $a204_now ) );
$a204_orphan = (int) $wpdb->insert_id;
check( 'a valid ticket without order is found', wpa204_has( 'tickets_without_order', 'ticket:' . $a204_orphan ) );

$a204_closed = wpa204_paid_order( $a204_type, 2, 'closed' );
$wpdb->query( "UPDATE {$p}wpcap_orders SET status='cancelled',payment_status='cancelled' WHERE order_id=$a204_closed" );
$a204_closed_tickets = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a204_closed ) );
$a204_refunded_order = wpa204_paid_order( $a204_type, 2, 'reftk' );
$a204_refunded_ticket = (int) tickets( $a204_refunded_order )[0]->ticket_id;
$wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id=$a204_refunded_ticket" );
$a204_keys = wpa204_keys( 'tickets_of_closed_orders' );
check( 'valid tickets of a cancelled order and a refunded but valid ticket are found', 2 === count( $a204_closed_tickets ) && ! array_diff( array( 'ticket:' . $a204_closed_tickets[0], 'ticket:' . $a204_closed_tickets[1], 'ticket:' . $a204_refunded_ticket ), $a204_keys ) );
check( 'a ticket of the healthy order of the same event is not reported', ! in_array( 'ticket:' . (int) tickets( $a204_refunded_order )[1]->ticket_id, $a204_keys, true ) );

$a204_fp = wpa204_fingerprint();
Doctor::run();
check( 'running all checks changes nothing (read-only, no audit entry)', $a204_fp === wpa204_fingerprint() );
$a204_scope = array( 'ticket:' . $a204_closed_tickets[0], 'ticket:' . $a204_closed_tickets[1], 'ticket:' . $a204_refunded_ticket );
$a204_preview = Doctor::repair( 'revoke_closed_order_tickets', true, $a204_scope );
check( 'repair preview (dry run) lists exactly the scoped tickets and writes nothing', is_array( $a204_preview ) && true === $a204_preview['dry_run'] && 3 === $a204_preview['count'] && ! array_diff( $a204_scope, $a204_preview['keys'] ) && $a204_fp === wpa204_fingerprint() );
$a204_audit0 = wpa204_last_audit_id();
$a204_done = Doctor::repair( 'revoke_closed_order_tickets', false, array( 'ticket:' . $a204_closed_tickets[0], 'ticket:' . $a204_closed_tickets[1] ) );
$a204_states = $wpdb->get_col( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $a204_closed_tickets ) . ')' );
check( 'repair revokes only the scoped tickets', 2 === $a204_done['count'] && array( 'revoked', 'revoked' ) === $a204_states && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$a204_refunded_ticket" ) );
$a204_doc_audit = wpa204_audits( 'doctor_repair', $a204_audit0 );
check( 'repair is audited (doctor_repair with repair, source, keys; doctor_tickets_revoked per order)', 1 === count( $a204_doc_audit ) && 'revoke_closed_order_tickets' === json_decode( $a204_doc_audit[0]->details, true )['repair'] && 'cli' === json_decode( $a204_doc_audit[0]->details, true )['source'] && 1 === count( wpa204_audits( 'doctor_tickets_revoked', $a204_audit0 ) ) && (int) wpa204_audits( 'doctor_tickets_revoked', $a204_audit0 )[0]->object_id === $a204_closed );
Doctor::repair( 'revoke_closed_order_tickets', false, array( 'ticket:' . $a204_refunded_ticket ) );
check( 'after the repair the check no longer finds them; a second run changes nothing', ! array_intersect( $a204_scope, wpa204_keys( 'tickets_of_closed_orders' ) ) && 0 === Doctor::repair( 'revoke_closed_order_tickets', false, $a204_scope )['count'] );
check( 'the orphan ticket is report-only (no repair offered)', '' === $a204_checks['tickets_without_order']['repair'] && is_wp_error( Doctor::repair( 'delete_orphans', false ) ) );

echo "A204: paid orders without tickets\n";
$a204_noticket = wpa204_paid_order( $a204_type, 1, 'noticket' );
$wpdb->query( "DELETE FROM {$p}wpcap_tickets WHERE order_id=$a204_noticket" );
check( 'a just paid order is given time to receive its tickets', ! wpa204_has( 'paid_orders_without_tickets', 'order:' . $a204_noticket ) );
$wpdb->query( "UPDATE {$p}wpcap_orders SET updated_at='$a204_past' WHERE order_id=$a204_noticket" );
check( 'a paid order without tickets is found after the grace period', wpa204_has( 'paid_orders_without_tickets', 'order:' . $a204_noticket ) );

echo "A204: refunds exceeding payments, duplicate provider refunds\n";
$a204_over = wpa204_paid_order( $a204_type, 1, 'overref' );
foreach ( array( 1, 2 ) as $a204_i ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $a204_over, 'idempotency_key' => hash( 'sha256', "wpa204-over-$a204_i-" . uniqid() ), 'amount_minor' => 800, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'status' => 'completed', 'provider_reference' => 're_wpa204_dup', 'created_at' => $a204_now, 'updated_at' => $a204_now ) );
	$a204_refund_ids[] = (int) $wpdb->insert_id;
}
check( 'refunds above the paid amount are found (1600 of 1000)', wpa204_has( 'refunds_exceed_payment', 'order:' . $a204_over ) );
check( 'two refunds with the same provider reference are found', wpa204_has( 'duplicate_provider_refunds', 'refund:' . $a204_refund_ids[0] ) );
$a204_ok_order = wpa204_paid_order( $a204_type, 2, 'okref' );
$a204_ok_refund = PassCaptain\Refunds::request( $a204_ok_order, array( (int) tickets( $a204_ok_order )[0]->ticket_id ), 'WPA204', 'wpa204-okref-' . uniqid() );
check( 'a regular partial refund is not reported', 'completed' === $a204_ok_refund['status'] && ! wpa204_has( 'refunds_exceed_payment', 'order:' . $a204_ok_order ) && ! wpa204_has( 'audit_gaps', 'refund:' . (int) $a204_ok_refund['refund_id'] ) );

echo "A204: stale queue claims\n";
$a204_job = static function ( $tag, $locked ) use ( $wpdb, $p, $a204_now ) {
	$wpdb->insert( "{$p}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', "wpa204-job-$tag-" . uniqid() ), 'job_type' => 'wpa204_test', 'payload' => '{}', 'run_after' => $a204_now, 'status' => 'running', 'attempts' => 1, 'locked_at' => $locked, 'created_at' => $a204_now, 'updated_at' => $a204_now ) );
	return (int) $wpdb->insert_id;
};
$a204_stale_job = $a204_job( 'stale', $a204_past );
$a204_fresh_job = $a204_job( 'fresh', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', 'wpa204-mail-' . uniqid() ), 'recipient' => 'wpa204@example.com', 'template_key' => 'wpa204', 'payload' => '{}', 'send_at' => $a204_now, 'status' => 'sending', 'attempts' => 1, 'locked_at' => $a204_past, 'created_at' => $a204_now ) );
$a204_stale_mail = (int) $wpdb->insert_id;
$a204_keys = wpa204_keys( 'stale_queue_claims' );
check( 'a job and an e-mail claimed an hour ago are found; a claim of one minute is not', in_array( 'jobs:' . $a204_stale_job, $a204_keys, true ) && in_array( 'messages:' . $a204_stale_mail, $a204_keys, true ) && ! in_array( 'jobs:' . $a204_fresh_job, $a204_keys, true ) );

echo "A204: holds past expiry\n";
$a204_hold = Orders::create( array( 'email' => 'wpa204-hold@example.com', 'items' => array( array( 'ticket_type_id' => $a204_type, 'quantity' => 3 ) ), 'idempotency_key' => 'wpa204-hold-' . uniqid() ) );
$a204_hold_res = (int) $wpdb->get_var( "SELECT reservation_id FROM {$p}wpcap_reservations WHERE order_id=" . (int) $a204_hold['order_id'] );
$a204_avail_before = Orders::availability( $a204_type );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='$a204_past' WHERE order_id=" . (int) $a204_hold['order_id'] );
$wpdb->insert( "{$p}wpcap_seat_holds", array( 'occurrence_id' => $a204_occ, 'seat_id' => 990001, 'order_id' => 0, 'request_key' => hash( 'sha256', 'wpa204-seat-' . uniqid() ), 'expires_at' => $a204_past, 'status' => 'active' ) );
$a204_seat_hold = (int) $wpdb->insert_id;
$a204_res = PassCaptain\ServiceBookings::create_resource( $a204_event, array( 'name' => 'WPA204 Raum', 'capacity' => 10 ) );
$a204_slot = PassCaptain\ServiceBookings::create_slot( $a204_res, array( 'starts_at' => gmdate( 'Y-m-d 09:00:00', time() + 5 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d 10:00:00', time() + 5 * DAY_IN_SECONDS ), 'capacity' => 10 ) );
$a204_booking = static function ( $status, $order_id, $expires ) use ( $wpdb, $p, $a204_slot, $a204_now ) {
	$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $a204_slot, 'order_id' => $order_id, 'customer_email' => 'wpa204-book@example.com', 'idempotency_key' => hash( 'sha256', 'wpa204-book-' . uniqid( '', true ) ), 'quantity' => 1, 'expires_at' => $expires, 'status' => $status, 'token_hash' => '', 'created_at' => $a204_now, 'updated_at' => $a204_now ) );
	return (int) $wpdb->insert_id;
};
$a204_held_booking = $a204_booking( 'held', 0, $a204_past );
$a204_keys = wpa204_keys( 'expired_holds' );
check( 'lapsed ticket hold, its unpaid order, a lapsed seat hold and a lapsed service booking hold are found', ! array_diff( array( 'reservation:' . $a204_hold_res, 'order:' . (int) $a204_hold['order_id'], 'seat_hold:' . $a204_seat_hold, 'booking:' . $a204_held_booking ), $a204_keys ) );
$a204_fp = wpa204_fingerprint();
$a204_prev = Doctor::repair( 'release_expired_holds' );
check( 'preview of the hold release lists them and changes nothing', in_array( 'reservation:' . $a204_hold_res, $a204_prev['keys'], true ) && $a204_fp === wpa204_fingerprint() );
$a204_audit0 = wpa204_last_audit_id();
$a204_rel = Doctor::repair( 'release_expired_holds', false );
check( 'release: hold expired, order expired, seat hold and service booking hold expired', 'expired' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_reservations WHERE reservation_id=$a204_hold_res" ) && 'expired' === Orders::get( $a204_hold['order_id'] )->payment_status && 'expired' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_seat_holds WHERE hold_id=$a204_seat_hold" ) && 'expired' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_service_bookings WHERE booking_id=$a204_held_booking" ) );
check( 'release: capacity is available again and the run is audited', $a204_avail_before + 3 === Orders::availability( $a204_type ) && 1 === count( wpa204_audits( 'doctor_repair', $a204_audit0 ) ) && ! array_intersect( array( 'reservation:' . $a204_hold_res, 'seat_hold:' . $a204_seat_hold, 'booking:' . $a204_held_booking ), wpa204_keys( 'expired_holds' ) ) && in_array( 'reservation:' . $a204_hold_res, $a204_rel['keys'], true ) );

echo "A204: capacity counter drift\n";
if ( PassCaptain\CapacityCounters::maintained() ) {
	PassCaptain\CapacityCounters::reconcile( true );
	$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET sold_qty=sold_qty+3 WHERE ticket_type_id=$a204_type" );
	$a204_keys = wpa204_keys( 'capacity_counter_drift' );
	check( 'a counter that disagrees with the orders is found', in_array( 'ticket_type:' . $a204_type, $a204_keys, true ) );
	$a204_audit0 = wpa204_last_audit_id();
	$a204_rb = Doctor::repair( 'rebuild_counters', false );
	check( 'rebuild: counters equal the source sums again, audited', ! PassCaptain\CapacityCounters::drift( array( $a204_occ ) ) && in_array( 'ticket_type:' . $a204_type, $a204_rb['keys'], true ) && 1 === count( wpa204_audits( 'doctor_repair', $a204_audit0 ) ) );
} else {
	check( 'capacity counters are maintained in the test database', false );
}

echo "A204: invoice/credit-note number gaps and audit gaps\n";
$a204_year = gmdate( 'Y' );
$a204_inv = array();
foreach ( array( 1, 3 ) as $a204_n ) {
	$wpdb->insert( "{$p}wpcap_invoices", array( 'order_id' => 880000000 + $a204_n + random_int( 0, 99999 ) * 10, 'invoice_number' => sprintf( 'WPA204-%s-%06d', $a204_year, $a204_n ), 'currency' => 'EUR', 'subtotal_minor' => 100, 'tax_minor' => 0, 'total_minor' => 100, 'buyer_snapshot' => '{}', 'issued_at' => $a204_now, 'status' => 'issued' ) );
	$a204_inv[] = (int) $wpdb->insert_id;
}
check( 'a missing invoice number is found (Invoices::document_gaps)', wpa204_has( 'document_number_gaps', 'number:' . sprintf( 'WPA204-%s-%06d', $a204_year, 2 ) ) && '' === $a204_checks['document_number_gaps']['repair'] );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $a204_ok_order, 'idempotency_key' => hash( 'sha256', 'wpa204-noaudit-' . uniqid() ), 'amount_minor' => 1, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'status' => 'completed', 'created_at' => $a204_now, 'updated_at' => $a204_now ) );
$a204_noaudit_refund = (int) $wpdb->insert_id;
$a204_keys = wpa204_keys( 'audit_gaps' );
check( 'a completed refund and invoices without audit entries are found', in_array( 'refund:' . $a204_noaudit_refund, $a204_keys, true ) && in_array( 'invoice:' . $a204_inv[0], $a204_keys, true ) );
PassCaptain\Audit::record( 'invoice_issued', 'invoice', $a204_inv[0], array( 'invoice_number' => 'x' ) );
check( 'an invoice with its audit entry is no longer reported', ! wpa204_has( 'audit_gaps', 'invoice:' . $a204_inv[0] ) && wpa204_has( 'audit_gaps', 'invoice:' . $a204_inv[1] ) );

echo "A204: service bookings in inconsistent states\n";
$a204_cancelled = Orders::create( array( 'email' => 'wpa204-bc@example.com', 'items' => array( array( 'ticket_type_id' => $a204_type, 'quantity' => 1 ) ), 'idempotency_key' => 'wpa204-bc-' . uniqid() ) );
Orders::cancel_order( $a204_cancelled['order_id'], 'test' );
$a204_b_closed = $a204_booking( 'confirmed', (int) $a204_cancelled['order_id'], null );
$a204_b_active = $a204_booking( 'pending_payment', wpa204_paid_order( $a204_type, 1, 'bpaid' ), gmdate( 'Y-m-d H:i:s', time() + 600 ) );
$a204_lapsed_order = wpa204_paid_order( $a204_type, 1, 'blapsed' );
$a204_b_lapsed = $a204_booking( 'pending_payment', $a204_lapsed_order, $a204_past );
$a204_b_unalloc = $a204_booking( 'unallocated', wpa204_paid_order( $a204_type, 1, 'bunalloc' ), null );
$a204_bkeys = array_map( static fn( $id ) => 'booking:' . $id, array( $a204_b_closed, $a204_b_active, $a204_b_lapsed, $a204_b_unalloc ) );
check( 'confirmed booking of a cancelled order, unconfirmed booking of a paid order, paid after lapse, unallocated without refund: all found', ! array_diff( $a204_bkeys, wpa204_keys( 'service_bookings' ) ) );
$a204_bprev = Doctor::repair( 'sync_service_bookings', true, $a204_bkeys );
check( 'preview: only the unambiguous ones (ended order, paid with valid hold) would change', array( 'booking:' . $a204_b_active, 'booking:' . $a204_b_closed ) == array_values( array_intersect( array( 'booking:' . $a204_b_active, 'booking:' . $a204_b_closed ), $a204_bprev['keys'] ) ) && 2 === $a204_bprev['count'] );
$a204_audit0 = wpa204_last_audit_id();
$a204_bdone = Doctor::repair( 'sync_service_bookings', false, $a204_bkeys );
$a204_bstate = static fn( $id ) => $wpdb->get_var( "SELECT status FROM {$p}wpcap_service_bookings WHERE booking_id=$id" );
check( 'repair: booking of the cancelled order released, booking of the paid order confirmed', 'cancelled' === $a204_bstate( $a204_b_closed ) && 'confirmed' === $a204_bstate( $a204_b_active ) && 2 === $a204_bdone['count'] );
check( 'repair: lapsed paid and unallocated bookings are left for a person', 'pending_payment' === $a204_bstate( $a204_b_lapsed ) && 'unallocated' === $a204_bstate( $a204_b_unalloc ) && wpa204_has( 'service_bookings', 'booking:' . $a204_b_lapsed ) && wpa204_has( 'service_bookings', 'booking:' . $a204_b_unalloc ) );
check( 'repair: audited (doctor_repair plus the booking entries of the reused code paths)', 1 === count( wpa204_audits( 'doctor_repair', $a204_audit0 ) ) && 1 === count( wpa204_audits( 'service_booking_released', $a204_audit0 ) ) && 1 === count( wpa204_audits( 'service_booking_confirmed', $a204_audit0 ) ) );

echo "A204: WP-CLI\n";
WP_CLI::$out = array();
WP_CLI::$halt = null;
$a204_cli = Doctor::cli( array(), array( 'check' => 'tickets_without_order,stale_queue_claims', 'format' => 'json' ) );
$a204_json = json_decode( (string) WP_CLI::$out[0], true );
check( 'doctor --check=… --format=json reports the selected checks and exits with 1 on findings', 2 === count( $a204_json ) && in_array( 'ticket:' . $a204_orphan, array_column( $a204_json[0]['items'], 'key' ), true ) && 1 === WP_CLI::$halt );
WP_CLI::$out = array();
WP_CLI::$halt = null;
Doctor::cli( array(), array( 'check' => 'tickets_without_order' ) );
check( 'table output names the finding and the hint', (bool) array_filter( WP_CLI::$out, static fn( $l ) => false !== strpos( $l, 'ticket:' . $a204_orphan ) ) && (bool) array_filter( WP_CLI::$out, static fn( $l ) => 0 === strpos( $l, 'CRITICAL' ) ) );
$a204_fp = wpa204_fingerprint();
WP_CLI::$out = array();
$a204_cli_prev = Doctor::cli( array(), array( 'repair' => 'requeue_stale_claims', 'keys' => 'jobs:' . $a204_stale_job . ',messages:' . $a204_stale_mail ) );
check( 'doctor --repair=… without --apply is a preview (nothing changed)', true === $a204_cli_prev['dry_run'] && 2 === $a204_cli_prev['count'] && $a204_fp === wpa204_fingerprint() && 'running' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=$a204_stale_job" ) );
$a204_audit0 = wpa204_last_audit_id();
$a204_cli_apply = Doctor::cli( array(), array( 'repair' => 'requeue_stale_claims', 'apply' => true, 'keys' => 'jobs:' . $a204_stale_job . ',messages:' . $a204_stale_mail . ',jobs:' . $a204_fresh_job ) );
check( 'doctor --repair=requeue_stale_claims --apply re-queues the stale claims only, audited', false === $a204_cli_apply['dry_run'] && 'queued' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=$a204_stale_job" ) && null === $wpdb->get_var( "SELECT locked_at FROM {$p}wpcap_jobs WHERE job_id=$a204_stale_job" ) && 'queued' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_message_queue WHERE message_id=$a204_stale_mail" ) && 'running' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=$a204_fresh_job" ) && 1 === count( wpa204_audits( 'doctor_repair', $a204_audit0 ) ) );
WP_CLI::$out = array();
Doctor::cli( array(), array( 'repair' => 'nonsense' ) );
check( 'an unknown repair is an error', (bool) array_filter( WP_CLI::$out, static fn( $l ) => 0 === strpos( $l, 'Error:' ) ) );

echo "A204: Betrieb page section\n";
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'repairs from the admin page need manage_options', is_wp_error( OperationsPage::doctor_action( array( 'repair' => 'rebuild_counters', 'confirm' => '1' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$a204_fp = wpa204_fingerprint();
$a204_adm_prev = OperationsPage::doctor_action( array( 'repair' => 'revoke_closed_order_tickets', 'keys' => 'ticket:1' ) );
check( 'without confirm the admin action is only a preview', is_array( $a204_adm_prev ) && true === $a204_adm_prev['dry_run'] && $a204_fp === wpa204_fingerprint() );
ob_start();
$_GET = array();
OperationsPage::render_doctor();
$a204_html = ob_get_clean();
check( 'the section is shown with a start button; checks do not run on every page view', false !== strpos( $a204_html, 'Datenkonsistenz' ) && false !== strpos( $a204_html, 'wpcap_doctor=1' ) && false === strpos( $a204_html, 'data-check=' ) );
$a204_link_job = $a204_job( 'link', $a204_past );
$_GET = array( 'wpcap_doctor' => '1', 'wpcap_doctor_preview' => 'sync_service_bookings' );
ob_start();
OperationsPage::render_doctor();
$a204_html = ob_get_clean();
$_GET = array();
check( 'on request all checks are listed with findings, hints and the manual/repair column', 12 === substr_count( $a204_html, 'data-check=' ) && false !== strpos( $a204_html, 'ticket:' . $a204_orphan ) && false !== strpos( $a204_html, 'Ticket prüfen' ) && false !== strpos( $a204_html, 'manuell prüfen' ) );
check( 'a check with a repair offers a preview link', false !== strpos( $a204_html, 'wpcap_doctor_preview=requeue_stale_claims' ) );
check( 'the requested preview shows a confirm form posting to the repair action', false !== strpos( $a204_html, 'value="' . OperationsPage::DOCTOR_ACTION . '"' ) && false !== strpos( $a204_html, 'name="repair" value="sync_service_bookings"' ) && false !== strpos( $a204_html, 'name="confirm" value="1"' ) );

// Clean up seeded inconsistencies so later modules start from consistent data.
$wpdb->query( "DELETE FROM {$p}wpcap_tickets WHERE ticket_id=$a204_orphan" );
$wpdb->query( "DELETE FROM {$p}wpcap_invoices WHERE invoice_id IN (" . implode( ',', $a204_inv ) . ')' );
$wpdb->query( "DELETE FROM {$p}wpcap_refunds WHERE refund_id IN (" . implode( ',', array_merge( $a204_refund_ids, array( $a204_noaudit_refund ) ) ) . ')' );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='wpa204_test'" );
$wpdb->query( "DELETE FROM {$p}wpcap_message_queue WHERE message_id=$a204_stale_mail" );
$wpdb->query( "DELETE FROM {$p}wpcap_service_bookings WHERE booking_id IN ($a204_b_lapsed,$a204_b_unalloc)" );
$GLOBALS['wpcap_test_transients'] = array();
