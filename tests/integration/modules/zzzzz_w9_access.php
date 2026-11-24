<?php
/**
 * W9: audit log (roles/capabilities, read access, viewer, export), date-level staff scope, data
 * minimisation per role, shared-account safeguards, MFA for sensitive actions, time-limited support
 * access, check-out role check and re-entry, refunds after check-in, buyer language selection and the
 * multisite network overview with two table prefixes. Included from run.php (after the module tests).
 */
// phpcs:ignoreFile

use PassCaptain\Access;
use PassCaptain\Admin\AuditLogPage;
use PassCaptain\Admin\SecurityScreens;
use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;
use PassCaptain\Security\AuditTrail;
use PassCaptain\Security\DataPolicy;
use PassCaptain\Security\Policy;
require_once __DIR__ . '/zz_r6_stubs.inc'; // esc_url() … for the language switcher.
use PassCaptain\Security\Sessions;
use PassCaptain\Security\StepUp;
use PassCaptain\Security\SupportAccess;

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

echo "W9 access protection: audit, scope, minimisation, MFA, support access, check-out, refunds, language, multisite\n";
global $wpdb;
$p = $wpdb->prefix;

Policy::register();
Settings::flush();

if ( ! class_exists( 'W9_Request' ) ) {
	final class W9_Request {
		public function __construct( private string $method, private string $route, private array $params = array() ) {}
		public function get_route() { return $this->route; }
		public function get_method() { return $this->method; }
		public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	}
}

// w9_* helpers: lib/fixtures_shared.php.
w9_admin();
Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );

/* ------------------------------------------------------------------ 1. Audit: roles and capabilities */

$before = w9_audit_count( 'user_role_changed' );
AuditTrail::on_set_role( 5001, 'wpcap_checkin_staff', array( 'subscriber' ) );
AuditTrail::on_set_role( 5001, 'editor', array( 'author' ) );
$row = w9_last_audit( 'user_role_changed' );
check( 'audit: role change to a plugin role is recorded (other role changes are not)', $before + 1 === w9_audit_count( 'user_role_changed' ) && 5001 === (int) $row->object_id && false !== strpos( $row->details, 'wpcap_checkin_staff' ) && false !== strpos( $row->details, 'subscriber' ) );
AuditTrail::on_set_role( 5001, 'subscriber', array( 'administrator' ) );
check( 'audit: losing the administrator role is recorded', $before + 2 === w9_audit_count( 'user_role_changed' ) );
AuditTrail::on_add_role( 5001, 'wpcap_finance' );
AuditTrail::on_remove_role( 5001, 'wpcap_finance' );
AuditTrail::on_add_role( 5001, 'author' );
check( 'audit: added/removed plugin roles are recorded', 1 === w9_audit_count( 'user_role_added', 5001 ) && 1 === w9_audit_count( 'user_role_removed', 5001 ) );
$diff = AuditTrail::compare_user_caps( 5002, array( 'edit_posts' => true ), array( 'edit_posts' => true, 'wpcap_checkin' => true, 'upload_files' => true ) );
$diff2 = AuditTrail::compare_user_caps( 5002, array( 'wpcap_checkin' => true, 'wpcap_refund' => true ), array( 'wpcap_refund' => false ) );
check( 'audit: direct plugin capability grant/revoke on a user is recorded (WP caps ignored)', array( 'wpcap_checkin' ) === $diff['granted'] && ! $diff['revoked'] && array( 'wpcap_refund', 'wpcap_checkin' ) == $diff2['revoked'] && 1 === w9_audit_count( 'user_capability_granted', 5002 ) && 1 === w9_audit_count( 'user_capability_revoked', 5002 ) );
$changes = AuditTrail::on_roles_option(
	array( 'wpcap_partner' => array( 'capabilities' => array( 'read' => true, 'wpcap_checkin' => true ) ), 'editor' => array( 'capabilities' => array( 'edit_posts' => true ) ) ),
	array( 'wpcap_partner' => array( 'capabilities' => array( 'read' => true, 'wpcap_view_finance' => true ) ), 'editor' => array( 'capabilities' => array( 'edit_posts' => true, 'upload_files' => true ) ), 'wpcap_new' => array( 'capabilities' => array( 'wpcap_checkin' => true ) ) )
);
check( 'audit: capability changes of plugin roles and new roles are recorded', isset( $changes['wpcap_partner'], $changes['wpcap_new'] ) && ! isset( $changes['editor'] ) && array( 'wpcap_view_finance' ) === $changes['wpcap_partner']['granted'] && array( 'wpcap_checkin' ) === $changes['wpcap_partner']['revoked'] && 1 <= w9_audit_count( 'role_capabilities_changed' ) && 1 <= w9_audit_count( 'role_created' ) );

/* ------------------------------------------------------------------ 1b. Audit: read access (rate limited) */

wpcap_test_reset_transients();
$reads_before = w9_audit_count( 'pii_read' );
$first = AuditTrail::read( 'order_detail', 'order', 777 );
$again = AuditTrail::read( 'order_detail', 'order', 777 );
$other = AuditTrail::read( 'order_detail', 'order', 778 );
check( 'read audit: first view logged, repeated view in the window aggregated, other object logged', $first && ! $again && $other && $reads_before + 2 === w9_audit_count( 'pii_read' ) );
$GLOBALS['wpcap_test_user_id'] = 0;
check( 'read audit: anonymous (buyer token) reads are not staff reads', false === AuditTrail::read( 'order_detail', 'order', 779 ) );
w9_admin();
Settings::update( array( 'audit_read_limit_per_hour' => 10 ) );
wpcap_test_reset_transients();
$reads_before = w9_audit_count( 'pii_read' );
$throttled_before = w9_audit_count( 'pii_read_throttled' );
for ( $i = 0; $i < 25; $i++ ) { AuditTrail::read( 'ticket_search', 'event', 9000 + $i ); }
check( 'read audit: at most N entries per person and hour, then one throttle marker (no explosion)', $reads_before + 10 === w9_audit_count( 'pii_read' ) && $throttled_before + 1 === w9_audit_count( 'pii_read_throttled' ) );
Settings::update( array( 'audit_read_limit_per_hour' => 300 ) );
wpcap_test_reset_transients();
$rest_before = w9_audit_count( 'pii_read' );
AuditTrail::on_rest_response( new WP_REST_Response( array( 'status' => 'valid' ) ), null, new W9_Request( 'POST', '/pass-captain/v1/tickets/validate', array( 'occurrence_id' => 42 ) ) );
AuditTrail::on_rest_response( new WP_REST_Response( array() ), null, new W9_Request( 'GET', '/pass-captain/v1/events' ) );
AuditTrail::on_rest_response( new WP_Error( 'x', 'y' ), null, new W9_Request( 'POST', '/pass-captain/v1/app/scan/validate' ) );
check( 'read audit: staff REST reads of ticket data are logged, public routes and errors are not', $rest_before + 1 === w9_audit_count( 'pii_read' ) && false !== strpos( (string) w9_last_audit( 'pii_read' )->details, 'tickets\/validate' ) );
$dl_before = w9_audit_count( 'export_downloaded' );
$pass = AuditTrail::on_download( null, "a;b\n", 'teilnehmer-5.csv', 'text/csv' );
check( 'downloads (exports, attendee lists) are audited without changing delivery', null === $pass && $dl_before + 1 === w9_audit_count( 'export_downloaded' ) && false !== strpos( w9_last_audit( 'export_downloaded' )->details, 'teilnehmer-5.csv' ) );

