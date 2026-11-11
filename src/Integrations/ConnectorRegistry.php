<?php
/** Registry for explicitly enabled site-local integration adapters. */
namespace PassCaptain\Integrations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConnectorRegistry {
	public static function dispatch( $event_name, array $payload, $idempotency_key ) {
		$enabled = get_option( 'wpcap_enabled_connectors', array() );
		$connectors = apply_filters( 'wp_pass_captain_connectors_v1', array() );
		if ( ! is_array( $enabled ) || ! is_array( $connectors ) ) { return array(); }
		$results = array();
		foreach ( $connectors as $connector ) {
			if ( ! $connector instanceof ConnectorInterface || ! in_array( $connector->key(), $enabled, true ) ) { continue; }
			try {
				$results[ sanitize_key( $connector->key() ) ] = $connector->deliver( sanitize_key( $event_name ), $payload, hash( 'sha256', $idempotency_key . '|' . $connector->key() ) );
			} catch ( \Throwable $error ) {
				do_action( 'wp_pass_captain_connector_error_v1', $connector->key(), $event_name, $error->getMessage() );
			}
		}
		return $results;
	}
}
