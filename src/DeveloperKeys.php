<?php
/** Scoped, hashed API keys for developer endpoints (core: read-only scopes; add-ons: own scopes, see Api\Auth). */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DeveloperKeys {
	/** Scopes of the core (always available; add-ons add more with wp_pass_captain_api_scopes_v1). */
	const SCOPES = array( 'events.read', 'reports.read' );

	/** Filter for additional scopes of add-ons (scope => label). */
	const SCOPES_FILTER = 'wp_pass_captain_api_scopes_v1';

	/**
	 * Every supported scope with its label: the core scopes plus valid add-on scopes
	 * (`area.action`, lower case letters, digits, underscores; core scopes cannot be replaced).
	 *
	 * @return array<string,string>
	 */
	public static function scopes() {
		$core = array(
			'events.read' => __( 'Event-Daten lesen (events.read)', 'pass-captain' ),
			'reports.read' => __( 'Berichte lesen (reports.read)', 'pass-captain' ),
		);
		$extra = apply_filters( 'wp_pass_captain_api_scopes_v1', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $scope => $label ) {
			$scope = (string) $scope;
			if ( isset( $core[ $scope ] ) || ! preg_match( '/^[a-z][a-z0-9_]{0,40}\.[a-z][a-z0-9_]{0,40}$/', $scope ) ) { continue; }
			$label = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';
			$core[ $scope ] = '' !== $label ? $label : $scope;
		}
		return $core;
	}

	/** Requests per minute and key when nothing else is configured. */
	const DEFAULT_RATE_LIMIT = 600;

	/** Upper bound for a per-key rate limit (administrators). */
	const MAX_RATE_LIMIT = 100000;

	/** Upper bound for a per-key rate limit set by event staff without manage_options. */
	const STAFF_MAX_RATE_LIMIT = 6000;

	/** Highest rate limit the current user may set: MAX_RATE_LIMIT for administrators, else STAFF_MAX_RATE_LIMIT. */
	public static function max_rate_limit() {
		return current_user_can( 'manage_options' ) ? self::MAX_RATE_LIMIT : self::STAFF_MAX_RATE_LIMIT;
	}

	/** Clamps a requested per-key rate limit to max_rate_limit() (0/empty = default). */
	public static function normalize_rate_limit( $rate_limit ) {
		$rate_limit = is_scalar( $rate_limit ) ? absint( $rate_limit ) : 0;
		return $rate_limit ? min( self::max_rate_limit(), $rate_limit ) : self::DEFAULT_RATE_LIMIT;
	}

	public static function issue( $event_id, array $scopes, $label = '', $expires_at = '', $rate_limit = self::DEFAULT_RATE_LIMIT ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$allowed = array_keys( self::scopes() );
		$rate_limit = self::normalize_rate_limit( $rate_limit );
		$scopes = array_values( array_unique( array_intersect( array_map( static function ( $scope ) { return trim( sanitize_text_field( $scope ) ); }, $scopes ), $allowed ) ) );
		if ( ! $event_id || ! $scopes ) { return new \WP_Error( 'wpcap_api_key_invalid', __( 'Event und mindestens ein unterstützter Scope sind erforderlich.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$secret = bin2hex( random_bytes( 32 ) );
		$prefix = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_api_keys', array( 'user_id' => get_current_user_id(), 'public_prefix' => $prefix, 'secret_hash' => hash( 'sha256', $secret ), 'event_id' => $event_id, 'scopes' => wp_json_encode( $scopes ), 'label' => sanitize_text_field( $label ), 'rate_limit' => $rate_limit, 'expires_at' => $expires_at ? sanitize_text_field( $expires_at ) : null, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $inserted ) { return new \WP_Error( 'wpcap_api_key_save', __( 'API-Key konnte nicht angelegt werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$key_id = (int) $wpdb->insert_id;
		Audit::record( 'developer_key_issued', 'api_key', $key_id, array( 'event_id' => $event_id, 'scopes' => $scopes, 'rate_limit' => $rate_limit, 'expires_at' => $expires_at ? (string) $expires_at : '' ) );
		return array( 'api_key_id' => $key_id, 'key' => $prefix . '.' . $secret, 'prefix' => $prefix, 'scopes' => $scopes, 'rate_limit' => $rate_limit, 'shown_once' => true );
	}

	/** Core routes: user id of the key, or false (any reason). See check() for the detailed variant. */
	public static function authorize( \WP_REST_Request $request, $scope, $event_id ) {
		$result = self::check( $request, $scope, $event_id );
		return is_wp_error( $result ) ? false : (int) $result['user_id'];
	}

	/**
	 * Validates the X-WPCAP-Key header of a request for a scope and an event: format, secret, revocation,
	 * expiry, event binding, the key owner's access to the event, the scope and the per-key rate limit
	 * (requests per minute, shared by all routes). Counts the request and updates last_used_at on success.
	 *
	 * @return array{api_key_id:int,user_id:int,event_id:int,scopes:string[],scope:string}|\WP_Error 401 missing/invalid/
	 *         revoked/expired key, 403 wrong event/scope/no access, 429 rate limit (retry_after in seconds).
	 */
	public static function check( \WP_REST_Request $request, $scope, $event_id ) {
		global $wpdb;
		$denied = static function ( $code, $message, $status, array $data = array() ) {
			return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
		};
		$provided = (string) $request->get_header( 'x-wpcap-key' );
		if ( '' === $provided ) { return $denied( 'wpcap_api_key_missing', __( 'API-Key fehlt (Header X-WPCAP-Key).', 'pass-captain' ), 401 ); }
		$parts = explode( '.', $provided, 2 );
		$invalid = $denied( 'wpcap_api_key_invalid', __( 'API-Key ist ungültig, widerrufen oder abgelaufen.', 'pass-captain' ), 401 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[a-f0-9]{12}$/', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) { return $invalid; }
		$key = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_prefix=%s AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', $parts[0] ) );
		if ( ! $key || ! hash_equals( $key->secret_hash, hash( 'sha256', $parts[1] ) ) || ( $key->expires_at && $key->expires_at <= current_time( 'mysql', true ) ) ) { return $invalid; }
		$event_id = absint( $event_id );
		if ( (int) $key->event_id !== $event_id ) { return $denied( 'wpcap_api_key_event', __( 'Dieser API-Key gilt nicht für dieses Event.', 'pass-captain' ), 403 ); }
		$user_id = (int) $key->user_id;
		// The key acts as its owner: the owner must still hold a capability for the scope through a role AND be staff of the event with it.
		if ( ! self::owner_can( $user_id, $event_id, (string) $scope ) ) {
			return $denied( 'wpcap_api_key_event', __( 'Dieser API-Key gilt nicht für dieses Event.', 'pass-captain' ), 403 );
		}
		$scopes = json_decode( $key->scopes, true );
		if ( ! is_array( $scopes ) || ! in_array( (string) $scope, $scopes, true ) ) { return $denied( 'wpcap_api_key_scope', __( 'Dem API-Key fehlt die nötige Berechtigung (Scope).', 'pass-captain' ), 403, array( 'scope' => (string) $scope ) ); }
		$rate_key = 'wpcap_api_rate_' . (int) $key->api_key_id . '_' . gmdate( 'YmdHi' );
		$rate = (int) get_transient( $rate_key );
		$limit = isset( $key->rate_limit ) && (int) $key->rate_limit > 0 ? (int) $key->rate_limit : self::DEFAULT_RATE_LIMIT;
		if ( $rate >= $limit ) { return $denied( 'wpcap_api_rate_limited', __( 'Ratenlimit des API-Keys erreicht. Bitte später erneut versuchen.', 'pass-captain' ), 429, array( 'retry_after' => max( 1, 60 - (int) gmdate( 's' ) ) ) ); }
		set_transient( $rate_key, $rate + 1, MINUTE_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'api_key_id' => (int) $key->api_key_id ), array( '%s' ), array( '%d' ) );
		return array( 'api_key_id' => (int) $key->api_key_id, 'user_id' => $user_id, 'event_id' => $event_id, 'scopes' => array_values( array_map( 'strval', $scopes ) ), 'scope' => (string) $scope );
	}

	/** Whether a scope only reads data (`area.read`); every other scope (add-ons, e.g. orders.write) writes. */
	public static function is_read_scope( $scope ) {
		return (bool) preg_match( '/\.read$/', (string) $scope );
	}

	/**
	 * Capabilities that allow using a key with the scope: read scopes accept event management or
	 * reports, write scopes only event management.
	 *
	 * @return string[]
	 */
	public static function caps_for_scope( $scope ) {
		return self::is_read_scope( $scope ) ? array( 'wpcap_manage_events', 'wpcap_view_reports' ) : array( 'wpcap_manage_events' );
	}

	/**
	 * Whether the key owner may still act with the scope on the event: administrators always; everybody
	 * else needs one capability of caps_for_scope() both through their role (user_can) and as event staff
	 * for exactly that capability. A deleted user or a role change that removed the capability ends the key.
	 */
	public static function owner_can( $user_id, $event_id, $scope ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return false; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		foreach ( self::caps_for_scope( $scope ) as $cap ) {
			if ( user_can( $user_id, $cap ) && $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE event_id=%d AND user_id=%d AND capability=%s LIMIT 1', $wpdb->prefix . 'wpcap_event_staff', absint( $event_id ), $user_id, $cap ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Hooks that end keys and staff assignments of users who leave or lose their role. */
	public static function register() {
		add_action( 'delete_user', array( __CLASS__, 'user_removed' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'user_removed' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'role_changed' ), 10, 1 );
		add_action( 'remove_user_role', array( __CLASS__, 'role_changed' ), 10, 1 );
	}

	/** delete_user / remove_user_from_blog action (no return value). */
	public static function user_removed( $user_id ) {
		self::on_user_removed( $user_id );
	}

	/** set_user_role / remove_user_role action (no return value). */
	public static function role_changed( $user_id ) {
		self::on_role_changed( $user_id );
	}

	/**
	 * delete_user / remove_user_from_blog: revokes every key of the user and removes their event and
	 * date staff assignments on this site. Sites without plugin tables are skipped.
	 *
	 * @return int Revoked keys.
	 */
	public static function on_user_removed( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id || ! Ops\Status::table_exists( 'wpcap_api_keys' ) ) { return 0; }
		$revoked = self::revoke_for_user( $user_id, 'user_removed' );
		foreach ( array( 'wpcap_event_staff', 'wpcap_occurrence_staff' ) as $suffix ) {
			if ( Ops\Status::table_exists( $suffix ) ) { $wpdb->delete( $wpdb->prefix . $suffix, array( 'user_id' => $user_id ), array( '%d' ) ); }
		}
		return $revoked;
	}

	/**
	 * set_user_role / remove_user_role: revokes the keys of the user that the new role can no longer use
	 * (check() refuses them anyway; revoking makes it visible and permanent). Staff rows stay so a
	 * temporary role change can be undone without re-assigning events.
	 *
	 * @return int Revoked keys.
	 */
	public static function on_role_changed( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id || ! Ops\Status::table_exists( 'wpcap_api_keys' ) || user_can( $user_id, 'manage_options' ) ) { return 0; }
		$keys = $wpdb->get_results( $wpdb->prepare( 'SELECT api_key_id,scopes FROM %i WHERE user_id=%d AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', $user_id ) );
		$ids = array();
		foreach ( $keys as $key ) {
			$scopes = json_decode( (string) $key->scopes, true );
			foreach ( is_array( $scopes ) ? $scopes : array( '' ) as $scope ) {
				$usable = false;
				foreach ( self::caps_for_scope( $scope ) as $cap ) { $usable = $usable || user_can( $user_id, $cap ); }
				if ( ! $usable ) { $ids[] = (int) $key->api_key_id; break; }
			}
		}
		return self::revoke_ids( $ids, 'role_changed' );
	}

	/** Revokes all active keys of a user. @return int Revoked keys. */
	private static function revoke_for_user( $user_id, $reason ) {
		global $wpdb;
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT api_key_id FROM %i WHERE user_id=%d AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', absint( $user_id ) ) ) );
		return self::revoke_ids( $ids, $reason );
	}

	/** @param int[] $ids Key ids. @return int Revoked keys. */
	private static function revoke_ids( array $ids, $reason ) {
		global $wpdb;
		$count = 0;
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			if ( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at=%s WHERE api_key_id=%d AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', current_time( 'mysql', true ), $id ) ) ) {
				++$count;
				Audit::record( 'developer_key_revoked', 'api_key', $id, array( 'reason' => sanitize_key( $reason ) ) );
			}
		}
		return $count;
	}

	/** Changes the per-key rate limit (owner or administrator). */
	public static function set_rate_limit( $key_id, $rate_limit ) {
		global $wpdb;
		$key = $wpdb->get_row( $wpdb->prepare( 'SELECT api_key_id,user_id,rate_limit FROM %i WHERE api_key_id=%d AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', absint( $key_id ) ) );
		if ( ! $key || ( (int) $key->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$rate_limit = self::normalize_rate_limit( $rate_limit );
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', array( 'rate_limit' => $rate_limit ), array( 'api_key_id' => (int) $key->api_key_id ), array( '%d' ), array( '%d' ) );
		Audit::record( 'developer_key_rate_limit', 'api_key', (int) $key->api_key_id, array( 'from' => (int) $key->rate_limit, 'to' => $rate_limit ) );
		return $rate_limit;
	}

	public static function revoke( $key_id ) {
		global $wpdb;
		$key = $wpdb->get_row( $wpdb->prepare( 'SELECT api_key_id,user_id FROM %i WHERE api_key_id=%d', $wpdb->prefix . 'wpcap_api_keys', absint( $key_id ) ) );
		if ( ! $key || ( (int) $key->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at=%s WHERE api_key_id=%d AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', current_time( 'mysql', true ), absint( $key_id ) ) );
		Audit::record( 'developer_key_revoked', 'api_key', absint( $key_id ) );
		return true;
	}
}
