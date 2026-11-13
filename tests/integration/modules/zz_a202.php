<?php
/**
 * A202: central outbound HTTP guard (OutboundHttp) and every caller: HTTPS only, no private/
 * loopback/link-local/metadata targets after DNS (IPv4/IPv6), DNS pinned against rebinding, no
 * redirects (or re-validated ones), timeouts, response size limits, redaction of secrets.
 * DNS is stubbed through `wpcap_outbound_resolve`, the transport through the HTTP stub and
 * `pre_http_request`.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Fiscalization\Providers\HttpClient as A202Fiscal;
use PassCaptain\OutboundHttp;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

$a202_code = static function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; };
$a202_dns = static function ( $host, $ips ) { $GLOBALS['wpcap_test_dns'][ $host ] = $ips; };
$a202_blocked = array();
add_action( 'wpcap_outbound_http_blocked', static function ( $code, $url, $context ) use ( &$a202_blocked ) { $a202_blocked[] = array( $code, $url, $context ); }, 10, 3 );

echo "A202: address classification (IPv4/IPv6)\n";
$a202_private = array( '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.100.100.200', '0.0.0.0', '224.0.0.1', '255.255.255.255', '198.18.0.1', '192.0.0.192', '168.63.129.16', '::', '::1', 'fe80::1', 'fc00::1', 'fd00:ec2::254', 'ff02::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe', '2002:7f00:1::1', '2001:db8::1', 'fec0::1', 'not-an-ip' );
$a202_public = array( '93.184.216.34', '8.8.8.8', '172.32.0.1', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8', '64:ff9b::808:808', '2002:0808:0808::1' );
$a202_bad = array_filter( $a202_private, static fn( $ip ) => OutboundHttp::is_public_ip( $ip ) );
$a202_good = array_filter( $a202_public, static fn( $ip ) => ! OutboundHttp::is_public_ip( $ip ) );
check( 'private, loopback, link-local, CGNAT, metadata, reserved and embedded IPv4 addresses are not public' . ( $a202_bad ? ' – ' . implode( ', ', $a202_bad ) : '' ), ! $a202_bad );
check( 'public IPv4/IPv6 addresses (also mapped/NAT64/6to4 of public IPv4) are public' . ( $a202_good ? ' – ' . implode( ', ', $a202_good ) : '' ), ! $a202_good );

echo "A202: URL checks\n";
$a202_dns( 'public.example', array( '93.184.216.34' ) );
$a202_dns( 'private.example', array( '10.0.0.7' ) );
$a202_dns( 'mixed.example', array( '93.184.216.34', '169.254.169.254' ) );
$a202_dns( 'v6.example', array( '::1' ) );
$a202_dns( 'nodns.example', array() );
$a202_dns( 'v6only.example', array( '2606:4700:4700::1111' ) );
check( 'http is refused', 'wpcap_http_insecure' === $a202_code( OutboundHttp::check( 'http://public.example/x' ) ) );
check( 'credentials in the URL, relative and odd URLs are refused', 'wpcap_http_url_invalid' === $a202_code( OutboundHttp::check( 'https://user:pw@public.example/' ) ) && 'wpcap_http_url_invalid' === $a202_code( OutboundHttp::check( '//public.example/' ) ) && 'wpcap_http_url_invalid' === $a202_code( OutboundHttp::check( 'https://public.example\\@evil/' ) ) );
$a202_hosts = array( 'https://localhost/', 'https://a.localhost/', 'https://printer.local/', 'https://metadata.google.internal/', 'https://intranet/', 'https://2130706433/', 'https://0x7f.1/', 'https://127.1/', 'https://127.0.0.1/', 'https://[::1]/', 'https://[::ffff:169.254.169.254]/', 'https://169.254.169.254/latest/meta-data/' );
$a202_open = array_filter( $a202_hosts, static fn( $u ) => 'wpcap_http_host_blocked' !== $a202_code( OutboundHttp::check( $u ) ) );
check( 'local names, single labels, non-canonical and private IP literals are refused' . ( $a202_open ? ' – ' . implode( ', ', $a202_open ) : '' ), ! $a202_open );
check( 'host resolving to a private address is refused after DNS', 'wpcap_http_host_blocked' === $a202_code( OutboundHttp::check( 'https://private.example/' ) ) && 'wpcap_http_host_blocked' === $a202_code( OutboundHttp::check( 'https://v6.example/' ) ) );
check( 'one private address among public ones is enough to refuse', 'wpcap_http_host_blocked' === $a202_code( OutboundHttp::check( 'https://mixed.example/' ) ) );
check( 'unresolvable host is refused (fail closed)', 'wpcap_http_dns' === $a202_code( OutboundHttp::check( 'https://nodns.example/' ) ) );
$a202_t = OutboundHttp::check( 'https://public.example:8443/hook' );
check( 'public host: checked addresses and CURLOPT_RESOLVE pin host:port:ip', ! is_wp_error( $a202_t ) && array( 'public.example:8443:93.184.216.34' ) === $a202_t['pin'] );
$a202_t = OutboundHttp::check( 'https://v6only.example/' );
check( 'IPv6-only host is pinned with brackets', ! is_wp_error( $a202_t ) && array( 'v6only.example:443:[2606:4700:4700::1111]' ) === $a202_t['pin'] );
check( 'fixed provider hosts: any other host is refused', 'wpcap_http_host_not_allowed' === $a202_code( OutboundHttp::check( 'https://public.example/', array( 'hosts' => array( 'api.stripe.com' ) ) ) ) && ! is_wp_error( OutboundHttp::check( 'https://api.stripe.com/v1/x', array( 'hosts' => array( 'api.stripe.com' ) ) ) ) );
check( 'exact exception origin allows plain HTTP to the fixed local endpoint only', ! is_wp_error( OutboundHttp::check( 'http://127.0.0.1:5618/state', array( 'exceptions' => array( 'http://127.0.0.1:5618' ) ) ) ) && 'wpcap_http_insecure' === $a202_code( OutboundHttp::check( 'http://127.0.0.1:5619/state', array( 'exceptions' => array( 'http://127.0.0.1:5618' ) ) ) ) && 'wpcap_http_insecure' === $a202_code( OutboundHttp::check( 'http://127.0.0.1:5618/state' ) ) );
add_filter( 'wpcap_outbound_http_exceptions', $a202_exc = static function ( $list, $context ) { return 'fiscal' === $context ? array_merge( $list, array( 'http://192.168.10.5:5618' ) ) : $list; }, 10, 2 );
check( 'site exception filter is scoped to its context', ! is_wp_error( OutboundHttp::check( 'http://192.168.10.5:5618/x', array( 'context' => 'fiscal' ) ) ) && is_wp_error( OutboundHttp::check( 'http://192.168.10.5:5618/x', array( 'context' => 'webhook' ) ) ) );
remove_filter( 'wpcap_outbound_http_exceptions', $a202_exc );
check( 'blocked requests fire wpcap_outbound_http_blocked with a redacted URL', (bool) array_filter( $a202_blocked, static fn( $b ) => 'wpcap_http_url_invalid' === $b[0] && false === strpos( $b[1], 'pw@' ) ) );

echo "A202: request hardening, pinning and DNS rebinding\n";
http_reset();
http_push( array( 'ok' => 1 ), 200, 'public.example' );
$a202_r = OutboundHttp::request( 'https://public.example/in', array( 'method' => 'POST', 'timeout' => 300, 'redirection' => 5, 'sslverify' => false, 'filename' => '/tmp/x', 'headers' => array( 'Authorization' => 'Bearer abc' ), 'body' => 'x' ), array( 'context' => 'test' ) );
$a202_args = http_requests( 'public.example' )[0]['args'] ?? array();
check( 'request passes: redirection 0, timeout capped at 30 s, TLS verification forced, no file download, size limit, reject_unsafe_urls', ! is_wp_error( $a202_r ) && 0 === $a202_args['redirection'] && 30 === $a202_args['timeout'] && true === $a202_args['sslverify'] && ! isset( $a202_args['filename'] ) && OutboundHttp::DEFAULT_MAX_BYTES + 1 === $a202_args['limit_response_size'] && true === $a202_args['reject_unsafe_urls'] );
$a202_curl = OutboundHttp::last_curl_options();
check( 'http_api_curl pins the connection (CURLOPT_RESOLVE), no cURL redirects, HTTPS only, connect timeout 5 s', array( 'public.example:443:93.184.216.34' ) === ( $a202_curl[ CURLOPT_RESOLVE ] ?? null ) && false === $a202_curl[ CURLOPT_FOLLOWLOCATION ] && CURLPROTO_HTTPS === $a202_curl[ CURLOPT_PROTOCOLS ] && 5 === $a202_curl[ CURLOPT_CONNECTTIMEOUT ] );
check( 'requests that did not pass the guard get no cURL changes', array() === OutboundHttp::curl_options( array( 'timeout' => 5 ) ) );

// Rebinding: the first DNS answer is public, every later answer points to loopback.
$GLOBALS['a202_rebind'] = 0;
add_filter( 'wpcap_outbound_resolve', $a202_rebinder = static function ( $ips, $host ) { if ( 'rebind.example' !== $host ) { return $ips; } return 1 === ++$GLOBALS['a202_rebind'] ? array( '93.184.216.34' ) : array( '127.0.0.1' ); }, 20, 2 );
http_reset();
http_push( array( 'ok' => 1 ), 200, 'rebind.example' );
$a202_r = OutboundHttp::request( 'https://rebind.example/hook', array( 'method' => 'POST', 'body' => '{}' ) );
$a202_args = http_requests( 'rebind.example' )[0]['args'] ?? array();
check( 'rebinding: resolved once, the connection is pinned to the checked public address', ! is_wp_error( $a202_r ) && 1 === $GLOBALS['a202_rebind'] && array( 'rebind.example:443:93.184.216.34' ) === ( $a202_args[ OutboundHttp::PIN_ARG ] ?? null ) && array( 'rebind.example:443:93.184.216.34' ) === OutboundHttp::last_curl_options()[ CURLOPT_RESOLVE ] );
http_reset();
$a202_r = OutboundHttp::request( 'https://rebind.example/hook', array( 'method' => 'POST', 'body' => '{}' ) );
check( 'rebinding: the next request sees the loopback answer and is refused before sending', 'wpcap_http_host_blocked' === $a202_code( $a202_r ) && array() === http_requests( 'rebind.example' ) );
remove_filter( 'wpcap_outbound_resolve', $a202_rebinder, 20 );
$GLOBALS['wpcap_test_filters']['wpcap_outbound_can_pin'] = false;
http_reset();
$a202_r = OutboundHttp::request( 'https://public.example/x' );
check( 'without cURL (no pinning) the request is refused', 'wpcap_http_unpinned' === $a202_code( $a202_r ) && array() === http_requests( 'public.example' ) );
$GLOBALS['wpcap_test_filters']['wpcap_outbound_allow_unpinned'] = true;
http_push( array(), 200, 'public.example' );
check( 'wpcap_outbound_allow_unpinned lets it through (explicit opt-out)', ! is_wp_error( OutboundHttp::request( 'https://public.example/x' ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_outbound_can_pin'], $GLOBALS['wpcap_test_filters']['wpcap_outbound_allow_unpinned'] );

echo "A202: redirects, size limits, pre_http_request stubs\n";
$a202_pre = static function ( $pre, $args, $url ) {
	$routes = array(
		'https://public.example/redir-meta' => array( 302, array( 'location' => 'https://169.254.169.254/latest/meta-data/' ), '' ),
		'https://public.example/redir-ok' => array( 303, array( 'location' => 'https://other.example/landing' ), '' ),
		'https://other.example/landing' => array( 200, array(), 'landed:' . ( $args['method'] ?? '' ) . ':' . ( isset( $args['headers']['Authorization'] ) ? 'auth' : 'noauth' ) . ':' . ( $args['body'] ?? '' ) ),
		'https://public.example/big' => array( 200, array(), str_repeat( 'x', 4097 ) ),
		'https://public.example/fail' => new WP_Error( 'http_request_failed', 'cURL error 7: connect to https://u:p@public.example/fail?api_key=SECRET123 failed, Authorization: Bearer tok_ABCDEF123' ),
	);
	if ( ! isset( $routes[ $url ] ) ) { return $pre; }
	$r = $routes[ $url ];
	return $r instanceof WP_Error ? $r : array( 'response' => array( 'code' => $r[0], 'message' => '' ), 'headers' => $r[1], 'body' => $r[2] );
};
add_filter( 'pre_http_request', $a202_pre, 10, 3 );
$a202_dns( 'other.example', array( '93.184.216.34' ) );
check( 'redirect without permission is an error (status kept)', 'wpcap_http_redirect' === $a202_code( $a202_r = OutboundHttp::request( 'https://public.example/redir-meta' ) ) && 302 === $a202_r->get_error_data()['status'] );
check( 'allowed redirect to a metadata address is re-validated and refused', 'wpcap_http_host_blocked' === $a202_code( OutboundHttp::request( 'https://public.example/redir-meta', array(), array( 'redirects' => 2 ) ) ) );
$a202_r = OutboundHttp::request( 'https://public.example/redir-ok', array( 'method' => 'POST', 'headers' => array( 'Authorization' => 'Bearer abc' ), 'body' => 'payload' ), array( 'redirects' => 1 ) );
check( 'allowed redirect to another public origin: 303 becomes GET without body, Authorization dropped', ! is_wp_error( $a202_r ) && 'landed:GET:noauth:' === $a202_r['body'] );
check( 'response above the size limit is refused', 'wpcap_http_too_large' === $a202_code( OutboundHttp::request( 'https://public.example/big', array(), array( 'max_bytes' => 4096 ) ) ) && ! is_wp_error( OutboundHttp::request( 'https://public.example/big', array(), array( 'max_bytes' => 8192 ) ) ) );
$a202_r = OutboundHttp::request( 'https://public.example/fail', array( 'headers' => array( 'Authorization' => 'Bearer tok_ABCDEF123', 'X-Api-Key' => 'SECRET123' ) ) );
$a202_msg = is_wp_error( $a202_r ) ? $a202_r->get_error_message() : '';
check( 'transport errors are redacted (header values, Bearer, URL credentials, secret query parameters)', 'wpcap_http_transport' === $a202_code( $a202_r ) && false === strpos( $a202_msg, 'SECRET123' ) && false === strpos( $a202_msg, 'tok_ABCDEF123' ) && false === strpos( $a202_msg, 'u:p@' ) && false !== strpos( $a202_msg, 'cURL error 7' ) );
check( 'redact(): Basic credentials, signature parameters, headers for logs', 'Authorization: [redacted]' === OutboundHttp::redact( 'Authorization: Basic dXNlcjpwdw==' ) && false === strpos( OutboundHttp::redact( 'https://x.example/?sig=abc123&a=1' ), 'abc123' ) && array( 'Authorization' => '[redacted]', 'X-Signature' => '[redacted]', 'Accept' => 'application/json' ) === OutboundHttp::redact_headers( array( 'Authorization' => 'Bearer x', 'X-Signature' => 't=1', 'Accept' => 'application/json' ) ) );
remove_filter( 'pre_http_request', $a202_pre );

echo "A202: callers – webhooks\n";
$a202_sub = PassCaptain\Webhooks\Dispatcher::create_subscription( 'https://hooks-a202.example/in', array( 'order.paid' ) );
check( 'webhook URL form check refuses metadata, IPv6 loopback and numeric hosts', ! PassCaptain\Webhooks\Dispatcher::valid_url( 'https://169.254.169.254/latest' ) && ! PassCaptain\Webhooks\Dispatcher::valid_url( 'https://[::1]/x' ) && ! PassCaptain\Webhooks\Dispatcher::valid_url( 'https://2130706433/x' ) && PassCaptain\Webhooks\Dispatcher::valid_url( 'https://hooks-a202.example/in' ) );
$wpdb->query( "UPDATE {$p}wpcap_webhook_deliveries SET status='cancelled' WHERE status='pending'" );
$a202_dns( 'hooks-a202.example', array( '10.0.0.5' ) );
PassCaptain\Webhooks\Dispatcher::emit( 'order.paid', 'a202-wh-1', array( 'object' => 'order', 'id' => 'a202' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_webhook_deliveries SET next_attempt_at=%s WHERE status='pending'", gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
http_reset();
PassCaptain\Webhooks\Dispatcher::process();
$a202_d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d ORDER BY delivery_id DESC LIMIT 1", $a202_sub['subscription_id'] ) );
check( 'webhook whose host now resolves to a private address (DNS rebinding after creation) is not sent', $a202_d && 'pending' === $a202_d->status && 0 === (int) $a202_d->response_code && array() === http_requests( 'hooks-a202' ) && false !== strpos( $a202_d->last_error, 'abgelehnt' ) );
$a202_dns( 'hooks-a202.example', array( '93.184.216.34' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_webhook_deliveries SET next_attempt_at=%s WHERE status='pending'", gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
http_push( array(), 200, 'hooks-a202' );
PassCaptain\Webhooks\Dispatcher::process();
$a202_req = http_requests( 'hooks-a202' )[0] ?? array( 'args' => array() );
check( 'webhook delivery goes through the guard: pinned, no redirects, 64 KB, 10 s', array( 'hooks-a202.example:443:93.184.216.34' ) === ( $a202_req['args'][ OutboundHttp::PIN_ARG ] ?? null ) && 0 === $a202_req['args']['redirection'] && 65537 === $a202_req['args']['limit_response_size'] && 10 === $a202_req['args']['timeout'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_webhook_subscriptions SET status='disabled' WHERE subscription_id=%d", $a202_sub['subscription_id'] ) );

echo "A202: callers – fiscal providers\n";
http_reset();
check( 'fiscal: plain HTTP endpoint is refused (no request sent)', 'provider_endpoint_blocked' === $a202_code( A202Fiscal::request( 'http://fiscal-a202.example/v2/sign', 'POST', array(), array( 'a' => 1 ) ) ) && array() === http_requests( 'fiscal-a202' ) );
$a202_dns( 'fiscal-a202.example', array( '169.254.169.254' ) );
check( 'fiscal: configured base_url resolving to the metadata address is refused', 'provider_endpoint_blocked' === $a202_code( A202Fiscal::request( 'https://fiscal-a202.example/v2/sign', 'POST' ) ) && array() === http_requests( 'fiscal-a202' ) );
$a202_dns( 'fiscal-a202.example', array( '93.184.216.34' ) );
http_push( array( 'ok' => true ), 200, 'fiscal-a202' );
$a202_r = A202Fiscal::request( 'https://fiscal-a202.example/auth/token', 'POST', array( 'Content-Type' => 'application/x-www-form-urlencoded', 'Authorization' => 'Basic eDp5' ), 'grant_type=client_credentials&TaxId=1' );
$a202_req = http_requests( 'fiscal-a202' )[0] ?? array( 'args' => array(), 'headers' => array(), 'body' => '' );
check( 'fiscal: public HTTPS endpoint is pinned; form bodies are sent as given with their content type', ! is_wp_error( $a202_r ) && 'grant_type=client_credentials&TaxId=1' === $a202_req['body'] && 'application/x-www-form-urlencoded' === $a202_req['headers']['Content-Type'] && array( 'fiscal-a202.example:443:93.184.216.34' ) === ( $a202_req['args'][ OutboundHttp::PIN_ARG ] ?? null ) && A202Fiscal::MAX_BYTES + 1 === $a202_req['args']['limit_response_size'] );
http_push( array( 'Result' => 'ok' ), 200, '127.0.0.1:5618' );
$a202_r = A202Fiscal::request( 'http://127.0.0.1:5618/state', 'GET', array(), null, array( 'exceptions' => array( 'http://127.0.0.1:5618' ) ) );
check( 'fiscal: the fixed local efsta EFR origin is the only plain-HTTP exception', ! is_wp_error( $a202_r ) && 'provider_endpoint_blocked' === $a202_code( A202Fiscal::request( 'http://127.0.0.1:8080/state', 'GET', array(), null, array( 'exceptions' => array( 'http://127.0.0.1:5618' ) ) ) ) );
$a202_efsta = file_get_contents( WPCAP_PATH . 'src/Fiscalization/Providers/EfstaEfrAdapter.php' );
check( 'efsta adapter sends every call through HttpClient with only its LOCAL_EFR exception', "const LOCAL_EFR = 'http://127.0.0.1:5618';" === ( preg_match( "/const LOCAL_EFR = '[^']+';/", $a202_efsta, $m ) ? $m[0] : '' ) && 1 === substr_count( $a202_efsta, 'return HttpClient::request(' ) && 4 === substr_count( $a202_efsta, 'self::http(' ) );
http_push( array( 'x' => str_repeat( 'y', A202Fiscal::MAX_BYTES ) ), 200, 'fiscal-a202' );
check( 'fiscal: oversized response is refused', 'provider_response_too_large' === $a202_code( A202Fiscal::request( 'https://fiscal-a202.example/journal', 'GET' ) ) );

echo "A202: callers – SMS\n";
$a202_sms = (array) Settings::get( 'sms' );
Settings::update( array( 'sms_http_secret' => 'Bearer a202-s3cret', 'sms' => array_merge( $a202_sms, array( 'provider' => 'http', 'http_endpoint' => 'https://sms-a202.example/send', 'http_auth_header' => 'Authorization' ) ) ) );
$a202_dns( 'sms-a202.example', array( '192.168.0.10' ) );
http_reset();
$a202_r = ( new PassCaptain\Sms\HttpProvider() )->send( '+4915100000009', 'Hallo', 'a202-sms-1' );
check( 'SMS HTTP: endpoint resolving to a private address is refused permanently, nothing sent', 'wpcap_sms_http' === $a202_code( $a202_r ) && ! empty( $a202_r->get_error_data()['permanent'] ) && array() === http_requests( 'sms-a202' ) );
$a202_dns( 'sms-a202.example', array( '93.184.216.34' ) );
add_filter( 'pre_http_request', $a202_smsfail = static function ( $pre, $args, $url ) { return false !== strpos( $url, 'sms-a202' ) ? new WP_Error( 'http_request_failed', 'gateway said: header Authorization=Bearer a202-s3cret rejected' ) : $pre; }, 10, 3 );
$a202_r = ( new PassCaptain\Sms\HttpProvider() )->send( '+4915100000009', 'Hallo', 'a202-sms-2' );
remove_filter( 'pre_http_request', $a202_smsfail );
$a202_req = http_requests( 'sms-a202' )[0] ?? array( 'args' => array() );
check( 'SMS HTTP: pinned request; the auth secret never appears in the (retryable) error', 'wpcap_sms_http' === $a202_code( $a202_r ) && false === strpos( $a202_r->get_error_message(), 'a202-s3cret' ) && empty( $a202_r->get_error_data()['permanent'] ) && array( 'sms-a202.example:443:93.184.216.34' ) === ( $a202_req['args'][ OutboundHttp::PIN_ARG ] ?? null ) );
Settings::update( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_auth_token' => 'a202-twilio-token', 'sms' => array_merge( $a202_sms, array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000' ) ) ) );
$a202_dns( 'api.twilio.com', array( '127.0.0.1' ) );
http_reset();
$a202_r = ( new PassCaptain\Sms\TwilioProvider() )->send( '+4915100000009', 'Hallo', 'a202-tw-1' );
check( 'Twilio: fixed host still checked after DNS (loopback answer refused)', 'wpcap_sms_http' === $a202_code( $a202_r ) && array() === http_requests( 'twilio' ) );
unset( $GLOBALS['wpcap_test_dns']['api.twilio.com'] );
http_push( array( 'sid' => 'SM1' ), 201, 'api.twilio.com' );
$a202_r = ( new PassCaptain\Sms\TwilioProvider() )->send( '+4915100000009', 'Hallo', 'a202-tw-2' );
$a202_req = http_requests( 'api.twilio.com' )[0] ?? array( 'args' => array() );
check( 'Twilio: request pinned, POST, no redirects', ! is_wp_error( $a202_r ) && 'POST' === $a202_req['method'] && 0 === $a202_req['args']['redirection'] && ! empty( $a202_req['args'][ OutboundHttp::PIN_ARG ] ) );
Settings::update( array( 'sms_http_secret' => '', 'twilio_account_sid' => '', 'twilio_auth_token' => '', 'sms' => array_merge( $a202_sms, array( 'provider' => '' ) ) ) );

echo "A202: callers – Wallet\n";
class A202_Apns extends PassCaptain\WalletPasses\ApnsClient {
	public array $calls = array();
	protected function request( $url, array $headers, $body, array $credentials ) { $this->calls[] = array( $url, $this->pin ); return array( 'status' => 200, 'body' => '' ); }
}
$a202_apns = new A202_Apns();
check( 'APNs: host other than Apple production/sandbox is refused before cURL', 0 === $a202_apns->send( 'tok', 'pass.x', array(), 'https://evil-a202.example' ) && array() === $a202_apns->calls );
check( 'APNs: Apple host is pinned to the checked address (CURLOPT_RESOLVE)', 200 === $a202_apns->send( 'tok', 'pass.x', array(), 'https://api.sandbox.push.apple.com' ) && array( 'api.sandbox.push.apple.com:443:93.184.216.34' ) === ( $a202_apns->calls[0][1] ?? null ) );
$a202_apns_src = file_get_contents( WPCAP_PATH . 'src/WalletPasses/ApnsClient.php' );
check( 'APNs cURL options: pin, HTTPS only, no redirects, connect timeout, size abort', false !== strpos( $a202_apns_src, 'CURLOPT_RESOLVE ] = $this->pin' ) && false !== strpos( $a202_apns_src, 'CURLOPT_FOLLOWLOCATION => false' ) && false !== strpos( $a202_apns_src, 'CURLPROTO_HTTPS' ) && false !== strpos( $a202_apns_src, 'CURLOPT_XFERINFOFUNCTION' ) );
check( 'Google Wallet: token_uri of a service account may only point to Google token hosts', 'wpcap_http_host_not_allowed' === $a202_code( OutboundHttp::check( 'https://evil-a202.example/token', array( 'hosts' => PassCaptain\WalletPasses\GoogleProvider::TOKEN_HOSTS ) ) ) );
$a202_sa = PassCaptain\Secrets::get( 'google_wallet_service_account' );
$a202_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $a202_key, $a202_pem );
PassCaptain\Secrets::set( 'google_wallet_service_account', json_encode( array( 'client_email' => 'a202@test.iam.gserviceaccount.com', 'private_key' => $a202_pem, 'token_uri' => 'https://evil-a202.example/token' ) ) );
delete_transient( 'wpcap_wallet_google_token' );
http_reset();
$a202_tok = ( new PassCaptain\WalletPasses\GoogleProvider() )->access_token();
check( 'Google Wallet: a service account with a foreign token_uri sends nothing (signed assertion stays local)', ! is_wp_error( PassCaptain\WalletPasses\Credentials::google() ) && '' === $a202_tok && array() === http_requests( 'evil-a202' ) );
PassCaptain\Secrets::set( 'google_wallet_service_account', json_encode( array( 'client_email' => 'a202@test.iam.gserviceaccount.com', 'private_key' => $a202_pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ) ) );
http_push( array( 'access_token' => 'ya29.a202', 'expires_in' => 3600 ), 200, 'oauth2.googleapis.com' );
http_push( array( 'id' => 'x' ), 200, 'walletobjects.googleapis.com' );
$a202_g = ( new PassCaptain\WalletPasses\GoogleProvider() )->api( 'GET', 'eventTicketClass/a202', array() );
$a202_req = http_requests( 'walletobjects.googleapis.com' )[0] ?? array( 'args' => array() );
check( 'Google Wallet: token and API calls go through the guard (pinned)', 200 === $a202_g['code'] && ! empty( ( http_requests( 'oauth2.googleapis.com' )[0]['args'][ OutboundHttp::PIN_ARG ] ?? null ) ) && ! empty( $a202_req['args'][ OutboundHttp::PIN_ARG ] ) );
delete_transient( 'wpcap_wallet_google_token' );
PassCaptain\Secrets::set( 'google_wallet_service_account', (string) $a202_sa );

echo "A202: callers – payment providers (fixed hosts)\n";
Settings::update( array( 'stripe_secret_key' => 'sk_test_a202', 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'a202-client', 'paypal_client_secret' => 'a202-secret', 'mollie_api_key' => 'test_a202a202a202a202a202a202a202a2' ) );
$a202_stripe = new ReflectionMethod( PassCaptain\Payments\StripeGateway::class, 'api' );
$a202_stripe->setAccessible( true );
$a202_stripe_obj = ( new ReflectionClass( PassCaptain\Payments\StripeGateway::class ) )->newInstanceWithoutConstructor();
http_reset();
http_push( array( 'object' => 'balance' ), 200, 'api.stripe.com' );
$a202_r = $a202_stripe->invoke( $a202_stripe_obj, 'GET', 'balance' );
$a202_req = http_requests( 'api.stripe.com' )[0] ?? array( 'args' => array() );
check( 'Stripe: through the guard (pinned, no redirects, size limit)', is_array( $a202_r ) && array( 'api.stripe.com:443:93.184.216.34' ) === ( $a202_req['args'][ OutboundHttp::PIN_ARG ] ?? null ) && 0 === $a202_req['args']['redirection'] && isset( $a202_req['args']['limit_response_size'] ) );
$a202_dns( 'api.stripe.com', array( '10.9.9.9' ) );
http_reset();
$a202_r = $a202_stripe->invoke( $a202_stripe_obj, 'GET', 'balance' );
check( 'Stripe: a private DNS answer for the fixed host is refused, the secret key is not in the error', 'wpcap_stripe_http' === $a202_code( $a202_r ) && array() === http_requests( 'api.stripe.com' ) && false === strpos( $a202_r->get_error_message(), 'sk_test_a202' ) );
unset( $GLOBALS['wpcap_test_dns']['api.stripe.com'] );
$a202_pp = new ReflectionMethod( PassCaptain\Payments\PayPalGateway::class, 'token' );
$a202_pp->setAccessible( true );
delete_transient( 'wpcap_paypal_token_' . substr( hash( 'sha256', PassCaptain\Payments\PayPalGateway::SANDBOX . '|a202-client|a202-secret' ), 0, 20 ) );
http_reset();
http_push( array( 'access_token' => 'A21', 'expires_in' => 3600 ), 200, 'api-m.sandbox.paypal.com' );
$a202_r = $a202_pp->invoke( null, true );
$a202_req = http_requests( 'api-m.sandbox.paypal.com' )[0] ?? array( 'args' => array() );
check( 'PayPal: OAuth token request through the guard (pinned, POST)', 'A21' === $a202_r && 'POST' === $a202_req['method'] && ! empty( $a202_req['args'][ OutboundHttp::PIN_ARG ] ) );
$a202_mo = new ReflectionMethod( PassCaptain\Mollie\MollieGateway::class, 'api' );
$a202_mo->setAccessible( true );
http_reset();
http_push( array( 'resource' => 'payment' ), 200, 'api.mollie.com' );
$a202_r = $a202_mo->invoke( null, 'GET', 'payments/tr_a202' );
$a202_req = http_requests( 'api.mollie.com' )[0] ?? array( 'args' => array() );
check( 'Mollie: through the guard (pinned)', is_array( $a202_r ) && ! empty( $a202_req['args'][ OutboundHttp::PIN_ARG ] ) );
Settings::update( array( 'stripe_secret_key' => '', 'paypal_client_id' => '', 'paypal_client_secret' => '', 'mollie_api_key' => '' ) );

$a202_src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $a202_file ) {
	if ( '.php' !== substr( (string) $a202_file, -4 ) || false !== strpos( (string) $a202_file, 'OutboundHttp.php' ) ) { continue; }
	if ( preg_match_all( '/\b(wp_remote_(?:request|get|post|head)|wp_safe_remote_\w+|curl_init|fsockopen|stream_socket_client)\s*\(/', file_get_contents( $a202_file ), $m ) ) {
		foreach ( $m[1] as $call ) { $a202_src .= basename( (string) $a202_file ) . ':' . $call . ' '; }
	}
}
check( 'no outbound call in src/ bypasses the guard (only the guarded APNs cURL client)' . ( 'ApnsClient.php:curl_init ' !== $a202_src ? ' – ' . $a202_src : '' ), 'ApnsClient.php:curl_init ' === $a202_src );
unset( $GLOBALS['wpcap_test_dns'] );
