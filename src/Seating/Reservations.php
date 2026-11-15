<?php
/**
 * Seat claims for orders: atomic reservation inside the order transaction, conversion to "sold",
 * assignment to issued tickets, release on cancel/expiry/revocation/refund and late-payment recovery.
 *
 * A claim is "active" while active_key (= seat_key) is set; the unique index (occurrence_id,
 * active_key) guarantees that a seat can only have one active claim per occurrence. Released claims
 * keep their row (active_key NULL) as history.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Seating;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reservations {
	const PAYLOAD_KEY = 'seats';
	/** Public checkouts hold at most this many seats per order (limits hold exhaustion). */
	const MAX_SEATS_PER_ORDER = 20;

	public static function register() {
		add_filter( 'wpcap_checkout_payload_keys', array( __CLASS__, 'payload_keys' ) );
		add_filter( 'wpcap_checkout_error_messages', array( __CLASS__, 'error_messages' ) );
		add_action( 'wpcap_order_creating', array( __CLASS__, 'on_order_creating' ), 10, 4 );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_order_paid' ), 5, 1 );
		// After series pools (10) and waitlist offers (20): lock order type → occurrence → pool → seat.
		add_filter( 'wpcap_reserve_capacity', array( __CLASS__, 'reserve_capacity' ), 30, 3 );
		add_filter( 'wpcap_ticket_availability', array( __CLASS__, 'filter_availability' ), 30, 2 );
		add_action( 'wpcap_ticket_created', array( __CLASS__, 'on_ticket_created' ), 10, 4 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'sync_order' ), 10, 1 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'on_ticket_revoked' ), 10, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund_completed' ), 10, 2 );
		add_action( 'wpcap_cleanup_reservations', array( __CLASS__, 'cleanup' ), 20, 0 );
		add_filter( 'wpcap_ticket_mail', array( __CLASS__, 'ticket_mail' ), 10, 3 );
		add_filter( 'wpcap_ticket_seat_label', array( __CLASS__, 'seat_label' ), 5, 2 );
	}

	public static function payload_keys( $keys ) {
		$keys[] = self::PAYLOAD_KEY;
		return $keys;
	}

	public static function error_messages( $messages ) {
		return array_merge( (array) $messages, array(
			'seat_unavailable' => __( 'Mindestens ein gewählter Sitzplatz ist nicht mehr verfügbar. Bitte wähle andere Plätze.', 'pass-captain' ),
			'seat_zone_mismatch' => __( 'Ein gewählter Sitzplatz passt nicht zur Ticketart (anderer Bereich bzw. andere Kategorie).', 'pass-captain' ),
			'seat_quantity_mismatch' => __( 'Die Anzahl der gewählten Sitzplätze muss je Ticketart der bestellten Menge entsprechen.', 'pass-captain' ),
			'seat_selection_invalid' => __( 'Die Sitzplatzauswahl ist ungültig.', 'pass-captain' ),
			'seat_sold_out' => __( 'Für diese Ticketart sind keine passenden Sitzplätze mehr frei.', 'pass-captain' ),
		) );
	}

	private static function t( $name ) { return Layouts::table( $name ); }

	/*
	 * A claim blocks its seat while it is sold, or its order is not cancelled/expired/refunded and is
	 * paid, still within the hold or covered by an active reservation. This condition (claims alias c,
	 * orders alias o) is written out in release_stale() and availability(); placeholders: now,
	 * reservations table, now.
	 */

	/**
	 * Releases held claims of an occurrence (or all occurrences) that no longer block their seat.
	 *
	 * Two steps: the stale claims are selected with a plain (non-locking) read and then released by
	 * claim id. A single UPDATE … JOIN would take shared locks on the order and reservation rows it
	 * reads – inside a checkout (ticket type locked) that deadlocked with late payments and the hold
	 * cleanup, which lock the order row first. Callers inside an order transaction hold the occurrence
	 * row lock, so no order of this occurrence can be confirmed late in between; on-time payments have
	 * an active hold and are never selected.
	 *
	 * @return int|false Number of released claims, false on a database error.
	 */
	public static function release_stale( $occurrence_id = 0 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$occurrence_id = absint( $occurrence_id ); // 0 = all occurrences.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT c.claim_id FROM %i c LEFT JOIN %i o ON o.order_id=c.order_id WHERE c.active_key IS NOT NULL AND c.status='held' AND (%d=0 OR c.occurrence_id=%d) AND NOT ( c.status='sold' OR ( COALESCE(o.status,'') NOT IN ('cancelled','expired','refunded') AND ( COALESCE(o.payment_status,'') IN ('paid','paid_unallocated') OR c.expires_at > %s OR EXISTS (SELECT 1 FROM %i r WHERE r.order_id=c.order_id AND r.status='active' AND r.expires_at > %s) ) ) ) ORDER BY c.claim_id",
			self::t( 'claims' ),
			$wpdb->prefix . 'wpcap_orders',
			$occurrence_id,
			$occurrence_id,
			$now,
			$wpdb->prefix . 'wpcap_reservations',
			$now
		) );
		if ( '' !== (string) $wpdb->last_error ) { return false; }
		return self::release_ids( array_map( 'absint', (array) $ids ), 'stale', $now );
	}

	/**
	 * Releases unassigned active held claims by id (status re-checked in the UPDATE).
	 *
	 * @return int|false Number of released claims, false on a database error.
	 */
	private static function release_ids( array $ids, $reason, $now ) {
		global $wpdb;
		$released = 0;
		foreach ( array_chunk( array_values( array_filter( $ids ) ), 500 ) as $chunk ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE %i SET active_key=NULL,status='released',release_reason=%s,updated_at=%s WHERE claim_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ") AND active_key IS NOT NULL AND status='held' AND ticket_id=0", array_merge( array( self::t( 'claims' ), (string) $reason, $now ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
			if ( false === $done ) { return false; }
			$released += (int) $done;
		}
		return $released;
	}

	/**
	 * Normalises the seat selection of a checkout payload to ticket_type_id => list of seat ids.
	 * Accepts {"<type_id>": [seat_id, ...]} or [{ticket_type_id, seat_id}, ...].
	 *
	 * @throws \RuntimeException seat_selection_invalid.
	 */
	public static function parse_selection( $raw ) {
		if ( null === $raw || '' === $raw || array() === $raw ) { return array(); }
		if ( ! is_array( $raw ) ) { throw new \RuntimeException( 'seat_selection_invalid' ); }
		$out = array();
		$count = 0;
		$is_list = array_keys( $raw ) === range( 0, count( $raw ) - 1 );
		if ( $is_list && is_array( $raw[0] ) ) {
			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) || ! is_scalar( $entry['ticket_type_id'] ?? null ) || ! is_scalar( $entry['seat_id'] ?? null ) ) { throw new \RuntimeException( 'seat_selection_invalid' ); }
				$out[ absint( $entry['ticket_type_id'] ) ][] = absint( $entry['seat_id'] );
				$count++;
			}
		} else {
			foreach ( $raw as $type_id => $ids ) {
				if ( ! is_array( $ids ) ) { throw new \RuntimeException( 'seat_selection_invalid' ); }
				foreach ( $ids as $id ) {
					if ( ! is_scalar( $id ) ) { throw new \RuntimeException( 'seat_selection_invalid' ); }
					$out[ absint( $type_id ) ][] = absint( $id );
					$count++;
				}
			}
		}
		$all = array();
		foreach ( $out as $type_id => $ids ) {
			if ( ! $type_id || in_array( 0, $ids, true ) ) { throw new \RuntimeException( 'seat_selection_invalid' ); }
			$all = array_merge( $all, $ids );
		}
		if ( $count > self::MAX_SEATS_PER_ORDER || count( $all ) !== count( array_unique( $all ) ) ) { throw new \RuntimeException( 'seat_selection_invalid' ); }
		return $out;
	}

	/** Hold expiry for a new order: its inventory hold, else the configured hold minutes. */
	private static function hold_expiry( $order_id ) {
		global $wpdb;
		$expires = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(expires_at) FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_reservations', absint( $order_id ) ) );
		return $expires ? $expires : gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) Settings::get( 'hold_minutes' ) ) * MINUTE_IN_SECONDS );
	}

	/**
	 * wpcap_order_creating: runs inside the order transaction (ticket types and occurrences locked).
	 *
	 * @throws \RuntimeException seat_* codes abort the order.
	 */
	public static function on_order_creating( $order_id, $selected, $payload = array(), $internal = array() ) {
		$requested = self::parse_selection( is_array( $payload ) ? ( $payload[ self::PAYLOAD_KEY ] ?? null ) : null );
		// Trusted channels (WooCommerce, POS, adapters) cannot pick seats: best available is assigned.
		$trusted = is_array( $internal ) && ( ! empty( $internal['external'] ) || ! empty( $internal['key_namespace'] ) || ! empty( $internal['seat_auto_assign'] ) );
		$by_occurrence = array();
		foreach ( (array) $selected as $line ) {
			$type = $line['type'];
			$by_occurrence[ (int) $type->occurrence_id ][ (int) $type->ticket_type_id ] = (int) $line['quantity'];
		}
		$known_types = array();
		foreach ( $by_occurrence as $types ) { $known_types += $types; }
		foreach ( array_keys( $requested ) as $type_id ) {
			if ( ! isset( $known_types[ $type_id ] ) ) { throw new \RuntimeException( 'seat_quantity_mismatch' ); }
		}
		ksort( $by_occurrence, SORT_NUMERIC );
		$expires = null;
		foreach ( $by_occurrence as $occurrence_id => $types ) {
			global $wpdb;
			$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d FOR UPDATE', self::t( 'links' ), $occurrence_id ) );
			// A failed read is not "no seat map": the order would be created without seats.
			if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			$mapping = $link ? Layouts::type_zones( $occurrence_id ) : array();
			if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			$explicit = array();
			$auto = array();
			foreach ( $types as $type_id => $quantity ) {
				$ids = $requested[ $type_id ] ?? array();
				if ( empty( $mapping[ $type_id ] ) ) {
					if ( $ids ) { throw new \RuntimeException( 'seat_zone_mismatch' ); }
					continue; // Ticket type without seats (e.g. not mapped / no seat map).
				}
				if ( ! $ids && $trusted ) { $auto[ $type_id ] = $quantity; continue; }
				if ( count( $ids ) !== $quantity ) { throw new \RuntimeException( 'seat_quantity_mismatch' ); }
				$explicit[ $type_id ] = $ids;
			}
			if ( ! $explicit && ! $auto ) { continue; }
			if ( null === $expires ) { $expires = self::hold_expiry( $order_id ); }
			self::claim( (int) $order_id, (int) $occurrence_id, $link, $mapping, $explicit, $auto, $expires );
		}
	}

	/**
	 * Claims seats for an order. Caller holds the link row lock (FOR UPDATE) inside a transaction.
	 *
	 * @throws \RuntimeException
	 */
	private static function claim( $order_id, $occurrence_id, $link, array $mapping, array $explicit, array $auto, $expires ) {
		global $wpdb;
		// Every statement is checked: after a deadlock/lock wait timeout the server rolled the order
		// transaction back, and later statements would autocommit orphaned claims that block seats.
		if ( false === self::release_stale( $occurrence_id ) ) { throw new \RuntimeException( 'db_busy' ); }
		$now = current_time( 'mysql', true );
		$claims = self::t( 'claims' );
		$plan = array(); // list of [type_id, seat row]
		if ( $explicit ) {
			$ids = array();
			foreach ( $explicit as $type_ids ) { $ids = array_merge( $ids, $type_ids ); }
			sort( $ids, SORT_NUMERIC );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id=%d AND seat_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY seat_id FOR UPDATE', array_merge( array( self::t( 'seats' ), (int) $link->version_id ), $ids ) ) );
			if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			$seats = array();
			foreach ( $rows as $row ) { $seats[ (int) $row->seat_id ] = $row; }
			foreach ( $explicit as $type_id => $type_ids ) {
				foreach ( $type_ids as $seat_id ) {
					$seat = $seats[ $seat_id ] ?? null;
					if ( ! $seat ) { throw new \RuntimeException( 'seat_unavailable' ); }
					if ( ! in_array( $seat->zone, $mapping[ $type_id ], true ) && ! in_array( $seat->category, $mapping[ $type_id ], true ) ) { throw new \RuntimeException( 'seat_zone_mismatch' ); }
					$plan[] = array( $type_id, $seat );
				}
			}
		}
		$taken_keys = array();
		foreach ( $plan as $entry ) { $taken_keys[] = $entry[1]->seat_key; }
		foreach ( $auto as $type_id => $quantity ) {
			$picked = self::free_seats( $occurrence_id, (int) $link->version_id, $mapping[ $type_id ], $quantity, $taken_keys );
			if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			if ( count( $picked ) < $quantity ) { throw new \RuntimeException( 'seat_sold_out' ); }
			foreach ( $picked as $seat ) { $plan[] = array( $type_id, $seat ); $taken_keys[] = $seat->seat_key; }
		}
		if ( ! $plan ) { return; }
		$keys = array_map( static function ( $entry ) { return $entry[1]->seat_key; }, $plan );
		$busy = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurrence_id=%d AND active_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') FOR UPDATE', array_merge( array( $claims, $occurrence_id ), array_values( $keys ) ) ) );
		if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		if ( $busy ) { throw new \RuntimeException( 'seat_unavailable' ); }
		foreach ( $plan as list( $type_id, $seat ) ) {
			$ok = $wpdb->insert( $claims, array(
				'occurrence_id' => $occurrence_id,
				'seat_key' => $seat->seat_key,
				'active_key' => $seat->seat_key,
				'seat_id' => (int) $seat->seat_id,
				'order_id' => $order_id,
				'ticket_type_id' => (int) $type_id,
				'ticket_id' => 0,
				'status' => 'held',
				'expires_at' => $expires,
				'created_at' => $now,
				'updated_at' => $now,
			) );
			// A duplicate on the unique (occurrence_id, active_key) index means somebody else won; a
			// deadlock/lock wait timeout means the transaction is gone (retry).
			if ( ! $ok ) { throw new \RuntimeException( \PassCaptain\DbSession::is_busy_error( $wpdb->last_error ) ? 'db_busy' : 'seat_unavailable' ); }
		}
	}

	/** Best available free seats of a version in the given zones/categories, locked FOR UPDATE. */
	private static function free_seats( $occurrence_id, $version_id, array $zones, $limit, array $exclude_keys = array() ) {
		global $wpdb;
		if ( ! $zones || $limit < 1 ) { return array(); }
		$zones = array_values( array_map( 'strval', $zones ) );
		// Seat keys are never empty, so '' keeps the NOT IN list valid when nothing is excluded.
		$exclude_keys = array_merge( array( '' ), array_values( array_map( 'strval', $exclude_keys ) ) );
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT s.* FROM %i s WHERE s.version_id=%d AND (s.zone IN (' . implode( ',', array_fill( 0, count( $zones ), '%s' ) ) . ') OR s.category IN (' . implode( ',', array_fill( 0, count( $zones ), '%s' ) ) . ')) AND s.seat_key NOT IN (' . implode( ',', array_fill( 0, count( $exclude_keys ), '%s' ) ) . ') AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.occurrence_id=%d AND c.active_key=s.seat_key) ORDER BY s.sort_order,s.seat_id LIMIT %d FOR UPDATE',
			array_merge( array( self::t( 'seats' ), $version_id ), $zones, $zones, $exclude_keys, array( self::t( 'claims' ), $occurrence_id, $limit ) )
		) );
	}

	/**
	 * wpcap_reserve_capacity, phase "recover" (late payment, inside the recovery transaction after the
	 * ticket type/occurrence/pool locks): the order's seats were released when its hold lapsed. Its
	 * previous seats are claimed again if still free, otherwise the best available seats of the ticket
	 * type's zones; without enough free seats the payment is not allocated (`seat_sold_out` →
	 * needs_review), so no ticket is issued without a seat. The order is paid in the same transaction,
	 * so the claims are stored as sold (a failed re-check rolls them back with a savepoint).
	 */
	public static function reserve_capacity( $result, $demand, $context ) {
		if ( $result instanceof \WP_Error || 'recover' !== ( $context['phase'] ?? '' ) ) { return $result; }
		$order_id = absint( $context['order_id'] ?? 0 );
		$types = (array) ( $context['types'] ?? array() );
		if ( ! $order_id || ! $types ) { return $result; }
		try {
			$missing = self::recover_seats( $order_id, (array) $demand, $types );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ) );
		}
		if ( $missing > 0 ) {
			return new \WP_Error( 'seat_sold_out', __( 'Für diese Ticketart sind keine passenden Sitzplätze mehr frei.', 'pass-captain' ) );
		}
		return $result;
	}

	/**
	 * Claims seats again (as sold) for an order whose claims lapsed. The caller holds the transaction
	 * and the ticket type/occurrence locks. Every statement is checked.
	 *
	 * @param array    $demand ticket_type_id => quantity.
	 * @param object[] $types  ticket_type_id => row (occurrence_id).
	 * @return int Number of seats that could not be claimed.
	 * @throws \RuntimeException db_busy on a database error.
	 */
	private static function recover_seats( $order_id, array $demand, array $types ) {
		global $wpdb;
		$claims = self::t( 'claims' );
		$now = current_time( 'mysql', true );
		$by_occurrence = array();
		foreach ( $demand as $type_id => $quantity ) {
			$type = $types[ $type_id ] ?? null;
			if ( $type ) { $by_occurrence[ (int) $type->occurrence_id ][ (int) $type_id ] = (int) $quantity; }
		}
		ksort( $by_occurrence, SORT_NUMERIC );
		$check = static function () use ( $wpdb ) {
			if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		};
		$missing_total = 0;
		$recoverable = null;
		foreach ( $by_occurrence as $occurrence_id => $lines ) {
			$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d FOR UPDATE', self::t( 'links' ), $occurrence_id ) );
			$check();
			if ( ! $link ) { continue; }
			$mapping = Layouts::type_zones( $occurrence_id );
			$check();
			ksort( $lines, SORT_NUMERIC );
			if ( ! array_intersect_key( $lines, array_filter( $mapping ) ) ) { continue; }
			// First release what no longer blocks a seat (the order's own lapsed claims included), then
			// count what the order still holds.
			if ( false === self::release_stale( $occurrence_id ) ) { throw new \RuntimeException( 'db_busy' ); }
			foreach ( $lines as $type_id => $quantity ) {
				if ( empty( $mapping[ $type_id ] ) ) { continue; }
				$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND ticket_type_id=%d AND active_key IS NOT NULL', $claims, $order_id, $type_id ) );
				$check();
				$need = $quantity - $have;
				if ( $need < 1 ) { continue; }
				if ( null === $recoverable ) {
					$recoverable = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND active_key IS NULL AND release_reason IN ('stale','expired') ORDER BY claim_id", $claims, $order_id ) );
					$check();
				}
				$plan = array();
				$keys = array();
				foreach ( (array) $recoverable as $claim ) {
					if ( count( $plan ) >= $need ) { break; }
					if ( (int) $claim->ticket_type_id !== (int) $type_id || (int) $claim->occurrence_id !== (int) $occurrence_id || in_array( $claim->seat_key, $keys, true ) ) { continue; }
					$seat = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id=%d AND seat_key=%s FOR UPDATE', self::t( 'seats' ), (int) $link->version_id, $claim->seat_key ) );
					$check();
					if ( ! $seat ) { continue; }
					$busy = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurrence_id=%d AND active_key=%s FOR UPDATE', $claims, $occurrence_id, $claim->seat_key ) );
					$check();
					if ( ! $busy ) { $plan[] = $seat; $keys[] = $seat->seat_key; }
				}
				if ( count( $plan ) < $need ) {
					foreach ( self::free_seats( $occurrence_id, (int) $link->version_id, $mapping[ $type_id ], $need - count( $plan ), $keys ) as $seat ) { $plan[] = $seat; }
					$check();
				}
				$claimed = 0;
				foreach ( $plan as $seat ) {
					$ok = $wpdb->insert( $claims, array( 'occurrence_id' => $occurrence_id, 'seat_key' => $seat->seat_key, 'active_key' => $seat->seat_key, 'seat_id' => (int) $seat->seat_id, 'order_id' => $order_id, 'ticket_type_id' => (int) $type_id, 'ticket_id' => 0, 'status' => 'sold', 'expires_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
					if ( ! $ok ) {
						if ( \PassCaptain\DbSession::is_busy_error( $wpdb->last_error ) ) { throw new \RuntimeException( 'db_busy' ); }
						continue; // Taken meanwhile (unique index): counts as missing.
					}
					++$claimed;
				}
				$missing_total += max( 0, $need - $claimed );
			}
		}
		return $missing_total;
	}

	/**
	 * wpcap_order_paid: held seats become sold. Late payments claim their seats again inside the
	 * recovery transaction (reserve_capacity()); this only repairs orders confirmed without that step
	 * (e.g. by an add-on) and flags them when seats are missing.
	 */
	public static function on_order_paid( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$claims = self::t( 'claims' );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='sold',updated_at=%s WHERE order_id=%d AND status='held' AND active_key IS NOT NULL", $claims, $now, $order_id ) );
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT oi.ticket_type_id,SUM(oi.quantity) AS quantity,tt.occurrence_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d GROUP BY oi.ticket_type_id,tt.occurrence_id ORDER BY tt.occurrence_id,oi.ticket_type_id', $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $order_id ) );
		$recoverable = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id=%d AND active_key IS NULL AND release_reason IN ('stale','expired')", $claims, $order_id ) );
		$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND active_key IS NOT NULL', $claims, $order_id ) );
		$ordered = array_sum( array_map( static function ( $line ) { return (int) $line->quantity; }, $lines ) );
		if ( ! $recoverable || $active >= $ordered ) { return; }
		$demand = array();
		$types = array();
		foreach ( $lines as $line ) {
			$demand[ (int) $line->ticket_type_id ] = (int) $line->quantity;
			$types[ (int) $line->ticket_type_id ] = (object) array( 'ticket_type_id' => (int) $line->ticket_type_id, 'occurrence_id' => (int) $line->occurrence_id );
		}
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'db_busy' ); }
			$missing_total = self::recover_seats( $order_id, $demand, $types );
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'db_busy' ); }
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$missing_total = -1;
		}
		if ( 0 !== $missing_total ) {
			Audit::record( 'seat_recovery_failed', 'order', $order_id, array( 'missing_seats' => $missing_total ) );
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Sitzplätze nach verspäteter Zahlung fehlen', 'pass-captain' ), sprintf( /* translators: %d: order id */ __( 'Für Bestellung #%d ist die Zahlung nach Ablauf der Reservierung eingegangen; nicht alle Sitzplätze konnten erneut zugewiesen werden. Bitte manuell klären.', 'pass-captain' ), $order_id ) );
		} else {
			Audit::record( 'seats_recovered', 'order', $order_id );
		}
	}

	/**
	 * wpcap_ticket_availability: a ticket type mapped to zones of a seat map sells at most the free
	 * seats of those zones/categories (the event page must not offer tickets without a seat).
	 */
	public static function filter_availability( $remaining, $type ) {
		if ( ! is_object( $type ) || empty( $type->ticket_type_id ) || empty( $type->occurrence_id ) ) { return $remaining; }
		$free = self::free_seat_count( (int) $type->occurrence_id, (int) $type->ticket_type_id );
		if ( null === $free ) { return $remaining; }
		return ( null === $remaining || ! is_numeric( $remaining ) ) ? $free : min( (int) $remaining, $free );
	}

	/** @var array Request cache: table prefix => occurrence_id => true for occurrences with a seat map. */
	private static $linked = array();

	/** Drops the request cache of linked occurrences (after a seat map was linked or removed). */
	public static function flush_link_cache() {
		self::$linked = array();
	}

	/**
	 * Occurrences with a seat map (one query per request), so the availability filter costs nothing
	 * for the (usual) ticket types without seats.
	 */
	private static function linked_occurrences() {
		global $wpdb;
		if ( ! isset( self::$linked[ $wpdb->prefix ] ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT occurrence_id FROM %i', self::t( 'links' ) ) );
			if ( '' !== (string) $wpdb->last_error ) { return array(); }
			self::$linked[ $wpdb->prefix ] = array_fill_keys( array_map( 'intval', (array) $ids ), true );
		}
		return self::$linked[ $wpdb->prefix ];
	}

	/** Free seats for a ticket type of an occurrence (null when the type has no seats). */
	public static function free_seat_count( $occurrence_id, $type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! isset( self::linked_occurrences()[ (int) $occurrence_id ] ) ) { return null; }
		$link = Layouts::link( $occurrence_id );
		if ( ! $link ) { return null; }
		$zones = \PassCaptain\DisplayBatch::memo( 'seating_zones_' . (int) $occurrence_id, static function () use ( $occurrence_id ) { return Layouts::type_zones( $occurrence_id ); } )[ $type_id ] ?? array();
		if ( ! $zones ) { return null; }
		$zones = array_values( array_map( 'strval', $zones ) );
		$now = current_time( 'mysql', true );
		$in = implode( ',', array_fill( 0, count( $zones ), '%s' ) );
		// Same "blocks its seat" condition as availability() and release_stale().
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i s WHERE s.version_id=%d AND (s.zone IN ($in) OR s.category IN ($in)) AND NOT EXISTS (SELECT 1 FROM %i c LEFT JOIN %i o ON o.order_id=c.order_id WHERE c.occurrence_id=%d AND c.active_key=s.seat_key AND ( c.status='sold' OR ( COALESCE(o.status,'') NOT IN ('cancelled','expired','refunded') AND ( COALESCE(o.payment_status,'') IN ('paid','paid_unallocated') OR c.expires_at > %s OR EXISTS (SELECT 1 FROM %i r WHERE r.order_id=c.order_id AND r.status='active' AND r.expires_at > %s) ) ) ))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			array_merge( array( self::t( 'seats' ), (int) $link->version_id ), $zones, $zones, array( self::t( 'claims' ), $p . 'wpcap_orders', (int) $occurrence_id, $now, $p . 'wpcap_reservations', $now ) )
		) );
	}

	/** wpcap_ticket_created (inside the issuance transaction): binds a claimed seat to the ticket. */
	public static function on_ticket_created( $ticket_id, $order, $line, $index = 0 ) {
		global $wpdb;
		$ticket_id = absint( $ticket_id );
		$order_id = (int) ( is_object( $order ) ? $order->order_id : 0 );
		$type_id = (int) ( is_object( $line ) ? $line->ticket_type_id : 0 );
		if ( ! $ticket_id || ! $order_id || ! $type_id ) { return; }
		$claims = self::t( 'claims' );
		$claim = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d AND ticket_type_id=%d AND ticket_id=0 AND active_key IS NOT NULL ORDER BY claim_id LIMIT 1 FOR UPDATE', $claims, $order_id, $type_id ) );
		if ( ! $claim ) {
			$occurrence_id = (int) ( $line->occurrence_id ?? 0 );
			if ( $occurrence_id && Layouts::link( $occurrence_id ) && ! empty( Layouts::type_zones( $occurrence_id )[ $type_id ] ) ) {
				Audit::record( 'seat_assignment_missing', 'ticket', $ticket_id, array( 'order_id' => $order_id ) );
			}
			return;
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET ticket_id=%d,status='sold',updated_at=%s WHERE claim_id=%d", $claims, $ticket_id, $now, (int) $claim->claim_id ) );
		$label = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT label FROM %i WHERE seat_id=%d', self::t( 'seats' ), (int) $claim->seat_id ) );
		$wpdb->insert( self::t( 'ticket_seats' ), array(
			'ticket_id' => $ticket_id,
			'order_id' => $order_id,
			'occurrence_id' => (int) $claim->occurrence_id,
			'claim_id' => (int) $claim->claim_id,
			'seat_id' => (int) $claim->seat_id,
			'seat_key' => $claim->seat_key,
			'label' => $label,
			'status' => 'assigned',
			'assigned_at' => $now,
		) );
	}

	/**
	 * Brings the claims of an order in line with the order/ticket state (idempotent):
	 * - cancelled/expired/refunded order: unassigned held seats are released;
	 * - revoked tickets (revocation, refund, order cancellation) free their seat.
	 * Checked-in tickets keep their seat.
	 */
	public static function sync_order( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,status,payment_status FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', $order_id ) );
		if ( ! $order ) { return; }
		$claims = self::t( 'claims' );
		$now = current_time( 'mysql', true );
		$released = 0;
		if ( in_array( $order->status, array( 'cancelled', 'expired', 'refunded' ), true ) ) {
			$reason = 'expired' === $order->status ? 'expired' : 'order_' . $order->status;
			$released += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET active_key=NULL,status='released',release_reason=%s,updated_at=%s WHERE order_id=%d AND active_key IS NOT NULL AND ticket_id=0", $claims, $reason, $now, $order_id ) );
		}
		$released += self::release_revoked( $now, (int) $order_id );
		if ( $released ) { Audit::record( 'seats_released', 'order', $order_id, array( 'count' => $released ) ); }
	}

	/** Frees seats whose ticket is revoked, of one order ($order_id) or of all orders (0). */
	private static function release_revoked( $now, $order_id = 0 ) {
		global $wpdb;
		$claims = self::t( 'claims' );
		$tickets = $wpdb->prefix . 'wpcap_tickets';
		$wpdb->query( $wpdb->prepare( "UPDATE %i ts INNER JOIN %i c ON c.claim_id=ts.claim_id INNER JOIN %i t ON t.ticket_id=ts.ticket_id SET ts.status='released',ts.released_at=%s WHERE ts.status='assigned' AND t.status='revoked' AND (%d=0 OR c.order_id=%d)", self::t( 'ticket_seats' ), $claims, $tickets, $now, $order_id, $order_id ) );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i c INNER JOIN %i t ON t.ticket_id=c.ticket_id SET c.active_key=NULL,c.status='released',c.release_reason='ticket_revoked',c.updated_at=%s WHERE c.active_key IS NOT NULL AND c.ticket_id>0 AND t.status='revoked' AND (%d=0 OR c.order_id=%d)", $claims, $tickets, $now, $order_id, $order_id ) );
	}

	public static function on_ticket_revoked( $ticket_id ) {
		global $wpdb;
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', absint( $ticket_id ) ) );
		if ( $order_id ) { self::sync_order( $order_id ); }
	}

	public static function on_refund_completed( $refund_id, $order_id = 0 ) {
		if ( $order_id ) { self::sync_order( $order_id ); }
	}

	/** Cron safety net: releases stale holds and seats of revoked tickets (also from other paths). */
	public static function cleanup() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$claims = self::t( 'claims' );
		// Claims of closed orders, selected first and released by id (no shared locks on order rows).
		$closed = $wpdb->get_results( $wpdb->prepare( "SELECT c.claim_id,o.status FROM %i c INNER JOIN %i o ON o.order_id=c.order_id WHERE c.active_key IS NOT NULL AND c.ticket_id=0 AND o.status IN ('cancelled','expired','refunded') ORDER BY c.claim_id", $claims, $wpdb->prefix . 'wpcap_orders' ) );
		$by_reason = array();
		foreach ( (array) $closed as $row ) { $by_reason[ 'expired' === $row->status ? 'expired' : 'order_' . $row->status ][] = (int) $row->claim_id; }
		foreach ( $by_reason as $reason => $ids ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET active_key=NULL,status=%s,release_reason=%s,updated_at=%s WHERE claim_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND active_key IS NOT NULL AND ticket_id=0', array_merge( array( $claims, 'released', $reason, $now ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
		}
		self::release_stale();
		self::release_revoked( $now );
	}

	/** Seat labels of tickets: ticket_id => label (active assignments only). */
	public static function labels_for_tickets( array $ticket_ids ) {
		global $wpdb;
		$ticket_ids = array_filter( array_map( 'absint', $ticket_ids ) );
		if ( ! $ticket_ids ) { return array(); }
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,label FROM %i WHERE status='assigned' AND ticket_id IN (" . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ') ORDER BY assignment_id', array_merge( array( self::t( 'ticket_seats' ) ), $ticket_ids ) ) ) as $row ) {
			$out[ (int) $row->ticket_id ] = $row->label;
		}
		return $out;
	}

	/** Filter wpcap_ticket_seat_label (used e.g. by wallet passes): the ticket's assigned seat. */
	public static function seat_label( $label, $ticket_id = 0 ) {
		if ( '' !== (string) $label || ! $ticket_id ) { return $label; }
		return self::labels_for_tickets( array( (int) $ticket_id ) )[ (int) $ticket_id ] ?? $label;
	}

	/** wpcap_ticket_mail: appends the seat of each ticket to the mail body. */
	public static function ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) || ! is_array( $issued ) || ! $issued ) { return $mail; }
		$labels = self::labels_for_tickets( array_map( static function ( $ticket ) { return (int) ( $ticket['ticket_id'] ?? 0 ); }, $issued ) );
		if ( ! $labels ) { return $mail; }
		$lines = array();
		foreach ( $issued as $ticket ) {
			$id = (int) ( $ticket['ticket_id'] ?? 0 );
			if ( isset( $labels[ $id ] ) ) {
				$lines[] = sprintf( '- %s — %s: %s', $ticket['event'] ?? '', $ticket['ticket'] ?? '', $labels[ $id ] );
			}
		}
		$mail['body'] = rtrim( (string) ( $mail['body'] ?? '' ) ) . "\n\n" . __( 'Deine Sitzplätze:', 'pass-captain' ) . "\n" . implode( "\n", $lines ) . "\n";
		return $mail;
	}

	/**
	 * Public availability of an occurrence's seat map. Contains no order or customer data: only
	 * the layout, seat attributes and a free/taken flag.
	 *
	 * @return array|null Null when the occurrence has no (public) seat map.
	 */
	public static function availability( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence = $wpdb->get_row( $wpdb->prepare( 'SELECT o.occurrence_id,o.status,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $occurrence_id ) ) );
		if ( ! $occurrence || 'published' !== $occurrence->event_status || 'scheduled' !== $occurrence->status ) { return null; }
		$link = Layouts::link( $occurrence->occurrence_id );
		$version = $link ? Layouts::version( $link->version_id ) : null;
		if ( ! $version ) { return null; }
		$now = current_time( 'mysql', true );
		$taken = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT c.seat_key FROM %i c LEFT JOIN %i o ON o.order_id=c.order_id WHERE c.occurrence_id=%d AND c.active_key IS NOT NULL AND ( c.status='sold' OR ( COALESCE(o.status,'') NOT IN ('cancelled','expired','refunded') AND ( COALESCE(o.payment_status,'') IN ('paid','paid_unallocated') OR c.expires_at > %s OR EXISTS (SELECT 1 FROM %i r WHERE r.order_id=c.order_id AND r.status='active' AND r.expires_at > %s) ) ) )", self::t( 'claims' ), $p . 'wpcap_orders', (int) $occurrence->occurrence_id, $now, $p . 'wpcap_reservations', $now ) ) );
		$definition = json_decode( $version->definition, true );
		$seats = array();
		foreach ( Layouts::seats( $version->version_id ) as $seat ) {
			$seats[] = array(
				'id' => (int) $seat->seat_id,
				'kind' => $seat->kind,
				'group' => $seat->group_key,
				'row' => $seat->row_label,
				'seat' => $seat->seat_label,
				'label' => $seat->label,
				'zone' => $seat->zone,
				'category' => $seat->category,
				'accessible' => (bool) $seat->is_accessible,
				'x' => (float) $seat->pos_x,
				'y' => (float) $seat->pos_y,
				'status' => isset( $taken[ $seat->seat_key ] ) ? 'taken' : 'free',
			);
		}
		$active_types = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM %i WHERE occurrence_id=%d AND status='active'", $p . 'wpcap_ticket_types', (int) $occurrence->occurrence_id ) ) );
		$types = array();
		foreach ( Layouts::type_zones( $occurrence->occurrence_id ) as $type_id => $zones ) {
			if ( in_array( (int) $type_id, $active_types, true ) ) { $types[ (string) $type_id ] = $zones; }
		}
		$areas = array_values( array_filter( (array) ( $definition['groups'] ?? array() ), static function ( $group ) { return 'standing' === ( $group['type'] ?? '' ); } ) );
		return array(
			'occurrence_id' => (int) $occurrence->occurrence_id,
			'version' => (int) $version->version,
			'width' => (int) ( $definition['width'] ?? 1000 ),
			'height' => (int) ( $definition['height'] ?? 700 ),
			'zones' => array_values( (array) ( $definition['zones'] ?? array() ) ),
			'categories' => array_values( (array) ( $definition['categories'] ?? array() ) ),
			'areas' => array_map( static function ( $area ) { return array( 'key' => $area['key'], 'label' => $area['label'], 'x' => $area['x'], 'y' => $area['y'], 'w' => $area['w'], 'h' => $area['h'], 'zone' => $area['zone'], 'category' => $area['category'] ); }, $areas ),
			'types' => (object) $types,
			'seats' => $seats,
		);
	}
}
