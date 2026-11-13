<?php
/**
 * Ticket exchange ("Umtausch"): one valid ticket becomes a ticket of another occurrence and/or
 * ticket type of the same event (or of the same series), inside the same order.
 *
 * Inventory model (docs/EXCHANGE.md):
 *  - The new ticket always belongs to the original order. One unit moves inside that order from
 *    the old order line to a line of the target type (value carried over), so core capacity, series
 *    pools, reports and later refunds see the order as it is now.
 *  - The capacity of the target is checked under the same locks as a checkout (ticket types, then
 *    occurrences FOR UPDATE, then `wpcap_reserve_capacity` with phase 'exchange' via
 *    Orders::reserve_exchange_capacity()). The unit of the exchanged ticket is released first inside
 *    the same transaction, so an exchange inside a sold-out occurrence works.
 *  - A price difference or fee is paid with a supplementary order (target line with the difference,
 *    fee line with quantity 0) that holds the target unit with a normal hold. Core payment
 *    confirmation (incl. late payment recovery) converts the hold; Service::finalize() then moves the
 *    unit into the original order and issues the ticket. Core never issues tickets for supplementary
 *    orders (`wpcap_issue_order_lines`).
 *  - A cheaper target refunds the difference via Refunds::request_amount() (approval rules apply)
 *    or keeps it as value of the new ticket ("none" policy).
 *
 * Every exchange is a row in wpcap_ticket_exchanges (audit trail, idempotency key, link between
 * the original and the supplementary order).
 */
namespace PassCaptain\Exchange;

