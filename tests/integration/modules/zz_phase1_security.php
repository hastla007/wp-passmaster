<?php
/**
 * Phase 1 security fixes: assignment after transfer, holder-change limit, refund overlap and
 * re-validation, cumulative approval threshold, invoice CSRF, wallet temp files, staff capability.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;
use PassCaptain\TicketAssignments;

global $wpdb;
$p = $wpdb->prefix;

/** Free order with $qty tickets; returns [order, access token]. */
function p1s_free_order( $qty, $email ) {
	$type = make_type( 10, 10, 0 );
	$GLOBALS['wpcap_test_transients'] = array();
	$before = count( $GLOBALS['wpcap_test_mails'] );
	$order = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'p1s-' . uniqid( '', true ) ) );
	$token = '';
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '/wpcap_access=([a-f0-9]{64})/', $mail['body'], $m ) ) { $token = $m[1]; }
	}
	return array( $order, $token );
}

function p1s_token_hash( $ticket_id ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket_id ) );
}

echo "Phase 1 security: assignment after transfer and holder-change limit\n";
list( $ao, $atoken ) = p1s_free_order( 2, 'p1s-buyer@example.com' );
$at = tickets( $ao['order_id'] );
check( 'setup: free order with access token and two tickets', 64 === strlen( $atoken ) && 2 === count( $at ) );
$transfer = Orders::transfer_ticket( $ao['public_id'], $atoken, $at[0]->public_id, 'p1s-recipient@example.com' );
$recipient_hash = p1s_token_hash( $at[0]->ticket_id );
$takeback = TicketAssignments::assign( $ao['public_id'], $atoken, $at[0]->public_id, 'Käufer', 'p1s-buyer@example.com' );
check( 'buyer cannot re-target a transferred ticket (409, recipient code stays valid)', ! is_wp_error( $transfer ) && is_wp_error( $takeback ) && 'wpcap_assignment_transferred' === $takeback->get_error_code() && 409 === $takeback->get_error_data()['status'] && $recipient_hash === p1s_token_hash( $at[0]->ticket_id ) );
$rename = TicketAssignments::assign( $ao['public_id'], $atoken, $at[0]->public_id, 'Neuer Name', 'p1s-recipient@example.com' );
check( 'transferred ticket: even same-holder edits via the buyer link are refused', is_wp_error( $rename ) && 'wpcap_assignment_transferred' === $rename->get_error_code() );

$first = TicketAssignments::assign( $ao['public_id'], $atoken, $at[1]->public_id, 'Carol', 'p1s-carol@example.com' );
$fix = TicketAssignments::assign( $ao['public_id'], $atoken, $at[1]->public_id, 'Carol Korrigiert', 'p1s-carol@example.com' );
$carol_hash = p1s_token_hash( $at[1]->ticket_id );
$second = TicketAssignments::assign( $ao['public_id'], $atoken, $at[1]->public_id, 'Dave', 'p1s-dave@example.com' );
check( 'first holder change rotates, name correction keeps the code', ! is_wp_error( $first ) && $first['code_rotated'] && ! is_wp_error( $fix ) && ! $fix['code_rotated'] );
check( 'second holder change via assign is refused (409) and keeps the current code', is_wp_error( $second ) && 'wpcap_assignment_limit' === $second->get_error_code() && 409 === $second->get_error_data()['status'] && $carol_hash === p1s_token_hash( $at[1]->ticket_id ) );
$back = TicketAssignments::assign( $ao['public_id'], $atoken, $at[1]->public_id, 'Käufer', 'p1s-buyer@example.com' );
check( 'buyer cannot rotate the code back to himself after assigning', is_wp_error( $back ) && $carol_hash === p1s_token_hash( $at[1]->ticket_id ) );
$GLOBALS['wpcap_test_filters']['wpcap_max_holder_changes'] = 2;
$allowed = TicketAssignments::assign( $ao['public_id'], $atoken, $at[1]->public_id, 'Dave', 'p1s-dave@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_max_holder_changes'] );
check( 'filter wpcap_max_holder_changes can allow more holder changes', ! is_wp_error( $allowed ) && $allowed['code_rotated'] && 2 === TicketAssignments::holder_changes( $at[1]->ticket_id ) );

