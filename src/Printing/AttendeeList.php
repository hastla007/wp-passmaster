<?php
/**
 * Attendee list for an occurrence (print view and CSV), minimised per role:
 *  - wpcap_manage_events on the event: full list incl. e-mail addresses, order and field answers;
 *  - wpcap_checkin (or wpcap_manage_orders) only: name, ticket type and status.
 */
namespace PassCaptain\Printing;

use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AttendeeList {
	/** 'full', 'basic' or '' (no access) for the current user. */
	public static function access_level( $occurrence_id ) {
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id ) { return ''; }
		if ( Scope::can_event( $event_id, array( 'wpcap_manage_events' ) ) ) { return 'full'; }
		if ( Scope::can_event( $event_id, array( 'wpcap_checkin', 'wpcap_manage_orders' ) ) ) { return 'basic'; }
		return '';
	}

	/**
	 * Rows (first row = header).
	 *
	 * @param string $level 'full' or 'basic'.
	 */
	public static function rows( $occurrence_id, $level ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tickets = Templates::tickets_for_occurrence( $occurrence_id, array( 'valid', 'checked_in' ) );
		$status_labels = array( 'valid' => __( 'Gültig', 'wp-pass-captain' ), 'checked_in' => __( 'Eingecheckt', 'wp-pass-captain' ) );
		if ( 'full' !== $level ) {
			$rows = array( array( __( 'Name', 'wp-pass-captain' ), __( 'Ticketart', 'wp-pass-captain' ), __( 'Status', 'wp-pass-captain' ) ) );
			foreach ( $tickets as $t ) {
				$rows[] = array( $t->attendee_name, $t->ticket_name, $status_labels[ $t->status ] ?? $t->status );
			}
			return $rows;
		}
		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT f.field_id,f.label FROM {$p}wpcap_participant_fields f INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=f.ticket_type_id WHERE tt.occurrence_id=%d ORDER BY f.position,f.field_id", absint( $occurrence_id ) ) );
		$answers = array();
		if ( $tickets ) {
			$ids = implode( ',', array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
			foreach ( $wpdb->get_results( "SELECT ticket_id,field_id,answer FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($ids)" ) as $a ) {
				$answers[ (int) $a->ticket_id ][ (int) $a->field_id ] = $a->answer;
			}
		}
		$header = array( __( 'Ticket', 'wp-pass-captain' ), __( 'Name', 'wp-pass-captain' ), __( 'Ticketart', 'wp-pass-captain' ), __( 'Status', 'wp-pass-captain' ), __( 'E-Mail Teilnehmer', 'wp-pass-captain' ), __( 'E-Mail Besteller', 'wp-pass-captain' ), __( 'Bestellung', 'wp-pass-captain' ) );
		foreach ( $fields as $f ) { $header[] = $f->label; }
		$rows = array( $header );
		foreach ( $tickets as $t ) {
			$buyer = false !== strpos( (string) $t->customer_email, '@invalid.example' ) ? '' : $t->customer_email;
			$row = array( substr( $t->public_id, 0, 12 ), $t->attendee_name, $t->ticket_name, $status_labels[ $t->status ] ?? $t->status, $t->attendee_email, $buyer, $t->order_public_id );
			foreach ( $fields as $f ) { $row[] = $answers[ (int) $t->ticket_id ][ (int) $f->field_id ] ?? ''; }
			$rows[] = $row;
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
		$title = ( $occ ? $occ->event_name . ' – ' . Scope::local( $occ->starts_at, $occ->timezone ) : '' );
		$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html( __( 'Teilnehmerliste', 'wp-pass-captain' ) ) . '</title>'
			. '<style>@page{size:A4;margin:12mm}body{font:11px/1.35 system-ui,sans-serif;color:#000}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}th{background:#eee}td.box{width:14px}@media print{button{display:none}}</style></head><body>';
		$html .= '<h1>' . esc_html( __( 'Teilnehmerliste', 'wp-pass-captain' ) ) . '</h1><p>' . esc_html( $title ) . ' · ' . esc_html( sprintf( /* translators: %d: count */ __( '%d Tickets', 'wp-pass-captain' ), count( $rows ) ) ) . '</p>';
		$html .= '<table><thead><tr><th></th>';
		foreach ( $header as $cell ) { $html .= '<th>' . esc_html( $cell ) . '</th>'; }
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr><td class="box">☐</td>';
			foreach ( $row as $cell ) { $html .= '<td>' . esc_html( $cell ) . '</td>'; }
			$html .= '</tr>';
		}
		$html .= '</tbody></table><p><button onclick="window.print()">' . esc_html( __( 'Drucken', 'wp-pass-captain' ) ) . '</button></p></body></html>';
		return $html;
	}
}
