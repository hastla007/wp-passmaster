<?php
// phpcs:ignoreFile
/**
 * A398: the offline scanner runs edge to edge (viewport-fit=cover) and keeps the top bar, the
 * content and the full-screen result clear of notch and home indicator (env(safe-area-inset-*));
 * the service worker cache was bumped so installed apps load the new stylesheet.
 */

echo "A398: offline scanner safe areas\n";
$a398_html = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/index.html' );
$a398_css = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.css' );
$a398_sw = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/sw.js' );
$a398_rule = static function ( $selector ) use ( $a398_css ) { return preg_match( '/^' . preg_quote( $selector, '/' ) . ' \{([^}]*)\}/m', $a398_css, $m ) ? $m[1] : ''; };
check( 'A398 the viewport covers the whole screen', false !== strpos( $a398_html, 'viewport-fit=cover' ) );
check( 'A398 the top bar keeps clear of the notch (top, left, right)', 1 === preg_match( '/padding: max\(10px, env\(safe-area-inset-top\)\) max\(12px, env\(safe-area-inset-right\)\) 10px max\(12px, env\(safe-area-inset-left\)\)/', $a398_rule( '.bar' ) ) );
check( 'A398 the content keeps clear of the home indicator and the side insets', false !== strpos( $a398_rule( 'main' ), 'env(safe-area-inset-bottom)' ) && false !== strpos( $a398_rule( 'main' ), 'env(safe-area-inset-left)' ) && false !== strpos( $a398_rule( 'main' ), 'env(safe-area-inset-right)' ) );
check( 'A398 the full-screen result respects all four insets', 4 === preg_match_all( '/env\(safe-area-inset-(top|right|bottom|left)\)/', $a398_rule( '.overlay' ) ) );
check( 'A398 plain padding stays as fallback before each env() rule', 1 === preg_match( '/padding: 10px 12px; padding: max\(/', $a398_rule( '.bar' ) ) && 1 === preg_match( '/padding: 24px; padding: max\(/', $a398_rule( '.overlay' ) ) );
check( 'A398 service worker cache bumped (v9 or later)', 1 === preg_match( "/wpcap-offline-scanner-v(9|[1-9][0-9]+)'/", $a398_sw ) );
