<?php
/**
 * Exchange policy: global settings (Settings key "exchange") with optional per-event overrides
 * (table wpcap_exchange_policies; NULL = use the global value).
 */
namespace PassCaptain\Exchange;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Policy {
	/** Keys that an event may override. */
	const EVENT_KEYS = array( 'enabled', 'portal_enabled', 'portal_hours', 'portal_max_per_ticket', 'refund_policy', 'fee_minor' );

	public static function defaults() {
		return array(
			'enabled' => 1,               // Staff (admin, box office) may exchange tickets.
			'portal_enabled' => 0,        // Buyers may exchange in the order portal.
			'portal_hours' => 24,         // Self-service until X hours before the start (old and new date).
			'portal_max_per_ticket' => 1, // Self-service exchanges per ticket (whole chain).
			'refund_policy' => 'refund',  // Cheaper target: 'refund' the difference or 'none'.
			'fee_minor' => 0,             // Exchange fee (gross, minor units).
			'allow_series' => 1,          // Staff may exchange into other events of the same series.
		);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_exchange_policies';
	}

	/** Global policy (settings merged with defaults). */
	public static function global_policy() {
		return self::sanitize( array_merge( self::defaults(), (array) Settings::get( 'exchange' ) ) );
	}

	/** Stored overrides of an event (only keys that are set). */
	public static function event_overrides( $event_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE event_id=%d', absint( $event_id ) ), ARRAY_A );
		if ( ! $row ) { return array(); }
		$out = array();
		foreach ( self::EVENT_KEYS as $key ) {
			if ( isset( $row[ $key ] ) && null !== $row[ $key ] && '' !== $row[ $key ] ) { $out[ $key ] = $row[ $key ]; }
		}
		return $out;
	}

	/** Effective policy of an event. */
	public static function for_event( $event_id ) {
		return self::sanitize( array_merge( self::global_policy(), self::event_overrides( $event_id ) ) );
	}

	public static function sanitize( array $in ) {
		$d = self::defaults();
		return array(
			'enabled' => empty( $in['enabled'] ) ? 0 : 1,
			'portal_enabled' => empty( $in['portal_enabled'] ) ? 0 : 1,
			'portal_hours' => min( 8760, absint( $in['portal_hours'] ?? $d['portal_hours'] ) ),
			'portal_max_per_ticket' => min( 100, absint( $in['portal_max_per_ticket'] ?? $d['portal_max_per_ticket'] ) ),
			'refund_policy' => 'none' === ( $in['refund_policy'] ?? '' ) ? 'none' : 'refund',
			'fee_minor' => min( 100000000, absint( $in['fee_minor'] ?? 0 ) ),
			'allow_series' => empty( $in['allow_series'] ) ? 0 : 1,
		);
	}

	/** Saves the global policy (callers check capabilities). */
	public static function save_global( array $in ) {
		$clean = self::sanitize( array_merge( self::global_policy(), $in ) );
		Settings::update( array( 'exchange' => $clean ) );
		Audit::record( 'exchange_policy_saved', 'settings', 0, $clean );
		return $clean;
	}

	/**
	 * Saves the overrides of an event. Keys missing or null in $in fall back to the global policy.
	 *
	 * @return array|\WP_Error Stored overrides.
	 */
	public static function save_event( $event_id, array $in ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! $event_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) {
			return new \WP_Error( 'wpcap_exchange_event', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$row = array( 'event_id' => $event_id, 'updated_at' => current_time( 'mysql', true ) );
		$clean = self::sanitize( array_merge( self::defaults(), array_filter( $in, static function ( $v ) { return null !== $v && '' !== $v; } ) ) );
		foreach ( self::EVENT_KEYS as $key ) {
			$row[ $key ] = array_key_exists( $key, $in ) && null !== $in[ $key ] && '' !== $in[ $key ] ? $clean[ $key ] : null;
		}
		$wpdb->delete( self::table(), array( 'event_id' => $event_id ) );
		$wpdb->insert( self::table(), $row );
		Audit::record( 'exchange_policy_saved', 'event', $event_id, $row );
		return self::event_overrides( $event_id );
	}
}
