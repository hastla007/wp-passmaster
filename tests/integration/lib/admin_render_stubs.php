<?php
/**
 * Minimal WordPress template functions for module tests that render an admin screen, so a test does
 * not depend on another module (admin_screens.php, ops_status.php, …) having defined them first.
 * Every stub is guarded: real or earlier definitions win.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="nonce">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return (string) $url; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'disabled' ) ) { function disabled( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $text = '' ) { echo '<button type="submit">' . esc_html( (string) $text ) . '</button>'; } }
