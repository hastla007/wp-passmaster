<?php
/**
 * Status of the optional WP Voucher Captain integration for Pass Captain's settings page: which parts of the
 * bridge are active, and what is missing. Neither plugin requires the other.
 */
namespace PassCaptain\Integrations\Vouchers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class IntegrationStatus {
	public static function voucher_captain_active() {
		return function_exists( 'vcap' ) && defined( 'VCAP_VERSION' );
	}

	/**
	 * Rows: label => [ ok (bool), text ].
	 *
	 * @return array<string, array{0:bool,1:string}>
	 */
	public static function rows() {
		if ( ! self::voucher_captain_active() ) {
			return array( __( 'WP Voucher Captain', 'wp-pass-captain' ) => array( false, __( 'nicht aktiv – Gutscheine im Ticket-Checkout und Gutschein → Ticket sind ausgeschaltet. Pass Captain funktioniert ohne Einschränkung.', 'wp-pass-captain' ) ) );
		}
		$version = (string) VCAP_VERSION;
		$provider = Bridge::available();
		// Voucher Captain versions with ticket vouchers and the shared scanner ship these classes.
		$tickets = class_exists( '\\VCAP\\Compat\\PassCaptainTickets' );
		$scanner = class_exists( '\\VCAP\\Compat\\PassCaptainScanner' );
		return array(
			__( 'WP Voucher Captain', 'wp-pass-captain' ) => array( true, sprintf( /* translators: %s: version */ __( 'aktiv (Version %s)', 'wp-pass-captain' ), $version ) ),
			__( 'Gutscheine im Ticket-Checkout', 'wp-pass-captain' ) => array( $provider, $provider ? __( 'aktiv (Anbieter-Vertrag v1)', 'wp-pass-captain' ) : __( 'kein kompatibler Gutschein-Anbieter – Voucher Captain aktualisieren', 'wp-pass-captain' ) ),
			__( 'Gutschein → Ticket', 'wp-pass-captain' ) => array( $tickets, $tickets ? __( 'verfügbar – Ticketart am Voucher-Captain-Produkt wählen', 'wp-pass-captain' ) : __( 'nicht verfügbar – Voucher Captain aktualisieren', 'wp-pass-captain' ) ),
			__( 'Gemeinsamer Scanner', 'wp-pass-captain' ) => array( $scanner, $scanner ? __( 'Der Voucher-Captain-Scanner erkennt auch Pass-Captain-Tickets (Recht „wpcap_checkin“ nötig).', 'wp-pass-captain' ) : __( 'nicht verfügbar – Voucher Captain aktualisieren', 'wp-pass-captain' ) ),
		);
	}

	public static function render() {
		echo '<div class="notice notice-info inline wpcap-integration-status"><p><strong>' . esc_html__( 'Integration WP Voucher Captain', 'wp-pass-captain' ) . '</strong></p><ul>';
		foreach ( self::rows() as $label => $row ) {
			echo '<li>' . ( $row[0] ? '✔ ' : '– ' ) . '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $row[1] ) . '</li>';
		}
		echo '</ul>';
		if ( self::voucher_captain_active() ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=vcap-vouchers' ) ) . '">' . esc_html__( 'Zu WP Voucher Captain', 'wp-pass-captain' ) . '</a> · <a href="' . esc_url( admin_url( 'admin.php?page=vcap-scanner' ) ) . '">' . esc_html__( 'Gemeinsamer Scanner', 'wp-pass-captain' ) . '</a></p>';
		}
		echo '</div>';
	}
}
