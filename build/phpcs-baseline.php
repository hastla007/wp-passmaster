<?php
/**
 * PHP_CodeSniffer with a baseline: known findings are frozen in phpcs-baseline.json, new ones fail.
 *
 * The baseline counts findings per file and sniff code (not per line), so it survives unrelated
 * edits and merges. A file/sniff pair whose count grows fails the check and the findings of that
 * pair are printed; fixed findings only produce a hint to tighten the baseline.
 *
 * Usage:  php build/phpcs-baseline.php            check (exit 1 on new findings)
 *         php build/phpcs-baseline.php --update   rewrite phpcs-baseline.json from the current state
 *
 * Ruleset: phpcs.xml.dist. Requires `composer install` (dev dependencies).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$root     = dirname( __DIR__ );
$baseline = $root . '/phpcs-baseline.json';
$update   = in_array( '--update', $argv, true );
$phpcs    = $root . '/vendor/bin/phpcs';

if ( ! is_file( $phpcs ) ) {
	fwrite( STDERR, "vendor/bin/phpcs not found – run composer install first.\n" );
	exit( 2 );
}

$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $phpcs ) . ' -q --no-colors --report=json --standard=' . escapeshellarg( $root . '/phpcs.xml.dist' );
$out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && ' . $cmd );
$report = json_decode( (string) $out, true );
if ( ! is_array( $report ) || ! isset( $report['files'] ) ) {
	fwrite( STDERR, "Could not parse the phpcs report:\n" . substr( (string) $out, 0, 4000 ) . "\n" );
	exit( 2 );
}

$current  = array();
$messages = array();
foreach ( $report['files'] as $file => $data ) {
	$rel = ltrim( str_replace( '\\', '/', str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : $file ), '/' );
	foreach ( $data['messages'] as $m ) {
		$current[ $rel ][ $m['source'] ] = ( $current[ $rel ][ $m['source'] ] ?? 0 ) + 1;
		$messages[ $rel ][ $m['source'] ][] = sprintf( '%s:%d:%d %s %s', $rel, $m['line'], $m['column'], $m['type'], $m['message'] );
	}
}
ksort( $current );
foreach ( $current as &$sniffs ) { ksort( $sniffs ); }
unset( $sniffs );

$total = array_sum( array_map( 'array_sum', $current ) );

if ( $update ) {
	file_put_contents( $baseline, json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	printf( "phpcs-baseline.json written: %d findings in %d files.\n", $total, count( $current ) );
	exit( 0 );
}

$known = is_file( $baseline ) ? json_decode( (string) file_get_contents( $baseline ), true ) : array();
if ( ! is_array( $known ) ) {
	fwrite( STDERR, "phpcs-baseline.json is not valid JSON.\n" );
	exit( 2 );
}

$new   = 0;
$fixed = 0;
foreach ( $current as $file => $sniffs ) {
	foreach ( $sniffs as $source => $count ) {
		$allowed = (int) ( $known[ $file ][ $source ] ?? 0 );
		if ( $count > $allowed ) {
			$new += $count - $allowed;
			printf( "NEW  %s  %s: %d finding(s), baseline allows %d\n", $file, $source, $count, $allowed );
			foreach ( $messages[ $file ][ $source ] as $line ) { echo '     ' . $line . "\n"; }
		}
	}
}
foreach ( $known as $file => $sniffs ) {
	foreach ( $sniffs as $source => $count ) {
		$fixed += max( 0, (int) $count - (int) ( $current[ $file ][ $source ] ?? 0 ) );
	}
}

printf( "phpcs: %d finding(s) in total, %d in baseline, %d new, %d fixed.\n", $total, array_sum( array_map( 'array_sum', $known ) ), $new, $fixed );
if ( $fixed > 0 && 0 === $new ) {
	echo "Findings were fixed – tighten the baseline with: php build/phpcs-baseline.php --update\n";
}
if ( $new > 0 ) {
	echo "New coding standard violations. Fix them (vendor/bin/phpcs <file>; vendor/bin/phpcbf for layout) or, only if justified,\n"
		. "add a targeted // phpcs:ignore Sniff.Code -- reason. Do not grow the baseline for new code.\n";
	exit( 1 );
}
exit( 0 );
