<?php
/**
 * Resolves adapter credentials from an external secret store at call time.
 *
 * The fiscal core never persists, returns, or logs these values. Site integrations should
 * expose only the credential set for the requested seller and provider.
 */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Credentials {
	/**
	 * Add trusted external credentials to the transient adapter configuration.
	 *
	 * Integrations may read from environment variables, a hosting secret manager, or their own
	 * encrypted store via the filter. Do not return credentials for another seller/provider.
	 */
	public static function with_credentials( array $configuration, $seller_id, $provider_id ) {
		$provided = apply_filters(
			'wpcap_fiscal_credentials',
			array(),
			absint( $seller_id ),
			sanitize_key( $provider_id )
		);
		$credentials = array();
		if ( is_array( $provided ) ) {
			foreach ( $provided as $key => $value ) {
				$key = sanitize_key( $key );
				if ( '' === $key || ! is_scalar( $value ) ) { continue; }
				$value = (string) $value;
				if ( strlen( $value ) > 8192 ) { continue; }
				$credentials[ $key ] = $value;
			}
		}
		$configuration['credentials'] = $credentials;
		return $configuration;
	}
}
