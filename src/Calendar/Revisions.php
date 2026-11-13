<?php
/**
 * Revision counter per occurrence (A140, schema 3.9.9).
 *
 * Every change of a calendar-relevant field (start, end, doors open, location, venue country,
 * status) increments `wpcap_occurrences.revision` and sets `revised_at`. The iCalendar export
 * uses the counter as SEQUENCE and `revised_at` as LAST-MODIFIED, so calendar clients replace an
 * imported entry (same UID) instead of keeping the outdated one. `notified_revision` remembers the
 * last revision ticket holders were informed about (change mails are sent at most once per revision).
 *
 * Write paths: EventsPage::save_occurrence(), EventsPage::cancel_occurrence(),
 * Orders::cancel_event() and Series\Generator::update().
 */
namespace PassCaptain\Calendar;

use PassCaptain\Admin\ChangeHistory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Revisions {
	/** Fields whose change increments the revision (SEQUENCE). */
	const FIELDS = array( 'starts_at', 'ends_at', 'doors_open_at', 'location', 'venue_country', 'status' );

	/** Fields whose change is announced to ticket holders (cancellations have their own mail). */
	const NOTIFY_FIELDS = array( 'starts_at', 'ends_at', 'doors_open_at', 'location', 'venue_country' );

	/** Whether the 3.9.9 columns exist (revision, revised_at, notified_revision, events.notify_changes). */
	public static function ready() {
		$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
		return false !== $version && '' !== $version && version_compare( $version, '3.9.9', '>=' );
	}

	/**
	 * Relevant changes between an occurrence row and the values written.
	 *
	 * @param object|array $old  Occurrence row before the change.
	 * @param array        $data Written values (only keys present are compared).
	 * @return array field => [old, new]
	 */
	public static function diff( $old, array $data ) {
		$old = (array) $old;
		$before = array();
		$after = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) { continue; }
			$before[ $field ] = self::normalize( $old[ $field ] ?? null );
			$after[ $field ] = self::normalize( $data[ $field ] );
		}
		return ChangeHistory::diff( $before, $after );
	}

	/** Keeps only the revision-relevant entries of a change list (field => [old, new]). */
	public static function relevant( array $changes ) {
		return array_intersect_key( $changes, array_flip( self::FIELDS ) );
	}

	/** Keeps only the entries ticket holders are informed about. */
	public static function notifiable( array $changes ) {
		return array_intersect_key( $changes, array_flip( self::NOTIFY_FIELDS ) );
	}

	/** NULL and '' are the same "not set" value. */
	private static function normalize( $value ) {
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Increments the revision of one occurrence.
	 *
	 * @return int New revision (0 before the 3.9.9 upgrade).
	 */
	public static function bump( $occurrence_id ) {
		$occurrence_id = absint( $occurrence_id );
		if ( ! $occurrence_id || ! self::ready() ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET revision=revision+1,revised_at=%s WHERE occurrence_id=%d", current_time( 'mysql', true ), $occurrence_id ) );
		return self::current( $occurrence_id );
	}

	/** Increments the revision of several occurrences (e.g. all dates of a cancelled event). */
	public static function bump_many( array $occurrence_ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $occurrence_ids ) ) );
		if ( ! $ids || ! self::ready() ) { return; }
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET revision=revision+1,revised_at=%s WHERE occurrence_id IN (" . implode( ',', $ids ) . ')', current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** Current revision of an occurrence (0 when unknown or before the upgrade). */
	public static function current( $occurrence_id ) {
		if ( ! self::ready() ) { return 0; }
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT revision FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
	}

	/**
	 * Claims the notification of a revision: true exactly once per occurrence and revision
	 * (concurrent or repeated calls get false). Without the 3.9.9 columns every call is allowed.
	 */
	public static function claim_notification( $occurrence_id, $revision ) {
		$revision = (int) $revision;
		if ( ! self::ready() || $revision < 1 ) { return true; }
		global $wpdb;
		$p = $wpdb->prefix;
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET notified_revision=%d WHERE occurrence_id=%d AND notified_revision<%d", $revision, absint( $occurrence_id ), $revision ) );
	}

	/** Whether change mails are switched on for an event (per-event opt-out, default on). */
	public static function event_notifies( $event_id ) {
		if ( ! self::ready() ) { return true; }
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT notify_changes FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $event_id ) ) );
		return null === $value || 1 === (int) $value;
	}
}
