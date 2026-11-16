<?php
/**
 * Membership and season-pass issue/approve/redeem operations.
 *
 * Issuing (A481): a pass is free admission, i.e. a complimentary ticket of its own. A request by staff
 * with `wpcap_manage_orders` creates a pass in status `pending` (no code exists yet); a second person
 * with `wpcap_approve_comps` and event scope approves it (four-eyes principle: never the requester),
 * only then the code is created and mailed. Site administrators (`manage_options`) issue directly.
 * Lists and actions: admin page "Mitgliedspässe" (Admin\MembershipsPage) and REST.
 *
 * Redeeming (A480, A482): the pass row is locked FOR UPDATE as the first statement of the
 * transaction, so the usage counts read afterwards see every redemption committed before (a plain
 * read before the lock would fix the snapshot and miss concurrent redemptions). Each use is bound to
 * the occurrence of the scanned ticket type (`wpcap_pass_uses.occurrence_id`, schema 3.10.18): the
 * occurrence must be scheduled and take place today (event time zone, day cutoff), the doors-open
 * time and the default entry window apply, a full occurrence admits no further member, and the
 * optional quota `wpcap_member_pass_occurrence_quota` limits member entries per occurrence. Member
 * entries appear in the admission statistics of the reports (`member_entries`).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Memberships {
	/** Capability that approves passes (same right as complimentary tickets). */
	const APPROVE_CAP = 'wpcap_approve_comps';
	/** Capability that requests passes. */
	const REQUEST_CAP = 'wpcap_manage_orders';

	/** Whether the columns of schema 3.10.18 exist (deferred upgrades). */
	public static function schema_ready() {
		return Log::has_column( 'wpcap_pass_uses', 'occurrence_id' ) && Log::has_column( 'wpcap_member_passes', 'requested_by' );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function get( $pass_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_member_passes WHERE pass_id=%d", absint( $pass_id ) ) );
	}

	public static function rules( $pass_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d ORDER BY rule_id", absint( $pass_id ) ) );
	}

	/**
	 * Whether the current user holds $cap for every event the rules name (rules without event =
	 * all events: administrators only).
	 *
	 * @param int[] $event_ids Event ids of the rules (0 = all events).
	 */
	public static function in_scope( array $event_ids, $cap ) {
		if ( ! $event_ids ) { return false; }
		foreach ( $event_ids as $event_id ) {
			if ( (int) $event_id ? ! Access::can_access_event( (int) $event_id, $cap ) : ! current_user_can( 'manage_options' ) ) { return false; }
		}
		return true;
	}

	/** Event ids of a pass's rules. */
	public static function event_ids( $pass_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d", absint( $pass_id ) ) ) );
	}

	/** Whether the current user may approve or reject the pass (approve cap, scope, not the requester). */
	public static function can_decide( $pass ) {
		if ( ! $pass || ! current_user_can( self::APPROVE_CAP ) || ! self::in_scope( self::event_ids( $pass->pass_id ), self::APPROVE_CAP ) ) { return false; }
		return ! self::self_approval( $pass );
	}

	/** The current user requested this pass and is no administrator: four-eyes principle forbids the approval. */
	public static function self_approval( $pass ) {
		return ! current_user_can( 'manage_options' ) && (int) ( $pass->requested_by ?? 0 ) && (int) $pass->requested_by === get_current_user_id();
	}

	public static function revoke( $pass_id ) {
		global $wpdb;
		$pass_id = absint( $pass_id );
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_member_passes SET status='revoked',token_hash=%s WHERE pass_id=%d AND status IN ('active','pending')", hash( 'sha256', 'revoked|' . bin2hex( random_bytes( 32 ) ) ), $pass_id ) );
		if ( false === $result ) { return new \WP_Error( 'wpcap_pass_revoke_failed', __( 'Pass konnte nicht widerrufen werden.', 'pass-captain' ) ); }
		Audit::record( 'member_pass_revoked', 'member_pass', $pass_id );
		return true;
	}

	/**
	 * Issues (administrators) or requests (everyone else) a pass.
	 *
	 * @return array|\WP_Error pass_id, status (active|pending), token (only when active).
	 */
	public static function issue( $email, $valid_from, $valid_until, array $rules ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! $rules || strtotime( $valid_until . ' UTC' ) <= strtotime( $valid_from . ' UTC' ) ) { return new \WP_Error( 'wpcap_pass_invalid', __( 'Passdaten ungültig.', 'pass-captain' ) ); }
		$ready = self::schema_ready();
		// Before the 3.10.18 upgrade there is no approval state: only administrators may issue.
		$direct = current_user_can( 'manage_options' ) || ( ! $ready && current_user_can( self::APPROVE_CAP ) );
		if ( ! $direct && ! $ready ) { return self::error( 'wpcap_pass_forbidden', __( 'Mitgliedspässe können erst nach dem Datenbank-Update beantragt werden.', 'pass-captain' ), 503 ); }
		$token = bin2hex( random_bytes( 32 ) );
		$passes = $wpdb->prefix . 'wpcap_member_passes';
		$now = current_time( 'mysql', true );
		$row = array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'member_email' => $email, 'token_hash' => hash( 'sha256', $direct ? $token : 'pending|' . bin2hex( random_bytes( 32 ) ) ), 'valid_from' => sanitize_text_field( $valid_from ), 'valid_until' => sanitize_text_field( $valid_until ), 'status' => $direct ? 'active' : 'pending', 'created_at' => $now );
		if ( $ready ) {
			$row['requested_by'] = get_current_user_id();
			if ( $direct ) { $row['approved_by'] = get_current_user_id(); $row['approved_at'] = $now; }
		}
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( $passes, $row );
		$pass_id = (int) $wpdb->insert_id;
		if ( ! $pass_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_save_failed', __( 'Pass konnte nicht angelegt werden.', 'pass-captain' ) ); }
		foreach ( $rules as $rule ) {
			$wpdb->insert( $wpdb->prefix . 'wpcap_pass_rules', array( 'pass_id' => $pass_id, 'event_id' => absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => absint( $rule['ticket_type_id'] ?? 0 ), 'uses_total' => absint( $rule['uses_total'] ?? 0 ), 'uses_per_day' => max( 1, absint( $rule['uses_per_day'] ?? 1 ) ) ), array( '%d', '%d', '%d', '%d', '%d' ) );
			if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_rule_failed', __( 'Passregeln konnten nicht gespeichert werden.', 'pass-captain' ) ); }
		}
		$wpdb->query( 'COMMIT' );
		if ( ! $direct ) {
			Audit::record( 'member_pass_requested', 'member_pass', $pass_id, array( 'rule_count' => count( $rules ) ) );
			return array( 'pass_id' => $pass_id, 'status' => 'pending' );
		}
		Mail\Mailer::send( 'member_pass', $email, array( 'pass_code' => $token, 'valid_from' => (string) $valid_from, 'valid_until' => (string) $valid_until ) );
		Audit::record( 'member_pass_issued', 'member_pass', $pass_id, array( 'rule_count' => count( $rules ) ) );
		return array( 'pass_id' => $pass_id, 'status' => 'active', 'token' => $token );
	}

	/**
	 * Approves a requested pass: creates and mails the code. Needs wpcap_approve_comps and event scope
	 * for every rule; the requester can never approve their own request (administrators excepted).
	 *
	 * @return array|\WP_Error pass_id, status, token.
	 */
	public static function approve( $pass_id ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return self::error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( self::self_approval( $pass ) ) { return self::error( 'wpcap_pass_four_eyes', __( 'Einen selbst beantragten Mitgliedspass muss eine andere Person freigeben.', 'pass-captain' ), 403 ); }
		if ( ! self::can_decide( $pass ) ) { return self::error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ), 403 ); }
		if ( 'pending' !== $pass->status ) { return self::error( 'wpcap_pass_state', __( 'Nur beantragte Pässe können freigegeben werden.', 'pass-captain' ), 409 ); }
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_member_passes SET status='active',token_hash=%s,approved_by=%d,approved_at=%s WHERE pass_id=%d AND status='pending'", hash( 'sha256', $token ), get_current_user_id(), current_time( 'mysql', true ), (int) $pass->pass_id ) );
		if ( 1 !== (int) $updated ) { return self::error( 'wpcap_pass_state', __( 'Nur beantragte Pässe können freigegeben werden.', 'pass-captain' ), 409 ); }
		Mail\Mailer::send( 'member_pass', $pass->member_email, array( 'pass_code' => $token, 'valid_from' => (string) $pass->valid_from, 'valid_until' => (string) $pass->valid_until ) );
		Audit::record( 'member_pass_approved', 'member_pass', (int) $pass->pass_id, array( 'requested_by' => (int) $pass->requested_by ) );
		return array( 'pass_id' => (int) $pass->pass_id, 'status' => 'active', 'token' => $token );
	}

	/** Rejects a requested pass (same rights as approve()). */
	public static function reject( $pass_id ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return self::error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! current_user_can( self::APPROVE_CAP ) || ! self::in_scope( self::event_ids( $pass->pass_id ), self::APPROVE_CAP ) ) { return self::error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ), 403 ); }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_member_passes SET status='rejected',approved_by=%d,approved_at=%s WHERE pass_id=%d AND status='pending'", get_current_user_id(), current_time( 'mysql', true ), (int) $pass->pass_id ) );
		if ( 1 !== (int) $updated ) { return self::error( 'wpcap_pass_state', __( 'Nur beantragte Pässe können abgelehnt werden.', 'pass-captain' ), 409 ); }
		Audit::record( 'member_pass_rejected', 'member_pass', (int) $pass->pass_id );
		return true;
	}

	/**
	 * Passes for the admin list, newest first; only passes whose rules are all in the user's scope.
	 *
	 * @param string $status '' = all.
	 * @return object[] Rows with rules, uses and requester/approver.
	 */
	public static function list_passes( $status = '', $limit = 200 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '' !== $status ? $wpdb->prepare( ' WHERE m.status=%s', sanitize_key( $status ) ) : '';
		$rows = (array) $wpdb->get_results( "SELECT m.*,(SELECT COUNT(*) FROM {$p}wpcap_pass_uses u WHERE u.pass_id=m.pass_id) AS uses FROM {$p}wpcap_member_passes m{$where} ORDER BY m.pass_id DESC LIMIT " . max( 1, min( 1000, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared where clause, int limit.
		$out = array();
		foreach ( $rows as $row ) {
			$events = self::event_ids( $row->pass_id );
			if ( ! self::in_scope( $events, self::REQUEST_CAP ) && ! self::in_scope( $events, self::APPROVE_CAP ) ) { continue; }
			$row->rules = self::rules( $row->pass_id );
			$out[] = $row;
		}
		return $out;
	}

	/** Ticket type with its occurrence (for redeem). */
	private static function scanned_type( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.status AS type_status,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.status AS occurrence_status,o.capacity,e.status AS event_status FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d", $ticket_type_id ) );
	}

	/** Whether $ts lies on a local day of the occurrence (start day … end day, day cutoff applied). */
	private static function on_occurrence_day( $type, $ts ) {
		$context = array( 'occurrence_id' => (int) $type->occurrence_id, 'event_id' => (int) $type->event_id );
		$day = Admission\Engine::service_date( $ts, $type->timezone, $context );
		$tz = Admission\Engine::timezone( $type->timezone );
		$first = ( new \DateTimeImmutable( $type->starts_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$end = ! empty( $type->ends_at ) && '0000-00-00 00:00:00' !== $type->ends_at ? $type->ends_at : $type->starts_at;
		$last = ( new \DateTimeImmutable( $end, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		return $day >= $first && $day <= max( $first, $last );
	}

	public static function redeem( $token, $ticket_type_id, $idempotency_key ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		$ticket_type_id = absint( $ticket_type_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( 64 !== strlen( $token ) || ! $ticket_type_id || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_pass_redeem_invalid', __( 'Passcode oder Ticketkategorie ungültig.', 'pass-captain' ) ); }
		$key = hash( 'sha256', $token . '|' . $idempotency_key );
		$uses = $wpdb->prefix . 'wpcap_pass_uses';
		$type = self::scanned_type( $ticket_type_id );
		if ( ! $type ) { return self::error( 'wpcap_pass_scope', __( 'Dieser Pass gilt nicht für dieses Event oder diese Ticketkategorie.', 'pass-captain' ), 403 ); }
		$bound = self::schema_ready();
		$wpdb->query( 'START TRANSACTION' );
		// A480: the lock comes first. Every read below runs after it and sees all redemptions of this
		// pass committed before (a plain read here would fix the transaction's snapshot too early).
		$pass = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_member_passes WHERE token_hash=%s FOR UPDATE", hash( 'sha256', $token ) ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT pass_use_id,pass_id FROM $uses WHERE idempotency_key=%s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id ); }
		$now = current_time( 'mysql', true );
		if ( ! $pass || 'active' !== $pass->status || $pass->valid_from > $now || $pass->valid_until < $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_inactive', __( 'Pass ist ungültig oder außerhalb der Laufzeit.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT r.* FROM {$wpdb->prefix}wpcap_pass_rules r WHERE r.pass_id=%d AND (r.ticket_type_id=%d OR r.ticket_type_id=0) AND (r.event_id=%d OR r.event_id=0) ORDER BY r.ticket_type_id DESC,r.event_id DESC LIMIT 1", $pass->pass_id, $ticket_type_id, (int) $type->event_id ) );
		if ( ! $rule ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_scope', __( 'Dieser Pass gilt nicht für dieses Event oder diese Ticketkategorie.', 'pass-captain' ), array( 'status' => 403 ) ); }
		// A482: the entry belongs to a real, scheduled occurrence that takes place today.
		$ts = Checkin::now();
		if ( 'scheduled' !== $type->occurrence_status || 'cancelled' === $type->event_status || in_array( (string) $type->type_status, array( 'archived', 'deleted' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pass_occurrence', __( 'Dieser Termin findet nicht statt; der Pass kann dafür nicht eingelöst werden.', 'pass-captain' ), 409 );
		}
		$found = array( 'occurrence_id' => (int) $type->occurrence_id, 'event_id' => (int) $type->event_id, 'starts_at' => $type->starts_at, 'ends_at' => $type->ends_at, 'timezone' => $type->timezone );
		if ( ! self::on_occurrence_day( $type, $ts ) || '' !== Admission\Engine::default_window_block( $found, $ts ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pass_not_today', __( 'Der Pass kann nur am Tag des Termins und innerhalb der Einlasszeit eingelöst werden.', 'pass-captain' ), 409 );
		}
		$day = Admission\Engine::service_date( $ts, $type->timezone, $found );
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d AND service_date=%s", $pass->pass_id, $rule->rule_id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d", $pass->pass_id, $rule->rule_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $rule->uses_per_day > 0 && $today >= (int) $rule->uses_per_day || (int) $rule->uses_total > 0 && $total >= (int) $rule->uses_total ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_quota', __( 'Das Nutzungskontingent dieses Passes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$row = array( 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'service_date' => $day, 'user_id' => get_current_user_id(), 'idempotency_key' => $key, 'created_at' => $now );
		if ( $bound ) {
			// Member entries of all passes for this occurrence are serialised on the occurrence row.
			$wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", (int) $type->occurrence_id ) );
			$members = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE occurrence_id=%d", (int) $type->occurrence_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			/**
			 * Optional quota of member-pass entries per occurrence (0 = no own quota). Members also never
			 * exceed the remaining capacity of the scanned ticket type / occurrence.
			 */
			$quota = max( 0, (int) apply_filters( 'wpcap_member_pass_occurrence_quota', 0, (int) $type->occurrence_id, (int) $ticket_type_id ) );
			$remaining = Orders::availability( $ticket_type_id );
			if ( ( $quota && $members >= $quota ) || ( null !== $remaining && (int) $remaining - $members <= 0 ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wpcap_pass_occurrence_full', __( 'Für diesen Termin sind keine Plätze für Mitgliedspässe mehr frei.', 'pass-captain' ), 409 );
			}
			$row['occurrence_id'] = (int) $type->occurrence_id;
			$row['ticket_type_id'] = $ticket_type_id;
		}
		$wpdb->insert( $uses, $row );
		$use_id = (int) $wpdb->insert_id;
		if ( ! $use_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_redeem_failed', __( 'Pass konnte nicht eingelöst werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'member_pass_redeemed', 'member_pass', $pass->pass_id, array( 'ticket_type_id' => $ticket_type_id, 'occurrence_id' => (int) $type->occurrence_id, 'pass_use_id' => $use_id ) );
		/** A member pass was redeemed for an occurrence (after COMMIT). */
		do_action( 'wpcap_member_pass_redeemed', (int) $pass->pass_id, $use_id, (int) $type->occurrence_id, $ticket_type_id );
		return array( 'status' => 'redeemed', 'pass_id' => (int) $pass->pass_id, 'occurrence_id' => (int) $type->occurrence_id );
	}

	/** Member entries per occurrence (admission statistics), occurrence id => count. */
	public static function entries_by_occurrence( array $occurrence_ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $occurrence_ids ) );
		if ( ! $ids || ! self::schema_ready() ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT occurrence_id,COUNT(*) AS n FROM {$wpdb->prefix}wpcap_pass_uses WHERE occurrence_id IN (" . implode( ',', $ids ) . ') GROUP BY occurrence_id' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints.
			$out[ (int) $row->occurrence_id ] = (int) $row->n;
		}
		return $out;
	}
}
