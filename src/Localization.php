<?php
/** Currency and locale helpers; tax and legal rules stay country-specific. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Localization {
	public static function currencies() {
		$currencies = apply_filters( 'wpcap_currencies', array( 'EUR', 'USD', 'CHF', 'GBP', 'CAD', 'AUD' ) );
		$currencies = Hooks::legacy_filter( 'wpcap_currencies', $currencies );
		if ( ! is_array( $currencies ) ) { return array( 'EUR' ); }
		$currencies = array_unique( array_filter( array_map( static function ( $code ) { $code = strtoupper( sanitize_text_field( $code ) ); return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : ''; }, $currencies ) ) );
		return $currencies ?: array( 'EUR' );
	}

	public static function current_locale() { return determine_locale(); }

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
