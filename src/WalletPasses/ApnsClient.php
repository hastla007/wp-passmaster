<?php
/**
 * Apple Push Notification service client for Wallet pass updates (HTTP/2, certificate auth with the
 * Pass Type ID certificate, topic = pass type identifier, empty JSON payload).
 *
 * Replace via the filter `wpcap_wallet_apns_client` (tests override request()).
 */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ApnsClient {
	/** @return int HTTP status (0 = transport error). */
	/** The only APNs hosts (production, sandbox). */
	const HOSTS = array( 'api.push.apple.com', 'api.sandbox.push.apple.com' );
	/** Response size limit (APNs answers with a small JSON reason). */
	const MAX_BYTES = 65536;

	/** CURLOPT_RESOLVE entries of the checked target (OutboundHttp), set by send(). */
	protected $pin = array();

	public function send( $push_token, $topic, array $credentials, $host = 'https://api.push.apple.com' ) {
		$url = rtrim( $host, '/' ) . '/3/device/' . rawurlencode( $push_token );
		// Raw cURL (HTTP/2 with client certificate) still goes through the outbound guard (A202):
		// fixed Apple hosts, HTTPS, public address after DNS, pinned below.
		$target = \PassCaptain\OutboundHttp::check( $url, array( 'context' => 'wallet_apns', 'hosts' => self::HOSTS ) );
		if ( is_wp_error( $target ) ) { return 0; }
		$this->pin = $target['pin'];
		$result = $this->request( $url, array( 'apns-topic: ' . $topic, 'apns-priority: 10', 'content-type: application/json' ), '{}', $credentials );
		return (int) ( $result['status'] ?? 0 );
	}

	/**
	 * Performs the HTTP/2 POST. Overridable seam.
	 *
	 * @return array{status:int,body:string}
	 */
	protected function request( $url, array $headers, $body, array $credentials ) {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURL_HTTP_VERSION_2_0' ) ) { return array( 'status' => 0, 'body' => 'curl_http2_missing' ); }
		$temp = array();
		$ch = curl_init( $url );
		$options = array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
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
			foreach ( array( 'cert' => CURLOPT_SSLCERT, 'key' => CURLOPT_SSLKEY ) as $part => $option ) {
				$file = tempnam( get_temp_dir(), 'wpcap-apns' );
				chmod( $file, 0600 );
				file_put_contents( $file, $credentials[ $part ] );
				$temp[] = $file;
				$options[ $option ] = $file;
			}
		}
		if ( '' !== (string) ( $credentials['password'] ?? '' ) ) { $options[ CURLOPT_KEYPASSWD ] = $credentials['password']; }
		if ( $this->pin ) { $options[ CURLOPT_RESOLVE ] = $this->pin; }
		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) { $options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS; }
		curl_setopt_array( $ch, $options );
		$response = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		foreach ( $temp as $file ) { wp_delete_file( $file ); }
		return array( 'status' => false === $response ? 0 : $status, 'body' => is_string( $response ) ? $response : '' );
	}
}
