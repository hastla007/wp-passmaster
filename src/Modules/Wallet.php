<?php
/**
 * Wallet module: Apple Wallet (.pkpass with PassKit web service + APNs updates) and Google Wallet
 * (save link + REST updates) for issued tickets. Providers are registered through
 * PassCaptain\Wallet\Bridge; implementation lives in src/WalletPasses/. See docs/WALLET.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\WalletPasses\AdminPage;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\WebService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Wallet {
	public static function register() {
		Service::register();
		WebService::register();
		\PassCaptain\WalletPasses\Health::register();
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_wallet_state (
				ticket_id bigint(20) unsigned NOT NULL,
				secret text NOT NULL,
				code_hash char(64) NOT NULL DEFAULT '',
				auth_token_hash char(64) NOT NULL DEFAULT '',
				generation int(10) unsigned NOT NULL DEFAULT 1,
				fingerprint char(64) NOT NULL DEFAULT '',
				update_tag bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				KEY update_tag (update_tag)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_wallet_registrations (
				registration_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				device_library_id varchar(128) NOT NULL,
				pass_type_id varchar(190) NOT NULL,
				serial_number varchar(190) NOT NULL,
				push_token varchar(200) NOT NULL,
				auth_token_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (registration_id),
				UNIQUE KEY device_serial (device_library_id,serial_number),
				KEY serial_number (serial_number),
				KEY push_token (push_token(191))
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		return array(
			'wallet_apple_enabled'          => 0,
			'wallet_apple_pass_type_id'     => '',
			'wallet_apple_team_id'          => '',
			'wallet_apple_delivery'         => 'attachment',
			'wallet_apple_icon_path'        => '',
			'wallet_apple_logo_path'        => '',
			'wallet_google_logo_url'        => '',
			'wallet_apns_host'              => 'https://api.push.apple.com',
			'apple_pass_cert'               => '',
			'apple_pass_key'                => '',
			'apple_pass_key_password'       => '',
			'apple_wwdr_cert'               => '',
			'wallet_google_enabled'         => 0,
			'wallet_google_issuer_id'       => '',
			'google_wallet_service_account' => '',
			'wallet_organization_name'      => '',
			'wallet_background_color'       => '#1d2327',
			'wallet_foreground_color'       => '#ffffff',
			'wallet_label_color'            => '#c3c4c7',
		);
	}

	/** Settings register entries of this module (SET-PC-01, SettingsSchema). */
	public static function settings_schema() {
		return array(
			'wallet_apple_enabled' => array( 'type' => 'bool', 'label' => __( 'Apple Wallet anbieten', 'pass-captain' ), 'help' => __( 'Tickets lassen sich in Apple Wallet speichern.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_apple_pass_type_id' => array( 'type' => 'text', 'label' => __( 'Apple Pass Type ID', 'pass-captain' ), 'help' => __( 'Kennung des Pass-Typs aus dem Apple-Entwicklerkonto (pass.…).', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_apple_team_id' => array( 'type' => 'text', 'label' => __( 'Apple Team ID', 'pass-captain' ), 'help' => __( 'Team-Kennung des Apple-Entwicklerkontos.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_apple_delivery' => array( 'type' => 'choice', 'options' => array( 'attachment' => __( 'Als Anhang (.pkpass)', 'pass-captain' ), 'link' => __( 'Als Download-Link', 'pass-captain' ) ), 'label' => __( 'Auslieferung der Apple-Pässe', 'pass-captain' ), 'help' => __( 'Ob Pässe als Download oder per Link ausgeliefert werden.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_apple_icon_path' => array( 'type' => 'text', 'label' => __( 'Symbol des Apple-Passes', 'pass-captain' ), 'help' => __( 'Pfad zur Symboldatei (PNG) für Apple-Pässe.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_apple_logo_path' => array( 'type' => 'text', 'label' => __( 'Logo des Apple-Passes', 'pass-captain' ), 'help' => __( 'Pfad zur Logodatei (PNG) für Apple-Pässe.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_google_logo_url' => array( 'type' => 'text', 'label' => __( 'Logo des Google-Passes', 'pass-captain' ), 'help' => __( 'Öffentliche Adresse des Logos für Google-Wallet-Pässe.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_apns_host' => array( 'type' => 'text', 'label' => __( 'Apple Push-Server', 'pass-captain' ), 'help' => __( 'Server für Aktualisierungen bereits gespeicherter Apple-Pässe.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'apple_pass_cert' => array( 'type' => 'secret', 'label' => __( 'Apple Pass-Zertifikat', 'pass-captain' ), 'help' => __( 'Zertifikat zum Signieren der Apple-Pässe; wird verschlüsselt gespeichert.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'apple_pass_key' => array( 'type' => 'secret', 'label' => __( 'Schlüssel des Apple-Zertifikats', 'pass-captain' ), 'help' => __( 'Privater Schlüssel zum Pass-Zertifikat; wird verschlüsselt gespeichert.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'apple_pass_key_password' => array( 'type' => 'secret', 'label' => __( 'Passwort des Apple-Schlüssels', 'pass-captain' ), 'help' => __( 'Passwort des privaten Schlüssels; wird verschlüsselt gespeichert.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'apple_wwdr_cert' => array( 'type' => 'secret', 'label' => __( 'Apple WWDR-Zwischenzertifikat', 'pass-captain' ), 'help' => __( 'Zwischenzertifikat von Apple für die Signatur; wird verschlüsselt gespeichert.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_google_enabled' => array( 'type' => 'bool', 'label' => __( 'Google Wallet anbieten', 'pass-captain' ), 'help' => __( 'Tickets lassen sich in Google Wallet speichern.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_google_issuer_id' => array( 'type' => 'text', 'label' => __( 'Google Issuer ID', 'pass-captain' ), 'help' => __( 'Aussteller-Kennung aus der Google Wallet Console.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'google_wallet_service_account' => array( 'type' => 'secret', 'label' => __( 'Google-Dienstkonto', 'pass-captain' ), 'help' => __( 'Schlüsseldatei (JSON) des Google-Dienstkontos; wird verschlüsselt gespeichert.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_organization_name' => array( 'type' => 'text', 'label' => __( 'Name des Veranstalters im Pass', 'pass-captain' ), 'help' => __( 'Erscheint als Aussteller auf Wallet-Pässen.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_background_color' => array( 'type' => 'text', 'label' => __( 'Hintergrundfarbe der Pässe', 'pass-captain' ), 'help' => __( 'Farbe als Hex-Wert, z. B. #1e3a8a.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_foreground_color' => array( 'type' => 'text', 'label' => __( 'Textfarbe der Pässe', 'pass-captain' ), 'help' => __( 'Farbe als Hex-Wert, z. B. #ffffff.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
			'wallet_label_color' => array( 'type' => 'text', 'label' => __( 'Farbe der Beschriftungen', 'pass-captain' ), 'help' => __( 'Farbe als Hex-Wert für die Feldbezeichnungen auf Pässen.', 'pass-captain' ), 'page' => 'wpcap-wallet' ),
		);
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Wallet', 'pass-captain' ), AdminPage::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_wallet_save' => array( AdminPage::class, 'handle_save' ),
			'wpcap_wallet_test_pass' => array( AdminPage::class, 'handle_test_pass' ),
		);
	}
}
