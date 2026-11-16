<?php
/**
 * A622: ticket holders (e.g. transfer recipients) can request new links by e-mail: confirmation link first
 * (no enumeration, rate limited, single use), then the holder's valid future tickets are reissued.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\TicketRecovery;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }

echo "A622: ticket link recovery for holders\n";
global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();
$a622_type = make_type( 30, 10, 2000 );
$a622_order = Orders::create( array( 'email' => 'a622-buyer-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a622_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a622-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A622: fixture order created', ! is_wp_error( $a622_order ) );
if ( ! is_wp_error( $a622_order ) ) {
	$a622_oid = (int) $a622_order['order_id'];
	Orders::confirm_external_payment( $a622_oid, 'a622-ref' );
	$a622_tickets = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $a622_oid ) ) );
	$a622_holder = 'a622-holder-' . uniqid() . '@example.com';
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET attendee_email=%s WHERE ticket_id=%d", $a622_holder, $a622_tickets[0] ) );
	$a622_hash_before = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[0] ) );
	$a622_other_before = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[1] ) );
	check( 'A622: only the holder address finds the ticket (buyer tickets are not served)', array( $a622_tickets[0] ) === TicketRecovery::ticket_ids( $a622_holder ) && array() === TicketRecovery::ticket_ids( 'nobody-' . uniqid() . '@example.com' ) );

	$GLOBALS['wpcap_test_mails'] = array();
	check( 'A622: an unknown address gets the same answer and no mail', true === TicketRecovery::request( 'nobody-' . uniqid() . '@example.com' ) && array() === $GLOBALS['wpcap_test_mails'] );
	check( 'A622: an invalid address is refused', is_wp_error( TicketRecovery::request( 'not-an-address' ) ) );
	$GLOBALS['wpcap_test_mails'] = array();
	check( 'A622: the holder gets a confirmation mail', true === TicketRecovery::request( strtoupper( $a622_holder ) ) && 1 === count( $GLOBALS['wpcap_test_mails'] ) && $a622_holder === $GLOBALS['wpcap_test_mails'][0]['to'] );
	preg_match( '/wpcap_recover=([a-f0-9]{48})/', (string) ( $GLOBALS['wpcap_test_mails'][0]['body'] ?? '' ), $a622_m );
	$a622_id = $a622_m[1] ?? '';
	check( 'A622: the mail carries a confirmation link, the ticket is unchanged until confirmed', 48 === strlen( $a622_id ) && $a622_hash_before === (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[0] ) ) );
	$a622_page = TicketRecovery::page( $a622_id );
	check( 'A622: opening the link only shows a confirmation button (link scanners change nothing)', false !== strpos( $a622_page, 'Neue Ticketlinks senden' ) && false !== strpos( $a622_page, 'method="post"' ) && $a622_hash_before === (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[0] ) ) );

	$GLOBALS['wpcap_test_mails'] = array();
	$a622_result = TicketRecovery::confirm( $a622_id );
	$a622_hash_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[0] ) );
	check( 'A622: confirming reissues the holder ticket and mails the new link to the holder', is_array( $a622_result ) && 1 === $a622_result['reissued'] && $a622_hash_after !== $a622_hash_before && in_array( $a622_holder, array_column( $GLOBALS['wpcap_test_mails'], 'to' ), true ) );
	check( 'A622: the buyer ticket keeps its code', $a622_other_before === (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[1] ) ) );
	check( 'A622: the confirmation link works only once', is_wp_error( TicketRecovery::confirm( $a622_id ) ) && 'wpcap_recover_expired' === TicketRecovery::confirm( $a622_id )->get_error_code() );
	check( 'A622: a made-up id is refused', is_wp_error( TicketRecovery::confirm( str_repeat( 'ab', 24 ) ) ) );

	// At most 3 mails per address and day; the answer stays the same.
	$GLOBALS['wpcap_test_mails'] = array();
	TicketRecovery::request( $a622_holder );
	TicketRecovery::request( $a622_holder );
	$a622_fourth = TicketRecovery::request( $a622_holder );
	check( 'A622: the per-address limit stops further mails silently', true === $a622_fourth && 2 === count( $GLOBALS['wpcap_test_mails'] ) );

	// Admission has begun: the ticket is left alone.
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.starts_at=%s WHERE tt.ticket_type_id=%d", gmdate( 'Y-m-d H:i:s', time() - 600 ), $a622_type ) );
	wpcap_test_reset_transients();
	$GLOBALS['wpcap_test_mails'] = array();
	TicketRecovery::request( $a622_holder );
	preg_match( '/wpcap_recover=([a-f0-9]{48})/', (string) ( $GLOBALS['wpcap_test_mails'][0]['body'] ?? '' ), $a622_m2 );
	$a622_late = isset( $a622_m2[1] ) ? TicketRecovery::confirm( $a622_m2[1] ) : null;
	check( 'A622: after admission began the code is not rotated (staff use the help code)', is_array( $a622_late ) && 0 === $a622_late['reissued'] && 1 === $a622_late['skipped'] && $a622_hash_after === (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a622_tickets[0] ) ) );
}
$a622_form = TicketRecovery::page( '1' );
check( 'A622: the request form has a nonce, a honeypot and the e-mail field', false !== strpos( $a622_form, 'name="_wpnonce"' ) && false !== strpos( $a622_form, 'name="website"' ) && false !== strpos( $a622_form, 'type="email"' ) );
$a622_spam = TicketRecovery::page( '1', array( '_wpnonce' => wp_create_nonce( 'wpcap_ticket_recover' ), 'website' => 'http://spam', 'email' => 'x@example.com' ) );
check( 'A622: a filled honeypot is refused', false === strpos( $a622_spam, TicketRecovery::generic_message() ) );
check( 'A622: an unknown ticket link points to the recovery page', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' ), 'TicketRecovery::url()' ) );
wpcap_test_reset_transients();
