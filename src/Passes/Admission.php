<?php
/**
 * Admission with passes.
 *
 * - bind() (`wpcap_ticket_valid_for_occurrence`): a pass ticket is bound to the linked occurrence
 *   it is scanned for – the occurrence named by the scanner context, otherwise the linked
 *   occurrence of the device's events/area that is valid today (service day incl. day cutoff) or
 *   nearest in time. Event scope, device restriction, areas and entry windows then apply to that
 *   occurrence as for an ordinary ticket.
 * - policy() (`wpcap_admission_policy`): pass types get the repeatable policy 'pass' (ticket status
 *   stays valid); entry-window fields of an explicit admission policy of the type are kept.
 * - evaluate() (`wpcap_admission_evaluate`): per (ticket, occurrence) on the existing
 *   wpcap_admission_entries rows (which carry occurrence_id): valid service day of the occurrence,
 *   entry mode once (one entry per occurrence) or re-entry (only while outside at that
 *   occurrence), and the "N of M" limit (entries at no more than N different occurrences).
 *   No extra state table is needed; the ticket-wide wpcap_admission_state keeps the last movement.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admission {
	/* ---------------------------------------------------------------------------------------------
	 * Binding a scan to a linked occurrence.
	 * ------------------------------------------------------------------------------------------- */

	/** Filter callback for `wpcap_ticket_valid_for_occurrence`. */
	public static function bind( $found, $context = array(), $at = null ) {
		if ( ! is_array( $found ) || empty( $found['ticket_type_id'] ) ) { return $found; }
		$config = Definitions::get( (int) $found['ticket_type_id'] );
		if ( ! $config ) { return $found; }
		$linked = Definitions::linked_occurrences( $config->type_id );
		if ( ! $linked ) { return $found; }
		$context = (array) $context;
		$at = null === $at ? time() : (int) $at;
		$wanted = absint( $context['occurrence_id'] ?? 0 );
		if ( $wanted ) {
			foreach ( $linked as $occ ) {
				if ( (int) $occ->occurrence_id === $wanted ) { return self::bound( $found, $occ ); }
			}
		}
		$event_ids = isset( $context['event_ids'] ) && is_array( $context['event_ids'] ) ? array_map( 'intval', $context['event_ids'] ) : array();
		$area_id = absint( $context['area_id'] ?? 0 );
		if ( $area_id ) {
			$area = \PassCaptain\Admission\Areas::get( $area_id );
			$area_event = $area ? array( (int) $area->event_id ) : array( 0 );
			$event_ids = $event_ids ? array_values( array_intersect( $event_ids, $area_event ) ) : $area_event;
			if ( ! $event_ids ) { $event_ids = array( 0 ); }
		}
		$candidates = $wanted ? array() : array_values( array_filter( $linked, static function ( $occ ) use ( $event_ids ) { return ! $event_ids || in_array( (int) $occ->event_id, $event_ids, true ); } ) );
		// Nothing matches the scanner (other occurrence/event): bind to the best linked occurrence
		// anyway, so the scan is refused with wrong_occurrence/wrong_event instead of out_of_window.
		$best = self::best( $candidates ? $candidates : $linked, $found, $at );
		return $best ? self::bound( $found, $best ) : $found;
	}

	/** Linked occurrence valid on the service day of $at (not cancelled preferred), else the nearest. */
	private static function best( array $occurrences, array $found, $at ) {
		$best = null;
		$best_score = null;
		foreach ( $occurrences as $occ ) {
			$view = self::bound( $found, $occ );
			$days = Policies::valid_days( array( 'policy' => 'daily' ), $view );
			$today = Engine::service_date( $at, $occ->timezone, $view );
			$start = strtotime( $occ->starts_at . ' UTC' );
			$end = max( $start, strtotime( ( $occ->ends_at ? $occ->ends_at : $occ->starts_at ) . ' UTC' ) );
			$distance = $at < $start ? $start - $at : ( $at > $end ? $at - $end : 0 );
			$cancelled = 'cancelled' === $occ->status || 'cancelled' === $occ->event_status ? 1 : 0;
			// Among equally valid dates, one this ticket was not admitted to yet comes first: two shows on
			// the same day (matinee + evening) must not bind the second scan to the used first show.
			$used = ! empty( $found['ticket_id'] ) && self::entries_at( (int) $found['ticket_id'], (int) $occ->occurrence_id ) > 0 ? 1 : 0;
			$score = array( in_array( $today, (array) $days, true ) ? 0 : 1, $cancelled, $used, $distance, (int) $occ->occurrence_id );
			if ( null === $best_score || $score < $best_score ) {
				$best = $occ;
				$best_score = $score;
			}
		}
		return $best;
	}

	/** $found re-targeted to a linked occurrence. */
	private static function bound( array $found, $occ ) {
		return array_merge( $found, array(
			'occurrence_id' => (int) $occ->occurrence_id,
			'event_id' => (int) $occ->event_id,
			'event_name' => (string) $occ->event_name,
			'starts_at' => (string) $occ->starts_at,
			'ends_at' => $occ->ends_at,
			'timezone' => (string) $occ->timezone,
			'occurrence_status' => (string) $occ->status,
			'event_status' => (string) $occ->event_status,
		) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Policy.
	 * ------------------------------------------------------------------------------------------- */

	/** Filter callback for `wpcap_admission_policy`. */
	public static function policy( $policy, $type_id ) {
		$config = Definitions::get( $type_id );
		if ( ! $config ) { return $policy; }
		$base = is_array( $policy ) ? $policy : array();
		return array(
			'ticket_type_id' => (int) $type_id,
			'policy' => 'pass',
			'max_entries' => 0,
			'valid_dates' => array(),
			'opens_before_minutes' => $base['opens_before_minutes'] ?? null,
			'closes_after_minutes' => $base['closes_after_minutes'] ?? null,
			'day_from' => $base['day_from'] ?? null,
			'day_until' => $base['day_until'] ?? null,
			'entry_mode' => $config->entry_mode,
			'max_occurrences' => (int) $config->max_occurrences,
			'occurrence_ids' => $config->occurrence_ids,
		);
	}

	/** Filter callback for `wpcap_admission_evaluate` (runs under the ticket row lock). */
	public static function evaluate( $result, $policy, $found, $state, $at ) {
		if ( null !== $result || ! is_array( $policy ) || 'pass' !== ( $policy['policy'] ?? '' ) ) { return $result; }
		$occurrence_id = (int) $found['occurrence_id'];
		$ticket_id = (int) $found['ticket_id'];
		if ( ! in_array( $occurrence_id, (array) $policy['occurrence_ids'], true ) ) { return 'not_valid_today'; }
		$days = Policies::valid_days( array( 'policy' => 'daily' ), $found );
		if ( null !== $days && ! in_array( Engine::service_date( $at, $found['timezone'], $found ), $days, true ) ) { return 'not_valid_today'; }
		$here = self::entries_at( $ticket_id, $occurrence_id );
		if ( 'reentry' === $policy['entry_mode'] ) {
			if ( self::inside_at( $ticket_id, $occurrence_id ) ) { return 'already_inside'; }
		} elseif ( $here > 0 ) {
			return 'daily_limit_reached';
		}
		if ( (int) $policy['max_occurrences'] > 0 && 0 === $here && self::used_occurrences( $ticket_id, (array) $policy['occurrence_ids'] ) >= (int) $policy['max_occurrences'] ) {
			return 'entry_limit_reached';
		}
		return '';
	}

	/** Effective entries of a ticket at one occurrence. */
	public static function entries_at( $ticket_id, $occurrence_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_admission_entries WHERE ticket_id=%d AND occurrence_id=%d AND direction='in' AND undone=0", absint( $ticket_id ), absint( $occurrence_id ) ) );
	}

	/** Whether the last effective movement of a ticket at an occurrence is an entry. */
	public static function inside_at( $ticket_id, $occurrence_id ) {
		global $wpdb;
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT direction FROM {$wpdb->prefix}wpcap_admission_entries WHERE ticket_id=%d AND occurrence_id=%d AND undone=0 ORDER BY entry_id DESC LIMIT 1", absint( $ticket_id ), absint( $occurrence_id ) ) );
		return 'in' === $last;
	}

	/** Number of different linked occurrences with an effective entry. */
	public static function used_occurrences( $ticket_id, array $occurrence_ids ) {
		global $wpdb;
		$occurrence_ids = array_values( array_filter( array_map( 'absint', $occurrence_ids ) ) );
		if ( ! $occurrence_ids ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT occurrence_id) FROM {$wpdb->prefix}wpcap_admission_entries WHERE ticket_id=%d AND direction='in' AND undone=0 AND occurrence_id IN (" . implode( ',', $occurrence_ids ) . ')', absint( $ticket_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/* ---------------------------------------------------------------------------------------------
	 * Areas.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Filter callback for `wpcap_ticket_allowed_areas`: the pass's areas of the scanned event; without
	 * configured areas for that event the type's own restriction applies only within that event.
	 */
	public static function allowed_areas( $allowed, $found ) {
		if ( ! is_array( $found ) || ! Definitions::is_pass( (int) ( $found['ticket_type_id'] ?? 0 ) ) ) { return $allowed; }
		global $wpdb;
		$config = Definitions::get( (int) $found['ticket_type_id'] );
		$event_areas = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT area_id FROM {$wpdb->prefix}wpcap_admission_areas WHERE event_id=%d", (int) $found['event_id'] ) ) );
		$own = array_values( array_intersect( $config->area_ids, $event_areas ) );
		if ( $own ) { return $own; }
		return array_values( array_intersect( array_map( 'intval', (array) $allowed ), $event_areas ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Report.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Entries of a pass per linked occurrence and service day.
	 *
	 * @return array[] occurrence_id, service_date, tickets (distinct), entries.
	 */
	public static function usage( $type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.occurrence_id,e.service_date,COUNT(DISTINCT e.ticket_id) AS tickets,COUNT(*) AS entries FROM {$p}wpcap_admission_entries e INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=e.ticket_id WHERE t.ticket_type_id=%d AND e.direction='in' AND e.undone=0 GROUP BY e.occurrence_id,e.service_date ORDER BY e.service_date,e.occurrence_id", absint( $type_id ) ) );
		return array_map( static function ( $row ) {
			return array( 'occurrence_id' => (int) $row->occurrence_id, 'service_date' => (string) $row->service_date, 'tickets' => (int) $row->tickets, 'entries' => (int) $row->entries );
		}, (array) $rows );
	}
}
