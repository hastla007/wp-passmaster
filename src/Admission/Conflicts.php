<?php
/**
 * Offline admission conflicts: an offline scanner let a person in, but the server rejected the
 * uploaded scan (already used, limit reached, wrong area …). Each such scan gets exactly one record
 * (deduplicated per user + idempotency key) that staff review and resolve on "Einlass-Konflikte".
 */
namespace PassCaptain\Admission;

use PassCaptain\Access;
use PassCaptain\Admin\Queries;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Conflicts {
	/** Capability needed (with event scope) to resolve conflicts. */
	const RESOLVE_CAP = 'wpcap_manage_events';
	const VIEW_CAPS = array( 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_manage_events' );

	public static function resolutions() {
		return array(
			'accepted' => __( 'Einlass nachträglich akzeptiert', 'pass-captain' ),
			'duplicate_scan' => __( 'Doppelscan derselben Person', 'pass-captain' ),
			'misuse' => __( 'Missbrauch (Ticket weitergegeben/kopiert)', 'pass-captain' ),
			'other' => __( 'Sonstiges', 'pass-captain' ),
		);
	}

	/**
	 * Records a conflict (idempotent).
	 *
	 * @param array $data ticket_id, event_id, occurrence_id, area_id, result, source, device_id, user_id,
	 *                    scanned_at, checkin_id, dedupe (string unique per scan).
	 * @return int Conflict id (existing one for a repeated upload).
	 */
	public static function record( array $data ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_id = absint( $data['ticket_id'] ?? 0 );
		if ( ! $ticket_id ) { return 0; }
		$dedupe = hash( 'sha256', (string) $data['dedupe'] );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT conflict_id FROM %i WHERE dedupe_key=%s', $p . 'wpcap_admission_conflicts', $dedupe ) );
		if ( $existing ) { return $existing; }
		$first = $wpdb->get_row( $wpdb->prepare( "SELECT checkin_id,device_id,user_id,COALESCE(scanned_at,created_at) AS at FROM %i WHERE ticket_id=%d AND result='checked_in' AND checkin_id<>%d ORDER BY checkin_id DESC LIMIT 1", $p . 'wpcap_checkins', $ticket_id, absint( $data['checkin_id'] ?? 0 ) ) );
		// Optional times are sent as '' and stored as NULL (NULLIF).
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO %i (dedupe_key,ticket_id,event_id,occurrence_id,area_id,result,source,device_id,user_id,scanned_at,checkin_id,first_checkin_id,first_device_id,first_user_id,first_at,status,created_at) VALUES (%s,%d,%d,%d,%d,%s,%s,%d,%d,NULLIF(%s,''),%d,%d,%d,%d,NULLIF(%s,''),'open',%s)",
			$p . 'wpcap_admission_conflicts',
			$dedupe, $ticket_id, absint( $data['event_id'] ?? 0 ), absint( $data['occurrence_id'] ?? 0 ), absint( $data['area_id'] ?? 0 ),
			sanitize_key( (string) ( $data['result'] ?? 'conflict' ) ), sanitize_key( (string) ( $data['source'] ?? 'offline' ) ),
			absint( $data['device_id'] ?? 0 ), absint( $data['user_id'] ?? 0 ),
			empty( $data['scanned_at'] ) ? '' : (string) $data['scanned_at'],
			absint( $data['checkin_id'] ?? 0 ), $first ? (int) $first->checkin_id : 0, $first ? (int) $first->device_id : 0, $first ? (int) $first->user_id : 0,
			$first ? (string) $first->at : '',
			current_time( 'mysql', true )
		) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT conflict_id FROM %i WHERE dedupe_key=%s', $p . 'wpcap_admission_conflicts', $dedupe ) );
		if ( $id && $id === (int) $wpdb->insert_id ) {
			Audit::record( 'admission_conflict_recorded', 'admission_conflict', $id, array( 'ticket_id' => $ticket_id, 'result' => sanitize_key( (string) ( $data['result'] ?? '' ) ), 'device_id' => absint( $data['device_id'] ?? 0 ) ) );
		}
		return $id;
	}

	public static function get( $conflict_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE conflict_id=%d', $wpdb->prefix . 'wpcap_admission_conflicts', absint( $conflict_id ) ) );
	}

	/** Existing conflict of a scan (for replayed uploads). */
	public static function find_by_dedupe( $dedupe ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT conflict_id FROM %i WHERE dedupe_key=%s', $wpdb->prefix . 'wpcap_admission_conflicts', hash( 'sha256', (string) $dedupe ) ) );
	}

	/** Open conflicts in the current user's event scope (menu badge). */
	public static function open_count_for_current_user() {
		global $wpdb;
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		if ( is_array( $scope ) && ! $scope ) { return 0; }
		$where = "status='open'" . Queries::scope_sql( $scope, 'event_id' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $wpdb->prefix . 'wpcap_admission_conflicts' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: literal condition and integer scope.
	}

	/**
	 * Conflict list.
	 *
	 * @param array      $filters status, event_id.
	 * @param int[]|null $scope   Event ids (null = all).
	 * @return array{rows: object[], total: int}
	 */
	public static function search( array $filters, $scope, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '1=1' . Queries::scope_sql( $scope, 'c.event_id' );
		$args = array();
		if ( ! empty( $filters['status'] ) ) { $where .= ' AND c.status=%s'; $args[] = (string) $filters['status']; }
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND c.event_id=%d'; $args[] = absint( $filters['event_id'] ); }
		$tables = array( $p . 'wpcap_admission_conflicts', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_scanner_devices', $p . 'wpcap_scanner_devices', $p . 'wpcap_admission_areas' );
		// $where: literal conditions with placeholders for every value ($args) and the integer scope.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i t ON t.ticket_id=c.ticket_id LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=c.occurrence_id LEFT JOIN %i e ON e.event_id=c.event_id LEFT JOIN %i d ON d.device_id=c.device_id LEFT JOIN %i fd ON fd.device_id=c.first_device_id LEFT JOIN %i a ON a.area_id=c.area_id WHERE $where", array_merge( $tables, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*,t.public_id AS ticket_public_id,tt.name AS ticket_name,o.timezone,e.name AS event_name,d.label AS device_label,fd.label AS first_device_label,a.name AS area_name FROM %i c LEFT JOIN %i t ON t.ticket_id=c.ticket_id LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=c.occurrence_id LEFT JOIN %i e ON e.event_id=c.event_id LEFT JOIN %i d ON d.device_id=c.device_id LEFT JOIN %i fd ON fd.device_id=c.first_device_id LEFT JOIN %i a ON a.area_id=c.area_id WHERE $where ORDER BY c.status='open' DESC, c.conflict_id DESC LIMIT %d OFFSET %d", array_merge( $tables, $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Resolves an open conflict.
	 *
	 * @return array|\WP_Error
	 */
	public static function resolve( $conflict_id, $resolution, $note ) {
		global $wpdb;
		$conflict = self::get( $conflict_id );
		if ( ! $conflict ) { return new \WP_Error( 'wpcap_conflict_missing', __( 'Konflikt nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( (int) $conflict->event_id, self::RESOLVE_CAP ) ) {
			return new \WP_Error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung, Einlass-Konflikte dieses Events zu klären.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( ! array_key_exists( (string) $resolution, self::resolutions() ) ) {
			return new \WP_Error( 'wpcap_conflict_resolution', __( 'Bitte eine Klärung auswählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$note = mb_substr( sanitize_textarea_field( (string) $note ), 0, 2000 );
		if ( 'other' === $resolution && '' === $note ) {
			return new \WP_Error( 'wpcap_conflict_note', __( 'Bei „Sonstiges“ ist eine Notiz erforderlich.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='resolved', resolution=%s, resolution_note=%s, resolved_by=%d, resolved_at=%s WHERE conflict_id=%d AND status='open'", $wpdb->prefix . 'wpcap_admission_conflicts', $resolution, $note, get_current_user_id(), current_time( 'mysql', true ), (int) $conflict->conflict_id ) );
		if ( ! $updated ) {
			return new \WP_Error( 'wpcap_conflict_resolved', __( 'Dieser Konflikt wurde bereits geklärt.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		Audit::record( 'admission_conflict_resolved', 'admission_conflict', (int) $conflict->conflict_id, array( 'event_id' => (int) $conflict->event_id, 'ticket_id' => (int) $conflict->ticket_id, 'resolution' => $resolution ) );
		return array( 'conflict_id' => (int) $conflict->conflict_id, 'resolution' => $resolution );
	}
}
