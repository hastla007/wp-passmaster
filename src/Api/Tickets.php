<?php
/**
 * Public, versioned read API ticket → order, event, occurrence (A709, contract `ticket_read` 1.0.0) for suite
 * plugins (e.g. Review Captain Pro): a stable array without personal data.
 *
 * - Input: internal ticket_id (int) or the public ticket ID (string, wpcap_tickets.public_id).
 * - Output: never e-mail, name, attendee data, ticket/help codes or tokens; only ids, states and dates.
 * - Version rule (docs/CONTRACTS.md): within 1.x keys are only added, never removed or retyped.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Api;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Tickets {
	/** Contract version of the returned array (docs/CONTRACTS.md, `ticket_read`). */
	const VERSION = '1.0.0';

	/**
	 * Ticket with order, event and occurrence, or null when not found.
	 *
	 * @param int|string $ticket Ticket id (int) or public ticket id (string).
	 * @return array<string,mixed>|null
	 */
	public static function get( $ticket ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$by_public = is_string( $ticket ) && ! ctype_digit( $ticket );
		$key = $by_public ? sanitize_text_field( $ticket ) : absint( $ticket );
		if ( ! $key ) { return null; }
		$sql = "SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.ticket_type_id,t.issued_at,ord.order_id,ord.public_id AS order_public_id,ord.status AS order_status,ord.payment_status,ord.currency,tt.name AS ticket_type_name,o.occurrence_id,o.starts_at,o.ends_at,o.timezone,o.status AS occurrence_status,e.event_id,e.name AS event_name,e.status AS event_status
			FROM {$p}wpcap_tickets t
			INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id
			INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id
			INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id
			INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id
			WHERE " . ( $by_public ? 't.public_id=%s' : 't.ticket_id=%d' ) . ' LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix, one placeholder chosen from two literals.
		if ( ! $row ) { return null; }
		// Valid admission right now: check-ins minus undos (as Reports\Attendance / Review\Events).
		$net = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(result='checked_in')-SUM(result='undone'),0) FROM {$wpdb->prefix}wpcap_checkins WHERE ticket_id=%d AND result IN ('checked_in','undone')", (int) $row->ticket_id ) );
		$checked_in = $net > 0 || 'checked_in' === (string) $row->status;
		$data = array(
			'contract_version' => self::VERSION,
			'ticket' => array(
				'id' => (int) $row->ticket_id,
				'public_id' => (string) $row->public_id,
				'status' => (string) $row->status,
				'refunded' => (bool) (int) $row->refunded,
				'checked_in' => $checked_in,
				'issued_at' => self::iso( $row->issued_at ),
				'ticket_type_id' => (int) $row->ticket_type_id,
				'ticket_type_name' => (string) $row->ticket_type_name,
			),
			'order' => array(
				'id' => (int) $row->order_id,
				'public_id' => (string) $row->order_public_id,
				'status' => (string) $row->order_status,
				'payment_status' => (string) $row->payment_status,
				'currency' => (string) $row->currency,
			),
			'event' => array(
				'id' => (int) $row->event_id,
				'name' => (string) $row->event_name,
				'status' => (string) $row->event_status,
			),
			'occurrence' => array(
				'id' => (int) $row->occurrence_id,
				'starts_at' => self::iso( $row->starts_at ),
				'ends_at' => self::iso( $row->ends_at ),
				'timezone' => (string) $row->timezone,
				'status' => (string) $row->occurrence_status,
			),
		);
		/**
		 * Read model of a ticket for suite plugins (contract ticket_read 1.x). Only add keys; never add personal data.
		 *
		 * @param array  $data   Ticket read model.
		 * @param object $row    Database row.
		 */
		return (array) apply_filters( 'wp_pass_captain_ticket_read_v1', $data, $row );
	}

	/** UTC datetime (Y-m-d H:i:s) as ISO 8601 with "Z", or null. */
	private static function iso( $utc ) {
		$utc = (string) $utc;
		if ( '' === $utc || 0 === strpos( $utc, '0000' ) ) { return null; }
		$ts = strtotime( $utc . ' UTC' );
		return false === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
