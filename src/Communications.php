<?php
/** Idempotent queue for scheduled transactional messages. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Communications {
	public static function cron_schedule( $schedules ) {
		$schedules['wpcap_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Alle fünf Minuten (Pass Captain)', 'pass-captain' ) );
		return $schedules;
	}

	public static function enqueue( $key, $email, $template, array $payload, $send_at = '' ) {
		global $wpdb;
		$email = sanitize_email( $email );
		$key = sanitize_text_field( $key );
		$template = sanitize_key( $template );
		if ( ! is_email( $email ) || '' === $template || '' === $key ) { return new \WP_Error( 'wpcap_message_invalid', __( 'Nachrichtendaten ungültig.', 'pass-captain' ) ); }
		$key = hash( 'sha256', $key );
		$result = $wpdb->insert( $wpdb->prefix . 'wpcap_message_queue', array( 'dedupe_key' => $key, 'recipient' => $email, 'template_key' => $template, 'payload' => wp_json_encode( $payload ), 'send_at' => $send_at ? sanitize_text_field( $send_at ) : current_time( 'mysql', true ), 'status' => 'queued', 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
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
		foreach ( array_values( $keys ) as $i => $key ) {
			if ( microtime( true ) >= (float) $deadline ) { $left = true; break; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { $left = true; break; }
			$now = current_time( 'mysql', true );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE dedupe_key=%s AND status='queued' AND send_at<=%s FOR UPDATE", $table, hash( 'sha256', sanitize_text_field( $key ) ), $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); continue; }
			$claimed = $wpdb->update( $table, array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now ), array( 'message_id' => (int) $row->message_id, 'status' => 'queued' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== (int) $claimed || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); continue; }
			self::process_row( $table, $row );
			++$done;
		}
		if ( $left ) { Jobs\TimeBudget::continue_later( 'wpcap_process_messages' ); }
		return $done;
	}

	/** Maximum messages per run (filter: wpcap_message_batch_size); the time budget usually ends a run first. */
	const BATCH_SIZE = 500;

	/** Send attempts (counted when a worker claims the message) before a message fails for good. */
	const MAX_ATTEMPTS = 5;

	/**
	 * Sends due messages until the time budget (Jobs\TimeBudget, ~20 s) or the batch size is used up.
	 * When due messages remain, an immediate follow-up run is scheduled instead of waiting for the
	 * next five-minute tick. Returns the number of messages processed.
	 */
	public static function process_due() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$now = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
		// A message whose attempts are used up (the worker died while sending it every time) fails for good.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=IF(attempts>=%d,'failed','queued'),locked_at=NULL WHERE status='sending' AND locked_at<%s", $table, self::MAX_ATTEMPTS, $stale ) );
		$limit = max( 1, (int) apply_filters( 'wpcap_message_batch_size', self::BATCH_SIZE ) );
		$deadline = Jobs\TimeBudget::deadline();
		$processed = 0;
		$drained = false;
		$db_failed = false; // A database error ends the run; the regular schedule retries (no immediate follow-up).
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			// Mail is only sent once the claim is durable: transaction started, exactly this row moved from
			// queued to sending, commit succeeded. On any database error the claim is rolled back and the
			// message stays queued for the next run (never sent twice by parallel workers).
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { $db_failed = true; break; }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status='queued' AND send_at<=%s ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $table, $now ) );
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
			self::process_row( $table, $row );
			++$processed;
		}
		if ( ! $drained && ! $db_failed && null !== $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM %i WHERE status='queued' AND send_at<=%s LIMIT 1", $table, $now ) ) ) {
			Jobs\TimeBudget::continue_later( 'wpcap_process_messages' );
		}
		Ops\Heartbeat::beat( 'messages', $processed ); // System status: last completed worker run.
		return $processed;
	}

	/** Sends one claimed row and stores the outcome (sent / retry with backoff / failed). */
	private static function process_row( $table, $row ) {
		Jobs\TimeBudget::claimed( $table, 'message_id', (int) $row->message_id, 'sending', 'queued', 'failed', self::MAX_ATTEMPTS, array( 'due' => 'send_at', 'error' => 'last_error' ) );
		$error = __( 'Template fehlt oder wp_mail ist fehlgeschlagen.', 'pass-captain' );
		try {
			$sent = self::send_row( $row );
		} catch ( \Throwable $e ) {
			// A throwing template filter or mail plugin must not stop the queue (retried with backoff).
			$sent = false;
			$error = Jobs::exception_error( $e, $row )->get_error_message();
		}
		if ( $sent ) {
			Jobs::update_with_retry( $table, array( 'status' => 'sent', 'locked_at' => null, 'last_error' => null ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$tries = (int) $row->attempts + 1;
			Jobs::update_with_retry( $table, array( 'status' => $tries >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $tries ) ) ), 'locked_at' => null, 'last_error' => $error ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		}
		Jobs\TimeBudget::finished( $table, (int) $row->message_id );
	}

	/**
	 * Renders and sends one claimed queue row. The template comes from wp_pass_captain_email_template_v1
	 * (or the payload itself); optional payload keys: headers (string[], e.g. List-Unsubscribe).
	 *
	 * @return bool Whether the mail was accepted.
	 */
	private static function send_row( $row ) {
		$payload = json_decode( $row->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		if ( isset( $payload['mail_type'] ) ) {
			// Typed mail (enqueue_mail()): rendered now with the current template in the recipient's locale.
			$message = Mail\Mailer::compose( (string) $payload['mail_type'], (array) ( $payload['vars'] ?? array() ), (string) ( $payload['locale'] ?? '' ) );
			if ( ! $message ) { return false; }
			$headers = self::clean_headers( (array) ( $payload['headers'] ?? array() ) );
			if ( $headers ) { $message['headers'] = $headers; }
			return Mail\Mailer::deliver( $row->recipient, $message );
		}
		$template = apply_filters( 'wp_pass_captain_email_template_v1', null, $row->template_key, $payload );
		$template = is_array( $template ) ? $template : $payload;
		$subject = sanitize_text_field( $template['subject'] ?? '' );
		$body = (string) ( $template['body'] ?? '' );
		if ( '' === $subject || '' === $body ) { return false; }
		$message = array( 'type' => 'queue_' . $row->template_key, 'subject' => $subject, 'body' => $body, 'format' => 'html' === ( $template['format'] ?? 'text' ) ? 'html' : 'text', 'attachments' => array(), 'locale' => (string) ( $template['locale'] ?? '' ) );
		$headers = self::clean_headers( (array) ( $template['headers'] ?? array() ) );
		if ( $headers ) { $message['headers'] = $headers; }
		// Through the central mailer (layout for HTML bodies, `wpcap_mail` filter); reminders keep their own editor.
		return Mail\Mailer::deliver( $row->recipient, $message );
	}

	/** Extra headers of a queued message: single-line strings only (no header injection). */
	private static function clean_headers( array $headers ) {
		return array_values( array_filter( array_map( 'strval', $headers ), static function ( $h ) { return '' !== $h && false === strpbrk( $h, "\r\n" ) && false !== strpos( $h, ':' ); } ) );
	}
}
