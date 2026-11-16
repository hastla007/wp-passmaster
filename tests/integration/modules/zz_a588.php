<?php
/**
 * A588: recovery of a ticket mail no longer depends on the plaintext codes of the first mail. The
 * retry of a failed ticket mail delivers the stored codes (TicketCodes, A586) instead of new ones, a
 * retry after a partly failed resend does not invalidate codes other recipients already received,
 * and staff can resend the stored codes ("Gespeicherte Codes erneut senden").
 * Included from run.php (uses check(), make_type(), tickets(), ticket_code_for()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;
use PassCaptain\TicketAssignments;
use PassCaptain\TicketCodes;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }

echo "A588: ticket mail recovery with the stored codes\n";
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\ServiceBookings::flush_schema_cache();
TicketCodes::register();
$a588_hashes = static function ( $order_id ) { global $wpdb; return $wpdb->get_col( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=" . (int) $order_id . ' ORDER BY ticket_id' ); };
$a588_codes_in = static function ( $to ) {
	$out = array();
	foreach ( $GLOBALS['wpcap_test_mails'] as $m ) {
		if ( $to === $m['to'] && preg_match_all( '/wpcap_ticket=([a-f0-9]{64})/', (string) $m['body'], $mm ) ) { $out = array_merge( $out, $mm[1] ); }
	}
	return array_values( array_unique( $out ) );
};

// 1. The first ticket mail fails: the retry delivers the same codes.
$a588_type = make_type( 30, 10, 0 );
$a588_block = static function ( $message, $to = '' ) { return false; };
add_filter( 'wpcap_mail', $a588_block, 10, 2 );
$a588_o = Orders::create( array( 'email' => 'a588@example.com', 'items' => array( array( 'ticket_type_id' => $a588_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a588-' . uniqid() ) );
remove_filter( 'wpcap_mail', $a588_block, 10 );
$a588_before = $a588_hashes( $a588_o['order_id'] );
// Merged design: the first delivery runs through the ticket outbox (ticket_delivery job, retried by the queue);
// a failed resend queues ticket_mail_retry.
$a588_job = $wpdb->get_row( $wpdb->prepare( "SELECT job_id,job_type,payload FROM {$p}wpcap_jobs WHERE job_type IN ('ticket_mail_retry','ticket_delivery') AND status='queued' AND payload LIKE %s ORDER BY job_id DESC LIMIT 1", '%"order_id":' . (int) $a588_o['order_id'] . '%' ) );
check( 'A588 fixture: failed first mail, codes stored, retry queued', $a588_job && 2 === count( $a588_before ) && '' !== TicketCodes::get( (int) tickets( $a588_o['order_id'] )[0]->ticket_id ) );
$GLOBALS['wpcap_test_mails'] = array();
$a588_retry = Orders::handle_job( false, (string) $a588_job->job_type, json_decode( (string) $a588_job->payload, true ), (int) $a588_job->job_id );
$a588_sent = $a588_codes_in( 'a588@example.com' );
check( 'A588: the retry delivers the codes of the failed mail (no new codes)', true === $a588_retry && $a588_before === $a588_hashes( $a588_o['order_id'] ) && 2 === count( $a588_sent ) && ! array_diff( array_map( static fn( $c ) => hash( 'sha256', $c ), $a588_sent ), $a588_before ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='done' WHERE job_id=%d", (int) $a588_job->job_id ) );

// 2. A resend reaches the buyer but not the attendee: the retry keeps the buyer's delivered codes.
$a588_o2 = Orders::create( array( 'email' => 'a588b@example.com', 'items' => array( array( 'ticket_type_id' => $a588_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a588b-' . uniqid() ) );
$a588_t2 = tickets( $a588_o2['order_id'] );
$a588_token = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $a588_token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $a588_o2['order_id'] ) );
$a588_assigned = TicketAssignments::assign( Orders::get( $a588_o2['order_id'] )->public_id, $a588_token, $a588_t2[1]->public_id, 'Gast', 'gast588@example.com' );
$a588_only_attendee = static function ( $message, $to = '' ) { return 'gast588@example.com' === $to ? false : $message; };
add_filter( 'wpcap_mail', $a588_only_attendee, 10, 2 );
$GLOBALS['wpcap_test_mails'] = array();
$a588_partial = Orders::resend_tickets( $a588_o2['order_id'] );
remove_filter( 'wpcap_mail', $a588_only_attendee, 10 );
$a588_buyer_codes = $a588_codes_in( 'a588b@example.com' );
$a588_after_resend = $a588_hashes( $a588_o2['order_id'] );
$a588_job2 = $wpdb->get_row( $wpdb->prepare( "SELECT job_id,payload FROM {$p}wpcap_jobs WHERE job_type='ticket_mail_retry' AND status='queued' AND payload LIKE %s ORDER BY job_id DESC LIMIT 1", '%"order_id":' . (int) $a588_o2['order_id'] . '%' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET created_at=%s WHERE job_id=%d", gmdate( 'Y-m-d H:i:s', time() + 3600 ), (int) $a588_job2->job_id ) );
$GLOBALS['wpcap_test_mails'] = array();
$a588_retry2 = Orders::handle_job( false, 'ticket_mail_retry', json_decode( (string) $a588_job2->payload, true ), (int) $a588_job2->job_id );
check( 'A588: after a partly failed resend the retry keeps the codes the buyer already received', ! is_wp_error( $a588_assigned ) && is_wp_error( $a588_partial ) && 1 === count( $a588_buyer_codes ) && true === $a588_retry2 && $a588_after_resend === $a588_hashes( $a588_o2['order_id'] ) && in_array( hash( 'sha256', $a588_buyer_codes[0] ), $a588_hashes( $a588_o2['order_id'] ), true ) && 1 === count( $a588_codes_in( 'gast588@example.com' ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='done' WHERE job_id=%d", (int) $a588_job2->job_id ) );

// 3. Staff: resend the stored codes or issue new ones.
$a588_h = $a588_hashes( $a588_o['order_id'] );
$a588_keep = OrdersPage::resend_tickets( array( 'order_id' => $a588_o['order_id'], 'codes' => 'keep' ) );
check( 'A588: admin "Gespeicherte Codes erneut senden" keeps every code', is_array( $a588_keep ) && 'keep' === $a588_keep['codes'] && $a588_h === $a588_hashes( $a588_o['order_id'] ) );
$a588_t1 = tickets( $a588_o['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET code_secret=NULL WHERE ticket_id=" . (int) $a588_t1[1]->ticket_id );
OrdersPage::resend_tickets( array( 'order_id' => $a588_o['order_id'], 'codes' => 'keep' ) );
$a588_h2 = $a588_hashes( $a588_o['order_id'] );
check( 'A588: a ticket without a stored code still gets a new one', $a588_h[0] === $a588_h2[0] && $a588_h[1] !== $a588_h2[1] );
OrdersPage::resend_tickets( array( 'order_id' => $a588_o['order_id'] ) );
$a588_h3 = $a588_hashes( $a588_o['order_id'] );
check( 'A588: default (codes "new") still issues new codes', $a588_h2[0] !== $a588_h3[0] && $a588_h2[1] !== $a588_h3[1] );
ob_start();
$a588_m = new ReflectionMethod( OrdersPage::class, 'render_resend_form' );
$a588_m->setAccessible( true );
$a588_m->invoke( null, Orders::get( $a588_o['order_id'] ) );
$a588_form = (string) ob_get_clean();
check( 'A588: the resend form offers "keep stored codes" (preselected) and "new codes"', 1 === preg_match( '/name="codes" value="keep" checked/', $a588_form ) && false !== strpos( $a588_form, 'name="codes" value="new"' ) );

// Later tests run without the store (they assert the hash-only behaviour of their own era).
remove_filter( 'wpcap_ticket_mail', array( TicketCodes::class, 'capture' ), 0 );
remove_action( 'wpcap_ticket_code_rotated', array( TicketCodes::class, 'remember' ), 5 );
remove_action( 'wpcap_ticket_code_issued', array( TicketCodes::class, 'remember' ), 5 );
remove_action( 'wpcap_ticket_revoked', array( TicketCodes::class, 'forget' ), 5 );
remove_filter( 'wpcap_ticket_known_code', array( TicketCodes::class, 'known_code' ), 5 );
