<?php
/**
 * Copies ticket types and everything that hangs off a ticket type to another occurrence: participant
 * fields, fiscal seller assignment, admission policy and areas, and the price rules / buyer limits
 * that are bound to the source type or the source occurrence. Event-wide rules (occurrence 0, type 0)
 * apply to the copy anyway and are not duplicated.
 *
 * Used by the series generator and by "Duplizieren" (event/occurrence). Callers run it inside their
 * own transaction; every method throws a \RuntimeException on a failed statement so the caller can
 * roll back. Tables of disabled modules are skipped.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketTypeCopier {
	/** @var array<string,bool> */
	private static $tables = array();

	/** Whether a (module) table exists; cached per request. */
	public static function table_exists( $table ) {
		global $wpdb;
		if ( ! isset( self::$tables[ $table ] ) ) {
			self::$tables[ $table ] = $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		return self::$tables[ $table ];
	}

	/** Resets the table cache (tests, after installing modules). */
	public static function reset_cache() {
		self::$tables = array();
	}

	/**
	 * Inserts a copy of a ticket type row for another occurrence.
	 *
	 * @param object $type      Source row (SELECT *).
	 * @param int    $occurrence_id Target occurrence.
	 * @param array  $overrides Column => value (e.g. sales_start/sales_end).
	 * @return int New ticket type id.
	 */
	public static function copy_type( $type, $occurrence_id, array $overrides = array() ) {
		global $wpdb;
		$row = (array) $type;
		unset( $row['ticket_type_id'] );
		$row['occurrence_id'] = absint( $occurrence_id );
		$row = array_merge( $row, $overrides );
		$ok = $wpdb->insert( $wpdb->prefix . 'wpcap_ticket_types', $row );
		$id = (int) $wpdb->insert_id;
		if ( ! $ok || ! $id ) { throw new \RuntimeException( 'type_insert' ); }
		return $id;
	}

	/**
	 * Copies the dependent rows of the mapped types.
	 *
	 * @param array<int,int> $map             Source type id => new type id.
	 * @param int            $from_occurrence Source occurrence (occurrence-scoped rules).
	 * @param int            $to_occurrence   Target occurrence.
	 * @param int            $to_event        Target event (price rules/limits; same event for series).
	 * @param bool           $same_event      False when copying into another event: admission areas
	 *                                        belong to the source event and are not linked.
	 * @return array<string,int> Copied rows per kind.
	 */
	public static function copy_dependents( array $map, $from_occurrence, $to_occurrence, $to_event = 0, $same_event = true ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$counts = array( 'fields' => 0, 'sellers' => 0, 'admission' => 0, 'areas' => 0, 'price_rules' => 0, 'buyer_limits' => 0 );
		$map = array_filter( array_map( 'absint', $map ) );
		if ( ! $map ) { return $counts; }
		$ids = array_map( 'absint', array_keys( $map ) );
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now = current_time( 'mysql', true );

		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ticket_type_id IN ($in) ORDER BY field_id", array_merge( array( $p . 'wpcap_participant_fields' ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		foreach ( (array) $fields as $field ) {
			$field['ticket_type_id'] = $map[ (int) $field['ticket_type_id'] ];
			unset( $field['field_id'] );
			if ( ! $wpdb->insert( $p . 'wpcap_participant_fields', $field ) ) { throw new \RuntimeException( 'field_insert' ); }
			++$counts['fields'];
		}

		$simple = array(
			'sellers' => $p . 'wpcap_ticket_sellers',
			'admission' => $p . 'wpcap_admission_policies',
			'areas' => $p . 'wpcap_admission_type_areas',
		);
		if ( ! $same_event ) { unset( $simple['areas'] ); }
		foreach ( $simple as $kind => $table ) {
			if ( ! self::table_exists( $table ) ) { continue; }
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ticket_type_id IN ($in)", array_merge( array( $table ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
			foreach ( (array) $rows as $row ) {
				$row['ticket_type_id'] = $map[ (int) $row['ticket_type_id'] ];
				if ( isset( $row['updated_at'] ) ) { $row['updated_at'] = $now; }
				if ( ! $wpdb->replace( $table, $row ) ) { throw new \RuntimeException( $kind . '_insert' ); }
				++$counts[ $kind ];
			}
		}

		$scoped = array(
			'price_rules' => array( $p . 'wpcap_price_rules', 'rule_id' ),
			'buyer_limits' => array( $p . 'wpcap_buyer_limits', 'limit_id' ),
		);
		foreach ( $scoped as $kind => list( $table, $pk ) ) {
			if ( ! self::table_exists( $table ) ) { continue; }
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status<>'deleted' AND (ticket_type_id IN ($in) OR (ticket_type_id=0 AND occurrence_id=%d AND occurrence_id<>0))", array_merge( array( $table ), $ids, array( absint( $from_occurrence ) ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
			foreach ( (array) $rows as $row ) {
				unset( $row[ $pk ] );
				if ( (int) $row['ticket_type_id'] ) { $row['ticket_type_id'] = $map[ (int) $row['ticket_type_id'] ]; }
				if ( (int) $row['occurrence_id'] ) { $row['occurrence_id'] = absint( $to_occurrence ); }
				if ( $to_event ) { $row['event_id'] = absint( $to_event ); }
				$row['created_at'] = $now;
				$row['updated_at'] = $now;
				if ( ! $wpdb->insert( $table, $row ) ) { throw new \RuntimeException( $kind . '_insert' ); }
				++$counts[ $kind ];
			}
		}
		return $counts;
	}
}
