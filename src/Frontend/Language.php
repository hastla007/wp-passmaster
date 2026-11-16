<?php
/**
 * Explicit buyer language selection (W9): a language selector in the checkout (field "locale", stored
 * on the order through Localization::order_locale()) and in the order portal (admin-post action
 * wpcap_portal_language, authorised by the order access token). The order locale is used for all
 * buyer e-mails (Mail\Mailer::send with the order locale), the portal and the ticket page.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Frontend;

use PassCaptain\Audit;
use PassCaptain\Localization;
use PassCaptain\Security\Policy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Language {
	const ACTION = 'wpcap_portal_language';

	public static function register() {
		add_filter( 'wpcap_checkout_payload_keys', array( __CLASS__, 'payload_keys' ) );
		add_action( 'admin_post_wpcap_portal_language', array( __CLASS__, 'handle_portal_language' ) );
		add_action( 'admin_post_nopriv_wpcap_portal_language', array( __CLASS__, 'handle_portal_language' ) );
	}

	public static function enabled() {
		return (bool) Policy::get( 'buyer_language_selector' ) && count( Localization::supported_locales() ) > 1;
	}

	/** @param string[] $keys Allowed checkout payload keys. */
	public static function payload_keys( $keys ) {
		$keys = is_array( $keys ) ? $keys : array();
		$keys[] = 'locale';
		return array_values( array_unique( $keys ) );
	}

	/** Language select (escaped markup) with the given locale preselected. */
	public static function select_html( $current, $id, $name = 'locale' ) {
		$current = Localization::normalize_buyer_locale( $current );
		$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" autocomplete="language">';
		foreach ( Localization::supported_locales() as $locale => $label ) {
			$html .= '<option value="' . esc_attr( (string) $locale ) . '" lang="' . esc_attr( str_replace( '_', '-', (string) $locale ) ) . '"' . ( (string) $locale === $current ? ' selected' : '' ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Checkout field (empty when the selector is disabled). */
	public static function checkout_field( $form_id ) {
		if ( ! self::enabled() ) { return ''; }
		$id = $form_id . '-locale';
		$current = function_exists( 'determine_locale' ) ? determine_locale() : 'de_DE';
		return '<div class="wpcap-field wpcap-language"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Sprache für E-Mails und Tickets', 'pass-captain' ) . '</label>' . self::select_html( $current, $id ) . '</div>';
	}

	/** Portal form (empty when disabled). */
	public static function portal_form( $order, $token ) {
		if ( ! self::enabled() ) { return ''; }
		$html = '<form class="card wpcap-language-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="order" value="' . esc_attr( (string) $order->public_id ) . '"><input type="hidden" name="token" value="' . esc_attr( (string) $token ) . '">';
		$html .= '<label for="wpcap-portal-locale">' . esc_html__( 'Sprache für E-Mails, Tickets und dieses Portal', 'pass-captain' ) . '</label>' . self::select_html( (string) $order->locale, 'wpcap-portal-locale' );
		$html .= ' <button type="submit">' . esc_html__( 'Sprache speichern', 'pass-captain' ) . '</button></form>';
		return $html;
	}

	/**
	 * Changes the language of an order after verifying the access token.
	 *
	 * @return array|\WP_Error ['locale' => ..., 'order' => row]
	 */
	public static function set_order_locale( $public_id, $token, $locale ) {
		global $wpdb;
		$public_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $public_id ) );
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) );
		$order = 32 === strlen( $public_id ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) ) : null;
		if ( ! $order || 64 !== strlen( $token ) || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $token ) ) ) {
			return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$locale = Localization::normalize_buyer_locale( $locale );
		if ( '' === $locale ) {
			return new \WP_Error( 'wpcap_locale_unsupported', __( 'Diese Sprache wird nicht unterstützt.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( $locale !== (string) $order->locale ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'locale' => $locale, 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => (int) $order->order_id ), array( '%s', '%s' ), array( '%d' ) );
			Audit::record( 'order_locale_changed', 'order', (int) $order->order_id, array( 'from' => (string) $order->locale, 'to' => $locale, 'source' => 'portal' ) );
			$order->locale = $locale;
		}
		return array( 'locale' => $locale, 'order' => $order );
	}

	public static function handle_portal_language() {
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised by the order access token (capability link).
		$public_id = is_scalar( $in['order'] ?? null ) ? (string) $in['order'] : '';
		$token = is_scalar( $in['token'] ?? null ) ? (string) $in['token'] : '';
		$result = self::set_order_locale( $public_id, $token, is_scalar( $in['locale'] ?? null ) ? (string) $in['locale'] : '' );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ) ) );
		}
		wp_safe_redirect( add_query_arg( array( 'wpcap_order' => (string) $result['order']->public_id, 'wpcap_access' => preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) ) ), home_url( '/' ) ) );
		exit;
	}
}
