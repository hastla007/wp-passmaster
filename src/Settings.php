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
			'campaign_max_codes'        => 3,
			'campaign_stacking'         => 'cumulative',
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
			// Legal texts of further languages: locale => [terms_text, consumer_info_text]. The plain
			// terms_text / consumer_info_text keys hold the texts of the default language (legal_default_locale()).
			'legal_texts'               => array(),
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

	/** Keys of the legal texts that exist per language. */
	const LEGAL_KEYS = array( 'terms_text', 'consumer_info_text' );

	/**
	 * Locale of the default-language legal texts (the plain terms_text / consumer_info_text keys):
	 * Polylang's or WPML's default language, otherwise the site locale. Filter: wpcap_legal_default_locale.
	 */
	public static function legal_default_locale() {
		$locale = '';
		if ( function_exists( 'pll_default_language' ) ) {
			$locale = (string) pll_default_language( 'locale' );
		}
		$code = '' === $locale ? (string) apply_filters( 'wpml_default_language', '' ) : '';
		if ( '' !== $code ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $language ) {
				if ( is_array( $language ) && $code === (string) ( $language['code'] ?? '' ) ) { $locale = (string) ( $language['default_locale'] ?? '' ); }
			}
		}
		if ( '' === $locale ) { $locale = Mail\Mailer::site_locale(); }
		$locale = Mail\Mailer::normalize_locale( (string) apply_filters( 'wpcap_legal_default_locale', $locale ) );
		return '' !== $locale ? $locale : 'de_DE';
	}

	/**
	 * Locales offered for legal texts: the default locale first, then the languages of Polylang/WPML,
	 * installed language packs and locales that already have texts. Filter: wpcap_legal_locales.
	 *
	 * @return string[]
	 */
	public static function legal_locales() {
		$default = self::legal_default_locale();
		$list = array( $default );
		if ( function_exists( 'pll_languages_list' ) ) {
			$list = array_merge( $list, (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
		}
		foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $language ) {
			if ( is_array( $language ) && ! empty( $language['default_locale'] ) ) { $list[] = (string) $language['default_locale']; }
		}
		if ( function_exists( 'get_available_languages' ) ) {
			$list = array_merge( $list, (array) get_available_languages() );
		}
		$list = array_merge( $list, array_map( 'strval', array_keys( (array) self::get( 'legal_texts' ) ) ) );
		$list = (array) apply_filters( 'wpcap_legal_locales', $list, $default );
		$out = array( $default );
		foreach ( $list as $locale ) {
			$locale = Mail\Mailer::normalize_locale( is_string( $locale ) ? $locale : '' );
			if ( '' !== $locale && ! in_array( $locale, $out, true ) ) { $out[] = $locale; }
		}
		return $out;
	}

	/** Stored texts of the further languages: locale => [key => text] (only non-empty texts). */
	public static function legal_translations() {
		$out = array();
		foreach ( (array) self::get( 'legal_texts' ) as $locale => $texts ) {
			$locale = Mail\Mailer::normalize_locale( (string) $locale );
			if ( '' === $locale || ! is_array( $texts ) ) { continue; }
			foreach ( self::LEGAL_KEYS as $key ) {
				if ( isset( $texts[ $key ] ) && is_scalar( $texts[ $key ] ) && '' !== trim( (string) $texts[ $key ] ) ) { $out[ $locale ][ $key ] = (string) $texts[ $key ]; }
			}
		}
		return $out;
	}

	/**
	 * Locale whose stored text is used for $key when a buyer uses $locale: the exact locale, else
	 * another locale of the same language (en_GB -> en_US), else the default language.
	 *
	 * @param string      $key    terms_text or consumer_info_text.
	 * @param string|null $locale Buyer locale; null = determine_locale().
	 */
	public static function legal_source_locale( $key, $locale = null ) {
		$default = self::legal_default_locale();
		$locale = Mail\Mailer::normalize_locale( null === $locale ? Mail\Mailer::current_locale() : (string) $locale );
		if ( '' === $locale || $locale === $default ) { return $default; }
		$translations = self::legal_translations();
		if ( isset( $translations[ $locale ][ $key ] ) ) { return $locale; }
		$language = strtok( $locale, '_' );
		// Same language as the default (de_AT with default de_DE): the default text is the right one.
		if ( strtok( $default, '_' ) === $language ) { return $default; }
		foreach ( $translations as $candidate => $texts ) {
			if ( isset( $texts[ $key ] ) && strtok( (string) $candidate, '_' ) === $language ) { return (string) $candidate; }
		}
		return $default;
	}

	/**
	 * Legal text for a buyer locale with fallback to the default language (see legal_source_locale()).
	 *
	 * @param string      $key    terms_text or consumer_info_text.
	 * @param string|null $locale Buyer locale; null = determine_locale().
	 */
	public static function legal_text( $key, $locale = null ) {
		if ( ! in_array( $key, self::LEGAL_KEYS, true ) ) { return ''; }
		$source = self::legal_source_locale( $key, $locale );
		if ( $source === self::legal_default_locale() ) { return (string) self::get( $key ); }
		return self::legal_translations()[ $source ][ $key ] ?? (string) self::get( $key );
	}

	/**
	 * Version hash of the legal texts a buyer of $locale is shown in the checkout (stored with each
	 * order). Hashes exactly the texts legal_text() returns, so the default language keeps the
	 * hash it had before texts per language existed.
	 *
	 * @param string|null $locale Buyer locale; null = determine_locale().
	 */
	public static function terms_version( $locale = null ) {
		$text = trim( self::legal_text( 'terms_text', $locale ) ) . "\n--\n" . trim( self::legal_text( 'consumer_info_text', $locale ) );
		return "\n--\n" === $text ? '' : substr( hash( 'sha256', $text ), 0, 16 );
	}
}