echo "Phase 1 security: refund overlap, re-validation and cumulative approval\n";
final class P1s_Fake_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static int $calls = 0;
	public function id() { return 'p1s_fake'; }
	public function title() { return 'Fake'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) { return array(); }
	public function verify_webhook( \WP_REST_Request $request ) { return null; }
	public function supports_refunds() { return true; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { self::$calls++; return 'fake_' . $idempotency_key; }
}
$p1s_gateways = static function ( $list ) { $list[] = new P1s_Fake_Gateway(); return $list; };
add_filter( 'wpcap_payment_gateways', $p1s_gateways );
PassCaptain\Payments\Registry::reset();
$old_threshold = (int) Settings::get( 'refund_approval_threshold' );
Settings::update( array( 'refund_approval_threshold' => 1000 ) );
$refund_type = make_type( 20, 10, 800 );
$paid_order = static function () use ( $refund_type, $wpdb, $p ) {
	$id = admin_paid_order( $refund_type, 3, 'p1s-refund@example.com' );
	$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='p1s_fake' WHERE order_id=" . (int) $id );
	return $id;
};
$requester = static function () { $GLOBALS['wpcap_test_user_id'] = 1; $GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP ); };
$approver = static function () { $GLOBALS['wpcap_test_user_id'] = 2; $GLOBALS['wpcap_test_denied_caps'] = array(); };
$refunded = static function ( $order_id ) { return (int) Orders::get( $order_id )->refunded_minor; };

// Audit reproduction: 3 × 8 €, threshold 10 €.
$o = $paid_order();
$rt = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $o ) );
$requester();
$r1 = Refunds::request( $o, array( $rt[0], $rt[1] ), 'Test', 'p1s-r1-' . uniqid() );
$r2 = Refunds::request( $o, array( $rt[0] ), 'Test', 'p1s-r2-' . uniqid() );
check( 'R1 (tickets 1+2, 16 €) waits for approval', is_array( $r1 ) && 'pending_approval' === $r1['status'] && 1600 === (int) $r1['amount_minor'] );
check( 'R2 for a ticket of the pending R1 is rejected as overlap (409)', is_wp_error( $r2 ) && 'wpcap_refund_overlap' === $r2->get_error_code() && 409 === $r2->get_error_data()['status'] && 0 === $refunded( $o ) );
$r3 = Refunds::request( $o, array( $rt[2] ), 'Test', 'p1s-r3-' . uniqid() );
check( 'R3 (ticket 3, 8 €) needs approval because 16 € are already pending (cumulative)', is_array( $r3 ) && 'pending_approval' === $r3['status'] && 800 === (int) $r3['amount_minor'] );
$all_open = Refunds::request( $o, array(), 'Test', 'p1s-all-' . uniqid() );
check( '"all open tickets" excludes tickets bound by pending refunds', is_wp_error( $all_open ) && 'wpcap_refund_tickets' === $all_open->get_error_code() );
$approver();
P1s_Fake_Gateway::$calls = 0;
$a1 = Refunds::approve( $r1['refund_id'] );
$a3 = Refunds::approve( $r3['refund_id'] );
check( 'approving R1 and R3 refunds exactly the order total once', ! is_wp_error( $a1 ) && 'completed' === $a1['status'] && ! is_wp_error( $a3 ) && 'completed' === $a3['status'] && 2400 === $refunded( $o ) && 2 === P1s_Fake_Gateway::$calls );

