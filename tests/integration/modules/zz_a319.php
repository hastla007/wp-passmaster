<?php
/**
 * A319: contrast of the offline scanner PWA (assets/offline-scanner/app.css). Every rule with text
 * and background colour, and every result state (white text of .result on its background), has at
 * least 4.5:1; the service worker cache was bumped so installed apps get the new stylesheet.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "A319: offline scanner contrast\n";

$a319_lum = static function ( $hex ) {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
	$c = array_map( static function ( $i ) use ( $hex ) { $v = hexdec( substr( $hex, $i, 2 ) ) / 255; return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); }, array( 0, 2, 4 ) );
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$a319_ratio = static function ( $a, $b ) use ( $a319_lum ) { $x = $a319_lum( $a ); $y = $a319_lum( $b ); return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); };

$a319_css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.css' ) );
preg_match_all( '#([^{}]+)\{([^{}]*)\}#', $a319_css, $a319_m, PREG_SET_ORDER );
$a319_rules = array();
$a319_vars = array();
foreach ( $a319_m as $a319_rule ) {
	$decls = array();
	foreach ( explode( ';', $a319_rule[2] ) as $decl ) {
		if ( false === strpos( $decl, ':' ) ) { continue; }
		list( $prop, $value ) = array_map( 'trim', explode( ':', $decl, 2 ) );
		$decls[ $prop ] = $value;
		if ( 0 === strpos( $prop, '--' ) ) { $a319_vars[ $prop ] = $value; }
	}
	$a319_rules[ trim( $a319_rule[1] ) ] = $decls;
}
$a319_hex = static function ( $value ) use ( $a319_vars ) {
	if ( preg_match( '#^var\((--[\w-]+)\)$#', (string) $value, $vm ) ) { $value = $a319_vars[ $vm[1] ] ?? ''; }
	return preg_match( '#^\#[0-9a-f]{3,6}$#i', (string) $value ) ? $value : null;
};
$a319_low = array();
$a319_pairs = 0;
foreach ( $a319_rules as $a319_selector => $decls ) {
	$fg = $a319_hex( $decls['color'] ?? '' );
	$bg = $a319_hex( $decls['background'] ?? '' );
	// .result states only set the background; their text is .result's white.
	if ( ! $fg && $bg && 0 === strpos( $a319_selector, '.result.' ) ) { $fg = $a319_hex( $a319_rules['.result']['color'] ?? '' ); }
	if ( ! $fg || ! $bg ) { continue; }
	$a319_pairs++;
	$ratio = $a319_ratio( $fg, $bg );
	if ( $ratio < 4.5 ) { $a319_low[] = sprintf( '%s %s on %s = %.2f', $a319_selector, $fg, $bg, $ratio ); }
}
check( "A319 all $a319_pairs text/background pairs of the PWA have at least 4.5:1" . ( $a319_low ? ': ' . implode( '; ', $a319_low ) : '' ), $a319_pairs >= 8 && ! $a319_low );
check( 'A319 status tags and the offline pill use the darker text tones', '#0b6b2c' === ( $a319_vars['--ok-text'] ?? '' ) && '#7a5200' === ( $a319_vars['--warn-text'] ?? '' ) && 'var(--warn-text)' === ( $a319_rules['.pill.offline']['color'] ?? '' ) );
check( 'A319 result details are not faded (no opacity on white text)', ! isset( $a319_rules['.result .small']['opacity'] ) );
$a319_sw = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/sw.js' );
check( 'A319 service worker cache bumped (v5 or later) so installed apps load the new stylesheet', 1 === preg_match( "/wpcap-offline-scanner-v([5-9]|[1-9][0-9]+)'/", $a319_sw ) );
