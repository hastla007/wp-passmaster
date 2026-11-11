<?php
/** Manual-payment adapter (bank transfer, box office). It never marks an order paid by itself. */
namespace PassCaptain\Payments;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ManualGateway implements GatewayInterface {
	public function id() { return 'manual'; }

	public function title() { return __( 'Überweisung / Zahlung vor Ort', 'wp-pass-captain' ); }

	public function is_available( $currency ) { return true; }

	public function create_payment( array $order, array $items = array() ) {
		$instructions = trim( (string) Settings::get( 'manual_instructions' ) );
		return array(
			'provider' => 'manual',
			'status' => 'pending',
			'order_reference' => sanitize_text_field( $order['public_id'] ?? '' ),
			'instructions' => '' !== $instructions ? $instructions : __( 'Die Zahlung wird vom Veranstalter bestätigt. Danach erhältst du deine Tickets per E-Mail.', 'wp-pass-captain' ),
		);
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		return new \WP_Error( 'wpcap_manual_no_webhook', __( 'Die manuelle Zahlart verarbeitet keine Webhooks.', 'wp-pass-captain' ) );
	}

	public function supports_refunds() { return false; }

	public function refund( array $order, $amount_minor, $idempotency_key ) {
		return new \WP_Error( 'wpcap_manual_refund', __( 'Manuelle Zahlungen werden außerhalb des Plugins zurücküberwiesen.', 'wp-pass-captain' ) );
	}
}