// Legacy / race: an overlapping refund was approved before the fix (inserted directly).
$o = $paid_order();
$rt = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $o ) );
$requester();
$r1 = Refunds::request( $o, array( $rt[0], $rt[1] ), 'Test', 'p1s-l1-' . uniqid() );
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $o, 'idempotency_key' => hash( 'sha256', uniqid( 'p1s', true ) ), 'amount_minor' => 800, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => json_encode( array( $rt[0] ) ), 'reason' => 'legacy', 'status' => 'approved', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$legacy = Refunds::execute( (int) $wpdb->insert_id );
$approver();
P1s_Fake_Gateway::$calls = 0;
$late = Refunds::approve( $r1['refund_id'] );
$late_row = Refunds::get( $r1['refund_id'] );
$ticket2 = $wpdb->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . $rt[1] );
check( 'approving R1 after ticket 1 was refunded fails without provider call', 'completed' === $legacy['status'] && is_wp_error( $late ) && 'wpcap_refund_conflict' === $late->get_error_code() && 'failed' === $late_row['status'] && '' !== $late_row['error_message'] && 0 === P1s_Fake_Gateway::$calls );
check( 'failed overlap leaves ticket 2 valid and only 8 € refunded', 'valid' === $ticket2->status && 0 === (int) $ticket2->refunded && 800 === $refunded( $o ) );
$retry = Refunds::execute( $r1['refund_id'] );
check( 'retrying the failed refund re-validates and fails again', is_wp_error( $retry ) && 0 === P1s_Fake_Gateway::$calls && 800 === $refunded( $o ) );

// Amount re-validation: an approved refund larger than what is left on the order.
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $o, 'idempotency_key' => hash( 'sha256', uniqid( 'p1s', true ) ), 'amount_minor' => 5000, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'reason' => 'zu viel', 'status' => 'approved', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$too_much_id = (int) $wpdb->insert_id;
$too_much = Refunds::execute( $too_much_id );
check( 'refund above the remaining order amount fails without provider call', is_wp_error( $too_much ) && 'failed' === Refunds::get( $too_much_id )['status'] && 0 === P1s_Fake_Gateway::$calls && 800 === $refunded( $o ) );

// Splitting does not avoid the approval.
$o = $paid_order();
$rt = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $o ) );
$requester();
$s1 = Refunds::request( $o, array( $rt[0] ), 'Test', 'p1s-s1-' . uniqid() );
$s2 = Refunds::request( $o, array( $rt[1] ), 'Test', 'p1s-s2-' . uniqid() );
check( 'single 8 € refund below the threshold runs immediately', is_array( $s1 ) && 'completed' === $s1['status'] );
check( 'second 8 € refund (cumulative 16 € > 10 €) needs approval', is_array( $s2 ) && 'pending_approval' === $s2['status'] && 800 === $refunded( $o ) );
$approver();
$s3 = Refunds::request( $o, array( $rt[2] ), 'Test', 'p1s-s3-' . uniqid() );
check( 'approvers are not held back by the threshold', is_array( $s3 ) && 'completed' === $s3['status'] );

