<?php
/**
 * No-show list for an occurrence (print view and CSV): valid tickets that were never admitted.
 *
 * A ticket counts as admitted when its status is checked_in or when its check-in log has more
 * "checked_in" than "undone" rows (repeatable policies keep the status valid). Revoked/refunded
 * tickets are not listed.
 * Access and data minimisation as for the attendee list (AttendeeList::access_level()):
 * check-in staff see name and ticket type only, event managers also e-mail addresses and order.
 * Large events (A477): tickets are read and checked block by block (AttendeeList::chunk_size()),
 * the counts come from SQL, CSV and HTML stream.
 */
namespace PassCaptain\Printing;

use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NoShowList {
	/** SQL condition "ticket t was admitted" (more checked_in than undone rows in its log). */
	private static function admitted_sql() {
		global $wpdb;
		return "EXISTS (SELECT 1 FROM {$wpdb->prefix}wpcap_checkins c WHERE c.ticket_id=t.ticket_id AND c.result IN ('checked_in','undone') GROUP BY c.ticket_id HAVING SUM(c.result='checked_in') > SUM(c.result='undone'))";
	}

	/**
	 * Never-admitted tickets of the occurrence, block by block (rows as
	 * Templates::tickets_for_occurrence()).
	 *
	 * @return \Generator<int, object>
	 */
	public static function ticket_stream( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		foreach ( Templates::ticket_blocks( $occurrence_id, array( 'valid' ), AttendeeList::chunk_size() ) as $tickets ) {
			$ids = implode( ',', array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
			// Every entry writes a "checked_in" log row and every take-back an "undone" row (scanner undo,
			// admin undo), also for repeatable policies whose ticket status stays valid.
			$admitted = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_checkins WHERE ticket_id IN ($ids) AND result IN ('checked_in','undone') GROUP BY ticket_id HAVING SUM(result='checked_in') > SUM(result='undone')" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list of one block.
			foreach ( $tickets as $t ) {
				if ( ! isset( $admitted[ (int) $t->ticket_id ] ) ) { yield $t; }
			}
		}
	}

	/** Ticket rows (as Templates::tickets_for_occurrence()) of the occurrence that were never admitted. */
	public static function tickets( $occurrence_id ) {
		return iterator_to_array( self::ticket_stream( $occurrence_id ), false );
	}

	/** Number of valid tickets never admitted (one query). */
	public static function count( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE tt.occurrence_id=%d AND t.status='valid' AND NOT " . self::admitted_sql(), absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed subquery, value as placeholder.
	}

	/** Header row for the level. */
	public static function header( $level ) {
		if ( 'full' !== $level ) { return array( __( 'Name', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ) ); }
		return array( __( 'Ticket', 'pass-captain' ), __( 'Name', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'E-Mail Teilnehmer', 'pass-captain' ), __( 'E-Mail Besteller', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ) );
	}

	/**
	 * Data rows (without header), block by block.
	 *
	 * @param string $level 'full' or 'basic' (AttendeeList::access_level()).
	 * @return \Generator<int, array>
	 */
	public static function row_stream( $occurrence_id, $level ) {
		foreach ( self::ticket_stream( $occurrence_id ) as $t ) {
			if ( 'full' !== $level ) {
				yield array( \PassCaptain\Pii::show( 'attendee_name', $t->attendee_name ), $t->ticket_name );
				continue;
			}
			$buyer = false !== strpos( (string) $t->customer_email, '@invalid.example' ) ? '' : $t->customer_email;
			yield array( substr( $t->public_id, 0, 12 ), $t->attendee_name, $t->ticket_name, $t->attendee_email, $buyer, $t->order_public_id );
		}
	}

	/**
	 * Rows (first row = header), built in memory – for small lists and tests; downloads stream.
	 *
	 * @param string $level 'full' or 'basic' (AttendeeList::access_level()).
	 */
	public static function rows( $occurrence_id, $level ) {
		$rows = array( self::header( $level ) );
		foreach ( self::row_stream( $occurrence_id, $level ) as $row ) { $rows[] = $row; }
		return $rows;
	}

	/** Printable HTML (A4, escaped) as a string; the download uses stream_html(). */
	public static function html( $occurrence_id, $level ) {
		ob_start();
		self::stream_html( $occurrence_id, $level );
		return (string) ob_get_clean();
	}

	/** Prints the printable HTML (A4, escaped) block by block (A477). */
	public static function stream_html( $occurrence_id, $level ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
		$missing = self::count( $occurrence_id );
		$issued = Templates::count_for_occurrence( $occurrence_id, array( 'valid', 'checked_in' ) );
		$title = $occ ? $occ->event_name . ' – ' . Scope::local( $occ->starts_at, $occ->timezone ) : '';
		/* translators: 1: tickets never admitted, 2: valid tickets in total */
		$summary = sprintf( __( '%1$d von %2$d gültigen Tickets nicht eingelassen', 'pass-captain' ), $missing, $issued );
		$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html( __( 'No-Show-Liste', 'pass-captain' ) ) . '</title>'
			. '<style>@page{size:A4;margin:12mm}body{font:11px/1.35 system-ui,sans-serif;color:#000}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}th{background:#eee}@media print{button{display:none}}</style></head><body>';
		$html .= '<h1>' . esc_html( __( 'No-Show-Liste', 'pass-captain' ) ) . '</h1><p>' . esc_html( $title ) . ' · ' . esc_html( $summary ) . '</p>';
		if ( ! $missing ) {
			echo $html . '<p>' . esc_html( __( 'Alle gültigen Tickets wurden eingelassen.', 'pass-captain' ) ) . '</p><p><button onclick="window.print()">' . esc_html( __( 'Drucken', 'pass-captain' ) ) . '</button></p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			return;
		}
		$html .= '<table><thead><tr>';
		foreach ( self::header( $level ) as $cell ) { $html .= '<th>' . esc_html( $cell ) . '</th>'; }
		$html .= '</tr></thead><tbody>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		$n = 0;
		$block = '';
		foreach ( self::row_stream( $occurrence_id, $level ) as $row ) {
			$block .= '<tr>';
			foreach ( $row as $cell ) { $block .= '<td>' . esc_html( $cell ) . '</td>'; }
			$block .= '</tr>';
			if ( 0 === ++$n % AttendeeList::chunk_size() ) {
				echo $block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$block = '';
				Scope::flush_output();
			}
		}
		echo $block . '</tbody></table><p><button onclick="window.print()">' . esc_html( __( 'Drucken', 'pass-captain' ) ) . '</button></p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
}
