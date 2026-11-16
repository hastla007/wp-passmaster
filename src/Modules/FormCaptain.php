<?php
/**
 * Module wrapper for the optional Form Captain adapter (src/Integrations/FormCaptain/): hooks, the
 * event ⇄ form table and the admin-post handler. Inactive without Form Captain.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Integrations\FormCaptain\Adapter;
use PassCaptain\Integrations\FormCaptain\EventForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Form Captain adapter.
 */
final class FormCaptain {
	/** Hooks (plugins_loaded). */
	public static function register() {
		Adapter::register();
	}

	/**
	 * Module schema.
	 *
	 * @param string $prefix  Table prefix.
	 * @param string $charset Charset/collation clause.
	 * @return string[]
	 */
	public static function schema( $prefix, $charset ) {
		return EventForms::schema( $prefix, $charset );
	}

	/**
	 * Admin-post handlers.
	 *
	 * @return array<string,callable>
	 */
	public static function admin_handlers() {
		return array( EventForms::ACTION => array( EventForms::class, 'handle_save' ) );
	}
}
