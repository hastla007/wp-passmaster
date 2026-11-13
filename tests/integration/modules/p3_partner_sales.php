<?php
/**
 * Integration tests for selling through the partner API (src/Partners/Sales.php, SalesApi.php,
 * Statement.php): write scopes, validation, reserve → confirm, direct sale, idempotency, partner
 * contingents (incl. concurrency), Allocations holds, cancellation/refund, settlement attribution,
 * write rate limit, audit and PII.
 * Included from run.php. Also runnable as a concurrency worker:
 *   php p3_partner_sales.php --ps-child <partner_id> <key_id> <ticket_type_id> <start_microtime>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Allocations\Holds;
use PassCaptain\Modules\Allocations;
use PassCaptain\Orders;
use PassCaptain\Partners\AdminPage as PartnerAdmin;
use PassCaptain\Partners\Api as PartnerApi;
use PassCaptain\Partners\Keys as PartnerKeys;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Sales;
use PassCaptain\Partners\SalesApi;
use PassCaptain\Partners\Settlements;
use PassCaptain\Partners\Statement;

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--ps-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	Sales::register();
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	$key = PartnerKeys::get( (int) $argv[3] );
	$ctx = array( 'key' => $key, 'partner' => Partners::get( (int) $argv[2] ), 'scopes' => PartnerKeys::scopes( $key ), 'event_ids' => array() );
	$start = (float) $argv[5];
	while ( microtime( true ) < $start ) { usleep( 2000 ); }
	$r = Sales::create( $ctx, array( 'mode' => 'reserve', 'items' => array( array( 'ticket_type_id' => (int) $argv[4], 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'conc' . getmypid() . '@example.com' ) ), 'ps-conc-' . getmypid() );
	echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_code() : 'OK';
	exit;
}

if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }

Sales::register();
Allocations::register();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array();
$ps_user_before = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 1;
$_SERVER['REMOTE_ADDR'] = '203.0.113.44';
$ps_audit_start = (int) $wpdb->get_var( "SELECT COALESCE(MAX(audit_id),0) FROM {$p}wpcap_audit_logs" );

PartnerApi::register_routes();

/** Calls a registered partner route like the REST server: permission callback, then callback. */
function ps_req( $method, $route, $token, $body = null, array $params = array(), array $headers = array() ) {
	$def = $GLOBALS['wpcap_test_routes'][ 'wp-pass-captain/v1' . $route ] ?? null;
	if ( ! $def ) { return new WP_Error( 'no_route', $route, array( 'status' => 404 ) ); }
	$endpoints = isset( $def['methods'] ) ? array( $def ) : $def;
	$endpoint = null;
	foreach ( $endpoints as $e ) { if ( $method === $e['methods'] ) { $endpoint = $e; } }
	if ( ! $endpoint ) { return new WP_Error( 'no_method', $method, array( 'status' => 405 ) ); }
	$req = new WP_REST_Request( $method );
	if ( null !== $token ) { $req->set_header( 'Authorization', 'Bearer ' . $token ); }
	foreach ( $headers as $k => $v ) { $req->set_header( $k, $v ); }
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	if ( null !== $body ) { $req->set_body( is_string( $body ) ? $body : json_encode( $body ) ); }
	$auth = call_user_func( $endpoint['permission_callback'], $req );
	if ( true !== $auth ) { return $auth; }
	$res = call_user_func( $endpoint['callback'], $req );
	if ( $res instanceof WP_REST_Response ) { $GLOBALS['ps_last_status'] = $res->get_status(); return $res->get_data(); }
	return $res;
}
function ps_status( $r ) { return is_wp_error( $r ) ? (int) ( $r->get_error_data()['status'] ?? 0 ) : (int) ( $GLOBALS['ps_last_status'] ?? 200 ); }
function ps_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
function ps_create( $token, $body, $key ) { return ps_req( 'POST', '/partner/orders', $token, $body, array(), null === $key ? array() : array( 'Idempotency-Key' => $key ) ); }
function ps_order_route( $suffix = '' ) { return '/partner/orders/' . SalesApi::ORDER_ID . $suffix; }
function ps_confirm( $token, $id, $body = null ) { return ps_req( 'POST', ps_order_route( '/confirm' ), $token, $body, array( 'order_id' => $id ) ); }
function ps_cancel( $token, $id, $body = null ) { return ps_req( 'POST', ps_order_route( '/cancel' ), $token, $body, array( 'order_id' => $id ) ); }
function ps_order_id( $public_id ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) ); }
function ps_writes( $result, $action = '' ) {
	global $wpdb;
	$like = '%"result":"' . $result . '"%';
	$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action='partner_api_write' AND details LIKE %s", $like );
	if ( '' !== $action ) { $sql .= $wpdb->prepare( ' AND details LIKE %s', '%"action":"' . $action . '"%' ); }
	return (int) $wpdb->get_var( $sql );
}
function ps_event_of( $type ) { global $wpdb; return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type ); }
function ps_occ_of( $type ) { global $wpdb; return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type ); }
function ps_codes_valid( array $tickets ) {
	global $wpdb;
	foreach ( $tickets as $t ) {
		if ( empty( $t['code'] ) ) { return false; }
		$hash = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s", $t['id'] ) );
		if ( ! hash_equals( (string) $hash, hash( 'sha256', $t['code'] ) ) ) { return false; }
	}
	return (bool) $tickets;
}

