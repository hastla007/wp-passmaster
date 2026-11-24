<?php
/**
 * Takes plugin access back when a WordPress user loses a role or is deleted (A277, A278).
 *
 * The event team (wpcap_event_staff) only narrows what a role allows, but check-in helpers without a
 * plugin role get wpcap_checkin as a user capability from the staff assignment (EventsPage::assign_staff()).
 * That capability, the assignment rows and paired scanner devices would otherwise outlive a role change.
 *
 * - A role is taken away (set_user_role with a role that is no longer there, remove_user_role):
 *   the check-in capability granted by the staff assignment is removed; event-team entries (with their
 *   occurrence assignments) for capabilities the user no longer holds are deleted; without
 *   wpcap_checkin the user's scanner devices are revoked, open pairing codes deleted and entry-area
 *   assignments removed. A user whose remaining roles still grant a capability keeps its entries.
 *   Developer API keys (DeveloperKeys) whose event the owner may no longer read are revoked (A278).
 *   Adding a role (or creating the user) changes nothing.
 * - The user is deleted or removed from the site (multisite): all of the above for every capability.
 *
 * Every revocation is audited (user_access_revoked). Filter `wpcap_revoke_access_on_role_change`
 * (bool, user id, removed roles) can keep the access for a role change, e.g. when an external role
 * manager replaces roles in two steps.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class UserAccess {
	/** Capability the staff assignment grants as a user capability. */
	const CHECKIN_CAP = 'wpcap_checkin';

	public static function register() {
		// After the AccessLog role hooks (priority 10), so the role change is logged before the revocation.
		add_action( 'set_user_role', array( __CLASS__, 'role_set' ), 20, 3 );
		add_action( 'remove_user_role', array( __CLASS__, 'role_removed' ), 20, 2 );
		add_action( 'delete_user', array( __CLASS__, 'user_deleted' ), 10, 1 );
		// Multisite: fires after switch_to_blog() to the site the user is removed from.
		add_action( 'remove_user_from_blog', array( __CLASS__, 'user_deleted' ), 10, 1 );
	}

	/** set_user_role: revokes when one of the previous roles is gone. */
	public static function role_set( $user_id, $role, $old_roles = array() ) {
		$removed = array_values( array_diff( array_map( 'strval', (array) $old_roles ), array( (string) $role ) ) );
		if ( ! $removed ) { return; }
		self::role_lost( (int) $user_id, $removed );
	}

	/** remove_user_role: a role was taken away. */
	public static function role_removed( $user_id, $role ) {
		self::role_lost( (int) $user_id, array( (string) $role ) );
	}

	/** delete_user / remove_user_from_blog: everything goes. */
	public static function user_deleted( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return array(); }
		$done = self::revoke( $user_id, null );
		self::audit( $user_id, 'user_deleted', $done );
		return $done;
	}

	private static function role_lost( $user_id, array $removed ) {
		if ( $user_id <= 0 || ! apply_filters( 'wpcap_revoke_access_on_role_change', true, $user_id, $removed ) ) { return array(); }
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : null;
		if ( ! $user ) { return self::user_deleted( $user_id ); }
		$done = array();
		// The staff assignment's user capability must not survive the role change (the user keeps
		// wpcap_checkin when a remaining role grants it; has_cap() below sees that).
		if ( ! empty( $user->caps[ self::CHECKIN_CAP ] ) && method_exists( $user, 'remove_cap' ) ) {
			$user->remove_cap( self::CHECKIN_CAP );
			$done['user_cap'] = array( self::CHECKIN_CAP );
		}
		$done += self::revoke( $user_id, $user );
		self::audit( $user_id, 'role_removed', $done, array( 'roles' => array_map( 'sanitize_key', $removed ) ) );
		return $done;
	}

	/** Whether the user (object) holds the capability (WP_User::has_cap(), like user_can()). */
	public static function holds( $user, $capability ) {
		if ( ! $user ) { return false; }
		return method_exists( $user, 'has_cap' ) ? (bool) $user->has_cap( $capability ) : user_can( $user, $capability );
	}

	/**
	 * Removes event-team entries, devices, pairings and area assignments the user no longer may hold.
	 *
	 * @param int         $user_id User.
	 * @param object|null $user    User object (capabilities checked) or null (revoke everything).
	 * @return array What was revoked (for the audit entry).
	 */
	private static function revoke( $user_id, $user ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$done = array();
		if ( Privacy::has_table( 'wpcap_event_staff' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_id,capability FROM {$p}wpcap_event_staff WHERE user_id=%d", $user_id ) );
			foreach ( $rows as $row ) {
				if ( $user && self::holds( $user, (string) $row->capability ) ) { continue; }
				$wpdb->delete( "{$p}wpcap_event_staff", array( 'event_id' => (int) $row->event_id, 'user_id' => $user_id, 'capability' => (string) $row->capability ), array( '%d', '%d', '%s' ) );
				StaffAssignments::delete_for_team( (int) $row->event_id, $user_id, (string) $row->capability );
				$done['staff'][] = array( 'event_id' => (int) $row->event_id, 'capability' => (string) $row->capability );
			}
			if ( ! empty( $done['staff'] ) ) { StaffAssignments::flush(); }
		}
		// Developer API keys (A278): checked after the team entries, which they depend on.
		$keys = DeveloperKeys::revoke_for_user( $user_id, $user );
		if ( $keys ) { $done['api_keys'] = $keys; }
		if ( $user && self::holds( $user, self::CHECKIN_CAP ) ) { return $done; }
		if ( Privacy::has_table( 'wpcap_scanner_devices' ) ) {
			$devices = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT device_id FROM {$p}wpcap_scanner_devices WHERE user_id=%d AND revoked_at IS NULL", $user_id ) ) );
			if ( $devices ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_scanner_devices SET revoked_at=%s WHERE user_id=%d AND revoked_at IS NULL", Clock::mysql(), $user_id ) );
				$done['devices'] = $devices;
			}
		}
		if ( Privacy::has_table( 'wpcap_device_pairings' ) ) {
			$pairings = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_device_pairings WHERE user_id=%d AND used_at IS NULL", $user_id ) );
			if ( $pairings > 0 ) { $done['pairings'] = $pairings; }
		}
		if ( Privacy::has_table( 'wpcap_admission_area_staff' ) ) {
			$areas = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_admission_area_staff WHERE user_id=%d", $user_id ) );
			if ( $areas > 0 ) { $done['areas'] = $areas; }
		}
		return $done;
	}

	private static function audit( $user_id, $reason, array $done, array $extra = array() ) {
		if ( ! $done ) { return; }
		Audit::record( 'user_access_revoked', 'user', (int) $user_id, array( 'reason' => $reason ) + $extra + $done );
	}
}
