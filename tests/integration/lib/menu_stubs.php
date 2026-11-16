<?php
/**
 * Recording stubs for add_menu_page()/add_submenu_page(), shared by the module tests that inspect the
 * admin menu (zz_a088, zzzzz_x5_addon_hooks). The first definition wins for the whole run, so every
 * module must get the same recorder: each call is written to all formats the modules read
 * (A088: $GLOBALS['a088_menu'] / $GLOBALS['submenu'][parent]; X5: $GLOBALS['x5_menu'] / $GLOBALS['x5_submenu']).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null ) {
		$GLOBALS['a088_menu'][] = array( $menu_title, $cap, $slug );
		$GLOBALS['x5_menu'][] = compact( 'page_title', 'menu_title', 'cap', 'slug', 'icon' );
		return 'toplevel_page_' . $slug;
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '', $pos = null ) {
		$GLOBALS['submenu'][ $parent ][] = array( $menu_title, $cap, $slug, $page_title );
		$GLOBALS['x5_submenu'][] = array( $parent, $slug );
		return $parent . '_page_' . $slug;
	}
}