/* ------------------------------------------------------------------ 1c. Audit-Log viewer and export */

$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 4242, 'action' => 'w9_probe', 'object_type' => 'order', 'object_id' => 31337, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 ) ) );
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 4242, 'action' => 'w9_probe', 'object_type' => 'order', 'object_id' => 31338, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 4243, 'action' => 'w9_probe', 'object_type' => 'event', 'object_id' => 31339, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$f = AuditLogPage::filters( array( 'audit_action' => 'w9_probe' ) );
$all = AuditLogPage::query( $f );
$by_user = AuditLogPage::query( AuditLogPage::filters( array( 'audit_action' => 'w9_probe', 'user_id' => 4242 ) ) );
$by_object = AuditLogPage::query( AuditLogPage::filters( array( 'audit_action' => 'w9_probe', 'object_type' => 'event' ) ) );
$by_id = AuditLogPage::query( AuditLogPage::filters( array( 'object_type' => 'order', 'object_id' => 31338 ) ) );
$recent = AuditLogPage::query( AuditLogPage::filters( array( 'audit_action' => 'w9_probe', 'from' => gmdate( 'Y-m-d', time() - 2 * 86400 ), 'to' => gmdate( 'Y-m-d', time() + 86400 ) ) ) );
$paged = AuditLogPage::query( $f, 2, 2 );
check( 'audit viewer: filters by action, user, object, object id and date range; paging', 3 === $all['total'] && 2 === $by_user['total'] && 1 === $by_object['total'] && 1 === $by_id['total'] && 2 === $recent['total'] && 1 === count( $paged['rows'] ) && 3 === $paged['total'] );
check( 'audit viewer: invalid filter input is ignored', '' === AuditLogPage::filters( array( 'from' => '2024-13-99x', 'audit_action' => array( 'x' ) ) )['from'] );
$exp_before = w9_audit_count( 'audit_log_exported' );
$export = AuditLogPage::export( $f );
check( 'audit export: CSV with the filtered rows, the export itself is audited', is_array( $export ) && 4 === substr_count( $export['csv'], "\n" ) && false !== strpos( $export['csv'], 'w9_probe' ) && $exp_before + 1 === w9_audit_count( 'audit_log_exported' ) );
w9_as( 9901, array() );
$denied = AuditLogPage::export( $f );
check( 'audit export: users without wpcap_view_audit/manage_options are refused', is_wp_error( $denied ) && $exp_before + 1 === w9_audit_count( 'audit_log_exported' ) );
w9_as( 9902, array( 'wpcap_view_audit' ) );
check( 'audit viewer: dedicated capability wpcap_view_audit is enough', AuditLogPage::can_view() && 'wpcap_view_audit' === AuditLogPage::menu_cap() );
w9_admin();

/* ------------------------------------------------------------------ 2. Date-level (occurrence) staff */

