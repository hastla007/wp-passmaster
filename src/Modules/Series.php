<?php
/**
 * Module "Serientermine": recurring occurrence series (daily, weekly, monthly, date lists) with
 * preview, idempotent generation, ticket type copies and optional seat-map link; editing a stored
 * series with a preview of its effects on future occurrences (A128); price tiers
 * (early bird, last minute, demand steps, weekday/time slots) and shared quota pools across the
 * occurrences of a series.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Series\AdminPage;
use PassCaptain\Series\Generator;
use PassCaptain\Series\Pools;
use PassCaptain\Series\Pricing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Series {
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		Pricing::register();
		Pools::register();
	}

	public static function schema( $prefix, $charset ) {
		return array_merge( Generator::schema( $prefix, $charset ), Pricing::schema( $prefix, $charset ), Pools::schema( $prefix, $charset ) );
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Serientermine', 'wp-pass-captain' ), Generator::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_series_create' => array( AdminPage::class, 'handle_create' ),
			'wpcap_series_update' => array( AdminPage::class, 'handle_update' ),
			'wpcap_series_rule_save' => array( AdminPage::class, 'handle_rule_save' ),
			'wpcap_series_rule_update' => array( AdminPage::class, 'handle_rule_update' ),
			'wpcap_series_rule_delete' => array( AdminPage::class, 'handle_rule_delete' ),
			'wpcap_series_pool_save' => array( AdminPage::class, 'handle_pool_save' ),
			'wpcap_series_pool_update' => array( AdminPage::class, 'handle_pool_update' ),
			'wpcap_series_pool_delete' => array( AdminPage::class, 'handle_pool_delete' ),
		);
	}
}
