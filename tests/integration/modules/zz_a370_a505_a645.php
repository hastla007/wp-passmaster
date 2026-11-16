<?php
/**
 * A370, A505: every answer of the plugin namespace is no-store unless the route sets its own
 * Cache-Control. A645: order_status takes the access token from the header X-WPCAP-Access-Token or a
 * POST body, marks a token in the query string as deprecated and survives a NULL token hash.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;
echo "A370/A505/A645: no-store and order access token\n";

$x1_hdr = static function ( $res ) { return array_change_key_case( $res instanceof WP_REST_Response ? (array) $res->get_headers() : array(), CASE_LOWER ); };
$x1_live = new WP_REST_Response( array( 'available' => 3 ) );
Controller::cache_headers( $x1_live, null, new WP_REST_Request( 'GET', '/pass-captain/v1/events' ) );
check( 'A505: a public live answer of the namespace gets Cache-Control no-store', false !== strpos( (string) ( $x1_hdr( $x1_live )['cache-control'] ?? '' ), 'no-store' ) );
$x1_own = new WP_REST_Response( array() );
$x1_own->header( 'Cache-Control', 'public, max-age=60' );
Controller::cache_headers( $x1_own, null, new WP_REST_Request( 'GET', '/pass-captain/v1/x' ) );
check( 'A505: a route with its own Cache-Control keeps it', 'public, max-age=60' === $x1_hdr( $x1_own )['cache-control'] );
$x1_foreign = new WP_REST_Response( array() );
Controller::cache_headers( $x1_foreign, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
check( 'A505: other namespaces are untouched', ! isset( $x1_hdr( $x1_foreign )['cache-control'] ) );
$x1_plugin = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'A505: the filter is registered for rest_post_dispatch', false !== strpos( $x1_plugin, "add_filter( 'rest_post_dispatch', array( Rest\\Controller::class, 'cache_headers' )" ) );

$x1_type = make_type( 5, 5, 0 );
$x1_order = Orders::create( array( 'email' => 'x1-a645@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a645-' . uniqid() ) );
$x1_public = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=%d", $x1_order['order_id'] ) );
$x1_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $x1_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $x1_order['order_id'] ) );
$x1_status = static function ( $method, $how, $token ) use ( $x1_public ) {
	$req = new WP_REST_Request( $method, '/pass-captain/v1/orders/' . $x1_public );
	$req['public_id'] = $x1_public;
	if ( 'header' === $how ) { $req->set_header( 'X-WPCAP-Access-Token', $token ); }
	if ( 'body' === $how ) { $req->set_body( json_encode( array( 'access_token' => $token ) ) ); }
	if ( 'query' === $how ) { $req->set_query_params( array( 'access_token' => $token ) ); }
	return Controller::order_status( $req );
};
$x1_by_header = $x1_status( 'GET', 'header', $x1_token );
$x1_by_body = $x1_status( 'POST', 'body', $x1_token );
$x1_by_query = $x1_status( 'GET', 'query', $x1_token );
$x1_wrong = $x1_status( 'GET', 'header', str_repeat( 'a', 64 ) );
check( 'A645: the token is accepted from the header X-WPCAP-Access-Token, without deprecation', $x1_by_header instanceof WP_REST_Response && $x1_public === $x1_by_header->get_data()['public_id'] && ! isset( $x1_hdr( $x1_by_header )['deprecation'] ) );
check( 'A645: the token is accepted from a POST body', $x1_by_body instanceof WP_REST_Response && $x1_public === $x1_by_body->get_data()['public_id'] );
check( 'A645: a token in the query string still works but is marked deprecated', $x1_by_query instanceof WP_REST_Response && 'true' === ( $x1_hdr( $x1_by_query )['deprecation'] ?? '' ) );
check( 'A645: a wrong header token is refused (403)', is_wp_error( $x1_wrong ) && 403 === $x1_wrong->get_error_data()['status'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET access_token_hash='' WHERE order_id=%d", $x1_order['order_id'] ) );
$x1_null = null;
try { $x1_null = $x1_status( 'GET', 'header', $x1_token ); } catch ( \Throwable $e ) { $x1_null = $e; }
$x1_ctrl = (string) file_get_contents( WPCAP_PATH . 'src/Rest/Controller.php' );
check( 'A645: an order without token hash answers 403; the hash is cast before hash_equals (no TypeError for NULL)', is_wp_error( $x1_null ) && 403 === $x1_null->get_error_data()['status'] && false !== strpos( $x1_ctrl, "hash_equals( (string) \$order['access_token_hash']" ) );
check( 'A645: the order route accepts GET and POST', false !== strpos( $x1_ctrl, "array( 'methods' => \\WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'order_status' )" ) && false !== strpos( $x1_ctrl, "array( 'methods' => \\WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'order_status' )" ) );
