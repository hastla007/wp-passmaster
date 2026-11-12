<?php
/** Fixed-window rate limiter on transients (object-cache aware). */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RateLimit {
	/** Records a hit; returns false once $limit hits happened in the current window. */
	public static function allow( $bucket, $limit, $window = 60 ) {
		$slot = (int) floor( time() / max( 1, (int) $window ) );
		// Keyed hash: bucket names may contain client addresses or IDs; a plain md5 of an IPv4
		// address can be reversed by brute force from the options table / object cache.
		$key = 'wpcap_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $slot, self::secret() ), 0, 40 );
		$count = (int) get_transient( $key );
		if ( $count >= (int) $limit ) { return false; }
		set_transient( $key, $count + 1, (int) $window );
		return true;
	}

	/** Anonymised client key (keyed hash of the remote address, site-specific secret). */
	public static function client() {
		return hash_hmac( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), self::secret() );
	}

	/** Site secret from wp_salt(); a static key would make the hashes identical across all sites. */
	private static function secret() {
		return function_exists( 'wp_salt' ) ? 'wpcap-rate|' . wp_salt( 'nonce' ) : 'wpcap-rate|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : ABSPATH );
	}
}
