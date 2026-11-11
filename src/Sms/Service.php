<?php
/**
 * SMS consent records and the SMS outbox.
 *
 * Nothing is ever sent without an active consent record (order, E.164 number, purpose, consent text
 * version, timestamp). The phone number is stored only together with that consent; withdrawing
 * erases the number. Messages go through the idempotent outbox (dedupe key per purpose/occurrence/order)
 * and are processed on the wpcap_process_messages cron.
 */
namespace PassCaptain\Sms;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const PURPOSE = 'event_updates';
	const MAX_ATTEMPTS = 4;

	/** All provider adapters keyed by id. */
	public static function providers() {
		$list = apply_filters( 'wpcap_sms_providers', array( new TwilioProvider(), new HttpProvider() ) );
		$out = array();
		foreach ( (array) $list as $provider ) {
			if ( $provider instanceof ProviderInterface ) { $out[ $provider->id() ] = $provider; }
		}
		return $out;
	}

	/** The selected and configured provider or null. */
	public static function provider() {
		$settings = (array) Settings::get( 'sms' );
		$all = self::providers();
		$provider = $all[ (string) ( $settings['provider'] ?? '' ) ] ?? null;
		return $provider && $provider->is_configured() ? $provider : null;
	}

	/** Whether the opt-in is offered in the checkout. */
	public static function enabled() {
		return null !== self::provider();
	}

	public static function consent_text() {
		$settings = (array) Settings::get( 'sms' );
		return trim( (string) ( $settings['consent_text'] ?? '' ) );
	}

	/** Version hash of the consent text shown in the checkout. */
	public static function consent_version() {
		return substr( hash( 'sha256', self::consent_text() ), 0, 16 );
	}

	/** Normalizes to E.164 or returns ''. */
	public static function normalize_phone( $raw ) {
		$phone = preg_replace( '/[\s\-\.\/\(\)]/', '', (string) $raw );
		if ( 0 === strpos( $phone, '00' ) ) { $phone = '+' . substr( $phone, 2 ); }
		if ( '' !== $phone && '0' === $phone[0] ) {
			$settings = (array) Settings::get( 'sms' );
			$prefix = (string) ( $settings['default_country_code'] ?? '' );
			if ( preg_match( '/^\+[1-9]\d{0,3}$/', $prefix ) ) { $phone = $prefix . substr( $phone, 1 ); }
		}
		return preg_match( '/^\+[1-9]\d{6,14}$/', $phone ) ? $phone : '';
	}

	/** Stores consent for an order (idempotent per order). */
	public static function record_consent( $order_id, $phone, $text_version = null ) {
		global $wpdb;
		$phone = self::normalize_phone( $phone );
		if ( '' === $phone || ! absint( $order_id ) ) { return false; }
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_sms_consents (order_id,phone,purpose,text_version,consented_at) VALUES (%d,%s,%s,%s,%s)", absint( $order_id ), $phone, self::PURPOSE, null === $text_version ? self::consent_version() : (string) $text_version, current_time( 'mysql', true ) ) );
		return true;
	}

	/** Active consent of an order or null. */
	public static function consent_for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_sms_consents WHERE order_id=%d AND withdrawn_at IS NULL AND phone<>''", absint( $order_id ) ) );
	}

	/** Withdraws consent and erases the number; queued messages are skipped. */
	public static function withdraw( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_consents SET phone='',withdrawn_at=%s WHERE order_id=%d AND withdrawn_at IS NULL", current_time( 'mysql', true ), absint( $order_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='skipped',last_error='consent_withdrawn' WHERE order_id=%d AND status='queued'", absint( $order_id ) ) );
		return true;
	}

	/**
	 * Queues a message for the consenting number of an order.
	 *
	 * @return bool|\WP_Error true when queued (or already queued), false when there is no consent.
	 */
	public static function enqueue( $dedupe, $order_id, $occurrence_id, $purpose, $body, $send_at = '' ) {
		global $wpdb;
		$consent = self::consent_for_order( $order_id );
		if ( ! $consent ) { return false; }
		$now = current_time( 'mysql', true );
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}wpcap_sms_messages (dedupe_key,consent_id,order_id,occurrence_id,purpose,body,status,send_at,created_at,updated_at) VALUES (%s,%d,%d,%d,%s,%s,'queued',%s,%s,%s)",
			hash( 'sha256', (string) $dedupe ), (int) $consent->consent_id, absint( $order_id ), absint( $occurrence_id ), sanitize_key( $purpose ), mb_substr( (string) $body, 0, 1000 ), $send_at ? $send_at : $now, $now, $now
		) );
		return false === $result ? new \WP_Error( 'wpcap_sms_enqueue', 'SMS could not be queued.' ) : true;
	}

	/** Sends due messages (cron). */
	public static function process_due( $limit = 25 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='queued',locked_at=NULL WHERE status='sending' AND locked_at<%s", gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
		$provider = self::provider();
		if ( ! $provider ) { return 0; }
		$sent = 0;
		for ( $i = 0; $i < $limit; $i++ ) {
			$wpdb->query( 'START TRANSACTION' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_sms_messages WHERE status='queued' AND send_at<=%s ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); break; }
			$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now, 'updated_at' => $now ), array( 'message_id' => (int) $row->message_id ) );
			$wpdb->query( 'COMMIT' );
			// Consent is re-checked at send time (it may have been withdrawn meanwhile).
			$consent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_sms_consents WHERE consent_id=%d AND withdrawn_at IS NULL AND phone<>''", (int) $row->consent_id ) );
			$skip = ! $consent ? 'no_consent' : (string) apply_filters( 'wpcap_sms_skip_reason', '', $row );
			if ( '' !== $skip ) {
				$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'skipped', 'locked_at' => null, 'last_error' => substr( $skip, 0, 190 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
				continue;
			}
			$result = $provider->send( $consent->phone, $row->body, 'wpcap-sms-' . $row->message_id );
			if ( is_wp_error( $result ) ) {
				$tries = (int) $row->attempts + 1;
				$data = (array) $result->get_error_data();
				$final = $tries >= self::MAX_ATTEMPTS || ! empty( $data['permanent'] );
				$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => $final ? 'failed' : 'queued', 'provider' => $provider->id(), 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 60 * ( 2 ** $tries ) ), 'locked_at' => null, 'last_error' => substr( $result->get_error_message(), 0, 190 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
				continue;
			}
			$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'sent', 'provider' => $provider->id(), 'provider_ref' => substr( (string) $result['provider_ref'], 0, 64 ), 'locked_at' => null, 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
			++$sent;
		}
		return $sent;
	}

	/** REST: POST /sms/twilio/status – delivery report with X-Twilio-Signature. */
	public static function twilio_status( \WP_REST_Request $request ) {
		$params = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
		if ( ! $params ) { parse_str( (string) $request->get_body(), $params ); }
		$signature = (string) $request->get_header( 'x-twilio-signature' );
		if ( ! TwilioProvider::signature_valid( TwilioProvider::status_callback_url(), $params, $signature, \PassCaptain\Settings::secret( 'twilio_auth_token' ) ) ) {
			return new \WP_Error( 'wpcap_sms_signature', __( 'Ungültige Signatur.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$sid = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $params['MessageSid'] ?? '' ) );
		$status = sanitize_key( $params['MessageStatus'] ?? ( $params['SmsStatus'] ?? '' ) );
		$map = array( 'delivered' => 'delivered', 'undelivered' => 'undelivered', 'failed' => 'failed', 'sent' => 'sent' );
		$updated = 0;
		if ( '' !== $sid && isset( $map[ $status ] ) ) {
			$updated = self::apply_status( 'twilio', $sid, $map[ $status ], sanitize_text_field( (string) ( $params['ErrorCode'] ?? '' ) ) );
		}
		return rest_ensure_response( array( 'received' => true, 'updated' => $updated ) );
	}

	/** Applies a delivery status; final states are never downgraded to "sent". */
	public static function apply_status( $provider, $provider_ref, $status, $error_code = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$data = array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
		if ( '' !== $error_code ) { $data['last_error'] = 'provider_error_' . substr( $error_code, 0, 20 ); }
		$set = array();
		foreach ( $data as $k => $v ) { $set[] = $wpdb->prepare( "$k=%s", $v ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal column names.
		$guard = 'sent' === $status ? " AND status IN ('sending','sent')" : " AND status IN ('sending','sent','delivered','undelivered','failed')";
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET " . implode( ',', $set ) . " WHERE provider=%s AND provider_ref=%s$guard", $provider, $provider_ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Records an audit entry without PII. */
	public static function audit( $action, $order_id, array $details = array() ) {
		Audit::record( $action, 'order', absint( $order_id ), $details );
	}
}
