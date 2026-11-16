<?php
// phpcs:ignoreFile
/**
 * A396: the menu area headings ("Verkauf", "Einlass" …) are readable in every core admin colour
 * scheme: a light scheme gets dark text instead of the fixed #c3c4c7 (1.5:1 on #fff).
 */
use PassCaptain\Admin\Menu;

echo "A396: menu headings per admin colour scheme\n";
$a396_css = Menu::menu_heading_css();
$a396_lum = static function ( $hex ) {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
	$c = array_map( static function ( $i ) use ( $hex ) { $v = hexdec( substr( $hex, $i, 2 ) ) / 255; return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4; }, array( 0, 2, 4 ) );
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$a396_ratio = static function ( $a, $b ) use ( $a396_lum ) { $x = array( $a396_lum( $a ), $a396_lum( $b ) ); rsort( $x ); return ( $x[0] + 0.05 ) / ( $x[1] + 0.05 ); };
/** Text colour of the heading label for a body class prefix ('' = default). */
$a396_color = static function ( $prefix ) use ( $a396_css ) {
	return preg_match( '/' . preg_quote( $prefix . '#adminmenu li.wpcap-menu-heading .wpcap-menu-heading-label', '/' ) . '\{[^}]*color:(#[0-9a-f]{3,6})/i', $a396_css, $m ) ? $m[1] : '';
};
// Submenu backgrounds of the core schemes (wp-admin/css/colors/*/_variables.scss).
$a396_schemes = array( '' => '#2c3338', 'body.admin-color-light ' => '#ffffff', 'body.admin-color-blue ' => '#4796b3', 'body.admin-color-sunrise ' => '#be3631' );
$a396_low = array();
foreach ( $a396_schemes as $a396_prefix => $a396_bg ) {
	$a396_fg = $a396_color( $a396_prefix );
	if ( '' === $a396_fg || $a396_ratio( $a396_fg, $a396_bg ) < 4.5 ) { $a396_low[] = ( $a396_prefix ?: 'default' ) . ' ' . $a396_fg . ' on ' . $a396_bg; }
}
check( 'A396 the heading text has at least 4.5:1 on the submenu of the default, light, blue and sunrise schemes' . ( $a396_low ? ' – ' . implode( '; ', $a396_low ) : '' ), ! $a396_low );
check( 'A396 the light scheme gets dark text and a dark separator', '#50575e' === $a396_color( 'body.admin-color-light ' ) && false !== strpos( $a396_css, 'body.admin-color-light #adminmenu li.wpcap-menu-heading{border-top-color:rgba(0,0,0,.15)}' ) );
check( 'A396 the ocean scheme uses white (the best contrast it allows)', '#fff' === $a396_color( 'body.admin-color-ocean ' ) && $a396_ratio( '#fff', '#627c83' ) > 4.4 );
check( 'A396 the link fallback (before the script turns it into text) has the same colours', false !== strpos( $a396_css, 'body.admin-color-light #adminmenu li.wpcap-menu-heading a,body.admin-color-light #adminmenu li.wpcap-menu-heading .wpcap-menu-heading-label{color:#50575e}' ) );
