<?php
/**
 * Versioned, vendor-neutral contract for country-specific fiscal adapters.
 *
 * Every mutating call receives the same immutable idempotency key on retries. Adapters must
 * resolve ambiguous timeouts with query_status() before creating another receipt.
 *
 * @package PassCaptain
 */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface AdapterInterface {
	public const VERSION = 2;

	/** Stable provider key, e.g. provider_x_de. */
	public function id();

	/** Adapter contract version; must equal AdapterInterface::VERSION. */
	public function interface_version();

	/** ISO-3166 alpha-2 countries supported by this adapter. */
	public function countries();

	/** Machine-readable capabilities, e.g. receipt.submit, transaction.finish, reports.daily. */
	public function capabilities();

	/** Provider status check; must not create a receipt. */
	public function health_check( array $seller, array $configuration );

	/**
	 * Query a prior submission by the stable idempotency key.
	 * Return ['status'=>'submitted','reference'=>...] or ['status'=>'not_found']; WP_Error means unknown.
	 */
	public function query_status( array $document, array $seller, array $configuration );

	/**
	 * Submit one seller-specific receipt to the provider.
	 * Return ['reference'=>'...','receipt'=>['qr_payload'=>'...','fields'=>[...]]] or WP_Error.
	 */
	public function submit( array $document, array $seller, array $configuration );
}
