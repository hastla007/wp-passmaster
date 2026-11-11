<?php
/** Fixed-window rate limiter on transients (object-cache aware). */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RateLimit {
	/** Records a hit; returns false once $limit hits happened in the current window. */
	public static function allow( $bucket, $limit, $window = 60 ) {
		$slot = (int) floor( time() / max( 1, (int) $window ) );
		$key = 'wpcap_rl_' . md5( $bucket . '|' . $slot );
		$count = (int) get_transient( $key );
		if ( $count >= (int) $limit ) { return false; }
		set_transient( $key, $count + 1, (int) $window );
		return true;
	}

	/** Anonymised client key (hash of the remote address). */
	public static function client() {
		return hash_hmac( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 'wpcap-rate' );
	}
}
