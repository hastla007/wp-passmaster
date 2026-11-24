<?php
/**
 * A400: operational alerts for staff (refund waiting for approval, payment to check, paid order without tickets,
 * webhook disabled …) through one place instead of direct wp_mail() calls to admin_email:
 *
 * - Recipient: setting `alert_email` (Settings → E-Mail-Versand), else the site admin e-mail; filter
 *   `wpcap_alert_recipients` (array of addresses, per topic) routes topics elsewhere or to several people.
 * - Sender: Mail\Mailer::send_plain() (configured sender name/address, envelope sender).
 * - Persistence: the latest alerts are kept (option `wpcap_alerts`, 30 entries, no personal data beyond what the
 *   subject carries) and shown on the status page, so an alert lost in a spam folder is still visible.
 * - Integrations: action `wpcap_alert` (topic, subject, body, context) for chat/ticket systems; filter
 *   `wpcap_alert_send` returning false keeps the mail back (the alert is still stored and the action fires).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Alerts {
	const OPTION = 'wpcap_alerts';
	const KEEP = 30;

	/**
	 * Raises an alert.
	 *
	 * @param string $topic   Short machine name (e.g. "refund_approval", "payment_review").
	 * @param string $subject Mail subject.
	 * @param string $body    Plain-text body.
	 * @param array  $context Extra data for the action/filters (order_id, refund_id …); not mailed.
	 * @return bool Whether a mail was sent to at least one recipient.
	 */
	public static function raise( $topic, $subject, $body, array $context = array() ) {
		$topic = sanitize_key( (string) $topic );
		$subject = (string) $subject;
		$body = (string) $body;
		self::remember( $topic, $subject );
		/**
		 * An operational alert was raised (also when its mail is held back).
		 *
		 * @param string $topic   Topic.
		 * @param string $subject Subject.
		 * @param string $body    Plain-text body.
		 * @param array  $context Context (ids).
		 */
		do_action( 'wpcap_alert', $topic, $subject, $body, $context );
		if ( ! apply_filters( 'wpcap_alert_send', true, $topic, $context ) ) { return false; }
		$sent = false;
		foreach ( self::recipients( $topic, $context ) as $to ) {
			$sent = Mail\Mailer::send_plain( $to, $subject, $body ) || $sent;
		}
		return $sent;
	}

	/**
	 * Recipients of a topic: alert_email setting or admin e-mail, then the filter; only valid addresses.
	 *
	 * @return string[]
	 */
	public static function recipients( $topic = '', array $context = array() ) {
		$configured = trim( (string) Settings::get( 'alert_email' ) );
		$default = '' !== $configured && is_email( $configured ) ? $configured : (string) get_option( 'admin_email' );
		$list = apply_filters( 'wpcap_alert_recipients', array( $default ), (string) $topic, $context );
		$out = array();
		foreach ( (array) $list as $address ) {
			$address = trim( (string) $address );
			if ( '' !== $address && is_email( $address ) && ! isset( $out[ strtolower( $address ) ] ) ) { $out[ strtolower( $address ) ] = $address; }
		}
		return array_values( $out );
	}

	/**
	 * Latest alerts, newest first.
	 *
	 * @return array<int,array{topic:string,subject:string,at:string}>
	 */
	public static function recent( $limit = 10 ) {
		$list = get_option( self::OPTION, array() );
		return array_slice( is_array( $list ) ? $list : array(), 0, max( 1, (int) $limit ) );
	}

	/** Forgets the stored alerts (status page button, tests). */
	public static function clear() {
		update_option( self::OPTION, array(), false );
	}

	private static function remember( $topic, $subject ) {
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		array_unshift( $list, array( 'topic' => $topic, 'subject' => mb_substr( $subject, 0, 200 ), 'at' => Clock::real_mysql() ) );
		update_option( self::OPTION, array_slice( $list, 0, self::KEEP ), false );
	}
}
