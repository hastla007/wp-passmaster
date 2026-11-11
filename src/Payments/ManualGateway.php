<?php
/** Manual-payment adapter. It never marks an order paid by itself. */
namespace PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ManualGateway implements GatewayInterface {
	public function create_payment( array $order ) {
		return array(
			'provider' => 'manual',
			'status' => 'pending',
			'order_reference' => sanitize_text_field( $order['public_id'] ?? '' ),
			'instructions' => __( 'Die Zahlung muss außerhalb des Plugins bestätigt werden.', 'wp-pass-captain' ),
		);
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		return new \WP_Error( 'wpcap_manual_no_webhook', __( 'Die manuelle Zahlart verarbeitet keine Webhooks.', 'wp-pass-captain' ) );
	}
}
