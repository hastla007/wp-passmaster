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

	/**
	 * Service day (Y-m-d) of a UTC timestamp in a timezone.
	 *
	 * The day starts at the configured cutoff hour (setting `admission_day_cutoff_hour`, 0–12,
	 * default 0 = local midnight), so a festival scan at 02:00 with cutoff 6 still counts to the
	 * previous day. Per occurrence/event the hour can be changed with the filter
	 * `wpcap_admission_day_cutoff_hour` ( $hour, $context ) – $context holds occurrence_id and
	 * event_id when known.
	 *
	 * @param int          $ts      UTC timestamp.
	 * @param string       $tz      Timezone of the occurrence.
	 * @param array|object $context Optional ticket/occurrence context (occurrence_id, event_id).
	 */
	public static function service_date( $ts, $tz, $context = array() ) {
		$hour = self::day_cutoff_hour( $context );
		$local = ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( self::timezone( $tz ) );
		if ( $hour > 0 && (int) $local->format( 'G' ) < $hour ) {
			$local = $local->modify( '-1 day' );
		}
		return $local->format( 'Y-m-d' );
	}

	/** Day cutoff hour (0–12) for a ticket/occurrence context. */
	public static function day_cutoff_hour( $context = array() ) {
		$context = (array) $context;
		$context = array(
			'occurrence_id' => absint( $context['occurrence_id'] ?? 0 ),
			'event_id' => absint( $context['event_id'] ?? 0 ),
		);
		$hour = (int) apply_filters( 'wpcap_admission_day_cutoff_hour', (int) \PassCaptain\Settings::get( 'admission_day_cutoff_hour' ), $context );
		return max( 0, min( 12, $hour ) );
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
	 * Default entry window for ticket types without their own admission policy (settings
	 * `default_entry_window`, `entry_opens_before_minutes`, `entry_closes_after_minutes`): from N minutes
	 * before the start until the end of the occurrence (without an end: the end of its local start day)
	 * plus M minutes. '' when inside, otherwise 'outside_entry_window'.
	 */
	public static function default_window_block( array $found, $at ) {
		if ( ! \PassCaptain\Settings::get( 'default_entry_window' ) ) { return ''; }
		$start = strtotime( $found['starts_at'] . ' UTC' );
		if ( ! empty( $found['ends_at'] ) ) {
			$end = max( $start, strtotime( $found['ends_at'] . ' UTC' ) );
		} else {
			$end = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( self::timezone( $found['timezone'] ) )->setTime( 23, 59, 59 )->getTimestamp();
		}
		$opens = max( 0, (int) \PassCaptain\Settings::get( 'entry_opens_before_minutes' ) );
		$closes = max( 0, (int) \PassCaptain\Settings::get( 'entry_closes_after_minutes' ) );
		return ( $at < $start - $opens * 60 || $at > $end + $closes * 60 ) ? 'outside_entry_window' : '';
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
				$date = self::service_date( $at, $found['timezone'], $found );
				$days = Policies::valid_days( $policy, $found );
				if ( null !== $days && ! in_array( $date, $days, true ) ) { return 'not_valid_today'; }
				$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND service_date=%s AND direction='in' AND undone=0", $entries, $ticket_id, $date ) );
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
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND direction='in' AND undone=0", $wpdb->prefix . 'wpcap_admission_entries', (int) $ticket_id ) );
	}

	/**
	 * State row of a ticket, locked and reconciled with later "undone" log rows. Null if none, false
	 * on a database error (callers abort: a failed read must not look like "never admitted").
	 */
	public static function state_for_update( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// No FOR UPDATE: callers already hold the ticket row lock, which serialises scans of one ticket.
		// Locking a not-yet-existing state row took gap locks that deadlocked concurrent scans of
		// different tickets against the later INSERT … ON DUPLICATE KEY.
		$state = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id=%d', $p . 'wpcap_admission_state', (int) $ticket_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return false; }
		if ( ! $state ) { return null; }
		$undone = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(checkin_id) AS last_id FROM %i WHERE ticket_id=%d AND result='undone' AND checkin_id>%d", $p . 'wpcap_checkins', (int) $ticket_id, (int) $state->last_checkin_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return false; }
		if ( $undone && (int) $undone->n > 0 ) {
			$n = (int) $undone->n;
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET undone=1 WHERE ticket_id=%d AND direction='in' AND undone=0 ORDER BY entry_id DESC LIMIT %d", $p . 'wpcap_admission_entries', (int) $ticket_id, $n ) ) ) { return false; }
			if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET inside=0, entries=GREATEST(CAST(entries AS SIGNED)-%d,0), last_checkin_id=%d, updated_at=%s WHERE ticket_id=%d', $p . 'wpcap_admission_state', $n, (int) $undone->last_id, current_time( 'mysql', true ), (int) $ticket_id ) ) ) { return false; }
			$state = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id=%d', $p . 'wpcap_admission_state', (int) $ticket_id ) );
			if ( '' !== (string) $wpdb->last_error ) { return false; }
		}
		return $state;
	}

	/** Whether a ticket is currently inside (state row, or legacy log for tickets without state). */
	public static function is_inside( $state, $ticket_id ) {
		if ( $state ) { return (bool) (int) $state->inside; }
		global $wpdb;
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT result FROM %i WHERE ticket_id=%d AND result IN ('checked_in','checked_out','undone') ORDER BY checkin_id DESC LIMIT 1", $wpdb->prefix . 'wpcap_checkins', (int) $ticket_id ) );
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
			'service_date' => self::service_date( $at, $found['timezone'], $found ),
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
				'INSERT INTO %i (ticket_id,occurrence_id,event_id,inside,area_id,entries,last_in_at,last_checkin_id,updated_at) VALUES (%d,%d,%d,1,%d,1,%s,%d,%s) ON DUPLICATE KEY UPDATE inside=1, area_id=VALUES(area_id), entries=entries+1, last_in_at=VALUES(last_in_at), last_checkin_id=VALUES(last_checkin_id), updated_at=VALUES(updated_at)', $p . 'wpcap_admission_state',
				(int) $found['ticket_id'], (int) $found['occurrence_id'], (int) $found['event_id'], $area, $when, (int) $checkin_id, $now
			) );
		} else {
			$ok = $wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (ticket_id,occurrence_id,event_id,inside,area_id,entries,last_out_at,last_checkin_id,updated_at) VALUES (%d,%d,%d,0,%d,0,%s,%d,%s) ON DUPLICATE KEY UPDATE inside=0, last_out_at=VALUES(last_out_at), last_checkin_id=VALUES(last_checkin_id), updated_at=VALUES(updated_at)', $p . 'wpcap_admission_state',
				(int) $found['ticket_id'], (int) $found['occurrence_id'], (int) $found['event_id'], $area, $when, (int) $checkin_id, $now
			) );
		}
		return false !== $ok && '' === $wpdb->last_error;
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

	/**
	 * Takes back the latest entry of a ticket inside the caller's transaction (ticket row locked, the
	 * "undone" check-in row $undo_id already written): the newest entry that is not undone is marked
	 * undone and the state is derived from the remaining entries – entries = remaining entries,
	 * inside = whether the newest remaining movement is an entry (an undone re-entry after a
	 * check-out leaves the person outside, an earlier entry stays counted).
	 *
	 * @return int|false Remaining entries of the ticket (tickets without entry rows: from the check-in log); false on a database error.
	 */
	public static function revert_last_entry( $ticket_id, $undo_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_id = (int) $ticket_id;
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT entry_id FROM %i WHERE ticket_id=%d AND direction='in' AND undone=0 ORDER BY entry_id DESC LIMIT 1", $p . 'wpcap_admission_entries', $ticket_id ) );
		if ( self::db_failed() ) { return false; }
		if ( ! $entry ) {
			// Legacy ticket without entry rows: successful check-ins minus undos in the log (incl. this one).
			$net = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(CASE WHEN result='checked_in' THEN 1 WHEN result='undone' THEN -1 ELSE 0 END) FROM %i WHERE ticket_id=%d", $p . 'wpcap_checkins', $ticket_id ) );
			return self::db_failed() ? false : max( 0, (int) $net );
		}
		if ( 1 !== (int) $wpdb->update( "{$p}wpcap_admission_entries", array( 'undone' => 1 ), array( 'entry_id' => (int) $entry->entry_id ) ) ) { return false; }
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND direction='in' AND undone=0", $p . 'wpcap_admission_entries', $ticket_id ) );
		$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT direction FROM %i WHERE ticket_id=%d AND undone=0 ORDER BY entry_id DESC LIMIT 1', $p . 'wpcap_admission_entries', $ticket_id ) );
		if ( self::db_failed() ) { return false; }
		if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET inside=%d, entries=%d, last_checkin_id=%d, updated_at=%s WHERE ticket_id=%d', $p . 'wpcap_admission_state', 'in' === $last ? 1 : 0, $remaining, (int) $undo_id, current_time( 'mysql', true ), $ticket_id ) ) ) { return false; }
		return $remaining;
	}

	/**
	 * Takes back the latest entry of a ticket with a repeatable policy (ticket status stays valid).
	 *
	 * @return array|\WP_Error
	 */
	public static function undo_last( array $found, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Every step is checked: an undo reported as done must really have freed the entry.
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_checkin_retry', __( 'Der Scan konnte gerade nicht gespeichert werden. Bitte erneut scannen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'result' => 'retry' ) );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		$wpdb->get_row( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', (int) $found['ticket_id'] ) );
		if ( '' !== (string) $wpdb->last_error || false === self::state_for_update( $found['ticket_id'] ) ) { return $busy(); }
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT entry_id FROM %i WHERE ticket_id=%d AND direction='in' AND undone=0 ORDER BY entry_id DESC LIMIT 1", $p . 'wpcap_admission_entries', (int) $found['ticket_id'] ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy(); }
		if ( ! $entry ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ), array( 'status' => 409, 'result' => 'not_checked_in' ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => (int) $found['ticket_id'], 'event_id' => (int) $found['event_id'], 'occurrence_day_id' => 0, 'user_id' => get_current_user_id(), 'result' => 'undone', 'created_at' => $now ) );
		$undo_id = '' === (string) $wpdb->last_error ? (int) $wpdb->insert_id : 0;
		if ( ! $undo_id ) { return $busy(); }
		if ( false === self::revert_last_entry( (int) $found['ticket_id'], $undo_id ) ) { return $busy(); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		Audit::record( 'checkin_undone', 'ticket', (int) $found['ticket_id'], array( 'event_id' => (int) $found['event_id'], 'reason' => mb_substr( sanitize_text_field( (string) $reason ), 0, 255 ) ) );
		return array( 'ticket_id' => (int) $found['ticket_id'], 'public_id' => $found['public_id'] );
	}

	/**
	 * Presence of an occurrence: tickets currently inside, in total and per area.
	 *
	 * @return array{present: int, by_area: array<int,int>}
	 */
	public static function presence( $occurrence_id ) {
		return self::presence_many( array( (int) $occurrence_id ) )[ (int) $occurrence_id ];
	}

	/**
	 * presence() for several occurrences with one query (PERF-15: the check-in screen lists up to
	 * 50 occurrences). Returns occurrence_id => array( 'present' => int, 'by_area' => int[] ).
	 *
	 * @param int[] $occurrence_ids Occurrences.
	 */
	public static function presence_many( array $occurrence_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $occurrence_ids ) ) ) );
		$out = array();
		foreach ( $occurrence_ids as $id ) { $out[ (int) $id ] = array( 'present' => 0, 'by_area' => array() ); }
		if ( ! $ids ) { return $out; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array (generated IN placeholders).
			"SELECT s.occurrence_id, s.area_id, COUNT(*) AS n FROM %i s WHERE s.occurrence_id IN ($placeholders) AND s.inside=1 AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.ticket_id=s.ticket_id AND c.result='undone' AND c.checkin_id>s.last_checkin_id) GROUP BY s.occurrence_id, s.area_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			array_merge( array( $p . 'wpcap_admission_state' ), $ids, array( $p . 'wpcap_checkins' ) )
		) );
		foreach ( $rows as $row ) {
			$occ = (int) $row->occurrence_id;
			$out[ $occ ]['by_area'][ (int) $row->area_id ] = (int) $row->n;
			$out[ $occ ]['present'] += (int) $row->n;
		}
		return $out;
	}

	/** Effective entries and check-outs of an occurrence, per area. */
	public static function movement( $occurrence_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT area_id, direction, COUNT(*) AS n FROM %i WHERE occurrence_id=%d AND undone=0 GROUP BY area_id, direction', $wpdb->prefix . 'wpcap_admission_entries', (int) $occurrence_id ) );
		$out = array( 'entries' => 0, 'checked_out' => 0, 'by_area' => array() );
		foreach ( $rows as $row ) {
			$key = 'in' === $row->direction ? 'entries' : 'checked_out';
			$out[ $key ] += (int) $row->n;
			$out['by_area'][ (int) $row->area_id ][ $key ] = (int) $row->n;
		}
		return $out;
	}
}
