<?php
/**
 * Write endpoints of the partner API (selling), registered by Api::register_routes():
 *
 *   GET  /partner/catalog                     orders:write   sellable ticket types, prices, availability
 *   POST /partner/orders                      orders:write   reservation or direct sale (Idempotency-Key)
 *   GET  /partner/orders/{id}                 orders:write   own API order
 *   POST /partner/orders/{id}/confirm         orders:write   payment collected by the partner → tickets
 *   POST /partner/orders/{id}/cancel          orders:cancel  release hold / refund within the window
 *   GET  /partner/orders/{id}/tickets         tickets:issue  ticket codes (holder of record)
 *   GET  /partner/sales/statement?period=     settlements:read  reseller statement (Wiederverkäufer-Abrechnung)
 *
 * Write calls (and code downloads) additionally pass the per-key write rate limit. Business logic:
 * Sales, Statement. Reference: docs/PARTNER-API.md.
 */
namespace PassCaptain\Partners;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SalesApi {
	const ORDER_ID = '(?P<order_id>[a-f0-9]{32})';

	/** route => list of [method, callback, scope, write]. */
	public static function routes() {
		return array(
			'/partner/catalog' => array( array( 'GET', 'catalog', 'orders:write', false ) ),
			'/partner/orders' => array( array( 'POST', 'create_order', 'orders:write', true ) ),
			'/partner/orders/' . self::ORDER_ID => array( array( 'GET', 'get_order', 'orders:write', false ) ),
			'/partner/orders/' . self::ORDER_ID . '/confirm' => array( array( 'POST', 'confirm_order', 'orders:write', true ) ),
			'/partner/orders/' . self::ORDER_ID . '/cancel' => array( array( 'POST', 'cancel_order', 'orders:cancel', true ) ),
			'/partner/orders/' . self::ORDER_ID . '/tickets' => array( array( 'GET', 'order_tickets', 'tickets:issue', true ) ),
			'/partner/sales/statement' => array( array( 'GET', 'statement', 'settlements:read', false ) ),
		);
	}

	/** Permission callback for write calls: authentication + scope (Api::permission) + write rate limit. */
	public static function write_permission( $scope ) {
		$auth = Api::permission( $scope );
		return static function ( $request ) use ( $auth ) {
			$result = $auth( $request );
			if ( true !== $result ) { return $result; }
			$ctx = Api::context();
			return Keys::allow_write( $ctx['key'], is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		};
	}

	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) { return $result; }
		$response = new \WP_REST_Response( $result['data'], (int) ( $result['status'] ?? 200 ) );
		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'X-WPCAP-Partner-API', Api::VERSION );
		}
		return $response;
	}

	/**
	 * JSON body of a request: array, null for an empty body, or WP_Error for anything that is not a
	 * JSON object.
	 */
	private static function body( $request, $required ) {
		$raw = method_exists( $request, 'get_body' ) ? trim( (string) $request->get_body() ) : '';
		if ( '' === $raw ) {
			return $required ? new \WP_Error( 'wpcap_partner_invalid_body', __( 'Der Request-Body muss ein JSON-Objekt sein.', 'pass-captain' ), array( 'status' => 400 ) ) : null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ( $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			return new \WP_Error( 'wpcap_partner_invalid_body', __( 'Der Request-Body muss ein JSON-Objekt sein.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		return $data;
	}

	private static function order_ref( $request ) {
		return (string) $request->get_param( 'order_id' );
	}

	/* ------------------------------------------------------------------ Endpoints */

	public static function catalog( $request ) {
		return self::respond( array( 'data' => array( 'ticket_types' => Sales::catalog( Api::context() ) ) ) );
	}

	public static function create_order( $request ) {
		$ctx = Api::context();
		$body = self::body( $request, true );
		if ( is_wp_error( $body ) ) { Sales::audit( $ctx, 'create', 0, $body ); return $body; }
		return self::respond( Sales::create( $ctx, $body, (string) $request->get_header( 'idempotency-key' ) ) );
	}

	public static function get_order( $request ) {
		return self::respond( Sales::get_order( Api::context(), self::order_ref( $request ) ) );
	}

	public static function confirm_order( $request ) {
		$ctx = Api::context();
		$body = self::body( $request, false );
		if ( is_wp_error( $body ) ) { Sales::audit( $ctx, 'confirm', 0, $body ); return $body; }
		return self::respond( Sales::confirm( $ctx, self::order_ref( $request ), $body ) );
	}

	public static function cancel_order( $request ) {
		$ctx = Api::context();
		$body = self::body( $request, false );
		if ( is_wp_error( $body ) ) { Sales::audit( $ctx, 'cancel', 0, $body ); return $body; }
		return self::respond( Sales::cancel( $ctx, self::order_ref( $request ), $body ) );
	}

	public static function order_tickets( $request ) {
		return self::respond( Sales::tickets( Api::context(), self::order_ref( $request ) ) );
	}

	/** Reseller statement of a month; like settlements only for keys without event restriction. */
	public static function statement( $request ) {
		$ctx = Api::context();
		if ( Keys::restricted_events( $ctx['key'] ) || Keys::restricted_types( $ctx['key'] ) ) {
			Keys::audit_failure( 'event_restricted_settlements', $ctx['key'], Keys::client_ip(), method_exists( $request, 'get_route' ) ? $request->get_route() : '' );
			return new \WP_Error( 'wpcap_partner_settlements_restricted', __( 'Abrechnungen sind nur mit API-Keys ohne Event-Einschränkung abrufbar.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$period = (string) $request->get_param( 'period' );
		if ( '' === $period ) { $period = gmdate( 'Y-m', strtotime( 'first day of last month' ) ); }
		if ( ! Settlements::valid_period( $period ) ) {
			return new \WP_Error( 'wpcap_settlement_period', __( 'Ungültiger Abrechnungszeitraum (JJJJ-MM).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		return self::respond( array( 'data' => Statement::payload( $ctx['partner'], $period ) ) );
	}
}
