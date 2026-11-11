<?php
/** Shared, idempotent webhook processing for all payment adapters. */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Webhooks {
	/** REST callback: POST /wp-pass-captain/v1/payments/{provider}/webhook */
	public static function handle( \WP_REST_Request $request ) {
		$gateway = Registry::get( sanitize_key( $request['provider'] ) );
		if ( ! $gateway ) {
			return new \WP_Error( 'wpcap_provider_unknown', 'Unknown provider.', array( 'status' => 404 ) );
		}
		$event = $gateway->verify_webhook( $request );
		if ( is_wp_error( $event ) ) {
			$data = (array) $event->get_error_data();
			return new \WP_Error( $event->get_error_code(), $event->get_error_message(), array( 'status' => $data['status'] ?? 400 ) );
		}
		$result = self::process( $gateway->id(), $event );
		if ( is_wp_error( $result ) ) {
			// 5xx so the provider retries later; the event row was released for the retry.
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'received' => true, 'result' => $result ) );
	}

	/**
	 * Applies a normalized, verified event exactly once.
	 *
	 * @param string $provider Provider id.
	 * @param array  $event    Normalized event (see GatewayInterface::verify_webhook()).
	 * @return string|\WP_Error Result label.
	 */
	public static function process( $provider, array $event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = substr( sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ), 0, 191 );
		if ( '' === $event_id ) {
			return new \WP_Error( 'wpcap_webhook_event_id', 'Missing event id.' );
		}
		$now = current_time( 'mysql', true );
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_payment_events (provider,provider_event_id,event_type,status,created_at,updated_at) VALUES (%s,%s,%s,'processing',%s,%s)", $provider, $event_id, sanitize_key( $event['type'] ?? '' ), $now, $now ) );
		if ( ! $claimed ) {
			return 'duplicate';
		}
		$order_id = 0;
		$public_id = sanitize_text_field( (string) ( $event['order_public_id'] ?? '' ) );
		if ( '' !== $public_id ) {
			$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE public_id=%s AND payment_provider=%s", $public_id, $provider ) );
		}
		$result = 'ignored';
		$type = (string) ( $event['type'] ?? '' );
		if ( $order_id && 'paid' === $type ) {
			$order = Orders::get( $order_id );
			if ( (int) ( $event['amount_minor'] ?? -1 ) !== (int) $order->total_minor || strtoupper( (string) ( $event['currency'] ?? '' ) ) !== strtoupper( $order->currency ) ) {
				// Never issue tickets for a payment that does not match the order.
				Audit::record( 'payment_amount_mismatch', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ) ) );
				$result = 'amount_mismatch';
			} else {
				if ( ! empty( $event['provider_reference'] ) ) {
					$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => substr( sanitize_text_field( $event['provider_reference'] ), 0, 191 ) ), array( 'order_id' => $order_id ) );
				}
				$confirmed = Orders::confirm_external_payment( $order_id, $provider . ':' . $event_id );
				$result = is_wp_error( $confirmed ) ? $confirmed->get_error_code() : 'paid';
			}
		} elseif ( $order_id && 'failed' === $type ) {
			$order = Orders::get( $order_id );
			if ( $order && 'pending' === $order->payment_status ) {
				Orders::cancel_order( $order_id, $provider . '_payment_failed' );
				$result = 'cancelled';
			}
		} elseif ( $order_id && 'refunded' === $type ) {
			// Full refund triggered outside the plugin (e.g. provider dashboard).
			$order = Orders::get( $order_id );
			if ( $order && (int) ( $event['amount_minor'] ?? 0 ) >= (int) $order->total_minor - (int) $order->refunded_minor ) {
				Orders::mark_refunded( $order_id, $provider . '_dashboard_refund' );
				$result = 'refunded';
			} else {
				Audit::record( 'external_partial_refund', 'order', $order_id, array( 'provider' => $provider, 'amount_minor' => (int) ( $event['amount_minor'] ?? 0 ) ) );
				$result = 'partial_refund_logged';
			}
		}
		$wpdb->update( "{$p}wpcap_payment_events", array( 'status' => 'processed', 'order_id' => $order_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'provider' => $provider, 'provider_event_id' => $event_id ) );
		return $result;
	}
}
