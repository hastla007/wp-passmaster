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
	 * @return array|\WP_Error Normalized event: ['event_id'=>string, 'type'=>'paid'|'payment_pending'|'payment_authorized'|'failed'
	 *                         |'refunded'|'refund_failed'|'chargeback_reversed'|'dispute_opened'|'dispute_reinstated'|'ignored',
	 *                         'order_public_id'=>string, 'provider_reference'=>string, 'amount_minor'=>int, 'currency'=>string,
	 *                         optional 'related_references'=>string[] (references of the same payment attempt),
	 *                         'payment_references'=>string[] (the refunded payment of a refund/chargeback event, A640),
	 *                         'chargeback'=>bool (a "refunded" event caused by a chargeback/dispute; legacy:
	 *                         'refund_reason'=>'chargeback'), 'cumulative'=>bool (amount_minor is the provider's running
	 *                         total of external refunds for provider_reference; only the unbooked rest is booked),
	 *                         'refund_id'=>string (one external refund, booked once per id), legacy 'refund_scope'
	 *                         ('single' | 'cumulative' | 'full' | 'list' with 'refunds' => [{refund_id, amount_minor}]),
	 *                         'redeliver'=>bool (answer 503 after applying it, so the provider delivers again and a
	 *                         follow-up change in the same provider state is applied), 'refund_states'=>array[]
	 *                         (states of the plugin's own refunds: 'key' = refund key, 'status' = succeeded|pending|failed,
	 *                         'reference'; applied by Refunds::apply_provider_states(), A272)].
	 *                         'payment_pending': the buyer chose a delayed method, the hold is extended (A577, QA-6).
	 *                         'payment_authorized': an authorization only (Klarna via Mollie), captured right away.
	 *                         'refund_failed': an external refund that was booked failed later (reversed).
	 *                         'chargeback_reversed'/'dispute_reinstated': a chargeback was won and the money paid back
	 *                         (A419): audit and admin alert only. 'dispute_opened': admin alert only.
	 *                         How each type is applied in each order state: docs/STATE-MACHINES.md.
	 *                         Gateways may also offer min_hold_minutes() (minimum checkout hold, see Rest\Controller).
	 */
	public function verify_webhook( \WP_REST_Request $request );

	/** Whether refunds can be requested through the provider. */
	public function supports_refunds();

	/**
	 * Request a refund at the provider. $idempotency_key is the refund key ("wpcap-refund-{id}"); adapters
	 * should store it at the provider so the refund can be found again (RefundLookupInterface).
	 *
	 * @return string|array|\WP_Error Provider refund reference, or ['reference' => string, 'status' => 'pending']
	 *                                for a refund the provider has accepted but not completed yet. A WP_Error
	 *                                whose data has 'outcome' => 'unknown' (or, without that key, status 409/5xx
	 *                                or code http_request_failed) means the refund may have been executed: it is
	 *                                reconciled, never retried as failed (A272). 'outcome' => 'definitive' means
	 *                                nothing was paid out.
	 */
	public function refund( array $order, $amount_minor, $idempotency_key );
}
