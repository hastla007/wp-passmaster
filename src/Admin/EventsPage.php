<?php
/**
 * Events admin: event list, event editing, occurrences, ticket types, participant fields, staff.
 *
 * Every data-changing action is a public static "process" method that takes an unslashed input
 * array and returns a result array or WP_Error (capability and event scope are checked inside).
 * The handle_* methods are the thin admin-post wrappers (nonce check, redirect).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Calendar\Revisions;
use PassCaptain\Localization;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EventsPage {
	const SLUG = 'wp-pass-captain';
	const CAP = 'wpcap_manage_events';
	const FIELD_TYPES = array( 'text', 'email', 'textarea', 'checkbox' );
	const STAFF_CAPS = array( 'wpcap_checkin', 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_view_reports' );

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/** Creates an event with one or more weekly occurrences and one ticket type per occurrence. */
	public static function create_event( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$name = Forms::text( $in, 'name' );
		$timezone = Forms::text( $in, 'timezone', 64 );
		$ticket_name = Forms::text( $in, 'ticket_name' );
		$currency = strtoupper( Forms::text( $in, 'currency', 3 ) ?: 'EUR' );
		if ( '' === $name || '' === $ticket_name || ! in_array( $currency, Localization::currencies(), true ) || ! Forms::is_valid_timezone( $timezone ) ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Bitte prüfe die Eventdaten.', 'wp-pass-captain' ) );
		}
		$dst_notes = array(); // A102: DST gap → rejected, ambiguous time → first instance with a note.
		$start_utc = Forms::local_to_utc_checked( $in['starts_at'] ?? '', $timezone, $dst_notes );
		if ( is_wp_error( $start_utc ) ) { return $start_utc; }
		if ( null === $start_utc ) {
			return Forms::error( 'wpcap_event_start', __( 'Beginn oder Ende des Events ist ungültig.', 'wp-pass-captain' ) );
		}
		$duration = 0;
		if ( '' !== trim( (string) ( $in['ends_at'] ?? '' ) ) ) {
			$end_utc = Forms::local_to_utc_checked( $in['ends_at'], $timezone, $dst_notes );
			if ( is_wp_error( $end_utc ) ) { return $end_utc; }
			if ( null === $end_utc || $end_utc <= $start_utc ) {
				return Forms::error( 'wpcap_event_end', __( 'Beginn oder Ende des Events ist ungültig.', 'wp-pass-captain' ) );
			}
			$duration = strtotime( $end_utc . ' UTC' ) - strtotime( $start_utc . ' UTC' );
		}
		$price = Forms::parse_money( $in['price'] ?? '0', Forms::currency_decimals( $currency ) );
		if ( null === $price ) { return Forms::error( 'wpcap_event_price', __( 'Der Preis ist ungültig.', 'wp-pass-captain' ) ); }
		$tax_bp = Forms::parse_percent_bp( $in['tax_rate'] ?? '' );
		if ( null === $tax_bp ) { return Forms::error( 'wpcap_event_tax', __( 'Der Steuersatz ist ungültig.', 'wp-pass-captain' ) ); }
		$status = Forms::choice( $in, 'status', array( 'draft', 'published' ), 'draft' );
		$repeat = Forms::choice( $in, 'repeat_frequency', array( 'none', 'weekly' ), 'none' );
		$repeat_count = 'weekly' === $repeat ? min( 12, max( 1, Forms::int( $in, 'repeat_count', 1 ) ) ) : 1;
		$now = current_time( 'mysql', true );
		$slug = substr( sanitize_title( $name ), 0, 180 ) . '-' . strtolower( wp_generate_password( 6, false, false ) );

		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( "{$p}wpcap_events", array(
			'name' => $name,
			'slug' => $slug,
			'description' => wp_kses_post( (string) ( $in['description'] ?? '' ) ),
			'status' => $status,
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$event_id = (int) $wpdb->insert_id;
		if ( ! $event_id ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_event_save', __( 'Das Event konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 );
		}
		$local_start = new \DateTimeImmutable( $start_utc, new \DateTimeZone( 'UTC' ) );
		$local_start = $local_start->setTimezone( new \DateTimeZone( $timezone ) );
		for ( $index = 0; $index < $repeat_count; $index++ ) {
			// Weekly repetition keeps the local wall-clock time across DST changes.
			$occ_start = Forms::weekly_repeat_start( $local_start, $index, $timezone, $dst_notes );
			$occ_end = $duration ? $occ_start->modify( '+' . $duration . ' seconds' ) : null;
			$wpdb->insert( "{$p}wpcap_occurrences", array(
				'event_id' => $event_id,
				'starts_at' => $occ_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'ends_at' => $occ_end ? $occ_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
				'timezone' => $timezone,
				'location' => Forms::text( $in, 'location' ),
				'capacity' => Forms::int( $in, 'event_capacity' ),
				'status' => 'scheduled',
			), array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) );
			$occurrence_id = (int) $wpdb->insert_id;
			if ( ! $occurrence_id ) {
				$wpdb->query( 'ROLLBACK' );
				return Forms::error( 'wpcap_occurrence_save', __( 'Ein Termin konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 );
			}
			$wpdb->insert( "{$p}wpcap_ticket_types", array(
				'occurrence_id' => $occurrence_id,
				'name' => $ticket_name,
				'price_minor' => $price,
				'currency' => $currency,
				'capacity' => Forms::int( $in, 'ticket_capacity' ),
				'max_per_order' => 10,
				'tax_rate_bp' => $tax_bp,
				'status' => 'active',
			), array( '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ) );
			if ( ! $wpdb->insert_id ) {
				$wpdb->query( 'ROLLBACK' );
				return Forms::error( 'wpcap_type_save', __( 'Die Ticketkategorie konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 );
			}
		}
		if ( ! Queries::is_global_admin() ) {
			// Creators without global rights keep access to their event through the event scope.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES (%d,%d,%s)", $event_id, get_current_user_id(), self::CAP ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'event_created', 'event', $event_id, array( 'status' => $status, 'occurrences' => $repeat_count ) );
		return array( 'event_id' => $event_id ) + Forms::dst_warning( $dst_notes );
	}

	/** Updates name, description and status (draft/published) of an event. */
	public static function update_event( array $in ) {
		$event = Queries::event( Forms::int( $in, 'event_id' ) );
		if ( ! $event ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		if ( 'cancelled' === $event->status ) {
			return Forms::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'wp-pass-captain' ), 409 );
		}
		$name = Forms::text( $in, 'name' );
		$status = $in['status'] ?? $event->status;
		if ( '' === $name || ! in_array( $status, array( 'draft', 'published' ), true ) ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Bitte prüfe die Eventdaten.', 'wp-pass-captain' ) );
		}
		global $wpdb;
		$data = array(
			'name' => $name,
			'description' => wp_kses_post( (string) ( $in['description'] ?? '' ) ),
			'status' => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s', '%s' );
		$extra = array();
		// A140: per-event opt-out of change mails; only touched when the form showed the checkbox.
		if ( ! empty( $in['notify_changes_shown'] ) && Revisions::ready() ) {
			$data['notify_changes'] = Forms::flag( $in, 'notify_changes' ) ? 1 : 0;
			$formats[] = '%d';
			if ( (int) ( $event->notify_changes ?? 1 ) !== $data['notify_changes'] ) { $extra['notify_changes'] = $data['notify_changes']; }
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_events', $data, array( 'event_id' => (int) $event->event_id ), $formats, array( '%d' ) );
		Audit::record( 'event_updated', 'event', (int) $event->event_id, array( 'status' => $status, 'previous_status' => $event->status ) + $extra );
		return array( 'event_id' => (int) $event->event_id );
	}

	/**
	 * Creates or updates an occurrence. Fires `wpcap_occurrence_rescheduled` when the start of an
	 * occurrence with issued tickets changes, `wpcap_occurrence_changed` when a calendar-relevant
	 * field changes (revision + 1, A140) and `wpcap_occurrence_cancelled` on cancellation.
	 */
	public static function save_occurrence( array $in ) {
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		$old = $occurrence_id ? Queries::occurrence( $occurrence_id ) : null;
		if ( $occurrence_id && ! $old ) { return Forms::error( 'wpcap_occurrence_missing', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$event = Queries::event( $old ? $old->event_id : Forms::int( $in, 'event_id' ) );
		if ( ! $event ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event->event_id, self::CAP ) || ( $old && ! Access::occurrence_in_scope( $old->occurrence_id ) ) || ! Access::location_in_scope( Forms::text( $in, 'location' ) ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		if ( 'cancelled' === $event->status ) {
			return Forms::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'wp-pass-captain' ), 409 );
		}
		$timezone = Forms::text( $in, 'timezone', 64 );
		if ( ! Forms::is_valid_timezone( $timezone ) ) { return Forms::error( 'wpcap_timezone', __( 'Die Zeitzone ist ungültig.', 'wp-pass-captain' ) ); }
		$dst_notes = array(); // A102: DST gap → rejected, ambiguous time → first instance with a note.
		$starts = Forms::local_to_utc_checked( $in['starts_at'] ?? '', $timezone, $dst_notes );
		if ( is_wp_error( $starts ) ) { return $starts; }
		if ( null === $starts ) { return Forms::error( 'wpcap_occurrence_start', __( 'Der Beginn ist ungültig.', 'wp-pass-captain' ) ); }
		$ends = null;
		if ( '' !== trim( (string) ( $in['ends_at'] ?? '' ) ) ) {
			$ends = Forms::local_to_utc_checked( $in['ends_at'], $timezone, $dst_notes );
			if ( is_wp_error( $ends ) ) { return $ends; }
			if ( null === $ends || $ends <= $starts ) { return Forms::error( 'wpcap_occurrence_end', __( 'Das Ende muss nach dem Beginn liegen.', 'wp-pass-captain' ) ); }
		}
		$doors = \PassCaptain\DoorsOpen::parse( $in['doors_open_at'] ?? '', $timezone, $starts );
		if ( is_wp_error( $doors ) ) { return Forms::error( $doors->get_error_code(), $doors->get_error_message() ); }
		$capacity = Forms::int( $in, 'capacity' );
		$status = Forms::choice( $in, 'status', array( 'scheduled', 'cancelled' ), $old ? $old->status : 'scheduled' );
		if ( $old && $capacity > 0 ) {
			$sold = Queries::sold_for_occurrence( $old->occurrence_id );
			if ( $capacity < $sold ) {
				/* translators: %d: sold tickets */
				return Forms::error( 'wpcap_capacity_below_sold', sprintf( _n( 'Das Kontingent darf nicht unter das bereits verkaufte %d Ticket sinken.', 'Das Kontingent darf nicht unter die bereits verkauften %d Tickets sinken.', $sold, 'wp-pass-captain' ), $sold) );
			}
		}
		$data = array(
			'starts_at' => $starts,
			'ends_at' => $ends,
			'timezone' => $timezone,
			'location' => Forms::text( $in, 'location' ),
			'capacity' => $capacity,
			'status' => $status,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s' );
		if ( \PassCaptain\DoorsOpen::ready() ) {
			$data['doors_open_at'] = $doors;
			$formats[] = '%s';
		}
		if ( array_key_exists( 'venue_country', $in ) && \PassCaptain\Tax\Profiles::ready() ) {
			// Place of supply for admission (A115); empty = seller country from the settings.
			$venue_country = trim( Forms::text( $in, 'venue_country', 10 ) );
			if ( '' !== $venue_country && '' === \PassCaptain\Tax\Profiles::country( $venue_country ) ) {
				return Forms::error( 'wpcap_occurrence_country', __( 'Das Veranstaltungsland muss ein ISO-Ländercode sein, z. B. DE oder AT.', 'wp-pass-captain' ) );
			}
			$data['venue_country'] = \PassCaptain\Tax\Profiles::country( $venue_country );
			$formats[] = '%s';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_occurrences';
		$rescheduled = false;
		if ( $old ) {
			$wpdb->update( $table, $data, array( 'occurrence_id' => (int) $old->occurrence_id ), $formats, array( '%d' ) );
			$occurrence_id = (int) $old->occurrence_id;
			$issued = Queries::issued_ticket_count_for_occurrence( $occurrence_id );
			// A140: calendar-relevant changes raise the revision (iCalendar SEQUENCE); one mail per save.
			$revision_changes = Revisions::diff( $old, $data );
			$revision = $revision_changes ? Revisions::bump( $occurrence_id ) : (int) ( $old->revision ?? 0 );
			$rescheduled = $old->starts_at !== $starts && $issued > 0;
			\PassCaptain\Notifications::after_save( $occurrence_id, $revision_changes, $revision, $rescheduled );
			// A114: field-by-field diff (old → new) for the occurrence's change history.
			$changes = ChangeHistory::diff( (array) $old, $data );
			Audit::record( 'occurrence_updated', 'occurrence', $occurrence_id, array( 'event_id' => (int) $event->event_id, 'old_starts_at' => $old->starts_at, 'new_starts_at' => $starts, 'status' => $status, 'rescheduled' => $rescheduled, 'changes' => $changes ) + ( isset( $data['venue_country'] ) && (string) ( $old->venue_country ?? '' ) !== $data['venue_country'] ? array( 'old_venue_country' => (string) ( $old->venue_country ?? '' ), 'venue_country' => $data['venue_country'] ) : array() ) );
			if ( 'cancelled' === $status && 'cancelled' !== $old->status ) {
				do_action( 'wpcap_occurrence_cancelled', $occurrence_id, $issued );
			} elseif ( self::capacity_grew( (int) $old->capacity, (int) $capacity ) ) {
				// More places: waitlist offers go out now instead of on the next hourly sweep.
				do_action( 'wpcap_capacity_increased', 0, $occurrence_id );
			}
		} else {
			$data['event_id'] = (int) $event->event_id;
			$formats[] = '%d';
			$wpdb->insert( $table, $data, $formats );
			$occurrence_id = (int) $wpdb->insert_id;
			if ( ! $occurrence_id ) { return Forms::error( 'wpcap_occurrence_save', __( 'Der Termin konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
			Audit::record( 'occurrence_created', 'occurrence', $occurrence_id, array( 'event_id' => (int) $event->event_id, 'starts_at' => $starts ) );
		}
		return array( 'occurrence_id' => $occurrence_id, 'event_id' => (int) $event->event_id, 'rescheduled' => $rescheduled ) + Forms::dst_warning( $dst_notes );
	}

	/** Whether a capacity change frees places (0 = unlimited). */
	private static function capacity_grew( $old, $new ) {
		return $old > 0 && ( 0 === $new || $new > $old );
	}

	/** Cancels a single occurrence (tickets stay; refunds are handled per order). */
	/**
	 * What cancelling an occurrence would do (shown before the irreversible action).
	 *
	 * @return array tickets (valid, anchored here), paid_orders, woo_orders, pending_orders, recipients,
	 *               auto_refund (bool), passes (list of name, tickets, effect).
	 */
	public static function cancellation_preview( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$base = "FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE tt.occurrence_id=%d AND t.status='valid'";
		$out = array(
			'tickets' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base", $occurrence_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragment.
			'paid_orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ord.order_id) $base AND ord.payment_status='paid' AND ord.payment_provider<>'woocommerce'", $occurrence_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'woo_orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ord.order_id) $base AND ord.payment_status='paid' AND ord.payment_provider='woocommerce'", $occurrence_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'recipients' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email)) $base", $occurrence_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'pending_orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ord.order_id) FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='pending'", $occurrence_id ) ),
			'auto_refund' => (bool) \PassCaptain\Settings::get( 'auto_refund_cancelled' ),
			'passes' => array(),
		);
		/** Further consequences of modules (e.g. passes linked to the occurrence): list of [name, tickets, effect]. */
		$out['passes'] = (array) apply_filters( 'wpcap_occurrence_cancellation_preview', array(), $occurrence_id );
		return $out;
	}

	public static function cancel_occurrence( array $in ) {
		$occ = Queries::occurrence( Forms::int( $in, 'occurrence_id' ) );
		if ( ! $occ ) { return Forms::error( 'wpcap_occurrence_missing', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $occ->occurrence_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		if ( 'cancelled' === $occ->status ) {
			return array( 'occurrence_id' => (int) $occ->occurrence_id, 'event_id' => (int) $occ->event_id, 'issued' => 0 );
		}
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_cancel_reason', __( 'Bitte einen Grund für die Absage angeben.', 'wp-pass-captain' ) ); }
		if ( ! Forms::flag( $in, 'confirm' ) ) { return Forms::error( 'wpcap_cancel_confirm', __( 'Bitte bestätigen, dass die Folgen der Absage geprüft wurden.', 'wp-pass-captain' ) ); }
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_occurrences', array( 'status' => 'cancelled' ), array( 'occurrence_id' => (int) $occ->occurrence_id ), array( '%s' ), array( '%d' ) );
		Revisions::bump( (int) $occ->occurrence_id );
		$issued = Queries::issued_ticket_count_for_occurrence( $occ->occurrence_id );
		Audit::record( 'occurrence_cancelled', 'occurrence', (int) $occ->occurrence_id, array( 'event_id' => (int) $occ->event_id, 'issued_tickets' => $issued, 'reason' => $reason, 'changes' => array( 'status' => array( $occ->status, 'cancelled' ) ) ) );
		do_action( 'wpcap_occurrence_cancelled', (int) $occ->occurrence_id, $issued );
		$result = array( 'occurrence_id' => (int) $occ->occurrence_id, 'event_id' => (int) $occ->event_id, 'issued' => $issued );
		// Summary of what happened (core + passes), shown after the redirect.
		$core = \PassCaptain\OccurrenceCancellation::last_summary( (int) $occ->occurrence_id );
		$pass = class_exists( \PassCaptain\Passes\Cancellation::class ) ? \PassCaptain\Passes\Cancellation::last_summary( (int) $occ->occurrence_id ) : array();
		$result['summary'] = array(
			'cancelled_orders' => (int) ( $core['cancelled_orders'] ?? 0 ),
			'revoked' => (int) ( $core['revoked'] ?? 0 ) + (int) ( $pass['revoked'] ?? 0 ),
			'refunds' => (int) ( $core['refunds'] ?? 0 ) + (int) ( $pass['day_refunds'] ?? 0 ),
			'refund_errors' => (int) ( $core['refund_errors'] ?? 0 ) + (int) ( $pass['refund_errors'] ?? 0 ) + (int) ( $pass['refunds_open'] ?? 0 ),
			'notified' => (int) ( $core['notified'] ?? 0 ) + (int) ( $pass['notified'] ?? 0 ),
		);
		if ( $issued ) {
			/* translators: %d: number of tickets */
			$result['warning'] = \PassCaptain\Settings::get( 'auto_refund_cancelled' )
				/* translators: %d: number of tickets */
				? sprintf( _n( '%d Ticket wurde widerrufen, der Inhaber benachrichtigt und die bezahlte Bestellung automatisch erstattet. WooCommerce-Bestellungen bitte in WooCommerce erstatten; Fehler stehen im Erstattungs-Menü.', '%d Tickets wurden widerrufen, die Inhaber benachrichtigt und bezahlte Bestellungen automatisch erstattet. WooCommerce-Bestellungen bitte in WooCommerce erstatten; Fehler stehen im Erstattungs-Menü.', $issued, 'wp-pass-captain' ), $issued)
				/* translators: %d: number of tickets */
				: sprintf( _n( '%d Ticket wurde widerrufen und der Inhaber benachrichtigt. Ein bezahltes Ticket bleibt erstattungsfähig: bitte über Bestellung → Erstatten erstatten (oder die automatische Erstattung in den Einstellungen aktivieren).', '%d Tickets wurden widerrufen und die Inhaber benachrichtigt. Bezahlte Tickets bleiben erstattungsfähig: bitte über Bestellung → Erstatten erstatten (oder die automatische Erstattung in den Einstellungen aktivieren).', $issued, 'wp-pass-captain' ), $issued);
		}
		return $result;
	}

	/** Creates or updates a ticket type of an occurrence. */
	public static function save_ticket_type( array $in ) {
		$type_id = Forms::int( $in, 'ticket_type_id' );
		$old = $type_id ? Queries::ticket_type( $type_id ) : null;
		if ( $type_id && ! $old ) { return Forms::error( 'wpcap_type_missing', __( 'Ticketart nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$occ = Queries::occurrence( $old ? $old->occurrence_id : Forms::int( $in, 'occurrence_id' ) );
		if ( ! $occ ) { return Forms::error( 'wpcap_occurrence_missing', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $occ->occurrence_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		$name = Forms::text( $in, 'name' );
		if ( '' === $name ) { return Forms::error( 'wpcap_type_name', __( 'Bitte einen Namen für die Ticketart angeben.', 'wp-pass-captain' ) ); }
		$currency = strtoupper( Forms::text( $in, 'currency', 3 ) );
		if ( ! in_array( $currency, Localization::currencies(), true ) ) { return Forms::error( 'wpcap_type_currency', __( 'Die Währung ist ungültig.', 'wp-pass-captain' ) ); }
		$price = Forms::parse_money( $in['price'] ?? '', Forms::currency_decimals( $currency ) );
		if ( null === $price ) { return Forms::error( 'wpcap_type_price', __( 'Der Preis ist ungültig. Beispiel: 12,50', 'wp-pass-captain' ) ); }
		$tax_bp = Forms::parse_percent_bp( $in['tax_rate'] ?? '' );
		if ( null === $tax_bp ) { return Forms::error( 'wpcap_type_tax', __( 'Der Steuersatz ist ungültig. Beispiel: 19 oder 7,7', 'wp-pass-captain' ) ); }
		$max = Forms::int( $in, 'max_per_order', 10 );
		if ( $max < 1 || $max > 100 ) { return Forms::error( 'wpcap_type_max', __( 'Die maximale Anzahl pro Bestellung muss zwischen 1 und 100 liegen.', 'wp-pass-captain' ) ); }
		$sales = array();
		foreach ( array( 'sales_start', 'sales_end' ) as $key ) {
			$raw = trim( (string) ( $in[ $key ] ?? '' ) );
			$sales[ $key ] = '' === $raw ? null : Forms::local_to_utc( $raw, $occ->timezone );
			if ( '' !== $raw && null === $sales[ $key ] ) { return Forms::error( 'wpcap_type_sales', __( 'Der Verkaufszeitraum ist ungültig.', 'wp-pass-captain' ) ); }
		}
		if ( $sales['sales_start'] && $sales['sales_end'] && $sales['sales_end'] <= $sales['sales_start'] ) {
			return Forms::error( 'wpcap_type_sales', __( 'Das Verkaufsende muss nach dem Verkaufsstart liegen.', 'wp-pass-captain' ) );
		}
		$checks = \PassCaptain\AdmissionChecks::parse( $in );
		if ( is_wp_error( $checks ) ) { return Forms::error( $checks->get_error_code(), $checks->get_error_message() ); }
		$capacity = Forms::int( $in, 'capacity' );
		$status = Forms::choice( $in, 'status', array( 'active', 'paused' ), 'active' );
		if ( $old ) {
			if ( $currency !== $old->currency && Queries::type_has_orders( $old->ticket_type_id ) ) {
				return Forms::error( 'wpcap_type_currency_locked', __( 'Die Währung kann nach den ersten Bestellungen nicht mehr geändert werden.', 'wp-pass-captain' ), 409 );
			}
			$sold = Queries::sold_for_type( $old->ticket_type_id );
			if ( $capacity > 0 && $capacity < $sold ) {
				/* translators: %d: sold tickets */
				return Forms::error( 'wpcap_capacity_below_sold', sprintf( _n( 'Das Kontingent darf nicht unter das bereits verkaufte %d Ticket sinken.', 'Das Kontingent darf nicht unter die bereits verkauften %d Tickets sinken.', $sold, 'wp-pass-captain' ), $sold) );
			}
		}
		$data = array(
			'name' => $name,
			'price_minor' => $price,
			'currency' => $currency,
			'tax_rate_bp' => $tax_bp,
			'capacity' => $capacity,
			'max_per_order' => $max,
			'sales_start' => $sales['sales_start'],
			'sales_end' => $sales['sales_end'],
			'status' => $status,
		);
		$formats = array( '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );
		if ( array_key_exists( 'tax_class', $in ) ) {
			// Tax class (A115): with an active country tax profile for it, sales use the profile's rate.
			$data['tax_class'] = substr( sanitize_key( (string) $in['tax_class'] ), 0, 64 );
			$formats[] = '%s';
		}
		if ( \PassCaptain\AdmissionChecks::ready() ) {
			$data += $checks;
			array_push( $formats, '%d', '%d', '%s' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_ticket_types';
		if ( $old ) {
			$wpdb->update( $table, $data, array( 'ticket_type_id' => (int) $old->ticket_type_id ), $formats, array( '%d' ) );
			$type_id = (int) $old->ticket_type_id;
			if ( 'active' === $status && ( self::capacity_grew( (int) $old->capacity, (int) $capacity ) || 'active' !== (string) $old->status ) ) {
				do_action( 'wpcap_capacity_increased', $type_id, 0 ); // Waitlist offers right away.
			}
			if ( isset( $old->name ) && (string) $old->name !== (string) $data['name'] ) {
				\PassCaptain\Integrations\Vouchers\TicketService::remember_rename( (int) $occ->event_id, (string) $old->name, (string) $data['name'] );
			}
		} else {
			$data['occurrence_id'] = (int) $occ->occurrence_id;
			$formats[] = '%d';
			$wpdb->insert( $table, $data, $formats );
			$type_id = (int) $wpdb->insert_id;
			if ( ! $type_id ) { return Forms::error( 'wpcap_type_save', __( 'Die Ticketkategorie konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		}
		$details = array( 'occurrence_id' => (int) $occ->occurrence_id, 'price_minor' => $price, 'tax_rate_bp' => $tax_bp, 'status' => $status, 'currency' => $currency, 'type_name' => $name, 'tax_class' => (string) ( $data['tax_class'] ?? ( $old->tax_class ?? '' ) ) );
		if ( $old ) {
			// A114: price, capacity and sales window changes (old → new) for the occurrence's history.
			$details['changes'] = ChangeHistory::diff( (array) $old, array_intersect_key( $data, array_flip( array( 'name', 'price_minor', 'currency', 'tax_rate_bp', 'tax_class', 'capacity', 'max_per_order', 'sales_start', 'sales_end', 'status' ) ) ) );
			if ( isset( $details['changes']['currency'] ) ) { $details['currency'] = (string) $old->currency; }
		}
		Audit::record( $old ? 'ticket_type_updated' : 'ticket_type_created', 'ticket_type', $type_id, $details );
		return array( 'ticket_type_id' => $type_id, 'occurrence_id' => (int) $occ->occurrence_id, 'event_id' => (int) $occ->event_id );
	}

	/** Creates or updates a participant field. The key is fixed after creation. */
	public static function save_participant_field( array $in ) {
		$field_id = Forms::int( $in, 'field_id' );
		$old = $field_id ? Queries::participant_field( $field_id ) : null;
		if ( $field_id && ! $old ) { return Forms::error( 'wpcap_field_missing', __( 'Feld nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$type = Queries::ticket_type( $old ? $old->ticket_type_id : Forms::int( $in, 'ticket_type_id' ) );
		if ( ! $type ) { return Forms::error( 'wpcap_type_missing', __( 'Ticketart nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $type->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		$label = Forms::text( $in, 'label' );
		$field_type = Forms::choice( $in, 'field_type', self::FIELD_TYPES, '' );
		if ( '' === $label || '' === $field_type ) { return Forms::error( 'wpcap_field_invalid', __( 'Bitte Bezeichnung und Feldtyp angeben.', 'wp-pass-captain' ) ); }
		$retention = Forms::int( $in, 'retention_days', 365 );
		if ( $retention > 3650 ) { return Forms::error( 'wpcap_field_retention', __( 'Die Aufbewahrungsdauer darf höchstens 3650 Tage betragen.', 'wp-pass-captain' ) ); }
		// Consent (A116): a checkbox with a stated purpose; grants and withdrawals are logged.
		$consent = \PassCaptain\Consents::ready() && Forms::flag( $in, 'consent' );
		if ( $consent && 'checkbox' !== $field_type ) { return Forms::error( 'wpcap_field_consent_type', __( 'Eine Einwilligung muss den Typ Checkbox haben.', 'wp-pass-captain' ) ); }
		if ( $consent && '' === Forms::text( $in, 'purpose' ) ) { return Forms::error( 'wpcap_field_consent_purpose', __( 'Bitte gib für die Einwilligung den Zweck an. Er wird beim Ankreuzen angezeigt und mit protokolliert.', 'wp-pass-captain' ) ); }
		$data = array(
			'label' => $label,
			'field_type' => $field_type,
			'required' => Forms::flag( $in, 'required' ) ? 1 : 0,
			'purpose' => Forms::text( $in, 'purpose' ),
			'retention_days' => $retention,
			'position' => min( 65535, Forms::int( $in, 'position' ) ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%d', '%d' );
		if ( \PassCaptain\Consents::ready() ) {
			$data['consent'] = $consent ? 1 : 0;
			$formats[] = '%d';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_participant_fields';
		if ( $old ) {
			$wpdb->update( $table, $data, array( 'field_id' => (int) $old->field_id ), $formats, array( '%d' ) );
			$field_id = (int) $old->field_id;
		} else {
			$key = substr( sanitize_key( (string) ( $in['field_key'] ?? '' ) ), 0, 64 );
			$key = str_replace( '-', '_', $key );
			if ( '' === $key ) { return Forms::error( 'wpcap_field_key', __( 'Bitte einen technischen Schlüssel angeben (a-z, 0-9, _).', 'wp-pass-captain' ) ); }
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT field_id FROM $table WHERE ticket_type_id=%d AND field_key=%s", (int) $type->ticket_type_id, $key ) );
			if ( $exists ) { return Forms::error( 'wpcap_field_key_taken', __( 'Dieser Schlüssel ist für die Ticketart bereits vergeben.', 'wp-pass-captain' ), 409 ); }
			$data['ticket_type_id'] = (int) $type->ticket_type_id;
			$data['field_key'] = $key;
			$data['status'] = 'active';
			$wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s', '%s' ) ) );
			$field_id = (int) $wpdb->insert_id;
			if ( ! $field_id ) { return Forms::error( 'wpcap_field_save', __( 'Das Feld konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		}
		$details = array( 'field_id' => $field_id, 'required' => $data['required'], 'retention_days' => $retention );
		if ( $consent ) {
			// A changed consent text is a new version; records name the version that was shown.
			$details['consent_text_version'] = \PassCaptain\Consents::ensure_text( (object) array( 'field_id' => $field_id, 'label' => $label, 'purpose' => $data['purpose'] ) )['text_version'];
		}
		Audit::record( $old ? 'participant_field_updated' : 'participant_field_created', 'ticket_type', (int) $type->ticket_type_id, $details );
		return array( 'field_id' => $field_id, 'ticket_type_id' => (int) $type->ticket_type_id );
	}

	/** Activates or deactivates a participant field (answers are kept). */
	public static function set_participant_field_status( array $in ) {
		$field = Queries::participant_field( Forms::int( $in, 'field_id' ) );
		if ( ! $field ) { return Forms::error( 'wpcap_field_missing', __( 'Feld nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $field->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		$status = Forms::choice( $in, 'status', array( 'active', 'inactive' ), 'inactive' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_participant_fields', array( 'status' => $status ), array( 'field_id' => (int) $field->field_id ), array( '%s' ), array( '%d' ) );
		Audit::record( 'participant_field_status', 'ticket_type', (int) $field->ticket_type_id, array( 'field_id' => (int) $field->field_id, 'status' => $status ) );
		return array( 'field_id' => (int) $field->field_id, 'ticket_type_id' => (int) $field->ticket_type_id );
	}

	/** Resolves a user by id, login or e-mail. */
	private static function find_user( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) { return null; }
		if ( ctype_digit( $ref ) ) { return get_user_by( 'id', absint( $ref ) ) ?: null; }
		if ( is_email( $ref ) ) { return get_user_by( 'email', $ref ) ?: null; }
		return get_user_by( 'login', sanitize_text_field( $ref ) ) ?: null;
	}

	/** Assigns event-scoped access to a user. */
	public static function assign_staff( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$event_id = Forms::int( $in, 'event_id' );
		$capability = Forms::choice( $in, 'capability', self::STAFF_CAPS, '' );
		$user = self::find_user( $in['user'] ?? ( $in['user_id'] ?? '' ) );
		if ( ! $event_id || ! $user || '' === $capability || ! Queries::event( $event_id ) ) {
			return Forms::error( 'wpcap_staff_invalid', __( 'Zuweisung ungültig.', 'wp-pass-captain' ) );
		}
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		if ( 'wpcap_checkin' === $capability ) {
			$user->add_cap( 'wpcap_checkin' );
		} elseif ( ! user_can( $user, $capability ) ) {
			// Event scope never grants a role capability implicitly; the user needs a matching role.
			return Forms::error( 'wpcap_staff_role', __( 'Der Benutzer hat dieses Recht nicht über seine Rolle (z. B. Pass Captain Veranstaltungsleitung). Bitte zuerst die Rolle zuweisen.', 'wp-pass-captain' ) );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_event_staff (event_id,user_id,capability) VALUES (%d,%d,%s)", $event_id, (int) $user->ID, $capability ) );
		Audit::record( 'staff_assigned', 'event', $event_id, array( 'user_id' => (int) $user->ID, 'capability' => $capability ) );
		return array( 'event_id' => $event_id, 'user_id' => (int) $user->ID );
	}

	/**
	 * assign_staff() grants wpcap_checkin as a user capability; take it back once the user has no
	 * check-in assignment left. A capability granted by one of the user's roles is left alone.
	 */
	private static function maybe_revoke_checkin_cap( $user_id ) {
		global $wpdb;
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_event_staff WHERE user_id=%d AND capability='wpcap_checkin'", $user_id ) );
		if ( $remaining ) { return; }
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! method_exists( $user, 'remove_cap' ) ) { return; }
		foreach ( (array) ( $user->roles ?? array() ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( 'wpcap_checkin' ) ) { return; }
		}
		if ( empty( $user->caps['wpcap_checkin'] ) ) { return; }
		$user->remove_cap( 'wpcap_checkin' );
	}

	/** Removes an event-scoped access entry. */
	public static function remove_staff( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		$user_id = Forms::int( $in, 'user_id' );
		$capability = Forms::choice( $in, 'capability', self::STAFF_CAPS, '' );
		if ( ! $event_id || ! $user_id || '' === $capability ) { return Forms::error( 'wpcap_staff_invalid', __( 'Zuweisung ungültig.', 'wp-pass-captain' ) ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wpcap_event_staff', array( 'event_id' => $event_id, 'user_id' => $user_id, 'capability' => $capability ), array( '%d', '%d', '%s' ) );
		if ( 'wpcap_checkin' === $capability ) { self::maybe_revoke_checkin_cap( $user_id ); }
		Audit::record( 'staff_removed', 'event', $event_id, array( 'user_id' => $user_id, 'capability' => $capability ) );
		return array( 'event_id' => $event_id );
	}

	/** Cancels a published event (revokes tickets, notifies buyers). */
	public static function cancel_event( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		$result = Orders::cancel_event( $event_id );
		return is_wp_error( $result ) ? Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ) : $result;
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	private static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	private static function event_url( $event_id ) {
		return self::url( array( 'view' => 'event', 'event_id' => absint( $event_id ) ) );
	}

	public static function handle_create_event() {
		Forms::verify( 'wpcap_create_event' );
		$result = self::create_event( Forms::post() );
		Forms::finish( $result, __( 'Event gespeichert.', 'wp-pass-captain' ), is_wp_error( $result ) ? self::url() : self::event_url( $result['event_id'] ) );
	}

	public static function handle_update_event() {
		$in = Forms::post();
		Forms::verify( 'wpcap_update_event' );
		Forms::finish( self::update_event( $in ), __( 'Event aktualisiert.', 'wp-pass-captain' ), self::event_url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_save_occurrence() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_occurrence' );
		$result = self::save_occurrence( $in );
		$message = __( 'Termin gespeichert.', 'wp-pass-captain' );
		if ( ! is_wp_error( $result ) && $result['rescheduled'] ) {
			$message .= ' ' . __( 'Der Termin hat verkaufte Tickets; die Verschiebung wurde gemeldet.', 'wp-pass-captain' );
		}
		$back = Forms::int( $in, 'occurrence_id' ) ? self::url( array( 'view' => 'occurrence', 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) ) : self::event_url( Forms::int( $in, 'event_id' ) );
		Forms::finish( $result, $message, is_wp_error( $result ) ? $back : self::url( array( 'view' => 'occurrence', 'occurrence_id' => $result['occurrence_id'] ) ), $back );
	}

	public static function handle_cancel_occurrence() {
		$in = Forms::post();
		Forms::verify( 'wpcap_cancel_occurrence' );
		$result = self::cancel_occurrence( $in );
		$message = __( 'Termin abgesagt.', 'wp-pass-captain' );
		if ( ! is_wp_error( $result ) && ! empty( $result['summary'] ) ) {
			$s = $result['summary'];
			/* translators: 1: cancelled open orders, 2: revoked tickets, 3: refunds, 4: refunds open/failed, 5: notified holders */
			$message = sprintf( __( 'Termin abgesagt: %1$d offene Bestellungen storniert, %2$d Tickets widerrufen, %3$d Erstattungen ausgeführt, %4$d Erstattungen offen, %5$d Personen benachrichtigt.', 'wp-pass-captain' ), $s['cancelled_orders'], $s['revoked'], $s['refunds'], $s['refund_errors'], $s['notified'] );
		}
		$back = self::url( array( 'view' => 'cancel_occurrence', 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) );
		Forms::finish( $result, $message, is_wp_error( $result ) ? $back : self::event_url( $result['event_id'] ), $back );
	}

	public static function handle_save_ticket_type() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_ticket_type' );
		$result = self::save_ticket_type( $in );
		$back = Forms::int( $in, 'ticket_type_id' ) ? self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ) ) ) : self::url( array( 'view' => 'occurrence', 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) );
		Forms::finish( $result, __( 'Ticketart gespeichert.', 'wp-pass-captain' ), is_wp_error( $result ) ? $back : self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $result['ticket_type_id'] ) ), $back );
	}

	public static function handle_save_participant_field() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_participant_field' );
		$result = self::save_participant_field( $in );
		$type_id = is_wp_error( $result ) ? Forms::int( $in, 'ticket_type_id' ) : $result['ticket_type_id'];
		Forms::finish( $result, __( 'Teilnehmerfeld gespeichert.', 'wp-pass-captain' ), self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $type_id ) ) );
	}

	public static function handle_participant_field_status() {
		$in = Forms::post();
		Forms::verify( 'wpcap_participant_field_status' );
		$result = self::set_participant_field_status( $in );
		$type_id = is_wp_error( $result ) ? Forms::int( $in, 'ticket_type_id' ) : $result['ticket_type_id'];
		Forms::finish( $result, __( 'Teilnehmerfeld aktualisiert.', 'wp-pass-captain' ), self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $type_id ) ) );
	}

	public static function handle_assign_staff() {
		$in = Forms::post();
		Forms::verify( 'wpcap_assign_staff' );
		$event_id = Forms::int( $in, 'event_id' );
		Forms::finish( self::assign_staff( $in ), __( 'Zugriff zugewiesen.', 'wp-pass-captain' ), $event_id ? self::event_url( $event_id ) : self::url() );
	}

	public static function handle_remove_staff() {
		$in = Forms::post();
		Forms::verify( 'wpcap_remove_staff' );
		Forms::finish( self::remove_staff( $in ), __( 'Zugriff entfernt.', 'wp-pass-captain' ), self::event_url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_cancel_event() {
		$in = Forms::post();
		$event_id = Forms::int( $in, 'event_id' );
		Forms::verify( 'wpcap_cancel_event_' . $event_id );
		$result = self::cancel_event( $in );
		/* translators: %d: notified buyers */
		$message = is_wp_error( $result ) ? '' : sprintf( __( 'Event abgesagt. %d Käufer wurden benachrichtigt.', 'wp-pass-captain' ), (int) ( $result['notified_count'] ?? 0 ) );
		if ( ! is_wp_error( $result ) && ! empty( $result['revoked'] ) ) {
			$message .= ' ' . ( ! empty( $result['auto_refund'] )
				/* translators: 1: automatic refunds, 2: failed refunds */
				? sprintf( __( '%1$d Bestellungen wurden automatisch erstattet, %2$d Erstattungen sind offen (siehe Erstattungen). WooCommerce-Bestellungen bitte in WooCommerce erstatten.', 'wp-pass-captain' ), (int) $result['refunds'], (int) $result['refund_errors'] )
				: __( 'Bezahlte Tickets bleiben erstattungsfähig: bitte über Bestellung → Erstatten erstatten.', 'wp-pass-captain' ) );
		}
		Forms::finish( $result, $message, self::url() );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 );
		}
		$view = Forms::query_arg( 'view' );
		echo '<div class="wrap wpcap-admin">';
		if ( 'event' === $view ) {
			self::render_event( absint( $_GET['event_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'occurrence' === $view ) {
			self::render_occurrence( absint( $_GET['occurrence_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'cancel_occurrence' === $view ) {
			self::render_cancel_occurrence( absint( $_GET['occurrence_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'ticket_type' === $view ) {
			self::render_ticket_type( absint( $_GET['ticket_type_id'] ?? 0 ), absint( $_GET['field_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/** Confirmation page of an occurrence cancellation: consequences, reason, explicit confirmation. */
	private static function render_cancel_occurrence( $occurrence_id ) {
		$occ = Queries::occurrence( $occurrence_id );
		if ( ! $occ || ! Access::can_access_occurrence( $occ->occurrence_id, self::CAP ) ) { self::denied(); return; }
		$event = Queries::event( (int) $occ->event_id );
		echo '<p><a href="' . esc_url( self::event_url( (int) $occ->event_id ) ) . '">' . esc_html__( '← Zum Event', 'wp-pass-captain' ) . '</a></p>';
		/* translators: 1: event name, 2: date */
		echo '<h1>' . esc_html( sprintf( __( 'Termin absagen: %1$s, %2$s', 'wp-pass-captain' ), $event ? $event->name : '', Forms::datetime( $occ->starts_at, $occ->timezone ) ) ) . '</h1>';
		Forms::render_notices();
		if ( 'cancelled' === $occ->status ) { echo '<p>' . esc_html__( 'Dieser Termin ist bereits abgesagt.', 'wp-pass-captain' ) . '</p>'; return; }
		$pv = self::cancellation_preview( (int) $occ->occurrence_id );
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Die Absage kann nicht rückgängig gemacht werden. Folgen:', 'wp-pass-captain' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
		/* translators: %d: tickets */
		echo '<li>' . esc_html( sprintf( __( '%d gültige Tickets werden widerrufen.', 'wp-pass-captain' ), $pv['tickets'] ) ) . '</li>';
		/* translators: %d: orders */
		echo '<li>' . esc_html( sprintf( __( '%d unbezahlte Bestellungen werden storniert, ihre Reservierungen freigegeben.', 'wp-pass-captain' ), $pv['pending_orders'] ) ) . '</li>';
		echo '<li>' . esc_html( $pv['auto_refund']
			/* translators: %d: orders */
			? sprintf( __( '%d bezahlte Bestellungen werden automatisch erstattet (Freigaberegeln gelten).', 'wp-pass-captain' ), $pv['paid_orders'] )
			/* translators: %d: orders */
			: sprintf( __( '%d bezahlte Bestellungen werden nicht automatisch erstattet; die Tickets bleiben erstattungsfähig (Bestellung → Erstatten).', 'wp-pass-captain' ), $pv['paid_orders'] ) ) . '</li>';
		if ( $pv['woo_orders'] ) {
			/* translators: %d: orders */
			echo '<li>' . esc_html( sprintf( __( '%d WooCommerce-Bestellungen müssen in WooCommerce erstattet werden.', 'wp-pass-captain' ), $pv['woo_orders'] ) ) . '</li>';
		}
		/* translators: %d: recipients */
		echo '<li>' . esc_html( sprintf( __( '%d Personen erhalten die Absage-Mail.', 'wp-pass-captain' ), $pv['recipients'] ) ) . '</li>';
		foreach ( $pv['passes'] as $pass ) {
			/* translators: 1: pass name, 2: tickets, 3: effect */
			echo '<li>' . esc_html( sprintf( __( 'Pass „%1$s“ (%2$d Tickets): %3$s', 'wp-pass-captain' ), (string) ( $pass['name'] ?? '' ), (int) ( $pass['tickets'] ?? 0 ), (string) ( $pass['effect'] ?? '' ) ) ) . '</li>';
		}
		echo '</ul></div>';
		Forms::open_form( 'wpcap_cancel_occurrence' );
		Forms::hidden( 'occurrence_id', (int) $occ->occurrence_id );
		echo '<table class="form-table"><tbody><tr><th><label for="wpcap-cancel-reason">' . esc_html__( 'Grund der Absage', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-cancel-reason" name="reason" class="regular-text" maxlength="255" required> <p class="description">' . esc_html__( 'Wird im Protokoll gespeichert.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Bestätigung', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Ich habe die Folgen geprüft und möchte den Termin absagen.', 'wp-pass-captain' ) . '</label></td></tr></tbody></table>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Termin endgültig absagen', 'wp-pass-captain' ) . '</button></p></form>';
	}

	private static function denied() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Nicht gefunden oder keine Berechtigung für dieses Event.', 'wp-pass-captain' ) . '</p></div>';
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Zur Eventliste', 'wp-pass-captain' ) . '</a></p>';
	}

	private static function status_options() {
		return array( 'draft' => __( 'Entwurf', 'wp-pass-captain' ), 'published' => __( 'Veröffentlicht', 'wp-pass-captain' ) );
	}

	private static function render_list() {
		$scope = Queries::event_ids_for( self::CAP );
		$page = Forms::current_page();
		$data = Queries::events_overview( $scope, $page, 50 );
		$figures = Queries::event_figures( array_map( static function ( $row ) { return (int) $row->event_id; }, $data['rows'] ) );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'wp-pass-captain' ); ?></h1>
		<hr class="wp-header-end">
		<?php Forms::render_notices(); ?>
		<?php GettingStarted::render(); ?>
		<table class="widefat striped wpcap-events-list">
			<caption class="screen-reader-text"><?php esc_html_e( 'Events mit nächstem Termin, Auslastung und Umsatz', 'wp-pass-captain' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Event', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Nächster Termin', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Ort', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Termine', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Verkauft / Kapazität', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Umsatz', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Noch keine Events angelegt.', 'wp-pass-captain' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $data['rows'] as $event ) :
				$next = $event->next_occurrence;
				$shown = $next ? $next : $event->last_occurrence;
				$fig = $figures[ (int) $event->event_id ] ?? array( 'sold' => 0, 'capacity' => 0, 'revenue' => array() );
				?>
				<tr>
					<th scope="row"><strong><a href="<?php echo esc_url( self::event_url( $event->event_id ) ); ?>"><?php echo esc_html( $event->name ); ?></a></strong></th>
					<td>
						<?php
						if ( $next ) {
							echo esc_html( Forms::datetime( $next->starts_at, $next->timezone ) );
						} elseif ( $shown ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Kein kommender Termin (zuletzt %s)', 'wp-pass-captain' ), Forms::datetime( $shown->starts_at, $shown->timezone ) ) );
						} else {
							echo '—';
						}
						?>
					</td>
					<td><?php echo esc_html( $shown ? $shown->location : '' ); ?></td>
					<td><?php echo esc_html( (string) (int) $event->occurrence_count ); ?></td>
					<td><?php echo esc_html( self::figures_sold_label( $fig ) ); ?></td>
					<td><?php echo esc_html( self::figures_revenue_label( $fig ) ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $event->status ) ); ?></td>
					<td>
						<a class="button" href="<?php echo esc_url( self::event_url( $event->event_id ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'wp-pass-captain' ); ?></a>
						<?php if ( 'published' === $event->status ) : ?>
							<?php Forms::open_form( 'wpcap_cancel_event', 'wpcap_cancel_event_' . $event->event_id, 'style="display:inline"' ); Forms::hidden( 'event_id', $event->event_id ); ?>
							<button class="button" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Event absagen und betroffene Käufer benachrichtigen?', 'wp-pass-captain' ) ); ?>')"><?php esc_html_e( 'Event absagen', 'wp-pass-captain' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php Forms::pagination( $data['total'], $page, 50, self::url() ); ?>

		<h2 id="wpcap-create-event"><?php esc_html_e( 'Event anlegen', 'wp-pass-captain' ); ?></h2>
		<p><?php esc_html_e( 'Neue Events starten als Entwurf. Zeiten werden in der gewählten Zeitzone eingegeben.', 'wp-pass-captain' ); ?></p>
		<?php Forms::open_form( 'wpcap_create_event' ); ?>
			<table class="form-table"><tbody>
			<tr><th><label for="wpcap-name"><?php esc_html_e( 'Eventname', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-name" name="name" type="text" class="regular-text" required maxlength="190"></td></tr>
			<tr><th><label for="wpcap-description"><?php esc_html_e( 'Beschreibung', 'wp-pass-captain' ); ?></label></th><td><textarea id="wpcap-description" name="description" class="large-text" rows="4"></textarea></td></tr>
			<tr><th><label for="wpcap-start"><?php esc_html_e( 'Beginn', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-start" name="starts_at" type="datetime-local" required> <?php Forms::timezone_select( 'timezone', wp_timezone_string() ); ?></td></tr>
			<tr><th><label for="wpcap-end"><?php esc_html_e( 'Ende (optional)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-end" name="ends_at" type="datetime-local"></td></tr>
			<tr><th><label for="wpcap-repeat"><?php esc_html_e( 'Terminserie', 'wp-pass-captain' ); ?></label></th><td><select id="wpcap-repeat" name="repeat_frequency"><option value="none"><?php esc_html_e( 'Einmalig', 'wp-pass-captain' ); ?></option><option value="weekly"><?php esc_html_e( 'Wöchentlich', 'wp-pass-captain' ); ?></option></select> <label><?php esc_html_e( 'Anzahl Termine (max. 12)', 'wp-pass-captain' ); ?> <input type="number" name="repeat_count" min="1" max="12" value="1"></label></td></tr>
			<tr><th><label for="wpcap-location"><?php esc_html_e( 'Ort', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-location" name="location" type="text" class="regular-text" maxlength="190"></td></tr>
			<tr><th><label for="wpcap-event-capacity"><?php esc_html_e( 'Kontingent pro Termin', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-event-capacity" name="event_capacity" type="number" min="0" value="0"> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-type-name"><?php esc_html_e( 'Ticketkategorie', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-type-name" name="ticket_name" type="text" value="Standard" required maxlength="190"></td></tr>
			<tr><th><label for="wpcap-price"><?php esc_html_e( 'Preis (brutto)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-price" name="price" type="text" inputmode="decimal" value="0,00" required size="10"> <?php Forms::select( 'currency', array_combine( Localization::currencies(), Localization::currencies() ), 'EUR', '', 'aria-label="' . esc_attr__( 'Währung', 'wp-pass-captain' ) . '"' ); ?></td></tr>
			<tr><th><label for="wpcap-tax"><?php esc_html_e( 'Steuersatz in %', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tax" name="tax_rate" type="text" inputmode="decimal" value="0" size="6"> <span class="description"><?php esc_html_e( 'z. B. 19 oder 7,7', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-type-capacity"><?php esc_html_e( 'Ticketkontingent', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-type-capacity" name="ticket_capacity" type="number" min="0" value="0"></td></tr>
			<tr><th><label for="wpcap-status"><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></label></th><td><?php Forms::select( 'status', self::status_options(), 'draft', 'wpcap-status' ); ?></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Event speichern', 'wp-pass-captain' ) ); ?>
		</form>
		<?php
	}

	/** "Verkauft / Kapazität" cell of the event list: "12 / 100 (12 %)", "12 / ∞". */
	public static function figures_sold_label( array $fig ) {
		$sold = (int) ( $fig['sold'] ?? 0 );
		$capacity = array_key_exists( 'capacity', $fig ) ? $fig['capacity'] : 0;
		if ( null === $capacity ) { return number_format_i18n( $sold ) . ' / ∞'; }
		$label = number_format_i18n( $sold ) . ' / ' . number_format_i18n( (int) $capacity );
		return (int) $capacity > 0 ? $label . ' (' . number_format_i18n( (int) floor( $sold * 100 / (int) $capacity ) ) . ' %)' : $label;
	}

	/** "Umsatz" cell of the event list: amounts per currency or "—". */
	public static function figures_revenue_label( array $fig ) {
		$parts = array();
		foreach ( (array) ( $fig['revenue'] ?? array() ) as $currency => $minor ) { $parts[] = Forms::money( (int) $minor, (string) $currency ); }
		return $parts ? implode( ' · ', $parts ) : '—';
	}

	private static function render_event( $event_id ) {
		$event = Queries::event( $event_id );
		if ( ! $event || ! Access::can_access_event( $event_id, self::CAP ) ) { self::denied(); return; }
		$editable = 'cancelled' !== $event->status;
		$occurrences = Queries::occurrences_for_event( $event_id );
		$default_tz = $occurrences ? end( $occurrences )->timezone : wp_timezone_string();
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( '← Zur Eventliste', 'wp-pass-captain' ); ?></a></p>
		<h1><?php echo esc_html( $event->name ); ?> <span class="subtitle"><?php echo esc_html( Forms::status_label( $event->status ) ); ?></span></h1>
		<?php Forms::render_notices(); ?>

		<h2><?php esc_html_e( 'Eventdaten', 'wp-pass-captain' ); ?></h2>
		<?php if ( $editable ) : ?>
			<?php Forms::open_form( 'wpcap_update_event' ); Forms::hidden( 'event_id', $event->event_id ); ?>
			<table class="form-table"><tbody>
				<tr><th><label for="wpcap-ev-name"><?php esc_html_e( 'Eventname', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-ev-name" name="name" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $event->name ); ?>"></td></tr>
				<tr><th><label for="wpcap-ev-desc"><?php esc_html_e( 'Beschreibung', 'wp-pass-captain' ); ?></label></th><td><textarea id="wpcap-ev-desc" name="description" class="large-text" rows="5"><?php echo esc_textarea( $event->description ); ?></textarea></td></tr>
				<tr><th><label for="wpcap-ev-status"><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></label></th><td><?php Forms::select( 'status', self::status_options(), $event->status, 'wpcap-ev-status' ); ?></td></tr>
				<?php if ( Revisions::ready() ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Änderungsmitteilungen', 'wp-pass-captain' ); ?></th><td><?php Forms::hidden( 'notify_changes_shown', 1 ); ?><label><input type="checkbox" name="notify_changes" value="1"<?php checked( 1 === (int) ( $event->notify_changes ?? 1 ) ); ?>> <?php esc_html_e( 'Ticketinhaber:innen per E-Mail informieren, wenn sich Zeit, Ort oder Einlass eines Termins ändern (mit aktualisierter Kalenderdatei)', 'wp-pass-captain' ); ?></label><p class="description"><?php esc_html_e( 'Absagen werden unabhängig davon immer mitgeteilt.', 'wp-pass-captain' ); ?></p></td></tr>
				<?php endif; ?>
			</tbody></table>
			<?php submit_button( __( 'Event aktualisieren', 'wp-pass-captain' ) ); ?>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'Dieses Event wurde abgesagt und kann nicht mehr bearbeitet werden.', 'wp-pass-captain' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Termine', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Beginn', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Ende', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Ort', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Verkauft / Kontingent', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $occurrences ) : ?><tr><td colspan="6"><?php esc_html_e( 'Keine Termine.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $occurrences as $occ ) : ?>
				<tr>
					<td><?php echo esc_html( Forms::datetime( $occ->starts_at, $occ->timezone ) ); ?><?php $doors = \PassCaptain\DoorsOpen::label( $occ->doors_open_at ?? null, $occ->timezone, $occ->starts_at ); ?><?php if ( '' !== $doors ) : ?><br><span class="description"><?php echo esc_html( $doors ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( $occ->ends_at ? Forms::datetime( $occ->ends_at, $occ->timezone, false ) : '—' ); ?></td>
					<td><?php echo esc_html( $occ->location ); ?></td>
					<td><?php echo esc_html( Queries::sold_for_occurrence( $occ->occurrence_id ) . ' / ' . ( (int) $occ->capacity ? (int) $occ->capacity : '∞' ) ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $occ->status ) ); ?></td>
					<td>
						<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'occurrence', 'occurrence_id' => $occ->occurrence_id ) ) ); ?>"><?php esc_html_e( 'Bearbeiten & Ticketarten', 'wp-pass-captain' ); ?></a>
						<?php if ( $editable && 'cancelled' !== $occ->status ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'cancel_occurrence', 'occurrence_id' => $occ->occurrence_id ) ) ); ?>"><?php esc_html_e( 'Absagen …', 'wp-pass-captain' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( \PassCaptain\Consents::ready() && Access::can_access_event( $event_id, OrdersPage::CAP ) ) : ?>
			<p><a class="button" href="<?php echo esc_url( \PassCaptain\Consents::export_url( $event_id ) ); ?>"><?php esc_html_e( 'Einwilligungen exportieren (CSV)', 'wp-pass-captain' ); ?></a> <span class="description"><?php esc_html_e( 'Alle Erteilungen und Widerrufe mit Textfassung, Quelle und aktuellem Stand; der Export wird im Audit-Protokoll vermerkt.', 'wp-pass-captain' ); ?></span></p>
		<?php endif; ?>

		<?php if ( $editable ) : ?>
			<h3><?php esc_html_e( 'Termin hinzufügen', 'wp-pass-captain' ); ?></h3>
			<?php self::occurrence_form( null, (int) $event->event_id, $default_tz ); ?>
		<?php endif; ?>

		<?php self::render_staff( $event ); ?>
		<?php
	}

	private static function occurrence_form( $occ, $event_id, $default_tz ) {
		$tz = $occ ? $occ->timezone : $default_tz;
		Forms::open_form( 'wpcap_save_occurrence' );
		Forms::hidden( 'event_id', $event_id );
		Forms::hidden( 'occurrence_id', $occ ? $occ->occurrence_id : 0 );
		?>
		<table class="form-table"><tbody>
			<tr><th><label for="wpcap-occ-start"><?php esc_html_e( 'Beginn (Ortszeit)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-occ-start" name="starts_at" type="datetime-local" required value="<?php echo esc_attr( $occ ? Forms::utc_to_local( $occ->starts_at, $tz ) : '' ); ?>"></td></tr>
			<tr><th><label for="wpcap-occ-end"><?php esc_html_e( 'Ende (optional)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-occ-end" name="ends_at" type="datetime-local" value="<?php echo esc_attr( $occ ? Forms::utc_to_local( $occ->ends_at, $tz ) : '' ); ?>"></td></tr>
			<tr><th><label for="wpcap-occ-doors"><?php esc_html_e( 'Einlass ab (optional)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-occ-doors" name="doors_open_at" type="datetime-local" aria-describedby="wpcap-occ-doors-help" value="<?php echo esc_attr( $occ && ! empty( $occ->doors_open_at ) ? Forms::utc_to_local( $occ->doors_open_at, $tz ) : '' ); ?>"> <span class="description" id="wpcap-occ-doors-help"><?php esc_html_e( 'Ortszeit. Vorher wird am Einlass niemand eingelassen, auch wenn eine Einlassregel früher öffnet. Leer = kein eigener Einlassbeginn.', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-occ-tz"><?php esc_html_e( 'Zeitzone', 'wp-pass-captain' ); ?></label></th><td><?php Forms::timezone_select( 'timezone', $tz, 'wpcap-occ-tz' ); ?></td></tr>
			<tr><th><label for="wpcap-occ-loc"><?php esc_html_e( 'Ort', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-occ-loc" name="location" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $occ ? $occ->location : '' ); ?>"></td></tr>
			<?php if ( \PassCaptain\Tax\Profiles::ready() ) : ?>
			<tr><th><label for="wpcap-occ-country"><?php esc_html_e( 'Veranstaltungsland', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-occ-country" name="venue_country" type="text" size="3" maxlength="2" pattern="[A-Za-z]{2}" style="text-transform:uppercase" aria-describedby="wpcap-occ-country-help" value="<?php echo esc_attr( $occ ? (string) ( $occ->venue_country ?? '' ) : '' ); ?>"> <span class="description" id="wpcap-occ-country-help"><?php echo esc_html( sprintf( /* translators: %s: seller country code from the settings */ __( 'ISO-Code, z. B. AT. Leistungsort für die Umsatzsteuer auf Eintrittskarten (Steuerprofile). Leer = Land des Rechnungsstellers (%s).', 'wp-pass-captain' ), \PassCaptain\Tax\Profiles::seller_country() ) ); ?></span></td></tr>
			<?php endif; ?>
			<tr><th><label for="wpcap-occ-cap"><?php esc_html_e( 'Kontingent', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-occ-cap" name="capacity" type="number" min="0" value="<?php echo esc_attr( $occ ? (int) $occ->capacity : 0 ); ?>"> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-occ-status"><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></label></th><td><?php Forms::select( 'status', array( 'scheduled' => __( 'Geplant', 'wp-pass-captain' ), 'cancelled' => __( 'Abgesagt', 'wp-pass-captain' ) ), $occ ? $occ->status : 'scheduled', 'wpcap-occ-status' ); ?></td></tr>
		</tbody></table>
		<?php if ( $occ && Queries::issued_ticket_count_for_occurrence( $occ->occurrence_id ) ) : ?>
			<p class="description"><?php esc_html_e( 'Für diesen Termin sind bereits Tickets ausgestellt. Eine Änderung des Beginns wird als Terminverschiebung gemeldet.', 'wp-pass-captain' ); ?></p>
		<?php endif; ?>
		<?php submit_button( $occ ? __( 'Termin speichern', 'wp-pass-captain' ) : __( 'Termin hinzufügen', 'wp-pass-captain' ) ); ?>
		</form>
		<?php
	}

	private static function render_staff( $event ) {
		$staff = Queries::staff_for_event( $event->event_id );
		$cap_labels = array(
			'wpcap_checkin' => __( 'Einlass/Scanner', 'wp-pass-captain' ),
			'wpcap_manage_events' => __( 'Event verwalten', 'wp-pass-captain' ),
			'wpcap_manage_orders' => __( 'Bestellungen/Kasse', 'wp-pass-captain' ),
			'wpcap_view_reports' => __( 'Berichte', 'wp-pass-captain' ),
		);
		?>
		<h2><?php esc_html_e( 'Personal und Zugriff', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Benutzer', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Recht', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $staff ) : ?><tr><td colspan="3"><?php esc_html_e( 'Noch kein Personal zugewiesen.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $staff as $row ) : ?>
				<tr>
					<td><?php echo esc_html( Forms::user_label( $row->user_id ) ); ?></td>
					<td><?php echo esc_html( $cap_labels[ $row->capability ] ?? $row->capability ); ?></td>
					<td>
						<?php Forms::open_form( 'wpcap_remove_staff', null, 'style="display:inline"' ); Forms::hidden( 'event_id', $event->event_id ); Forms::hidden( 'user_id', $row->user_id ); Forms::hidden( 'capability', $row->capability ); ?>
						<button class="button-link-delete button-link" type="submit"><?php esc_html_e( 'Entfernen', 'wp-pass-captain' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Zugriff zuweisen', 'wp-pass-captain' ); ?></h3>
		<?php Forms::open_form( 'wpcap_assign_staff' ); Forms::hidden( 'event_id', $event->event_id ); ?>
		<label><?php esc_html_e( 'Benutzer (ID, Login oder E-Mail)', 'wp-pass-captain' ); ?> <input type="text" name="user" required></label>
		<label><?php esc_html_e( 'Recht', 'wp-pass-captain' ); ?> <?php Forms::select( 'capability', $cap_labels, 'wpcap_checkin' ); ?></label>
		<button class="button" type="submit"><?php esc_html_e( 'Zugriff zuweisen', 'wp-pass-captain' ); ?></button>
		<p class="description"><?php esc_html_e( 'Einlassrechte werden direkt vergeben. Für alle anderen Rechte braucht der Benutzer eine passende Rolle (z. B. Pass Captain Veranstaltungsleitung).', 'wp-pass-captain' ); ?></p>
		</form>
		<?php
	}

	private static function render_occurrence( $occurrence_id ) {
		$occ = Queries::occurrence( $occurrence_id );
		if ( ! $occ || ! Access::can_access_occurrence( $occ->occurrence_id, self::CAP ) ) { self::denied(); return; }
		$event = Queries::event( $occ->event_id );
		$editable = $event && 'cancelled' !== $event->status;
		$types = Queries::ticket_types_for_occurrence( $occurrence_id );
		?>
		<p><a href="<?php echo esc_url( self::event_url( $occ->event_id ) ); ?>"><?php echo esc_html( '← ' . ( $event ? $event->name : '' ) ); ?></a></p>
		<h1><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Termin %s', 'wp-pass-captain' ), Forms::datetime( $occ->starts_at, $occ->timezone ) ) ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $editable ) { self::occurrence_form( $occ, (int) $occ->event_id, $occ->timezone ); } ?>

		<h2><?php esc_html_e( 'Ticketarten', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Preis', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Steuer', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Verkauft / Kontingent', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Max. pro Bestellung', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Verkaufszeitraum', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $types ) : ?><tr><td colspan="7"><?php esc_html_e( 'Noch keine Ticketarten.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $types as $type ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $type->ticket_type_id ) ) ); ?>"><?php echo esc_html( $type->name ); ?></a></td>
					<td><?php echo esc_html( Forms::money( $type->price_minor, $type->currency ) ); ?></td>
					<td><?php echo esc_html( Forms::percent_input( $type->tax_rate_bp ) . ' %' ); ?></td>
					<td><?php echo esc_html( Queries::sold_for_type( $type->ticket_type_id ) . ' / ' . ( (int) $type->capacity ? (int) $type->capacity : '∞' ) ); ?></td>
					<td><?php echo esc_html( (string) (int) $type->max_per_order ); ?></td>
					<td><?php echo esc_html( ( $type->sales_start ? Forms::datetime( $type->sales_start, $occ->timezone, false ) : '…' ) . ' – ' . ( $type->sales_end ? Forms::datetime( $type->sales_end, $occ->timezone, false ) : '…' ) ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $type->status ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $editable ) : ?>
			<h3><?php esc_html_e( 'Ticketart hinzufügen', 'wp-pass-captain' ); ?></h3>
			<?php self::ticket_type_form( null, $occ ); ?>
		<?php endif; ?>
		<?php
		ChangeHistory::render( ChangeHistory::for_occurrence( (int) $occ->occurrence_id ), (string) $occ->timezone, 'wpcap-occurrence-history' );
	}

	private static function ticket_type_form( $type, $occ ) {
		$currency = $type ? $type->currency : 'EUR';
		Forms::open_form( 'wpcap_save_ticket_type' );
		Forms::hidden( 'occurrence_id', $occ->occurrence_id );
		Forms::hidden( 'ticket_type_id', $type ? $type->ticket_type_id : 0 );
		?>
		<table class="form-table"><tbody>
			<tr><th><label for="wpcap-tt-name"><?php esc_html_e( 'Name', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-name" name="name" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $type ? $type->name : '' ); ?>"></td></tr>
			<tr><th><label for="wpcap-tt-price"><?php esc_html_e( 'Preis (brutto)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-price" name="price" type="text" inputmode="decimal" required size="10" value="<?php echo esc_attr( Forms::money_input( $type ? $type->price_minor : 0, Forms::currency_decimals( $currency ) ) ); ?>"> <?php Forms::select( 'currency', array_combine( Localization::currencies(), Localization::currencies() ), $currency ); ?> <span class="description"><?php esc_html_e( 'z. B. 12,50', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-tax"><?php esc_html_e( 'Steuersatz in %', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-tax" name="tax_rate" type="text" inputmode="decimal" size="6" value="<?php echo esc_attr( Forms::percent_input( $type ? $type->tax_rate_bp : 0 ) ); ?>"> <span class="description"><?php esc_html_e( 'Im Preis enthaltene Umsatzsteuer, z. B. 19 oder 7,7', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-taxclass"><?php esc_html_e( 'Steuerklasse (optional)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-taxclass" name="tax_class" type="text" size="16" maxlength="64" pattern="[a-z0-9_-]*" aria-describedby="wpcap-tt-taxclass-help" value="<?php echo esc_attr( $type ? (string) ( $type->tax_class ?? '' ) : '' ); ?>"> <span class="description" id="wpcap-tt-taxclass-help"><?php esc_html_e( 'z. B. culture. Gibt es für das Veranstaltungsland ein aktives Steuerprofil dieser Klasse, gilt dessen Satz; sonst der Steuersatz oben.', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-cap"><?php esc_html_e( 'Kontingent', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-cap" name="capacity" type="number" min="0" value="<?php echo esc_attr( $type ? (int) $type->capacity : 0 ); ?>"> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'wp-pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-max"><?php esc_html_e( 'Max. pro Bestellung', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-max" name="max_per_order" type="number" min="1" max="100" value="<?php echo esc_attr( $type ? (int) $type->max_per_order : 10 ); ?>"></td></tr>
			<tr><th><label for="wpcap-tt-ss"><?php esc_html_e( 'Verkaufsstart', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-ss" name="sales_start" type="datetime-local" value="<?php echo esc_attr( $type ? Forms::utc_to_local( $type->sales_start, $occ->timezone ) : '' ); ?>"> <span class="description"><?php echo esc_html( sprintf( /* translators: %s: timezone */ __( 'Ortszeit %s; leer = sofort', 'wp-pass-captain' ), $occ->timezone ) ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-se"><?php esc_html_e( 'Verkaufsende', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-tt-se" name="sales_end" type="datetime-local" value="<?php echo esc_attr( $type ? Forms::utc_to_local( $type->sales_end, $occ->timezone ) : '' ); ?>"> <span class="description"><?php esc_html_e( 'leer = bis Terminbeginn', 'wp-pass-captain' ); ?></span></td></tr>
			<?php $checks = \PassCaptain\AdmissionChecks::for_type( $type ? $type : 0 ); ?>
			<tr><th><?php esc_html_e( 'Einlasskontrolle', 'wp-pass-captain' ); ?></th><td>
				<label><input type="checkbox" name="id_check" value="1" <?php echo $checks['id_check'] ? 'checked' : ''; ?>> <?php esc_html_e( 'Ausweis prüfen', 'wp-pass-captain' ); ?></label><br>
				<label><?php esc_html_e( 'Mindestalter', 'wp-pass-captain' ); ?> <input name="min_age" type="number" min="0" max="99" class="small-text" value="<?php echo esc_attr( $checks['min_age'] ? (string) $checks['min_age'] : '' ); ?>"></label><br>
				<label><?php esc_html_e( 'Hinweis für das Einlasspersonal', 'wp-pass-captain' ); ?> <input name="staff_note" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $checks['staff_note'] ); ?>"></label>
				<p class="description"><?php esc_html_e( 'Wird beim Scannen (Web-Scanner, App, Offline-Scanner) angezeigt; Mindestalter und Ausweispflicht sehen Käufer auch beim Ticketkauf. Der Scan wird dadurch nicht abgelehnt.', 'wp-pass-captain' ); ?></p>
			</td></tr>
			<tr><th><label for="wpcap-tt-status"><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></label></th><td><?php Forms::select( 'status', array( 'active' => __( 'Aktiv', 'wp-pass-captain' ), 'paused' => __( 'Pausiert', 'wp-pass-captain' ) ), $type ? $type->status : 'active', 'wpcap-tt-status' ); ?></td></tr>
		</tbody></table>
		<?php submit_button( $type ? __( 'Ticketart speichern', 'wp-pass-captain' ) : __( 'Ticketart hinzufügen', 'wp-pass-captain' ) ); ?>
		</form>
		<?php
	}

	private static function render_ticket_type( $ticket_type_id, $edit_field_id ) {
		$type = Queries::ticket_type( $ticket_type_id );
		if ( ! $type || ! Access::can_access_event( $type->event_id, self::CAP ) ) { self::denied(); return; }
		$occ = Queries::occurrence( $type->occurrence_id );
		$event = Queries::event( $type->event_id );
		$editable = $event && 'cancelled' !== $event->status;
		$fields = Queries::participant_fields( $ticket_type_id );
		$edit_field = null;
		foreach ( $fields as $field ) {
			if ( (int) $field->field_id === $edit_field_id ) { $edit_field = $field; }
		}
		$type_labels = array( 'text' => __( 'Text', 'wp-pass-captain' ), 'email' => __( 'E-Mail', 'wp-pass-captain' ), 'textarea' => __( 'Mehrzeiliger Text', 'wp-pass-captain' ), 'checkbox' => __( 'Checkbox', 'wp-pass-captain' ) );
		$type_url = self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $ticket_type_id ) );
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'view' => 'occurrence', 'occurrence_id' => $type->occurrence_id ) ) ); ?>"><?php echo esc_html( '← ' . ( $event ? $event->name . ', ' : '' ) . Forms::datetime( $occ->starts_at, $occ->timezone ) ); ?></a></p>
		<h1><?php echo esc_html( sprintf( /* translators: %s: ticket type name */ __( 'Ticketart: %s', 'wp-pass-captain' ), $type->name ) ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $editable ) { self::ticket_type_form( $type, $occ ); } ?>

		<h2><?php esc_html_e( 'Teilnehmerfelder', 'wp-pass-captain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Nur Daten abfragen, die für die Veranstaltung erforderlich sind. Zweck und Aufbewahrungsdauer werden Käufern angezeigt.', 'wp-pass-captain' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Schlüssel', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Bezeichnung', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Typ', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Pflicht', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Zweck', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aufbewahrung (Tage)', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $fields ) : ?><tr><td colspan="8"><?php esc_html_e( 'Keine Teilnehmerfelder.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $fields as $field ) : ?>
				<tr>
					<td><code><?php echo esc_html( $field->field_key ); ?></code></td>
					<td><?php echo esc_html( $field->label ); ?></td>
					<td><?php echo esc_html( ( $type_labels[ $field->field_type ] ?? $field->field_type ) . ( ! empty( $field->consent ) ? ' · ' . __( 'Einwilligung', 'wp-pass-captain' ) : '' ) ); ?></td>
					<td><?php echo $field->required ? esc_html__( 'Ja', 'wp-pass-captain' ) : esc_html__( 'Nein', 'wp-pass-captain' ); ?></td>
					<td><?php echo esc_html( $field->purpose ); ?></td>
					<td><?php echo esc_html( (string) (int) $field->retention_days ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $field->status ) ); ?></td>
					<td>
						<?php if ( $editable ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'field_id', $field->field_id, $type_url ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'wp-pass-captain' ); ?></a>
						<?php Forms::open_form( 'wpcap_participant_field_status', null, 'style="display:inline"' ); Forms::hidden( 'field_id', $field->field_id ); Forms::hidden( 'ticket_type_id', $ticket_type_id ); Forms::hidden( 'status', 'active' === $field->status ? 'inactive' : 'active' ); ?>
						<button class="button" type="submit"><?php echo 'active' === $field->status ? esc_html__( 'Deaktivieren', 'wp-pass-captain' ) : esc_html__( 'Aktivieren', 'wp-pass-captain' ); ?></button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $editable ) : ?>
			<h3><?php echo $edit_field ? esc_html__( 'Teilnehmerfeld bearbeiten', 'wp-pass-captain' ) : esc_html__( 'Teilnehmerfeld hinzufügen', 'wp-pass-captain' ); ?></h3>
			<?php Forms::open_form( 'wpcap_save_participant_field' ); Forms::hidden( 'ticket_type_id', $ticket_type_id ); Forms::hidden( 'field_id', $edit_field ? $edit_field->field_id : 0 ); ?>
			<table class="form-table"><tbody>
				<tr><th><label for="wpcap-pf-key"><?php esc_html_e( 'Schlüssel', 'wp-pass-captain' ); ?></label></th><td>
					<?php if ( $edit_field ) : ?><code><?php echo esc_html( $edit_field->field_key ); ?></code> <span class="description"><?php esc_html_e( 'Der Schlüssel kann nicht geändert werden.', 'wp-pass-captain' ); ?></span>
					<?php else : ?><input id="wpcap-pf-key" name="field_key" type="text" required maxlength="64" pattern="[a-z0-9_]+"> <span class="description"><?php esc_html_e( 'Technischer Name, z. B. company (a-z, 0-9, _)', 'wp-pass-captain' ); ?></span><?php endif; ?>
				</td></tr>
				<tr><th><label for="wpcap-pf-label"><?php esc_html_e( 'Bezeichnung', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-pf-label" name="label" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $edit_field ? $edit_field->label : '' ); ?>"></td></tr>
				<tr><th><label for="wpcap-pf-type"><?php esc_html_e( 'Typ', 'wp-pass-captain' ); ?></label></th><td><?php Forms::select( 'field_type', $type_labels, $edit_field ? $edit_field->field_type : 'text', 'wpcap-pf-type' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Pflichtfeld', 'wp-pass-captain' ); ?></th><td><label><input type="checkbox" name="required" value="1" <?php checked( $edit_field && $edit_field->required ); ?>> <?php esc_html_e( 'Für jedes Ticket erforderlich', 'wp-pass-captain' ); ?></label></td></tr>
				<?php if ( \PassCaptain\Consents::ready() ) : ?>
				<tr><th><?php esc_html_e( 'Einwilligung', 'wp-pass-captain' ); ?></th><td><label><input type="checkbox" name="consent" value="1" aria-describedby="wpcap-pf-consent-help" <?php checked( $edit_field && ! empty( $edit_field->consent ) ); ?>> <?php esc_html_e( 'Als Einwilligung protokollieren und Widerruf ermöglichen', 'wp-pass-captain' ); ?></label>
					<p class="description" id="wpcap-pf-consent-help"><?php esc_html_e( 'Nur für Checkboxen. Jede Erteilung und jeder Widerruf wird mit Textfassung, Zeitpunkt und Quelle protokolliert; Teilnehmende können in der Bestellübersicht und auf der Ticketseite widerrufen. Zweck ist Pflicht. Eine Änderung von Bezeichnung oder Zweck gilt als neue Textfassung.', 'wp-pass-captain' ); ?></p></td></tr>
				<?php endif; ?>
				<tr><th><label for="wpcap-pf-purpose"><?php esc_html_e( 'Zweck', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-pf-purpose" name="purpose" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $edit_field ? $edit_field->purpose : '' ); ?>"></td></tr>
				<tr><th><label for="wpcap-pf-ret"><?php esc_html_e( 'Aufbewahrung (Tage)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-pf-ret" name="retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( $edit_field ? (int) $edit_field->retention_days : 365 ); ?>"></td></tr>
				<tr><th><label for="wpcap-pf-pos"><?php esc_html_e( 'Reihenfolge', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-pf-pos" name="position" type="number" min="0" value="<?php echo esc_attr( $edit_field ? (int) $edit_field->position : count( $fields ) ); ?>"></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Feld speichern', 'wp-pass-captain' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
