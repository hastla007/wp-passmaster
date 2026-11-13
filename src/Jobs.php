<?php
/** Idempotent, retryable background jobs with a dead-letter state. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Jobs {
	public static function enqueue( $key, $type, array $payload, $run_after = '' ) {
		global $wpdb;
		$key = sanitize_text_field( $key );
		$type = sanitize_key( $type );
		if ( '' === $key || '' === $type ) { return new \WP_Error( 'wpcap_job_invalid', __( 'Job-Schlüssel oder Job-Typ ungültig.', 'pass-captain' ) ); }
		$result = $wpdb->insert( $wpdb->prefix . 'wpcap_jobs', array( 'idempotency_key' => hash( 'sha256', $key ), 'job_type' => $type, 'payload' => wp_json_encode( $payload ), 'run_after' => $run_after ? sanitize_text_field( $run_after ) : current_time( 'mysql', true ), 'status' => 'queued', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $result && false === strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) { return new \WP_Error( 'wpcap_job_enqueue', __( 'Job konnte nicht eingeplant werden.', 'pass-captain' ) ); }
		return true;
	}

	/**
	 * Runs due jobs until the shared cron time budget (Jobs\TimeBudget) or $limit is used up; the budget
	 * is checked before each claim, so no job is claimed that this request does not process. A
	 * remaining backlog triggers an immediate follow-up run. Returns the number of jobs processed.
	 */
	public static function process_due( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_jobs';
		$now = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued',locked_at=NULL WHERE status='running' AND locked_at<%s", $stale ) );
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : 20;
		$deadline = Jobs\TimeBudget::deadline();
		$processed = 0;
		$drained = false;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { break; } // No row lock without a transaction.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE status='queued' AND run_after<=%s ORDER BY run_after,job_id LIMIT 1 FOR UPDATE", $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); $drained = true; break; }
			$claimed = $wpdb->update( $table, array( 'status' => 'running', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now, 'updated_at' => $now ), array( 'job_id' => (int) $row->job_id, 'status' => 'queued' ), array( '%s', '%d', '%s', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== (int) $claimed || false === $wpdb->query( 'COMMIT' ) ) {
				// Claim not durable (database error or lost race): never run the handler unclaimed.
				$wpdb->query( 'ROLLBACK' );
				break;
			}
			Jobs\TimeBudget::claimed( $table, 'job_id', (int) $row->job_id, 'running', 'queued' );
			++$processed;
			$payload = json_decode( $row->payload, true );
			$payload = is_array( $payload ) ? $payload : array();
			$handled = apply_filters( 'wpcap_process_job', false, $row->job_type, $payload, (int) $row->job_id );
			$handled = Hooks::legacy_filter( 'wpcap_process_job', $handled, $row->job_type, $payload, (int) $row->job_id );
			Jobs\TimeBudget::finished( $table, (int) $row->job_id );
			if ( true === $handled ) {
				$wpdb->update( $table, array( 'status' => 'complete', 'locked_at' => null, 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'job_id' => (int) $row->job_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
				continue;
			}
			$attempts = (int) $row->attempts + 1;
			$status = $attempts >= 8 ? 'dead_letter' : 'queued';
			$wpdb->update( $table, array( 'status' => $status, 'run_after' => gmdate( 'Y-m-d H:i:s', time() + min( DAY_IN_SECONDS, 60 * ( 2 ** min( $attempts, 10 ) ) ) ), 'locked_at' => null, 'last_error' => 'No handler acknowledged the job.', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_id' => (int) $row->job_id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		}
		if ( ! $drained && null !== $wpdb->get_var( $wpdb->prepare( "SELECT job_id FROM $table WHERE status='queued' AND run_after<=%s LIMIT 1", $now ) ) ) {
			Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' );
		}
		return $processed;
	}
}
