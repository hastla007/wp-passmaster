<?php
/**
 * Apple Push Notification service client for Wallet pass updates (HTTP/2, certificate auth with the
 * Pass Type ID certificate, topic = pass type identifier, empty JSON payload).
 *
 * Replace via the filter `wpcap_wallet_apns_client` (tests override request()).
 *
 * One cURL handle is kept per client and reused for every push of a dispatch run (Service::process()
 * keeps one client per run), so the TLS handshake happens once and HTTP/2 reuses the connection;
 * close() ends it.
 */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ApnsClient {
	/** The only APNs hosts (production, sandbox); the Pass Type certificate is never presented to another host. */
	const HOSTS = array( 'api.push.apple.com', 'api.sandbox.push.apple.com' );
	/** Response size limit (APNs answers with a small JSON reason). */
	const MAX_BYTES = 65536;

	/** CURLOPT_RESOLVE entries of the checked target (OutboundHttp), set by send(). */
	protected $pin = array();

	/** @var \CurlHandle|resource|null Reused cURL handle. */
	private $handle = null;
	/** @var string Fingerprint of the credentials, pin and proxy the handle was set up with. */
	private $handle_key = '';
	/** @var string[] Temporary key files of the handle (cURL without blob support). */
	private $temp = array();
	private $temp_dir = '';

	/** @return int HTTP status (0 = transport error). */
	public function send( $push_token, $topic, array $credentials, $host = 'https://api.push.apple.com' ) {
		$host = rtrim( (string) $host, '/' );
		if ( ! in_array( (string) preg_replace( '#^https://#', '', $host ), self::HOSTS, true ) ) { $host = 'https://' . self::HOSTS[0]; } // Never an arbitrary (internal) host.
		$url = $host . '/3/device/' . rawurlencode( $push_token );
		// Raw cURL (HTTP/2 with client certificate) still goes through the outbound guard (A202):
		// fixed Apple hosts, HTTPS, public address after DNS, pinned below.
		$target = \PassCaptain\OutboundHttp::check( $url, array( 'context' => 'wallet_apns', 'hosts' => self::HOSTS ) );
		if ( is_wp_error( $target ) ) { return 0; }
		// WP_HTTP_BLOCK_EXTERNAL / WP_ACCESSIBLE_HOSTS apply to this raw cURL call as well (A361).
		if ( self::blocked( $url ) ) { return 0; }
		$this->pin = $target['pin'];
		$result = $this->request( $url, array( 'apns-topic: ' . $topic, 'apns-priority: 10', 'content-type: application/json' ), '{}', $credentials );
		return (int) ( $result['status'] ?? 0 );
	}

	/**
	 * Whether WordPress blocks this request (WP_HTTP_BLOCK_EXTERNAL without the host in
	 * WP_ACCESSIBLE_HOSTS), decided by WP_Http::block_request() like for every wp_remote_*() call (A361).
	 *
	 * @param string $url Request URL.
	 * @return bool
	 */
	public static function blocked( $url ) {
		return class_exists( '\WP_Http' ) && ( new \WP_Http() )->block_request( $url );
	}

	/**
	 * cURL options for the HTTP proxy configured with WP_PROXY_HOST/PORT/USERNAME/PASSWORD
	 * (WP_PROXY_BYPASS_HOSTS respected via WP_HTTP_Proxy::send_through_proxy()), as WordPress' own
	 * cURL transport sets them (A361). Empty without a proxy.
	 *
	 * @param string $url Request URL.
	 * @return array<int,mixed>
	 */
	public static function proxy_options( $url ) {
		if ( ! class_exists( '\WP_HTTP_Proxy' ) ) { return array(); }
		$proxy = new \WP_HTTP_Proxy();
		if ( ! $proxy->is_enabled() || ! $proxy->send_through_proxy( $url ) ) { return array(); }
		$options = array(
			CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
			CURLOPT_PROXY => $proxy->host(),
			CURLOPT_PROXYPORT => (int) $proxy->port(),
			// HTTPS through the proxy as a CONNECT tunnel: the client certificate stays end-to-end.
			CURLOPT_HTTPPROXYTUNNEL => true,
		);
		if ( $proxy->use_authentication() ) {
			$options[ CURLOPT_PROXYAUTH ] = CURLAUTH_ANY;
			$options[ CURLOPT_PROXYUSERPWD ] = $proxy->authentication();
		}
		return $options;
	}

	/**
	 * Performs the HTTP/2 POST on the shared handle. Overridable seam.
	 *
	 * @return array{status:int,body:string}
	 */
	protected function request( $url, array $headers, $body, array $credentials ) {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURL_HTTP_VERSION_2_0' ) ) { return array( 'status' => 0, 'body' => 'curl_http2_missing' ); }
		// Direct cURL on purpose: APNs only speaks HTTP/2 and authenticates with a client certificate held
		// in memory; the WordPress HTTP API (Requests) supports neither HTTP/2 nor certificate blobs.
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$error = $this->open( $url, $credentials );
		if ( '' !== $error ) { return array( 'status' => 0, 'body' => $error ); }
		curl_setopt_array( $this->handle, array( CURLOPT_URL => $url, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers ) );
		$response = curl_exec( $this->handle );
		$status = (int) curl_getinfo( $this->handle, CURLINFO_RESPONSE_CODE );
		if ( false === $response ) { $this->close(); } // A broken connection is not reused.
		// phpcs:enable
		return array( 'status' => false === $response ? 0 : $status, 'body' => is_string( $response ) ? $response : '' );
	}

	/** Sets up the handle for these credentials, pinned address and proxy (once per run); returns '' or an error code. */
	private function open( $url, array $credentials ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		$proxy = self::proxy_options( $url );
		$key = hash( 'sha256', $credentials['cert'] . '|' . $credentials['key'] . '|' . (string) ( $credentials['password'] ?? '' ) . '|' . wp_json_encode( $this->pin ) . '|' . wp_json_encode( $proxy ) );
		if ( null !== $this->handle && $key === $this->handle_key ) { return ''; }
		$this->close();
		$handle = curl_init();
		$options = array(
			CURLOPT_POST => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_NOPROGRESS => false,
			// Abort responses above MAX_BYTES.
			CURLOPT_XFERINFOFUNCTION => static function ( $ch, $download_total, $downloaded ) { return $downloaded > self::MAX_BYTES ? 1 : 0; },
			CURLOPT_SSLCERTTYPE => 'PEM',
			CURLOPT_SSLKEYTYPE => 'PEM',
		);
		if ( defined( 'CURLOPT_SSLCERT_BLOB' ) && defined( 'CURLOPT_SSLKEY_BLOB' ) ) {
			$options[ CURLOPT_SSLCERT_BLOB ] = $credentials['cert'];
			$options[ CURLOPT_SSLKEY_BLOB ] = $credentials['key'];
		} else {
			// Older cURL without blob support: key material in a private directory (0700), files 0600,
			// kept until close().
			$this->temp_dir = (string) \PassCaptain\TempFiles::private_dir();
			if ( '' === $this->temp_dir ) { unset( $handle ); return 'temp_dir_failed'; }
			foreach ( array( 'cert' => CURLOPT_SSLCERT, 'key' => CURLOPT_SSLKEY ) as $part => $option ) {
				$file = $this->temp_dir . '/' . $part . '.pem';
				$this->temp[] = $file;
				if ( ! \PassCaptain\TempFiles::put( $file, $credentials[ $part ] ) ) {
					unset( $handle );
					$this->remove_temp();
					return 'temp_file_failed';
				}
				$options[ $option ] = $file;
			}
		}
		if ( '' !== (string) ( $credentials['password'] ?? '' ) ) { $options[ CURLOPT_KEYPASSWD ] = $credentials['password']; }
		if ( $this->pin ) { $options[ CURLOPT_RESOLVE ] = $this->pin; }
		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) { $options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS; }
		curl_setopt_array( $handle, $proxy + $options );
		$this->handle = $handle;
		$this->handle_key = $key;
		// phpcs:enable
		return '';
	}

	/**
	 * Closes the connection and removes temporary key files. Idempotent. No curl_close(): a no-op since
	 * PHP 8.0 and deprecated in 8.5 (A361); dropping the handle frees it.
	 */
	public function close() {
		$this->handle = null;
		$this->handle_key = '';
		$this->remove_temp();
	}

	private function remove_temp() {
		foreach ( $this->temp as $file ) { \PassCaptain\TempFiles::remove( $file ); }
		$this->temp = array();
		if ( '' !== $this->temp_dir ) { \PassCaptain\TempFiles::remove_dir( $this->temp_dir ); }
		$this->temp_dir = '';
	}

	public function __destruct() {
		$this->close();
	}
}
