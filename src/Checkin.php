<?php
/**
 * Ticket validation and check-in service shared by the web scanner, the mobile app API and
 * offline sync. Check-ins are atomic (row lock on the ticket) and optionally idempotent per
 * user + client key, so a retried request after a network error never counts twice.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Checkin {
	/** Extracts the 64-hex ticket code from a scanned value (raw code or ticket URL). */
	public static function normalize_code( $value ) {
		return preg_match( '/[a-f0-9]{64}/i', (string) $value, $m ) ? strtolower( $m[0] ) : '';
	}

	/**
	 * Looks a ticket up by code and checks admission preconditions (not the ticket status).
	 *
	 * @return array|\WP_Error
	 */
	public static function lookup( $code ) {
		$code = self::normalize_code( $code );
		if ( '' === $code ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.attendee_email,ta.attendee_name,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.status AS occurrence_status,e.status AS event_status,e.name AS event_name FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.token_hash=%s LIMIT 1",
			hash( 'sha256', $code )
		) );
		if ( ! $row ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
		if ( 'cancelled' === $row->occurrence_status || 'cancelled' === $row->event_status ) { return new \WP_Error( 'wpcap_ticket_cancelled_event', __( 'Dieser Termin wurde abgesagt; das Ticket berechtigt nicht zum Einlass.', 'wp-pass-captain' ), array( 'status' => 409, 'result' => 'cancelled_event', 'event_id' => (int) $row->event_id ) ); }
		if ( strtotime( $row->starts_at . ' UTC' ) < time() - DAY_IN_SECONDS || strtotime( $row->starts_at . ' UTC' ) > time() + 365 * DAY_IN_SECONDS ) { return new \WP_Error( 'wpcap_ticket_out_of_window', __( 'Das Ticket liegt außerhalb des Einlasszeitraums.', 'wp-pass-captain' ), array( 'status' => 409, 'result' => 'out_of_window', 'event_id' => (int) $row->event_id ) ); }
		return array( 'ticket_id' => (int) $row->ticket_id, 'public_id' => $row->public_id, 'event_id' => (int) $row->event_id, 'occurrence_id' => (int) $row->occurrence_id, 'status' => $row->status, 'event_name' => $row->event_name, 'ticket_name' => $row->ticket_name, 'starts_at' => $row->starts_at, 'timezone' => $row->timezone, 'attendee_name' => (string) $row->attendee_name );
	}

	/**
	 * Read-only preview for scanners. Personal data is limited to the attendee name, and only
	 * when the operator enabled `scanner_show_attendee`.
	 *
	 * @param array $context ['event_ids' => int[]|null (device restriction), 'occurrence_id' => int]
	 */
	public static function preview( $code, array $context = array() ) {
		$found = self::lookup( $code );
		if ( is_wp_error( $found ) ) { return $found; }
		$denied = self::deny( $found, $context );
		if ( $denied ) { return $denied; }
		return self::public_view( $found, $found['status'] );
	}

	/**
	 * Checks a ticket in.
	 *
	 * @param array $context ['device_id', 'idempotency_key', 'source' (web|app|offline), 'scanned_at' (UTC),
	 *                        'event_ids' (device restriction), 'occurrence_id' (expected occurrence)]
	 * @return array|\WP_Error Array with 'status' => 'checked_in' on success.
	 */
	public static function perform( $code, array $context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$user_id = get_current_user_id();
		$key = isset( $context['idempotency_key'] ) && '' !== (string) $context['idempotency_key'] ? hash( 'sha256', (string) $context['idempotency_key'] ) : null;
		if ( $key ) {
			$previous = $wpdb->get_row( $wpdb->prepare( "SELECT c.result,c.ticket_id FROM {$p}wpcap_checkins c WHERE c.user_id=%d AND c.idempotency_key=%s ORDER BY c.checkin_id DESC LIMIT 1", $user_id, $key ) );
			if ( $previous ) {
				$found = self::lookup( $code );
				if ( ! is_wp_error( $found ) && (int) $found['ticket_id'] === (int) $previous->ticket_id ) {
					return 'checked_in' === $previous->result
						? array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in', 'replayed' => true ) )
						: self::result_error( $previous->result, $found );
				}
			}
		}
		$found = self::lookup( $code );
		if ( is_wp_error( $found ) ) { return $found; }
		$denied = self::deny( $found, $context );
		if ( $denied ) { return $denied; }

		$log = static function ( $ticket_id, $result, $day_id = 0 ) use ( $wpdb, $p, $found, $user_id, $key, $context ) {
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
		};

		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", $found['ticket_id'] ) );
		if ( ! $ticket || 'valid' !== $ticket->status ) {
			// Never log a rejected scan as "checked_in" – reports count that value as admissions.
			$result = ! $ticket ? 'unknown' : ( 'checked_in' === $ticket->status ? 'already_checked_in' : $ticket->status );
			$log( $found['ticket_id'], $result );
			$wpdb->query( 'COMMIT' );
			return self::result_error( $result, $found );
		}
		$service_date = wp_date( 'Y-m-d', time(), new \DateTimeZone( $found['timezone'] ?: 'UTC' ) );
		$day_table = "{$p}wpcap_occurrence_days";
		$day = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_day_id,admission_policy,max_entries FROM $day_table WHERE occurrence_id=%d AND service_date=%s", $found['occurrence_id'], $service_date ) );
		$has_day_rules = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $day_table WHERE occurrence_id=%d", $found['occurrence_id'] ) );
		$repeatable = $day && in_array( $day->admission_policy, array( 'daily', 'reentry' ), true );
		$blocked = '';
		if ( $has_day_rules && ! $day ) { $blocked = 'not_valid_today'; }
		if ( $repeatable ) {
			$entries_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=%d AND occurrence_day_id=%d AND result='checked_in'", $ticket->ticket_id, $day->occurrence_day_id ) );
			if ( $entries_today >= (int) $day->max_entries ) { $blocked = 'daily_limit_reached'; }
		}
		if ( $blocked ) {
			$log( $ticket->ticket_id, $blocked, $day ? (int) $day->occurrence_day_id : 0 );
			$wpdb->query( 'COMMIT' );
			return self::result_error( $blocked, $found );
		}
		if ( ! $repeatable ) { $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $ticket->ticket_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) ); }
		$log( $ticket->ticket_id, 'checked_in', $day ? (int) $day->occurrence_day_id : 0 );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'ticket_checked_in', 'ticket', $ticket->ticket_id, array( 'event_id' => $found['event_id'], 'device_id' => absint( $context['device_id'] ?? 0 ), 'source' => sanitize_key( $context['source'] ?? 'web' ) ) );
		do_action( 'wpcap_ticket_checked_in', (int) $ticket->ticket_id, $context );
		return array_merge( self::public_view( $found, 'checked_in' ), array( 'status' => 'checked_in' ) );
	}

	/** Event scope and device restrictions; returns WP_Error or null. */
	private static function deny( array $found, array $context ) {
		if ( ! Access::can_access_event( $found['event_id'], 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'wp-pass-captain' ), array( 'status' => 403, 'result' => 'forbidden' ) );
		}
		if ( isset( $context['event_ids'] ) && is_array( $context['event_ids'] ) && $context['event_ids'] && ! in_array( (int) $found['event_id'], array_map( 'intval', $context['event_ids'] ), true ) ) {
			return new \WP_Error( 'wpcap_device_event', __( 'Dieses Gerät ist für ein anderes Event freigegeben.', 'wp-pass-captain' ), array( 'status' => 403, 'result' => 'wrong_event' ) );
		}
		if ( ! empty( $context['occurrence_id'] ) && (int) $context['occurrence_id'] !== (int) $found['occurrence_id'] ) {
			return new \WP_Error( 'wpcap_wrong_occurrence', __( 'Das Ticket gilt für einen anderen Termin.', 'wp-pass-captain' ), array( 'status' => 409, 'result' => 'wrong_occurrence' ) );
		}
		return null;
	}

	/** Stable error per result code. */
	private static function result_error( $result, array $found ) {
		$messages = array(
			'already_checked_in' => __( 'Ticket wurde bereits verwendet.', 'wp-pass-captain' ),
			'revoked' => __( 'Ticket ist widerrufen.', 'wp-pass-captain' ),
			'not_valid_today' => __( 'Ticket ist heute nicht gültig.', 'wp-pass-captain' ),
			'daily_limit_reached' => __( 'Das Tageskontingent für dieses Ticket ist erreicht.', 'wp-pass-captain' ),
		);
		$code = in_array( $result, array( 'not_valid_today', 'daily_limit_reached' ), true ) ? 'wpcap_ticket_day_rule' : 'wpcap_ticket_not_valid';
		return new \WP_Error( $code, $messages[ $result ] ?? __( 'Ticket wurde bereits verwendet oder ist widerrufen.', 'wp-pass-captain' ), array( 'status' => 409, 'ticket_status' => $result, 'result' => $result, 'event' => $found['event_name'], 'ticket' => $found['ticket_name'] ) );
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
