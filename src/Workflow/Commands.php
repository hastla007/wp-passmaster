<?php
/**
 * v1 commands of the workflow contract (A676, PASS-WF-04): the few actions a workflow provider may
 * ask Pass Captain to perform – resend the ticket mail, revoke a ticket, cancel an event, reschedule
 * a date and confirm an allowed attendance. Everything else (payment, refund, ticket issuance, codes)
 * stays with Pass Captain's own flows and has no command.
 *
 * - Allowlist: COMMANDS (command => capability, argument types, required arguments). Unknown commands
 *   and unknown arguments are refused, never ignored.
 * - Capability: the current user (the provider runs as a user) needs the command's capability, and
 *   the event-scoped access of the underlying admin service (Access) applies as well.
 * - Idempotency key (required): the same key with the same command and arguments returns the stored
 *   result (`replayed` = true) without running again; the same key with other arguments is a conflict.
 *   Temporary failures (5xx) do not use the key up.
 * - Audit: every executed command (`workflow_command`: command, key hash, result code) – no personal
 *   data. Results never contain ticket codes, help codes or personal data.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Workflow;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Commands {
	const VERSION = '1.0.0';
	const TABLE = 'wpcap_workflow_commands';

	const COMMANDS = array(
		'mail.resend' => array(
			'capability' => 'wpcap_manage_orders',
			'args' => array( 'order_id' => 'string', 'new_codes' => 'bool' ),
			'required' => array( 'order_id' ),
		),
		'ticket.revoke' => array(
			'capability' => 'wpcap_manage_orders',
			'args' => array( 'ticket_id' => 'string', 'reason' => 'string' ),
			'required' => array( 'ticket_id', 'reason' ),
		),
		'event.cancel' => array(
			'capability' => 'wpcap_manage_events',
			'args' => array( 'event_id' => 'int', 'reason' => 'string' ),
			'required' => array( 'event_id', 'reason' ),
		),
		'occurrence.reschedule' => array(
			'capability' => 'wpcap_manage_events',
			'args' => array( 'occurrence_id' => 'int', 'starts_at' => 'string', 'ends_at' => '?string' ),
			'required' => array( 'occurrence_id', 'starts_at' ),
		),
		'attendance.confirm' => array(
			'capability' => 'wpcap_checkin',
			'args' => array( 'ticket_id' => 'string', 'method' => 'string' ),
			'required' => array( 'ticket_id' ),
		),
	);

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

	/** Machine-readable description (provider documentation, fixtures). */
	public static function describe() {
		return array( 'version' => self::VERSION, 'commands' => self::COMMANDS );
	}

	private static function error( $code, $message, $status, array $data = array() ) {
		return new \WP_Error( $code, $message, array( 'status' => (int) $status ) + $data );
	}

	/**
	 * Runs a command.
	 *
	 * @param string $command         Command of COMMANDS.
	 * @param array  $args            Arguments (allowlist of the command).
	 * @param string $idempotency_key 8–64 characters [A-Za-z0-9._:-].
	 * @return array|\WP_Error {command, idempotency_key, replayed, result}
	 */
	public static function execute( $command, array $args, $idempotency_key ) {
		global $wpdb;
		$command = (string) $command;
		$def = self::COMMANDS[ $command ] ?? null;
		if ( ! $def ) {
			return self::error( 'wpcap_workflow_command_unknown', __( 'Dieser Befehl ist nicht freigegeben.', 'pass-captain' ), 400 );
		}
		$key = (string) $idempotency_key;
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,64}$/', $key ) ) {
			return self::error( 'wpcap_workflow_idempotency_key', __( 'Ein Idempotency-Key (8–64 Zeichen) ist erforderlich.', 'pass-captain' ), 400 );
		}
		$clean = array();
		foreach ( $args as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $def['args'][ $name ] ) ) {
				return self::error( 'wpcap_workflow_command_arg_not_allowed', __( 'Der Befehl enthält ein nicht erlaubtes Argument.', 'pass-captain' ), 400, array( 'arg' => sanitize_key( $name ) ) );
			}
			$type = ltrim( $def['args'][ $name ], '?' );
			if ( null === $value && '?' === $def['args'][ $name ][0] ) { $clean[ $name ] = null; continue; }
			if ( 'int' === $type ) {
				if ( ! is_numeric( $value ) || (int) $value < 1 ) { return self::error( 'wpcap_workflow_command_invalid', __( 'Ein Argument des Befehls ist ungültig.', 'pass-captain' ), 400, array( 'arg' => $name ) ); }
				$clean[ $name ] = (int) $value;
			} elseif ( 'bool' === $type ) {
				$clean[ $name ] = (bool) $value;
			} else {
				if ( ! is_scalar( $value ) ) { return self::error( 'wpcap_workflow_command_invalid', __( 'Ein Argument des Befehls ist ungültig.', 'pass-captain' ), 400, array( 'arg' => $name ) ); }
				$clean[ $name ] = mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
			}
		}
		foreach ( $def['required'] as $name ) {
			if ( ! isset( $clean[ $name ] ) || '' === $clean[ $name ] ) {
				return self::error( 'wpcap_workflow_command_invalid', __( 'Ein Pflichtargument des Befehls fehlt.', 'pass-captain' ), 400, array( 'arg' => $name ) );
			}
		}
		if ( ! current_user_can( $def['capability'] ) ) {
			self::audit( $command, $key, 'forbidden' );
			return self::error( 'wpcap_workflow_forbidden', __( 'Keine Berechtigung für diesen Befehl.', 'pass-captain' ), 403 );
		}
		if ( ! self::ready() ) {
			return self::error( 'wpcap_workflow_command_unavailable', __( 'Befehle sind nach dem Datenbank-Update verfügbar.', 'pass-captain' ), 503, array( 'retryable' => true ) );
		}
		ksort( $clean );
		$hash = hash( 'sha256', $command . '|' . wp_json_encode( $clean ) );
		$t = self::table();
		$now = \PassCaptain\Clock::mysql();
		$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (idempotency_key,command,args_hash,status,user_id,created_at) VALUES (%s,%s,%s,'running',%d,%s)", $key, $command, $hash, get_current_user_id(), $now ) );
		if ( 1 !== $inserted ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE idempotency_key=%s", $key ) );
			if ( ! $row ) {
				return self::error( 'wpcap_workflow_command_unavailable', __( 'Der Befehl konnte gerade nicht ausgeführt werden. Bitte erneut versuchen.', 'pass-captain' ), 503, array( 'retryable' => true ) );
			}
			if ( $row->command !== $command || ! hash_equals( (string) $row->args_hash, $hash ) ) {
				return self::error( 'wpcap_workflow_idempotency_conflict', __( 'Dieser Idempotency-Key wurde bereits für einen anderen Befehl verwendet.', 'pass-captain' ), 409 );
			}
			if ( 'running' === $row->status ) {
				return self::error( 'wpcap_workflow_command_in_progress', __( 'Dieser Befehl wird gerade ausgeführt.', 'pass-captain' ), 409, array( 'retryable' => true ) );
			}
			$stored = json_decode( (string) $row->result, true );
			if ( 'failed' === $row->status ) {
				return self::error( (string) ( $stored['code'] ?? 'wpcap_workflow_command_failed' ), (string) ( $stored['message'] ?? '' ), (int) ( $stored['status'] ?? 409 ), array( 'replayed' => true ) );
			}
			return array( 'command' => $command, 'idempotency_key' => $key, 'replayed' => true, 'result' => is_array( $stored ) ? $stored : array() );
		}
		try {
			$result = self::run( $command, $clean );
		} catch ( \Throwable $e ) {
			$result = self::error( 'wpcap_workflow_command_failed', __( 'Der Befehl ist fehlgeschlagen.', 'pass-captain' ), 500 );
		}
		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( (array) $result->get_error_data() )['status'] ?? 409 );
			if ( $status >= 500 ) {
				// Temporary: the key is not used up, the provider may retry it.
				$wpdb->delete( $t, array( 'idempotency_key' => $key ) );
			} else {
				$wpdb->update( $t, array( 'status' => 'failed', 'result' => wp_json_encode( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'status' => $status ) ), 'finished_at' => \PassCaptain\Clock::mysql() ), array( 'idempotency_key' => $key ) );
			}
			self::audit( $command, $key, sanitize_key( $result->get_error_code() ) );
			return $result;
		}
		$wpdb->update( $t, array( 'status' => 'done', 'result' => wp_json_encode( $result ), 'finished_at' => \PassCaptain\Clock::mysql() ), array( 'idempotency_key' => $key ) );
		self::audit( $command, $key, 'ok' );
		return array( 'command' => $command, 'idempotency_key' => $key, 'replayed' => false, 'result' => $result );
	}

	private static function audit( $command, $key, $result ) {
		Audit::record( 'workflow_command', 'workflow', 0, array( 'command' => $command, 'key' => substr( hash( 'sha256', (string) $key ), 0, 16 ), 'result' => $result ) );
	}

	/** Admin form errors carry the HTTP status as data. */
	private static function service( $result ) {
		if ( ! is_wp_error( $result ) ) { return $result; }
		$data = (array) $result->get_error_data();
		return self::error( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 409 ) );
	}

	private static function ticket_by_public_id( $public_id ) {
		global $wpdb;
		$public_id = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $public_id ) );
		return 32 === strlen( $public_id ) ? $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,public_id,order_id,status,refunded FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s", $public_id ) ) : null;
	}

	private static function not_found() {
		return self::error( 'wpcap_workflow_command_not_found', __( 'Das Objekt des Befehls wurde nicht gefunden.', 'pass-captain' ), 404 );
	}

	/** The command itself (arguments already checked). */
	private static function run( $command, array $a ) {
		global $wpdb;
		switch ( $command ) {
			case 'mail.resend':
				$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", strtolower( (string) $a['order_id'] ) ) );
				if ( ! $order_id ) { return self::not_found(); }
				if ( ! \PassCaptain\Admin\Queries::can_access_order( $order_id, 'wpcap_manage_orders' ) ) { return self::error( 'wpcap_workflow_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ), 403 ); }
				// Default: the stored codes again (nothing becomes invalid); transferred tickets keep theirs.
				$sent = \PassCaptain\Orders::resend_tickets( $order_id, array( 'skip_transferred' => true ) + ( empty( $a['new_codes'] ) ? array( 'keep_codes' => true ) : array() ) );
				if ( is_wp_error( $sent ) ) { return self::service( $sent ); }
				return array( 'order_id' => (string) $a['order_id'], 'status' => 'sent', 'codes' => empty( $a['new_codes'] ) ? 'kept' : 'new' );
			case 'ticket.revoke':
				$ticket = self::ticket_by_public_id( $a['ticket_id'] );
				if ( ! $ticket ) { return self::not_found(); }
				$r = self::service( \PassCaptain\Admin\TicketsPage::revoke_ticket( array( 'ticket_id' => (int) $ticket->ticket_id, 'reason' => (string) $a['reason'] ) ) );
				return is_wp_error( $r ) ? $r : array( 'ticket_id' => (string) $ticket->public_id, 'status' => 'revoked' );
			case 'event.cancel':
				$r = self::service( \PassCaptain\Admin\EventsPage::cancel_event( array( 'event_id' => (int) $a['event_id'], 'reason' => (string) $a['reason'], 'confirm' => '1' ) ) );
				return is_wp_error( $r ) ? $r : array( 'event_id' => (int) $a['event_id'], 'status' => 'cancelled', 'run_status' => sanitize_key( (string) ( $r['status'] ?? '' ) ) );
			case 'occurrence.reschedule':
				return self::reschedule( (int) $a['occurrence_id'], (string) $a['starts_at'], $a['ends_at'] ?? null );
			case 'attendance.confirm':
				return self::confirm_attendance( (string) $a['ticket_id'], (string) ( $a['method'] ?? 'manual' ) );
		}
		return self::error( 'wpcap_workflow_command_unknown', __( 'Dieser Befehl ist nicht freigegeben.', 'pass-captain' ), 400 );
	}

	/** Moves a date through the regular occurrence form (notifications, revisions, sales windows). */
	private static function reschedule( $occurrence_id, $starts_at, $ends_at ) {
		$occ = \PassCaptain\Admin\Queries::occurrence( $occurrence_id );
		if ( ! $occ ) { return self::not_found(); }
		$start = strtotime( $starts_at );
		$end = null === $ends_at || '' === $ends_at ? null : strtotime( (string) $ends_at );
		if ( ! $start || false === $end || ! preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $starts_at ) ) {
			return self::error( 'wpcap_workflow_command_invalid', __( 'Beginn und Ende brauchen ISO 8601 mit Zeitzone (z. B. 2027-05-01T19:30:00Z).', 'pass-captain' ), 400, array( 'arg' => 'starts_at' ) );
		}
		$local = static function ( $ts ) use ( $occ ) { return \PassCaptain\Admin\Forms::utc_to_local( gmdate( 'Y-m-d H:i:s', $ts ), $occ->timezone ); };
		$in = array(
			'occurrence_id' => (int) $occ->occurrence_id,
			'timezone' => (string) $occ->timezone,
			'starts_at' => $local( $start ),
			'ends_at' => null === $end ? ( empty( $occ->ends_at ) ? '' : $local( strtotime( $occ->ends_at . ' UTC' ) + ( $start - strtotime( $occ->starts_at . ' UTC' ) ) ) ) : $local( $end ),
			'location' => (string) $occ->location,
			'capacity' => (int) $occ->capacity,
			'doors_open_at' => empty( $occ->doors_open_at ) ? '' : $local( strtotime( $occ->doors_open_at . ' UTC' ) + ( $start - strtotime( $occ->starts_at . ' UTC' ) ) ),
		);
		$r = self::service( \PassCaptain\Admin\EventsPage::save_occurrence( $in ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$now = \PassCaptain\Admin\Queries::occurrence( $occurrence_id );
		return array( 'occurrence_id' => (int) $occurrence_id, 'status' => 'rescheduled', 'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $now->starts_at . ' UTC' ) ) );
	}

	/**
	 * An allowed attendance confirmation (organiser verified participation without a scan, e.g. a
	 * session list): only for paid, not refunded, valid tickets of a date that has begun.
	 */
	private static function confirm_attendance( $public_id, $method ) {
		global $wpdb;
		$ticket = self::ticket_by_public_id( $public_id );
		if ( ! $ticket ) { return self::not_found(); }
		$method = in_array( $method, self::ATTENDANCE_METHODS, true ) ? $method : '';
		if ( '' === $method ) {
			return self::error( 'wpcap_workflow_command_invalid', __( 'Unbekannte Art der Teilnahmebestätigung.', 'pass-captain' ), 400, array( 'arg' => 'method' ) );
		}
		$p = $wpdb->prefix;
		$ctx = $wpdb->get_row( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.status AS occurrence_status,ord.payment_status FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", (int) $ticket->ticket_id ) );
		if ( ! \PassCaptain\Access::can_access_occurrence( (int) $ctx->occurrence_id, 'wpcap_checkin' ) ) {
			return self::error( 'wpcap_workflow_forbidden', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), 403 );
		}
		if ( 'paid' !== $ctx->payment_status || (int) $ticket->refunded || ! in_array( (string) $ticket->status, array( 'valid', 'checked_in' ), true ) || 'cancelled' === $ctx->occurrence_status ) {
			return self::error( 'wpcap_workflow_attendance_not_allowed', __( 'Für dieses Ticket kann keine Teilnahme bestätigt werden.', 'pass-captain' ), 409 );
		}
		if ( strtotime( $ctx->starts_at . ' UTC' ) > \PassCaptain\Clock::now() ) {
			return self::error( 'wpcap_workflow_attendance_too_early', __( 'Eine Teilnahme kann erst ab Beginn des Termins bestätigt werden.', 'pass-captain' ), 409 );
		}
		Audit::record( 'attendance_confirmed', 'ticket', (int) $ticket->ticket_id, array( 'method' => $method, 'source' => 'command' ) );
		/** A676: an allowed attendance confirmation (not a scan); context: method, source. */
		do_action( 'wpcap_attendance_confirmed', (int) $ticket->ticket_id, array( 'method' => $method, 'source' => 'command' ) );
		return array( 'ticket_id' => (string) $ticket->public_id, 'status' => 'confirmed', 'method' => $method );
	}
}