echo "Partner sales: setup (roles, price list, keys)\n";
$ta = make_type( 50, 10, 2000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, name='Parkett' WHERE ticket_type_id=$ta" );
$tb = make_type( 50, 10, 1500 );
$tc = make_type( 50, 10, 1200 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=700 WHERE ticket_type_id=$tc" );
$ea = ps_event_of( $ta ); $eb = ps_event_of( $tb ); $ec = ps_event_of( $tc );
$period = gmdate( 'Y-m' );
$o1 = PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'name' => 'Sales Veranstalter GmbH', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0', 'fee_tax_rate' => '19', 'settlement_start' => $period . '-01', 'event_ids' => array( $ea, $eb ), 'role' => 'organizer', 'sales_channel' => 'Eigener Shop', 'hold_minutes' => '20', 'cancel_window_hours' => '48', 'anonymous_buyer' => '1' ) )['partner_id'] ?? 0;
$o2 = Partners::save( array( 'name' => 'Fremd-Veranstalter AG', 'currency' => 'EUR', 'settlement_start' => $period . '-01' ) );
Partners::set_events( $o2, array( $ec ) );
$r1 = PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'name' => 'Ticket-Reseller KG', 'currency' => 'EUR', 'commission' => '0', 'fee_per_ticket' => '0', 'fee_tax_rate' => '19', 'role' => 'reseller', 'sales_channel' => 'Reseller-Portal', 'hold_minutes' => '10', 'cancel_window_hours' => '0', 'reseller_commission' => '10', 'reseller_fee' => '0,50' ) )['partner_id'] ?? 0;
$org = Partners::get( $o1 );
$res = Partners::get( $r1 );
check( 'admin form stores role, channel, hold, cancel window, anonymous flag and reseller terms', 'organizer' === $org->role && 'Eigener Shop' === $org->sales_channel && 20 === (int) $org->hold_minutes && 48 === (int) $org->cancel_window_hours && 1 === (int) $org->anonymous_buyer && 'reseller' === $res->role && 1000 === (int) $res->reseller_commission_bp && 50 === (int) $res->reseller_fee_minor );
check( 'hold minutes are capped at 60 and roles validated', is_wp_error( Partners::save( array( 'name' => 'X', 'hold_minutes' => 61 ) ) ) && is_wp_error( Partners::save( array( 'name' => 'X', 'role' => 'admin' ) ) ) );
Partners::save( array( 'name' => 'Sales Veranstalter GmbH', 'currency' => 'EUR', 'commission_bp' => 1000, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $period . '-01' ), $o1 );
check( 'saving without sales fields keeps the stored API settings', 48 === (int) Partners::get( $o1 )->cancel_window_hours && 'Eigener Shop' === Partners::get( $o1 )->sales_channel );

check( 'organizer cannot list foreign ticket types; reseller can', is_wp_error( Sales::set_price( $o1, $tc, 1000 ) ) && true === Sales::set_price( $r1, $tc, 1000, true ) );
PartnerAdmin::admin_action( array( 'do' => 'save_price', 'partner_id' => $r1, 'ticket_type_id' => $ta, 'price' => '18,00', 'price_basis' => 'gross' ) );
$sellable_r = Sales::sellable_types( Partners::get( $r1 ) );
$sellable_o = Sales::sellable_types( Partners::get( $o1 ) );
check( 'reseller sells its price list (net price + 7 % VAT = 10,70), organizer all own types', array( $ta, $tc ) === array_keys( $sellable_r ) && 1800 === Sales::gross_price( $sellable_r[ $ta ], (object) array( 'tax_rate_bp' => 1900 ) ) && 1070 === Sales::gross_price( $sellable_r[ $tc ], (object) array( 'tax_rate_bp' => 700 ) ) && isset( $sellable_o[ $ta ], $sellable_o[ $tb ] ) && ! isset( $sellable_o[ $tc ] ) );

check( 'tickets:issue requires orders:write', is_wp_error( PartnerKeys::issue( $o1, array( 'tickets:issue' ) ) ) );
check( 'key restrictions must lie within the sellable assortment', is_wp_error( PartnerKeys::issue( $r1, array( 'orders:write' ), array( 'ticket_type_ids' => array( $tb ) ) ) ) && is_wp_error( PartnerKeys::issue( $r1, array( 'orders:write' ), array( 'event_ids' => array( $eb ) ) ) ) && ! is_wp_error( PartnerKeys::issue( $r1, array( 'orders:write' ), array( 'event_ids' => array( $ec ) ) ) ) );
$k_org = PartnerAdmin::admin_action( array( 'do' => 'issue_key', 'id' => $o1, 'scopes' => array( 'orders:write', 'orders:cancel', 'tickets:issue', 'orders:read' ), 'label' => 'Shop' ) );
$tok_org = $k_org['key'];
$tok_read = PartnerKeys::issue( $o1, array( 'events:read', 'orders:read' ) )['key'];
$tok_nocancel = PartnerKeys::issue( $o1, array( 'orders:write' ) )['key'];
$tok_pii = PartnerKeys::issue( $o1, array( 'orders:write', 'attendees:read' ) )['key'];
$k_res = PartnerKeys::issue( $r1, array( 'orders:write', 'orders:cancel', 'tickets:issue', 'settlements:read', 'orders:read' ) );
$tok_res = $k_res['key'];
$tok_res_tc = PartnerAdmin::admin_action( array( 'do' => 'issue_key', 'id' => $r1, 'scopes' => array( 'orders:write' ), 'ticket_type_ids' => (string) $tc, 'write_rate_limit' => '2' ) )['key'];
check( 'admin issues keys with write scopes', 4 === count( $k_org['scopes'] ) && in_array( 'tickets:issue', $k_org['scopes'], true ) );
$k_tc_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_partner_keys WHERE partner_id=" . (int) $r1 . ' ORDER BY key_id DESC LIMIT 1' );
check( 'key row stores ticket types and write limit', array( $tc ) === PartnerKeys::restricted_types( $k_tc_row ) && 2 === (int) $k_tc_row->write_rate_limit );

