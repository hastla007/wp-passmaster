<?php
/**
 * A203: compatibility of the bridge and REST contracts.
 *
 * - Contract fixtures (tests/contracts/*.json): the real responses of the public REST API, partner API,
 *   developer API, mobile app API and offline scanner API, the payloads sent to the Voucher Captain
 *   provider and to Affiliate Captain, and the ticket service answers must still match the committed
 *   shapes (a removed/renamed field or a type change fails, added fields are fine). Requests are built
 *   from the committed request examples, so renaming a request parameter fails too.
 * - Robustness: unknown extra fields from the other side are ignored, missing optional fields are
 *   handled, a different major contract version is refused cleanly, minor versions are accepted.
 * - Faults: exceptions, timeouts and malformed answers of the voucher provider / Affiliate Captain give
 *   a clean error and leave no half-booked order or voucher reservation behind.
 *
 * Bootstrapping a new fixture entry: WPCAP_CONTRACTS_RECORD=1 writes missing shapes (review by hand).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__, 2 ) . '/contracts/shape.php';

use PassCaptain\Contracts;
use PassCaptain\Orders;
use PassCaptain\Rest\Controller;
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as VoucherCheckout;
use PassCaptain\Integrations\Vouchers\TicketService;
use PassCaptain\Integrations\AffiliateCaptain\Adapter as AffiliateAdapter;

if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.203';

/** REST request for a route of the plugin namespace (params as query/body params or as JSON body). */
function wpa203_request( $method, $route, array $params = array(), array $headers = array(), $json = false ) {
	$r = new WP_REST_Request( $method );
	$r->route = '/pass-captain/v1' . $route;
	foreach ( $headers as $k => $v ) { $r->set_header( $k, $v ); }
	if ( $json ) { $r->set_body( wp_json_encode( $params ) ); } else { foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); } }
	return $r;
}
/** Runs a request like the REST server: contract check (rest_pre_dispatch), permission, handler. */
function wpa203_dispatch( $request, $permission, $callback ) {
	$pre = Contracts::pre_dispatch( null, null, $request );
	if ( null !== $pre ) { return $pre; }
	if ( $permission ) {
		$ok = call_user_func( $permission, $request );
		if ( true !== $ok ) { return is_wp_error( $ok ) ? $ok : new WP_Error( 'rest_forbidden', 'forbidden', array( 'status' => 401 ) ); }
	}
	$res = call_user_func( $callback, $request );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
/** JSON body as the REST server sends it (WP_Error → code/message/data). */
function wpa203_body( $result ) {
	return is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ) : $result;
}
/** Filled request example of a fixture endpoint. */
function wpa203_req( $fixture, $endpoint, array $vars = array() ) {
	$fx = wpcap_contract_fixture( $fixture );
	return (array) wpcap_contract_fill( $fx['endpoints'][ $endpoint ]['request'] ?? array(), $vars );
}
/** Example answer of the other side (outbound fixtures). */
function wpa203_example( $fixture, $endpoint ) {
	$fx = wpcap_contract_fixture( $fixture );
	return $fx['endpoints'][ $endpoint ]['response'] ?? null;
}
/** Checks a value against the fixture shape ($part: response for inbound, request for outbound). */
function wpa203_contract( $fixture, $endpoint, $part, $value ) {
	$fx = wpcap_contract_fixture( $fixture );
	if ( ! isset( $fx['endpoints'][ $endpoint ][ $part ] ) ) {
		$recorded = wpcap_contract_record( $fixture, $endpoint, $part, $value );
		check( "contract $fixture: fixture has a $part shape for $endpoint" . ( $recorded ? ' (recorded now, review it)' : '' ), false );
		return false;
	}
	if ( is_wp_error( $value ) ) {
		check( "contract $fixture $endpoint: call failed – " . $value->get_error_code() . ' ' . $value->get_error_message(), false );
		return false;
	}
	$violations = wpcap_contract_violations( $fx['endpoints'][ $endpoint ][ $part ], $value );
	check( "contract $fixture: $endpoint $part matches the fixture" . ( $violations ? ' – ' . implode( '; ', array_slice( $violations, 0, 6 ) ) : '' ), ! $violations );
	return ! $violations;
}
function wpa203_event_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function wpa203_occ_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}

echo "A203: contract shape checker\n";
$a203_shape = array( 'id' => 'int', 'name' => 'string', '?note' => 'string|null', 'items' => array( array( 'n' => 'int' ) ), 'meta' => array( '*' => 'int' ) );
$a203_ok = array( 'id' => 5, 'name' => 'A', 'items' => array( array( 'n' => 1 ), array( 'n' => 2 ) ), 'meta' => array( 'x' => 1 ) );
check( 'a value of the fixture shape passes; added fields and missing optional fields are allowed', ! wpcap_contract_violations( $a203_shape, $a203_ok ) && ! wpcap_contract_violations( $a203_shape, $a203_ok + array( 'added' => array( 1 ), 'note' => null ) ) && ! wpcap_contract_violations( $a203_shape, array_merge( $a203_ok, array( 'items' => array() ) ) ) );
$a203_removed = $a203_ok; unset( $a203_removed['name'] );
$a203_renamed = $a203_removed + array( 'title' => 'A' );
check( 'a removed field fails', array( '$.name: missing' ) === wpcap_contract_violations( $a203_shape, $a203_removed ) );
check( 'a renamed field fails', array( '$.name: missing' ) === wpcap_contract_violations( $a203_shape, $a203_renamed ) );
check( 'a type change fails (int → numeric string, nested list element, map value, optional field)', wpcap_contract_violations( $a203_shape, array_merge( $a203_ok, array( 'id' => '5' ) ) ) && wpcap_contract_violations( $a203_shape, array_merge( $a203_ok, array( 'items' => array( array( 'n' => 1.5 ) ) ) ) ) && wpcap_contract_violations( $a203_shape, array_merge( $a203_ok, array( 'meta' => array( 'x' => 'eins' ) ) ) ) && wpcap_contract_violations( $a203_shape, $a203_ok + array( 'note' => 3 ) ) );
check( 'an object turning into a list (or the reverse) fails', wpcap_contract_violations( $a203_shape, array_merge( $a203_ok, array( 'items' => array( 'n' => 1 ) ) ) ) && wpcap_contract_violations( array( 'a' => array( 'b' => 'int' ) ), array( 'a' => array( 1, 2 ) ) ) );
check( 'objects are compared as JSON (stdClass rows like arrays)', ! wpcap_contract_violations( array( 'a' => 'int' ), (object) array( 'a' => 1 ) ) );

