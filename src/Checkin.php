<?php
/**
 * Ticket validation, check-in and check-out service shared by the web scanner, the mobile app API
 * and offline sync. Check-ins are atomic (row lock on the ticket) and optionally idempotent per
 * user + client key, so a retried request after a network error never counts twice.
 *
 * Admission policies per ticket type (single, daily, multi-day, multiple entries, re-entry), entry
 * windows, areas and presence live in PassCaptain\Admission. Ticket types without a policy keep the
 * original behaviour: single entry, plus the per-occurrence day rules (wpcap_occurrence_days).
 */
namespace PassCaptain;

use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Conflicts;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Checkin {
	/** Results of an offline scan that count as double admission (API flag `conflict`). */
	const REJECTED_ADMISSION = array( 'already_checked_in', 'daily_limit_reached', 'entry_limit_reached', 'already_inside' );

	/** Extracts the 64-hex ticket code from a scanned value (raw code or ticket URL). */
	public static function normalize_code( $value ) {
		return is_scalar( $value ) && preg_match( '/[a-f0-9]{64}/i', (string) $value, $m ) ? strtolower( $m[0] ) : '';
	}

	/** Current time for admission decisions (filterable for tests and simulations). */
	public static function now() {
		return (int) apply_filters( 'wpcap_checkin_now', time() );
	}

	/**
	 * Time a scan is evaluated at: the device time (scanned_at) for offline uploads, but only within
	 * the configured maximum age (Admission setting offline_max_age_hours, default 12 h); older or
	 * missing times are evaluated at "now".
	 */
	private static function eval_time( array $context ) {
		$now = self::now();
		if ( 'offline' === ( $context['source'] ?? '' ) && ! empty( $context['scanned_at'] ) ) {
			$ts = strtotime( $context['scanned_at'] . ' UTC' );
			$max_age = Policies::offline_max_age_seconds();
			// Uploads of the web scanner (visual check without package) get a shorter maximum age.
			if ( isset( $context['max_age'] ) ) { $max_age = min( $max_age, max( 0, (int) $context['max_age'] ) ); }
			if ( $ts && $ts <= $now + 300 && $ts >= $now - $max_age ) { return min( $ts, $now ); }
		}
		return $now;
	}

	/** Maximum age (seconds) of the device time of web scanner offline uploads (audit R2, Experte #3). */
	const WEB_UPLOAD_MAX_AGE = HOUR_IN_SECONDS;

	/** Internal error data that must not leave the server (ids used for conflict recording). */
	const INTERNAL_KEYS = array( 'ticket_id', 'event_id', 'occurrence_id', 'checkin_id' );

	/** Removes internal ids from an error before it is returned to clients. */
	public static function public_error( \WP_Error $error ) {
		$data = (array) $error->get_error_data();
		foreach ( self::INTERNAL_KEYS as $k ) { unset( $data[ $k ] ); }
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	private static function publish( $result ) {
		return is_wp_error( $result ) ? self::public_error( $result ) : $result;
	}

	/**
	 * Looks a ticket up by code and checks admission preconditions (not the ticket status).
	 *
	 * @param string   $code
	 * @param int|null $at     Evaluation time (UTC timestamp); default now.
	 * @param bool     $strict false skips the cancellation/plausibility checks (used for check-out).
	 * @return array|\WP_Error
	 */
	public static function lookup( $code, $at = null, $strict = true ) {
		// Signed QR payloads (Security\SignedQr): signature and key first, then the ticket by public id.
		$claims = Security\SignedQr::is_signed( $code ) ? Security\SignedQr::verify( $code ) : null;
		if ( is_wp_error( $claims ) ) { return $claims; }
		$code = $claims ? '' : self::normalize_code( $code );
		if ( ! $claims && '' === $code ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$column = $claims ? 't.public_id' : 't.token_hash';
		$sql = "SELECT t.ticket_id,t.public_id,t.token_hash,t.status,t.ticket_type_id,t.attendee_email,ta.attendee_name,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.status AS occurrence_status,e.status AS event_status,e.name AS event_name FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE {$column}=%s LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $claims ? (string) $claims['t'] : hash( 'sha256', $code ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table prefix and column name from literals.
		// A failed query is not an unknown ticket: offline uploads would drop the admission for good.
		if ( ! $row && '' !== (string) $wpdb->last_error ) { return self::retry_error(); }
		if ( ! $row ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		if ( $claims && ! Security\SignedQr::matches( $claims, (string) $row->token_hash, (int) $row->occurrence_id ) ) {
			// The ticket was reissued (new code) or moved: QR codes of the previous code are void.
			return new \WP_Error( 'wpcap_qr_superseded', __( 'Dieser QR-Code wurde ersetzt; das Ticket wurde neu ausgestellt.', 'pass-captain' ), array( 'status' => 409, 'result' => 'revoked', 'ticket_status' => 'revoked', 'ticket_id' => (int) $row->ticket_id, 'event_id' => (int) $row->event_id, 'occurrence_id' => (int) $row->occurrence_id ) );
		}
		$found = array( 'ticket_id' => (int) $row->ticket_id, 'public_id' => $row->public_id, 'event_id' => (int) $row->event_id, 'occurrence_id' => (int) $row->occurrence_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'status' => $row->status, 'event_name' => $row->event_name, 'ticket_name' => $row->ticket_name, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'timezone' => $row->timezone, 'attendee_name' => (string) $row->attendee_name );
		if ( ! $strict ) { return $found; }
		$internal = array( 'event_id' => (int) $row->event_id, 'ticket_id' => (int) $row->ticket_id, 'occurrence_id' => (int) $row->occurrence_id );
		if ( 'cancelled' === $row->occurrence_status || 'cancelled' === $row->event_status ) { return new \WP_Error( 'wpcap_ticket_cancelled_event', __( 'Dieser Termin wurde abgesagt; das Ticket berechtigt nicht zum Einlass.', 'pass-captain' ), array_merge( array( 'status' => 409, 'result' => 'cancelled_event' ), $internal ) ); }
		$at = null === $at ? self::now() : (int) $at;
		$start = strtotime( $row->starts_at . ' UTC' );
		$last = Policies::last_valid_ts( (int) $row->ticket_type_id, $found );
		if ( $last < $at - DAY_IN_SECONDS || $start > $at + 365 * DAY_IN_SECONDS ) { return new \WP_Error( 'wpcap_ticket_out_of_window', __( 'Das Ticket liegt außerhalb des Einlasszeitraums.', 'pass-captain' ), array_merge( array( 'status' => 409, 'result' => 'out_of_window' ), $internal ) ); }
		if ( $claims && ! Security\SignedQr::in_window( $claims, $at, $row ) ) { return new \WP_Error( 'wpcap_qr_expired', __( 'Der QR-Code liegt außerhalb seines Gültigkeitszeitraums.', 'pass-captain' ), array_merge( array( 'status' => 409, 'result' => 'out_of_window' ), $internal ) ); }
		return $found;
	}

	/**
	 * Read-only preview for scanners. Personal data is limited to the attendee name, and only
	 * when the operator enabled `scanner_show_attendee`. For valid tickets `status` is the result a
	 * check-in would have now when the admission policy would block it (e.g. not_valid_today).
	 *
	 * @param array $context ['event_ids' => int[]|null (device restriction), 'occurrence_id' => int, 'area_id' => int]
	 */
	public static function preview( $code, array $context = array() ) {
		$adapter = ScannerAdapters::for_code( $code );
		if ( '' !== $adapter ) { return ScannerAdapters::preview( $adapter, $code, $context ); }
		$at = self::eval_time( $context );
		$found = self::lookup( $code, $at );
		if ( is_wp_error( $found ) ) { return self::public_error( $found ); }
		$denied = self::deny( $found, $context, 'in' );
		if ( $denied ) { return self::public_error( $denied ); }
		$status = $found['status'];
		if ( 'valid' === $status ) {
			$policy = Policies::for_type( $found['ticket_type_id'] );
			if ( $policy ) {
				global $wpdb;
				$state = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_admission_state', $found['ticket_id'] ) );
				$blocked = Engine::evaluate_in( $policy, $found, $state, $at );
				if ( $blocked ) { $status = $blocked; }
			} else {
				$status = Engine::default_window_block( $found, $at ) ?: $status;
			}
		}
		return self::public_view( $found, $status );
	}

	/**
	 * Checks a ticket in.
	 *
	 * @param array $context ['device_id', 'idempotency_key', 'source' (web|app|offline), 'scanned_at' (UTC),
	 *                        'event_ids' (device restriction), 'occurrence_id' (expected occurrence), 'area_id']
	 * @return array|\WP_Error Array with 'status' => 'checked_in' on success.
	 */
	public static function perform( $code, array $context = array() ) {
		// Codes that are no Pass Captain ticket may belong to a registered scanner adapter (add-ons).
		$adapter = ScannerAdapters::for_code( $code );
		if ( '' !== $adapter ) { return ScannerAdapters::check_in( $adapter, $code, $context ); }
		return self::publish( self::perform_internal( $code, $context ) );
	}

	private static function perform_internal( $code, array $context ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$user_id = get_current_user_id();
		$key = self::key_hash( $context );
		$at = self::eval_time( $context );
		if ( $key ) {
			$replay = self::replay( $code, $context, $key, $user_id );
			if ( null !== $replay ) { return $replay; }
		}
		$found = self::lookup( $code, $at );
		if ( is_wp_error( $found ) ) { return self::offline_conflict( $found, null, $context, $key ); }
		$denied = self::deny( $found, $context, 'in' );
		if ( $denied ) { return self::offline_conflict( $denied, $found, $context, $key ); }
		$log = self::logger( $found, $context, $key, $user_id );

		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_id,status FROM %i WHERE ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', $found['ticket_id'] ) );
		if ( '' !== $wpdb->last_error ) { return self::abort(); }
		// Idempotency is decided under the ticket lock: the key is claimed atomically (primary key
		// user + key + ticket). A parallel request with the same key waited for this lock; when the
		// claim already exists, the first request has committed and its outcome is replayed.
		if ( $key && $ticket ) {
			$claimed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id,key_hash,ticket_id,created_at) VALUES (%d,%s,%d,%s)', $p . 'wpcap_checkin_claims', $user_id, $key, (int) $ticket->ticket_id, current_time( 'mysql', true ) ) );
			if ( false === $claimed || self::db_failed() ) { return self::abort(); }
			if ( 0 === (int) $claimed ) {
				$wpdb->query( 'ROLLBACK' );
				$replay = self::replay( $code, $context, $key, $user_id );
				return null !== $replay ? $replay : self::retry_error();
			}
		}
		$reentry = $ticket && 'checked_in' === $ticket->status ? self::reentry_block( $found, $at ) : null;
		if ( null !== $reentry ) {
			// Re-entry after check-out (settings reentry_after_checkout / max_reentries) for ticket types without own policy.
			if ( '' !== $reentry ) {
				$id = $log( $ticket->ticket_id, $reentry );
				$wpdb->query( 'COMMIT' );
				return self::offline_conflict( self::result_error( $reentry, $found, $id ), $found, $context, $key, $id );
			}
			$checkin_id = $log( $ticket->ticket_id, 'checked_in' );
			if ( ! $checkin_id || ! Engine::record_in( $found, $checkin_id, $context, $at, $user_id ) ) { return self::abort(); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { return self::abort(); }
			Audit::record( 'ticket_reentered', 'ticket', $ticket->ticket_id, array( 'event_id' => $found['event_id'], 'device_id' => absint( $context['device_id'] ?? 0 ), 'source' => sanitize_key( $context['source'] ?? 'web' ) ) );
			do_action( 'wpcap_ticket_checked_in', (int) $ticket->ticket_id, $context );
			return array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in', 'reentry' => true ) );
		}
		if ( ! $ticket || 'valid' !== $ticket->status ) {
			// Never log a rejected scan as "checked_in" – reports count that value as admissions.
			$result = ! $ticket ? 'unknown' : ( 'checked_in' === $ticket->status ? 'already_checked_in' : $ticket->status );
			$id = $log( $found['ticket_id'], $result );
			$wpdb->query( 'COMMIT' );
			return self::offline_conflict( self::result_error( $result, $found, $id ), $found, $context, $key, $id );
		}
		// Every read that decides about admission is checked as well: a failed read must not turn into
		// "no policy" (a multi-entry ticket consumed as single entry) or "no entries yet" (an extra entry).
		$state = Engine::state_for_update( $ticket->ticket_id );
		if ( false === $state ) { return self::abort(); }
		$policy = Policies::for_type( $found['ticket_type_id'] );
		if ( '' !== (string) $wpdb->last_error ) { return self::abort(); }
		$day_id = 0;
		if ( $policy ) {
			$blocked = Engine::evaluate_in( $policy, $found, $state, $at );
			if ( '' !== (string) $wpdb->last_error ) { return self::abort(); }
			$repeatable = 'single' !== $policy['policy'];
		} else {
			// Legacy per-occurrence day rules.
			$service_date = Engine::service_date( $at, $found['timezone'], $found );
			$day_table = "{$p}wpcap_occurrence_days";
			$day = $wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_day_id,admission_policy,max_entries FROM %i WHERE occurrence_id=%d AND service_date=%s', $day_table, $found['occurrence_id'], $service_date ) );
			if ( '' !== (string) $wpdb->last_error ) { return self::abort(); }
			$has_day_rules = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurrence_id=%d', $day_table, $found['occurrence_id'] ) );
			if ( '' !== (string) $wpdb->last_error ) { return self::abort(); }
			$repeatable = $day && in_array( $day->admission_policy, array( 'daily', 'reentry' ), true );
			$day_id = $day ? (int) $day->occurrence_day_id : 0;
			$blocked = Engine::default_window_block( $found, $at );
			if ( ! $blocked && $has_day_rules && ! $day ) { $blocked = 'not_valid_today'; }
			if ( ! $blocked && $repeatable ) {
				$entries_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND occurrence_day_id=%d AND result='checked_in'", $p . 'wpcap_checkins', $ticket->ticket_id, $day->occurrence_day_id ) );
				$undone_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND occurrence_day_id=%d AND result='undone'", $p . 'wpcap_checkins', $ticket->ticket_id, $day->occurrence_day_id ) );
				if ( '' !== (string) $wpdb->last_error ) { return self::abort(); }
				if ( $entries_today - $undone_today >= (int) $day->max_entries ) { $blocked = 'daily_limit_reached'; }
			}
		}
		if ( $blocked ) {
			$id = $log( $ticket->ticket_id, $blocked, $day_id );
			$wpdb->query( 'COMMIT' );
			return self::offline_conflict( self::result_error( $blocked, $found, $id ), $found, $context, $key, $id );
		}
		// A backdated offline scan that would be refused if evaluated now (e.g. second admission on a
		// daily ticket claimed for "yesterday") is admitted as uploaded but recorded for review.
		// Ticket types without own policy are checked against the default entry window (setting
		// default_entry_window): a scan claimed for a time before admission closed is reviewed too.
		$backdated = '';
		$now = self::now();
		if ( 'offline' === ( $context['source'] ?? '' ) && $at < $now - 15 * MINUTE_IN_SECONDS ) {
			$backdated = $policy ? Engine::evaluate_in( $policy, $found, $state, $now ) : Engine::default_window_block( $found, $now );
		}
		// Every write is checked: after a deadlock or lock timeout MariaDB has rolled the transaction
		// back, and continuing would autocommit a "checked_in" log for a ticket that stayed valid.
		if ( ! $repeatable && 1 !== $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $ticket->ticket_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) ) ) { return self::abort(); }
		$checkin_id = $log( $ticket->ticket_id, 'checked_in', $day_id );
		if ( ! $checkin_id || ! Engine::record_in( $found, $checkin_id, $context, $at, $user_id ) ) { return self::abort(); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return self::abort(); }
		$review_id = 0;
		if ( $backdated ) {
			$review = self::offline_conflict( new \WP_Error( 'wpcap_backdated_admission', '', array( 'result' => 'backdated_admission' ) ), $found, $context, $key, $checkin_id );
			$review_id = (int) ( ( (array) $review->get_error_data() )['conflict_id'] ?? 0 );
		}
		Audit::record( 'ticket_checked_in', 'ticket', $ticket->ticket_id, array( 'event_id' => $found['event_id'], 'device_id' => absint( $context['device_id'] ?? 0 ), 'source' => sanitize_key( $context['source'] ?? 'web' ), 'area_id' => absint( $context['area_id'] ?? 0 ) ) );
		do_action( 'wpcap_ticket_checked_in', (int) $ticket->ticket_id, $context );
		return array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in' ), $review_id ? array( 'conflict_id' => $review_id, 'review' => 'backdated_admission' ) : array() );
	}

	/**
	 * Checks a ticket out (person leaves). Requires wpcap_checkin with event scope, like check-in.
	 * Logged with result "checked_out"; a ticket that is not inside gives "not_checked_in".
	 *
	 * @param array $context Same keys as perform().
	 * @return array|\WP_Error Array with 'status' => 'checked_out' on success.
	 */
	public static function check_out( $code, array $context = array() ) {
		return self::publish( self::check_out_internal( $code, $context ) );
	}

	private static function check_out_internal( $code, array $context ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$user_id = get_current_user_id();
		$key = self::key_hash( $context );
		$at = self::eval_time( $context );
		$found = self::lookup( $code, $at, false );
		if ( is_wp_error( $found ) ) { return $found; }
		if ( $key ) {
			$previous = $wpdb->get_row( $wpdb->prepare( 'SELECT c.checkin_id,c.result,c.ticket_id FROM %i c WHERE c.user_id=%d AND c.idempotency_key=%s ORDER BY c.checkin_id DESC LIMIT 1', $p . 'wpcap_checkins', $user_id, $key ) );
			if ( $previous && (int) $previous->ticket_id === (int) $found['ticket_id'] ) {
				return 'checked_out' === $previous->result
					? array_merge( self::public_view( $found, 'checked_out' ), array( 'status' => 'checked_out', 'replayed' => true ) )
					: self::result_error( $previous->result, $found, (int) $previous->checkin_id );
			}
		}
		$denied = self::deny( $found, $context, 'out' );
		if ( ! $denied ) { $denied = self::checkout_role_denied( $found ); }
		if ( $denied ) { return $denied; }
		$log = self::logger( $found, $context, $key, $user_id );
		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_id,status FROM %i WHERE ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', $found['ticket_id'] ) );
		if ( '' !== $wpdb->last_error ) { return self::abort(); }
		$state = Engine::state_for_update( $found['ticket_id'] );
		if ( false === $state ) { return self::abort(); }
		$inside = $ticket && Engine::is_inside( $state, $found['ticket_id'] );
		if ( '' !== (string) $wpdb->last_error ) { return self::abort(); }
		if ( ! $inside ) {
			$id = $log( $found['ticket_id'], 'not_checked_in' );
			$wpdb->query( 'COMMIT' );
			return self::result_error( 'not_checked_in', $found, $id );
		}
		$checkin_id = $log( $found['ticket_id'], 'checked_out' );
		if ( ! $checkin_id || ! Engine::record_out( $found, $checkin_id, $context, $at, $user_id ) ) { return self::abort(); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return self::abort(); }
		Audit::record( 'ticket_checked_out', 'ticket', $found['ticket_id'], array( 'event_id' => $found['event_id'], 'device_id' => absint( $context['device_id'] ?? 0 ), 'source' => sanitize_key( $context['source'] ?? 'web' ), 'area_id' => absint( $context['area_id'] ?? 0 ) ) );
		do_action( 'wpcap_ticket_checked_out', (int) $found['ticket_id'], $context );
		return array_merge( self::public_view( $found, 'checked_out' ), array( 'status' => 'checked_out' ) );
	}

	/**
	 * Re-entry of a checked-in ticket without own admission policy: null when the rules do not apply
	 * (setting off, ticket type with policy, ticket still inside), '' when re-entry is allowed,
	 * otherwise the blocking result (entry_limit_reached, outside_entry_window). Inside the transaction.
	 */
	private static function reentry_block( array $found, $at ) {
		if ( ! Security\Policy::get( 'reentry_after_checkout' ) || Policies::for_type( $found['ticket_type_id'] ) ) { return null; }
		global $wpdb;
		// A refunded ticket keeps status checked_in as history but never grants another entry.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT refunded FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', (int) $found['ticket_id'] ) ) ) { return null; }
		$state = Engine::state_for_update( $found['ticket_id'] );
		if ( ! $state || (int) $state->inside || empty( $state->last_out_at ) ) { return null; }
		$max = (int) Security\Policy::get( 'max_reentries' );
		$reentries = max( 0, (int) $state->entries - 1 );
		if ( $max > 0 && $reentries >= $max ) { return 'entry_limit_reached'; }
		$window = Engine::default_window_block( $found, $at );
		return $window ? $window : '';
	}

	/**
	 * Role check of check-out (setting checkout_roles): with a role list, only users with one of these
	 * roles (or administrators) may check out; event/date scope is checked before in deny().
	 */
	private static function checkout_role_denied( array $found ) {
		$allowed = array_filter( array_map( 'trim', explode( ',', (string) Security\Policy::get( 'checkout_roles' ) ) ) );
		if ( ! $allowed || current_user_can( 'manage_options' ) ) { return null; }
		if ( array_intersect( $allowed, self::current_roles() ) ) { return null; }
		return new \WP_Error( 'wpcap_checkout_forbidden', __( 'Check-out ist für deine Rolle nicht freigegeben.', 'pass-captain' ), array( 'status' => 403, 'result' => 'forbidden', 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_id' => (int) $found['occurrence_id'] ) );
	}

	/**
	 * Permission to take back the latest admission of a ticket (admin ticket screen, app undo).
	 * Administrators and staff with wpcap_manage_orders for the event may always; roles in the setting
	 * undo_roles as well. Everybody else (plain check-in staff) only undoes an own scan – same user or,
	 * for apps, the same device – within undo_window_minutes (default 5; 0 = never).
	 *
	 * @param array $context device_id of the requesting app (optional).
	 * @return \WP_Error|null
	 */
	public static function undo_denied( $ticket_id, $event_id, array $context = array() ) {
		if ( current_user_can( 'manage_options' ) || Access::can_access_event( (int) $event_id, 'wpcap_manage_orders' ) ) { return null; }
		$allowed = array_filter( array_map( 'trim', explode( ',', (string) Security\Policy::get( 'undo_roles' ) ) ) );
		if ( $allowed && array_intersect( $allowed, self::current_roles() ) ) { return null; }
		$minutes = max( 0, (int) Security\Policy::get( 'undo_window_minutes' ) );
		global $wpdb;
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT user_id,device_id,created_at FROM %i WHERE ticket_id=%d AND result='checked_in' ORDER BY checkin_id DESC LIMIT 1", $wpdb->prefix . 'wpcap_checkins', (int) $ticket_id ) );
		$device_id = absint( $context['device_id'] ?? 0 );
		$own = $last && ( (int) $last->user_id === get_current_user_id() || ( $device_id && (int) $last->device_id === $device_id ) );
		$recent = $last && strtotime( $last->created_at . ' UTC' ) >= time() - $minutes * MINUTE_IN_SECONDS;
		if ( $minutes > 0 && $own && $recent ) { return null; }
		$message = $minutes > 0
			/* translators: %d: minutes */
			? sprintf( __( 'Nur eigene Scans der letzten %d Minuten können zurückgenommen werden. Bitte die Einlassleitung fragen.', 'pass-captain' ), $minutes )
			: __( 'Check-ins können nur von der Einlassleitung zurückgenommen werden.', 'pass-captain' );
		return new \WP_Error( 'wpcap_undo_forbidden', $message, array( 'status' => 403, 'result' => 'forbidden' ) );
	}

	/**
	 * Claims the idempotency key of an undo inside the caller's transaction (ticket row locked).
	 *
	 * @return int 1 claimed (first request), 0 already claimed (replay), -1 database error.
	 */
	public static function claim_key( $key, $ticket_id ) {
		global $wpdb;
		$claimed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id,key_hash,ticket_id,created_at) VALUES (%d,%s,%d,%s)', $wpdb->prefix . 'wpcap_checkin_claims', get_current_user_id(), hash( 'sha256', 'undo|' . (string) $key ), (int) $ticket_id, current_time( 'mysql', true ) ) );
		if ( false === $claimed || '' !== (string) $wpdb->last_error ) { return -1; }
		return (int) $claimed > 0 ? 1 : 0;
	}

	/** Roles of the current user (filter wpcap_checkout_user_roles). */
	private static function current_roles() {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$roles = is_object( $user ) ? array_values( (array) ( get_object_vars( $user )['roles'] ?? array() ) ) : array();
		return array_map( 'strval', (array) apply_filters( 'wpcap_checkout_user_roles', $roles, get_current_user_id() ) );
	}

	/**
	 * Answer for a repeated idempotency key of this user (null: key unknown or used for another
	 * ticket). An admission wins over later rejections logged under the same key, so a retry after a
	 * lost response shows the original "OK" instead of "already used".
	 *
	 * @return array|\WP_Error|null
	 */
	private static function replay( $code, array $context, $key, $user_id ) {
		global $wpdb;
		$previous = $wpdb->get_row( $wpdb->prepare( "SELECT c.checkin_id,c.result,c.ticket_id FROM %i c WHERE c.user_id=%d AND c.idempotency_key=%s ORDER BY (c.result='checked_in') DESC, c.checkin_id DESC LIMIT 1", $wpdb->prefix . 'wpcap_checkins', $user_id, $key ) );
		if ( ! $previous && '' !== (string) $wpdb->last_error ) { return self::retry_error(); }
		if ( ! $previous ) { return null; }
		$found = self::lookup( $code, null, false );
		if ( is_wp_error( $found ) || (int) $found['ticket_id'] !== (int) $previous->ticket_id ) { return null; }
		// A replay is answered only while the user may still admit this ticket here (access, device, date, area).
		$denied = self::deny( $found, $context, 'in' );
		if ( $denied ) { return $denied; }
		$review_id = 'checked_in' === $previous->result && 'offline' === ( $context['source'] ?? '' ) ? Conflicts::find_by_dedupe( $user_id . '|' . $key ) : 0;
		return 'checked_in' === $previous->result
			? array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in', 'replayed' => true ), $review_id ? array( 'conflict_id' => $review_id, 'review' => 'backdated_admission' ) : array() )
			: self::offline_conflict( self::result_error( $previous->result, $found, (int) $previous->checkin_id ), $found, $context, $key, (int) $previous->checkin_id );
	}

	/** Schema of the idempotency claims (one row per user + key + ticket, written under the ticket lock). */
	public static function claims_schema( $table, $charset ) {
		return "CREATE TABLE $table (
				user_id bigint(20) unsigned NOT NULL,
				key_hash char(64) NOT NULL,
				ticket_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (user_id,key_hash,ticket_id),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;";
	}

	/**
	 * Deletes idempotency claims older than 7 days (retries arrive within minutes; offline uploads
	 * within the offline maximum age). Replays of older keys are still answered from the check-in log.
	 */
	public static function purge_claims( $now = null, $deadline = null, $batch = 5000 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', ( null === $now ? time() : (int) $now ) - 7 * DAY_IN_SECONDS );
		$total = 0;
		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at<%s LIMIT %d', $wpdb->prefix . 'wpcap_checkin_claims', $cutoff, (int) $batch ) );
			$total += max( 0, $deleted );
		} while ( $deleted >= $batch && ( null === $deadline || microtime( true ) < $deadline ) );
		return $total;
	}

	/**
	 * Whether the last database statement failed (every query resets/sets the error).
	 *
	 * @phpstan-impure
	 */
	private static function db_failed() {
		global $wpdb;
		return '' !== (string) $wpdb->last_error;
	}

	/** Rolls back a failed check-in transaction and returns a retryable error (nothing was booked). */
	private static function abort() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return self::retry_error();
	}

	/** Retryable error for a scan that hit a database error (nothing was booked; scan again / upload again). */
	private static function retry_error() {
		return new \WP_Error( 'wpcap_checkin_retry', __( 'Der Scan konnte gerade nicht gespeichert werden. Bitte erneut scannen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'result' => 'retry' ) );
	}

	private static function key_hash( array $context ) {
		return isset( $context['idempotency_key'] ) && '' !== (string) $context['idempotency_key'] ? hash( 'sha256', (string) $context['idempotency_key'] ) : null;
	}

	/** Returns a closure that writes one wpcap_checkins row and returns its id. */
	private static function logger( array $found, array $context, $key, $user_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return static function ( $ticket_id, $result, $day_id = 0 ) use ( $wpdb, $p, $found, $user_id, $key, $context ) {
			$wpdb->insert( "{$p}wpcap_checkins", array(
				'ticket_id' => (int) $ticket_id,
				'event_id' => (int) $found['event_id'],
				'occurrence_day_id' => (int) $day_id,
				'user_id' => $user_id,
				'result' => $result,
				'device_id' => absint( $context['device_id'] ?? 0 ),
				'idempotency_key' => $key,
				'source' => sanitize_key( $context['source'] ?? 'web' ),
				'scanned_at' => ! empty( $context['scanned_at'] ) ? $context['scanned_at'] : null,
				'created_at' => current_time( 'mysql', true ),
			) );
			return '' === $wpdb->last_error ? (int) $wpdb->insert_id : 0;
		};
	}

	/**
	 * For offline uploads: a rejected scan of a known ticket was already admitted on the device, so
	 * it is recorded as admission conflict. The error then carries `conflict_id`.
	 */
	private static function offline_conflict( \WP_Error $error, $found, array $context, $key, $checkin_id = 0 ) {
		if ( 'offline' !== ( $context['source'] ?? '' ) ) { return $error; }
		$data = (array) $error->get_error_data();
		$ticket_id = $found ? (int) $found['ticket_id'] : (int) ( $data['ticket_id'] ?? 0 );
		$event_id = $found ? (int) $found['event_id'] : (int) ( $data['event_id'] ?? 0 );
		// Only scans the uploader was allowed to make for this event can be conflicts; otherwise a
		// scanner of event A could create open conflicts in event B.
		if ( ! $ticket_id || ! $event_id || in_array( (string) ( $data['result'] ?? '' ), array( 'forbidden', 'wrong_event', 'unknown', 'retry' ), true ) ) { return $error; }
		if ( ! Access::can_access_occurrence( $found ? (int) $found['occurrence_id'] : absint( $data['occurrence_id'] ?? 0 ), 'wpcap_checkin', $event_id ) ) { return $error; }
		if ( isset( $context['event_ids'] ) && is_array( $context['event_ids'] ) && $context['event_ids'] && ! in_array( $event_id, array_map( 'intval', $context['event_ids'] ), true ) ) { return $error; }
		$dedupe = $key ? get_current_user_id() . '|' . $key : 'scan|' . get_current_user_id() . '|' . $ticket_id . '|' . (string) ( $context['scanned_at'] ?? '' ) . '|' . (string) $checkin_id;
		$conflict_id = Conflicts::record( array(
			'dedupe' => $dedupe,
			'ticket_id' => $ticket_id,
			'event_id' => $event_id,
			'occurrence_id' => $found ? $found['occurrence_id'] : ( $data['occurrence_id'] ?? 0 ),
			'area_id' => absint( $context['area_id'] ?? 0 ),
			'result' => (string) ( $data['result'] ?? $error->get_error_code() ),
			'source' => 'offline',
			'device_id' => absint( $context['device_id'] ?? 0 ),
			'user_id' => get_current_user_id(),
			'scanned_at' => $context['scanned_at'] ?? null,
			'checkin_id' => $checkin_id,
		) );
		if ( $conflict_id ) { $data['conflict_id'] = $conflict_id; }
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/** Event scope, device and area restrictions; returns WP_Error or null. */
	private static function deny( array $found, array $context, $direction = 'in' ) {
		$internal = array( 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_id' => (int) $found['occurrence_id'] );
		// Event-level assignment or an assignment for exactly this date (occurrence scope).
		if ( ! Access::can_access_occurrence( $found['occurrence_id'], 'wpcap_checkin', $found['event_id'] ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), array_merge( $internal, array( 'status' => 403, 'result' => 'forbidden' ) ) );
		}
		if ( isset( $context['event_ids'] ) && is_array( $context['event_ids'] ) && $context['event_ids'] && ! in_array( (int) $found['event_id'], array_map( 'intval', $context['event_ids'] ), true ) ) {
			return new \WP_Error( 'wpcap_device_event', __( 'Dieses Gerät ist für ein anderes Event freigegeben.', 'pass-captain' ), array_merge( $internal, array( 'status' => 403, 'result' => 'wrong_event' ) ) );
		}
		if ( ! empty( $context['occurrence_id'] ) && (int) $context['occurrence_id'] !== (int) $found['occurrence_id'] ) {
			return new \WP_Error( 'wpcap_wrong_occurrence', __( 'Das Ticket gilt für einen anderen Termin.', 'pass-captain' ), array_merge( $internal, array( 'status' => 409, 'result' => 'wrong_occurrence' ) ) );
		}
		if ( Areas::check( $found, absint( $context['area_id'] ?? 0 ), $direction ) ) {
			return new \WP_Error( 'wpcap_wrong_area', __( 'Das Ticket gilt nicht für diesen Einlassbereich (oder du bist hier nicht eingeteilt).', 'pass-captain' ), array_merge( $internal, array( 'status' => 409, 'result' => 'wrong_area', 'event' => $found['event_name'], 'ticket' => $found['ticket_name'] ) ) );
		}
		return null;
	}

	/** Stable error per result code. */
	private static function result_error( $result, array $found, $checkin_id = 0 ) {
		$messages = array(
			'already_checked_in' => __( 'Ticket wurde bereits verwendet.', 'pass-captain' ),
			'revoked' => __( 'Ticket ist widerrufen.', 'pass-captain' ),
			'not_valid_today' => __( 'Ticket ist heute nicht gültig.', 'pass-captain' ),
			'daily_limit_reached' => __( 'Das Tageskontingent für dieses Ticket ist erreicht.', 'pass-captain' ),
			'entry_limit_reached' => __( 'Die maximale Anzahl an Einlässen für dieses Ticket ist erreicht.', 'pass-captain' ),
			'outside_entry_window' => __( 'Einlass mit diesem Ticket ist zu dieser Uhrzeit nicht möglich.', 'pass-captain' ),
			'already_inside' => __( 'Ticket ist bereits eingecheckt; für einen Wiedereinlass zuerst auschecken.', 'pass-captain' ),
			'not_checked_in' => __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ),
		);
		$codes = array(
			'not_valid_today' => 'wpcap_ticket_day_rule',
			'daily_limit_reached' => 'wpcap_ticket_day_rule',
			'entry_limit_reached' => 'wpcap_ticket_entry_limit',
			'outside_entry_window' => 'wpcap_ticket_entry_window',
			'already_inside' => 'wpcap_ticket_already_inside',
			'not_checked_in' => 'wpcap_ticket_not_checked_in',
		);
		return new \WP_Error( $codes[ $result ] ?? 'wpcap_ticket_not_valid', $messages[ $result ] ?? __( 'Ticket wurde bereits verwendet oder ist widerrufen.', 'pass-captain' ), array( 'status' => 409, 'ticket_status' => $result, 'result' => $result, 'event' => $found['event_name'], 'ticket' => $found['ticket_name'], 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_id' => (int) $found['occurrence_id'], 'checkin_id' => (int) $checkin_id ) );
	}

	/** Data shown to scanning staff (no buyer e-mail, no payment data). */
	private static function public_view( array $found, $status ) {
		$view = array(
			'status' => $status,
			'ticket_public_id' => $found['public_id'],
			'event_id' => $found['event_id'],
			'occurrence_id' => $found['occurrence_id'],
			'event' => $found['event_name'],
			'ticket' => $found['ticket_name'],
			'starts_at' => gmdate( 'c', strtotime( $found['starts_at'] . ' UTC' ) ),
			'timezone' => $found['timezone'],
		);
		if ( Settings::get( 'scanner_show_attendee' ) && '' !== $found['attendee_name'] ) {
			$view['attendee_name'] = $found['attendee_name'];
		}
		return $view;
	}
}
