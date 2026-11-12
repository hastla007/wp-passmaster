<?php
/**
 * Admission state per ticket (inside/outside, entry count) and entries per day.
 *
 * All methods that read or write state are called by PassCaptain\Checkin inside its transaction,
 * after the ticket row has been locked (SELECT … FOR UPDATE), so they are serialised per ticket.
 * Undo rows ("undone") written to wpcap_checkins by other code (admin ticket screen) are reconciled
 * lazily: the latest entries are marked undone and the ticket counts as outside again.
 */
namespace PassCaptain\Admission;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Engine {
	/** Block codes returned by evaluate_in(). */
	const CODES = array( 'outside_entry_window', 'not_valid_today', 'daily_limit_reached', 'entry_limit_reached', 'already_inside' );

	public static function timezone( $tz ) {
		try {
			return new \DateTimeZone( $tz ?: 'UTC' );
		} catch ( \Throwable $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/** Local date (Y-m-d) of a UTC timestamp in a timezone. */
	public static function service_date( $ts, $tz ) {
		return ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( self::timezone( $tz ) )->format( 'Y-m-d' );
	}

	/** '' when inside the entry window, otherwise 'outside_entry_window'. */
	public static function window_block( array $policy, array $found, $at ) {
		$start = strtotime( $found['starts_at'] . ' UTC' );
		$end = ! empty( $found['ends_at'] ) ? strtotime( $found['ends_at'] . ' UTC' ) : $start;
		if ( null !== $policy['opens_before_minutes'] && $at < $start - $policy['opens_before_minutes'] * 60 ) { return 'outside_entry_window'; }
		if ( null !== $policy['closes_after_minutes'] && $at > max( $start, $end ) + $policy['closes_after_minutes'] * 60 ) { return 'outside_entry_window'; }
		if ( $policy['day_from'] || $policy['day_until'] ) {
			$hm = ( new \DateTimeImmutable( '@' . (int) $at ) )->setTimezone( self::timezone( $found['timezone'] ) )->format( 'H:i' );
			if ( ( $policy['day_from'] && $hm < $policy['day_from'] ) || ( $policy['day_until'] && $hm >= $policy['day_until'] ) ) { return 'outside_entry_window'; }
		}
		return '';
	}

	/**
	 * Evaluates a check-in against the policy. Must run under the ticket row lock.
	 *
	 * @return string '' when admitted, otherwise a block code.
	 */
	public static function evaluate_in( array $policy, array $found, $state, $at ) {
		$window = self::window_block( $policy, $found, $at );
		if ( $window ) { return $window; }
		global $wpdb;
		$entries = "{$wpdb->prefix}wpcap_admission_entries";
		$ticket_id = (int) $found['ticket_id'];
		switch ( $policy['policy'] ) {
			case 'daily':
			case 'multi_day':
				$date = self::service_date( $at, $found['timezone'] );
				$days = Policies::valid_days( $policy, $found );
				if ( null !== $days && ! in_array( $date, $days, true ) ) { return 'not_valid_today'; }
				$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $entries WHERE ticket_id=%d AND service_date=%s AND direction='in' AND undone=0", $ticket_id, $date ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $today >= 1 ? 'daily_limit_reached' : '';
			case 'multi_entry':
				return self::entry_count( $ticket_id ) >= max( 1, $policy['max_entries'] ) ? 'entry_limit_reached' : '';
			case 'reentry':
				if ( $state && (int) $state->inside ) { return 'already_inside'; }
				return $policy['max_entries'] > 0 && self::entry_count( $ticket_id ) >= $policy['max_entries'] ? 'entry_limit_reached' : '';
		}
		return '';
	}

	/** Effective (not undone) entries of a ticket. */
	public static function entry_count( $ticket_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_admission_entries WHERE ticket_id=%d AND direction='in' AND undone=0", (int) $ticket_id ) );
	}

	/** State row of a ticket, locked and reconciled with later "undone" log rows. Null if none. */
	public static function state_for_update( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// No FOR UPDATE: callers already hold the ticket row lock, which serialises scans of one ticket.
		// Locking a not-yet-existing state row took gap locks that deadlocked concurrent scans of
		// different tickets against the later INSERT … ON DUPLICATE KEY.
		$state = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_admission_state WHERE ticket_id=%d", (int) $ticket_id ) );
		if ( ! $state ) { return null; }
		$undone = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(checkin_id) AS last_id FROM {$p}wpcap_checkins WHERE ticket_id=%d AND result='undone' AND checkin_id>%d", (int) $ticket_id, (int) $state->last_checkin_id ) );
		if ( $undone && (int) $undone->n > 0 ) {
			$n = (int) $undone->n;
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_admission_entries SET undone=1 WHERE ticket_id=%d AND direction='in' AND undone=0 ORDER BY entry_id DESC LIMIT %d", (int) $ticket_id, $n ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_admission_state SET inside=0, entries=GREATEST(CAST(entries AS SIGNED)-%d,0), last_checkin_id=%d, updated_at=%s WHERE ticket_id=%d", $n, (int) $undone->last_id, current_time( 'mysql', true ), (int) $ticket_id ) );
			$state = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_admission_state WHERE ticket_id=%d", (int) $ticket_id ) );
		}
		return $state;
	}

	/** Whether a ticket is currently inside (state row, or legacy log for tickets without state). */
	public static function is_inside( $state, $ticket_id ) {
		if ( $state ) { return (bool) (int) $state->inside; }
		global $wpdb;
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT result FROM {$wpdb->prefix}wpcap_checkins WHERE ticket_id=%d AND result IN ('checked_in','checked_out','undone') ORDER BY checkin_id DESC LIMIT 1", (int) $ticket_id ) );
		return 'checked_in' === $last;
	}

