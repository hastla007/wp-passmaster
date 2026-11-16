<?php
/**
 * Batch print as a background job (A427, A630).
 *
 * A batch PDF with new ticket codes used to rotate all codes first and then render everything in
 * memory in the admin request: a time-out or memory limit left every ticket of the date invalid
 * without a PDF (the new codes were never stored). Now:
 *
 * 1. create(): the print order (ticket ids, current code hashes) is stored in a private file and a
 *    row in wpcap_print_batches; nothing changes at the tickets.
 * 2. Job `print_batch`: renders block_size() tickets per run with fresh codes and appends the pages
 *    to a private file (byte sizes of the committed state in the row; a run that died is redone
 *    from there). The new codes are kept only in the PDF and in a private sidecar file; the tickets
 *    keep their codes. The last run writes the PDF trailer → status `ready`.
 * 3. deliver() (first download): the new codes are activated in one transaction (only for tickets
 *    that are still valid with the code they had when the batch was created) and the file is sent.
 *    Hooks (`wpcap_ticket_code_rotated`: help codes, wallet passes, partner codes) follow in the job
 *    `print_batch_notify`.
 *
 * Cancelled, failed or never downloaded batches therefore never invalidate a ticket. Tickets held
 * by someone other than the buyer (transferred, or assigned to another attendee e-mail) get no new
 * code: they are printed without QR code and their holder keeps the valid code (A630).
 * Files: BatchJob::dir() (default uploads/pass-captain-private, filter `wpcap_print_batch_dir`),
 * random names; batches are deleted keep_days() after their last change (hourly cleanup).
 */
namespace PassCaptain\Printing;

