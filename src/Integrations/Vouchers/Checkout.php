<?php
/**
 * Orchestrates Voucher Captain redemptions for native orders through the versioned Bridge.
 *
 * Flow: quote + reserve after the order (and its inventory hold) exists → amount due reduced →
 * commit after confirmed payment → release on cancel/expiry → proportional refund on ticket refunds.
 * Provider calls never run inside a Pass Captain database transaction.
 *
 * Expected provider data: quote() returns an array with `amount_minor` (discount/credit applied, minor
 * units, same currency); reserve()/commit() return arrays that are stored and handed back unchanged.
 * Optional: the voucher purpose (see purpose()) as `purpose`, `voucher_purpose`, `voucher_type`,
 * `tax_type` ('single'|'multi', 'SPV'|'MPV', 'einzweck'|'mehrzweck') or the booleans `single_purpose` /
 * `multi_purpose`, on the quote, the reservation or a nested `voucher` array.
 *
 * VAT treatment (EU VAT Directive Art. 30a/30b, DE §3 Abs. 13–15 UStG, AT §4 Abs. 9 UStG):
 * - Single-purpose voucher ("single"): VAT was due when the voucher was sold, so the redemption is a
 *   price reduction: total, discount and line VAT are reduced (unchanged legacy behaviour).
 * - Multi-purpose voucher ("multi", default): the redemption is a means of payment. Order lines,
 *   discount_minor and tax_minor keep the full consideration; only the amount due (orders.total_minor,
 *   which every gateway, webhook amount check and refund cap uses as the cash amount) is reduced. The
 *   voucher part is stored in the voucher snapshot (`amount_paid_by_voucher`, `gross_minor`), invoices
 *   show the full gross amount and list the voucher under "Zahlungen".
 * Refunds are split proportionally: the cash part comes from Refunds::calculate() (gross share × amount
 * due / subtotal), the voucher part is the same share of the voucher amount (by ticket value; the last
 * refund receives the remainder) and goes back to the voucher. Credit notes of multi-purpose orders cover
 * cash + voucher part with the full VAT of the refunded tickets (see credit_note_amounts()).
 */
namespace PassCaptain\Integrations\Vouchers;

