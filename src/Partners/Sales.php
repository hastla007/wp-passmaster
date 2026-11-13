<?php
/**
 * Selling through the partner API (organizers and resellers with the write scopes orders:write,
 * orders:cancel, tickets:issue). REST handlers: SalesApi; reference: docs/PARTNER-API.md.
 *
 * Orders are created with the public core API Orders::create() using trusted internal options:
 * external (payment is collected by the partner), source/payment_provider "partner", key_namespace
 * "partner{ID}" (idempotency keys are separated per partner), hold_minutes from the partner (max. 60),
 * unit_prices from the partner price list (otherwise the core price incl. `wpcap_ticket_unit_price`)
 * and allocation_id when a partner contingent draws from an Allocations hold. Payment is confirmed
 * with Orders::confirm_external_payment(); paid orders are cancelled within the partner's window via
 * Refunds::record_external() (the partner refunds the buyer itself).
 *
 * Inside the order transaction (`wpcap_order_creating`, priority 8) this class
 *  - locks the partner's contingent rows (FOR UPDATE, by id) and counts their usage with a locking
 *    read of its own usage table, so concurrent partner orders can never exceed a contingent;
 *  - stores the seller of record (wpcap_partner_orders) atomically with the order.
 *
 * Ticket codes exist in plaintext only at issuance. For partner orders they are captured from the
 * ticket mail filter and stored encrypted (secretbox, key derived from the WordPress auth salt) so the
 * partner, as holder of record, can download them again (scope tickets:issue). Later rotations
 * (resend, reissue, print) update the stored code; a transfer/assignment to another holder deletes it.
 */
namespace PassCaptain\Partners;

