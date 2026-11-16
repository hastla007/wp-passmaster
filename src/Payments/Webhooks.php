<?php
/** Shared, idempotent webhook processing for all payment adapters. */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Log;
use PassCaptain\Operations\Monitor;
use PassCaptain\Operations\SiteHealth;
use PassCaptain\Orders;
use PassCaptain\Redact;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Webhooks {
	/** URL of the provider's webhook endpoint (entered in the provider's dashboard). */
	public static function endpoint_url( $provider ) {
		return rest_url( 'pass-captain/v1/payments/' . sanitize_key( (string) $provider ) . '/webhook' );
	}

	/** REST callback: POST /pass-captain/v1/payments/{provider}/webhook */
	public static function handle( \WP_REST_Request $request ) {
		$gateway = Registry::get( sanitize_key( $request['provider'] ) );
		if ( ! $gateway ) {
			return new \WP_Error( 'wpcap_provider_unknown', 'Unknown provider.', array( 'status' => 404 ) );
		}
		$event = $gateway->verify_webhook( $request );
		if ( is_wp_error( $event ) ) {
			$data = (array) $event->get_error_data();
			$status = (int) ( $data['status'] ?? 400 );
			// A323: rejected deliveries are counted per provider (Site Health, Betrieb) – a wrong
			// signing secret otherwise only shows as "no webhooks" two days later.
			self::failure( $gateway->id(), 401 === $status ? 'signature' : ( $status >= 500 ? 'verify' : 'payload' ), $event, 'warning' );
			return new \WP_Error( $event->get_error_code(), $event->get_error_message(), array( 'status' => $status ) );
		}
		$result = self::process( $gateway->id(), $event );
		if ( is_wp_error( $result ) ) {
			// A crash was already recorded in process(); a database/processing error is recorded here.
			if ( 'wpcap_webhook_exception' !== $result->get_error_code() && 'wpcap_webhook_in_progress' !== $result->get_error_code() ) {
				self::failure( $gateway->id(), 'processing', $result, 'warning', array( 'event_type' => sanitize_key( (string) ( $event['type'] ?? '' ) ) ) );
			}
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
		$event_id = \PassCaptain\Text::cut( sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ), 191 );
		if ( '' === $event_id ) {
			return new \WP_Error( 'wpcap_webhook_event_id', 'Missing event id.' );
		}
		if ( ! empty( $event['refund_states'] ) && is_array( $event['refund_states'] ) ) {
			// States of the plugin's own refunds (A272, A348): settle refunds with an unknown outcome or pending
			// at the provider. Idempotent, so also applied for a repeated event (Mollie posts the same payment
			// id for every refund change); a database error makes the provider redeliver.
			$applied = Refunds::apply_provider_states( $provider, $event['refund_states'] );
			if ( is_wp_error( $applied ) ) { return $applied; }
		}
		$now = current_time( 'mysql', true );
		// A claim left in "processing" by a crashed request may be taken over after 10 minutes.
		$taken_over = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE provider=%s AND provider_event_id=%s AND status='processing' AND updated_at < %s", $p . 'wpcap_payment_events', $provider, $event_id, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );
		if ( false === $taken_over ) {
			return self::db_error( $provider, $event_id, 'takeover' );
		}
		// INSERT IGNORE: 1 = claimed, 0 = already claimed/processed (duplicate), false = database error.
		// Only a real duplicate is acknowledged; a database error must reach the provider as a 5xx so it retries.
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (provider,provider_event_id,event_type,status,created_at,updated_at) VALUES (%s,%s,%s,'processing',%s,%s)", $p . 'wpcap_payment_events', $provider, $event_id, sanitize_key( $event['type'] ?? '' ), $now, $now ) );
		if ( false === $claimed ) {
			return self::db_error( $provider, $event_id, 'claim' );
		}
		if ( 0 === (int) $claimed ) {
			// Only an event that was fully processed is a duplicate. A claim still "processing" belongs
			// to a request that is running or crashed (its release may have failed with the connection):
			// acknowledging it would make the provider stop retrying an event that was never applied.
			// A concurrent delivery of the same event usually finishes within moments: wait briefly for it
			// (filter wpcap_webhook_claim_wait_ms, default 3000) before asking the provider to retry.
			$wait_until = microtime( true ) + max( 0, (int) apply_filters( 'wpcap_webhook_claim_wait_ms', 3000 ) ) / 1000;
			do {
				$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE provider=%s AND provider_event_id=%s', $p . 'wpcap_payment_events', $provider, $event_id ) );
				if ( 'processed' === $status ) {
					return 'duplicate';
				}
				if ( '' !== (string) $wpdb->last_error ) {
					return self::db_error( $provider, $event_id, 'claim_status' );
				}
				if ( 'processing' !== $status || microtime( true ) >= $wait_until ) { break; }
				usleep( 50000 );
			} while ( true );
			return new \WP_Error( 'wpcap_webhook_in_progress', 'Event is being processed; retry later.', array( 'status' => 409, 'retryable' => true ) );
		}
		$release = static function () use ( $wpdb, $p, $provider, $event_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE provider=%s AND provider_event_id=%s AND status='processing'", $p . 'wpcap_payment_events', $provider, $event_id ) );
		};
		try {
			$order_id = 0;
			$public_id = sanitize_text_field( (string) ( $event['order_public_id'] ?? '' ) );
			if ( '' !== $public_id ) {
				$order_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE public_id=%s AND payment_provider=%s', $p . 'wpcap_orders', $public_id, $provider ) );
				if ( '' !== (string) $wpdb->last_error ) {
					// A failed lookup is not an unknown order: "ignored" would acknowledge a lost payment.
					$release();
					return self::db_error( $provider, $event_id, 'order_lookup' );
				}
			}
			// Ticket mails of this payment go to the job queue (due now): the provider gets its answer
			// without waiting for PDF rendering, wallet signing and SMTP (no timeouts and redeliveries).
			$result = Orders::defer_ticket_mail( static function () use ( $provider, $event_id, $order_id, $event ) { return self::apply( $provider, $event_id, $order_id, $event ); } );
		} catch ( \Throwable $error ) {
			$release();
			// A323: class, file:line and database error into the support log; counted per provider.
			$reference = Log::exception( 'webhook', $error, array( 'provider' => sanitize_key( (string) $provider ), 'event_type' => sanitize_key( (string) ( $event['type'] ?? '' ) ), 'order_id' => (int) ( $order_id ?? 0 ) ) );
			Monitor::record_provider_failure( Monitor::WEBHOOK_OPTION, (string) $provider, 'exception', 'wpcap_webhook_exception', Redact::exception( $error ), $reference );
			delete_transient( SiteHealth::NOTICE_TRANSIENT ); // The admin notice shows it right away.
			return \PassCaptain\Errors::busy( 'Processing failed; retry later.', 'wpcap_webhook_exception', '' !== $reference ? array( 'reference' => $reference ) : array() );
		}
		if ( is_wp_error( $result ) ) {
			// Transient failure (e.g. lock wait timeout): release the claim so the provider retries.
			// If the release fails too, the claim is taken over after 10 minutes.
			$release();
			return $result;
		}
		// processing → processed (StateMachine 'payment_event'); a processed event is never reopened.
		$marked = $wpdb->update( "{$p}wpcap_payment_events", array( 'status' => 'processed', 'order_id' => $order_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'provider' => $provider, 'provider_event_id' => $event_id, 'status' => 'processing' ) );
		if ( false === $marked ) {
			// The event was applied; the claim stays "processing" and a redelivery after the takeover
			// window re-applies it idempotently (finds the work done: "already_paid" etc.).
			\PassCaptain\Log::error( 'webhook_mark_processed_failed', 'Payment webhook applied, but marking the event as processed failed.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'provider' => $provider ) ) );
		}
		return $result;
	}

	/** Retryable error for a database failure while claiming an event (the endpoint answers 500). */
	private static function db_error( $provider, $event_id, $step ) {
		global $wpdb;
		\PassCaptain\Log::error( 'webhook_db_error', 'Payment webhook hit a database error; the provider will retry.', array( 'object_type' => 'payment_event', 'object_id' => 0, 'details' => array( 'provider' => $provider, 'event_id' => $event_id, 'step' => $step, 'error' => \PassCaptain\Text::cut( (string) $wpdb->last_error, 200 ) ) ) );
		return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
	}

	/**
	 * Records a failed webhook delivery (A323): per-provider counter for Site Health and the
	 * operations page; the first failure of a kind per minute also goes to the support log.
	 *
	 * @param string    $provider Provider id.
	 * @param string    $kind     signature, payload, verify, processing.
	 * @param \WP_Error $error    Error.
	 */
	private static function failure( $provider, $kind, \WP_Error $error, $level, array $context = array() ) {
		$code = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$new = Monitor::record_provider_failure( Monitor::WEBHOOK_OPTION, (string) $provider, $kind, $code, $message );
		if ( $new ) {
			$reference = Log::write( $level, 'webhook', 'Payment webhook rejected (' . $kind . '): ' . $code . ' ' . $message, array( 'provider' => sanitize_key( (string) $provider ), 'kind' => $kind ) + $context );
			if ( '' !== $reference && 'signature' === $kind ) { delete_transient( SiteHealth::NOTICE_TRANSIENT ); }
		}
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

	/**
	 * True when a refund/chargeback event concerns the payment stored on the order (A640). Gateways name
	 * the refunded payment in `payment_references` (Stripe: PaymentIntent, PayPal: capture and PayPal
	 * order, Mollie: payment); without them, or without a stored reference, it cannot be told (true).
	 */
	private static function refund_of_current_attempt( $order, array $event ) {
		$refs = array_values( array_filter( array_map( 'strval', (array) ( $event['payment_references'] ?? array() ) ) ) );
		$current = (string) $order->provider_reference;
		return ! $refs || '' === $current || in_array( $current, $refs, true );
	}

	/** Applies a claimed event; returns a result label or WP_Error for retryable failures. */
	private static function apply( $provider, $event_id, $order_id, array $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type = (string) ( $event['type'] ?? '' );
		$order = $order_id ? Orders::get( $order_id ) : null;
		if ( ! $order && '' !== $wpdb->last_error ) {
			return \PassCaptain\Errors::busy();
		}
		if ( ! $order ) {
			if ( $order_id && '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
			}
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
					Cases::open( $order, $provider, 'double_payment', (string) ( $event['provider_reference'] ?? '' ), (int) ( $event['amount_minor'] ?? 0 ), (string) ( $event['currency'] ?? '' ) );
					self::alert( $order, __( 'Für eine bereits bezahlte Bestellung ist eine zweite Zahlung eingegangen. Bitte die doppelte Zahlung beim Zahlungsanbieter erstatten (oder die automatische Erstattung solcher Fälle in den Einstellungen aktivieren). Der Fall steht im Systemstatus.', 'pass-captain' ), $provider, $event );
					return 'double_payment';
				}
					// Redelivery after an error: completes counters and tickets (Orders::confirm_external_payment()).
					$completed = Orders::confirm_external_payment( $order_id, $provider . ':' . $event_id );
					return is_wp_error( $completed ) ? $completed : 'already_paid';
				}
				if ( 'paid_unallocated' === $order->payment_status ) {
					// Paid but flagged for review (no capacity left): a repeated confirmation must not complete
					// it behind the operator's back; a payment of another attempt is a double payment.
					if ( ! self::is_current_attempt( $order, $event ) ) {
						Audit::record( 'payment_double', 'order', $order_id, array( 'provider' => $provider, 'reference' => (string) ( $event['provider_reference'] ?? '' ) ) );
						Cases::open( $order, $provider, 'double_payment', (string) ( $event['provider_reference'] ?? '' ), (int) ( $event['amount_minor'] ?? 0 ), (string) ( $event['currency'] ?? '' ) );
						self::alert( $order, __( 'Für eine bereits bezahlte Bestellung ist eine zweite Zahlung eingegangen. Bitte die doppelte Zahlung beim Zahlungsanbieter erstatten.', 'pass-captain' ), $provider, $event );
						return 'double_payment';
					}
					return 'needs_review';
				}
				if ( in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
					// A273: with `late_payment_auto_refund` the payment of the current attempt for a cancelled order
					// is refunded automatically (Orders::schedule_late_refund()). Otherwise the payment is recorded
					// as a case (Payments\Cases: status page, optional automatic refund of the payment reference).
					$auto = 'cancelled' === $order->status && self::is_current_attempt( $order, $event ) && Orders::schedule_late_refund( $order, 'payment_for_closed_order' );
					Audit::record( 'payment_for_closed_order', 'order', $order_id, array( 'provider' => $provider, 'status' => $order->status, 'reference' => (string) ( $event['provider_reference'] ?? '' ), 'auto_refund' => $auto ) );
					if ( ! $auto ) {
						Cases::open( $order, $provider, 'paid_for_closed_order', (string) ( $event['provider_reference'] ?? '' ), (int) ( $event['amount_minor'] ?? 0 ), (string) ( $event['currency'] ?? '' ) );
					}
					self::alert( $order, $auto ? __( 'Für eine stornierte Bestellung ist eine Zahlung eingegangen. Es wurden keine Tickets ausgestellt; die Zahlung wird automatisch erstattet.', 'pass-captain' ) : __( 'Für eine stornierte oder erstattete Bestellung ist eine Zahlung eingegangen. Es wurden keine Tickets ausgestellt; bitte erstatten oder die Bestellung manuell klären. Der Fall steht im Systemstatus.', 'pass-captain' ), $provider, $event );
				return 'paid_for_closed_order';
			}
			if ( ! empty( $event['provider_reference'] ) ) {
				$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => \PassCaptain\Text::cut( sanitize_text_field( $event['provider_reference'] ), 191 ) ), array( 'order_id' => $order_id ) );
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
		if ( 'payment_pending' === $type ) {
			// The buyer finished the payment page with a delayed method (SEPA Direct Debit, bank transfer):
			// the payment confirms days later, so the hold is extended (A577, QA-6) and its confirmation does
			// not hit an expired hold. A hold that already lapsed is not revived (late-payment re-check).
			if ( 'pending' !== $order->payment_status || ! self::is_current_attempt( $order, $event ) ) { return 'ignored'; }
			if ( ! Orders::has_active_hold( $order_id ) ) {
				Audit::record( 'delayed_payment_hold_lapsed', 'order', $order_id, array( 'provider' => (string) $provider ) );
				return 'hold_lapsed';
			}
			$until = Orders::extend_hold_for_delayed_payment( $order_id );
			if ( null === $until ) { return 'hold_not_extended'; }
			// Optional gateway hook, e.g. Mollie moves the bank transfer's due date to the new hold end.
			$gateway = Registry::get( $provider );
			if ( $gateway && method_exists( $gateway, 'after_hold_extended' ) ) {
				$aligned = $gateway->after_hold_extended( (array) $order, $event );
				if ( is_wp_error( $aligned ) ) { \PassCaptain\Log::warning( 'delayed_payment_due_date_failed', 'Could not align the hold with the due date.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'provider' => $provider, 'error' => $aligned->get_error_code() ) ) ); }
			}
			return 'hold_extended';
		}
		if ( 'payment_authorized' === $type ) {
			// Payment methods that only authorize (Klarna/Riverty via Mollie): capture right away; the
			// capture's own "paid" event confirms the order. Retried by the provider on a failed capture.
			if ( 'pending' !== $order->payment_status || ! self::is_current_attempt( $order, $event ) ) {
				return 'ignored';
			}
			// A351: only the full order amount is captured; another authorized amount is left to the admin.
			if ( (int) ( $event['amount_minor'] ?? -1 ) !== (int) $order->total_minor || strtoupper( (string) ( $event['currency'] ?? '' ) ) !== strtoupper( (string) $order->currency ) ) {
				Audit::record( 'payment_amount_mismatch', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ), 'authorized' => true ) );
				return 'authorized_amount_mismatch';
			}
			$gateway = Registry::get( $provider );
			if ( ! $gateway || ! method_exists( $gateway, 'capture_authorized' ) ) {
				self::alert( $order, __( 'Eine Zahlung wurde nur autorisiert und kann nicht automatisch eingezogen werden. Bitte beim Zahlungsanbieter einziehen oder stornieren.', 'pass-captain' ), $provider, $event );
				return 'authorized_not_captured';
			}
			$captured = $gateway->capture_authorized( (array) $order, $event );
			if ( is_wp_error( $captured ) ) {
				\PassCaptain\Log::error( 'payment_capture_failed', 'Payment capture failed.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'provider' => $provider, 'error' => $captured->get_error_code() ) ) );
				return new \WP_Error( 'wpcap_capture_failed', 'Capture failed; retry later.', array( 'status' => 503, 'retryable' => true ) );
			}
			self::extend_for_delayed_payment( $order, $provider );
			Audit::record( 'payment_capture_requested', 'order', $order_id, array( 'provider' => $provider ) );
			return 'capture_requested';
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
			// Retryable database error: nothing changed; the claim is released and the provider retries.
			return is_wp_error( $cancelled ) ? $cancelled : 'cancelled';
		}
		if ( 'refunded' === $type && Cases::is_case_reference( $provider, array_merge( self::event_references( $event ), array_map( 'strval', (array) ( $event['payment_references'] ?? array() ) ) ) ) ) {
			// Refund of a payment that was never booked on the order (double payment, payment for a closed
			// order – Payments\Cases): it must not reduce the order's own payment.
			Audit::record( 'payment_case_refund_event', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ) ) );
			return 'payment_case_refund';
		}
		if ( 'refund_failed' === $type ) {
			// A refund booked as completed failed later at the provider: reverse the booking (Refunds).
			$refs = array_values( array_unique( array_filter( array_merge( array_map( 'strval', (array) ( $event['refund_ids'] ?? array() ) ), array( (string) ( $event['refund_id'] ?? '' ), (string) ( $event['provider_reference'] ?? '' ) ) ) ) ) );
			$reversed = Refunds::provider_failed( $order_id, $refs, $provider );
			if ( is_wp_error( $reversed ) ) {
				return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
			}
			if ( ! $reversed ) {
				Audit::record( 'refund_failed_unknown', 'order', $order_id, array( 'provider' => $provider, 'reference' => \PassCaptain\Text::cut( implode( ',', $refs ), 150 ) ) );
				return 'ignored';
			}
			Cases::open( $order, $provider, 'refund_failed', (string) ( $refs[0] ?? '' ), (int) array_sum( array_map( static function ( $row ) { return (int) $row['amount_minor']; }, $reversed ) ), (string) $order->currency );
			return 'refund_failed';
		}
		if ( 'dispute_opened' === $type ) {
			Audit::record( 'payment_dispute_opened', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ), 'reference' => \PassCaptain\Text::cut( sanitize_text_field( (string) ( $event['provider_reference'] ?? '' ) ), 100 ) ) );
			self::alert( $order, __( 'Für diese Bestellung wurde beim Zahlungsanbieter eine Zahlungsanfechtung (Dispute/Chargeback) eröffnet. Bitte beim Anbieter reagieren. Wird das Geld zurückgebucht, verbucht Pass Captain die Erstattung automatisch und sperrt die Tickets.', 'pass-captain' ), $provider, $event );
			return 'dispute_alerted';
		}
		if ( 'chargeback_reversed' === $type || 'dispute_reinstated' === $type ) {
			// A419: a chargeback was decided in the merchant's favour and the money is back. The chargeback
			// was booked as a refund (order refunded, tickets revoked); undoing that is a decision for the
			// operator (reissue tickets or pay the buyer back), so it is only recorded and reported.
			$amount = max( 0, (int) ( $event['amount_minor'] ?? 0 ) );
			$booked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id=%d AND provider_reference IN (%s,%s) AND status='completed'", $p . 'wpcap_refunds', $order_id, (string) ( $event['provider_reference'] ?? '' ), 'ext:' . sanitize_key( $provider ) . ':' . (string) ( $event['provider_reference'] ?? '' ) ) );
			Audit::record( 'chargeback_reversed', 'order', $order_id, array( 'provider' => $provider, 'reference' => (string) ( $event['provider_reference'] ?? '' ), 'amount_minor' => $amount, 'chargeback_booked' => $booked > 0, 'status' => (string) $order->status ) );
			/* translators: %s: amount with currency */
			$message = sprintf( __( 'Eine Rückbuchung (Chargeback) wurde zu Ihren Gunsten entschieden; der Zahlungsanbieter zahlt %s wieder aus.', 'pass-captain' ), \PassCaptain\Money::format( $amount, (string) $order->currency ) ) . ' ';
			$message .= $booked
				? __( 'Die Rückbuchung war als Erstattung gebucht (Tickets widerrufen, Gutschrift erstellt). Bitte entscheiden: Tickets neu ausstellen und Gutschrift stornieren oder dem Käufer den Betrag erstatten. Pass Captain ändert die Bestellung nicht automatisch.', 'pass-captain' )
				: __( 'Zu dieser Rückbuchung ist keine Erstattung gebucht; die Bestellung bleibt unverändert. Bitte beim Zahlungsanbieter prüfen.', 'pass-captain' );
			self::alert( $order, $message, $provider, $event );
			return 'chargeback_reversed';
		}
		if ( 'refunded' === $type ) {
			return self::apply_refund_event( $provider, $event_id, $order, $event );
		}
		return 'ignored';
	}

	/**
	 * Refund made outside the plugin (provider dashboard, chargeback, PayPal reversal). It is booked
	 * like any refund – refund row, refunded amount, credit note, voucher share and partner settlement
	 * all follow from `wpcap_refund_completed` (Refunds::record_external()) – instead of only flipping
	 * the order status; the order is marked refunded once the remaining total is booked.
	 *
	 * Amount semantics: `cumulative` (also legacy refund_scope 'cumulative') = the provider's running
	 * total of external refunds of the payment (only the part not yet booked for provider_reference
	 * counts); refund_scope 'list' = every external refund with its own id (each booked once);
	 * `refund_id` (or refund_scope 'single') = one refund, idempotent per refund id; otherwise the
	 * amount of this event, idempotent per event id. An amount covering the remaining total (or
	 * refund_scope 'full') refunds the order completely.
	 *
	 * @return string|\WP_Error
	 */
	private static function apply_refund_event( $provider, $event_id, $order, array $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $order->order_id;
		$scope = (string) ( $event['refund_scope'] ?? '' );
		$chargeback = ! empty( $event['chargeback'] ) || 'chargeback' === sanitize_key( (string) ( $event['refund_reason'] ?? '' ) );
		if ( 'pending' === $order->payment_status ) {
			// Out of order: the refund overtook the payment confirmation. Nothing is booked; the 5xx makes the
			// provider redeliver it, by then the payment is confirmed (or the order closed).
			Audit::record( 'refund_before_payment', 'order', $order_id, array( 'provider' => $provider, 'event' => $event_id ) );
			return new \WP_Error( 'wpcap_refund_before_payment', 'Refund event before the payment confirmation; retry later.', array( 'status' => 503, 'retryable' => true ) );
		}
		if ( 'expired' === $order->payment_status && 'cancelled' !== $order->status && (int) ( $event['amount_minor'] ?? 0 ) >= (int) $order->total_minor ) {
			// Full refund/chargeback of an order that was never paid here and whose hold lapsed (no refund
			// ledger entry is possible): it cancels the order and never marks it refunded. A `paid` event
			// arriving later answers `paid_for_closed_order` (admin alert, no tickets). A payment booked in the
			// meantime (checked under the row lock) is left alone.
			$cancelled = Orders::cancel_order( $order_id, $provider . '_refund_unpaid', true );
			if ( true === $cancelled ) {
				Audit::record( 'provider_refund_unpaid_cancelled', 'order', $order_id, array( 'provider' => $provider, 'previous_status' => (string) $order->status, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ), 'reference' => \PassCaptain\Text::cut( sanitize_text_field( (string) ( $event['provider_reference'] ?? '' ) ), 100 ) ) );
				return 'cancelled_unpaid';
			}
			if ( is_wp_error( $cancelled ) && 'wpcap_order_paid_state' !== $cancelled->get_error_code() ) {
				return $cancelled; // Retryable database error: the provider redelivers.
			}
			$order = Orders::get( $order_id );
			if ( ! $order ) { return self::db_error( $provider, $event_id, 'refund_reload' ); }
		}
		if ( in_array( $order->payment_status, array( 'cancelled', 'expired' ), true ) ) {
			// The plugin never booked this payment (e.g. "paid_for_closed_order" refunded by the operator):
			// no refund row, no credit note, the order stays closed.
			Audit::record( 'refund_for_unpaid_order', 'order', $order_id, array( 'provider' => $provider, 'status' => $order->status, 'chargeback' => $chargeback ) );
			return 'refund_for_unpaid_order';
		}
		if ( ! self::refund_of_current_attempt( $order, $event ) ) {
			// A640: refund (or chargeback) of another payment of this order, e.g. the second payment of a
			// double payment. The booked payment is untouched: no refund row, no revoked tickets.
			Audit::record( 'refund_of_other_payment', 'order', $order_id, array( 'provider' => $provider, 'reference' => (string) ( $event['provider_reference'] ?? '' ), 'amount_minor' => max( 0, (int) ( $event['amount_minor'] ?? 0 ) ), 'chargeback' => $chargeback ) );
			self::alert( $order, __( 'Beim Zahlungsanbieter wurde eine Erstattung bzw. Rückbuchung einer anderen Zahlung dieser Bestellung gemeldet (z. B. einer Doppelzahlung). Die gebuchte Zahlung und die Tickets bleiben unverändert; bitte beim Anbieter prüfen.', 'pass-captain' ), $provider, $event );
			return 'refund_other_payment';
		}
		if ( 'list' === $scope ) {
			// Every external refund with its own id: booked one by one (a refund booked earlier is skipped).
			$result = 'refund_already_booked';
			foreach ( (array) ( $event['refunds'] ?? array() ) as $item ) {
				$refund_id = \PassCaptain\Text::cut( sanitize_text_field( (string) ( $item['refund_id'] ?? '' ) ), 150 );
				$amount = (int) ( $item['amount_minor'] ?? 0 );
				if ( '' === $refund_id || $amount <= 0 ) { continue; }
				$current = Orders::get( $order_id );
				if ( ! $current ) { return self::db_error( $provider, $event_id, 'refund_reload' ); }
				$single = array_merge( $event, array( 'refund_scope' => 'single', 'refund_id' => $refund_id, 'amount_minor' => $amount ) );
				unset( $single['refunds'] );
				$one = self::apply_refund_event( $provider, $event_id, $current, $single );
				if ( is_wp_error( $one ) || 'refunded' === $one ) { return $one; }
				if ( 'partial_refund' === $one ) { $result = 'partial_refund'; }
			}
			return $result;
		}
		// Money a native refund is paying out right now belongs to that refund (A346): it is not open
		// for an external refund, and its own provider event never books it a second time.
		$running   = Refunds::running_minor( $order_id );
		$remaining = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - $running );
		$amount    = max( 0, (int) ( $event['amount_minor'] ?? 0 ) );
		$reference = (string) ( $event['provider_reference'] ?? '' );
		if ( ! empty( $event['cumulative'] ) || 'cumulative' === $scope ) {
			// Cumulative provider total (Mollie and Stripe snapshots): only the part not yet booked for
			// this payment counts, so a repeated or older snapshot never books an amount twice.
			$booked_sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM %i WHERE order_id=%d AND provider_reference IN (%s,%s) AND status='completed'", $p . 'wpcap_refunds', $order_id, $reference, 'ext:' . sanitize_key( $provider ) . ':' . $reference ) );
			if ( null === $booked_sum || self::db_failed() ) { return self::db_error( $provider, $event_id, 'refund_sum' ); }
			$amount = max( 0, $amount - (int) $booked_sum );
			if ( 0 === $amount && ( $remaining > 0 || $running > 0 ) ) {
				return 'already_booked';
			}
		}
		if ( 0 === $remaining && $running > 0 && ! $chargeback ) {
			// Everything still open is being refunded by the plugin right now: an external refund on top
			// would pay out more than was paid. Nothing is booked (never a refund over 0 €); the admin checks it.
			Audit::record( 'external_refund_during_native_refund', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => $amount ) );
			self::alert( $order, __( 'Beim Zahlungsanbieter wurde eine Erstattung gemeldet, während eine Erstattung über Pass Captain für den ganzen offenen Betrag läuft. Es wurde nichts zusätzlich gebucht; bitte beim Anbieter prüfen, ob doppelt erstattet wurde.', 'pass-captain' ), $provider, $event );
			return 'already_booked';
		}
		$reason = $provider . ( $chargeback ? '_chargeback' : '_dashboard_refund' );
		$full   = 'full' === $scope || $amount >= $remaining;
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
		// Idempotency: per provider refund id when known (the same refund may arrive in several events),
		// per cumulative total for snapshots, else per event.
		$refund_ref = (string) ( $event['refund_id'] ?? '' );
		if ( '' !== $refund_ref ) {
			$key = $provider . ':refund:' . $refund_ref;
		} elseif ( ! empty( $event['cumulative'] ) || 'cumulative' === $scope ) {
			$key = $provider . ':cumulative:' . $reference . ':' . (int) ( $event['amount_minor'] ?? 0 );
		} else {
			$key = $provider . ':' . $event_id;
		}
		$booking_error = null;
		if ( $remaining > 0 && $amount > 0 ) {
			$tickets = $full ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE order_id=%d AND refunded=0', $p . 'wpcap_tickets', $order_id ) ) ) : array();
			$booked  = Refunds::record_external(
				$order_id,
				$tickets,
				$full ? $remaining : $amount,
				$reason,
				$key,
				array( 'provider_reference' => $reference, 'source' => $provider )
			);
			if ( false === $booked || ( is_wp_error( $booked ) && 'wpcap_refund_nothing' === $booked->get_error_code() ) ) {
				$booked = null; // Nothing left to book (0 €): no refund row, no credit note.
			}
			if ( is_wp_error( $booked ) && 'wpcap_refund_state' === $booked->get_error_code() && ! $full ) {
				// Not a paid order any more (e.g. needs_review): no ledger entry possible.
				Audit::record( 'external_partial_refund', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => $amount, 'payment_status' => (string) $order->payment_status ) );
				return 'partial_refund_logged';
			}
			if ( is_wp_error( $booked ) && 'wpcap_refund_state' !== $booked->get_error_code() ) {
				if ( ! $full ) {
					return $booked; // Retried by the provider.
				}
				$booking_error = $booked;
			}
		}
		if ( $full ) {
			// The money is back with the buyer: the order is closed (tickets revoked) even if the refund
			// row could not be written yet.
			$marked = Orders::mark_refunded( $order_id, $reason );
			if ( is_wp_error( $marked ) ) {
				return $marked; // A database error: the provider redelivers.
			}
			if ( $booking_error ) {
				// A264: refund row, credit note and refunded total are still missing. The 5xx makes the
				// provider redeliver the event; record_external() is idempotent per key, so the
				// redelivery books exactly the missing refund (the order is already "refunded").
				Audit::record( 'external_refund_booking_failed', 'order', $order_id, array( 'provider' => $provider, 'event' => $event_id, 'error' => $booking_error->get_error_code() ) );
				return new \WP_Error( 'wpcap_refund_booking_retry', 'Order marked refunded, refund booking failed; retry later.', array( 'status' => 503, 'retryable' => true ) );
			}
			return 'refunded';
		}
		return 'partial_refund';
	}

	/** Hold length (minutes) for a delayed payment method (filter wp_pass_captain_delayed_payment_hold_minutes_v1). */
	public static function delayed_hold_minutes( $order, $provider ) {
		return max( 1, (int) apply_filters( 'wp_pass_captain_delayed_payment_hold_minutes_v1', 7 * DAY_IN_SECONDS / MINUTE_IN_SECONDS, $order, (string) $provider ) );
	}

	/**
	 * Extends the active hold of a pending order whose payment uses a delayed method (default 7 days,
	 * filter `wp_pass_captain_delayed_payment_hold_minutes_v1`), but never past the start of the
	 * earliest occurrence of the order: tickets for a started occurrence must not stay blocked by a
	 * transfer that may never arrive. A hold that already lapsed is not revived; the later payment then
	 * goes through the late-payment re-check (Orders).
	 *
	 * @return bool Whether an active hold was extended.
	 */
	public static function extend_for_delayed_payment( $order, $provider ) {
		global $wpdb;
		$minutes = self::delayed_hold_minutes( $order, $provider );
		$p = $wpdb->prefix;
		$starts = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(o.starts_at) FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', (int) $order->order_id ) );
		if ( '' !== $starts ) {
			$minutes = min( $minutes, (int) floor( ( strtotime( $starts . ' UTC' ) - time() ) / MINUTE_IN_SECONDS ) );
		}
		$extended = $minutes > 0 ? (int) Orders::extend_hold( (int) $order->order_id, $minutes ) : 0;
		Audit::record( $extended ? 'delayed_payment_hold_extended' : 'delayed_payment_hold_lapsed', 'order', (int) $order->order_id, array( 'provider' => (string) $provider, 'minutes' => max( 0, $minutes ) ) );
		return $extended > 0;
	}

	/**
	 * Whether the last query failed (re-read after every query).
	 *
	 * @phpstan-impure
	 */
	private static function db_failed() {
		global $wpdb;
		return '' !== (string) $wpdb->last_error;
	}

	/** Mails the site admin about a payment that needs manual action. */
	private static function alert( $order, $message, $provider, array $event ) {
		/* translators: 1: order id, 2: provider, 3: reference, 4: message */
		wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Zahlung prüfen', 'pass-captain' ), sprintf( __( "Bestellung %1\$s (%2\$s, Referenz %3\$s):\n\n%4\$s", 'pass-captain' ), $order->public_id, $provider, (string) ( $event['provider_reference'] ?? '' ), $message ) );
	}
}
