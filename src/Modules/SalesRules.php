<?php
/**
 * Module "Verkaufsregeln": automatic price rules for all occurrences/ticket types, buyer limits,
 * campaign stacking settings, versioned tax profiles (admin "Steuerprofile") and logged consents
 * for participant fields. Code lives in src/Sales/; tables are part of the core schema.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Sales\AdminSections;
use PassCaptain\Sales\Consents;
use PassCaptain\Sales\PriceRules;
use PassCaptain\Sales\TaxProfilesPage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class SalesRules {
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		PriceRules::register();
		Consents::register();
		AdminSections::register();
	}

	public static function settings_defaults() {
		// Cap of the total order reduction (price rules + campaign codes) in basis points of the
		// value at stored prices; 0 = no cap (Campaigns::calculate()).
		return array( 'max_total_discount_bp' => 0 );
	}

	/** Settings register entries of this module (SET-PC-01, SettingsSchema). */
	public static function settings_schema() {
		return array(
			'max_total_discount_bp' => array( 'type' => 'int', 'label' => __( 'Höchster Gesamtrabatt (Basispunkte)', 'pass-captain' ), 'help' => __( 'Obergrenze für die Summe aller Rabatte einer Bestellung in Basispunkten (10000 = 100 %).', 'pass-captain' ), 'page' => 'wpcap-sales-rules', 'min' => 0, 'max' => 10000 ),
		);
	}

	public static function admin_pages() {
		return array( array( TaxProfilesPage::SLUG, __( 'Steuerprofile', 'pass-captain' ), TaxProfilesPage::CAP, array( TaxProfilesPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array_merge(
			array( TaxProfilesPage::ACTION => array( TaxProfilesPage::class, 'handle_action' ) ),
			AdminSections::handlers()
		);
	}
}
