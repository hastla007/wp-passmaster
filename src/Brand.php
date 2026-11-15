<?php
/**
 * Product name and logo shown to users (white label extension point for add-ons).
 *
 * Without filters everything stays as before: the name is "Pass Captain", there is no logo and the
 * admin menu keeps its dashicon. An add-on changes the name with `wp_pass_captain_brand_name_v1`
 * (per context), the logo with `wp_pass_captain_brand_logo_v1` and the admin menu with
 * `wp_pass_captain_admin_menu_title_v1` / `wp_pass_captain_admin_menu_icon_v1` (docs/EXTENSIONS.md,
 * "Pass Captain Pro / Add-on-Schnittstellen").
 *
 * Translated texts of the text domain that contain the product name (admin notices, admin mails,
 * the web scanner heading, the offline scanner texts …) are rewritten through the gettext filters of
 * the domain, so no source string had to change.
 *
 * Contexts: admin_menu, admin, scanner (web scanner shortcode), offline_scanner (PWA), portal (order
 * portal), ticket_page, pdf (PDF metadata), text (translated texts). Invoice documents show the seller,
 * not the product brand, and therefore get no logo.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Brand {
	const DEFAULT_NAME = 'Pass Captain';
	const DEFAULT_MENU_ICON = 'dashicons-tickets-alt';
	const NAME_FILTER = 'wp_pass_captain_brand_name_v1';
	const LOGO_FILTER = 'wp_pass_captain_brand_logo_v1';
	const MENU_TITLE_FILTER = 'wp_pass_captain_admin_menu_title_v1';
	const MENU_ICON_FILTER = 'wp_pass_captain_admin_menu_icon_v1';

	/** Contexts the core passes to the filters. */
	const CONTEXTS = array( 'admin_menu', 'admin', 'scanner', 'offline_scanner', 'portal', 'ticket_page', 'pdf', 'text' );

	/** @var bool Guards against recursion when a brand callback itself translates texts of the domain. */
	private static $resolving = false;

	/** Hooks the gettext filters of the text domain (called once from Plugin::run()). */
	public static function register() {
		add_filter( 'gettext_pass-captain', array( __CLASS__, 'filter_gettext' ), 20 );
		add_filter( 'ngettext_pass-captain', array( __CLASS__, 'filter_gettext' ), 20 );
		add_filter( 'gettext_with_context_pass-captain', array( __CLASS__, 'filter_gettext' ), 20 );
	}

	/** Product name for a context (plain text, max. 100 characters; empty results fall back to the default). */
	public static function name( $context = 'admin' ) {
		if ( self::$resolving ) { return self::DEFAULT_NAME; }
		self::$resolving = true;
		try {
			$name = apply_filters( 'wp_pass_captain_brand_name_v1', self::DEFAULT_NAME, (string) $context );
		} finally {
			self::$resolving = false;
		}
		$name = is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '';
		return '' !== $name ? mb_substr( $name, 0, 100 ) : self::DEFAULT_NAME;
	}

	/** Logo URL for a context ('' = no logo, the default). Only http(s) URLs and relative paths are accepted. */
	public static function logo( $context = 'admin' ) {
		$url = apply_filters( 'wp_pass_captain_brand_logo_v1', '', (string) $context );
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || ! preg_match( '#^(https?://|/(?!/))#i', $url ) ) { return ''; }
		return esc_url_raw( $url );
	}

	/** Replaces the default product name in a text by the name for $context (unchanged without a filter). */
	public static function text( $text, $context = 'text' ) {
		$text = (string) $text;
		if ( false === strpos( $text, self::DEFAULT_NAME ) ) { return $text; }
		$name = self::name( $context );
		if ( self::DEFAULT_NAME === $name ) { return $text; }
		// Idempotent for names that contain the default name ("Pass Captain Pro"): already replaced texts stay.
		if ( false !== strpos( $name, self::DEFAULT_NAME ) && false !== strpos( $text, $name ) ) { return $text; }
		return str_replace( self::DEFAULT_NAME, $name, $text );
	}

	/** gettext_pass-captain / ngettext_pass-captain / gettext_with_context_pass-captain filter. */
	public static function filter_gettext( $translation ) {
		return is_string( $translation ) ? self::text( $translation, 'text' ) : $translation;
	}

	/** Title of the core admin menu entry (default: the brand name for context admin_menu). */
	public static function menu_title() {
		$title = apply_filters( 'wp_pass_captain_admin_menu_title_v1', self::name( 'admin_menu' ) );
		$title = is_scalar( $title ) ? sanitize_text_field( (string) $title ) : '';
		return '' !== $title ? mb_substr( $title, 0, 100 ) : self::DEFAULT_NAME;
	}

	/**
	 * Icon of the core admin menu entry: a dashicons class, an http(s) image URL, a data:image/svg+xml;base64
	 * URI or "none" (as add_menu_page() accepts); anything else falls back to the default dashicon.
	 */
	public static function menu_icon() {
		$icon = apply_filters( 'wp_pass_captain_admin_menu_icon_v1', self::DEFAULT_MENU_ICON );
		$icon = is_string( $icon ) ? trim( $icon ) : '';
		if ( preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) || 'none' === $icon || preg_match( '#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $icon ) ) { return $icon; }
		if ( preg_match( '#^https?://#i', $icon ) ) { return esc_url_raw( $icon ); }
		return self::DEFAULT_MENU_ICON;
	}

	/** Escaped <img> of the logo for a context, or '' without a logo (default). */
	public static function logo_html( $context, $max_height = 64 ) {
		$logo = self::logo( $context );
		if ( '' === $logo ) { return ''; }
		return '<p class="wpcap-brand-logo"><img src="' . esc_attr( $logo ) . '" alt="' . esc_attr( self::name( $context ) ) . '" style="max-width:240px;max-height:' . (int) $max_height . 'px;height:auto;width:auto"></p>';
	}

	/** Brand data for clients that render it themselves (offline scanner PWA). */
	public static function data( $context ) {
		$name = self::name( $context );
		$logo = self::logo( $context );
		return array( 'name' => $name, 'logo' => $logo, 'custom' => self::DEFAULT_NAME !== $name || '' !== $logo );
	}
}
