<?php
/**
 * Y1 (audit panel 2026-11-15, security and access): pass rule scope for REST and admin (SEC-1),
 * CSV injection (SEC-2), developer keys bound to the owner's role and ended with the user (SEC-3),
 * checkout response without order ids (SEC-4), waitlist limits (SEC-5), private-page headers
 * (FE-7/INT-6), array parameters (QA-8), scope matrix of writing routes with object ids (LEAD-1),
 * check-in replay re-checks access (V3), staff rate-limit ceiling (V4), IPv6 /64 rate buckets (V1).
 * Included from run.php (after the module tests).
 */
// phpcs:ignoreFile

use PassCaptain\DeveloperKeys;
use PassCaptain\Memberships;
use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Reports\Scope;
use PassCaptain\Rest\Controller;
use PassCaptain\Security\Input;
use PassCaptain\Security\PrivatePage;
use PassCaptain\Waitlists;

echo "Y1 security: scope, CSV, developer keys, checkout response, waitlist, private pages, array parameters\n";
global $wpdb;
$p = $wpdb->prefix;

if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( 'get_query_var' ) ) { function get_query_var( $k, $d = '' ) { return $GLOBALS['y1_query_vars'][ $k ] ?? $d; } }
if ( ! function_exists( 'is_singular' ) ) { function is_singular() { return ! empty( $GLOBALS['y1_post'] ); } }
if ( ! function_exists( 'get_post' ) ) { function get_post() { return $GLOBALS['y1_post'] ?? null; } }
if ( ! function_exists( 'has_shortcode' ) ) { function has_shortcode( $content, $tag ) { return false !== strpos( (string) $content, '[' . $tag ); } }

/* ------------------------------------------------------------------ Personas */

$y1_all_caps = array( 'manage_options', 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance', 'wpcap_view_audit' );
$GLOBALS['y1_user_caps'] = array();
/** Current user with the given role capabilities; other users answer user_can() from $GLOBALS['y1_user_caps'] (user 1 = administrator). */
function y1_as( $user_id, array $caps ) {
	global $y1_all_caps;
	$GLOBALS['y1_user_caps'][ (int) $user_id ] = $caps;
	$GLOBALS['wpcap_test_user_id'] = (int) $user_id;
	$GLOBALS['wpcap_test_denied_caps'] = array_values( array_diff( $y1_all_caps, $caps ) );
	$GLOBALS['wpcap_test_user_can'] = static function ( $u, $c ) {
		if ( 1 === (int) $u ) { return true; }
		return in_array( $c, $GLOBALS['y1_user_caps'][ (int) $u ] ?? array(), true );
	};
	\PassCaptain\Security\SupportAccess::flush_cache();
}
function y1_admin() {
	$GLOBALS['wpcap_test_user_id'] = 1;
	$GLOBALS['wpcap_test_denied_caps'] = array();
	unset( $GLOBALS['wpcap_test_user_can'] );
	\PassCaptain\Security\SupportAccess::flush_cache();
}
function y1_staff( $user_id, $event_id, array $caps ) {
	global $wpdb;
	foreach ( $caps as $cap ) { $wpdb->insert( "{$wpdb->prefix}wpcap_event_staff", array( 'event_id' => $event_id, 'user_id' => $user_id, 'capability' => $cap ) ); }
}
function y1_json( $method, $route, array $body = array(), array $params = array(), array $headers = array() ) {
	$r = new WP_REST_Request( $method, $route );
	$r->set_body( json_encode( $body ) );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	foreach ( $headers as $k => $v ) { $r->set_header( $k, $v ); }
	return $r;
}
function y1_status( $r ) {
	if ( is_wp_error( $r ) ) { return (int) ( $r->get_error_data()['status'] ?? 0 ); }
	return $r instanceof WP_REST_Response ? (int) $r->get_status() : 200;
}
function y1_event_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function y1_occ_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}
/** Checksums of all plugin tables except bookkeeping/log tables (denied attempts may be logged). */
function y1_snapshot() {
	global $wpdb;
	$sums = array();
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', str_replace( '_', '\\_', $wpdb->prefix . 'wpcap_' ) . '%' ) ) as $table ) {
		if ( preg_match( '/wpcap_(audit_logs|api_keys|partner_keys|checkins|admission_conflicts|offline_conflicts|checkin_conflicts|jobs|message_queue)$/', $table ) ) { continue; }
		$row = $wpdb->get_row( "CHECKSUM TABLE `$table`", ARRAY_A );
		$sums[ $table ] = $row['Checksum'] ?? '';
	}
	return $sums;
}
function y1_changed( array $before ) {
	$after = y1_snapshot();
	return array_values( array_map( static fn( $t ) => preg_replace( '/^.*wpcap_/', '', $t ), array_keys( array_filter( $after, static fn( $sum, $t ) => ( $before[ $t ] ?? null ) !== $sum, ARRAY_FILTER_USE_BOTH ) ) ) );
}
/** Captures PHP warnings/notices of a callable ("Array to string conversion" etc.). */
function y1_warnings( callable $fn ) {
	$warnings = array();
	set_error_handler( static function ( $no, $msg ) use ( &$warnings ) { $warnings[] = $msg; return true; } );
	try { $fn(); } finally { restore_error_handler(); }
	return $warnings;
}

/* ------------------------------------------------------------------ Fixtures: event A and event B */

