<?php
/**
 * Module "Serientermine": recurring occurrence series (daily, weekly, monthly, date lists) with
 * preview, idempotent generation, ticket type copies and optional seat-map link.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Series\AdminPage;
use PassCaptain\Series\Generator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Series {
	public static function register() {}

	public static function schema( $prefix, $charset ) {
		return Generator::schema( $prefix, $charset );
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Serientermine', 'wp-pass-captain' ), Generator::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_series_create' => array( AdminPage::class, 'handle_create' ) );
	}
}
