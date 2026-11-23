<?php
/** Idempotent queue for scheduled transactional messages. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Communications {
	public static function cron_schedule( $schedules ) {
		$schedules['wpcap_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Alle fünf Minuten (Pass Captain)', 'pass-captain' ) );
		return $schedules;
	}

	/**
	 * Queues a message (deduplicated by $key).
	 *
	 * @param int $order_id Order the message belongs to (order history, dead letters; schema 3.10.16).
	 *                      0 = payload order_id, else the current support log scope.
	 */
	public static function enqueue( $key, $email, $template, array $payload, $send_at = '', $order_id = 0 ) {
		global $wpdb;
		$email = sanitize_email( $email );
		$key = sanitize_text_field( $key );
		$template = sanitize_key( $template );
		if ( ! is_email( $email ) || '' === $template || '' === $key ) { return new \WP_Error( 'wpcap_message_invalid', __( 'Nachrichtendaten ungültig.', 'pass-captain' ) ); }
		$key = hash( 'sha256', $key );
		$row = array( 'dedupe_key' => $key, 'recipient' => $email, 'template_key' => $template, 'payload' => wp_json_encode( $payload ), 'send_at' => $send_at ? sanitize_text_field( $send_at ) : current_time( 'mysql', true ), 'status' => 'queued', 'created_at' => current_time( 'mysql', true ) );
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$order_id = absint( $order_id ) ?: absint( $payload['order_id'] ?? ( Log::current_scope()['order_id'] ?? 0 ) );
		if ( $order_id && Log::has_column( 'wpcap_message_queue', 'order_id' ) ) {
			$row['order_id'] = $order_id;
			$formats[] = '%d';
		}
		$result = $wpdb->insert( $wpdb->prefix . 'wpcap_message_queue', $row, $formats );
		if ( false === $result && false === strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) { return new \WP_Error( 'wpcap_message_enqueue', __( 'Nachricht konnte nicht eingeplant werden.', 'pass-captain' ) ); }
		return true;
	}

	/**
	 * Seconds of queued mail a request may send right away (Communications::run_now() from bulk
	 * notifications such as reschedules and event cancellations); the rest goes to the worker.
	 *
	 * @internal Tests set it to 0 to leave everything queued.
	 * @var int
	 */
	public static $inline_seconds = 10;

	/**
	 * Queues a mail of a registered type (Mail\Types) for one recipient. It is rendered when it is sent
	 * (current template, recipient locale); variables must be JSON-safe (scalars or Mailer::block()).
	 * The dedupe key makes a repeated call (job retry, double submit) a no-op for this recipient.
	 *
	 * @param string $key    Dedupe key, e.g. "resched|12|2030-01-02 18:00:00|anna@example.com".
	 * @param string $ref    Progress reference stored first in the payload (see progress()), e.g. "resched|12|…".
	 * @return true|\WP_Error
	 */
	public static function enqueue_mail( $key, $email, $type, array $vars, $locale = '', $ref = '', $send_at = '' ) {
		$payload = array( 'ref' => (string) $ref, 'mail_type' => (string) $type, 'vars' => $vars, 'locale' => (string) $locale );
		return self::enqueue( $key, $email, 'mail_' . $type, $payload, $send_at );
	}

	/**
	 * Delivery progress of queued mails whose payload ref starts with $ref (see enqueue_mail()).
	 *
	 * @return int[] queued, sending, sent, failed, cancelled.
	 */
	public static function progress( $ref ) {
		global $wpdb;
		$out = array( 'queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0 );
		// Payload starts with {"ref":"<ref>…; the literal prefix is escaped for LIKE (same as $wpdb->esc_like()).
		$prefix = '{"ref":' . substr( (string) wp_json_encode( (string) $ref ), 0, -1 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n FROM %i WHERE payload LIKE %s GROUP BY status', $wpdb->prefix . 'wpcap_message_queue', addcslashes( $prefix, '_%\\' ) . '%' ) );
		foreach ( (array) $rows as $row ) { $out[ (string) $row->status ] = (int) $row->n; }
		return $out;
	}

	/**
	 * Sends specific queued messages (by dedupe key) right away until $deadline (Unix time, may be
	 * fractional). Same claim protocol as process_due(): a message another worker claimed is skipped,
	 * nothing is sent twice. Whatever is left (deadline, backoff) stays queued for the worker, which is
	 * asked for an immediate follow-up run.
	 *
	 * @param string[] $keys Dedupe keys as passed to enqueue().
	 * @return int Number of messages processed.
	 */
	public static function run_now( array $keys, $deadline ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$done = 0;
		$left = false;
		// A623: the per-minute rate also applies to mails sent right away.
		$rate = self::rate_per_minute();
		$budget = $rate > 0 ? max( 0, $rate - self::sent_last_minute( Clock::now() ) ) : PHP_INT_MAX;
		foreach ( array_values( $keys ) as $i => $key ) {
			if ( microtime( true ) >= (float) $deadline || $budget <= 0 ) { $left = true; break; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { $left = true; break; }
			$now = current_time( 'mysql', true );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE dedupe_key=%s AND status='queued' AND send_at<=%s FOR UPDATE", $table, hash( 'sha256', sanitize_text_field( $key ) ), $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); continue; }
			$claimed = $wpdb->update( $table, array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now ), array( 'message_id' => (int) $row->message_id, 'status' => 'queued' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== (int) $claimed || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); continue; }
			self::process_row( $table, $row, $budget );
			++$done;
		}
		if ( $left ) { Jobs\TimeBudget::continue_later( 'wpcap_process_messages' ); }
		return $done;
	}

	/** Maximum messages per run (filter: wpcap_message_batch_size); the time budget usually ends a run first. */
	const BATCH_SIZE = 500;

	/** Send attempts (counted when a worker claims the message) before a message fails for good. */
	const MAX_ATTEMPTS = 5;

	/** A623: deferrals of one message because of a provider limit before it counts as a failure. */
	const MAX_LIMIT_DEFERRALS = 24;

	/**
	 * A623: queued mails per minute (setting mail_rate_per_minute, filter wpcap_message_rate_per_minute);
	 * 0 = unlimited.
	 */
	public static function rate_per_minute() {
		/**
		 * Maximum queued mails sent per minute (A623), e.g. the limit of the mail provider. 0 = no limit.
		 *
		 * @param int $rate Setting `mail_rate_per_minute`.
		 */
		return max( 0, (int) apply_filters( 'wpcap_message_rate_per_minute', (int) Settings::get( 'mail_rate_per_minute', 0 ) ) );
	}

	/** A623: mails of the queue sent during the last minute (needs sent_at, schema 3.10.16). */
	private static function sent_last_minute( $now_ts ) {
		global $wpdb;
		if ( ! Log::has_column( 'wpcap_message_queue', 'sent_at' ) ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='sent' AND sent_at>%s", $wpdb->prefix . 'wpcap_message_queue', gmdate( 'Y-m-d H:i:s', (int) $now_ts - MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * A623: whether a mail failure is a provider limit (rate, quota, "too many", SMTP 421/450/451/452,
	 * enhanced status 4.7.x) – such a mail is deferred, not counted as a failed attempt.
	 *
	 * @param string $reason Error text of the mailer.
	 */
	public static function is_limit_error( $reason ) {
		$hit = (bool) preg_match( '/rate.?limit|quota|too many|throttl|try again later|limit exceeded|sending limit|\b(?:421|450|451|452)\b|\b4\.7\.\d+\b/i', (string) $reason );
		/**
		 * Whether a mail error means "provider limit, retry later" (A623).
		 *
		 * @param bool   $hit    Built-in detection.
		 * @param string $reason Error text.
		 */
		return (bool) apply_filters( 'wpcap_mail_limit_error', $hit, (string) $reason );
	}

	/**
	 * Sends due messages until the time budget (Jobs\TimeBudget, ~20 s), the batch size or the
	 * per-minute rate (A623) is used up. When due messages remain (and the rate allows), an
	 * immediate follow-up run is scheduled instead of waiting for the next five-minute tick.
	 * Returns the number of messages processed.
	 */
	public static function process_due() {
		return self::process( '' );
	}

	/**
	 * Sends the due messages of one template key only (e.g. the mails of one cancellation run,
	 * BulkRuns), with the same claim, retry and time-budget rules as process_due().
	 */
	public static function process_template( $template_key ) {
		$template_key = sanitize_key( (string) $template_key );
		return '' === $template_key ? 0 : self::process( $template_key );
	}

	private static function process( $only ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$now = current_time( 'mysql', true );
		$filter = '' === $only ? '' : $wpdb->prepare( ' AND template_key=%s', $only );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
		// A message whose attempts are used up (the worker died while sending it every time) fails for good.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=IF(attempts>=%d,'failed','queued'),locked_at=NULL WHERE status='sending' AND locked_at<%s", $table, self::MAX_ATTEMPTS, $stale ) );
		$limit = max( 1, (int) apply_filters( 'wpcap_message_batch_size', self::BATCH_SIZE ) );
		// A623: at most rate_per_minute() mails per minute; the rest waits for the next run.
		$rate = self::rate_per_minute();
		$budget = $rate > 0 ? max( 0, $rate - self::sent_last_minute( Clock::now() ) ) : PHP_INT_MAX;
		$deadline = Jobs\TimeBudget::deadline();
		$processed = 0;
		$drained = false;
		$db_failed = false; // A database error ends the run; the regular schedule retries (no immediate follow-up).
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			if ( $budget <= 0 ) { break; }
			// Mail is only sent once the claim is durable: transaction started, exactly this row moved from
			// queued to sending, commit succeeded. On any database error the claim is rolled back and the
			// message stays queued for the next run (never sent twice by parallel workers).
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { $db_failed = true; break; }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status='queued' AND send_at<=%s$filter ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $table, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is prepared above.
			if ( ! $row ) {
				if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); $db_failed = true; break; }
				$wpdb->query( 'COMMIT' );
				$drained = true;
				break;
			}
			$claimed = $wpdb->update( $table, array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now ), array( 'message_id' => (int) $row->message_id, 'status' => 'queued' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			if ( false === $claimed ) { $wpdb->query( 'ROLLBACK' ); $db_failed = true; break; }
			if ( 1 !== (int) $claimed ) { $wpdb->query( 'ROLLBACK' ); continue; }
			if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); $db_failed = true; break; }
			self::process_row( $table, $row, $budget );
			++$processed;
		}
		if ( $rate > 0 && $budget <= 0 && ! $drained ) {
			// A623: rate used up (or provider limit) – the five-minute cron continues; no immediate follow-up run.
			Ops\Heartbeat::beat( 'messages', $processed );
			return $processed;
		}
		if ( ! $drained && ! $db_failed && null !== $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM %i WHERE status='queued' AND send_at<=%s$filter LIMIT 1", $table, $now ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is prepared above.
			Jobs\TimeBudget::continue_later( 'wpcap_process_messages' );
		}
		Ops\Heartbeat::beat( 'messages', $processed ); // System status: last completed worker run.
		return $processed;
	}

	/**
	 * Sends one claimed row and stores the outcome: sent / cancelled (renderer says skip, A689) /
	 * deferred (provider limit, A623: no attempt used, $budget set to 0 to end the run) / retry with
	 * backoff / failed. A throwing renderer or mail plugin never stops the queue.
	 *
	 * @param int $budget Remaining sends of this run (A623 rate); decremented per sent mail.
	 */
	private static function process_row( $table, $row, &$budget = PHP_INT_MAX ) {
		Jobs\TimeBudget::claimed( $table, 'message_id', (int) $row->message_id, 'sending', 'queued', 'failed', self::MAX_ATTEMPTS, array( 'due' => 'send_at', 'error' => 'last_error' ) );
		$payload = json_decode( (string) $row->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		$scope = Jobs::log_scope( $payload );
		if ( ! empty( $row->order_id ) ) { $scope['order_id'] = (int) $row->order_id; }
		$outcome = array( 'sent' => false, 'skip' => '', 'renderable' => false, 'temp_files' => array() );
		$reason = '';
		Operations\Monitor::reset_last_mail_failure();
		try {
			$outcome = Log::scope( $scope, static function () use ( $row, $payload ) {
				return self::send_row( $row, $payload );
			} );
		} catch ( \Throwable $e ) {
			// A throwing template filter or mail plugin must not stop the queue (retried with backoff).
			$reason = Jobs::exception_error( $e, $row )->get_error_message();
		}
		Mail\TempFiles::release( array_filter( (array) ( $outcome['temp_files'] ?? array() ), 'is_string' ) ); // A626.
		if ( '' !== (string) $outcome['skip'] ) {
			// A689: the recipient opted out (or the message is obsolete) – cancelled, never sent.
			Jobs::update_with_retry( $table, array( 'status' => 'cancelled', 'locked_at' => null, 'last_error' => sanitize_key( (string) $outcome['skip'] ) ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		} elseif ( $outcome['sent'] ) {
			--$budget;
			$done = array( 'status' => 'sent', 'locked_at' => null, 'last_error' => null );
			$done_formats = array( '%s', '%s', '%s' );
			if ( Log::has_column( 'wpcap_message_queue', 'sent_at' ) ) {
				$done['sent_at'] = current_time( 'mysql', true ); // A328: when it went out (order history).
				$done_formats[] = '%s';
			}
			// Retried after a lost connection: an unmarked mail would be sent again (at-least-once).
			Jobs::update_with_retry( $table, $done, array( 'message_id' => (int) $row->message_id ), $done_formats, array( '%d' ) );
		} else {
			$tries = (int) $row->attempts + 1;
			if ( '' === $reason ) {
				// A325: the actual reason – the error of the site's mailer (wp_mail_failed), or a missing template.
				$mail_failure = Operations\Monitor::last_mail_failure_text();
				if ( '' !== $mail_failure ) {
					$reason = $mail_failure;
				} elseif ( $outcome['renderable'] ) {
					$reason = __( 'wp_mail hat den Versand ohne Fehlermeldung abgelehnt (Rückgabe false).', 'pass-captain' );
				} else {
					/* translators: %s: message template key */
					$reason = sprintf( __( 'Für die Vorlage %s gibt es keinen Betreff oder Text (Vorlage fehlt oder Modul deaktiviert).', 'pass-captain' ), (string) $row->template_key );
				}
			}
			$deferrals = (int) ( $payload['_limit_deferrals'] ?? 0 );
			if ( self::is_limit_error( $reason ) && $deferrals < self::MAX_LIMIT_DEFERRALS ) {
				// A623: provider limit – try again in 15 minutes without using up an attempt, and stop
				// this run (the next mails would hit the same limit).
				$payload['_limit_deferrals'] = $deferrals + 1;
				Jobs::update_with_retry( $table, array( 'status' => 'queued', 'attempts' => (int) $row->attempts, 'payload' => wp_json_encode( $payload ), 'send_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + 15 * MINUTE_IN_SECONDS ), 'locked_at' => null, 'last_error' => Operations\Queues::safe_error( $reason ) ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%d', '%s', '%s', '%s', '%s' ), array( '%d' ) );
				$budget = 0;
			} else {
				$final = $tries >= self::MAX_ATTEMPTS;
				Jobs::update_with_retry( $table, array( 'status' => $final ? 'failed' : 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $tries ) ) ), 'locked_at' => null, 'last_error' => Operations\Queues::safe_error( $reason ) ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
				if ( $final ) {
					Log::error( 'mail', 'Queued message failed for good: ' . sanitize_key( (string) $row->template_key ) . ' – ' . $reason, $scope + array( 'message_id' => (int) $row->message_id ) );
				}
			}
		}
		Jobs\TimeBudget::finished( $table, (int) $row->message_id );
	}

	/**
	 * Renders and sends one claimed queue row.
	 *
	 * - Typed mail (enqueue_mail()): rendered now with the current template in the recipient's locale.
	 * - Otherwise a renderer (filter `wpcap_email_template`, legacy wp_pass_captain_email_template_v1)
	 *   returns subject/body (format, locale, headers), a fully composed `message` (BulkRuns: mail
	 *   type, locale, attachments), `skip` (A689: cancelled) and optional `temp_files` (A626: released
	 *   after sending). A652: the stored payload is data, never a template – without a renderer the
	 *   message fails as "template missing".
	 * Optional headers (string[], e.g. List-Unsubscribe) are single-line strings only.
	 *
	 * @return array sent (bool), skip (string), renderable (bool), temp_files (string[]).
	 */
	private static function send_row( $row, array $payload ) {
		$out = array( 'sent' => false, 'skip' => '', 'renderable' => false, 'temp_files' => array() );
		if ( isset( $payload['mail_type'] ) ) {
			$message = Mail\Mailer::compose( (string) $payload['mail_type'], (array) ( $payload['vars'] ?? array() ), (string) ( $payload['locale'] ?? '' ) );
			if ( ! $message ) { return $out; }
			$out['renderable'] = true;
			$headers = self::clean_headers( (array) ( $payload['headers'] ?? array() ) );
			if ( $headers ) { $message['headers'] = $headers; }
			if ( ! empty( $row->order_id ) ) { $message['order_id'] = (int) $row->order_id; }
			$out['sent'] = (bool) Mail\Mailer::deliver( $row->recipient, $message );
			return $out;
		}
		$template = apply_filters( 'wpcap_email_template', null, $row->template_key, $payload, (string) $row->recipient );
		$template = Hooks::legacy_filter( 'wpcap_email_template', $template, $row->template_key, $payload, (string) $row->recipient ); // Same arguments for callbacks of the former name (recipient: unsubscribe headers, opt-outs).
		$template = is_array( $template ) ? $template : array();
		$out['temp_files'] = (array) ( $template['temp_files'] ?? array() );
		if ( ! empty( $template['skip'] ) ) {
			$out['skip'] = (string) $template['skip'];
			return $out;
		}
		if ( isset( $template['message'] ) && is_array( $template['message'] ) ) {
			$out['renderable'] = true;
			$out['sent'] = (bool) Mail\Mailer::deliver( $row->recipient, $template['message'] );
			return $out;
		}
		$subject = sanitize_text_field( $template['subject'] ?? '' );
		$body = (string) ( $template['body'] ?? '' );
		if ( '' === $subject || '' === $body ) { return $out; }
		$out['renderable'] = true;
		$message = array( 'type' => 'queue_' . $row->template_key, 'subject' => $subject, 'body' => $body, 'format' => 'html' === ( $template['format'] ?? 'text' ) ? 'html' : 'text', 'attachments' => array(), 'locale' => (string) ( $template['locale'] ?? '' ) );
		$headers = self::clean_headers( (array) ( $template['headers'] ?? array() ) );
		if ( $headers ) { $message['headers'] = $headers; }
		if ( ! empty( $row->order_id ) ) { $message['order_id'] = (int) $row->order_id; }
		// Through the central mailer (layout for HTML bodies, `wpcap_mail` filter); reminders keep their own editor.
		$out['sent'] = (bool) Mail\Mailer::deliver( $row->recipient, $message );
		return $out;
	}

	/** Extra headers of a queued message: single-line strings only (no header injection). */
	private static function clean_headers( array $headers ) {
		return array_values( array_filter( array_map( 'strval', $headers ), static function ( $h ) { return '' !== $h && false === strpbrk( $h, "\r\n" ) && false !== strpos( $h, ':' ); } ) );
	}
}
