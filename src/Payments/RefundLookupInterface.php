<?php
/**
 * Optional adapter contract: look up a refund at the provider (A272, A348).
 *
 * Refunds requested by the plugin carry their key ("wpcap-refund-{refund_id}") at the provider:
 * Stripe and Mollie as metadata `wpcap_refund`, PayPal as `custom_id`. When a refund call ended
 * without a clear answer (timeout, transport error, 5xx, 409), `Refunds` keeps the refund in
 * "processing" and reconciles it through this lookup (job `refund_reconcile`, admin lookup) instead
 * of paying out a second time. The same lookup follows refunds that are still pending at the provider.
 */
namespace PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface RefundLookupInterface {
	/**
	 * Finds the provider refund of a plugin refund.
	 *
	 * @param array  $order     Order row as array (provider_reference, currency, public_id, ...).
	 * @param string $key       Refund key ("wpcap-refund-{refund_id}"), stored at the provider.
	 * @param string $reference Provider refund id when known (then read directly).
	 * @return array|null|\WP_Error ['status' => 'succeeded'|'pending'|'failed', 'reference' => string,
	 *                              'amount_minor' => int]; null when the provider conclusively has no
	 *                              refund with this key (nothing was paid out); WP_Error when the
	 *                              lookup failed or is not conclusive.
	 */
	public function find_refund( array $order, $key, $reference = '' );
}
