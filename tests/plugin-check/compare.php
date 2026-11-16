<?php
/**
 * A305: gate for WordPress "Plugin Check" (PCP) results against a baseline.
 *
 * Usage:
 *   php tests/plugin-check/compare.php <pcp-output> [baseline.json]   check (exit 1 on new errors)
 *   php tests/plugin-check/compare.php <pcp-output> --write <file>     write a new baseline
 *
 * <pcp-output> is the output of `wp plugin check pass-captain --format=json --categories=plugin_repo`
 * ("FILE: <path>" lines, each followed by a JSON array of findings). Only findings of type ERROR count.
 * The baseline holds the number of known errors per file and check code (the backlog from the first
 * run, see docs/RELEASE.md "Plugin Check"); any error above that count – a new file, a new code or
 * more of the same – fails the job. Fewer errors are reported so the baseline can be tightened.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( $argc < 2 ) { fwrite( STDERR, "usage: compare.php <pcp-output> [baseline.json | --write <file>]\n" ); exit( 2 ); }

/** Error counts "file|code" => n from PCP output. */
function wpcap_pcp_errors( $path ) {
	$counts = array();
	$file = '';
	$raw = (string) @file_get_contents( $path );
	if ( '' === trim( $raw ) ) { return $counts; }
	foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
		if ( 0 === strpos( $line, 'FILE: ' ) ) { $file = trim( substr( $line, 6 ) ); continue; }
		$rows = json_decode( $line, true );
		if ( ! is_array( $rows ) ) { continue; }
		foreach ( $rows as $row ) {
			if ( 'ERROR' !== ( $row['type'] ?? '' ) ) { continue; }
			$key = $file . '|' . (string) ( $row['code'] ?? '' );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}
	ksort( $counts );
	return $counts;
}

$found = wpcap_pcp_errors( $argv[1] );
if ( '--write' === ( $argv[2] ?? '' ) ) {
	$out = array( 'note' => 'Known Plugin Check errors per file|code (A305). Lower the numbers when fixing; never raise them without a reason in docs/RELEASE.md.', 'errors' => $found );
	file_put_contents( $argv[3], json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo 'Baseline written: ' . array_sum( $found ) . " errors\n";
	exit( 0 );
}
$baseline_file = $argv[2] ?? __DIR__ . '/baseline.json';
$baseline = json_decode( (string) @file_get_contents( $baseline_file ), true );
if ( ! is_array( $baseline ) || ! is_array( $baseline['errors'] ?? null ) ) { fwrite( STDERR, "Baseline not readable: $baseline_file\n" ); exit( 2 ); }
$known = $baseline['errors'];
$new = array();
$fixed = 0;
foreach ( $found as $key => $n ) {
	$allowed = (int) ( $known[ $key ] ?? 0 );
	if ( $n > $allowed ) { $new[ $key ] = $n - $allowed; }
}
foreach ( $known as $key => $n ) { $fixed += max( 0, (int) $n - (int) ( $found[ $key ] ?? 0 ) ); }
echo 'Plugin Check errors: ' . array_sum( $found ) . ' (baseline ' . array_sum( array_map( 'intval', $known ) ) . ")\n";
if ( $fixed ) { echo "ok   $fixed known errors are gone – lower tests/plugin-check/baseline.json (compare.php --write)\n"; }
if ( $new ) {
	echo "FAIL new Plugin Check errors:\n";
	foreach ( $new as $key => $n ) { echo "  +$n  $key\n"; }
	exit( 1 );
}
echo "ok   no new Plugin Check errors\n";
exit( 0 );
