<?php
/**
 * A144: assignments below the event – per occurrence (and capability), per entry area and per
 * service (A133 provider records) – honoured by every occurrence-level access check, plus the
 * admin page "Zuweisungen".
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Access;
use PassCaptain\StaffAssignments;
use PassCaptain\Admin\AssignmentsPage;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\Queries;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }

global $wpdb;
$p = $wpdb->prefix;
$wpa144_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$wpa144_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpa144_render = static function ( callable $render, array $get = array() ) {
	$saved = $_GET;
	$_GET = $get;
	ob_start();
	try { $render(); $html = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $html = 'EXCEPTION ' . get_class( $e ) . ' ' . $e->getMessage(); }
	$_GET = $saved;
	return $html;
};

echo "A144: occurrence assignments narrow the event team\n";
// Event with two occurrences (Früh, Spät), each with a paid order and ticket codes.
$wpa144_setup = static function () use ( $wpdb, $p ) {
	$s = adm_setup();
	$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ) ), array( 'occurrence_id' => $s['occ'] ) );
	$s['order'] = (int) $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=" . (int) $s['occ'] . ' ORDER BY t.ticket_id DESC LIMIT 1' );
	$s['ticket'] = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $s['order'] . ' ORDER BY ticket_id LIMIT 1' );
	return $s;
};
$wpa144_o1 = $wpa144_setup();
$wpa144_o2 = $wpa144_setup();
$wpa144_event = $wpa144_o1['event'];
$wpdb->update( "{$p}wpcap_occurrences", array( 'event_id' => $wpa144_event ), array( 'occurrence_id' => $wpa144_o2['occ'] ) );
check( 'setup: one event, two occurrences with paid orders and ticket codes', $wpa144_o1['order'] && $wpa144_o2['order'] && $wpa144_o1['codes'] && $wpa144_o2['codes'] );

$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 9150, 'wpa144door', 'door144@example.com' );
$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 9151, 'wpa144outsider', 'outsider144@example.com' );
$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 9152, 'wpa144manager', 'manager144@example.com' );
foreach ( array( 'wpcap_checkin', 'wpcap_manage_orders', 'wpcap_view_reports' ) as $wpa144_cap ) {
	$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $wpa144_event, 'user_id' => 9150, 'capability' => $wpa144_cap ) );
}
StaffAssignments::flush();

$wpa144_add = static function ( $user, $cap, $occ ) { return AssignmentsPage::add( array( 'scope' => 'occurrence', 'object_id' => $occ, 'user' => $user, 'capability' => $cap ) ); };
$wpa144_a1 = $wpa144_add( 'door144@example.com', 'wpcap_checkin', $wpa144_o1['occ'] );
check( 'administrator limits the door person to the early occurrence for check-in (audited)', is_int( $wpa144_a1 ) && $wpa144_a1 > 0 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='staff_assignment_added' AND object_type='occurrence' AND object_id=%d", $wpa144_o1['occ'] ) ) > 0 );
$wpa144_not_team = $wpa144_add( 'outsider144@example.com', 'wpcap_checkin', $wpa144_o1['occ'] );
$wpa144_bad_cap = $wpa144_add( 'door144@example.com', 'wpcap_refund', $wpa144_o1['occ'] );
$wpa144_bad_scope = AssignmentsPage::add( array( 'scope' => 'event', 'object_id' => $wpa144_event, 'user' => 'door144@example.com' ) );
check( 'occurrence assignment needs an event-team entry for the capability; unknown capability/scope refused', is_wp_error( $wpa144_not_team ) && 'wpcap_assignment_team' === $wpa144_not_team->get_error_code() && is_wp_error( $wpa144_bad_cap ) && is_wp_error( $wpa144_bad_scope ) );

// Act as the door person (non-administrator).
$GLOBALS['wpcap_test_user_id'] = 9150;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
StaffAssignments::flush();
check( 'check-in reaches the assigned occurrence only; orders (not limited) still both', Access::can_access_occurrence( $wpa144_o1['occ'], 'wpcap_checkin' ) && ! Access::can_access_occurrence( $wpa144_o2['occ'], 'wpcap_checkin' ) && Access::can_access_occurrence( $wpa144_o2['occ'], 'wpcap_manage_orders' ) && Access::can_access_event( $wpa144_event, 'wpcap_checkin' ) );
$wpa144_scan = static function ( $code ) {
	$req = new WP_REST_Request( 'POST' );
	$req->set_param( 'token', $code );
	return PassCaptain\Rest\Controller::check_in( $req );
};
$wpa144_in2 = $wpa144_scan( $wpa144_o2['codes'][0] );
$wpa144_in1 = $wpa144_scan( $wpa144_o1['codes'][0] );
check( 'REST scanner: ticket of the other occurrence refused as forbidden, assigned occurrence admitted', is_wp_error( $wpa144_in2 ) && 'forbidden' === ( $wpa144_in2->get_error_data()['result'] ?? '' ) && ! is_wp_error( $wpa144_in1 ) && 'checked_in' === ( $wpa144_in1->get_data()['status'] ?? '' ) );
check( 'capability-free checks: union of team capabilities (orders unlimited → both occurrences visible)', Access::occurrence_in_scope( $wpa144_o2['occ'] ) && ! Access::occurrence_in_scope( $wpa144_o2['occ'], 0, 'wpcap_checkin' ) && '' === Access::occurrence_scope_sql( 'o' ) );

// Limit orders, too.
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpa144_add( 'door144@example.com', 'wpcap_manage_orders', $wpa144_o1['occ'] );
$GLOBALS['wpcap_test_user_id'] = 9150;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
StaffAssignments::flush();
check( 'orders: only the order of the assigned occurrence', Queries::can_access_order( $wpa144_o1['order'], 'wpcap_manage_orders' ) && ! Queries::can_access_order( $wpa144_o2['order'], 'wpcap_manage_orders' ) );
$wpa144_resend = PassCaptain\Admin\OrdersPage::resend_tickets( array( 'order_id' => $wpa144_o2['order'] ) );
check( 'admin-post order action on the other occurrence refused (403)', is_wp_error( $wpa144_resend ) && 403 === ( $wpa144_resend->get_error_data()['status'] ?? 0 ) );
$wpa144_ticket_undo = PassCaptain\Admin\TicketsPage::undo_checkin( array( 'ticket_id' => $wpa144_o2['ticket'] ) );
check( 'admin-post ticket action on the other occurrence refused (403)', is_wp_error( $wpa144_ticket_undo ) && 403 === ( $wpa144_ticket_undo->get_error_data()['status'] ?? 0 ) );
check( 'reports not limited yet: capability-free list filter stays off (union of team capabilities)', '' === Access::occurrence_scope_sql( 'o' ) );
// Limit reports as well: now every team capability is limited → lists and event-wide figures narrow.
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpa144_add( 'door144@example.com', 'wpcap_view_reports', $wpa144_o1['occ'] );
$GLOBALS['wpcap_test_user_id'] = 9150;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
StaffAssignments::flush();
$wpa144_orders = array_map( static fn( $o ) => (int) $o->order_id, Queries::search_orders( array(), array( $wpa144_event ), 1, 500 )['rows'] );
$wpa144_tickets = array_map( static fn( $t ) => (int) $t->ticket_id, Queries::search_tickets( array(), array( $wpa144_event ), 1, 500 )['rows'] );
check( 'all team capabilities limited: order and ticket lists show the assigned occurrence only', in_array( $wpa144_o1['order'], $wpa144_orders, true ) && ! in_array( $wpa144_o2['order'], $wpa144_orders, true ) && in_array( $wpa144_o1['ticket'], $wpa144_tickets, true ) && ! in_array( $wpa144_o2['ticket'], $wpa144_tickets, true ) );
$wpa144_rep = new WP_REST_Request( 'GET' );
$wpa144_rep->set_param( 'event_id', $wpa144_event );
check( 'event-wide report refused, settlement sheet and attendee list only for the assigned occurrence', ! PassCaptain\Rest\Controller::report_permission( $wpa144_rep ) && ! Access::event_fully_in_scope( $wpa144_event ) && PassCaptain\Reports\Settlement::can_view( $wpa144_o1['occ'] ) && ! PassCaptain\Reports\Settlement::can_view( $wpa144_o2['occ'] ) && '' === PassCaptain\Printing\AttendeeList::access_level( $wpa144_o2['occ'] ) );
check( 'the door person cannot manage assignments', 403 === AssignmentsPage::add( array( 'scope' => 'occurrence', 'object_id' => $wpa144_o2['occ'], 'user' => 'door144@example.com', 'capability' => 'wpcap_checkin' ) )->get_error_data()['status'] && 403 === AssignmentsPage::remove( array( 'assignment_id' => $wpa144_a1 ) )->get_error_data()['status'] );

echo "A144: event managers limited to an occurrence, lifecycle, admin page\n";
// Event manager limited to the early occurrence for wpcap_manage_events.
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $wpa144_event, 'user_id' => 9152, 'capability' => 'wpcap_manage_events' ) );
StaffAssignments::flush();
$wpa144_add( 'manager144@example.com', 'wpcap_manage_events', $wpa144_o1['occ'] );
$GLOBALS['wpcap_test_user_id'] = 9152;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
StaffAssignments::flush();
$wpa144_m_other = $wpa144_add( 'door144@example.com', 'wpcap_checkin', $wpa144_o2['occ'] );
$wpa144_m_edit = EventsPage::save_occurrence( array( 'occurrence_id' => $wpa144_o2['occ'], 'timezone' => 'Europe/Vienna', 'starts_at' => '2031-01-01T20:00', 'location' => 'X' ) );
check( 'limited event manager: no assignments or edits for the other occurrence (403)', is_wp_error( $wpa144_m_other ) && 403 === $wpa144_m_other->get_error_data()['status'] && is_wp_error( $wpa144_m_edit ) && 403 === ( $wpa144_m_edit->get_error_data()['status'] ?? 0 ) );
check( 'limited event manager may still remove an assignment of the assigned occurrence', true === AssignmentsPage::remove( array( 'assignment_id' => $wpa144_a1 ) ) && ! StaffAssignments::get( $wpa144_a1 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpa144_add( 'door144@example.com', 'wpcap_checkin', $wpa144_o1['occ'] );
// Removing the event-team entry removes the narrowing assignments of that capability.
EventsPage::remove_staff( array( 'event_id' => $wpa144_event, 'user_id' => 9150, 'capability' => 'wpcap_checkin' ) );
check( 'removing the event-team entry removes its occurrence assignments (other capabilities stay)', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_staff_assignments WHERE user_id=9150 AND capability='wpcap_checkin'" ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_staff_assignments WHERE user_id=9150 AND scope='occurrence'" ) );
check( 'administrators are never limited by assignments', Access::can_access_occurrence( $wpa144_o2['occ'], 'wpcap_manage_orders' ) && Access::event_fully_in_scope( $wpa144_event ) && '' === Access::occurrence_scope_sql( 'o' ) );

// Entry areas (admission module) through the same page.
if ( AssignmentsPage::areas_available() ) {
	$wpa144_area_a = PassCaptain\Admission\Areas::save( $wpa144_event, 'Haupteingang' );
	$wpa144_area_b = PassCaptain\Admission\Areas::save( $wpa144_event, 'Backstage' );
	$wpa144_area_add = AssignmentsPage::add_area( array( 'area_id' => $wpa144_area_a, 'user' => 'door144@example.com' ) );
	check( 'entry area assignment through the page (admission area staff, audited)', ! is_wp_error( $wpa144_area_add ) && array( (int) $wpa144_area_a ) === PassCaptain\Admission\Areas::user_area_ids( 9150, $wpa144_event ) );
	$GLOBALS['wpcap_test_user_id'] = 9150;
	$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
	$wpa144_found = array( 'event_id' => $wpa144_event, 'ticket_type_id' => $wpa144_o1['type'] );
	check( 'area assignment is enforced at the scan (other area and no area refused)', '' === PassCaptain\Admission\Areas::check( $wpa144_found, $wpa144_area_a ) && 'wrong_area' === PassCaptain\Admission\Areas::check( $wpa144_found, $wpa144_area_b ) && 'wrong_area' === PassCaptain\Admission\Areas::check( $wpa144_found, 0 ) );
	$wpa144_area_forbidden = AssignmentsPage::add_area( array( 'area_id' => $wpa144_area_b, 'user' => 'door144@example.com' ) );
	check( 'people without event management cannot assign areas', is_wp_error( $wpa144_area_forbidden ) && 403 === $wpa144_area_forbidden->get_error_data()['status'] );
	$GLOBALS['wpcap_test_user_id'] = 1;
	$GLOBALS['wpcap_test_denied_caps'] = array();
	AssignmentsPage::remove_area( array( 'area_id' => $wpa144_area_a, 'user_id' => 9150 ) );
	check( 'area assignment removed', array() === PassCaptain\Admission\Areas::user_area_ids( 9150, $wpa144_event ) );
}

// Services: the page shows the A133 provider records per resource (managed on Service-Zeitfenster).
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $wpa144_event, 'name' => 'Garderobe', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$wpa144_res = (int) $wpdb->insert_id;
$wpa144_pid = PassCaptain\ServiceProviders::save( array( 'name' => 'Garderobier Gert', 'email' => 'gert@example.com', 'user_id' => 9153 ) );
check( 'service assignment uses the provider records (A133), no parallel resource scope', true === PassCaptain\ServiceProviders::assign( $wpa144_res, array( $wpa144_pid ) ) && ! isset( StaffAssignments::SCOPES['resource'] ) && is_wp_error( AssignmentsPage::add( array( 'scope' => 'resource', 'object_id' => $wpa144_res, 'user' => 'door144@example.com' ) ) ) );
$wpa144_page = $wpa144_render( array( AssignmentsPage::class, 'render' ), array( 'event_id' => (string) $wpa144_event ) );
check( 'page "Zuweisungen": team overview, occurrence, area and service sections with forms', false !== strpos( $wpa144_page, 'Event-Team' ) && false !== strpos( $wpa144_page, 'wpcap_assignment_add' ) && false !== strpos( $wpa144_page, 'wpcap_assignment_remove' ) && false !== strpos( $wpa144_page, 'Garderobe' ) && false !== strpos( $wpa144_page, 'Garderobier Gert' ) && ( ! AssignmentsPage::areas_available() || false !== strpos( $wpa144_page, 'wpcap_assignment_area_add' ) ) && false === strpos( $wpa144_page, 'EXCEPTION' ) );
check( 'menu: page and admin-post actions registered', in_array( AssignmentsPage::SLUG, array_column( Menu::entries(), 0 ), true ) && isset( Menu::handlers()['wpcap_assignment_add'], Menu::handlers()['wpcap_assignment_remove'], Menu::handlers()['wpcap_assignment_area_add'], Menu::handlers()['wpcap_assignment_area_remove'] ) );
$_POST = array( 'scope' => 'occurrence', 'object_id' => (string) $wpa144_o2['occ'], 'user' => 'door144@example.com', 'capability' => 'wpcap_checkin', 'event_id' => (string) $wpa144_event );
$GLOBALS['wpcap_test_user_id'] = 9150;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$wpa144_died = false;
try { AssignmentsPage::handle_add(); } catch ( Wpcap_Test_Die $e ) { $wpa144_died = true; } catch ( Wpcap_Test_Redirect $e ) { $wpa144_died = false; }
$_POST = array();
check( 'admin-post handler refuses people without event management (403)', $wpa144_died );

$GLOBALS['wpcap_test_user_id'] = $wpa144_saved_user ?? 1;
$GLOBALS['wpcap_test_denied_caps'] = $wpa144_saved_denied;
StaffAssignments::flush();
