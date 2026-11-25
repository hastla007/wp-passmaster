<?php
/**
 * Tax summaries for the tax adviser (TODO L911): two CSV exports on the reports page (export types of
 * Reports\Exports, capability wpcap_view_finance, whole site only).
 *
 * - `tax_oss` ("Steuerauswertung nach Land und Satz (OSS)"): the accounting ledger
 *   (Invoices::ledger_export_rows(): payments, refunds, chargebacks, failed and reversed refunds of the
 *   period) summed per tax country (place of supply, line snapshot), VAT rate and currency. Countries
 *   other than the seller country inside the EU are marked as OSS candidates (B2C admission is taxed
 *   where the event takes place; EU OSS, Art. 369a ff. MwStSystRL), rows naming several countries
 *   ("DE, AT", refunds of mixed orders) are marked for review.
 * - `tax_zm` ("Zusammenfassende Meldung (Reverse Charge)"): finalized organizer settlements whose
 *   commission/fee invoice was issued under reverse charge (Settlements::totals() fee_reverse_charge),
 *   summed per recipient VAT ID and country over the settlement months of the date range
 *   (sonstige Leistungen, § 18a UStG / Art. 262 MwStSystRL).
 *
 * Both are aids, no tax return: amounts come from the stored documents, nothing is recalculated, and
 * the tax adviser checks registration duties, thresholds and the reporting period (docs/TAX-PROFILES.md).
 */
namespace PassCaptain\Reports;

