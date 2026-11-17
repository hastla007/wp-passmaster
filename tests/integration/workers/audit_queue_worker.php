<?php
/**
 * Child process for the parallel queue worker test (tests/integration/modules/zzz_audit_db_robustness.php).
 * Runs one job worker and one message worker pass against the shared test database and prints
 * every job it handled ("J:<id>") and every mail it sent ("M:<recipient>").
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__ ) . '/bootstrap.php';

add_filter(
	'wp_pass_captain_process_job_v1',
	static function ( $handled, $type, $payload, $job_id ) {
		if ( 'audit_parallel' !== $type ) { return $handled; }
		usleep( 5000 );
		echo 'J:' . (int) $job_id . "\n";
		return true;
	},
	10,
	4
);
// A652: the stored payload is data, never a template – the test renderer supplies subject and body.
add_filter(
	'wpcap_email_template',
	static function ( $template, $key, $payload ) {
		return 'audit_parallel' === $key ? array( 'subject' => (string) ( $payload['subject'] ?? '' ), 'body' => (string) ( $payload['body'] ?? '' ) ) : $template;
	},
	10,
	3
);
PassCaptain\Jobs::process_due( 200 );
PassCaptain\Communications::process_due();
foreach ( $GLOBALS['wpcap_test_mails'] as $mail ) {
	if ( 0 === strpos( (string) $mail['to'], 'audit-par-' ) ) { echo 'M:' . $mail['to'] . "\n"; }
}
