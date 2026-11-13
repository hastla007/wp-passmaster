<?php
/**
 * Plugin settings (option wpcap_settings). Provider secrets (Secrets::KEYS) are never stored here:
 * Settings::update() routes them into the encrypted store (Secrets), all()/get() return their
 * empty defaults. Read secrets with Secrets::get().
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Settings {
	const OPTION = 'wpcap_settings';

	/** Per-request cache of defaults() (module defaults contain translated texts). */
	private static $defaults = null;

	/** Per-request cache of all(): [stored option value, merged result]. */
	private static $all = null;

	/** Default values for every setting (core plus modules); built once per request. */
	public static function defaults() {
		if ( null === self::$defaults ) {
			self::$defaults = array_merge( self::core_defaults(), Modules::settings_defaults() );
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
				if ( 'wp-pass-captain' === $domain ) { self::flush(); }
			},
			0,
			1
		);
	}

	private static function core_defaults() {
		return array(
			'hold_minutes'              => 10,
			'manual_hold_hours'         => 72,
			'manual_cutoff_hours'       => 0,
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
			'seller_name'               => '',
			'seller_address'            => '',
			'seller_vat_id'             => '',
			'seller_tax_number'         => '',
			'seller_country'            => 'DE',
			// Platform operator is a small business (DE § 19 UStG / AT § 6 Abs. 1 Z 27 UStG): no VAT on its invoices.
			'seller_small_business'     => 0,
			'seller_small_business_note' => '',
			'invoice_prefix'            => 'WPC',
			'billing_required_threshold_minor' => 25000,
			'credit_note_prefix'        => 'RK', // Rechnungskorrektur; installs from before schema 3.8.3 keep 'GS' (Installer).
			'auto_invoice'              => 0,
			// Voucher Captain redemptions without purpose data: 'multi' (payment, VAT at redemption) or 'single'.
			'voucher_default_purpose'   => 'multi',
			'delete_data_on_uninstall'  => 0,
			'scanner_show_attendee'     => 0,
			'device_max_hours'          => 24,
			'admission_day_cutoff_hour' => 0,
			'auto_refund_cancelled'     => 0,
			// Entry window for ticket types without their own admission rule: 2 h before start until the end.
			'default_entry_window'      => 1,
			'entry_opens_before_minutes' => 120,
			'entry_closes_after_minutes' => 0,
		);
	}

	/** All settings merged with defaults. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		// Secrets are never exposed through the general settings (legacy plaintext before migration included).
		$stored = is_array( $stored ) ? array_diff_key( $stored, array_flip( Secrets::KEYS ) ) : array();
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
	 * Persist already sanitised values (unknown keys are ignored). Secret keys are encrypted into
	 * the credential store ('' removes them) and never written to wpcap_settings.
	 */
	public static function update( array $values ) {
		$values = array_intersect_key( $values, self::defaults() );
		// Plaintext from before the vault would otherwise be dropped below without being moved.
		if ( Secrets::migration_pending() ) { Secrets::migrate(); }
		foreach ( Secrets::KEYS as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				Secrets::set( $key, is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '' );
				unset( $values[ $key ] );
			}
		}
		$stored = array_diff_key( self::all(), array_flip( Secrets::KEYS ) );
		update_option( self::OPTION, array_merge( $stored, $values ), false );
		self::$all = null;
	}

	/**
	 * Lookup for values that may be overridden: constant (e.g. WPCAP_PAYPAL_CLIENT_ID) > environment
	 * variable > stored option. Secrets (Secrets::KEYS) are delegated to Secrets::get().
	 *
	 * @param string $key Settings key, e.g. "paypal_client_id".
	 */
	public static function secret( $key ) {
		if ( Secrets::is_secret( $key ) ) { return Secrets::get( $key ); }
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return (string) constant( $constant ); }
		$env = getenv( $constant );
		if ( is_string( $env ) && '' !== $env ) { return $env; }
		return (string) self::get( $key );
	}

	/** Version hash of the legal texts currently shown in the checkout (stored with each order). */
	public static function terms_version() {
		$text = trim( (string) self::get( 'terms_text' ) ) . "\n--\n" . trim( (string) self::get( 'consumer_info_text' ) );
		return "\n--\n" === $text ? '' : substr( hash( 'sha256', $text ), 0, 16 );
	}
}
