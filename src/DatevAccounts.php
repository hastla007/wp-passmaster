<?php
/**
 * DATEV accounts of the booking batch (SET-PC-37): the chart (datev_chart, SKR03/SKR04) proposes the
 * accounts; the settings screen edits them as one table. Stored keys:
 *   datev_contra_account – money account of all payments ('' = Geldtransit of the chart), as before;
 *   datev_accounts       – own accounts: revenue (VAT rate in basis points => account), bu_keys (rate =>
 *                          BU key), revenue_default (account of all other rates), contra_by_provider
 *                          (payment provider => money account). Empty = the chart (former behaviour).
 * The filter wpcap_datev_accounts keeps the last word (Invoices::datev_accounts()).
 *
 * The account numbers of the charts are the ones the export used before; which accounts fit an
 * operator is a question for the tax adviser.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DatevAccounts {
	/**
	 * Accounts of a chart (the former fixed values).
	 *
	 * @return array{revenue: array<int,string>, revenue_default: string, contra: string}
	 */
	public static function chart_defaults( $chart ) {
		$skr04 = 'skr04' === (string) $chart;
		return array(
			'revenue' => $skr04 ? array( 1900 => '4400', 700 => '4300' ) : array( 1900 => '8400', 700 => '8300' ),
			'revenue_default' => $skr04 ? '4200' : '8200',
			'contra' => $skr04 ? '1460' : '1360',
		);
	}

	/**
	 * Stored own accounts in the canonical form; anything malformed is left out (reading never fails).
	 *
	 * @return array{revenue: array<int,string>, bu_keys: array<int,string>, revenue_default: string, contra_by_provider: array<string,string>}
	 */
	public static function normalize( array $stored ) {
		$out = array( 'revenue' => array(), 'bu_keys' => array(), 'revenue_default' => '', 'contra_by_provider' => array() );
		foreach ( array( 'revenue' => 'account', 'bu_keys' => 'bu' ) as $map => $kind ) {
			foreach ( (array) ( $stored[ $map ] ?? array() ) as $rate => $value ) {
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( is_numeric( $rate ) && (int) $rate >= 0 && (int) $rate <= 10000 && self::valid( $kind, $value ) ) { $out[ $map ][ (int) $rate ] = $value; }
			}
			ksort( $out[ $map ] );
		}
		$default = is_scalar( $stored['revenue_default'] ?? null ) ? trim( (string) $stored['revenue_default'] ) : '';
		$out['revenue_default'] = self::valid( 'account', $default ) ? $default : '';
		foreach ( (array) ( $stored['contra_by_provider'] ?? array() ) as $provider => $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			$provider = sanitize_key( (string) $provider );
			if ( '' !== $provider && self::valid( 'account', $value ) ) { $out['contra_by_provider'][ $provider ] = $value; }
		}
		ksort( $out['contra_by_provider'] );
		return $out;
	}

	/** Account: 4 to 8 digits; BU key: 1 to 4 digits. */
	private static function valid( $kind, $value ) {
		return (bool) preg_match( 'bu' === $kind ? '/^\d{1,4}$/' : '/^\d{4,8}$/', (string) $value );
	}

	/**
	 * Settings register: checks the table (form rows) or the canonical form (import, WP-CLI) and returns
	 * the canonical form. Reports every invalid cell instead of dropping it.
	 *
	 * @param mixed $value Submitted value.
	 * @return array|\WP_Error
	 */
	public static function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'wpcap_datev_accounts', __( '„DATEV-Konten“ erwartet eine Liste von Werten.', 'pass-captain' ) );
		}
		$errors = array();
		$out = array( 'revenue' => array(), 'bu_keys' => array(), 'revenue_default' => '', 'contra_by_provider' => array() );
		$account = static function ( $raw, $label ) use ( &$errors ) {
			$raw = is_scalar( $raw ) ? preg_replace( '/\s+/', '', (string) $raw ) : '';
			if ( '' !== $raw && ! preg_match( '/^\d{4,8}$/', $raw ) ) {
				/* translators: %s: row of the DATEV table, e.g. "Erlöskonto 19 %" */
				$errors[] = sprintf( __( '%s: Das Konto muss 4 bis 8 Ziffern haben.', 'pass-captain' ), $label );
				return '';
			}
			return $raw;
		};
		// Form rows: rates[i] = {rate, account, bu}.
		$rows = array();
		foreach ( (array) ( $value['rates'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) { $rows[] = $row; }
		}
		// Canonical form: revenue / bu_keys keyed by basis points.
		foreach ( array( 'revenue' => 'account', 'bu_keys' => 'bu' ) as $map => $field ) {
			foreach ( (array) ( $value[ $map ] ?? array() ) as $rate_bp => $cell ) {
				$rows[] = array( 'rate_bp' => $rate_bp, $field => $cell );
			}
		}
		foreach ( $rows as $row ) {
			$cell_account = is_scalar( $row['account'] ?? null ) ? trim( (string) $row['account'] ) : '';
			$cell_bu = is_scalar( $row['bu'] ?? null ) ? preg_replace( '/\s+/', '', (string) $row['bu'] ) : '';
			if ( '' === $cell_account && '' === $cell_bu ) { continue; }
			if ( array_key_exists( 'rate_bp', $row ) ) {
				$rate_bp = is_numeric( $row['rate_bp'] ) && (int) $row['rate_bp'] >= 0 && (int) $row['rate_bp'] <= 10000 ? (int) $row['rate_bp'] : null;
				$rate_label = is_scalar( $row['rate_bp'] ) ? (string) $row['rate_bp'] : '?';
			} else {
				$rate_text = is_scalar( $row['rate'] ?? null ) ? trim( (string) $row['rate'] ) : '';
				$rate_bp = '' === $rate_text ? null : Admin\Forms::parse_percent_bp( $rate_text );
				$rate_bp = null !== $rate_bp && $rate_bp >= 0 && $rate_bp <= 10000 ? (int) $rate_bp : null;
				$rate_label = $rate_text . ' %';
			}
			if ( null === $rate_bp ) {
				/* translators: %s: entered VAT rate */
				$errors[] = sprintf( __( 'DATEV-Konten: „%s“ ist kein gültiger Steuersatz (0 bis 100).', 'pass-captain' ), $rate_label );
				continue;
			}
			/* translators: %s: VAT rate */
			$label = sprintf( __( 'Erlöskonto %s', 'pass-captain' ), Admin\Forms::percent_input( $rate_bp ) . ' %' );
			$checked = $account( $cell_account, $label );
			if ( '' !== $checked ) { $out['revenue'][ $rate_bp ] = $checked; }
			if ( '' !== $cell_bu ) {
				if ( preg_match( '/^\d{1,4}$/', $cell_bu ) ) {
					$out['bu_keys'][ $rate_bp ] = $cell_bu;
				} else {
					/* translators: %s: VAT rate */
					$errors[] = sprintf( __( 'BU-Schlüssel %s: 1 bis 4 Ziffern.', 'pass-captain' ), Admin\Forms::percent_input( $rate_bp ) . ' %' );
				}
			}
		}
		$out['revenue_default'] = $account( $value['revenue_default'] ?? '', __( 'Erlöskonto für alle übrigen Sätze', 'pass-captain' ) );
		foreach ( (array) ( $value['contra_by_provider'] ?? array() ) as $provider => $cell ) {
			$provider = sanitize_key( (string) $provider );
			if ( '' === $provider ) { continue; }
			/* translators: %s: payment method */
			$checked = $account( $cell, sprintf( __( 'Geldkonto %s', 'pass-captain' ), $provider ) );
			if ( '' !== $checked ) { $out['contra_by_provider'][ $provider ] = $checked; }
		}
		if ( $errors ) { return new \WP_Error( 'wpcap_datev_accounts', implode( ' ', $errors ), array( 'messages' => $errors ) ); }
		ksort( $out['revenue'] );
		ksort( $out['bu_keys'] );
		ksort( $out['contra_by_provider'] );
		// Only the parts that were submitted (WP-CLI and import merge a part into the stored value); the table
		// of the settings screen submits every part, so an emptied table stores nothing of its own.
		$table = isset( $value['rates'] );
		$parts = array_filter(
			array(
				'revenue' => $table || isset( $value['revenue'] ),
				'bu_keys' => $table || isset( $value['bu_keys'] ),
				'revenue_default' => array_key_exists( 'revenue_default', $value ),
				'contra_by_provider' => isset( $value['contra_by_provider'] ),
			)
		);
		$out = array_intersect_key( $out, $parts );
		$own = array_filter( $out, static function ( $part ) { return array() !== $part && '' !== $part; } );
		return $table && ! $own ? array() : $out;
	}

	/**
	 * Settings register: money account '' or 4 to 8 digits (the export used to ignore other values silently).
	 *
	 * @return string|\WP_Error
	 */
	public static function sanitize_contra( $value ) {
		$value = is_scalar( $value ) ? preg_replace( '/\s+/', '', (string) $value ) : '';
		if ( '' !== $value && ! preg_match( '/^\d{4,8}$/', $value ) ) {
			return new \WP_Error( 'wpcap_datev_contra', __( 'Das Geldkonto muss 4 bis 8 Ziffern haben (leer = Geldtransit des Kontenrahmens).', 'pass-captain' ) );
		}
		return $value;
	}

	/**
	 * Accounts of the booking batch before the filter: chart, money account and own accounts.
	 *
	 * @return array{revenue: array<int,string>, revenue_default: string, bu_keys: array<int,string>, contra: string, contra_by_provider: array<string,string>}
	 */
	public static function effective() {
		$base = self::chart_defaults( (string) Settings::get( 'datev_chart', 'skr03' ) );
		$contra = preg_replace( '/\D/', '', (string) Settings::get( 'datev_contra_account', '' ) );
		$own = self::normalize( (array) Settings::get( 'datev_accounts', array() ) );
		return array(
			'revenue' => array_replace( $base['revenue'], $own['revenue'] ),
			'revenue_default' => '' !== $own['revenue_default'] ? $own['revenue_default'] : $base['revenue_default'],
			'bu_keys' => $own['bu_keys'],
			'contra' => strlen( $contra ) >= 4 && strlen( $contra ) <= 8 ? $contra : $base['contra'],
			'contra_by_provider' => $own['contra_by_provider'],
		);
	}
}
