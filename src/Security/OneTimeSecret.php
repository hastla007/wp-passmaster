<?php
/**
 * A507: one-time secrets between a POST and the page after the redirect (new API key, partner key, pairing code).
 * Kept per user, encrypted with the Vault (no plaintext key in wp_options or a persistent object cache), short
 * lifetime, and deleted when read – it is shown exactly once.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OneTimeSecret {
	/**
	 * Stores a value for the current user.
	 *
	 * @param string $slot Name, e.g. "devkey".
	 * @param mixed  $value JSON-encodable value.
	 * @param int    $ttl   Seconds.
	 */
	public static function put( $slot, $value, $ttl = 600 ) {
		$json = (string) wp_json_encode( $value );
		return set_transient( self::key( $slot ), Vault::encrypt( $json, self::context( $slot ) ), max( 60, (int) $ttl ) );
	}

	/**
	 * Reads and deletes the value of the current user (null when there is none or it cannot be decrypted).
	 *
	 * @return mixed|null
	 */
	public static function take( $slot ) {
		$stored = get_transient( self::key( $slot ) );
		if ( false === $stored ) { return null; }
		delete_transient( self::key( $slot ) );
		$json = Vault::decrypt( (string) $stored, self::context( $slot ) );
		if ( null === $json || '' === $json ) { return null; }
		return json_decode( $json, true );
	}

	private static function key( $slot ) {
		return 'wpcap_once_' . sanitize_key( (string) $slot ) . '_' . get_current_user_id();
	}

	private static function context( $slot ) {
		return 'one_time:' . sanitize_key( (string) $slot ) . ':' . get_current_user_id();
	}
}
