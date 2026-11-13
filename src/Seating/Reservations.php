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

	/**
	 * SQL condition (claims alias c) that is true while a claim still blocks its seat: sold, or held
	 * for an order that is not cancelled/expired and is paid, inside its own hold window, or still has
	 * an active inventory reservation (e.g. an extended hold).
	 */
	private static function active_condition( $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->prepare(
			"( c.status='sold' OR ( COALESCE(o.status,'') NOT IN ('cancelled','expired','refunded') AND ( COALESCE(o.payment_status,'') IN ('paid','paid_unallocated') OR c.expires_at > %s OR EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=c.order_id AND r.status='active' AND r.expires_at > %s) ) ) )",
			$now,
			$now
		);
	}

	/** Releases held claims of an occurrence (or all occurrences) that no longer block their seat. */
	public static function release_stale( $occurrence_id = 0 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$scope = $occurrence_id ? $wpdb->prepare( ' AND c.occurrence_id=%d', absint( $occurrence_id ) ) : '';
		return (int) $wpdb->query(
			'UPDATE ' . self::t( 'claims' ) . " c LEFT JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=c.order_id SET c.active_key=NULL,c.status='released',c.release_reason='stale',c.updated_at=" . $wpdb->prepare( '%s', $now ) .
			" WHERE c.active_key IS NOT NULL AND c.status='held'{$scope} AND NOT " . self::active_condition( $now )
		);
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
		$expires = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d", absint( $order_id ) ) );
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
			$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'links' ) . ' WHERE occurrence_id=%d FOR UPDATE', $occurrence_id ) );
			$mapping = $link ? Layouts::type_zones( $occurrence_id ) : array();
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
		self::release_stale( $occurrence_id );
		$now = current_time( 'mysql', true );
		$claims = self::t( 'claims' );
		$plan = array(); // list of [type_id, seat row]
		if ( $explicit ) {
			$ids = array();
			foreach ( $explicit as $type_ids ) { $ids = array_merge( $ids, $type_ids ); }
			sort( $ids, SORT_NUMERIC );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'seats' ) . ' WHERE version_id=%d AND seat_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ') ORDER BY seat_id FOR UPDATE', (int) $link->version_id ) );
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
			if ( count( $picked ) < $quantity ) { throw new \RuntimeException( 'seat_sold_out' ); }
			foreach ( $picked as $seat ) { $plan[] = array( $type_id, $seat ); $taken_keys[] = $seat->seat_key; }
		}
		if ( ! $plan ) { return; }
		$keys = array_map( static function ( $entry ) { return $entry[1]->seat_key; }, $plan );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$busy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $claims WHERE occurrence_id=%d AND active_key IN ($placeholders) FOR UPDATE", array_merge( array( $occurrence_id ), $keys ) ) );
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
			// A duplicate on the unique (occurrence_id, active_key) index means somebody else won.
			if ( ! $ok ) { throw new \RuntimeException( 'seat_unavailable' ); }
		}
	}

	/** Best available free seats of a version in the given zones/categories, locked FOR UPDATE. */
	private static function free_seats( $occurrence_id, $version_id, array $zones, $limit, array $exclude_keys = array() ) {
		global $wpdb;
		if ( ! $zones || $limit < 1 ) { return array(); }
		$zone_list = implode( ',', array_map( static function ( $zone ) use ( $wpdb ) { return $wpdb->prepare( '%s', $zone ); }, $zones ) );
		$exclude = $exclude_keys ? ' AND s.seat_key NOT IN (' . implode( ',', array_map( static function ( $key ) use ( $wpdb ) { return $wpdb->prepare( '%s', $key ); }, $exclude_keys ) ) . ')' : '';
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT s.* FROM ' . self::t( 'seats' ) . " s WHERE s.version_id=%d AND (s.zone IN ($zone_list) OR s.category IN ($zone_list)){$exclude} AND NOT EXISTS (SELECT 1 FROM " . self::t( 'claims' ) . ' c WHERE c.occurrence_id=%d AND c.active_key=s.seat_key) ORDER BY s.sort_order,s.seat_id LIMIT %d FOR UPDATE',
			$version_id,
			$occurrence_id,
			$limit
		) );
	}

	/**
	 * wpcap_order_paid: held seats become sold. After a late payment (hold expired, capacity
	 * recovered by core) the previous seats are re-claimed if still free, otherwise best available
	 * seats of the ticket type's zones; if none is left the order is flagged in the audit log.
	 */
	public static function on_order_paid( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$claims = self::t( 'claims' );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE $claims SET status='sold',updated_at=%s WHERE order_id=%d AND status='held' AND active_key IS NOT NULL", $now, $order_id ) );
		$recoverable = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $claims WHERE order_id=%d AND active_key IS NULL AND release_reason IN ('stale','expired') ORDER BY claim_id", $order_id ) );
		if ( ! $recoverable ) { return; }
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT oi.ticket_type_id,SUM(oi.quantity) AS quantity,tt.occurrence_id FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d GROUP BY oi.ticket_type_id,tt.occurrence_id ORDER BY tt.occurrence_id,oi.ticket_type_id", $order_id ) );
		$missing_total = 0;
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $lines as $line ) {
				$occurrence_id = (int) $line->occurrence_id;
				$type_id = (int) $line->ticket_type_id;
				$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'links' ) . ' WHERE occurrence_id=%d FOR UPDATE', $occurrence_id ) );
				$mapping = $link ? Layouts::type_zones( $occurrence_id ) : array();
				if ( empty( $mapping[ $type_id ] ) ) { continue; }
				$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $claims WHERE order_id=%d AND ticket_type_id=%d AND active_key IS NOT NULL", $order_id, $type_id ) );
				$need = (int) $line->quantity - $have;
				if ( $need < 1 ) { continue; }
				self::release_stale( $occurrence_id );
				$previous = array_filter( $recoverable, static function ( $claim ) use ( $type_id, $occurrence_id ) { return (int) $claim->ticket_type_id === $type_id && (int) $claim->occurrence_id === $occurrence_id; } );
				$plan = array();
				$keys = array();
				foreach ( $previous as $claim ) {
					if ( count( $plan ) >= $need ) { break; }
					$seat = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s FOR UPDATE', (int) $link->version_id, $claim->seat_key ) );
					$busy = $seat ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $claims WHERE occurrence_id=%d AND active_key=%s FOR UPDATE", $occurrence_id, $claim->seat_key ) ) : 1;
					if ( $seat && ! $busy && ! in_array( $seat->seat_key, $keys, true ) ) { $plan[] = $seat; $keys[] = $seat->seat_key; }
				}
				if ( count( $plan ) < $need ) {
					foreach ( self::free_seats( $occurrence_id, (int) $link->version_id, $mapping[ $type_id ], $need - count( $plan ), $keys ) as $seat ) { $plan[] = $seat; }
				}
				foreach ( $plan as $seat ) {
					$ok = $wpdb->insert( $claims, array( 'occurrence_id' => $occurrence_id, 'seat_key' => $seat->seat_key, 'active_key' => $seat->seat_key, 'seat_id' => (int) $seat->seat_id, 'order_id' => $order_id, 'ticket_type_id' => $type_id, 'ticket_id' => 0, 'status' => 'sold', 'expires_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
					if ( ! $ok ) { $need++; }
				}
				$missing_total += max( 0, $need - count( $plan ) );
			}
			$wpdb->query( 'COMMIT' );
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

	/** wpcap_ticket_created (inside the issuance transaction): binds a claimed seat to the ticket. */
	public static function on_ticket_created( $ticket_id, $order, $line, $index = 0 ) {
		global $wpdb;
		$ticket_id = absint( $ticket_id );
		$order_id = (int) ( is_object( $order ) ? $order->order_id : 0 );
		$type_id = (int) ( is_object( $line ) ? $line->ticket_type_id : 0 );
		if ( ! $ticket_id || ! $order_id || ! $type_id ) { return; }
		$claims = self::t( 'claims' );
		$claim = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $claims WHERE order_id=%d AND ticket_type_id=%d AND ticket_id=0 AND active_key IS NOT NULL ORDER BY claim_id LIMIT 1 FOR UPDATE", $order_id, $type_id ) );
		if ( ! $claim ) {
			$occurrence_id = (int) ( $line->occurrence_id ?? 0 );
			if ( $occurrence_id && Layouts::link( $occurrence_id ) && ! empty( Layouts::type_zones( $occurrence_id )[ $type_id ] ) ) {
				Audit::record( 'seat_assignment_missing', 'ticket', $ticket_id, array( 'order_id' => $order_id ) );
			}
			return;
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE $claims SET ticket_id=%d,status='sold',updated_at=%s WHERE claim_id=%d", $ticket_id, $now, (int) $claim->claim_id ) );
		$label = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT label FROM ' . self::t( 'seats' ) . ' WHERE seat_id=%d', (int) $claim->seat_id ) );
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
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,status,payment_status FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", $order_id ) );
		if ( ! $order ) { return; }
		$claims = self::t( 'claims' );
		$now = current_time( 'mysql', true );
		$released = 0;
		if ( in_array( $order->status, array( 'cancelled', 'expired', 'refunded' ), true ) ) {
			$reason = 'expired' === $order->status ? 'expired' : 'order_' . $order->status;
			$released += (int) $wpdb->query( $wpdb->prepare( "UPDATE $claims SET active_key=NULL,status='released',release_reason=%s,updated_at=%s WHERE order_id=%d AND active_key IS NOT NULL AND ticket_id=0", $reason, $now, $order_id ) );
		}
		$released += self::release_revoked( $now, $wpdb->prepare( ' AND c.order_id=%d', $order_id ) );
		if ( $released ) { Audit::record( 'seats_released', 'order', $order_id, array( 'count' => $released ) ); }
	}

	/** Frees seats whose ticket is revoked; $scope is an SQL fragment on claims alias c. */
	private static function release_revoked( $now, $scope = '' ) {
		global $wpdb;
		$claims = self::t( 'claims' );
		$wpdb->query( 'UPDATE ' . self::t( 'ticket_seats' ) . " ts INNER JOIN $claims c ON c.claim_id=ts.claim_id INNER JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=ts.ticket_id SET ts.status='released',ts.released_at=" . $wpdb->prepare( '%s', $now ) . " WHERE ts.status='assigned' AND t.status='revoked'{$scope}" );
		return (int) $wpdb->query( "UPDATE $claims c INNER JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=c.ticket_id SET c.active_key=NULL,c.status='released',c.release_reason='ticket_revoked',c.updated_at=" . $wpdb->prepare( '%s', $now ) . " WHERE c.active_key IS NOT NULL AND c.ticket_id>0 AND t.status='revoked'{$scope}" );
	}

	public static function on_ticket_revoked( $ticket_id ) {
		global $wpdb;
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
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
		$wpdb->query( $wpdb->prepare( "UPDATE $claims c INNER JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=c.order_id SET c.active_key=NULL,c.status='released',c.release_reason=IF(o.status='expired','expired',CONCAT('order_',o.status)),c.updated_at=%s WHERE c.active_key IS NOT NULL AND c.ticket_id=0 AND o.status IN ('cancelled','expired','refunded')", $now ) );
		self::release_stale();
		self::release_revoked( $now );
	}

	/** Seat labels of tickets: ticket_id => label (active assignments only). */
	public static function labels_for_tickets( array $ticket_ids ) {
		global $wpdb;
		$ticket_ids = array_filter( array_map( 'absint', $ticket_ids ) );
		if ( ! $ticket_ids ) { return array(); }
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT ticket_id,label FROM ' . self::t( 'ticket_seats' ) . " WHERE status='assigned' AND ticket_id IN (" . implode( ',', $ticket_ids ) . ') ORDER BY assignment_id' ) as $row ) {
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
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT o.occurrence_id,o.status,e.status AS event_status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
		if ( ! $occurrence || 'published' !== $occurrence->event_status || 'scheduled' !== $occurrence->status ) { return null; }
		$link = Layouts::link( $occurrence->occurrence_id );
		$version = $link ? Layouts::version( $link->version_id ) : null;
		if ( ! $version ) { return null; }
		$now = current_time( 'mysql', true );
		$taken = array_flip( $wpdb->get_col( $wpdb->prepare( 'SELECT c.seat_key FROM ' . self::t( 'claims' ) . " c LEFT JOIN {$p}wpcap_orders o ON o.order_id=c.order_id WHERE c.occurrence_id=%d AND c.active_key IS NOT NULL AND " . self::active_condition( $now ), (int) $occurrence->occurrence_id ) ) );
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
		$active_types = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d AND status='active'", (int) $occurrence->occurrence_id ) ) );
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
