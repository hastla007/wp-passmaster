<?php
/**
 * Checkout block integration: loads the participant field renderer (assets/woocommerce/attendees.js).
 *
 * Only loaded when WooCommerce Blocks fires woocommerce_blocks_checkout_block_registration, so the
 * interface is guaranteed to exist.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class BlocksIntegration implements IntegrationInterface {
	public function get_name() {
		return Attendees::HANDLE;
	}

	public function initialize() {
		wp_register_script(
			Attendees::HANDLE,
			WPCAP_URL . 'assets/woocommerce/attendees.js',
			array( 'wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-data' ),
			WPCAP_VERSION,
			true
		);
		// Only registered here: initialize() runs on every request with WooCommerce (also wp-admin).
		// The stylesheet is enqueued together with the checkout block's assets.
		wp_register_style( Attendees::HANDLE, WPCAP_URL . 'assets/woocommerce/attendees.css', array(), WPCAP_VERSION );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( __CLASS__, 'enqueue_style' ) );
	}

	/** Checkout block assets are being enqueued: add the attendee field styles. */
	public static function enqueue_style() {
		wp_enqueue_style( Attendees::HANDLE );
	}

	public function get_script_handles() {
		return array( Attendees::HANDLE );
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		return Attendees::script_data();
	}
}
