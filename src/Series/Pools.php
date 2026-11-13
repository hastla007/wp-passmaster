<?php
/**
 * Shared quota pools across the occurrences of a series, e.g. "max 500 tickets across all dates"
 * or "200 student tickets across the series" (pool limited to ticket types named "Student").
 *
 * Usage is derived, never counted: paid units (minus refunded/revoked) plus active, unexpired
 * holds of all member ticket types – exactly like core capacity. Expiry, cancellation, refunds and
 * revocations therefore release pool capacity automatically.
 *
 * Race freedom: the core calls `wpcap_reserve_capacity` inside the order transaction after locking
 * ticket types and occurrences and before its first consistent read. reserve() uses locking reads
 * only until the pool rows are locked FOR UPDATE (ordered by pool_id), then reads usage. Every
 * order or late payment that adds pool usage serialises on the pool row, and the transaction's
 * snapshot is taken after that lock, so it sees all committed holds.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Series;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pools {
	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_series_pools (
				pool_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				series_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				type_name varchar(190) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (pool_id),
				KEY series_status (series_id,status)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function register() {
		add_filter( 'wpcap_reserve_capacity', array( __CLASS__, 'reserve' ), 10, 3 );
		add_filter( 'wpcap_ticket_availability', array( __CLASS__, 'availability' ), 10, 3 );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Storage.
	 * ------------------------------------------------------------------------------------------- */

	public static function for_series( $series_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_series_pools WHERE series_id=%d AND status='active' ORDER BY pool_id", absint( $series_id ) ) );
	}

	/** Creates a pool. Input: name, type_name ('' = all types), capacity (≥ 1). @return int|\WP_Error */
	public static function create( $series_id, array $in ) {
		global $wpdb;
		$series = $wpdb->get_row( $wpdb->prepare( "SELECT series_id,event_id FROM {$wpdb->prefix}wpcap_series WHERE series_id=%d", absint( $series_id ) ) );
		if ( ! $series ) { return self::error( 'wpcap_series_missing', __( 'Serie nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $series->event_id, Generator::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$capacity = is_scalar( $in['capacity'] ?? null ) && preg_match( '/^\d{1,7}$/', trim( (string) $in['capacity'] ) ) ? (int) $in['capacity'] : 0;
		if ( $capacity < 1 ) { return self::error( 'wpcap_series_pool_capacity', __( 'Bitte ein Kontingent von mindestens 1 angeben.', 'pass-captain' ) ); }
		$name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		$type_name = mb_substr( sanitize_text_field( (string) ( $in['type_name'] ?? '' ) ), 0, 190 );
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( "{$wpdb->prefix}wpcap_series_pools", array( 'series_id' => (int) $series->series_id, 'name' => '' !== $name ? $name : __( 'Serienkontingent', 'pass-captain' ), 'type_name' => $type_name, 'capacity' => $capacity, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		if ( ! $ok ) { return self::error( 'wpcap_series_pool_save', __( 'Das Kontingent konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$pool_id = (int) $wpdb->insert_id;
		// A114: series-scoped entry (change history of the series).
		\PassCaptain\Audit::record( 'series_pool_created', 'series', (int) $series->series_id, array( 'event_id' => (int) $series->event_id, 'series_id' => (int) $series->series_id, 'pool_id' => $pool_id, 'name' => '' !== $name ? $name : __( 'Serienkontingent', 'pass-captain' ), 'type_name' => $type_name, 'capacity' => $capacity ) );
		return $pool_id;
	}

	/** Changes the capacity of a pool (may be below current usage: then nothing more is sold). */
	public static function update_capacity( $pool_id, $capacity ) {
		global $wpdb;
		$pool = self::pool_with_event( $pool_id );
		if ( is_wp_error( $pool ) ) { return $pool; }
		$capacity = absint( $capacity );
		if ( $capacity < 1 ) { return self::error( 'wpcap_series_pool_capacity', __( 'Bitte ein Kontingent von mindestens 1 angeben.', 'pass-captain' ) ); }
		$wpdb->update( "{$wpdb->prefix}wpcap_series_pools", array( 'capacity' => $capacity, 'updated_at' => current_time( 'mysql', true ) ), array( 'pool_id' => (int) $pool->pool_id ) );
		\PassCaptain\Audit::record( 'series_pool_updated', 'series', (int) $pool->series_id, array( 'event_id' => (int) $pool->event_id, 'series_id' => (int) $pool->series_id, 'pool_id' => (int) $pool->pool_id, 'capacity' => $capacity, 'changes' => \PassCaptain\Admin\ChangeHistory::diff( array( 'name' => $pool->name, 'capacity' => (int) $pool->capacity ), array( 'name' => $pool->name, 'capacity' => $capacity ) ) ) );
		return true;
	}

	/** Deactivates a pool. */
	public static function delete( $pool_id ) {
		global $wpdb;
		$pool = self::pool_with_event( $pool_id );
		if ( is_wp_error( $pool ) ) { return $pool; }
		$wpdb->update( "{$wpdb->prefix}wpcap_series_pools", array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ), array( 'pool_id' => (int) $pool->pool_id ) );
		\PassCaptain\Audit::record( 'series_pool_deleted', 'series', (int) $pool->series_id, array( 'event_id' => (int) $pool->event_id, 'series_id' => (int) $pool->series_id, 'pool_id' => (int) $pool->pool_id, 'name' => (string) $pool->name ) );
		return true;
	}

	private static function pool_with_event( $pool_id ) {
		global $wpdb;
		$pool = $wpdb->get_row( $wpdb->prepare( "SELECT p.*,s.event_id FROM {$wpdb->prefix}wpcap_series_pools p INNER JOIN {$wpdb->prefix}wpcap_series s ON s.series_id=p.series_id WHERE p.pool_id=%d AND p.status='active'", absint( $pool_id ) ) );
		if ( ! $pool ) { return self::error( 'wpcap_series_pool_missing', __( 'Kontingent nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $pool->event_id, Generator::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		return $pool;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Usage.
	 * ------------------------------------------------------------------------------------------- */

	/** All ticket type ids of a pool (every occurrence of the series, filtered by type name). */
	public static function member_type_ids( $pool ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.name FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_series_occurrences so ON so.occurrence_id=tt.occurrence_id WHERE so.series_id=%d", (int) $pool->series_id ) );
		$ids = array();
		foreach ( $rows as $row ) {
			if ( Pricing::name_matches( $pool->type_name, $row->name ) ) { $ids[] = (int) $row->ticket_type_id; }
		}
		return $ids;
	}

	/**
	 * Units of the given ticket types that consume capacity: paid (minus refunded/revoked) plus active
	 * unexpired holds; same definition as the core capacity check.
	 */
	public static function used( array $type_ids, $now, $exclude_order_id = 0 ) {
		global $wpdb;
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids ) { return 0; }
		$p = $wpdb->prefix;
		// Maintained counters (one row per type) instead of summing order lines and holds.
		$counted = \PassCaptain\CapacityCounters::used_for_types( $type_ids, $exclude_order_id, $now );
		if ( null !== $counted ) { return $counted; }
		$in = implode( ',', $type_ids );
		$exclude = absint( $exclude_order_id );
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id IN ($in) AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $exclude ) );
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at > %s AND order_id <> %d", $now, $exclude ) );
		return $sold + $held;
	}

	/**
	 * Pools that contain at least one of the given ticket types, keyed by pool id (ascending).
	 * With $lock, uses locking reads only and locks the pool rows FOR UPDATE.
	 *
	 * @param object[] $types Ticket type rows (occurrence_id, name).
	 * @return array pool_id => {pool, type_ids (of $types)}
	 */
	public static function pools_for_types( array $types, $lock = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ_ids = array_values( array_unique( array_filter( array_map( static function ( $type ) { return (int) $type->occurrence_id; }, $types ) ) ) );
		if ( ! $occ_ids ) { return array(); }
		$series_rows = $wpdb->get_results( "SELECT series_id,occurrence_id FROM {$p}wpcap_series_occurrences WHERE occurrence_id IN (" . implode( ',', $occ_ids ) . ')' . ( $lock ? ' LOCK IN SHARE MODE' : '' ) );
		if ( ! $series_rows ) { return array(); }
		$series_of = array();
		foreach ( $series_rows as $row ) { $series_of[ (int) $row->occurrence_id ] = (int) $row->series_id; }
		$series_ids = array_values( array_unique( $series_of ) );
		sort( $series_ids );
		$pools = $wpdb->get_results( "SELECT * FROM {$p}wpcap_series_pools WHERE series_id IN (" . implode( ',', $series_ids ) . ") AND status='active' ORDER BY pool_id" . ( $lock ? ' FOR UPDATE' : '' ) );
		$out = array();
		foreach ( $pools as $pool ) {
			$member = array();
			foreach ( $types as $type ) {
				if ( ( $series_of[ (int) $type->occurrence_id ] ?? 0 ) === (int) $pool->series_id && Pricing::name_matches( $pool->type_name, $type->name ) ) {
					$member[] = (int) $type->ticket_type_id;
				}
			}
			if ( $member ) { $out[ (int) $pool->pool_id ] = array( 'pool' => $pool, 'type_ids' => $member ); }
		}
		ksort( $out );
		return $out;
	}

	/** Filter callback for `wpcap_reserve_capacity`. */
	public static function reserve( $result, $demand, $context ) {
		if ( is_wp_error( $result ) ) { return $result; }
		$types = (array) ( $context['types'] ?? array() );
		if ( ! $types ) { return $result; }
		$pools = self::pools_for_types( $types, true );
		$now = (string) ( $context['now'] ?? current_time( 'mysql', true ) );
		foreach ( $pools as $entry ) {
			$need = 0;
			foreach ( $entry['type_ids'] as $type_id ) { $need += (int) ( $demand[ $type_id ] ?? 0 ); }
			if ( ! $need ) { continue; }
			$pool = $entry['pool'];
			$used = self::used( self::member_type_ids( $pool ), $now, (int) ( $context['order_id'] ?? 0 ) );
			if ( $used + $need > (int) $pool->capacity ) {
				/* translators: 1: pool name, 2: remaining units */
				return self::error( 'series_pool_sold_out', sprintf( __( 'Das gemeinsame Kontingent „%1$s“ hat nur noch %2$d Tickets frei.', 'pass-captain' ), $pool->name, max( 0, (int) $pool->capacity - $used ) ), 409 );
			}
		}
		return $result;
	}

	/** Remaining units of each pool containing the type (smallest wins); null when in no pool. */
	public static function remaining_for_type( $type ) {
		$min = null;
		foreach ( self::pools_for_types( array( $type ) ) as $entry ) {
			$left = max( 0, (int) $entry['pool']->capacity - self::used( self::member_type_ids( $entry['pool'] ), current_time( 'mysql', true ) ) );
			$min = null === $min ? $left : min( $min, $left );
		}
		return $min;
	}

	/** Filter callback for `wpcap_ticket_availability`. */
	public static function availability( $remaining, $type, $occurrence = null ) {
		if ( ! $type ) { return $remaining; }
		$pool_left = self::remaining_for_type( $type );
		if ( null === $pool_left ) { return $remaining; }
		return null === $remaining ? $pool_left : min( (int) $remaining, $pool_left );
	}

	/** Pools of a series with their current usage (admin display). */
	public static function overview( $series_id ) {
		$out = array();
		foreach ( self::for_series( $series_id ) as $pool ) {
			$members = self::member_type_ids( $pool );
			$pool->used = self::used( $members, current_time( 'mysql', true ) );
			$pool->member_count = count( $members );
			$out[] = $pool;
		}
		return $out;
	}
}