	/** Records a successful entry (inside the check-in transaction). */
	public static function record_in( array $found, $checkin_id, array $context, $at, $user_id ) {
		return self::record( $found, $checkin_id, $context, $at, $user_id, 'in' );
	}

	/** Records a successful check-out (inside the check-out transaction). */
	public static function record_out( array $found, $checkin_id, array $context, $at, $user_id ) {
		return self::record( $found, $checkin_id, $context, $at, $user_id, 'out' );
	}

	private static function record( array $found, $checkin_id, array $context, $at, $user_id, $direction ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$when = gmdate( 'Y-m-d H:i:s', (int) $at );
		$area = absint( $context['area_id'] ?? 0 );
		$wpdb->insert( "{$p}wpcap_admission_entries", array(
			'ticket_id' => (int) $found['ticket_id'],
			'occurrence_id' => (int) $found['occurrence_id'],
			'area_id' => $area,
			'service_date' => self::service_date( $at, $found['timezone'] ),
			'direction' => $direction,
			'checkin_id' => (int) $checkin_id,
			'device_id' => absint( $context['device_id'] ?? 0 ),
			'user_id' => (int) $user_id,
			'undone' => 0,
			'entered_at' => $when,
			'created_at' => $now,
		) );
		if ( '' !== $wpdb->last_error ) { return false; }
		if ( 'in' === $direction ) {
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$p}wpcap_admission_state (ticket_id,occurrence_id,event_id,inside,area_id,entries,last_in_at,last_checkin_id,updated_at) VALUES (%d,%d,%d,1,%d,1,%s,%d,%s) ON DUPLICATE KEY UPDATE inside=1, area_id=VALUES(area_id), entries=entries+1, last_in_at=VALUES(last_in_at), last_checkin_id=VALUES(last_checkin_id), updated_at=VALUES(updated_at)",
				(int) $found['ticket_id'], (int) $found['occurrence_id'], (int) $found['event_id'], $area, $when, (int) $checkin_id, $now
			) );
		} else {
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$p}wpcap_admission_state (ticket_id,occurrence_id,event_id,inside,area_id,entries,last_out_at,last_checkin_id,updated_at) VALUES (%d,%d,%d,0,%d,0,%s,%d,%s) ON DUPLICATE KEY UPDATE inside=0, last_out_at=VALUES(last_out_at), last_checkin_id=VALUES(last_checkin_id), updated_at=VALUES(updated_at)",
				(int) $found['ticket_id'], (int) $found['occurrence_id'], (int) $found['event_id'], $area, $when, (int) $checkin_id, $now
			) );
		}
		return false !== $ok && '' === $wpdb->last_error;
	}

	/**
	 * Takes back the latest entry of a ticket with a repeatable policy (ticket status stays valid).
	 *
	 * @return array|\WP_Error
	 */
	public static function undo_last( array $found, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_row( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", (int) $found['ticket_id'] ) );
		self::state_for_update( $found['ticket_id'] );
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT entry_id FROM {$p}wpcap_admission_entries WHERE ticket_id=%d AND direction='in' AND undone=0 ORDER BY entry_id DESC LIMIT 1", (int) $found['ticket_id'] ) );
		if ( ! $entry ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'wp-pass-captain' ), array( 'status' => 409, 'result' => 'not_checked_in' ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_day_id' => 0, 'user_id' => get_current_user_id(), 'result' => 'undone', 'created_at' => $now ) );
		$undo_id = (int) $wpdb->insert_id;
		$wpdb->update( "{$p}wpcap_admission_entries", array( 'undone' => 1 ), array( 'entry_id' => (int) $entry->entry_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_admission_state SET inside=0, entries=GREATEST(CAST(entries AS SIGNED)-1,0), last_checkin_id=%d, updated_at=%s WHERE ticket_id=%d", $undo_id, $now, (int) $found['ticket_id'] ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'checkin_undone', 'ticket', (int) $found['ticket_id'], array( 'event_id' => (int) $found['event_id'], 'reason' => mb_substr( sanitize_text_field( (string) $reason ), 0, 255 ) ) );
		return array( 'ticket_id' => (int) $found['ticket_id'], 'public_id' => $found['public_id'] );
	}

	/**
	 * Presence of an occurrence: tickets currently inside, in total and per area.
	 *
	 * @return array{present: int, by_area: array<int,int>}
	 */
	public static function presence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.area_id, COUNT(*) AS n FROM {$p}wpcap_admission_state s WHERE s.occurrence_id=%d AND s.inside=1 AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_checkins c WHERE c.ticket_id=s.ticket_id AND c.result='undone' AND c.checkin_id>s.last_checkin_id) GROUP BY s.area_id", (int) $occurrence_id ) );
		$by_area = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$by_area[ (int) $row->area_id ] = (int) $row->n;
			$total += (int) $row->n;
		}
		return array( 'present' => $total, 'by_area' => $by_area );
	}

	/** Effective entries and check-outs of an occurrence, per area. */
	public static function movement( $occurrence_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT area_id, direction, COUNT(*) AS n FROM {$wpdb->prefix}wpcap_admission_entries WHERE occurrence_id=%d AND undone=0 GROUP BY area_id, direction", (int) $occurrence_id ) );
		$out = array( 'entries' => 0, 'checked_out' => 0, 'by_area' => array() );
		foreach ( $rows as $row ) {
			$key = 'in' === $row->direction ? 'entries' : 'checked_out';
			$out[ $key ] += (int) $row->n;
			$out['by_area'][ (int) $row->area_id ][ $key ] = (int) $row->n;
		}
		return $out;
	}
}
