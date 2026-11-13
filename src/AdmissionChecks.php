<?php
/**
 * ID and age check per ticket type (schema 3.8.4, columns wpcap_ticket_types.id_check, min_age,
 * staff_note): scanning staff see "Ausweis prüfen", the minimum age and a free note with every scan
 * result (web scanner, mobile API, offline package/app); buyers see the requirement on the event card.
 * The check itself is done by the staff – the scan is not refused.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdmissionChecks {
	const MAX_AGE = 99;
	const NOTE_LENGTH = 190;

	/** Whether the columns exist (a deferred upgrade may still be pending). */
	public static function ready() {
		$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
		return false !== $version && '' !== $version && version_compare( $version, '3.8.4', '>=' );
	}

	/**
	 * Validates the ticket type form values.
	 *
	 * @return array|\WP_Error ['id_check' => 0|1, 'min_age' => int, 'staff_note' => string]
	 */
	public static function parse( array $in ) {
		$age = trim( (string) ( $in['min_age'] ?? '' ) );
		if ( '' !== $age && ( ! preg_match( '/^\d{1,2}$/', $age ) || (int) $age > self::MAX_AGE ) ) {
			return new \WP_Error( 'wpcap_type_min_age', __( 'Das Mindestalter muss zwischen 0 und 99 Jahren liegen (leer oder 0 = keines).', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		return array(
			'id_check' => empty( $in['id_check'] ) ? 0 : 1,
			'min_age' => (int) $age,
			'staff_note' => mb_substr( sanitize_text_field( (string) ( $in['staff_note'] ?? '' ) ), 0, self::NOTE_LENGTH ),
		);
	}

	/**
	 * Settings of a ticket type (row with the columns, or id).
	 *
	 * @param object|int $type
	 * @return array{id_check: bool, min_age: int, staff_note: string}
	 */
	public static function for_type( $type ) {
		$none = array( 'id_check' => false, 'min_age' => 0, 'staff_note' => '' );
		if ( ! self::ready() ) { return $none; }
		if ( ! is_object( $type ) || ! property_exists( $type, 'id_check' ) ) {
			global $wpdb;
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT id_check,min_age,staff_note FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", absint( is_object( $type ) ? ( $type->ticket_type_id ?? 0 ) : $type ) ) );
			if ( ! $type ) { return $none; }
		}
		return array( 'id_check' => (bool) (int) $type->id_check, 'min_age' => (int) ( $type->min_age ?? 0 ), 'staff_note' => (string) ( $type->staff_note ?? '' ) );
	}

	/** Hint for scanning staff ("Ausweis prüfen · Mindestalter 18 · note"), '' when nothing is set. */
	public static function staff_hint( array $checks ) {
		$parts = array();
		if ( $checks['id_check'] ) { $parts[] = __( 'Ausweis prüfen', 'wp-pass-captain' ); }
		/* translators: %d: minimum age in years */
		if ( $checks['min_age'] > 0 ) { $parts[] = sprintf( __( 'Mindestalter %d', 'wp-pass-captain' ), $checks['min_age'] ); }
		if ( '' !== $checks['staff_note'] ) { $parts[] = $checks['staff_note']; }
		return implode( ' · ', $parts );
	}

	/** Notice for buyers ("ab 18 Jahren, Ausweis erforderlich"), '' when nothing is set. */
	public static function buyer_notice( array $checks ) {
		$parts = array();
		/* translators: %d: minimum age in years */
		if ( $checks['min_age'] > 0 ) { $parts[] = sprintf( __( 'ab %d Jahren', 'wp-pass-captain' ), $checks['min_age'] ); }
		if ( $checks['id_check'] ) { $parts[] = __( 'Ausweis erforderlich', 'wp-pass-captain' ); }
		return implode( ', ', $parts );
	}

	/** Fields added to a scan result for staff (empty array when nothing is set). */
	public static function scan_fields( $ticket_type_id ) {
		$checks = self::for_type( (int) $ticket_type_id );
		$hint = self::staff_hint( $checks );
		if ( '' === $hint ) { return array(); }
		return array( 'id_check' => $checks['id_check'], 'min_age' => $checks['min_age'], 'staff_hint' => $hint );
	}
}
