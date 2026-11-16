<?php
/**
 * WordPress admin stubs shared by the setup/settings UX tests (A289–A297). Every stub is guarded,
 * so the files also run alone (filtered runs) and after other module tests that define them.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id = 0 ) { return 'https://example.test/?page_id=' . (int) $id; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( 'wp_die: ' . $m ); } }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $a = -1, $q = '_wpnonce' ) { $GLOBALS['wpcap_test_nonce_checks'][] = $a; return 1; } }
if ( ! class_exists( 'Wpcap_Test_Redirect' ) ) { class Wpcap_Test_Redirect extends Exception {} }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $url, $status = 302 ) { throw new Wpcap_Test_Redirect( (string) $url ); } }

/** Creates minimal WordPress posts/postmeta tables (test prefix) and points $wpdb at them; false drops them. */
if ( ! function_exists( 'r6_posts_tables' ) ) {
	function r6_posts_tables( $create = true ) {
		global $wpdb;
		$posts = $wpdb->prefix . 'posts';
		$meta = $wpdb->prefix . 'postmeta';
		$wpdb->query( "DROP TABLE IF EXISTS $meta" );
		$wpdb->query( "DROP TABLE IF EXISTS $posts" );
		if ( ! $create ) { $wpdb->posts = ''; $wpdb->postmeta = ''; return; }
		$wpdb->query( "CREATE TABLE $posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_title text NOT NULL, post_content longtext NOT NULL, post_type varchar(20) NOT NULL DEFAULT 'post', post_status varchar(20) NOT NULL DEFAULT 'publish', PRIMARY KEY (ID)) DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE $meta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY post_id (post_id)) DEFAULT CHARSET=utf8mb4" );
		$wpdb->posts = $posts;
		$wpdb->postmeta = $meta;
	}
}
