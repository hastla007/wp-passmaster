<?php
/**
 * Resumable bulk runs (A428): occurrence cancellations, event cancellations and change notices
 * (rescheduling) run in batched, idempotent steps instead of one long admin request.
 *
 * A run is a row in `wpcap_bulk_runs` (kind, object, phase, cursor, counters). drive() works the
 * phases until the time budget (Jobs\TimeBudget) is used up; an unfinished run queues a `bulk_run`
 * job that continues it in the background, and a second click on "Absagen" continues it right away.
 * Only one request works on a run at a time (lease `locked_until`, released in `finally` and on
 * shutdown, otherwise it expires after the budget plus two minutes).
 *
 * - occurrence_cancel: orders (cancel open orders) → refund_plan (one `cancellation_refund` job per
 *   paid native order, before revocation) → revoke (batched; the holders' mails are queued before
 *   their tickets are revoked) → modules (action `wpcap_occurrence_cancellation_step`, e.g. passes)
 *   → refunds (runs the due refund jobs; failures stay jobs with backoff) → mails → done.
 * - event_cancel: mails_plan (event mail per buyer of paid/open orders, queued first) → occurrences
 *   (fires `wpcap_occurrence_cancelled` per occurrence, each one starts its own occurrence run) →
 *   wait (finishes those runs) → safety_orders / safety_tickets (when the occurrence handler is
 *   unhooked) → released (`wpcap_capacity_released`) → mails → done.
 * - notify: queue (one mail per holder of a valid ticket) → mails → done.
 *
 * Mails go through the message queue (Communications) with the template key `wpcap_bulk_{run_id}`
 * and a dedupe key per run and recipient, so a resumed run never mails twice and an aborted send
 * is finished by the queue worker. Refunds are idempotent through their refund key.
 *
 * Without the table (before the 3.10.13 schema upgrade) a run is kept in memory and runs to the end
 * in the current request (former behaviour).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BulkRuns {
	const TABLE = 'wpcap_bulk_runs';

	/** Job type that continues an unfinished run. */
	const JOB = 'bulk_run';

	/** Template key prefix of queued run mails (followed by the run id). */
	const MAIL_PREFIX = 'wpcap_bulk_';

	/** Default rows per batch (filter `wpcap_bulk_run_batch_size`). */
	const BATCH = 200;

	private static $hooked = false;

	/** Deadline of the outermost drive() of this request (nested runs share it). */
	private static $deadline = null;

	/** Leases held by this request: run_id => true. */
	private static $leases = array();

	private static $shutdown = false;

	/** Registers the job and mail handlers (idempotent; drive() calls it, too). */
	public static function register() {
		if ( self::$hooked ) { return; }
		self::$hooked = true;
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 4 );
		add_filter( 'wpcap_process_job', array( OccurrenceCancellation::class, 'handle_job' ), 10, 4 );
		add_filter( 'wpcap_email_template', array( __CLASS__, 'render_mail' ), 10, 3 );
	}

	/** Whether the runs table exists (schema 3.10.13). */
	public static function ready() {
		return Privacy::has_table( self::TABLE );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	private static function batch() {
		return max( 1, min( 1000, (int) apply_filters( 'wpcap_bulk_run_batch_size', self::BATCH ) ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Runs.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Starts a run, or returns the existing run with the same key (a run per key exists once).
	 *
	 * @param string $kind      occurrence_cancel|event_cancel|notify.
	 * @param int    $object_id Occurrence or event id.
	 * @param string $key       Unique run key.
	 * @param array  $data      Run data (JSON).
	 * @return object Run.
	 */
	public static function start( $kind, $object_id, $key, array $data ) {
		global $wpdb;
		$phases = array( 'occurrence_cancel' => 'orders', 'event_cancel' => 'mails_plan', 'notify' => 'queue' );
		$row = array(
			'run_key' => \PassCaptain\Text::cut( (string) $key, 120 ),
			'kind' => (string) $kind,
			'object_id' => absint( $object_id ),
			'status' => 'running',
			'phase' => $phases[ $kind ] ?? 'mails',
			'cursor_id' => 0,
			'payload' => wp_json_encode( $data ),
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( self::ready() ) {
			$existing = self::by_key( $row['run_key'] );
			if ( $existing ) { return $existing; }
			$wpdb->insert( self::table(), $row );
			$run = self::by_key( $row['run_key'] );
			if ( $run ) {
				Audit::record( 'bulk_run_started', 'bulk_run', (int) $run->run_id, array( 'kind' => $run->kind, 'object_id' => (int) $run->object_id ) );
				return $run;
			}
		}
		// Not upgraded yet (or the insert failed): an in-memory run that finishes in this request.
		$row += array( 'run_id' => 0, 'cancelled_orders' => 0, 'revoked' => 0, 'refunds' => 0, 'refunds_open' => 0, 'mails_queued' => 0, 'mails_sent' => 0, 'locked_until' => null, 'finished_at' => null );
		return self::decode( (object) $row );
	}

	public static function get( $run_id ) {
		global $wpdb;
		if ( ! absint( $run_id ) || ! self::ready() ) { return null; }
		return self::decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE run_id=%d', absint( $run_id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
	}

	public static function by_key( $key ) {
		global $wpdb;
		if ( ! self::ready() ) { return null; }
		return self::decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE run_key=%s', (string) $key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
	}

	/** Runs of an object (newest first). @return object[] */
	public static function for_object( $kind, $object_id, $limit = 10 ) {
		global $wpdb;
		if ( ! self::ready() ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE kind=%s AND object_id=%d ORDER BY run_id DESC LIMIT %d', (string) $kind, absint( $object_id ), max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		return array_values( array_filter( array_map( array( __CLASS__, 'decode' ), (array) $rows ) ) );
	}

	/** The occurrence cancellation run of an occurrence, or null. */
	public static function occurrence_run( $occurrence_id ) {
		return self::by_key( 'occurrence_cancel|' . absint( $occurrence_id ) );
	}

	/** The event cancellation run of an event, or null. */
	public static function event_run( $event_id ) {
		return self::by_key( 'event_cancel|' . absint( $event_id ) );
	}

	private static function decode( $row ) {
		if ( ! $row ) { return null; }
		$data = json_decode( (string) $row->payload, true );
		$row->data = is_array( $data ) ? $data : array();
		foreach ( array( 'run_id', 'object_id', 'cursor_id', 'cancelled_orders', 'revoked', 'refunds', 'refunds_open', 'mails_queued' ) as $field ) { $row->$field = (int) ( $row->$field ?? 0 ); }
		$row->mails_sent = (int) ( $row->mails_sent ?? 0 ); // In-memory runs count direct sends here.
		return $row;
	}

	/** Updates fields of a run (row and object). */
	private static function save( $run, array $fields ) {
		global $wpdb;
		foreach ( $fields as $name => $value ) { $run->$name = $value; }
		if ( ! $run->run_id ) { return; }
		$fields['updated_at'] = current_time( 'mysql', true );
		$run->updated_at = $fields['updated_at'];
		$wpdb->update( self::table(), $fields, array( 'run_id' => (int) $run->run_id ) );
	}

	/** Atomically adds to a counter column (refund jobs report their outcome here). */
	public static function count( $run_id, $column, $by = 1 ) {
		global $wpdb;
		if ( ! absint( $run_id ) || ! self::ready() || ! in_array( $column, array( 'refunds', 'refunds_open' ), true ) ) { return; }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET $column=$column+%d,updated_at=%s WHERE run_id=%d", (int) $by, current_time( 'mysql', true ), absint( $run_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and allow-listed column.
	}

	/**
	 * Works on a run until it is complete or the time budget is used up. A run another request is
	 * working on is left alone. An unfinished run is continued by a `bulk_run` job.
	 *
	 * @param object|int $run
	 * @return object|null The run after this request's work.
	 */
	public static function drive( $run, $deadline = null ) {
		self::register();
		$run = is_object( $run ) ? $run : self::get( $run );
		if ( ! $run || 'running' !== $run->status ) { return $run; }
		$outer = null === self::$deadline;
		if ( $outer ) { self::$deadline = null !== $deadline ? (float) $deadline : Jobs\TimeBudget::deadline(); }
		$limit = null !== $deadline ? min( (float) $deadline, self::$deadline ) : self::$deadline;
		try {
			if ( $run->run_id && ! self::lease( $run ) ) { return self::get( $run->run_id ); }
			try {
				$guard = 0;
				while ( 'running' === $run->status && $guard++ < 1000000 ) {
					// In-memory runs cannot be resumed: they always finish.
					if ( $run->run_id && Jobs\TimeBudget::exhausted( $limit ) ) { break; }
					if ( false === self::step( $run, $run->run_id ? $limit : PHP_INT_MAX ) ) { break; }
				}
			} finally {
				if ( $run->run_id ) { self::release( (int) $run->run_id ); }
			}
			if ( $run->run_id && 'running' === $run->status ) { self::schedule( $run ); }
		} finally {
			if ( $outer ) { self::$deadline = null; }
		}
		return $run->run_id ? self::get( $run->run_id ) : $run;
	}

	private static function lease( $run ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + (int) ceil( Jobs\TimeBudget::seconds() ) + 120 );
		$taken = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET locked_until=%s WHERE run_id=%d AND status='running' AND (locked_until IS NULL OR locked_until<%s)", $until, (int) $run->run_id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		if ( 1 !== (int) $taken ) { return false; }
		self::$leases[ (int) $run->run_id ] = true;
		if ( ! self::$shutdown ) {
			self::$shutdown = true;
			register_shutdown_function( array( __CLASS__, 'release_leases' ) );
		}
		return true;
	}

	private static function release( $run_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET locked_until=NULL WHERE run_id=%d', (int) $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		unset( self::$leases[ (int) $run_id ] );
	}

	/** Shutdown: leases of runs this request could not finish are released at once. */
	public static function release_leases() {
		foreach ( array_keys( self::$leases ) as $run_id ) { self::release( $run_id ); }
	}

	/** Queues the continuation job of an unfinished run. */
	private static function schedule( $run ) {
		Jobs::enqueue( 'bulk-run|' . (int) $run->run_id . '|' . $run->phase . '|' . (int) $run->cursor_id . '|' . $run->updated_at, self::JOB, array( 'run_id' => (int) $run->run_id ) );
		Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' );
	}

	/** wpcap_process_job: continues a run. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( self::JOB !== $type || true === $handled ) { return $handled; }
		$run = self::get( absint( ( (array) $payload )['run_id'] ?? 0 ) );
		if ( $run && 'running' === $run->status ) { self::drive( $run ); }
		return true;
	}

	/** One step of the current phase. @return bool False: stop for now (budget, nothing processable). */
	private static function step( $run, $deadline ) {
		$method = $run->kind . '_' . $run->phase;
		$map = array(
			'occurrence_cancel_orders' => 'occ_orders',
			'occurrence_cancel_refund_plan' => 'occ_refund_plan',
			'occurrence_cancel_revoke' => 'occ_revoke',
			'occurrence_cancel_modules' => 'occ_modules',
			'occurrence_cancel_refunds' => 'occ_refunds',
			'event_cancel_mails_plan' => 'event_mails_plan',
			'event_cancel_occurrences' => 'event_occurrences',
			'event_cancel_wait' => 'event_wait',
			'event_cancel_safety_orders' => 'event_safety_orders',
			'event_cancel_safety_tickets' => 'event_safety_tickets',
			'event_cancel_released' => 'event_released',
			'notify_queue' => 'notify_queue',
		);
		if ( 'mails' === $run->phase ) { return self::send( $run, $deadline ); }
		if ( ! isset( $map[ $method ] ) ) { self::finish( $run ); return true; }
		return call_user_func( array( __CLASS__, $map[ $method ] ), $run, $deadline );
	}

	private static function next( $run, $phase ) {
		self::save( $run, array( 'phase' => $phase, 'cursor_id' => 0 ) );
		return true;
	}

	private static function finish( $run ) {
		self::save( $run, array( 'status' => 'complete', 'phase' => 'done', 'finished_at' => current_time( 'mysql', true ) ) );
		$summary = self::summary( $run );
		$actions = array( 'occurrence_cancel' => 'occurrence_cancellation_processed', 'event_cancel' => 'event_cancellation_processed', 'notify' => 'occurrence_notification_processed' );
		$types = array( 'occurrence_cancel' => 'occurrence', 'event_cancel' => 'event', 'notify' => 'occurrence' );
		Audit::record( $actions[ $run->kind ] ?? 'bulk_run_completed', $types[ $run->kind ] ?? 'bulk_run', (int) $run->object_id, array_intersect_key( $summary, array_flip( array( 'cancelled_orders', 'refunds', 'refund_errors', 'revoked', 'notified' ) ) ) + array( 'run_id' => (int) $run->run_id ) );
		/**
		 * A bulk run (occurrence/event cancellation, change notice) has finished all its steps (A428).
		 *
		 * @param int    $run_id    Run id (0 for an in-memory run before the schema upgrade).
		 * @param string $kind      occurrence_cancel, event_cancel or notify.
		 * @param int    $object_id Occurrence or event id.
		 * @param array  $summary   Counters (see BulkRuns::summary()).
		 */
		do_action( 'wpcap_bulk_run_completed', (int) $run->run_id, (string) $run->kind, (int) $run->object_id, $summary );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Occurrence cancellation.
	 * ------------------------------------------------------------------------------------------- */

	private static function occ_orders( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status IN ('pending','expired') AND ord.order_id>%d ORDER BY ord.order_id LIMIT %d", (int) $run->object_id, (int) $run->cursor_id, self::batch() ) ) );
		if ( ! $ids ) { return self::next( $run, 'refund_plan' ); }
		$cancelled = 0;
		foreach ( $ids as $order_id ) {
			if ( true === Orders::cancel_order( $order_id, 'occurrence_cancelled' ) ) { ++$cancelled; } // Else a retry job finishes it.
		}
		self::save( $run, array( 'cursor_id' => max( $ids ), 'cancelled_orders' => $run->cancelled_orders + $cancelled ) );
		return true;
	}

	/** One refund job per paid native order (ticket ids fixed before the tickets are revoked). */
	private static function occ_refund_plan( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = (int) $run->object_id;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT ord.order_id,ord.payment_provider FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in') AND ord.order_id>%d ORDER BY ord.order_id LIMIT %d", $occurrence_id, (int) $run->cursor_id, self::batch() ) );
		if ( ! $rows ) { return self::next( $run, 'revoke' ); }
		$refunds = 0;
		$open = 0;
		foreach ( $rows as $row ) {
			if ( 'woocommerce' === $row->payment_provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, $occurrence_id );
				continue;
			}
			if ( empty( $run->data['auto_refund'] ) ) { continue; }
			// Ticket ids per order (a GROUP_CONCAT list would be cut for large orders).
			$ticket_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND tt.occurrence_id=%d AND t.refunded=0 AND t.status IN ('valid','checked_in') ORDER BY t.ticket_id", (int) $row->order_id, $occurrence_id ) ) );
			$job = array( 'run_id' => (int) $run->run_id, 'mode' => 'tickets', 'order_id' => (int) $row->order_id, 'occurrence_id' => $occurrence_id, 'ticket_ids' => $ticket_ids, 'reason' => __( 'Termin abgesagt', 'pass-captain' ), 'key' => 'occurrence-cancelled-' . $occurrence_id );
			if ( $run->run_id ) {
				Jobs::enqueue( 'cancellation-refund|' . (int) $row->order_id . '|' . $job['key'], OccurrenceCancellation::REFUND_JOB, $job );
				continue;
			}
			// In-memory run: refund now, failures become retry jobs (A354).
			$result = OccurrenceCancellation::request_refund( $job );
			$outcome = OccurrenceCancellation::refund_outcome( $result );
			if ( 'done' === $outcome ) { ++$refunds; continue; }
			++$open;
			if ( 'retry' === $outcome ) { OccurrenceCancellation::schedule_refund_retry( $job, is_wp_error( $result ) ? $result->get_error_code() : (string) ( $result['status'] ?? '' ) ); }
		}
		self::save( $run, array( 'cursor_id' => (int) end( $rows )->order_id, 'refunds' => $run->refunds + $refunds, 'refunds_open' => $run->refunds_open + $open ) );
		return true;
	}

	/** Revokes a batch of valid tickets; their holders' mails are queued first. */
	private static function occ_revoke( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = (int) $run->object_id;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,ord.locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' ORDER BY t.ticket_id LIMIT %d", $occurrence_id, self::batch() ) );
		if ( ! $rows ) { return self::next( $run, 'modules' ); }
		if ( empty( $run->data['event_batch'] ) ) {
			foreach ( $rows as $row ) {
				self::queue_mail( $run, (string) $row->email, (string) $row->locale, array( 'mail' => 'occurrence_cancelled', 'occurrence_id' => $occurrence_id, 'auto_refund' => ! empty( $run->data['auto_refund'] ) ? 1 : 0, 'ics' => 1 ), 'occ-cancel|' . $occurrence_id . '|' . strtolower( (string) $row->email ) );
			}
		}
		$revoked = Orders::revoke_ids( array_map( static function ( $row ) { return (int) $row->ticket_id; }, $rows ) );
		self::save( $run, array( 'revoked' => $run->revoked + count( $revoked ) ) );
		Orders::fire_revoked( $revoked, 'occurrence_cancelled' );
		return (bool) $revoked; // Nothing revoked (database error): try again later.
	}

	private static function occ_modules( $run ) {
		/**
		 * Step of an occurrence cancellation run for modules (e.g. passes linked to the occurrence),
		 * after the core revoked the tickets and planned its refunds (A428). Callbacks must be
		 * idempotent: a run aborted inside this step runs it again.
		 *
		 * @param int $occurrence_id Cancelled occurrence.
		 * @param int $run_id        Run id (0 before the schema upgrade); mails via BulkRuns::queue_mail().
		 */
		do_action( 'wpcap_occurrence_cancellation_step', (int) $run->object_id, (int) $run->run_id );
		return self::next( $run, 'refunds' );
	}

	/** Runs the due refund jobs of this run (failed ones wait for their retry). */
	private static function occ_refunds( $run, $deadline ) {
		if ( ! $run->run_id || ! self::refund_counts( $run )['due'] ) { return self::next( $run, 'mails' ); }
		if ( Jobs\TimeBudget::exhausted( $deadline ) ) { return false; }
		return Jobs::process_due( 10, OccurrenceCancellation::REFUND_JOB ) > 0;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Event cancellation.
	 * ------------------------------------------------------------------------------------------- */

	/** Event mails to buyers of paid/open orders, queued before any order changes. */
	private static function event_mails_plan( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = (int) $run->object_id;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.payment_status IN ('paid','pending','paid_unallocated') AND ord.order_id>%d ORDER BY ord.order_id LIMIT %d", $event_id, (int) $run->cursor_id, self::batch() ) ) );
		if ( ! $ids ) { return self::next( $run, 'occurrences' ); }
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email," . Notifications::LATEST_LOCALE_SQL . " AS locale FROM {$wpdb->prefix}wpcap_orders ord INNER JOIN {$wpdb->prefix}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$wpdb->prefix}wpcap_tickets t ON t.order_id=ord.order_id AND t.ticket_type_id=tt.ticket_type_id WHERE o.event_id=%d AND ord.order_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY email', array_merge( array( $event_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Notifications::LATEST_LOCALE_SQL is a class constant with literal SQL.
		foreach ( (array) $recipients as $recipient ) {
			self::queue_mail( $run, (string) $recipient->email, (string) $recipient->locale, array( 'mail' => 'event_cancelled', 'event_id' => $event_id, 'event_name' => (string) ( $run->data['event_name'] ?? '' ) ), 'event-cancel|' . $event_id . '|' . strtolower( (string) $recipient->email ) );
		}
		self::save( $run, array( 'cursor_id' => max( $ids ) ) );
		return true;
	}

	/** Fires the occurrence cancellation for the next occurrence (each starts its own run). */
	private static function event_occurrences( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $run->data['occurrence_ids'] ?? array() ) ), static function ( $id ) use ( $run ) { return $id > (int) $run->cursor_id; } ) );
		sort( $ids );
		if ( ! $ids ) { return self::next( $run, 'wait' ); }
		$occurrence_id = $ids[0];
		// Cursor first: the action fires at most once per occurrence, also when this request dies in it.
		// `firing` remembers the occurrence until the action returned (see event_wait()).
		$run->data['firing'] = $occurrence_id;
		self::save( $run, array( 'cursor_id' => $occurrence_id, 'payload' => wp_json_encode( $run->data ) ) );
		$issued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status IN ('valid','checked_in')", $occurrence_id ) );
		Audit::record( 'occurrence_cancelled', 'occurrence', $occurrence_id, array( 'event_id' => (int) $run->object_id, 'issued_tickets' => $issued, 'via' => 'event_cancelled' ) );
		OccurrenceCancellation::begin_event_batch();
		try {
			do_action( 'wpcap_occurrence_cancelled', $occurrence_id, $issued );
		} finally {
			OccurrenceCancellation::end_event_batch();
		}
		$run->data['firing'] = 0;
		self::save( $run, array( 'payload' => wp_json_encode( $run->data ) ) );
		return true;
	}

	/** Finishes the occurrence runs of the event. */
	private static function event_wait( $run ) {
		$firing = (int) ( $run->data['firing'] ?? 0 );
		if ( $firing ) {
			// The request died while the action ran for this occurrence: its core consequences are
			// started here (without firing the action for all listeners again).
			if ( ! self::occurrence_run( $firing ) ) {
				OccurrenceCancellation::begin_event_batch();
				try {
					OccurrenceCancellation::handle( $firing );
				} finally {
					OccurrenceCancellation::end_event_batch();
				}
			}
			$run->data['firing'] = 0;
			self::save( $run, array( 'payload' => wp_json_encode( $run->data ) ) );
		}
		foreach ( self::sub_runs( $run ) as $sub ) {
			if ( 'running' !== $sub->status ) { continue; }
			$sub = self::drive( $sub );
			if ( $sub && 'running' === $sub->status ) { return false; }
		}
		return self::next( $run, 'safety_orders' );
	}

	/** Occurrence runs of an event run. @return object[] */
	private static function sub_runs( $run ) {
		$runs = array();
		foreach ( (array) ( $run->data['occurrence_ids'] ?? array() ) as $occurrence_id ) {
			$sub = self::occurrence_run( (int) $occurrence_id );
			if ( $sub ) { $runs[] = $sub; }
		}
		return $runs;
	}

	/** Safety net when the occurrence handler is unhooked: open orders of the event. */
	private static function event_safety_orders( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.payment_status IN ('pending','expired') AND ord.order_id>%d ORDER BY ord.order_id LIMIT %d", (int) $run->object_id, (int) $run->cursor_id, self::batch() ) ) );
		if ( ! $ids ) { return self::next( $run, 'safety_tickets' ); }
		$cancelled = 0;
		foreach ( $ids as $order_id ) {
			if ( true === Orders::cancel_order( $order_id, 'event_cancelled' ) ) { ++$cancelled; }
		}
		self::save( $run, array( 'cursor_id' => max( $ids ), 'cancelled_orders' => $run->cancelled_orders + $cancelled ) );
		return true;
	}

	/** Safety net: tickets of the event that are still valid. */
	private static function event_safety_tickets( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND t.status='valid' ORDER BY t.ticket_id LIMIT %d", (int) $run->object_id, self::batch() ) );
		if ( ! $ids ) {
			self::next( $run, 'released' );
			self::announce_event_cancelled( $run );
			return true;
		}
		$revoked = Orders::revoke_ids( $ids );
		self::save( $run, array( 'revoked' => $run->revoked + count( $revoked ) ) );
		Orders::fire_revoked( $revoked, 'event_cancelled' );
		return (bool) $revoked;
	}

	/**
	 * PC-9: wpcap_event_cancelled fires once per event cancellation, after every date of the event was
	 * cancelled and every ticket of it revoked (occurrence runs and safety net done), before the capacity
	 * release and the event mails. The run remembers it (data event_announced, saved before the action),
	 * so a resumed or repeated run never fires it again.
	 */
	private static function announce_event_cancelled( $run ) {
		if ( ! empty( $run->data['event_announced'] ) ) { return; }
		$run->data['event_announced'] = 1;
		self::save( $run, array( 'payload' => wp_json_encode( $run->data ) ) );
		/**
		 * An event was cancelled: committed, its dates are cancelled and its tickets revoked.
		 *
		 * @param int   $event_id Event id.
		 * @param array $context  occurrence_ids (cancelled dates), run_id (BulkRuns run; 0 before the schema
		 *                        upgrade). Further keys may be added.
		 */
		do_action( 'wpcap_event_cancelled', (int) $run->object_id, array( 'occurrence_ids' => array_map( 'intval', (array) ( $run->data['occurrence_ids'] ?? array() ) ), 'run_id' => (int) $run->run_id ) );
	}

	/** Capacity of the event's orders stopped counting (open orders reported it when cancelled). */
	private static function event_released( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND oi.order_id>%d ORDER BY oi.order_id LIMIT %d", (int) $run->object_id, (int) $run->cursor_id, self::batch() ) ) );
		if ( ! $ids ) { return self::next( $run, 'mails' ); }
		$pending = array_map( 'intval', (array) ( $run->data['pending_before'] ?? array() ) );
		foreach ( array_diff( $ids, $pending ) as $order_id ) {
			/** See Orders: inventory of an order stopped counting. */
			do_action( 'wpcap_capacity_released', (int) $order_id, 'event_cancelled', array() );
		}
		self::save( $run, array( 'cursor_id' => max( $ids ) ) );
		return true;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Change notices (rescheduling, A140 change mails).
	 * ------------------------------------------------------------------------------------------- */

	private static function notify_queue( $run ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,ord.locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.ticket_id>%d ORDER BY t.ticket_id LIMIT %d", (int) $run->object_id, (int) $run->cursor_id, self::batch() ) );
		if ( ! $rows ) { return self::next( $run, 'mails' ); }
		$spec = (array) ( $run->data['mail'] ?? array() );
		foreach ( $rows as $row ) {
			self::queue_mail( $run, (string) $row->email, (string) $row->locale, $spec, 'notify|' . $run->run_key . '|' . strtolower( (string) $row->email ) );
		}
		self::save( $run, array( 'cursor_id' => (int) end( $rows )->ticket_id ) );
		return true;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Mails through the message queue.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Queues one mail of a run (deduplicated per run and recipient). In-memory runs send at once.
	 *
	 * @param object|int $run   Run or run id.
	 * @param array      $spec  mail (occurrence_cancelled|event_cancelled|occurrence_rescheduled|
	 *                          occurrence_changed or a module kind, see `wpcap_bulk_mail`) and its data.
	 * @return bool True when newly queued (or sent).
	 */
	public static function queue_mail( $run, $email, $locale, array $spec, $dedupe ) {
		global $wpdb;
		$run = is_object( $run ) ? $run : self::get( $run );
		$email = sanitize_email( (string) $email );
		if ( ! $run || ! is_email( $email ) ) { return false; }
		$payload = array( 'run_id' => (int) $run->run_id, 'locale' => (string) $locale ) + $spec;
		if ( ! $run->run_id ) {
			$built = self::build_mail( $payload );
			$sent = $built && Mail\Mailer::deliver( $email, $built['message'] );
			Mail\TempFiles::release( array_filter( (array) ( $built['temp_files'] ?? array() ), 'is_string' ) ); // A626.
			if ( $sent ) { $run->mails_sent = (int) $run->mails_sent + 1; }
			return (bool) $sent;
		}
		$key = 'bulk|' . $dedupe;
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM {$wpdb->prefix}wpcap_message_queue WHERE dedupe_key=%s", hash( 'sha256', $key ) ) ) ) { return false; }
		if ( true !== Communications::enqueue( $key, $email, self::MAIL_PREFIX . (int) $run->run_id, $payload ) ) { return false; }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET mails_queued=mails_queued+1 WHERE run_id=%d', (int) $run->run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		$run->mails_queued = (int) $run->mails_queued + 1;
		return true;
	}

	/** Sends the due queued mails of the run; done when none is due (retries wait for the queue worker). */
	private static function send( $run, $deadline ) {
		if ( ! $run->run_id || ! self::mail_counts( $run )['due'] ) {
			self::finish( $run );
			return true;
		}
		if ( Jobs\TimeBudget::exhausted( $deadline ) ) { return false; }
		return Communications::process_template( self::MAIL_PREFIX . (int) $run->run_id ) > 0;
	}

	/** wpcap_email_template: renders a queued run mail with the current data, in the recipient's locale. */
	public static function render_mail( $template, $template_key, $payload = array() ) {
		if ( 0 !== strpos( (string) $template_key, self::MAIL_PREFIX ) ) { return $template; }
		$built = self::build_mail( (array) $payload );
		return $built ? $built : array( 'subject' => '', 'body' => '' );
	}

	/** @return array|null message (composed), temp_files. */
	public static function build_mail( array $payload ) {
		$kind = (string) ( $payload['mail'] ?? '' );
		$occurrence_id = absint( $payload['occurrence_id'] ?? 0 );
		$spec = null;
		if ( 'occurrence_cancelled' === $kind ) {
			$spec = OccurrenceCancellation::mail_spec( $occurrence_id, ! empty( $payload['auto_refund'] ) );
		} elseif ( 'event_cancelled' === $kind ) {
			$spec = array( 'type' => 'event_cancelled', 'vars' => array( 'event_name' => (string) ( $payload['event_name'] ?? '' ) ) );
		} elseif ( in_array( $kind, array( 'occurrence_rescheduled', 'occurrence_changed' ), true ) ) {
			$spec = Notifications::mail_spec( $payload );
		} else {
			/**
			 * Builds a queued bulk-run mail of a module (A428): return ['type' => mail type,
			 * 'vars' => placeholders] (optional 'ics' => occurrence id for the calendar attachment).
			 *
			 * @param array|null $spec    Null unless a module knows the kind.
			 * @param array      $payload Queued data (run_id, locale, mail, …).
			 */
			$spec = apply_filters( 'wpcap_bulk_mail', null, $payload );
		}
		if ( ! is_array( $spec ) || empty( $spec['type'] ) ) { return null; }
		$message = Mail\Mailer::compose( (string) $spec['type'], (array) ( $spec['vars'] ?? array() ), (string) ( $payload['locale'] ?? '' ) );
		if ( ! $message ) { return null; }
		$ics = ! empty( $payload['ics'] ) ? $occurrence_id : absint( $spec['ics'] ?? 0 );
		$files = $ics ? Notifications::ics_file( $ics ) : array();
		$message['attachments'] = $files;
		return array( 'message' => $message, 'temp_files' => $files );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Progress.
	 * ------------------------------------------------------------------------------------------- */

	/** Queued mails of a run: total, sent, failed, waiting, due. */
	public static function mail_counts( $run ) {
		global $wpdb;
		$out = array( 'total' => 0, 'sent' => 0, 'failed' => 0, 'waiting' => 0, 'due' => 0 );
		if ( ! $run || ! $run->run_id ) { return array( 'sent' => (int) ( $run->mails_sent ?? 0 ), 'total' => (int) ( $run->mails_sent ?? 0 ) ) + $out; }
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$key = self::MAIL_PREFIX . (int) $run->run_id;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS n FROM $table WHERE template_key=%s GROUP BY status", $key ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			$out['total'] += (int) $row->n;
			if ( 'sent' === $row->status ) { $out['sent'] += (int) $row->n; } elseif ( in_array( $row->status, array( 'failed', 'cancelled' ), true ) ) { $out['failed'] += (int) $row->n; } else { $out['waiting'] += (int) $row->n; }
		}
		$out['due'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE template_key=%s AND status='queued' AND send_at<=%s", $key, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return $out;
	}

	/** Refund jobs of a run: retrying (queued/running), failed (dead letter), due. */
	public static function refund_counts( $run ) {
		global $wpdb;
		$out = array( 'retrying' => 0, 'failed' => 0, 'due' => 0 );
		if ( ! $run || ! $run->run_id ) { return $out; }
		$table = $wpdb->prefix . 'wpcap_jobs';
		$like = '{"run_id":' . (int) $run->run_id . ',%';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS n FROM $table WHERE job_type=%s AND payload LIKE %s AND status<>'complete' GROUP BY status", OccurrenceCancellation::REFUND_JOB, $like ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			if ( 'dead_letter' === $row->status ) { $out['failed'] += (int) $row->n; } else { $out['retrying'] += (int) $row->n; }
		}
		$out['due'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE job_type=%s AND payload LIKE %s AND status='queued' AND run_after<=%s", OccurrenceCancellation::REFUND_JOB, $like, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return $out;
	}

	/**
	 * Counters of a run (an event run includes its occurrence runs).
	 *
	 * @return array cancelled_orders, revoked, refunds, refund_errors, refunds_retrying, refunds_failed,
	 *               notified, mails_total, mails_failed, mails_waiting, complete, phase.
	 */
	public static function summary( $run ) {
		$run = is_object( $run ) ? $run : self::get( $run );
		if ( ! $run ) { return array(); }
		$mails = self::mail_counts( $run );
		$refunds = self::refund_counts( $run );
		$out = array(
			'cancelled_orders' => (int) $run->cancelled_orders,
			'revoked' => (int) $run->revoked,
			'refunds' => (int) $run->refunds,
			'refund_errors' => (int) $run->refunds_open + $refunds['retrying'] + $refunds['failed'],
			'refunds_retrying' => $refunds['retrying'],
			'refunds_failed' => $refunds['failed'],
			'notified' => $mails['sent'],
			'mails_total' => $mails['total'],
			'mails_failed' => $mails['failed'],
			'mails_waiting' => $mails['waiting'],
			'complete' => 'complete' === $run->status,
			'phase' => (string) $run->phase,
		);
		if ( 'event_cancel' === $run->kind ) {
			foreach ( self::sub_runs( $run ) as $sub ) {
				$part = self::summary( $sub );
				foreach ( array( 'cancelled_orders', 'revoked', 'refunds', 'refund_errors', 'refunds_retrying', 'refunds_failed' ) as $field ) { $out[ $field ] += (int) $part[ $field ]; }
				$out['complete'] = $out['complete'] && $part['complete'];
			}
		}
		return $out;
	}

	/** Human-readable phase. */
	public static function phase_label( $phase ) {
		$labels = array(
			'orders' => __( 'offene Bestellungen stornieren', 'pass-captain' ),
			'refund_plan' => __( 'Erstattungen vorbereiten', 'pass-captain' ),
			'revoke' => __( 'Tickets widerrufen', 'pass-captain' ),
			'modules' => __( 'Pässe und Module', 'pass-captain' ),
			'refunds' => __( 'Erstattungen ausführen', 'pass-captain' ),
			'mails_plan' => __( 'Benachrichtigungen vorbereiten', 'pass-captain' ),
			'occurrences' => __( 'Termine absagen', 'pass-captain' ),
			'wait' => __( 'Terminabsagen abschließen', 'pass-captain' ),
			'safety_orders' => __( 'offene Bestellungen stornieren', 'pass-captain' ),
			'safety_tickets' => __( 'Tickets widerrufen', 'pass-captain' ),
			'released' => __( 'Kontingente freigeben', 'pass-captain' ),
			'queue' => __( 'Benachrichtigungen vorbereiten', 'pass-captain' ),
			'mails' => __( 'E-Mails versenden', 'pass-captain' ),
			'done' => __( 'abgeschlossen', 'pass-captain' ),
		);
		return $labels[ $phase ] ?? (string) $phase;
	}

	/** Progress box of runs (admin event/occurrence page). */
	public static function render_progress( array $runs ) {
		$runs = array_values( array_filter( $runs ) );
		if ( ! $runs ) { return; }
		$kinds = array( 'occurrence_cancel' => __( 'Terminabsage', 'pass-captain' ), 'event_cancel' => __( 'Eventabsage', 'pass-captain' ), 'notify' => __( 'Änderungsmitteilung', 'pass-captain' ) );
		echo '<h2>' . esc_html__( 'Absagen und Benachrichtigungen', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped wpcap-bulk-runs"><thead><tr><th scope="col">' . esc_html__( 'Vorgang', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Stand', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Fortschritt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			$s = self::summary( $run );
			$running = 'running' === $run->status;
			$state = $running
				/* translators: %s: current step */
				? sprintf( __( 'läuft: %s', 'pass-captain' ), self::phase_label( $run->phase ) )
				: __( 'abgeschlossen', 'pass-captain' );
			$parts = array();
			if ( 'notify' !== $run->kind ) {
				/* translators: %d: number */
				$parts[] = sprintf( _n( '%d offene Bestellung storniert', '%d offene Bestellungen storniert', $s['cancelled_orders'], 'pass-captain' ), $s['cancelled_orders'] );
				/* translators: %d: number */
				$parts[] = sprintf( _n( '%d Ticket widerrufen', '%d Tickets widerrufen', $s['revoked'], 'pass-captain' ), $s['revoked'] );
				/* translators: 1: refunds done, 2: refunds retried in the background, 3: failed refunds */
				$parts[] = sprintf( __( '%1$d Erstattungen ausgeführt, %2$d werden wiederholt, %3$d fehlgeschlagen', 'pass-captain' ), $s['refunds'], $s['refunds_retrying'], $s['refunds_failed'] );
			}
			/* translators: 1: sent mails, 2: queued mails, 3: failed mails */
			$parts[] = sprintf( __( '%1$d von %2$d E-Mails versendet (%3$d fehlgeschlagen)', 'pass-captain' ), $s['notified'], $s['mails_total'], $s['mails_failed'] );
			echo '<tr><td>' . esc_html( $kinds[ $run->kind ] ?? $run->kind ) . '<br><span class="description">' . esc_html( Admin\Forms::datetime( $run->created_at, 'UTC' ) ) . '</span></td>';
			echo '<td>' . esc_html( $state ) . '</td><td>' . esc_html( implode( ' · ', $parts ) ) . '</td><td>';
			if ( $running ) {
				Admin\Forms::open_form( 'wpcap_resume_bulk_run', 'wpcap_resume_bulk_run_' . (int) $run->run_id, 'style="display:inline"' );
				Admin\Forms::hidden( 'run_id', (int) $run->run_id );
				echo '<button class="button" type="submit">' . esc_html__( 'Jetzt fortsetzen', 'pass-captain' ) . '</button></form><p class="description">' . esc_html__( 'Läuft sonst automatisch im Hintergrund weiter.', 'pass-captain' ) . '</p>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
