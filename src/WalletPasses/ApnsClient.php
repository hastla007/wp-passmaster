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
	public function send( $push_token, $topic, array $credentials, $host = 'https://api.push.apple.com' ) {
		$url = rtrim( $host, '/' ) . '/3/device/' . rawurlencode( $push_token );
		$result = $this->request( $url, array( 'apns-topic: ' . $topic, 'apns-priority: 10', 'content-type: application/json' ), '{}', $credentials );
		return (int) ( $result['status'] ?? 0 );
	}

	/**
	 * Performs the HTTP/2 POST. Overridable seam.
	 *
	 * @return array{status:int,body:string}
	 */
	protected function request( $url, array $headers, $body, array $credentials ) {
		// Direct cURL on purpose: APNs only speaks HTTP/2 and authenticates with a client certificate held
		// in memory; the WordPress HTTP API (Requests) supports neither HTTP/2 nor certificate blobs.
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURL_HTTP_VERSION_2_0' ) ) { return array( 'status' => 0, 'body' => 'curl_http2_missing' ); }
		$temp = array();
		$temp_dir = '';
		$ch = curl_init( $url );
		$options = array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_SSLCERTTYPE => 'PEM',
			CURLOPT_SSLKEYTYPE => 'PEM',
		);
		if ( defined( 'CURLOPT_SSLCERT_BLOB' ) && defined( 'CURLOPT_SSLKEY_BLOB' ) ) {
			$options[ CURLOPT_SSLCERT_BLOB ] = $credentials['cert'];
			$options[ CURLOPT_SSLKEY_BLOB ] = $credentials['key'];
		} else {
			// Older cURL without blob support: key material in a private directory (0700), files 0600.
			$temp_dir = (string) \PassCaptain\TempFiles::private_dir();
			if ( '' === $temp_dir ) { curl_close( $ch ); return array( 'status' => 0, 'body' => 'temp_dir_failed' ); }
			foreach ( array( 'cert' => CURLOPT_SSLCERT, 'key' => CURLOPT_SSLKEY ) as $part => $option ) {
				$file = $temp_dir . '/' . $part . '.pem';
				$temp[] = $file;
				if ( ! \PassCaptain\TempFiles::put( $file, $credentials[ $part ] ) ) {
					curl_close( $ch );
					foreach ( $temp as $written ) { \PassCaptain\TempFiles::remove( $written ); }
					\PassCaptain\TempFiles::remove_dir( $temp_dir );
					return array( 'status' => 0, 'body' => 'temp_file_failed' );
				}
				$options[ $option ] = $file;
			}
		}
		if ( '' !== (string) ( $credentials['password'] ?? '' ) ) { $options[ CURLOPT_KEYPASSWD ] = $credentials['password']; }
		curl_setopt_array( $ch, $options );
		$response = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		foreach ( $temp as $file ) { \PassCaptain\TempFiles::remove( $file ); }
		if ( '' !== $temp_dir ) { \PassCaptain\TempFiles::remove_dir( $temp_dir ); }
		// phpcs:enable
		return array( 'status' => false === $response ? 0 : $status, 'body' => is_string( $response ) ? $response : '' );
	}
}
