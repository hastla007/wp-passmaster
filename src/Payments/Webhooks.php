<?php
/** Shared, idempotent webhook processing for all payment adapters. */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Webhooks {
	/** REST callback: POST /wp-pass-captain/v1/payments/{provider}/webhook */
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
		$taken_over = $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_payment_events WHERE provider=%s AND provider_event_id=%s AND status='processing' AND updated_at < %s", $provider, $event_id, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );
		if ( false === $taken_over ) {
			return self::db_error( $provider, $event_id, 'takeover' );
		}
		// INSERT IGNORE: 1 = claimed, 0 = already claimed/processed (duplicate), false = database error.
		// Only a real duplicate is acknowledged; a database error must reach the provider as a 5xx so it retries.
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_payment_events (provider,provider_event_id,event_type,status,created_at,updated_at) VALUES (%s,%s,%s,'processing',%s,%s)", $provider, $event_id, sanitize_key( $event['type'] ?? '' ), $now, $now ) );
		if ( false === $claimed ) {
			return self::db_error( $provider, $event_id, 'claim' );
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
		$marked = $wpdb->update( "{$p}wpcap_payment_events", array( 'status' => 'processed', 'order_id' => $order_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'provider' => $provider, 'provider_event_id' => $event_id ) );
		if ( false === $marked ) {
			// The event was applied; the claim stays "processing" and a redelivery after the takeover
			// window re-applies it idempotently.
			Audit::record( 'webhook_mark_processed_failed', 'order', $order_id, array( 'provider' => $provider ) );
		}
		return $result;
	}

	/** Retryable error for a database failure while claiming an event (the endpoint answers 500). */
	private static function db_error( $provider, $event_id, $step ) {
		global $wpdb;
		Audit::record( 'webhook_db_error', 'payment_event', 0, array( 'provider' => $provider, 'event_id' => $event_id, 'step' => $step, 'error' => substr( (string) $wpdb->last_error, 0, 200 ) ) );
		return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
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
				// Redelivery after an issuance failure: issue the missing tickets now, else 5xx again.
				$issued = Orders::issue_and_email( $order_id );
				return is_wp_error( $issued ) ? $issued : 'already_paid';
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
			// The payment is booked; if the ticket issuance after it failed, answer 5xx so the
			// redelivery ("already_paid") issues them (a queued job retries it as well).
			$missing = Orders::missing_tickets( $order_id );
			if ( null === $missing || $missing > 0 ) {
				return new \WP_Error( 'wpcap_issue_failed', 'Payment booked; ticket issuance pending, retry later.', array( 'status' => 503, 'retryable' => true ) );
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
			$cancelled = Orders::cancel_order( $order_id, $provider . '_payment_failed' );
			// Retryable database error: nothing changed; the claim is released and the provider retries.
			return is_wp_error( $cancelled ) ? $cancelled : 'cancelled';
		}
		if ( 'refunded' === $type ) {
			// Full refund triggered outside the plugin (e.g. provider dashboard).
			if ( (int) ( $event['amount_minor'] ?? 0 ) >= (int) $order->total_minor - (int) $order->refunded_minor ) {
				$refunded = Orders::mark_refunded( $order_id, $provider . '_dashboard_refund' );
				return is_wp_error( $refunded ) ? $refunded : 'refunded';
			}
			Audit::record( 'external_partial_refund', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ) ) );
			return 'partial_refund_logged';
		}
		return 'ignored';
	}

	/** Mails the site admin about a payment that needs manual action. */
	private static function alert( $order, $message, $provider, array $event ) {
		/* translators: 1: order id, 2: provider, 3: reference, 4: message */
		wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Zahlung prüfen', 'pass-captain' ), sprintf( __( "Bestellung %1\$s (%2\$s, Referenz %3\$s):\n\n%4\$s", 'pass-captain' ), $order->public_id, $provider, (string) ( $event['provider_reference'] ?? '' ), $message ) );
	}
}
