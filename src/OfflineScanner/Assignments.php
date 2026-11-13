<?php
/**
 * Device ↔ event/occurrence assignment for offline use.
 *
 * A paired device (wpcap_scanner_devices) only receives offline packages for occurrences it is
 * explicitly assigned to: either all occurrences of an event (occurrence_id 0) or single ones.
 * The event must also be among the device's paired events (event_ids from the pairing).
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Audit;
use PassCaptain\Mobile\Devices;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Assignments {
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_offline_assignments';
	}

	public static function device( $device_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_scanner_devices WHERE device_id=%d", absint( $device_id ) ) );
	}

	/** Device is usable: not revoked, not expired. */
	public static function device_active( $device ) {
		return $device && ! $device->revoked_at && ( ! $device->expires_at || $device->expires_at > current_time( 'mysql', true ) );
	}

	/**
	 * Assigns a device to an event (all occurrences) or one occurrence (caller checks permissions).
	 *
	 * @return array|\WP_Error
	 */
	public static function assign( $device_id, $event_id, $occurrence_id = 0 ) {
		global $wpdb;
		$device = self::device( $device_id );
		if ( ! self::device_active( $device ) ) {
			return new \WP_Error( 'wpcap_offline_device', __( 'Gerät nicht gefunden, abgelaufen oder widerrufen.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$event_id = absint( $event_id );
		if ( ! in_array( $event_id, Devices::event_ids( $device ), true ) ) {
			return new \WP_Error( 'wpcap_offline_device_event', __( 'Das Gerät ist nicht für dieses Event gekoppelt.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$occurrence_id = absint( $occurrence_id );
		if ( $occurrence_id && $event_id !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) ) ) {
			return new \WP_Error( 'wpcap_offline_occurrence', __( 'Der Termin gehört nicht zu diesem Event.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (device_id,event_id,occurrence_id,assigned_by,created_at) VALUES (%d,%d,%d,%d,%s)', (int) $device->device_id, $event_id, $occurrence_id, get_current_user_id(), current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Audit::record( 'offline_device_assigned', 'device', (int) $device->device_id, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id ) );
		return array( 'device_id' => (int) $device->device_id, 'event_id' => $event_id, 'occurrence_id' => $occurrence_id );
	}

	public static function unassign( $device_id, $event_id, $occurrence_id = 0 ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'device_id' => absint( $device_id ), 'event_id' => absint( $event_id ), 'occurrence_id' => absint( $occurrence_id ) ), array( '%d', '%d', '%d' ) );
		Audit::record( 'offline_device_unassigned', 'device', absint( $device_id ), array( 'event_id' => absint( $event_id ), 'occurrence_id' => absint( $occurrence_id ) ) );
		return array( 'device_id' => absint( $device_id ), 'event_id' => absint( $event_id ) );
	}

	/** Assignment row covering this occurrence (event-wide or exact), or null. */
	public static function find( $device_id, $event_id, $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE device_id=%d AND event_id=%d AND occurrence_id IN (0,%d) ORDER BY occurrence_id DESC LIMIT 1', absint( $device_id ), absint( $event_id ), absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function touch( $row ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_package_at' => current_time( 'mysql', true ) ), array( 'device_id' => (int) $row->device_id, 'event_id' => (int) $row->event_id, 'occurrence_id' => (int) $row->occurrence_id ) );
	}

	/** All assignment rows of a device. */
	public static function for_device( $device_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE device_id=%d ORDER BY event_id, occurrence_id', absint( $device_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Assignment rows of an event with device info. */
	public static function for_event( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( "SELECT a.*,d.label,d.platform,d.revoked_at,d.expires_at,d.last_seen_at FROM {$p}wpcap_offline_assignments a LEFT JOIN {$p}wpcap_scanner_devices d ON d.device_id=a.device_id WHERE a.event_id=%d ORDER BY a.device_id, a.occurrence_id", absint( $event_id ) ) );
	}

	/**
	 * Occurrences a device may fetch packages for: assigned, event opted in and paired, from yesterday on.
	 *
	 * @return object[] occurrence_id, event_id, event_name, starts_at, ends_at, timezone, status
	 */
	public static function occurrences_for_device( $device ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$events = Devices::event_ids( $device );
		if ( ! $events ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT o.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.ends_at,o.timezone,o.status FROM {$p}wpcap_offline_assignments a INNER JOIN {$p}wpcap_offline_events r ON r.event_id=a.event_id AND r.enabled=1 INNER JOIN {$p}wpcap_occurrences o ON o.event_id=a.event_id AND ( a.occurrence_id=0 OR a.occurrence_id=o.occurrence_id ) INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE a.device_id=%d AND a.event_id IN (" . implode( ',', array_map( 'absint', $events ) ) . ') AND COALESCE(o.ends_at,o.starts_at) >= %s ORDER BY o.starts_at LIMIT 100', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			(int) $device->device_id,
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
		return $rows;
	}

	/** Active devices paired for an event (candidates for assignment). */
	public static function candidates( $event_id ) {
		global $wpdb;
		$out = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT d.* FROM {$wpdb->prefix}wpcap_scanner_devices d WHERE d.revoked_at IS NULL AND (d.expires_at IS NULL OR d.expires_at>%s) ORDER BY d.device_id DESC LIMIT 500", current_time( 'mysql', true ) ) );
		foreach ( $rows as $row ) {
			if ( in_array( absint( $event_id ), Devices::event_ids( $row ), true ) ) { $out[] = $row; }
		}
		return $out;
	}
}
