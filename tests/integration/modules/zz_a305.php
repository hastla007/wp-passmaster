<?php
// phpcs:ignoreFile
/**
 * A305: WordPress "Plugin Check" runs in CI against the built ZIP (wp-matrix.yml job plugin-check) and
 * fails on errors that are not in tests/plugin-check/baseline.json. Here: the job definition and the
 * gate script (compare.php) on fixture output.
 */

echo "A305: Plugin Check in CI with a baseline\n";
$a305_root = dirname( __DIR__, 3 );
$a305_wf = (string) file_get_contents( $a305_root . '/.github/workflows/wp-matrix.yml' );
$a305_job = substr( $a305_wf, (int) strpos( $a305_wf, "\n  plugin-check:" ) );
$a305_job = substr( $a305_job, 0, (int) strpos( $a305_job, "\n  woocommerce:" ) ?: strlen( $a305_job ) );
check( 'A305 wp-matrix.yml has a plugin-check job', false !== strpos( $a305_wf, "\n  plugin-check:" ) );
check( 'A305 the job checks the built ZIP in a throw-away WordPress with a pinned Plugin Check', false !== strpos( $a305_job, 'bash build/release.sh' ) && false !== strpos( $a305_job, 'wp core install' ) && false !== strpos( $a305_job, 'plugin install plugin-check --version="$PCP_VERSION"' ) && false !== strpos( $a305_job, 'build/dist/pass-captain.zip' ) && 1 === preg_match( "/PCP_VERSION: '\\d+\\.\\d+\\.\\d+'/", $a305_job ) );
check( 'A305 the job runs the plugin_repo checks and gates them with compare.php', false !== strpos( $a305_job, 'plugin check pass-captain --format=json --categories=plugin_repo' ) && false !== strpos( $a305_job, 'tests/plugin-check/compare.php' ) && false === strpos( $a305_job, 'continue-on-error' ) );
$a305_base = json_decode( (string) file_get_contents( $a305_root . '/tests/plugin-check/baseline.json' ), true );
check( 'A305 the baseline lists known errors per file|code', is_array( $a305_base['errors'] ?? null ) && $a305_base['errors'] && ! array_filter( array_keys( $a305_base['errors'] ), static fn( $k ) => 1 !== substr_count( $k, '|' ) ) );

$a305_dir = sys_get_temp_dir() . '/wpcap-a305-' . getmypid();
@mkdir( $a305_dir );
file_put_contents( "$a305_dir/base.json", json_encode( array( 'errors' => array( 'src/A.php|X.Code' => 2 ) ) ) );
$a305_run = static function ( $output ) use ( $a305_dir, $a305_root ) {
	file_put_contents( "$a305_dir/out.txt", $output );
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $a305_root . '/tests/plugin-check/compare.php' ) . ' ' . escapeshellarg( "$a305_dir/out.txt" ) . ' ' . escapeshellarg( "$a305_dir/base.json" ) . ' 2>&1', $lines, $rc );
	return array( $rc, implode( "\n", $lines ) );
};
$a305_err = static fn( $code, $type = 'ERROR' ) => array( 'line' => 1, 'column' => 1, 'type' => $type, 'code' => $code, 'message' => 'm' );
list( $a305_rc ) = $a305_run( "FILE: src/A.php\n" . json_encode( array( $a305_err( 'X.Code' ), $a305_err( 'X.Code' ), $a305_err( 'W.Code', 'WARNING' ) ) ) . "\n" );
check( 'A305 known errors and warnings pass', 0 === $a305_rc );
list( $a305_rc, $a305_msg ) = $a305_run( "FILE: src/A.php\n" . json_encode( array( $a305_err( 'X.Code' ), $a305_err( 'X.Code' ), $a305_err( 'X.Code' ) ) ) . "\n" );
check( 'A305 one more error of a known code fails', 1 === $a305_rc && false !== strpos( $a305_msg, '+1  src/A.php|X.Code' ) );
list( $a305_rc ) = $a305_run( "FILE: src/B.php\n" . json_encode( array( $a305_err( 'X.Code' ) ) ) . "\n" );
check( 'A305 an error in a new file fails', 1 === $a305_rc );
list( $a305_rc, $a305_msg ) = $a305_run( "FILE: src/A.php\n" . json_encode( array( $a305_err( 'X.Code' ) ) ) . "\n" );
check( 'A305 fewer errors pass and ask to lower the baseline', 0 === $a305_rc && false !== strpos( $a305_msg, 'lower tests/plugin-check/baseline.json' ) );
array_map( 'unlink', glob( "$a305_dir/*" ) );
@rmdir( $a305_dir );
