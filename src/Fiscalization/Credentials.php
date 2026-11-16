<?php
/**
 * Fiscal provider credentials per legal seller and provider.
 *
 * Storage: option `wpcap_fiscal_credential_vault` = [seller_id][provider_id] => ['keys' => names,
 * 'value' => Security\Vault ciphertext of the JSON object]. There is no second crypto scheme: values
 * use the plugin's secrets vault (libsodium secretbox, master key PASS_CAPTAIN_SECRET_KEY or the
 * wp-config salts, key rotation via PASS_CAPTAIN_SECRET_KEY_PREVIOUS). Every entry is encrypted for its
 * own context `fiscal_credentials:{seller_id}:{provider_id}`, so a ciphertext copied to another seller or
 * provider does not decrypt. Entries readable only with the previous key are re-encrypted on use and by
 * Vault::migrate(); entries of the earlier draft format (AES-GCM from the auth salts) are migrated once.
 *
 * Values are only returned to an adapter call (with_credentials()) and are registered with
 * Security\Redact for the rest of the request. They are never rendered, returned through REST or
 * written to the audit log (only the field names are).
 */
namespace PassCaptain\Fiscalization;

use PassCaptain\Log;
use PassCaptain\Security\Redact;
use PassCaptain\Security\Vault;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Credentials {
	const OPTION = 'wpcap_fiscal_credential_vault';

	/** Vault context of one seller/provider entry. */
	public static function context( $seller_id, $provider_id ) {
		return 'fiscal_credentials:' . absint( $seller_id ) . ':' . sanitize_key( $provider_id );
	}

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
			Redact::remember( $value );
		}
		$configuration['credentials'] = $clean;
		return $configuration;
	}

	/** Whether credentials can be stored encrypted (libsodium and a master key are available). */
	public static function storage_available() {
		return Vault::available() && '' !== Vault::key_source();
	}

	/** Save or update credentials, merging existing entries and removing selected names. */
	public static function save( $seller_id, $provider_id, array $values, array $remove = array() ) {
		$seller_id = absint( $seller_id );
		$provider_id = sanitize_key( $provider_id );
		if ( ! $seller_id || '' === $provider_id ) { return new \WP_Error( 'wpcap_fiscal_credentials_scope', __( 'Verkäufer und Anbieter sind erforderlich.', 'pass-captain' ) ); }
		$credentials = self::load( $seller_id, $provider_id );
		foreach ( $values as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $key ) || ! is_scalar( $value ) ) { return new \WP_Error( 'wpcap_fiscal_credentials_invalid', __( 'Zugangsdaten enthalten ein ungültiges Feld.', 'pass-captain' ) ); }
			$value = (string) $value;
			if ( '' === $value || strlen( $value ) > 8192 ) { return new \WP_Error( 'wpcap_fiscal_credentials_invalid', __( 'Zugangsdaten dürfen nicht leer sein und maximal 8192 Zeichen umfassen.', 'pass-captain' ) ); }
			$credentials[ $key ] = $value;
		}
		foreach ( $remove as $key ) { unset( $credentials[ sanitize_key( $key ) ] ); }
		if ( ! $credentials ) { return self::delete( $seller_id, $provider_id ); }
		return self::store( $seller_id, $provider_id, $credentials );
	}

	/** Encrypts and writes one entry; refuses to store anything in plaintext. */
	private static function store( $seller_id, $provider_id, array $credentials ) {
		if ( ! self::storage_available() ) {
			return new \WP_Error( 'wpcap_fiscal_credentials_crypto', __( 'Zugangsdaten können nicht verschlüsselt gespeichert werden (libsodium oder Schlüssel PASS_CAPTAIN_SECRET_KEY bzw. wp-config-Salts fehlen).', 'pass-captain' ) );
		}
		$cipher = Vault::encrypt( (string) wp_json_encode( $credentials ), self::context( $seller_id, $provider_id ) );
		if ( ! Vault::is_encrypted( $cipher ) ) {
			return new \WP_Error( 'wpcap_fiscal_credentials_crypto', __( 'Zugangsdaten konnten nicht sicher verschlüsselt werden.', 'pass-captain' ) );
		}
		$vault = self::entries();
		$vault[ $seller_id ][ $provider_id ] = array(
			'keys' => array_values( array_map( 'strval', array_keys( $credentials ) ) ),
			'value' => $cipher,
		);
		$saved = update_option( self::OPTION, $vault, false );
		if ( ! $saved && get_option( self::OPTION, array() ) !== $vault ) {
			return new \WP_Error( 'wpcap_fiscal_credentials_save', __( 'Verschlüsselte Zugangsdaten konnten nicht gespeichert werden.', 'pass-captain' ) );
		}
		return true;
	}

	/** @return array<int,array<string,array>> */
	private static function entries() {
		$vault = get_option( self::OPTION, array() );
		return is_array( $vault ) ? $vault : array();
	}

	/** Decrypt for one adapter call; corrupt, foreign-context or key-mismatched entries fail closed. */
	private static function load( $seller_id, $provider_id ) {
		$seller_id = absint( $seller_id );
		$provider_id = sanitize_key( $provider_id );
		$entry = self::entries()[ $seller_id ][ $provider_id ] ?? null;
		if ( ! is_array( $entry ) ) { return array(); }
		if ( ! isset( $entry['value'] ) ) {
			return self::migrate_legacy_entry( $seller_id, $provider_id, $entry );
		}
		$stored = (string) $entry['value'];
		if ( ! Vault::is_encrypted( $stored ) ) { return array(); } // Never accept a plaintext entry.
		$context = self::context( $seller_id, $provider_id );
		$plain = Vault::decrypt( $stored, $context );
		$decoded = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $decoded ) ) {
			Log::warning( 'fiscal_credentials_undecryptable', 'Stored fiscal provider credentials cannot be decrypted (key changed or entry tampered with); they count as not set.', array( 'object_type' => 'seller', 'object_id' => $seller_id, 'details' => array( 'provider_id' => $provider_id ) ) );
			return array();
		}
		if ( Vault::needs_reencrypt_value( $stored, $context ) ) {
			self::store( $seller_id, $provider_id, $decoded ); // Key rotation: re-encrypt with the current key.
		}
		return $decoded;
	}

	/**
	 * Entries of the earlier draft format (AES-256-GCM, key from the auth/secure_auth salts): decrypted
	 * once and re-encrypted with the Vault; undecryptable entries are removed (fail closed).
	 */
	private static function migrate_legacy_entry( $seller_id, $provider_id, array $entry ) {
		$decoded = null;
		$nonce = base64_decode( (string) ( $entry['nonce'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext transport encoding.
		$tag = base64_decode( (string) ( $entry['tag'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext transport encoding.
		$cipher = base64_decode( (string) ( $entry['ciphertext'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext transport encoding.
		if ( function_exists( 'openssl_decrypt' ) && function_exists( 'wp_salt' ) && false !== $nonce && 12 === strlen( $nonce ) && false !== $tag && 16 === strlen( $tag ) && false !== $cipher ) {
			$key = hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
			$decoded = false === $plain ? null : json_decode( $plain, true );
		}
		if ( ! is_array( $decoded ) || is_wp_error( self::store( $seller_id, $provider_id, $decoded ) ) ) {
			self::delete( $seller_id, $provider_id );
			Log::warning( 'fiscal_credentials_legacy_dropped', 'Fiscal provider credentials in the earlier draft format could not be migrated and were removed; please enter them again.', array( 'object_type' => 'seller', 'object_id' => $seller_id, 'details' => array( 'provider_id' => $provider_id ) ) );
			return array();
		}
		return $decoded;
	}

	/**
	 * Re-encrypts all entries that are legacy or only readable with the previous master key
	 * (called by Security\Vault::migrate()). Returns the number of entries rewritten.
	 */
	public static function migrate() {
		$count = 0;
		foreach ( self::entries() as $seller_id => $providers ) {
			foreach ( (array) $providers as $provider_id => $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				$legacy = ! isset( $entry['value'] );
				$rotate = ! $legacy && Vault::needs_reencrypt_value( (string) $entry['value'], self::context( $seller_id, $provider_id ) );
				if ( $legacy || $rotate ) {
					self::load( $seller_id, $provider_id );
					++$count;
				}
			}
		}
		return $count;
	}

	/** Credential names only; values are never exposed to the admin page or REST. */
	public static function stored_names( $seller_id, $provider_id ) {
		$entry = self::entries()[ absint( $seller_id ) ][ sanitize_key( $provider_id ) ] ?? null;
		return is_array( $entry ) && is_array( $entry['keys'] ?? null ) ? array_values( array_map( 'sanitize_key', $entry['keys'] ) ) : array();
	}

	public static function delete( $seller_id, $provider_id ) {
		$vault = self::entries();
		unset( $vault[ absint( $seller_id ) ][ sanitize_key( $provider_id ) ] );
		if ( empty( $vault[ absint( $seller_id ) ] ) ) { unset( $vault[ absint( $seller_id ) ] ); }
		update_option( self::OPTION, $vault, false );
		return true;
	}

	/**
	 * Short-lived provider access token cache (e.g. fiskaly bearer tokens), encrypted with the Vault so a
	 * database dump does not expose a usable token. Returns '' when nothing usable is cached.
	 */
	public static function cached_token( $cache_key ) {
		$stored = get_transient( (string) $cache_key );
		if ( ! is_string( $stored ) || ! Vault::is_encrypted( $stored ) ) { return ''; }
		$plain = Vault::decrypt( $stored, 'fiscal_token:' . $cache_key );
		if ( null === $plain || '' === $plain ) { return ''; }
		Redact::remember( $plain );
		return $plain;
	}

	/** Caches a provider token encrypted; nothing is cached when encryption is unavailable. */
	public static function cache_token( $cache_key, $token, $ttl ) {
		Redact::remember( (string) $token );
		$cipher = Vault::encrypt( (string) $token, 'fiscal_token:' . $cache_key );
		if ( Vault::is_encrypted( $cipher ) ) { set_transient( (string) $cache_key, $cipher, (int) $ttl ); }
	}

	public static function forget_token( $cache_key ) {
		delete_transient( (string) $cache_key );
	}
}
