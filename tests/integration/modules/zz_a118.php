<?php
/**
 * A118: buyer self-service in the order portal – ticket PDF download (single / all), cancellation
 * requests through the refund approval workflow, order link from the ticket page.
 * Included from run.php (helpers ex_* from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\PortalSelfService as A118;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }

/** Gives an order a known access token; returns [public_id, token]. */
function a118_access( $order_id ) {
	global $wpdb;
	$token = bin2hex( random_bytes( 32 ) );
	$wpdb->update( "{$wpdb->prefix}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $order_id ) );
	return array( (string) Orders::get( $order_id )->public_id, $token );
}
function a118_hashes( array $ids ) {
	global $wpdb;
	$out = array();
	foreach ( $ids as $id ) { $out[ $id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $id ) ); }
	return $out;
}
function a118_public( $ticket_id ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket_id ) );
}
function a118_audit( $action, $order_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_type='order' AND object_id=%d", $action, $order_id ) );
}
function a118_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }

$a118_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'portal_refund_request_hours' => 0 ) );
$GLOBALS['wpcap_test_transients'] = array();

echo "A118: schema and settings\n";
check( 'schema 3.8.9: wpcap_refunds.source exists, Refunds::source_ready()', version_compare( PassCaptain\Installer::DB_VERSION, '3.8.9', '>=' ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_refunds LIKE 'source'" ) && Refunds::source_ready() );
check( 'cancellation requests are off by default (portal_refund_request_hours = 0)', 0 === Settings::defaults()['portal_refund_request_hours'] && 0 === A118::refund_hours() );
check( 'mail types refund_request_received / refund_request_rejected are registered', null !== PassCaptain\Mail\Mailer::type( 'refund_request_received' ) && isset( PassCaptain\Mail\Mailer::type( 'refund_request_received' )['placeholders']['portal_link'] ) && null !== PassCaptain\Mail\Mailer::type( 'refund_request_rejected' ) );

echo "A118: ticket PDF in the order portal\n";
$a118_rotations = array();
$a118_rot_cb = static function ( $id, $code, $reason = '' ) use ( &$a118_rotations ) { $a118_rotations[] = array( (int) $id, (string) $reason ); };
add_action( 'wpcap_ticket_code_rotated', $a118_rot_cb, 99, 3 );
$a118_occ = ex_occ( ex_event( 'A118 Konzert' ), 0, 168 );
$a118_type = ex_type( $a118_occ, 'Standard', 2500 );
list( $a118_o, $a118_t ) = ex_buy( $a118_type, 3, 'a118@example.com' );
list( $a118_pub, $a118_tok ) = a118_access( $a118_o );
list( $a118_o2, $a118_t2 ) = ex_buy( $a118_type, 1, 'a118-other@example.com' );
$a118_foreign = a118_public( $a118_t2[0] );
$a118_foreign_hash = a118_hashes( $a118_t2 );

$r = A118::pdf( $a118_pub, str_repeat( 'a', 64 ), 'all' );
check( 'wrong access token → 403, nothing rotated', 'wpcap_order_access_denied' === a118_code( $r ) && 403 === ( $r->get_error_data()['status'] ?? 0 ) && ! $a118_rotations );
check( 'expired-looking/malformed order id → 403', 'wpcap_order_access_denied' === a118_code( A118::pdf( 'zz', $a118_tok, 'all' ) ) );
$r = A118::pdf( $a118_pub, $a118_tok, $a118_foreign );
check( 'ticket of another order → 404 (same as unknown), its code untouched', 'wpcap_ticket_missing' === a118_code( $r ) && 404 === ( $r->get_error_data()['status'] ?? 0 ) && $a118_foreign_hash === a118_hashes( $a118_t2 ) );

$before = a118_hashes( $a118_t );
$r = A118::pdf( $a118_pub, $a118_tok, 'all' );
$after = a118_hashes( $a118_t );
check( 'all tickets: one PDF with three pages', is_array( $r ) && 0 === strpos( $r['content'], '%PDF-1.4' ) && 3 === $r['tickets'] && 3 === substr_count( $r['content'], '/Type /Page ' ) && str_starts_with( $r['filename'], 'tickets-' ) && str_ends_with( $r['filename'], '.pdf' ) );
check( 'codes are stored hashed only → new codes for the PDF (previous ones invalid), rotation hook reason portal_pdf', is_array( $r ) && 3 === $r['new_codes'] && ! array_intersect_assoc( $before, $after ) && 3 === count( array_filter( $a118_rotations, static fn( $x ) => 'portal_pdf' === $x[1] ) ) );
check( 'other orders are never touched by the download', $a118_foreign_hash === a118_hashes( $a118_t2 ) );
check( 'download is audited (portal_ticket_pdf)', 1 === a118_audit( 'portal_ticket_pdf', $a118_o ) );

// A module that still knows the current code (wallet store) avoids a new code.
$a118_known = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $a118_known ) ), array( 'ticket_id' => $a118_t[0] ) );
$a118_known_cb = static function ( $code, $id ) use ( $a118_known, $a118_t ) { return (int) $id === $a118_t[0] ? $a118_known : ( (int) $id === $a118_t[1] ? str_repeat( 'b', 64 ) : $code ); };
add_filter( 'wpcap_ticket_known_code', $a118_known_cb, 10, 2 );
$a118_rotations = array();
$h1 = a118_hashes( array( $a118_t[1] ) );
$r = A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[0] ) );
check( 'single ticket with a known current code: PDF with that code, no rotation', is_array( $r ) && 1 === $r['tickets'] && 0 === $r['new_codes'] && false !== strpos( $r['content'], substr( $a118_known, 0, 32 ) ) && hash( 'sha256', $a118_known ) === a118_hashes( array( $a118_t[0] ) )[ $a118_t[0] ] && ! $a118_rotations && str_starts_with( $r['filename'], 'ticket-' ) );
$r = A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[1] ) );
check( 'a "known" code that does not match the stored hash is ignored (new code instead)', is_array( $r ) && 1 === $r['new_codes'] && $h1 !== a118_hashes( array( $a118_t[1] ) ) );
remove_filter( 'wpcap_ticket_known_code', $a118_known_cb, 10 );