echo "Partner sales: scopes and validation\n";
$GLOBALS['wpcap_test_transients'] = array();
$buy = static fn( $type, $qty = 1, $extra = array() ) => array_merge( array( 'mode' => 'reserve', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'buyer' => array( 'email' => 'kunde@example.com' ) ), $extra );
$r = ps_create( $tok_read, $buy( $ta ), 'ps-scope-1' );
check( 'read-only key cannot create orders (403, audited scope_missing)', 403 === ps_status( $r ) && 'wpcap_partner_forbidden' === ps_code( $r ) );
check( 'missing Idempotency-Key is rejected', 'wpcap_partner_idempotency_key' === ps_code( ps_create( $tok_org, $buy( $ta ), null ) ) );
// A054: billing data above the threshold (threshold lowered to 10 € for this check).
$a54_threshold = PassCaptain\Settings::get( 'billing_required_threshold_minor' );
PassCaptain\Settings::update( array( 'billing_required_threshold_minor' => 1000 ) );
$a54_no = ps_create( $tok_org, $buy( $ta ), 'ps-a54-1' );
$a54_bad = ps_create( $tok_org, $buy( $ta, 1, array( 'billing' => array( 'first_name' => 'Ada' ) ) ), 'ps-a54-2' );
$a54_ok = ps_create( $tok_org, $buy( $ta, 1, array( 'billing' => array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => 'Ring 1', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT' ) ) ), 'ps-a54-3' );
check( 'A054: above the billing threshold the partner API requires "billing" (422)', 422 === ps_status( $a54_no ) && 'wpcap_partner_billing_required' === ps_code( $a54_no ) );
check( 'A054: incomplete billing data is rejected (400)', 'wpcap_partner_invalid_billing' === ps_code( $a54_bad ) );
$a54_oid = is_wp_error( $a54_ok ) ? 0 : ps_order_id( $a54_ok['order']['id'] );
check( 'A054: with billing the order is created and stores it', 201 === ps_status( $a54_ok ) && $a54_oid && 'Lovelace' === ( PassCaptain\Billing::for_order( $a54_oid )['last_name'] ?? '' ) );
if ( $a54_oid ) {
	// Released again and removed so the following checks start without partner orders.
	ps_cancel( $tok_org, $a54_ok['order']['id'] );
	$wpdb->query( "DELETE FROM {$p}wpcap_partner_orders WHERE order_id=" . (int) $a54_oid );
}
PassCaptain\Settings::update( array( 'billing_required_threshold_minor' => $a54_threshold ) );
check( 'unknown fields are rejected (strict validation)', 'wpcap_partner_invalid_field' === ps_code( ps_create( $tok_org, $buy( $ta, 1, array( 'unit_price' => 1 ) ), 'ps-val-1' ) ) );
check( 'client cannot pass prices or quantities as strings', 'wpcap_partner_invalid_items' === ps_code( ps_create( $tok_org, array( 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => '2', 'price_minor' => 1 ) ), 'buyer' => array( 'email' => 'a@example.com' ) ), 'ps-val-2' ) ) );
check( 'buyer e-mail is required', 'wpcap_partner_invalid_buyer' === ps_code( ps_create( $tok_org, array( 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ) ), 'ps-val-3' ) ) );
check( 'non-JSON body is rejected', 'wpcap_partner_invalid_body' === ps_code( ps_create( $tok_org, 'email=a@example.com', 'ps-val-4' ) ) );
check( 'anonymous buyers need the partner flag (reseller: 403)', 403 === ps_status( ps_create( $tok_res, array( 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'anonymous_buyer' => true ), 'ps-val-5' ) ) );
check( 'anonymous buyers need tickets:issue', 'wpcap_partner_anonymous_forbidden' === ps_code( ps_create( $tok_nocancel, array( 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'anonymous_buyer' => true ), 'ps-val-6' ) ) );
check( 'reseller cannot sell types outside its price list', 'wpcap_partner_type_forbidden' === ps_code( ps_create( $tok_res, $buy( $tb ), 'ps-val-7' ) ) );
check( 'organizer cannot sell foreign events', 'wpcap_partner_type_forbidden' === ps_code( ps_create( $tok_org, $buy( $tc ), 'ps-val-8' ) ) );
check( 'type-restricted key cannot sell other types', 'wpcap_partner_type_forbidden' === ps_code( ps_create( $tok_res_tc, $buy( $ta ), 'ps-val-9' ) ) );
check( 'rejections create no orders', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_partner_orders" ) );
check( 'every rejected write call is audited', ps_writes( 'error', 'create' ) >= 9 );

echo "Partner sales: reserve → confirm\n";
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_mails'] = array();
$r = ps_create( $tok_org, $buy( $ta, 2, array( 'external_reference' => 'SHOP-1001', 'attendees' => array( array( 'ticket_type_id' => $ta, 'name' => 'Ada' ) ) ) ), 'ps-reserve-0001' );
$res_id = $r['order']['id'] ?? '';
$oid = ps_order_id( $res_id );
$hold_until = strtotime( (string) ( $r['order']['hold_expires_at'] ?? '' ) );
check( 'reserve returns 201 with a pending order, hold of 20 minutes and no tickets', 201 === ps_status( $r ) && 'pending' === $r['order']['payment_status'] && 'reserve' === $r['order']['mode'] && abs( $hold_until - ( time() + 1200 ) ) < 90 && array() === $r['tickets'] && 'SHOP-1001' === $r['order']['external_reference'] && 'Eigener Shop' === $r['order']['channel'] );
$order_row = Orders::get( $oid );
check( 'order uses the core price, provider "partner" and counts against capacity', 'partner' === $order_row->payment_provider && 4000 === (int) $order_row->total_minor && 48 === Orders::availability( $ta ) );
check( 'no buyer e-mail in the response without attendees:read', ! array_key_exists( 'buyer_email', $r['order'] ) );
$sale = Sales::sale_row( $oid );
check( 'seller of record stored with key, mode and channel', $sale && (int) $sale->partner_id === (int) $o1 && 'reserve' === $sale->mode && (int) $sale->key_id === (int) $k_org['key_id'] );
$GLOBALS['wpcap_test_actions'] = array();
$c = ps_confirm( $tok_org, $res_id, array( 'payment_reference' => 'PAY-77' ) );
check( 'confirm marks paid and returns tickets with valid codes', 200 === ps_status( $c ) && 'paid' === $c['order']['payment_status'] && 2 === count( $c['tickets'] ) && ps_codes_valid( $c['tickets'] ) && null !== $c['order']['confirmed_at'] );
check( 'webhooks/hooks fire as usual (order paid, tickets issued)', in_array( 'wpcap_order_paid', $GLOBALS['wpcap_test_actions'], true ) && in_array( 'wpcap_tickets_issued', $GLOBALS['wpcap_test_actions'], true ) );
check( 'buyer with e-mail receives the ticket mail', (bool) array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'kunde@example.com' === $m['to'] ) );
check( 'payment reference stored as provider reference', 'PAY-77' === Orders::get( $oid )->provider_reference );
$c2 = ps_confirm( $tok_org, $res_id );
$dl = ps_req( 'GET', ps_order_route( '/tickets' ), $tok_org, null, array( 'order_id' => $res_id ) );
check( 'confirm is idempotent and the re-download returns the same codes (no rotation)', 200 === ps_status( $c2 ) && $c2['tickets'] === $c['tickets'] && array_column( $dl['tickets'], 'code' ) === array_column( $c['tickets'], 'code' ) && ps_codes_valid( $dl['tickets'] ) );
check( 'codes are stored encrypted only', ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_partner_ticket_codes WHERE code_box LIKE %s", '%' . $c['tickets'][0]['code'] . '%' ) ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_partner_ticket_codes WHERE order_id=$oid" ) );
$tid0 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE public_id=%s", $c['tickets'][0]['id'] ) );
$rotated = PassCaptain\Printing\Templates::rotate_codes( array( $tid0 ), 'print' );
$dl2 = ps_req( 'GET', ps_order_route( '/tickets' ), $tok_org, null, array( 'order_id' => $res_id ) );
check( 'staff rotation (print) updates the partner copy', $rotated[ $tid0 ] === $dl2['tickets'][0]['code'] && ps_codes_valid( $dl2['tickets'] ) );
Sales::on_code_rotated( $tid0, bin2hex( random_bytes( 32 ) ), 'transfer' );
$dl3 = ps_req( 'GET', ps_order_route( '/tickets' ), $tok_org, null, array( 'order_id' => $res_id ) );
check( 'after a transfer to another holder the partner gets no code', null === $dl3['tickets'][0]['code'] && null !== $dl3['tickets'][1]['code'] );
check( 'code download requires tickets:issue', 403 === ps_status( ps_req( 'GET', ps_order_route( '/tickets' ), $tok_nocancel, null, array( 'order_id' => $res_id ) ) ) );
$c_nocodes = ps_confirm( $tok_nocancel, $res_id );
check( 'without tickets:issue the confirm response has no codes', 200 === ps_status( $c_nocodes ) && ! array_key_exists( 'code', $c_nocodes['tickets'][0] ) );
$pii = ps_req( 'GET', ps_order_route(), $tok_pii, null, array( 'order_id' => $res_id ) );
check( 'buyer e-mail only with attendees:read', 'kunde@example.com' === ( $pii['order']['buyer_email'] ?? '' ) );
check( 'another partner cannot see or confirm the order (404)', 404 === ps_status( ps_req( 'GET', ps_order_route(), $tok_res, null, array( 'order_id' => $res_id ) ) ) && 404 === ps_status( ps_confirm( $tok_res, $res_id ) ) && 404 === ps_status( ps_cancel( $tok_res, $res_id ) ) );

