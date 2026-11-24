<?php
/**
 * Optional extension of AdapterInterface: provider-side closing of one box office shift (Z report).
 *
 * An adapter that implements it is asked once per (Z report, legal seller) after the shift was closed,
 * but only when the box office setting "boxoffice_fiscal_closing" is enabled (Fiscalization\ShiftClosing).
 * Adapters without it are not called; the Z report then shows "vom Anbieter nicht unterstützt".
 *
 * @package PassCaptain
 */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ClosingAdapterInterface {
	/** Capability key an adapter with shift closing support should list in capabilities(). */
	public const CAPABILITY = 'shift_closing';

	/**
	 * Close one shift at the provider (e.g. daily/shift closing, Z signature). Must be idempotent per
	 * $closing['idempotency_key'] and must not throw on transport errors.
	 *
	 * $closing: idempotency_key, z_id, z_number, z_hash, register_id, shift_id, location_id,
	 * fiscal_register_id, currency, opened_at, closed_at (UTC), net_total_minor, grand_total_minor (whole
	 * register), methods, vat, counts (Z totals of the register), seller_gross_minor, seller_tax_minor,
	 * documents (submitted/open counts of this seller), document_keys (idempotency keys of this seller's
	 * receipts in the shift).
	 *
	 * Return ['reference' => '...', 'signature' => '...', 'fields' => [label => value]] (reference required)
	 * or WP_Error.
	 */
	public function close_shift( array $closing, array $seller, array $configuration );
}
