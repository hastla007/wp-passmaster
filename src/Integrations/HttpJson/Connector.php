<?php
/**
 * One configured HTTP/JSON endpoint as connector (ConnectorInterface, key `http_json_{id}`).
 * Events the endpoint did not select are acknowledged without a request.
 */
namespace PassCaptain\Integrations\HttpJson;

use PassCaptain\Integrations\ConnectorInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Connector implements ConnectorInterface {
	/** @var int */
	private $endpoint_id;

	public function __construct( $endpoint_id ) {
		$this->endpoint_id = absint( $endpoint_id );
	}

	public function key() {
		return Endpoints::key( $this->endpoint_id );
	}

	public function version() {
		return Endpoints::CONTRACT_VERSION;
	}

	public function capabilities() {
		return array( 'events', 'http_json', 'field_mapping' );
	}

	/** @return true|\WP_Error */
	public function deliver( $event_name, array $payload, $idempotency_key ) {
		$endpoint = Endpoints::get( $this->endpoint_id );
		if ( ! $endpoint || 'active' !== $endpoint->status || ! in_array( (string) $event_name, $endpoint->events, true ) ) { return true; }
		return Endpoints::deliver( $endpoint, $event_name, $payload, $idempotency_key );
	}

	/**
	 * Filter wp_pass_captain_connectors_v1: one connector per active endpoint.
	 *
	 * @param array $connectors
	 * @return array
	 */
	public static function register_all( $connectors ) {
		$connectors = is_array( $connectors ) ? $connectors : array();
		foreach ( Endpoints::all() as $endpoint ) {
			if ( 'active' === $endpoint->status ) { $connectors[] = new self( $endpoint->endpoint_id ); }
		}
		return $connectors;
	}
}