// External refunds (WooCommerce, provider dashboard): no approval, but overlap/remaining respected.
$o = $paid_order();
$rt = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $o ) );
$requester();
$pending = Refunds::request( $o, array( $rt[0], $rt[1] ), 'Test', 'p1s-e1-' . uniqid() );
$ext = Refunds::record_external( $o, array( $rt[0] ), 800, 'extern', 'p1s-ext-' . uniqid() );
check( 'external refund is recorded without approval', is_array( $ext ) && 'completed' === $ext['status'] && 800 === $refunded( $o ) );
$approver();
P1s_Fake_Gateway::$calls = 0;
$after_ext = Refunds::approve( $pending['refund_id'] );
check( 'pending native refund overlapping the external one fails on approval', is_wp_error( $after_ext ) && 'failed' === Refunds::get( $pending['refund_id'] )['status'] && 0 === P1s_Fake_Gateway::$calls && 800 === $refunded( $o ) );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $o, 'idempotency_key' => hash( 'sha256', uniqid( 'p1s', true ) ), 'amount_minor' => 800, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => json_encode( array( $rt[2] ) ), 'reason' => 'läuft', 'status' => 'processing', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$ext2 = Refunds::record_external( $o, array( $rt[1], $rt[2] ), 2400, 'extern', 'p1s-ext2-' . uniqid() );
$t3 = $wpdb->get_row( "SELECT refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . $rt[2] );
check( 'external refund skips tickets/amount of a running native refund', is_array( $ext2 ) && 800 === (int) $ext2['amount_minor'] && array( $rt[1] ) === json_decode( $ext2['ticket_ids'], true ) && 0 === (int) $t3->refunded && 1600 === $refunded( $o ) );

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
Settings::update( array( 'refund_approval_threshold' => $old_threshold ) );
remove_filter( 'wpcap_payment_gateways', $p1s_gateways );
PassCaptain\Payments\Registry::reset();

echo "Phase 1 security: invoice PDF download needs a nonce to issue an invoice\n";
$GLOBALS['wpcap_test_transients'] = array();
$unpaid = Orders::create( array( 'email' => 'p1s-inv@example.com', 'items' => array( array( 'ticket_type_id' => $refund_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p1s-inv-' . uniqid() ) );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$_GET = array( 'order_id' => (string) $unpaid['order_id'] );
$died = false;
try { PassCaptain\Printing\InvoicePdf::handle_admin_download(); } catch ( Wpcap_Test_Die $e ) { $died = true; }
$_GET = array();
check( 'download without an invoice checks the wpcap_issue_invoice nonce first', $died && in_array( 'wpcap_issue_invoice_' . (int) $unpaid['order_id'], $GLOBALS['wpcap_test_nonce_checks'], true ) );

echo "Phase 1 security: wallet .pkpass attachments in a private temp directory\n";
$parts = new ReflectionMethod( PassCaptain\WalletPasses\Service::class, 'mail_parts' );
$parts->setAccessible( true );
list( , $files ) = $parts->invoke( null, array( 'apple' => array( 'serial_number' => 'abcdef1234567890', 'bundle' => 'PKPASS', 'download_url' => '' ) ), array( 'Event', 'Ticket' ), true );
$file = $files[0] ?? '';
$dir = dirname( $file );
check( 'pkpass is written to a random 0700 directory as 0600 file', '' !== $file && is_file( $file ) && rtrim( sys_get_temp_dir(), '/' ) !== rtrim( $dir, '/' ) && str_starts_with( basename( $dir ), 'wpcap-' ) && 0700 === ( fileperms( $dir ) & 0777 ) && 0600 === ( fileperms( $file ) & 0777 ) && 'ticket-abcdef123456.pkpass' === basename( $file ) );
PassCaptain\WalletPasses\Service::cleanup_temp_files();
check( 'pkpass temp file and directory are cleaned up', ! file_exists( $file ) && ! is_dir( $dir ) );

echo "Phase 1 security: staff check-in capability is removed with the last assignment\n";
final class P1s_User {
	public array $caps = array();
	public array $roles = array( 'subscriber' );
	public function __construct( public int $ID, public string $user_login, public string $user_email ) {}
	public function add_cap( $cap ) { $this->caps[ $cap ] = true; }
	public function remove_cap( $cap ) { unset( $this->caps[ $cap ] ); }
}
$staff_user = new P1s_User( 4711, 'p1s-scanner', 'p1s-scanner@example.com' );
$GLOBALS['wpcap_test_users'][] = $staff_user;
$ev1 = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . make_type( 5 ) );
$ev2 = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . make_type( 5 ) );
PassCaptain\Admin\EventsPage::assign_staff( array( 'event_id' => $ev1, 'user' => 'p1s-scanner', 'capability' => 'wpcap_checkin' ) );
PassCaptain\Admin\EventsPage::assign_staff( array( 'event_id' => $ev2, 'user' => 'p1s-scanner', 'capability' => 'wpcap_checkin' ) );
check( 'setup: check-in capability granted with the assignment', ! empty( $staff_user->caps['wpcap_checkin'] ) );
PassCaptain\Admin\EventsPage::remove_staff( array( 'event_id' => $ev1, 'user_id' => 4711, 'capability' => 'wpcap_checkin' ) );
check( 'capability stays while another check-in assignment exists', ! empty( $staff_user->caps['wpcap_checkin'] ) );
PassCaptain\Admin\EventsPage::remove_staff( array( 'event_id' => $ev2, 'user_id' => 4711, 'capability' => 'wpcap_checkin' ) );
check( 'capability is removed with the last check-in assignment', empty( $staff_user->caps['wpcap_checkin'] ) );
