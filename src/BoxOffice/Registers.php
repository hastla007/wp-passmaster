<?php
/**
 * Box office registers ("Kassen" / cash drawers).
 *
 * A register has a name, an optional location text and event restriction, a currency and a blind
 * count setting. It has at most one open shift at a time (UNIQUE wpcap_boxoffice_sessions.open_register)
 * and its own gapless Z report sequence with a cumulative grand total (see Shifts and ZReport).
 * Registers are never deleted, only deactivated, so their Z history stays complete.
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Registers {
	/** Capability to manage registers, view all shifts and Z reports and book corrections. */
	const MANAGE_CAP = 'manage_options';
	/** Option holding the default register (legacy open_session() and the migration use it). */
	const DEFAULT_OPTION = 'wpcap_boxoffice_default_register';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_boxoffice_registers';
	}

	public static function get( $register_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE register_id=%d', absint( $register_id ) ) );
	}

	/** @return object[] Registers ordered by name (inactive ones only with $include_inactive). */
	public static function all( $include_inactive = false ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ( $include_inactive ? '' : " WHERE status='active'" ) . ' ORDER BY name,register_id' );
	}

	/** Whether the current user may manage registers (create/edit, corrections, all Z reports). */
	public static function can_manage() {
		return current_user_can( self::MANAGE_CAP );
	}

	/**
	 * Creates or updates a register.
	 *
	 * @param array $data name, location, event_id, currency, blind_count (bool), status (active|inactive).
	 * @return object|\WP_Error Register row.
	 */
	public static function save( array $data, $register_id = 0 ) {
		global $wpdb;
		if ( ! self::can_manage() ) { return new \WP_Error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$register_id = absint( $register_id );
		$existing = $register_id ? self::get( $register_id ) : null;
		if ( $register_id && ! $existing ) { return new \WP_Error( 'wpcap_pos_register', __( 'Kasse nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$name = mb_substr( trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) ), 0, 100 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_pos_register_name', __( 'Bitte einen Namen für die Kasse angeben.', 'wp-pass-captain' ) ); }
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? ( $existing ? $existing->currency : 'EUR' ) ) ) );
		$currency = 3 === strlen( $currency ) ? $currency : 'EUR';
		$status = 'inactive' === ( $data['status'] ?? 'active' ) ? 'inactive' : 'active';
		if ( $existing && $existing->currency !== $currency && Shifts::open_for_register( $register_id ) ) {
			return new \WP_Error( 'wpcap_pos_register_busy', __( 'Die Währung kann nicht geändert werden, solange eine Schicht offen ist.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		if ( $existing && 'inactive' === $status && Shifts::open_for_register( $register_id ) ) {
			return new \WP_Error( 'wpcap_pos_register_busy', __( 'Eine Kasse mit offener Schicht kann nicht deaktiviert werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$row = array(
			'name' => $name,
			'location' => mb_substr( sanitize_text_field( (string) ( $data['location'] ?? '' ) ), 0, 190 ),
			'event_id' => absint( $data['event_id'] ?? 0 ),
			'currency' => $currency,
			'blind_count' => empty( $data['blind_count'] ) ? 0 : 1,
			'status' => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			$wpdb->update( self::table(), $row, array( 'register_id' => $register_id ) );
			Audit::record( 'pos_register_updated', 'pos_register', $register_id, array_diff_key( $row, array( 'updated_at' => 1 ) ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			$row['created_by'] = get_current_user_id();
			if ( false === $wpdb->insert( self::table(), $row ) ) { return new \WP_Error( 'wpcap_pos_register_save', __( 'Die Kasse konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
			$register_id = (int) $wpdb->insert_id;
			Audit::record( 'pos_register_created', 'pos_register', $register_id, array( 'name' => $name ) );
		}
		return self::get( $register_id );
	}

	/** Inserts a register without capability check (system use: default register, migration). */
	private static function insert_system( $name, $currency = 'EUR', $location = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( self::table(), array( 'name' => $name, 'location' => $location, 'event_id' => 0, 'currency' => $currency, 'blind_count' => 0, 'status' => 'active', 'created_by' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		$register_id = (int) $wpdb->insert_id;
		if ( $register_id ) { Audit::record( 'pos_register_created', 'pos_register', $register_id, array( 'name' => $name, 'system' => true ) ); }
		return $register_id;
	}

	/** Default register ("Hauptkasse"); created on first use. */
	public static function default_id( $currency = 'EUR' ) {
		$id = absint( get_option( self::DEFAULT_OPTION, 0 ) );
		if ( $id && self::get( $id ) ) { return $id; }
		$first = self::all();
		if ( $first ) {
			$id = (int) $first[0]->register_id;
		} else {
			$id = self::insert_system( __( 'Hauptkasse', 'wp-pass-captain' ), $currency );
		}
		if ( $id ) { update_option( self::DEFAULT_OPTION, $id ); }
		return $id;
	}

	/** Pending handover of a register (the next shift must be opened by accepting it). */
	public static function pending_handover( $register_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_handovers WHERE register_id=%d AND status='pending' ORDER BY handover_id DESC LIMIT 1", absint( $register_id ) ) );
	}

	/** Active registers the current user can open a shift on now (no open shift, no pending handover). */
	public static function available() {
		$out = array();
		foreach ( self::all() as $r ) {
			if ( Shifts::open_for_register( $r->register_id ) || self::pending_handover( $r->register_id ) ) { continue; }
			if ( (int) $r->event_id && ! \PassCaptain\Reports\Scope::can_event( (int) $r->event_id, Service::CAP ) ) { continue; }
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Migration of cash sessions from before registers existed (register_id 0): all of them are
	 * mapped to the default register. Closed sessions get a Z report each (source "migration",
	 * numbered in closing order, totals and counts taken from the stored session values; the grand
	 * total continues). Because a register can only have one open shift, the second and further
	 * open legacy sessions get an own register each ("Kasse n (übernommen)"). No session data is
	 * changed apart from register_id/open_register. Idempotent.
	 *
	 * @return bool False when the tables are not installed yet.
	 */
	public static function migrate_legacy_sessions() {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $wpdb->get_row( "SHOW COLUMNS FROM `{$p}wpcap_boxoffice_sessions` LIKE 'register_id'" ) || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'wpcap_boxoffice_zreports' ) ) ) { return false; }
		$legacy = $wpdb->get_results( "SELECT * FROM {$p}wpcap_boxoffice_sessions WHERE register_id=0 ORDER BY (closed_at IS NULL), closed_at, session_id" );
		if ( ! $legacy ) { return true; }
		$default = self::default_id( (string) $legacy[0]->currency ?: 'EUR' );
		$open_seen = (bool) Shifts::open_for_register( $default );
		$extra = 1;
		foreach ( $legacy as $s ) {
			if ( in_array( $s->status, array( 'open', 'counting' ), true ) ) {
				$register_id = $default;
				if ( $open_seen ) {
					/* translators: %d: running number */
					$register_id = self::insert_system( sprintf( __( 'Kasse %d (übernommen)', 'wp-pass-captain' ), ++$extra ), (string) $s->currency ?: 'EUR' );
				}
				$open_seen = $open_seen || $register_id === $default;
				// One transaction per open shift: register assignment and opener row together, or neither.
				$wpdb->query( 'START TRANSACTION' );
				$locked = $wpdb->get_row( $wpdb->prepare( "SELECT register_id FROM {$p}wpcap_boxoffice_sessions WHERE session_id=%d FOR UPDATE", $s->session_id ) );
				if ( ! $locked || 0 !== (int) $locked->register_id ) { $wpdb->query( 'COMMIT' ); continue; }
				$moved = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_boxoffice_sessions SET register_id=%d,open_register=%d WHERE session_id=%d AND register_id=0", $register_id, $register_id, $s->session_id ) );
				$opener = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_boxoffice_shift_cashiers (shift_id,user_id,role,added_by,added_at) VALUES (%d,%d,'opener',%d,%s)", $s->session_id, $s->user_id, $s->user_id, $s->opened_at ) );
				if ( 1 !== (int) $moved || false === $opener || false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					return false; // Retried on the next run (version option not updated).
				}
				Audit::record( 'pos_shift_migrated', 'pos_session', (int) $s->session_id, array( 'register_id' => $register_id, 'status' => $s->status ) );
				continue;
			}
			$wpdb->query( 'START TRANSACTION' );
			$wpdb->get_row( $wpdb->prepare( 'SELECT register_id FROM ' . self::table() . ' WHERE register_id=%d FOR UPDATE', $default ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sessions WHERE session_id=%d FOR UPDATE", $s->session_id ) );
			if ( ! $row || 0 !== (int) $row->register_id ) { $wpdb->query( 'COMMIT' ); continue; }
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_boxoffice_sessions SET register_id=%d WHERE session_id=%d", $default, $s->session_id ) );
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_boxoffice_shift_cashiers (shift_id,user_id,role,added_by,added_at) VALUES (%d,%d,'opener',%d,%s)", $s->session_id, $s->user_id, $s->user_id, $s->opened_at ) );
			$z = ZReport::insert_legacy( $row, $default );
			if ( is_wp_error( $z ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
			$wpdb->query( 'COMMIT' );
			Audit::record( 'pos_shift_migrated', 'pos_session', (int) $s->session_id, array( 'register_id' => $default, 'z_id' => $z ) );
		}
		return true;
	}
}