$a203_versions = Contracts::versions();
$a203_fixture_ok = true;
$a203_fixture_names = array();
foreach ( glob( dirname( __DIR__, 2 ) . '/contracts/*.json' ) as $a203_file ) {
	$a203_fx = json_decode( (string) file_get_contents( $a203_file ), true );
	$a203_fixture_names[] = basename( $a203_file, '.json' );
	$a203_fixture_ok = $a203_fixture_ok && is_array( $a203_fx ) && isset( $a203_versions[ $a203_fx['contract'] ?? '' ] ) && Contracts::compatible( $a203_fx['version'] ?? '', $a203_versions[ $a203_fx['contract'] ] ) && ! empty( $a203_fx['endpoints'] );
}
sort( $a203_fixture_names );
check( 'every interface has a fixture file; each names a known contract of the current major version', $a203_fixture_ok && array( 'affiliate', 'app', 'developer', 'offline', 'partner', 'public', 'voucher-bridge' ) === $a203_fixture_names );
check( 'contract versions follow the interface constants (app 1.1, partner 1, offline package 1, bridge 1)', PassCaptain\Mobile\Api::VERSION === $a203_versions['app'] && 1 === Contracts::major( $a203_versions['partner'] ) && 1 === Contracts::major( $a203_versions['offline'] ) && '1.0' === $a203_versions['voucher_provider'] && 1 === Contracts::major( $a203_versions['affiliate'] ) );

echo "A203: REST contract version negotiation\n";
check( 'major/compatible: minor and patch versions are compatible, other majors and garbage are not', Contracts::compatible( '1.7', '1.0' ) && Contracts::compatible( '1', '1.1' ) && Contracts::compatible( 'v1.2.3', '1.0' ) && ! Contracts::compatible( '2.0', '1.0' ) && ! Contracts::compatible( '0.9', '1.0' ) && ! Contracts::compatible( 'banana', '1.0' ) && ! Contracts::compatible( '', '1.0' ) );
$a203_route_api = array(
	'/events' => 'public', '/checkout' => 'public', '/partner/me' => 'partner', '/partner/orders/' . str_repeat( 'a', 32 ) => 'partner',
	'/developer/events/5' => 'developer', '/app/me' => 'app', '/app/scan/sync' => 'app', '/app/offline/package' => 'offline',
);
$a203_map_ok = true;
foreach ( $a203_route_api as $a203_route => $a203_api ) { $a203_map_ok = $a203_map_ok && $a203_api === Contracts::api_for_request( wpa203_request( 'GET', $a203_route ) ); }
$a203_other = new WP_REST_Request( 'GET' );
$a203_other->route = '/wp/v2/posts';
check( 'routes map to their interface; the shared report route is the developer API with X-WPCAP-Key; foreign routes are ignored', $a203_map_ok && 'developer' === Contracts::api_for_request( wpa203_request( 'GET', '/events/5/reports/summary', array(), array( 'X-WPCAP-Key' => 'x.y' ) ) ) && 'public' === Contracts::api_for_request( wpa203_request( 'GET', '/events/5/reports/summary' ) ) && null === Contracts::api_for_request( $a203_other ) );
$a203_pre = static fn( $route, $version ) => Contracts::pre_dispatch( null, null, wpa203_request( 'GET', $route, array(), '' === $version ? array() : array( 'X-WPCAP-Contract' => $version ) ) );
check( 'no header or a minor version of the same major is accepted on every API', null === $a203_pre( '/events', '' ) && null === $a203_pre( '/events', '1.9' ) && null === $a203_pre( '/partner/me', '1.0' ) && null === $a203_pre( '/app/me', '1.0' ) && null === $a203_pre( '/app/me', '1.4' ) && null === $a203_pre( '/app/offline/keys', '1' ) && null === $a203_pre( '/developer/events/1', '1.2' ) );
$a203_major = $a203_pre( '/partner/me', '2.0' );
$a203_major_data = is_wp_error( $a203_major ) ? $a203_major->get_error_data() : array();
check( 'a different major version is refused with 400 wpcap_contract_version naming the supported version', is_wp_error( $a203_major ) && 'wpcap_contract_version' === $a203_major->get_error_code() && 400 === $a203_major_data['status'] && 'partner' === $a203_major_data['api'] && '2.0' === $a203_major_data['requested'] && Contracts::version( 'partner' ) === $a203_major_data['supported'] && false !== strpos( $a203_major->get_error_message(), '1.x' ) );
check( 'major mismatch and garbage are refused on all APIs; foreign routes are left alone', is_wp_error( $a203_pre( '/events', '2.1' ) ) && is_wp_error( $a203_pre( '/app/me', '0.9' ) ) && is_wp_error( $a203_pre( '/app/offline/package', '3' ) ) && is_wp_error( $a203_pre( '/developer/events/1', 'neu' ) ) && null === Contracts::pre_dispatch( null, null, ( static function () { $r = new WP_REST_Request( 'GET' ); $r->route = '/wp/v2/posts'; $r->set_header( 'X-WPCAP-Contract', '9.0' ); return $r; } )() ) );
$a203_earlier = new WP_Error( 'earlier', 'x' );
check( 'an earlier rest_pre_dispatch result is passed through', $a203_earlier === Contracts::pre_dispatch( $a203_earlier, null, wpa203_request( 'GET', '/events', array(), array( 'X-WPCAP-Contract' => '2.0' ) ) ) );
$a203_resp = new class() { public $headers = array(); public function header( $k, $v ) { $this->headers[ $k ] = $v; } };
Contracts::post_dispatch( $a203_resp, null, wpa203_request( 'GET', '/app/me' ) );
check( 'answers announce the served contract (X-WPCAP-Contract: app/1.1)', 'app/' . PassCaptain\Mobile\Api::VERSION === ( $a203_resp->headers['X-WPCAP-Contract'] ?? '' ) );
wpa203_contract( 'public', 'error', 'response', wpa203_body( $a203_major ) );
check( 'Contracts filters are registered by the plugin', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' ), 'Contracts::register();' ) );

echo "A203: public REST API contract\n";
$a203_saved_providers = PassCaptain\Settings::get( 'enabled_providers' );
PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0 ) );
$a203_type = make_type( 60, 10, 2500 );
$a203_event = wpa203_event_of_type( $a203_type );
$a203_occ = wpa203_occ_of_type( $a203_type );
// The list holds the 100 next dates: a date starting soon is listed whatever else the test database holds.
$a203_soon = make_type( 5, 5, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() + 1800 ) . "' WHERE occurrence_id=" . wpa203_occ_of_type( $a203_soon ) );
$a203_events = wpa203_dispatch( wpa203_request( 'GET', '/events', wpa203_req( 'public', 'GET /events' ) ), null, array( Controller::class, 'events' ) );
check( 'GET /events lists an upcoming event', is_array( $a203_events ) && in_array( wpa203_event_of_type( $a203_soon ), array_column( $a203_events, 'event_id' ), true ) );
wpa203_contract( 'public', 'GET /events', 'response', $a203_events );
wpa203_contract( 'public', 'GET /checkout/options', 'response', wpa203_dispatch( wpa203_request( 'GET', '/checkout/options', wpa203_req( 'public', 'GET /checkout/options' ) ), null, array( Controller::class, 'checkout_options' ) ) );
$a203_checkout = static function ( array $body ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return wpa203_dispatch( wpa203_request( 'POST', '/checkout', $body, array(), true ), null, array( Controller::class, 'checkout' ) );
};
$a203_co = $a203_checkout( wpa203_req( 'public', 'POST /checkout', array( 'email' => 'wpa203-buyer@example.com', 'ticket_type_id' => $a203_type, 'idempotency_key' => 'wpa203-co-' . uniqid() ) ) );
check( 'POST /checkout accepts the committed request example', is_array( $a203_co ) && ! empty( $a203_co['order_id'] ) && 5000 === (int) $a203_co['total_minor'] );
wpa203_contract( 'public', 'POST /checkout', 'response', $a203_co );
$a203_extra_body = wpa203_req( 'public', 'POST /checkout', array( 'email' => 'wpa203-extra@example.com', 'ticket_type_id' => $a203_type, 'idempotency_key' => 'wpa203-extra-' . uniqid() ) );
$a203_extra_body['client_build'] = '2027.1';
$a203_extra_body['unit_prices'] = array( $a203_type => 0 );
$a203_extra_body['items'][0]['colour'] = 'blau';
$a203_extra_body['analytics'] = array( 'utm' => 'x' );
$a203_extra = $a203_checkout( $a203_extra_body );
check( 'unknown extra request fields are ignored (also nested); trusted fields cannot be smuggled in', is_array( $a203_extra ) && 5000 === (int) $a203_extra['total_minor'] && 'pending' === $a203_extra['payment_status'] );
wpa203_contract( 'public', 'POST /checkout', 'response', $a203_extra );
$a203_min_body = wpa203_req( 'public', 'POST /checkout', array( 'email' => 'wpa203-min@example.com', 'ticket_type_id' => $a203_type, 'idempotency_key' => 'wpa203-min-' . uniqid() ) );
unset( $a203_min_body['provider'] );
$a203_min = $a203_checkout( $a203_min_body );
check( 'a missing optional request field (provider) falls back to its default', is_array( $a203_min ) && 'manual' === ( $a203_min['payment']['provider'] ?? 'manual' ) && ! empty( $a203_min['order_id'] ) );
$a203_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a203_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( 'order_id' => (int) $a203_co['order_id'] ) );
$a203_status_req = wpa203_req( 'public', 'GET /orders/{public_id}', array( 'public_id' => $a203_co['public_id'], 'access_token' => $a203_token ) );
$a203_status = wpa203_dispatch( wpa203_request( 'GET', '/orders/' . $a203_co['public_id'], $a203_status_req ), null, array( Controller::class, 'order_status' ) );
check( 'GET /orders/{public_id} answers with the committed request', is_array( $a203_status ) && 'pending' === $a203_status['payment_status'] );
wpa203_contract( 'public', 'GET /orders/{public_id}', 'response', $a203_status );
$a203_denied = wpa203_dispatch( wpa203_request( 'GET', '/orders/' . $a203_co['public_id'], array( 'public_id' => $a203_co['public_id'], 'access_token' => str_repeat( '0', 64 ) ) ), null, array( Controller::class, 'order_status' ) );
check( 'errors keep the WP_Error JSON shape (code, message, data.status)', is_wp_error( $a203_denied ) && 403 === $a203_denied->get_error_data()['status'] );
wpa203_contract( 'public', 'error', 'response', wpa203_body( $a203_denied ) );
Orders::confirm_manual_payment( $a203_co['order_id'] );

