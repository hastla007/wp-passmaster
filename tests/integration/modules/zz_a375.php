<?php
/**
 * A375: Site Health tests for anonymous REST access and for a proxy without trusted proxies.
 */

use PassCaptain\Operations\SiteHealth;
use PassCaptain\Settings;

if ( ! function_exists( "esc_url" ) ) { function esc_url( $u ) { return (string) $u; } }
echo "A375: site health REST/proxy\n";
$a375_resolve = static function ( $ips, $host ) { return 'example.test' === $host ? array( '93.184.216.34' ) : $ips; };
add_filter( 'wpcap_outbound_resolve', $a375_resolve, 10, 2 );
check( 'A375: both tests are registered', isset( SiteHealth::test_callbacks()['rest_anonymous'], SiteHealth::test_callbacks()['proxy'] ) );

$a375_probe = static function ( $code ) {
	delete_transient( SiteHealth::REST_PROBE_TRANSIENT );
	$GLOBALS['wpcap_test_http_requests'] = array();
	$GLOBALS['wpcap_test_http_queue'] = $code instanceof WP_Error ? array( array( 'error' => $code, 'match' => 'pass-captain/v1/events' ) ) : array( array( 'code' => $code, 'body' => '[]', 'match' => 'pass-captain/v1/events' ) );
	return SiteHealth::test_rest_anonymous();
};
$a375_ok = $a375_probe( 200 );
$a375_req = $GLOBALS['wpcap_test_http_requests'][0] ?? array();
check( 'A375: probe is a cookieless GET of the public events endpoint', 'GET' === ( $a375_req['method'] ?? '' ) && str_contains( (string) ( $a375_req['url'] ?? '' ), 'pass-captain/v1/events' ) && array() === ( $a375_req['args']['cookies'] ?? null ) && 'good' === $a375_ok['status'] );
$GLOBALS['wpcap_test_http_requests'] = array();
SiteHealth::test_rest_anonymous();
check( 'A375: the probe result is cached', array() === $GLOBALS['wpcap_test_http_requests'] );
$a375_blocked = $a375_probe( 403 );
check( 'A375: 403 for guests is critical and names the namespace', 'critical' === $a375_blocked['status'] && str_contains( $a375_blocked['description'], 'pass-captain/v1' ) && str_contains( $a375_blocked['description'], '403' ) );
check( 'A375: 401 is critical as well', 'critical' === $a375_probe( 401 )['status'] );
$a375_err = $a375_probe( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
check( 'A375: loopback failure is only a recommendation', 'recommended' === $a375_err['status'] && str_contains( $a375_err['description'], 'nicht erreichbar' ) );
remove_filter( 'wpcap_outbound_resolve', $a375_resolve, 10 );
delete_transient( SiteHealth::REST_PROBE_TRANSIENT );
$GLOBALS['wpcap_test_http_queue'] = array();

$a375_server = $_SERVER;
$a375_proxies = Settings::get( 'trusted_proxies' );
Settings::update( array( 'trusted_proxies' => '' ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_TRUE_CLIENT_IP'], $_SERVER['HTTP_FORWARDED'] );
$a375_seen = get_option( PassCaptain\Sales\CheckoutThrottle::SEEN_OPTION );
delete_option( PassCaptain\Sales\CheckoutThrottle::SEEN_OPTION );
check( 'A375: no proxy hint → good', 'good' === SiteHealth::test_proxy()['status'] );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
$a375_proxy = SiteHealth::test_proxy();
check( 'A375: forwarded header without trusted proxies → recommendation with link to the advanced tab', 'recommended' === $a375_proxy['status'] && str_contains( $a375_proxy['actions'], 'tab=advanced' ) && str_contains( $a375_proxy['description'], 'Einstellungen → Erweitert' ) );
Settings::update( array( 'trusted_proxies' => '203.0.113.0/24' ) );
check( 'A375: with trusted proxies the test is good', 'good' === SiteHealth::test_proxy()['status'] );
Settings::update( array( 'trusted_proxies' => $a375_proxies ) );
$_SERVER = $a375_server;
if ( false !== $a375_seen ) { update_option( PassCaptain\Sales\CheckoutThrottle::SEEN_OPTION, $a375_seen, false ); }
