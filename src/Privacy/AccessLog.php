<?php
/**
 * Audit trail for access to personal data (A122): staff views of orders, tickets and attendee or
 * guest lists, exports, staff REST reads, WordPress privacy exporter/eraser runs, changes of plugin
 * roles and capabilities, and corrections of personal data with a masked before/after diff.
 *
 * Everything lands in the existing append-only audit log (Audit::record(), table wpcap_audit_logs)
 * and is deleted with it after the audit retention (Retention step "audit_logs"). Views and reads
 * are de-duplicated per user, object, view and filter within a short window
 * (wpcap_pii_audit_window, default 10 minutes), so reloading a page does not flood the log.
 * Details never contain secrets and only masked personal data ("e***@e***.com").
 *
 * Action names: docs/SECURITY.md "Audit-Log: Zugriffe auf personenbezogene Daten".
 *
 * @package PassCaptain
 */

namespace PassCaptain\Privacy;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AccessLog {
	/** Staff opened a view showing personal data of one object (order, ticket, guest list, …). */
	const ACTION_VIEW = 'pii_viewed';
	/** Staff opened a list view with personal data (once per page view and filter, not per row). */
	const ACTION_LIST = 'pii_list_viewed';
	/** Export or print view with personal data (CSV, PDF, HTML list). */
	const ACTION_EXPORT = 'pii_exported';
	/** Staff, device or partner REST endpoint returned personal data. */
	const ACTION_REST = 'pii_api_read';
	/** One page of a plugin exporter of the WordPress privacy tools ran. */
	const ACTION_PRIVACY_EXPORT = 'privacy_export_run';
	/** One page of a plugin eraser of the WordPress privacy tools ran. */
	const ACTION_PRIVACY_ERASE = 'privacy_erasure_run';
	/** WordPress finished an export file (wp_privacy_personal_data_exported). */
	const ACTION_PRIVACY_EXPORTED = 'privacy_export_completed';
	/** WordPress finished an erasure request (wp_privacy_personal_data_erased). */
	const ACTION_PRIVACY_ERASED = 'privacy_erasure_completed';
	const ACTION_ROLE_SET = 'user_role_set';
	const ACTION_ROLE_ADDED = 'user_role_added';
	const ACTION_ROLE_REMOVED = 'user_role_removed';
	const ACTION_CAP_ADDED = 'user_cap_added';
	const ACTION_CAP_REMOVED = 'user_cap_removed';
	/** Staff corrected personal data (details.changes: field => masked from/to). */
	const ACTION_CORRECTED = 'record_corrected';

	/** Default de-duplication window for views and reads (seconds). */
	const WINDOW = 600;

	/** Roles the plugin ships (Installer::register_roles()). */
	const PLUGIN_ROLES = array( 'wpcap_ticket_admin', 'wpcap_event_manager', 'wpcap_box_office', 'wpcap_checkin_staff', 'wpcap_partner', 'wpcap_service_provider', 'wpcap_finance', 'wpcap_support' );

	/** Exporter/eraser keys of the plugin start with one of these. */
	const PRIVACY_KEY_PREFIXES = array( 'pass-captain', 'wpcap' );

	/** Field names whose values never appear in a diff, not even masked. */
	const SECRET_FIELD_PATTERN = '/token|hash|secret|password|passwort|iban|(^|_)code$|(^|_)key$/i';

	/** Capabilities of plugin users seen before a user meta update (user id => caps). */
	private static $caps_before = array();