// Revoked/refunded, checked-in and passed-on tickets are not downloadable and are marked.
$a118_ref = Refunds::request( $a118_o, array( $a118_t[2] ), 'Admin', 'a118-admin-refund' );
check( 'setup: admin refund of one ticket completed', is_array( $a118_ref ) && 'completed' === $a118_ref['status'] );
$r = A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[2] ) );
check( 'refunded ticket → 409, not downloadable', 'wpcap_ticket_pdf_void' === a118_code( $r ) && 409 === ( $r->get_error_data()['status'] ?? 0 ) );
$r = A118::pdf( $a118_pub, $a118_tok, 'all' );
check( '"all" leaves the refunded ticket out', is_array( $r ) && 2 === $r['tickets'] );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a118_t[0] ) );
check( 'checked-in ticket → 409 used', 'wpcap_ticket_pdf_used' === a118_code( A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[0] ) ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid', 'transfer_count' => 1 ), array( 'ticket_id' => $a118_t[0] ) );
check( 'transferred ticket → 409 held by someone else (the buyer cannot take the recipient\'s code)', 'wpcap_ticket_pdf_held_elsewhere' === a118_code( A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[0] ) ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 0, 'attendee_email' => 'guest@example.com' ), array( 'ticket_id' => $a118_t[0] ) );
check( 'ticket assigned to another e-mail → 409 held by someone else', 'wpcap_ticket_pdf_held_elsewhere' === a118_code( A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[0] ) ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => 'A118@example.com' ), array( 'ticket_id' => $a118_t[0] ) );
check( 'attendee e-mail = buyer e-mail (any case) stays downloadable', is_array( A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[0] ) ) ) );
$a118_rows = A118::tickets( Orders::get( $a118_o ) );
$a118_by = array();
foreach ( $a118_rows as $row ) { $a118_by[ (int) $row->ticket_id ] = $row; }
$a118_html_void = A118::ticket_html( $a118_by[ $a118_t[2] ], Orders::get( $a118_o ), $a118_tok );
$a118_html_ok = A118::ticket_html( $a118_by[ $a118_t[1] ], Orders::get( $a118_o ), $a118_tok );
check( 'portal marks the refunded ticket and offers no download for it', false !== strpos( $a118_html_void, 'storniert bzw. erstattet' ) && false === strpos( $a118_html_void, 'wpcap_portal_pdf' ) );
check( 'portal offers a nonce-protected POST download for a valid ticket', false !== strpos( $a118_html_ok, 'method="post"' ) && false !== strpos( $a118_html_ok, 'name="_wpnonce"' ) && false !== strpos( $a118_html_ok, 'value="' . a118_public( $a118_t[1] ) . '"' ) && false !== strpos( $a118_html_ok, 'data-new-codes="1"' ) );
$a118_portal = A118::portal_html( Orders::get( $a118_o ), $a118_tok, $a118_rows );
check( 'portal card: "all tickets" download, refund history, no other order\'s ticket', false !== strpos( $a118_portal, 'value="all"' ) && false !== strpos( $a118_portal, 'Stornierungen und Erstattungen' ) && false === strpos( $a118_portal, $a118_foreign ) );
check( 'request form hidden while the feature is off', false === strpos( $a118_portal, 'wpcap-refund-form' ) );