y1_admin();
$GLOBALS['wpcap_test_transients'] = array();
\PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0 ) );
$y1_type_a = make_type( 50, 10, 0 );
$y1_type_b = make_type( 50, 10, 0 );
$y1_event_a = y1_event_of_type( $y1_type_a );
$y1_event_b = y1_event_of_type( $y1_type_b );
$y1_occ_a = y1_occ_of_type( $y1_type_a );
$y1_occ_b = y1_occ_of_type( $y1_type_b );
$y1_order_a = Orders::create( array( 'email' => 'y1-a@example.com', 'items' => array( array( 'ticket_type_id' => $y1_type_a, 'quantity' => 2 ) ), 'idempotency_key' => 'y1-a-' . uniqid() ) );
$y1_codes_a = ticket_code_for( $y1_order_a['order_id'] );
$y1_order_b = Orders::create( array( 'email' => 'y1-b@example.com', 'items' => array( array( 'ticket_type_id' => $y1_type_b, 'quantity' => 2 ) ), 'idempotency_key' => 'y1-b-' . uniqid() ) );
$y1_codes_b = ticket_code_for( $y1_order_b['order_id'] );
$y1_public_b = (string) Orders::get( $y1_order_b['order_id'] )->public_id;
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $y1_event_a, 'name' => 'Y1 Raum A', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$y1_res_a = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $y1_event_b, 'name' => 'Y1 Raum B', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$y1_res_b = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $y1_res_b, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 90000 ), 'capacity' => 2, 'status' => 'open' ) );
$y1_slot_b = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $y1_slot_b, 'customer_email' => 'y1-book@example.com', 'idempotency_key' => hash( 'sha256', 'y1-booking-' . uniqid() ), 'quantity' => 1, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y1_booking_b = (int) $wpdb->insert_id;
$y1_from = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$y1_until = gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 );
$y1_pass_b = Memberships::issue( 'y1-pass-b@example.com', $y1_from, $y1_until, array( array( 'event_id' => $y1_event_b, 'ticket_type_id' => $y1_type_b ) ) );
$y1_pass_service_b = Memberships::issue( 'y1-pass-sb@example.com', $y1_from, $y1_until, array( array( 'scope' => 'service', 'resource_id' => $y1_res_b ) ) );
$y1_key_admin_b = DeveloperKeys::issue( $y1_event_b, array( 'events.read' ), 'y1-admin-b' );
check( 'Y1 fixtures: two events with orders/tickets, resources, slot/booking, passes and a key', $y1_event_a && $y1_event_b && $y1_event_a !== $y1_event_b && $y1_codes_a && $y1_codes_b && $y1_res_b && $y1_booking_b && ! is_wp_error( $y1_pass_b ) && ! is_wp_error( $y1_pass_service_b ) && ! is_wp_error( $y1_key_admin_b ) );

/* ------------------------------------------------------------------ SEC-1: pass rules, REST and admin */

