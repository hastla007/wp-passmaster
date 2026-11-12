<?php
/**
 * Applies the per-event offline rules to the results of the existing offline sync endpoint
 * (/app/scan/sync). Called after Api::sync() with the uploaded scans (same order as the results).
 *
 *  - duplicate_policy first_sync_wins: a double admission (ticket already admitted by an earlier
 *    upload) is still recorded as admission conflict, but resolved automatically as "duplicate_scan".
 *  - max_offline_hours: an admitted scan whose device time is older than the event's maximum offline
 *    duration is flagged (offline_expired) and recorded as an open conflict for review.
 *
 * Result rows gain: offline_rules (policy), auto_resolved, offline_expired, conflict_status.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Admission\Conflicts;
use PassCaptain\Audit;
use PassCaptain\Checkin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Sync {

	public static function apply( array $results, array $scans, $device ) {
		$rules_cache = array();
		$rules_for = static function ( $event_id ) use ( &$rules_cache ) {
			if ( ! isset( $rules_cache[ $event_id ] ) ) { $rules_cache[ $event_id ] = Rules::get( $event_id ); }
			return $rules_cache[ $event_id ];
		};
		foreach ( $results as $i => $row ) {
			if ( ! is_array( $row ) || 'invalid' === ( $row['result'] ?? '' ) ) { continue; }
			$scan = isset( $scans[ $i ] ) && is_array( $scans[ $i ] ) ? $scans[ $i ] : array();
			$conflict = ! empty( $row['conflict_id'] ) ? Conflicts::get( (int) $row['conflict_id'] ) : null;
			$event_id = $conflict ? (int) $conflict->event_id : (int) ( $row['event_id'] ?? 0 );
			if ( ! $event_id ) { continue; }
			$rules = $rules_for( $event_id );
			if ( ! $rules['enabled'] ) { continue; }
			$row['offline_rules'] = $rules['duplicate_policy'];

			if ( $conflict && ! empty( $row['double_admission'] ) && 'first_sync_wins' === $rules['duplicate_policy'] ) {
				self::auto_resolve( $conflict );
				$conflict = Conflicts::get( (int) $conflict->conflict_id );
			}

			$scanned = strtotime( (string) ( $scan['scanned_at'] ?? '' ) );
			$out = 'out' === ( $scan['direction'] ?? 'in' );
			if ( ! $out && $scanned && $scanned < time() - $rules['max_offline_hours'] * HOUR_IN_SECONDS ) {
				$row['offline_expired'] = true;
				if ( ! $conflict && ! empty( $row['ok'] ) ) {
					$id = self::record_expired( (string) ( $scan['code'] ?? '' ), (string) ( $scan['idempotency_key'] ?? '' ), $scanned, $device );
					if ( $id ) {
						$row['conflict'] = true;
						$row['conflict_id'] = $id;
						$conflict = Conflicts::get( $id );
					}
				}
			}
			if ( $conflict ) {
				$row['conflict_status'] = (string) $conflict->status;
				$row['auto_resolved'] = 'resolved' === $conflict->status && 'duplicate_scan' === $conflict->resolution && 0 === (int) $conflict->resolved_by;
			}
			$results[ $i ] = $row;
		}
		return $results;
	}

	private static function auto_resolve( $conflict ) {
		global $wpdb;
		if ( 'open' !== $conflict->status ) { return; }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_admission_conflicts SET status='resolved', resolution='duplicate_scan', resolution_note=%s, resolved_by=0, resolved_at=%s WHERE conflict_id=%d AND status='open'", __( 'Automatisch geklärt: Regel „Erste Synchronisierung gewinnt“ (Offline-Scanner).', 'wp-pass-captain' ), current_time( 'mysql', true ), (int) $conflict->conflict_id ) );
		if ( $updated ) {
			Audit::record( 'admission_conflict_auto_resolved', 'admission_conflict', (int) $conflict->conflict_id, array( 'event_id' => (int) $conflict->event_id, 'ticket_id' => (int) $conflict->ticket_id, 'rule' => 'first_sync_wins' ) );
		}
	}

	/** Records an "offline_expired" review conflict for an admitted scan (idempotent per scan). */
	private static function record_expired( $code, $key, $scanned, $device ) {
		global $wpdb;
		$found = Checkin::lookup( $code, null, false );
		if ( is_wp_error( $found ) || '' === $key ) { return 0; }
		$key_hash = hash( 'sha256', 'app:' . sanitize_text_field( $key ) );
		$user_id = get_current_user_id();
		$checkin_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT checkin_id FROM {$wpdb->prefix}wpcap_checkins WHERE user_id=%d AND idempotency_key=%s AND result='checked_in' ORDER BY checkin_id DESC LIMIT 1", $user_id, $key_hash ) );
		return Conflicts::record( array(
			'dedupe' => 'offline_expired|' . $user_id . '|' . $key_hash,
			'ticket_id' => $found['ticket_id'],
			'event_id' => $found['event_id'],
			'occurrence_id' => $found['occurrence_id'],
			'area_id' => \PassCaptain\Mobile\Devices::area_id( $device ),
			'result' => 'offline_expired',
			'source' => 'offline',
			'device_id' => (int) $device->device_id,
			'user_id' => $user_id,
			'scanned_at' => gmdate( 'Y-m-d H:i:s', $scanned ),
			'checkin_id' => $checkin_id,
		) );
	}
}
