<?php
/**
 * Mail suppressions: reminder unsubscribes (link in every reminder, List-Unsubscribe with RFC 8058
 * one-click) and bounce/complaint reports of the sending service (optional webhook).
 *
 * Table wpcap_mail_suppressions (DB 3.17.2) stores only the SHA-256 of the lower-cased address, the
 * scope and the reason – no plain address. Scope "reminders": no more reminder mails to the address
 * (unsubscribe or spam complaint); transactional mails (tickets, cancellations, refunds) are always
 * sent. Reminders are per recipient, not per order: one unsubscribe covers every order the person
 * holds tickets for (the person decides, not the order).
 *
 * Unsubscribe link: ?wpcap_unsubscribe=<address hash>&sig=<HMAC>. GET only shows a confirmation
 * button (link scanners must not unsubscribe anybody); POST (the button, or the mail client's
 * one-click POST "List-Unsubscribe=One-Click") records the opt-out.
 *
 * Bounce webhook (setting mail_bounce_webhook, off by default): POST /wp-pass-captain/v1/mail/bounce
 * with the token (header X-WPCAP-Bounce-Token, or ?token= for services that cannot send headers).
 * Accepted shapes: {"email","type"} (generic), Postmark (RecordType/Email), SendGrid (array of
 * {event,email}), Mailgun ({"event-data":{event,recipient}}), Amazon SES via SNS (bounce/complaint
 * notification). Recent queue messages to the address are marked failed, the orders of the address
 * get an audit entry "mail_bounced" (shown on the order page), complaints also unsubscribe reminders.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Mail;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Suppression {
	const SCOPE_REMINDERS = 'reminders';

	/** Option holding the bounce webhook token. */
	const TOKEN_OPTION = 'wpcap_mail_bounce_token';

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_unsubscribe' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Table schema (Installer). */
	public static function schema( $table, $charset ) {
		return "CREATE TABLE $table (
				email_hash char(64) NOT NULL,
				scope varchar(20) NOT NULL,
				reason varchar(20) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (email_hash,scope)
			) ENGINE=InnoDB $charset;";
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_mail_suppressions';
	}

	/** Hash of an address (lower-cased, trimmed). */
	public static function hash( $email ) {
		return hash( 'sha256', strtolower( trim( (string) $email ) ) );
	}

	/** Whether mails of $scope to $email are suppressed. */
	public static function suppressed( $email, $scope = self::SCOPE_REMINDERS ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT email_hash FROM %i WHERE email_hash=%s AND scope=%s', self::table(), self::hash( $email ), (string) $scope ) );
	}

	/** Records a suppression by address hash (idempotent). */
	public static function suppress_hash( $hash, $scope, $reason ) {
		global $wpdb;
		$hash = preg_match( '/^[a-f0-9]{64}$/', (string) $hash ) ? (string) $hash : '';
		if ( '' === $hash ) { return false; }
		return false !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (email_hash,scope,reason,created_at) VALUES (%s,%s,%s,%s)', self::table(), $hash, sanitize_key( $scope ), sanitize_key( $reason ), current_time( 'mysql', true ) ) );
	}

	/** HMAC of an address hash for the unsubscribe link. */
	private static function signature( $hash ) {
		return hash_hmac( 'sha256', 'wpcap-unsubscribe|' . $hash, function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'wpcap' );
	}

	/** Personal unsubscribe link for reminders. */
	public static function unsubscribe_url( $email ) {
		$hash = self::hash( $email );
		return add_query_arg( array( 'wpcap_unsubscribe' => $hash, 'sig' => self::signature( $hash ) ), home_url( '/' ) );
	}

	/** List-Unsubscribe headers (RFC 2369) with one-click POST (RFC 8058) for a reminder mail. */
	public static function headers( $email ) {
		return array( 'List-Unsubscribe: <' . esc_url_raw( self::unsubscribe_url( $email ) ) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' );
	}

	/**
	 * Unsubscribe page logic: validates the link; POST records the opt-out.
	 *
	 * @return array{status:int,done:bool,valid:bool}
	 */
	public static function handle_unsubscribe( $hash, $sig, $post ) {
		$hash = strtolower( (string) $hash );
		$valid = (bool) preg_match( '/^[a-f0-9]{64}$/', $hash ) && hash_equals( self::signature( $hash ), (string) $sig );
		if ( ! $valid ) { return array( 'status' => 404, 'done' => false, 'valid' => false ); }
		if ( $post ) {
			self::suppress_hash( $hash, self::SCOPE_REMINDERS, 'unsubscribe' );
			Audit::record( 'reminder_unsubscribed', 'system', 0, array( 'source' => 'link' ) );
		}
		return array( 'status' => 200, 'done' => (bool) $post, 'valid' => true );
	}

	/** template_redirect: ?wpcap_unsubscribe=<hash>&sig=<hmac>. */
	public static function maybe_render_unsubscribe() {
		$hash = isset( $_GET['wpcap_unsubscribe'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_unsubscribe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed link is the capability.
		if ( '' === $hash ) { return; }
		$sig = isset( $_GET['sig'] ) ? sanitize_key( wp_unslash( $_GET['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed link.
		$post = 'POST' === strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) );
		$result = self::handle_unsubscribe( $hash, $sig, $post );
		\PassCaptain\Security\PrivatePage::send( true );
		status_header( $result['status'] );
		echo self::page( $hash, $sig, $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/** HTML of the unsubscribe page. */
	public static function page( $hash, $sig, array $result ) {
		$html = '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', function_exists( 'determine_locale' ) ? determine_locale() : 'de_DE' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Erinnerungen abbestellen', 'pass-captain' ) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;color:#1d2327}button{padding:.55rem 1rem;min-height:2.75rem}:focus-visible{outline:3px solid #135e96;outline-offset:3px}</style></head><body><main><h1>' . esc_html__( 'Erinnerungen abbestellen', 'pass-captain' ) . '</h1>';
		if ( ! $result['valid'] ) {
			$html .= '<p>' . esc_html__( 'Der Link ist ungültig.', 'pass-captain' ) . '</p>';
		} elseif ( $result['done'] ) {
			$html .= '<p role="status">' . esc_html__( 'Du erhältst keine Erinnerungs-E-Mails mehr. Tickets und wichtige Informationen zu deinen Bestellungen (z. B. Absagen) senden wir weiterhin.', 'pass-captain' ) . '</p>';
		} else {
			$html .= '<p>' . esc_html__( 'Keine Erinnerungs-E-Mails vor Veranstaltungen mehr erhalten? Tickets und wichtige Informationen zu deinen Bestellungen senden wir weiterhin.', 'pass-captain' ) . '</p>';
			$html .= '<form method="post" action="' . esc_url( add_query_arg( array( 'wpcap_unsubscribe' => $hash, 'sig' => $sig ), home_url( '/' ) ) ) . '"><input type="hidden" name="List-Unsubscribe" value="One-Click"><p><button type="submit">' . esc_html__( 'Erinnerungen abbestellen', 'pass-captain' ) . '</button></p></form>';
		}
		return $html . '</main></body></html>';
	}

	/* ------------------------------------------------------------------ Bounce webhook */

	/** Whether the bounce webhook is enabled (setting mail_bounce_webhook). */
	public static function webhook_enabled() {
		return ! empty( Settings::get( 'mail_bounce_webhook' ) );
	}

	/** Token of the bounce webhook (created on first use). */
	public static function token() {
		$token = (string) get_option( self::TOKEN_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			$token = bin2hex( random_bytes( 24 ) );
			update_option( self::TOKEN_OPTION, $token, false );
		}
		return $token;
	}

	/** Endpoint URL to enter at the sending service (token as query parameter). */
	public static function webhook_url() {
		$base = function_exists( 'rest_url' ) ? rest_url( 'wp-pass-captain/v1/mail/bounce' ) : home_url( '/wp-json/wp-pass-captain/v1/mail/bounce' );
		return add_query_arg( 'token', self::token(), $base );
	}

	public static function routes() {
		register_rest_route( 'wp-pass-captain/v1', '/mail/bounce', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'rest_bounce' ),
			'permission_callback' => '__return_true', // Token checked in the callback (constant time).
		) );
	}

	/** REST: POST /mail/bounce. */
	public static function rest_bounce( \WP_REST_Request $request ) {
		if ( ! self::webhook_enabled() ) {
			return new \WP_Error( 'wpcap_bounce_disabled', __( 'Der Bounce-Webhook ist nicht aktiviert.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$token = (string) $request->get_header( 'x-wpcap-bounce-token' );
		if ( '' === $token ) { $token = (string) $request->get_param( 'token' ); }
		if ( '' === $token || ! hash_equals( self::token(), $token ) ) {
			return new \WP_Error( 'wpcap_bounce_token', __( 'Ungültiges Token.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$data = json_decode( (string) $request->get_body(), true );
		$events = self::parse( is_array( $data ) ? $data : array() );
		$handled = 0;
		foreach ( array_slice( $events, 0, 100 ) as $event ) {
			$handled += self::record( $event['email'], $event['type'] ) ? 1 : 0;
		}
		return function_exists( 'rest_ensure_response' ) ? rest_ensure_response( array( 'handled' => $handled ) ) : array( 'handled' => $handled );
	}

	/**
	 * Normalised events of a provider payload.
	 *
	 * @return array[] {email, type: bounce|complaint}
	 */
	public static function parse( array $data ) {
		$out = array();
		$add = static function ( $email, $type ) use ( &$out ) {
			$email = sanitize_email( (string) $email );
			$type = strtolower( (string) $type );
			$map = array( 'bounce' => 'bounce', 'hardbounce' => 'bounce', 'bounced' => 'bounce', 'dropped' => 'bounce', 'failed' => 'bounce', 'permanent' => 'bounce', 'complaint' => 'complaint', 'spamcomplaint' => 'complaint', 'spamreport' => 'complaint', 'complained' => 'complaint' );
			if ( is_email( $email ) && isset( $map[ $type ] ) ) { $out[] = array( 'email' => $email, 'type' => $map[ $type ] ); }
		};
		if ( isset( $data['Type'], $data['Message'] ) && 'Notification' === $data['Type'] ) {
			// Amazon SES via SNS: the notification is a JSON string.
			$ses = json_decode( (string) $data['Message'], true );
			$kind = strtolower( (string) ( $ses['notificationType'] ?? '' ) );
			$list = 'complaint' === $kind ? (array) ( $ses['complaint']['complainedRecipients'] ?? array() ) : (array) ( $ses['bounce']['bouncedRecipients'] ?? array() );
			if ( 'bounce' === $kind && 'Permanent' !== ( $ses['bounce']['bounceType'] ?? '' ) ) { return array(); }
			foreach ( $list as $r ) { $add( $r['emailAddress'] ?? '', $kind ); }
			return $out;
		}
		if ( isset( $data['event-data'] ) ) { // Mailgun.
			$e = (array) $data['event-data'];
			if ( 'failed' === ( $e['event'] ?? '' ) && 'temporary' === ( $e['severity'] ?? '' ) ) { return array(); }
			$add( $e['recipient'] ?? '', $e['event'] ?? '' );
			return $out;
		}
		if ( isset( $data['RecordType'] ) ) { // Postmark.
			if ( 'Bounce' === $data['RecordType'] && ! in_array( (string) ( $data['Type'] ?? 'HardBounce' ), array( 'HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'SpamNotification' ), true ) ) { return array(); }
			$add( $data['Email'] ?? '', 'SpamComplaint' === $data['RecordType'] ? 'complaint' : 'bounce' );
			return $out;
		}
		if ( array_is_list( $data ) ) { // SendGrid: list of events.
			foreach ( $data as $e ) {
				if ( is_array( $e ) ) { $add( $e['email'] ?? '', $e['event'] ?? '' ); }
			}
			return $out;
		}
		$add( $data['email'] ?? '', $data['type'] ?? 'bounce' ); // Generic.
		return $out;
	}

	/**
	 * Applies one bounce/complaint: recent queue messages to the address are marked failed, the
	 * address' orders of the last 90 days get the audit entry mail_bounced, complaints unsubscribe
	 * reminders.
	 */
	public static function record( $email, $type ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) { return false; }
		$type = 'complaint' === $type ? 'complaint' : 'bounce';
		$since = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='failed',last_error=%s WHERE recipient=%s AND status IN ('sent','queued') AND created_at>=%s", $p . 'wpcap_message_queue', 'complaint' === $type ? 'Spam complaint reported by the mail service.' : 'Bounce reported by the mail service.', $email, $since ) );
		if ( 'complaint' === $type ) { self::suppress_hash( self::hash( $email ), self::SCOPE_REMINDERS, 'complaint' ); }
		$orders = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE customer_email=%s AND created_at>=%s ORDER BY order_id DESC LIMIT 20', $p . 'wpcap_orders', $email, gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		foreach ( (array) $orders as $order_id ) {
			Audit::record( 'mail_bounced', 'order', (int) $order_id, array( 'type' => $type ) );
		}
		return true;
	}

	/**
	 * Latest bounce/complaint reported for an order (order page).
	 *
	 * @return array|null {type, at}
	 */
	public static function bounce_for_order( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT details,created_at FROM %i WHERE object_type='order' AND object_id=%d AND action='mail_bounced' ORDER BY audit_id DESC LIMIT 1", $wpdb->prefix . 'wpcap_audit_logs', absint( $order_id ) ) );
		if ( ! $row ) { return null; }
		$details = json_decode( (string) $row->details, true );
		return array( 'type' => (string) ( $details['type'] ?? 'bounce' ), 'at' => (string) $row->created_at );
	}
}