echo "Y1 SEC-1: pass rule scope (REST POST /member-passes and admin screen share one check)\n";
$y1_cashier = 9811;
y1_staff( $y1_cashier, $y1_event_a, array( 'wpcap_manage_orders', 'wpcap_checkin' ) );
y1_as( $y1_cashier, array( 'wpcap_manage_orders', 'wpcap_checkin' ) );
$y1_pass_body = static fn( array $rules ) => array( 'email' => 'y1-self@example.com', 'valid_from' => $GLOBALS['y1_from'], 'valid_until' => $GLOBALS['y1_until'], 'rules' => $rules );
$GLOBALS['y1_from'] = $y1_from;
$GLOBALS['y1_until'] = $y1_until;
$y1_sec1 = array(
	// The audit case: event_id of the own event passed the old REST check, the rule was stored as event 0 / resource 0 = all resources.
	'service rule for all resources carrying the own event_id' => array( array( 'scope' => 'service', 'event_id' => $y1_event_a, 'resource_id' => 0, 'uses_total' => 0 ), 403 ),
	'service rule for a resource of another event carrying the own event_id' => array( array( 'scope' => 'service', 'event_id' => $y1_event_a, 'resource_id' => $y1_res_b ), 403 ),
	'service rule for all resources (resource_id 0)' => array( array( 'scope' => 'service', 'resource_id' => 0, 'uses_total' => 0 ), 403 ),
	'service rule without resource_id' => array( array( 'scope' => 'service' ), 403 ),
	'"any" rule (events and resources together)' => array( array( 'scope' => 'any', 'event_id' => $y1_event_a ), 403 ),
	'service rule for a resource of another event' => array( array( 'scope' => 'service', 'resource_id' => $y1_res_b ), 403 ),
	'service rule for a resource of the own event without event management' => array( array( 'scope' => 'service', 'resource_id' => $y1_res_a ), 403 ),
	'ticket type of another event (no event_id)' => array( array( 'ticket_type_id' => $y1_type_b ), 403 ),
	'own event with a ticket type of another event' => array( array( 'event_id' => $y1_event_a, 'ticket_type_id' => $y1_type_b ), 400 ),
	'rule for all events (event_id 0)' => array( array( 'event_id' => 0 ), 403 ),
	'scope given as array' => array( array( 'scope' => array( 'service' ), 'event_id' => 0 ), 403 ),
);
foreach ( $y1_sec1 as $label => list( $rule, $expected ) ) {
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_member_passes" );
	$res = Controller::issue_member_pass( y1_json( 'POST', '/wp-pass-captain/v1/member-passes', $y1_pass_body( array( array( 'event_id' => $y1_event_a, 'ticket_type_id' => $y1_type_a ), $rule ) ) ) );
	$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_member_passes" );
	check( "REST: cashier of event A is refused – $label ($expected, nothing stored)", is_wp_error( $res ) && $expected === y1_status( $res ) && $before === $after );
}
$y1_ok = Controller::issue_member_pass( y1_json( 'POST', '/wp-pass-captain/v1/member-passes', $y1_pass_body( array( array( 'ticket_type_id' => $y1_type_a, 'uses_total' => 3 ) ) ) ) );
$y1_ok_rules = 201 === y1_status( $y1_ok ) ? Memberships::rules( $y1_ok->get_data()['pass_id'] ) : array();
check( 'REST: own ticket type works and the event is taken from the ticket type', 201 === y1_status( $y1_ok ) && 1 === count( $y1_ok_rules ) && $y1_event_a === (int) $y1_ok_rules[0]->event_id && 'event' === $y1_ok_rules[0]->scope && 0 === (int) $y1_ok_rules[0]->resource_id );
$y1_admin_any = \PassCaptain\Admin\MembershipsPage::admin_action( array( 'do' => 'issue', 'email' => 'y1-adm@example.com', 'valid_from' => gmdate( 'Y-m-d\TH:i', time() - 3600 ), 'valid_until' => gmdate( 'Y-m-d\TH:i', time() + 86400 ), 'scope' => 'any', 'event_id' => $y1_event_a ) );
$y1_admin_res = \PassCaptain\Admin\MembershipsPage::admin_action( array( 'do' => 'issue', 'email' => 'y1-adm@example.com', 'valid_from' => gmdate( 'Y-m-d\TH:i', time() - 3600 ), 'valid_until' => gmdate( 'Y-m-d\TH:i', time() + 86400 ), 'scope' => 'service', 'resource_id' => $y1_res_b ) );
check( 'admin screen: the same check refuses "any" and foreign resources for the cashier', is_wp_error( $y1_admin_any ) && 403 === y1_status( $y1_admin_any ) && is_wp_error( $y1_admin_res ) && 403 === y1_status( $y1_admin_res ) );
$y1_rev = Controller::revoke_member_pass( y1_json( 'POST', '/x', array(), array( 'pass_id' => $y1_pass_service_b['pass_id'] ) ) );
check( 'REST: a service pass of another event\'s resource cannot be revoked by the cashier', 403 === y1_status( $y1_rev ) && 'active' === Memberships::get( $y1_pass_service_b['pass_id'] )->status );
// Resource manager of event B (event management) may issue and revoke service passes of that resource only.
$y1_manager_b = 9812;
y1_staff( $y1_manager_b, $y1_event_b, array( 'wpcap_manage_events', 'wpcap_manage_orders' ) );
y1_as( $y1_manager_b, array( 'wpcap_manage_events', 'wpcap_manage_orders' ) );
$y1_svc = Controller::issue_member_pass( y1_json( 'POST', '/wp-pass-captain/v1/member-passes', $y1_pass_body( array( array( 'scope' => 'service', 'resource_id' => $y1_res_b, 'event_id' => $y1_event_a, 'ticket_type_id' => $y1_type_a ) ) ) ) );
$y1_svc_rules = 201 === y1_status( $y1_svc ) ? Memberships::rules( $y1_svc->get_data()['pass_id'] ) : array();
check( 'REST: manager of resource B issues a service pass; event/ticket type of the input are cleared', 201 === y1_status( $y1_svc ) && 'service' === ( $y1_svc_rules[0]->scope ?? '' ) && 0 === (int) $y1_svc_rules[0]->event_id && 0 === (int) $y1_svc_rules[0]->ticket_type_id && $y1_res_b === (int) $y1_svc_rules[0]->resource_id );
$y1_rev2 = Controller::revoke_member_pass( y1_json( 'POST', '/x', array(), array( 'pass_id' => $y1_pass_service_b['pass_id'] ) ) );
check( 'REST: the resource manager can revoke the service pass of the own resource', 200 === y1_status( $y1_rev2 ) && 'revoked' === Memberships::get( $y1_pass_service_b['pass_id'] )->status );
y1_admin();
$y1_admin_all = Controller::issue_member_pass( y1_json( 'POST', '/wp-pass-captain/v1/member-passes', $y1_pass_body( array( array( 'scope' => 'service', 'resource_id' => 0 ) ) ) ) );
check( 'REST: administrators may still issue service passes for all resources', 201 === y1_status( $y1_admin_all ) );

/* ------------------------------------------------------------------ LEAD-1: scope matrix of writing routes */

