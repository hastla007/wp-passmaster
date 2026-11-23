<?php
/** Currency and locale helpers; tax and legal rules stay country-specific. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Localization {
	/** ISO 4217 currencies without minor unit. */
	const ZERO_DECIMAL_CURRENCIES = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/** ISO 4217 currencies with three minor-unit digits. */
	const THREE_DECIMAL_CURRENCIES = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/** Minor-unit digits of a currency (amounts are stored as integers in this unit). */
	public static function currency_decimals( $currency ) {
		return Money::decimals( $currency ); // One source (filter wpcap_currency_decimals), same lists as above.
	}

	/** Localised number for an amount in minor units ("1.234,50", "1.500" for JPY, "12,345" for KWD). */
	public static function money_number( $minor, $currency ) {
		$decimals = self::currency_decimals( $currency );
		return number_format_i18n( (int) $minor / ( 10 ** $decimals ), $decimals );
	}

	/**
	 * Machine-readable decimal for an amount in minor units (CSV exports, provider APIs): dot as
	 * separator, no grouping, exactly the currency's minor-unit digits ("1234.50" EUR, "1500" JPY,
	 * "12.345" KWD). Integer arithmetic, so large amounts are exact.
	 */
	public static function money_decimal( $minor, $currency ) {
		$decimals = self::currency_decimals( $currency );
		$minor = (int) $minor;
		$sign = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( 0 === $decimals ) { return $sign . $digits; }
		$digits = str_pad( $digits, $decimals + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
	}

	/** Built-in currency list (setting "currencies" empty). */
	const DEFAULT_CURRENCIES = array( 'EUR', 'USD', 'CHF', 'GBP', 'CAD', 'AUD' );

	/**
	 * Offered currencies: the setting "currencies" (SET-PC-08) or the built-in list, the default currency
	 * always included; the filters keep the last word.
	 */
	public static function currencies() {
		$configured = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) Settings::get( 'currencies', '' ) ) ) ) ) );
		$base = $configured ? $configured : self::DEFAULT_CURRENCIES;
		$default = strtoupper( (string) Settings::get( 'default_currency', 'EUR' ) );
		if ( preg_match( '/^[A-Z]{3}$/', $default ) && ! in_array( $default, $base, true ) ) { array_unshift( $base, $default ); }
		$currencies = apply_filters( 'wpcap_currencies', $base );
		// Versioned contract wp_pass_captain_currencies_v1; the unversioned name still fires (deprecated since
		// 1.5.0, through Hooks::legacy_filter() only when a callback is registered on it).
		$currencies = apply_filters( 'wp_pass_captain_currencies_v1', $currencies );
		$currencies = Hooks::legacy_filter( 'wpcap_currencies', $currencies );
		if ( ! is_array( $currencies ) ) { return array( 'EUR' ); }
		$currencies = array_unique( array_filter( array_map( static function ( $code ) { $code = strtoupper( sanitize_text_field( $code ) ); return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : ''; }, $currencies ) ) );
		return $currencies ?: array( 'EUR' );
	}

	/**
	 * Currency of new prices (ticket types, registers, discount codes, SMS costs): the setting
	 * "default_currency" when it is offered, else the first offered currency (SET-PC-08).
	 */
	public static function default_currency() {
		$currencies = self::currencies();
		$default = strtoupper( (string) Settings::get( 'default_currency', 'EUR' ) );
		return in_array( $default, $currencies, true ) ? $default : (string) reset( $currencies );
	}

	public static function current_locale() { return determine_locale(); }

	/** Native names of the locales the plugin ships translations for. */
	const LOCALE_NAMES = array(
		'de_DE' => 'Deutsch',
		'de_DE_formal' => 'Deutsch (Sie)',
		'en_US' => 'English',
		'es_ES' => 'Español',
		'fr_FR' => 'Français',
		'it_IT' => 'Italiano',
		'nl_NL' => 'Nederlands',
	);

	/**
	 * Locales a buyer can choose (source language de_DE plus every shipped catalogue in languages/),
	 * locale => native name. Filter wpcap_buyer_locales.
	 *
	 * @return array<string,string>
	 */
	public static function supported_locales() {
		$locales = array( 'de_DE' => self::LOCALE_NAMES['de_DE'] );
		foreach ( (array) glob( WPCAP_PATH . 'languages/pass-captain-*.mo' ) as $file ) {
			if ( preg_match( '/pass-captain-([a-z]{2,3}_[A-Z]{2}(?:_formal)?)\.mo$/', (string) $file, $m ) ) {
				$locales[ $m[1] ] = self::LOCALE_NAMES[ $m[1] ] ?? $m[1];
			}
		}
		$filtered = apply_filters( 'wpcap_buyer_locales', $locales );
		return is_array( $filtered ) && $filtered ? $filtered : $locales;
	}

	/**
	 * Supported locale or '' (accepts de_DE, de-DE, de). A regional variant without its own catalogue maps to
	 * the supported locale of the same language (en_GB -> en_US, as the legal texts do, A099).
	 */
	public static function normalize_buyer_locale( $requested ) {
		$requested = is_scalar( $requested ) ? str_replace( '-', '_', trim( (string) $requested ) ) : '';
		if ( '' === $requested ) { return ''; }
		$supported = self::supported_locales();
		if ( isset( $supported[ $requested ] ) ) { return $requested; }
		if ( ! preg_match( '/^([a-z]{2,3})(?:_[A-Za-z0-9]{2,8})?$/i', $requested, $lang ) ) { return ''; }
		foreach ( array_keys( $supported ) as $locale ) {
			if ( 0 === strcasecmp( strtok( (string) $locale, '_' ), $lang[1] ) ) { return (string) $locale; }
		}
		return '';
	}

	/** Locale stored on a new order: the buyer's explicit choice when supported, else the request locale. */
	public static function order_locale( $requested = '' ) {
		$chosen = self::normalize_buyer_locale( $requested );
		if ( '' !== $chosen ) { return $chosen; }
		return function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
	}

	/** @var array<string, \Collator|false> Collators per locale (false: intl unavailable). */
	private static $collators = array();

	/**
	 * A520: compares two texts for display order in the site language: "Äpfel" next to "Apfel",
	 * "zoe" with "Zoe", numbers in natural order ("Block 2" before "Block 10"). Uses ICU (intl) when
	 * available, otherwise a case- and accent-insensitive natural comparison.
	 *
	 * @param string      $a      First text.
	 * @param string      $b      Second text.
	 * @param string|null $locale Locale (default: site locale).
	 */
	public static function compare( $a, $b, $locale = null ) {
		$locale = null !== $locale ? (string) $locale : Mail\Mailer::site_locale();
		if ( ! array_key_exists( $locale, self::$collators ) ) {
			self::$collators[ $locale ] = false;
			if ( class_exists( '\Collator' ) ) {
				$collator = new \Collator( $locale );
				$collator->setAttribute( \Collator::NUMERIC_COLLATION, \Collator::ON );
				self::$collators[ $locale ] = $collator;
			}
		}
		$collator = self::$collators[ $locale ];
		if ( $collator ) {
			$result = $collator->compare( (string) $a, (string) $b );
			if ( false !== $result ) { return (int) $result; }
		}
		return strnatcasecmp( self::fold( (string) $a ), self::fold( (string) $b ) );
	}

	/** Text without accents (fallback of compare()). */
	private static function fold( $text ) {
		if ( function_exists( 'remove_accents' ) ) { return (string) remove_accents( $text ); }
		$ascii = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'ASCII//TRANSLIT', $text ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input falls back below.
		return false !== $ascii ? $ascii : $text;
	}

	/** Directory of the bundled catalogues (.mo, and the script translations .json built by build/release.sh). */
	public static function languages_dir() { return WPCAP_PATH . 'languages'; }

	/**
	 * A382: makes the bundled catalogue (languages/pass-captain-<locale>.mo) the fallback of the
	 * just-in-time loader instead of loading it eagerly per load_plugin_textdomain – Plugin Check flags
	 * that, WordPress.org loads language packs on demand. WordPress looks in wp-content/languages/plugins
	 * first, so a language pack from translate.wordpress.org wins; only without one the bundled file is
	 * used. Nothing is loaded here – the catalogue comes with the first translated string, as for every
	 * plugin from WordPress.org. Returns false when the registry is missing (WordPress before 6.1).
	 */
	public static function register_textdomain() {
		global $wp_textdomain_registry;
		if ( ! is_object( $wp_textdomain_registry ) || ! method_exists( $wp_textdomain_registry, 'set_custom_path' ) ) { return false; }
		$wp_textdomain_registry->set_custom_path( 'pass-captain', self::languages_dir() );
		return true;
	}

	/**
	 * A382: drops the loaded plugin catalogue so the just-in-time loader reads it again for the current
	 * locale on the next translated string (language pack first, then the bundled file).
	 */
	public static function reload_textdomain() {
		if ( function_exists( 'unload_textdomain' ) ) { unload_textdomain( 'pass-captain', true ); }
		self::register_textdomain();
	}

	/**
	 * A275: lets WordPress load the bundled JED translations (languages/pass-captain-<locale>-<md5>.json)
	 * for the editor scripts of a block registered from block.json. WordPress itself only looks in
	 * wp-content/languages/plugins (language packs); those still win when present.
	 *
	 * @param \WP_Block_Type|false $block Result of register_block_type().
	 */
	public static function block_translations( $block ) {
		if ( ! $block || ! function_exists( 'wp_set_script_translations' ) ) { return; }
		foreach ( array_merge( (array) ( $block->editor_script_handles ?? array() ), (array) ( $block->view_script_handles ?? array() ) ) as $handle ) {
			wp_set_script_translations( (string) $handle, 'pass-captain', self::languages_dir() );
		}
	}
}