echo "A203: partner API contract\n";
$a203_partner = PassCaptain\Partners\Repository::save( array( 'name' => 'WPA203 Veranstalter', 'currency' => 'EUR' ) );
PassCaptain\Partners\Repository::set_events( $a203_partner, array( $a203_event ) );
$a203_pkey = PassCaptain\Partners\Keys::issue( $a203_partner, array( 'events:read', 'orders:read', 'tickets:read' ) );
check( 'partner key issued for the test partner', is_array( $a203_pkey ) && ! empty( $a203_pkey['key'] ) );
$a203_partner_call = static function ( $endpoint, $route, $scope, $callback, array $vars = array(), array $extra = array() ) use ( $a203_pkey ) {
	$params = array_merge( wpa203_req( 'partner', $endpoint, $vars ), $extra );
	return wpa203_dispatch( wpa203_request( 'GET', $route, $params, array( 'Authorization' => 'Bearer ' . $a203_pkey['key'] ) ), PassCaptain\Partners\Api::permission( $scope ), array( PassCaptain\Partners\Api::class, $callback ) );
};
$a203_pme = $a203_partner_call( 'GET /partner/me', '/partner/me', null, 'me' );
check( 'partner me works with the fixture request', is_array( $a203_pme ) && (int) $a203_partner === (int) $a203_pme['partner']['id'] );
wpa203_contract( 'partner', 'GET /partner/me', 'response', $a203_pme );
wpa203_contract( 'partner', 'GET /partner/events', 'response', $a203_partner_call( 'GET /partner/events', '/partner/events', 'events:read', 'events' ) );
wpa203_contract( 'partner', 'GET /partner/events/{event_id}', 'response', $a203_partner_call( 'GET /partner/events/{event_id}', '/partner/events/' . $a203_event, 'events:read', 'event', array( 'event_id' => $a203_event ) ) );
$a203_porders = $a203_partner_call( 'GET /partner/orders', '/partner/orders', 'orders:read', 'orders' );
check( 'partner orders list the paid test order', is_array( $a203_porders ) && in_array( $a203_co['public_id'], array_column( $a203_porders['orders'], 'id' ), true ) );
wpa203_contract( 'partner', 'GET /partner/orders', 'response', $a203_porders );
$a203_ptickets = $a203_partner_call( 'GET /partner/tickets', '/partner/tickets', 'tickets:read', 'tickets', array( 'event_id' => $a203_event ) );
check( 'partner tickets list the issued tickets', is_array( $a203_ptickets ) && $a203_ptickets['total'] >= 2 );
wpa203_contract( 'partner', 'GET /partner/tickets', 'response', $a203_ptickets );
$a203_pextra = $a203_partner_call( 'GET /partner/orders', '/partner/orders', 'orders:read', 'orders', array(), array( 'sort' => 'weird', 'x_partner_trace' => 'abc', 'include' => array( 'everything' ) ) );
check( 'unknown query parameters from the partner side are ignored', is_array( $a203_pextra ) && array_column( $a203_porders['orders'], 'id' ) === array_column( $a203_pextra['orders'], 'id' ) );
$a203_pv2 = wpa203_dispatch( wpa203_request( 'GET', '/partner/me', array(), array( 'Authorization' => 'Bearer ' . $a203_pkey['key'], 'X-WPCAP-Contract' => '2.0' ) ), PassCaptain\Partners\Api::permission( null ), array( PassCaptain\Partners\Api::class, 'me' ) );
check( 'a partner client of contract 2.x is refused before authentication', is_wp_error( $a203_pv2 ) && 'wpcap_contract_version' === $a203_pv2->get_error_code() );

