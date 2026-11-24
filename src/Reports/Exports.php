<?php
/**
 * Extension points for CSV exports.
 *
 * - wp_pass_captain_export_rows_v1 ( array $rows, string $type, array $context ): rows (first row =
 *   header) of every core CSV export before output, e.g. to append columns. Types: `report` (reports
 *   page, context `report`), `accounting` (accounting export, context `from`, `to`),
 *   `accounting_ledger` (payments/refunds export independent of invoices, same context) and every
 *   additional type below. Cells are passed through Scope::csv_cell() on output (no formulas).
 * - wp_pass_captain_export_types_v1 ( array $types ): additional export types, shown as buttons on
 *   the reports page: id => [ 'label' => string, 'capability' => string (default wpcap_view_reports),
 *   'rows' => callable( array $filters, $scope ): array rows, 'filename' => string (optional) ].
 *   The filter receives the core types `tax_oss` and `tax_zm` (Reports\TaxSummary, wpcap_view_finance).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Reports;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Exports {
	const ACTION = 'wpcap_reports_export';

	/** Applies wp_pass_captain_export_rows_v1; invalid results keep the core rows. */
	public static function filter_rows( array $rows, $type, array $context = array() ) {
		$filtered = apply_filters( 'wp_pass_captain_export_rows_v1', $rows, (string) $type, $context );
		if ( ! is_array( $filtered ) ) { return $rows; }
		return array_values( array_map( static function ( $row ) { return array_values( array_map( static function ( $cell ) { return is_scalar( $cell ) || null === $cell ? $cell : wp_json_encode( $cell ); }, (array) $row ) ); }, $filtered ) );
	}

	/**
	 * Registered additional export types the current user may run.
	 *
	 * @return array<string,array{label:string,capability:string,rows:callable,filename:string}>
	 */
	public static function types() {
		// Core types first (TaxSummary: OSS and ZM summaries for finance users); the filter may add or remove.
		$types = apply_filters( 'wp_pass_captain_export_types_v1', TaxSummary::types() );
		if ( ! is_array( $types ) ) { return array(); }
		$out = array();
		foreach ( $types as $id => $def ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || in_array( $id, array( 'report', 'accounting', 'accounting_ledger' ), true ) || ! is_array( $def ) || ! isset( $def['rows'] ) || ! is_callable( $def['rows'] ) ) { continue; }
			$cap = (string) ( $def['capability'] ?? 'wpcap_view_reports' );
			if ( ! current_user_can( $cap ) ) { continue; }
			$out[ $id ] = array(
				'label'      => (string) ( $def['label'] ?? $id ),
				'capability' => $cap,
				'rows'       => $def['rows'],
				'filename'   => (string) ( $def['filename'] ?? 'pass-captain-' . $id ),
			);
		}
		return $out;
	}

	/** Download link of an additional export type. */
	public static function url( $type_id, array $filters ) {
		return add_query_arg( array_merge( $filters, array( 'action' => self::ACTION, 'type' => $type_id, '_wpnonce' => wp_create_nonce( self::ACTION . '_' . $type_id ) ) ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * Builds the CSV of an additional export type for the current user.
	 *
	 * @return array{filename:string,csv:string}|\WP_Error
	 */
	public static function build( $type_id, array $filters ) {
		$types = self::types();
		$type_id = sanitize_key( (string) $type_id );
		if ( ! isset( $types[ $type_id ] ) ) {
			return new \WP_Error( 'wpcap_export_unknown', __( 'Unbekannter oder nicht erlaubter Export.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$scope = Data::scope();
		if ( false === $scope ) {
			return new \WP_Error( 'wpcap_export_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$rows = call_user_func( $types[ $type_id ]['rows'], $filters, $scope );
		$rows = self::filter_rows( is_array( $rows ) ? $rows : array(), $type_id, array( 'filters' => $filters ) );
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '-', $types[ $type_id ]['filename'] ) . '-' . $filters['from'] . '-' . $filters['to'] . '.csv';
		return array( 'filename' => $name, 'csv' => Scope::csv( $rows ) );
	}

	/** admin-post handler of additional export types. */
	public static function handle_export() {
		$type_id = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		check_admin_referer( self::ACTION . '_' . $type_id );
		$filters = Page::filters( wp_unslash( $_GET ) );
		$result = self::build( $type_id, $filters );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Audit::record( 'report_exported', 'report', 0, array( 'type' => $type_id, 'from' => $filters['from'], 'to' => $filters['to'] ) );
		Scope::send_download( $result['csv'], $result['filename'], 'text/csv; charset=utf-8' );
	}
}
