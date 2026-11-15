<?php
/**
 * Checkout notices next to the order button: withdrawal right notice and privacy notice.
 *
 * Withdrawal (Widerrufsrecht): for admission tickets to events on a specific date there is usually
 * no right of withdrawal (DE § 312g Abs. 2 Nr. 9 BGB, AT § 18 Abs. 1 Z 10 FAGG). The plugin shows an
 * editable default text (setting `withdrawal_notice_text`, switch `withdrawal_notice`) only when every
 * position of the checkout has a fixed date. Products without a date (member passes, vouchers,
 * service passes, if they are ever sold in the own checkout) usually have a right of withdrawal: then
 * the notice `withdrawal_right_text` (editable, e.g. the operator's withdrawal instructions) is shown.
 * The texts are defaults, not legal advice; the operator has to check and adapt them.
 *
 * Privacy (Art. 13 GDPR): a short notice with the link to the WordPress privacy policy page
 * (get_privacy_policy_url()), only when such a page is set.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Frontend;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LegalNotice {
	/**
	 * Whether every ticket type belongs to an occurrence with a start (all positions are dated).
	 *
	 * @param object[]    $types      Ticket types (occurrence_id, optional starts_at).
	 * @param object|null $occurrence Occurrence the types belong to (event card: one occurrence).
	 */
	public static function all_dated( array $types, $occurrence = null ) {
		if ( ! $types ) { return false; }
		$start = is_object( $occurrence ) ? trim( (string) ( $occurrence->starts_at ?? '' ) ) : '';
		foreach ( $types as $type ) {
			$type_start = trim( (string) ( $type->starts_at ?? $start ) );
			if ( '' === $type_start || 0 === strpos( $type_start, '0000-00-00' ) ) { return false; }
		}
		return true;
	}

	/**
	 * Withdrawal notice for a checkout.
	 *
	 * @param bool $all_dated Whether every position has a fixed event date.
	 * @return array{kind:string,text:string} kind `excluded` (no right of withdrawal), `right` (right of
	 *                                        withdrawal exists) or '' (nothing to show).
	 */
	public static function withdrawal( $all_dated ) {
		if ( $all_dated ) {
			$text = trim( (string) Settings::get( 'withdrawal_notice_text' ) );
			return Settings::get( 'withdrawal_notice' ) && '' !== $text ? array( 'kind' => 'excluded', 'text' => $text ) : array( 'kind' => '', 'text' => '' );
		}
		$text = trim( (string) Settings::get( 'withdrawal_right_text' ) );
		return '' !== $text ? array( 'kind' => 'right', 'text' => $text ) : array( 'kind' => '', 'text' => '' );
	}

	/** URL of the privacy policy page ('' when none is set or the function is unavailable). */
	public static function privacy_url() {
		return function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
	}

	/**
	 * Notices below the order button of the event card: withdrawal notice and privacy notice.
	 *
	 * @param object   $occurrence Occurrence/event row of the card (starts_at).
	 * @param object[] $types      Ticket types offered on the card.
	 */
	public static function checkout_html( $occurrence, array $types ) {
		$html = '';
		$withdrawal = self::withdrawal( self::all_dated( $types, $occurrence ) );
		if ( '' !== $withdrawal['kind'] ) {
			$html .= '<div class="wpcap-legal-notice wpcap-withdrawal wpcap-withdrawal-' . esc_attr( $withdrawal['kind'] ) . '">' . wp_kses_post( wpautop( $withdrawal['text'] ) ) . '</div>';
		}
		$privacy = self::privacy_url();
		if ( '' !== $privacy ) {
			$html .= '<p class="wpcap-legal-notice wpcap-privacy-notice"><small>' . sprintf(
				/* translators: %s: link to the privacy policy */
				esc_html__( 'Wir verarbeiten deine Angaben zur Abwicklung der Bestellung. Details findest du in der %s.', 'pass-captain' ),
				'<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Datenschutzerklärung', 'pass-captain' ) . '</a>'
			) . '</small></p>';
		}
		return (string) apply_filters( 'wp_pass_captain_checkout_legal_html_v1', $html, $occurrence, $types );
	}
}
