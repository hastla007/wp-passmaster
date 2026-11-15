<?php
/**
 * Public authentication helper for REST routes of add-ons that accept the core developer API keys
 * (header X-WPCAP-Key, admin page "Entwickler-API-Keys").
 *
 * request_can() runs exactly the checks of the core routes (DeveloperKeys::check()): key format and
 * secret, revocation, expiry, event binding, the key owner's access to the event, the scope and the
 * per-key rate limit (one counter per key and minute for core and add-on routes). Scopes of add-ons are
 * registered with the filter `wp_pass_captain_api_scopes_v1` and then appear on the admin page.
 *
 * Add-on routes still declare their own `permission_callback` (WordPress requires it) and call
 * request_can() there (docs/EXTENSIONS.md, "Pass Captain Pro / Add-on-Schnittstellen"):
 *
 *     'permission_callback' => static fn( WP_REST_Request $r ) => PassCaptain\Api\Auth::request_can( $r, 'orders.write' ),
 *
 * @package PassCaptain
 */

namespace PassCaptain\Api;

use PassCaptain\DeveloperKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Auth {
	/** @var \WeakMap<\WP_REST_Request,array>|null Authorised keys per request object (key_for()). */
	private static $keys = null;

	/**
	 * Whether the request carries a valid developer key with $scope for the event.
	 *
	 * @param \WP_REST_Request $request  The REST request.
	 * @param string           $scope    Scope, e.g. "events.read" or a scope of the add-on ("orders.write").
	 * @param int|null         $event_id Event the request acts on; null = route/query parameter `event_id`.
	 * @return true|\WP_Error 400 without event, 401 missing/invalid/revoked/expired key, 403 wrong event,
	 *                        scope or access, 429 rate limit (data retry_after; also sent as Retry-After).
	 */
	public static function request_can( \WP_REST_Request $request, $scope, $event_id = null ) {
		$scope = (string) $scope;
		if ( ! array_key_exists( $scope, DeveloperKeys::scopes() ) ) {
			return new \WP_Error( 'wpcap_api_scope_unknown', __( 'Unbekannter Scope.', 'pass-captain' ), array( 'status' => 403, 'scope' => $scope ) );
		}
		$event_id = null === $event_id ? absint( $request->get_param( 'event_id' ) ) : absint( $event_id );
		if ( ! $event_id ) {
			return new \WP_Error( 'wpcap_api_event_required', __( 'Für API-Keys ist ein Event erforderlich.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$result = DeveloperKeys::check( $request, $scope, $event_id );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			if ( ! empty( $data['retry_after'] ) ) { self::retry_after_header( (int) $data['retry_after'] ); }
			return $result;
		}
		self::$keys = self::$keys ?? new \WeakMap();
		self::$keys[ $request ] = $result;
		return true;
	}

	/**
	 * Key that request_can() authorised for this request (api_key_id, user_id = key owner, event_id, scopes,
	 * scope), or null. Lets the route act as the key owner, e.g. for capability checks.
	 */
	public static function key_for( \WP_REST_Request $request ) {
		return null !== self::$keys && isset( self::$keys[ $request ] ) ? self::$keys[ $request ] : null;
	}

	/** Sends Retry-After on the REST response of a rate-limited request. */
	private static function retry_after_header( $seconds ) {
		add_filter(
			'rest_post_dispatch',
			static function ( $response ) use ( $seconds ) {
				if ( $response instanceof \WP_REST_Response && 429 === $response->get_status() ) { $response->header( 'Retry-After', (string) max( 1, (int) $seconds ) ); }
				return $response;
			}
		);
	}
}
