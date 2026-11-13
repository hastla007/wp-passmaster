<?php
/**
 * Module: reports ("Berichte") – revenue, VAT, refunds, capacity, check-in and channel share,
 * settlement sheet per occurrence (Settlement).
 * Code lives in src/Reports/.
 */
namespace PassCaptain\Modules;

use PassCaptain\Reports\Page;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reports {
	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Berichte', 'pass-captain' ), Page::menu_cap(), array( Page::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_reports_csv' => array( Page::class, 'handle_csv' ),
			\PassCaptain\Reports\Settlement::ACTION => array( \PassCaptain\Reports\Settlement::class, 'handle' ),
		);
	}
}
