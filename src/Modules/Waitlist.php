<?php
/**
 * Waitlist module: automatic offers when capacity is released, admin page and settings.
 * The service lives in PassCaptain\Waitlists, the admin screen in PassCaptain\Waitlists\AdminPage.
 */
namespace PassCaptain\Modules;

use PassCaptain\Waitlists;
use PassCaptain\Waitlists\AdminPage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Waitlist {
	public static function register() {
		Waitlists::register();
	}

	public static function settings_defaults() {
		return array(
			'waitlist' => array(
				'auto_offers' => 1,
				'offer_hours' => 24,
				'max_offers_per_run' => 10,
				'strict_fifo' => 0,
			),
		);
	}

	public static function admin_pages() {
		return array( array( AdminPage::PAGE, __( 'Warteliste', 'pass-captain' ), AdminPage::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			AdminPage::ACTION_ENTRY => array( AdminPage::class, 'handle_entry' ),
			AdminPage::ACTION_SETTINGS => array( AdminPage::class, 'handle_settings' ),
		);
	}
}
