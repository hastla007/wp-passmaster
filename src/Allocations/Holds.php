<?php
/**
 * Reserved contingents ("Kontingente"): named holds and kills per occurrence (optionally per
 * ticket type).
 *
 * - A hold ("Künstler 20") blocks `quantity - used` units for public sale. Trusted sources
 *   (box office `pos`, guest lists `guestlist`, `admin`; filter `wpcap_allocation_trusted_sources`)
 *   consume it via `$internal['allocation_id']` (or the filter `wpcap_allocation_hold_for_source`);
 *   consumed units are counted in `used` and as sold by the core.
 * - A kill ("Technik-Sperre 4") permanently blocks its quantity and can never be consumed; only an
 *   explicit admin release lifts it.
 * - Holds can be released fully or partially (back to public sale); holds with `release_hours`
 *   are released automatically that many hours before the occurrence starts (hourly cron).
 * - Units of cancelled/expired/refunded orders return to the hold (seat holds: to public sale).
 *
 * Lock order (same as Orders::create()): ticket types (by id) → occurrence → holds of the
 * occurrence (by id) → seat map link → seats/claims → buyer rows.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Allocations;

use PassCaptain\Audit;
use PassCaptain\Modules\Allocations as Module;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Holds {
	const KINDS = array( 'hold', 'kill' );

	/** Locked state of the current order transaction (from wpcap_reserve_capacity to wpcap_order_creating). */
	private static $tx = null;
	/** Dynamic error messages of the last aborted order (code => text). */
	private static $messages = array();

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_alloc_' . $name;
	}

	/** Sources that may consume holds and are not bound to them (box office, guest list, admin). */
	public static function trusted_sources() {
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'wpcap_allocation_trusted_sources', array( 'pos', 'guestlist', 'admin' ) ) ) ) );
	}

	public static function is_trusted( $source ) {
		return in_array( sanitize_key( (string) $source ), self::trusted_sources(), true );
	}

	public static function error_messages() {
		return array_merge(
			array(
				'allocation_invalid' => __( 'Das gewählte Kontingent passt nicht zu dieser Bestellung.', 'wp-pass-captain' ),
				'allocation_forbidden' => __( 'Kontingente können über diesen Verkaufskanal nicht genutzt werden.', 'wp-pass-captain' ),
				'allocation_exhausted' => __( 'Das gewählte Kontingent reicht für diese Bestellung nicht aus.', 'wp-pass-captain' ),
			),
			self::$messages
		);
	}

	/** Sets a message for an abort code (used by the checkout error message filter). */
	public static function set_message( $code, $message ) {
		self::$messages[ $code ] = (string) $message;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Reading.
	 * ------------------------------------------------------------------------------------------- */

	public static function get( $hold_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'holds' ) . ' WHERE hold_id=%d', absint( $hold_id ) ) );
	}

	/** Holds of an occurrence (active only unless $all). */
	public static function for_occurrence( $occurrence_id, $all = false ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'holds' ) . ' WHERE occurrence_id=%d' . ( $all ? '' : " AND status='active'" ) . ' ORDER BY hold_id', absint( $occurrence_id ) ) );
	}

	/** Units a hold still blocks right now (lapsed consumption excluded). */
	public static function open_units( $hold ) {
		$hold = is_object( $hold ) ? $hold : self::get( $hold );
		if ( ! $hold ) { return 0; }
		$stale = Capacity::stale_usage( array( (int) $hold->hold_id ), current_time( 'mysql', true ) );
		return Capacity::open( $hold, $stale[ (int) $hold->hold_id ] ?? 0 );
	}

	/** Whether a hold covers a ticket type (same occurrence; type-specific holds only their type). */
	public static function covers( $hold, $type ) {
		return (int) $hold->occurrence_id === (int) $type->occurrence_id && ( ! (int) $hold->ticket_type_id || (int) $hold->ticket_type_id === (int) $type->ticket_type_id );
	}

	/** Whether a source may consume the hold (empty `sources` = every trusted source). */
	public static function source_allowed( $hold, $source ) {
		if ( ! self::is_trusted( $source ) ) { return false; }
		$allowed = array_filter( array_map( 'sanitize_key', explode( ',', (string) $hold->sources ) ) );
		return ! $allowed || in_array( sanitize_key( $source ), $allowed, true );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Core hooks.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * wpcap_reserve_capacity: locks the holds of the order's occurrences (FOR UPDATE, by id) and
	 * enforces them for public sources. Trusted sources are checked in on_order_creating(), where
	 * `$internal['allocation_id']` is known. Late payment recovery re-consumes the order's hold units.
	 */
	public static function reserve_capacity( $result, $demand = array(), $context = array() ) {
		global $wpdb;
		self::$tx = null;
		self::$messages = array();
		if ( is_wp_error( $result ) ) { return $result; }
		$occurrences = (array) ( $context['occurrences'] ?? array() );
		$ids = array_map( 'absint', array_keys( $occurrences ) );
		if ( ! $ids ) { return $result; }
		sort( $ids, SORT_NUMERIC );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'holds' ) . ' WHERE occurrence_id IN (' . implode( ',', $ids ) . ") AND status='active' ORDER BY hold_id FOR UPDATE" );
		if ( '' !== $wpdb->last_error ) { return new \WP_Error( 'db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'wp-pass-captain' ) ); }
		$holds = array();
		foreach ( (array) $rows as $row ) { $holds[ (int) $row->hold_id ] = $row; }
		$phase = (string) ( $context['phase'] ?? 'create' );
		$source = sanitize_key( (string) ( $context['source'] ?? 'native' ) );
		$now = (string) ( $context['now'] ?? current_time( 'mysql', true ) );
		$order_id = absint( $context['order_id'] ?? 0 );
		self::$tx = array(
			'phase' => $phase,
			'source' => $source,
			'demand' => array_map( 'absint', (array) $demand ),
			'types' => (array) ( $context['types'] ?? array() ),
			'occurrences' => $occurrences,
			'holds' => $holds,
			'now' => $now,
			'consumption' => null,
		);
		if ( 'recover' === $phase ) { return self::recover( $result, $order_id ); }
		if ( ! $holds || self::is_trusted( $source ) ) { return $result; }
		$error = Capacity::check( self::$tx['demand'], self::$tx['types'], $occurrences, $holds, array(), 0, $now );
		return '' === $error ? $result : new \WP_Error( $error, '' );
	}

	/** Late payment: the order's released hold units are taken again if the hold still has room. */
	private static function recover( $result, $order_id ) {
		global $wpdb;
		$tx = self::$tx;
		$usage = $order_id ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'usage' ) . ' WHERE order_id=%d ORDER BY usage_id', $order_id ) ) : array();
		$restore = array();
		$retake = array();
		foreach ( (array) $usage as $row ) {
			$hold_id = (int) $row->hold_id;
			if ( 'active' === $row->status ) {
				// Still booked on the hold but counted as stale (lapsed reservation): counts again.
				$restore[ $hold_id ] = ( $restore[ $hold_id ] ?? 0 ) + (int) $row->quantity - (int) $row->released;
			} elseif ( (int) $row->quantity > 0 ) {
				$retake[ $hold_id ][] = $row;
			}
		}
		$consumed = array();
		foreach ( $retake as $hold_id => $rows ) {
			$hold = $tx['holds'][ $hold_id ] ?? null;
			if ( ! $hold || 'hold' !== $hold->kind ) {
				// Hold closed meanwhile: the order needs public capacity (checked below and by the core).
				unset( $retake[ $hold_id ] );
				continue;
			}
			$consumed[ $hold_id ] = array_sum( array_map( static function ( $row ) { return (int) $row->quantity; }, $rows ) );
		}
		// Own lapsed units are not stale for the order itself: open = quantity - used + stale - consumed.
		$error = Capacity::check( $tx['demand'], $tx['types'], $tx['occurrences'], $tx['holds'], $consumed, $order_id, $tx['now'], $restore );
		if ( '' === $error ) {
			foreach ( $consumed as $hold_id => $units ) {
				$stale = Capacity::stale_usage( array( $hold_id ), $tx['now'] );
				if ( $units > Capacity::open( $tx['holds'][ $hold_id ], ( $stale[ $hold_id ] ?? 0 ) ) ) { $error = 'sold_out'; break; }
			}
		}
		if ( '' !== $error ) { return new \WP_Error( $error, '' ); }
		$now = $tx['now'];
		foreach ( $retake as $hold_id => $rows ) {
			foreach ( $rows as $row ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'usage' ) . " SET released=0,status='active',updated_at=%s WHERE usage_id=%d", $now, (int) $row->usage_id ) );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'holds' ) . ' SET used=used+%d,updated_at=%s WHERE hold_id=%d', $consumed[ $hold_id ], $now, $hold_id ) );
			if ( '' !== $wpdb->last_error ) { return new \WP_Error( 'db_busy', '' ); }
		}
		return $result;
	}

	/**
	 * wpcap_order_creating (priority 4, before seat claims): trusted sources consume the requested
	 * hold (`$internal['allocation_id']`) and are checked against the remaining open holds.
	 *
	 * @throws \RuntimeException allocation_* / sold_out / occurrence_sold_out / db_busy.
	 */
	public static function on_order_creating( $order_id, $selected, $payload = array(), $internal = array() ) {
		global $wpdb;
		$tx = self::$tx;
		if ( ! $tx || 'create' !== $tx['phase'] ) { return; }
		$internal = is_array( $internal ) ? $internal : array();
		$source = $tx['source'];
		$trusted = self::is_trusted( $source );
		$hold_id = absint( $internal['allocation_id'] ?? 0 );
		if ( ! $hold_id && $trusted ) {
			/**
			 * Maps a trusted source to a hold it consumes when no allocation_id is given
			 * (e.g. guest lists → "Künstler"). Return a hold id or 0.
			 */
			$hold_id = absint( apply_filters( 'wpcap_allocation_hold_for_source', 0, $source, $selected, $internal ) );
		}
		if ( $hold_id && ! $trusted ) { throw new \RuntimeException( 'allocation_forbidden' ); }
		if ( ! $trusted || ( ! $hold_id && ! $tx['holds'] ) ) { return; }
		$consumed = array();
		$per_type = array();
		$hold = null;
		if ( $hold_id ) {
			$hold = $tx['holds'][ $hold_id ] ?? null;
			if ( ! $hold || 'hold' !== $hold->kind || ! self::source_allowed( $hold, $source ) ) { throw new \RuntimeException( 'allocation_invalid' ); }
			foreach ( (array) $selected as $line ) {
				if ( self::covers( $hold, $line['type'] ) ) {
					$type_id = (int) $line['type']->ticket_type_id;
					$per_type[ $type_id ] = ( $per_type[ $type_id ] ?? 0 ) + (int) $line['quantity'];
				}
			}
			$covered = array_sum( $per_type );
			if ( ! $covered ) { throw new \RuntimeException( 'allocation_invalid' ); }
			$stale = Capacity::stale_usage( array( $hold_id ), $tx['now'] );
			$open = Capacity::open( $hold, $stale[ $hold_id ] ?? 0 );
			if ( $covered > $open ) {
				/* translators: 1: hold name, 2: remaining units */
				self::set_message( 'allocation_exhausted', sprintf( __( 'Im Kontingent „%1$s“ sind nur noch %2$d Plätze frei.', 'wp-pass-captain' ), $hold->name, $open ) );
				throw new \RuntimeException( 'allocation_exhausted' );
			}
			$consumed[ $hold_id ] = $covered;
		}
		$error = Capacity::check( $tx['demand'], $tx['types'], $tx['occurrences'], $tx['holds'], $consumed, (int) $order_id, $tx['now'] );
		if ( '' !== $error ) { throw new \RuntimeException( $error ); }
		if ( ! $hold ) { return; }
		$now = $tx['now'];
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'holds' ) . ' SET used=used+%d,updated_at=%s WHERE hold_id=%d', $consumed[ $hold_id ], $now, $hold_id ) );
		if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		foreach ( $per_type as $type_id => $quantity ) {
			$wpdb->insert( self::table( 'usage' ), array( 'hold_id' => $hold_id, 'order_id' => (int) $order_id, 'ticket_type_id' => $type_id, 'quantity' => $quantity, 'released' => 0, 'source' => $source, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		}
		$hold->used = (int) $hold->used + $consumed[ $hold_id ];
		self::$tx['holds'][ $hold_id ] = $hold;
		self::$tx['consumption'] = array(
			'hold' => $hold,
			'order_id' => (int) $order_id,
			'per_type' => $per_type,
			'seats' => SeatHolds::release_for_order( $hold, $per_type, (int) $order_id, $now ),
			'explicit_seats' => is_array( $payload ) && ! empty( $payload['seats'] ),
		);
	}

	/** Consumption of the running order transaction (for the seat transfer at priority 20). */
	public static function current_consumption( $order_id ) {
		$c = self::$tx['consumption'] ?? null;
		return $c && (int) $c['order_id'] === (int) $order_id ? $c : null;
	}

	/** wpcap_ticket_availability: public availability minus open holds (never more than the core value). */
	public static function availability( $remaining, $type = null, $occurrence = null ) {
		if ( null === $remaining || ! is_object( $type ) || ! is_object( $occurrence ) ) { return $remaining; }
		$holds = array();
		foreach ( self::for_occurrence( (int) $occurrence->occurrence_id ) as $hold ) { $holds[ (int) $hold->hold_id ] = $hold; }
		if ( ! $holds ) { return $remaining; }
		$public = Capacity::public_remaining( $type, $occurrence, $holds, current_time( 'mysql', true ) );
		return null === $public ? $remaining : min( (int) $remaining, $public );
	}

	/**
	 * wpcap_capacity_released: consumed units of the order go back to their hold (to public sale
	 * when the hold is released or holds seats). `tickets_revoked` returns only the revoked units.
	 */
	public static function on_capacity_released( $order_id, $reason = '', $details = array() ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) { return; }
		$partial = 'tickets_revoked' === $reason;
		$type_id = $partial ? absint( $details['ticket_type_id'] ?? 0 ) : 0;
		$need = $partial ? absint( $details['quantity'] ?? 0 ) : PHP_INT_MAX;
		if ( $partial && ( ! $type_id || ! $need ) ) { return; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'usage' ) . " WHERE order_id=%d AND status='active'" . ( $type_id ? $wpdb->prepare( ' AND ticket_type_id=%d', $type_id ) : '' ) . ' ORDER BY usage_id', $order_id ) );
		$now = current_time( 'mysql', true );
		foreach ( (array) $rows as $row ) {
			if ( $need < 1 ) { break; }
			$units = min( $need, (int) $row->quantity - (int) $row->released );
			if ( $units < 1 ) { continue; }
			// Conditional, so a concurrent duplicate notification cannot return units twice.
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'usage' ) . " SET released=released+%d,status=IF(released>=quantity,'released','active'),updated_at=%s WHERE usage_id=%d AND status='active' AND quantity-released>=%d", $units, $now, (int) $row->usage_id, $units ) );
			if ( 1 !== (int) $changed ) { continue; }
			$need -= $units;
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table( 'holds' ) . " h SET h.quantity=IF(h.status='active' AND NOT EXISTS (SELECT 1 FROM " . self::table( 'hold_seats' ) . ' hs WHERE hs.hold_id=h.hold_id), h.quantity, GREATEST(0, CAST(h.quantity AS SIGNED) - %d)), h.used=GREATEST(0, CAST(h.used AS SIGNED) - %d), h.updated_at=%s WHERE h.hold_id=%d',
				$units,
				$units,
				$now,
				(int) $row->hold_id
			) );
			Audit::record( 'allocation_returned', 'allocation', (int) $row->hold_id, array( 'order_id' => $order_id, 'units' => $units, 'reason' => sanitize_key( (string) $reason ) ) );
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Management (admin, API).
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Locks an occurrence for hold management in the core lock order: its ticket types (by id),
	 * then the occurrence, then its holds. Must run inside a transaction.
	 *
	 * @return array{occurrence: object|null, types: array, holds: array}
	 */
	public static function lock_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$types = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id FOR UPDATE", absint( $occurrence_id ) ) ) as $type ) { $types[ (int) $type->ticket_type_id ] = $type; }
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", absint( $occurrence_id ) ) );
		$holds = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'holds' ) . " WHERE occurrence_id=%d AND status='active' ORDER BY hold_id FOR UPDATE", absint( $occurrence_id ) ) ) as $hold ) { $holds[ (int) $hold->hold_id ] = $hold; }
		return array( 'occurrence' => $occurrence, 'types' => $types, 'holds' => $holds );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Sanitises a source list ("guestlist, pos") to "guestlist,pos" (trusted sources only). */
	public static function clean_sources( $sources ) {
		$list = is_array( $sources ) ? $sources : explode( ',', (string) $sources );
		$list = array_values( array_unique( array_intersect( array_filter( array_map( 'sanitize_key', array_map( 'trim', $list ) ) ), self::trusted_sources() ) ) );
		return implode( ',', $list );
	}

	/**
	 * Creates a hold or kill.
	 *
	 * @param array $data occurrence_id, ticket_type_id (0 = whole occurrence), name, kind (hold|kill),
	 *                    quantity, release_hours (null = setting default; kills never auto-release),
	 *                    sources (list or comma string of trusted sources; empty = all), note.
	 * @return object|\WP_Error The new hold row.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$occurrence_id = absint( $data['occurrence_id'] ?? 0 );
		$type_id = absint( $data['ticket_type_id'] ?? 0 );
		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 100 );
		$kind = in_array( $data['kind'] ?? 'hold', self::KINDS, true ) ? $data['kind'] : 'hold';
		$quantity = absint( $data['quantity'] ?? 0 );
		$hours = isset( $data['release_hours'] ) && '' !== $data['release_hours'] ? absint( $data['release_hours'] ) : absint( Module::config()['auto_release_hours'] );
		if ( 'kill' === $kind ) { $hours = 0; }
		if ( '' === $name ) { return self::error( 'wpcap_alloc_name', __( 'Bitte einen Namen für das Kontingent angeben.', 'wp-pass-captain' ) ); }
		if ( $quantity < 1 || $quantity > 100000 ) { return self::error( 'wpcap_alloc_quantity', __( 'Bitte eine Anzahl ab 1 angeben.', 'wp-pass-captain' ) ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::lock_occurrence( $occurrence_id );
		if ( ! $locked['occurrence'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_occurrence', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$type = $type_id ? ( $locked['types'][ $type_id ] ?? null ) : null;
		if ( $type_id && ! $type ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_type', __( 'Die Ticketart gehört nicht zu diesem Termin.', 'wp-pass-captain' ) ); }
		$free = Capacity::public_remaining( $type, $locked['occurrence'], $locked['holds'], $now );
		if ( null !== $free && $quantity > $free ) {
			$wpdb->query( 'ROLLBACK' );
			/* translators: %d: free units */
			return self::error( 'wpcap_alloc_capacity', sprintf( __( 'Es sind nur noch %d Plätze im freien Verkauf, die zurückgehalten werden können.', 'wp-pass-captain' ), $free ), 409 );
		}
		$ok = $wpdb->insert( self::table( 'holds' ), array(
			'occurrence_id' => $occurrence_id,
			'ticket_type_id' => $type_id,
			'name' => $name,
			'kind' => $kind,
			'quantity' => $quantity,
			'used' => 0,
			'release_hours' => $hours,
			'sources' => self::clean_sources( $data['sources'] ?? '' ),
			'status' => 'active',
			'note' => mb_substr( sanitize_text_field( (string) ( $data['note'] ?? '' ) ), 0, 255 ),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$hold_id = (int) $wpdb->insert_id;
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_save', __( 'Das Kontingent konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		Audit::record( 'allocation_created', 'allocation', $hold_id, array( 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id, 'kind' => $kind, 'quantity' => $quantity ) );
		return self::get( $hold_id );
	}

	/**
	 * Changes name, note, sources and auto-release hours; `add` enlarges the hold (capacity checked).
	 *
	 * @return object|\WP_Error
	 */
	public static function update( $hold_id, array $data ) {
		global $wpdb;
		$hold = self::get( $hold_id );
		if ( ! $hold || 'active' !== $hold->status ) { return self::error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), 404 ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::lock_occurrence( (int) $hold->occurrence_id );
		$hold = $locked['holds'][ (int) $hold->hold_id ] ?? null;
		if ( ! $hold ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), 404 ); }
		$fields = array( 'updated_at' => $now );
		if ( isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ) { $fields['name'] = mb_substr( sanitize_text_field( (string) $data['name'] ), 0, 100 ); }
		if ( isset( $data['note'] ) ) { $fields['note'] = mb_substr( sanitize_text_field( (string) $data['note'] ), 0, 255 ); }
		if ( isset( $data['sources'] ) ) { $fields['sources'] = self::clean_sources( $data['sources'] ); }
		if ( isset( $data['release_hours'] ) && '' !== $data['release_hours'] && 'kill' !== $hold->kind ) { $fields['release_hours'] = absint( $data['release_hours'] ); }
		$add = absint( $data['add'] ?? 0 );
		if ( $add ) {
			$type = (int) $hold->ticket_type_id ? ( $locked['types'][ (int) $hold->ticket_type_id ] ?? null ) : null;
			$free = Capacity::public_remaining( $type, $locked['occurrence'], $locked['holds'], $now );
			if ( null !== $free && $add > $free ) {
				$wpdb->query( 'ROLLBACK' );
				/* translators: %d: free units */
				return self::error( 'wpcap_alloc_capacity', sprintf( __( 'Es sind nur noch %d Plätze im freien Verkauf, die zurückgehalten werden können.', 'wp-pass-captain' ), $free ), 409 );
			}
			$fields['quantity'] = (int) $hold->quantity + $add;
		}
		$wpdb->update( self::table( 'holds' ), $fields, array( 'hold_id' => (int) $hold->hold_id ) );
		if ( '' !== $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_save', __( 'Das Kontingent konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		Audit::record( 'allocation_updated', 'allocation', (int) $hold->hold_id, array_diff_key( $fields, array( 'updated_at' => 1 ) ) );
		return self::get( $hold->hold_id );
	}

	/**
	 * Releases open units of a hold/kill back to public sale.
	 *
	 * @param int      $hold_id
	 * @param int|null $units   Null = everything open (the hold is closed: status `released`).
	 * @param string   $reason  manual|auto|… (audit log).
	 * @return array|\WP_Error   released units and the hold row.
	 */
	public static function release( $hold_id, $units = null, $reason = 'manual' ) {
		global $wpdb;
		$hold = self::get( $hold_id );
		if ( ! $hold || 'active' !== $hold->status ) { return self::error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), 404 ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::lock_occurrence( (int) $hold->occurrence_id );
		$hold = $locked['holds'][ (int) $hold->hold_id ] ?? null;
		if ( ! $hold ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden oder bereits freigegeben.', 'wp-pass-captain' ), 404 ); }
		// Lapsed consumption is not released here: it returns via the expiry job (or is recovered).
		$open = Capacity::open( $hold );
		$full = null === $units || absint( $units ) >= $open;
		$release = $full ? $open : absint( $units );
		if ( ! $full && $release < 1 ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_units', __( 'Bitte eine Anzahl ab 1 angeben.', 'wp-pass-captain' ) ); }
		$fields = array( 'quantity' => max( 0, (int) $hold->quantity - $release ), 'updated_at' => $now );
		if ( $full && null === $units ) {
			$fields['status'] = 'released';
			$fields['released_at'] = $now;
		}
		$wpdb->update( self::table( 'holds' ), $fields, array( 'hold_id' => (int) $hold->hold_id ) );
		$hold = (object) array_merge( (array) $hold, $fields );
		$seats = SeatHolds::sync( $hold, $now );
		if ( '' !== $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_alloc_save', __( 'Das Kontingent konnte nicht freigegeben werden.', 'wp-pass-captain' ), 500 ); }
		Audit::record( 'allocation_released', 'allocation', (int) $hold->hold_id, array( 'units' => $release, 'seats' => $seats, 'reason' => sanitize_key( $reason ), 'closed' => isset( $fields['status'] ) ) );
		/** A hold released units back to public sale. */
		do_action( 'wpcap_allocation_released', (int) $hold->hold_id, $release, (string) $reason );
		if ( $release > 0 ) { self::notify_capacity_increased( $hold ); }
		return array( 'released' => $release, 'seats' => $seats, 'hold' => self::get( $hold->hold_id ) );
	}

	/**
	 * Fires `wpcap_capacity_increased( $ticket_type_id, $occurrence_id )` (waitlist offers) for the
	 * hold's ticket type, or for every active ticket type of the occurrence for occurrence-wide holds.
	 * Call after the commit.
	 */
	public static function notify_capacity_increased( $hold ) {
		global $wpdb;
		$occurrence_id = (int) $hold->occurrence_id;
		$type_ids = (int) $hold->ticket_type_id ? array( (int) $hold->ticket_type_id ) : array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id", $occurrence_id ) ) );
		foreach ( $type_ids as $type_id ) {
			do_action( 'wpcap_capacity_increased', $type_id, $occurrence_id );
		}
	}

	/**
	 * Cron (wpcap_cleanup_reservations): releases holds whose auto-release time
	 * (start - release_hours) has come. Kills are never released automatically.
	 *
	 * @return int Number of released holds.
	 */
	public static function auto_release_due( $now = null ) {
		global $wpdb;
		$now = $now ? (string) $now : current_time( 'mysql', true );
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT h.hold_id FROM ' . self::table( 'holds' ) . " h INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=h.occurrence_id WHERE h.status='active' AND h.kind='hold' AND h.release_hours>0 AND DATE_SUB(o.starts_at, INTERVAL h.release_hours HOUR) <= %s ORDER BY h.hold_id LIMIT 200",
			$now
		) );
		$count = 0;
		foreach ( array_map( 'absint', (array) $ids ) as $hold_id ) {
			if ( ! is_wp_error( self::release( $hold_id, null, 'auto' ) ) ) { $count++; }
		}
		return $count;
	}

	/**
	 * Creates an order that consumes a hold (admin/staff helper). Defaults to source `admin`.
	 *
	 * @param int   $hold_id
	 * @param array $payload  As for Orders::create() (email, items, idempotency_key, …).
	 * @param array $internal Further trusted options (source, unit_prices with external, …).
	 * @return array|\WP_Error Result of Orders::create().
	 */
	public static function create_order( $hold_id, array $payload, array $internal = array() ) {
		$internal = array_merge( array( 'source' => 'admin', 'key_namespace' => 'admin', 'skip_rate_limit' => true ), $internal );
		$internal['allocation_id'] = absint( $hold_id );
		return Orders::create( $payload, $internal );
	}

	/**
	 * Usage report of an occurrence: holds with totals, consumption per source and the latest
	 * consuming orders.
	 */
	public static function report( $occurrence_id ) {
		global $wpdb;
		$holds = self::for_occurrence( $occurrence_id, true );
		$ids = array_map( static function ( $hold ) { return (int) $hold->hold_id; }, $holds );
		$by_source = array();
		$orders = array();
		if ( $ids ) {
			$in = implode( ',', $ids );
			foreach ( (array) $wpdb->get_results( 'SELECT hold_id,source,SUM(quantity-released) AS units FROM ' . self::table( 'usage' ) . " WHERE hold_id IN ($in) AND status='active' GROUP BY hold_id,source ORDER BY hold_id,source" ) as $row ) {
				$by_source[ (int) $row->hold_id ][ $row->source ] = (int) $row->units;
			}
			$orders = $wpdb->get_results( 'SELECT u.*,o.public_id,o.customer_email,o.status AS order_status FROM ' . self::table( 'usage' ) . " u LEFT JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=u.order_id WHERE u.hold_id IN ($in) ORDER BY u.usage_id DESC LIMIT 100" );
		}
		$out = array();
		foreach ( $holds as $hold ) {
			$out[] = array(
				'hold' => $hold,
				'total' => (int) $hold->quantity,
				'used' => (int) $hold->used,
				'open' => self::open_units( $hold ),
				'seats' => SeatHolds::seat_labels( (int) $hold->hold_id ),
				'by_source' => $by_source[ (int) $hold->hold_id ] ?? array(),
			);
		}
		return array( 'holds' => $out, 'orders' => (array) $orders );
	}
}
