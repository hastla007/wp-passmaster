<?php
/** Payment provider contract for idempotent adapter implementations. */
namespace PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adapters never handle card data: they redirect to a hosted payment page and confirm via signed
 * webhooks. Payment state changes always go through Orders::confirm_external_payment() /
 * Orders::cancel_order(), never through direct table writes.
 */
interface GatewayInterface {
	/** Stable provider id stored with orders ("manual", "stripe", "paypal"). */
	public function id();

	/** Customer facing title. */
	public function title();

	/** Whether the provider is configured and supports the currency. */
	public function is_available( $currency );

	/**
	 * Start a provider payment for a pending order.
	 *
	 * @param array $order Order row as array (order_id, public_id, currency, total_minor, customer_email, ...).
	 * @param array $items Order item rows as arrays (with name, quantity, unit_price_minor, line_total_minor).
	 * @return array|\WP_Error ['provider'=>..., 'status'=>'pending', 'redirect_url'?:..., 'instructions'?:..., 'provider_reference'?:...]
	 */
	public function create_payment( array $order, array $items = array() );

	/**
	 * Verify a provider webhook.
	 *
	 * @return array|\WP_Error Normalized event: ['event_id'=>string, 'type'=>'paid'|'failed'|'refunded'|'ignored',
	 *                         'order_public_id'=>string, 'provider_reference'=>string, 'amount_minor'=>int, 'currency'=>string,
	 *                         optional 'related_references'=>string[], 'chargeback'=>bool (a "refunded" event caused by a
	 *                         chargeback/dispute), 'cumulative'=>bool (amount_minor is the provider's running total of
	 *                         external refunds for provider_reference; only the unbooked rest is booked), 'redeliver'=>bool (answer 503 after applying it, so the provider
	 *                         delivers again and a follow-up change in the same provider state is applied)]. How each type is applied in each order state: docs/STATE-MACHINES.md.
	 */
	public function verify_webhook( \WP_REST_Request $request );

	/** Whether refunds can be requested through the provider. */
	public function supports_refunds();

	/**
	 * Request a refund at the provider.
	 *
	 * @return string|\WP_Error Provider refund reference.
	 */
	public function refund( array $order, $amount_minor, $idempotency_key );
}
