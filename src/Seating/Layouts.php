<?php
/**
 * Versioned seat-map layouts: editable draft per layout, immutable published versions with seat
 * snapshots, and the link of a published version to an occurrence (with ticket type → zone mapping).
 *
 * Invariants:
 * - A published version and its seats are never changed or deleted.
 * - A draft may not move, relabel, rezone or delete a seat that is held or sold in an occurrence
 *   linked to this layout (any version); the editor shows those seats as locked.
 * - An occurrence may only be (re)linked to a version that contains every claimed seat key unchanged.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Seating;

use PassCaptain\Access;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Layouts {
	const CAP = 'wpcap_manage_events';
	const MAX_SEATS = 3000;
	const MAX_STANDING = 10000;
	const MAX_COORD = 10000;

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_seating_' . $name;
	}

	/** Whether wpcap_seating_seats.is_companion exists (schema 3.10.18, A485; deferred upgrades). */
	public static function companion_ready() {
		return \PassCaptain\Log::has_column( 'wpcap_seating_seats', 'is_companion' );
	}

	public static function schema( $prefix, $charset ) {
		$t = $prefix . 'wpcap_seating_';
		return array(
			"CREATE TABLE {$t}layouts (
				layout_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				draft longtext NOT NULL,
				draft_revision int(10) unsigned NOT NULL DEFAULT 0,
				published_version int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (layout_id),
				KEY event_id (event_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}versions (
				version_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				layout_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				definition longtext NOT NULL,
				seat_count int(10) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (version_id),
				UNIQUE KEY layout_version (layout_id,version),
				KEY event_id (event_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}seats (
				seat_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				version_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				kind varchar(16) NOT NULL DEFAULT 'seat',
				group_key varchar(64) NOT NULL DEFAULT '',
				row_label varchar(60) NOT NULL DEFAULT '',
				seat_label varchar(40) NOT NULL DEFAULT '',
				label varchar(120) NOT NULL DEFAULT '',
				zone varchar(64) NOT NULL DEFAULT '',
				category varchar(64) NOT NULL DEFAULT '',
				is_accessible tinyint(1) unsigned NOT NULL DEFAULT 0,
				is_companion tinyint(1) unsigned NOT NULL DEFAULT 0,
				pos_x decimal(9,2) NOT NULL DEFAULT 0,
				pos_y decimal(9,2) NOT NULL DEFAULT 0,
				sort_order int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (seat_id),
				UNIQUE KEY version_seat (version_id,seat_key),
				KEY version_zone (version_id,zone)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}links (
				occurrence_id bigint(20) unsigned NOT NULL,
				layout_id bigint(20) unsigned NOT NULL,
				version_id bigint(20) unsigned NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (occurrence_id),
				KEY layout_id (layout_id),
				KEY version_id (version_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}type_zones (
				occurrence_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				zone varchar(64) NOT NULL,
				PRIMARY KEY  (occurrence_id,ticket_type_id,zone),
				KEY ticket_type_id (ticket_type_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}claims (
				claim_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				active_key varchar(64) NULL,
				seat_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'held',
				release_reason varchar(32) NOT NULL DEFAULT '',
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (claim_id),
				UNIQUE KEY active_seat (occurrence_id,active_key),
				KEY order_id (order_id,status),
				KEY ticket_id (ticket_id),
				KEY occurrence_status (occurrence_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}ticket_seats (
				assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				claim_id bigint(20) unsigned NOT NULL,
				seat_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				label varchar(120) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'assigned',
				assigned_at datetime NOT NULL,
				released_at datetime NULL,
				PRIMARY KEY  (assignment_id),
				KEY ticket_id (ticket_id,status),
				KEY order_id (order_id),
				KEY occurrence_id (occurrence_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Draft normalisation
	 * ------------------------------------------------------------------------------------------- */

	private static function key( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value ) ? $value : '';
	}

	private static function text( $value, $max ) {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
	}

	private static function coord( $value ) {
		$value = is_numeric( $value ) ? round( (float) $value, 2 ) : 0.0;
		return max( 0.0, min( (float) self::MAX_COORD, $value ) );
	}

	private static function color( $value ) {
		return is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : '#8c8f94';
	}

	/** Empty layout for a new map. */
	public static function empty_draft() {
		return array(
			'width' => 1000,
			'height' => 700,
			'zones' => array( array( 'key' => 'parkett', 'name' => __( 'Parkett', 'pass-captain' ), 'color' => '#2271b1' ) ),
			'categories' => array( array( 'key' => 'kat1', 'name' => __( 'Kategorie 1', 'pass-captain' ), 'color' => '#00a32a' ) ),
			'groups' => array(),
			'seats' => array(),
		);
	}

	/**
	 * Validates and normalises an editor draft.
	 *
	 * Format: { width, height, zones:[{key,name,color}], categories:[{key,name,color}],
	 *   groups:[{key,type:row|block|table|standing,label,x,y,w,h,zone,category,capacity,accessible}],
	 *   seats:[{key,group,row,label,x,y,zone,category,accessible,companion}] }
	 * `companion` marks a companion seat next to a wheelchair space (A485): only sold together with an
	 * accessible seat and never assigned automatically.
	 *
	 * @return array|\WP_Error
	 */
	public static function normalize( $draft ) {
		if ( is_string( $draft ) ) { $draft = json_decode( $draft, true ); }
		if ( ! is_array( $draft ) ) {
			return new \WP_Error( 'wpcap_seating_invalid', __( 'Der Saalplan konnte nicht gelesen werden.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$out = array(
			'width' => (int) max( 200, min( self::MAX_COORD, (int) ( $draft['width'] ?? 1000 ) ) ),
			'height' => (int) max( 200, min( self::MAX_COORD, (int) ( $draft['height'] ?? 700 ) ) ),
			'zones' => array(),
			'categories' => array(),
			'groups' => array(),
			'seats' => array(),
		);
		foreach ( array( 'zones', 'categories' ) as $list ) {
			foreach ( array_slice( (array) ( $draft[ $list ] ?? array() ), 0, 50 ) as $item ) {
				$key = sanitize_key( is_array( $item ) ? ( $item['key'] ?? '' ) : '' );
				if ( '' === $key || isset( $out[ $list ][ $key ] ) ) { continue; }
				$name = self::text( $item['name'] ?? '', 60 );
				$out[ $list ][ $key ] = array( 'key' => $key, 'name' => '' !== $name ? $name : $key, 'color' => self::color( $item['color'] ?? '' ) );
			}
		}
		$standing = 0;
		foreach ( (array) ( $draft['groups'] ?? array() ) as $group ) {
			if ( ! is_array( $group ) ) { continue; }
			$key = self::key( $group['key'] ?? '' );
			if ( '' === $key || isset( $out['groups'][ $key ] ) ) {
				return new \WP_Error( 'wpcap_seating_group_key', __( 'Jede Gruppe benötigt einen eindeutigen Schlüssel.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$type = in_array( $group['type'] ?? '', array( 'row', 'block', 'table', 'standing' ), true ) ? $group['type'] : 'row';
			$entry = array(
				'key' => $key,
				'type' => $type,
				'label' => self::text( $group['label'] ?? '', 60 ),
				'x' => self::coord( $group['x'] ?? 0 ),
				'y' => self::coord( $group['y'] ?? 0 ),
				'zone' => isset( $out['zones'][ sanitize_key( $group['zone'] ?? '' ) ] ) ? sanitize_key( $group['zone'] ) : '',
				'category' => isset( $out['categories'][ sanitize_key( $group['category'] ?? '' ) ] ) ? sanitize_key( $group['category'] ) : '',
			);
			if ( 'standing' === $type ) {
				$capacity = absint( $group['capacity'] ?? 0 );
				if ( $capacity < 1 || $capacity > 2000 ) {
					return new \WP_Error( 'wpcap_seating_standing', __( 'Ein Stehbereich braucht eine Kapazität von 1 bis 2000.', 'pass-captain' ), array( 'status' => 400 ) );
				}
				$standing += $capacity;
				$entry['capacity'] = $capacity;
				$entry['w'] = max( 20.0, self::coord( $group['w'] ?? 200 ) );
				$entry['h'] = max( 20.0, self::coord( $group['h'] ?? 120 ) );
				$entry['accessible'] = ! empty( $group['accessible'] );
			}
			$out['groups'][ $key ] = $entry;
		}
		if ( $standing > self::MAX_STANDING ) {
			return new \WP_Error( 'wpcap_seating_standing', __( 'Zu viele Stehplätze im Saalplan.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$seats = (array) ( $draft['seats'] ?? array() );
		if ( count( $seats ) > self::MAX_SEATS ) {
			/* translators: %d: maximum seats */
			return new \WP_Error( 'wpcap_seating_too_many', sprintf( __( 'Ein Saalplan darf höchstens %d Sitzplätze enthalten.', 'pass-captain' ), self::MAX_SEATS ), array( 'status' => 400 ) );
		}
		foreach ( $seats as $seat ) {
			if ( ! is_array( $seat ) ) { continue; }
			$key = self::key( $seat['key'] ?? '' );
			if ( '' === $key || isset( $out['seats'][ $key ] ) ) {
				return new \WP_Error( 'wpcap_seating_seat_key', __( 'Jeder Sitzplatz benötigt einen eindeutigen Schlüssel.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$label = self::text( $seat['label'] ?? '', 40 );
			if ( '' === $label ) {
				return new \WP_Error( 'wpcap_seating_seat_label', __( 'Jeder Sitzplatz benötigt eine Bezeichnung.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$group = self::key( $seat['group'] ?? '' );
			$group = isset( $out['groups'][ $group ] ) && 'standing' !== $out['groups'][ $group ]['type'] ? $group : '';
			$zone = sanitize_key( $seat['zone'] ?? '' );
			$category = sanitize_key( $seat['category'] ?? '' );
			$out['seats'][ $key ] = array(
				'key' => $key,
				'group' => $group,
				'row' => self::text( $seat['row'] ?? '', 60 ),
				'label' => $label,
				'x' => self::coord( $seat['x'] ?? 0 ),
				'y' => self::coord( $seat['y'] ?? 0 ),
				'zone' => isset( $out['zones'][ $zone ] ) ? $zone : '',
				'category' => isset( $out['categories'][ $category ] ) ? $category : '',
				'accessible' => ! empty( $seat['accessible'] ),
				'companion' => ! empty( $seat['companion'] ) && empty( $seat['accessible'] ),
			);
		}
		// A528: standing spots get the keys "{group}-{n}" (expand()). A seat or another standing area with
		// the same key would make spots disappear silently – refused, naming the key.
		$taken = array_fill_keys( array_map( 'strval', array_keys( $out['seats'] ) ), true );
		foreach ( $out['groups'] as $group ) {
			if ( 'standing' !== $group['type'] ) { continue; }
			for ( $n = 1; $n <= $group['capacity']; $n++ ) {
				$spot = self::standing_key( $group['key'], $n );
				if ( isset( $taken[ $spot ] ) ) {
					return new \WP_Error(
						'wpcap_seating_standing_key',
						/* translators: 1: key of a standing spot, 2: standing area key */
						sprintf( __( 'Der Stehplatz „%1$s“ des Stehbereichs „%2$s“ hat denselben Schlüssel wie ein Sitzplatz oder ein anderer Stehplatz. Bitte den Schlüssel des Stehbereichs oder des Sitzplatzes ändern.', 'pass-captain' ), $spot, $group['key'] ),
						array( 'status' => 400, 'seat_key' => $spot )
					);
				}
				$taken[ $spot ] = true;
			}
		}
		$out['zones'] = array_values( $out['zones'] );
		$out['categories'] = array_values( $out['categories'] );
		$out['groups'] = array_values( $out['groups'] );
		$out['seats'] = array_values( $out['seats'] );
		return $out;
	}

	/** Key of standing spot $n of a standing area (A528: also used for the collision check). */
	public static function standing_key( $group_key, $n ) {
		return substr( (string) $group_key, 0, 54 ) . '-' . (int) $n;
	}

	/** Display label of a seat. */
	public static function display_label( $row, $label, $kind = 'seat' ) {
		if ( 'standing' === $kind ) {
			/* translators: %s: standing area */
			return sprintf( __( '%s (Stehplatz)', 'pass-captain' ), '' !== $row ? $row : __( 'Stehbereich', 'pass-captain' ) );
		}
		/* translators: 1: row or table, 2: seat */
		return '' !== $row ? sprintf( __( '%1$s, Platz %2$s', 'pass-captain' ), $row, $label ) : $label;
	}

	/**
	 * Expands a normalised draft into seat rows (standing areas become numbered spots; the effective
	 * zone/category of a seat falls back to its group's).
	 */
	public static function expand( array $layout ) {
		$groups = array();
		foreach ( $layout['groups'] as $group ) { $groups[ $group['key'] ] = $group; }
		$rows = array();
		$order = 0;
		foreach ( $layout['seats'] as $seat ) {
			$group = $groups[ $seat['group'] ] ?? null;
			$rows[ $seat['key'] ] = array(
				'seat_key' => $seat['key'],
				'kind' => 'seat',
				'group_key' => $seat['group'],
				'row_label' => $seat['row'],
				'seat_label' => $seat['label'],
				'label' => self::display_label( $seat['row'], $seat['label'] ),
				'zone' => '' !== $seat['zone'] ? $seat['zone'] : ( $group['zone'] ?? '' ),
				'category' => '' !== $seat['category'] ? $seat['category'] : ( $group['category'] ?? '' ),
				'accessible' => $seat['accessible'] ? 1 : 0,
				'companion' => ! empty( $seat['companion'] ) ? 1 : 0,
				'pos_x' => $seat['x'],
				'pos_y' => $seat['y'],
				'sort_order' => ++$order,
			);
		}
		foreach ( $groups as $group ) {
			if ( 'standing' !== $group['type'] ) { continue; }
			for ( $n = 1; $n <= $group['capacity']; $n++ ) {
				$key = self::standing_key( $group['key'], $n );
				if ( isset( $rows[ $key ] ) ) { continue; } // Refused by normalize() (A528); older stored drafts only.
				$rows[ $key ] = array(
					'seat_key' => $key,
					'kind' => 'standing',
					'group_key' => $group['key'],
					'row_label' => $group['label'],
					'seat_label' => (string) $n,
					'label' => self::display_label( $group['label'], (string) $n, 'standing' ),
					'zone' => $group['zone'],
					'category' => $group['category'],
					'accessible' => ! empty( $group['accessible'] ) ? 1 : 0,
					'companion' => 0,
					'pos_x' => round( $group['x'] + $group['w'] / 2, 2 ),
					'pos_y' => round( $group['y'] + $group['h'] / 2, 2 ),
					'sort_order' => ++$order,
				);
			}
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Queries
	 * ------------------------------------------------------------------------------------------- */

	public static function get( $layout_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE layout_id=%d', self::table( 'layouts' ), absint( $layout_id ) ) );
	}

	public static function for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT layout_id,event_id,name,draft_revision,published_version,updated_at FROM %i WHERE event_id=%d ORDER BY layout_id', self::table( 'layouts' ), absint( $event_id ) ) );
	}

	public static function version( $version_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id=%d', self::table( 'versions' ), absint( $version_id ) ) );
	}

	public static function versions( $layout_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT version_id,layout_id,event_id,version,name,seat_count,created_by,created_at FROM %i WHERE layout_id=%d ORDER BY version DESC', self::table( 'versions' ), absint( $layout_id ) ) );
	}

	public static function versions_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT version_id,layout_id,version,name,seat_count FROM %i WHERE event_id=%d ORDER BY layout_id,version DESC', self::table( 'versions' ), absint( $event_id ) ) );
	}

	public static function seats( $version_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id=%d ORDER BY sort_order,seat_id', self::table( 'seats' ), absint( $version_id ) ) );
	}

	public static function link( $occurrence_id ) {
		$occurrence_id = absint( $occurrence_id );
		if ( ! $occurrence_id ) { return null; }
		if ( null === self::$links || ! array_key_exists( $occurrence_id, self::$links ) ) { self::prime_links( array( $occurrence_id ) ); }
		return self::$links[ $occurrence_id ] ?? null;
	}

	/**
	 * Loads the seat map links of these occurrences in one query (listings: action
	 * `wpcap_listing_occurrences`); later link() calls for them need no query. Only the requested
	 * occurrences are read – not every link ever made (A376).
	 *
	 * @param int[] $occurrence_ids Occurrences.
	 */
	public static function prime_links( array $occurrence_ids ) {
		global $wpdb;
		if ( null === self::$links ) { self::$links = array(); }
		$missing = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $occurrence_ids ) ) ), array_keys( self::$links ) ) );
		foreach ( array_chunk( $missing, 500 ) as $chunk ) {
			foreach ( $chunk as $id ) { self::$links[ $id ] = null; }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( self::table( 'links' ) ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array (generated IN placeholders).
			foreach ( (array) $rows as $row ) { self::$links[ (int) $row->occurrence_id ] = $row; }
		}
	}

	/** Request cache of seat map links (occurrence id => row|null); reset after writes (wpcap_availability_flushed). */
	private static $links = null;

	/** Drops the request caches of seat map links (this class and Reservations' linked occurrences). */
	public static function flush_links() {
		self::$links = null;
		Reservations::flush_link_cache();
	}

	/** ticket_type_id => list of zone/category keys for an occurrence. */
	public static function type_zones( $occurrence_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,zone FROM %i WHERE occurrence_id=%d ORDER BY ticket_type_id,zone', self::table( 'type_zones' ), absint( $occurrence_id ) ) ) as $row ) {
			$out[ (int) $row->ticket_type_id ][] = $row->zone;
		}
		return $out;
	}

	/**
	 * Seats currently held or sold in any occurrence linked to the layout, keyed by seat_key, with
	 * the snapshot (label, position, zone) they were claimed with.
	 */
	public static function locked_seats( $layout_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT c.seat_key,c.status,s.kind,s.group_key,s.row_label,s.seat_label,s.label,s.zone,s.category,s.is_accessible,s.pos_x,s.pos_y FROM %i c INNER JOIN %i l ON l.occurrence_id=c.occurrence_id INNER JOIN %i s ON s.seat_id=c.seat_id WHERE l.layout_id=%d AND c.active_key IS NOT NULL ORDER BY c.claim_id', self::table( 'claims' ), self::table( 'links' ), self::table( 'seats' ),
			absint( $layout_id )
		) );
		$out = array();
		foreach ( $rows as $row ) {
			if ( isset( $out[ $row->seat_key ] ) && 'sold' !== $row->status ) { continue; }
			$out[ $row->seat_key ] = $row;
		}
		return $out;
	}

	/** Returns the keys of locked seats that $rows (expanded seats) would move, change or remove. */
	public static function protection_violations( array $locked, array $rows ) {
		$violations = array();
		foreach ( $locked as $key => $seat ) {
			$new = $rows[ $key ] ?? null;
			$ok = $new && $new['kind'] === $seat->kind && $new['zone'] === $seat->zone && $new['category'] === $seat->category;
			if ( $ok && 'seat' === $seat->kind ) {
				$ok = $new['row_label'] === $seat->row_label && $new['seat_label'] === $seat->seat_label
					&& abs( (float) $new['pos_x'] - (float) $seat->pos_x ) < 0.01 && abs( (float) $new['pos_y'] - (float) $seat->pos_y ) < 0.01;
			}
			if ( ! $ok ) { $violations[ $key ] = $seat->label; }
		}
		return $violations;
	}

	private static function violation_error( array $violations ) {
		return new \WP_Error(
			'wpcap_seating_locked_seat',
			/* translators: %s: seat labels */
			sprintf( __( 'Verkaufte oder reservierte Plätze dürfen nicht verschoben, geändert oder gelöscht werden: %s', 'pass-captain' ), implode( '; ', array_slice( array_values( $violations ), 0, 10 ) ) . ( count( $violations ) > 10 ? ' …' : '' ) ),
			array( 'status' => 409, 'seat_keys' => array_keys( $violations ) )
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Commands (all check capability and event scope)
	 * ------------------------------------------------------------------------------------------- */

	public static function create( $event_id, $name ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id,status FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', $event_id ) );
		if ( ! $event ) { return new \WP_Error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::forbidden(); }
		$name = self::text( $name, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_seating_name', __( 'Bitte einen Namen für den Saalplan angeben.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$now = \PassCaptain\Clock::mysql();
		$wpdb->insert( self::table( 'layouts' ), array( 'event_id' => $event_id, 'name' => $name, 'draft' => wp_json_encode( self::empty_draft() ), 'draft_revision' => 0, 'published_version' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		$layout_id = (int) $wpdb->insert_id;
		if ( ! $layout_id ) { return new \WP_Error( 'wpcap_seating_save', __( 'Der Saalplan konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'seat_map_created', 'event', $event_id, array( 'layout_id' => $layout_id ) );
		return array( 'layout_id' => $layout_id, 'event_id' => $event_id );
	}

	/** Keys of a list of {key,...} items. */
	private static function item_keys( $items ) {
		return array_values( array_filter( array_map( static function ( $item ) { return is_array( $item ) ? (string) ( $item['key'] ?? '' ) : ''; }, (array) $items ) ) );
	}

	private static function forbidden() {
		return new \WP_Error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/**
	 * Saves the draft. $revision is the revision the editor started from (optimistic locking, 0 skips
	 * the check). Rejects drafts that change locked seats.
	 */
	public static function save_draft( $layout_id, $draft, $revision = 0, $name = null ) {
		global $wpdb;
		$layout = self::get( $layout_id );
		if ( ! $layout ) { return new \WP_Error( 'wpcap_seating_missing', __( 'Saalplan nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $layout->event_id, self::CAP ) ) { return self::forbidden(); }
		$normalized = self::normalize( $draft );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$violations = self::protection_violations( self::locked_seats( $layout->layout_id ), self::expand( $normalized ) );
		if ( $violations ) { return self::violation_error( $violations ); }
		$new_name = null !== $name ? self::text( $name, 190 ) : '';
		$revision = absint( $revision ); // 0 = no revision check.
		// The name only changes when a non-empty one is given.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET draft=%s,updated_at=%s,name=IF(%d=1,%s,name),draft_revision=draft_revision+1 WHERE layout_id=%d AND (%d=0 OR draft_revision=%d)",
			self::table( 'layouts' ),
			wp_json_encode( $normalized ),
			\PassCaptain\Clock::mysql(),
			'' !== $new_name ? 1 : 0,
			$new_name,
			(int) $layout->layout_id,
			$revision,
			$revision
		) );
		if ( ! $updated ) {
			return new \WP_Error( 'wpcap_seating_conflict', __( 'Der Saalplan wurde inzwischen von jemand anderem geändert. Bitte neu laden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		return array( 'layout_id' => (int) $layout->layout_id, 'event_id' => (int) $layout->event_id, 'revision' => (int) $layout->draft_revision + 1, 'seat_count' => count( self::expand( $normalized ) ) );
	}

	/**
	 * Publishes the current draft as a new immutable version. With $upgrade_links, occurrences linked
	 * to this layout move to the new version (their held/sold seats are guaranteed unchanged).
	 */
	public static function publish( $layout_id, $upgrade_links = true ) {
		global $wpdb;
		$layout = self::get( $layout_id );
		if ( ! $layout ) { return new \WP_Error( 'wpcap_seating_missing', __( 'Saalplan nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $layout->event_id, self::CAP ) ) { return self::forbidden(); }
		$wpdb->query( 'START TRANSACTION' );
		try {
			$layout = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE layout_id=%d FOR UPDATE', self::table( 'layouts' ), (int) $layout->layout_id ) );
			$normalized = self::normalize( $layout->draft );
			if ( is_wp_error( $normalized ) ) { throw new SeatingException( $normalized ); }
			$rows = self::expand( $normalized );
			if ( ! $rows ) { throw new SeatingException( new \WP_Error( 'wpcap_seating_empty', __( 'Ein leerer Saalplan kann nicht veröffentlicht werden.', 'pass-captain' ), array( 'status' => 400 ) ) ); }
			// Lock the links first so no seat of a linked occurrence can be claimed while we check.
			$links = $wpdb->get_results( $wpdb->prepare( 'SELECT occurrence_id,version_id FROM %i WHERE layout_id=%d ORDER BY occurrence_id FOR UPDATE', self::table( 'links' ), (int) $layout->layout_id ) );
			$violations = self::protection_violations( self::locked_seats( $layout->layout_id ), $rows );
			if ( $violations ) { throw new SeatingException( self::violation_error( $violations ) ); }
			// A487: moving the linked occurrences must not leave a ticket type without its zones/seats.
			if ( $upgrade_links && $links ) {
				$lost = self::mapping_losses( array_map( static function ( $link ) { return (int) $link->occurrence_id; }, $links ), $normalized, $rows );
				if ( $lost ) { throw new SeatingException( self::mapping_error( $lost ) ); }
			}
			$version = (int) $layout->published_version + 1;
			$now = \PassCaptain\Clock::mysql();
			$inserted = $wpdb->insert( self::table( 'versions' ), array( 'layout_id' => (int) $layout->layout_id, 'event_id' => (int) $layout->event_id, 'version' => $version, 'name' => $layout->name, 'definition' => wp_json_encode( $normalized ), 'seat_count' => count( $rows ), 'created_by' => get_current_user_id(), 'created_at' => $now ) );
			$version_id = (int) $wpdb->insert_id;
			if ( ! $inserted || ! $version_id ) { throw new SeatingException( new \WP_Error( 'wpcap_seating_save', __( 'Die Version konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ) ); }
			$companions = self::companion_ready();
			foreach ( array_chunk( array_values( $rows ), 200 ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $row ) {
					$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s,%s,%d,%f,%f,%d' . ( $companions ? ',%d' : '' ) . ')', array_merge( array( $version_id, $row['seat_key'], $row['kind'], $row['group_key'], $row['row_label'], $row['seat_label'], $row['label'], $row['zone'], $row['category'], $row['accessible'], $row['pos_x'], $row['pos_y'], $row['sort_order'] ), $companions ? array( (int) ( $row['companion'] ?? 0 ) ) : array() ) );
				}
				$ok = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (version_id,seat_key,kind,group_key,row_label,seat_label,label,zone,category,is_accessible,pos_x,pos_y,sort_order' . ( $companions ? ',is_companion' : '' ) . ') VALUES ', self::table( 'seats' ) ) . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- value tuples prepared above.
				if ( false === $ok ) { throw new SeatingException( new \WP_Error( 'wpcap_seating_save', __( 'Die Sitzplätze konnten nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ) ); }
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET published_version=%d,updated_at=%s WHERE layout_id=%d', self::table( 'layouts' ), $version, $now, (int) $layout->layout_id ) );
			$upgraded = array();
			if ( $upgrade_links ) {
				foreach ( $links as $link ) {
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET version_id=%d,updated_at=%s WHERE occurrence_id=%d', self::table( 'links' ), $version_id, $now, (int) $link->occurrence_id ) );
					$upgraded[] = (int) $link->occurrence_id;
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( SeatingException $e ) {
			$wpdb->query( 'ROLLBACK' );
			return $e->error;
		}
		Audit::record( 'seat_map_published', 'event', (int) $layout->event_id, array( 'layout_id' => (int) $layout->layout_id, 'version' => $version, 'seats' => count( $rows ), 'upgraded_occurrences' => $upgraded ) );
		return array( 'layout_id' => (int) $layout->layout_id, 'event_id' => (int) $layout->event_id, 'version' => $version, 'version_id' => $version_id, 'seat_count' => count( $rows ), 'upgraded' => $upgraded );
	}

	/**
	 * Ticket types of linked occurrences whose zone/category mapping would point to nothing in a new
	 * version (a mapped zone is missing, or no seat of the version matches): "Event – Ticketart" labels.
	 *
	 * @param int[] $occurrence_ids Linked occurrences.
	 * @param array $definition     Normalised draft of the new version.
	 * @param array $rows           Expanded seats of the new version.
	 * @return string[]
	 */
	public static function mapping_losses( array $occurrence_ids, array $definition, array $rows ) {
		global $wpdb;
		$known = array_merge( self::item_keys( $definition['zones'] ?? array() ), self::item_keys( $definition['categories'] ?? array() ) );
		$lost = array();
		foreach ( $occurrence_ids as $occurrence_id ) {
			foreach ( self::type_zones( $occurrence_id ) as $type_id => $zones ) {
				$missing = array_diff( $zones, $known );
				$seats = 0;
				foreach ( $rows as $row ) {
					if ( in_array( (string) $row['zone'], $zones, true ) || in_array( (string) $row['category'], $zones, true ) ) { ++$seats; }
				}
				if ( ! $missing && $seats ) { continue; }
				$name = $wpdb->get_row( $wpdb->prepare( "SELECT tt.name,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", (int) $type_id ) );
				$lost[] = $name ? $name->name . ' (' . \PassCaptain\Admin\Forms::datetime( $name->starts_at, $name->timezone, false ) . ')' : '#' . (int) $type_id;
			}
		}
		return array_values( array_unique( $lost ) );
	}

	private static function mapping_error( array $lost ) {
		return new \WP_Error(
			'wpcap_seating_mapping_lost',
			/* translators: %s: ticket types with date */
			sprintf( __( 'In der neuen Version fehlen Bereiche bzw. Plätze, denen Ticketarten verknüpfter Termine zugeordnet sind: %s. Die Version wurde nicht veröffentlicht. Bitte die Bereiche im Entwurf wiederherstellen oder ohne Umstellung der Termine veröffentlichen und die Termine danach mit neuer Zuordnung verknüpfen.', 'pass-captain' ), implode( '; ', array_slice( $lost, 0, 10 ) ) . ( count( $lost ) > 10 ? ' …' : '' ) ),
			array( 'status' => 409, 'ticket_types' => $lost )
		);
	}

	/**
	 * Seats a ticket type can be sold on per mapping (A486): ticket_type_id => number of seats of the
	 * version in its zones/categories, for an occurrence and version.
	 */
	public static function mapped_seat_counts( $version_id, array $type_zones ) {
		global $wpdb;
		$out = array();
		foreach ( $type_zones as $type_id => $zones ) {
			$zones = array_values( array_filter( array_map( 'strval', (array) $zones ) ) );
			if ( ! $zones ) { continue; }
			$in = implode( ',', array_fill( 0, count( $zones ), '%s' ) );
			$out[ (int) $type_id ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'seats' ) . " WHERE version_id=%d AND (zone IN ($in) OR category IN ($in))", array_merge( array( (int) $version_id ), $zones, $zones ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		}
		return $out;
	}

	/**
	 * Links an occurrence to a published version and maps its ticket types to zones/categories.
	 *
	 * @param array $type_zones ticket_type_id => list of zone or category keys.
	 */
	public static function link_occurrence( $occurrence_id, $version_id, array $type_zones ) {
		global $wpdb;
		$occurrence = $wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_id,event_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
		$version = self::version( $version_id );
		if ( ! $occurrence || ! $version ) { return new \WP_Error( 'wpcap_seating_missing', __( 'Termin oder Saalplan-Version nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $occurrence->event_id, self::CAP ) ) { return self::forbidden(); }
		if ( (int) $version->event_id !== (int) $occurrence->event_id ) {
			return new \WP_Error( 'wpcap_seating_event_mismatch', __( 'Der Saalplan gehört zu einem anderen Event.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$definition = json_decode( $version->definition, true );
		$known = array_merge( self::item_keys( $definition['zones'] ?? array() ), self::item_keys( $definition['categories'] ?? array() ) );
		$type_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_type_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_ticket_types', (int) $occurrence->occurrence_id ) ) );
		$clean = array();
		foreach ( $type_zones as $type_id => $zones ) {
			$type_id = absint( $type_id );
			if ( ! in_array( $type_id, $type_ids, true ) ) {
				return new \WP_Error( 'wpcap_seating_type', __( 'Eine Ticketart gehört nicht zu diesem Termin.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			foreach ( (array) $zones as $zone ) {
				$zone = sanitize_key( $zone );
				if ( '' === $zone ) { continue; }
				if ( ! in_array( $zone, $known, true ) ) {
					return new \WP_Error( 'wpcap_seating_zone', __( 'Ein Bereich bzw. eine Kategorie existiert in dieser Version nicht.', 'pass-captain' ), array( 'status' => 400 ) );
				}
				$clean[ $type_id ][ $zone ] = $zone;
			}
		}
		$now = \PassCaptain\Clock::mysql();
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE occurrence_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_occurrences', (int) $occurrence->occurrence_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d FOR UPDATE', self::table( 'links' ), (int) $occurrence->occurrence_id ) );
		// Claimed seats of this occurrence must exist unchanged in the target version.
		$claimed = $wpdb->get_results( $wpdb->prepare( 'SELECT c.seat_key,s.kind,s.group_key,s.row_label,s.seat_label,s.label,s.zone,s.category,s.is_accessible,s.pos_x,s.pos_y FROM %i c INNER JOIN %i s ON s.seat_id=c.seat_id WHERE c.occurrence_id=%d AND c.active_key IS NOT NULL', self::table( 'claims' ), self::table( 'seats' ), (int) $occurrence->occurrence_id ) );
		if ( $claimed ) {
			$locked = array();
			foreach ( $claimed as $row ) { $locked[ $row->seat_key ] = $row; }
			$target = array();
			foreach ( self::seats( $version->version_id ) as $seat ) { $target[ $seat->seat_key ] = (array) $seat; }
			$violations = self::protection_violations( $locked, $target );
			if ( $violations ) { $wpdb->query( 'ROLLBACK' ); return self::violation_error( $violations ); }
		}
		if ( $existing ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET layout_id=%d,version_id=%d,updated_at=%s WHERE occurrence_id=%d', self::table( 'links' ), (int) $version->layout_id, (int) $version->version_id, $now, (int) $occurrence->occurrence_id ) );
		} else {
			$wpdb->insert( self::table( 'links' ), array( 'occurrence_id' => (int) $occurrence->occurrence_id, 'layout_id' => (int) $version->layout_id, 'version_id' => (int) $version->version_id, 'updated_at' => $now ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE occurrence_id=%d', self::table( 'type_zones' ), (int) $occurrence->occurrence_id ) );
		foreach ( $clean as $type_id => $zones ) {
			foreach ( $zones as $zone ) {
				$wpdb->insert( self::table( 'type_zones' ), array( 'occurrence_id' => (int) $occurrence->occurrence_id, 'ticket_type_id' => $type_id, 'zone' => $zone ) );
			}
		}
		$wpdb->query( 'COMMIT' );
		self::flush_links();
		Audit::record( 'seat_map_linked', 'occurrence', (int) $occurrence->occurrence_id, array( 'version_id' => (int) $version->version_id, 'version' => (int) $version->version ) );
		$result = array( 'occurrence_id' => (int) $occurrence->occurrence_id, 'event_id' => (int) $occurrence->event_id, 'version_id' => (int) $version->version_id );
		$warnings = self::link_warnings( (int) $occurrence->occurrence_id, (int) $version->version_id, $clean );
		if ( $warnings ) { $result['warning'] = implode( ' ', $warnings ); }
		return $result;
	}

	/**
	 * A486: ticket types whose quota exceeds the seats they are mapped to sell at most these seats –
	 * the link warns about it (and about mapped types without any seat).
	 *
	 * @return string[] Messages.
	 */
	public static function link_warnings( $occurrence_id, $version_id, array $type_zones ) {
		global $wpdb;
		$counts = self::mapped_seat_counts( $version_id, $type_zones );
		if ( ! $counts ) { return array(); }
		$capacity = (int) $wpdb->get_var( $wpdb->prepare( "SELECT capacity FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		$out = array();
		foreach ( $counts as $type_id => $seats ) {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT name,capacity FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
			if ( ! $type ) { continue; }
			$quota = (int) $type->capacity > 0 ? (int) $type->capacity : $capacity;
			if ( 0 === $seats ) {
				/* translators: %s: ticket type */
				$out[] = sprintf( __( '„%s“ ist keinem Platz dieser Version zugeordnet und kann nicht verkauft werden.', 'pass-captain' ), $type->name );
			} elseif ( 0 === $quota || $quota > $seats ) {
				/* translators: 1: ticket type, 2: matching seats, 3: quota of the ticket type */
				$out[] = sprintf( __( '„%1$s“ passt nur auf %2$d Plätze des Saalplans (Kontingent: %3$s); verkauft werden höchstens diese Plätze.', 'pass-captain' ), $type->name, $seats, $quota ? (string) $quota : __( 'unbegrenzt', 'pass-captain' ) );
			}
		}
		return $out;
	}

	/** Removes the seat map from an occurrence (only while no seat is held or sold). */
	public static function unlink_occurrence( $occurrence_id ) {
		global $wpdb;
		$occurrence = $wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_id,event_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
		if ( ! $occurrence ) { return new \WP_Error( 'wpcap_seating_missing', __( 'Termin nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( $occurrence->event_id, self::CAP ) ) { return self::forbidden(); }
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE occurrence_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_occurrences', (int) $occurrence->occurrence_id ) );
		$claimed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurrence_id=%d AND active_key IS NOT NULL', self::table( 'claims' ), (int) $occurrence->occurrence_id ) );
		if ( $claimed ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_seating_in_use', __( 'Für diesen Termin sind bereits Plätze reserviert oder verkauft; der Saalplan kann nicht entfernt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE occurrence_id=%d', self::table( 'links' ), (int) $occurrence->occurrence_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE occurrence_id=%d', self::table( 'type_zones' ), (int) $occurrence->occurrence_id ) );
		$wpdb->query( 'COMMIT' );
		self::flush_links();
		Audit::record( 'seat_map_unlinked', 'occurrence', (int) $occurrence->occurrence_id );
		return array( 'occurrence_id' => (int) $occurrence->occurrence_id, 'event_id' => (int) $occurrence->event_id );
	}
}

