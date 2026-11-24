<?php
/**
 * Rules for the plugin's own admin notices (A301; TODO A6 / WordPress.org guideline 11).
 *
 * Hints and recommendations (not errors that break sales) appear only on the plugin's own admin
 * pages and can be dismissed per user (user meta). Notices about the optional add-on are only accepted
 * through the filter `wpcap_addon_notices` and are rendered by this class – plain text, own pages
 * only, always dismissible, never on the dashboard or other plugins' screens.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Notices {
	/** User meta: notice id => dismissed version. */
	const META = 'wpcap_dismissed_notices';
	/** admin-post action. */
	const ACTION = 'wpcap_dismiss_notice';

	public static function register() {
		add_action( 'admin_post_' . Notices::ACTION, array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'addon_notices' ), 50 );
	}

	/**
	 * Whether the current admin screen is one of the plugin's own pages (page=wpcap…/pass-captain),
	 * optionally also the dashboard.
	 */
	public static function own_screen( $with_dashboard = false ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		if ( str_starts_with( $page, 'wpcap' ) || 'pass-captain' === $page ) { return true; }
		if ( ! $with_dashboard ) { return false; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'dashboard' === ( $screen->id ?? '' );
	}

	/** Whether the user dismissed this notice (in this version; a new version shows it again). */
	public static function dismissed( $id, $version = '1', $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) { return false; }
		$all = get_user_meta( $user_id, self::META, true );
		return is_array( $all ) && isset( $all[ sanitize_key( $id ) ] ) && (string) $all[ sanitize_key( $id ) ] === (string) $version;
	}

	/** Marks a notice as dismissed for a user. */
	public static function dismiss( $id, $version = '1', $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || '' === sanitize_key( $id ) ) { return false; }
		$all = get_user_meta( $user_id, self::META, true );
		$all = is_array( $all ) ? $all : array();
		$all[ sanitize_key( $id ) ] = substr( (string) $version, 0, 40 );
		update_user_meta( $user_id, self::META, array_slice( $all, -50, null, true ) );
		return true;
	}

	/** "Hide" button (small POST form, nonce per notice) for a notice. */
	public static function dismiss_button( $id, $version = '1' ) {
		$id = sanitize_key( $id );
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-notice-dismiss"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="notice" value="' . esc_attr( $id ) . '"><input type="hidden" name="version" value="' . esc_attr( (string) $version ) . '">'
			. wp_nonce_field( self::ACTION . '_' . $id, '_wpnonce', true, false )
			. '<button type="submit" class="button-link">' . esc_html__( 'Hinweis für mich ausblenden', 'pass-captain' ) . '</button></form>';
		return $html;
	}

	/** admin-post: dismisses a notice for the current user and returns to the page. */
	public static function handle_dismiss() {
		// The dismissible notices are shown to administrators only.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		$id = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		Forms::verify( self::ACTION . '_' . $id );
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Forms::verify() above.
		self::dismiss( $id, $version );
		$back = wp_get_referer();
		Forms::redirect( $back ? $back : admin_url() );
	}

	/**
	 * Notices of the optional add-on (TODO A6): only on the plugin's own pages, only for administrators,
	 * plain text, always dismissible.
	 */
	public static function addon_notices() {
		if ( ! self::own_screen() || ! current_user_can( 'manage_options' ) ) { return; }
		/**
		 * Notices about the optional add-on. Each entry: `id` (key), `message` (plain text), optional
		 * `url` and `link` (link text), `version` (a new version shows a dismissed notice again).
		 * Rendered unobtrusively (notice-info), only on Pass Captain pages and always dismissible per user.
		 *
		 * @param array[] $notices Notices.
		 */
		$notices = apply_filters( 'wpcap_addon_notices', array() );
		foreach ( is_array( $notices ) ? array_slice( $notices, 0, 3 ) : array() as $notice ) {
			if ( ! is_array( $notice ) || '' === sanitize_key( $notice['id'] ?? '' ) || '' === trim( (string) ( $notice['message'] ?? '' ) ) ) { continue; }
			$id = 'addon_' . sanitize_key( $notice['id'] );
			$version = (string) ( $notice['version'] ?? '1' );
			if ( self::dismissed( $id, $version ) ) { continue; }
			$link = ! empty( $notice['url'] ) ? ' <a href="' . esc_url( (string) $notice['url'] ) . '">' . esc_html( (string) ( $notice['link'] ?? __( 'Mehr erfahren', 'pass-captain' ) ) ) . '</a>' : '';
			echo '<div class="notice notice-info"><p>' . esc_html( wp_strip_all_tags( (string) $notice['message'] ) ) . $link . '</p>' . self::dismiss_button( $id, $version ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above / built with esc_* in dismiss_button().
		}
	}
}
