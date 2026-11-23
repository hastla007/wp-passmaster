<?php
/**
 * SET-PC-06: Pro hint lines – static text, hideable per person, replaced by an add-on field with the same id.
 */
use PassCaptain\Admin\ProHints;

echo "SET-PC-06: Pro hint lines\n";
check( 'SET-PC-06: no hint line ships until a Pro function is sold (E8/S5)', array() === ProHints::all() );
$sp06_hints = array( array( 'id' => 'pro_sp06_designer', 'tab' => 'general', 'title' => 'Ticketdesigner', 'text' => 'Gestalte Tickets frei.', 'link' => 'https://example.com/pro' ) );
check( 'SET-PC-06: a hint shows on its tab only', 1 === count( ProHints::for_tab( 'general', $sp06_hints ) ) && array() === ProHints::for_tab( 'payments', $sp06_hints ) );
ob_start();
ProHints::render( 'general', $sp06_hints );
$sp06_html = ob_get_clean();
check( 'SET-PC-06: the line is plain text with a hide button, no form field for the function', false !== strpos( $sp06_html, 'Ticketdesigner' ) && false !== strpos( $sp06_html, 'wpcap_pro_hint_hide' ) && false === strpos( $sp06_html, 'name="pro_sp06_designer"' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'SET-PC-06: only manage_options sees hint lines', array() === ProHints::for_tab( 'general', $sp06_hints ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$sp06_f = static function ( $sections ) { $sections['addon_sp06'] = array( 'title' => 'Pro', 'tab' => 'general', 'addon' => true, 'fields' => array( 'pro_sp06_designer' => array( 'type' => 'bool', 'label' => 'Designer' ) ) ); return $sections; };
add_filter( 'wp_pass_captain_settings_sections_v1', $sp06_f );
check( 'SET-PC-06: an add-on field with the same id replaces the line', array() === ProHints::for_tab( 'general', $sp06_hints ) );
remove_filter( 'wp_pass_captain_settings_sections_v1', $sp06_f );
ProHints::hide( 'pro_sp06_designer' );
check( 'SET-PC-06: a hidden line stays hidden for this person', array() === ProHints::for_tab( 'general', $sp06_hints ) && in_array( 'pro_sp06_designer', ProHints::hidden(), true ) );
update_user_option( get_current_user_id(), ProHints::META, array() );
$sp06_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/ProHints.php' );
check( 'SET-PC-06: the hint list contains no licence or entitlement code', ! preg_match( '/licen[cs]e_?(key|client|check)|entitlement|allows_|wplc/i', $sp06_src ) );
