<?php
/**
 * Per-buyer ticket limits ("max. Tickets pro Käufer") per event and/or ticket type, across all
 * orders of the buyer (paid tickets and active reservations).
 *
 * Buyers are identified by their normalised e-mail address (lowercase; Gmail dots/plus tags via
 * the filter `wpcap_allocation_normalize_gmail`, any rule via `wpcap_allocation_buyer_email`) and,
 * if enabled, additionally by the logged-in WordPress user id. Every identity is checked.
 *
 * Race-free counting: in `wpcap_order_creating` (inside the order transaction) one row per
 * (event, buyer key) is locked with INSERT … ON DUPLICATE KEY UPDATE (exclusive lock, also for a new
 * row) in a fixed order. The row lists the buyer's orders of the event with their quantities, so a
 * concurrent order of the same buyer that committed after this transaction's snapshot is still
 * counted (from the row, which is read with a locking read). Orders visible in the snapshot are
 * counted from the core tables, so expired holds, cancellations and refunds no longer count.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Allocations;

use PassCaptain\Modules\Allocations as Module;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BuyerLimits {
	private static $messages = array();

	public static function error_messages() {
		return array_merge(
			array( 'allocation_buyer_limit' => __( 'Für dieses Event ist die maximale Anzahl an Tickets pro Person erreicht.', 'wp-pass-captain' ) ),
			self::$messages
		);
	}

	/** Sources that are not limited (box office, guest list, admin by default). */
	public static function exempt_sources() {
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'wpcap_allocation_limit_exempt_sources', array( 'pos', 'guestlist', 'admin' ) ) ) ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Configuration.
	 * ------------------------------------------------------------------------------------------- */

	/** Limits of an event: ticket_type_id (0 = whole event) => max per buyer. */
	public static function for_event( $event_id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,max_per_buyer FROM ' . Holds::table( 'limits' ) . ' WHERE event_id=%d AND max_per_buyer>0', absint( $event_id ) ) ) as $row ) {
			$out[ (int) $row->ticket_type_id ] = (int) $row->max_per_buyer;
		}
		return $out;
	}

	/**
	 * Sets (or with 0 removes) a limit.
	 *
	 * @return true|\WP_Error
	 */
	public static function set( $event_id, $ticket_type_id, $max ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$ticket_type_id = absint( $ticket_type_id );
		$max = absint( $max );
		if ( ! $event_id ) { return new \WP_Error( 'wpcap_alloc_event', __( 'Event nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( $ticket_type_id ) {
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d", $ticket_type_id ) );
			if ( $owner !== $event_id ) { return new \WP_Error( 'wpcap_alloc_type', __( 'Die Ticketart gehört nicht zu diesem Event.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		}
		if ( ! $max ) {
			$wpdb->delete( Holds::table( 'limits' ), array( 'event_id' => $event_id, 'ticket_type_id' => $ticket_type_id ) );
			return true;
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Holds::table( 'limits' ) . ' (event_id,ticket_type_id,max_per_buyer,updated_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE max_per_buyer=VALUES(max_per_buyer),updated_at=VALUES(updated_at)', $event_id, $ticket_type_id, $max, current_time( 'mysql', true ) ) );
		return '' === $wpdb->last_error ? true : new \WP_Error( 'wpcap_alloc_save', __( 'Das Limit konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Buyer identity.
	 * ------------------------------------------------------------------------------------------- */

	/** Normalised e-mail address used as buyer identity. */
	public static function normalize_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at = strrpos( $email, '@' );
		if ( false !== $at && apply_filters( 'wpcap_allocation_normalize_gmail', false ) ) {
			$local = substr( $email, 0, $at );
			$domain = substr( $email, $at + 1 );
			if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
				$plus = strpos( $local, '+' );
				$local = str_replace( '.', '', false === $plus ? $local : substr( $local, 0, $plus ) );
				$email = $local . '@gmail.com';
			}
		}
		return (string) apply_filters( 'wpcap_allocation_buyer_email', $email );
	}

	/**
	 * Buyer keys (sha256) of an order: e-mail and, if enabled, the WordPress user.
	 *
	 * @param string $email
	 * @param int    $user_id 0 = none.
	 * @return string[] Sorted keys.
	 */
	public static function keys( $email, $user_id = 0 ) {
		$keys = array();
		$email = self::normalize_email( $email );
		if ( '' !== $email ) { $keys[] = hash( 'sha256', 'email:' . $email ); }
		if ( $user_id && ! empty( Module::config()['buyer_limit_by_user'] ) ) { $keys[] = hash( 'sha256', 'user:' . absint( $user_id ) ); }
		sort( $keys );
		return array_values( array_unique( $keys ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Enforcement.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * wpcap_order_creating (priority 6): enforces the limits of the order's events.
	 *
	 * @throws \RuntimeException allocation_buyer_limit (message via wpcap_checkout_error_messages), db_busy.
	 */
	public static function on_order_creating( $order_id, $selected, $payload = array(), $internal = array() ) {
		global $wpdb;
		self::$messages = array();
		$internal = is_array( $internal ) ? $internal : array();
		$payload = is_array( $payload ) ? $payload : array();
		$source = sanitize_key( (string) ( $internal['source'] ?? ( $internal['key_namespace'] ?? 'native' ) ) );
		$by_event = array();
		$names = array();
		foreach ( (array) $selected as $line ) {
			$event_id = (int) $line['event_id'];
			$type_id = (int) $line['type']->ticket_type_id;
			$by_event[ $event_id ][ $type_id ] = ( $by_event[ $event_id ][ $type_id ] ?? 0 ) + (int) $line['quantity'];
			$names[ $type_id ] = (string) $line['type']->name;
		}
		ksort( $by_event, SORT_NUMERIC );
		$limits = array();
		foreach ( array_keys( $by_event ) as $event_id ) {
			$event_limits = self::for_event( $event_id );
			if ( $event_limits ) { $limits[ $event_id ] = $event_limits; }
		}
		if ( ! $limits ) { return; }
		$exempt = in_array( $source, self::exempt_sources(), true );
		/** Whether the buyer limit applies to this order (default: not for exempt sources). */
		if ( ! apply_filters( 'wpcap_allocation_buyer_limit_applies', ! $exempt, $source, $selected, $internal ) ) { return; }
		$user_id = isset( $internal['customer_user_id'] ) ? absint( $internal['customer_user_id'] ) : ( in_array( $source, array( 'native', 'woocommerce', 'waitlist' ), true ) ? absint( get_current_user_id() ) : 0 );
		$keys = self::keys( (string) ( $payload['email'] ?? '' ), $user_id );
		if ( ! $keys ) { return; }
		$now = current_time( 'mysql', true );
		$table = Holds::table( 'buyers' );
		foreach ( $limits as $event_id => $event_limits ) {
			$ordered = $by_event[ $event_id ];
			$ordered_total = array_sum( $ordered );
			foreach ( $keys as $key ) {
				// Exclusive row lock, also when the row is new (no shared-lock upgrade, no gap lock).
				$wpdb->query( $wpdb->prepare( "INSERT INTO $table (event_id,buyer_key,holdings,updated_at) VALUES (%d,%s,'{}',%s) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)", $event_id, $key, $now ) );
				$row = '' === $wpdb->last_error ? $wpdb->get_row( $wpdb->prepare( "SELECT buyer_id,holdings FROM $table WHERE event_id=%d AND buyer_key=%s FOR UPDATE", $event_id, $key ) ) : null;
				if ( ! $row ) { throw new \RuntimeException( 'db_busy' ); }
				$holdings = json_decode( (string) $row->holdings, true );
				$holdings = is_array( $holdings ) ? $holdings : array();
				$current = self::current( $holdings, $now );
				if ( null === $current ) { throw new \RuntimeException( 'db_busy' ); }
				$had_total = array_sum( $current['types'] );
				if ( isset( $event_limits[0] ) && $had_total + $ordered_total > $event_limits[0] ) {
					/* translators: 1: maximum tickets per person, 2: tickets the buyer already has */
					self::$messages['allocation_buyer_limit'] = sprintf( __( 'Pro Person sind höchstens %1$d Tickets für dieses Event möglich; du hast bereits %2$d.', 'wp-pass-captain' ), $event_limits[0], $had_total );
					throw new \RuntimeException( 'allocation_buyer_limit' );
				}
				foreach ( $ordered as $type_id => $quantity ) {
					$max = $event_limits[ $type_id ] ?? 0;
					$had = (int) ( $current['types'][ $type_id ] ?? 0 );
					if ( $max && $had + $quantity > $max ) {
						/* translators: 1: maximum tickets per person, 2: ticket type, 3: tickets the buyer already has */
						self::$messages['allocation_buyer_limit'] = sprintf( __( 'Pro Person sind höchstens %1$d Tickets der Ticketart „%2$s“ möglich; du hast bereits %3$d.', 'wp-pass-captain' ), $max, $names[ $type_id ] ?? '', $had );
						throw new \RuntimeException( 'allocation_buyer_limit' );
					}
				}
				$holdings = $current['holdings'];
				$holdings[ (string) (int) $order_id ] = array_map( 'intval', $ordered );
				$wpdb->query( $wpdb->prepare( "UPDATE $table SET holdings=%s,updated_at=%s WHERE buyer_id=%d", wp_json_encode( $holdings ), $now, (int) $row->buyer_id ) );
				if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			}
		}
	}

	/**
	 * Tickets a buyer currently has from the listed orders (order_id => [type_id => qty]).
	 * Orders visible to this transaction are counted from the core tables (paid units minus
	 * refunded, or active unexpired reservations); orders not visible yet (committed after the
	 * snapshot) count with their recorded quantities. Finished orders are pruned.
	 *
	 * @return array|null types (type_id => qty) and pruned holdings; null on a database error.
	 */
	public static function current( array $holdings, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_filter( array_map( 'absint', array_keys( $holdings ) ) ) );
		$types = array();
		if ( ! $ids ) { return array( 'types' => array(), 'holdings' => array() ); }
		$in = implode( ',', $ids );
		$orders = array();
		foreach ( (array) $wpdb->get_results( "SELECT order_id,status,payment_status FROM {$p}wpcap_orders WHERE order_id IN ($in)" ) as $order ) { $orders[ (int) $order->order_id ] = $order; }
		$paid = array();
		foreach ( (array) $wpdb->get_results( "SELECT order_id,ticket_type_id,SUM(quantity - refunded_quantity) AS units FROM {$p}wpcap_order_items WHERE order_id IN ($in) GROUP BY order_id,ticket_type_id" ) as $row ) { $paid[ (int) $row->order_id ][ (int) $row->ticket_type_id ] = (int) $row->units; }
		$held = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id,ticket_type_id,SUM(quantity) AS units FROM {$p}wpcap_reservations WHERE order_id IN ($in) AND status='active' AND expires_at > %s GROUP BY order_id,ticket_type_id", $now ) ) as $row ) { $held[ (int) $row->order_id ][ (int) $row->ticket_type_id ] = (int) $row->units; }
		if ( '' !== $wpdb->last_error ) { return null; }
		$kept = array();
		foreach ( $holdings as $order_id => $recorded ) {
			$order_id = absint( $order_id );
			$recorded = is_array( $recorded ) ? array_map( 'intval', $recorded ) : array();
			$order = $orders[ $order_id ] ?? null;
			if ( ! $order ) {
				$counts = $recorded; // Committed after our snapshot: trust the recorded quantities.
			} elseif ( in_array( $order->status, array( 'cancelled', 'expired', 'refunded' ), true ) ) {
				continue; // Finished for good: pruned.
			} elseif ( 'paid' === $order->payment_status ) {
				$counts = array_intersect_key( $paid[ $order_id ] ?? array(), $recorded );
			} elseif ( 'pending' === $order->payment_status ) {
				$counts = array_intersect_key( $held[ $order_id ] ?? array(), $recorded );
			} else {
				$counts = array(); // e.g. paid_unallocated: no tickets.
			}
			$kept[ (string) $order_id ] = $recorded;
			foreach ( $counts as $type_id => $units ) { $types[ (int) $type_id ] = ( $types[ (int) $type_id ] ?? 0 ) + max( 0, (int) $units ); }
		}
		return array( 'types' => $types, 'holdings' => $kept );
	}

	/** Tickets a buyer (e-mail) currently has for an event (for staff/tests; non-locking). */
	public static function count_for( $event_id, $email, $user_id = 0 ) {
		global $wpdb;
		$max = 0;
		foreach ( self::keys( $email, $user_id ) as $key ) {
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT holdings FROM ' . Holds::table( 'buyers' ) . ' WHERE event_id=%d AND buyer_key=%s', absint( $event_id ), $key ) );
			$holdings = json_decode( (string) $raw, true );
			$current = self::current( is_array( $holdings ) ? $holdings : array(), current_time( 'mysql', true ) );
			$max = max( $max, array_sum( $current ? $current['types'] : array() ) );
		}
		return $max;
	}

	/** wpcap_checkout_form_fields: shows the limits on the event card. */
	public static function render_notice( $event = null, $types = array() ) {
		if ( ! is_object( $event ) || empty( $event->event_id ) ) { return; }
		$limits = self::for_event( (int) $event->event_id );
		if ( ! $limits ) { return; }
		$lines = array();
		if ( isset( $limits[0] ) ) {
			/* translators: %d: maximum tickets per person */
			$lines[] = sprintf( __( 'Max. %d Tickets pro Person für dieses Event.', 'wp-pass-captain' ), $limits[0] );
		}
		foreach ( (array) $types as $type ) {
			$max = $limits[ (int) ( $type->ticket_type_id ?? 0 ) ] ?? 0;
			/* translators: 1: ticket type, 2: maximum tickets per person */
			if ( $max ) { $lines[] = sprintf( __( '%1$s: max. %2$d pro Person.', 'wp-pass-captain' ), $type->name, $max ); }
		}
		if ( $lines ) { echo '<p class="wpcap-buyer-limit">' . esc_html( implode( ' ', $lines ) ) . '</p>'; }
	}
}
