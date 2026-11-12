<?php
/**
 * Resolves fiscal adapter credentials from the encrypted site vault and trusted integrations.
 *
 * Stored values are encrypted with AES-256-GCM using WordPress authentication salts. The core
 * returns credentials only to an adapter call and never renders or logs their values.
 */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Credentials {
	const OPTION = 'wpcap_fiscal_credential_vault';

	/** Add seller/provider credentials to transient adapter configuration. */
	public static function with_credentials( array $configuration, $seller_id, $provider_id ) {
		$provided = apply_filters(
			'wpcap_fiscal_credentials',
			array(),
			absint( $seller_id ),
			sanitize_key( $provider_id )
		);
		$credentials = array_merge( self::load( $seller_id, $provider_id ), is_array( $provided ) ? $provided : array() );
		$clean = array();
		foreach ( $credentials as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_scalar( $value ) ) { continue; }
			$value = (string) $value;
			if ( strlen( $value ) > 8192 ) { continue; }
			$clean[ $key ] = $value;
		}
		$configuration['credentials'] = $clean;
		return $configuration;
	}

	/** Save or update credentials, merging existing entries and removing selected names. */
	public static function save( $seller_id, $provider_id, array $values, array $remove = array() ) {
		$seller_id = absint( $seller_id );
		$provider_id = sanitize_key( $provider_id );
		if ( ! $seller_id || '' === $provider_id ) { return new \WP_Error( 'wpcap_fiscal_credentials_scope', __( 'Verkäufer und Anbieter sind erforderlich.', 'wp-pass-captain' ) ); }
		$credentials = self::load( $seller_id, $provider_id );
		foreach ( $values as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $key ) || ! is_scalar( $value ) ) { return new \WP_Error( 'wpcap_fiscal_credentials_invalid', __( 'Zugangsdaten enthalten ein ungültiges Feld.', 'wp-pass-captain' ) ); }
			$value = (string) $value;
			if ( '' === $value || strlen( $value ) > 8192 ) { return new \WP_Error( 'wpcap_fiscal_credentials_invalid', __( 'Zugangsdaten dürfen nicht leer sein und maximal 8192 Zeichen umfassen.', 'wp-pass-captain' ) ); }
			$credentials[ $key ] = $value;
		}
		foreach ( $remove as $key ) { unset( $credentials[ sanitize_key( $key ) ] ); }
		if ( ! $credentials ) { return self::delete( $seller_id, $provider_id ); }
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) ) {
			return new \WP_Error( 'wpcap_fiscal_credentials_crypto', __( 'Verschlüsselung oder WordPress-Schlüsselmaterial ist nicht verfügbar.', 'wp-pass-captain' ) );
		}
		$key = self::encryption_key();
		try { $nonce = random_bytes( 12 ); } catch ( \Throwable $e ) { return new \WP_Error( 'wpcap_fiscal_credentials_crypto', __( 'Sichere Zufallsdaten sind nicht verfügbar.', 'wp-pass-captain' ) ); }
		$tag = '';
		$plain = wp_json_encode( $credentials );
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $cipher || '' === $tag ) { return new \WP_Error( 'wpcap_fiscal_credentials_crypto', __( 'Zugangsdaten konnten nicht sicher verschlüsselt werden.', 'wp-pass-captain' ) ); }
		$vault = get_option( self::OPTION, array() );
		$vault = is_array( $vault ) ? $vault : array();
		$vault[ $seller_id ][ $provider_id ] = array(
			'keys' => array_values( array_keys( $credentials ) ),
			'nonce' => base64_encode( $nonce ),
			'tag' => base64_encode( $tag ),
			'ciphertext' => base64_encode( $cipher ),
		);
		update_option( self::OPTION, $vault, false );
		return true;
	}

	/** Decrypt for one adapter call; corrupt or salt-mismatched entries fail closed. */
	private static function load( $seller_id, $provider_id ) {
		if ( ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) ) { return array(); }
		$vault = get_option( self::OPTION, array() );
		$entry = is_array( $vault ) ? ( $vault[ absint( $seller_id ) ][ sanitize_key( $provider_id ) ] ?? null ) : null;
		if ( ! is_array( $entry ) ) { return array(); }
		$nonce = base64_decode( (string) ( $entry['nonce'] ?? '' ), true );
		$tag = base64_decode( (string) ( $entry['tag'] ?? '' ), true );
		$cipher = base64_decode( (string) ( $entry['ciphertext'] ?? '' ), true );
		if ( false === $nonce || 12 !== strlen( $nonce ) || false === $tag || 16 !== strlen( $tag ) || false === $cipher ) { return array(); }
		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag );
		$decoded = false === $plain ? null : json_decode( $plain, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}

	/** Credential names only; values are never exposed to the admin page. */
	public static function stored_names( $seller_id, $provider_id ) {
		$vault = get_option( self::OPTION, array() );
		$entry = is_array( $vault ) ? ( $vault[ absint( $seller_id ) ][ sanitize_key( $provider_id ) ] ?? null ) : null;
		return is_array( $entry ) && is_array( $entry['keys'] ?? null ) ? array_values( array_map( 'sanitize_key', $entry['keys'] ) ) : array();
	}

	public static function delete( $seller_id, $provider_id ) {
		$vault = get_option( self::OPTION, array() );
		$vault = is_array( $vault ) ? $vault : array();
		unset( $vault[ absint( $seller_id ) ][ sanitize_key( $provider_id ) ] );
		if ( empty( $vault[ absint( $seller_id ) ] ) ) { unset( $vault[ absint( $seller_id ) ] ); }
		update_option( self::OPTION, $vault, false );
		return true;
	}
}
