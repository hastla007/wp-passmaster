<?php
// phpcs:ignoreFile
/**
 * A302: inline JavaScript/CSS is no longer echoed as <script>/<style>. Admin pages use registered
 * handles (wp_add_inline_script/_style), stand-alone documents (order portal, waitlist offer page,
 * exchange form) wp_get_inline_script_tag(), so CSP nonces and Plugin Check apply. The script API runs
 * stubbed in a child process.
 */

echo "A302: inline scripts and styles through the WordPress APIs\n";
$a302_cmd = 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a302/child.php' ) . ' 2>&1';
$a302_raw = (string) shell_exec( $a302_cmd );
$a302 = json_decode( trim( $a302_raw ), true );
check( 'A302 child process ran' . ( is_array( $a302 ) ? '' : ' – ' . substr( $a302_raw, 0, 300 ) ), is_array( $a302 ) );
$a302 = is_array( $a302 ) ? $a302 : array();
check( 'A302 admin menu: nothing echoed, CSS via wp_add_inline_style, JS on a registered footer handle', ! empty( $a302['menu_nothing_echoed'] ) && ! empty( $a302['menu_css_on_handles'] ) && ! empty( $a302['menu_js_footer_handle'] ) );
check( 'A302 the page CSS is only added on Pass Captain pages', ! empty( $a302['plugin_css_only_on_own_pages'] ) );
check( 'A302 stand-alone pages print their scripts with wp_get_inline_script_tag()', ! empty( $a302['standalone_tag_via_api'] ) && ! empty( $a302['waitlist_script_via_api'] ) && ! empty( $a302['exchange_script_via_api'] ) );

// No echoed <script> left in src/ (Assets is the only place that builds a fallback tag).
$a302_hits = array();
$a302_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $a302_it as $a302_file ) {
	if ( 'php' !== $a302_file->getExtension() || 'Assets.php' === $a302_file->getFilename() ) { continue; }
	foreach ( file( $a302_file->getPathname() ) as $a302_n => $a302_line ) {
		if ( preg_match( '/<script\b(?![^>]*\bsrc=)/i', $a302_line ) && ! preg_match( '/^\s*(\*|\/\/)/', $a302_line ) ) { $a302_hits[] = basename( $a302_file->getPathname() ) . ':' . ( $a302_n + 1 ); }
	}
}
check( 'A302 no hand-written inline <script> left in src/' . ( $a302_hits ? ' – ' . implode( ', ', $a302_hits ) : '' ), ! $a302_hits );
$a302_menu = (string) file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' );
check( 'A302 no <style> echoed into admin pages (Menu)', false === strpos( $a302_menu, "echo '<style" ) && false !== strpos( $a302_menu, "add_action( 'admin_enqueue_scripts', array( self::class, 'admin_styles' ) )" ) );
$a302_fallback = PassCaptain\Assets::inline_script_tag( 'var y=2;' );
check( 'A302 without the WordPress API a plain tag is the fallback', function_exists( 'wp_get_inline_script_tag' ) || '<script>var y=2;</script>' === $a302_fallback );