echo "Y1 LEAD-1: scope matrix – staff of event A against object ids of event B in path and body\n";
$y1_registrars = array(
	array( Controller::class, 'register_routes' ),
	array( \PassCaptain\Admission\Rest::class, 'routes' ),
	array( \PassCaptain\Services\Api::class, 'register_routes' ),
	array( \PassCaptain\Mobile\Api::class, 'routes' ),
	array( \PassCaptain\OfflineScanner\Rest::class, 'routes' ),
	array( \PassCaptain\Partners\Api::class, 'register_routes' ),
	array( \PassCaptain\Seating\Rest::class, 'register_routes' ),
	array( \PassCaptain\WalletPasses\WebService::class, 'routes' ),
	array( \PassCaptain\Modules\Sms::class, 'register_routes' ),
	array( \PassCaptain\Modules\Fiscalization::class, 'register_routes' ),
);
$GLOBALS['wpcap_test_routes'] = array();
foreach ( $y1_registrars as $registrar ) { call_user_func( $registrar ); }
$y1_endpoints = array();
foreach ( $GLOBALS['wpcap_test_routes'] as $route => $args ) {
	foreach ( isset( $args['callback'] ) ? array( $args ) : $args as $endpoint ) {
		if ( is_array( $endpoint ) && isset( $endpoint['callback'] ) ) { $y1_endpoints[ $endpoint['methods'] . ' ' . $route ] = $endpoint; }
	}
}
$y1_ns = 'wp-pass-captain/v1';
// Writing routes that take an object id (path or body) and are authorised by a capability: each needs a case below.
$y1_cases = array(
	"POST $y1_ns/member-passes" => array(
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'scope' => 'service', 'event_id' => $y1_event_a, 'resource_id' => 0 ) ) ) ),
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'scope' => 'any', 'event_id' => $y1_event_a ) ) ) ),
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'scope' => 'service', 'resource_id' => 0 ) ) ) ),
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'scope' => 'service', 'resource_id' => $y1_res_b ) ) ) ),
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'scope' => 'any' ) ) ) ),
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'ticket_type_id' => $y1_type_b ) ) ) ),
		array( array(), array( 'email' => 'y1-m@example.com', 'valid_from' => $y1_from, 'valid_until' => $y1_until, 'rules' => array( array( 'event_id' => $y1_event_a, 'ticket_type_id' => $y1_type_b ) ) ) ),
	),
	"POST $y1_ns/member-passes/(?P<pass_id>\d+)/revoke" => array( array( array( 'pass_id' => $y1_pass_b['pass_id'] ), array() ) ),
	"POST $y1_ns/pos/sales" => array( array( array(), array( 'email' => 'y1-pos@example.com', 'items' => array( array( 'ticket_type_id' => $y1_type_b, 'quantity' => 1 ) ), 'idempotency_key' => 'y1-pos-b', 'payment_method' => 'cash' ) ) ),
	"POST $y1_ns/passes/redeem" => array( array( array(), array( 'ticket_type_id' => $y1_type_b, 'token' => str_repeat( 'a', 64 ), 'idempotency_key' => 'y1-redeem' ) ) ),
	"POST $y1_ns/orders/(?P<public_id>[a-f0-9]{32})/invoice" => array( array( array( 'public_id' => $y1_public_b ), array() ) ),
	"POST $y1_ns/tickets/validate" => array( array( array( 'token' => $y1_codes_b[0] ?? '' ), array() ) ),
	"POST $y1_ns/tickets/check-in" => array( array( array( 'token' => $y1_codes_b[0] ?? '', 'idempotency_key' => 'y1-ci-b' ), array() ) ),
	"POST $y1_ns/tickets/offline-sync" => array( array( array(), array( 'tokens' => array( $y1_codes_b[0] ?? '' ) ) ) ),
	"POST $y1_ns/admission/check-in" => array( array( array( 'token' => $y1_codes_b[1] ?? '', 'idempotency_key' => 'y1-adm-b' ), array() ) ),
	"POST $y1_ns/admission/check-out" => array( array( array( 'token' => $y1_codes_b[1] ?? '' ), array() ) ),
	"POST $y1_ns/admission/offline-sync" => array( array( array(), array( 'scans' => array( array( 'token' => $y1_codes_b[1] ?? '', 'idempotency_key' => 'y1-off-b' ) ) ) ) ),
	"POST $y1_ns/events/(?P<event_id>\d+)/seat-maps" => array( array( array( 'event_id' => $y1_event_b ), array( 'name' => 'Y1', 'seats' => array( array( 'seat_key' => 'a1', 'seat_label' => 'A1' ) ) ) ) ),
	"POST $y1_ns/events/(?P<event_id>\d+)/resources" => array(
		array( array( 'event_id' => $y1_event_b ), array( 'name' => 'Y1 fremd' ) ),
		array( array( 'event_id' => $y1_event_a ), array( 'name' => 'Y1 Preis fremd', 'ticket_type_id' => $y1_type_b ) ),
	),
	"POST $y1_ns/resources/(?P<resource_id>\d+)/slots" => array( array( array( 'resource_id' => $y1_res_b ), array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 + 3600 ) ) ) ),
	"POST $y1_ns/occurrences/(?P<occurrence_id>\d+)/admission-days" => array( array( array( 'occurrence_id' => $y1_occ_b ), array( 'days' => array( array( 'date' => gmdate( 'Y-m-d', time() + 7 * 86400 ), 'policy' => 'daily' ) ) ) ) ),
	"POST $y1_ns/events/(?P<event_id>\d+)/campaigns" => array(
		array( array( 'event_id' => $y1_event_b ), array( 'code' => 'Y1B', 'kind' => 'percent', 'value' => 10 ) ),
		array( array( 'event_id' => $y1_event_a ), array( 'code' => 'Y1AB', 'kind' => 'percent', 'value' => 10, 'ticket_type_id' => $y1_type_b ) ),
	),
	"POST $y1_ns/events/(?P<event_id>\d+)/waitlist/offer" => array(
		array( array( 'event_id' => $y1_event_b, 'ticket_type_id' => $y1_type_b ), array() ),
		array( array( 'event_id' => $y1_event_a, 'ticket_type_id' => $y1_type_b ), array() ),
	),
	"POST $y1_ns/developer/keys" => array( array( array(), array( 'event_id' => $y1_event_b, 'scopes' => array( 'events.read' ) ) ) ),
	"POST $y1_ns/developer/keys/(?P<key_id>\d+)/revoke" => array( array( array( 'key_id' => $y1_key_admin_b['api_key_id'] ), array() ) ),
	"POST $y1_ns/events/(?P<event_id>\d+)/translations" => array( array( array( 'event_id' => $y1_event_b ), array( 'locale' => 'en_US', 'name' => 'Y1' ) ) ),
	"POST $y1_ns/service-bookings/(?P<booking_id>\d+)/decide" => array( array( array( 'booking_id' => $y1_booking_b, 'decision' => 'confirm' ), array() ) ),
	"POST $y1_ns/service-bookings/(?P<booking_id>\d+)/check-in" => array( array( array( 'booking_id' => $y1_booking_b ), array() ) ),
	"POST $y1_ns/service-bookings/(?P<booking_id>\d+)/no-show" => array( array( array( 'booking_id' => $y1_booking_b ), array() ) ),
);
// Writing routes that are not event scoped by an object id of a capability holder (with the reason).
$y1_exempt = array(
	'#/tax-profiles$#' => 'administrators only (manage_options), no event scope',
	'#/fiscal/#' => 'administrators only (manage_options), no event scope',
	'#/app/#' => 'scanner device token, scoped to the paired events (zz_w5_permissions, mobile)',
	'#/partner/#' => 'partner key, scoped to the partner\'s events (zz_w5_permissions, partners)',
	'#/wallet/#' => 'PassKit authentication token / signature',
	'#/sms/#' => 'Twilio signature',
);
$y1_uncovered = array();
foreach ( $y1_endpoints as $key => $endpoint ) {
	if ( 0 === strpos( $key, 'GET ' ) || '__return_true' === $endpoint['permission_callback'] || isset( $y1_cases[ $key ] ) ) { continue; }
	$exempt = false;
	foreach ( array_keys( $y1_exempt ) as $pattern ) { $exempt = $exempt || preg_match( $pattern, $key ); }
	if ( ! $exempt ) { $y1_uncovered[] = $key; }
}
check( 'every capability-guarded writing route has a scope case or a documented exemption' . ( $y1_uncovered ? ' – missing: ' . implode( ', ', $y1_uncovered ) : '' ), ! $y1_uncovered );
$y1_staff_a = 9813;
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=$y1_staff_a" );
y1_staff( $y1_staff_a, $y1_event_a, \PassCaptain\Admin\EventsPage::STAFF_CAPS );
$y1_ticket_b_status = static fn() => $wpdb->get_col( "SELECT status FROM {$p}wpcap_tickets WHERE order_id=" . (int) $y1_order_b['order_id'] . ' ORDER BY ticket_id' );
$y1_b_before = $y1_ticket_b_status();
foreach ( $y1_cases as $key => $cases ) {
	if ( ! isset( $y1_endpoints[ $key ] ) ) { check( "scope matrix route exists: $key", false ); continue; }
	list( $method, $route ) = explode( ' ', $key, 2 );
	foreach ( $cases as $i => list( $params, $body ) ) {
		y1_as( $y1_staff_a, \PassCaptain\Admin\EventsPage::STAFF_CAPS );
		$GLOBALS['wpcap_test_transients'] = array();
		$request = y1_json( explode( ',', $method )[0], '/' . $route, $body, $params );
		$before = y1_snapshot();
		$response = null;
		$allowed = true;
		ob_start();
		try {
			$perm = call_user_func( $y1_endpoints[ $key ]['permission_callback'], $request );
			$allowed = true === $perm;
			if ( $allowed ) { $response = call_user_func( $y1_endpoints[ $key ]['callback'], $request ); }
		} catch ( Throwable $e ) {
			$response = new WP_Error( 'y1_exception', get_class( $e ) . ': ' . $e->getMessage() );
		}
		ob_end_clean();
		$changed = y1_changed( $before );
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		$batch_admitted = is_array( $data ) && isset( $data['results'] ) && array_filter( (array) $data['results'], static fn( $r ) => in_array( (string) ( $r['status'] ?? '' ), array( 'checked_in', 'checked_out', 'valid', 'ok' ), true ) );
		$refused = ! $allowed || ( is_wp_error( $response ) && in_array( y1_status( $response ), array( 400, 403, 404, 409 ), true ) ) || ( is_array( $data ) && isset( $data['results'] ) && ! $batch_admitted );
		check( "staff of event A refused on object of event B: $key #" . ( $i + 1 ) . ( $refused && ! $changed ? '' : ' – got ' . ( is_wp_error( $response ) ? $response->get_error_code() . ' ' . y1_status( $response ) : wp_json_encode( $data ) ) . ( $changed ? ' changed: ' . implode( ',', $changed ) : '' ) ), $refused && ! $changed );
	}
}
y1_admin();
check( 'tickets of event B stay unused after the matrix', $y1_b_before === $y1_ticket_b_status() && ! in_array( 'checked_in', $y1_ticket_b_status(), true ) );
check( 'the developer key of event B is still active', null === $wpdb->get_var( "SELECT revoked_at FROM {$p}wpcap_api_keys WHERE api_key_id=" . (int) $y1_key_admin_b['api_key_id'] ) );

