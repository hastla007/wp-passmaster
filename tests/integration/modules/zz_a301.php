<?php
/**
 * A301 (and TODO A6): the "key derived from the salts" recommendation is shown only on Pass Captain
 * pages, can be dismissed per user (user meta) and is listed in Site Health; add-on notices go through
 * Admin\Notices (own pages only, plain text, always dismissible). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Notices as A301Notices;
use PassCaptain\Secrets as A301Secrets;

echo "A301: plugin notices only on own pages, dismissible\n";
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $f = '<input type="hidden" name="_wpnonce" value="n">'; if ( $echo ) { echo $f; } return $f; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }

$a301_get = $_GET;
$a301_had_key = '' !== A301Secrets::get( 'sms_http_secret' );
if ( ! $a301_had_key ) { A301Secrets::set( 'sms_http_secret', 'a301-test-value' ); }
$GLOBALS['wpcap_test_user_id'] = 301;
unset( $GLOBALS['p1u_user_meta'][301] );
$a301_render = static function ( $page ) {
	$_GET = '' === $page ? array() : array( 'page' => $page );
	ob_start();
	A301Secrets::admin_notice();
	return (string) ob_get_clean();
};
check( 'A301 fixture: credentials stored with the salts-derived key', 'salts' === A301Secrets::key_source() );
check( 'A301 no Pass Captain hint on other admin pages (e.g. Posts, other plugins)', '' === $a301_render( '' ) && '' === $a301_render( 'woocommerce' ) );
$a301_own = $a301_render( 'wpcap-settings' );
check( 'A301 the recommendation appears on Pass Captain pages, with a dismiss button', false !== strpos( $a301_own, 'WPCAP_SECRETS_KEY' ) && false !== strpos( $a301_own, 'name="action" value="' . A301Notices::ACTION . '"' ) && false !== strpos( $a301_own, 'value="' . A301Secrets::SALTS_NOTICE . '"' ) );
A301Notices::dismiss( A301Secrets::SALTS_NOTICE );
check( 'A301 dismissed per user (user meta): hidden for this user', '' === $a301_render( 'wpcap-settings' ) && A301Notices::dismissed( A301Secrets::SALTS_NOTICE ) );
$GLOBALS['wpcap_test_user_id'] = 302;
check( 'A301 other users still see it', false !== strpos( $a301_render( 'pass-captain' ), 'WPCAP_SECRETS_KEY' ) );
$GLOBALS['wpcap_test_user_id'] = 301;
$a301_health = A301Secrets::site_health_test();
$a301_tests = A301Secrets::site_status_tests( array() );
check( 'A301 Site Health keeps listing the recommendation (not dismissible there)', 'recommended' === $a301_health['status'] && false !== strpos( $a301_health['description'], 'WPCAP_SECRETS_KEY' ) && isset( $a301_tests['direct']['wpcap_secrets_key'] ) );

// Add-on notices (TODO A6).
$a301_addon = static function () { return array( array( 'id' => 'promo', 'message' => 'Mehr Funktionen im Add-on <script>x</script>', 'url' => 'https://example.test/addon', 'link' => 'Ansehen' ) ); };
add_filter( 'wpcap_addon_notices', $a301_addon );
$a301_addon_html = static function ( $page ) { $_GET = '' === $page ? array() : array( 'page' => $page ); ob_start(); A301Notices::addon_notices(); return (string) ob_get_clean(); };
check( 'A6 add-on notices never on other admin screens', '' === $a301_addon_html( '' ) && '' === $a301_addon_html( 'edit.php' ) );
$a301_a = $a301_addon_html( 'wpcap-events' );
check( 'A6 add-on notices on own pages: info, plain text, with dismiss button', false !== strpos( $a301_a, 'notice-info' ) && false === strpos( $a301_a, '<script>' ) && false !== strpos( $a301_a, 'https://example.test/addon' ) && false !== strpos( $a301_a, 'value="addon_promo"' ) );
A301Notices::dismiss( 'addon_promo' );
check( 'A6 a dismissed add-on notice stays hidden', '' === $a301_addon_html( 'wpcap-events' ) );
remove_filter( 'wpcap_addon_notices', $a301_addon );

$_GET = $a301_get;
$GLOBALS['wpcap_test_user_id'] = 1;
unset( $GLOBALS['p1u_user_meta'][301], $GLOBALS['p1u_user_meta'][302] );
if ( ! $a301_had_key ) { A301Secrets::set( 'sms_http_secret', '' ); }
