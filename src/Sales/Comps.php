<?php
/**
 * Complimentary tickets and guest lists without the box office.
 *
 * Staff issue tickets of a ticket type to one recipient (quantity) or to a CSV guest list
 * ("Name,E-Mail[,Menge]" per line, ";" or tab also accepted). Every recipient gets an own order through
 * Orders::create() with the trusted option `comp`: price 0, payment provider "comp", tickets issued
 * and mailed at once, no cash session, receipt number or fiscal document. Capacity (ticket type,
 * occurrence, seat/module holds) applies exactly as for a sale – an exhausted contingent stops the
 * remaining rows. Hidden ticket types (visibility "hidden") can be issued here.
 *
 * Idempotent per batch: the form carries a batch key, each row uses "<batch>:<row>" as idempotency
 * key, so a resubmitted form does not issue twice. Every batch is audited (comp_tickets_issued);
 * reports can filter sales vs. complimentary orders (Reports filter "channel").
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Comps {
	const PROVIDER = 'comp';
	const CAP = 'wpcap_manage_orders';
	/** Rows per guest list (one order each). */
	const MAX_ROWS = 500;

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Parses a guest list. Lines: name, e-mail, optional quantity; a header line ("name,email") and
	 * empty lines are skipped.
	 *
	 * @return array|\WP_Error List of {line, name, email, quantity}.
	 */
	public static function parse_guest_list( $csv ) {
		$rows = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $csv );
		foreach ( $lines as $index => $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$delimiter = false !== strpos( $line, ';' ) ? ';' : ( false !== strpos( $line, "\t" ) ? "\t" : ',' );
			$cells = array_map( 'trim', str_getcsv( $line, $delimiter, '"', '\\' ) );
			$name = sanitize_text_field( (string) ( $cells[0] ?? '' ) );
			$email = sanitize_email( (string) ( $cells[1] ?? '' ) );
			if ( ! is_email( $email ) && is_email( sanitize_email( $name ) ) ) {
				// "E-Mail,Name" order.
				list( $name, $email ) = array( sanitize_text_field( (string) ( $cells[1] ?? '' ) ), sanitize_email( $name ) );
			}
			if ( ! is_email( $email ) ) {
				if ( ! $rows && preg_match( '/mail/i', $line ) ) { continue; } // Header line.
				/* translators: %d: line number */
				return self::error( 'wpcap_comp_list', sprintf( __( 'Zeile %d der Gästeliste hat keine gültige E-Mail-Adresse.', 'pass-captain' ), $index + 1 ) );
			}
			$quantity = isset( $cells[2] ) && '' !== $cells[2] ? Orders::positive_int( $cells[2] ) : 1;
			if ( $quantity < 1 || $quantity > 100 ) {
				/* translators: %d: line number */
				return self::error( 'wpcap_comp_list', sprintf( __( 'Zeile %d der Gästeliste hat eine ungültige Menge.', 'pass-captain' ), $index + 1 ) );
			}
			$rows[] = array( 'line' => $index + 1, 'name' => mb_substr( $name, 0, 190 ), 'email' => $email, 'quantity' => $quantity );
			if ( count( $rows ) > self::MAX_ROWS ) {
				/* translators: %d: maximum number of rows */
				return self::error( 'wpcap_comp_list', sprintf( __( 'Eine Gästeliste darf höchstens %d Zeilen haben.', 'pass-captain' ), self::MAX_ROWS ) );
			}
		}
		return $rows;
	}

	/**
	 * Issues complimentary tickets.
	 *
	 * @param array $in ticket_type_id, batch_key, note, and either recipient_email (+ recipient_name,
	 *                  quantity) or guest_list (CSV text).
	 * @return array|\WP_Error {issued (tickets), orders (ids), failed: [{line, email, message}]}
	 */
	public static function issue( array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type_id = absint( $in['ticket_type_id'] ?? 0 );
		$type = $wpdb->get_row( $wpdb->prepare( 'SELECT tt.ticket_type_id,tt.name,tt.occurrence_id,o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $type_id ) );
		if ( ! $type ) { return self::error( 'wpcap_comp_type', __( 'Ticketart nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( (int) $type->occurrence_id, self::CAP, (int) $type->event_id ) && ! Access::can_access_event( (int) $type->event_id, 'wpcap_manage_events' ) ) {
			return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 );
		}
		$list = trim( (string) ( $in['guest_list'] ?? '' ) );
		if ( '' !== $list ) {
			$rows = self::parse_guest_list( $list );
			if ( is_wp_error( $rows ) ) { return $rows; }
		} else {
			$email = sanitize_email( (string) ( $in['recipient_email'] ?? '' ) );
			$quantity = Orders::positive_int( $in['quantity'] ?? 1 );
			if ( ! is_email( $email ) ) { return self::error( 'wpcap_comp_email', __( 'Bitte eine gültige E-Mail-Adresse des Empfängers angeben.', 'pass-captain' ) ); }
			if ( $quantity < 1 || $quantity > 100 ) { return self::error( 'wpcap_comp_quantity', __( 'Bitte eine Menge zwischen 1 und 100 angeben.', 'pass-captain' ) ); }
			$rows = array( array( 'line' => 1, 'name' => mb_substr( sanitize_text_field( (string) ( $in['recipient_name'] ?? '' ) ), 0, 190 ), 'email' => $email, 'quantity' => $quantity ) );
		}
		if ( ! $rows ) { return self::error( 'wpcap_comp_list', __( 'Die Gästeliste ist leer.', 'pass-captain' ) ); }
		$batch = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $in['batch_key'] ?? '' ) );
		if ( '' === $batch ) { $batch = wp_generate_uuid4(); }
		$note = mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 190 );
		$issued = 0;
		$orders = array();
		$failed = array();
		foreach ( $rows as $row ) {
			$attendees = array();
			if ( '' !== $row['name'] ) {
				for ( $i = 0; $i < $row['quantity']; $i++ ) { $attendees[] = array( 'ticket_type_id' => $type_id, 'name' => $row['name'], 'email' => 0 === $i ? $row['email'] : '' ); }
			}
			$result = Orders::create(
				array( 'email' => $row['email'], 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $row['quantity'] ) ), 'attendees' => $attendees, 'idempotency_key' => 'comp:' . $batch . ':' . $row['line'] . ':' . strtolower( $row['email'] ) ),
				array( 'comp' => true )
			);
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'line' => $row['line'], 'email' => $row['email'], 'message' => $result->get_error_message() );
				continue;
			}
			$orders[] = (int) $result['order_id'];
			if ( empty( $result['reused'] ) ) { $issued += $row['quantity']; }
		}
		Audit::record( 'comp_tickets_issued', 'occurrence', (int) $type->occurrence_id, array( 'event_id' => (int) $type->event_id, 'ticket_type_id' => $type_id, 'tickets' => $issued, 'orders' => count( $orders ), 'failed' => count( $failed ), 'guest_list' => '' !== $list, 'note' => $note ) );
		return array( 'issued' => $issued, 'orders' => $orders, 'failed' => $failed );
	}
}