/* ------------------------------------------------------------------ SEC-2: CSV */

echo "Y1 SEC-2: CSV cells cannot break out of their quotes\n";
$y1_csv = Scope::csv( array( array( 'Name', 'Ticket' ), array( 'Max\\";=1+1;x', 'Standard' ), array( 'a"b', 'c\\' ) ) );
$y1_lines = array_values( array_filter( explode( "\n", str_replace( "\r", '', substr( $y1_csv, 3 ) ) ) ) );
$y1_cells = array_map( static fn( $l ) => str_getcsv( $l, ';', '"', '' ), $y1_lines );
check( 'a backslash before a quote does not end the cell (RFC 4180 reader sees 2 cells)', 2 === count( $y1_cells[1] ?? array() ) && 'Standard' === ( $y1_cells[1][1] ?? '' ) && false !== strpos( (string) ( $y1_cells[1][0] ?? '' ), '=1+1' ) );
check( 'no cell of the export starts with a formula character', ! array_filter( array_merge( ...$y1_cells ), static fn( $c ) => preg_match( '/^[=+\-@]/', (string) $c ) ) );
check( 'quotes and trailing backslashes round-trip', 'a"b' === ( $y1_cells[2][0] ?? '' ) && 'c\\' === ( $y1_cells[2][1] ?? '' ) );
check( 'csv_cell neutralises a formula after a quote, semicolon or tab inside the text', "'" === substr( Scope::csv_cell( 'Max";=HYPERLINK(1)' ), 0, 1 ) && "'" === substr( Scope::csv_cell( "x\t+1" ), 0, 1 ) && "'" === substr( Scope::csv_cell( 'a; @SUM(1)' ), 0, 1 ) );
check( 'csv_cell keeps ordinary text, numbers and JSON documents unchanged', 'Rock-Konzert 2026' === Scope::csv_cell( 'Rock-Konzert 2026' ) && '-12.50' === Scope::csv_cell( '-12.50' ) && '{"a":"-1","b":[-2,"=x"]}' === Scope::csv_cell( '{"a":"-1","b":[-2,"=x"]}' ) );
check( 'csv_cell turns arrays and null into empty cells without warnings', array() === y1_warnings( static function () { Scope::csv_cell( array( 'x' ) ); Scope::csv_cell( null ); } ) && '' === Scope::csv_cell( array( 'x' ) ) );

