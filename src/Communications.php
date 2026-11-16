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

	/** Maximum messages per run (filter: wpcap_message_batch_size); the time budget usually ends a run first. */
	const BATCH_SIZE = 500;

	/**
	 * Sends due messages until the time budget (Jobs\TimeBudget, ~20 s) or the batch size is used up.
	 * When due messages remain, an immediate follow-up run is scheduled instead of waiting for the
	 * next five-minute tick. Returns the number of messages processed.
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
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued',locked_at=NULL WHERE status='sending' AND locked_at<%s", $stale ) );
		$limit = max( 1, (int) apply_filters( 'wpcap_message_batch_size', self::BATCH_SIZE ) );
		$deadline = Jobs\TimeBudget::deadline();
		$processed = 0;
		$drained = false;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { break; } // No row lock without a transaction.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE status='queued' AND send_at<=%s$filter ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is prepared above.
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); $drained = true; break; }
			$claimed = $wpdb->update( $table, array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now ), array( 'message_id' => (int) $row->message_id, 'status' => 'queued' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== (int) $claimed || false === $wpdb->query( 'COMMIT' ) ) {
				// Claim not durable (database error or lost race): never send unclaimed (duplicate mails).
				$wpdb->query( 'ROLLBACK' );
				break;
			}
			Jobs\TimeBudget::claimed( $table, 'message_id', (int) $row->message_id, 'sending', 'queued' );
			$payload = json_decode( $row->payload, true );
			$payload  = is_array( $payload ) ? $payload : array();
			$template = apply_filters( 'wpcap_email_template', null, $row->template_key, $payload, (string) $row->recipient );
			$template = Hooks::legacy_filter( 'wpcap_email_template', $template, $row->template_key, $payload );
			// A652: only a renderer (filter `wpcap_email_template`) makes a mail; the stored payload is data,
			// never a template (no subject/body taken from it). Without a renderer: "template missing".
			$template = is_array( $template ) ? $template : array();
			$subject = sanitize_text_field( $template['subject'] ?? '' );
			$body = (string) ( $template['body'] ?? '' );
			// Through the central mailer (layout for HTML bodies, `wpcap_mail` filter); reminders keep their own editor.
			// A fully composed message (e.g. BulkRuns: mail type, locale, attachments) is sent as it is.
			$composed = isset( $template['message'] ) && is_array( $template['message'] );
			$scope = Jobs::log_scope( $payload );
			if ( ! empty( $row->order_id ) ) { $scope['order_id'] = (int) $row->order_id; }
			Operations\Monitor::reset_last_mail_failure();
			$sent = Log::scope( $scope, static function () use ( $composed, $template, $row, $subject, $body ) {
				return $composed
					? Mail\Mailer::deliver( $row->recipient, $template['message'] )
					: '' !== $subject && '' !== $body && Mail\Mailer::deliver( $row->recipient, array( 'type' => 'queue_' . $row->template_key, 'subject' => $subject, 'body' => $body, 'format' => 'html' === ( $template['format'] ?? 'text' ) ? 'html' : 'text', 'attachments' => array(), 'locale' => (string) ( $template['locale'] ?? '' ) ) );
			} );
			Mail\TempFiles::release( array_filter( (array) ( $template['temp_files'] ?? array() ), 'is_string' ) ); // A626.
			if ( $sent ) {
				$done = array( 'status' => 'sent', 'locked_at' => null, 'last_error' => null );
				$done_formats = array( '%s', '%s', '%s' );
				if ( Log::has_column( 'wpcap_message_queue', 'sent_at' ) ) {
					$done['sent_at'] = current_time( 'mysql', true ); // A328: when it went out (order history).
					$done_formats[] = '%s';
				}
				// Once more after a lost connection: an unmarked mail would be sent again (at-least-once).
				if ( false === $wpdb->update( $table, $done, array( 'message_id' => (int) $row->message_id ), $done_formats, array( '%d' ) ) ) {
					$wpdb->update( $table, $done, array( 'message_id' => (int) $row->message_id ), $done_formats, array( '%d' ) );
				}
			}
			else {
				$tries = (int) $row->attempts + 1;
				// A325: the actual reason – the error of the site's mailer (wp_mail_failed), or a missing template.
				$mail_failure = Operations\Monitor::last_mail_failure_text();
				if ( '' !== $mail_failure ) {
					$reason = $mail_failure;
				} elseif ( $composed || ( '' !== $subject && '' !== $body ) ) {
					$reason = __( 'wp_mail hat den Versand ohne Fehlermeldung abgelehnt (Rückgabe false).', 'pass-captain' );
				} else {
					/* translators: %s: message template key */
					$reason = sprintf( __( 'Für die Vorlage %s gibt es keinen Betreff oder Text (Vorlage fehlt oder Modul deaktiviert).', 'pass-captain' ), (string) $row->template_key );
				}
				$wpdb->update( $table, array( 'status' => $tries >= 5 ? 'failed' : 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $tries ) ) ), 'locked_at' => null, 'last_error' => Operations\Queues::safe_error( $reason ) ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
				if ( $tries >= 5 ) {
					Log::error( 'mail', 'Queued message failed for good: ' . sanitize_key( (string) $row->template_key ) . ' – ' . $reason, $scope + array( 'message_id' => (int) $row->message_id ) );
				}
			}
			Jobs\TimeBudget::finished( $table, (int) $row->message_id );
			++$processed;
		}
		if ( ! $drained && null !== $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM $table WHERE status='queued' AND send_at<=%s$filter LIMIT 1", $now ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is prepared above.
			Jobs\TimeBudget::continue_later( 'wpcap_process_messages' );
		}
		return $processed;
	}
}
