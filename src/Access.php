<?php
/**
 * Central access checks: capability + event staff assignment + optional per-user scope.
 *
 * Layers (all must pass; administrators with manage_options skip layers 2 and 3):
 *  1. capability of the role (current_user_can),
 *  2. event assignment in wpcap_event_staff for that capability,
 *  3. optional scope of the user (A119, opt-in, user meta `wpcap_access_scope`):
 *     - locations: only occurrences whose location (occurrences.location, compared trimmed and
 *       case-insensitive) is listed; an event is reachable when at least one of its occurrences is,
 *       occurrence-level checks (orders, tickets, check-ins, lists, exports) use the exact location;
 *     - partners: only events linked to one of the listed partners (wpcap_partner_events);
 *       `linked_partners` adds the partners the user is linked to (wpcap_partner_users).
 *     Users without scope keep the previous behaviour. Filter `wpcap_access_scope`.
 *
 * @package PassCaptain
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Access {
	/** User meta with the scope: ['locations' => string[], 'partners' => int[], 'linked_partners' => bool]. */
	const SCOPE_META = 'wpcap_access_scope';

	/** Whether the user holds the capability (current user through current_user_can()). */
	public static function user_has( $user_id, $capability ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) { return false; }
		return $user_id === (int) get_current_user_id() ? current_user_can( $capability ) : user_can( $user_id, $capability );
	}

	/** Administrators (manage_options) are never scoped. */
	public static function is_global( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		return self::user_has( $user_id, 'manage_options' );
	}

	public static function can_access_event( $event_id, $capability ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( $capability ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			// Administrators: no assignment needed; event_in_scope() is true for them (is_global()).
			return self::event_in_scope( $event_id, $user_id );
		}
		global $wpdb;
		$assigned = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id = %d AND user_id = %d AND capability = %s LIMIT 1",
				absint( $event_id ),
				$user_id,
				sanitize_key( $capability )
			)
		);
		return $assigned && self::event_in_scope( $event_id, $user_id );
	}

	/**
	 * Occurrence-level check: event access for the capability and the occurrence inside the user's
	 * location/partner scope. Use this wherever the occurrence is known (tickets, check-ins, orders).
	 */
	public static function can_access_occurrence( $occurrence_id, $capability ) {
		$occurrence = self::occurrence_row( $occurrence_id );
		if ( ! $occurrence ) { return false; }
		return self::can_access_event( (int) $occurrence->event_id, $capability ) && self::occurrence_in_scope( $occurrence_id );
	}

	/**
	 * Normalised scope of a user or null (no restriction).
	 *
	 * @return array{locations: string[], partners: int[]|null}|null
	 */
	public static function scope( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		if ( ! $user_id || self::is_global( $user_id ) ) { return null; }
		$raw = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, self::SCOPE_META, true ) : array();
		$scope = self::normalize( is_array( $raw ) ? $raw : array() );
		if ( ! empty( $scope['linked_partners'] ) ) {
			$linked = Privacy::has_table( 'wpcap_partner_users' ) ? Partners\Repository::partners_of_user( $user_id ) : array();
			$scope['partners'] = array_values( array_unique( array_merge( (array) $scope['partners'], $linked ) ) );
		}
		unset( $scope['linked_partners'] );
		/**
		 * Scope of a non-administrator (A119). Return ['locations' => string[], 'partners' => int[]|null];
		 * an empty location list and partners null mean "no restriction".
		 *
		 * @param array $scope   Normalised scope.
		 * @param int   $user_id User.
		 */
		$scope = apply_filters( 'wpcap_access_scope', $scope, $user_id );
		$scope = is_array( $scope ) ? $scope : array();
		$locations = self::normalize( array( 'locations' => $scope['locations'] ?? array() ) )['locations'];
		$partners = isset( $scope['partners'] ) && is_array( $scope['partners'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $scope['partners'] ) ) ) ) : null;
		if ( ! $locations && null === $partners ) { return null; }
		return array( 'locations' => $locations, 'partners' => $partners );
	}

	/**
	 * Cleans scope input (user profile form, user meta).
	 *
	 * @param array $input locations (string[] or text with one location per line), partners (int[]),
	 *                     linked_partners (bool).
	 * @return array{locations: string[], partners: int[]|null, linked_partners: bool}
	 */
	public static function normalize( array $input ) {
		$locations = $input['locations'] ?? array();
		if ( is_string( $locations ) ) { $locations = preg_split( '/\r\n|\r|\n/', $locations ); }
		$clean = array();
		foreach ( (array) $locations as $location ) {
			$location = mb_substr( trim( sanitize_text_field( (string) $location ) ), 0, 190 );
			if ( '' !== $location ) { $clean[ self::location_key( $location ) ] = $location; }
		}
		$partners = null;
		if ( isset( $input['partners'] ) && is_array( $input['partners'] ) && $input['partners'] ) {
			$partners = array_values( array_unique( array_filter( array_map( 'absint', $input['partners'] ) ) ) );
			$partners = $partners ? $partners : null;
		}
		$linked = ! empty( $input['linked_partners'] );
		if ( $linked && null === $partners ) { $partners = array(); }
		return array( 'locations' => array_values( $clean ), 'partners' => $partners, 'linked_partners' => $linked );
	}

	/** Stores the scope of a user (empty input removes it). Only administrators may call this. */
	public static function set_scope( $user_id, array $input ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! current_user_can( 'manage_options' ) ) { return false; }
		$scope = self::normalize( $input );
		if ( ! $scope['locations'] && null === $scope['partners'] && ! $scope['linked_partners'] ) {
			if ( function_exists( 'delete_user_meta' ) ) { delete_user_meta( $user_id, self::SCOPE_META ); } else { update_user_meta( $user_id, self::SCOPE_META, array() ); }
		} else {
			update_user_meta( $user_id, self::SCOPE_META, $scope );
		}
		Audit::record( 'access_scope_set', 'user', $user_id, $scope );
		return true;
	}

	/** Comparison key of a location: trimmed, case-insensitive. */
	public static function location_key( $location ) {
		return mb_strtolower( trim( (string) $location ) );
	}

	/** Whether the event lies in the user's scope (true without scope). */
	public static function event_in_scope( $event_id, $user_id = 0 ) {
		$scope = self::scope( $user_id );
		if ( null === $scope ) { return true; }
		$event_id = absint( $event_id );
		if ( ! $event_id || ! self::partner_ok( $event_id, $scope ) ) { return false; }
		if ( ! $scope['locations'] ) { return true; }
		global $wpdb;
		list( $sql, $args ) = self::location_clause( 'location', $scope['locations'] );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d AND $sql LIMIT 1", array_merge( array( $event_id ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
	}

	/** Whether the occurrence lies in the user's scope (true without scope). */
	public static function occurrence_in_scope( $occurrence_id, $user_id = 0 ) {
		$scope = self::scope( $user_id );
		if ( null === $scope ) { return true; }
		$occurrence = self::occurrence_row( $occurrence_id );
		if ( ! $occurrence || ! self::partner_ok( (int) $occurrence->event_id, $scope ) ) { return false; }
		if ( ! $scope['locations'] ) { return true; }
		return in_array( self::location_key( $occurrence->location ), array_map( array( __CLASS__, 'location_key' ), $scope['locations'] ), true );
	}

	/** Whether a location may be used by the user (true without location scope). */
	public static function location_in_scope( $location, $user_id = 0 ) {
		$scope = self::scope( $user_id );
		if ( null === $scope || ! $scope['locations'] ) { return true; }
		return in_array( self::location_key( $location ), array_map( array( __CLASS__, 'location_key' ), $scope['locations'] ), true );
	}

	/** Whether every occurrence of the event lies in the user's scope (event-wide figures; true without scope). */
	public static function event_fully_in_scope( $event_id, $user_id = 0 ) {
		if ( null === self::scope( $user_id ) ) { return true; }
		if ( ! self::event_in_scope( $event_id, $user_id ) ) { return false; }
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d", absint( $event_id ) ) ) as $occurrence_id ) {
			if ( ! self::occurrence_in_scope( (int) $occurrence_id, $user_id ) ) { return false; }
		}
		return true;
	}

	/** Whether every occurrence of the order lies in the user's scope (orders without items: only unscoped users). */
	public static function order_in_scope( $order_id, $user_id = 0 ) {
		if ( null === self::scope( $user_id ) ) { return true; }
		$occurrences = self::order_occurrence_ids( $order_id );
		if ( ! $occurrences ) { return false; }
		foreach ( $occurrences as $occurrence_id ) {
			if ( ! self::occurrence_in_scope( $occurrence_id, $user_id ) ) { return false; }
		}
		return true;
	}

	/** Distinct occurrence ids an order has items for. */
	public static function order_occurrence_ids( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tt.occurrence_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d", absint( $order_id ) ) ) );
	}

	/**
	 * Restricts an event id list to the current user's scope.
	 *
	 * @param int[]|null $event_ids Null = all events (then every event in scope is returned).
	 * @return int[]|null Null only when the user has no scope and the input was null.
	 */
	public static function filter_event_ids( $event_ids, $user_id = 0 ) {
		$scope = self::scope( $user_id );
		if ( null === $scope ) { return $event_ids; }
		if ( is_array( $event_ids ) && ! $event_ids ) { return array(); }
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '1=1';
		$args = array();
		if ( is_array( $event_ids ) ) { $where .= ' AND e.event_id IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ')'; }
		if ( null !== $scope['partners'] ) {
			if ( ! $scope['partners'] || ! Privacy::has_table( 'wpcap_partner_events' ) ) { return array(); }
			$where .= " AND e.event_id IN (SELECT pe.event_id FROM {$p}wpcap_partner_events pe WHERE pe.partner_id IN (" . implode( ',', array_map( 'absint', $scope['partners'] ) ) . '))';
		}
		if ( $scope['locations'] ) {
			list( $sql, $loc_args ) = self::location_clause( 'o.location', $scope['locations'] );
			$where .= " AND EXISTS (SELECT 1 FROM {$p}wpcap_occurrences o WHERE o.event_id=e.event_id AND $sql)";
			$args = array_merge( $args, $loc_args );
		}
		$query = "SELECT e.event_id FROM {$p}wpcap_events e WHERE $where";
		return array_map( 'absint', $wpdb->get_col( $args ? $wpdb->prepare( $query, $args ) : $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids cast, values as placeholders.
	}

	/**
	 * SQL fragment (" AND alias.occurrence_id IN (…)", integers only, so it can be embedded in
	 * queries that are prepared afterwards) restricting an occurrences alias to the user's scope;
	 * '' without scope. Used by admin lists that join wpcap_occurrences.
	 */
	public static function occurrence_scope_sql( $alias = 'o', $user_id = 0 ) {
		$scope = self::scope( $user_id );
		if ( null === $scope ) { return ''; }
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		$ids = self::scoped_occurrence_ids( $scope );
		return $ids ? " AND {$alias}.occurrence_id IN (" . implode( ',', $ids ) . ')' : ' AND 1=0';
	}

	/** Occurrence ids inside a (non-null) scope. */
	private static function scoped_occurrence_ids( array $scope ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '1=1';
		$args = array();
		if ( null !== $scope['partners'] ) {
			if ( ! $scope['partners'] || ! Privacy::has_table( 'wpcap_partner_events' ) ) { return array(); }
			$where .= " AND o.event_id IN (SELECT pe.event_id FROM {$p}wpcap_partner_events pe WHERE pe.partner_id IN (" . implode( ',', array_map( 'absint', $scope['partners'] ) ) . '))';
		}
		if ( $scope['locations'] ) {
			list( $clause, $args ) = self::location_clause( 'o.location', $scope['locations'] );
			$where .= ' AND ' . $clause;
		}
		$query = "SELECT o.occurrence_id FROM {$p}wpcap_occurrences o WHERE $where";
		return array_map( 'absint', $wpdb->get_col( $args ? $wpdb->prepare( $query, $args ) : $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids cast, values as placeholders.
	}

	/** [sql with placeholders, args] for "column in one of the locations" (trimmed, case-insensitive). */
	private static function location_clause( $column, array $locations ) {
		$keys = array_values( array_unique( array_map( array( __CLASS__, 'location_key' ), $locations ) ) );
		return array( 'LOWER(TRIM(' . $column . ')) IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys );
	}

	private static function partner_ok( $event_id, array $scope ) {
		if ( null === $scope['partners'] ) { return true; }
		if ( ! $scope['partners'] || ! Privacy::has_table( 'wpcap_partner_events' ) ) { return false; }
		global $wpdb;
		$partner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT partner_id FROM {$wpdb->prefix}wpcap_partner_events WHERE event_id=%d", absint( $event_id ) ) );
		return $partner && in_array( $partner, $scope['partners'], true );
	}

	private static function occurrence_row( $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,event_id,location FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
	}
}
