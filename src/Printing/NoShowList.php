<?php
/**
 * No-show list for an occurrence (print view and CSV): valid tickets that were never admitted.
 *
 * A ticket counts as admitted when its status is checked_in or when its check-in log has more
 * "checked_in" than "undone" rows (repeatable policies keep the status valid). Revoked/refunded
 * tickets are not listed.
 * Access and data minimisation as for the attendee list (AttendeeList::access_level()):
 * check-in staff see name and ticket type only, event managers also e-mail addresses and order.
 */
namespace PassCaptain\Printing;

use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NoShowList {
	/** Ticket rows (as Templates::tickets_for_occurrence()) of the occurrence that were never admitted. */
	public static function tickets( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tickets = Templates::tickets_for_occurrence( $occurrence_id, array( 'valid' ) );
		if ( ! $tickets ) { return array(); }
		$ids = implode( ',', array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
		// Every entry writes a "checked_in" log row and every take-back an "undone" row (scanner undo,
		// admin undo), also for repeatable policies whose ticket status stays valid.
		$admitted = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_checkins WHERE ticket_id IN ($ids) AND result IN ('checked_in','undone') GROUP BY ticket_id HAVING SUM(result='checked_in') > SUM(result='undone')" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		return array_values( array_filter( $tickets, static function ( $t ) use ( $admitted ) { return ! isset( $admitted[ (int) $t->ticket_id ] ); } ) );
	}

	/**
	 * Rows (first row = header).
	 *
	 * @param string $level 'full' or 'basic' (AttendeeList::access_level()).
	 */
	public static function rows( $occurrence_id, $level ) {
		$tickets = self::tickets( $occurrence_id );
		if ( 'full' !== $level ) {
			$rows = array( array( __( 'Name', 'wp-pass-captain' ), __( 'Ticketart', 'wp-pass-captain' ) ) );
			foreach ( $tickets as $t ) { $rows[] = array( \PassCaptain\Pii::show( 'attendee_name', $t->attendee_name ), $t->ticket_name ); }
			return $rows;
		}
		$rows = array( array( __( 'Ticket', 'wp-pass-captain' ), __( 'Name', 'wp-pass-captain' ), __( 'Ticketart', 'wp-pass-captain' ), __( 'E-Mail Teilnehmer', 'wp-pass-captain' ), __( 'E-Mail Besteller', 'wp-pass-captain' ), __( 'Bestellung', 'wp-pass-captain' ) ) );
		foreach ( $tickets as $t ) {
			$buyer = false !== strpos( (string) $t->customer_email, '@invalid.example' ) ? '' : $t->customer_email;
			$rows[] = array( substr( $t->public_id, 0, 12 ), $t->attendee_name, $t->ticket_name, $t->attendee_email, $buyer, $t->order_public_id );
		}
		return $rows;
	}

	/** Printable HTML (A4, escaped). */
	public static function html( $occurrence_id, $level ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
		$rows = self::rows( $occurrence_id, $level );
		$header = array_shift( $rows );
		$issued = count( Templates::tickets_for_occurrence( $occurrence_id, array( 'valid', 'checked_in' ) ) );
		$title = $occ ? $occ->event_name . ' – ' . Scope::local( $occ->starts_at, $occ->timezone ) : '';
		/* translators: 1: tickets never admitted, 2: valid tickets in total */
		$summary = sprintf( __( '%1$d von %2$d gültigen Tickets nicht eingelassen', 'wp-pass-captain' ), count( $rows ), $issued );
		$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html( __( 'No-Show-Liste', 'wp-pass-captain' ) ) . '</title>'
			. '<style>@page{size:A4;margin:12mm}body{font:11px/1.35 system-ui,sans-serif;color:#000}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}th{background:#eee}@media print{button{display:none}}</style></head><body>';
		$html .= '<h1>' . esc_html( __( 'No-Show-Liste', 'wp-pass-captain' ) ) . '</h1><p>' . esc_html( $title ) . ' · ' . esc_html( $summary ) . '</p>';
		if ( ! $rows ) {
			$html .= '<p>' . esc_html( __( 'Alle gültigen Tickets wurden eingelassen.', 'wp-pass-captain' ) ) . '</p>';
		} else {
			$html .= '<table><thead><tr>';
			foreach ( $header as $cell ) { $html .= '<th>' . esc_html( $cell ) . '</th>'; }
			$html .= '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$html .= '<tr>';
				foreach ( $row as $cell ) { $html .= '<td>' . esc_html( $cell ) . '</td>'; }
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}
		$html .= '<p><button onclick="window.print()">' . esc_html( __( 'Drucken', 'wp-pass-captain' ) ) . '</button></p></body></html>';
		return $html;
	}
}