$w9a = w9_type();
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $w9a['event'], 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 4 * 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$w9_occ2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $w9_occ2, 'name' => 'Tag 2', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$w9_type2 = (int) $wpdb->insert_id;
$o1 = w9_order( $w9a['type'], 2, 'day1@example.com' );
$codes1 = ticket_code_for( $o1 );
$o2 = w9_order( $w9_type2, 1, 'day2@example.com' );
$codes2 = ticket_code_for( $o2 );
$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 9950, 'w9-door', 'w9-door@example.com' );
$assign = SecurityScreens::assign_occurrence( array( 'occurrence_id' => $w9a['occ'], 'user' => 'w9-door' ) );
check( 'occurrence staff: admin assigns check-in for one date (audited)', is_array( $assign ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrence_staff WHERE user_id=9950 AND occurrence_id={$w9a['occ']}" ) && 1 <= w9_audit_count( 'occurrence_staff_assigned', $w9a['occ'] ) );
w9_as( 9950, array( 'wpcap_checkin' ) );
check( 'occurrence scope: access to the assigned date only, never event-wide', Access::can_access_occurrence( $w9a['occ'], 'wpcap_checkin' ) && ! Access::can_access_occurrence( $w9_occ2, 'wpcap_checkin' ) && ! Access::can_access_event( $w9a['event'], 'wpcap_checkin' ) && Access::can_access_event_any_date( $w9a['event'], 'wpcap_checkin' ) && array( $w9a['occ'] ) === Access::occurrence_scope( $w9a['event'], 'wpcap_checkin' ) );
$in1 = Checkin::perform( $codes1[0] ?? '', array( 'source' => 'web' ) );
$in2 = Checkin::perform( $codes2[0] ?? '', array( 'source' => 'web' ) );
check( 'occurrence scope: check-in works for the assigned date and is refused for the other date', is_array( $in1 ) && 'checked_in' === $in1['status'] && is_wp_error( $in2 ) && 'wpcap_forbidden_event' === $in2->get_error_code() );
$out1 = Checkin::check_out( $codes1[0] ?? '', array( 'source' => 'web' ) );
check( 'occurrence scope: check-out allowed on the assigned date', is_array( $out1 ) && 'checked_out' === $out1['status'] );
check( 'occurrence scope: attendee list (basic) for the assigned date only', 'basic' === PassCaptain\Printing\AttendeeList::access_level( $w9a['occ'] ) && '' === PassCaptain\Printing\AttendeeList::access_level( $w9_occ2 ) );
w9_as( 9950, array() );
check( 'occurrence scope: the assignment never replaces the role capability', ! Access::can_access_occurrence( $w9a['occ'], 'wpcap_checkin' ) );
w9_as( 9951, array( 'wpcap_manage_events' ) );
$foreign = SecurityScreens::assign_occurrence( array( 'occurrence_id' => $w9a['occ'], 'user' => 'w9-door' ) );
check( 'occurrence staff: event managers of other events cannot assign', is_wp_error( $foreign ) && 403 === $foreign->get_error_data()['status'] );
w9_admin();
$removed = SecurityScreens::remove_occurrence( array( 'occurrence_id' => $w9a['occ'], 'user_id' => 9950 ) );
w9_as( 9950, array( 'wpcap_checkin' ) );
check( 'occurrence staff: removal ends the access (audited)', is_array( $removed ) && ! Access::can_access_occurrence( $w9a['occ'], 'wpcap_checkin' ) && 1 <= w9_audit_count( 'occurrence_staff_removed', $w9a['occ'] ) );
w9_admin();

/* ------------------------------------------------------------------ 3. Data minimisation per role */

$w9m = w9_type( 2500 );
$w9m_order = w9_order( $w9m['type'], 1, 'buyer.minimal@example.com', true );
$wpdb->update( "{$p}wpcap_orders", array( 'billing' => wp_json_encode( array( 'company' => 'ACME GmbH', 'street' => 'Weg 1' ) ), 'provider_reference' => 'pi_secret_ref' ), array( 'order_id' => $w9m_order ) );
$w9m_ticket = $wpdb->get_row( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=$w9m_order LIMIT 1" );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => (int) $w9m_ticket->ticket_id, 'attendee_name' => 'Erika Mustermann', 'attendee_email' => 'erika@example.com', 'buyer_email' => 'buyer.minimal@example.com', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$order_row = Orders::get( $w9m_order );
$ticket_rows = PassCaptain\Admin\Queries::order_tickets( $w9m_order );
$ticket_rows[0]->answers = array( 'Allergien' => 'Nüsse' );
$personas = array(
	'checkin' => array( 'wpcap_checkin' ),
	'partner' => array( 'wpcap_checkin', 'wpcap_view_reports' ),
	'finance' => array( 'wpcap_view_finance', 'wpcap_view_reports', 'wpcap_approve_refunds' ),
	'support' => array( 'wpcap_manage_orders' ),
	'operations' => array( 'wpcap_manage_orders', 'wpcap_checkin' ),
);
$profiles = array();
foreach ( $personas as $name => $caps ) { w9_as( 9960, $caps ); $profiles[ $name ] = DataPolicy::profile(); }
w9_admin();
$profiles['admin'] = DataPolicy::profile();
check( 'minimisation: role profiles are derived from capabilities', array( 'checkin' => 'checkin', 'partner' => 'partner', 'finance' => 'finance', 'support' => 'support', 'operations' => 'operations', 'admin' => 'full' ) === $profiles );
$payment_fields = array( 'total_minor', 'subtotal_minor', 'tax_minor', 'refunded_minor', 'payment_provider', 'provider_reference', 'billing' );
foreach ( array( 'checkin', 'partner' ) as $name ) {
	w9_as( 9960, $personas[ $name ] );
	$view = (array) DataPolicy::order_view( $order_row );
	check( "minimisation: $name role gets no payment data (amounts, provider reference, billing)", ! array_intersect_key( $view, array_flip( $payment_fields ) ) && ! DataPolicy::can_view_payment() );
	$stripped = DataPolicy::strip_payment( array( 'sales_by_currency' => array( array( 'currency' => 'EUR', 'tickets_sold' => 3, 'gross_minor' => 7500 ) ), 'checkins' => 2 ) );
	check( "minimisation: $name role – nested payment keys removed from REST/report data", ! isset( $stripped['sales_by_currency'][0]['gross_minor'] ) && 3 === $stripped['sales_by_currency'][0]['tickets_sold'] );
}
w9_as( 9960, $personas['finance'] );
$fin_ticket = DataPolicy::ticket_view( $ticket_rows[0], $w9m_order );
check( 'minimisation: finance gets payment data but no attendee extras (answers, attendee e-mails)', DataPolicy::can_view_payment() && ! DataPolicy::can_view_attendee_extras() && array() === $fin_ticket->answers && ! isset( $fin_ticket->attendee_email ) && ! isset( $fin_ticket->assigned_email ) );
check( 'minimisation: finance has no attendee list access', '' === PassCaptain\Printing\AttendeeList::access_level( $w9m['occ'] ) );
w9_as( 9960, $personas['checkin'] );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $w9m['event'], 'user_id' => 9960, 'capability' => 'wpcap_checkin' ) );
$basic = PassCaptain\Printing\AttendeeList::rows( $w9m['occ'], PassCaptain\Printing\AttendeeList::access_level( $w9m['occ'] ) );
check( 'minimisation: check-in staff attendee list has no e-mail or order data', 'basic' === PassCaptain\Printing\AttendeeList::access_level( $w9m['occ'] ) && 3 === count( $basic[0] ) && false === strpos( wp_json_encode( $basic ), '@' ) );
$view = Checkin::preview( ticket_code_for( $w9m_order )[0] ?? '', array() );
check( 'minimisation: scanner preview carries no payment data and no buyer e-mail', is_array( $view ) && ! array_intersect_key( $view, array_flip( $payment_fields ) ) && false === strpos( wp_json_encode( $view ), '@' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=9960" );
// Report CSV and REST report for the partner role.
w9_admin();
$report = PassCaptain\Reports\Data::build( PassCaptain\Reports\Page::filters( array() ), null );
$full_csv = wp_json_encode( PassCaptain\Reports\Data::csv_rows( $report ) );
w9_as( 9960, $personas['partner'] );
$partner_csv = wp_json_encode( PassCaptain\Reports\Data::csv_rows( $report ) );
check( 'minimisation: report CSV for partner role has ticket/admission figures but no amounts', false !== strpos( $full_csv, 'Brutto' ) && false === strpos( $partner_csv, 'Brutto' ) && false === strpos( $partner_csv, 'USt' ) && false !== strpos( $partner_csv, 'Eingecheckt' ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $w9m['event'], 'user_id' => 9960, 'capability' => 'wpcap_view_reports' ) );
wpcap_test_reset_transients();
$rr = new WP_REST_Request( 'GET', '/pass-captain/v1/events/' . $w9m['event'] . '/reports/summary' );
$rr->set_param( 'event_id', $w9m['event'] );
$allowed = PassCaptain\Rest\Controller::report_permission( $rr );
$partner_report = PassCaptain\Rest\Controller::event_report( $rr );
$partner_json = wp_json_encode( $partner_report instanceof WP_REST_Response ? $partner_report->get_data() : $partner_report );
w9_admin();
$admin_report = PassCaptain\Rest\Controller::event_report( $rr );
$admin_json = wp_json_encode( $admin_report instanceof WP_REST_Response ? $admin_report->get_data() : $admin_report );
check( 'minimisation: REST report summary without amounts for the partner role (cached data too)', true === $allowed && false === strpos( $partner_json, 'gross_minor' ) && false !== strpos( $partner_json, 'tickets_sold' ) && false !== strpos( $admin_json, 'gross_minor' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=9960" );
// Support: masked personal data with audited per-case unmask.
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $w9m['event'], 'user_id' => 9961, 'capability' => 'wpcap_manage_orders' ) );
w9_as( 9961, $personas['support'] );
wpcap_test_reset_transients();
$masked_order = DataPolicy::order_view( $order_row );
$masked_ticket = DataPolicy::ticket_view( $ticket_rows[0], $w9m_order );
check( 'minimisation: support sees masked buyer/attendee data, no billing, no answers', DataPolicy::masks_pii( $w9m_order ) && 'b•••@e•••.com' === $masked_order->customer_email && null === $masked_order->billing && 'E. M•••' === $masked_ticket->attendee_name && false === strpos( wp_json_encode( $masked_ticket ), 'erika@' ) && array() === $masked_ticket->answers );
$no_reason = DataPolicy::unmask( $w9m_order, 'x' );
$unmask_before = w9_audit_count( 'pii_unmasked', $w9m_order );
$unmasked = DataPolicy::unmask( $w9m_order, 'Supportfall #4711' );
check( 'minimisation: unmask needs a reason, is per case and audited', is_wp_error( $no_reason ) && is_array( $unmasked ) && ! DataPolicy::masks_pii( $w9m_order ) && DataPolicy::masks_pii( $w9m_order + 1 ) && $unmask_before + 1 === w9_audit_count( 'pii_unmasked', $w9m_order ) && false !== strpos( w9_last_audit( 'pii_unmasked' )->details, '4711' ) && 'buyer.minimal@example.com' === DataPolicy::order_view( $order_row )->customer_email );
$foreign_unmask = DataPolicy::unmask( $o1, 'Supportfall #4712' );
check( 'minimisation: support cannot unmask orders of events without assignment', is_wp_error( $foreign_unmask ) && 403 === $foreign_unmask->get_error_data()['status'] );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=9961" );
w9_admin();

/* ------------------------------------------------------------------ 4. Shared accounts: concurrent sessions */

if ( ! class_exists( 'WP_Session_Tokens' ) ) {
	class WP_Session_Tokens {
		public static array $store = array();
		private function __construct( private int $user_id ) {}
		public static function get_instance( $user_id ) { return new self( (int) $user_id ); }
		public function get_all() { return array_values( self::$store[ $this->user_id ] ?? array() ); }
		public function get( $token ) { return self::$store[ $this->user_id ][ hash( 'sha256', $token ) ] ?? null; }
		public function destroy_others( $token ) { $keep = hash( 'sha256', $token ); self::$store[ $this->user_id ] = array_intersect_key( self::$store[ $this->user_id ] ?? array(), array( $keep => 1 ) ); }
	}
}
WP_Session_Tokens::$store[9970] = array(
	hash( 'sha256', 'tok-old' ) => array( 'ip' => '10.1.2.3', 'ua' => 'Scanner A', 'login' => time() - 600, 'expiration' => time() + 86400 ),
	hash( 'sha256', 'tok-new' ) => array( 'ip' => '192.168.7.9', 'ua' => 'Phone B', 'login' => time(), 'expiration' => time() + 86400 ),
);
w9_as( 9970, array( 'wpcap_checkin' ) );
$cs_before = w9_audit_count( 'concurrent_session_detected', 9970 );
$detect = Sessions::on_login( '', 0, 0, 9970, 'logged_in', 'tok-new' );
check( 'sessions: concurrent login of a scanner account from another device is detected and audited', 1 === $detect['concurrent'] && ! $detect['ended'] && $cs_before + 1 === w9_audit_count( 'concurrent_session_detected', 9970 ) && 2 === count( WP_Session_Tokens::$store[9970] ) && false === strpos( w9_last_audit( 'concurrent_session_detected' )->details, '10.1.2.3' ) );
Settings::update( array( 'staff_single_session' => 1 ) );
$single = Sessions::on_login( '', 0, 0, 9970, 'logged_in', 'tok-new' );
check( 'sessions: with "one active session" the other sessions are ended', $single['ended'] && 1 === count( WP_Session_Tokens::$store[9970] ) && isset( WP_Session_Tokens::$store[9970][ hash( 'sha256', 'tok-new' ) ] ) );
WP_Session_Tokens::$store[9971] = array( hash( 'sha256', 'a' ) => array( 'ip' => '1.1.1.1', 'ua' => 'x' ), hash( 'sha256', 'b' ) => array( 'ip' => '2.2.2.2', 'ua' => 'y' ) );
w9_as( 9971, array( 'wpcap_checkin', 'wpcap_manage_events' ) );
$mgr = Sessions::on_login( '', 0, 0, 9971, 'logged_in', 'b' );
check( 'sessions: managers/administrators are not restricted', 0 === $mgr['concurrent'] && 2 === count( WP_Session_Tokens::$store[9971] ) );
Settings::update( array( 'staff_single_session' => 0 ) );
w9_admin();

/* ------------------------------------------------------------------ 5. MFA for sensitive actions, support access */

Settings::update( array( 'mfa_settings' => 1, 'mfa_exports' => 1, 'mfa_api_keys' => 1, 'mfa_roles' => 1 ) );
$blocked_before = w9_audit_count( 'mfa_required_blocked' );
$no2fa = StepUp::check( array( 'settings' ), 'wpcap_save_settings' );
$bulk_ok = StepUp::check( array( 'bulk' ), 'wpcap_cancel_event' );
check( 'MFA: configured categories require 2FA (blocked attempt audited), others do not', is_wp_error( $no2fa ) && 'wpcap_mfa_required' === $no2fa->get_error_code() && true === $bulk_ok && $blocked_before + 1 === w9_audit_count( 'mfa_required_blocked' ) );
check( 'MFA: secrets in the core settings form are a separate category', in_array( 'secrets', StepUp::categories_for_action( 'wpcap_save_settings', array( 'stripe_secret_key' => 'sk_live_x' ) ), true ) && ! in_array( 'secrets', StepUp::categories_for_action( 'wpcap_save_settings', array( 'hold_minutes' => 5 ) ), true ) );
$_REQUEST = array( 'action' => 'wpcap_reports_csv' );
$died = false;
try { StepUp::guard_admin_request(); } catch ( Wpcap_Test_Die $e ) { $died = true; }
$_REQUEST = array( 'action' => 'wpcap_resend_tickets' );
$passed = true === StepUp::guard_admin_request();
$_REQUEST = array();
check( 'MFA: admin-post exports are stopped before the handler, unrelated actions pass', $died && $passed );
$rest_block = StepUp::guard_rest( null, null, new W9_Request( 'POST', '/pass-captain/v1/developer/keys' ) );
$rest_pass = StepUp::guard_rest( null, null, new W9_Request( 'GET', '/pass-captain/v1/events' ) );
check( 'MFA: API key creation over REST requires 2FA', is_wp_error( $rest_block ) && 403 === $rest_block->get_error_data()['status'] && null === $rest_pass );
$audit_export = AuditLogPage::export( $f );
check( 'MFA: audit-log export requires 2FA when exports are protected', is_wp_error( $audit_export ) && 'wpcap_mfa_required' === $audit_export->get_error_code() );
$errors = new WP_Error();
StepUp::guard_profile_role( $errors, false, (object) array( 'role' => 'wpcap_box_office' ) );
check( 'MFA: assigning a plugin role on the profile screen requires 2FA', 'wpcap_mfa_required' === $errors->get_error_code() );
$has2fa = static fn( $has, $user_id ) => true;
add_filter( 'wpcap_user_has_2fa', $has2fa, 10, 2 );
check( 'MFA: users with 2FA (Two Factor plugin or filter wpcap_user_has_2fa) pass', true === StepUp::check( array( 'settings', 'exports' ) ) && is_array( AuditLogPage::export( $f ) ) );
remove_filter( 'wpcap_user_has_2fa', $has2fa, 10 );
$grant_no2fa = SupportAccess::grant( 9980, 2, 'Supportfall #1', array( 'wpcap_manage_orders' ) );
check( 'MFA: granting support access is a role assignment and requires 2FA', is_wp_error( $grant_no2fa ) && 'wpcap_mfa_required' === $grant_no2fa->get_error_code() );
Settings::update( array( 'mfa_settings' => 0, 'mfa_exports' => 0, 'mfa_api_keys' => 0, 'mfa_roles' => 0 ) );

$w9s = w9_type( 1000 );
$w9s_order = w9_order( $w9s['type'], 1, 'support-case@example.com', true );
$sa_before = w9_audit_count( 'support_access_granted', 9980 );
$bad_hours = SupportAccess::grant( 9980, 999, 'Supportfall #2', array( 'wpcap_manage_orders' ) );
$bad_reason = SupportAccess::grant( 9980, 2, 'x', array( 'wpcap_manage_orders' ) );
$bad_caps = SupportAccess::grant( 9980, 2, 'Supportfall #2', array( 'manage_options' ) );
$grant = SupportAccess::grant( 9980, 2, 'Supportfall #2', array( 'wpcap_manage_orders' ) );
check( 'support access: duration limit, reason and grantable capabilities are enforced; grant audited', is_wp_error( $bad_hours ) && is_wp_error( $bad_reason ) && is_wp_error( $bad_caps ) && is_array( $grant ) && 'active' === $grant['status'] && $sa_before + 1 === w9_audit_count( 'support_access_granted', 9980 ) );
w9_as( 9981, array( 'wpcap_manage_orders' ) );
check( 'support access: only administrators can grant', is_wp_error( SupportAccess::grant( 9980, 1, 'Supportfall #3', array( 'wpcap_manage_orders' ) ) ) );
w9_as( 9980, array( 'wpcap_manage_orders' ) );
$caps = SupportAccess::filter_caps( array(), array( 'wpcap_manage_orders' ), array( 'wpcap_manage_orders', 9980 ), (object) array( 'ID' => 9980 ) );
check( 'support access: active grant adds the capability and covers every event while active', ! empty( $caps['wpcap_manage_orders'] ) && Access::can_access_event( $w9s['event'], 'wpcap_manage_orders' ) && PassCaptain\Admin\Queries::can_access_order( $w9s_order, 'wpcap_manage_orders' ) && null === PassCaptain\Admin\Queries::event_ids_for( 'wpcap_manage_orders' ) );
$wpdb->query( "UPDATE {$p}wpcap_support_grants SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE grant_id=" . (int) $grant['grant_id'] );
SupportAccess::flush_cache();
check( 'support access: expiry is enforced immediately, even before the cron ran', ! Access::can_access_event( $w9s['event'], 'wpcap_manage_orders' ) && array() === SupportAccess::active_caps( 9980 ) );
w9_admin();
$expired = SupportAccess::expire_due();
check( 'support access: cron marks expired grants and audits the expiry', $expired >= 1 && 'expired' === SupportAccess::get( $grant['grant_id'] )['status'] && 1 <= w9_audit_count( 'support_access_expired', 9980 ) );
$grant2 = SupportAccess::grant( 9980, 1, 'Supportfall #5', array( 'wpcap_checkin' ) );
$revoked = SupportAccess::revoke( $grant2['grant_id'] );
check( 'support access: revocation ends access (audited, not twice)', is_array( $revoked ) && 'revoked' === $revoked['status'] && array() === SupportAccess::active_caps( 9980 ) && is_wp_error( SupportAccess::revoke( $grant2['grant_id'] ) ) && 1 <= w9_audit_count( 'support_access_revoked', 9980 ) );

/* ------------------------------------------------------------------ 6. Check-out role check and re-entry */

$w9c = w9_type();
$w9c_order = w9_order( $w9c['type'], 2, 'reentry@example.com' );
$w9c_codes = ticket_code_for( $w9c_order );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $w9c['event'], 'user_id' => 9990, 'capability' => 'wpcap_checkin' ) );
w9_as( 9990, array( 'wpcap_checkin' ) );
$in = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
Settings::update( array( 'checkout_roles' => 'wpcap_event_manager,wpcap_box_office' ) );
$roles = array( 'wpcap_checkin_staff' );
$role_filter = static function () use ( &$roles ) { return $roles; };
add_filter( 'wpcap_checkout_user_roles', $role_filter );
$out_denied = Checkin::check_out( $w9c_codes[0], array( 'source' => 'web' ) );
$roles = array( 'wpcap_box_office' );
$out_ok = Checkin::check_out( $w9c_codes[0], array( 'source' => 'web' ) );
remove_filter( 'wpcap_checkout_user_roles', $role_filter );
check( 'check-out: role list restricts check-out (forbidden for other roles, internal ids not exposed)', is_array( $in ) && is_wp_error( $out_denied ) && 'wpcap_checkout_forbidden' === $out_denied->get_error_code() && ! isset( $out_denied->get_error_data()['ticket_id'] ) && is_array( $out_ok ) && 'checked_out' === $out_ok['status'] );
Settings::update( array( 'checkout_roles' => '' ) );
w9_as( 9991, array( 'wpcap_checkin' ) );
$out_foreign = Checkin::check_out( $w9c_codes[1], array( 'source' => 'web' ) );
check( 'check-out: requires check-in access for the event/date', is_wp_error( $out_foreign ) && 'wpcap_forbidden_event' === $out_foreign->get_error_code() );
w9_as( 9990, array( 'wpcap_checkin' ) );
$no_reentry = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
check( 're-entry: without the setting a checked-out single ticket is not admitted again', is_wp_error( $no_reentry ) && 'already_checked_in' === ( $no_reentry->get_error_data()['result'] ?? '' ) );
Settings::update( array( 'reentry_after_checkout' => 1, 'max_reentries' => 1 ) );
$re1 = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
$inside = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
Checkin::check_out( $w9c_codes[0], array( 'source' => 'web' ) );
$re2 = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
check( 're-entry: allowed after check-out up to the maximum, never while inside', is_array( $re1 ) && ! empty( $re1['reentry'] ) && is_wp_error( $inside ) && 'already_checked_in' === ( $inside->get_error_data()['result'] ?? '' ) && is_wp_error( $re2 ) && 'entry_limit_reached' === ( $re2->get_error_data()['result'] ?? '' ) && 1 <= w9_audit_count( 'ticket_reentered' ) );
Settings::update( array( 'max_reentries' => 0 ) );
$re3 = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
Checkin::check_out( $w9c_codes[0], array( 'source' => 'web' ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE token_hash='" . hash( 'sha256', $w9c_codes[0] ) . "'" );
$re_refunded = Checkin::perform( $w9c_codes[0], array( 'source' => 'web' ) );
check( 're-entry: unlimited with max 0; refunded tickets are never re-admitted', is_array( $re3 ) && is_wp_error( $re_refunded ) );
Settings::update( array( 'reentry_after_checkout' => 0, 'max_reentries' => 0 ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=9990" );
w9_admin();

/* ------------------------------------------------------------------ 7. Refunds after check-in */

$w9r = w9_type( 2000 );
$w9r_order = w9_order( $w9r['type'], 3, 'refund-checkin@example.com', true );
$w9r_codes = ticket_code_for( $w9r_order );
Checkin::perform( $w9r_codes[0], array( 'source' => 'web' ) );
$w9r_tickets = $wpdb->get_results( "SELECT ticket_id,status,token_hash FROM {$p}wpcap_tickets WHERE order_id=$w9r_order ORDER BY ticket_id" );
$checked = 0; $valid = array();
foreach ( $w9r_tickets as $t ) { if ( 'checked_in' === $t->status ) { $checked = (int) $t->ticket_id; } else { $valid[] = (int) $t->ticket_id; } }
check( 'refund policy: checked-in tickets are detected', array( $checked ) === Refunds::checked_in_ids( array_merge( array( $checked ), $valid ) ) );
Settings::update( array( 'refund_after_checkin' => 'deny' ) );
$deny = Refunds::request( $w9r_order, array( $checked ), 'Gast unzufrieden', 'w9-deny-1' );
$deny_all = Refunds::request( $w9r_order, array(), 'Alles', 'w9-deny-all' );
$ok_valid = Refunds::request( $w9r_order, array( $valid[0] ), 'Gast verhindert', 'w9-deny-2' );
check( 'refund policy deny: checked-in tickets (also in "refund all") are refused, unused tickets refundable', is_wp_error( $deny ) && 'wpcap_refund_checked_in' === $deny->get_error_code() && is_wp_error( $deny_all ) && is_array( $ok_valid ) && 'completed' === $ok_valid['status'] );
Settings::update( array( 'refund_after_checkin' => 'approval' ) );
// Merged policy (A131): staff refund a checked-in ticket only with a documented exception (used_override).
$pending = Refunds::request( $w9r_order, array( $checked ), 'Kulanz', 'w9-approval-1', array( 'used_override' => 'Kulanz nach Einlass' ) );
$self_approve = is_array( $pending ) ? Refunds::approve( $pending['refund_id'] ) : null;
$GLOBALS['wpcap_test_user_id'] = 2;
$approved = is_array( $pending ) ? Refunds::approve( $pending['refund_id'] ) : null;
w9_admin();
$state = $wpdb->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=$checked" );
check( 'refund policy approval: second person required even for approvers; checked-in ticket stays checked_in (history) and is marked refunded', is_array( $pending ) && 'pending_approval' === $pending['status'] && is_wp_error( $self_approve ) && is_array( $approved ) && 'completed' === $approved['status'] && 'checked_in' === $state->status && 1 === (int) $state->refunded );
Settings::update( array( 'refund_after_checkin' => 'allow' ) );
$w9r2_order = w9_order( $w9r['type'], 1, 'refund-allow@example.com', true );
Checkin::perform( ticket_code_for( $w9r2_order )[0], array( 'source' => 'web' ) );
// Merged policy: "allow" lets system flows (cancelled dates) refund checked-in tickets; staff still need the
// documented exception (A131), which always takes a second approval.
$allow_plain = Refunds::request( $w9r2_order, array(), 'Absage', 'w9-allow-0' );
$allow = Refunds::request( $w9r2_order, array(), 'Absage', 'w9-allow-1', array( 'used_override' => 'Absage nach Einlass' ) );
$GLOBALS['wpcap_test_user_id'] = 2;
$allow_done = is_array( $allow ) ? Refunds::approve( $allow['refund_id'] ) : null;
w9_admin();
check( 'refund policy allow (default): checked-in tickets are refunded with a documented exception and a second approval', is_wp_error( $allow_plain ) && 'wpcap_refund_used_tickets' === $allow_plain->get_error_code() && is_array( $allow ) && 'pending_approval' === $allow['status'] && is_array( $allow_done ) && 'completed' === $allow_done['status'] );
// Occurrence cancellation with automatic refunds follows the same rule.
Settings::update( array( 'auto_refund_cancelled' => 1, 'refund_after_checkin' => 'deny' ) );
$w9x = w9_type( 1500 );
$w9x_order = w9_order( $w9x['type'], 2, 'cancel-checkin@example.com', true );
Checkin::perform( ticket_code_for( $w9x_order )[0], array( 'source' => 'web' ) );
$skip_before = w9_audit_count( 'occurrence_refund_checked_in_skipped', $w9x_order );
$counts = PassCaptain\OccurrenceCancellation::refund_paid( $w9x['occ'], 'w9-occ-' . $w9x['occ'] );
$refunded_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$w9x_order AND refunded=1" );
$checked_refunded = (int) $wpdb->get_var( "SELECT refunded FROM {$p}wpcap_tickets WHERE order_id=$w9x_order AND status='checked_in'" );
check( 'occurrence cancellation (deny): only unused tickets are refunded automatically, skipped tickets audited', 1 === $counts['refunds'] && 1 === $refunded_n && 0 === $checked_refunded && $skip_before + 1 === w9_audit_count( 'occurrence_refund_checked_in_skipped', $w9x_order ) );
Settings::update( array( 'refund_after_checkin' => 'approval' ) );
$w9y = w9_type( 1500 );
$w9y_order = w9_order( $w9y['type'], 2, 'cancel-approval@example.com', true );
Checkin::perform( ticket_code_for( $w9y_order )[0], array( 'source' => 'web' ) );
PassCaptain\OccurrenceCancellation::refund_paid( $w9y['occ'], 'w9-occ-' . $w9y['occ'] );
$statuses = $wpdb->get_col( "SELECT status FROM {$p}wpcap_refunds WHERE order_id=$w9y_order ORDER BY refund_id" );
check( 'occurrence cancellation (approval): unused tickets refunded, checked-in tickets wait for approval', array( 'completed', 'pending_approval' ) === $statuses || array( 'pending_approval', 'completed' ) === $statuses );
Settings::update( array( 'auto_refund_cancelled' => 0, 'refund_after_checkin' => 'allow' ) );

/* ------------------------------------------------------------------ 8. Buyer language */

$locales = PassCaptain\Localization::supported_locales();
check( 'language: supported locales = source language plus shipped catalogues', isset( $locales['de_DE'], $locales['en_US'], $locales['fr_FR'], $locales['nl_NL'] ) && 'Français' === $locales['fr_FR'] );
check( 'language: normalisation accepts de-DE/fr, rejects unsupported locales', 'fr_FR' === PassCaptain\Localization::normalize_buyer_locale( 'fr' ) && 'en_US' === PassCaptain\Localization::normalize_buyer_locale( 'en-US' ) && '' === PassCaptain\Localization::normalize_buyer_locale( 'xx_XX' ) && '' === PassCaptain\Localization::normalize_buyer_locale( array() ) );
check( 'language: checkout accepts the locale field', in_array( 'locale', PassCaptain\Frontend\Language::payload_keys( array( 'email' ) ), true ) );
$w9l = w9_type();
wpcap_test_reset_transients();
$fr = Orders::create( array( 'email' => 'fr@example.com', 'items' => array( array( 'ticket_type_id' => $w9l['type'], 'quantity' => 1 ) ), 'idempotency_key' => 'w9-lang-fr', 'locale' => 'fr_FR' ) );
$xx = Orders::create( array( 'email' => 'xx@example.com', 'items' => array( array( 'ticket_type_id' => $w9l['type'], 'quantity' => 1 ) ), 'idempotency_key' => 'w9-lang-xx', 'locale' => 'xx_XX' ) );
check( 'language: chosen locale is stored on the order, unsupported falls back to the request locale', 'fr_FR' === Orders::get( $fr['order_id'] )->locale && 'de_DE' === Orders::get( $xx['order_id'] )->locale );
$composed = PassCaptain\Mail\Mailer::compose( 'order_access', array( 'order_id' => 'x', 'access_link' => 'https://example.test' ), (string) Orders::get( $fr['order_id'] )->locale );
check( 'language: e-mails are composed in the order locale', is_array( $composed ) && 'fr_FR' === $composed['locale'] );
$token = str_repeat( 'ab', 32 );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'order_id' => $fr['order_id'] ) );
$bad = PassCaptain\Frontend\Language::set_order_locale( $fr['public_id'], str_repeat( '0', 64 ), 'en_US' );
$unsupported = PassCaptain\Frontend\Language::set_order_locale( $fr['public_id'], $token, 'xx_XX' );
$changed = PassCaptain\Frontend\Language::set_order_locale( $fr['public_id'], $token, 'nl_NL' );
check( 'language: portal change requires the access token and a supported locale; change is audited', is_wp_error( $bad ) && 403 === $bad->get_error_data()['status'] && is_wp_error( $unsupported ) && is_array( $changed ) && 'nl_NL' === Orders::get( $fr['order_id'] )->locale && 1 <= w9_audit_count( 'order_locale_changed', $fr['order_id'] ) );
$form = PassCaptain\Frontend\Language::portal_form( Orders::get( $fr['order_id'] ), $token );
$field = PassCaptain\Frontend\Language::checkout_field( 'wpcap-1' );
check( 'language: selector in portal (current order language preselected) and checkout', false !== strpos( $form, 'value="nl_NL" lang="nl-NL" selected' ) && false !== strpos( $form, 'wpcap_portal_language' ) && false !== strpos( $field, 'name="buyer_locale"' ) && false !== strpos( $field, 'Français' ) );
Settings::update( array( 'buyer_language_selector' => 0 ) );
check( 'language: selector can be switched off', '' === PassCaptain\Frontend\Language::checkout_field( 'wpcap-1' ) );
Settings::update( array( 'buyer_language_selector' => 1 ) );

/* ------------------------------------------------------------------ 9. Multisite network overview (two prefixes) */

$w9_prefixes = array( 1 => $wpdb->prefix, 2 => $wpdb->prefix . '2_' );
$GLOBALS['w9_blog_stack'] = array();
$GLOBALS['w9_blog'] = 1;
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $id ) { global $wpdb; $GLOBALS['w9_blog_stack'][] = $GLOBALS['w9_blog']; $GLOBALS['w9_blog'] = (int) $id; $GLOBALS['wpcap_test_blog_id'] = (int) $id; $wpdb->prefix = $GLOBALS['w9_prefixes'][ (int) $id ]; return true; }
	function restore_current_blog() { global $wpdb; $GLOBALS['w9_blog'] = array_pop( $GLOBALS['w9_blog_stack'] ) ?? 1; $GLOBALS['wpcap_test_blog_id'] = $GLOBALS['w9_blog']; $wpdb->prefix = $GLOBALS['w9_prefixes'][ $GLOBALS['w9_blog'] ]; return true; }
}
// bootstrap.php may already define get_current_blog_id() (multisite stubs reading wpcap_test_blog_id): keep both in sync.
if ( ! function_exists( 'get_current_blog_id' ) ) { function get_current_blog_id() { return (int) ( $GLOBALS['wpcap_test_blog_id'] ?? $GLOBALS['w9_blog'] ?? 1 ); } }
$GLOBALS['w9_prefixes'] = $w9_prefixes;
// Site 1 figures before site 2 exists: earlier modules may have left orders in any currency (CHF too).
$w9_s1_revenue = PassCaptain\Ops\Network::overview( array( 1 ) )[0]['revenue_30d'];
// Site 2: own schema with its own prefix.
$wpdb->prefix = $w9_prefixes[2];
wpcap_test_install();
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => 'Site2 Event', 'slug' => 's2-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->insert( "{$wpdb->prefix}wpcap_orders", array( 'public_id' => md5( 's2o1' ), 'idempotency_key' => hash( 'sha256', 's2o1' ), 'customer_email' => 's2@example.com', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'CHF', 'total_minor' => 12300, 'refunded_minor' => 300, 'price_snapshot' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->prefix = $w9_prefixes[1];
$site1_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$w9_prefixes[1]}wpcap_events" );
$site1_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$w9_prefixes[1]}wpcap_orders" );
$GLOBALS['w9_prefixes'][3] = $wpdb->prefix . 'nosite3_';
$overview = PassCaptain\Ops\Network::overview( array( 1, 2, 3 ) );
$s1 = $overview[0]; $s2 = $overview[1]; $s3 = $overview[2];
check( 'multisite: per-site figures come from each site\'s own tables (strict isolation)', $s1['installed'] && $s2['installed'] && $site1_events === $s1['events'] && $site1_orders === $s1['orders'] && 1 === $s2['events'] && 1 === $s2['orders'] && array( 'CHF' => 12000 ) === $s2['revenue_30d'] && $w9_s1_revenue === $s1['revenue_30d'] && $s1['prefix'] !== $s2['prefix'] );
check( 'multisite: a site without plugin tables is reported, not read', ! $s3['installed'] && 0 === $s3['orders'] );
check( 'multisite: prefix is restored after the overview', $w9_prefixes[1] === $wpdb->prefix && 1 === get_current_blog_id() );
$csv_rows = PassCaptain\Ops\Network::csv_rows( $overview );
check( 'multisite: network CSV lists every site with revenue per currency and health', 'site_id' === $csv_rows[0][0] && count( $csv_rows ) >= 4 && in_array( array( 2, 'Testsite', 'yes', 1, 1, 1, 'CHF', '120.00', $s2['errors'], $s2['warnings'] ), $csv_rows, true ) );
add_filter( 'wpcap_network_site_ids', static fn() => array( 1, 2 ) );
$net_before = w9_audit_count( 'network_report_exported' );
$net = PassCaptain\Ops\Network::report();
w9_as( 9995, array( 'manage_options' ) );
$net_denied = PassCaptain\Ops\Network::report();
w9_admin();
check( 'multisite: network report needs manage_network_options and is audited', is_array( $net ) && false !== strpos( $net['csv'], 'CHF' ) && is_wp_error( $net_denied ) && $net_before + 1 === w9_audit_count( 'network_report_exported' ) );
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $w9_prefixes[2] . 'wpcap_', '_%\\' ) . '%' ) ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `$table`" ); }
check( 'multisite: site 2 tables removed, site 1 data untouched', $site1_orders === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$w9_prefixes[1]}wpcap_orders" ) );
unset( $GLOBALS['wpcap_test_hooks']['wpcap_network_site_ids'] );
w9_admin();
$_REQUEST = $_POST = $_GET = array();
