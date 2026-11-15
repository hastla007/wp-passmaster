<?php
/**
 * Status banner after returning from a hosted payment page (?wpcap_payment=…&order=…&wpcap_rt=…).
 *
 * - The buyer returns to the page they ordered on (checkout field return_to, same site only via
 *   wp_validate_redirect()), not to the home page; set_base() is called before the gateway builds its
 *   return URLs, PayPal/Mollie carry it through their own return handlers (wpcap_return_to).
 * - The banner is printed at wp_body_open; classic themes without that hook get it at the top of the
 *   main content (the_content). Pages with ?wpcap_payment are never cached (DONOTCACHEPAGE + nocache).
 * - After a cancelled or failed payment the buyer can pay again (new payment page for the same order
 *   while its hold lasts) or release the reservation at once (cancel_order(), unpaid orders only).
 *   Both actions are authorised by the return token wpcap_rt, a keyed hash of the order's public id
 *   that only travels in the return URL handed to the payment provider.
 */
namespace PassCaptain\Payments;

use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReturnNotice {
	/** Validated return page of the current checkout request ('' = home page). */
	private static $base = '';

	/** Whether the banner was already printed in this request (wp_body_open or the_content). */
	private static $rendered = false;

	public static function register() {
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
		add_filter( 'the_content', array( __CLASS__, 'prepend_to_content' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 0, 0 );
		add_action( 'admin_post_wpcap_payment_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_nopriv_wpcap_payment_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_wpcap_payment_release', array( __CLASS__, 'handle_release' ) );
		add_action( 'admin_post_nopriv_wpcap_payment_release', array( __CLASS__, 'handle_release' ) );
	}

	/** Messages only; the order state is never changed by showing the banner (webhooks/capture do that). */
	public static function message( $state ) {
		$messages = array(
			'success'  => __( 'Danke! Deine Zahlung wird bestätigt. Die Tickets kommen in wenigen Minuten per E-Mail.', 'pass-captain' ),
			'pending'  => __( 'Deine Zahlung wird noch verarbeitet. Sobald sie bestätigt ist, senden wir dir die Tickets per E-Mail.', 'pass-captain' ),
			'failed'   => __( 'Die Zahlung ist fehlgeschlagen. Es wurde nichts abgebucht; du kannst es erneut versuchen.', 'pass-captain' ),
			'cancel'   => __( 'Die Zahlung wurde abgebrochen. Deine Reservierung verfällt automatisch.', 'pass-captain' ),
			'released' => __( 'Deine Reservierung wurde freigegeben. Es wurde nichts abgebucht.', 'pass-captain' ),
		);
		return $messages[ $state ] ?? '';
	}

	/** Banner state from the request (?wpcap_payment). */
	public static function state() {
		return sanitize_key( wp_unslash( $_GET['wpcap_payment'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/** template_redirect: a page with ?wpcap_payment shows an order-specific banner – never cache it. */
	public static function no_cache() {
		if ( '' === self::state() ) { return false; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared constant read by page-cache plugins.
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) { nocache_headers(); }
		return true;
	}

	/* ------------------------------------------------------------------ return URLs */

	/** Same-site page without the plugin's return parameters, '' when not allowed. */
	public static function sanitize_base( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > 2000 ) { return ''; }
		$url = (string) wp_validate_redirect( esc_url_raw( $url ), '' );
		if ( '' === $url ) { return ''; }
		$url = (string) strtok( $url, '#' );
		return (string) remove_query_arg( array( 'wpcap_payment', 'order', 'wpcap_rt', 'wpcap_return_to', 'wpcap_paypal_return', 'wpcap_mollie_return', 'token' ), $url );
	}

	/** Sets the return page for the payment pages created in this request. */
	public static function set_base( $url ) {
		self::$base = self::sanitize_base( $url );
	}

	/** Return page: set_base(), else ?wpcap_return_to of a gateway return handler, else the home page. */
	public static function base() {
		if ( '' !== self::$base ) { return self::$base; }
		$from_request = isset( $_GET['wpcap_return_to'] ) ? self::sanitize_base( wp_unslash( (string) $_GET['wpcap_return_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in sanitize_base().
		return '' !== $from_request ? $from_request : home_url( '/' );
	}

	/** Return token of an order (keyed hash of the public id, 32 hex characters). */
	public static function token( $public_id ) {
		$key = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : ABSPATH );
		return substr( hash_hmac( 'sha256', 'wpcap-payment-return|' . (string) $public_id, $key ), 0, 32 );
	}

	/** Buyer return URL for a payment state (the gateways add their own filter on top). */
	public static function url( $status, $public_id ) {
		return add_query_arg( array( 'wpcap_payment' => sanitize_key( $status ), 'order' => (string) $public_id, 'wpcap_rt' => self::token( $public_id ) ), self::base() );
	}

	/** URL of a gateway's own return handler that keeps the return page (PayPal capture, Mollie). */
	public static function handler_url( array $args ) {
		if ( '' !== self::$base && self::$base !== home_url( '/' ) ) { $args['wpcap_return_to'] = self::$base; }
		return add_query_arg( $args, home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ pay again / release */

	/** Order for a public id and return token, or null. */
	public static function order_for( $public_id, $token ) {
		global $wpdb;
		$public_id = is_string( $public_id ) ? strtolower( $public_id ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $public_id ) || ! is_string( $token ) || ! hash_equals( self::token( $public_id ), $token ) ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,public_id,status,payment_status,payment_provider,currency,total_minor FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) );
	}

	/** End of the order's active hold (Unix time), 0 when none is left. */
	public static function hold_until( $order_id ) {
		global $wpdb;
		$until = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM %i WHERE order_id=%d AND status='active' AND expires_at > %s", $wpdb->prefix . 'wpcap_reservations', absint( $order_id ), current_time( 'mysql', true ) ) );
		return $until ? (int) strtotime( $until . ' UTC' ) : 0;
	}

	/** Enabled hosted-payment gateway of an unpaid order whose hold still runs, or null. */
	private static function retry_gateway( $order ) {
		if ( ! $order || 'pending' !== $order->payment_status || 'pending_payment' !== $order->status || ! self::hold_until( (int) $order->order_id ) ) { return null; }
		$gateway = Registry::get( (string) $order->payment_provider );
		$enabled = (array) Settings::get( 'enabled_providers' );
		if ( ! $gateway || 'manual' === $order->payment_provider || ! in_array( (string) $order->payment_provider, $enabled, true ) || ! $gateway->is_available( (string) $order->currency ) ) { return null; }
		return $gateway;
	}

	/** Whether "Erneut bezahlen" / "Reservierung freigeben" can be offered for the order. */
	public static function can_retry( $order ) {
		return null !== self::retry_gateway( $order );
	}

	/**
	 * New payment page for the same order while its hold lasts (Stripe reuses a still open session of
	 * the same amount). Returns array( redirect_url ) or WP_Error.
	 */
	public static function retry( $public_id, $token, $return_to = '' ) {
		$order = self::order_for( $public_id, $token );
		if ( ! $order ) { return new \WP_Error( 'wpcap_payment_retry_denied', __( 'Diese Bestellung wurde nicht gefunden.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! RateLimit::allow( 'wpcap_payment_retry|' . $order->public_id, 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$gateway = self::retry_gateway( $order );
		if ( ! $gateway ) { return new \WP_Error( 'wpcap_payment_retry_closed', __( 'Die Reservierung ist abgelaufen. Bitte bestelle erneut.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$min_hold = method_exists( $gateway, 'min_hold_minutes' ) ? max( 0, (int) $gateway->min_hold_minutes() ) : 0;
		$hold_until = self::hold_until( (int) $order->order_id );
		if ( $min_hold && $hold_until < time() + $min_hold * MINUTE_IN_SECONDS ) {
			// The new payment page must end inside the hold (same rule as the checkout, QA-6).
			Orders::extend_hold( (int) $order->order_id, $min_hold );
			$hold_until = self::hold_until( (int) $order->order_id );
		}
		$row = (array) Orders::get( (int) $order->order_id );
		$row['hold_expires_at'] = $hold_until;
		self::set_base( $return_to );
		$items = array_map( static function ( $line ) { return (array) $line; }, Orders::items_for_order( (int) $order->order_id ) );
		$payment = $gateway->create_payment( $row, $items );
		if ( is_wp_error( $payment ) ) { return new \WP_Error( $payment->get_error_code(), $payment->get_error_message(), array( 'status' => 502 ) ); }
		if ( empty( $payment['redirect_url'] ) ) { return new \WP_Error( 'wpcap_payment_retry_closed', __( 'Für diese Zahlungsart ist keine neue Zahlungsseite möglich.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( (int) $order->order_id, $payment['provider_reference'] ); }
		return array( 'redirect_url' => (string) $payment['redirect_url'] );
	}

	/**
	 * Releases the reservation of an unpaid order at once (the tickets are free for others). An open
	 * Stripe Checkout session is expired first, so it can no longer be paid.
	 *
	 * @return true|\WP_Error
	 */
	public static function release( $public_id, $token ) {
		$order = self::order_for( $public_id, $token );
		if ( ! $order ) { return new \WP_Error( 'wpcap_payment_release_denied', __( 'Diese Bestellung wurde nicht gefunden.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! in_array( (string) $order->payment_status, array( 'pending', 'expired' ), true ) ) {
			return in_array( (string) $order->status, array( 'cancelled' ), true ) ? true : new \WP_Error( 'wpcap_order_paid_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$gateway = Registry::get( (string) $order->payment_provider );
		if ( $gateway && method_exists( $gateway, 'cancel_payment' ) ) {
			$cancelled = $gateway->cancel_payment( (array) Orders::get( (int) $order->order_id ) );
			if ( is_wp_error( $cancelled ) ) { return $cancelled; }
		}
		$result = Orders::cancel_order( (int) $order->order_id, 'buyer_released', true );
		return is_wp_error( $result ) ? $result : true;
	}

	/** admin-post (POST): pay again. */
	public static function handle_retry() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorised by the order's return token (wpcap_rt).
		$public_id = sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) );
		$token = sanitize_text_field( wp_unslash( $_POST['wpcap_rt'] ?? '' ) );
		$return_to = esc_url_raw( wp_unslash( $_POST['return_to'] ?? '' ) );
		// phpcs:enable
		$result = self::retry( $public_id, $token, $return_to );
		nocache_headers();
		if ( is_wp_error( $result ) ) {
			self::set_base( $return_to );
			wp_safe_redirect( self::url( 'failed', $public_id ) );
			exit;
		}
		wp_redirect( $result['redirect_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- payment provider page created by the gateway.
		exit;
	}

	/** admin-post (POST): release the reservation. */
	public static function handle_release() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorised by the order's return token (wpcap_rt).
		$public_id = sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) );
		$token = sanitize_text_field( wp_unslash( $_POST['wpcap_rt'] ?? '' ) );
		$return_to = esc_url_raw( wp_unslash( $_POST['return_to'] ?? '' ) );
		// phpcs:enable
		$result = self::release( $public_id, $token );
		self::set_base( $return_to );
		nocache_headers();
		wp_safe_redirect( self::url( is_wp_error( $result ) ? 'failed' : 'released', $public_id ) );
		exit;
	}

	/* ------------------------------------------------------------------ banner */

	/** Banner HTML for the current request ('' without ?wpcap_payment). */
	public static function html() {
		$state = self::state();
		$message = self::message( $state );
		if ( '' === $message ) { return ''; }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; actions are authorised by the token.
		$public_id = sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) );
		$token = sanitize_text_field( wp_unslash( $_GET['wpcap_rt'] ?? '' ) );
		// phpcs:enable
		$actions = '';
		if ( in_array( $state, array( 'cancel', 'failed' ), true ) ) {
			$order = self::order_for( $public_id, $token );
			if ( $order && self::can_retry( $order ) ) {
				$until = self::hold_until( (int) $order->order_id );
				/* translators: %s: time */
				$message = sprintf( 'cancel' === $state ? __( 'Die Zahlung wurde abgebrochen. Deine Tickets sind noch bis %s Uhr für dich reserviert.', 'pass-captain' ) : __( 'Die Zahlung ist fehlgeschlagen. Es wurde nichts abgebucht. Deine Tickets sind noch bis %s Uhr für dich reserviert.', 'pass-captain' ), wp_date( 'H:i', $until ) );
				$base = self::base();
				$form = static function ( $action, $label, $css ) use ( $public_id, $token, $base ) {
					return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-payment-action"><input type="hidden" name="action" value="' . esc_attr( 'wpcap_payment_' . $action ) . '"><input type="hidden" name="order" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="wpcap_rt" value="' . esc_attr( $token ) . '"><input type="hidden" name="return_to" value="' . esc_attr( $base ) . '"><button type="submit" class="' . esc_attr( $css ) . '">' . esc_html( $label ) . '</button></form>';
				};
				$actions = '<div class="wpcap-payment-actions">' . $form( 'retry', __( 'Erneut bezahlen', 'pass-captain' ), 'wp-element-button' ) . $form( 'release', __( 'Reservierung freigeben', 'pass-captain' ), 'wpcap-link-button' ) . '</div>';
			}
		}
		$color = in_array( $state, array( 'success', 'pending', 'released' ), true ) ? '#146c2e' : '#b32d2e';
		return '<div class="wpcap-payment-notice" role="status" style="padding:1rem;margin:1rem;border-left:4px solid ' . esc_attr( $color ) . ';background:#f6f7f7;color:#1e1e1e"><p style="margin:0">' . esc_html( $message ) . '</p>' . $actions . '</div>';
	}

	/** wp_body_open. */
	public static function render() {
		$html = self::html();
		if ( '' === $html ) { return; }
		self::$rendered = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
	}

	/** the_content: fallback for themes without wp_body_open (main query, once). */
	public static function prepend_to_content( $content ) {
		if ( self::$rendered || '' === self::state() ) { return $content; }
		if ( function_exists( 'in_the_loop' ) && ( ! in_the_loop() || ! is_main_query() ) ) { return $content; }
		$html = self::html();
		if ( '' === $html ) { return $content; }
		self::$rendered = true;
		return $html . $content;
	}

	/** Resets the request state (tests). */
	public static function reset() {
		self::$base = '';
		self::$rendered = false;
	}
}
