<?php
/**
 * Two-factor requirement for sensitive actions (W9). Extends the optional 2FA check of refunds
 * (Refunds::user_has_2fa, filter wpcap_user_has_2fa) to configurable categories (Policy::MFA_CATEGORIES):
 * settings, secrets, exports, roles (staff/role assignments, support access), bulk actions and API keys.
 *
 * Enforcement points: admin-post/admin-ajax actions are mapped centrally (admin_init runs before the
 * handler), REST routes through rest_pre_dispatch, WordPress role changes of plugin roles through the
 * profile/user-list hooks. Blocked attempts are recorded in the audit log.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Audit;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StepUp {
	/** admin-post/admin-ajax action => categories. */
	const ADMIN_ACTIONS = array(
		'wpcap_save_settings'           => array( 'settings' ),
		'wpcap_calendar_save'           => array( 'settings' ),
		'wpcap_mail_template_save'      => array( 'settings' ),
		'wpcap_mail_template_reset'     => array( 'settings' ),
		'wpcap_mail_layout_save'        => array( 'settings' ),
		'wpcap_pos_settings'            => array( 'settings' ),
		'wpcap_print_settings'          => array( 'settings' ),
		'wpcap_reminders_save'          => array( 'settings' ),
		'wpcap_admission_save_settings' => array( 'settings' ),
		'wpcap_mollie_save'             => array( 'settings', 'secrets' ),
		'wpcap_sms_save'                => array( 'settings', 'secrets' ),
		'wpcap_wallet_save'             => array( 'settings', 'secrets' ),
		'wpcap_offline_rotate_key'      => array( 'secrets' ),
		'wpcap_webhook_action'          => array( 'settings', 'secrets' ),
		'wpcap_reports_export'          => array( 'exports' ),
		'wpcap_reports_csv'             => array( 'exports' ),
		'wpcap_export_accounting'       => array( 'exports' ),
		'wpcap_waitlist_export'         => array( 'exports' ),
		'wpcap_attendee_list'           => array( 'exports' ),
		'wpcap_audit_export'            => array( 'exports' ),
		'wpcap_network_report'          => array( 'exports' ),
		'wpcap_assign_staff'            => array( 'roles' ),
		'wpcap_remove_staff'            => array( 'roles' ),
		'wpcap_admission_add_staff'     => array( 'roles' ),
		'wpcap_admission_remove_staff'  => array( 'roles' ),
		'wpcap_assign_occurrence_staff' => array( 'roles' ),
		'wpcap_remove_occurrence_staff' => array( 'roles' ),
		'wpcap_support_grant'           => array( 'roles' ),
		'wpcap_support_revoke'          => array( 'roles' ),
		'wpcap_print_batch'             => array( 'bulk' ),
		'wpcap_cancel_event'            => array( 'bulk' ),
		'wpcap_cancel_occurrence'       => array( 'bulk' ),
		'wpcap_ops_queue_action'        => array( 'bulk' ),
		'wpcap_ops_repair'              => array( 'bulk' ),
		'wpcap_api_key_action'          => array( 'api_keys' ),
		'wpcap_partner_action'          => array( 'api_keys' ),
	);

	/** REST "METHOD route-regex" => categories. */
	const REST_ROUTES = array(
		'POST #^/wp-pass-captain/v1/developer/keys#' => array( 'api_keys' ),
		'POST #^/wp-pass-captain/v1/tax-profiles$#'  => array( 'settings' ),
	);

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'guard_admin_request' ), 1, 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 10, 3 );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'guard_profile_role' ), 10, 3 );
	}

	/** Whether the current user satisfies 2FA (shared detection with refunds). */
	public static function has_2fa( $user_id = 0 ) {
		return Refunds::user_has_2fa( $user_id ? $user_id : get_current_user_id() );
	}

	/**
	 * Checks the categories of an action. True when no category requires 2FA or the user has it.
	 *
	 * @param string|string[] $categories Categories.
	 * @param string          $action     Action name for the audit log.
	 * @return true|\WP_Error
	 */
	public static function check( $categories, $action = '' ) {
		$required = array_values( array_filter( (array) $categories, array( Policy::class, 'mfa_required' ) ) );
		if ( ! $required || self::has_2fa() ) { return true; }
		Audit::record( 'mfa_required_blocked', 'user', get_current_user_id(), array( 'action' => sanitize_key( (string) $action ), 'categories' => $required ) );
		return new \WP_Error( 'wpcap_mfa_required', __( 'Für diese Aktion ist eine aktivierte Zwei-Faktor-Anmeldung erforderlich. Bitte richte 2FA für dein Konto ein.', 'pass-captain' ), array( 'status' => 403, 'categories' => $required ) );
	}

	/** Categories of an admin-post/ajax action (including secrets in the core settings form). */
	public static function categories_for_action( $action, array $request = array() ) {
		$categories = self::ADMIN_ACTIONS[ sanitize_key( (string) $action ) ] ?? array();
		if ( 'wpcap_save_settings' === $action ) {
			foreach ( \PassCaptain\Settings::secret_keys() as $key ) {
				if ( ( isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) && '' !== trim( (string) $request[ $key ] ) ) || ! empty( $request[ $key . '__clear' ] ) ) {
					$categories[] = 'secrets';
					break;
				}
			}
		}
		return array_values( array_unique( $categories ) );
	}

	/**
	 * Runs on admin_init (admin-post.php / admin-ajax.php run it before the action handler) and on
	 * users.php bulk role changes; ends the request with 403 when 2FA is required but missing.
	 */
	public static function guard_admin_request() {
		$request = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads the action name; the handler verifies the nonce.
		$action = isset( $request['action'] ) && is_scalar( $request['action'] ) ? sanitize_key( (string) $request['action'] ) : '';
		$result = true;
		if ( '' !== $action && isset( self::ADMIN_ACTIONS[ $action ] ) ) {
			$result = self::check( self::categories_for_action( $action, $request ), $action );
		} elseif ( 'promote' === $action || ! empty( $request['new_role'] ) ) {
			// users.php bulk "change role to" with a plugin role.
			$role = sanitize_key( (string) ( $request['new_role'] ?? '' ) );
			if ( '' !== $role && AuditTrail::is_plugin_role( $role ) ) { $result = self::check( array( 'roles' ), 'user_role_change' ); }
		}
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		return $result;
	}

	/**
	 * REST guard.
	 *
	 * @param mixed $result  Pre-dispatch result.
	 * @param mixed $server  Server.
	 * @param mixed $request Request.
	 */
	public static function guard_rest( $result, $server = null, $request = null ) {
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) { return $result; }
		$route = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		foreach ( self::REST_ROUTES as $spec => $categories ) {
			list( $m, $pattern ) = explode( ' ', $spec, 2 );
			if ( $m === $method && preg_match( $pattern, $route ) ) {
				$check = self::check( $categories, 'rest' );
				if ( is_wp_error( $check ) ) { return $check; }
			}
		}
		return $result;
	}

	/**
	 * Profile screen: changing a user's role to or from a plugin role requires 2FA (category roles).
	 *
	 * @param \WP_Error $errors Errors.
	 * @param bool      $update Whether an existing user is updated.
	 * @param object    $user   User data being saved.
	 */
	public static function guard_profile_role( $errors, $update = false, $user = null ) {
		if ( ! is_object( $user ) || empty( $user->role ) ) { return; }
		$old = ( $update && ! empty( $user->ID ) && function_exists( 'get_userdata' ) ) ? get_userdata( (int) $user->ID ) : null;
		$old_roles = $old ? (array) ( get_object_vars( $old )['roles'] ?? array() ) : array();
		if ( in_array( $user->role, $old_roles, true ) ) { return; }
		if ( ! AuditTrail::is_plugin_role( $user->role ) && ! array_filter( $old_roles, array( AuditTrail::class, 'is_plugin_role' ) ) ) { return; }
		$check = self::check( array( 'roles' ), 'user_role_change' );
		if ( is_wp_error( $check ) && is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( $check->get_error_code(), $check->get_error_message() );
		}
	}
}
