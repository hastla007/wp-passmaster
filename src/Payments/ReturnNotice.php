<?php
/** Status banner after returning from a hosted payment page (?wpcap_payment=…&order=…). */
namespace PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReturnNotice {
	public static function register() {
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
	}

	/** Messages only; the order state is never changed here (webhooks/capture do that). */
	public static function message( $state ) {
		$messages = array(
			'success' => __( 'Danke! Deine Zahlung wird bestätigt. Die Tickets kommen in wenigen Minuten per E-Mail.', 'wp-pass-captain' ),
			'pending' => __( 'Deine Zahlung wird noch verarbeitet. Sobald sie bestätigt ist, senden wir dir die Tickets per E-Mail.', 'wp-pass-captain' ),
			'failed'  => __( 'Die Zahlung ist fehlgeschlagen. Es wurde nichts abgebucht; du kannst es erneut versuchen.', 'wp-pass-captain' ),
			'cancel'  => __( 'Die Zahlung wurde abgebrochen. Deine Reservierung verfällt automatisch.', 'wp-pass-captain' ),
		);
		return $messages[ $state ] ?? '';
	}

	public static function render() {
		$state = sanitize_key( wp_unslash( $_GET['wpcap_payment'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = self::message( $state );
		if ( '' === $message ) { return; }
		$color = in_array( $state, array( 'success', 'pending' ), true ) ? '#146c2e' : '#b32d2e';
		echo '<div class="wpcap-payment-notice" role="status" style="padding:1rem;margin:1rem;border-left:4px solid ' . esc_attr( $color ) . ';background:#f6f7f7">' . esc_html( $message ) . '</div>';
	}
}
