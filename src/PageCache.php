<?php
/**
 * Full-page caches and CDNs (A283, A508).

 * No store: personal capability pages (ticket, order portal, customer invoice, waitlist offer/
 * unsubscribe, payment return, invoice download, web scanner) call no_store() before any output,
 * also in their 404 branches.

 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PageCache {
	const SCANNER_SHORTCODES = array( 'wp_pass_captain_scanner', 'wpcap_scanner' );

	/** Query parameters of personal pages that must never be stored by a page cache. */
	const NO_STORE_PARAMS = array(
		'wpcap_ticket' => 'ticket',
		'wpcap_order' => 'portal',
		'wpcap_waitlist_accept' => 'waitlist_offer',
		'wpcap_waitlist_unsubscribe' => 'waitlist_unsubscribe',
		'wpcap_invoice' => 'invoice',
		'wpcap_invoice_pdf' => 'invoice_pdf',
		'wpcap_payment' => 'payment_return',
		'wpcap_paypal_return' => 'payment_return',
		'wpcap_mollie_return' => 'payment_return',
	);

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_store_request' ), 0 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * No store (A283, A508).
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Marks the current response as personal: DONOTCACHEPAGE for page-cache plugins (WP Rocket,
	 * LiteSpeed, W3TC, WP Super Cache), nocache_headers() for proxies and CDNs (Cloudflare, Varnish)
	 * and LiteSpeed's own no-cache control. Call it before any output.
	 *
	 * @param string $context Page kind (ticket, portal, waitlist_offer, …).
	 */
	public static function no_store( $context = '' ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) { nocache_headers(); }
		do_action( 'litespeed_control_set_nocache', 'pass-captain ' . (string) $context );
		/**
		 * A personal page of the plugin is being sent and must not be cached (A283).
		 *
		 * @param string $context ticket, portal, waitlist_offer, waitlist_unsubscribe, waitlist_result,
		 *                        payment_return, invoice_pdf, scanner.
		 */
		do_action( 'wpcap_page_no_store', (string) $context );
	}

	/**
	 * Context of a front-end request that must not be cached ('' when it may be cached): one of the
	 * capability query parameters, or a post containing the web scanner shortcode.
	 *
	 * @param array  $query        Query parameters ($_GET).
	 * @param string $post_content Content of the queried singular post ('' otherwise).
	 */
	public static function no_store_context( array $query, $post_content = '' ) {
		foreach ( self::NO_STORE_PARAMS as $param => $context ) {
			if ( isset( $query[ $param ] ) && '' !== Http::str( $query[ $param ] ) ) { return $context; }
		}
		return self::has_scanner( (string) $post_content ) ? 'scanner' : '';
	}

	/** template_redirect (priority 0): no_store() for capability links and scanner pages, before any output. */
	public static function maybe_no_store_request() {
		$content = '';
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post = get_queried_object();
			$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
		}
		$context = self::no_store_context( wp_unslash( $_GET ), $content ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks whether parameters are present.
		if ( '' !== $context ) { self::no_store( $context ); }
	}

	/** Whether content contains the web scanner shortcode. */
	public static function has_scanner( $content ) {
		foreach ( self::SCANNER_SHORTCODES as $tag ) {
			if ( self::contains_shortcode( $content, $tag ) ) { return true; }
		}
		return false;
	}

	private static function contains_shortcode( $content, $tag ) {
		if ( function_exists( 'has_shortcode' ) && function_exists( 'shortcode_exists' ) && shortcode_exists( $tag ) ) {
			return has_shortcode( $content, $tag );
		}
		return false !== strpos( $content, '[' . $tag );
	}
}
