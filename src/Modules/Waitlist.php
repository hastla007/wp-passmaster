<?php
/**
 * Waitlist module: automatic offers when capacity is released, admin page and settings.
 * The service lives in PassCaptain\Waitlists, the admin screen in PassCaptain\Waitlists\AdminPage.
 */
namespace PassCaptain\Modules;

use PassCaptain\Waitlists;
use PassCaptain\Waitlists\AdminPage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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
				'close_mail' => 1,
				'change_mail' => 1,
				'double_opt_in' => 1,
				'confirm_hours' => 48,
				'offer_cutoff_minutes' => 15, // SET-PC-21: no new offer when less time is left before online sales end.
			),
		);
	}

	/** Settings register entries of this module (SET-PC-01, SettingsSchema). */
	public static function settings_schema() {
		return array(
			'waitlist' => array( 'type' => 'group', 'label' => __( 'Warteliste', 'pass-captain' ), 'help' => __( 'Automatische Angebote, Gültigkeit eines Angebots, Reihenfolge, Benachrichtigungen und E-Mail-Bestätigung (Double-Opt-in).', 'pass-captain' ), 'page' => 'wpcap-waitlist', 'fields' => array( 'offer_hours' => array( 'type' => 'int', 'min' => 1, 'max' => 336, 'label' => __( 'Gültigkeit eines Angebots (Stunden)', 'pass-captain' ) ), 'max_offers_per_run' => array( 'type' => 'int', 'min' => 1, 'max' => 100, 'label' => __( 'Höchstens Angebote pro Durchlauf', 'pass-captain' ) ), 'confirm_hours' => array( 'type' => 'int', 'min' => 1, 'max' => 168, 'label' => __( 'Gültigkeit des Bestätigungslinks (Stunden)', 'pass-captain' ) ), 'offer_cutoff_minutes' => array( 'type' => 'int', 'min' => 5, 'max' => 1440, 'label' => __( 'Sperrfrist vor Verkaufsende (Minuten)', 'pass-captain' ), 'help' => __( 'Bleibt bis zum Ende des Online-Verkaufs weniger Zeit, macht die Warteliste kein neues Angebot mehr. Standard: 15.', 'pass-captain' ) ) ) ),
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
