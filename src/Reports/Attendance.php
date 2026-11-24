<?php
/**
 * Who belongs to an occurrence and who was admitted there – one definition for the report figures
 * (Data::occurrence_stats(), Data::checkin_histogram()), the no-show list and the attendee list
 * (A571–A573).
 *
 * Tickets of an occurrence:
 *  - ordinary tickets: their ticket type belongs to the occurrence;
 *  - passes (module Passes): every ticket of a pass type linked to the occurrence – also on the linked
 *    occurrences of other events, and not on the anchor occurrence the pass is sold on unless that
 *    one is linked as well.
 *
 * Admitted at the occurrence:
 *  - ordinary tickets: status checked_in, or more "checked_in" than "undone" log rows (repeatable
 *    policies keep the status valid; every take-back writes an "undone" row);
 *  - passes: an admission entry (wpcap_admission_entries) at that occurrence that was not undone.
 *
 * Effective entries (histogram): the "checked_in" log rows that no later "undone" row took back
 * (each undo cancels the latest open entry of the ticket), for passes the entries at the occurrence.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Reports;

use PassCaptain\Passes\Definitions;
use PassCaptain\Privacy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Attendance {
	/** Whether pass links apply (module enabled, tables present). */
	public static function passes_active() {
		return \PassCaptain\Modules::enabled( \PassCaptain\Modules\Passes::class ) && Privacy::has_table( 'wpcap_pass_types' ) && Privacy::has_table( 'wpcap_pass_occurrences' );
	}

	/** All pass ticket type ids (empty without the Passes module). */
	public static function pass_type_ids() {
		return self::passes_active() ? array_map( 'intval', Definitions::all_ids() ) : array();
	}

	/** Pass ticket type ids linked to an occurrence. */
	public static function linked_pass_types( $occurrence_id ) {
		return self::passes_active() ? array_map( 'intval', Definitions::pass_ids_for_occurrence( absint( $occurrence_id ) ) ) : array();
	}

	/**
	 * SQL condition (aliases t = wpcap_tickets, tt = wpcap_ticket_types) for the tickets of an
	 * occurrence, passes included (see the class comment).
	 */
	public static function ticket_condition( $occurrence_id ) {
		$occurrence_id = absint( $occurrence_id );
		$all = self::pass_type_ids();
		$linked = self::linked_pass_types( $occurrence_id );
		$sql = 'tt.occurrence_id=' . $occurrence_id . ( $all ? ' AND t.ticket_type_id NOT IN (' . implode( ',', $all ) . ')' : '' );
		if ( $linked ) { $sql = '(' . $sql . ') OR t.ticket_type_id IN (' . implode( ',', $linked ) . ')'; }
		return '(' . $sql . ')';
	}

	/** SQL expression (alias t): 1 when an ordinary ticket counts as admitted. */
	private static function ordinary_admitted_sql() {
		global $wpdb;
		return "(t.status='checked_in' OR COALESCE((SELECT SUM(c.result='checked_in')-SUM(c.result='undone') FROM {$wpdb->prefix}wpcap_checkins c WHERE c.ticket_id=t.ticket_id AND c.result IN ('checked_in','undone')),0)>0)";
	}

	/** SQL expression (alias t, occurrence id expression $occ): 1 when a pass has an entry there. */
	private static function pass_admitted_sql( $occ ) {
		global $wpdb;
		if ( ! Privacy::has_table( 'wpcap_admission_entries' ) ) { return '0'; }
		return "EXISTS (SELECT 1 FROM {$wpdb->prefix}wpcap_admission_entries e WHERE e.ticket_id=t.ticket_id AND e.occurrence_id=$occ AND e.direction='in' AND e.undone=0)";
	}

	/**
	 * Valid tickets (valid/checked_in) and admitted tickets per occurrence.
	 *
	 * @param int[] $occurrence_ids
	 * @return array<int,array{issued:int,admitted:int}>
	 */
	public static function counts( array $occurrence_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_filter( array_map( 'absint', $occurrence_ids ) ) );
		$out = array();
		if ( ! $ids ) { return $out; }
		$in = implode( ',', $ids );
		$passes = self::pass_type_ids();
		$not_pass = $passes ? ' AND t.ticket_type_id NOT IN (' . implode( ',', $passes ) . ')' : '';
		$rows = (array) $wpdb->get_results( "SELECT tt.occurrence_id,COUNT(*) AS n,SUM(" . self::ordinary_admitted_sql() . ") AS admitted FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id IN ($in) AND t.status IN ('valid','checked_in')$not_pass GROUP BY tt.occurrence_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- absint()/intval integer lists; ordinary_admitted_sql() is fixed SQL.
		if ( $passes ) {
			$rows = array_merge( $rows, (array) $wpdb->get_results( "SELECT po.occurrence_id,COUNT(*) AS n,SUM(" . self::pass_admitted_sql( 'po.occurrence_id' ) . ") AS admitted FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_pass_occurrences po ON po.type_id=t.ticket_type_id WHERE po.occurrence_id IN ($in) AND t.status IN ('valid','checked_in') GROUP BY po.occurrence_id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- absint() integer list; pass_admitted_sql() is fixed SQL with a column expression.
		}
		foreach ( $rows as $row ) {
			$id = (int) $row->occurrence_id;
			$out[ $id ] = array(
				'issued' => ( $out[ $id ]['issued'] ?? 0 ) + (int) $row->n,
				'admitted' => ( $out[ $id ]['admitted'] ?? 0 ) + (int) $row->admitted,
			);
		}
		return $out;
	}

	/**
	 * Ticket ids among $tickets that were admitted at the occurrence.
	 *
	 * @param object[] $tickets Rows with ticket_id and ticket_type_id (e.g. Templates::tickets_for_occurrence()).
	 * @return array<int,true> ticket_id => true
	 */
	public static function admitted_ids( $occurrence_id, array $tickets ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$passes = array_flip( self::linked_pass_types( $occurrence_id ) );
		$ordinary = array();
		$pass = array();
		foreach ( $tickets as $t ) {
			if ( isset( $passes[ (int) $t->ticket_type_id ] ) ) { $pass[] = (int) $t->ticket_id; } else { $ordinary[] = (int) $t->ticket_id; }
		}
		$out = array();
		foreach ( array_chunk( $ordinary, 1000 ) as $chunk ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$wpdb->prefix}wpcap_tickets t WHERE t.ticket_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') AND ' . self::ordinary_admitted_sql(), $chunk ) ) as $id ) { $out[ (int) $id ] = true; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ordinary_admitted_sql() is fixed SQL.
		}
		foreach ( array_chunk( $pass, 1000 ) as $chunk ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$wpdb->prefix}wpcap_tickets t WHERE t.ticket_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') AND ' . self::pass_admitted_sql( (string) $occurrence_id ), $chunk ) ) as $id ) { $out[ (int) $id ] = true; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- pass_admitted_sql() is fixed SQL; the occurrence id is absint().
		}
		return $out;
	}

	/**
	 * UTC times of the effective entries at an occurrence (taken-back scans removed).
	 *
	 * @return string[] "Y-m-d H:i:s" (UTC), unsorted.
	 */
	public static function entry_times( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$passes = self::pass_type_ids();
		$not_pass = $passes ? ' AND t.ticket_type_id NOT IN (' . implode( ',', $passes ) . ')' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.ticket_id,c.result,COALESCE(c.scanned_at,c.created_at) AS at FROM {$p}wpcap_checkins c INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND c.result IN ('checked_in','undone')$not_pass ORDER BY c.checkin_id", $occurrence_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$open = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->ticket_id;
			if ( 'undone' === $row->result ) {
				// An undo takes back the latest entry of the ticket that is still open.
				if ( ! empty( $open[ $id ] ) ) { array_pop( $open[ $id ] ); }
				continue;
			}
			$open[ $id ][] = (string) $row->at;
		}
		$times = $open ? array_merge( ...array_values( $open ) ) : array();
		$linked = self::linked_pass_types( $occurrence_id );
		if ( $linked && Privacy::has_table( 'wpcap_admission_entries' ) ) {
			$times = array_merge( $times, array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT e.entered_at FROM {$wpdb->prefix}wpcap_admission_entries e INNER JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=e.ticket_id WHERE e.occurrence_id=%d AND e.direction='in' AND e.undone=0 AND t.ticket_type_id IN (" . implode( ',', array_fill( 0, count( $linked ), '%d' ) ) . ')', array_merge( array( $occurrence_id ), $linked ) ) ) ) );
		}
		return $times;
	}

	/**
	 * Start of admission (UTC timestamp): doors-open time, else the start minus the default entry
	 * window (when that setting is on), else the start.
	 */
	public static function admission_start( $occurrence ) {
		$start = strtotime( (string) $occurrence->starts_at . ' UTC' );
		$doors = \PassCaptain\DoorsOpen::for_occurrence( (int) $occurrence->occurrence_id );
		if ( $doors ) { return (int) strtotime( $doors . ' UTC' ); }
		if ( \PassCaptain\Settings::get( 'default_entry_window' ) ) {
			return (int) $start - max( 0, (int) \PassCaptain\Settings::get( 'entry_opens_before_minutes' ) ) * 60;
		}
		return (int) $start;
	}
}
