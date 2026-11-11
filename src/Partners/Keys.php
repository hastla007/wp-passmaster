<?php
/**
 * Scoped partner API keys.
 *
 * Format: "wpcap_pk_{12 hex prefix}_{64 hex secret}". Only the public prefix and the SHA-256 hash of
 * the 256-bit random secret are stored; the full key is returned once on creation. Keys carry scopes,
 * an optional event restriction (subset of the partner's events), an optional IP allowlist
 * (addresses or CIDR ranges, IPv4/IPv6), an optional expiry and a per-key rate limit. Failed
 * authentications are audited and lock the client address out after repeated failures.
 */
namespace PassCaptain\Partners;

use PassCaptain\Audit;
use PassCaptain\RateLimit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Keys {
	const TOKEN_PREFIX = 'wpcap_pk_';
	/** Read scopes. attendees:read additionally unlocks buyer and attendee e-mail addresses. */
	const SCOPES = array( 'events:read', 'orders:read', 'tickets:read', 'checkins:read', 'reports:read', 'settlements:read', 'attendees:read' );
	/** Failed authentications per client address and window before the address is locked out. */
	const FAIL_LIMIT = 20;
	const FAIL_WINDOW = 600;

	public static function scope_labels() {
		return array(
			'events:read' => __( 'Events, Termine und Ticketarten lesen', 'wp-pass-captain' ),
			'orders:read' => __( 'Bestellungen lesen (ohne E-Mail-Adressen)', 'wp-pass-captain' ),
			'tickets:read' => __( 'Tickets lesen (ohne E-Mail-Adressen)', 'wp-pass-captain' ),
			'checkins:read' => __( 'Check-ins lesen', 'wp-pass-captain' ),
			'reports:read' => __( 'Berichte (Umsatz, Auslastung) lesen', 'wp-pass-captain' ),
			'settlements:read' => __( 'Abgeschlossene Abrechnungen lesen', 'wp-pass-captain' ),
			'attendees:read' => __( 'Personenbezogene Daten: E-Mail-Adressen von Käufer:innen und Teilnehmenden', 'wp-pass-captain' ),
		);
	}

	/**
	 * Issues a key.
	 *
	 * @param array $args label, event_ids (int[], empty = all partner events), ip_allowlist (string|array),
	 *                    expires_at (UTC "Y-m-d H:i:s" or ''), rate_limit (requests/minute, 0 = default).
	 * @return array|\WP_Error key_id, key (shown once), prefix, scopes.
	 */
	public static function issue( $partner_id, array $scopes, array $args = array() ) {
		global $wpdb;
		$partner = Repository::get( $partner_id );
		if ( ! $partner ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Partner nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$scopes = array_values( array_intersect( self::SCOPES, array_map( 'strval', $scopes ) ) );
		if ( ! $scopes ) { return new \WP_Error( 'wpcap_partner_key_scopes', __( 'Mindestens ein unterstützter Scope ist erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$events = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['event_ids'] ?? array() ) ) ) ) );
		if ( array_diff( $events, Repository::event_ids( $partner->partner_id ) ) ) {
			return new \WP_Error( 'wpcap_partner_key_events', __( 'Ein Key kann nur auf Events dieses Partners beschränkt werden.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$allow = self::parse_allowlist( $args['ip_allowlist'] ?? '' );
		if ( is_wp_error( $allow ) ) { return $allow; }
		$expires = (string) ( $args['expires_at'] ?? '' );
		if ( '' !== $expires && ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires ) || $expires <= current_time( 'mysql', true ) ) ) {
			return new \WP_Error( 'wpcap_partner_key_expiry', __( 'Das Ablaufdatum muss in der Zukunft liegen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$prefix = bin2hex( random_bytes( 6 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$saved = $wpdb->insert( "{$wpdb->prefix}wpcap_partner_keys", array(
			'partner_id' => (int) $partner->partner_id,
			'label' => substr( sanitize_text_field( (string) ( $args['label'] ?? '' ) ), 0, 190 ),
			'public_prefix' => $prefix,
			'secret_hash' => hash( 'sha256', $secret ),
			'scopes' => implode( ',', $scopes ),
			'event_ids' => $events ? wp_json_encode( $events ) : null,
			'ip_allowlist' => $allow ? implode( "\n", $allow ) : null,
			'rate_limit' => min( 100000, absint( $args['rate_limit'] ?? 0 ) ),
			'expires_at' => '' !== $expires ? $expires : null,
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		) );
		if ( false === $saved ) { return new \WP_Error( 'wpcap_partner_key_save', __( 'API-Key konnte nicht angelegt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$key_id = (int) $wpdb->insert_id;
		Audit::record( 'partner_key_created', 'partner_key', $key_id, array( 'partner_id' => (int) $partner->partner_id, 'scopes' => $scopes, 'event_ids' => $events, 'ip_allowlist' => (bool) $allow, 'expires_at' => $expires ) );
		return array( 'key_id' => $key_id, 'key' => self::TOKEN_PREFIX . $prefix . '_' . $secret, 'prefix' => $prefix, 'scopes' => $scopes, 'shown_once' => true );
	}

	public static function get( $key_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_partner_keys WHERE key_id=%d", absint( $key_id ) ) );
	}

	/** Keys of a partner (newest first; secret hashes stripped). */
	public static function for_partner( $partner_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT key_id,partner_id,label,public_prefix,scopes,event_ids,ip_allowlist,rate_limit,expires_at,last_used_at,revoked_at,created_at FROM {$wpdb->prefix}wpcap_partner_keys WHERE partner_id=%d ORDER BY key_id DESC", absint( $partner_id ) ) );
	}

	public static function revoke( $key_id ) {
		global $wpdb;
		$key = self::get( $key_id );
		if ( ! $key ) { return new \WP_Error( 'wpcap_partner_key_missing', __( 'API-Key nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( $key->revoked_at ) { return array( 'key_id' => (int) $key->key_id, 'already_revoked' => true ); }
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_partner_keys SET revoked_at=%s, revoked_by=%d WHERE key_id=%d AND revoked_at IS NULL", current_time( 'mysql', true ), get_current_user_id(), (int) $key->key_id ) );
		Audit::record( 'partner_key_revoked', 'partner_key', (int) $key->key_id, array( 'partner_id' => (int) $key->partner_id ) );
		return array( 'key_id' => (int) $key->key_id );
	}

	/** Scopes of a key row. */
	public static function scopes( $key ) {
		return array_values( array_filter( explode( ',', (string) $key->scopes ) ) );
	}

	/**
	 * Effective event ids of a key: the key restriction intersected with the partner's current events
	 * (unlinking an event from the partner immediately removes access).
	 */
	public static function effective_events( $key ) {
		$partner_events = Repository::event_ids( $key->partner_id );
		$restricted = array_map( 'absint', (array) json_decode( (string) $key->event_ids, true ) );
		return array_values( $restricted ? array_intersect( $partner_events, $restricted ) : $partner_events );
	}

	/**
	 * Validates and normalises an allowlist (one address or CIDR range per line/comma).
	 *
	 * @return string[]|\WP_Error
	 */
	public static function parse_allowlist( $input ) {
		$entries = is_array( $input ) ? $input : preg_split( '/[\s,;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY );
		$out = array();
		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) { continue; }
			$parts = explode( '/', $entry, 2 );
			$bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input is reported below.
			$max = false === $bin ? 0 : strlen( $bin ) * 8;
			if ( false === $bin || ( isset( $parts[1] ) && ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max ) ) ) {
				/* translators: %s: entry */
				return new \WP_Error( 'wpcap_partner_key_ip', sprintf( __( 'Ungültiger IP-Eintrag: %s', 'wp-pass-captain' ), $entry ), array( 'status' => 400 ) );
			}
			$out[] = inet_ntop( $bin ) . ( isset( $parts[1] ) ? '/' . (int) $parts[1] : '' );
		}
		return array_values( array_unique( $out ) );
	}

	/** Whether $ip matches one of the allowlist entries. */
	public static function ip_allowed( $ip, array $allowlist ) {
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid client address = no match.
		if ( false === $ip_bin ) { return false; }
		foreach ( $allowlist as $entry ) {
			$parts = explode( '/', $entry, 2 );
			$net = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $net || strlen( $net ) !== strlen( $ip_bin ) ) { continue; }
			$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $net, 0, $bytes ) !== substr( $ip_bin, 0, $bytes ) ) { continue; }
			$rest = $bits % 8;
			if ( $rest ) {
				$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
				if ( ( ord( $net[ $bytes ] ) & $mask ) !== ( ord( $ip_bin[ $bytes ] ) & $mask ) ) { continue; }
			}
			return true;
		}
		return false;
	}

	/** Client address (REMOTE_ADDR; proxies must be resolved via the wpcap_partner_client_ip filter). */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'wpcap_partner_client_ip', $ip );
	}

	/** Address with the host part removed for the audit log (IPv4 /24, IPv6 /48). */
	public static function anonymise_ip( $ip ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) { return ''; }
		$keep = 4 === strlen( $bin ) ? 3 : 6;
		return inet_ntop( substr( $bin, 0, $keep ) . str_repeat( "\0", strlen( $bin ) - $keep ) );
	}

	private static function lock_key( $ip ) {
		return 'wpcap_partner_lock_' . md5( (string) $ip );
	}

	/**
	 * Authenticates a request (Authorization: Bearer wpcap_pk_…) and checks the scope.
	 *
	 * @param string|null $scope Required scope (null = any valid key).
	 * @return array|\WP_Error Context: key (row), partner (row), event_ids (int[]), scopes (string[]).
	 */
	public static function authenticate( $request, $scope = null ) {
		global $wpdb;
		$ip = self::client_ip();
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( get_transient( self::lock_key( $ip ) ) ) {
			return new \WP_Error( 'wpcap_partner_locked', __( 'Zu viele fehlgeschlagene Anmeldungen. Bitte später erneut versuchen.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$header = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+' . self::TOKEN_PREFIX . '([a-f0-9]{12})_([a-f0-9]{64})$/', trim( $header ), $m ) ) {
			return self::fail( '' === $header ? 'missing' : 'malformed', null, $ip, $route, 401, true );
		}
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_partner_keys WHERE public_prefix=%s", $m[1] ) );
		if ( ! $key || ! hash_equals( (string) $key->secret_hash, hash( 'sha256', $m[2] ) ) ) {
			return self::fail( 'invalid', $key, $ip, $route, 401, true );
		}
		if ( $key->revoked_at ) { return self::fail( 'revoked', $key, $ip, $route, 401, true ); }
		if ( $key->expires_at && $key->expires_at <= current_time( 'mysql', true ) ) { return self::fail( 'expired', $key, $ip, $route, 401, true ); }
		$partner = Repository::get( $key->partner_id );
		if ( ! $partner || 'active' !== $partner->status ) { return self::fail( 'partner_inactive', $key, $ip, $route, 403, false ); }
		$allow = array_filter( explode( "\n", (string) $key->ip_allowlist ) );
		if ( $allow && ! self::ip_allowed( $ip, $allow ) ) { return self::fail( 'ip_not_allowed', $key, $ip, $route, 403, false ); }
		$limit = (int) $key->rate_limit ?: max( 1, (int) ( Settings::get( 'partners' )['rate_limit'] ?? 120 ) );
		if ( ! RateLimit::allow( 'partner_key_' . (int) $key->key_id, $limit, 60 ) ) {
			// Audit the first rejection per minute only, so a misbehaving client cannot flood the log.
			$flag = 'wpcap_partner_rl_logged_' . (int) $key->key_id;
			if ( ! get_transient( $flag ) ) {
				set_transient( $flag, 1, MINUTE_IN_SECONDS );
				self::audit_failure( 'rate_limited', $key, $ip, $route );
			}
			return new \WP_Error( 'wpcap_partner_rate_limited', __( 'Ratenlimit überschritten. Bitte später erneut versuchen.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$scopes = self::scopes( $key );
		if ( null !== $scope && ! in_array( $scope, $scopes, true ) ) {
			return self::fail( 'scope_missing', $key, $ip, $route, 403, false, array( 'scope' => $scope ) );
		}
		$now = current_time( 'mysql', true );
		if ( ! $key->last_used_at || strtotime( $key->last_used_at . ' UTC' ) < time() - 60 ) {
			$wpdb->update( "{$wpdb->prefix}wpcap_partner_keys", array( 'last_used_at' => $now ), array( 'key_id' => (int) $key->key_id ) );
		}
		return array( 'key' => $key, 'partner' => $partner, 'event_ids' => self::effective_events( $key ), 'scopes' => $scopes );
	}

	/** Records a failed authentication/authorisation and returns the error. */
	private static function fail( $reason, $key, $ip, $route, $status, $count_for_lockout, array $extra = array() ) {
		if ( $count_for_lockout && ! RateLimit::allow( 'partner_auth_fail_' . md5( (string) $ip ), self::FAIL_LIMIT, self::FAIL_WINDOW ) ) {
			set_transient( self::lock_key( $ip ), 1, self::FAIL_WINDOW );
		}
		self::audit_failure( $reason, $key, $ip, $route, $extra );
		$messages = array(
			401 => __( 'Ungültiger, abgelaufener oder widerrufener API-Key.', 'wp-pass-captain' ),
			403 => __( 'Dieser API-Key ist für diese Anfrage nicht berechtigt.', 'wp-pass-captain' ),
		);
		return new \WP_Error( 401 === $status ? 'wpcap_partner_unauthorized' : 'wpcap_partner_forbidden', $messages[ $status ], array( 'status' => $status, 'reason' => $reason ) );
	}

	public static function audit_failure( $reason, $key, $ip, $route, array $extra = array() ) {
		Audit::record( 'partner_key_auth_failed', 'partner_key', $key ? (int) $key->key_id : 0, array_merge( array( 'reason' => $reason, 'partner_id' => $key ? (int) $key->partner_id : 0, 'route' => substr( (string) $route, 0, 120 ), 'ip' => self::anonymise_ip( $ip ) ), $extra ) );
	}
}
