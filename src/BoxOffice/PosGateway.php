<?php
/**
 * Payment adapters for box office payments (pos_cash, pos_card, pos_free, pos_invoice).
 *
 * They are never offered in the online checkout (is_available() is false). refund() only returns a
 * reference: the money is paid back at the till (cash), on the card terminal or by credit note.
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Payments\GatewayInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PosGateway implements GatewayInterface {
	private $id;
	private $title;

	public function __construct( $id, $title ) {
		$this->id = (string) $id;
		$this->title = (string) $title;
	}

	public function id() { return $this->id; }

	public function title() { return $this->title; }

	public function is_available( $currency ) { return false; }

	public function create_payment( array $order, array $items = array() ) {
		return new \WP_Error( 'wpcap_pos_only', __( 'Diese Zahlart ist nur an der Kasse verfügbar.', 'pass-captain' ) );
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		return new \WP_Error( 'wpcap_pos_no_webhook', __( 'Kassenzahlungen verarbeiten keine Webhooks.', 'pass-captain' ) );
	}

	public function supports_refunds() { return true; }

	public function refund( array $order, $amount_minor, $idempotency_key ) {
		return substr( $this->id . ':' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $idempotency_key ), 0, 191 );
	}

	/** wpcap_payment_gateways filter. */
	public static function register_gateways( $list ) {
		$list = is_array( $list ) ? $list : array();
		foreach ( Service::methods() as $method => $def ) {
			$list[] = new self( $def['provider'], $def['label'] );
		}
		return $list;
	}
}
