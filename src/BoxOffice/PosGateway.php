<?php
/**
 * Payment adapters for box office payments (pos_cash, pos_card, pos_free, pos_invoice).
 *
 * They are never offered in the online checkout (is_available() is false). refund() books the payout
 * into an open shift (Shifts::book_refund(): negative movement with storno receipt number and VAT per
 * rate) and returns a reference: the money is paid back at the till (cash), on the card terminal or
 * by credit note. Cash and card payouts fail without an open shift, so they never bypass the
 * reconciliation; the refund can be executed again once a shift is open.
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
		return new \WP_Error( 'wpcap_pos_only', __( 'Diese Zahlart ist nur an der Kasse verfügbar.', 'wp-pass-captain' ) );
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		return new \WP_Error( 'wpcap_pos_no_webhook', __( 'Kassenzahlungen verarbeiten keine Webhooks.', 'wp-pass-captain' ) );
	}

	public function supports_refunds() { return true; }

	public function refund( array $order, $amount_minor, $idempotency_key ) {
		if ( preg_match( '/^wpcap-refund-(\d+)$/', (string) $idempotency_key, $m ) ) {
			$booked = Shifts::book_refund( (int) $m[1], true );
			if ( is_wp_error( $booked ) ) { return $booked; }
		}
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
