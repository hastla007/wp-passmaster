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
		if ( ! $event ) { return self::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		if ( 'cancelled' === $event->status ) { return self::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'pass-captain' ), 409 ); }
		$rule = Rules::normalize( $in );
		if ( is_wp_error( $rule ) ) { return $rule; }
		$template_id = absint( $in['template_occurrence_id'] ?? 0 );
		if ( $template_id ) {
			$template = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $template_id ) );
			if ( ! $template || (int) $template->event_id !== $event_id ) { return self::error( 'wpcap_series_template', __( 'Der Vorlagentermin gehört nicht zu diesem Event.', 'pass-captain' ) ); }
		}
		$sales_start = self::optional_int( $in['sales_start_days_before'] ?? '' );
		$sales_end = self::optional_int( $in['sales_end_minutes_before'] ?? '' );
		if ( false === $sales_start || false === $sales_end || ( null !== $sales_start && $sales_start > 730 ) || ( null !== $sales_end && $sales_end > 60 * 24 * 60 ) ) {
			return self::error( 'wpcap_series_sales', __( 'Der Verkaufszeitraum ist ungültig.', 'pass-captain' ) );
		}
		$seat_version = absint( $in['seat_version_id'] ?? 0 );
		if ( $seat_version ) {
			$version_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_seating_versions WHERE version_id=%d", $seat_version ) );
			if ( $version_event !== $event_id ) { return self::error( 'wpcap_series_seat_map', __( 'Die Saalplan-Version gehört nicht zu diesem Event.', 'pass-captain' ) ); }
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
		$hash_source = $definition;
		unset( $hash_source['name'] );
		$hash = hash( 'sha256', wp_json_encode( $hash_source ) );
		$created = array();
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
				$existing[ $item['starts_at'] ] = true;
				$type_maps[ $occurrence_id ] = array();
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
					$type_maps[ $occurrence_id ][ (int) $type->ticket_type_id ] = (int) $wpdb->insert_id;
				}
				$wpdb->insert( "{$p}wpcap_series_occurrences", array( 'series_id' => $series_id, 'occurrence_id' => $occurrence_id, 'starts_at' => $item['starts_at'], 'created_at' => $now ) );
				$created[] = $occurrence_id;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_series SET updated_at=%s WHERE series_id=%d", $now, $series_id ) );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_series_failed', __( 'Die Serientermine konnten nicht angelegt werden; es wurde nichts gespeichert.', 'pass-captain' ), 500 );
		}
		$seat_links = self::link_seat_maps( $definition, $type_maps );
		foreach ( $created as $occurrence_id ) {
			Audit::record( 'occurrence_created', 'occurrence', $occurrence_id, array( 'event_id' => $definition['event_id'], 'series_id' => $series_id ) );
		}
		Audit::record( 'series_generated', 'event', $definition['event_id'], array( 'series_id' => $series_id, 'created' => count( $created ), 'skipped_existing' => $skipped_existing, 'skipped_past' => $skipped_past ) );
		return array( 'series_id' => $series_id, 'event_id' => $definition['event_id'], 'created' => $created, 'skipped_existing' => $skipped_existing, 'skipped_past' => $skipped_past, 'seat_links' => $seat_links );
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

	/** Stored series of an event. */
	public static function for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.series_id,s.name,s.definition,s.created_at,s.updated_at,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_series_occurrences so WHERE so.series_id=s.series_id) AS occurrences FROM {$wpdb->prefix}wpcap_series s WHERE s.event_id=%d ORDER BY s.series_id DESC", absint( $event_id ) ) );
	}
}
