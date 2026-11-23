<?php
/**
 * Per-event opt-in for offline scanning and its conflict rules.
 *
 * Events are NOT offline-capable by default. When enabled:
 *  - max_offline_hours: lifetime of a signed package (a device must refresh within this time) and
 *    the maximum age of an offline scan at upload; older scans are flagged for review.
 *  - duplicate_policy: how a ticket admitted offline on two devices is handled at sync.
 *      review           – the later upload becomes an open admission conflict ("Einlass-Konflikte").
 *      first_sync_wins  – the first uploaded admission counts; later duplicates of the same device or
 *                         user are recorded and resolved automatically as "Doppelscan" (still visible
 *                         in the history); duplicates from another device stay open for review.
 *  - include_attendee: attendee names in packages; only effective while the global setting
 *    scanner_show_attendee is on. Default: token hashes only.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rules {
	const POLICIES = array( 'review', 'first_sync_wins' );
	const MAX_HOURS = 72;

	public static function policy_labels() {
		return array(
			'review' => __( 'Zur Prüfung markieren (offener Einlass-Konflikt)', 'pass-captain' ),
			'first_sync_wins' => __( 'Erste Synchronisierung gewinnt (Doppelscan am selben Gerät automatisch geklärt, geräteübergreifend zur Prüfung)', 'pass-captain' ),
		);
	}

	public static function defaults() {
		return array( 'enabled' => false, 'max_offline_hours' => 12, 'duplicate_policy' => 'review', 'include_attendee' => false );
	}

	/** Rules of an event (defaults when never configured: disabled). */
	public static function get( $event_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_offline_events', absint( $event_id ) ) );
		if ( ! $row ) { return self::defaults(); }
		return array(
			'enabled' => (bool) $row->enabled,
			'max_offline_hours' => max( 1, (int) $row->max_offline_hours ),
			'duplicate_policy' => in_array( $row->duplicate_policy, self::POLICIES, true ) ? $row->duplicate_policy : 'review',
			'include_attendee' => (bool) $row->include_attendee,
		);
	}

	public static function enabled( $event_id ) {
		return self::get( $event_id )['enabled'];
	}

	/** Attendee names are only shipped when the event allows it AND the global scanner setting is on. */
	public static function attendee_names( array $rules ) {
		return $rules['include_attendee'] && (bool) Settings::get( 'scanner_show_attendee' );
	}

	/** Longest package lifetime over all enabled events (key rotation grace fallback). */
	public static function max_hours_enabled() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(max_offline_hours) FROM %i WHERE enabled=1', $wpdb->prefix . 'wpcap_offline_events' ) );
	}

	/**
	 * Stores the rules (caller checks permissions).
	 *
	 * @return array|\WP_Error Saved rules.
	 */
	public static function save( $event_id, array $in ) {
		global $wpdb;
		$hours = (int) ( $in['max_offline_hours'] ?? 12 );
		if ( $hours < 1 || $hours > self::MAX_HOURS ) {
			return new \WP_Error( 'wpcap_offline_hours', sprintf( /* translators: %d: hours */ __( 'Die maximale Offline-Dauer muss zwischen 1 und %d Stunden liegen.', 'pass-captain' ), self::MAX_HOURS ), array( 'status' => 400 ) );
		}
		$policy = (string) ( $in['duplicate_policy'] ?? 'review' );
		if ( ! in_array( $policy, self::POLICIES, true ) ) {
			return new \WP_Error( 'wpcap_offline_policy', __( 'Unbekannte Regel für Doppelscans.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$rules = array( 'enabled' => ! empty( $in['enabled'] ), 'max_offline_hours' => $hours, 'duplicate_policy' => $policy, 'include_attendee' => ! empty( $in['include_attendee'] ) );
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (event_id,enabled,max_offline_hours,duplicate_policy,include_attendee,updated_by,updated_at) VALUES (%d,%d,%d,%s,%d,%d,%s) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),max_offline_hours=VALUES(max_offline_hours),duplicate_policy=VALUES(duplicate_policy),include_attendee=VALUES(include_attendee),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)', $wpdb->prefix . 'wpcap_offline_events',
			absint( $event_id ), $rules['enabled'] ? 1 : 0, $hours, $policy, $rules['include_attendee'] ? 1 : 0, get_current_user_id(), \PassCaptain\Clock::mysql()
		) );
		Audit::record( 'offline_rules_saved', 'event', absint( $event_id ), $rules );
		return $rules;
	}
}
