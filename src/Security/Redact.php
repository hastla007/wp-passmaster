<?php
/**
 * Central redaction for anything that leaves the request in a durable or external form: audit log
 * details, error_log lines, stored provider/delivery errors and REST error messages.
 *
 * Removes: configured provider secrets (exact values), API keys / tokens by pattern (Stripe,
 * Mollie, Twilio, PayPal bearer, JWT, partner and developer keys, webhook secrets), PEM private
 * keys, 64-hex ticket/access codes, e-mail addresses (masked) and values under sensitive keys.
 */
namespace PassCaptain\Security;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Redact {
	const MASK = '[redacted]';

	/** Array keys whose values are always removed. */
	const SENSITIVE_KEYS = '/(secret|passw|token|api_?key|authorization|private_?key|cookie|signature|credential|auth_header|iban|card)/i';

	/** Array keys holding e-mail addresses / phone numbers (masked, not removed). */
	const PII_KEYS = '/(e_?mail|phone|recipient)$/i';

	/** Per-request cache of configured secret values. */
	private static $known = null;

	public static function reset() {
		self::$known = null;
	}

	/**
	 * Registers a secret that is only known at runtime (e.g. fiscal provider credentials decrypted for
	 * one adapter call), so later log/audit/error output of this request redacts its exact value.
	 */
	public static function remember( $secret ) {
		$secret = trim( (string) $secret );
		if ( strlen( $secret ) < 6 ) { return; }
		self::known_secrets();
		if ( ! in_array( $secret, self::$known, true ) ) {
			self::$known[] = $secret;
			usort( self::$known, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		}
	}

	/** Configured secrets (decrypted settings + constants), longest first, at least 6 characters. */
	private static function known_secrets() {
		if ( null !== self::$known ) { return self::$known; }
		self::$known = array();
		if ( class_exists( Settings::class ) ) {
			foreach ( Settings::secret_keys() as $key ) {
				$value = trim( (string) Settings::secret( $key ) );
				if ( strlen( $value ) >= 6 ) { self::$known[] = $value; }
			}
			foreach ( array( 'paypal_client_id', 'twilio_account_sid' ) as $key ) {
				$value = trim( (string) Settings::secret( $key ) );
				if ( strlen( $value ) >= 12 ) { self::$known[] = $value; }
			}
		}
		usort( self::$known, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		return self::$known;
	}

	/** Masks an e-mail address: "jane.doe@example.com" -> "j***@example.com". */
	public static function email( $email ) {
		$email = (string) $email;
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) { return self::MASK; }
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** Redacts a free text (error message, provider response, log line). */
	public static function text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) { return $text; }
		foreach ( self::known_secrets() as $secret ) {
			if ( false !== strpos( $text, $secret ) ) { $text = str_replace( $secret, self::MASK, $text ); }
		}
		$patterns = array(
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => self::MASK,
			'/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{8,}\b/' => '$1_$2_' . self::MASK,
			'/\bwhsec_[A-Za-z0-9]{8,}\b/' => 'whsec_' . self::MASK,
			'/\b(live|test)_[A-Za-z0-9]{20,}\b/' => '$1_' . self::MASK,
			'/\bwpcap_pk_[A-Za-z0-9_\-.]{8,}/' => 'wpcap_pk_' . self::MASK,
			'/\b[a-f0-9]{12}\.[a-f0-9]{64}\b/i' => self::MASK,
			'/\bAC[a-f0-9]{32}\b/i' => 'AC' . self::MASK,
			'/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\b/' => self::MASK,
			'/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1 ' . self::MASK,
			'/([?&](?:access_token|token|key|secret|password|api_key|code)=)[^&\s"\']+/i' => '$1' . self::MASK,
			'/("(?:access_token|token|secret|password|api_key|client_secret|auth_token|refresh_token|private_key)"\s*:\s*")[^"]*"/i' => '$1' . self::MASK . '"',
			// Ticket, access and offer codes are 64 hex characters (hashes look the same and are masked too).
			'/\b([a-f0-9]{6})[a-f0-9]{58}\b/i' => '$1…',
		);
		$text = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		return (string) preg_replace_callback(
			'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
			static function ( $m ) { return self::email( $m[0] ); },
			$text
		);
	}

	/** Redacts an array/object recursively (keys and string values). */
	public static function data( $data, $depth = 0 ) {
		if ( $depth > 8 ) { return self::MASK; }
		if ( is_object( $data ) ) { $data = get_object_vars( $data ); }
		if ( ! is_array( $data ) ) { return is_string( $data ) ? self::text( $data ) : $data; }
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SENSITIVE_KEYS, $key ) && ! is_bool( $value ) && null !== $value && '' !== $value ) {
				$out[ $key ] = is_array( $value ) ? self::MASK : self::MASK;
				continue;
			}
			if ( is_string( $key ) && preg_match( self::PII_KEYS, $key ) && is_string( $value ) && '' !== $value ) {
				$out[ $key ] = false !== strpos( $value, '@' ) ? self::email( $value ) : self::MASK;
				continue;
			}
			$out[ $key ] = self::data( $value, $depth + 1 );
		}
		return $out;
	}

	/** WP_Error with redacted message and data (status codes are kept). */
	public static function error( \WP_Error $error ) {
		$data = $error->get_error_data();
		return new \WP_Error( $error->get_error_code(), self::text( $error->get_error_message() ), is_array( $data ) || is_object( $data ) ? self::data( $data ) : ( is_string( $data ) ? self::text( $data ) : $data ) );
	}

	/** error_log() with redaction; the only place the plugin writes to the PHP error log. */
	public static function log( $message, array $context = array() ) {
		$line = 'Pass Captain: ' . self::text( (string) $message );
		if ( $context ) { $line .= ' ' . wp_json_encode( self::data( $context ) ); }
		if ( function_exists( 'apply_filters' ) && false === apply_filters( 'wpcap_error_log', true, $line ) ) { return $line; }
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- redacted above.
		return $line;
	}
}
