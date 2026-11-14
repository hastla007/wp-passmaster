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
	 * @return array|\WP_Error Refund row as array.
	 */
	public static function request( $order_id, array $ticket_ids, $reason, $idempotency_key ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$key_hash = hash( 'sha256', 'refund|' . $order_id . '|' . sanitize_text_field( $idempotency_key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { return $existing; }

		$check = self::check_actor();
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
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return $existing; }
		$open = self::open_refunds( $order_id );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $requested && array_intersect( $requested, $open['ticket_ids'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_overlap', __( 'Für mindestens eines der gewählten Tickets läuft bereits eine Erstattung oder sie wartet auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$calc = self::calculate( $order, $requested, $open['ticket_ids'], $open['amount_minor'] );
		if ( is_wp_error( $calc ) ) { $wpdb->query( 'ROLLBACK' ); return $calc; }

		// The threshold applies to everything refunded or pending for this order, so splitting a
		// large refund into small requests does not avoid the second approval.
		$threshold = (int) Settings::get( 'refund_approval_threshold' );
		$cumulative = (int) $order->refunded_minor + $open['amount_minor'] + $calc['amount_minor'];
		$needs_approval = $threshold > 0 && $cumulative > $threshold && ! current_user_can( self::APPROVE_CAP );
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $calc['amount_minor'],
			'tax_minor' => $calc['tax_minor'],
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( $calc['ticket_ids'] ),
			'reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
			'status' => $needs_approval ? 'pending_approval' : 'approved',
			'requested_by' => get_current_user_id(),
			'approved_by' => $needs_approval ? 0 : get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
			return $existing ? $existing : new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$refund_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $calc['amount_minor'], 'needs_approval' => $needs_approval ) );
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, number_format_i18n( $calc['amount_minor'] / 100, 2 ) . ' ' . $order->currency ) );
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
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		if ( ! $refund || ! in_array( $refund['status'], array( 'approved', 'failed' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		// Re-validate under the order lock right before money moves: an approved refund may have been
		// overtaken by another refund of the same tickets (or the order total may be used up).
		$locked_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $refund['order_id'] ) );
		$invalid = self::execution_conflict( $refund, $locked_order );
		if ( '' !== $invalid ) {
			$failed = $wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'failed', 'error_message' => substr( $invalid, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
			if ( false === $failed || false === $wpdb->query( 'COMMIT' ) ) {
				$error = (string) $wpdb->last_error;
				$wpdb->query( 'ROLLBACK' );
				return self::db_error( $refund_id, (int) $refund['order_id'], 'mark_failed', $error );
			}
			Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => 'wpcap_refund_conflict' ) );
			return new \WP_Error( 'wpcap_refund_conflict', $invalid, array( 'status' => 409 ) );
		}
		// Claim: must move exactly this row out of approved/failed, and the commit must succeed,
		// before any money moves. Otherwise nothing happened and the refund can simply be retried.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET status='processing',provider_reference='',updated_at=%s WHERE refund_id=%d AND status IN ('approved','failed')", current_time( 'mysql', true ), $refund_id ) );
		if ( 1 !== (int) $claimed || false === $wpdb->query( 'COMMIT' ) ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::db_error( $refund_id, (int) $refund['order_id'], 'claim', $error );
		}

		$order = Orders::get( (int) $refund['order_id'] );
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		$provider_reference = 'manual';
		if ( $gateway && $gateway->supports_refunds() && (int) $refund['amount_minor'] > 0 ) {
			$result = $gateway->refund( (array) $order, (int) $refund['amount_minor'], 'wpcap-refund-' . $refund_id );
			if ( is_wp_error( $result ) ) {
				$failed = $wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'failed', 'error_message' => substr( $result->get_error_message(), 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id, 'status' => 'processing' ) );
				if ( 1 !== (int) $failed ) {
					// Stays "processing" without provider reference: needs a manual check, never an automatic retry.
					self::log_problem( $refund_id, (int) $refund['order_id'], 'mark_failed_after_provider_error', (string) $wpdb->last_error );
				}
				Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => $result->get_error_code() ) );
				return $result;
			}
			$provider_reference = (string) $result;
			if ( '' === $provider_reference ) { $provider_reference = 'wpcap-refund-' . $refund_id; }
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
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		if ( ! $refund || 'processing' !== $refund['status'] || '' === (string) $refund['provider_reference'] ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$order_id = (int) $refund['order_id'];
		$ok = null !== $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$ticket_ids = array_map( 'absint', (array) json_decode( $refund['ticket_ids'], true ) );
		$revoked_ids = array();
		if ( $ok && $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$per_type = $wpdb->get_results( "SELECT ticket_type_id, COUNT(*) AS n FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND refunded=0 AND status IN ('valid','checked_in','revoked') GROUP BY ticket_type_id" );
			$ok = '' === (string) $wpdb->last_error;
			if ( $ok ) {
				$revoked_ids = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d AND status='valid' FOR UPDATE", $order_id ) ) );
				// revoke_ids() cannot report errors: verify that no ticket of this refund is still valid.
				$still_valid = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d AND status='valid'", $order_id ) );
				$ok = null !== $still_valid && 0 === (int) $still_valid;
			}
			foreach ( $ok ? $per_type : array() as $row ) {
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", (int) $row->n, $order_id, (int) $row->ticket_type_id ) ) ) {
					$ok = false;
					break;
				}
			}
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN ($ids) AND order_id=%d", $order_id ) );
		}
		$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d", (int) $refund['amount_minor'], current_time( 'mysql', true ), $order_id ) );
		$ok = $ok && 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET status='completed',error_message='',updated_at=%s WHERE refund_id=%d AND status='processing'", current_time( 'mysql', true ), $refund_id ) );
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return self::booking_error( $refund_id, $order_id, 'finalize', $error );
		}
		Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0", $order_id ) );
		if ( 0 === $remaining ) {
			Orders::mark_refunded( $order_id, 'native_refund_' . $refund_id );
		}
		$order = Orders::get( $order_id );
		Audit::record( 'refund_completed', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => (int) $refund['amount_minor'], 'revoked_tickets' => count( $revoked_ids ), 'provider' => $order->payment_provider ) );
		do_action( 'wpcap_refund_completed', $refund_id, $order_id );
		self::notify_buyer( $refund_id, $order, count( $revoked_ids ) );
		return self::get( $refund_id );
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
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT refund_id FROM {$wpdb->prefix}wpcap_refunds WHERE status='processing' AND provider_reference<>'' ORDER BY refund_id LIMIT %d", max( 1, absint( $limit ) ) ) );
		$done = 0;
		foreach ( (array) $ids as $id ) {
			$result = self::finalize( (int) $id );
			if ( is_array( $result ) && 'completed' === $result['status'] ) { ++$done; }
		}
		return $done;
	}

	/** Refunds paid out at the provider whose local booking is still pending (admin notice). */
	public static function unbooked_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_refunds WHERE status='processing' AND provider_reference<>''" );
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
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operators must see failed bookings.
		error_log( sprintf( 'Pass Captain: refund %d (%s) database error: %s %s', absint( $refund_id ), $step, $db_error, $extra ? wp_json_encode( $extra ) : '' ) );
		if ( $order_id ) {
			Audit::record( 'refund_db_error', 'order', absint( $order_id ), array_merge( array( 'refund_id' => absint( $refund_id ), 'step' => $step, 'error' => substr( $db_error, 0, 190 ) ), $extra ) );
		}
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
	 * @return array|\WP_Error Refund row.
	 */
	public static function record_external( $order_id, array $ticket_ids, $amount_minor, $reason, $idempotency_key, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$key_hash = hash( 'sha256', 'external-refund|' . $order_id . '|' . sanitize_text_field( $idempotency_key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { return $existing; }
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( ! $order || ! in_array( $order->payment_status, array( 'paid', 'refunded' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$ticket_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d AND refunded=0 FOR UPDATE", $order_id ) ) );
		}
		// The payout already happened outside, so no approval applies. Tickets and money that a native
		// refund is paying out right now stay with that refund; pending or approved native refunds of the
		// same tickets fail their re-validation in execute() instead.
		$running = self::open_refunds( $order_id, array( 'processing' ) );
		$ticket_ids = array_values( array_diff( $ticket_ids, $running['ticket_ids'] ) );
		$amount = min( max( 0, (int) $amount_minor ), max( 0, (int) $order->total_minor - (int) $order->refunded_minor - $running['amount_minor'] ) );
		$tax = isset( $args['tax_minor'] ) ? min( $amount, absint( $args['tax_minor'] ) ) : self::tax_for( $order, $ticket_ids ?: null, $amount );
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
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
			return $existing ? $existing : new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$refund_id = (int) $wpdb->insert_id;
		$revoked_ids = array();
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			foreach ( $wpdb->get_results( "SELECT ticket_type_id, COUNT(*) AS n FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND refunded=0 AND status IN ('valid','checked_in','revoked') GROUP BY ticket_type_id" ) as $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", (int) $row->n, $order_id, (int) $row->ticket_type_id ) );
			}
			$revoked_ids = Orders::revoke_ids( $ticket_ids );
			$wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN ($ids)" );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d", $amount, $now, $order_id ) );
		$wpdb->query( 'COMMIT' );
		Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0", $order_id ) );
		if ( 0 === $remaining && $ticket_ids ) {
			Orders::mark_refunded( $order_id, sanitize_key( $args['source'] ?? 'external' ) . '_refund_' . $refund_id );
		}
		Audit::record( 'refund_completed', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $amount, 'revoked_tickets' => count( $revoked_ids ), 'provider' => sanitize_key( $args['source'] ?? $order->payment_provider ), 'external' => true ) );
		do_action( 'wpcap_refund_completed', $refund_id, $order_id );
		return self::get( $refund_id );
	}

	/**
	 * Tickets and amount bound by refunds of an order that are not finished yet.
	 *
	 * @param string[] $statuses Refund statuses to include.
	 * @return array{ticket_ids:int[],amount_minor:int}
	 */
	private static function open_refunds( $order_id, array $statuses = array( 'pending_approval', 'approved', 'processing' ), $exclude_refund_id = 0 ) {
		global $wpdb;
		$in = implode( ',', array_map( static function ( $s ) { return "'" . preg_replace( '/[^a-z_]/', '', (string) $s ) . "'"; }, $statuses ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_ids,amount_minor FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d AND refund_id<>%d AND status IN ($in)", absint( $order_id ), absint( $exclude_refund_id ) ) );
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
			$ids = implode( ',', $ticket_ids );
			$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d AND refunded=0 FOR UPDATE", (int) $refund['order_id'] ) );
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
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE refund_id=%d", absint( $refund_id ) ), ARRAY_A );
	}

	/** Refunds of an order. */
	public static function for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", absint( $order_id ) ), ARRAY_A );
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
			: $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY ticket_id", (int) $order->order_id ) );
		// Tickets of pending/running refunds ($reserved_ids, worth $reserved_minor) are not open any more.
		$open_ids = array_values( array_diff( array_map( static function ( $t ) { return (int) $t->ticket_id; }, $open ), array_map( 'intval', $reserved_ids ) ) );
		$ticket_ids = $ticket_ids ? array_values( array_unique( array_map( 'absint', $ticket_ids ) ) ) : $open_ids;
		if ( ! $ticket_ids || array_diff( $ticket_ids, $open_ids ) ) {
			return new \WP_Error( 'wpcap_refund_tickets', __( 'Die gewählten Tickets gehören nicht zur Bestellung oder wurden bereits erstattet.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$remaining_total = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - max( 0, (int) $reserved_minor ) );
		if ( count( $ticket_ids ) === count( $open_ids ) ) {
			$amount = $remaining_total;
		} else {
			list( $gross ) = self::line_shares( $order, $ticket_ids );
			$subtotal = max( 1, (int) $order->subtotal_minor ?: (int) $order->total_minor );
			$amount = min( $remaining_total, (int) round( $gross * (int) $order->total_minor / $subtotal ) );
		}
		$tax = self::tax_for( $order, count( $ticket_ids ) === count( $open_ids ) ? null : $ticket_ids, $amount );
		return array( 'ticket_ids' => $ticket_ids, 'amount_minor' => $amount, 'tax_minor' => $tax );
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
		$send = $refund && 'woocommerce' !== $order->payment_provider && is_email( (string) $order->customer_email );
		if ( ! apply_filters( 'wpcap_send_refund_mail', $send, $refund_id, $order ) ) { return; }
		$credit_note = (string) ( $refund['credit_note_number'] ?? '' );
		$amount_minor = (int) $refund['amount_minor'];
		$currency = (string) $refund['currency'];
		$vars = array(
			'order_id' => (string) $order->public_id,
			// Callables run in the recipient's locale (number format, translated notes).
			'refund_amount' => static function () use ( $amount_minor, $currency ) { return number_format_i18n( $amount_minor / 100, 2 ) . ' ' . $currency; },
			'revoked_count' => (int) $revoked,
			'revoked_note' => static function () use ( $revoked ) { return Mail\Types::revoked_note( (int) $revoked ); },
			'credit_note_number' => $credit_note,
			'credit_note' => static function () use ( $credit_note ) { return Mail\Types::credit_note( $credit_note ); },
			'reason' => (string) ( $refund['reason'] ?? '' ),
		);
		Mail\Mailer::send( 'refund_confirmation', $order->customer_email, $vars, array(), (string) ( $order->locale ?? '' ) );
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

	private static function line_shares( $order, array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $ticket_ids ) { return array( 0, 0 ); }
		$unit = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,quantity,line_total_minor,tax_minor FROM {$p}wpcap_order_items WHERE order_id=%d", (int) $order->order_id ) ) as $item ) {
			$q = max( 1, (int) $item->quantity );
			$unit[ (int) $item->ticket_type_id ] = array( (int) $item->line_total_minor / $q, (int) $item->tax_minor / $q );
		}
		$ids = implode( ',', array_map( 'absint', $ticket_ids ) );
		$gross = 0.0;
		$tax = 0.0;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_id IN ($ids)", (int) $order->order_id ) ) as $type_id ) {
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
		$refunded_tax = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tax_minor),0) FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d AND status IN ('completed','processing','approved','pending_approval')", (int) $order->order_id ) );
		$remaining_tax = max( 0, (int) $order->tax_minor - $refunded_tax );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d AND status IN ('processing','approved','pending_approval')", (int) $order->order_id ) );
		$remaining_total = max( 0, $total - (int) $order->refunded_minor - $pending );
		if ( $amount >= $remaining_total ) {
			return min( $amount, $remaining_tax );
		}
		if ( ! $ticket_ids ) {
			return min( $remaining_tax, (int) round( (int) $order->tax_minor * $amount / $total ) );
		}
		list( $gross, $tax ) = self::line_shares( $order, $ticket_ids );
		if ( $gross <= 0 ) { return min( $remaining_tax, (int) round( (int) $order->tax_minor * $amount / $total ) ); }
		return min( $remaining_tax, $amount, (int) round( $tax * $amount / $gross ) );
	}
}
