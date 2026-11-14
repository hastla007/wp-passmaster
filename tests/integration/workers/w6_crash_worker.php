<?php
/**
 * Child process for the worker restart tests (tests/integration/modules/zzzz_w6_workers.php).
 *
 * Usage: php w6_crash_worker.php job|message|webhook
 * Runs one worker pass; the item of the "w6_crash" kind kills the process in the middle of its
 * handler (exit inside the handler, like a fatal error or the PHP time limit). Shutdown functions
 * still run, so the claim must be handed back by Jobs\TimeBudget::release_claims().
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__ ) . '/bootstrap.php';

$mode = $argv[1] ?? 'job';

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args = array() ) {
		if ( false !== strpos( (string) $url, 'crash' ) ) { echo "CRASH\n"; exit( 3 ); }
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => '' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; } }

add_filter(
	'wp_pass_captain_process_job_v1',
	static function ( $handled, $type, $payload, $job_id ) {
		if ( 'w6_crash' === $type ) { echo "CRASH\n"; exit( 3 ); }
		return $handled;
	},
	10,
	4
);
add_filter(
	'wp_pass_captain_email_template_v1',
	static function ( $template, $key, $payload ) {
		if ( 'w6_crash' === $key ) { echo "CRASH\n"; exit( 3 ); }
		return $template;
	},
	10,
	3
);

if ( 'job' === $mode ) { PassCaptain\Jobs::process_due( 50 ); }
if ( 'message' === $mode ) { PassCaptain\Communications::process_due(); }
if ( 'webhook' === $mode ) { PassCaptain\Webhooks\Dispatcher::process( 50 ); }
echo "DONE\n";
