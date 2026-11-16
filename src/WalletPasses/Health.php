<?php
/**
 * Wallet health: Site Health test "Pass Captain Wallet" and an admin notice for administrators.
 *
 * - A584: the code store key comes from the WordPress auth salt (no WPCAP_WALLET_ENCRYPTION_KEY) –
 *   recommended; the key changed since the stored codes were encrypted – critical (passes cannot be
 *   rebuilt; they keep their last state, nothing is voided).
 * - A585: Apple pass/WWDR certificate expires within Credentials::EXPIRY_WARNING_DAYS – recommended,
 *   shown on every admin screen; expired – critical (Apple Wallet then counts as not configured).
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Admin\Forms;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Health {
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/** Whether any wallet provider is switched on (checks only matter then). */
	private static function enabled() {
		return (bool) Settings::get( 'wallet_apple_enabled' ) || (bool) Settings::get( 'wallet_google_enabled' );
	}

	/**
	 * Current findings, most severe first.
	 *
	 * @return array<int,array{key:string,status:string,message:string}> status: critical|recommended.
	 */
	public static function issues() {
		if ( ! self::enabled() ) { return array(); }
		$out = array();
		try {
			$changed = Store::key_changed();
		} catch ( \Throwable $e ) {
			$changed = true;
		}
		if ( $changed ) {
			$out[] = array( 'key' => 'key_changed', 'status' => 'critical', 'message' => __( 'Der Schlüssel für die gespeicherten Wallet-Ticketcodes hat sich geändert (neue WordPress-Salts oder geänderte WPCAP_WALLET_ENCRYPTION_KEY). Bestehende Wallet-Pässe können nicht mehr aktualisiert werden; sie behalten ihren letzten Stand, die Tickets bleiben gültig. Stelle den bisherigen Schlüssel wieder her (als WPCAP_WALLET_ENCRYPTION_KEY in wp-config.php) oder sende betroffene Tickets erneut.', 'pass-captain' ) );
		}
		if ( Settings::get( 'wallet_apple_enabled' ) ) {
			$material = Credentials::apple( false );
			if ( is_array( $material ) ) {
				foreach ( array( 'cert' => __( 'Pass-Type-ID-Zertifikat', 'pass-captain' ), 'wwdr' => __( 'Apple-WWDR-Zwischenzertifikat', 'pass-captain' ) ) as $which => $label ) {
					$info = Credentials::describe_cert( $material[ $which ] );
					if ( ! $info ) { continue; }
					$date = gmdate( 'd.m.Y', $info['valid_to'] );
					if ( $info['valid_to'] <= time() ) {
						/* translators: 1: certificate name, 2: date */
						$out[] = array( 'key' => 'apple_' . $which . '_expired', 'status' => 'critical', 'message' => sprintf( __( '%1$s ist am %2$s abgelaufen. Es werden keine Apple-Wallet-Pässe mehr erstellt oder aktualisiert, bis ein neues Zertifikat hinterlegt ist.', 'pass-captain' ), $label, $date ) );
					} elseif ( $info['valid_to'] < time() + Credentials::EXPIRY_WARNING_DAYS * DAY_IN_SECONDS ) {
						$days = max( 1, (int) ceil( ( $info['valid_to'] - time() ) / DAY_IN_SECONDS ) );
						/* translators: 1: certificate name, 2: date, 3: number of days */
						$out[] = array( 'key' => 'apple_' . $which . '_expiring', 'status' => 'recommended', 'message' => sprintf( _n( '%1$s läuft am %2$s ab (in %3$d Tag). Bitte rechtzeitig im Apple-Developer-Konto erneuern und hier hinterlegen; danach werden keine Apple-Wallet-Pässe mehr erstellt.', '%1$s läuft am %2$s ab (in %3$d Tagen). Bitte rechtzeitig im Apple-Developer-Konto erneuern und hier hinterlegen; danach werden keine Apple-Wallet-Pässe mehr erstellt.', $days, 'pass-captain' ), $label, $date, $days ) );
					}
				}
			}
		}
		if ( 'salt' === Store::key_source() ) {
			$out[] = array( 'key' => 'key_from_salt', 'status' => 'recommended', 'message' => __( 'Die Wallet-Ticketcodes werden mit einem aus den WordPress-Salts abgeleiteten Schlüssel verschlüsselt. Werden die Salts erneuert (z. B. nach einem Sicherheitsvorfall), lassen sich bestehende Wallet-Pässe nicht mehr aktualisieren. Lege in wp-config.php eine eigene, dauerhafte Konstante WPCAP_WALLET_ENCRYPTION_KEY an (vor dem ersten Wallet-Pass oder mit dem bisherigen Salt-Wert).', 'pass-captain' ) );
		}
		/**
		 * Wallet health findings (Site Health test and admin notice).
		 *
		 * @param array $issues List of [key, status (critical|recommended), message].
		 */
		$out = (array) apply_filters( 'wpcap_wallet_health_issues', $out );
		usort( $out, static function ( $a, $b ) { return ( 'critical' === $b['status'] ) <=> ( 'critical' === $a['status'] ); } );
		return $out;
	}

	/** Filter site_status_tests. */
	public static function register_tests( $tests ) {
		$tests = is_array( $tests ) ? $tests : array();
		$tests['direct']['wpcap_wallet'] = array( 'label' => __( 'Pass Captain Wallet', 'pass-captain' ), 'test' => array( __CLASS__, 'test' ) );
		return $tests;
	}

	/** Site Health result. */
	public static function test() {
		$issues = self::issues();
		$status = $issues ? $issues[0]['status'] : 'good';
		$description = $issues ? '<ul>' . implode( '', array_map( static function ( $issue ) { return '<li>' . esc_html( $issue['message'] ) . '</li>'; }, $issues ) ) . '</ul>' : '<p>' . esc_html__( 'Wallet-Zertifikate und Schlüssel sind in Ordnung.', 'pass-captain' ) . '</p>';
		return array(
			'label' => $issues ? __( 'Wallet-Pässe brauchen Aufmerksamkeit', 'pass-captain' ) : __( 'Wallet-Pässe sind einsatzbereit', 'pass-captain' ),
			'status' => $status,
			'badge' => array( 'label' => __( 'Pass Captain', 'pass-captain' ), 'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' ) ),
			'description' => $description,
			'actions' => '<p><a href="' . esc_url( Forms::page_url( AdminPage::SLUG ) ) . '">' . esc_html__( 'Zu den Wallet-Einstellungen', 'pass-captain' ) . '</a></p>',
			'test' => 'wpcap_wallet',
		);
	}

	/**
	 * Admin notice for administrators: critical findings on every admin screen, recommendations
	 * only on the Wallet screen (no nagging).
	 */
	public static function admin_notice() {
		if ( ! current_user_can( AdminPage::CAP ) ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		foreach ( self::issues() as $issue ) {
			if ( 'critical' !== $issue['status'] && AdminPage::SLUG !== $page && ! self::notice_everywhere( $issue['key'] ) ) { continue; }
			echo '<div class="notice notice-' . ( 'critical' === $issue['status'] ? 'error' : 'warning' ) . '"><p><strong>' . esc_html__( 'Pass Captain Wallet:', 'pass-captain' ) . '</strong> ' . esc_html( $issue['message'] ) . '</p></div>';
		}
	}

	/** Recommendations that are shown on every admin screen (time-critical ones). */
	private static function notice_everywhere( $key ) {
		return in_array( $key, array( 'apple_cert_expiring', 'apple_wwdr_expiring' ), true );
	}
}
