<?php
/**
 * Provider contract for country-specific fiscal adapters.
 *
 * Implementations must use the idempotency_key on every remote operation and must never
 * retry a non-idempotent fiscal submission blindly.
 *
 * @package PassCaptain
 */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface AdapterInterface {
	/** Stable provider key, e.g. provider_x_de. */
	public function id();

	/** ISO-3166 alpha-2 countries supported by this adapter. */
	public function countries();

	/**
	 * Submit one seller-specific receipt to the provider.
	 *
	 * @param array $document Immutable local receipt snapshot and operation id.
	 * @param array $seller Legal seller snapshot.
	 * @param array $configuration Non-secret provider configuration.
	 * @return array|\WP_Error Provider result; result must include a stable reference and printable data.
	 */
	public function submit( array $document, array $seller, array $configuration );
}
