<?php
/**
 * Time-limited, logged support access (W9): an administrator grants a user plugin capabilities for
 * N hours (at most `support_access_max_hours`). While active, the capabilities are added through the
 * user_has_cap filter and count for every event (Access::can_access_event). Expiry is checked on every
 * use; the hourly cleanup cron marks expired grants and writes the audit entry. Grant, revocation and
 * expiry are audited.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class SupportAccess {
	/** Capabilities that may be granted temporarily (never manage_options or finance). */
	const GRANTABLE = array( 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports' );

	/** Request cache: user id => capabilities. */
	private static $cache = array();

	public static function register() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), 20, 4 );
		add_action( 'wpcap_cleanup_reservations', array( __CLASS__, 'expire_due' ), 40, 0 );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_support_grants';
	}

	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Grants temporary access.
	 *
	 * @param int      $user_id Support user.
	 * @param int      $hours   Duration (1 … support_access_max_hours).
	 * @param string   $reason  Reason (ticket number, case) – required.
	 * @param string[] $caps    Capabilities (subset of GRANTABLE).
	 * @return array|\WP_Error Grant row.
	 */
	public static function grant( $user_id, $hours, $reason, array $caps = array( 'wpcap_manage_orders' ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wpcap_support_forbidden', __( 'Nur Administratoren dürfen Supportzugriff vergeben.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$check = StepUp::check( array( 'roles' ), 'support_grant' );
		if ( is_wp_error( $check ) ) { return $check; }
		$user_id = absint( $user_id );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$caps = array_values( array_intersect( self::GRANTABLE, array_map( 'sanitize_key', $caps ) ) );
		$max = max( 1, (int) Policy::get( 'support_access_max_hours' ) );
		$hours = absint( $hours );
		if ( ! $user_id || get_current_user_id() === $user_id ) {
			return new \WP_Error( 'wpcap_support_user', __( 'Bitte einen anderen Benutzer wählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! $caps || $hours < 1 || $hours > $max ) {
			/* translators: %d: maximum hours */
			return new \WP_Error( 'wpcap_support_invalid', sprintf( __( 'Bitte Rechte und eine Dauer von 1 bis %d Stunden wählen.', 'pass-captain' ), $max ), array( 'status' => 400 ) );
		}
		if ( strlen( $reason ) < 5 ) {
			return new \WP_Error( 'wpcap_support_reason', __( 'Bitte einen Grund (z. B. Ticketnummer) angeben.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$now = \PassCaptain\Clock::mysql();
		$expires = gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + $hours * HOUR_IN_SECONDS );
		$ok = $wpdb->insert( self::table(), array( 'user_id' => $user_id, 'granted_by' => get_current_user_id(), 'reason' => \PassCaptain\Text::cut( $reason, 255 ), 'capabilities' => implode( ',', $caps ), 'status' => 'active', 'expires_at' => $expires, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
		if ( ! $ok ) {
			return new \WP_Error( 'wpcap_support_save', __( 'Der Supportzugriff konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$grant_id = (int) $wpdb->insert_id;
		self::flush_cache();
		Audit::record( 'support_access_granted', 'user', $user_id, array( 'grant_id' => $grant_id, 'hours' => $hours, 'capabilities' => $caps, 'reason' => \PassCaptain\Text::cut( $reason, 190 ) ) );
		return self::get( $grant_id );
	}

	public static function get( $grant_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE grant_id=%d', self::table(), absint( $grant_id ) ), ARRAY_A );
	}

	/** @return array|\WP_Error */
	public static function revoke( $grant_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wpcap_support_forbidden', __( 'Nur Administratoren dürfen Supportzugriff widerrufen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$check = StepUp::check( array( 'roles' ), 'support_revoke' );
		if ( is_wp_error( $check ) ) { return $check; }
		global $wpdb;
		$grant = self::get( $grant_id );
		$updated = $grant ? $wpdb->update( self::table(), array( 'status' => 'revoked', 'revoked_by' => get_current_user_id(), 'ended_at' => \PassCaptain\Clock::mysql() ), array( 'grant_id' => absint( $grant_id ), 'status' => 'active' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) ) : 0;
		if ( ! $updated ) {
			return new \WP_Error( 'wpcap_support_not_active', __( 'Dieser Supportzugriff ist nicht mehr aktiv.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		self::flush_cache();
		Audit::record( 'support_access_revoked', 'user', (int) $grant['user_id'], array( 'grant_id' => absint( $grant_id ) ) );
		return self::get( $grant_id );
	}

	/** Marks expired grants (cron) and audits each expiry. @return int Number of expired grants. */
	public static function expire_due() {
		global $wpdb;
		$now = \PassCaptain\Clock::mysql();
		$due = $wpdb->get_results( $wpdb->prepare( "SELECT grant_id,user_id FROM %i WHERE status='active' AND expires_at <= %s LIMIT 200", self::table(), $now ) );
		$count = 0;
		foreach ( (array) $due as $row ) {
			if ( $wpdb->update( self::table(), array( 'status' => 'expired', 'ended_at' => $now ), array( 'grant_id' => (int) $row->grant_id, 'status' => 'active' ), array( '%s', '%s' ), array( '%d', '%s' ) ) ) {
				Audit::record( 'support_access_expired', 'user', (int) $row->user_id, array( 'grant_id' => (int) $row->grant_id ) );
				++$count;
			}
		}
		if ( $count ) { self::flush_cache(); }
		return $count;
	}

	/** Capabilities a user currently holds through an active, unexpired grant. */
	public static function active_caps( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return array(); }
		if ( ! isset( self::$cache[ $user_id ] ) ) {
			global $wpdb;
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT capabilities FROM %i WHERE user_id=%d AND status='active' AND expires_at > %s", self::table(), $user_id, \PassCaptain\Clock::mysql() ) );
			$caps = array();
			foreach ( (array) $rows as $list ) { $caps = array_merge( $caps, explode( ',', (string) $list ) ); }
			self::$cache[ $user_id ] = array_values( array_intersect( self::GRANTABLE, array_unique( $caps ) ) );
		}
		return self::$cache[ $user_id ];
	}

	/** Whether an active grant covers the capability (event scope: all events). */
	public static function covers( $user_id, $cap ) {
		return in_array( (string) $cap, self::active_caps( $user_id ), true );
	}

	/**
	 * user_has_cap filter: adds granted capabilities while the grant is active.
	 *
	 * @param array $allcaps All capabilities of the user.
	 * @param array $caps    Required primitive capabilities.
	 * @param array $args    Arguments.
	 * @param mixed $user    User object.
	 */
	public static function filter_caps( $allcaps, $caps = array(), $args = array(), $user = null ) {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) ( $args[1] ?? 0 );
		if ( ! $user_id || ! array_intersect( (array) $caps, self::GRANTABLE ) ) { return $allcaps; }
		foreach ( self::active_caps( $user_id ) as $cap ) { $allcaps[ $cap ] = true; }
		return $allcaps;
	}

	/** Grants for the admin list (newest first). */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY grant_id DESC LIMIT %d', self::table(), absint( $limit ) ) );
	}
}