list( $a118_ou, ) = array( 0 );
$GLOBALS['wpcap_test_transients'] = array();
$a118_unpaid = Orders::create( array( 'email' => 'a118-unpaid@example.com', 'items' => array( array( 'ticket_type_id' => $a118_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a118-unpaid-' . uniqid() ) );
list( $a118_upub, $a118_utok ) = a118_access( (int) $a118_unpaid['order_id'] );
check( 'unpaid order → 409, no PDF', 'wpcap_order_not_paid' === a118_code( A118::pdf( $a118_upub, $a118_utok, 'all' ) ) );

$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_filters']['wpcap_portal_pdf_rate_limit'] = 1;
$r1 = A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[1] ) );
$r2 = A118::pdf( $a118_pub, $a118_tok, a118_public( $a118_t[1] ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_portal_pdf_rate_limit'] );
check( 'downloads are rate limited per order (429)', is_array( $r1 ) && 'wpcap_rate_limited' === a118_code( $r2 ) && 429 === ( $r2->get_error_data()['status'] ?? 0 ) );
$GLOBALS['wpcap_test_transients'] = array();
remove_action( 'wpcap_ticket_code_rotated', $a118_rot_cb, 99 );

echo "A118: cancellation requests through the refund approval workflow\n";
list( $b_o, $b_t ) = ex_buy( $a118_type, 3, 'a118-cancel@example.com' );
list( $b_pub, $b_tok ) = a118_access( $b_o );
$b_key = 'k' . bin2hex( random_bytes( 12 ) );
$r = A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[0] ) ), 'idempotency_key' => $b_key ) );
check( 'feature off → 409 and no refund row', 'wpcap_refund_request_disabled' === a118_code( $r ) && ! Refunds::for_order( $b_o ) );

