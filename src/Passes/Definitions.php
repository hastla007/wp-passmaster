<?php
/**
 * Pass definitions: which ticket types are passes, their linked occurrences and options.
 *
 * A pass is an ordinary row of wpcap_ticket_types (its occurrence_id is the "anchor" occurrence the
 * pass is sold on) plus a row in wpcap_pass_types and one row per linked occurrence in
 * wpcap_pass_occurrences. Every linked occurrence also has a row in wpcap_pass_gates: the lock row
 * that serialises all orders touching that occurrence (see Capacity) and the optional pass quota.
 *
 * Options:
 *  - entry_mode       'once' (one entry per linked occurrence) or 'reentry' (unlimited re-entry
 *                     per occurrence, check-out in between);
 *  - max_occurrences  N of M: admission at most at N different linked occurrences (0 = all);
 *  - area_ids         allowed entrance areas (of any linked event); none for an event = all areas;
 *  - refund_per_day_minor  partial refund per pass when a linked occurrence is cancelled;
 *  - on_cancel        'keep' (pass stays valid for the remaining occurrences, default) or
 *                     'revoke' (whole pass is revoked and – with auto refund – refunded).
 *
 * Writes are single autocommit statements (no explicit transaction), so they never hold locks
 * while waiting for the gate/occurrence locks of an order; gate rows are written before links.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

use PassCaptain\Access;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Definitions {
	const CAP = 'wpcap_manage_events';
	const MODES = array( 'once', 'reentry' );
	const ON_CANCEL = array( 'keep', 'revoke' );
	const MAX_OCCURRENCES = 366;

	/** Request cache: type_id => config object|false. */
	private static $cache = array();

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_pass_' . $name;
	}

	public static function flush() {
		self::$cache = array();
		self::$all = null;
		self::$by_occurrence = null;
	}

	/** Request cache: all pass type ids (null = not loaded). */
	private static $all = null;

	/** Request cache: occurrence id => pass type ids (null = not loaded). */
	private static $by_occurrence = null;

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Reading.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Pass configuration of a ticket type, or null when the type is no pass.
	 *
	 * @return object|null type_id, entry_mode, max_occurrences, area_ids (int[]), refund_per_day_minor,
	 *                     on_cancel, occurrence_ids (int[] ascending).
	 */
	public static function get( $type_id ) {
		$type_id = absint( $type_id );
		if ( ! $type_id ) { return null; }
		// Most ticket types are no passes: answered from the (cached) id list without a query.
		if ( ! in_array( $type_id, self::all_ids(), true ) ) { return null; }
		if ( ! array_key_exists( $type_id, self::$cache ) ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'types' ) . ' WHERE type_id=%d', $type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
			if ( $row ) {
				$ids = (array) json_decode( (string) $row->area_ids, true );
				$row->type_id = (int) $row->type_id;
				$row->max_occurrences = (int) $row->max_occurrences;
				$row->refund_per_day_minor = (int) $row->refund_per_day_minor;
				$row->area_ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
				$row->occurrence_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT occurrence_id FROM ' . self::table( 'occurrences' ) . ' WHERE type_id=%d ORDER BY occurrence_id', $type_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
			}
			self::$cache[ $type_id ] = $row ? $row : false;
		}
		return self::$cache[ $type_id ] ? self::$cache[ $type_id ] : null;
	}

	public static function is_pass( $type_id ) {
		return null !== self::get( $type_id );
	}

	/** Linked occurrence ids of a pass (ascending), empty for other types. */
	public static function occurrence_ids( $type_id ) {
		$config = self::get( $type_id );
		return $config ? $config->occurrence_ids : array();
	}

	/**
	 * Linked occurrences with event data, ordered by start.
	 *
	 * @return object[] occurrence row + event_name, event_status.
	 */
	public static function linked_occurrences( $type_id ) {
		global $wpdb;
		$ids = self::occurrence_ids( $type_id );
		if ( ! $ids ) { return array(); }
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( "SELECT o.*,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id IN (" . implode( ',', $ids ) . ') ORDER BY o.starts_at,o.occurrence_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** wpcap_order_started: true when a date linked to a pass type of the order has begun. */
	public static function order_started( $started, $order_id, $now ) {
		if ( $started ) { return true; }
		global $wpdb;
		$pass_ids = self::all_ids();
		if ( ! $pass_ids ) { return false; }
		$types = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d", absint( $order_id ) ) ) );
		foreach ( array_intersect( $types, array_map( 'intval', $pass_ids ) ) as $type_id ) {
			foreach ( self::linked_occurrences( $type_id ) as $occ ) {
				if ( (string) $occ->starts_at <= (string) $now ) { return true; }
			}
		}
		return false;
	}

	/** Pass type ids linked to an occurrence. */
	public static function pass_ids_for_occurrence( $occurrence_id ) {
		global $wpdb;
		if ( null === self::$by_occurrence ) {
			// One query per request for all links (listings ask for every occurrence).
			self::$by_occurrence = array();
			if ( self::all_ids() ) {
				foreach ( (array) $wpdb->get_results( 'SELECT occurrence_id,type_id FROM ' . self::table( 'occurrences' ) . ' ORDER BY type_id' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
					self::$by_occurrence[ (int) $row->occurrence_id ][] = (int) $row->type_id;
				}
			}
		}
		return self::$by_occurrence[ absint( $occurrence_id ) ] ?? array();
	}

	/** All pass type ids (cached per request; flush() after changes). */
	public static function all_ids() {
		global $wpdb;
		if ( null === self::$all ) {
			self::$all = array_map( 'intval', $wpdb->get_col( 'SELECT type_id FROM ' . self::table( 'types' ) . ' ORDER BY type_id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		}
		return self::$all;
	}

	/** Pass quota of an occurrence (0 = none). */
	public static function quota( $occurrence_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT pass_quota FROM ' . self::table( 'gates' ) . ' WHERE occurrence_id=%d', absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
	}

	/** Ticket type row with its anchor occurrence/event. */
	public static function type_row( $type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.*,o.event_id,o.starts_at,o.timezone,o.status AS occurrence_status,e.name AS event_name FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d", absint( $type_id ) ) );
	}

	/** Units sold or actively held of a pass type (paid minus refunded plus active holds). */
	public static function sold_units( $type_id ) {
		return Capacity::type_units( array( absint( $type_id ) ), 0, current_time( 'mysql', true ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Writing.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Creates a new pass ticket type on an anchor occurrence and configures it.
	 *
	 * @param array $in occurrence_id (anchor), name, price_minor, currency, capacity, max_per_order,
	 *                  tax_rate_bp, plus the save() options (occurrence_ids, …).
	 * @return int|\WP_Error Ticket type id.
	 */
	public static function create( array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$anchor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", absint( $in['occurrence_id'] ?? 0 ) ) );
		if ( ! $anchor ) { return self::error( 'wpcap_pass_anchor', __( 'Bitte einen Termin wählen, auf dem der Pass verkauft wird.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $anchor->event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return self::error( 'wpcap_pass_name', __( 'Bitte einen Namen für den Pass angeben.', 'pass-captain' ) ); }
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['currency'] ?? 'EUR' ) ) );
		$wpdb->insert( "{$p}wpcap_ticket_types", array(
			'occurrence_id' => (int) $anchor->occurrence_id,
			'name' => $name,
			'price_minor' => absint( $in['price_minor'] ?? 0 ),
			'currency' => 3 === strlen( $currency ) ? $currency : 'EUR',
			'capacity' => absint( $in['capacity'] ?? 0 ),
			'max_per_order' => max( 1, min( 100, absint( $in['max_per_order'] ?? 10 ) ) ),
			'tax_rate_bp' => absint( $in['tax_rate_bp'] ?? 0 ),
			'status' => 'active',
		) );
		$type_id = (int) $wpdb->insert_id;
		if ( ! $type_id || '' !== $wpdb->last_error ) { return self::error( 'wpcap_pass_save', __( 'Der Pass konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$saved = self::save( $type_id, $in );
		if ( is_wp_error( $saved ) ) {
			$wpdb->delete( "{$p}wpcap_ticket_types", array( 'ticket_type_id' => $type_id ) );
			return $saved;
		}
		Audit::record( 'pass_created', 'event', (int) $anchor->event_id, array( 'ticket_type_id' => $type_id, 'occurrences' => $saved->occurrence_ids ) );
		return $type_id;
	}

	/**
	 * Turns a ticket type into a pass or changes its configuration.
	 *
	 * @param array $in occurrence_ids (int[] or comma list), entry_mode, max_occurrences, area_ids,
	 *                  refund_per_day_minor, on_cancel.
	 * @return object|\WP_Error Configuration (see get()).
	 */
	public static function save( $type_id, array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type = self::type_row( $type_id );
		if ( ! $type ) { return self::error( 'wpcap_pass_type', __( 'Ticketart nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $type->event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$previous = self::get( $type_id );
		if ( $previous ) {
			// Options not given keep their current value.
			$in += array( 'occurrence_ids' => $previous->occurrence_ids, 'entry_mode' => $previous->entry_mode, 'max_occurrences' => $previous->max_occurrences, 'area_ids' => $previous->area_ids, 'refund_per_day_minor' => $previous->refund_per_day_minor, 'on_cancel' => $previous->on_cancel );
		}
		$raw = $in['occurrence_ids'] ?? array();
		$ids = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		sort( $ids );
		if ( ! $ids ) { return self::error( 'wpcap_pass_occurrences', __( 'Bitte mindestens einen Termin für den Pass wählen.', 'pass-captain' ) ); }
		if ( count( $ids ) > self::MAX_OCCURRENCES ) { return self::error( 'wpcap_pass_occurrences', __( 'Zu viele Termine (höchstens 366).', 'pass-captain' ) ); }
		$occurrences = array();
		foreach ( (array) $wpdb->get_results( "SELECT occurrence_id,event_id,capacity,status FROM {$p}wpcap_occurrences WHERE occurrence_id IN (" . implode( ',', $ids ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$occurrences[ (int) $row->occurrence_id ] = $row;
		}
		$event_ids = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $occurrences[ $id ] ) ) { return self::error( 'wpcap_pass_occurrences', __( 'Ein gewählter Termin existiert nicht.', 'pass-captain' ), 404 ); }
			$event_ids[ (int) $occurrences[ $id ]->event_id ] = true;
		}
		foreach ( array_keys( $event_ids ) as $event_id ) {
			if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für ein Event der gewählten Termine.', 'pass-captain' ), 403 ); }
		}
		$mode = (string) ( $in['entry_mode'] ?? 'once' );
		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'once';
		$max = absint( $in['max_occurrences'] ?? 0 );
		if ( $max > count( $ids ) ) { return self::error( 'wpcap_pass_limit', __( 'Die Anzahl der nutzbaren Termine darf nicht größer sein als die Anzahl der gewählten Termine.', 'pass-captain' ) ); }
		if ( $max === count( $ids ) ) { $max = 0; }
		$areas_raw = $in['area_ids'] ?? array();
		$area_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $areas_raw ) ? $areas_raw : preg_split( '/[\s,;]+/', (string) $areas_raw ) ) ) ) );
		if ( $area_ids ) {
			$valid = array_map( 'intval', $wpdb->get_col( "SELECT area_id FROM {$p}wpcap_admission_areas WHERE area_id IN (" . implode( ',', $area_ids ) . ') AND event_id IN (' . implode( ',', array_keys( $event_ids ) ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer lists.
			if ( count( $valid ) !== count( $area_ids ) ) { return self::error( 'wpcap_pass_areas', __( 'Einlassbereiche müssen zu den Events der gewählten Termine gehören.', 'pass-captain' ) ); }
			sort( $area_ids );
		}
		$on_cancel = (string) ( $in['on_cancel'] ?? 'keep' );
		$on_cancel = in_array( $on_cancel, self::ON_CANCEL, true ) ? $on_cancel : 'keep';
		$refund = absint( $in['refund_per_day_minor'] ?? 0 );
		if ( $refund > (int) $type->price_minor ) { return self::error( 'wpcap_pass_refund', __( 'Die Teilerstattung pro Tag darf den Passpreis nicht übersteigen.', 'pass-captain' ) ); }

		// Occurrences added to a pass that is already sold must have room for the sold passes.
		$sold = self::sold_units( $type_id );
		$now = current_time( 'mysql', true );
		if ( $sold > 0 ) {
			foreach ( array_diff( $ids, $previous ? $previous->occurrence_ids : array() ) as $id ) {
				$occ = $occurrences[ $id ];
				if ( (int) $occ->capacity < 1 || (int) $occ->occurrence_id === (int) $type->occurrence_id ) { continue; }
				$used = Capacity::core_used( $id, 0, $now ) + Capacity::pass_used( $id, 0, $now, true );
				if ( $used + $sold > (int) $occ->capacity ) {
					return self::error( 'wpcap_pass_capacity', __( 'Ein neu gewählter Termin hat nicht genug freie Plätze für die bereits verkauften Pässe.', 'pass-captain' ), 409 );
				}
			}
		}

		$gates = self::table( 'gates' );
		foreach ( $ids as $id ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $gates (occurrence_id,pass_quota,updated_at) VALUES (%d,0,%s)", $id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		}
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . self::table( 'types' ) . ' (type_id,entry_mode,max_occurrences,area_ids,refund_per_day_minor,on_cancel,created_at,updated_at) VALUES (%d,%s,%d,%s,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE entry_mode=VALUES(entry_mode),max_occurrences=VALUES(max_occurrences),area_ids=VALUES(area_ids),refund_per_day_minor=VALUES(refund_per_day_minor),on_cancel=VALUES(on_cancel),updated_at=VALUES(updated_at)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
			(int) $type->ticket_type_id, $mode, $max, wp_json_encode( $area_ids ), $refund, $on_cancel, $now, $now
		) );
		if ( '' !== $wpdb->last_error ) { return self::error( 'wpcap_pass_save', __( 'Der Pass konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$links = self::table( 'occurrences' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $links WHERE type_id=%d AND occurrence_id NOT IN (" . implode( ',', $ids ) . ')', (int) $type->ticket_type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, integer list.
		foreach ( $ids as $id ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $links (type_id,occurrence_id) VALUES (%d,%d)", (int) $type->ticket_type_id, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		}
		self::flush();
		Audit::record( 'pass_saved', 'event', (int) $type->event_id, array( 'ticket_type_id' => (int) $type->ticket_type_id, 'occurrences' => $ids, 'entry_mode' => $mode, 'max_occurrences' => $max ) );
		return self::get( $type_id );
	}

	/** Turns a pass back into an ordinary ticket type (only while none is sold or held). */
	public static function remove( $type_id ) {
		global $wpdb;
		$type = self::type_row( $type_id );
		if ( ! $type || ! self::is_pass( $type_id ) ) { return self::error( 'wpcap_pass_type', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $type->event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		if ( self::sold_units( $type_id ) > 0 ) { return self::error( 'wpcap_pass_sold', __( 'Der Pass wurde bereits verkauft; die verknüpften Termine können nur geändert, nicht entfernt werden.', 'pass-captain' ), 409 ); }
		$wpdb->delete( self::table( 'occurrences' ), array( 'type_id' => absint( $type_id ) ) );
		$wpdb->delete( self::table( 'types' ), array( 'type_id' => absint( $type_id ) ) );
		self::flush();
		Audit::record( 'pass_removed', 'event', (int) $type->event_id, array( 'ticket_type_id' => absint( $type_id ) ) );
		return true;
	}

	/** Sets the pass quota of a linked occurrence (max. pass units counted there, 0 = none). */
	public static function set_quota( $occurrence_id, $quota ) {
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) );
		if ( ! $event_id ) { return self::error( 'wpcap_pass_occurrences', __( 'Termin nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$gates = self::table( 'gates' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO $gates (occurrence_id,pass_quota,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE pass_quota=VALUES(pass_quota),updated_at=VALUES(updated_at)", $occurrence_id, absint( $quota ), current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		Audit::record( 'pass_quota_saved', 'occurrence', $occurrence_id, array( 'pass_quota' => absint( $quota ) ) );
		return true;
	}
}
