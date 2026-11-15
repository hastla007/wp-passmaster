<?php
/**
 * Admin viewer of the audit log (W9): filters by action, object, user and date range, paging and an
 * audited CSV export. Requires wpcap_view_audit (granted to administrators) or manage_options.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Reports\Scope;
use PassCaptain\Security\AuditTrail;
use PassCaptain\Security\StepUp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AuditLogPage {
	const SLUG = 'wpcap-audit-log';
	const CAP = 'wpcap_view_audit';
	const EXPORT_ACTION = 'wpcap_audit_export';
	/** Rows per database page while streaming the export (no overall limit). */
	const EXPORT_PAGE = 1000;

	/** Capability for the menu entry. */
	public static function menu_cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	public static function can_view() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Normalised filters from request data.
	 *
	 * @return array{action:string,object_type:string,object_id:int,user_id:int,from:string,to:string}
	 */
	public static function filters( array $in ) {
		$date = static function ( $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		return array(
			'action'      => isset( $in['audit_action'] ) && is_scalar( $in['audit_action'] ) ? sanitize_key( (string) $in['audit_action'] ) : '',
			'object_type' => isset( $in['object_type'] ) && is_scalar( $in['object_type'] ) ? sanitize_key( (string) $in['object_type'] ) : '',
			'object_id'   => isset( $in['object_id'] ) ? absint( $in['object_id'] ) : 0,
			'user_id'     => isset( $in['user_id'] ) ? absint( $in['user_id'] ) : 0,
			'from'        => $date( $in['from'] ?? '' ),
			'to'          => $date( $in['to'] ?? '' ),
		);
	}

	/** WHERE clause and arguments; dates are site-local days converted to UTC bounds. */
	private static function where( array $f ) {
		$where = '1=1';
		$args = array();
		if ( '' !== $f['action'] ) { $where .= ' AND action=%s'; $args[] = $f['action']; }
		if ( '' !== $f['object_type'] ) { $where .= ' AND object_type=%s'; $args[] = $f['object_type']; }
		if ( $f['object_id'] ) { $where .= ' AND object_id=%d'; $args[] = $f['object_id']; }
		if ( $f['user_id'] ) { $where .= ' AND user_id=%d'; $args[] = $f['user_id']; }
		$tz = Scope::site_timezone();
		if ( '' !== $f['from'] ) { $where .= ' AND created_at >= %s'; $args[] = self::local_day_to_utc( $f['from'], $tz, 0 ); }
		if ( '' !== $f['to'] ) { $where .= ' AND created_at < %s'; $args[] = self::local_day_to_utc( $f['to'], $tz, 1 ); }
		return array( $where, $args );
	}

	private static function local_day_to_utc( $day, $tz, $add_days ) {
		try {
			$dt = new \DateTimeImmutable( $day . ' 00:00:00', new \DateTimeZone( $tz ) );
			return $dt->modify( '+' . (int) $add_days . ' day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable $e ) {
			return $day . ' 00:00:00';
		}
	}

	/**
	 * Audit rows (newest first).
	 *
	 * @return array{rows:object[],total:int}
	 */
	public static function query( array $filters, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		list( $where, $args ) = self::where( $filters );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
		$list_sql = "SELECT * FROM $table WHERE $where ORDER BY audit_id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/** Distinct values of a column for the filter selects (max. 300). */
	public static function distinct( $column ) {
		global $wpdb;
		$column = 'object_type' === $column ? 'object_type' : 'action';
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT $column FROM {$wpdb->prefix}wpcap_audit_logs ORDER BY $column LIMIT 300" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelisted column.
	}

	/** CSV rows (header first) for the filters, as an array (small exports, tests). */
	public static function csv_rows( array $filters ) {
		return iterator_to_array( self::csv_row_stream( $filters ), false );
	}

	/**
	 * CSV rows (header first) for the filters, newest first, read in pages of EXPORT_PAGE rows by
	 * audit_id (keyset): no row limit (QA-7), memory stays flat while streaming.
	 *
	 * @return \Generator
	 */
	public static function csv_row_stream( array $filters ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		list( $where, $args ) = self::where( $filters );
		yield array( 'audit_id', 'created_at_utc', 'user_id', 'action', 'object_type', 'object_id', 'details' );
		$last = PHP_INT_MAX;
		do {
			$sql = "SELECT * FROM $table WHERE $where AND audit_id < %d ORDER BY audit_id DESC LIMIT %d";
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $last, self::EXPORT_PAGE ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
			foreach ( $rows as $row ) {
				$last = (int) $row->audit_id;
				yield array( (int) $row->audit_id, (string) $row->created_at, (int) $row->user_id, (string) $row->action, (string) $row->object_type, (int) $row->object_id, (string) $row->details );
			}
			$full_page = count( $rows ) === self::EXPORT_PAGE;
		} while ( $full_page );
	}

	/**
	 * Builds the export after permission and 2FA checks; records the export in the audit log.
	 *
	 * @param bool $stream True: rows as a generator (download), false: the CSV string.
	 * @return array{filename:string,csv?:string,rows?:\Generator}|\WP_Error
	 */
	public static function export( array $filters, $stream = false ) {
		if ( ! self::can_view() ) { return Forms::forbidden(); }
		$check = StepUp::check( array( 'exports' ), self::EXPORT_ACTION );
		if ( is_wp_error( $check ) ) { return $check; }
		$total = (int) self::query( $filters, 1, 1 )['total'];
		Audit::record( 'audit_log_exported', 'audit', 0, array_merge( array_filter( $filters ), array( 'rows' => $total ) ) );
		$filename = 'wp-pass-captain-audit-' . gmdate( 'Ymd-His' ) . '.csv';
		// $stream: rows as a generator for Scope::send_csv() (the admin download); else the CSV string.
		return $stream ? array( 'filename' => $filename, 'rows' => self::csv_row_stream( $filters ) ) : array( 'filename' => $filename, 'csv' => Scope::csv( self::csv_rows( $filters ) ) );
	}

	public static function handle_export() {
		if ( ! self::can_view() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		check_admin_referer( self::EXPORT_ACTION );
		$result = self::export( self::filters( wp_unslash( $_GET ) ), true );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Scope::send_csv( $result['rows'], $result['filename'] );
	}

	public static function render() {
		if ( ! self::can_view() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$filters = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$page = Forms::current_page();
		$per_page = 50;
		$data = self::query( $filters, $page, $per_page );
		AuditTrail::read( 'audit_log', 'audit', 0, array( 'scope' => md5( wp_json_encode( $filters ) ) . $page ) );
		$query_args = array_filter( array( 'audit_action' => $filters['action'], 'object_type' => $filters['object_type'], 'object_id' => $filters['object_id'], 'user_id' => $filters['user_id'], 'from' => $filters['from'], 'to' => $filters['to'] ) );
		$export_url = wp_nonce_url( add_query_arg( array_merge( $query_args, array( 'action' => self::EXPORT_ACTION ) ), admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
		$actions = array( '' => __( 'Alle Aktionen', 'pass-captain' ) );
		foreach ( self::distinct( 'action' ) as $a ) { $actions[ $a ] = $a; }
		$objects = array( '' => __( 'Alle Objekte', 'pass-captain' ) );
		foreach ( self::distinct( 'object_type' ) as $o ) { $objects[ $o ] = $o; }
		$tz = Scope::site_timezone();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Audit-Log', 'pass-captain' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Nachvollziehbarkeit von Änderungen und Zugriffen auf personenbezogene Daten. Details sind redigiert (keine Secrets, Codes oder E-Mail-Adressen). Jeder Export wird selbst protokolliert.', 'pass-captain' ) . '</p>';
		echo '<form method="get" class="wpcap-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><p>';
		Forms::select( 'audit_action', $actions, $filters['action'], 'wpcap-audit-action', 'aria-label="' . esc_attr__( 'Aktion', 'pass-captain' ) . '"' );
		echo ' ';
		Forms::select( 'object_type', $objects, $filters['object_type'], 'wpcap-audit-object', 'aria-label="' . esc_attr__( 'Objekt', 'pass-captain' ) . '"' );
		echo ' <label>' . esc_html__( 'Objekt-ID', 'pass-captain' ) . ' <input type="number" min="0" name="object_id" value="' . esc_attr( $filters['object_id'] ? (string) $filters['object_id'] : '' ) . '" style="width:7em"></label>';
		echo ' <label>' . esc_html__( 'Benutzer-ID', 'pass-captain' ) . ' <input type="number" min="0" name="user_id" value="' . esc_attr( $filters['user_id'] ? (string) $filters['user_id'] : '' ) . '" style="width:7em"></label>';
		echo ' <label>' . esc_html__( 'Von', 'pass-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '"></label>';
		echo ' <label>' . esc_html__( 'Bis', 'pass-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '"></label>';
		echo ' <button class="button" type="submit">' . esc_html__( 'Filtern', 'pass-captain' ) . '</button>';
		echo ' <a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'CSV-Export (wird protokolliert)', 'pass-captain' ) . '</a></p></form>';
		/* translators: 1: number of entries, 2: time zone */
		echo '<p>' . esc_html( sprintf( __( '%1$d Einträge · Zeiten in %2$s', 'pass-captain' ), $data['total'], $tz ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th>' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktion', 'pass-captain' ) . '</th><th>' . esc_html__( 'Objekt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Details', 'pass-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $data['rows'] ) { echo '<tr><td colspan="5">' . esc_html__( 'Keine Einträge gefunden.', 'pass-captain' ) . '</td></tr>'; }
		foreach ( $data['rows'] as $row ) {
			$details = json_decode( (string) $row->details, true );
			$pretty = is_array( $details ) && $details ? wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
			echo '<tr><td>' . esc_html( Scope::local( $row->created_at, $tz, 'd.m.Y H:i:s' ) ) . '</td>';
			echo '<td>' . esc_html( (int) $row->user_id ? Forms::user_label( (int) $row->user_id ) : __( 'System', 'pass-captain' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row->action ) . '</code></td>';
			echo '<td>' . esc_html( $row->object_type . ( (int) $row->object_id ? ' #' . (int) $row->object_id : '' ) ) . '</td>';
			echo '<td><code style="white-space:pre-wrap;word-break:break-word">' . esc_html( (string) $pretty ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		Forms::pagination( $data['total'], $page, $per_page, Forms::page_url( self::SLUG, $query_args ) );
		echo '</div>';
	}
}
