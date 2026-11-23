<?php
/**
 * Former `wp-` prefixed names of blocks and shortcodes (A303).
 *
 * The blocks are called `pass-captain/events` and `pass-captain/calendar` (like
 * `pass-captain/service-booking`); the shortcodes `[wpcap_events]`, `[wpcap_calendar]` and `[wpcap_scanner]`.
 * The former names stay registered as deprecated aliases because they are stored in post content:
 * - blocks `wp-pass-captain/events` / `wp-pass-captain/calendar` render as before, are hidden in the
 *   inserter and are converted to the new name when the post is opened in the block editor
 *   (assets/blocks/legacy-names.js);
 * - shortcodes `[wp_pass_captain_events]`, `[wp_pass_captain_calendar]`, `[wp_pass_captain_scanner]`
 *   keep working and are reported as deprecated (only with WP_DEBUG, via _deprecated_function()).
 *
 * The versioned cross-plugin hooks `wp_pass_captain_*_v1` are not renamed here: they are a contract
 * with the partner plugins (docs/HOOKS.md, Namensregel A104).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

defined( 'ABSPATH' ) || exit;

final class LegacyNames {
	const EVENTS_BLOCK   = 'pass-captain/events';
	const CALENDAR_BLOCK = 'pass-captain/calendar';

	/** Former block name => current block name. */
	const BLOCKS = array(
		'wp-pass-captain/events'   => self::EVENTS_BLOCK,
		'wp-pass-captain/calendar' => self::CALENDAR_BLOCK,
	);

	/** Former shortcode => current shortcode. */
	const SHORTCODES = array(
		'wp_pass_captain_events'   => 'wpcap_events',
		'wp_pass_captain_calendar' => 'wpcap_calendar',
		'wp_pass_captain_scanner'  => 'wpcap_scanner',
	);

	/** Version in which the former names were deprecated. */
	const DEPRECATED_SINCE = '1.5.0';

	/** Script handle of the editor conversion of the former block names. */
	const SCRIPT = 'wpcap-legacy-blocks';

	/** Block names (current first, then the former one) for a current block name. */
	public static function block_names( $block ) {
		return array_merge( array( (string) $block ), array_keys( self::BLOCKS, (string) $block, true ) );
	}

	/** Shortcode tags (current first, then the former one) for a current shortcode. */
	public static function shortcodes( $tag ) {
		return array_merge( array( (string) $tag ), array_keys( self::SHORTCODES, (string) $tag, true ) );
	}

	/** Whether serialized block content contains the block under its current or former name. */
	public static function content_has_block( $content, $block ) {
		foreach ( self::block_names( $block ) as $name ) {
			if ( false !== strpos( (string) $content, '<!-- wp:' . $name . ' ' ) || false !== strpos( (string) $content, '<!-- wp:' . $name . '-->' ) ) { return true; }
		}
		return false;
	}

	/** Whether content contains the shortcode under its current or former tag. */
	public static function content_has_shortcode( $content, $tag ) {
		foreach ( self::shortcodes( $tag ) as $name ) {
			if ( function_exists( 'has_shortcode' ) ? has_shortcode( (string) $content, $name ) : false !== strpos( (string) $content, '[' . $name ) ) { return true; }
		}
		return false;
	}

	/**
	 * Reports the use of a former shortcode tag as deprecated (shown by WordPress only with WP_DEBUG).
	 * Returns whether $tag is a former tag.
	 */
	public static function shortcode_used( $tag ) {
		$tag = (string) $tag;
		if ( ! isset( self::SHORTCODES[ $tag ] ) ) { return false; }
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( esc_html( '[' . $tag . ']' ), esc_html( self::DEPRECATED_SINCE ), esc_html( '[' . self::SHORTCODES[ $tag ] . ']' ) );
		}
		return true;
	}

	/**
	 * Registers the former name of a block registered from block.json as a hidden alias with the same
	 * attributes, supports, styles and render callback. The editor converts it to the current name.
	 *
	 * @param \WP_Block_Type|false $block Result of register_block_type() for the current name.
	 * @return \WP_Block_Type|false The alias, false when nothing was registered.
	 */
	public static function register_block_alias( $block ) {
		if ( ! is_object( $block ) || ! function_exists( 'register_block_type' ) ) { return false; }
		$old = array_search( (string) $block->name, self::BLOCKS, true );
		if ( false === $old ) { return false; }
		if ( class_exists( '\WP_Block_Type_Registry' ) && \WP_Block_Type_Registry::get_instance()->is_registered( $old ) ) { return false; }
		$supports = is_array( $block->supports ?? null ) ? $block->supports : array();
		$supports['inserter'] = false;
		$args = array(
			'api_version'     => $block->api_version ?? 3,
			'title'           => (string) ( $block->title ?? $old ),
			'category'        => $block->category ?? 'widgets',
			'icon'            => $block->icon ?? null,
			'attributes'      => is_array( $block->attributes ?? null ) ? $block->attributes : array(),
			'supports'        => $supports,
			'style_handles'   => (array) ( $block->style_handles ?? array() ),
			'render_callback' => $block->render_callback ?? null,
		);
		if ( function_exists( 'wp_register_script' ) && defined( 'WPCAP_URL' ) ) {
			wp_register_script( self::SCRIPT, WPCAP_URL . 'assets/blocks/legacy-names.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-data', 'wp-i18n' ), defined( 'WPCAP_VERSION' ) ? WPCAP_VERSION : false, true );
			if ( function_exists( 'wp_set_script_translations' ) ) { wp_set_script_translations( self::SCRIPT, 'pass-captain', Localization::languages_dir() ); }
			$args['editor_script_handles'] = array( self::SCRIPT );
		}
		return register_block_type( $old, $args );
	}
}
