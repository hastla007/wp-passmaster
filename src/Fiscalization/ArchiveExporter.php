<?php
/**
 * Restricted, normalized CSV export for fiscal operations.
 *
 * This is an operational archive extract, not a country-mandated export format.
 */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ArchiveExporter {
	/** Download normalized fiscal document data for a bounded UTC date interval. */
	public static function handle_admin_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpcap_fiscal_export' );
		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$until = sanitize_text_field( wp_unslash( $_POST['until'] ?? '' ) );
		if ( ! self::valid_date( $from ) || ! self::valid_date( $until ) || $from > $until ) {
			wp_die( esc_html__( 'Bitte einen gültigen UTC-Zeitraum wählen.', 'wp-pass-captain' ), '', array( 'response' => 400 ) );
		}
		$end_exclusive = ( new \DateTimeImmutable( $until . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.document_id,d.order_id,d.seller_id,d.document_key,d.country_code,d.provider_id,d.status,d.currency,d.gross_minor,d.tax_minor,d.snapshot,d.provider_result,d.last_error,d.attempts,d.created_at,d.updated_at,s.legal_name
			FROM {$wpdb->prefix}wpcap_fiscal_documents d
			LEFT JOIN {$wpdb->prefix}wpcap_legal_sellers s ON s.seller_id=d.seller_id
			WHERE d.created_at >= %s AND d.created_at < %s ORDER BY d.created_at,d.document_id LIMIT 50000",
			$from . ' 00:00:00',
			$end_exclusive
		) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wp-pass-captain-fiscal-' . $from . '-to-' . $until . '.csv' );
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) { wp_die( esc_html__( 'Export konnte nicht gestartet werden.', 'wp-pass-captain' ), '', array( 'response' => 500 ) ); }
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'document_id', 'order_id', 'seller_id', 'seller_name', 'country', 'provider', 'status', 'document_key', 'currency', 'gross_minor', 'tax_minor', 'provider_reference', 'qr_payload_base64', 'receipt_fields_json', 'snapshot_json', 'last_error', 'attempts', 'created_at_utc', 'updated_at_utc' ) );
		foreach ( (array) $rows as $row ) {
			$snapshot = json_decode( $row->snapshot, true );
			$result = json_decode( $row->provider_result, true );
			$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
			$fields = is_array( $receipt['fields'] ?? null ) ? array_slice( $receipt['fields'], 0, 32, true ) : array();
			fputcsv( $output, array_map( array( __CLASS__, 'cell' ), array(
				(int) $row->document_id,
				(int) $row->order_id,
				(int) $row->seller_id,
				(string) $row->legal_name,
				(string) $row->country_code,
				(string) $row->provider_id,
				(string) $row->status,
				(string) $row->document_key,
				(string) $row->currency,
				(int) $row->gross_minor,
				(int) $row->tax_minor,
				is_scalar( $result['reference'] ?? null ) ? (string) $result['reference'] : '',
				is_string( $receipt['qr_payload'] ?? null ) ? base64_encode( $receipt['qr_payload'] ) : '',
				wp_json_encode( $fields ),
				wp_json_encode( is_array( $snapshot ) ? $snapshot : array() ),
				(string) $row->last_error,
				(int) $row->attempts,
				(string) $row->created_at,
				(string) $row->updated_at,
			) ) );
		}
		fclose( $output );
		exit;
	}

	private static function valid_date( $value ) {
		if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) { return false; }
		$parts = array_map( 'intval', explode( '-', $value ) );
		return 3 === count( $parts ) && checkdate( $parts[1], $parts[2], $parts[0] );
	}

	/** Neutralize spreadsheet formulas while preserving the original value as text. */
	private static function cell( $value ) {
		if ( ! is_scalar( $value ) ) { return ''; }
		$value = (string) $value;
		return preg_match( '/^[\\s]*[=+@\\-]/', $value ) ? "'" . $value : $value;
	}
}
