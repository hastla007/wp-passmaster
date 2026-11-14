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
	 * @param array  $args            system (bool: buyer self-service – skips the actor check and never
	 *                                approves itself), require_approval (bool: always wait for an approver,
	 *                                whatever the threshold), source (string, e.g. "portal"; stored with
	 *                                schema 3.9.4).
	 * @return array|\WP_Error Refund row as array.
	 */
	public static function request( $order_id, array $ticket_ids, $reason, $idempotency_key, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$system = ! empty( $args['system'] );
		$existing = self::find_by_key( $order_id, $idempotency_key );
		if ( $existing ) { return self::resume( $existing ); }
		$key_hash = self::key_hash( $order_id, $idempotency_key );

		if ( ! $system ) {
			$check = self::check_actor();
			if ( is_wp_error( $check ) ) { return $check; }
		}
		$order = Orders::get( $order_id );
		if ( ! $order && '' !== $wpdb->last_error ) { return self::busy(); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === $order->payment_provider ) {
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// Serialise refund requests per order: overlap and approval checks below must see every
		// refund that is already pending or running for this order.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return self::resume( $existing ); }
		$open = self::open_refunds( $order_id );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $requested && array_intersect( $requested, $open['ticket_ids'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_overlap', __( 'Für mindestens eines der gewählten Tickets läuft bereits eine Erstattung oder sie wartet auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$calc = self::calculate( $order, $requested, $open['ticket_ids'], $open['amount_minor'] );
		// The reads above are plain reads: one that failed lost the connection (and the transaction),
		// so its empty result proves nothing (A183).
		if ( false === Orders::transaction_alive() ) { return self::busy(); }
		if ( is_wp_error( $calc ) ) { $wpdb->query( 'ROLLBACK' ); return $calc; }

		// A131: tickets already used for admission are only refunded with an explicit exception (reason,
		// approver capability) or by a system flow (cancelled date/event); the refund then always needs
		// the second approval, except for system flows.
		$used = self::used_ticket_ids( $calc['ticket_ids'] );
		$used_override = trim( sanitize_text_field( (string) ( $args['used_override'] ?? '' ) ) );
		if ( $used && ! $system && empty( $args['allow_used'] ) ) {
			if ( '' === $used_override || ! current_user_can( self::APPROVE_CAP ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error(
					'wpcap_refund_used_tickets',
					current_user_can( self::APPROVE_CAP )
						/* translators: %d: number of tickets */
						? sprintf( _n( '%d gewähltes Ticket wurde bereits zum Einlass genutzt. Für eine Erstattung bitte eine Ausnahme mit Begründung angeben.', '%d gewählte Tickets wurden bereits zum Einlass genutzt. Für eine Erstattung bitte eine Ausnahme mit Begründung angeben.', count( $used ), 'pass-captain' ), count( $used ) )
						/* translators: %d: number of tickets */
						: sprintf( _n( '%d gewähltes Ticket wurde bereits zum Einlass genutzt und kann nur mit Freigabeberechtigung erstattet werden.', '%d gewählte Tickets wurden bereits zum Einlass genutzt und können nur mit Freigabeberechtigung erstattet werden.', count( $used ), 'pass-captain' ), count( $used ) ),
					array( 'status' => 409, 'used_ticket_ids' => $used )
				);
			}
			$args['require_approval'] = true;
			/* translators: %s: reason for the exception */
			$reason = trim( $reason . ' ' . sprintf( __( '[eingelassen, Ausnahme: %s]', 'pass-captain' ), $used_override ) );
		}

		// The threshold applies to everything refunded or pending for this order, so splitting a
		// large refund into small requests does not avoid the second approval.
		$threshold = (int) Settings::get( 'refund_approval_threshold' );
		$cumulative = (int) $order->refunded_minor + $open['amount_minor'] + $calc['amount_minor'];
		$needs_approval = ! empty( $args['require_approval'] ) || ( $threshold > 0 && $cumulative > $threshold && ( $system || ! current_user_can( self::APPROVE_CAP ) ) );
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
			// A buyer request has no WordPress user as requester (any approver may decide on it).
			'requested_by' => $system ? 0 : get_current_user_id(),
			'approved_by' => $needs_approval ? 0 : get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		);
		$source = sanitize_key( (string) ( $args['source'] ?? '' ) );
		if ( '' !== $source && self::source_ready() ) { $row['source'] = substr( $source, 0, 20 ); }
		if ( false === Orders::transaction_alive() ) { return self::busy(); }
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", $row );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
			return $existing ? self::resume( $existing ) : self::busy();
		}
		$refund_id = (int) $wpdb->insert_id;
		if ( false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		// Durable? The reply of a failed COMMIT may be lost although it went through, and a COMMIT
		// that wpdb re-ran after a reconnect committed nothing (A183).
		$saved = $wpdb->get_var( $wpdb->prepare( "SELECT refund_id FROM {$p}wpcap_refunds WHERE refund_id=%d AND idempotency_key=%s", $refund_id, $key_hash ) );
		if ( (int) $saved !== $refund_id ) { return self::busy(); }
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $calc['amount_minor'], 'needs_approval' => $needs_approval, 'source' => '' !== $source ? $source : 'admin' ) );
		if ( $needs_approval && 'portal' === $source ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Stornierungsanfrage wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s hat die Käuferin bzw. der Käufer im Bestellportal eine Stornierung mit Erstattung über %2$s angefragt. Bitte unter „Erstattungen zur Freigabe“ freigeben oder ablehnen.', 'pass-captain' ), $order->public_id, Money::format( $calc['amount_minor'], $order->currency ) ) );
			return self::get( $refund_id );
		}
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, Money::format( $calc['amount_minor'], $order->currency ) ) );
			return self::get( $refund_id );
		}
		return self::execute( $refund_id );
	}

	/**
	 * Requests an amount-only refund (no ticket is revoked), e.g. the price difference of a ticket
	 * exchange to a cheaper ticket. Same rules as request(): idempotent per key, serialised per
	 * order, capped at the order's remaining amount, second approval above the threshold (system
	 * requests – e.g. a customer self-service – never count as approver). Executes immediately
	 * unless an approval is required.
	 *
	 * @param int    $order_id        Order ID.
	 * @param int    $amount_minor    Gross amount to refund (> 0).
	 * @param string $reason          Reason.
	 * @param string $idempotency_key Unique key for this request.
	 * @param array  $args            system (bool: skip the actor check, never self-approve), tax_minor (int, else pro rata).
	 * @return array|\WP_Error Refund row.
	 */
	public static function request_amount( $order_id, $amount_minor, $reason, $idempotency_key, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$amount_minor = absint( $amount_minor );
		$system = ! empty( $args['system'] );
		$key_hash = hash( 'sha256', 'refund-amount|' . $order_id . '|' . sanitize_text_field( $idempotency_key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { return $existing; }
		if ( ! $system ) {
			$check = self::check_actor();
			if ( is_wp_error( $check ) ) { return $check; }
		}
		if ( $amount_minor < 1 ) {
			return new \WP_Error( 'wpcap_refund_amount', __( 'Der Erstattungsbetrag muss größer als 0 sein.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		// A reversed refund of a fully refunded order is paid out again from a "refunded" order (A348).
		$states = ! empty( $args['allow_refunded'] ) ? array( 'paid', 'refunded' ) : array( 'paid' );
		if ( ! $order || ! in_array( $order->payment_status, $states, true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === $order->payment_provider ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return $existing; }
		$open = self::open_refunds( $order_id );
		$remaining = (int) $order->total_minor - (int) $order->refunded_minor - $open['amount_minor'];
		if ( $amount_minor > $remaining ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_amount', __( 'Der Erstattungsbetrag übersteigt den noch offenen Bestellbetrag.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$tax = isset( $args['tax_minor'] ) ? min( $amount_minor, absint( $args['tax_minor'] ) ) : self::tax_for( $order, null, $amount_minor );
		$threshold = (int) Settings::get( 'refund_approval_threshold' );
		$cumulative = (int) $order->refunded_minor + $open['amount_minor'] + $amount_minor;
		$needs_approval = $threshold > 0 && $cumulative > $threshold && ( $system || ! current_user_can( self::APPROVE_CAP ) );
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $amount_minor,
			'tax_minor' => $tax,
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( array() ),
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
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $amount_minor, 'needs_approval' => $needs_approval, 'amount_only' => true ) );
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, Money::format( $amount_minor, $order->currency ) ) );
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
		if ( 1 !== (int) $approved ) {
			// Approved/rejected by someone else meanwhile, or a database error: never execute twice.
			return new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
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
	 */
	public static function execute( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = absint( $refund_id );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		// A failed lock is no missing refund (A183).
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		// A refund left in "processing" after the provider paid out (provider_reference stored) is
		// finished locally without a second payout (see repair_stuck()).
		$resume = $refund && 'processing' === $refund['status'] && '' !== (string) $refund['provider_reference'];
		if ( ! $refund || ( ! $resume && ! in_array( $refund['status'], array( 'approved', 'failed' ), true ) ) ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		if ( $resume ) {
			$wpdb->query( 'COMMIT' );
			return self::finish_locally( $refund, (string) $refund['provider_reference'] );
		}
		// Re-validate under the order lock right before money moves: an approved refund may have been
		// overtaken by another refund of the same tickets (or the order total may be used up).
		$locked_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $refund['order_id'] ) );
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		$invalid = self::execution_conflict( $refund, $locked_order );
		// A conflict found by reads that failed is no conflict: the refund stays approved (A183).
		if ( self::CONFLICT_UNKNOWN === $invalid || false === Orders::transaction_alive() ) { return self::busy(); }
		if ( '' !== $invalid ) {
			if ( false === StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => substr( $invalid, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				return self::busy();
			}
			Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => 'wpcap_refund_conflict' ) );
			return new \WP_Error( 'wpcap_refund_conflict', $invalid, array( 'status' => 409 ) );
		}
		$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'processing', array( 'updated_at' => current_time( 'mysql', true ) ) );
		if ( 1 !== $moved || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			// Nothing paid out yet. Only a durable "processing" (e.g. the COMMIT went through but its
			// reply was lost) is this request's claim to pay out; otherwise it is safe to retry.
			if ( 'processing' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_refunds WHERE refund_id=%d AND provider_reference=''", $refund_id ) ) ) {
				return Errors::busy( __( 'Die Erstattung konnte gerade nicht gestartet werden. Bitte erneut versuchen.', 'pass-captain' ) );
			}
		}

		$order = Orders::get( (int) $refund['order_id'] );
		if ( ! $order ) {
			// Read failed after the claim: nothing was paid out. processing → failed (StateMachine) with
			// a note that lets a repeated request run it again (resume()).
			StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => self::RETRY_NOTE, 'updated_at' => current_time( 'mysql', true ) ), array( 'provider_reference' => '' ) );
			return Errors::busy( __( 'Die Erstattung konnte gerade nicht gestartet werden. Bitte erneut versuchen.', 'pass-captain' ) );
		}
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		$provider_reference = 'manual';
		if ( $gateway && $gateway->supports_refunds() && (int) $refund['amount_minor'] > 0 ) {
			$result = $gateway->refund( (array) $order, (int) $refund['amount_minor'], self::key_for( $refund_id ) );
			if ( is_wp_error( $result ) && self::is_unknown_outcome( $result ) ) {
				// A272: timeout, transport error, 5xx or 409 – the provider may have paid out. The refund stays
				// "processing" without a reference (never retried as "failed") and is reconciled with the
				// provider (job refund_reconcile, webhooks, admin lookup or manual confirmation).
				return self::mark_outcome_unknown( $refund, $result );
			}
			if ( is_wp_error( $result ) ) {
				StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => substr( Redact::text( $result->get_error_message(), false ), 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'status' => 'processing' ) );
				Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => $result->get_error_code() ) );
				return $result;
			}
			$provider_reference = is_array( $result ) ? (string) ( $result['reference'] ?? '' ) : (string) $result;
		}
		// The money has moved: record it first, so a failure below is repaired without a second payout.
		$record = array( 'provider_reference' => substr( '' !== $provider_reference ? $provider_reference : 'manual', 0, 191 ), 'updated_at' => current_time( 'mysql', true ) );
		$pending = isset( $result ) && is_array( $result ) && 'pending' === ( $result['status'] ?? '' );
		if ( self::provider_status_ready() && isset( $result ) && ! is_wp_error( $result ) ) {
			// A348: accepted but not completed by the provider yet – booked now, followed until it settles.
			$record['provider_status'] = $pending ? 'pending' : 'succeeded';
		}
		$wpdb->update( "{$p}wpcap_refunds", $record, array( 'refund_id' => $refund_id, 'status' => 'processing' ) );
		if ( $pending && self::provider_status_ready() ) {
			Audit::record( 'refund_provider_pending', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'reference' => $provider_reference ) );
			self::schedule_reconcile( $refund_id, 0 );
		}
		return self::finish_locally( $refund, $provider_reference );
	}

	/**
	 * Local bookkeeping of a refund whose payout is done: tickets, order lines, counters, order total,
	 * status completed, hooks and buyer mail. Idempotent (tickets/amount only once via the status).
	 */
	private static function finish_locally( array $refund, $provider_reference ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = (int) $refund['refund_id'];
		$order_id = (int) $refund['order_id'];
		$order = Orders::get( $order_id );
		// Stays "processing" with its provider reference: repair_stuck() finishes it later. After a
		// lost connection part of the steps below may have been committed on their own; every step
		// is idempotent, so the repeat completes exactly what is missing (A183).
		$failed = static function () use ( $wpdb, $order_id, $refund_id ) {
			$lost = false === Orders::transaction_alive();
			$wpdb->query( 'ROLLBACK' );
			if ( $lost ) { CapacityCounters::repair_order( $order_id ); }
			Audit::record( 'refund_bookkeeping_failed', 'order', $order_id, array( 'refund_id' => $refund_id ) );
			return Errors::busy( __( 'Die Erstattung wurde ausgezahlt, konnte aber noch nicht verbucht werden; sie wird automatisch nachgebucht.', 'pass-captain' ) );
		};
		if ( ! $order || false === $wpdb->query( 'START TRANSACTION' ) ) { return $failed(); }
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ) );
		if ( '' !== $wpdb->last_error ) { return $failed(); }
		if ( ! $locked || 'processing' !== $locked->status ) {
			$wpdb->query( 'ROLLBACK' );
			return self::get( $refund_id );
		}
		$ticket_ids = array_values( array_filter( array_map( 'absint', (array) json_decode( $refund['ticket_ids'], true ) ) ) );
		$revoked_ids = array();
		$released = array();
		$ids = implode( ',', $ticket_ids );
		if ( $ticket_ids ) {
			$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id,status,refunded FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d ORDER BY ticket_id FOR UPDATE", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			if ( '' !== $wpdb->last_error ) { return $failed(); }
			$counted = array();
			$flag_only = array();
			foreach ( (array) $tickets as $ticket ) {
				if ( 'valid' === $ticket->status ) {
					$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id=%d AND status='valid'", (int) $ticket->ticket_id ) );
					if ( false === $changed ) { return $failed(); }
					if ( 1 === (int) $changed ) { $revoked_ids[] = (int) $ticket->ticket_id; }
				}
				if ( in_array( $ticket->status, array( 'valid', 'checked_in', 'revoked' ), true ) ) {
					$counted[] = (int) $ticket->ticket_id;
				} elseif ( ! (int) $ticket->refunded ) {
					$flag_only[] = (int) $ticket->ticket_id;
				}
			}
			// One rule for every flow (CapacityRelease, A579): the ticket is flagged refunded and its
			// unit is released only if no earlier flow (revoke, exchange) released it – a repeat never
			// counts a ticket twice.
			$released = CapacityRelease::release_tickets( $order_id, $counted, array( 'refund' => true ) );
			if ( false === $released ) { return $failed(); }
			if ( $flag_only && false === $wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN (" . implode( ',', $flag_only ) . ') AND refunded=0' ) ) { return $failed(); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			// Refunded units leave the sold counters (CapacityCounters).
			if ( false === CapacityCounters::sync_order_now( $order_id ) ) { return $failed(); }
		}
		// Status and refunded total change in one statement, and only once every ticket of the refund
		// is flagged: a statement that ran on its own after a lost connection never completes a half
		// booking, and the total is never added twice. The refund transition is guarded like every
		// other one (StateMachine 'refund': only processing → completed).
		$done = $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id SET r.status='completed',r.provider_reference=%s,r.error_message='',r.updated_at=%s,o.refunded_minor=LEAST(o.total_minor,o.refunded_minor+r.amount_minor),o.updated_at=%s WHERE r.refund_id=%d AND " . StateMachine::source_sql( 'refund', 'completed', 'r.status' )
			. ( $ticket_ids ? " AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_tickets t WHERE t.ticket_id IN ($ids) AND t.order_id=r.order_id AND (t.refunded=0 OR t.status='valid'))" : '' ),
			substr( '' !== (string) $provider_reference ? (string) $provider_reference : 'manual', 0, 191 ),
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			$refund_id
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer list and fixed state list.
		if ( ! $done ) { return $failed(); }
		if ( false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			// Durable when the reply of the COMMIT was lost after it went through.
			if ( 'completed' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_refunds WHERE refund_id=%d", $refund_id ) ) ) { return $failed(); }
		}
		$revoked = count( $revoked_ids );
		if ( ! empty( $revoked_ids ) ) {
			Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		}
		// Partial release: waitlists and allocation holds get exactly these units back (A578).
		CapacityRelease::announce( $order_id, $released, 'partial_refund', array( 'refund_id' => $refund_id ) );
		// The order is refunded once no ticket is left that is in use or still refundable (A357):
		// tickets revoked by an admin or exchanged away do not keep it open.
		$remaining = self::refundable_count( $order_id );
		if ( null === $remaining ) { $remaining = self::refundable_count( $order_id ); }
		// A failed count is no "nothing left": that would refund the whole order (A183).
		if ( 0 === $remaining ) {
			Orders::mark_refunded( (int) $refund['order_id'], 'native_refund_' . $refund_id );
		}
		Audit::record( 'refund_completed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'amount_minor' => (int) $refund['amount_minor'], 'revoked_tickets' => $revoked, 'provider' => $order->payment_provider ) );
		do_action( 'wpcap_refund_completed', $refund_id, (int) $refund['order_id'] );
		self::notify_buyer( $refund_id, $order, $revoked );
		return self::get( $refund_id );
	}

	/**
	 * Cron (wpcap_cleanup_reservations): finishes refunds stuck in "processing" after the provider paid
	 * out (provider_reference stored). Those stuck without a reference (crash during the provider call)
	 * get the outcome "unknown" and are reconciled with the provider (job refund_reconcile) — they are
	 * never paid out a second time automatically (A272, A350).
	 *
	 * @return int Number of refunds finished.
	 */
	public static function repair_stuck() {
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE status='processing' AND updated_at < %s AND (provider_reference<>'' OR error_message<>%s) ORDER BY refund_id LIMIT 50", $cutoff, self::OUTCOME_UNKNOWN ), ARRAY_A );
		$done = 0;
		foreach ( (array) $rows as $row ) {
			if ( '' !== (string) $row['provider_reference'] ) {
				$result = self::finish_locally( $row, (string) $row['provider_reference'] );
				if ( ! is_wp_error( $result ) ) {
					++$done;
				}
				continue;
			}
			// Crash during the provider call, or flagged by an older version ("needs_manual_check").
			$flagged = $wpdb->update( "{$p}wpcap_refunds", array( 'error_message' => self::OUTCOME_UNKNOWN ), array( 'refund_id' => (int) $row['refund_id'], 'status' => 'processing', 'provider_reference' => '' ) );
			if ( 1 === (int) $flagged ) {
				Audit::record( 'refund_stuck', 'order', (int) $row['order_id'], array( 'refund_id' => (int) $row['refund_id'] ) );
				self::schedule_reconcile( (int) $row['refund_id'], 0 );
			}
		}
		return $done;
	}

	/* ------------------------------------------------------------------ Unknown outcome (A272, A350) */

	/** error_message of a refund whose provider call ended without a clear answer (A272). */
	const OUTCOME_UNKNOWN = 'outcome_unknown';

	/** Runs of the job refund_reconcile before the refund is left to a manual decision (about 2 days). */
	const RECONCILE_ATTEMPTS = 8;

	/** Minutes until the next reconciliation run, per attempt. */
	const RECONCILE_DELAYS = array( 2, 10, 30, 60, 180, 360, 720, 1440 );

	/** Key of a plugin refund at the provider (idempotency key, metadata wpcap_refund, PayPal custom_id). */
	public static function key_for( $refund_id ) {
		return 'wpcap-refund-' . absint( $refund_id );
	}

	/** Refund id of a key from key_for(), 0 for anything else. */
	public static function id_from_key( $key ) {
		return preg_match( '/^wpcap-refund-(\d+)$/', (string) $key, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Outcome of a write call (POST) that failed with an HTTP status: "unknown" when the provider may
	 * have executed it (2xx without a readable body, 409 conflict, 5xx), else "definitive".
	 */
	public static function outcome_for_status( $code ) {
		$code = (int) $code;
		return ( $code < 300 || 409 === $code || $code >= 500 ) ? 'unknown' : 'definitive';
	}

	/** Marks an adapter error as a clear "nothing was paid out" (raised before the provider was called). */
	public static function definitive( \WP_Error $error ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$data['outcome'] = 'definitive';
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Whether a refund call may have paid out although it failed: the adapter says so (data.outcome),
	 * or – adapters without that flag – a transport error, 409 or a 5xx status.
	 */
	public static function is_unknown_outcome( \WP_Error $error ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		if ( isset( $data['outcome'] ) ) {
			return 'unknown' === $data['outcome'];
		}
		if ( 'http_request_failed' === $error->get_error_code() ) {
			return true;
		}
		$status = (int) ( $data['status'] ?? 0 );
		return 409 === $status || $status >= 500;
	}

	/** Of two provider states of the same refund key the more advanced one (succeeded > pending > failed). */
	public static function better_state( $a, $b ) {
		$rank = array(
			'failed'    => 1,
			'pending'   => 2,
			'succeeded' => 3,
		);
		if ( ! is_array( $a ) ) { return $b; }
		if ( ! is_array( $b ) ) { return $a; }
		return ( $rank[ $b['status'] ?? '' ] ?? 0 ) > ( $rank[ $a['status'] ?? '' ] ?? 0 ) ? $b : $a;
	}

	/** Keeps a refund whose payout is unclear in "processing" and starts the reconciliation. */
	private static function mark_outcome_unknown( array $refund, \WP_Error $error ) {
		global $wpdb;
		$refund_id = (int) $refund['refund_id'];
		// A failed update leaves the marker empty: repair_stuck() sets it and schedules the job later.
		$wpdb->update( "{$wpdb->prefix}wpcap_refunds", array( 'error_message' => self::OUTCOME_UNKNOWN, 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id, 'status' => 'processing', 'provider_reference' => '' ) );
		Audit::record( 'refund_outcome_unknown', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => $error->get_error_code() ) );
		self::schedule_reconcile( $refund_id, 0 );
		return new \WP_Error(
			'wpcap_refund_outcome_unknown',
			__( 'Der Zahlungsanbieter hat nicht eindeutig geantwortet; die Erstattung ist eventuell schon ausgezahlt. Sie wird automatisch beim Anbieter abgeglichen und nicht erneut ausgezahlt. Bitte nicht noch einmal auslösen.', 'pass-captain' ),
			array(
				'status'    => 202,
				'refund_id' => $refund_id,
			)
		);
	}

	/** Plans run $attempt of the job refund_reconcile (idempotent per refund and attempt). */
	public static function schedule_reconcile( $refund_id, $attempt ) {
		$attempt = max( 0, (int) $attempt );
		$delay = self::RECONCILE_DELAYS[ min( $attempt, count( self::RECONCILE_DELAYS ) - 1 ) ];
		return Jobs::enqueue( 'refund-reconcile|' . absint( $refund_id ) . '|' . $attempt, 'refund_reconcile', array( 'refund_id' => absint( $refund_id ), 'attempt' => $attempt ), gmdate( 'Y-m-d H:i:s', time() + $delay * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Job handler (wpcap_process_job) of refund_reconcile: asks the provider about a refund with an unknown
	 * outcome. Still open (no answer, lookup failed, provider not sure yet): next run with a longer wait;
	 * after RECONCILE_ATTEMPTS runs – or at once for an adapter without lookup – the admin is asked to
	 * decide (operations page / order page).
	 */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( 'refund_reconcile' !== $type ) { return $handled; }
		$refund_id = absint( $payload['refund_id'] ?? 0 );
		$attempt = absint( $payload['attempt'] ?? 0 );
		$result = self::reconcile( $refund_id, 'job' );
		if ( ! is_wp_error( $result ) && 'waiting' !== $result ) { return true; }
		if ( is_wp_error( $result ) && 'wpcap_refund_missing' === $result->get_error_code() ) { return true; }
		if ( ( is_wp_error( $result ) && 'wpcap_refund_lookup_unsupported' === $result->get_error_code() ) || $attempt + 1 >= self::RECONCILE_ATTEMPTS ) {
			self::needs_manual_decision( $refund_id, $result );
			return true;
		}
		$scheduled = self::schedule_reconcile( $refund_id, $attempt + 1 );
		return is_wp_error( $scheduled ) ? $scheduled : true;
	}

	/** The reconciliation gave up: audit and admin mail (the refund stays visible as unresolved). */
	private static function needs_manual_decision( $refund_id, $result ) {
		$refund = self::get( $refund_id );
		if ( $refund && 'completed' === $refund['status'] && 'pending' === (string) ( $refund['provider_status'] ?? '' ) ) {
			// A348: still pending after about two days. Later webhooks still settle it.
			Audit::record( 'refund_provider_still_pending', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund_id ) );
			/* translators: 1: refund id, 2: provider refund reference */
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Erstattung beim Zahlungsanbieter noch ausstehend', 'pass-captain' ), sprintf( __( 'Die Erstattung #%1$d (Referenz %2$s) ist beim Zahlungsanbieter seit zwei Tagen ausstehend. Bitte im Konto des Anbieters prüfen. Meldet der Anbieter sie später als gescheitert, wird die Buchung automatisch storniert.', 'pass-captain' ), (int) $refund_id, (string) $refund['provider_reference'] ) );
			return;
		}
		if ( ! $refund || 'processing' !== $refund['status'] || '' !== (string) $refund['provider_reference'] ) { return; }
		$order = Orders::get( (int) $refund['order_id'] );
		Audit::record( 'refund_reconcile_exhausted', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : (string) $result ) );
		$reference = $order ? Frontend\Format::order_reference( (string) $order->public_id ) : (string) $refund['order_id'];
		wp_mail(
			get_option( 'admin_email' ),
			__( 'Pass Captain: Erstattung mit unklarem Ausgang', 'pass-captain' ),
			/* translators: 1: refund id, 2: order reference, 3: amount, 4: payment provider */
			sprintf( __( "Die Erstattung #%1\$d zu Bestellung %2\$s über %3\$s (%4\$s) konnte nicht automatisch mit dem Zahlungsanbieter abgeglichen werden. Sie wird nicht erneut ausgezahlt.\n\nBitte im Konto des Zahlungsanbieters prüfen und unter Pass Captain → Betrieb → „Erstattungen mit unklarem Ausgang“ oder in der Bestellung klären (erneut beim Anbieter prüfen oder manuell bestätigen).", 'pass-captain' ), (int) $refund_id, $reference, Money::format( (int) $refund['amount_minor'], (string) $refund['currency'] ), $order ? (string) $order->payment_provider : '' )
		);
	}

	/**
	 * Asks the provider about a refund whose payout is unclear (processing without provider reference)
	 * and settles it: paid out (or pending at the provider) → booked like any refund, without a second
	 * payout; failed, or conclusively unknown at the provider 10 minutes after the call → "failed"
	 * (nothing was paid out, a new refund may be requested).
	 *
	 * @param int    $refund_id Refund.
	 * @param string $source    job, admin, cli.
	 * @return string|\WP_Error completed, failed, waiting (provider has nothing yet), nothing (no unclear
	 *                          refund) or WP_Error (lookup failed or not possible).
	 */
	public static function reconcile( $refund_id, $source = 'job' ) {
		global $wpdb;
		$refund = self::get( $refund_id );
		if ( ! $refund ) {
			return '' !== $wpdb->last_error ? self::busy() : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$pending = 'completed' === $refund['status'] && 'pending' === (string) ( $refund['provider_status'] ?? '' );
		if ( ! $pending && ( 'processing' !== $refund['status'] || '' !== (string) $refund['provider_reference'] ) ) {
			return 'nothing';
		}
		$order = Orders::get( (int) $refund['order_id'] );
		if ( ! $order ) { return self::busy(); }
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		if ( ! $gateway instanceof Payments\RefundLookupInterface ) {
			return new \WP_Error( 'wpcap_refund_lookup_unsupported', __( 'Dieser Zahlungsanbieter unterstützt keinen automatischen Abgleich. Bitte im Konto des Anbieters prüfen und die Erstattung manuell bestätigen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$found = $gateway->find_refund( (array) $order, self::key_for( $refund_id ), $pending ? (string) $refund['provider_reference'] : '' );
		if ( is_wp_error( $found ) ) {
			Audit::record( 'refund_lookup_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund_id, 'error' => $found->get_error_code(), 'source' => sanitize_key( $source ) ) );
			return $found;
		}
		if ( $pending ) {
			// A348: a booked refund still pending at the provider.
			if ( null === $found || 'pending' === ( $found['status'] ?? '' ) ) { return 'waiting'; }
			return self::apply_state( $refund, $found, $source );
		}
		if ( null === $found ) {
			// The call may still be on its way: "not found" counts only 10 minutes after it.
			if ( strtotime( $refund['updated_at'] . ' UTC' ) > time() - 10 * MINUTE_IN_SECONDS ) { return 'waiting'; }
			$found = array(
				'status'    => 'not_found',
				'reference' => '',
			);
		}
		return self::apply_state( $refund, $found, $source );
	}

	/**
	 * Applies provider states of plugin refunds reported by a webhook (event field refund_states:
	 * key, status, reference). Idempotent; only refunds of orders paid with $provider are touched.
	 *
	 * @return true|\WP_Error WP_Error on a database error (the provider redelivers).
	 */
	public static function apply_provider_states( $provider, array $states ) {
		foreach ( $states as $state ) {
			$refund_id = is_array( $state ) ? self::id_from_key( $state['key'] ?? '' ) : 0;
			if ( ! $refund_id || ! in_array( $state['status'] ?? '', array( 'succeeded', 'pending', 'failed' ), true ) ) { continue; }
			$refund = self::get( $refund_id );
			if ( ! $refund ) { continue; }
			$order = Orders::get( (int) $refund['order_id'] );
			if ( ! $order || (string) $order->payment_provider !== (string) $provider ) { continue; }
			$applied = self::apply_state( $refund, $state, 'webhook' );
			if ( is_wp_error( $applied ) ) { return $applied; }
		}
		return true;
	}

	/**
	 * Settles a refund with a provider state (succeeded, pending, failed, not_found).
	 *
	 * @return string|\WP_Error completed, failed, nothing or WP_Error.
	 */
	private static function apply_state( array $refund, array $state, $source ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = (int) $refund['refund_id'];
		$status = (string) ( $state['status'] ?? '' );
		$reference = substr( sanitize_text_field( (string) ( $state['reference'] ?? '' ) ), 0, 191 );
		$paid = in_array( $status, array( 'succeeded', 'pending' ), true ) && '' !== $reference;
		if ( 'processing' === $refund['status'] && '' === (string) $refund['provider_reference'] ) {
			if ( $paid ) {
				$set = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET provider_reference=%s,error_message='' WHERE refund_id=%d AND status='processing' AND provider_reference=''", $reference, $refund_id ) );
				if ( false === $set ) { return self::busy(); }
				Audit::record( 'refund_reconciled', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'result' => 'paid_out', 'provider_status' => $status, 'reference' => $reference, 'source' => sanitize_key( $source ) ) );
				$row = self::get( $refund_id );
				$done = $row ? self::finish_locally( $row, (string) $row['provider_reference'] ) : self::busy();
				return is_wp_error( $done ) ? $done : 'completed';
			}
			if ( in_array( $status, array( 'failed', 'not_found' ), true ) ) {
				$note = 'failed' === $status
					? __( 'Abgleich: Der Zahlungsanbieter hat die Erstattung abgelehnt; es wurde nichts ausgezahlt.', 'pass-captain' )
					: __( 'Abgleich: Beim Zahlungsanbieter gibt es diese Erstattung nicht; es wurde nichts ausgezahlt.', 'pass-captain' );
				$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => substr( $note, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'status' => 'processing', 'provider_reference' => '' ) );
				if ( false === $moved ) { return self::busy(); }
				if ( 1 === $moved ) {
					Audit::record( 'refund_reconciled', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'result' => 'not_paid_out', 'provider_status' => $status, 'source' => sanitize_key( $source ) ) );
				}
				return 'failed';
			}
			return 'nothing';
		}
		if ( 'failed' === $refund['status'] && $paid ) {
			return self::paid_after_failure( $refund, $reference, $source );
		}
		$same = '' === $reference || (string) $refund['provider_reference'] === $reference;
		if ( 'processing' === $refund['status'] && 'failed' === $status && $same ) {
			// Accepted as pending, failed before the local booking: nothing to book.
			$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => substr( __( 'Der Zahlungsanbieter hat die Erstattung nachträglich abgelehnt; es wurde nichts ausgezahlt.', 'pass-captain' ), 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'status' => 'processing' ) );
			if ( false === $moved ) { return self::busy(); }
			if ( 1 === $moved ) { Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => 'provider_failed', 'source' => sanitize_key( $source ) ) ); }
			return 'failed';
		}
		if ( 'completed' === $refund['status'] && $same && self::provider_status_ready() ) {
			if ( 'failed' === $status ) {
				return self::reverse( $refund, $source );
			}
			if ( 'succeeded' === $status && 'succeeded' !== (string) ( $refund['provider_status'] ?? '' ) ) {
				// Status only: the booking date (updated_at) of the completed refund stays as it is.
				$set = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET provider_status='succeeded',updated_at=updated_at WHERE refund_id=%d AND status='completed'", $refund_id ) );
				if ( false === $set ) { return self::busy(); }
				Audit::record( 'refund_provider_succeeded', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'source' => sanitize_key( $source ) ) );
				return 'completed';
			}
		}
		return 'nothing';
	}

	/** Whether wpcap_refunds.provider_status/reversed_at exist (schema 3.10.11; not yet during a deferred upgrade). */
	public static function provider_status_ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_refunds LIKE 'reversed_at'" );
		}
		return $ready[ $key ];
	}

	/**
	 * A348: the provider reports a refund that is booked here (completed) as failed – the money stayed
	 * with the seller. The booking is reversed: status "reversed" (its booking date stays), the order's
	 * refunded total goes down, the credit note gets a reversal document (Invoices::reverse_credit_note()).
	 * Tickets stay revoked. The admin is mailed; the amount can be paid out again (retry_reversed()).
	 *
	 * @return string|\WP_Error reversed, nothing or WP_Error.
	 */
	private static function reverse( array $refund, $source ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = (int) $refund['refund_id'];
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'reversed', array( 'provider_status' => 'failed', 'reversed_at' => $now ) );
		if ( false === $moved ) { return self::busy(); }
		if ( 1 !== $moved ) {
			$wpdb->query( 'ROLLBACK' );
			return 'nothing';
		}
		$total = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=GREATEST(0,CAST(refunded_minor AS SIGNED)-%d),updated_at=%s WHERE order_id=%d", (int) $refund['amount_minor'], $now, (int) $refund['order_id'] ) );
		if ( false === $total || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) { return self::busy(); }
		$credit = Invoices::reverse_credit_note( $refund_id );
		Audit::record( 'refund_reversed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'amount_minor' => (int) $refund['amount_minor'], 'credit_note_reversal' => is_array( $credit ) ? (string) $credit['reversal_number'] : '', 'source' => sanitize_key( $source ) ) );
		/**
		 * A booked refund failed at the provider and was reversed (A348): its amount counts as paid again.
		 *
		 * @param int $refund_id Refund.
		 * @param int $order_id  Order.
		 */
		do_action( 'wpcap_refund_reversed', $refund_id, (int) $refund['order_id'] );
		$order = Orders::get( (int) $refund['order_id'] );
		$public = $order ? Frontend\Format::order_reference( (string) $order->public_id ) : (string) $refund['order_id'];
		wp_mail(
			get_option( 'admin_email' ),
			__( 'Pass Captain: Erstattung beim Zahlungsanbieter gescheitert', 'pass-captain' ),
			/* translators: 1: refund id, 2: order reference, 3: amount, 4: credit note reversal number or "–" */
			sprintf( __( "Der Zahlungsanbieter meldet die Erstattung #%1\$d zu Bestellung %2\$s über %3\$s als gescheitert; das Geld wurde nicht an die Käuferin bzw. den Käufer ausgezahlt.\n\nDie Buchung wurde storniert: Der Betrag gilt wieder als bezahlt, die Rechnungskorrektur wurde storniert (Storno-Beleg: %4\$s). Die Tickets bleiben widerrufen.\n\nBitte in der Bestellung „Erneut auszahlen“ wählen oder den Betrag auf anderem Weg erstatten.", 'pass-captain' ), $refund_id, $public, Money::format( (int) $refund['amount_minor'], (string) $refund['currency'] ), is_array( $credit ) && '' !== (string) $credit['reversal_number'] ? (string) $credit['reversal_number'] : '–' )
		);
		return 'reversed';
	}

	/** The new refund created by retry_reversed() for a reversed refund, or null. */
	public static function find_retry( $refund_id ) {
		global $wpdb;
		$refund = self::get( $refund_id );
		if ( ! $refund ) { return null; }
		$key_hash = hash( 'sha256', 'refund-amount|' . (int) $refund['order_id'] . '|reversed-retry-' . absint( $refund_id ) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
	}

	/**
	 * Pays out the amount of a reversed refund again (A348) as a new amount-only refund through the
	 * provider, idempotent per reversed refund; same approval rules as request_amount().
	 *
	 * @return array|\WP_Error New refund row.
	 */
	public static function retry_reversed( $refund_id ) {
		$refund = self::get( $refund_id );
		if ( ! $refund || 'reversed' !== $refund['status'] ) {
			return new \WP_Error( 'wpcap_refund_not_reversed', __( 'Diese Erstattung wurde nicht storniert.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		/* translators: %d: id of the reversed refund */
		$reason = sprintf( __( 'Neue Auszahlung der beim Anbieter gescheiterten Erstattung #%d', 'pass-captain' ), (int) $refund['refund_id'] );
		return self::request_amount( (int) $refund['order_id'], (int) $refund['amount_minor'], $reason, 'reversed-retry-' . (int) $refund['refund_id'], array( 'tax_minor' => (int) $refund['tax_minor'], 'allow_refunded' => true ) );
	}

	/**
	 * The provider paid out a refund recorded here as failed (e.g. confirmed as "not paid out" by hand).
	 * It is booked after all if its tickets and amount are still open; otherwise money left twice and
	 * the admin has to claim it back. Both cases are audited and mailed to the admin.
	 */
	private static function paid_after_failure( array $refund, $reference, $source ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = (int) $refund['refund_id'];
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		$order = $locked ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $locked['order_id'] ) ) : null;
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		if ( ! $locked || 'failed' !== $locked['status'] ) {
			$wpdb->query( 'ROLLBACK' );
			return 'nothing';
		}
		$conflict = self::execution_conflict( $locked, $order );
		if ( self::CONFLICT_UNKNOWN === $conflict ) { return self::busy(); }
		$booked = '' === $conflict && 1 === StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'processing', array( 'provider_reference' => $reference, 'error_message' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'status' => 'failed' ) );
		if ( ! $booked || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$booked = false;
		}
		$public = $order ? Frontend\Format::order_reference( (string) $order->public_id ) : (string) $refund['order_id'];
		Audit::record( 'refund_paid_after_failure', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'reference' => $reference, 'booked' => $booked, 'source' => sanitize_key( $source ) ) );
		wp_mail(
			get_option( 'admin_email' ),
			__( 'Pass Captain: Erstattung doch ausgezahlt', 'pass-captain' ),
			$booked
				/* translators: 1: refund id, 2: order reference, 3: provider reference */
				? sprintf( __( 'Die Erstattung #%1$d zu Bestellung %2$s galt als fehlgeschlagen, wurde vom Zahlungsanbieter aber ausgezahlt (Referenz %3$s). Sie wird jetzt nachträglich verbucht.', 'pass-captain' ), $refund_id, $public, $reference )
				/* translators: 1: refund id, 2: order reference, 3: provider reference */
				: sprintf( __( 'Die Erstattung #%1$d zu Bestellung %2$s galt als fehlgeschlagen, wurde vom Zahlungsanbieter aber ausgezahlt (Referenz %3$s). Die Tickets bzw. der Betrag wurden inzwischen anders erstattet: Es wurde vermutlich doppelt ausgezahlt. Bitte beim Zahlungsanbieter prüfen und den Betrag zurückfordern.', 'pass-captain' ), $refund_id, $public, $reference )
		);
		if ( ! $booked ) { return 'nothing'; }
		$row = self::get( $refund_id );
		$done = $row ? self::finish_locally( $row, $reference ) : self::busy();
		return is_wp_error( $done ) ? $done : 'completed';
	}

	/**
	 * Manual decision about a refund with an unknown outcome (A350), after a check in the provider's
	 * account: "paid" books it (provider reference optional), "not_paid" marks it failed. Needs the
	 * approval capability and a note; audited.
	 *
	 * @param int    $refund_id Refund.
	 * @param string $decision  paid or not_paid.
	 * @param string $note      How the outcome was checked (required).
	 * @param string $reference Provider refund id (optional, decision "paid").
	 * @return array|\WP_Error Refund row.
	 */
	public static function resolve_manually( $refund_id, $decision, $note, $reference = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! current_user_can( self::APPROVE_CAP ) ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Nur Personen mit Freigabeberechtigung dürfen den Ausgang einer Erstattung bestätigen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$check = self::check_actor();
		if ( is_wp_error( $check ) ) { return $check; }
		$note = substr( trim( sanitize_text_field( (string) $note ) ), 0, 200 );
		if ( '' === $note ) {
			return new \WP_Error( 'wpcap_refund_note', __( 'Bitte angeben, wie der Ausgang geprüft wurde (z. B. „im Stripe-Dashboard gesehen“).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$refund = self::get( $refund_id );
		if ( ! $refund || 'processing' !== $refund['status'] || '' !== (string) $refund['provider_reference'] ) {
			return new \WP_Error( 'wpcap_refund_not_unresolved', __( 'Der Ausgang dieser Erstattung ist nicht offen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$refund_id = (int) $refund['refund_id'];
		if ( 'paid' === $decision ) {
			$reference = substr( sanitize_text_field( (string) $reference ), 0, 150 );
			$stored = '' !== $reference ? $reference : 'manual-confirmed-' . $refund_id;
			$set = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET provider_reference=%s,error_message='' WHERE refund_id=%d AND status='processing' AND provider_reference=''", $stored, $refund_id ) );
			if ( false === $set ) { return self::busy(); }
			if ( 1 !== (int) $set ) {
				return new \WP_Error( 'wpcap_refund_not_unresolved', __( 'Der Ausgang dieser Erstattung ist nicht offen.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			Audit::record( 'refund_resolved_manually', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'decision' => 'paid', 'reference' => $stored, 'note' => $note ) );
			$row = self::get( $refund_id );
			return $row ? self::finish_locally( $row, $stored ) : self::busy();
		}
		if ( 'not_paid' !== $decision ) {
			return new \WP_Error( 'wpcap_refund_decision', __( 'Unbekannte Entscheidung.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		/* translators: %s: note of the person who checked the outcome */
		$message = sprintf( __( 'Manuell geklärt: nicht ausgezahlt (%s)', 'pass-captain' ), $note );
		$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => substr( $message, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'status' => 'processing', 'provider_reference' => '' ) );
		if ( false === $moved ) { return self::busy(); }
		if ( 1 !== $moved ) {
			return new \WP_Error( 'wpcap_refund_not_unresolved', __( 'Der Ausgang dieser Erstattung ist nicht offen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		Audit::record( 'refund_resolved_manually', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'decision' => 'not_paid', 'note' => $note ) );
		return self::get( $refund_id );
	}

	/** Whether a refund row waits for its outcome to be cleared up (processing without provider reference). */
	public static function is_unresolved( array $refund ) {
		if ( 'processing' !== ( $refund['status'] ?? '' ) || '' !== (string) ( $refund['provider_reference'] ?? '' ) ) { return false; }
		return in_array( (string) ( $refund['error_message'] ?? '' ), array( self::OUTCOME_UNKNOWN, 'needs_manual_check' ), true )
			|| strtotime( (string) ( $refund['updated_at'] ?? '' ) . ' UTC' ) < time() - 10 * MINUTE_IN_SECONDS;
	}

	/**
	 * Refunds whose outcome is unclear (operations page): processing without provider reference, marked
	 * "unknown" or older than 10 minutes. Oldest first, with order number and provider.
	 *
	 * @return array[]
	 */
	public static function unresolved( $limit = 100 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*,o.public_id AS order_public_id,o.payment_provider,o.customer_email FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status='processing' AND r.provider_reference='' AND (r.error_message IN (%s,'needs_manual_check') OR r.updated_at < %s) ORDER BY r.refund_id LIMIT %d", self::OUTCOME_UNKNOWN, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ), max( 1, (int) $limit ) ), ARRAY_A );
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
		if ( $amount <= 0 && ( ! $ticket_ids || (int) $amount_minor > 0 ) ) {
			// Nothing left to refund (e.g. a native refund pays out the rest right now): never book a
			// refund over 0 € with a credit note (A346). A free order's tickets are still cancelled.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_nothing', __( 'Es ist kein Betrag mehr offen, der als Erstattung verbucht werden könnte.', 'pass-captain' ), array( 'status' => 409 ) );
		}
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
		$released = array();
		$booked = true;
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$counted = array_map( 'intval', (array) $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND status IN ('valid','checked_in','revoked')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$revoked_ids = Orders::revoke_ids( $ticket_ids );
			// Same rule as native refunds (CapacityRelease): a ticket whose unit a revoke or an exchange
			// already released is flagged refunded without releasing it again.
			$released = CapacityRelease::release_tickets( $order_id, $counted, array( 'refund' => true ) );
			$booked = false !== $released && false !== $wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		}
		$total_ok = $booked ? $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d", $amount, $now, $order_id ) ) : false;
		if ( false === $total_ok || false === $wpdb->query( 'COMMIT' ) ) {
			// Nothing recorded: the adapter may retry with the same idempotency key.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		if ( $ticket_ids ) { CapacityCounters::sync_order_now( $order_id ); }
		Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		CapacityRelease::announce( $order_id, (array) $released, 'partial_refund', array( 'refund_id' => $refund_id ) );
		// Tickets revoked by an admin or exchanged away do not keep the order open (A357).
		$remaining = self::refundable_count( $order_id );
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

	/** Amount of the order's native refunds that are being paid out right now (status processing). */
	public static function running_minor( $order_id ) {
		return (int) self::open_refunds( absint( $order_id ), array( 'processing' ) )['amount_minor'];
	}

	/** execution_conflict(): a read failed, so it is unknown whether the refund may be paid out. */
	const CONFLICT_UNKNOWN = "\0db_busy";

	/** error_message of a refund that failed before any payout because of a database error (resumable). */
	const RETRY_NOTE = 'db_busy_before_payout';

	/** Rolls back and returns the retryable "busy" error (deadlock, lock wait timeout, lost connection). */
	private static function busy() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return Errors::busy( __( 'Die Erstattung konnte gerade nicht gespeichert werden. Bitte erneut versuchen.', 'pass-captain' ) );
	}

	/**
	 * Result of a request repeated with its idempotency key: an approved refund that never started
	 * (the first call failed after saving it) and a payout without bookkeeping are continued.
	 */
	private static function resume( array $refund ) {
		if ( 'approved' === $refund['status'] || ( 'failed' === $refund['status'] && self::RETRY_NOTE === (string) $refund['error_message'] ) || ( 'processing' === $refund['status'] && '' !== (string) $refund['provider_reference'] ) ) {
			return self::execute( (int) $refund['refund_id'] );
		}
		return $refund;
	}

	/**
	 * Checks (under the order lock) whether a refund may still be paid out.
	 *
	 * @return string Empty when fine, CONFLICT_UNKNOWN when a read failed, otherwise the reason it must fail.
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
			if ( '' !== $wpdb->last_error ) { return self::CONFLICT_UNKNOWN; }
			$running = self::open_refunds( (int) $refund['order_id'], array( 'processing' ), (int) $refund['refund_id'] );
			if ( $open !== count( $ticket_ids ) || array_intersect( $ticket_ids, $running['ticket_ids'] ) ) {
				return __( 'Mindestens eines der Tickets wurde bereits erstattet; die Erstattung wurde nicht ausgeführt.', 'pass-captain' );
			}
			// A537: every ticket must still be in use (valid or checked in) or revoked by the cancellation
			// of its date (Orders::refundable_tickets()). A ticket exchanged or revoked since the request
			// already gave its unit back and lives on elsewhere: paying it out would refund a ticket that
			// is still valid (exchange) or release its capacity a second time (revoke).
			$refundable = Orders::refundable_tickets( (int) $refund['order_id'], $read_failed );
			if ( $read_failed ) { return self::CONFLICT_UNKNOWN; }
			if ( array_diff( $ticket_ids, array_map( static function ( $t ) { return (int) $t->ticket_id; }, $refundable ) ) ) {
				return __( 'Mindestens eines der Tickets wurde inzwischen umgetauscht oder widerrufen; die Erstattung wurde nicht ausgeführt.', 'pass-captain' );
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

	/**
	 * Tickets of the order that keep it open: in use (valid, checked in) or still refundable after a
	 * cancellation (Orders::refundable_tickets()). Null when the read failed (A183).
	 */
	private static function refundable_count( $order_id ) {
		$rows = Orders::refundable_tickets( absint( $order_id ), $failed );
		return $failed ? null : count( $rows );
	}

	/**
	 * Whether a refund of the ticket is pending, approved or running (A537): exchange, revoke and
	 * transfer of the ticket wait until it is decided. Call under the order row lock (refund requests
	 * lock it too). Null when the read failed.
	 *
	 * @return bool|null
	 */
	public static function ticket_has_open_refund( $order_id, $ticket_id ) {
		global $wpdb;
		$open = self::open_refunds( absint( $order_id ) );
		if ( '' !== $wpdb->last_error ) { return null; }
		return in_array( absint( $ticket_id ), $open['ticket_ids'], true );
	}

	/** Idempotency hash of a ticket refund request. */
	private static function key_hash( $order_id, $idempotency_key ) {
		return hash( 'sha256', 'refund|' . absint( $order_id ) . '|' . sanitize_text_field( $idempotency_key ) );
	}

	/** Ticket refund of an order created with this idempotency key (request()), or null. */
	public static function find_by_key( $order_id, $idempotency_key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE idempotency_key=%s AND order_id=%d", self::key_hash( $order_id, $idempotency_key ), absint( $order_id ) ), ARRAY_A );
	}

	/** Whether wpcap_refunds.source exists (schema 3.9.4; not yet during a deferred upgrade). */
	public static function source_ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		// Only a positive answer is cached: the upgrade may complete later in the same process.
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_refunds LIKE 'source'" );
		}
		return $ready[ $key ];
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
		// A357: completeness is decided by ticket value. Tickets that are neither refundable nor refunded
		// (revoked by an admin, with or without releasing their place) keep their share of the money;
		// tickets exchanged away carried theirs into the new ticket, which is refundable.
		$retained = self::retained_ticket_ids( $order, array_map( static function ( $t ) { return (int) $t->ticket_id; }, $open ) );
		$retained_minor = $retained ? self::scaled_value( $order, $retained ) : 0;
		$remaining_total = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - max( 0, (int) $reserved_minor ) - $retained_minor );
		$full = count( $ticket_ids ) === count( $open_ids );
		if ( $full ) {
			// The last remaining tickets receive the rounding remainder.
			$amount = $remaining_total;
		} else {
			$amount = min( $remaining_total, self::scaled_value( $order, $ticket_ids ) );
		}
		$tax = self::tax_for( $order, $full && ! $retained ? null : $ticket_ids, $amount );
		return array( 'ticket_ids' => $ticket_ids, 'amount_minor' => $amount, 'tax_minor' => $tax );
	}

	/**
	 * The tickets' share of the gross after their own discount (a discount for another event does not
	 * reduce it), scaled to the amount paid (a multi-purpose voucher pays the rest).
	 */
	private static function scaled_value( $order, array $ticket_ids ) {
		if ( ! $ticket_ids ) { return 0; }
		list( , , $base ) = self::line_shares( $order, $ticket_ids );
		$after_discount = max( 1, ( (int) $order->subtotal_minor ?: (int) $order->total_minor ) - (int) $order->discount_minor );
		return (int) round( $base * (int) $order->total_minor / $after_discount );
	}

	/**
	 * Tickets of an order that keep their value without being refundable (A357): not refunded, not
	 * in use or refundable, and not exchanged away (an exchanged ticket's value lives on in its new
	 * ticket and order line).
	 *
	 * @param int[] $refundable Refundable ticket ids of the order.
	 * @return int[]
	 */
	private static function retained_ticket_ids( $order, array $refundable ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$exchanged = Privacy::has_table( 'wpcap_ticket_exchanges' ) ? " AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_ticket_exchanges x WHERE x.old_ticket_id=t.ticket_id AND x.status='completed')" : '';
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t WHERE t.order_id=%d AND t.refunded=0$exchanged ORDER BY t.ticket_id", (int) $order->order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragment.
		return array_values( array_diff( $ids, array_map( 'intval', $refundable ) ) );
	}

	/**
	 * Tickets of the list that were used for admission (see Orders::used_ticket_ids()).
	 *
	 * @param int[] $ticket_ids Ticket ids.
	 * @return int[]
	 */
	public static function used_ticket_ids( array $ticket_ids ) {
		// Same definition as the rest of the plugin (undone check-ins do not count, entries of the
		// admission module do).
		return Orders::used_ticket_ids( $ticket_ids );
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
		$reference = Frontend\Format::order_reference( (string) $order->public_id );
		$vars = array(
			// The short order number the buyer knows (portal, bank reference), not the 32-character id.
			'order_id' => $reference,
			// Callables run in the recipient's locale (number format, translated notes).
			'refund_amount' => static function () use ( $amount_minor, $currency ) { return Money::format( $amount_minor, $currency ); },
			'refund_intro' => static function () use ( $order, $reference, $amount_minor, $currency ) { return PayoutAccount::refund_intro( $order, $reference, Money::format( $amount_minor, $currency ) ); },
			'refund_method_note' => static function () use ( $order ) { return PayoutAccount::refund_note( $order ); },
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
		list( , $tax, $base ) = self::line_shares( $order, $ticket_ids );
		return $base > 0 ? min( (int) $amount, (int) round( $tax * (int) $amount / $base ) ) : 0;
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
		$items = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,quantity,line_total_minor,tax_minor' . ( OrderLines::ready() ? ',discount_minor' : '' ) . " FROM {$p}wpcap_order_items WHERE order_id=%d ORDER BY item_id", (int) $order->order_id ) );
		// Line totals are stored before the order discount, the line VAT after it: the gross that carries this VAT
		// is the discounted line total (same allocation as in reports and settlements).
		$discount = isset( $order->discount_minor ) ? (int) $order->discount_minor : (int) $wpdb->get_var( $wpdb->prepare( "SELECT discount_minor FROM {$p}wpcap_orders WHERE order_id=%d", (int) $order->order_id ) );
		$discounted = \PassCaptain\Reports\Data::allocate_discount( $items, $discount );
		foreach ( array_values( $items ) as $i => $item ) {
			$q = max( 1, (int) $item->quantity );
			$unit[ (int) $item->ticket_type_id ] = array( (int) $item->line_total_minor / $q, (int) $item->tax_minor / $q, (int) $discounted[ $i ] / $q );
		}
		$ids = implode( ',', array_map( 'absint', $ticket_ids ) );
		$gross = 0.0;
		$tax = 0.0;
		$net_of_discount = 0.0;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_id IN ($ids)", (int) $order->order_id ) ) as $type_id ) {
			$gross += $unit[ (int) $type_id ][0] ?? 0;
			$tax += $unit[ (int) $type_id ][1] ?? 0;
			$net_of_discount += $unit[ (int) $type_id ][2] ?? 0;
		}
		// [gross before discount, VAT, gross after discount (the base of that VAT)].
		return array( (int) round( $gross ), (int) round( $tax ), (int) round( $net_of_discount ) );
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
		// The order VAT belongs to the full consideration: amount due plus a multi-purpose voucher
		// payment. A refund of (part of) the cash share only carries its proportional VAT.
		$gross = max( $total, Integrations\Vouchers\Checkout::gross_minor( $order ) );
		if ( $amount >= $remaining_total && $gross === $total ) {
			return min( $amount, $remaining_tax );
		}
		if ( ! $ticket_ids ) {
			// Amount-only: pro rata over what is still open (A200), so after ticket refunds of one rate
			// the VAT follows the rates that are left (Invoices::refund_line_parts() splits the same way).
			if ( $gross === $total && $remaining_total > 0 ) {
				return min( $remaining_tax, $amount, (int) round( $remaining_tax * $amount / $remaining_total ) );
			}
			return min( $remaining_tax, $amount, (int) round( (int) $order->tax_minor * $amount / $gross ) );
		}
		list( , $tax, $base ) = self::line_shares( $order, $ticket_ids );
		if ( $base <= 0 ) { return min( $remaining_tax, (int) round( (int) $order->tax_minor * $amount / $gross ) ); }
		return min( $remaining_tax, $amount, (int) round( $tax * $amount / $base ) );
	}
}
