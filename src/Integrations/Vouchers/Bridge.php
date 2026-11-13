<?php
/** Safe, versioned integration boundary for WP Voucher Captain. */
namespace PassCaptain\Integrations\Vouchers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Bridge {
	const VERSION = 1;
	const FILTER = 'wp_pass_captain_voucher_provider_v1';

	/** Return the supported provider or null; unknown versions never get called. */
	public static function provider() {
		$provider = apply_filters( self::FILTER, null, self::VERSION );
		return $provider instanceof ProviderInterface ? $provider : null;
	}

	public static function available() { return null !== self::provider(); }

	public static function quote( array $context ) {
		$provider = self::provider();
		return $provider ? $provider->quote( $context ) : new \WP_Error( 'wpcap_voucher_unavailable', __( 'Es ist kein kompatibler WP Voucher Captain Anbieter aktiv.', 'pass-captain' ) );
	}

	public static function reserve( array $quote, $key ) { return self::call( 'reserve', array( $quote, sanitize_text_field( $key ) ) ); }
	public static function commit( array $reservation, $key ) { return self::call( 'commit', array( $reservation, sanitize_text_field( $key ) ) ); }
	public static function release( array $reservation, $key ) { return self::call( 'release', array( $reservation, sanitize_text_field( $key ) ) ); }
	public static function refund( array $redemption, $amount_minor, $key ) { return self::call( 'refund', array( $redemption, absint( $amount_minor ), sanitize_text_field( $key ) ) ); }

	private static function call( $method, array $args ) {
		$provider = self::provider();
		if ( ! $provider ) { return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Es ist kein kompatibler WP Voucher Captain Anbieter aktiv.', 'pass-captain' ) ); }
		return call_user_func_array( array( $provider, $method ), $args );
	}
}
