<?php
/**
 * Capability matching shared by profiles and adapters.
 *
 * Country policy is recorded in reviewed profile data. This class enforces the mandatory
 * core capabilities and normalizes the additional requirements; it does not decide legal scope.
 */
namespace PassCaptain\\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CountryPolicy {
	public static function required_capabilities( array $profile ) {
		$extra = (array) ( $profile['required_capabilities'] ?? array() );
		$extra = array_filter( array_map( 'sanitize_key', $extra ) );
		return array_values( array_unique( array_merge( array( 'receipt_submit', 'operation_status' ), $extra ) ) );
	}

	public static function missing_capabilities( $adapter, array $profile ) {
		if ( ! $adapter instanceof AdapterInterface ) { return self::required_capabilities( $profile ); }
		$available = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $adapter->capabilities() ) ) ) );
		return array_values( array_diff( self::required_capabilities( $profile ), $available ) );
	}
}
