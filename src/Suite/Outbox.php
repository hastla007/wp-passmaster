<?php
/**
 * Event outbox of the review participation contract (A667; table {prefix}wpcap_event_outbox, A675).
 * Every review event is written once – independent of whether a review provider is installed. The
 * event id is derived from type and a stable discriminator, so a hook that fires twice (retries,
 * repeated provider webhooks) records the event once (UNIQUE event_id).
 *
 * Delivery to the provider (Contract::provider()) runs on the cron wpcap_process_jobs in sequence order,
 * at least once, with exponential backoff (as outgoing webhooks) and at most MAX_ATTEMPTS attempts.
 * Without a (compatible) provider nothing is delivered and nothing is marked: the events stay in the
 * outbox until the retention period ends and reach a provider installed later (replay). Payloads
 * contain no personal data and no ticket codes (Contract::sanitize()).
 *
 * PC-7 (owner decision 23.11.2026, suite decisions D13/D10): the core has no workflow contract, no
 * workflow outbox and no mail handoff – Workflow Captain uses the wpcap_* actions, the read facade
 * wpcap_get_*_state() and the public action functions (wpcap_resend_ticket_mail() …). The outbox only
 * serves the review contract (CONTRACTS; column `contract`, schema 3.10.27). Rows of the former
 * workflow contract (`contract` = 'workflow', written up to plugin 1.6.0) are never delivered and
 * expire with the retention period.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Suite;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Outbox {
	const TABLE = 'wpcap_event_outbox';
	const MAX_ATTEMPTS = 8;
	const RETENTION_DAYS = 30;

	/** Cron hook of the immediate delivery after a new event. */
	const DISPATCH_HOOK = 'wpcap_outbox_dispatch';

	/** Contracts served by the outbox: name => contract class (status option per contract: wpcap_{name}_status). */
	const CONTRACTS = array(
		'review' => \PassCaptain\Review\Contract::class,
	);

	/** Contract class of an event type (null for unknown types). */
	public static function contract_for( $type ) {
		foreach ( self::CONTRACTS as $class ) {
			if ( $class::known( $type ) ) { return $class; }
		}
		return null;
	}

	/** Whether rows carry their contract (schema 3.10.27); before that no contract event is recorded. */
	public static function multi_ready() {
		return \PassCaptain\Log::has_column( self::TABLE, 'contract' );
	}

	public static function register() {
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'deliver' ), 22, 0 );
		add_action( self::DISPATCH_HOOK, array( __CLASS__, 'deliver' ), 10, 0 );
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'purge_old' ), 26, 0 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Whether the outbox table exists (schema 3.10.24; a deferred upgrade may still be pending). */
	public static function ready() {
		return \PassCaptain\Privacy::has_table( self::TABLE );
	}

	/** Table definition; the column default 'workflow' is historical (unchanged schema) – every insert names its contract. */
	public static function schema( $prefix, $charset ) {
		return "CREATE TABLE {$prefix}" . self::TABLE . " (
				outbox_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				contract varchar(20) NOT NULL DEFAULT 'workflow',
				event_id varchar(40) NOT NULL,
				event_type varchar(40) NOT NULL,
				schema_version varchar(10) NOT NULL DEFAULT '',
				subject_type varchar(20) NOT NULL DEFAULT '',
				subject_id varchar(64) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				occurred_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(190) NOT NULL DEFAULT '',
				next_attempt_at datetime NOT NULL,
				locked_at datetime NULL,
				delivered_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (outbox_id),
				UNIQUE KEY event_id (event_id),
				KEY due (status,next_attempt_at),
				KEY contract_due (contract,status,next_attempt_at),
				KEY subject (subject_type,subject_id),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;";
	}

	/** Stable event id: same type + discriminator = same id (also the webhook event id). */
	public static function event_id( $type, $dedupe ) {
		return 'evt_' . substr( hash( 'sha256', (string) $type . '|' . (string) $dedupe ), 0, 32 );
	}

	/**
	 * Records an event once.
	 *
	 * @param string   $type        Event type of a contract in CONTRACTS.
	 * @param string   $dedupe      Stable discriminator of the business event.
	 * @param string   $subject_id  Stable id of the subject.
	 * @param array    $data        Data; reduced to the allowlist.
	 * @param int|null $occurred_at Unix time (default now).
	 * @return string|false Event id when newly recorded, false otherwise (duplicate, unknown type, no table).
	 */
	public static function record( $type, $dedupe, $subject_id, array $data, $occurred_at = null ) {
		global $wpdb;
		// Recording never disturbs the caller: its error state stays as it was (A183 checks last_error).
		$error = $wpdb->last_error;
		$event_id = self::insert( $type, $dedupe, $subject_id, $data, $occurred_at );
		$wpdb->last_error = $error;
		if ( false === $event_id ) { return false; }
		$class = self::contract_for( $type );
		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::DISPATCH_HOOK ) && $class && has_filter( $class::FILTER ) ) {
			wp_schedule_single_event( \PassCaptain\Clock::real() + 5, self::DISPATCH_HOOK );
		}
		return $event_id;
	}

	/** Writes the outbox row; event id or false. */
	private static function insert( $type, $dedupe, $subject_id, array $data, $occurred_at ) {
		global $wpdb;
		$class = self::contract_for( $type );
		if ( ! $class || ! self::ready() || ! self::multi_ready() ) { return false; } // Contract rows need schema 3.10.27.
		/** Internal: data of a suite contract event before the allowlist is applied (fields outside it are dropped). */
		$data = (array) apply_filters( 'wpcap_outbox_event_data', $data, $type );
		$data = $class::sanitize( $type, $data, $dropped );
		if ( $dropped ) { \PassCaptain\Log::debug( $class::NAME, $class::code( 'field_not_allowed' ) . ': ' . $type, array( 'fields' => array_slice( $dropped, 0, 20 ) ) ); }
		$occurred_at = null === $occurred_at ? \PassCaptain\Clock::now() : (int) $occurred_at;
		$event_id = self::event_id( $type, $dedupe );
		$envelope = $class::envelope( $event_id, $type, $subject_id, $data, $occurred_at );
		$now = \PassCaptain\Clock::mysql();
		$values = array( $event_id, $type, $class::schema_version( $type ), $class::subject( $type ), substr( (string) $subject_id, 0, 64 ), (string) wp_json_encode( $envelope ), gmdate( 'Y-m-d H:i:s', $occurred_at ), $now, $now );
		$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (contract,event_id,event_type,schema_version,subject_type,subject_id,payload,occurred_at,status,attempts,next_attempt_at,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,'pending',0,%s,%s)", array_merge( array( self::table(), $class::NAME ), $values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- 12 values in one array.
		if ( 1 !== $inserted ) { return false; }
		/** Internal: a suite contract event was recorded (envelope without sequence). */
		do_action( 'wpcap_outbox_event_recorded', $envelope );
		return $event_id;
	}

	/** Envelope of an outbox row as delivered (sequence = outbox id). */
	public static function envelope_of( $row, $replay = false ) {
		$envelope = json_decode( (string) $row->payload, true );
		if ( ! is_array( $envelope ) ) { return null; }
		$envelope['sequence'] = (int) $row->outbox_id;
		$envelope['attempt'] = (int) $row->attempts + 1;
		$envelope['replay'] = (bool) $replay;
		return $envelope;
	}

	/**
	 * Delivers due events to the provider of each contract (cron). Returns the number of attempts.
	 *
	 * @param int $limit Maximum attempts in this run (per contract).
	 */
	public static function deliver( $limit = 50 ) {
		if ( ! self::ready() || ! self::multi_ready() ) { return 0; }
		$done = 0;
		foreach ( self::CONTRACTS as $class ) {
			$done += self::deliver_contract( $class, $limit );
		}
		return $done;
	}

	/** Delivery run of one contract. */
	private static function deliver_contract( $contract, $limit ) {
		global $wpdb;
		$provider = $contract::provider( $status );
		self::remember_status( $status, $contract );
		if ( ! $provider ) { return 0; }
		$t = self::table();
		$scope = $wpdb->prepare( ' AND contract=%s', $contract::NAME );
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : 50;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='pending',locked_at=NULL WHERE status='delivering' AND locked_at<%s", $t, gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - 15 * MINUTE_IN_SECONDS ) ) . $scope ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
		$deadline = \PassCaptain\Jobs\TimeBudget::deadline();
		$done = 0;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( \PassCaptain\Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			$now = \PassCaptain\Clock::mysql();
			// Due events in sequence order. A failing event does not hold back later ones: providers order
			// by `sequence` and treat events idempotently (at-least-once delivery).
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status='pending' AND next_attempt_at<=%s", $t, $now ) . $scope . ' ORDER BY outbox_id LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
			if ( ! $row ) { break; }
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='delivering',locked_at=%s WHERE outbox_id=%d AND status='pending'", $t, $now, (int) $row->outbox_id ) );
			if ( 1 !== (int) $claimed ) { continue; }
			self::attempt( $provider, $row, $contract );
			++$done;
		}
		return $done;
	}

	/** One delivery attempt of a claimed row. */
	private static function attempt( $provider, $row, $contract ) {
		global $wpdb;
		// Delivered before and queued again (replay()): the provider sees replay = true.
		$envelope = self::envelope_of( $row, ! empty( $row->delivered_at ) );
		$attempts = (int) $row->attempts + 1;
		$now = \PassCaptain\Clock::mysql();
		$code = '';
		$retry = true;
		$provider_error = $contract::code( 'provider_error' );
		if ( null === $envelope ) {
			$code = $provider_error;
			$retry = false;
		} else {
			try {
				$result = $provider->receive( $envelope );
				if ( true === $result || is_array( $result ) ) {
					$wpdb->update( self::table(), array( 'status' => 'delivered', 'attempts' => $attempts, 'last_error' => '', 'locked_at' => null, 'delivered_at' => $now ), array( 'outbox_id' => (int) $row->outbox_id ) );
					return;
				}
				if ( is_wp_error( $result ) ) {
					$data = (array) $result->get_error_data();
					$code = sanitize_key( (string) $result->get_error_code() );
					$retry = $contract::code( 'rejected' ) !== $code && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
				} else {
					$code = $provider_error;
				}
			} catch ( \Throwable $e ) {
				$code = $provider_error;
			}
		}
		$final = ! $retry || $attempts >= (int) apply_filters( 'wpcap_outbox_max_attempts', self::MAX_ATTEMPTS );
		$wpdb->update( self::table(), array(
			'status' => $final ? 'failed' : 'pending',
			'attempts' => $attempts,
			'last_error' => \PassCaptain\Text::cut( '' !== $code ? $code : $provider_error, 190 ),
			'locked_at' => null,
			'next_attempt_at' => gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + \PassCaptain\Webhooks\Dispatcher::backoff( $attempts ) ),
		), array( 'outbox_id' => (int) $row->outbox_id ) );
		if ( $final ) {
			\PassCaptain\Log::warning( $contract::NAME, 'Suite contract event not delivered: ' . $row->event_type, array( 'code' => $code, 'attempts' => $attempts, 'sequence' => (int) $row->outbox_id ) );
		}
	}

	private static function remember_status( array $status, $contract ) {
		$option = $contract::status_option();
		$previous = get_option( $option );
		$state = array( 'state' => (string) ( $status['state'] ?? '' ), 'version' => (string) ( $status['version'] ?? '' ), 'code' => (string) ( $status['code'] ?? '' ) );
		if ( ! is_array( $previous ) || array_intersect_key( $previous, $state ) !== $state ) {
			update_option( $option, $state + array( 'checked_at' => \PassCaptain\Clock::mysql() ), false );
		}
	}

	/**
	 * Events after a sequence number, for a provider that pulls or catches up (contract service).
	 *
	 * @param int      $after_sequence Last sequence the provider has (0 = from the oldest kept event).
	 * @param int      $limit          1–500.
	 * @param string[] $types          Only these types (empty = all of the contract).
	 * @param string   $contract       Contract name (review).
	 * @return array[] Envelopes in sequence order (replay = true).
	 */
	public static function since( $after_sequence = 0, $limit = 100, array $types = array(), $contract = 'review' ) {
		global $wpdb;
		$class = self::CONTRACTS[ (string) $contract ] ?? null;
		if ( ! $class || ! self::ready() || ! self::multi_ready() ) { return array(); }
		$limit = max( 1, min( 500, (int) $limit ) );
		$types = array_values( array_filter( array_map( 'strval', $types ), array( $class, 'known' ) ) );
		$where = $types ? ' AND event_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')' : '';
		$where .= $wpdb->prepare( ' AND contract=%s', $class::NAME );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT outbox_id,payload,attempts FROM %i WHERE outbox_id>%d$where ORDER BY outbox_id LIMIT %d", array_merge( array( self::table(), absint( $after_sequence ) ), $types, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders only / prepared fragment / values in one array.
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row->attempts = -1; // Pulled events are not delivery attempts.
			$envelope = self::envelope_of( $row, true );
			if ( $envelope ) { unset( $envelope['attempt'] ); $out[] = $envelope; }
		}
		return $out;
	}

	/**
	 * Queues the events from a sequence number on again (provider lost data, new provider). Needs
	 * the contract's CAPABILITY; audited.
	 *
	 * @param int    $from_sequence First sequence to queue again.
	 * @param string $contract      Contract name (review).
	 * @return int|\WP_Error Number of events queued again.
	 */
	public static function replay( $from_sequence = 0, $contract = 'review' ) {
		global $wpdb;
		$class = self::CONTRACTS[ (string) $contract ] ?? \PassCaptain\Review\Contract::class;
		if ( ! current_user_can( $class::CAPABILITY ) ) {
			return new \WP_Error( $class::code( 'forbidden' ), __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( ! self::ready() || ! self::multi_ready() ) { return 0; }
		$scope = $wpdb->prepare( ' AND contract=%s', $class::NAME );
		$now = \PassCaptain\Clock::mysql();
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='pending',attempts=0,last_error='',next_attempt_at=%s,locked_at=NULL WHERE outbox_id>=%d AND status<>'delivering'", self::table(), $now, absint( $from_sequence ) ) . $scope ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
		Audit::record( $class::NAME . '_replay', $class::NAME, absint( $from_sequence ), array( 'events' => $count ) );
		return $count;
	}

	/** Deletes events older than the retention period (cron). Returns deleted rows. */
	public static function purge_old() {
		global $wpdb;
		if ( ! self::ready() ) { return 0; }
		$days = max( 1, min( 365, (int) apply_filters( 'wpcap_outbox_retention_days', self::RETENTION_DAYS ) ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status<>'delivering' AND created_at<%s", self::table(), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - $days * DAY_IN_SECONDS ) ) );
	}
}
