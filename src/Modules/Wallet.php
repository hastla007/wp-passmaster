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

final class Wallet {
	public static function register() {
		Service::register();
		WebService::register();
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
