<?php
/**
 * Encryption at rest for provider secrets (Stripe, PayPal, Mollie, Twilio, SMS gateway, Wallet
 * keys, webhook signing secrets, fiscal provider credentials per seller/provider).
 *
 * Format: "pcv1:" . base64( nonce(24) . secretbox(plaintext) ) using libsodium
 * (sodium_crypto_secretbox, XSalsa20-Poly1305). Every value is encrypted with a sub key derived
 * for its context (setting name), so a ciphertext copied into another field does not decrypt.
 *
 * Key (never stored in the database), first match wins:
 *   1. constant PASS_CAPTAIN_SECRET_KEY (wp-config.php)
 *   2. environment variable PASS_CAPTAIN_SECRET_KEY
 *   3. fallback: derived from the wp-config salts AUTH_KEY + SECURE_AUTH_KEY (HKDF-SHA256).
 *      Rotating those salts makes stored secrets undecryptable – they then count as "not set"
 *      and the admin is asked to enter them again. See docs/SECURITY.md.
 * Key rotation: keep the old key in PASS_CAPTAIN_SECRET_KEY_PREVIOUS; values are re-encrypted
 * with the new key by migrate() (runs on upgrade and on admin requests until done).
 */
namespace PassCaptain\Security;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Vault {
	const PREFIX = 'pcv1:';
	const MIGRATED_OPTION = 'wpcap_vault_version';
	const VERSION = 3;

