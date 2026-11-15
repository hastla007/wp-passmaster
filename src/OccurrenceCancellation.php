<?php
/**
 * Consequences of cancelling a single occurrence (wpcap_occurrence_cancelled):
 * open orders are cancelled and their holds released, valid tickets are revoked, ticket holders
 * are informed, and – when `auto_refund_cancelled` is enabled – paid native orders are refunded
 * for the affected tickets. WooCommerce orders get an order note; the refund happens in WooCommerce.
 * Without automatic refund, paid tickets are revoked but stay refundable natively
 * (Orders::refundable_tickets(): revoked + not refunded + occurrence cancelled).
 * Whole-event cancellations (Orders::cancel_event) run this per occurrence inside an "event batch",
 * where the per-occurrence mail is replaced by one event cancellation mail.
 *
 * Order of work (PERF-4): tickets are revoked first with bulk UPDATEs; per-ticket hooks, refunds and
 * mails follow as job batches (Jobs), the first ones inside the request, the rest in the worker.
 * An abort at any point therefore never leaves valid tickets with refunded money.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OccurrenceCancellation {
	/** Job types of the batched follow-up work (wp_pass_captain_process_job_v1). */
	const JOB_HOOKS = 'occurrence_cancel_hooks';
	const JOB_REFUNDS = 'occurrence_cancel_refunds';
	const JOB_NOTIFY = 'occurrence_cancel_notify';

	/** Batch sizes: tickets per hook job, orders per refund job, recipients per mail job. */
	const HOOK_BATCH = 200;
	const REFUND_BATCH = 20;
	const NOTIFY_BATCH = 100;

	/**
	 * Seconds of follow-up work done inside the request (the admin's form submit). Everything that
	 * does not fit stays queued for the job worker; the admin sees the progress instead of a timeout.
	 *
	 * @internal Tests set it to 0 to leave all batches queued.
	 * @var int
	 */
	public static $inline_seconds = 20;

	/** Nesting depth of an event cancellation in progress (suppresses per-occurrence mails). */
	private static $event_batch = 0;

	/** Shared inline deadline of an event cancellation (all occurrences together). */
	private static $batch_deadline = 0;

	/** Summary of the last handle() run per occurrence id (request scope). */
	private static $summaries = array();

	/** Counters of job batches run in this request, per run id. */
	private static $counters = array();

	/** Whether the job handler is hooked in this request. */
	private static $jobs_registered = false;

	public static function register() {
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'handle' ), 10, 1 );
		self::register_jobs();
	}

	/** Hooks the batch handler once (also when handle() is called without register()). */
	private static function register_jobs() {
		if ( self::$jobs_registered ) { return; }
		self::$jobs_registered = true;
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/** Marks the start of a whole-event cancellation: holders get the event mail instead. */
	public static function begin_event_batch() {
		if ( 0 === self::$event_batch ) { self::$batch_deadline = time() + max( 0, (int) self::$inline_seconds ); }
		++self::$event_batch;
	}

	public static function end_event_batch() {
		self::$event_batch = max( 0, self::$event_batch - 1 );
		if ( 0 === self::$event_batch ) { self::$batch_deadline = 0; }
	}

	/** Summary counters of the last cancellation handled for an occurrence in this request. */
	public static function last_summary( $occurrence_id ) {
		global $wpdb;
		return self::$summaries[ $wpdb->prefix . '|' . absint( $occurrence_id ) ] ?? array();
	}

	/**
	 * Handles a cancelled occurrence:
	 * 1. unpaid orders are cancelled (holds, campaigns, vouchers released);
	 * 2. the refund plan (paid orders and their open tickets) is read;
	 * 3. all valid tickets are revoked with bulk UPDATEs – before any money moves, so an abort at any
	 *    later point never leaves valid tickets with refunded money;
	 * 4. wpcap_ticket_revoked (per ticket, after the UPDATE), refunds and holder mails are queued as
	 *    job batches; the first batches run right away within $inline_seconds, the rest in the worker.
	 *
	 * @return array Summary counters (refunds/notified count the batches done in this request; queued_jobs the rest).
	 */
	public static function handle( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', $occurrence_id ) );
		if ( ! $occ ) { return array(); }
		self::register_jobs();
		$run = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		$summary = array( 'cancelled_orders' => 0, 'refunds' => 0, 'refund_errors' => 0, 'revoked' => 0, 'notified' => 0, 'queued_jobs' => 0, 'run' => $run );

		// Recipients first, while tickets are still valid.
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' GROUP BY email", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', $occurrence_id ) );

		// 1. Unpaid orders touching this occurrence: cancel (releases holds, campaigns, vouchers). Expired
		// orders with a started provider payment (provider_reference) are cancelled as well: a payment that
		// still arrives then counts as "payment for a closed order" (alert, no tickets; PayPal does not capture).
		$pending = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM %i ord INNER JOIN %i oi ON oi.order_id=ord.order_id INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ( ord.payment_status='pending' OR ( ord.payment_status='expired' AND COALESCE(ord.provider_reference,'')<>'' ) )", $p . 'wpcap_orders', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $occurrence_id ) );
		foreach ( $pending as $order_id ) {
			Orders::cancel_order( (int) $order_id, 'occurrence_cancelled' );
			++$summary['cancelled_orders'];
		}

		// 2. Refund plan: paid orders with their open tickets of this occurrence (one query).
		$plan = self::refund_plan( $occurrence_id );

		// 3. Revoke first (bulk UPDATE); tickets revoked by the cancellation stay refundable.
		$revoked_ids = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' ORDER BY t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $occurrence_id ) ) );
		$summary['revoked'] = count( $revoked_ids );

		// 4. Follow-up work as job batches (persisted before anything runs).
		$keys = array();
		$base = array( 'occurrence_id' => $occurrence_id, 'run' => $run );
		foreach ( array_chunk( $revoked_ids, self::HOOK_BATCH ) as $i => $chunk ) {
			$keys[] = self::enqueue( "occ-cancel-$occurrence_id-$run-hooks-$i", self::JOB_HOOKS, $base + array( 'ticket_ids' => $chunk ) );
		}
		$auto_refund = (bool) Settings::get( 'auto_refund_cancelled' );
		$plan = array_values( array_filter( $plan, static function ( $row ) use ( $auto_refund ) { return $auto_refund || 'woocommerce' === $row['provider']; } ) );
		foreach ( array_chunk( $plan, self::REFUND_BATCH ) as $i => $chunk ) {
			$keys[] = self::enqueue( "occ-cancel-$occurrence_id-$run-refunds-$i", self::JOB_REFUNDS, $base + array( 'key' => 'occurrence-cancelled-' . $occurrence_id, 'orders' => $chunk ) );
		}
		$list = array();
		foreach ( self::$event_batch ? array() : $recipients as $recipient ) {
			if ( is_email( $recipient->email ) ) { $list[] = array( 'email' => (string) $recipient->email, 'locale' => (string) $recipient->locale ); }
		}
		foreach ( array_chunk( $list, self::NOTIFY_BATCH ) as $i => $chunk ) {
			$keys[] = self::enqueue( "occ-cancel-$occurrence_id-$run-notify-$i", self::JOB_NOTIFY, $base + array( 'auto_refund' => $auto_refund, 'recipients' => $chunk ) );
		}
		$keys = array_values( array_filter( $keys ) );
		$deadline = self::$event_batch && self::$batch_deadline ? self::$batch_deadline : time() + max( 0, (int) self::$inline_seconds );
		self::$counters[ $run ] = array( 'refunds' => 0, 'refund_errors' => 0, 'notified' => 0 );
		$ran = Jobs::run_now( $keys, $deadline );
		$summary = array_merge( $summary, self::$counters[ $run ] );
		$summary['queued_jobs'] = count( $keys ) - $ran;
		unset( self::$counters[ $run ] );
		Audit::record( 'occurrence_cancellation_processed', 'occurrence', $occurrence_id, $summary );
		self::$summaries[ $wpdb->prefix . '|' . $occurrence_id ] = $summary;
		return $summary;
	}

	/** Enqueues one batch; returns its key (or '' when it could not be stored). */
	private static function enqueue( $key, $type, array $payload ) {
		$result = Jobs::enqueue( $key, $type, $payload );
		if ( is_wp_error( $result ) ) {
			Audit::record( 'occurrence_cancellation_enqueue_failed', 'occurrence', (int) $payload['occurrence_id'], array( 'type' => $type, 'error' => $result->get_error_code() ) );
			return '';
		}
		return $key;
	}

	/**
	 * Paid orders with tickets of the occurrence that are still open (valid or checked in, not refunded),
	 * read before the revoke. One row per order: order_id, provider, ticket_ids.
	 *
	 * @return array[]
	 */
	private static function refund_plan( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$plan = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,ord.order_id,ord.payment_provider FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in') ORDER BY ord.order_id,t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', absint( $occurrence_id ) ) );
		foreach ( $rows as $row ) {
			$id = (int) $row->order_id;
			if ( ! isset( $plan[ $id ] ) ) { $plan[ $id ] = array( 'order_id' => $id, 'provider' => (string) $row->payment_provider, 'ticket_ids' => array() ); }
			$plan[ $id ]['ticket_ids'][] = (int) $row->ticket_id;
		}
		return array_values( $plan );
	}

	/**
	 * Progress of the follow-up batches of an occurrence cancellation (admin status).
	 *
	 * @return int[] status => number of batches (queued, running, complete, dead_letter).
	 */
	public static function progress( $occurrence_id ) {
		global $wpdb;
		$out = array( 'queued' => 0, 'running' => 0, 'complete' => 0, 'dead_letter' => 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n FROM %i WHERE job_type IN (%s,%s,%s) AND payload LIKE %s GROUP BY status', $wpdb->prefix . 'wpcap_jobs', self::JOB_HOOKS, self::JOB_REFUNDS, self::JOB_NOTIFY, '{"occurrence_id":' . absint( $occurrence_id ) . ',%' ) );
		foreach ( $rows as $row ) { $out[ (string) $row->status ] = (int) $row->n; }
		return $out;
	}

	/** Filter wp_pass_captain_process_job_v1: runs one batch of a cancellation. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( ! in_array( $type, array( self::JOB_HOOKS, self::JOB_REFUNDS, self::JOB_NOTIFY ), true ) ) { return $handled; }
		$payload = (array) $payload;
		$run = (string) ( $payload['run'] ?? '' );
		$occurrence_id = absint( $payload['occurrence_id'] ?? 0 );
		if ( self::JOB_HOOKS === $type ) {
			// Per-ticket hooks (wallet, webhooks, SMS, seats) after the revoke has been committed.
			Orders::fire_revoked( array_map( 'absint', (array) ( $payload['ticket_ids'] ?? array() ) ), 'occurrence_cancelled' );
			return true;
		}
		if ( self::JOB_REFUNDS === $type ) {
			$counts = self::refund_orders( $occurrence_id, (array) ( $payload['orders'] ?? array() ), (string) ( $payload['key'] ?? 'occurrence-cancelled-' . $occurrence_id ) );
			self::count( $run, $counts );
			return true;
		}
		$sent = self::notify( $occurrence_id, (array) ( $payload['recipients'] ?? array() ), ! empty( $payload['auto_refund'] ) );
		if ( is_wp_error( $sent ) ) { return $sent; }
		self::count( $run, array( 'notified' => $sent ) );
		return true;
	}

	private static function count( $run, array $counts ) {
		if ( ! isset( self::$counters[ $run ] ) ) { return; }
		foreach ( $counts as $key => $n ) { self::$counters[ $run ][ $key ] = ( self::$counters[ $run ][ $key ] ?? 0 ) + (int) $n; }
	}

	/** Sends the cancellation mail to one batch of holders; returns the number sent. */
	private static function notify( $occurrence_id, array $recipients, $auto_refund ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $occurrence_id ) ) );
		if ( ! $occ ) { return 0; }
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$vars = array(
			'event_name' => (string) $occ->event_name,
			'event_date' => wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz ),
			'event_location' => (string) $occ->location,
			'timezone' => (string) $occ->timezone,
			// Translated in the recipient's locale.
			'refund_note' => static function () use ( $auto_refund ) { return $auto_refund ? __( 'Der Ticketpreis wird automatisch erstattet.', 'pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'pass-captain' ); },
		);
		$sent = 0;
		foreach ( $recipients as $recipient ) {
			$email = (string) ( $recipient['email'] ?? '' );
			if ( is_email( $email ) && Mail\Mailer::send( 'occurrence_cancelled', $email, $vars, array(), (string) ( $recipient['locale'] ?? '' ) ) ) { ++$sent; }
		}
		return $sent;
	}

	/**
	 * Refunds the still open tickets of an occurrence in paid native orders when `auto_refund_cancelled` is
	 * enabled (WooCommerce orders get the `wpcap_occurrence_cancelled_woocommerce_order` signal instead).
	 * Synchronous variant for direct calls; handle() reads the same plan before revoking and refunds
	 * in job batches afterwards (revoked tickets of a cancelled occurrence stay refundable).
	 *
	 * @return int[] ['refunds' => n, 'refund_errors' => n]
	 */
	public static function refund_paid( $occurrence_id, $key ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		// Ticket ids are read per order (GROUP_CONCAT would be truncated for large orders).
		$paid = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT ord.order_id,ord.payment_provider FROM %i ord INNER JOIN %i t ON t.order_id=ord.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in')", $p . 'wpcap_orders', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $occurrence_id ) );
		foreach ( $paid as $row ) {
			$row->ticket_ids = implode( ',', $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND tt.occurrence_id=%d AND t.refunded=0 AND t.status IN ('valid','checked_in')", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', (int) $row->order_id, $occurrence_id ) ) );
		}
		$orders = array();
		foreach ( $paid as $row ) {
			$orders[] = array( 'order_id' => (int) $row->order_id, 'provider' => (string) $row->payment_provider, 'ticket_ids' => array_map( 'absint', explode( ',', $row->ticket_ids ) ) );
		}
		return self::refund_orders( $occurrence_id, $orders, $key );
	}

	/**
	 * Refunds one batch of the refund plan (see refund_paid()); tickets may already be revoked by the
	 * cancellation (they stay refundable while the occurrence is cancelled).
	 *
	 * @param array[] $orders order_id, provider, ticket_ids.
	 * @return int[] ['refunds' => n, 'refund_errors' => n]
	 */
	private static function refund_orders( $occurrence_id, array $orders, $key ) {
		$counts = array( 'refunds' => 0, 'refund_errors' => 0 );
		foreach ( $orders as $row ) {
			$row = (object) $row;
			if ( 'woocommerce' === $row->provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, $occurrence_id );
				continue;
			}
			if ( ! Settings::get( 'auto_refund_cancelled' ) ) { continue; }
			$ticket_ids = array_values( array_filter( array_map( 'absint', (array) $row->ticket_ids ) ) );
			if ( ! $ticket_ids ) { continue; }
			// Refund rule for tickets already used for admission (setting refund_after_checkin):
			// deny = refund only unused tickets; approval = used tickets wait for a second approval.
			$policy = Refunds::checkin_policy();
			$checked_in = 'allow' === $policy ? array() : Refunds::checked_in_ids( $ticket_ids );
			if ( $checked_in ) {
				$ticket_ids = array_values( array_diff( $ticket_ids, $checked_in ) );
				if ( 'approval' === $policy ) {
					$pending = Refunds::request( (int) $row->order_id, $checked_in, __( 'Termin abgesagt (bereits eingecheckt)', 'pass-captain' ), $key . '-checked-in' );
					Audit::record( 'occurrence_refund_checked_in_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'tickets' => count( $checked_in ), 'status' => is_wp_error( $pending ) ? $pending->get_error_code() : (string) ( $pending['status'] ?? '' ) ) );
				} else {
					Audit::record( 'occurrence_refund_checked_in_skipped', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'tickets' => count( $checked_in ) ) );
				}
				if ( ! $ticket_ids ) { continue; }
			}
			$result = Refunds::request( (int) $row->order_id, $ticket_ids, __( 'Termin abgesagt', 'pass-captain' ), $key );
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) ) {
				++$counts['refund_errors'];
				Audit::record( 'occurrence_auto_refund_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : ( $result['status'] ?? '' ) ) );
			} else {
				++$counts['refunds'];
			}
		}
		return $counts;
	}
}