use PassCaptain\Audit;
use PassCaptain\HelpCode;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BatchJob {
	const JOB = 'print_batch';
	const NOTIFY_JOB = 'print_batch_notify';
	/** Tickets rendered per job run (filter wpcap_print_batch_block; rounded up to a multiple of 10). */
	const BLOCK = 200;
	/** Most tickets in one batch (filter wpcap_print_batch_max). */
	const MAX = 20000;
	/** Batches without new codes up to this size are rendered in the request (filter wpcap_print_batch_sync_limit). */
	const SYNC_LIMIT = 300;
	/** Days a batch and its file are kept after the last change (filter wpcap_print_batch_keep_days). */
	const KEEP_DAYS = 7;

	public static function register() {
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 3 );
		add_action( 'wpcap_cleanup_reservations', array( __CLASS__, 'cleanup' ), 70, 0 );
	}

	public static function ready() { return Privacy::has_table( 'wpcap_print_batches' ); }

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_print_batches';
	}

	public static function block_size() {
		$n = max( 1, (int) apply_filters( 'wpcap_print_batch_block', self::BLOCK ) );
		return (int) ( ceil( $n / 10 ) * 10 ); // Name tags: full pages of 10.
	}

	public static function max_tickets() { return max( 1, (int) apply_filters( 'wpcap_print_batch_max', self::MAX ) ); }

	public static function sync_limit() { return max( 0, (int) apply_filters( 'wpcap_print_batch_sync_limit', self::SYNC_LIMIT ) ); }

	public static function keep_days() { return max( 1, (int) apply_filters( 'wpcap_print_batch_keep_days', self::KEEP_DAYS ) ); }

	/** Private directory for batch files (created 0700 with deny rules); null when not writable. */
	public static function dir() {
		$base = function_exists( 'wp_upload_dir' ) ? (string) ( wp_upload_dir()['basedir'] ?? '' ) : '';
		$dir = '' !== $base ? rtrim( $base, '/\\' ) . '/pass-captain-private' : rtrim( (string) get_temp_dir(), '/\\' ) . '/pass-captain-private';
		$dir = rtrim( (string) apply_filters( 'wpcap_print_batch_dir', $dir ), '/\\' );
		if ( ! is_dir( $dir ) ) {
			$old = umask( 0077 );
			@mkdir( $dir, 0700, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
			umask( $old );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { return null; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/** Path of a batch file: ids (print order), part (PDF being written), pdf, xref (page offsets), codes. */
	public static function path( $batch, $ext ) {
		$dir = self::dir();
		return null === $dir ? '' : $dir . '/batch-' . preg_replace( '/[^a-f0-9]/', '', (string) $batch->token ) . '.' . $ext;
	}

	public static function get( $batch_id ) {
		global $wpdb;
		if ( ! self::ready() ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE batch_id=%d', absint( $batch_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Recent batches the current user may download (print page). */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		if ( ! self::ready() ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY batch_id DESC LIMIT %d', max( 1, (int) $limit ) * 3 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_slice( array_values( array_filter( $rows, array( __CLASS__, 'can_access' ) ) ), 0, (int) $limit );
	}

	/** Same rights as creating the batch: print rights for the date, order management for new codes. */
	public static function can_access( $batch ) {
		if ( ! $batch ) { return false; }
		$event_id = Scope::occurrence_event( (int) $batch->occurrence_id );
		if ( ! $event_id || ! Scope::can_occurrence( (int) $batch->occurrence_id, array( 'wpcap_manage_events', 'wpcap_manage_orders' ) ) ) { return false; }
		return ! (int) $batch->with_codes || Scope::can_event( $event_id, array( 'wpcap_manage_orders' ) );
	}

	/**
	 * Creates a batch (permission checked by Page::batch_request()). Returns the batch id.
	 *
	 * @return int|\WP_Error
	 */
	public static function create( $occurrence_id, $kind, $with_codes ) {
		global $wpdb;
		if ( ! self::ready() ) { return new \WP_Error( 'wpcap_print_batch_unavailable', __( 'Der Stapeldruck im Hintergrund ist noch nicht eingerichtet (Datenbank-Update ausstehend).', 'pass-captain' ) ); }
		$refs = Templates::ticket_refs_for_occurrence( $occurrence_id, $with_codes ? array( 'valid' ) : Templates::batch_statuses( $kind ) );
		if ( ! $refs ) { return new \WP_Error( 'wpcap_print_batch_empty', __( 'Für diesen Termin gibt es keine Tickets zum Drucken.', 'pass-captain' ) ); }
		if ( count( $refs ) > self::max_tickets() ) {
			/* translators: %d: maximum number of tickets */
			return new \WP_Error( 'wpcap_print_batch_too_large', sprintf( _n( 'Ein Stapeldruck umfasst höchstens %d Ticket. Bitte den Grenzwert (Filter wpcap_print_batch_max) erhöhen.', 'Ein Stapeldruck umfasst höchstens %d Tickets. Bitte den Grenzwert (Filter wpcap_print_batch_max) erhöhen.', self::max_tickets(), 'pass-captain' ), self::max_tickets() ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$probe = (object) array( 'token' => $token );
		$ids_file = self::path( $probe, 'ids' );
		if ( '' === $ids_file ) { return new \WP_Error( 'wpcap_print_batch_dir', __( 'Das Verzeichnis für Druckdateien ist nicht beschreibbar.', 'pass-captain' ) ); }
		$lines = '';
		$held = 0;
		foreach ( $refs as $ref ) {
			$lines .= (int) $ref->ticket_id . "\t" . preg_replace( '/[^a-f0-9]/', '', (string) $ref->token_hash ) . "\t" . (int) $ref->held_by_other . "\n";
			$held += (int) $ref->held_by_other;
		}
		if ( false === self::write( $ids_file, $lines, 0 ) ) { return new \WP_Error( 'wpcap_print_batch_dir', __( 'Das Verzeichnis für Druckdateien ist nicht beschreibbar.', 'pass-captain' ) ); }
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( self::table(), array( 'token' => $token, 'occurrence_id' => absint( $occurrence_id ), 'kind' => $kind, 'with_codes' => $with_codes ? 1 : 0, 'user_id' => get_current_user_id(), 'status' => 'rendering', 'total' => count( $refs ), 'held_by_other' => $with_codes ? $held : 0, 'created_at' => $now, 'updated_at' => $now ) );
		if ( ! $ok ) {
			self::delete_files( $probe );
			return new \WP_Error( 'wpcap_print_batch_failed', __( 'Der Stapeldruck konnte nicht angelegt werden.', 'pass-captain' ) );
		}
		$batch_id = (int) $wpdb->insert_id;
		Audit::record( 'print_batch_created', 'occurrence', absint( $occurrence_id ), array( 'batch' => $batch_id, 'kind' => $kind, 'codes' => (bool) $with_codes, 'tickets' => count( $refs ), 'held_by_other' => $with_codes ? $held : 0 ) );
		self::queue( self::JOB, $batch_id, 0 );
		return $batch_id;
	}

	private static function queue( $type, $batch_id, $cursor ) {
		$result = Jobs::enqueue( ( self::JOB === $type ? 'print-batch|' : 'print-batch-notify|' ) . (int) $batch_id . '|' . (int) $cursor, $type, array( 'batch_id' => (int) $batch_id ) );
		Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' );
		return $result;
	}

	/** Filter wpcap_process_job. */
	public static function handle_job( $handled, $type, $payload ) {
		if ( self::JOB !== $type && self::NOTIFY_JOB !== $type ) { return $handled; }
		$batch_id = absint( $payload['batch_id'] ?? 0 );
		$result = self::JOB === $type ? self::step( $batch_id ) : self::notify( $batch_id );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( 'more' === $result ) {
			$batch = self::get( $batch_id );
			$queued = self::queue( $type, $batch_id, self::JOB === $type ? (int) $batch->done : (int) $batch->notified );
			if ( is_wp_error( $queued ) ) { return $queued; }
		}
		return true;
	}

	/**
	 * Renders blocks until the batch is ready or $budget seconds are used (admin request after
	 * create(), so small batches are ready at once). Returns the last step() result.
	 */
	public static function work( $batch_id, $budget = 3.0 ) {
		$deadline = microtime( true ) + (float) $budget;
		do {
			$result = self::step( $batch_id );
		} while ( 'more' === $result && microtime( true ) < $deadline );
		return $result;
	}

	/** One block under the batch lock: 'more', 'done', 'busy' (another worker), 'stop' or WP_Error. */
	public static function step( $batch_id ) {
		global $wpdb;
		$lock = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->prefix ) . 'wpcap_print_batch_' . absint( $batch_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 'busy'; }
		try {
			return self::render_block( absint( $batch_id ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function render_block( $batch_id ) {
		global $wpdb;
		$batch = self::get( $batch_id );
		if ( ! $batch || 'rendering' !== $batch->status ) { return 'stop'; }
		$part = self::path( $batch, 'part' );
		if ( '' === $part ) { return new \WP_Error( 'wpcap_print_batch_dir', 'print directory not writable' ); }
		$pdf_bytes = (int) $batch->pdf_bytes;
		if ( 0 === $pdf_bytes ) {
			if ( false === self::write( $part, PdfWriter::header(), 0 ) ) { return self::fail( $batch, 'write' ); }
			$pdf_bytes = strlen( PdfWriter::header() );
		}
		if ( (int) $batch->done >= (int) $batch->total ) { return self::finalize( $batch, $pdf_bytes ); }
		$slice = self::lines( self::path( $batch, 'ids' ), (int) $batch->done, self::block_size() );
		if ( ! $slice ) { return self::fail( $batch, 'ids' ); }
		$rows = Templates::tickets_by_ids( array_map( static fn( $l ) => (int) $l[0], $slice ) );
		$items = array();
		$codes = '';
		foreach ( $slice as $line ) {
			$row = $rows[ (int) $line[0] ] ?? null;
			if ( ! $row ) { continue; } // Deleted meanwhile.
			$token = '';
			$help = '';
			// New codes only for tickets still valid with the code they had at creation, never for
			// tickets someone else holds (A630).
			if ( (int) $batch->with_codes && ! (int) $line[2] && 'valid' === $row->status && hash_equals( (string) $line[1], (string) $row->token_hash ) ) {
				$token = bin2hex( random_bytes( 32 ) );
				$help = HelpCode::preview( (int) $row->ticket_id, $token );
				$codes .= (int) $row->ticket_id . "\t" . $token . "\t" . $line[1] . "\n";
			}
			$items[] = Templates::values( $row, $token, $help );
		}
		$pages = Templates::pages( $batch->kind, $items, Templates::body_for_event( Scope::occurrence_event( (int) $batch->occurrence_id ), $batch->kind ) );
		$chunk = $pages->page_objects( 6 + 2 * (int) $batch->pages, $pdf_bytes );
		$xref = '';
		foreach ( $chunk['offsets'] as $offset ) { $xref .= sprintf( "%010d\n", $offset ); }
		// Append from the committed sizes (a run that died before its row update is redone).
		if ( false === self::write( $part, $chunk['bytes'], $pdf_bytes )
			|| false === self::write( self::path( $batch, 'xref' ), $xref, (int) $batch->xref_bytes )
			|| ( (int) $batch->with_codes && false === self::write( self::path( $batch, 'codes' ), $codes, (int) $batch->codes_bytes ) ) ) {
			return self::fail( $batch, 'write' );
		}
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET done=done+%d,pages=pages+%d,pdf_bytes=%d,xref_bytes=xref_bytes+%d,codes_bytes=codes_bytes+%d,updated_at=%s WHERE batch_id=%d AND status=%s AND done=%d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			count( $slice ),
			count( $chunk['offsets'] ) / 2,
			$pdf_bytes + strlen( $chunk['bytes'] ),
			strlen( $xref ),
			strlen( $codes ),
			current_time( 'mysql', true ),
			$batch_id,
			'rendering',
			(int) $batch->done
		) );
		if ( 1 !== (int) $updated ) { return false === $updated ? \PassCaptain\Errors::busy( 'batch progress not saved' ) : 'stop'; }
		return (int) $batch->done + count( $slice ) >= (int) $batch->total ? self::finalize( self::get( $batch_id ), $pdf_bytes + strlen( $chunk['bytes'] ) ) : 'more';
	}

	/** Writes the PDF trailer and marks the batch ready. */
	private static function finalize( $batch, $pdf_bytes ) {
		global $wpdb;
		$offsets = array();
		$xref = self::lines( self::path( $batch, 'xref' ), 0, PHP_INT_MAX, (int) $batch->xref_bytes );
		foreach ( $xref as $i => $line ) { $offsets[ 6 + $i ] = (int) $line[0]; }
		$part = self::path( $batch, 'part' );
		$trailer = PdfWriter::trailer( Templates::title( $batch->kind ), $offsets, $pdf_bytes );
		if ( false === self::write( $part, $trailer, $pdf_bytes ) || ! @rename( $part, self::path( $batch, 'pdf' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return self::fail( $batch, 'write' );
		}
		$wpdb->update( self::table(), array( 'status' => 'ready', 'pdf_bytes' => $pdf_bytes + strlen( $trailer ), 'updated_at' => current_time( 'mysql', true ) ), array( 'batch_id' => (int) $batch->batch_id, 'status' => 'rendering' ) );
		wp_delete_file( self::path( $batch, 'xref' ) );
		return 'done';
	}

	private static function fail( $batch, $reason ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'failed', 'error' => sanitize_key( $reason ), 'updated_at' => current_time( 'mysql', true ) ), array( 'batch_id' => (int) $batch->batch_id, 'status' => 'rendering' ) );
		self::delete_files( $batch );
		Audit::record( 'print_batch_failed', 'occurrence', (int) $batch->occurrence_id, array( 'batch' => (int) $batch->batch_id, 'reason' => sanitize_key( $reason ) ) );
		return 'stop';
	}

	/**
	 * First download of a ready batch: activates the new codes (one transaction) and returns the file.
	 * Later downloads return the file without changing anything.
	 *
	 * @return array{path:string, filename:string, rotated:int, skipped:int}|\WP_Error
	 */
	public static function deliver( $batch_id ) {
		global $wpdb;
		$batch = self::get( $batch_id );
		if ( ! $batch || ! in_array( $batch->status, array( 'ready', 'delivered' ), true ) || ! is_readable( self::path( $batch, 'pdf' ) ) ) {
			return new \WP_Error( 'wpcap_print_batch_not_ready', __( 'Die Druckdatei ist nicht (mehr) verfügbar.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$file = array( 'path' => self::path( $batch, 'pdf' ), 'filename' => $batch->kind . '-' . (int) $batch->occurrence_id . '-' . (int) $batch->batch_id . '.pdf' );
		if ( 'delivered' === $batch->status ) { return $file + array( 'rotated' => (int) $batch->rotated, 'skipped' => (int) $batch->skipped ); }
		$table = $wpdb->prefix . 'wpcap_tickets';
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return \PassCaptain\Errors::busy( __( 'Die neuen Ticketcodes konnten gerade nicht aktiviert werden; es wurde nichts geändert. Bitte erneut herunterladen.', 'pass-captain' ) );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::table() . ' WHERE batch_id=%d FOR UPDATE', (int) $batch->batch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'ready' !== $status ) {
			$wpdb->query( 'ROLLBACK' );
			return 'delivered' === $status ? self::deliver( $batch_id ) : new \WP_Error( 'wpcap_print_batch_not_ready', __( 'Die Druckdatei ist nicht (mehr) verfügbar.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$rotated = 0;
		$skipped = 0;
		$help = HelpCode::ready() ? ',help_hash=NULL,help_n=0' : '';
		foreach ( (int) $batch->with_codes ? self::lines( self::path( $batch, 'codes' ), 0, PHP_INT_MAX, (int) $batch->codes_bytes ) : array() as $line ) {
			// Only while the ticket is valid with the code it had when the batch was created.
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET token_hash=%s$help WHERE ticket_id=%d AND status='valid' AND token_hash=%s", hash( 'sha256', (string) $line[1] ), (int) $line[0], (string) $line[2] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $changed ) { return $busy(); }
			if ( $changed ) { \PassCaptain\CodeHistory::replaced( (int) $line[0], (string) $line[2], 'print' ); } // A543
			$changed ? ++$rotated : ++$skipped;
		}
		$done = $wpdb->update( self::table(), array( 'status' => 'delivered', 'rotated' => $rotated, 'skipped' => $skipped, 'delivered_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'batch_id' => (int) $batch->batch_id, 'status' => 'ready' ) );
		if ( 1 !== (int) $done || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		if ( $rotated ) {
			Audit::record( 'ticket_codes_rotated', 'occurrence', (int) $batch->occurrence_id, array( 'count' => $rotated, 'skipped' => $skipped, 'reason' => sanitize_key( 'batch_' . $batch->kind ), 'batch' => (int) $batch->batch_id ) );
			self::queue( self::NOTIFY_JOB, (int) $batch->batch_id, 0 );
		} elseif ( (int) $batch->with_codes ) {
			wp_delete_file( self::path( $batch, 'codes' ) );
		}
		Audit::record( 'print_batch_delivered', 'occurrence', (int) $batch->occurrence_id, array( 'batch' => (int) $batch->batch_id, 'rotated' => $rotated, 'skipped' => $skipped ) );
		return $file + array( 'rotated' => $rotated, 'skipped' => $skipped );
	}

	/**
	 * After delivery: fires `wpcap_ticket_code_rotated` (reason "print") for every activated code
	 * (help codes, wallet passes, partner codes), within the cron time budget; then deletes the codes.
	 */
	public static function notify( $batch_id ) {
		global $wpdb;
		$batch = self::get( $batch_id );
		if ( ! $batch || 'delivered' !== $batch->status ) { return 'stop'; }
		$codes = self::path( $batch, 'codes' );
		$lines = is_readable( $codes ) ? self::lines( $codes, (int) $batch->notified, PHP_INT_MAX, (int) $batch->codes_bytes ) : array();
		$deadline = Jobs\TimeBudget::deadline();
		$cursor = (int) $batch->notified;
		$count = 0;
		foreach ( $lines as $line ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			++$cursor;
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", (int) $line[0] ) );
			if ( ! hash_equals( $current, hash( 'sha256', (string) $line[1] ) ) ) { continue; } // Skipped or changed since.
			/** The ticket got a new code (old one invalid), e.g. so wallet passes can be refreshed. */
			do_action( 'wpcap_ticket_code_rotated', (int) $line[0], (string) $line[1], 'print' );
			++$count;
		}
		$wpdb->update( self::table(), array( 'notified' => $cursor ), array( 'batch_id' => (int) $batch->batch_id ) );
		if ( $cursor < (int) $batch->notified + count( $lines ) ) { return 'more'; }
		wp_delete_file( $codes ); // The new codes now exist only in the PDF.
		return 'done';
	}

	/** Cancels a batch that is not delivered yet: nothing changes at the tickets, the files are deleted. */
	public static function cancel( $batch_id ) {
		global $wpdb;
		$batch = self::get( $batch_id );
		if ( ! $batch ) { return new \WP_Error( 'wpcap_print_batch_not_found', __( 'Stapeldruck nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='cancelled',updated_at=%s WHERE batch_id=%d AND status IN ('rendering','ready')", current_time( 'mysql', true ), (int) $batch->batch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $changed ) { return new \WP_Error( 'wpcap_print_batch_delivered', __( 'Dieser Stapeldruck wurde bereits heruntergeladen und kann nicht mehr abgebrochen werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		self::delete_files( $batch );
		Audit::record( 'print_batch_cancelled', 'occurrence', (int) $batch->occurrence_id, array( 'batch' => (int) $batch->batch_id ) );
		return true;
	}

	/**
	 * Hourly (wpcap_cleanup_reservations): deletes batches and files keep_days() after their last
	 * change (a ready batch nobody downloaded never changed a ticket) and fails batches whose
	 * rendering stopped for a day.
	 */
	public static function cleanup() {
		global $wpdb;
		if ( ! self::ready() ) { return 0; }
		$now = time();
		$stuck = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status='rendering' AND updated_at<%s LIMIT 100", gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $stuck as $batch ) { self::fail( $batch, 'stalled' ); }
		$old = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE updated_at<%s LIMIT 100', gmdate( 'Y-m-d H:i:s', $now - self::keep_days() * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $old as $batch ) {
			if ( 'rendering' === $batch->status ) { continue; }
			self::delete_files( $batch );
			$wpdb->delete( self::table(), array( 'batch_id' => (int) $batch->batch_id ) );
		}
		return count( $old );
	}

	private static function delete_files( $batch ) {
		foreach ( array( 'ids', 'part', 'pdf', 'xref', 'codes' ) as $ext ) {
			$file = self::path( $batch, $ext );
			if ( '' !== $file ) { wp_delete_file( $file ); }
		}
	}

	/** Writes $data at byte $at (the file is cut there first), mode 0600. */
	private static function write( $file, $data, $at ) {
		if ( '' === (string) $file ) { return false; }
		$old = umask( 0077 );
		$fh = @fopen( $file, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		umask( $old );
		if ( ! $fh ) { return false; }
		$ok = ftruncate( $fh, (int) $at ) && 0 === fseek( $fh, (int) $at ) && ( '' === (string) $data || strlen( $data ) === fwrite( $fh, $data ) ) && fflush( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok;
	}

	/**
	 * Tab-separated lines $offset … $offset+$count-1 of a file, read up to byte $limit (the committed
	 * size; a line written by a run that died is ignored).
	 *
	 * @return array<int,string[]>
	 */
	private static function lines( $file, $offset, $count, $limit = PHP_INT_MAX ) {
		if ( '' === (string) $file || ! is_readable( $file ) ) { return array(); }
		$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) { return array(); }
		$out = array();
		$i = 0;
		while ( count( $out ) < $count && ftell( $fh ) < $limit && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( "\n" !== substr( $line, -1 ) ) { break; }
			if ( $i++ < $offset ) { continue; }
			$out[] = explode( "\t", rtrim( $line, "\n" ) );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/** Sends a delivered file (streamed, not loaded into memory). */
	public static function send( array $file ) {
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $file['filename'] ) . '"' );
			header( 'Content-Length: ' . (int) filesize( $file['path'] ) );
			header( 'X-Content-Type-Options: nosniff' );
		}
		readfile( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		\PassCaptain\Http::end();
	}
}
