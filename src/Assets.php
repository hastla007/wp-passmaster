<?php
/**
 * Inline scripts and styles through the WordPress script/style APIs (A302).
 *
 * Plugin Check and Content-Security-Policy setups expect inline code to go through
 * wp_add_inline_script()/wp_add_inline_style() on a registered handle (admin and theme pages) or
 * wp_get_inline_script_tag() (stand-alone pages such as the order portal, which print their own
 * document): only then can a CSP plugin add its nonce (filter wp_inline_script_attributes) and only
 * then do caching/minify plugins see the code. Without those functions (tests, very old WordPress)
 * a plain tag is printed.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Assets {
	/**
	 * Adds inline JavaScript to a handle (registered empty in the footer when it does not exist yet)
	 * and enqueues it. Works while the page body is rendered: footer scripts are printed later.
	 *
	 * @param string $handle   Script handle (own handles start with "wpcap-").
	 * @param string $js       JavaScript without <script> tags.
	 * @param string $position 'after' or 'before' the handle's file.
	 */
	public static function inline_script( $handle, $js, $position = 'after' ) {
		if ( ! function_exists( 'wp_add_inline_script' ) || ! function_exists( 'wp_script_is' ) ) {
			echo self::inline_script_tag( $js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own script.
			return false;
		}
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false, array(), WPCAP_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter -- in_footer is true.
		}
		wp_enqueue_script( $handle );
		return wp_add_inline_script( $handle, (string) $js, 'before' === $position ? 'before' : 'after' );
	}

	/**
	 * Adds inline CSS to a style handle (registered empty when needed) and enqueues it. Call on
	 * admin_enqueue_scripts / wp_enqueue_scripts.
	 */
	public static function inline_style( $handle, $css ) {
		if ( ! function_exists( 'wp_add_inline_style' ) || ! function_exists( 'wp_style_is' ) ) {
			echo '<style>' . wp_strip_all_tags( (string) $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own CSS.
			return false;
		}
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), WPCAP_VERSION );
		}
		wp_enqueue_style( $handle );
		return wp_add_inline_style( $handle, (string) $css );
	}

	/** A <script> tag for stand-alone documents (wp_get_inline_script_tag(): filterable attributes, CSP nonce). */
	public static function inline_script_tag( $js, array $attributes = array() ) {
		if ( function_exists( 'wp_get_inline_script_tag' ) ) {
			return wp_get_inline_script_tag( (string) $js, $attributes );
		}
		return '<script>' . $js . '</script>';
	}
}
