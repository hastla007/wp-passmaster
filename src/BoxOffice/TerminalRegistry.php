<?php
/** Admin-managed POS locations and registers. */
namespace PassCaptain\\BoxOffice;

use PassCaptain\\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TerminalRegistry {
	const SETTING = 'boxoffice_terminals';

	/** Parse administrator-managed rows: location_id|register_id|display_name. */
	public static function parse( $raw ) {
		$entries = array();
		$errors = array();
		foreach ( preg_split( '/\\r\\n|\\r|\\n/', (string) $raw ) as $line_number => $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) { continue; }
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $parts[1] ) || '' === $parts[2] ) {
				$errors[] = $line_number + 1;
				continue;
			}
			$location_id = absint( $parts[0] );
			$register_id = $parts[1];
			$key = self::key( $location_id, $register_id );
			if ( isset( $entries[ $key ] ) ) {
				$errors[] = $line_number + 1;
				continue;
			}
			$entries[ $key ] = array(
				'location_id' => $location_id,
				'register_id' => $register_id,
				'label' => mb_substr( sanitize_text_field( $parts[2] ), 0, 80 ),
			);
		}
		return array( 'entries' => $entries, 'errors' => $errors );
	}

	/** Stable key used by the POS selector. */
	public static function key( $location_id, $register_id ) {
		return absint( $location_id ) . '|' . (string) $register_id;
	}

	public static function raw() {
		return (string) Settings::get( self::SETTING );
	}

	public static function entries() {
		$parsed = self::parse( self::raw() );
		return $parsed['entries'];
	}

	public static function is_configured() {
		return '' !== trim( self::raw() );
	}

	/** Return a directory entry only when the submitted key exactly matches a configured terminal. */
	public static function find( $key ) {
		$entries = self::entries();
		return $entries[ (string) $key ] ?? null;
	}

	public static function allows( $location_id, $register_id ) {
		if ( ! self::is_configured() ) { return true; } // Preserve existing installations until an admin enables the directory.
		return isset( self::entries()[ self::key( $location_id, $register_id ) ] );
	}

	/** Check input before saving so malformed or duplicate register IDs are never silently ignored. */
	public static function validate( $raw ) {
		$parsed = self::parse( $raw );
		if ( $parsed['errors'] ) {
			return new \\WP_Error( 'wpcap_pos_terminals_invalid', sprintf( __( 'Ungültige Kassenliste in Zeile(n): %s. Erwartet wird Standort-ID|Kassen-ID|Anzeigename.', 'wp-pass-captain' ), implode( ', ', $parsed['errors'] ) ) );
		}
		return $parsed;
	}
}
