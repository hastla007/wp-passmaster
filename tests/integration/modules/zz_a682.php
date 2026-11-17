<?php
/**
 * A682: missing attendee data is requested specifically before/at dispatch – the ticket mail names
 * per ticket what is still missing, the admin order view and the order portal show it per ticket,
 * and an admin action mails the buyer the list with a new order link (at most once per hour,
 * audited). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;
use PassCaptain\ParticipantFields;

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }

echo "A682: missing attendee data is named per ticket and requested\n";
$x6m_type = make_type( 10, 10, 0 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name_required' => 1 ), array( 'ticket_type_id' => $x6m_type ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $x6m_type, 'field_key' => 'shirt', 'label' => 'T-Shirt', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
$x6m_shirt = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $x6m_type, 'field_key' => 'note', 'label' => 'Notiz', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
if ( PassCaptain\Consents::ready() ) {
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $x6m_type, 'field_key' => 'photo', 'label' => 'Fotos', 'field_type' => 'checkbox', 'required' => 1, 'consent' => 1, 'purpose' => 'Fotos', 'status' => 'active' ) );
}
// Data is collected later (as with waitlist offers or orders created without attendee data).
$x6m_mails = count( $GLOBALS['wpcap_test_mails'] );
$x6m_o = Orders::create( array( 'email' => 'x6m-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $x6m_type, 'quantity' => 2 ) ), 'attendees' => array( array( 'ticket_type_id' => $x6m_type, 'name' => 'Ada', 'answers' => array( 'shirt' => 'M' ) ) ), 'idempotency_key' => 'x6m-' . uniqid() ), array( 'defer_attendees' => true ) );
check( 'A682: an order with deferred attendee data is accepted', is_array( $x6m_o ) );
Orders::confirm_manual_payment( $x6m_o['order_id'] );
$x6m_tickets = tickets( $x6m_o['order_id'] );
$x6m_ids = array_map( static fn( $t ) => (int) $t->ticket_id, $x6m_tickets );
$x6m_missing = ParticipantFields::missing( $x6m_ids );
check( 'A682: missing() names the gaps per ticket (name and required fields; consents and optional fields never)', 1 === count( $x6m_missing ) && array( 'Name', 'T-Shirt' ) === ( $x6m_missing[ $x6m_ids[1] ] ?? null ) );
$x6m_ticket_mail = null;
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $x6m_mails ) as $x6m_m ) { if ( 'x6m-buyer@example.com' === $x6m_m['to'] && str_starts_with( $x6m_m['subject'], 'Deine Tickets' ) ) { $x6m_ticket_mail = $x6m_m; } }
check( 'A682: the ticket mail names the missing data once, at the ticket that lacks it', $x6m_ticket_mail && 1 === substr_count( $x6m_ticket_mail['body'], 'Noch fehlende Angaben: Name, T-Shirt' ) && false !== strpos( (string) $x6m_ticket_mail['html'], 'Noch fehlende Angaben: Name, T-Shirt' ) );
check( 'A682: the gap is audited without personal data', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='attendee_data_missing' AND object_id=%d AND details NOT LIKE %s", $x6m_o['order_id'], '%x6m-buyer%' ) ) );

// Admin order view and portal show it per ticket.
$GLOBALS['wpcap_test_get'] = $_GET;
ob_start();
$_GET = array( 'page' => 'wpcap-orders', 'order_id' => (string) $x6m_o['order_id'] );
try { OrdersPage::render(); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
$x6m_admin = ob_get_clean();
$_GET = $GLOBALS['wpcap_test_get'];
check( 'A682: the admin order view shows "Fehlt: Name, T-Shirt" and offers the request action', false !== strpos( $x6m_admin, 'Fehlt:' ) && false !== strpos( $x6m_admin, 'Name, T-Shirt' ) && false !== strpos( $x6m_admin, 'wpcap_request_attendee_data' ) );

$x6m_hash = (string) Orders::get( $x6m_o['order_id'] )->access_token_hash;
$x6m_mails = count( $GLOBALS['wpcap_test_mails'] );
$x6m_r = OrdersPage::request_attendee_data( array( 'order_id' => $x6m_o['order_id'] ) );
$x6m_req = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $x6m_mails ), static fn( $m ) => 'x6m-buyer@example.com' === $m['to'] ) );
check( 'A682: the request mails the buyer the missing data per ticket with a new order link', is_array( $x6m_r ) && 1 === $x6m_r['tickets'] && $x6m_r['sent'] && 1 === count( $x6m_req ) && false !== strpos( $x6m_req[0]['body'], 'Name, T-Shirt' ) && false !== strpos( $x6m_req[0]['body'], 'wpcap_access=' ) && $x6m_hash !== (string) Orders::get( $x6m_o['order_id'] )->access_token_hash );
preg_match( '/wpcap_access=([a-f0-9]{64})/', $x6m_req[0]['body'] ?? '', $x6m_tok );
check( 'A682: the link in the request opens the order (new access token)', ! empty( $x6m_tok[1] ) && hash_equals( (string) Orders::get( $x6m_o['order_id'] )->access_token_hash, hash( 'sha256', $x6m_tok[1] ) ) );
$x6m_again = OrdersPage::request_attendee_data( array( 'order_id' => $x6m_o['order_id'] ) );
check( 'A682: a second request within the hour is refused (429)', is_wp_error( $x6m_again ) && 'wpcap_attendee_request_recent' === $x6m_again->get_error_code() );
check( 'A682: the request is audited without personal data', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='attendee_data_requested' AND object_id=%d AND details NOT LIKE %s", $x6m_o['order_id'], '%@%' ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$x6m_denied = OrdersPage::request_attendee_data( array( 'order_id' => $x6m_o['order_id'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A682: without the orders capability nothing is sent', is_wp_error( $x6m_denied ) && 403 === ( $x6m_denied->get_error_data()['status'] ?? 0 ) );

// Portal: the buyer sees what is missing per ticket.
$x6m_portal = PassCaptain\OrderPortal::render_page( Orders::get( $x6m_o['order_id'] ), $x6m_tok[1] ?? '' );
check( 'A682: the order portal shows per ticket what is still missing', false !== strpos( $x6m_portal, 'Für dieses Ticket fehlen noch: Name, T-Shirt' ) );

// Filled in: nothing is missing any more, a request is refused.
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,'x6m-buyer@example.com','','Bo','assigned',%s) ON DUPLICATE KEY UPDATE attendee_name='Bo'", $x6m_ids[1], gmdate( 'Y-m-d H:i:s' ) ) );
ParticipantFields::save_answer( $x6m_ids[1], $x6m_shirt, 'L' );
check( 'A682: once name and field are given nothing is missing', array() === ParticipantFields::missing( $x6m_ids ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_audit_logs WHERE action='attendee_data_requested' AND object_id=%d", $x6m_o['order_id'] ) );
$x6m_done = ParticipantFields::request_missing( $x6m_o['order_id'] );
check( 'A682: a complete order is not asked again', is_wp_error( $x6m_done ) && 'wpcap_attendee_data_complete' === $x6m_done->get_error_code() );
check( 'A682: the request mail type is registered with its placeholders', isset( PassCaptain\Mail\Types::all()['attendee_data_request']['placeholders']['missing_list'] ) );
