<?php
/** Versioned opt-in connector contract. */
namespace PassCaptain\Integrations;

interface ConnectorInterface {
	public function key();
	public function version();
	public function capabilities();
	public function deliver( $event_name, array $payload, $idempotency_key );
}
