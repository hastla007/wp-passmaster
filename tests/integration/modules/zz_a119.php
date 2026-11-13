<?php
/**
 * A119: REST route coverage (every route has a permission check, public routes are listed),
 * location/partner scopes of staff members across admin, admin-post, REST, reports and exports,
 * PII minimisation per role.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Access;
use PassCaptain\Pii;
use PassCaptain\Settings;
use PassCaptain\Admin\Queries;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Admin\TicketsPage;

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; }
}
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }

global $wpdb;
$p = $wpdb->prefix;

echo "A119: every REST route has a permission check; public routes are explicit\n";
$a119_root = dirname( __DIR__, 3 );
// Registrar of every source file that calls register_rest_route().
$a119_registrars = array(
	'src/Rest/Controller.php' => array( PassCaptain\Rest\Controller::class, 'register_routes' ),
	'src/Modules/Sms.php' => array( PassCaptain\Modules\Sms::class, 'register_routes' ),
	'src/Mobile/Api.php' => array( PassCaptain\Mobile\Api::class, 'routes' ),
	'src/WalletPasses/WebService.php' => array( PassCaptain\WalletPasses\WebService::class, 'routes' ),
	'src/Partners/Api.php' => array( PassCaptain\Partners\Api::class, 'register_routes' ),
	'src/OfflineScanner/Rest.php' => array( PassCaptain\OfflineScanner\Rest::class, 'routes' ),
	'src/GuestLists/Rest.php' => array( PassCaptain\GuestLists\Rest::class, 'routes' ),
	'src/Seating/Rest.php' => array( PassCaptain\Seating\Rest::class, 'register_routes' ),
	'src/Exchange/Portal.php' => array( PassCaptain\Exchange\Portal::class, 'register_routes' ),
	'src/Admission/Rest.php' => array( PassCaptain\Admission\Rest::class, 'routes' ),
	'src/PortalSelfService.php' => array( PassCaptain\PortalSelfService::class, 'register_routes' ),
);
$a119_files = array();
$a119_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $a119_root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $a119_it as $a119_file ) {
	if ( 'php' === $a119_file->getExtension() && false !== strpos( (string) file_get_contents( $a119_file->getPathname() ), 'register_rest_route(' ) ) {
		$a119_files[] = ltrim( str_replace( $a119_root, '', $a119_file->getPathname() ), '/' );
	}
}
sort( $a119_files );
$a119_known = array_keys( $a119_registrars );
sort( $a119_known );
check( 'every source file registering REST routes is covered by this test' . ( array_diff( $a119_files, $a119_known ) ? ' – missing: ' . implode( ', ', array_diff( $a119_files, $a119_known ) ) : '' ), $a119_files === $a119_known );

$a119_saved_routes = $GLOBALS['wpcap_test_routes'] ?? array();
$GLOBALS['wpcap_test_routes'] = array();
foreach ( $a119_registrars as $a119_reg ) { call_user_func( $a119_reg ); }
$a119_routes = $GLOBALS['wpcap_test_routes'];
$GLOBALS['wpcap_test_routes'] = $a119_saved_routes;

// Intentionally public routes ("METHOD route"): access token / signature / pairing code checked in the
// callback, or only public data. docs/SECURITY.md lists them.
$a119_public = array(
	'GET wp-pass-captain/v1/events',
	'POST wp-pass-captain/v1/checkout',
	'GET wp-pass-captain/v1/checkout/options',
	'GET wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/transfer',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/resend',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/assign',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/billing',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/payout-account',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/exchange',
	'POST wp-pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/refund-request',
	'GET wp-pass-captain/v1/events/(?P<event_id>\d+)/seats',
	'POST wp-pass-captain/v1/events/(?P<event_id>\d+)/seat-holds',
	'GET wp-pass-captain/v1/events/(?P<event_id>\d+)/service-slots',
	'POST wp-pass-captain/v1/service-slots/(?P<slot_id>\d+)/bookings',
	'POST wp-pass-captain/v1/campaigns/preview',
	'POST wp-pass-captain/v1/payments/(?P<provider>[a-z0-9_-]+)/webhook',
	'POST wp-pass-captain/v1/events/(?P<event_id>\d+)/waitlist',
	'POST wp-pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/accept',
	'POST wp-pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/decline',
	'GET wp-pass-captain/v1/events/(?P<event_id>\d+)/translations',
	'POST wp-pass-captain/v1/sms/twilio/status',
	'POST wp-pass-captain/v1/sms/twilio/inbound',
	'POST wp-pass-captain/v1/app/pair',
	'GET wp-pass-captain/v1/seating/occurrences/(?P<occurrence_id>\d+)',
);
$a119_unlisted = array();
$a119_missing_cb = array();
$a119_public_seen = array();
$a119_endpoints = 0;
foreach ( $a119_routes as $a119_route => $a119_args ) {
	$a119_list = isset( $a119_args['methods'] ) || isset( $a119_args['callback'] ) ? array( $a119_args ) : array_values( array_filter( $a119_args, 'is_array' ) );
	foreach ( $a119_list as $a119_ep ) {
		$a119_endpoints++;
		$a119_key = strtoupper( (string) ( $a119_ep['methods'] ?? 'GET' ) ) . ' ' . $a119_route;
		$a119_cb = $a119_ep['permission_callback'] ?? null;
		if ( '__return_true' === $a119_cb ) {
			// Apple Wallet web service: authenticated by the pass's authentication token in the callback.
			if ( false !== strpos( $a119_route, 'wallet' ) ) { $a119_public_seen[] = $a119_key; continue; }
			if ( in_array( $a119_key, $a119_public, true ) ) { $a119_public_seen[] = $a119_key; continue; }
			$a119_unlisted[] = $a119_key;
			continue;
		}
		if ( null === $a119_cb || ! is_callable( $a119_cb ) ) { $a119_missing_cb[] = $a119_key; }
	}
}
check( 'routes were enumerated (' . $a119_endpoints . ' endpoints)', $a119_endpoints >= 60 );
check( 'every endpoint has a callable permission_callback' . ( $a119_missing_cb ? ' – ' . implode( ', ', $a119_missing_cb ) : '' ), ! $a119_missing_cb );
check( 'no endpoint uses __return_true unless listed as intentionally public' . ( $a119_unlisted ? ' – ' . implode( ', ', $a119_unlisted ) : '' ), ! $a119_unlisted );
check( 'the public list has no stale entries' . ( array_diff( $a119_public, $a119_public_seen ) ? ' – ' . implode( ', ', array_diff( $a119_public, $a119_public_seen ) ) : '' ), ! array_diff( $a119_public, $a119_public_seen ) );
// Authenticated scanner/admin routes refuse anonymous and capability-less callers.
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin', 'wpcap_manage_orders', 'wpcap_manage_events' );
$a119_req = new WP_REST_Request( 'POST' );
check( 'scanner, POS and event management permission callbacks deny users without the capability', ! PassCaptain\Rest\Controller::scanner_permission() && ! PassCaptain\Rest\Controller::pos_permission() && ! PassCaptain\Rest\Controller::manage_events_permission() && ! PassCaptain\Admission\Rest::permission() && ! PassCaptain\GuestLists\Rest::permission() );
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A119: location scope of a staff member\n";
// Event A: occurrence at "Halle Nord" and a second one at "Halle Süd"; event B: only "Halle Süd".
$a119_now = gmdate( 'Y-m-d H:i:s', time() - 1800 );
$a119_setup = static function ( $location ) use ( $wpdb, $p, $a119_now ) {
	$s = adm_setup();
	$wpdb->update( "{$p}wpcap_occurrences", array( 'location' => $location, 'starts_at' => $a119_now ), array( 'occurrence_id' => $s['occ'] ) );
	$s['order'] = (int) $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=" . (int) $s['occ'] . ' ORDER BY t.ticket_id DESC LIMIT 1' );
	$s['ticket'] = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $s['order'] . ' ORDER BY ticket_id LIMIT 1' );
	return $s;
};
$a119_a = $a119_setup( 'Halle Nord' );
$a119_b = $a119_setup( 'Halle Süd' );
// Second occurrence of event A at Halle Süd (moved there from its own throwaway event).
$a119_a2 = $a119_setup( ' halle süd ' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'event_id' => $a119_a['event'] ), array( 'occurrence_id' => $a119_a2['occ'] ) );
$a119_a2['event'] = $a119_a['event'];
check( 'setup: three paid orders with ticket codes', $a119_a['order'] && $a119_b['order'] && $a119_a2['order'] && $a119_a['codes'] && $a119_b['codes'] && $a119_a2['codes'] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=%d", 1 ) );
foreach ( array( $a119_a['event'], $a119_b['event'] ) as $a119_ev ) {
	foreach ( array( 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_manage_events' ) as $a119_cap ) {
		$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a119_ev, 'user_id' => 1, 'capability' => $a119_cap ) );
	}
}

// Unscoped non-administrator: unchanged behaviour.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'without scope a staff member keeps access to every assigned event and occurrence', null === Access::scope() && Access::can_access_event( $a119_b['event'], 'wpcap_manage_orders' ) && Access::can_access_occurrence( $a119_a2['occ'], 'wpcap_checkin' ) );
check( 'scope assignment requires manage_options', false === Access::set_scope( 1, array( 'locations' => "Halle Nord" ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
Access::set_scope( 1, array( 'locations' => "  halle NORD \n\n" ) );
check( 'administrators are never scoped (scope stored, but manage_options bypasses it)', array( 'halle NORD' ) === Access::normalize( (array) get_user_meta( 1, Access::SCOPE_META, true ) )['locations'] && null === Access::scope() && Access::can_access_event( $a119_b['event'], 'wpcap_manage_orders' ) );

$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'scope applies to the non-administrator (locations, no partner limit)', array( 'locations' => array( 'halle NORD' ), 'partners' => null ) === Access::scope() );
check( 'events: A (has a Nord occurrence) allowed, B (only Süd) denied', Access::can_access_event( $a119_a['event'], 'wpcap_manage_orders' ) && ! Access::can_access_event( $a119_b['event'], 'wpcap_manage_orders' ) );
check( 'occurrences: Nord allowed, Süd of the same event denied (trimmed, case-insensitive match)', Access::can_access_occurrence( $a119_a['occ'], 'wpcap_checkin' ) && ! Access::can_access_occurrence( $a119_a2['occ'], 'wpcap_checkin' ) && ! Access::can_access_occurrence( $a119_b['occ'], 'wpcap_checkin' ) );
$a119_ids = Queries::event_ids_for( 'wpcap_manage_orders' );
check( 'admin event scope lists only events in scope', in_array( $a119_a['event'], $a119_ids, true ) && ! in_array( $a119_b['event'], $a119_ids, true ) );

$a119_occ_edit = PassCaptain\Admin\EventsPage::save_occurrence( array( 'occurrence_id' => $a119_a2['occ'], 'timezone' => 'Europe/Vienna', 'starts_at' => '2030-01-01T20:00', 'location' => 'Halle Nord' ) );
$a119_occ_new = PassCaptain\Admin\EventsPage::save_occurrence( array( 'event_id' => $a119_a['event'], 'timezone' => 'Europe/Vienna', 'starts_at' => '2030-01-01T20:00', 'location' => 'Halle Süd' ) );
$a119_occ_cancel = PassCaptain\Admin\EventsPage::cancel_occurrence( array( 'occurrence_id' => $a119_a2['occ'], 'reason' => 'x' ) );
check( 'events admin: occurrences outside the scope cannot be edited, created or cancelled', is_wp_error( $a119_occ_edit ) && 403 === ( $a119_occ_edit->get_error_data()['status'] ?? 0 ) && is_wp_error( $a119_occ_new ) && 403 === ( $a119_occ_new->get_error_data()['status'] ?? 0 ) && is_wp_error( $a119_occ_cancel ) && 403 === ( $a119_occ_cancel->get_error_data()['status'] ?? 0 ) );

// Orders (admin pages and admin-post processing).
check( 'orders: access to the Nord order only (Süd order of event A denied, too)', Queries::can_access_order( $a119_a['order'], 'wpcap_manage_orders' ) && ! Queries::can_access_order( $a119_a2['order'], 'wpcap_manage_orders' ) && ! Queries::can_access_order( $a119_b['order'], 'wpcap_manage_orders' ) );
$a119_resend = OrdersPage::resend_tickets( array( 'order_id' => $a119_b['order'] ) );
$a119_cancel = OrdersPage::cancel_order( array( 'order_id' => $a119_a2['order'], 'reason' => 'x' ) );
check( 'orders: admin-post actions on orders outside the scope are refused (403)', is_wp_error( $a119_resend ) && 403 === ( $a119_resend->get_error_data()['status'] ?? 0 ) && is_wp_error( $a119_cancel ) && 403 === ( $a119_cancel->get_error_data()['status'] ?? 0 ) );
$a119_order_ids = array_map( static fn( $o ) => (int) $o->order_id, Queries::search_orders( array(), array( $a119_a['event'], $a119_b['event'] ), 1, 500 )['rows'] );
check( 'orders: list contains the Nord order, not the Süd orders', in_array( $a119_a['order'], $a119_order_ids, true ) && ! in_array( $a119_a2['order'], $a119_order_ids, true ) && ! in_array( $a119_b['order'], $a119_order_ids, true ) );
$a119_req = new WP_REST_Request( 'POST' );
$a119_req->set_param( 'public_id', PassCaptain\Orders::get( $a119_a2['order'] )->public_id );
$a119_invoice = PassCaptain\Rest\Controller::issue_invoice( $a119_req );
check( 'REST: invoice for an order outside the scope is refused', is_wp_error( $a119_invoice ) && 'wpcap_invoice_forbidden' === $a119_invoice->get_error_code() );

// Tickets.
$a119_revoke = TicketsPage::revoke_ticket( array( 'ticket_id' => $a119_b['ticket'], 'reason' => 'Test' ) );
$a119_reissue = TicketsPage::reissue_ticket( array( 'ticket_id' => $a119_a2['ticket'] ) );
check( 'tickets: revoke/reissue outside the scope refused (403)', is_wp_error( $a119_revoke ) && 403 === ( $a119_revoke->get_error_data()['status'] ?? 0 ) && is_wp_error( $a119_reissue ) && 403 === ( $a119_reissue->get_error_data()['status'] ?? 0 ) );
$a119_ticket_ids = array_map( static fn( $t ) => (int) $t->ticket_id, Queries::search_tickets( array( 'status' => 'valid' ), array( $a119_a['event'], $a119_b['event'] ), 1, 500 )['rows'] );
check( 'tickets: list contains Nord tickets only', in_array( $a119_a['ticket'], $a119_ticket_ids, true ) && ! in_array( $a119_a2['ticket'], $a119_ticket_ids, true ) && ! in_array( $a119_b['ticket'], $a119_ticket_ids, true ) );

// Check-ins (REST scanner route and admin undo).
$a119_scan = static function ( $code ) {
	$req = new WP_REST_Request( 'POST' );
	$req->set_param( 'token', $code );
	return PassCaptain\Rest\Controller::check_in( $req );
};
$a119_in_b = $a119_scan( $a119_b['codes'][0] );
$a119_in_a2 = $a119_scan( $a119_a2['codes'][0] );
$a119_in_a = $a119_scan( $a119_a['codes'][0] );
check( 'check-in REST: Süd tickets refused as forbidden, Nord ticket admitted', is_wp_error( $a119_in_b ) && 'forbidden' === ( $a119_in_b->get_error_data()['result'] ?? '' ) && is_wp_error( $a119_in_a2 ) && 'forbidden' === ( $a119_in_a2->get_error_data()['result'] ?? '' ) && ! is_wp_error( $a119_in_a ) && 'checked_in' === ( $a119_in_a->get_data()['status'] ?? '' ) );
$a119_log = array_map( static fn( $r ) => (int) $r->ticket_id, Queries::checkin_log( array(), array( $a119_a['event'], $a119_b['event'] ), 1, 500 )['rows'] );
check( 'check-in log shows only check-ins in scope', in_array( $a119_a['ticket'], $a119_log, true ) && ! in_array( $a119_b['ticket'], $a119_log, true ) && ! in_array( $a119_a2['ticket'], $a119_log, true ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$a119_scan( $a119_b['codes'][0] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$a119_undo = TicketsPage::undo_checkin( array( 'ticket_id' => $a119_b['ticket'] ) );
check( 'check-in undo outside the scope refused', is_wp_error( $a119_undo ) && 403 === ( $a119_undo->get_error_data()['status'] ?? 0 ) );

// Reports (finance without scope sees all; with scope only its occurrences) and exports.
$a119_report = PassCaptain\Reports\Data::build( array( 'from' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ), PassCaptain\Reports\Data::scope() );
$a119_occs = array_map( 'intval', array_keys( $a119_report['occurrences'] ) );
check( 'reports: scoped finance user sees Nord figures, no Süd occurrence (same event)', is_array( PassCaptain\Reports\Data::scope() ) && in_array( $a119_a['occ'], $a119_occs, true ) && ! in_array( $a119_a2['occ'], $a119_occs, true ) && ! in_array( $a119_b['occ'], $a119_occs, true ) );
check( 'reports: occurrence settlement sheet only in scope', PassCaptain\Reports\Settlement::can_view( $a119_a['occ'] ) && ! PassCaptain\Reports\Settlement::can_view( $a119_a2['occ'] ) && ! PassCaptain\Reports\Settlement::can_view( $a119_b['occ'] ) );
$a119_rep_req = new WP_REST_Request( 'GET' );
$a119_rep_req->set_param( 'event_id', $a119_a['event'] );
$a119_rep_b = new WP_REST_Request( 'GET' );
$a119_rep_b->set_param( 'event_id', $a119_b['event'] );
check( 'REST event report: refused for B and for A (event-wide figures would include Halle Süd)', ! PassCaptain\Rest\Controller::report_permission( $a119_rep_req ) && ! PassCaptain\Rest\Controller::report_permission( $a119_rep_b ) );
check( 'exports: attendee list only for occurrences in scope', 'full' === PassCaptain\Printing\AttendeeList::access_level( $a119_a['occ'] ) && '' === PassCaptain\Printing\AttendeeList::access_level( $a119_a2['occ'] ) && '' === PassCaptain\Printing\AttendeeList::access_level( $a119_b['occ'] ) );
$a119_batch = PassCaptain\Printing\Page::batch_request( $a119_b['occ'], 'ticket', false );
check( 'exports: print batch outside the scope refused', is_wp_error( $a119_batch ) );
check( 'finance documents: invoice access only for orders in scope', PassCaptain\Printing\InvoicePdf::can_view_order_finance( $a119_a['order'] ) && ! PassCaptain\Printing\InvoicePdf::can_view_order_finance( $a119_b['order'] ) );

echo "A119: partner scope\n";
if ( PassCaptain\Privacy::has_table( 'wpcap_partner_events' ) ) {
	$wpdb->insert( "{$p}wpcap_partners", array( 'name' => 'A119 Veranstalter', 'billing_address' => '', 'payout_details' => '', 'notes' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$a119_partner = (int) $wpdb->insert_id;
	$wpdb->query( "DELETE FROM {$p}wpcap_partner_events WHERE event_id=" . (int) $a119_b['event'] );
	$wpdb->insert( "{$p}wpcap_partner_events", array( 'event_id' => $a119_b['event'], 'partner_id' => $a119_partner ) );
	$GLOBALS['wpcap_test_denied_caps'] = array();
	Access::set_scope( 1, array( 'partners' => array( $a119_partner ) ) );
	$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
	check( 'partner scope: only the partner\'s events (B allowed, A denied)', Access::can_access_event( $a119_b['event'], 'wpcap_manage_orders' ) && ! Access::can_access_event( $a119_a['event'], 'wpcap_manage_orders' ) && Queries::can_access_order( $a119_b['order'], 'wpcap_manage_orders' ) && ! Queries::can_access_order( $a119_a['order'], 'wpcap_manage_orders' ) );
	$GLOBALS['wpcap_test_denied_caps'] = array();
	Access::set_scope( 1, array( 'linked_partners' => true ) );
	$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
	check( 'linked partners: without a link nothing is reachable', ! Access::can_access_event( $a119_b['event'], 'wpcap_checkin' ) && array() === Queries::event_ids_for( 'wpcap_checkin' ) );
	$wpdb->insert( "{$p}wpcap_partner_users", array( 'partner_id' => $a119_partner, 'user_id' => 1 ) );
	check( 'linked partners: the linked partner\'s events become reachable', Access::can_access_event( $a119_b['event'], 'wpcap_checkin' ) && array( $a119_b['event'] ) === Queries::event_ids_for( 'wpcap_checkin' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partner_users WHERE partner_id=%d", $a119_partner ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partner_events WHERE partner_id=%d", $a119_partner ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partners WHERE partner_id=%d", $a119_partner ) );
} else {
	check( 'partner module tables present', false );
}
$a119_f = static function ( $scope, $user_id ) { return array( 'locations' => array(), 'partners' => null ); };
add_filter( 'wpcap_access_scope', $a119_f, 10, 2 );
check( 'filter wpcap_access_scope can lift the scope', null === Access::scope() );
remove_filter( 'wpcap_access_scope', $a119_f, 10 );
$GLOBALS['wpcap_test_denied_caps'] = array();
Access::set_scope( 1, array() );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'empty scope removes the restriction again', null === Access::scope() && Access::can_access_event( $a119_b['event'], 'wpcap_manage_orders' ) );

echo "A119: PII minimisation per role\n";
$a119_only = static function ( array $caps ) {
	$all = array( 'manage_options', 'wpcap_manage_orders', 'wpcap_manage_events', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_view_finance', 'wpcap_refund', 'wpcap_approve_refunds' );
	$GLOBALS['wpcap_test_denied_caps'] = array_values( array_diff( $all, $caps ) );
};
$a119_view = static function () { $out = array(); foreach ( Pii::FIELDS as $f ) { $out[ $f ] = Pii::can_view( $f ); } return $out; };
Settings::update( array( 'scanner_show_attendee' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'administrators / order managers: full profile, everything visible', array( 'full' ) === Pii::profiles() && ! in_array( false, $a119_view(), true ) );
$a119_only( array( 'wpcap_manage_orders' ) );
check( 'support / box office (manage_orders) keep full access (unchanged)', array( 'full' ) === Pii::profiles() && ! in_array( false, $a119_view(), true ) );
$a119_only( array( 'wpcap_checkin' ) );
check( 'door staff: no buyer data, no attendee e-mail/answers, name hidden while scanner_show_attendee is off', array( 'door' ) === Pii::profiles() && ! in_array( true, $a119_view(), true ) );
Settings::update( array( 'scanner_show_attendee' => 1 ) );
check( 'door staff: attendee name visible with scanner_show_attendee', Pii::can_view( 'attendee_name' ) && ! Pii::can_view( 'attendee_email' ) && ! Pii::can_view( 'buyer_email' ) );
Settings::update( array( 'scanner_show_attendee' => 0 ) );
$a119_only( array( 'wpcap_view_finance', 'wpcap_view_reports', 'wpcap_approve_refunds' ) );
$a119_v = $a119_view();
check( 'finance: buyer name/e-mail/phone and billing, no attendee data', $a119_v['buyer_name'] && $a119_v['buyer_email'] && $a119_v['buyer_phone'] && $a119_v['billing'] && ! $a119_v['attendee_name'] && ! $a119_v['attendee_email'] && ! $a119_v['attendee_answers'] );
$a119_only( array( 'wpcap_view_reports' ) );
check( 'reports only: no personal data at all', array( 'reports' ) === Pii::profiles() && ! in_array( true, $a119_view(), true ) );
$a119_only( array( 'wpcap_checkin', 'wpcap_view_reports' ) );
check( 'organizer role (check-in + reports): union of door and reports', array( 'door', 'reports' ) === Pii::profiles() && ! Pii::can_view( 'buyer_email' ) );
check( 'masking: e-mail keeps first letters and top-level domain, names become initials', 'a***@e***.com' === Pii::mask_email( 'anna.muster@example.com' ) && 'A. M. M.' === Pii::mask_name( 'anna Maria Muster' ) && 'a***@e***.com' === Pii::show( 'buyer_email', 'anna.muster@example.com' ) && '' === Pii::show( 'attendee_answers', 'geheim' ) );
$a119_cv = static function ( $allowed, $field ) { return 'buyer_email' === $field ? true : $allowed; };
add_filter( 'wpcap_pii_can_view', $a119_cv, 10, 2 );
check( 'filter wpcap_pii_can_view can grant a field', Pii::can_view( 'buyer_email' ) && ! Pii::can_view( 'attendee_email' ) );
remove_filter( 'wpcap_pii_can_view', $a119_cv, 10 );

// Applied in lists/exports: attendee list (basic), ticket search, scanner response.
$wpdb->query( "DELETE FROM {$p}wpcap_ticket_assignments WHERE ticket_id=" . (int) $a119_a2['ticket'] );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $a119_a2['ticket'], 'buyer_email' => 'buyer@example.com', 'attendee_email' => 'erika@example.com', 'attendee_name' => 'Erika Mustermann', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a119_only( array( 'wpcap_checkin' ) );
$a119_rows = PassCaptain\Printing\AttendeeList::rows( $a119_a2['occ'], 'basic' );
$a119_names = array_column( array_slice( $a119_rows, 1 ), 0 );
check( 'attendee list (basic) for door staff shows initials only', in_array( 'E. M.', $a119_names, true ) && ! in_array( 'Erika Mustermann', $a119_names, true ) );
check( 'ticket search: door staff cannot search by masked name/e-mail', 0 === Queries::search_tickets( array( 'search' => 'Erika' ), null, 1, 50 )['total'] && 0 === Queries::search_tickets( array( 'search' => 'erika@example' ), null, 1, 50 )['total'] );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'ticket search and attendee list unchanged for full access', Queries::search_tickets( array( 'search' => 'Erika' ), null, 1, 50 )['total'] >= 1 && in_array( 'Erika Mustermann', array_column( array_slice( PassCaptain\Printing\AttendeeList::rows( $a119_a2['occ'], 'basic' ), 1 ), 0 ), true ) );
Settings::update( array( 'scanner_show_attendee' => 1 ) );
$a119_only( array( 'wpcap_view_reports', 'wpcap_checkin' ) );
$a119_pre = PassCaptain\Checkin::preview( $a119_a2['codes'][0] );
$a119_name_door = is_array( $a119_pre ) ? ( $a119_pre['attendee_name'] ?? '' ) : 'error';
add_filter( 'wpcap_pii_can_view', $a119_deny = static function ( $allowed, $field ) { return 'attendee_name' === $field ? false : $allowed; }, 10, 2 );
$a119_pre2 = PassCaptain\Checkin::preview( $a119_a2['codes'][0] );
remove_filter( 'wpcap_pii_can_view', $a119_deny, 10 );
check( 'scanner response: name with the setting, hidden when the PII matrix denies it', 'Erika Mustermann' === $a119_name_door && is_array( $a119_pre2 ) && ! isset( $a119_pre2['attendee_name'] ) );
Settings::update( array( 'scanner_show_attendee' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=%d", 1 ) );
