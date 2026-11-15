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

	/** Number confirmed with the SMS code (verified_at = time of the correct code). */
	const VERIFIED = 'verified';
	/** Recorded while code verification was switched off. */
	const UNVERIFIED = 'unverified';
	/** Consent recorded before code verification existed (DB < 3.15.2); stays valid. */
	const LEGACY = 'legacy';

	/**
	 * Stores consent for an order (idempotent per order).
	 *
	 * @param string      $verification VERIFIED or UNVERIFIED (default).
	 * @param string|null $verified_at  UTC time the code was confirmed (VERIFIED only).
	 */
	public static function record_consent( $order_id, $phone, $text_version = null, $verification = self::UNVERIFIED, $verified_at = null ) {
		global $wpdb;
		$phone = self::normalize_phone( $phone );
		if ( '' === $phone || ! absint( $order_id ) ) { return false; }
		$verification = self::VERIFIED === $verification && $verified_at ? self::VERIFIED : self::UNVERIFIED;
		$table = $wpdb->prefix . 'wpcap_sms_consents';
		$version = null === $text_version ? self::consent_version() : (string) $text_version;
		$now = current_time( 'mysql', true );
		if ( self::VERIFIED === $verification ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (order_id,phone,purpose,text_version,consented_at,verification,verified_at) VALUES (%d,%s,%s,%s,%s,%s,%s)', $table, absint( $order_id ), $phone, self::PURPOSE, $version, $now, self::VERIFIED, (string) $verified_at ) );
		} else {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (order_id,phone,purpose,text_version,consented_at,verification) VALUES (%d,%s,%s,%s,%s,%s)', $table, absint( $order_id ), $phone, self::PURPOSE, $version, $now, self::UNVERIFIED ) );
		}
		return true;
	}

	/**
	 * SQL condition (literal, alias-free) for consents that may receive messages: with code
	 * verification on, only confirmed numbers and the legacy stock; unconfirmed opt-ins get nothing
	 * but the code itself.
	 */
	public static function sendable_condition() {
		return Verification::required() ? " AND verification IN ('" . self::VERIFIED . "','" . self::LEGACY . "')" : '';
	}

	/** Active (and, with verification on, confirmed or legacy) consent of an order or null. */
	public static function consent_for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND withdrawn_at IS NULL AND retired_at IS NULL AND phone<>''" . self::sendable_condition(), $wpdb->prefix . 'wpcap_sms_consents', absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal condition.
	}

	/** Country calling codes numbers may belong to (setting sms.allowed_prefixes, default +43,+49,+41). */
	public static function allowed_prefixes() {
		$settings = (array) Settings::get( 'sms' );
		$raw = (string) ( $settings['allowed_prefixes'] ?? '+43,+49,+41' );
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $prefix ) {
			if ( preg_match( '/^\+[1-9]\d{0,3}$/', $prefix ) ) { $out[] = $prefix; }
		}
		return $out;
	}

	/**
	 * Mobile ranges for countries with known numbering plans. Premium, shared-cost, service and
	 * landline ranges of these countries are rejected; other allowed prefixes are only checked by prefix.
	 */
	const MOBILE_PATTERNS = array(
		'+49' => '/^\+491[5-7]\d{8,9}$/',
		'+43' => '/^\+436[5-9]\d{6,10}$/',
		'+41' => '/^\+417[5-9]\d{7}$/',
	);

	/** Whether an E.164 number may receive SMS (allowed country and, where known, a mobile range). */
	public static function number_allowed( $e164 ) {
		$e164 = (string) $e164;
		foreach ( self::allowed_prefixes() as $prefix ) {
			if ( 0 !== strpos( $e164, $prefix ) ) { continue; }
			return isset( self::MOBILE_PATTERNS[ $prefix ] ) ? 1 === preg_match( self::MOBILE_PATTERNS[ $prefix ], $e164 ) : true;
		}
		return false;
	}

	/** Consents recorded for a number in the last 24 hours. */
	public static function recent_consents_for_number( $e164 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE phone=%s AND consented_at>%s', $wpdb->prefix . 'wpcap_sms_consents', (string) $e164, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	/** Withdraws consent and erases the number; queued messages are skipped. */
	public static function withdraw( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET phone='',withdrawn_at=%s WHERE order_id=%d AND (withdrawn_at IS NULL OR phone<>'')", $p . 'wpcap_sms_consents', current_time( 'mysql', true ), absint( $order_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='skipped',last_error='consent_withdrawn' WHERE order_id=%d AND status IN ('queued','sending')", $p . 'wpcap_sms_messages', absint( $order_id ) ) );
		return $changed > 0;
	}

	/** Withdraws every consent of a number (inbound STOP). Returns the number of consents withdrawn. */
	public static function withdraw_phone( $e164 ) {
		global $wpdb;
		$orders = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE phone=%s', $wpdb->prefix . 'wpcap_sms_consents', (string) $e164 ) );
		foreach ( $orders as $order_id ) { self::withdraw( (int) $order_id ); }
		return count( $orders );
	}

	/**
	 * Ends a consent because the order no longer needs messages (cancelled, fully refunded, revoked,
	 * event over). Queued messages are skipped except cancellation notices; the number is erased as
	 * soon as nothing is queued for it any more.
	 */
	public static function retire( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$changed = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET retired_at=%s WHERE order_id=%d AND retired_at IS NULL AND withdrawn_at IS NULL', $p . 'wpcap_sms_consents', current_time( 'mysql', true ), absint( $order_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='skipped',last_error='consent_retired' WHERE order_id=%d AND status='queued' AND purpose<>'cancellation'", $p . 'wpcap_sms_messages', absint( $order_id ) ) );
		self::erase_retired_numbers();
		return $changed > 0;
	}

	/** Data minimisation: erase numbers of retired consents without queued messages. */
	public static function erase_retired_numbers() {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i c SET c.phone='' WHERE c.retired_at IS NOT NULL AND c.phone<>'' AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.consent_id=c.consent_id AND m.status IN ('queued','sending'))", $p . 'wpcap_sms_consents', $p . 'wpcap_sms_messages' ) );
	}

	/** Retires consents of settled orders whose events are over (one day after the last start). */
	public static function retire_finished() {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT c.order_id FROM %i c INNER JOIN %i ord ON ord.order_id=c.order_id WHERE c.retired_at IS NULL AND c.withdrawn_at IS NULL AND ord.payment_status<>'pending' AND NOT EXISTS (SELECT 1 FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=c.order_id AND t.status IN ('valid','checked_in') AND o.status='scheduled' AND o.starts_at>%s) LIMIT 200", $p . 'wpcap_sms_consents', $p . 'wpcap_orders', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		foreach ( $ids as $order_id ) { self::retire( (int) $order_id ); }
		return count( $ids );
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
			"INSERT IGNORE INTO %i (dedupe_key,consent_id,order_id,occurrence_id,purpose,body,status,send_at,created_at,updated_at) VALUES (%s,%d,%d,%d,%s,%s,'queued',%s,%s,%s)", $wpdb->prefix . 'wpcap_sms_messages',
			hash( 'sha256', (string) $dedupe ), (int) $consent->consent_id, absint( $order_id ), absint( $occurrence_id ), sanitize_key( $purpose ), mb_substr( (string) $body, 0, 1000 ), $send_at ? $send_at : $now, $now, $now
		) );
		return false === $result ? new \WP_Error( 'wpcap_sms_enqueue', 'SMS could not be queued.' ) : true;
	}

	/** Sends due messages (cron). */
	public static function process_due( $limit = 25 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		// Claims of crashed workers go back to the queue; attempts are counted at claim time, so a message
		// that kills the worker every time ends as failed instead of being retried forever.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=IF(attempts>=%d,'failed','queued'),locked_at=NULL WHERE status='sending' AND locked_at<%s", $p . 'wpcap_sms_messages', self::MAX_ATTEMPTS, gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
		self::retire_finished();
		Verification::prune();
		$provider = self::provider();
		if ( ! $provider ) { self::erase_retired_numbers(); \PassCaptain\Ops\Heartbeat::beat( 'sms' ); return 0; }
		$sent = 0;
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : 25;
		// Shared cron time budget: checked before each claim, so a claimed message is always processed.
		$deadline = \PassCaptain\Jobs\TimeBudget::deadline();
		$table = "{$p}wpcap_sms_messages";
		$claimed = 0;
		$budget_hit = false;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( \PassCaptain\Jobs\TimeBudget::exhausted( $deadline ) ) { $budget_hit = true; break; }
			if ( $claimed ) { \PassCaptain\Jobs\TimeBudget::finished( $table, $claimed ); }
			$claimed = 0;
			// The SMS is only sent once the claim is durable: transaction started, exactly this row moved
			// from queued to sending (attempt counted), commit succeeded. Otherwise a parallel worker could
			// send it again. On a database error the run ends; the next run retries.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { break; }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status='queued' AND send_at<=%s ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $p . 'wpcap_sms_messages', $now ) );
			if ( ! $row ) {
				if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); } else { $wpdb->query( 'COMMIT' ); }
				break;
			}
			$updated = $wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now, 'updated_at' => $now ), array( 'message_id' => (int) $row->message_id, 'status' => 'queued' ) );
			if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); break; }
			if ( 1 !== (int) $updated ) { $wpdb->query( 'ROLLBACK' ); continue; }
			if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); break; }
			$claimed = (int) $row->message_id;
			\PassCaptain\Jobs\TimeBudget::claimed( $table, 'message_id', $claimed, 'sending', 'queued', 'failed', self::MAX_ATTEMPTS, array( 'due' => 'send_at', 'error' => 'last_error' ) );
			try {
				// Consent is re-checked at send time (it may have been withdrawn meanwhile).
				$consent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE consent_id=%d AND withdrawn_at IS NULL AND phone<>''" . self::sendable_condition(), $p . 'wpcap_sms_consents', (int) $row->consent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal condition.
				$skip = ! $consent ? 'no_consent' : (string) apply_filters( 'wpcap_sms_skip_reason', '', $row );
				if ( '' !== $skip ) {
					$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'skipped', 'locked_at' => null, 'last_error' => substr( $skip, 0, 190 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
					continue;
				}
				$result = $provider->send( $consent->phone, $row->body, 'wpcap-sms-' . $row->message_id );
			} catch ( \Throwable $e ) {
				// A throwing provider or filter must not stop the SMS queue: retried with backoff like a failed send.
				$result = \PassCaptain\Jobs::exception_error( $e, $row );
			}
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
		if ( $claimed ) { \PassCaptain\Jobs\TimeBudget::finished( $table, $claimed ); }
		if ( $budget_hit ) { \PassCaptain\Jobs\TimeBudget::continue_later( 'wpcap_process_messages' ); }
		self::erase_retired_numbers();
		\PassCaptain\Ops\Heartbeat::beat( 'sms', $sent ); // System status: last completed worker run.
		return $sent;
	}

	/** REST: POST /sms/twilio/status – delivery report with X-Twilio-Signature. */
	public static function twilio_status( \WP_REST_Request $request ) {
		$params = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
		if ( ! $params ) { parse_str( (string) $request->get_body(), $params ); }
		$signature = (string) $request->get_header( 'x-twilio-signature' );
		if ( ! TwilioProvider::signature_valid( TwilioProvider::status_callback_url(), $params, $signature, \PassCaptain\Settings::secret( 'twilio_auth_token' ) ) ) {
			return new \WP_Error( 'wpcap_sms_signature', __( 'Ungültige Signatur.', 'pass-captain' ), array( 'status' => 403 ) );
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

	/** Opt-out keywords for inbound SMS. */
	const STOP_WORDS = array( 'STOP', 'STOPP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'ABMELDEN', 'ENDE' );

	/** REST: POST /sms/twilio/inbound – inbound SMS; a STOP keyword withdraws all consents of the sender. */
	public static function twilio_inbound( \WP_REST_Request $request ) {
		$params = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
		if ( ! $params ) { parse_str( (string) $request->get_body(), $params ); }
		if ( ! TwilioProvider::signature_valid( TwilioProvider::inbound_url(), $params, (string) $request->get_header( 'x-twilio-signature' ), \PassCaptain\Settings::secret( 'twilio_auth_token' ) ) ) {
			return new \WP_Error( 'wpcap_sms_signature', __( 'Ungültige Signatur.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$word = strtoupper( trim( (string) ( $params['Body'] ?? '' ) ) );
		$from = self::normalize_phone( (string) ( $params['From'] ?? '' ) );
		$withdrawn = 0;
		if ( '' !== $from && in_array( $word, self::STOP_WORDS, true ) ) {
			$withdrawn = self::withdraw_phone( $from );
			if ( $withdrawn ) { Audit::record( 'sms_consent_withdrawn', 'sms', 0, array( 'source' => 'inbound_stop', 'consents' => $withdrawn ) ); }
		}
		return rest_ensure_response( array( 'received' => true, 'withdrawn' => $withdrawn ) );
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

}
