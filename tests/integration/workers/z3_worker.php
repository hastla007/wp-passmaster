<?php
/**
 * Child process for the Z3 tests (tests/integration/modules/zzzzzzz_z3_perf.php).
 *
 * Usage: php z3_worker.php <mode> <args...>
 *   hit <start_at_unix_float> <key> <limit> <hits>  RateLimit::hit() <hits> times after a common start
 *                                                    time (own connection); prints the number allowed.
 *   checkout <start_at_unix_float> <ticket_type_id> <ip_limit>
 *                                                    One Orders::create() from 198.51.100.77 (checkout
 *                                                    limit per IP = <ip_limit>) after the common start
 *                                                    time; prints OK or the error code.
 *   boot                                             Plugin::run() + settings defaults of a frontend
 *                                                    request; prints JSON with the loaded files.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__ ) . '/bootstrap.php';

$mode = (string) ( $argv[1] ?? '' );
if ( 'hit' === $mode || 'checkout' === $mode ) {
	$start = (float) $argv[2];
	while ( microtime( true ) < $start ) { usleep( 500 ); }
}

switch ( $mode ) {
	case 'hit':
		$ok = 0;
		for ( $i = 0; $i < (int) $argv[5]; $i++ ) { $ok += PassCaptain\RateLimit::hit( (string) $argv[3], (int) $argv[4], 3600 ) ? 1 : 0; }
		echo $ok;
		break;
	case 'checkout':
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = (int) $argv[4];
		$r = PassCaptain\Orders::create( array( 'email' => 'z3-par-' . getmypid() . '@example.com', 'items' => array( array( 'ticket_type_id' => (int) $argv[3], 'quantity' => 1 ) ), 'idempotency_key' => 'z3-par-' . getmypid() . uniqid() ) );
		echo is_wp_error( $r ) ? $r->get_error_code() : 'OK';
		break;
	case 'boot':
		// A frontend request (is_admin() false): only what Plugin::run() and the settings defaults load.
		foreach ( array( 'is_multisite' => false, 'add_shortcode' => null, 'register_block_type' => null, 'is_admin' => false, 'wp_doing_ajax' => false, 'wp_doing_cron' => false, 'did_action' => 0, 'register_rest_route' => null, 'wp_next_scheduled' => 1, 'wp_schedule_event' => null, 'wp_schedule_single_event' => null ) as $fn => $ret ) {
			if ( ! function_exists( $fn ) ) { eval( 'function ' . $fn . '( ...$a ) { return ' . var_export( $ret, true ) . '; }' ); }
		}
		$base = get_included_files();
		PassCaptain\Plugin::instance()->run();
		PassCaptain\Settings::get( 'currency' );
		PassCaptain\Installer::fingerprint();
		$files = array_values( array_map( static fn( $f ) => str_replace( WPCAP_PATH, '', $f ), array_diff( get_included_files(), $base ) ) );
		echo wp_json_encode( array( 'files' => $files, 'admin_post' => isset( $GLOBALS['wpcap_test_hooks']['admin_post_wpcap_cancel_order'] ) ) );
		break;
}
