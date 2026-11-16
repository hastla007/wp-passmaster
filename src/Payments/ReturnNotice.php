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
 *
 * Also kept from the parallel implementation (R6): set_base()/base() (return page of the current
 * checkout, carried through the gateways' own return handlers as wpcap_return_to), handler_url(),
 * the return token wpcap_rt (same keyed hash as wpcap_rk, token()), "pay again" (retry()) and
 * "release the reservation" (release()) with their admin-post handlers, and no_cache() for pages with
 * ?wpcap_payment (DONOTCACHEPAGE + nocache headers).
 */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Frontend\Format;
use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReturnNotice {
	/** Query arguments of the return URLs; stripped from a page URL before it becomes a return base. */
	const ARGS = array( 'wpcap_payment', 'order', 'wpcap_rk', 'wpcap_rt', 'wpcap_return_to', 'wpcap_paypal_return', 'wpcap_mollie_return', 'token', 'PayerID', 'session_id', 'wpcap_portal' );

	/** Return page set for the payment pages of the current request (set_base(); '' = none). */
	private static $requested_base = '';

	/** Whether the notice was printed in this request (wp_body_open, the_content or the event list). */
	private static $shown = false;

	public static function register() {
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
		// A307: themes without wp_body_open (many classic themes): the notice goes in front of the page
		// content, or in front of the event list ([wpcap_events] / block) – once per request.
		add_filter( 'the_content', array( __CLASS__, 'prepend_to_content' ), 5 );
		add_action( 'admin_post_wpcap_payment_return', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_nopriv_wpcap_payment_return', array( __CLASS__, 'handle_action' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 0, 0 );
		add_action( 'admin_post_wpcap_payment_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_nopriv_wpcap_payment_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_wpcap_payment_release', array( __CLASS__, 'handle_release' ) );
		add_action( 'admin_post_nopriv_wpcap_payment_release', array( __CLASS__, 'handle_release' ) );
	}

	/** Messages only; the order state is never changed here (webhooks/capture do that). */
	public static function message( $state ) {
		$messages = array(
			'success' => __( 'Danke! Deine Zahlung wird bestätigt. Die Tickets kommen in wenigen Minuten per E-Mail.', 'pass-captain' ),
			'pending' => __( 'Deine Zahlung wird noch verarbeitet. Sobald sie bestätigt ist, senden wir dir die Tickets per E-Mail.', 'pass-captain' ),
			'failed'  => __( 'Die Zahlung ist fehlgeschlagen. Es wurde nichts abgebucht; du kannst es erneut versuchen.', 'pass-captain' ),
			'cancel'  => __( 'Die Zahlung wurde abgebrochen. Deine Reservierung verfällt automatisch.', 'pass-captain' ),
			'cancelled' => __( 'Deine Bestellung wurde storniert. Die reservierten Plätze sind wieder frei; es wurde nichts abgebucht.', 'pass-captain' ),
			'released' => __( 'Deine Reservierung wurde freigegeben. Es wurde nichts abgebucht.', 'pass-captain' ),
			'expired'  => __( 'Deine Reservierung ist abgelaufen, es wurde keine Zahlung eingezogen. Bitte starte die Bestellung erneut.', 'pass-captain' ),
		);
		return $messages[ $state ] ?? '';
	}

	/** Keyed hash of a public order id that authorises the actions on the return page. */
	public static function key( $public_id ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : ABSPATH );
		return substr( hash_hmac( 'sha256', 'wpcap-payment-return|' . (string) $public_id, $salt ), 0, 32 );
	}

	/** Return token of an order (wpcap_rt; the same keyed hash as key()). */
	public static function token( $public_id ) {
		return self::key( $public_id );
	}

	/** Banner state from the request (?wpcap_payment). */
	public static function state() {
		return sanitize_key( wp_unslash( $_GET['wpcap_payment'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/** Key of the current request: wpcap_rk, else the legacy return token wpcap_rt. */
	private static function request_key() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; actions are POSTed and keyed.
		$key = sanitize_text_field( wp_unslash( (string) ( $_GET['wpcap_rk'] ?? '' ) ) );
		if ( '' === $key ) { $key = sanitize_text_field( wp_unslash( (string) ( $_GET['wpcap_rt'] ?? '' ) ) ); }
		// phpcs:enable
		return $key;
	}

	/** template_redirect: a page with ?wpcap_payment shows an order-specific banner – never cache it. */
	public static function no_cache() {
		if ( '' === self::state() ) { return false; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared constant read by page-cache plugins.
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) { nocache_headers(); }
		return true;
	}

	/** Same-site page without the plugin's return parameters, '' when not allowed. */
	public static function sanitize_base( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > 2000 ) { return ''; }
		$url = (string) wp_validate_redirect( esc_url_raw( $url ), '' );
		if ( '' === $url ) { return ''; }
		$url = (string) strtok( $url, '#' );
		return (string) remove_query_arg( self::ARGS, $url );
	}

	/** Sets the return page for the payment pages created in this request. */
	public static function set_base( $url ) {
		self::$requested_base = self::sanitize_base( $url );
	}

	/** URL of a gateway's own return handler that keeps the return page (PayPal capture, Mollie). */
	public static function handler_url( array $args ) {
		$base = self::base();
		if ( $base !== home_url( '/' ) ) { $args['wpcap_return_to'] = $base; }
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * A URL of this site to return to: http(s), same host as the home URL, without fragment and
	 * without the return arguments. Anything else gives the home page.
	 */
	public static function base( $url = null ) {
		if ( null === $url ) {
			// Return page of the current request: set_base(), else ?wpcap_return_to of a gateway return
			// handler, else the home page.
			if ( '' !== self::$requested_base ) { return self::$requested_base; }
			$from_request = isset( $_GET['wpcap_return_to'] ) ? self::sanitize_base( wp_unslash( (string) $_GET['wpcap_return_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in sanitize_base().
			return '' !== $from_request ? $from_request : home_url( '/' );
		}
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
		$given = (string) ( $order['return_base'] ?? '' );
		return self::base( '' !== $given ? $given : self::base() );
	}

	/**
	 * The standard return URL (?wpcap_payment=…&order=…&wpcap_rk=…&wpcap_rt=…) on $base (default: base()),
	 * through `wpcap_payment_return_url`.
	 */
	public static function url( $status, $public_id, $base = null, $provider = '' ) {
		$base = null === $base || '' === $base ? self::base() : $base;
		$key = self::key( $public_id );
		$url = self::with_args( $base, array( 'wpcap_payment' => sanitize_key( (string) $status ), 'order' => (string) $public_id, 'wpcap_rk' => $key, 'wpcap_rt' => $key ) );
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
	public static function html( $state = null, $public_id = '', $key = '', $portal = '' ) {
		if ( null === $state ) {
			// Legacy call without arguments: the state of the current request.
			$state = self::state();
			$public_id = sanitize_text_field( wp_unslash( (string) ( $_GET['order'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$key = self::request_key();
		}
		$message = self::message( $state );
		if ( '' === $message ) { return ''; }
		$color = in_array( $state, array( 'success', 'pending', 'cancelled', 'released' ), true ) ? '#146c2e' : '#b32d2e';
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
		$key = self::request_key();
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

	/** Tests: forget that the notice was printed and the return page of the request. */
	public static function reset() {
		self::$shown = false;
		self::$requested_base = '';
	}

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
		\PassCaptain\Http::end();
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

	/* ------------------------------------------------------------------ pay again / release (R6) */

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
			wp_safe_redirect( self::url( 'failed', $public_id, null, 'return' ) );
			\PassCaptain\Http::end();
		}
		wp_redirect( $result['redirect_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- payment provider page created by the gateway.
		\PassCaptain\Http::end();
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
		wp_safe_redirect( self::url( is_wp_error( $result ) ? 'failed' : 'released', $public_id, null, 'return' ) );
		\PassCaptain\Http::end();
	}
}
