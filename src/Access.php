<?php
/**
 * Event- and occurrence-scoped capability checks.
 *
 * Event level (wpcap_event_staff): access to the whole event. Occurrence level
 * (wpcap_occurrence_staff): access to single dates only – used for admission (check-in/out,
 * scanner, attendee lists of that date); it never grants event-wide access.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Access {
	public static function can_access_event( $event_id, $capability ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( $capability ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$assigned = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE event_id = %d AND user_id = %d AND capability = %s LIMIT 1', $wpdb->prefix . 'wpcap_event_staff',
				absint( $event_id ),
				$user_id,
				sanitize_key( $capability )
			)
		);
		// Time-limited support access (Security\SupportAccess) covers every event while it is active.
		return $assigned || Security\SupportAccess::covers( $user_id, $capability );
	}

	/**
	 * Access to one occurrence (date): event-level assignment or an assignment for exactly this date.
	 *
	 * @param int    $occurrence_id Occurrence.
	 * @param string $capability    Capability the user must also hold through a role.
	 * @param int    $event_id      Event of the occurrence when already known (saves a query).
	 */
	public static function can_access_occurrence( $occurrence_id, $capability, $event_id = 0 ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( $capability ) ) { return false; }
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) );
		}
		if ( $event_id && self::can_access_event( $event_id, $capability ) ) { return true; }
		return self::has_occurrence_assignment( $occurrence_id, $capability );
	}

	/** Whether the current user holds the capability and is assigned to exactly this date. */
	public static function has_occurrence_assignment( $occurrence_id, $capability ) {
		$user_id = get_current_user_id();
		$occurrence_id = absint( $occurrence_id );
		if ( ! $user_id || ! $occurrence_id || ! current_user_can( $capability ) ) { return false; }
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE occurrence_id=%d AND user_id=%d AND capability=%s LIMIT 1', $wpdb->prefix . 'wpcap_occurrence_staff', $occurrence_id, $user_id, sanitize_key( $capability ) ) );
	}

	/**
	 * Whether the user may work on at least one date of the event (event-level or occurrence-level).
	 * For lists and device pairing; every single scan is still checked per occurrence.
	 */
	public static function can_access_event_any_date( $event_id, $capability ) {
		if ( self::can_access_event( $event_id, $capability ) ) { return true; }
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( $capability ) ) { return false; }
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE event_id=%d AND user_id=%d AND capability=%s LIMIT 1', $wpdb->prefix . 'wpcap_occurrence_staff', absint( $event_id ), $user_id, sanitize_key( $capability ) ) );
	}

	/**
	 * Occurrence ids of an event the user may access: null = the whole event, [] = none.
	 *
	 * @return int[]|null
	 */
	public static function occurrence_scope( $event_id, $capability ) {
		if ( self::can_access_event( $event_id, $capability ) ) { return null; }
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( $capability ) ) { return array(); }
		global $wpdb;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE event_id=%d AND user_id=%d AND capability=%s', $wpdb->prefix . 'wpcap_occurrence_staff', absint( $event_id ), $user_id, sanitize_key( $capability ) ) ) );
	}

	/** Event ids with at least one date assignment of the current user for the capability. */
	public static function occurrence_event_ids( $capability ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return array(); }
		global $wpdb;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_id FROM %i WHERE user_id=%d AND capability=%s', $wpdb->prefix . 'wpcap_occurrence_staff', $user_id, sanitize_key( $capability ) ) ) );
	}
}
