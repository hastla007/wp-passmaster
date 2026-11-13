<?php
/**
 * E-mail template editor ("E-Mail-Vorlagen").
 *
 * Lists all customer mail types of the central mailer (PassCaptain\Mail\Mailer) and lets
 * administrators override subject and body per type and locale (text or HTML with a simple layout),
 * preview them live with sample data, send a test mail to themselves and reset to the built-in text.
 * Overrides live in the option `wpcap_mail_templates`; layout settings (logo, colour, footer) in
 * the plugin settings key `mail_layout`. Code: src/Mail/.
 */
namespace PassCaptain\Modules;

use PassCaptain\Mail\Admin;
use PassCaptain\Mail\Layout;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MailTemplates {
	public static function register() {
		add_action( 'wp_ajax_' . Admin::ACTION_PREVIEW, array( Admin::class, 'handle_preview_ajax' ) );
	}

	public static function settings_defaults() {
		return array(
			'mail_layout' => array(
				'logo_url' => '',
				'color' => Layout::DEFAULT_COLOR,
				'footer' => '',
			),
		);
	}

	public static function admin_pages() {
		return array( array( Admin::PAGE, __( 'E-Mail-Vorlagen', 'pass-captain' ), Admin::CAP, array( Admin::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			Admin::ACTION_SAVE => array( Admin::class, 'handle_save' ),
			Admin::ACTION_RESET => array( Admin::class, 'handle_reset' ),
			Admin::ACTION_TEST => array( Admin::class, 'handle_test' ),
			Admin::ACTION_LAYOUT => array( Admin::class, 'handle_layout' ),
		);
	}
}
