<?php
/**
 * Scoped, hashed API keys for read-only developer endpoints (A161: admin page, per-key rate limit,
 * expiry changes, route → scope map). A key belongs to one event and one WordPress user (its owner);
 * every request is checked against the owner's event assignment and location/partner scope (A119).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DeveloperKeys {
	/** Default requests per key and minute (filter wpcap_developer_key_rate_limit). */
	const DEFAULT_RATE_LIMIT = 600;

	/** Upper bound for a per-key rate limit. */
	const MAX_RATE_LIMIT = 10000;

	/**
	 * Developer REST routes ("METHOD route" relative to the namespace) and the scope each requires.
	 * Every route that accepts the X-WPCAP-Key header is listed here; docs/SECURITY.md mirrors it.
	 */
	const ROUTES = array(
		'GET /developer/events/{event_id}'               => 'events.read',
		'GET /developer/events/{event_id}/service-slots' => 'slots.read',
		'GET /events/{event_id}/reports/summary'         => 'reports.read',
	);

	/** Supported scopes => label. */
	public static function scopes() {
		return array(
			'events.read' => __( 'Eventdaten lesen (Name, Beschreibung, Status)', 'wp-pass-captain' ),
			'reports.read' => __( 'Eventbericht lesen (Umsatz je Währung, Check-ins)', 'wp-pass-captain' ),
			'slots.read' => __( 'Service-Zeitfenster lesen (Kapazität und Belegung, ohne Kundendaten)', 'wp-pass-captain' ),
		);
	}

	/** Whether the 3.10.0 column (per-key rate limit) exists. */
	public static function ready() {
		$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
		return false !== $version && '' !== $version && version_compare( $version, '3.10.0', '>=' );
	}

	/** Valid scopes of a list (unknown values dropped). */
	private static function clean_scopes( array $scopes ) {
		$scopes = array_map( static function ( $scope ) { return is_scalar( $scope ) ? trim( sanitize_text_field( (string) $scope ) ) : ''; }, $scopes );
		return array_values( array_unique( array_intersect( $scopes, array_keys( self::scopes() ) ) ) );
	}

	/** Validates an expiry (UTC 'Y-m-d H:i:s', in the future); '' = no expiry. */
	private static function clean_expiry( $expires_at ) {
		$expires_at = trim( (string) $expires_at );
		if ( '' === $expires_at ) { return ''; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires_at ) || strtotime( $expires_at . ' UTC' ) <= time() ) {
			return new \WP_Error( 'wpcap_api_key_expiry', __( 'Ablaufdatum muss in der Zukunft liegen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		return $expires_at;
	}

	/** Validates a per-key rate limit (0 = default). */
	private static function clean_rate_limit( $rate_limit ) {
		$rate_limit = is_scalar( $rate_limit ) ? (int) $rate_limit : 0;
		if ( $rate_limit < 0 || $rate_limit > self::MAX_RATE_LIMIT ) {
			/* translators: %d: maximum requests per minute */
			return new \WP_Error( 'wpcap_api_key_rate', sprintf( __( 'Das Ratenlimit muss zwischen 1 und %d Anfragen pro Minute liegen (leer = Standard).', 'wp-pass-captain' ), self::MAX_RATE_LIMIT ), array( 'status' => 400 ) );
		}
		return $rate_limit;
	}

	/**
	 * Issues a key for the current user. The secret is returned once and only its hash is stored.
	 *
	 * @param int    $event_id   Event the key is restricted to (the current user needs wpcap_manage_events there).
	 * @param array  $scopes     Scopes (see scopes()).
	 * @param string $label      Label.
	 * @param string $expires_at UTC 'Y-m-d H:i:s' or ''.
	 * @param int    $rate_limit Requests per minute, 0 = default.
	 */
	public static function issue( $event_id, array $scopes, $label = '', $expires_at = '', $rate_limit = 0 ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$scopes = self::clean_scopes( $scopes );
		if ( ! $event_id || ! $scopes ) { return new \WP_Error( 'wpcap_api_key_invalid', __( 'Event und mindestens ein unterstützter Scope sind erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$expires_at = self::clean_expiry( $expires_at );
		if ( is_wp_error( $expires_at ) ) { return $expires_at; }
		$rate_limit = self::clean_rate_limit( $rate_limit );
		if ( is_wp_error( $rate_limit ) ) { return $rate_limit; }
		$secret = bin2hex( random_bytes( 32 ) );
		$prefix = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$label = mb_substr( sanitize_text_field( (string) $label ), 0, 190 );
		$row = array( 'user_id' => get_current_user_id(), 'public_prefix' => $prefix, 'secret_hash' => hash( 'sha256', $secret ), 'event_id' => $event_id, 'scopes' => wp_json_encode( $scopes ), 'label' => $label, 'expires_at' => '' !== $expires_at ? $expires_at : null, 'created_at' => current_time( 'mysql', true ) );
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );
		if ( self::ready() ) {
			$row['rate_limit'] = $rate_limit;
			$formats[] = '%d';
		}
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_api_keys', $row, $formats );
		if ( false === $inserted ) { return new \WP_Error( 'wpcap_api_key_save', __( 'API-Key konnte nicht angelegt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$key_id = (int) $wpdb->insert_id;
		Audit::record( 'developer_key_issued', 'api_key', $key_id, array( 'event_id' => $event_id, 'scopes' => $scopes, 'prefix' => $prefix, 'label' => $label, 'expires_at' => '' !== $expires_at ? $expires_at : null, 'rate_limit' => $rate_limit ) );
		return array( 'api_key_id' => $key_id, 'key' => $prefix . '.' . $secret, 'prefix' => $prefix, 'scopes' => $scopes, 'expires_at' => '' !== $expires_at ? $expires_at : null, 'rate_limit' => self::rate_limit( (object) array( 'api_key_id' => $key_id, 'rate_limit' => $rate_limit ) ), 'shown_once' => true );
	}

	/** Effective requests per minute of a key row (own limit or the filtered default). */
	public static function rate_limit( $key ) {
		$own = (int) ( $key->rate_limit ?? 0 );
		$limit = $own > 0 ? $own : self::DEFAULT_RATE_LIMIT;
		/** Requests per minute for a developer API key (own limit or 600), e.g. to lower all keys. */
		return max( 1, (int) apply_filters( 'wpcap_developer_key_rate_limit', $limit, (int) ( $key->api_key_id ?? 0 ), $own ) );
	}

	/** Status of a key row: active, expired or revoked. */
	public static function status( $key ) {
		if ( ! empty( $key->revoked_at ) ) { return 'revoked'; }
		if ( ! empty( $key->expires_at ) && $key->expires_at <= current_time( 'mysql', true ) ) { return 'expired'; }
		return 'active';
	}

	/** Whether the last authorize() call was refused only because the key's rate limit was reached. */
	private static $rate_limited = false;

	/**
	 * REST permission callback for a developer route: true, false (401/403 by WordPress) or a 429 error
	 * when the key is valid but over its per-minute limit (Retry-After = seconds until the next minute).
	 *
	 * @return true|false|\WP_Error
	 */
	public static function permission( \WP_REST_Request $request, $scope, $event_id ) {
		if ( false !== self::authorize( $request, $scope, $event_id ) ) { return true; }
		if ( ! self::$rate_limited ) { return false; }
		$retry = max( 1, 60 - (int) gmdate( 's' ) );
		self::$retry_after = $retry;
		return new \WP_Error( 'wpcap_rate_limited', __( 'Ratenlimit des API-Keys erreicht. Bitte warte bis zur nächsten Minute.', 'wp-pass-captain' ), array( 'status' => 429, 'retry_after' => $retry ) );
	}

	/** Seconds for the Retry-After header of the current 429 response (0 = none). */
	private static $retry_after = 0;

	/** rest_post_dispatch: adds Retry-After to a developer-key 429. */
	public static function retry_after_header( $response ) {
		if ( self::$retry_after > 0 && is_object( $response ) && method_exists( $response, 'header' ) && method_exists( $response, 'get_status' ) && 429 === (int) $response->get_status() ) {
			$response->header( 'Retry-After', (string) self::$retry_after );
			self::$retry_after = 0;
		}
		return $response;
	}

	/**
	 * Checks the X-WPCAP-Key header for a scope and event; returns the owner's user id or false.
	 * Counts the request against the key's rate limit.
	 */
	public static function authorize( \WP_REST_Request $request, $scope, $event_id ) {
		global $wpdb;
		self::$rate_limited = false;
		$provided = (string) $request->get_header( 'x-wpcap-key' );
		$parts = explode( '.', $provided, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[a-f0-9]{12}$/', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) { return false; }
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_api_keys WHERE public_prefix=%s AND revoked_at IS NULL", $parts[0] ) );
		if ( ! $key || ! hash_equals( $key->secret_hash, hash( 'sha256', $parts[1] ) ) || ( $key->expires_at && $key->expires_at <= current_time( 'mysql', true ) ) || (int) $key->event_id !== absint( $event_id ) ) { return false; }
		$user_id = (int) $key->user_id;
		if ( ! user_can( $user_id, 'manage_options' ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id=%d AND user_id=%d AND capability IN ('wpcap_manage_events','wpcap_view_reports') LIMIT 1", $event_id, $user_id ) ) ) { return false; }
		// Location/partner scope of the key owner (A119): event-wide data only when every occurrence is in scope.
		if ( ! Access::event_fully_in_scope( $event_id, $user_id ) ) { return false; }
		$scopes = json_decode( $key->scopes, true );
		if ( ! is_array( $scopes ) || ! in_array( $scope, $scopes, true ) ) { return false; }
		$rate_key = 'wpcap_api_rate_' . (int) $key->api_key_id . '_' . gmdate( 'YmdHi' );
		$rate = (int) get_transient( $rate_key );
		if ( $rate >= self::rate_limit( $key ) ) {
			self::$rate_limited = true;
			return false;
		}
		set_transient( $rate_key, $rate + 1, MINUTE_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'api_key_id' => (int) $key->api_key_id ), array( '%s' ), array( '%d' ) );
		return (int) $key->user_id;
	}

	/** Key row the current user may manage (owner or administrator), or null. */
	private static function manageable( $key_id ) {
		global $wpdb;
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_api_keys WHERE api_key_id=%d", absint( $key_id ) ) );
		if ( ! $key || ( (int) $key->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) { return null; }
		return $key;
	}

	public static function revoke( $key_id ) {
		global $wpdb;
		$key = self::manageable( $key_id );
		if ( ! $key ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( $key->revoked_at ) { return true; }
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_api_keys SET revoked_at=%s WHERE api_key_id=%d AND revoked_at IS NULL", current_time( 'mysql', true ), (int) $key->api_key_id ) );
		Audit::record( 'developer_key_revoked', 'api_key', absint( $key_id ), array( 'prefix' => $key->public_prefix, 'event_id' => (int) $key->event_id ) );
		return true;
	}

	/**
	 * Changes expiry and rate limit of a key that is not revoked (an expired key becomes usable again
	 * with a new expiry in the future).
	 *
	 * @param int    $key_id     Key.
	 * @param string $expires_at New UTC expiry 'Y-m-d H:i:s' (future) or '' for no expiry.
	 * @param int    $rate_limit Requests per minute, 0 = default.
	 */
	public static function update( $key_id, $expires_at, $rate_limit = 0 ) {
		global $wpdb;
		$key = self::manageable( $key_id );
		if ( ! $key ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( $key->revoked_at ) { return new \WP_Error( 'wpcap_api_key_revoked', __( 'Ein widerrufener API-Key kann nicht mehr geändert werden. Lege einen neuen an.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$expires_at = self::clean_expiry( $expires_at );
		if ( is_wp_error( $expires_at ) ) { return $expires_at; }
		$rate_limit = self::clean_rate_limit( $rate_limit );
		if ( is_wp_error( $rate_limit ) ) { return $rate_limit; }
		$data = array( 'expires_at' => '' !== $expires_at ? $expires_at : null );
		$formats = array( '%s' );
		if ( self::ready() ) {
			$data['rate_limit'] = $rate_limit;
			$formats[] = '%d';
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', $data, array( 'api_key_id' => (int) $key->api_key_id ), $formats, array( '%d' ) );
		Audit::record( 'developer_key_updated', 'api_key', (int) $key->api_key_id, array( 'prefix' => $key->public_prefix, 'expires_at' => array( $key->expires_at, $data['expires_at'] ), 'rate_limit' => array( (int) ( $key->rate_limit ?? 0 ), $rate_limit ) ) );
		return true;
	}

	/**
	 * Keys for the admin list (newest first) with event name and owner.
	 *
	 * @param string $status '' (all), active, expired or revoked.
	 */
	public static function all( $status = '', $limit = 200 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$where = '';
		if ( 'revoked' === $status ) {
			$where = ' WHERE k.revoked_at IS NOT NULL';
		} elseif ( 'expired' === $status ) {
			$where = $wpdb->prepare( ' WHERE k.revoked_at IS NULL AND k.expires_at IS NOT NULL AND k.expires_at <= %s', $now );
		} elseif ( 'active' === $status ) {
			$where = $wpdb->prepare( ' WHERE k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > %s)', $now );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
		return $wpdb->get_results( $wpdb->prepare( "SELECT k.*,e.name AS event_name FROM {$wpdb->prefix}wpcap_api_keys k LEFT JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=k.event_id$where ORDER BY k.api_key_id DESC LIMIT %d", max( 1, min( 1000, absint( $limit ) ) ) ) );
	}

	/** Scopes of a key row. */
	public static function key_scopes( $key ) {
		$scopes = json_decode( (string) ( $key->scopes ?? '' ), true );
		return is_array( $scopes ) ? array_values( array_filter( $scopes, 'is_string' ) ) : array();
	}
}
