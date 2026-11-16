<?php
/**
 * Native refunds with optional second approval.
 *
 * Refunds are position based: the caller names the tickets to refund. The amount is derived from
 * the order item snapshot (incl. proportional discount), the provider refund is requested through the
 * payment adapter, refunded tickets are revoked (checked-in tickets stay as history) and their
 * capacity is released. Every request is idempotent via its key and recorded in wpcap_refunds.
 */
namespace PassCaptain;

use PassCaptain\Payments\Registry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Refunds {
	const APPROVE_CAP = 'wpcap_approve_refunds';
	const REFUND_CAP = 'wpcap_refund';

	/**
	 * Requests a refund. Executes it immediately unless a second approval is required.
	 *
	 * @param int    $order_id        Order ID.
	 * @param int[]  $ticket_ids      Tickets to refund; empty = all tickets not yet refunded.
	 * @param string $reason          Reason (stored in audit log and refund record).
	 * @param string $idempotency_key Unique key for this refund request.
	 * @param array  $options         deduct_minor (fixed fee kept), deduct_bp (fee in basis points of the
	 *                                ticket value), max_minor (goodwill cap, 0 = none) – see quote();
	 *                                self_service / customer (trusted: buyer request from the order portal,
	 *                                e.g. Sales\SelfService or after a postponement; no staff capability is
	 *                                checked – the caller verified the access token – and the refund always
	 *                                waits for a staff approval).
	 * @return array|\WP_Error Refund row as array.
	 */
	public static function request( $order_id, array $ticket_ids, $reason, $idempotency_key, array $options = array() ) {
		$customer = ! empty( $options['customer'] );
		$self_service = $customer || ! empty( $options['self_service'] );
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$key_hash = hash( 'sha256', 'refund|' . $order_id . '|' . sanitize_text_field( $idempotency_key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
		if ( $existing ) {
			// "approved" means the execution never got past its claim (e.g. a database error): a retry of
			// the same request continues it. execute() claims the row, so it cannot pay out twice.
			if ( ! $self_service && 'approved' === $existing['status'] && true === self::check_actor() ) { return self::execute( (int) $existing['refund_id'] ); }
			return $existing;
		}

		$check = $self_service ? true : self::check_actor();
		if ( is_wp_error( $check ) ) { return $check; }
		$order = Orders::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === $order->payment_provider ) {
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// Serialise refund requests per order: overlap and approval checks below must see every
		// refund that is already pending or running for this order.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::db_error( 0, $order_id, 'request_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::request_db_error( $order_id, 'request_lock' ); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return $existing; }
		self::$read_failed = false;
		$open = self::open_refunds( $order_id );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $requested && array_intersect( $requested, $open['ticket_ids'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_overlap', __( 'Für mindestens eines der gewählten Tickets läuft bereits eine Erstattung oder sie wartet auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$calc = self::calculate( $order, $requested, $open['ticket_ids'], $open['amount_minor'] );
		if ( is_wp_error( $calc ) ) { $wpdb->query( 'ROLLBACK' ); return $calc; }
		$calc = self::apply_deduction( $calc, $options );
		// A failed read while computing the amount must not produce a refund of the wrong amount
		// (e.g. tickets revoked for 0.00 because the order lines could not be read).
		if ( self::$read_failed ) { return self::request_db_error( $order_id, 'request_calculate' ); }
		// Policy for tickets that were already used for admission (setting refund_after_checkin).
		$checked_in = self::checked_in_ids( $calc['ticket_ids'] );
		$checkin_policy = $checked_in ? self::checkin_policy() : 'allow';
		if ( 'deny' === $checkin_policy ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_checked_in', __( 'Bereits eingecheckte Tickets können nach den Erstattungsregeln nicht erstattet werden.', 'pass-captain' ), array( 'status' => 409, 'checked_in_ticket_ids' => $checked_in ) );
		}

		// The threshold applies to everything refunded or pending for this order, so splitting a
		// large refund into small requests does not avoid the second approval.
		$threshold = (int) Settings::get( 'refund_approval_threshold' );
		$cumulative = (int) $order->refunded_minor + $open['amount_minor'] + $calc['amount_minor'];
		$needs_approval = $threshold > 0 && $cumulative > $threshold && ! current_user_can( self::APPROVE_CAP );
		// "allow with approval": a second person must approve, even when the requester could approve.
		$needs_approval = $needs_approval || 'approval' === $checkin_policy || $self_service;
		$now = current_time( 'mysql', true );
		$row = array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $calc['amount_minor'],
			'tax_minor' => $calc['tax_minor'],
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( $calc['ticket_ids'] ),
			'reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
			'status' => $needs_approval ? 'pending_approval' : 'approved',
			'requested_by' => $self_service ? 0 : get_current_user_id(),
			'approved_by' => $needs_approval ? 0 : get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		);
		// Only written when there is one, so refunds keep working while a deferred schema upgrade is pending.
		if ( $calc['deducted_minor'] > 0 ) { $row['deducted_minor'] = $calc['deducted_minor']; }
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", $row );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
			return $existing ? $existing : new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$refund_id = (int) $wpdb->insert_id;
		if ( false === $wpdb->query( 'COMMIT' ) ) { return self::request_db_error( $order_id, 'request_commit' ); }
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $calc['amount_minor'], 'deducted_minor' => $calc['deducted_minor'], 'needs_approval' => $needs_approval, 'checked_in_tickets' => count( $checked_in ), 'checkin_policy' => $checkin_policy, 'self_service' => $self_service, 'by_customer' => $customer ) );
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, Localization::money_number( $calc['amount_minor'], $order->currency ) . ' ' . $order->currency ) );
			return self::get( $refund_id );
		}
		return self::execute( $refund_id );
	}

	/** Second approval by a different user with the approval capability. */
	public static function approve( $refund_id ) {
		$refund = self::get( $refund_id );
		if ( ! $refund || 'pending_approval' !== $refund['status'] ) {
			return new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( self::APPROVE_CAP ) || (int) $refund['requested_by'] === get_current_user_id() ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Die Freigabe muss durch eine andere berechtigte Person erfolgen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$check = self::check_actor();
		if ( is_wp_error( $check ) ) { return $check; }
		global $wpdb;
		$approved = $wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		if ( false === $approved ) { return self::db_error( absint( $refund_id ), (int) $refund['order_id'], 'approve' ); }
		Audit::record( 'refund_approved', 'order', (int) $refund['order_id'], array( 'refund_id' => absint( $refund_id ) ) );
		return self::execute( $refund_id );
	}

	/** Rejects a pending refund. */
	public static function reject( $refund_id, $reason = '' ) {
		global $wpdb;
		if ( ! current_user_can( self::APPROVE_CAP ) ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'rejected', 'error_message' => substr( sanitize_text_field( $reason ), 0, 255 ), 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		if ( $updated ) {
			$rejected = self::get( $refund_id );
			Audit::record( 'refund_rejected', 'order', (int) $rejected['order_id'], array( 'refund_id' => absint( $refund_id ) ) );
			/** A pending refund was rejected by an approver. */
			do_action( 'wpcap_refund_rejected', absint( $refund_id ), (int) $rejected['order_id'] );
			return $rejected;
		}
		return new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	/**
	 * Executes an approved (or previously failed) refund: provider refund, ticket revocation,
	 * capacity release, order totals. Safe to retry after a provider failure.
	 *
	 * Money moves before the local booking, so the steps are separated by persisted states:
	 * the refund is claimed (approved/failed → processing, exactly one row, committed) before the
	 * provider is called; right after a provider success its reference is stored on the still
	 * processing row. "processing" with a provider reference means "paid out, not yet booked":
	 * finalize() books it in one transaction, and reconcile() finishes such refunds later (cron,
	 * refund admin page) without calling the provider again.
	 */
	public static function execute( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = absint( $refund_id );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::db_error( $refund_id, 0, 'start_transaction' );
		}
		$refund = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d FOR UPDATE', $p . 'wpcap_refunds', $refund_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( $refund_id, 0, 'lock_refund', $error );
		}
		if ( ! $refund || ! in_array( $refund['status'], array( 'approved', 'failed' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		// Re-validate under the order lock right before money moves: an approved refund may have been
		// overtaken by another refund of the same tickets (or the order total may be used up).
		$locked_order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', (int) $refund['order_id'] ) );
		if ( '' !== (string) $wpdb->last_error ) {
			// A failed read is not a conflict: the refund must stay approved for the retry.
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( $refund_id, (int) $refund['order_id'], 'lock_order', $error );
		}
		self::$read_failed = false;
		$invalid = self::execution_conflict( $refund, $locked_order );
		if ( self::$read_failed ) {
			// A failed read is not a conflict: marking the refund failed would end it for good.
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( $refund_id, (int) $refund['order_id'], 'validate', $error );
		}
		if ( '' !== $invalid ) {
			$failed = $wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'failed', 'error_message' => substr( $invalid, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
			if ( false === $failed || false === $wpdb->query( 'COMMIT' ) ) {
				$error = (string) $wpdb->last_error;
				$wpdb->query( 'ROLLBACK' );
				return self::db_error( $refund_id, (int) $refund['order_id'], 'mark_failed', $error );
			}
			Log::error( 'refund_failed', 'Refund failed.', array( 'object_type' => 'order', 'object_id' => (int) $refund['order_id'], 'details' => array( 'refund_id' => $refund_id, 'error' => 'wpcap_refund_conflict' ) ) );
			return new \WP_Error( 'wpcap_refund_conflict', $invalid, array( 'status' => 409 ) );
		}
		// Claim: must move exactly this row out of approved/failed, and the commit must succeed,
		// before any money moves. Otherwise nothing happened and the refund can simply be retried.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='processing',provider_reference='',updated_at=%s WHERE refund_id=%d AND status IN ('approved','failed')", $p . 'wpcap_refunds', current_time( 'mysql', true ), $refund_id ) );
		if ( 1 !== (int) $claimed || false === $wpdb->query( 'COMMIT' ) ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( $refund_id, (int) $refund['order_id'], 'claim', $error );
		}

		$order = Orders::get( (int) $refund['order_id'] );
		if ( ! $order ) {
			// Without the order the provider is unknown; never fall back to "manual" (that would book a
			// refund nobody paid out). Nothing moved yet: hand the claim back for a retry.
			$released = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='approved',updated_at=%s WHERE refund_id=%d AND status='processing' AND provider_reference=''", $p . 'wpcap_refunds', current_time( 'mysql', true ), $refund_id ) );
			if ( 1 !== (int) $released ) {
				self::log_problem( $refund_id, (int) $refund['order_id'], 'release_claim', (string) $wpdb->last_error );
			}
			return self::db_error( $refund_id, (int) $refund['order_id'], 'load_order' );
		}
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		$provider_reference = 'manual';
		if ( $gateway && $gateway->supports_refunds() && (int) $refund['amount_minor'] > 0 ) {
			$attempt = (int) ( $refund['provider_attempt'] ?? 0 );
			$result = $gateway->refund( (array) $order, (int) $refund['amount_minor'], self::provider_key( $refund_id, $attempt ) );
			if ( is_wp_error( $result ) ) {
				// A final decline (the provider answered and refused, e.g. insufficient balance, card
				// expired) moves the next retry to a new idempotency key: Stripe, PayPal and Mollie replay the
				// stored answer of a key (Stripe for 24 h), so the same key would fail again although the
				// cause was fixed. Network errors, timeouts and 5xx keep the key: the first request may have
				// paid out, and only the same key prevents a second payout.
				$final = self::is_final_decline( $result );
				$failed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='failed',error_message=%s,provider_attempt=provider_attempt+%d,updated_at=%s WHERE refund_id=%d AND status='processing'", $p . 'wpcap_refunds', substr( $result->get_error_message(), 0, 255 ), $final ? 1 : 0, current_time( 'mysql', true ), $refund_id ) );
				if ( 1 !== (int) $failed ) {
					// Stays "processing" without provider reference: needs a manual check, never an automatic retry.
					self::log_problem( $refund_id, (int) $refund['order_id'], 'mark_failed_after_provider_error', (string) $wpdb->last_error );
				}
				Log::error( 'refund_failed', 'Refund failed.', array( 'object_type' => 'order', 'object_id' => (int) $refund['order_id'], 'details' => array( 'refund_id' => $refund_id, 'error' => $result->get_error_code(), 'attempt' => $attempt, 'final_decline' => $final ) ) );
				return $result;
			}
			$provider_reference = (string) $result;
			if ( '' === $provider_reference ) { $provider_reference = self::provider_key( $refund_id, $attempt ); }
		}
		// Persist the payout first: from here on the refund must only be booked, never paid out again.
		$stored = $wpdb->update( "{$p}wpcap_refunds", array( 'provider_reference' => substr( $provider_reference, 0, 191 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id, 'status' => 'processing' ) );
		if ( false === $stored ) {
			// The provider has paid out but the database refused the note: the refund stays "processing"
			// (tickets and amount stay bound, it is never re-run automatically) and is logged for a manual booking.
			self::log_problem( $refund_id, (int) $refund['order_id'], 'store_provider_reference', (string) $wpdb->last_error, array( 'provider_reference' => $provider_reference ) );
			return new \WP_Error( 'wpcap_refund_booking', __( 'Die Erstattung wurde beim Zahlungsanbieter ausgeführt, konnte aber nicht verbucht werden. Bitte die Erstattung prüfen.', 'pass-captain' ), array( 'status' => 500, 'provider_reference' => $provider_reference ) );
		}
		return self::finalize( $refund_id );
	}

	/**
	 * Idempotency key of a provider refund request. Attempt 0 keeps the historic key
	 * "wpcap-refund-{id}" (refunds started before the attempt counter keep their key); every final
	 * decline (is_final_decline()) moves the next attempt to "wpcap-refund-{id}-{attempt}".
	 */
	public static function provider_key( $refund_id, $attempt = 0 ) {
		return 'wpcap-refund-' . absint( $refund_id ) . ( (int) $attempt > 0 ? '-' . absint( $attempt ) : '' );
	}

	/**
	 * Whether a provider error is a final decline: the provider answered and the refund was definitely
	 * not executed, so a retry may use a new idempotency key without risking a second payout.
	 *
	 * Final: HTTP 4xx except 409 (Stripe: concurrent request with the same key / parameter mismatch –
	 * the first request may still succeed) and 429 (rate limit, not stored under the key), and the
	 * gateway codes for a refund object returned with status failed/canceled. Not final (same key on the
	 * retry): no HTTP status (network error, timeout, local validation before any request) and 5xx.
	 */
	public static function is_final_decline( \WP_Error $error ) {
		if ( in_array( $error->get_error_code(), array( 'wpcap_stripe_refund_failed', 'wpcap_paypal_refund_failed', 'wpcap_mollie_refund_failed' ), true ) ) {
			return true;
		}
		$data = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		return $status >= 400 && $status < 500 && ! in_array( $status, array( 409, 429 ), true );
	}

	/**
	 * Books a refund that was paid out (status processing with provider reference): revokes and flags
	 * the tickets, updates order item and order totals and marks the refund completed – all in one
	 * transaction. Idempotent: does nothing unless the refund is still waiting for its booking. On a
	 * database error everything is rolled back and the refund stays recoverable for reconcile().
	 *
	 * @return array|\WP_Error Refund row.
	 */
	private static function finalize( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = absint( $refund_id );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::booking_error( $refund_id, 0, 'start_transaction' );
		}
		$refund = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d FOR UPDATE', $p . 'wpcap_refunds', $refund_id ), ARRAY_A );
		if ( ! $refund || 'processing' !== $refund['status'] || '' === (string) $refund['provider_reference'] ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$order_id = (int) $refund['order_id'];
		$ok = null !== $wpdb->get_row( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		$ticket_ids = array_values( array_map( 'absint', (array) json_decode( $refund['ticket_ids'], true ) ) );
		$revoked_ids = array();
		if ( $ok && $ticket_ids ) {
			$per_type = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id, COUNT(*) AS n FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ") AND refunded=0 AND status IN ('valid','checked_in','revoked') GROUP BY ticket_type_id", array_merge( array( $p . 'wpcap_tickets' ), $ticket_ids ) ) );
			$ok = '' === (string) $wpdb->last_error;
			if ( $ok ) {
				$revoked_ids = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' AND ticket_id IN (" . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ') FOR UPDATE', array_merge( array( $p . 'wpcap_tickets', $order_id ), $ticket_ids ) ) ) );
				// revoke_ids() cannot report errors: verify that no ticket of this refund is still valid.
				$still_valid = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id=%d AND status='valid' AND ticket_id IN (" . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', $order_id ), $ticket_ids ) ) );
				$ok = null !== $still_valid && 0 === (int) $still_valid;
			}
			foreach ( $ok ? $per_type : array() as $row ) {
				if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1', $p . 'wpcap_order_items', (int) $row->n, $order_id, (int) $row->ticket_type_id ) ) ) {
					$ok = false;
					break;
				}
			}
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded=1 WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', $order_id ), $ticket_ids ) ) );
		}
		$ok = $ok && false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d', $p . 'wpcap_orders', (int) $refund['amount_minor'], current_time( 'mysql', true ), $order_id ) );
		$ok = $ok && 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='completed',error_message='',updated_at=%s WHERE refund_id=%d AND status='processing'", $p . 'wpcap_refunds', current_time( 'mysql', true ), $refund_id ) );
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::booking_error( $refund_id, $order_id, 'finalize', $error );
		}
		Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND refunded=0', $p . 'wpcap_tickets', $order_id ) );
		if ( 0 === $remaining ) {
			// A database error here leaves the refund booked and queues the order status for a retry.
			self::mark_order_refunded( $order_id, 'native_refund_' . $refund_id );
		}
		$order = Orders::get( $order_id );
		Audit::record( 'refund_completed', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => (int) $refund['amount_minor'], 'revoked_tickets' => count( $revoked_ids ), 'provider' => $order ? $order->payment_provider : '' ) );
		do_action( 'wpcap_refund_completed', $refund_id, $order_id );
		self::notify_buyer( $refund_id, $order, count( $revoked_ids ) );
		return self::get( $refund_id );
	}

	/**
	 * A refund booked as completed failed later at the provider (Stripe refund.failed /
	 * charge.refund.updated, Mollie refund status failed): the money never reached the buyer. In one
	 * transaction the refund rows with one of the references (plugin refund id or "ext:{provider}:{id}")
	 * are set to `failed` (error_message `provider_refund_failed`, next attempt with a new idempotency
	 * key), the order's refunded amount is reduced again and a fully refunded order goes back to
	 * complete/paid. Revoked tickets stay revoked (seats may have been sold again); the credit note is
	 * voided (Invoices::void_credit_note_for_refund()) and `wp_pass_captain_refund_failed_v1` fires.
	 *
	 * @param string[] $references Provider refund references.
	 * @return array|\WP_Error Rows of the refunds that were reversed (empty: nothing booked under these references).
	 */
	public static function provider_failed( $order_id, array $references, $provider = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$refs = array();
		foreach ( array_filter( array_map( 'strval', $references ) ) as $ref ) {
			$refs[] = substr( $ref, 0, 191 );
			if ( '' !== (string) $provider ) { $refs[] = substr( 'ext:' . sanitize_key( (string) $provider ) . ':' . $ref, 0, 191 ); }
		}
		$refs = array_values( array_unique( $refs ) );
		if ( ! $order_id || ! $refs ) { return array(); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::db_error( 0, $order_id, 'provider_failed_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,status,payment_status,refunded_minor,total_minor FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		$rows = $order ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND status='completed' AND provider_reference IN (" . implode( ',', array_fill( 0, count( $refs ), '%s' ) ) . ') FOR UPDATE', array_merge( array( $p . 'wpcap_refunds', $order_id ), $refs ) ), ARRAY_A ) : array();
		if ( '' !== (string) $wpdb->last_error ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( 0, $order_id, 'provider_failed_lock', $error );
		}
		if ( ! $rows ) {
			$wpdb->query( 'COMMIT' );
			return array();
		}
		$amount = (int) array_sum( array_map( static function ( $row ) { return (int) $row['amount_minor']; }, $rows ) );
		$ok = true;
		foreach ( $rows as $row ) {
			$ok = $ok && 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='failed',error_message='provider_refund_failed',provider_attempt=provider_attempt+1,updated_at=%s WHERE refund_id=%d AND status='completed'", $p . 'wpcap_refunds', current_time( 'mysql', true ), (int) $row['refund_id'] ) );
		}
		$reopen = 'refunded' === (string) $order->status && (int) $order->refunded_minor - $amount < (int) $order->total_minor;
		$ok = $ok && false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_minor=GREATEST(0,CAST(refunded_minor AS SIGNED)-%d),updated_at=%s WHERE order_id=%d', $p . 'wpcap_orders', $amount, current_time( 'mysql', true ), $order_id ) );
		if ( $reopen ) {
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='complete',payment_status='paid' WHERE order_id=%d AND status='refunded'", $p . 'wpcap_orders', $order_id ) );
		}
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( 0, $order_id, 'provider_failed_update', $error );
		}
		foreach ( $rows as $row ) {
			Invoices::void_credit_note_for_refund( (int) $row['refund_id'] );
			Log::error( 'refund_provider_failed', 'Refund failed at the payment provider.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'refund_id' => (int) $row['refund_id'], 'amount_minor' => (int) $row['amount_minor'], 'reference' => (string) $row['provider_reference'], 'order_reopened' => $reopen ) ) );
			do_action( 'wp_pass_captain_refund_failed_v1', (int) $row['refund_id'], $order_id );
		}
		return $rows;
	}

	/**
	 * Finishes refunds that were paid out by the provider but not booked locally (status processing
	 * with provider reference), e.g. after a database error. Never calls the provider. Idempotent;
	 * runs with the job queue (cron) and when the refund admin page loads.
	 *
	 * @return int Number of refunds booked.
	 */
	public static function reconcile( $limit = 20 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT refund_id FROM %i WHERE status='processing' AND provider_reference<>'' ORDER BY refund_id LIMIT %d", $wpdb->prefix . 'wpcap_refunds', max( 1, absint( $limit ) ) ) );
		$done = 0;
		foreach ( (array) $ids as $id ) {
			$result = self::finalize( (int) $id );
			if ( is_array( $result ) && 'completed' === $result['status'] ) { ++$done; }
		}
		self::reconcile_order_states( $limit );
		return $done;
	}

	/** Job type that retries Orders::mark_refunded() after a database error. */
	const ORDER_STATE_JOB = 'order_mark_refunded';

	/** @var bool */
	private static $registered = false;

	/** Registers the job handler for order status retries (Plugin::run(), Jobs::process_due()). */
	public static function register() {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) { return; }
		self::$registered = true;
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/** Filter wp_pass_captain_process_job_v1: retries marking a fully refunded order as refunded. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::ORDER_STATE_JOB !== $type ) { return $handled; }
		$payload = (array) $payload;
		$order_id = absint( $payload['order_id'] ?? 0 );
		if ( ! $order_id ) { return true; }
		$result = Orders::mark_refunded( $order_id, sanitize_text_field( (string) ( $payload['reason'] ?? 'refund_retry' ) ) );
		if ( true === $result ) {
			Audit::record( 'order_refund_state_recovered', 'order', $order_id, array( 'source' => 'job' ) );
			return true;
		}
		return false;
	}

	/**
	 * Marks a fully refunded order as refunded (Orders::mark_refunded()). On a database error the refund
	 * itself stays booked; the error is logged (audit + admin notice on the refunds page) and an
	 * `order_mark_refunded` job retries it; reconcile() also picks such orders up.
	 *
	 * @return true|\WP_Error
	 */
	public static function mark_order_refunded( $order_id, $reason ) {
		$result = Orders::mark_refunded( $order_id, $reason );
		if ( true !== $result ) {
			self::schedule_order_state( $order_id, $reason, is_wp_error( $result ) ? $result->get_error_message() : '' );
		}
		return $result;
	}

	/** Logs a failed order status update after a refund and queues its retry. */
	public static function schedule_order_state( $order_id, $reason, $error = '' ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) { return; }
		Log::error(
			'order_refund_state_pending',
			sprintf( 'order %d could not be marked refunded (%s): %s; retry queued', $order_id, (string) $reason, (string) $error ),
			array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'reason' => sanitize_text_field( (string) $reason ), 'error' => substr( (string) $error, 0, 190 ) ) )
		);
		Jobs::enqueue( 'order-mark-refunded-' . $order_id . '-' . gmdate( 'YmdHi' ), self::ORDER_STATE_JOB, array( 'order_id' => $order_id, 'reason' => sanitize_text_field( (string) $reason ) ), gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) );
	}

	/**
	 * Orders whose tickets are all refunded by a completed refund (last 14 days) but that are still
	 * marked paid, i.e. Orders::mark_refunded() failed after the booking.
	 *
	 * @return int[] Order IDs.
	 */
	public static function orders_pending_refund_state( $limit = 20 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$since = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT r.order_id FROM %i r INNER JOIN %i o ON o.order_id=r.order_id WHERE r.status='completed' AND r.updated_at>=%s AND o.payment_status='paid' AND EXISTS (SELECT 1 FROM %i t WHERE t.order_id=o.order_id) AND NOT EXISTS (SELECT 1 FROM %i t2 WHERE t2.order_id=o.order_id AND t2.refunded=0) AND NOT EXISTS (SELECT 1 FROM %i r2 WHERE r2.order_id=o.order_id AND r2.status='processing') ORDER BY r.order_id LIMIT %d", $p . 'wpcap_refunds', $p . 'wpcap_orders', $since, $p . 'wpcap_tickets', $p . 'wpcap_tickets', $p . 'wpcap_refunds', max( 1, absint( $limit ) ) ) ) );
	}

	/** Marks orders found by orders_pending_refund_state() as refunded (no provider call). */
	private static function reconcile_order_states( $limit ) {
		foreach ( self::orders_pending_refund_state( $limit ) as $order_id ) {
			if ( true === Orders::mark_refunded( $order_id, 'refund_reconcile' ) ) {
				Audit::record( 'order_refund_state_recovered', 'order', $order_id, array( 'source' => 'reconcile' ) );
			}
		}
	}

	/** Refunds paid out at the provider whose local booking is still pending (admin notice). */
	public static function unbooked_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='processing' AND provider_reference<>''", $wpdb->prefix . 'wpcap_refunds' ) );
	}

	/** Set when a read used for an amount calculation failed (see calculate()); callers abort. */
	private static $read_failed = false;

	/** Read helper for amount calculations: records a failed query in $read_failed. */
	private static function checked( $result ) {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) { self::$read_failed = true; }
		return $result;
	}

	/** Database error while requesting a refund: rolled back, nothing stored, nothing paid out. */
	private static function request_db_error( $order_id, $step ) {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		$wpdb->query( 'ROLLBACK' );
		return self::db_error( 0, $order_id, $step, $error );
	}

	/** Database error before any money moved: the refund keeps its state and can be retried. */
	private static function db_error( $refund_id, $order_id, $step, $error = '' ) {
		global $wpdb;
		self::log_problem( $refund_id, $order_id, $step, '' !== $error ? $error : (string) $wpdb->last_error );
		return new \WP_Error( 'wpcap_refund_db', __( 'Die Erstattung konnte wegen eines Datenbankfehlers nicht gestartet werden. Es wurde nichts ausgezahlt; bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503 ) );
	}

	/** Database error after the payout: the booking is retried by reconcile(). */
	private static function booking_error( $refund_id, $order_id, $step, $error = '' ) {
		global $wpdb;
		self::log_problem( $refund_id, $order_id, $step, '' !== $error ? $error : (string) $wpdb->last_error );
		return new \WP_Error( 'wpcap_refund_booking_pending', __( 'Die Erstattung wurde beim Zahlungsanbieter ausgeführt; die Verbuchung wird automatisch nachgeholt.', 'pass-captain' ), array( 'status' => 202, 'refund_id' => absint( $refund_id ) ) );
	}

	/** Logs a refund database problem (PHP error log and – if the database accepts it – the audit log). */
	private static function log_problem( $refund_id, $order_id, $step, $db_error, array $extra = array() ) {
		Log::error(
			'refund_db_error',
			sprintf( 'refund %d (%s) database error: %s %s', absint( $refund_id ), $step, $db_error, $extra ? wp_json_encode( $extra ) : '' ),
			array(
				'audit'       => (bool) $order_id,
				'object_type' => 'order',
				'object_id'   => absint( $order_id ),
				'details'     => array_merge( array( 'refund_id' => absint( $refund_id ), 'step' => $step, 'error' => substr( $db_error, 0, 190 ) ), $extra ),
			)
		);
	}

	/** Returns a refund row as array or null. */
	/**
	 * Records a refund that was already paid out outside Pass Captain (e.g. WooCommerce, provider
	 * dashboard). No provider call, no approval, no capability check (system action – callers must
	 * be trusted adapters). Same accounting as execute(): refund row (status completed), refunded
	 * tickets flagged and – if still valid – revoked, capacity released, order refunded_minor,
	 * order marked refunded when no ticket is left, wpcap_refund_completed (credit note, seats, webhooks).
	 * Idempotent per $idempotency_key.
	 *
	 * @param int    $order_id        Core order ID.
	 * @param int[]  $ticket_ids      Refunded tickets (may be empty for an amount-only refund). Tickets
	 *                                of other orders or already refunded tickets are ignored.
	 * @param int    $amount_minor    Refunded gross amount; capped at the order's remaining amount.
	 * @param string $reason          Reason.
	 * @param string $idempotency_key Unique key per external refund (e.g. "woocommerce-refund-123").
	 * @param array  $args            provider_reference (string), source (string), tax_minor (int, else proportional).
	 * @return array|false|\WP_Error Refund row; false when there is nothing to record: no amount (≤ 0, also
	 *                               after capping at the remaining amount under the order lock) and no
	 *                               ticket left to refund. Nothing is written then (no zero refund row).
	 */
	public static function record_external( $order_id, array $ticket_ids, $amount_minor, $reason, $idempotency_key, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		// Neither money nor tickets: a no-op. A zero amount with tickets (free tickets, a WooCommerce refund
		// of 0.00 with items) is still recorded, because the tickets must be revoked.
		if ( (int) $amount_minor <= 0 && ! array_filter( array_map( 'absint', $ticket_ids ) ) ) { return false; }
		$key_hash = hash( 'sha256', 'external-refund|' . $order_id . '|' . sanitize_text_field( $idempotency_key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
		if ( $existing ) { return $existing; }
		if ( '' !== (string) $wpdb->last_error ) { return self::record_error( 0, $order_id, 'lookup' ); }
		// Every write, the transaction start and the commit are checked like in execute()/finalize(): on a
		// database error everything is rolled back and a retryable error is returned, so the caller can
		// record the same external refund again (idempotent per key).
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::record_error( 0, $order_id, 'start_transaction' ); }
		self::$read_failed = false;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::record_error( 0, $order_id, 'lock', true ); }
		if ( ! $order || ! in_array( $order->payment_status, array( 'paid', 'refunded' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $ticket_ids ) {
			$ticket_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE order_id=%d AND refunded=0 AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ') FOR UPDATE', array_merge( array( $p . 'wpcap_tickets', $order_id ), $ticket_ids ) ) ) );
			if ( '' !== (string) $wpdb->last_error ) { return self::record_error( 0, $order_id, 'tickets', true ); }
		}
		// The payout already happened outside, so no approval applies. Tickets and money that a native
		// refund is paying out right now stay with that refund; pending or approved native refunds of the
		// same tickets fail their re-validation in execute() instead.
		$running = self::open_refunds( $order_id, array( 'processing' ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::record_error( 0, $order_id, 'open_refunds', true ); }
		$ticket_ids = array_values( array_diff( $ticket_ids, $running['ticket_ids'] ) );
		$amount = min( max( 0, (int) $amount_minor ), max( 0, (int) $order->total_minor - (int) $order->refunded_minor - $running['amount_minor'] ) );
		if ( $amount <= 0 && ! $ticket_ids ) {
			// Lost a race (e.g. a native refund or another webhook booked the rest meanwhile): no zero row.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$tax = isset( $args['tax_minor'] ) ? min( $amount, absint( $args['tax_minor'] ) ) : self::tax_for( $order, $ticket_ids ?: null, $amount );
		if ( self::$read_failed ) { return self::record_error( 0, $order_id, 'read', true ); }
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $amount,
			'tax_minor' => $tax,
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( $ticket_ids ),
			'reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
			'status' => 'completed',
			'provider_reference' => substr( sanitize_text_field( (string) ( $args['provider_reference'] ?? 'external' ) ), 0, 191 ),
			'requested_by' => get_current_user_id(),
			'approved_by' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$refund_id = false === $inserted ? 0 : (int) $wpdb->insert_id;
		if ( ! $refund_id ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
			return $existing ? $existing : self::record_error( 0, $order_id, 'insert', false, $error );
		}
		$revoked_ids = array();
		$ok = true;
		if ( $ticket_ids ) {
			$per_type = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id, COUNT(*) AS n FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ") AND refunded=0 AND status IN ('valid','checked_in','revoked') GROUP BY ticket_type_id", array_merge( array( $p . 'wpcap_tickets' ), $ticket_ids ) ) );
			$ok = '' === (string) $wpdb->last_error;
			foreach ( $ok ? $per_type : array() as $row ) {
				if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1', $p . 'wpcap_order_items', (int) $row->n, $order_id, (int) $row->ticket_type_id ) ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$revoked_ids = Orders::revoke_ids( $ticket_ids );
				// revoke_ids() cannot report errors: verify that no ticket of this refund is still valid.
				$still_valid = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id=%d AND status='valid' AND ticket_id IN (" . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', $order_id ), $ticket_ids ) ) );
				$ok = null !== $still_valid && 0 === (int) $still_valid;
			}
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded=1 WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', $order_id ), $ticket_ids ) ) );
		}
		$ok = $ok && false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d', $p . 'wpcap_orders', $amount, $now, $order_id ) );
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			return self::record_error( $refund_id, $order_id, 'book', true );
		}
		Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND refunded=0', $p . 'wpcap_tickets', $order_id ) );
		if ( null !== $remaining && 0 === (int) $remaining && $ticket_ids ) {
			// The refund is recorded; a failed status update is logged and retried by the job queue.
			self::mark_order_refunded( $order_id, sanitize_key( $args['source'] ?? 'external' ) . '_refund_' . $refund_id );
		}
		Audit::record( 'refund_completed', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $amount, 'revoked_tickets' => count( $revoked_ids ), 'provider' => sanitize_key( $args['source'] ?? $order->payment_provider ), 'external' => true ) );
		do_action( 'wpcap_refund_completed', $refund_id, $order_id );
		return self::get( $refund_id );
	}

	/**
	 * Database error while recording an external refund: rolls back (when $rollback) and returns a
	 * retryable error. Nothing was recorded; the same call can simply be repeated.
	 */
	private static function record_error( $refund_id, $order_id, $step, $rollback = false, $error = '' ) {
		global $wpdb;
		$error = '' !== $error ? $error : (string) $wpdb->last_error;
		if ( $rollback ) { $wpdb->query( 'ROLLBACK' ); }
		self::log_problem( $refund_id, $order_id, 'record_external_' . $step, $error );
		return new \WP_Error( 'wpcap_refund_record_failed', __( 'Die außerhalb von Pass Captain ausgeführte Erstattung konnte wegen eines Datenbankfehlers nicht übernommen werden. Sie wird automatisch erneut versucht.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}

	/**
	 * Tickets and amount bound by refunds of an order that are not finished yet.
	 *
	 * @param string[] $statuses Refund statuses to include.
	 * @return array{ticket_ids:int[],amount_minor:int}
	 */
	private static function open_refunds( $order_id, array $statuses = array( 'pending_approval', 'approved', 'processing' ), $exclude_refund_id = 0 ) {
		global $wpdb;
		$statuses = array_values( array_map( static function ( $s ) { return preg_replace( '/[^a-z_]/', '', (string) $s ); }, $statuses ) );
		$rows = self::checked( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_ids,amount_minor FROM %i WHERE order_id=%d AND refund_id<>%d AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_refunds', absint( $order_id ), absint( $exclude_refund_id ) ), $statuses ) ) ) );
		$ids = array();
		$amount = 0;
		foreach ( (array) $rows as $row ) {
			$ids = array_merge( $ids, array_map( 'absint', (array) json_decode( (string) $row->ticket_ids, true ) ) );
			$amount += (int) $row->amount_minor;
		}
		return array( 'ticket_ids' => array_values( array_unique( array_filter( $ids ) ) ), 'amount_minor' => $amount );
	}

	/**
	 * Checks (under the order lock) whether a refund may still be paid out.
	 *
	 * @return string Empty when fine, otherwise the reason it must fail.
	 */
	private static function execution_conflict( array $refund, $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $order || ! in_array( $order->payment_status, array( 'paid', 'refunded' ), true ) ) {
			return __( 'Die Bestellung kann nicht mehr erstattet werden.', 'pass-captain' );
		}
		$ticket_ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) ) );
		if ( $ticket_ids ) {
			$open = (int) self::checked( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND refunded=0 AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ') FOR UPDATE', array_merge( array( $p . 'wpcap_tickets', (int) $refund['order_id'] ), $ticket_ids ) ) ) );
			$running = self::open_refunds( (int) $refund['order_id'], array( 'processing' ), (int) $refund['refund_id'] );
			if ( $open !== count( $ticket_ids ) || array_intersect( $ticket_ids, $running['ticket_ids'] ) ) {
				return __( 'Mindestens eines der Tickets wurde bereits erstattet; die Erstattung wurde nicht ausgeführt.', 'pass-captain' );
			}
		} else {
			$running = self::open_refunds( (int) $refund['order_id'], array( 'processing' ), (int) $refund['refund_id'] );
		}
		$remaining = (int) $order->total_minor - (int) $order->refunded_minor - $running['amount_minor'];
		if ( (int) $refund['amount_minor'] > $remaining ) {
			return __( 'Der Erstattungsbetrag übersteigt den noch offenen Bestellbetrag; die Erstattung wurde nicht ausgeführt.', 'pass-captain' );
		}
		return '';
	}

	public static function get( $refund_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d', $wpdb->prefix . 'wpcap_refunds', absint( $refund_id ) ), ARRAY_A );
	}

	/** Refunds of an order. */
	public static function for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d ORDER BY refund_id', $wpdb->prefix . 'wpcap_refunds', absint( $order_id ) ), ARRAY_A );
	}

	/**
	 * Fee / goodwill cap on a calculated refund: amount = max(0, value − deduct_minor − value × deduct_bp)
	 * capped at max_minor (> 0). The kept part is recorded as deducted_minor; VAT is recomputed on the
	 * refunded amount, so the credit note covers exactly what goes back.
	 *
	 * @param array $calc calculate() result.
	 * @return array $calc with amount_minor, tax_minor, deducted_minor and value_minor (before deduction).
	 */
	public static function apply_deduction( array $calc, array $options ) {
		$value = (int) $calc['amount_minor'];
		$fee = absint( $options['deduct_minor'] ?? 0 ) + intdiv( $value * min( 10000, absint( $options['deduct_bp'] ?? 0 ) ), 10000 );
		$amount = max( 0, $value - $fee );
		$max = absint( $options['max_minor'] ?? 0 );
		if ( $max > 0 ) { $amount = min( $amount, $max ); }
		$calc['value_minor'] = $value;
		$calc['deducted_minor'] = $value - $amount;
		if ( $amount !== $value ) {
			$calc['amount_minor'] = $amount;
			$calc['tax_minor'] = $value > 0 ? (int) round( (int) $calc['tax_minor'] * $amount / $value ) : 0;
		}
		return $calc;
	}

	/**
	 * Preview for the admin form: amount, VAT and kept fee for the tickets without writing anything.
	 *
	 * @return array|\WP_Error {ticket_ids, value_minor, deducted_minor, amount_minor, tax_minor, currency}
	 */
	public static function preview( $order_id, array $ticket_ids, array $options = array() ) {
		$order = Orders::get( absint( $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$open = self::open_refunds( (int) $order->order_id );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		$calc = self::calculate( $order, $requested, $open['ticket_ids'], $open['amount_minor'] );
		if ( is_wp_error( $calc ) ) { return $calc; }
		return self::apply_deduction( $calc, $options ) + array( 'currency' => (string) $order->currency );
	}

	/** Fees kept by earlier or pending refunds of an order (deducted_minor). */
	private static function deducted_total( $order_id ) {
		global $wpdb;
		$sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(deducted_minor),0) FROM %i WHERE order_id=%d AND status IN ('completed','pending_approval','approved','processing')", $wpdb->prefix . 'wpcap_refunds', absint( $order_id ) ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->last_error = ''; return 0; } // Column missing before the schema upgrade.
		return (int) $sum;
	}

	/**
	 * Calculates the refund amount for tickets (not yet refunded) from the order item snapshots.
	 * The last remaining tickets receive the rounding remainder so a full refund equals the order total.
	 */
	public static function calculate( $order, array $ticket_ids, array $reserved_ids = array(), $reserved_minor = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Orders::refundable_tickets() also covers tickets revoked by an occurrence/event cancellation.
		$open = method_exists( Orders::class, 'refundable_tickets' )
			? Orders::refundable_tickets( (int) $order->order_id )
			: $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id FROM %i WHERE order_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY ticket_id", $p . 'wpcap_tickets', (int) $order->order_id ) );
		// Tickets of pending/running refunds ($reserved_ids, worth $reserved_minor) are not open any more.
		$open_ids = array_values( array_diff( array_map( static function ( $t ) { return (int) $t->ticket_id; }, $open ), array_map( 'intval', $reserved_ids ) ) );
		$ticket_ids = $ticket_ids ? array_values( array_unique( array_map( 'absint', $ticket_ids ) ) ) : $open_ids;
		if ( ! $ticket_ids || array_diff( $ticket_ids, $open_ids ) ) {
			return new \WP_Error( 'wpcap_refund_tickets', __( 'Die gewählten Tickets gehören nicht zur Bestellung oder wurden bereits erstattet.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// Fees kept by earlier refunds (apply_deduction()) are not refunded with the remaining tickets.
		$remaining_total = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - max( 0, (int) $reserved_minor ) - self::deducted_total( (int) $order->order_id ) );
		if ( count( $ticket_ids ) === count( $open_ids ) ) {
			$amount = $remaining_total;
		} elseif ( null !== Orders::snapshot_line_discounts( $order ) ) {
			// Discount split per line known: each ticket refunds its own line's discounted price, scaled to
			// the cash part of the order (a multi-purpose voucher paid the rest; its share goes back to the
			// voucher, Vouchers\Checkout::refund_plan()).
			$amount = min( $remaining_total, self::discounted_share( $order, $ticket_ids, true ) );
		} else {
			list( $gross ) = self::line_shares( $order, $ticket_ids );
			$subtotal = max( 1, (int) $order->subtotal_minor ?: (int) $order->total_minor );
			$amount = min( $remaining_total, (int) round( $gross * (int) $order->total_minor / $subtotal ) );
		}
		$tax = self::tax_for( $order, count( $ticket_ids ) === count( $open_ids ) ? null : $ticket_ids, $amount );
		return array( 'ticket_ids' => $ticket_ids, 'amount_minor' => $amount, 'tax_minor' => $tax );
	}

	/** Refund rule for checked-in tickets: allow | approval | deny (setting refund_after_checkin). */
	public static function checkin_policy() {
		$policy = (string) Security\Policy::get( 'refund_after_checkin' );
		return in_array( $policy, array( 'allow', 'approval', 'deny' ), true ) ? $policy : 'allow';
	}

	/**
	 * Tickets of the list that were used for admission: status checked_in (single entry) or at least
	 * one effective entry in the admission state (repeatable policies keep status valid).
	 *
	 * @param int[] $ticket_ids Tickets.
	 * @return int[]
	 */
	public static function checked_in_ids( array $ticket_ids ) {
		$ticket_ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ticket_ids ) { return array(); }
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT t.ticket_id FROM %i t LEFT JOIN %i s ON s.ticket_id=t.ticket_id WHERE t.ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ") AND (t.status='checked_in' OR COALESCE(s.entries,0)>0) ORDER BY t.ticket_id", array_merge( array( $p . 'wpcap_tickets', $p . 'wpcap_admission_state' ), $ticket_ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** Optional enforcement of two-factor authentication for refund actions. */
	private static function check_actor() {
		if ( ! current_user_can( self::REFUND_CAP ) && ! current_user_can( self::APPROVE_CAP ) ) {
			return new \WP_Error( 'wpcap_refund_forbidden', __( 'Keine Berechtigung für Erstattungen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( Settings::get( 'require_2fa_for_refunds' ) && ! self::user_has_2fa( get_current_user_id() ) ) {
			return new \WP_Error( 'wpcap_refund_2fa', __( 'Für Erstattungen ist eine aktivierte Zwei-Faktor-Anmeldung erforderlich.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Detects 2FA via the Two Factor plugin or a `wpcap_user_has_2fa` filter for other providers. */
	public static function user_has_2fa( $user_id ) {
		$has = class_exists( '\\Two_Factor_Core' ) && method_exists( '\\Two_Factor_Core', 'is_user_using_two_factor' ) && \Two_Factor_Core::is_user_using_two_factor( $user_id );
		return (bool) apply_filters( 'wpcap_user_has_2fa', $has, $user_id );
	}

	/** Refund confirmation to the buyer (WooCommerce sends its own refund mail). */
	private static function notify_buyer( $refund_id, $order, $revoked ) {
		$refund = self::get( $refund_id );
		$send = $refund && $order && 'woocommerce' !== $order->payment_provider && is_email( (string) $order->customer_email );
		if ( ! apply_filters( 'wpcap_send_refund_mail', $send, $refund_id, $order ) ) { return; }
		$credit_note = (string) ( $refund['credit_note_number'] ?? '' );
		$amount_minor = (int) $refund['amount_minor'];
		$currency = (string) $refund['currency'];
		$vars = array(
			'order_id' => (string) $order->public_id,
			// Callables run in the recipient's locale (number format, translated notes).
			'refund_amount' => static function () use ( $amount_minor, $currency ) { return Localization::money_number( $amount_minor, $currency ) . ' ' . $currency; },
			'revoked_count' => (int) $revoked,
			'revoked_note' => static function () use ( $revoked ) { return Mail\Types::revoked_note( (int) $revoked ); },
			'credit_note_number' => $credit_note,
			'credit_note' => static function () use ( $credit_note ) { return Mail\Types::credit_note( $credit_note ); },
			'reason' => (string) ( $refund['reason'] ?? '' ),
		);
		// Add-on attachments for the correction document (wp_pass_captain_invoice_mail_attachments_v1); temporary files.
		$attachments = Api\Invoices::correction_mail_attachments( $refund_id, (int) $order->order_id );
		try {
			Mail\Mailer::send( 'refund_confirmation', $order->customer_email, $vars, $attachments, (string) ( $order->locale ?? '' ) );
		} finally {
			foreach ( $attachments as $file ) { wp_delete_file( $file ); }
		}
	}

	/**
	 * Gross and VAT shares (minor units) of the given tickets, from the order lines they belong to.
	 *
	 * @return int[] [gross, tax]
	 */
	/** VAT contained in $amount for the given tickets (their line VAT scaled to the amount). */
	public static function tax_for_tickets( $order, array $ticket_ids, $amount ) {
		list( $gross, $tax ) = self::line_shares( $order, $ticket_ids );
		return $gross > 0 ? min( (int) $amount, (int) round( $tax * (int) $amount / $gross ) ) : 0;
	}

	/** Gross value (minor units, before order-level discounts) of the given tickets, from their order lines. */
	public static function gross_for_tickets( $order, array $ticket_ids ) {
		list( $gross ) = self::line_shares( $order, $ticket_ids );
		return $gross;
	}

	/**
	 * Discounted gross of the given tickets: (line total − line discount) / quantity per ticket, rounded
	 * once. $cash: scaled to the amount due (orders.total_minor) over all discounted lines – the part paid
	 * in cash when a multi-purpose voucher paid the rest (factor 1 without voucher).
	 */
	private static function discounted_share( $order, array $ticket_ids, $cash = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$discounts = (array) Orders::snapshot_line_discounts( $order );
		$unit = array();
		$all = 0;
		foreach ( self::checked( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,SUM(quantity) AS quantity,SUM(line_total_minor) AS line_total FROM %i WHERE order_id=%d GROUP BY ticket_type_id', $p . 'wpcap_order_items', (int) $order->order_id ) ) ) as $item ) {
			$type = (int) $item->ticket_type_id;
			$line = max( 0, (int) $item->line_total - (int) ( $discounts[ $type ] ?? 0 ) );
			$all += $line;
			$unit[ $type ] = $line / max( 1, (int) $item->quantity );
		}
		$ids = array_values( array_map( 'absint', $ticket_ids ) );
		$sum = 0.0;
		foreach ( $ids ? self::checked( $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_type_id FROM %i WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', (int) $order->order_id ), $ids ) ) ) ) : array() as $type_id ) {
			$sum += $unit[ (int) $type_id ] ?? 0;
		}
		if ( $cash && $all > 0 && (int) $order->total_minor < $all ) { $sum = $sum * (int) $order->total_minor / $all; }
		return (int) round( $sum );
	}

	private static function line_shares( $order, array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $ticket_ids ) { return array( 0, 0 ); }
		$unit = array();
		foreach ( self::checked( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,quantity,line_total_minor,tax_minor FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', (int) $order->order_id ) ) ) as $item ) {
			$q = max( 1, (int) $item->quantity );
			$unit[ (int) $item->ticket_type_id ] = array( (int) $item->line_total_minor / $q, (int) $item->tax_minor / $q );
		}
		$ids = array_values( array_map( 'absint', $ticket_ids ) );
		$gross = 0.0;
		$tax = 0.0;
		foreach ( self::checked( $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_type_id FROM %i WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', (int) $order->order_id ), $ids ) ) ) ) as $type_id ) {
			$gross += $unit[ (int) $type_id ][0] ?? 0;
			$tax += $unit[ (int) $type_id ][1] ?? 0;
		}
		return array( (int) round( $gross ), (int) round( $tax ) );
	}

	/**
	 * VAT contained in a refund. Ticket refunds use the VAT of the refunded lines (orders can mix
	 * rates), scaled like the amount; a refund of everything left takes the remaining VAT; an
	 * amount-only refund is pro rata over the order.
	 *
	 * @param int[]|null $ticket_ids Refunded tickets, null = all remaining.
	 */
	private static function tax_for( $order, $ticket_ids, $amount ) {
		global $wpdb;
		$total = (int) $order->total_minor;
		if ( $total <= 0 || $amount <= 0 ) { return 0; }
		$refunded_tax = (int) self::checked( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tax_minor),0) FROM %i WHERE order_id=%d AND status IN ('completed','processing','approved','pending_approval')", $wpdb->prefix . 'wpcap_refunds', (int) $order->order_id ) ) );
		$remaining_tax = max( 0, (int) $order->tax_minor - $refunded_tax );
		$pending = (int) self::checked( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM %i WHERE order_id=%d AND status IN ('processing','approved','pending_approval')", $wpdb->prefix . 'wpcap_refunds', (int) $order->order_id ) ) );
		$remaining_total = max( 0, $total - (int) $order->refunded_minor - $pending );
		if ( $amount >= $remaining_total ) {
			return min( $amount, $remaining_tax );
		}
		if ( ! $ticket_ids ) {
			return min( $remaining_tax, (int) round( (int) $order->tax_minor * $amount / $total ) );
		}
		list( $gross, $tax ) = self::line_shares( $order, $ticket_ids );
		// Line VAT was computed on the discounted line amount: scale it against the discounted gross of
		// the tickets (not the list price), else a partial refund of a discounted order books too little VAT.
		$discounted = null !== Orders::snapshot_line_discounts( $order ) ? self::discounted_share( $order, $ticket_ids ) : (int) round( $gross * $total / max( 1, (int) $order->subtotal_minor ?: $total ) );
		if ( $discounted <= 0 ) { return min( $remaining_tax, (int) round( (int) $order->tax_minor * $amount / $total ) ); }
		return min( $remaining_tax, $amount, (int) round( $tax * $amount / $discounted ) );
	}
}
