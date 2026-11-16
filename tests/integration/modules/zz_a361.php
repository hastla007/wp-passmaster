<?php
/**
 * A361: the APNs cURL client follows WordPress' HTTP settings (WP_HTTP_BLOCK_EXTERNAL via
 * WP_Http::block_request(), WP_PROXY_* via WP_HTTP_Proxy) and no longer calls curl_close() (PHP 8.5).
 * WP_Http/WP_HTTP_Proxy are stubbed here with the behaviour of the WordPress classes.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\WalletPasses\ApnsClient;

echo "A361: APNs client respects WP HTTP block/proxy settings\n";

if ( ! class_exists( 'WP_Http' ) ) {
	class WP_Http {
		public function block_request( $uri ) {
			$cfg = $GLOBALS['x2_a361_http'] ?? array();
			if ( empty( $cfg['block_external'] ) ) { return false; }
			$host = (string) parse_url( $uri, PHP_URL_HOST );
			return ! in_array( $host, (array) ( $cfg['accessible'] ?? array() ), true );
		}
	}
}
if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
	class WP_HTTP_Proxy {
		private function cfg() { return $GLOBALS['x2_a361_proxy'] ?? array(); }
		public function is_enabled() { return '' !== (string) ( $this->cfg()['host'] ?? '' ); }
		public function use_authentication() { return '' !== (string) ( $this->cfg()['user'] ?? '' ); }
		public function host() { return (string) ( $this->cfg()['host'] ?? '' ); }
		public function port() { return (string) ( $this->cfg()['port'] ?? '' ); }
		public function authentication() { return $this->cfg()['user'] . ':' . $this->cfg()['pass']; }
		public function send_through_proxy( $uri ) { return ! in_array( (string) parse_url( $uri, PHP_URL_HOST ), (array) ( $this->cfg()['bypass'] ?? array() ), true ); }
	}
}

class X2_A361_Apns extends ApnsClient {
	public array $calls = array();
	protected function request( $url, array $headers, $body, array $credentials ) { $this->calls[] = $url; return array( 'status' => 200, 'body' => '' ); }
}

$GLOBALS['x2_a361_http'] = array( 'block_external' => true, 'accessible' => array() );
$x2_a361 = new X2_A361_Apns();
check( 'A361: WP_HTTP_BLOCK_EXTERNAL stops the APNs push before cURL', 0 === $x2_a361->send( 'tok', 'pass.x', array(), 'https://api.sandbox.push.apple.com' ) && array() === $x2_a361->calls );
$GLOBALS['x2_a361_http'] = array( 'block_external' => true, 'accessible' => array( 'api.sandbox.push.apple.com' ) );
check( 'A361: a host listed in WP_ACCESSIBLE_HOSTS is still pushed to', 200 === $x2_a361->send( 'tok', 'pass.x', array(), 'https://api.sandbox.push.apple.com' ) && 1 === count( $x2_a361->calls ) );
$GLOBALS['x2_a361_http'] = array();

check( 'A361: no proxy configured – no proxy options', array() === ApnsClient::proxy_options( 'https://api.push.apple.com/3/device/x' ) );
$GLOBALS['x2_a361_proxy'] = array( 'host' => 'proxy.internal', 'port' => '3128', 'user' => 'u', 'pass' => 'p' );
$x2_a361_opts = ApnsClient::proxy_options( 'https://api.push.apple.com/3/device/x' );
check( 'A361: WP_PROXY_HOST/PORT/USERNAME/PASSWORD become cURL proxy options (CONNECT tunnel)', 'proxy.internal' === ( $x2_a361_opts[ CURLOPT_PROXY ] ?? null ) && 3128 === ( $x2_a361_opts[ CURLOPT_PROXYPORT ] ?? null ) && 'u:p' === ( $x2_a361_opts[ CURLOPT_PROXYUSERPWD ] ?? null ) && true === ( $x2_a361_opts[ CURLOPT_HTTPPROXYTUNNEL ] ?? null ) );
$GLOBALS['x2_a361_proxy']['bypass'] = array( 'api.push.apple.com' );
check( 'A361: WP_PROXY_BYPASS_HOSTS is respected', array() === ApnsClient::proxy_options( 'https://api.push.apple.com/3/device/x' ) );
unset( $GLOBALS['x2_a361_proxy'] );

$x2_a361_src = file_get_contents( WPCAP_PATH . 'src/WalletPasses/ApnsClient.php' );
check( 'A361: no curl_close() call (deprecated in PHP 8.5), proxy options applied to the handle', ! preg_match( '/curl_close\s*\(\s*\$/', $x2_a361_src ) && false !== strpos( $x2_a361_src, 'self::proxy_options( $url ) + $options' ) );