$r = ps_create( $tok_org, $buy( $tb ), 'ps-expire-0001' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), ps_order_id( $r['order']['id'] ) ) );
$c = ps_confirm( $tok_org, $r['order']['id'] );
check( 'an expired reservation cannot be confirmed (409, no late recovery)', 'wpcap_partner_hold_expired' === ps_code( $c ) && 'pending' === Orders::get( ps_order_id( $r['order']['id'] ) )->payment_status );

echo "Partner sales: direct sale, partner prices, anonymous buyer\n";
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_mails'] = array();
$s = ps_create( $tok_org, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $tb, 'quantity' => 3 ) ), 'anonymous_buyer' => true, 'payment_reference' => 'KASSE-1' ), 'ps-sell-0001' );
$sid = ps_order_id( $s['order']['id'] ?? '' );
check( 'direct sale is paid in one call and returns 3 codes', 201 === ps_status( $s ) && 'paid' === $s['order']['payment_status'] && 'sell' === $s['order']['mode'] && 3 === count( $s['tickets'] ) && ps_codes_valid( $s['tickets'] ) && $s['order']['anonymous_buyer'] );
$placeholder = Orders::get( $sid )->customer_email;
check( 'anonymous buyer gets a placeholder address whose mails are suppressed', Sales::is_placeholder_email( $placeholder ) && true === Sales::suppress_placeholder_mail( null, array( 'to' => $placeholder ) ) && null === Sales::suppress_placeholder_mail( null, array( 'to' => 'a@example.com' ) ) );
$rs = ps_create( $tok_res, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 2 ) ), 'buyer' => array( 'email' => 'reseller-kunde@example.com' ), 'external_reference' => 'R-500' ), 'ps-res-0002' );
$rs_id = ps_order_id( $rs['order']['id'] ?? '' );
check( 'reseller sells with its partner price (2 × 18,00)', 201 === ps_status( $rs ) && 3600 === (int) Orders::get( $rs_id )->total_minor && 1800 === $rs['order']['lines'][0]['unit_price_minor'] && 2 === count( $rs['tickets'] ) );
$rs2 = ps_create( $tok_res_tc, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $tc, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'x@example.com' ) ), 'ps-res-0003' );
check( 'net price list entry is charged gross (10,00 + 7 % = 10,70); without tickets:issue no codes', 201 === ps_status( $rs2 ) && 1070 === $rs2['order']['total_minor'] && ! isset( $rs2['tickets'][0]['code'] ) );
$cat = ps_req( 'GET', '/partner/catalog', $tok_res );
$cat_by = array_column( $cat['ticket_types'] ?? array(), null, 'ticket_type_id' );
check( 'catalog lists the assortment with partner prices and availability', 2 === count( $cat_by ) && 1800 === $cat_by[ $ta ]['price_minor'] && 'partner' === $cat_by[ $ta ]['price_source'] && 1070 === $cat_by[ $tc ]['price_minor'] && is_int( $cat_by[ $ta ]['available'] ) );
$read_list = ps_req( 'GET', '/partner/orders', $tok_res, null, array() );
check( 'reseller read access does not expose other sellers\' orders of foreign events', 0 === (int) ( $read_list['total'] ?? -1 ) );