echo "A203: developer API contract\n";
$a203_dkey = PassCaptain\DeveloperKeys::issue( $a203_event, array( 'events.read', 'slots.read', 'reports.read' ), 'WPA203', '', 0 );
$a203_res = PassCaptain\ServiceBookings::create_resource( $a203_event, array( 'name' => 'WPA203 Raum', 'capacity' => 2 ) );
$a203_slot = PassCaptain\ServiceBookings::create_slot( $a203_res, array( 'starts_at' => gmdate( 'Y-m-d 10:00:00', time() + 4 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d 11:00:00', time() + 4 * DAY_IN_SECONDS ), 'capacity' => 2 ) );
check( 'developer key, resource and slot created', is_array( $a203_dkey ) && is_int( $a203_res ) && is_int( $a203_slot ) );
$a203_dev = static function ( $endpoint, $route, $permission, $callback, array $vars ) use ( $a203_dkey ) {
	return wpa203_dispatch( wpa203_request( 'GET', $route, wpa203_req( 'developer', $endpoint, $vars ), array( 'X-WPCAP-Key' => $a203_dkey['key'] ) ), array( Controller::class, $permission ), array( Controller::class, $callback ) );
};
$a203_devent = $a203_dev( 'GET /developer/events/{event_id}', '/developer/events/' . $a203_event, 'developer_key_permission', 'developer_event', array( 'event_id' => $a203_event ) );
check( 'developer event endpoint answers', is_array( $a203_devent ) && (string) $a203_event === (string) $a203_devent['event_id'] );
wpa203_contract( 'developer', 'GET /developer/events/{event_id}', 'response', $a203_devent );
$a203_dslots = $a203_dev( 'GET /developer/events/{event_id}/service-slots', '/developer/events/' . $a203_event . '/service-slots', 'developer_slots_permission', 'developer_service_slots', array( 'event_id' => $a203_event ) );
check( 'developer slots endpoint lists the slot', is_array( $a203_dslots ) && in_array( $a203_slot, array_column( $a203_dslots, 'slot_id' ), true ) );
wpa203_contract( 'developer', 'GET /developer/events/{event_id}/service-slots', 'response', $a203_dslots );
$a203_report = $a203_dev( 'GET /events/{event_id}/reports/summary', '/events/' . $a203_event . '/reports/summary', 'report_permission', 'event_report', array( 'event_id' => $a203_event, 'from' => gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d' ) ) );
check( 'report summary counts the paid test order', is_array( $a203_report ) && 2 <= (int) ( $a203_report['sales_by_currency'][0]['tickets_sold'] ?? 0 ) );
wpa203_contract( 'developer', 'GET /events/{event_id}/reports/summary', 'response', $a203_report );

echo "A203: mobile app API contract\n";
$a203_free = make_type( 10, 10, 0 );
$a203_free_event = wpa203_event_of_type( $a203_free );
$a203_free_occ = wpa203_occ_of_type( $a203_free );
$a203_free_order = Orders::create( array( 'email' => 'wpa203-app@example.com', 'items' => array( array( 'ticket_type_id' => $a203_free, 'quantity' => 3 ) ), 'idempotency_key' => 'wpa203-app-' . uniqid() ) );
$a203_codes = ticket_code_for( $a203_free_order['order_id'] );
$a203_pairing = PassCaptain\Mobile\Devices::create_pairing( array( $a203_free_event ), 'WPA203', 12 );
$a203_pair_params = wpa203_req( 'app', 'POST /app/pair', array( 'pairing_code' => $a203_pairing['code'] ) );
$a203_pair_params['push_token_v9'] = 'unbekannt';
$GLOBALS['wpcap_test_user_id'] = 0;
$a203_pair = wpa203_dispatch( wpa203_request( 'POST', '/app/pair', $a203_pair_params ), null, array( PassCaptain\Mobile\Api::class, 'pair' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'pairing with the committed request (plus an unknown field) works', 3 === count( $a203_codes ) && is_array( $a203_pair ) && ! empty( $a203_pair['token'] ) );
wpa203_contract( 'app', 'POST /app/pair', 'response', $a203_pair );
$a203_app = static function ( $method, $endpoint, $route, $callback, array $vars = array(), array $headers = array() ) use ( $a203_pair ) {
	return wpa203_dispatch( wpa203_request( $method, $route, wpa203_req( 'app', $endpoint, $vars ), array( 'Authorization' => 'Bearer ' . $a203_pair['token'] ) + $headers ), array( PassCaptain\Mobile\Api::class, 'authenticate' ), array( PassCaptain\Mobile\Api::class, $callback ) );
};
wpa203_contract( 'app', 'GET /app/me', 'response', $a203_app( 'GET', 'GET /app/me', '/app/me', 'me' ) );
$a203_aocc = $a203_app( 'GET', 'GET /app/occurrences', '/app/occurrences', 'occurrences' );
check( 'app occurrences list the paired event', is_array( $a203_aocc ) && in_array( $a203_free_occ, array_column( $a203_aocc['occurrences'], 'occurrence_id' ), true ) );
wpa203_contract( 'app', 'GET /app/occurrences', 'response', $a203_aocc );
$a203_val = $a203_app( 'POST', 'POST /app/scan/validate', '/app/scan/validate', 'validate', array( 'code' => $a203_codes[0], 'occurrence_id' => $a203_free_occ ) );
check( 'validate answers ok for a valid ticket', is_array( $a203_val ) && true === $a203_val['ok'] );
wpa203_contract( 'app', 'POST /app/scan/validate', 'response', $a203_val );
$a203_ci = $a203_app( 'POST', 'POST /app/scan/check-in', '/app/scan/check-in', 'check_in', array( 'code' => $a203_codes[0], 'idempotency_key' => 'wpa203-ci-1', 'occurrence_id' => $a203_free_occ ) );
check( 'check-in with the committed request works', is_array( $a203_ci ) && true === $a203_ci['ok'] );
wpa203_contract( 'app', 'POST /app/scan/check-in', 'response', $a203_ci );
$a203_sync_params = wpa203_req( 'app', 'POST /app/scan/sync', array( 'code' => $a203_codes[1], 'idempotency_key' => 'wpa203-sync-1', 'scanned_at' => gmdate( 'c', time() - 60 ) ) );
$a203_sync_params['scans'][] = array( 'code' => $a203_codes[2], 'idempotency_key' => 'wpa203-sync-2', 'battery' => 12, 'gps' => array( 1, 2 ) );
$a203_sync = wpa203_dispatch( wpa203_request( 'POST', '/app/scan/sync', $a203_sync_params, array( 'Authorization' => 'Bearer ' . $a203_pair['token'] ) ), array( PassCaptain\Mobile\Api::class, 'authenticate' ), array( PassCaptain\Mobile\Api::class, 'sync' ) );
check( 'sync: unknown scan fields are ignored, missing optional fields (scanned_at, direction) default', is_array( $a203_sync ) && 2 === count( $a203_sync['results'] ) && true === $a203_sync['results'][1]['ok'] && 'in' === $a203_sync['results'][1]['direction'] );
wpa203_contract( 'app', 'POST /app/scan/sync', 'response', $a203_sync );
$a203_app_v2 = $a203_app( 'GET', 'GET /app/me', '/app/me', 'me', array(), array( 'X-WPCAP-Contract' => '2.0' ) );
$a203_app_v11 = $a203_app( 'GET', 'GET /app/me', '/app/me', 'me', array(), array( 'X-WPCAP-Contract' => '1.0' ) );
check( 'an app built for 1.0 still works against 1.1, an app for 2.0 gets a clean 400', is_array( $a203_app_v11 ) && is_wp_error( $a203_app_v2 ) && 400 === $a203_app_v2->get_error_data()['status'] );

echo "A203: offline scanner API contract\n";
PassCaptain\OfflineScanner\AdminPage::save_rules( array( 'event_id' => $a203_free_event, 'enabled' => '1', 'max_offline_hours' => '6', 'duplicate_policy' => 'review' ) );
$a203_device_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT device_id FROM {$p}wpcap_scanner_devices WHERE public_id=%s", $a203_pair['device_id'] ) );
$a203_assigned = PassCaptain\OfflineScanner\AdminPage::assign( array( 'event_id' => $a203_free_event, 'device_id' => $a203_device_id, 'occurrence_id' => $a203_free_occ ) );
$a203_off = static function ( $endpoint, $route, $callback, array $vars = array() ) use ( $a203_pair ) {
	return wpa203_dispatch( wpa203_request( 'GET', $route, wpa203_req( 'offline', $endpoint, $vars ), array( 'Authorization' => 'Bearer ' . $a203_pair['token'] ) ), array( PassCaptain\Mobile\Api::class, 'authenticate' ), array( PassCaptain\OfflineScanner\Rest::class, $callback ) );
};
wpa203_contract( 'offline', 'GET /app/offline/keys', 'response', $a203_off( 'GET /app/offline/keys', '/app/offline/keys', 'keys' ) );
$a203_oocc = $a203_off( 'GET /app/offline/occurrences', '/app/offline/occurrences', 'occurrences' );
check( 'offline occurrences list the assigned date', ! is_wp_error( $a203_assigned ) && is_array( $a203_oocc ) && in_array( $a203_free_occ, array_column( $a203_oocc['occurrences'], 'occurrence_id' ), true ) );
wpa203_contract( 'offline', 'GET /app/offline/occurrences', 'response', $a203_oocc );
$a203_pkg = $a203_off( 'GET /app/offline/package', '/app/offline/package', 'package', array( 'occurrence_id' => $a203_free_occ ) );
check( 'offline package is signed', is_array( $a203_pkg ) && null !== PassCaptain\OfflineScanner\Package::verify( $a203_pkg ) );
wpa203_contract( 'offline', 'GET /app/offline/package', 'response', $a203_pkg );
wpa203_contract( 'offline', 'package payload', 'response', is_array( $a203_pkg ) ? json_decode( (string) $a203_pkg['payload'], true ) : null );

echo "A203: Voucher Captain bridge – contract version, shapes, robustness\n";
final class WPA203_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public $calls = array();
	public $version = '1.3';
	/** method => 'exception' | 'timeout' | 'garbage' | WP_Error | callable */
	public $fault = array();
	/** Extra fields of a newer provider (minor version) in every answer. */
	public $extra = array( 'x_vcap_trace' => array( 'node' => 'eu-1' ), 'expires_in' => 900 );
	public $quote_answer = null;
	public function contract_version() { if ( $this->version instanceof Throwable ) { throw $this->version; } return $this->version; }
	private function answer( $method, array $args, $default ) {
		$this->calls[] = array( $method, $args );
		$fault = $this->fault[ $method ] ?? null;
		if ( 'exception' === $fault ) { throw new RuntimeException( 'provider crashed in ' . $method ); }
		if ( 'timeout' === $fault ) { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' ); }
		if ( 'garbage' === $fault ) { return 'OK'; }
		if ( is_wp_error( $fault ) ) { return $fault; }
		return is_array( $default ) ? $default + $this->extra : $default;
	}
	public function quote( array $context ) { return $this->answer( 'quote', array( $context ), $this->quote_answer ?? wpa203_example( 'voucher-bridge', 'provider.quote' ) ); }
	public function reserve( array $quote, $key ) { return $this->answer( 'reserve', array( 'quote' => $quote, 'idempotency_key' => $key ), array( 'reservation' => 'r-' . $key ) + wpa203_example( 'voucher-bridge', 'provider.reserve' ) ); }
	public function commit( array $reservation, $key ) { return $this->answer( 'commit', array( 'reservation' => $reservation, 'idempotency_key' => $key ), array( 'redemption' => 'd-' . $key ) + wpa203_example( 'voucher-bridge', 'provider.commit' ) ); }
	public function release( array $reservation, $key ) { return $this->answer( 'release', array( 'reservation' => $reservation, 'idempotency_key' => $key ), wpa203_example( 'voucher-bridge', 'provider.release' ) ); }
	public function refund( array $redemption, $amount, $key ) { return $this->answer( 'refund', array( 'redemption' => $redemption, 'amount_minor' => $amount, 'idempotency_key' => $key ), wpa203_example( 'voucher-bridge', 'provider.refund' ) ); }
	public function last( $method ) { foreach ( array_reverse( $this->calls ) as $c ) { if ( $method === $c[0] ) { return $c[1]; } } return null; }
	public function count( $method ) { return count( array_filter( $this->calls, static fn( $c ) => $method === $c[0] ) ); }
}
$a203_vp = new WPA203_Voucher_Provider();
$a203_saved_vp = $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ?? null;
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $a203_vp;
check( 'a provider of a newer minor contract version (1.3) is used', Bridge::available() && '' === Bridge::incompatible_contract() );
$a203_vp->version = '2.0';
check( 'a provider of another major version (2.0) is never called and is reported as incompatible', ! Bridge::available() && '2.0' === Bridge::incompatible_contract() && 'wpcap_voucher_unavailable' === Bridge::quote( array() )->get_error_code() && 0 === count( $a203_vp->calls ) );
$a203_vp->version = new RuntimeException( 'kaputt' );
check( 'a provider whose version check throws counts as incompatible', ! Bridge::available() );
$a203_vp->version = '1.3';
check( 'providers without contract_version() implement 1.0 (legacy providers stay compatible)', '1.0' === Bridge::provider_contract( new Test_Voucher_Provider() ) );
check( 'ticket and check-in services accept minor versions of contract 1 and refuse 2', TicketService::service( null, '1.4' ) instanceof TicketService && null === TicketService::service( null, 2 ) && PassCaptain\Integrations\Vouchers\CheckinService::service( null, 1 ) instanceof PassCaptain\Integrations\Vouchers\CheckinService && null === PassCaptain\Integrations\Vouchers\CheckinService::service( null, 2 ) );

$a203_saved_purpose = PassCaptain\Settings::get( 'voucher_default_purpose' );
PassCaptain\Settings::update( array( 'voucher_default_purpose' => 'multi' ) );
$a203_vorder = static function ( $tag ) use ( $a203_type ) {
	return Orders::create( array( 'email' => "wpa203-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $a203_type, 'quantity' => 2 ) ), 'idempotency_key' => "wpa203-$tag-" . uniqid() ) );
};
/** Order, voucher and hold state that must not change on a failed voucher call. */
$a203_state = static function ( $order_id ) use ( $wpdb, $p ) {
	$o = Orders::get( $order_id );
	return array( (int) $o->total_minor, (int) $o->tax_minor, (int) $o->discount_minor, (string) $o->payment_status, (string) $o->status, (string) $o->voucher_snapshot, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE status='active' AND order_id=" . (int) $order_id ), (int) $wpdb->get_var( "SELECT SUM(tax_minor) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $order_id ) );
};
$a203_v1 = $a203_vorder( 'v1' );
$a203_applied = VoucherCheckout::apply( $a203_v1['order_id'], 'WPA203-GIFT-0001' );
check( 'the minimal provider answer of the fixture (amount_minor, currency) plus unknown extra fields is applied', is_array( $a203_applied ) && 1500 === $a203_applied['applied_minor'] && 3500 === $a203_applied['total_minor'] && 'multi' === $a203_applied['purpose'] );
wpa203_contract( 'voucher-bridge', 'provider.quote', 'request', $a203_vp->last( 'quote' )[0] );
wpa203_contract( 'voucher-bridge', 'provider.reserve', 'request', $a203_vp->last( 'reserve' ) );
$a203_snap = VoucherCheckout::voucher_state( $a203_v1['order_id'] );
check( 'unknown provider fields are stored unchanged and handed back, never interpreted', 'eu-1' === ( $a203_snap['reservation']['x_vcap_trace']['node'] ?? '' ) && 'reserved' === $a203_snap['status'] );
$a203_vp->quote_answer = array( 'amount_minor' => 700 );
$a203_v2 = $a203_vorder( 'v2' );
$a203_nocur = VoucherCheckout::apply( $a203_v2['order_id'], 'WPA203-NOCUR' );
check( 'a quote without the optional currency/treatment uses the order currency and the default purpose', is_array( $a203_nocur ) && 700 === $a203_nocur['applied_minor'] && 'multi' === $a203_nocur['purpose'] );
$a203_vp->quote_answer = array( 'amount_minor' => 'viel' );
$a203_v3 = $a203_vorder( 'v3' );
$a203_before = $a203_state( $a203_v3['order_id'] );
$a203_badamount = VoucherCheckout::apply( $a203_v3['order_id'], 'WPA203-BADAMOUNT' );
check( 'a quote with a malformed amount is refused without touching the order', is_wp_error( $a203_badamount ) && 'wpcap_voucher_quote_invalid' === $a203_badamount->get_error_code() && $a203_before === $a203_state( $a203_v3['order_id'] ) );
$a203_vp->quote_answer = null;

echo "A203: Voucher Captain bridge – faults leave no half-booked state\n";
$a203_vp->fault = array( 'quote' => 'exception' );
$a203_before = $a203_state( $a203_v3['order_id'] );
$a203_err = VoucherCheckout::apply( $a203_v3['order_id'], 'WPA203-CRASH' );
check( 'quote exception: clean customer error without internals, order unchanged', is_wp_error( $a203_err ) && 409 === $a203_err->get_error_data()['status'] && false === strpos( $a203_err->get_error_message(), 'crashed' ) && $a203_before === $a203_state( $a203_v3['order_id'] ) );
$a203_vp->fault = array( 'reserve' => 'timeout' );
$a203_rel_before = $a203_vp->count( 'release' );
$a203_err = VoucherCheckout::apply( $a203_v3['order_id'], 'WPA203-SLOW' );
$a203_rel = $a203_vp->last( 'release' );
$a203_public = Orders::get( $a203_v3['order_id'] )->public_id;
check( 'reserve timeout: clean error, order and holds unchanged', is_wp_error( $a203_err ) && $a203_before === $a203_state( $a203_v3['order_id'] ) );
check( 'reserve timeout: a possibly made reservation is released by its idempotency key and the failure audited', $a203_rel_before + 1 === $a203_vp->count( 'release' ) && 'wpcap-order-' . $a203_public === ( $a203_rel['reservation']['idempotency_key'] ?? '' ) && 'wpcap-release-' . $a203_public === $a203_rel['idempotency_key'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='voucher_reserve_failed' AND object_id=" . (int) $a203_v3['order_id'] ) );
$a203_vp->fault = array( 'reserve' => 'exception', 'release' => 'exception' );
$a203_err = VoucherCheckout::apply( $a203_v3['order_id'], 'WPA203-CRASH2' );
check( 'reserve and release both throwing: still a clean error, order unchanged', is_wp_error( $a203_err ) && $a203_before === $a203_state( $a203_v3['order_id'] ) );
$a203_vp->fault = array( 'reserve' => 'garbage' );
$a203_err = VoucherCheckout::apply( $a203_v3['order_id'], 'WPA203-GARBAGE' );
check( 'a malformed reserve answer (no array) is refused, order unchanged', is_wp_error( $a203_err ) && $a203_before === $a203_state( $a203_v3['order_id'] ) );
check( 'bridge maps faults to WP_Errors with HTTP status (503 exception, 504 timeout, 502 malformed)', ( static function () use ( $a203_vp ) {
	$a203_vp->fault = array( 'commit' => 'exception' );
	$e1 = Bridge::commit( array(), 'k' );
	$a203_vp->fault = array( 'commit' => 'timeout' );
	$e2 = Bridge::commit( array(), 'k' );
	$a203_vp->fault = array( 'commit' => 'garbage' );
	$e3 = Bridge::commit( array(), 'k' );
	$a203_vp->fault = array();
	return 503 === $e1->get_error_data()['status'] && 504 === $e2->get_error_data()['status'] && 502 === $e3->get_error_data()['status'] && VoucherCheckout::is_temporary( $e1 ) && VoucherCheckout::is_temporary( $e2 );
} )() );

// Through the public checkout: a voucher timeout cancels the attempt and frees its capacity.
$a203_avail = Orders::availability( $a203_type );
$a203_vp->fault = array( 'reserve' => 'timeout' );
$a203_vco_body = wpa203_req( 'public', 'POST /checkout', array( 'email' => 'wpa203-vco@example.com', 'ticket_type_id' => $a203_type, 'idempotency_key' => 'wpa203-vco-' . uniqid() ) );
$a203_vco_body['voucher_code'] = 'WPA203-TIMEOUT';
$a203_vco = $a203_checkout( $a203_vco_body );
$a203_vco_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE customer_email=%s ORDER BY order_id DESC LIMIT 1", 'wpa203-vco@example.com' ) );
check( 'checkout with a timing-out voucher: error on the voucher field, attempt cancelled, holds released, capacity back', is_wp_error( $a203_vco ) && 'voucher_code' === ( $a203_vco->get_error_data()['field'] ?? '' ) && 'cancelled' === Orders::get( $a203_vco_order )->payment_status && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE status='active' AND order_id=$a203_vco_order" ) && $a203_avail === Orders::availability( $a203_type ) && null === Orders::get( $a203_vco_order )->voucher_snapshot );
$a203_vp->fault = array();

// Commit after payment: an exception keeps the reservation and schedules a retry (no lost voucher charge).
Orders::confirm_manual_payment( $a203_v1['order_id'] );
$a203_vp->fault = array( 'commit' => 'exception' );
$a203_commit = VoucherCheckout::commit( $a203_v1['order_id'] );
$a203_snap = VoucherCheckout::voucher_state( $a203_v1['order_id'] );
check( 'commit exception: order stays paid with tickets, voucher stays reserved, retry job queued', false === $a203_commit && 'reserved' === $a203_snap['status'] && 'paid' === Orders::get( $a203_v1['order_id'] )->payment_status && 2 === count( tickets( $a203_v1['order_id'], 'valid' ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE idempotency_key=%s AND status='queued'", hash( 'sha256', 'voucher-commit-' . (int) $a203_v1['order_id'] ) ) ) );
$a203_vp->fault = array( 'commit' => 'timeout' );
check( 'commit timeout: retried as well', false === VoucherCheckout::commit( $a203_v1['order_id'] ) && 'reserved' === VoucherCheckout::voucher_state( $a203_v1['order_id'] )['status'] );
$a203_vp->fault = array();
$a203_commits = $a203_vp->count( 'commit' );
check( 'after recovery the retry commits exactly once', true === VoucherCheckout::commit( $a203_v1['order_id'] ) && 'committed' === VoucherCheckout::voucher_state( $a203_v1['order_id'] )['status'] && true === VoucherCheckout::commit( $a203_v1['order_id'] ) && $a203_commits + 1 === $a203_vp->count( 'commit' ) );
wpa203_contract( 'voucher-bridge', 'provider.commit', 'request', $a203_vp->last( 'commit' ) );

// Refund of the voucher share: a throwing provider is retried, never lost.
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$a203_vt = tickets( $a203_v1['order_id'] );
$a203_refund = PassCaptain\Refunds::request( $a203_v1['order_id'], array( (int) $a203_vt[0]->ticket_id ), 'WPA203', 'wpa203-vr-' . uniqid() );
$a203_vp->fault = array( 'refund' => 'exception' );
$a203_r1 = VoucherCheckout::refund( $a203_refund['refund_id'], $a203_v1['order_id'] );
$a203_snap = VoucherCheckout::voucher_state( $a203_v1['order_id'] );
check( 'voucher refund exception: retry scheduled, refund remembered as failed, nothing booked twice', false === $a203_r1 && in_array( (int) $a203_refund['refund_id'], $a203_snap['failed_refund_ids'] ?? array(), true ) && 0 === (int) $a203_snap['refunded_minor'] );
$a203_vp->fault = array();
$a203_refund_calls = $a203_vp->count( 'refund' );
$a203_r2 = VoucherCheckout::refund( $a203_refund['refund_id'], $a203_v1['order_id'], true );
$a203_snap = VoucherCheckout::voucher_state( $a203_v1['order_id'] );
check( 'voucher refund after recovery: share refunded once', true === $a203_r2 && (int) $a203_snap['refunded_minor'] > 0 && ! in_array( (int) $a203_refund['refund_id'], $a203_snap['failed_refund_ids'] ?? array(), true ) && true === VoucherCheckout::refund( $a203_refund['refund_id'], $a203_v1['order_id'] ) && $a203_refund_calls + 1 === $a203_vp->count( 'refund' ) );
wpa203_contract( 'voucher-bridge', 'provider.refund', 'request', $a203_vp->last( 'refund' ) );

// Release of an unpaid order: a throwing provider is audited, the order state is not affected.
$a203_v4 = $a203_vorder( 'v4' );
VoucherCheckout::apply( $a203_v4['order_id'], 'WPA203-REL' );
Orders::cancel_order( $a203_v4['order_id'], 'test' );
$a203_vp->fault = array( 'release' => 'exception' );
VoucherCheckout::release( $a203_v4['order_id'] );
check( 'release exception: audited, snapshot kept for a later retry, order stays cancelled', 'reserved' === VoucherCheckout::voucher_state( $a203_v4['order_id'] )['status'] && 'cancelled' === Orders::get( $a203_v4['order_id'] )->payment_status && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='voucher_release_failed' AND object_id=" . (int) $a203_v4['order_id'] ) );
$a203_vp->fault = array();
VoucherCheckout::release( $a203_v4['order_id'] );
check( 'release after recovery', 'released' === VoucherCheckout::voucher_state( $a203_v4['order_id'] )['status'] );
wpa203_contract( 'voucher-bridge', 'provider.release', 'request', $a203_vp->last( 'release' ) );

echo "A203: Voucher Captain ticket service (inbound) contract\n";
$a203_ts = TicketService::service( null, 1 );
$a203_ts_req = wpa203_req( 'voucher-bridge', 'ticket_service.issue_tickets', array( 'redemption_key' => 'wpa203-red-1', 'idempotency_key' => 'wpa203-ts-' . uniqid(), 'ticket_type_id' => $a203_type, 'email' => 'wpa203-ts@example.com' ) );
$a203_issued = $a203_ts->issue_tickets( $a203_ts_req['source'], $a203_ts_req['idempotency_key'], $a203_ts_req['target'], $a203_ts_req['recipient'] );
check( 'issue_tickets accepts the committed request', is_array( $a203_issued ) && 'issued' === $a203_issued['status'] && 1 === count( $a203_issued['ticket_ids'] ) );
wpa203_contract( 'voucher-bridge', 'ticket_service.issue_tickets', 'response', $a203_issued );
$a203_ts_extra = wpa203_req( 'voucher-bridge', 'ticket_service.issue_tickets', array( 'redemption_key' => 'wpa203-red-2', 'idempotency_key' => 'wpa203-ts2-' . uniqid(), 'ticket_type_id' => $a203_type, 'email' => 'wpa203-ts2@example.com' ) );
$a203_ts_extra['source'] += array( 'campaign' => 'xmas', 'meta' => array( 'v' => 2 ) );
unset( $a203_ts_extra['source']['voucher_id'], $a203_ts_extra['recipient']['name'] );
$a203_ts_extra['target']['seat_hint'] = 'A1';
$a203_issued2 = $a203_ts->issue_tickets( $a203_ts_extra['source'], $a203_ts_extra['idempotency_key'], $a203_ts_extra['target'], $a203_ts_extra['recipient'] );
check( 'issue_tickets ignores unknown fields and handles missing optional ones (voucher_id, name)', is_array( $a203_issued2 ) && 'issued' === $a203_issued2['status'] );
wpa203_contract( 'voucher-bridge', 'ticket_service.issue_tickets', 'response', $a203_issued2 );
$a203_again = $a203_ts->issue_tickets( $a203_ts_req['source'], $a203_ts_req['idempotency_key'], $a203_ts_req['target'], $a203_ts_req['recipient'] );
check( 'a retried call with the same key answers the same tickets (reused)', is_array( $a203_again ) && true === $a203_again['reused'] && $a203_issued['ticket_ids'] === $a203_again['ticket_ids'] );
$a203_revoked_payload = null;
$a203_revoked_cb = static function ( $payload ) use ( &$a203_revoked_payload ) { $a203_revoked_payload = $payload; };
add_action( TicketService::REVOKED_ACTION, $a203_revoked_cb );
Orders::cancel_order( (int) $a203_issued2['order_id'], 'test' );
TicketService::order_closed( (int) $a203_issued2['order_id'] );
remove_action( TicketService::REVOKED_ACTION, $a203_revoked_cb );
check( 'revoked action fires with its payload', is_array( $a203_revoked_payload ) && 'cancelled' === $a203_revoked_payload['reason'] );
wpa203_contract( 'voucher-bridge', 'ticket_service.revoked_action', 'request', $a203_revoked_payload );
if ( null === $a203_saved_vp ) { unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ); } else { $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $a203_saved_vp; }

echo "A203: Affiliate Captain adapter contract\n";
$GLOBALS['wpac_test_version'] = '1.9.4';
check( 'Affiliate Captain of a newer minor/patch version is used', AffiliateAdapter::available() );
foreach ( array( '2.0.0', '0.9', '', 'eins', array( 1 ) ) as $a203_v ) { $GLOBALS['wpac_test_version'] = $a203_v; check( 'incompatible or malformed Affiliate Captain version is refused: ' . wp_json_encode( $a203_v ), ! AffiliateAdapter::available() ); }
$GLOBALS['wpac_test_version'] = new RuntimeException( 'version check failed' );
check( 'a throwing version check counts as incompatible', ! AffiliateAdapter::available() );
$GLOBALS['wpac_test_version'] = '1.1.0';
wpa203_contract( 'affiliate', 'wpac_registered_integrations', 'request', apply_filters( 'wpac_registered_integrations', array() )['pass-captain'] ?? null );
$GLOBALS['wpac_test_sale_response'] = (array) wpa203_example( 'affiliate', 'wpac_record_sale' ) + array( 'commission_minor' => 1234, 'x_wpac' => array( 'tier' => 2 ) );
$a203_aff_type = make_type( 20, 10, 11900 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$a203_aff_type" );
$a203_aff_order = static function ( $tag ) use ( $a203_aff_type ) {
	return Orders::create( array( 'email' => "wpa203-aff-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $a203_aff_type, 'quantity' => 2 ) ), 'idempotency_key' => "wpa203-aff-$tag-" . uniqid(), 'partner_code' => 'NORD-1' ) );
};
$a203_aff_reported = static function ( $order_id ) use ( $wpdb, $p ) {
	$s = json_decode( (string) $wpdb->get_var( "SELECT price_snapshot FROM {$p}wpcap_orders WHERE order_id=" . (int) $order_id ), true );
	return isset( $s['affiliate_lines'] );
};
$a203_ao = $a203_aff_order( 'a1' );
$a203_sales = count( $GLOBALS['wpac_test_sales'] );
Orders::confirm_manual_payment( $a203_ao['order_id'] );
$a203_sale = end( $GLOBALS['wpac_test_sales'] );
check( 'sale reported; an answer with unknown extra fields is accepted', $a203_sales + 1 === count( $GLOBALS['wpac_test_sales'] ) && (string) $a203_ao['order_id'] === $a203_sale['order_id'] && $a203_aff_reported( $a203_ao['order_id'] ) );
wpa203_contract( 'affiliate', 'wpac_record_sale', 'request', $a203_sale );
$a203_at = tickets( $a203_ao['order_id'] );
PassCaptain\Refunds::request( $a203_ao['order_id'], array( (int) $a203_at[0]->ticket_id ), 'WPA203', 'wpa203-ar1-' . uniqid() );
wpa203_contract( 'affiliate', 'wpac_record_refund (partial)', 'request', end( $GLOBALS['wpac_test_refunds'] ) );
Orders::cancel_order( $a203_ao['order_id'], 'storniert' );
wpa203_contract( 'affiliate', 'wpac_record_refund (full)', 'request', end( $GLOBALS['wpac_test_refunds'] ) );

$GLOBALS['wpac_test_sale_response'] = array();
$a203_ao2 = $a203_aff_order( 'a2' );
Orders::confirm_manual_payment( $a203_ao2['order_id'] );
check( 'an answer without the optional status field is accepted', $a203_aff_reported( $a203_ao2['order_id'] ) );
foreach ( array( 'exception' => new RuntimeException( 'wpac down' ), 'timeout' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ) as $a203_kind => $a203_fault ) {
	$GLOBALS['wpac_test_sale_fault'] = $a203_fault;
	$a203_ao3 = $a203_aff_order( 'f' . $a203_kind );
	Orders::confirm_manual_payment( $a203_ao3['order_id'] );
	$a203_res = AffiliateAdapter::on_paid( $a203_ao3['order_id'] );
	check( "affiliate $a203_kind: clean WP_Error, failure audited, order paid but not marked as reported", is_wp_error( $a203_res ) && 'paid' === Orders::get( $a203_ao3['order_id'] )->payment_status && ! $a203_aff_reported( $a203_ao3['order_id'] ) && 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='affiliate_sale_failed' AND object_id=" . (int) $a203_ao3['order_id'] ) );
	unset( $GLOBALS['wpac_test_sale_fault'] );
	$a203_before_sales = count( $GLOBALS['wpac_test_sales'] );
	AffiliateAdapter::on_paid( $a203_ao3['order_id'] );
	check( "affiliate $a203_kind: the next paid event reports the sale exactly once", $a203_aff_reported( $a203_ao3['order_id'] ) && $a203_before_sales + 1 === count( $GLOBALS['wpac_test_sales'] ) && null === AffiliateAdapter::on_paid( $a203_ao3['order_id'] ) );
}
unset( $GLOBALS['wpac_test_sale_response'] );
PassCaptain\Settings::update( array( 'enabled_providers' => $a203_saved_providers, 'voucher_default_purpose' => $a203_saved_purpose ) );
$GLOBALS['wpcap_test_transients'] = array();
