<?php
/**
 * Return from a hosted payment page (?wpcap_payment=…&order=…&wpcap_rk=…).
 *
 * A554: the buyer comes back to the page the purchase started on (the checkout sends its URL as
 * `return_url`; only URLs of this site are accepted, otherwise the home page), the notice names the
 * order number, and after an aborted or failed payment the buyer can continue the payment or cancel
 * the order, which releases the reserved seats at once instead of holding them until the hold lapses.
 * The actions are authorised by `wpcap_rk`, a keyed hash of the order's public id that only travels
 * in the return URLs (never derivable from the public id alone).
 */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Frontend\Format;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReturnNotice {
	/** Query arguments of the return URLs; stripped from a page URL before it becomes a return base. */
	const ARGS = array( 'wpcap_payment', 'order', 'wpcap_rk', 'wpcap_paypal_return', 'wpcap_mollie_return', 'token', 'PayerID', 'session_id', 'wpcap_portal' );

	/** Whether the notice was printed in this request (wp_body_open, the_content or the event list). */
	private static $shown = false;

	public static function register() {
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
		// A307: themes without wp_body_open (many classic themes): the notice goes in front of the page
		// content, or in front of the event list ([wpcap_events] / block) – once per request.
		add_filter( 'the_content', array( __CLASS__, 'prepend_to_content' ), 5 );
		add_action( 'admin_post_wpcap_payment_return', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_nopriv_wpcap_payment_return', array( __CLASS__, 'handle_action' ) );
	}

	/** Messages only; the order state is never changed here (webhooks/capture do that). */
	public static function message( $state ) {
		$messages = array(
			'success' => __( 'Danke! Deine Zahlung wird bestätigt. Die Tickets kommen in wenigen Minuten per E-Mail.', 'pass-captain' ),
			'pending' => __( 'Deine Zahlung wird noch verarbeitet. Sobald sie bestätigt ist, senden wir dir die Tickets per E-Mail.', 'pass-captain' ),
			'failed'  => __( 'Die Zahlung ist fehlgeschlagen. Es wurde nichts abgebucht; du kannst es erneut versuchen.', 'pass-captain' ),
			'cancel'  => __( 'Die Zahlung wurde abgebrochen. Deine Reservierung verfällt automatisch.', 'pass-captain' ),
			'cancelled' => __( 'Deine Bestellung wurde storniert. Die reservierten Plätze sind wieder frei; es wurde nichts abgebucht.', 'pass-captain' ),
		);
		return $messages[ $state ] ?? '';
	}

	/** Keyed hash of a public order id that authorises the actions on the return page. */
	public static function key( $public_id ) {
		return substr( hash_hmac( 'sha256', 'wpcap-payment-return|' . (string) $public_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * A URL of this site to return to: http(s), same host as the home URL, without fragment and
	 * without the return arguments. Anything else gives the home page.
	 */
	public static function base( $url ) {
		$home = home_url( '/' );
		$url = is_string( $url ) ? trim( $url ) : '';
		$parts = '' !== $url ? parse_url( $url ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing, no WP context needed.
		$home_host = strtolower( (string) parse_url( $home, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== $home_host || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $url ) > 2000 ) {
			return $home;
		}
		$query = array();
		if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
		$query = array_diff_key( $query, array_flip( self::ARGS ) );
		$out = strtolower( $parts['scheme'] ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
		return $query ? $out . '?' . http_build_query( $query ) : $out;
	}

	/** $base with extra query arguments (keeps the base's own arguments). */
	public static function with_args( $base, array $args ) {
		$base = self::base( $base );
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}

	/** Return base of an order array passed to create_payment() (`return_base`, set by the checkout). */
	public static function base_of( array $order ) {
		return self::base( (string) ( $order['return_base'] ?? '' ) );
	}

	/** The standard return URL (?wpcap_payment=…&order=…&wpcap_rk=…) on $base, through `wpcap_payment_return_url`. */
	public static function url( $status, $public_id, $base, $provider ) {
		$url = self::with_args( $base, array( 'wpcap_payment' => (string) $status, 'order' => (string) $public_id, 'wpcap_rk' => self::key( $public_id ) ) );
		return (string) apply_filters( 'wpcap_payment_return_url', $url, $status, $public_id, $provider );
	}

	/** Base of the current request (e.g. the PayPal/Mollie return handler on the checkout page). */
	public static function current_base() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by base().
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with the home host in base().
		$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https' : (string) parse_url( home_url( '/' ), PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return self::base( '' !== $host ? $scheme . '://' . $host . $uri : '' );
	}

	/** Pending order of a return URL, or null (wrong key, unknown order, nothing open any more). */
	private static function open_order( $public_id, $key ) {
		global $wpdb;
		$public_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $public_id ) );
		if ( 32 !== strlen( $public_id ) || ! is_string( $key ) || ! hash_equals( self::key( $public_id ), $key ) ) { return null; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || 'pending_payment' !== $order->status || 'pending' !== $order->payment_status || ! Orders::hold_end( (int) $order->order_id ) ) { return null; }
		return $order;
	}

	/** Whether the keyed order was paid meanwhile (e.g. in another tab). */
	private static function paid( $public_id, $key ) {
		global $wpdb;
		$public_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $public_id ) );
		if ( 32 !== strlen( $public_id ) || ! is_string( $key ) || ! hash_equals( self::key( $public_id ), $key ) ) { return false; }
		return 'paid' === $wpdb->get_var( $wpdb->prepare( "SELECT payment_status FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
	}

	/** Notice HTML for a return state; '' for unknown states. */
	public static function html( $state, $public_id = '', $key = '', $portal = '' ) {
		$message = self::message( $state );
		if ( '' === $message ) { return ''; }
		$color = in_array( $state, array( 'success', 'pending', 'cancelled' ), true ) ? '#146c2e' : '#b32d2e';
		$out = '<div class="wpcap-payment-notice" role="status" style="padding:1rem;margin:1rem;border-left:4px solid ' . esc_attr( $color ) . ';background:#f6f7f7"><p>' . esc_html( $message ) . '</p>';
		$reference = Format::order_reference( (string) $public_id );
		if ( '' !== $reference ) {
			/* translators: %s: short order number */
			$out .= '<p class="wpcap-order-number">' . esc_html( sprintf( __( 'Bestellnummer: %s', 'pass-captain' ), $reference ) ) . '</p>';
		}
		$order = in_array( $state, array( 'cancel', 'failed' ), true ) ? self::open_order( $public_id, $key ) : null;
		if ( $order ) {
			$until = Orders::hold_end( (int) $order->order_id );
			/* translators: %s: time the reservation ends */
			$out .= '<p>' . esc_html( sprintf( __( 'Deine Tickets sind noch bis %s reserviert. Du kannst die Zahlung fortsetzen oder die Bestellung stornieren und die Plätze sofort freigeben.', 'pass-captain' ), wp_date( 'H:i', $until ) ) ) . '</p>';
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-payment-actions">';
			$out .= '<input type="hidden" name="action" value="wpcap_payment_return"><input type="hidden" name="order" value="' . esc_attr( $order->public_id ) . '"><input type="hidden" name="wpcap_rk" value="' . esc_attr( $key ) . '"><input type="hidden" name="back" value="' . esc_attr( self::current_base() ) . '">';
			$out .= '<button type="submit" name="decision" value="resume" class="wp-element-button">' . esc_html__( 'Zahlung fortsetzen', 'pass-captain' ) . '</button> ';
			$out .= '<button type="submit" name="decision" value="cancel" class="wp-element-button">' . esc_html__( 'Bestellung stornieren', 'pass-captain' ) . '</button></form>';
		}
		$out .= self::portal_html( $state, $public_id, $key, $portal );
		return $out . '</div>';
	}

	/**
	 * A307: way to the order portal. The page never shows the personal access link itself (it is only
	 * stored as a hash and travels by e-mail; a return URL can end up in browser histories, proxies or
	 * screenshots). With the keyed return URL the buyer can have a new link sent to the order's e-mail
	 * address (rate-limited; the previous link becomes invalid).
	 */
	private static function portal_html( $state, $public_id, $key, $portal ) {
		$order = self::keyed_order( $public_id, $key );
		if ( ! $order || 'woocommerce' === (string) $order->payment_provider || 'cancelled' === $state ) { return ''; }
		if ( 'sent' === $portal ) {
			/* translators: %s: masked e-mail address */
			return '<p class="wpcap-portal-link" role="status">' . esc_html( sprintf( __( 'Wir haben einen neuen Link zu deiner Bestellung an %s gesendet. Der bisherige Link ist damit ungültig.', 'pass-captain' ), Format::mask_email( (string) $order->customer_email ) ) ) . '</p>';
		}
		if ( 'limited' === $portal ) {
			return '<p class="wpcap-portal-link" role="status">' . esc_html__( 'Der Link wurde gerade erst gesendet. Bitte sieh in deinem Postfach (auch im Spam-Ordner) nach oder versuche es später erneut.', 'pass-captain' ) . '</p>';
		}
		$out = '<p class="wpcap-portal-link">' . esc_html__( 'Tickets, Rechnung und Teilnehmerangaben verwaltest du über den persönlichen Link zu deiner Bestellung, den wir dir per E-Mail gesendet haben.', 'pass-captain' ) . '</p>';
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-portal-request">';
		$out .= '<input type="hidden" name="action" value="wpcap_payment_return"><input type="hidden" name="order" value="' . esc_attr( $order->public_id ) . '"><input type="hidden" name="wpcap_rk" value="' . esc_attr( $key ) . '"><input type="hidden" name="state" value="' . esc_attr( $state ) . '"><input type="hidden" name="back" value="' . esc_attr( self::current_base() ) . '">';
		$out .= '<button type="submit" name="decision" value="portal_link" class="wp-element-button">' . esc_html__( 'Link zur Bestellung erneut per E-Mail senden', 'pass-captain' ) . '</button></form>';
		return $out;
	}

	/** Order of a keyed return URL in any state, or null. */
	private static function keyed_order( $public_id, $key ) {
		global $wpdb;
		$public_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $public_id ) );
		if ( 32 !== strlen( $public_id ) || ! is_string( $key ) || ! hash_equals( self::key( $public_id ), $key ) ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,customer_email,status,payment_provider FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
	}

	/** The notice for the current request, once ('' when there is none or it was printed already). */
	public static function take() {
		if ( self::$shown ) { return ''; }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; actions are POSTed and keyed.
		$state = sanitize_key( wp_unslash( $_GET['wpcap_payment'] ?? '' ) );
		if ( '' === self::message( $state ) ) { return ''; }
		$public_id = sanitize_text_field( wp_unslash( (string) ( $_GET['order'] ?? '' ) ) );
		$key = sanitize_text_field( wp_unslash( (string) ( $_GET['wpcap_rk'] ?? '' ) ) );
		$portal = sanitize_key( wp_unslash( (string) ( $_GET['wpcap_portal'] ?? '' ) ) );
		// phpcs:enable
		// A283: the banner belongs to one buyer. Headers were sent by template_redirect already
		// (PageCache::maybe_no_store_request()); DONOTCACHEPAGE still keeps page-cache plugins away.
		\PassCaptain\PageCache::no_store( 'payment_return' );
		self::$shown = true;
		return self::html( $state, $public_id, $key, $portal );
	}

	/** the_content fallback (A307): main query, in the loop, notice not printed yet. */
	public static function prepend_to_content( $content ) {
		if ( self::$shown || ! function_exists( 'in_the_loop' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
		return self::take() . $content;
	}

	/** Tests: forget that the notice was printed. */
	public static function reset() { self::$shown = false; }

	/** wp_body_open: prints the notice (once per request, see take()). */
	public static function render() {
		echo self::take(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
	}

	/**
	 * admin-post: "Zahlung fortsetzen" (a new or the still open payment session of the same order) or
	 * "Bestellung stornieren" (cancels the pending order, releases the hold, ends an open Stripe/Mollie
	 * session where the gateway supports it), then back to the page.
	 */
	public static function handle_action() {
		$target = self::perform_action( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised by the keyed hash wpcap_rk.
		nocache_headers();
		// The payment page is another host (Stripe, PayPal, Mollie): wp_redirect, not wp_safe_redirect.
		wp_redirect( $target, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- provider URL from the gateway or a URL of this site (base()).
		exit;
	}

	/**
	 * The action itself; returns the redirect target.
	 *
	 * @param array $post order, wpcap_rk, back, decision (resume|cancel).
	 */
	public static function perform_action( array $post ) {
		$public_id = sanitize_text_field( (string) ( $post['order'] ?? '' ) );
		$key = sanitize_text_field( (string) ( $post['wpcap_rk'] ?? '' ) );
		$back = self::base( (string) ( $post['back'] ?? '' ) );
		$decision = sanitize_key( (string) ( $post['decision'] ?? '' ) );
		if ( 'portal_link' === $decision ) { return self::send_portal_link( $public_id, $key, $back, sanitize_key( (string) ( $post['state'] ?? '' ) ) ); }
		$order = self::open_order( $public_id, $key );
		$target = self::url( self::paid( $public_id, $key ) ? 'success' : 'failed', $public_id, $back, 'return' );
		if ( $order && 'cancel' === $decision ) {
			$gateway = Registry::get( (string) $order->payment_provider );
			if ( $gateway && method_exists( $gateway, 'abandon_payment' ) ) { $gateway->abandon_payment( (array) $order ); }
			$cancelled = Orders::cancel_order( (int) $order->order_id, 'buyer_cancelled_on_return', array( 'pending' ) );
			if ( true === $cancelled ) {
				Audit::record( 'order_cancelled_by_buyer', 'order', (int) $order->order_id, array( 'provider' => (string) $order->payment_provider ) );
				$target = self::url( 'cancelled', $order->public_id, $back, 'return' );
			} else {
				$target = self::url( 'cancel', $order->public_id, $back, 'return' );
			}
		} elseif ( $order && 'resume' === $decision ) {
			$gateway = Registry::get( (string) $order->payment_provider );
			$payment = $gateway ? $gateway->create_payment( array( 'return_base' => $back ) + (array) $order, array_map( static function ( $row ) { return (array) $row; }, Orders::items_for_order( (int) $order->order_id ) ) ) : null;
			if ( is_array( $payment ) && ! empty( $payment['redirect_url'] ) && preg_match( '#^https://#i', (string) $payment['redirect_url'] ) ) {
				if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( (int) $order->order_id, $payment['provider_reference'] ); }
				$target = (string) $payment['redirect_url'];
			}
		}
		return $target;
	}

	/** "Link erneut senden" (A307): new access link by e-mail to the order's address, rate-limited. */
	private static function send_portal_link( $public_id, $key, $back, $state ) {
		$state = '' !== self::message( $state ) ? $state : 'pending';
		$order = self::keyed_order( $public_id, $key );
		if ( ! $order || 'woocommerce' === (string) $order->payment_provider ) { return self::url( $state, $public_id, $back, 'return' ); }
		$allowed = \PassCaptain\RateLimit::allow( 'return-portal-client|' . \PassCaptain\RateLimit::client(), 10, HOUR_IN_SECONDS )
			&& \PassCaptain\RateLimit::allow( 'return-portal|' . $order->public_id, 2, HOUR_IN_SECONDS );
		$sent = $allowed && Orders::rotate_access_link( (int) $order->order_id );
		if ( $sent ) { Audit::record( 'order_access_link_resent', 'order', (int) $order->order_id, array( 'source' => 'payment_return' ) ); }
		$url = self::url( $state, $order->public_id, $back, 'return' );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'wpcap_portal=' . ( $sent ? 'sent' : 'limited' );
	}
}
