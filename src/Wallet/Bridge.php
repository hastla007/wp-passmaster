<?php
/** Versioned Apple/Google wallet integration point. */
namespace PassCaptain\Wallet;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Bridge {
	const VERSION = 1;

	public static function provider( $provider ) {
		$provider = apply_filters( 'wp_pass_captain_wallet_provider_v1', null, sanitize_key( $provider ), self::VERSION );
		return $provider instanceof ProviderInterface ? $provider : null;
	}

	public static function available( $provider ) { return null !== self::provider( $provider ); }
}
