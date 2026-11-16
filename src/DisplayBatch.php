<?php
/**
 * Request-local batch scope for read-only display pages (event page, GET /events, box office list).
 *
 * Inside DisplayBatch::run() the ticket types and occurrences of the page are known up front, so the
 * per-type lookups (Orders::availability(), waitlist holds, series pools, price rules) answer from a
 * few grouped queries (IN … GROUP BY) instead of several queries per ticket type. The data is only
 * used while the callback runs: nothing is cached across requests or after the batch, so writes
 * (checkout, refunds) never see it. Results are the same as the per-type code paths.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DisplayBatch {
	/** @var array|null Current batch: prefix, now, types (id => row), occurrences (id => row), memo. */
	private static $batch = null;

	/**
	 * Runs $callback with the given ticket types/occurrences preloaded for display lookups.
	 *
	 * @param int[]    $type_ids       Ticket type ids shown on the page.
	 * @param callable $callback       Work to do (receives nothing); its return value is returned.
	 * @return mixed
	 */
	public static function run( array $type_ids, callable $callback ) {
		global $wpdb;
		$outer = self::$batch;
		$type_ids = array_values( array_unique( array_filter( array_map( 'absint', $type_ids ) ) ) );
		self::$batch = array(
			'prefix' => $wpdb->prefix,
			'now' => current_time( 'mysql', true ),
			'types' => array(),
			'occurrences' => array(),
			'memo' => array(),
		);
		try {
			if ( $type_ids ) {
				$p = $wpdb->prefix;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ")", array_merge( array( $p . 'wpcap_ticket_types' ), $type_ids ) ) ) as $row ) {
					self::$batch['types'][ (int) $row->ticket_type_id ] = $row;
				}
				$occ_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, self::$batch['types'] ) ) );
				if ( $occ_ids ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
					foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ")", array_merge( array( $p . 'wpcap_occurrences' ), $occ_ids ) ) ) as $row ) {
						self::$batch['occurrences'][ (int) $row->occurrence_id ] = $row;
					}
				}
			}
			return $callback();
		} finally {
			self::$batch = $outer;
		}
	}

	/** True while a batch for the current site (table prefix) runs. */
	public static function active() {
		global $wpdb;
		return null !== self::$batch && self::$batch['prefix'] === $wpdb->prefix;
	}

	/** Preloaded ticket type row (SELECT *) or null when the type is not part of the batch. */
	public static function type( $ticket_type_id ) {
		return self::active() ? ( self::$batch['types'][ absint( $ticket_type_id ) ] ?? null ) : null;
	}

	/** Preloaded occurrence row (SELECT *) or null. */
	public static function occurrence( $occurrence_id ) {
		return self::active() ? ( self::$batch['occurrences'][ absint( $occurrence_id ) ] ?? null ) : null;
	}

	/** @return object[] id => ticket type row. */
	public static function types() {
		return self::active() ? self::$batch['types'] : array();
	}

	/** @return object[] id => occurrence row. */
	public static function occurrences() {
		return self::active() ? self::$batch['occurrences'] : array();
	}

	/** UTC time the batch started (one "now" for every lookup of the page). */
	public static function now() {
		return self::active() ? self::$batch['now'] : current_time( 'mysql', true );
	}

	/**
	 * Value of a named bucket, computed once per batch by $load (e.g. one grouped query for every
	 * type of the page). Outside a batch $load runs every time.
	 *
	 * @return mixed
	 */
	public static function memo( $bucket, callable $load ) {
		if ( ! self::active() ) { return $load(); }
		if ( ! array_key_exists( $bucket, self::$batch['memo'] ) ) {
			self::$batch['memo'][ $bucket ] = $load();
		}
		return self::$batch['memo'][ $bucket ];
	}

	/**
	 * Capacity usage of the batch: paid units (minus refunded) and active unexpired holds, per ticket
	 * type and per occurrence (all types of the occurrence). Same definitions as Orders::availability();
	 * the *_with_order sums leave out holds without order (as Series\Pools::used() does).
	 *
	 * @return array{type_sold:int[],type_held:int[],type_held_with_order:int[],occ_sold:int[],occ_held:int[],occ_held_with_order:int[]}
	 */
	public static function usage() {
		return self::memo( 'usage', static function () {
			global $wpdb;
			$p = $wpdb->prefix;
			$now = self::now();
			$out = array( 'type_sold' => array(), 'type_held' => array(), 'type_held_with_order' => array(), 'occ_sold' => array(), 'occ_held' => array(), 'occ_held_with_order' => array() );
			$type_ids = array_keys( self::types() );
			$occ_ids = array_keys( self::occurrences() );
			if ( $type_ids ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT oi.ticket_type_id AS id, COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) AS n FROM %i oi INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ") AND ord.payment_status='paid' AND ord.status <> 'refunded' GROUP BY oi.ticket_type_id", array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_orders' ), $type_ids ) ) ) as $row ) {
					$out['type_sold'][ (int) $row->id ] = (int) $row->n;
				}
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id AS id, COALESCE(SUM(quantity),0) AS n, COALESCE(SUM(CASE WHEN order_id <> 0 THEN quantity ELSE 0 END),0) AS o FROM %i WHERE ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ") AND status='active' AND expires_at > %s GROUP BY ticket_type_id", array_merge( array( $p . 'wpcap_reservations' ), $type_ids, array( $now ) ) ) ) as $row ) {
					$out['type_held'][ (int) $row->id ] = (int) $row->n;
					$out['type_held_with_order'][ (int) $row->id ] = (int) $row->o;
				}
				// phpcs:enable
			}
			if ( $occ_ids ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT tt.occurrence_id AS id, COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) AS n FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ") AND ord.payment_status='paid' AND ord.status <> 'refunded' GROUP BY tt.occurrence_id", array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_orders' ), $occ_ids ) ) ) as $row ) {
					$out['occ_sold'][ (int) $row->id ] = (int) $row->n;
				}
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT tt.occurrence_id AS id, COALESCE(SUM(r.quantity),0) AS n, COALESCE(SUM(CASE WHEN r.order_id <> 0 THEN r.quantity ELSE 0 END),0) AS o FROM %i r INNER JOIN %i tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ") AND r.status='active' AND r.expires_at > %s GROUP BY tt.occurrence_id", array_merge( array( $p . 'wpcap_reservations', $p . 'wpcap_ticket_types' ), $occ_ids, array( $now ) ) ) ) as $row ) {
					$out['occ_held'][ (int) $row->id ] = (int) $row->n;
					$out['occ_held_with_order'][ (int) $row->id ] = (int) $row->o;
				}
				// phpcs:enable
			}
			return $out;
		} );
	}
}
