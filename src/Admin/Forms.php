<?php
/**
 * Shared helpers for the admin screens: input parsing (money, percent, local datetimes),
 * output formatting, form scaffolding, flash notices and redirects.
 *
 * Parsing helpers are pure functions so they can be unit tested without WordPress.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Forms {
	/** Upper bound for entered amounts in major units (keeps minor values far below bigint limits). */
	const MAX_MAJOR_DIGITS = 10;

	/** ISO 4217 currencies without minor unit. */
	const ZERO_DECIMAL_CURRENCIES = \PassCaptain\Money::ZERO_DECIMALS;

	/** Number of decimals of a currency's minor unit (0 JPY, 2 EUR, 3 KWD; see PassCaptain\Money). */
	public static function currency_decimals( $currency ) {
		return \PassCaptain\Money::decimals( $currency );
	}

	/**
	 * Parses a user-entered decimal amount into minor units without floating point math.
	 *
	 * Accepts "12,50", "12.50", "12", "1.234,56", "1,234.56", "1 234,56", "1'234.50", "€ 12,50", "12,5".
	 * A single separator followed by exactly three digits is read as a thousands separator
	 * ("1.234" = 1234), otherwise as the decimal separator; for three-decimal currencies
	 * (decimals = 3) it is the decimal separator ("12,345" = 12345 fils). Negative values are rejected.
	 *
	 * @param mixed $input    Raw input.
	 * @param int   $decimals Minor unit decimals (2 for EUR).
	 * @return int|null Minor units or null when the input is not a valid amount.
	 */
	public static function parse_money( $input, $decimals = 2 ) {
		if ( ! is_scalar( $input ) ) { return null; }
		$decimals = max( 0, min( 4, (int) $decimals ) );
		$s = trim( (string) $input );
		$s = preg_replace( "/[\\s\\x{00A0}\\x{202F}'\\x{2019}]/u", '', $s );
		$s = preg_replace( '/^(?:[A-Za-z]{1,3}|[€$£¥])/u', '', (string) $s );
		$s = preg_replace( '/(?:[A-Za-z]{1,3}|[€$£¥])$/u', '', (string) $s );
		if ( null === $s || '' === $s || ! preg_match( '/^[0-9.,]+$/', $s ) || ! preg_match( '/[0-9]/', $s ) ) {
			return null;
		}
		$last_dot = strrpos( $s, '.' );
		$last_comma = strrpos( $s, ',' );
		$decimal_sep = null;
		if ( false !== $last_dot && false !== $last_comma ) {
			$decimal_sep = $last_dot > $last_comma ? '.' : ',';
		} elseif ( false !== $last_dot || false !== $last_comma ) {
			$sep = false !== $last_dot ? '.' : ',';
			$after = strlen( $s ) - strrpos( $s, $sep ) - 1;
			// Three digits after a single separator are thousands ("1.234") – except for currencies with
			// three decimals (KWD "12,345" = 12.345), where money_input() writes exactly that.
			if ( 1 === substr_count( $s, $sep ) && ( 3 !== $after || 3 === $decimals ) ) {
				$decimal_sep = $sep;
			}
		}
		if ( null !== $decimal_sep ) {
			$pos = strrpos( $s, $decimal_sep );
			$int_part = substr( $s, 0, $pos );
			$fraction = (string) substr( $s, $pos + 1 );
		} else {
			$int_part = $s;
			$fraction = '';
		}
		if ( '' !== $fraction && ! ctype_digit( $fraction ) ) { return null; }
		if ( '' === $int_part ) {
			if ( '' === $fraction ) { return null; }
			$int_part = '0';
		}
		if ( ! ctype_digit( $int_part ) ) {
			// Grouped thousands: 1-3 leading digits, then groups of exactly three with one separator type.
			if ( ! preg_match( '/^[0-9]{1,3}(?:([.,])[0-9]{3})(?:\1[0-9]{3})*$/', $int_part, $m ) || $m[1] === $decimal_sep || '0' === $int_part[0] ) {
				return null;
			}
			$int_part = str_replace( $m[1], '', $int_part );
		}
		$int_part = ltrim( $int_part, '0' );
		if ( '' === $int_part ) { $int_part = '0'; }
		if ( strlen( $int_part ) > self::MAX_MAJOR_DIGITS ) { return null; }
		if ( strlen( $fraction ) > $decimals ) {
			// Extra digits are only accepted when they are zeros ("12,500" with a decimal comma, "100,00" for JPY).
			if ( '' !== trim( substr( $fraction, $decimals ), '0' ) ) { return null; }
			$fraction = substr( $fraction, 0, $decimals );
		}
		$fraction = str_pad( $fraction, $decimals, '0' );
		return (int) $int_part * ( 10 ** $decimals ) + ( $decimals ? (int) $fraction : 0 );
	}

	/** Formats minor units for an input field, German style ("1234,50"). */
	public static function money_input( $minor, $decimals = 2 ) {
		$minor = (int) $minor;
		$decimals = max( 0, (int) $decimals );
		if ( ! $decimals ) { return (string) $minor; }
		$factor = 10 ** $decimals;
		return intdiv( $minor, $factor ) . ',' . str_pad( (string) ( abs( $minor ) % $factor ), $decimals, '0', STR_PAD_LEFT );
	}

	/** Display string for an amount, e.g. "1.234,50 EUR". */
	public static function money( $minor, $currency ) {
		return \PassCaptain\Money::format( $minor, $currency );
	}

	/**
	 * Parses a percentage ("19", "7,7", "7.75 %") into basis points (1900, 770, 775).
	 *
	 * @return int|null Basis points between 0 and 10000, or null when invalid.
	 */
	public static function parse_percent_bp( $input ) {
		if ( ! is_scalar( $input ) ) { return null; }
		$s = preg_replace( '/[\s\x{00A0}%]/u', '', trim( (string) $input ) );
		if ( '' === $s ) { return 0; }
		if ( ! preg_match( '/^([0-9]{1,3})(?:[.,]([0-9]{0,2}))?$/', (string) $s, $m ) ) { return null; }
		$bp = (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '', 2, '0' );
		return $bp <= 10000 ? $bp : null;
	}

	/** Formats basis points for an input field ("19", "7,7", "7,75"). */
	public static function percent_input( $bp ) {
		$bp = max( 0, (int) $bp );
		$fraction = rtrim( str_pad( (string) ( $bp % 100 ), 2, '0', STR_PAD_LEFT ), '0' );
		return intdiv( $bp, 100 ) . ( '' !== $fraction ? ',' . $fraction : '' );
	}

	/** Whether a string is a valid IANA timezone identifier. */
	public static function is_valid_timezone( $timezone ) {
		return is_string( $timezone ) && '' !== $timezone && in_array( $timezone, timezone_identifiers_list(), true );
	}

	/**
	 * Converts a local wall-clock time (datetime-local input) in $timezone to a UTC MySQL datetime.
	 * Deterministic around DST changes: a time inside a gap (02:30 on spring-forward days) is moved
	 * forward by PHP (→ 03:30); an ambiguous time (fall-back hour) uses the first instance (summer
	 * time). Admin forms for occurrence times use local_to_utc_checked(), which rejects gap times.
	 *
	 * @return string|null "Y-m-d H:i:s" in UTC, or null when the input or timezone is invalid.
	 */
	public static function local_to_utc( $local, $timezone ) {
		$info = self::local_time_info( $local, $timezone );
		return $info ? $info['utc'] : null;
	}

	/**
	 * Analyses a local wall-clock time in $timezone with respect to DST changes.
	 *
	 * @return array|null null for invalid input, else:
	 *   status     'ok', 'gap' (the time does not exist) or 'ambiguous' (it exists twice),
	 *   utc        UTC datetime used by local_to_utc() (gap: shifted forward; ambiguous: first instance),
	 *   local      normalised input "Y-m-d H:i",
	 *   next_valid gap only: first valid local time after the gap ("Y-m-d H:i"),
	 *   offsets    ambiguous only: UTC offsets of the first and second instance (e.g. +02:00, +01:00).
	 */
	public static function local_time_info( $local, $timezone ) {
		if ( ! is_scalar( $local ) || ! self::is_valid_timezone( $timezone ) ) { return null; }
		$local = str_replace( 'T', ' ', trim( (string) $local ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local, $m ) ) { return null; }
		$format = isset( $m[1] ) ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
		$zone = new \DateTimeZone( $timezone );
		$date = \DateTimeImmutable::createFromFormat( '!' . $format, $local, $zone );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) { return null; }
		$utc = new \DateTimeZone( 'UTC' );
		$ts = $date->getTimestamp();
		$info = array( 'status' => 'ok', 'local' => substr( $local, 0, 16 ) );
		if ( $date->format( $format ) !== $local ) {
			// Not a wall-clock time of this zone: the last transition before the shifted instant ends the gap.
			$info['status'] = 'gap';
			$end = $ts;
			foreach ( (array) $zone->getTransitions( $ts - 2 * DAY_IN_SECONDS, $ts ) as $i => $transition ) {
				if ( $i > 0 && (int) $transition['ts'] <= $ts ) { $end = (int) $transition['ts']; }
			}
			$info['next_valid'] = $date->setTimestamp( $end )->format( 'Y-m-d H:i' );
		} else {
			// The same wall-clock time at another instant (shifts of up to two hours cover all zones).
			$instants = array( $ts );
			foreach ( array( -7200, -3600, -1800, 1800, 3600, 7200 ) as $delta ) {
				if ( $date->setTimestamp( $ts + $delta )->format( $format ) === $local ) { $instants[] = $ts + $delta; }
			}
			if ( count( $instants ) > 1 ) {
				sort( $instants );
				$ts = $instants[0];
				$info['status'] = 'ambiguous';
				$info['offsets'] = array( $date->setTimestamp( $instants[0] )->format( 'P' ), $date->setTimestamp( $instants[1] )->format( 'P' ) );
			}
		}
		$info['utc'] = $date->setTimestamp( $ts )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		return $info;
	}

	/**
	 * local_to_utc() for occurrence times entered by an admin: a time inside a DST gap is rejected
	 * with the next valid time; an ambiguous time uses the first instance and adds a note to $notes.
	 *
	 * @param mixed    $local    datetime-local input.
	 * @param string   $timezone IANA timezone.
	 * @param string[] $notes    Collects notes for ambiguous times (by reference).
	 * @return string|null|\WP_Error UTC datetime, null for invalid input, WP_Error for a nonexistent time.
	 */
	public static function local_to_utc_checked( $local, $timezone, &$notes = array() ) {
		$info = self::local_time_info( $local, $timezone );
		if ( ! $info ) { return null; }
		$shown = static function ( $value ) { return wp_date( 'd.m.Y H:i', strtotime( $value . ' UTC' ), new \DateTimeZone( 'UTC' ) ); };
		if ( 'gap' === $info['status'] ) {
			return self::error( 'wpcap_time_nonexistent', sprintf(
				/* translators: 1: entered local time, 2: timezone, 3: next valid local time */
				__( 'Die Ortszeit %1$s gibt es in der Zeitzone %2$s wegen der Umstellung auf Sommerzeit nicht. Bitte eine andere Zeit wählen, z. B. %3$s (erste gültige Zeit danach).', 'pass-captain' ),
				$shown( $info['local'] ),
				$timezone,
				$shown( $info['next_valid'] )
			) );
		}
		if ( 'ambiguous' === $info['status'] ) {
			$notes[] = sprintf(
				/* translators: 1: entered local time, 2: timezone, 3: UTC offset used, 4: UTC offset of the second instance */
				__( 'Die Ortszeit %1$s kommt in der Zeitzone %2$s wegen der Umstellung auf Winterzeit zweimal vor. Verwendet wurde die erste (Sommerzeit, UTC%3$s), nicht die zweite (UTC%4$s).', 'pass-captain' ),
				$shown( $info['local'] ),
				$timezone,
				$info['offsets'][0],
				$info['offsets'][1]
			);
		}
		return $info['utc'];
	}

	/**
	 * Start of the $index-th weekly repetition at the same local wall-clock time. A repetition whose
	 * time does not exist (DST gap) is moved forward by the gap, an ambiguous one uses the first
	 * instance; both add a note to $notes.
	 *
	 * @param \DateTimeImmutable $first First start in the event timezone.
	 * @return \DateTimeImmutable Start in the event timezone.
	 */
	public static function weekly_repeat_start( \DateTimeImmutable $first, $index, $timezone, &$notes = array() ) {
		if ( ! $index ) { return $first; }
		$wall = ( new \DateTimeImmutable( $first->format( 'Y-m-d H:i:s' ), new \DateTimeZone( 'UTC' ) ) )->modify( '+' . ( 7 * (int) $index ) . ' days' )->format( 'Y-m-d H:i:s' );
		$info = self::local_time_info( $wall, $timezone );
		if ( ! $info ) { return $first->modify( '+' . ( 7 * (int) $index ) . ' days' ); }
		$start = ( new \DateTimeImmutable( $info['utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( $timezone ) );
		$shown = wp_date( 'd.m.Y H:i', strtotime( $info['local'] . ' UTC' ), new \DateTimeZone( 'UTC' ) );
		if ( 'gap' === $info['status'] ) {
			/* translators: 1: local time of the repetition, 2: timezone, 3: local time used instead */
			$notes[] = sprintf( __( 'Die Wiederholung am %1$s fällt in die Zeitumstellung (Zeitzone %2$s, diese Ortszeit gibt es nicht) und wurde auf %3$s verschoben.', 'pass-captain' ), $shown, $timezone, $start->format( 'd.m.Y H:i' ) );
		} elseif ( 'ambiguous' === $info['status'] ) {
			/* translators: 1: local time of the repetition, 2: timezone, 3: UTC offset used */
			$notes[] = sprintf( __( 'Die Wiederholung am %1$s liegt in der doppelten Stunde der Zeitumstellung (Zeitzone %2$s); verwendet wurde die erste (Sommerzeit, UTC%3$s).', 'pass-captain' ), $shown, $timezone, $info['offsets'][0] );
		}
		return $start;
	}

	/** Result fragment with the collected DST notes as a warning flash (Forms::finish), or array(). */
	public static function dst_warning( array $notes ) {
		$notes = array_values( array_unique( array_filter( $notes ) ) );
		return $notes ? array( 'warning' => implode( ' ', $notes ) ) : array();
	}

	/**
	 * Converts a UTC MySQL datetime to local time in $timezone.
	 *
	 * @param string|null $utc      UTC datetime or empty.
	 * @param string      $timezone IANA timezone (falls back to UTC).
	 * @param string      $format   Output format; default matches datetime-local inputs.
	 */
	public static function utc_to_local( $utc, $timezone, $format = 'Y-m-d\TH:i' ) {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) { return ''; }
		try {
			$date = new \DateTimeImmutable( (string) $utc, new \DateTimeZone( 'UTC' ) );
			return $date->setTimezone( new \DateTimeZone( self::is_valid_timezone( $timezone ) ? $timezone : 'UTC' ) )->format( $format );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Display string for a UTC datetime in a timezone, e.g. "24.12.2026 19:30 Europe/Berlin". */
	public static function datetime( $utc, $timezone, $with_zone = true ) {
		$local = self::utc_to_local( $utc, $timezone, 'd.m.Y H:i' );
		if ( '' === $local ) { return '—'; }
		return $with_zone ? $local . ' ' . ( self::is_valid_timezone( $timezone ) ? $timezone : 'UTC' ) : $local;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Input helpers (operate on already unslashed arrays).
	 * ------------------------------------------------------------------------------------------- */

	/** Single-line text input, trimmed and length limited. */
	public static function text( array $in, $key, $max = 190 ) {
		$value = $in[ $key ] ?? '';
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
	}

	/** Multi-line plain text input. */
	public static function textarea( array $in, $key, $max = 5000 ) {
		$value = $in[ $key ] ?? '';
		return is_scalar( $value ) ? mb_substr( sanitize_textarea_field( (string) $value ), 0, $max ) : '';
	}

	/** Non-negative integer input. */
	public static function int( array $in, $key, $default = 0 ) {
		$value = $in[ $key ] ?? $default;
		return is_scalar( $value ) ? absint( $value ) : (int) $default;
	}

	/** Checkbox input. */
	public static function flag( array $in, $key ) {
		$value = $in[ $key ] ?? '';
		return is_scalar( $value ) && '' !== (string) $value && '0' !== (string) $value;
	}

	/** Value restricted to an allow list. */
	public static function choice( array $in, $key, array $allowed, $default ) {
		$value = $in[ $key ] ?? $default;
		return is_scalar( $value ) && in_array( (string) $value, $allowed, true ) ? (string) $value : $default;
	}

	/** List of positive integers (e.g. ticket_ids[]). */
	public static function ids( array $in, $key ) {
		$value = $in[ $key ] ?? array();
		if ( ! is_array( $value ) ) { $value = array( $value ); }
		return array_values( array_unique( array_filter( array_map( 'absint', array_filter( $value, 'is_scalar' ) ) ) ) );
	}

	/** List of sanitised keys (e.g. enabled_providers[]). */
	public static function ids_or_keys( array $in, $key ) {
		$value = $in[ $key ] ?? array();
		if ( ! is_array( $value ) ) { $value = array( $value ); }
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_filter( $value, 'is_scalar' ) ) ) ) );
	}

	/**
	 * Builds a WP_Error for admin handlers.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message (names the field, A297).
	 * @param int    $status  HTTP status.
	 * @param string $field   Name of the form field the error belongs to ('' = none); the form marks it.
	 */
	public static function error( $code, $message, $status = 400, $field = '' ) {
		$data = array( 'status' => $status );
		if ( '' !== (string) $field ) { $data['field'] = (string) $field; }
		return new \WP_Error( $code, $message, $data );
	}

	/** The same error with the form field it belongs to (e.g. a DST error of a date field). */
	public static function with_field( \WP_Error $error, $field ) {
		$data = $error->get_error_data();
		return self::error( $error->get_error_code(), $error->get_error_message(), is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400, $field );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Input of a failed form (A297): kept for one redisplay instead of being discarded.
	 * ------------------------------------------------------------------------------------------- */

	/** Transient prefix (per user) of the input of the last failed form. */
	const OLD_INPUT = 'wpcap_admin_old_input_';

	/**
	 * admin-post actions whose input is kept after a validation error (filter
	 * `wpcap_admin_remember_input_actions`). Only forms that show it again are listed, so no other
	 * input (attendee data, credentials) is stored.
	 *
	 * @return string[]
	 */
	public static function remember_input_actions() {
		return (array) apply_filters( 'wpcap_admin_remember_input_actions', array( 'wpcap_create_event', 'wpcap_update_event', 'wpcap_save_occurrence', 'wpcap_save_ticket_type' ) );
	}

	/**
	 * Keeps the submitted input of a failed form for five minutes (current user only) together with
	 * the field the error names. Hidden WordPress fields and secret-like keys are never stored.
	 */
	public static function remember_input( \WP_Error $error, ?array $post = null ) {
		$post = null === $post ? self::post() : $post;
		$action = isset( $post['action'] ) && is_scalar( $post['action'] ) ? sanitize_key( (string) $post['action'] ) : '';
		if ( '' === $action || ! in_array( $action, self::remember_input_actions(), true ) ) { return false; }
		$input = array();
		foreach ( $post as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] || 'action' === $key || preg_match( '/(secret|password|token|nonce|_key$)/i', $key ) ) { continue; }
			if ( is_scalar( $value ) ) {
				$input[ $key ] = \PassCaptain\Text::cut( (string) $value, 20000 );
			} elseif ( is_array( $value ) ) {
				$input[ $key ] = array_map( 'strval', array_slice( array_filter( $value, 'is_scalar' ), 0, 200, true ) );
			}
		}
		$data = $error->get_error_data();
		$field = is_array( $data ) && isset( $data['field'] ) && is_scalar( $data['field'] ) ? sanitize_key( (string) $data['field'] ) : '';
		set_transient( self::OLD_INPUT . get_current_user_id(), array( 'action' => $action, 'input' => $input, 'field' => $field ), 5 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Input kept for a form (once; it is removed when a form takes it). $match ties it to one form
	 * instance, e.g. array( 'ticket_type_id' => 12 ) for the edit form of one ticket type.
	 *
	 * @param string $action admin-post action of the form.
	 * @param array  $match  Hidden field values the kept input must have.
	 * @return array input, field – or array() when nothing is kept for this form.
	 */
	public static function old( $action, array $match = array() ) {
		$key = self::OLD_INPUT . get_current_user_id();
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || ( $stored['action'] ?? '' ) !== $action ) { return array(); }
		$input = (array) ( $stored['input'] ?? array() );
		foreach ( $match as $name => $value ) {
			if ( (string) ( $input[ $name ] ?? '' ) !== (string) $value ) { return array(); }
		}
		delete_transient( $key );
		return array( 'input' => $input, 'field' => (string) ( $stored['field'] ?? '' ) );
	}

	/** Kept value of a field, else $default (the stored value). */
	public static function old_value( array $old, $key, $default ) {
		return $old && array_key_exists( $key, $old['input'] ) && is_scalar( $old['input'][ $key ] ) ? (string) $old['input'][ $key ] : $default;
	}

	/** Kept state of a checkbox, else $default. A kept form without the box means unticked. */
	public static function old_flag( array $old, $key, $default ) {
		return $old ? ! empty( $old['input'][ $key ] ) : (bool) $default;
	}

	/** ' aria-invalid="true" autofocus' for the field the error named. */
	public static function invalid_attr( array $old, $key ) {
		return $old && $key === $old['field'] ? ' aria-invalid="true" autofocus' : '';
	}

	/** Standard "no permission" error. */
	public static function forbidden( $message = '' ) {
		return self::error( 'wpcap_admin_forbidden', '' !== $message ? $message : __( 'Keine Berechtigung.', 'pass-captain' ), 403 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Request flow: nonce check, flash notices, redirects.
	 * ------------------------------------------------------------------------------------------- */

	/** Unslashed POST data. */
	public static function post() {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by caller.
	}

	/**
	 * Stops an admin-post request with 403 unless the user holds at least one of the capabilities
	 * (A145: capability first, before any lookup or validation; the object-level check follows in the
	 * handler).
	 *
	 * @param string[] $caps Capabilities.
	 */
	public static function require_any_cap( array $caps ) {
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) { return; }
		}
		wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) );
	}

	/** Verifies the nonce of an admin-post request (dies on failure). */
	public static function verify( $nonce_action ) {
		check_admin_referer( $nonce_action );
	}

	/** Stores a notice for the current user, shown on the next admin page view. */
	public static function flash( $type, $message ) {
		$key = 'wpcap_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notices[] = array( 'type' => 'error' === $type ? 'error' : ( 'warning' === $type ? 'warning' : 'success' ), 'message' => 'error' === $type ? \PassCaptain\Redact::text( (string) $message, false ) : (string) $message );
		set_transient( $key, array_slice( $notices, -10 ), 5 * MINUTE_IN_SECONDS );
	}

	/** Prints and clears stored notices. */
	public static function render_notices() {
		$key = 'wpcap_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) { return; }
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
	}

	/**
	 * Finishes an admin-post request: flashes the outcome and redirects.
	 * Forbidden results end with wp_die (403) instead of a redirect.
	 *
	 * @param mixed           $result  Handler result (WP_Error on failure).
	 * @param string          $success Success message.
	 * @param string          $url     Redirect target.
	 * @param string|callable $error_url Optional redirect target on errors.
	 */
	public static function finish( $result, $success, $url, $error_url = '' ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && 403 === (int) ( $data['status'] ?? 0 ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403, 'back_link' => true ) );
			}
			self::flash( 'error', $result->get_error_message() );
			self::remember_input( $result ); // A297: the form shows the input again.
			self::redirect( '' !== $error_url ? $error_url : $url );
		}
		if ( '' !== $success ) { self::flash( 'success', $success ); }
		if ( is_array( $result ) && ! empty( $result['warning'] ) ) { self::flash( 'warning', $result['warning'] ); }
		self::redirect( $url );
	}

	/** Safe redirect followed by exit. */
	public static function redirect( $url ) {
		wp_safe_redirect( $url );
		\PassCaptain\Http::end();
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering helpers.
	 * ------------------------------------------------------------------------------------------- */

	/** URL of an admin page of the plugin. */
	public static function page_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Tab navigation of a screen that merges several views (N02, N04). The first tab is the default
	 * view and is linked without a tab parameter.
	 *
	 * @param string               $page   Page slug.
	 * @param array<string,string> $tabs   Tab id => label (first = default view).
	 * @param string               $active Active tab id.
	 * @param array                $args   Further query args kept on every tab link (e.g. event_id).
	 * @param string               $label  Accessible name of the navigation.
	 */
	public static function nav_tabs( $page, array $tabs, $active, array $args, $label ) {
		$default = (string) array_key_first( $tabs );
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $tabs as $id => $title ) {
			$current = (string) $id === (string) $active;
			$url = self::page_url( $page, array_filter( $args ) + ( (string) $id === $default ? array() : array( 'tab' => (string) $id ) ) );
			echo '<a class="nav-tab' . ( $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $title ) . '</a>';
		}
		echo '</nav>';
	}

	/** Opens an admin-post form with action and nonce fields. */
	public static function open_form( $action, $nonce_action = null, $attrs = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $attrs ? ' ' . $attrs : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $attrs is a literal from callers.
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( null === $nonce_action ? $action : $nonce_action );
	}

	/** Hidden input. */
	public static function hidden( $name, $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	/** Select element. */
	public static function select( $name, array $options, $selected, $id = '', $extra = '' ) {
		echo '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $extra ? ' ' . $extra : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $extra is a literal from callers.
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, (string) $selected, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/** aria-label attribute for $extra of select()/inputs whose visible context is not a <label> (e.g. filter bars). */
	public static function aria_label( $label ) {
		return 'aria-label="' . esc_attr( $label ) . '"';
	}

	/** Timezone select. */
	public static function timezone_select( $name, $selected, $id = '', $extra = '' ) {
		$zones = timezone_identifiers_list();
		self::select( $name, array_combine( $zones, $zones ), $selected, $id, $extra ?: 'aria-label="' . esc_attr__( 'Zeitzone', 'pass-captain' ) . '"' );
	}

	/** Renders "Seite x von y" pagination links. */
	public static function pagination( $total, $page, $per_page, $base_url ) {
		$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">';
		/* translators: %s: number of entries */
		echo esc_html( sprintf( _n( '%s Eintrag', '%s Einträge', (int) $total, 'pass-captain' ), number_format_i18n( (int) $total ) ) );
		echo '</span>';
		if ( $pages > 1 ) {
			echo ' <span class="pagination-links">';
			if ( $page > 1 ) {
				echo '<a class="prev-page button" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base_url ) ) . '">&lsaquo;</a> ';
			}
			/* translators: 1: current page, 2: total pages */
			echo '<span class="paging-input">' . esc_html( sprintf( __( 'Seite %1$d von %2$d', 'pass-captain' ), $page, $pages ) ) . '</span>';
			if ( $page < $pages ) {
				echo ' <a class="next-page button" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base_url ) ) . '">&rsaquo;</a>';
			}
			echo '</span>';
		}
		echo '</div></div>';
	}

	/**
	 * Note and date filter under a bounded occurrence list (Queries::occurrence_window(), A476):
	 * "x of y dates shown" and a GET form "dates from" (parameter occ_from) that moves the window.
	 * Prints nothing when every date is listed and no filter is set.
	 *
	 * @param array $window Result of Queries::occurrence_window().
	 * @param array $args   Further query arguments of the page (e.g. event_id).
	 */
	public static function occurrence_window_filter( array $window, $slug, array $args = array() ) {
		if ( empty( $window['more'] ) && '' === (string) ( $window['from'] ?? '' ) ) { return; }
		echo '<form method="get" class="wpcap-occurrence-window"><input type="hidden" name="page" value="' . esc_attr( $slug ) . '">';
		foreach ( $args as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">'; }
		/* translators: 1: dates listed, 2: dates of the event */
		echo '<p class="description">' . esc_html( sprintf( __( '%1$d von %2$d Terminen werden angezeigt (die nächsten ab dem gewählten Tag, ohne Auswahl ab heute).', 'pass-captain' ), count( (array) $window['rows'] ), (int) $window['total'] ) ) . ' ';
		echo '<label>' . esc_html__( 'Termine ab', 'pass-captain' ) . ' <input type="date" name="occ_from" value="' . esc_attr( (string) ( $window['from'] ?? '' ) ) . '"></label> ';
		echo '<button class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button></p></form>';
	}

	/** Current page number from the query string. */
	public static function current_page() {
		return max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Sanitised GET parameter. */
	public static function query_arg( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view parameter, sanitised on the next line.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : $default;
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
	}

	/** Display name of a WordPress user id. */
	public static function user_label( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return '—'; }
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		return $user ? $user->display_name . ' (#' . $user_id . ')' : '#' . $user_id;
	}

	/**
	 * Translated labels for status values. A515: all with the gettext context "status", so short words
	 * ("Aktiv", "Geplant", "Abgelaufen") are translated as a status – and can agree in gender with the
	 * object in languages that need it – instead of sharing the entry of the same word elsewhere.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'draft'            => _x( 'Entwurf', 'status', 'pass-captain' ),
			'published'        => _x( 'Veröffentlicht', 'status', 'pass-captain' ),
			'cancelled'        => _x( 'Abgesagt/Storniert', 'status', 'pass-captain' ),
			'scheduled'        => _x( 'Geplant', 'status', 'pass-captain' ),
			'active'           => _x( 'Aktiv', 'status', 'pass-captain' ),
			'paused'           => _x( 'Pausiert', 'status', 'pass-captain' ),
			'inactive'         => _x( 'Deaktiviert', 'status', 'pass-captain' ),
			'pending_payment'  => _x( 'Zahlung offen', 'status', 'pass-captain' ),
			'complete'         => _x( 'Abgeschlossen', 'status', 'pass-captain' ),
			'refunded'         => _x( 'Erstattet', 'status', 'pass-captain' ),
			'expired'          => _x( 'Abgelaufen', 'status', 'pass-captain' ),
			'needs_review'     => _x( 'Prüfung nötig', 'status', 'pass-captain' ),
			'pending'          => _x( 'Ausstehend', 'status', 'pass-captain' ),
			'paid'             => _x( 'Bezahlt', 'status', 'pass-captain' ),
			'paid_unallocated' => _x( 'Bezahlt, ohne Kontingent', 'status', 'pass-captain' ),
			'valid'            => _x( 'Gültig', 'status', 'pass-captain' ),
			'checked_in'       => _x( 'Eingecheckt', 'status', 'pass-captain' ),
			'already_checked_in' => _x( 'Bereits eingecheckt (abgelehnt)', 'status', 'pass-captain' ),
			'not_valid_today'  => _x( 'Heute nicht gültig', 'status', 'pass-captain' ),
			'daily_limit_reached' => _x( 'Tageslimit erreicht', 'status', 'pass-captain' ),
			'revoked'          => _x( 'Widerrufen', 'status', 'pass-captain' ),
			'pending_approval' => _x( 'Wartet auf Freigabe', 'status', 'pass-captain' ),
			'approved'         => _x( 'Freigegeben', 'status', 'pass-captain' ),
			'processing'       => _x( 'In Bearbeitung', 'status', 'pass-captain' ),
			'completed'        => _x( 'Durchgeführt', 'status', 'pass-captain' ),
			'failed'           => _x( 'Fehlgeschlagen', 'status', 'pass-captain' ),
			'rejected'         => _x( 'Abgelehnt', 'status', 'pass-captain' ),
			'reversed'         => _x( 'Beim Zahlungsanbieter gescheitert, storniert', 'status', 'pass-captain' ),
			'undone'           => _x( 'Check-in zurückgenommen', 'status', 'pass-captain' ),
			'unknown'          => _x( 'Unbekannt', 'status', 'pass-captain' ),
			'outside_entry_window' => _x( 'Außerhalb des Einlasszeitraums', 'status', 'pass-captain' ),
			'entry_limit_reached' => _x( 'Einlass-Limit erreicht', 'status', 'pass-captain' ),
			'already_inside'   => _x( 'Bereits drinnen', 'status', 'pass-captain' ),
			'not_checked_in'   => _x( 'Nicht eingecheckt', 'status', 'pass-captain' ),
			'wrong_area'       => _x( 'Falscher Einlassbereich', 'status', 'pass-captain' ),
			'checked_out'      => _x( 'Ausgecheckt', 'status', 'pass-captain' ),
		);
		return $labels[ (string) $status ] ?? (string) $status;
	}

	/**
	 * A383: "Status: Gültig" (optionally "Status: Gültig (erstattet)") as one translatable sentence
	 * instead of a label glued to the value, so languages can order or punctuate it their own way.
	 *
	 * @param string $status   Status value.
	 * @param bool   $refunded Whether the ticket was refunded.
	 */
	public static function status_line( $status, $refunded = false ) {
		$label = self::status_label( $status );
		return $refunded
			/* translators: %s: ticket status (e.g. "Gültig") of a refunded ticket */
			? sprintf( __( 'Status: %s (erstattet)', 'pass-captain' ), $label )
			/* translators: %s: ticket status (e.g. "Gültig") */
			: sprintf( __( 'Status: %s', 'pass-captain' ), $label );
	}
}
