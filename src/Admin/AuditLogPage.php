<?php
/**
 * Read-only audit log viewer (A122): filter by area (PII views, exports, privacy tools, roles,
 * corrections), exact action, user, object and date. Entries are deleted automatically after the
 * audit retention (Privacy\Retention step "audit_logs", filter wpcap_privacy_retention_days).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Privacy\AccessLog;
use PassCaptain\Privacy\Retention;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AuditLogPage {
	const SLUG = 'wpcap-audit-log';
	const CAP = 'manage_options';
	const PER_PAGE = 50;

	/** Filters from the query string. */
	public static function filters() {
		$groups = AccessLog::groups();
		$group = sanitize_key( Forms::query_arg( 'group' ) );
		$date_from = Forms::query_arg( 'date_from' );
		$date_to = Forms::query_arg( 'date_to' );
		$valid = static function ( $date ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ); };
		return array(
			'group' => isset( $groups[ $group ] ) ? $group : '',
			'action' => sanitize_key( Forms::query_arg( 'audit_action' ) ),
			'user_id' => absint( Forms::query_arg( 'user_id', 0 ) ),
			'object_type' => sanitize_key( Forms::query_arg( 'object_type' ) ),
			'object_id' => absint( Forms::query_arg( 'object_id', 0 ) ),
			'date_from' => $valid( $date_from ) ? $date_from : '',
			'date_to' => $valid( $date_to ) ? $date_to : '',
		);
	}

	/**
	 * Audit entries matching the filters, newest first.
	 *
	 * @return array{rows: object[], total: int}
	 */
	public static function query( array $filters, $page = 1, $per_page = self::PER_PAGE ) {
		global $wpdb;
		$where = array( '1=1' );
		$args = array();
		$groups = AccessLog::groups();
		if ( ! empty( $filters['group'] ) && isset( $groups[ $filters['group'] ] ) ) {
			$where[] = 'action IN (' . implode( ',', array_fill( 0, count( $groups[ $filters['group'] ] ), '%s' ) ) . ')';
			$args = array_merge( $args, $groups[ $filters['group'] ] );
		}
		if ( ! empty( $filters['action'] ) ) { $where[] = 'action=%s'; $args[] = sanitize_key( $filters['action'] ); }
		if ( ! empty( $filters['user_id'] ) ) { $where[] = 'user_id=%d'; $args[] = absint( $filters['user_id'] ); }
		if ( ! empty( $filters['object_type'] ) ) { $where[] = 'object_type=%s'; $args[] = sanitize_key( $filters['object_type'] ); }
		if ( ! empty( $filters['object_id'] ) ) { $where[] = 'object_id=%d'; $args[] = absint( $filters['object_id'] ); }
		if ( ! empty( $filters['date_from'] ) ) { $where[] = 'created_at>=%s'; $args[] = $filters['date_from'] . ' 00:00:00'; }
		if ( ! empty( $filters['date_to'] ) ) { $where[] = 'created_at<=%s'; $args[] = $filters['date_to'] . ' 23:59:59'; }
		$sql_where = implode( ' AND ', $where );
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		$offset = ( max( 1, (int) $page ) - 1 ) * max( 1, (int) $per_page );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where";
		$rows_sql = "SELECT * FROM $table WHERE $sql_where ORDER BY audit_id DESC LIMIT %d OFFSET %d";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( max( 1, (int) $per_page ), $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/** Details for display: JSON without the internal de-duplication fingerprint, shortened. */
	public static function details_text( $details ) {
		$data = json_decode( (string) $details, true );
		if ( ! is_array( $data ) ) { return (string) $details; }
		unset( $data['fp'] );
		$text = (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return strlen( $text ) > 400 ? substr( $text, 0, 400 ) . '…' : $text;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$filters = self::filters();
		$page = Forms::current_page();
		$data = self::query( $filters, $page );
		AccessLog::list_view( 'audit_log', $filters + array( 'paged' => $page ), count( $data['rows'] ) );
		$days = Retention::days( 'audit_logs' );
		$base = Forms::page_url( self::SLUG, array_filter( array(
			'group' => $filters['group'],
			'audit_action' => $filters['action'],
			'user_id' => $filters['user_id'],
			'object_type' => $filters['object_type'],
			'object_id' => $filters['object_id'],
			'date_from' => $filters['date_from'],
			'date_to' => $filters['date_to'],
		) ) );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Audit-Log', 'pass-captain' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Protokoll von Änderungen, Exporten und Zugriffen auf personenbezogene Daten. Personenbezogene Werte erscheinen hier nur maskiert.', 'pass-captain' ) . ' ';
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			echo esc_html( sprintf( _n( 'Einträge werden nach %d Tag automatisch gelöscht.', 'Einträge werden nach %d Tagen automatisch gelöscht.', $days, 'pass-captain' ), $days ) );
		} else {
			echo esc_html__( 'Einträge werden nicht automatisch gelöscht.', 'pass-captain' );
		}
		echo '</p>';
		echo '<form method="get" class="wpcap-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		Forms::select( 'group', array( '' => __( 'Alle Bereiche', 'pass-captain' ) ) + AccessLog::group_labels(), $filters['group'], '', 'aria-label="' . esc_attr__( 'Bereich', 'pass-captain' ) . '"' );
		echo ' <input type="text" name="audit_action" size="18" value="' . esc_attr( $filters['action'] ) . '" placeholder="' . esc_attr__( 'Aktion, z. B. pii_viewed', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Aktion', 'pass-captain' ) . '">';
		echo ' <input type="number" name="user_id" min="0" style="width:7em" value="' . esc_attr( $filters['user_id'] ? (string) $filters['user_id'] : '' ) . '" placeholder="' . esc_attr__( 'Benutzer-ID', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Benutzer-ID', 'pass-captain' ) . '">';
		echo ' <input type="text" name="object_type" size="10" value="' . esc_attr( $filters['object_type'] ) . '" placeholder="' . esc_attr__( 'Objekttyp', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Objekttyp', 'pass-captain' ) . '">';
		echo ' <input type="number" name="object_id" min="0" style="width:7em" value="' . esc_attr( $filters['object_id'] ? (string) $filters['object_id'] : '' ) . '" placeholder="' . esc_attr__( 'Objekt-ID', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Objekt-ID', 'pass-captain' ) . '">';
		echo ' <input type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" aria-label="' . esc_attr__( 'Von (UTC)', 'pass-captain' ) . '">';
		echo ' <input type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" aria-label="' . esc_attr__( 'Bis (UTC)', 'pass-captain' ) . '">';
		echo ' <button class="button" type="submit">' . esc_html__( 'Filtern', 'pass-captain' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Objekt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Details', 'pass-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Keine Einträge gefunden.', 'pass-captain' ) . '</td></tr>';
		}
		foreach ( $data['rows'] as $row ) {
			echo '<tr><td>' . esc_html( Forms::datetime( $row->created_at, wp_timezone_string(), false ) ) . '</td><td>' . esc_html( (int) $row->user_id ? Forms::user_label( (int) $row->user_id ) : __( 'System', 'pass-captain' ) ) . '</td><td><code>' . esc_html( $row->action ) . '</code></td><td>' . esc_html( $row->object_type . ( (int) $row->object_id ? ' #' . (int) $row->object_id : '' ) ) . '</td><td><code style="white-space:pre-wrap;word-break:break-word">' . esc_html( self::details_text( $row->details ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		Forms::pagination( $data['total'], $page, self::PER_PAGE, $base );
		echo '</div>';
	}
}
