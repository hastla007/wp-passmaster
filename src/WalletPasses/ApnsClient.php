<?php
/**
 * Apple Push Notification service client for Wallet pass updates (HTTP/2, certificate auth with the
 * Pass Type ID certificate, topic = pass type identifier, empty JSON payload).
 *
 * One cURL handle is kept per client and reused for every push of a dispatch run (Service::process()
 * keeps one client per run), so the TLS handshake happens once and HTTP/2 reuses the connection;
 * close() ends it.
 *
 * Replace via the filter `wpcap_wallet_apns_client` (tests override request()).
 */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ApnsClient {
	/** The only APNs hosts the Pass Type certificate is presented to. */
	const HOSTS = array( 'https://api.push.apple.com', 'https://api.sandbox.push.apple.com' );

	/** @var \CurlHandle|null Reused cURL handle. */
	private $handle = null;
	/** @var string Fingerprint of the credentials the handle was set up with. */
	private $handle_key = '';
	/** @var string[] Temporary key files of the handle (cURL without blob support). */
	private $temp = array();
	private $temp_dir = '';

	/** @return int HTTP status (0 = transport error). */
	public function send( $push_token, $topic, array $credentials, $host = 'https://api.push.apple.com' ) {
		$host = rtrim( (string) $host, '/' );
		if ( ! in_array( $host, self::HOSTS, true ) ) { $host = self::HOSTS[0]; } // Never an arbitrary (internal) host.
		$url = $host . '/3/device/' . rawurlencode( $push_token );
		$result = $this->request( $url, array( 'apns-topic: ' . $topic, 'apns-priority: 10', 'content-type: application/json' ), '{}', $credentials );
		return (int) ( $result['status'] ?? 0 );
	}

	/**
	 * Performs the HTTP/2 POST on the shared handle. Overridable seam.
	 *
	 * @return array{status:int,body:string}
	 */
	protected function request( $url, array $headers, $body, array $credentials ) {
		// Direct cURL on purpose: APNs only speaks HTTP/2 and authenticates with a client certificate held
		// in memory; the WordPress HTTP API (Requests) supports neither HTTP/2 nor certificate blobs.
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURL_HTTP_VERSION_2_0' ) ) { return array( 'status' => 0, 'body' => 'curl_http2_missing' ); }
		$error = $this->open( $credentials );
		if ( '' !== $error ) { return array( 'status' => 0, 'body' => $error ); }
		curl_setopt_array( $this->handle, array( CURLOPT_URL => $url, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers ) );
		$response = curl_exec( $this->handle );
		$status = (int) curl_getinfo( $this->handle, CURLINFO_RESPONSE_CODE );
		if ( false === $response ) { $this->close(); } // A broken connection is not reused.
		// phpcs:enable
		return array( 'status' => false === $response ? 0 : $status, 'body' => is_string( $response ) ? $response : '' );
	}

	/** Sets up the handle for these credentials (once per run); returns '' or an error code. */
	private function open( array $credentials ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		$key = hash( 'sha256', $credentials['cert'] . '|' . $credentials['key'] . '|' . (string) ( $credentials['password'] ?? '' ) );
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
			if ( '' === $this->temp_dir ) { curl_close( $handle ); return 'temp_dir_failed'; } // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			foreach ( array( 'cert' => CURLOPT_SSLCERT, 'key' => CURLOPT_SSLKEY ) as $part => $option ) {
				$file = $this->temp_dir . '/' . $part . '.pem';
				$this->temp[] = $file;
				if ( ! \PassCaptain\TempFiles::put( $file, $credentials[ $part ] ) ) {
					curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
					$this->remove_temp();
					return 'temp_file_failed';
				}
				$options[ $option ] = $file;
			}
		}
		if ( '' !== (string) ( $credentials['password'] ?? '' ) ) { $options[ CURLOPT_KEYPASSWD ] = $credentials['password']; }
		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) { $options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS; }
		curl_setopt_array( $handle, $options );
		$this->handle = $handle;
		$this->handle_key = $key;
		// phpcs:enable
		return '';
	}

	/** Closes the connection and removes temporary key files. Idempotent. */
	public function close() {
		if ( null !== $this->handle ) {
			curl_close( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			$this->handle = null;
		}
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
