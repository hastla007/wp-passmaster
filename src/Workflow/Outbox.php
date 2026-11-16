<?php
/**
 * Event outbox (A675, PASS-WF-03): every domain event of the workflow contract is written once to
 * {prefix}wpcap_event_outbox – independent of webhook subscriptions and of whether a workflow provider
 * is installed. The event id is derived from type and a stable discriminator, so a hook that fires twice
 * (retries, repeated provider webhooks) records the event once (UNIQUE event_id).
 *
 * Delivery to the provider (Contract::provider()) runs on the cron wpcap_process_jobs in sequence order,
 * at least once, with exponential backoff (as outgoing webhooks) and at most MAX_ATTEMPTS attempts.
 * Without a (compatible) provider nothing is delivered and nothing is marked: the events stay in the
 * outbox until the retention period ends and reach a provider installed later (replay). Payloads
 * contain no personal data and no ticket codes (Contract::sanitize()).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Workflow;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Outbox {
	const TABLE = 'wpcap_event_outbox';
	const MAX_ATTEMPTS = 8;
	const RETENTION_DAYS = 30;

	/** Status of the last delivery run, for diagnostics: option wpcap_workflow_status. */
	const STATUS_OPTION = 'wpcap_workflow_status';

	public static function register() {
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'deliver' ), 22, 0 );
		add_action( 'wpcap_workflow_dispatch', array( __CLASS__, 'deliver' ), 10, 0 );
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'purge_old' ), 26, 0 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Whether the outbox table exists (schema 3.10.18; a deferred upgrade may still be pending). */
	public static function ready() {
		return \PassCaptain\Privacy::has_table( self::TABLE );
	}

	public static function schema( $prefix, $charset ) {
		return "CREATE TABLE {$prefix}" . self::TABLE . " (
				outbox_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
	 * @param string   $type        Event type of Contract::EVENTS.
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
		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpcap_workflow_dispatch' ) && has_filter( Contract::FILTER ) ) {
			wp_schedule_single_event( time() + 5, 'wpcap_workflow_dispatch' );
		}
		return $event_id;
	}

	/** Writes the outbox row; event id or false. */
	private static function insert( $type, $dedupe, $subject_id, array $data, $occurred_at ) {
		global $wpdb;
		if ( ! Contract::known( $type ) || ! self::ready() ) { return false; }
		/** Internal: data of a workflow event before the allowlist is applied (fields outside it are dropped). */
		$data = (array) apply_filters( 'wpcap_workflow_event_data', $data, $type );
		$data = Contract::sanitize( $type, $data, $dropped );
		if ( $dropped ) { \PassCaptain\Log::debug( 'workflow', 'wpcap_workflow_field_not_allowed: ' . $type, array( 'fields' => array_slice( $dropped, 0, 20 ) ) ); }
		$occurred_at = null === $occurred_at ? time() : (int) $occurred_at;
		$event_id = self::event_id( $type, $dedupe );
		$envelope = Contract::envelope( $event_id, $type, $subject_id, $data, $occurred_at );
		$now = current_time( 'mysql', true );
		$inserted = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . " (event_id,event_type,schema_version,subject_type,subject_id,payload,occurred_at,status,attempts,next_attempt_at,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,'pending',0,%s,%s)", $event_id, $type, Contract::schema_version( $type ), Contract::subject( $type ), substr( (string) $subject_id, 0, 64 ), (string) wp_json_encode( $envelope ), gmdate( 'Y-m-d H:i:s', $occurred_at ), $now, $now ) );
		if ( 1 !== $inserted ) { return false; }
		/** Internal: a workflow event was recorded (envelope without sequence). */
		do_action( 'wpcap_workflow_event_recorded', $envelope );
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
	 * Delivers due events to the provider (cron). Returns the number of attempts.
	 *
	 * @param int $limit Maximum attempts in this run.
	 */
	public static function deliver( $limit = 50 ) {
		global $wpdb;
		if ( ! self::ready() ) { return 0; }
		$provider = Contract::provider( $status );
		self::remember_status( $status );
		if ( ! $provider ) { return 0; }
		$t = self::table();
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : 50;
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET status='pending',locked_at=NULL WHERE status='delivering' AND locked_at<%s", gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
		$deadline = \PassCaptain\Jobs\TimeBudget::deadline();
		$done = 0;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( \PassCaptain\Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			$now = current_time( 'mysql', true );
			// Due events in sequence order. A failing event does not hold back later ones: providers order
			// by `sequence` and treat events idempotently (at-least-once delivery).
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE status='pending' AND next_attempt_at<=%s ORDER BY outbox_id LIMIT 1", $now ) );
			if ( ! $row ) { break; }
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status='delivering',locked_at=%s WHERE outbox_id=%d AND status='pending'", $now, (int) $row->outbox_id ) );
			if ( 1 !== (int) $claimed ) { continue; }
			self::attempt( $provider, $row );
			++$done;
		}
		return $done;
	}

	/** One delivery attempt of a claimed row. */
	private static function attempt( $provider, $row ) {
		global $wpdb;
		// Delivered before and queued again (replay()): the provider sees replay = true.
		$envelope = self::envelope_of( $row, ! empty( $row->delivered_at ) );
		$attempts = (int) $row->attempts + 1;
		$now = current_time( 'mysql', true );
		$code = '';
		$retry = true;
		if ( null === $envelope ) {
			$code = 'wpcap_workflow_provider_error';
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
					$retry = 'wpcap_workflow_rejected' !== $code && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
				} else {
					$code = 'wpcap_workflow_provider_error';
				}
			} catch ( \Throwable $e ) {
				$code = 'wpcap_workflow_provider_error';
			}
		}
		$final = ! $retry || $attempts >= (int) apply_filters( 'wpcap_workflow_max_attempts', self::MAX_ATTEMPTS );
		$wpdb->update( self::table(), array(
			'status' => $final ? 'failed' : 'pending',
			'attempts' => $attempts,
			'last_error' => substr( '' !== $code ? $code : 'wpcap_workflow_provider_error', 0, 190 ),
			'locked_at' => null,
			'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + \PassCaptain\Webhooks\Dispatcher::backoff( $attempts ) ),
		), array( 'outbox_id' => (int) $row->outbox_id ) );
		if ( $final ) {
			\PassCaptain\Log::warning( 'workflow', 'Workflow event not delivered: ' . $row->event_type, array( 'code' => $code, 'attempts' => $attempts, 'sequence' => (int) $row->outbox_id ) );
		}
	}

	private static function remember_status( array $status ) {
		$previous = get_option( self::STATUS_OPTION );
		$state = array( 'state' => (string) ( $status['state'] ?? '' ), 'version' => (string) ( $status['version'] ?? '' ), 'code' => (string) ( $status['code'] ?? '' ) );
		if ( ! is_array( $previous ) || array_intersect_key( $previous, $state ) !== $state ) {
			update_option( self::STATUS_OPTION, $state + array( 'checked_at' => current_time( 'mysql', true ) ), false );
		}
	}

	/**
	 * Events after a sequence number, for a provider that pulls or catches up (contract service).
	 *
	 * @param int      $after_sequence Last sequence the provider has (0 = from the oldest kept event).
	 * @param int      $limit          1–500.
	 * @param string[] $types          Only these types (empty = all).
	 * @return array[] Envelopes in sequence order (replay = true).
	 */
	public static function since( $after_sequence = 0, $limit = 100, array $types = array() ) {
		global $wpdb;
		if ( ! self::ready() ) { return array(); }
		$limit = max( 1, min( 500, (int) $limit ) );
		$types = array_values( array_filter( array_map( 'strval', $types ), array( Contract::class, 'known' ) ) );
		$where = $types ? ' AND event_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT outbox_id,payload,attempts FROM ' . self::table() . " WHERE outbox_id>%d$where ORDER BY outbox_id LIMIT %d", array_merge( array( absint( $after_sequence ) ), $types, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
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
	 * Contract::CAPABILITY; audited.
	 *
	 * @return int|\WP_Error Number of events queued again.
	 */
	public static function replay( $from_sequence = 0 ) {
		global $wpdb;
		if ( ! current_user_can( Contract::CAPABILITY ) ) {
			return new \WP_Error( 'wpcap_workflow_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( ! self::ready() ) { return 0; }
		$now = current_time( 'mysql', true );
		$count = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='pending',attempts=0,last_error='',next_attempt_at=%s,locked_at=NULL WHERE outbox_id>=%d AND status<>'delivering'", $now, absint( $from_sequence ) ) );
		Audit::record( 'workflow_replay', 'workflow', absint( $from_sequence ), array( 'events' => $count ) );
		return $count;
	}

	/** Deletes events older than the retention period (cron). Returns deleted rows. */
	public static function purge_old() {
		global $wpdb;
		if ( ! self::ready() ) { return 0; }
		$days = max( 1, min( 365, (int) apply_filters( 'wpcap_workflow_retention_days', self::RETENTION_DAYS ) ) );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status<>'delivering' AND created_at<%s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
