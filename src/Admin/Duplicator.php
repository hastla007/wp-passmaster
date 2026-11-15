<?php
/**
 * "Duplizieren" for events and occurrences.
 *
 * Occurrence: same event, new start; end and the ticket types' sales windows move by the same
 * distance; ticket types with participant fields, seller, admission settings, price rules and buyer
 * limits (TicketTypeCopier) and the seat-map link are copied. Event: a draft copy with a new slug;
 * optionally its upcoming, not cancelled occurrences (same dates) with ticket types and their
 * dependents plus the event-wide price rules and buyer limits. Admission areas, seat maps and staff
 * belong to the source event and are not copied into another event. Sold tickets are never copied.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\TicketTypeCopier;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Duplicator {
	/** Upcoming occurrences copied with an event at most. */
	const MAX_OCCURRENCES = 200;

	private static function shift( $datetime, $seconds ) {
		return $datetime ? gmdate( 'Y-m-d H:i:s', strtotime( $datetime . ' UTC' ) + (int) $seconds ) : null;
	}

	/** Copies one occurrence row with its types and dependents (inside the caller's transaction). */
	private static function copy_occurrence( $occ, $event_id, $starts_at, $same_event ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$delta = strtotime( $starts_at . ' UTC' ) - strtotime( $occ->starts_at . ' UTC' );
		$row = (array) $occ;
		unset( $row['occurrence_id'] );
		$row['event_id'] = absint( $event_id );
		$row['starts_at'] = $starts_at;
		$row['ends_at'] = self::shift( $occ->ends_at, $delta );
		$row['status'] = 'scheduled';
		if ( ! $wpdb->insert( $p . 'wpcap_occurrences', $row ) || ! $wpdb->insert_id ) { throw new \RuntimeException( 'occurrence_insert' ); }
		$new_occ = (int) $wpdb->insert_id;
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE occurrence_id=%d AND status<>'archived' ORDER BY ticket_type_id", $p . 'wpcap_ticket_types', (int) $occ->occurrence_id ) );
		$map = array();
		foreach ( $types as $type ) {
			$map[ (int) $type->ticket_type_id ] = TicketTypeCopier::copy_type( $type, $new_occ, array( 'sales_start' => self::shift( $type->sales_start, $delta ), 'sales_end' => self::shift( $type->sales_end, $delta ) ) );
		}
		TicketTypeCopier::copy_dependents( $map, (int) $occ->occurrence_id, $new_occ, (int) $event_id, $same_event );
		return array( $new_occ, $map );
	}

	/**
	 * @param string $starts_at New start (UTC, Y-m-d H:i:s).
	 * @return array|\WP_Error {occurrence_id}
	 */
	public static function duplicate_occurrence( $occurrence_id, $starts_at ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', absint( $occurrence_id ) ) );
		if ( ! $occ || ! Access::can_access_event( (int) $occ->event_id, EventsPage::CAP ) ) { return Forms::forbidden( __( 'Nicht gefunden oder keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE event_id=%d', $p . 'wpcap_events', (int) $occ->event_id ) );
		if ( 'cancelled' === $status ) { return Forms::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'pass-captain' ), 409 ); }
		if ( ! is_string( $starts_at ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $starts_at ) || $starts_at <= current_time( 'mysql', true ) ) {
			return Forms::error( 'wpcap_duplicate_start', __( 'Bitte einen Beginn in der Zukunft angeben.', 'pass-captain' ) );
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE event_id=%d AND starts_at=%s', $p . 'wpcap_occurrences', (int) $occ->event_id, $starts_at ) ) ) {
			return Forms::error( 'wpcap_duplicate_exists', __( 'Zu diesem Beginn gibt es bereits einen Termin.', 'pass-captain' ), 409 );
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			list( $new_occ, $map ) = self::copy_occurrence( $occ, (int) $occ->event_id, $starts_at, true );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_duplicate_failed', __( 'Der Termin konnte nicht dupliziert werden; es wurde nichts gespeichert.', 'pass-captain' ), 500 );
		}
		self::link_seats( (int) $occ->occurrence_id, $new_occ, $map );
		Audit::record( 'occurrence_duplicated', 'occurrence', $new_occ, array( 'event_id' => (int) $occ->event_id, 'source' => (int) $occ->occurrence_id, 'ticket_types' => count( $map ) ) );
		return array( 'occurrence_id' => $new_occ, 'event_id' => (int) $occ->event_id );
	}

	/** Same seat-map version and zones as the source occurrence (Seating module). */
	private static function link_seats( $from_occ, $to_occ, array $map ) {
		if ( ! class_exists( '\\PassCaptain\\Seating\\Layouts' ) || ! TicketTypeCopier::table_exists( \PassCaptain\Seating\Layouts::table( 'links' ) ) ) { return; }
		$link = \PassCaptain\Seating\Layouts::link( $from_occ );
		if ( ! $link ) { return; }
		$zones = array();
		foreach ( \PassCaptain\Seating\Layouts::type_zones( $from_occ ) as $type => $list ) {
			if ( isset( $map[ $type ] ) ) { $zones[ $map[ $type ] ] = $list; }
		}
		\PassCaptain\Seating\Layouts::link_occurrence( $to_occ, (int) $link->version_id, $zones );
	}

	/**
	 * @return array|\WP_Error {event_id, occurrences}
	 */
	public static function duplicate_event( $event_id, $with_occurrences = true ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d', $p . 'wpcap_events', absint( $event_id ) ), ARRAY_A );
		if ( ! $event || ! current_user_can( EventsPage::CAP ) || ! Access::can_access_event( (int) $event['event_id'], EventsPage::CAP ) ) { return Forms::forbidden( __( 'Nicht gefunden oder keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$now = current_time( 'mysql', true );
		$source_id = (int) $event['event_id'];
		unset( $event['event_id'] );
		/* translators: %s: event name */
		$event['name'] = mb_substr( sprintf( __( '%s (Kopie)', 'pass-captain' ), $event['name'] ), 0, 190 );
		$event['slug'] = substr( sanitize_title( $event['name'] ), 0, 180 ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$event['status'] = 'draft';
		$event['created_at'] = $now;
		$event['updated_at'] = $now;
		$occurrences = $with_occurrences ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id=%d AND status<>'cancelled' AND starts_at>%s ORDER BY starts_at LIMIT %d", $p . 'wpcap_occurrences', $source_id, $now, self::MAX_OCCURRENCES ) ) : array();
		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( ! $wpdb->insert( $p . 'wpcap_events', $event ) || ! $wpdb->insert_id ) { throw new \RuntimeException( 'event_insert' ); }
			$new_event = (int) $wpdb->insert_id;
			foreach ( $occurrences as $occ ) { self::copy_occurrence( $occ, $new_event, $occ->starts_at, false ); }
			if ( $with_occurrences ) {
				foreach ( array( 'wpcap_price_rules' => 'rule_id', 'wpcap_buyer_limits' => 'limit_id' ) as $table => $pk ) {
					if ( ! TicketTypeCopier::table_exists( $p . $table ) ) { continue; }
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id=%d AND occurrence_id=0 AND ticket_type_id=0 AND status<>'deleted'", $p . $table, $source_id ), ARRAY_A ) as $row ) {
						unset( $row[ $pk ] );
						$row['event_id'] = $new_event;
						$row['created_at'] = $now;
						$row['updated_at'] = $now;
						if ( ! $wpdb->insert( $p . $table, $row ) ) { throw new \RuntimeException( 'rule_insert' ); }
					}
				}
			}
			if ( ! Queries::is_global_admin() ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id,user_id,capability) VALUES (%d,%d,%s)', $p . 'wpcap_event_staff', $new_event, get_current_user_id(), EventsPage::CAP ) );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_duplicate_failed', __( 'Das Event konnte nicht dupliziert werden; es wurde nichts gespeichert.', 'pass-captain' ), 500 );
		}
		Audit::record( 'event_duplicated', 'event', $new_event, array( 'source' => $source_id, 'occurrences' => count( $occurrences ) ) );
		return array( 'event_id' => $new_event, 'occurrences' => count( $occurrences ) );
	}
}
