<?php
/**
 * Personal order access link (order portal): issuing a new access token and mailing the link.
 *
 * First step of splitting the Orders class (audit round 2, expert #1). After merging the Arbeitsliste line the
 * implementation lives in Orders::rotate_access_link() / Orders::send_order_access_link() again: it also knows the
 * pending link of A631 (a new link that only replaces the current one when it is first used) and clears it on
 * rotation. This class stays as a thin entry point with the same signatures, so there is exactly one
 * implementation. The token itself is stored only as SHA-256 hash (orders.access_token_hash) and is valid for
 * ACCESS_DAYS days.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PortalAccess {
	/** Validity of an access link in days. */
	const ACCESS_DAYS = 30;

	/** Issues a new personal order access link (the old link becomes invalid) and emails it. */
	public static function rotate( $order_id ) {
		return Orders::rotate_access_link( $order_id );
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
		return Orders::send_order_access_link( $email, $public_id, $access_token, $locale );
	}
}
