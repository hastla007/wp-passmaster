<?php
/**
 * Number patterns of the document sequences (SET-PC-03): invoices, corrections (Rechnungskorrektur),
 * box office receipts, partner settlements and self-billing credit notes (Gutschrift).
 *
 * Per kind the settings {kind}_number_pattern (placeholders {prefix}, {year}, {yy}, {number}),
 * {kind}_number_digits (zero padding of {number}), {kind}_number_start (first number of a new
 * counter and lower bound of the running one; may only be raised above the last issued number) and
 * {kind}_number_yearly (counter starts again each year; only when the pattern contains {year} or {yy},
 * otherwise the numbers would repeat). The defaults are exactly the former fixed format
 * "PREFIX-YYYY-000123", restarting every year.
 *
 * The counters stay in wpcap_invoice_sequences / wpcap_document_sequences and are reserved inside
 * the document transaction (Invoices::next_number(), Invoices::next_document_number()); a continuous
 * counter uses the row of year 0. A change of the pattern applies to the next number only: the
 * counter is never set back, so no number is issued twice. Raising the start number of a running
 * counter skips the numbers in between; the skip is recorded (audit + option) and the gap check
 * (Invoices::document_gaps()) does not report it.
 *
 * The number ranges of agent organizers (series invoice-org-N / credit-org-N) belong to the organizer
 * and keep the fixed format.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DocumentNumbers {
	/** Former fixed format (and default of every kind). */
	const DEFAULT_PATTERN = '{prefix}-{year}-{number}';
	const DEFAULT_DIGITS = 6;

	/** Configurable kinds => series in wpcap_document_sequences ('' = wpcap_invoice_sequences). */
	const KINDS = array(
		'invoice' => '',
		'credit' => 'credit',
		'receipt' => 'receipt',
		'settlement' => 'partner',
		'self_billing' => 'credit-self',
	);

	/** Placeholders of a pattern. */
	const PLACEHOLDERS = array( '{prefix}', '{year}', '{yy}', '{number}' );

	/** Highest padding (the number columns hold 40 characters). */
	const MAX_DIGITS = 10;

	/** Longest literal text of a pattern (without placeholders). */
	const MAX_LITERAL = 12;

	/** Option: skipped ranges (start number raised on a running counter) – list of {range, from, to}. */
	const SKIPS_OPTION = 'wpcap_number_skips';

	/** Option: patterns used for issued numbers per kind (gap check also parses numbers of earlier patterns). */
	const PATTERNS_OPTION = 'wpcap_number_patterns_used';

	/** Kind of a sequence series ('' for the organizer ranges and unknown series: fixed format). */
	public static function kind_of_series( $series ) {
		$kind = array_search( (string) $series, self::KINDS, true );
		return false === $kind ? '' : (string) $kind;
	}

	/**
	 * Effective configuration of a kind (defaults for '' and unknown kinds).
	 *
	 * @return array{pattern:string,digits:int,start:int,yearly:bool}
	 */
	public static function config( $kind ) {
		$kind = (string) $kind;
		if ( ! isset( self::KINDS[ $kind ] ) ) {
			return array( 'pattern' => self::DEFAULT_PATTERN, 'digits' => self::DEFAULT_DIGITS, 'start' => 0, 'yearly' => true );
		}
		$pattern = self::sanitize_pattern( Settings::get( $kind . '_number_pattern', self::DEFAULT_PATTERN ) );
		$pattern = is_wp_error( $pattern ) ? self::DEFAULT_PATTERN : $pattern;
		$digits = (int) Settings::get( $kind . '_number_digits', self::DEFAULT_DIGITS );
		return array(
			'pattern' => $pattern,
			'digits' => $digits >= 1 && $digits <= self::MAX_DIGITS ? $digits : self::DEFAULT_DIGITS,
			'start' => max( 0, (int) Settings::get( $kind . '_number_start', 0 ) ),
			// Without a year in the pattern a yearly counter would repeat numbers: it never restarts then.
			'yearly' => (bool) Settings::get( $kind . '_number_yearly', 1 ) && self::has_year( $pattern ),
		);
	}

	/** Whether the configuration of a kind is the former fixed format. */
	public static function is_default( array $config ) {
		return self::DEFAULT_PATTERN === $config['pattern'] && self::DEFAULT_DIGITS === $config['digits'];
	}

	/** Whether a pattern contains a year placeholder. */
	public static function has_year( $pattern ) {
		return false !== strpos( (string) $pattern, '{year}' ) || false !== strpos( (string) $pattern, '{yy}' );
	}

	/**
	 * Formats a reserved number (filter wp_pass_captain_invoice_number_v1).
	 *
	 * @param string $kind   Kind (see KINDS) or '' (fixed format).
	 * @param string $prefix Cleaned prefix.
	 * @param int    $year   Year of the document date (site timezone).
	 * @param int    $number Reserved counter value.
	 * @param string $series Sequence series (context for the filter).
	 */
	public static function format( $kind, $prefix, $year, $number, $series = '' ) {
		$config = self::config( $kind );
		$formatted = self::apply_pattern( $config['pattern'], $config['digits'], (string) $prefix, (int) $year, (int) $number );
		if ( '' !== (string) $kind && ! self::is_default( $config ) ) { self::remember_pattern( (string) $kind, $config ); }
		/**
		 * Document number before it is stored (SET-PC-03). The number must stay unique within its document
		 * type (a duplicate makes the document fail and rolls the reservation back) and should keep the
		 * configured pattern, otherwise the gap check cannot read it. Allowed characters: A–Z, a–z, 0–9,
		 * "-", "_", "." and "/", at most 40.
		 *
		 * @param string $formatted Formatted number.
		 * @param array  $context   kind (invoice|credit|receipt|settlement|self_billing|'' for organizer
		 *                          ranges), series, prefix, year, number (counter value), pattern, digits.
		 */
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'wp_pass_captain_invoice_number_v1', $formatted, array( 'kind' => (string) $kind, 'series' => (string) $series, 'prefix' => (string) $prefix, 'year' => (int) $year, 'number' => (int) $number, 'pattern' => $config['pattern'], 'digits' => $config['digits'] ) ) : $formatted;
		$filtered = is_scalar( $filtered ) ? substr( (string) preg_replace( '#[^A-Za-z0-9_.\-/]#', '', (string) $filtered ), 0, 40 ) : '';
		return '' !== $filtered ? $filtered : $formatted;
	}

	/** Pattern with its placeholders replaced (no filter). */
	public static function apply_pattern( $pattern, $digits, $prefix, $year, $number ) {
		return strtr(
			(string) $pattern,
			array(
				'{prefix}' => (string) $prefix,
				'{year}' => sprintf( '%04d', (int) $year ),
				'{yy}' => sprintf( '%02d', (int) $year % 100 ),
				'{number}' => str_pad( (string) (int) $number, max( 1, (int) $digits ), '0', STR_PAD_LEFT ),
			)
		);
	}

	/**
	 * Checks a pattern (settings register): {number} exactly once, every placeholder at most once, no
	 * unknown placeholder, literal text only A–Z, a–z, 0–9, "-", "_" and "." (at most 12 characters).
	 * Empty = the default pattern.
	 *
	 * @return string|\WP_Error
	 */
	public static function sanitize_pattern( $value ) {
		$pattern = trim( is_scalar( $value ) ? (string) $value : '' );
		if ( '' === $pattern ) { return self::DEFAULT_PATTERN; }
		$literal = $pattern;
		foreach ( self::PLACEHOLDERS as $placeholder ) {
			if ( substr_count( $pattern, $placeholder ) > 1 ) {
				/* translators: %s: placeholder, e.g. {year} */
				return new \WP_Error( 'wpcap_number_pattern', sprintf( __( 'Das Nummernmuster darf %s nur einmal enthalten.', 'pass-captain' ), $placeholder ) );
			}
			$literal = str_replace( $placeholder, '', $literal );
		}
		if ( 1 !== substr_count( $pattern, '{number}' ) ) {
			return new \WP_Error( 'wpcap_number_pattern', __( 'Das Nummernmuster muss den Platzhalter {number} enthalten.', 'pass-captain' ) );
		}
		if ( preg_match( '/[{}]/', $literal ) ) {
			return new \WP_Error( 'wpcap_number_pattern', __( 'Das Nummernmuster enthält einen unbekannten Platzhalter. Erlaubt sind {prefix}, {year}, {yy} und {number}.', 'pass-captain' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.\-]*$/', $literal ) || strlen( $literal ) > self::MAX_LITERAL ) {
			return new \WP_Error( 'wpcap_number_pattern', __( 'Außer den Platzhaltern darf das Nummernmuster nur Buchstaben, Ziffern, „-“, „_“ und „.“ enthalten (höchstens 12 Zeichen).', 'pass-captain' ) );
		}
		return $pattern;
	}

	/**
	 * Checks a new start number (settings register): it may only be raised above the last number issued by
	 * the running counter of the kind; 0 switches the lower bound off. Unchanged values always pass.
	 *
	 * @param string $kind    Kind.
	 * @param mixed  $value   Checked integer.
	 * @param mixed  $current Stored value.
	 * @return int|\WP_Error
	 */
	public static function sanitize_start( $kind, $value, $current = null ) {
		$value = (int) $value;
		if ( 0 === $value || ( null !== $current && (int) $current === $value ) ) { return $value; }
		$last = self::last_issued( $kind );
		if ( $last > 0 && $value <= $last ) {
			/* translators: %d: last issued number */
			return new \WP_Error( 'wpcap_number_start', sprintf( __( 'Die Startnummer muss größer sein als die zuletzt vergebene Nummer (%d). Nummern werden nie zurückgesetzt.', 'pass-captain' ), $last ) );
		}
		return $value;
	}

	/** Prefix of a kind as the documents use it. */
	public static function prefix_of( $kind ) {
		$clean = static function ( $prefix, $fallback ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $prefix ) ?: $fallback; };
		$partners = (array) Settings::get( 'partners', array() );
		switch ( (string) $kind ) {
			case 'invoice':
				return $clean( Settings::get( 'invoice_prefix' ), 'WPC' );
			case 'credit':
				return $clean( (string) Settings::get( 'credit_note_prefix' ) ?: Invoices::CREDIT_PREFIX_DEFAULT, 'DOC' );
			case 'receipt':
				return $clean( Settings::get( 'boxoffice_receipt_prefix', 'BON' ), 'BON' );
			case 'settlement':
				return $clean( '' !== (string) ( $partners['settlement_prefix'] ?? '' ) ? $partners['settlement_prefix'] : 'PA', 'DOC' );
			case 'self_billing':
				return $clean( '' !== (string) ( $partners['self_billing_prefix'] ?? '' ) ? $partners['self_billing_prefix'] : 'GU', 'DOC' );
		}
		return 'DOC';
	}

	/** Table and series of a kind. */
	private static function storage( $kind ) {
		global $wpdb;
		$series = self::KINDS[ (string) $kind ] ?? (string) $kind;
		return '' === $series ? array( $wpdb->prefix . 'wpcap_invoice_sequences', null ) : array( $wpdb->prefix . 'wpcap_document_sequences', $series );
	}

	/**
	 * Reserves the next counter value of a sequence (caller has started the transaction). Applies the
	 * yearly/continuous counter, the start number and the hand-over between both counter rows.
	 *
	 * @param string      $table  Sequence table.
	 * @param string|null $series Series (null = wpcap_invoice_sequences, keyed by year only).
	 * @param int         $year   Year of the document date.
	 * @param array       $config See config().
	 * @return int|false Counter value, false on any database failure.
	 */
	public static function reserve( $table, $series, $year, array $config ) {
		global $wpdb;
		$counter = $config['yearly'] ? (int) $year : 0;
		$where = null === $series ? $wpdb->prepare( 'year=%d', $counter ) : $wpdb->prepare( 'series=%s AND year=%d', $series, $counter );
		$initial = max( 1, (int) $config['start'] );
		if ( 0 === $counter ) {
			// Continuous counter: never below any counter value this series ever reached (no number twice).
			$highest = null === $series ? $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(next_number) FROM %i', $table ) ) : $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(next_number) FROM %i WHERE series=%s', $table, $series ) );
			$initial = max( $initial, (int) $highest );
		}
		$inserted = null === $series
			? $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (year,next_number) VALUES (%d,%d)', $table, $counter, $initial ) )
			: $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (series,year,next_number) VALUES (%s,%d,%d)', $table, $series, $counter, $initial ) );
		if ( false === $inserted ) { return false; }
		$row = $wpdb->get_row( "SELECT next_number FROM {$table} WHERE {$where} FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a prepared condition.
		if ( ! $row ) { return false; }
		$locked = (int) $row->next_number;
		$number = $locked;
		if ( 0 !== $counter ) {
			// Back from a continuous counter: continue above it this year, then drop it (plain read first,
			// so the usual case takes no lock on a missing row).
			$continuous = null === $series ? $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE year=0', $table ) ) : $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE series=%s AND year=0', $table, $series ) );
			if ( null !== $continuous ) {
				$continuous = null === $series ? $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE year=0 FOR UPDATE', $table ) ) : $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE series=%s AND year=0 FOR UPDATE', $table, $series ) );
				$number = max( $number, (int) $continuous );
				$deleted = null === $series ? $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE year=0', $table ) ) : $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE series=%s AND year=0', $table, $series ) );
				if ( false === $deleted ) { return false; }
			}
		}
		$skipped_from = 0;
		if ( (int) $config['start'] > $number ) {
			// Start number raised on a running counter: the numbers in between are skipped on purpose.
			if ( $number > 1 ) { $skipped_from = $number; }
			$number = (int) $config['start'];
		}
		// Guarded by the locked value: a number handed out without advancing the counter would be issued twice.
		$updated = $wpdb->query( "UPDATE {$table} SET next_number=" . (int) ( $number + 1 ) . " WHERE {$where} AND next_number=" . (int) $locked ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name, a prepared condition and integers.
		if ( 1 !== (int) $updated ) { return false; }
		if ( 0 === $counter && (int) $year > 0 ) {
			// The continuous number carries the year of its date: a yearly row of that year must never hand it
			// out again after a switch back to yearly counters.
			$synced = null === $series
				? $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_number=GREATEST(next_number,%d) WHERE year=%d', $table, $number + 1, (int) $year ) )
				: $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_number=GREATEST(next_number,%d) WHERE series=%s AND year=%d', $table, $number + 1, $series, (int) $year ) );
			if ( false === $synced ) { return false; }
		}
		if ( $skipped_from ) { self::$pending_skip = array( $skipped_from, $number - 1 ); }
		return $number;
	}

	/** @var array{0:int,1:int}|null Skip noticed by the last reserve() (recorded once the number is formatted). */
	private static $pending_skip = null;

	/**
	 * Records a skip noticed by reserve() for the formatted number (called by the sequences after
	 * formatting). The gap check leaves these numbers out; the audit log keeps the reason.
	 */
	public static function record_pending_skip( $kind, $formatted ) {
		if ( null === self::$pending_skip ) { return; }
		list( $from, $to ) = self::$pending_skip;
		self::$pending_skip = null;
		$parsed = self::parse( (string) $formatted );
		if ( null === $parsed ) { return; }
		$skips = get_option( self::SKIPS_OPTION, array() );
		$skips = is_array( $skips ) ? $skips : array();
		$skips[] = array( 'range' => $parsed['range'], 'from' => (int) $from, 'to' => (int) $to );
		update_option( self::SKIPS_OPTION, array_slice( $skips, -200 ), false );
		Audit::record( 'document_numbers_skipped', 'settings', 0, array( 'kind' => (string) $kind, 'range' => $parsed['range'], 'from' => (int) $from, 'to' => (int) $to ) );
	}

	/** Skipped numbers of a range (gap check). */
	public static function skipped( $range, $number ) {
		foreach ( (array) get_option( self::SKIPS_OPTION, array() ) as $skip ) {
			if ( is_array( $skip ) && (string) ( $skip['range'] ?? '' ) === (string) $range && $number >= (int) ( $skip['from'] ?? 0 ) && $number <= (int) ( $skip['to'] ?? -1 ) ) { return true; }
		}
		return false;
	}

	/** Remembers a non-default pattern that issued numbers (the gap check parses it later too). */
	private static function remember_pattern( $kind, array $config ) {
		$used = get_option( self::PATTERNS_OPTION, array() );
		$used = is_array( $used ) ? $used : array();
		$entry = $config['pattern'] . '|' . $config['digits'];
		if ( in_array( $entry, (array) ( $used[ $kind ] ?? array() ), true ) ) { return; }
		$used[ $kind ][] = $entry;
		update_option( self::PATTERNS_OPTION, $used, false );
	}

	/**
	 * Reads a document number: the former format "PREFIX-YYYY-000123" or a pattern that issued numbers.
	 *
	 * @return array{range:string,number:int,year:int,digits:int,template:string}|null template holds "%s"
	 *         where the zero-padded number goes; year 0 when the pattern has none.
	 */
	public static function parse( $number ) {
		$number = (string) $number;
		// The former format first (it is what almost every install issues), then the patterns that issued numbers.
		if ( preg_match( '/^(.+)-(\d{4})-(\d{6,})$/', $number, $m ) ) {
			return array( 'range' => $m[1] . '-' . $m[2], 'number' => (int) $m[3], 'year' => (int) $m[2], 'digits' => self::DEFAULT_DIGITS, 'template' => $m[1] . '-' . $m[2] . '-%s' );
		}
		$patterns = array();
		foreach ( (array) get_option( self::PATTERNS_OPTION, array() ) as $entries ) {
			foreach ( (array) $entries as $entry ) { $patterns[] = (string) $entry; }
		}
		foreach ( array_unique( $patterns ) as $entry ) {
			list( $pattern, $digits ) = array_pad( explode( '|', $entry, 2 ), 2, self::DEFAULT_DIGITS );
			$parsed = self::parse_with( $number, $pattern, (int) $digits );
			if ( null !== $parsed ) { return $parsed; }
		}
		return null;
	}

	/** parse() with one pattern. */
	private static function parse_with( $number, $pattern, $digits ) {
		if ( self::DEFAULT_PATTERN === $pattern && self::DEFAULT_DIGITS === $digits ) { return null; } // Read by the former rule.
		$regex = '';
		$groups = array();
		foreach ( preg_split( '/(\{prefix\}|\{year\}|\{yy\}|\{number\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $part ) {
			$map = array( '{prefix}' => '([A-Za-z0-9_-]*?)', '{year}' => '(\d{4})', '{yy}' => '(\d{2})', '{number}' => '(\d{' . max( 1, (int) $digits ) . ',})' );
			if ( isset( $map[ $part ] ) ) {
				$regex .= $map[ $part ];
				$groups[] = $part;
			} else {
				$regex .= preg_quote( $part, '/' );
			}
		}
		if ( ! preg_match( '/^' . $regex . '$/', $number, $m ) ) { return null; }
		$values = array_combine( $groups, array_slice( $m, 1, count( $groups ) ) );
		$year = isset( $values['{year}'] ) ? (int) $values['{year}'] : ( isset( $values['{yy}'] ) ? 2000 + (int) $values['{yy}'] : 0 );
		$template = strtr( $pattern, array( '{prefix}' => (string) ( $values['{prefix}'] ?? '' ), '{year}' => (string) ( $values['{year}'] ?? '' ), '{yy}' => (string) ( $values['{yy}'] ?? '' ), '{number}' => '%s' ) );
		return array( 'range' => str_replace( '%s', '#', $template ), 'number' => (int) $values['{number}'], 'year' => $year, 'digits' => (int) $digits, 'template' => $template );
	}

	/** Last counter value issued by the running counter of a kind (0 = none yet). */
	public static function last_issued( $kind ) {
		return max( 0, self::peek_counter( $kind ) - 1 );
	}

	/** Counter value the next document of a kind would get (no reservation). */
	public static function peek_counter( $kind, $year = 0 ) {
		global $wpdb;
		if ( ! isset( self::KINDS[ (string) $kind ] ) ) { return 1; }
		$config = self::config( $kind );
		$year = $year ? (int) $year : Invoices::local_year();
		list( $table, $series ) = self::storage( $kind );
		$counter = $config['yearly'] ? $year : 0;
		$value = null === $series ? $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE year=%d', $table, $counter ) ) : $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE series=%s AND year=%d', $table, $series, $counter ) );
		if ( null === $value && 0 === $counter ) {
			$value = null === $series ? $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(next_number) FROM %i', $table ) ) : $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(next_number) FROM %i WHERE series=%s', $table, $series ) );
		}
		$next = max( 1, (int) $value );
		if ( 0 !== $counter ) {
			$continuous = null === $series ? $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE year=0', $table ) ) : $wpdb->get_var( $wpdb->prepare( 'SELECT next_number FROM %i WHERE series=%s AND year=0', $table, $series ) );
			if ( null !== $continuous ) { $next = max( $next, (int) $continuous ); }
		}
		return max( $next, (int) $config['start'] );
	}

	/** Next number of a kind as it would be issued now (settings screen, tests; no reservation, no filter). */
	public static function preview( $kind ) {
		$config = self::config( $kind );
		return self::apply_pattern( $config['pattern'], $config['digits'], self::prefix_of( $kind ), Invoices::local_year(), self::peek_counter( $kind ) );
	}

	/** Labels of the kinds (settings screen). */
	public static function labels() {
		return array(
			'invoice' => __( 'Rechnungen', 'pass-captain' ),
			'credit' => __( 'Rechnungskorrekturen', 'pass-captain' ),
			'receipt' => __( 'Kassenbons', 'pass-captain' ),
			'settlement' => __( 'Partner-Abrechnungen', 'pass-captain' ),
			'self_billing' => __( 'Gutschriften (Partner)', 'pass-captain' ),
		);
	}
}