Settings::update( array( 'portal_refund_request_hours' => 24 ) );
add_action( 'wpcap_refund_rejected', array( A118::class, 'on_refund_rejected' ), 10, 2 );
$a118_hook = array();
$a118_hook_cb = static function ( $refund_id, $order_id ) use ( &$a118_hook ) { $a118_hook[] = array( (int) $refund_id, (int) $order_id ); };
add_action( 'wpcap_portal_refund_requested', $a118_hook_cb, 10, 2 );
check( 'wrong access token → 403', 'wpcap_order_access_denied' === a118_code( A118::request_refund( $b_pub, str_repeat( 'c', 64 ), array( 'ticket_public_ids' => array( a118_public( $b_t[0] ) ), 'idempotency_key' => $b_key ) ) ) );
check( 'missing/short idempotency key → 400 (reload)', 'wpcap_refund_key' === a118_code( A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[0] ) ), 'idempotency_key' => 'x' ) ) ) );
check( 'no ticket selected → 400', 'wpcap_refund_tickets' === a118_code( A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array(), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) );
check( 'ticket of another order → 404, no refund created', 'wpcap_ticket_missing' === a118_code( A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[0] ), $a118_foreign ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) && ! Refunds::for_order( $b_o ) && ! Refunds::for_order( $a118_o2 ) );

$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$r = A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[0] ), a118_public( $b_t[1] ) ), 'reason' => "Krank <b>geworden</b>\n", 'idempotency_key' => $b_key ) );
$b_rows = Refunds::for_order( $b_o );
$b_refund = $b_rows[0] ?? array();
check( 'request creates ONE refund in pending_approval even below the approval threshold (0 = none)', is_array( $r ) && 'pending_approval' === $r['status'] && 1 === count( $b_rows ) && 'pending_approval' === $b_refund['status'] && 5000 === (int) $b_refund['amount_minor'] && 2 === $r['ticket_count'] );
check( 'request: source portal, requested by nobody (any approver may decide), reason sanitised', 'portal' === $b_refund['source'] && 0 === (int) $b_refund['requested_by'] && 0 === (int) $b_refund['approved_by'] && 'Krank geworden' === $b_refund['reason'] );
check( 'tickets stay valid until the request is approved', 2 === count( array_filter( tickets( $b_o ), static fn( $t ) => in_array( (int) $t->ticket_id, array( $b_t[0], $b_t[1] ), true ) && 'valid' === $t->status ) ) );
$b_mails = array_slice( $GLOBALS['wpcap_test_mails'], $mails_before );
$b_buyer = array_values( array_filter( $b_mails, static fn( $m ) => 'a118-cancel@example.com' === $m['to'] ) );
$b_admin = array_values( array_filter( $b_mails, static fn( $m ) => 'admin@example.com' === $m['to'] ) );
check( 'buyer gets the confirmation mail (refund_request_received) with amount, tickets and portal link', 1 === count( $b_buyer ) && false !== strpos( $b_buyer[0]['subject'], 'Stornierungsanfrage' ) && false !== strpos( $b_buyer[0]['body'], '50,00 EUR' ) && false !== strpos( $b_buyer[0]['body'], 'A118 Konzert — Standard' ) && false !== strpos( $b_buyer[0]['body'], 'wpcap_access=' . $b_tok ) );
check( 'admins are notified and see the request in the approval queue', 1 === count( $b_admin ) && false !== strpos( $b_admin[0]['subject'], 'Stornierungsanfrage' ) && in_array( (int) $b_refund['refund_id'], array_map( static fn( $x ) => (int) $x->refund_id, PassCaptain\Admin\Queries::pending_refunds( null ) ), true ) );
check( 'request audited and announced (wpcap_portal_refund_requested)', 1 === a118_audit( 'refund_requested_by_customer', $b_o ) && array( array( (int) $b_refund['refund_id'], $b_o ) ) === $a118_hook );

$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$again = A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[0] ), a118_public( $b_t[1] ) ), 'idempotency_key' => $b_key ) );
check( 'double submit (same form key) returns the same request, no second row or mail', is_array( $again ) && ! empty( $again['reused'] ) && 1 === count( Refunds::for_order( $b_o ) ) && count( $GLOBALS['wpcap_test_mails'] ) === $mails_before );
$dup = A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[1] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) );
check( 'second request for a ticket with an open request → 409', 'wpcap_refund_ticket_requested' === a118_code( $dup ) && 1 === count( Refunds::for_order( $b_o ) ) );

