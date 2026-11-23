<?php
/**
 * Public read facade for the state of orders, tickets, refunds, occurrences and events
 * (extension contract 1.3.0, docs/EXTENSIONS.md "Lesefassade für Zustände").
 *
 * Each method returns a flat array of state data or null when the record does not exist:
 * IDs (record ID and, for orders and tickets, the 32-hex `public_id`), status keys, amounts in minor units
 * with `currency`, counts, user IDs and timestamps in UTC (RFC 3339 with `Z`).
 *
 * Never returned: names, e-mail addresses, postal or billing data, ticket codes or their hashes, QR or barcode
 * values, access tokens, provider references, free text (reasons, error messages, notes, descriptions,
 * locations) and attendee answers. Every query names its columns, so these values are not even loaded.
 *
 * Pure reads: one SELECT statement per call, no writes, no caches, no hooks. The facade does not check
 * capabilities: it is a PHP API for plugins on the same site, and the caller decides who may see what it shows.
 *
 * The arrays only change compatibly within version 1 (new keys may be added, existing keys keep their meaning).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Api;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReadFacade {
	const VERSION = 1;

	/** Keys of each result, in this order (the contract; tests compare against these lists). */
	const ORDER_KEYS = array( 'version', 'order_id', 'public_id', 'status', 'payment_status', 'payment_provider', 'currency', 'total_minor', 'subtotal_minor', 'discount_minor', 'tax_minor', 'refunded_minor', 'ticket_count', 'checked_in_count', 'customer_user_id', 'created_at', 'updated_at' );
	const TICKET_KEYS = array( 'version', 'ticket_id', 'public_id', 'order_id', 'order_public_id', 'ticket_type_id', 'occurrence_id', 'event_id', 'status', 'checked_in', 'refunded', 'issued_at' );
	const REFUND_KEYS = array( 'version', 'refund_id', 'order_id', 'order_public_id', 'status', 'currency', 'amount_minor', 'tax_minor', 'deducted_minor', 'ticket_count', 'requested_by_user_id', 'approved_by_user_id', 'created_at', 'updated_at' );
	const OCCURRENCE_KEYS = array( 'version', 'occurrence_id', 'event_id', 'status', 'starts_at', 'ends_at', 'timezone', 'capacity', 'issued_ticket_count', 'rescheduled_at' );
	const EVENT_KEYS = array( 'version', 'event_id', 'status', 'occurrence_count', 'created_at', 'updated_at' );

	/**
	 * State of an order.
	 *
	 * @param int|string $id Order ID or the order's `public_id` (32 hex characters).
	 * @return array<string,mixed>|null
	 */
	public static function order( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = self::where( $id, true, 'o.order_id', 'o.public_id' );
		if ( null === $where ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE part from where() adds its own placeholder.
			'SELECT o.order_id,o.public_id,o.status,o.payment_status,o.payment_provider,o.currency,o.total_minor,o.subtotal_minor,o.discount_minor,o.tax_minor,o.refunded_minor,o.customer_user_id,o.created_at,o.updated_at,'
			. "(SELECT COUNT(*) FROM %i t WHERE t.order_id=o.order_id) AS ticket_count,(SELECT COUNT(*) FROM %i t WHERE t.order_id=o.order_id AND t.status='checked_in') AS checked_in_count"
			. ' FROM %i o WHERE ' . $where[0] . ' LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column comparison with a placeholder.
			$p . 'wpcap_tickets',
			$p . 'wpcap_tickets',
			$p . 'wpcap_orders',
			$where[1]
		), ARRAY_A );
		if ( ! $row ) { return null; }
		return array(
			'version' => self::VERSION,
			'order_id' => (int) $row['order_id'],
			'public_id' => (string) $row['public_id'],
			'status' => (string) $row['status'],
			'payment_status' => (string) $row['payment_status'],
			'payment_provider' => (string) $row['payment_provider'],
			'currency' => (string) $row['currency'],
			'total_minor' => (int) $row['total_minor'],
			'subtotal_minor' => (int) $row['subtotal_minor'],
			'discount_minor' => (int) $row['discount_minor'],
			'tax_minor' => (int) $row['tax_minor'],
			'refunded_minor' => (int) $row['refunded_minor'],
			'ticket_count' => (int) $row['ticket_count'],
			'checked_in_count' => (int) $row['checked_in_count'],
			'customer_user_id' => self::user( $row['customer_user_id'] ),
			'created_at' => self::utc( $row['created_at'] ),
			'updated_at' => self::utc( $row['updated_at'] ),
		);
	}

	/**
	 * State of a ticket.
	 *
	 * @param int|string $id Ticket ID or the ticket's `public_id` (32 hex characters).
	 * @return array<string,mixed>|null
	 */
	public static function ticket( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = self::where( $id, true, 't.ticket_id', 't.public_id' );
		if ( null === $where ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE part from where() adds its own placeholder.
			'SELECT t.ticket_id,t.public_id,t.order_id,t.ticket_type_id,t.status,t.refunded,t.issued_at,tt.occurrence_id,oc.event_id,o.public_id AS order_public_id'
			. ' FROM %i t LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i oc ON oc.occurrence_id=tt.occurrence_id LEFT JOIN %i o ON o.order_id=t.order_id'
			. ' WHERE ' . $where[0] . ' LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column comparison with a placeholder.
			$p . 'wpcap_tickets',
			$p . 'wpcap_ticket_types',
			$p . 'wpcap_occurrences',
			$p . 'wpcap_orders',
			$where[1]
		), ARRAY_A );
		if ( ! $row ) { return null; }
		return array(
			'version' => self::VERSION,
			'ticket_id' => (int) $row['ticket_id'],
			'public_id' => (string) $row['public_id'],
			'order_id' => (int) $row['order_id'],
			'order_public_id' => null === $row['order_public_id'] ? null : (string) $row['order_public_id'],
			'ticket_type_id' => (int) $row['ticket_type_id'],
			'occurrence_id' => null === $row['occurrence_id'] ? null : (int) $row['occurrence_id'],
			'event_id' => null === $row['event_id'] ? null : (int) $row['event_id'],
			'status' => (string) $row['status'],
			'checked_in' => 'checked_in' === (string) $row['status'],
			'refunded' => 1 === (int) $row['refunded'] || 'refunded' === (string) $row['status'],
			'issued_at' => self::utc( $row['issued_at'] ),
		);
	}

	/**
	 * State of a refund (reason, error message and provider reference stay unread).
	 *
	 * @param int|string $id Refund ID (refunds have no public ID).
	 * @return array<string,mixed>|null
	 */
	public static function refund( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = self::where( $id, false, 'r.refund_id', '' );
		if ( null === $where ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE part from where() adds its own placeholder.
			'SELECT r.refund_id,r.order_id,r.status,r.currency,r.amount_minor,r.tax_minor,r.deducted_minor,r.ticket_ids,r.requested_by,r.approved_by,r.created_at,r.updated_at,o.public_id AS order_public_id'
			. ' FROM %i r LEFT JOIN %i o ON o.order_id=r.order_id WHERE ' . $where[0] . ' LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column comparison with a placeholder.
			$p . 'wpcap_refunds',
			$p . 'wpcap_orders',
			$where[1]
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$tickets = json_decode( (string) $row['ticket_ids'], true );
		return array(
			'version' => self::VERSION,
			'refund_id' => (int) $row['refund_id'],
			'order_id' => (int) $row['order_id'],
			'order_public_id' => null === $row['order_public_id'] ? null : (string) $row['order_public_id'],
			'status' => (string) $row['status'],
			'currency' => (string) $row['currency'],
			'amount_minor' => (int) $row['amount_minor'],
			'tax_minor' => (int) $row['tax_minor'],
			'deducted_minor' => (int) $row['deducted_minor'],
			'ticket_count' => is_array( $tickets ) ? count( $tickets ) : 0,
			'requested_by_user_id' => self::user( $row['requested_by'] ),
			'approved_by_user_id' => self::user( $row['approved_by'] ),
			'created_at' => self::utc( $row['created_at'] ),
			'updated_at' => self::utc( $row['updated_at'] ),
		);
	}

	/**
	 * State of an occurrence (date of an event); the location (free text) stays unread.
	 *
	 * @param int|string $id Occurrence ID (occurrences have no public ID).
	 * @return array<string,mixed>|null
	 */
	public static function occurrence( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = self::where( $id, false, 'oc.occurrence_id', '' );
		if ( null === $where ) { return null; }
		// Issued tickets: the same basis as Admin\Queries::issued_ticket_count_for_occurrence() (valid or checked in).
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE part from where() adds its own placeholder.
			'SELECT oc.occurrence_id,oc.event_id,oc.status,oc.starts_at,oc.ends_at,oc.timezone,oc.capacity,oc.rescheduled_at,'
			. "(SELECT COUNT(*) FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=oc.occurrence_id AND t.status IN ('valid','checked_in')) AS issued_ticket_count"
			. ' FROM %i oc WHERE ' . $where[0] . ' LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column comparison with a placeholder.
			$p . 'wpcap_tickets',
			$p . 'wpcap_ticket_types',
			$p . 'wpcap_occurrences',
			$where[1]
		), ARRAY_A );
		if ( ! $row ) { return null; }
		return array(
			'version' => self::VERSION,
			'occurrence_id' => (int) $row['occurrence_id'],
			'event_id' => (int) $row['event_id'],
			'status' => (string) $row['status'],
			'starts_at' => self::utc( $row['starts_at'] ),
			'ends_at' => self::utc( $row['ends_at'] ),
			'timezone' => (string) $row['timezone'],
			'capacity' => (int) $row['capacity'],
			'issued_ticket_count' => (int) $row['issued_ticket_count'],
			'rescheduled_at' => self::utc( $row['rescheduled_at'] ),
		);
	}

	/**
	 * State of an event; name, slug, description and other texts stay unread.
	 *
	 * @param int|string $id Event ID (events have no public ID).
	 * @return array<string,mixed>|null
	 */
	public static function event( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = self::where( $id, false, 'e.event_id', '' );
		if ( null === $where ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE part from where() adds its own placeholder.
			'SELECT e.event_id,e.status,e.created_at,e.updated_at,(SELECT COUNT(*) FROM %i oc WHERE oc.event_id=e.event_id) AS occurrence_count'
			. ' FROM %i e WHERE ' . $where[0] . ' LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column comparison with a placeholder.
			$p . 'wpcap_occurrences',
			$p . 'wpcap_events',
			$where[1]
		), ARRAY_A );
		if ( ! $row ) { return null; }
		return array(
			'version' => self::VERSION,
			'event_id' => (int) $row['event_id'],
			'status' => (string) $row['status'],
			'occurrence_count' => (int) $row['occurrence_count'],
			'created_at' => self::utc( $row['created_at'] ),
			'updated_at' => self::utc( $row['updated_at'] ),
		);
	}

	/**
	 * WHERE clause for an ID argument: a positive integer (or digit string) is a record ID; a string of 32 hex
	 * characters is a public ID where the record has one. Anything else gives null (= no record).
	 *
	 * @param mixed  $id           ID argument of the caller.
	 * @param bool   $has_public   Whether the record type has a `public_id`.
	 * @param string $id_column    Record ID column.
	 * @param string $public_column Public ID column.
	 * @return array{0:string,1:int|string}|null Literal SQL condition with one placeholder, and its value.
	 */
	private static function where( $id, $has_public, $id_column, $public_column ) {
		if ( is_int( $id ) ) {
			return $id > 0 ? array( $id_column . '=%d', $id ) : null;
		}
		if ( ! is_string( $id ) ) { return null; }
		$id = trim( $id );
		if ( $has_public && 1 === preg_match( '/^[0-9a-fA-F]{32}$/', $id ) ) {
			return array( $public_column . '=%s', strtolower( $id ) );
		}
		if ( 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $id ) ) {
			return array( $id_column . '=%d', (int) $id );
		}
		return null;
	}

	/** User ID or null for "none" (0). */
	private static function user( $value ) {
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}

	/** Stored UTC datetime as RFC 3339 with `Z`, or null when empty. */
	private static function utc( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) { return null; }
		$time = strtotime( $value . ' UTC' );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
