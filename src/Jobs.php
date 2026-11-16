<?php
/**
 * Idempotent, retryable background jobs with a dead-letter state.
 *
 * Guarantees (A188, docs/OPERATIONS.md "Hintergrundverarbeitung"):
 * - A job is claimed by exactly one worker (row lock + conditional status change, committed before
 *   the handler runs); a claim that is not durable is never run.
 * - Execution is at-least-once: a worker that dies after the handler's side effect but before the
 *   job is marked complete leaves the job to be run again (claim released on shutdown, or taken back
 *   after 15 minutes). Handlers must be idempotent; they receive the job id to detect a repeat.
 * - A failed run (handler result other than true, WP_Error, exception, worker died) is retried with
 *   exponential backoff (backoff_seconds()); after MAX_ATTEMPTS runs the job is a dead letter, shown in
 *   the operations page and Site Health, audited and announced via `wpcap_job_dead_letter`.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Jobs {
	/** Runs (claims) of a job before it becomes a dead letter. */
	const MAX_ATTEMPTS = 8;

	/** Minutes after which a claim of a worker that died is taken back. */
	const STALE_MINUTES = 15;

	public static function enqueue( $key, $type, array $payload, $run_after = '' ) {
		global $wpdb;
		$key = sanitize_text_field( $key );
		$type = sanitize_key( $type );
		if ( '' === $key || '' === $type ) { return new \WP_Error( 'wpcap_job_invalid', __( 'Job-Schlüssel oder Job-Typ ungültig.', 'pass-captain' ) ); }
		$row = array( 'idempotency_key' => hash( 'sha256', $key ), 'job_type' => $type, 'payload' => wp_json_encode( $payload ), 'run_after' => $run_after ? sanitize_text_field( $run_after ) : current_time( 'mysql', true ), 'status' => 'queued', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		// A326/A328: the order a job belongs to (payload order_id, else the current log scope), for the
		// dead-letter list and the order history (schema 3.10.16).
		$order_id = absint( $payload['order_id'] ?? ( Log::current_scope()['order_id'] ?? 0 ) );
		if ( $order_id && Log::has_column( 'wpcap_jobs', 'order_id' ) ) {
			$row['order_id'] = $order_id;
			$formats[] = '%d';
		}
		$result = $wpdb->insert( $wpdb->prefix . 'wpcap_jobs', $row, $formats );
		if ( false === $result && false === strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) { return new \WP_Error( 'wpcap_job_enqueue', __( 'Job konnte nicht eingeplant werden.', 'pass-captain' ) ); }
		return true;
	}

	/** Wait before the next run after $attempts failed runs: 2, 4, 8, … minutes, at most one day. */
	public static function backoff_seconds( $attempts ) {
		return (int) min( DAY_IN_SECONDS, 60 * ( 2 ** min( max( 1, (int) $attempts ), 10 ) ) );
	}

	/**
	 * Runs due jobs until the shared cron time budget (Jobs\TimeBudget) or $limit is used up; the budget
	 * is checked before each claim, so no job is claimed that this request does not process. A
	 * remaining backlog triggers an immediate follow-up run. Returns the number of jobs processed.
	 */
	public static function process_due( $limit = 20, $type = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_jobs';
		$now = current_time( 'mysql', true );
		// Optional: only jobs of one type (e.g. the refunds of a cancellation run, BulkRuns).
		$type = is_string( $type ) ? sanitize_key( $type ) : '';
		$filter = '' === $type ? '' : $wpdb->prepare( ' AND job_type=%s', $type );
		self::recover_stale( $now );
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : 20;
		$deadline = Jobs\TimeBudget::deadline();
		$processed = 0;
		$drained = false;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { break; } // No row lock without a transaction.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE status='queued' AND run_after<=%s$filter ORDER BY run_after,job_id LIMIT 1 FOR UPDATE", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is prepared above.
			if ( '' !== $wpdb->last_error ) {
				// Lock wait timeout or lost connection: not "nothing due" (the follow-up run is kept).
				$wpdb->query( 'ROLLBACK' );
				break;
			}
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); $drained = true; break; }
			if ( (int) $row->attempts >= self::MAX_ATTEMPTS ) {
				// Its last run did not finish (worker died, claim released or taken back): no further run.
				// queued → running → dead_letter (StateMachine 'job') in one transaction, without a run.
				$error = (string) $row->last_error;
				$taken = StateMachine::transition( 'job', $table, 'job_id', (int) $row->job_id, 'running', array( 'locked_at' => $now, 'updated_at' => $now ) );
				$dead = 1 === $taken ? StateMachine::transition( 'job', $table, 'job_id', (int) $row->job_id, 'dead_letter', array( 'locked_at' => null, 'updated_at' => $now ) ) : false;
				if ( 1 !== $dead || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); break; }
				self::dead_letter( $row, '' !== $error ? $error : __( 'Der Worker wurde während der Ausführung beendet.', 'pass-captain' ) );
				continue;
			}
			$claimed = $wpdb->update( $table, array( 'status' => 'running', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now, 'updated_at' => $now ), array( 'job_id' => (int) $row->job_id, 'status' => 'queued' ), array( '%s', '%d', '%s', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== (int) $claimed || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) || ! self::claim_durable( (int) $row->job_id, (int) $row->attempts + 1, $now ) ) {
				// Claim not durable (database error, lost race, a COMMIT whose result is unknown, or one
				// that wpdb re-ran on a new session after a reconnect and that committed nothing): never
				// run the handler unclaimed. A claim that did commit is taken back as stale.
				$wpdb->query( 'ROLLBACK' );
				break;
			}
			$attempts = (int) $row->attempts + 1;
			// A worker that dies from here on (fatal error, time limit) releases the claim on shutdown,
			// with the backoff of a failed run; the stale recovery is the fallback.
			Jobs\TimeBudget::claimed( $table, 'job_id', (int) $row->job_id, 'running', 'queued', array( 'run_after' => gmdate( 'Y-m-d H:i:s', time() + self::backoff_seconds( $attempts ) ), 'last_error' => __( 'Der Worker wurde während der Ausführung beendet.', 'pass-captain' ) ) );
			++$processed;
			$payload = json_decode( $row->payload, true );
			$payload = is_array( $payload ) ? $payload : array();
			/* translators: %s: job type */
			$error = sprintf( __( 'Kein Handler hat den Job %s als erledigt gemeldet (Rückgabe ohne Fehlerangabe).', 'pass-captain' ), sanitize_key( $row->job_type ) );
			$scope = self::log_scope( $payload );
			Operations\Monitor::reset_last_mail_failure();
			try {
				// Entries of the support log (and mails) of this run belong to the job's order/ticket/occurrence.
				$handled = Log::scope( $scope, static function () use ( $row, $payload ) {
					$handled = apply_filters( 'wpcap_process_job', false, $row->job_type, $payload, (int) $row->job_id );
					return Hooks::legacy_filter( 'wpcap_process_job', $handled, $row->job_type, $payload, (int) $row->job_id );
				} );
				// A325: a handler explains a failed run with a WP_Error; that reason is the job's last_error.
				if ( $handled instanceof \WP_Error ) { $error = $handled->get_error_code() . ': ' . $handled->get_error_message(); }
			} catch ( \Throwable $e ) {
				// A crashing handler is a failed run (backoff, dead letter), not a job stuck in "running".
				$handled = false;
				$reference = Log::exception( 'job', $e, $scope + array( 'job_id' => (int) $row->job_id, 'job_type' => sanitize_key( $row->job_type ), 'attempt' => $attempts ) );
				$error = Redact::exception( $e ) . ( '' !== $reference ? ' [' . $reference . ']' : '' );
			}
			// A mail that failed during the run (wp_mail error of the site's mailer) explains it best.
			$mail_failure = true === $handled ? '' : Operations\Monitor::last_mail_failure_text();
			if ( '' !== $mail_failure && false === strpos( $error, $mail_failure ) ) { $error .= ' – ' . $mail_failure; }
			if ( true === $handled ) {
				$done = array( 'locked_at' => null, 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) );
				// Only this run's claim (running + its locked_at) may finish the job: a stale claim that was
				// re-queued and taken over by another worker is left to that worker. Once more on the
				// reconnected session after a lost connection; if that fails too, the job runs again later
				// (at-least-once).
				if ( false === self::mark( (int) $row->job_id, 'complete', $done, $now ) ) { self::mark( (int) $row->job_id, 'complete', $done, $now ); }
				Jobs\TimeBudget::finished( $table, (int) $row->job_id );
				continue;
			}
			$dead = $attempts >= self::MAX_ATTEMPTS;
			$to = $dead ? 'dead_letter' : 'queued';
			$failed = array( 'run_after' => gmdate( 'Y-m-d H:i:s', time() + self::backoff_seconds( $attempts ) ), 'locked_at' => null, 'last_error' => self::short_error( Redact::text( (string) $error ) ), 'updated_at' => current_time( 'mysql', true ) );
			$marked = self::mark( (int) $row->job_id, $to, $failed, $now );
			if ( false === $marked ) { $marked = self::mark( (int) $row->job_id, $to, $failed, $now ); }
			if ( false === $marked ) {
				continue; // The shutdown release (backoff, error note) puts it back.
			}
			Jobs\TimeBudget::finished( $table, (int) $row->job_id );
			Log::write( $dead ? 'error' : 'warning', 'job', ( $dead ? 'Job failed for good (dead letter): ' : 'Job run failed, retry planned: ' ) . sanitize_key( $row->job_type ) . ' – ' . $failed['last_error'], $scope + array( 'job_id' => (int) $row->job_id, 'attempt' => $attempts ) );
			if ( $dead && 1 === $marked ) { self::dead_letter( $row, $failed['last_error'] ); }
		}
		if ( ! $drained && null !== $wpdb->get_var( $wpdb->prepare( "SELECT job_id FROM $table WHERE status='queued' AND run_after<=%s$filter LIMIT 1", $now ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is prepared above.
			Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' );
		}
		return $processed;
	}

	/**
	 * Takes back claims of workers that died without the shutdown release (e.g. killed process)
	 * after STALE_MINUTES: queued again with the backoff of a failed run; a job at MAX_ATTEMPTS
	 * becomes a dead letter on its next claim.
	 */
	private static function recover_stale( $now ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_jobs';
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued',locked_at=NULL,run_after=DATE_ADD(%s, INTERVAL LEAST(%d, 60*POW(2, LEAST(GREATEST(attempts,1),10))) SECOND),last_error=%s,updated_at=%s WHERE status='running' AND locked_at<%s", $now, DAY_IN_SECONDS, __( 'Der Worker wurde während der Ausführung beendet (Reservierung nach 15 Minuten zurückgeholt).', 'pass-captain' ), $now, $stale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
	}

	/**
	 * Ends this run's claim: running → $to (StateMachine 'job'), only while the job still carries this
	 * run's lock time (A184). Returns changed rows (0: the claim was taken over), false on a database error.
	 */
	private static function mark( $job_id, $to, array $data, $locked_at ) {
		global $wpdb;
		return StateMachine::transition( 'job', $wpdb->prefix . 'wpcap_jobs', 'job_id', (int) $job_id, $to, $data, array( 'status' => 'running', 'locked_at' => $locked_at ) );
	}

	/** Whether this run's claim is committed (A183): the job is running with this run's attempt and lock time. */
	private static function claim_durable( $job_id, $attempts, $locked_at ) {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT job_id FROM {$wpdb->prefix}wpcap_jobs WHERE job_id=%d AND status='running' AND attempts=%d AND locked_at=%s", $job_id, $attempts, $locked_at );
		$found = $wpdb->get_var( $sql );
		if ( '' !== $wpdb->last_error ) { $found = $wpdb->get_var( $sql ); } // Once more on the reconnected session.
		return (int) $found === (int) $job_id;
	}

	/** order_id/ticket_id/occurrence_id of a job payload (support log scope). */
	public static function log_scope( array $payload ) {
		$out = array();
		foreach ( array( 'order_id', 'ticket_id', 'occurrence_id' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_numeric( $payload[ $key ] ) && (int) $payload[ $key ] > 0 ) { $out[ $key ] = (int) $payload[ $key ]; }
		}
		return $out;
	}

	/** Error text for last_error: one line, at most 500 characters, e-mail addresses masked. */
	private static function short_error( $error ) {
		$error = preg_replace( '/\s+/', ' ', (string) $error );
		$error = preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/', '…@…', (string) $error );
		return mb_substr( trim( (string) $error ), 0, 500 );
	}

	/** A job reached its last attempt: audited and announced (operations page and Site Health list it). */
	private static function dead_letter( $row, $error ) {
		Audit::record( 'job_dead_letter', 'job', (int) $row->job_id, array( 'type' => (string) $row->job_type, 'attempts' => max( (int) $row->attempts, self::MAX_ATTEMPTS ) ) );
		/**
		 * A background job failed for good (dead letter) after Jobs::MAX_ATTEMPTS runs.
		 *
		 * @param int    $job_id Job ID.
		 * @param string $type   Job type.
		 * @param string $error  Last error (masked, at most 500 characters).
		 */
		do_action( 'wpcap_job_dead_letter', (int) $row->job_id, (string) $row->job_type, (string) $error );
	}
}
