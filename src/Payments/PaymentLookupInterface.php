<?php
/**
 * Optional adapter contract: look up the payment of an order at the provider (A352).
 *
 * The reconciliation job (Payments\Reconcile) asks the provider about orders whose local state may be
 * behind – a pending order whose webhook never arrived, or an order that lapsed or was cancelled here
 * while the buyer paid. The answer is applied through Webhooks::process() exactly like a webhook, so
 * every rule of the state machine (amount check, late payment, double payment) applies.
 */
namespace PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface PaymentLookupInterface {
	/**
	 * Current state of the order's payment at the provider.
	 *
	 * @param array $order Order row as array (public_id, provider_reference, currency, total_minor, ...).
	 * @return array|null|\WP_Error Normalized event (as verify_webhook() returns it, with a stable
	 *                              event_id); null when the provider has nothing to report yet (payment
	 *                              still open); WP_Error when the lookup failed.
	 */
	public function find_payment( array $order );
}
