<?php
/**
 * Narrow staff assignments below the event level (table wpcap_staff_assignments).
 *
 * Scope "occurrence" (A144): a member of the event team (wpcap_event_staff) is limited to single
 * occurrences of the event for one capability (check-in, orders, event management, reports). As
 * long as such an assignment exists for (person, event, capability), every occurrence-level check
 * of that capability only admits the assigned occurrences (Access::can_access_occurrence(),
 * occurrence_in_scope(), order_in_scope(), occurrence_scope_sql(), event_fully_in_scope()).
 * Without one the person keeps the whole event. Removing the event-team entry removes them, too.
 *
 * Entry areas use the existing area staff of the admission module (wpcap_admission_area_staff,
 * Admission\Areas::check()); services use the provider records of A133 (ServiceProviders, a record
 * linked to a WordPress user gives access to its resources). The admin page "Zuweisungen" shows all
 * three kinds per event.
 *
 * Assignments are managed by people with wpcap_manage_events for the event (for occurrences: for
 * that occurrence); every change is audited (staff_assignment_added / staff_assignment_removed).
 *
 * @package PassCaptain
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StaffAssignments {
	/** Capability needed to manage assignments of an event. */
	const MANAGE_CAP = 'wpcap_manage_events';
	/** Scope => capabilities an assignment of that scope may carry. */
	const SCOPES = array(
		'occurrence' => array( 'wpcap_checkin', 'wpcap_manage_orders', 'wpcap_manage_events', 'wpcap_view_reports' ),
	);

	/** Per-request cache: user id => scope => capability => object id => event id. */
	private static $cache = array();
	/** Per-request cache: "user|event" => event-team capabilities. */
	private static $team = array();

	/** Whether the table exists (schema 3.10.6). */
	public static function ready() {
		return Privacy::has_table( 'wpcap_staff_assignments' );
	}

	/** Forgets the per-request cache (after changes, tests). */
	public static function flush() {
		self::$cache = array();
		self::$team = array();
	}

	/** Resolves a user by id, login or e-mail (null when unknown). */
	public static function find_user( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref || ! function_exists( 'get_user_by' ) ) { return null; }
		if ( ctype_digit( $ref ) ) { return get_user_by( 'id', absint( $ref ) ) ?: null; }
		if ( is_email( $ref ) ) { return get_user_by( 'email', $ref ) ?: null; }
		return get_user_by( 'login', sanitize_text_field( $ref ) ) ?: null;
	}

	/** Event id of an assignable object (0 when it does not exist). */
	public static function object_event( $scope, $object_id ) {
		global $wpdb;
		$object_id = absint( $object_id );
		if ( 'occurrence' === $scope ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $object_id ) );
		}
		return 0;
	}

	/**
	 * Object ids of a scope the user is assigned to for the capability.
	 *
	 * @return int[]
	 */
	public static function object_ids( $user_id, $scope, $capability ) {
		return array_keys( self::rows( $user_id )[ $scope ][ $capability ] ?? array() );
	}

	/** All assignments of a user: scope => capability => object id => event id (cached per request). */
	private static function rows( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! self::ready() ) { return array(); }
		if ( ! isset( self::$cache[ $user_id ] ) ) {
			global $wpdb;
			self::$cache[ $user_id ] = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT scope,object_id,event_id,capability FROM {$wpdb->prefix}wpcap_staff_assignments WHERE user_id=%d", $user_id ) ) as $row ) {
				self::$cache[ $user_id ][ $row->scope ][ $row->capability ][ (int) $row->object_id ] = (int) $row->event_id;
			}
		}
		return self::$cache[ $user_id ];
	}

	/** Whether the user has any occurrence assignment (cheap pre-check for the access layer). */
	public static function has_occurrence_limits( $user_id ) {
		return ! empty( self::rows( $user_id )['occurrence'] );
	}

	/** Event-team capabilities of the user for the event (wpcap_event_staff). */
	public static function team_caps( $user_id, $event_id ) {
		$key = absint( $user_id ) . '|' . absint( $event_id );
		if ( ! isset( self::$team[ $key ] ) ) {
			global $wpdb;
			self::$team[ $key ] = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT capability FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id=%d AND user_id=%d", absint( $event_id ), absint( $user_id ) ) ) );
		}
		return self::$team[ $key ];
	}

	/**
	 * Occurrences of the event the user is limited to (A144), or null when not limited.
	 *
	 * With a capability: the occurrence assignments of exactly that capability (none = not limited).
	 * Without (capability-free checks such as lists and exports): the union over the person's
	 * event-team capabilities – limited only when every one of them is limited.
	 *
	 * @return int[]|null
	 */
	public static function limited_occurrences( $user_id, $event_id, $capability = null ) {
		$by_cap = self::rows( $user_id )['occurrence'] ?? array();
		if ( ! $by_cap ) { return null; }
		$event_id = absint( $event_id );
		$of_event = static function ( $cap ) use ( $by_cap, $event_id ) {
			return array_keys( array_filter( $by_cap[ $cap ] ?? array(), static function ( $ev ) use ( $event_id ) { return $ev === $event_id; } ) );
		};
		if ( null !== $capability ) {
			$ids = $of_event( (string) $capability );
			return $ids ? $ids : null;
		}
		$caps = self::team_caps( $user_id, $event_id );
		if ( ! $caps ) { return null; }
		$union = array();
		foreach ( $caps as $cap ) {
			$ids = $of_event( $cap );
			if ( ! $ids ) { return null; }
			$union = array_merge( $union, $ids );
		}
		return array_values( array_unique( $union ) );
	}

	/** Whether the user is assigned to the object for the capability. */
	public static function has( $user_id, $scope, $object_id, $capability ) {
		return in_array( absint( $object_id ), self::object_ids( $user_id, $scope, $capability ), true );
	}

	/**
	 * Assignments of an event (optionally of one scope), with user label.
	 *
	 * @return object[] assignment_id, user_id, event_id, scope, object_id, capability, created_at
	 */
	public static function for_event( $event_id, $scope = '' ) {
		if ( ! self::ready() ) { return array(); }
		global $wpdb;
		$scope_sql = '' !== $scope ? $wpdb->prepare( ' AND scope=%s', sanitize_key( $scope ) ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $scope_sql is prepared above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_staff_assignments WHERE event_id=%d$scope_sql ORDER BY scope,object_id,user_id,capability", absint( $event_id ) ) );
	}

	/** One assignment row, or null. */
	public static function get( $assignment_id ) {
		if ( ! self::ready() ) { return null; }
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_staff_assignments WHERE assignment_id=%d", absint( $assignment_id ) ) );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Whether the current user may manage assignments of the object (event team with wpcap_manage_events). */
	public static function can_manage( $scope, $object_id ) {
		return 'occurrence' === $scope && Access::can_access_occurrence( $object_id, self::MANAGE_CAP );
	}

	/**
	 * Limits an event-team member to an occurrence for a capability. The person must already be in the
	 * event team for that capability (an assignment never grants access).
	 *
	 * @param string           $scope      Key of SCOPES.
	 * @param int              $object_id  Object of the scope.
	 * @param \WP_User|int|string $user    User object, id, login or e-mail.
	 * @param string           $capability Capability (default: first one of the scope).
	 * @return int|\WP_Error Assignment id.
	 */
	public static function add( $scope, $object_id, $user, $capability = '' ) {
		global $wpdb;
		$scope = sanitize_key( (string) $scope );
		if ( ! isset( self::SCOPES[ $scope ] ) || ! self::ready() ) { return self::error( 'wpcap_assignment_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) ); }
		$capability = '' === (string) $capability ? self::SCOPES[ $scope ][0] : sanitize_key( (string) $capability );
		if ( ! in_array( $capability, self::SCOPES[ $scope ], true ) ) { return self::error( 'wpcap_assignment_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) ); }
		$event_id = self::object_event( $scope, $object_id );
		if ( ! $event_id ) { return self::error( 'wpcap_assignment_object', __( 'Zuweisungsziel nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can_manage( $scope, $object_id ) ) { return self::error( 'wpcap_assignment_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$user = is_object( $user ) ? $user : self::find_user( (string) $user );
		if ( ! $user || empty( $user->ID ) ) { return self::error( 'wpcap_assignment_user', __( 'Benutzer nicht gefunden.', 'pass-captain' ) ); }
		if ( 'occurrence' === $scope ) {
			// Narrows an existing event-team entry; it never grants access on its own.
			if ( ! in_array( $capability, self::team_caps( (int) $user->ID, $event_id ), true ) ) {
				return self::error( 'wpcap_assignment_team', __( 'Die Person ist für dieses Recht nicht im Event-Team. Bitte zuerst beim Event unter „Personal und Zugriff“ zuweisen.', 'pass-captain' ) );
			}
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_staff_assignments (user_id,event_id,scope,object_id,capability,created_by,created_at) VALUES (%d,%d,%s,%d,%s,%d,%s)", (int) $user->ID, $event_id, $scope, absint( $object_id ), $capability, get_current_user_id(), current_time( 'mysql', true ) ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT assignment_id FROM {$wpdb->prefix}wpcap_staff_assignments WHERE user_id=%d AND scope=%s AND object_id=%d AND capability=%s", (int) $user->ID, $scope, absint( $object_id ), $capability ) );
		self::flush();
		Audit::record( 'staff_assignment_added', $scope, absint( $object_id ), array( 'event_id' => $event_id, 'user_id' => (int) $user->ID, 'capability' => $capability ) );
		return $id;
	}

	/** Removes an assignment (wpcap_manage_events for its event). */
	public static function remove( $assignment_id ) {
		global $wpdb;
		$row = self::get( $assignment_id );
		if ( ! $row ) { return self::error( 'wpcap_assignment_missing', __( 'Zuweisung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( (int) $row->object_id, self::MANAGE_CAP ) ) { return self::error( 'wpcap_assignment_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$wpdb->delete( $wpdb->prefix . 'wpcap_staff_assignments', array( 'assignment_id' => (int) $row->assignment_id ), array( '%d' ) );
		self::flush();
		Audit::record( 'staff_assignment_removed', (string) $row->scope, (int) $row->object_id, array( 'event_id' => (int) $row->event_id, 'user_id' => (int) $row->user_id, 'capability' => (string) $row->capability ) );
		return true;
	}

	/**
	 * Removes the occurrence assignments that narrow an event-team entry (called when the entry is
	 * removed); no permission check, callers check.
	 */
	public static function delete_for_team( $event_id, $user_id, $capability ) {
		if ( ! self::ready() ) { return 0; }
		global $wpdb;
		$n = (int) $wpdb->delete( $wpdb->prefix . 'wpcap_staff_assignments', array( 'event_id' => absint( $event_id ), 'user_id' => absint( $user_id ), 'scope' => 'occurrence', 'capability' => sanitize_key( $capability ) ), array( '%d', '%d', '%s', '%s' ) );
		self::flush();
		return $n;
	}

}
