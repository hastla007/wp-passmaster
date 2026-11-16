<?php
/**
 * Admin viewer of the audit log (W9, A122, A413): filters by area (PII views, exports, privacy tools,
 * roles, corrections), action, object, user and date range (site-time days), readable action names and
 * object links, paging and an audited CSV export (2FA step-up). Requires wpcap_view_audit (granted to
 * administrators) or manage_options. Entries are deleted automatically after the audit retention
 * (Privacy\Retention step "audit_logs", filter wpcap_privacy_retention_days).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Privacy\AccessLog;
use PassCaptain\Privacy\Retention;
use PassCaptain\Reports\Scope;
use PassCaptain\Security\AuditTrail;
use PassCaptain\Security\StepUp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AuditLogPage {
	const SLUG = 'wpcap-audit-log';
	const CAP = 'wpcap_view_audit';
	const PER_PAGE = 50;
	const EXPORT_ACTION = 'wpcap_audit_export';
	/** Rows per database page while streaming the export (no overall limit). */
	const EXPORT_PAGE = 1000;

	/** Capability for the menu entry. */
	public static function menu_cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	public static function can_view() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Normalised filters from request data (default: the query string). Dates are accepted as from/to
	 * and date_from/date_to; both keys are returned.
	 *
	 * @param array|null $in Request data.
	 * @return array{group:string,action:string,object_type:string,object_id:int,user_id:int,from:string,to:string,date_from:string,date_to:string}
	 */
	public static function filters( $in = null ) {
		if ( ! is_array( $in ) ) { $in = wp_unslash( $_GET ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$date = static function ( $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		$groups = AccessLog::groups();
		$group = isset( $in['group'] ) && is_scalar( $in['group'] ) ? sanitize_key( (string) $in['group'] ) : '';
		$from = $date( $in['from'] ?? ( $in['date_from'] ?? '' ) );
		$to = $date( $in['to'] ?? ( $in['date_to'] ?? '' ) );
		return array(
			'group'       => isset( $groups[ $group ] ) ? $group : '',
			'action'      => isset( $in['audit_action'] ) && is_scalar( $in['audit_action'] ) ? sanitize_key( (string) $in['audit_action'] ) : '',
			'object_type' => isset( $in['object_type'] ) && is_scalar( $in['object_type'] ) ? sanitize_key( (string) $in['object_type'] ) : '',
			'object_id'   => isset( $in['object_id'] ) && is_scalar( $in['object_id'] ) ? absint( $in['object_id'] ) : 0,
			'user_id'     => isset( $in['user_id'] ) && is_scalar( $in['user_id'] ) ? absint( $in['user_id'] ) : 0,
			'from'        => $from,
			'to'          => $to,
			'date_from'   => $from,
			'date_to'     => $to,
		);
	}

	/** WHERE clause and arguments; dates are site-local days converted to UTC bounds (A413). */
	private static function where( array $f ) {
		$where = '1=1';
		$args = array();
		$groups = AccessLog::groups();
		if ( ! empty( $f['group'] ) && isset( $groups[ $f['group'] ] ) && $groups[ $f['group'] ] ) {
			$where .= ' AND action IN (' . implode( ',', array_fill( 0, count( $groups[ $f['group'] ] ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $groups[ $f['group'] ] ) );
		}
		if ( ! empty( $f['action'] ) ) { $where .= ' AND action=%s'; $args[] = sanitize_key( $f['action'] ); }
		if ( ! empty( $f['object_type'] ) ) { $where .= ' AND object_type=%s'; $args[] = sanitize_key( $f['object_type'] ); }
		if ( ! empty( $f['object_id'] ) ) { $where .= ' AND object_id=%d'; $args[] = absint( $f['object_id'] ); }
		if ( ! empty( $f['user_id'] ) ) { $where .= ' AND user_id=%d'; $args[] = absint( $f['user_id'] ); }
		$from = (string) ( $f['from'] ?? ( $f['date_from'] ?? '' ) );
		$to = (string) ( $f['to'] ?? ( $f['date_to'] ?? '' ) );
		if ( '' !== $from && '' !== ( $bound = self::utc_bound( $from ) ) ) { $where .= ' AND created_at >= %s'; $args[] = $bound; }
		if ( '' !== $to && '' !== ( $bound = self::utc_bound( $to, true ) ) ) { $where .= ' AND created_at < %s'; $args[] = $bound; }
		return array( $where, $args );
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
			$day = new \DateTimeImmutable( (string) $date . ' 00:00:00', new \DateTimeZone( Scope::site_timezone() ) );
		} catch ( \Exception $e ) {
			return '';
		}
		if ( $end ) { $day = $day->modify( '+1 day' ); }
		return $day->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Audit rows (newest first).
	 *
	 * @return array{rows:object[],total:int}
	 */
	public static function query( array $filters, $page = 1, $per_page = self::PER_PAGE ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		list( $where, $args ) = self::where( $filters );
		$per_page = max( 1, (int) $per_page );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
		$list_sql = "SELECT * FROM $table WHERE $where ORDER BY audit_id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per_page, ( max( 1, (int) $page ) - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/** Distinct values of a column for the filter selects (max. 300). */
	public static function distinct( $column ) {
		global $wpdb;
		$column = 'object_type' === $column ? 'object_type' : 'action';
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT $column FROM {$wpdb->prefix}wpcap_audit_logs ORDER BY $column LIMIT 300" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelisted column.
	}

	/** CSV rows (header first) for the filters, as an array (small exports, tests). */
	public static function csv_rows( array $filters ) {
		return iterator_to_array( self::csv_row_stream( $filters ), false );
	}

	/**
	 * CSV rows (header first) for the filters, newest first, read in pages of EXPORT_PAGE rows by
	 * audit_id (keyset): no row limit (QA-7), memory stays flat while streaming.
	 *
	 * @return \Generator
	 */
	public static function csv_row_stream( array $filters ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		list( $where, $args ) = self::where( $filters );
		yield array( 'audit_id', 'created_at_utc', 'user_id', 'action', 'object_type', 'object_id', 'details' );
		$last = PHP_INT_MAX;
		do {
			$sql = "SELECT * FROM $table WHERE $where AND audit_id < %d ORDER BY audit_id DESC LIMIT %d";
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $last, self::EXPORT_PAGE ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders.
			foreach ( $rows as $row ) {
				$last = (int) $row->audit_id;
				yield array( (int) $row->audit_id, (string) $row->created_at, (int) $row->user_id, (string) $row->action, (string) $row->object_type, (int) $row->object_id, (string) $row->details );
			}
			$full_page = count( $rows ) === self::EXPORT_PAGE;
		} while ( $full_page );
	}

	/**
	 * Builds the export after permission and 2FA checks; records the export in the audit log.
	 *
	 * @param bool $stream True: rows as a generator (download), false: the CSV string.
	 * @return array{filename:string,csv?:string,rows?:\Generator}|\WP_Error
	 */
	public static function export( array $filters, $stream = false ) {
		if ( ! self::can_view() ) { return Forms::forbidden(); }
		$check = StepUp::check( array( 'exports' ), self::EXPORT_ACTION );
		if ( is_wp_error( $check ) ) { return $check; }
		$total = (int) self::query( $filters, 1, 1 )['total'];
		Audit::record( 'audit_log_exported', 'audit', 0, array_merge( array_filter( $filters ), array( 'rows' => $total ) ) );
		$filename = 'pass-captain-audit-' . gmdate( 'Ymd-His' ) . '.csv';
		// $stream: rows as a generator for Scope::send_csv() (the admin download); else the CSV string.
		return $stream ? array( 'filename' => $filename, 'rows' => self::csv_row_stream( $filters ) ) : array( 'filename' => $filename, 'csv' => Scope::csv( self::csv_rows( $filters ) ) );
	}

	public static function handle_export() {
		if ( ! self::can_view() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		check_admin_referer( self::EXPORT_ACTION );
		$result = self::export( self::filters( wp_unslash( $_GET ) ), true );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Scope::send_csv( $result['rows'], $result['filename'] );
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
		if ( ! self::can_view() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$filters = self::filters();
		$page = Forms::current_page();
		$data = self::query( $filters, $page );
		AuditTrail::read( 'audit_log', 'audit', 0, array( 'scope' => md5( (string) wp_json_encode( $filters ) ) . $page ) );
		AccessLog::list_view( 'audit_log', $filters + array( 'paged' => $page ), count( $data['rows'] ) );
		$days = Retention::days( 'audit_logs' );
		$query_args = array_filter( array( 'group' => $filters['group'], 'audit_action' => $filters['action'], 'object_type' => $filters['object_type'], 'object_id' => $filters['object_id'], 'user_id' => $filters['user_id'], 'from' => $filters['from'], 'to' => $filters['to'] ) );
		$export_url = wp_nonce_url( add_query_arg( array_merge( $query_args, array( 'action' => self::EXPORT_ACTION ) ), admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
		$actions = array( '' => __( 'Alle Aktionen', 'pass-captain' ) );
		foreach ( self::distinct( 'action' ) as $a ) { $actions[ $a ] = self::action_label( $a ) !== $a ? self::action_label( $a ) . ' (' . $a . ')' : $a; }
		$objects = array( '' => __( 'Alle Objekte', 'pass-captain' ) );
		foreach ( self::distinct( 'object_type' ) as $ot ) { $objects[ $ot ] = self::object_type_label( $ot ); }
		$tz = Scope::site_timezone();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Audit-Log', 'pass-captain' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Nachvollziehbarkeit von Änderungen, Exporten und Zugriffen auf personenbezogene Daten. Details sind redigiert (keine Secrets, Codes oder E-Mail-Adressen). Jeder Export wird selbst protokolliert.', 'pass-captain' ) . ' ';
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			echo esc_html( sprintf( _n( 'Einträge werden nach %d Tag automatisch gelöscht.', 'Einträge werden nach %d Tagen automatisch gelöscht.', $days, 'pass-captain' ), $days ) );
		} else {
			echo esc_html__( 'Einträge werden nicht automatisch gelöscht.', 'pass-captain' );
		}
		echo '</p>';
		echo '<form method="get" class="wpcap-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><p>';
		Forms::select( 'group', array( '' => __( 'Alle Bereiche', 'pass-captain' ) ) + AccessLog::group_labels(), $filters['group'], 'wpcap-audit-group', 'aria-label="' . esc_attr__( 'Bereich', 'pass-captain' ) . '"' );
		echo ' ';
		Forms::select( 'audit_action', $actions, $filters['action'], 'wpcap-audit-action', 'aria-label="' . esc_attr__( 'Aktion', 'pass-captain' ) . '"' );
		echo ' ';
		Forms::select( 'object_type', $objects, $filters['object_type'], 'wpcap-audit-object', 'aria-label="' . esc_attr__( 'Objekt', 'pass-captain' ) . '"' );
		echo ' <label>' . esc_html__( 'Objekt-ID', 'pass-captain' ) . ' <input type="number" min="0" name="object_id" value="' . esc_attr( $filters['object_id'] ? (string) $filters['object_id'] : '' ) . '" style="width:7em"></label>';
		echo ' <label>' . esc_html__( 'Benutzer-ID', 'pass-captain' ) . ' <input type="number" min="0" name="user_id" value="' . esc_attr( $filters['user_id'] ? (string) $filters['user_id'] : '' ) . '" style="width:7em"></label>';
		echo ' <label>' . esc_html__( 'Von', 'pass-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '"></label>';
		echo ' <label>' . esc_html__( 'Bis', 'pass-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '"></label>';
		echo ' <button class="button" type="submit">' . esc_html__( 'Filtern', 'pass-captain' ) . '</button>';
		echo ' <a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'CSV-Export (wird protokolliert)', 'pass-captain' ) . '</a></p></form>';
		/* translators: 1: number of entries, 2: time zone */
		echo '<p>' . esc_html( sprintf( __( '%1$d Einträge · Zeiten in %2$s', 'pass-captain' ), $data['total'], $tz ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Objekt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Details', 'pass-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $data['rows'] ) { echo '<tr><td colspan="5">' . esc_html__( 'Keine Einträge gefunden.', 'pass-captain' ) . '</td></tr>'; }
		foreach ( $data['rows'] as $row ) {
			echo self::row_cells( $row ) . '<td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '<code style="white-space:pre-wrap;word-break:break-word">' . esc_html( self::details_text( $row->details ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		Forms::pagination( $data['total'], $page, self::PER_PAGE, Forms::page_url( self::SLUG, $query_args ) );
		echo '</div>';
	}
}
