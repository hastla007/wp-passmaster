<?php
/**
 * A622: "Meine Tickets per E-Mail anfordern" for ticket holders (attendee e-mail, e.g. transfer recipients) who
 * lost their link. Two steps, so nobody can invalidate other people's tickets by typing their address:
 *
 * 1. The holder enters the address (page /?wpcap_recover=1 or REST POST /tickets/recover). The answer is always
 *    the same (no enumeration); if the address holds tickets, it gets a confirmation link (1 hour, single use,
 *    rate limited per client and per address).
 * 2. The link shows a button (POST, so mail link scanners do not trigger it); confirming reissues every valid
 *    future ticket of that holder (Orders::reissue_ticket(): new code, old QR code invalid, mail with the new link
 *    to the holder). Tickets whose admission has begun or whose code is on offline scanners stay as they are
 *    (Admission\CodeRotationLock), staff help with the help code there.
 *
 * Buyers keep using the order mail / order portal; only holder addresses are served here (tickets of the buyer
 * may have been forwarded as PDF and must not change on an anonymous request).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketRecovery {
	const QUERY_VAR = 'wpcap_recover';
	const TTL = HOUR_IN_SECONDS;
	const MAX_TICKETS = 20;

	public static function register() {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = self::QUERY_VAR; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'pass-captain/v1', '/tickets/recover', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => static function ( \WP_REST_Request $request ) {
				$result = self::request( (string) $request->get_param( 'email' ) );
				return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'message' => self::generic_message() ) );
			},
			'permission_callback' => '__return_true',
			'args' => array( 'email' => array( 'required' => true, 'type' => 'string', 'maxLength' => 190 ) ),
		) );
	}

	/** URL of the request form. */
	public static function url() {
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/** The same answer for every address (no enumeration of holders). */
	public static function generic_message() {
		return __( 'Wenn zu dieser Adresse Tickets gehören, erhältst du in wenigen Minuten eine E-Mail mit einem Bestätigungslink.', 'pass-captain' );
	}

	/**
	 * Step 1: send a confirmation link when the address holds tickets that can be reissued.
	 *
	 * @return true|\WP_Error true also when nothing was sent (same answer); WP_Error only for invalid input or limits.
	 */
	public static function request( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'wpcap_recover_email', __( 'Bitte gib eine gültige E-Mail-Adresse ein.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimit::allow_client( 'ticket_recover', (int) apply_filters( 'wpcap_ticket_recover_client_limit', 5 ), HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Zu viele Anfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		// Per address: at most 3 mails a day, counted silently (the answer stays the same).
		if ( ! RateLimit::allow( 'ticket_recover_mail|' . RateLimit::hash( $email ), 3, DAY_IN_SECONDS ) ) { return true; }
		if ( ! self::ticket_ids( $email ) ) { return true; }
		$id = bin2hex( random_bytes( 24 ) );
		set_transient( self::key( $id ), $email, self::TTL );
		$link = add_query_arg( self::QUERY_VAR, $id, home_url( '/' ) );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Deine Tickets bei %s', 'pass-captain' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body = __( 'Du hast neue Links zu deinen Tickets angefordert.', 'pass-captain' ) . "\n\n"
			. __( 'Öffne diesen Link und bestätige, um neue Ticketlinks zu erhalten (gültig für eine Stunde). Die bisherigen QR-Codes werden dabei ungültig:', 'pass-captain' ) . "\n" . esc_url_raw( $link ) . "\n\n"
			. __( 'Wenn du das nicht angefordert hast, ignoriere diese E-Mail; deine Tickets bleiben unverändert.', 'pass-captain' ) . "\n";
		Mail\Mailer::send_plain( $email, $subject, $body );
		Audit::record( 'ticket_recovery_requested', 'ticket', 0, array( 'tickets' => count( self::ticket_ids( $email ) ) ) );
		return true;
	}

	/**
	 * Step 2: reissue the holder's tickets for a confirmation id (single use).
	 *
	 * @return array{reissued:int,skipped:int}|\WP_Error
	 */
	public static function confirm( $id ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $id );
		$email = 48 === strlen( $id ) ? get_transient( self::key( $id ) ) : false;
		if ( ! is_string( $email ) || '' === $email ) {
			return new \WP_Error( 'wpcap_recover_expired', __( 'Dieser Link ist abgelaufen oder wurde schon verwendet. Bitte fordere einen neuen an.', 'pass-captain' ), array( 'status' => 410 ) );
		}
		delete_transient( self::key( $id ) );
		$reissued = 0;
		$skipped = 0;
		foreach ( self::ticket_ids( $email ) as $ticket_id ) {
			if ( class_exists( Admission\CodeRotationLock::class ) && '' !== Admission\CodeRotationLock::reason( $ticket_id ) ) { ++$skipped; continue; }
			$result = Orders::reissue_ticket( $ticket_id, array( 'reason' => 'holder_recovery' ) );
			if ( is_wp_error( $result ) ) { ++$skipped; } else { ++$reissued; }
		}
		return array( 'reissued' => $reissued, 'skipped' => $skipped );
	}

	/**
	 * Valid, unrefunded tickets of paid orders whose holder address is $email and whose date is not over.
	 *
	 * @return int[]
	 */
	public static function ticket_ids( $email ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix, values as placeholders.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id
			WHERE t.attendee_email=%s AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' AND COALESCE(o.ends_at,o.starts_at)>=%s ORDER BY t.ticket_id LIMIT %d",
			strtolower( (string) $email ),
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			self::MAX_TICKETS
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $rows );
	}

	private static function key( $id ) {
		return 'wpcap_recover_' . substr( hash( 'sha256', (string) $id ), 0, 40 );
	}

	/** Public page: request form (?wpcap_recover=1), confirmation (?wpcap_recover=<id>), results. */
	public static function maybe_render() {
		$value = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $value ) { return; }
		nocache_headers();
		if ( function_exists( 'header' ) && ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex' ); }
		echo self::page( $value, Http::is_post() ? wp_unslash( $_POST ) : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Missing -- escaped in page(); the nonce is verified there.
		exit;
	}

	/**
	 * HTML of the page for a query value and POST data (testable without exit).
	 *
	 * @param string $value Query value: '1' (form) or a confirmation id.
	 * @param array  $post  Unslashed POST data.
	 */
	public static function page( $value, array $post = array() ) {
		$notice = '';
		$body = '';
		if ( '1' === $value ) {
			if ( $post ) {
				if ( ! wp_verify_nonce( (string) ( $post['_wpnonce'] ?? '' ), 'wpcap_ticket_recover' ) || '' !== (string) ( $post['website'] ?? '' ) ) {
					$notice = __( 'Die Anfrage konnte nicht geprüft werden. Bitte lade die Seite neu.', 'pass-captain' );
				} else {
					$result = self::request( (string) ( $post['email'] ?? '' ) );
					$notice = is_wp_error( $result ) ? $result->get_error_message() : self::generic_message();
				}
			}
			$body = '<p>' . esc_html__( 'Du hast ein Ticket von jemand anderem erhalten und findest den Link nicht mehr? Gib die Adresse ein, an die das Ticket übertragen wurde.', 'pass-captain' ) . '</p>'
				. '<form method="post"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wpcap_ticket_recover' ) ) . '">'
				. '<p class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>'
				. '<p><label for="wpcap-recover-email">' . esc_html__( 'E-Mail-Adresse', 'pass-captain' ) . '</label><br><input id="wpcap-recover-email" name="email" type="email" required autocomplete="email" maxlength="190"></p>'
				. '<p><button type="submit">' . esc_html__( 'Ticketlinks anfordern', 'pass-captain' ) . '</button></p></form>'
				. '<p class="muted">' . esc_html__( 'Käufer finden ihre Tickets in der Bestellbestätigung und im Bestellportal.', 'pass-captain' ) . '</p>';
		} elseif ( $post ) {
			$result = wp_verify_nonce( (string) ( $post['_wpnonce'] ?? '' ), 'wpcap_ticket_recover_confirm' ) ? self::confirm( $value ) : new \WP_Error( 'wpcap_nonce', __( 'Die Anfrage konnte nicht geprüft werden. Bitte lade die Seite neu.', 'pass-captain' ) );
			if ( is_wp_error( $result ) ) {
				$notice = $result->get_error_message();
			} else {
				/* translators: %d: number of tickets */
				$notice = $result['reissued'] ? sprintf( _n( 'Erledigt: %d neuer Ticketlink ist unterwegs zu dir.', 'Erledigt: %d neue Ticketlinks sind unterwegs zu dir.', $result['reissued'], 'pass-captain' ), $result['reissued'] ) : __( 'Es konnten keine Tickets neu ausgestellt werden.', 'pass-captain' );
				/* translators: %d: number of tickets */
				$notice .= $result['skipped'] ? ' ' . sprintf( _n( '%d Ticket kann jetzt nicht mehr geändert werden (Einlass begonnen); bitte wende dich vor Ort an das Einlasspersonal.', '%d Tickets können jetzt nicht mehr geändert werden (Einlass begonnen); bitte wende dich vor Ort an das Einlasspersonal.', $result['skipped'], 'pass-captain' ), $result['skipped'] ) : '';
			}
		} else {
			$body = '<p>' . esc_html__( 'Bestätige, dass du neue Links zu deinen Tickets erhalten möchtest. Die bisherigen QR-Codes werden dabei ungültig.', 'pass-captain' ) . '</p>'
				. '<form method="post"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wpcap_ticket_recover_confirm' ) ) . '"><p><button type="submit">' . esc_html__( 'Neue Ticketlinks senden', 'pass-captain' ) . '</button></p></form>';
		}
		return '<!doctype html><html ' . Frontend\Format::html_lang_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Tickets anfordern', 'pass-captain' ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:36rem;margin:2rem auto;padding:0 1rem;color:#1d2327}input[type=email]{width:100%;padding:.5rem;font:inherit}button{padding:.6rem 1rem;min-height:2.75rem;font:inherit}.muted{color:#50575e}.hp{position:absolute;left:-9999px}.notice{font-weight:700}</style></head><body><main>'
			. '<h1>' . esc_html__( 'Tickets anfordern', 'pass-captain' ) . '</h1>'
			. ( '' !== $notice ? '<p class="notice" role="status">' . esc_html( $notice ) . '</p>' : '' )
			. $body . '</main></body></html>';
	}
}