	/** Test seam / per-request cache of the master keys ([current, previous|null]). */
	private static $keys = null;

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 5, 0 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/** Drops cached keys (tests, key change in the same request). */
	public static function reset() {
		self::$keys = null;
	}

	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'hash_hkdf' );
	}

	/** Where the master key comes from: 'constant', 'env', 'salts' or '' (no key, no encryption). */
	public static function key_source() {
		if ( defined( 'PASS_CAPTAIN_SECRET_KEY' ) && '' !== (string) constant( 'PASS_CAPTAIN_SECRET_KEY' ) ) { return 'constant'; }
		$env = getenv( 'PASS_CAPTAIN_SECRET_KEY' );
		if ( is_string( $env ) && '' !== $env ) { return 'env'; }
		return '' !== self::salt_material() ? 'salts' : '';
	}

	private static function salt_material() {
		$parts = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $constant ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { $parts[] = (string) constant( $constant ); }
		}
		if ( ! $parts && function_exists( 'wp_salt' ) ) {
			// wp_salt() falls back to salts stored in the options table when wp-config has none.
			$parts = array( (string) wp_salt( 'auth' ), (string) wp_salt( 'secure_auth' ) );
		}
		$material = implode( '|', $parts );
		return strlen( $material ) >= 16 ? $material : '';
	}

	/** 32 byte key from configured material: 64 hex chars, base64 of 32 bytes or any longer passphrase. */
	private static function normalise_key( $material ) {
		$material = trim( (string) $material );
		if ( '' === $material ) { return null; }
		if ( preg_match( '/^[a-f0-9]{64}$/i', $material ) ) { return hex2bin( $material ); }
		$decoded = base64_decode( $material, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key/ciphertext transport encoding.
		if ( false !== $decoded && 32 === strlen( $decoded ) ) { return $decoded; }
		return hash_hkdf( 'sha256', $material, 32, 'pass-captain-vault-key', 'pass-captain' );
	}

	/** @return array{0:?string,1:?string} [current master key, previous master key]. */
	private static function keys() {
		if ( null !== self::$keys ) { return self::$keys; }
		$current = null;
		$source = self::key_source();
		if ( 'constant' === $source ) {
			$current = self::normalise_key( constant( 'PASS_CAPTAIN_SECRET_KEY' ) );
		} elseif ( 'env' === $source ) {
			$current = self::normalise_key( getenv( 'PASS_CAPTAIN_SECRET_KEY' ) );
		} elseif ( 'salts' === $source ) {
			$current = hash_hkdf( 'sha256', self::salt_material(), 32, 'pass-captain-vault-salts', 'pass-captain' );
		}
		$previous = null;
		if ( defined( 'PASS_CAPTAIN_SECRET_KEY_PREVIOUS' ) ) {
			$previous = self::normalise_key( constant( 'PASS_CAPTAIN_SECRET_KEY_PREVIOUS' ) );
		} elseif ( is_string( getenv( 'PASS_CAPTAIN_SECRET_KEY_PREVIOUS' ) ) && '' !== getenv( 'PASS_CAPTAIN_SECRET_KEY_PREVIOUS' ) ) {
			$previous = self::normalise_key( getenv( 'PASS_CAPTAIN_SECRET_KEY_PREVIOUS' ) );
		}
		if ( null === $previous && 'salts' !== $source && '' !== self::salt_material() ) {
			// Values encrypted before a dedicated key was configured stay readable and get re-encrypted.
			$previous = hash_hkdf( 'sha256', self::salt_material(), 32, 'pass-captain-vault-salts', 'pass-captain' );
		}
		// Not cached without key: wp_salt() is pluggable and may not exist yet early in the request.
		if ( null === $current ) { return array( null, $previous ); }
		self::$keys = array( $current, $previous );
		return self::$keys;
	}

	private static function subkey( $master, $context ) {
		return hash_hkdf( 'sha256', $master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'field:' . (string) $context, 'pass-captain-vault' );
	}

	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strncmp( $value, self::PREFIX, strlen( self::PREFIX ) );
	}

	/**
	 * Encrypts a value for a context. Empty values and values that are already encrypted are
	 * returned unchanged; without libsodium or key the plaintext is returned (see status()).
	 */
	public static function encrypt( $plaintext, $context ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || self::is_encrypted( $plaintext ) || ! self::available() ) { return $plaintext; }
		list( $key ) = self::keys();
		if ( null === $key ) { return $plaintext; }
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::subkey( $key, $context ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key/ciphertext transport encoding.
	}

	/**
	 * Decrypts a stored value. Plaintext (legacy, not yet migrated) is returned as is.
	 *
	 * @return string|null Plaintext, or null when the value cannot be decrypted (key lost/changed, tampered).
	 */
	public static function decrypt( $stored, $context ) {
		$stored = (string) $stored;
		if ( ! self::is_encrypted( $stored ) ) { return $stored; }
		$result = self::open( $stored, $context );
		return null === $result ? null : $result[0];
	}

	/** @return array{0:string,1:bool}|null [plaintext, decrypted with the previous key]. */
	private static function open( $stored, $context ) {
		if ( ! self::available() ) { return null; }
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key/ciphertext transport encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) { return null; }
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		foreach ( self::keys() as $index => $key ) {
			if ( null === $key ) { continue; }
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::subkey( $key, $context ) );
			if ( false !== $plain ) { return array( $plain, 1 === $index ); }
		}
		return null;
	}

	/** Masked hint for the admin UI, e.g. "••••1234" (short secrets show no characters). */
	public static function mask( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) { return ''; }
		return strlen( $plaintext ) >= 16 ? '••••' . substr( $plaintext, -4 ) : '••••';
	}

	/* ------------------------------------------------------------------ Migration */

	public static function maybe_migrate() {
		if ( (int) get_option( self::MIGRATED_OPTION, 0 ) >= self::VERSION && ! self::needs_reencrypt() ) { return; }
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }
		self::migrate();
	}

	/** True when a stored setting is still plaintext or only readable with the previous key. */
	private static function needs_reencrypt() {
		$stored = get_option( Settings::OPTION, array() );
		if ( ! is_array( $stored ) ) { return false; }
		foreach ( Settings::secret_keys() as $key ) {
			$value = $stored[ $key ] ?? '';
			if ( ! is_string( $value ) || '' === $value ) { continue; }
			if ( ! self::is_encrypted( $value ) ) { return self::available() && null !== self::keys()[0]; }
			$opened = self::open( $value, $key );
			if ( $opened && $opened[1] ) { return true; }
		}
		return false;
	}

	/**
	 * Encrypts plaintext secrets in wpcap_settings and in the webhook subscription table once, and
	 * re-encrypts values that are only readable with the previous key. Idempotent.
	 *
	 * @return array{settings:int,webhooks:int,partners?:int,fiscal?:int} Number of values (re-)encrypted.
	 */
	public static function migrate() {
		$done = array( 'settings' => 0, 'webhooks' => 0, 'partners' => 0, 'fiscal' => 0 );
		if ( ! self::available() || null === self::keys()[0] ) { return $done; }
		$stored = get_option( Settings::OPTION, array() );
		if ( is_array( $stored ) ) {
			$changed = false;
			foreach ( Settings::secret_keys() as $key ) {
				$value = $stored[ $key ] ?? '';
				if ( ! is_string( $value ) || '' === $value ) { continue; }
				$plain = self::plain_for_reencrypt( $value, $key );
				if ( null === $plain ) { continue; }
				$stored[ $key ] = self::encrypt( $plain, $key );
				$changed = true;
				++$done['settings'];
			}
			if ( $changed ) {
				update_option( Settings::OPTION, $stored, true );
				Settings::flush();
			}
		}
		$done['webhooks'] = self::migrate_webhook_secrets();
		// Partner payout details (IBAN etc.), also in finalized settlement snapshots (since vault version 2).
		$done['partners'] = \PassCaptain\Partners\Repository::migrate_payout_details();
		// Fiscal provider credentials per seller/provider (since vault version 3).
		$done['fiscal'] = \PassCaptain\Fiscalization\Credentials::migrate();
		update_option( self::MIGRATED_OPTION, self::VERSION, false );
		return $done;
	}

	/** True when an encrypted value is only readable with the previous master key (re-encrypt it). */
	public static function needs_reencrypt_value( $stored, $context ) {
		if ( ! self::is_encrypted( $stored ) ) { return false; }
		$opened = self::open( (string) $stored, $context );
		return $opened && $opened[1];
	}

	/** Plaintext when the stored value must be (re-)encrypted, null when it is fine or unreadable. */
	private static function plain_for_reencrypt( $value, $context ) {
		if ( ! self::is_encrypted( $value ) ) { return $value; }
		$opened = self::open( $value, $context );
		return $opened && $opened[1] ? $opened[0] : null;
	}

	private static function migrate_webhook_secrets() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_webhook_subscriptions';
		$column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'secret' ) );
		// Only once the column is wide enough for ciphertext (schema upgrade); never truncate a secret.
		if ( ! $column || ! preg_match( '/\((\d+)\)/', (string) ( $column->Type ?? '' ), $m ) || (int) $m[1] < 200 ) { return 0; } // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$count = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT subscription_id,secret FROM %i', $table ) ) as $row ) {
			$plain = self::plain_for_reencrypt( (string) $row->secret, 'webhook_secret' );
			if ( null === $plain || '' === $plain ) { continue; }
			$wpdb->update( $table, array( 'secret' => self::encrypt( $plain, 'webhook_secret' ) ), array( 'subscription_id' => (int) $row->subscription_id ) );
			++$count;
		}
		return $count;
	}

	/* ------------------------------------------------------------------ Status / notices */

	/** Settings whose stored value cannot be decrypted (and is not overridden by a constant/env var). */
	public static function undecryptable_settings() {
		$out = array();
		$stored = get_option( Settings::OPTION, array() );
		if ( ! is_array( $stored ) ) { return $out; }
		foreach ( Settings::secret_keys() as $key ) {
			$value = $stored[ $key ] ?? '';
			if ( self::is_encrypted( $value ) && '' === Settings::override_source( $key ) && null === self::decrypt( $value, $key ) ) { $out[] = $key; }
		}
		return $out;
	}

	public static function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$broken = self::undecryptable_settings();
		if ( $broken ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pass Captain:', 'pass-captain' ) . '</strong> ';
			/* translators: %s: comma separated setting names */
			echo esc_html( sprintf( __( 'Gespeicherte Zugangsdaten können nicht mehr entschlüsselt werden (Schlüssel PASS_CAPTAIN_SECRET_KEY oder die wp-config-Salts wurden geändert): %s. Sie werden als „nicht gesetzt“ behandelt – bitte erneut eingeben.', 'pass-captain' ), implode( ', ', $broken ) ) );
			echo '</p></div>';
		}
		if ( ! self::available() || '' === self::key_source() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Pass Captain: libsodium oder ein Schlüssel fehlt – Zugangsdaten werden unverschlüsselt gespeichert.', 'pass-captain' ) . '</p></div>';
		}
	}

	/** Explanation of the key source for the settings screen. */
	public static function key_notice() {
		switch ( self::key_source() ) {
			case 'constant':
			case 'env':
				return array( 'success', __( 'Zugangsdaten werden mit PASS_CAPTAIN_SECRET_KEY verschlüsselt gespeichert.', 'pass-captain' ) );
			case 'salts':
				return array( 'warning', __( 'Zugangsdaten werden mit einem aus den wp-config-Salts (AUTH_KEY, SECURE_AUTH_KEY) abgeleiteten Schlüssel verschlüsselt. Werden die Salts erneuert, müssen alle Zugangsdaten neu eingegeben werden. Empfohlen: eigene Konstante PASS_CAPTAIN_SECRET_KEY (32 zufällige Bytes, hex) in wp-config.php.', 'pass-captain' ) );
		}
		return array( 'error', __( 'Kein Schlüssel verfügbar: Zugangsdaten werden unverschlüsselt gespeichert.', 'pass-captain' ) );
	}

	/** Test seam: sets the master keys directly. */
	public static function set_keys_for_tests( $current, $previous = null ) {
		self::$keys = array( $current, $previous );
	}
}
