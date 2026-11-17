<?php
// phpcs:ignoreFile
/**
 * A533: "Tickets erneut senden" carries a form key; a double submit (double click, reload of the POST)
 * rotates the codes and mails the tickets only once. The key is checked and recorded under the order
 * lock; a new form (new key) resends again.
 */
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;

echo "A533: resend tickets – double submit\n";
global $wpdb;
$p = $wpdb->prefix;
$a533_type = make_type( 10, 10, 0 );
$a533_r = Orders::create( array( 'email' => 'a533@example.com', 'items' => array( array( 'ticket_type_id' => $a533_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a533-' . uniqid() ) );
$a533_order = (int) $a533_r['order_id'];
$a533_hashes = static fn() => $wpdb->get_col( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $a533_order ) );
$a533_mails = static fn() => count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'a533@example.com' === $m['to'] ) );

$a533_key = wp_generate_uuid4();
$a533_h0 = $a533_hashes();
$a533_m0 = $a533_mails();
$a533_first = OrdersPage::resend_tickets( array( 'order_id' => $a533_order, 'codes' => 'new', 'request_key' => $a533_key ) );
$a533_h1 = $a533_hashes();
check( 'A533: the first submit rotates the codes and mails once', ! is_wp_error( $a533_first ) && $a533_h1 !== $a533_h0 && $a533_mails() === $a533_m0 + 1 );
$a533_second = OrdersPage::resend_tickets( array( 'order_id' => $a533_order, 'codes' => 'new', 'request_key' => $a533_key ) );
check( 'A533: the same form submitted again changes nothing (codes from the first mail stay valid)', is_wp_error( $a533_second ) && 'wpcap_resend_duplicate' === $a533_second->get_error_code() && $a533_hashes() === $a533_h1 && $a533_mails() === $a533_m0 + 1 );
check( 'A533: the request is recorded on the order (audit marker)', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='tickets_resend_request' AND object_id=%d", $a533_order ) ) );
$a533_third = OrdersPage::resend_tickets( array( 'order_id' => $a533_order, 'codes' => 'new', 'request_key' => wp_generate_uuid4() ) );
check( 'A533: a new form (new key) resends again', ! is_wp_error( $a533_third ) && $a533_hashes() !== $a533_h1 && $a533_mails() === $a533_m0 + 2 );

// A failed resend does not use up the key.
$a533_key2 = wp_generate_uuid4();
$a533_h3 = $a533_hashes();
wpcap_test_fault( "/UPDATE \\S*wpcap_tickets\\S* SET \\S*token_hash/", 'lock_timeout', array( 'rollback' => true ) );
$a533_failed = OrdersPage::resend_tickets( array( 'order_id' => $a533_order, 'codes' => 'new', 'request_key' => $a533_key2 ) );
wpcap_test_faults_clear();
$a533_retry = OrdersPage::resend_tickets( array( 'order_id' => $a533_order, 'codes' => 'new', 'request_key' => $a533_key2 ) );
check( 'A533: after a failed resend the same form can be sent again', is_wp_error( $a533_failed ) && ! is_wp_error( $a533_retry ) && $a533_hashes() !== $a533_h3 );
check( 'A533: callers without a key (jobs, API) keep working', ! is_wp_error( OrdersPage::resend_tickets( array( 'order_id' => $a533_order, 'codes' => 'keep' ) ) ) );
