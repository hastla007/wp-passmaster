<?php
/**
 * A420: a checkout idempotency key belongs to one cart (another cart under the same key: 409).
 * A421: public write routes declare typed args (whole quantities/ids ≥ 1); the service booking
 * refuses negative/fractional quantities instead of turning them around.
 * A534: "false", "0", "no" are no consent (accept_terms, sms_consent).
 */
// phpcs:ignoreFile
use PassCaptain\Http;
use PassCaptain\Orders;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

require_once dirname( __DIR__ ) . '/channels.php';

global $wpdb;
$p = $wpdb->prefix;
echo "A420/A421/A534: checkout input\n";
$GLOBALS['wpcap_test_transients'] = array();

// A420.
$x1_type = make_type( 20, 10, 1500 );
$x1_type2 = make_type( 20, 10, 1500 );
$x1_key = 'x1-a420-' . bin2hex( random_bytes( 8 ) );
$x1_a = Orders::create( array( 'email' => 'x1-a420@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 2 ) ), 'idempotency_key' => $x1_key ) );
$x1_same = Orders::create( array( 'email' => 'X1-A420@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ), array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'idempotency_key' => $x1_key ) );
$x1_more = Orders::create( array( 'email' => 'x1-a420@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 3 ) ), 'idempotency_key' => $x1_key ) );
$x1_other = Orders::create( array( 'email' => 'x1-a420@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type2, 'quantity' => 2 ) ), 'idempotency_key' => $x1_key ) );
$x1_mail = Orders::create( array( 'email' => 'someone-else@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 2 ) ), 'idempotency_key' => $x1_key ) );
check( 'A420: the first checkout stores a request fingerprint', ! is_wp_error( $x1_a ) && 64 === strlen( (string) $wpdb->get_var( $wpdb->prepare( "SELECT request_hash FROM {$p}wpcap_orders WHERE order_id=%d", $x1_a['order_id'] ) ) ) );
check( 'A420: a retry with the same cart (lines summed, e-mail case) reuses the order', ! is_wp_error( $x1_same ) && ! empty( $x1_same['reused'] ) && $x1_same['order_id'] === $x1_a['order_id'] );
check( 'A420: the same key with another quantity, ticket type or e-mail gets 409', is_wp_error( $x1_more ) && 'wpcap_idempotency_conflict' === $x1_more->get_error_code() && 409 === $x1_more->get_error_data()['status'] && is_wp_error( $x1_other ) && is_wp_error( $x1_mail ) );
check( 'A420: nothing was booked for the refused attempts', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(quantity) FROM {$p}wpcap_order_items WHERE order_id=%d", $x1_a['order_id'] ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_order_items WHERE ticket_type_id=%d", $x1_type2 ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET request_hash='' WHERE order_id=%d", $x1_a['order_id'] ) );
$x1_legacy = Orders::create( array( 'email' => 'x1-a420@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 3 ) ), 'idempotency_key' => $x1_key ) );
check( 'A420: an order from before the upgrade (no fingerprint) is still reused', ! is_wp_error( $x1_legacy ) && ! empty( $x1_legacy['reused'] ) );
check( 'A420: the schema has the column and the version was raised', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Installer.php' ), "request_hash char(64) NOT NULL DEFAULT ''" ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.24', '>' ) );

// A534.
check( 'A534: Http::accepted() takes only true/1/"1"/"true"/"on"/"yes"', Http::accepted( true ) && Http::accepted( 1 ) && Http::accepted( '1' ) && Http::accepted( 'TRUE' ) && Http::accepted( 'on' ) && Http::accepted( 'yes' ) && ! Http::accepted( 'false' ) && ! Http::accepted( '0' ) && ! Http::accepted( 'no' ) && ! Http::accepted( 'off' ) && ! Http::accepted( '' ) && ! Http::accepted( false ) && ! Http::accepted( 0 ) && ! Http::accepted( 2 ) && ! Http::accepted( array( 1 ) ) && ! Http::accepted( null ) );
$x1_terms_before = Settings::get( 'require_terms' );
Settings::update( array( 'require_terms' => 1 ) );
$x1_false = Orders::create( array( 'email' => 'x1-a534@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a534-f-' . uniqid(), 'accept_terms' => 'false' ) );
$x1_zero = Orders::create( array( 'email' => 'x1-a534@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a534-z-' . uniqid(), 'accept_terms' => '0' ) );
$x1_true = Orders::create( array( 'email' => 'x1-a534@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a534-t-' . uniqid(), 'accept_terms' => true ) );
Settings::update( array( 'require_terms' => $x1_terms_before ) );
check( 'A534: accept_terms "false" or "0" is no consent (400 wpcap_terms_required)', is_wp_error( $x1_false ) && 'wpcap_terms_required' === $x1_false->get_error_code() && is_wp_error( $x1_zero ) && 'wpcap_terms_required' === $x1_zero->get_error_code() );
check( 'A534: accept_terms true is consent', ! is_wp_error( $x1_true ) );
$x1_sms = (string) file_get_contents( WPCAP_PATH . 'src/Modules/Sms.php' );
$x1_wl = (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' );
check( 'A534: SMS consent and waitlist acceptance use the strict check', false !== strpos( $x1_sms, "Http::accepted( \$payload['sms_consent'] ?? null )" ) && false !== strpos( $x1_wl, "! Http::accepted( \$args['accept_terms'] ?? null )" ) );

// A421.
$x1_routes = wpcap_test_rest_endpoints();
$x1_args = static function ( $key ) use ( $x1_routes ) { return (array) ( $x1_routes[ $key ]['args']['args'] ?? array() ); };
$x1_items = $x1_args( 'POST pass-captain/v1/checkout' )['items'] ?? array();
check( 'A421: checkout items declare whole ticket type ids and quantities ≥ 1', 'array' === ( $x1_items['type'] ?? '' ) && 'integer' === ( $x1_items['items']['properties']['quantity']['type'] ?? '' ) && 1 === ( $x1_items['items']['properties']['quantity']['minimum'] ?? 0 ) && 1 === ( $x1_items['items']['properties']['ticket_type_id']['minimum'] ?? 0 ) && 'rest_validate_request_arg' === ( $x1_items['validate_callback'] ?? '' ) );
$x1_svc = $x1_args( 'POST pass-captain/v1/service-slots/(?P<slot_id>\d+)/bookings' );
$x1_wait = $x1_args( 'POST pass-captain/v1/events/(?P<event_id>\d+)/waitlist' );
check( 'A421: service booking and waitlist declare typed quantity, ids and e-mail', 'integer' === ( $x1_svc['quantity']['type'] ?? '' ) && 1 === ( $x1_svc['quantity']['minimum'] ?? 0 ) && 20 === ( $x1_svc['quantity']['maximum'] ?? 0 ) && 'string' === ( $x1_svc['email']['type'] ?? '' ) && 'integer' === ( $x1_wait['quantity']['type'] ?? '' ) && 'integer' === ( $x1_wait['ticket_type_id']['type'] ?? '' ) && 'string' === ( $x1_wait['email']['type'] ?? '' ) );
$x1_untyped = array();
foreach ( array( 'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/transfer', 'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/resend', 'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/billing', 'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/payout-account', 'POST pass-captain/v1/campaigns/preview', 'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/accept', 'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/decline' ) as $x1_route ) {
	$a = $x1_args( $x1_route );
	if ( ! $a ) { $x1_untyped[] = $x1_route; continue; }
	foreach ( $a as $name => $schema ) { if ( empty( $schema['type'] ) || 'rest_validate_request_arg' !== ( $schema['validate_callback'] ?? '' ) ) { $x1_untyped[] = "$x1_route:$name"; } }
}
check( 'A421: the public order portal, campaign preview and waitlist routes declare typed args' . ( $x1_untyped ? ' – ' . implode( ', ', $x1_untyped ) : '' ), ! $x1_untyped );
$x1_book = static function ( $quantity ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/service-slots/1/bookings' );
	$req['slot_id'] = 1;
	$req->set_body( json_encode( array( 'email' => 'x1-a421@example.com', 'quantity' => $quantity, 'idempotency_key' => 'x1-a421-' . uniqid() ) ) );
	return Controller::book_service_slot( $req );
};
$x1_neg = $x1_book( -2 );
$x1_frac = $x1_book( '2.5' );
$x1_big = $x1_book( 21 );
check( 'A421: a negative, fractional or too large service quantity is refused (400), not turned into 2', is_wp_error( $x1_neg ) && 'wpcap_service_quantity' === $x1_neg->get_error_code() && is_wp_error( $x1_frac ) && 'wpcap_service_quantity' === $x1_frac->get_error_code() && is_wp_error( $x1_big ) && 400 === $x1_big->get_error_data()['status'] );
$x1_prev = PassCaptain\Campaigns::preview( array( array( 'ticket_type_id' => $x1_type, 'quantity' => -2 ) ), 'X1NOPE' );
check( 'A421: the discount preview ignores a negative quantity', is_wp_error( $x1_prev ) || 0 === (int) ( $x1_prev['subtotal_minor'] ?? 0 ) );
