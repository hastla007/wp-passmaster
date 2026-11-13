<?php
/**
 * Central redaction of secrets and masked personal data in everything the plugin writes for
 * operators or clients under error conditions (A194): debug log, audit details, REST error
 * responses, admin notices, admin mails, error columns of the queues and the Site Health texts.
 *
 * Removed are
 * - the values of all provider secrets (Secrets::KEYS, also when set by constant/environment) and of
 *   the outgoing webhook signing secrets, in plain, URL- and JSON-encoded form,
 * - well-known secret shapes: PEM private keys, Stripe secret/restricted keys and webhook secrets
 *   (sk_/rk_/whsec_), Bearer/Basic credentials, `key=value` pairs whose name is a secret
 *   (password, token, api_key, secret, signature, …), user:password in URLs,
 * - card numbers (13–19 digits, Luhn) and IBANs (mod-97 checksum): only the last four characters stay,
 * - with $mask_emails (default) e-mail addresses (as in the operations overview).
 *
 * Uncaught exceptions of the plugin's REST callbacks are turned into a generic 500 error with a
 * reference; the (redacted) exception text goes to the PHP error log, never a stack trace.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Redact {
	const MASK = '[redacted]';

	/** REST namespaces whose responses and callbacks are guarded (current and legacy slug). */
	const REST_PREFIXES = array( '/pass-captain/' );

	/** Key names whose (string) values are always removed in structured data. */
	const SECRET_KEY_PATTERN = '/(secret|passw|passphrase|token|api[_-]?key|apikey|private[_-]?key|authori[sz]ation|signature|^auth$|^sig$|cvc|cvv|card[_-]?number|^pan$|^iban$)/i';

	/** Per-request list of known secret values (longest first); null = not loaded yet. */
	private static $known = null;

	public static function register() {
		add_filter( 'rest_endpoints', array( __CLASS__, 'guard_endpoints' ), 999 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'rest_after_callbacks' ), 999, 3 );
	}

	/** Forgets the cached secret values (after a secret was changed; tests). */
	public static function reset() {
		self::$known = null;
	}

	/**
	 * Values that must never appear in an output: all provider secrets and webhook signing secrets.
	 * Extend with the filter `wpcap_redact_values`.
	 *
	 * @return string[]
	 */
	public static function known_values() {
		if ( null !== self::$known ) { return self::$known; }
		$values = array();
		foreach ( Secrets::KEYS as $key ) {
			$value = (string) Secrets::get( $key );
			if ( '' === $value ) { continue; }
			$values[] = $value;
			// JSON service accounts / PEM bundles: also the parts that may be quoted on their own.
			$json = json_decode( $value, true );
			if ( is_array( $json ) ) {
				foreach ( array( 'private_key', 'private_key_id' ) as $part ) {
					if ( ! empty( $json[ $part ] ) && is_string( $json[ $part ] ) ) { $values[] = $json[ $part ]; }
				}
			}
			foreach ( preg_split( '/\R/', $value ) as $line ) {
				$line = trim( (string) $line );
				if ( strlen( $line ) >= 24 && ! str_starts_with( $line, '-----' ) ) { $values[] = $line; }
			}
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && Privacy::has_table( 'wpcap_webhook_subscriptions' ) ) {
			foreach ( (array) $wpdb->get_col( "SELECT secret FROM {$wpdb->prefix}wpcap_webhook_subscriptions WHERE secret<>'' LIMIT 200" ) as $secret ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$values[] = (string) $secret;
			}
		}
		/**
		 * Additional secret values that redaction removes from logs, error texts and error responses.
		 *
		 * @param string[] $values Known secret values.
		 */
		$values = (array) apply_filters( 'wpcap_redact_values', $values );
		$out = array();
		foreach ( $values as $value ) {
			$value = (string) $value;
			if ( strlen( $value ) < 8 ) { continue; } // Too short to be told apart from ordinary words.
			$out[ $value ] = true;
			$out[ rawurlencode( $value ) ] = true;
			$out[ urlencode( $value ) ] = true; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- form encoding of request bodies.
			$out[ substr( (string) wp_json_encode( $value ), 1, -1 ) ] = true;
		}
		$out = array_keys( $out );
		usort( $out, static function ( $a, $b ) { return strlen( (string) $b ) - strlen( (string) $a ); } );
		self::$known = array_values( array_filter( array_map( 'strval', $out ), static function ( $v ) { return strlen( $v ) >= 8; } ) );
		return self::$known;
	}

	/**
	 * Redacted copy of a text.
	 *
	 * @param mixed $text        Text (non-scalars yield '').
	 * @param bool  $mask_emails Also mask e-mail addresses.
	 */
	public static function text( $text, $mask_emails = true ) {
		if ( ! is_scalar( $text ) ) { return ''; }
		$text = (string) $text;
		if ( '' === $text ) { return ''; }
		$known = self::known_values();
		if ( $known ) { $text = str_replace( $known, self::MASK, $text ); }
		$patterns = array(
			// PEM private keys (also truncated ones).
			'/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s' => self::MASK,
			// Stripe-style secret/restricted keys and webhook secrets.
			'/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9*]{6,}/' => self::MASK,
			'/\bwhsec_[A-Za-z0-9]{6,}/' => self::MASK,
			// HTTP credentials.
			'/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]{8,}=*/' => '$1 ' . self::MASK,
			// user:password@ in URLs.
			'#(\b[a-z][a-z0-9+.\-]*://)[^\s/@:]+:[^\s/@]+@#i' => '$1' . self::MASK . '@',
			// name=value / "name": "value" pairs whose name is a secret.
			'/((?:[A-Za-z0-9_\-]*(?:secret|passw(?:or)?d|passphrase|api[_-]?key|apikey|access[_-]?token|auth[_-]?token|refresh[_-]?token|private[_-]?key|signature)[A-Za-z0-9_\-]*|token|key|sig)["\']?\s*[:=]\s*["\']?)(?!\[redacted\])([^\s"\'&,;}\]]{4,})/i' => '$1' . self::MASK,
		);
		$text = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		$text = (string) preg_replace_callback( '/(?<![A-Za-z0-9])[A-Z]{2}[0-9]{2}(?:[ ]?[A-Z0-9]){11,30}(?![A-Za-z0-9])/', array( __CLASS__, 'mask_iban_match' ), $text );
		$text = (string) preg_replace_callback( '/(?<![0-9])(?:[0-9][ \-]?){12,18}[0-9](?![0-9])/', array( __CLASS__, 'mask_card_match' ), $text );
		if ( $mask_emails ) {
			$text = (string) preg_replace( '/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[A-Za-z]{2,}/', '***@***', $text );
		}
		return $text;
	}

	/** @param string[] $m Match. */
	private static function mask_iban_match( $m ) {
		$iban = PayoutAccount::normalize_iban( $m[0] );
		return '' === $iban ? $m[0] : substr( $iban, 0, 2 ) . '** **** ' . substr( $iban, -4 );
	}

	/** @param string[] $m Match. */
	private static function mask_card_match( $m ) {
		$digits = preg_replace( '/\D/', '', $m[0] );
		$len = strlen( $digits );
		if ( $len < 13 || $len > 19 || ! self::luhn( $digits ) ) { return $m[0]; }
		return '**** ' . substr( $digits, -4 );
	}

	/** Luhn checksum of a digit string. */
	public static function luhn( $digits ) {
		$sum = 0;
		$alt = false;
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$n = (int) $digits[ $i ];
			if ( $alt ) { $n *= 2; if ( $n > 9 ) { $n -= 9; } }
			$sum += $n;
			$alt = ! $alt;
		}
		return 0 === $sum % 10;
	}

	/**
	 * Redacted copy of structured data: secret-named keys lose their value, strings are redacted.
	 *
	 * @param mixed $value       Data.
	 * @param bool  $mask_emails Also mask e-mail addresses in strings.
	 */
	public static function value( $value, $mask_emails = true, $depth = 0 ) {
		if ( $depth > 8 ) { return null; }
		if ( is_string( $value ) ) { return self::text( $value, $mask_emails ); }
		if ( is_object( $value ) && ! $value instanceof \JsonSerializable ) { $value = get_object_vars( $value ); }
		if ( ! is_array( $value ) ) { return is_scalar( $value ) || null === $value ? $value : null; }
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && preg_match( self::SECRET_KEY_PATTERN, $key ) && ( is_string( $item ) && '' !== $item ) ) {
				$out[ $key ] = self::MASK;
				continue;
			}
			$out[ $key ] = self::value( $item, $mask_emails, $depth + 1 );
		}
		return $out;
	}

	/** Redacted copy of a WP_Error (message and data of every code). */
	public static function error( $error, $mask_emails = true ) {
		if ( ! $error instanceof \WP_Error ) { return $error; }
		$codes = method_exists( $error, 'get_error_codes' ) ? $error->get_error_codes() : array( $error->get_error_code() );
		$out = null;
		foreach ( $codes as $code ) {
			$messages = method_exists( $error, 'get_error_messages' ) ? $error->get_error_messages( $code ) : array( $error->get_error_message() );
			$data = $error->get_error_data( $code );
			foreach ( $messages ?: array( '' ) as $message ) {
				$message = self::text( $message, $mask_emails );
				if ( null === $out ) {
					$out = new \WP_Error( $code, $message, null === $data ? '' : self::value( $data, $mask_emails ) );
				} else {
					$out->add( $code, $message, null === $data ? '' : self::value( $data, $mask_emails ) );
				}
			}
		}
		return $out ?? new \WP_Error();
	}

	/** One-line description of a throwable without trace and arguments: "Class: message (file:line)". */
	public static function exception( $e ) {
		if ( ! $e instanceof \Throwable ) { return ''; }
		return get_class( $e ) . ': ' . self::text( $e->getMessage() ) . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	}

	/**
	 * Writes a redacted line to the PHP error log (debug.log with WP_DEBUG_LOG).
	 *
	 * @param string $message Message.
	 * @param array  $context Optional structured context (redacted, JSON).
	 */
	public static function log( $message, array $context = array() ) {
		$line = 'Pass Captain: ' . self::text( (string) $message );
		if ( $context ) { $line .= ' ' . wp_json_encode( self::value( $context ) ); }
		$line = str_replace( array( "\r", "\n" ), ' ', $line );
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the plugin's central (redacted) log line.
	}

	/* ---------------------------------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------------------------------- */

	private static function own_route( $route ) {
		foreach ( self::REST_PREFIXES as $prefix ) {
			if ( 0 === strpos( (string) $route, $prefix ) ) { return true; }
		}
		return false;
	}

	/**
	 * rest_endpoints: wraps the callbacks and permission callbacks of the plugin's routes so an
	 * uncaught exception becomes a 500 error without message details or stack trace.
	 *
	 * @param array $endpoints Route => handlers.
	 */
	public static function guard_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) { return $endpoints; }
		foreach ( $endpoints as $route => $handlers ) {
			if ( ! self::own_route( $route ) || ! is_array( $handlers ) ) { continue; }
			foreach ( $handlers as $i => $handler ) {
				if ( ! is_int( $i ) || ! is_array( $handler ) ) { continue; }
				foreach ( array( 'callback', 'permission_callback' ) as $slot ) {
					if ( isset( $handler[ $slot ] ) && is_callable( $handler[ $slot ] ) && empty( $handler[ 'wpcap_guarded_' . $slot ] ) ) {
						$handler[ $slot ] = self::guard( $handler[ $slot ], (string) $route );
						$handler[ 'wpcap_guarded_' . $slot ] = true;
					}
				}
				$endpoints[ $route ][ $i ] = $handler;
			}
		}
		return $endpoints;
	}

	/** Callable that runs $callback and turns any Throwable into internal_error(). */
	public static function guard( $callback, $route = '' ) {
		return static function ( ...$args ) use ( $callback, $route ) {
			try {
				return call_user_func_array( $callback, $args );
			} catch ( \Throwable $e ) {
				return self::internal_error( $e, $route );
			}
		};
	}

	/** Logs a throwable (redacted, without trace) and returns the generic 500 error with a reference. */
	public static function internal_error( $e, $context = '' ) {
		$reference = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
		self::log( 'internal error ' . $reference . ( '' !== (string) $context ? ' in ' . (string) $context : '' ) . ': ' . self::exception( $e ) );
		return new \WP_Error(
			'wpcap_internal_error',
			/* translators: %s: error reference for support */
			sprintf( __( 'Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut (Referenz %s).', 'pass-captain' ), $reference ),
			array( 'status' => 500, 'reference' => $reference )
		);
	}

	/**
	 * rest_request_after_callbacks: error answers of the plugin's routes carry no secrets.
	 *
	 * @param mixed            $response Result of the callback.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 */
	public static function rest_after_callbacks( $response, $handler = array(), $request = null ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( '' !== $route && ! self::own_route( $route ) ) { return $response; }
		if ( $response instanceof \WP_Error ) { return self::error( $response ); }
		if ( $response instanceof \WP_REST_Response && method_exists( $response, 'set_data' ) && (int) $response->get_status() >= 400 ) {
			$response->set_data( self::value( $response->get_data() ) );
		}
		return $response;
	}
}
