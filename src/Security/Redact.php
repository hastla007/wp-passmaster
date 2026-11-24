<?php
/**
 * Central redaction for anything that leaves the request in a durable or external form: audit log
 * details, error_log lines, stored provider/delivery errors and REST error messages.
 *
 * Removes: configured provider secrets (exact values), API keys / tokens by pattern (Stripe,
 * Mollie, Twilio, PayPal bearer, JWT, partner and developer keys, webhook secrets), PEM private
 * keys, 64-hex ticket/access codes, e-mail addresses (masked), IBANs, IPv4/IPv6 addresses, phone
 * numbers and values under sensitive keys (contact keys such as phone, ip, address and names masked).
 */
namespace PassCaptain\Security;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Redact {
	const MASK = '[redacted]';

	/** Array keys whose values are always removed. */
	const SENSITIVE_KEYS = '/(secret|passw|token|api_?key|authorization|private_?key|cookie|signature|credential|auth_header|iban|card)/i';

	/** Array keys holding contact data: e-mail, phone, client address, names, postal address (masked, not removed). */
	const PII_KEYS = '/(e_?mail|phone|mobile|recipient|(^|_)ip(_?address)?|^to|attendee_name|holder_name|contact_name|customer_name|buyer_name|full_name|first_name|last_name|street|(^|_)address(_line_?\d)?|postcode|zip)$/i';

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
		// Values of secret-named fields first: a configured secret that is part of a field name (e.g. a
		// PayPal secret "secret" inside "client_secret") must not break the field patterns below.
		$text = (string) preg_replace(
			array( '/([?&](?:access_token|token|key|secret|password|api_key|code)=)[^&\s"\']+/i', '/("(?:access_token|token|secret|password|api_key|client_secret|auth_token|refresh_token|private_key)"\s*:\s*")[^"]*"/i' ),
			array( '$1' . self::MASK, '$1' . self::MASK . '"' ),
			$text
		);
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
		$text = (string) preg_replace_callback(
			'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
			static function ( $m ) { return self::email( $m[0] ); },
			$text
		);
		return self::contact_data( $text );
	}

	/**
	 * IBANs, IPv4/IPv6 addresses and phone numbers in free text. Phone numbers need an international
	 * (+, 00) or national (0 followed by a non-zero digit) prefix and 8–15 digits, so dates, times,
	 * amounts and ids are left alone.
	 */
	private static function contact_data( $text ) {
		$text = (string) preg_replace( '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?\b/', self::MASK, $text );
		$text = (string) preg_replace_callback(
			'/(?<![\w:.])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])|(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/i',
			static function ( $m ) {
				if ( false === filter_var( $m[0], FILTER_VALIDATE_IP ) ) { return $m[0]; }
				// Already truncated addresses (/24 IPv4 "198.51.100.0", /48 IPv6 "2001:db8:1::") stay readable.
				return preg_match( '/^(\d+\.){3}0$|::$/', $m[0] ) ? $m[0] : self::MASK;
			},
			$text
		);
		return (string) preg_replace_callback(
			'/(?<![\w.\/+\-])(?:\+|00)?\d[\d ()\/\-]{6,}\d(?![\w.\/\-])/',
			static function ( $m ) {
				$candidate = $m[0];
				$digits = (string) preg_replace( '/\D/', '', $candidate );
				$prefixed = '+' === $candidate[0] || 0 === strpos( $candidate, '00' ) || ( '0' === $candidate[0] && isset( $digits[1] ) && '0' !== $digits[1] );
				return $prefixed && strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? self::MASK : $candidate;
			},
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
				$out[ $key ] = self::pii_value( $value );
				continue;
			}
			if ( is_array( $value ) && $value && ! array_diff( array_keys( $value ), array( 'from', 'to' ) ) ) {
				// Before/after diff of a correction (AccessLog::correction(), A122): the field name decides, not the
				// key "to" (which elsewhere names a mail recipient). Contact fields stay masked, e.g. a country code
				// stays readable.
				$pii = is_string( $key ) && preg_match( self::PII_KEYS, $key );
				foreach ( $value as $side => $v ) {
					$out[ $key ][ $side ] = $pii && is_string( $v ) && '' !== $v ? self::pii_value( $v ) : self::data( $v, $depth + 2 );
				}
				continue;
			}
			$out[ $key ] = self::data( $value, $depth + 1 );
		}
		return $out;
	}

	/** Masked form of a contact value: e-mail masked, IP truncated, an already masked value ("E***") kept, else removed. */
	private static function pii_value( $value ) {
		if ( false !== strpos( $value, '@' ) ) { return self::email( $value ); }
		if ( false !== filter_var( $value, FILTER_VALIDATE_IP ) ) { return self::contact_data( $value ); } // Truncated addresses stay readable.
		if ( preg_match( '/^[^*]{0,2}\*{3}$/u', $value ) ) { return $value; }
		return self::MASK;
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
