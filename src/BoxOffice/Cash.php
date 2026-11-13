<?php
/**
 * Cash counting helpers: denominations (notes and coins in minor units) and amount resolution.
 *
 * A count is either a plain amount or a denomination count {value_minor: quantity}; with both, they
 * must match. Denominations are filterable per currency (`wpcap_boxoffice_denominations`).
 */
namespace PassCaptain\BoxOffice;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cash {
	/** Notes and coins in minor units (EUR and CHF style); highest first. */
	public static function denominations( $currency = 'EUR' ) {
		$list = array( 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1 );
		if ( 'CHF' === strtoupper( (string) $currency ) ) { $list = array( 100000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5 ); }
		$list = array_values( array_unique( array_filter( array_map( 'absint', (array) apply_filters( 'wpcap_boxoffice_denominations', $list, $currency ) ) ) ) );
		rsort( $list );
		return $list;
	}

	/** Display label of a denomination ("50,00" / "0,20"). */
	public static function label( $minor ) {
		return number_format_i18n( absint( $minor ) / 100, 2 );
	}

	/**
	 * Normalises a denomination count.
	 *
	 * @param mixed $counts {value_minor: quantity}; empty quantities are dropped.
	 * @return array|\WP_Error [value_minor => quantity] ordered highest first.
	 */
	public static function normalize( $counts, $currency = 'EUR' ) {
		if ( ! is_array( $counts ) ) { return array(); }
		$allowed = self::denominations( $currency );
		$out = array();
		foreach ( $counts as $value => $qty ) {
			if ( ! is_scalar( $qty ) || '' === trim( (string) $qty ) ) { continue; }
			if ( ! preg_match( '/^\d+$/', trim( (string) $qty ) ) ) {
				return new \WP_Error( 'wpcap_pos_denomination', __( 'Bitte bei der Stückelung nur ganze Stückzahlen eintragen.', 'pass-captain' ) );
			}
			$qty = (int) $qty;
			$value = absint( $value );
			if ( ! in_array( $value, $allowed, true ) ) {
				return new \WP_Error( 'wpcap_pos_denomination', __( 'Unbekannte Stückelung.', 'pass-captain' ) );
			}
			if ( $qty > 0 ) { $out[ $value ] = $qty; }
		}
		krsort( $out );
		return $out;
	}

	public static function sum( array $counts ) {
		$total = 0;
		foreach ( $counts as $value => $qty ) { $total += absint( $value ) * absint( $qty ); }
		return $total;
	}

	/**
	 * Resolves a counted amount from an amount and/or a denomination count.
	 *
	 * @param int|null   $amount_minor Plain amount (null = not given).
	 * @param array|null $denominations Denomination count (null/empty = not given).
	 * @return array|\WP_Error [amount_minor, denominations]
	 */
	public static function resolve( $amount_minor, $denominations, $currency = 'EUR' ) {
		$counts = self::normalize( $denominations, $currency );
		if ( is_wp_error( $counts ) ) { return $counts; }
		if ( $counts ) {
			$sum = self::sum( $counts );
			if ( null !== $amount_minor && '' !== $amount_minor && (int) $amount_minor !== $sum ) {
				return new \WP_Error( 'wpcap_pos_denomination_sum', __( 'Der Betrag stimmt nicht mit der Summe der Stückelung überein.', 'pass-captain' ) );
			}
			return array( $sum, $counts );
		}
		if ( null === $amount_minor || '' === $amount_minor ) {
			return new \WP_Error( 'wpcap_pos_amount', __( 'Bitte den gezählten Betrag oder die Stückelung angeben.', 'pass-captain' ) );
		}
		if ( (int) $amount_minor < 0 ) { return new \WP_Error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ); }
		return array( (int) $amount_minor, array() );
	}
}
