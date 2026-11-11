<?php
/**
 * Reverse direction of the Voucher Captain bridge: a redeemed voucher is turned into Pass Captain tickets.
 *
 * Voucher Captain (or any other voucher system) gets this service through the versioned filter
 * `wp_pass_captain_ticket_service_v1` and calls issue_tickets() after it has reserved the voucher. The service
 * creates a free order ("paid by voucher") through the normal checkout path, so capacity, holds, attendee
 * fields and ticket mails are exactly those of a regular order. The caller's idempotency key makes every call
 * repeatable: the same key always answers with the same order and tickets, and a failed call can simply be
 * retried. Only after issue_tickets() confirmed the tickets does the voucher system commit the redemption.
 *
 * Errors are WP_Error with data `retryable` (true for temporary failures such as a busy database).
 */
namespace PassCaptain\Integrations\Vouchers;

use PassCaptain\Audit;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketService {
	const FILTER = 'wp_pass_captain_ticket_service_v1';
	const VERSION = 1;
	/** Key namespace of voucher orders (keyed hash, so a public checkout can never claim such a key). */
	const NAMESPACE_KEY = 'voucher';
	const PROVIDER = 'voucher';

	public static function register() {
		add_filter( self::FILTER, array( __CLASS__, 'service' ), 10, 2 );
	}

	/** Filter callback: the service for contract version 1 (another implementation registered earlier wins). */
	public static function service( $service = null, $version = self::VERSION ) {
		if ( null !== $service || self::VERSION !== (int) $version ) { return $service; }
		return new self();
	}

	/**
	 * Issues tickets for a redeemed voucher.
	 *
	 * @param array  $source          Where the tickets come from, stored for the audit trail: provider (e.g.
	 *                                "voucher-captain"), voucher_id, redemption_key, code_hint (last characters).
	 * @param string $idempotency_key Stable key of this exchange (e.g. "vcap-voucher-123"); max. 191 characters.
	 * @param array  $target          ticket_type_id (int) and quantity (1–10).
	 * @param array  $recipient       email (required), name, attendees (list as in the public checkout, for
	 *                                ticket types with required attendee fields).
	 * @return array|\WP_Error order_id, order_public_id, ticket_ids (public ids), status "issued", reused (bool).
	 */
	public function issue_tickets( array $source, $idempotency_key, array $target, array $recipient ) {
		$key = sanitize_text_field( (string) $idempotency_key );
		$type_id = absint( $target['ticket_type_id'] ?? 0 );
		$quantity = absint( $target['quantity'] ?? 1 );
		$email = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 191 || ! $type_id || $quantity < 1 || $quantity > 10 || ! is_email( $email ) ) {
			return self::error( 'wpcap_ticket_request_invalid', __( 'Ticketart, Anzahl, Schlüssel und eine gültige E-Mail-Adresse sind erforderlich.', 'wp-pass-captain' ), 400 );
		}
		$existing = self::order_for_key( $key );
		if ( $existing ) {
			return self::complete( $existing, $source, true );
		}
		$order = Orders::create(
			array(
				'email' => $email,
				'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $quantity ) ),
				'attendees' => is_array( $recipient['attendees'] ?? null ) ? $recipient['attendees'] : array(),
				'idempotency_key' => $key,
			),
			array(
				'external' => true,
				'payment_provider' => self::PROVIDER,
				'key_namespace' => self::NAMESPACE_KEY,
				'source' => 'voucher',
				'skip_rate_limit' => true,
				'unit_prices' => array( $type_id => 0 ),
				'line_totals' => array( $type_id => 0 ),
				'line_taxes' => array( $type_id => 0 ),
			)
		);
		if ( is_wp_error( $order ) ) { return $order; }
		$row = Orders::get( (int) $order['order_id'] );
		return $row ? self::complete( $row, $source, ! empty( $order['reused'] ) ) : self::error( 'wpcap_ticket_issue_failed', __( 'Die Tickets konnten nicht ausgestellt werden.', 'wp-pass-captain' ), 503, true );
	}

	/** Current state of an exchange (null when the key is unknown). */
	public function get( $idempotency_key ) {
		$order = self::order_for_key( sanitize_text_field( (string) $idempotency_key ) );
		return $order ? self::data( $order, false ) : null;
	}

	/**
	 * Revokes the tickets of an exchange (e.g. the voucher purchase was refunded). Checked-in tickets stay valid.
	 * Idempotent; unknown keys are answered with true.
	 */
	public function revoke_tickets( $idempotency_key, $reason = '' ) {
		$order = self::order_for_key( sanitize_text_field( (string) $idempotency_key ) );
		if ( ! $order ) { return true; }
		Orders::cancel_order( (int) $order->order_id, '' !== (string) $reason ? (string) $reason : 'voucher_revoked' );
		return true;
	}

	/**
	 * Ticket types that can be issued for vouchers (upcoming occurrences, active types), for admin selects.
	 *
	 * @return array<int, array{ticket_type_id:int,label:string,event:string,starts_at:string,currency:string,price_minor:int}>
	 */
	public function targets() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.name,tt.currency,tt.price_minor,e.name AS event_name,o.starts_at,o.timezone FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.status='active' AND o.status<>'cancelled' AND o.starts_at>=%s ORDER BY o.starts_at,e.name,tt.ticket_type_id LIMIT 500", current_time( 'mysql', true ) ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			try { $tz = new \DateTimeZone( $row->timezone ?: 'UTC' ); } catch ( \Exception $e ) { $tz = new \DateTimeZone( 'UTC' ); }
			$local = ( new \DateTimeImmutable( $row->starts_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$out[] = array(
				'ticket_type_id' => (int) $row->ticket_type_id,
				/* translators: 1: event, 2: date, 3: ticket type */
				'label' => sprintf( __( '%1$s – %2$s – %3$s', 'wp-pass-captain' ), $row->event_name, $local->format( 'd.m.Y H:i' ), $row->name ),
				'event' => (string) $row->event_name,
				'starts_at' => (string) $row->starts_at,
				'currency' => (string) $row->currency,
				'price_minor' => (int) $row->price_minor,
			);
		}
		return $out;
	}

	/** Confirms the free order (issues and mails the tickets) and answers with the order data. */
	private static function complete( $order, array $source, $reused ) {
		if ( in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
			return self::error( 'wpcap_ticket_exchange_revoked', __( 'Die Tickets dieser Einlösung wurden bereits widerrufen.', 'wp-pass-captain' ), 409 );
		}
		if ( 'paid' !== $order->payment_status ) {
			$reference = 'voucher:' . sanitize_text_field( (string) ( $source['redemption_key'] ?? ( $source['voucher_id'] ?? '' ) ) );
			$confirmed = Orders::confirm_external_payment( (int) $order->order_id, $reference );
			if ( is_wp_error( $confirmed ) ) {
				$data = (array) $confirmed->get_error_data();
				$retryable = ! empty( $data['retryable'] ) || 503 === (int) ( $data['status'] ?? 0 );
				return new \WP_Error( $confirmed->get_error_code(), $confirmed->get_error_message(), array( 'status' => $retryable ? 503 : 409, 'retryable' => $retryable ) );
			}
			Orders::set_provider_reference( (int) $order->order_id, mb_substr( $reference, 0, 191 ) );
			Audit::record(
				'voucher_tickets_issued',
				'order',
				(int) $order->order_id,
				array(
					'provider' => sanitize_key( (string) ( $source['provider'] ?? '' ) ),
					'voucher_id' => absint( $source['voucher_id'] ?? 0 ),
					'redemption_key' => sanitize_text_field( (string) ( $source['redemption_key'] ?? '' ) ),
					'code_hint' => mb_substr( sanitize_text_field( (string) ( $source['code_hint'] ?? '' ) ), -4 ),
				)
			);
			$order = Orders::get( (int) $order->order_id );
		} else {
			// Tickets of a paid order are issued idempotently (fills gaps after an interrupted issuance).
			Orders::issue_and_email( (int) $order->order_id );
		}
		return self::data( $order, $reused );
	}

	private static function data( $order, $reused ) {
		$tickets = Orders::tickets_for_order( (int) $order->order_id );
		$valid = array_values( array_filter( (array) $tickets, static function ( $t ) { return 'valid' === $t->status || 'checked_in' === $t->status; } ) );
		$revoked = in_array( $order->status, array( 'cancelled', 'refunded' ), true );
		return array(
			'order_id' => (int) $order->order_id,
			'order_public_id' => (string) $order->public_id,
			'ticket_ids' => array_map( static function ( $t ) { return (string) $t->public_id; }, $valid ),
			'status' => $revoked ? 'revoked' : ( 'paid' === $order->payment_status ? 'issued' : 'pending' ),
			'reused' => (bool) $reused,
		);
	}

	private static function order_for_key( $key ) {
		global $wpdb;
		if ( '' === $key ) { return null; }
		$hash = hash_hmac( 'sha256', $key, 'wpcap-channel-' . self::NAMESPACE_KEY );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE idempotency_key=%s", $hash ) );
	}

	private static function error( $code, $message, $status, $retryable = false ) {
		return new \WP_Error( $code, $message, array( 'status' => $status, 'retryable' => $retryable ) );
	}
}
