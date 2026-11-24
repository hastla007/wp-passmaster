<?php
/**
 * Bot check of the public checkout form (block / shortcode, templates/event-card.php + assets/checkout.js):
 * an invisible trap field that people never fill and a minimum time between showing the form and sending
 * it. No cookies, no third-party service.
 *
 * Only requests of the plugin's own form are checked: checkout.js sends `form_guard` ({hp, ms}) only when
 * the form carries the trap field. Requests without it (own REST integrations, older cached pages,
 * internal callers such as service bookings, partner API, WooCommerce, box office) are not affected; they
 * stay bound by the rate limits. Switch: setting `checkout_bot_check` (default on), threshold
 * `checkout_min_fill_seconds` (default 3, 0 = trap field only).
 *
 * A refusal never locks a person out: "too fast" is answered with a hint and the next submit (the timer
 * keeps running) passes; the trap field is hidden (hidden attribute + .wpcap-hp display:none in public.css),
 * and browsers do not autofill fields that are not displayed.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Log;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FormGuard {
	/** Name of the trap field in the form. */
	const FIELD = 'wpcap_hp';

	/** Whether the check is switched on. */
	public static function enabled() {
		return (bool) Settings::get( 'checkout_bot_check', 1 );
	}

	/** Minimum fill time in milliseconds (0 = off). */
	public static function min_fill_ms() {
		return max( 0, min( 30, (int) Settings::get( 'checkout_min_fill_seconds', 3 ) ) ) * 1000;
	}

	/**
	 * Trap field markup for the checkout form ('' when switched off). Outside the visual flow and the
	 * accessibility tree, not focusable, without autocomplete hint.
	 *
	 * @param string $form_id Id prefix of the form.
	 */
	public static function field( $form_id ) {
		if ( ! self::enabled() ) { return ''; }
		$id = $form_id . '-hp';
		return '<div class="wpcap-hp" aria-hidden="true" hidden>'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Dieses Feld bitte leer lassen', 'pass-captain' ) . '</label>'
			. '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( self::FIELD ) . '" type="text" value="" tabindex="-1" autocomplete="off">'
			. '</div>';
	}

	/**
	 * Checks the `form_guard` part of a checkout request.
	 *
	 * @param array $params Request parameters.
	 * @return true|\WP_Error
	 */
	public static function check( array $params ) {
		if ( ! self::enabled() || ! isset( $params['form_guard'] ) || ! is_array( $params['form_guard'] ) ) { return true; }
		$guard = $params['form_guard'];
		$trap = Input::text( $guard['hp'] ?? '' );
		if ( '' !== trim( $trap ) ) {
			Log::info( 'checkout', 'Checkout refused: trap field filled', array( 'reason' => 'form_guard_trap' ) );
			return new \WP_Error( 'wpcap_form_guard', __( 'Die Bestellung konnte nicht gesendet werden. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ), array( 'status' => 400, 'reason' => 'form_guard' ) );
		}
		$min = self::min_fill_ms();
		$elapsed = is_numeric( $guard['ms'] ?? null ) ? (int) $guard['ms'] : -1;
		if ( $min > 0 && $elapsed < $min ) {
			Log::info( 'checkout', 'Checkout refused: form sent too fast', array( 'reason' => 'form_guard_fast', 'elapsed_ms' => max( -1, $elapsed ) ) );
			return new \WP_Error( 'wpcap_form_too_fast', __( 'Das ging sehr schnell. Bitte prüfe deine Angaben und sende das Formular noch einmal ab.', 'pass-captain' ), array( 'status' => 400, 'reason' => 'form_too_fast' ) );
		}
		return true;
	}
}