echo "Partner sales: idempotency\n";
$GLOBALS['wpcap_test_transients'] = array();
$again = ps_create( $tok_org, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $tb, 'quantity' => 3 ) ), 'anonymous_buyer' => true, 'payment_reference' => 'KASSE-1' ), 'ps-sell-0001' );
check( 'same key and body returns the same order (200, reused) with the same codes', 200 === ps_status( $again ) && true === $again['reused'] && $again['order']['id'] === $s['order']['id'] && array_column( $again['tickets'], 'code' ) === array_column( $s['tickets'], 'code' ) );
$diff = ps_create( $tok_org, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $tb, 'quantity' => 4 ) ), 'anonymous_buyer' => true ), 'ps-sell-0001' );
check( 'same key with a different body is rejected (422)', 422 === ps_status( $diff ) && 'wpcap_partner_idempotency_mismatch' === ps_code( $diff ) );
$other = ps_create( $tok_res, array( 'mode' => 'reserve', 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'y@example.com' ) ), 'ps-sell-0001' );
check( 'idempotency keys are separated per partner', 201 === ps_status( $other ) && $other['order']['id'] !== $s['order']['id'] );
check( 'only one order exists for the repeated key', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_partner_orders WHERE partner_id=%d AND mode='sell' AND anonymous=1", $o1 ) ) );

echo "Partner sales: contingents (sequential and concurrent)\n";
$GLOBALS['wpcap_test_transients'] = array();
$td = make_type( 50, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_events SET name='Kontingent-Event' WHERE event_id=" . ps_event_of( $td ) );
Sales::set_price( $r1, $td );
$cid = PartnerAdmin::admin_action( array( 'do' => 'save_contingent', 'partner_id' => $r1, 'occurrence_id' => ps_occ_of( $td ), 'ticket_type_id' => 0, 'quantity' => 5 ) )['contingent_id'] ?? 0;
check( 'contingent saved via admin (occurrence-wide, 5 units)', $cid && 5 === (int) Sales::contingent( $cid )->quantity );
check( 'contingent validation (type must belong to occurrence, quantity or hold needed)', is_wp_error( Sales::save_contingent( $r1, array( 'occurrence_id' => ps_occ_of( $td ), 'ticket_type_id' => $ta, 'quantity' => 1 ) ) ) && is_wp_error( Sales::save_contingent( $r1, array( 'occurrence_id' => ps_occ_of( $td ) ) ) ) );
$procs = array();
$start = microtime( true ) + 1.5;
for ( $i = 0; $i < 12; $i++ ) {
	$procs[] = popen( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $wpdb->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --ps-child ' . (int) $r1 . ' ' . (int) $k_res['key_id'] . ' ' . (int) $td . ' ' . $start . ' 2>&1', 'r' );
}
$outs = array();
foreach ( $procs as $proc ) { $outs[] = trim( stream_get_contents( $proc ) ); pclose( $proc ); }
$ok = count( array_filter( $outs, static fn( $o ) => 'OK' === $o ) );
$exhausted = count( array_filter( $outs, static fn( $o ) => 'ERR:wpcap_partner_order_rejected' === $o ) );
$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$td AND status='active'" );
check( "exactly 5 of 12 concurrent partner reservations fit the contingent (ok $ok, rejected $exhausted, held $held)", 5 === $ok && 7 === $exhausted && 5 === $held );
if ( 5 !== $ok ) { echo '    child output: ' . implode( ' | ', array_unique( $outs ) ) . "\n"; }
check( 'contingent usage counts active holds', 5 === Sales::contingent_used( Sales::contingent( $cid ) ) );
$more = ps_create( $tok_res, array( 'items' => array( array( 'ticket_type_id' => $td, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'z@example.com' ) ), 'ps-cont-0001' );
check( 'the next sale is rejected with the contingent message (409)', 409 === ps_status( $more ) && false !== strpos( $more->get_error_message(), 'Kontingent' ) );
check( 'the public checkout is not limited by the partner contingent', ! is_wp_error( Orders::create( array( 'email' => 'public@example.com', 'items' => array( array( 'ticket_type_id' => $td, 'quantity' => 2 ) ), 'idempotency_key' => 'ps-public-' . uniqid() ) ) ) );
$first = (int) $wpdb->get_var( "SELECT po.order_id FROM {$p}wpcap_partner_orders po INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=po.order_id WHERE oi.ticket_type_id=$td ORDER BY po.order_id LIMIT 1" );
$first_pub = Orders::get( $first )->public_id;
$rel = ps_cancel( $tok_res, $first_pub, array( 'reason' => 'Kunde abgesprungen' ) );
check( 'cancelling a reservation releases it', 200 === ps_status( $rel ) && true === $rel['released'] && 'cancelled' === $rel['order']['status'] && 4 === Sales::contingent_used( Sales::contingent( $cid ) ) );
$more = ps_create( $tok_res, array( 'items' => array( array( 'ticket_type_id' => $td, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'z@example.com' ) ), 'ps-cont-0002' );
check( 'released units can be sold again', 201 === ps_status( $more ) );

echo "Partner sales: selling from an Allocations hold\n";
$GLOBALS['wpcap_test_transients'] = array();
$te = make_type( 0, 10, 2500, 4 );
Sales::set_price( $r1, $te );
$hold = Holds::create( array( 'occurrence_id' => ps_occ_of( $te ), 'name' => 'Reseller-Kontingent', 'kind' => 'hold', 'quantity' => 3, 'sources' => 'partner' ) );
check( 'fixture: hold of 3 on an occurrence of 4 (1 public)', ! is_wp_error( $hold ) && 1 === Orders::availability( $te ) );
check( 'contingent can name the hold (validated against the occurrence)', is_wp_error( Sales::save_contingent( $r1, array( 'occurrence_id' => ps_occ_of( $td ), 'allocation_id' => (int) $hold->hold_id ) ) ) && Sales::save_contingent( $r1, array( 'occurrence_id' => ps_occ_of( $te ), 'allocation_id' => (int) $hold->hold_id ) ) > 0 );
$h1 = ps_create( $tok_res, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $te, 'quantity' => 3 ) ), 'buyer' => array( 'email' => 'h@example.com' ) ), 'ps-hold-0001' );
check( 'partner sale consumes the hold', 201 === ps_status( $h1 ) && 3 === (int) Holds::get( $hold->hold_id )->used && 1 === Orders::availability( $te ) );
$h2 = ps_create( $tok_res, array( 'items' => array( array( 'ticket_type_id' => $te, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'h@example.com' ) ), 'ps-hold-0002' );
check( 'an exhausted hold rejects further partner sales', 409 === ps_status( $h2 ) );
check( 'the public unit is still available to the public', ! is_wp_error( Orders::create( array( 'email' => 'pub2@example.com', 'items' => array( array( 'ticket_type_id' => $te, 'quantity' => 1 ) ), 'idempotency_key' => 'ps-pub-hold-' . uniqid() ) ) ) );

echo "Partner sales: cancellation and refunds\n";
$GLOBALS['wpcap_test_actions'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$avail_before = Orders::availability( $ta );
$cx = ps_cancel( $tok_org, $res_id, array( 'reason' => 'Rückgabe' ) );
$refund = $wpdb->get_row( "SELECT * FROM {$p}wpcap_refunds WHERE order_id=$oid" );
check( 'paid order inside the window: refund recorded (external), tickets revoked, order refunded', 200 === ps_status( $cx ) && 4000 === (int) ( $cx['refund']['amount_minor'] ?? 0 ) && $refund && 'completed' === $refund->status && 'refunded' === Orders::get( $oid )->status && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$oid AND status='valid'" ) );
check( 'refund fires the usual hooks (webhooks: order.refunded, ticket.revoked)', in_array( 'wpcap_refund_completed', $GLOBALS['wpcap_test_actions'], true ) && in_array( 'wpcap_ticket_revoked', $GLOBALS['wpcap_test_actions'], true ) );
check( 'capacity returns after the refund', $avail_before + 2 === Orders::availability( $ta ) );
$cx2 = ps_cancel( $tok_org, $res_id );
check( 'cancel is idempotent', 200 === ps_status( $cx2 ) && true === $cx2['already_cancelled'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=$oid" ) );
check( 'cancel requires orders:cancel', 403 === ps_status( ps_cancel( $tok_nocancel, $s['order']['id'] ) ) );
$cn = ps_cancel( $tok_res, $rs['order']['id'] );
check( 'no cancellation window → paid orders cannot be cancelled', 'wpcap_partner_cancel_not_allowed' === ps_code( $cn ) && 'paid' === Orders::get( $rs_id )->payment_status );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE order_id=%d ORDER BY ticket_id LIMIT 1", $sid ) );
check( 'checked-in tickets block the cancellation', 'wpcap_partner_cancel_checked_in' === ps_code( ps_cancel( $tok_org, $s['order']['id'] ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='valid' WHERE order_id=%d AND status='checked_in'", $sid ) );
if ( ! function_exists( 'a46_checkin' ) ) {
	/** Check-in history row for a ticket that stays "valid" (re-entry / multi-entry / pass). */
	function a46_checkin( $ticket_id, $result ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_checkins (ticket_id,event_id,occurrence_day_id,user_id,result,created_at) SELECT t.ticket_id,o.event_id,0,0,%s,%s FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", $result, gmdate( 'Y-m-d H:i:s' ), (int) $ticket_id ) );
	}
}
$a46_tid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id LIMIT 1", $sid ) );
a46_checkin( $a46_tid, 'checked_in' );
check( 'A046: a valid ticket admitted before (re-entry) blocks the cancellation', 'wpcap_partner_cancel_checked_in' === ps_code( ps_cancel( $tok_org, $s['order']['id'] ) ) );
a46_checkin( $a46_tid, 'undone' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_orders SET confirmed_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 49 * 3600 ), $sid ) );
check( 'after the window the cancellation is refused', 'wpcap_partner_cancel_window_closed' === ps_code( ps_cancel( $tok_org, $s['order']['id'] ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_orders SET confirmed_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s' ), $sid ) );

echo "Partner sales: settlements attribution\n";
$GLOBALS['wpcap_test_transients'] = array();
// Reseller sale R-500 (2 × 18,00) of the organizer's event and a refunded reseller order.
$rr = ps_create( $tok_res, array( 'mode' => 'sell', 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'refund@example.com' ) ), 'ps-res-refund' );
$rr_id = ps_order_id( $rr['order']['id'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partners SET cancel_window_hours=24 WHERE partner_id=%d", $r1 ) );
$rrc = ps_cancel( $tok_res, $rr['order']['id'] );
check( 'reseller with a window can cancel its paid order', 200 === ps_status( $rrc ) && 1800 === (int) $rrc['refund']['amount_minor'] );
$org_calc = Settlements::compute( Partners::get( $o1 ), $period );
$org_orders = array_unique( array_column( $org_calc['lines'], 'order_id' ) );
check( 'organizer settlement includes the reseller sale of its event', in_array( $rs_id, $org_orders, true ) && in_array( $rr_id, $org_orders, true ) && in_array( $sid, $org_orders, true ) );
check( 'organizer settlement does not include the reseller\'s sale of a foreign event', ! in_array( ps_order_id( $rs2['order']['id'] ), $org_orders, true ) );
$foreign = Settlements::compute( Partners::get( $o2 ), $period );
check( 'the foreign organizer is settled for the reseller sale of its event', in_array( ps_order_id( $rs2['order']['id'] ), array_column( $foreign['lines'], 'order_id' ), true ) );
$st = Statement::compute( Partners::get( $r1 ), $period );
$t = $st['totals'];
$r1_sales = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(ord.total_minor),0) FROM {$p}wpcap_partner_orders po INNER JOIN {$p}wpcap_orders ord ON ord.order_id=po.order_id WHERE po.partner_id=%d AND ord.payment_status IN ('paid','refunded')", $r1 ) );
check( 'reseller statement lists only the reseller\'s sales (gross) and its refund', $r1_sales === $t['sales_gross'] && 1800 === $t['refunded'] && $t['gross'] === $r1_sales - 1800 && ! array_filter( $st['lines'], static fn( $l ) => $l['order'] === $s['order']['id'] ) );
$expected_commission = array_sum( array_map( static fn( $l ) => (int) round( $l['net_minor'] * 1000 / 10000 ), $st['lines'] ) );
check( 'reseller commission 10 % of net and 0,50 per ticket, refund reversed', $expected_commission === $t['commission'] && 50 * ( $t['tickets_sold'] - $t['tickets_refunded'] ) === $t['fee'] && $t['due_to_platform'] === $t['gross'] - $t['remuneration_gross'] && $t['remuneration_tax'] === (int) round( $t['remuneration_net'] * 0.19 ) );
$api_st = ps_req( 'GET', '/partner/sales/statement', $tok_res, null, array( 'period' => $period ) );
check( 'statement via API (settlements:read)', 200 === ps_status( $api_st ) && $t['due_to_platform'] === $api_st['totals']['due_to_platform_minor'] && count( $st['lines'] ) === count( $api_st['lines'] ) );
check( 'statement refused for type-restricted keys / without scope', 403 === ps_status( ps_req( 'GET', '/partner/sales/statement', $tok_res_tc, null, array( 'period' => $period ) ) ) );
$org_st = Statement::compute( Partners::get( $o1 ), $period )['totals'];
check( 'organizer API sales: no reseller remuneration, everything collected is due', 0 === $org_st['commission'] && $org_st['due_to_platform'] === $org_st['gross'] );
check( 'statement CSV has a header and totals', count( Statement::csv_rows( Partners::get( $r1 ), $period ) ) === count( $st['lines'] ) + 7 );
// Audit 3 (A023): reseller remuneration without VAT for a small business and with reverse charge.
check( 'A023: domestic reseller: remuneration with VAT, document is the reseller invoice', $t['remuneration_tax'] > 0 && 'reseller_invoice' === $t['remuneration_document'] && false === $t['remuneration_reverse_charge'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partners SET small_business=1 WHERE partner_id=%d", $r1 ) );
$t_sb = Statement::compute( Partners::get( $r1 ), $period )['totals'];
check( 'A023: small business reseller: no VAT on the remuneration, with note', 0 === $t_sb['remuneration_tax'] && true === $t_sb['remuneration_small_business'] && '' !== $t_sb['remuneration_note'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partners SET small_business=0,country='AT' WHERE partner_id=%d", $r1 ) );
PassCaptain\Settings::update( array( 'seller_country' => 'DE', 'seller_vat_id' => 'DE123456789' ) );
$t_rc = Statement::compute( Partners::get( $r1 ), $period )['totals'];
check( 'A023: reseller in another EU state: reverse charge, no VAT', 0 === $t_rc['remuneration_tax'] && true === $t_rc['remuneration_reverse_charge'] && false !== strpos( $t_rc['remuneration_note'], 'Reverse Charge' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partners SET country='' WHERE partner_id=%d", $r1 ) );
// Audit 3 (A029): a reseller reads only the orders it sold, not all buyers of its linked events.
$a29_type = make_type( 20, 10, 1500 );
$a29_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a29_type" );
PassCaptain\Partners\Repository::set_events( $r1, array( $a29_event ) );
$GLOBALS['wpcap_test_transients'] = array();
$a29_online = Orders::create( array( 'email' => 'online-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $a29_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a29-' . uniqid() ) );
Orders::confirm_external_payment( $a29_online['order_id'], 'test' );
$tok_res_read = PartnerKeys::issue( $r1, array( 'orders:read', 'tickets:read', 'attendees:read' ) )['key'];
$res_orders = ps_req( 'GET', '/partner/orders', $tok_res_read );
$res_ids = array_column( (array) ( $res_orders['orders'] ?? array() ), 'id' );
check( 'A029: reseller does not see online orders (and their e-mails) of its linked event', 200 === ps_status( $res_orders ) && ! in_array( Orders::get( $a29_online['order_id'] )->public_id, $res_ids, true ) && false === strpos( wp_json_encode( $res_orders ), 'online-buyer' ) );
$res_tickets = ps_req( 'GET', '/partner/tickets', $tok_res_read );
check( 'A029: reseller tickets exclude foreign sales', 200 === ps_status( $res_tickets ) && ! in_array( Orders::get( $a29_online['order_id'] )->public_id, array_column( (array) ( $res_tickets['tickets'] ?? array() ), 'order_id' ), true ) );
$o1_before = PassCaptain\Partners\Repository::event_ids( $o1 );
PassCaptain\Partners\Repository::set_events( $r1, array() );
PassCaptain\Partners\Repository::set_events( $o1, array_merge( $o1_before, array( $a29_event ) ) );
$tok_org_read = PartnerKeys::issue( $o1, array( 'orders:read' ) )['key'];
$org_orders = ps_req( 'GET', '/partner/orders', $tok_org_read );
check( 'A029: an organizer still sees all orders of its events', in_array( Orders::get( $a29_online['order_id'] )->public_id, array_column( (array) ( $org_orders['orders'] ?? array() ), 'id' ), true ) );
PassCaptain\Partners\Repository::set_events( $o1, $o1_before );

echo "Partner sales: write rate limit and audit\n";
$GLOBALS['wpcap_test_transients'] = array();
$rl = array();
for ( $i = 0; $i < 3; $i++ ) { $rl[] = ps_status( ps_create( $tok_res_tc, array( 'items' => array( array( 'ticket_type_id' => $tc, 'quantity' => 1 ) ), 'buyer' => array( 'email' => 'rl@example.com' ) ), 'ps-rl-000' . $i ) ); }
check( 'write calls are limited per key (2/min → third 429)', array( 201, 201, 429 ) === $rl );
check( 'reads are not counted against the write limit', 200 === ps_status( ps_req( 'GET', '/partner/catalog', $tok_res_tc ) ) );
check( 'write rate limiting is audited', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='partner_key_auth_failed' AND details LIKE '%\"reason\":\"write_rate_limited\"%'" ) );
check( 'successful writes are audited per action', ps_writes( 'ok', 'create' ) >= 8 && ps_writes( 'ok', 'confirm' ) >= 2 && ps_writes( 'ok', 'cancel' ) >= 3 && ps_writes( 'ok', 'tickets_download' ) >= 3 );
$leak = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='partner_api_write' AND (details LIKE '%@example.com%' OR details LIKE '%ps-reserve-0001%' OR details LIKE '%wpcap_pk_%')" );
check( 'audit entries contain no e-mail, idempotency key or secret', 0 === $leak );

// partners.php counts key auth failures globally: drop the ones created here.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_audit_logs WHERE action='partner_key_auth_failed' AND audit_id > %d", $ps_audit_start ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_user_id'] = $ps_user_before;
