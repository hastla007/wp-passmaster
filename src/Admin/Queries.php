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
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $caps ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_event_staff WHERE user_id=%d AND capability IN ($placeholders)", array_merge( array( get_current_user_id() ), $caps ) ) );
		// Location/partner scope of the user (A119; no-op without scope).
		return Access::filter_event_ids( array_map( 'absint', $ids ) );
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
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $event_id ) ) );
	}

	public static function occurrence( $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
	}

	public static function occurrences_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at, occurrence_id", absint( $event_id ) ) );
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
		$now = time();
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
		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.*,o.event_id,o.timezone,o.starts_at AS occurrence_starts_at FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", absint( $ticket_type_id ) ) );
	}

	public static function ticket_types_for_occurrence( $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id", absint( $occurrence_id ) ) );
	}

	/** Participant field with event context. */
	public static function participant_field( $field_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT f.*,o.event_id FROM {$p}wpcap_participant_fields f INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=f.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE f.field_id=%d", absint( $field_id ) ) );
	}

	public static function participant_fields( $ticket_type_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d ORDER BY position, field_id", absint( $ticket_type_id ) ) );
	}

	public static function staff_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id=%d ORDER BY user_id, capability", absint( $event_id ) ) );
	}

	/** Tickets issued (valid or checked in) for an occurrence. */
	public static function issued_ticket_count_for_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status IN ('valid','checked_in')", absint( $occurrence_id ) ) );
	}

	/** Sold units of an occurrence (paid, not refunded) – same basis as the capacity checks in Orders. */
	public static function sold_for_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", absint( $occurrence_id ) ) );
		/** Sold units shown for an occurrence; modules add units counted there (e.g. passes anchored elsewhere). */
		return (int) apply_filters( 'wpcap_occurrence_sold', $sold, absint( $occurrence_id ) );
	}

	/** Sold units of a ticket type (paid, not refunded). */
	public static function sold_for_type( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", absint( $ticket_type_id ) ) );
	}

	/** Whether any order (in any state) references the ticket type. */
	public static function type_has_orders( $ticket_type_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_order_items WHERE ticket_type_id=%d LIMIT 1", absint( $ticket_type_id ) ) );
	}

	/**
	 * Events overview: exactly one row per event with occurrence count and next upcoming occurrence.
	 *
	 * @param int[]|null $event_ids Scope (null = all).
	 * @return array{rows: object[], total: int}
	 */
	public static function events_overview( $event_ids, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = self::scope_sql( $event_ids, 'e.event_id' );
		$now = current_time( 'mysql', true );
		$offset = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events e WHERE 1=1{$scope}" );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.slug,e.status,e.created_at,
				(SELECT COUNT(*) FROM {$p}wpcap_occurrences oc WHERE oc.event_id=e.event_id) AS occurrence_count,
				(SELECT o1.occurrence_id FROM {$p}wpcap_occurrences o1 WHERE o1.event_id=e.event_id AND o1.status='scheduled' AND o1.starts_at >= %s ORDER BY o1.starts_at, o1.occurrence_id LIMIT 1) AS next_occurrence_id,
				(SELECT o2.occurrence_id FROM {$p}wpcap_occurrences o2 WHERE o2.event_id=e.event_id ORDER BY o2.starts_at DESC, o2.occurrence_id DESC LIMIT 1) AS last_occurrence_id
			FROM {$p}wpcap_events e WHERE 1=1{$scope} ORDER BY e.created_at DESC, e.event_id DESC LIMIT %d OFFSET %d",
			$now,
			$per_page,
			$offset
		) );
		$occ_ids = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'next_occurrence_id', 'last_occurrence_id' ) as $col ) {
				if ( $row->$col ) { $occ_ids[] = (int) $row->$col; }
			}
		}
		$occurrences = array();
		if ( $occ_ids ) {
			foreach ( $wpdb->get_results( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id IN (" . implode( ',', array_unique( $occ_ids ) ) . ')' ) as $occ ) {
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

	/* --------------------------------------------------------------------------------------------- */

	/** Distinct event ids an order has items for. */
	public static function order_event_ids( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", absint( $order_id ) ) ) );
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
	 * Order search with filters.
	 *
	 * @param array      $filters   status, payment_status, search (public id prefix or e-mail part), event_id.
	 * @param int[]|null $event_ids Scope.
	 * @return array{rows: object[], total: int}
	 */
	public static function search_orders( array $filters, $event_ids, $page = 1, $per_page = 20 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '1=1';
		$args = array();
		if ( ! empty( $filters['status'] ) ) { $where .= ' AND ord.status=%s'; $args[] = (string) $filters['status']; }
		if ( ! empty( $filters['payment_status'] ) ) { $where .= ' AND ord.payment_status=%s'; $args[] = (string) $filters['payment_status']; }
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$search = trim( (string) $filters['search'] );
			$where .= ' AND (ord.public_id LIKE %s OR ord.customer_email LIKE %s)';
			// Short order numbers / payment references ("Bestellung 3F2A-9C1E") are public id prefixes.
			$id_prefix = preg_match( '/^(?:bestellung\s+)?([a-f0-9]{4})[\s-]?([a-f0-9]{4})$/i', $search, $m ) ? $m[1] . $m[2] : $search;
			$args[] = self::like( strtolower( $id_prefix ) ) . '%';
			$args[] = '%' . self::like( $search ) . '%';
		}
		$items_join = "SELECT 1 FROM {$p}wpcap_order_items fi INNER JOIN {$p}wpcap_ticket_types ft ON ft.ticket_type_id=fi.ticket_type_id INNER JOIN {$p}wpcap_occurrences fo ON fo.occurrence_id=ft.occurrence_id WHERE fi.order_id=ord.order_id";
		if ( ! empty( $filters['event_id'] ) ) {
			$where .= " AND EXISTS ($items_join AND fo.event_id=%d)";
			$args[] = absint( $filters['event_id'] );
		}
		if ( null !== $event_ids ) {
			$where .= $event_ids ? " AND EXISTS ($items_join" . self::scope_sql( $event_ids, 'fo.event_id' ) . Access::occurrence_scope_sql( 'fo' ) . ')' : ' AND 1=0';
		}
		$count_sql = "SELECT COUNT(*) FROM {$p}wpcap_orders ord WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$list_args = array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ord.*,(SELECT GROUP_CONCAT(DISTINCT e.name ORDER BY e.name SEPARATOR ', ') FROM {$p}wpcap_order_items ei INNER JOIN {$p}wpcap_ticket_types et ON et.ticket_type_id=ei.ticket_type_id INNER JOIN {$p}wpcap_occurrences eo ON eo.occurrence_id=et.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=eo.event_id WHERE ei.order_id=ord.order_id) AS event_names FROM {$p}wpcap_orders ord WHERE $where ORDER BY ord.created_at DESC, ord.order_id DESC LIMIT %d OFFSET %d",
			$list_args
		) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Order items with tax, event and occurrence context. */
	public static function order_items( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( "SELECT oi.*,tt.name,e.name AS event_name,o.starts_at,o.timezone FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", absint( $order_id ) ) );
	}

	/** Tickets of an order with attendee name/e-mail and participant answers (label => answer). */
	public static function order_tickets( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.attendee_email,t.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,o.event_id,o.starts_at,o.timezone,ta.attendee_name,ta.attendee_email AS assigned_email FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id", absint( $order_id ) ) );
		if ( ! $tickets ) { return array(); }
		$ids = implode( ',', array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
		$answers = array();
		foreach ( $wpdb->get_results( "SELECT a.ticket_id,a.answer,f.label,f.field_type FROM {$p}wpcap_participant_answers a INNER JOIN {$p}wpcap_participant_fields f ON f.field_id=a.field_id WHERE a.ticket_id IN ($ids) ORDER BY f.position,f.field_id" ) as $answer ) {
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
		return $wpdb->get_row( $wpdb->prepare( "SELECT t.*,tt.name AS ticket_name,tt.occurrence_id,o.event_id,o.starts_at,o.timezone,ord.public_id AS order_public_id,ord.customer_email FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
	}

	/**
	 * Ticket search.
	 *
	 * @param array      $filters   search (ticket/order public id prefix, buyer or attendee e-mail/name part), event_id, status.
	 * @param int[]|null $event_ids Scope.
	 * @return array{rows: object[], total: int}
	 */
	public static function search_tickets( array $filters, $event_ids, $page = 1, $per_page = 30 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$from = "FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id";
		$where = '1=1' . self::scope_sql( $event_ids, 'o.event_id' ) . Access::occurrence_scope_sql( 'o' );
		$args = array();
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$search = trim( (string) $filters['search'] );
			$prefix = self::like( strtolower( $search ) ) . '%';
			$part = '%' . self::like( $search ) . '%';
			// Only fields the user may see are searchable (A119: no probing of masked e-mails/names).
			$columns = array( 't.public_id' => $prefix, 'ord.public_id' => $prefix );
			if ( \PassCaptain\Pii::can_view( 'buyer_email' ) ) { $columns['ord.customer_email'] = $part; }
			if ( \PassCaptain\Pii::can_view( 'attendee_email' ) ) { $columns['t.attendee_email'] = $part; $columns['ta.attendee_email'] = $part; }
			if ( \PassCaptain\Pii::can_view( 'attendee_name' ) ) { $columns['ta.attendee_name'] = $part; }
			$where .= ' AND (' . implode( ' OR ', array_map( static function ( $column ) { return $column . ' LIKE %s'; }, array_keys( $columns ) ) ) . ')';
			array_push( $args, ...array_values( $columns ) );
		}
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND o.event_id=%d'; $args[] = absint( $filters['event_id'] ); }
		if ( ! empty( $filters['status'] ) ) { $where .= ' AND t.status=%s'; $args[] = (string) $filters['status']; }
		$count_sql = "SELECT COUNT(*) $from WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.attendee_email,t.issued_at,t.order_id,ord.public_id AS order_public_id,ord.customer_email,ta.attendee_name,ta.attendee_email AS assigned_email,tt.name AS ticket_name,o.event_id,o.starts_at,o.timezone,e.name AS event_name,(SELECT MAX(c.created_at) FROM {$p}wpcap_checkins c WHERE c.ticket_id=t.ticket_id AND c.result='checked_in') AS last_checkin_at $from WHERE $where ORDER BY t.ticket_id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
		) );
		return array( 'rows' => $rows, 'total' => $total );
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
		$from = "FROM {$p}wpcap_checkins c LEFT JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_events e ON e.event_id=c.event_id";
		$where = '1=1' . self::scope_sql( $event_ids, 'c.event_id' ) . Access::occurrence_scope_sql( 'o' );
		$args = array();
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND c.event_id=%d'; $args[] = absint( $filters['event_id'] ); }
		if ( ! empty( $filters['result'] ) ) { $where .= ' AND c.result=%s'; $args[] = (string) $filters['result']; }
		if ( ! empty( $filters['date_from'] ) ) { $where .= ' AND c.created_at >= %s'; $args[] = (string) $filters['date_from']; }
		if ( ! empty( $filters['date_to'] ) ) { $where .= ' AND c.created_at < %s'; $args[] = (string) $filters['date_to']; }
		$count_sql = "SELECT COUNT(*) $from WHERE $where";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.*,t.public_id AS ticket_public_id,t.order_id,tt.name AS ticket_name,o.timezone,e.name AS event_name $from WHERE $where ORDER BY c.created_at DESC, c.checkin_id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
		) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Distinct check-in results present in the log. */
	public static function checkin_results() {
		global $wpdb;
		$results = $wpdb->get_col( "SELECT DISTINCT result FROM {$wpdb->prefix}wpcap_checkins ORDER BY result" );
		return array_values( array_unique( array_merge( array( 'checked_in', 'undone' ), $results ) ) );
	}

	/** Refunds waiting for approval (scoped by the order's events). */
	public static function pending_refunds( $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = '';
		if ( null !== $event_ids ) {
			$scope = $event_ids ? " AND EXISTS (SELECT 1 FROM {$p}wpcap_order_items fi INNER JOIN {$p}wpcap_ticket_types ft ON ft.ticket_type_id=fi.ticket_type_id INNER JOIN {$p}wpcap_occurrences fo ON fo.occurrence_id=ft.occurrence_id WHERE fi.order_id=r.order_id" . self::scope_sql( $event_ids, 'fo.event_id' ) . ')' : ' AND 1=0';
		}
		return $wpdb->get_results( "SELECT r.*,ord.public_id AS order_public_id,ord.customer_email,ord.payment_provider,(SELECT GROUP_CONCAT(DISTINCT e.name SEPARATOR ', ') FROM {$p}wpcap_order_items ei INNER JOIN {$p}wpcap_ticket_types et ON et.ticket_type_id=ei.ticket_type_id INNER JOIN {$p}wpcap_occurrences eo ON eo.occurrence_id=et.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=eo.event_id WHERE ei.order_id=r.order_id) AS event_names FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE r.status='pending_approval'{$scope} ORDER BY r.created_at, r.refund_id LIMIT 200" );
	}
}
