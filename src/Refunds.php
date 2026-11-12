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
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === $order->payment_provider ) {
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$calc = self::calculate( $order, $ticket_ids );
		if ( is_wp_error( $calc ) ) { return $calc; }

		$needs_approval = (int) Settings::get( 'refund_approval_threshold' ) > 0 && $calc['amount_minor'] > (int) Settings::get( 'refund_approval_threshold' ) && ! current_user_can( self::APPROVE_CAP );
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
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
			return $existing ? $existing : new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		$refund_id = (int) $wpdb->insert_id;
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $calc['amount_minor'], 'needs_approval' => $needs_approval ) );
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Erstattung wartet auf Freigabe', 'wp-pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'wp-pass-captain' ), $order->public_id, number_format_i18n( $calc['amount_minor'] / 100, 2 ) . ' ' . $order->currency ) );
			return self::get( $refund_id );
		}
		return self::execute( $refund_id );
	}

	/** Second approval by a different user with the approval capability. */
	public static function approve( $refund_id ) {
		$refund = self::get( $refund_id );
		if ( ! $refund || 'pending_approval' !== $refund['status'] ) {
			return new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( self::APPROVE_CAP ) || (int) $refund['requested_by'] === get_current_user_id() ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Die Freigabe muss durch eine andere berechtigte Person erfolgen.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$check = self::check_actor();
		if ( is_wp_error( $check ) ) { return $check; }
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		Audit::record( 'refund_approved', 'order', (int) $refund['order_id'], array( 'refund_id' => absint( $refund_id ) ) );
		return self::execute( $refund_id );
	}

	/** Rejects a pending refund. */
	public static function reject( $refund_id, $reason = '' ) {
		global $wpdb;
		if ( ! current_user_can( self::APPROVE_CAP ) ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'rejected', 'error_message' => substr( sanitize_text_field( $reason ), 0, 255 ), 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		if ( $updated ) {
			$rejected = self::get( $refund_id );
			Audit::record( 'refund_rejected', 'order', (int) $rejected['order_id'], array( 'refund_id' => absint( $refund_id ) ) );
			/** A pending refund was rejected by an approver. */
			do_action( 'wpcap_refund_rejected', absint( $refund_id ), (int) $rejected['order_id'] );
			return $rejected;
		}
		return new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'wp-pass-captain' ), array( 'status' => 409 ) );
	}

	/**
	 * Executes an approved (or previously failed) refund: provider refund, ticket revocation,
	 * capacity release, order totals. Safe to retry after a provider failure.
	 */
	public static function execute( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = absint( $refund_id );
		$wpdb->query( 'START TRANSACTION' );
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		if ( ! $refund || ! in_array( $refund['status'], array( 'approved', 'failed' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
		}
		$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'processing', 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
		$wpdb->query( 'COMMIT' );

		$order = Orders::get( (int) $refund['order_id'] );
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		$provider_reference = 'manual';
		if ( $gateway && $gateway->supports_refunds() && (int) $refund['amount_minor'] > 0 ) {
			$result = $gateway->refund( (array) $order, (int) $refund['amount_minor'], 'wpcap-refund-' . $refund_id );
			if ( is_wp_error( $result ) ) {
				$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'failed', 'error_message' => substr( $result->get_error_message(), 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
				Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => $result->get_error_code() ) );
				return $result;
			}
			$provider_reference = (string) $result;
		}

		$ticket_ids = array_map( 'absint', (array) json_decode( $refund['ticket_ids'], true ) );
		$revoked = 0;
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$per_type = $wpdb->get_results( "SELECT ticket_type_id, COUNT(*) AS n FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND status='valid' GROUP BY ticket_type_id" );
			$revoked_ids = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d AND status='valid'", (int) $refund['order_id'] ) ) );
			$revoked = count( $revoked_ids );
			Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
			foreach ( $per_type as $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", (int) $row->n, (int) $refund['order_id'], (int) $row->ticket_type_id ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN ($ids) AND order_id=%d", (int) $refund['order_id'] ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d", (int) $refund['amount_minor'], current_time( 'mysql', true ), (int) $refund['order_id'] ) );
		$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'completed', 'provider_reference' => substr( $provider_reference, 0, 191 ), 'error_message' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0", (int) $refund['order_id'] ) );
		if ( 0 === $remaining ) {
			Orders::mark_refunded( (int) $refund['order_id'], 'native_refund_' . $refund_id );
		}
		Audit::record( 'refund_completed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'amount_minor' => (int) $refund['amount_minor'], 'revoked_tickets' => $revoked, 'provider' => $order->payment_provider ) );
		do_action( 'wpcap_refund_completed', $refund_id, (int) $refund['order_id'] );
		self::notify_buyer( $refund_id, $order, $revoked );
		return self::get( $refund_id );
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
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$ticket_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d AND refunded=0 FOR UPDATE", $order_id ) ) );
		}
		$amount = min( max( 0, (int) $amount_minor ), max( 0, (int) $order->total_minor - (int) $order->refunded_minor ) );
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
			return $existing ? $existing : new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		$refund_id = (int) $wpdb->insert_id;
		$revoked_ids = array();
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			foreach ( $wpdb->get_results( "SELECT ticket_type_id, COUNT(*) AS n FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND status='valid' GROUP BY ticket_type_id" ) as $row ) {
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
	public static function calculate( $order, array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$open = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY ticket_id", (int) $order->order_id ) );
		$open_ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, $open );
		$ticket_ids = $ticket_ids ? array_values( array_unique( array_map( 'absint', $ticket_ids ) ) ) : $open_ids;
		if ( ! $ticket_ids || array_diff( $ticket_ids, $open_ids ) ) {
			return new \WP_Error( 'wpcap_refund_tickets', __( 'Die gewählten Tickets gehören nicht zur Bestellung oder wurden bereits erstattet.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$remaining_total = max( 0, (int) $order->total_minor - (int) $order->refunded_minor );
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
			return new \WP_Error( 'wpcap_refund_forbidden', __( 'Keine Berechtigung für Erstattungen.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		if ( Settings::get( 'require_2fa_for_refunds' ) && ! self::user_has_2fa( get_current_user_id() ) ) {
			return new \WP_Error( 'wpcap_refund_2fa', __( 'Für Erstattungen ist eine aktivierte Zwei-Faktor-Anmeldung erforderlich.', 'wp-pass-captain' ), array( 'status' => 403 ) );
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
		$remaining_total = max( 0, $total - (int) $order->refunded_minor );
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
