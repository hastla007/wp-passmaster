<?php
/**
 * Upgrade test helper (A181): creates the schema of an OLD plugin version.
 *
 * Runs that version's own code and integration test bootstrap (a `git archive` of the tag, extracted
 * by tests/upgrade/run.php) against an empty table prefix and prints the schema version and options
 * that version stored, as JSON. Nothing of the current code is loaded here.
 *
 * Usage: WPCAP_TEST_PREFIX=<prefix> php tests/upgrade/legacy.php <extracted-tree>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$tree = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $tree || ! is_readable( $tree . '/tests/integration/bootstrap.php' ) ) {
	fwrite( STDERR, "usage: legacy.php <extracted plugin tree>\n" );
	exit( 2 );
}
require $tree . '/tests/integration/bootstrap.php';
// The old bootstrap's dbDelta stub runs the old CREATE TABLE statements (tables do not exist yet).
wpcap_test_install();
echo json_encode(
	array(
		'version' => PassCaptain\Installer::version(),
		'options' => $GLOBALS['wpcap_test_options'],
	)
);
