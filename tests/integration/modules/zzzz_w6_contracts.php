<?php
/**
 * Bridge and REST contract compatibility (W6): WP Voucher Captain bridge (v1), WP Affiliate Captain
 * adapter (contract 1.x) and the mobile/offline scanner API.
 *
 * Unknown extra fields, missing optional fields, incompatible versions, contract-violating answers,
 * provider exceptions (network timeouts) and duplicate requests.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Integrations\Vouchers\Bridge as W6Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as W6Vouchers;
use PassCaptain\Integrations\AffiliateCaptain\Adapter as W6Affiliate;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

/** Scriptable v1 voucher provider. */
final class W6_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public array $script = array(); // method => callable( ...$args ) returning the answer.
	private function answer( $method, array $args, $default ) {
		$this->calls[] = array( $method, end( $args ) );
		return isset( $this->script[ $method ] ) ? call_user_func_array( $this->script[ $method ], $args ) : $default;
	}
	public function quote( array $context ) { return $this->answer( 'quote', array( $context ), array( 'amount_minor' => 500, 'currency' => $context['currency'] ) ); }
	public function reserve( array $quote, $key ) { return $this->answer( 'reserve', array( $quote, $key ), array( 'reservation' => 'r-' . $key ) ); }
	public function commit( array $reservation, $key ) { return $this->answer( 'commit', array( $reservation, $key ), array( 'redemption' => 'd-' . $key ) ); }
	public function release( array $reservation, $key ) { return $this->answer( 'release', array( $reservation, $key ), true ); }
	public function refund( array $redemption, $amount, $key ) { return $this->answer( 'refund', array( $redemption, $amount, $key ), true ); }
}
$w6c_saved_override = $GLOBALS['wpcap_test_filters'][ W6Bridge::FILTER ] ?? null;
unset( $GLOBALS['wpcap_test_filters'][ W6Bridge::FILTER ] );
$w6c_provider = new W6_Voucher_Provider();
$w6c_supported = 1;
/** Provider plugins hook the versioned filter and only answer for the version they implement. */
$w6c_filter = static function ( $provider, $version ) use ( &$w6c_supported, $w6c_provider ) { return (int) $version === $w6c_supported ? $w6c_provider : $provider; };
add_filter( W6Bridge::FILTER, $w6c_filter, 10, 2 );

