<?php
/**
 * Registry for explicitly enabled site-local integration adapters.
 *
 * Connectors register through the filter `wp_pass_captain_connectors_v1` and only run when their key
 * is listed in the site option `wpcap_enabled_connectors`. The core delivers its domain events to them
 * through the job queue (see ConnectorEvents); dispatch() performs one delivery.
 */
namespace PassCaptain\Integrations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConnectorRegistry {
	/**
	 * Delivers an event to the enabled connectors.
	 *
	 * @param string      $event_name      Event name (sanitize_key()).
	 * @param array       $payload         Event payload.
	 * @param string      $idempotency_key Event key; each connector receives sha256( key . '|' . connector key ).
	 * @param string|null $only            Deliver to this connector key only (job queue delivery).
	 * @return array Connector key => deliver() result; a connector that threw has no entry.
	 */
	public static function dispatch( $event_name, array $payload, $idempotency_key, $only = null ) {
		$enabled = get_option( 'wpcap_enabled_connectors', array() );
		$connectors = apply_filters( 'wp_pass_captain_connectors_v1', array() );
		if ( ! is_array( $enabled ) || ! is_array( $connectors ) ) { return array(); }
		$results = array();
		foreach ( $connectors as $connector ) {
			if ( ! $connector instanceof ConnectorInterface || ! in_array( $connector->key(), $enabled, true ) ) { continue; }
			if ( null !== $only && (string) $only !== (string) $connector->key() ) { continue; }
			try {
				$results[ sanitize_key( $connector->key() ) ] = $connector->deliver( sanitize_key( $event_name ), $payload, hash( 'sha256', $idempotency_key . '|' . $connector->key() ) );
			} catch ( \Throwable $error ) {
				do_action( 'wp_pass_captain_connector_error_v1', $connector->key(), $event_name, $error->getMessage() );
			}
		}
		return $results;
	}
}
