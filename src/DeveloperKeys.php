<?php
/** Scoped, hashed API keys for read-only developer endpoints. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DeveloperKeys {
	public static function issue( $event_id, array $scopes, $label = '', $expires_at = '' ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$allowed = array( 'events.read', 'reports.read' );
		$scopes = array_values( array_unique( array_intersect( array_map( static function ( $scope ) { return trim( sanitize_text_field( $scope ) ); }, $scopes ), $allowed ) ) );
		if ( ! $event_id || ! $scopes ) { return new \WP_Error( 'wpcap_api_key_invalid', __( 'Event und mindestens ein unterstützter Scope sind erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$secret = bin2hex( random_bytes( 32 ) );
		$prefix = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_api_keys', array( 'user_id' => get_current_user_id(), 'public_prefix' => $prefix, 'secret_hash' => hash( 'sha256', $secret ), 'event_id' => $event_id, 'scopes' => wp_json_encode( $scopes ), 'label' => sanitize_text_field( $label ), 'expires_at' => $expires_at ? sanitize_text_field( $expires_at ) : null, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		if ( false === $inserted ) { return new \WP_Error( 'wpcap_api_key_save', __( 'API-Key konnte nicht angelegt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'developer_key_issued', 'api_key', (int) $wpdb->insert_id, array( 'event_id' => $event_id, 'scopes' => $scopes ) );
		return array( 'api_key_id' => (int) $wpdb->insert_id, 'key' => $prefix . '.' . $secret, 'scopes' => $scopes, 'shown_once' => true );
	}

	public static function authorize( \WP_REST_Request $request, $scope, $event_id ) {
		global $wpdb;
		$provided = (string) $request->get_header( 'x-wpcap-key' );
		$parts = explode( '.', $provided, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[a-f0-9]{12}$/', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) { return false; }
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_api_keys WHERE public_prefix=%s AND revoked_at IS NULL", $parts[0] ) );
		if ( ! $key || ! hash_equals( $key->secret_hash, hash( 'sha256', $parts[1] ) ) || ( $key->expires_at && $key->expires_at <= current_time( 'mysql', true ) ) || (int) $key->event_id !== absint( $event_id ) ) { return false; }
		$user_id = (int) $key->user_id;
		if ( ! user_can( $user_id, 'manage_options' ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id=%d AND user_id=%d AND capability IN ('wpcap_manage_events','wpcap_view_reports') LIMIT 1", $event_id, $user_id ) ) ) { return false; }
		$scopes = json_decode( $key->scopes, true );
		if ( ! is_array( $scopes ) || ! in_array( $scope, $scopes, true ) ) { return false; }
		$rate_key = 'wpcap_api_rate_' . (int) $key->api_key_id . '_' . gmdate( 'YmdHi' );
		$rate = (int) get_transient( $rate_key );
		if ( $rate >= 600 ) { return false; }
		set_transient( $rate_key, $rate + 1, MINUTE_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'api_key_id' => (int) $key->api_key_id ), array( '%s' ), array( '%d' ) );
		return (int) $key->user_id;
	}

	public static function revoke( $key_id ) {
		global $wpdb;
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT api_key_id,user_id FROM {$wpdb->prefix}wpcap_api_keys WHERE api_key_id=%d", absint( $key_id ) ) );
		if ( ! $key || ( (int) $key->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'API-Key nicht gefunden oder keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->update( $wpdb->prefix . 'wpcap_api_keys', array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'api_key_id' => absint( $key_id ), 'revoked_at' => null ), array( '%s' ), array( '%d', '%s' ) );
		Audit::record( 'developer_key_revoked', 'api_key', absint( $key_id ) );
		return true;
	}
}
