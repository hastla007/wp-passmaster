<?php
/**
 * Read-only audit log viewer (A122): filter by area (PII views, exports, privacy tools, roles,
 * corrections), exact action, user, object and date. Entries are deleted automatically after the
 * audit retention (Privacy\Retention step "audit_logs", filter wpcap_privacy_retention_days).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Privacy\AccessLog;
use PassCaptain\Privacy\Retention;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AuditLogPage {
	const SLUG = 'wpcap-audit-log';
	const CAP = 'manage_options';
	const PER_PAGE = 50;

	/** Filters from the query string. */
	public static function filters() {
		$groups = AccessLog::groups();
		$group = sanitize_key( Forms::query_arg( 'group' ) );
		$date_from = Forms::query_arg( 'date_from' );
		$date_to = Forms::query_arg( 'date_to' );
		$valid = static function ( $date ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ); };
		return array(
			'group' => isset( $groups[ $group ] ) ? $group : '',
			'action' => sanitize_key( Forms::query_arg( 'audit_action' ) ),
			'user_id' => absint( Forms::query_arg( 'user_id', 0 ) ),
			'object_type' => sanitize_key( Forms::query_arg( 'object_type' ) ),
			'object_id' => absint( Forms::query_arg( 'object_id', 0 ) ),
			'date_from' => $valid( $date_from ) ? $date_from : '',
			'date_to' => $valid( $date_to ) ? $date_to : '',
		);
	}

	/**
	 * Audit entries matching the filters, newest first.
	 *
	 * @return array{rows: object[], total: int}
	 */
	public static function query( array $filters, $page = 1, $per_page = self::PER_PAGE ) {
		global $wpdb;
		$where = array( '1=1' );
		$args = array();
		$groups = AccessLog::groups();
		if ( ! empty( $filters['group'] ) && isset( $groups[ $filters['group'] ] ) ) {
			$where[] = 'action IN (' . implode( ',', array_fill( 0, count( $groups[ $filters['group'] ] ), '%s' ) ) . ')';
			$args = array_merge( $args, $groups[ $filters['group'] ] );
		}
		if ( ! empty( $filters['action'] ) ) { $where[] = 'action=%s'; $args[] = sanitize_key( $filters['action'] ); }
		if ( ! empty( $filters['user_id'] ) ) { $where[] = 'user_id=%d'; $args[] = absint( $filters['user_id'] ); }
		if ( ! empty( $filters['object_type'] ) ) { $where[] = 'object_type=%s'; $args[] = sanitize_key( $filters['object_type'] ); }
		if ( ! empty( $filters['object_id'] ) ) { $where[] = 'object_id=%d'; $args[] = absint( $filters['object_id'] ); }
		// A413: the filter days are site-time days; created_at is UTC.
		if ( ! empty( $filters['date_from'] ) ) { $where[] = 'created_at>=%s'; $args[] = self::utc_bound( $filters['date_from'] ); }
		if ( ! empty( $filters['date_to'] ) ) { $where[] = 'created_at<%s'; $args[] = self::utc_bound( $filters['date_to'], true ); }
		$sql_where = implode( ' AND ', $where );
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		$offset = ( max( 1, (int) $page ) - 1 ) * max( 1, (int) $per_page );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where";
		$rows_sql = "SELECT * FROM $table WHERE $sql_where ORDER BY audit_id DESC LIMIT %d OFFSET %d";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( max( 1, (int) $per_page ), $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/**
	 * A413: UTC bounds of a filter day given in the site time zone ("2026-03-29" = that day from
	 * 00:00 to 24:00 site time, also across a DST change).
	 *
	 * @param string $date Y-m-d.
	 * @param bool   $end  true: first second after the day (exclusive upper bound).
	 */
	public static function utc_bound( $date, $end = false ) {
		try {
			$day = new \DateTimeImmutable( (string) $date . ' 00:00:00', \PassCaptain\Frontend\Format::site_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}
		if ( $end ) { $day = $day->modify( '+1 day' ); }
		return $day->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A413: readable names of audit actions (the code stays visible for the action filter). Actions
	 * without a name are shown by their code.
	 *
	 * @return array<string, string>
	 */
	public static function action_labels() {
		$labels = array(
			'pii_viewed' => __( 'Personenbezogene Daten angesehen', 'pass-captain' ),
			'pii_list_viewed' => __( 'Liste mit personenbezogenen Daten angesehen', 'pass-captain' ),
			'pii_exported' => __( 'Personenbezogene Daten exportiert', 'pass-captain' ),
			'pii_api_read' => __( 'Personenbezogene Daten über die API gelesen', 'pass-captain' ),
			'privacy_export_run' => __( 'Datenschutz-Export gestartet', 'pass-captain' ),
			'privacy_erasure_run' => __( 'Datenschutz-Löschung gestartet', 'pass-captain' ),
			'privacy_export_completed' => __( 'Datenschutz-Export abgeschlossen', 'pass-captain' ),
			'privacy_erasure_completed' => __( 'Datenschutz-Löschung abgeschlossen', 'pass-captain' ),
			'privacy_erasure' => __( 'Daten gelöscht bzw. anonymisiert', 'pass-captain' ),
			'privacy_erasure_deferred' => __( 'Löschung zurückgestellt', 'pass-captain' ),
			'privacy_retention' => __( 'Aufbewahrungsfrist angewendet', 'pass-captain' ),
			'user_role_set' => __( 'Rolle gesetzt', 'pass-captain' ),
			'user_role_added' => __( 'Rolle hinzugefügt', 'pass-captain' ),
			'user_role_removed' => __( 'Rolle entzogen', 'pass-captain' ),
			'user_cap_added' => __( 'Recht vergeben', 'pass-captain' ),
			'user_cap_removed' => __( 'Recht entzogen', 'pass-captain' ),
			'user_access_revoked' => __( 'Zugänge nach Rollenänderung entzogen', 'pass-captain' ),
			'staff_assigned' => __( 'Team-Mitglied zugeordnet', 'pass-captain' ),
			'staff_removed' => __( 'Team-Mitglied entfernt', 'pass-captain' ),
			'record_corrected' => __( 'Datensatz korrigiert', 'pass-captain' ),
			'billing_updated' => __( 'Rechnungsdaten geändert', 'pass-captain' ),
			'attendee_list_csv' => __( 'Teilnehmerliste exportiert (CSV)', 'pass-captain' ),
			'attendee_list_html' => __( 'Teilnehmerliste gedruckt', 'pass-captain' ),
			'no_show_list_csv' => __( 'No-Show-Liste exportiert (CSV)', 'pass-captain' ),
			'no_show_list_html' => __( 'No-Show-Liste gedruckt', 'pass-captain' ),
			'print_batch' => __( 'Tickets gedruckt', 'pass-captain' ),
			'report_exported' => __( 'Bericht exportiert', 'pass-captain' ),
			'accounting_exported' => __( 'Buchhaltungsexport erstellt', 'pass-captain' ),
			'settlement_sheet_csv' => __( 'Abrechnung exportiert (CSV)', 'pass-captain' ),
			'settlement_sheet_html' => __( 'Abrechnung gedruckt', 'pass-captain' ),
			'order_created' => __( 'Bestellung angelegt', 'pass-captain' ),
			'order_cancelled' => __( 'Bestellung storniert', 'pass-captain' ),
			'order_cancelled_by_buyer' => __( 'Bestellung durch Käufer:in storniert', 'pass-captain' ),
			'order_refunded' => __( 'Bestellung erstattet', 'pass-captain' ),
			'order_access_link_resent' => __( 'Bestelllink erneut gesendet', 'pass-captain' ),
			'order_link_requested' => __( 'Bestelllink angefordert', 'pass-captain' ),
			'external_payment_confirmed' => __( 'Zahlung bestätigt', 'pass-captain' ),
			'manual_payment_confirmed' => __( 'Überweisung als bezahlt markiert', 'pass-captain' ),
			'payment_unallocated' => __( 'Zahlung ohne freies Kontingent', 'pass-captain' ),
			'payment_for_closed_order' => __( 'Zahlung für geschlossene Bestellung', 'pass-captain' ),
			'payment_amount_mismatch' => __( 'Zahlungsbetrag weicht ab', 'pass-captain' ),
			'refund_requested' => __( 'Erstattung angefordert', 'pass-captain' ),
			'refund_requested_by_customer' => __( 'Stornierung im Bestellportal angefragt', 'pass-captain' ),
			'refund_approved' => __( 'Erstattung freigegeben', 'pass-captain' ),
			'refund_rejected' => __( 'Erstattung abgelehnt', 'pass-captain' ),
			'refund_completed' => __( 'Erstattung durchgeführt', 'pass-captain' ),
			'refund_failed' => __( 'Erstattung fehlgeschlagen', 'pass-captain' ),
			'invoice_issued' => __( 'Rechnung ausgestellt', 'pass-captain' ),
			'credit_note_issued' => __( 'Rechnungskorrektur ausgestellt', 'pass-captain' ),
			'credit_note_reversed' => __( 'Rechnungskorrektur storniert', 'pass-captain' ),
			'ticket_checked_in' => __( 'Ticket eingecheckt', 'pass-captain' ),
			'ticket_checked_out' => __( 'Ticket ausgecheckt', 'pass-captain' ),
			'checkin_undone' => __( 'Check-in zurückgenommen', 'pass-captain' ),
			'ticket_transferred' => __( 'Ticket übertragen', 'pass-captain' ),
			'ticket_assigned' => __( 'Ticket zugewiesen', 'pass-captain' ),
			'ticket_holder_changed' => __( 'Ticketinhaber:in geändert', 'pass-captain' ),
			'ticket_attendee_renamed' => __( 'Teilnehmername geändert', 'pass-captain' ),
			'ticket_attendee_updated' => __( 'Teilnehmerangaben geändert', 'pass-captain' ),
			'ticket_reissued' => __( 'Ticket neu ausgestellt', 'pass-captain' ),
			'ticket_revoked' => __( 'Ticket widerrufen', 'pass-captain' ),
			'tickets_revoked' => __( 'Tickets widerrufen', 'pass-captain' ),
			'tickets_resent' => __( 'Tickets erneut gesendet', 'pass-captain' ),
			'ticket_codes_rotated' => __( 'Ticketcodes erneuert', 'pass-captain' ),
			'ticket_exchanged' => __( 'Ticket umgetauscht', 'pass-captain' ),
			'ticket_exchange_requested' => __( 'Umtausch angefragt', 'pass-captain' ),
			'ticket_mail_failed' => __( 'Ticketmail fehlgeschlagen', 'pass-captain' ),
			'ticket_issue_failed' => __( 'Ticketausstellung fehlgeschlagen', 'pass-captain' ),
			'admin_ticket_pdf' => __( 'Ticket-PDF in der Verwaltung erstellt', 'pass-captain' ),
			'portal_ticket_pdf' => __( 'Ticket-PDF im Bestellportal heruntergeladen', 'pass-captain' ),
			'event_created' => __( 'Event angelegt', 'pass-captain' ),
			'event_updated' => __( 'Event geändert', 'pass-captain' ),
			'event_cancelled' => __( 'Event abgesagt', 'pass-captain' ),
			'settings_updated' => __( 'Einstellungen geändert', 'pass-captain' ),
			'pos_sale' => __( 'Kassenverkauf', 'pass-captain' ),
			'pos_storno' => __( 'Kassenverkauf storniert', 'pass-captain' ),
			'schema_upgrade_failed' => __( 'Datenbank-Update fehlgeschlagen', 'pass-captain' ),
		) + ChangeHistory::action_labels();
		/**
		 * Readable names of audit actions in the audit log viewer (A413).
		 *
		 * @param array<string, string> $labels action => name.
		 */
		$labels = apply_filters( 'wpcap_audit_action_labels', $labels );
		return is_array( $labels ) ? $labels : array();
	}

	/** A413: name of an audit action, or its code. */
	public static function action_label( $action ) {
		return (string) ( self::action_labels()[ (string) $action ] ?? $action );
	}

	/**
	 * A413: admin link of an audit object (order, ticket, event, occurrence → its event, user); '' when
	 * the object type has no page.
	 */
	public static function object_url( $object_type, $object_id ) {
		global $wpdb;
		$object_id = absint( $object_id );
		if ( ! $object_id ) { return ''; }
		switch ( (string) $object_type ) {
			case 'order':
				return OrdersPage::order_url( $object_id );
			case 'ticket':
				return Forms::page_url( TicketsPage::SLUG, array( 's' => (string) $object_id ) );
			case 'event':
				return EventsPage::event_url( $object_id );
			case 'occurrence':
				$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $object_id ) );
				return $event_id ? EventsPage::event_url( $event_id ) : '';
			case 'user':
				return function_exists( 'admin_url' ) ? admin_url( 'user-edit.php?user_id=' . $object_id ) : '';
		}
		return '';
	}

	/** A413: translated object type. */
	public static function object_type_label( $object_type ) {
		$labels = array(
			'order'      => __( 'Bestellung', 'pass-captain' ),
			'ticket'     => __( 'Ticket', 'pass-captain' ),
			'event'      => __( 'Event', 'pass-captain' ),
			'occurrence' => __( 'Termin', 'pass-captain' ),
			'user'       => __( 'Benutzer', 'pass-captain' ),
			'settings'   => __( 'Einstellungen', 'pass-captain' ),
			'system'     => __( 'System', 'pass-captain' ),
			'invoice'    => __( 'Rechnung', 'pass-captain' ),
			'refund'     => __( 'Erstattung', 'pass-captain' ),
			'series'     => __( 'Serie', 'pass-captain' ),
			'waitlist'   => __( 'Warteliste', 'pass-captain' ),
			'partner'    => __( 'Partner', 'pass-captain' ),
			'list'       => __( 'Liste', 'pass-captain' ),
		);
		return $labels[ (string) $object_type ] ?? (string) $object_type;
	}

	/** Details for display: JSON without the internal de-duplication fingerprint, shortened. */
	public static function details_text( $details ) {
		$data = json_decode( (string) $details, true );
		if ( ! is_array( $data ) ) { return (string) $details; }
		unset( $data['fp'] );
		$text = (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return \PassCaptain\Text::ellipsis( $text, 400 );
	}

	/**
	 * A413: time (site time zone), user, action (name and code) and linked object of an audit row as
	 * `<tr>` with its first four cells (escaped HTML).
	 */
	public static function row_cells( $row ) {
		$url = self::object_url( $row->object_type, (int) $row->object_id );
		$object = self::object_type_label( $row->object_type ) . ( (int) $row->object_id ? ' #' . (int) $row->object_id : '' );
		$label = self::action_label( $row->action );
		return '<tr><td>' . esc_html( \PassCaptain\Frontend\Format::site_datetime( (string) $row->created_at ) ) . '</td>'
			. '<td>' . esc_html( (int) $row->user_id ? Forms::user_label( (int) $row->user_id ) : __( 'System', 'pass-captain' ) ) . '</td>'
			. '<td>' . ( $label !== (string) $row->action ? esc_html( $label ) . '<br>' : '' ) . '<code>' . esc_html( $row->action ) . '</code></td>'
			. '<td>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $object ) . '</a>' : esc_html( $object ) ) . '</td>';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$filters = self::filters();
		$page = Forms::current_page();
		$data = self::query( $filters, $page );
		AccessLog::list_view( 'audit_log', $filters + array( 'paged' => $page ), count( $data['rows'] ) );
		$days = Retention::days( 'audit_logs' );
		$base = Forms::page_url( self::SLUG, array_filter( array(
			'group' => $filters['group'],
			'audit_action' => $filters['action'],
			'user_id' => $filters['user_id'],
			'object_type' => $filters['object_type'],
			'object_id' => $filters['object_id'],
			'date_from' => $filters['date_from'],
			'date_to' => $filters['date_to'],
		) ) );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Audit-Log', 'pass-captain' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Protokoll von Änderungen, Exporten und Zugriffen auf personenbezogene Daten. Personenbezogene Werte erscheinen hier nur maskiert.', 'pass-captain' ) . ' ';
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			echo esc_html( sprintf( _n( 'Einträge werden nach %d Tag automatisch gelöscht.', 'Einträge werden nach %d Tagen automatisch gelöscht.', $days, 'pass-captain' ), $days ) );
		} else {
			echo esc_html__( 'Einträge werden nicht automatisch gelöscht.', 'pass-captain' );
		}
		echo '</p>';
		echo '<form method="get" class="wpcap-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		Forms::select( 'group', array( '' => __( 'Alle Bereiche', 'pass-captain' ) ) + AccessLog::group_labels(), $filters['group'], '', 'aria-label="' . esc_attr__( 'Bereich', 'pass-captain' ) . '"' );
		echo ' <input type="text" name="audit_action" size="18" value="' . esc_attr( $filters['action'] ) . '" placeholder="' . esc_attr__( 'Aktion, z. B. pii_viewed', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Aktion', 'pass-captain' ) . '">';
		echo ' <input type="number" name="user_id" min="0" style="width:7em" value="' . esc_attr( $filters['user_id'] ? (string) $filters['user_id'] : '' ) . '" placeholder="' . esc_attr__( 'Benutzer-ID', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Benutzer-ID', 'pass-captain' ) . '">';
		echo ' <input type="text" name="object_type" size="10" value="' . esc_attr( $filters['object_type'] ) . '" placeholder="' . esc_attr__( 'Objekttyp', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Objekttyp', 'pass-captain' ) . '">';
		echo ' <input type="number" name="object_id" min="0" style="width:7em" value="' . esc_attr( $filters['object_id'] ? (string) $filters['object_id'] : '' ) . '" placeholder="' . esc_attr__( 'Objekt-ID', 'pass-captain' ) . '" aria-label="' . esc_attr__( 'Objekt-ID', 'pass-captain' ) . '">';
		echo ' <input type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" aria-label="' . esc_attr__( 'Von (Website-Zeit)', 'pass-captain' ) . '">';
		echo ' <input type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" aria-label="' . esc_attr__( 'Bis (Website-Zeit)', 'pass-captain' ) . '">';
		echo ' <button class="button" type="submit">' . esc_html__( 'Filtern', 'pass-captain' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Objekt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Details', 'pass-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Keine Einträge gefunden.', 'pass-captain' ) . '</td></tr>';
		}
		foreach ( $data['rows'] as $row ) {
			echo self::row_cells( $row ) . '<td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '<code style="white-space:pre-wrap;word-break:break-word">' . esc_html( self::details_text( $row->details ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		Forms::pagination( $data['total'], $page, self::PER_PAGE, $base );
		echo '</div>';
	}
}
