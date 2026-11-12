<?php
/**
 * Seat holds: seats of a seated occurrence blocked for a hold/kill.
 *
 * The Seating module has no hold/block API, so a blocked seat is an active claim in its claims
 * table with the marker order_id = 0, ticket_id = 0, status 'held' and expires_at 9999-12-31.
 * Seating treats such a claim as taken (availability, auto-assignment, unique active seat) and its
 * cleanup jobs never release it (they only touch claims of real orders or lapsed holds). Our table
 * wpcap_alloc_hold_seats maps claims to holds.
 *
 * When a trusted order consumes a seat hold, the hold's seats (matching the ticket type's zones)
 * are freed right before Seating assigns seats (priority 4) and the order's auto-assigned claims
 * are moved onto them afterwards (priority 20), all inside the order transaction.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Allocations;

use PassCaptain\Audit;
use PassCaptain\Seating\Layouts;
use PassCaptain\Seating\Reservations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SeatHolds {
	const FOREVER = '9999-12-31 23:59:59';
	const MARKER = 'allocation';

	private static function available() {
		return class_exists( Layouts::class );
	}

	private static function claims() { return Layouts::table( 'claims' ); }

	/** Active held seats of a hold (locking when $lock). */
	public static function held( $hold_id, $lock = false ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Holds::table( 'hold_seats' ) . " WHERE hold_id=%d AND status='held' ORDER BY hold_seat_id" . ( $lock ? ' FOR UPDATE' : '' ), absint( $hold_id ) ) );
	}

	/** Display labels of a hold's held seats. */
	public static function seat_labels( $hold_id ) {
		global $wpdb;
		if ( ! self::available() ) { return array(); }
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT s.label FROM ' . Holds::table( 'hold_seats' ) . ' hs INNER JOIN ' . Layouts::table( 'seats' ) . " s ON s.seat_id=hs.seat_id WHERE hs.hold_id=%d AND hs.status='held' ORDER BY hs.hold_seat_id", absint( $hold_id ) ) ) );
	}

	/** Inserts the blocking claim of a seat; false when the seat is already taken. */
	private static function block( $occurrence_id, $seat, $type_id, $now ) {
		global $wpdb;
		$ok = $wpdb->insert( self::claims(), array(
			'occurrence_id' => (int) $occurrence_id,
			'seat_key' => $seat->seat_key,
			'active_key' => $seat->seat_key,
			'seat_id' => (int) $seat->seat_id,
			'order_id' => 0,
			'ticket_type_id' => (int) $type_id,
			'ticket_id' => 0,
			'status' => 'held',
			'release_reason' => '',
			'expires_at' => self::FOREVER,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/** Frees the blocking claims of hold seat rows and marks the rows (released|used). */
	private static function unblock( array $rows, $status, $now, $order_id = 0 ) {
		global $wpdb;
		foreach ( $rows as $row ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::claims() . " SET active_key=NULL,status='released',release_reason=%s,updated_at=%s WHERE claim_id=%d AND order_id=0 AND active_key IS NOT NULL", self::MARKER, $now, (int) $row->claim_id ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Holds::table( 'hold_seats' ) . ' SET status=%s,order_id=%d,updated_at=%s WHERE hold_seat_id=%d', $status, absint( $order_id ), $now, (int) $row->hold_seat_id ) );
		}
		return count( $rows );
	}

	/**
	 * Blocks seats for a hold. The hold grows if it has fewer open units than held seats
	 * (capacity checked like Holds::update()).
	 *
	 * @param int   $hold_id
	 * @param int[] $seat_ids Seat ids of the occurrence's linked seat map version.
	 * @return array|\WP_Error blocked count and labels.
	 */
	public static function add( $hold_id, array $seat_ids ) {
		global $wpdb;
		if ( ! self::available() ) { return new \WP_Error( 'wpcap_alloc_seating', __( 'Das Saalplan-Modul ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$seat_ids = array_values( array_unique( array_filter( array_map( 'absint', $seat_ids ) ) ) );
		$hold = Holds::get( $hold_id );
		if ( ! $hold || 'active' !== $hold->status ) { return new \WP_Error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! $seat_ids || count( $seat_ids ) > 500 ) { return new \WP_Error( 'wpcap_alloc_seats', __( 'Bitte Sitzplätze auswählen.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$now = current_time( 'mysql', true );
		$occurrence_id = (int) $hold->occurrence_id;
		$wpdb->query( 'START TRANSACTION' );
		$fail = static function ( $code, $message, $status = 409 ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( $code, $message, array( 'status' => $status ) );
		};
		$locked = Holds::lock_occurrence( $occurrence_id );
		$hold = $locked['holds'][ (int) $hold->hold_id ] ?? null;
		if ( ! $hold ) { return $fail( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), 404 ); }
		$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Layouts::table( 'links' ) . ' WHERE occurrence_id=%d FOR UPDATE', $occurrence_id ) );
		if ( ! $link ) { return $fail( 'wpcap_alloc_seating', __( 'Dieser Termin hat keinen Saalplan.', 'wp-pass-captain' ), 400 ); }
		$seats = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_id IN (' . implode( ',', $seat_ids ) . ') ORDER BY seat_id FOR UPDATE', (int) $link->version_id ) );
		if ( count( (array) $seats ) !== count( $seat_ids ) ) { return $fail( 'wpcap_alloc_seats', __( 'Mindestens ein Sitzplatz gehört nicht zum Saalplan dieses Termins.', 'wp-pass-captain' ), 400 ); }
		if ( (int) $hold->ticket_type_id ) {
			$zones = Layouts::type_zones( $occurrence_id )[ (int) $hold->ticket_type_id ] ?? array();
			foreach ( $seats as $seat ) {
				if ( ! in_array( $seat->zone, $zones, true ) && ! in_array( $seat->category, $zones, true ) ) {
					return $fail( 'wpcap_alloc_seat_zone', __( 'Ein Sitzplatz passt nicht zur Ticketart des Kontingents.', 'wp-pass-captain' ), 400 );
				}
			}
		}
		Reservations::release_stale( $occurrence_id );
		$keys = array_map( static function ( $seat ) { return $seat->seat_key; }, $seats );
		$busy = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::claims() . ' WHERE occurrence_id=%d AND active_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') FOR UPDATE', array_merge( array( $occurrence_id ), $keys ) ) );
		if ( $busy ) { return $fail( 'wpcap_alloc_seat_taken', __( 'Mindestens ein Sitzplatz ist bereits reserviert, verkauft oder gesperrt.', 'wp-pass-captain' ) ); }
		$held_after = count( self::held( (int) $hold->hold_id, true ) ) + count( $seats );
		$grow = max( 0, $held_after - Capacity::open( $hold ) );
		if ( $grow ) {
			$type = (int) $hold->ticket_type_id ? ( $locked['types'][ (int) $hold->ticket_type_id ] ?? null ) : null;
			$free = Capacity::public_remaining( $type, $locked['occurrence'], $locked['holds'], $now );
			if ( null !== $free && $grow > $free ) {
				/* translators: %d: free units */
				return $fail( 'wpcap_alloc_capacity', sprintf( __( 'Es sind nur noch %d Plätze im freien Verkauf, die zurückgehalten werden können.', 'wp-pass-captain' ), $free ) );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Holds::table( 'holds' ) . ' SET quantity=quantity+%d,updated_at=%s WHERE hold_id=%d', $grow, $now, (int) $hold->hold_id ) );
		}
		foreach ( $seats as $seat ) {
			$claim_id = self::block( $occurrence_id, $seat, (int) $hold->ticket_type_id, $now );
			if ( ! $claim_id ) { return $fail( 'wpcap_alloc_seat_taken', __( 'Mindestens ein Sitzplatz ist bereits reserviert, verkauft oder gesperrt.', 'wp-pass-captain' ) ); }
			$wpdb->insert( Holds::table( 'hold_seats' ), array( 'hold_id' => (int) $hold->hold_id, 'occurrence_id' => $occurrence_id, 'claim_id' => $claim_id, 'seat_id' => (int) $seat->seat_id, 'seat_key' => $seat->seat_key, 'status' => 'held', 'order_id' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		}
		if ( '' !== $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { return $fail( 'wpcap_alloc_save', __( 'Die Sitzplätze konnten nicht gesperrt werden.', 'wp-pass-captain' ), 500 ); }
		Audit::record( 'allocation_seats_added', 'allocation', (int) $hold->hold_id, array( 'seats' => count( $seats ), 'grown' => $grow ) );
		return array( 'blocked' => count( $seats ), 'labels' => array_map( static function ( $seat ) { return $seat->label; }, $seats ) );
	}

	/**
	 * Releases specific held seats of a hold to public sale; the hold shrinks by the same number.
	 *
	 * @return array|\WP_Error released count.
	 */
	public static function remove( $hold_id, array $seat_ids ) {
		global $wpdb;
		$hold = Holds::get( $hold_id );
		if ( ! $hold || 'active' !== $hold->status ) { return new \WP_Error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$seat_ids = array_values( array_unique( array_filter( array_map( 'absint', $seat_ids ) ) ) );
		if ( ! $seat_ids ) { return new \WP_Error( 'wpcap_alloc_seats', __( 'Bitte Sitzplätze auswählen.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$locked = Holds::lock_occurrence( (int) $hold->occurrence_id );
		$hold = $locked['holds'][ (int) $hold->hold_id ] ?? null;
		if ( ! $hold ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_id FROM ' . Layouts::table( 'links' ) . ' WHERE occurrence_id=%d FOR UPDATE', (int) $hold->occurrence_id ) );
		$rows = array_values( array_filter( self::held( (int) $hold->hold_id, true ), static function ( $row ) use ( $seat_ids ) { return in_array( (int) $row->seat_id, $seat_ids, true ); } ) );
		$count = self::unblock( $rows, 'released', $now );
		$shrink = min( $count, Capacity::open( $hold ) );
		if ( $shrink ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Holds::table( 'holds' ) . ' SET quantity=GREATEST(0, CAST(quantity AS SIGNED) - %d),updated_at=%s WHERE hold_id=%d', $shrink, $now, (int) $hold->hold_id ) );
		}
		if ( '' !== $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_alloc_save', __( 'Die Sitzplätze konnten nicht freigegeben werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'allocation_seats_released', 'allocation', (int) $hold->hold_id, array( 'seats' => $count ) );
		if ( $count ) { Holds::notify_capacity_increased( $hold ); }
		return array( 'released' => $count );
	}

	/**
	 * Inside a hold transaction after its quantity changed: frees held seats beyond the open units
	 * (newest first). Returns the number of freed seats.
	 */
	public static function sync( $hold, $now ) {
		global $wpdb;
		if ( ! self::available() ) { return 0; }
		$rows = self::held( (int) $hold->hold_id, true );
		$open = 'active' === $hold->status ? Capacity::open( $hold ) : 0;
		$extra = count( $rows ) - $open;
		if ( $extra < 1 ) { return 0; }
		$wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_id FROM ' . Layouts::table( 'links' ) . ' WHERE occurrence_id=%d FOR UPDATE', (int) $hold->occurrence_id ) );
		return self::unblock( array_slice( array_reverse( $rows ), 0, $extra ), 'released', $now );
	}

	/**
	 * wpcap_order_creating priority 4 (via Holds): frees the hold's seats for the consumed units so
	 * Seating can assign them. Returns ticket_type_id => list of freed hold seat rows.
	 */
	public static function release_for_order( $hold, array $per_type, $order_id, $now ) {
		if ( ! self::available() ) { return array(); }
		$rows = self::held( (int) $hold->hold_id, true );
		if ( ! $rows ) { return array(); }
		global $wpdb;
		$mapping = Layouts::type_zones( (int) $hold->occurrence_id );
		$seat_info = array();
		foreach ( (array) $wpdb->get_results( 'SELECT seat_id,zone,category FROM ' . Layouts::table( 'seats' ) . ' WHERE seat_id IN (' . implode( ',', array_map( static function ( $row ) { return (int) $row->seat_id; }, $rows ) ) . ')' ) as $seat ) { $seat_info[ (int) $seat->seat_id ] = $seat; }
		$freed = array();
		foreach ( $per_type as $type_id => $quantity ) {
			$zones = $mapping[ (int) $type_id ] ?? array();
			if ( ! $zones ) { continue; }
			foreach ( $rows as $index => $row ) {
				if ( count( $freed[ $type_id ] ?? array() ) >= $quantity ) { break; }
				$seat = $seat_info[ (int) $row->seat_id ] ?? null;
				if ( ! $seat || ( ! in_array( $seat->zone, $zones, true ) && ! in_array( $seat->category, $zones, true ) ) ) { continue; }
				$freed[ $type_id ][] = $row;
				unset( $rows[ $index ] );
			}
			if ( ! empty( $freed[ $type_id ] ) ) { self::unblock( $freed[ $type_id ], 'used', $now, $order_id ); }
		}
		return $freed;
	}

	/**
	 * wpcap_order_creating priority 20 (after Seating claimed seats): moves the order's
	 * auto-assigned claims onto the freed hold seats; freed seats the order did not get are blocked
	 * for the hold again.
	 */
	public static function after_seat_claims( $order_id, $selected = array(), $payload = array(), $internal = array() ) {
		global $wpdb;
		$consumption = Holds::current_consumption( $order_id );
		if ( ! $consumption || ! $consumption['seats'] ) { return; }
		$hold = $consumption['hold'];
		$claims = self::claims();
		$now = current_time( 'mysql', true );
		foreach ( $consumption['seats'] as $type_id => $rows ) {
			$own = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $claims WHERE order_id=%d AND ticket_type_id=%d AND active_key IS NOT NULL ORDER BY claim_id FOR UPDATE", (int) $order_id, (int) $type_id ) );
			$own_keys = array_map( static function ( $claim ) { return $claim->seat_key; }, $own );
			$wanted = array_values( array_filter( $rows, static function ( $row ) use ( $own_keys ) { return ! in_array( $row->seat_key, $own_keys, true ); } ) );
			$hold_keys = array_map( static function ( $row ) { return $row->seat_key; }, $rows );
			if ( ! $consumption['explicit_seats'] ) {
				foreach ( $own as $claim ) {
					if ( ! $wanted || in_array( $claim->seat_key, $hold_keys, true ) ) { continue; }
					$target = array_shift( $wanted );
					$moved = $wpdb->query( $wpdb->prepare( "UPDATE $claims SET seat_key=%s,active_key=%s,seat_id=%d,updated_at=%s WHERE claim_id=%d", $target->seat_key, $target->seat_key, (int) $target->seat_id, $now, (int) $claim->claim_id ) );
					if ( 1 !== (int) $moved ) { throw new \RuntimeException( 'seat_unavailable' ); }
				}
			}
			// Hold seats the order did not take stay blocked for the hold.
			foreach ( $wanted as $row ) {
				$seat = (object) array( 'seat_key' => $row->seat_key, 'seat_id' => (int) $row->seat_id );
				$claim_id = self::block( (int) $hold->occurrence_id, $seat, (int) $hold->ticket_type_id, $now );
				if ( ! $claim_id ) { throw new \RuntimeException( 'seat_unavailable' ); }
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Holds::table( 'hold_seats' ) . " SET status='held',order_id=0,claim_id=%d,updated_at=%s WHERE hold_seat_id=%d", $claim_id, $now, (int) $row->hold_seat_id ) );
			}
		}
	}
}