$b_portal = A118::portal_html( Orders::get( $b_o ), $b_tok, A118::tickets( Orders::get( $b_o ) ) );
check( 'portal shows the open request and offers only the remaining ticket', false !== strpos( $b_portal, 'Angefragt – wird geprüft' ) && false !== strpos( $b_portal, 'wpcap-refund-form' ) && 1 === substr_count( $b_portal, 'name="ticket_public_ids[]"' ) && false !== strpos( $b_portal, 'value="' . a118_public( $b_t[2] ) . '"' ) );

// REST: nonce required.
$req = new WP_REST_Request( 'POST', '/pass-captain/v1/orders/' . $b_pub . '/refund-request' );
$req->set_param( 'public_id', $b_pub );
$req->set_body( wp_json_encode( array( 'access_token' => $b_tok, 'nonce' => 'bad', 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ), 'ticket_public_ids' => array( a118_public( $b_t[2] ) ) ) ) );
$rest = A118::rest_refund_request( $req );
check( 'REST endpoint rejects a missing/invalid nonce (403)', 'wpcap_nonce' === a118_code( $rest ) && 1 === count( Refunds::for_order( $b_o ) ) );

// Approval executes the refund through the normal workflow.
$approved = Refunds::approve( (int) $b_refund['refund_id'] );
check( 'approver releases the request: refund completed, tickets refunded and revoked', is_array( $approved ) && 'completed' === $approved['status'] && 2 === count( array_filter( tickets( $b_o ), static fn( $t ) => in_array( (int) $t->ticket_id, array( $b_t[0], $b_t[1] ), true ) && 'revoked' === $t->status ) ) );
check( 'portal status: "Erstattet"; PDF only for the remaining ticket', false !== strpos( A118::portal_html( Orders::get( $b_o ), $b_tok, A118::tickets( Orders::get( $b_o ) ) ), 'Erstattet' ) && 1 === ( A118::pdf( $b_pub, $b_tok, 'all' )['tickets'] ?? 0 ) );

// Rejection informs the buyer, tickets stay valid.
$GLOBALS['wpcap_test_transients'] = array();
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$rej = A118::request_refund( $b_pub, $b_tok, array( 'ticket_public_ids' => array( a118_public( $b_t[2] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) );
$rejected = is_array( $rej ) ? Refunds::reject( (int) Refunds::for_order( $b_o )[1]['refund_id'], 'Keine Kulanz' ) : null;
$rej_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'a118-cancel@example.com' === $m['to'] && false !== strpos( $m['body'], 'nicht angenommen' ) ) );
check( 'rejected request: buyer informed (refund_request_rejected, no internal reason), ticket stays valid', is_array( $rejected ) && 'rejected' === $rejected['status'] && 1 === count( $rej_mail ) && false === strpos( $rej_mail[0]['body'], 'Keine Kulanz' ) && 'valid' === ex_ticket( $b_t[2] )->status );
check( 'after a rejection the ticket can be requested again', false !== strpos( A118::portal_html( Orders::get( $b_o ), $b_tok, A118::tickets( Orders::get( $b_o ) ) ), 'value="' . a118_public( $b_t[2] ) . '"' ) );

