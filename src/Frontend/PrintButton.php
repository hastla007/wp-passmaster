<?php
/**
 * Print button of the plugin's standalone HTML pages (ticket page, invoice, receipt, attendee list,
 * settlement) without inline JavaScript: the button carries data-wpcap-print and assets/print.js
 * (same origin, versioned) handles the click. Keeps these pages compatible with a strict CSP
 * (script-src 'self', no 'unsafe-inline'), audit FE-8.
 */
namespace PassCaptain\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PrintButton {
	/** Versioned URL of assets/print.js. */
	public static function script_url() {
		return add_query_arg( 'ver', WPCAP_VERSION, plugins_url( 'assets/print.js', WPCAP_FILE ) );
	}

	/** External script tag (deferred) for assets/print.js. */
	public static function script_tag() {
		return '<script src="' . esc_attr( self::script_url() ) . '" defer></script>';
	}

	/**
	 * Print button plus the script tag.
	 *
	 * @param string $label Visible label.
	 * @param string $class Optional CSS class.
	 */
	public static function html( $label, $class = '' ) {
		return '<button type="button" data-wpcap-print' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>' . self::script_tag();
	}
}