use PassCaptain\Audit;
use PassCaptain\CapacityCounters;
use PassCaptain\Mail;
use PassCaptain\Modules;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const PENDING = 'pending_payment';
	const COMPLETED = 'completed';
	const EXPIRED = 'expired';
	const CANCELLED = 'cancelled';
	const REVIEW = 'needs_review';
	const CHANNELS = array( 'admin', 'portal', 'pos', 'api' );

	/** Right to give tickets away (goodwill exchange, box office free method); see GuestLists. */
	const COMP_CAP = 'wpcap_approve_comps';

	/** Supplementary order ids of the current request (order_id => bool). */
	private static $supplement_cache = array();

	public static function register() {
		add_filter( 'wpcap_issue_order_lines', array( __CLASS__, 'filter_issue_lines' ), 10, 2 );
		add_filter( 'wpcap_invoice_items', array( __CLASS__, 'invoice_items' ), 10, 2 );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_order_paid' ), 30, 1 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'on_order_cancelled' ), 30, 1 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'on_order_cancelled' ), 30, 1 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'on_ticket_revoked' ), 30, 2 );
		add_action( 'wpcap_ticket_checked_in', array( __CLASS__, 'on_ticket_checked_in' ), 30, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund_completed' ), 30, 2 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_ticket_exchanges';
	}

	private static function error( $code, $message, $status = 409 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Exchange row as array, or null. */
	public static function get( $exchange_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE exchange_id=%d', absint( $exchange_id ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/** Exchanges of an order (as original or supplementary order), newest first. */
	public static function for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d OR supplementary_order_id=%d ORDER BY exchange_id DESC', absint( $order_id ), absint( $order_id ) ), ARRAY_A );
	}

	/** Exchange whose supplementary order is $order_id, or null. */
	public static function for_supplement( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE supplementary_order_id=%d ORDER BY exchange_id DESC LIMIT 1', absint( $order_id ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/** Whether an order is the supplementary payment order of an exchange. */
	public static function is_supplement( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) { return false; }
		if ( ! isset( self::$supplement_cache[ $order_id ] ) ) {
			self::$supplement_cache[ $order_id ] = null !== self::for_supplement( $order_id );
		}
		return self::$supplement_cache[ $order_id ];
	}

	/* ---------------------------------------------------------------------------------------------
	 * Context, options, quote.
	 * ------------------------------------------------------------------------------------------- */

	/** Ticket with order, type, occurrence and event context (no token). */
	public static function ticket( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.order_id,t.ticket_type_id,t.attendee_email,t.transfer_count,t.refunded,t.status,tt.name AS ticket_name,tt.tax_rate_bp,tt.currency,tt.occurrence_id,o.event_id,o.starts_at,o.timezone,o.location,e.name AS event_name,ord.payment_status,ord.status AS order_status,ord.customer_email,ord.locale,ord.public_id AS order_public_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d",
			absint( $ticket_id )
		) );
	}

	/** Ticket type with occurrence and event context. */
	private static function target( $type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT tt.*,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status AS occurrence_status,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d",
			absint( $type_id )
		) );
	}

	/** Seller of an event for exchange purposes: organizer partner + sales model, or the platform. */
	private static function seller_key( $event_id ) {
		if ( ! class_exists( \PassCaptain\Partners\Repository::class ) ) { return 'platform'; }
		$partner = \PassCaptain\Partners\Repository::partner_of_event( $event_id );
		if ( ! $partner ) { return 'platform'; }
		$partner = is_object( $partner ) ? $partner : \PassCaptain\Partners\Repository::get( (int) $partner );
		return $partner ? 'partner-' . (int) $partner->partner_id . '-' . \PassCaptain\Partners\SalesModel::model_of( $partner ) : 'platform';
	}

	/** Series ids of an occurrence (series module; empty without it). */
	private static function series_of( $occurrence_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_series_occurrences';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { return array(); }
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT series_id FROM $table WHERE occurrence_id=%d", absint( $occurrence_id ) ) ) );
	}

	/** Whether the ticket was already used for admission (status or any successful scan). */
	private static function was_admitted( $ticket ) {
		if ( 'checked_in' === $ticket->status ) { return true; }
		return (bool) Orders::used_ticket_ids( array( (int) $ticket->ticket_id ) );
	}

	/** Completed exchanges in the chain of a ticket (all predecessors and the ticket itself). */
	public static function exchange_count( $ticket_id ) {
		global $wpdb;
		$root = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT root_ticket_id FROM ' . self::table() . " WHERE new_ticket_id=%d AND status='completed' LIMIT 1", absint( $ticket_id ) ) );
		if ( ! $root ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE root_ticket_id=%d AND status='completed'", $root ) );
	}

	/**
	 * Checks whether $ticket may be exchanged into $target on this channel.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate( $ticket, $target, $channel, array $policy ) {
		if ( ! $ticket ) { return self::error( 'wpcap_exchange_ticket_missing', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( empty( $policy['enabled'] ) ) { return self::error( 'wpcap_exchange_disabled', __( 'Für dieses Event ist kein Umtausch möglich.', 'wp-pass-captain' ), 403 ); }
		if ( 'paid' !== $ticket->payment_status ) { return self::error( 'wpcap_exchange_order_unpaid', __( 'Nur Tickets bezahlter Bestellungen können umgetauscht werden.', 'wp-pass-captain' ) ); }
		if ( (int) $ticket->refunded || self::was_admitted( $ticket ) || 'valid' !== $ticket->status ) {
			return self::error( 'wpcap_exchange_ticket_state', __( 'Nur gültige, nicht eingecheckte und nicht erstattete Tickets können umgetauscht werden.', 'wp-pass-captain' ) );
		}
		if ( ! $target || 'active' !== $target->status || 'scheduled' !== $target->occurrence_status || 'published' !== $target->event_status ) {
			return self::error( 'wpcap_exchange_target_unavailable', __( 'Die gewählte Ticketart ist nicht verfügbar.', 'wp-pass-captain' ) );
		}
		if ( (int) $target->ticket_type_id === (int) $ticket->ticket_type_id ) {
			return self::error( 'wpcap_exchange_same_type', __( 'Bitte einen anderen Termin oder eine andere Ticketart wählen.', 'wp-pass-captain' ), 400 );
		}
		if ( strtoupper( (string) $target->currency ) !== strtoupper( (string) $ticket->currency ) ) {
			return self::error( 'wpcap_exchange_currency', __( 'Ein Umtausch ist nur innerhalb derselben Währung möglich.', 'wp-pass-captain' ), 400 );
		}
		// A different VAT rate would change the invoiced service after the fact (invoice correction
		// and a new document needed): refund the ticket and sell the new one instead.
		if ( (int) $target->tax_rate_bp !== (int) $ticket->tax_rate_bp ) {
			return self::error( 'wpcap_exchange_tax_rate', __( 'Ein Umtausch ist nur zwischen Ticketarten mit demselben Steuersatz möglich. Bitte das Ticket erstatten und das neue Ticket neu verkaufen.', 'wp-pass-captain' ), 400 );
		}
		if ( strtotime( $target->starts_at . ' UTC' ) <= time() && 'pos' !== $channel ) {
			return self::error( 'wpcap_exchange_target_started', __( 'Der gewählte Termin hat bereits begonnen.', 'wp-pass-captain' ) );
		}
		$same_event = (int) $target->event_id === (int) $ticket->event_id;
		if ( ! $same_event ) {
			$shared = array_intersect( self::series_of( (int) $ticket->occurrence_id ), self::series_of( (int) $target->occurrence_id ) );
			if ( 'portal' === $channel || empty( $policy['allow_series'] ) || ! $shared ) {
				return self::error( 'wpcap_exchange_other_event', __( 'Ein Umtausch ist nur innerhalb desselben Events möglich.', 'wp-pass-captain' ), 400 );
			}
			// Another organizer or sales model would invoice a service of someone else (mixed sellers):
			// only within the same seller.
			if ( self::seller_key( (int) $ticket->event_id ) !== self::seller_key( (int) $target->event_id ) ) {
				return self::error( 'wpcap_exchange_other_seller', __( 'Ein Umtausch zwischen Events verschiedener Veranstalter oder Verkaufsmodelle ist nicht möglich. Bitte erstatten und neu verkaufen.', 'wp-pass-captain' ), 400 );
			}
		}
		if ( 'portal' === $channel ) {
			// A transferred ticket belongs to its recipient: the original buyer must not swap it (and
			// collect the refund of a downgrade) through the order link. Staff can still exchange it.
			if ( (int) $ticket->transfer_count > 0 ) {
				return self::error( 'wpcap_exchange_transferred', __( 'Dieses Ticket wurde übertragen und kann nur vom Veranstalter umgetauscht werden.', 'wp-pass-captain' ), 403 );
			}
			$now = current_time( 'mysql', true );
			if ( ( $target->sales_start && $target->sales_start > $now ) || ( $target->sales_end && $target->sales_end < $now ) ) {
				return self::error( 'wpcap_exchange_sales_closed', __( 'Der Verkauf für diese Ticketart ist geschlossen.', 'wp-pass-captain' ) );
			}
			if ( empty( $policy['portal_enabled'] ) ) { return self::error( 'wpcap_exchange_portal_disabled', __( 'Ein Umtausch ist hier nicht möglich. Bitte wende dich an den Veranstalter.', 'wp-pass-captain' ), 403 ); }
			$limit = time() + max( 0, (int) $policy['portal_hours'] ) * HOUR_IN_SECONDS;
			if ( strtotime( $ticket->starts_at . ' UTC' ) <= $limit || strtotime( $target->starts_at . ' UTC' ) <= $limit ) {
				/* translators: %d: hours */
				return self::error( 'wpcap_exchange_too_late', sprintf( _n( 'Ein Umtausch ist nur bis %d Stunde vor Beginn möglich.', 'Ein Umtausch ist nur bis %d Stunden vor Beginn möglich.', (int) $policy['portal_hours'], 'wp-pass-captain' ), (int) $policy['portal_hours']) );
			}
			if ( self::exchange_count( (int) $ticket->ticket_id ) >= max( 0, (int) $policy['portal_max_per_ticket'] ) ) {
				return self::error( 'wpcap_exchange_limit', __( 'Dieses Ticket wurde bereits so oft wie erlaubt umgetauscht.', 'wp-pass-captain' ) );
			}
		}
		return true;
	}

	/**
	 * Possible targets for a ticket (active types of scheduled occurrences of the same event, or of
	 * the same series for staff channels), with current price, difference and availability.
	 *
	 * @return array[] {ticket_type_id, occurrence_id, event_name, ticket_name, starts_at, timezone, price_minor, difference_minor, available (int|null), seated (bool)}
	 */
	public static function options( $ticket_id, $channel = 'admin' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket = self::ticket( $ticket_id );
		if ( ! $ticket ) { return array(); }
		$policy = Policy::for_event( (int) $ticket->event_id );
		$event_ids = array( (int) $ticket->event_id );
		if ( 'portal' !== $channel && ! empty( $policy['allow_series'] ) ) {
			foreach ( self::series_of( (int) $ticket->occurrence_id ) as $series_id ) {
				$event_ids = array_merge( $event_ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$p}wpcap_series_occurrences so INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=so.occurrence_id WHERE so.series_id=%d", $series_id ) ) ) );
			}
		}
		$in = implode( ',', array_unique( array_map( 'absint', $event_ids ) ) );
		$rows = $wpdb->get_results( "SELECT tt.ticket_type_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id IN ($in) AND tt.status='active' AND o.status='scheduled' ORDER BY o.starts_at,tt.ticket_type_id LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$value = self::ticket_value( $ticket );
		$out = array();
		foreach ( $rows as $row ) {
			$target = self::target( (int) $row->ticket_type_id );
			if ( true !== self::validate( $ticket, $target, $channel, $policy ) ) { continue; }
			$price = Orders::unit_price( $target, null, 1, array( 'phase' => 'display', 'source' => 'exchange' ) );
			$out[] = array(
				'ticket_type_id' => (int) $target->ticket_type_id,
				'occurrence_id' => (int) $target->occurrence_id,
				'event_name' => (string) $target->event_name,
				'ticket_name' => (string) $target->name,
				'starts_at' => (string) $target->starts_at,
				'timezone' => (string) $target->timezone,
				'price_minor' => $price,
				'currency' => (string) $target->currency,
				'difference_minor' => $price - $value,
				'available' => Orders::availability( (int) $target->ticket_type_id ),
				'seated' => self::is_seated( (int) $target->occurrence_id, (int) $target->ticket_type_id ),
			);
		}
		return $out;
	}

	/**
	 * Value of a ticket: what it was paid with (its line share after order discounts, including a
	 * multi-purpose voucher payment), or for a ticket created by an exchange the value recorded with
	 * that exchange.
	 *
	 * The line share is taken from the lines as paid (OrderLines): units an exchange moved into or
	 * out of the same ticket type do not change the value of the other tickets of the order.
	 */
	public static function ticket_value( $ticket ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$recorded = $wpdb->get_var( $wpdb->prepare( 'SELECT new_value_minor FROM ' . self::table() . " WHERE new_ticket_id=%d AND status='completed' ORDER BY exchange_id DESC LIMIT 1", (int) $ticket->ticket_id ) );
		if ( null !== $recorded ) { return (int) $recorded; }
		$line = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(' . \PassCaptain\OrderLines::paid( 'line_total_minor', '' ) . '),0) AS gross,COALESCE(SUM(' . \PassCaptain\OrderLines::paid( 'quantity', '' ) . "),0) AS qty FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d", (int) $ticket->order_id, (int) $ticket->ticket_type_id ) );
		$order = Orders::get( (int) $ticket->order_id );
		if ( ! $line || ! $order || (int) $line->qty < 1 ) { return 0; }
		$unit = (int) round( (int) $line->gross / (int) $line->qty );
		// Full consideration: a multi-purpose voucher is a means of payment, not a discount.
		$paid = \PassCaptain\Integrations\Vouchers\Checkout::gross_minor( $order );
		$subtotal = (int) $order->subtotal_minor ?: $paid;
		return $subtotal > 0 ? (int) round( $unit * min( $paid, $subtotal ) / $subtotal ) : 0;
	}

	/**
	 * Money of an exchange.
	 *
	 * @param int   $value  Value of the old ticket.
	 * @param int   $price  Current unit price of the target.
	 * @param array $policy Effective policy (fee_minor, refund_policy).
	 * @param array $args   waive (bool), refund_policy ('refund'|'none' override).
	 * @return array {old_value_minor, new_price_minor, difference_minor, fee_minor, refund_minor, due_minor, new_value_minor, waived, refund_policy, supplement_minor}
	 */
	public static function calculate( $value, $price, array $policy, array $args = array() ) {
		$value = max( 0, (int) $value );
		$price = max( 0, (int) $price );
		$waived = ! empty( $args['waive'] );
		$refund_policy = in_array( $args['refund_policy'] ?? '', array( 'refund', 'none' ), true ) ? $args['refund_policy'] : ( 'none' === ( $policy['refund_policy'] ?? 'refund' ) ? 'none' : 'refund' );
		$diff = $price - $value;
		$fee = $waived ? 0 : max( 0, (int) ( $policy['fee_minor'] ?? 0 ) );
		$supplement = 0;
		$refund = 0;
		if ( $diff > 0 ) {
			$supplement = $waived ? 0 : $diff;
		} elseif ( $diff < 0 && 'refund' === $refund_policy ) {
			// A cheaper target: the fee is withheld from the refund (never more than the difference).
			$fee = min( $fee, -$diff );
			$refund = -$diff - $fee;
		}
		$due = $supplement + ( ( $diff < 0 && 'refund' === $refund_policy ) ? 0 : $fee );
		return array(
			'old_value_minor' => $value,
			'new_price_minor' => $price,
			'difference_minor' => $diff,
			'fee_minor' => $fee,
			'refund_minor' => $refund,
			'due_minor' => $due,
			'supplement_minor' => $supplement,
			'new_value_minor' => max( 0, $value + $supplement - ( $diff < 0 && 'refund' === $refund_policy ? -$diff : 0 ) ),
			'waived' => $waived,
			'refund_policy' => $refund_policy,
		);
	}

	/** Non-binding quote for a ticket and target (display); the request recomputes under locks. */
	public static function quote( $ticket_id, $target_type_id, array $args = array() ) {
		$ticket = self::ticket( $ticket_id );
		$target = self::target( $target_type_id );
		$channel = in_array( (string) ( $args['channel'] ?? '' ), self::CHANNELS, true ) ? (string) $args['channel'] : 'admin';
		$policy = $ticket ? Policy::for_event( (int) $ticket->event_id ) : Policy::global_policy();
		$valid = self::validate( $ticket, $target, $channel, $policy );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$price = Orders::unit_price( $target, null, 1, array( 'phase' => 'display', 'source' => 'exchange' ) );
		return self::calculate( self::ticket_value( $ticket ), $price, $policy, $args ) + array( 'currency' => (string) $target->currency );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Seats (seating module, optional).
	 * ------------------------------------------------------------------------------------------- */

	private static function seating_enabled() {
		return class_exists( \PassCaptain\Seating\Reservations::class ) && in_array( \PassCaptain\Modules\Seating::class, Modules::classes(), true );
	}

	/** Whether the target type has seats on a linked seat map. */
	public static function is_seated( $occurrence_id, $type_id ) {
		if ( ! self::seating_enabled() || ! \PassCaptain\Seating\Layouts::link( $occurrence_id ) ) { return false; }
		return ! empty( \PassCaptain\Seating\Layouts::type_zones( $occurrence_id )[ $type_id ] );
	}

	/** Free seats for a seated target type (admin pick): seat_id => label. */
	public static function free_seats( $occurrence_id, $type_id ) {
		if ( ! self::is_seated( $occurrence_id, $type_id ) ) { return array(); }
		$map = \PassCaptain\Seating\Reservations::availability( $occurrence_id );
		$zones = \PassCaptain\Seating\Layouts::type_zones( $occurrence_id )[ $type_id ] ?? array();
		$out = array();
		foreach ( (array) ( $map['seats'] ?? array() ) as $seat ) {
			if ( 'free' === $seat['status'] && ( in_array( $seat['zone'], $zones, true ) || in_array( $seat['category'], $zones, true ) ) ) {
				$out[ (int) $seat['id'] ] = (string) $seat['label'];
			}
		}
		return $out;
	}

	/**
	 * Claims a seat for the target inside the caller's transaction (best available or $seat_id).
	 *
	 * @throws \RuntimeException seat_* codes.
	 */
	private static function claim_seat( $order_id, $target, $seat_id ) {
		if ( ! self::seating_enabled() ) {
			if ( $seat_id ) { throw new \RuntimeException( 'seat_zone_mismatch' ); }
			return;
		}
		$selected = array( array( 'type' => $target, 'event_id' => (int) $target->event_id, 'quantity' => 1 ) );
		$payload = $seat_id ? array( \PassCaptain\Seating\Reservations::PAYLOAD_KEY => array( (int) $target->ticket_type_id => array( (int) $seat_id ) ) ) : array();
		\PassCaptain\Seating\Reservations::on_order_creating( (int) $order_id, $selected, $payload, array( 'seat_auto_assign' => true ) );
	}

	/** Seat label of a ticket ('' without seat). */
	private static function seat_label( $ticket_id ) {
		return (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket_id, array() );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Exchange.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Exchanges a ticket. Idempotent per idempotency_key; a repeated request for a ticket with a
	 * pending exchange into the same target returns that exchange.
	 *
	 * @param int   $ticket_id      Ticket to exchange.
	 * @param int   $target_type_id Target ticket type.
	 * @param array $args           channel (admin|portal|pos|api), idempotency_key, seat_id (seated
	 *                              target; else best seat), waive (bool, staff only: goodwill without
	 *                              supplement/fee), refund_policy ('refund'|'none', staff only),
	 *                              provider (payment gateway for the supplement; portal),
	 *                              pos_method (cash|card|free|invoice: box office, paid at once),
	 *                              hold_minutes, note.
	 * @return array|\WP_Error Exchange row plus 'payment' (pending supplement) / 'warning'.
	 */
	public static function request( $ticket_id, $target_type_id, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$channel = in_array( (string) ( $args['channel'] ?? '' ), self::CHANNELS, true ) ? (string) $args['channel'] : 'admin';
		if ( 'portal' === $channel ) {
			// Goodwill and policy overrides are staff decisions.
			unset( $args['waive'], $args['refund_policy'] );
		}
		// A free upgrade (goodwill) or a box office "free" supplement is a comp: same right as the
		// guest list approval (four-eyes principle there), not just order management.
		if ( ( ! empty( $args['waive'] ) || ( 'pos' === $channel && 'free' === ( $args['pos_method'] ?? '' ) ) ) && ! current_user_can( self::COMP_CAP ) ) {
			return self::error( 'wpcap_exchange_waive_forbidden', __( 'Für einen Umtausch ohne Aufpreis (Kulanz/Freikarte) fehlt die Berechtigung zur Freigabe von Freikarten.', 'wp-pass-captain' ), 403 );
		}
		$key = sanitize_text_field( (string) ( $args['idempotency_key'] ?? '' ) );
		$key_hash = '' !== $key ? hash( 'sha256', 'exchange|' . absint( $ticket_id ) . '|' . $key ) : null;
		if ( $key_hash ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE idempotency_key=%s', $key_hash ), ARRAY_A );
			if ( $existing ) { return $existing + array( 'reused' => true ); }
		}
		$ticket = self::ticket( $ticket_id );
		$target = self::target( $target_type_id );
		$policy = $ticket ? Policy::for_event( (int) $ticket->event_id ) : Policy::global_policy();
		$valid = self::validate( $ticket, $target, $channel, $policy );
		if ( is_wp_error( $valid ) ) {
			// A double submit after the exchange completed must not report an error.
			$done = $ticket ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE old_ticket_id=%d AND to_type_id=%d AND status IN ('completed','pending_payment') ORDER BY exchange_id DESC LIMIT 1", (int) $ticket->ticket_id, absint( $target_type_id ) ), ARRAY_A ) : null;
			if ( $done && 'completed' === $done['status'] && 'valid' !== $ticket->status && empty( $key_hash ) ) { return $done + array( 'reused' => true ); }
			return $valid;
		}
		$seat_id = absint( $args['seat_id'] ?? 0 );
		$now = current_time( 'mysql', true );
		// Read before the lock phase (the transaction may only use locking reads until all locks are held).
		$value = self::ticket_value( $ticket );
		// Double submit of a pending upgrade (the target may be held by that very exchange).
		$open = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE old_ticket_id=%d AND status=%s ORDER BY exchange_id DESC LIMIT 1', (int) $ticket->ticket_id, self::PENDING ), ARRAY_A );
		if ( $open && self::supplement_open( (int) $open['supplementary_order_id'], $now ) ) {
			if ( (int) $open['to_type_id'] === (int) $target->ticket_type_id ) { return $open + array( 'reused' => true ); }
			return self::map_error( 'exchange_pending' );
		}

		$wpdb->query( 'START TRANSACTION' );
		$error = null;
		$stale_supplement = 0;
		$pending_result = null;
		$completed = null;
		try {
			// Lock phase (locking reads only, so the snapshot of the capacity check is taken after every
			// lock is held): order, ticket, ticket types by id, occurrences by id, the old order line,
			// then module resources in Orders::reserve_exchange_capacity(). The ticket lock serialises
			// all exchanges of one ticket.
			$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $ticket->order_id ) );
			$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", (int) $ticket->ticket_id ) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			if ( ! $order || 'paid' !== $order->payment_status || ! $locked || 'valid' !== $locked->status || (int) $locked->refunded ) { throw new \RuntimeException( 'ticket_state' ); }
			// Re-check under the lock: a transfer may have committed after validate().
			if ( 'portal' === $channel && (int) $locked->transfer_count > 0 ) { throw new \RuntimeException( 'transferred' ); }
			$type_ids = array( (int) $ticket->ticket_type_id, (int) $target->ticket_type_id );
			sort( $type_ids, SORT_NUMERIC );
			$types = array();
			foreach ( $type_ids as $type_id ) {
				$types[ $type_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $type_id ) );
			}
			if ( in_array( null, $types, true ) ) { throw new \RuntimeException( '' !== $wpdb->last_error ? 'db_busy' : 'target_unavailable' ); }
			$occ_ids = array_unique( array( (int) $types[ (int) $ticket->ticket_type_id ]->occurrence_id, (int) $types[ (int) $target->ticket_type_id ]->occurrence_id ) );
			sort( $occ_ids, SORT_NUMERIC );
			$occurrences = array();
			foreach ( $occ_ids as $occ_id ) {
				$occurrences[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occ_id ) );
			}
			if ( in_array( null, $occurrences, true ) ) { throw new \RuntimeException( '' !== $wpdb->last_error ? 'db_busy' : 'target_unavailable' ); }
			$target_type = $types[ (int) $target->ticket_type_id ];
			$target_occ = $occurrences[ (int) $target_type->occurrence_id ];
			if ( ! $target_type || 'active' !== $target_type->status || ! $target_occ || 'scheduled' !== $target_occ->status ) { throw new \RuntimeException( 'target_unavailable' ); }
			$old_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d AND quantity>0 ORDER BY item_id LIMIT 1 FOR UPDATE", (int) $order->order_id, (int) $ticket->ticket_type_id ) );
			if ( ! $old_item ) { throw new \RuntimeException( 'ticket_state' ); }

			// Release the exchanged unit first, so the check below does not count it.
			$share = self::unit_share( $old_item );
			self::release_unit( $old_item, $share );
			$capacity = Orders::reserve_exchange_capacity( $target_type, $target_occ, 1, array( 'now' => $now, 'source' => $channel, 'exchange' => array( 'ticket_id' => (int) $ticket->ticket_id, 'order_id' => (int) $order->order_id, 'from_type_id' => (int) $ticket->ticket_type_id ) ) );
			if ( $capacity ) { $error = $capacity; throw new \RuntimeException( $capacity->get_error_code() ); }
			if ( false === Orders::transaction_alive() ) { throw new \RuntimeException( 'db_busy' ); }
			// Under the ticket lock: a concurrent request with the same key or a pending exchange won.
			if ( $key_hash ) {
				$again = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE idempotency_key=%s', $key_hash ), ARRAY_A );
				if ( $again ) { $wpdb->query( 'ROLLBACK' ); return $again + array( 'reused' => true ); }
			}
			$pending = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE old_ticket_id=%d AND status=%s ORDER BY exchange_id DESC LIMIT 1', (int) $ticket->ticket_id, self::PENDING ), ARRAY_A );
			if ( $pending && self::supplement_open( (int) $pending['supplementary_order_id'], $now ) ) {
				if ( (int) $pending['to_type_id'] === (int) $target->ticket_type_id ) { $wpdb->query( 'ROLLBACK' ); return $pending + array( 'reused' => true ); }
				throw new \RuntimeException( 'exchange_pending' );
			}
			if ( $pending ) {
				// Hold lapsed but not cleaned up yet: this request supersedes it.
				$wpdb->update( self::table(), array( 'status' => self::EXPIRED, 'pending_ticket_id' => null, 'updated_at' => $now ), array( 'exchange_id' => (int) $pending['exchange_id'] ) );
				$stale_supplement = (int) $pending['supplementary_order_id'];
			}

			$price = Orders::unit_price( $target_type, $target_occ, 1, array( 'now' => $now, 'source' => $channel, 'phase' => 'exchange' ) );
			$money = self::calculate( $value, $price, $policy, $args );
			/** Changes the money of an exchange (difference, fee, refund) before it is stored. */
			$money = (array) apply_filters( 'wpcap_exchange_money', $money, $ticket, $target_type, $args );
			// Self-service refunds only for money the platform itself collected: partner sales were paid
			// to the partner, box office sales at the till – there the organizer/box office refunds.
			if ( 'portal' === $channel && (int) $money['refund_minor'] > 0 && ! self::platform_collected( $order ) ) {
				throw new \RuntimeException( 'refund_offline' );
			}
			$row = array(
				'idempotency_key' => $key_hash,
				'order_id' => (int) $order->order_id,
				'old_ticket_id' => (int) $ticket->ticket_id,
				'new_ticket_id' => 0,
				'root_ticket_id' => self::root_of( (int) $ticket->ticket_id ),
				'from_type_id' => (int) $ticket->ticket_type_id,
				'to_type_id' => (int) $target_type->ticket_type_id,
				'channel' => $channel,
				'currency' => (string) $order->currency,
				'old_value_minor' => (int) $money['old_value_minor'],
				'new_price_minor' => (int) $money['new_price_minor'],
				'difference_minor' => (int) $money['difference_minor'],
				'fee_minor' => (int) $money['fee_minor'],
				'refund_minor' => (int) $money['refund_minor'],
				'due_minor' => (int) $money['due_minor'],
				'new_value_minor' => (int) $money['new_value_minor'],
				'waived' => $money['waived'] ? 1 : 0,
				'refund_policy' => (string) $money['refund_policy'],
				'seat_id' => $seat_id,
				'note' => mb_substr( sanitize_text_field( (string) ( $args['note'] ?? '' ) ), 0, 255 ),
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			);

			if ( (int) $money['due_minor'] > 0 ) {
				// Supplementary payment: restore the old line (it stays sold until payment) and hold the target.
				self::restore_unit( $old_item );
				$pending_result = self::create_supplement( $order, $ticket, $target_type, $target_occ, $money, $row, $args, $channel, $seat_id );
			} else {
				$completed = self::complete_locked( $order, $ticket, $target_type, $target_occ, $old_item, $share, $money, $row, $seat_id, false );
			}
			// Capacity counters follow the moved/held units in this transaction (types and occurrences are locked).
			if ( false === CapacityCounters::sync_order( (int) $order->order_id, array( (int) $ticket->ticket_type_id, (int) $target_type->ticket_type_id ) ) ) { throw new \RuntimeException( 'db_busy' ); }
			if ( $pending_result && false === CapacityCounters::sync_order( (int) $pending_result['order_id'] ) ) { throw new \RuntimeException( 'db_busy' ); }
			if ( false === Orders::transaction_alive() ) { throw new \RuntimeException( 'db_busy' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'db_busy' ); }
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::map_error( $e->getMessage(), $error );
		}

		if ( $stale_supplement ) {
			$stale = Orders::get( $stale_supplement );
			if ( $stale && 'pending' === $stale->payment_status ) { Orders::cancel_order( $stale_supplement, 'exchange_superseded' ); }
		}
		if ( $pending_result ) {
			return self::after_supplement( $pending_result, $args, $channel );
		}
		return self::after_complete( $completed );
	}

	/** Maps an internal failure code to a customer-facing error. */
	private static function map_error( $code, $error = null ) {
		if ( $error instanceof \WP_Error ) {
			$data = (array) $error->get_error_data();
			return new \WP_Error( 'wpcap_exchange_failed', $error->get_error_message(), array( 'status' => (int) ( $data['status'] ?? 409 ), 'reason' => $error->get_error_code(), 'retryable' => ! empty( $data['retryable'] ) ) );
		}
		$messages = apply_filters( 'wpcap_checkout_error_messages', array(
			'ticket_state' => __( 'Nur gültige, nicht eingecheckte und nicht erstattete Tickets können umgetauscht werden.', 'wp-pass-captain' ),
			'exchange_pending' => __( 'Für dieses Ticket läuft bereits ein Umtausch, der auf Zahlung wartet.', 'wp-pass-captain' ),
			'target_unavailable' => __( 'Die gewählte Ticketart ist nicht verfügbar.', 'wp-pass-captain' ),
			'transferred' => __( 'Dieses Ticket wurde übertragen und kann nur vom Veranstalter umgetauscht werden.', 'wp-pass-captain' ),
			'refund_offline' => __( 'Bei diesem Umtausch würde Geld erstattet, das bei der Verkaufsstelle (Kasse oder Vertriebspartner) bezahlt wurde. Bitte wende dich für den Umtausch an die Verkaufsstelle oder den Veranstalter.', 'wp-pass-captain' ),
			'db_busy' => __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'wp-pass-captain' ),
			'insert_failed' => __( 'Der Umtausch konnte nicht gespeichert werden.', 'wp-pass-captain' ),
			'billing_invalid' => __( 'Die Rechnungsdaten sind unvollständig: Vorname, Nachname, Straße, PLZ, Ort und Land angeben.', 'wp-pass-captain' ),
			'billing_required' => __( 'Für diesen Aufpreis braucht die Rechnung Name und Anschrift. Bitte Rechnungsdaten angeben oder den Veranstalter kontaktieren.', 'wp-pass-captain' ),
		) );
		$message = $messages[ $code ] ?? __( 'Der Umtausch konnte nicht durchgeführt werden.', 'wp-pass-captain' );
		return new \WP_Error( 'wpcap_exchange_failed', $message, array( 'status' => 'db_busy' === $code ? 503 : 409, 'reason' => (string) $code, 'retryable' => 'db_busy' === $code ) );
	}

	/** First ticket of an exchange chain. */
	private static function root_of( $ticket_id ) {
		global $wpdb;
		$root = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT root_ticket_id FROM ' . self::table() . " WHERE new_ticket_id=%d AND status='completed' LIMIT 1", absint( $ticket_id ) ) );
		return $root ? $root : absint( $ticket_id );
	}

	/** Whether a supplementary order still holds its target (pending with an active hold). */
	private static function supplement_open( $order_id, $now ) {
		global $wpdb;
		$order = Orders::get( $order_id );
		if ( ! $order || 'pending' !== $order->payment_status ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at>%s LIMIT 1", absint( $order_id ), $now ) );
	}

	/** Gross and VAT share of one unit of an order line. */
	private static function unit_share( $item ) {
		$qty = max( 1, (int) $item->quantity );
		if ( 1 === $qty ) { return array( (int) $item->line_total_minor, (int) $item->tax_minor ); }
		return array( (int) round( (int) $item->line_total_minor / $qty ), (int) round( (int) $item->tax_minor / $qty ) );
	}

	/** Takes one unit (and its money share) out of an order line. */
	private static function release_unit( $item, array $share ) {
		global $wpdb;
		// Accounting keeps the paid values of the line (OrderLines).
		if ( ! \PassCaptain\OrderLines::freeze( (int) $item->item_id ) ) { throw new \RuntimeException( 'db_busy' ); }
		$ok = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}wpcap_order_items SET refunded_quantity=LEAST(refunded_quantity,quantity-1),quantity=quantity-1,line_total_minor=GREATEST(CAST(line_total_minor AS SIGNED)-%d,0),tax_minor=GREATEST(CAST(tax_minor AS SIGNED)-%d,0) WHERE item_id=%d AND quantity>0",
			(int) $share[0],
			(int) $share[1],
			(int) $item->item_id
		) );
		if ( 1 !== (int) $ok ) { throw new \RuntimeException( 'db_busy' ); }
	}

	/** Puts an order line back to the given state. */
	private static function restore_unit( $item ) {
		global $wpdb;
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_order_items SET quantity=%d,refunded_quantity=%d,line_total_minor=%d,tax_minor=%d WHERE item_id=%d", (int) $item->quantity, (int) $item->refunded_quantity, (int) $item->line_total_minor, (int) $item->tax_minor, (int) $item->item_id ) );
		if ( false === $ok ) { throw new \RuntimeException( 'db_busy' ); }
	}

	/** Adds one unit of the target type with the carried value to the original order. */
	private static function add_unit( $order_id, $target_type, $carried_gross, $price ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rate = (int) $target_type->tax_rate_bp;
		$tax = Orders::gross_tax( $carried_gross, $rate );
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1 FOR UPDATE", (int) $order_id, (int) $target_type->ticket_type_id ) );
		if ( $item ) {
			if ( ! \PassCaptain\OrderLines::freeze( (int) $item->item_id ) ) { throw new \RuntimeException( 'db_busy' ); }
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET quantity=quantity+1,line_total_minor=line_total_minor+%d,tax_minor=tax_minor+%d WHERE item_id=%d", (int) $carried_gross, $tax, (int) $item->item_id ) );
		} else {
			$ok = $wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => (int) $order_id, 'ticket_type_id' => (int) $target_type->ticket_type_id, 'quantity' => 1, 'unit_price_minor' => (int) $price, 'line_total_minor' => (int) $carried_gross, 'tax_rate_bp' => $rate, 'tax_minor' => $tax, 'refunded_quantity' => 0 ) + ( \PassCaptain\OrderLines::ready() ? array( 'orig_quantity' => 0, 'orig_line_total_minor' => 0, 'orig_tax_minor' => 0 ) : array() ) );
		}
		if ( ! $ok ) { throw new \RuntimeException( 'db_busy' ); }
	}

	/**
	 * Inside the transaction (all rows locked, old unit released): moves the unit, revokes the old
	 * ticket, issues the new one and stores/updates the exchange row.
	 *
	 * @param bool $from_supplement Finalisation after payment ($row is the stored exchange).
	 * @return array Completion data for after_complete().
	 */
	private static function complete_locked( $order, $ticket, $target_type, $target_occ, $old_item, array $share, array $money, array $row, $seat_id, $from_supplement ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		// Value carried into the target line (pre-discount gross, like the order lines): the old share
		// minus what is refunded now. A paid supplement stays on the supplementary order.
		$order_total = \PassCaptain\Integrations\Vouchers\Checkout::gross_minor( $order ); // Incl. a multi-purpose voucher payment.
		$order_subtotal = (int) $order->subtotal_minor ?: $order_total;
		$refund_gross = (int) $money['refund_minor'] + ( (int) $money['difference_minor'] < 0 && 'refund' === $money['refund_policy'] ? (int) $money['fee_minor'] : 0 );
		$refund_pre = $order_total > 0 ? (int) round( $refund_gross * $order_subtotal / $order_total ) : $refund_gross;
		$carried = max( 0, (int) $share[0] - $refund_pre );
		self::add_unit( (int) $order->order_id, $target_type, $carried, (int) $money['new_price_minor'] );

		if ( $from_supplement ) {
			// The supplement's target unit now lives in the original order.
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=quantity WHERE order_id=%d AND ticket_type_id=%d", (int) $row['supplementary_order_id'], (int) $target_type->ticket_type_id ) );
			if ( self::seating_enabled() ) {
				// Seat held/sold for the supplementary order moves to the original order.
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . \PassCaptain\Seating\Layouts::table( 'claims' ) . ' SET order_id=%d,updated_at=%s WHERE order_id=%d AND ticket_type_id=%d AND ticket_id=0 AND active_key IS NOT NULL', (int) $order->order_id, $now, (int) $row['supplementary_order_id'], (int) $target_type->ticket_type_id ) );
			}
		} else {
			self::claim_seat( (int) $order->order_id, (object) array_merge( (array) $target_type, array( 'event_id' => (int) $target_occ->event_id ) ), $seat_id );
		}

		$revoked = Orders::revoke_ids( array( (int) $ticket->ticket_id ) );
		if ( 1 !== count( $revoked ) ) { throw new \RuntimeException( 'ticket_state' ); }

		$token = bin2hex( random_bytes( 32 ) );
		$inserted = $wpdb->insert( "{$p}wpcap_tickets", array(
			'public_id' => bin2hex( random_bytes( 16 ) ),
			'order_id' => (int) $order->order_id,
			'ticket_type_id' => (int) $target_type->ticket_type_id,
			'token_hash' => hash( 'sha256', $token ),
			'attendee_email' => (string) $ticket->attendee_email,
			'transfer_count' => (int) $ticket->transfer_count,
			'status' => 'valid',
			'issued_at' => $now,
		) );
		$new_id = (int) $wpdb->insert_id;
		if ( ! $inserted || ! $new_id ) { throw new \RuntimeException( 'insert_failed' ); }
		// The holder stays the holder (name/e-mail).
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) SELECT %d,buyer_email,attendee_email,attendee_name,status,%s FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", $new_id, $now, (int) $ticket->ticket_id ) );
		// Answers to participant fields carry over where the target type asks the same field (same key).
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) SELECT %d,nf.field_id,a.answer,%s,%s FROM {$p}wpcap_participant_answers a INNER JOIN {$p}wpcap_participant_fields of ON of.field_id=a.field_id INNER JOIN {$p}wpcap_participant_fields nf ON nf.field_key=of.field_key AND nf.ticket_type_id=%d WHERE a.ticket_id=%d",
			$new_id, $now, $now, (int) $target_type->ticket_type_id, (int) $ticket->ticket_id
		) );
		// Granted consents follow their answers (same field key), logged with source "exchange".
		\PassCaptain\Consents::carry_over( (int) $ticket->ticket_id, $new_id, (int) $target_type->ticket_type_id );
		$line = (object) array(
			'order_id' => (int) $order->order_id,
			'ticket_type_id' => (int) $target_type->ticket_type_id,
			'occurrence_id' => (int) $target_occ->occurrence_id,
			'event_id' => (int) $target_occ->event_id,
			'quantity' => 1,
			'ticket_name' => (string) $target_type->name,
			'starts_at' => (string) $target_occ->starts_at,
			'timezone' => (string) $target_occ->timezone,
			'location' => (string) $target_occ->location,
		);
		/** Same hook as core issuance (inside the transaction), e.g. seat assignment. Do not store the token. */
		do_action( 'wpcap_ticket_created', $new_id, $order, $line, 0 );

		$row['new_ticket_id'] = $new_id;
		$row['status'] = self::COMPLETED;
		$row['completed_at'] = $now;
		$row['updated_at'] = $now;
		if ( $from_supplement ) {
			$exchange_id = (int) $row['exchange_id'];
			$ok = $wpdb->update( self::table(), array( 'new_ticket_id' => $new_id, 'status' => self::COMPLETED, 'pending_ticket_id' => null, 'completed_at' => $now, 'updated_at' => $now ), array( 'exchange_id' => $exchange_id ) );
			if ( false === $ok ) { throw new \RuntimeException( 'insert_failed' ); }
		} else {
			$row['pending_ticket_id'] = null;
			if ( ! $wpdb->insert( self::table(), $row ) ) { throw new \RuntimeException( 'insert_failed' ); }
			$exchange_id = (int) $wpdb->insert_id;
			$row['exchange_id'] = $exchange_id;
		}
		return array(
			'exchange_id' => $exchange_id,
			'row' => $row,
			'order' => $order,
			'old' => $ticket,
			'type' => $target_type,
			'occurrence' => $target_occ,
			'new_ticket_id' => $new_id,
			'token' => $token,
			'from_supplement' => $from_supplement,
		);
	}

	/** After commit: hooks, refund, mail, audit. */
	private static function after_complete( array $done ) {
		global $wpdb;
		$exchange_id = (int) $done['exchange_id'];
		$order = $done['order'];
		$old = $done['old'];
		$new_id = (int) $done['new_ticket_id'];
		self::$supplement_cache = array();
		Orders::fire_revoked( array( (int) $old->ticket_id ), 'exchange' );
		do_action( 'wpcap_capacity_released', (int) $order->order_id, 'exchange', array( 'ticket_type_id' => (int) $old->ticket_type_id, 'quantity' => 1, 'exchange_id' => $exchange_id ) );
		do_action( 'wpcap_tickets_issued', (int) $order->order_id, 1 );

		$warning = '';
		$price_note = array( 'none', '' );
		$row = self::get( $exchange_id );
		$money = static function ( $minor ) use ( $order ) { return number_format_i18n( $minor / 100, 2 ) . ' ' . $order->currency; };
		if ( (int) $row['refund_minor'] > 0 && 'woocommerce' === (string) $order->payment_provider ) {
			// WooCommerce collected the money: the difference is refunded there (refund in WooCommerce →
			// the adapter books it). Tracked as refund_status "woocommerce" with a note on the WC order.
			$wpdb->update( self::table(), array( 'refund_status' => 'woocommerce', 'updated_at' => current_time( 'mysql', true ) ), array( 'exchange_id' => $exchange_id ) );
			Audit::record( 'ticket_exchange_refund_external', 'order', (int) $order->order_id, array( 'exchange_id' => $exchange_id, 'amount_minor' => (int) $row['refund_minor'], 'provider' => 'woocommerce' ) );
			/** The difference of an exchange must be refunded outside Pass Captain (e.g. in WooCommerce). */
			do_action( 'wpcap_exchange_refund_external', $exchange_id, (int) $order->order_id, (int) $row['refund_minor'], (string) $order->currency );
			$price_note = array( 'refund_pending', $money( (int) $row['refund_minor'] ) );
		} elseif ( (int) $row['refund_minor'] > 0 ) {
			$channel = (string) $row['channel'];
			$refund = Refunds::request_amount(
				(int) $order->order_id,
				(int) $row['refund_minor'],
				/* translators: 1: old ticket type, 2: new ticket type */
				sprintf( __( 'Umtausch %1$s → %2$s', 'wp-pass-captain' ), $old->ticket_name, $done['type']->name ),
				'exchange-' . $exchange_id,
				array( 'system' => in_array( $channel, array( 'portal', 'api' ), true ), 'tax_minor' => Orders::gross_tax( (int) $row['refund_minor'], (int) $old->tax_rate_bp ) )
			);
			if ( is_wp_error( $refund ) ) {
				$warning = sprintf( /* translators: %s: error */ __( 'Der Umtausch ist erfolgt, die Erstattung der Differenz konnte aber nicht ausgelöst werden: %s', 'wp-pass-captain' ), $refund->get_error_message() );
				$wpdb->update( self::table(), array( 'refund_status' => 'error', 'updated_at' => current_time( 'mysql', true ) ), array( 'exchange_id' => $exchange_id ) );
				Audit::record( 'ticket_exchange_refund_failed', 'order', (int) $order->order_id, array( 'exchange_id' => $exchange_id, 'error' => $refund->get_error_code() ) );
				$price_note = array( 'refund_pending', $money( (int) $row['refund_minor'] ) );
			} else {
				$wpdb->update( self::table(), array( 'refund_id' => (int) $refund['refund_id'], 'refund_status' => (string) $refund['status'], 'updated_at' => current_time( 'mysql', true ) ), array( 'exchange_id' => $exchange_id ) );
				$price_note = array( 'completed' === $refund['status'] ? 'refund' : 'refund_pending', $money( (int) $row['refund_minor'] ) );
			}
		} elseif ( (int) $row['due_minor'] > 0 ) {
			$price_note = array( 'paid', $money( (int) $row['due_minor'] ) );
		} elseif ( (int) $row['waived'] && (int) $row['difference_minor'] > 0 ) {
			$price_note = array( 'waived', '' );
		}

		$mailed = self::mail( $done, $price_note );
		Audit::record( 'ticket_exchanged', 'ticket', (int) $old->ticket_id, array(
			'exchange_id' => $exchange_id,
			'order_id' => (int) $order->order_id,
			'new_ticket_id' => $new_id,
			'from_type_id' => (int) $row['from_type_id'],
			'to_type_id' => (int) $row['to_type_id'],
			'channel' => (string) $row['channel'],
			'difference_minor' => (int) $row['difference_minor'],
			'fee_minor' => (int) $row['fee_minor'],
			'refund_minor' => (int) $row['refund_minor'],
			'due_minor' => (int) $row['due_minor'],
			'waived' => (bool) $row['waived'],
			'supplementary_order_id' => (int) $row['supplementary_order_id'],
			'mailed' => $mailed,
		) );
		/**
		 * A ticket was exchanged: the old ticket is revoked (reason 'exchange'), the new one issued.
		 *
		 * @param int   $old_ticket_id Exchanged (now revoked) ticket.
		 * @param int   $new_ticket_id New ticket (same order).
		 * @param int   $exchange_id   Row in wpcap_ticket_exchanges.
		 * @param array $exchange      Exchange row.
		 */
		do_action( 'wpcap_ticket_exchanged', (int) $old->ticket_id, $new_id, $exchange_id, self::get( $exchange_id ) );
		$result = self::get( $exchange_id );
		if ( '' !== $warning ) { $result['warning'] = $warning; }
		return $result;
	}

	/** Sends the exchange mail with the new ticket link to the holder (attendee, else buyer). */
	private static function mail( array $done, array $price_note ) {
		$order = $done['order'];
		$old = $done['old'];
		$type = $done['type'];
		$occ = $done['occurrence'];
		$new_id = (int) $done['new_ticket_id'];
		$recipient = is_email( (string) $old->attendee_email ) ? (string) $old->attendee_email : (string) $order->customer_email;
		if ( ! is_email( $recipient ) ) { return false; }
		$seat = self::seat_label( $new_id );
		// Help code of the new ticket (also listed by the wpcap_ticket_code_mail_section filter below).
		\PassCaptain\HelpCode::for_ticket( $new_id, (string) $done['token'] );
		$kind = $price_note[0];
		$amount = $price_note[1];
		$vars = array(
			'order_id' => (string) $order->public_id,
			'event_name' => self::event_name( (int) $occ->event_id ),
			'ticket_name' => (string) $type->name,
			'event_date' => Orders::format_start( $occ->starts_at, $occ->timezone ),
			'event_location' => (string) $occ->location,
			'old_event_name' => (string) $old->event_name,
			'old_ticket_name' => (string) $old->ticket_name,
			'old_event_date' => Orders::format_start( $old->starts_at, $old->timezone ),
			'seat' => $seat,
			'seat_note' => static function () use ( $seat ) { return '' !== $seat ? __( 'Sitzplatz:', 'wp-pass-captain' ) . ' ' . $seat . "\n" : ''; },
			'price_note' => static function () use ( $kind, $amount ) { return Mail\Types::exchange_price_note( $kind, $amount ); },
			'ticket_link' => Mail\Mailer::link( add_query_arg( 'wpcap_ticket', $done['token'], home_url( '/' ) ) ),
			'ticket_extras' => Mail\Blocks::extra( (string) apply_filters( 'wpcap_ticket_code_mail_section', '', $new_id ) ),
		);
		return (bool) Mail\Mailer::send( 'ticket_exchanged', $recipient, $vars, array(), (string) $order->locale );
	}

	private static function event_name( $event_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $event_id ) ) );
	}

	/**
	 * Inside the request transaction: supplementary order (target line with the difference, fee line
	 * with quantity 0), hold on the target, seat claim and pending exchange row.
	 *
	 * @return array Data for after_supplement().
	 */
	private static function create_supplement( $order, $ticket, $target_type, $target_occ, array $money, array $row, array $args, $channel, $seat_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$provider = self::supplement_provider( $order, $args, $channel );
		if ( isset( $args['hold_minutes'] ) ) {
			$hold = max( 1, absint( $args['hold_minutes'] ) );
		} elseif ( 'manual' === $provider ) {
			// Never beyond the start of the target (no cutoff here: the old ticket stays valid meanwhile).
			$hold = Orders::manual_hold_until( strtotime( $target_occ->starts_at . ' UTC' ) ) ?? max( 1, (int) floor( ( strtotime( $target_occ->starts_at . ' UTC' ) - time() ) / 60 ) );
		} else {
			$hold = max( 1, (int) Settings::get( 'hold_minutes' ) );
		}
		$rate = (int) $target_type->tax_rate_bp;
		$supplement = (int) $money['supplement_minor'];
		$fee = (int) $money['fee_minor'];
		$total = (int) $money['due_minor'];
		// The supplement is invoiced on its own: above the billing threshold it needs the buyer's name
		// and address – from the original order, or passed with the exchange ($args['billing']).
		$billing_json = ! empty( $order->billing ) ? (string) $order->billing : '';
		if ( '' === $billing_json && is_array( $args['billing'] ?? null ) ) {
			$given = \PassCaptain\Billing::validate( array( 'enabled' => true ) + $args['billing'] );
			if ( is_wp_error( $given ) ) { throw new \RuntimeException( 'billing_invalid' ); }
			if ( $given && ! \PassCaptain\Billing::is_empty( $given ) ) { $billing_json = (string) wp_json_encode( $given ); }
		}
		if ( '' === $billing_json && \PassCaptain\Billing::required_for( $total, (string) $order->currency ) ) {
			throw new \RuntimeException( 'billing_required' );
		}
		$tax_line = Orders::gross_tax( $supplement, $rate );
		$tax_fee = Orders::gross_tax( $fee, $rate );
		$access_token = bin2hex( random_bytes( 32 ) );
		$public_id = bin2hex( random_bytes( 16 ) );
		$snapshot = array(
			'currency' => (string) $order->currency,
			'subtotal_minor' => $total,
			'discount_minor' => 0,
			'total_minor' => $total,
			'campaigns' => array(),
			'exchange' => array( 'original_order' => (string) $order->public_id, 'old_ticket' => (string) $ticket->public_id, 'from_type_id' => (int) $ticket->ticket_type_id, 'to_type_id' => (int) $target_type->ticket_type_id, 'difference_minor' => (int) $money['difference_minor'], 'fee_minor' => $fee ),
		);
		$order_row = array(
			'public_id' => $public_id,
			'idempotency_key' => hash( 'sha256', 'exchange-supplement|' . ( $row['idempotency_key'] ?? '' ) . '|' . wp_generate_uuid4() ),
			'customer_email' => (string) $order->customer_email,
			'access_token_hash' => hash( 'sha256', $access_token ),
			'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
			'status' => 'pending_payment',
			'payment_status' => 'pending',
			'currency' => (string) $order->currency,
			'total_minor' => $total,
			'subtotal_minor' => $total,
			'discount_minor' => 0,
			'price_snapshot' => wp_json_encode( $snapshot ),
			'tax_minor' => $tax_line + $tax_fee,
			'payment_provider' => $provider,
			'terms_version' => '',
			'locale' => (string) $order->locale,
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( '' !== $billing_json ) { $order_row['billing'] = $billing_json; }
		if ( ! $wpdb->insert( "{$p}wpcap_orders", $order_row ) ) { throw new \RuntimeException( 'insert_failed' ); }
		$supp_id = (int) $wpdb->insert_id;
		// Target line: one unit of the target (holds/counts capacity until the unit moves to the original order).
		$ok = $wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $supp_id, 'ticket_type_id' => (int) $target_type->ticket_type_id, 'quantity' => 1, 'unit_price_minor' => $supplement, 'line_total_minor' => $supplement, 'tax_rate_bp' => $rate, 'tax_minor' => $tax_line, 'refunded_quantity' => 0 ) );
		if ( $ok && $fee > 0 ) {
			// Fee line: quantity 0 (no inventory); invoices show it as "Umtauschgebühr" (wpcap_invoice_items).
			$ok = $wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $supp_id, 'ticket_type_id' => (int) $target_type->ticket_type_id, 'quantity' => 0, 'unit_price_minor' => $fee, 'line_total_minor' => $fee, 'tax_rate_bp' => $rate, 'tax_minor' => $tax_fee, 'refunded_quantity' => 0 ) );
		}
		if ( ! $ok ) { throw new \RuntimeException( 'insert_failed' ); }
		$ok = $wpdb->insert( "{$p}wpcap_reservations", array( 'order_id' => $supp_id, 'ticket_type_id' => (int) $target_type->ticket_type_id, 'quantity' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $hold * MINUTE_IN_SECONDS ), 'status' => 'active' ) );
		if ( ! $ok ) { throw new \RuntimeException( 'insert_failed' ); }
		self::claim_seat( $supp_id, (object) array_merge( (array) $target_type, array( 'event_id' => (int) $target_occ->event_id ) ), $seat_id );
		$row['status'] = self::PENDING;
		$row['pending_ticket_id'] = (int) $ticket->ticket_id;
		$row['supplementary_order_id'] = $supp_id;
		if ( ! $wpdb->insert( self::table(), $row ) ) { throw new \RuntimeException( 'exchange_pending' ); }
		$exchange_id = (int) $wpdb->insert_id;
		return array( 'exchange_id' => $exchange_id, 'order_id' => $supp_id, 'public_id' => $public_id, 'access_token' => $access_token, 'provider' => $provider, 'hold_minutes' => $hold, 'original' => $order, 'ticket' => $ticket, 'type' => $target_type );
	}

	/** Payment provider of a supplementary order. */
	/**
	 * Whether the platform itself received the order's payment (so it can refund it): not for partner
	 * sales (paid to the partner), box office sales (till), WooCommerce orders (refunded in
	 * WooCommerce) and comps. Filter wpcap_exchange_platform_collected.
	 */
	public static function platform_collected( $order ) {
		$provider = (string) ( $order->payment_provider ?? '' );
		$own = ! in_array( $provider, array( 'partner', 'woocommerce', 'comp' ), true ) && 0 !== strpos( $provider, 'pos_' );
		return (bool) apply_filters( 'wpcap_exchange_platform_collected', $own, $order );
	}

	private static function supplement_provider( $order, array $args, $channel ) {
		if ( 'pos' === $channel ) {
			$method = sanitize_key( (string) ( $args['pos_method'] ?? 'cash' ) );
			return 'pos_' . ( in_array( $method, array( 'cash', 'card', 'free', 'invoice' ), true ) ? $method : 'cash' );
		}
		$enabled = (array) Settings::get( 'enabled_providers' );
		$wanted = sanitize_key( (string) ( $args['provider'] ?? '' ) );
		if ( '' !== $wanted && in_array( $wanted, $enabled, true ) && Registry::get( $wanted ) ) { return $wanted; }
		if ( 'portal' === $channel && in_array( (string) $order->payment_provider, $enabled, true ) && Registry::get( (string) $order->payment_provider ) ) { return (string) $order->payment_provider; }
		return 'manual';
	}

	/** After the supplement commit: payment start (portal), immediate confirmation (box office), mails. */
	private static function after_supplement( array $data, array $args, $channel ) {
		$supp_id = (int) $data['order_id'];
		self::$supplement_cache[ $supp_id ] = true;
		Audit::record( 'ticket_exchange_requested', 'ticket', (int) $data['ticket']->ticket_id, array( 'exchange_id' => (int) $data['exchange_id'], 'supplementary_order_id' => $supp_id, 'to_type_id' => (int) $data['type']->ticket_type_id, 'channel' => $channel ) );
		Audit::record( 'order_created', 'order', $supp_id, array( 'total_minor' => (int) Orders::get( $supp_id )->total_minor, 'currency' => (string) Orders::get( $supp_id )->currency, 'source' => 'exchange' ) );
		$payment = array();
		if ( 'pos' === $channel ) {
			$confirmed = Orders::confirm_external_payment( $supp_id, 'pos:' . sanitize_key( (string) ( $args['pos_method'] ?? 'cash' ) ) . ':exchange' );
			if ( is_wp_error( $confirmed ) ) {
				$row = self::get( (int) $data['exchange_id'] );
				return $row + array( 'warning' => $confirmed->get_error_message() );
			}
			return self::get( (int) $data['exchange_id'] );
		}
		$supp = Orders::get( $supp_id );
		$gateway = Registry::get( $data['provider'] );
		if ( $gateway && 'portal' === $channel ) {
			$items = array( array( 'name' => sprintf( /* translators: 1: old type, 2: new type */ __( 'Umtausch %1$s → %2$s', 'wp-pass-captain' ), $data['ticket']->ticket_name, $data['type']->name ), 'event_name' => (string) $data['ticket']->event_name, 'quantity' => 1, 'unit_price_minor' => (int) $supp->total_minor, 'line_total_minor' => (int) $supp->total_minor ) );
			$started = $gateway->create_payment( (array) $supp, $items );
			if ( is_wp_error( $started ) ) {
				Orders::cancel_order( $supp_id, 'payment_start_failed' );
				return new \WP_Error( 'wpcap_exchange_payment', $started->get_error_message(), array( 'status' => 502 ) );
			}
			if ( ! empty( $started['provider_reference'] ) ) { Orders::set_provider_reference( $supp_id, $started['provider_reference'] ); }
			$payment = array_intersect_key( $started, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
		}
		// The personal link of the supplementary order carries the payment details (e.g. bank transfer).
		Orders::send_order_access_link( (string) $supp->customer_email, (string) $supp->public_id, (string) $data['access_token'], (string) $supp->locale );
		$row = self::get( (int) $data['exchange_id'] );
		$row['payment'] = $payment + array( 'order_public_id' => (string) $supp->public_id, 'amount_minor' => (int) $supp->total_minor, 'currency' => (string) $supp->currency, 'hold_minutes' => (int) $data['hold_minutes'] );
		return $row;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Supplement lifecycle.
	 * ------------------------------------------------------------------------------------------- */

	/** wpcap_issue_order_lines: core does not issue tickets for supplementary orders. */
	public static function filter_issue_lines( $lines, $order = null ) {
		$order_id = is_object( $order ) ? (int) $order->order_id : 0;
		return self::is_supplement( $order_id ) ? array() : $lines;
	}

	/** wpcap_order_paid: a paid supplement completes its exchange. */
	public static function on_order_paid( $order_id ) {
		if ( ! self::is_supplement( $order_id ) ) { return; }
		$exchange = self::for_supplement( $order_id );
		if ( $exchange && in_array( $exchange['status'], array( self::PENDING, self::EXPIRED, self::CANCELLED ), true ) ) {
			self::finalize( (int) $exchange['exchange_id'] );
		}
	}

	/**
	 * Completes an exchange after its supplementary order was paid. Idempotent. When the old ticket
	 * can no longer be exchanged (checked in, revoked, refunded meanwhile) the exchange is flagged
	 * needs_review and the operator is notified (the paid supplement keeps its unit until refunded).
	 *
	 * @return array|\WP_Error Exchange row.
	 */
	public static function finalize( $exchange_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$exchange = self::get( $exchange_id );
		if ( ! $exchange ) { return self::error( 'wpcap_exchange_missing', __( 'Umtausch nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( self::COMPLETED === $exchange['status'] || self::REVIEW === $exchange['status'] ) { return $exchange; }
		$ticket = self::ticket( (int) $exchange['old_ticket_id'] );
		$done = null;
		$review = '';
		$wpdb->query( 'START TRANSACTION' );
		try {
			// Same lock order as request(): orders, ticket, exchange, ticket types, occurrences. The
			// supplementary order is locked here too (ascending order ids), before the types: a concurrent
			// cancellation of it locks order → types (CapacityCounters::sync_order_now()), so locking it
			// only later inside sync_order() could deadlock.
			$order_ids = array_values( array_unique( array_filter( array( (int) $exchange['order_id'], (int) $exchange['supplementary_order_id'] ) ) ) );
			sort( $order_ids );
			$orders = array();
			foreach ( $order_ids as $lock_id ) {
				$orders[ $lock_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $lock_id ) );
			}
			$order = $orders[ (int) $exchange['order_id'] ] ?? null;
			$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", (int) $exchange['old_ticket_id'] ) );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE exchange_id=%d FOR UPDATE', absint( $exchange_id ) ), ARRAY_A );
			$supp = $wpdb->get_row( $wpdb->prepare( "SELECT payment_status FROM {$p}wpcap_orders WHERE order_id=%d", (int) $exchange['supplementary_order_id'] ) );
			if ( ! $row || self::COMPLETED === $row['status'] || self::REVIEW === $row['status'] || ! $supp || 'paid' !== $supp->payment_status ) {
				$wpdb->query( 'COMMIT' );
				return $row ? $row : $exchange;
			}
			if ( ! $order || 'paid' !== $order->payment_status || ! $locked || 'valid' !== $locked->status || (int) $locked->refunded || ! $ticket || self::was_admitted( $ticket ) ) {
				$review = 'ticket_state';
			}
			$old_item = $review ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d AND quantity>0 ORDER BY item_id LIMIT 1 FOR UPDATE", (int) $order->order_id, (int) $exchange['from_type_id'] ) );
			if ( ! $review && ! $old_item ) { $review = 'order_line_missing'; }
			if ( $review ) {
				$wpdb->update( self::table(), array( 'status' => self::REVIEW, 'pending_ticket_id' => null, 'note' => $review, 'updated_at' => current_time( 'mysql', true ) ), array( 'exchange_id' => absint( $exchange_id ) ) );
				$wpdb->query( 'COMMIT' );
			} else {
				$type_ids = array( (int) $exchange['from_type_id'], (int) $exchange['to_type_id'] );
				sort( $type_ids, SORT_NUMERIC );
				$types = array();
				foreach ( $type_ids as $type_id ) {
					$types[ $type_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $type_id ) );
				}
				$target_type = $types[ (int) $exchange['to_type_id'] ];
				// Both occurrences by id (the old unit leaves the source occurrence's counters).
				$occ_ids = array_unique( array_filter( array( (int) ( $types[ (int) $exchange['from_type_id'] ]->occurrence_id ?? 0 ), (int) $target_type->occurrence_id ) ) );
				sort( $occ_ids, SORT_NUMERIC );
				$locked_occ = array();
				foreach ( $occ_ids as $occ_id ) {
					$locked_occ[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occ_id ) );
				}
				$target_occ = $locked_occ[ (int) $target_type->occurrence_id ] ?? null;
				$share = self::unit_share( $old_item );
				// Net zero for capacity: the paid supplement counts the target until its unit moves here.
				self::release_unit( $old_item, $share );
				$money = array(
					'refund_minor' => 0,
					'difference_minor' => (int) $row['difference_minor'],
					'refund_policy' => (string) $row['refund_policy'],
					'fee_minor' => (int) $row['fee_minor'],
					'new_price_minor' => (int) $row['new_price_minor'],
				);
				$done = self::complete_locked( $order, $ticket, $target_type, $target_occ, $old_item, $share, $money, $row, (int) $row['seat_id'], true );
				if ( false === CapacityCounters::sync_order( (int) $order->order_id, array( (int) $exchange['from_type_id'], (int) $exchange['to_type_id'] ) ) || false === CapacityCounters::sync_order( (int) $exchange['supplementary_order_id'] ) ) { throw new \RuntimeException( 'db_busy' ); }
				if ( false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'db_busy' ); }
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			Audit::record( 'ticket_exchange_finalize_failed', 'order', (int) $exchange['order_id'], array( 'exchange_id' => absint( $exchange_id ), 'error' => $e->getMessage() ) );
			return self::map_error( $e->getMessage() );
		}
		if ( $review ) {
			Audit::record( 'ticket_exchange_review', 'order', (int) $exchange['order_id'], array( 'exchange_id' => absint( $exchange_id ), 'reason' => $review, 'supplementary_order_id' => (int) $exchange['supplementary_order_id'] ) );
			wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Umtausch bezahlt, aber nicht durchführbar', 'wp-pass-captain' ), sprintf( /* translators: 1: exchange id, 2: order id */ __( 'Der Aufpreis für Umtausch #%1$d (Bestellung #%2$d) wurde bezahlt, das ursprüngliche Ticket ist aber nicht mehr gültig (eingecheckt, widerrufen oder erstattet). Bitte manuell klären und den Aufpreis gegebenenfalls erstatten.', 'wp-pass-captain' ), absint( $exchange_id ), (int) $exchange['order_id'] ) );
			return self::get( $exchange_id );
		}
		return self::after_complete( $done );
	}

	/**
	 * wpcap_order_cancelled / wpcap_order_refunded: an expired or cancelled supplement ends its
	 * pending exchange (the hold and seat are released by core/seating); a cancelled or refunded
	 * original order cancels its pending supplements.
	 */
	public static function on_order_cancelled( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$exchange = self::for_supplement( $order_id );
		if ( $exchange && self::PENDING === $exchange['status'] ) {
			$order = Orders::get( $order_id );
			if ( $order && 'paid' !== $order->payment_status ) {
				$status = 'expired' === $order->status ? self::EXPIRED : self::CANCELLED;
				$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET status=%s,pending_ticket_id=NULL,updated_at=%s WHERE exchange_id=%d AND status=%s', $status, current_time( 'mysql', true ), (int) $exchange['exchange_id'], self::PENDING ) );
				if ( $changed ) {
					Audit::record( 'ticket_exchange_' . $status, 'ticket', (int) $exchange['old_ticket_id'], array( 'exchange_id' => (int) $exchange['exchange_id'], 'supplementary_order_id' => $order_id ) );
				}
			}
			return;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT supplementary_order_id FROM ' . self::table() . ' WHERE order_id=%d AND status=%s', $order_id, self::PENDING ) ) as $pending ) {
			Orders::cancel_order( (int) $pending->supplementary_order_id, 'exchange_original_closed' );
		}
	}

	/** A pending exchange ends when its old ticket is revoked or used for admission. */
	public static function on_ticket_revoked( $ticket_id, $reason = '' ) {
		if ( 'exchange' === (string) $reason ) { return; }
		self::cancel_pending_for_ticket( $ticket_id, 'ticket_revoked' );
	}

	public static function on_ticket_checked_in( $ticket_id ) {
		self::cancel_pending_for_ticket( $ticket_id, 'ticket_checked_in' );
	}

	private static function cancel_pending_for_ticket( $ticket_id, $reason ) {
		global $wpdb;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT supplementary_order_id FROM ' . self::table() . ' WHERE old_ticket_id=%d AND status=%s', absint( $ticket_id ), self::PENDING ) ) as $pending ) {
			$supp = Orders::get( (int) $pending->supplementary_order_id );
			if ( $supp && 'pending' === $supp->payment_status ) { Orders::cancel_order( (int) $pending->supplementary_order_id, 'exchange_' . $reason ); }
		}
	}

	/**
	 * wpcap_refund_completed: when a ticket that came from an exchange is refunded, the supplements
	 * paid for it (whole exchange chain) are refunded too (amount-only refunds of the supplementary
	 * orders; approval rules apply). The exchange fee is part of the supplement and refunded with it
	 * only when `wpcap_exchange_refund_fee` returns true.
	 */
	public static function on_refund_completed( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$refund = Refunds::get( $refund_id );
		if ( ! $refund || self::is_supplement( (int) $refund['order_id'] ) ) { return; }
		foreach ( array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) as $ticket_id ) {
			$guard = 0;
			while ( $ticket_id && $guard++ < 50 ) {
				$exchange = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE new_ticket_id=%d AND status='completed' LIMIT 1", $ticket_id ), ARRAY_A );
				if ( ! $exchange ) { break; }
				$supp = (int) $exchange['supplementary_order_id'] ? Orders::get( (int) $exchange['supplementary_order_id'] ) : null;
				if ( $supp && 'paid' === $supp->payment_status ) {
					$fee = apply_filters( 'wpcap_exchange_refund_fee', false, $exchange ) ? 0 : (int) $exchange['fee_minor'];
					$amount = min( (int) $supp->total_minor - (int) $supp->refunded_minor, max( 0, (int) $exchange['due_minor'] - $fee ) );
					if ( $amount > 0 ) {
						$result = Refunds::request_amount( (int) $supp->order_id, $amount, __( 'Erstattung Aufpreis (Umtausch)', 'wp-pass-captain' ), 'exchange-supplement-' . (int) $exchange['exchange_id'], array( 'system' => true, 'tax_minor' => Orders::gross_tax( $amount, (int) $wpdb->get_var( $wpdb->prepare( "SELECT tax_rate_bp FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d ORDER BY item_id LIMIT 1", (int) $supp->order_id ) ) ) ) );
						Audit::record( 'ticket_exchange_supplement_refund', 'order', (int) $supp->order_id, array( 'exchange_id' => (int) $exchange['exchange_id'], 'refund_id' => absint( $refund_id ), 'result' => is_wp_error( $result ) ? $result->get_error_code() : (string) $result['status'] ) );
					}
				}
				$ticket_id = (int) $exchange['old_ticket_id'];
			}
		}
	}

	/** wpcap_invoice_items: readable lines for supplementary orders (difference and fee). */
	public static function invoice_items( $items, $order = null ) {
		$order_id = is_object( $order ) ? (int) $order->order_id : 0;
		if ( ! self::is_supplement( $order_id ) ) { return $items; }
		$exchange = self::for_supplement( $order_id );
		$from = $exchange ? self::type_name( (int) $exchange['from_type_id'] ) : '';
		foreach ( (array) $items as $index => $item ) {
			if ( ! is_object( $item ) ) { continue; }
			$item = clone $item;
			if ( 0 === (int) $item->quantity ) {
				$item->quantity = 1;
				$item->name = __( 'Umtauschgebühr', 'wp-pass-captain' );
			} else {
				/* translators: 1: previous ticket type, 2: new ticket type */
				$item->name = sprintf( __( 'Aufpreis Umtausch %1$s → %2$s', 'wp-pass-captain' ), $from, (string) $item->name );
			}
			$items[ $index ] = $item;
		}
		return $items;
	}

	public static function type_name( $type_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", absint( $type_id ) ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Box office.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Exchange at the box office (public API for the box office module): staff with
	 * wpcap_manage_orders for the event; a supplement or fee is collected at the counter and
	 * confirmed at once (provider pos_<method>), so the new ticket is issued right away.
	 *
	 * @param int|string $ticket         Ticket id or public id.
	 * @param int        $target_type_id Target ticket type.
	 * @param array      $args           pos_method (cash|card|free|invoice), idempotency_key, seat_id, waive, refund_policy, note.
	 * @return array|\WP_Error Exchange row.
	 */
	public static function box_office_exchange( $ticket, $target_type_id, array $args = array() ) {
		$ticket_id = self::resolve_ticket( $ticket );
		$row = $ticket_id ? self::ticket( $ticket_id ) : null;
		if ( ! $row ) { return self::error( 'wpcap_exchange_ticket_missing', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $row->event_id, 'wpcap_manage_orders' ) ) {
			return self::error( 'wpcap_exchange_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), 403 );
		}
		$args['channel'] = 'pos';
		$method = sanitize_key( (string) ( $args['pos_method'] ?? 'cash' ) );
		$method = in_array( $method, array( 'cash', 'card', 'free', 'invoice' ), true ) ? $method : 'cash';
		$args['pos_method'] = $method;
		$pos = class_exists( \PassCaptain\BoxOffice\Service::class );
		// Money at the counter is booked into the cashier's open shift (receipt, Z report).
		if ( $pos && \PassCaptain\BoxOffice\Shifts::needs_shift( $method ) && ! \PassCaptain\BoxOffice\Service::open_session_for( get_current_user_id() ) ) {
			return self::error( 'wpcap_pos_no_session', __( 'Für einen Umtausch mit Aufpreis an der Kasse muss eine Kassenschicht geöffnet sein.', 'wp-pass-captain' ), 409 );
		}
		$result = self::request( $ticket_id, $target_type_id, $args );
		if ( is_wp_error( $result ) || ! $pos || empty( $result['supplementary_order_id'] ) ) { return $result; }
		$supp = Orders::get( (int) $result['supplementary_order_id'] );
		if ( ! $supp || 'paid' !== $supp->payment_status ) { return $result; }
		$target = self::target( (int) $target_type_id );
		$booked = \PassCaptain\BoxOffice\Service::book_order( (int) $supp->order_id, $method, 'exchange-' . (int) $result['exchange_id'], $target ? (int) $target->occurrence_id : 0 );
		if ( is_wp_error( $booked ) ) {
			Audit::record( 'ticket_exchange_pos_unbooked', 'order', (int) $supp->order_id, array( 'exchange_id' => (int) $result['exchange_id'], 'error' => $booked->get_error_code() ) );
			return $result + array( 'warning' => $booked->get_error_message() );
		}
		if ( is_array( $booked ) ) { $result['receipt_number'] = (string) $booked['sale']->receipt_number; }
		return $result;
	}

	/** Ticket id from an id or a 32-character public id. */
	public static function resolve_ticket( $ticket ) {
		global $wpdb;
		if ( is_string( $ticket ) && preg_match( '/^[a-f0-9]{32}$/', $ticket ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s", $ticket ) );
		}
		return absint( $ticket );
	}
}
