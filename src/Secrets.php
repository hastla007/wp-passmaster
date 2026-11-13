<?php
/**
 * Encrypted credential store for provider secrets (Stripe, PayPal, Mollie, SMS, Wallet).
 *
 * Secrets never live in the general option `wpcap_settings`. They are stored in the option
 * `wpcap_secrets` (not autoloaded), each value sealed with sodium_crypto_secretbox (XSalsa20-Poly1305,
 * authenticated) under a key that is NOT stored in the database:
 *
 * - preferred: constant WPCAP_SECRETS_KEY in wp-config.php (or environment variable of that name),
 * - fallback: derived from the WordPress salts (wp_salt('auth') + AUTH_KEY); administrators see a
 *   notice recommending the dedicated constant.
 *
 * Entry format: "v1:<key id>:<base64(nonce . box)>". The key id is a short keyed hash of the key, so
 * entries sealed with another key are recognised without trial decryption. The sealed plaintext is
 * "<setting name>\0<value>", which binds a box to its name (boxes cannot be swapped between names).
 *
 * Lookup order (get()): constant WPCAP_<NAME> > environment variable WPCAP_<NAME> > vault.
 * Fail closed: a vault entry that cannot be decrypted yields '' (the gateway counts as not
 * configured) and an admin notice names the affected settings; values are never logged.
 *
 * Rotation: set the new WPCAP_SECRETS_KEY and the old one as WPCAP_SECRETS_KEY_PREVIOUS. Entries are
 * still readable with the previous key and are re-encrypted with the new one on the next admin, cron
 * or WP-CLI request (rewrap(), behind the upgrade lock). See docs/OPERATIONS.md "Zugangsdaten / Secrets".
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Secrets {
	const OPTION = 'wpcap_secrets';
	/** Version of the plaintext-to-vault migration that has run on this site. */
	const VERSION_OPTION = 'wpcap_secrets_version';
	const VERSION = 1;
	const FORMAT = 'v1';

	/** Settings keys that are secrets (all of them are kept out of wpcap_settings). */
	const KEYS = array(
		'stripe_secret_key',
		'stripe_webhook_secret',
		'paypal_client_secret',
		'mollie_api_key',
		'twilio_auth_token',
		'sms_http_secret',
		'sms_http_webhook_secret',
		'apple_pass_cert',
		'apple_pass_key',
		'apple_pass_key_password',
		'apple_wwdr_cert',
		'google_wallet_service_account',
	);

	/** Per-request cache of decrypted values: name => [sealed box + key ids, plaintext or false]. */
	private static $cache = array();

	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	public static function is_secret( $key ) {
		return in_array( (string) $key, self::KEYS, true );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Keys
	 * ------------------------------------------------------------------------------------------- */

	/** Raw key material from a constant or environment variable ('' if not set). */
	private static function material( $name ) {
		if ( defined( $name ) && '' !== (string) constant( $name ) ) { return (string) constant( $name ); }
		$env = getenv( $name );
		return is_string( $env ) && '' !== $env ? $env : '';
	}

	private static function derive( $material ) {
		return hash_hmac( 'sha256', 'wpcap-secrets-secretbox-v1', (string) $material, true );
	}

	/** Where the current key comes from: 'constant' (WPCAP_SECRETS_KEY) or 'salts'. */
	public static function key_source() {
		return '' !== self::material( 'WPCAP_SECRETS_KEY' ) ? 'constant' : 'salts';
	}

	private static function salt_key() {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return self::derive( $salt . '|' . ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' ) );
	}

	/** Key used for new entries. */
	private static function current_key() {
		$material = self::material( 'WPCAP_SECRETS_KEY' );
		return '' !== $material ? self::derive( $material ) : self::salt_key();
	}

	/** Short public identifier of a key (keyed hash; reveals nothing about the key). */
	private static function kid( $key ) {
		return substr( hash_hmac( 'sha256', 'wpcap-secrets-key-id', $key ), 0, 10 );
	}

	/**
	 * Keys an entry may be sealed with, by key id: the current key, WPCAP_SECRETS_KEY_PREVIOUS and,
	 * once a dedicated key is configured, the salt-derived key used before (for the switch-over).
	 */
	private static function keyring() {
		$ring = array();
		$current = self::current_key();
		$ring[ self::kid( $current ) ] = $current;
		$previous = self::material( 'WPCAP_SECRETS_KEY_PREVIOUS' );
		if ( '' !== $previous ) {
			$key = self::derive( $previous );
			$ring[ self::kid( $key ) ] = $key;
		}
		if ( 'constant' === self::key_source() ) {
			$salt = self::salt_key();
			$ring[ self::kid( $salt ) ] = $salt;
		}
		return $ring;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Sealing
	 * ------------------------------------------------------------------------------------------- */

	private static function seal( $name, $value ) {
		$key = self::current_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box = sodium_crypto_secretbox( $name . "\0" . $value, $nonce, $key );
		return self::FORMAT . ':' . self::kid( $key ) . ':' . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Key id of a sealed entry ('' if malformed). */
	private static function entry_kid( $sealed ) {
		$parts = explode( ':', (string) $sealed, 3 );
		return 3 === count( $parts ) && self::FORMAT === $parts[0] ? $parts[1] : '';
	}

	/** Plaintext of a sealed entry, or false (unknown key, tampered, wrong name, malformed). */
	private static function open( $name, $sealed ) {
		$parts = explode( ':', (string) $sealed, 3 );
		if ( 3 !== count( $parts ) || self::FORMAT !== $parts[0] ) { return false; }
		$ring = self::keyring();
		if ( ! isset( $ring[ $parts[1] ] ) ) { return false; }
		$raw = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) { return false; }
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $ring[ $parts[1] ] );
		} catch ( \Throwable $e ) {
			return false;
		}
		$prefix = $name . "\0";
		if ( ! is_string( $plain ) || 0 !== strpos( $plain, $prefix ) ) { return false; }
		return (string) substr( $plain, strlen( $prefix ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------------------------------- */

	private static function vault() {
		$vault = get_option( self::OPTION, array() );
		return is_array( $vault ) ? $vault : array();
	}

	private static function save_vault( array $vault ) {
		update_option( self::OPTION, $vault, false );
		self::$cache = array();
	}

	/**
	 * Secret value: constant WPCAP_<NAME> > environment variable > vault. '' when not set or when
	 * the stored value cannot be decrypted (fail closed).
	 */
	public static function get( $key ) {
		$key = (string) $key;
		$override = self::material( 'WPCAP_' . strtoupper( $key ) );
		if ( '' !== $override ) { return $override; }
		if ( ! self::is_secret( $key ) ) { return ''; }
		$value = self::stored( $key );
		return false === $value ? '' : $value;
	}

	/**
	 * Value held by the plugin itself (ignores constants): string, '' if none, false if an entry
	 * exists but cannot be decrypted.
	 */
	public static function stored( $key ) {
		$key = (string) $key;
		if ( ! self::is_secret( $key ) ) { return ''; }
		$vault = self::vault();
		if ( ! isset( $vault[ $key ] ) || '' === $vault[ $key ] ) {
			// Transition only: until the one-off migration ran, the old plaintext value is still used.
			if ( self::migration_pending() ) {
				$raw = get_option( Settings::OPTION, array() );
				return is_array( $raw ) && is_scalar( $raw[ $key ] ?? null ) ? (string) $raw[ $key ] : '';
			}
			return '';
		}
		// Cached per entry and configured keys, so a changed key is never answered from the cache.
		$sealed = (string) $vault[ $key ] . '|' . implode( ',', array_keys( self::keyring() ) );
		if ( ! isset( self::$cache[ $key ] ) || self::$cache[ $key ][0] !== $sealed ) {
			self::$cache[ $key ] = array( $sealed, self::open( $key, (string) $vault[ $key ] ) );
		}
		return self::$cache[ $key ][1];
	}

	/** Stores (or with '' removes) a secret. Unknown keys are ignored. */
	public static function set( $key, $value ) {
		$key = (string) $key;
		if ( ! self::is_secret( $key ) ) { return false; }
		$vault = self::vault();
		$value = (string) $value;
		if ( '' === $value ) {
			unset( $vault[ $key ] );
		} else {
			$vault[ $key ] = self::seal( $key, $value );
		}
		self::save_vault( $vault );
		return true;
	}

	/** 'constant', 'env', 'vault', 'undecryptable' or ''. */
	public static function source( $key ) {
		$constant = 'WPCAP_' . strtoupper( (string) $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return 'constant'; }
		$env = getenv( $constant );
		if ( is_string( $env ) && '' !== $env ) { return 'env'; }
		$stored = self::stored( $key );
		if ( false === $stored ) { return 'undecryptable'; }
		return '' !== $stored ? 'vault' : '';
	}

	/** Masked hint for the admin: "…abcd" for values of 12+ characters, otherwise ''. */
	public static function hint( $key ) {
		$stored = self::stored( $key );
		if ( ! is_string( $stored ) ) { return ''; }
		$stored = rtrim( $stored );
		return mb_strlen( $stored ) >= 12 ? '…' . mb_substr( $stored, -4 ) : '';
	}

	/** Names of vault entries that cannot be decrypted with any configured key. */
	public static function undecryptable() {
		$failed = array();
		foreach ( array_keys( self::vault() ) as $key ) {
			if ( self::is_secret( $key ) && false === self::stored( $key ) ) { $failed[] = (string) $key; }
		}
		return $failed;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Migration and rotation
	 * ------------------------------------------------------------------------------------------- */

	/** Whether plaintext secrets may still be in wpcap_settings. */
	public static function migration_pending() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < self::VERSION ) { return true; }
		$raw = get_option( Settings::OPTION, array() );
		return is_array( $raw ) && (bool) array_intersect_key( $raw, array_flip( self::KEYS ) );
	}

	/**
	 * Moves plaintext secrets from wpcap_settings into the vault and removes them there. Idempotent.
	 * A plaintext value found after an earlier migration (e.g. written by an older plugin version
	 * after a downgrade) is newer than the vault entry and replaces it.
	 *
	 * @return string[] names that were moved.
	 */
	public static function migrate() {
		$raw = get_option( Settings::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$moved = array();
		$vault = self::vault();
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) { continue; }
			$value = is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
			if ( '' !== $value ) {
				$vault[ $key ] = self::seal( $key, $value );
				$moved[] = $key;
			}
			unset( $raw[ $key ] );
		}
		// Vault first: if the request dies in between, the plaintext is still there and the next run repeats.
		if ( $moved ) { self::save_vault( $vault ); }
		update_option( Settings::OPTION, $raw, false );
		update_option( self::VERSION_OPTION, self::VERSION );
		Settings::flush();
		if ( $moved ) {
			Audit::record( 'secrets_migrated', 'settings', 0, array( 'keys' => $moved ) );
		}
		return $moved;
	}

	/** Whether vault entries are sealed with a key other than the current one. */
	public static function rewrap_pending() {
		$current = self::kid( self::current_key() );
		foreach ( self::vault() as $sealed ) {
			if ( self::entry_kid( $sealed ) !== $current ) { return true; }
		}
		return false;
	}

	/**
	 * Re-encrypts entries sealed with WPCAP_SECRETS_KEY_PREVIOUS (or the former salt-derived key)
	 * with the current key. Entries that no configured key opens are left untouched (they may become
	 * readable once the right previous key is configured) and reported.
	 *
	 * @return array{rewrapped:string[],failed:string[]}
	 */
	public static function rewrap() {
		$current = self::kid( self::current_key() );
		$vault = self::vault();
		$result = array( 'rewrapped' => array(), 'failed' => array() );
		foreach ( $vault as $key => $sealed ) {
			if ( ! self::is_secret( $key ) || self::entry_kid( $sealed ) === $current ) { continue; }
			$plain = self::open( (string) $key, $sealed );
			if ( false === $plain ) {
				$result['failed'][] = (string) $key;
				continue;
			}
			$vault[ $key ] = self::seal( (string) $key, $plain );
			$result['rewrapped'][] = (string) $key;
		}
		if ( $result['rewrapped'] ) {
			self::save_vault( $vault );
			Audit::record( 'secrets_rewrapped', 'settings', 0, array( 'keys' => $result['rewrapped'] ) );
		}
		return $result;
	}

	/**
	 * Runs a pending migration (all contexts) or rewrap (admin, cron, WP-CLI) behind the upgrade lock.
	 * Cheap when nothing is pending.
	 *
	 * @param string $context Installer::context() value.
	 */
	public static function maybe_upgrade( $context = 'admin' ) {
		$migrate = self::migration_pending();
		$rewrap = 'frontend' !== $context && self::rewrap_pending();
		if ( ! $migrate && ! $rewrap ) { return false; }
		return Installer::run_locked(
			static function () {
				if ( self::migration_pending() ) { self::migrate(); }
				if ( self::rewrap_pending() ) { self::rewrap(); }
			}
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------------------------------- */

	/** Human-readable label of a secret for notices. */
	public static function label( $key ) {
		$labels = array(
			'stripe_secret_key' => __( 'Stripe Secret Key', 'pass-captain' ),
			'stripe_webhook_secret' => __( 'Stripe Webhook-Signing-Secret', 'pass-captain' ),
			'paypal_client_secret' => __( 'PayPal Client-Secret', 'pass-captain' ),
			'mollie_api_key' => __( 'Mollie API-Schlüssel', 'pass-captain' ),
			'twilio_auth_token' => __( 'Twilio Auth Token', 'pass-captain' ),
			'sms_http_secret' => __( 'SMS-HTTP-Zugangsdaten', 'pass-captain' ),
			'sms_http_webhook_secret' => __( 'SMS-HTTP-Signaturschlüssel für Rückmeldungen', 'pass-captain' ),
			'apple_pass_cert' => __( 'Apple-Wallet-Zertifikat', 'pass-captain' ),
			'apple_pass_key' => __( 'Apple-Wallet-Schlüssel', 'pass-captain' ),
			'apple_pass_key_password' => __( 'Apple-Wallet-Schlüsselpasswort', 'pass-captain' ),
			'apple_wwdr_cert' => __( 'Apple-WWDR-Zertifikat', 'pass-captain' ),
			'google_wallet_service_account' => __( 'Google-Wallet-Servicekonto', 'pass-captain' ),
		);
		return $labels[ $key ] ?? (string) $key;
	}

	/** Status text for a secret field (never contains more than the last four characters). */
	public static function status_text( $key ) {
		switch ( self::source( $key ) ) {
			case 'constant':
			case 'env':
				/* translators: %s: constant name */
				return sprintf( __( 'Gesetzt über %s (wp-config.php oder Umgebungsvariable); ein hier gespeicherter Wert wird nicht verwendet.', 'pass-captain' ), 'WPCAP_' . strtoupper( $key ) );
			case 'undecryptable':
				return __( 'Gespeichert, aber nicht entschlüsselbar (Schlüssel geändert?) – bitte neu eingeben. Bis dahin gilt der Dienst als nicht eingerichtet.', 'pass-captain' );
			case 'vault':
				$hint = self::hint( $key );
				/* translators: %s: last four characters, e.g. "…a1b2" */
				return '' !== $hint ? sprintf( __( 'Gespeichert (verschlüsselt), endet auf %s', 'pass-captain' ), $hint ) : __( 'Gespeichert (verschlüsselt)', 'pass-captain' );
			default:
				return __( 'Kein Wert gespeichert', 'pass-captain' );
		}
	}

	/**
	 * Masked form field: empty input (empty = keep), status with the last four characters and a
	 * "löschen" checkbox. Values from constants cannot be edited here.
	 *
	 * @param string $key        Secret name (also the input name).
	 * @param string $id         HTML id of the input.
	 * @param string $clear_name Name of the delete checkbox.
	 * @param bool   $multiline  Textarea (certificates, JSON) instead of a password input.
	 */
	public static function render_field( $key, $id = '', $clear_name = '', $multiline = false ) {
		$id = '' !== $id ? $id : 'wpcap-set-' . $key;
		$clear_name = '' !== $clear_name ? $clear_name : $key . '__clear';
		$source = self::source( $key );
		if ( in_array( $source, array( 'constant', 'env' ), true ) ) {
			echo '<p><strong>' . esc_html( self::status_text( $key ) ) . '</strong></p>';
			return;
		}
		$stored = in_array( $source, array( 'vault', 'undecryptable' ), true );
		$placeholder = $stored ? __( 'Leer lassen, um den gespeicherten Wert zu behalten', 'pass-captain' ) : __( 'Nicht gesetzt', 'pass-captain' );
		if ( $multiline ) {
			echo '<textarea class="large-text code" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '"></textarea>';
		} else {
			echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr( $placeholder ) . '">';
		}
		echo ' <strong>' . esc_html( self::status_text( $key ) ) . '</strong>';
		if ( $stored ) {
			echo ' <label><input type="checkbox" name="' . esc_attr( $clear_name ) . '" value="1"> ' . esc_html__( 'Gespeicherten Wert löschen', 'pass-captain' ) . '</label>';
		}
		/* translators: %s: constant name */
		echo '<p class="description">' . esc_html( sprintf( __( 'Wird verschlüsselt gespeichert. Alternativ als Konstante %s in wp-config.php definieren.', 'pass-captain' ), 'WPCAP_' . strtoupper( $key ) ) ) . '</p>';
	}

	/** Admin notice: undecryptable entries (error) and salt-derived key in use (warning). */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$vault = self::vault();
		if ( ! $vault ) { return; }
		$failed = self::undecryptable();
		if ( $failed ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pass Captain: Gespeicherte Zugangsdaten können nicht entschlüsselt werden.', 'pass-captain' ) . '</strong> '
				. esc_html( implode( ', ', array_map( array( __CLASS__, 'label' ), $failed ) ) ) . '. '
				. esc_html__( 'Die betroffenen Zahlungsarten und Dienste gelten bis zur Behebung als nicht eingerichtet. Ursache ist meist ein geänderter WPCAP_SECRETS_KEY oder geänderte WordPress-Salts: den bisherigen Schlüssel als WPCAP_SECRETS_KEY_PREVIOUS in wp-config.php eintragen oder die Werte in den Einstellungen neu eingeben.', 'pass-captain' )
				. '</p></div>';
		}
		if ( 'salts' === self::key_source() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Pass Captain: Zugangsdaten (z. B. Stripe, PayPal) werden mit einem aus den WordPress-Salts abgeleiteten Schlüssel verschlüsselt. Empfohlen: einen eigenen Schlüssel als Konstante WPCAP_SECRETS_KEY in wp-config.php festlegen (mindestens 32 zufällige Zeichen) – sonst werden die Zugangsdaten unlesbar, wenn die Salts erneuert werden.', 'pass-captain' ) . '</p></div>';
		}
	}

	/**
	 * Site Health → Info: status of every secret (source only, never values).
	 *
	 * @param array $info Debug information sections.
	 */
	public static function debug_information( $info ) {
		$fields = array(
			'key_source' => array( 'label' => __( 'Schlüssel', 'pass-captain' ), 'value' => 'constant' === self::key_source() ? 'WPCAP_SECRETS_KEY' : __( 'aus WordPress-Salts abgeleitet', 'pass-captain' ) ),
		);
		$labels = array( 'constant' => __( 'Konstante', 'pass-captain' ), 'env' => __( 'Umgebungsvariable', 'pass-captain' ), 'vault' => __( 'verschlüsselt gespeichert', 'pass-captain' ), 'undecryptable' => __( 'nicht entschlüsselbar', 'pass-captain' ), '' => __( 'nicht gesetzt', 'pass-captain' ) );
		foreach ( self::KEYS as $key ) {
			$fields[ $key ] = array( 'label' => self::label( $key ), 'value' => $labels[ self::source( $key ) ] ?? '', 'private' => true );
		}
		$info = is_array( $info ) ? $info : array();
		$info['pass-captain-secrets'] = array( 'label' => __( 'Pass Captain – Zugangsdaten', 'pass-captain' ), 'fields' => $fields );
		return $info;
	}
}
