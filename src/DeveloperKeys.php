<?php
/**
 * Scoped, hashed API keys for developer endpoints (core: read-only scopes; add-ons: own scopes, see Api\Auth).
 * A161: admin page, per-key rate limit, expiry changes, route → scope map. A key belongs to one event and
 * one WordPress user (its owner); every request is checked against the owner's event assignment and
 * location/partner scope (A119), and the owner must still exist and hold the capability of that
 * assignment (A278).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DeveloperKeys {
	/** Scopes of the core (always available; add-ons add more with wp_pass_captain_api_scopes_v1). */
	const SCOPES = array( 'events.read', 'reports.read', 'slots.read' );

	/** Filter for additional scopes of add-ons (scope => label). */
	const SCOPES_FILTER = 'wp_pass_captain_api_scopes_v1';

	/**
	 * Developer REST routes ("METHOD route" relative to the namespace) and the scope each requires.
	 * Every route that accepts the X-WPCAP-Key header is listed here; docs/SECURITY.md mirrors it.
	 */
	const ROUTES = array(
		'GET /developer/events/{event_id}'               => 'events.read',
		'GET /developer/events/{event_id}/service-slots' => 'slots.read',
		'GET /events/{event_id}/reports/summary'         => 'reports.read',
	);

	/**
	 * Every supported scope with its label: the core scopes plus valid add-on scopes
	 * (`area.action`, lower case letters, digits, underscores; core scopes cannot be replaced).
	 *
	 * @return array<string,string>
	 */
	public static function scopes() {
		$core = array(
			'events.read' => __( 'Eventdaten lesen (Name, Beschreibung, Status)', 'pass-captain' ),
			'reports.read' => __( 'Eventbericht lesen (Umsatz je Währung, Check-ins)', 'pass-captain' ),
			'slots.read' => __( 'Service-Zeitfenster lesen (Kapazität und Belegung, ohne Kundendaten)', 'pass-captain' ),
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

	/** Requests per minute and key when nothing else is configured (filter wpcap_developer_key_rate_limit). */
	const DEFAULT_RATE_LIMIT = 600;

	/** Upper bound for a per-key rate limit (administrators). */
	const MAX_RATE_LIMIT = 10000;

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
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires_at ) || strtotime( $expires_at . ' UTC' ) <= Clock::now() ) {
			return new \WP_Error( 'wpcap_api_key_expiry', __( 'Ablaufdatum muss in der Zukunft liegen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		return $expires_at;
	}

	/** Validates a per-key rate limit (0 = default). */
	private static function clean_rate_limit( $rate_limit ) {
		$rate_limit = is_scalar( $rate_limit ) ? (int) $rate_limit : 0;
		if ( $rate_limit < 0 || $rate_limit > self::max_rate_limit() ) {
			/* translators: %d: maximum requests per minute */
			return new \WP_Error( 'wpcap_api_key_rate', sprintf( __( 'Das Ratenlimit muss zwischen 1 und %d Anfragen pro Minute liegen (leer = Standard).', 'pass-captain' ), self::max_rate_limit() ), array( 'status' => 400 ) );
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
		if ( ! $event_id || ! $scopes ) { return new \WP_Error( 'wpcap_api_key_invalid', __( 'Event und mindestens ein unterstützter Scope sind erforderlich.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$expires_at = self::clean_expiry( $expires_at );
		if ( is_wp_error( $expires_at ) ) { return $expires_at; }
		$rate_limit = self::clean_rate_limit( $rate_limit );
		if ( is_wp_error( $rate_limit ) ) { return $rate_limit; }
		$secret = bin2hex( random_bytes( 32 ) );
		$prefix = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$label = mb_substr( sanitize_text_field( (string) $label ), 0, 190 );
		$row = array( 'user_id' => get_current_user_id(), 'public_prefix' => $prefix, 'secret_hash' => hash( 'sha256', $secret ), 'event_id' => $event_id, 'scopes' => wp_json_encode( $scopes ), 'label' => $label, 'expires_at' => '' !== $expires_at ? $expires_at : null, 'created_at' => Clock::mysql() );
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );
		if ( self::ready() ) {
			$row['rate_limit'] = $rate_limit;
			$formats[] = '%d';
		}
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_api_keys', $row, $formats );
		if ( false === $inserted ) { return new \WP_Error( 'wpcap_api_key_save', __( 'API-Key konnte nicht angelegt werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
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
		if ( ! empty( $key->expires_at ) && $key->expires_at <= Clock::mysql() ) { return 'expired'; }
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
		$result = self::check( $request, $scope, $event_id );
		if ( ! is_wp_error( $result ) ) { return true; }
		if ( ! self::$rate_limited ) { return false; }
		$retry = max( 1, 60 - (int) gmdate( 's' ) );
		self::$retry_after = $retry;
		return new \WP_Error( 'wpcap_rate_limited', __( 'Ratenlimit des API-Keys erreicht. Bitte warte bis zur nächsten Minute.', 'pass-captain' ), array( 'status' => 429, 'retry_after' => $retry ) );
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
		self::$rate_limited = false;
		$denied = static function ( $code, $message, $status, array $data = array() ) {
			return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
		};
		$provided = (string) $request->get_header( 'x-wpcap-key' );
		if ( '' === $provided ) { return $denied( 'wpcap_api_key_missing', __( 'API-Key fehlt (Header X-WPCAP-Key).', 'pass-captain' ), 401 ); }
		$parts = explode( '.', $provided, 2 );
		$invalid = $denied( 'wpcap_api_key_invalid', __( 'API-Key ist ungültig, widerrufen oder abgelaufen.', 'pass-captain' ), 401 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[a-f0-9]{12}$/', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) { return $invalid; }
		$key = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_prefix=%s AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', $parts[0] ) );
		if ( ! $key || ! hash_equals( $key->secret_hash, hash( 'sha256', $parts[1] ) ) || ( $key->expires_at && $key->expires_at <= Clock::mysql() ) ) { return $invalid; }
		$event_id = absint( $event_id );
		if ( (int) $key->event_id !== $event_id ) { return $denied( 'wpcap_api_key_event', __( 'Dieser API-Key gilt nicht für dieses Event.', 'pass-captain' ), 403 ); }
		$user_id = (int) $key->user_id;
		// The key acts as its owner: the owner must still exist, hold a capability for the scope through a role
		// AND be staff of the event with it (A278).
		if ( ! self::owner_can( $user_id, $event_id, (string) $scope ) ) {
			return $denied( 'wpcap_api_key_event', __( 'Dieser API-Key gilt nicht für dieses Event.', 'pass-captain' ), 403 );
		}
		// Location/partner scope of the key owner (A119): event-wide data only when every occurrence is in scope.
		if ( ! Access::event_fully_in_scope( $event_id, $user_id ) ) {
			return $denied( 'wpcap_api_key_event', __( 'Dieser API-Key gilt nicht für dieses Event.', 'pass-captain' ), 403 );
		}
		$scopes = json_decode( $key->scopes, true );
		if ( ! is_array( $scopes ) || ! in_array( (string) $scope, $scopes, true ) ) { return $denied( 'wpcap_api_key_scope', __( 'Dem API-Key fehlt die nötige Berechtigung (Scope).', 'pass-captain' ), 403, array( 'scope' => (string) $scope ) ); }
		// Fixed window per calendar minute (RateLimit::allow() slots), counted atomically (QA-V2, A288);
		// Retry-After in permission() relies on the clock minute.
		if ( ! RateLimit::allow( 'developer_key|' . (int) $key->api_key_id, self::rate_limit( $key ), MINUTE_IN_SECONDS ) ) {
			self::$rate_limited = true;
			return $denied( 'wpcap_api_rate_limited', __( 'Ratenlimit des API-Keys erreicht. Bitte später erneut versuchen.', 'pass-captain' ), 429, array( 'retry_after' => max( 1, 60 - (int) gmdate( 's' ) ) ) );
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', array( 'last_used_at' => Clock::mysql() ), array( 'api_key_id' => (int) $key->api_key_id ), array( '%s' ), array( '%d' ) );
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
		$revoked = count( self::revoke_for_user( $user_id, 'user_removed' ) );
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

	/** @param int[] $ids Key ids. @return int Revoked keys. */
	private static function revoke_ids( array $ids, $reason ) {
		global $wpdb;
		$count = 0;
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			if ( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at=%s WHERE api_key_id=%d AND revoked_at IS NULL', $wpdb->prefix . 'wpcap_api_keys', Clock::mysql(), $id ) ) ) {
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

	/**
	 * Whether the key owner still exists and may read the event (A278): administrators (manage_options),
	 * otherwise an event-team entry for wpcap_manage_events or wpcap_view_reports whose capability the owner
	 * still holds through a role or user capability. A deleted or downgraded owner's keys stop working at
	 * once (and UserAccess revokes them on role removal and deletion).
	 */
	public static function owner_may_read( $user_id, $event_id ) {
		global $wpdb;
		$owner = $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( (int) $user_id ) : false;
		if ( ! $owner ) { return false; }
		if ( UserAccess::holds( $owner, 'manage_options' ) ) { return true; }
		$caps = $wpdb->get_col( $wpdb->prepare( "SELECT capability FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id=%d AND user_id=%d AND capability IN ('wpcap_manage_events','wpcap_view_reports')", absint( $event_id ), (int) $user_id ) );
		foreach ( $caps as $cap ) {
			if ( UserAccess::holds( $owner, (string) $cap ) ) { return true; }
		}
		return false;
	}

	/**
	 * Revokes the active keys of a user that the owner can no longer use (A278): all keys when $user is
	 * null (deleted user) or a reason string (e.g. 'user_removed'), otherwise ($user is the WP_User) those
	 * whose event the owner may no longer read.
	 *
	 * @param int                 $user_id User.
	 * @param \WP_User|string|null $user    Current user object, a revocation reason, or null.
	 * @return int[] Revoked key ids.
	 */
	public static function revoke_for_user( $user_id, $user = null ) {
		$reason = is_string( $user ) ? sanitize_key( $user ) : ( $user ? 'owner_access_lost' : 'owner_deleted' );
		$user = is_object( $user ) ? $user : null;
		global $wpdb;
		if ( ! Privacy::has_table( 'wpcap_api_keys' ) ) { return array(); }
		$keys = $wpdb->get_results( $wpdb->prepare( "SELECT api_key_id,event_id,public_prefix FROM {$wpdb->prefix}wpcap_api_keys WHERE user_id=%d AND revoked_at IS NULL", (int) $user_id ) );
		$revoked = array();
		foreach ( $keys as $key ) {
			if ( $user && self::owner_may_read( (int) $user_id, (int) $key->event_id ) ) { continue; }
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_api_keys SET revoked_at=%s WHERE api_key_id=%d AND revoked_at IS NULL", Clock::mysql(), (int) $key->api_key_id ) );
			Audit::record( 'developer_key_revoked', 'api_key', (int) $key->api_key_id, array( 'prefix' => $key->public_prefix, 'event_id' => (int) $key->event_id, 'reason' => $reason ) );
			$revoked[] = (int) $key->api_key_id;
		}
		return $revoked;
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
		if ( ! $key ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $key->revoked_at ) { return true; }
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_api_keys SET revoked_at=%s WHERE api_key_id=%d AND revoked_at IS NULL", Clock::mysql(), (int) $key->api_key_id ) );
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
		if ( ! $key ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $key->revoked_at ) { return new \WP_Error( 'wpcap_api_key_revoked', __( 'Ein widerrufener API-Key kann nicht mehr geändert werden. Lege einen neuen an.', 'pass-captain' ), array( 'status' => 409 ) ); }
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
		$now = Clock::mysql();
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
