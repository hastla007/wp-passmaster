<?php
/**
 * Safe, versioned integration boundary for Voucher Captain (contract docs/CONTRACTS.md).
 *
 * Every provider call goes through this class: an incompatible provider (other major contract version) is
 * never called, exceptions and timeouts become a WP_Error with HTTP status 503/504 (temporary, retried by
 * the callers) and malformed answers a WP_Error 502. Callers therefore only ever see an array, true or a
 * WP_Error and can keep their own state consistent.
 */
namespace PassCaptain\Integrations\Vouchers;

use PassCaptain\Contracts;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Bridge {
	const VERSION = 1;
	/** Contract version (major.minor) Pass Captain implements; providers of another major are refused. */
	const CONTRACT = '1.0';
	const FILTER = 'wp_pass_captain_voucher_provider_v1';

	/** Return the supported provider or null; unknown versions never get called. */
	public static function provider() {
		$provider = apply_filters( self::FILTER, null, self::VERSION );
		if ( ! $provider instanceof ProviderInterface ) { return null; }
		return Contracts::compatible( self::provider_contract( $provider ), self::CONTRACT ) ? $provider : null;
	}

	/**
	 * Contract version a provider declares through the optional method contract_version() ("1.2");
	 * providers without it implement the interface of filter version 1, i.e. "1.0".
	 */
	public static function provider_contract( $provider ) {
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'contract_version' ) ) { return self::CONTRACT; }
		try {
			return (string) $provider->contract_version();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Contract version of a registered but incompatible provider ('' when none or compatible). */
	public static function incompatible_contract() {
		$provider = apply_filters( self::FILTER, null, self::VERSION );
		if ( ! $provider instanceof ProviderInterface ) { return ''; }
		$version = self::provider_contract( $provider );
		return Contracts::compatible( $version, self::CONTRACT ) ? '' : ( '' !== $version ? $version : '?' );
	}

	public static function available() { return null !== self::provider(); }

	public static function quote( array $context ) { return self::call( 'quote', array( $context ) ); }
	public static function reserve( array $quote, $key ) { return self::call( 'reserve', array( $quote, sanitize_text_field( $key ) ) ); }
	public static function commit( array $reservation, $key ) { return self::call( 'commit', array( $reservation, sanitize_text_field( $key ) ) ); }
	public static function release( array $reservation, $key ) { return self::call( 'release', array( $reservation, sanitize_text_field( $key ) ) ); }
	public static function refund( array $redemption, $amount_minor, $key ) { return self::call( 'refund', array( $redemption, absint( $amount_minor ), sanitize_text_field( $key ) ) ); }

	/** Whether an error is a timeout or outage of the provider (callers may retry with the same key). */
	public static function is_transport_error( $error ) {
		return is_wp_error( $error ) && in_array( $error->get_error_code(), array( 'wpcap_voucher_timeout', 'wpcap_voucher_provider_error' ), true );
	}

	private static function call( $method, array $args ) {
		$provider = self::provider();
		if ( ! $provider ) { return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Es ist kein kompatibler Voucher Captain Anbieter aktiv.', 'pass-captain' ) ); }
		try {
			$result = call_user_func_array( array( $provider, $method ), $args );
		} catch ( \Throwable $e ) {
			// Exceptions never leave the bridge: callers decide on retry/rollback with a plain WP_Error.
			error_log( 'Pass Captain: voucher provider ' . $method . ' failed: ' . get_class( $e ) . ' ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new \WP_Error( 'wpcap_voucher_provider_error', __( 'Der Gutscheindienst ist gerade nicht erreichbar. Bitte versuche es gleich noch einmal.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'method' => $method ) );
		}
		return self::normalize( $method, $result );
	}

	/** Maps transport errors to 504 and refuses answers outside the contract (502). */
	private static function normalize( $method, $result ) {
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			if ( 'http_request_failed' === $code || false !== stripos( $code, 'timeout' ) || false !== stripos( (string) $result->get_error_message(), 'timed out' ) ) {
				return new \WP_Error( 'wpcap_voucher_timeout', __( 'Der Gutscheindienst antwortet gerade nicht. Bitte versuche es gleich noch einmal.', 'pass-captain' ), array( 'status' => 504, 'retryable' => true, 'method' => $method, 'provider_code' => $code ) );
			}
			return $result;
		}
		// quote/reserve must describe what was applied; commit/release/refund may answer with true or data.
		$valid = in_array( $method, array( 'quote', 'reserve' ), true ) ? is_array( $result ) : ( is_array( $result ) || true === $result );
		if ( ! $valid ) {
			return new \WP_Error( 'wpcap_voucher_provider_response', __( 'Der Gutscheindienst hat eine ungültige Antwort geliefert.', 'pass-captain' ), array( 'status' => 502, 'method' => $method ) );
		}
		return $result;
	}
}
