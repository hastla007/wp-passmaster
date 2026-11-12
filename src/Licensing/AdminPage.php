<?php
/**
 * License activation and status screen.
 *
 * @package wp-pass-captain
 */

namespace PassCaptain\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a site administrator activate or deactivate this product license.
 */
final class AdminPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_wpcap_activate_license', array( $this, 'activate' ) );
		add_action( 'admin_post_wpcap_deactivate_license', array( $this, 'deactivate' ) );
	}

	public function add_page(): void {
		add_options_page(
			esc_html__( 'License', 'wp-pass-captain' ),
			esc_html__( 'License', 'wp-pass-captain' ),
			'manage_options',
			'wpcap-license',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$server = (string) get_option( 'wpcap_wplc_server_url', '' );
		$credentials = get_option( 'wpcap_wplc_credentials', array() );
		$license = null;
		$error = '';
		if ( is_array( $credentials ) && ! empty( $credentials['installation_id'] ) && '' !== $server ) {
			$license = ( new LicenseClient( $server, 'wp-pass-captain', WPCAP_VERSION, 'wpcap_wplc_credentials' ) )->check_entitlements();
			if ( is_wp_error( $license ) ) {
				$error = $license->get_error_message();
				$license = null;
			}
		}
		$notice_key = 'wpcap_license_notice_' . get_current_user_id();
		$notice = get_transient( $notice_key );
		delete_transient( $notice_key );
		echo '<div class="wrap"><h1>' . esc_html__( 'License', 'wp-pass-captain' ) . '</h1>';
		if ( is_array( $notice ) ) {
			$notice_class = 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( is_array( $license ) ) {
			echo '<h2>' . esc_html__( 'Current license status', 'wp-pass-captain' ) . '</h2><dl>';
			echo '<dt>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( (string) ( $license['license_status'] ?? 'unknown' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Paid through', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( (string) ( $license['paid_until'] ?? '—' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Sites', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( (string) ( $license['site_count'] ?? 0 ) . ' / ' . (string) ( $license['site_limit'] ?? 0 ) ) . '</dd>';
			echo '</dl><h3>' . esc_html__( 'Feature rights', 'wp-pass-captain' ) . '</h3><pre>' . esc_html( wp_json_encode( $license['entitlements'] ?? array(), JSON_PRETTY_PRINT ) ) . '</pre>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpcap_deactivate_license">';
			wp_nonce_field( 'wpcap_deactivate_license' );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Deactivate this site', 'wp-pass-captain' ) . '</button></p></form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpcap_activate_license">';
			wp_nonce_field( 'wpcap_activate_license' );
			echo '<table class="form-table"><tr><th><label for="wplc-server">' . esc_html__( 'License server URL', 'wp-pass-captain' ) . '</label></th><td><input id="wplc-server" name="server_url" type="url" class="regular-text" value="' . esc_attr( $server ) . '" required placeholder="https://licenses.example.com"></td></tr>';
			echo '<tr><th><label for="wplc-key">' . esc_html__( 'License key', 'wp-pass-captain' ) . '</label></th><td><input id="wplc-key" name="license_key" type="password" class="regular-text" autocomplete="off" required></td></tr></table>';
			echo '<p>' . esc_html__( 'The license key is used once for activation and is not stored by this plugin. Activation credentials are stored locally without autoloading.', 'wp-pass-captain' ) . '</p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Activate license', 'wp-pass-captain' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	public function activate(): void {
		$this->authorize( 'wpcap_activate_license' );
		$server = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ), array( 'https' ) ) : '';
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( '' === $server || '' === $key ) {
			$this->redirect( 'error', __( 'Enter a valid HTTPS license server URL and license key.', 'wp-pass-captain' ) );
		}
		update_option( 'wpcap_wplc_server_url', $server, false );
		$result = ( new LicenseClient( $server, 'wp-pass-captain', WPCAP_VERSION, 'wpcap_wplc_credentials' ) )->activate( $key );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$this->redirect( 'success', __( 'License activated for this site.', 'wp-pass-captain' ) );
	}

	public function deactivate(): void {
		$this->authorize( 'wpcap_deactivate_license' );
		$server = (string) get_option( 'wpcap_wplc_server_url', '' );
		$result = ( new LicenseClient( $server, 'wp-pass-captain', WPCAP_VERSION, 'wpcap_wplc_credentials' ) )->deactivate();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$this->redirect( 'success', __( 'This site was deactivated.', 'wp-pass-captain' ) );
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this license.', 'wp-pass-captain' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $type, string $message ): void {
		set_transient( 'wpcap_license_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=wpcap-license' ) );
		exit;
	}
}
