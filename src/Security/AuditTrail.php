<?php
/**
 * Additional audit sources (W9):
 *  - changes of WordPress roles of users (set/add/remove role) when a plugin-relevant role is involved,
 *  - direct capability grants/revocations of plugin capabilities on users,
 *  - capability changes of plugin roles (role definitions),
 *  - read access to personal data by staff (order detail, ticket search, attendee lists, exports,
 *    staff REST reads) – rate limited: one entry per person, view and object every 15 minutes and at
 *    most `audit_read_limit_per_hour` entries per person and hour (then one "throttled" marker).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AuditTrail {
	/** Seconds in which repeated reads of the same object by the same person are aggregated. */
	const READ_WINDOW = 900;

	/** REST routes (regex on the route) whose successful responses contain personal data for staff. */
	const PII_ROUTES = array(
		'#^/wp-pass-captain/v1/tickets/(validate|check-in)#',
		'#^/wp-pass-captain/v1/admission/check-(in|out)#',
		'#^/wp-pass-captain/v1/app/scan/#',
		'#^/wp-pass-captain/v1/app/offline/package#',
		'#^/wp-pass-captain/v1/events/\d+/reports/#',
		'#^/wp-pass-captain/v1/member-passes#',
	);

	public static function register() {
		add_action( 'set_user_role', array( __CLASS__, 'on_set_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_role' ), 10, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_remove_role' ), 10, 2 );
		add_action( 'update_user_meta', array( __CLASS__, 'on_user_meta' ), 10, 4 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta_added' ), 10, 4 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ), 10, 1 );
		global $wpdb;
		if ( isset( $wpdb->prefix ) ) {
			add_action( 'update_option_' . $wpdb->prefix . 'user_roles', array( __CLASS__, 'roles_option_updated' ), 10, 2 );
		}
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_rest_response' ), 10, 3 );
		add_filter( 'wpcap_pre_send_download', array( __CLASS__, 'on_download' ), 1, 4 );
	}

	/**
	 * Every download of the admin (CSV exports, attendee lists, PDFs) is recorded; delivery is not
	 * changed (the filter value is passed through).
	 *
	 * @param mixed  $pre      Filter value (null = default delivery).
	 * @param string $body     Download body.
	 * @param string $filename File name.
	 * @param string $type     Content type.
	 */
	public static function on_download( $pre, $body = '', $filename = '', $type = '' ) {
		if ( get_current_user_id() ) {
			Audit::record( 'export_downloaded', 'download', 0, array( 'file' => preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $filename ), 'type' => sanitize_text_field( (string) $type ), 'bytes' => strlen( (string) $body ) ) );
		}
		return $pre;
	}

	/* ---------------------------------------------------------------- roles and capabilities */

	/** Plugin capabilities (prefix wpcap_) plus the WordPress capabilities that grant full plugin access. */
	public static function is_plugin_cap( $cap ) {
		return 0 === strpos( (string) $cap, 'wpcap_' ) || in_array( (string) $cap, array( 'manage_options' ), true );
	}

	/** Whether a role is a plugin role or grants plugin capabilities. */
	public static function is_plugin_role( $role ) {
		$role = (string) $role;
		if ( 0 === strpos( $role, 'wpcap_' ) || 'administrator' === $role ) { return true; }
		$object = function_exists( 'get_role' ) ? get_role( $role ) : null;
		if ( $object ) {
			foreach ( (array) $object->capabilities as $cap => $grant ) {
				if ( $grant && self::is_plugin_cap( $cap ) ) { return true; }
			}
		}
		return false;
	}

	/**
	 * @param int      $user_id   User.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 */
	public static function on_set_role( $user_id, $role, $old_roles = array() ) {
		$old_roles = array_values( array_map( 'strval', (array) $old_roles ) );
		$relevant = array_filter( array_merge( array( (string) $role ), $old_roles ), array( __CLASS__, 'is_plugin_role' ) );
		if ( ! $relevant || ( array( (string) $role ) === $old_roles ) ) { return; }
		Audit::record( 'user_role_changed', 'user', $user_id, array( 'role' => sanitize_key( (string) $role ), 'old_roles' => array_map( 'sanitize_key', $old_roles ) ) );
	}

	public static function on_add_role( $user_id, $role ) {
		if ( self::is_plugin_role( $role ) ) {
			Audit::record( 'user_role_added', 'user', $user_id, array( 'role' => sanitize_key( (string) $role ) ) );
		}
	}

	public static function on_remove_role( $user_id, $role ) {
		if ( self::is_plugin_role( $role ) ) {
			Audit::record( 'user_role_removed', 'user', $user_id, array( 'role' => sanitize_key( (string) $role ) ) );
		}
	}

	/** Meta key that stores a user's roles and direct capabilities on this site. */
	private static function caps_meta_key() {
		global $wpdb;
		$prefix = method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix() : (string) $wpdb->prefix;
		return $prefix . 'capabilities';
	}

	/**
	 * Direct capability grants/revocations (WP_User::add_cap/remove_cap). Role entries in the same
	 * meta are reported by the role hooks, so only non-role keys are compared here.
	 */
	public static function on_user_meta( $meta_id, $user_id, $meta_key, $new_value ) {
		if ( self::caps_meta_key() !== $meta_key ) { return; }
		$old = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, $meta_key, true ) : array();
		self::compare_user_caps( $user_id, is_array( $old ) ? $old : array(), is_array( $new_value ) ? $new_value : array() );
	}

	public static function on_user_meta_added( $meta_id, $user_id, $meta_key, $value ) {
		if ( self::caps_meta_key() !== $meta_key ) { return; }
		self::compare_user_caps( $user_id, array(), is_array( $value ) ? $value : array() );
	}

	/**
	 * Records granted/revoked plugin capabilities between two capability maps of a user.
	 *
	 * @return array{granted:string[],revoked:string[]}
	 */
	public static function compare_user_caps( $user_id, array $old_caps, array $new_caps ) {
		$roles = function_exists( 'wp_roles' ) ? array_keys( (array) wp_roles()->roles ) : array();
		$pick = static function ( array $caps ) use ( $roles ) {
			$out = array();
			foreach ( $caps as $cap => $grant ) {
				if ( in_array( (string) $cap, $roles, true ) || ! self::is_plugin_cap( $cap ) ) { continue; }
				$out[ (string) $cap ] = (bool) $grant;
			}
			return $out;
		};
		$before = $pick( $old_caps );
		$after = $pick( $new_caps );
		$granted = array();
		$revoked = array();
		foreach ( $after as $cap => $grant ) {
			if ( $grant && empty( $before[ $cap ] ) ) { $granted[] = $cap; }
			if ( ! $grant && ! empty( $before[ $cap ] ) ) { $revoked[] = $cap; }
		}
		foreach ( $before as $cap => $grant ) {
			if ( $grant && ! array_key_exists( $cap, $after ) ) { $revoked[] = $cap; }
		}
		if ( $granted ) { Audit::record( 'user_capability_granted', 'user', $user_id, array( 'capabilities' => $granted ) ); }
		if ( $revoked ) { Audit::record( 'user_capability_revoked', 'user', $user_id, array( 'capabilities' => $revoked ) ); }
		return array( 'granted' => $granted, 'revoked' => $revoked );
	}

	public static function on_delete_user( $user_id ) {
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		$roles = $user ? array_values( array_filter( (array) $user->roles, array( __CLASS__, 'is_plugin_role' ) ) ) : array();
		if ( $roles ) {
			Audit::record( 'user_deleted', 'user', $user_id, array( 'roles' => array_map( 'sanitize_key', $roles ) ) );
		}
	}

	/** update_option_{prefix}user_roles action (no return value). */
	public static function roles_option_updated( $old_roles, $new_roles ) {
		self::on_roles_option( $old_roles, $new_roles );
	}

	/**
	 * Role definitions changed (WP_Role::add_cap/remove_cap, add_role/remove_role): records plugin
	 * capability changes per role and added/removed plugin roles.
	 *
	 * @return array<string,array{granted:string[],revoked:string[]}> Changes per role.
	 */
	public static function on_roles_option( $old_roles, $new_roles ) {
		$old = is_array( $old_roles ) ? $old_roles : array();
		$new = is_array( $new_roles ) ? $new_roles : array();
		$changes = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $role ) {
			$before = (array) ( $old[ $role ]['capabilities'] ?? array() );
			$after = (array) ( $new[ $role ]['capabilities'] ?? array() );
			$granted = array();
			$revoked = array();
			foreach ( $after as $cap => $grant ) {
				if ( self::is_plugin_cap( $cap ) && $grant && empty( $before[ $cap ] ) ) { $granted[] = (string) $cap; }
			}
			foreach ( $before as $cap => $grant ) {
				if ( self::is_plugin_cap( $cap ) && $grant && empty( $after[ $cap ] ) ) { $revoked[] = (string) $cap; }
			}
			$is_new = ! isset( $old[ $role ] ) && isset( $new[ $role ] );
			$is_removed = isset( $old[ $role ] ) && ! isset( $new[ $role ] );
			if ( ! $granted && ! $revoked ) { continue; }
			$changes[ (string) $role ] = array( 'granted' => $granted, 'revoked' => $revoked );
			$action = $is_new ? 'role_created' : ( $is_removed ? 'role_deleted' : 'role_capabilities_changed' );
			Audit::record( $action, 'role', 0, array( 'role' => sanitize_key( (string) $role ), 'granted' => $granted, 'revoked' => $revoked ) );
		}
		return $changes;
	}

	/* ---------------------------------------------------------------- read access to personal data */

	/**
	 * Records a read access of the current staff user to personal data (rate limited, see class doc).
	 *
	 * @param string $view        What was viewed, e.g. order_detail, ticket_search, attendee_list, export, rest.
	 * @param string $object_type Audit object type (order, occurrence, event, ticket, report …).
	 * @param int    $object_id   Object id (0 when the read covers a list).
	 * @param array  $details     Additional non-sensitive details (never search terms or e-mail addresses).
	 * @return bool Whether an audit row was written.
	 */
	public static function read( $view, $object_type, $object_id = 0, array $details = array() ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Policy::get( 'audit_read_access' ) ) { return false; }
		$view = sanitize_key( (string) $view );
		$key = 'wpcap_ar_' . md5( $user_id . '|' . $view . '|' . $object_type . '|' . (int) $object_id . '|' . (string) ( $details['scope'] ?? '' ) );
		if ( get_transient( $key ) ) { return false; }
		set_transient( $key, 1, self::READ_WINDOW );
		$budget_key = 'wpcap_ar_budget_' . $user_id . '_' . gmdate( 'YmdH' );
		$used = (int) get_transient( $budget_key );
		$limit = max( 10, (int) Policy::get( 'audit_read_limit_per_hour' ) );
		set_transient( $budget_key, $used + 1, HOUR_IN_SECONDS );
		if ( $used >= $limit ) {
			if ( $used === $limit ) {
				Audit::record( 'pii_read_throttled', 'user', $user_id, array( 'limit' => $limit ) );
			}
			return false;
		}
		Audit::record( 'pii_read', $object_type, $object_id, array_merge( $details, array( 'view' => $view ) ) );
		return true;
	}

	/**
	 * Staff REST reads: successful responses of routes with personal data are recorded (requests by
	 * logged-in users and paired scanner devices; buyer requests with an access token are anonymous).
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   Server.
	 * @param mixed $request  Request.
	 */
	public static function on_rest_response( $response, $server = null, $request = null ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! get_current_user_id() ) { return $response; }
		$route = (string) $request->get_route();
		$status = is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		if ( $status >= 300 || is_wp_error( $response ) ) { return $response; }
		foreach ( self::PII_ROUTES as $pattern ) {
			if ( preg_match( $pattern, $route ) ) {
				$object = method_exists( $request, 'get_param' ) ? absint( $request->get_param( 'occurrence_id' ) ?: $request->get_param( 'event_id' ) ) : 0;
				self::read( 'rest', 'route', $object, array( 'route' => preg_replace( '#/\d+#', '/{id}', $route ), 'scope' => $route ) );
				break;
			}
		}
		return $response;
	}
}
