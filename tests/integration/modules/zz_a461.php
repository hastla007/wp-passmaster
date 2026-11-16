<?php
/**
 * A461: the Apple Wallet web service answers a rate limit with 429 + Retry-After (never 401, which
 * tells the device its pass is invalid), limits per serial number / device library instead of per
 * client address, and counts only failed requests per address (venue / carrier NAT). The signed
 * download and Google save links answer 429 instead of "invalid link". Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\WalletPasses\WebService;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A461: wallet web service rate limits (429 + Retry-After, per serial/device)\n";
global $wpdb;
$a461_fx = wpr7_wallet_enable();
$a461_type = make_type( 10, 10, 0 );
$a461_r = Orders::create( array( 'email' => 'a461@example.com', 'items' => array( array( 'ticket_type_id' => $a461_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a461-' . uniqid() ) );
$a461_tickets = tickets( $a461_r['order_id'] );
$a461_mail = wpr7_ticket_mail( 'a461@example.com' );
$a461_auth = array();
foreach ( $a461_tickets as $a461_t ) {
	$a461_pj = json_decode( wpr7_unzip( $a461_mail['files'][ 'ticket-' . substr( $a461_t->public_id, 0, 12 ) . '.pkpass' ] ?? '' )['pass.json'] ?? '{}', true );
	$a461_auth[ $a461_t->public_id ] = (string) ( $a461_pj['authenticationToken'] ?? '' );
}
check( 'A461 setup: two passes with tokens', 2 === count( array_filter( $a461_auth ) ) );
$a461_serials = array_keys( $a461_auth );

// Small limits in a long window, so the test never crosses a window boundary.
$a461_limits = static function () { return array( 'serial' => 3, 'device' => 3, 'failures' => 2, 'download' => 2, 'google' => 2, 'window' => 3600 ); };
add_filter( 'wpcap_wallet_rate_limits', $a461_limits );
$a461_ip_old = $_SERVER['REMOTE_ADDR'] ?? null;
$a461_req = static function ( $serial, $token, $ip ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$r = new WP_REST_Request( 'GET' );
	$r->set_param( 'pass_type', 'pass.test.wpcap' );
	$r->set_param( 'serial', $serial );
	if ( null !== $token ) { $r->set_header( 'Authorization', 'ApplePass ' . $token ); }
	return $r;
};
$a461_status = static function ( $res ) { return is_wp_error( $res ) ? (int) ( $res->get_error_data()['status'] ?? 0 ) : (int) $res->get_status(); };
$a461_retry = static function ( $res ) { return ( ! is_wp_error( $res ) && method_exists( $res, 'get_headers' ) ) ? (int) ( $res->get_headers()['Retry-After'] ?? 0 ) : 0; };
$a461_nat = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );

// Many phones behind one address: each pass has its own budget; the shared address is not limited.
$a461_codes = array();
foreach ( $a461_serials as $a461_serial ) {
	for ( $i = 0; $i < 3; $i++ ) { $a461_codes[] = $a461_status( WebService::latest_pass( $a461_req( $a461_serial, $a461_auth[ $a461_serial ], $a461_nat ) ) ); }
}
check( 'A461: six authenticated fetches of two passes from one address all succeed', array( 200 ) === array_values( array_unique( array_map( static fn( $c ) => 304 === $c ? 200 : $c, $a461_codes ) ) ) );
$a461_over = WebService::latest_pass( $a461_req( $a461_serials[0], $a461_auth[ $a461_serials[0] ], '198.19.1.' . random_int( 1, 250 ) ) );
check( 'A461: over the per-serial limit → 429 (not 401), also from another address', 429 === $a461_status( $a461_over ) );
check( 'A461: 429 carries Retry-After (seconds until the window ends) and no-store', $a461_retry( $a461_over ) >= 1 && $a461_retry( $a461_over ) <= 3600 && 'no-store' === ( $a461_over->get_headers()['Cache-Control'] ?? '' ) );

// Failed requests count per address; at the limit the address gets 429, other addresses do not.
$a461_bad = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$a461_f1 = $a461_status( WebService::latest_pass( $a461_req( $a461_serials[1], str_repeat( 'x', 40 ), $a461_bad ) ) );
$a461_f2 = $a461_status( WebService::latest_pass( $a461_req( 'unknown-' . uniqid(), str_repeat( 'y', 40 ), $a461_bad ) ) );
$a461_f3 = WebService::latest_pass( $a461_req( $a461_serials[1], $a461_auth[ $a461_serials[1] ], $a461_bad ) );
check( 'A461: wrong token / unknown serial → 401 until the failure budget is used up', 401 === $a461_f1 && 401 === $a461_f2 );
check( 'A461: then the address gets 429 with Retry-After (not 401: the pass stays valid on the device)', 429 === $a461_status( $a461_f3 ) && $a461_retry( $a461_f3 ) >= 1 );
check( 'A461: registration from that address is answered 429 too', 429 === $a461_status( WebService::register_device( $a461_req( $a461_serials[1], $a461_auth[ $a461_serials[1] ], $a461_bad ) ) ) );

// Serial list: per device library id; unknown ids count as failures of the address.
$a461_dev = 'devA461' . bin2hex( random_bytes( 3 ) );
$a461_list = static function ( $device, $ip ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$r = new WP_REST_Request( 'GET' );
	$r->set_param( 'device', $device );
	$r->set_param( 'pass_type', 'pass.test.wpcap' );
	$r->set_param( 'passesUpdatedSince', '' );
	return WebService::serials( $r );
};
$a461_l = array();
// From changing addresses, so the failure budget of one address is not what stops the list.
for ( $i = 0; $i < 3; $i++ ) { $a461_l[] = $a461_status( $a461_list( $a461_dev, '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 ) ) ); }
$a461_l4 = $a461_list( $a461_dev, '198.19.2.' . random_int( 1, 250 ) );
$a461_ip5 = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$a461_u = array( $a461_status( $a461_list( 'devX' . uniqid(), $a461_ip5 ) ), $a461_status( $a461_list( 'devY' . uniqid(), $a461_ip5 ) ), $a461_status( $a461_list( 'devZ' . uniqid(), $a461_ip5 ) ) );
check( 'A461: unknown device ids count as failures of the address (404, 404, then 429)', array( 404, 404, 429 ) === $a461_u );
check( 'A461: serial list over the per-device limit → 429 with Retry-After', array( 404 ) === array_values( array_unique( $a461_l ) ) && 429 === $a461_status( $a461_l4 ) && $a461_retry( $a461_l4 ) >= 1 );

// Signed download link: the limit is no "invalid link".
$a461_ip4 = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$a461_dl = static function ( $ip ) use ( $a461_serials ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$r = new WP_REST_Request( 'GET' );
	$r->set_param( 'serial', $a461_serials[0] );
	$r->set_param( 'sig', str_repeat( '0', 64 ) );
	return WebService::download( $r );
};
$a461_d = array( $a461_status( $a461_dl( $a461_ip4 ) ), $a461_status( $a461_dl( $a461_ip4 ) ) );
$a461_d3 = $a461_dl( $a461_ip4 );
check( 'A461: download link over the limit → 429 with Retry-After instead of 404', array( 404, 404 ) === $a461_d && 429 === $a461_status( $a461_d3 ) && $a461_retry( $a461_d3 ) >= 1 );
check( 'A461: limits are filterable (wpcap_wallet_rate_limits)', 3 === WebService::limits()['serial'] && 3600 === WebService::limits()['window'] );

remove_filter( 'wpcap_wallet_rate_limits', $a461_limits );
check( 'A461: defaults per minute', 60 === WebService::limits()['window'] && WebService::limits()['serial'] >= 60 );
if ( null === $a461_ip_old ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $a461_ip_old; }
wpr7_wallet_disable();
