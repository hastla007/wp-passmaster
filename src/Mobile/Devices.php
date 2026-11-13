<?php
/**
 * Scanner devices for the mobile app API.
 *
 * A staff member creates a one-time pairing code (QR) in the admin. The app exchanges it for a
 * device token that is bound to that person, limited to selected events, expires after a configurable
 * number of hours and can be revoked at any time. Only a SHA-256 hash of the secret is stored.
 */
namespace PassCaptain\Mobile;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Devices {
	const TOKEN_PREFIX = 'wpcapd';
	const PAIRING_MINUTES = 10;

	/** Device authenticated for the current request. */
	private static $current = null;

	/**
	 * Creates a one-time pairing code for the current user.
	 *
	 * @return array|\WP_Error ['code', 'expires_at', 'qr_payload']
	 */
	public static function create_pairing( array $event_ids, $label = '', $hours = 0, $area_id = 0 ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_pairing_forbidden', __( 'Für die Gerätekopplung ist eine Einlassberechtigung erforderlich.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return new \WP_Error( 'wpcap_pairing_events', __( 'Bitte mindestens ein Event für das Gerät auswählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		foreach ( $event_ids as $event_id ) {
			if ( ! Access::can_access_event( $event_id, 'wpcap_checkin' ) ) {
				return new \WP_Error( 'wpcap_pairing_event_forbidden', __( 'Für mindestens ein gewähltes Event fehlt die Einlassberechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
			}
		}
		$area_id = absint( $area_id );
		$area_error = \PassCaptain\Admission\Areas::validate_for_pairing( $area_id, $event_ids );
		if ( $area_error ) { return $area_error; }
		$max = max( 1, (int) Settings::get( 'device_max_hours' ) );
		$hours = min( $max, max( 1, absint( $hours ) ?: $max ) );
		$code = self::random_code();
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::PAIRING_MINUTES * MINUTE_IN_SECONDS );
		$wpdb->insert( $wpdb->prefix . 'wpcap_device_pairings', array(
			'code_hash' => hash( 'sha256', $code ),
			'user_id' => $user_id,
			'event_ids' => wp_json_encode( $event_ids ),
			'label' => mb_substr( sanitize_text_field( $label ), 0, 190 ),
			'device_hours' => $hours,
			'expires_at' => $expires,
			'created_at' => current_time( 'mysql', true ),
		) );
		\PassCaptain\Admission\Areas::set_pairing_area( (int) $wpdb->insert_id, $area_id );
		Audit::record( 'device_pairing_created', 'user', $user_id, array( 'event_ids' => $event_ids, 'hours' => $hours, 'area_id' => $area_id ) );
		$payload = wp_json_encode( array( 'type' => 'wpcap-pair', 'v' => 1, 'api' => esc_url_raw( rest_url( 'wp-pass-captain/v1/app/' ) ), 'code' => $code ) );
		return array( 'code' => $code, 'expires_at' => $expires, 'device_hours' => $hours, 'area_id' => $area_id, 'qr_payload' => $payload );
	}

	/**
	 * Exchanges a pairing code for a device token (shown once).
	 *
	 * @return array|\WP_Error
	 */
	public static function pair( $code, $label = '', $platform = '', $app_version = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( strlen( $code ) < 16 ) {
			return new \WP_Error( 'wpcap_pairing_invalid', __( 'Kopplungscode ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$pairing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_device_pairings WHERE code_hash=%s FOR UPDATE", hash( 'sha256', $code ) ) );
		if ( ! $pairing || $pairing->used_at || $pairing->expires_at <= $now || ! user_can( (int) $pairing->user_id, 'wpcap_checkin' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_pairing_invalid', __( 'Kopplungscode ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$public_id = bin2hex( random_bytes( 16 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + (int) $pairing->device_hours * HOUR_IN_SECONDS );
		$wpdb->insert( "{$p}wpcap_scanner_devices", array(
			'public_id' => $public_id,
			'user_id' => (int) $pairing->user_id,
			'secret_hash' => hash( 'sha256', $secret ),
			'label' => mb_substr( sanitize_text_field( $label ?: $pairing->label ), 0, 190 ),
			'platform' => substr( sanitize_key( $platform ), 0, 32 ),
			'app_version' => substr( sanitize_text_field( $app_version ), 0, 32 ),
			'event_ids' => $pairing->event_ids,
			'expires_at' => $expires,
			'created_at' => $now,
		) );
		$device_id = (int) $wpdb->insert_id;
		$wpdb->update( "{$p}wpcap_device_pairings", array( 'used_at' => $now, 'device_id' => $device_id ), array( 'pairing_id' => (int) $pairing->pairing_id ) );
		$area_id = \PassCaptain\Admission\Areas::pairing_area( (int) $pairing->pairing_id );
		if ( $area_id ) { \PassCaptain\Admission\Areas::set_device_area( $device_id, $area_id ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'device_paired', 'device', $device_id, array( 'user_id' => (int) $pairing->user_id, 'platform' => sanitize_key( $platform ) ) );
		$user = get_userdata( (int) $pairing->user_id );
		return array(
			'token' => self::TOKEN_PREFIX . '_' . $public_id . '_' . $secret,
			'token_type' => 'Bearer',
			'device_id' => $public_id,
			'expires_at' => gmdate( 'c', strtotime( $expires . ' UTC' ) ),
			'user' => array( 'display_name' => $user ? $user->display_name : '' ),
			'event_ids' => array_map( 'intval', (array) json_decode( (string) $pairing->event_ids, true ) ),
			'area' => self::area_payload( $area_id ),
		);
	}

	/**
	 * Authenticates a request by its device token and switches the current user for the request.
	 *
	 * @return object|\WP_Error Device row.
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		global $wpdb;
		$header = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+' . self::TOKEN_PREFIX . '_([a-f0-9]{32})_([a-f0-9]{64})$/', trim( $header ), $m ) ) {
			return new \WP_Error( 'wpcap_device_unauthenticated', __( 'Gerätetoken fehlt oder ist ungültig.', 'pass-captain' ), array( 'status' => 401 ) );
		}
		$device = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_scanner_devices WHERE public_id=%s", $m[1] ) );
		$now = current_time( 'mysql', true );
		if ( ! $device || ! hash_equals( (string) $device->secret_hash, hash( 'sha256', $m[2] ) ) || $device->revoked_at || ( $device->expires_at && $device->expires_at <= $now ) ) {
			return new \WP_Error( 'wpcap_device_unauthenticated', __( 'Gerätetoken ist ungültig, abgelaufen oder widerrufen.', 'pass-captain' ), array( 'status' => 401 ) );
		}
		if ( ! user_can( (int) $device->user_id, 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_device_user', __( 'Die Person hinter diesem Gerät hat keine Einlassberechtigung mehr.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( ! \PassCaptain\RateLimit::allow( 'device_' . $device->device_id, 600, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_device_rate', __( 'Zu viele Anfragen von diesem Gerät.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		wp_set_current_user( (int) $device->user_id );
		if ( ! $device->last_seen_at || strtotime( $device->last_seen_at . ' UTC' ) < time() - 60 ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_scanner_devices', array( 'last_seen_at' => $now ), array( 'device_id' => (int) $device->device_id ) );
		}
		self::$current = $device;
		return $device;
	}

	/** Device of the current request (after authenticate()). */
	public static function current() { return self::$current; }

	/** Allowed event IDs of a device. */
	public static function event_ids( $device ) {
		return array_map( 'intval', (array) json_decode( (string) $device->event_ids, true ) );
	}

	/** Entrance area a device is bound to (0 = none). */
	public static function area_id( $device ) {
		return $device ? \PassCaptain\Admission\Areas::device_area( (int) $device->device_id ) : 0;
	}

	/** Area as returned by the API ({id, name, event_id} or null). */
	public static function area_payload( $area_id ) {
		$area = $area_id ? \PassCaptain\Admission\Areas::get( $area_id ) : null;
		return $area ? array( 'id' => (int) $area->area_id, 'name' => $area->name, 'event_id' => (int) $area->event_id ) : null;
	}

	/** Revokes a device; owners can revoke their own devices, admins all. */
	public static function revoke( $device_id ) {
		global $wpdb;
		$device = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_scanner_devices WHERE device_id=%d", absint( $device_id ) ) );
		if ( ! $device ) { return new \WP_Error( 'wpcap_device_missing', __( 'Gerät nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( (int) $device->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wpcap_device_forbidden', __( 'Keine Berechtigung für dieses Gerät.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( ! $device->revoked_at ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_scanner_devices', array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'device_id' => (int) $device->device_id ) );
			Audit::record( 'device_revoked', 'device', (int) $device->device_id );
		}
		return true;
	}

	/** Devices visible to the current user. */
	public static function list_for_current_user() {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = current_user_can( 'manage_options' ) ? '1=1' : $wpdb->prepare( 'd.user_id=%d', get_current_user_id() );
		return $wpdb->get_results( "SELECT d.*,u.display_name FROM {$p}wpcap_scanner_devices d LEFT JOIN {$wpdb->users} u ON u.ID=d.user_id WHERE $where ORDER BY d.device_id DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
	}

	/** 20 characters from an unambiguous alphabet (≈100 bits), grouped for manual entry. */
	private static function random_code() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code = '';
		for ( $i = 0; $i < 20; $i++ ) { $code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ]; }
		return $code;
	}
}
