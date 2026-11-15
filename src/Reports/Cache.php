<?php
/**
 * Short-lived cache of event reports (REST GET /events/{id}/report) with explicit invalidation.
 *
 * Reports are cached per event and date range for CACHE_SECONDS. Every change that affects the
 * numbers invalidates all cached ranges of the affected event right away:
 *
 *  - order paid, cancelled/expired (wpcap_order_cancelled), refunded, tickets issued,
 *    refund completed, capacity released;
 *  - ticket checked in / checked out / revoked;
 *  - occurrence cancelled or rescheduled, event / occurrence / ticket type saved or cancelled
 *    (Admin\EventsPage calls flush_event()).
 *
 * The cached keys of an event are kept in an index transient, so an invalidation only writes when a
 * cached report exists (check-ins on a busy entrance do not write an option per scan).
 */
namespace PassCaptain\Reports;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cache {
	const CACHE_SECONDS = 300;

	private static $registered = false;

	public static function register() {
		if ( self::$registered || ! function_exists( 'add_action' ) ) { return; }
		self::$registered = true;
		foreach ( array( 'wpcap_order_paid', 'wpcap_order_cancelled', 'wpcap_order_refunded', 'wpcap_tickets_issued' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_order' ), 20, 1 );
		}
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'flush_refund' ), 20, 2 );
		add_action( 'wpcap_capacity_released', array( __CLASS__, 'flush_order' ), 20, 1 );
		foreach ( array( 'wpcap_ticket_checked_in', 'wpcap_ticket_checked_out', 'wpcap_ticket_revoked' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_ticket' ), 20, 1 );
		}
		foreach ( array( 'wpcap_occurrence_cancelled', 'wpcap_occurrence_rescheduled' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_occurrence' ), 20, 1 );
		}
	}

	/** Transient key of a report (per site: transients are per site on multisite). */
	public static function key( $event_id, $from, $to ) {
		return 'wpcap_report_' . absint( $event_id ) . '_' . md5( (string) $from . '|' . (string) $to );
	}

	private static function index_key( $event_id ) {
		return 'wpcap_report_idx_' . absint( $event_id );
	}

	/** Cached report or false. */
	public static function get( $event_id, $from, $to ) {
		return get_transient( self::key( $event_id, $from, $to ) );
	}

	public static function set( $event_id, $from, $to, $data ) {
		$key = self::key( $event_id, $from, $to );
		$index = get_transient( self::index_key( $event_id ) );
		$index = is_array( $index ) ? $index : array();
		$index[ $key ] = true;
		// The index outlives its entries, so no cached range is ever missed by an invalidation.
		set_transient( self::index_key( $event_id ), $index, 2 * self::CACHE_SECONDS );
		set_transient( $key, $data, self::CACHE_SECONDS );
	}

	/** Drops every cached report range of an event. */
	public static function flush_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) { return 0; }
		$index = get_transient( self::index_key( $event_id ) );
		if ( ! is_array( $index ) || ! $index ) { return 0; }
		foreach ( array_keys( $index ) as $key ) { delete_transient( $key ); }
		delete_transient( self::index_key( $event_id ) );
		return count( $index );
	}

	public static function flush_order( $order_id ) {
		foreach ( Scope::order_events( $order_id ) as $event_id ) { self::flush_event( $event_id ); }
	}

	public static function flush_refund( $refund_id, $order_id = 0 ) {
		self::flush_order( $order_id );
	}

	public static function flush_ticket( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $ticket_id ) ) );
		self::flush_event( $event_id );
	}

	public static function flush_occurrence( $occurrence_id ) {
		self::flush_event( Scope::occurrence_event( $occurrence_id ) );
	}
}
