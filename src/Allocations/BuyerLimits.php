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
			array( 'allocation_buyer_limit' => __( 'Für dieses Event ist die maximale Anzahl an Tickets pro Person erreicht.', 'pass-captain' ) ),
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
		if ( null === self::$limits ) {
			// All limits in one query per request (the event card asks per card).
			self::$limits = array();
			foreach ( (array) $wpdb->get_results( 'SELECT event_id,ticket_type_id,max_per_buyer FROM ' . Holds::table( 'limits' ) . ' WHERE max_per_buyer>0' ) as $row ) {
				self::$limits[ (int) $row->event_id ][ (int) $row->ticket_type_id ] = (int) $row->max_per_buyer;
			}
		}
		return self::$limits[ absint( $event_id ) ] ?? array();
	}

	/** Request cache of limits (event id => type id => max); reset by flush(). */
	private static $limits = null;

	public static function flush() {
		self::$limits = null;
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
		if ( ! $event_id ) { return new \WP_Error( 'wpcap_alloc_event', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $ticket_type_id ) {
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d", $ticket_type_id ) );
			if ( $owner !== $event_id ) { return new \WP_Error( 'wpcap_alloc_type', __( 'Die Ticketart gehört nicht zu diesem Event.', 'pass-captain' ), array( 'status' => 400 ) ); }
		}
		if ( ! $max ) {
			$wpdb->delete( Holds::table( 'limits' ), array( 'event_id' => $event_id, 'ticket_type_id' => $ticket_type_id ) );
			return true;
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Holds::table( 'limits' ) . ' (event_id,ticket_type_id,max_per_buyer,updated_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE max_per_buyer=VALUES(max_per_buyer),updated_at=VALUES(updated_at)', $event_id, $ticket_type_id, $max, current_time( 'mysql', true ) ) );
		return '' === $wpdb->last_error ? true : new \WP_Error( 'wpcap_alloc_save', __( 'Das Limit konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
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
		if ( '' !== $email ) { $keys[] = self::hash( 'email:' . $email ); }
		if ( $user_id && ! empty( Module::config()['buyer_limit_by_user'] ) ) { $keys[] = self::hash( 'user:' . absint( $user_id ) ); }
		sort( $keys );
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Keyed hash of a buyer identity (site secret): a plain sha256 of an e-mail address could be
	 * recomputed from a known address and re-identify anonymised orders.
	 */
	public static function hash( $value ) {
		$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'wpcap' );
		return hash_hmac( 'sha256', 'wpcap-buyer|' . (string) $value, $secret );
	}

	/** Version of the buyer key derivation (2 = HMAC). */
	const KEY_VERSION = 2;

	/**
	 * One-time migration to keyed hashes: the buyer rows are rebuilt from the orders of events with
	 * limits (e-mail identities; the old unkeyed rows are deleted). Runs once (option, lock).
	 */
	public static function maybe_migrate_keys() {
		global $wpdb;
		if ( (int) get_option( 'wpcap_alloc_key_version', 0 ) >= self::KEY_VERSION ) { return false; }
		$table = Holds::table( 'buyers' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { return false; }
		if ( ! (int) $wpdb->get_var( "SELECT GET_LOCK('wpcap_alloc_keys', 0)" ) ) { return false; }
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$events = array_map( 'absint', (array) $wpdb->get_col( 'SELECT DISTINCT event_id FROM ' . Holds::table( 'limits' ) . ' WHERE max_per_buyer>0' ) );
		foreach ( $events as $event_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ord.order_id,ord.customer_email,oi.ticket_type_id,SUM(oi.quantity) AS qty FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.status NOT IN ('cancelled','expired','refunded') GROUP BY ord.order_id,ord.customer_email,oi.ticket_type_id", $event_id ) );
			$by_key = array();
			foreach ( (array) $rows as $row ) {
				foreach ( self::keys( (string) $row->customer_email ) as $key ) {
					$by_key[ $key ][ (string) (int) $row->order_id ][ (int) $row->ticket_type_id ] = (int) $row->qty;
				}
			}
			foreach ( $by_key as $key => $holdings ) {
				$wpdb->insert( $table, array( 'event_id' => $event_id, 'buyer_key' => $key, 'holdings' => wp_json_encode( $holdings ), 'updated_at' => $now ) );
			}
		}
		update_option( 'wpcap_alloc_key_version', self::KEY_VERSION );
		$wpdb->query( "SELECT RELEASE_LOCK('wpcap_alloc_keys')" );
		return true;
	}

	/** Privacy eraser: removes the buyer rows of an e-mail address (all events). */
	public static function privacy_erase( $email_address, $page = 1 ) {
		global $wpdb;
		$keys = self::keys( (string) $email_address );
		$removed = 0;
		foreach ( $keys as $key ) {
			$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Holds::table( 'buyers' ) . ' WHERE buyer_key=%s', $key ) );
		}
		return array( 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

	/** Privacy exporter: events for which buyer-limit counts exist for the address. */
	public static function privacy_export( $email_address, $page = 1 ) {
		global $wpdb;
		$data = array();
		foreach ( self::keys( (string) $email_address ) as $key ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT b.event_id,b.holdings,b.updated_at,e.name FROM ' . Holds::table( 'buyers' ) . " b LEFT JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=b.event_id WHERE b.buyer_key=%s", $key ) ) as $row ) {
				$data[] = array( 'group_id' => 'pass-captain-buyer-limits', 'group_label' => __( 'Pass Captain Käuferlimit', 'pass-captain' ), 'item_id' => 'buyer-limit-' . (int) $row->event_id, 'data' => array(
					array( 'name' => __( 'Event', 'pass-captain' ), 'value' => (string) $row->name ),
					array( 'name' => __( 'Gezählte Bestellungen (Bestell-ID => Ticketart => Menge)', 'pass-captain' ), 'value' => (string) $row->holdings ),
					array( 'name' => __( 'Aktualisiert (UTC)', 'pass-captain' ), 'value' => (string) $row->updated_at ),
				) );
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Retention (wpcap_privacy_retention_steps): buyer rows of events whose last occurrence ended
	 * more than `attendee_retention_days` (or the default attendee retention) ago are deleted.
	 */
	public static function retention_step( $counts, $now, $deadline ) {
		global $wpdb;
		if ( microtime( true ) >= $deadline ) { return $counts; }
		$days = \PassCaptain\Privacy\Retention::attendee_days() ?: \PassCaptain\Privacy\Retention::default_attendee_days();
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $now - $days * DAY_IN_SECONDS );
		$table = Holds::table( 'buyers' );
		$counts['buyer_limits'] = (int) $wpdb->query( $wpdb->prepare( "DELETE b FROM $table b WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}wpcap_occurrences o WHERE o.event_id=b.event_id AND COALESCE(o.ends_at, DATE_ADD(o.starts_at, INTERVAL 1 DAY))>=%s)", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return $counts;
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
					self::$messages['allocation_buyer_limit'] = sprintf( __( 'Pro Person sind höchstens %1$d Tickets für dieses Event möglich; du hast bereits %2$d.', 'pass-captain' ), $event_limits[0], $had_total );
					throw new \RuntimeException( 'allocation_buyer_limit' );
				}
				foreach ( $ordered as $type_id => $quantity ) {
					$max = $event_limits[ $type_id ] ?? 0;
					$had = (int) ( $current['types'][ $type_id ] ?? 0 );
					if ( $max && $had + $quantity > $max ) {
						/* translators: 1: maximum tickets per person, 2: ticket type, 3: tickets the buyer already has */
						self::$messages['allocation_buyer_limit'] = sprintf( __( 'Pro Person sind höchstens %1$d Tickets der Ticketart „%2$s“ möglich; du hast bereits %3$d.', 'pass-captain' ), $max, $names[ $type_id ] ?? '', $had );
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
	 * wpcap_reserve_capacity (priority 25), phase 'exchange': a ticket exchange into another type is
	 * one more ticket of the target type for the buyer of the original order (the exchanged unit has
	 * already left its line in this transaction). Same locked buyer rows as on_order_creating(); the
	 * target type is recorded for the order so later purchases count it. Staff channels (admin, pos)
	 * are exempt like in the checkout (`wpcap_allocation_limit_exempt_sources`).
	 *
	 * @return true|\WP_Error
	 */
	public static function reserve_exchange( $result, $demand, $context ) {
		global $wpdb;
		if ( is_wp_error( $result ) || 'exchange' !== ( $context['phase'] ?? '' ) ) { return $result; }
		$exchange = (array) ( $context['exchange'] ?? array() );
		$order_id = absint( $exchange['order_id'] ?? 0 );
		$source = sanitize_key( (string) ( $context['source'] ?? 'exchange' ) );
		if ( ! $order_id || in_array( $source, self::exempt_sources(), true ) ) { return $result; }
		$occurrence = $context['occurrences'] ? reset( $context['occurrences'] ) : null;
		$type_id = (int) key( (array) $demand );
		$quantity = (int) current( (array) $demand );
		if ( ! $occurrence || ! $type_id ) { return $result; }
		$event_id = (int) $occurrence->event_id;
		$limits = self::for_event( $event_id );
		if ( ! $limits || ( ! isset( $limits[0] ) && ! isset( $limits[ $type_id ] ) ) ) { return $result; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT customer_email FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", $order_id ) );
		$keys = $order ? self::keys( (string) $order->customer_email ) : array();
		if ( ! $keys ) { return $result; }
		$now = (string) ( $context['now'] ?? current_time( 'mysql', true ) );
		$table = Holds::table( 'buyers' );
		$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
		foreach ( $keys as $key ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO $table (event_id,buyer_key,holdings,updated_at) VALUES (%d,%s,'{}',%s) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)", $event_id, $key, $now ) );
			$row = '' === $wpdb->last_error ? $wpdb->get_row( $wpdb->prepare( "SELECT buyer_id,holdings FROM $table WHERE event_id=%d AND buyer_key=%s FOR UPDATE", $event_id, $key ) ) : null;
			if ( ! $row ) { return \PassCaptain\Errors::busy( '', 'db_busy' ); }
			$holdings = json_decode( (string) $row->holdings, true );
			$holdings = is_array( $holdings ) ? $holdings : array();
			// The original order counts with all its current lines (also types it got by exchanges).
			$recorded = (array) ( $holdings[ (string) $order_id ] ?? array() );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d", $order_id ) ) as $line_type ) {
				$recorded[ (int) $line_type ] = max( 1, (int) ( $recorded[ (int) $line_type ] ?? 0 ) );
			}
			$holdings[ (string) $order_id ] = $recorded;
			$current = self::current( $holdings, $now );
			if ( null === $current ) { return \PassCaptain\Errors::busy( '', 'db_busy' ); }
			if ( isset( $limits[0] ) && array_sum( $current['types'] ) + $quantity > $limits[0] ) {
				/* translators: %d: maximum tickets per person */
				return new \WP_Error( 'allocation_buyer_limit', sprintf( _n( 'Pro Person ist höchstens %d Ticket für dieses Event möglich.', 'Pro Person sind höchstens %d Tickets für dieses Event möglich.', $limits[0], 'pass-captain' ), $limits[0]), array( 'status' => 409 ) );
			}
			$max = $limits[ $type_id ] ?? 0;
			$had = (int) ( $current['types'][ $type_id ] ?? 0 );
			if ( $max && $had + $quantity > $max ) {
				/* translators: 1: maximum tickets per person, 2: ticket type, 3: tickets the buyer already has */
				return new \WP_Error( 'allocation_buyer_limit', sprintf( __( 'Pro Person sind höchstens %1$d Tickets der Ticketart „%2$s“ möglich; du hast bereits %3$d.', 'pass-captain' ), $max, $name, $had ), array( 'status' => 409 ) );
			}
			$holdings = $current['holdings'];
			$holdings[ (string) $order_id ] = array_replace( (array) ( $holdings[ (string) $order_id ] ?? array() ), array( $type_id => max( 1, (int) ( $recorded[ $type_id ] ?? 0 ) ) ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET holdings=%s,updated_at=%s WHERE buyer_id=%d", wp_json_encode( $holdings ), $now, (int) $row->buyer_id ) );
			if ( '' !== $wpdb->last_error ) { return \PassCaptain\Errors::busy( '', 'db_busy' ); }
		}
		return $result;
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

	/** wpcap_event_card_quantity_max: the card's stepper stops at the per-person limit of the type. */
	public static function card_quantity_max( $max, $type = null, $event = null ) {
		if ( ! is_object( $event ) || empty( $event->event_id ) || ! is_object( $type ) ) { return $max; }
		$limits = self::for_event( (int) $event->event_id );
		$limit = (int) ( $limits[ (int) ( $type->ticket_type_id ?? 0 ) ] ?? 0 );
		return $limit ? min( (int) $max, $limit ) : $max;
	}

	/** wpcap_event_card_max_total: per-person limit for the whole event caps all types together. */
	public static function card_max_total( $max, $event = null ) {
		if ( ! is_object( $event ) || empty( $event->event_id ) ) { return $max; }
		$limit = (int) ( self::for_event( (int) $event->event_id )[0] ?? 0 );
		return $limit && ( ! $max || $limit < $max ) ? $limit : $max;
	}

	/** wpcap_checkout_form_fields: shows the limits on the event card. */
	public static function render_notice( $event = null, $types = array() ) {
		if ( ! is_object( $event ) || empty( $event->event_id ) ) { return; }
		$limits = self::for_event( (int) $event->event_id );
		if ( ! $limits ) { return; }
		$lines = array();
		if ( isset( $limits[0] ) ) {
			/* translators: %d: maximum tickets per person */
			$lines[] = sprintf( _n( 'Max. %d Ticket pro Person für dieses Event.', 'Max. %d Tickets pro Person für dieses Event.', $limits[0], 'pass-captain' ), $limits[0]);
		}
		foreach ( (array) $types as $type ) {
			$max = $limits[ (int) ( $type->ticket_type_id ?? 0 ) ] ?? 0;
			/* translators: 1: ticket type, 2: maximum tickets per person */
			if ( $max ) { $lines[] = sprintf( __( '%1$s: max. %2$d pro Person.', 'pass-captain' ), $type->name, $max ); }
		}
		if ( $lines ) { echo '<p class="wpcap-buyer-limit">' . esc_html( implode( ' ', $lines ) ) . '</p>'; }
	}
}
