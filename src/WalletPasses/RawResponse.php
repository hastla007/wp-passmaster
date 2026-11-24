<?php
/**
 * REST response whose body is sent as raw bytes with its own headers (A648): .pkpass files, the
 * .pkpasses bundle and the Google save redirect. Only this class switches on WebService::serve_raw();
 * get_data() keeps the shape ['__wpcap_raw' => bytes, 'headers' => [...]] for callers and tests.
 */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
class RawResponse extends \WP_REST_Response {
	/** @var string */
	private $raw_body;

	/** @var array<string,string> */
	private $raw_header_list = array();

	/**
	 * @param string               $body    Bytes to send.
	 * @param array<string,string> $headers Header name => value (line breaks are removed).
	 * @param int                  $status  HTTP status.
	 */
	public function __construct( $body, array $headers = array(), $status = 200 ) {
		$this->raw_body = (string) $body;
		foreach ( $headers as $name => $value ) {
			$name = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $name );
			if ( '' !== $name ) { $this->raw_header_list[ $name ] = str_replace( array( "\r", "\n" ), '', (string) $value ); }
		}
		parent::__construct( array( '__wpcap_raw' => $this->raw_body, 'headers' => $this->raw_header_list ), (int) $status );
	}

	/** Bytes of the body. */
	public function raw() { return $this->raw_body; }

	/** @return array<string,string> */
	public function raw_headers() { return $this->raw_header_list; }
}
