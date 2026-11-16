<?php
/**
 * Cache for report and aggregated figures with generation keys (A201).
 *
 * Every cached report lives under a key that contains the table prefix (site), the report name, its
 * parameters and two generation tokens: the site generation plus the event generation (event reports)
 * or the "all events" generation (site-wide reports). A change bumps the generation (a new random
 * token), so the old entries are never read again; the TTL (filter `wpcap_report_cache_ttl`, default
 * 300 s, 0 = no caching) is only a safety net.
 *
 * Invalidation does not rely on callers: the `query` filter sees every write to the report tables
 * (events, occurrences, ticket types, orders, order lines, refunds, check-ins, tickets, invoices,
 * credit notes), resolves the ids in the statement (event_id, occurrence_id, ticket_type_id,
 * order_id, item_id, ticket_id, refund_id, invoice_id, checkin_id, credit_note_id) to event ids before
 * it runs (so deleted rows and moved ticket types still map to their old event) and bumps those
 * events after the write is committed: after COMMIT/ROLLBACK, after an autocommit write, before a cached
 * read and at shutdown. A write whose rows cannot be determined (no id in the statement, sub-select,
 * OR) bumps the site generation. A bare INSERT into orders, invoices or events carries no report figure
 * yet (order lines, credit notes, occurrences do) and bumps nothing.
 *
 * Other writers (own tables, external imports) call invalidate_event()/invalidate_site() or fire
 * the action `wpcap_report_cache_invalidate` (event id, 0 = site).
 *
 * Earlier API (kept for callers of the other development line): get()/set() for a report of an
 * event and date range (event id 0 = admin report page of all events) and flush_event()/flush_order()/
 * flush_refund()/flush_ticket()/flush_occurrence(), which invalidate through the generations.
 */
namespace PassCaptain\Reports;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cache {
	/** Default lifetime of a cached report (seconds; safety net only). */
	const TTL = 300;
	/** Upper bound for the TTL filter. */
	const MAX_TTL = 900;
	/** Lifetime of a generation token (an expired token only causes misses). */
	const GENERATION_TTL = DAY_IN_SECONDS;

	/** Tables whose writes change report figures (without prefix and "wpcap_"). */
	const WATCHED = array( 'events', 'occurrences', 'ticket_types', 'orders', 'order_items', 'refunds', 'checkins', 'tickets', 'credit_notes', 'invoices' );
	/** A bare INSERT into these tables carries no report figure yet. */
	const INERT_INSERTS = array( 'events', 'orders', 'invoices' );
	/** Id columns that are resolved to events. */
	const KINDS = array( 'event_id', 'occurrence_id', 'ticket_type_id', 'order_id', 'item_id', 'ticket_id', 'refund_id', 'invoice_id', 'checkin_id', 'credit_note_id' );

