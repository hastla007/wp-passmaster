<?php
/**
 * Guest lists and complimentary tickets ("Freikarten").
 *
 * Lists belong to an event (optionally to one occurrence) and carry a reason code, an optional
 * quota (tickets incl. plus-ones), an owner and an optional designated approver. Entries go through
 * requested → approved → issued (or rejected / cancelled). Approval needs the capability
 * wpcap_approve_comps plus event scope; the quota is enforced at approval under a row lock on the
 * list (approved + issuing + issued entries count against it).
 *
 * Issuing an entry creates a 0 € order through Orders::create() with trusted internal options
 * (payment_provider "comp", key_namespace "guestlist", unit prices/line totals/taxes 0, door sales
 * allowed) – the same pattern as the box office "Freikarte" (pos_free) – and confirms it with
 * Orders::confirm_external_payment(), which issues the tickets and mails them to the guest. The
 * comp reason is stored in the order's price_snapshot (key "comp"). Capacity is consumed like any
 * other ticket; cancelling an entry cancels the order (tickets revoked, capacity freed).
 * Guests without e-mail get the placeholder guestlist+<hash>@invalid.example; mails to that
 * reserved domain are suppressed and the tickets are handed out from the will-call list.
 *
 * Arrival ("Angekommen") checks the entry's tickets in through Checkin::perform() (event scope,
 * admission policies, check-in log): only code hashes are stored, so each ticket gets a fresh
 * code first (like printing), which is used once for the check-in.
 */
namespace PassCaptain\GuestLists;