use PassCaptain\Audit;
use PassCaptain\Jobs;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Checkout {
	/** Job type for commit retries (Jobs queue). */
	const JOB = 'voucher_commit';
	/** Retry of a voucher share refund that failed temporarily. */
	const REFUND_JOB = 'voucher_refund';
	/** The Jobs queue moves a job to dead_letter after this many attempts; the last one flags the order. */
	const FINAL_ATTEMPT = 8;
	/** Voucher purposes (VAT treatment). */
	const PURPOSE_SINGLE = 'single';
	const PURPOSE_MULTI = 'multi';

	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'commit' ) );
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 4 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'release' ) );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund' ), 20, 2 );
	}

	/**
	 * Applies a voucher code to a pending order.
	 *
	 * @return array|\WP_Error ['applied_minor'=>int, 'total_minor'=>int (amount due), 'amount_due_minor'=>int, 'purpose'=>string]
	 */
	public static function apply( $order_id, $code ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) { return array( 'applied_minor' => 0 ); }
		if ( ! Bridge::available() ) {
			return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Gutscheine können derzeit nicht eingelöst werden.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$order = Orders::get( $order_id );
		if ( ! $order || 'pending' !== $order->payment_status || $order->voucher_snapshot ) {
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$items = Orders::items_for_order( $order_id );
		$context = array(
			'code' => $code,
			'currency' => $order->currency,
			'amount_minor' => (int) $order->total_minor,
			'tax_minor' => (int) $order->tax_minor,
			'customer_email' => $order->customer_email,
			'order_reference' => 'wpcap:' . $order->public_id,
			'lines' => array_map( static function ( $item ) {
				return array( 'ticket_type_id' => (int) $item->ticket_type_id, 'event' => $item->event_name, 'name' => $item->name, 'quantity' => (int) $item->quantity, 'line_total_minor' => (int) $item->line_total_minor, 'tax_rate_bp' => (int) $item->tax_rate_bp );
			}, $items ),
		);
		$quote = Bridge::quote( $context );
		if ( is_wp_error( $quote ) ) { return self::user_error( $quote ); }
		$amount = (int) ( $quote['amount_minor'] ?? 0 );
		if ( $amount < 1 || $amount > (int) $order->total_minor || strtoupper( (string) ( $quote['currency'] ?? $order->currency ) ) !== strtoupper( $order->currency ) ) {
			return new \WP_Error( 'wpcap_voucher_quote_invalid', __( 'Der Gutschein passt nicht zu dieser Bestellung.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$key = 'wpcap-order-' . $order->public_id;
		$reservation = Bridge::reserve( $quote, $key );
		if ( is_wp_error( $reservation ) ) { return self::user_error( $reservation ); }

		$old_total = (int) $order->total_minor;
		$new_total = $old_total - $amount;
		$purpose = self::purpose( array( 'quote' => $quote, 'reservation' => $reservation ), array( 'source' => 'checkout', 'order_id' => (int) $order_id, 'code_hint' => substr( $code, -4 ) ) );
		$snapshot = array( 'code_hint' => substr( $code, -4 ), 'amount_minor' => $amount, 'purpose' => $purpose, 'gross_minor' => $old_total, 'gross_tax_minor' => (int) $order->tax_minor, 'quote' => $quote, 'reservation' => $reservation, 'status' => 'reserved', 'refunded_minor' => 0, 'refunded_tickets' => 0 );
		// One transaction, every write checked; the order row only changes while it is still pending, unchanged
		// and without a voucher (a concurrent retry or an expiry in between wins).
		$guard = $wpdb->prepare( " WHERE order_id=%d AND payment_status='pending' AND total_minor=%d AND (voucher_snapshot IS NULL OR voucher_snapshot='')", (int) $order_id, $old_total );
		$wpdb->query( 'START TRANSACTION' );
		$ok = true;
		if ( self::PURPOSE_MULTI === $purpose ) {
			// Means of payment: lines, discount and VAT keep the full consideration; only the amount due shrinks.
			$snapshot['amount_paid_by_voucher'] = $amount;
			$snapshot['amount_due_minor'] = $new_total;
			$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET total_minor=%d,voucher_snapshot=%s,updated_at=%s", $new_total, wp_json_encode( $snapshot ), current_time( 'mysql', true ) ) . $guard ) && 1 === (int) $wpdb->rows_affected; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $guard is prepared.
		} else {
			// Single-purpose: VAT was paid when the voucher was sold; the redemption reduces price and VAT.
			$new_tax = $old_total > 0 ? (int) round( (int) $order->tax_minor * $new_total / $old_total ) : 0;
			// Keep line VAT consistent with the reduced total (proportional, remainder on the last line).
			$tax_left = $new_tax;
			$last = count( $items ) - 1;
			foreach ( $items as $index => $item ) {
				$line_tax = $index === $last ? $tax_left : ( (int) $order->tax_minor > 0 ? (int) round( (int) $item->tax_minor * $new_tax / (int) $order->tax_minor ) : 0 );
				$line_tax = max( 0, min( $line_tax, $tax_left ) );
				$tax_left -= $line_tax;
				$ok = $ok && false !== $wpdb->update( "{$p}wpcap_order_items", array( 'tax_minor' => $line_tax ), array( 'item_id' => (int) $item->item_id ) );
			}
			$snapshot['amount_paid_by_voucher'] = 0;
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET total_minor=%d,discount_minor=discount_minor+%d,tax_minor=%d,voucher_snapshot=%s,updated_at=%s", $new_total, $amount, $new_tax, wp_json_encode( $snapshot ), current_time( 'mysql', true ) ) . $guard ) && 1 === (int) $wpdb->rows_affected; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $guard is prepared.
		}
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			$current = Orders::get( $order_id );
			// The reservation key is shared with a concurrent request that applied it: keep it then.
			if ( ! $current || ! $current->voucher_snapshot ) {
				Bridge::release( (array) $reservation, 'wpcap-release-' . $order->public_id );
			}
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'voucher_reserved', 'order', (int) $order_id, array( 'amount_minor' => $amount, 'purpose' => $purpose ) );
		if ( 0 === $new_total ) {
			// Fully paid by voucher: confirm like a free order (commit follows via wpcap_order_paid).
			Orders::confirm_external_payment( (int) $order_id, 'voucher' );
		}
		return array( 'applied_minor' => $amount, 'total_minor' => $new_total, 'amount_due_minor' => $new_total, 'purpose' => $purpose );
	}

	/**
	 * Commits the voucher after payment (wpcap_order_paid).
	 *
	 * - Reservation released before the payment arrived (order expired, late payment recovered):
	 *   the voucher is reserved again with the stored quote and then committed.
	 * - Temporary provider errors: retried through the Jobs queue (same idempotency keys).
	 * - Anything else (voucher used up, voided, provider gone for good): the order keeps its tickets
	 *   but the voucher snapshot is marked `commit_failed`, audited and the admin is emailed –
	 *   never a silent discount without a voucher charge.
	 *
	 * @return bool True when nothing is left to do, false when a retry is scheduled.
	 */
	public static function commit( $order_id, $final = false ) {
		$order_id = absint( $order_id );
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || ! in_array( $snapshot['status'] ?? '', array( 'reserved', 'released' ), true ) ) { return true; }
		$order = Orders::get( $order_id );
		// Refunded or cancelled before the (retried) commit: the voucher is not charged at all.
		if ( 'reserved' === $snapshot['status'] && $order && ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) || in_array( $order->payment_status, array( 'refunded', 'cancelled' ), true ) ) ) {
			$released = Bridge::release( (array) $snapshot['reservation'], 'wpcap-release-' . $order->public_id );
			if ( is_wp_error( $released ) ) {
				Audit::record( 'voucher_release_failed', 'order', $order_id, array( 'error' => $released->get_error_code() ) );
				return true;
			}
			$snapshot['status'] = 'released';
			unset( $snapshot['pending_refund_ids'] );
			self::save( $order_id, $snapshot );
			Audit::record( 'voucher_released', 'order', $order_id, array( 'reason' => $order->status ) );
			return true;
		}
		if ( 'released' === $snapshot['status'] ) {
			if ( ! $order || 'paid' !== $order->payment_status ) { return true; }
			$again = self::reserve_again( $order_id, $order, $snapshot );
			if ( is_wp_error( $again ) ) { return self::reserve_again_error( $order_id, $snapshot, $again, $final ); }
			$snapshot = $again;
		}
		$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-' . ( $order ? $order->public_id : $order_id ) );
		// Released on the voucher side (reservation TTL, cron, API) while the order waited for payment.
		if ( is_wp_error( $result ) && 'vcap_reservation_missing' === $result->get_error_code() && empty( $snapshot['late_reservation'] ) && $order && 'paid' === $order->payment_status ) {
			$again = self::reserve_again( $order_id, $order, $snapshot );
			if ( is_wp_error( $again ) ) { return self::reserve_again_error( $order_id, $snapshot, $again, $final ); }
			$snapshot = $again;
			$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-late-' . $order->public_id );
		}
		if ( is_wp_error( $result ) ) { return self::commit_error( $order_id, $snapshot, $result, $final ); }
		$pending = array_map( 'absint', (array) ( $snapshot['pending_refund_ids'] ?? array() ) );
		$snapshot['status'] = 'committed';
		$snapshot['redemption'] = $result;
		unset( $snapshot['error'], $snapshot['pending_refund_ids'] );
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_committed', 'order', $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
		// Ticket refunds made while the commit was still pending receive their voucher share now.
		foreach ( $pending as $refund_id ) { self::refund( $refund_id, $order_id ); }
		return true;
	}

	/**
	 * Reserves the voucher again with the stored quote (late payment after a release on either side).
	 *
	 * @return array|\WP_Error Updated snapshot.
	 */
	private static function reserve_again( $order_id, $order, array $snapshot ) {
		$again = Bridge::reserve( (array) ( $snapshot['quote'] ?? array() ), 'wpcap-order-late-' . $order->public_id );
		if ( is_wp_error( $again ) ) { return $again; }
		$amount = $again['applied_minor'] ?? ( $again['amount_minor'] ?? null );
		if ( null !== $amount && (int) $amount !== (int) $snapshot['amount_minor'] ) {
			Bridge::release( (array) $again, 'wpcap-release-late-' . $order->public_id );
			return new \WP_Error( 'wpcap_voucher_amount_changed', __( 'Der Gutschein deckt den ursprünglichen Betrag nicht mehr.', 'wp-pass-captain' ) );
		}
		$snapshot['reservation'] = $again;
		$snapshot['status'] = 'reserved';
		$snapshot['late_reservation'] = true;
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_reserved_again', 'order', $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
		return $snapshot;
	}

	private static function reserve_again_error( $order_id, array $snapshot, \WP_Error $error, $final ) {
		return 'wpcap_voucher_amount_changed' === $error->get_error_code() ? self::needs_review( $order_id, $snapshot, $error ) : self::commit_error( $order_id, $snapshot, $error, $final );
	}

	/** Filter wp_pass_captain_process_job_v1: retries a voucher commit. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( self::JOB !== $type && self::REFUND_JOB !== $type ) { return $handled; }
		global $wpdb;
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$wpdb->prefix}wpcap_jobs WHERE job_id=%d", absint( $job_id ) ) );
		if ( self::REFUND_JOB === $type ) {
			return self::refund( absint( $payload['refund_id'] ?? 0 ), absint( $payload['order_id'] ?? 0 ), true, $attempts >= self::FINAL_ATTEMPT );
		}
		return self::commit( absint( $payload['order_id'] ?? 0 ), $attempts >= self::FINAL_ATTEMPT );
	}

	/** Whether a provider error may go away by itself (outage, plugin update, lock timeout). */
	public static function is_temporary( \WP_Error $error ) {
		$data = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		return $status >= 500 || in_array( $error->get_error_code(), array( 'vcap_temporary_failure', 'wpcap_voucher_unavailable' ), true );
	}

	private static function commit_error( $order_id, array $snapshot, \WP_Error $error, $final ) {
		if ( ! $final && self::is_temporary( $error ) ) {
			Audit::record( 'voucher_commit_failed', 'order', (int) $order_id, array( 'error' => $error->get_error_code(), 'retry' => true ) );
			Jobs::enqueue( 'voucher-commit-' . (int) $order_id, self::JOB, array( 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ) );
			return false;
		}
		return self::needs_review( $order_id, $snapshot, $error );
	}

	/** Paid, tickets issued, but the voucher could not be charged: flag, audit and alert. */
	private static function needs_review( $order_id, array $snapshot, \WP_Error $error ) {
		$snapshot['status'] = 'commit_failed';
		$snapshot['error'] = $error->get_error_code();
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_commit_failed', 'order', (int) $order_id, array( 'error' => $error->get_error_code(), 'retry' => false ) );
		$order = Orders::get( $order_id );
		wp_mail(
			get_option( 'admin_email' ),
			__( 'WP Pass Captain: Gutschein konnte nicht belastet werden', 'wp-pass-captain' ),
			sprintf(
				/* translators: 1: order id, 2: amount with currency, 3: last code characters, 4: error code */
				__( 'Bestellung %1$s ist bezahlt und die Tickets wurden ausgestellt, aber der Gutschein (…%3$s, %2$s) konnte in WP Voucher Captain nicht belastet werden (Fehler: %4$s). Bitte den Betrag beim Kunden nachfordern oder den Gutschein manuell belasten.', 'wp-pass-captain' ),
				$order ? $order->public_id : (int) $order_id,
				\PassCaptain\Admin\Forms::money( (int) $snapshot['amount_minor'], $order ? $order->currency : 'EUR' ),
				(string) ( $snapshot['code_hint'] ?? '' ),
				$error->get_error_code()
			)
		);
		return true;
	}

	/** Voucher snapshot of an order for display (null when no voucher was used). */
	public static function voucher_state( $order_id ) {
		return self::snapshot( $order_id );
	}

	/** Releases an unused reservation when an unpaid order is cancelled or expires. */
	public static function release( $order_id ) {
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || 'reserved' !== $snapshot['status'] ) { return; }
		$order = Orders::get( $order_id );
		if ( $order && 'paid' === $order->payment_status ) { return; }
		$result = Bridge::release( (array) $snapshot['reservation'], 'wpcap-release-' . $order->public_id );
		if ( is_wp_error( $result ) ) {
			Audit::record( 'voucher_release_failed', 'order', (int) $order_id, array( 'error' => $result->get_error_code() ) );
			return;
		}
		$snapshot['status'] = 'released';
		self::save( $order_id, $snapshot );
	}

	/**
	 * Refunds the voucher share of a ticket refund back to the voucher (Voucher Captain decides how value
	 * is restored). The share comes from refund_plan(): proportional to the value of the refunded tickets,
	 * the last refund receives the remainder. The cash part was refunded by the payment gateway.
	 */
	/**
	 * @param bool $from_job      Called by the retry job (no new job is queued; false = try again later).
	 * @param bool $final_attempt Last retry: a failure is flagged instead of retried.
	 * @return bool True when the voucher share is settled (or nothing is to do), false while a retry is due.
	 */
	public static function refund( $refund_id, $order_id, $from_job = false, $final_attempt = false ) {
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot ) { return true; }
		$done_ids = array_map( 'absint', (array) ( $snapshot['refund_ids'] ?? array() ) );
		if ( in_array( absint( $refund_id ), $done_ids, true ) ) { return true; }
		if ( 'reserved' === $snapshot['status'] ) {
			// Commit still pending (retry job): the share is refunded right after the commit.
			$pending = array_map( 'absint', (array) ( $snapshot['pending_refund_ids'] ?? array() ) );
			if ( ! in_array( absint( $refund_id ), $pending, true ) ) {
				$snapshot['pending_refund_ids'] = array_merge( $pending, array( absint( $refund_id ) ) );
				self::save( $order_id, $snapshot );
			}
			return true;
		}
		if ( 'committed' !== $snapshot['status'] ) { return true; }
		$plan = self::refund_plan( $refund_id );
		$snapshot = self::snapshot( $order_id ); // refund_plan() may have stored the plan.
		if ( ! $plan ) { return true; }
		$share = (int) $plan['voucher_minor'];
		if ( $share < 1 ) {
			$snapshot['refunded_tickets'] = (int) $snapshot['refunded_tickets'] + (int) $plan['tickets'];
			$snapshot['refund_ids'] = array_merge( $done_ids, array( absint( $refund_id ) ) );
			self::save( $order_id, $snapshot );
			return true;
		}
		$result = Bridge::refund( (array) ( $snapshot['redemption'] ?? array() ), $share, 'wpcap-refund-' . absint( $refund_id ) );
		if ( is_wp_error( $result ) ) {
			// Never lose the voucher share: temporary errors are retried with the same refund key (the voucher
			// system refunds each key once), a final one is flagged and the admin is told.
			$final = $from_job ? $final_attempt : ! self::is_temporary( $result );
			Audit::record( 'voucher_refund_failed', 'order', (int) $order_id, array( 'refund_id' => absint( $refund_id ), 'error' => $result->get_error_code(), 'retry' => ! $final ) );
			$snapshot['failed_refund_ids'] = array_values( array_unique( array_merge( array_map( 'absint', (array) ( $snapshot['failed_refund_ids'] ?? array() ) ), array( absint( $refund_id ) ) ) ) );
			self::save( $order_id, $snapshot );
			if ( $final ) {
				$order = \PassCaptain\Orders::get( (int) $order_id );
				/* translators: 1: order, 2: amount in minor units, 3: error */
				wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Gutschein-Rückbuchung fehlgeschlagen', 'wp-pass-captain' ), sprintf( __( "Bei Bestellung %1\$s konnte der Gutscheinanteil einer Erstattung (%2\$d in kleinster Einheit) nicht auf den Gutschein zurückgebucht werden:\n\n%3\$s\n\nBitte den Gutschein im Gutschein-Plugin manuell korrigieren.", 'wp-pass-captain' ), $order ? $order->public_id : (string) $order_id, $share, $result->get_error_message() ) );
			} elseif ( ! $from_job ) {
				Jobs::enqueue( 'voucher-refund-' . absint( $refund_id ), self::REFUND_JOB, array( 'refund_id' => absint( $refund_id ), 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ) );
			}
			return $final;
		}
		$snapshot['refunded_minor'] = (int) $snapshot['refunded_minor'] + $share;
		$snapshot['refunded_tickets'] = (int) $snapshot['refunded_tickets'] + (int) $plan['tickets'];
		$snapshot['refund_ids'] = array_merge( $done_ids, array( absint( $refund_id ) ) );
		$snapshot['failed_refund_ids'] = array_values( array_diff( array_map( 'absint', (array) ( $snapshot['failed_refund_ids'] ?? array() ) ), array( absint( $refund_id ) ) ) );
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_refunded', 'order', (int) $order_id, array( 'refund_id' => absint( $refund_id ), 'amount_minor' => $share, 'purpose' => self::snapshot_purpose( $snapshot ) ) );
		return true;
	}

	/**
	 * Split of a completed ticket refund into cash part (refund row, paid back by the gateway) and voucher
	 * part, computed once and stored in the voucher snapshot (`refund_plans`) so that the credit note and
	 * the voucher refund use the same numbers.
	 *
	 * Voucher part = voucher amount × value of the refunded tickets / value of all tickets, the refund that
	 * covers the last tickets receives the remainder. For multi-purpose vouchers also the full VAT of the
	 * refunded tickets (`tax_minor`, cash + voucher part) for the credit note.
	 *
	 * @return array|null voucher_minor, cash_minor, tax_minor (full VAT, multi only), tickets, purpose.
	 */
	public static function refund_plan( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund = \PassCaptain\Refunds::get( $refund_id );
		if ( ! $refund ) { return null; }
		$order_id = (int) $refund['order_id'];
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || ! in_array( $snapshot['status'] ?? '', array( 'committed', 'commit_failed', 'external' ), true ) ) { return null; }
		$plans = (array) ( $snapshot['refund_plans'] ?? array() );
		if ( isset( $plans[ (string) absint( $refund_id ) ] ) ) { return (array) $plans[ (string) absint( $refund_id ) ]; }
		$ticket_ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) ) );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,quantity,line_total_minor,tax_minor FROM {$p}wpcap_order_items WHERE order_id=%d", $order_id ) );
		$unit = array();
		$all_value = 0;
		$all_tickets = 0;
		foreach ( (array) $items as $item ) {
			$q = max( 1, (int) $item->quantity );
			$unit[ (int) $item->ticket_type_id ] = array( (int) $item->line_total_minor / $q, (int) $item->tax_minor / $q );
			$all_value += (int) $item->line_total_minor;
			$all_tickets += (int) $item->quantity;
		}
		$value = 0.0;
		$line_tax = 0.0;
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_id IN ($ids)", $order_id ) ) as $type_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$value += $unit[ (int) $type_id ][0] ?? 0;
				$line_tax += $unit[ (int) $type_id ][1] ?? 0;
			}
		}
		$voucher = (int) $snapshot['amount_minor'];
		$planned = 0;
		$planned_tickets = 0;
		foreach ( $plans as $other ) {
			$planned += (int) ( $other['voucher_minor'] ?? 0 );
			$planned_tickets += (int) ( $other['tickets'] ?? 0 );
		}
		// Refunds handled before plans existed (snapshots of older versions).
		$planned = max( $planned, (int) ( $snapshot['refunded_minor'] ?? 0 ) );
		$planned_tickets = max( $planned_tickets, (int) ( $snapshot['refunded_tickets'] ?? 0 ) );
		$tickets = count( $ticket_ids );
		$final = $tickets > 0 && $planned_tickets + $tickets >= $all_tickets;
		if ( ! $tickets ) {
			$share = 0; // Amount-only refunds are paid from the cash part.
		} elseif ( $final ) {
			$share = $voucher - $planned;
		} else {
			// Free tickets (single-purpose voucher from the ticket service, lines at 0): share by ticket count.
			$share = $all_value > 0 ? (int) floor( $voucher * $value / $all_value ) : ( $all_tickets > 0 ? (int) floor( $voucher * $tickets / $all_tickets ) : 0 );
		}
		$share = max( 0, min( $share, $voucher - $planned ) );
		$purpose = self::snapshot_purpose( $snapshot );
		$plan = array( 'voucher_minor' => $share, 'cash_minor' => (int) $refund['amount_minor'], 'tickets' => $tickets, 'purpose' => $purpose );
		if ( self::PURPOSE_MULTI === $purpose ) {
			$order = Orders::get( $order_id );
			$gross = (int) $refund['amount_minor'] + $share;
			if ( $final ) {
				// The remaining VAT of the order, minus what earlier refunds of this order already corrected.
				$earlier = 0;
				foreach ( \PassCaptain\Refunds::for_order( $order_id ) as $other ) {
					if ( (int) $other['refund_id'] === absint( $refund_id ) || 'completed' !== $other['status'] ) { continue; }
					$earlier += isset( $plans[ (string) (int) $other['refund_id'] ] ) ? (int) ( $plans[ (string) (int) $other['refund_id'] ]['tax_minor'] ?? $other['tax_minor'] ) : (int) $other['tax_minor'];
				}
				$tax = max( 0, (int) $order->tax_minor - $earlier );
			} else {
				// The VAT booked on the refunded tickets' lines (already on the discounted amount).
				$tax = $value > 0 ? (int) round( $line_tax ) : (int) $refund['tax_minor'];
			}
			$plan['tax_minor'] = max( 0, min( $gross, $tax ) );
		}
		$plans[ (string) absint( $refund_id ) ] = $plan;
		$snapshot['refund_plans'] = $plans;
		self::save( $order_id, $snapshot );
		return $plan;
	}

	/**
	 * Amounts for the credit note of a refund: for multi-purpose vouchers the correction covers the full
	 * consideration (cash part + voucher part) and its VAT; null = use the refund row unchanged.
	 *
	 * @return array|null total_minor, tax_minor, voucher_minor
	 */
	public static function credit_note_amounts( $refund_id ) {
		$plan = self::refund_plan( $refund_id );
		if ( ! $plan || self::PURPOSE_MULTI !== ( $plan['purpose'] ?? '' ) || (int) $plan['voucher_minor'] < 1 ) { return null; }
		return array(
			'total_minor' => (int) $plan['cash_minor'] + (int) $plan['voucher_minor'],
			'tax_minor' => (int) $plan['tax_minor'],
			'voucher_minor' => (int) $plan['voucher_minor'],
		);
	}

	/**
	 * Amounts of a completed refund for reports, organizer settlements and reseller statements, read from
	 * the stored refund plan (`refund_plans`, see refund_plan()) instead of the cash-only refund row:
	 * - multi-purpose voucher: amount = cash part + voucher share, tax = full VAT of the refunded tickets
	 *   (the same numbers as the credit note, credit_note_amounts()),
	 * - single-purpose voucher: amount/tax of the refund row; voucher_minor is the voucher value given back
	 *   (reverses the organizer's claim against the voucher seller),
	 * - no voucher: the refund row unchanged.
	 *
	 * @param object|array $refund Refund row with refund_id, order_id, amount_minor, tax_minor; an optional
	 *                             `has_voucher` (0/1) skips the lookup for orders without voucher.
	 * @return array{amount_minor:int,tax_minor:int,voucher_minor:int,purpose:string|null}
	 */
	public static function refund_amounts( $refund ) {
		$refund = (object) $refund;
		$out = array( 'amount_minor' => (int) ( $refund->amount_minor ?? 0 ), 'tax_minor' => (int) ( $refund->tax_minor ?? 0 ), 'voucher_minor' => 0, 'purpose' => null );
		if ( isset( $refund->has_voucher ) && ! (int) $refund->has_voucher ) { return $out; }
		$refund_id = absint( $refund->refund_id ?? 0 );
		$plan = $refund_id ? self::refund_plan( $refund_id ) : null;
		if ( ! $plan ) { return $out; }
		$out['purpose'] = (string) ( $plan['purpose'] ?? '' );
		$out['voucher_minor'] = max( 0, (int) ( $plan['voucher_minor'] ?? 0 ) );
		if ( self::PURPOSE_MULTI === $out['purpose'] && $out['voucher_minor'] > 0 ) {
			$out['amount_minor'] = (int) $plan['cash_minor'] + $out['voucher_minor'];
			$out['tax_minor'] = (int) ( $plan['tax_minor'] ?? $out['tax_minor'] );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Voucher purpose (VAT treatment) */

	/**
	 * Purpose of a voucher: 'single' (Einzweckgutschein) or 'multi' (Mehrzweckgutschein).
	 *
	 * 1. Data from Voucher Captain (quote, reservation, source …; see purpose_from_data()),
	 * 2. filter `wpcap_voucher_purpose( $purpose|null, $voucher, $context )` (may decide or override),
	 * 3. setting `voucher_default_purpose` ('multi' by default).
	 *
	 * @param array $voucher Provider data of the voucher (any nesting of quote/reservation/voucher).
	 * @param array $context source ('checkout'|'ticket_service'), order_id, code_hint, …
	 */
	public static function purpose( array $voucher, array $context = array() ) {
		$purpose = apply_filters( 'wpcap_voucher_purpose', self::purpose_from_data( $voucher ), $voucher, $context );
		$purpose = self::normalize_purpose( $purpose );
		if ( null === $purpose ) {
			$purpose = self::normalize_purpose( \PassCaptain\Settings::get( 'voucher_default_purpose' ) ) ?? self::PURPOSE_MULTI;
		}
		return $purpose;
	}

	/** Purpose stated in provider data (common keys, also nested in quote/reservation/voucher), or null. */
	public static function purpose_from_data( $data, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 3 ) { return null; }
		// Contract field of ProviderInterface::quote(): payment = multi-purpose voucher; single and
		// discount = price reduction (single-purpose voucher / plain discount).
		if ( isset( $data['treatment'] ) && is_scalar( $data['treatment'] ) ) {
			$treatment = strtolower( trim( (string) $data['treatment'] ) );
			if ( 'payment' === $treatment ) { return self::PURPOSE_MULTI; }
			if ( in_array( $treatment, array( 'single', 'discount' ), true ) ) { return self::PURPOSE_SINGLE; }
		}
		foreach ( array( 'purpose', 'voucher_purpose', 'voucher_type', 'tax_type', 'vat_type', 'tax_treatment' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$found = self::normalize_purpose( $data[ $key ] );
				if ( null !== $found ) { return $found; }
			}
		}
		foreach ( array( 'single_purpose' => self::PURPOSE_SINGLE, 'is_single_purpose' => self::PURPOSE_SINGLE, 'multi_purpose' => self::PURPOSE_MULTI, 'is_multi_purpose' => self::PURPOSE_MULTI ) as $key => $value ) {
			if ( array_key_exists( $key, $data ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$flag = filter_var( $data[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $flag ) { continue; }
				return $flag ? $value : ( self::PURPOSE_SINGLE === $value ? self::PURPOSE_MULTI : self::PURPOSE_SINGLE );
			}
		}
		foreach ( array( 'voucher', 'quote', 'reservation', 'redemption', 'source', 'meta' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$found = self::purpose_from_data( $data[ $key ], $depth + 1 );
				if ( null !== $found ) { return $found; }
			}
		}
		return null;
	}

	/** 'single'|'multi' for the usual spellings (SPV/MPV, Einzweck/Mehrzweck …), null for anything else. */
	public static function normalize_purpose( $value ) {
		if ( ! is_scalar( $value ) ) { return null; }
		$v = preg_replace( '/[^a-z]/', '', strtolower( (string) $value ) );
		if ( in_array( $v, array( 'single', 'singlepurpose', 'singlepurposevoucher', 'spv', 'einzweck', 'einzweckgutschein' ), true ) ) { return self::PURPOSE_SINGLE; }
		if ( in_array( $v, array( 'multi', 'multipurpose', 'multipurposevoucher', 'mpv', 'mehrzweck', 'mehrzweckgutschein' ), true ) ) { return self::PURPOSE_MULTI; }
		return null;
	}

	/** Purpose recorded in a snapshot; snapshots of older versions were always treated as discounts. */
	public static function snapshot_purpose( array $snapshot ) {
		return self::normalize_purpose( $snapshot['purpose'] ?? '' ) ?? self::PURPOSE_SINGLE;
	}

	/**
	 * Amount of an order paid with a multi-purpose voucher (0 for single-purpose vouchers, which reduce the
	 * price, and for orders without voucher). A released reservation of an unpaid order does not count.
	 */
	public static function voucher_payment_minor( $order ) {
		$order = is_object( $order ) ? $order : Orders::get( absint( $order ) );
		if ( ! $order || empty( $order->voucher_snapshot ) ) { return 0; }
		$snapshot = (array) json_decode( (string) $order->voucher_snapshot, true );
		if ( self::PURPOSE_MULTI !== self::snapshot_purpose( $snapshot ) ) { return 0; }
		if ( 'released' === ( $snapshot['status'] ?? '' ) && 'paid' !== $order->payment_status ) { return 0; }
		return (int) ( $snapshot['amount_paid_by_voucher'] ?? $snapshot['amount_minor'] ?? 0 );
	}

	/**
	 * Value of a single-purpose voucher redeemed on a paid order (booked as a discount on the order,
	 * VAT was due when the voucher was sold). For ticket service exchanges (free tickets) the stored voucher
	 * value `face_value_minor` (TicketService::record_voucher()). 0 otherwise.
	 */
	public static function single_purpose_minor( $order ) {
		$order = is_object( $order ) ? $order : Orders::get( absint( $order ) );
		if ( ! $order || empty( $order->voucher_snapshot ) ) { return 0; }
		$snapshot = (array) json_decode( (string) $order->voucher_snapshot, true );
		if ( self::PURPOSE_SINGLE !== self::snapshot_purpose( $snapshot ) || 'released' === ( $snapshot['status'] ?? '' ) ) { return 0; }
		return max( 0, (int) ( $snapshot['face_value_minor'] ?? $snapshot['applied_minor'] ?? $snapshot['amount_minor'] ?? 0 ) );
	}

	/** Full gross consideration of an order (amount due + multi-purpose voucher payment). */
	public static function gross_minor( $order ) {
		$order = is_object( $order ) ? $order : Orders::get( absint( $order ) );
		return $order ? (int) $order->total_minor + self::voucher_payment_minor( $order ) : 0;
	}

	/**
	 * Payments of an order for the invoice section "Zahlungen" (frozen in the invoice snapshot).
	 *
	 * @return array{vouchers: array<int, array{code_hint:string,amount_minor:int,purpose:string}>, paid_minor:int, provider:string}|null
	 */
	public static function invoice_payments( $order ) {
		$order = is_object( $order ) ? $order : Orders::get( absint( $order ) );
		if ( ! $order || empty( $order->voucher_snapshot ) ) { return null; }
		$snapshot = (array) json_decode( (string) $order->voucher_snapshot, true );
		$purpose = self::snapshot_purpose( $snapshot );
		return array(
			'vouchers' => array(
				array(
					'code_hint' => mb_substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $snapshot['code_hint'] ?? '' ) ), -4 ),
					'amount_minor' => (int) ( $snapshot['amount_minor'] ?? 0 ),
					'purpose' => $purpose,
				),
			),
			'paid_minor' => (int) $order->total_minor,
			'provider' => (string) $order->payment_provider,
		);
	}

	/** Masked voucher code for documents ("Gutschein …1234"). */
	public static function masked_code( $hint ) {
		$hint = (string) $hint;
		return '' === $hint ? '…' : '…' . $hint;
	}

	private static function snapshot( $order_id ) {
		$order = Orders::get( $order_id );
		return ( $order && $order->voucher_snapshot ) ? (array) json_decode( $order->voucher_snapshot, true ) : null;
	}

	private static function save( $order_id, array $snapshot ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'voucher_snapshot' => wp_json_encode( $snapshot ) ), array( 'order_id' => absint( $order_id ) ) );
	}

	/** Provider errors are shown to customers without internal details. */
	private static function user_error( \WP_Error $error ) {
		$message = $error->get_error_message() ?: __( 'Der Gutschein konnte nicht eingelöst werden.', 'wp-pass-captain' );
		return new \WP_Error( 'wpcap_voucher_rejected', $message, array( 'status' => 409 ) );
	}
}
