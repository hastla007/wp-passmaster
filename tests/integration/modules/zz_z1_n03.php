<?php
/**
 * Z1 / N03 (merge follow-up): one address classifier and one proxy list.
 * - Security\OutboundHttp (admin-configured webhook/SMS/fiscal targets) classified addresses with
 *   its own, shorter block list: the Azure wire server 168.63.129.16, the special-purpose block
 *   2001::/23 (ORCHID, AMT, …) and the local-use NAT64 prefix 64:ff9b:1::/48 counted as public.
 *   It now uses PassCaptain\OutboundHttp::is_public_ip() (also: IPv4-translated ::ffff:0:a.b.c.d).
 * - RateLimit::trusted_proxies() kept CIDR entries with an impossible prefix length (10.0.0.0/99),
 *   which then counted as "proxies configured" for the checkout throttle although ClientIp dropped them.
 * - RateLimit::client_network() is ClientIp::limit_key( $ip, 64 ).
 * Self-contained (WPCAP_TEST_ONLY=z1_n03).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\ClientIp;
use PassCaptain\OutboundHttp as CoreOutbound;
use PassCaptain\RateLimit;
use PassCaptain\Security\OutboundHttp as SecurityOutbound;
use PassCaptain\Settings;

echo "Z1/N03: one address classifier for both outbound guards\n";
$z1n_private = array( '168.63.129.16', '2001:10::1', '2001:20::1', '2001:1::1', '64:ff9b:1::1', '::ffff:0:7f00:1', '::ffff:0:a9fe:a9fe', '127.0.0.1', '169.254.169.254', '::ffff:10.0.0.1', '64:ff9b::a9fe:a9fe', 'fd00:ec2::254', '2002:7f00:1::1' );
$z1n_public = array( '93.184.216.34', '2606:4700:4700::1111', '2001:4860::1', '64:ff9b::808:808', '2002:0808:0808::1' );
$z1n_leaks = array_values( array_filter( $z1n_private, static fn( $ip ) => SecurityOutbound::is_public_ip( $ip ) || CoreOutbound::is_public_ip( $ip ) ) );
check( 'z1/n03: metadata, special-purpose and translated addresses are not public in either guard' . ( $z1n_leaks ? ' – ' . implode( ', ', $z1n_leaks ) : '' ), ! $z1n_leaks );
$z1n_diff = array_values( array_filter( array_merge( $z1n_private, $z1n_public ), static fn( $ip ) => SecurityOutbound::is_public_ip( $ip ) !== CoreOutbound::is_public_ip( $ip ) ) );
check( 'z1/n03: both guards classify every address the same way' . ( $z1n_diff ? ' – ' . implode( ', ', $z1n_diff ) : '' ), ! $z1n_diff && ! array_filter( $z1n_public, static fn( $ip ) => ! SecurityOutbound::is_public_ip( $ip ) ) );
$GLOBALS['wpcap_test_dns']['z1-wire.example'] = array( '168.63.129.16' );
$GLOBALS['wpcap_test_dns']['z1-orchid.example'] = array( '2001:10::5' );
$z1n_wire = SecurityOutbound::validate_url( 'https://z1-wire.example/hook' );
$z1n_orchid = SecurityOutbound::validate_url( 'https://z1-orchid.example/hook' );
check( 'z1/n03: a webhook target resolving to the Azure wire server or 2001::/23 is refused', is_wp_error( $z1n_wire ) && 'wpcap_outbound_private_target' === $z1n_wire->get_error_code() && is_wp_error( $z1n_orchid ) && 'wpcap_outbound_private_target' === $z1n_orchid->get_error_code() );
check( 'z1/n03: a public target is still accepted', true === SecurityOutbound::validate_url( 'https://z1-public.example/hook' ) );
check( 'z1/n03: CIDR helper of the security guard matches like ClientIp (incl. mapped peers, invalid prefix = no match)', SecurityOutbound::ip_in_cidr( '10.1.2.3', '10.0.0.0/8' ) && SecurityOutbound::ip_in_cidr( '::ffff:10.1.2.3', '10.0.0.0/8' ) && ! SecurityOutbound::ip_in_cidr( '11.0.0.1', '10.0.0.0/8' ) && ! @SecurityOutbound::ip_in_cidr( '10.0.0.1', '10.0.0.0/99' ) && SecurityOutbound::ip_in_cidr( '2001:db8::5', '2001:db8::/32' ) );
unset( $GLOBALS['wpcap_test_dns']['z1-wire.example'], $GLOBALS['wpcap_test_dns']['z1-orchid.example'] );

echo "Z1/N03: one trusted-proxy list and one network key\n";
$z1n_before = Settings::get( 'trusted_proxies' );
Settings::update( array( 'trusted_proxies' => "10.0.0.0/99\n192.168.0.0/33" ) );
check( 'z1/n03: proxy entries with an impossible prefix length are dropped by RateLimit too (no "proxy configured")', array() === RateLimit::trusted_proxies() && array() === ClientIp::trusted_proxies() );
Settings::update( array( 'trusted_proxies' => "10.0.0.0/8\nfoo\n2001:db8::/32\n10.0.0.0/33" ) );
check( 'z1/n03: RateLimit and ClientIp return the same list', array( '10.0.0.0/8', '2001:db8::/32' ) === RateLimit::trusted_proxies() && RateLimit::trusted_proxies() === ClientIp::trusted_proxies() );
Settings::update( array( 'trusted_proxies' => (string) $z1n_before ) );
$z1n_net = array( '2001:db8:1:2:aaaa::1', '203.0.113.9', '::ffff:203.0.113.9', 'not-an-ip' );
check( 'z1/n03: client_network() equals ClientIp::limit_key( ip, 64 ) (invalid input passes through)', array( '2001:db8:1:2::/64', '203.0.113.9', '203.0.113.9', 'not-an-ip' ) === array_map( array( RateLimit::class, 'client_network' ), $z1n_net ) && ClientIp::limit_key( '2001:db8:1:2:aaaa::1', 64 ) === RateLimit::client_network( '2001:db8:1:2:aaaa::1' ) );
