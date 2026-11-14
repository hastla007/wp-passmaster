<?php
/**
 * Release gate (build/verify-release.sh, used by .github/workflows/release.yml): runs the shell
 * test suite tests/release/verify-release.test.sh so CI exercises it with the integration tests.
 * Included from run.php (uses check()). Skipped when bash, git or jq are missing.
 */
// phpcs:ignoreFile

echo "Release gate: tag must be on main, CI green for the exact SHA, versions matching\n";
$rg_tools = array();
foreach ( array( 'bash', 'git', 'jq' ) as $rg_tool ) {
	if ( '' === trim( (string) shell_exec( 'command -v ' . escapeshellarg( $rg_tool ) . ' 2>/dev/null' ) ) ) { $rg_tools[] = $rg_tool; }
}
if ( $rg_tools ) {
	echo '  skip release gate shell tests (missing: ' . implode( ', ', $rg_tools ) . ")\n";
} else {
	$rg_out = array();
	$rg_code = 1;
	exec( 'bash ' . escapeshellarg( dirname( __DIR__, 2 ) . '/release/verify-release.test.sh' ) . ' 2>&1', $rg_out, $rg_code );
	$rg_failed = array_values( array_filter( $rg_out, static fn( $l ) => 0 === strpos( ltrim( $l ), 'FAIL' ) ) );
	check( 'release gate shell tests pass (' . ( preg_grep( '/passed, \d+ failed/', $rg_out ) ? implode( '', preg_grep( '/passed, \d+ failed/', $rg_out ) ) : 'no summary' ) . ')' . ( $rg_failed ? ' – ' . implode( ' | ', array_map( 'trim', $rg_failed ) ) : '' ), 0 === $rg_code && in_array( 'ALL PASSED', $rg_out, true ) );
	check( 'release workflow runs the gate before building', (bool) preg_match( '/bash build\/verify-release\.sh "\$TAG" "\$RELEASE_SHA".*?name: Build ZIP/s', (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/release.yml' ) ) );
}
