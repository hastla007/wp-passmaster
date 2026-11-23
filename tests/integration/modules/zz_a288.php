<?php
/**
 * Worklist item A288: the remaining transient counters (manual payment holds per client, developer
 * key per-minute limit, service booking tokens, partner API lockout and log flags, affiliate partner
 * codes) count atomically through RateLimit. Included from run.php after zz_a278.php (q1_issue_key()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\RateLimit;

$q1r_saved_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$q1r_saved_backend = $GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'];
$GLOBALS['wpcap_test_transients'] = array();
// Database backend: atomic; any leftover transient counter would show up in wpcap_test_transients.
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';

echo "A288: manual payment holds per client\n";
$q1r_manual = new ReflectionMethod( PassCaptain\Rest\Controller::class, 'manual_hold_limit' );
$q1r_manual->setAccessible( true );
$GLOBALS['wpcap_test_filters']['wpcap_manual_orders_per_client'] = 2;
$_SERVER['REMOTE_ADDR'] = '2001:db8:288:1::' . dechex( random_int( 1, 65535 ) );
$q1r_a = $q1r_manual->invoke( null, 'not-an-email', 60 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:288:1::' . dechex( random_int( 1, 65535 ) ) . ':1';
$q1r_b = $q1r_manual->invoke( null, 'not-an-email', 60 );
$q1r_c = $q1r_manual->invoke( null, 'not-an-email', 60 );
check( 'A288 manual hold limit counts in RateLimit (db), per /64, third order refused', true === $q1r_a && true === $q1r_b && is_wp_error( $q1r_c ) && 'wpcap_rate_limited' === $q1r_c->get_error_code() && 'db' === RateLimit::$last_backend );
unset( $GLOBALS['wpcap_test_filters']['wpcap_manual_orders_per_client'] );

echo "A288: developer key per-minute limit\n";
$q1r_ev = q1_event();
$q1r_owner = q1_user( 28801, array( 'administrator' ) );
$q1r_key = q1_issue_key( 28801, $q1r_ev );
$GLOBALS['wpcap_test_filters']['wpcap_developer_key_rate_limit'] = 2;
$q1r_hits = array();
for ( $i = 0; $i < 3; $i++ ) { $q1r_hits[] = PassCaptain\DeveloperKeys::authorize( q1_key_request( $q1r_key['key'], $q1r_ev ), 'events.read', $q1r_ev ); }
check( 'A288 developer key limit counts in RateLimit (no transient per key and minute)', 28801 === $q1r_hits[0] && 28801 === $q1r_hits[1] && false === $q1r_hits[2]
	&& is_wp_error( PassCaptain\DeveloperKeys::permission( q1_key_request( $q1r_key['key'], $q1r_ev ), 'events.read', $q1r_ev ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_developer_key_rate_limit'] );

echo "A288: affiliate partner codes\n";
$q1r_code = new ReflectionMethod( PassCaptain\Integrations\AffiliateCaptain\Adapter::class, 'check_code' );
$q1r_code->setAccessible( true );
$_SERVER['REMOTE_ADDR'] = '198.51.100.188';
$q1r_errors = array();
for ( $i = 0; $i < 11; $i++ ) {
	try { $q1r_code->invoke( null, 'NOPE' . $i ); $q1r_errors[] = 'ok'; } catch ( RuntimeException $e ) { $q1r_errors[] = $e->getMessage(); }
}
check( 'A288 10 wrong partner codes per client, then partner_code_rate_limited', 10 === count( array_keys( $q1r_errors, 'partner_code_invalid', true ) ) && 'partner_code_rate_limited' === end( $q1r_errors ) );

echo "A288: no transient counters left\n";
check( 'A288 no transient was written by these counters', ! array_filter( array_keys( $GLOBALS['wpcap_test_transients'] ), static fn( $k ) => preg_match( '/^wpcap_(rl_|manual_client_|api_rate_|partner_fail_|svc_token_|partner_lock_|partner_w?rl_logged_)/', $k ) ) );
$q1r_src = '';
foreach ( array( 'src/Rest/Controller.php', 'src/DeveloperKeys.php', 'src/ServiceBookingFlow.php', 'src/Partners/Keys.php', 'src/Integrations/AffiliateCaptain/Adapter.php' ) as $q1r_file ) { $q1r_src .= file_get_contents( WPCAP_PATH . $q1r_file ); }
check( 'A288 the five files no longer use get_transient()/set_transient() for counters or flags', 0 === preg_match( '/\b(get|set)_transient\s*\(/', $q1r_src ) );

$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = $q1r_saved_backend;
if ( null === $q1r_saved_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $q1r_saved_remote; }
$GLOBALS['wpcap_test_transients'] = array();
