<?php
/**
 * Read queries and access scoping for the admin screens.
 *
 * Scoping: users with manage_options see everything; other users only see events they are
 * assigned to in wpcap_event_staff for a capability they also hold through their role.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Queries {
	/** Whether the current user sees all events. */
	public static function is_global_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Event ids the current user may access with any of the given capabilities.
	 *
	 * @param string|string[] $caps Capabilities.
	 * @return int[]|null Null means "all events".
	 */
	public static function event_ids_for( $caps ) {
		$caps = array_values( array_filter( (array) $caps, static function ( $cap ) { return current_user_can( $cap ); } ) );
		if ( ! $caps ) { return array(); }
		if ( self::is_global_admin() ) { return null; }
		// Active time-limited support access (Security\SupportAccess) covers all events.
		foreach ( $caps as $cap ) {
			if ( \PassCaptain\Security\SupportAccess::covers( get_current_user_id(), $cap ) ) { return null; }
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $caps ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_event_staff WHERE user_id=%d AND capability IN ($placeholders)", array_merge( array( get_current_user_id() ), $caps ) ) );
		// Location/partner scope of the user (A119; no-op without scope).
		return Access::filter_event_ids( array_map( 'absint', $ids ) );
	}

	/**
	 * Occurrence (date) ids the current user is assigned to with any of the capabilities
	 * (wpcap_occurrence_staff; date-level staff without event access).
	 *
	 * @param string|string[] $caps Capabilities.
	 * @return int[]
	 */
	public static function occurrence_ids_for( $caps ) {
		$caps = array_values( array_filter( (array) $caps, static function ( $cap ) { return current_user_can( $cap ); } ) );
		if ( ! $caps || ! get_current_user_id() ) { return array(); }
		global $wpdb;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT occurrence_id FROM %i WHERE user_id=%d AND capability IN (' . implode( ',', array_fill( 0, count( $caps ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_occurrence_staff', get_current_user_id() ), $caps ) ) ) );
	}

	/** SQL fragment restricting $column to the given scope (null = no restriction). */
	public static function scope_sql( $event_ids, $column ) {
		if ( null === $event_ids ) { return ''; }
		if ( ! $event_ids ) { return ' AND 1=0'; }
		return ' AND ' . $column . ' IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ')';
	}

	/** Whether the current user may access the event with at least one of the capabilities. */
	public static function can_access_event_any( $event_id, array $caps ) {
		foreach ( $caps as $cap ) {
			if ( Access::can_access_event( $event_id, $cap ) ) { return true; }
		}
		return false;
	}

	/** Escapes LIKE wildcards. */
	public static function like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/* --------------------------------------------------------------------------------------------- */

	public static function event( $event_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', absint( $event_id ) ) );
	}

	public static function occurrence( $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
	}

	/** @param int $limit 0 = all dates; otherwise at most that many (A476: no full list where a few suffice). */
	public static function occurrences_for_event( $event_id, $limit = 0 ) {
		global $wpdb;
		if ( $limit > 0 ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d ORDER BY starts_at, occurrence_id LIMIT %d', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ), absint( $limit ) ) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d ORDER BY starts_at, occurrence_id', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ) ) );
	}

	/**
	 * Occurrence choices for staff selectors (box office, print, guest list): only scheduled
	 * occurrences of published events; running and upcoming ones first (ascending, the default
	 * choice is the first key), past ones after them (newest first, marked "vergangen").
	 * An occurrence counts as running until 12 hours after its start (or until its end).
	 *
	 * @param object[] $rows        Rows with occurrence_id, starts_at, timezone, status, label (string),
	 *                              optionally ends_at and event_status.
	 * @param bool     $include_past Whether past occurrences are offered at all.
	 * @return array occurrence_id => label
	 */
	public static function occurrence_choices( array $rows, $include_past = true ) {
		$now = \PassCaptain\Clock::now();
		$upcoming = array();
		$past = array();
		foreach ( $rows as $row ) {
			if ( 'scheduled' !== ( $row->status ?? 'scheduled' ) || ( isset( $row->event_status ) && 'published' !== $row->event_status ) ) { continue; }
			$start = strtotime( $row->starts_at . ' UTC' );
			$end = ! empty( $row->ends_at ) ? strtotime( $row->ends_at . ' UTC' ) : $start + 12 * HOUR_IN_SECONDS;
			if ( max( $end, $start ) >= $now ) {
				$upcoming[ (int) $row->occurrence_id ] = array( $start, (string) $row->label );
			} elseif ( $include_past ) {
				$past[ (int) $row->occurrence_id ] = array( $start, (string) $row->label );
			}
		}
		uasort( $upcoming, static function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		uasort( $past, static function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		$out = array();
		foreach ( $upcoming as $id => $item ) { $out[ $id ] = $item[1]; }
		/* translators: %s: occurrence label */
		foreach ( $past as $id => $item ) { $out[ $id ] = sprintf( __( '%s (vergangen)', 'pass-captain' ), $item[1] ); }
		return $out;
	}

	/** Ticket type with occurrence context (event_id, timezone). */
	public static function ticket_type( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT tt.*,o.event_id,o.timezone,o.starts_at AS occurrence_starts_at FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $ticket_type_id ) ) );
	}

	public static function ticket_types_for_occurrence( $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d ORDER BY ' . ( \PassCaptain\Orders::schema_at_least( '3.17.5' ) ? 'position,' : '' ) . 'ticket_type_id', $wpdb->prefix . 'wpcap_ticket_types', absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal ORDER BY.
	}

	/**
	 * Number of ticket types per occurrence (one query, A476).
	 *
	 * @param int[] $occurrence_ids Occurrences.
	 * @return array<int,int>
	 */
	public static function ticket_type_counts( array $occurrence_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $occurrence_ids ) ) ) );
		if ( ! $ids ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT occurrence_id, COUNT(*) AS n FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY occurrence_id', $ids ) ) as $row ) {
			$out[ (int) $row->occurrence_id ] = (int) $row->n;
		}
		return $out;
	}

	/** Participant field with event context. */
	public static function participant_field( $field_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT f.*,o.event_id FROM %i f INNER JOIN %i tt ON tt.ticket_type_id=f.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE f.field_id=%d', $p . 'wpcap_participant_fields', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $field_id ) ) );
	}

	public static function participant_fields( $ticket_type_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d ORDER BY position, field_id', $wpdb->prefix . 'wpcap_participant_fields', absint( $ticket_type_id ) ) );
	}

	public static function staff_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d ORDER BY user_id, capability', $wpdb->prefix . 'wpcap_event_staff', absint( $event_id ) ) );
	}

	/** Tickets issued (valid or checked in) for an occurrence. */
	public static function issued_ticket_count_for_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status IN ('valid','checked_in')", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', absint( $occurrence_id ) ) );
	}

	/** Sold units of an occurrence (paid, not refunded, without add-on products) – same basis as the capacity checks in Orders. */
	public static function sold_for_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'" . \PassCaptain\Sales\Addons::not_addon_sql( 'tt' ), absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal fragment (add-on products use no seats).
		/** Sold units shown for an occurrence; modules add units counted there (e.g. passes anchored elsewhere). */
		return (int) apply_filters( 'wpcap_occurrence_sold', $sold, absint( $occurrence_id ) );
	}

	/**
	 * Sold units of several occurrences with one GROUP BY query (A476; same rule and filter as
	 * sold_for_occurrence()).
	 *
	 * @param int[] $occurrence_ids Occurrences.
	 * @return array<int,int> occurrence_id => sold units.
	 */
	public static function sold_for_occurrences( array $occurrence_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $occurrence_ids ) ) ) );
		if ( ! $ids ) { return array(); }
		$sold = array_fill_keys( $ids, 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.occurrence_id, COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) AS sold FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND ord.payment_status='paid' AND ord.status <> 'refunded'" . \PassCaptain\Sales\Addons::not_addon_sql( 'tt' ) . ' GROUP BY tt.occurrence_id', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and literal fragment.
		foreach ( (array) $rows as $row ) { $sold[ (int) $row->occurrence_id ] = (int) $row->sold; }
		foreach ( $sold as $id => $units ) {
			/** See sold_for_occurrence(). */
			$sold[ $id ] = (int) apply_filters( 'wpcap_occurrence_sold', $units, $id );
		}
		return $sold;
	}

	/** Occurrences per page on the event detail page (A476; filter wpcap_admin_occurrences_per_page). */
	public static function occurrences_per_page() {
		return max( 1, min( 500, (int) apply_filters( 'wpcap_admin_occurrences_per_page', 50 ) ) );
	}

	/**
	 * One page of an event's occurrences (A476: long series are no longer rendered at once). Without
	 * a page number the page holding the next occurrence that is not over (start less than 12 hours
	 * ago) is shown, so a daily series of several years opens at "today".
	 *
	 * @return array{rows: object[], total: int, page: int, pages: int, per_page: int}
	 */
	public static function occurrence_page( $event_id, $page = 0, $per_page = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$per_page = $per_page ? max( 1, (int) $per_page ) : self::occurrences_per_page();
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d", $event_id ) );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = (int) $page;
		if ( $page < 1 ) {
			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d AND starts_at < %s", $event_id, gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - 12 * HOUR_IN_SECONDS ) ) );
			$page = $before >= $total ? $pages : intdiv( $before, $per_page ) + 1;
		}
		$page = min( max( 1, $page ), $pages );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at, occurrence_id LIMIT %d OFFSET %d", $event_id, $per_page, ( $page - 1 ) * $per_page ) );
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per_page );
	}

	/** Latest occurrence of an event (default time zone of a new occurrence) or null. */
	public static function last_occurrence( $event_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at DESC, occurrence_id DESC LIMIT 1", absint( $event_id ) ) );
	}

	/** Cancelled occurrences of an event (ids), for the progress of their cancellation runs. */
	public static function cancelled_occurrence_ids( $event_id, $limit = 50 ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d AND status='cancelled' ORDER BY starts_at DESC LIMIT %d", absint( $event_id ), max( 1, (int) $limit ) ) ) );
	}

	/** Choices offered in an occurrence select (A476; filter wpcap_admin_occurrence_choices, default 200). */
	public static function occurrence_choice_limit() {
		return max( 1, min( 2000, (int) apply_filters( 'wpcap_admin_occurrence_choices', 200 ) ) );
	}

	/**
	 * Bounded occurrence list of an event for selects and per-occurrence forms (A476): the next
	 * occurrences from $from (Y-m-d, site time zone; default: those not over yet) in ascending
	 * order, filled up with the most recent earlier ones, at most $limit; occurrences in $keep (e.g.
	 * already assigned or selected) are always included. A series with thousands of dates no longer
	 * renders thousands of options.
	 *
	 * @param int[] $keep Occurrence ids that must be part of the list.
	 * @return array{rows: object[], total: int, more: bool, from: string}
	 */
	public static function occurrence_window( $event_id, array $keep = array(), $from = '', $limit = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$limit = $limit ? max( 1, (int) $limit ) : self::occurrence_choice_limit();
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '';
		$since = '' !== $from ? self::local_day_start_utc( $from ) : gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - 12 * HOUR_IN_SECONDS );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d", $event_id ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE event_id=%d AND starts_at >= %s ORDER BY starts_at, occurrence_id LIMIT %d", $event_id, $since, $limit ) );
		if ( count( $rows ) < $limit ) {
			$earlier = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE event_id=%d AND starts_at < %s ORDER BY starts_at DESC, occurrence_id DESC LIMIT %d", $event_id, $since, $limit - count( $rows ) ) );
			$rows = array_merge( array_reverse( $earlier ), $rows );
		}
		$seen = array();
		foreach ( $rows as $row ) { $seen[ (int) $row->occurrence_id ] = true; }
		$missing = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $keep ) ) ), array_keys( $seen ) ) );
		if ( $missing ) {
			$extra = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d AND occurrence_id IN (" . implode( ',', array_fill( 0, count( $missing ), '%d' ) ) . ')', array_merge( array( $event_id ), $missing ) ) );
			$rows = array_merge( $rows, $extra );
			usort( $rows, static function ( $a, $b ) { return array( $a->starts_at, (int) $a->occurrence_id ) <=> array( $b->starts_at, (int) $b->occurrence_id ); } );
		}
		return array( 'rows' => $rows, 'total' => $total, 'more' => $total > count( $rows ), 'from' => $from );
	}

	/** UTC start of a local calendar day (site time zone). */
	private static function local_day_start_utc( $date ) {
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			return ( new \DateTimeImmutable( $date . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $date . ' 00:00:00';
		}
	}

	/** Sold units of a ticket type (paid, not refunded). */
	public static function sold_for_type( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $p . 'wpcap_order_items', $p . 'wpcap_orders', absint( $ticket_type_id ) ) );
	}

	/** Whether any order (in any state) references the ticket type. */
	public static function type_has_orders( $ticket_type_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE ticket_type_id=%d LIMIT 1', $wpdb->prefix . 'wpcap_order_items', absint( $ticket_type_id ) ) );
	}

	/** Event states of the status views of the event list (A411). */
	const EVENT_STATUSES = array( 'draft', 'published', 'cancelled' );

	/**
	 * ORDER BY of the event list (A411): name or status (indexed) in either direction, else newest first.
	 *
	 * @param array $view_args orderby (name|status), order (asc|desc).
	 */
	public static function events_order_sql( array $view_args ) {
		$dir = 'desc' === strtolower( (string) ( $view_args['order'] ?? '' ) ) ? 'DESC' : 'ASC';
		switch ( (string) ( $view_args['orderby'] ?? '' ) ) {
			case 'name':
				return "e.name {$dir}, e.event_id {$dir}";
			case 'status':
				return "e.status {$dir}, e.created_at DESC, e.event_id DESC";
		}
		return 'e.created_at DESC, e.event_id DESC';
	}

	/**
	 * Number of events per state in the scope (status views of the event list, one indexed GROUP BY).
	 *
	 * @param int[]|null $event_ids Scope (null = all).
	 * @return array<string,int> state => count, plus "all".
	 */
	public static function event_status_counts( $event_ids ) {
		global $wpdb;
		$where = '1=1' . self::scope_sql( $event_ids, 'e.event_id' );
		$out = array_fill_keys( self::EVENT_STATUSES, 0 );
		$all = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT e.status, COUNT(*) AS n FROM %i e WHERE {$where} GROUP BY e.status", $wpdb->prefix . 'wpcap_events' ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: integer scope only.
			$all += (int) $row->n;
			if ( isset( $out[ (string) $row->status ] ) ) { $out[ (string) $row->status ] = (int) $row->n; }
		}
		return array( 'all' => $all ) + $out;
	}

	/**
	 * Events overview: exactly one row per event with occurrence count and next upcoming occurrence.
	 *
	 * @param int[]|null $event_ids Scope (null = all).
	 * @param array      $view_args A411 list table: status (view), orderby (name|status), order (asc|desc).
	 * @return array{rows: object[], total: int}
	 */
	public static function events_overview( $event_ids, $page = 1, $per_page = 50, array $view_args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// The scope condition from scope_sql() lists integer event ids only (or is empty for all events).
		$scope = self::scope_sql( $event_ids, 'e.event_id' );
		$where = '1=1' . $scope;
		// A411: status view (indexed column) and a whitelisted sort order of the list table.
		$status = (string) ( $view_args['status'] ?? '' );
		if ( in_array( $status, self::EVENT_STATUSES, true ) ) { $where .= " AND e.status='" . $status . "'"; }
		$order_sql = self::events_order_sql( $view_args );
		$now = \PassCaptain\Clock::mysql();
		$offset = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e WHERE {$where}", $p . 'wpcap_events' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: integer scope and a whitelisted status only.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: integer scope and a whitelisted status; $order_sql: fixed SQL from events_order_sql().
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.slug,e.status,e.created_at,
				(SELECT COUNT(*) FROM %i oc WHERE oc.event_id=e.event_id) AS occurrence_count,
				(SELECT o1.occurrence_id FROM %i o1 WHERE o1.event_id=e.event_id AND o1.status='scheduled' AND o1.starts_at >= %s ORDER BY o1.starts_at, o1.occurrence_id LIMIT 1) AS next_occurrence_id,
				(SELECT o2.occurrence_id FROM %i o2 WHERE o2.event_id=e.event_id ORDER BY o2.starts_at DESC, o2.occurrence_id DESC LIMIT 1) AS last_occurrence_id
			FROM %i e WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
			$p . 'wpcap_occurrences',
			$p . 'wpcap_occurrences',
			$now,
			$p . 'wpcap_occurrences',
			$p . 'wpcap_events',
			$per_page,
			$offset
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$occ_ids = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'next_occurrence_id', 'last_occurrence_id' ) as $col ) {
				if ( $row->$col ) { $occ_ids[] = (int) $row->$col; }
			}
		}
		$occurrences = array();
		if ( $occ_ids ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id IN (' . implode( ',', array_fill( 0, count( array_unique( $occ_ids ) ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_occurrences' ), array_unique( $occ_ids ) ) ) ) as $occ ) {
				$occurrences[ (int) $occ->occurrence_id ] = $occ;
			}
		}
		foreach ( $rows as $row ) {
			$row->next_occurrence = $row->next_occurrence_id ? ( $occurrences[ (int) $row->next_occurrence_id ] ?? null ) : null;
			$row->last_occurrence = $row->last_occurrence_id ? ( $occurrences[ (int) $row->last_occurrence_id ] ?? null ) : null;
		}
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Key figures of many events with two queries (event list, no per-row queries):
	 * - sold: paid, not refunded units (as sold_for_occurrence(), summed over all occurrences);
	 * - capacity: sellable units over the not cancelled occurrences – per occurrence the smaller of
	 *   the occurrence limit and the sum of its ticket type limits; null when one is unlimited;
	 * - revenue: currency => gross minor units of the paid, not refunded units after line discounts
	 *   (refunded/exchanged units pro rata).
	 *
	 * @param int[] $event_ids Event ids.
	 * @return array event_id => ['sold' => int, 'capacity' => int|null, 'revenue' => array<string,int>]
	 */
	public static function event_figures( array $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', $ids );
		$out = array();
		foreach ( $ids as $id ) { $out[ $id ] = array( 'sold' => 0, 'capacity' => 0, 'revenue' => array() ); }

		$qty = 'oi.quantity - oi.refunded_quantity';
		$paid_qty = \PassCaptain\OrderLines::paid( 'quantity' );
		$paid_total = \PassCaptain\OrderLines::paid( 'line_total_minor' ) . ( \PassCaptain\OrderLines::ready() ? ' - COALESCE(oi.discount_minor,0)' : '' );
		$sales = $wpdb->get_results(
			"SELECT oc.event_id, ord.currency,
				SUM({$qty}) AS sold,
				SUM(CASE WHEN {$paid_qty} > 0 THEN FLOOR(GREATEST({$paid_total},0) * LEAST({$qty},{$paid_qty}) / {$paid_qty}) ELSE 0 END) AS revenue
			FROM {$p}wpcap_order_items oi
			INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id
			INNER JOIN {$p}wpcap_occurrences oc ON oc.occurrence_id=tt.occurrence_id
			INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id
			WHERE oc.event_id IN ({$in}) AND ord.payment_status='paid' AND ord.status <> 'refunded'
			GROUP BY oc.event_id, ord.currency"
		);
		foreach ( (array) $sales as $row ) {
			$event = (int) $row->event_id;
			$out[ $event ]['sold'] += (int) $row->sold;
			$revenue = (int) $row->revenue;
			if ( $revenue > 0 ) {
				$currency = strtoupper( (string) $row->currency );
				$out[ $event ]['revenue'][ $currency ] = ( $out[ $event ]['revenue'][ $currency ] ?? 0 ) + $revenue;
			}
		}

		$caps = $wpdb->get_results(
			"SELECT oc.event_id, oc.occurrence_id, oc.capacity AS occurrence_capacity,
				COUNT(tt.ticket_type_id) AS types, COALESCE(SUM(tt.capacity = 0),0) AS unlimited_types, COALESCE(SUM(tt.capacity),0) AS type_capacity
			FROM {$p}wpcap_occurrences oc
			LEFT JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=oc.occurrence_id
			WHERE oc.event_id IN ({$in}) AND oc.status <> 'cancelled'
			GROUP BY oc.event_id, oc.occurrence_id, oc.capacity"
		);
		foreach ( (array) $caps as $row ) {
			$event = (int) $row->event_id;
			if ( null === $out[ $event ]['capacity'] ) { continue; }
			$limits = array();
			if ( (int) $row->occurrence_capacity > 0 ) { $limits[] = (int) $row->occurrence_capacity; }
			if ( (int) $row->types > 0 && 0 === (int) $row->unlimited_types ) { $limits[] = (int) $row->type_capacity; }
			if ( ! $limits && (int) $row->types > 0 ) {
				$out[ $event ]['capacity'] = null; // Unlimited.
				continue;
			}
			$out[ $event ]['capacity'] += $limits ? min( $limits ) : 0;
		}
		/**
		 * Key figures of the admin event list; modules adjust them in bulk (e.g. units counted elsewhere).
		 *
		 * @param array $out       event_id => ['sold', 'capacity', 'revenue'].
		 * @param int[] $event_ids Event ids.
		 */
		return (array) apply_filters( 'wpcap_event_figures', $out, $ids );
	}

	/** Maximum number of events in an event select (event_options()). */
	const EVENT_OPTIONS_LIMIT = 500;

	/** Whether the last event_options() call was cut at EVENT_OPTIONS_LIMIT. */
	private static $options_truncated = false;

	/** True when the last event_options() list was cut (more events exist than shown). */
	public static function event_options_truncated() {
		return self::$options_truncated;
	}

	/**
	 * Escaped note for a cut event select (empty when the last list was complete): which events are
	 * shown and how to reach the others (event ID in the page address).
	 */
	public static function event_options_note() {
		if ( ! self::$options_truncated ) { return ''; }
		/* translators: %d: number of events shown */
		return '<p class="description wpcap-truncated">' . esc_html( sprintf( __( 'Es werden nur die ersten %d Events (alphabetisch) angezeigt. Weitere Events über die Event-ID in der Adresse auswählen (…&event_id=123); eine bereits gespeicherte Auswahl bleibt erhalten.', 'pass-captain' ), self::EVENT_OPTIONS_LIMIT ) ) . '</p>';
	}

	/**
	 * Event id => label for selects (scoped). Events sharing a name are told apart by their first
	 * date and ID ("Konzert (12.05.2027 20:00 Europe/Vienna, #14)"). At most EVENT_OPTIONS_LIMIT
	 * events plus the $include ids (selected or stored values, when in scope).
	 *
	 * @param int[]|null $event_ids Scope (null = all).
	 * @param int[]      $include   Event ids that must be in the list when in scope.
	 */
	public static function event_options( $event_ids, array $include = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = self::scope_sql( $event_ids, 'e.event_id' );
		$select = "SELECT e.event_id,e.name,MIN(o.starts_at) AS first_start,MIN(o.timezone) AS timezone FROM {$p}wpcap_events e LEFT JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id WHERE 1=1{$scope}";
		$rows = (array) $wpdb->get_results( "$select GROUP BY e.event_id,e.name ORDER BY e.name,first_start LIMIT " . ( self::EVENT_OPTIONS_LIMIT + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope from scope_sql(), integer limit.
		// A447: the list is capped; a cut list is reported (event_options_note()) and the selected/stored
		// events are always added, so a select never silently switches to another value or drops one.
		self::$options_truncated = count( $rows ) > self::EVENT_OPTIONS_LIMIT;
		$rows = array_slice( $rows, 0, self::EVENT_OPTIONS_LIMIT );
		$have = array_map( static function ( $r ) { return (int) $r->event_id; }, $rows );
		$include = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $include ) ) ), $have ) );
		if ( $include ) {
			$extra = (array) $wpdb->get_results( "$select AND e.event_id IN (" . implode( ',', $include ) . ') GROUP BY e.event_id,e.name' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$rows = array_merge( $rows, $extra );
		}
		$count = array();
		foreach ( (array) $rows as $row ) { $key = mb_strtolower( trim( (string) $row->name ) ); $count[ $key ] = ( $count[ $key ] ?? 0 ) + 1; }
		$out = array();
		foreach ( (array) $rows as $row ) {
			$label = (string) $row->name;
			if ( $count[ mb_strtolower( trim( $label ) ) ] > 1 ) {
				$label .= ' (' . ( $row->first_start ? \PassCaptain\Orders::format_start( $row->first_start, (string) $row->timezone ) . ', ' : '' ) . '#' . (int) $row->event_id . ')';
			}
			$out[ (int) $row->event_id ] = $label;
		}
		return $out;
	}

	/**
	 * Public id prefix of a typed order or ticket number (A336/A545): the short order number as shown
	 * in mails, the portal and payment references ("AB12-CD34", "ab12 cd34", "Bestellung AB12-CD34",
	 * "#AB12CD34") or a longer part of the public id, lower case without separators. '' when the input
	 * is no such number.
	 */
	public static function public_id_prefix( $search ) {
		$value = strtolower( trim( (string) $search ) );
		$value = (string) preg_replace( '/^(?:bestellung|bestellnummer|order|ticket)\s*(?:nr\.?|nummer)?\s*:?\s*/u', '', $value );
		$hex = (string) preg_replace( '/[\s\-#]+/', '', $value );
		return 1 === preg_match( '/^[a-f0-9]{4,32}$/', $hex ) ? $hex : '';
	}

	/**
	 * SQL condition "every word of the search occurs in the billing data" (A336: buyer name/company in
	 * the billing JSON). Each word is matched as typed and in its JSON-escaped form (non-ASCII letters are
	 * stored as \uXXXX). Empty when the search has no word of at least two characters.
	 *
	 * @param string $column Column holding the billing JSON.
	 * @param string $search Search input.
	 * @return array{0:string,1:string[]} SQL fragment and its arguments.
	 */
	public static function billing_words_sql( $column, $search ) {
		$words = array_slice( array_values( array_filter( preg_split( '/\s+/u', trim( (string) $search ) ) ?: array(), static function ( $w ) { return mb_strlen( $w ) >= 2; } ) ), 0, 4 );
		$parts = array();
		$args = array();
		foreach ( $words as $word ) {
			$escaped = substr( (string) wp_json_encode( $word ), 1, -1 );
			$variants = array_unique( array( $word, $escaped ) );
			$parts[] = '(' . implode( ' OR ', array_fill( 0, count( $variants ), $column . ' LIKE %s' ) ) . ')';
			foreach ( $variants as $variant ) { $args[] = '%' . self::like( $variant ) . '%'; }
		}
		return $parts ? array( '(' . implode( ' AND ', $parts ) . ')', $args ) : array( '', array() );
	}

	/* --------------------------------------------------------------------------------------------- */

	/** Distinct event ids an order has items for. */
	public static function order_event_ids( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT o.event_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $order_id ) ) ) );
	}

	/**
	 * Whether the current user may act on an order: they need the capability for every event in it
	 * and every occurrence must lie in their location/partner scope (A119).
	 * Orders without items are reserved for global admins.
	 */
	public static function can_access_order( $order_id, $cap ) {
		if ( ! current_user_can( $cap ) ) { return false; }
		$events = self::order_event_ids( $order_id );
		if ( ! $events ) { return self::is_global_admin(); }
		foreach ( $events as $event_id ) {
			if ( ! Access::can_access_event( $event_id, $cap ) ) { return false; }
		}
		return Access::order_in_scope( $order_id, 0, $cap );
	}

	/**
	 * A650: SQL condition for orders the user may see in lists – like can_access_order(): at least one
	 * line and every line in an event (and occurrence) of the scope. An order that also holds tickets of
	 * a foreign event is not listed (its e-mail, total and the foreign event name would show).
	 *
	 * @param int[]|null $event_ids Scope (null = all events).
	 * @param string     $order_col Column holding the order id (e.g. ord.order_id).
	 */
	public static function order_scope_sql( $event_ids, $order_col ) {
		if ( null === $event_ids ) { return ''; }
		if ( ! $event_ids ) { return ' AND 1=0'; }
		global $wpdb;
		$p = $wpdb->prefix;
		$order_col = preg_replace( '/[^A-Za-z0-9_.]/', '', (string) $order_col );
		$lines = "SELECT 1 FROM {$p}wpcap_order_items fi INNER JOIN {$p}wpcap_ticket_types ft ON ft.ticket_type_id=fi.ticket_type_id INNER JOIN {$p}wpcap_occurrences fo ON fo.occurrence_id=ft.occurrence_id WHERE fi.order_id=$order_col";
		$in_scope = '(1=1' . self::scope_sql( $event_ids, 'fo.event_id' ) . Access::occurrence_scope_sql( 'fo' ) . ')';
		return " AND EXISTS ($lines) AND NOT EXISTS ($lines AND NOT $in_scope)";
	}

	/**
	 * Order search with filters.
	 *
	 * @param array      $filters   status, payment_status, search (order number "AB12-CD34" or public id
	 *                              prefix, e-mail part, billing name/company, attendee name or e-mail,
	 *                              ticket number), event_id.
	 * @param int[]|null $event_ids Scope.
	 * @return array{rows: object[], total: int}
	 */
	public static function search_orders( array $filters, $event_ids, $page = 1, $per_page = 20, array $sort = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '1=1';
		$args = array();
		if ( ! empty( $filters['status'] ) ) { $where .= ' AND ord.status=%s'; $args[] = (string) $filters['status']; }
		if ( ! empty( $filters['payment_status'] ) ) { $where .= ' AND ord.payment_status=%s'; $args[] = (string) $filters['payment_status']; }
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$search = trim( (string) $filters['search'] );
			$part = '%' . self::like( $search ) . '%';
			// Short order numbers / payment references ("Bestellung 3F2A-9C1E") are public id prefixes.
			$id_prefix = self::public_id_prefix( $search );
			if ( is_email( $search ) ) {
				// A complete address: exact match on the customer_email index (PERF-15).
				$or = array( 'ord.public_id LIKE %s', 'ord.customer_email=%s' );
				array_push( $args, self::like( '' !== $id_prefix ? $id_prefix : strtolower( $search ) ) . '%', $search );
			} else {
				$or = array( 'ord.public_id LIKE %s', 'ord.customer_email LIKE %s' );
				// E-mail: prefix search ("anna", "anna@exa") on the customer_email index; a leading "@" searches
				// by domain (suffix, documented full scan). PERF-15.
				array_push( $args, self::like( '' !== $id_prefix ? $id_prefix : strtolower( $search ) ) . '%', '@' === $search[0] ? '%' . self::like( $search ) : self::like( $search ) . '%' );
			}
			// A336: buyer name/company (billing data) and attendee names, as far as the user may see them.
			// R7: names only from 3 characters on (no accidental full scans for "Zo").
			$names = '@' !== $search[0] && mb_strlen( $search ) >= 3;
			if ( $names && ( \PassCaptain\Pii::can_view( 'buyer_name' ) || \PassCaptain\Pii::can_view( 'billing' ) ) ) {
				list( $billing_sql, $billing_args ) = self::billing_words_sql( 'ord.billing', $search );
				if ( '' !== $billing_sql ) { $or[] = $billing_sql; array_push( $args, ...$billing_args ); }
			}
			if ( $names && \PassCaptain\Pii::can_view( 'attendee_name' ) ) {
				$or[] = "EXISTS (SELECT 1 FROM {$p}wpcap_tickets st INNER JOIN {$p}wpcap_ticket_assignments sa ON sa.ticket_id=st.ticket_id WHERE st.order_id=ord.order_id AND sa.attendee_name LIKE %s)";
				$args[] = $part;
			}
			if ( \PassCaptain\Pii::can_view( 'attendee_email' ) ) {
				$or[] = "EXISTS (SELECT 1 FROM {$p}wpcap_tickets st LEFT JOIN {$p}wpcap_ticket_assignments sa ON sa.ticket_id=st.ticket_id WHERE st.order_id=ord.order_id AND (st.attendee_email LIKE %s OR sa.attendee_email LIKE %s))";
				$email_part = is_email( $search ) ? self::like( $search ) : ( '@' === $search[0] ? '%' . self::like( $search ) : self::like( $search ) . '%' );
				array_push( $args, $email_part, $email_part );
			}
			if ( '' !== $id_prefix && strlen( $id_prefix ) >= 8 ) {
				// A ticket number of the order (ticket list, PDF) finds the order, too.
				$or[] = "EXISTS (SELECT 1 FROM {$p}wpcap_tickets st WHERE st.order_id=ord.order_id AND st.public_id LIKE %s)";
				$args[] = self::like( $id_prefix ) . '%';
			}
			$where .= ' AND (' . implode( ' OR ', $or ) . ')';
		}
		$items_join = "SELECT 1 FROM {$p}wpcap_order_items fi INNER JOIN {$p}wpcap_ticket_types ft ON ft.ticket_type_id=fi.ticket_type_id INNER JOIN {$p}wpcap_occurrences fo ON fo.occurrence_id=ft.occurrence_id WHERE fi.order_id=ord.order_id";
		if ( ! empty( $filters['event_id'] ) ) {
			$where .= " AND EXISTS ($items_join AND fo.event_id=%d)";
			$args[] = absint( $filters['event_id'] );
		}
		$where .= self::order_scope_sql( $event_ids, 'ord.order_id' );
		$count_sql = "SELECT COUNT(*) FROM {$p}wpcap_orders ord WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$list_args = array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ord.*,(SELECT GROUP_CONCAT(DISTINCT e.name ORDER BY e.name SEPARATOR ', ') FROM {$p}wpcap_order_items ei INNER JOIN {$p}wpcap_ticket_types et ON et.ticket_type_id=ei.ticket_type_id INNER JOIN {$p}wpcap_occurrences eo ON eo.occurrence_id=et.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=eo.event_id WHERE ei.order_id=ord.order_id) AS event_names FROM {$p}wpcap_orders ord WHERE $where ORDER BY " . self::order_sort_sql( $sort ) . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY from the whitelist in order_sort_sql().
			$list_args
		) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * ORDER BY of the order list (A411): whitelisted column (date = created_at, total = total_minor) and
	 * direction; the order id breaks ties in the same direction. Default: newest first.
	 */
	public static function order_sort_sql( array $sort ) {
		$column = 'total' === ( $sort['orderby'] ?? '' ) ? 'ord.total_minor' : 'ord.created_at';
		$dir = 'asc' === strtolower( (string) ( $sort['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		return "$column $dir, ord.order_id $dir";
	}

	/**
	 * Number of paid orders without tickets that need a decision (`needs_review`: payment after the
	 * hold expired without free capacity) the current user may see (A337: menu counter, admin notice).
	 * Same scope as the order list (search_orders()); uses the status index.
	 *
	 * @param int[]|null $event_ids Scope (null = all events).
	 */
	public static function needs_review_count( $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( null !== $event_ids && ! $event_ids ) { return 0; }
		$scope = self::order_scope_sql( $event_ids, 'ord.order_id' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders ord WHERE ord.status='needs_review'{$scope}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer scope from scope_sql()/occurrence_scope_sql().
	}

	/** Order items with tax, event and occurrence context. */
	public static function order_items( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$items = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT oi.*,tt.name,e.name AS event_name,o.starts_at,o.timezone FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $order_id ) ) );
		foreach ( $items as $item ) {
			// A425: service fee lines are named as such (quantity 0: no admission).
			if ( \PassCaptain\ServiceFee::is_fee_line( $item ) ) { $item->name = \PassCaptain\ServiceFee::label( $item, (string) $item->name ); }
		}
		return $items;
	}

	/** Tickets of an order with attendee name/e-mail and participant answers (label => answer). */
	public static function order_tickets( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tickets = $wpdb->get_results( $wpdb->prepare( 'SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.attendee_email,t.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,o.event_id,o.starts_at,o.timezone,ta.attendee_name,ta.attendee_email AS assigned_email FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_ticket_assignments', absint( $order_id ) ) );
		if ( ! $tickets ) { return array(); }
		$ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets );
		$answers = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT a.ticket_id,a.answer,f.field_key,f.label,f.field_type FROM %i a INNER JOIN %i f ON f.field_id=a.field_id WHERE a.ticket_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY f.position,f.field_id', array_merge( array( $p . 'wpcap_participant_answers', $p . 'wpcap_participant_fields' ), $ids ) ) ) as $answer ) {
			if ( ! \PassCaptain\Pii::can_view_answer( (string) $answer->field_key, true ) ) { continue; } // PASS-FE-05: rights per field (the data policy decides on all answers).
			$value = 'checkbox' === $answer->field_type ? ( '1' === (string) $answer->answer ? __( 'Ja', 'pass-captain' ) : __( 'Nein', 'pass-captain' ) ) : $answer->answer;
			$answers[ (int) $answer->ticket_id ][ $answer->label ] = $value;
		}
		foreach ( $tickets as $ticket ) {
			$ticket->answers = $answers[ (int) $ticket->ticket_id ] ?? array();
		}
		return $tickets;
	}

	/* --------------------------------------------------------------------------------------------- */

	/** Ticket with event/occurrence/order context. */
	public static function ticket_context( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT t.*,tt.name AS ticket_name,tt.occurrence_id,o.event_id,o.starts_at,o.timezone,ord.public_id AS order_public_id,ord.customer_email FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_orders', absint( $ticket_id ) ) );
	}

	/**
	 * Ticket search.
	 *
	 * @param array      $filters   search (ticket/order number – also "AB12-CD34" as shown to buyers –, help
	 *                              code, full ticket code or link, buyer or attendee e-mail/name part), event_id, status.
	 * @param int[]|null $event_ids      Scope.
	 * @param int[]      $occurrence_ids Additional single dates in scope (date-level staff); ignored when $event_ids is null.
	 * @return array{rows: object[], total: int}
	 */
	public static function search_tickets( array $filters, $event_ids, $page = 1, $per_page = 30, array $occurrence_ids = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// A432: the page is found on the tickets (and only the tables the filters need), its context and
		// last check-ins are read for the ids of the page only.
		$occ_join = " INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id";
		if ( null !== $event_ids && $occurrence_ids ) {
			// Events in scope or one of the assigned dates (wpcap_occurrence_staff).
			$where = '1=1 AND (' . ( $event_ids ? 'o.event_id IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ') OR ' : '' ) . 'o.occurrence_id IN (' . implode( ',', array_map( 'absint', $occurrence_ids ) ) . '))' . Access::occurrence_scope_sql( 'o' );
		} else {
			$where = '1=1' . self::scope_sql( $event_ids, 'o.event_id' ) . Access::occurrence_scope_sql( 'o' );
		}
		$needs_occ = '1=1' !== $where;
		$args = array();
		$notice = null;
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			// A441: the matching tickets are collected first, one indexed lookup per field, instead of
			// six LIKE '%x%' over a six-table join; the page is then built on these ids.
			$search = trim( (string) $filters['search'] );
			$capped = false;
			$matches = self::ticket_search_ids( $search, $capped, $notice );
			if ( $capped ) {
				/* translators: %d: maximum number of matches per field */
				$notice = sprintf( __( 'Mehr als %d Treffer – bitte die Suche genauer fassen (z. B. vollständige E-Mail-Adresse oder Bestellnummer).', 'pass-captain' ), self::TICKET_SEARCH_MAX );
			}
			$where .= ' AND t.ticket_id IN (' . ( $matches ? implode( ',', $matches ) : '0' ) . ')';
		}
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND o.event_id=%d'; $args[] = absint( $filters['event_id'] ); $needs_occ = true; }
		if ( ! empty( $filters['status'] ) ) { $where .= ' AND t.status=%s'; $args[] = (string) $filters['status']; }
		// A684: date and ticket type filters, sort order of the attendee management.
		if ( ! empty( $filters['occurrence_id'] ) ) { $where .= ' AND o.occurrence_id=%d'; $args[] = absint( $filters['occurrence_id'] ); $needs_occ = true; }
		if ( ! empty( $filters['ticket_type_id'] ) ) { $where .= ' AND t.ticket_type_id=%d'; $args[] = absint( $filters['ticket_type_id'] ); }
		// PASS-FE-05: one ticket type by name over all dates (the types of each date are their own rows).
		if ( empty( $filters['ticket_type_id'] ) && isset( $filters['type_name'] ) && '' !== (string) $filters['type_name'] ) { $where .= ' AND tt.name=%s'; $args[] = (string) $filters['type_name']; $needs_occ = true; }
		$orderby = self::ticket_order( (string) ( $filters['orderby'] ?? '' ), (string) ( $filters['order'] ?? '' ) );
		if ( false !== strpos( $orderby, 'o.' ) || false !== strpos( $orderby, 'tt.' ) ) { $needs_occ = true; }
		$name_join = false !== strpos( $orderby, 'ta.' ) ? " LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id" : '';
		// PASS-FE-05: the event sort reads the event name (only for the id query, the count needs no join).
		if ( 0 === strpos( $orderby, 'e.' ) ) { $needs_occ = true; $name_join .= " INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id"; }
		$from = "FROM {$p}wpcap_tickets t" . ( $needs_occ ? $occ_join : '' );
		$count_sql = "SELECT COUNT(*) $from WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$ids = $total ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT t.ticket_id $from$name_join WHERE $where ORDER BY $orderby LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments: joins, WHERE with placeholders, ORDER BY from ticket_order().
			array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
		) ) ) : array();
		$rows = array();
		if ( $ids ) {
			$in = implode( ',', $ids );
			$rows = (array) $wpdb->get_results( "SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.attendee_email,t.issued_at,t.order_id,ord.public_id AS order_public_id,ord.customer_email,ta.attendee_name,ta.attendee_email AS assigned_email,tt.name AS ticket_name,o.event_id,o.occurrence_id,o.starts_at,o.timezone,e.name AS event_name FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			// The page keeps the order of the id query (A684 sort orders); sorted here, not with a filesort (A432).
			$pos = array_flip( $ids );
			usort( $rows, static function ( $a, $b ) use ( $pos ) { return $pos[ (int) $a->ticket_id ] <=> $pos[ (int) $b->ticket_id ]; } );
			$last = array();
			foreach ( (array) $wpdb->get_results( "SELECT ticket_id,MAX(created_at) AS last_checkin_at FROM {$p}wpcap_checkins WHERE ticket_id IN ($in) AND result='checked_in' GROUP BY ticket_id" ) as $checkin ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$last[ (int) $checkin->ticket_id ] = (string) $checkin->last_checkin_at;
			}
			foreach ( $rows as $row ) { $row->last_checkin_at = $last[ (int) $row->ticket_id ] ?? null; }
		}
		return array( 'rows' => $rows, 'total' => $total ) + ( null !== $notice ? array( 'notice' => $notice ) : array() );
	}

	/** A684: sort orders of the ticket list (key => label); the SQL comes from ticket_order(). */
	public static function ticket_orders() {
		return array(
			'newest' => __( 'Neueste zuerst', 'pass-captain' ),
			'oldest' => __( 'Älteste zuerst', 'pass-captain' ),
			'event' => __( 'Nach Event', 'pass-captain' ), // PASS-FE-05.
			'date' => __( 'Nach Termin', 'pass-captain' ),
			'type' => __( 'Nach Ticketart', 'pass-captain' ),
			'name' => __( 'Nach Name', 'pass-captain' ),
			'status' => __( 'Nach Status', 'pass-captain' ), // PASS-FE-05.
		);
	}

	/**
	 * ORDER BY clause of a sort key (fixed SQL, unknown keys fall back to newest first). $order "desc"
	 * reverses the date order (sortable column of the list table, A411); the other keys have a fixed direction.
	 */
	public static function ticket_order( $key, $order = '' ) {
		$map = array(
			'oldest' => 't.ticket_id ASC',
			'date' => 'desc' === $order ? 'o.starts_at DESC, t.ticket_id DESC' : 'o.starts_at ASC, t.ticket_id ASC',
			'type' => 'tt.name ASC, t.ticket_id ASC',
			// PASS-FE-05: event name, within an event by date and ticket (lists over several events).
			'event' => 'e.name ASC, o.event_id ASC, o.starts_at ASC, t.ticket_id ASC',
			'name' => "COALESCE(NULLIF(ta.attendee_name,''),'~') ASC, t.ticket_id ASC",
			// PASS-FE-05: status, within a status by date (several dates of an event) and ticket.
			'status' => "FIELD(t.status,'valid','checked_in','revoked') ASC, t.status ASC, o.starts_at ASC, t.ticket_id ASC",
		);
		return $map[ $key ] ?? 't.ticket_id DESC';
	}

	/** Matches read per search field (A441); more means the search is too broad. */
	const TICKET_SEARCH_MAX = 1000;

	/**
	 * Ticket ids matching a ticket search (A441). Every field is looked up on its own index:
	 * ticket and order numbers by prefix (also "AB12-CD34" as shown to buyers), e-mail addresses by
	 * prefix (buyer, ticket, assignment), attendee names by prefix of the name or of one of its words
	 * (the word search reads only the name index of wpcap_ticket_assignments), the full ticket code
	 * exactly and the help code through the rate-limited HelpCode lookup. Names and e-mails only for
	 * users who may see them (A119).
	 *
	 * @param string      $search Search input (trimmed).
	 * @param bool        $capped Set when a field had more than TICKET_SEARCH_MAX matches.
	 * @param string|null $notice Set to the help-code rate-limit message.
	 * @return int[]
	 */
	private static function ticket_search_ids( $search, &$capped, &$notice ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$max = self::TICKET_SEARCH_MAX + 1;
		$id_prefix = self::public_id_prefix( $search );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
		$number = self::like( '' !== $id_prefix ? $id_prefix : $lower ) . '%';
		$text = self::like( $lower ) . '%';
		$queries = array(
			$wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE public_id LIKE %s LIMIT %d", $number, $max ),
			$wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id WHERE ord.public_id LIKE %s LIMIT %d", $number, $max ),
		);
		if ( mb_strlen( $search ) >= 2 ) {
			// Only fields the user may see are searchable (A119: no probing of masked e-mails/names).
			if ( \PassCaptain\Pii::can_view( 'buyer_email' ) ) {
				$queries[] = $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id WHERE ord.customer_email LIKE %s LIMIT %d", $text, $max );
			}
			if ( \PassCaptain\Pii::can_view( 'attendee_email' ) ) {
				$queries[] = $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE attendee_email LIKE %s LIMIT %d", $text, $max );
				$queries[] = $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_ticket_assignments WHERE attendee_email LIKE %s LIMIT %d", $text, $max );
			}
			if ( \PassCaptain\Pii::can_view( 'attendee_name' ) ) {
				$queries[] = $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_ticket_assignments WHERE attendee_name LIKE %s LIMIT %d", $text, $max );
				// Last name or a later word: scans only KEY attendee_name (name, ticket id), no join.
				$queries[] = $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_ticket_assignments WHERE attendee_name LIKE %s LIMIT %d", '% ' . $text, $max );
			}
		}
		$ids = array();
		foreach ( $queries as $sql ) {
			$found = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			if ( count( $found ) > self::TICKET_SEARCH_MAX ) { $capped = true; $found = array_slice( $found, 0, self::TICKET_SEARCH_MAX ); }
			foreach ( $found as $id ) { $ids[ $id ] = $id; }
		}
		// The full ticket code or ticket link (64 hex) – whoever holds it holds the ticket anyway.
		$code = \PassCaptain\Checkin::normalize_code( $search );
		if ( '' !== $code ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
			if ( $id ) { $ids[ $id ] = $id; }
		}
		// The help code printed under the QR code: rate-limited lookup like at the scanner (HelpCode).
		if ( \PassCaptain\HelpCode::looks_like( $search ) ) {
			$help_ticket = \PassCaptain\HelpCode::resolve( $search );
			if ( is_wp_error( $help_ticket ) ) { $notice = $help_ticket->get_error_message(); }
			if ( is_int( $help_ticket ) && $help_ticket > 0 ) { $ids[ $help_ticket ] = $help_ticket; }
		}
		return array_values( $ids );
	}

	/**
	 * Check-in log.
	 *
	 * @param array      $filters   event_id, result, date_from/date_to (UTC "Y-m-d H:i:s" bounds, to exclusive).
	 * @param int[]|null $event_ids Scope.
	 * @return array{rows: object[], total: int}
	 */
	public static function checkin_log( array $filters, $event_ids, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// A433: count and page on wpcap_checkins alone (KEY created, event_result_created); the ticket
		// chain is joined only when the user's occurrence scope needs it, names only for the page.
		$scope_join = " LEFT JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id";
		$occurrence_scope = Access::occurrence_scope_sql( 'o' );
		$where = '1=1' . self::scope_sql( $event_ids, 'c.event_id' ) . $occurrence_scope;
		$args = array();
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND c.event_id=%d'; $args[] = absint( $filters['event_id'] ); }
		if ( ! empty( $filters['result'] ) ) { $where .= ' AND c.result=%s'; $args[] = (string) $filters['result']; }
		if ( ! empty( $filters['date_from'] ) ) { $where .= ' AND c.created_at >= %s'; $args[] = (string) $filters['date_from']; }
		if ( ! empty( $filters['date_to'] ) ) { $where .= ' AND c.created_at < %s'; $args[] = (string) $filters['date_to']; }
		$from = "FROM {$p}wpcap_checkins c" . ( '' !== $occurrence_scope ? $scope_join : '' );
		$count_sql = "SELECT COUNT(*) $from WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$ids = $total ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT c.checkin_id $from WHERE $where ORDER BY c.created_at DESC, c.checkin_id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
		) ) ) : array();
		$rows = array();
		if ( $ids ) {
			$by_id = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*,t.public_id AS ticket_public_id,t.order_id,tt.name AS ticket_name,o.timezone,e.name AS event_name FROM {$wpdb->prefix}wpcap_checkins c LEFT JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=c.ticket_id LEFT JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=c.event_id WHERE c.checkin_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ) as $row ) {
				$by_id[ (int) $row->checkin_id ] = $row;
			}
			foreach ( $ids as $id ) { if ( isset( $by_id[ $id ] ) ) { $rows[] = $by_id[ $id ]; } } // Order of the page.
		}
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Results the check-in log can be filtered by (A433: a fixed list instead of a DISTINCT scan of
	 * the whole log; Checkin::LOG_RESULTS, filter `wpcap_checkin_log_results`).
	 */
	public static function checkin_results() {
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters( 'wpcap_checkin_log_results', \PassCaptain\Checkin::LOG_RESULTS ) ) ) );
	}

	/** Event scope of the pending-refund queries ('' = all events). */
	private static function pending_refund_scope( $event_ids ) {
		return self::order_scope_sql( $event_ids, 'r.order_id' ); // A650: only orders entirely in scope.
	}

	/**
	 * Number of refunds waiting for approval, for the menu badge on every admin page (A369): one
	 * COUNT(*) on the status index (KEY status_updated) instead of loading up to 200 rows with
	 * order data and event names. Same rows as pending_refunds(), capped at 200 like the list.
	 *
	 * @param int[]|null $event_ids Event scope (null = all).
	 */
	public static function pending_refunds_count( $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = self::pending_refund_scope( $event_ids );
		if ( ' AND 1=0' === $scope ) { return 0; }
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT r.refund_id FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE r.status='pending_approval'{$scope} LIMIT 200) pending" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer scope.
	}

	/** Refunds waiting for approval (scoped by the order's events). */
	public static function pending_refunds( $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = self::pending_refund_scope( $event_ids );
		return $wpdb->get_results( "SELECT r.*,ord.public_id AS order_public_id,ord.customer_email,ord.payment_provider,(SELECT GROUP_CONCAT(DISTINCT e.name SEPARATOR ', ') FROM {$p}wpcap_order_items ei INNER JOIN {$p}wpcap_ticket_types et ON et.ticket_type_id=ei.ticket_type_id INNER JOIN {$p}wpcap_occurrences eo ON eo.occurrence_id=et.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=eo.event_id WHERE ei.order_id=r.order_id) AS event_names FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE r.status='pending_approval'{$scope} ORDER BY r.created_at, r.refund_id LIMIT 200" );
	}
}
