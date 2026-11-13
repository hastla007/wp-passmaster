<?php
/**
 * PII minimisation per role (A119): which personal data a staff member sees in admin lists,
 * details, exports and REST responses. Matrix in docs/SECURITY.md ("PII je Rolle").
 *
 * Profiles are derived from capabilities (custom roles work the same way):
 *  - full:    manage_options, wpcap_manage_orders or wpcap_manage_events – everything (unchanged);
 *  - finance: wpcap_view_finance – buyer name/e-mail/phone and billing data, no attendee data;
 *  - door:    wpcap_checkin – attendee name only when the setting scanner_show_attendee is on;
 *  - reports: wpcap_view_reports – aggregated figures only, no personal data.
 * A user with several profiles sees the union. Filters: `wpcap_pii_matrix`, `wpcap_pii_can_view`,
 * `wpcap_pii_mask`.
 *
 * @package PassCaptain
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pii {
	const FIELDS = array( 'buyer_name', 'buyer_email', 'buyer_phone', 'billing', 'attendee_name', 'attendee_email', 'attendee_answers' );
	const PROFILES = array( 'full', 'finance', 'door', 'reports' );
	/** Matrix value meaning "visible when the setting scanner_show_attendee is on". */
	const IF_SCANNER_SETTING = 'scanner_show_attendee';

	/**
	 * Profile => field => true|false|'scanner_show_attendee'.
	 *
	 * @return array<string, array<string, bool|string>>
	 */
	public static function matrix() {
		$all = array_fill_keys( self::FIELDS, true );
		$none = array_fill_keys( self::FIELDS, false );
		$matrix = array(
			'full'    => $all,
			'finance' => array_merge( $none, array( 'buyer_name' => true, 'buyer_email' => true, 'buyer_phone' => true, 'billing' => true ) ),
			'door'    => array_merge( $none, array( 'attendee_name' => self::IF_SCANNER_SETTING ) ),
			'reports' => $none,
		);
		/**
		 * PII matrix (profile => field => true, false or 'scanner_show_attendee').
		 *
		 * @param array $matrix Default matrix.
		 */
		$filtered = apply_filters( 'wpcap_pii_matrix', $matrix );
		return is_array( $filtered ) ? $filtered : $matrix;
	}

	/**
	 * Profiles of a user (empty: no plugin role at all).
	 *
	 * @return string[]
	 */
	public static function profiles( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		if ( ! $user_id ) { return array(); }
		foreach ( array( 'manage_options', 'wpcap_manage_orders', 'wpcap_manage_events' ) as $cap ) {
			if ( Access::user_has( $user_id, $cap ) ) { return array( 'full' ); }
		}
		$profiles = array();
		if ( Access::user_has( $user_id, 'wpcap_view_finance' ) ) { $profiles[] = 'finance'; }
		if ( Access::user_has( $user_id, 'wpcap_checkin' ) ) { $profiles[] = 'door'; }
		if ( Access::user_has( $user_id, 'wpcap_view_reports' ) ) { $profiles[] = 'reports'; }
		return $profiles;
	}

	/** Whether the user may see a field in clear text. */
	public static function can_view( $field, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		$profiles = self::profiles( $user_id );
		$matrix = self::matrix();
		$allowed = false;
		foreach ( $profiles as $profile ) {
			$rule = $matrix[ $profile ][ $field ] ?? false;
			if ( self::IF_SCANNER_SETTING === $rule ) { $rule = (bool) Settings::get( 'scanner_show_attendee' ); }
			if ( true === $rule ) { $allowed = true; break; }
		}
		/**
		 * Whether a user may see a personal data field in clear text.
		 *
		 * @param bool     $allowed  Result of the matrix.
		 * @param string   $field    buyer_name, buyer_email, buyer_phone, billing, attendee_name, attendee_email or attendee_answers.
		 * @param int      $user_id  User.
		 * @param string[] $profiles Profiles of the user.
		 */
		return (bool) apply_filters( 'wpcap_pii_can_view', $allowed, (string) $field, $user_id, $profiles );
	}

	/** Value for display: clear text when allowed, otherwise masked (see mask()). */
	public static function show( $field, $value, $user_id = 0 ) {
		$value = (string) $value;
		if ( '' === $value || self::can_view( $field, $user_id ) ) { return $value; }
		return self::mask( $field, $value );
	}

	/** Masked value of a field: e-mail "a***@e***.com", names "A. M.", everything else empty. */
	public static function mask( $field, $value ) {
		$value = (string) $value;
		if ( in_array( $field, array( 'buyer_email', 'attendee_email' ), true ) ) {
			$masked = self::mask_email( $value );
		} elseif ( in_array( $field, array( 'buyer_name', 'attendee_name' ), true ) ) {
			$masked = self::mask_name( $value );
		} else {
			$masked = '';
		}
		/**
		 * Masked form of a personal data field.
		 *
		 * @param string $masked Masked value.
		 * @param string $field  Field key.
		 * @param string $value  Clear text.
		 */
		return (string) apply_filters( 'wpcap_pii_mask', $masked, (string) $field, $value );
	}

	/** "anna.muster@example.com" → "a***@e***.com" (top-level domain kept). */
	public static function mask_email( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email ) { return ''; }
		$at = strrpos( $email, '@' );
		if ( false === $at ) { return mb_substr( $email, 0, 1 ) . '***'; }
		$local = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$dot = strrpos( $domain, '.' );
		$tld = false === $dot ? '' : substr( $domain, $dot );
		return mb_substr( $local, 0, 1 ) . '***@' . ( '' !== $domain ? mb_substr( $domain, 0, 1 ) : '' ) . '***' . $tld;
	}

	/** "Anna Maria Muster" → "A. M. M." (initials only). */
	public static function mask_name( $name ) {
		$parts = preg_split( '/\s+/u', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
		return implode( ' ', array_map( static function ( $part ) { return mb_strtoupper( mb_substr( $part, 0, 1 ) ) . '.'; }, (array) $parts ) );
	}
}