	public static function register() {
		add_filter( 'wp_privacy_personal_data_export_page', array( __CLASS__, 'privacy_export_page' ), 99, 7 );
		add_filter( 'wp_privacy_personal_data_erasure_page', array( __CLASS__, 'privacy_erasure_page' ), 99, 6 );
		add_action( 'wp_privacy_personal_data_exported', array( __CLASS__, 'privacy_exported' ), 10, 1 );
		add_action( 'wp_privacy_personal_data_erased', array( __CLASS__, 'privacy_erased' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'role_set' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'role_added' ), 10, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'role_removed' ), 10, 2 );
		add_action( 'update_user_meta', array( __CLASS__, 'caps_before_update' ), 10, 4 );
		add_action( 'updated_user_meta', array( __CLASS__, 'caps_after_update' ), 10, 4 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'rest_read' ), 99, 3 );
	}

	/** Action names per filter group of the audit log viewer (incl. older actions of the same kind). */
	public static function groups() {
		return array(
			'pii' => array( self::ACTION_VIEW, self::ACTION_LIST, self::ACTION_REST ),
			'export' => array( self::ACTION_EXPORT, 'attendee_list_csv', 'attendee_list_html', 'no_show_list_csv', 'no_show_list_html', 'print_batch', 'report_exported', 'accounting_exported', 'settlement_sheet_csv', 'settlement_sheet_html' ),
			'privacy' => array( self::ACTION_PRIVACY_EXPORT, self::ACTION_PRIVACY_ERASE, self::ACTION_PRIVACY_EXPORTED, self::ACTION_PRIVACY_ERASED, 'privacy_erasure', 'privacy_erasure_deferred', 'privacy_retention' ),
			'roles' => array( self::ACTION_ROLE_SET, self::ACTION_ROLE_ADDED, self::ACTION_ROLE_REMOVED, self::ACTION_CAP_ADDED, self::ACTION_CAP_REMOVED, 'staff_assigned', 'staff_removed', 'admission_area_staff_added', 'admission_area_staff_removed', 'partner_users_set', 'pos_cashier_added', 'service_provider_created', 'service_provider_updated', 'service_resource_providers_set', 'staff_assignment_added', 'staff_assignment_removed' ),
			'corrections' => array( self::ACTION_CORRECTED, 'billing_updated', 'ticket_holder_changed', 'ticket_assigned', 'ticket_reissued' ),
		);
	}

	/** Labels of the filter groups (audit log viewer). */
	public static function group_labels() {
		return array(
			'pii' => __( 'Ansicht personenbezogener Daten', 'pass-captain' ),
			'export' => __( 'Exporte und Drucklisten', 'pass-captain' ),
			'privacy' => __( 'Datenschutz-Export und -Löschung', 'pass-captain' ),
			'roles' => __( 'Rollen, Rechte und Teamzuordnung', 'pass-captain' ),
			'corrections' => __( 'Korrekturen', 'pass-captain' ),
		);
	}

	/* ------------------------------------------------------------------ Views, lists, exports */

	/** De-duplication window in seconds (filter wpcap_pii_audit_window; 0 = log every access). */
	public static function window() {
		/** Seconds within which the same user's repeated view of the same object/list is logged once. */
		return max( 0, (int) apply_filters( 'wpcap_pii_audit_window', self::WINDOW ) );
	}

	/**
	 * A staff member opened a view showing personal data of one object.
	 *
	 * @param string $view        View key, e.g. "order_detail".
	 * @param string $object_type Audit object type (order, ticket, event, occurrence, partner, …).
	 * @param int    $object_id   Object id.
	 * @param array  $context     Extra non-personal details (ids, filters).
	 * @return bool Whether an entry was written (false = de-duplicated).
	 */
	public static function view( $view, $object_type, $object_id, array $context = array() ) {
		return self::record_deduplicated( self::ACTION_VIEW, (string) $view, (string) $object_type, (int) $object_id, $context );
	}

	/**
	 * A staff member opened a list view with personal data: one entry per page view and filter.
	 * Search terms are not stored in clear text (only whether one was used and its masked form).
	 *
	 * @param string   $view    View key, e.g. "order_list".
	 * @param array    $filters Filters of the page view (search terms are masked).
	 * @param int|null $rows    Rows shown on the page.
	 * @param string   $object_type Object the list belongs to ("list" for global lists).
	 * @param int      $object_id   Id of that object (event, occurrence; 0 for global lists).
	 */
	public static function list_view( $view, array $filters = array(), $rows = null, $object_type = 'list', $object_id = 0 ) {
		$context = array( 'filters' => self::clean_filters( $filters ) );
		if ( null !== $rows ) { $context['rows'] = (int) $rows; }
		return self::record_deduplicated( self::ACTION_LIST, (string) $view, (string) $object_type, (int) $object_id, $context, array( 'filters' => $context['filters'] ) );
	}

	/**
	 * An export or print view containing personal data was produced. Always logged (no de-duplication):
	 * every file that leaves the system counts.
	 *
	 * @param string $kind        Export kind, e.g. "guestlist_csv".
	 * @param string $object_type Audit object type.
	 * @param int    $object_id   Object id.
	 * @param array  $details     Non-personal details (rows, format, filters).
	 */
	public static function export( $kind, $object_type, $object_id, array $details = array() ) {
		Audit::record( self::ACTION_EXPORT, $object_type, (int) $object_id, array( 'kind' => sanitize_key( (string) $kind ) ) + self::clean_filters( $details ) );
	}

	/**
	 * Writes the entry unless the same user already has one for the same action, view, object and
	 * fingerprint inside the window. Looked up in the audit table itself (indexed by object), so no
	 * extra state is stored.
	 */
	private static function record_deduplicated( $action, $view, $object_type, $object_id, array $context, array $fingerprint_extra = array() ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$object_type = sanitize_key( $object_type );
		$fingerprint = substr( hash( 'sha256', wp_json_encode( array( $action, $view, $object_type, $object_id, $user_id, $fingerprint_extra ) ) ), 0, 16 );
		$window = self::window();
		if ( $window > 0 ) {
			$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) . ' UTC' ) - $window );
			$seen = $wpdb->get_var( $wpdb->prepare(
				"SELECT audit_id FROM {$wpdb->prefix}wpcap_audit_logs WHERE object_type=%s AND object_id=%d AND action=%s AND user_id=%d AND created_at>=%s AND details LIKE %s LIMIT 1",
				$object_type,
				$object_id,
				$action,
				$user_id,
				$since,
				'%"fp":"' . $fingerprint . '"%'
			) );
			if ( $seen ) { return false; }
		}
		Audit::record( $action, $object_type, $object_id, array( 'view' => sanitize_key( $view ) ) + $context + array( 'fp' => $fingerprint ) );
		return true;
	}

	/** Filters/details for the log: ids and keys stay, free text is masked, empty values dropped. */
	private static function clean_filters( array $filters ) {
		$out = array();
		foreach ( $filters as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( null === $value || '' === $value || array() === $value || 0 === $value || false === $value ) { continue; }
			if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = self::clean_filters( $value );
			} elseif ( in_array( $key, array( 's', 'search', 'q', 'query', 'email', 'name', 'receipt' ), true ) ) {
				$out[ $key ] = self::mask( (string) $value );
			} else {
				$text = (string) $value;
				$out[ $key ] = preg_match( '/^[A-Za-z0-9_.:\-]{1,64}$/', $text ) && false === strpos( $text, '@' ) ? $text : self::mask( $text );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Masking and diffs */

	/**
	 * Masked form of a personal value: e-mail "e***@e***.com", other text "E***" (length hidden),
	 * numbers and booleans unchanged, empty stays empty.
	 */
	public static function mask( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		if ( is_array( $value ) || is_object( $value ) ) { return '***'; }
		$text = trim( (string) $value );
		if ( '' === $text ) { return ''; }
		if ( false !== strpos( $text, '@' ) ) {
			list( $local, $domain ) = array_pad( explode( '@', $text, 2 ), 2, '' );
			$dot = strrpos( $domain, '.' );
			$host = false === $dot ? $domain : substr( $domain, 0, $dot );
			$tld = false === $dot ? '' : substr( $domain, $dot );
			return self::first_char( $local ) . '***@' . self::first_char( $host ) . '***' . $tld;
		}
		return self::first_char( $text ) . '***';
	}

	private static function first_char( $text ) {
		return function_exists( 'mb_substr' ) ? (string) mb_substr( (string) $text, 0, 1 ) : substr( (string) $text, 0, 1 );
	}

	/**
	 * Changed fields between two flat records: field => ['from' => masked, 'to' => masked].
	 * Fields listed in $clear (status values, ids) keep their values; secret-looking fields
	 * (token, hash, code, IBAN, …) only report that they changed.
	 *
	 * @param array $before Values before the change.
	 * @param array $after  Values after the change.
	 * @param array $clear  Field names whose values are not personal and stay readable.
	 */
	public static function diff( array $before, array $after, array $clear = array() ) {
		$changes = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $field ) {
			$old = $before[ $field ] ?? null;
			$new = $after[ $field ] ?? null;
			if ( self::normalise( $old ) === self::normalise( $new ) ) { continue; }
			$field = (string) $field;
			if ( preg_match( self::SECRET_FIELD_PATTERN, $field ) ) {
				$changes[ $field ] = array( 'changed' => true );
			} elseif ( in_array( $field, $clear, true ) ) {
				$changes[ $field ] = array( 'from' => $old, 'to' => $new );
			} else {
				$changes[ $field ] = array( 'from' => self::mask( $old ), 'to' => self::mask( $new ) );
			}
		}
		return $changes;
	}

	private static function normalise( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) { return wp_json_encode( $value ); }
		return null === $value ? '' : trim( (string) $value );
	}

	/**
	 * Staff corrected personal data of a record. Logged only when something changed.
	 *
	 * @param string $object_type order, ticket, guest_entry, …
	 * @param int    $object_id   Object id.
	 * @param array  $before      Values before.
	 * @param array  $after       Values after.
	 * @param array  $context     Non-personal details (source, reason, order_id).
	 * @param array  $clear       Field names whose values are not personal.
	 * @return array The masked changes (empty = nothing logged).
	 */
	public static function correction( $object_type, $object_id, array $before, array $after, array $context = array(), array $clear = array() ) {
		$changes = self::diff( $before, $after, $clear );
		if ( $changes ) {
			Audit::record( self::ACTION_CORRECTED, $object_type, (int) $object_id, $context + array( 'changes' => $changes ) );
		}
		return $changes;
	}

	/* ------------------------------------------------------------------ WordPress privacy tools */

	/** Whether an exporter/eraser key belongs to this plugin or its modules. */
	public static function is_plugin_privacy_key( $key ) {
		$key = (string) $key;
		foreach ( self::PRIVACY_KEY_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) { return true; }
		}
		return false;
	}

	/** wp_privacy_personal_data_export_page: one entry per plugin exporter page that returned data (or the first page). */
	public static function privacy_export_page( $response, $exporter_index = 0, $email_address = '', $page = 1, $request_id = 0, $send_as_email = false, $exporter_key = '' ) {
		if ( ! is_array( $response ) || ! self::is_plugin_privacy_key( $exporter_key ) ) { return $response; }
		$items = is_array( $response['data'] ?? null ) ? count( $response['data'] ) : 0;
		if ( $items > 0 || 1 === (int) $page ) {
			Audit::record( self::ACTION_PRIVACY_EXPORT, 'privacy_request', (int) $request_id, array(
				'exporter' => sanitize_key( (string) $exporter_key ),
				'page' => (int) $page,
				'items' => $items,
				'done' => ! empty( $response['done'] ),
				'email' => self::mask( (string) $email_address ),
				'send_as_email' => (bool) $send_as_email,
			) );
		}
		return $response;
	}

	/** wp_privacy_personal_data_erasure_page: one entry per plugin eraser page that changed or kept data (or the first page). */
	public static function privacy_erasure_page( $response, $eraser_index = 0, $email_address = '', $page = 1, $request_id = 0, $eraser_key = '' ) {
		if ( ! is_array( $response ) || ! self::is_plugin_privacy_key( $eraser_key ) ) { return $response; }
		$removed = ! empty( $response['items_removed'] );
		$retained = ! empty( $response['items_retained'] );
		if ( $removed || $retained || 1 === (int) $page ) {
			Audit::record( self::ACTION_PRIVACY_ERASE, 'privacy_request', (int) $request_id, array(
				'eraser' => sanitize_key( (string) $eraser_key ),
				'page' => (int) $page,
				'items_removed' => $removed,
				'items_retained' => $retained,
				'done' => ! empty( $response['done'] ),
				'email' => self::mask( (string) $email_address ),
			) );
		}
		return $response;
	}

	/** wp_privacy_personal_data_exported: the export file of a request was generated. */
	public static function privacy_exported( $request_id ) {
		Audit::record( self::ACTION_PRIVACY_EXPORTED, 'privacy_request', (int) $request_id, self::request_details( $request_id ) );
	}

	/** wp_privacy_personal_data_erased: an erasure request was completed. */
	public static function privacy_erased( $request_id ) {
		Audit::record( self::ACTION_PRIVACY_ERASED, 'privacy_request', (int) $request_id, self::request_details( $request_id ) );
	}

	private static function request_details( $request_id ) {
		$request = function_exists( 'wp_get_user_request' ) ? wp_get_user_request( (int) $request_id ) : null;
		return $request && ! empty( $request->email ) ? array( 'email' => self::mask( (string) $request->email ) ) : array();
	}

	/* ------------------------------------------------------------------ Roles and capabilities */

	/** Plugin role, or a role that carries plugin capabilities (e.g. administrator). */
	public static function is_relevant_role( $role ) {
		$role = (string) $role;
		if ( '' === $role ) { return false; }
		if ( in_array( $role, self::PLUGIN_ROLES, true ) || 0 === strpos( $role, 'wpcap_' ) ) { return true; }
		$object = function_exists( 'get_role' ) ? get_role( $role ) : null;
		if ( $object && is_array( $object->capabilities ?? null ) ) {
			foreach ( $object->capabilities as $cap => $grant ) {
				if ( $grant && self::is_plugin_cap( $cap ) ) { return true; }
			}
		}
		return false;
	}

	/** Capability of this plugin (wpcap_ prefix, not a plugin role slug). */
	public static function is_plugin_cap( $cap ) {
		$cap = (string) $cap;
		return 0 === strpos( $cap, 'wpcap_' ) && ! in_array( $cap, self::PLUGIN_ROLES, true );
	}

	/** set_user_role: the user's roles were replaced. */
	public static function role_set( $user_id, $role, $old_roles = array() ) {
		$old_roles = array_values( array_map( 'strval', (array) $old_roles ) );
		if ( array( (string) $role ) === $old_roles ) { return; }
		$relevant = self::is_relevant_role( $role ) || array_filter( $old_roles, array( __CLASS__, 'is_relevant_role' ) );
		if ( ! $relevant ) { return; }
		Audit::record( self::ACTION_ROLE_SET, 'user', (int) $user_id, array( 'role' => sanitize_key( (string) $role ), 'old_roles' => array_map( 'sanitize_key', $old_roles ) ) );
	}

	/** add_user_role: a role was added to the user. */
	public static function role_added( $user_id, $role ) {
		if ( self::is_relevant_role( $role ) ) {
			Audit::record( self::ACTION_ROLE_ADDED, 'user', (int) $user_id, array( 'role' => sanitize_key( (string) $role ) ) );
		}
	}

	/** remove_user_role: a role was removed from the user. */
	public static function role_removed( $user_id, $role ) {
		if ( self::is_relevant_role( $role ) ) {
			Audit::record( self::ACTION_ROLE_REMOVED, 'user', (int) $user_id, array( 'role' => sanitize_key( (string) $role ) ) );
		}
	}

	/** Meta key of the user capabilities ({prefix}capabilities). */
	private static function caps_key() {
		global $wpdb;
		return ( isset( $wpdb->prefix ) ? $wpdb->prefix : '' ) . 'capabilities';
	}

	/** update_user_meta (before the write): remembers the current capabilities. */
	public static function caps_before_update( $meta_id, $user_id, $meta_key, $value = null ) {
		if ( self::caps_key() !== (string) $meta_key || ! function_exists( 'get_user_meta' ) ) { return; }
		$current = get_user_meta( (int) $user_id, $meta_key, true );
		self::$caps_before[ (int) $user_id ] = is_array( $current ) ? $current : array();
	}

	/**
	 * updated_user_meta: logs plugin capabilities granted to or taken from a single user (e.g. by the
	 * staff assignment, WP_User::add_cap()). Role changes are logged by the role hooks.
	 */
	public static function caps_after_update( $meta_id, $user_id, $meta_key, $value = null ) {
		if ( self::caps_key() !== (string) $meta_key ) { return; }
		$user_id = (int) $user_id;
		$before = self::$caps_before[ $user_id ] ?? array();
		unset( self::$caps_before[ $user_id ] );
		self::log_cap_changes( $user_id, $before, is_array( $value ) ? $value : array() );
	}

	/** Logs added/removed plugin capabilities between two capability arrays (role keys ignored). */
	public static function log_cap_changes( $user_id, array $before, array $after ) {
		$granted = static function ( array $caps ) {
			$out = array();
			foreach ( $caps as $cap => $grant ) {
				if ( $grant && self::is_plugin_cap( $cap ) && ! ( function_exists( 'get_role' ) && get_role( (string) $cap ) ) ) { $out[] = (string) $cap; }
			}
			sort( $out );
			return $out;
		};
		$old = $granted( $before );
		$new = $granted( $after );
		$added = array_values( array_diff( $new, $old ) );
		$removed = array_values( array_diff( $old, $new ) );
		if ( $added ) { Audit::record( self::ACTION_CAP_ADDED, 'user', (int) $user_id, array( 'caps' => $added ) ); }
		if ( $removed ) { Audit::record( self::ACTION_CAP_REMOVED, 'user', (int) $user_id, array( 'caps' => $removed ) ); }
	}

	/* ------------------------------------------------------------------ REST reads */

	/**
	 * Staff/device/partner REST endpoints that return personal data (GET): route pattern => view key.
	 * Named group "id" becomes the object id; object type per entry.
	 */
	public static function rest_routes() {
		$routes = array(
			'#^/pass-captain/v1/events/(?P<id>\d+)/waitlist/entries$#' => array( 'waitlist_entries', 'event' ),
			'#^/pass-captain/v1/guestlists/search$#' => array( 'guestlist_search', 'occurrence', 'occurrence_id' ),
			'#^/pass-captain/v1/guestlists/will-call$#' => array( 'guestlist_will_call', 'occurrence', 'occurrence_id' ),
			'#^/pass-captain/v1/app/offline/package$#' => array( 'offline_package', 'occurrence', 'occurrence_id' ),
			'#^/pass-captain/v1/partner/orders$#' => array( 'partner_orders', 'partner' ),
			'#^/pass-captain/v1/partner/tickets$#' => array( 'partner_tickets', 'partner' ),
			'#^/pass-captain/v1/partner/checkins$#' => array( 'partner_checkins', 'partner' ),
		);
		/** REST routes (regex => [view, object type, id parameter]) whose successful GET responses are audited as personal data reads. */
		return (array) apply_filters( 'wpcap_pii_audit_rest_routes', $routes );
	}

	/** rest_post_dispatch: audits successful GET reads of the routes above (de-duplicated). */
	public static function rest_read( $result, $server = null, $request = null ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_method' ) ) { return $result; }
		if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) { return $result; }
		$status = is_object( $result ) && method_exists( $result, 'get_status' ) ? (int) $result->get_status() : 200;
		if ( $status >= 400 || ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) ) { return $result; }
		$route = (string) $request->get_route();
		foreach ( self::rest_routes() as $pattern => $def ) {
			if ( ! preg_match( $pattern, $route, $m ) ) { continue; }
			$def = array_values( (array) $def );
			$object_type = (string) ( $def[1] ?? 'rest' );
			$object_id = isset( $m['id'] ) ? (int) $m['id'] : ( ! empty( $def[2] ) ? absint( $request->get_param( $def[2] ) ) : 0 );
			$context = array( 'route' => $route );
			if ( 'partner' === $object_type && class_exists( \PassCaptain\Partners\Api::class ) ) {
				$ctx = \PassCaptain\Partners\Api::context();
				$object_id = (int) ( $ctx['partner']->partner_id ?? $object_id );
				if ( ! empty( $ctx['key']->key_id ) ) { $context['key_id'] = (int) $ctx['key']->key_id; }
			}
			$params = array();
			foreach ( array( 'event_id', 'occurrence_id', 'since', 'page', 'q', 'search' ) as $param ) {
				$value = $request->get_param( $param );
				if ( is_scalar( $value ) && '' !== (string) $value ) { $params[ $param ] = is_numeric( $value ) ? (int) $value : (string) $value; }
			}
			$context['params'] = self::clean_filters( $params );
			$data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : $result;
			if ( is_array( $data ) && array_is_list( $data ) ) { $context['rows'] = count( $data ); }
			self::record_deduplicated( self::ACTION_REST, (string) $def[0], $object_type, $object_id, $context, array( 'params' => $context['params'], 'key' => $context['key_id'] ?? 0 ) );
			break;
		}
		return $result;
	}
}
