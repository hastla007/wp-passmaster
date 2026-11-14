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
		$currency = strtoupper( (string) $currency );
		if ( in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ) { return 0; }
		return in_array( $currency, self::THREE_DECIMAL_CURRENCIES, true ) ? 3 : 2;
	}

	/** Localised number for an amount in minor units ("1.234,50", "1.500" for JPY, "12,345" for KWD). */
	public static function money_number( $minor, $currency ) {
		$decimals = self::currency_decimals( $currency );
		return number_format_i18n( (int) $minor / ( 10 ** $decimals ), $decimals );
	}

	public static function currencies() {
		$currencies = apply_filters( 'wp_pass_captain_currencies', array( 'EUR', 'USD', 'CHF', 'GBP', 'CAD', 'AUD' ) );
		if ( ! is_array( $currencies ) ) { return array( 'EUR' ); }
		$currencies = array_unique( array_filter( array_map( static function ( $code ) { $code = strtoupper( sanitize_text_field( $code ) ); return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : ''; }, $currencies ) ) );
		return $currencies ?: array( 'EUR' );
	}

	public static function current_locale() { return determine_locale(); }
}
