<?php
/**
 * A586: the current ticket code is stored encrypted, so repeated portal downloads do not rotate the
 * codes (also without the wallet module), and staff get a "Ticket-PDF" per order/ticket. Included
 * from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;
use PassCaptain\TicketCodes;

echo "A586: stored ticket codes, idempotent portal PDF, admin ticket PDF\n";
global $wpdb;
$p = $wpdb->prefix;
TicketCodes::register();
$a586_rotations = array();
add_action( 'wpcap_ticket_code_rotated', $a586_rot = static function ( $id, $code, $reason = '' ) use ( &$a586_rotations ) { $a586_rotations[] = (int) $id; }, 99, 3 );
$a586_hashes = static function ( $order_id ) { global $wpdb; return $wpdb->get_col( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=" . (int) $order_id . ' ORDER BY ticket_id' ); };
check( 'A586: schema 3.10.17 column wpcap_tickets.code_secret exists', TicketCodes::ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.17', '>=' ) );

$a586_type = make_type( 10, 10, 0 );
$a586_r = Orders::create( array( 'email' => 'a586@example.com', 'items' => array( array( 'ticket_type_id' => $a586_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a586-1' ) );
$a586_codes = ticket_code_for( $a586_r['order_id'] );
$a586_t = tickets( $a586_r['order_id'] );
$a586_box = (string) $wpdb->get_var( "SELECT code_secret FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $a586_t[0]->ticket_id );
check( 'A586: codes stored encrypted at issuance (no plaintext in the database)', 2 === count( $a586_codes ) && '' !== $a586_box && false === strpos( $a586_box, $a586_codes[0] ) && in_array( TicketCodes::get( (int) $a586_t[0]->ticket_id ), $a586_codes, true ) );
$a586_before = $a586_hashes( $a586_r['order_id'] );

// Order portal access.
$a586_token = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $a586_token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $a586_r['order_id'] ) );
$a586_public = (string) $wpdb->get_var( 'SELECT public_id FROM ' . $p . 'wpcap_orders WHERE order_id=' . (int) $a586_r['order_id'] );
$GLOBALS['wpcap_test_transients'] = array();
check( 'A586: portal no longer warns about new codes', false === PortalSelfService::pdf_needs_new_codes( $a586_t ) );
$a586_pdf1 = PortalSelfService::pdf( $a586_public, $a586_token, 'all' );
$a586_pdf2 = PortalSelfService::pdf( $a586_public, $a586_token, 'all' );
check( 'A586: two portal downloads keep the codes (no rotation, mailed codes stay valid)', is_array( $a586_pdf1 ) && is_array( $a586_pdf2 ) && 0 === $a586_pdf1['new_codes'] && 0 === $a586_pdf2['new_codes'] && $a586_before === $a586_hashes( $a586_r['order_id'] ) && ! $a586_rotations );
check( 'A586: the PDF carries the mailed code', is_array( $a586_pdf1 ) && false !== strpos( wpcap_pdf_plain( $a586_pdf1['content'] ), substr( $a586_codes[0], 0, 32 ) ) );

// A ticket whose code is unknown (issued before the update) is rotated once, then stored.
$wpdb->query( "UPDATE {$p}wpcap_tickets SET code_secret=NULL WHERE ticket_id=" . (int) $a586_t[1]->ticket_id );
$a586_pdf3 = PortalSelfService::pdf( $a586_public, $a586_token, $a586_t[1]->public_id );
$a586_mid = $a586_hashes( $a586_r['order_id'] );
$a586_pdf4 = PortalSelfService::pdf( $a586_public, $a586_token, $a586_t[1]->public_id );
check( 'A586: unknown code → one new code, the next download reuses it', is_array( $a586_pdf3 ) && 1 === $a586_pdf3['new_codes'] && is_array( $a586_pdf4 ) && 0 === $a586_pdf4['new_codes'] && $a586_mid === $a586_hashes( $a586_r['order_id'] ) && array( (int) $a586_t[1]->ticket_id ) === $a586_rotations );

// Context binding: a box copied to another ticket does not open.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET code_secret=%s WHERE ticket_id=%d", $a586_box, (int) $a586_t[1]->ticket_id ) );
check( 'A586: a box copied to another ticket is rejected', '' === TicketCodes::get( (int) $a586_t[1]->ticket_id ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET code_secret=NULL WHERE ticket_id=" . (int) $a586_t[1]->ticket_id );

// Admin "Ticket-PDF".
$a586_rotations = array();
$a586_admin = OrdersPage::ticket_pdf( array( 'order_id' => $a586_r['order_id'] ) );
check( 'A586: admin ticket PDF for the order uses the stored codes; ticket without code counted', is_array( $a586_admin ) && 1 === $a586_admin['tickets'] && 1 === $a586_admin['missing'] && false !== strpos( wpcap_pdf_plain( $a586_admin['content'] ), substr( $a586_codes[0], 0, 32 ) ) && ! $a586_rotations && str_starts_with( $a586_admin['filename'], 'tickets-' ) );
$a586_single = OrdersPage::ticket_pdf( array( 'order_id' => $a586_r['order_id'], 'ticket' => $a586_t[0]->public_id ) );
check( 'A586: admin ticket PDF for one ticket', is_array( $a586_single ) && 1 === $a586_single['tickets'] && str_starts_with( $a586_single['filename'], 'ticket-' ) );
$a586_none = OrdersPage::ticket_pdf( array( 'order_id' => $a586_r['order_id'], 'ticket' => $a586_t[1]->public_id ) );
check( 'A586: ticket without stored code → error naming "Tickets erneut senden", nothing rotated', is_wp_error( $a586_none ) && 'wpcap_ticket_pdf_no_code' === $a586_none->get_error_code() && false !== strpos( $a586_none->get_error_message(), 'erneut senden' ) && ! $a586_rotations );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders', 'manage_options' );
check( 'A586: admin ticket PDF needs order permissions', is_wp_error( OrdersPage::ticket_pdf( array( 'order_id' => $a586_r['order_id'] ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A586: audit entry for the admin download', (bool) $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='admin_ticket_pdf' AND object_id=" . (int) $a586_r['order_id'] ) );

// Revocation clears the stored code; the filter switches the storage off.
PassCaptain\Admin\TicketsPage::revoke_ticket( array( 'ticket_id' => (int) $a586_t[0]->ticket_id, 'reason' => 'Test' ) );
check( 'A586: revoked ticket keeps no stored code', null === $wpdb->get_var( "SELECT code_secret FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $a586_t[0]->ticket_id ) );
$GLOBALS['wpcap_test_filters']['wpcap_store_ticket_codes'] = false;
$a586_r2 = Orders::create( array( 'email' => 'a586b@example.com', 'items' => array( array( 'ticket_type_id' => $a586_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a586-2' ) );
check( 'A586: wpcap_store_ticket_codes=false keeps hashes only', null === $wpdb->get_var( "SELECT code_secret FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a586_r2['order_id'] ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_store_ticket_codes'] );

// Later tests run without the store (they assert the hash-only behaviour of their own era).
remove_filter( 'wpcap_ticket_mail', array( TicketCodes::class, 'capture' ), 0 );
remove_action( 'wpcap_ticket_code_rotated', array( TicketCodes::class, 'remember' ), 5 );
remove_action( 'wpcap_ticket_code_issued', array( TicketCodes::class, 'remember' ), 5 );
remove_action( 'wpcap_ticket_revoked', array( TicketCodes::class, 'forget' ), 5 );
remove_filter( 'wpcap_ticket_known_code', array( TicketCodes::class, 'known_code' ), 5 );
remove_action( 'wpcap_ticket_code_rotated', $a586_rot, 99 );
