<?php
/**
 * Public write actions for suite plugins (PC-7; successor of the workflow commands A676): resend the
 * ticket mail, revoke a ticket, cancel an event, reschedule a date, confirm an allowed attendance.
 * Plain PHP functions on the same site (wpcap_resend_ticket_mail() …, src/functions.php) – no queue,
 * no envelope, no provider contract. Workflow Captain calls them from its own runs.
 *
 * - Capability: the current user, or the user named in $args['user_id'] (then the action runs as that
 *   user, the current user is restored afterwards). The event-scoped access of the underlying admin
 *   service (Access) applies as well.
 * - Service: the regular core flows with their checks, notifications, hooks and audit entries.
 *   Payment, refund, ticket issuance and ticket codes have no action.
 * - Idempotency (optional): $args['idempotency_key'] (8–64 characters [A-Za-z0-9._:-]). The same key
 *   with the same action and arguments returns the stored result (`replayed` = true) without running
 *   again; the same key with other arguments is a conflict. Temporary failures (5xx) do not use the
 *   key up. Stored in {prefix}wpcap_workflow_commands (historical table name, schema 3.10.27).
 * - Audit: every call that passed the argument check (`api_action`: action, object, user, hash of the
 *   key, result code) – no personal data. Results never contain ticket codes, help codes or
 *   personal data.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Api;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Actions {
	const VERSION = '1.0.0';

	/** Idempotency table (created as wpcap_workflow_commands by schema 3.10.27; name kept). */
	const TABLE = 'wpcap_workflow_commands';

	/** Action => capability. */
	const ACTIONS = array(
		'resend_ticket_mail'    => 'wpcap_manage_orders',
		'revoke_ticket'         => 'wpcap_manage_orders',
		'cancel_event'          => 'wpcap_manage_events',
		'reschedule_occurrence' => 'wpcap_manage_events',
		'confirm_attendance'    => 'wpcap_checkin',
	);

	/** Options every action understands in $args (besides its own). */
	const COMMON_ARGS = array( 'user_id', 'idempotency_key' );

	/** Methods an attendance confirmation may name (what the organiser verified). */
	const ATTENDANCE_METHODS = array( 'manual', 'list', 'session', 'online' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function ready() {
		return \PassCaptain\Privacy::has_table( self::TABLE );
	}

	public static function schema( $prefix, $charset ) {
		return "CREATE TABLE {$prefix}" . self::TABLE . " (
				command_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idempotency_key varchar(64) NOT NULL,
				command varchar(40) NOT NULL,
				args_hash char(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'running',
				result longtext NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (command_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;";
	}

	/* ------------------------------------------------------------------ The five actions */

	/**
	 * Sends the ticket mail of an order again (default: the stored codes, nothing becomes invalid;
	 * transferred tickets keep theirs).
	 *
	 * @param int|string $order Order id or public order id (32 hex characters).
	 * @param array      $args  new_codes (bool, default false: keep the codes), user_id, idempotency_key.
	 * @return array|\WP_Error {action, replayed, order_id (public), status: sent, codes: kept|new}
	 */
	public static function resend_ticket_mail( $order, array $args = array() ) {
		global $wpdb;
		$check = self::check_args( $args, array( 'new_codes' ) );
		if ( is_wp_error( $check ) ) { return $check; }
		$order_id = self::order_id( $order );
		$new_codes = ! empty( $args['new_codes'] );
		return self::run( 'resend_ticket_mail', $order_id, array( 'order_id' => $order_id, 'new_codes' => $new_codes ), $args, static function () use ( $wpdb, $order_id, $new_codes ) {
			if ( ! $order_id ) { return self::not_found(); }
			if ( ! \PassCaptain\Admin\Queries::can_access_order( $order_id, 'wpcap_manage_orders' ) ) {
				return self::error( 'wpcap_action_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ), 403 );
			}
			$sent = \PassCaptain\Orders::resend_tickets( $order_id, array( 'skip_transferred' => true ) + ( $new_codes ? array() : array( 'keep_codes' => true ) ) );
			if ( is_wp_error( $sent ) ) { return self::service( $sent ); }
			$public = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", $order_id ) );
			return array( 'order_id' => $public, 'status' => 'sent', 'codes' => $new_codes ? 'new' : 'kept' );
		} );
	}

	/**
	 * Revokes a ticket through the admin service (without refund; capacity is not released).
	 *
	 * @param int|string $ticket Ticket id or public ticket id.
	 * @param string     $reason Reason (required, stored in the audit log and passed to wpcap_ticket_revoked).
	 * @param array      $args   user_id, idempotency_key.
	 * @return array|\WP_Error {action, replayed, ticket_id (public), status: revoked}
	 */
	public static function revoke_ticket( $ticket, $reason, array $args = array() ) {
		$check = self::check_args( $args );
		if ( is_wp_error( $check ) ) { return $check; }
		$reason = self::text( $reason );
		if ( '' === $reason ) { return self::invalid( 'reason' ); }
		$row = self::ticket( $ticket );
		$ticket_id = $row ? (int) $row->ticket_id : 0;
		return self::run( 'revoke_ticket', $ticket_id, array( 'ticket_id' => $ticket_id, 'reason' => $reason ), $args, static function () use ( $row, $reason ) {
			if ( ! $row ) { return self::not_found(); }
			$r = self::service( \PassCaptain\Admin\TicketsPage::revoke_ticket( array( 'ticket_id' => (int) $row->ticket_id, 'reason' => $reason ) ) );
			return is_wp_error( $r ) ? $r : array( 'ticket_id' => (string) $row->public_id, 'status' => 'revoked' );
		} );
	}

	/**
	 * Cancels an event through the regular cancellation (including the configured automatic refund
	 * auto_refund_cancelled); an event that is already cancelled continues its unfinished run.
	 *
	 * @param int    $event_id Event id.
	 * @param string $reason   Reason (required).
	 * @param array  $args     user_id, idempotency_key.
	 * @return array|\WP_Error {action, replayed, event_id, status: cancelled, run_status, complete}
	 */
	public static function cancel_event( $event_id, $reason, array $args = array() ) {
		$check = self::check_args( $args );
		if ( is_wp_error( $check ) ) { return $check; }
		$event_id = is_numeric( $event_id ) ? absint( $event_id ) : 0;
		$reason = self::text( $reason );
		if ( ! $event_id ) { return self::invalid( 'event_id' ); }
		if ( '' === $reason ) { return self::invalid( 'reason' ); }
		return self::run( 'cancel_event', $event_id, array( 'event_id' => $event_id, 'reason' => $reason ), $args, static function () use ( $event_id, $reason ) {
			$r = self::service( \PassCaptain\Admin\EventsPage::cancel_event( array( 'event_id' => $event_id, 'reason' => $reason, 'confirm' => '1' ) ) );
			if ( is_wp_error( $r ) ) { return $r; }
			return array( 'event_id' => $event_id, 'status' => 'cancelled', 'run_status' => sanitize_key( (string) ( $r['status'] ?? '' ) ), 'complete' => ! empty( $r['complete'] ) );
		} );
	}

	/**
	 * Moves a date through the regular occurrence form (revision, notification, sales windows; end
	 * and doors move along when $ends_at is null).
	 *
	 * @param int         $occurrence_id Occurrence id.
	 * @param string      $starts_at     New start, ISO 8601 with time zone (e.g. 2027-05-01T19:30:00Z).
	 * @param string|null $ends_at       New end (ISO 8601) or null to keep the duration.
	 * @param array       $args          user_id, idempotency_key.
	 * @return array|\WP_Error {action, replayed, occurrence_id, status: rescheduled, starts_at (UTC, ISO 8601)}
	 */
	public static function reschedule_occurrence( $occurrence_id, $starts_at, $ends_at = null, array $args = array() ) {
		$check = self::check_args( $args );
		if ( is_wp_error( $check ) ) { return $check; }
		$occurrence_id = is_numeric( $occurrence_id ) ? absint( $occurrence_id ) : 0;
		if ( ! $occurrence_id ) { return self::invalid( 'occurrence_id' ); }
		$starts_at = is_scalar( $starts_at ) ? trim( (string) $starts_at ) : '';
		$ends_at = null === $ends_at || '' === $ends_at ? null : ( is_scalar( $ends_at ) ? trim( (string) $ends_at ) : '' );
		$iso = '/^\d{4}-\d\d-\d\dT\d\d:\d\d(:\d\d)?(\.\d+)?(Z|[+-]\d\d:?\d\d)$/';
		$start = preg_match( $iso, $starts_at ) ? strtotime( $starts_at ) : false;
		$end = null === $ends_at ? null : ( preg_match( $iso, $ends_at ) ? strtotime( $ends_at ) : false );
		if ( ! $start || false === $end ) {
			return self::error( 'wpcap_action_invalid', __( 'Beginn und Ende brauchen ISO 8601 mit Zeitzone (z. B. 2027-05-01T19:30:00Z).', 'pass-captain' ), 400, array( 'arg' => ! $start ? 'starts_at' : 'ends_at' ) );
		}
		return self::run( 'reschedule_occurrence', $occurrence_id, array( 'occurrence_id' => $occurrence_id, 'starts_at' => $start, 'ends_at' => $end ), $args, static function () use ( $occurrence_id, $start, $end ) {
			return self::reschedule( $occurrence_id, $start, $end );
		} );
	}

	/**
	 * An allowed attendance confirmation without a scan (the organiser verified participation, e.g. a
	 * session list): only for paid, not refunded, valid tickets of a date that has begun and is not
	 * cancelled. Fires wpcap_attendance_confirmed (review contract: experience_completed, method
	 * confirmed); a second confirmation of the same ticket changes nothing there.
	 *
	 * @param int|string $ticket Ticket id or public ticket id.
	 * @param string     $method manual, list, session or online.
	 * @param array      $args   user_id, idempotency_key.
	 * @return array|\WP_Error {action, replayed, ticket_id (public), status: confirmed, method}
	 */
	public static function confirm_attendance( $ticket, $method = 'manual', array $args = array() ) {
		$check = self::check_args( $args );
		if ( is_wp_error( $check ) ) { return $check; }
		$method = is_scalar( $method ) ? (string) $method : '';
		if ( ! in_array( $method, self::ATTENDANCE_METHODS, true ) ) {
			return self::error( 'wpcap_action_invalid', __( 'Unbekannte Art der Teilnahmebestätigung.', 'pass-captain' ), 400, array( 'arg' => 'method' ) );
		}
		$row = self::ticket( $ticket );
		$ticket_id = $row ? (int) $row->ticket_id : 0;
		return self::run( 'confirm_attendance', $ticket_id, array( 'ticket_id' => $ticket_id, 'method' => $method ), $args, static function () use ( $row, $method ) {
			return self::confirm( $row, $method );
		} );
	}

	/* ------------------------------------------------------------------ Runner */

	private static function error( $code, $message, $status, array $data = array() ) {
		return new \WP_Error( $code, $message, array( 'status' => (int) $status ) + $data );
	}

	private static function invalid( $arg ) {
		return self::error( 'wpcap_action_invalid', __( 'Ein Argument der Aktion ist ungültig.', 'pass-captain' ), 400, array( 'arg' => $arg ) );
	}

	private static function not_found() {
		return self::error( 'wpcap_action_not_found', __( 'Das Objekt der Aktion wurde nicht gefunden.', 'pass-captain' ), 404 );
	}

	/** Admin form errors carry the HTTP status as data. */
	private static function service( $result ) {
		if ( ! is_wp_error( $result ) ) { return $result; }
		$data = (array) $result->get_error_data();
		return self::error( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 409 ) );
	}

	private static function text( $value ) {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 255 ) : '';
	}

	/** Unknown keys in $args are refused (never ignored); user_id and idempotency_key are checked here. */
	private static function check_args( array $args, array $own = array() ) {
		foreach ( array_keys( $args ) as $key ) {
			if ( ! in_array( (string) $key, array_merge( self::COMMON_ARGS, $own ), true ) ) { return self::invalid( sanitize_key( (string) $key ) ); }
		}
		if ( isset( $args['user_id'] ) && ( ! is_numeric( $args['user_id'] ) || (int) $args['user_id'] < 1 ) ) { return self::invalid( 'user_id' ); }
		if ( isset( $args['idempotency_key'] ) && ( ! is_string( $args['idempotency_key'] ) || ! preg_match( '/^[A-Za-z0-9._:-]{8,64}$/', $args['idempotency_key'] ) ) ) {
			return self::error( 'wpcap_action_idempotency_key', __( 'Der Idempotency-Key muss 8–64 Zeichen aus A–Z, a–z, 0–9, Punkt, Unterstrich, Doppelpunkt oder Bindestrich haben.', 'pass-captain' ), 400, array( 'arg' => 'idempotency_key' ) );
		}
		return true;
	}

	/** Order id of an order id or a public order id (0 when unknown). */
	private static function order_id( $order ) {
		global $wpdb;
		if ( is_int( $order ) || ( is_string( $order ) && ctype_digit( $order ) ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", (int) $order ) );
		}
		$public = is_string( $order ) ? strtolower( $order ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $public ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public ) ) : 0;
	}

	/** Ticket row (ticket_id, public_id, status, refunded) of a ticket id or public ticket id; null when unknown. */
	private static function ticket( $ticket ) {
		global $wpdb;
		$sql = "SELECT ticket_id,public_id,order_id,status,refunded FROM {$wpdb->prefix}wpcap_tickets WHERE ";
		if ( is_int( $ticket ) || ( is_string( $ticket ) && ctype_digit( $ticket ) ) ) {
			return $wpdb->get_row( $wpdb->prepare( $sql . 'ticket_id=%d', (int) $ticket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment.
		}
		$public = is_string( $ticket ) ? strtolower( $ticket ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $public ) ? $wpdb->get_row( $wpdb->prepare( $sql . 'public_id=%s', $public ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment.
	}

	private static function audit( $action, $object_id, $user_id, $key, $result ) {
		Audit::record( 'api_action', 'api', (int) $object_id, array( 'action' => $action, 'user_id' => (int) $user_id, 'key' => '' === (string) $key ? '' : substr( hash( 'sha256', (string) $key ), 0, 16 ), 'result' => $result ) );
	}

	/**
	 * Capability, user switch, idempotency, audit around one action.
	 *
	 * @param string   $action    Key of ACTIONS.
	 * @param int      $object_id Internal id of the object (audit; 0 when unknown).
	 * @param array    $normal    Normalised arguments (idempotency hash).
	 * @param array    $args      Caller options (user_id, idempotency_key).
	 * @param callable $body      The action; array result or WP_Error.
	 */
	private static function run( $action, $object_id, array $normal, array $args, callable $body ) {
		global $wpdb;
		$capability = self::ACTIONS[ $action ];
		$previous = (int) get_current_user_id();
		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : $previous;
		$key = (string) ( $args['idempotency_key'] ?? '' );
		if ( $user_id !== $previous && ! get_userdata( $user_id ) ) {
			return self::invalid( 'user_id' );
		}
		$allowed = $user_id === $previous ? current_user_can( $capability ) : user_can( $user_id, $capability );
		if ( ! $allowed ) {
			self::audit( $action, $object_id, $user_id, $key, 'forbidden' );
			return self::error( 'wpcap_action_forbidden', __( 'Keine Berechtigung für diese Aktion.', 'pass-captain' ), 403 );
		}
		$t = self::table();
		if ( '' !== $key ) {
			if ( ! self::ready() ) {
				return self::error( 'wpcap_action_unavailable', __( 'Die Aktion konnte gerade nicht ausgeführt werden. Bitte erneut versuchen.', 'pass-captain' ), 503, array( 'retryable' => true ) );
			}
			ksort( $normal );
			$hash = hash( 'sha256', $action . '|' . wp_json_encode( $normal ) );
			$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (idempotency_key,command,args_hash,status,user_id,created_at) VALUES (%s,%s,%s,'running',%d,%s)", $key, $action, $hash, $user_id, \PassCaptain\Clock::mysql() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			if ( 1 !== $inserted ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE idempotency_key=%s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				if ( ! $row ) {
					return self::error( 'wpcap_action_unavailable', __( 'Die Aktion konnte gerade nicht ausgeführt werden. Bitte erneut versuchen.', 'pass-captain' ), 503, array( 'retryable' => true ) );
				}
				if ( $row->command !== $action || ! hash_equals( (string) $row->args_hash, $hash ) ) {
					return self::error( 'wpcap_action_idempotency_conflict', __( 'Dieser Idempotency-Key wurde bereits für eine andere Aktion verwendet.', 'pass-captain' ), 409 );
				}
				if ( 'running' === $row->status ) {
					return self::error( 'wpcap_action_in_progress', __( 'Diese Aktion wird gerade ausgeführt.', 'pass-captain' ), 409, array( 'retryable' => true ) );
				}
				$stored = json_decode( (string) $row->result, true );
				if ( 'failed' === $row->status ) {
					return self::error( (string) ( $stored['code'] ?? 'wpcap_action_failed' ), (string) ( $stored['message'] ?? '' ), (int) ( $stored['status'] ?? 409 ), array( 'replayed' => true ) );
				}
				return array( 'action' => $action, 'replayed' => true ) + ( is_array( $stored ) ? $stored : array() );
			}
		}
		$switched = $user_id !== $previous;
		if ( $switched ) { wp_set_current_user( $user_id ); }
		try {
			$result = $body();
		} catch ( \Throwable $e ) {
			$result = self::error( 'wpcap_action_failed', __( 'Die Aktion ist fehlgeschlagen.', 'pass-captain' ), 500 );
		} finally {
			if ( $switched ) { wp_set_current_user( $previous ); }
		}
		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( (array) $result->get_error_data() )['status'] ?? 409 );
			if ( '' !== $key ) {
				if ( $status >= 500 ) {
					// Temporary: the key is not used up, the caller may retry it.
					$wpdb->delete( $t, array( 'idempotency_key' => $key ) );
				} else {
					$wpdb->update( $t, array( 'status' => 'failed', 'result' => wp_json_encode( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'status' => $status ) ), 'finished_at' => \PassCaptain\Clock::mysql() ), array( 'idempotency_key' => $key ) );
				}
			}
			self::audit( $action, $object_id, $user_id, $key, sanitize_key( $result->get_error_code() ) );
			return $result;
		}
		if ( '' !== $key ) {
			$wpdb->update( $t, array( 'status' => 'done', 'result' => wp_json_encode( $result ), 'finished_at' => \PassCaptain\Clock::mysql() ), array( 'idempotency_key' => $key ) );
		}
		self::audit( $action, $object_id, $user_id, $key, 'ok' );
		return array( 'action' => $action, 'replayed' => false ) + $result;
	}

	/* ------------------------------------------------------------------ Action bodies */

	private static function reschedule( $occurrence_id, $start, $end ) {
		$occ = \PassCaptain\Admin\Queries::occurrence( $occurrence_id );
		if ( ! $occ ) { return self::not_found(); }
		$local = static function ( $ts ) use ( $occ ) { return \PassCaptain\Admin\Forms::utc_to_local( gmdate( 'Y-m-d H:i:s', $ts ), $occ->timezone ); };
		$shift = $start - strtotime( $occ->starts_at . ' UTC' );
		$in = array(
			'occurrence_id' => (int) $occ->occurrence_id,
			'timezone' => (string) $occ->timezone,
			'starts_at' => $local( $start ),
			'ends_at' => null === $end ? ( empty( $occ->ends_at ) ? '' : $local( strtotime( $occ->ends_at . ' UTC' ) + $shift ) ) : $local( $end ),
			'location' => (string) $occ->location,
			'capacity' => (int) $occ->capacity,
			'doors_open_at' => empty( $occ->doors_open_at ) ? '' : $local( strtotime( $occ->doors_open_at . ' UTC' ) + $shift ),
		);
		$r = self::service( \PassCaptain\Admin\EventsPage::save_occurrence( $in ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$now = \PassCaptain\Admin\Queries::occurrence( $occurrence_id );
		return array( 'occurrence_id' => (int) $occurrence_id, 'status' => 'rescheduled', 'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $now->starts_at . ' UTC' ) ) );
	}

	private static function confirm( $ticket, $method ) {
		global $wpdb;
		if ( ! $ticket ) { return self::not_found(); }
		$p = $wpdb->prefix;
		$ctx = $wpdb->get_row( $wpdb->prepare( 'SELECT o.occurrence_id,o.starts_at,o.status AS occurrence_status,ord.payment_status FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d', $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', (int) $ticket->ticket_id ) );
		if ( ! $ctx ) { return self::not_found(); }
		if ( ! \PassCaptain\Access::can_access_occurrence( (int) $ctx->occurrence_id, 'wpcap_checkin' ) ) {
			return self::error( 'wpcap_action_forbidden', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), 403 );
		}
		if ( 'paid' !== $ctx->payment_status || (int) $ticket->refunded || ! in_array( (string) $ticket->status, array( 'valid', 'checked_in' ), true ) || 'cancelled' === $ctx->occurrence_status ) {
			return self::error( 'wpcap_action_attendance_not_allowed', __( 'Für dieses Ticket kann keine Teilnahme bestätigt werden.', 'pass-captain' ), 409 );
		}
		if ( strtotime( $ctx->starts_at . ' UTC' ) > \PassCaptain\Clock::now() ) {
			return self::error( 'wpcap_action_attendance_too_early', __( 'Eine Teilnahme kann erst ab Beginn des Termins bestätigt werden.', 'pass-captain' ), 409 );
		}
		Audit::record( 'attendance_confirmed', 'ticket', (int) $ticket->ticket_id, array( 'method' => $method, 'source' => 'api' ) );
		/** An allowed attendance confirmation (not a scan), after the audit entry; context: method, source (api). */
		do_action( 'wpcap_attendance_confirmed', (int) $ticket->ticket_id, array( 'method' => $method, 'source' => 'api' ) );
		return array( 'ticket_id' => (string) $ticket->public_id, 'status' => 'confirmed', 'method' => $method );
	}
}
