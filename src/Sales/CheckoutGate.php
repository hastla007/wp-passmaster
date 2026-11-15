<?php
/**
 * Gate before public checkouts and bookings (extension point for e.g. a virtual waiting room).
 *
 * Filter `wp_pass_captain_checkout_allowed_v1( true $allowed, array $context )` returns `true` or a
 * `WP_Error`. The core asks it for public sales only:
 *  - native checkout (POST /checkout and every other Orders::create() call without a channel namespace
 *    and without trusted adapter options),
 *  - service bookings by customers (POST /service-slots/{id}/bookings, pass bookings),
 *  - WooCommerce: add to cart, cart check, classic checkout and the Store API (cart and checkout blocks).
 * Never for box office, admin, waitlist offers, voucher conversion, WooCommerce order processing after
 * checkout or other internal sources – those either already hold their capacity or are staff actions.
 *
 * Without a callback nothing changes (the filter returns true). A WP_Error is passed to the buyer: the
 * status defaults to 503, `retry_after` (seconds) becomes a Retry-After header on REST responses.
 * Fail open: an exception thrown by a callback is logged and the checkout continues, so a broken add-on
 * never blocks ticket sales.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Log;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CheckoutGate {
	const FILTER = 'wp_pass_captain_checkout_allowed_v1';
	const VERSION = 1;

	/** Hooks the Retry-After header for refused REST checkouts. */
	public static function register() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'retry_after_header' ) );
	}

	/** Whether any callback is registered (outside WordPress the filter is always asked). */
	public static function is_active() {
		return ! function_exists( 'has_filter' ) || false !== has_filter( 'wp_pass_captain_checkout_allowed_v1' );
	}

	/**
	 * Asks the gate.
	 *
	 * @param array $context source, phase and items (ticket type id => quantity), optional occurrence_ids,
	 *                       event_ids, slot_id, email, idempotency_key, request (WP_REST_Request|null).
	 * @return true|\WP_Error
	 */
	public static function check( array $context ) {
		if ( ! self::is_active() ) { return true; }
		$context = self::context( $context );
		try {
			$result = apply_filters( 'wp_pass_captain_checkout_allowed_v1', true, $context );
		} catch ( \Throwable $e ) {
			Log::warning( 'checkout_gate_failed', 'Checkout gate callback failed; checkout continues.', array( 'source' => $context['source'], 'error' => get_class( $e ) ) );
			return true;
		}
		return self::normalize( $result );
	}

	/** Completes the context: version, ids as ints, occurrence/event ids of the ticket types. */
	public static function context( array $context ) {
		$items = array();
		foreach ( (array) ( $context['items'] ?? array() ) as $type_id => $quantity ) {
			$type_id = absint( $type_id );
			if ( $type_id ) { $items[ $type_id ] = ( $items[ $type_id ] ?? 0 ) + absint( $quantity ); }
		}
		$out = array(
			'version' => self::VERSION,
			'source' => sanitize_key( (string) ( $context['source'] ?? 'native' ) ),
			'phase' => sanitize_key( (string) ( $context['phase'] ?? 'checkout' ) ),
			'items' => $items,
			'ticket_type_ids' => array_keys( $items ),
			'occurrence_ids' => array_values( array_unique( array_map( 'absint', (array) ( $context['occurrence_ids'] ?? array() ) ) ) ),
			'event_ids' => array_values( array_unique( array_map( 'absint', (array) ( $context['event_ids'] ?? array() ) ) ) ),
			'slot_id' => absint( $context['slot_id'] ?? 0 ),
			'email' => is_string( $context['email'] ?? null ) ? (string) $context['email'] : '',
			'idempotency_key' => is_scalar( $context['idempotency_key'] ?? null ) ? (string) $context['idempotency_key'] : '',
			'request' => ( $context['request'] ?? null ) instanceof \WP_REST_Request ? $context['request'] : null,
		);
		if ( $items && ( ! $out['occurrence_ids'] || ! $out['event_ids'] ) ) {
			global $wpdb;
			$ids = implode( ',', array_map( 'intval', array_keys( $items ) ) );
			$rows = (array) $wpdb->get_results( "SELECT tt.ticket_type_id,o.occurrence_id,o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( $rows as $row ) {
				$out['occurrence_ids'][] = (int) $row->occurrence_id;
				$out['event_ids'][] = (int) $row->event_id;
			}
			$out['occurrence_ids'] = array_values( array_unique( $out['occurrence_ids'] ) );
			$out['event_ids'] = array_values( array_unique( $out['event_ids'] ) );
		}
		sort( $out['occurrence_ids'] );
		sort( $out['event_ids'] );
		return $out;
	}

	/** true stays true; a WP_Error gets a status (default 503); anything else becomes a generic refusal. */
	public static function normalize( $result ) {
		if ( true === $result ) { return true; }
		if ( ! is_wp_error( $result ) ) {
			return new \WP_Error( 'wpcap_checkout_not_allowed', __( 'Der Ticketverkauf ist gerade nicht möglich. Bitte versuche es in Kürze erneut.', 'pass-captain' ), array( 'status' => 503, 'checkout_gate' => true ) );
		}
		$data = $result->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$status = (int) ( $data['status'] ?? 0 );
		$data['status'] = $status >= 400 && $status < 600 ? $status : 503;
		if ( isset( $data['retry_after'] ) ) { $data['retry_after'] = max( 1, min( DAY_IN_SECONDS, absint( $data['retry_after'] ) ) ); }
		$data['checkout_gate'] = true;
		$message = '' !== (string) $result->get_error_message() ? (string) $result->get_error_message() : __( 'Der Ticketverkauf ist gerade nicht möglich. Bitte versuche es in Kürze erneut.', 'pass-captain' );
		return new \WP_Error( (string) $result->get_error_code() ?: 'wpcap_checkout_not_allowed', $message, $data );
	}

	/** rest_post_dispatch: Retry-After for gate refusals (error data retry_after). */
	public static function retry_after_header( $response ) {
		if ( ! $response instanceof \WP_REST_Response || $response->get_status() < 400 ) { return $response; }
		$data = $response->get_data();
		$error = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		if ( ! empty( $error['checkout_gate'] ) && ! empty( $error['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) absint( $error['retry_after'] ) );
		}
		return $response;
	}
}
