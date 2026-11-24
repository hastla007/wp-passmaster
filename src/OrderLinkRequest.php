<?php
/**
 * "Tickets/Bestelllink anfordern" for buyers: the buyer enters the order e-mail address and receives one mail
 * with fresh personal links to their recent orders (order portal, tickets, invoices).
 *
 * - Page /?wpcap_order_link=1 and REST POST /orders/link-request; the same form is part of the page shown for
 *   an expired or invalid order link (OrderPortal).
 * - The answer is always the same, whether or not the address has orders (no account enumeration); only input
 *   errors and the per-client limit are reported. Per address at most 3 mails a day, counted silently.
 * - The new links are pending links (A631): the buyer's current link stays valid until a new one is opened,
 *   so typing someone else's address never locks that person out. The links go only to the order address.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrderLinkRequest {
	const QUERY_VAR = 'wpcap_order_link';
	/** Most recent orders listed in one mail. */
	const MAX_ORDERS = 10;
	/** Mails per address and day (silent). */
	const ADDRESS_LIMIT = 3;
	/** Payment states whose orders get a link (open bank transfers, paid and refunded orders). */
	const PAYMENT_STATES = array( 'pending', 'paid', 'paid_unallocated', 'refunded' );

	public static function register() {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = self::QUERY_VAR; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'pass-captain/v1', '/orders/link-request', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => static function ( \WP_REST_Request $request ) {
				$result = self::request( (string) $request->get_param( 'email' ) );
				return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'message' => self::generic_message() ) );
			},
			'permission_callback' => '__return_true', // Public form: same answer for every address, rate limited.
			'args' => array( 'email' => array( 'required' => true, 'type' => 'string', 'maxLength' => 190 ) ),
		) );
	}

	/** URL of the request page. */
	public static function url() {
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/** The same answer for every address. */
	public static function generic_message() {
		return __( 'Wenn zu dieser Adresse Bestellungen gehören, erhältst du in wenigen Minuten eine E-Mail mit neuen Links zu deinen Bestellungen und Tickets.', 'pass-captain' );
	}

	/**
	 * Sends the links when the address has orders.
	 *
	 * @return true|\WP_Error true also when nothing was sent (same answer); WP_Error only for invalid input or the client limit.
	 */
	public static function request( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		if ( '' === $email || ! is_email( $email ) || Http::email_too_long( $email ) ) {
			return new \WP_Error( 'wpcap_order_link_email', __( 'Bitte gib eine gültige E-Mail-Adresse ein.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimit::allow_client( 'order_link_request', (int) apply_filters( 'wpcap_order_link_request_client_limit', 5 ), HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Zu viele Anfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		if ( Mail\Mailer::is_placeholder( $email ) ) { return true; }
		if ( ! RateLimit::allow( 'order_link_request_mail|' . RateLimit::hash( $email ), self::ADDRESS_LIMIT, DAY_IN_SECONDS ) ) { return true; }
		$orders = self::orders( $email );
		if ( ! $orders ) { return true; }
		self::send( $email, $orders );
		return true;
	}

	/**
	 * Recent orders of an address that get a link (newest first).
	 *
	 * @return object[] order_id, public_id, created_at, locale.
	 */
	public static function orders( $email ) {
		global $wpdb;
		$states = self::PAYMENT_STATES;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, values as placeholders.
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id,public_id,created_at,locale FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s AND status<>'expired' AND payment_status IN (" . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ') ORDER BY order_id DESC LIMIT %d',
			array_merge( array( strtolower( (string) $email ) ), $states, array( self::MAX_ORDERS ) )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Issues a pending link per order and mails the list (in the language of the newest order). */
	private static function send( $email, array $orders ) {
		$text = '';
		$html = '<ul>';
		$locale = (string) ( $orders[0]->locale ?? '' );
		$lines = Mail\Mailer::with_locale( $locale, static function () use ( $orders ) {
			$lines = array();
			foreach ( $orders as $order ) {
				$token = Orders::issue_access_token( (int) $order->order_id, true );
				if ( '' === $token ) { continue; }
				$events = self::event_names( (int) $order->order_id );
				$lines[] = array(
					'order_id' => (int) $order->order_id,
					'head' => Frontend\Format::order_reference( (string) $order->public_id ) . ' (' . Frontend\Format::local( (string) $order->created_at, null, 'date' ) . ')' . ( $events ? ': ' . implode( ', ', $events ) : '' ),
					'link' => Mail\Mailer::link( PortalSelfService::portal_url( (string) $order->public_id, $token ) ),
				);
			}
			return $lines;
		} );
		foreach ( (array) $lines as $line ) {
			$text .= $line['head'] . "\n" . $line['link']['text'] . "\n\n";
			$html .= '<li>' . esc_html( $line['head'] ) . '<br>' . $line['link']['html'] . '</li>';
		}
		if ( ! $lines ) { return false; }
		$sent = Mail\Mailer::send( 'order_links', $email, array(
			'order_count' => count( $lines ),
			'order_list' => Mail\Mailer::block( $text, $html . '</ul>' ),
			'valid_days' => Orders::pending_link_ready() ? Orders::PENDING_LINK_DAYS : Orders\PortalAccess::ACCESS_DAYS,
		), array(), $locale, (int) $lines[0]['order_id'] );
		foreach ( $lines as $line ) {
			Audit::record( 'order_link_requested', 'order', (int) $line['order_id'], array( 'source' => 'email_form', 'sent' => (bool) $sent ) );
		}
		return $sent;
	}

	/** Event names of an order (at most three). */
	private static function event_names( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT e.name FROM {$p}wpcap_order_items i INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=i.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE i.order_id=%d LIMIT 3", absint( $order_id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
	}

	/* ------------------------------------------------------------------ Pages */

	public static function maybe_render() {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) { return; }
		PageCache::no_store( 'order_link_request' );
		if ( function_exists( 'header' ) && ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex' ); }
		echo self::page( Http::is_post() ? wp_unslash( $_POST ) : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Missing -- escaped in page(); the nonce is verified in handle_post().
		Http::end();
	}

	/**
	 * Notice for a submitted form ('' without POST data).
	 *
	 * @param array $post Unslashed POST data.
	 */
	public static function handle_post( array $post ) {
		if ( ! $post || ! isset( $post['wpcap_order_link_email'] ) ) { return ''; }
		if ( ! wp_verify_nonce( (string) ( $post['_wpnonce'] ?? '' ), 'wpcap_order_link_request' ) || '' !== (string) ( $post['website'] ?? '' ) ) {
			return __( 'Die Anfrage konnte nicht geprüft werden. Bitte lade die Seite neu.', 'pass-captain' );
		}
		$result = self::request( (string) $post['wpcap_order_link_email'] );
		return is_wp_error( $result ) ? $result->get_error_message() : self::generic_message();
	}

	/** The request form (posts to the request page). */
	public static function form_html() {
		return '<form method="post" action="' . esc_url( self::url() ) . '" class="wpcap-order-link-request"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wpcap_order_link_request' ) ) . '">'
			. '<p class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>'
			. '<p><label for="wpcap-order-link-email">' . esc_html__( 'E-Mail-Adresse deiner Bestellung', 'pass-captain' ) . '</label><br><input id="wpcap-order-link-email" name="wpcap_order_link_email" type="email" required autocomplete="email" maxlength="190"></p>'
			. '<p><button type="submit">' . esc_html__( 'Neue Links anfordern', 'pass-captain' ) . '</button></p></form>';
	}

	/**
	 * Request page (form and result).
	 *
	 * @param array $post Unslashed POST data.
	 */
	public static function page( array $post = array() ) {
		$notice = self::handle_post( $post );
		$body = '<p>' . esc_html__( 'Du findest die E-Mail mit deinen Tickets oder den Link zu deiner Bestellung nicht mehr? Gib die E-Mail-Adresse ein, mit der du bestellt hast. Wir senden dir neue Links zu deinen Bestellungen.', 'pass-captain' ) . '</p>'
			. self::form_html()
			. '<p class="muted">' . esc_html__( 'Du hast ein Ticket von jemand anderem erhalten?', 'pass-captain' ) . ' <a href="' . esc_url( TicketRecovery::url() ) . '">' . esc_html__( 'Ticketlinks für übertragene Tickets anfordern', 'pass-captain' ) . '</a></p>';
		$html = self::document( __( 'Tickets/Bestelllink anfordern', 'pass-captain' ), $notice, $body );
		// A407: theme override pass-captain/order-link-request.php.
		return PublicEvents::page( 'order-link-request.php', $html, array( 'notice' => $notice, 'step' => 'request' ) );
	}

	/** Page for an expired or invalid order link (status 404 set by the caller), with the request form. */
	public static function invalid_link_page() {
		$body = '<p>' . esc_html__( 'Dieser Link zu deiner Bestellung ist abgelaufen oder nicht mehr gültig, zum Beispiel weil inzwischen ein neuer Link gesendet oder die E-Mail-Adresse der Bestellung geändert wurde.', 'pass-captain' ) . '</p>'
			. '<p>' . esc_html__( 'Deine Tickets bleiben davon unberührt. Fordere hier einen neuen Link an:', 'pass-captain' ) . '</p>'
			. self::form_html()
			. '<p class="muted">' . esc_html__( 'Du hast ein Ticket von jemand anderem erhalten?', 'pass-captain' ) . ' <a href="' . esc_url( TicketRecovery::url() ) . '">' . esc_html__( 'Ticketlinks für übertragene Tickets anfordern', 'pass-captain' ) . '</a></p>';
		$html = self::document( __( 'Link nicht mehr gültig', 'pass-captain' ), '', $body );
		// A407: theme override pass-captain/order-link-invalid.php.
		return PublicEvents::page( 'order-link-invalid.php', $html, array() );
	}

	private static function document( $title, $notice, $body ) {
		return '<!doctype html><html ' . Frontend\Format::html_lang_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:36rem;margin:2rem auto;padding:0 1rem;color:#1d2327}input[type=email]{width:100%;padding:.5rem;font:inherit;box-sizing:border-box}button{padding:.6rem 1rem;min-height:2.75rem;font:inherit}.muted{color:#50575e}.hp{position:absolute;left:-9999px}.notice{font-weight:700}</style></head><body><main>'
			. '<h1>' . esc_html( $title ) . '</h1>'
			. ( '' !== $notice ? '<p class="notice" role="status">' . esc_html( $notice ) . '</p>' : '' )
			. $body . '</main></body></html>';
	}
}
