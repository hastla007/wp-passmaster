<?php
/**
 * Minor units per currency (ISO 4217) and the conversions used wherever amounts are shown or
 * exported. Amounts are stored as integers in the currency's minor unit (EUR cents, JPY yen,
 * KWD fils = 1/1000); only these helpers turn them into decimal numbers, so exports, reports,
 * fiscal documents, mails and PDFs never assume "/100".
 * Rounding rules: docs/OPERATIONS.md, section "Geldbeträge, Rundung und Währungen".
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Money {
	/** ISO 4217 currencies without minor unit. */
	const ZERO_DECIMALS = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/** ISO 4217 currencies with three decimals. */
	const THREE_DECIMALS = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/** Decimals of the currency's minor unit (0–4; default 2). Filter `wpcap_currency_decimals`. */
	public static function decimals( $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		$decimals = in_array( $currency, self::ZERO_DECIMALS, true ) ? 0 : ( in_array( $currency, self::THREE_DECIMALS, true ) ? 3 : 2 );
		/**
		 * Number of decimals of a currency's minor unit (stored amounts are integers in that unit).
		 * Only change it for currencies the ISO list does not cover, never for currencies with data.
		 *
		 * @param int    $decimals ISO 4217 minor unit digits (0, 2 or 3).
		 * @param string $currency Upper-case currency code.
		 */
		return max( 0, min( 4, (int) apply_filters( 'wpcap_currency_decimals', $decimals, $currency ) ) );
	}

	/** Minor units per major unit (100 for EUR, 1 for JPY, 1000 for KWD). */
	public static function factor( $currency ) {
		return 10 ** self::decimals( $currency );
	}

	/**
	 * Exact decimal string of a minor amount without thousands separator, e.g. "-1234.50",
	 * "1500" (JPY), "12.345" (KWD). Integer math only; for CSV, DATEV, fiscal and API payloads.
	 */
	public static function to_decimal( $minor, $currency, $decimal_point = '.' ) {
		$minor = (int) $minor;
		$decimals = self::decimals( $currency );
		$sign = $minor < 0 ? '-' : '';
		$abs = (string) abs( $minor );
		if ( ! $decimals ) { return $sign . $abs; }
		$abs = str_pad( $abs, $decimals + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $abs, 0, -$decimals ) . $decimal_point . substr( $abs, -$decimals );
	}

	/**
	 * Minor units of a decimal string as written by to_decimal() ("-12.34", "1500", "12.345"; the
	 * comma is accepted as decimal point). Exact; more decimals than the currency has → null.
	 *
	 * @return int|null
	 */
	public static function to_minor( $value, $currency ) {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m ) ) { return null; }
		$decimals = self::decimals( $currency );
		$fraction = $m[3] ?? '';
		if ( strlen( $fraction ) > $decimals ) {
			if ( '' !== trim( substr( $fraction, $decimals ), '0' ) ) { return null; }
			$fraction = substr( $fraction, 0, $decimals );
		}
		$minor = (int) $m[2] * self::factor( $currency ) + (int) str_pad( $fraction, $decimals, '0' );
		return '-' === $m[1] ? -$minor : $minor;
	}

	/** Localised number without currency, e.g. "1.234,50" / "1.500" (JPY) / "12,345" (KWD). */
	public static function number( $minor, $currency ) {
		$decimals = self::decimals( $currency );
		return number_format_i18n( (int) $minor / ( 10 ** $decimals ), $decimals );
	}

	/** Localised amount with the currency code, e.g. "1.234,50 EUR". */
	public static function format( $minor, $currency ) {
		return self::number( $minor, $currency ) . ' ' . strtoupper( (string) $currency );
	}
}
