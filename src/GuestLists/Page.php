<?php
/**
 * Admin page "Gästelisten": lists per event with quota usage, entries (add, CSV import, approve,
 * reject, issue, resend, cancel), CSV export, printable will-call list and name lookup with
 * "Angekommen" for check-in staff. Every handler re-checks permissions in Service.
 */
namespace PassCaptain\GuestLists;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Page {
	const SLUG = 'wpcap-guestlists';

	/** Menu capability: event managers, else check-in staff (lookup/will-call only). */
	public static function menu_cap() {
		return current_user_can( Service::MANAGE_CAP ) || ! current_user_can( Service::CHECKIN_CAP ) ? Service::MANAGE_CAP : Service::CHECKIN_CAP;
	}

	private static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, array_filter( $args ) );
	}

	/* ------------------------------------------------------------------ handlers */

	public static function handle_save_list() {
		Forms::verify( 'wpcap_gl_save_list' );
		$in = Forms::post();
		$result = Service::save_list( array(
			'list_id' => Forms::int( $in, 'list_id' ),
			'event_id' => Forms::int( $in, 'event_id' ),
			'occurrence_id' => Forms::int( $in, 'occurrence_id' ),
			'name' => Forms::text( $in, 'name' ),
			'reason' => Forms::choice( $in, 'reason', array_keys( Service::reasons() ), 'other' ),
			'quota' => Forms::int( $in, 'quota' ),
			'approver_id' => Forms::int( $in, 'approver_id' ),
			'note' => Forms::textarea( $in, 'note', 2000 ),
		) );
		$list_id = is_wp_error( $result ) ? Forms::int( $in, 'list_id' ) : (int) $result->list_id;
		Forms::finish( $result, __( 'Gästeliste gespeichert.', 'wp-pass-captain' ), self::url( array( 'event_id' => Forms::int( $in, 'event_id' ), 'list_id' => $list_id ) ) );
	}

	public static function handle_archive_list() {
		Forms::verify( 'wpcap_gl_archive_list' );
		$in = Forms::post();
		Forms::finish( Service::archive_list( Forms::int( $in, 'list_id' ) ), __( 'Gästeliste archiviert.', 'wp-pass-captain' ), self::url( array( 'event_id' => Forms::int( $in, 'event_id' ) ) ) );
	}

	public static function handle_add_entry() {
		Forms::verify( 'wpcap_gl_add_entry' );
		$in = Forms::post();
		$list_id = Forms::int( $in, 'list_id' );
		$result = Service::add_entry( $list_id, array(
			'name' => Forms::text( $in, 'name' ),
			'email' => Forms::text( $in, 'email' ),
			'company' => Forms::text( $in, 'company' ),
			'quantity' => Forms::int( $in, 'quantity', 1 ),
			'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ),
			'note' => Forms::text( $in, 'note', 255 ),
			'approve' => Forms::flag( $in, 'approve' ),
		) );
		Forms::finish( $result, __( 'Gast eingetragen.', 'wp-pass-captain' ), self::url( array( 'event_id' => Forms::int( $in, 'event_id' ), 'list_id' => $list_id ) ) );
	}

	public static function handle_import() {
		Forms::verify( 'wpcap_gl_import' );
		$in = Forms::post();
		$list_id = Forms::int( $in, 'list_id' );
		$csv = Forms::textarea( $in, 'csv', 200000 );
		// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		$upload = isset( $_FILES['csv_file'] ) && is_array( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; only the temp path and size are used.
		$tmp = is_string( $upload['tmp_name'] ?? null ) ? $upload['tmp_name'] : '';
		if ( '' !== $tmp && is_uploaded_file( $tmp ) && (int) ( $upload['size'] ?? 0 ) <= 512000 ) {
			$csv = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
		}
		$result = Service::import_csv( $list_id, $csv, Forms::int( $in, 'ticket_type_id' ) );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			/* translators: %d: number of guests */
			$message = sprintf( __( '%d Gäste importiert.', 'wp-pass-captain' ), $result['created'] );
			if ( $result['errors'] ) {
				$lines = array();
				foreach ( array_slice( $result['errors'], 0, 10, true ) as $line => $error ) { $lines[] = sprintf( /* translators: 1: line, 2: error */ __( 'Zeile %1$d: %2$s', 'wp-pass-captain' ), $line, $error ); }
				$result['warning'] = implode( ' ', $lines );
			}
		}
		Forms::finish( $result, $message, self::url( array( 'event_id' => Forms::int( $in, 'event_id' ), 'list_id' => $list_id ) ) );
	}

	public static function handle_entry_action() {
		Forms::verify( 'wpcap_gl_entry_action' );
		$in = Forms::post();
		$entry_id = Forms::int( $in, 'entry_id' );
		$op = Forms::choice( $in, 'op', array( 'approve', 'reject', 'issue', 'resend', 'cancel' ), '' );
		$messages = array(
			'approve' => __( 'Eintrag freigegeben.', 'wp-pass-captain' ),
			'reject' => __( 'Eintrag abgelehnt.', 'wp-pass-captain' ),
			'issue' => __( 'Freikarten ausgestellt.', 'wp-pass-captain' ),
			'resend' => __( 'Tickets erneut gesendet.', 'wp-pass-captain' ),
			'cancel' => __( 'Eintrag storniert; Tickets wurden gesperrt und das Kontingent freigegeben.', 'wp-pass-captain' ),
		);
		switch ( $op ) {
			case 'approve': $result = Service::approve( $entry_id ); break;
			case 'reject': $result = Service::reject( $entry_id, Forms::text( $in, 'note', 255 ) ); break;
			case 'issue': $result = Service::issue( $entry_id ); break;
			case 'resend': $result = Service::resend( $entry_id, Forms::text( $in, 'email' ) ); break;
			case 'cancel': $result = Service::cancel( $entry_id, Forms::text( $in, 'note', 255 ) ); break;
			default: $result = Forms::error( 'wpcap_guestlist_action', __( 'Unbekannte Aktion.', 'wp-pass-captain' ) );
		}
		Forms::finish( $result, $messages[ $op ] ?? '', self::url( array( 'event_id' => Forms::int( $in, 'event_id' ), 'list_id' => Forms::int( $in, 'list_id' ) ) ) );
	}

	public static function handle_arrive() {
		Forms::verify( 'wpcap_gl_arrive' );
		$in = Forms::post();
		$result = Service::arrive( Forms::int( $in, 'entry_id' ), Forms::int( $in, 'count' ) );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			/* translators: 1: checked in tickets, 2: remaining tickets */
			$message = sprintf( __( '%1$d Ticket(s) eingecheckt, %2$d offen.', 'wp-pass-captain' ), $result['checked_in'], $result['remaining'] );
			if ( $result['errors'] ) { $result['warning'] = implode( ' ', $result['errors'] ); }
		}
		Forms::finish( $result, $message, self::url( array( 'event_id' => Forms::int( $in, 'event_id' ), 'occurrence_id' => Forms::int( $in, 'occurrence_id' ), 'q' => Forms::text( $in, 'q' ) ) ) . '#wpcap-gl-checkin' );
	}

	public static function handle_export() {
		Forms::verify( 'wpcap_gl_export' );
		$event_id = absint( Forms::query_arg( 'event_id' ) );
		$rows = Service::export_rows( $event_id, absint( Forms::query_arg( 'list_id' ) ) );
		if ( is_wp_error( $rows ) ) { wp_die( esc_html( $rows->get_error_message() ), '', array( 'response' => 403 ) ); }
		\PassCaptain\Privacy\AccessLog::export( 'guestlist_csv', 'event', $event_id, array( 'list_id' => absint( Forms::query_arg( 'list_id' ) ), 'rows' => max( 0, count( $rows ) - 1 ) ) );
		Scope::send_download( Scope::csv( $rows ), 'gaesteliste-' . $event_id . '.csv', 'text/csv; charset=utf-8' );
	}

	public static function handle_print() {
		Forms::verify( 'wpcap_gl_print' );
		$html = self::will_call_html( absint( Forms::query_arg( 'occurrence_id' ) ) );
		if ( is_wp_error( $html ) ) { wp_die( esc_html( $html->get_error_message() ), '', array( 'response' => 403 ) ); }
		\PassCaptain\Privacy\AccessLog::export( 'guestlist_print', 'occurrence', absint( Forms::query_arg( 'occurrence_id' ) ) );
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); header( 'X-Content-Type-Options: nosniff' ); }
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/** Printable will-call list (standalone HTML document, every value escaped). */
	public static function will_call_html( $occurrence_id ) {
		$rows = Service::will_call( $occurrence_id );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$occ = Queries::occurrence( $occurrence_id );
		$event = $occ ? Queries::event( $occ->event_id ) : null;
		$title = ( $event ? $event->name : '' ) . ' – ' . ( $occ ? Scope::local( $occ->starts_at, $occ->timezone ) : '' );
		$guests = array_sum( array_map( static function ( $e ) { return (int) $e->quantity; }, $rows ) );
		$h = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html( __( 'Gästeliste', 'wp-pass-captain' ) . ' ' . $title ) . '</title>';
		$h .= '<style>body{font:12px/1.4 sans-serif;margin:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:4px 6px;text-align:left;vertical-align:top}th{background:#eee}td.box{width:18px}code{font-size:11px}@media print{a{display:none}}</style></head><body>';
		$h .= '<h1>' . esc_html( __( 'Gästeliste / Abholliste', 'wp-pass-captain' ) ) . '</h1><p>' . esc_html( $title ) . ' · ' . esc_html( sprintf( /* translators: 1: entries, 2: tickets */ __( '%1$d Einträge, %2$d Personen', 'wp-pass-captain' ), count( $rows ), $guests ) ) . '</p>';
		$h .= '<table><thead><tr><th></th><th>' . esc_html__( 'Name', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Firma', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Liste', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Personen', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Ticketart', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Ticketcodes', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		$labels = Service::status_labels();
		foreach ( $rows as $e ) {
			$persons = (int) $e->quantity . ( $e->plus_ones ? ' (' . sprintf( /* translators: %d: plus-ones */ _n( '+%d Begleitung', '+%d Begleitungen', (int) $e->plus_ones, 'wp-pass-captain' ), (int) $e->plus_ones ) . ')' : '' );
			$status = 'issued' === $e->status ? sprintf( /* translators: 1: checked in, 2: total */ __( '%1$d/%2$d eingecheckt', 'wp-pass-captain' ), (int) $e->tickets_checked_in, (int) $e->quantity ) : ( $labels[ $e->status ] ?? $e->status );
			$h .= '<tr><td class="box">☐</td><td>' . esc_html( $e->name ) . '</td><td>' . esc_html( $e->company ) . '</td><td>' . esc_html( $e->list_name ) . '</td><td>' . esc_html( $persons ) . '</td><td>' . esc_html( (string) $e->ticket_name ) . '</td><td><code>' . esc_html( implode( ' ', $e->codes ) ) . '</code></td><td>' . esc_html( $status ) . '</td></tr>';
		}
		$h .= '</tbody></table></body></html>';
		return $h;
	}

	/* ------------------------------------------------------------------ render */

	public static function render() {
		if ( ! current_user_can( Service::MANAGE_CAP ) && ! current_user_can( Service::CHECKIN_CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$events = Queries::event_options( Queries::event_ids_for( array( Service::MANAGE_CAP, Service::CHECKIN_CAP ) ) );
		$event_id = absint( Forms::query_arg( 'event_id' ) );
		if ( ! isset( $events[ $event_id ] ) ) { $event_id = (int) array_key_first( $events ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Gästelisten & Freikarten', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		if ( ! $events ) { echo '<p>' . esc_html__( 'Keine Events verfügbar.', 'wp-pass-captain' ) . '</p></div>'; return; }
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label>' . esc_html__( 'Event', 'wp-pass-captain' ) . ' ';
		Forms::select( 'event_id', $events, $event_id );
		echo '</label> <button class="button">' . esc_html__( 'Anzeigen', 'wp-pass-captain' ) . '</button></form>';

		if ( Service::can_manage( $event_id ) ) {
			\PassCaptain\Privacy\AccessLog::view( 'guestlist', 'event', $event_id, array_filter( array( 'list_id' => absint( Forms::query_arg( 'list_id' ) ) ) ) );
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Freikarten werden mit 0 € und 0 % USt ausgestellt und erscheinen in Umsatzberichten und Veranstalter-Abrechnungen nicht als Verkauf, sondern separat als Freikarten. Hinweis: Freikarten als Gegenleistung (z. B. Sponsoring, Medienkooperationen, Tausch gegen Werbeleistungen) können umsatzsteuerlich ein tauschähnlicher Umsatz sein – die steuerliche Beurteilung und Erfassung liegt beim Betreiber.', 'wp-pass-captain' ) . '</p></div>';
			self::render_lists( $event_id );
		}
		if ( Service::can_view_will_call( $event_id ) ) { self::render_checkin( $event_id ); }
		echo '</div>';
	}

	/** Users who may approve comps (user id => "Name (login)"); keeps a stored approver in the list. */
	private static function approver_choices( $current ) {
		$out = array();
		$users = function_exists( 'get_users' ) ? get_users( array( 'capability__in' => array( Service::APPROVE_CAP, 'manage_options' ), 'fields' => array( 'ID', 'display_name', 'user_login' ), 'number' => 200, 'orderby' => 'display_name' ) ) : array();
		foreach ( (array) $users as $user ) { $out[ (int) $user->ID ] = $user->display_name . ' (' . $user->user_login . ')'; }
		if ( $current && ! isset( $out[ $current ] ) ) { $out[ $current ] = '#' . $current; }
		return $out;
	}

	private static function occurrence_options( $event_id, $with_all = false ) {
		$out = $with_all ? array( 0 => __( 'Alle Termine', 'wp-pass-captain' ) ) : array();
		// Scheduled dates only; running/upcoming first (default), past ones marked (Queries::occurrence_choices()).
		$rows = Queries::occurrences_for_event( $event_id );
		foreach ( $rows as $o ) { $o->label = Scope::local( $o->starts_at, $o->timezone ) . ( $o->location ? ' – ' . $o->location : '' ); }
		return $out + Queries::occurrence_choices( $rows );
	}

	private static function render_lists( $event_id ) {
		$lists = Service::lists_for_event( $event_id );
		$reasons = Service::reasons();
		$occurrences = self::occurrence_options( $event_id, true );
		$list_id = absint( Forms::query_arg( 'list_id' ) );
		echo '<h2>' . esc_html__( 'Listen', 'wp-pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Liste', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Grund', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Termin', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Kontingent (vergeben / gesamt)', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Offene Anfragen', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Verantwortlich / Freigabe', 'wp-pass-captain' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $lists ) { echo '<tr><td colspan="7">' . esc_html__( 'Noch keine Gästelisten.', 'wp-pass-captain' ) . '</td></tr>'; }
		foreach ( $lists as $l ) {
			$u = Service::usage( $l->list_id );
			echo '<tr><td><a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'list_id' => (int) $l->list_id ) ) ) . '"><strong>' . esc_html( $l->name ) . '</strong></a></td><td>' . esc_html( $reasons[ $l->reason ] ?? $l->reason ) . '</td><td>' . esc_html( $occurrences[ (int) $l->occurrence_id ] ?? '' ) . '</td><td>' . esc_html( $u['used'] . ' / ' . ( $u['quota'] ?: '∞' ) ) . '</td><td>' . (int) $u['requested'] . '</td><td>' . esc_html( Forms::user_label( $l->owner_id ) . ' / ' . ( $l->approver_id ? Forms::user_label( $l->approver_id ) : __( 'alle Freigabeberechtigten', 'wp-pass-captain' ) ) ) . '</td><td>';
			Forms::open_form( 'wpcap_gl_archive_list', null, 'style="display:inline"' );
			Forms::hidden( 'list_id', $l->list_id );
			Forms::hidden( 'event_id', $event_id );
			echo '<button class="button-link button-link-delete">' . esc_html__( 'Archivieren', 'wp-pass-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_gl_export', 'event_id' => $event_id ), admin_url( 'admin-post.php' ) ), 'wpcap_gl_export' );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Alle Gästelisten als CSV exportieren', 'wp-pass-captain' ) . '</a></p>';

		$current = null;
		foreach ( $lists as $l ) { if ( (int) $l->list_id === $list_id ) { $current = $l; } }
		self::render_list_form( $event_id, $current, $occurrences );
		if ( $current ) { self::render_entries( $event_id, $current ); }
	}

	private static function render_list_form( $event_id, $list, array $occurrences ) {
		echo '<h2>' . esc_html( $list ? sprintf( /* translators: %s: list */ __( 'Liste „%s“ bearbeiten', 'wp-pass-captain' ), $list->name ) : __( 'Neue Gästeliste', 'wp-pass-captain' ) ) . '</h2>';
		Forms::open_form( 'wpcap_gl_save_list' );
		Forms::hidden( 'event_id', $event_id );
		Forms::hidden( 'list_id', $list ? $list->list_id : 0 );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="wpcap-gl-name">' . esc_html__( 'Name', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-gl-name" name="name" required value="' . esc_attr( $list ? $list->name : '' ) . '" placeholder="' . esc_attr__( 'z. B. Presse, Künstler, Sponsor, Personal', 'wp-pass-captain' ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-gl-reason">' . esc_html__( 'Grund', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'reason', Service::reasons(), $list ? $list->reason : 'press', 'wpcap-gl-reason' );
		echo '</td></tr><tr><th><label for="wpcap-gl-occ">' . esc_html__( 'Termin', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'occurrence_id', $occurrences, $list ? (int) $list->occurrence_id : 0, 'wpcap-gl-occ' );
		echo '</td></tr>';
		echo '<tr><th><label for="wpcap-gl-quota">' . esc_html__( 'Kontingent (Tickets inkl. Begleitung)', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-gl-quota" type="number" min="0" name="quota" value="' . esc_attr( $list ? (int) $list->quota : 0 ) . '"><p class="description">' . esc_html__( '0 = unbegrenzt. Freikarten verbrauchen die Kapazität des Termins wie verkaufte Tickets.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th><label for="wpcap-gl-approver">' . esc_html__( 'Freigabe nur durch', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'approver_id', array( 0 => __( 'alle Berechtigten', 'wp-pass-captain' ) ) + self::approver_choices( $list ? (int) $list->approver_id : 0 ), $list ? (int) $list->approver_id : 0, 'wpcap-gl-approver' );
		echo '<p class="description">' . esc_html__( '0 = alle mit dem Recht „Freikarten freigeben“ für dieses Event.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th><label for="wpcap-gl-note">' . esc_html__( 'Notiz', 'wp-pass-captain' ) . '</label></th><td><textarea id="wpcap-gl-note" name="note" rows="2" class="large-text">' . esc_textarea( $list ? (string) $list->note : '' ) . '</textarea></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Liste speichern', 'wp-pass-captain' ) . '</button></p></form>';
	}

	private static function render_entries( $event_id, $list ) {
		$types = array();
		foreach ( Service::ticket_types_for_list( $list ) as $t ) { $types[ (int) $t->ticket_type_id ] = $t->name . ( $list->occurrence_id ? '' : ' (' . Scope::local( $t->starts_at, $t->timezone ) . ')' ); }
		$can_approve = Service::can_approve( $list );
		$labels = Service::status_labels();
		$u = Service::usage( $list->list_id );
		echo '<h2>' . esc_html( sprintf( /* translators: 1: list, 2: used, 3: quota */ __( 'Einträge „%1$s“ – %2$d / %3$s Tickets vergeben', 'wp-pass-captain' ), $list->name, $u['used'], $u['quota'] ?: '∞' ) ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Firma', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'E-Mail', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Tickets', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Ticketart', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Aktionen', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		$entries = Service::entries_for_list( $list->list_id );
		if ( ! $entries ) { echo '<tr><td colspan="7">' . esc_html__( 'Noch keine Gäste.', 'wp-pass-captain' ) . '</td></tr>'; }
		foreach ( $entries as $e ) {
			echo '<tr><td>' . esc_html( $e->name ) . ( '' !== $e->note ? '<br><small>' . esc_html( $e->note ) . '</small>' : '' ) . '</td><td>' . esc_html( $e->company ) . '</td><td>' . esc_html( Service::is_placeholder_email( $e->email ) ? '' : $e->email ) . '</td><td>' . (int) $e->quantity . '</td><td>' . esc_html( (string) $e->ticket_name ) . '</td><td>' . esc_html( $labels[ $e->status ] ?? $e->status ) . ( $e->arrived_at ? ' · ' . esc_html__( 'angekommen', 'wp-pass-captain' ) : '' ) . '</td><td>';
			$ops = array();
			if ( $can_approve && 'requested' === $e->status ) { $ops['approve'] = __( 'Freigeben', 'wp-pass-captain' ); }
			if ( $can_approve && in_array( $e->status, array( 'requested', 'approved' ), true ) ) { $ops['reject'] = __( 'Ablehnen', 'wp-pass-captain' ); }
			if ( in_array( $e->status, array( 'approved', 'issuing' ), true ) ) { $ops['issue'] = __( 'Ausstellen', 'wp-pass-captain' ); }
			if ( 'issued' === $e->status && '' !== $e->email ) { $ops['resend'] = __( 'Erneut senden', 'wp-pass-captain' ); }
			if ( in_array( $e->status, array( 'requested', 'approved', 'issuing', 'issued' ), true ) ) { $ops['cancel'] = __( 'Stornieren', 'wp-pass-captain' ); }
			foreach ( $ops as $op => $label ) {
				Forms::open_form( 'wpcap_gl_entry_action', null, 'style="display:inline"' . ( 'cancel' === $op ? ' onsubmit="return confirm(\'' . esc_js( __( 'Eintrag stornieren? Ausgestellte Tickets werden gesperrt.', 'wp-pass-captain' ) ) . '\')"' : '' ) );
				Forms::hidden( 'entry_id', $e->entry_id );
				Forms::hidden( 'list_id', $list->list_id );
				Forms::hidden( 'event_id', $event_id );
				Forms::hidden( 'op', $op );
				echo '<button class="button button-small' . ( 'cancel' === $op ? ' button-link-delete' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $types ) {
			$export = wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_gl_export', 'event_id' => $event_id, 'list_id' => (int) $list->list_id ), admin_url( 'admin-post.php' ) ), 'wpcap_gl_export' );
			echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Diese Liste als CSV exportieren', 'wp-pass-captain' ) . '</a></p>';
			echo '<h3>' . esc_html__( 'Gast eintragen', 'wp-pass-captain' ) . '</h3>';
			Forms::open_form( 'wpcap_gl_add_entry' );
			Forms::hidden( 'list_id', $list->list_id );
			Forms::hidden( 'event_id', $event_id );
			echo '<p><input name="name" required placeholder="' . esc_attr__( 'Name', 'wp-pass-captain' ) . '" aria-label="' . esc_attr__( 'Name', 'wp-pass-captain' ) . '"> <input name="email" type="email" placeholder="' . esc_attr__( 'E-Mail (optional)', 'wp-pass-captain' ) . '" aria-label="' . esc_attr__( 'E-Mail', 'wp-pass-captain' ) . '"> <input name="company" placeholder="' . esc_attr__( 'Firma/Medium', 'wp-pass-captain' ) . '" aria-label="' . esc_attr__( 'Firma', 'wp-pass-captain' ) . '"> <input name="quantity" type="number" min="1" max="' . (int) Service::max_quantity() . '" value="1" aria-label="' . esc_attr__( 'Tickets inkl. Begleitung', 'wp-pass-captain' ) . '"> ';
			Forms::select( 'ticket_type_id', $types, (int) array_key_first( $types ), '', 'aria-label="' . esc_attr__( 'Ticketart', 'wp-pass-captain' ) . '"' );
			echo ' <input name="note" placeholder="' . esc_attr__( 'Notiz (keine Gesundheitsdaten)', 'wp-pass-captain' ) . '" aria-label="' . esc_attr__( 'Notiz – bitte keine Gesundheits- oder anderen besonderen Daten eintragen', 'wp-pass-captain' ) . '">';
			if ( $can_approve ) { echo ' <label><input type="checkbox" name="approve" value="1"> ' . esc_html__( 'gleich freigeben', 'wp-pass-captain' ) . '</label>'; }
			echo ' <button class="button button-primary">' . esc_html__( 'Eintragen', 'wp-pass-captain' ) . '</button></p></form>';
			echo '<h3>' . esc_html__( 'CSV-Import', 'wp-pass-captain' ) . '</h3>';
			Forms::open_form( 'wpcap_gl_import', null, 'enctype="multipart/form-data"' );
			Forms::hidden( 'list_id', $list->list_id );
			Forms::hidden( 'event_id', $event_id );
			echo '<p class="description">' . esc_html__( 'Eine Zeile pro Gast: Name;E-Mail;Anzahl;Ticketart (Ticketart als ID oder Name, leer = Auswahl unten). Importierte Gäste werden als Anfrage angelegt.', 'wp-pass-captain' ) . '</p>';
			echo '<p><textarea name="csv" rows="4" class="large-text" aria-label="' . esc_attr__( 'CSV-Daten', 'wp-pass-captain' ) . '" placeholder="Erika Muster;erika@example.com;2;"></textarea></p><p><input type="file" name="csv_file" accept=".csv,text/csv" aria-label="' . esc_attr__( 'CSV-Datei', 'wp-pass-captain' ) . '"> ';
			Forms::select( 'ticket_type_id', $types, (int) array_key_first( $types ), '', 'aria-label="' . esc_attr__( 'Standard-Ticketart', 'wp-pass-captain' ) . '"' );
			echo ' <button class="button">' . esc_html__( 'Importieren', 'wp-pass-captain' ) . '</button></p></form>';
		}
	}

	private static function render_checkin( $event_id ) {
		$occurrences = self::occurrence_options( $event_id );
		if ( ! $occurrences ) { return; }
		$occurrence_id = absint( Forms::query_arg( 'occurrence_id' ) );
		if ( ! isset( $occurrences[ $occurrence_id ] ) ) { $occurrence_id = (int) array_key_first( $occurrences ); }
		$q = Forms::query_arg( 'q' );
		echo '<h2 id="wpcap-gl-checkin">' . esc_html__( 'Abholliste & Einlass nach Namen', 'wp-pass-captain' ) . '</h2>';
		echo '<form method="get" action="#wpcap-gl-checkin"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="event_id" value="' . (int) $event_id . '"><label>' . esc_html__( 'Termin', 'wp-pass-captain' ) . ' ';
		Forms::select( 'occurrence_id', $occurrences, $occurrence_id );
		echo '</label> <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Name, Firma oder Ticketcode', 'wp-pass-captain' ) . '" aria-label="' . esc_attr__( 'Gast suchen', 'wp-pass-captain' ) . '"> <button class="button">' . esc_html__( 'Suchen', 'wp-pass-captain' ) . '</button> ';
		$print = wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_gl_print', 'occurrence_id' => $occurrence_id ), admin_url( 'admin-post.php' ) ), 'wpcap_gl_print' );
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $print ) . '">' . esc_html__( 'Abholliste drucken', 'wp-pass-captain' ) . '</a></form>';
		if ( '' === $q ) { return; }
		$results = Service::can_checkin( $event_id ) ? Service::search( $occurrence_id, $q ) : array();
		if ( is_wp_error( $results ) || ! $results ) { echo '<p>' . esc_html__( 'Kein ausgestellter Gästelisten-Eintrag gefunden.', 'wp-pass-captain' ) . '</p>'; return; }
		\PassCaptain\Privacy\AccessLog::list_view( 'guestlist_checkin', array( 'q' => $q ), count( $results ), 'occurrence', $occurrence_id );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Firma', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Liste', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Personen', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Codes', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Eingecheckt', 'wp-pass-captain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $results as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['company'] ) . '</td><td>' . esc_html( $r['list'] ) . '</td><td>' . (int) $r['quantity'] . '</td><td><code>' . esc_html( implode( ' ', $r['codes'] ) ) . '</code></td><td>' . (int) $r['tickets_checked_in'] . ' / ' . (int) $r['quantity'] . '</td><td>';
			if ( $r['tickets_valid'] ) {
				Forms::open_form( 'wpcap_gl_arrive', null, 'style="display:inline"' );
				Forms::hidden( 'entry_id', $r['entry_id'] );
				Forms::hidden( 'event_id', $event_id );
				Forms::hidden( 'occurrence_id', $occurrence_id );
				Forms::hidden( 'q', $q );
				echo '<input type="number" name="count" min="1" max="' . (int) $r['tickets_valid'] . '" value="' . (int) $r['tickets_valid'] . '" style="width:4em" aria-label="' . esc_attr__( 'Anzahl Personen', 'wp-pass-captain' ) . '"> <button class="button button-primary">' . esc_html__( 'Angekommen', 'wp-pass-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
