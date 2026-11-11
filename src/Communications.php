<?php
/** Idempotent queue for scheduled transactional messages. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Communications {
	public static function cron_schedule( $schedules ) {
		$schedules['wpcap_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Alle fünf Minuten (WP Pass Captain)', 'wp-pass-captain' ) );
		return $schedules;
	}

	public static function enqueue( $key, $email, $template, array $payload, $send_at = '' ) {
		global $wpdb;
		$email = sanitize_email( $email );
		$key = sanitize_text_field( $key );
		$template = sanitize_key( $template );
		if ( ! is_email( $email ) || '' === $template || '' === $key ) { return new \WP_Error( 'wpcap_message_invalid', __( 'Nachrichtendaten ungültig.', 'wp-pass-captain' ) ); }
		$key = hash( 'sha256', $key );
		$result = $wpdb->insert( $wpdb->prefix . 'wpcap_message_queue', array( 'dedupe_key' => $key, 'recipient' => $email, 'template_key' => $template, 'payload' => wp_json_encode( $payload ), 'send_at' => $send_at ? sanitize_text_field( $send_at ) : current_time( 'mysql', true ), 'status' => 'queued', 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $result && false === strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) { return new \WP_Error( 'wpcap_message_enqueue', __( 'Nachricht konnte nicht eingeplant werden.', 'wp-pass-captain' ) ); }
		return true;
	}

	public static function process_due() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$now = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued',locked_at=NULL WHERE status='sending' AND locked_at<%s", $stale ) );
		for ( $i = 0; $i < 25; $i++ ) {
			$wpdb->query( 'START TRANSACTION' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE status='queued' AND send_at<=%s ORDER BY send_at,message_id LIMIT 1 FOR UPDATE", $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); break; }
			$wpdb->update( $table, array( 'status' => 'sending', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now ), array( 'message_id' => (int) $row->message_id, 'status' => 'queued' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			$wpdb->query( 'COMMIT' );
			$payload = json_decode( $row->payload, true );
			$template = apply_filters( 'wp_pass_captain_email_template_v1', null, $row->template_key, is_array( $payload ) ? $payload : array() );
			$template = is_array( $template ) ? $template : ( is_array( $payload ) ? $payload : array() );
			$subject = sanitize_text_field( $template['subject'] ?? '' );
			$body = (string) ( $template['body'] ?? '' );
			$sent = '' !== $subject && '' !== $body && wp_mail( $row->recipient, $subject, $body );
			if ( $sent ) { $wpdb->update( $table, array( 'status' => 'sent', 'locked_at' => null, 'last_error' => null ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s' ), array( '%d' ) ); }
			else {
				$tries = (int) $row->attempts + 1;
				$wpdb->update( $table, array( 'status' => $tries >= 5 ? 'failed' : 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $tries ) ) ), 'locked_at' => null, 'last_error' => __( 'Template fehlt oder wp_mail ist fehlgeschlagen.', 'wp-pass-captain' ) ), array( 'message_id' => (int) $row->message_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
			}
		}
	}
}
