<?php
/**
 * Module "Saalpläne": graphical seat-map editor with versions, occurrence links, checkout seat
 * selection, atomic seat holds and ticket ↔ seat assignment.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Seating\AdminPage;
use PassCaptain\Seating\Layouts;
use PassCaptain\Seating\Picker;
use PassCaptain\Seating\Reservations;
use PassCaptain\Seating\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Seating {
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		Reservations::register();
		Picker::register();
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( 'wpcap_availability_flushed', array( \PassCaptain\Seating\Layouts::class, 'flush_links' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( AdminPage::class, 'enqueue' ) );
	}

	public static function schema( $prefix, $charset ) {
		return Layouts::schema( $prefix, $charset );
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Saalpläne', 'pass-captain' ), Layouts::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_seating_create' => array( AdminPage::class, 'handle_create' ),
			'wpcap_seating_save' => array( AdminPage::class, 'handle_save' ),
			'wpcap_seating_link' => array( AdminPage::class, 'handle_link' ),
			'wpcap_seating_unlink' => array( AdminPage::class, 'handle_unlink' ),
		);
	}
}
