<?php
/**
 * Runtime proof (TODO "Laufzeitnachweise für die bestätigten Befunde", role negative tests for the
 * ticket admin page): the rendered list and its free-text search for door staff (wpcap_checkin
 * only), for staff of another event and for reports-only accounts. The Pii rules themselves are
 * unit-tested in zz_a119.php; this module checks the actual page output and search results.
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( 'wp_die: ' . $m ); } }

global $wpdb;
$p = $wpdb->prefix;
echo "Tickets page: role negative tests (door staff, foreign event, reports only)\n";

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$pbt_type = make_type( 10, 10, 0 );
$pbt_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $pbt_type ) );
$pbt_order = admin_paid_order( $pbt_type, 2, 'pbdoor.buyer@example.com' );
$pbt_tickets = Orders::tickets_for_order( $pbt_order );
$pbt_tid = (int) $pbt_tickets[0]->ticket_id;
$pbt_pub = (string) $pbt_tickets[0]->public_id;
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $pbt_tid, 'buyer_email' => 'pbdoor.buyer@example.com', 'attendee_email' => 'berta.quxly@example.com', 'attendee_name' => 'Berta Quxly', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
// A second event the door staff is not assigned to.
$pbt_other_type = make_type( 10, 10, 0 );
$pbt_other_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $pbt_other_type ) );
$pbt_other_order = admin_paid_order( $pbt_other_type, 1, 'pbother.buyer@example.com' );
$pbt_other_pub = (string) Orders::tickets_for_order( $pbt_other_order )[0]->public_id;

$pbt_all_caps = array( 'manage_options', 'wpcap_manage_orders', 'wpcap_manage_events', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_view_finance', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_service_bookings', 'wpcap_service_provider' );
$pbt_as = static function ( $user_id, array $caps, callable $fn ) use ( $pbt_all_caps ) {
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$GLOBALS['wpcap_test_denied_caps'] = array_values( array_diff( $pbt_all_caps, $caps ) );
	try { return $fn(); } finally { $GLOBALS['wpcap_test_denied_caps'] = array(); $GLOBALS['wpcap_test_user_id'] = 1; }
};
$pbt_render = static function ( array $get ) {
	$_GET = array( 'page' => 'wpcap-tickets' ) + $get;
	ob_start();
	try { TicketsPage::render(); } catch ( Throwable $e ) { ob_end_clean(); $_GET = array(); return 'DIED:' . $e->getMessage(); }
	$_GET = array();
	return (string) ob_get_clean();
};
$pbt_found = static function ( $search, $event_id = 0 ) {
	$rows = TicketsPage::search( TicketsPage::filters( array( 's' => $search, 'event_id' => $event_id, 'status' => '', 'occurrence_id' => 0, 'ticket_type_id' => 0, 'orderby' => '' ) ), 1, 50 )['rows'];
	return array_map( static fn( $r ) => (string) $r->public_id, $rows );
};
$pbt_door_user = 9301;
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $pbt_event, 'user_id' => $pbt_door_user, 'capability' => 'wpcap_checkin' ) );

// Control: an order manager sees and finds everything.
Settings::update( array( 'scanner_show_attendee' => 0 ) );
$pbt_admin_html = $pbt_render( array( 'event_id' => (string) $pbt_event ) );
check( 'PB tickets page control: order manager sees buyer e-mail, attendee name and e-mail', false !== strpos( $pbt_admin_html, 'pbdoor.buyer@example.com' ) && false !== strpos( $pbt_admin_html, 'Berta Quxly' ) && false !== strpos( $pbt_admin_html, 'berta.quxly@example.com' ) );
check( 'PB tickets page control: order manager finds the tickets by buyer e-mail and attendee name', in_array( $pbt_pub, $pbt_found( 'pbdoor.buyer@' ), true ) && in_array( $pbt_pub, $pbt_found( 'Quxly' ), true ) );

// Door staff (wpcap_checkin only, assigned to the event), scanner_show_attendee off.
$pbt_door_html = $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_render( array( 'event_id' => (string) $pbt_event ) ) );
check( 'PB door staff: the ticket list of the assigned event renders with the ticket', 0 !== strpos( $pbt_door_html, 'DIED:' ) && false !== strpos( $pbt_door_html, $pbt_pub ) );
check( 'PB door staff: no buyer e-mail, attendee e-mail or attendee name in the page', false === strpos( $pbt_door_html, 'pbdoor.buyer@example.com' ) && false === strpos( $pbt_door_html, 'berta.quxly@example.com' ) && false === strpos( $pbt_door_html, 'Berta Quxly' ) && false === strpos( $pbt_door_html, 'pbdoor.buyer' ) && false === strpos( $pbt_door_html, 'berta.quxly' ) );
check( 'PB door staff: free-text search by e-mail fragment or attendee name finds nothing (no probing of masked data)', array() === $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( 'pbdoor.buyer@' ) ) && array() === $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( 'berta.quxly' ) ) && array() === $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( 'Quxly' ) ) );
check( 'PB door staff: the ticket number still finds the ticket', array( $pbt_pub ) === $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( $pbt_pub ) ) );

// With scanner_show_attendee the attendee name becomes visible and searchable, e-mails stay hidden.
Settings::update( array( 'scanner_show_attendee' => 1 ) );
$pbt_door_named = $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_render( array( 'event_id' => (string) $pbt_event ) ) );
check( 'PB door staff with scanner_show_attendee: attendee name shown, both e-mails still hidden', false !== strpos( $pbt_door_named, 'Berta Quxly' ) && false === strpos( $pbt_door_named, 'pbdoor.buyer@example.com' ) && false === strpos( $pbt_door_named, 'berta.quxly@example.com' ) );
check( 'PB door staff with scanner_show_attendee: search by name finds the ticket, search by e-mail still not', in_array( $pbt_pub, $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( 'Quxly' ) ), true ) && array() === $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( 'pbdoor.buyer@' ) ) );
Settings::update( array( 'scanner_show_attendee' => 0 ) );

// Door staff never sees tickets of an event it is not assigned to.
check( 'PB door staff: tickets of a foreign event are not found by ticket number', array() === $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_found( $pbt_other_pub ) ) );
$pbt_door_foreign = $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => $pbt_render( array( 'event_id' => (string) $pbt_other_event ) ) );
check( 'PB door staff: the list filtered to a foreign event shows none of its tickets or buyers', false === strpos( $pbt_door_foreign, $pbt_other_pub ) && false === strpos( $pbt_door_foreign, 'pbother.buyer' ) );

// An account with only report rights may not open the page at all.
$pbt_reports = $pbt_as( 9302, array( 'wpcap_view_reports' ), static fn() => $pbt_render( array( 'event_id' => (string) $pbt_event ) ) );
check( 'PB reports-only account: the ticket page is refused (no list, no personal data)', 0 === strpos( $pbt_reports, 'DIED:' ) && false === strpos( $pbt_reports, 'pbdoor.buyer' ) );

// The admin actions with personal data stay closed for door staff.
$pbt_fix = $pbt_as( $pbt_door_user, array( 'wpcap_checkin' ), static fn() => TicketsPage::correct_attendee( array( 'ticket_id' => $pbt_tid, 'attendee_name' => 'X', 'attendee_email' => 'x@example.com', 'reason' => 'test' ) ) );
check( 'PB door staff: attendee correction refused (needs wpcap_manage_orders)', is_wp_error( $pbt_fix ) && 'wpcap_admin_forbidden' === $pbt_fix->get_error_code() );
check( 'PB door staff: attendee data unchanged after the refused correction', 'Berta Quxly' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", $pbt_tid ) ) );
