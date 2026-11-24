<?php
/**
 * Explicit handoff of optional mails to the workflow provider (A677, PASS-WF-05): per mail type the
 * site owner decides whether Pass Captain sends it itself (default) or hands it to Workflow Captain
 * (or another provider of the workflow contract). Only optional mails can be handed off (TYPES, e.g.
 * the date reminder) – tickets, payment, reservation, refund and admission mails always stay with
 * Pass Captain.
 *
 * - Opt-in per type: option OPTION (list of types), set on Pass Captain → Status (admin).
 * - Provider: the registered workflow provider (Contract::provider(), compatible major version) with
 *   a method `handle_mail( array $handoff ): true|array|WP_Error`. The handoff carries what the mail
 *   needs – recipient, language, subject, body, format and the public order id; that is the explicit,
 *   chosen transfer of the mail. Mails with attachments are never handed off.
 * - Native fallback: without a (compatible) provider, without handle_mail(), on an exception or a
 *   refusal, Pass Captain sends the mail itself. Every decision is logged (support log, without the
 *   address) and counted (STATS_OPTION, shown on the status page).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Workflow;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MailHandoff {
	const OPTION = 'wpcap_workflow_mail_handoff';
	const STATS_OPTION = 'wpcap_workflow_mail_handoff_stats';
	const VERSION = '1.0.0';

	/** Types that are never handed off, whatever a filter adds (transactional/legal mails). */
	const NEVER = array( 'tickets', 'tickets_resend', 'order_access', 'order_links', 'refund_confirmation', 'refund_request_received', 'refund_request_rejected', 'event_cancelled', 'occurrence_cancelled', 'occurrence_rescheduled', 'occurrence_changed', 'ticket_transfer_recipient', 'ticket_transfer_sender', 'ticket_exchanged', 'waitlist_offer', 'attendee_data_request' );

	/** Types that can be handed off: label per type. */
	public static function types() {
		$types = array( 'queue_wpcap_reminder' => __( 'Terminerinnerung (Modul Erinnerungen)', 'pass-captain' ) );
		/**
		 * Optional mail types that may be handed to the workflow provider (A677): type => label.
		 * Transactional types (MailHandoff::NEVER) are removed again.
		 */
		$types = (array) apply_filters( 'wpcap_workflow_mail_handoff_types', $types );
		return array_diff_key( array_filter( $types, 'is_string' ), array_flip( self::NEVER ) );
	}

	/** Types the site owner handed off. */
	public static function enabled() {
		$chosen = get_option( self::OPTION, array() );
		return array_values( array_intersect( array_keys( self::types() ), is_array( $chosen ) ? array_map( 'strval', $chosen ) : array() ) );
	}

	/** Saves the handed-off types (capability manage_options, audited). */
	public static function save( array $types ) {
		if ( ! current_user_can( Contract::CAPABILITY ) ) {
			return new \WP_Error( 'wpcap_workflow_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$types = array_values( array_intersect( array_keys( self::types() ), array_map( 'strval', $types ) ) );
		update_option( self::OPTION, $types, false );
		\PassCaptain\Audit::record( 'workflow_mail_handoff_set', 'workflow', 0, array( 'types' => $types ) );
		return $types;
	}

	/**
	 * Called by Mailer::deliver() before the native send: true when the provider took the mail
	 * (nothing is sent natively), false to send it natively.
	 *
	 * @param string|string[] $to      Recipient(s).
	 * @param array           $message Composed message (type, subject, body, format, locale, attachments, order_id).
	 */
	public static function hand_off( $to, array $message ) {
		$type = (string) ( $message['type'] ?? '' );
		if ( '' === $type || ! in_array( $type, self::enabled(), true ) ) { return false; }
		$context = array( 'mail_type' => sanitize_key( $type ) );
		if ( ! empty( $message['order_id'] ) ) { $context['order_id'] = absint( $message['order_id'] ); }
		if ( ! empty( $message['attachments'] ) ) { return self::fallback( 'attachments', $context ); }
		$provider = Contract::provider( $status );
		if ( ! $provider ) { return self::fallback( 'provider_' . ( $status['state'] ?? 'missing' ), $context ); }
		if ( ! method_exists( $provider, 'handle_mail' ) ) { return self::fallback( 'not_supported', $context ); }
		global $wpdb;
		$order = empty( $message['order_id'] ) ? '' : (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $message['order_id'] ) ) );
		$handoff = array(
			'handoff_version' => self::VERSION,
			'type' => $type,
			'to' => $to,
			'locale' => (string) ( $message['locale'] ?? '' ),
			'subject' => (string) ( $message['subject'] ?? '' ),
			'body' => (string) ( $message['body'] ?? '' ),
			'format' => 'html' === ( $message['format'] ?? 'text' ) ? 'html' : 'text',
			'order_id' => $order,
		);
		try {
			$result = $provider->handle_mail( $handoff );
		} catch ( \Throwable $e ) {
			return self::fallback( 'provider_error', $context );
		}
		if ( true !== $result && ! is_array( $result ) ) {
			return self::fallback( is_wp_error( $result ) ? 'rejected' : 'provider_error', $context );
		}
		self::count( 'accepted', $type );
		\PassCaptain\Log::info( 'mail', 'Mail handed to the workflow provider: ' . $type, $context );
		return true;
	}

	private static function fallback( $reason, array $context ) {
		self::count( 'fallback', (string) $context['mail_type'], $reason );
		\PassCaptain\Log::info( 'mail', 'Workflow mail handoff not possible, sent natively: ' . $context['mail_type'], $context + array( 'reason' => $reason ) );
		return false;
	}

	/** Counters for the status page: accepted, fallback, last decision. */
	private static function count( $result, $type, $reason = '' ) {
		$stats = get_option( self::STATS_OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$stats[ $result ] = (int) ( $stats[ $result ] ?? 0 ) + 1;
		$stats['last'] = array( 'result' => $result, 'type' => sanitize_key( $type ), 'reason' => $reason, 'at' => \PassCaptain\Clock::mysql() );
		update_option( self::STATS_OPTION, $stats, false );
	}

	/** Counters (accepted, fallback, last). */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		return is_array( $stats ) ? $stats + array( 'accepted' => 0, 'fallback' => 0, 'last' => null ) : array( 'accepted' => 0, 'fallback' => 0, 'last' => null );
	}
}
