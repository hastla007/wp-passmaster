<?php
/**
 * Offline scanner packages with passes. Packages are built per occurrence; a pass ticket is shipped
 * in the package of every linked occurrence (and not in the package of an anchor occurrence that
 * is not linked). Its shipped status reflects the pass rules for that occurrence: "checked_in"
 * (used) after an entry there in entry mode "once", or when the N-of-M limit is used up by other
 * occurrences; otherwise "valid". The ticket type descriptor carries policy "pass" and
 * multi = re-entry allowed.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

use PassCaptain\Admission\Areas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Offline {
	/** Filter callback for `wpcap_offline_live_tickets`. */
	public static function live_tickets( $live, $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$live = (array) $live;
		foreach ( $live as $i => $row ) {
			$config = Definitions::get( (int) $row->ticket_type_id );
			if ( $config && ! in_array( $occurrence_id, $config->occurrence_ids, true ) ) { unset( $live[ $i ] ); }
		}
		$pass_ids = Definitions::pass_ids_for_occurrence( $occurrence_id );
		if ( ! $pass_ids ) { return array_values( $live ); }
		$foreign = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN (" . implode( ',', $pass_ids ) . ') AND occurrence_id<>%d', $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( $foreign ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.ticket_id,t.token_hash,t.status,t.ticket_type_id,ta.attendee_name,o.status AS occurrence_status,e.status AS event_status FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=%d INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_type_id IN (" . implode( ',', $foreign ) . ") AND t.token_hash<>''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$occurrence_id
			) );
			foreach ( (array) $rows as $row ) { $live[] = $row; }
		}
		// Shipped status per pass rules at this occurrence (batched).
		$ids = array();
		foreach ( $live as $row ) {
			if ( in_array( (int) $row->ticket_type_id, $pass_ids, true ) && 'valid' === $row->status ) { $ids[] = (int) $row->ticket_id; }
		}
		if ( ! $ids ) { return array_values( $live ); }
		$here = array();
		$distinct = array();
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in = implode( ',', $chunk );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,COUNT(*) AS n FROM {$p}wpcap_admission_entries WHERE ticket_id IN ($in) AND occurrence_id=%d AND direction='in' AND undone=0 GROUP BY ticket_id", $occurrence_id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$here[ (int) $row->ticket_id ] = (int) $row->n;
			}
			foreach ( (array) $wpdb->get_results( "SELECT ticket_id,occurrence_id FROM {$p}wpcap_admission_entries WHERE ticket_id IN ($in) AND direction='in' AND undone=0 GROUP BY ticket_id,occurrence_id" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$distinct[ (int) $row->ticket_id ][] = (int) $row->occurrence_id;
			}
		}
		foreach ( $live as $row ) {
			if ( ! in_array( (int) $row->ticket_id, $ids, true ) ) { continue; }
			$config = Definitions::get( (int) $row->ticket_type_id );
			$entered_here = ( $here[ (int) $row->ticket_id ] ?? 0 ) > 0;
			$used = count( array_intersect( $distinct[ (int) $row->ticket_id ] ?? array(), $config->occurrence_ids ) );
			if ( ( 'once' === $config->entry_mode && $entered_here ) || ( $config->max_occurrences > 0 && ! $entered_here && $used >= $config->max_occurrences ) ) {
				$row->status = 'checked_in';
			}
		}
		return array_values( $live );
	}

	/** Filter callback for `wpcap_offline_package_types`. */
	public static function package_types( $types, $occurrence ) {
		global $wpdb;
		$types = (array) $types;
		$occurrence_id = (int) $occurrence->occurrence_id;
		$event_id = (int) $occurrence->event_id;
		$listed = array();
		foreach ( $types as $i => $type ) {
			$config = Definitions::get( (int) $type['id'] );
			$listed[ (int) $type['id'] ] = true;
			if ( ! $config ) { continue; }
			if ( ! in_array( $occurrence_id, $config->occurrence_ids, true ) ) { unset( $types[ $i ] ); continue; }
			$types[ $i ] = self::descriptor( (int) $type['id'], (string) $type['name'], $config, $event_id );
		}
		foreach ( Definitions::pass_ids_for_occurrence( $occurrence_id ) as $type_id ) {
			if ( isset( $listed[ $type_id ] ) ) { continue; }
			$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
			$types[] = self::descriptor( $type_id, $name, Definitions::get( $type_id ), $event_id );
		}
		return array_values( $types );
	}

	private static function descriptor( $type_id, $name, $config, $event_id ) {
		$areas = Admission::allowed_areas( Areas::type_area_ids( $type_id ), array( 'ticket_type_id' => $type_id, 'event_id' => $event_id ) );
		return array( 'id' => $type_id, 'name' => $name, 'areas' => array_values( array_map( 'intval', $areas ) ), 'policy' => 'pass', 'multi' => 'reentry' === $config->entry_mode, 'pass' => true );
	}
}
