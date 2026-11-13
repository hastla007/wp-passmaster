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

	/** Maximum messages per run (filter: wpcap_message_batch_size); the time budget usually ends a run first. */
	const BATCH_SIZE = 500;

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
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued',locked_at=NULL WHERE status='sending' AND locked_at<%s", $stale ) );
		$limit = max( 1, (int) apply_filters( 'wpcap_message_batch_size', self::BATCH_SIZE ) );
		$deadline = Jobs\TimeBudget::deadline();
		$processed = 0;
		$drained = false;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { break; } // No row lock without a transaction.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE status='queued' AND send_at<=%s ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $now ) );
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
			$template = apply_filters( 'wpcap_email_template', null, $row->template_key, $payload );
			$template = Hooks::legacy_filter( 'wpcap_email_template', $template, $row->template_key, $payload );
			$template = is_array( $template ) ? $template : ( is_array( $payload ) ? $payload : array() );
			$subject = sanitize_text_field( $template['subject'] ?? '' );
			$body = (string) ( $template['body'] ?? '' );
			// Through the central mailer (layout for HTML bodies, `wpcap_mail` filter); reminders keep their own editor.
			$sent = '' !== $subject && '' !== $body && Mail\Mailer::deliver( $row->recipient, array( 'type' => 'queue_' . $row->template_key, 'subject' => $subject, 'body' => $body, 'format' => 'html' === ( $template['format'] ?? 'text' ) ? 'html' : 'text', 'attachments' => array(), 'locale' => (string) ( $template['locale'] ?? '' ) ) );
			if ( $sent ) {
				$done = array( 'status' => 'sent', 'locked_at' => null, 'last_error' => null );
				// Once more after a lost connection: an unmarked mail would be sent again (at-least-once).
				if ( false === $wpdb->update( $table, $done, array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) {
					$wpdb->update( $table, $done, array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
				}
			}
			else {
				$tries = (int) $row->attempts + 1;
				$wpdb->update( $table, array( 'status' => $tries >= 5 ? 'failed' : 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $tries ) ) ), 'locked_at' => null, 'last_error' => __( 'Template fehlt oder wp_mail ist fehlgeschlagen.', 'pass-captain' ) ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
			}
			Jobs\TimeBudget::finished( $table, (int) $row->message_id );
			++$processed;
		}
		if ( ! $drained && null !== $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM $table WHERE status='queued' AND send_at<=%s LIMIT 1", $now ) ) ) {
			Jobs\TimeBudget::continue_later( 'wpcap_process_messages' );
		}
		return $processed;
	}
}
