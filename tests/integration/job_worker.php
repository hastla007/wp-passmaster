<?php
/**
 * Child process for tests/integration/modules/zz_a188.php: one background worker that runs
 * Jobs::process_due() against the test database (same WPCAP_TEST_* environment as run.php).
 *
 * Usage: php tests/integration/job_worker.php <race|die> [limit]
 *   race  process due jobs; job type a188_race records each run (audit action a188_ran) and takes 20 ms
 *   die   like race, but the worker exits right after a handler's side effect, before the job is
 *         marked complete (shutdown functions still run, as after a fatal error)
 *
 * Prints the number of processed jobs.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

$mode = $argv[1] ?? 'race';
add_filter( 'wpcap_process_job', array( PassCaptain\Orders::class, 'handle_job' ), 10, 4 );
add_filter( 'wpcap_process_job', static function ( $handled, $type, $payload ) {
	if ( 'a188_race' !== $type ) { return $handled; }
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'wpcap_audit_logs', array( 'user_id' => 0, 'action' => 'a188_ran', 'object_type' => 'job', 'object_id' => (int) $payload['n'], 'details' => (string) getmypid(), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	usleep( 20000 );
	return true;
}, 10, 3 );
if ( 'die' === $mode ) {
	add_filter( 'query', static function ( $sql ) {
		if ( preg_match( "/^UPDATE \\S+wpcap_jobs SET status='complete'/", (string) $sql ) ) { exit( 3 ); }
		return $sql;
	} );
}
echo PassCaptain\Jobs::process_due( (int) ( $argv[2] ?? 50 ) );
