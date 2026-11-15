<?php
/**
 * Buyer limits across orders: at most N tickets per buyer for an event, one occurrence or one
 * ticket type. A buyer is matched by order e-mail (case-insensitive), by the logged-in customer
 * account (orders.customer_user_id) or by either ("both").
 *
 * Counted are tickets of paid, not refunded orders (minus refunded units) plus active, unexpired
 * holds of pending orders, i.e. the same basis as the capacity check. Enforcement is atomic:
 * Orders::create() takes a per-buyer database lock (GET_LOCK on the e-mail and on the customer id)
 * before its transaction starts, so the consistent snapshot of the transaction already contains
 * every committed order of that buyer, and checks inside the transaction after all row locks.
 * Concurrent orders of the same buyer are serialised; other buyers are not slowed down.
 *
 * Late payment confirmations of expired holds (Orders::confirm_external_payment) are re-checked
 * under the same buyer locks; a payment that would exceed a limit is not turned into tickets but
 * flagged `needs_review` (the money was already taken: the operator refunds it or raises the limit).
 * Not covered: box office sales (door sales, staff-entered e-mail).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Access;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BuyerLimits {
	const CAP = 'wpcap_manage_events';
	const MATCH = array( 'email', 'customer', 'both' );

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Normalised e-mail used for matching (filter `wpcap_buyer_limit_email`). */
	public static function normalize_email( $email ) {
		return (string) apply_filters( 'wpcap_buyer_limit_email', strtolower( trim( (string) $email ) ) );
	}

	/** Active limits of the events. @return object[] */
	public static function for_events( array $event_ids ) {
		global $wpdb;
		$event_ids = array_values( array_filter( array_map( 'absint', $event_ids ) ) );
		if ( ! $event_ids ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status='active' AND event_id IN (" . implode( ',', array_fill( 0, count( $event_ids ), '%d' ) ) . ') ORDER BY limit_id', $wpdb->prefix . 'wpcap_buyer_limits', ...$event_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- absint ids.
		return is_array( $rows ) ? $rows : array();
	}

	/** Creates a limit. Input: occurrence_id, ticket_type_id (0 = all), max_quantity, match_by. */
	public static function create( $event_id, array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id=%d', $p . 'wpcap_events', $event_id ) ) ) { return self::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$occurrence_id = absint( $in['occurrence_id'] ?? 0 );
		$type_id = absint( $in['ticket_type_id'] ?? 0 );
		$max = absint( $in['max_quantity'] ?? 0 );
		$match = in_array( (string) ( $in['match_by'] ?? '' ), self::MATCH, true ) ? (string) $in['match_by'] : 'both';
		if ( $max < 1 || $max > 10000 ) { return self::error( 'wpcap_buyer_limit_max', __( 'Bitte eine Höchstmenge zwischen 1 und 10000 angeben.', 'pass-captain' ) ); }
		if ( $occurrence_id && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $occurrence_id ) ) !== $event_id ) {
			return self::error( 'wpcap_buyer_limit_scope', __( 'Der Termin gehört nicht zu diesem Event.', 'pass-captain' ) );
		}
		if ( $type_id ) {
			$type = $wpdb->get_row( $wpdb->prepare( 'SELECT tt.occurrence_id,o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $type_id ) );
			if ( ! $type || (int) $type->event_id !== $event_id || ( $occurrence_id && (int) $type->occurrence_id !== $occurrence_id ) ) {
				return self::error( 'wpcap_buyer_limit_scope', __( 'Die Ticketart gehört nicht zu diesem Event bzw. Termin.', 'pass-captain' ) );
			}
		}
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( "{$p}wpcap_buyer_limits", array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id, 'max_quantity' => $max, 'match_by' => $match, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		if ( ! $ok ) { return self::error( 'wpcap_buyer_limit_save', __( 'Das Käuferlimit konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$id = (int) $wpdb->insert_id;
		Audit::record( 'buyer_limit_created', 'event', $event_id, array( 'limit_id' => $id, 'max' => $max, 'match_by' => $match, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id ) );
		return $id;
	}

	/** Deactivates a limit. */
	public static function delete( $limit_id ) {
		global $wpdb;
		$limit = $wpdb->get_row( $wpdb->prepare( 'SELECT limit_id,event_id FROM %i WHERE limit_id=%d', $wpdb->prefix . 'wpcap_buyer_limits', absint( $limit_id ) ) );
		if ( ! $limit ) { return self::error( 'wpcap_buyer_limit_missing', __( 'Käuferlimit nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $limit->event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$wpdb->update( "{$wpdb->prefix}wpcap_buyer_limits", array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ), array( 'limit_id' => (int) $limit->limit_id ) );
		Audit::record( 'buyer_limit_deleted', 'event', (int) $limit->event_id, array( 'limit_id' => (int) $limit->limit_id ) );
		return true;
	}

	/** Lock names for a buyer (sorted, MySQL limit 64 characters). */
	public static function lock_names( $email, $user_id ) {
		global $wpdb;
		$salt = substr( md5( (string) $wpdb->prefix ), 0, 8 );
		$names = array( 'wpcap_buyer_' . $salt . '_e' . substr( hash( 'sha256', self::normalize_email( $email ) ), 0, 32 ) );
		if ( absint( $user_id ) ) { $names[] = 'wpcap_buyer_' . $salt . '_u' . absint( $user_id ); }
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Whether any active limit could apply to the ticket types (cheap pre-check before locking).
	 *
	 * @param int[] $type_ids
	 */
	public static function relevant( array $type_ids ) {
		global $wpdb;
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids ) { return false; }
		$p = $wpdb->prefix;
		$hit = $wpdb->get_var( $wpdb->prepare( "SELECT bl.limit_id FROM %i bl INNER JOIN %i o ON o.event_id=bl.event_id INNER JOIN %i tt ON tt.occurrence_id=o.occurrence_id WHERE bl.status='active' AND tt.ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ') LIMIT 1', $p . 'wpcap_buyer_limits', $p . 'wpcap_occurrences', $p . 'wpcap_ticket_types', ...$type_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- absint ids.
		return (bool) $hit;
	}

	/**
	 * Takes the buyer locks (waits up to `wpcap_buyer_limit_lock_wait` seconds, default 10).
	 *
	 * @return string[]|false Lock names to release, or false when a lock could not be taken.
	 */
	public static function acquire( $email, $user_id ) {
		global $wpdb;
		$wait = max( 1, (int) apply_filters( 'wpcap_buyer_limit_lock_wait', 10 ) );
		$held = array();
		foreach ( self::lock_names( $email, $user_id ) as $name ) {
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, $wait ) ) ) {
				self::release( $held );
				return false;
			}
			$held[] = $name;
		}
		return $held;
	}

	/** Releases buyer locks taken by acquire(). */
	public static function release( $names ) {
		global $wpdb;
		foreach ( array_reverse( (array) $names ) as $name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Tickets of the buyer already counting against a limit (paid + active holds), excluding $exclude_order_id.
	 */
	public static function used( $limit, $email, $user_id, $now, $exclude_order_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$limit = (object) $limit;
		$who = array();
		$email = self::normalize_email( $email );
		if ( in_array( $limit->match_by, array( 'email', 'both' ), true ) && '' !== $email ) { $who[] = $wpdb->prepare( 'LOWER(ord.customer_email)=%s', $email ); }
		if ( in_array( $limit->match_by, array( 'customer', 'both' ), true ) && absint( $user_id ) ) { $who[] = $wpdb->prepare( 'ord.customer_user_id=%d', absint( $user_id ) ); }
		if ( ! $who ) { return 0; }
		$scope = $wpdb->prepare( 'o.event_id=%d', (int) $limit->event_id );
		if ( (int) $limit->occurrence_id ) { $scope .= $wpdb->prepare( ' AND o.occurrence_id=%d', (int) $limit->occurrence_id ); }
		if ( (int) $limit->ticket_type_id ) { $scope .= $wpdb->prepare( ' AND tt.ticket_type_id=%d', (int) $limit->ticket_type_id ); }
		$from = "{$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id";
		$where = '(' . implode( ' OR ', $who ) . ') AND ' . $scope . $wpdb->prepare( ' AND ord.order_id<>%d', absint( $exclude_order_id ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments prepared above.
		$paid = (int) $wpdb->get_var( "SELECT COALESCE(SUM(oi.quantity-oi.refunded_quantity),0) FROM $from WHERE $where AND ord.payment_status='paid' AND ord.status<>'refunded'" );
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE $where AND r.status='active' AND r.expires_at>%s AND ord.payment_status<>'paid'", $now ) );
		// phpcs:enable
		return $paid + $held;
	}

	/**
	 * Checks the requested quantities against all limits. Call inside the order transaction after
	 * every row lock and with the buyer locks held.
	 *
	 * @param array    $lines       ticket_type_id => quantity.
	 * @param object[] $types       ticket_type_id => row.
	 * @param object[] $occurrences occurrence_id => row (event_id, event_name).
	 * @param int      $exclude_order_id Order not counted as already used (the order being confirmed).
	 * @return \WP_Error|null
	 */
	public static function check( array $lines, array $types, array $occurrences, $email, $user_id, $now, $exclude_order_id = 0 ) {
		$events = array();
		foreach ( $occurrences as $occurrence ) { if ( $occurrence ) { $events[] = (int) $occurrence->event_id; } }
		$limits = self::for_events( array_unique( $events ) );
		foreach ( $limits as $limit ) {
			$requested = 0;
			foreach ( $lines as $type_id => $quantity ) {
				$type = $types[ $type_id ] ?? null;
				$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
				if ( ! $occurrence || (int) $occurrence->event_id !== (int) $limit->event_id ) { continue; }
				if ( (int) $limit->occurrence_id && (int) $limit->occurrence_id !== (int) $type->occurrence_id ) { continue; }
				if ( (int) $limit->ticket_type_id && (int) $limit->ticket_type_id !== (int) $type_id ) { continue; }
				$requested += (int) $quantity;
			}
			if ( ! $requested ) { continue; }
			$used = self::used( $limit, $email, $user_id, $now, $exclude_order_id );
			if ( $used + $requested > (int) $limit->max_quantity ) {
				$left = max( 0, (int) $limit->max_quantity - $used );
				$message = $left > 0
					/* translators: 1: maximum per person, 2: tickets still possible */
					? sprintf( __( 'Pro Person sind hierfür höchstens %1$d Tickets möglich. Du kannst noch %2$d Ticket(s) buchen.', 'pass-captain' ), (int) $limit->max_quantity, $left )
					/* translators: %d: maximum per person */
					: sprintf( __( 'Pro Person sind hierfür höchstens %d Tickets möglich. Dieses Limit ist mit deinen bisherigen Bestellungen bereits erreicht.', 'pass-captain' ), (int) $limit->max_quantity );
				return new \WP_Error( 'buyer_limit', $message, array( 'status' => 409, 'limit_id' => (int) $limit->limit_id, 'remaining' => $left ) );
			}
		}
		return null;
	}
}
