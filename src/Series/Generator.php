<?php
/**
 * Generates occurrences for a series: preview, idempotent creation (no duplicate start times per
 * event), ticket type copies from a template occurrence and optional seat-map link.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Series;

use PassCaptain\Access;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Generator {
	const CAP = 'wpcap_manage_events';

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_series (
				series_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				template_occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				definition longtext NOT NULL,
				definition_hash char(64) NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (series_id),
				UNIQUE KEY event_definition (event_id,definition_hash),
				KEY event_id (event_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_series_occurrences (
				series_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				starts_at datetime NOT NULL,
				created_at datetime NOT NULL,
				flag varchar(20) NOT NULL DEFAULT '',
				flagged_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (series_id,occurrence_id),
				UNIQUE KEY occurrence_id (occurrence_id),
				KEY series_start (series_id,starts_at)
			) ENGINE=InnoDB $charset;",
		);
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Optional non-negative integer ('' = not set). */
	private static function optional_int( $value ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) { return null; }
		return preg_match( '/^\d+$/', trim( (string) $value ) ) ? (int) $value : false;
	}

	/**
	 * Validates the full definition (rule + event + template + options).
	 *
	 * @return array|\WP_Error
	 */
	public static function definition( array $in ) {
		global $wpdb;
		$event_id = absint( $in['event_id'] ?? 0 );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event ) { return self::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), 403 ); }
		if ( 'cancelled' === $event->status ) { return self::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'wp-pass-captain' ), 409 ); }
		$rule = Rules::normalize( $in );
		if ( is_wp_error( $rule ) ) { return $rule; }
		$template_id = absint( $in['template_occurrence_id'] ?? 0 );
		if ( $template_id ) {
			$template = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $template_id ) );
			if ( ! $template || (int) $template->event_id !== $event_id ) { return self::error( 'wpcap_series_template', __( 'Der Vorlagentermin gehört nicht zu diesem Event.', 'wp-pass-captain' ) ); }
		}
		$sales_start = self::optional_int( $in['sales_start_days_before'] ?? '' );
		$sales_end = self::optional_int( $in['sales_end_minutes_before'] ?? '' );
		if ( false === $sales_start || false === $sales_end || ( null !== $sales_start && $sales_start > 730 ) || ( null !== $sales_end && $sales_end > 60 * 24 * 60 ) ) {
			return self::error( 'wpcap_series_sales', __( 'Der Verkaufszeitraum ist ungültig.', 'wp-pass-captain' ) );
		}
		$seat_version = absint( $in['seat_version_id'] ?? 0 );
		if ( $seat_version ) {
			$version_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_seating_versions WHERE version_id=%d", $seat_version ) );
			if ( $version_event !== $event_id ) { return self::error( 'wpcap_series_seat_map', __( 'Die Saalplan-Version gehört nicht zu diesem Event.', 'wp-pass-captain' ) ); }
		}
		$definition = array(
			'event_id' => $event_id,
			'template_occurrence_id' => $template_id,
			'location' => mb_substr( sanitize_text_field( (string) ( $in['location'] ?? '' ) ), 0, 190 ),
			'capacity' => absint( $in['capacity'] ?? 0 ),
			'sales_start_days_before' => $sales_start,
			'sales_end_minutes_before' => $sales_end,
			'seat_version_id' => $seat_version,
			'rule' => $rule,
		);
		$definition['name'] = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		return $definition;
	}

	/**
	 * Preview: the occurrences the definition yields, each flagged "exists" (an occurrence of the
	 * event already starts at that time – it will be skipped) or "past".
	 *
	 * @return array|\WP_Error {definition, items[]}
	 */
	public static function preview( array $in ) {
		$definition = self::definition( $in );
		if ( is_wp_error( $definition ) ) { return $definition; }
		$items = Rules::expand( $definition['rule'] );
		if ( is_wp_error( $items ) ) { return $items; }
		$existing = self::existing_starts( $definition['event_id'], $items );
		$now = current_time( 'mysql', true );
		foreach ( $items as &$item ) {
			$item['exists'] = isset( $existing[ $item['starts_at'] ] );
			$item['past'] = $item['starts_at'] <= $now;
		}
		unset( $item );
		return array( 'definition' => $definition, 'items' => $items );
	}

	private static function existing_starts( $event_id, array $items, $lock = false ) {
		global $wpdb;
		if ( ! $items ) { return array(); }
		$starts = array_map( static function ( $item ) use ( $wpdb ) { return $wpdb->prepare( '%s', $item['starts_at'] ); }, $items );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT starts_at FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d AND starts_at IN (" . implode( ',', $starts ) . ')' . ( $lock ? ' FOR UPDATE' : '' ), $event_id ) );
		return array_flip( $rows );
	}

	/**
	 * Creates the missing occurrences. Idempotent: start times that already exist for the event are
	 * skipped, and the definition is stored once per event (by hash).
	 *
	 * @return array|\WP_Error {series_id, created (occurrence ids), skipped_existing, skipped_past, seat_links}
	 */
	public static function create( array $in ) {
		global $wpdb;
		$preview = self::preview( $in );
		if ( is_wp_error( $preview ) ) { return $preview; }
		$definition = $preview['definition'];
		$items = $preview['items'];
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$template = null;
		$types = array();
		if ( $definition['template_occurrence_id'] ) {
			$template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $definition['template_occurrence_id'] ) );
			$types = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d AND status<>'archived' ORDER BY ticket_type_id", $definition['template_occurrence_id'] ) );
		}
		$hash = self::definition_hash( $definition );
		$created = array();
		$created_starts = array();
		$type_maps = array();
		$skipped_existing = 0;
		$skipped_past = 0;
		$wpdb->query( 'START TRANSACTION' );
		try {
			// Serialises concurrent runs for the same event.
			$wpdb->query( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_events WHERE event_id=%d FOR UPDATE", $definition['event_id'] ) );
			$series_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT series_id FROM {$p}wpcap_series WHERE event_id=%d AND definition_hash=%s", $definition['event_id'], $hash ) );
			if ( ! $series_id ) {
				$ok = $wpdb->insert( "{$p}wpcap_series", array( 'event_id' => $definition['event_id'], 'name' => $definition['name'], 'template_occurrence_id' => $definition['template_occurrence_id'], 'definition' => wp_json_encode( $definition ), 'definition_hash' => $hash, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
				$series_id = (int) $wpdb->insert_id;
				if ( ! $ok || ! $series_id ) { throw new \RuntimeException( 'series_insert' ); }
			}
			$existing = self::existing_starts( $definition['event_id'], $items, true );
			foreach ( $items as $item ) {
				if ( $item['past'] ) { $skipped_past++; continue; }
				if ( isset( $existing[ $item['starts_at'] ] ) ) { $skipped_existing++; continue; }
				list( $occurrence_id, $type_maps[ $occurrence_id ] ) = self::insert_occurrence( $definition, $item, $template, $types, $series_id, $now );
				$existing[ $item['starts_at'] ] = true;
				$created[] = $occurrence_id;
				$created_starts[ $occurrence_id ] = $item['starts_at'];
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_series SET updated_at=%s WHERE series_id=%d", $now, $series_id ) );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_series_failed', __( 'Die Serientermine konnten nicht angelegt werden; es wurde nichts gespeichert.', 'wp-pass-captain' ), 500 );
		}
		$seat_links = self::link_seat_maps( $definition, $type_maps );
		foreach ( $created as $occurrence_id ) {
			Audit::record( 'occurrence_created', 'occurrence', $occurrence_id, array( 'event_id' => $definition['event_id'], 'series_id' => $series_id, 'starts_at' => $created_starts[ $occurrence_id ] ?? null ) );
		}
		Audit::record( 'series_generated', 'series', $series_id, array( 'event_id' => $definition['event_id'], 'series_id' => $series_id, 'created' => count( $created ), 'skipped_existing' => $skipped_existing, 'skipped_past' => $skipped_past ) );
		return array( 'series_id' => $series_id, 'event_id' => $definition['event_id'], 'created' => $created, 'skipped_existing' => $skipped_existing, 'skipped_past' => $skipped_past, 'seat_links' => $seat_links ) + \PassCaptain\Admin\Forms::dst_warning( self::dst_notes( $items, $definition['rule']['timezone'] ) );
	}

	/**
	 * A102: notes for generated items whose local time falls into a DST change (moved forward from
	 * a nonexistent time, or first instance of an ambiguous time).
	 *
	 * @return string[]
	 */
	public static function dst_notes( array $items, $timezone ) {
		$gap = array();
		$ambiguous = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['past'] ) ) { continue; }
			$when = gmdate( 'd.m.Y', strtotime( $item['date'] ) );
			if ( 'gap' === ( $item['dst'] ?? '' ) ) { $gap[] = $when . ' → ' . substr( $item['local'], 11 ); }
			if ( 'ambiguous' === ( $item['dst'] ?? '' ) ) { $ambiguous[] = $when . ' ' . substr( $item['local'], 11 ) . ' (UTC' . $item['offset'] . ')'; }
		}
		$notes = array();
		if ( $gap ) {
			/* translators: 1: timezone, 2: list of dates with the time used */
			$notes[] = sprintf( __( 'Zeitumstellung (%1$s): Die Uhrzeit gibt es an diesen Tagen nicht, die Termine wurden auf die erste gültige Zeit danach verschoben: %2$s.', 'wp-pass-captain' ), $timezone, implode( ', ', $gap ) );
		}
		if ( $ambiguous ) {
			/* translators: 1: timezone, 2: list of dates with the UTC offset used */
			$notes[] = sprintf( __( 'Zeitumstellung (%1$s): Die Uhrzeit kommt an diesen Tagen zweimal vor; verwendet wurde jeweils die erste (Sommerzeit): %2$s.', 'wp-pass-captain' ), $timezone, implode( ', ', $ambiguous ) );
		}
		return $notes;
	}

	/**
	 * Sales window boundary for a new occurrence: explicit offset before start, else the template
	 * type's offset relative to the template start, else none.
	 */
	public static function sales_time( $starts_at, $offset, $unit, $template_value, $template ) {
		$start = strtotime( $starts_at . ' UTC' );
		if ( null !== $offset ) { return gmdate( 'Y-m-d H:i:s', $start - $offset * $unit ); }
		if ( $template_value && $template ) {
			return gmdate( 'Y-m-d H:i:s', $start - ( strtotime( $template->starts_at . ' UTC' ) - strtotime( $template_value . ' UTC' ) ) );
		}
		return null;
	}

	/** Links created occurrences to a seat-map version, mapping zones like the template's types. */
	private static function link_seat_maps( array $definition, array $type_maps ) {
		if ( ! $definition['seat_version_id'] || ! $type_maps || ! class_exists( '\\PassCaptain\\Seating\\Layouts' ) ) { return 0; }
		$template_zones = $definition['template_occurrence_id'] ? \PassCaptain\Seating\Layouts::type_zones( $definition['template_occurrence_id'] ) : array();
		$linked = 0;
		foreach ( $type_maps as $occurrence_id => $map ) {
			$zones = array();
			foreach ( $map as $template_type => $new_type ) {
				if ( ! empty( $template_zones[ $template_type ] ) ) { $zones[ $new_type ] = $template_zones[ $template_type ]; }
			}
			$result = \PassCaptain\Seating\Layouts::link_occurrence( $occurrence_id, $definition['seat_version_id'], $zones );
			if ( ! is_wp_error( $result ) ) { $linked++; }
		}
		return $linked;
	}

	/** Hash identifying a definition per event (the name is not part of it). */
	public static function definition_hash( array $definition ) {
		unset( $definition['name'] );
		return hash( 'sha256', wp_json_encode( $definition ) );
	}

	/**
	 * Inserts one occurrence of a series with copies of the template's ticket types.
	 *
	 * @return array array( occurrence id, template type id => new type id )
	 */
	private static function insert_occurrence( array $definition, array $item, $template, array $types, $series_id, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ok = $wpdb->insert( "{$p}wpcap_occurrences", array(
			'event_id' => $definition['event_id'],
			'starts_at' => $item['starts_at'],
			'ends_at' => $item['ends_at'],
			'timezone' => $definition['rule']['timezone'],
			'location' => '' !== $definition['location'] ? $definition['location'] : ( $template ? $template->location : '' ),
			'capacity' => $definition['capacity'] ? $definition['capacity'] : ( $template ? (int) $template->capacity : 0 ),
			'status' => 'scheduled',
		) );
		$occurrence_id = (int) $wpdb->insert_id;
		if ( ! $ok || ! $occurrence_id ) { throw new \RuntimeException( 'occurrence_insert' ); }
		$map = array();
		foreach ( $types as $type ) {
			$ok = $wpdb->insert( "{$p}wpcap_ticket_types", array(
				'occurrence_id' => $occurrence_id,
				'name' => $type->name,
				'price_minor' => (int) $type->price_minor,
				'currency' => $type->currency,
				'tax_class' => $type->tax_class,
				'capacity' => (int) $type->capacity,
				'max_per_order' => (int) $type->max_per_order,
				'tax_rate_bp' => (int) $type->tax_rate_bp,
				'sales_start' => self::sales_time( $item['starts_at'], $definition['sales_start_days_before'], 86400, $type->sales_start, $template ),
				'sales_end' => self::sales_time( $item['starts_at'], $definition['sales_end_minutes_before'], 60, $type->sales_end, $template ),
				'status' => $type->status,
			) );
			if ( ! $ok ) { throw new \RuntimeException( 'type_insert' ); }
			$map[ (int) $type->ticket_type_id ] = (int) $wpdb->insert_id;
		}
		$wpdb->insert( "{$p}wpcap_series_occurrences", array( 'series_id' => $series_id, 'occurrence_id' => $occurrence_id, 'starts_at' => $item['starts_at'], 'created_at' => $now ) );
		return array( $occurrence_id, $map );
	}

	/* ---------------------------------------------------------------------------------------------
	 * A128: editing a stored series (rule, default capacity, location, sales windows, seat map).
	 * ------------------------------------------------------------------------------------------- */

	/** Whether the flag columns of schema 3.9.6 exist (older schema during a deferred upgrade: no flags). */
	public static function flags_ready() {
		global $wpdb;
		static $ready = array();
		if ( ! isset( $ready[ $wpdb->prefix ] ) ) { $ready[ $wpdb->prefix ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_series_occurrences LIKE 'flag'" ); }
		return $ready[ $wpdb->prefix ];
	}

	/** Flat view of a definition (rule fields at the top level) for diffs and the history. */
	public static function flat_definition( array $definition ) {
		$out = array();
		foreach ( array( 'name', 'template_occurrence_id', 'location', 'capacity', 'sales_start_days_before', 'sales_end_minutes_before', 'seat_version_id' ) as $key ) {
			$out[ $key ] = $definition[ $key ] ?? null;
		}
		foreach ( array( 'freq', 'interval', 'weekdays', 'month_day', 'nth', 'nth_weekday', 'dates', 'start_date', 'time', 'duration_minutes', 'timezone', 'until', 'count', 'exclusions' ) as $key ) {
			$out[ $key ] = $definition['rule'][ $key ] ?? null;
		}
		return $out;
	}

	/**
	 * Tables (other than the ones a series owns) whose rows point at occurrences or ticket types:
	 * orders, tickets, holds, seat claims, waitlists, guest lists, passes, … Any such row keeps an
	 * occurrence from being deleted when it drops out of the rule.
	 *
	 * @return array list of array( table, column, kind ) with kind occurrence|type.
	 */
	private static function reference_columns() {
		global $wpdb;
		static $cache = array();
		$prefix = $wpdb->prefix . 'wpcap_';
		if ( isset( $cache[ $prefix ] ) ) { return $cache[ $prefix ]; }
		$owned = array( 'occurrences', 'ticket_types', 'series_occurrences', 'seating_links', 'seating_type_zones' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s AND (COLUMN_NAME LIKE %s OR COLUMN_NAME LIKE %s)', str_replace( '_', '\\_', $prefix ) . '%', '%occurrence\\_id', '%ticket\\_type\\_id' ) );
		$out = array();
		foreach ( $rows as $row ) {
			$table = (string) $row->t;
			if ( in_array( substr( $table, strlen( $prefix ) ), $owned, true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table . (string) $row->c ) ) { continue; }
			$out[] = array( $table, (string) $row->c, false !== strpos( (string) $row->c, 'ticket_type_id' ) ? 'type' : 'occurrence' );
		}
		$cache[ $prefix ] = $out;
		return $out;
	}

	/** Tables that still reference an occurrence or its ticket types (empty = it may be deleted). */
	public static function blocking_references( $occurrence_id ) {
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		$type_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence_id ) ) );
		$found = array();
		foreach ( self::reference_columns() as list( $table, $column, $kind ) ) {
			if ( 'type' === $kind && ! $type_ids ) { continue; }
			$where = 'type' === $kind ? "$column IN (" . implode( ',', $type_ids ) . ')' : $wpdb->prepare( "$column=%d", $occurrence_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from information_schema, checked above.
			if ( $wpdb->get_var( "SELECT 1 FROM $table WHERE $where LIMIT 1" ) ) { $found[ $table ] = substr( $table, strlen( $wpdb->prefix ) ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_values( $found );
	}

	/** Sold out: occurrence capacity reached, or every ticket type has a capacity and is full. */
	private static function is_sold_out( $occ, $sold ) {
		if ( (int) $occ->capacity > 0 ) { return $sold >= (int) $occ->capacity; }
		$types = \PassCaptain\Admin\Queries::ticket_types_for_occurrence( $occ->occurrence_id );
		if ( ! $types ) { return false; }
		foreach ( $types as $type ) {
			if ( (int) $type->capacity < 1 || \PassCaptain\Admin\Queries::sold_for_type( $type->ticket_type_id ) < (int) $type->capacity ) { return false; }
		}
		return true;
	}

	/**
	 * Preview of a definition change: which occurrences are added, changed, removed (deleted when
	 * nothing refers to them, else kept and flagged) or skipped. Only future occurrences are
	 * touched; cancelled and sold-out ones only with the explicit options include_cancelled /
	 * include_sold_out. Existing occurrences are matched to the new rule by the date of the slot
	 * they were generated for (so a manually moved occurrence keeps its slot). Only fields whose
	 * definition value changed are pushed to existing occurrences, so manual per-date edits of other
	 * fields stay.
	 *
	 * @param array $options include_sold_out, include_cancelled (bool).
	 * @return array|\WP_Error {series, old_definition, definition, changes, rows[], counts, options, fingerprint, dst_notes}
	 */
	public static function plan_update( $series_id, array $in, array $options = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$series = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_series WHERE series_id=%d", absint( $series_id ) ) );
		if ( ! $series ) { return self::error( 'wpcap_series_missing', __( 'Serie nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$in['event_id'] = (int) $series->event_id;
		$definition = self::definition( $in );
		if ( is_wp_error( $definition ) ) { return $definition; }
		$old = json_decode( (string) $series->definition, true );
		$old = is_array( $old ) ? $old : array();
		$old_rule = is_array( $old['rule'] ?? null ) ? $old['rule'] : array();
		$rule = $definition['rule'];
		$hash = self::definition_hash( $definition );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT series_id FROM {$p}wpcap_series WHERE event_id=%d AND definition_hash=%s AND series_id<>%d", (int) $series->event_id, $hash, (int) $series->series_id ) ) ) {
			return self::error( 'wpcap_series_duplicate', __( 'Eine andere Serie dieses Events hat bereits genau diese Definition.', 'wp-pass-captain' ), 409 );
		}
		$items = Rules::expand( $rule );
		if ( is_wp_error( $items ) ) { return $items; }
		$options = array( 'include_sold_out' => ! empty( $options['include_sold_out'] ), 'include_cancelled' => ! empty( $options['include_cancelled'] ) );
		$now = current_time( 'mysql', true );
		$old_tz = (string) ( $old_rule['timezone'] ?? $rule['timezone'] );
		$template = $definition['template_occurrence_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $definition['template_occurrence_id'] ) ) : null;
		$target_capacity = $definition['capacity'] ? $definition['capacity'] : ( $template ? (int) $template->capacity : null );
		$target_location = '' !== $definition['location'] ? $definition['location'] : ( $template ? (string) $template->location : null );
		$capacity_changed = (int) ( $old['capacity'] ?? 0 ) !== (int) $definition['capacity'] && null !== $target_capacity;
		$location_changed = (string) ( $old['location'] ?? '' ) !== $definition['location'] && null !== $target_location;
		$duration_changed = (int) ( $old_rule['duration_minutes'] ?? 0 ) !== (int) $rule['duration_minutes'];
		$tz_changed = $old_tz !== $rule['timezone'];
		$sales_start_changed = ( $old['sales_start_days_before'] ?? null ) !== $definition['sales_start_days_before'] && null !== $definition['sales_start_days_before'];
		$sales_end_changed = ( $old['sales_end_minutes_before'] ?? null ) !== $definition['sales_end_minutes_before'] && null !== $definition['sales_end_minutes_before'];
		$seat_changed = (int) ( $old['seat_version_id'] ?? 0 ) !== (int) $definition['seat_version_id'];
		$flag_col = self::flags_ready() ? 'so.flag' : "''";
		$linked = $wpdb->get_results( $wpdb->prepare( "SELECT o.*,so.starts_at AS slot_starts_at,$flag_col AS series_flag FROM {$p}wpcap_series_occurrences so INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=so.occurrence_id WHERE so.series_id=%d ORDER BY so.starts_at,o.occurrence_id", (int) $series->series_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal column.
		$by_date = array();
		$unmatched = array();
		foreach ( $linked as $occ ) {
			$slot = \PassCaptain\Admin\Forms::utc_to_local( $occ->slot_starts_at, $old_tz, 'Y-m-d' );
			if ( isset( $by_date[ $slot ] ) ) { $unmatched[] = $occ; } else { $by_date[ $slot ] = $occ; }
		}
		$existing = self::existing_starts( (int) $series->event_id, $items );
		$rows = array();
		$past = 0;
		$unchanged = 0;
		$notes_items = array();
		foreach ( $items as $item ) {
			$date = $item['date'];
			if ( isset( $by_date[ $date ] ) ) {
				$occ = $by_date[ $date ];
				unset( $by_date[ $date ] );
				if ( $occ->starts_at <= $now ) { $past++; continue; }
				$before = array( 'starts_at' => $occ->starts_at, 'ends_at' => $occ->ends_at, 'timezone' => $occ->timezone, 'capacity' => (int) $occ->capacity, 'location' => (string) $occ->location );
				$after = array();
				if ( $item['starts_at'] !== $occ->slot_starts_at ) {
					$after['starts_at'] = $item['starts_at'];
					$after['ends_at'] = $item['ends_at'];
					if ( ! empty( $occ->doors_open_at ) ) {
						$before['doors_open_at'] = $occ->doors_open_at;
						$after['doors_open_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $occ->doors_open_at . ' UTC' ) + strtotime( $item['starts_at'] . ' UTC' ) - strtotime( $occ->starts_at . ' UTC' ) );
					}
				} elseif ( $duration_changed ) {
					$after['ends_at'] = $item['ends_at'];
				}
				if ( $tz_changed ) { $after['timezone'] = $rule['timezone']; }
				if ( $capacity_changed ) { $after['capacity'] = $target_capacity; }
				if ( $location_changed ) { $after['location'] = $target_location; }
				$changes = \PassCaptain\Admin\ChangeHistory::diff( $before, $after );
				$row = array( 'date' => $date, 'occurrence_id' => (int) $occ->occurrence_id, 'status' => $occ->status, 'item' => $item, 'before' => $before, 'changes' => $changes, 'sales' => $sales_start_changed || $sales_end_changed || isset( $changes['starts_at'] ), 'seat' => $seat_changed, 'unflag' => '' !== (string) $occ->series_flag, 'reason' => '' );
				if ( ! $changes && ! $row['sales'] && ! $row['seat'] ) {
					if ( $row['unflag'] ) { $rows[] = array( 'action' => 'unflag' ) + $row; } else { $unchanged++; }
					continue;
				}
				$row['sold'] = \PassCaptain\Admin\Queries::sold_for_occurrence( $occ->occurrence_id );
				$row['issued'] = \PassCaptain\Admin\Queries::issued_ticket_count_for_occurrence( $occ->occurrence_id );
				if ( 'cancelled' === $occ->status && ! $options['include_cancelled'] ) {
					$rows[] = array( 'action' => 'skip', 'reason' => 'cancelled' ) + $row;
				} elseif ( 'cancelled' !== $occ->status && ! $options['include_sold_out'] && self::is_sold_out( $occ, $row['sold'] ) ) {
					$rows[] = array( 'action' => 'skip', 'reason' => 'sold_out' ) + $row;
				} elseif ( isset( $changes['capacity'] ) && (int) $changes['capacity'][1] > 0 && (int) $changes['capacity'][1] < $row['sold'] ) {
					$rows[] = array( 'action' => 'conflict', 'reason' => 'capacity_below_sold' ) + $row;
				} elseif ( isset( $changes['starts_at'] ) && isset( $existing[ $item['starts_at'] ] ) ) {
					$rows[] = array( 'action' => 'conflict', 'reason' => 'exists' ) + $row;
				} else {
					$row['reschedule'] = isset( $changes['starts_at'] ) && $row['issued'] > 0;
					$rows[] = array( 'action' => 'change' ) + $row;
					$notes_items[] = $item;
				}
				continue;
			}
			if ( $item['starts_at'] <= $now ) { $past++; continue; }
			$after = array( 'starts_at' => $item['starts_at'], 'ends_at' => $item['ends_at'], 'capacity' => (int) ( $definition['capacity'] ? $definition['capacity'] : ( $template ? (int) $template->capacity : 0 ) ), 'location' => '' !== $definition['location'] ? $definition['location'] : ( $template ? (string) $template->location : '' ) );
			if ( isset( $existing[ $item['starts_at'] ] ) ) {
				$rows[] = array( 'action' => 'skip', 'reason' => 'exists', 'date' => $date, 'occurrence_id' => 0, 'item' => $item, 'before' => array(), 'changes' => array() );
				continue;
			}
			$rows[] = array( 'action' => 'add', 'reason' => '', 'date' => $date, 'occurrence_id' => 0, 'item' => $item, 'before' => array(), 'changes' => \PassCaptain\Admin\ChangeHistory::diff( array(), $after ) );
			$notes_items[] = $item;
		}
		foreach ( array_merge( array_values( $by_date ), $unmatched ) as $occ ) {
			if ( $occ->starts_at <= $now ) { $past++; continue; }
			$row = array( 'date' => \PassCaptain\Admin\Forms::utc_to_local( $occ->starts_at, $occ->timezone, 'Y-m-d' ), 'occurrence_id' => (int) $occ->occurrence_id, 'status' => $occ->status, 'before' => array( 'starts_at' => $occ->starts_at, 'ends_at' => $occ->ends_at, 'capacity' => (int) $occ->capacity, 'location' => (string) $occ->location ), 'changes' => array(), 'flagged' => '' !== (string) $occ->series_flag );
			$row['sold'] = \PassCaptain\Admin\Queries::sold_for_occurrence( $occ->occurrence_id );
			$row['issued'] = \PassCaptain\Admin\Queries::issued_ticket_count_for_occurrence( $occ->occurrence_id );
			if ( 'cancelled' === $occ->status ) {
				$rows[] = array( 'action' => 'skip', 'reason' => 'cancelled_outside' ) + $row;
				continue;
			}
			$refs = self::blocking_references( $occ->occurrence_id );
			$rows[] = $refs ? array( 'action' => 'flag', 'reason' => $row['sold'] || $row['issued'] ? 'sold' : 'linked', 'references' => $refs ) + $row : array( 'action' => 'delete', 'reason' => '' ) + $row;
		}
		usort( $rows, static function ( $a, $b ) { return array( $a['date'], $a['occurrence_id'] ) <=> array( $b['date'], $b['occurrence_id'] ); } );
		$counts = array( 'add' => 0, 'change' => 0, 'delete' => 0, 'flag' => 0, 'skip' => 0, 'conflict' => 0, 'unflag' => 0, 'unchanged' => $unchanged, 'past' => $past );
		foreach ( $rows as $row ) { $counts[ $row['action'] ]++; }
		$changes = \PassCaptain\Admin\ChangeHistory::diff( self::flat_definition( $old ), self::flat_definition( $definition ) );
		$fingerprint = hash( 'sha256', (string) wp_json_encode( array( $hash, $definition['name'], $options, array_map( static function ( $row ) { return array( $row['action'], $row['occurrence_id'], $row['date'], $row['changes'], $row['reason'] ); }, $rows ) ) ) );
		return array(
			'series' => $series,
			'old_definition' => $old,
			'definition' => $definition,
			'changes' => $changes,
			'rows' => $rows,
			'counts' => $counts,
			'options' => $options,
			'fingerprint' => $fingerprint,
			'dst_notes' => self::dst_notes( $notes_items, $rule['timezone'] ),
		);
	}

	/**
	 * Applies a definition change exactly as previewed (the fingerprint of plan_update() must still
	 * match; otherwise nothing is changed). Fires `wpcap_occurrence_rescheduled` for moved
	 * occurrences with issued tickets, `wpcap_occurrence_changed` for calendar-relevant changes
	 * (revision + 1, A140) and `wpcap_series_updated` at the end.
	 *
	 * @return array|\WP_Error {series_id, event_id, added[], changed[], deleted[], flagged[], skipped, seat_failed[], warning?}
	 */
	public static function update( $series_id, array $in, array $options, $fingerprint ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$plan = self::plan_update( $series_id, $in, $options );
		if ( is_wp_error( $plan ) ) { return $plan; }
		if ( ! is_string( $fingerprint ) || ! hash_equals( $plan['fingerprint'], $fingerprint ) ) {
			return self::error( 'wpcap_series_plan_stale', __( 'Die Termine haben sich seit der Vorschau geändert. Bitte prüfe die Vorschau erneut.', 'wp-pass-captain' ), 409 );
		}
		$definition = $plan['definition'];
		$series_id = (int) $plan['series']->series_id;
		$event_id = (int) $definition['event_id'];
		$now = current_time( 'mysql', true );
		$template = null;
		$types = array();
		if ( $definition['template_occurrence_id'] ) {
			$template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $definition['template_occurrence_id'] ) );
			$types = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d AND status<>'archived' ORDER BY ticket_type_id", $definition['template_occurrence_id'] ) );
		}
		$flags = self::flags_ready();
		$result = array( 'series_id' => $series_id, 'event_id' => $event_id, 'added' => array(), 'changed' => array(), 'deleted' => array(), 'flagged' => array(), 'skipped' => 0, 'seat_failed' => array() );
		$type_maps = array();
		$audits = array();
		$after_commit = array();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_events WHERE event_id=%d FOR UPDATE", $event_id ) );
			$wpdb->update( "{$p}wpcap_series", array( 'name' => $definition['name'], 'template_occurrence_id' => $definition['template_occurrence_id'], 'definition' => wp_json_encode( $definition ), 'definition_hash' => self::definition_hash( $definition ), 'updated_at' => $now ), array( 'series_id' => $series_id ) );
			foreach ( $plan['rows'] as $row ) {
				$occurrence_id = (int) $row['occurrence_id'];
				$unflag_audit = array( 'occurrence_unflagged', $occurrence_id, array( 'event_id' => $event_id, 'series_id' => $series_id, 'changes' => array( 'flag' => array( 'outside_rule', '' ) ) ) );
				switch ( $row['action'] ) {
					case 'add':
						if ( self::existing_starts( $event_id, array( $row['item'] ), true ) ) { $result['skipped']++; break; }
						list( $occurrence_id, $type_maps[ $occurrence_id ] ) = self::insert_occurrence( $definition, $row['item'], $template, $types, $series_id, $now );
						$result['added'][] = $occurrence_id;
						$audits[] = array( 'occurrence_created', $occurrence_id, array( 'event_id' => $event_id, 'series_id' => $series_id, 'starts_at' => $row['item']['starts_at'] ) );
						break;
					case 'change':
						$data = array();
						foreach ( $row['changes'] as $field => $pair ) { $data[ $field ] = $pair[1]; }
						if ( $data ) { $wpdb->update( "{$p}wpcap_occurrences", $data, array( 'occurrence_id' => $occurrence_id ) ); }
						// A140: calendar-relevant changes raise the revision (iCalendar SEQUENCE).
						$row['revision'] = \PassCaptain\Calendar\Revisions::relevant( $row['changes'] ) ? \PassCaptain\Calendar\Revisions::bump( $occurrence_id ) : 0;
						self::update_sales_windows( $occurrence_id, $series_id, $row, $definition, $audits );
						$wpdb->update( "{$p}wpcap_series_occurrences", array( 'starts_at' => $row['item']['starts_at'] ) + ( $flags ? array( 'flag' => '', 'flagged_at' => null ) : array() ), array( 'series_id' => $series_id, 'occurrence_id' => $occurrence_id ) );
						$result['changed'][] = $occurrence_id;
						if ( $row['unflag'] ) { $audits[] = $unflag_audit; }
						if ( $row['changes'] ) { $audits[] = array( 'occurrence_updated', $occurrence_id, array( 'event_id' => $event_id, 'series_id' => $series_id, 'source' => 'series', 'rescheduled' => ! empty( $row['reschedule'] ), 'changes' => $row['changes'] ) ); }
						$after_commit[] = $row;
						break;
					case 'unflag':
					case 'skip':
					case 'conflict':
						if ( ! empty( $row['unflag'] ) && $flags ) {
							$wpdb->update( "{$p}wpcap_series_occurrences", array( 'flag' => '', 'flagged_at' => null ), array( 'series_id' => $series_id, 'occurrence_id' => $occurrence_id ) );
							$audits[] = $unflag_audit;
						}
						if ( 'unflag' !== $row['action'] ) { $result['skipped']++; }
						break;
					case 'delete':
						// Re-checked under the lock: anything that refers to it by now keeps it (flagged).
						$wpdb->query( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occurrence_id ) );
						if ( ! self::blocking_references( $occurrence_id ) ) {
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence_id ) );
							if ( class_exists( '\\PassCaptain\\Seating\\Layouts' ) ) {
								$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \PassCaptain\Seating\Layouts::table( 'type_zones' ) . ' WHERE occurrence_id=%d', $occurrence_id ) );
								$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \PassCaptain\Seating\Layouts::table( 'links' ) . ' WHERE occurrence_id=%d', $occurrence_id ) );
							}
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_series_occurrences WHERE occurrence_id=%d", $occurrence_id ) );
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) );
							$result['deleted'][] = $occurrence_id;
							$audits[] = array( 'occurrence_deleted', $occurrence_id, array( 'event_id' => $event_id, 'series_id' => $series_id, 'starts_at' => $row['before']['starts_at'] ) );
							break;
						}
						// Something refers to it now: keep and flag.
						// Fall through.
					case 'flag':
						if ( $flags && empty( $row['flagged'] ) ) {
							$wpdb->update( "{$p}wpcap_series_occurrences", array( 'flag' => 'outside_rule', 'flagged_at' => $now ), array( 'series_id' => $series_id, 'occurrence_id' => $occurrence_id ) );
							$audits[] = array( 'occurrence_flagged', $occurrence_id, array( 'event_id' => $event_id, 'series_id' => $series_id, 'sold' => (int) ( $row['sold'] ?? 0 ), 'changes' => array( 'flag' => array( '', 'outside_rule' ) ) ) );
						}
						$result['flagged'][] = $occurrence_id;
						break;
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_series_failed', __( 'Die Serie konnte nicht geändert werden; es wurde nichts gespeichert.', 'wp-pass-captain' ), 500 );
		}
		if ( class_exists( '\\PassCaptain\\Seating\\Layouts' ) ) { \PassCaptain\Seating\Layouts::flush_links(); }
		self::link_seat_maps( $definition, $type_maps );
		foreach ( $after_commit as $row ) {
			$occurrence_id = (int) $row['occurrence_id'];
			if ( ! empty( $row['seat'] ) && is_wp_error( self::relink_seat_map( $occurrence_id, $definition ) ) ) {
				$result['seat_failed'][] = $occurrence_id;
			}
			// Reschedule (moved start with issued tickets) and change notification: one mail per save.
			\PassCaptain\Notifications::after_save( $occurrence_id, $row['changes'], (int) ( $row['revision'] ?? 0 ), ! empty( $row['reschedule'] ) );
			if ( isset( $row['changes']['capacity'] ) && 'scheduled' === $row['status'] ) {
				$old_cap = (int) $row['changes']['capacity'][0];
				$new_cap = (int) $row['changes']['capacity'][1];
				if ( $old_cap > 0 && ( 0 === $new_cap || $new_cap > $old_cap ) ) { do_action( 'wpcap_capacity_increased', 0, $occurrence_id ); }
			}
		}
		foreach ( $audits as list( $action, $object_id, $details ) ) {
			Audit::record( $action, 0 === strpos( $action, 'occurrence_' ) ? 'occurrence' : 'ticket_type', $object_id, $details );
		}
		$effects = array( 'add' => count( $result['added'] ), 'change' => count( $result['changed'] ), 'delete' => count( $result['deleted'] ), 'flag' => count( $result['flagged'] ), 'skip' => $result['skipped'] );
		Audit::record( 'series_updated', 'series', $series_id, array( 'event_id' => $event_id, 'series_id' => $series_id, 'changes' => $plan['changes'], 'effects' => $effects, 'options' => $plan['options'] ) );
		if ( $result['seat_failed'] ) {
			/* translators: %d: number of occurrences */
			$result['warning'] = sprintf( __( 'Bei %d Terminen konnte der Saalplan nicht umgestellt werden (z. B. weil verkaufte Plätze in der neuen Version fehlen). Bitte prüfe sie unter Saalpläne.', 'wp-pass-captain' ), count( $result['seat_failed'] ) );
		}
		/**
		 * After a series definition change was applied (A128).
		 *
		 * @param int   $series_id
		 * @param array $result    added, changed, deleted, flagged (occurrence ids), skipped, seat_failed.
		 */
		do_action( 'wpcap_series_updated', $series_id, $result );
		$dst = \PassCaptain\Admin\Forms::dst_warning( $plan['dst_notes'] );
		if ( isset( $result['warning'], $dst['warning'] ) ) { $dst['warning'] = $result['warning'] . ' ' . $dst['warning']; }
		return $dst + $result;
	}

	/**
	 * New sales windows of a changed occurrence's ticket types: an explicit offset of the definition
	 * is applied when it changed or the start moved; otherwise a moved start shifts the existing
	 * window by the same amount.
	 */
	private static function update_sales_windows( $occurrence_id, $series_id, array $row, array $definition, array &$audits ) {
		global $wpdb;
		if ( empty( $row['sales'] ) ) { return; }
		$old_start = $row['before']['starts_at'];
		$new_start = isset( $row['changes']['starts_at'] ) ? $row['changes']['starts_at'][1] : $old_start;
		$delta = strtotime( $new_start . ' UTC' ) - strtotime( $old_start . ' UTC' );
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence_id ) );
		foreach ( $types as $type ) {
			$after = array();
			foreach ( array( 'sales_start' => array( 'sales_start_days_before', 86400 ), 'sales_end' => array( 'sales_end_minutes_before', 60 ) ) as $field => list( $key, $unit ) ) {
				if ( null !== $definition[ $key ] ) {
					$after[ $field ] = gmdate( 'Y-m-d H:i:s', strtotime( $new_start . ' UTC' ) - $definition[ $key ] * $unit );
				} elseif ( $delta && ! empty( $type->$field ) ) {
					$after[ $field ] = gmdate( 'Y-m-d H:i:s', strtotime( $type->$field . ' UTC' ) + $delta );
				}
			}
			$changes = \PassCaptain\Admin\ChangeHistory::diff( (array) $type, $after );
			if ( ! $changes ) { continue; }
			$data = array();
			foreach ( $changes as $field => $pair ) { $data[ $field ] = $pair[1]; }
			$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", $data, array( 'ticket_type_id' => (int) $type->ticket_type_id ) );
			$audits[] = array( 'ticket_type_updated', (int) $type->ticket_type_id, array( 'occurrence_id' => (int) $occurrence_id, 'series_id' => (int) $series_id, 'type_name' => $type->name, 'currency' => $type->currency, 'changes' => $changes ) );
		}
	}

	/** Points a changed occurrence to the series' seat-map version (or removes the link). */
	private static function relink_seat_map( $occurrence_id, array $definition ) {
		global $wpdb;
		if ( ! class_exists( '\\PassCaptain\\Seating\\Layouts' ) ) { return true; }
		if ( ! $definition['seat_version_id'] ) {
			return \PassCaptain\Seating\Layouts::link( $occurrence_id ) ? \PassCaptain\Seating\Layouts::unlink_occurrence( $occurrence_id ) : true;
		}
		$zones = \PassCaptain\Seating\Layouts::type_zones( $occurrence_id );
		if ( ! $zones && $definition['template_occurrence_id'] ) {
			// Map like the template: same ticket type name → same zones/categories.
			$template_zones = \PassCaptain\Seating\Layouts::type_zones( $definition['template_occurrence_id'] );
			$names = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d", $definition['template_occurrence_id'] ) ) as $type ) {
				if ( ! empty( $template_zones[ (int) $type->ticket_type_id ] ) ) { $names[ mb_strtolower( $type->name ) ] = $template_zones[ (int) $type->ticket_type_id ]; }
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence_id ) ) as $type ) {
				if ( isset( $names[ mb_strtolower( $type->name ) ] ) ) { $zones[ (int) $type->ticket_type_id ] = $names[ mb_strtolower( $type->name ) ]; }
			}
		}
		return \PassCaptain\Seating\Layouts::link_occurrence( $occurrence_id, $definition['seat_version_id'], $zones );
	}

	/** Occurrences of a series kept although they fell out of the rule (A128). */
	public static function flagged( $series_id ) {
		global $wpdb;
		if ( ! self::flags_ready() ) { return array(); }
		return $wpdb->get_results( $wpdb->prepare( "SELECT o.*,so.flagged_at FROM {$wpdb->prefix}wpcap_series_occurrences so INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=so.occurrence_id WHERE so.series_id=%d AND so.flag<>'' ORDER BY o.starts_at", absint( $series_id ) ) );
	}

	/** Form input for a stored definition (prefills the edit form). */
	public static function definition_input( array $definition ) {
		$rule = is_array( $definition['rule'] ?? null ) ? $definition['rule'] : array();
		$in = array(
			'event_id' => (string) (int) ( $definition['event_id'] ?? 0 ),
			'name' => (string) ( $definition['name'] ?? '' ),
			'template_occurrence_id' => (string) (int) ( $definition['template_occurrence_id'] ?? 0 ),
			'location' => (string) ( $definition['location'] ?? '' ),
			'capacity' => (string) (int) ( $definition['capacity'] ?? 0 ),
			'sales_start_days_before' => null === ( $definition['sales_start_days_before'] ?? null ) ? '' : (string) (int) $definition['sales_start_days_before'],
			'sales_end_minutes_before' => null === ( $definition['sales_end_minutes_before'] ?? null ) ? '' : (string) (int) $definition['sales_end_minutes_before'],
			'seat_version_id' => (string) (int) ( $definition['seat_version_id'] ?? 0 ),
		);
		foreach ( array( 'freq', 'interval', 'month_day', 'nth', 'nth_weekday', 'start_date', 'time', 'duration_minutes', 'timezone', 'until', 'count' ) as $key ) {
			if ( isset( $rule[ $key ] ) ) { $in[ $key ] = (string) $rule[ $key ]; }
		}
		$in['weekdays'] = array_map( 'strval', (array) ( $rule['weekdays'] ?? array() ) );
		$in['dates'] = implode( "\n", (array) ( $rule['dates'] ?? array() ) );
		$in['exclusions'] = implode( "\n", (array) ( $rule['exclusions'] ?? array() ) );
		return $in;
	}

	/** Stored series of an event. */
	public static function for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.series_id,s.name,s.definition,s.created_at,s.updated_at,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_series_occurrences so WHERE so.series_id=s.series_id) AS occurrences FROM {$wpdb->prefix}wpcap_series s WHERE s.event_id=%d ORDER BY s.series_id DESC", absint( $event_id ) ) );
	}
}
