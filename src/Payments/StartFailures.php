<?php
/**
 * Failed payment starts (A324): the provider's text (e.g. "Stripe-Fehler: Your account cannot
 * currently make live charges") belongs to the operator, not to the buyer. The buyer gets a neutral
 * message with a reference ("Fehler-ID"); the provider text goes – redacted – into the support log
 * (with order and provider) and the per-provider counter that the Site Health test
 * `wpcap_payment_start` turns into an admin notice when failures pile up.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Payments;

use PassCaptain\Log;
use PassCaptain\Operations\Monitor;
use PassCaptain\Operations\SiteHealth;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StartFailures {
	/**
	 * Records a failed create_payment() and returns the error for the buyer: the provider's error code
	 * (machine-readable, e.g. wpcap_stripe_api), a neutral message with the reference, status 502.
	 *
	 * @param string    $provider Provider id.
	 * @param int       $order_id Order.
	 * @param \WP_Error $error    Error of create_payment().
	 * @param string    $flow     checkout, waitlist_offer, exchange.
	 * @param string    $code     Error code of the answer ('' = the provider's code).
	 */
	public static function handle( $provider, $order_id, \WP_Error $error, $flow = 'checkout', $code = '' ) {
		$provider = sanitize_key( (string) $provider );
		$data = (array) $error->get_error_data();
		$reference = Log::error(
			'payment',
			'Payment start failed: ' . $error->get_error_code() . ' ' . $error->get_error_message(),
			array(
				'order_id'      => absint( $order_id ),
				'provider'      => $provider,
				'flow'          => sanitize_key( (string) $flow ),
				'provider_code' => sanitize_key( (string) $error->get_error_code() ),
				'outcome'       => sanitize_key( (string) ( $data['outcome'] ?? '' ) ),
			)
		);
		$reference = '' !== $reference ? $reference : Log::new_reference();
		$critical_before = SiteHealth::test_payment_start();
		Monitor::record_provider_failure( Monitor::PAYMENT_START_OPTION, $provider, 'start', (string) $error->get_error_code(), (string) $error->get_error_message(), $reference );
		if ( 'critical' !== $critical_before['status'] && 'critical' === SiteHealth::test_payment_start()['status'] ) {
			delete_transient( SiteHealth::NOTICE_TRANSIENT ); // Threshold reached: the admin notice shows it right away.
		}
		/**
		 * A payment could not be started at the provider (A324). The buyer saw a neutral message.
		 *
		 * @param int       $order_id  Order (cancelled or reopened by the caller).
		 * @param string    $provider  Provider id.
		 * @param \WP_Error $error     Provider error (unredacted; never show it to buyers).
		 * @param string    $reference Support log reference shown to the buyer.
		 */
		do_action( 'wpcap_payment_start_failed', absint( $order_id ), $provider, $error, $reference );
		// A642: the order's audit trail keeps the redacted provider code and text as well.
		\PassCaptain\Audit::record( 'payment_start_failed', 'order', absint( $order_id ), array( 'provider' => $provider, 'flow' => sanitize_key( (string) $flow ), 'error' => sanitize_key( (string) $error->get_error_code() ), 'message' => substr( \PassCaptain\Redact::text( (string) $error->get_error_message() ), 0, 300 ), 'reference' => $reference ) );
		// Buyers never see the provider's error code either (A642), only the generic one of the flow.
		return new \WP_Error(
			'' !== (string) $code ? (string) $code : 'wpcap_payment_start_failed',
			/* translators: %s: error reference for support */
			sprintf( __( 'Die Zahlung konnte gerade nicht gestartet werden. Bitte versuche es in einem Moment erneut oder wähle eine andere Zahlungsart. Fehler-ID: %s', 'pass-captain' ), $reference ),
			array( 'status' => 502, 'reference' => $reference, 'reason' => 'payment_start_failed' )
		);
	}
}
