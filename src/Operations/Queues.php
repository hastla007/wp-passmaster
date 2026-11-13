<?php
/**
 * Operations view of the background queues (A163): background jobs (wpcap_jobs), the message queue
 * (wpcap_message_queue) and outgoing webhook deliveries (wpcap_webhook_deliveries). Counts by status,
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

final class Queues {
	/** Most items one retry/discard request changes. */
	const MAX_BULK = 500;

	/**
	 * Queue definitions: key => table suffix, id/type/due columns, waiting/in-flight status, failed
	 * statuses (shown and retried), cron hook that processes the queue.
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
		);
	}

	/** Display names of the queues. */
	public static function labels() {
		return array(
			'jobs'     => __( 'Hintergrundjobs', 'pass-captain' ),
			'messages' => __( 'E-Mail-Warteschlange', 'pass-captain' ),
			'webhooks' => __( 'Webhook-Zustellungen', 'pass-captain' ),
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
		$now = null === $now ? time() : (int) $now;
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
			'failed'             => (int) ( $counts[ $def['failed'] ] ?? 0 ),
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
		$extra = 'messages' === $queue ? ', recipient' : ( 'webhooks' === $queue ? ', subscription_id, response_code' : '' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status=%s", $def['failed'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT $id AS id, $type AS type, attempts, last_error, payload, created_at$extra FROM $table WHERE status=%s ORDER BY $id DESC LIMIT %d OFFSET %d", $def['failed'], max( 1, (int) $limit ), max( 0, (int) $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column/table names.
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
			);
			if ( 'messages' === $queue ) {
				$item['detail'] = '' === (string) $row->recipient ? '' : \PassCaptain\Pii::mask_email( (string) $row->recipient );
			} elseif ( 'webhooks' === $queue ) {
				$item['detail'] = 'Webhook #' . (int) $row->subscription_id . ( (int) $row->response_code ? ' · HTTP ' . (int) $row->response_code : '' );
			}
			$out[] = $item;
		}
		return array( 'rows' => $out, 'total' => $total );
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

	/** Error text for display: e-mail addresses masked, at most 190 characters. */
	public static function safe_error( $error ) {
		$error = (string) preg_replace( '/[^\s@<>"\']+@[^\s@<>"\']+/', '***@***', (string) $error );
		$error = trim( (string) preg_replace( '/\s+/', ' ', $error ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 190 ) : substr( $error, 0, 190 );
	}

	/** IDs of all failed items of a queue (oldest first, at most MAX_BULK). */
	public static function failed_ids( $queue ) {
		global $wpdb;
		$def = self::def( $queue );
		if ( ! $def ) { return array(); }
		$table = $wpdb->prefix . $def['table'];
		$id = $def['id'];
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT $id FROM $table WHERE status=%s ORDER BY $id LIMIT %d", $def['failed'], self::MAX_BULK ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column/table names.
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
		$now = current_time( 'mysql', true );
		$done = array();
		foreach ( $ids as $id ) {
			if ( 'webhooks' === $queue ) {
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE delivery_id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				if ( 'failed' === $status && true === Dispatcher::redeliver( $id ) ) { $done[] = $id; }
				continue;
			}
			if ( 'jobs' === $queue ) {
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued', attempts=0, run_after=%s, locked_at=NULL, last_error=NULL, updated_at=%s WHERE job_id=%d AND status='dead_letter'", $now, $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
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
		$set = 'messages' === $queue ? "status='cancelled', locked_at=NULL" : $wpdb->prepare( "status='cancelled', locked_at=NULL, updated_at=%s", current_time( 'mysql', true ) );
		$done = array();
		foreach ( $ids as $id ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE $id_col=%d AND status=%s", $id, $def['failed'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed names, prepared SET.
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
