<?php
/**
 * Shared-account safeguards for scanner and box-office staff (W9).
 *
 * Uses the WordPress session tokens (WP_Session_Tokens) of the user: on every login of a staff
 * account (wpcap_checkin without manage_options/wpcap_manage_events) concurrent sessions from other
 * devices or IP addresses are detected and audited. With the setting `staff_single_session` all other
 * sessions of the account are ended, so one account cannot be used on several devices at once.
 * Organisational rule (docs/SECURITY.md): one personal account per person, no shared logins.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Sessions {
	public static function register() {
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'logged_in_cookie_set' ), 10, 6 );
	}

	/** set_logged_in_cookie action (no return value). */
	public static function logged_in_cookie_set( $cookie = '', $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ) {
		self::on_login( $cookie, $expire, $expiration, $user_id, $scheme, $token );
	}

	/** Whether the safeguards apply to the user (scanner/box-office/partner staff, not administrators). */
	public static function applies_to( $user_id ) {
		$user_id = absint( $user_id );
		return $user_id && user_can( $user_id, 'wpcap_checkin' ) && ! user_can( $user_id, 'manage_options' ) && ! user_can( $user_id, 'wpcap_manage_events' );
	}

	/** Short, non-identifying fingerprint of a session (IP network /24 or /48 + user agent hash). */
	public static function fingerprint( array $session ) {
		$ip = (string) ( $session['ip'] ?? '' );
		if ( false !== strpos( $ip, ':' ) ) {
			$net = implode( ':', array_slice( explode( ':', $ip ), 0, 3 ) );
		} else {
			$net = implode( '.', array_slice( explode( '.', $ip ), 0, 3 ) );
		}
		return substr( hash( 'sha256', $net . '|' . (string) ( $session['ua'] ?? '' ) ), 0, 12 );
	}

	/**
	 * Other active sessions of the user that come from a different device/network than $current_token.
	 *
	 * @return array<int,array{fingerprint:string,login:int,expiration:int}>
	 */
	public static function concurrent( $user_id, $current_token = '' ) {
		if ( ! class_exists( '\\WP_Session_Tokens' ) ) { return array(); }
		$manager = \WP_Session_Tokens::get_instance( absint( $user_id ) );
		$sessions = (array) $manager->get_all();
		$current = null;
		if ( '' !== (string) $current_token ) {
			$current = $manager->get( (string) $current_token );
		}
		$current_fp = is_array( $current ) ? self::fingerprint( $current ) : '';
		$out = array();
		foreach ( $sessions as $session ) {
			if ( ! is_array( $session ) || ( is_array( $current ) && $session == $current ) ) { continue; } // phpcs:ignore Universal.Operators.StrictComparisons -- compares session arrays.
			$fp = self::fingerprint( $session );
			if ( '' !== $current_fp && $fp === $current_fp ) { continue; }
			$out[] = array( 'fingerprint' => $fp, 'login' => (int) ( $session['login'] ?? 0 ), 'expiration' => (int) ( $session['expiration'] ?? 0 ) );
		}
		return $out;
	}

	/**
	 * set_logged_in_cookie: new session created for $user_id with $token.
	 *
	 * @return array{concurrent:int,ended:bool}
	 */
	public static function on_login( $cookie = '', $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ) {
		$result = array( 'concurrent' => 0, 'ended' => false );
		if ( ! self::applies_to( $user_id ) || '' === (string) $token ) { return $result; }
		$others = self::concurrent( $user_id, $token );
		$result['concurrent'] = count( $others );
		if ( ! $others ) { return $result; }
		$single = (bool) Policy::get( 'staff_single_session' );
		if ( $single && class_exists( '\\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( absint( $user_id ) )->destroy_others( (string) $token );
			$result['ended'] = true;
		}
		Audit::record( 'concurrent_session_detected', 'user', $user_id, array( 'other_sessions' => count( $others ), 'devices' => array_values( array_unique( array_column( $others, 'fingerprint' ) ) ), 'ended_others' => $result['ended'] ) );
		return $result;
	}
}
