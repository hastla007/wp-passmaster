<?php
/** SMS provider adapter contract. Third parties add adapters via the `wpcap_sms_providers` filter. */
namespace PassCaptain\Sms;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ProviderInterface {
	/** Stable id ("twilio", "http"). */
	public function id();

	/** Admin facing title. */
	public function title();

	/** Whether credentials/endpoint are configured. */
	public function is_configured();

	/**
	 * Sends one message.
	 *
	 * @param string $to        E.164 number (+4917...).
	 * @param string $body      Message text.
	 * @param string $reference Internal message reference (no PII), usable as idempotency key.
	 * @return array|\WP_Error ['status' => 'sent'|'queued', 'provider_ref' => string] plus optional
	 *                         'price' (decimal string, sign ignored), 'currency' (ISO 4217) and
	 *                         'segments' (int) when the provider reports them; the price is then
	 *                         recorded instead of the configured estimate.
	 */
	public function send( $to, $body, $reference );
}
