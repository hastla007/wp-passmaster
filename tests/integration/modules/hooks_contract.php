<?php
/**
 * Contract test for the versioned public hooks: every `*_v1` hook documented in docs/HOOKS.md (as a
 * table row) appears in the plugin code, and every `*_v1` hook name used in the code is documented.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "Hooks contract: versioned *_v1 hooks in docs/HOOKS.md match the code\n";
$hc_doc = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
preg_match_all( '/^\|\s*`((?:wp_pass_captain|wpcap)_[a-z0-9_]+_v1)`\s*\|/m', $hc_doc, $hc_m );
$hc_documented = array_values( array_unique( $hc_m[1] ) );
sort( $hc_documented );

$hc_code = array();
$hc_files = array();
foreach ( array( 'src', 'templates' ) as $hc_dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . $hc_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' !== $file->getExtension() ) { continue; }
		$src = (string) file_get_contents( $file->getPathname() );
		// String literals only (calls, add_filter, class constants) – mentions in comments do not count.
		foreach ( token_get_all( $src ) as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && preg_match( '/^[\'"]((?:wp_pass_captain|wpcap)_[a-z0-9_]+_v1)[\'"]$/', $token[1], $mm ) ) {
				$hc_code[ $mm[1] ] = true;
				$hc_files[ $mm[1] ][] = substr( $file->getPathname(), strlen( WPCAP_PATH ) );
			}
		}
	}
}
$hc_code = array_keys( $hc_code );
sort( $hc_code );

$hc_undocumented = array_values( array_diff( $hc_code, $hc_documented ) );
$hc_missing = array_values( array_diff( $hc_documented, $hc_code ) );
check( 'HOOKS.md documents at least the known contracts (' . count( $hc_documented ) . ' *_v1 hooks)', count( $hc_documented ) >= 18 && in_array( 'wp_pass_captain_process_job_v1', $hc_documented, true ) && in_array( 'wp_pass_captain_settings_sections_v1', $hc_documented, true ) );
check( 'every *_v1 hook used in the code is documented in HOOKS.md' . ( $hc_undocumented ? ' – missing: ' . implode( ', ', $hc_undocumented ) : '' ), array() === $hc_undocumented );
check( 'every *_v1 hook documented in HOOKS.md exists in the code' . ( $hc_missing ? ' – not found: ' . implode( ', ', $hc_missing ) : '' ), array() === $hc_missing );

// Each documented hook is actually fired (apply_filters/do_action with the literal or via a class constant).
$hc_fired = array();
$hc_all_src = '';
foreach ( array( 'src', 'templates' ) as $hc_dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . $hc_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) { if ( 'php' === $file->getExtension() ) { $hc_all_src .= file_get_contents( $file->getPathname() ) . "\n"; } }
}
foreach ( $hc_documented as $hook ) {
	$direct = preg_match( '/(apply_filters|do_action)\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $hc_all_src );
	$via_const = preg_match( '/const\s+([A-Z_]+)\s*=\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $hc_all_src, $cm ) && preg_match( '/(apply_filters|do_action)\(\s*(self|static|[A-Za-z\\\\]+)::' . $cm[1] . '\b/', $hc_all_src );
	if ( ! $direct && ! $via_const ) { $hc_fired[] = $hook; }
}
check( 'every documented *_v1 hook is fired by the core' . ( $hc_fired ? ' – never fired: ' . implode( ', ', $hc_fired ) : '' ), array() === $hc_fired );
