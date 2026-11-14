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

	public static function quote( array $context ) { return self::call( 'quote', array( $context ) ); }

	public static function reserve( array $quote, $key ) { return self::call( 'reserve', array( $quote, sanitize_text_field( $key ) ) ); }
	public static function commit( array $reservation, $key ) { return self::call( 'commit', array( $reservation, sanitize_text_field( $key ) ) ); }
	public static function release( array $reservation, $key ) { return self::call( 'release', array( $reservation, sanitize_text_field( $key ) ) ); }
	public static function refund( array $redemption, $amount_minor, $key ) { return self::call( 'refund', array( $redemption, absint( $amount_minor ), sanitize_text_field( $key ) ) ); }

	/**
	 * Calls the provider defensively: an exception (e.g. a network timeout inside the provider) becomes a
	 * retryable WP_Error, and an answer outside the contract (quote/reserve/commit must return an array,
	 * release/refund an array or true) is never treated as success.
	 */
	private static function call( $method, array $args ) {
		$provider = self::provider();
		if ( ! $provider ) { return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Es ist kein kompatibler WP Voucher Captain Anbieter aktiv.', 'pass-captain' ) ); }
		try {
			$result = call_user_func_array( array( $provider, $method ), $args );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wpcap_voucher_provider_error', __( 'Der Gutscheinanbieter ist gerade nicht erreichbar. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'method' => $method ) );
		}
		if ( is_wp_error( $result ) || is_array( $result ) || ( true === $result && in_array( $method, array( 'release', 'refund' ), true ) ) ) {
			return $result;
		}
		return new \WP_Error( 'wpcap_voucher_contract', __( 'Der Gutscheinanbieter hat eine ungültige Antwort geliefert.', 'pass-captain' ), array( 'status' => 502, 'method' => $method ) );
	}
}
