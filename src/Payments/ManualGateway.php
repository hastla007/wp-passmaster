<?php
/** Manual-payment adapter (bank transfer, box office). It never marks an order paid by itself. */
namespace PassCaptain\Payments;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ManualGateway implements GatewayInterface {
	public function id() { return 'manual'; }

	public function title() { return __( 'Überweisung', 'pass-captain' ); }

	/** Only offered once the buyer can actually pay: bank account (IBAN + recipient) or a payment instruction text (A265). */
	public function is_available( $currency ) { return self::configured(); }

	/** Whether manual payment has what the buyer needs to pay (see is_available()). */
	public static function configured() {
		return BankTransfer::has_account() || '' !== trim( (string) Settings::get( 'manual_instructions' ) );
	}

	/** Enabled in the settings but not configured: the checkout does not offer it (admin notice, settings status). */
	public static function enabled_but_incomplete() {
		return in_array( 'manual', (array) Settings::get( 'enabled_providers' ), true ) && ! self::configured();
	}

	public function create_payment( array $order, array $items = array() ) {
		$instructions = trim( (string) Settings::get( 'manual_instructions' ) );
		return array(
			'provider' => 'manual',
			'status' => 'pending',
			'order_reference' => sanitize_text_field( $order['public_id'] ?? '' ),
			'instructions' => '' !== $instructions ? $instructions : __( 'Die Zahlung wird vom Veranstalter bestätigt. Danach erhältst du deine Tickets per E-Mail.', 'pass-captain' ),
		);
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		return new \WP_Error( 'wpcap_manual_no_webhook', __( 'Die manuelle Zahlart verarbeitet keine Webhooks.', 'pass-captain' ) );
	}

	public function supports_refunds() { return false; }

	public function refund( array $order, $amount_minor, $idempotency_key ) {
		return new \WP_Error( 'wpcap_manual_refund', __( 'Manuelle Zahlungen werden außerhalb des Plugins zurücküberwiesen.', 'pass-captain' ) );
	}
}
