<?php
/** Plugin settings. Secrets are preferably defined as constants in wp-config.php. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Settings {
	const OPTION = 'wpcap_settings';

	/**
	 * Uninstall opt-in ('delete' or 'keep'), stored outside wpcap_settings: the uninstall.php of the old
	 * "wp-pass-captain" copy (up to 1.4) reads wpcap_settings['delete_data_on_uninstall'], and on a multisite
	 * network it runs for every site, also where this copy's pre_uninstall_plugin guard is not loaded. The
	 * setting key delete_data_on_uninstall stays the interface (settings page, Settings::get()), but it is
	 * read from and written to this option; in wpcap_settings it is always 0.
	 */
	const UNINSTALL_POLICY = 'wpcap_uninstall_policy';

	/** Per-request cache of defaults() (module defaults contain translated texts). */
	private static $defaults = null;

	/** Per-request cache of all(): [stored option value, merged result]. */
	private static $all = null;

	/** Default values for every setting (core plus modules); built once per request. */
	public static function defaults() {
		if ( null === self::$defaults ) {
			$defaults = array_merge( self::core_defaults(), Modules::settings_defaults() );
			/**
			 * Add-on settings outside modules: key => default. Core and module keys cannot be replaced
			 * (only new keys are taken over). Edit them through wp_pass_captain_settings_sections_v1.
			 */
			$extra = apply_filters( 'wp_pass_captain_settings_defaults_v1', array() );
			self::$defaults = is_array( $extra ) ? $defaults + $extra : $defaults;
		}
		return self::$defaults;
	}

	/** Drops the memoized defaults/values (locale switch, text domain loaded, settings updated). */
	public static function flush() {
		self::$defaults = null;
		self::$all = null;
	}

	/** Invalidates the per-request cache when translations change. */
	public static function register() {
		add_action( 'change_locale', array( __CLASS__, 'flush' ), 0, 0 );
		add_action(
			'load_textdomain',
			static function ( $domain ) {
				if ( 'pass-captain' === $domain ) { self::flush(); }
			},
			0,
			1
		);
	}

	private static function core_defaults() {
		return array(
			'hold_minutes'              => 10,
			'manual_hold_hours'         => 72,
			'enabled_providers'         => array( 'manual' ),
			'manual_instructions'       => '',
			'bank_recipient'            => '',
			'bank_iban'                 => '',
			'bank_bic'                  => '',
			'stripe_mode'               => 'test',
			'stripe_secret_key'         => '',
			'stripe_webhook_secret'     => '',
			'paypal_mode'               => 'sandbox',
			'paypal_client_id'          => '',
			'paypal_client_secret'      => '',
			'paypal_webhook_id'         => '',
			'terms_text'                => '',
			'consumer_info_text'        => '',
			'require_terms'             => 0,
			'refund_approval_threshold' => 0,
			'require_2fa_for_refunds'   => 0,
			'attendee_retention_days'   => 0,
			'attendee_edit_hours'       => 24,
			// Privacy\Retention: order personal data after the statutory period (years from the end of
			// the order's calendar year), waitlists after the date, member passes after valid_until.
			'retention_order_years'     => 10,
			'retention_waitlist_days'   => 30,
			'retention_pass_days'       => 365,
			'seller_name'               => '',
			'seller_address'            => '',
			'seller_vat_id'             => '',
			'seller_tax_number'         => '',
			'seller_country'            => 'DE',
			'invoice_prefix'            => 'WPC',
			'billing_required_threshold_minor' => 25000,
			'credit_note_prefix'        => 'GS',
			'auto_invoice'              => 0,
			// Voucher Captain redemptions without purpose data: 'multi' (payment, VAT at redemption) or 'single'.
			'voucher_default_purpose'   => 'multi',
			'delete_data_on_uninstall'  => 0,
			'scanner_show_attendee'     => 0,
			// Signed QR payloads (PC1.…) instead of the plain ticket code in new QR codes; scanners accept both.
			'signed_qr'                 => 0,
			'device_max_hours'          => 24,
			'admission_day_cutoff_hour' => 0,
			'auto_refund_cancelled'     => 0,
			// Entry window for ticket types without their own admission rule: 2 h before start until the end.
			'default_entry_window'      => 1,
			'entry_opens_before_minutes' => 120,
			'entry_closes_after_minutes' => 0,
			// Reverse proxies / CDN whose forwarded client address is trusted (IPs or CIDRs, comma separated).
			'trusted_proxies'           => '',
			'trusted_proxy_header'      => 'x_forwarded_for',
			// Fiscal services on the local network (plain http, loopback/private addresses) are blocked unless enabled.
			'fiscal_allow_local_service' => 0,
		);
	}

	/** All settings merged with defaults. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored['delete_data_on_uninstall'] = self::deletes_data_on_uninstall() ? 1 : 0;
		// The option is re-read every time (another site, direct update_option), the merge is reused.
		if ( null === self::$all || self::$all[0] !== $stored ) {
			self::$all = array( $stored, array_merge( self::defaults(), $stored ) );
		}
		return self::$all[1];
	}

	/** One setting. */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Settings that hold provider secrets. They are stored encrypted (Security\Vault, "pcv1:" values);
	 * get()/all() return the stored ciphertext, only secret() returns the plaintext.
	 */
	const SECRET_KEYS = array(
		'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret',
		'mollie_api_key', 'twilio_auth_token', 'sms_http_secret',
		'apple_pass_cert', 'apple_pass_key', 'apple_pass_key_password', 'apple_wwdr_cert', 'google_wallet_service_account',
	);

	/** Secret setting keys (filter wpcap_secret_settings lets add-ons register their own). */
	public static function secret_keys() {
		$keys = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpcap_secret_settings', self::SECRET_KEYS ) : self::SECRET_KEYS;
		return array_values( array_unique( array_map( 'strval', $keys ) ) );
	}

	public static function is_secret( $key ) {
		return in_array( (string) $key, self::secret_keys(), true );
	}

	/** Persist already sanitised values (unknown keys are ignored); secrets are encrypted at rest. */
	public static function update( array $values ) {
		$values = array_intersect_key( $values, self::defaults() );
		if ( array_key_exists( 'delete_data_on_uninstall', $values ) ) {
			update_option( self::UNINSTALL_POLICY, empty( $values['delete_data_on_uninstall'] ) ? 'keep' : 'delete', false );
		}
		foreach ( self::secret_keys() as $key ) {
			if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$values[ $key ] = Security\Vault::encrypt( $values[ $key ], $key );
			}
		}
		$merged = array_merge( self::all(), $values );
		$merged['delete_data_on_uninstall'] = 0; // Never in the old key (see UNINSTALL_POLICY).
		update_option( self::OPTION, $merged, true ); // Autoloaded (PERF-11): read on every request; secrets inside are encrypted.
		self::$all = null;
		Security\Redact::reset();
	}

	/** Whether the operator opted in to removing all data when the plugin is deleted (uninstall.php). */
	public static function deletes_data_on_uninstall() {
		return 'delete' === get_option( self::UNINSTALL_POLICY, 'keep' );
	}

	/**
	 * Upgrade step (per site, idempotent): moves an opt-in still stored in wpcap_settings to
	 * UNINSTALL_POLICY and sets the old key to 0, so the old copy's uninstall.php keeps the data.
	 */
	public static function migrate_uninstall_policy() {
		$settings = get_option( self::OPTION, array() );
		if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
			update_option( self::UNINSTALL_POLICY, 'delete', false );
			$settings['delete_data_on_uninstall'] = 0;
			update_option( self::OPTION, $settings, true );
			self::$all = null;
		} elseif ( false === get_option( self::UNINSTALL_POLICY, false ) ) {
			update_option( self::UNINSTALL_POLICY, 'keep', false );
		}
	}

	/**
	 * Marks the settings option as autoloaded on existing installs (it was stored with autoload=no,
	 * which cost an extra query on every request). Idempotent; runs with the schema upgrade.
	 */
	public static function ensure_autoload() {
		if ( false === get_option( self::OPTION, false ) ) { return false; }
		if ( function_exists( 'wp_set_option_autoload' ) ) { return (bool) wp_set_option_autoload( self::OPTION, true ); }
		return false;
	}

	/** Name of the constant/environment variable (WPCAP_<KEY>) that overrides a key, or ''. */
	public static function override_source( $key ) {
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return $constant; }
		$env = getenv( $constant );
		return is_string( $env ) && '' !== $env ? $constant : '';
	}

	/**
	 * Secret lookup: constant (e.g. WPCAP_STRIPE_SECRET_KEY) > environment variable > stored option
	 * (decrypted). A stored value that cannot be decrypted (key lost) counts as empty.
	 *
	 * @param string $key Settings key, e.g. "stripe_secret_key".
	 */
	public static function secret( $key ) {
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return (string) constant( $constant ); }
		$env = getenv( $constant );
		if ( is_string( $env ) && '' !== $env ) { return $env; }
		$stored = self::get( $key );
		if ( ! is_string( $stored ) ) { return is_scalar( $stored ) ? (string) $stored : ''; }
		return (string) Security\Vault::decrypt( $stored, $key );
	}

	/**
	 * State of a secret for admin screens (never the value itself).
	 *
	 * @return array{state:string,hint:string,source:string} state: override|stored|undecryptable|empty.
	 */
	public static function secret_status( $key ) {
		$override = self::override_source( $key );
		if ( '' !== $override ) { return array( 'state' => 'override', 'hint' => '', 'source' => $override ); }
		$stored = self::get( $key );
		if ( ! is_string( $stored ) || '' === $stored ) { return array( 'state' => 'empty', 'hint' => '', 'source' => '' ); }
		$plain = Security\Vault::decrypt( $stored, $key );
		if ( null === $plain ) { return array( 'state' => 'undecryptable', 'hint' => '', 'source' => '' ); }
		return array( 'state' => 'stored', 'hint' => Security\Vault::mask( $plain ), 'source' => '' );
	}

	/** Version hash of the legal texts currently shown in the checkout (stored with each order). */
	public static function terms_version() {
		$text = trim( (string) self::get( 'terms_text' ) ) . "\n--\n" . trim( (string) self::get( 'consumer_info_text' ) );
		return "\n--\n" === $text ? '' : substr( hash( 'sha256', $text ), 0, 16 );
	}
}
