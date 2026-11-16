<?php
/**
 * A282: rendering the public event list never calls the Mollie API. The method selector reads the
 * cached method list only; a missing list is loaded in the background (wpcap_mollie_refresh_methods)
 * with a short timeout, and a failed lookup is cached for a few minutes so an outage does not slow
 * every checkout. Included from run.php (uses the HTTP fakes of payments.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Mollie as A282Mollie;
use PassCaptain\Mollie\MollieGateway as A282Gateway;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

echo "A282: Mollie methods never loaded while rendering\n";
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ?? false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] = (int) $ts; return true; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }

$a282_settings = get_option( 'wpcap_settings' );
$a282_key      = PassCaptain\Secrets::get( 'mollie_api_key' );
Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Zz9', 10 ), 'enabled_providers' => array( 'manual', 'mollie' ), 'mollie' => array( 'methods' => array( 'ideal', 'creditcard' ), 'billing_country' => '' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_cron'] = array();
$GLOBALS['p1p_scheduled'] = array();
http_reset();
$a282_type  = make_type( 10, 10, 3300 );
$a282_types = $wpdb->get_results( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a282_type" );
ob_start(); A282Mollie::render_checkout_fields( (object) array( 'occurrence_id' => 282 ), $a282_types ); $a282_html = (string) ob_get_clean();
check( 'A282 rendering an event card with an uncached amount makes no Mollie request', ! http_requests( '/v2/methods' ) );
check( 'A282 without a cached list the selector is left out (method chosen on the Mollie page)', '' === $a282_html );
check( 'A282 the method list is scheduled for background loading', false !== wp_next_scheduled( A282Gateway::REFRESH_HOOK, array( '33.00', 'EUR' ) ) );
check( 'A282 the refresh hook is registered and unscheduled on deactivation', has_action( A282Gateway::REFRESH_HOOK ) && in_array( A282Gateway::REFRESH_HOOK, PassCaptain\Plugin::cron_hooks(), true ) );

http_push( array( '_embedded' => array( 'methods' => array( array( 'id' => 'ideal', 'description' => 'iDEAL' ), array( 'id' => 'creditcard', 'description' => 'Kreditkarte' ) ) ) ), 200, '/v2/methods' );
A282Gateway::refresh_methods( '33.00', 'EUR' );
$a282_req = http_requests( '/v2/methods' )[0] ?? array( 'timeout' => 0 );
check( 'A282 the background job loads the list with a short timeout', 1 === count( http_requests( '/v2/methods' ) ) && A282Gateway::METHODS_TIMEOUT === (int) $a282_req['timeout'] );
http_reset();
ob_start(); A282Mollie::render_checkout_fields( (object) array( 'occurrence_id' => 282 ), $a282_types ); $a282_html = (string) ob_get_clean();
check( 'A282 afterwards the selector renders from the cache, still without a request', false !== strpos( $a282_html, 'value="ideal"' ) && ! http_requests( '/v2/methods' ) );

// Outage: the failed lookup is remembered, the next lookups do not wait for Mollie again.
http_reset();
http_push( array( 'detail' => 'Service unavailable' ), 503, '/v2/methods' );
$a282_err = A282Gateway::available_methods( '44.00', 'EUR' );
$a282_again = A282Gateway::available_methods( '44.00', 'EUR' );
check( 'A282 a failed lookup is cached briefly (no second request during an outage)', is_wp_error( $a282_err ) && is_wp_error( $a282_again ) && 1 === count( http_requests( '/v2/methods' ) ) );
check( 'A282 the negative cache expires after a few minutes', A282Gateway::METHODS_ERROR_TTL > 0 && A282Gateway::METHODS_ERROR_TTL <= 15 * MINUTE_IN_SECONDS );
check( 'A282 the payment path still loads synchronously on a cache miss', ( static function () { http_reset(); http_push( array( '_embedded' => array( 'methods' => array() ) ), 200, '/v2/methods' ); A282Gateway::available_methods( '55.00', 'EUR' ); return 1 === count( http_requests( '/v2/methods' ) ); } )() );

update_option( 'wpcap_settings', $a282_settings );
Settings::flush();
Settings::update( array( 'mollie_api_key' => $a282_key ) );
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
