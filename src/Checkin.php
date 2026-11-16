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

	/**
	 * Extracts the 64-hex ticket code from a scanned value (raw code or ticket URL). A value with more
	 * than one code (a hex run longer than 64 characters or two different codes, e.g. a keyboard
	 * scanner typing behind the previous code, A271) has no code: '' – never the first of them.
	 */
	public static function normalize_code( $value ) {
		$codes = self::codes_in( $value );
		return 1 === count( $codes ) && 64 === strlen( $codes[0] ) ? $codes[0] : '';
	}

	/** True when a scanned value holds more than one ticket code (refused, A271). */
	public static function has_multiple_codes( $value ) {
		$codes = self::codes_in( $value );
		return count( $codes ) > 1 || ( 1 === count( $codes ) && 64 !== strlen( $codes[0] ) );
	}

	/** Distinct lower-case hex runs of at least 64 characters in a scanned value. */
	private static function codes_in( $value ) {
		if ( ! preg_match_all( '/[a-f0-9]{64,}/i', Http::str( $value ), $m ) ) { return array(); }
		return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
	}

	/** Current time for admission decisions (filterable for tests and simulations). */
	public static function now() {
		return (int) apply_filters( 'wpcap_checkin_now', Clock::now() );
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
			if ( $ts && $ts <= $now + 300 && $ts >= $now - Policies::offline_max_age_seconds() ) { return min( $ts, $now ); }
		}
		return $now;
	}

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

	/** Order statuses whose tickets never admit (same rule as the Doctor check "tickets of closed orders"). */
	const CLOSED_ORDER_STATUSES = array( 'cancelled', 'refunded', 'expired' );

	/**
	 * Whether a ticket is void for admission regardless of its status (A590): refunded, or its order
	 * is cancelled, refunded or expired.
	 */
	public static function is_void( $refunded, $order_status, $payment_status ) {
		return (int) $refunded > 0 || in_array( (string) $order_status, self::CLOSED_ORDER_STATUSES, true ) || in_array( (string) $payment_status, self::CLOSED_ORDER_STATUSES, true );
	}

	private static function void_error( array $internal = array() ) {
		return new \WP_Error( 'wpcap_ticket_refunded', __( 'Ticket ist erstattet bzw. die Bestellung storniert; kein Einlass.', 'pass-captain' ), array_merge( array( 'status' => 409, 'result' => 'revoked', 'ticket_status' => 'revoked' ), $internal ) );
	}

	/**
	 * Looks a ticket up by code and checks admission preconditions (not the ticket status).
	 *
	 * @param string   $code
	 * @param int|null $at      Evaluation time (UTC timestamp); default now.
	 * @param bool     $strict  false skips the cancellation/plausibility checks (used for check-out).
	 * @param array    $context Scan context (event_ids, occurrence_id, area_id) for `wpcap_ticket_valid_for_occurrence`.
	 * @return array|\WP_Error
	 */
	public static function lookup( $code, $at = null, $strict = true, array $context = array() ) {
		$input = $code;
		// Internal reference by ticket id (perform_ticket(), e.g. guest list arrival): no code involved.
		$ticket_ref = absint( $context['ticket_ref'] ?? 0 );
		if ( ! $ticket_ref && self::has_multiple_codes( $input ) ) {
			return new \WP_Error( 'wpcap_ticket_ambiguous', __( 'Mehrere Ticketcodes in einer Eingabe – nichts geprüft. Bitte jedes Ticket einzeln scannen.', 'pass-captain' ), array( 'status' => 400, 'result' => 'invalid_code' ) );
		}
		$code = $ticket_ref ? '' : self::normalize_code( $code );
		$help_ticket = $ticket_ref;
		if ( ! $ticket_ref && '' === $code ) {
			// Staff-only fallback: the short help code printed next to the QR code (rate-limited).
			$help_ticket = HelpCode::resolve( $input );
			if ( is_wp_error( $help_ticket ) ) { return $help_ticket; }
			if ( ! $help_ticket ) {
				return HelpCode::looks_like( $input )
					? new \WP_Error( 'wpcap_ticket_not_found', __( 'Hilfscode nicht gefunden. Bitte Zeichen prüfen (ohne 0, O, 1, I).', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) )
					: new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) );
			}
		}
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.ticket_type_id,t.attendee_email,ta.attendee_name,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.status AS occurrence_status,e.status AS event_status,e.name AS event_name,ord.status AS order_status,ord.payment_status FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE " . ( $help_ticket ? 't.ticket_id=%d' : 't.token_hash=%s' ) . ' LIMIT 1',
			$help_ticket ? $help_ticket : hash( 'sha256', $code )
		) );
		// A failed lookup is no unknown ticket (A183): the scanner must retry, not refuse the guest.
		if ( ! $row && '' !== $wpdb->last_error ) { return self::retry_error(); }
		if ( ! $row ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		$found = array( 'ticket_id' => (int) $row->ticket_id, 'public_id' => $row->public_id, 'event_id' => (int) $row->event_id, 'occurrence_id' => (int) $row->occurrence_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'status' => $row->status, 'event_name' => $row->event_name, 'ticket_name' => $row->ticket_name, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'timezone' => $row->timezone, 'attendee_name' => (string) $row->attendee_name );
		if ( $help_ticket && ! $ticket_ref ) { $found['help_code'] = true; }
		if ( $ticket_ref ) { $found['manual'] = true; } // Staff check-in by ticket id (A338, A591).
		// [Passes] Extension point: a ticket may be valid for another occurrence than the one of its
		// ticket type (multi-date/festival passes). Modules return $found bound to that occurrence
		// (occurrence_id, event_id, event_name, starts_at, ends_at, timezone and the statuses below);
		// other keys are ignored. Default: unchanged.
		$bound = apply_filters( 'wpcap_ticket_valid_for_occurrence', $found + array( 'occurrence_status' => $row->occurrence_status, 'event_status' => $row->event_status ), $context, null === $at ? self::now() : (int) $at );
		if ( is_array( $bound ) ) {
			foreach ( array( 'occurrence_id', 'event_id', 'event_name', 'starts_at', 'ends_at', 'timezone' ) as $k ) {
				if ( array_key_exists( $k, $bound ) ) { $found[ $k ] = in_array( $k, array( 'occurrence_id', 'event_id' ), true ) ? (int) $bound[ $k ] : $bound[ $k ]; }
			}
			$row->occurrence_status = (string) ( $bound['occurrence_status'] ?? $row->occurrence_status );
			$row->event_status = (string) ( $bound['event_status'] ?? $row->event_status );
		}
		if ( ! $strict ) { return $found; }
		$internal = array( 'event_id' => (int) $row->event_id, 'ticket_id' => (int) $row->ticket_id, 'occurrence_id' => (int) $row->occurrence_id );
		if ( 'cancelled' === $row->occurrence_status || 'cancelled' === $row->event_status ) { return new \WP_Error( 'wpcap_ticket_cancelled_event', __( 'Dieser Termin wurde abgesagt; das Ticket berechtigt nicht zum Einlass.', 'pass-captain' ), array_merge( array( 'status' => 409, 'result' => 'cancelled_event' ), $internal ) ); }
		// A590: a refunded ticket or one of a closed order never admits, whatever its status says (a
		// checked-in ticket keeps status checked_in when it is refunded).
		if ( self::is_void( $row->refunded, $row->order_status, $row->payment_status ) ) { return self::void_error( $internal ); }
		$at = null === $at ? self::now() : (int) $at;
		$start = strtotime( $found['starts_at'] . ' UTC' );
		$last = Policies::last_valid_ts( (int) $row->ticket_type_id, $found );
		if ( $last < $at - DAY_IN_SECONDS || $start > $at + 365 * DAY_IN_SECONDS ) { return new \WP_Error( 'wpcap_ticket_out_of_window', __( 'Das Ticket liegt außerhalb des Einlasszeitraums.', 'pass-captain' ), array_merge( array( 'status' => 409, 'result' => 'out_of_window' ), $internal ) ); }
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
		unset( $context['ticket_ref'] );
		$at = self::eval_time( $context );
		$found = self::lookup( $code, $at, true, $context );
		if ( is_wp_error( $found ) ) { return self::public_error( $found ); }
		$denied = self::deny( $found, $context, 'in' );
		if ( $denied ) { return self::public_error( $denied ); }
		$status = $found['status'];
		if ( 'valid' === $status ) {
			$policy = Policies::for_type( $found['ticket_type_id'] );
			if ( $policy ) {
				global $wpdb;
				$state = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_admission_state WHERE ticket_id=%d", $found['ticket_id'] ) );
				$blocked = Engine::evaluate_in( $policy, $found, $state, $at );
				if ( $blocked ) { $status = $blocked; }
			} else {
				$status = Engine::default_window_block( $found, $at ) ?: $status;
			}
			if ( 'valid' === $status ) {
				$status = Areas::capacity_block( $found, absint( $context['area_id'] ?? 0 ) ) ?: $status;
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
		unset( $context['ticket_ref'] ); // Only perform_ticket() may address a ticket without its code.
		return self::publish( self::perform_internal( $code, $context ) );
	}

	/**
	 * Checks a ticket in by its id, without knowing its code – staff at the admin ticket list (A338),
	 * e.g. when the guest has no readable code, and guest list arrival (A591). Exactly the same rules,
	 * lock and log as a scan (event scope of wpcap_checkin, occurrence and area restrictions, admission
	 * policies, area capacity, idempotency key); the ticket code stays unchanged. The audit entry
	 * carries `via: manual`; callers name their source in the context (e.g. `admin`).
	 *
	 * @param int   $ticket_id Ticket.
	 * @param array $context   Same keys as perform() (occurrence_id, area_id, idempotency_key, source, …).
	 * @return array|\WP_Error
	 */
	public static function perform_ticket( $ticket_id, array $context = array() ) {
		$ticket_id = absint( $ticket_id );
		if ( ! $ticket_id ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		$context['ticket_ref'] = $ticket_id;
		return self::publish( self::perform_internal( '', $context ) );
	}

	/** Previous scan of the same user and idempotency key (null if none, false on a read error). */
	private static function previous_scan( $user_id, $key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT c.checkin_id,c.result,c.ticket_id FROM {$wpdb->prefix}wpcap_checkins c WHERE c.user_id=%d AND c.idempotency_key=%s ORDER BY c.checkin_id DESC LIMIT 1", $user_id, $key ) );
		return '' !== $wpdb->last_error ? false : $row;
	}

	/** Answer to a check-in whose idempotency key was already booked for this ticket: the first result again. */
	private static function replay_in( $previous, array $found, array $context, $key, $user_id ) {
		if ( 'checked_in' !== $previous->result ) {
			return self::offline_conflict( self::result_error( $previous->result, $found, (int) $previous->checkin_id ), $found, $context, $key, (int) $previous->checkin_id );
		}
		$review_id = 'offline' === ( $context['source'] ?? '' ) ? Conflicts::find_by_dedupe( $user_id . '|' . $key ) : 0;
		$review = $review_id ? Conflicts::get( $review_id ) : null;
		return array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in', 'replayed' => true ), $review ? array( 'conflict_id' => $review_id, 'review' => (string) $review->result ) : array() );
	}

	private static function perform_internal( $code, array $context ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$user_id = get_current_user_id();
		$key = self::key_hash( $context );
		$at = self::eval_time( $context );
		if ( $key ) {
			$previous = self::previous_scan( $user_id, $key );
			// Unknown whether this scan was already booked: a fresh attempt could answer "already used".
			if ( false === $previous ) { return self::retry_error(); }
			if ( $previous ) {
				$found = self::lookup( $code, null, false, array_intersect_key( $context, array( 'ticket_ref' => 1 ) ) );
				if ( ! is_wp_error( $found ) && (int) $found['ticket_id'] === (int) $previous->ticket_id ) {
					return self::replay_in( $previous, $found, $context, $key, $user_id );
				}
			}
		}
		$found = self::lookup( $code, $at, true, $context );
		if ( is_wp_error( $found ) ) { return Errors::is_busy( $found ) ? $found : self::offline_conflict( $found, null, $context, $key ); }
		$denied = self::deny( $found, $context, 'in' );
		// A refusal that rests on a failed read (scope, areas) is no refusal (A183).
		if ( $denied && '' !== $wpdb->last_error ) { return self::retry_error(); }
		if ( $denied ) { return self::offline_conflict( $denied, $found, $context, $key ); }
		$log = self::logger( $found, $context, $key, $user_id );

		// Without a transaction every statement below would autocommit on its own.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::abort(); }
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", $found['ticket_id'] ) );
		if ( '' !== $wpdb->last_error ) { return self::abort(); }
		if ( $key && $ticket ) {
			// A589: a retry with the same key that ran in parallel waited for this lock. The key is
			// checked again now (this plain read is the transaction's first consistent read, so it sees
			// what the other request committed): the scan is booked once and the retry gets its answer.
			$previous = self::previous_scan( $user_id, $key );
			if ( false === $previous ) { return self::abort(); }
			if ( $previous && (int) $previous->ticket_id === (int) $ticket->ticket_id ) {
				$wpdb->query( 'ROLLBACK' );
				return self::replay_in( $previous, $found, $context, $key, $user_id );
			}
		}
		if ( ! $ticket || 'valid' !== $ticket->status || (int) $ticket->refunded ) {
			// Never log a rejected scan as "checked_in" – reports count that value as admissions.
			$result = ! $ticket ? 'unknown' : ( (int) $ticket->refunded ? 'revoked' : ( 'checked_in' === $ticket->status ? 'already_checked_in' : $ticket->status ) );
			$id = $log( $found['ticket_id'], $result );
			$wpdb->query( 'COMMIT' );
			return self::offline_conflict( self::result_error( $result, $found, $id ), $found, $context, $key, $id );
		}
		$state = Engine::state_for_update( $ticket->ticket_id );
		if ( '' !== $wpdb->last_error ) { return self::abort(); }
		$policy = Policies::for_type( $found['ticket_type_id'] );
		$day_id = 0;
		if ( $policy ) {
			$blocked = Engine::evaluate_in( $policy, $found, $state, $at );
			$repeatable = 'single' !== $policy['policy'];
		} else {
			// Legacy per-occurrence day rules.
			$service_date = Engine::service_date( $at, $found['timezone'], $found );
			$day_table = "{$p}wpcap_occurrence_days";
			$day = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_day_id,admission_policy,max_entries FROM $day_table WHERE occurrence_id=%d AND service_date=%s", $found['occurrence_id'], $service_date ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_day_rules = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $day_table WHERE occurrence_id=%d", $found['occurrence_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$repeatable = $day && in_array( $day->admission_policy, array( 'daily', 'reentry' ), true );
			$day_id = $day ? (int) $day->occurrence_day_id : 0;
			$blocked = Engine::default_window_block( $found, $at );
			if ( ! $blocked && $has_day_rules && ! $day ) { $blocked = 'not_valid_today'; }
			if ( ! $blocked && $repeatable ) {
				$entries_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=%d AND occurrence_day_id=%d AND result='checked_in'", $ticket->ticket_id, $day->occurrence_day_id ) );
				$undone_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=%d AND occurrence_day_id=%d AND result='undone'", $ticket->ticket_id, $day->occurrence_day_id ) );
				if ( $entries_today - $undone_today >= (int) $day->max_entries ) { $blocked = 'daily_limit_reached'; }
			}
		}
		// Capacity of the entrance area (people inside at the same time). An offline upload of a paired
		// device is not refused for it – the device already let the person in – but recorded for review;
		// every other upload (web scanner queue) is checked like an online scan (A592).
		$over_capacity = '';
		if ( ! $blocked ) {
			$capacity = Areas::capacity_block( $found, absint( $context['area_id'] ?? 0 ), true );
			if ( '' !== $wpdb->last_error ) { return self::abort(); }
			if ( $capacity && self::device_offline( $context ) ) { $over_capacity = $capacity; } else { $blocked = $capacity; }
		}
		if ( $blocked ) {
			$id = $log( $ticket->ticket_id, $blocked, $day_id );
			$wpdb->query( 'COMMIT' );
			return self::offline_conflict( self::result_error( $blocked, $found, $id ), $found, $context, $key, $id );
		}
		// A backdated offline scan that would be refused if evaluated now (e.g. second admission on a
		// daily ticket claimed for "yesterday", or an entry window that closed meanwhile – also for
		// ticket types without a policy, A592) is admitted as uploaded but always recorded for review,
		// like an offline admission into a full area.
		$review = '';
		$now = self::now();
		if ( 'offline' === ( $context['source'] ?? '' ) && $at < $now - 15 * MINUTE_IN_SECONDS && '' !== self::refused_at( $policy, $found, $state, $now ) ) {
			$review = 'backdated_admission';
		}
		if ( '' === $review && '' !== $over_capacity ) { $review = 'area_full'; }
		// Every write is checked: after a deadlock or lock timeout MariaDB has rolled the transaction
		// back, and continuing would autocommit a "checked_in" log for a ticket that stayed valid.
		if ( ! $repeatable && 1 !== $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $ticket->ticket_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) ) ) { return self::abort(); }
		$checkin_id = $log( $ticket->ticket_id, 'checked_in', $day_id );
		if ( ! $checkin_id || ! Engine::record_in( $found, $checkin_id, $context, $at, $user_id ) ) { return self::abort(); }
		if ( ! self::commit_scan( (int) $ticket->ticket_id, $checkin_id, $repeatable ) ) { return self::abort(); }
		$review_id = 0;
		if ( '' !== $review ) {
			$recorded = self::offline_conflict( new \WP_Error( 'wpcap_' . $review, '', array( 'result' => $review ) ), $found, $context, $key, $checkin_id );
			$review_id = (int) ( ( (array) $recorded->get_error_data() )['conflict_id'] ?? 0 );
		}
		Audit::record( 'ticket_checked_in', 'ticket', $ticket->ticket_id, array_filter( array( 'event_id' => $found['event_id'], 'device_id' => absint( $context['device_id'] ?? 0 ), 'source' => sanitize_key( $context['source'] ?? 'web' ), 'area_id' => absint( $context['area_id'] ?? 0 ), 'via' => empty( $found['help_code'] ) ? ( empty( $found['manual'] ) ? null : 'manual' ) : 'help_code' ), static function ( $v ) { return null !== $v; } ) );
		do_action( 'wpcap_ticket_checked_in', (int) $ticket->ticket_id, $context );
		return array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in' ), self::history( (int) $ticket->ticket_id ), $review_id ? array( 'conflict_id' => $review_id, 'review' => $review ) : array() );
	}

	/** Offline upload of a paired device (Mobile API / offline scanner app): the device admitted the person. */
	private static function device_offline( array $context ) {
		return 'offline' === ( $context['source'] ?? '' ) && absint( $context['device_id'] ?? 0 ) > 0;
	}

	/**
	 * Block code a check-in would get at $ts (policy, default entry window and doors-open time, or the
	 * legacy per-day rules), '' when it would be admitted. Read-only; runs under the ticket row lock.
	 */
	private static function refused_at( $policy, array $found, $state, $ts ) {
		if ( $policy ) { return Engine::evaluate_in( $policy, $found, $state, $ts ); }
		$block = Engine::default_window_block( $found, $ts );
		if ( '' !== $block ) { return $block; }
		global $wpdb;
		$day_table = "{$wpdb->prefix}wpcap_occurrence_days";
		$has_day_rules = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $day_table WHERE occurrence_id=%d", $found['occurrence_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $has_day_rules ) { return ''; }
		$day = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $day_table WHERE occurrence_id=%d AND service_date=%s", $found['occurrence_id'], Engine::service_date( $ts, $found['timezone'], $found ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $day ? '' : 'not_valid_today';
	}

	/**
	 * Checks a ticket out (person leaves). Requires wpcap_checkin with event scope, like check-in.
	 * Logged with result "checked_out"; a ticket that is not inside gives "not_checked_in".
	 *
	 * @param array $context Same keys as perform().
	 * @return array|\WP_Error Array with 'status' => 'checked_out' on success.
	 */
	public static function check_out( $code, array $context = array() ) {
		unset( $context['ticket_ref'] );
		return self::publish( self::check_out_internal( $code, $context ) );
	}

	private static function check_out_internal( $code, array $context ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$user_id = get_current_user_id();
		$key = self::key_hash( $context );
		$at = self::eval_time( $context );
		$found = self::lookup( $code, $at, false, $context );
		if ( is_wp_error( $found ) ) { return $found; }
		if ( $key ) {
			$previous = self::previous_scan( $user_id, $key );
			if ( false === $previous ) { return self::retry_error(); }
			if ( $previous && (int) $previous->ticket_id === (int) $found['ticket_id'] ) {
				return self::replay_out( $previous, $found );
			}
		}
		$denied = self::deny( $found, $context, 'out' );
		if ( $denied ) { return $denied; }
		$log = self::logger( $found, $context, $key, $user_id );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::abort(); }
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", $found['ticket_id'] ) );
		if ( '' !== $wpdb->last_error ) { return self::abort(); }
		if ( $key && $ticket ) {
			// A589: same-key retry that waited for the ticket lock (see perform_internal()).
			$previous = self::previous_scan( $user_id, $key );
			if ( false === $previous ) { return self::abort(); }
			if ( $previous && (int) $previous->ticket_id === (int) $ticket->ticket_id ) {
				$wpdb->query( 'ROLLBACK' );
				return self::replay_out( $previous, $found );
			}
		}
		$state = Engine::state_for_update( $found['ticket_id'] );
		if ( ! $ticket || ! Engine::is_inside( $state, $found['ticket_id'] ) ) {
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

	private static function replay_out( $previous, array $found ) {
		return 'checked_out' === $previous->result
			? array_merge( self::public_view( $found, 'checked_out' ), array( 'status' => 'checked_out', 'replayed' => true ) )
			: self::result_error( $previous->result, $found, (int) $previous->checkin_id );
	}

	/** Rolls back a failed check-in transaction and returns a retryable error (nothing was booked). */
	private static function abort() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return self::retry_error();
	}

	private static function retry_error() {
		return new \WP_Error( 'wpcap_checkin_retry', __( 'Der Scan konnte gerade nicht gespeichert werden. Bitte erneut scannen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}

	/**
	 * Commits an admission and tells whether it is durable (A183). Its first write – the ticket
	 * status, or for repeatable admissions the log row – decides: after a lost connection wpdb
	 * reconnects and runs the rest with autocommit while the earlier statements were rolled back, and
	 * a COMMIT that wpdb re-ran on the new session commits nothing. If the first write is missing,
	 * rows written after the loss (log row, admission entry, presence) are removed and the scan is
	 * reported as "retry".
	 */
	private static function commit_scan( $ticket_id, $checkin_id, $repeatable ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		$probe = $repeatable
			? $wpdb->prepare( "SELECT checkin_id FROM {$p}wpcap_checkins WHERE checkin_id=%d", $checkin_id )
			: $wpdb->prepare( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=%d", $ticket_id );
		$value = $wpdb->get_var( $probe );
		if ( '' !== $wpdb->last_error ) { $value = $wpdb->get_var( $probe ); } // Once more on the reconnected session.
		$durable = $repeatable ? null !== $value : 'checked_in' === $value;
		if ( $durable && '' === $wpdb->last_error ) { return true; }
		if ( '' === $wpdb->last_error ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_checkins WHERE checkin_id=%d AND ticket_id=%d", $checkin_id, $ticket_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_admission_entries WHERE checkin_id=%d AND ticket_id=%d", $checkin_id, $ticket_id ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_admission_state SET inside=0,entries=GREATEST(CAST(entries AS SIGNED)-1,0) WHERE ticket_id=%d AND last_checkin_id=%d", $ticket_id, $checkin_id ) );
		}
		return false;
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
		if ( ! $ticket_id || ! $event_id || in_array( (string) ( $data['result'] ?? '' ), array( 'forbidden', 'wrong_event', 'unknown' ), true ) ) { return $error; }
		if ( ! Access::can_access_event( $event_id, 'wpcap_checkin' ) || ( $found && ! Access::occurrence_in_scope( $found['occurrence_id'], 0, 'wpcap_checkin' ) ) ) { return $error; }
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

	/**
	 * Checks whether the current user (and device) may take back the latest admission of a ticket
	 * (A590). Call under the ticket row lock. Refused for refunded tickets and tickets of closed orders
	 * (the undo would make them admissible again), and outside the area binding that applies to
	 * check-ins: staff assigned to entrance areas of the event and devices bound to an area may only
	 * undo admissions through their area.
	 *
	 * @param int   $ticket_id
	 * @param array $context device_id, area_id (area of the device/scanner), event_ids (device events).
	 * @return array|\WP_Error ['area_id' => area of the admission, 'occurrence_id' => its occurrence]
	 */
	public static function undo_guard( $ticket_id, array $context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.refunded,ord.status AS order_status,ord.payment_status,o.event_id,o.occurrence_id FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
		if ( '' !== $wpdb->last_error ) { return self::retry_error(); }
		if ( ! $row ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		if ( self::is_void( $row->refunded, $row->order_status, $row->payment_status ) ) {
			return new \WP_Error( 'wpcap_undo_refunded', __( 'Das Ticket ist erstattet bzw. die Bestellung storniert. Der Einlass kann nicht zurückgenommen werden, sonst wäre das Ticket wieder gültig.', 'pass-captain' ), array( 'status' => 409, 'result' => 'revoked' ) );
		}
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT area_id,occurrence_id FROM {$p}wpcap_admission_entries WHERE ticket_id=%d AND direction='in' AND undone=0 ORDER BY entry_id DESC LIMIT 1", absint( $ticket_id ) ) );
		if ( '' !== $wpdb->last_error ) { return self::retry_error(); }
		$area_id = $entry ? (int) $entry->area_id : 0;
		$occurrence_id = $entry ? (int) $entry->occurrence_id : (int) $row->occurrence_id;
		$event_id = (int) $row->event_id;
		$area = $area_id ? Areas::get( $area_id ) : null;
		if ( $area ) { $event_id = (int) $area->event_id; }
		$wrong_area = new \WP_Error( 'wpcap_undo_wrong_area', __( 'Dieser Einlass erfolgte in einem anderen Einlassbereich; zurücknehmen kann ihn nur das dort eingeteilte Personal bzw. Gerät.', 'pass-captain' ), array( 'status' => 403, 'result' => 'wrong_area' ) );
		$scanner_area = absint( $context['area_id'] ?? 0 );
		if ( $scanner_area && $scanner_area !== $area_id ) { return $wrong_area; }
		if ( ! current_user_can( 'manage_options' ) ) {
			$mine = Areas::user_area_ids( get_current_user_id(), $event_id );
			if ( $mine && ! in_array( $area_id, $mine, true ) ) { return $wrong_area; }
		}
		return array( 'area_id' => $area_id, 'occurrence_id' => $occurrence_id );
	}

	/** Event scope, device and area restrictions; returns WP_Error or null. */
	private static function deny( array $found, array $context, $direction = 'in' ) {
		$internal = array( 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_id' => (int) $found['occurrence_id'] );
		if ( ! Access::can_access_occurrence( $found['occurrence_id'], 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), array_merge( $internal, array( 'status' => 403, 'result' => 'forbidden' ) ) );
		}
		// A paired device scans only its own events; a device without events scans nothing (A592).
		$device_events = isset( $context['event_ids'] ) && is_array( $context['event_ids'] ) && ( $context['event_ids'] || absint( $context['device_id'] ?? 0 ) );
		if ( $device_events && ! in_array( (int) $found['event_id'], array_map( 'intval', $context['event_ids'] ), true ) ) {
			return new \WP_Error( 'wpcap_device_event', __( 'Dieses Gerät ist für ein anderes Event freigegeben.', 'pass-captain' ), array_merge( $internal, array( 'status' => 403, 'result' => 'wrong_event' ) ) );
		}
		if ( ! empty( $context['occurrence_id'] ) && (int) $context['occurrence_id'] !== (int) $found['occurrence_id'] ) {
			return new \WP_Error( 'wpcap_wrong_occurrence', self::with_validity( __( 'Das Ticket gilt für einen anderen Termin.', 'pass-captain' ), $found ), array_merge( $internal, array( 'status' => 409, 'result' => 'wrong_occurrence' ) ) );
		}
		if ( Areas::check( $found, absint( $context['area_id'] ?? 0 ), $direction ) ) {
			return new \WP_Error( 'wpcap_wrong_area', __( 'Das Ticket gilt nicht für diesen Einlassbereich (oder du bist hier nicht eingeteilt).', 'pass-captain' ), array_merge( $internal, array( 'status' => 409, 'result' => 'wrong_area', 'event' => $found['event_name'], 'ticket' => $found['ticket_name'] ) ) );
		}
		return null;
	}

	/** Appends the ticket's own date (event timezone) so staff can tell the holder when it is valid. */
	private static function with_validity( $message, array $found ) {
		if ( empty( $found['starts_at'] ) ) { return $message; }
		/* translators: 1: refusal reason, 2: date and time of the ticket's occurrence */
		return sprintf( __( '%1$s Das Ticket gilt für %2$s.', 'pass-captain' ), $message, Orders::format_start( $found['starts_at'], (string) ( $found['timezone'] ?? '' ) ) );
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
			'area_full' => __( 'Der Einlassbereich ist voll. Einlass erst, wenn Personen den Bereich verlassen haben (Auslass scannen).', 'pass-captain' ),
		);
		$codes = array(
			'not_valid_today' => 'wpcap_ticket_day_rule',
			'daily_limit_reached' => 'wpcap_ticket_day_rule',
			'entry_limit_reached' => 'wpcap_ticket_entry_limit',
			'outside_entry_window' => 'wpcap_ticket_entry_window',
			'already_inside' => 'wpcap_ticket_already_inside',
			'not_checked_in' => 'wpcap_ticket_not_checked_in',
			'area_full' => 'wpcap_area_full',
		);
		$message = $messages[ $result ] ?? __( 'Ticket wurde bereits verwendet oder ist widerrufen.', 'pass-captain' );
		if ( in_array( $result, array( 'outside_entry_window', 'not_valid_today' ), true ) ) {
			$message = self::with_validity( $message, $found );
		}
		$doors = 'outside_entry_window' === $result ? DoorsOpen::label( DoorsOpen::for_occurrence( (int) $found['occurrence_id'] ), (string) ( $found['timezone'] ?? '' ), (string) ( $found['starts_at'] ?? '' ) ) : '';
		if ( '' !== $doors ) { $message .= ' ' . $doors . '.'; }
		$data = array( 'status' => 409, 'ticket_status' => $result, 'result' => $result, 'event' => $found['event_name'], 'ticket' => $found['ticket_name'], 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_id' => (int) $found['occurrence_id'], 'checkin_id' => (int) $checkin_id );
		// "Already admitted at 19:42 (Nordeingang)": staff can tell a double scan from a copied ticket.
		if ( in_array( $result, self::REJECTED_ADMISSION, true ) ) { $data += self::history( (int) $found['ticket_id'] ); }
		$data += AdmissionChecks::scan_fields( (int) ( $found['ticket_type_id'] ?? 0 ) );
		if ( ! empty( $found['help_code'] ) ) { $data['help_code'] = true; }
		return new \WP_Error( $codes[ $result ] ?? 'wpcap_ticket_not_valid', $message, $data );
	}

	/** Scanner fields passed on to clients with a refused scan (besides result, message, event, ticket). */
	const CLIENT_ERROR_KEYS = array( 'first_checked_in_at', 'last_checked_in_at', 'last_checked_in_area', 'admissions', 'first_admission', 'id_check', 'min_age', 'staff_hint', 'help_code' );

	/**
	 * Admission history of a ticket for the scanner result: first and latest admission (UTC, ISO
	 * 8601), the entrance area of the latest one and the number of admissions (undone ones excluded).
	 * `first_admission` is true when exactly one admission exists (e.g. the scan that just succeeded).
	 */
	public static function history( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_id = absint( $ticket_id );
		$stats = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n,MIN(entered_at) AS first_at,MAX(entry_id) AS last_id FROM {$p}wpcap_admission_entries WHERE ticket_id=%d AND direction='in' AND undone=0", $ticket_id ) );
		$last = $stats && (int) $stats->n ? $wpdb->get_row( $wpdb->prepare( "SELECT e.entered_at,a.name AS area_name FROM {$p}wpcap_admission_entries e LEFT JOIN {$p}wpcap_admission_areas a ON a.area_id=e.area_id WHERE e.entry_id=%d", (int) $stats->last_id ) ) : null;
		if ( ! $stats || ! (int) $stats->n ) {
			// Admissions from before the admission log: the check-in log.
			$legacy = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(result='checked_in')-SUM(result='undone') AS n,MIN(CASE WHEN result='checked_in' THEN created_at END) AS first_at,MAX(CASE WHEN result='checked_in' THEN created_at END) AS last_at FROM {$p}wpcap_checkins WHERE ticket_id=%d", $ticket_id ) );
			if ( ! $legacy || (int) $legacy->n <= 0 || ! $legacy->first_at ) { return array(); }
			return array( 'admissions' => (int) $legacy->n, 'first_admission' => 1 === (int) $legacy->n, 'first_checked_in_at' => gmdate( 'c', strtotime( $legacy->first_at . ' UTC' ) ), 'last_checked_in_at' => gmdate( 'c', strtotime( $legacy->last_at . ' UTC' ) ) );
		}
		$out = array( 'admissions' => (int) $stats->n, 'first_admission' => 1 === (int) $stats->n, 'first_checked_in_at' => gmdate( 'c', strtotime( $stats->first_at . ' UTC' ) ) );
		if ( $last ) {
			$out['last_checked_in_at'] = gmdate( 'c', strtotime( $last->entered_at . ' UTC' ) );
			if ( '' !== (string) $last->area_name ) { $out['last_checked_in_area'] = (string) $last->area_name; }
		}
		return $out;
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
		$doors = DoorsOpen::for_occurrence( (int) $found['occurrence_id'] );
		if ( null !== $doors ) { $view['doors_open_at'] = gmdate( 'c', strtotime( $doors . ' UTC' ) ); }
		// ID/age check of the ticket type: shown to staff with every result of a known ticket.
		$view += AdmissionChecks::scan_fields( (int) ( $found['ticket_type_id'] ?? 0 ) );
		if ( ! empty( $found['help_code'] ) ) { $view['help_code'] = true; }
		// Setting gates the scanner for everyone; Pii (A119) additionally hides it from roles without the name in their matrix.
		if ( Settings::get( 'scanner_show_attendee' ) && '' !== $found['attendee_name'] && Pii::can_view( 'attendee_name' ) ) {
			$view['attendee_name'] = $found['attendee_name'];
		}
		return $view;
	}
}