	/** Pending bumps: blog id => [event id (0 = site) => true]. */
	private static $pending = array();
	/** Whether pending bumps are due (outside a transaction) before the next statement. */
	private static $flush_due = false;
	/** Whether a transaction is open (seen through the query filter). */
	private static $in_tx = false;
	/** Reentrancy guard: our own statements are not inspected. */
	private static $busy = false;
	/** Resolution memo: prefix|kind|id => int[] event ids. */
	private static $memo = array();
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_filter( 'query', array( __CLASS__, 'note_query' ), 5 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1, 0 );
		add_action( 'wpcap_report_cache_invalidate', array( __CLASS__, 'invalidate_event' ), 10, 1 );
	}

	/** Lifetime of cached reports in seconds (0 = caching off). */
	public static function ttl() {
		return max( 0, min( self::MAX_TTL, (int) apply_filters( 'wpcap_report_cache_ttl', self::TTL ) ) );
	}

	/**
	 * Cached value of a report: computed by $compute on a miss and stored for ttl().
	 *
	 * @param string   $name     Report name (e.g. "rest_event_report").
	 * @param int      $event_id Event id; 0 = site-wide report (invalidated by every event change).
	 * @param array    $params   Parameters that select the figures (date range, filters, scope).
	 * @param callable $compute  Returns the report (any serialisable value; WP_Error is not cached).
	 */
	public static function remember( $name, $event_id, array $params, callable $compute ) {
		if ( ! self::$in_tx ) { self::flush(); }
		$ttl = self::ttl();
		if ( ! $ttl ) { return $compute(); }
		$key = self::key( $name, $event_id, $params );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'v', $cached ) ) { return $cached['v']; }
		$value = $compute();
		if ( ! is_wp_error( $value ) ) { set_transient( $key, array( 'v' => $value ), $ttl ); }
		return $value;
	}

	/**
	 * Transient name of a cached report: site (table prefix) + report + event + generations + params.
	 */
	public static function key( $name, $event_id, array $params = array() ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$scope = $event_id ? self::generation( $event_id ) : self::generation( -1 );
		return 'wpcap_rep_' . md5( wp_json_encode( array( (string) $wpdb->prefix, self::blog_id(), (string) $name, $event_id, self::generation( 0 ), $scope, $params ) ) );
	}

	/**
	 * Current generation token: 0 = site, -1 = all events (site-wide reports), >0 = event.
	 */
	public static function generation( $scope ) {
		$name = self::generation_name( $scope );
		$token = get_transient( $name );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = self::new_token();
			set_transient( $name, $token, self::GENERATION_TTL );
		}
		return $token;
	}

	/** Invalidates all cached reports of an event (and site-wide reports); 0 = whole site. */
	public static function invalidate_event( $event_id ) {
		self::$pending[ self::blog_id() ][ absint( $event_id ) ] = true;
		if ( ! self::$in_tx ) { self::flush(); } else { self::$flush_due = true; }
	}

	/* ------------------------------------------------------------------ earlier API */

	/** Lifetime of a cached report of the earlier API (seconds); see ttl(). */
	const CACHE_SECONDS = self::TTL;

	/** Cached report of an event and date range (event 0 = all events of the admin page) or false. */
	public static function get( $event_id, $from, $to ) {
		if ( ! self::$in_tx ) { self::flush(); }
		if ( ! self::ttl() ) { return false; }
		$cached = get_transient( self::key( 'report_range', $event_id, array( (string) $from, (string) $to ) ) );
		return is_array( $cached ) && array_key_exists( 'v', $cached ) ? $cached['v'] : false;
	}

	/** Stores a report of an event and date range for ttl(). */
	public static function set( $event_id, $from, $to, $data ) {
		$ttl = self::ttl();
		if ( ! $ttl || is_wp_error( $data ) ) { return false; }
		return set_transient( self::key( 'report_range', $event_id, array( (string) $from, (string) $to ) ), array( 'v' => $data ), $ttl );
	}

	/**
	 * Invalidates the cached reports of an event (and the site-wide ones). Returns the number of dropped
	 * entries of the earlier index-based cache: always 0, generations make the entries unreachable
	 * without touching them.
	 */
	public static function flush_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( $event_id ) { self::invalidate_event( $event_id ); }
		return 0;
	}

	public static function flush_order( $order_id ) {
		foreach ( Scope::order_events( $order_id ) as $event_id ) { self::flush_event( $event_id ); }
	}

	public static function flush_refund( $refund_id, $order_id = 0 ) {
		self::flush_order( $order_id );
	}

	public static function flush_ticket( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::flush_event( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $ticket_id ) ) ) );
	}

	public static function flush_occurrence( $occurrence_id ) {
		self::flush_event( Scope::occurrence_event( $occurrence_id ) );
	}

	/** Invalidates all cached reports of the site. */
	public static function invalidate_site() {
		self::invalidate_event( 0 );
	}

	/** Pending bumps (tests, diagnostics): blog id => event ids. */
	public static function pending() {
		return array_map( 'array_keys', self::$pending );
	}

	/** Bumps the pending generations now (also at shutdown, before a cached read and after COMMIT). */
	public static function flush() {
		self::$flush_due = false;
		if ( ! self::$pending || self::$busy ) { return; }
		$busy = self::$busy;
		self::$busy = true;
		$state = self::snapshot();
		$pending = self::$pending;
		self::$pending = array();
		try {
			$current = self::blog_id();
			foreach ( $pending as $blog_id => $events ) {
				$switched = $blog_id !== $current && function_exists( 'switch_to_blog' );
				if ( $switched ) { switch_to_blog( $blog_id ); }
				if ( isset( $events[0] ) ) {
					self::bump( 0 );
				} else {
					foreach ( array_keys( $events ) as $event_id ) { self::bump( $event_id ); }
				}
				self::bump( -1 );
				if ( $switched ) { restore_current_blog(); }
			}
		} finally {
			self::restore( $state );
			self::$busy = $busy;
		}
	}

	/**
	 * Filter `query`: tracks transactions and notes writes to the report tables.
	 *
	 * @param string $query SQL statement.
	 * @return string Unchanged statement.
	 */
	public static function note_query( $query ) {
		if ( self::$busy || ! is_string( $query ) ) { return $query; }
		if ( self::$flush_due && ! self::$in_tx ) { self::flush(); }
		global $wpdb;
		$in_tx = self::$in_tx;
		$wpdb->last_error = ''; // wpdb::query() resets it after the filter anyway; here it shows failed lookups.
		self::$busy = true;
		try {
			self::inspect( $query );
		} finally {
			self::$busy = false;
		}
		// One of our lookups failed inside a transaction (lost connection, deadlock victim): the transaction
		// may be gone, so the caller's write must not run on its own (autocommit after a reconnect). An
		// empty statement makes wpdb::query() return false; the lookup's error stays in last_error.
		if ( $in_tx && '' !== (string) $wpdb->last_error ) { return ''; }
		return $query;
	}

	/** Statement analysis behind note_query(). */
	private static function inspect( $query ) {
		$q = ltrim( $query );
		if ( preg_match( '/^(?:START\s+TRANSACTION|BEGIN)\b/i', $q ) ) {
			// A nested START commits the open transaction implicitly.
			if ( self::$in_tx && self::$pending ) { self::$flush_due = true; }
			self::$in_tx = true;
			return;
		}
		if ( preg_match( '/^(?:COMMIT|ROLLBACK)\s*;?\s*$/i', $q ) ) {
			self::$in_tx = false;
			if ( self::$pending ) { self::$flush_due = true; }
			return;
		}
		if ( ! preg_match( '/^(INSERT|REPLACE|UPDATE|DELETE)\b/i', $q, $verb ) ) { return; }
		global $wpdb;
		$prefix = (string) $wpdb->prefix . 'wpcap_';
		if ( false === stripos( $q, $prefix ) ) { return; }
		$verb = strtoupper( $verb[1] );
		$targets = array_values( array_intersect( self::targets( $verb, $q, $prefix ), self::WATCHED ) );
		if ( ! $targets ) { return; }
		$insert = 'INSERT' === $verb || 'REPLACE' === $verb;
		$ids = $insert ? self::insert_ids( $q ) : self::where_ids( $q );
		if ( $insert && ! array_diff( $targets, self::INERT_INSERTS ) && ( ! is_array( $ids ) || ! $ids ) ) { return; }
		$events = is_array( $ids ) && $ids ? self::resolve( $ids ) : null;
		$blog = self::blog_id();
		if ( null === $events ) {
			self::$pending[ $blog ][0] = true;
		} else {
			foreach ( $events as $event_id ) { self::$pending[ $blog ][ $event_id ] = true; }
		}
		// Mappings may change with this statement: resolve fresh afterwards.
		if ( array_intersect( $targets, array( 'occurrences', 'ticket_types', 'order_items', 'tickets', 'refunds', 'invoices', 'credit_notes', 'checkins' ) ) ) { self::$memo = array(); }
		if ( ! self::$in_tx && ! empty( self::$pending[ $blog ] ) ) { self::$flush_due = true; }
	}

	/** Short names of the tables a write statement changes. */
	private static function targets( $verb, $q, $prefix ) {
		if ( 'INSERT' === $verb || 'REPLACE' === $verb ) {
			$segment = preg_match( '/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?(`?[A-Za-z0-9_]+`?)/i', $q, $m ) ? $m[1] : '';
		} elseif ( 'UPDATE' === $verb ) {
			list( $segment, $set ) = array_pad( preg_split( '/\bSET\b/i', $q, 2 ), 2, '' );
			// Multi-table UPDATE … JOIN: only the tables whose alias is assigned in SET change; joined
			// tables that are only read (e.g. tickets in a seating release) are no targets.
			if ( preg_match_all( '/`?' . preg_quote( $prefix, '/' ) . '([A-Za-z0-9_]+)`?\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*)/i', $segment, $aliased, PREG_SET_ORDER ) && preg_match_all( '/(?:^|,)\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\.`?[A-Za-z0-9_]+`?\s*=/', preg_split( '/\bWHERE\b/i', $set, 2 )[0], $assigned ) ) {
				$map = array();
				foreach ( $aliased as $a ) {
					if ( ! in_array( strtoupper( $a[2] ), array( 'INNER', 'LEFT', 'RIGHT', 'JOIN', 'CROSS', 'ON', 'STRAIGHT_JOIN' ), true ) ) { $map[ strtolower( $a[2] ) ] = $a[1]; }
				}
				$changed = array();
				foreach ( array_unique( array_map( 'strtolower', $assigned[1] ) ) as $alias ) {
					if ( ! isset( $map[ $alias ] ) ) { $changed = null; break; }
					$changed[] = $map[ $alias ];
				}
				if ( $changed ) { return array_values( array_unique( $changed ) ); }
			}
		} else {
			$segment = preg_split( '/\bWHERE\b/i', $q, 2 )[0];
		}
		preg_match_all( '/`?' . preg_quote( $prefix, '/' ) . '([A-Za-z0-9_]+)`?/', $segment, $all );
		return array_values( array_unique( $all[1] ) );
	}

	/**
	 * Ids of an UPDATE/DELETE (SET and WHERE): kind => int[]; null when the rows cannot be determined.
	 */
	private static function where_ids( $q ) {
		$ids = self::pairs( $q );
		$where = preg_split( '/\bWHERE\b/i', $q, 2 );
		if ( ! $ids || ! isset( $where[1] ) || preg_match( '/\bSELECT\b|\bOR\b/i', $where[1] ) ) { return null; }
		return $ids;
	}

	/** "kind = N" and "kind IN (N, …)" pairs of a statement. */
	private static function pairs( $sql ) {
		$ids = array();
		$kinds = implode( '|', self::KINDS );
		if ( preg_match_all( '/(?<![A-Za-z0-9_])`?(' . $kinds . ')`?\s*(?:=\s*\'?(\d+)\'?|IN\s*\(\s*([\d\s,\']+)\))/i', $sql, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$kind = strtolower( $match[1] );
				$values = isset( $match[3] ) && '' !== $match[3] ? preg_split( '/[\s,\']+/', $match[3], -1, PREG_SPLIT_NO_EMPTY ) : array( $match[2] );
				foreach ( $values as $v ) { $ids[ $kind ][] = (int) $v; }
			}
		}
		return $ids;
	}

	/**
	 * Ids of an INSERT/REPLACE … (cols) VALUES (…), (…): kind => int[]; null when not parseable
	 * (INSERT … SELECT, unusual syntax).
	 */
	private static function insert_ids( $q ) {
		if ( preg_match( '/\bSELECT\b/i', $q ) ) { return null; }
		if ( ! preg_match( '/\(([^()]*)\)\s*VALUES?\s*/i', $q, $m, PREG_OFFSET_CAPTURE ) ) {
			// INSERT … SET col = val.
			return self::pairs( $q ) ?: null;
		}
		$columns = array_map( static function ( $c ) { return strtolower( trim( $c, " \t\n\r`" ) ); }, explode( ',', $m[1][0] ) );
		$tuples = self::tuples( substr( $q, $m[0][1] + strlen( $m[0][0] ) ) );
		if ( null === $tuples ) { return null; }
		$ids = array();
		foreach ( $tuples as $values ) {
			if ( count( $values ) !== count( $columns ) ) { return null; }
			foreach ( $columns as $i => $column ) {
				if ( in_array( $column, self::KINDS, true ) && preg_match( '/^\'?(\d+)\'?$/', $values[ $i ], $v ) ) { $ids[ $column ][] = (int) $v[1]; }
			}
		}
		return $ids;
	}

	/** Value tuples "(a, 'b'), (c, d)" as lists of raw tokens; null on a parse error. */
	private static function tuples( $sql ) {
		$out = array();
		$len = strlen( $sql );
		$i = 0;
		while ( $i < $len ) {
			while ( $i < $len && ( ctype_space( $sql[ $i ] ) || ',' === $sql[ $i ] ) ) { ++$i; }
			if ( $i >= $len || '(' !== $sql[ $i ] ) { break; }
			++$i;
			$values = array();
			$token = '';
			$depth = 0;
			while ( true ) {
				if ( $i >= $len ) { return null; }
				$c = $sql[ $i ];
				if ( '\'' === $c || '"' === $c ) {
					$quote = $c;
					$token .= $c;
					++$i;
					while ( $i < $len ) {
						if ( '\\' === $sql[ $i ] ) { $token .= substr( $sql, $i, 2 ); $i += 2; continue; }
						if ( $quote === $sql[ $i ] ) {
							if ( $i + 1 < $len && $quote === $sql[ $i + 1 ] ) { $token .= $quote . $quote; $i += 2; continue; }
							break;
						}
						$token .= $sql[ $i ];
						++$i;
					}
					if ( $i >= $len ) { return null; }
					$token .= $quote;
					++$i;
					continue;
				}
				if ( '(' === $c ) { ++$depth; }
				if ( ')' === $c && $depth > 0 ) { --$depth; $token .= $c; ++$i; continue; }
				if ( ')' === $c || ( ',' === $c && 0 === $depth ) ) {
					$values[] = trim( $token );
					$token = '';
					++$i;
					if ( ')' === $c ) { break; }
					continue;
				}
				$token .= $c;
				++$i;
			}
			$out[] = $values;
		}
		return $out ? $out : null;
	}

	/**
	 * Event ids of the given ids (memoised per request until a mapping table is written).
	 *
	 * @param array $ids kind => int[].
	 * @return int[]|null Null when a lookup failed (rows undeterminable).
	 */
	private static function resolve( array $ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$events = array();
		// Bulk statements (e.g. Orders::revoke_ids()) name many tickets: one query for all of them.
		$missing = array();
		foreach ( array_unique( array_filter( array_map( 'absint', (array) ( $ids['ticket_id'] ?? array() ) ) ) ) as $id ) {
			if ( ! isset( self::$memo[ $p . '|ticket_id|' . $id ] ) ) { $missing[] = $id; }
		}
		foreach ( count( $missing ) > 1 ? array_chunk( $missing, 500 ) : array() as $chunk ) {
			foreach ( $chunk as $id ) { self::$memo[ $p . '|ticket_id|' . $id ] = array(); }
			$rows = (array) $wpdb->get_results( "SELECT t.ticket_id,o.event_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id IN (" . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			if ( '' !== (string) $wpdb->last_error ) {
				foreach ( $chunk as $id ) { unset( self::$memo[ $p . '|ticket_id|' . $id ] ); }
				return null;
			}
			foreach ( $rows as $row ) {
				self::$memo[ $p . '|ticket_id|' . (int) $row->ticket_id ][] = (int) $row->event_id;
			}
		}
		foreach ( $ids as $kind => $values ) {
			foreach ( array_unique( array_filter( array_map( 'absint', $values ) ) ) as $id ) {
				if ( 'event_id' === $kind ) { $events[] = $id; continue; }
				$memo = $p . '|' . $kind . '|' . $id;
				if ( ! isset( self::$memo[ $memo ] ) ) {
					switch ( $kind ) {
						case 'occurrence_id':
							$found = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $id ) );
							break;
						case 'ticket_type_id':
							$found = $wpdb->get_col( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $id ) );
							break;
						case 'order_id':
							$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", $id ) );
							break;
						case 'item_id':
							$found = $wpdb->get_col( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.item_id=%d", $id ) );
							break;
						case 'ticket_id':
							$found = $wpdb->get_col( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", $id ) );
							break;
						case 'checkin_id':
							$found = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_checkins WHERE checkin_id=%d", $id ) );
							break;
						case 'refund_id':
							$found = self::order_events( $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_refunds WHERE refund_id=%d", $id ) ) );
							break;
						case 'invoice_id':
							$found = self::order_events( $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_invoices WHERE invoice_id=%d", $id ) ) );
							break;
						case 'credit_note_id':
							$found = self::order_events( $wpdb->get_col( $wpdb->prepare( "SELECT i.order_id FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id WHERE c.credit_note_id=%d", $id ) ) );
							break;
						default:
							$found = array();
					}
					// A failed lookup is no mapping: the caller treats the rows as undeterminable (site bump).
					if ( null === $found || '' !== (string) $wpdb->last_error ) { return null; }
					self::$memo[ $memo ] = array_map( 'intval', (array) $found );
				}
				$events = array_merge( $events, self::$memo[ $memo ] );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $events ) ) ) );
	}

	/** Event ids of orders (via their lines); null when a lookup failed. */
	private static function order_events( array $order_ids ) {
		$events = array();
		foreach ( $order_ids as $order_id ) {
			$found = self::resolve( array( 'order_id' => array( (int) $order_id ) ) );
			if ( null === $found ) { return null; }
			$events = array_merge( $events, $found );
		}
		return $events;
	}

	private static function bump( $scope ) {
		set_transient( self::generation_name( $scope ), self::new_token(), self::GENERATION_TTL );
	}

	private static function generation_name( $scope ) {
		$scope = (int) $scope;
		return 0 === $scope ? 'wpcap_rgen_site' : ( $scope < 0 ? 'wpcap_rgen_all' : 'wpcap_rgen_e' . $scope );
	}

	private static function new_token() {
		return bin2hex( random_bytes( 8 ) );
	}

	private static function blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}

	/** wpdb result state, so statements of a flush between two caller statements stay invisible. */
	private static function snapshot() {
		global $wpdb;
		$state = array();
		foreach ( array( 'insert_id', 'rows_affected', 'num_rows', 'last_result', 'last_query', 'last_error' ) as $prop ) {
			if ( property_exists( $wpdb, $prop ) ) { $state[ $prop ] = $wpdb->$prop; }
		}
		return $state;
	}

	private static function restore( array $state ) {
		global $wpdb;
		foreach ( $state as $prop => $value ) { $wpdb->$prop = $value; }
	}

	/** Test helper: forget transaction state, pending bumps and memo. */
	public static function reset() {
		self::$pending = array();
		self::$flush_due = false;
		self::$in_tx = false;
		self::$memo = array();
	}
}