use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Sales {
	const SOURCE = 'partner';
	const PROVIDER = 'partner';
	const MODES = array( 'reserve', 'sell' );
	const PLACEHOLDER_PREFIX = 'partner+';
	const PLACEHOLDER_DOMAIN = '@invalid.example';
	const MAX_ITEMS = 10;
	/** Rotation reasons after which the partner is no longer the holder of the ticket code. */
	const FOREIGN_ROTATIONS = array( 'transfer', 'assign' );

	private static $registered = false;
	/** Dynamic abort messages of the running order (code => text). */
	private static $messages = array();

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'wpcap_order_creating', array( __CLASS__, 'on_order_creating' ), 8, 4 );
		add_filter( 'wpcap_ticket_mail', array( __CLASS__, 'capture_codes' ), 1, 3 );
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'suppress_placeholder_mail' ), 10, 2 );
		add_filter( 'wpcap_allocation_trusted_sources', array( __CLASS__, 'trusted_sources' ) );
		add_filter( 'wpcap_checkout_error_messages', array( __CLASS__, 'error_messages' ) );
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_' . $name;
	}

	private static function error( $code, $message, $status = 400, array $extra = array() ) {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	/* ------------------------------------------------------------------ Hooks */

	/** Partner sales may consume Allocations holds (contingent with allocation_id; hold sources empty or "partner"). */
	public static function trusted_sources( $sources ) {
		$sources = (array) $sources;
		$sources[] = self::SOURCE;
		return array_values( array_unique( $sources ) );
	}

	public static function error_messages( $messages ) {
		return array_merge( (array) $messages, array(
			'partner_contingent_exhausted' => __( 'Das Kontingent dieses Veranstalters für diesen Termin reicht nicht aus.', 'wp-pass-captain' ),
		), self::$messages );
	}

	public static function is_placeholder_email( $email ) {
		$email = strtolower( (string) $email );
		return 0 === strpos( $email, self::PLACEHOLDER_PREFIX ) && self::PLACEHOLDER_DOMAIN === substr( $email, -strlen( self::PLACEHOLDER_DOMAIN ) );
	}

	/** pre_wp_mail: drop mails that only go to partner placeholder addresses (anonymous buyers). */
	public static function suppress_placeholder_mail( $return, $atts = array() ) {
		$to = $atts['to'] ?? array();
		$to = is_array( $to ) ? $to : explode( ',', (string) $to );
		if ( $to && ! array_filter( $to, static function ( $r ) { return ! self::is_placeholder_email( trim( (string) $r ) ); } ) ) {
			return true;
		}
		return $return;
	}

	/**
	 * wpcap_order_creating (priority 8, inside the order transaction): enforces the partner's
	 * contingents and records the partner as seller. Only runs for `$internal['partner_sale']`.
	 *
	 * @throws \RuntimeException partner_contingent_exhausted / db_busy.
	 */
	public static function on_order_creating( $order_id, $selected, $payload = array(), $internal = array() ) {
		global $wpdb;
		$sale = is_array( $internal ) ? ( $internal['partner_sale'] ?? null ) : null;
		if ( ! is_array( $sale ) ) { return; }
		$partner_id = absint( $sale['partner_id'] ?? 0 );
		if ( ! $partner_id ) { throw new \RuntimeException( 'db_busy' ); }
		$now = current_time( 'mysql', true );
		$occurrences = array();
		foreach ( (array) $selected as $line ) { $occurrences[] = (int) $line['type']->occurrence_id; }
		$occurrences = array_values( array_unique( array_filter( $occurrences ) ) );
		sort( $occurrences, SORT_NUMERIC );
		$usage = array();
		if ( $occurrences ) {
			// Lock order: ticket types → occurrences → allocation holds (core/Allocations) → partner contingents (by id).
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'partner_contingents' ) . ' WHERE partner_id=%d AND occurrence_id IN (' . implode( ',', $occurrences ) . ') ORDER BY contingent_id FOR UPDATE', $partner_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			foreach ( (array) $rows as $c ) {
				$demand = self::demand_for( $c, $selected );
				if ( ! $demand ) { continue; }
				$usage[ (int) $c->contingent_id ] = $demand;
				if ( (int) $c->quantity <= 0 ) { continue; }
				$used = self::contingent_used( $c, $now, true );
				if ( null === $used ) { throw new \RuntimeException( 'db_busy' ); }
				if ( $used + $demand > (int) $c->quantity ) {
					/* translators: %d: remaining units */
					self::$messages['partner_contingent_exhausted'] = sprintf( __( 'Im Kontingent dieses Veranstalters sind nur noch %d Tickets frei.', 'wp-pass-captain' ), max( 0, (int) $c->quantity - $used ) );
					throw new \RuntimeException( 'partner_contingent_exhausted' );
				}
			}
		}
		$inserted = $wpdb->insert( self::t( 'partner_orders' ), array(
			'order_id' => (int) $order_id,
			'partner_id' => $partner_id,
			'key_id' => absint( $sale['key_id'] ?? 0 ),
			'mode' => in_array( $sale['mode'] ?? '', self::MODES, true ) ? $sale['mode'] : 'reserve',
			'channel' => substr( (string) ( $sale['channel'] ?? '' ), 0, 64 ),
			'external_reference' => substr( (string) ( $sale['external_reference'] ?? '' ), 0, 100 ),
			'anonymous' => empty( $sale['anonymous'] ) ? 0 : 1,
			'request_hash' => (string) ( $sale['request_hash'] ?? '' ),
			'created_at' => $now,
		) );
		if ( ! $inserted || '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		foreach ( $usage as $contingent_id => $quantity ) {
			$wpdb->insert( self::t( 'partner_contingent_usage' ), array( 'order_id' => (int) $order_id, 'contingent_id' => $contingent_id, 'quantity' => $quantity ) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		}
	}

	/** Units of the selected order lines that fall into a contingent's scope. */
	private static function demand_for( $contingent, $selected ) {
		$demand = 0;
		foreach ( (array) $selected as $line ) {
			$type = $line['type'];
			if ( (int) $type->occurrence_id !== (int) $contingent->occurrence_id ) { continue; }
			if ( (int) $contingent->ticket_type_id && (int) $contingent->ticket_type_id !== (int) $type->ticket_type_id ) { continue; }
			$demand += (int) $line['quantity'];
		}
		return $demand;
	}

	/**
	 * Units of a contingent used by the partner's orders: paid units (minus refunded) and units of
	 * pending orders with an active hold.
	 *
	 * With $locking the usage rows are read with a locking read (latest committed version) while the
	 * contingent row is locked; order states are read from the transaction snapshot. An order that is
	 * not visible in the snapshot committed after it and counts in full; stale states can only
	 * over-count (never oversell).
	 *
	 * @return int|null Null on a database error.
	 */
	public static function contingent_used( $contingent, $now = null, $locking = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = $now ?: current_time( 'mysql', true );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,quantity FROM ' . self::t( 'partner_contingent_usage' ) . ' WHERE contingent_id=%d' . ( $locking ? ' LOCK IN SHARE MODE' : '' ), (int) $contingent->contingent_id ) );
		if ( '' !== $wpdb->last_error ) { return null; }
		if ( ! $rows ) { return 0; }
		$in = implode( ',', array_map( static function ( $r ) { return (int) $r->order_id; }, $rows ) );
		$orders = array();
		foreach ( $wpdb->get_results( "SELECT order_id,status,payment_status FROM {$p}wpcap_orders WHERE order_id IN ($in)" ) as $o ) { $orders[ (int) $o->order_id ] = $o; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$held = array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$p}wpcap_reservations WHERE order_id IN ($in) AND status='active' AND expires_at > %s", $now ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$type_sql = (int) $contingent->ticket_type_id ? $wpdb->prepare( ' AND oi.ticket_type_id=%d', (int) $contingent->ticket_type_id ) : '';
		$paid = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT oi.order_id,SUM(oi.quantity-oi.refunded_quantity) AS units FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id IN ($in) AND tt.occurrence_id=%d{$type_sql} GROUP BY oi.order_id", (int) $contingent->occurrence_id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$paid[ (int) $row->order_id ] = (int) $row->units;
		}
		if ( '' !== $wpdb->last_error ) { return null; }
		$used = 0;
		foreach ( $rows as $r ) {
			$id = (int) $r->order_id;
			$o = $orders[ $id ] ?? null;
			if ( ! $o ) { $used += (int) $r->quantity; continue; }
			if ( in_array( $o->status, array( 'cancelled', 'expired', 'refunded' ), true ) ) { continue; }
			if ( 'paid' === $o->payment_status ) { $used += min( (int) $r->quantity, max( 0, $paid[ $id ] ?? 0 ) ); continue; }
			if ( 'pending' === $o->payment_status && isset( $held[ $id ] ) ) { $used += (int) $r->quantity; }
		}
		return $used;
	}

	/** wpcap_capacity_released: deletes contingent usage rows of an order that is cancelled, expired or refunded. */
	public static function prune_usage( $order_id = 0 ) {
		global $wpdb;
		$order = \PassCaptain\Orders::get( absint( $order_id ) );
		if ( ! $order || ! in_array( $order->status, array( 'cancelled', 'expired', 'refunded' ), true ) ) { return 0; }
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'partner_contingent_usage' ) . ' WHERE order_id=%d', absint( $order_id ) ) );
	}

	/* ------------------------------------------------------------------ Assortment, prices, contingents */

	/** Price list rows of a partner: ticket_type_id => row. */
	public static function prices( $partner_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'partner_prices' ) . ' WHERE partner_id=%d ORDER BY ticket_type_id', absint( $partner_id ) ) ) as $row ) {
			$out[ (int) $row->ticket_type_id ] = $row;
		}
		return $out;
	}

	/** Events of the partner's price list (a reseller's assortment). */
	public static function assortment_event_ids( $partner ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $partner || Repository::ROLE_RESELLER !== Repository::role_of( $partner ) ) { return array(); }
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$p}wpcap_partner_prices pp INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=pp.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE pp.partner_id=%d ORDER BY o.event_id", (int) $partner->partner_id ) ) );
	}

	/**
	 * Ticket types the partner (optionally: this key) may sell.
	 * Organizer: every type of its own events (price list rows override the price).
	 * Reseller: the types of its price list ("Sortiment"). A key narrows this by its event and ticket
	 * type restriction.
	 *
	 * @return array<int,array> ticket_type_id => [event_id, occurrence_id, price_minor (gross|null = core price), price_is_net]
	 */
	public static function sellable_types( $partner, $key = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $partner ) { return array(); }
		$prices = self::prices( $partner->partner_id );
		$select = "SELECT tt.ticket_type_id,tt.occurrence_id,o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id";
		if ( Repository::ROLE_RESELLER === Repository::role_of( $partner ) ) {
			$ids = array_keys( $prices );
			$rows = $ids ? $wpdb->get_results( "$select WHERE tt.ticket_type_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY tt.ticket_type_id' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		} else {
			$events = Repository::event_ids( $partner->partner_id );
			$rows = $events ? $wpdb->get_results( "$select WHERE o.event_id IN (" . implode( ',', array_map( 'intval', $events ) ) . ') ORDER BY tt.ticket_type_id' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$key_events = $key ? Keys::restricted_events( $key ) : array();
		$key_types = $key ? Keys::restricted_types( $key ) : array();
		$out = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->ticket_type_id;
			if ( $key_events && ! in_array( (int) $row->event_id, $key_events, true ) ) { continue; }
			if ( $key_types && ! in_array( $id, $key_types, true ) ) { continue; }
			$price = $prices[ $id ] ?? null;
			$out[ $id ] = array(
				'ticket_type_id' => $id,
				'event_id' => (int) $row->event_id,
				'occurrence_id' => (int) $row->occurrence_id,
				'price_minor' => $price && null !== $price->price_minor ? (int) $price->price_minor : null,
				'price_is_net' => $price ? (bool) $price->price_is_net : false,
			);
		}
		return $out;
	}

	/** Gross unit price of a price list entry (net prices get the ticket type's VAT, half up). */
	public static function gross_price( array $entry, $type ) {
		if ( null === $entry['price_minor'] ) { return null; }
		$price = (int) $entry['price_minor'];
		if ( ! empty( $entry['price_is_net'] ) ) {
			$price += intdiv( 2 * $price * (int) $type->tax_rate_bp + 10000, 20000 );
		}
		return max( 0, $price );
	}

	/**
	 * Adds/updates a price list entry. $price_minor null = core price (assortment entry only).
	 *
	 * @return true|\WP_Error
	 */
	public static function set_price( $partner_id, $ticket_type_id, $price_minor = null, $is_net = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$partner = Repository::get( $partner_id );
		if ( ! $partner ) { return self::error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", absint( $ticket_type_id ) ) );
		if ( ! $event_id ) { return self::error( 'wpcap_partner_price_type', __( 'Ticketart nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( Repository::ROLE_ORGANIZER === Repository::role_of( $partner ) && ! in_array( $event_id, Repository::event_ids( $partner->partner_id ), true ) ) {
			return self::error( 'wpcap_partner_price_type', __( 'Veranstalter können nur Ticketarten ihrer eigenen Events verkaufen. Für fremde Events die Rolle „Wiederverkäufer“ verwenden.', 'wp-pass-captain' ) );
		}
		if ( null !== $price_minor && (int) $price_minor < 0 ) { return self::error( 'wpcap_partner_price', __( 'Der Preis darf nicht negativ sein.', 'wp-pass-captain' ) ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$p}wpcap_partner_prices (partner_id,ticket_type_id,price_minor,price_is_net,created_at,updated_at) VALUES (%d,%d," . ( null === $price_minor ? 'NULL' : (int) $price_minor ) . ',%d,%s,%s) ON DUPLICATE KEY UPDATE price_minor=VALUES(price_minor),price_is_net=VALUES(price_is_net),updated_at=VALUES(updated_at)', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer.
			(int) $partner->partner_id,
			absint( $ticket_type_id ),
			$is_net ? 1 : 0,
			$now,
			$now
		) );
		Audit::record( 'partner_price_set', 'partner', (int) $partner->partner_id, array( 'ticket_type_id' => absint( $ticket_type_id ), 'price_minor' => null === $price_minor ? null : (int) $price_minor, 'net' => (bool) $is_net ) );
		return true;
	}

	public static function delete_price( $partner_id, $ticket_type_id ) {
		global $wpdb;
		$wpdb->delete( self::t( 'partner_prices' ), array( 'partner_id' => absint( $partner_id ), 'ticket_type_id' => absint( $ticket_type_id ) ) );
		Audit::record( 'partner_price_deleted', 'partner', absint( $partner_id ), array( 'ticket_type_id' => absint( $ticket_type_id ) ) );
		return true;
	}

	public static function contingents( $partner_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'partner_contingents' ) . ' WHERE partner_id=%d ORDER BY occurrence_id,ticket_type_id', absint( $partner_id ) ) );
	}

	public static function contingent( $contingent_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'partner_contingents' ) . ' WHERE contingent_id=%d', absint( $contingent_id ) ) );
	}

	/**
	 * Creates or updates the contingent of a partner for an occurrence (optionally one ticket type).
	 *
	 * @param array $data occurrence_id, ticket_type_id (0 = all types), quantity (0 = no own limit),
	 *                    allocation_id (Allocations hold to sell from, 0 = public capacity).
	 * @return int|\WP_Error Contingent id.
	 */
	public static function save_contingent( $partner_id, array $data ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$partner = Repository::get( $partner_id );
		if ( ! $partner ) { return self::error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$occurrence_id = absint( $data['occurrence_id'] ?? 0 );
		$type_id = absint( $data['ticket_type_id'] ?? 0 );
		$quantity = (int) ( $data['quantity'] ?? 0 );
		$allocation_id = absint( $data['allocation_id'] ?? 0 );
		if ( ! $occurrence_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) ) ) {
			return self::error( 'wpcap_partner_contingent', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 );
		}
		if ( $type_id && $occurrence_id !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) ) ) {
			return self::error( 'wpcap_partner_contingent', __( 'Die Ticketart gehört nicht zu diesem Termin.', 'wp-pass-captain' ) );
		}
		if ( $quantity < 0 || $quantity > 1000000 ) { return self::error( 'wpcap_partner_contingent', __( 'Ungültige Kontingentgröße.', 'wp-pass-captain' ) ); }
		if ( $allocation_id ) {
			$hold = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_alloc_holds WHERE hold_id=%d", $allocation_id ) );
			if ( ! $hold || 'hold' !== $hold->kind || (int) $hold->occurrence_id !== $occurrence_id || ( (int) $hold->ticket_type_id && $type_id && (int) $hold->ticket_type_id !== $type_id ) ) {
				return self::error( 'wpcap_partner_contingent_hold', __( 'Das Kontingent (Allocations) passt nicht zu diesem Termin bzw. dieser Ticketart.', 'wp-pass-captain' ) );
			}
		}
		if ( ! $quantity && ! $allocation_id ) { return self::error( 'wpcap_partner_contingent', __( 'Bitte eine Menge oder ein Kontingent (Allocations) angeben.', 'wp-pass-captain' ) ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$p}wpcap_partner_contingents (partner_id,occurrence_id,ticket_type_id,quantity,allocation_id,created_at,updated_at) VALUES (%d,%d,%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),allocation_id=VALUES(allocation_id),updated_at=VALUES(updated_at)",
			(int) $partner->partner_id, $occurrence_id, $type_id, $quantity, $allocation_id, $now, $now
		) );
		if ( '' !== $wpdb->last_error ) { return self::error( 'wpcap_partner_contingent_save', __( 'Kontingent konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT contingent_id FROM {$p}wpcap_partner_contingents WHERE partner_id=%d AND occurrence_id=%d AND ticket_type_id=%d", (int) $partner->partner_id, $occurrence_id, $type_id ) );
		Audit::record( 'partner_contingent_saved', 'partner', (int) $partner->partner_id, array( 'contingent_id' => $id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id, 'quantity' => $quantity, 'allocation_id' => $allocation_id ) );
		return $id;
	}

	public static function delete_contingent( $contingent_id ) {
		global $wpdb;
		$c = self::contingent( $contingent_id );
		if ( ! $c ) { return self::error( 'wpcap_partner_contingent', __( 'Kontingent nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$wpdb->delete( self::t( 'partner_contingents' ), array( 'contingent_id' => (int) $c->contingent_id ) );
		Audit::record( 'partner_contingent_deleted', 'partner', (int) $c->partner_id, array( 'contingent_id' => (int) $c->contingent_id ) );
		return array( 'contingent_id' => (int) $c->contingent_id );
	}

	/**
	 * Sellable types with prices and remaining availability for the key (GET /partner/catalog).
	 */
	public static function catalog( array $ctx ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$types = self::sellable_types( $ctx['partner'], $ctx['key'] );
		if ( ! $types ) { return array(); }
		$rows = $wpdb->get_results( "SELECT tt.*,o.starts_at,o.ends_at,o.timezone,o.location,o.status AS occurrence_status,o.event_id,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id IN (" . implode( ',', array_keys( $types ) ) . ') ORDER BY o.starts_at,tt.ticket_type_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$contingents = self::contingents( $ctx['partner']->partner_id );
		$out = array();
		foreach ( $rows as $tt ) {
			$entry = $types[ (int) $tt->ticket_type_id ];
			$override = self::gross_price( $entry, $tt );
			$remaining = Orders::availability( (int) $tt->ticket_type_id );
			foreach ( $contingents as $c ) {
				if ( (int) $c->occurrence_id !== (int) $tt->occurrence_id || ( (int) $c->ticket_type_id && (int) $c->ticket_type_id !== (int) $tt->ticket_type_id ) || (int) $c->quantity <= 0 ) { continue; }
				$left = max( 0, (int) $c->quantity - (int) self::contingent_used( $c ) );
				$remaining = null === $remaining ? $left : min( $remaining, $left );
			}
			$out[] = array(
				'ticket_type_id' => (int) $tt->ticket_type_id,
				'name' => $tt->name,
				'event_id' => (int) $tt->event_id,
				'event_name' => $tt->event_name,
				'occurrence_id' => (int) $tt->occurrence_id,
				'starts_at' => self::iso( $tt->starts_at ),
				'timezone' => $tt->timezone,
				'location' => $tt->location,
				'currency' => $tt->currency,
				'price_minor' => null !== $override ? $override : Orders::unit_price( $tt, null, 1, array( 'source' => self::SOURCE ) ),
				'price_source' => null !== $override ? 'partner' : 'core',
				'tax_rate_bp' => (int) $tt->tax_rate_bp,
				'max_per_order' => (int) $tt->max_per_order,
				'available' => null === $remaining ? null : (int) $remaining,
				'on_sale' => 'active' === $tt->status && 'published' === $tt->event_status && 'scheduled' === $tt->occurrence_status,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Orders */

	private static function iso( $utc ) {
		return $utc ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $utc . ' UTC' ) ) : null;
	}

	/** Seller-of-record row of an order (null = no partner API order). */
	public static function sale_row( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'partner_orders' ) . ' WHERE order_id=%d', absint( $order_id ) ) );
	}

	/** Order ids sold by a partner (newest first). */
	public static function order_ids( $partner_id, $limit = 100, $offset = 0 ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM ' . self::t( 'partner_orders' ) . ' WHERE partner_id=%d ORDER BY order_id DESC LIMIT %d OFFSET %d', absint( $partner_id ), max( 1, (int) $limit ), max( 0, (int) $offset ) ) ) );
	}

	/**
	 * Partner API order by public id, only if it was sold by the key's partner and every line lies
	 * within the key's sellable types (event/type restriction). Anything else is "not found".
	 *
	 * @return array|\WP_Error ['order' => row, 'sale' => row]
	 */
	public static function find( array $ctx, $public_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$public_id = strtolower( (string) $public_id );
		$missing = self::error( 'wpcap_partner_order_missing', __( 'Bestellung nicht gefunden.', 'wp-pass-captain' ), 404 );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $public_id ) ) { return $missing; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT ord.* FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_partner_orders po ON po.order_id=ord.order_id WHERE ord.public_id=%s AND po.partner_id=%d", $public_id, (int) $ctx['partner']->partner_id ) );
		if ( ! $order ) { return $missing; }
		$allowed = self::sellable_types( $ctx['partner'], $ctx['key'] );
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$p}wpcap_order_items WHERE order_id=%d", (int) $order->order_id ) ) as $type_id ) {
			if ( ! isset( $allowed[ (int) $type_id ] ) ) { return $missing; }
		}
		return array( 'order' => $order, 'sale' => self::sale_row( $order->order_id ) );
	}

	private static function has_active_hold( $order_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s LIMIT 1", absint( $order_id ), current_time( 'mysql', true ) ) );
	}

	/** Strict body check: only the listed fields are accepted. */
	private static function unknown_fields( $body, array $allowed ) {
		if ( null === $body || array() === $body ) { return null; }
		if ( ! is_array( $body ) ) { return self::error( 'wpcap_partner_invalid_body', __( 'Der Request-Body muss ein JSON-Objekt sein.', 'wp-pass-captain' ) ); }
		$unknown = array_diff( array_map( 'strval', array_keys( $body ) ), $allowed );
		if ( $unknown ) {
			/* translators: %s: field names */
			return self::error( 'wpcap_partner_invalid_field', sprintf( __( 'Unbekannte Felder: %s', 'wp-pass-captain' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) ), 400, array( 'fields' => array_values( array_map( 'sanitize_key', $unknown ) ) ) );
		}
		return null;
	}

	/** External references: printable ASCII without quotes/markup, up to 100 characters. */
	private static function valid_reference( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9 ._:\/#+-]{1,100}$/', $value );
	}

	public static function valid_idempotency_key( $key ) {
		return is_string( $key ) && (bool) preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $key );
	}

	/**
	 * Validates a create request.
	 *
	 * @return array|\WP_Error Normalised request.
	 */
	public static function validate_create( array $ctx, $body, $idempotency_key ) {
		if ( ! self::valid_idempotency_key( $idempotency_key ) ) {
			return self::error( 'wpcap_partner_idempotency_key', __( 'Der Header Idempotency-Key ist erforderlich (8–100 Zeichen: Buchstaben, Ziffern, . _ : -).', 'wp-pass-captain' ) );
		}
		if ( ! is_array( $body ) ) { return self::error( 'wpcap_partner_invalid_body', __( 'Der Request-Body muss ein JSON-Objekt sein.', 'wp-pass-captain' ) ); }
		$unknown = self::unknown_fields( $body, array( 'mode', 'items', 'buyer', 'anonymous_buyer', 'attendees', 'external_reference', 'payment_reference' ) );
		if ( $unknown ) { return $unknown; }
		$mode = $body['mode'] ?? 'reserve';
		if ( ! in_array( $mode, self::MODES, true ) ) { return self::error( 'wpcap_partner_invalid_mode', __( 'mode muss "reserve" oder "sell" sein.', 'wp-pass-captain' ), 400, array( 'field' => 'mode' ) ); }
		$items = $body['items'] ?? null;
		if ( ! is_array( $items ) || ! $items || count( $items ) > self::MAX_ITEMS || array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) {
			/* translators: %d: maximum lines */
			return self::error( 'wpcap_partner_invalid_items', sprintf( __( 'items muss eine Liste mit 1 bis %d Positionen sein.', 'wp-pass-captain' ), self::MAX_ITEMS ), 400, array( 'field' => 'items' ) );
		}
		$sellable = self::sellable_types( $ctx['partner'], $ctx['key'] );
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'ticket_type_id', 'quantity' ) ) || ! is_int( $item['ticket_type_id'] ?? null ) || ! is_int( $item['quantity'] ?? null ) || $item['ticket_type_id'] < 1 || $item['quantity'] < 1 || $item['quantity'] > 100 ) {
				return self::error( 'wpcap_partner_invalid_items', __( 'Jede Position braucht ticket_type_id (Ganzzahl) und quantity (1–100).', 'wp-pass-captain' ), 400, array( 'field' => 'items' ) );
			}
			if ( ! isset( $sellable[ $item['ticket_type_id'] ] ) ) {
				return self::error( 'wpcap_partner_type_forbidden', __( 'Diese Ticketart darf mit diesem API-Key nicht verkauft werden.', 'wp-pass-captain' ), 403, array( 'ticket_type_id' => $item['ticket_type_id'] ) );
			}
			$clean[ $item['ticket_type_id'] ] = ( $clean[ $item['ticket_type_id'] ] ?? 0 ) + $item['quantity'];
		}
		ksort( $clean );
		$anonymous = $body['anonymous_buyer'] ?? false;
		if ( ! is_bool( $anonymous ) ) { return self::error( 'wpcap_partner_invalid_buyer', __( 'anonymous_buyer muss true oder false sein.', 'wp-pass-captain' ), 400, array( 'field' => 'anonymous_buyer' ) ); }
		$buyer = $body['buyer'] ?? array();
		if ( ! is_array( $buyer ) || array_diff( array_keys( $buyer ), array( 'email' ) ) ) {
			return self::error( 'wpcap_partner_invalid_buyer', __( 'buyer darf nur das Feld email enthalten.', 'wp-pass-captain' ), 400, array( 'field' => 'buyer' ) );
		}
		$email = isset( $buyer['email'] ) && is_string( $buyer['email'] ) ? sanitize_email( $buyer['email'] ) : '';
		if ( $anonymous ) {
			if ( empty( $ctx['partner']->anonymous_buyer ) ) {
				return self::error( 'wpcap_partner_anonymous_forbidden', __( 'Verkäufe ohne Käufer-E-Mail sind für diesen Veranstalter nicht freigegeben.', 'wp-pass-captain' ), 403 );
			}
			if ( ! in_array( 'tickets:issue', $ctx['scopes'], true ) ) {
				return self::error( 'wpcap_partner_anonymous_forbidden', __( 'Verkäufe ohne Käufer-E-Mail erfordern den Scope tickets:issue (die Tickets werden an den Veranstalter übergeben).', 'wp-pass-captain' ), 403 );
			}
			if ( isset( $buyer['email'] ) ) { return self::error( 'wpcap_partner_invalid_buyer', __( 'Bei anonymous_buyer darf keine Käufer-E-Mail angegeben werden.', 'wp-pass-captain' ), 400, array( 'field' => 'buyer.email' ) ); }
		} elseif ( ! is_email( $email ) || self::is_placeholder_email( $email ) || substr( strtolower( $email ), -strlen( self::PLACEHOLDER_DOMAIN ) ) === self::PLACEHOLDER_DOMAIN ) {
			return self::error( 'wpcap_partner_invalid_buyer', __( 'buyer.email ist erforderlich und muss eine gültige E-Mail-Adresse sein.', 'wp-pass-captain' ), 400, array( 'field' => 'buyer.email' ) );
		}
		$attendees = $body['attendees'] ?? array();
		if ( ! is_array( $attendees ) || count( $attendees ) > array_sum( $clean ) ) {
			return self::error( 'wpcap_partner_invalid_attendees', __( 'attendees muss eine Liste mit höchstens so vielen Einträgen wie Tickets sein.', 'wp-pass-captain' ), 400, array( 'field' => 'attendees' ) );
		}
		foreach ( $attendees as $a ) {
			if ( ! is_array( $a ) || array_diff( array_keys( $a ), array( 'ticket_type_id', 'name', 'email', 'answers' ) ) || ! isset( $clean[ absint( $a['ticket_type_id'] ?? 0 ) ] ) ) {
				return self::error( 'wpcap_partner_invalid_attendees', __( 'Jeder Teilnehmende braucht eine ticket_type_id aus items und darf nur name, email und answers enthalten.', 'wp-pass-captain' ), 400, array( 'field' => 'attendees' ) );
			}
		}
		$reference = $body['external_reference'] ?? '';
		if ( '' !== $reference && ! self::valid_reference( $reference ) ) {
			return self::error( 'wpcap_partner_invalid_reference', __( 'external_reference: höchstens 100 Zeichen (Buchstaben, Ziffern, Leerzeichen, . _ : / # + -).', 'wp-pass-captain' ), 400, array( 'field' => 'external_reference' ) );
		}
		$payment_reference = $body['payment_reference'] ?? '';
		if ( '' !== $payment_reference && ( 'sell' !== $mode || ! self::valid_reference( $payment_reference ) ) ) {
			return self::error( 'wpcap_partner_invalid_reference', __( 'payment_reference ist nur bei mode "sell" erlaubt (höchstens 100 Zeichen).', 'wp-pass-captain' ), 400, array( 'field' => 'payment_reference' ) );
		}
		$request = array( 'mode' => $mode, 'items' => $clean, 'email' => $anonymous ? '' : strtolower( $email ), 'anonymous' => $anonymous, 'attendees' => array_values( $attendees ), 'external_reference' => $reference, 'payment_reference' => $payment_reference );
		$request['hash'] = hash( 'sha256', (string) wp_json_encode( array( $request['mode'], $request['items'], $request['email'], $request['anonymous'], $request['attendees'], $request['external_reference'] ) ) );
		return $request;
	}

	/** Anonymous buyer: placeholder address (mails to it are suppressed), stable per partner and key. */
	private static function placeholder_email( $partner_id, $idempotency_key ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : ABSPATH;
		return self::PLACEHOLDER_PREFIX . substr( hash_hmac( 'sha256', (int) $partner_id . '|' . $idempotency_key, 'wpcap-partner|' . $salt ), 0, 16 ) . self::PLACEHOLDER_DOMAIN;
	}

	/**
	 * POST /partner/orders: reservation (mode reserve) or direct sale (mode sell = create + confirm).
	 *
	 * @return array|\WP_Error ['status' => 201|200, 'data' => response]
	 */
	public static function create( array $ctx, $body, $idempotency_key ) {
		global $wpdb;
		$partner = $ctx['partner'];
		$req = self::validate_create( $ctx, $body, $idempotency_key );
		if ( is_wp_error( $req ) ) { self::audit( $ctx, 'create', 0, $req ); return $req; }
		$sellable = self::sellable_types( $partner, $ctx['key'] );
		$unit_prices = array();
		$allocations = array();
		$contingents = self::contingents( $partner->partner_id );
		foreach ( $req['items'] as $type_id => $quantity ) {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
			if ( ! $type ) { continue; } // The core rejects it as unavailable.
			$gross = self::gross_price( $sellable[ $type_id ], $type );
			if ( null !== $gross ) { $unit_prices[ $type_id ] = $gross; }
			foreach ( $contingents as $c ) {
				if ( (int) $c->allocation_id && (int) $c->occurrence_id === (int) $type->occurrence_id && ( ! (int) $c->ticket_type_id || (int) $c->ticket_type_id === (int) $type_id ) ) {
					$allocations[ (int) $c->allocation_id ] = true;
				}
			}
		}
		if ( count( $allocations ) > 1 ) {
			$error = self::error( 'wpcap_partner_allocation_mixed', __( 'Die Positionen stammen aus verschiedenen Kontingenten. Bitte je Kontingent eine eigene Bestellung anlegen.', 'wp-pass-captain' ) );
			self::audit( $ctx, 'create', 0, $error );
			return $error;
		}
		$internal = array(
			'external' => true,
			'source' => self::SOURCE,
			'payment_provider' => self::PROVIDER,
			'key_namespace' => 'partner' . (int) $partner->partner_id,
			'hold_minutes' => max( 1, min( Repository::MAX_HOLD_MINUTES, (int) ( $partner->hold_minutes ?? 15 ) ?: 15 ) ),
			'unit_prices' => $unit_prices,
			'partner_sale' => array(
				'partner_id' => (int) $partner->partner_id,
				'key_id' => (int) $ctx['key']->key_id,
				'mode' => $req['mode'],
				'channel' => (string) ( $partner->sales_channel ?? '' ),
				'external_reference' => $req['external_reference'],
				'anonymous' => $req['anonymous'],
				'request_hash' => $req['hash'],
			),
		);
		if ( $allocations ) { $internal['allocation_id'] = (int) array_key_first( $allocations ); }
		$items = array();
		foreach ( $req['items'] as $type_id => $quantity ) { $items[] = array( 'ticket_type_id' => $type_id, 'quantity' => $quantity ); }
		$payload = array(
			'email' => $req['anonymous'] ? self::placeholder_email( $partner->partner_id, $idempotency_key ) : $req['email'],
			'items' => $items,
			'idempotency_key' => $idempotency_key,
			'attendees' => $req['attendees'],
		);
		self::$messages = array();
		$result = Orders::create( $payload, $internal );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			$status = (int) ( $data['status'] ?? 409 );
			$error = 'wpcap_checkout_failed' === $result->get_error_code()
				? self::error( 'wpcap_partner_order_rejected', $result->get_error_message(), $status, array( 'retryable' => ! empty( $data['retryable'] ) ) )
				: self::error( 'wpcap_partner_order_invalid', $result->get_error_message(), $status >= 400 ? $status : 400, array( 'reason' => $result->get_error_code() ) );
			self::audit( $ctx, 'create', 0, $error );
			return $error;
		}
		$order_id = (int) $result['order_id'];
		$sale = self::sale_row( $order_id );
		$reused = ! empty( $result['reused'] );
		if ( ! $sale || (int) $sale->partner_id !== (int) $partner->partner_id ) {
			// Cannot happen with the per-partner key namespace; never expose a foreign order.
			$error = self::error( 'wpcap_partner_idempotency_conflict', __( 'Dieser Idempotency-Key wurde bereits verwendet.', 'wp-pass-captain' ), 409 );
			self::audit( $ctx, 'create', 0, $error );
			return $error;
		}
		if ( $reused && ! hash_equals( (string) $sale->request_hash, $req['hash'] ) ) {
			$error = self::error( 'wpcap_partner_idempotency_mismatch', __( 'Dieser Idempotency-Key wurde bereits für eine andere Anfrage verwendet.', 'wp-pass-captain' ), 422 );
			self::audit( $ctx, 'create', $order_id, $error );
			return $error;
		}
		$found = array( 'order' => Orders::get( $order_id ), 'sale' => $sale );
		if ( 'sell' === $req['mode'] && 'pending' === $found['order']->payment_status ) {
			$confirmed = self::do_confirm( $ctx, $found, $req['payment_reference'] );
			if ( is_wp_error( $confirmed ) ) { self::audit( $ctx, 'create', $order_id, $confirmed ); return $confirmed; }
			$found = array( 'order' => Orders::get( $order_id ), 'sale' => self::sale_row( $order_id ) );
		}
		self::audit( $ctx, 'create', $order_id, null, array( 'mode' => $req['mode'], 'reused' => $reused, 'total_minor' => (int) $found['order']->total_minor ) );
		return array( 'status' => $reused ? 200 : 201, 'data' => array( 'order' => self::order_payload( $ctx, $found['order'], $found['sale'] ), 'tickets' => self::tickets_payload( $ctx, $found['order'] ), 'reused' => $reused ) );
	}

	/** POST /partner/orders/{id}/confirm: the partner collected the payment; tickets are issued. */
	public static function confirm( array $ctx, $public_id, $body ) {
		$found = self::find( $ctx, $public_id );
		if ( is_wp_error( $found ) ) { self::audit( $ctx, 'confirm', 0, $found ); return $found; }
		$order_id = (int) $found['order']->order_id;
		$unknown = self::unknown_fields( $body, array( 'payment_reference' ) );
		if ( $unknown ) { self::audit( $ctx, 'confirm', $order_id, $unknown ); return $unknown; }
		$reference = is_array( $body ) ? ( $body['payment_reference'] ?? '' ) : '';
		if ( '' !== $reference && ! self::valid_reference( $reference ) ) {
			$error = self::error( 'wpcap_partner_invalid_reference', __( 'payment_reference: höchstens 100 Zeichen (Buchstaben, Ziffern, Leerzeichen, . _ : / # + -).', 'wp-pass-captain' ), 400, array( 'field' => 'payment_reference' ) );
			self::audit( $ctx, 'confirm', $order_id, $error );
			return $error;
		}
		$already = 'paid' === $found['order']->payment_status;
		$result = $already ? true : self::do_confirm( $ctx, $found, $reference );
		if ( is_wp_error( $result ) ) { self::audit( $ctx, 'confirm', $order_id, $result ); return $result; }
		$order = Orders::get( $order_id );
		self::audit( $ctx, 'confirm', $order_id, null, array( 'already_paid' => $already ) );
		return array( 'status' => 200, 'data' => array( 'order' => self::order_payload( $ctx, $order, self::sale_row( $order_id ) ), 'tickets' => self::tickets_payload( $ctx, $order ) ) );
	}

	/** Confirms a pending partner order with an active hold. */
	private static function do_confirm( array $ctx, array $found, $reference ) {
		global $wpdb;
		$order = $found['order'];
		$order_id = (int) $order->order_id;
		if ( 'pending' !== $order->payment_status ) {
			return self::error( 'wpcap_partner_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'wp-pass-captain' ), 409, array( 'order_status' => $order->status ) );
		}
		// Extend first (only active holds are extended), then require an active hold: an expired
		// reservation is never revived through the API (no late recovery, contingents stay exact).
		Orders::extend_hold( $order_id, 5 );
		if ( ! self::has_active_hold( $order_id ) ) {
			return self::error( 'wpcap_partner_hold_expired', __( 'Die Reservierung ist abgelaufen. Bitte eine neue Bestellung anlegen.', 'wp-pass-captain' ), 409 );
		}
		$result = Orders::confirm_external_payment( $order_id, 'partner:' . (int) $ctx['partner']->partner_id . ( '' !== $reference ? ':' . $reference : '' ) );
		if ( is_wp_error( $result ) ) {
			return self::error( 'wpcap_partner_confirm_failed', $result->get_error_message(), 409, array( 'reason' => $result->get_error_code() ) );
		}
		if ( '' !== $reference ) { Orders::set_provider_reference( $order_id, $reference ); }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'partner_orders' ) . ' SET confirmed_at=COALESCE(confirmed_at,%s) WHERE order_id=%d', current_time( 'mysql', true ), $order_id ) );
		return true;
	}

	/**
	 * POST /partner/orders/{id}/cancel: releases a reservation, or – within the partner's cancellation
	 * window, before the event starts and without checked-in tickets – records a full refund of a paid
	 * order (the partner refunds the buyer) and revokes its tickets. Idempotent.
	 */
	public static function cancel( array $ctx, $public_id, $body ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$found = self::find( $ctx, $public_id );
		if ( is_wp_error( $found ) ) { self::audit( $ctx, 'cancel', 0, $found ); return $found; }
		$order = $found['order'];
		$order_id = (int) $order->order_id;
		$unknown = self::unknown_fields( $body, array( 'reason' ) );
		if ( $unknown ) { self::audit( $ctx, 'cancel', $order_id, $unknown ); return $unknown; }
		$reason = is_array( $body ) && is_string( $body['reason'] ?? null ) ? substr( sanitize_text_field( $body['reason'] ), 0, 190 ) : '';
		$now = current_time( 'mysql', true );
		$done = static function ( $extra = array() ) use ( $ctx, $order_id ) {
			$order = Orders::get( $order_id );
			return array( 'status' => 200, 'data' => array_merge( array( 'order' => self::order_payload( $ctx, $order, self::sale_row( $order_id ) ) ), $extra ) );
		};
		if ( in_array( $order->status, array( 'cancelled', 'expired', 'refunded' ), true ) ) {
			self::audit( $ctx, 'cancel', $order_id, null, array( 'already' => $order->status ) );
			return $done( array( 'already_cancelled' => true ) );
		}
		if ( 'pending' === $order->payment_status ) {
			$cancelled = Orders::cancel_order( $order_id, 'partner_api' . ( '' !== $reason ? ': ' . $reason : '' ) );
			if ( is_wp_error( $cancelled ) ) { return $cancelled; }
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'partner_orders' ) . ' SET cancelled_at=%s WHERE order_id=%d', $now, $order_id ) );
			self::audit( $ctx, 'cancel', $order_id, null, array( 'kind' => 'release' ) );
			return $done( array( 'released' => true ) );
		}
		if ( 'paid' !== $order->payment_status ) {
			$error = self::error( 'wpcap_partner_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'wp-pass-captain' ), 409, array( 'order_status' => $order->status ) );
			self::audit( $ctx, 'cancel', $order_id, $error );
			return $error;
		}
		$window = (int) ( $ctx['partner']->cancel_window_hours ?? 0 );
		$since = $found['sale']->confirmed_at ?: $order->created_at;
		$error = null;
		if ( $window <= 0 ) {
			$error = self::error( 'wpcap_partner_cancel_not_allowed', __( 'Bezahlte Bestellungen können über die API nicht storniert werden (kein Stornofenster vereinbart).', 'wp-pass-captain' ), 409 );
		} elseif ( strtotime( $since . ' UTC' ) + $window * HOUR_IN_SECONDS < time() ) {
			$error = self::error( 'wpcap_partner_cancel_window_closed', __( 'Das Stornofenster für diese Bestellung ist abgelaufen.', 'wp-pass-captain' ), 409 );
		} elseif ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d AND o.starts_at <= %s LIMIT 1", $order_id, $now ) ) ) {
			$error = self::error( 'wpcap_partner_cancel_started', __( 'Die Veranstaltung hat bereits begonnen; eine Stornierung ist nicht mehr möglich.', 'wp-pass-captain' ), 409 );
		} elseif ( Orders::used_ticket_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", $order_id ) ) ) ) {
			$error = self::error( 'wpcap_partner_cancel_checked_in', __( 'Mindestens ein Ticket wurde bereits eingecheckt; die Bestellung kann nicht storniert werden.', 'wp-pass-captain' ), 409 );
		}
		if ( $error ) { self::audit( $ctx, 'cancel', $order_id, $error ); return $error; }
		$ticket_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0 ORDER BY ticket_id", $order_id ) ) );
		$amount = max( 0, (int) $order->total_minor - (int) $order->refunded_minor );
		$refund = Refunds::record_external( $order_id, $ticket_ids, $amount, __( 'Storno durch Veranstalter-API', 'wp-pass-captain' ) . ( '' !== $reason ? ': ' . $reason : '' ), 'partner-cancel-' . $order_id, array( 'source' => self::SOURCE, 'provider_reference' => 'partner:' . (int) $ctx['partner']->partner_id ) );
		if ( is_wp_error( $refund ) ) { self::audit( $ctx, 'cancel', $order_id, $refund ); return $refund; }
		if ( ! $ticket_ids ) {
			$marked = Orders::mark_refunded( $order_id, 'partner_api' );
			if ( is_wp_error( $marked ) ) { self::audit( $ctx, 'cancel', $order_id, $marked ); return $marked; } // Idempotent retry.
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'partner_orders' ) . ' SET cancelled_at=%s WHERE order_id=%d', $now, $order_id ) );
		self::audit( $ctx, 'cancel', $order_id, null, array( 'kind' => 'refund', 'refund_id' => (int) ( $refund['refund_id'] ?? 0 ), 'amount_minor' => (int) ( $refund['amount_minor'] ?? 0 ) ) );
		return $done( array( 'refund' => array( 'id' => (int) ( $refund['refund_id'] ?? 0 ), 'amount_minor' => (int) ( $refund['amount_minor'] ?? 0 ), 'tax_minor' => (int) ( $refund['tax_minor'] ?? 0 ), 'currency' => (string) ( $refund['currency'] ?? $order->currency ) ) ) );
	}

	/** GET /partner/orders/{id}/tickets (tickets:issue): ticket codes for the holder of record. */
	public static function tickets( array $ctx, $public_id ) {
		$found = self::find( $ctx, $public_id );
		if ( is_wp_error( $found ) ) { return $found; }
		$tickets = self::tickets_payload( $ctx, $found['order'] );
		self::audit( $ctx, 'tickets_download', (int) $found['order']->order_id, null, array( 'tickets' => count( $tickets ) ) );
		return array( 'status' => 200, 'data' => array( 'order_id' => $found['order']->public_id, 'tickets' => $tickets ) );
	}

	/** GET /partner/orders/{id} (orders:write): own API order. */
	public static function get_order( array $ctx, $public_id ) {
		$found = self::find( $ctx, $public_id );
		if ( is_wp_error( $found ) ) { return $found; }
		return array( 'status' => 200, 'data' => array( 'order' => self::order_payload( $ctx, $found['order'], $found['sale'] ) ) );
	}

	/** Order representation for the partner (buyer e-mail only with attendees:read). */
	public static function order_payload( array $ctx, $order, $sale ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$lines = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT oi.ticket_type_id,oi.quantity,oi.refunded_quantity,oi.unit_price_minor,oi.line_total_minor,oi.tax_rate_bp,oi.tax_minor,tt.name,tt.occurrence_id,o.event_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d ORDER BY oi.item_id", (int) $order->order_id ) ) as $l ) {
			$lines[] = array( 'event_id' => (int) $l->event_id, 'occurrence_id' => (int) $l->occurrence_id, 'ticket_type_id' => (int) $l->ticket_type_id, 'ticket_type' => $l->name, 'quantity' => (int) $l->quantity, 'refunded_quantity' => (int) $l->refunded_quantity, 'unit_price_minor' => (int) $l->unit_price_minor, 'line_total_minor' => (int) $l->line_total_minor, 'tax_rate_bp' => (int) $l->tax_rate_bp, 'tax_minor' => (int) $l->tax_minor );
		}
		$hold = 'pending' === $order->payment_status ? $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active'", (int) $order->order_id ) ) : null;
		$out = array(
			'id' => $order->public_id,
			'mode' => $sale ? $sale->mode : 'reserve',
			'status' => $order->status,
			'payment_status' => $order->payment_status,
			'currency' => $order->currency,
			'total_minor' => (int) $order->total_minor,
			'tax_minor' => (int) $order->tax_minor,
			'refunded_minor' => (int) $order->refunded_minor,
			'external_reference' => $sale ? (string) $sale->external_reference : '',
			'channel' => $sale ? (string) $sale->channel : '',
			'anonymous_buyer' => $sale ? (bool) $sale->anonymous : false,
			'hold_expires_at' => self::iso( $hold ),
			'created_at' => self::iso( $order->created_at ),
			'confirmed_at' => $sale ? self::iso( $sale->confirmed_at ) : null,
			'cancelled_at' => $sale ? self::iso( $sale->cancelled_at ) : null,
			'lines' => $lines,
		);
		if ( in_array( 'attendees:read', $ctx['scopes'], true ) ) {
			$out['buyer_email'] = self::is_placeholder_email( $order->customer_email ) ? null : \PassCaptain\Privacy::public_email( $order->customer_email );
		}
		return $out;
	}

	/**
	 * Tickets of an order. Codes (and ticket page URLs) only with tickets:issue; a code is null when it
	 * was rotated to another holder (transfer/assignment) or the ticket is no longer valid.
	 */
	public static function tickets_payload( array $ctx, $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( 'paid' !== $order->payment_status && 'refunded' !== $order->payment_status ) { return array(); }
		$with_codes = in_array( 'tickets:issue', $ctx['scopes'], true );
		$codes = $with_codes ? self::stored_codes( (int) $order->order_id ) : array();
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.ticket_type_id,tt.name,tt.occurrence_id,o.event_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=%d ORDER BY t.ticket_id", (int) $order->order_id ) ) as $t ) {
			$row = array( 'id' => $t->public_id, 'ticket_type_id' => (int) $t->ticket_type_id, 'ticket_type' => $t->name, 'event_id' => (int) $t->event_id, 'occurrence_id' => (int) $t->occurrence_id, 'status' => $t->status, 'refunded' => (bool) $t->refunded );
			if ( $with_codes ) {
				$code = 'valid' === $t->status ? ( $codes[ (int) $t->ticket_id ] ?? null ) : null;
				$row['code'] = $code;
				$row['url'] = null !== $code ? add_query_arg( 'wpcap_ticket', $code, home_url( '/' ) ) : null;
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Ticket codes */

	private static function code_key() {
		$base = defined( 'WPCAP_PARTNER_CODES_KEY' ) ? (string) constant( 'WPCAP_PARTNER_CODES_KEY' ) : '';
		if ( '' === $base && function_exists( 'wp_salt' ) ) { $base = (string) wp_salt( 'auth' ); }
		if ( '' === $base ) { $base = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : ABSPATH; }
		return hash_hmac( 'sha256', 'wpcap-partner-codes-secretbox-v1', $base, true );
	}

	public static function seal( $code ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $code, $nonce, self::code_key() ) );
	}

	public static function open( $box ) {
		if ( 0 !== strpos( (string) $box, 'v1:' ) ) { return null; }
		$raw = base64_decode( substr( (string) $box, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return null; }
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::code_key() );
		} catch ( \Throwable $e ) { return null; }
		return false === $plain ? null : $plain;
	}

	private static function store_code( $ticket_id, $order_id, $partner_id, $code ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::t( 'partner_ticket_codes' ) . ' (ticket_id,order_id,partner_id,code_box,updated_at) VALUES (%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE code_box=VALUES(code_box),updated_at=VALUES(updated_at)', (int) $ticket_id, (int) $order_id, (int) $partner_id, self::seal( $code ), current_time( 'mysql', true ) ) );
	}

	/** ticket_id => plaintext code of an order (only codes that can be decrypted). */
	private static function stored_codes( $order_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,code_box FROM ' . self::t( 'partner_ticket_codes' ) . ' WHERE order_id=%d', absint( $order_id ) ) ) as $row ) {
			$code = self::open( $row->code_box );
			if ( null !== $code ) { $out[ (int) $row->ticket_id ] = $code; }
		}
		return $out;
	}

	/** wpcap_ticket_mail (priority 1): keeps the codes of newly issued partner tickets (encrypted). */
	public static function capture_codes( $mail, $order = null, $issued = array() ) {
		if ( ! is_object( $order ) || ! $issued ) { return $mail; }
		$sale = self::sale_row( (int) $order->order_id );
		if ( ! $sale ) { return $mail; }
		foreach ( (array) $issued as $ticket ) {
			if ( ! empty( $ticket['ticket_id'] ) && ! empty( $ticket['token'] ) ) {
				self::store_code( (int) $ticket['ticket_id'], (int) $order->order_id, (int) $sale->partner_id, (string) $ticket['token'] );
			}
		}
		return $mail;
	}

	/** wpcap_ticket_code_rotated: follow staff rotations; a new holder (transfer/assignment) removes the code. */
	public static function on_code_rotated( $ticket_id, $token = '', $reason = '' ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'partner_ticket_codes' ) . ' WHERE ticket_id=%d', absint( $ticket_id ) ) );
		if ( ! $row ) { return; }
		if ( in_array( sanitize_key( (string) $reason ), self::FOREIGN_ROTATIONS, true ) || '' === (string) $token ) {
			$wpdb->delete( self::t( 'partner_ticket_codes' ), array( 'ticket_id' => (int) $row->ticket_id ) );
			return;
		}
		self::store_code( (int) $row->ticket_id, (int) $row->order_id, (int) $row->partner_id, (string) $token );
	}

	/* ------------------------------------------------------------------ Audit */

	/** Audit entry for every write call (success or rejection); never contains secrets or buyer data. */
	public static function audit( array $ctx, $action, $order_id, $error = null, array $extra = array() ) {
		$details = array_merge( array(
			'action' => sanitize_key( $action ),
			'partner_id' => (int) $ctx['partner']->partner_id,
			'key_id' => (int) $ctx['key']->key_id,
			'result' => $error ? 'error' : 'ok',
			'ip' => Keys::anonymise_ip( Keys::client_ip() ),
		), $extra );
		if ( $error instanceof \WP_Error ) {
			$details['code'] = $error->get_error_code();
			$details['status'] = (int) ( $error->get_error_data()['status'] ?? 0 );
		}
		Audit::record( 'partner_api_write', $order_id ? 'order' : 'partner_key', $order_id ? (int) $order_id : (int) $ctx['key']->key_id, $details );
	}
}
