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
		// Active time-limited support access (Security\SupportAccess) covers all events.
		foreach ( $caps as $cap ) {
			if ( \PassCaptain\Security\SupportAccess::covers( get_current_user_id(), $cap ) ) { return null; }
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_id FROM %i WHERE user_id=%d AND capability IN (' . implode( ',', array_fill( 0, count( $caps ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_event_staff', get_current_user_id() ), $caps ) ) );
		return array_map( 'absint', $ids );
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

	public static function occurrences_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d ORDER BY starts_at, occurrence_id', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ) ) );
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

	/** Sold units of an occurrence (paid, not refunded) – same basis as the capacity checks in Orders. */
	public static function sold_for_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_orders', absint( $occurrence_id ) ) );
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

	/**
	 * Events overview: exactly one row per event with occurrence count and next upcoming occurrence.
	 *
	 * @param int[]|null $event_ids Scope (null = all).
	 * @return array{rows: object[], total: int}
	 */
	public static function events_overview( $event_ids, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// The scope condition from scope_sql() lists integer event ids only (or is empty for all events).
		$where = '1=1' . self::scope_sql( $event_ids, 'e.event_id' );
		$now = current_time( 'mysql', true );
		$offset = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e WHERE {$where}", $p . 'wpcap_events' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: integer scope only.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.slug,e.status,e.created_at,
				(SELECT COUNT(*) FROM %i oc WHERE oc.event_id=e.event_id) AS occurrence_count,
				(SELECT o1.occurrence_id FROM %i o1 WHERE o1.event_id=e.event_id AND o1.status='scheduled' AND o1.starts_at >= %s ORDER BY o1.starts_at, o1.occurrence_id LIMIT 1) AS next_occurrence_id,
				(SELECT o2.occurrence_id FROM %i o2 WHERE o2.event_id=e.event_id ORDER BY o2.starts_at DESC, o2.occurrence_id DESC LIMIT 1) AS last_occurrence_id
			FROM %i e WHERE {$where} ORDER BY e.created_at DESC, e.event_id DESC LIMIT %d OFFSET %d",
			$p . 'wpcap_occurrences',
			$p . 'wpcap_occurrences',
			$now,
			$p . 'wpcap_occurrences',
			$p . 'wpcap_events',
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

	/** Event id => name for filter selects (scoped). */
	public static function event_options( $event_ids ) {
		global $wpdb;
		$where = '1=1' . self::scope_sql( $event_ids, 'event_id' );
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT event_id,name FROM %i WHERE {$where} ORDER BY name LIMIT 500", $wpdb->prefix . 'wpcap_events' ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: integer scope only.
			$out[ (int) $row->event_id ] = $row->name;
		}
		return $out;
	}

	/* --------------------------------------------------------------------------------------------- */

	/** Distinct event ids an order has items for. */
	public static function order_event_ids( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT o.event_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $order_id ) ) ) );
	}

	/**
	 * Whether the current user may act on an order: they need the capability for every event in it.
	 * Orders without items are reserved for global admins.
	 */
	public static function can_access_order( $order_id, $cap ) {
		if ( ! current_user_can( $cap ) ) { return false; }
		$events = self::order_event_ids( $order_id );
		if ( ! $events ) { return self::is_global_admin(); }
		foreach ( $events as $event_id ) {
			if ( ! Access::can_access_event( $event_id, $cap ) ) { return false; }
		}
		return true;
	}

	/**
	 * Order search with filters.
	 *
	 * @param array      $filters   status, payment_status, search (public id prefix, complete e-mail, e-mail prefix or "@domain";
	 *                              from 3 characters also attendee name prefix, billing first/last name or
	 *                              company prefix and ticket id prefix), event_id.
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
			if ( is_email( $search ) ) {
				// A complete address: exact match on the customer_email index (PERF-15).
				$where .= ' AND ord.customer_email=%s';
				$args[] = $search;
			} else {
				// Short order numbers / payment references ("Bestellung 3F2A-9C1E") are public id prefixes.
				// E-mail: prefix search ("anna", "anna@exa"), which can use the customer_email index; a
				// leading "@" searches by domain (suffix, full scan; docs/OPERATIONS.md). PERF-15.
				$id_prefix = preg_match( '/^(?:bestellung\s+)?([a-f0-9]{4})[\s-]?([a-f0-9]{4})$/i', $search, $m ) ? $m[1] . $m[2] : $search;
				$conditions = array( 'ord.public_id LIKE %s', 'ord.customer_email LIKE %s' );
				$args[] = self::like( strtolower( $id_prefix ) ) . '%';
				$args[] = '@' === $search[0] ? '%' . self::like( $search ) : self::like( $search ) . '%';
				if ( '@' !== $search[0] && mb_strlen( $search ) >= 3 ) {
					// Attendee name (prefix), billing first/last name or company (prefix inside the stored JSON,
					// matched in its JSON encoding so umlauts match too) and ticket id prefix.
					$conditions[] = "EXISTS (SELECT 1 FROM {$p}wpcap_tickets st INNER JOIN {$p}wpcap_ticket_assignments sa ON sa.ticket_id=st.ticket_id WHERE st.order_id=ord.order_id AND sa.attendee_name LIKE %s)";
					$args[] = self::like( $search ) . '%';
					$json = substr( (string) wp_json_encode( $search ), 0, -1 );
					foreach ( array( 'first_name', 'last_name', 'company' ) as $key ) {
						$conditions[] = 'ord.billing LIKE %s';
						$args[] = '%"' . $key . '":' . self::like( $json ) . '%';
					}
					if ( preg_match( '/^[a-f0-9]{6,32}$/i', $search ) ) {
						$conditions[] = "EXISTS (SELECT 1 FROM {$p}wpcap_tickets sp WHERE sp.order_id=ord.order_id AND sp.public_id LIKE %s)";
						$args[] = self::like( strtolower( $search ) ) . '%';
					}
				}
				$where .= ' AND (' . implode( ' OR ', $conditions ) . ')';
			}
		}
		$items_join = "SELECT 1 FROM {$p}wpcap_order_items fi INNER JOIN {$p}wpcap_ticket_types ft ON ft.ticket_type_id=fi.ticket_type_id INNER JOIN {$p}wpcap_occurrences fo ON fo.occurrence_id=ft.occurrence_id WHERE fi.order_id=ord.order_id";
		if ( ! empty( $filters['event_id'] ) ) {
			$where .= " AND EXISTS ($items_join AND fo.event_id=%d)";
			$args[] = absint( $filters['event_id'] );
		}
		if ( null !== $event_ids ) {
			$where .= $event_ids ? " AND EXISTS ($items_join" . self::scope_sql( $event_ids, 'fo.event_id' ) . ')' : ' AND 1=0';
		}
		// $where: literal conditions with placeholders for every value ($args), table names from $wpdb->prefix.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i ord WHERE $where", array_merge( array( $p . 'wpcap_orders' ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
		$list_args = array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_orders' ), $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
			"SELECT ord.*,(SELECT GROUP_CONCAT(DISTINCT e.name ORDER BY e.name SEPARATOR ', ') FROM %i ei INNER JOIN %i et ON et.ticket_type_id=ei.ticket_type_id INNER JOIN %i eo ON eo.occurrence_id=et.occurrence_id INNER JOIN %i e ON e.event_id=eo.event_id WHERE ei.order_id=ord.order_id) AS event_names FROM %i ord WHERE $where ORDER BY ord.created_at DESC, ord.order_id DESC LIMIT %d OFFSET %d",
			$list_args
		) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Order items with tax, event and occurrence context. */
	public static function order_items( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT oi.*,tt.name,e.name AS event_name,o.starts_at,o.timezone FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $order_id ) ) );
	}

	/** Tickets of an order with attendee name/e-mail and participant answers (label => answer). */
	public static function order_tickets( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tickets = $wpdb->get_results( $wpdb->prepare( 'SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.attendee_email,t.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,o.event_id,o.starts_at,o.timezone,ta.attendee_name,ta.attendee_email AS assigned_email FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_ticket_assignments', absint( $order_id ) ) );
		if ( ! $tickets ) { return array(); }
		$ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets );
		$answers = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT a.ticket_id,a.answer,f.label,f.field_type FROM %i a INNER JOIN %i f ON f.field_id=a.field_id WHERE a.ticket_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY f.position,f.field_id', array_merge( array( $p . 'wpcap_participant_answers', $p . 'wpcap_participant_fields' ), $ids ) ) ) as $answer ) {
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
	 * @param array      $filters   search (ticket/order public id prefix, buyer or attendee e-mail/name part), event_id, status.
	 * @param int[]|null $event_ids      Scope.
	 * @param int[]      $occurrence_ids Additional single dates in scope (date-level staff); ignored when $event_ids is null.
	 * @return array{rows: object[], total: int}
	 */
	public static function search_tickets( array $filters, $event_ids, $page = 1, $per_page = 30, array $occurrence_ids = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tables = array( $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_orders', $p . 'wpcap_ticket_assignments' );
		if ( null !== $event_ids && $occurrence_ids ) {
			// Events in scope or one of the assigned dates.
			$where = '1=1 AND (' . ( $event_ids ? 'o.event_id IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ') OR ' : '' ) . 'o.occurrence_id IN (' . implode( ',', array_map( 'absint', $occurrence_ids ) ) . '))';
		} else {
			$where = '1=1' . self::scope_sql( $event_ids, 'o.event_id' );
		}
		$args = array();
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$search = trim( (string) $filters['search'] );
			$prefix = self::like( strtolower( $search ) ) . '%';
			$part = '%' . self::like( $search ) . '%';
			$where .= ' AND (t.public_id LIKE %s OR ord.public_id LIKE %s OR ord.customer_email LIKE %s OR t.attendee_email LIKE %s OR ta.attendee_email LIKE %s OR ta.attendee_name LIKE %s)';
			array_push( $args, $prefix, $prefix, $part, $part, $part, $part );
		}
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND o.event_id=%d'; $args[] = absint( $filters['event_id'] ); }
		if ( ! empty( $filters['status'] ) ) { $where .= ' AND t.status=%s'; $args[] = (string) $filters['status']; }
		// $where: literal conditions with placeholders for every value ($args) and the integer scope.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id INNER JOIN %i ord ON ord.order_id=t.order_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE $where", array_merge( $tables, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
			"SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.attendee_email,t.issued_at,t.order_id,ord.public_id AS order_public_id,ord.customer_email,ta.attendee_name,ta.attendee_email AS assigned_email,tt.name AS ticket_name,o.event_id,o.occurrence_id,o.starts_at,o.timezone,e.name AS event_name,(SELECT MAX(c.created_at) FROM %i c WHERE c.ticket_id=t.ticket_id AND c.result='checked_in') AS last_checkin_at FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id INNER JOIN %i ord ON ord.order_id=t.order_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE $where ORDER BY t.ticket_id DESC LIMIT %d OFFSET %d",
			array_merge( array( $p . 'wpcap_checkins' ), $tables, $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
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
		$tables = array( $p . 'wpcap_checkins', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events' );
		$where = '1=1' . self::scope_sql( $event_ids, 'c.event_id' );
		$args = array();
		if ( ! empty( $filters['event_id'] ) ) { $where .= ' AND c.event_id=%d'; $args[] = absint( $filters['event_id'] ); }
		if ( ! empty( $filters['result'] ) ) { $where .= ' AND c.result=%s'; $args[] = (string) $filters['result']; }
		if ( ! empty( $filters['date_from'] ) ) { $where .= ' AND c.created_at >= %s'; $args[] = (string) $filters['date_from']; }
		if ( ! empty( $filters['date_to'] ) ) { $where .= ' AND c.created_at < %s'; $args[] = (string) $filters['date_to']; }
		// $where: literal conditions with placeholders for every value ($args) and the integer scope.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i t ON t.ticket_id=c.ticket_id LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i e ON e.event_id=c.event_id WHERE $where", array_merge( $tables, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array, built together with $where.
			"SELECT c.*,t.public_id AS ticket_public_id,t.order_id,tt.name AS ticket_name,o.timezone,e.name AS event_name FROM %i c LEFT JOIN %i t ON t.ticket_id=c.ticket_id LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i e ON e.event_id=c.event_id WHERE $where ORDER BY c.created_at DESC, c.checkin_id DESC LIMIT %d OFFSET %d",
			array_merge( $tables, $args, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
		) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Check-in results written by the core (filter options of the check-in log). */
	const CHECKIN_RESULTS = array( 'checked_in', 'checked_out', 'undone', 'already_checked_in', 'not_checked_in', 'revoked', 'unknown', 'wrong_event', 'wrong_occurrence', 'wrong_area', 'out_of_window', 'not_valid_today', 'daily_limit_reached', 'cancelled_event', 'invalid_signature', 'backdated_admission', 'offline_expired', 'forbidden' );

	/**
	 * Check-in results for the log filter: the known core results plus any other value present in the
	 * log (add-on policies). The DISTINCT scan over the whole log runs at most once a day (transient),
	 * not on every page view (PERF-8).
	 */
	public static function checkin_results() {
		global $wpdb;
		$extra = get_transient( 'wpcap_checkin_results' );
		if ( ! is_array( $extra ) ) {
			$extra = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT result FROM %i', $wpdb->prefix . 'wpcap_checkins' ) ) );
			set_transient( 'wpcap_checkin_results', $extra, DAY_IN_SECONDS );
		}
		$results = array_values( array_unique( array_merge( self::CHECKIN_RESULTS, $extra ) ) );
		sort( $results );
		return $results;
	}

	/** WHERE condition of pending_refunds() (literal, table names from the prefix, integer scope). */
	private static function pending_refunds_where( $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = "r.status='pending_approval'";
		if ( null !== $event_ids ) {
			$where .= $event_ids ? " AND EXISTS (SELECT 1 FROM {$p}wpcap_order_items fi INNER JOIN {$p}wpcap_ticket_types ft ON ft.ticket_type_id=fi.ticket_type_id INNER JOIN {$p}wpcap_occurrences fo ON fo.occurrence_id=ft.occurrence_id WHERE fi.order_id=r.order_id" . self::scope_sql( $event_ids, 'fo.event_id' ) . ')' : ' AND 1=0';
		}
		return $where;
	}

	/** Number of refunds waiting for approval (menu badge; COUNT instead of loading the list). */
	public static function pending_refunds_count( $event_ids ) {
		global $wpdb;
		$where = self::pending_refunds_where( $event_ids );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r WHERE {$where}", $wpdb->prefix . 'wpcap_refunds' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see pending_refunds_where().
	}

	/** Refunds waiting for approval (scoped by the order's events). */
	public static function pending_refunds( $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = self::pending_refunds_where( $event_ids );
		// $where: literal conditions, table names from $wpdb->prefix and the integer scope.
		return $wpdb->get_results( $wpdb->prepare( "SELECT r.*,ord.public_id AS order_public_id,ord.customer_email,ord.payment_provider,(SELECT GROUP_CONCAT(DISTINCT e.name SEPARATOR ', ') FROM %i ei INNER JOIN %i et ON et.ticket_type_id=ei.ticket_type_id INNER JOIN %i eo ON eo.occurrence_id=et.occurrence_id INNER JOIN %i e ON e.event_id=eo.event_id WHERE ei.order_id=r.order_id) AS event_names FROM %i r INNER JOIN %i ord ON ord.order_id=r.order_id WHERE {$where} ORDER BY r.created_at, r.refund_id LIMIT 200", $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_refunds', $p . 'wpcap_orders' ) );
	}
}
