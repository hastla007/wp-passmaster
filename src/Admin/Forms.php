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
	const ZERO_DECIMAL_CURRENCIES = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/** Number of decimals of a currency's minor unit. */
	public static function currency_decimals( $currency ) {
		return in_array( strtoupper( (string) $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 0 : 2;
	}

	/**
	 * Parses a user-entered decimal amount into minor units without floating point math.
	 *
	 * Accepts "12,50", "12.50", "12", "1.234,56", "1,234.56", "1 234,56", "1'234.50", "€ 12,50", "12,5".
	 * A single separator followed by exactly three digits is read as a thousands separator
	 * ("1.234" = 1234), otherwise as the decimal separator. Negative values are rejected.
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
			if ( 1 === substr_count( $s, $sep ) && 3 !== $after ) {
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
		$decimals = self::currency_decimals( $currency );
		return number_format_i18n( (int) $minor / ( 10 ** $decimals ), $decimals ) . ' ' . strtoupper( (string) $currency );
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
				__( 'Die Ortszeit %1$s gibt es in der Zeitzone %2$s wegen der Umstellung auf Sommerzeit nicht. Bitte eine andere Zeit wählen, z. B. %3$s (erste gültige Zeit danach).', 'wp-pass-captain' ),
				$shown( $info['local'] ),
				$timezone,
				$shown( $info['next_valid'] )
			) );
		}
		if ( 'ambiguous' === $info['status'] ) {
			$notes[] = sprintf(
				/* translators: 1: entered local time, 2: timezone, 3: UTC offset used, 4: UTC offset of the second instance */
				__( 'Die Ortszeit %1$s kommt in der Zeitzone %2$s wegen der Umstellung auf Winterzeit zweimal vor. Verwendet wurde die erste (Sommerzeit, UTC%3$s), nicht die zweite (UTC%4$s).', 'wp-pass-captain' ),
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
			$notes[] = sprintf( __( 'Die Wiederholung am %1$s fällt in die Zeitumstellung (Zeitzone %2$s, diese Ortszeit gibt es nicht) und wurde auf %3$s verschoben.', 'wp-pass-captain' ), $shown, $timezone, $start->format( 'd.m.Y H:i' ) );
		} elseif ( 'ambiguous' === $info['status'] ) {
			/* translators: 1: local time of the repetition, 2: timezone, 3: UTC offset used */
			$notes[] = sprintf( __( 'Die Wiederholung am %1$s liegt in der doppelten Stunde der Zeitumstellung (Zeitzone %2$s); verwendet wurde die erste (Sommerzeit, UTC%3$s).', 'wp-pass-captain' ), $shown, $timezone, $info['offsets'][0] );
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

	/** Builds a WP_Error for admin handlers. */
	public static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Standard "no permission" error. */
	public static function forbidden( $message = '' ) {
		return self::error( 'wpcap_admin_forbidden', '' !== $message ? $message : __( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Request flow: nonce check, flash notices, redirects.
	 * ------------------------------------------------------------------------------------------- */

	/** Unslashed POST data. */
	public static function post() {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by caller.
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
		$notices[] = array( 'type' => 'error' === $type ? 'error' : ( 'warning' === $type ? 'warning' : 'success' ), 'message' => (string) $message );
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
			self::redirect( '' !== $error_url ? $error_url : $url );
		}
		if ( '' !== $success ) { self::flash( 'success', $success ); }
		if ( is_array( $result ) && ! empty( $result['warning'] ) ) { self::flash( 'warning', $result['warning'] ); }
		self::redirect( $url );
	}

	/** Safe redirect followed by exit. */
	public static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering helpers.
	 * ------------------------------------------------------------------------------------------- */

	/** URL of an admin page of the plugin. */
	public static function page_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
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

	/** Timezone select. */
	public static function timezone_select( $name, $selected, $id = '', $extra = '' ) {
		$zones = timezone_identifiers_list();
		self::select( $name, array_combine( $zones, $zones ), $selected, $id, $extra ?: 'aria-label="' . esc_attr__( 'Zeitzone', 'wp-pass-captain' ) . '"' );
	}

	/** Renders "Seite x von y" pagination links. */
	public static function pagination( $total, $page, $per_page, $base_url ) {
		$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">';
		/* translators: %s: number of entries */
		echo esc_html( sprintf( _n( '%s Eintrag', '%s Einträge', (int) $total, 'wp-pass-captain' ), number_format_i18n( (int) $total ) ) );
		echo '</span>';
		if ( $pages > 1 ) {
			echo ' <span class="pagination-links">';
			if ( $page > 1 ) {
				echo '<a class="prev-page button" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base_url ) ) . '">&lsaquo;</a> ';
			}
			/* translators: 1: current page, 2: total pages */
			echo '<span class="paging-input">' . esc_html( sprintf( __( 'Seite %1$d von %2$d', 'wp-pass-captain' ), $page, $pages ) ) . '</span>';
			if ( $page < $pages ) {
				echo ' <a class="next-page button" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base_url ) ) . '">&rsaquo;</a>';
			}
			echo '</span>';
		}
		echo '</div></div>';
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

	/** Translated labels for status values. */
	public static function status_label( $status ) {
		$labels = array(
			'draft'            => __( 'Entwurf', 'wp-pass-captain' ),
			'published'        => __( 'Veröffentlicht', 'wp-pass-captain' ),
			'cancelled'        => __( 'Abgesagt/Storniert', 'wp-pass-captain' ),
			'scheduled'        => __( 'Geplant', 'wp-pass-captain' ),
			'active'           => __( 'Aktiv', 'wp-pass-captain' ),
			'paused'           => __( 'Pausiert', 'wp-pass-captain' ),
			'inactive'         => __( 'Deaktiviert', 'wp-pass-captain' ),
			'pending_payment'  => __( 'Zahlung offen', 'wp-pass-captain' ),
			'complete'         => __( 'Abgeschlossen', 'wp-pass-captain' ),
			'refunded'         => __( 'Erstattet', 'wp-pass-captain' ),
			'expired'          => __( 'Abgelaufen', 'wp-pass-captain' ),
			'needs_review'     => __( 'Prüfung nötig', 'wp-pass-captain' ),
			'pending'          => __( 'Ausstehend', 'wp-pass-captain' ),
			'paid'             => __( 'Bezahlt', 'wp-pass-captain' ),
			'paid_unallocated' => __( 'Bezahlt, ohne Kontingent', 'wp-pass-captain' ),
			'valid'            => __( 'Gültig', 'wp-pass-captain' ),
			'checked_in'       => __( 'Eingecheckt', 'wp-pass-captain' ),
			'already_checked_in' => __( 'Bereits eingecheckt (abgelehnt)', 'wp-pass-captain' ),
			'not_valid_today'  => __( 'Heute nicht gültig', 'wp-pass-captain' ),
			'daily_limit_reached' => __( 'Tageslimit erreicht', 'wp-pass-captain' ),
			'revoked'          => __( 'Widerrufen', 'wp-pass-captain' ),
			'pending_approval' => __( 'Wartet auf Freigabe', 'wp-pass-captain' ),
			'approved'         => __( 'Freigegeben', 'wp-pass-captain' ),
			'processing'       => __( 'In Bearbeitung', 'wp-pass-captain' ),
			'completed'        => __( 'Durchgeführt', 'wp-pass-captain' ),
			'failed'           => __( 'Fehlgeschlagen', 'wp-pass-captain' ),
			'rejected'         => __( 'Abgelehnt', 'wp-pass-captain' ),
			'undone'           => __( 'Check-in zurückgenommen', 'wp-pass-captain' ),
			'unknown'          => __( 'Unbekannt', 'wp-pass-captain' ),
			'outside_entry_window' => __( 'Außerhalb des Einlasszeitraums', 'wp-pass-captain' ),
			'entry_limit_reached' => __( 'Einlass-Limit erreicht', 'wp-pass-captain' ),
			'already_inside'   => __( 'Bereits drinnen', 'wp-pass-captain' ),
			'not_checked_in'   => __( 'Nicht eingecheckt', 'wp-pass-captain' ),
			'wrong_area'       => __( 'Falscher Einlassbereich', 'wp-pass-captain' ),
			'checked_out'      => __( 'Ausgecheckt', 'wp-pass-captain' ),
		);
		return $labels[ (string) $status ] ?? (string) $status;
	}
}
