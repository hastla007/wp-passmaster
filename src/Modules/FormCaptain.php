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
use PassCaptain\Integrations\FormCaptain\EventRequests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Form Captain adapter.
 *
 * @internal Not part of the public add-on API (A402): may change in any release.
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
		return array_merge( EventForms::schema( $prefix, $charset ), EventRequests::schema( $prefix, $charset ) );
	}

	/**
	 * Admin-post handlers.
	 *
	 * @return array<string,callable>
	 */
	public static function admin_handlers() {
		return array(
			EventForms::ACTION           => array( EventForms::class, 'handle_save' ),
			EventRequests::ACTION_BOOK   => array( EventRequests::class, 'handle_book' ),
			EventRequests::ACTION_REJECT => array( EventRequests::class, 'handle_reject' ),
		);
	}
}
