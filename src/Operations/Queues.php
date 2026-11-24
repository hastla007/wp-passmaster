<?php
/**
 * Operations view of the background queues (A163): background jobs (wpcap_jobs), the message queue
 * (wpcap_message_queue), outgoing webhook deliveries (wpcap_webhook_deliveries) and the SMS outbox
 * (wpcap_sms_messages, A417: failed, held by the budget stop, send outcome unknown). Counts by status,
 * backlog and age of the oldest due item, the list of dead-letter/failed items without payload values,
 * and the controlled actions "retry" (queued again with a fresh attempt budget, processing triggered
 * right away) and "discard" (status cancelled). Both actions are audited.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Operations;

use PassCaptain\Audit;
use PassCaptain\Jobs\TimeBudget;
use PassCaptain\Privacy;
use PassCaptain\Webhooks\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Queues {
	/** Most items one retry/discard request changes. */
	const MAX_BULK = 500;

	/**
	 * Queue definitions: key => table suffix, id/type/due columns, waiting/in-flight status, failed
	 * status (shown and retried; a list for the SMS outbox), status of a stale claim after a repair
	 * ('stale', default the waiting status), payload column (null: none), cron hook of the queue.
	 */
	public static function definitions() {
		return array(
			'jobs'     => array(
				'table'   => 'wpcap_jobs',
				'id'      => 'job_id',
				'type'    => 'job_type',
				'due'     => 'run_after',
				'queued'  => 'queued',
				'running' => 'running',
				'failed'  => 'dead_letter',
				'hook'    => 'wpcap_process_jobs',
			),
			'messages' => array(
				'table'   => 'wpcap_message_queue',
				'id'      => 'message_id',
				'type'    => 'template_key',
				'due'     => 'send_at',
				'queued'  => 'queued',
				'running' => 'sending',
				'failed'  => 'failed',
				'hook'    => 'wpcap_process_messages',
			),
			'webhooks' => array(
				'table'   => 'wpcap_webhook_deliveries',
				'id'      => 'delivery_id',
				'type'    => 'event_type',
				'due'     => 'next_attempt_at',
				'queued'  => 'pending',
				'running' => 'delivering',
				'failed'  => 'failed',
				'hook'    => 'wpcap_webhooks_dispatch',
			),
			// A417: SMS outbox. "held" (budget stop, expires after Sms\Service::HELD_DAYS) and "unknown"
			// (send outcome unclear, A416) need a decision just like "failed". A stale claim is never
			// queued again blindly: it may have been sent (A416).
			'sms'      => array(
				'table'   => 'wpcap_sms_messages',
				'id'      => 'message_id',
				'type'    => 'purpose',
				'due'     => 'send_at',
				'queued'  => 'queued',
				'running' => 'sending',
				'failed'  => array( 'failed', 'held', 'unknown' ),
				'stale'   => 'unknown',
				'payload' => null,
				'hook'    => 'wpcap_process_messages',
			),
		);
	}

	/** Display names of the queues. */
	public static function labels() {
		return array(
			'jobs'     => __( 'Hintergrundjobs', 'pass-captain' ),
			'messages' => __( 'E-Mail-Warteschlange', 'pass-captain' ),
			'webhooks' => __( 'Webhook-Zustellungen', 'pass-captain' ),
			'sms'      => __( 'SMS-Warteschlange', 'pass-captain' ),
		);
	}

	/** Queues whose table exists (the webhook table belongs to a module). */
	public static function available() {
		$out = array();
		foreach ( self::definitions() as $key => $def ) {
			if ( Privacy::has_table( $def['table'] ) ) { $out[ $key ] = $def; }
		}
		return $out;
	}

	private static function def( $queue ) {
		$all = self::available();
		return $all[ (string) $queue ] ?? null;
	}

	/** Failed statuses of a queue definition (always a list). */
	public static function failed_statuses( array $def ) {
		return array_values( (array) $def['failed'] );
	}

	/** SQL condition "status IN (failed statuses)" of a queue definition. */
	private static function failed_where( array $def ) {
		global $wpdb;
		$statuses = self::failed_statuses( $def );
		return $wpdb->prepare( 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', ...$statuses ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
	}

	/** Extra condition for due items: webhook deliveries of inactive subscriptions are never processed. */
	private static function due_scope( $queue ) {
		global $wpdb;
		return 'webhooks' === $queue ? " AND subscription_id IN (SELECT subscription_id FROM {$wpdb->prefix}wpcap_webhook_subscriptions WHERE status='active')" : '';
	}

	/**
	 * Figures of one queue.
	 *
	 * @param string   $queue Queue key.
	 * @param int|null $now   Unix time (tests).
	 * @return array{counts: array<string,int>, due: int, oldest_due_seconds: int, failed: int}|null
	 */
	public static function stats( $queue, $now = null ) {
		global $wpdb;
		$def = self::def( $queue );
		if ( ! $def ) { return null; }
		$now = null === $now ? \PassCaptain\Clock::now() : (int) $now;
		$table = $wpdb->prefix . $def['table'];
		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$counts[ (string) $row->status ] = (int) $row->n;
		}
		$due_col = $def['due'];
		$scope = self::due_scope( $queue );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MIN($due_col) AS oldest FROM $table WHERE status=%s AND $due_col<=%s$scope", $def['queued'], gmdate( 'Y-m-d H:i:s', $now ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column/table names.
		$oldest = $row && $row->oldest ? strtotime( $row->oldest . ' UTC' ) : 0;
		return array(
			'counts'             => $counts,
			'due'                => $row ? (int) $row->n : 0,
			'oldest_due_seconds' => $oldest ? max( 0, $now - (int) $oldest ) : 0,
			'failed'             => (int) array_sum( array_intersect_key( $counts, array_flip( self::failed_statuses( $def ) ) ) ),
		);
	}

	/**
	 * Dead-letter/failed items, newest first. Payload values are never returned: only the top-level keys
	 * (webhooks: the keys of data.object), the recipient of a message masked, errors with e-mail
	 * addresses masked.
	 *
	 * @return array{rows: array[], total: int}
	 */
	public static function failed_items( $queue, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$def = self::def( $queue );
		if ( ! $def ) { return array( 'rows' => array(), 'total' => 0 ); }
		$table = $wpdb->prefix . $def['table'];
		$id = $def['id'];
		$type = $def['type'];
		$extra = 'messages' === $queue ? ', recipient' : ( 'webhooks' === $queue ? ', subscription_id, response_code' : ( 'sms' === $queue ? ', status' : '' ) );
		if ( in_array( $queue, array( 'jobs', 'messages', 'sms' ), true ) && \PassCaptain\Log::has_column( $def['table'], 'order_id' ) ) { $extra .= ', order_id'; }
		$payload = array_key_exists( 'payload', $def ) && null === $def['payload'] ? "'' AS payload" : 'payload';
		$where = self::failed_where( $def );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, prepared condition.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT $id AS id, $type AS type, attempts, last_error, $payload, created_at$extra FROM $table WHERE $where ORDER BY $id DESC LIMIT %d OFFSET %d", max( 1, (int) $limit ), max( 0, (int) $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column/table names, prepared condition.
		$out = array();
		foreach ( (array) $rows as $row ) {
			$item = array(
				'id'           => (int) $row->id,
				'type'         => (string) $row->type,
				'attempts'     => (int) $row->attempts,
				'last_error'   => self::safe_error( (string) $row->last_error ),
				'payload_keys' => self::payload_keys( (string) $row->payload, 'webhooks' === $queue ),
				'created_at'   => (string) $row->created_at,
				'detail'       => '',
				// A326: the order/ticket/occurrence the item belongs to (ids only).
				'refs'         => self::payload_refs( (string) $row->payload, 'webhooks' === $queue, (int) ( $row->order_id ?? 0 ) ),
			);
			if ( 'messages' === $queue ) {
				$item['detail'] = '' === (string) $row->recipient ? '' : \PassCaptain\Pii::mask_email( (string) $row->recipient );
			} elseif ( 'webhooks' === $queue ) {
				$item['detail'] = 'Webhook #' . (int) $row->subscription_id . ( (int) $row->response_code ? ' · HTTP ' . (int) $row->response_code : '' );
			} elseif ( 'sms' === $queue ) {
				$item['status'] = (string) $row->status;
				$item['detail'] = self::sms_status_label( (string) $row->status, (string) $row->created_at );
			}
			$out[] = $item;
		}
		return array( 'rows' => $out, 'total' => $total );
	}

	/** Why an SMS needs a decision (A417); held messages with the date they expire. */
	public static function sms_status_label( $status, $created_at = '' ) {
		if ( 'held' === $status ) {
			$expires = strtotime( $created_at . ' UTC' ) + \PassCaptain\Sms\Service::HELD_DAYS * DAY_IN_SECONDS;
			/* translators: %s: date and time the held message expires */
			return sprintf( __( 'Wegen des Monatsbudgets zurückgehalten, verfällt am %s', 'pass-captain' ), wp_date( 'd.m.Y H:i', $expires ) );
		}
		if ( 'unknown' === $status ) {
			return __( 'Versandergebnis unklar – vor „Erneut versuchen“ beim Anbieter prüfen, ob die SMS angekommen ist', 'pass-captain' );
		}
		return __( 'Versand fehlgeschlagen', 'pass-captain' );
	}

	/** Payload keys whose (integer) values identify the object an item belongs to. */
	const REF_KEYS = array( 'order_id', 'ticket_id', 'occurrence_id', 'event_id', 'refund_id', 'run_id' );

	/**
	 * Ids an item belongs to (A326): positive integers of REF_KEYS from the payload (webhooks: also
	 * data.object), plus the stored order_id column. No other payload value is read.
	 *
	 * @return array<string,int>
	 */
	public static function payload_refs( $payload, $webhook = false, $order_id = 0 ) {
		$data = json_decode( (string) $payload, true );
		$sources = is_array( $data ) ? array( $data ) : array();
		if ( $webhook && is_array( $data ) && isset( $data['data']['object'] ) && is_array( $data['data']['object'] ) ) { $sources[] = $data['data']['object']; }
		$out = array();
		foreach ( $sources as $source ) {
			foreach ( self::REF_KEYS as $key ) {
				if ( ! isset( $out[ $key ] ) && isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && preg_match( '/^\d{1,19}$/', (string) $source[ $key ] ) && (int) $source[ $key ] > 0 ) {
					$out[ $key ] = (int) $source[ $key ];
				}
			}
		}
		if ( $order_id > 0 && ! isset( $out['order_id'] ) ) { $out = array( 'order_id' => (int) $order_id ) + $out; }
		return $out;
	}

	/** Top-level keys of a JSON payload (at most 12), never values. */
	public static function payload_keys( $payload, $webhook = false ) {
		$data = json_decode( (string) $payload, true );
		if ( $webhook && is_array( $data ) && isset( $data['data']['object'] ) && is_array( $data['data']['object'] ) ) {
			$data = $data['data']['object'];
		}
		if ( ! is_array( $data ) ) { return array(); }
		$keys = array();
		foreach ( array_keys( $data ) as $key ) {
			$keys[] = substr( preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $key ), 0, 40 );
		}
		return array_slice( array_values( array_filter( $keys, 'strlen' ) ), 0, 12 );
	}

	/** Error text for display and storage: secrets redacted, e-mail addresses masked (Redact::text()), at most 190 characters. */
	public static function safe_error( $error ) {
		$error = \PassCaptain\Redact::text( (string) $error );
		$error = (string) preg_replace( '/[^\s@<>"\']+@[^\s@<>"\']+/', '***@***', $error );
		$error = trim( (string) preg_replace( '/\s+/', ' ', $error ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 190 ) : \PassCaptain\Text::cut( $error, 190 );
	}

	/** IDs of all failed items of a queue (oldest first, at most MAX_BULK). */
	public static function failed_ids( $queue ) {
		global $wpdb;
		$def = self::def( $queue );
		if ( ! $def ) { return array(); }
		$table = $wpdb->prefix . $def['table'];
		$id = $def['id'];
		$where = self::failed_where( $def );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT $id FROM $table WHERE $where ORDER BY $id LIMIT %d", self::MAX_BULK ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column/table names, prepared condition.
	}

	/** Normalised id list (positive, unique, at most MAX_BULK). */
	private static function clean_ids( array $ids ) {
		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, self::MAX_BULK );
	}

	/**
	 * Queues failed items again: status waiting, attempts 0, due now, error cleared; then triggers the
	 * queue's worker right away. Only items still in the failed status are changed. Message items whose
	 * content was removed (data protection) are skipped; webhook deliveries go through
	 * Dispatcher::redeliver() (personal data rebuilt from current data for PII subscriptions).
	 *
	 * @return int|\WP_Error Number of items queued again.
	 */
	public static function retry( $queue, array $ids ) {
		global $wpdb;
		$def = self::def( $queue );
		if ( ! $def ) { return new \WP_Error( 'wpcap_operations_queue', __( 'Unbekannte Warteschlange.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$ids = self::clean_ids( $ids );
		if ( ! $ids ) { return new \WP_Error( 'wpcap_operations_empty', __( 'Bitte mindestens einen Eintrag auswählen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$table = $wpdb->prefix . $def['table'];
		$now = \PassCaptain\Clock::mysql();
		$done = array();
		foreach ( $ids as $id ) {
			if ( 'webhooks' === $queue ) {
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE delivery_id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				if ( 'failed' === $status && true === Dispatcher::redeliver( $id ) ) { $done[] = $id; }
				continue;
			}
			if ( 'jobs' === $queue ) {
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued', attempts=0, run_after=%s, locked_at=NULL, last_error=NULL, updated_at=%s WHERE job_id=%d AND status='dead_letter'", $now, $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			} elseif ( 'sms' === $queue ) {
				// Consent, opt-out and budget are checked again when the message is sent.
				$where = self::failed_where( $def );
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued', attempts=0, send_at=%s, locked_at=NULL, last_error=NULL, updated_at=%s WHERE message_id=%d AND $where", $now, $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, prepared condition.
			} else {
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued', attempts=0, send_at=%s, locked_at=NULL, last_error=NULL WHERE message_id=%d AND status='failed' AND recipient<>''", $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			}
			if ( 1 === (int) $changed ) { $done[] = $id; }
		}
		if ( $done ) {
			self::audit( 'operations_retry', $queue, $done );
			self::run_now( $queue );
		}
		return count( $done );
	}

	/**
	 * Discards failed items (status cancelled; final, removed by the normal clean-up/retention).
	 *
	 * @return int|\WP_Error Number of items discarded.
	 */
	public static function discard( $queue, array $ids ) {
		global $wpdb;
		$def = self::def( $queue );
		if ( ! $def ) { return new \WP_Error( 'wpcap_operations_queue', __( 'Unbekannte Warteschlange.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$ids = self::clean_ids( $ids );
		if ( ! $ids ) { return new \WP_Error( 'wpcap_operations_empty', __( 'Bitte mindestens einen Eintrag auswählen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$table = $wpdb->prefix . $def['table'];
		$id_col = $def['id'];
		$set = 'messages' === $queue ? "status='cancelled', locked_at=NULL" : $wpdb->prepare( "status='cancelled', locked_at=NULL, updated_at=%s", \PassCaptain\Clock::mysql() );
		$done = array();
		foreach ( $ids as $id ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE $id_col=%d AND " . self::failed_where( $def ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed names, prepared SET and condition.
			if ( 1 === (int) $changed ) { $done[] = $id; }
		}
		if ( $done ) { self::audit( 'operations_discard', $queue, $done ); }
		return count( $done );
	}

	private static function audit( $action, $queue, array $ids ) {
		Audit::record( $action, 'queue_' . $queue, 1 === count( $ids ) ? $ids[0] : 0, array( 'queue' => $queue, 'count' => count( $ids ), 'ids' => array_slice( $ids, 0, 100 ) ) );
	}

	/**
	 * Starts the queue's worker without waiting for the next five-minute tick: an immediate follow-up
	 * run (Action Scheduler or a WP-Cron event, see TimeBudget::continue_later()) and a non-blocking
	 * WP-Cron spawn. The admin request itself does not process the queue (no long page loads).
	 */
	public static function run_now( $queue ) {
		$def = self::definitions()[ $queue ] ?? null;
		if ( ! $def ) { return false; }
		if ( 'webhooks' === $queue ) {
			Dispatcher::schedule_soon();
		} else {
			TimeBudget::continue_later( $def['hook'] );
		}
		if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) { spawn_cron(); }
		return true;
	}
}