use PassCaptain\Invoices;
use PassCaptain\Partners\SalesModel;
use PassCaptain\Tax\Profiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TaxSummary {
	const CAPABILITY = 'wpcap_view_finance';

	/** Export types of the reports page (Exports::types()). */
	public static function types() {
		return array(
			'tax_oss' => array(
				'label'      => __( 'Steuerauswertung nach Land und Satz (OSS)', 'pass-captain' ),
				'capability' => self::CAPABILITY,
				'rows'       => static function ( array $filters, $scope ) { return null === $scope ? self::oss_rows( $filters['from'], $filters['to'] ) : self::oss_rows( '', '' ); },
				'filename'   => 'pass-captain-steuer-laender',
			),
			'tax_zm'  => array(
				'label'      => __( 'Zusammenfassende Meldung (Reverse Charge)', 'pass-captain' ),
				'capability' => self::CAPABILITY,
				'rows'       => static function ( array $filters, $scope ) { return null === $scope ? self::zm_rows( $filters['from'], $filters['to'] ) : self::zm_rows( '', '' ); },
				'filename'   => 'pass-captain-zm',
			),
		);
	}

	/** Header of the OSS summary. */
	public static function oss_header() {
		return array( 'Steuerland', 'USt-Satz', 'Waehrung', 'Netto', 'USt', 'Brutto', 'Buchungen', 'OSS-Kandidat' );
	}

	/**
	 * Ledger rows of the period summed per tax country, rate and currency ('' dates: header only – for a
	 * user whose report scope is limited to some events; the summary covers the whole site).
	 *
	 * @param string $from Y-m-d (site days, as the ledger export).
	 * @param string $to   Y-m-d.
	 * @return array[] Rows, first row = header.
	 */
	public static function oss_rows( $from, $to ) {
		$out = array( self::oss_header() );
		if ( ! Data::valid_date( $from ) || ! Data::valid_date( $to ) ) { return $out; }
		$ledger = Invoices::ledger_export_rows( $from, $to );
		$head = array_flip( (array) array_shift( $ledger ) );
		$col = static function ( $name, $fallback ) use ( $head ) { return $head[ $name ] ?? $fallback; };
		list( $c_net, $c_rate, $c_tax, $c_gross, $c_cur, $c_country ) = array( $col( 'Netto', 8 ), $col( 'USt-Satz', 9 ), $col( 'USt', 10 ), $col( 'Brutto', 11 ), $col( 'Waehrung', 12 ), $col( 'Steuerland', 15 ) );
		$seller = Profiles::seller_country();
		$groups = array();
		foreach ( $ledger as $row ) {
			$row = array_values( (array) $row );
			$currency = strtoupper( (string) ( $row[ $c_cur ] ?? '' ) );
			$country = strtoupper( trim( (string) ( $row[ $c_country ] ?? '' ) ) );
			$country = '' !== $country ? $country : $seller;
			$rate = (string) ( $row[ $c_rate ] ?? '' );
			$key = $country . '|' . $rate . '|' . $currency;
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'country' => $country, 'rate' => $rate, 'currency' => $currency, 'net' => 0, 'tax' => 0, 'gross' => 0, 'count' => 0, 'decimals' => 0 ); }
			foreach ( array( 'net' => $c_net, 'tax' => $c_tax, 'gross' => $c_gross ) as $field => $index ) {
				list( $minor, $decimals ) = self::to_minor( (string) ( $row[ $index ] ?? '0' ) );
				$groups[ $key ][ $field ] += $minor;
				$groups[ $key ]['decimals'] = max( $groups[ $key ]['decimals'], $decimals );
			}
			++$groups[ $key ]['count'];
		}
		uasort( $groups, static function ( $a, $b ) { return array( $a['country'], $a['currency'], (float) $a['rate'] ) <=> array( $b['country'], $b['currency'], (float) $b['rate'] ); } );
		foreach ( $groups as $g ) {
			$out[] = array( $g['country'], $g['rate'], $g['currency'], self::from_minor( $g['net'], $g['decimals'] ), self::from_minor( $g['tax'], $g['decimals'] ), self::from_minor( $g['gross'], $g['decimals'] ), $g['count'], self::oss_flag( $g['country'], $seller ) );
		}
		return Exports::filter_rows( $out, 'tax_oss', array( 'from' => $from, 'to' => $to ) );
	}

	/** "ja" (other EU country), "nein" (seller country, outside the EU) or "prüfen" (several countries). */
	public static function oss_flag( $country, $seller ) {
		if ( false !== strpos( (string) $country, ',' ) ) { return 'prüfen'; }
		return $country !== $seller && in_array( $country, SalesModel::EU_COUNTRIES, true ) && in_array( $seller, SalesModel::EU_COUNTRIES, true ) ? 'ja' : 'nein';
	}

	/** Header of the ZM summary. */
	public static function zm_header() {
		return array( 'USt-IdNr', 'Land', 'Art', 'Bemessungsgrundlage', 'Waehrung', 'Belege', 'Zeitraeume' );
	}

	/**
	 * Reverse-charge commission/fee invoices of finalized (or paid) settlements whose settlement month
	 * (YYYY-MM) lies in the months of the range (a quarter: the first to the last day of the quarter), summed per recipient VAT ID, country and currency (amount: platform_fee_net of the stored
	 * totals; country and VAT ID from the snapshot taken at finalization).
	 *
	 * @return array[] Rows, first row = header.
	 */
	public static function zm_rows( $from, $to ) {
		global $wpdb;
		$out = array( self::zm_header() );
		if ( ! Data::valid_date( $from ) || ! Data::valid_date( $to ) ) { return $out; }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT settlement_id,period,period_end,currency,totals,partner_snapshot,document_number FROM %i WHERE status IN ('finalized','paid') AND period >= %s AND period <= %s ORDER BY period,settlement_id", $wpdb->prefix . 'wpcap_partner_settlements', substr( $from, 0, 7 ), substr( $to, 0, 7 ) ) );
		$groups = array();
		foreach ( $rows as $s ) {
			$totals = json_decode( (string) $s->totals, true );
			if ( ! is_array( $totals ) || empty( $totals['fee_reverse_charge'] ) ) { continue; }
			$snapshot = json_decode( (string) $s->partner_snapshot, true );
			$partner = is_array( $snapshot['partner'] ?? null ) ? $snapshot['partner'] : array();
			$vat_id = SalesModel::normalize_vat_id( (string) ( $partner['vat_id'] ?? '' ) );
			$country = SalesModel::partner_country( (object) $partner );
			$currency = strtoupper( (string) $s->currency );
			$key = $vat_id . '|' . $country . '|' . $currency;
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'vat_id' => $vat_id, 'country' => $country, 'currency' => $currency, 'net' => 0, 'docs' => array(), 'periods' => array() ); }
			$groups[ $key ]['net'] += (int) ( $totals['platform_fee_net'] ?? 0 );
			$groups[ $key ]['docs'][] = (string) $s->document_number;
			$groups[ $key ]['periods'][ (string) $s->period ] = true;
		}
		ksort( $groups );
		foreach ( $groups as $g ) {
			$out[] = array( $g['vat_id'], $g['country'], 'Sonstige Leistung', \PassCaptain\Localization::money_decimal( $g['net'], $g['currency'] ), $g['currency'], implode( ' ', array_filter( $g['docs'] ) ), implode( ' ', array_keys( $g['periods'] ) ) );
		}
		return Exports::filter_rows( $out, 'tax_zm', array( 'from' => $from, 'to' => $to ) );
	}

	/**
	 * Decimal string ("-12.50") as integer minor units and its number of decimals.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function to_minor( $decimal ) {
		$decimal = trim( (string) $decimal );
		if ( ! preg_match( '/^(-?)(\d*)(?:\.(\d+))?$/', $decimal, $m ) ) { return array( 0, 0 ); }
		$frac = $m[3] ?? '';
		$value = (int) ( ( '' !== $m[2] ? $m[2] : '0' ) . $frac );
		return array( '-' === $m[1] ? -$value : $value, strlen( $frac ) );
	}

	/** Integer minor units as a decimal string with $decimals places. */
	public static function from_minor( $minor, $decimals ) {
		$minor = (int) $minor;
		$sign = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( $decimals <= 0 ) { return $sign . $digits; }
		$digits = str_pad( $digits, $decimals + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
	}
}