$w6c_type = make_type( 0, 10, 2000 );
function w6c_order( $type ) {
	wpcap_test_reset_transients();
	return Orders::create( array( 'email' => 'w6c' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w6c-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
}
function w6c_total( $o ) { return (int) Orders::get( $o['order_id'] )->total_minor; }

echo "W6 contracts: WP Voucher Captain bridge v1\n";
$w6c_supported = 2;
$o = w6c_order( $w6c_type );
$r = W6Vouchers::apply( $o['order_id'], 'CODE-1' );
check( 'provider that only implements contract v2 is never called (bridge asks for v1)', ! W6Bridge::available() && is_wp_error( $r ) && 'wpcap_voucher_unavailable' === $r->get_error_code() && ! $w6c_provider->calls && 2000 === w6c_total( $o ) );
$w6c_supported = 1;

$w6c_provider->script['quote'] = static fn( $ctx ) => array( 'amount_minor' => '500', 'currency' => strtolower( $ctx['currency'] ), 'future_field' => array( 'x' => 1 ), 'treatment' => 'payment', 'voucher' => array( 'brand_new' => true ) );
$w6c_provider->script['reserve'] = static fn( $q, $k ) => array( 'reservation' => 'r-' . $k, 'unknown' => 'ignored' );
$r = W6Vouchers::apply( $o['order_id'], 'CODE-1' );
check( 'unknown extra fields, numeric strings and lower-case currency are accepted', is_array( $r ) && 500 === $r['applied_minor'] && 1500 === w6c_total( $o ) );
$again = W6Vouchers::apply( $o['order_id'], 'CODE-1' );
check( 'duplicate apply on the same order is refused without a second reservation', is_wp_error( $again ) && 1 === count( array_filter( $w6c_provider->calls, static fn( $c ) => 'reserve' === $c[0] ) ) && 1500 === w6c_total( $o ) );

$w6c_provider->script = array( 'quote' => static fn( $ctx ) => array( 'amount_minor' => 300 ) );
$o = w6c_order( $w6c_type );
$r = W6Vouchers::apply( $o['order_id'], 'CODE-2' );
check( 'missing optional fields (currency, treatment) fall back to the order currency / default purpose', is_array( $r ) && 300 === $r['applied_minor'] );

$bad_quotes = array(
	'currency mismatch' => array( 'amount_minor' => 300, 'currency' => 'USD' ),
	'amount above total' => array( 'amount_minor' => 999999, 'currency' => 'EUR' ),
	'negative amount' => array( 'amount_minor' => -5, 'currency' => 'EUR' ),
	'missing amount' => array( 'currency' => 'EUR' ),
);
foreach ( $bad_quotes as $label => $quote ) {
	$w6c_provider->script = array( 'quote' => static fn( $ctx ) => $quote );
	$w6c_provider->calls = array();
	$o = w6c_order( $w6c_type );
	$r = W6Vouchers::apply( $o['order_id'], 'BAD' );
	check( "invalid quote ($label) is refused before anything is reserved", is_wp_error( $r ) && ! array_filter( $w6c_provider->calls, static fn( $c ) => 'reserve' === $c[0] ) && 2000 === w6c_total( $o ) );
}

$violations = array(
	'quote throws (timeout)' => array( 'quote', static function () { throw new RuntimeException( 'cURL error 28: Operation timed out' ); } ),
	'reserve throws (timeout)' => array( 'reserve', static function () { throw new RuntimeException( 'cURL error 28: Operation timed out' ); } ),
	'quote returns a string' => array( 'quote', static fn() => 'OK' ),
	'reserve returns null' => array( 'reserve', static fn() => null ),
	'reserve returns true' => array( 'reserve', static fn() => true ),
);
foreach ( $violations as $label => list( $method, $fn ) ) {
	$w6c_provider->script = array( $method => $fn );
	$o = w6c_order( $w6c_type );
	$thrown = false;
	try { $r = W6Vouchers::apply( $o['order_id'], 'CODE-X' ); } catch ( Throwable $e ) { $thrown = true; $r = null; }
	$order = Orders::get( $o['order_id'] );
	check( "provider $label: clean error, order unchanged, no voucher snapshot", ! $thrown && is_wp_error( $r ) && 2000 === (int) $order->total_minor && empty( $order->voucher_snapshot ) );
}
$w6c_provider->script = array( 'commit' => static function () { throw new RuntimeException( 'timeout' ); } );
$res = W6Bridge::commit( array( 'reservation' => 'r-1' ), 'k' );
check( 'commit timeout reaches the caller as a retryable error (the commit job retries it)', is_wp_error( $res ) && ! empty( $res->get_error_data()['retryable'] ) );
$w6c_provider->script = array( 'refund' => static fn() => 'done' );
check( 'refund answer outside the contract is not taken as success', is_wp_error( W6Bridge::refund( array( 'redemption' => 'd' ), 100, 'k' ) ) );
$w6c_provider->script = array();
check( 'contract-conform release/refund answers (true) pass through', true === W6Bridge::release( array(), 'k' ) && true === W6Bridge::refund( array(), 1, 'k' ) );

remove_filter( W6Bridge::FILTER, $w6c_filter, 10 );
if ( null !== $w6c_saved_override ) { $GLOBALS['wpcap_test_filters'][ W6Bridge::FILTER ] = $w6c_saved_override; }

/* ------------------------------------------------------------------------------------------ */
echo "W6 contracts: WP Affiliate Captain adapter 1.x\n";
$versions = array( '1.0.0' => true, '1.9.3' => true, '1' => true, '2.0.0' => false, '0.9.9' => false, '' => false, 'abc' => false, '10.0' => false );
$ok = true;
foreach ( $versions as $v => $expected ) { $GLOBALS['wpac_test_version'] = $v; if ( W6Affiliate::available() !== $expected ) { $ok = false; } }
$GLOBALS['wpac_test_version'] = '1.1.0';
check( 'contract version gate: same major only (1.0.0, 1.9.3, 1 ok; 2.0.0, 0.9.9, empty, garbage, 10.0 refused)', $ok );

$aff_type = make_type( 0, 10, 11900 );
$o = w6c_order( $aff_type );
add_filter( 'wpcap_affiliate_sale_payload', static function ( $payload ) { $GLOBALS['w6c_last_payload'] = $payload; return $payload; } );
Orders::confirm_manual_payment( $o['order_id'] );
$first = W6Affiliate::on_paid( $o['order_id'] );
check( 'sale payload carries the contract version and source (receiver can gate on it)', '1.0.0' === ( $GLOBALS['w6c_last_payload']['contract'] ?? '' ) && 'pass-captain' === ( $GLOBALS['w6c_last_payload']['source'] ?? '' ) );
$count = count( array_filter( $GLOBALS['wpac_test_sales'], static fn( $s ) => (string) $s['order_id'] === (string) $o['order_id'] ) );
check( 'duplicate paid hook / manual retry reports the sale once', $count <= 1 && null === W6Affiliate::on_paid( $o['order_id'] ) && $count === count( array_filter( $GLOBALS['wpac_test_sales'], static fn( $s ) => (string) $s['order_id'] === (string) $o['order_id'] ) ) );

/* ------------------------------------------------------------------------------------------ */
echo "W6 contracts: mobile / offline scanner API\n";
$m_type = make_type( 0, 10, 0 );
$m_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$m_type" );
$GLOBALS['wpcap_test_user_id'] = 1;
$pairing = PassCaptain\Mobile\Devices::create_pairing( array( $m_event ), 'W6 contract', 12 );
$device = PassCaptain\Mobile\Devices::pair( $pairing['code'], 'W6 contract', 'android', '9.9.9-future' );
$GLOBALS['wpcap_test_user_id'] = 1;
$token = (string) ( $device['token'] ?? '' );
$me = app_call( 'me', 'GET', $token );
check( 'device of a newer app version pairs; /me reports the API version', '' !== $token && is_array( $me ) && PassCaptain\Mobile\Api::VERSION === $me['api_version'] );
wpcap_test_reset_transients();
$mo = Orders::create( array( 'email' => 'w6c-m@example.com', 'items' => array( array( 'ticket_type_id' => $m_type, 'quantity' => 3 ) ), 'idempotency_key' => 'w6c-m-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$codes = ticket_code_for( $mo['order_id'] );
$scans = array(
	array( 'code' => $codes[0], 'idempotency_key' => 'k1', 'scanned_at' => gmdate( 'c', time() - 120 ), 'direction' => 'in', 'app_extra' => array( 'battery' => 12 ), 'gps' => '48.2,16.3' ),
	array( 'code' => $codes[1], 'idempotency_key' => 'k2' ), // Optional scanned_at / direction missing.
	array( 'code' => $codes[0], 'idempotency_key' => 'k1', 'scanned_at' => gmdate( 'c', time() - 120 ) ), // Duplicate in the same batch.
	array( 'idempotency_key' => 'k3' ), // Code missing.
	array( 'code' => $codes[2] ), // Key missing.
	'garbage',
);
$res = app_call( 'sync', 'POST', $token, array( 'scans' => $scans, 'client' => array( 'schema' => 99 ) ) );
$rows = is_array( $res ) ? $res['results'] : array();
check( 'sync tolerates unknown fields and answers every scan in order', 6 === count( $rows ) && true === $rows[0]['ok'] && true === $rows[1]['ok'] );
check( 'duplicate scan in the same batch replays instead of counting twice', true === ( $rows[2]['ok'] ?? null ) && ! empty( $rows[2]['replayed'] ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_in' AND ticket_id=(SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $codes[0] ) . "')" ) );
check( 'malformed scans (no code, no key, not an object) get a per-row error, not a failed request', false === $rows[3]['ok'] && 'invalid' === $rows[4]['result'] && 'invalid' === $rows[5]['result'] );
$res2 = app_call( 'sync', 'POST', $token, array( 'scans' => array_slice( $scans, 0, 2 ) ) );
check( 'whole batch uploaded again (lost response) changes nothing', true === $res2['results'][0]['ok'] && ! empty( $res2['results'][0]['replayed'] ) && ! empty( $res2['results'][1]['replayed'] ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $mo['order_id'] . " AND status='checked_in'" ) );
$too_many = app_call( 'sync', 'POST', $token, array( 'scans' => array_fill( 0, 201, array( 'code' => $codes[2], 'idempotency_key' => 'x' ) ) ) );
check( 'batch above the documented limit (200) is rejected as a whole', is_wp_error( $too_many ) && 400 === $too_many->get_error_data()['status'] );
