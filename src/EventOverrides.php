<?php
/**
 * Per-event overrides of module settings (SET-PC-33 ticket PDF, SET-PC-34 reminders).
 *
 * One option (not autoloaded) holds event_id => [area => values]; an area without stored values
 * means "use the global setting". Callers validate the values (settings register) before save();
 * this class only stores, reads and removes them. Keys of an area that are missing or '' fall back
 * to the global value in the module that reads them.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class EventOverrides {
	/** Option: event_id => [area => values]. */
	const OPTION = 'wpcap_event_overrides';

	/** Areas that may be overridden per event. */
	const AREAS = array( 'ticket_pdf', 'reminders' );

	/** All stored overrides: event_id => [area => values]. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** Stored values of one event and area ([] = use the global setting). */
	public static function get( $event_id, $area ) {
		$all = self::all();
		$values = $all[ absint( $event_id ) ][ (string) $area ] ?? array();
		return is_array( $values ) ? $values : array();
	}

	/** Event ids with stored values for an area, ascending. */
	public static function events( $area ) {
		$ids = array();
		foreach ( self::all() as $event_id => $areas ) {
			if ( is_array( $areas ) && ! empty( $areas[ (string) $area ] ) ) { $ids[] = (int) $event_id; }
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Stores the values of one event and area (already validated by the caller); [] removes them.
	 *
	 * @return true|\WP_Error
	 */
	public static function save( $event_id, $area, array $values ) {
		$event_id = absint( $event_id );
		$area = (string) $area;
		if ( ! in_array( $area, self::AREAS, true ) ) {
			return new \WP_Error( 'wpcap_event_override_area', __( 'Dieser Bereich kann nicht je Event übersteuert werden.', 'pass-captain' ) );
		}
		if ( ! $event_id || ! self::event_exists( $event_id ) ) {
			return new \WP_Error( 'wpcap_event_override_event', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$all = self::all();
		$before = $all[ $event_id ][ $area ] ?? array();
		if ( $values ) {
			$all[ $event_id ][ $area ] = $values;
		} else {
			unset( $all[ $event_id ][ $area ] );
			if ( empty( $all[ $event_id ] ) ) { unset( $all[ $event_id ] ); }
		}
		update_option( self::OPTION, $all, false );
		if ( $before !== $values ) {
			Audit::record( 'event_override_saved', 'event', $event_id, array( 'area' => $area, 'keys' => array_keys( $values ) ) );
		}
		return true;
	}

	/** Removes every override of an event (e.g. when the event is deleted). */
	public static function forget( $event_id ) {
		$all = self::all();
		if ( ! isset( $all[ absint( $event_id ) ] ) ) { return; }
		unset( $all[ absint( $event_id ) ] );
		update_option( self::OPTION, $all, false );
	}

	private static function event_exists( $event_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', absint( $event_id ) ) );
	}
}
