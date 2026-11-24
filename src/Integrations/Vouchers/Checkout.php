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
 *   price reduction: total, discount, the per-line discount split (price_snapshot.line_discounts) and
 *   line VAT are reduced; unsent fiscal documents are rebuilt, a sent one refuses the voucher.
 * - Promotion code ("discount"): no voucher in the sense of the VAT rules (nothing was paid for it), a
 *   plain price reduction – booked like a single-purpose voucher, but the invoice does not claim that VAT
 *   was charged when it was sold, and it creates no claim against a voucher seller.
 * - A single-purpose voucher is one only for supplies with the VAT of its sale: when the provider sends that
 *   VAT (`sale_tax`) and a ticket line has another rate or place of supply, the redemption is booked as a
 *   multi-purpose voucher (see effective_purpose()).
 * - Multi-purpose voucher ("multi", default): the redemption is a means of payment. Order lines,
 *   discount_minor and tax_minor keep the full consideration; only the amount due (orders.total_minor,
 *   which every gateway, webhook amount check and refund cap uses as the cash amount) is reduced. The
 *   voucher part is stored in the voucher snapshot (`amount_paid_by_voucher`, `gross_minor`), invoices
 *   show the full gross amount and list the voucher under "Zahlungen".
 * The voucher is split over the lines at apply time (`voucher_lines`, proportional to the line amounts
 * after campaign discounts). Refunds: the cash part comes from Refunds::calculate() (discounted value of
 * the tickets scaled to the amount due), the voucher part is the tickets' share of `voucher_lines` (the
 * last refund receives the remainder) and goes back to the voucher. Credit notes of multi-purpose orders cover
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
	/** Promotion code of the voucher plugin: reduces price and VAT like a single-purpose voucher, but no VAT was charged in advance. */
	const PURPOSE_DISCOUNT = 'discount';
	/** A claim on the snapshot (one provider operation at a time) older than this is void (crashed request). */
	const CLAIM_TTL = 120;
	/** Attempts of a version-checked snapshot write before giving up. */
	const CAS_TRIES = 8;

	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'commit' ) );
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 4 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'release' ) );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund' ), 20, 2 );
		// A632: a late payment never issues tickets on a discount whose voucher was released.
		add_action( 'wpcap_before_payment_confirm', array( __CLASS__, 'prepare_late_payment' ) );
		add_filter( 'wpcap_order_recovering', array( __CLASS__, 'recovering' ), 10, 2 );
		add_action( 'wpcap_capacity_released', array( __CLASS__, 'on_capacity_released' ), 10, 2 );
	}

	/**
	 * Action wpcap_before_payment_confirm (outside any transaction, A632): an unpaid order whose voucher
	 * reservation was released (hold lapsed, order expired) reserves the voucher again before the payment
	 * is booked – the amount due still carries the voucher discount. If that fails the snapshot stays
	 * `released` and recovering() sends the payment to review (`needs_review`/`paid_unallocated`)
	 * instead of issuing tickets.
	 */
	public static function prepare_late_payment( $order_id ) {
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || 'released' !== ( $snapshot['status'] ?? '' ) ) { return; }
		$order = Orders::get( $order_id );
		if ( ! $order || in_array( (string) $order->payment_status, array( 'paid', 'refunded', 'cancelled' ), true ) || in_array( (string) $order->status, array( 'refunded', 'cancelled' ), true ) ) { return; }
		// A parallel confirmation that already reserves the voucher again holds the claim (false): it decides.
		$claim = self::claim( (int) $order_id, 'reserve', array( 'released' ) );
		if ( ! $claim ) { return; }
		list( $snapshot, $token ) = $claim;
		try {
			$again = self::reserve_again( (int) $order_id, $order, $snapshot, $token );
		} finally {
			self::unclaim( (int) $order_id, $token );
		}
		if ( is_wp_error( $again ) ) {
			\PassCaptain\Log::error( 'voucher_reserve_again_failed', 'voucher reserve again failed', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'error' => $again->get_error_code() ) ) );
		}
	}

	/**
	 * Filter wpcap_order_recovering (late payment re-check under the order lock, A632): a voucher that is
	 * still released means the payment only covers the discounted amount without a voucher behind it.
	 *
	 * @return null|\WP_Error
	 */
	public static function recovering( $result, $order_id ) {
		if ( $result instanceof \WP_Error ) { return $result; }
		$snapshot = self::snapshot( $order_id );
		if ( $snapshot && 'released' === ( $snapshot['status'] ?? '' ) ) {
			return new \WP_Error( 'voucher_released', __( 'Der Gutschein der Bestellung ist nicht mehr reserviert.', 'pass-captain' ) );
		}
		return $result;
	}

	/**
	 * Action wpcap_capacity_released: a late payment that went to review (no tickets) gives a voucher that
	 * was reserved again for it back, so it does not stay blocked; a retried allocation reserves it anew.
	 */
	public static function on_capacity_released( $order_id, $reason = '' ) {
		if ( 'payment_unallocated' === $reason ) { self::release( $order_id ); }
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
			return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Gutscheine können derzeit nicht eingelöst werden.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$order = Orders::get( $order_id );
		if ( ! $order || 'pending' !== $order->payment_status || $order->voucher_snapshot ) {
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'pass-captain' ), array( 'status' => 409 ) );
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
			return new \WP_Error( 'wpcap_voucher_quote_invalid', __( 'Der Gutschein passt nicht zu dieser Bestellung.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$key = 'wpcap-order-' . $order->public_id;
		$reservation = Bridge::reserve( $quote, $key );
		if ( is_wp_error( $reservation ) ) {
			if ( Bridge::is_transport_error( $reservation ) ) {
				// Timeout/exception: the provider may have reserved before failing. Nothing was written on our
				// side; release the key so no voucher amount stays blocked (contract: release by idempotency key).
				Bridge::release( array( 'idempotency_key' => $key, 'order_reference' => 'wpcap:' . $order->public_id ), 'wpcap-release-' . $order->public_id );
				\PassCaptain\Log::error( 'voucher_reserve_failed', 'voucher reserve failed', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'error' => $reservation->get_error_code() ) ) );
			}
			return self::user_error( $reservation );
		}

		$old_total = (int) $order->total_minor;
		$new_total = $old_total - $amount;
		$purpose = self::purpose( array( 'quote' => $quote, 'reservation' => $reservation ), array( 'source' => 'checkout', 'order_id' => (int) $order_id, 'code_hint' => substr( $code, -4 ) ) );
		list( $purpose, $purpose_changed ) = self::effective_purpose( $purpose, array( 'quote' => $quote, 'reservation' => $reservation ), self::tax_lines( $items ), array( 'source' => 'checkout', 'order_id' => (int) $order_id ) );
		// Split of the voucher over the order lines, proportional to what is paid for each line after
		// campaign discounts (PAY-4/PAY-6): single-purpose → part of the line discount, multi-purpose →
		// part of the line paid by voucher. Refunds take the voucher share of a ticket from it.
		$price_snapshot = json_decode( (string) $order->price_snapshot, true );
		$price_snapshot = is_array( $price_snapshot ) ? $price_snapshot : array();
		$line_discounts = Orders::snapshot_line_discounts( $order );
		if ( null === $line_discounts ) {
			// Orders before the split was stored: the proportional split of the order discount.
			$line_discounts = array();
			$all = array();
			foreach ( $items as $index => $item ) { $all[ $index ] = (int) $item->line_total_minor; }
			foreach ( Orders::allocate( $all, (int) $order->discount_minor ) as $index => $part ) { $line_discounts[ (int) $items[ $index ]->ticket_type_id ] = $part; }
		}
		$paid_lines = array();
		foreach ( $items as $item ) {
			$type_id = (int) $item->ticket_type_id;
			$paid_lines[ $type_id ] = ( $paid_lines[ $type_id ] ?? 0 ) + max( 0, (int) $item->line_total_minor - (int) ( $line_discounts[ $type_id ] ?? 0 ) );
		}
		$voucher_lines = Orders::allocate( $paid_lines, $amount );
		$snapshot = array( 'code_hint' => substr( $code, -4 ), 'amount_minor' => $amount, 'purpose' => $purpose, 'gross_minor' => $old_total, 'gross_tax_minor' => (int) $order->tax_minor, 'voucher_lines' => $voucher_lines, 'quote' => $quote, 'reservation' => $reservation, 'status' => 'reserved', 'refunded_minor' => 0, 'refunded_tickets' => 0 );
		if ( '' !== $purpose_changed ) {
			$snapshot['purpose_changed'] = $purpose_changed;
		}
		// Single-purpose vouchers and promotion codes change the price: fiscal documents already sent (or
		// possibly sent) to the provider cannot follow any more, so the voucher is refused (docs/VOUCHERS.md).
		$fiscal = self::reduces_price( $purpose ) && class_exists( \PassCaptain\Modules\Fiscalization::class ) ? \PassCaptain\Modules\Fiscalization::order_documents_state( (int) $order_id ) : 'none';
		if ( 'locked' === $fiscal ) {
			Bridge::release( (array) $reservation, 'wpcap-release-' . $order->public_id );
			return new \WP_Error( 'wpcap_voucher_fiscal_locked', __( 'Der Gutschein kann nicht mehr angewendet werden, weil für diese Bestellung bereits ein Fiskalbeleg übermittelt wird.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// One transaction, every write checked; the order row only changes while it is still pending, unchanged
		// and without a voucher (a concurrent retry or an expiry in between wins): see the WHERE of both UPDATEs.
		$wpdb->query( 'START TRANSACTION' );
		$ok = true;
		if ( self::PURPOSE_MULTI === $purpose ) {
			// Means of payment: lines, discount and VAT keep the full consideration; only the amount due shrinks.
			$snapshot['amount_paid_by_voucher'] = $amount;
			$snapshot['amount_due_minor'] = $new_total;
			$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET total_minor=%d,voucher_snapshot=%s,updated_at=%s WHERE order_id=%d AND payment_status='pending' AND total_minor=%d AND (voucher_snapshot IS NULL OR voucher_snapshot='')", $p . 'wpcap_orders', $new_total, wp_json_encode( $snapshot ), \PassCaptain\Clock::mysql(), (int) $order_id, $old_total ) ) && 1 === (int) $wpdb->rows_affected;
		} else {
			// Single-purpose: VAT was paid when the voucher was sold; the redemption reduces price and VAT.
			// Promotion code: a plain price reduction, booked the same way.
			// The voucher becomes part of the line discounts (price_snapshot.line_discounts), so invoice,
			// reports, partner settlements, refunds and the fiscal document use the reduced line amounts;
			// line VAT is computed again on the reduced line amount (same rule as the checkout).
			$new_tax = 0;
			$fiscal_lines = array();
			foreach ( $items as $item ) {
				$type_id = (int) $item->ticket_type_id;
				$line_discounts[ $type_id ] = (int) ( $line_discounts[ $type_id ] ?? 0 ) + (int) ( $voucher_lines[ $type_id ] ?? 0 );
				$voucher_lines[ $type_id ] = 0; // Each type's part is added once.
				$gross = max( 0, (int) $item->line_total_minor - (int) $line_discounts[ $type_id ] );
				$line_tax = (int) $item->tax_minor > 0 ? Orders::gross_tax( $gross, (int) $item->tax_rate_bp ) : 0;
				$new_tax += $line_tax;
				$fiscal_lines[ $type_id ] = array( $gross, $line_tax );
				$ok = $ok && false !== $wpdb->update( "{$p}wpcap_order_items", array( 'tax_minor' => $line_tax ), array( 'item_id' => (int) $item->item_id ) );
			}
			$price_snapshot['line_discounts'] = array_map( 'intval', $line_discounts );
			$price_snapshot['discount_minor'] = (int) $order->discount_minor + $amount;
			$price_snapshot['total_minor'] = $new_total;
			$price_snapshot['voucher_minor'] = $amount;
			$snapshot['amount_paid_by_voucher'] = 0;
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET total_minor=%d,discount_minor=discount_minor+%d,tax_minor=%d,price_snapshot=%s,voucher_snapshot=%s,updated_at=%s WHERE order_id=%d AND payment_status='pending' AND total_minor=%d AND (voucher_snapshot IS NULL OR voucher_snapshot='')", $p . 'wpcap_orders', $new_total, $amount, $new_tax, wp_json_encode( $price_snapshot ), wp_json_encode( $snapshot ), \PassCaptain\Clock::mysql(), (int) $order_id, $old_total ) ) && 1 === (int) $wpdb->rows_affected;
			// Unsent fiscal documents (created at checkout) follow the reduced amounts.
			if ( $ok && 'open' === $fiscal ) { $ok = \PassCaptain\Modules\Fiscalization::reprice_open_documents( (int) $order_id, $fiscal_lines ); }
		}
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			$current = Orders::get( $order_id );
			// The reservation key is shared with a concurrent request that applied it: keep it then.
			if ( ! $current || ! $current->voucher_snapshot ) {
				Bridge::release( (array) $reservation, 'wpcap-release-' . $order->public_id );
			}
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'voucher_reserved', 'order', (int) $order_id, array_filter( array( 'amount_minor' => $amount, 'purpose' => $purpose, 'purpose_changed' => $purpose_changed ) ) );
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
		$claim = self::claim( $order_id, 'commit', array( 'reserved', 'released' ) );
		if ( null === $claim ) { return true; }
		if ( false === $claim ) {
			// Another operation (e.g. the expiry releasing the reservation) is running: try again shortly;
			// the retry sees its result (a released voucher is reserved again and then committed).
			Jobs::enqueue( 'voucher-commit-' . $order_id, self::JOB, array( 'order_id' => $order_id ), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + MINUTE_IN_SECONDS ) );
			return false;
		}
		list( $snapshot, $token ) = $claim;
		$pending = array();
		try {
			$done = self::commit_claimed( $order_id, $snapshot, $token, $final, $pending );
		} finally {
			self::unclaim( $order_id, $token );
		}
		// Ticket refunds made while the commit was still pending receive their voucher share now.
		foreach ( $pending as $refund_id ) { self::refund( $refund_id, $order_id ); }
		return $done;
	}

	/**
	 * commit() under the claim.
	 *
	 * @param int[] $pending Out: refunds that waited for the commit.
	 */
	private static function commit_claimed( $order_id, array $snapshot, $token, $final_attempt, array &$pending ) {
		$order = Orders::get( $order_id );
		// Refunded or cancelled before the (retried) commit: the voucher is not charged at all.
		if ( 'reserved' === $snapshot['status'] && $order && ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) || in_array( $order->payment_status, array( 'refunded', 'cancelled' ), true ) ) ) {
			$released = Bridge::release( (array) $snapshot['reservation'], 'wpcap-release-' . $order->public_id );
			if ( is_wp_error( $released ) ) {
				\PassCaptain\Log::error( 'voucher_release_failed', 'Voucher reservation could not be released.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'error' => $released->get_error_code() ) ) );
				return true;
			}
			if ( self::put( $order_id, $token, array( 'status' => 'released', 'pending_refund_ids' => null ) ) ) {
				Audit::record( 'voucher_released', 'order', $order_id, array( 'reason' => $order->status ) );
			}
			return true;
		}
		if ( 'released' === $snapshot['status'] ) {
			if ( ! $order || 'paid' !== $order->payment_status ) { return true; }
			$again = self::reserve_again( $order_id, $order, $snapshot, $token );
			if ( is_wp_error( $again ) ) { return self::reserve_again_error( $order_id, $snapshot, $again, $final_attempt, $token ); }
			$snapshot = $again;
		}
		$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-' . ( $order ? $order->public_id : $order_id ) );
		// Released on the voucher side (reservation TTL, cron, API) while the order waited for payment.
		if ( is_wp_error( $result ) && 'vcap_reservation_missing' === $result->get_error_code() && empty( $snapshot['late_reservation'] ) && $order && 'paid' === $order->payment_status ) {
			$again = self::reserve_again( $order_id, $order, $snapshot, $token );
			if ( is_wp_error( $again ) ) { return self::reserve_again_error( $order_id, $snapshot, $again, $final_attempt, $token ); }
			$snapshot = $again;
			$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-late-' . $order->public_id );
		}
		if ( is_wp_error( $result ) ) { return self::commit_error( $order_id, $snapshot, $result, $final_attempt, $token ); }
		$stored = self::change(
			$order_id,
			static function ( array $fresh ) use ( $result, &$pending ) {
				// Pending refund ids are taken from the fresh snapshot in the same version-checked write, so a
				// refund queued while the provider call ran is never lost.
				$pending = array_map( 'absint', (array) ( $fresh['pending_refund_ids'] ?? array() ) );
				$fresh['status'] = 'committed';
				$fresh['redemption'] = $result;
				unset( $fresh['error'], $fresh['pending_refund_ids'] );
				return $fresh;
			},
			$token
		);
		if ( ! $stored ) {
			// Not stored: the retry commits again with the same key (the provider answers once).
			$pending = array();
			Jobs::enqueue( 'voucher-commit-' . (int) $order_id, self::JOB, array( 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + MINUTE_IN_SECONDS ) );
			return false;
		}
		Audit::record( 'voucher_committed', 'order', $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
		return true;
	}

	/**
	 * Reserves the voucher again with the stored quote (late payment after a release on either side).
	 *
	 * @return array|\WP_Error Updated snapshot.
	 */
	private static function reserve_again( $order_id, $order, array $snapshot, $token ) {
		// Each new late reservation after a release of an earlier one gets its own key (a retried
		// allocation after review must not get the released reservation back from the provider).
		$round = (int) ( $snapshot['late_rounds'] ?? 0 );
		$suffix = $round > 0 ? '-' . ( $round + 1 ) : '';
		if ( ! Bridge::available() ) {
			return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Gutscheine können derzeit nicht eingelöst werden.', 'pass-captain' ) );
		}
		$again = Bridge::reserve( (array) ( $snapshot['quote'] ?? array() ), 'wpcap-order-late-' . $order->public_id . $suffix );
		if ( is_wp_error( $again ) ) { return $again; }
		$amount = $again['applied_minor'] ?? ( $again['amount_minor'] ?? null );
		if ( null !== $amount && (int) $amount !== (int) $snapshot['amount_minor'] ) {
			Bridge::release( (array) $again, 'wpcap-release-late-' . $order->public_id . $suffix );
			return new \WP_Error( 'wpcap_voucher_amount_changed', __( 'Der Gutschein deckt den ursprünglichen Betrag nicht mehr.', 'pass-captain' ) );
		}
		$stored = self::put( $order_id, $token, array( 'reservation' => $again, 'status' => 'reserved', 'late_reservation' => true, 'late_rounds' => $round + 1 ) );
		if ( ! $stored ) {
			return new \WP_Error( 'wpcap_voucher_busy', __( 'Der Gutschein wird gerade anderweitig bearbeitet. Bitte versuche es gleich noch einmal.', 'pass-captain' ), array( 'status' => 503 ) );
		}
		$snapshot = $stored;
		Audit::record( 'voucher_reserved_again', 'order', $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
		return $snapshot;
	}

	private static function reserve_again_error( $order_id, array $snapshot, \WP_Error $error, $final, $token ) {
		return 'wpcap_voucher_amount_changed' === $error->get_error_code() ? self::needs_review( $order_id, $snapshot, $error, $token ) : self::commit_error( $order_id, $snapshot, $error, $final, $token );
	}

	/** Filter wpcap_process_job: retries a voucher commit. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( self::JOB !== $type && self::REFUND_JOB !== $type ) { return $handled; }
		global $wpdb;
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM %i WHERE job_id=%d', $wpdb->prefix . 'wpcap_jobs', absint( $job_id ) ) );
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

	private static function commit_error( $order_id, array $snapshot, \WP_Error $error, $final, $token ) {
		if ( ! $final && self::is_temporary( $error ) ) {
			\PassCaptain\Log::error( 'voucher_commit_failed', 'Voucher redemption could not be committed.', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'error' => $error->get_error_code(), 'retry' => true ) ) );
			Jobs::enqueue( 'voucher-commit-' . (int) $order_id, self::JOB, array( 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + 5 * MINUTE_IN_SECONDS ) );
			return false;
		}
		return self::needs_review( $order_id, $snapshot, $error, $token );
	}

	/** Paid, tickets issued, but the voucher could not be charged: flag, audit and alert. */
	private static function needs_review( $order_id, array $snapshot, \WP_Error $error, $token ) {
		// Not stored = the claim was lost: the operation that took over decides.
		if ( ! self::put( $order_id, $token, array( 'status' => 'commit_failed', 'error' => $error->get_error_code() ) ) ) { return true; }
		\PassCaptain\Log::error( 'voucher_commit_failed', 'Voucher redemption could not be committed.', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'error' => $error->get_error_code(), 'retry' => false ) ) );
		$order = Orders::get( $order_id );
		\PassCaptain\Alerts::raise(
			'voucher_charge_failed',
			__( 'Pass Captain: Gutschein konnte nicht belastet werden', 'pass-captain' ),
			sprintf(
				/* translators: 1: order id, 2: amount with currency, 3: last code characters, 4: error code */
				__( 'Bestellung %1$s ist bezahlt und die Tickets wurden ausgestellt, aber der Gutschein (…%3$s, %2$s) konnte in Voucher Captain nicht belastet werden (Fehler: %4$s). Bitte den Betrag beim Kunden nachfordern oder den Gutschein manuell belasten.', 'pass-captain' ),
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
		if ( ! $order || 'paid' === $order->payment_status ) { return; }
		// Claimed in one atomic UPDATE together with "order not paid": a late payment that is booked
		// meanwhile finds the claim and commits after the release (reserving the voucher again), and a
		// release never starts once the payment was booked or a commit runs.
		$claim = self::claim( $order_id, 'release', array( 'reserved' ), " AND payment_status<>'paid'" );
		if ( ! $claim ) { return; }
		list( $snapshot, $token ) = $claim;
		try {
			self::release_claimed( $order_id, $order, $snapshot, $token );
		} finally {
			self::unclaim( $order_id, $token );
		}
	}

	private static function release_claimed( $order_id, $order, array $snapshot, $token ) {
		$rounds = (int) ( $snapshot['late_rounds'] ?? 0 );
		$result = Bridge::release( (array) $snapshot['reservation'], 'wpcap-release-' . $order->public_id . ( $rounds > 0 ? '-late-' . $rounds : '' ) );
		if ( is_wp_error( $result ) ) {
			\PassCaptain\Log::error( 'voucher_release_failed', 'Voucher reservation could not be released.', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'error' => $result->get_error_code() ) ) );
			return;
		}
		self::put( $order_id, $token, array( 'status' => 'released' ) );
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
		$refund_id = absint( $refund_id );
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot ) { return true; }
		if ( in_array( $refund_id, array_map( 'absint', (array) ( $snapshot['refund_ids'] ?? array() ) ), true ) ) { return true; }
		if ( 'reserved' === $snapshot['status'] ) {
			// Commit still pending (retry job): the share is refunded right after the commit. Queued with a
			// version check, so a commit that is stored at the same moment either sees the id or this call
			// sees `committed` and refunds the share itself.
			$queued = false;
			self::change(
				$order_id,
				static function ( array $fresh ) use ( $refund_id, &$queued ) {
					$queued = false;
					if ( 'reserved' !== ( $fresh['status'] ?? '' ) ) { return null; }
					$queued = true;
					$pending = array_map( 'absint', (array) ( $fresh['pending_refund_ids'] ?? array() ) );
					if ( in_array( $refund_id, $pending, true ) ) { return null; }
					$fresh['pending_refund_ids'] = array_merge( $pending, array( $refund_id ) );
					return $fresh;
				}
			);
			if ( $queued ) { return true; }
			$snapshot = self::snapshot( $order_id );
			if ( ! $snapshot ) { return true; }
		}
		if ( 'committed' !== $snapshot['status'] ) { return true; }
		$plan = self::refund_plan( $refund_id );
		if ( ! $plan ) { return true; }
		$snapshot = self::snapshot( $order_id ); // refund_plan() may have stored the plan.
		if ( ! $snapshot ) { return true; }
		$share = (int) $plan['voucher_minor'];
		if ( $share < 1 ) {
			self::book_refund( $order_id, $refund_id, 0, (int) $plan['tickets'] );
			return true;
		}
		$result = Bridge::refund( (array) ( $snapshot['redemption'] ?? array() ), $share, 'wpcap-refund-' . $refund_id );
		if ( is_wp_error( $result ) ) {
			// Never lose the voucher share: temporary errors are retried with the same refund key (the voucher
			// system refunds each key once), a final one is flagged and the admin is told.
			$final = $from_job ? $final_attempt : ! self::is_temporary( $result );
			\PassCaptain\Log::error( 'voucher_refund_failed', 'Voucher refund failed.', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'refund_id' => $refund_id, 'error' => $result->get_error_code() ) ) );
			self::change(
				$order_id,
				static function ( array $fresh ) use ( $refund_id ) {
					$failed = array_map( 'absint', (array) ( $fresh['failed_refund_ids'] ?? array() ) );
					if ( in_array( $refund_id, $failed, true ) || in_array( $refund_id, array_map( 'absint', (array) ( $fresh['refund_ids'] ?? array() ) ), true ) ) { return null; }
					$fresh['failed_refund_ids'] = array_values( array_merge( $failed, array( $refund_id ) ) );
					return $fresh;
				}
			);
			if ( $final ) {
				$order = \PassCaptain\Orders::get( (int) $order_id );
				/* translators: 1: order, 2: amount in minor units, 3: error */
				\PassCaptain\Alerts::raise( 'voucher_refund_failed', __( 'Pass Captain: Gutschein-Rückbuchung fehlgeschlagen', 'pass-captain' ), sprintf( __( "Bei Bestellung %1\$s konnte der Gutscheinanteil einer Erstattung (%2\$d in kleinster Einheit) nicht auf den Gutschein zurückgebucht werden:\n\n%3\$s\n\nBitte den Gutschein im Gutschein-Plugin manuell korrigieren.", 'pass-captain' ), $order ? $order->public_id : (string) $order_id, $share, \PassCaptain\Redact::text( $result->get_error_message(), false ) ) );
			} elseif ( ! $from_job ) {
				Jobs::enqueue( 'voucher-refund-' . $refund_id, self::REFUND_JOB, array( 'refund_id' => $refund_id, 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + 5 * MINUTE_IN_SECONDS ) );
			}
			return $final;
		}
		$booked = self::book_refund( $order_id, $refund_id, $share, (int) $plan['tickets'] );
		if ( $booked ) {
			Audit::record( 'voucher_refunded', 'order', (int) $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $share, 'purpose' => self::snapshot_purpose( $snapshot ) ) );
		}
		if ( false === $booked && ! $from_job ) {
			Jobs::enqueue( 'voucher-refund-' . $refund_id, self::REFUND_JOB, array( 'refund_id' => $refund_id, 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + MINUTE_IN_SECONDS ) );
		}
		return false !== $booked;
	}

	/**
	 * Books a settled voucher share into the snapshot exactly once per refund (version-checked: two
	 * parallel runs for the same refund, e.g. the hook and the retry job, count it once; the provider
	 * itself refunds each key once).
	 *
	 * @return bool|null True when booked now, null when it was already booked, false after conflicts.
	 */
	private static function book_refund( $order_id, $refund_id, $share, $tickets ) {
		$booked = false;
		$stored = self::change(
			$order_id,
			static function ( array $fresh ) use ( $refund_id, $share, $tickets, &$booked ) {
				$booked = false;
				$done = array_map( 'absint', (array) ( $fresh['refund_ids'] ?? array() ) );
				if ( in_array( (int) $refund_id, $done, true ) ) { return null; }
				$booked = true;
				if ( $share > 0 ) { $fresh['refunded_minor'] = (int) ( $fresh['refunded_minor'] ?? 0 ) + (int) $share; }
				$fresh['refunded_tickets'] = (int) ( $fresh['refunded_tickets'] ?? 0 ) + (int) $tickets;
				$fresh['refund_ids'] = array_merge( $done, array( (int) $refund_id ) );
				$fresh['failed_refund_ids'] = array_values( array_diff( array_map( 'absint', (array) ( $fresh['failed_refund_ids'] ?? array() ) ), array( (int) $refund_id ) ) );
				return $fresh;
			}
		);
		if ( false === $stored ) { return false; }
		return $booked && $stored ? true : null;
	}

	/**
	 * Split of a completed ticket refund into cash part (refund row, paid back by the gateway) and voucher
	 * part, computed once and stored in the voucher snapshot (`refund_plans`) so that the credit note and
	 * the voucher refund use the same numbers.
	 *
	 * Voucher part = the refunded tickets' part of the voucher split stored at apply time (voucher_lines,
	 * proportional to the line amounts after campaign discounts; older snapshots: voucher amount × their
	 * discounted line value / all discounted line values), the refund that covers the last tickets
	 * receives the remainder. For multi-purpose vouchers also the full VAT of the
	 * refunded tickets (`tax_minor`, cash + voucher part) for the credit note.
	 *
	 * @return array|null voucher_minor, cash_minor, tax_minor (full VAT, multi only), tickets, purpose.
	 */
	public static function refund_plan( $refund_id ) {
		$refund = \PassCaptain\Refunds::get( $refund_id );
		if ( ! $refund ) { return null; }
		$order_id = (int) $refund['order_id'];
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || ! in_array( $snapshot['status'] ?? '', array( 'committed', 'commit_failed', 'external' ), true ) ) { return null; }
		if ( isset( $snapshot['refund_plans'][ (string) absint( $refund_id ) ] ) ) { return (array) $snapshot['refund_plans'][ (string) absint( $refund_id ) ]; }
		// Computed and stored with a version check: the plan depends on the plans of earlier refunds (the
		// last refund receives the remainder), so a plan stored meanwhile by a parallel refund makes this
		// one recompute; the first stored plan of a refund wins and is returned to every caller.
		$result = null;
		$stored = self::change(
			$order_id,
			static function ( array $fresh ) use ( $refund_id, $refund, $order_id, &$result ) {
				$result = null;
				if ( ! in_array( $fresh['status'] ?? '', array( 'committed', 'commit_failed', 'external' ), true ) ) { return null; }
				$plans = (array) ( $fresh['refund_plans'] ?? array() );
				if ( isset( $plans[ (string) absint( $refund_id ) ] ) ) {
					$result = (array) $plans[ (string) absint( $refund_id ) ];
					return null;
				}
				$result = self::compute_plan( $refund_id, $refund, $order_id, $fresh );
				$plans[ (string) absint( $refund_id ) ] = $result;
				$fresh['refund_plans'] = $plans;
				return $fresh;
			}
		);
		return false === $stored ? null : $result;
	}

	/** refund_plan() for the given snapshot (not stored). */
	private static function compute_plan( $refund_id, array $refund, $order_id, array $snapshot ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$plans = (array) ( $snapshot['refund_plans'] ?? array() );
		$ticket_ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) ) );
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,quantity,line_total_minor,tax_minor FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', $order_id ) );
		// Weight of a ticket = its part of the voucher (voucher_lines, stored at apply time on the line
		// amounts after campaign discounts); older snapshots: its line value after campaign discounts.
		$voucher_lines = isset( $snapshot['voucher_lines'] ) && is_array( $snapshot['voucher_lines'] ) ? array_map( 'intval', $snapshot['voucher_lines'] ) : null;
		$line_discounts = null === $voucher_lines ? (array) Orders::snapshot_line_discounts( Orders::get( $order_id ) ) : array();
		$unit = array();
		$all_value = 0;
		$all_tickets = 0;
		foreach ( (array) $items as $item ) {
			$q = max( 1, (int) $item->quantity );
			$type_id = (int) $item->ticket_type_id;
			$weight = null !== $voucher_lines ? (int) ( $voucher_lines[ $type_id ] ?? 0 ) : max( 0, (int) $item->line_total_minor - (int) ( $line_discounts[ $type_id ] ?? 0 ) );
			$unit[ $type_id ] = array( $weight / $q, (int) $item->tax_minor / $q );
			$all_value += $weight;
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
			$share = $all_value > 0 ? (int) floor( $voucher * $value / $all_value + 1e-6 ) : ( $all_tickets > 0 ? (int) floor( $voucher * $tickets / $all_tickets + 1e-6 ) : 0 );
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
	 * Purpose of a voucher: 'single' (Einzweckgutschein), 'multi' (Mehrzweckgutschein) or 'discount'
	 * (promotion code, plain price reduction).
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
		// Contract field of ProviderInterface::quote(): payment = multi-purpose voucher; single = single-purpose
		// voucher; discount = promotion code (plain price reduction, no VAT charged in advance).
		if ( isset( $data['treatment'] ) && is_scalar( $data['treatment'] ) ) {
			$treatment = strtolower( trim( (string) $data['treatment'] ) );
			if ( 'payment' === $treatment ) { return self::PURPOSE_MULTI; }
			if ( 'single' === $treatment ) { return self::PURPOSE_SINGLE; }
			if ( 'discount' === $treatment ) { return self::PURPOSE_DISCOUNT; }
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

	/** 'single'|'multi'|'discount' for the usual spellings (SPV/MPV, Einzweck/Mehrzweck, Rabatt …), null for anything else. */
	public static function normalize_purpose( $value ) {
		if ( ! is_scalar( $value ) ) { return null; }
		$v = preg_replace( '/[^a-z]/', '', strtolower( (string) $value ) );
		if ( in_array( $v, array( 'single', 'singlepurpose', 'singlepurposevoucher', 'spv', 'einzweck', 'einzweckgutschein' ), true ) ) { return self::PURPOSE_SINGLE; }
		if ( in_array( $v, array( 'multi', 'multipurpose', 'multipurposevoucher', 'mpv', 'mehrzweck', 'mehrzweckgutschein' ), true ) ) { return self::PURPOSE_MULTI; }
		if ( in_array( $v, array( 'discount', 'promotion', 'promo', 'promocode', 'rabatt', 'rabattcode', 'rabattaktion', 'preisnachlass' ), true ) ) { return self::PURPOSE_DISCOUNT; }
		return null;
	}

	/** Whether a purpose reduces price and VAT of the order (single-purpose voucher, promotion code). */
	public static function reduces_price( $purpose ) {
		return self::PURPOSE_MULTI !== $purpose;
	}

	/**
	 * A single-purpose voucher is one only for supplies whose place of supply and VAT were known when it was
	 * sold (Art. 30a MwStSystRL, § 3 Abs. 14 UStG, § 4 Abs. 9 öUStG). When the voucher plugin sends the VAT of
	 * the sale (`sale_tax` = rate_bp and country) and a ticket line carries another rate or is taxed in another
	 * country (the line's place of supply, filter `wpcap_voucher_tax_country`), the voucher cannot be a
	 * single-purpose one for these tickets: the redemption is booked as a multi-purpose voucher (means of
	 * payment, VAT at redemption). Without sale data nothing changes.
	 *
	 * @param string $purpose Purpose from purpose().
	 * @param array  $data    Voucher data (quote, reservation, ticket service source).
	 * @param array  $lines   Ticket lines: rate_bp (int) and country (ISO code, '' = seller country).
	 * @param array  $context Order or ticket context (passed to the filter).
	 * @return array{0:string,1:string} Purpose and the reason of a change ('' | 'single_rate' | 'single_country').
	 */
	public static function effective_purpose( $purpose, array $data, array $lines, array $context = array() ) {
		if ( self::PURPOSE_SINGLE !== $purpose ) { return array( $purpose, '' ); }
		$sale = self::sale_tax_from_data( $data );
		if ( null === $sale ) { return array( $purpose, '' ); }
		foreach ( $lines as $line ) {
			$own = \PassCaptain\Tax\Profiles::country( $line['country'] ?? '' );
			/**
			 * Country in which a ticket line is taxed, compared with the country of a single-purpose voucher's sale.
			 *
			 * @param string $country Place of supply of the line (venue country, else the seller country).
			 * @param array  $context source, order_id or ticket_type_id, and the line (rate_bp, country).
			 */
			$country = \PassCaptain\Tax\Profiles::country( (string) apply_filters( 'wpcap_voucher_tax_country', '' !== $own ? $own : \PassCaptain\Tax\Profiles::seller_country(), $context + array( 'line' => $line ) ) );
			if ( '' !== $sale['country'] && '' !== $country && $sale['country'] !== $country ) { return array( self::PURPOSE_MULTI, 'single_country' ); }
		}
		foreach ( $lines as $line ) {
			if ( (int) ( $line['rate_bp'] ?? 0 ) !== $sale['rate_bp'] ) { return array( self::PURPOSE_MULTI, 'single_rate' ); }
		}
		return array( $purpose, '' );
	}

	/** VAT of the voucher sale from the plugin data (`sale_tax`, also nested in quote/reservation/voucher/source), else null. */
	public static function sale_tax_from_data( $data, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 3 ) { return null; }
		if ( isset( $data['sale_tax'] ) && is_array( $data['sale_tax'] ) && isset( $data['sale_tax']['rate_bp'] ) && is_numeric( $data['sale_tax']['rate_bp'] ) ) {
			return array( 'rate_bp' => (int) $data['sale_tax']['rate_bp'], 'country' => \PassCaptain\Tax\Profiles::country( $data['sale_tax']['country'] ?? '' ) );
		}
		foreach ( array( 'quote', 'reservation', 'voucher', 'source' ) as $key ) {
			$found = isset( $data[ $key ] ) ? self::sale_tax_from_data( $data[ $key ], $depth + 1 ) : null;
			if ( null !== $found ) { return $found; }
		}
		return null;
	}

	/**
	 * Ticket lines of an order for effective_purpose(): VAT rate and place of supply. Service fee lines are
	 * left out (a voucher for tickets says nothing about the platform's fee).
	 *
	 * @param object[] $items Order lines (Orders::items_for_order()).
	 * @return array<int, array{rate_bp:int,country:string}>
	 */
	public static function tax_lines( array $items ) {
		$lines = array();
		foreach ( $items as $item ) {
			if ( '' !== (string) ( $item->fee_kind ?? '' ) ) { continue; }
			$lines[] = array( 'rate_bp' => (int) $item->tax_rate_bp, 'country' => (string) ( $item->tax_country ?? '' ) );
		}
		return $lines;
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
		return self::read( $order_id )[0];
	}

	/* ------------------------------------------------------------------ Concurrency of voucher_snapshot */

	/**
	 * Fresh read of the stored snapshot.
	 *
	 * @return array{0: array|null, 1: string|null} Decoded snapshot and the stored text (for the version check).
	 */
	private static function read( $order_id ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT voucher_snapshot FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) );
		if ( null === $raw || '' === (string) $raw ) { return array( null, null ); }
		$snapshot = json_decode( (string) $raw, true );
		return array( is_array( $snapshot ) ? $snapshot : null, (string) $raw );
	}

	/**
	 * Version-checked write (compare and swap): the snapshot is only stored while the column still holds
	 * $expected, i.e. nobody changed it since it was read. Every write raises `rev`, so two writes never
	 * store the same text. $extra adds conditions on the order row of the same atomic UPDATE (e.g. "not
	 * paid"), evaluated under the row lock the UPDATE takes.
	 */
	private static function swap( $order_id, array &$snapshot, $expected, $extra = '' ) {
		global $wpdb;
		$snapshot['rev'] = (int) ( $snapshot['rev'] ?? 0 ) + 1;
		$rows = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET voucher_snapshot=%s WHERE order_id=%d AND voucher_snapshot=%s' . $extra, $wpdb->prefix . 'wpcap_orders', wp_json_encode( $snapshot ), absint( $order_id ), (string) $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $extra is a fixed condition from this class.
		return 1 === $rows;
	}

	/**
	 * Read–change–write loop with version check. $change receives the fresh snapshot and returns the
	 * snapshot to store, or null for "nothing to store". With $token the write only happens while the
	 * caller still holds the claim (see claim()); the claim itself is left in place.
	 *
	 * @param callable(array): (array|null) $change
	 * @return array|null|false Stored snapshot, null when nothing was stored, false after repeated conflicts.
	 */
	private static function change( $order_id, callable $change, $token = null ) {
		for ( $try = 0; $try < self::CAS_TRIES; $try++ ) {
			list( $snapshot, $raw ) = self::read( $order_id );
			if ( ! $snapshot ) { return null; }
			if ( null !== $token && $token !== (string) ( $snapshot['claim']['token'] ?? '' ) ) {
				\PassCaptain\Log::error( 'voucher_claim_lost', 'Voucher snapshot claim was lost before the result could be stored.', array( 'object_type' => 'order', 'object_id' => (int) $order_id ) );
				return null;
			}
			$new = $change( $snapshot );
			if ( null === $new ) { return null; }
			if ( self::swap( $order_id, $new, $raw ) ) { return $new; }
		}
		\PassCaptain\Log::error( 'voucher_snapshot_conflict', 'Voucher snapshot could not be stored after repeated concurrent changes.', array( 'object_type' => 'order', 'object_id' => (int) $order_id ) );
		return false;
	}

	/** Whether another operation holds a live claim on the snapshot (claims older than CLAIM_TTL are void). */
	private static function claimed( array $snapshot ) {
		return ! empty( $snapshot['claim']['token'] ) && (int) ( $snapshot['claim']['at'] ?? 0 ) > \PassCaptain\Clock::real() - self::CLAIM_TTL;
	}

	/**
	 * Claims the snapshot for one provider operation (commit, release, reserve again): only one of them
	 * runs at a time, so an expiry that releases the voucher and a late payment that commits it can never
	 * both act on the same reservation. Provider calls run outside any transaction; the claim is a field
	 * of the snapshot set with a version-checked write and removed by unclaim().
	 *
	 * @param string[] $statuses Snapshot states the operation applies to.
	 * @param string   $extra    Additional condition on the order row (same atomic UPDATE).
	 * @return array{0: array, 1: string}|null|false [snapshot, token]; null = state does not apply (any
	 *                                              more); false = another operation is running.
	 */
	private static function claim( $order_id, $op, array $statuses, $extra = '' ) {
		for ( $try = 0; $try < self::CAS_TRIES; $try++ ) {
			list( $snapshot, $raw ) = self::read( $order_id );
			if ( ! $snapshot || ! in_array( (string) ( $snapshot['status'] ?? '' ), $statuses, true ) ) { return null; }
			if ( self::claimed( $snapshot ) ) { return false; }
			$token = bin2hex( random_bytes( 8 ) );
			$snapshot['claim'] = array( 'op' => (string) $op, 'token' => $token, 'at' => \PassCaptain\Clock::real() );
			if ( self::swap( $order_id, $snapshot, $raw, $extra ) ) { return array( $snapshot, $token ); }
			if ( '' !== $extra ) {
				// The extra condition may be what failed (e.g. the order was paid meanwhile): re-check it.
				global $wpdb;
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id=%d' . $extra, $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) ) ) { return null; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $extra is a fixed condition from this class.
			}
		}
		return false;
	}

	/** Removes the caller's claim (no-op when it is gone or belongs to someone else). */
	private static function unclaim( $order_id, $token ) {
		self::change(
			$order_id,
			static function ( array $snapshot ) use ( $token ) {
				if ( $token !== (string) ( $snapshot['claim']['token'] ?? '' ) ) { return null; }
				unset( $snapshot['claim'] );
				return $snapshot;
			}
		);
	}

	/**
	 * Stores fields under the caller's claim on the fresh snapshot (concurrent bookkeeping such as
	 * pending refund ids or refund plans is kept). Null values remove the field.
	 *
	 * @return array|null|false See change().
	 */
	private static function put( $order_id, $token, array $fields ) {
		return self::change(
			$order_id,
			static function ( array $snapshot ) use ( $fields ) {
				foreach ( $fields as $key => $value ) {
					if ( null === $value ) {
						unset( $snapshot[ $key ] );
					} else {
						$snapshot[ $key ] = $value;
					}
				}
				return $snapshot;
			},
			$token
		);
	}

	/** Provider errors are shown to customers without internal details. */
	private static function user_error( \WP_Error $error ) {
		$message = $error->get_error_message() ?: __( 'Der Gutschein konnte nicht eingelöst werden.', 'pass-captain' );
		return new \WP_Error( 'wpcap_voucher_rejected', $message, array( 'status' => 409 ) );
	}
}
