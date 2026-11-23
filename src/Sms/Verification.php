<?php
/**
 * Phone number verification for the SMS opt-in (audit PRIV-16): a 6-digit code is sent by SMS before
 * the checkout, the buyer enters it, and only a verified number becomes a consent that receives
 * messages.
 *
 * - Request (POST /sms/verify/request): number normalised and checked against the allowed mobile
 *   ranges, same-origin request, rate limits per number (hour/day), per client address (hour) and per
 *   site (day), at most MAX_SENDS codes per verification with a RESEND_SECONDS cooldown. Returns an
 *   unguessable verification token (stored only as sha256).
 * - Confirm (POST /sms/verify/confirm): token + code; MAX_ATTEMPTS wrong codes end the verification,
 *   codes expire after TTL seconds. The code is stored only as keyed hash (RateLimit::hash()).
 * - Checkout: the token travels as `sms_verification`; Modules\Sms::store_consent() consumes it once
 *   (same number, verified, not older than USE_WINDOW) and stores the consent as verified with the
 *   verification time. Nothing but the code is ever sent to an unverified number.
 * - Rows are deleted one day after creation (prune(), cron): the number of an abandoned verification
 *   is not kept.
 */
namespace PassCaptain\Sms;

use PassCaptain\RateLimit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Verification {
	const TTL = 600;
	const MAX_ATTEMPTS = 5;
	const MAX_SENDS = 3;
	const RESEND_SECONDS = 60;
	/** A verified number must be used for an order within this time. */
	const USE_WINDOW = 3600;

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_sms_verifications';
	}

	/** Whether the opt-in needs a confirmed number (setting sms.verify_phone, default on). */
	public static function required() {
		$settings = (array) Settings::get( 'sms' );
		return ! array_key_exists( 'verify_phone', $settings ) || ! empty( $settings['verify_phone'] );
	}

	/**
	 * Abuse limits (filter wpcap_sms_otp_limits).
	 *
	 * @return array{number_hour:int,number_day:int,ip_hour:int,site_day:int,confirm_ip_hour:int}
	 */
	public static function limits() {
		$defaults = array( 'number_hour' => 3, 'number_day' => 5, 'ip_hour' => 5, 'site_day' => 500, 'confirm_ip_hour' => 30 );
		$limits = apply_filters( 'wpcap_sms_otp_limits', $defaults );
		$out = array();
		foreach ( $defaults as $key => $value ) {
			$out[ $key ] = max( 1, (int) ( is_array( $limits ) && isset( $limits[ $key ] ) ? $limits[ $key ] : $value ) );
		}
		return $out;
	}

	/** Keyed hash of a code for one verification (the plain code is never stored). */
	private static function code_hash( $token_hash, $code ) {
		return RateLimit::hash( 'sms_otp|' . $token_hash . '|' . $code );
	}

	/** Anonymised client key for the per-address limits. */
	private static function client_key() {
		return RateLimit::client();
	}

	private static function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Starts a verification (or sends a new code for an existing one) and sends the code by SMS.
	 *
	 * @param string $raw_phone Number as typed.
	 * @param string $token     Token of an earlier request for the same number (resend), or ''.
	 * @return array|\WP_Error token, expires_in, resend_in, phone_masked
	 */
	public static function request( $raw_phone, $token = '' ) {
		global $wpdb;
		$provider = Service::provider();
		if ( ! $provider ) { return self::error( 'wpcap_sms_unavailable', __( 'SMS-Benachrichtigungen sind derzeit nicht verfügbar.', 'pass-captain' ), 400 ); }
		$phone = Service::normalize_phone( $raw_phone );
		if ( '' === $phone ) { return self::error( 'wpcap_sms_phone_invalid', __( 'Bitte gib eine gültige Mobilnummer an (z. B. +49 151 23456789) oder deaktiviere die SMS-Benachrichtigung.', 'pass-captain' ), 400 ); }
		if ( ! Service::number_allowed( $phone ) ) { return self::error( 'wpcap_sms_phone_not_allowed', __( 'SMS-Benachrichtigungen sind nur für Mobilnummern aus den unterstützten Ländern möglich. Bitte deaktiviere die SMS-Benachrichtigung oder nutze eine andere Nummer.', 'pass-captain' ), 400 ); }
		$now = time();
		$row = null;
		$token = \PassCaptain\Security\Input::hex( $token );
		if ( 64 === strlen( $token ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s AND phone=%s AND verified_at IS NULL', self::table(), hash( 'sha256', $token ), $phone ) );
			if ( $row ) {
				$wait = self::RESEND_SECONDS - ( $now - (int) strtotime( $row->last_sent_at . ' UTC' ) );
				if ( $wait > 0 ) { return self::error( 'wpcap_sms_otp_wait', sprintf( /* translators: %d: seconds */ __( 'Bitte warte %d Sekunden, bevor du einen neuen Code anforderst.', 'pass-captain' ), $wait ), 429 ); }
				if ( (int) $row->sends >= self::MAX_SENDS || (int) $row->attempts >= self::MAX_ATTEMPTS ) { $row = null; } // Start over (counts against the limits below).
			}
		}
		$limits = self::limits();
		$number_key = RateLimit::hash( $phone );
		// Cost protection: every code is a paid SMS to a number the requester may not own.
		if ( ! RateLimit::allow( 'sms_otp_ip|' . self::client_key(), $limits['ip_hour'], HOUR_IN_SECONDS )
			|| ! RateLimit::allow( 'sms_otp_number|' . $number_key, $limits['number_hour'], HOUR_IN_SECONDS )
			|| ! RateLimit::allow( 'sms_otp_number_day|' . $number_key, $limits['number_day'], DAY_IN_SECONDS )
			|| ! RateLimit::allow( 'sms_otp_site', $limits['site_day'], DAY_IN_SECONDS ) ) {
			return self::error( 'wpcap_sms_rate_limited', __( 'Für diese Mobilnummer wurden zu viele Codes angefordert. Bitte versuche es später erneut oder bestelle ohne SMS-Benachrichtigung.', 'pass-captain' ), 429 );
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$created = gmdate( 'Y-m-d H:i:s', $now );
		$expires = gmdate( 'Y-m-d H:i:s', $now + self::TTL );
		if ( $row ) {
			$token_hash = (string) $row->token_hash;
			$wpdb->update( self::table(), array( 'code_hash' => self::code_hash( $token_hash, $code ), 'attempts' => 0, 'sends' => (int) $row->sends + 1, 'expires_at' => $expires, 'last_sent_at' => $created ), array( 'verification_id' => (int) $row->verification_id ) );
		} else {
			$token = bin2hex( random_bytes( 32 ) );
			$token_hash = hash( 'sha256', $token );
			$inserted = $wpdb->insert( self::table(), array( 'token_hash' => $token_hash, 'phone' => $phone, 'code_hash' => self::code_hash( $token_hash, $code ), 'attempts' => 0, 'sends' => 1, 'expires_at' => $expires, 'last_sent_at' => $created, 'used_order_id' => 0, 'created_at' => $created ) );
			if ( ! $inserted ) { return self::error( 'wpcap_sms_otp_failed', __( 'Der Code konnte nicht gesendet werden. Bitte versuche es erneut.', 'pass-captain' ), 500 ); }
		}
		$site = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		/* translators: 1: code, 2: site name */
		$body = sprintf( __( '%1$s ist dein Bestätigungscode für SMS-Benachrichtigungen von %2$s. Gültig 10 Minuten.', 'pass-captain' ), $code, '' !== $site ? $site : self::host( home_url( '/' ) ) );
		$body = (string) apply_filters( 'wpcap_sms_otp_body', $body, $code );
		if ( false === strpos( $body, $code ) ) { $body = $code . ' ' . $body; }
		$sent = $provider->send( $phone, $body, 'wpcap-sms-otp-' . substr( $token_hash, 0, 16 ) );
		if ( is_wp_error( $sent ) ) {
			return self::error( 'wpcap_sms_otp_failed', __( 'Der Code konnte nicht gesendet werden. Bitte prüfe die Nummer oder bestelle ohne SMS-Benachrichtigung.', 'pass-captain' ), 502 );
		}
		return array( 'token' => $token, 'expires_in' => self::TTL, 'resend_in' => self::RESEND_SECONDS, 'phone_masked' => self::mask( $phone ) );
	}

	/**
	 * Checks a code.
	 *
	 * @return array|\WP_Error verified => true
	 */
	public static function confirm( $token, $code ) {
		global $wpdb;
		$token = \PassCaptain\Security\Input::hex( $token );
		$code = preg_replace( '/\D/', '', is_scalar( $code ) ? (string) $code : '' );
		$invalid = self::error( 'wpcap_sms_otp_invalid', __( 'Der Code ist falsch oder abgelaufen. Bitte fordere einen neuen Code an.', 'pass-captain' ), 400 );
		if ( ! RateLimit::allow( 'sms_otp_confirm|' . self::client_key(), self::limits()['confirm_ip_hour'], HOUR_IN_SECONDS ) ) {
			return self::error( 'wpcap_sms_rate_limited', __( 'Zu viele Versuche. Bitte versuche es später erneut.', 'pass-captain' ), 429 );
		}
		if ( 64 !== strlen( $token ) || 6 !== strlen( $code ) ) { return $invalid; }
		$token_hash = hash( 'sha256', $token );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', self::table(), $token_hash ) );
		if ( ! $row ) { return $invalid; }
		if ( null !== $row->verified_at ) { return array( 'verified' => true ); }
		$now = current_time( 'mysql', true );
		if ( (int) $row->attempts >= self::MAX_ATTEMPTS || (string) $row->expires_at <= $now ) { return $invalid; }
		// Count the attempt first (atomic), so parallel guesses cannot exceed MAX_ATTEMPTS.
		$counted = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts=attempts+1 WHERE verification_id=%d AND attempts<%d AND verified_at IS NULL', self::table(), (int) $row->verification_id, self::MAX_ATTEMPTS ) );
		if ( 1 !== $counted || ! hash_equals( (string) $row->code_hash, self::code_hash( $token_hash, $code ) ) ) { return $invalid; }
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET verified_at=%s,code_hash=%s WHERE verification_id=%d AND verified_at IS NULL', self::table(), $now, '', (int) $row->verification_id ) );
		return array( 'verified' => true, 'phone_masked' => self::mask( (string) $row->phone ) );
	}

	/**
	 * Consumes a verified token for an order (inside the checkout transaction: a rolled back order
	 * frees it again). The same order may consume it again (idempotent retry).
	 *
	 * @return string|null Verification time (UTC) or null when the token does not prove this number.
	 */
	public static function consume( $token, $phone, $order_id ) {
		global $wpdb;
		$token = \PassCaptain\Security\Input::hex( $token );
		if ( 64 !== strlen( $token ) || '' === (string) $phone ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s AND phone=%s AND verified_at IS NOT NULL AND verified_at>%s', self::table(), hash( 'sha256', $token ), (string) $phone, gmdate( 'Y-m-d H:i:s', time() - self::USE_WINDOW ) ) );
		if ( ! $row ) { return null; }
		if ( (int) $row->used_order_id === absint( $order_id ) ) { return (string) $row->verified_at; }
		$used = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_order_id=%d WHERE verification_id=%d AND used_order_id=0', self::table(), absint( $order_id ), (int) $row->verification_id ) );
		return 1 === $used ? (string) $row->verified_at : null;
	}

	/** Deletes verifications older than a day (data minimisation; cron). */
	public static function prune() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at<%s', self::table(), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	/** "+49 151 ••••• 89" style mask for the confirmation text. */
	public static function mask( $e164 ) {
		$e164 = (string) $e164;
		return strlen( $e164 ) > 6 ? substr( $e164, 0, 6 ) . str_repeat( '•', max( 0, strlen( $e164 ) - 8 ) ) . substr( $e164, -2 ) : $e164;
	}

	/** Same-origin check for the browser endpoints (Origin, else Referer header; absent = allowed). */
	public static function same_origin( $origin, $referer ) {
		$source = '' !== (string) $origin ? (string) $origin : (string) $referer;
		if ( '' === $source ) { return true; } // Non-browser clients: the rate limits still apply.
		$own = self::host( home_url( '/' ) );
		return '' !== $own && self::host( $source ) === $own;
	}

	/** Lower-case host of a URL ('' when none). */
	private static function host( $url ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url ) : parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
	}

	/* ------------------------------------------------------------------ REST */

	public static function rest_request( \WP_REST_Request $request ) {
		if ( ! self::same_origin( (string) $request->get_header( 'origin' ), (string) $request->get_header( 'referer' ) ) ) {
			return self::error( 'wpcap_sms_origin', __( 'Ungültige Anfrage.', 'pass-captain' ), 403 );
		}
		if ( ! self::required() || ! Service::enabled() ) { return self::error( 'wpcap_sms_unavailable', __( 'SMS-Benachrichtigungen sind derzeit nicht verfügbar.', 'pass-captain' ), 400 ); }
		$phone = $request->get_param( 'phone' );
		$token = $request->get_param( 'token' );
		$result = self::request( is_scalar( $phone ) ? (string) $phone : '', is_scalar( $token ) ? (string) $token : '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_confirm( \WP_REST_Request $request ) {
		if ( ! self::same_origin( (string) $request->get_header( 'origin' ), (string) $request->get_header( 'referer' ) ) ) {
			return self::error( 'wpcap_sms_origin', __( 'Ungültige Anfrage.', 'pass-captain' ), 403 );
		}
		$token = $request->get_param( 'token' );
		$code = $request->get_param( 'code' );
		$result = self::confirm( is_scalar( $token ) ? (string) $token : '', is_scalar( $code ) ? (string) $code : '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