echo "A118: cancellation rules (deadline, used tickets, comps)\n";
$c_occ = ex_occ( ex_event( 'A118 bald' ), 0, 10 );
$c_type = ex_type( $c_occ, 'Bald', 1500 );
list( $c_o, $c_t ) = ex_buy( $c_type, 1, 'a118-late@example.com' );
list( $c_pub, $c_tok ) = a118_access( $c_o );
check( 'less than X hours before the start → 409 too late', 'wpcap_refund_ticket_too_late' === a118_code( A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( a118_public( $c_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) );
Settings::update( array( 'portal_refund_request_hours' => 2 ) );
$GLOBALS['wpcap_test_transients'] = array();
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $c_t[0] ) );
check( 'checked-in (used) ticket → 409', 'wpcap_refund_ticket_not_valid' === a118_code( A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( a118_public( $c_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => $c_t[0] ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_provider' => 'comp' ), array( 'order_id' => $c_o ) );
check( 'comp order → 409, no request', 'wpcap_refund_request_comp' === a118_code( A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( a118_public( $c_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) && ! Refunds::for_order( $c_o ) );
$free = ex_type( $c_occ, 'Gratis', 0 );
list( $f_o, $f_t ) = ex_buy( $free, 1, 'a118-free@example.com' );
list( $f_pub, $f_tok ) = a118_access( $f_o );
check( 'free order (total 0) → 409 comp', 'wpcap_refund_request_comp' === a118_code( A118::request_refund( $f_pub, $f_tok, array( 'ticket_public_ids' => array( a118_public( $f_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_provider' => 'woocommerce' ), array( 'order_id' => $c_o ) );
check( 'WooCommerce order → 409 (refund in the shop)', 'wpcap_refund_request_woocommerce' === a118_code( A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( a118_public( $c_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) ) ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_provider' => 'manual' ), array( 'order_id' => $c_o ) );
$GLOBALS['wpcap_test_filters']['wpcap_portal_refund_rate_limit'] = 1;
$GLOBALS['wpcap_test_transients'] = array();
$rl1 = A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( 'f' . str_repeat( '0', 31 ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) );
$rl2 = A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( a118_public( $c_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_portal_refund_rate_limit'] );
check( 'requests are rate limited per order (429)', 'wpcap_ticket_missing' === a118_code( $rl1 ) && 'wpcap_rate_limited' === a118_code( $rl2 ) );
$GLOBALS['wpcap_test_transients'] = array();
$ok = A118::request_refund( $c_pub, $c_tok, array( 'ticket_public_ids' => array( a118_public( $c_t[0] ) ), 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ) ) );
check( 'within the deadline a paid ticket can be requested', is_array( $ok ) && 'pending_approval' === $ok['status'] );
remove_action( 'wpcap_portal_refund_requested', $a118_hook_cb, 10 );
remove_action( 'wpcap_refund_rejected', array( A118::class, 'on_refund_rejected' ), 10 );

echo "A118: order link from the ticket page\n";
$GLOBALS['wpcap_test_transients'] = array();
list( $d_o, $d_t ) = ex_buy( $a118_type, 2, 'a118-link@example.com' );
$d_codes = array();
foreach ( $d_t as $id ) {
	$code = bin2hex( random_bytes( 32 ) );
	$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $code ) ), array( 'ticket_id' => $id ) );
	$d_codes[ $id ] = $code;
}
$d_hash = (string) Orders::get( $d_o )->access_token_hash;
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$sent = A118::send_order_link( $d_codes[ $d_t[0] ] );
$d_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'a118-link@example.com' === $m['to'] ) );
check( 'ticket page sends a new order link to the buyer (masked address shown), old link invalid', 'a***@example.com' === $sent && 1 === count( $d_mail ) && false !== strpos( $d_mail[0]['body'], 'wpcap_order=' . Orders::get( $d_o )->public_id ) && $d_hash !== (string) Orders::get( $d_o )->access_token_hash && 1 === a118_audit( 'order_link_requested', $d_o ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 1 ), array( 'ticket_id' => $d_t[1] ) );
check( 'not offered for a ticket passed on to someone else', 'wpcap_order_link_unavailable' === a118_code( A118::send_order_link( $d_codes[ $d_t[1] ] ) ) );
check( 'unknown ticket code → error', is_wp_error( A118::send_order_link( str_repeat( 'd', 64 ) ) ) );
A118::send_order_link( $d_codes[ $d_t[0] ] );
A118::send_order_link( $d_codes[ $d_t[0] ] );
check( 'order link mails are rate limited (3 per day and order)', 'wpcap_rate_limited' === a118_code( A118::send_order_link( $d_codes[ $d_t[0] ] ) ) );
$GLOBALS['wpcap_test_transients'] = array();

update_option( Settings::OPTION, $a118_saved );
Settings::flush();
