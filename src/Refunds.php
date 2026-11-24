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

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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
	 *                                schema 3.9.4), used_override / allow_used (A131, see below);
	 *                                deduct_minor (fixed fee kept), deduct_bp (fee in basis points of the
	 *                                ticket value), max_minor (goodwill cap, 0 = none) – see quote();
	 *                                self_service / customer (trusted: buyer request from the order portal,
	 *                                e.g. Sales\SelfService or after a postponement; no staff capability is
	 *                                checked – the caller verified the access token – and the refund always
	 *                                waits for a staff approval; same as system + require_approval).
	 * @return array|\WP_Error Refund row as array.
	 */
	public static function request( $order_id, array $ticket_ids, $reason, $idempotency_key, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$self_service = ! empty( $args['customer'] ) || ! empty( $args['self_service'] );
		if ( $self_service ) {
			$args['system'] = true;
			$args['require_approval'] = true;
		}
		$options = $args; // Fee/goodwill options (apply_deduction()).
		$system = ! empty( $args['system'] );
		// The actor is checked before the idempotency lookup (A643): a replayed key must not resume
		// (execute) an approved refund for someone who may not refund (any more).
		if ( ! $system ) {
			$check = self::check_actor();
			if ( is_wp_error( $check ) ) { return $check; }
		}
		$existing = self::find_by_key( $order_id, $idempotency_key );
		if ( $existing ) { return $self_service ? $existing : self::resume( $existing ); }
		$key_hash = self::key_hash( $order_id, $idempotency_key );
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
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::db_error( 0, $order_id, 'request_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::request_db_error( $order_id, 'request_lock' ); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::request_db_error( $order_id, 'request_key' ); }
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return $self_service ? $existing : self::resume( $existing ); }
		self::$read_failed = false;
		$open = self::open_refunds( $order_id );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( $requested && array_intersect( $requested, $open['ticket_ids'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_overlap', __( 'Für mindestens eines der gewählten Tickets läuft bereits eine Erstattung oder sie wartet auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$calc = self::calculate( $order, $requested, $open['ticket_ids'], $open['amount_minor'], $args );
		// The reads above are plain reads: one that failed lost the connection (and the transaction),
		// so its empty result proves nothing (A183).
		if ( false === Orders::transaction_alive() ) { return self::busy(); }
		if ( is_wp_error( $calc ) ) { $wpdb->query( 'ROLLBACK' ); return $calc; }
		$calc = self::apply_deduction( $calc, $options );
		// A failed read while computing the amount must not produce a refund of the wrong amount
		// (e.g. tickets revoked for 0.00 because the order lines could not be read).
		if ( self::$read_failed ) { return self::request_db_error( $order_id, 'request_calculate' ); }
		// A496: a buyer (order portal) cannot cancel a ticket that belongs to someone else now. The portal
		// checks this before, but a transfer may have committed in between; transfers lock the order row
		// as well, so this read under the lock is final. Staff may still refund a transferred ticket.
		if ( 'portal' === sanitize_key( (string) ( $args['source'] ?? '' ) ) ) {
			$transferred = self::transferred_ticket_ids( $order_id, $calc['ticket_ids'] );
			if ( null === $transferred ) { return self::busy(); }
			if ( $transferred ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcap_refund_ticket_transferred', PortalSelfService::refund_block_message( 'transferred' ), array( 'status' => 409, 'ticket_ids' => $transferred ) );
			}
		}

		// Tickets already used for admission. Policy setting refund_after_checkin (Security\Policy): "deny"
		// refuses them, "approval" makes a second person approve. A131: staff may only refund them with an
		// explicit exception (reason, approver capability) – then the refund always needs the second
		// approval; system flows (cancelled date/event) are not limited.
		$used = self::used_ticket_ids( $calc['ticket_ids'] );
		$checkin_policy = $used ? self::checkin_policy() : 'allow';
		if ( $used && ! $system && 'deny' === $checkin_policy ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_checked_in', __( 'Bereits eingecheckte Tickets können nach den Erstattungsregeln nicht erstattet werden.', 'pass-captain' ), array( 'status' => 409, 'checked_in_ticket_ids' => $used ) );
		}
		if ( $used && 'approval' === $checkin_policy ) { $args['require_approval'] = true; }
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
					array( 'status' => 409, 'used_ticket_ids' => $used, 'checked_in_ticket_ids' => $used )
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
		$needs_approval = ! empty( $args['require_approval'] ) || ( $threshold > 0 && $cumulative > $threshold && ! self::may_self_approve( $system ) );
		$now = Clock::mysql();
		$row = array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $calc['amount_minor'],
			'tax_minor' => $calc['tax_minor'],
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( $calc['ticket_ids'] ),
			'reason' => \PassCaptain\Text::cut( sanitize_text_field( $reason ), 255 ),
			'status' => $needs_approval ? 'pending_approval' : 'approved',
			// A buyer request has no WordPress user as requester (any approver may decide on it).
			'requested_by' => $system ? 0 : get_current_user_id(),
			'approved_by' => $needs_approval ? 0 : get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		);
		// Only written when there is one, so refunds keep working while a deferred schema upgrade is pending.
		if ( $calc['deducted_minor'] > 0 ) { $row['deducted_minor'] = $calc['deducted_minor']; }
		// A425 (owner decision 23.11.2026): service fee given back / kept by this refund (schema 3.18.4).
		if ( self::fee_ready() && ( $calc['fee_minor'] || $calc['fee_kept_minor'] ) ) {
			$row['fee_minor'] = (int) $calc['fee_minor'];
			$row['fee_tax_minor'] = (int) $calc['fee_tax_minor'];
			$row['fee_kept_minor'] = (int) $calc['fee_kept_minor'];
			$row['fee_kept_tax_minor'] = (int) $calc['fee_kept_tax_minor'];
		}
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
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $calc['amount_minor'], 'deducted_minor' => $calc['deducted_minor'], 'service_fee_minor' => $calc['fee_minor'], 'service_fee_kept_minor' => $calc['fee_kept_minor'], 'needs_approval' => $needs_approval, 'source' => '' !== $source ? $source : 'admin', 'checked_in_tickets' => count( $used ), 'checkin_policy' => $checkin_policy, 'self_service' => $self_service ) );
		if ( $needs_approval && 'portal' === $source ) {
			\PassCaptain\Alerts::raise( 'refund_request_approval', __( 'Pass Captain: Stornierungsanfrage wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s hat die Käuferin bzw. der Käufer im Bestellportal eine Stornierung mit Erstattung über %2$s angefragt. Bitte unter „Erstattungen zur Freigabe“ freigeben oder ablehnen.', 'pass-captain' ), $order->public_id, Money::format( $calc['amount_minor'], $order->currency ) ) );
			return self::get( $refund_id );
		}
		if ( $needs_approval ) {
			\PassCaptain\Alerts::raise( 'refund_approval', __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, Money::format( $calc['amount_minor'], $order->currency ) ) );
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
		// Actor first, then the idempotency hit (A643).
		if ( ! $system ) {
			$check = self::check_actor();
			if ( is_wp_error( $check ) ) { return $check; }
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { return $existing; }
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
		$needs_approval = $threshold > 0 && $cumulative > $threshold && ! self::may_self_approve( $system );
		$now = Clock::mysql();
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $amount_minor,
			'tax_minor' => $tax,
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( array() ),
			'reason' => \PassCaptain\Text::cut( sanitize_text_field( $reason ), 255 ),
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
			\PassCaptain\Alerts::raise( 'refund_approval', __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, Money::format( $amount_minor, $order->currency ) ) );
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
		$approved = $wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'updated_at' => Clock::mysql() ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		if ( false === $approved ) { return self::db_error( absint( $refund_id ), (int) $refund['order_id'], 'approve' ); }
		if ( 1 !== (int) $approved ) {
			// Approved/rejected by someone else meanwhile: never execute twice.
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
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'rejected', 'error_message' => \PassCaptain\Text::cut( sanitize_text_field( $reason ), 255 ), 'approved_by' => get_current_user_id(), 'updated_at' => Clock::mysql() ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
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
			if ( false === StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => \PassCaptain\Text::cut( $invalid, 255 ), 'updated_at' => Clock::mysql() ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				return self::busy();
			}
			\PassCaptain\Log::error( 'refund_failed', 'refund failed', array( 'object_type' => 'order', 'object_id' => (int) $refund['order_id'], 'details' => array( 'refund_id' => $refund_id, 'error' => 'wpcap_refund_conflict' ) ) );
			return new \WP_Error( 'wpcap_refund_conflict', $invalid, array( 'status' => 409 ) );
		}
		$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'processing', array( 'updated_at' => Clock::mysql() ) );
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
			StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => self::RETRY_NOTE, 'updated_at' => Clock::mysql() ), array( 'provider_reference' => '' ) );
			return Errors::busy( __( 'Die Erstattung konnte gerade nicht gestartet werden. Bitte erneut versuchen.', 'pass-captain' ) );
		}
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		$provider_reference = 'manual';
		if ( ! $gateway && in_array( (string) $order->payment_provider, array( 'stripe', 'paypal', 'mollie' ), true ) && (int) $refund['amount_minor'] > 0 ) {
			// The online provider of the order is not available (e.g. the Mollie module was switched off): never
			// book the refund as a manual payout – the money would be recorded as returned without moving.
			StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => 'gateway_unavailable', 'updated_at' => Clock::mysql() ), array( 'status' => 'processing' ) );
			return new \WP_Error( 'wpcap_refund_gateway_unavailable', __( 'Der Zahlungsanbieter dieser Bestellung ist nicht verfügbar; die Erstattung wurde nicht ausgeführt.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( $gateway && $gateway->supports_refunds() && (int) $refund['amount_minor'] > 0 ) {
			// A final decline moved earlier attempts to a new idempotency key (provider_key()).
			$attempt = (int) ( $refund['provider_attempt'] ?? 0 );
			$result = $gateway->refund( (array) $order, (int) $refund['amount_minor'], self::provider_key( $refund_id, $attempt ) );
			if ( is_wp_error( $result ) && self::is_unknown_outcome( $result ) ) {
				// A272: timeout, transport error, 5xx or 409 – the provider may have paid out. The refund stays
				// "processing" without a reference (never retried as "failed") and is reconciled with the
				// provider (job refund_reconcile, webhooks, admin lookup or manual confirmation).
				return self::mark_outcome_unknown( $refund, $result );
			}
			if ( is_wp_error( $result ) ) {
				// A final decline (the provider answered and refused, e.g. insufficient balance, card
				// expired) moves the next retry to a new idempotency key: Stripe, PayPal and Mollie replay the
				// stored answer of a key (Stripe for 24 h), so the same key would fail again although the
				// cause was fixed. Unclear outcomes (above) keep the key: only it prevents a second payout.
				$final = self::is_final_decline( $result );
				$set = array( 'error_message' => \PassCaptain\Text::cut( Redact::text( $result->get_error_message(), false ), 255 ), 'updated_at' => Clock::mysql() );
				if ( $final && array_key_exists( 'provider_attempt', $refund ) ) { $set['provider_attempt'] = $attempt + 1; }
				StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', $set, array( 'status' => 'processing' ) );
				Log::error( 'refund_failed', 'Refund failed.', array( 'object_type' => 'order', 'object_id' => (int) $refund['order_id'], 'details' => array( 'refund_id' => $refund_id, 'error' => $result->get_error_code(), 'attempt' => $attempt, 'final_decline' => $final ) ) );
				return $result;
			}
			$provider_reference = is_array( $result ) ? (string) ( $result['reference'] ?? '' ) : (string) $result;
		}
		// The money has moved: record it first, so a failure below is repaired without a second payout.
		$record = array( 'provider_reference' => \PassCaptain\Text::cut( '' !== $provider_reference ? $provider_reference : 'manual', 191 ), 'updated_at' => Clock::mysql() );
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
			\PassCaptain\Log::error( 'refund_bookkeeping_failed', 'refund bookkeeping failed', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'refund_id' => $refund_id ) ) );
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
			\PassCaptain\Text::cut( '' !== (string) $provider_reference ? (string) $provider_reference : 'manual', 191 ),
			Clock::mysql(),
			Clock::mysql(),
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
	 * charge.refund.updated, Mollie refund status failed): the money never reached the buyer. Each
	 * completed refund with one of the references (plugin refund id, provider refund id or the legacy
	 * "ext:{provider}:{id}") is reversed like a failed provider state (A348, reverse(): status
	 * `reversed`, the order's refunded amount goes down, the credit note gets a reversal document, the
	 * admin is mailed and the amount can be paid out again with retry_reversed()). A fully refunded
	 * order whose refunded amount is no longer complete goes back to complete/paid. Revoked tickets stay
	 * revoked (seats may have been sold again); `wp_pass_captain_refund_failed_v1` fires per refund.
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
			$refs[] = \PassCaptain\Text::cut( $ref, 191 );
			if ( '' !== (string) $provider ) { $refs[] = \PassCaptain\Text::cut( 'ext:' . sanitize_key( (string) $provider ) . ':' . $ref, 191 ); }
		}
		$refs = array_values( array_unique( $refs ) );
		if ( ! $order_id || ! $refs ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND status='completed' AND provider_reference IN (" . implode( ',', array_fill( 0, count( $refs ), '%s' ) ) . ') ORDER BY refund_id', array_merge( array( $p . 'wpcap_refunds', $order_id ), $refs ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return self::db_error( 0, $order_id, 'provider_failed_lock' ); }
		$reversed = array();
		foreach ( $rows as $row ) {
			$result = self::reverse( $row, 'webhook' );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( 'reversed' !== $result ) { continue; }
			$reversed[] = $row;
			Log::error( 'refund_provider_failed', 'Refund failed at the payment provider.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'refund_id' => (int) $row['refund_id'], 'amount_minor' => (int) $row['amount_minor'], 'reference' => (string) $row['provider_reference'] ) ) );
			do_action( 'wp_pass_captain_refund_failed_v1', (int) $row['refund_id'], $order_id );
		}
		if ( $reversed ) {
			// The money is (partly) with the seller again: a "refunded" order is open again.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='complete',payment_status='paid',updated_at=%s WHERE order_id=%d AND status='refunded' AND refunded_minor<total_minor", $p . 'wpcap_orders', Clock::mysql(), $order_id ) );
		}
		return $reversed;
	}

	/**
	 * Finishes refunds that were paid out by the provider but not booked locally (status processing
	 * with provider reference), e.g. after a database error, and orders whose refunded state could not
	 * be stored. Never calls the provider. Idempotent; runs with the job queue (cron), the refund admin
	 * page and the system status page.
	 *
	 * @return int Number of refunds booked.
	 */
	public static function reconcile_bookings( $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status='processing' AND provider_reference<>'' ORDER BY refund_id LIMIT %d", $wpdb->prefix . 'wpcap_refunds', max( 1, absint( $limit ) ) ), ARRAY_A );
		$done = 0;
		foreach ( (array) $rows as $row ) {
			$result = self::finish_locally( $row, (string) $row['provider_reference'] );
			if ( is_array( $result ) && 'completed' === $result['status'] ) { ++$done; }
		}
		self::reconcile_order_states( $limit );
		return $done;
	}

	/** Job type that retries Orders::mark_refunded() after a database error. */
	const ORDER_STATE_JOB = 'order_mark_refunded';

	/** @var bool */
	private static $registered = false;

	/** Registers the job handler (Plugin::run(), Jobs::process_due()); same callback/priority as Plugin, so registered once. */
	public static function register() {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) { return; }
		self::$registered = true;
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 4 );
	}

	/**
	 * Job handler (filter `wpcap_process_job`, legacy wp_pass_captain_process_job_v1): retries marking a
	 * fully refunded order as refunded (`order_mark_refunded`) and reconciles refunds with an unknown
	 * outcome (`refund_reconcile`, A272): still open (no answer, lookup failed, provider not sure yet):
	 * next run with a longer wait; after RECONCILE_ATTEMPTS runs – or at once for an adapter without
	 * lookup – the admin is asked to decide (operations page / order page).
	 */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		$payload = (array) $payload;
		if ( self::ORDER_STATE_JOB === $type ) {
			$order_id = absint( $payload['order_id'] ?? 0 );
			if ( ! $order_id ) { return true; }
			$result = Orders::mark_refunded( $order_id, sanitize_text_field( (string) ( $payload['reason'] ?? 'refund_retry' ) ) );
			if ( true === $result ) {
				Audit::record( 'order_refund_state_recovered', 'order', $order_id, array( 'source' => 'job' ) );
				return true;
			}
			return false;
		}
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
			array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'reason' => sanitize_text_field( (string) $reason ), 'error' => \PassCaptain\Text::cut( (string) $error, 190 ) ) )
		);
		Jobs::enqueue( 'order-mark-refunded-' . $order_id . '-' . gmdate( 'YmdHi', Clock::now() ), self::ORDER_STATE_JOB, array( 'order_id' => $order_id, 'reason' => sanitize_text_field( (string) $reason ) ), gmdate( 'Y-m-d H:i:s', Clock::now() + MINUTE_IN_SECONDS ) );
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
		$since = gmdate( 'Y-m-d H:i:s', Clock::now() - 14 * DAY_IN_SECONDS );
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

	/** Logs a refund database problem (PHP error log and – if the database accepts it – the audit log). */
	private static function log_problem( $refund_id, $order_id, $step, $db_error, array $extra = array() ) {
		Log::error(
			'refund_db_error',
			sprintf( 'refund %d (%s) database error: %s %s', absint( $refund_id ), $step, $db_error, $extra ? wp_json_encode( $extra ) : '' ),
			array(
				'audit'       => (bool) $order_id,
				'object_type' => 'order',
				'object_id'   => absint( $order_id ),
				'details'     => array_merge( array( 'refund_id' => absint( $refund_id ), 'step' => $step, 'error' => \PassCaptain\Text::cut( $db_error, 190 ) ), $extra ),
			)
		);
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
		$cutoff = gmdate( 'Y-m-d H:i:s', Clock::now() - Config::get( 'refund_wait_minutes' ) * MINUTE_IN_SECONDS );
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
	public static function key_for( $refund_id, $attempt = 0 ) {
		return self::provider_key( $refund_id, $attempt );
	}

	/** Refund id of a key from key_for(), 0 for anything else. */
	public static function id_from_key( $key ) {
		return preg_match( '/^wpcap-refund-(\d+)(?:-\d+)?$/', (string) $key, $m ) ? (int) $m[1] : 0;
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
		$wpdb->update( "{$wpdb->prefix}wpcap_refunds", array( 'error_message' => self::OUTCOME_UNKNOWN, 'updated_at' => Clock::mysql() ), array( 'refund_id' => $refund_id, 'status' => 'processing', 'provider_reference' => '' ) );
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
		return Jobs::enqueue( 'refund-reconcile|' . absint( $refund_id ) . '|' . $attempt, 'refund_reconcile', array( 'refund_id' => absint( $refund_id ), 'attempt' => $attempt ), gmdate( 'Y-m-d H:i:s', Clock::now() + $delay * MINUTE_IN_SECONDS ) );
	}

	/** The reconciliation gave up: audit and admin mail (the refund stays visible as unresolved). */
	private static function needs_manual_decision( $refund_id, $result ) {
		$refund = self::get( $refund_id );
		if ( $refund && 'completed' === $refund['status'] && 'pending' === (string) ( $refund['provider_status'] ?? '' ) ) {
			// A348: still pending after about two days. Later webhooks still settle it.
			Audit::record( 'refund_provider_still_pending', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund_id ) );
			/* translators: 1: refund id, 2: provider refund reference */
			\PassCaptain\Alerts::raise( 'refund_provider_pending', __( 'Pass Captain: Erstattung beim Zahlungsanbieter noch ausstehend', 'pass-captain' ), sprintf( __( 'Die Erstattung #%1$d (Referenz %2$s) ist beim Zahlungsanbieter seit zwei Tagen ausstehend. Bitte im Konto des Anbieters prüfen. Meldet der Anbieter sie später als gescheitert, wird die Buchung automatisch storniert.', 'pass-captain' ), (int) $refund_id, (string) $refund['provider_reference'] ) );
			return;
		}
		if ( ! $refund || 'processing' !== $refund['status'] || '' !== (string) $refund['provider_reference'] ) { return; }
		$order = Orders::get( (int) $refund['order_id'] );
		Audit::record( 'refund_reconcile_exhausted', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : (string) $result ) );
		$reference = $order ? Frontend\Format::order_reference( (string) $order->public_id ) : (string) $refund['order_id'];
		\PassCaptain\Alerts::raise(
			'refund_unclear',
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
	 * Without a refund id (legacy call reconcile()) the bookkeeping sweep reconcile_bookings() runs
	 * instead and its count is returned.
	 *
	 * @param int|null $refund_id Refund (null: reconcile_bookings()).
	 * @param string   $source    job, admin, cli.
	 * @return string|int|\WP_Error completed, failed, waiting (provider has nothing yet), nothing (no unclear
	 *                              refund) or WP_Error (lookup failed or not possible).
	 */
	public static function reconcile( $refund_id = null, $source = 'job' ) {
		if ( null === $refund_id ) { return self::reconcile_bookings(); }
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
		$found = $gateway->find_refund( (array) $order, self::key_for( $refund_id, (int) ( $refund['provider_attempt'] ?? 0 ) ), $pending ? (string) $refund['provider_reference'] : '' );
		if ( is_wp_error( $found ) ) {
			\PassCaptain\Log::error( 'refund_lookup_failed', 'refund lookup failed', array( 'object_type' => 'order', 'object_id' => (int) $refund['order_id'], 'details' => array( 'refund_id' => (int) $refund_id, 'error' => $found->get_error_code(), 'source' => sanitize_key( $source ) ) ) );
			return $found;
		}
		if ( $pending ) {
			// A348: a booked refund still pending at the provider.
			if ( null === $found || 'pending' === ( $found['status'] ?? '' ) ) { return 'waiting'; }
			return self::apply_state( $refund, $found, $source );
		}
		if ( null === $found ) {
			// The call may still be on its way: "not found" counts only 10 minutes after it.
			if ( strtotime( $refund['updated_at'] . ' UTC' ) > Clock::now() - Config::get( 'refund_wait_minutes' ) * MINUTE_IN_SECONDS ) { return 'waiting'; }
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
		$reference = \PassCaptain\Text::cut( sanitize_text_field( (string) ( $state['reference'] ?? '' ) ), 191 );
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
				$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => \PassCaptain\Text::cut( $note, 255 ), 'updated_at' => Clock::mysql() ), array( 'status' => 'processing', 'provider_reference' => '' ) );
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
			$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => \PassCaptain\Text::cut( __( 'Der Zahlungsanbieter hat die Erstattung nachträglich abgelehnt; es wurde nichts ausgezahlt.', 'pass-captain' ), 255 ), 'updated_at' => Clock::mysql() ), array( 'status' => 'processing' ) );
			if ( false === $moved ) { return self::busy(); }
			if ( 1 === $moved ) { \PassCaptain\Log::error( 'refund_failed', 'refund failed', array( 'object_type' => 'order', 'object_id' => (int) $refund['order_id'], 'details' => array( 'refund_id' => $refund_id, 'error' => 'provider_failed', 'source' => sanitize_key( $source ) ) ) ); }
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
		$now = Clock::mysql();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'reversed', array( 'provider_status' => 'failed', 'reversed_at' => $now ) );
		if ( false === $moved ) { return self::busy(); }
		if ( 1 !== $moved ) {
			$wpdb->query( 'ROLLBACK' );
			return 'nothing';
		}
		$total = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=GREATEST(0,CAST(refunded_minor AS SIGNED)-%d),updated_at=%s WHERE order_id=%d", (int) $refund['amount_minor'], $now, (int) $refund['order_id'] ) );
		// The money is (partly) with the seller again: a "refunded" order is paid again (R5-8, both reversal paths).
		$state = false === $total ? false : $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET status='complete',payment_status='paid' WHERE order_id=%d AND status='refunded' AND refunded_minor<total_minor", (int) $refund['order_id'] ) );
		if ( false === $state || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) { return self::busy(); }
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
		\PassCaptain\Alerts::raise(
			'refund_failed',
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
		$booked = '' === $conflict && 1 === StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'processing', array( 'provider_reference' => $reference, 'error_message' => '', 'updated_at' => Clock::mysql() ), array( 'status' => 'failed' ) );
		if ( ! $booked || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$booked = false;
		}
		$public = $order ? Frontend\Format::order_reference( (string) $order->public_id ) : (string) $refund['order_id'];
		Audit::record( 'refund_paid_after_failure', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'reference' => $reference, 'booked' => $booked, 'source' => sanitize_key( $source ) ) );
		\PassCaptain\Alerts::raise(
			'refund_paid_late',
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
		$note = \PassCaptain\Text::cut( trim( sanitize_text_field( (string) $note ) ), 200 );
		if ( '' === $note ) {
			return new \WP_Error( 'wpcap_refund_note', __( 'Bitte angeben, wie der Ausgang geprüft wurde (z. B. „im Stripe-Dashboard gesehen“).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$refund = self::get( $refund_id );
		if ( ! $refund || 'processing' !== $refund['status'] || '' !== (string) $refund['provider_reference'] ) {
			return new \WP_Error( 'wpcap_refund_not_unresolved', __( 'Der Ausgang dieser Erstattung ist nicht offen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$refund_id = (int) $refund['refund_id'];
		if ( 'paid' === $decision ) {
			$reference = \PassCaptain\Text::cut( sanitize_text_field( (string) $reference ), 150 );
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
		$moved = StateMachine::transition( 'refund', "{$p}wpcap_refunds", 'refund_id', $refund_id, 'failed', array( 'error_message' => \PassCaptain\Text::cut( $message, 255 ), 'updated_at' => Clock::mysql() ), array( 'status' => 'processing', 'provider_reference' => '' ) );
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
			|| strtotime( (string) ( $refund['updated_at'] ?? '' ) . ' UTC' ) < Clock::now() - Config::get( 'refund_wait_minutes' ) * MINUTE_IN_SECONDS;
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
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*,o.public_id AS order_public_id,o.payment_provider,o.customer_email FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status='processing' AND r.provider_reference='' AND (r.error_message IN (%s,'needs_manual_check') OR r.updated_at < %s) ORDER BY r.refund_id LIMIT %d", self::OUTCOME_UNKNOWN, gmdate( 'Y-m-d H:i:s', Clock::now() - Config::get( 'refund_wait_minutes' ) * MINUTE_IN_SECONDS ), max( 1, (int) $limit ) ), ARRAY_A );
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
		if ( $amount <= 0 && ! $ticket_ids && (int) $amount_minor <= 0 ) {
			// Nothing to record (amount 0 or negative, no ticket): a no-op, no zero row.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( $amount <= 0 && ( ! $ticket_ids || (int) $amount_minor > 0 ) ) {
			// Nothing left to refund (e.g. a native refund pays out the rest right now, or another webhook
			// booked it): never book a refund over 0 € with a credit note (A346). A free order's tickets
			// are still cancelled.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_nothing', __( 'Es ist kein Betrag mehr offen, der als Erstattung verbucht werden könnte.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$tax = isset( $args['tax_minor'] ) ? min( $amount, absint( $args['tax_minor'] ) ) : self::tax_for( $order, $ticket_ids ?: null, $amount );
		if ( self::$read_failed ) { return self::record_error( 0, $order_id, 'read', true ); }
		$now = Clock::mysql();
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $amount,
			'tax_minor' => $tax,
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( $ticket_ids ),
			'reason' => \PassCaptain\Text::cut( sanitize_text_field( $reason ), 255 ),
			'status' => 'completed',
			'provider_reference' => \PassCaptain\Text::cut( sanitize_text_field( (string) ( $args['provider_reference'] ?? 'external' ) ), 191 ),
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
		$released = array();
		$booked = true;
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$counted = array_map( 'intval', (array) $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND status IN ('valid','checked_in','revoked')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$booked = '' === (string) $wpdb->last_error;
			$revoked_ids = $booked ? Orders::revoke_ids( $ticket_ids ) : array();
			if ( $booked ) {
				// revoke_ids() cannot report errors: verify that no ticket of this refund is still valid.
				$still_valid = $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE status='valid' AND ticket_id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$booked = null !== $still_valid && 0 === (int) $still_valid;
			}
			// Same rule as native refunds (CapacityRelease): a ticket whose unit a revoke or an exchange
			// already released is flagged refunded without releasing it again.
			$released = $booked ? CapacityRelease::release_tickets( $order_id, $counted, array( 'refund' => true ) ) : false;
			$booked = false !== $released && false !== $wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		}
		$total_ok = $booked ? $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d", $amount, $now, $order_id ) ) : false;
		if ( false === $total_ok || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			// Nothing recorded: the adapter may retry with the same idempotency key.
			return self::record_error( $refund_id, $order_id, 'book', true );
		}
		if ( $ticket_ids ) { CapacityCounters::sync_order_now( $order_id ); }
		Orders::fire_revoked( $revoked_ids, 'refund_' . $refund_id );
		CapacityRelease::announce( $order_id, (array) $released, 'partial_refund', array( 'refund_id' => $refund_id ) );
		// Tickets revoked by an admin or exchanged away do not keep the order open (A357).
		$remaining = self::refundable_count( $order_id );
		if ( 0 === $remaining && $ticket_ids ) {
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
			// A496: a buyer's cancellation request never pays out a ticket that was transferred to another
			// person since (request() and transfers exclude each other; this also covers older data).
			if ( 'portal' === (string) ( $refund['source'] ?? '' ) ) {
				$transferred = self::transferred_ticket_ids( (int) $refund['order_id'], $ticket_ids );
				if ( null === $transferred ) { return self::CONFLICT_UNKNOWN; }
				if ( $transferred ) {
					return __( 'Mindestens eines der Tickets wurde inzwischen an eine andere Person übertragen; die Stornierungsanfrage der Käuferin bzw. des Käufers wurde nicht ausgeführt.', 'pass-captain' );
				}
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

	/**
	 * Tickets of the list (of this order) that were transferred to another person (A496); null when
	 * the read failed.
	 *
	 * @param int   $order_id   Order id.
	 * @param int[] $ticket_ids Ticket ids.
	 * @return int[]|null
	 */
	private static function transferred_ticket_ids( $order_id, array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ids ) { return array(); }
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND transfer_count>0 AND ticket_id IN (" . implode( ',', $ids ) . ')', absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( '' !== $wpdb->last_error ) { return null; }
		return array_map( 'intval', (array) $rows );
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
	/** Whether wpcap_refunds has the service fee columns (schema 3.18.4; not yet during a deferred upgrade). */
	public static function fee_ready() {
		return Log::has_column( 'wpcap_refunds', 'fee_kept_tax_minor' );
	}

	/** Service fee columns of a refund row for SELECTs (',r.fee_minor,…'), '' before schema 3.18.4. */
	public static function fee_columns( $alias = 'r' ) {
		$a = '' !== $alias ? $alias . '.' : '';
		return self::fee_ready() ? ",{$a}fee_minor,{$a}fee_tax_minor,{$a}fee_kept_minor,{$a}fee_kept_tax_minor" : '';
	}

	/** VAT of service fees kept by earlier or pending refunds of an order. */
	private static function fee_kept_tax_total( $order_id ) {
		global $wpdb;
		if ( ! self::fee_ready() ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(fee_kept_tax_minor),0) FROM %i WHERE order_id=%d AND status IN ('completed','pending_approval','approved','processing')", $wpdb->prefix . 'wpcap_refunds', absint( $order_id ) ) );
	}

	/** VAT of an order not yet refunded or reserved by a running refund. */
	private static function remaining_tax( $order ) {
		global $wpdb;
		$refunded_tax = (int) self::checked( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tax_minor),0) FROM %i WHERE order_id=%d AND status IN ('completed','processing','approved','pending_approval')", $wpdb->prefix . 'wpcap_refunds', (int) $order->order_id ) ) );
		return max( 0, (int) $order->tax_minor - $refunded_tax );
	}

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
		// A425: a service fee kept by calculate() counts as kept as well (value = with the fee).
		$kept_fee = (int) ( $calc['fee_kept_minor'] ?? 0 );
		$calc['value_minor'] = $value + $kept_fee;
		$calc['deducted_minor'] = $value - $amount + $kept_fee;
		if ( $amount !== $value ) {
			$calc['amount_minor'] = $amount;
			$calc['tax_minor'] = $value > 0 ? (int) round( (int) $calc['tax_minor'] * $amount / $value ) : 0;
		}
		return $calc;
	}

	/** Fees kept by earlier or pending refunds of an order (deducted_minor). */
	private static function deducted_total( $order_id ) {
		global $wpdb;
		$sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(deducted_minor),0) FROM %i WHERE order_id=%d AND status IN ('completed','pending_approval','approved','processing')", $wpdb->prefix . 'wpcap_refunds', absint( $order_id ) ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->last_error = ''; return 0; } // Column missing before the schema upgrade.
		return (int) $sum;
	}

	/**
	 * Read-only preview of request() for the admin refund form (A340, R7): amount, VAT and kept fee for
	 * the tickets (empty = all open tickets), taking refunds that are pending or running into account,
	 * and whether the threshold would require a second approval for the current user. Tickets already
	 * used for admission additionally need an exception (request() decides).
	 *
	 * @param object|int $order      Order row or id.
	 * @param int[]      $ticket_ids Tickets (empty = all open).
	 * @param array      $options    deduct_minor, deduct_bp, max_minor (see apply_deduction()).
	 * @return array|\WP_Error {ticket_ids, value_minor, deducted_minor, amount_minor, tax_minor, currency, needs_approval}
	 */
	public static function preview( $order, array $ticket_ids = array(), array $options = array() ) {
		$order = is_object( $order ) ? $order : Orders::get( absint( $order ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$open = self::open_refunds( (int) $order->order_id );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		$calc = self::calculate( $order, $requested, $open['ticket_ids'], $open['amount_minor'], $options );
		if ( is_wp_error( $calc ) ) { return $calc; }
		$calc = self::apply_deduction( $calc, $options ) + array( 'currency' => (string) $order->currency );
		$threshold = (int) Settings::get( 'refund_approval_threshold' );
		$cumulative = (int) $order->refunded_minor + $open['amount_minor'] + $calc['amount_minor'];
		$calc['needs_approval'] = $threshold > 0 && $cumulative > $threshold && ! self::may_self_approve();
		return $calc;
	}

	/**
	 * Calculates the refund amount for tickets (not yet refunded) from the order item snapshots.
	 * The last remaining tickets receive the rounding remainder so a full refund equals the order total.
	 *
	 * A425 (owner decision 23.11.2026): the service fee of the tickets goes back for tickets of a cancelled
	 * occurrence/event, else per setting service_fee_refund (buyer's wish, default: kept); option
	 * service_fee ('refund' | 'retain') decides for the whole refund (ServiceFee::refund_part()). A kept
	 * fee is not refunded (amount and VAT without it) and is reported as fee_kept_minor (apply_deduction()
	 * books it as kept, so later refunds never return it).
	 *
	 * @param array $options service_fee ('refund', 'retain', '' = automatic).
	 * @return array|\WP_Error ticket_ids, amount_minor, tax_minor, fee_minor, fee_tax_minor, fee_kept_minor, fee_kept_tax_minor.
	 */
	public static function calculate( $order, array $ticket_ids, array $reserved_ids = array(), $reserved_minor = 0, array $options = array() ) {
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
		// A357: completeness is decided by ticket value. Tickets that are neither refundable nor refunded
		// (revoked by an admin, with or without releasing their place) keep their share of the money;
		// tickets exchanged away carried theirs into the new ticket, which is refundable. Fees kept by
		// earlier refunds (apply_deduction()) are not refunded with the remaining tickets either.
		$retained = self::retained_ticket_ids( $order, array_map( static function ( $t ) { return (int) $t->ticket_id; }, $open ) );
		$retained_minor = $retained ? self::scaled_value( $order, $retained ) : 0;
		$remaining_total = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - max( 0, (int) $reserved_minor ) - $retained_minor - self::deducted_total( (int) $order->order_id ) );
		$full = count( $ticket_ids ) === count( $open_ids );
		if ( $full ) {
			// The last remaining tickets receive the rounding remainder.
			$amount = $remaining_total;
		} elseif ( null !== Orders::snapshot_line_discounts( $order ) ) {
			// Discount split per line known: each ticket refunds its own line's discounted price, scaled to
			// the cash part of the order (a multi-purpose voucher paid the rest; its share goes back to the
			// voucher, Vouchers\Checkout::refund_plan()).
			$amount = min( $remaining_total, self::discounted_share( $order, $ticket_ids, true ) );
		} else {
			$amount = min( $remaining_total, self::scaled_value( $order, $ticket_ids ) );
		}
		$kept_tax_before = $full ? self::fee_kept_tax_total( (int) $order->order_id ) : 0;
		$tax = $kept_tax_before > 0 && ! $retained && $amount >= $remaining_total
			// The rest of the order after refunds that kept a fee: its VAT is what is left without the kept fees' VAT.
			? min( $amount, max( 0, self::remaining_tax( $order ) - $kept_tax_before ) )
			: self::tax_for( $order, $full && ! $retained ? null : $ticket_ids, $amount );
		$fee = ServiceFee::refund_part( $order, $ticket_ids, $full, sanitize_key( (string) ( $options['service_fee'] ?? '' ) ) );
		$kept = min( $amount, $fee['kept'] );
		$kept_tax = min( $tax, $fee['kept_tax'] );
		$amount -= $kept;
		$tax = min( $amount, $tax - $kept_tax );
		return array(
			'ticket_ids' => $ticket_ids,
			'amount_minor' => $amount,
			'tax_minor' => $tax,
			'fee_minor' => min( $amount, $fee['refund'] ),
			'fee_tax_minor' => min( $tax, $fee['refund_tax'] ),
			'fee_kept_minor' => $kept,
			'fee_kept_tax_minor' => $kept_tax,
		);
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
	/**
	 * Whether the requester's own refund above `refund_approval_threshold` runs without a second person:
	 * approvers (`wpcap_approve_refunds`) may by default; with the setting `refund_four_eyes_approvers`
	 * (A643) every refund above the threshold waits for a different approver. System requests never
	 * approve themselves.
	 */
	public static function may_self_approve( $system = false ) {
		if ( $system || ! current_user_can( self::APPROVE_CAP ) ) { return false; }
		return ! Settings::get( 'refund_four_eyes_approvers' );
	}

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
		// Add-on attachments for the correction document (wp_pass_captain_invoice_mail_attachments_v1); temporary files.
		$attachments = Api\Invoices::correction_mail_attachments( $refund_id, (int) $order->order_id );
		try {
			Mail\Mailer::send( 'refund_confirmation', $order->customer_email, $vars, $attachments, (string) ( $order->locale ?? '' ), (int) ( $order->order_id ?? 0 ) );
		} finally {
			foreach ( $attachments as $file ) { wp_delete_file( $file ); }
		}
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
		// A425: the per-ticket service fee is part of the ticket's share (grouped with its type); the
		// per-order fee belongs to no ticket and goes back with the last one (calculate()).
		$fees = ServiceFee::ready();
		$sql = $fees
			? "SELECT ticket_type_id,SUM(quantity) AS quantity,SUM(line_total_minor) AS line_total,MAX(unit_price_minor) AS fee_unit,fee_kind FROM %i WHERE order_id=%d GROUP BY ticket_type_id,fee_kind ORDER BY fee_kind='ticket'"
			: 'SELECT ticket_type_id,SUM(quantity) AS quantity,SUM(line_total_minor) AS line_total FROM %i WHERE order_id=%d GROUP BY ticket_type_id';
		foreach ( self::checked( $wpdb->get_results( $wpdb->prepare( $sql, $p . 'wpcap_order_items', (int) $order->order_id ) ) ) as $item ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- one of two literal queries.
			$type = (int) $item->ticket_type_id;
			$kind = (string) ( $item->fee_kind ?? '' );
			if ( '' !== $kind ) {
				$all += (int) $item->line_total;
				if ( ServiceFee::KIND_TICKET === $kind && isset( $unit[ $type ] ) ) { $unit[ $type ] += (int) $item->fee_unit; }
				continue;
			}
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

	/**
	 * Gross, VAT and base shares (minor units) of the given tickets, from the order lines they belong to.
	 *
	 * @return int[] [gross, tax, base]
	 */
	private static function line_shares( $order, array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $ticket_ids ) { return array( 0, 0, 0 ); }
		$unit = array();
		$fees = ServiceFee::ready();
		$items = (array) self::checked( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,quantity,unit_price_minor,line_total_minor,tax_minor' . ( OrderLines::ready() ? ',discount_minor' : '' ) . ( $fees ? ',fee_kind' : '' ) . ' FROM %i WHERE order_id=%d ORDER BY item_id', $p . 'wpcap_order_items', (int) $order->order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column list.
		// Line totals are stored before the order discount, the line VAT after it: the gross that carries this VAT
		// is the discounted line total (same allocation as in reports and settlements).
		$discount = isset( $order->discount_minor ) ? (int) $order->discount_minor : (int) $wpdb->get_var( $wpdb->prepare( "SELECT discount_minor FROM {$p}wpcap_orders WHERE order_id=%d", (int) $order->order_id ) );
		$discounted = \PassCaptain\Reports\Data::allocate_discount( $items, $discount );
		$fee_lines = array();
		foreach ( array_values( $items ) as $i => $item ) {
			if ( ServiceFee::is_fee_line( $item ) ) {
				$fee_lines[ $i ] = $item;
				continue;
			}
			$q = max( 1, (int) $item->quantity );
			$unit[ (int) $item->ticket_type_id ] = array( (int) $item->line_total_minor / $q, (int) $item->tax_minor / $q, (int) $discounted[ $i ] / $q );
		}
		// A425: a ticket's share includes its per-ticket service fee (same VAT rate as the ticket line); the
		// per-order fee is no ticket's share.
		foreach ( $fee_lines as $i => $item ) {
			$type = (int) $item->ticket_type_id;
			if ( ServiceFee::KIND_TICKET !== (string) $item->fee_kind || ! isset( $unit[ $type ] ) ) { continue; }
			$n = ServiceFee::covered( $item );
			$unit[ $type ][0] += (int) $item->line_total_minor / $n;
			$unit[ $type ][1] += (int) $item->tax_minor / $n;
			$unit[ $type ][2] += (int) $discounted[ $i ] / $n;
		}
		$ids = array_values( array_map( 'absint', $ticket_ids ) );
		$gross = 0.0;
		$tax = 0.0;
		$net_of_discount = 0.0;
		foreach ( self::checked( $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_type_id FROM %i WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets', (int) $order->order_id ), $ids ) ) ) ) as $type_id ) {
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
		$refunded_tax = (int) self::checked( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tax_minor),0) FROM %i WHERE order_id=%d AND status IN ('completed','processing','approved','pending_approval')", $wpdb->prefix . 'wpcap_refunds', (int) $order->order_id ) ) );
		$remaining_tax = max( 0, (int) $order->tax_minor - $refunded_tax );
		$pending = (int) self::checked( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM %i WHERE order_id=%d AND status IN ('processing','approved','pending_approval')", $wpdb->prefix . 'wpcap_refunds', (int) $order->order_id ) ) );
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
