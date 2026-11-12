<?php
/**
 * Entrance areas (gates) per event, ticket type restrictions, staff and device assignment.
 *
 * Rules applied at check-in (Checkin::deny):
 *  1. A selected area must be active and belong to the ticket's event.
 *  2. Staff assigned to areas of an event may scan for that event only in one of their areas
 *     (users with manage_options are not restricted). Staff without area assignment are unrestricted.
 *  3. A ticket type restricted to areas is only admitted in one of them (also not without an area).
 * Check-out applies rules 1 and 2 only.
 */
namespace PassCaptain\Admission;

use PassCaptain\Access;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Areas {
	public static function get( $area_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_admission_areas WHERE area_id=%d", absint( $area_id ) ) );
	}

	/** Areas of an event (active only unless $all). */
	public static function for_event( $event_id, $all = false ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_admission_areas WHERE event_id=%d" . ( $all ? '' : " AND status='active'" ) . ' ORDER BY name, area_id', absint( $event_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal fragment.
	}

	/** Creates or renames an area (caller checks permissions). */
	public static function save( $event_id, $name, $area_id = 0 ) {
		global $wpdb;
		$name = mb_substr( sanitize_text_field( (string) $name ), 0, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_area_invalid', __( 'Bitte einen Namen für den Bereich angeben.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		if ( $area_id ) {
			$area = self::get( $area_id );
			if ( ! $area || (int) $area->event_id !== (int) $event_id ) { return new \WP_Error( 'wpcap_area_missing', __( 'Bereich nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
			$wpdb->update( "{$wpdb->prefix}wpcap_admission_areas", array( 'name' => $name ), array( 'area_id' => (int) $area_id ) );
			return (int) $area_id;
		}
		$wpdb->insert( "{$wpdb->prefix}wpcap_admission_areas", array( 'event_id' => absint( $event_id ), 'name' => $name, 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ) );
		return (int) $wpdb->insert_id;
	}

	/** Allowed area ids of a ticket type (empty = all areas). */
	public static function type_area_ids( $ticket_type_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT area_id FROM {$wpdb->prefix}wpcap_admission_type_areas WHERE ticket_type_id=%d", absint( $ticket_type_id ) ) ) );
	}

	/** Replaces the area restriction of a ticket type; areas must belong to $event_id. */
	public static function set_type_areas( $ticket_type_id, $event_id, array $area_ids ) {
		global $wpdb;
		$valid = array_map( static function ( $a ) { return (int) $a->area_id; }, self::for_event( $event_id, true ) );
		$area_ids = array_values( array_intersect( array_map( 'absint', $area_ids ), $valid ) );
		$wpdb->delete( "{$wpdb->prefix}wpcap_admission_type_areas", array( 'ticket_type_id' => absint( $ticket_type_id ) ), array( '%d' ) );
		foreach ( $area_ids as $area_id ) {
			$wpdb->insert( "{$wpdb->prefix}wpcap_admission_type_areas", array( 'ticket_type_id' => absint( $ticket_type_id ), 'area_id' => $area_id ) );
		}
		return $area_ids;
	}

	/** Area ids of $event_id the user is assigned to (empty = not restricted). */
	public static function user_area_ids( $user_id, $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT s.area_id FROM {$p}wpcap_admission_area_staff s INNER JOIN {$p}wpcap_admission_areas a ON a.area_id=s.area_id WHERE s.user_id=%d AND a.event_id=%d AND a.status='active'", absint( $user_id ), absint( $event_id ) ) ) );
	}

	public static function staff( $area_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}wpcap_admission_area_staff WHERE area_id=%d ORDER BY user_id", absint( $area_id ) ) ) );
	}

	public static function add_staff( $area_id, $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_admission_area_staff (area_id,user_id) VALUES (%d,%d)", absint( $area_id ), absint( $user_id ) ) );
	}

	public static function remove_staff( $area_id, $user_id ) {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}wpcap_admission_area_staff", array( 'area_id' => absint( $area_id ), 'user_id' => absint( $user_id ) ), array( '%d', '%d' ) );
	}

	/**
	 * Area check for a scan.
	 *
	 * @param array  $found     Ticket context (event_id, ticket_type_id).
	 * @param int    $area_id   Selected/bound area (0 = none).
	 * @param string $direction 'in' or 'out'.
	 * @return string '' when allowed, otherwise 'wrong_area'.
	 */
	public static function check( array $found, $area_id, $direction = 'in' ) {
		$area_id = absint( $area_id );
		if ( $area_id ) {
			$area = self::get( $area_id );
			if ( ! $area || 'active' !== $area->status || (int) $area->event_id !== (int) $found['event_id'] ) { return 'wrong_area'; }
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$mine = self::user_area_ids( get_current_user_id(), $found['event_id'] );
			if ( $mine && ! in_array( $area_id, $mine, true ) ) { return 'wrong_area'; }
		}
		if ( 'in' === $direction ) {
			// [Passes] Filterable so a ticket valid for several events (multi-date pass) can name the
			// allowed areas of the event it is scanned for. Empty = all areas.
			$allowed = array_map( 'intval', (array) apply_filters( 'wpcap_ticket_allowed_areas', self::type_area_ids( $found['ticket_type_id'] ), $found ) );
			if ( $allowed && ! in_array( $area_id, $allowed, true ) ) { return 'wrong_area'; }
		}
		return '';
	}

	/**
	 * Areas the current user may scan in, for the given events (for scanner selection).
	 *
	 * @param int[]|null $event_ids Null = all events the user has check-in access to.
	 * @return object[] area_id, event_id, name, event_name
	 */
	public static function selectable( $event_ids = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = "a.status='active' AND e.status='published'";
		if ( is_array( $event_ids ) ) {
			if ( ! $event_ids ) { return array(); }
			$where .= ' AND a.event_id IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ')';
		}
		$rows = $wpdb->get_results( "SELECT a.area_id,a.event_id,a.name,e.name AS event_name FROM {$p}wpcap_admission_areas a INNER JOIN {$p}wpcap_events e ON e.event_id=a.event_id WHERE $where ORDER BY e.name,a.name LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$admin = current_user_can( 'manage_options' );
		$out = array();
		$mine = array();
		foreach ( $rows as $row ) {
			if ( ! Access::can_access_event( (int) $row->event_id, 'wpcap_checkin' ) ) { continue; }
			if ( ! $admin ) {
				if ( ! isset( $mine[ $row->event_id ] ) ) { $mine[ $row->event_id ] = self::user_area_ids( get_current_user_id(), $row->event_id ); }
				if ( $mine[ $row->event_id ] && ! in_array( (int) $row->area_id, $mine[ $row->event_id ], true ) ) { continue; }
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ---- devices and pairings ---- */

	/** Validates an area chosen for a pairing; returns WP_Error or null. */
	public static function validate_for_pairing( $area_id, array $event_ids ) {
		if ( ! $area_id ) { return null; }
		$area = self::get( $area_id );
		if ( ! $area || 'active' !== $area->status || ! in_array( (int) $area->event_id, array_map( 'intval', $event_ids ), true ) ) {
			return new \WP_Error( 'wpcap_pairing_area', __( 'Der Einlassbereich gehört zu keinem der gewählten Events.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$mine = self::user_area_ids( get_current_user_id(), $area->event_id );
			if ( $mine && ! in_array( (int) $area_id, $mine, true ) ) {
				return new \WP_Error( 'wpcap_pairing_area_forbidden', __( 'Für diesen Einlassbereich bist du nicht eingeteilt.', 'wp-pass-captain' ), array( 'status' => 403 ) );
			}
		}
		return null;
	}

	public static function set_pairing_area( $pairing_id, $area_id ) {
		global $wpdb;
		if ( ! $area_id ) { return; }
		$wpdb->query( $wpdb->prepare( "REPLACE INTO {$wpdb->prefix}wpcap_admission_pairings (pairing_id,area_id) VALUES (%d,%d)", absint( $pairing_id ), absint( $area_id ) ) );
	}

	public static function pairing_area( $pairing_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT area_id FROM {$wpdb->prefix}wpcap_admission_pairings WHERE pairing_id=%d", absint( $pairing_id ) ) );
	}

	public static function set_device_area( $device_id, $area_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_admission_devices (device_id,area_id) VALUES (%d,%d) ON DUPLICATE KEY UPDATE area_id=VALUES(area_id)", absint( $device_id ), absint( $area_id ) ) );
	}

	public static function device_area( $device_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT area_id FROM {$wpdb->prefix}wpcap_admission_devices WHERE device_id=%d", absint( $device_id ) ) );
	}

	/** Remembers an offline sync upload of a device. */
	public static function touch_device_sync( $device_id, $scans ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_admission_devices (device_id,last_sync_at,last_sync_scans) VALUES (%d,%s,%d) ON DUPLICATE KEY UPDATE last_sync_at=VALUES(last_sync_at), last_sync_scans=VALUES(last_sync_scans)", absint( $device_id ), current_time( 'mysql', true ), absint( $scans ) ) );
	}

	/**
	 * Sync status of devices: area, last sync, open conflicts.
	 *
	 * @param int[] $device_ids
	 * @return array<int, array{area_id:int, area_name:string, last_sync_at:?string, last_sync_scans:int, open_conflicts:int}>
	 */
	public static function device_status( array $device_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_filter( array_map( 'absint', $device_ids ) ) );
		$out = array();
		foreach ( $ids as $id ) { $out[ $id ] = array( 'area_id' => 0, 'area_name' => '', 'last_sync_at' => null, 'last_sync_scans' => 0, 'open_conflicts' => 0 ); }
		if ( ! $ids ) { return $out; }
		$list = implode( ',', $ids );
		foreach ( $wpdb->get_results( "SELECT d.device_id,d.area_id,d.last_sync_at,d.last_sync_scans,a.name FROM {$p}wpcap_admission_devices d LEFT JOIN {$p}wpcap_admission_areas a ON a.area_id=d.area_id WHERE d.device_id IN ($list)" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$out[ (int) $row->device_id ] = array_merge( $out[ (int) $row->device_id ], array( 'area_id' => (int) $row->area_id, 'area_name' => (string) $row->name, 'last_sync_at' => $row->last_sync_at, 'last_sync_scans' => (int) $row->last_sync_scans ) );
		}
		foreach ( $wpdb->get_results( "SELECT device_id, COUNT(*) AS n FROM {$p}wpcap_admission_conflicts WHERE status='open' AND device_id IN ($list) GROUP BY device_id" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$out[ (int) $row->device_id ]['open_conflicts'] = (int) $row->n;
		}
		return $out;
	}

	/** Archives or reactivates an area. */
	public static function set_status( $area_id, $status ) {
		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}wpcap_admission_areas", array( 'status' => 'archived' === $status ? 'archived' : 'active' ), array( 'area_id' => absint( $area_id ) ) );
		Audit::record( 'admission_area_status', 'admission_area', absint( $area_id ), array( 'status' => $status ) );
	}
}
