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
