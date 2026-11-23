<?php
/**
 * Worker for tests/integration/modules/zz_a302.php, in its own process: the script/style API is
 * stubbed here (in the main process other tests rely on their own stubs). Prints one JSON object.
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';

$GLOBALS['a302'] = array( 'scripts' => array(), 'styles' => array(), 'enqueued' => array(), 'inline' => array(), 'css' => array() );
function wp_script_is( $h, $l = 'enqueued' ) { return isset( $GLOBALS['a302']['scripts'][ $h ] ); }
function wp_register_script( $h, $src, $deps = array(), $ver = false, $footer = false ) { $GLOBALS['a302']['scripts'][ $h ] = array( $src, $footer ); return true; }
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $footer = false ) { if ( '' !== $src ) { wp_register_script( $h, $src, $deps, $ver, $footer ); } $GLOBALS['a302']['enqueued'][] = $h; }
function wp_add_inline_script( $h, $js, $pos = 'after' ) { $GLOBALS['a302']['inline'][] = array( $h, $js, $pos ); return true; }
function wp_style_is( $h, $l = 'enqueued' ) { return isset( $GLOBALS['a302']['styles'][ $h ] ); }
function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['a302']['styles'][ $h ] = $src; return true; }
function wp_enqueue_style( $h ) { $GLOBALS['a302']['enqueued'][] = 'style:' . $h; }
function wp_add_inline_style( $h, $css ) { $GLOBALS['a302']['css'][] = array( $h, $css ); return true; }
function wp_get_inline_script_tag( $js, $attrs = array() ) { return '<script nonce="TESTNONCE">' . $js . '</script>'; }

$out = array();
$_GET = array( 'page' => 'wpcap-orders' );
ob_start();
PassCaptain\Admin\Menu::admin_styles();
PassCaptain\Admin\Menu::menu_heading_script();
$echoed = (string) ob_get_clean();
$out['menu_nothing_echoed'] = '' === $echoed;
$out['menu_css_on_handles'] = 2 === count( $GLOBALS['a302']['css'] ) && false !== strpos( $GLOBALS['a302']['css'][0][1], 'wpcap-menu-heading' ) && false === strpos( implode( '', array_column( $GLOBALS['a302']['css'], 1 ) ), '<style' );
$out['menu_js_footer_handle'] = 'wpcap-admin-menu' === ( $GLOBALS['a302']['inline'][0][0] ?? '' ) && false === ( $GLOBALS['a302']['scripts']['wpcap-admin-menu'][0] ?? null ) && true === ( $GLOBALS['a302']['scripts']['wpcap-admin-menu'][1] ?? null ) && in_array( 'wpcap-admin-menu', $GLOBALS['a302']['enqueued'], true ) && false === strpos( $GLOBALS['a302']['inline'][0][1], '<script' );
$_GET = array( 'page' => 'edit.php' );
$GLOBALS['a302']['css'] = array();
PassCaptain\Admin\Menu::admin_styles();
$out['plugin_css_only_on_own_pages'] = 1 === count( $GLOBALS['a302']['css'] );

// Stand-alone documents: tags from wp_get_inline_script_tag() (CSP nonce filter applies).
$tag = PassCaptain\Assets::inline_script_tag( 'var x=1;' );
$out['standalone_tag_via_api'] = '<script nonce="TESTNONCE">var x=1;</script>' === $tag;
$m = new ReflectionMethod( PassCaptain\Waitlists::class, 'submit_once_script' );
$m->setAccessible( true );
$out['waitlist_script_via_api'] = 0 === strpos( (string) $m->invoke( null ), '<script nonce="TESTNONCE">' );
$m = new ReflectionMethod( PassCaptain\Exchange\Portal::class, 'script' );
$m->setAccessible( true );
$out['exchange_script_via_api'] = 0 === strpos( (string) $m->invoke( null, 'abc', 'tok' ), '<script nonce="TESTNONCE">' );
echo json_encode( $out );
