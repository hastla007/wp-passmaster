<?php
/**
 * Payment confirmation: external and manual payments, late payment recovery and payments that cannot be allocated.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Admin;
use PassCaptain\Audit;
use PassCaptain\Campaigns;
use PassCaptain\CapacityCounters;
use PassCaptain\Clock;
use PassCaptain\DbSession;
use PassCaptain\Errors;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Payments;
use PassCaptain\Sales;
use PassCaptain\Settings;
use PassCaptain\StateMachine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::confirm_external_payment(), confirm_manual_payment(), late_payment_reason()
 * and schedule_late_refund(), with the confirmation transaction, the late payment re-check, the
 * `needs_review` path and the commit probe. Moved out of Orders without behaviour change (TODO
 * "Gott-Klasse Orders"). Transactions are started and checked through Orders\Transaction.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Payment {
	/** Rolls back a payment confirmation that hit a deadlock/lock timeout; callers retry (webhooks get a 5xx). */
	private static function payment_busy( $order_id = 0 ) {
		global $wpdb;
		$lost = false === Transaction::in_transaction();
		$wpdb->query( 'ROLLBACK' );
		// Statements after a lost connection may have changed counters without their rows (A183).
		if ( $lost && $order_id ) { CapacityCounters::repair_order( $order_id ); }
		return Errors::busy( __( 'Die Zahlung konnte gerade nicht verbucht werden. Bitte erneut versuchen.', 'pass-captain' ), 'wpcap_db_busy', array( 'retry_after' => Orders::RETRY_AFTER_SECONDS ) );
	}

	/**
	 * Confirms a payment reported by an external system (e.g. WooCommerce). Idempotent.
	 *
	 * Active holds are converted. If the hold has lapsed (late payment recovery), everything that may
	 * have changed since is checked again under row locks: occurrence and event status, the
	 * occurrence end, module resources, capacity, discount code limits and `wpcap_order_recovering`
	 * (buyer limits). If any check fails, the order is flagged `needs_review` with payment status
	 * `paid_unallocated` – no tickets are issued, nothing is overbooked, the admin is alerted and,
	 * with the setting `late_payment_auto_refund`, the payment is refunded automatically.
	 *
	 * Buyer limits (Sales\BuyerLimits) are re-checked for such a late payment as well: the lapsed hold
	 * no longer counted against the buyer, who may have bought up to the limit in the meantime. If the
	 * payment would push the buyer past a limit, the order goes the same `needs_review` way (admin
	 * alert; the operator refunds it or raises the limit and confirms again). The buyer locks are taken
	 * before the transaction, in the same order as Orders::create().
	 *
	 * @return true|\WP_Error
	 */
	public static function confirm_external_payment( $order_id, $reference = '' ) {
		$order_id = absint( $order_id );
		/**
		 * Before a payment is confirmed, outside any transaction: modules prepare what the late payment
		 * re-check needs and cannot do under row locks, e.g. Voucher Captain reserves a voucher again whose
		 * reservation was released when the hold lapsed (A632). Runs for every confirmation (also repeated
		 * ones); handlers must be idempotent and check the order state themselves.
		 *
		 * @param int $order_id Order.
		 */
		do_action( 'wpcap_before_payment_confirm', $order_id );
		return DbSession::with_short_lock_wait( static function () use ( $order_id, $reference ) {
			$buyer = self::late_payment_buyer( $order_id );
			$locks = array();
			if ( $buyer ) {
				$locks = Sales\BuyerLimits::acquire( $buyer->customer_email, (int) $buyer->customer_user_id );
				if ( false === $locks ) { return self::payment_busy(); }
			}
			try {
				return self::confirm_payment_locked( $order_id, $reference, (bool) $buyer );
			} finally {
				if ( $locks ) { Sales\BuyerLimits::release( $locks ); }
			}
		} );
	}

	/**
	 * Order row (e-mail, customer id) when a payment confirmation may need the buyer-limit re-check:
	 * not paid yet, not closed, no door sale, and an active buyer limit covers one of its ticket types.
	 * Unlocked pre-read; the decision is repeated under the row lock.
	 */
	private static function late_payment_buyer( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,customer_email,customer_user_id,status,payment_status,payment_provider FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $order_id ) );
		if ( ! $order || 'paid' === $order->payment_status || in_array( $order->status, array( 'refunded', 'cancelled' ), true ) || in_array( (string) $order->payment_provider, array( 'pos', 'boxoffice' ), true ) ) { return null; }
		$types = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ticket_type_id FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', $order_id ) ) );
		return $types && Sales\BuyerLimits::relevant( $types ) ? $order : null;
	}

	/**
	 * confirm_external_payment() with the buyer locks held (when $check_limits).
	 *
	 * Lock order (as in Orders::create(), so payments, checkouts and the hold cleanup cannot deadlock
	 * each other): the order lines and reservation ids are read before the transaction (they never
	 * change after the order was created). Then the order row, ticket types and occurrences (the paid
	 * units enter the capacity counters); a late payment then locks module resources
	 * (`wpcap_reserve_capacity`: pools, seats), and finally the order's reservations. Reservations are
	 * locked by primary key FOR UPDATE (record locks only: no gap locks on the order_id index, no
	 * shared lock that is upgraded later). If an on-time confirmation finds that the hold lapsed
	 * meanwhile, it starts over as a late payment.
	 */
	private static function confirm_payment_locked( $order_id, $reference, $check_limits ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Autocommit reads (no snapshot yet): the late re-check must take its snapshot after its locks.
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id, SUM(quantity) AS quantity FROM %i WHERE order_id=%d GROUP BY ticket_type_id ORDER BY ticket_type_id', $p . 'wpcap_order_items', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
		$hold_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT reservation_id FROM %i WHERE order_id=%d ORDER BY reservation_id', $p . 'wpcap_reservations', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
		$late = ! Holds::active( $order_id );
		$result = self::confirm_payment_transaction( $order_id, $reference, $check_limits, (array) $lines, $hold_ids, $late );
		if ( 'hold_lapsed' === $result ) {
			// The hold expired between the pre-read and the lock: take the type/occurrence locks first.
			$result = self::confirm_payment_transaction( $order_id, $reference, $check_limits, (array) $lines, $hold_ids, true );
		}
		return 'hold_lapsed' === $result ? self::payment_busy() : $result;
	}

	/**
	 * One confirmation transaction (see confirm_payment_locked()).
	 *
	 * Lock order: order row, ticket types and occurrences by id (as in Orders::create(); the paid units
	 * enter the sold counters in this transaction), then – for a late payment – module resources
	 * (`wpcap_reserve_capacity`), then the order's reservations by primary key.
	 *
	 * @return true|\WP_Error|string 'hold_lapsed' when an on-time confirmation found no active hold any more.
	 */
	private static function confirm_payment_transaction( $order_id, $reference, $check_limits, array $lines, array $hold_ids, $late ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = Clock::mysql();
		// Without a transaction the status update below would autocommit and the in_transaction()
		// check before COMMIT would then roll back nothing: a paid order without tickets (A183).
		if ( false === Transaction::begin() ) { return self::payment_busy( $order_id ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy( $order_id ); }
		if ( ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'pass-captain' ) );
		}
		if ( 'paid' === $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' ); // Nothing written; the issuance locks the order itself.
			// A repeated confirmation (webhook redelivery after an error) completes what is missing:
			// counters of an order whose booking ran partly outside its transaction, and tickets.
			CapacityCounters::sync_order_now( $order_id );
			$issued = Orders::issue_and_email( $order_id );
			return is_wp_error( $issued ) ? $issued : true;
		}
		if ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ) );
		}
		// Capacity locks: ticket types, then occurrences, with the same statements and in the same order as
		// Orders::create(). Locking reads only until every capacity lock is held (fresh snapshot for the
		// re-check below). A checkout holding these locks never locks existing order rows (seat claims are
		// released without shared locks, see Seating\Reservations::release_stale()).
		$locked_types = array();
		foreach ( $lines as $line ) {
			$locked_types[ (int) $line->ticket_type_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d FOR UPDATE', $p . 'wpcap_ticket_types', $line->ticket_type_id ) );
			if ( '' !== $wpdb->last_error ) { return self::payment_busy( $order_id ); }
		}
		$occ_ids = array_unique( array_map( static function ( $type ) { return $type ? (int) $type->occurrence_id : 0; }, $locked_types ) );
		sort( $occ_ids, SORT_NUMERIC );
		$occurrences = array();
		foreach ( $occ_ids as $occ_id ) {
			if ( ! $occ_id ) { continue; }
			$occurrences[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE', $p . 'wpcap_occurrences', $p . 'wpcap_events', $occ_id ) );
			if ( '' !== $wpdb->last_error ) { return self::payment_busy( $order_id ); }
		}
		$recovered = false;
		if ( $late ) {
			// Late payment: everything that may have changed since the hold lapsed is checked again:
			// ticket type, occurrence/event status and end (A273), module resources (`wpcap_reserve_capacity`),
			// capacity, buyer limits (Sales\BuyerLimits, buyer locks held), discount code limits (A353,
			// PAY-1) and `wpcap_order_recovering` (e.g. allocation buyer limits, A483).
			$demand = array();
			foreach ( $lines as $line ) { $demand[ (int) $line->ticket_type_id ] = (int) $line->quantity; }
			$recover_context = array( 'phase' => 'recover', 'order_id' => $order_id, 'types' => $locked_types, 'occurrences' => array_filter( $occurrences ), 'now' => $now, 'source' => (string) $order->payment_provider, 'email' => (string) $order->customer_email, 'user_id' => (int) ( $order->customer_user_id ?? 0 ), 'internal' => array() );
			$error = self::recovery_error( $lines, $locked_types, $occurrences, $now );
			if ( '' === $error ) {
				foreach ( $lines as $line ) {
					$type = $locked_types[ (int) $line->ticket_type_id ] ?? null;
					if ( ! $type || 'active' !== (string) $type->status ) { $error = 'ticket_type_unavailable'; break; }
				}
			}
			// Module resources may write (e.g. seats claimed again): undone if the re-check fails below.
			if ( false === $wpdb->query( 'SAVEPOINT wpcap_recover' ) ) { return self::payment_busy( $order_id ); }
			$reserve_error = '' === $error ? Capacity::reserve( $demand, $recover_context ) : null;
			// A transient lock problem (deadlock, lock wait timeout) is no "sold out": roll back so the
			// payment provider redelivers the webhook (5xx) instead of flagging a paid order for review.
			if ( $reserve_error && ( 'db_busy' === $reserve_error->get_error_code() || Orders::is_retryable_error( $reserve_error ) ) ) { return self::payment_busy( $order_id ); }
			if ( false === Holds::lock( $hold_ids ) ) { return self::payment_busy( $order_id ); }
			$per_occurrence = array();
			foreach ( '' !== $error ? array() : $lines as $line ) {
				$type = $locked_types[ (int) $line->ticket_type_id ];
				$occurrence = $occurrences[ (int) $type->occurrence_id ];
				$check_occurrence = clone $occurrence;
				$check_occurrence->capacity = 0; // Occurrence capacity is checked below for the summed quantity.
				$error = Capacity::error( $type, $check_occurrence, (int) $line->quantity, $order_id );
				if ( $error ) { break; }
				$per_occurrence[ (int) $occurrence->occurrence_id ] = ( $per_occurrence[ (int) $occurrence->occurrence_id ] ?? 0 ) + ( Sales\Addons::is_addon( $type ) ? 0 : (int) $line->quantity ); // Add-ons use no seats.
			}
			if ( ! $error && $reserve_error ) {
				$error = $reserve_error->get_error_code();
			}
			if ( ! $error ) {
				foreach ( $per_occurrence as $occ_id => $quantity ) {
					$unlimited_type = (object) array( 'ticket_type_id' => 0, 'capacity' => 0 );
					$error = Capacity::error( $unlimited_type, $occurrences[ $occ_id ], $quantity, $order_id );
					if ( $error ) { break; }
				}
			}
			if ( 'db_busy' === $error ) { return self::payment_busy( $order_id ); }
			if ( ! $error && $check_limits ) {
				// The lapsed hold no longer counted against the buyer: re-check the limits (buyer locks held).
				$limit_error = Sales\BuyerLimits::check( $demand, $locked_types, array_filter( $occurrences ), (string) $order->customer_email, (int) ( $order->customer_user_id ?? 0 ), $now, $order_id );
				if ( $limit_error ) { $error = 'buyer_limit'; }
			}
			if ( ! $error ) {
				// Discount codes released when the hold lapsed are redeemed again under the campaign lock,
				// within their usage limit (A353, PAY-1).
				$campaign_error = Campaigns::recover_for_order( $order_id );
				if ( is_wp_error( $campaign_error ) ) {
					if ( Orders::is_retryable_error( $campaign_error ) ) { return self::payment_busy( $order_id ); }
					$error = $campaign_error->get_error_code();
				}
			}
			if ( ! $error ) {
				/**
				 * Late payment recovery, inside the transaction after capacity and discount codes (like
				 * `wpcap_order_creating` on checkout): modules re-check per-order rules that may have
				 * changed since the hold lapsed (e.g. buyer limits) under their own row locks. Return a
				 * WP_Error to flag the order for review instead of issuing tickets (a busy error –
				 * Errors::busy() – rolls back so the provider redelivers).
				 *
				 * @param null|\WP_Error $result   Null to continue.
				 * @param int            $order_id Order.
				 * @param int[]          $demand   ticket_type_id => quantity of the order.
				 * @param array          $context  As for `wpcap_reserve_capacity` (phase 'recover').
				 */
				$recovering = apply_filters( 'wpcap_order_recovering', null, $order_id, $demand, $recover_context );
				if ( $recovering instanceof \WP_Error ) {
					if ( Orders::is_retryable_error( $recovering ) ) { return self::payment_busy( $order_id ); }
					$error = $recovering->get_error_code();
				}
			}
			if ( $error ) {
				return self::payment_unallocated( $order, $error, $reference, $hold_ids, $now );
			}
			$recovered = true;
		} else {
			$holds = Holds::lock( $hold_ids );
			if ( false === $holds ) { return self::payment_busy( $order_id ); }
			$active = array_filter( $holds, static function ( $hold ) use ( $now ) { return 'active' === $hold->status && $hold->expires_at > $now; } );
			if ( ! $active ) {
				$wpdb->query( 'ROLLBACK' );
				return 'hold_lapsed';
			}
		}
		// Checked like Orders::create(): after a server-side rollback the remaining statements would
		// autocommit without the capacity re-check's locks. Guarded like every order transition (StateMachine).
		if ( 1 !== StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'complete', array( 'payment_status' => 'paid', 'updated_at' => $now ) + ( Orders::schema_at_least( '3.18.1' ) ? array( 'paid_at' => $now ) : array() ) ) ) { return self::payment_busy( $order_id ); }
		if ( false === Holds::set_status( $hold_ids, 'converted', array( 'active', 'expired', 'released' ) ) ) { return self::payment_busy( $order_id ); }
		// Holds leave held_qty, the paid lines enter sold_qty – atomically with the status change.
		if ( false === CapacityCounters::sync_order( $order_id ) ) { return self::payment_busy( $order_id ); }
		if ( false === Campaigns::commit_for_order( $order_id ) ) { return self::payment_busy( $order_id ); }
		if ( ! self::commit_probe( $order_id, 'paid' ) ) { return self::payment_busy( $order_id ); }
		Orders::flush_availability_cache();
		Audit::record( 'external_payment_confirmed', 'order', $order_id, array( 'reference' => sanitize_text_field( $reference ), 'late_recovery' => $recovered ) );
		do_action( 'wpcap_order_paid', $order_id );
		// Paid but not yet issued (database error): the caller retries (webhooks get a 5xx and are
		// redelivered), and the repeated confirmation issues the tickets.
		$issued = Orders::issue_and_email( $order_id );
		return is_wp_error( $issued ) ? $issued : true;
	}

	/**
	 * Late payment that cannot be allocated: undoes module claims of the recovery (savepoint), flags
	 * the order needs_review / paid_unallocated (StateMachine), expires the holds, syncs the capacity
	 * counters, gives reserved discount codes back, commits and then alerts the admin (and queues the
	 * automatic refund with `late_payment_auto_refund`, see late_payment_unallocated()). needs_review is
	 * a final answer (webhooks acknowledge it), so it is persisted before anything is reported; when a
	 * step is not durable the provider redelivers (A183).
	 *
	 * The returned error's `reason` is 'sale_closed' for a closed sale (detail in `detail`: ticket type
	 * unavailable, occurrence cancelled/ended), otherwise the cause (buyer_limit, campaign_exhausted,
	 * seat_sold_out, sold_out, voucher_released, allocation_buyer_limit, …).
	 *
	 * @return \WP_Error wpcap_needs_review (or wpcap_db_busy when it could not be stored).
	 */
	private static function payment_unallocated( $order, $error, $reference, array $hold_ids, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $order->order_id;
		if ( false === $wpdb->query( 'ROLLBACK TO SAVEPOINT wpcap_recover' ) ) { return self::payment_busy( $order_id ); }
		// A repeated flag of a needs_review order changes nothing (StateMachine returns 0, not false).
		if ( false === StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'needs_review', array( 'payment_status' => 'paid_unallocated', 'updated_at' => $now ) )
			|| false === Holds::set_status( $hold_ids, 'expired', array( 'active' ) )
			|| false === CapacityCounters::sync_order( $order_id )
			|| false === Campaigns::release_for_order( $order_id ) ) {
			return self::payment_busy( $order_id );
		}
		if ( ! self::commit_probe( $order_id, 'paid_unallocated' ) ) { return self::payment_busy( $order_id ); }
		Orders::flush_availability_cache();
		Transaction::capacity_released( $order_id, 'payment_unallocated' );
		self::late_payment_unallocated( $order, $error, $reference );
		$closed = in_array( $error, array( 'sale_closed', 'ticket_type_unavailable', 'occurrence_unavailable', 'occurrence_ended' ), true );
		$data = array( 'reason' => $closed ? 'sale_closed' : (string) $error );
		if ( $closed ) { $data['detail'] = (string) $error; }
		return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und die Tickets können nicht mehr ausgestellt werden. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ) . ' ' . self::late_payment_reason( $error ), $data );
	}

	/**
	 * Late payment re-check of the order lines against the locked rows: ticket type and occurrence
	 * still there, occurrence `scheduled`, event `published` and the occurrence not ended
	 * (occurrence_end()). Returns an error code or ''.
	 *
	 * @param object[] $lines        ticket_type_id, quantity.
	 * @param array    $types        Locked ticket types by id (null = missing).
	 * @param array    $occurrences  Locked occurrences (with event_status) by id.
	 * @param string   $now          UTC now.
	 */
	private static function recovery_error( array $lines, array $types, array $occurrences, $now ) {
		foreach ( $lines as $line ) {
			$type = $types[ (int) $line->ticket_type_id ] ?? null;
			$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
			if ( ! $type || ! $occurrence ) { return 'ticket_type_unavailable'; }
			if ( 'scheduled' !== (string) $occurrence->status || 'published' !== (string) ( $occurrence->event_status ?? '' ) ) { return 'occurrence_unavailable'; }
			if ( Orders::occurrence_end( $occurrence ) < $now ) { return 'occurrence_ended'; }
		}
		return '';
	}

	/** Admin text for the reason a late payment could not be allocated. */
	public static function late_payment_reason( $code ) {
		switch ( (string) $code ) {
			case 'occurrence_unavailable':
				return __( 'Der Termin oder das Event wurde inzwischen abgesagt bzw. ist nicht mehr im Verkauf.', 'pass-captain' );
			case 'occurrence_ended':
				return __( 'Der Termin ist bereits vorbei.', 'pass-captain' );
			case 'ticket_type_unavailable':
				return __( 'Die Ticketart ist nicht mehr vorhanden.', 'pass-captain' );
			case 'campaign_exhausted':
				return __( 'Das Nutzungslimit des Rabattcodes ist inzwischen ausgeschöpft.', 'pass-captain' );
			case 'allocation_buyer_limit':
			case 'buyer_limit':
				return __( 'Die maximale Anzahl an Tickets pro Person wäre überschritten.', 'pass-captain' );
			case 'sale_closed':
				return __( 'Der Termin ist inzwischen abgesagt oder vorbei bzw. die Ticketart ist nicht mehr im Verkauf.', 'pass-captain' );
			case 'seat_sold_out':
				return __( 'Die Sitzplätze sind inzwischen vergeben und es sind keine passenden Plätze mehr frei.', 'pass-captain' );
			case 'voucher_released':
				return __( 'Der Gutschein der Bestellung wurde mit Ablauf der Reservierung freigegeben und konnte nicht erneut reserviert werden; die Zahlung deckt nur den Betrag nach Gutscheinabzug.', 'pass-captain' );
		}
		return __( 'Es ist kein Kontingent mehr frei.', 'pass-captain' );
	}

	/**
	 * After a late payment was flagged `needs_review`: audit entry, admin alert and – with the setting
	 * `late_payment_auto_refund` – an automatic full refund through the payment provider (job
	 * `late_payment_refund`, retried until it succeeds).
	 */
	private static function late_payment_unallocated( $order, $reason, $reference ) {
		$auto = self::schedule_late_refund( $order, 'payment_unallocated' );
		Audit::record( 'payment_unallocated', 'order', (int) $order->order_id, array( 'reason' => (string) $reason, 'reference' => sanitize_text_field( $reference ), 'auto_refund' => $auto ) );
		$text = sprintf(
			/* translators: 1: order number, 2: payment reference, 3: reason */
			__( 'Die Zahlung für Bestellung %1$s (Referenz %2$s) ist nach Ablauf der Reservierung eingegangen. Es wurden keine Tickets ausgestellt: %3$s', 'pass-captain' ),
			$order->public_id,
			sanitize_text_field( $reference ),
			self::late_payment_reason( $reason )
		);
		$text .= "\n\n" . ( $auto
			? __( 'Die Zahlung wird automatisch erstattet.', 'pass-captain' )
			: __( 'Bitte manuell klären (Zuteilung erneut versuchen oder erstatten).', 'pass-captain' ) );
		// A337: straight to the order (also listed under Bestellungen with the status filter "Prüfung nötig").
		if ( function_exists( 'admin_url' ) ) { $text .= "\n\n" . Admin\OrdersPage::order_url( (int) $order->order_id ); }
		\PassCaptain\Alerts::raise( 'paid_without_tickets', __( 'Pass Captain: Bezahlte Bestellung ohne Tickets', 'pass-captain' ), $text );
	}

	/**
	 * Queues the automatic refund of a payment that issued no tickets (late payment flagged for review,
	 * payment for a cancelled order) when `late_payment_auto_refund` is enabled and the provider can
	 * refund (not WooCommerce: refunds there happen in WooCommerce).
	 *
	 * @param object $order Order row.
	 * @param string $why   Audit reason.
	 * @return bool Whether a refund was queued.
	 */
	public static function schedule_late_refund( $order, $why ) {
		if ( ! Settings::get( 'late_payment_auto_refund' ) || (int) $order->total_minor < 1 || 'woocommerce' === (string) $order->payment_provider ) { return false; }
		$gateway = Payments\Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! $gateway->supports_refunds() ) { return false; }
		return true === Jobs::enqueue( 'late-payment-refund|' . (int) $order->order_id, 'late_payment_refund', array( 'order_id' => (int) $order->order_id, 'reason' => sanitize_key( $why ) ) );
	}

	/**
	 * Commits a payment booking and tells whether it is durable (A183). The order update is the
	 * booking's first write: when the connection was lost and wpdb reconnected, every statement from
	 * the lost one on ran with autocommit and the earlier ones were rolled back. So the order's
	 * payment status after the commit attempt decides: $status means everything was committed;
	 * anything else means the order update was rolled back (a COMMIT that wpdb re-ran on a new
	 * session commits nothing) – then payment_busy() repairs the counters.
	 */
	private static function commit_probe( $order_id, $status ) {
		global $wpdb;
		if ( false === Transaction::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		$sql = $wpdb->prepare( "SELECT payment_status FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) );
		$now = $wpdb->get_var( $sql );
		if ( '' !== $wpdb->last_error ) { $now = $wpdb->get_var( $sql ); } // Once more on the reconnected session.
		return '' === $wpdb->last_error && $status === $now;
	}

	/**
	 * Staff confirmation of a manual payment (bank transfer, box office).
	 *
	 * Manual payments usually arrive after the short checkout hold has lapsed, so this uses the same
	 * locked capacity re-check as external payments instead of rejecting expired holds: the order is
	 * confirmed if capacity is still available, otherwise flagged `needs_review`.
	 */
	public static function confirm_manual_payment( $order_id ) {
		$order = Orders::get( $order_id );
		if ( $order && 'paid' === $order->payment_status ) {
			// Booked earlier, but its issuance may have failed (database error): complete it.
			CapacityCounters::sync_order_now( (int) $order_id );
			Orders::issue_and_email( (int) $order_id );
		}
		if ( ! $order || 'paid' === $order->payment_status || in_array( $order->status, array( 'refunded', 'cancelled' ), true ) ) {
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ) );
		}
		$result = self::confirm_external_payment( $order_id, 'manual:user-' . get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			Audit::record( 'manual_payment_confirmed', 'order', absint( $order_id ) );
		}
		return $result;
	}
}
