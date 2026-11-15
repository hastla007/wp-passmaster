<?php
/**
 * Personal order access link (order portal): issuing a new access token and mailing the link.
 *
 * First step of splitting the Orders class (audit round 2, expert #1): the logic lives here, Orders keeps
 * its static methods with identical signatures as a facade (Orders::rotate_access_link(),
 * Orders::send_order_access_link()), so add-ons and existing callers are unaffected. The token itself is
 * stored only as SHA-256 hash (orders.access_token_hash) and is valid for ACCESS_DAYS days.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Mail\Mailer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PortalAccess {
	/** Validity of an access link in days. */
	const ACCESS_DAYS = 30;

	/** Issues a new personal order access link (the old link becomes invalid) and emails it. */
	public static function rotate( $order_id ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,public_id,customer_email FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) );
		if ( ! $order ) { return false; }
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_DAYS * DAY_IN_SECONDS ) ), array( 'order_id' => (int) $order->order_id ), array( '%s', '%s' ), array( '%d' ) );
		return self::send( $order->customer_email, $order->public_id, $token );
	}

	/**
	 * Mails the access link of an order (template order_access) in the order's language.
	 *
	 * @param string      $email        Recipient.
	 * @param string      $public_id    Public order id.
	 * @param string      $access_token Plain access token (only in the link, never stored).
	 * @param string|null $locale       Language; null reads the order's locale.
	 */
	public static function send( $email, $public_id, $access_token, $locale = null ) {
		global $wpdb;
		$url = add_query_arg( array( 'wpcap_order' => $public_id, 'wpcap_access' => $access_token ), home_url( '/' ) );
		if ( null === $locale ) {
			$locale = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT locale FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', (string) $public_id ) );
		}
		return Mailer::send( 'order_access', sanitize_email( $email ), array( 'order_id' => (string) $public_id, 'access_link' => Mailer::link( $url ) ), array(), (string) $locale );
	}
}