use PassCaptain\Audit;
use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const MANAGE_CAP = 'wpcap_manage_events';
	const APPROVE_CAP = 'wpcap_approve_comps';
	const CHECKIN_CAP = 'wpcap_checkin';
	const PROVIDER = 'comp';
	const NAMESPACE_KEY = 'guestlist';
	const PLACEHOLDER_DOMAIN = '@invalid.example';
	/** Entry statuses whose tickets count against the list quota. */
	const QUOTA_STATUSES = array( 'approved', 'issuing', 'issued' );

	public static function reasons() {
		return array(
			'press' => __( 'Presse', 'wp-pass-captain' ),
			'artist' => __( 'Künstler', 'wp-pass-captain' ),
			'sponsor' => __( 'Sponsor', 'wp-pass-captain' ),
			'staff' => __( 'Personal', 'wp-pass-captain' ),
			'promotion' => __( 'Promotion', 'wp-pass-captain' ),
			'other' => __( 'Sonstiges', 'wp-pass-captain' ),
		);
	}

	public static function status_labels() {
		return array(
			'requested' => __( 'Angefragt', 'wp-pass-captain' ),
			'approved' => __( 'Freigegeben', 'wp-pass-captain' ),
			'issuing' => __( 'Wird ausgestellt', 'wp-pass-captain' ),
			'issued' => __( 'Ausgestellt', 'wp-pass-captain' ),
			'rejected' => __( 'Abgelehnt', 'wp-pass-captain' ),
			'cancelled' => __( 'Storniert', 'wp-pass-captain' ),
		);
	}

	/** Payment providers that mark complimentary orders (excluded from revenue and settlements). */
	public static function comp_providers() {
		return \PassCaptain\Reports\Data::comp_providers();
	}

	/** Maximum tickets per entry (guest + plus-ones). */
	public static function max_quantity() {
		return max( 1, min( 100, (int) apply_filters( 'wpcap_guestlist_max_tickets_per_entry', 20 ) ) );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function forbidden() {
		return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), 403 );
	}

	/* ------------------------------------------------------------------ permissions */

	public static function can_manage( $event_id ) {
		return Scope::can_event( $event_id, self::MANAGE_CAP );
	}

	/**
	 * Approving needs wpcap_approve_comps and event scope (manage_events or approve_comps assignment).
	 * A list with a designated approver can only be decided by that user or a site administrator.
	 */
	public static function can_approve( $list ) {
		if ( ! $list || ! current_user_can( self::APPROVE_CAP ) ) { return false; }
		if ( ! Scope::can_event( (int) $list->event_id, array( self::APPROVE_CAP, self::MANAGE_CAP ) ) ) { return false; }
		$approver = (int) $list->approver_id;
		return ! $approver || get_current_user_id() === $approver || current_user_can( 'manage_options' );
	}

	/** Whether the current user requested this entry and may therefore not approve it (setting guestlist_four_eyes). */
	public static function self_approval_blocked( $entry ) {
		if ( ! (int) \PassCaptain\Settings::get( 'guestlist_four_eyes' ) || current_user_can( 'manage_options' ) ) { return false; }
		return (int) $entry->requested_by && (int) $entry->requested_by === get_current_user_id();
	}

	public static function can_checkin( $event_id ) {
		return Scope::can_event( $event_id, self::CHECKIN_CAP );
	}

	/** Will-call list access: event managers and check-in staff of the event. */
	public static function can_view_will_call( $event_id ) {
		return self::can_manage( $event_id ) || self::can_checkin( $event_id );
	}

	/* ------------------------------------------------------------------ reads */

	public static function get_list( $list_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_guest_lists WHERE list_id=%d", absint( $list_id ) ) );
	}

	public static function get_entry( $entry_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_guest_entries WHERE entry_id=%d", absint( $entry_id ) ) );
	}

	public static function lists_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_guest_lists WHERE event_id=%d AND status='active' ORDER BY name,list_id", absint( $event_id ) ) );
	}

	public static function entries_for_list( $list_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( "SELECT g.*,tt.name AS ticket_name FROM {$p}wpcap_guest_entries g LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=g.ticket_type_id WHERE g.list_id=%d ORDER BY g.name,g.entry_id", absint( $list_id ) ) );
	}

	/** Quota usage of a list: quota (0 = unlimited), used (approved/issued tickets), requested, issued. */
	public static function usage( $list_id ) {
		global $wpdb;
		$list = self::get_list( $list_id );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status,COALESCE(SUM(quantity),0) AS n FROM {$wpdb->prefix}wpcap_guest_entries WHERE list_id=%d GROUP BY status", absint( $list_id ) ) );
		$by = array();
		foreach ( $rows as $r ) { $by[ $r->status ] = (int) $r->n; }
		$used = 0;
		foreach ( self::QUOTA_STATUSES as $s ) { $used += $by[ $s ] ?? 0; }
		return array( 'quota' => $list ? (int) $list->quota : 0, 'used' => $used, 'requested' => $by['requested'] ?? 0, 'issued' => $by['issued'] ?? 0 );
	}

	/** Ticket types an entry of the list may use (the list's occurrence, else all occurrences of the event). */
	public static function ticket_types_for_list( $list ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( (int) $list->occurrence_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT tt.*,o.starts_at,o.timezone FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.occurrence_id=%d ORDER BY tt.ticket_type_id", (int) $list->occurrence_id ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT tt.*,o.starts_at,o.timezone FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d ORDER BY o.starts_at,tt.ticket_type_id", (int) $list->event_id ) );
	}

	/* ------------------------------------------------------------------ lists */

	/**
	 * Creates or updates a list.
	 *
	 * @param array $in event_id, occurrence_id (0 = whole event), name, reason, quota, owner_id, approver_id, note; list_id to update.
	 * @return object|\WP_Error List row.
	 */
	public static function save_list( array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$list_id = absint( $in['list_id'] ?? 0 );
		$existing = $list_id ? self::get_list( $list_id ) : null;
		if ( $list_id && ! $existing ) { return self::error( 'wpcap_guestlist_missing', __( 'Gästeliste nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$event_id = $existing ? (int) $existing->event_id : absint( $in['event_id'] ?? 0 );
		if ( ! $event_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return self::error( 'wpcap_guestlist_event', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $event_id ) ) { return self::forbidden(); }
		$occurrence_id = absint( $in['occurrence_id'] ?? ( $existing ? $existing->occurrence_id : 0 ) );
		if ( $occurrence_id && $event_id !== Scope::occurrence_event( $occurrence_id ) ) { return self::error( 'wpcap_guestlist_occurrence', __( 'Der Termin gehört nicht zu diesem Event.', 'wp-pass-captain' ) ); }
		$name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return self::error( 'wpcap_guestlist_name', __( 'Bitte einen Namen für die Gästeliste angeben.', 'wp-pass-captain' ) ); }
		$reason = sanitize_key( (string) ( $in['reason'] ?? 'other' ) );
		if ( ! isset( self::reasons()[ $reason ] ) ) { $reason = 'other'; }
		$row = array(
			'event_id' => $event_id,
			'occurrence_id' => $occurrence_id,
			'name' => $name,
			'reason' => $reason,
			'quota' => min( 100000, absint( $in['quota'] ?? 0 ) ),
			'owner_id' => absint( $in['owner_id'] ?? ( $existing ? $existing->owner_id : get_current_user_id() ) ),
			'approver_id' => absint( $in['approver_id'] ?? 0 ),
			'note' => mb_substr( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ), 0, 2000 ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			if ( (int) $existing->approver_id !== $row['approver_id'] && ! self::can_approve( $existing ) ) { return self::error( 'wpcap_forbidden', __( 'Nur Freigabeberechtigte können die freigebende Person ändern.', 'wp-pass-captain' ), 403 ); }
			if ( $row['quota'] && $row['quota'] < self::usage( $list_id )['used'] ) { return self::error( 'wpcap_guestlist_quota', __( 'Das Kontingent ist kleiner als die bereits freigegebenen Tickets.', 'wp-pass-captain' ), 409 ); }
			if ( $occurrence_id !== (int) $existing->occurrence_id && $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_guest_entries WHERE list_id=%d", $list_id ) ) ) { return self::error( 'wpcap_guestlist_occurrence', __( 'Der Termin kann nicht mehr geändert werden, die Liste hat bereits Einträge.', 'wp-pass-captain' ), 409 ); }
			$wpdb->update( "{$p}wpcap_guest_lists", $row, array( 'list_id' => $list_id ) );
			Audit::record( 'guestlist_updated', 'event', $event_id, array( 'list_id' => $list_id ) );
			return self::get_list( $list_id );
		}
		$row += array( 'status' => 'active', 'created_by' => get_current_user_id(), 'created_at' => $row['updated_at'] );
		if ( ! $wpdb->insert( "{$p}wpcap_guest_lists", $row ) ) { return self::error( 'wpcap_guestlist_save', __( 'Die Gästeliste konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		$list_id = (int) $wpdb->insert_id;
		Audit::record( 'guestlist_created', 'event', $event_id, array( 'list_id' => $list_id, 'reason' => $reason, 'quota' => $row['quota'] ) );
		return self::get_list( $list_id );
	}

	/** Archives a list (only without open or issued entries). */
	public static function archive_list( $list_id ) {
		global $wpdb;
		$list = self::get_list( $list_id );
		if ( ! $list ) { return self::error( 'wpcap_guestlist_missing', __( 'Gästeliste nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $list->event_id ) ) { return self::forbidden(); }
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_guest_entries WHERE list_id=%d AND status IN ('requested','approved','issuing','issued')", (int) $list->list_id ) );
		if ( $open ) { return self::error( 'wpcap_guestlist_open_entries', __( 'Die Liste hat noch offene oder ausgestellte Einträge.', 'wp-pass-captain' ), 409 ); }
		$wpdb->update( "{$wpdb->prefix}wpcap_guest_lists", array( 'status' => 'archived', 'updated_at' => current_time( 'mysql', true ) ), array( 'list_id' => (int) $list->list_id ) );
		Audit::record( 'guestlist_archived', 'event', (int) $list->event_id, array( 'list_id' => (int) $list->list_id ) );
		return true;
	}

	/* ------------------------------------------------------------------ entries */

	/**
	 * Adds a request to a list.
	 *
	 * @param array $in name, email, company, quantity (tickets incl. plus-ones), ticket_type_id, note,
	 *                  approve (bool: approve right away when the user may approve).
	 * @return object|\WP_Error Entry row.
	 */
	public static function add_entry( $list_id, array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$list = self::get_list( $list_id );
		if ( ! $list || 'active' !== $list->status ) { return self::error( 'wpcap_guestlist_missing', __( 'Gästeliste nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $list->event_id ) ) { return self::forbidden(); }
		$name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return self::error( 'wpcap_guestlist_entry_name', __( 'Bitte den Namen des Gastes angeben.', 'wp-pass-captain' ) ); }
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		if ( '' !== $email && ( ! is_email( $email ) || self::is_placeholder_email( $email ) ) ) { return self::error( 'wpcap_guestlist_entry_email', __( 'Die E-Mail-Adresse ist ungültig.', 'wp-pass-captain' ) ); }
		$quantity = absint( $in['quantity'] ?? 1 );
		if ( $quantity < 1 || $quantity > self::max_quantity() ) {
			/* translators: %d: maximum tickets */
			return self::error( 'wpcap_guestlist_entry_quantity', sprintf( _n( 'Bitte %d Ticket (inkl. Begleitpersonen) angeben.', 'Bitte 1 bis %d Tickets (inkl. Begleitpersonen) angeben.', self::max_quantity(), 'wp-pass-captain' ), self::max_quantity()) );
		}
		$type_id = absint( $in['ticket_type_id'] ?? 0 );
		$type = null;
		foreach ( self::ticket_types_for_list( $list ) as $t ) {
			if ( (int) $t->ticket_type_id === $type_id ) { $type = $t; }
		}
		if ( ! $type ) { return self::error( 'wpcap_guestlist_entry_type', __( 'Bitte eine Ticketart dieses Termins wählen.', 'wp-pass-captain' ) ); }
		$now = current_time( 'mysql', true );
		$row = array(
			'list_id' => (int) $list->list_id,
			'event_id' => (int) $list->event_id,
			'occurrence_id' => (int) $type->occurrence_id,
			'ticket_type_id' => $type_id,
			'name' => $name,
			'email' => (string) $email,
			'company' => mb_substr( sanitize_text_field( (string) ( $in['company'] ?? '' ) ), 0, 190 ),
			'quantity' => $quantity,
			'note' => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 255 ),
			'status' => 'requested',
			'requested_by' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( ! $wpdb->insert( "{$p}wpcap_guest_entries", $row ) ) { return self::error( 'wpcap_guestlist_save', __( 'Der Eintrag konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		$entry_id = (int) $wpdb->insert_id;
		Audit::record( 'guestlist_entry_requested', 'event', (int) $list->event_id, array( 'list_id' => (int) $list->list_id, 'entry_id' => $entry_id, 'quantity' => $quantity ) );
		if ( ! empty( $in['approve'] ) && self::can_approve( $list ) && ! self::self_approval_blocked( self::get_entry( $entry_id ) ) ) {
			$approved = self::approve( $entry_id );
			if ( is_wp_error( $approved ) ) { return $approved; }
		}
		return self::get_entry( $entry_id );
	}

	/**
	 * Imports entries from CSV text: name;email;count;type per line (header optional, type = ticket
	 * type id or name, else $default_type_id). Returns created count and per-line errors.
	 *
	 * @return array|\WP_Error ['created' => int, 'errors' => [line => message]]
	 */
	public static function import_csv( $list_id, $csv, $default_type_id = 0 ) {
		$list = self::get_list( $list_id );
		if ( ! $list ) { return self::error( 'wpcap_guestlist_missing', __( 'Gästeliste nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $list->event_id ) ) { return self::forbidden(); }
		$types = array();
		foreach ( self::ticket_types_for_list( $list ) as $t ) {
			$types[ (string) (int) $t->ticket_type_id ] = (int) $t->ticket_type_id;
			$types[ mb_strtolower( trim( (string) $t->name ) ) ] = $types[ mb_strtolower( trim( (string) $t->name ) ) ] ?? (int) $t->ticket_type_id;
		}
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv );
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( count( $lines ) > 1001 ) { return self::error( 'wpcap_guestlist_import_size', __( 'Bitte höchstens 1000 Zeilen pro Import.', 'wp-pass-captain' ) ); }
		$created = 0;
		$errors = array();
		foreach ( $lines as $n => $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$cols = array_map( 'trim', str_getcsv( $line, false !== strpos( $line, ';' ) ? ';' : ',', '"', '\\' ) );
			if ( 0 === $n && in_array( mb_strtolower( $cols[0] ?? '' ), array( 'name', 'gast' ), true ) ) { continue; }
			$type_key = mb_strtolower( (string) ( $cols[3] ?? '' ) );
			$type_id = '' === $type_key ? absint( $default_type_id ) : ( $types[ $type_key ] ?? 0 );
			$entry = self::add_entry( $list_id, array( 'name' => $cols[0] ?? '', 'email' => $cols[1] ?? '', 'quantity' => '' === ( $cols[2] ?? '' ) ? 1 : $cols[2], 'ticket_type_id' => $type_id ) );
			if ( is_wp_error( $entry ) ) { $errors[ $n + 1 ] = $entry->get_error_message(); } else { ++$created; }
		}
		return array( 'created' => $created, 'errors' => $errors );
	}

	/** Approves a requested entry; enforces the list quota under a lock on the list row. */
	public static function approve( $entry_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$entry = self::get_entry( $entry_id );
		$list = $entry ? self::get_list( $entry->list_id ) : null;
		if ( ! $entry || ! $list ) { return self::error( 'wpcap_guestlist_entry_missing', __( 'Eintrag nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_approve( $list ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung, Freikarten freizugeben.', 'wp-pass-captain' ), 403 ); }
		if ( self::self_approval_blocked( $entry ) ) {
			return self::error( 'wpcap_guestlist_four_eyes', __( 'Vier-Augen-Prinzip: Eine Freikarte kann nicht von der Person freigegeben werden, die sie beantragt hat.', 'wp-pass-captain' ), 403 );
		}
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT quota FROM {$p}wpcap_guest_lists WHERE list_id=%d FOR UPDATE", (int) $list->list_id ) );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT status,quantity FROM {$p}wpcap_guest_entries WHERE entry_id=%d FOR UPDATE", (int) $entry->entry_id ) );
		if ( ! $locked || ! $current ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_guestlist_busy', __( 'Bitte erneut versuchen.', 'wp-pass-captain' ), 503 ); }
		if ( 'requested' !== $current->status ) {
			$wpdb->query( 'COMMIT' );
			return in_array( $current->status, self::QUOTA_STATUSES, true ) ? self::get_entry( $entry_id ) : self::error( 'wpcap_guestlist_state', __( 'Der Eintrag kann in diesem Status nicht freigegeben werden.', 'wp-pass-captain' ), 409 );
		}
		if ( (int) $locked->quota > 0 ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_guest_entries WHERE list_id=%d AND status IN ('approved','issuing','issued')", (int) $list->list_id ) );
			if ( $used + (int) $current->quantity > (int) $locked->quota ) {
				$wpdb->query( 'ROLLBACK' );
				/* translators: 1: used, 2: quota */
				return self::error( 'wpcap_guestlist_quota', sprintf( __( 'Das Kontingent der Liste ist ausgeschöpft (%1$d von %2$d Tickets vergeben).', 'wp-pass-captain' ), $used, (int) $locked->quota ), 409 );
			}
		}
		$now = current_time( 'mysql', true );
		$wpdb->update( "{$p}wpcap_guest_entries", array( 'status' => 'approved', 'decided_by' => get_current_user_id(), 'decided_at' => $now, 'updated_at' => $now ), array( 'entry_id' => (int) $entry->entry_id ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'guestlist_entry_approved', 'event', (int) $list->event_id, array( 'list_id' => (int) $list->list_id, 'entry_id' => (int) $entry->entry_id, 'quantity' => (int) $current->quantity ) );
		return self::get_entry( $entry_id );
	}

	public static function reject( $entry_id, $note = '' ) {
		global $wpdb;
		$entry = self::get_entry( $entry_id );
		$list = $entry ? self::get_list( $entry->list_id ) : null;
		if ( ! $entry || ! $list ) { return self::error( 'wpcap_guestlist_entry_missing', __( 'Eintrag nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_approve( $list ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung, Freikarten freizugeben.', 'wp-pass-captain' ), 403 ); }
		$now = current_time( 'mysql', true );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_guest_entries SET status='rejected',decided_by=%d,decided_at=%s,updated_at=%s WHERE entry_id=%d AND status IN ('requested','approved')", get_current_user_id(), $now, $now, (int) $entry->entry_id ) );
		if ( ! $changed ) { return self::error( 'wpcap_guestlist_state', __( 'Der Eintrag kann in diesem Status nicht abgelehnt werden.', 'wp-pass-captain' ), 409 ); }
		$note = mb_substr( sanitize_text_field( (string) $note ), 0, 255 );
		if ( '' !== $note ) { $wpdb->update( "{$wpdb->prefix}wpcap_guest_entries", array( 'note' => $note ), array( 'entry_id' => (int) $entry->entry_id ) ); }
		Audit::record( 'guestlist_entry_rejected', 'event', (int) $list->event_id, array( 'list_id' => (int) $list->list_id, 'entry_id' => (int) $entry->entry_id ) );
		return self::get_entry( $entry_id );
	}

	public static function is_placeholder_email( $email ) {
		return self::PLACEHOLDER_DOMAIN === strtolower( substr( (string) $email, -strlen( self::PLACEHOLDER_DOMAIN ) ) );
	}

	/** pre_wp_mail: drop mails that only go to guest list placeholder addresses. */
	public static function suppress_placeholder_mail( $return, $atts = array() ) {
		$to = $atts['to'] ?? array();
		$to = is_array( $to ) ? $to : explode( ',', (string) $to );
		if ( $to && ! array_filter( $to, static function ( $r ) { return ! self::is_placeholder_email( trim( (string) $r ) ); } ) ) {
			return true;
		}
		return $return;
	}

	/**
	 * Issues the tickets of an approved entry as a 0 € comp order. Idempotent: a retry reuses the
	 * order (channel idempotency key per entry) and only completes missing steps.
	 *
	 * @return object|\WP_Error Entry row.
	 */
	public static function issue( $entry_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$entry = self::get_entry( $entry_id );
		$list = $entry ? self::get_list( $entry->list_id ) : null;
		if ( ! $entry || ! $list ) { return self::error( 'wpcap_guestlist_entry_missing', __( 'Eintrag nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $list->event_id ) ) { return self::forbidden(); }
		if ( 'issued' === $entry->status ) { return $entry; }
		if ( 'approved' === $entry->status ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_guest_entries SET status='issuing',updated_at=%s WHERE entry_id=%d AND status='approved'", current_time( 'mysql', true ), (int) $entry->entry_id ) );
			if ( ! $claimed ) { return self::issue( $entry_id ); }
		} elseif ( 'issuing' !== $entry->status ) {
			return self::error( 'wpcap_guestlist_state', __( 'Nur freigegebene Einträge können ausgestellt werden.', 'wp-pass-captain' ), 409 );
		}

		$email = '' !== (string) $entry->email ? (string) $entry->email : 'guestlist+' . substr( hash( 'sha256', 'guestlist|' . (int) $entry->entry_id . '|' . self::wp_salt_value() ), 0, 16 ) . self::PLACEHOLDER_DOMAIN;
		$type_id = (int) $entry->ticket_type_id;
		$attendees = array();
		for ( $i = 0; $i < (int) $entry->quantity; $i++ ) {
			// Companion tickets are neutral by default: whoever holds one does not learn the guest's name.
			// Filter wpcap_guestlist_companion_name (e.g. sprintf( 'Begleitung von %s', $entry->name )).
			$companion = (string) apply_filters( 'wpcap_guestlist_companion_name', __( 'Begleitung', 'wp-pass-captain' ), $entry, $i );
			$attendees[] = array( 'ticket_type_id' => $type_id, 'name' => 0 === $i ? $entry->name : $companion );
		}
		$internal = array(
			'external' => true,
			'payment_provider' => self::PROVIDER,
			'key_namespace' => self::NAMESPACE_KEY,
			'source' => 'guestlist',
			'hold_minutes' => 15,
			'skip_rate_limit' => true,
			'allow_door_sales' => true,
			'unit_prices' => array( $type_id => 0 ),
			'line_totals' => array( $type_id => 0 ),
			'line_taxes' => array( $type_id => 0 ),
			'guestlist_comp' => array( 'reason' => (string) $list->reason, 'list_id' => (int) $list->list_id, 'list' => (string) $list->name, 'entry_id' => (int) $entry->entry_id, 'approved_by' => (int) $entry->decided_by ),
		);
		$order = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => (int) $entry->quantity ) ), 'attendees' => $attendees, 'idempotency_key' => 'entry-' . (int) $entry->entry_id ), $internal );
		if ( is_wp_error( $order ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_guest_entries SET status='approved' WHERE entry_id=%d AND status='issuing' AND order_id IS NULL", (int) $entry->entry_id ) );
			return $order;
		}
		$order_id = (int) $order['order_id'];
		$existing = Orders::get( $order_id );
		if ( $existing && in_array( $existing->status, array( 'cancelled', 'refunded' ), true ) ) {
			return self::error( 'wpcap_guestlist_order_state', __( 'Die Freikarten-Bestellung dieses Eintrags wurde storniert.', 'wp-pass-captain' ), 409 );
		}
		$wpdb->update( "{$p}wpcap_guest_entries", array( 'order_id' => $order_id ), array( 'entry_id' => (int) $entry->entry_id ) );
		$confirmed = Orders::confirm_external_payment( $order_id, 'comp:guestlist:' . (int) $entry->entry_id );
		if ( is_wp_error( $confirmed ) ) { return $confirmed; }
		$issued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d", $order_id ) );
		if ( $issued < (int) $entry->quantity ) {
			return self::error( 'wpcap_guestlist_not_issued', __( 'Es wurden nicht alle Tickets ausgestellt. Bitte erneut versuchen.', 'wp-pass-captain' ), 409 );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_guest_entries SET status='issued',issued_at=%s,updated_at=%s WHERE entry_id=%d AND status='issuing'", $now, $now, (int) $entry->entry_id ) );
		Audit::record( 'guestlist_entry_issued', 'order', $order_id, array( 'list_id' => (int) $list->list_id, 'entry_id' => (int) $entry->entry_id, 'tickets' => (int) $entry->quantity, 'reason' => (string) $list->reason, 'mailed' => '' !== (string) $entry->email ) );
		return self::get_entry( $entry_id );
	}

	/** Site-specific salt for placeholder addresses (falls back to a constant outside WordPress). */
	private static function wp_salt_value() {
		return function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'wpcap-guestlist';
	}

	/**
	 * Sends the tickets of an issued entry again (codes are rotated). An optional new e-mail address
	 * replaces the stored one (e.g. for a will-call guest who wants the tickets by mail after all).
	 */
	public static function resend( $entry_id, $email = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$entry = self::get_entry( $entry_id );
		$list = $entry ? self::get_list( $entry->list_id ) : null;
		if ( ! $entry || ! $list ) { return self::error( 'wpcap_guestlist_entry_missing', __( 'Eintrag nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $list->event_id ) ) { return self::forbidden(); }
		if ( 'issued' !== $entry->status || ! $entry->order_id ) { return self::error( 'wpcap_guestlist_state', __( 'Nur ausgestellte Einträge können erneut gesendet werden.', 'wp-pass-captain' ), 409 ); }
		$email = sanitize_email( (string) $email );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) || self::is_placeholder_email( $email ) ) { return self::error( 'wpcap_guestlist_entry_email', __( 'Die E-Mail-Adresse ist ungültig.', 'wp-pass-captain' ) ); }
			$wpdb->update( "{$p}wpcap_guest_entries", array( 'email' => $email, 'updated_at' => current_time( 'mysql', true ) ), array( 'entry_id' => (int) $entry->entry_id ) );
			$wpdb->update( "{$p}wpcap_orders", array( 'customer_email' => $email ), array( 'order_id' => (int) $entry->order_id ) );
			$entry->email = $email;
		}
		if ( '' === (string) $entry->email ) { return self::error( 'wpcap_guestlist_no_email', __( 'Für diesen Gast ist keine E-Mail-Adresse hinterlegt. Die Tickets liegen an der Abendkasse (Abholliste).', 'wp-pass-captain' ) ); }
		$sent = Orders::resend_tickets( (int) $entry->order_id );
		if ( is_wp_error( $sent ) ) { return $sent; }
		Audit::record( 'guestlist_entry_resent', 'order', (int) $entry->order_id, array( 'entry_id' => (int) $entry->entry_id ) );
		return self::get_entry( $entry_id );
	}

	/**
	 * Cancels an entry. Issued entries: the comp order is cancelled (tickets revoked, capacity freed);
	 * not possible once a ticket of the entry was checked in.
	 */
	public static function cancel( $entry_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$entry = self::get_entry( $entry_id );
		$list = $entry ? self::get_list( $entry->list_id ) : null;
		if ( ! $entry || ! $list ) { return self::error( 'wpcap_guestlist_entry_missing', __( 'Eintrag nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_manage( $list->event_id ) ) { return self::forbidden(); }
		if ( 'cancelled' === $entry->status ) { return $entry; }
		if ( 'rejected' === $entry->status ) { return self::error( 'wpcap_guestlist_state', __( 'Abgelehnte Einträge können nicht storniert werden.', 'wp-pass-captain' ), 409 ); }
		if ( $entry->order_id ) {
			$checked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t WHERE t.order_id=%d AND (t.status='checked_in' OR EXISTS (SELECT 1 FROM {$p}wpcap_checkins c WHERE c.ticket_id=t.ticket_id AND c.result='checked_in'))", (int) $entry->order_id ) );
			if ( $checked ) { return self::error( 'wpcap_guestlist_checked_in', __( 'Mindestens ein Ticket dieses Eintrags wurde bereits eingecheckt; der Eintrag kann nicht mehr storniert werden.', 'wp-pass-captain' ), 409 ); }
		}
		$now = current_time( 'mysql', true );
		// Tickets first: if that fails the entry stays active and the cancellation can be repeated.
		if ( $entry->order_id ) {
			$cancelled = Orders::cancel_order( (int) $entry->order_id, 'guestlist_cancelled' );
			if ( is_wp_error( $cancelled ) ) { return $cancelled; }
		}
		$wpdb->update( "{$p}wpcap_guest_entries", array( 'status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now ), array( 'entry_id' => (int) $entry->entry_id ) );
		Audit::record( 'guestlist_entry_cancelled', 'event', (int) $list->event_id, array( 'list_id' => (int) $list->list_id, 'entry_id' => (int) $entry->entry_id, 'order_id' => (int) $entry->order_id, 'reason' => sanitize_text_field( (string) $reason ) ) );
		return self::get_entry( $entry_id );
	}

	/** wpcap_order_cancelled / wpcap_order_refunded: an entry whose comp order ended elsewhere is cancelled too. */
	public static function on_order_ended( $order_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_guest_entries SET status='cancelled',cancelled_at=%s,updated_at=%s WHERE order_id=%d AND status IN ('issuing','issued')", $now, $now, absint( $order_id ) ) );
	}

	/**
	 * wpcap_order_creating (inside the order transaction): stores the comp reason in the order's
	 * price snapshot, so it travels with the order (exports, audits, support).
	 */
	public static function annotate_order( $order_id, $selected = array(), $payload = array(), $internal = array() ) {
		if ( empty( $internal['guestlist_comp'] ) || ! is_array( $internal['guestlist_comp'] ) ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_orders';
		$snapshot = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT price_snapshot FROM $table WHERE order_id=%d", absint( $order_id ) ) ), true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$comp = $internal['guestlist_comp'];
		$snapshot['comp'] = array(
			'reason' => sanitize_key( (string) ( $comp['reason'] ?? 'other' ) ),
			'source' => 'guestlist',
			'list_id' => absint( $comp['list_id'] ?? 0 ),
			'list' => mb_substr( sanitize_text_field( (string) ( $comp['list'] ?? '' ) ), 0, 190 ),
			'entry_id' => absint( $comp['entry_id'] ?? 0 ),
			'approved_by' => absint( $comp['approved_by'] ?? 0 ),
			'vat' => 0,
		);
		if ( false === $wpdb->update( $table, array( 'price_snapshot' => wp_json_encode( $snapshot ) ), array( 'order_id' => absint( $order_id ) ) ) ) {
			throw new \RuntimeException( 'db_busy' );
		}
	}

	/* ------------------------------------------------------------------ will-call and arrival */

	/** Short code printed on the will-call list (first 8 characters of the ticket's public id). */
	public static function short_code( $public_id ) {
		return strtoupper( substr( (string) $public_id, 0, 8 ) );
	}

	/** Tickets of an entry's order with status and short code. */
	public static function entry_tickets( $entry ) {
		global $wpdb;
		if ( ! $entry || ! $entry->order_id ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,public_id,status FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $entry->order_id ) );
		foreach ( $rows as $row ) { $row->short_code = self::short_code( $row->public_id ); }
		return $rows;
	}

	private static function decorate( array $entries ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_ids = array_filter( array_map( static function ( $e ) { return (int) $e->order_id; }, $entries ) );
		$tickets = array();
		if ( $order_ids ) {
			foreach ( $wpdb->get_results( "SELECT ticket_id,order_id,public_id,status,(SELECT COUNT(*) FROM {$p}wpcap_checkins c WHERE c.ticket_id=t.ticket_id AND c.result='checked_in') AS entries FROM {$p}wpcap_tickets t WHERE order_id IN (" . implode( ',', array_map( 'absint', $order_ids ) ) . ') ORDER BY ticket_id' ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
				$tickets[ (int) $t->order_id ][] = $t;
			}
		}
		$out = array();
		foreach ( $entries as $e ) {
			$list = $tickets[ (int) $e->order_id ] ?? array();
			$e->codes = array_map( static function ( $t ) { return self::short_code( $t->public_id ); }, $list );
			$e->tickets_valid = count( array_filter( $list, static function ( $t ) { return 'valid' === $t->status; } ) );
			$e->tickets_checked_in = count( array_filter( $list, static function ( $t ) { return 'checked_in' === $t->status || (int) $t->entries > 0; } ) );
			$e->plus_ones = max( 0, (int) $e->quantity - 1 );
			$out[] = $e;
		}
		return $out;
	}

	/**
	 * Will-call list of an occurrence: issued (and approved, not yet issued) entries, alphabetical.
	 *
	 * @return array|\WP_Error Entry rows with list_name, reason, ticket_name, codes, plus_ones, tickets_valid, tickets_checked_in.
	 */
	public static function will_call( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id ) { return self::error( 'wpcap_guestlist_occurrence', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_view_will_call( $event_id ) ) { return self::forbidden(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT g.*,l.name AS list_name,l.reason,tt.name AS ticket_name FROM {$p}wpcap_guest_entries g INNER JOIN {$p}wpcap_guest_lists l ON l.list_id=g.list_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=g.ticket_type_id WHERE g.occurrence_id=%d AND g.status IN ('approved','issuing','issued') ORDER BY g.name,g.company,g.entry_id", absint( $occurrence_id ) ) );
		$rows = self::decorate( $rows );
		usort( $rows, static function ( $a, $b ) { return array( self::sort_key( $a->name ), (int) $a->entry_id ) <=> array( self::sort_key( $b->name ), (int) $b->entry_id ); } );
		return $rows;
	}

	/** Case- and accent-insensitive sort key ("Ährlich" next to "Ahrens"). */
	private static function sort_key( $name ) {
		$name = mb_strtolower( (string) $name );
		return strtr( $name, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a' ) );
	}

	/**
	 * Name lookup for check-in staff: matches name, company or a ticket short code of issued entries.
	 *
	 * @return array|\WP_Error Max. 25 entries (no e-mail addresses).
	 */
	public static function search( $occurrence_id, $query ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id ) { return self::error( 'wpcap_guestlist_occurrence', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_checkin( $event_id ) ) { return self::forbidden(); }
		$query = trim( sanitize_text_field( (string) $query ) );
		if ( mb_strlen( $query ) < 2 ) { return array(); }
		$like = '%' . addcslashes( $query, '_%\\' ) . '%';
		$code = preg_match( '/^[a-f0-9]{4,32}$/i', $query ) ? strtolower( $query ) . '%' : '';
		$code_sql = '' !== $code ? $wpdb->prepare( " OR EXISTS (SELECT 1 FROM {$p}wpcap_tickets t WHERE t.order_id=g.order_id AND t.public_id LIKE %s)", $code ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT g.*,l.name AS list_name,l.reason,tt.name AS ticket_name FROM {$p}wpcap_guest_entries g INNER JOIN {$p}wpcap_guest_lists l ON l.list_id=g.list_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=g.ticket_type_id WHERE g.occurrence_id=%d AND g.status='issued' AND (g.name LIKE %s OR g.company LIKE %s{$code_sql}) ORDER BY g.name,g.entry_id LIMIT 25", absint( $occurrence_id ), $like, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared fragment.
		return array_map( static function ( $e ) {
			return array(
				'entry_id' => (int) $e->entry_id,
				'name' => (string) $e->name,
				'company' => (string) $e->company,
				'list' => (string) $e->list_name,
				'ticket' => (string) $e->ticket_name,
				'quantity' => (int) $e->quantity,
				'plus_ones' => (int) $e->plus_ones,
				'codes' => $e->codes,
				'tickets_valid' => (int) $e->tickets_valid,
				'tickets_checked_in' => (int) $e->tickets_checked_in,
				'arrived' => null !== $e->arrived_at,
			);
		}, self::decorate( $rows ) );
	}

	/**
	 * Marks an entry as arrived: checks in up to $count still valid tickets (0 = all) through
	 * Checkin::perform() (event scope, admission policies and the check-in log apply). Each ticket
	 * gets a new code for this (the mailed code stops working; it is admitted now anyway).
	 *
	 * @return array|\WP_Error ['checked_in' => int, 'remaining' => int, 'errors' => string[]]
	 */
	public static function arrive( $entry_id, $count = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$entry = self::get_entry( $entry_id );
		if ( ! $entry ) { return self::error( 'wpcap_guestlist_entry_missing', __( 'Eintrag nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_checkin( $entry->event_id ) ) { return self::forbidden(); }
		if ( 'issued' !== $entry->status || ! $entry->order_id ) { return self::error( 'wpcap_guestlist_state', __( 'Für diesen Eintrag sind keine Tickets ausgestellt.', 'wp-pass-captain' ), 409 ); }
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid' ORDER BY ticket_id", (int) $entry->order_id ) ) );
		$count = absint( $count );
		if ( $count ) { $ids = array_slice( $ids, 0, $count ); }
		if ( ! $ids ) { return self::error( 'wpcap_guestlist_all_checked_in', __( 'Alle Tickets dieses Eintrags sind bereits eingecheckt.', 'wp-pass-captain' ), 409 ); }
		$checked = 0;
		$errors = array();
		foreach ( $ids as $ticket_id ) {
			$token = bin2hex( random_bytes( 32 ) );
			if ( ! $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET token_hash=%s WHERE ticket_id=%d AND status='valid'", hash( 'sha256', $token ), $ticket_id ) ) ) { continue; }
			do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, 'guestlist' );
			$result = Checkin::perform( $token, array( 'source' => 'guestlist', 'occurrence_id' => (int) $entry->occurrence_id ) );
			if ( is_wp_error( $result ) ) { $errors[] = $result->get_error_message(); } else { ++$checked; }
		}
		if ( $checked ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_guest_entries SET arrived_at=COALESCE(arrived_at,%s),updated_at=%s WHERE entry_id=%d", current_time( 'mysql', true ), current_time( 'mysql', true ), (int) $entry->entry_id ) );
			Audit::record( 'guestlist_entry_arrived', 'event', (int) $entry->event_id, array( 'entry_id' => (int) $entry->entry_id, 'checked_in' => $checked ) );
		}
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'", (int) $entry->order_id ) );
		if ( ! $checked && $errors ) { return self::error( 'wpcap_guestlist_checkin_failed', $errors[0], 409 ); }
		return array( 'checked_in' => $checked, 'remaining' => $remaining, 'errors' => array_values( array_unique( $errors ) ) );
	}

	/* ------------------------------------------------------------------ export */

	/**
	 * CSV rows of an event's guest lists (optionally one list).
	 *
	 * @return array|\WP_Error
	 */
	public static function export_rows( $event_id, $list_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! self::can_manage( $event_id ) ) { return self::forbidden(); }
		$where = $list_id ? $wpdb->prepare( ' AND g.list_id=%d', absint( $list_id ) ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT g.*,l.name AS list_name,l.reason,tt.name AS ticket_name,o.starts_at,o.timezone FROM {$p}wpcap_guest_entries g INNER JOIN {$p}wpcap_guest_lists l ON l.list_id=g.list_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=g.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=g.occurrence_id WHERE g.event_id=%d{$where} ORDER BY l.name,g.name,g.entry_id", absint( $event_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared fragment.
		$reasons = self::reasons();
		$statuses = self::status_labels();
		$out = array( array( __( 'Liste', 'wp-pass-captain' ), __( 'Grund', 'wp-pass-captain' ), __( 'Termin', 'wp-pass-captain' ), __( 'Name', 'wp-pass-captain' ), __( 'E-Mail', 'wp-pass-captain' ), __( 'Firma', 'wp-pass-captain' ), __( 'Tickets', 'wp-pass-captain' ), __( 'Begleitpersonen', 'wp-pass-captain' ), __( 'Ticketart', 'wp-pass-captain' ), __( 'Status', 'wp-pass-captain' ), __( 'Ticketcodes', 'wp-pass-captain' ), __( 'Eingecheckt', 'wp-pass-captain' ), __( 'Notiz', 'wp-pass-captain' ) ) );
		foreach ( self::decorate( $rows ) as $e ) {
			$out[] = array( $e->list_name, $reasons[ $e->reason ] ?? $e->reason, Scope::local( $e->starts_at, $e->timezone ), $e->name, self::is_placeholder_email( $e->email ) ? '' : $e->email, $e->company, (int) $e->quantity, (int) $e->plus_ones, (string) $e->ticket_name, $statuses[ $e->status ] ?? $e->status, implode( ' ', $e->codes ), (int) $e->tickets_checked_in, $e->note );
		}
		return $out;
	}
}