/* ------------------------------------------------------------------ SEC-3: developer keys */

echo "Y1 SEC-3: developer keys follow the owner's role and end with the user\n";
DeveloperKeys::register();
add_filter( 'wp_pass_captain_api_scopes_v1', static function ( $scopes ) { $scopes['orders.write'] = 'Y1 orders write'; return $scopes; } );
$y1_dev = 9814;
$y1_reporter = 9815;
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id IN ($y1_dev,$y1_reporter)" );
y1_staff( $y1_dev, $y1_event_a, array( 'wpcap_manage_events' ) );
y1_staff( $y1_reporter, $y1_event_a, array( 'wpcap_view_reports' ) );
$GLOBALS['y1_user_caps'][ $y1_reporter ] = array( 'wpcap_view_reports' );
y1_as( $y1_dev, array( 'wpcap_manage_events' ) );
$y1_key = DeveloperKeys::issue( $y1_event_a, array( 'events.read', 'reports.read', 'orders.write' ), 'y1-dev' );
$y1_key2 = DeveloperKeys::issue( $y1_event_a, array( 'events.read' ), 'y1-dev-2' );
y1_as( $y1_reporter, array( 'wpcap_view_reports' ) );
$y1_rkey = DeveloperKeys::issue( $y1_event_a, array( 'events.read', 'orders.write' ), 'y1-reporter' );
$y1_rkey2 = DeveloperKeys::issue( $y1_event_a, array( 'events.read', 'reports.read' ), 'y1-reporter-read' );
y1_admin();
$y1_check = static fn( $key, $scope ) => DeveloperKeys::check( y1_json( 'GET', '/x', array(), array(), array( 'X-WPCAP-Key' => $key['key'] ) ), $scope, $GLOBALS['y1_event_a'] );
$GLOBALS['y1_event_a'] = $y1_event_a;
$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => 1 === (int) $u || in_array( $c, $GLOBALS['y1_user_caps'][ (int) $u ] ?? array(), true );
$GLOBALS['wpcap_test_transients'] = array();
check( 'key of an event manager works for read and write scopes', ! is_wp_error( $y1_check( $y1_key, 'events.read' ) ) && ! is_wp_error( $y1_check( $y1_key, 'orders.write' ) ) );
$y1_rw = $y1_check( $y1_rkey, 'orders.write' );
check( 'key of report-only staff: read scope yes, write scope no (403)', ! is_wp_error( $y1_check( $y1_rkey, 'events.read' ) ) && is_wp_error( $y1_rw ) && 403 === y1_status( $y1_rw ) );
$GLOBALS['y1_user_caps'][ $y1_dev ] = array(); // Role lost the capability, staff row still there.
$y1_lost = $y1_check( $y1_key, 'events.read' );
check( 'a key stops working as soon as the owner\'s role loses the capability (staff row alone is not enough)', is_wp_error( $y1_lost ) && 403 === y1_status( $y1_lost ) );
do_action( 'set_user_role', $y1_dev, 'subscriber', array( 'wpcap_event_manager' ) );
$y1_revoked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_api_keys WHERE user_id=%d AND revoked_at IS NOT NULL", $y1_dev ) );
check( 'set_user_role revokes the keys the new role cannot use (audited)', 2 === $y1_revoked && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='developer_key_revoked' AND object_id=" . (int) $y1_key['api_key_id'] ) >= 1 );
do_action( 'set_user_role', $y1_reporter, 'wpcap_partner', array( 'wpcap_partner' ) );
$y1_revoked_at = static fn( $key ) => $wpdb->get_var( "SELECT revoked_at FROM {$p}wpcap_api_keys WHERE api_key_id=" . (int) $key['api_key_id'] );
check( 'set_user_role keeps keys whose scopes the role still covers and revokes a write key of report-only staff', null === $y1_revoked_at( $y1_rkey2 ) && null !== $y1_revoked_at( $y1_rkey ) );
do_action( 'delete_user', $y1_reporter );
check( 'delete_user revokes all keys of the user and removes the staff rows', null !== $y1_revoked_at( $y1_rkey2 ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_event_staff WHERE user_id=$y1_reporter" ) );
y1_staff( $y1_reporter, $y1_event_a, array( 'wpcap_view_reports' ) );
do_action( 'remove_user_from_blog', $y1_reporter, 1 );
check( 'remove_user_from_blog removes the staff rows of the site as well', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_event_staff WHERE user_id=$y1_reporter" ) );
check( 'administrators keep their keys on a role hook', 0 === DeveloperKeys::on_role_changed( 1 ) );
y1_admin();

/* ------------------------------------------------------------------ V4: rate limit ceiling */

echo "Y1 V4: per-key rate limit above the staff ceiling needs an administrator\n";
y1_as( $y1_dev, array( 'wpcap_manage_events' ) );
check( 'staff: requested 100000 req/min is clamped to the staff ceiling', DeveloperKeys::STAFF_MAX_RATE_LIMIT === DeveloperKeys::normalize_rate_limit( 100000 ) && DeveloperKeys::STAFF_MAX_RATE_LIMIT === DeveloperKeys::max_rate_limit() );
y1_admin();
check( 'administrators may set up to MAX_RATE_LIMIT', DeveloperKeys::MAX_RATE_LIMIT === DeveloperKeys::normalize_rate_limit( 100000 ) && DeveloperKeys::STAFF_MAX_RATE_LIMIT < DeveloperKeys::MAX_RATE_LIMIT );

/* ------------------------------------------------------------------ SEC-4: checkout response */

echo "Y1 SEC-4: the checkout response carries neither order_id nor public_id\n";
$GLOBALS['wpcap_test_transients'] = array();
$y1_co = Controller::checkout( y1_json( 'POST', '/wp-pass-captain/v1/checkout', array( 'email' => 'y1-co@example.com', 'items' => array( array( 'ticket_type_id' => $y1_type_a, 'quantity' => 1 ) ), 'idempotency_key' => 'y1-co-' . uniqid(), 'provider' => 'manual' ) ) );
$y1_co_data = $y1_co instanceof WP_REST_Response ? $y1_co->get_data() : array();
$y1_co_row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id FROM {$p}wpcap_orders WHERE customer_email=%s ORDER BY order_id DESC LIMIT 1", 'y1-co@example.com' ) );
check( 'checkout answers with reference/status/total but without order_id and public_id', 201 === y1_status( $y1_co ) && ! array_key_exists( 'order_id', $y1_co_data ) && ! array_key_exists( 'public_id', $y1_co_data ) && \PassCaptain\Frontend\Format::order_reference( $y1_co_row->public_id ) === ( $y1_co_data['reference'] ?? '' ) && false === strpos( wp_json_encode( $y1_co_data ), (string) $y1_co_row->public_id ) );

/* ------------------------------------------------------------------ SEC-5 / V1: waitlist limits */

echo "Y1 SEC-5/V1: waitlist limits (case-insensitive address, global IP limit, IPv6 /64)\n";
$y1_server = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$GLOBALS['wpcap_test_transients'] = array();
for ( $i = 0; $i < 5; $i++ ) { Waitlists::join( $y1_event_a, $y1_occ_a, $y1_type_a, 'Y1.Case@Example.com' ); }
$_SERVER['REMOTE_ADDR'] = '198.51.100.8';
$y1_lower = Waitlists::join( $y1_event_a, $y1_occ_a, $y1_type_a, 'y1.case@example.com' );
check( 'the per-address limit ignores upper/lower case (6th sign-up → 429)', is_wp_error( $y1_lower ) && 429 === y1_status( $y1_lower ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_filters']['wp_pass_captain_waitlist_ip_limit_v1'] = 3;
$y1_wl_types = array( make_type( 1 ), make_type( 1 ), make_type( 1 ), make_type( 1 ) );
$y1_wl = array();
foreach ( $y1_wl_types as $i => $type ) { $y1_wl[] = Waitlists::join( y1_event_of_type( $type ), y1_occ_of_type( $type ), $type, "y1-wl-$i@example.com" ); }
unset( $GLOBALS['wpcap_test_filters']['wp_pass_captain_waitlist_ip_limit_v1'] );
check( 'one IP is limited across all events (limit 3: three accepted, the fourth on another event → 429)', ! is_wp_error( $y1_wl[0] ) && ! is_wp_error( $y1_wl[2] ) && is_wp_error( $y1_wl[3] ) && 429 === y1_status( $y1_wl[3] ) );
check( 'IPv6 addresses share one rate bucket per /64, IPv4 stays per address', '2001:db8:1:2::/64' === RateLimit::client_network( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) && RateLimit::client_network( '2001:db8:1:2::1' ) === RateLimit::client_network( '2001:db8:1:2:ffff::9' ) && '203.0.113.9' === RateLimit::client_network( '203.0.113.9' ) && '203.0.113.9' === RateLimit::client_network( '::ffff:203.0.113.9' ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_transients'][ 'wpcap_seathold_' . RateLimit::hash( '2001:db8:7:7::/64' ) ] = 20;
$_SERVER['REMOTE_ADDR'] = '2001:db8:7:7:1234:5678:9abc:def0';
$y1_hold = \PassCaptain\Seats::hold( $y1_occ_a, array( 1 ), 0, 10, 'y1-hold' );
check( 'seat holds: a rotated IPv6 address inside the same /64 is still rate limited (429)', is_wp_error( $y1_hold ) && 429 === y1_status( $y1_hold ) );
$_SERVER = $y1_server;
$GLOBALS['wpcap_test_transients'] = array();

/* ------------------------------------------------------------------ FE-7 / INT-6: private pages */

echo "Y1 FE-7/INT-6: private pages are not cached, framed or indexed\n";
PrivatePage::reset();
PrivatePage::send();
$y1_lines = PrivatePage::last_sent();
check( 'PrivatePage sends X-Frame-Options DENY, CSP frame-ancestors none, X-Robots-Tag noindex, no-store', in_array( 'X-Frame-Options: DENY', $y1_lines, true ) && in_array( "Content-Security-Policy: frame-ancestors 'none'", $y1_lines, true ) && (bool) preg_grep( '/^X-Robots-Tag: noindex/', $y1_lines ) && (bool) preg_grep( '/^Cache-Control: no-store/', $y1_lines ) && in_array( 'Referrer-Policy: no-referrer', $y1_lines, true ) );
check( 'PrivatePage defines DONOTCACHEPAGE for page-cache plugins', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
$y1_pages = array(
	array( \PassCaptain\OrderPortal::class, 'maybe_render' ),
	array( \PassCaptain\TicketPage::class, 'maybe_render' ),
	array( \PassCaptain\Invoices::class, 'maybe_render_customer_invoice' ),
	array( \PassCaptain\Invoices::class, 'handle_admin_print' ),
	array( \PassCaptain\Printing\InvoicePdf::class, 'maybe_customer_download' ),
	array( \PassCaptain\Services\Portal::class, 'maybe_render' ),
	array( \PassCaptain\Waitlists::class, 'maybe_render_offer_page' ),
	array( \PassCaptain\Waitlists::class, 'handle_accept_request' ),
	array( \PassCaptain\Sales\Consents::class, 'maybe_render_attendee_page' ),
	array( \PassCaptain\PublicEvents::class, 'protect_scanner_page' ),
);
$y1_missing = array();
foreach ( $y1_pages as list( $class, $method ) ) {
	$rm = new ReflectionMethod( $class, $method );
	$src = implode( '', array_slice( file( $rm->getFileName() ), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1 ) );
	if ( false === strpos( $src, 'PrivatePage::send()' ) ) { $y1_missing[] = $class . '::' . $method; }
}
check( 'every token/staff page sends the private-page headers' . ( $y1_missing ? ' – missing: ' . implode( ', ', $y1_missing ) : '' ), ! $y1_missing );
$y1_plugin_src = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'the scanner page protection runs on template_redirect (before the theme prints headers)', false !== strpos( $y1_plugin_src, "add_action( 'template_redirect', array( PublicEvents::class, 'maybe_protect_scanner_page' )" ) );
PrivatePage::reset();
$GLOBALS['y1_post'] = (object) array( 'post_content' => '<p>Einlass</p>[wp_pass_captain_scanner]' );
$y1_scanner = \PassCaptain\PublicEvents::protect_scanner_page();
$y1_scanner_lines = PrivatePage::last_sent();
$GLOBALS['y1_post'] = (object) array( 'post_content' => '[wp_pass_captain_events]' );
PrivatePage::reset();
$y1_events = \PassCaptain\PublicEvents::protect_scanner_page();
unset( $GLOBALS['y1_post'] );
check( 'a page with the scanner shortcode gets the headers, the public event page does not', true === $y1_scanner && in_array( 'X-Frame-Options: DENY', $y1_scanner_lines, true ) && false === $y1_events && array() === PrivatePage::last_sent() );

/* ------------------------------------------------------------------ QA-8: array parameters */

echo "Y1 QA-8: array parameters do not raise 'Array to string conversion'\n";
$y1_get = $_GET;
$GLOBALS['y1_query_vars'] = array( 'wpcap_order' => array( 'x' ), 'wpcap_access' => array( 'x' ), 'wpcap_ticket' => array( 'x' ) );
$_GET = array( 'wpcap_invoice' => array( 'x' ), 'wpcap_access' => array( 'x' ), 'wpcap_service_booking' => '1', 'token' => array( 'x' ), 'do' => array( 'x' ), 'wpcap_consent' => array( 'x' ) );
$y1_w = y1_warnings( static function () {
	\PassCaptain\OrderPortal::maybe_render();
	\PassCaptain\TicketPage::maybe_render();
	\PassCaptain\Invoices::maybe_render_customer_invoice();
	\PassCaptain\Services\Portal::maybe_render();
	\PassCaptain\Sales\Consents::maybe_render_attendee_page();
	\PassCaptain\Printing\InvoicePdf::customer_pdf( array( 'x' ), array( 'x' ) );
	Memberships::redeem( array( 'x' ), 1, 'k' );
	Waitlists::accept_offer( 1, array( 'x' ) );
	\PassCaptain\TicketAssignments::assign( str_repeat( 'a', 32 ), array( 'x' ), 't', 'Name', 'name@example.com' );
} );
$_GET = $y1_get;
unset( $GLOBALS['y1_query_vars'] );
check( 'portal, ticket page, invoice, service portal, consent page, PDF, pass and offer code: no warnings' . ( $y1_w ? ' – ' . implode( ' | ', array_unique( $y1_w ) ) : '' ), array() === $y1_w );
check( 'Input::text/hex return empty strings for arrays and keep scalar values', '' === Input::text( array( 'a' ) ) && '' === Input::text( null ) && '12' === Input::text( 12 ) && '' === Input::hex( array( 'ab' ) ) && 'ab12' === Input::hex( 'xAB-ab12' ) );
$y1_paypal = (string) file_get_contents( WPCAP_PATH . 'src/Payments/PayPalGateway.php' );
check( 'PayPal and consent page no longer cast request arrays to string (sanitize_text_field/sanitize_key return \'\' for arrays)', false === strpos( $y1_paypal, "(string) ( \$_GET['token']" ) && false === strpos( $y1_paypal, "(string) ( \$_GET['order']" ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Sales/Consents.php' ), "(string) \$_GET['wpcap_consent']" ) );

/* ------------------------------------------------------------------ V3: check-in replay re-checks access */

echo "Y1 V3: an idempotent check-in replay is answered only while the user may still admit the ticket\n";
$y1_door = 9816;
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=$y1_door" );
y1_staff( $y1_door, $y1_event_a, array( 'wpcap_checkin' ) );
y1_as( $y1_door, array( 'wpcap_checkin' ) );
$y1_first = \PassCaptain\Checkin::perform( $y1_codes_a[0] ?? '', array( 'source' => 'web', 'idempotency_key' => 'y1-replay' ) );
$y1_replay = \PassCaptain\Checkin::perform( $y1_codes_a[0] ?? '', array( 'source' => 'web', 'idempotency_key' => 'y1-replay' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=$y1_door" );
$y1_after = \PassCaptain\Checkin::perform( $y1_codes_a[0] ?? '', array( 'source' => 'web', 'idempotency_key' => 'y1-replay' ) );
check( 'replay while assigned answers the stored result', ! is_wp_error( $y1_first ) && 'checked_in' === ( $y1_first['status'] ?? '' ) && ! is_wp_error( $y1_replay ) && ! empty( $y1_replay['replayed'] ) );
check( 'after the assignment is removed the replay is refused (403) instead of returning ticket data', is_wp_error( $y1_after ) && 'wpcap_forbidden_event' === $y1_after->get_error_code() );
y1_admin();
