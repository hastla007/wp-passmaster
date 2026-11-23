<?php
/**
 * A687/A688: bulk check-in and check-out of several tickets in one request (app route /app/scan/batch and
 * the admin ticket list).
 *
 * Every ticket goes through exactly the rules of a single admission (Checkin::perform_ticket() /
 * check_out_ticket(): event and date scope, device and area binding, admission policy, area capacity,
 * ticket lock and log). The run has a batch key chosen by the caller; each ticket gets the idempotency key
 * "<prefix><batch key>:<ticket id>", so sending the same batch again (after a timeout or for the failed
 * tickets) never admits a ticket twice: tickets already booked by this batch answer with their first result
 * (`replayed`), only the rest is tried again. The report counts the outcomes (done, already, refused, retry)
 * and lists each ticket.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admission;

use PassCaptain\Audit;
use PassCaptain\Checkin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BulkCheckin {
	/** Most tickets of one run (filter `wpcap_bulk_checkin_max`, at most 500). */
	const MAX = 100;

	/** Results that mean "already in the wanted state" (nothing to do, not an error). */
	const ALREADY = array( 'already_checked_in', 'already_inside', 'not_checked_in' );

	/** Largest number of tickets per run. */
	public static function max() {
		return max( 1, min( 500, (int) apply_filters( 'wpcap_bulk_checkin_max', self::MAX ) ) );
	}

	/** A batch key: 8–64 characters of letters, digits, "-", "_" and "."; '' when invalid. */
	public static function key( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( '/^[A-Za-z0-9._-]{8,64}$/', $value ) ? $value : '';
	}

	/**
	 * Runs a batch.
	 *
	 * @param int[]  $ticket_ids Tickets (duplicates are dropped, order kept).
	 * @param string $direction  'in' or 'out'.
	 * @param string $batch_key  Idempotency key of the batch (key()).
	 * @param array  $context    Context of Checkin (device_id, event_ids, occurrence_id, area_id, source); the
	 *                           idempotency key is set per ticket. `key_prefix` separates callers (app:, admin:).
	 * @return array|\WP_Error Report: batch_key, direction, total, counts{done,already,refused,retry}, complete,
	 *                         results[] {ticket_id, ok, result, message, replayed, retryable}.
	 */
	public static function run( array $ticket_ids, $direction, $batch_key, array $context = array() ) {
		$batch_key = self::key( $batch_key );
		if ( '' === $batch_key ) {
			return new \WP_Error( 'wpcap_bulk_key', __( 'Für den Sammellauf fehlt ein gültiger Schlüssel (8–64 Zeichen).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$direction = 'out' === $direction ? 'out' : 'in';
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ids || count( $ids ) > self::max() ) {
			/* translators: %d: maximum number of tickets */
			return new \WP_Error( 'wpcap_bulk_size', sprintf( _n( 'Ein Sammellauf umfasst höchstens %d Ticket.', 'Ein Sammellauf umfasst 1 bis %d Tickets.', self::max(), 'pass-captain' ), self::max() ), array( 'status' => 400 ) );
		}
		$prefix = (string) ( $context['key_prefix'] ?? '' );
		unset( $context['key_prefix'] );
		$counts = array( 'done' => 0, 'already' => 0, 'refused' => 0, 'retry' => 0 );
		$results = array();
		foreach ( $ids as $ticket_id ) {
			$ctx = $context;
			$ctx['idempotency_key'] = $prefix . 'bulk:' . $batch_key . ':' . $ticket_id;
			$outcome = 'out' === $direction ? Checkin::check_out_ticket( $ticket_id, $ctx ) : Checkin::perform_ticket( $ticket_id, $ctx );
			$row = self::row( $ticket_id, $outcome, $direction );
			++$counts[ $row['bucket'] ];
			unset( $row['bucket'] );
			$results[] = $row;
		}
		Audit::record( 'bulk_check_' . $direction, 'batch', 0, array( 'batch_key' => $batch_key, 'tickets' => count( $ids ), 'counts' => $counts, 'device_id' => absint( $context['device_id'] ?? 0 ), 'source' => sanitize_key( (string) ( $context['source'] ?? '' ) ) ) );
		return array(
			'batch_key' => $batch_key,
			'direction' => $direction,
			'total'     => count( $ids ),
			'counts'    => $counts,
			// Complete: nothing left that a repeat of the same batch could still book.
			'complete'  => 0 === $counts['retry'],
			'results'   => $results,
		);
	}

	/** One report line; `bucket` (done, already, refused, retry) is removed by run(). */
	private static function row( $ticket_id, $outcome, $direction ) {
		if ( ! is_wp_error( $outcome ) ) {
			return array( 'ticket_id' => (int) $ticket_id, 'public_id' => (string) ( $outcome['public_id'] ?? '' ), 'ok' => true, 'result' => (string) ( $outcome['status'] ?? ( 'out' === $direction ? 'checked_out' : 'checked_in' ) ), 'message' => '', 'replayed' => ! empty( $outcome['replayed'] ), 'retryable' => false, 'bucket' => 'done' );
		}
		$data = (array) $outcome->get_error_data();
		$retry = ! empty( $data['retryable'] ) || \PassCaptain\Errors::is_busy( $outcome );
		$result = $retry ? 'retry' : (string) ( $data['result'] ?? $data['ticket_status'] ?? 'error' );
		return array(
			'ticket_id' => (int) $ticket_id,
			'public_id' => '',
			'ok'        => false,
			'result'    => $result,
			'message'   => $outcome->get_error_message(),
			'replayed'  => false,
			'retryable' => $retry,
			'bucket'    => $retry ? 'retry' : ( in_array( $result, self::ALREADY, true ) ? 'already' : 'refused' ),
		);
	}

	/** Ticket ids of the report lines a repeat would try again (retry); refused ones need a decision. */
	public static function retry_ids( array $report ) {
		$out = array();
		foreach ( (array) ( $report['results'] ?? array() ) as $row ) {
			if ( ! empty( $row['retryable'] ) ) { $out[] = (int) $row['ticket_id']; }
		}
		return $out;
	}
}
