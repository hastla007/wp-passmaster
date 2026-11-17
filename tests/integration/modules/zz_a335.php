<?php
/**
 * A335: "Tickets erneut senden" in the admin leaves tickets passed on to another person alone by
 * default; including them is a deliberate choice. A failed mail's retry keeps that scope, and the
 * WooCommerce/Voucher Captain resends do not rotate transferred tickets either.
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A335: admin resend keeps the codes of transferred tickets unless chosen deliberately\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

$a335_type = make_type( 20, 10, 1500 );
$a335_order = admin_paid_order( $a335_type, 2, 'a335-buyer@example.com' );
$a335_ids = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $a335_order ) );
$a335_hash = static fn( $id ) => (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $id ) );
// Ticket 2 was passed on to a friend (portal transfer: new code at the recipient's address).
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 1, 'attendee_email' => 'a335-friend@example.com' ), array( 'ticket_id' => $a335_ids[1] ) );
check( 'resend counts: 2 valid, 1 transferred', array( 'valid' => 2, 'transferred' => 1 ) === Orders::resend_counts( $a335_order ) );

$a335_h0 = $a335_hash( $a335_ids[0] );
$a335_h1 = $a335_hash( $a335_ids[1] );
$GLOBALS['wpcap_test_mails'] = array();
$a335_default = OrdersPage::resend_tickets( array( 'order_id' => $a335_order ) );
check( 'default resend: own ticket gets a new code, the transferred ticket keeps its code', is_array( $a335_default ) && $a335_h0 !== $a335_hash( $a335_ids[0] ) && $a335_h1 === $a335_hash( $a335_ids[1] ) );
check( 'default resend: result reports 1 sent / 1 kept and no mail goes to the recipient', 1 === ( $a335_default['sent'] ?? null ) && 1 === ( $a335_default['kept'] ?? null ) && ! array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'a335-friend@example.com' === $m['to'] ) );
$a335_audit = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='tickets_resent' AND object_id=%d ORDER BY audit_id DESC LIMIT 1", $a335_order ) );
check( 'default resend: audit records the scope', false !== strpos( (string) $a335_audit, 'skip_transferred' ) );

$a335_h0 = $a335_hash( $a335_ids[0] );
$GLOBALS['wpcap_test_mails'] = array();
$a335_all = OrdersPage::resend_tickets( array( 'order_id' => $a335_order, 'scope' => 'all' ) );
check( 'deliberate resend incl. transferred: both codes change, recipient gets the new code', is_array( $a335_all ) && 2 === $a335_all['sent'] && 0 === $a335_all['kept'] && $a335_h0 !== $a335_hash( $a335_ids[0] ) && $a335_h1 !== $a335_hash( $a335_ids[1] ) && array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'a335-friend@example.com' === $m['to'] ) );

// Only transferred tickets left: the default is refused with an explanation, nothing rotates.
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 1 ), array( 'ticket_id' => $a335_ids[0] ) );
$a335_h0 = $a335_hash( $a335_ids[0] );
$a335_h1 = $a335_hash( $a335_ids[1] );
$a335_only = OrdersPage::resend_tickets( array( 'order_id' => $a335_order ) );
check( 'only transferred tickets: default resend refused, no code changed', is_wp_error( $a335_only ) && 'wpcap_resend_only_transferred' === $a335_only->get_error_code() && $a335_h0 === $a335_hash( $a335_ids[0] ) && $a335_h1 === $a335_hash( $a335_ids[1] ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 0 ), array( 'ticket_id' => $a335_ids[0] ) );

// A failed mail: the retry job keeps the scope and still leaves the transferred ticket alone.
$a335_block = static function () { return false; };
add_filter( 'wpcap_mail', $a335_block );
$a335_fail = OrdersPage::resend_tickets( array( 'order_id' => $a335_order ) );
remove_filter( 'wpcap_mail', $a335_block );
$a335_job = $wpdb->get_row( $wpdb->prepare( "SELECT job_id,payload FROM {$p}wpcap_jobs WHERE job_type='ticket_mail_retry' AND payload LIKE %s ORDER BY job_id DESC LIMIT 1", '%"order_id":' . $a335_order . '%' ) );
check( 'failed mail: retry job payload carries skip_transferred', is_wp_error( $a335_fail ) && $a335_job && array( 'order_id' => $a335_order, 'skip_transferred' => true ) === json_decode( (string) $a335_job->payload, true ) );
$a335_h1 = $a335_hash( $a335_ids[1] );
$a335_h0 = $a335_hash( $a335_ids[0] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET created_at=%s WHERE job_id=%d", gmdate( 'Y-m-d H:i:s', time() + 3600 ), (int) $a335_job->job_id ) );
$a335_retry = Orders::handle_job( false, 'ticket_mail_retry', json_decode( (string) $a335_job->payload, true ), (int) $a335_job->job_id );
check( 'retry job: rotates the own ticket only', true === $a335_retry && $a335_h0 !== $a335_hash( $a335_ids[0] ) && $a335_h1 === $a335_hash( $a335_ids[1] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='done' WHERE job_id=%d", (int) $a335_job->job_id ) );

// Rendering: the form defaults to "own" and offers the deliberate choice.
ob_start();
$a335_m = new ReflectionMethod( OrdersPage::class, 'render_resend_form' );
$a335_m->setAccessible( true );
$a335_m->invoke( null, Orders::get( $a335_order ) );
$a335_html = ob_get_clean();
check( 'resend form: "own" preselected, "all" offered with its consequence', 1 === preg_match( '/name="scope" value="own" checked/', $a335_html ) && false !== strpos( $a335_html, 'value="all"' ) && false !== strpos( $a335_html, 'Auch übertragene Tickets' ) );

// Other staff/buyer resends: WooCommerce order action and Voucher Captain exchange resend.
$a335_src = file_get_contents( WPCAP_PATH . 'src/Integrations/WooCommerce/OrderAdapter.php' ) . file_get_contents( WPCAP_PATH . 'src/Integrations/Vouchers/TicketService.php' );
check( 'WooCommerce and Voucher Captain resends skip transferred tickets', 2 === substr_count( $a335_src, "array( 'skip_transferred' => true )" ) );
