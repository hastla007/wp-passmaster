<?php
/**
 * SMS consent records and the SMS outbox.
 *
 * Nothing is ever sent without an active consent record (order, E.164 number, purpose, consent text
 * version, source, timestamp). The phone number is stored only together with that consent;
 * withdrawing erases the number and records the withdrawal source. An inbound STOP puts the number
 * (as SHA-256 hash) on the opt-out list: no consent is recorded or used for it until START.
 * Messages go through the idempotent outbox (dedupe key per purpose/occurrence/order) and are
 * processed on the wpcap_process_messages cron; each message records segments, destination country
 * and cost (see Costs), and the monthly budget can hold messages back.
 */
namespace PassCaptain\Sms;

use PassCaptain\LegacySlug;
use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const PURPOSE = 'event_updates';
	const MAX_ATTEMPTS = 4;
	/** Messages held by the budget stop are skipped after this many days. */
	const HELD_DAYS = 7;

	/** Opt-out keywords for inbound SMS. */
	const STOP_WORDS = array( 'STOP', 'STOPP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'ABMELDEN', 'ENDE' );

	/** Opt-in keywords (undo an earlier STOP). */
	const START_WORDS = array( 'START', 'UNSTOP', 'ANMELDEN' );

	/**
	 * Schema 3.10.2 present (segments/costs, consent source, opt-out list). Older schema during a
	 * deferred upgrade: costs and opt-outs off, consents recorded without source. Only true is cached.
	 */
	public static function ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_sms_messages LIKE 'cost_source'" )
				&& null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_sms_consents LIKE 'withdrawn_source'" )
				&& null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpcap_sms_optouts' ) );
		}
		return $ready[ $key ];
	}

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

	/** Consent or withdrawal source (checkout, admin, import, api, inbound_stop, …): sanitised key, 32 chars. */
	public static function sanitize_source( $source, $fallback = 'checkout' ) {
		$source = substr( sanitize_key( (string) $source ), 0, 32 );
		return '' !== $source ? $source : $fallback;
	}

	/**
	 * Stores consent for an order (idempotent per order) with source and timestamp.
	 * Numbers on the opt-out list (STOP) are refused.
	 */
	public static function record_consent( $order_id, $phone, $text_version = null, $source = 'checkout' ) {
		global $wpdb;
		$phone = self::normalize_phone( $phone );
		if ( '' === $phone || ! absint( $order_id ) || self::is_opted_out( $phone ) ) { return false; }
		$version = null === $text_version ? self::consent_version() : (string) $text_version;
		$now = current_time( 'mysql', true );
		if ( self::ready() ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_sms_consents (order_id,phone,purpose,text_version,consented_at,source) VALUES (%d,%s,%s,%s,%s,%s)", absint( $order_id ), $phone, self::PURPOSE, $version, $now, self::sanitize_source( $source ) ) );
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_sms_consents (order_id,phone,purpose,text_version,consented_at) VALUES (%d,%s,%s,%s,%s)", absint( $order_id ), $phone, self::PURPOSE, $version, $now ) );
		}
		return true;
	}

	/** Active consent of an order or null. */
	public static function consent_for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_sms_consents WHERE order_id=%d AND withdrawn_at IS NULL AND retired_at IS NULL AND phone<>''", absint( $order_id ) ) );
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
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_sms_consents WHERE phone=%s AND consented_at>%s", (string) $e164, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	/**
	 * Withdraws consent and erases the number; queued and held messages are skipped. The first
	 * withdrawal keeps its timestamp and source; a later call only erases a remaining number.
	 *
	 * @param string $source Who withdrew: admin, customer, inbound_stop, privacy_erasure.
	 */
	public static function withdraw( $order_id, $source = 'admin' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		if ( self::ready() ) {
			$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_consents SET phone='',withdrawn_source=IF(withdrawn_at IS NULL,%s,withdrawn_source),withdrawn_at=COALESCE(withdrawn_at,%s) WHERE order_id=%d AND (withdrawn_at IS NULL OR phone<>'')", self::sanitize_source( $source, 'admin' ), $now, absint( $order_id ) ) );
		} else {
			$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_consents SET phone='',withdrawn_at=%s WHERE order_id=%d AND (withdrawn_at IS NULL OR phone<>'')", $now, absint( $order_id ) ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='skipped',last_error='consent_withdrawn' WHERE order_id=%d AND status IN ('queued','sending','held')", absint( $order_id ) ) );
		return $changed > 0;
	}

	/** Withdraws every consent of a number. Returns the number of consents withdrawn. */
	public static function withdraw_phone( $e164, $source = 'inbound_stop' ) {
		global $wpdb;
		$orders = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_sms_consents WHERE phone=%s", (string) $e164 ) );
		foreach ( $orders as $order_id ) { self::withdraw( (int) $order_id, $source ); }
		return count( $orders );
	}

	/* ------------------------------------------------------------------ Opt-out list */

	/** Pseudonymised key of a number on the opt-out list (the number itself is not kept there). */
	public static function phone_hash( $e164 ) {
		return hash( 'sha256', 'wpcap-sms-optout|' . (string) $e164 );
	}

	/** Whether the number sent STOP (and not START since). */
	public static function is_opted_out( $e164 ) {
		global $wpdb;
		if ( '' === (string) $e164 || ! self::ready() ) { return false; }
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT phone_hash FROM {$wpdb->prefix}wpcap_sms_optouts WHERE phone_hash=%s", self::phone_hash( $e164 ) ) );
	}

	/**
	 * Opt-out (STOP): withdraws every consent of the number and puts it on the opt-out list.
	 * Returns the number of consents withdrawn.
	 */
	public static function opt_out( $e164, $source = 'inbound_stop' ) {
		global $wpdb;
		$e164 = self::normalize_phone( $e164 );
		if ( '' === $e164 ) { return 0; }
		$source = self::sanitize_source( $source, 'inbound_stop' );
		if ( self::ready() ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_sms_optouts (phone_hash,source,opted_out_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE source=VALUES(source),opted_out_at=VALUES(opted_out_at)", self::phone_hash( $e164 ), $source, current_time( 'mysql', true ) ) );
		}
		$withdrawn = self::withdraw_phone( $e164, $source );
		Audit::record( 'sms_consent_withdrawn', 'sms', 0, array( 'source' => $source, 'consents' => $withdrawn, 'opt_out' => 1 ) );
		do_action( 'wpcap_sms_opted_out', $source, $withdrawn );
		return $withdrawn;
	}

	/** Opt-in again (START): removes the number from the opt-out list; old consents stay withdrawn. */
	public static function opt_in( $e164, $source = 'inbound_start' ) {
		global $wpdb;
		$e164 = self::normalize_phone( $e164 );
		if ( '' === $e164 || ! self::ready() ) { return false; }
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_sms_optouts WHERE phone_hash=%s", self::phone_hash( $e164 ) ) );
		if ( $removed ) { Audit::record( 'sms_opt_in', 'sms', 0, array( 'source' => self::sanitize_source( $source, 'inbound_start' ) ) ); }
		return $removed > 0;
	}

	/** STOP/START keyword of an inbound text: 'stop', 'start' or ''. */
	public static function keyword( $text ) {
		$word = strtoupper( trim( (string) $text, " \t\n\r\0\x0B.!" ) );
		if ( in_array( $word, self::STOP_WORDS, true ) ) { return 'stop'; }
		if ( in_array( $word, self::START_WORDS, true ) ) { return 'start'; }
		return '';
	}

	/** Handles an inbound SMS: STOP opts out, START opts in again. Returns [keyword, consents withdrawn]. */
	public static function handle_inbound( $from, $text ) {
		$from = self::normalize_phone( $from );
		$keyword = '' === $from ? '' : self::keyword( $text );
		$withdrawn = 0;
		if ( 'stop' === $keyword ) {
			$withdrawn = self::opt_out( $from, 'inbound_stop' );
		} elseif ( 'start' === $keyword ) {
			self::opt_in( $from, 'inbound_start' );
		}
		return array( $keyword, $withdrawn );
	}

	/**
	 * Ends a consent because the order no longer needs messages (cancelled, fully refunded, revoked,
	 * event over). Queued and held messages are skipped except cancellation notices; the number is
	 * erased as soon as nothing is queued for it any more.
	 */
	public static function retire( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_consents SET retired_at=%s WHERE order_id=%d AND retired_at IS NULL AND withdrawn_at IS NULL", current_time( 'mysql', true ), absint( $order_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='skipped',last_error='consent_retired' WHERE order_id=%d AND status IN ('queued','held') AND purpose<>'cancellation'", absint( $order_id ) ) );
		self::erase_retired_numbers();
		return $changed > 0;
	}

	/** Data minimisation: erase numbers of retired consents without queued or held messages. */
	public static function erase_retired_numbers() {
		global $wpdb;
		$p = $wpdb->prefix;
		return (int) $wpdb->query( "UPDATE {$p}wpcap_sms_consents c SET c.phone='' WHERE c.retired_at IS NOT NULL AND c.phone<>'' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_sms_messages m WHERE m.consent_id=c.consent_id AND m.status IN ('queued','sending','held'))" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input.
	}

	/** Retires consents of settled orders whose events are over (one day after the last start). */
	public static function retire_finished() {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT c.order_id FROM {$p}wpcap_sms_consents c INNER JOIN {$p}wpcap_orders ord ON ord.order_id=c.order_id WHERE c.retired_at IS NULL AND c.withdrawn_at IS NULL AND ord.payment_status<>'pending' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=c.order_id AND t.status IN ('valid','checked_in') AND o.status='scheduled' AND o.starts_at>%s) LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		foreach ( $ids as $order_id ) { self::retire( (int) $order_id ); }
		return count( $ids );
	}

	/**
	 * Queues a message for the consenting number of an order (with its segment count).
	 *
	 * @return bool|\WP_Error true when queued (or already queued), false when there is no consent.
	 */
	public static function enqueue( $dedupe, $order_id, $occurrence_id, $purpose, $body, $send_at = '' ) {
		global $wpdb;
		$consent = self::consent_for_order( $order_id );
		if ( ! $consent || self::is_opted_out( $consent->phone ) ) { return false; }
		$now = current_time( 'mysql', true );
		$body = mb_substr( (string) $body, 0, 1000 );
		if ( self::ready() ) {
			$seg = Segments::count( $body );
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}wpcap_sms_messages (dedupe_key,consent_id,order_id,occurrence_id,purpose,body,status,send_at,created_at,updated_at,segments,encoding) VALUES (%s,%d,%d,%d,%s,%s,'queued',%s,%s,%s,%d,%s)",
				hash( 'sha256', (string) $dedupe ), (int) $consent->consent_id, absint( $order_id ), absint( $occurrence_id ), sanitize_key( $purpose ), $body, $send_at ? $send_at : $now, $now, $now, $seg['segments'], $seg['encoding']
			) );
		} else {
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}wpcap_sms_messages (dedupe_key,consent_id,order_id,occurrence_id,purpose,body,status,send_at,created_at,updated_at) VALUES (%s,%d,%d,%d,%s,%s,'queued',%s,%s,%s)",
				hash( 'sha256', (string) $dedupe ), (int) $consent->consent_id, absint( $order_id ), absint( $occurrence_id ), sanitize_key( $purpose ), $body, $send_at ? $send_at : $now, $now, $now
			) );
		}
		return false === $result ? new \WP_Error( 'wpcap_sms_enqueue', 'SMS could not be queued.' ) : true;
	}

	/** Sends due messages (cron). */
	public static function process_due( $limit = 25 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='queued',locked_at=NULL WHERE status='sending' AND locked_at<%s", gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
		// Messages held by the budget stop and not released within HELD_DAYS are dropped.
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='skipped',last_error='budget_exceeded' WHERE status='held' AND created_at<%s", gmdate( 'Y-m-d H:i:s', time() - self::HELD_DAYS * DAY_IN_SECONDS ) ) );
		self::retire_finished();
		$provider = self::provider();
		if ( ! $provider ) { self::erase_retired_numbers(); return 0; }
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
			$wpdb->query( 'START TRANSACTION' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_sms_messages WHERE status='queued' AND send_at<=%s ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); break; }
			$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now, 'updated_at' => $now ), array( 'message_id' => (int) $row->message_id ) );
			$wpdb->query( 'COMMIT' );
			$claimed = (int) $row->message_id;
			\PassCaptain\Jobs\TimeBudget::claimed( $table, 'message_id', $claimed, 'sending', 'queued' );
			// Consent is re-checked at send time (it may have been withdrawn meanwhile).
			$consent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_sms_consents WHERE consent_id=%d AND withdrawn_at IS NULL AND phone<>''", (int) $row->consent_id ) );
			$skip = ! $consent ? 'no_consent' : ( self::is_opted_out( $consent->phone ) ? 'opted_out' : (string) apply_filters( 'wpcap_sms_skip_reason', '', $row ) );
			if ( '' !== $skip ) {
				$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'skipped', 'locked_at' => null, 'last_error' => substr( $skip, 0, 190 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
				continue;
			}
			$costing = array();
			if ( self::ready() ) {
				$seg = Segments::count( $row->body );
				$estimate = Costs::estimate( $consent->phone, $seg['segments'] );
				$costing = array( 'segments' => $seg['segments'], 'encoding' => $seg['encoding'], 'country' => Costs::country_of( $consent->phone ), 'estimate' => $estimate );
				// Hard stop: the message would exceed the monthly budget; hold it for the administrator.
				if ( ! Costs::allows( $estimate ) ) {
					$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => 'held', 'attempts' => (int) $row->attempts, 'locked_at' => null, 'last_error' => 'budget_exceeded', 'segments' => $seg['segments'], 'encoding' => $seg['encoding'], 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
					Costs::stopped();
					continue;
				}
			}
			$result = $provider->send( $consent->phone, $row->body, 'wpcap-sms-' . $row->message_id );
			if ( is_wp_error( $result ) ) {
				$tries = (int) $row->attempts + 1;
				$data = (array) $result->get_error_data();
				$final = $tries >= self::MAX_ATTEMPTS || ! empty( $data['permanent'] );
				$wpdb->update( "{$p}wpcap_sms_messages", array( 'status' => $final ? 'failed' : 'queued', 'provider' => $provider->id(), 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 60 * ( 2 ** $tries ) ), 'locked_at' => null, 'last_error' => substr( $result->get_error_message(), 0, 190 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'message_id' => (int) $row->message_id ) );
				continue;
			}
			$done = current_time( 'mysql', true );
			$update = array( 'status' => 'sent', 'provider' => $provider->id(), 'provider_ref' => substr( (string) $result['provider_ref'], 0, 64 ), 'locked_at' => null, 'last_error' => null, 'updated_at' => $done );
			if ( $costing ) {
				$update = array_merge( $update, self::cost_columns( $costing, (array) $result ), array( 'sent_at' => $done, 'status_at' => $done ) );
			}
			$wpdb->update( "{$p}wpcap_sms_messages", $update, array( 'message_id' => (int) $row->message_id ) );
			if ( $costing ) { Costs::after_cost(); }
			++$sent;
		}
		if ( $claimed ) { \PassCaptain\Jobs\TimeBudget::finished( $table, $claimed ); }
		if ( $budget_hit ) { \PassCaptain\Jobs\TimeBudget::continue_later( 'wpcap_process_messages' ); }
		self::erase_retired_numbers();
		return $sent;
	}

	/**
	 * Segment and cost columns of a sent message: the provider-reported price (and segment count)
	 * when available in the accounting currency, otherwise the configured estimate.
	 */
	private static function cost_columns( array $costing, array $result ) {
		$counted = (int) $costing['segments'];
		$segments = isset( $result['segments'] ) && (int) $result['segments'] > 0 ? min( 65535, (int) $result['segments'] ) : $counted;
		$reported = Costs::provider_amount( $result['price'] ?? null, (string) ( $result['currency'] ?? '' ) );
		if ( null !== $reported ) {
			$cost = $reported;
			$source = 'provider';
		} else {
			$estimate = $costing['estimate'];
			// The provider counted the segments differently: scale the configured estimate.
			$cost = null === $estimate || $segments === $counted ? $estimate : round( $estimate / max( 1, $counted ) * $segments, 5 );
			$source = null === $cost ? 'none' : 'configured';
		}
		return array(
			'segments' => $segments,
			'encoding' => (string) $costing['encoding'],
			'country' => substr( (string) $costing['country'], 0, 8 ),
			'cost' => null === $cost ? null : Costs::format( $cost ),
			'cost_currency' => Costs::currency(),
			'cost_source' => $source,
		);
	}

	/** Releases messages held by the budget stop (sent on the next run if the budget allows). */
	public static function release_held() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_sms_messages SET status='queued',last_error=NULL,send_at=%s,updated_at=%s WHERE status='held'", $now, $now ) );
	}

	/** Number of messages held by the budget stop. */
	public static function held_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_sms_messages WHERE status='held'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no input.
	}

	/** REST request parameters: form body or JSON object. */
	private static function request_params( \WP_REST_Request $request ) {
		$params = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
		if ( $params ) { return $params; }
		$body = (string) $request->get_body();
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) { return $json; }
		parse_str( $body, $params );
		return $params;
	}

	/** REST: POST /sms/twilio/status – delivery report with X-Twilio-Signature. */
	public static function twilio_status( \WP_REST_Request $request ) {
		$params = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
		if ( ! $params ) { parse_str( (string) $request->get_body(), $params ); }
		$signature = (string) $request->get_header( 'x-twilio-signature' );
		if ( ! TwilioProvider::signature_valid( LegacySlug::called_url( TwilioProvider::status_callback_url() ), $params, $signature, \PassCaptain\Secrets::get( 'twilio_auth_token' ) ) ) {
			return new \WP_Error( 'wpcap_sms_signature', __( 'Ungültige Signatur.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$sid = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $params['MessageSid'] ?? '' ) );
		$status = sanitize_key( $params['MessageStatus'] ?? ( $params['SmsStatus'] ?? '' ) );
		$map = array( 'delivered' => 'delivered', 'undelivered' => 'undelivered', 'failed' => 'failed', 'sent' => 'sent' );
		$updated = 0;
		if ( '' !== $sid && isset( $map[ $status ] ) ) {
			// Price/PriceUnit, when Twilio includes them, replace the estimated cost.
			$updated = self::apply_status( 'twilio', $sid, $map[ $status ], sanitize_text_field( (string) ( $params['ErrorCode'] ?? '' ) ), $params['Price'] ?? null, (string) ( $params['PriceUnit'] ?? '' ) );
		}
		return rest_ensure_response( array( 'received' => true, 'updated' => $updated ) );
	}

	/** REST: POST /sms/twilio/inbound – inbound SMS; STOP opts the sender out, START opts in again. */
	public static function twilio_inbound( \WP_REST_Request $request ) {
		$params = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
		if ( ! $params ) { parse_str( (string) $request->get_body(), $params ); }
		if ( ! TwilioProvider::signature_valid( LegacySlug::called_url( TwilioProvider::inbound_url() ), $params, (string) $request->get_header( 'x-twilio-signature' ), \PassCaptain\Secrets::get( 'twilio_auth_token' ) ) ) {
			return new \WP_Error( 'wpcap_sms_signature', __( 'Ungültige Signatur.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		list( , $withdrawn ) = self::handle_inbound( (string) ( $params['From'] ?? '' ), (string) ( $params['Body'] ?? '' ) );
		return rest_ensure_response( array( 'received' => true, 'withdrawn' => $withdrawn ) );
	}

	/** Provider status values of the generic HTTP callback => internal status (filter wpcap_sms_http_status_map). */
	public static function http_status_map() {
		return (array) apply_filters(
			'wpcap_sms_http_status_map',
			array(
				'delivered' => 'delivered',
				'delivrd' => 'delivered',
				'delivered_to_handset' => 'delivered',
				'undelivered' => 'undelivered',
				'undeliv' => 'undelivered',
				'expired' => 'undelivered',
				'failed' => 'failed',
				'rejected' => 'failed',
				'rejectd' => 'failed',
				'error' => 'failed',
				'sent' => 'sent',
				'accepted' => 'sent',
				'enroute' => 'sent',
			)
		);
	}

	/** Verifies the generic HTTP callback signature; WP_Error (403) when invalid or no secret is set. */
	private static function http_verify( \WP_REST_Request $request ) {
		$valid = HttpProvider::callback_signature_valid( (string) $request->get_body(), (string) $request->get_header( HttpProvider::signature_header() ), \PassCaptain\Secrets::get( 'sms_http_webhook_secret' ) );
		return $valid ? true : new \WP_Error( 'wpcap_sms_signature', __( 'Ungültige Signatur.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/**
	 * REST: POST /sms/http/status – delivery report of the generic HTTP gateway (JSON or form body)
	 * signed with HMAC-SHA256 over the raw body. Fields: reference (wpcap-sms-ID) or id/message_id,
	 * status, optional error_code, price, currency.
	 */
	public static function http_status( \WP_REST_Request $request ) {
		$verified = self::http_verify( $request );
		if ( is_wp_error( $verified ) ) { return $verified; }
		$params = self::request_params( $request );
		$map = self::http_status_map();
		$status = (string) ( $map[ strtolower( sanitize_text_field( (string) ( $params['status'] ?? '' ) ) ) ] ?? '' );
		$error = substr( sanitize_text_field( (string) ( $params['error_code'] ?? '' ) ), 0, 20 );
		$price = isset( $params['price'] ) && is_scalar( $params['price'] ) ? (string) $params['price'] : null;
		$currency = substr( sanitize_text_field( (string) ( $params['currency'] ?? '' ) ), 0, 3 );
		$updated = 0;
		if ( in_array( $status, array( 'delivered', 'undelivered', 'failed', 'sent' ), true ) ) {
			if ( preg_match( '/^wpcap-sms-(\d+)$/', (string) ( $params['reference'] ?? '' ), $m ) ) {
				$updated = self::apply_status( 'http', '', $status, $error, $price, $currency, (int) $m[1] );
			} else {
				$ref = substr( sanitize_text_field( (string) ( $params['id'] ?? ( $params['message_id'] ?? '' ) ) ), 0, 64 );
				if ( '' !== $ref ) { $updated = self::apply_status( 'http', $ref, $status, $error, $price, $currency ); }
			}
		}
		return rest_ensure_response( array( 'received' => true, 'updated' => $updated ) );
	}

	/** REST: POST /sms/http/inbound – inbound SMS of the generic gateway (from, text or body); STOP/START. */
	public static function http_inbound( \WP_REST_Request $request ) {
		$verified = self::http_verify( $request );
		if ( is_wp_error( $verified ) ) { return $verified; }
		$params = self::request_params( $request );
		list( $keyword, $withdrawn ) = self::handle_inbound( (string) ( $params['from'] ?? '' ), (string) ( $params['text'] ?? ( $params['body'] ?? '' ) ) );
		return rest_ensure_response( array( 'received' => true, 'keyword' => $keyword, 'withdrawn' => $withdrawn ) );
	}

	/**
	 * Applies a delivery status; final states are never downgraded to "sent". A provider-reported
	 * price (in the accounting currency) replaces the estimated cost.
	 *
	 * @param int $message_id Match by message id (callback carries our reference) instead of provider_ref.
	 */
	public static function apply_status( $provider, $provider_ref, $status, $error_code = '', $price = null, $currency = '', $message_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$data = array( 'status' => $status, 'updated_at' => $now );
		if ( '' !== $error_code ) { $data['last_error'] = 'provider_error_' . substr( $error_code, 0, 20 ); }
		if ( self::ready() ) {
			$data['status_at'] = $now;
			$amount = Costs::provider_amount( $price, $currency );
			if ( null !== $amount ) {
				$data['cost'] = Costs::format( $amount );
				$data['cost_currency'] = Costs::currency();
				$data['cost_source'] = 'provider';
			}
		}
		$set = array();
		foreach ( $data as $k => $v ) { $set[] = $wpdb->prepare( "$k=%s", $v ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal column names.
		$guard = 'sent' === $status ? " AND status IN ('sending','sent')" : " AND status IN ('sending','sent','delivered','undelivered','failed')";
		$where = $message_id ? $wpdb->prepare( 'provider=%s AND message_id=%d', $provider, (int) $message_id ) : $wpdb->prepare( 'provider=%s AND provider_ref=%s', $provider, $provider_ref );
		$updated = (int) $wpdb->query( "UPDATE {$p}wpcap_sms_messages SET " . implode( ',', $set ) . " WHERE $where$guard" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- parts prepared above.
		if ( $updated && isset( $data['cost'] ) ) { Costs::after_cost(); }
		return $updated;
	}
}
