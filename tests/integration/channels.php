<?php
/**
 * Entry points of the plugin for the access coverage tests (A119 REST, A145 REST + admin-post +
 * AJAX): registrars, the enumerated REST endpoints and the intentionally public ones.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; }
}
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }

/** Registrar of every source file that calls register_rest_route(). */
function wpcap_test_rest_registrars() {
	return array(
		'src/Rest/Controller.php' => array( PassCaptain\Rest\Controller::class, 'register_routes' ),
		'src/Modules/Sms.php' => array( PassCaptain\Modules\Sms::class, 'register_routes' ),
		'src/Mobile/Api.php' => array( PassCaptain\Mobile\Api::class, 'routes' ),
		'src/WalletPasses/WebService.php' => array( PassCaptain\WalletPasses\WebService::class, 'routes' ),
		'src/Partners/Api.php' => array( PassCaptain\Partners\Api::class, 'register_routes' ),
		'src/OfflineScanner/Rest.php' => array( PassCaptain\OfflineScanner\Rest::class, 'routes' ),
		'src/GuestLists/Rest.php' => array( PassCaptain\GuestLists\Rest::class, 'routes' ),
		'src/Seating/Rest.php' => array( PassCaptain\Seating\Rest::class, 'register_routes' ),
		'src/Exchange/Portal.php' => array( PassCaptain\Exchange\Portal::class, 'register_routes' ),
		'src/Admission/Rest.php' => array( PassCaptain\Admission\Rest::class, 'routes' ),
		'src/PortalSelfService.php' => array( PassCaptain\PortalSelfService::class, 'register_routes' ),
	);
}

/** Source files (relative to the plugin root) whose code contains $needle. */
function wpcap_test_source_files_with( $needle ) {
	$root = dirname( __DIR__, 2 );
	$files = array();
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() && false !== strpos( (string) file_get_contents( $file->getPathname() ), $needle ) ) {
			$files[] = ltrim( str_replace( $root, '', $file->getPathname() ), '/' );
		}
	}
	sort( $files );
	return $files;
}

/**
 * Every registered REST endpoint.
 *
 * @return array<string, array> "METHOD route" => ['route' => string, 'method' => string, 'args' => endpoint args]
 */
function wpcap_test_rest_endpoints() {
	$saved = $GLOBALS['wpcap_test_routes'] ?? array();
	$GLOBALS['wpcap_test_routes'] = array();
	foreach ( wpcap_test_rest_registrars() as $registrar ) { call_user_func( $registrar ); }
	$routes = $GLOBALS['wpcap_test_routes'];
	$GLOBALS['wpcap_test_routes'] = $saved;
	$out = array();
	foreach ( $routes as $route => $args ) {
		$list = isset( $args['methods'] ) || isset( $args['callback'] ) ? array( $args ) : array_values( array_filter( $args, 'is_array' ) );
		foreach ( $list as $ep ) {
			$method = strtoupper( (string) ( $ep['methods'] ?? 'GET' ) );
			$out[ $method . ' ' . $route ] = array( 'route' => $route, 'method' => $method, 'args' => $ep );
		}
	}
	return $out;
}

/**
 * Intentionally public REST routes ("METHOD route"): access token / signature / pairing code checked
 * in the callback, or only public data. docs/SECURITY.md lists them. The Apple Wallet web service
 * (routes containing "wallet") authenticates with the pass's authentication token in the callback.
 */
function wpcap_test_public_rest() {
	return array(
		'GET pass-captain/v1/events',
		'POST pass-captain/v1/checkout',
		'GET pass-captain/v1/checkout/options',
		'GET pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/transfer',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/resend',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/assign',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/billing',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/payout-account',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/exchange',
		'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/refund-request',
		'GET pass-captain/v1/events/(?P<event_id>\d+)/seats',
		'POST pass-captain/v1/events/(?P<event_id>\d+)/seat-holds',
		'GET pass-captain/v1/events/(?P<event_id>\d+)/service-slots',
		'POST pass-captain/v1/service-slots/(?P<slot_id>\d+)/bookings',
		'POST pass-captain/v1/campaigns/preview',
		'POST pass-captain/v1/payments/(?P<provider>[a-z0-9_-]+)/webhook',
		'POST pass-captain/v1/events/(?P<event_id>\d+)/waitlist',
		'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/accept',
		'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/decline',
		'GET pass-captain/v1/events/(?P<event_id>\d+)/translations',
		'POST pass-captain/v1/sms/twilio/status',
		'POST pass-captain/v1/sms/twilio/inbound',
		// A141: generic HTTP gateway callbacks, HMAC-SHA256 body signature checked in the callback.
		'POST pass-captain/v1/sms/http/status',
		'POST pass-captain/v1/sms/http/inbound',
		'POST pass-captain/v1/app/pair',
		'GET pass-captain/v1/seating/occurrences/(?P<occurrence_id>\d+)',
	);
}

/** Whether a REST endpoint is intentionally public (see wpcap_test_public_rest()). */
function wpcap_test_rest_is_public( $key, array $endpoint ) {
	if ( '__return_true' !== ( $endpoint['args']['permission_callback'] ?? null ) ) { return false; }
	return false !== strpos( $endpoint['route'], 'wallet' ) || in_array( $key, wpcap_test_public_rest(), true );
}
