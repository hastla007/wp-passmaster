<?php
/**
 * Guard for single runs (WPCAP_TEST_ONLY): no module calls a helper function that only another module
 * defines. Shared helpers belong in tests/integration/lib/ (fixtures_shared.php, fixtures_fiscal.php,
 * fixtures_modules.php …), loaded by run.php or with require_once by the modules that use them.
 *
 * Static check over the tokens of every module file: functions defined at the top level of the file
 * (not methods, not stubs behind function_exists(), not worker branches) are collected per file; a call of such a function from another module file is a finding unless the name
 * is also defined in run.php, bootstrap.php, the core test files or tests/integration/lib/.
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "Test helpers: no module depends on helpers of another module\n";

/**
 * Known exceptions: module => called functions that are still defined in another module, with the reason.
 * Keep this list short; move a helper to lib/ instead of adding an entry.
 */
$hg_allowed = array(
	// w9_* live in zzzzz_w9_access.php, which the active development branch changes at the moment.
	'zzzzz_x3_residuals.php' => array( 'w9_admin', 'w9_type', 'w9_order', 'w9_as', 'w9_last_audit' ),
	// Called only behind function_exists( 'off_pair' ): the offline package check needs the module state
	// of offline_scanner.php (registration, keys) and is skipped in a single run.
	'zz_a543.php' => array( 'off_pair' ),
);

/** File-level function definitions and calls of one PHP file: [defined[], called[]]. */
function hg_scan( $file ) {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$n = count( $tokens );
	$defined = array();
	$called = array();
	$depth = 0;
	$class_depths = array();
	$skip_ws = static function ( $i, $step ) use ( $tokens, $n ) {
		while ( $i >= 0 && $i < $n && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { $i += $step; }
		return $i;
	};
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { $depth++; continue; }
		if ( '}' === $t ) {
			$depth--;
			if ( $class_depths && end( $class_depths ) === $depth ) { array_pop( $class_depths ); }
			continue;
		}
		if ( ! is_array( $t ) ) { continue; }
		if ( in_array( $t[0], array( T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM ), true ) ) {
			$prev = $skip_ws( $i - 1, -1 );
			if ( ! ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_DOUBLE_COLON === $tokens[ $prev ][0] ) ) { $class_depths[] = $depth; }
			continue;
		}
		if ( T_FUNCTION === $t[0] ) {
			$next = $skip_ws( $i + 1, 1 );
			if ( '&' === ( $tokens[ $next ] ?? null ) ) { $next = $skip_ws( $next + 1, 1 ); }
			// Only top-level definitions are helpers of a module: stubs of WordPress/WooCommerce functions sit
			// behind function_exists() (the first definition of a run wins), worker branches inside an if block.
			if ( 0 === $depth && ! $class_depths && is_array( $tokens[ $next ] ?? null ) && T_STRING === $tokens[ $next ][0] ) { $defined[ strtolower( $tokens[ $next ][1] ) ] = true; }
			continue;
		}
		if ( T_STRING === $t[0] ) {
			$next = $skip_ws( $i + 1, 1 );
			$prev = $skip_ws( $i - 1, -1 );
			$prev_id = $prev >= 0 ? ( is_array( $tokens[ $prev ] ) ? $tokens[ $prev ][0] : $tokens[ $prev ] ) : null;
			if ( '(' === ( $tokens[ $next ] ?? null ) && ! in_array( $prev_id, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ), true ) ) {
				$called[ strtolower( $t[1] ) ] = true;
			}
		}
	}
	return array( array_keys( $defined ), array_keys( $called ) );
}

$hg_dir = dirname( __DIR__ );
$hg_base = array();
foreach ( array_merge( glob( $hg_dir . '/*.php' ), glob( $hg_dir . '/lib/*.php' ) ) as $hg_file ) {
	foreach ( hg_scan( $hg_file )[0] as $hg_fn ) { $hg_base[ $hg_fn ] = true; }
}
$hg_defs = array();
$hg_calls = array();
foreach ( glob( __DIR__ . '/*.php' ) as $hg_file ) {
	list( $hg_d, $hg_c ) = hg_scan( $hg_file );
	foreach ( $hg_d as $hg_fn ) { $hg_defs[ $hg_fn ][] = basename( $hg_file ); }
	$hg_calls[ basename( $hg_file ) ] = $hg_c;
}
$hg_findings = array();
$hg_used_allowances = array();
foreach ( $hg_calls as $hg_module => $hg_fns ) {
	foreach ( $hg_fns as $hg_fn ) {
		if ( isset( $hg_base[ $hg_fn ] ) || ! isset( $hg_defs[ $hg_fn ] ) || in_array( $hg_module, $hg_defs[ $hg_fn ], true ) ) { continue; }
		if ( in_array( $hg_fn, $hg_allowed[ $hg_module ] ?? array(), true ) ) { $hg_used_allowances[ $hg_module . ':' . $hg_fn ] = true; continue; }
		$hg_findings[] = "$hg_module calls $hg_fn() from " . implode( ', ', array_unique( $hg_defs[ $hg_fn ] ) );
	}
}
sort( $hg_findings );
check( 'every helper a module calls is defined in that module or in tests/integration/lib/' . ( $hg_findings ? ': ' . implode( '; ', array_slice( $hg_findings, 0, 8 ) ) : '' ), array() === $hg_findings );

// Stale exceptions are removed from the list (an entry that no longer matches hides nothing, but misleads).
$hg_stale = array();
foreach ( $hg_allowed as $hg_module => $hg_fns ) {
	foreach ( $hg_fns as $hg_fn ) { if ( empty( $hg_used_allowances[ $hg_module . ':' . $hg_fn ] ) ) { $hg_stale[] = "$hg_module:$hg_fn"; } }
}
check( 'the exception list of the helper guard has no stale entries' . ( $hg_stale ? ': ' . implode( ', ', $hg_stale ) : '' ), array() === $hg_stale );

// The guard itself finds a cross-module helper (negative test with two temporary module files).
$hg_tmp = sys_get_temp_dir() . '/wpcap-hg-' . bin2hex( random_bytes( 4 ) );
mkdir( $hg_tmp );
file_put_contents( $hg_tmp . '/a.php', "<?php\nfunction hg_probe_helper() { return 1; }\nclass Hg_Probe { public function hg_probe_method() {} }\nif ( ! function_exists( 'hg_probe_stub' ) ) { function hg_probe_stub() {} }\n" );
file_put_contents( $hg_tmp . '/b.php', "<?php\necho hg_probe_helper();\n\$x = new Hg_Probe();\n\$x->hg_probe_method();\n" );
list( $hg_ad ) = hg_scan( $hg_tmp . '/a.php' );
list( , $hg_bc ) = hg_scan( $hg_tmp . '/b.php' );
unlink( $hg_tmp . '/a.php' );
unlink( $hg_tmp . '/b.php' );
rmdir( $hg_tmp );
check( 'helper guard: file-level functions count as definitions, methods and function_exists() stubs do not; calls are found', array( 'hg_probe_helper' ) === $hg_ad && in_array( 'hg_probe_helper', $hg_bc, true ) && ! in_array( 'hg_probe_method', $hg_bc, true ) );

// The shared helpers load in a single run of a module that uses them.
require_once $hg_dir . '/lib/fixtures_shared.php';
check( 'shared helpers load from tests/integration/lib/fixtures_shared.php', function_exists( 'woo_t_order' ) && function_exists( 'w6_matrix' ) && function_exists( 'svc_paid' ) && class_exists( 'Q1_User', false ) );
