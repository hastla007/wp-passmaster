<?php
/**
 * Contract (version 1, revision 1.1) for card-terminal payments at the box office.
 *
 * The core ships no terminal provider. An add-on registers implementations through the filter
 * `wp_pass_captain_pos_payment_devices_v1` (see Devices and docs/EXTENSIONS.md). The core keeps the
 * sale pending with its capacity hold, polls the device and books the sale through the same path as
 * cash/card sales once the payment succeeded (fiscal gating applies).
 *
 * Every method may return a WP_Error or throw; the core treats that as a failure of the call
 * (start: the sale is released; poll: retried until the timeout; cancel: the sale stays pending).
 */
namespace PassCaptain\BoxOffice;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface DeviceGatewayInterface {
	/** Contract version (major; sent as `version` in the sale payload). */
	const VERSION = 1;

	/**
	 * Backward-compatible revision of version 1 (sent as `contract` = "1.1" in the sale payload).
	 * 1.1: start()/poll() may report the charged amount (amount_minor, currency); see poll().
	 */
	const REVISION = 1;

	/** Name shown to the cashier, e.g. "Terminal Eingang". */
	public function label(): string;

	/**
	 * Starts a payment on the terminal. Called once per sale; must be idempotent per `sale_key`
	 * (a retried start for the same sale must not create a second payment).
	 *
	 * @param array $sale version, contract ("1.1"), sale_key (64 hex, stable per sale), sale_id, order_id, order_public_id,
	 *                    amount_minor, currency, description, device_id, session_id, location_id,
	 *                    register_id, cashier_id.
	 * @return array{reference:string,status:string,amount_minor?:int,currency?:string}|\WP_Error status pending|succeeded|failed;
	 *         return an error only when no payment was started.
	 */
	public function start( array $sale );

	/**
	 * Current state of a started payment.
	 *
	 * Since contract 1.1 the answer may carry the amount the terminal actually charged: `amount_minor`
	 * (int, minor units) and `currency` (ISO 4217). When present on a "succeeded" answer (also from
	 * start()), the core compares them with the order before confirming; a difference is never booked:
	 * the sale ends as `amount_mismatch` (order cancelled, alert to the site admin, the charged amount
	 * must be refunded on the terminal). Without these fields nothing is compared (contract 1.0).
	 *
	 * @return array{status:string,provider_reference?:string,message?:string,amount_minor?:int,currency?:string}|\WP_Error
	 *         status pending|succeeded|failed|cancelled.
	 */
	public function poll( string $reference );

	/**
	 * Cancels a payment that is still pending on the terminal.
	 *
	 * @return true|\WP_Error An error means the payment could not be cancelled (e.g. card already
	 *                        presented); the sale then stays pending.
	 */
	public function cancel( string $reference );
}
