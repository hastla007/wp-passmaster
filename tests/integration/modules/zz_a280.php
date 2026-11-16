<?php
/**
 * Worklist item A280: IPv6 clients count per /64 (and their /48 network) in rate limits, so rotating
 * addresses does not multiply checkout, hold or waitlist limits. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\ClientIp;
use PassCaptain\Orders;
use PassCaptain\RateLimit;

$q1ip_saved_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$GLOBALS['wpcap_test_transients'] = array();

echo "A280: rate-limit keys of IPv6 clients\n";
check( 'A280 limit_key: IPv6 reduced to /64, IPv4 and IPv4-mapped unchanged, invalid empty',
	'2001:db8:1:2::/64' === ClientIp::limit_key( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) && '2001:db8:1:2::/64' === ClientIp::limit_key( '2001:DB8:1:2::1' )
	&& '2001:db8:1::/48' === ClientIp::limit_key( '2001:db8:1:2::1', 48 ) && '2001:db8:1:2:8000::/65' === ClientIp::limit_key( '2001:db8:1:2:8000::1', 65 )
	&& '192.0.2.7' === ClientIp::limit_key( '192.0.2.7' ) && '192.0.2.7' === ClientIp::limit_key( '::ffff:192.0.2.7' ) && '' === ClientIp::limit_key( 'nonsense' ) && '' === ClientIp::limit_key( '' ) );
check( 'A280 is_ipv6: true for IPv6, false for IPv4 and IPv4-mapped', ClientIp::is_ipv6( '2001:db8::1' ) && ! ClientIp::is_ipv6( '192.0.2.1' ) && ! ClientIp::is_ipv6( '::ffff:192.0.2.1' ) );
$_SERVER['REMOTE_ADDR'] = '2001:db8:280:1::1';
$q1ip_a = RateLimit::client();
$_SERVER['REMOTE_ADDR'] = '2001:db8:280:1:ffff:ffff:ffff:fffe';
$q1ip_b = RateLimit::client();
$_SERVER['REMOTE_ADDR'] = '2001:db8:280:2::1';
$q1ip_c = RateLimit::client();
check( 'A280 RateLimit::client(): same key inside a /64, another key for the next /64', $q1ip_a === $q1ip_b && $q1ip_a !== $q1ip_c && $q1ip_c !== RateLimit::client( 48 ) );

echo "A280: checkout limit with rotating IPv6 addresses\n";
$q1ip_type = make_type( 100, 10, 0 );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = 1;
$q1ip_buy = static function ( $addr, $n ) use ( $q1ip_type ) {
	$_SERVER['REMOTE_ADDR'] = $addr;
	return Orders::create( array( 'email' => "a280-$n@example.com", 'items' => array( array( 'ticket_type_id' => $q1ip_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a280-' . $n . '-' . uniqid() ) );
};
$q1ip_first = $q1ip_buy( '2001:db8:2800:1::1', 1 );
$q1ip_rot = $q1ip_buy( '2001:db8:2800:1::2', 2 );
check( 'A280 a second address of the same /64 is limited like the first (was a new client before)', ! is_wp_error( $q1ip_first ) && is_wp_error( $q1ip_rot ) && 'wpcap_rate_limited' === $q1ip_rot->get_error_code() );
$q1ip_ok = 0;
for ( $i = 2; $i <= 4; $i++ ) { $q1ip_ok += is_wp_error( $q1ip_buy( "2001:db8:2800:$i::1", 10 + $i ) ) ? 0 : 1; }
$q1ip_net = $q1ip_buy( '2001:db8:2800:5::1', 20 );
check( 'A280 other /64s of the same /48 share 4× the limit, then the network is limited', 3 === $q1ip_ok && is_wp_error( $q1ip_net ) && 'wpcap_rate_limited' === $q1ip_net->get_error_code() );
check( 'A280 another /48 and IPv4 clients are unaffected', ! is_wp_error( $q1ip_buy( '2001:db8:2801:1::1', 30 ) ) && ! is_wp_error( $q1ip_buy( '192.0.2.80', 31 ) ) && is_wp_error( $q1ip_buy( '192.0.2.80', 32 ) ) && ! is_wp_error( $q1ip_buy( '192.0.2.81', 33 ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] );

echo "A280: partner key lockout per /64\n";
$q1ip_lock = new ReflectionMethod( PassCaptain\Partners\Keys::class, 'fail_bucket' );
$q1ip_lock->setAccessible( true );
check( 'A280 partner lockout key is the same inside a /64', $q1ip_lock->invoke( null, '2001:db8:9:9::1' ) === $q1ip_lock->invoke( null, '2001:db8:9:9:1:2:3:4' ) && $q1ip_lock->invoke( null, '2001:db8:9:9::1' ) !== $q1ip_lock->invoke( null, '2001:db8:9:a::1' ) );

if ( null === $q1ip_saved_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $q1ip_saved_remote; }
$GLOBALS['wpcap_test_transients'] = array();
