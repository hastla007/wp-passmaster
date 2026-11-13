<?php
/** Shared, idempotent webhook processing for all payment adapters. */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Webhooks {
	/** REST callback: POST /pass-captain/v1/payments/{provider}/webhook */
	public static function handle( \WP_REST_Request $request ) {
		$gateway = Registry::get( sanitize_key( $request['provider'] ) );
		if ( ! $gateway ) {
			return new \WP_Error( 'wpcap_provider_unknown', 'Unknown provider.', array( 'status' => 404 ) );
		}
		$event = $gateway->verify_webhook( $request );
		if ( is_wp_error( $event ) ) {
			$data = (array) $event->get_error_data();
			return new \WP_Error( $event->get_error_code(), $event->get_error_message(), array( 'status' => $data['status'] ?? 400 ) );
		}
		$result = self::process( $gateway->id(), $event );
		if ( is_wp_error( $result ) ) {
			// 5xx so the provider retries later; the event row was released for the retry.
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}
		if ( ! empty( $event['redeliver'] ) && 'duplicate' !== $result ) {
			// The provider state holds a further change (e.g. a refund) that applies only after this event:
			// ask for a redelivery, which is then normalized against the new order state.
			return new \WP_Error( 'wpcap_webhook_redeliver', 'Applied; please redeliver for the follow-up change.', array( 'status' => 503 ) );
		}
		return rest_ensure_response( array( 'received' => true, 'result' => $result ) );
	}

	/**
	 * Applies a normalized, verified event exactly once.
	 *
	 * @param string $provider Provider id.
	 * @param array  $event    Normalized event (see GatewayInterface::verify_webhook()).
	 * @return string|\WP_Error Result label.
	 */
	public static function process( $provider, array $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = substr( sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ), 0, 191 );
		if ( '' === $event_id ) {
			return new \WP_Error( 'wpcap_webhook_event_id', 'Missing event id.' );
		}
		$now = current_time( 'mysql', true );
		// A claim left in "processing" by a crashed request may be taken over after 10 minutes.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_payment_events WHERE provider=%s AND provider_event_id=%s AND status='processing' AND updated_at < %s", $provider, $event_id, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_payment_events (provider,provider_event_id,event_type,status,created_at,updated_at) VALUES (%s,%s,%s,'processing',%s,%s)", $provider, $event_id, sanitize_key( $event['type'] ?? '' ), $now, $now ) );
		if ( false === $claimed ) {
			// Database error (not a duplicate): the provider must redeliver the event.
			return \PassCaptain\Errors::busy( __( 'Das Ereignis konnte gerade nicht gespeichert werden. Bitte erneut zustellen.', 'pass-captain' ) );
		}
		if ( 0 === (int) $claimed ) {
			return 'duplicate';
		}
		$release = static function () use ( $wpdb, $p, $provider, $event_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_payment_events WHERE provider=%s AND provider_event_id=%s AND status='processing'", $provider, $event_id ) );
		};
		try {
			$order_id = 0;
			$public_id = sanitize_text_field( (string) ( $event['order_public_id'] ?? '' ) );
			if ( '' !== $public_id ) {
				$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE public_id=%s AND payment_provider=%s", $public_id, $provider ) );
			}
			$result = self::apply( $provider, $event_id, $order_id, $event );
		} catch ( \Throwable $error ) {
			$release();
			return new \WP_Error( 'wpcap_webhook_exception', 'Processing failed; retry later.' );
		}
		if ( is_wp_error( $result ) ) {
			// Transient failure (e.g. lock wait timeout): release the claim so the provider retries.
			$release();
			return $result;
		}
		// processing → processed (StateMachine 'payment_event'); a processed event is never reopened.
		$wpdb->update( "{$p}wpcap_payment_events", array( 'status' => 'processed', 'order_id' => $order_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'provider' => $provider, 'provider_event_id' => $event_id, 'status' => 'processing' ) );
		return $result;
	}

	/** References that identify the payment attempt of an event. */
	private static function event_references( array $event ) {
		$refs = array_merge( array( (string) ( $event['provider_reference'] ?? '' ) ), array_map( 'strval', (array) ( $event['related_references'] ?? array() ) ) );
		return array_values( array_unique( array_filter( $refs ) ) );
	}

	/** True when the event belongs to the payment attempt currently stored on the order (or cannot be told). */
	private static function is_current_attempt( $order, array $event ) {
		$refs = self::event_references( $event );
		$current = (string) $order->provider_reference;
		return ! $refs || '' === $current || in_array( $current, $refs, true );
	}

	/** Applies a claimed event; returns a result label or WP_Error for retryable failures. */
	private static function apply( $provider, $event_id, $order_id, array $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type = (string) ( $event['type'] ?? '' );
		$order = $order_id ? Orders::get( $order_id ) : null;
		if ( ! $order ) {
			return 'ignored';
		}
		if ( 'paid' === $type ) {
			if ( (int) ( $event['amount_minor'] ?? -1 ) !== (int) $order->total_minor || strtoupper( (string) ( $event['currency'] ?? '' ) ) !== strtoupper( $order->currency ) ) {
				// Never issue tickets for a payment that does not match the order.
				Audit::record( 'payment_amount_mismatch', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ) ) );
				self::alert( $order, __( 'Eine Zahlung passt nicht zum Bestellbetrag; es wurden keine Tickets ausgestellt.', 'pass-captain' ), $provider, $event );
				return 'amount_mismatch';
			}
			if ( 'paid' === $order->payment_status ) {
				if ( ! self::is_current_attempt( $order, $event ) ) {
					// A second payment attempt also succeeded: the buyer paid twice.
					Audit::record( 'payment_double', 'order', $order_id, array( 'provider' => $provider, 'reference' => (string) ( $event['provider_reference'] ?? '' ) ) );
					self::alert( $order, __( 'Für eine bereits bezahlte Bestellung ist eine zweite Zahlung eingegangen. Bitte die doppelte Zahlung beim Zahlungsanbieter erstatten.', 'pass-captain' ), $provider, $event );
					return 'double_payment';
				}
				Orders::issue_and_email( $order_id );
				return 'already_paid';
			}
			if ( 'paid_unallocated' === $order->payment_status ) {
				// Paid but flagged for review (no capacity left): a repeated confirmation must not complete
				// it behind the operator's back; a payment of another attempt is a double payment.
				if ( ! self::is_current_attempt( $order, $event ) ) {
					Audit::record( 'payment_double', 'order', $order_id, array( 'provider' => $provider, 'reference' => (string) ( $event['provider_reference'] ?? '' ) ) );
					self::alert( $order, __( 'Für eine bereits bezahlte Bestellung ist eine zweite Zahlung eingegangen. Bitte die doppelte Zahlung beim Zahlungsanbieter erstatten.', 'pass-captain' ), $provider, $event );
					return 'double_payment';
				}
				return 'needs_review';
			}
			if ( in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
				Audit::record( 'payment_for_closed_order', 'order', $order_id, array( 'provider' => $provider, 'status' => $order->status, 'reference' => (string) ( $event['provider_reference'] ?? '' ) ) );
				self::alert( $order, __( 'Für eine stornierte oder erstattete Bestellung ist eine Zahlung eingegangen. Es wurden keine Tickets ausgestellt; bitte erstatten oder die Bestellung manuell klären.', 'pass-captain' ), $provider, $event );
				return 'paid_for_closed_order';
			}
			if ( ! empty( $event['provider_reference'] ) ) {
				$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => substr( sanitize_text_field( $event['provider_reference'] ), 0, 191 ) ), array( 'order_id' => $order_id ) );
			}
			$confirmed = Orders::confirm_external_payment( $order_id, $provider . ':' . $event_id );
			if ( is_wp_error( $confirmed ) ) {
				// needs_review is final (flagged and alerted by Orders); anything else is retried.
				return 'wpcap_needs_review' === $confirmed->get_error_code() ? 'needs_review' : $confirmed;
			}
			return 'paid';
		}
		if ( 'failed' === $type ) {
			if ( 'pending' !== $order->payment_status ) {
				return 'ignored';
			}
			if ( ! self::is_current_attempt( $order, $event ) ) {
				// Failure of an older attempt; the buyer may be paying with a newer one.
				Audit::record( 'stale_payment_failure_ignored', 'order', $order_id, array( 'provider' => $provider ) );
				return 'stale_attempt';
			}
			// Guarded under the order lock: a confirmation that won the race keeps the order paid.
			$cancelled = Orders::cancel_order( $order_id, $provider . '_payment_failed', array( 'pending' ) );
			if ( is_wp_error( $cancelled ) && 'wpcap_order_state' === $cancelled->get_error_code() ) {
				return 'ignored';
			}
			// A database error keeps the event unprocessed: the provider redelivers it.
			return is_wp_error( $cancelled ) ? $cancelled : 'cancelled';
		}
		if ( 'refunded' === $type ) {
			// Refund made outside the plugin (provider dashboard, chargeback). It is booked like any refund – refund
			// row, refunded amount, credit note, voucher share and partner settlement all follow from
			// `wpcap_refund_completed` – instead of only flipping the order status.
			global $wpdb;
			$chargeback = ! empty( $event['chargeback'] );
			if ( 'pending' === $order->payment_status ) {
				// Out of order: the refund overtook the payment confirmation. Nothing is booked; the 5xx makes
				// the provider redeliver it, by then the payment is confirmed (or the order closed).
				Audit::record( 'refund_before_payment', 'order', $order_id, array( 'provider' => $provider, 'event' => $event_id ) );
				return new \WP_Error( 'wpcap_refund_before_payment', 'Refund event before the payment confirmation; retry later.', array( 'status' => 503, 'retryable' => true ) );
			}
			if ( in_array( $order->payment_status, array( 'cancelled', 'expired' ), true ) ) {
				// The plugin never booked this payment (e.g. "paid_for_closed_order" refunded by the operator):
				// no refund row, no credit note, the order stays closed.
				Audit::record( 'refund_for_unpaid_order', 'order', $order_id, array( 'provider' => $provider, 'status' => $order->status, 'chargeback' => $chargeback ) );
				return 'refund_for_unpaid_order';
			}
			$remaining = max( 0, (int) $order->total_minor - (int) $order->refunded_minor );
			$amount    = max( 0, (int) ( $event['amount_minor'] ?? 0 ) );
			if ( ! empty( $event['cumulative'] ) ) {
				// Cumulative provider total (Mollie snapshots): only the part not yet booked for this payment
				// counts, so a repeated or older snapshot never books an amount twice.
				$amount = max( 0, $amount - (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d AND provider_reference=%s AND status='completed'", $order_id, (string) ( $event['provider_reference'] ?? '' ) ) ) );
				if ( 0 === $amount && $remaining > 0 ) {
					return 'already_booked';
				}
			}
			$reason    = $provider . ( $chargeback ? '_chargeback' : '_dashboard_refund' );
			$full      = $amount >= $remaining;
			if ( 'paid_unallocated' === $order->payment_status ) {
				// No tickets, no invoice: a full refund closes the case, a partial one stays with the operator.
				if ( ! $full ) {
					Audit::record( 'partial_refund_for_review_order', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => $amount ) );
					self::alert( $order, __( 'Für eine zu prüfende Bestellung (bezahlt, ohne Tickets) wurde ein Teilbetrag erstattet. Bitte die Bestellung manuell klären.', 'pass-captain' ), $provider, $event );
					return 'needs_review';
				}
				$marked = Orders::mark_refunded( $order_id, $reason );
				return is_wp_error( $marked ) ? $marked : 'refunded';
			}
			if ( 0 === $remaining && 'refunded' === $order->status && $chargeback ) {
				// Money pulled back a second time after the order was refunded in full: dispute the chargeback.
				Audit::record( 'chargeback_after_refund', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => $amount ) );
				self::alert( $order, __( 'Für eine bereits vollständig erstattete Bestellung wurde eine Rückbuchung (Chargeback) gemeldet. Bitte beim Zahlungsanbieter widersprechen; es wurde nichts erneut gebucht.', 'pass-captain' ), $provider, $event );
				return 'chargeback_after_refund';
			}
			if ( $remaining > 0 && $amount > 0 ) {
				$tickets = $full ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND refunded=0", $order_id ) ) ) : array();
				$booked  = Refunds::record_external(
					$order_id,
					$tickets,
					$full ? $remaining : $amount,
					$reason,
					$provider . ':' . $event_id,
					array( 'provider_reference' => (string) ( $event['provider_reference'] ?? '' ), 'source' => $provider )
				);
				if ( is_wp_error( $booked ) && ! $full ) {
					return $booked; // Retried by the provider.
				}
			}
			if ( $full ) {
				$marked = Orders::mark_refunded( $order_id, $reason );
				return is_wp_error( $marked ) ? $marked : 'refunded'; // A database error: the provider redelivers.
			}
			return 'partial_refund';
		}
		return 'ignored';
	}

	/** Mails the site admin about a payment that needs manual action. */
	private static function alert( $order, $message, $provider, array $event ) {
		/* translators: 1: order id, 2: provider, 3: reference, 4: message */
		wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Zahlung prüfen', 'pass-captain' ), sprintf( __( "Bestellung %1\$s (%2\$s, Referenz %3\$s):\n\n%4\$s", 'pass-captain' ), $order->public_id, $provider, (string) ( $event['provider_reference'] ?? '' ), $message ) );
	}
}
