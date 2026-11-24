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

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Assignments {
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_offline_assignments';
	}

	public static function device( $device_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE device_id=%d', $wpdb->prefix . 'wpcap_scanner_devices', absint( $device_id ) ) );
	}

	/** Device is usable: not revoked, not expired. */
	public static function device_active( $device ) {
		return $device && ! $device->revoked_at && ( ! $device->expires_at || $device->expires_at > \PassCaptain\Clock::mysql() );
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
		if ( $occurrence_id && $event_id !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) ) ) {
			return new \WP_Error( 'wpcap_offline_occurrence', __( 'Der Termin gehört nicht zu diesem Event.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (device_id,event_id,occurrence_id,assigned_by,created_at) VALUES (%d,%d,%d,%d,%s)', self::table(), (int) $device->device_id, $event_id, $occurrence_id, get_current_user_id(), \PassCaptain\Clock::mysql() ) );
		Audit::record( 'offline_device_assigned', 'device', (int) $device->device_id, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id ) );
		return array( 'device_id' => (int) $device->device_id, 'event_id' => $event_id, 'occurrence_id' => $occurrence_id );
	}

	/**
	 * A597: a freshly paired device gets the events of its pairing for offline use right away (event-wide), when
	 * offline admission is switched on for the event – no second step in the admin. Recorded as assigned by the
	 * person who created the pairing.
	 *
	 * @param int[] $event_ids Events of the pairing.
	 * @return int[] Events assigned.
	 */
	public static function assign_paired( $device_id, array $event_ids, $by_user ) {
		global $wpdb;
		$done = array();
		foreach ( array_unique( array_map( 'absint', $event_ids ) ) as $event_id ) {
			if ( ! $event_id || ! Rules::enabled( $event_id ) ) { continue; }
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (device_id,event_id,occurrence_id,assigned_by,created_at) VALUES (%d,%d,0,%d,%s)', self::table(), absint( $device_id ), $event_id, absint( $by_user ), \PassCaptain\Clock::mysql() ) );
			$done[] = $event_id;
		}
		if ( $done ) { Audit::record( 'offline_device_assigned', 'device', absint( $device_id ), array( 'event_ids' => $done, 'occurrence_id' => 0, 'via' => 'pairing' ) ); }
		return $done;
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
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE device_id=%d AND event_id=%d AND occurrence_id IN (0,%d) ORDER BY occurrence_id DESC LIMIT 1', self::table(), absint( $device_id ), absint( $event_id ), absint( $occurrence_id ) ) );
	}

	public static function touch( $row ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_package_at' => \PassCaptain\Clock::mysql() ), array( 'device_id' => (int) $row->device_id, 'event_id' => (int) $row->event_id, 'occurrence_id' => (int) $row->occurrence_id ) );
	}

	/**
	 * A688: end of validity of the offline package fetched for an assignment row (last_package_at plus the
	 * event's max_offline_hours, the `expires_at` signed into the package), UTC; '' without a package.
	 */
	public static function package_expiry( $row, $hours = null ) {
		$fetched = (string) ( $row->last_package_at ?? '' );
		if ( '' === $fetched ) { return ''; }
		$hours = null === $hours ? (int) Rules::get( (int) $row->event_id )['max_offline_hours'] : (int) $hours;
		return gmdate( 'Y-m-d H:i:s', strtotime( $fetched . ' UTC' ) + max( 1, $hours ) * HOUR_IN_SECONDS );
	}

	/**
	 * A688: offline package state per device for the device list: assignments, packages fetched, the next
	 * expiry of a still valid package (`valid_until`), the latest expiry of the expired ones (`expired_at`)
	 * and whether every fetched package has expired. One query for all devices.
	 *
	 * @param int[] $device_ids Devices.
	 * @return array<int,array{assignments:int,packages:int,valid_until:string,expired_at:string,all_expired:bool}>
	 */
	public static function package_status( array $device_ids ) {
		global $wpdb;
		$device_ids = array_values( array_unique( array_filter( array_map( 'absint', $device_ids ) ) ) );
		$out = array();
		foreach ( $device_ids as $id ) { $out[ $id ] = array( 'assignments' => 0, 'packages' => 0, 'valid_until' => '', 'expired_at' => '', 'all_expired' => false ); }
		if ( ! $device_ids ) { return $out; }
		$rows = $wpdb->get_results( 'SELECT device_id,event_id,occurrence_id,last_package_at FROM ' . self::table() . ' WHERE device_id IN (' . implode( ',', $device_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix, integer list.
		$now = \PassCaptain\Clock::mysql();
		$hours = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->device_id;
			++$out[ $id ]['assignments'];
			if ( ! $row->last_package_at ) { continue; }
			$event = (int) $row->event_id;
			if ( ! isset( $hours[ $event ] ) ) { $hours[ $event ] = (int) Rules::get( $event )['max_offline_hours']; }
			$expiry = self::package_expiry( $row, $hours[ $event ] );
			++$out[ $id ]['packages'];
			if ( $expiry > $now ) {
				if ( '' === $out[ $id ]['valid_until'] || $expiry < $out[ $id ]['valid_until'] ) { $out[ $id ]['valid_until'] = $expiry; }
			} elseif ( $expiry > $out[ $id ]['expired_at'] ) {
				$out[ $id ]['expired_at'] = $expiry;
			}
		}
		foreach ( $out as $id => $status ) { $out[ $id ]['all_expired'] = $status['packages'] > 0 && '' === $status['valid_until']; }
		return $out;
	}

	/** All assignment rows of a device. */
	public static function for_device( $device_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE device_id=%d ORDER BY event_id, occurrence_id', self::table(), absint( $device_id ) ) );
	}

	/** Assignment rows of an event with device info. */
	public static function for_event( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT a.*,d.label,d.platform,d.revoked_at,d.expires_at,d.last_seen_at FROM %i a LEFT JOIN %i d ON d.device_id=a.device_id WHERE a.event_id=%d ORDER BY a.device_id, a.occurrence_id', $p . 'wpcap_offline_assignments', $p . 'wpcap_scanner_devices', absint( $event_id ) ) );
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
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Occurrence::end_sql() returns a static SQL expression (sanitized alias, integer duration).
			'SELECT DISTINCT o.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.ends_at,o.timezone,o.status FROM %i a INNER JOIN %i r ON r.event_id=a.event_id AND r.enabled=1 INNER JOIN %i o ON o.event_id=a.event_id AND ( a.occurrence_id=0 OR a.occurrence_id=o.occurrence_id ) INNER JOIN %i e ON e.event_id=o.event_id WHERE a.device_id=%d AND ' . \PassCaptain\Occurrence::end_sql( 'o' ) . ' >= %s AND a.event_id IN (' . implode( ',', array_fill( 0, count( $events ), '%d' ) ) . ') ORDER BY o.starts_at LIMIT 100', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Occurrence::end_sql() returns a static SQL expression (sanitized alias, integer duration), A606 effective end.
			array_merge(
				array(
					$p . 'wpcap_offline_assignments',
					$p . 'wpcap_offline_events',
					$p . 'wpcap_occurrences',
					$p . 'wpcap_events',
					(int) $device->device_id,
					gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - DAY_IN_SECONDS ),
				),
				array_values( array_map( 'absint', $events ) )
			)
		) );
		return $rows;
	}

	/** Active devices paired for an event (candidates for assignment). */
	public static function candidates( $event_id ) {
		global $wpdb;
		$out = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT d.* FROM %i d WHERE d.revoked_at IS NULL AND (d.expires_at IS NULL OR d.expires_at>%s) ORDER BY d.device_id DESC LIMIT 500', $wpdb->prefix . 'wpcap_scanner_devices', \PassCaptain\Clock::mysql() ) );
		foreach ( $rows as $row ) {
			if ( in_array( absint( $event_id ), Devices::event_ids( $row ), true ) ) { $out[] = $row; }
		}
		return $out;
	}
}
