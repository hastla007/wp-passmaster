<?php
/** Shared, idempotent webhook processing for all payment adapters. */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Refunds;

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
		if ( 'payment_pending' === $type ) {
			// A delayed payment method was started (SEPA debit, bank transfer): keep the tickets held until
			// the money can arrive, so its confirmation does not hit an expired hold (QA-6).
			if ( 'pending' !== $order->payment_status || ! self::is_current_attempt( $order, $event ) ) {
				return 'ignored';
			}
			return self::extend_for_delayed_payment( $order, $provider ) ? 'hold_extended' : 'hold_lapsed';
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
			if ( in_array( $order->payment_status, array( 'pending', 'expired' ), true ) && (int) ( $event['amount_minor'] ?? 0 ) >= (int) $order->total_minor ) {
				// Full refund/chargeback of an order that was never paid here (no refund ledger entry is
				// possible): it cancels the order – holds released – and never marks it refunded. A `paid`
				// event arriving later answers `paid_for_closed_order` (admin alert, no tickets). A payment
				// booked in the meantime (checked under the row lock) falls through to the paid handling.
				// Partial refund events of unpaid orders stay audit-only (below).
				$cancelled = Orders::cancel_order( $order_id, $provider . '_refund_unpaid', true );
				if ( true === $cancelled ) {
					Audit::record( 'provider_refund_unpaid_cancelled', 'order', $order_id, array( 'provider' => $provider, 'previous_status' => (string) $order->status, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ), 'reference' => substr( sanitize_text_field( (string) ( $event['provider_reference'] ?? '' ) ), 0, 100 ) ) );
					return 'cancelled_unpaid';
				}
				if ( is_wp_error( $cancelled ) && 'wpcap_order_paid_state' !== $cancelled->get_error_code() ) {
					return $cancelled; // Retryable database error: the provider redelivers.
				}
				$order = Orders::get( $order_id );
				if ( ! $order ) {
					return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
				}
			}
			return self::book_external_refund( $provider, $event_id, $order, $event );
		}
		if ( 'dispute_opened' === $type ) {
			Audit::record( 'payment_dispute_opened', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ), 'reference' => substr( sanitize_text_field( (string) ( $event['provider_reference'] ?? '' ) ), 0, 100 ) ) );
			self::alert( $order, __( 'Für diese Bestellung wurde beim Zahlungsanbieter eine Zahlungsanfechtung (Dispute/Chargeback) eröffnet. Bitte beim Anbieter reagieren. Wird das Geld zurückgebucht, verbucht Pass Captain die Erstattung automatisch und sperrt die Tickets.', 'pass-captain' ), $provider, $event );
			return 'dispute_alerted';
		}
		if ( 'dispute_reinstated' === $type ) {
			Audit::record( 'payment_dispute_reinstated', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ) ) );
			self::alert( $order, __( 'Eine Zahlungsanfechtung wurde zugunsten des Veranstalters entschieden; das Geld wurde wieder gutgeschrieben. Die bereits verbuchte Erstattung und gesperrte Tickets bitte manuell prüfen.', 'pass-captain' ), $provider, $event );
			return 'dispute_reinstated';
		}
		return 'ignored';
	}

	/**
	 * Books money that left outside the plugin (provider dashboard refund, chargeback, PayPal reversal)
	 * in the refund ledger via Refunds::record_external() – credit note, fiscal correction and partner
	 * settlement follow through `wpcap_refund_completed` – and marks the order refunded only once the
	 * whole total is booked.
	 *
	 * Event fields: amount_minor; refund_scope `single` (amount of this one refund, idempotent per
	 * refund_id), `cumulative` (running total of all external refunds/chargebacks of the payment; only the
	 * part not booked yet is recorded) or `full` (the payment is fully refunded at the provider: the
	 * remaining amount is recorded). Without refund_scope an amount covering the remaining total counts
	 * as `full`, anything else as `single` keyed by the event id.
	 *
	 * @return string|\WP_Error
	 */
	private static function book_external_refund( $provider, $event_id, $order, array $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $order->order_id;
		$amount = max( 0, (int) ( $event['amount_minor'] ?? 0 ) );
		$open_amount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM %i WHERE order_id=%d AND status='processing'", $p . 'wpcap_refunds', $order_id ) );
		if ( self::db_failed() ) {
			return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
		}
		// A plugin refund still being paid out is booked by Refunds itself: it is not external money.
		$remaining = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - $open_amount );
		$scope = (string) ( $event['refund_scope'] ?? '' );
		if ( '' === $scope ) {
			$scope = $amount >= $remaining ? 'full' : 'single';
		}
		$ext_prefix = 'ext:' . sanitize_key( $provider ) . ':';
		if ( 'full' === $scope ) {
			$this_refund = $remaining;
			$key = $provider . ':full';
		} elseif ( 'cumulative' === $scope ) {
			$booked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM %i WHERE order_id=%d AND status='completed' AND LEFT(provider_reference,%d)=%s", $p . 'wpcap_refunds', $order_id, strlen( $ext_prefix ), $ext_prefix ) );
			if ( self::db_failed() ) {
				return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
			}
			$this_refund = max( 0, $amount - $booked );
			$key = $provider . ':cumulative:' . $amount;
		} else {
			$this_refund = $amount;
			$key = $provider . ':refund:' . ( '' !== (string) ( $event['refund_id'] ?? '' ) ? (string) $event['refund_id'] : $event_id );
		}
		$this_refund = min( $this_refund, $remaining );
		$reason = sanitize_key( (string) ( $event['refund_reason'] ?? '' ) );
		$reason = $provider . '_' . ( '' !== $reason ? $reason : 'dashboard_refund' );
		if ( $this_refund > 0 ) {
			$reference = $ext_prefix . substr( sanitize_text_field( (string) ( $event['refund_id'] ?? '' ) ?: (string) ( $event['provider_reference'] ?? '' ) ?: $event_id ), 0, 150 );
			// false: under the order lock nothing was left to book (a concurrent refund took the rest) – no
			// zero row is written; the state check below decides like for a redelivery.
			$row = Refunds::record_external( $order_id, array(), $this_refund, $reason, $key, array( 'provider_reference' => $reference, 'source' => $provider ) );
			if ( is_wp_error( $row ) ) {
				if ( 'wpcap_refund_state' !== $row->get_error_code() ) {
					return $row; // Retryable database error: the provider redelivers, the key keeps it idempotent.
				}
				// Not a paid order (e.g. paid without free capacity, needs_review): no ledger entry possible.
				if ( 'full' !== $scope && $amount < $remaining ) {
					Audit::record( 'external_partial_refund', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => $amount, 'payment_status' => (string) $order->payment_status ) );
					return 'partial_refund_logged';
				}
				$refunded = Orders::mark_refunded( $order_id, $reason );
				return is_wp_error( $refunded ) ? $refunded : 'refunded';
			}
		}
		$fresh = Orders::get( $order_id );
		if ( ! $fresh ) {
			return new \WP_Error( 'wpcap_webhook_db_error', 'Database unavailable; retry later.', array( 'status' => 503, 'retryable' => true ) );
		}
		if ( 'full' === $scope || (int) $fresh->refunded_minor >= (int) $fresh->total_minor ) {
			$refunded = Orders::mark_refunded( $order_id, $reason );
			return is_wp_error( $refunded ) ? $refunded : 'refunded';
		}
		// A redelivered refund (same key) returns the existing row and changes nothing.
		return (int) $fresh->refunded_minor > (int) $order->refunded_minor ? 'partial_refund_booked' : 'refund_already_booked';
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
		$minutes = max( 1, (int) apply_filters( 'wp_pass_captain_delayed_payment_hold_minutes_v1', 7 * DAY_IN_SECONDS / MINUTE_IN_SECONDS, $order, (string) $provider ) );
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
