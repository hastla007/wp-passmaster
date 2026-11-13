<?php
/**
 * A121: compares a load-test result (--json of tests/load/run.php or tests/load/http.php) with the
 * thresholds in tests/load/baselines/<name>.json and fails on regressions.
 *
 * Baseline format: { "measured": { "<scenario>": { "p95_ms": …, "p99_ms": … } },   (reference run, informational)
 *                    "limits":   { "<scenario>": { "p95_ms": …, "error_rate": … } } }
 * A scenario in "limits" that is missing from the result fails too (a silently skipped scenario is a
 * regression). Invariant failures in the result ("failures") always fail.
 *
 * Usage: php tests/load/compare.php <result.json> <baseline.json>
 */
if ( PHP_SAPI !== 'cli' || 3 !== $argc ) { fwrite( STDERR, "Usage: php tests/load/compare.php <result.json> <baseline.json>\n" ); exit( 2 ); }
$result = json_decode( (string) file_get_contents( $argv[1] ), true );
$base   = json_decode( (string) file_get_contents( $argv[2] ), true );
if ( ! is_array( $result ) || ! is_array( $base ) || empty( $base['limits'] ) ) { fwrite( STDERR, "unreadable result or baseline\n" ); exit( 2 ); }
$by = array();
foreach ( (array) ( $result['results'] ?? array() ) as $r ) { $by[ (string) $r['scenario'] ] = $r; }
$fail = 0;
foreach ( (array) ( $result['failures'] ?? array() ) as $f ) { echo 'FAIL invariant: ' . ( is_string( $f ) ? $f : json_encode( $f ) ) . "\n"; $fail = 1; }
foreach ( $base['limits'] as $scenario => $limit ) {
	if ( ! isset( $by[ $scenario ] ) ) { echo "FAIL $scenario: missing from the result\n"; $fail = 1; continue; }
	$r = $by[ $scenario ];
	$ref = $base['measured'][ $scenario ] ?? array();
	foreach ( $limit as $metric => $max ) {
		$value = (float) ( $r[ $metric ] ?? INF );
		$line = sprintf( '%s %s = %s (limit %s%s)', $scenario, $metric, $value, $max, isset( $ref[ $metric ] ) ? ', reference ' . $ref[ $metric ] : '' );
		if ( $value > (float) $max ) { echo "FAIL $line\n"; $fail = 1; } else { echo "ok   $line\n"; }
	}
}
exit( $fail );
