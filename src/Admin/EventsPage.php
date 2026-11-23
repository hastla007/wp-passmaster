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
use PassCaptain\BulkRuns;
use PassCaptain\Calendar\Revisions;
use PassCaptain\Localization;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EventsPage {
	const SLUG = 'pass-captain';
	const CAP = 'wpcap_manage_events';
	/** Participant field types: ParticipantFields::TYPES plus radio buttons and logged consent of the audit line. */
	const FIELD_TYPES = array( 'text', 'email', 'textarea', 'checkbox', 'consent', 'select', 'radio', 'multiselect', 'date', 'tel', 'number' );

	/** Field types whose choices are stored one per line (options column) by this screen; see save_participant_field(). */
	const CHOICE_TYPES = array( 'select', 'radio' );
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
		// A297: each check names its field (the form marks it and keeps the input).
		if ( '' === $name ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Bitte einen Eventnamen angeben.', 'pass-captain' ), 400, 'name' );
		}
		if ( ! Forms::is_valid_timezone( $timezone ) ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Die Zeitzone ist ungültig.', 'pass-captain' ), 400, 'timezone' );
		}
		if ( '' === $ticket_name ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Bitte einen Namen für die Ticketart angeben.', 'pass-captain' ), 400, 'ticket_name' );
		}
		if ( ! in_array( $currency, Localization::currencies(), true ) ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Die Währung ist ungültig.', 'pass-captain' ), 400, 'currency' );
		}
		$dst_notes = array(); // A102: DST gap → rejected, ambiguous time → first instance with a note.
		$start_utc = Forms::local_to_utc_checked( $in['starts_at'] ?? '', $timezone, $dst_notes );
		if ( is_wp_error( $start_utc ) ) { return Forms::with_field( $start_utc, 'starts_at' ); }
		if ( null === $start_utc ) {
			return Forms::error( 'wpcap_event_start', __( 'Der Beginn fehlt oder ist ungültig.', 'pass-captain' ), 400, 'starts_at' );
		}
		$duration = 0;
		if ( '' !== trim( (string) ( $in['ends_at'] ?? '' ) ) ) {
			$end_utc = Forms::local_to_utc_checked( $in['ends_at'], $timezone, $dst_notes );
			if ( is_wp_error( $end_utc ) ) { return Forms::with_field( $end_utc, 'ends_at' ); }
			if ( null === $end_utc || $end_utc <= $start_utc ) {
				return Forms::error( 'wpcap_event_end', __( 'Das Ende ist ungültig oder liegt nicht nach dem Beginn.', 'pass-captain' ), 400, 'ends_at' );
			}
			$duration = strtotime( $end_utc . ' UTC' ) - strtotime( $start_utc . ' UTC' );
		}
		$price = Forms::parse_money( $in['price'] ?? '0', Forms::currency_decimals( $currency ) );
		if ( null === $price ) { return Forms::error( 'wpcap_event_price', __( 'Der Preis ist ungültig. Beispiel: 12,50', 'pass-captain' ), 400, 'price' ); }
		$tax_bp = Forms::parse_percent_bp( $in['tax_rate'] ?? '' );
		if ( null === $tax_bp ) { return Forms::error( 'wpcap_event_tax', __( 'Der Steuersatz ist ungültig. Beispiel: 19 oder 7,7', 'pass-captain' ), 400, 'tax_rate' ); }
		$status = Forms::choice( $in, 'status', array( 'draft', 'published' ), 'draft' );
		$repeat = Forms::choice( $in, 'repeat_frequency', array( 'none', 'weekly' ), 'none' );
		$repeat_count = 'weekly' === $repeat ? min( 12, max( 1, Forms::int( $in, 'repeat_count', 1 ) ) ) : 1;
		$now = \PassCaptain\Clock::mysql();
		$slug = \PassCaptain\Text::cut( sanitize_title( $name ), 180 ) . '-' . strtolower( wp_generate_password( 6, false, false ) );

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
			return Forms::error( 'wpcap_event_save', __( 'Das Event konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
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
				return Forms::error( 'wpcap_occurrence_save', __( 'Ein Termin konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
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
				return Forms::error( 'wpcap_type_save', __( 'Die Ticketart konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
			}
		}
		if ( ! Queries::is_global_admin() ) {
			// Creators without global rights keep access to their event through the event scope.
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id,user_id,capability) VALUES (%d,%d,%s)', $p . 'wpcap_event_staff', $event_id, get_current_user_id(), self::CAP ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'event_created', 'event', $event_id, array( 'status' => $status, 'occurrences' => $repeat_count ) );
		\PassCaptain\PageCache::event_changed( $event_id, 'event' ); // A446: listing/calendar pages.
		$result = array( 'event_id' => $event_id ) + Forms::dst_warning( $dst_notes );
		return 'published' === $status ? self::with_publish_warning( $result, $event_id ) : $result;
	}

	/**
	 * Publishing checklist of an event (A293): what keeps a published event from being bookable on
	 * the sales page – no upcoming date, no active ticket type, no ticket type on sale (presale not
	 * started or sale ended), no available payment method for a currency. Empty = bookable.
	 *
	 * @return array key (occurrence|ticket_type|on_sale|payment) => message.
	 */
	public static function publish_issues( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = \PassCaptain\Clock::mysql();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT o.occurrence_id, o.timezone, tt.ticket_type_id, tt.currency, tt.sales_start, tt.sales_end FROM {$p}wpcap_occurrences o LEFT JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id AND tt.status='active' WHERE o.event_id=%d AND o.status='scheduled' AND o.starts_at >= %s",
			(int) $event_id,
			$now
		) );
		if ( ! $rows ) {
			return array( 'occurrence' => __( 'Kein geplanter Termin in der Zukunft – das Event erscheint nicht auf der Verkaufsseite. Einen Termin anlegen.', 'pass-captain' ) );
		}
		$types = array_filter( $rows, static function ( $row ) { return null !== $row->ticket_type_id; } );
		if ( ! $types ) {
			return array( 'ticket_type' => __( 'Keine aktive Ticketart für einen kommenden Termin – das Event erscheint nicht auf der Verkaufsseite. Eine Ticketart anlegen oder aktivieren.', 'pass-captain' ) );
		}
		$issues = array();
		$on_sale = false;
		$presale = '';
		$timezone = '';
		foreach ( $types as $type ) {
			$started = null === $type->sales_start || $type->sales_start <= $now;
			$open = null === $type->sales_end || $type->sales_end >= $now;
			if ( $started && $open ) { $on_sale = true; }
			if ( ! $started && ( '' === $presale || $type->sales_start < $presale ) ) {
				$presale = (string) $type->sales_start;
				$timezone = (string) $type->timezone;
			}
		}
		if ( ! $on_sale ) {
			$issues['on_sale'] = '' !== $presale
				/* translators: %s: date and time the sale starts */
				? sprintf( __( 'Noch keine Ticketart im Verkauf: Der Vorverkauf beginnt am %s. Bis dahin zeigt die Verkaufsseite „Vorverkauf ab …“.', 'pass-captain' ), Forms::datetime( $presale, $timezone ) )
				: __( 'Der Verkaufszeitraum aller Ticketarten ist beendet – das Event erscheint nicht mehr auf der Verkaufsseite.', 'pass-captain' );
		}
		$missing = array();
		foreach ( array_unique( array_map( static function ( $type ) { return strtoupper( (string) $type->currency ); }, $types ) ) as $currency ) {
			if ( ! \PassCaptain\Payments\Registry::enabled( $currency ) ) { $missing[] = $currency; }
		}
		if ( $missing ) {
			$issues['payment'] = sprintf(
				/* translators: %s: currency codes */
				__( 'Keine verfügbare Zahlungsart für %s – im Checkout kann nicht bestellt werden. Unter Einstellungen → Zahlungsarten eine Zahlungsart aktivieren und vollständig einrichten.', 'pass-captain' ),
				implode( ', ', $missing )
			);
		}
		return $issues;
	}

	/** Adds the publishing checklist of a just published event to a result as warning (A293). */
	private static function with_publish_warning( array $result, $event_id ) {
		$issues = self::publish_issues( $event_id );
		if ( ! $issues ) { return $result; }
		$warning = __( 'Das Event ist veröffentlicht, aber noch nicht buchbar:', 'pass-captain' ) . ' ' . implode( ' ', $issues );
		$result['warning'] = trim( ( $result['warning'] ?? '' ) . ' ' . $warning );
		$result['publish_issues'] = array_keys( $issues );
		return $result;
	}

	/** Updates name, description and status (draft/published) of an event. */
	public static function update_event( array $in ) {
		$event = Queries::event( Forms::int( $in, 'event_id' ) );
		if ( ! $event ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( 'cancelled' === $event->status ) {
			return Forms::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'pass-captain' ), 409 );
		}
		$name = Forms::text( $in, 'name' );
		$status = $in['status'] ?? $event->status;
		if ( '' === $name ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Bitte einen Eventnamen angeben.', 'pass-captain' ), 400, 'name' );
		}
		if ( ! in_array( $status, array( 'draft', 'published' ), true ) ) {
			return Forms::error( 'wpcap_event_invalid', __( 'Der Status ist ungültig.', 'pass-captain' ), 400, 'status' );
		}
		global $wpdb;
		$data = array(
			'name' => $name,
			'description' => wp_kses_post( (string) ( $in['description'] ?? '' ) ),
			'status' => $status,
			'updated_at' => \PassCaptain\Clock::mysql(),
		);
		$formats = array( '%s', '%s', '%s', '%s' );
		$extra = array();
		// Optional event page fields (Frontend\EventPage) and the self-service cancellation rule (Sales\SelfService).
		if ( array_key_exists( 'image_url', $in ) ) {
			$image = esc_url_raw( trim( (string) $in['image_url'] ) );
			$data['image_url'] = preg_match( '#^https?://#i', $image ) ? mb_substr( $image, 0, 500 ) : '';
			$data['category'] = Forms::text( $in, 'category' );
			array_push( $formats, '%s', '%s' );
		}
		if ( array_key_exists( 'refund_hours_before', $in ) ) {
			$policy = \PassCaptain\Sales\SelfService::parse_policy( $in );
			if ( is_wp_error( $policy ) ) { return $policy; }
			$data['refund_policy'] = $policy ? wp_json_encode( $policy ) : null;
			$formats[] = '%s';
		}
		// A140: per-event opt-out of change mails; only touched when the form showed the checkbox.
		if ( ! empty( $in['notify_changes_shown'] ) && Revisions::ready() ) {
			$data['notify_changes'] = Forms::flag( $in, 'notify_changes' ) ? 1 : 0;
			$formats[] = '%d';
			if ( (int) ( $event->notify_changes ?? 1 ) !== $data['notify_changes'] ) { $extra['notify_changes'] = $data['notify_changes']; }
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_events', $data, array( 'event_id' => (int) $event->event_id ), $formats, array( '%d' ) );
		Audit::record( 'event_updated', 'event', (int) $event->event_id, array( 'status' => $status, 'previous_status' => $event->status ) + $extra );
		\PassCaptain\PageCache::event_changed( (int) $event->event_id, 'event' ); // A446.
		\PassCaptain\Reports\Cache::flush_event( (int) $event->event_id );
		$changes = \PassCaptain\Admin\ChangeHistory::diff( (array) $event, array( 'name' => $name, 'status' => $status ) );
		if ( $changes ) {
			/**
			 * After an event's name or status was changed in the admin (A582, e.g. wallet passes).
			 *
			 * @param int   $event_id
			 * @param array $changes field => [old, new].
			 */
			do_action( 'wpcap_event_updated', (int) $event->event_id, $changes );
		}
		$result = array( 'event_id' => (int) $event->event_id );
		return 'published' === $status ? self::with_publish_warning( $result, (int) $event->event_id ) : $result;
	}

	/**
	 * Creates or updates an occurrence. Fires `wpcap_occurrence_rescheduled` when the start of an
	 * occurrence with issued tickets changes, `wpcap_occurrence_changed` when a calendar-relevant
	 * field changes (revision + 1, A140) and `wpcap_occurrence_cancelled` on cancellation.
	 */
	public static function save_occurrence( array $in ) {
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		$old = $occurrence_id ? Queries::occurrence( $occurrence_id ) : null;
		if ( $occurrence_id && ! $old ) { return Forms::error( 'wpcap_occurrence_missing', __( 'Termin nicht gefunden.', 'pass-captain' ), 404 ); }
		$event = Queries::event( $old ? $old->event_id : Forms::int( $in, 'event_id' ) );
		if ( ! $event ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event->event_id, self::CAP ) || ( $old && ! Access::occurrence_in_scope( $old->occurrence_id ) ) || ! Access::location_in_scope( Forms::text( $in, 'location' ) ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( 'cancelled' === $event->status ) {
			return Forms::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'pass-captain' ), 409 );
		}
		$timezone = Forms::text( $in, 'timezone', 64 );
		if ( ! Forms::is_valid_timezone( $timezone ) ) { return Forms::error( 'wpcap_timezone', __( 'Die Zeitzone ist ungültig.', 'pass-captain' ), 400, 'timezone' ); }
		$dst_notes = array(); // A102: DST gap → rejected, ambiguous time → first instance with a note.
		$starts = Forms::local_to_utc_checked( $in['starts_at'] ?? '', $timezone, $dst_notes );
		if ( is_wp_error( $starts ) ) { return Forms::with_field( $starts, 'starts_at' ); }
		if ( null === $starts ) { return Forms::error( 'wpcap_occurrence_start', __( 'Der Beginn ist ungültig.', 'pass-captain' ), 400, 'starts_at' ); }
		$ends = null;
		if ( '' !== trim( (string) ( $in['ends_at'] ?? '' ) ) ) {
			$ends = Forms::local_to_utc_checked( $in['ends_at'], $timezone, $dst_notes );
			if ( is_wp_error( $ends ) ) { return Forms::with_field( $ends, 'ends_at' ); }
			if ( null === $ends || $ends <= $starts ) { return Forms::error( 'wpcap_occurrence_end', __( 'Das Ende muss nach dem Beginn liegen.', 'pass-captain' ), 400, 'ends_at' ); }
		}
		$doors = \PassCaptain\DoorsOpen::parse( $in['doors_open_at'] ?? '', $timezone, $starts );
		if ( is_wp_error( $doors ) ) { return Forms::error( $doors->get_error_code(), $doors->get_error_message(), 400, 'doors_open_at' ); }
		$capacity = Forms::int( $in, 'capacity' );
		// A266/A356: the status is not edited here. Cancelling goes through the confirmation page
		// (cancel_occurrence(): consequences, reason, confirmation); a cancelled occurrence stays
		// cancelled (its tickets were revoked and the holders informed).
		$status = $old ? (string) $old->status : 'scheduled';
		$requested = isset( $in['status'] ) ? Forms::choice( $in, 'status', array( 'scheduled', 'cancelled' ), '' ) : '';
		if ( 'cancelled' === $requested && 'cancelled' !== $status ) {
			return Forms::error( 'wpcap_occurrence_cancel_flow', __( 'Termine werden nur über „Absagen …“ abgesagt: Dort stehen die Folgen (widerrufene Tickets, Erstattungen, Benachrichtigungen), der Grund und die Bestätigung.', 'pass-captain' ), 409 );
		}
		if ( 'scheduled' === $requested && 'cancelled' === $status ) {
			return Forms::error( 'wpcap_occurrence_reactivate', self::reactivation_message(), 409 );
		}
		if ( $old && $capacity > 0 ) {
			$sold = Queries::sold_for_occurrence( $old->occurrence_id );
			if ( $capacity < $sold ) {
				/* translators: %d: sold tickets */
				return Forms::error( 'wpcap_capacity_below_sold', sprintf( _n( 'Das Kontingent darf nicht unter das bereits verkaufte %d Ticket sinken.', 'Das Kontingent darf nicht unter die bereits verkauften %d Tickets sinken.', $sold, 'pass-captain' ), $sold) );
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
				return Forms::error( 'wpcap_occurrence_country', __( 'Das Veranstaltungsland muss ein ISO-Ländercode sein, z. B. DE oder AT.', 'pass-captain' ) );
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
			if ( 'scheduled' === $status && self::capacity_grew( (int) $old->capacity, (int) $capacity ) ) {
				// More places: waitlist offers go out now instead of on the next hourly sweep.
				do_action( 'wpcap_capacity_increased', 0, $occurrence_id );
			}
			if ( 'cancelled' === $status && 'cancelled' !== $old->status ) {
				do_action( 'wpcap_occurrence_cancelled', $occurrence_id, $issued );
			}
		} else {
			$data['event_id'] = (int) $event->event_id;
			$formats[] = '%d';
			$wpdb->insert( $table, $data, $formats );
			$occurrence_id = (int) $wpdb->insert_id;
			if ( ! $occurrence_id ) { return Forms::error( 'wpcap_occurrence_save', __( 'Der Termin konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
			Audit::record( 'occurrence_created', 'occurrence', $occurrence_id, array( 'event_id' => (int) $event->event_id, 'starts_at' => $starts ) );
		}
		if ( $old && $old->starts_at !== $starts && ( empty( $in['shift_sales_windows_shown'] ) || Forms::flag( $in, 'shift_sales_windows' ) ) ) {
			// A555: the sales windows move with the start (same local calendar distance).
			$dst_notes = array_merge( $dst_notes, self::shift_sales_windows( $occurrence_id, (int) $event->event_id, $old, $data ) );
		}
		if ( $old ) {
			// A562: an edit that moves the date into another phase (e.g. back to upcoming) is reported now.
			\PassCaptain\Occurrence::phase_changed( $occurrence_id, \PassCaptain\Occurrence::phase( $data ), \PassCaptain\Occurrence::phase( $old ), array( 'source' => 'admin', 'event_id' => (int) $event->event_id ) );
			// A535: ticket types whose sales end now lies after the occurrence's (effective) end.
			$late = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sales_end) FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' AND sales_end IS NOT NULL", $occurrence_id ) );
			$dst_notes[] = \PassCaptain\Occurrence::sales_end_warning( $data, (string) $late );
		}
		\PassCaptain\PageCache::event_changed( (int) $event->event_id, 'occurrence', $occurrence_id ); // A446.
		\PassCaptain\Reports\Cache::flush_event( (int) $event->event_id );
		return array( 'occurrence_id' => $occurrence_id, 'event_id' => (int) $event->event_id, 'rescheduled' => $rescheduled ) + Forms::dst_warning( $dst_notes );
	}

	/**
	 * Moves the sales windows of a rescheduled occurrence's ticket types by the same local calendar
	 * distance as its start (A555; 10:00 local stays 10:00 across DST). A window that would then lie
	 * completely outside [now, effective end] – already over, or starting only after the date – is
	 * left unchanged and named in a warning, so the admin decides.
	 *
	 * @param object $old  Occurrence row before the change.
	 * @param array  $data Written occurrence values (starts_at, ends_at, timezone).
	 * @return string[] Warnings.
	 */
	private static function shift_sales_windows( $occurrence_id, $event_id, $old, array $data ) {
		global $wpdb;
		$now = \PassCaptain\Clock::now();
		$end = \PassCaptain\Occurrence::end_ts( $data );
		$timezone = (string) $data['timezone'];
		$refused = array();
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name,currency,sales_start,sales_end FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND (sales_start IS NOT NULL OR sales_end IS NOT NULL) ORDER BY ticket_type_id", absint( $occurrence_id ) ) );
		foreach ( (array) $types as $type ) {
			$after = array();
			foreach ( array( 'sales_start', 'sales_end' ) as $field ) {
				if ( ! empty( $type->$field ) ) { $after[ $field ] = \PassCaptain\Occurrence::local_shift( $type->$field, $old->starts_at, $data['starts_at'], $timezone ); }
			}
			$closed = isset( $after['sales_end'] ) && strtotime( $after['sales_end'] . ' UTC' ) < $now;
			$late = isset( $after['sales_start'] ) && strtotime( $after['sales_start'] . ' UTC' ) >= $end;
			if ( $closed || $late ) {
				$refused[] = $type->name;
				continue;
			}
			$changes = ChangeHistory::diff( (array) $type, $after );
			if ( ! $changes ) { continue; }
			$wpdb->update( $wpdb->prefix . 'wpcap_ticket_types', $after, array( 'ticket_type_id' => (int) $type->ticket_type_id ) );
			Audit::record( 'ticket_type_updated', 'ticket_type', (int) $type->ticket_type_id, array( 'occurrence_id' => (int) $occurrence_id, 'event_id' => (int) $event_id, 'type_name' => $type->name, 'currency' => $type->currency, 'reason' => 'occurrence_rescheduled', 'changes' => $changes ) );
		}
		if ( ! $refused ) { return array(); }
		return array(
			sprintf(
				/* translators: %s: ticket type names */
				__( 'Der Verkaufszeitraum von %s wurde nicht mitverschoben: Er wäre danach schon vorbei bzw. begänne erst nach dem Termin. Bitte den Verkaufszeitraum dieser Ticketarten prüfen.', 'pass-captain' ),
				implode( ', ', array_map( static function ( $name ) { return '„' . $name . '“'; }, $refused ) )
			),
		);
	}

	/** Why a cancelled occurrence cannot be scheduled again (A356). */
	public static function reactivation_message() {
		return __( 'Ein abgesagter Termin kann nicht wieder auf „Geplant“ gesetzt werden: Seine Tickets wurden widerrufen (bzw. erstattet) und die Inhaber über die Absage informiert. Lege bitte einen neuen Termin an und verkaufe dafür neue Tickets.', 'pass-captain' );
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
		if ( ! $occ ) { return Forms::error( 'wpcap_occurrence_missing', __( 'Termin nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $occ->occurrence_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( 'cancelled' === $occ->status ) {
			// A428: a second click continues an unfinished cancellation instead of returning at once.
			$run = BulkRuns::occurrence_run( (int) $occ->occurrence_id );
			$result = array( 'occurrence_id' => (int) $occ->occurrence_id, 'event_id' => (int) $occ->event_id, 'issued' => 0, 'resumed' => (bool) $run, 'complete' => true );
			if ( $run ) {
				$run = BulkRuns::drive( $run );
				$result['summary'] = BulkRuns::summary( $run );
				$result['complete'] = ! empty( $result['summary']['complete'] );
			}
			return $result;
		}
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_cancel_reason', __( 'Bitte einen Grund für die Absage angeben.', 'pass-captain' ) ); }
		if ( ! Forms::flag( $in, 'confirm' ) ) { return Forms::error( 'wpcap_cancel_confirm', __( 'Bitte bestätigen, dass die Folgen der Absage geprüft wurden.', 'pass-captain' ) ); }
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
		$result['complete'] = ! array_key_exists( 'complete', $core ) || ! empty( $core['complete'] );
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
				? sprintf( _n( '%d Ticket wurde widerrufen, der Inhaber benachrichtigt und die bezahlte Bestellung automatisch erstattet. WooCommerce-Bestellungen bitte in WooCommerce erstatten; Fehler stehen im Erstattungs-Menü.', '%d Tickets wurden widerrufen, die Inhaber benachrichtigt und bezahlte Bestellungen automatisch erstattet. WooCommerce-Bestellungen bitte in WooCommerce erstatten; Fehler stehen im Erstattungs-Menü.', $issued, 'pass-captain' ), $issued)
				/* translators: %d: number of tickets */
				: sprintf( _n( '%d Ticket wurde widerrufen und der Inhaber benachrichtigt. Ein bezahltes Ticket bleibt erstattungsfähig: bitte über Bestellung → Erstatten erstatten (oder die automatische Erstattung in den Einstellungen aktivieren).', '%d Tickets wurden widerrufen und die Inhaber benachrichtigt. Bezahlte Tickets bleiben erstattungsfähig: bitte über Bestellung → Erstatten erstatten (oder die automatische Erstattung in den Einstellungen aktivieren).', $issued, 'pass-captain' ), $issued);
		}
		// Large occurrences: revocation is done, the rest (refunds, mails, wallet/webhooks) continues in the background.
		$summary = \PassCaptain\OccurrenceCancellation::last_summary( (int) $occ->occurrence_id );
		if ( ! empty( $summary['queued_jobs'] ) ) {
			$result['queued_jobs'] = (int) $summary['queued_jobs'];
			$result['warning'] = trim( ( $result['warning'] ?? '' ) . ' ' . sprintf(
				/* translators: %d: number of background batches */
				__( 'Alle Tickets sind bereits widerrufen. %d weitere Arbeitspakete (Erstattungen, Benachrichtigungen, Wallet/Webhooks) laufen im Hintergrund weiter; den Fortschritt zeigt der Systemstatus (Hintergrundjobs).', 'pass-captain' ),
				(int) $summary['queued_jobs']
			) );
		}
		return $result;
	}

	/** Editable fields of a ticket type that make up its revision (A531). */
	const TYPE_REVISION_FIELDS = array( 'name', 'price_minor', 'currency', 'tax_rate_bp', 'tax_class', 'capacity', 'max_per_order', 'sales_start', 'sales_end', 'status', 'id_check', 'min_age', 'staff_note' );

	/**
	 * Revision of a ticket type (A531, optimistic locking): hash of its editable fields. The edit form
	 * carries it; saving a form whose revision no longer matches is refused. Counters (sold/held) are
	 * not part of it, so sales never invalidate an open form.
	 */
	public static function type_revision( $type ) {
		$type = (array) $type;
		$fields = array();
		foreach ( self::TYPE_REVISION_FIELDS as $field ) { $fields[ $field ] = isset( $type[ $field ] ) ? (string) $type[ $field ] : ''; }
		return substr( hash( 'sha256', (string) wp_json_encode( $fields ) ), 0, 32 );
	}

	/** Creates or updates a ticket type of an occurrence. */
	public static function save_ticket_type( array $in ) {
		$type_id = Forms::int( $in, 'ticket_type_id' );
		$old = $type_id ? Queries::ticket_type( $type_id ) : null;
		if ( $type_id && ! $old ) { return Forms::error( 'wpcap_type_missing', __( 'Ticketart nicht gefunden.', 'pass-captain' ), 404 ); }
		$occ = Queries::occurrence( $old ? $old->occurrence_id : Forms::int( $in, 'occurrence_id' ) );
		if ( ! $occ ) { return Forms::error( 'wpcap_occurrence_missing', __( 'Termin nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $occ->occurrence_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$name = Forms::text( $in, 'name' );
		if ( '' === $name ) { return Forms::error( 'wpcap_type_name', __( 'Bitte einen Namen für die Ticketart angeben.', 'pass-captain' ), 400, 'name' ); }
		$currency = strtoupper( Forms::text( $in, 'currency', 3 ) );
		if ( ! in_array( $currency, Localization::currencies(), true ) ) { return Forms::error( 'wpcap_type_currency', __( 'Die Währung ist ungültig.', 'pass-captain' ), 400, 'currency' ); }
		$price = Forms::parse_money( $in['price'] ?? '', Forms::currency_decimals( $currency ) );
		if ( null === $price ) { return Forms::error( 'wpcap_type_price', __( 'Der Preis ist ungültig. Beispiel: 12,50', 'pass-captain' ), 400, 'price' ); }
		$tax_bp = Forms::parse_percent_bp( $in['tax_rate'] ?? '' );
		if ( null === $tax_bp ) { return Forms::error( 'wpcap_type_tax', __( 'Der Steuersatz ist ungültig. Beispiel: 19 oder 7,7', 'pass-captain' ), 400, 'tax_rate' ); }
		$max = Forms::int( $in, 'max_per_order', 10 );
		if ( $max < 1 || $max > 100 ) { return Forms::error( 'wpcap_type_max', __( 'Die maximale Anzahl pro Bestellung muss zwischen 1 und 100 liegen.', 'pass-captain' ), 400, 'max_per_order' ); }
		$sales = array();
		foreach ( array( 'sales_start', 'sales_end' ) as $key ) {
			$raw = trim( (string) ( $in[ $key ] ?? '' ) );
			$sales[ $key ] = '' === $raw ? null : Forms::local_to_utc( $raw, $occ->timezone );
			if ( '' !== $raw && null === $sales[ $key ] ) {
				return Forms::error( 'wpcap_type_sales', 'sales_start' === $key ? __( 'Der Verkaufsstart ist ungültig.', 'pass-captain' ) : __( 'Das Verkaufsende ist ungültig.', 'pass-captain' ), 400, $key );
			}
		}
		if ( $sales['sales_start'] && $sales['sales_end'] && $sales['sales_end'] <= $sales['sales_start'] ) {
			return Forms::error( 'wpcap_type_sales', __( 'Das Verkaufsende muss nach dem Verkaufsstart liegen.', 'pass-captain' ), 400, 'sales_end' );
		}
		$checks = \PassCaptain\AdmissionChecks::parse( $in );
		if ( is_wp_error( $checks ) ) { return Forms::error( $checks->get_error_code(), $checks->get_error_message() ); }
		$capacity = Forms::int( $in, 'capacity' );
		$status = Forms::choice( $in, 'status', array( 'active', 'paused' ), 'active' );
		global $wpdb;
		if ( $old ) {
			// A531: check and update under the row lock – sales lock the same row (Orders::create()), so the
			// sold count cannot change between the check and the update; a form opened on an older state
			// of the type (another admin saved meanwhile) is refused instead of overwriting that change.
			$wpdb->query( 'START TRANSACTION' );
			$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", (int) $old->ticket_type_id ) );
			if ( ! $locked ) {
				$busy = '' !== (string) $wpdb->last_error;
				$wpdb->query( 'ROLLBACK' );
				return $busy
					? Forms::error( 'wpcap_type_busy', __( 'Die Ticketart wird gerade verkauft oder bearbeitet. Bitte in ein paar Sekunden erneut speichern.', 'pass-captain' ), 503 )
					: Forms::error( 'wpcap_type_missing', __( 'Ticketart nicht gefunden.', 'pass-captain' ), 404 );
			}
			foreach ( (array) $locked as $column => $value ) { $old->$column = $value; }
			$revision = Forms::text( $in, 'revision', 64 );
			if ( '' !== $revision && ! hash_equals( self::type_revision( $old ), $revision ) ) {
				$wpdb->query( 'ROLLBACK' );
				return Forms::error( 'wpcap_type_changed', __( 'Die Ticketart wurde inzwischen geändert (zum Beispiel von einer anderen Person). Deine Eingaben wurden nicht gespeichert; bitte prüfe sie gegen den aktuellen Stand und speichere erneut.', 'pass-captain' ), 409, 'revision' );
			}
			$refuse = static function ( $error ) use ( $wpdb ) {
				$wpdb->query( 'ROLLBACK' );
				return $error;
			};
			if ( $currency !== $old->currency && Queries::type_has_orders( $old->ticket_type_id ) ) {
				return $refuse( Forms::error( 'wpcap_type_currency_locked', __( 'Die Währung kann nach den ersten Bestellungen nicht mehr geändert werden.', 'pass-captain' ), 409 ) );
			}
			$sold = Queries::sold_for_type( $old->ticket_type_id );
			if ( $capacity > 0 && $capacity < $sold ) {
				/* translators: %d: sold tickets */
				return $refuse( Forms::error( 'wpcap_capacity_below_sold', sprintf( _n( 'Das Kontingent darf nicht unter das bereits verkaufte %d Ticket sinken.', 'Das Kontingent darf nicht unter die bereits verkauften %d Tickets sinken.', $sold, 'pass-captain' ), $sold) ) );
			}
			// A334: price, tax rate or tax class of a type that already has orders: only with an
			// explicit confirmation – the change applies to new orders only.
			$sold_change = self::sold_pricing_changes( $old, $price, $tax_bp, array_key_exists( 'tax_class', $in ) ? substr( sanitize_key( (string) $in['tax_class'] ), 0, 64 ) : null );
			if ( $sold_change && ! Forms::flag( $in, 'confirm_sold_change' ) ) {
				return $refuse( Forms::error( 'wpcap_type_sold_change', self::sold_change_message( $sold, $sold_change ) . ' ' . __( 'Bitte bestätige die Änderung mit dem Häkchen unter dem Formular.', 'pass-captain' ), 409, 'confirm_sold_change' ) );
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
		if ( \PassCaptain\ParticipantFields::name_switch_ready() ) {
			// A552: every ticket of this type needs the attendee's name.
			$data['attendee_name_required'] = Forms::flag( $in, 'attendee_name_required' ) ? 1 : 0;
			$formats[] = '%d';
		}
		if ( array_key_exists( 'visibility', $in ) ) {
			// Sales\Visibility: public | code (unlock code list or a campaign scoped to the type) | hidden.
			$data['visibility'] = Forms::choice( $in, 'visibility', \PassCaptain\Sales\Visibility::VALUES, 'public' );
			$data['access_codes'] = implode( "\n", \PassCaptain\Sales\Visibility::parse_codes( (string) ( $in['access_codes'] ?? '' ) ) );
			$formats[] = '%s';
			$formats[] = '%s';
		}
		// Buyer-facing description, display order and the attendee-name option (DB 3.17.5); only when
		// submitted, so callers that do not know these fields keep the stored values.
		if ( \PassCaptain\Orders::schema_at_least( '3.17.5' ) ) {
			if ( array_key_exists( 'description', $in ) ) {
				$data['description'] = Forms::textarea( $in, 'description', 1000 );
				$formats[] = '%s';
			}
			if ( array_key_exists( 'position', $in ) ) {
				$data['position'] = min( 65535, max( 0, Forms::int( $in, 'position' ) ) );
				$formats[] = '%d';
			}
			if ( array_key_exists( 'attendee_name', $in ) ) {
				$data['attendee_name'] = Forms::choice( $in, 'attendee_name', array( 'off', 'optional', 'required' ), 'optional' );
				$formats[] = '%s';
			}
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_ticket_types';
		if ( $old ) {
			$updated = $wpdb->update( $table, $data, array( 'ticket_type_id' => (int) $old->ticket_type_id ), $formats, array( '%d' ) );
			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return Forms::error( 'wpcap_type_save', __( 'Die Ticketart konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
			}
			$wpdb->query( 'COMMIT' );
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
			if ( ! $type_id ) { return Forms::error( 'wpcap_type_save', __( 'Die Ticketart konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		}
		$details = array( 'occurrence_id' => (int) $occ->occurrence_id, 'price_minor' => $price, 'tax_rate_bp' => $tax_bp, 'status' => $status, 'currency' => $currency, 'type_name' => $name, 'tax_class' => (string) ( $data['tax_class'] ?? ( $old->tax_class ?? '' ) ) );
		if ( $old ) {
			// A114: price, capacity and sales window changes (old → new) for the occurrence's history.
			$details['changes'] = ChangeHistory::diff( (array) $old, array_intersect_key( $data, array_flip( array( 'name', 'price_minor', 'currency', 'tax_rate_bp', 'tax_class', 'capacity', 'max_per_order', 'sales_start', 'sales_end', 'status' ) ) ) );
			if ( isset( $details['changes']['currency'] ) ) { $details['currency'] = (string) $old->currency; }
		}
		Audit::record( $old ? 'ticket_type_updated' : 'ticket_type_created', 'ticket_type', $type_id, $details );
		if ( $old && ! empty( $details['changes'] ) ) {
			/**
			 * After an existing ticket type was saved with changes (A582, e.g. the name on wallet passes).
			 *
			 * @param int   $ticket_type_id
			 * @param array $changes field => [old, new].
			 */
			do_action( 'wpcap_ticket_type_updated', $type_id, (array) $details['changes'] );
		}
		\PassCaptain\Reports\Cache::flush_event( (int) $occ->event_id );
		// A446: price, sales window, status or capacity changed what the listing shows.
		\PassCaptain\PageCache::event_changed( (int) $occ->event_id, 'ticket_type', (int) $occ->occurrence_id );
		// A535: a sales end after the occurrence's (effective) end is saved, but online sales stop at the end.
		$warning = \PassCaptain\Occurrence::sales_end_warning( $occ, (string) $sales['sales_end'] );
		if ( ! empty( $sold_change ) ) {
			// A334: the confirmed change is recorded with what it did not change.
			$warning = trim( self::sold_change_message( (int) $sold, $sold_change ) . ' ' . $warning );
		}
		$result = array( 'ticket_type_id' => $type_id, 'occurrence_id' => (int) $occ->occurrence_id, 'event_id' => (int) $occ->event_id );
		if ( Forms::flag( $in, 'apply_all' ) && empty( $in['_wpcap_sibling'] ) ) {
			// A333: the same change for the same-named ticket type of every upcoming date of the event.
			$result += self::apply_type_to_siblings( $in, $old, $occ, $sales );
			if ( ! empty( $result['skipped'] ) ) { $warning = trim( $warning . ' ' . implode( ' ', $result['skipped'] ) ); }
		}
		return $result + ( '' !== $warning ? array( 'warning' => $warning ) : array() );
	}

	/**
	 * Upcoming scheduled occurrences of an event other than $except, by start.
	 *
	 * @return object[]
	 */
	public static function upcoming_occurrences( $event_id, $except = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT occurrence_id,starts_at,timezone FROM {$p}wpcap_occurrences WHERE event_id=%d AND occurrence_id<>%d AND status='scheduled' AND starts_at>=%s ORDER BY starts_at,occurrence_id", absint( $event_id ), absint( $except ), \PassCaptain\Clock::mysql() ) );
	}

	/**
	 * Ticket types named $name on the other upcoming dates of the event (A333: "auf alle Termine
	 * anwenden"); archived types are left out.
	 *
	 * @return object[] ticket type rows with occurrence_starts_at and timezone.
	 */
	public static function sibling_types( $event_id, $except_occurrence, $name ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.*,o.timezone,o.starts_at AS occurrence_starts_at FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND o.occurrence_id<>%d AND o.status='scheduled' AND o.starts_at>=%s AND tt.name=%s AND tt.status<>'archived' ORDER BY o.starts_at,tt.ticket_type_id", absint( $event_id ), absint( $except_occurrence ), \PassCaptain\Clock::mysql(), (string) $name ) );
	}

	/**
	 * A333: applies a saved ticket type to the same-named type of every other upcoming date of the
	 * event (through save_ticket_type(), so every check – currency lock, capacity, A334
	 * confirmation – runs per date). Existing types take over name, price, currency, tax, maximum
	 * per order, status and admission checks and keep their own capacity and sales window; a new
	 * type is created on every upcoming date that has none of that name, with the sales window
	 * moved with the date.
	 *
	 * @param array       $in    Submitted form.
	 * @param object|null $old   Type before the change (null: new type).
	 * @param object      $occ   Occurrence of the edited type.
	 * @param array       $sales UTC sales_start/sales_end of the edited type.
	 * @return array{applied:int, skipped:string[]}
	 */
	private static function apply_type_to_siblings( array $in, $old, $occ, array $sales ) {
		$applied = 0;
		$skipped = array();
		$base = array_diff_key( $in, array_flip( array( 'apply_all', 'ticket_type_id', 'occurrence_id', 'capacity', 'sales_start', 'sales_end', 'revision' ) ) );
		$base['_wpcap_sibling'] = 1;
		$jobs = array();
		if ( $old ) {
			foreach ( self::sibling_types( (int) $occ->event_id, (int) $occ->occurrence_id, (string) $old->name ) as $type ) {
				$jobs[] = array( $type, $base + array(
					'ticket_type_id' => (int) $type->ticket_type_id,
					'capacity' => (int) $type->capacity,
					'sales_start' => Forms::utc_to_local( $type->sales_start, $type->timezone ),
					'sales_end' => Forms::utc_to_local( $type->sales_end, $type->timezone ),
				) );
			}
		} else {
			$name = Forms::text( $in, 'name' );
			$shift = static function ( $utc, $target ) use ( $occ ) {
				if ( empty( $utc ) ) { return ''; }
				$moved = strtotime( $utc . ' UTC' ) + ( strtotime( $target->starts_at . ' UTC' ) - strtotime( $occ->starts_at . ' UTC' ) );
				return Forms::utc_to_local( gmdate( 'Y-m-d H:i:s', $moved ), $target->timezone );
			};
			$taken = array();
			foreach ( self::sibling_types( (int) $occ->event_id, (int) $occ->occurrence_id, $name ) as $type ) { $taken[ (int) $type->occurrence_id ] = true; }
			foreach ( self::upcoming_occurrences( (int) $occ->event_id, (int) $occ->occurrence_id ) as $target ) {
				if ( isset( $taken[ (int) $target->occurrence_id ] ) ) { continue; }
				$jobs[] = array( $target, $base + array(
					'ticket_type_id' => 0,
					'occurrence_id' => (int) $target->occurrence_id,
					'capacity' => Forms::int( $in, 'capacity' ),
					'sales_start' => $shift( $sales['sales_start'], $target ),
					'sales_end' => $shift( $sales['sales_end'], $target ),
				) );
			}
		}
		foreach ( $jobs as $job ) {
			list( $target, $sub ) = $job;
			$done = self::save_ticket_type( $sub );
			if ( is_wp_error( $done ) ) {
				$when = Forms::datetime( $target->occurrence_starts_at ?? $target->starts_at, $target->timezone );
				/* translators: 1: date of the occurrence, 2: reason */
				$skipped[] = sprintf( __( 'Nicht übernommen für %1$s: %2$s', 'pass-captain' ), $when, $done->get_error_message() );
				continue;
			}
			++$applied;
		}
		return array( 'applied' => $applied, 'skipped' => $skipped );
	}

	/** Success notice of a save with "auf alle Termine anwenden" (A333). */
	private static function applied_notice( $base, $result ) {
		if ( ! is_array( $result ) || ! isset( $result['applied'] ) ) { return $base; }
		/* translators: %d: number of other dates */
		return $base . ' ' . sprintf( _n( 'Auf %d weiteren Termin übernommen.', 'Auf %d weitere Termine übernommen.', (int) $result['applied'], 'pass-captain' ), (int) $result['applied'] );
	}

	/**
	 * Price-relevant fields that change on a ticket type with orders (A334): price_minor,
	 * tax_rate_bp, tax_class ($tax_class null = not submitted). Empty without orders.
	 *
	 * @return string[]
	 */
	public static function sold_pricing_changes( $old, $price, $tax_bp, $tax_class = null ) {
		$changed = array();
		if ( (int) $price !== (int) $old->price_minor ) { $changed[] = 'price_minor'; }
		if ( (int) $tax_bp !== (int) $old->tax_rate_bp ) { $changed[] = 'tax_rate_bp'; }
		if ( null !== $tax_class && (string) $tax_class !== (string) ( $old->tax_class ?? '' ) ) { $changed[] = 'tax_class'; }
		return $changed && Queries::type_has_orders( (int) $old->ticket_type_id ) ? $changed : array();
	}

	/** "Bereits N Tickets verkauft: der neue Preis gilt nur für neue Bestellungen …" (A334). */
	private static function sold_change_message( $sold, array $changed ) {
		$labels = array( 'price_minor' => __( 'Preis', 'pass-captain' ), 'tax_rate_bp' => __( 'Steuersatz', 'pass-captain' ), 'tax_class' => __( 'Steuerklasse', 'pass-captain' ) );
		$what = implode( ', ', array_intersect_key( $labels, array_flip( $changed ) ) );
		return sprintf(
			/* translators: 1: number of sold tickets, 2: changed fields, e.g. "Preis, Steuersatz" */
			_n( 'Von dieser Ticketart ist bereits %1$d Ticket verkauft (bzw. Bestellungen sind offen): Die Änderung (%2$s) gilt nur für neue Bestellungen. Bestehende Bestellungen, Tickets, Rechnungen und Erstattungen behalten Preis und Steuer der Bestellung.', 'Von dieser Ticketart sind bereits %1$d Tickets verkauft (bzw. Bestellungen sind offen): Die Änderung (%2$s) gilt nur für neue Bestellungen. Bestehende Bestellungen, Tickets, Rechnungen und Erstattungen behalten Preis und Steuer der Bestellung.', (int) $sold, 'pass-captain' ),
			(int) $sold,
			$what
		);
	}

	/** Whether the module "Serientermine" is on (A333: the event forms point to it). */
	public static function series_available() {
		return class_exists( '\\PassCaptain\\Series\\AdminPage' ) && \PassCaptain\Modules::enabled( \PassCaptain\Modules\Series::class );
	}

	/** Creates or updates a participant field. The key is fixed after creation. */
	public static function save_participant_field( array $in ) {
		$field_id = Forms::int( $in, 'field_id' );
		$old = $field_id ? Queries::participant_field( $field_id ) : null;
		if ( $field_id && ! $old ) { return Forms::error( 'wpcap_field_missing', __( 'Feld nicht gefunden.', 'pass-captain' ), 404 ); }
		$type = Queries::ticket_type( $old ? $old->ticket_type_id : Forms::int( $in, 'ticket_type_id' ) );
		if ( ! $type ) { return Forms::error( 'wpcap_type_missing', __( 'Ticketart nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $type->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$label = Forms::text( $in, 'label' );
		$field_type = Forms::choice( $in, 'field_type', self::FIELD_TYPES, '' );
		if ( '' === $label || '' === $field_type ) { return Forms::error( 'wpcap_field_invalid', __( 'Bitte Bezeichnung und Feldtyp angeben.', 'pass-captain' ) ); }
		$retention = Forms::int( $in, 'retention_days', 365 );
		if ( $retention > 3650 ) { return Forms::error( 'wpcap_field_retention', __( 'Die Aufbewahrungsdauer darf höchstens 3650 Tage betragen.', 'pass-captain' ) ); }
		// Consent (A116): a checkbox with a stated purpose; grants and withdrawals are logged.
		$consent = \PassCaptain\Consents::ready() && Forms::flag( $in, 'consent' );
		if ( $consent && 'checkbox' !== $field_type ) { return Forms::error( 'wpcap_field_consent_type', __( 'Eine Einwilligung muss den Typ Checkbox haben.', 'pass-captain' ) ); }
		if ( $consent && '' === Forms::text( $in, 'purpose' ) ) { return Forms::error( 'wpcap_field_consent_purpose', __( 'Bitte gib für die Einwilligung den Zweck an. Er wird beim Ankreuzen angezeigt und mit protokolliert.', 'pass-captain' ) ); }
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
		$fields_ready = \PassCaptain\ParticipantFields::ready();
		if ( $fields_ready ) {
			// A680: choices (select/multi-select), help text and scope (per ticket or once per order).
			$options = array();
			if ( in_array( $field_type, \PassCaptain\ParticipantFields::CHOICE_TYPES, true ) ) {
				$options = \PassCaptain\ParticipantFields::parse_options( $in['options'] ?? '', $field_type );
				if ( is_wp_error( $options ) ) { return Forms::error( $options->get_error_code(), $options->get_error_message() ); }
			}
			$scope = Forms::choice( $in, 'scope', \PassCaptain\ParticipantFields::SCOPES, 'ticket' );
			if ( 'order' === $scope && $consent ) { return Forms::error( 'wpcap_field_consent_scope', __( 'Eine Einwilligung gilt je Ticketperson und kann nicht für die ganze Bestellung abgefragt werden.', 'pass-captain' ) ); }
			$data += array( 'options' => $options ? (string) wp_json_encode( array_values( $options ) ) : '', 'help_text' => Forms::text( $in, 'help_text' ), 'scope' => $scope );
			array_push( $formats, '%s', '%s', '%s' );
		}
		if ( \PassCaptain\ParticipantFields::locks_ready() ) {
			// A683: self-service per field – changeable or locked, optionally only until N hours before the start.
			$edit_hours = Forms::int( $in, 'edit_hours' );
			if ( $edit_hours > 8760 ) { return Forms::error( 'wpcap_field_edit_hours', __( 'Die Änderungsfrist darf höchstens 8760 Stunden (ein Jahr) betragen.', 'pass-captain' ) ); }
			$data += array( 'self_service' => Forms::choice( $in, 'self_service', array( 'edit', 'locked' ), 'edit' ), 'edit_hours' => max( 0, $edit_hours ) );
			array_push( $formats, '%s', '%d' );
		}
		global $wpdb;
		$json_choices = $fields_ready && in_array( $field_type, \PassCaptain\ParticipantFields::CHOICE_TYPES, true );
		if ( ! $json_choices && in_array( $field_type, self::CHOICE_TYPES, true ) ) {
			// Choices, one per line (max. 50 × 190 characters); the checkout only accepts one of them.
			$choices = array_slice( \PassCaptain\Orders::field_options( array( 'options' => Forms::textarea( $in, 'options', 10000 ) ) ), 0, 50 );
			$choices = array_values( array_unique( array_map( static function ( $c ) { return mb_substr( $c, 0, 190 ); }, $choices ) ) );
			if ( count( $choices ) < 2 ) {
				return Forms::error( 'wpcap_field_options', __( 'Für Auswahlliste und Optionsfelder bitte mindestens zwei Auswahlmöglichkeiten angeben (eine pro Zeile).', 'pass-captain' ) );
			}
			if ( ! array_key_exists( 'options', $data ) ) { $formats[] = '%s'; }
			$data['options'] = implode( "\n", $choices );
		} elseif ( ! $fields_ready && \PassCaptain\Orders::schema_at_least( '3.17.5' ) ) {
			$data['options'] = null;
			$formats[] = '%s';
		}
		if ( 'consent' === $field_type ) {
			// Logged consent (Sales\Consents): text and version are stored with every consent record.
			$consent_text = Forms::textarea( $in, 'consent_text', 2000 );
			$consent_version = Forms::text( $in, 'consent_version', 32 );
			if ( '' === $consent_text || ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $consent_version ) ) {
				return Forms::error( 'wpcap_field_consent', __( 'Für ein Einwilligungsfeld bitte Einwilligungstext und Version (z. B. 1 oder 2026-11) angeben.', 'pass-captain' ) );
			}
			if ( $old && (string) $old->consent_text !== $consent_text && (string) $old->consent_version === $consent_version && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE field_id=%d', $wpdb->prefix . 'wpcap_consents', (int) $old->field_id ) ) ) {
				return Forms::error( 'wpcap_field_consent_version', __( 'Der Einwilligungstext wurde geändert: Bitte auch die Version ändern, damit bestehende Einwilligungen ihrer Textfassung zugeordnet bleiben.', 'pass-captain' ) );
			}
			$data['consent_text'] = $consent_text;
			$data['consent_version'] = $consent_version;
			$formats[] = '%s';
			$formats[] = '%s';
		}
		$table = $wpdb->prefix . 'wpcap_participant_fields';
		$version = 1;
		if ( $old ) {
			if ( $fields_ready ) {
				// A680: a changed definition is a new version; answers keep the version they were given under.
				$version = (int) ( $old->version ?? 1 ) + ( \PassCaptain\ParticipantFields::version_changed( $old, $data ) ? 1 : 0 );
				$data['version'] = max( 1, $version );
				$formats[] = '%d';
			}
			$wpdb->update( $table, $data, array( 'field_id' => (int) $old->field_id ), $formats, array( '%d' ) );
			$field_id = (int) $old->field_id;
		} else {
			$key = substr( sanitize_key( (string) ( $in['field_key'] ?? '' ) ), 0, 64 );
			$key = str_replace( '-', '_', $key );
			if ( '' === $key ) { return Forms::error( 'wpcap_field_key', __( 'Bitte einen technischen Schlüssel angeben (a-z, 0-9, _).', 'pass-captain' ) ); }
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT field_id FROM %i WHERE ticket_type_id=%d AND field_key=%s', $table, (int) $type->ticket_type_id, $key ) );
			if ( $exists ) { return Forms::error( 'wpcap_field_key_taken', __( 'Dieser Schlüssel ist für die Ticketart bereits vergeben.', 'pass-captain' ), 409 ); }
			$data['ticket_type_id'] = (int) $type->ticket_type_id;
			$data['field_key'] = $key;
			$data['status'] = 'active';
			$wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%s', '%s' ) ) );
			$field_id = (int) $wpdb->insert_id;
			if ( ! $field_id ) { return Forms::error( 'wpcap_field_save', __( 'Das Feld konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		}
		$details = array( 'field_id' => $field_id, 'required' => $data['required'], 'retention_days' => $retention );
		if ( $fields_ready ) {
			// The audit log keeps every version of the definition (A680).
			$details += array( 'version' => $version, 'field_type' => $field_type, 'label' => $label, 'scope' => $data['scope'], 'options' => $data['options'] ? json_decode( $data['options'], true ) : array() );
		}
		if ( $consent ) {
			// A changed consent text is a new version; records name the version that was shown.
			$details['consent_text_version'] = \PassCaptain\Consents::ensure_text( (object) array( 'field_id' => $field_id, 'label' => $label, 'purpose' => $data['purpose'] ) )['text_version'];
		}
		Audit::record( $old ? 'participant_field_updated' : 'participant_field_created', 'ticket_type', (int) $type->ticket_type_id, $details );
		$result = array( 'field_id' => $field_id, 'ticket_type_id' => (int) $type->ticket_type_id );
		if ( Forms::flag( $in, 'apply_all' ) && empty( $in['_wpcap_sibling'] ) ) {
			// A333: the same field on the same-named ticket type of every upcoming date (matched by key).
			$key = $old ? (string) $old->field_key : (string) $data['field_key'];
			$sub = array_diff_key( $in, array_flip( array( 'apply_all', 'field_id', 'ticket_type_id', 'field_key' ) ) ) + array( '_wpcap_sibling' => 1 );
			$result['applied'] = 0;
			$skipped = array();
			foreach ( self::sibling_types( (int) $type->event_id, (int) $type->occurrence_id, (string) $type->name ) as $sibling ) {
				$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT field_id FROM $table WHERE ticket_type_id=%d AND field_key=%s", (int) $sibling->ticket_type_id, $key ) );
				$done = self::save_participant_field( $sub + ( $existing ? array( 'field_id' => $existing ) : array( 'ticket_type_id' => (int) $sibling->ticket_type_id, 'field_key' => $key ) ) );
				if ( is_wp_error( $done ) ) {
					/* translators: 1: date of the occurrence, 2: reason */
					$skipped[] = sprintf( __( 'Nicht übernommen für %1$s: %2$s', 'pass-captain' ), Forms::datetime( $sibling->occurrence_starts_at, $sibling->timezone ), $done->get_error_message() );
					continue;
				}
				++$result['applied'];
			}
			if ( $skipped ) { $result['warning'] = implode( ' ', $skipped ); }
		}
		return $result;
	}

	/** Labels of the participant field types (A680). */
	public static function field_type_labels() {
		return array(
			'text' => __( 'Text', 'pass-captain' ),
			'email' => __( 'E-Mail', 'pass-captain' ),
			'textarea' => __( 'Mehrzeiliger Text', 'pass-captain' ),
			'checkbox' => __( 'Checkbox', 'pass-captain' ),
			'select' => __( 'Auswahl', 'pass-captain' ),
			'multiselect' => __( 'Mehrfachauswahl', 'pass-captain' ),
			'tel' => __( 'Telefon', 'pass-captain' ),
			'date' => __( 'Datum', 'pass-captain' ),
			'number' => __( 'Zahl', 'pass-captain' ),
			'radio' => __( 'Optionsfelder (eine Auswahl)', 'pass-captain' ),
			'consent' => __( 'Einwilligung (protokolliert)', 'pass-captain' ),
		);
	}

	/** Activates or deactivates a participant field (answers are kept). */
	public static function set_participant_field_status( array $in ) {
		$field = Queries::participant_field( Forms::int( $in, 'field_id' ) );
		if ( ! $field ) { return Forms::error( 'wpcap_field_missing', __( 'Feld nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $field->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$status = Forms::choice( $in, 'status', array( 'active', 'inactive' ), 'inactive' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_participant_fields', array( 'status' => $status ), array( 'field_id' => (int) $field->field_id ), array( '%s' ), array( '%d' ) );
		Audit::record( 'participant_field_status', 'ticket_type', (int) $field->ticket_type_id, array( 'field_id' => (int) $field->field_id, 'status' => $status ) );
		return array( 'field_id' => (int) $field->field_id, 'ticket_type_id' => (int) $field->ticket_type_id );
	}

	/**
	 * Assigns event-scoped access to a user. The account must be assignable (A471, A636:
	 * StaffAssignments::assignable_user() – member of this site and, for event managers without
	 * `list_users`, already holding a Pass Captain role); every refused account gets the same answer.
	 * "Event verwalten" includes delegating the event's rights within the event (CONCEPT.md).
	 */
	public static function assign_staff( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$event_id = Forms::int( $in, 'event_id' );
		$capability = Forms::choice( $in, 'capability', self::STAFF_CAPS, '' );
		if ( ! $event_id || '' === $capability || ! Queries::event( $event_id ) ) {
			return Forms::error( 'wpcap_staff_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) );
		}
		// Access first: people without rights for the event learn nothing about accounts.
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$user = \PassCaptain\StaffAssignments::assignable_user( (string) ( $in['user'] ?? ( $in['user_id'] ?? '' ) ) );
		if ( ! $user ) { return \PassCaptain\StaffAssignments::assignable_error( 'wpcap_staff_invalid' ); }
		if ( 'wpcap_checkin' === $capability ) {
			// Taken back with the last check-in assignment and on every role removal (UserAccess, A277).
			$user->add_cap( 'wpcap_checkin' );
		} elseif ( ! user_can( $user, $capability ) ) {
			// Event scope never grants a role capability implicitly; the user needs a matching role.
			// Callers who cannot list users get the same answer as for an unknown account (A636).
			if ( ! current_user_can( 'list_users' ) ) { return \PassCaptain\StaffAssignments::assignable_error( 'wpcap_staff_invalid' ); }
			return Forms::error( 'wpcap_staff_role', __( 'Der Benutzer hat dieses Recht nicht über seine Rolle (z. B. Pass Captain Veranstaltungsleitung). Bitte zuerst die Rolle zuweisen.', 'pass-captain' ) );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_event_staff (event_id,user_id,capability) VALUES (%d,%d,%s)", $event_id, (int) $user->ID, $capability ) );
		\PassCaptain\StaffAssignments::flush();
		Audit::record( 'staff_assigned', 'event', $event_id, array( 'user_id' => (int) $user->ID, 'capability' => $capability ) );
		return array( 'event_id' => $event_id, 'user_id' => (int) $user->ID );
	}

	/**
	 * assign_staff() grants wpcap_checkin as a user capability; take it back once the user has no
	 * check-in assignment left. A capability granted by one of the user's roles is left alone.
	 */
	public static function maybe_revoke_checkin_cap( $user_id ) {
		global $wpdb;
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id=%d AND capability='wpcap_checkin'", $wpdb->prefix . 'wpcap_event_staff', $user_id ) );
		// Date-level assignments (SecurityScreens, wpcap_occurrence_staff) keep the capability as well.
		$remaining += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id=%d AND capability='wpcap_checkin'", $wpdb->prefix . 'wpcap_occurrence_staff', $user_id ) );
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
		if ( ! $event_id || ! $user_id || '' === $capability ) { return Forms::error( 'wpcap_staff_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wpcap_event_staff', array( 'event_id' => $event_id, 'user_id' => $user_id, 'capability' => $capability ), array( '%d', '%d', '%s' ) );
		// Occurrence assignments only narrow this team entry (A144); without it they would be stale.
		\PassCaptain\StaffAssignments::delete_for_team( $event_id, $user_id, $capability );
		if ( 'wpcap_checkin' === $capability ) { self::maybe_revoke_checkin_cap( $user_id ); }
		Audit::record( 'staff_removed', 'event', $event_id, array( 'user_id' => $user_id, 'capability' => $capability ) );
		return array( 'event_id' => $event_id );
	}

	/**
	 * What cancelling a whole event would do (A267, shown before the irreversible action).
	 *
	 * @return array occurrences (not yet cancelled), tickets (valid), paid_orders (native),
	 *               woo_orders, pending_orders, recipients (buyers/attendees of paid and open orders),
	 *               auto_refund (bool).
	 */
	public static function event_cancellation_preview( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$join = "FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE o.event_id=%d AND t.status='valid'";
		$items = "FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id AND t.ticket_type_id=tt.ticket_type_id WHERE o.event_id=%d";
		return array(
			'occurrences' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d AND status<>'cancelled'", $event_id ) ),
			'tickets' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $join", $event_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragment.
			'paid_orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ord.order_id) $join AND ord.payment_status='paid' AND ord.payment_provider<>'woocommerce'", $event_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'woo_orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ord.order_id) $join AND ord.payment_status='paid' AND ord.payment_provider='woocommerce'", $event_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'pending_orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ord.order_id) $items AND ord.payment_status='pending'", $event_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'recipients' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email)) $items AND ord.payment_status IN ('paid','pending','paid_unallocated')", $event_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'auto_refund' => (bool) \PassCaptain\Settings::get( 'auto_refund_cancelled' ),
		);
	}

	/**
	 * Cancels a published event (A267: only with a reason and the explicit confirmation of the
	 * consequences shown on the confirmation page): all occurrences, tickets revoked, buyers informed.
	 */
	public static function cancel_event( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$event = Queries::event( $event_id );
		if ( $event && 'cancelled' === $event->status ) {
			// A428: a second click continues an unfinished cancellation.
			$result = Orders::resume_event_cancellation( $event_id );
			return is_wp_error( $result ) ? Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ) : $result;
		}
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_cancel_reason', __( 'Bitte einen Grund für die Absage angeben.', 'pass-captain' ) ); }
		if ( ! Forms::flag( $in, 'confirm' ) ) { return Forms::error( 'wpcap_cancel_confirm', __( 'Bitte bestätigen, dass die Folgen der Absage geprüft wurden.', 'pass-captain' ) ); }
		$result = Orders::cancel_event( $event_id, $reason );
		\PassCaptain\Reports\Cache::flush_event( $event_id );
		return is_wp_error( $result ) ? Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ) : $result;
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	private static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	public static function event_url( $event_id ) {
		return self::url( array( 'view' => 'event', 'event_id' => absint( $event_id ) ) );
	}

	public static function handle_create_event() {
		Forms::verify( 'wpcap_create_event' );
		$result = self::create_event( Forms::post() );
		Forms::finish( $result, __( 'Event gespeichert.', 'pass-captain' ), is_wp_error( $result ) ? self::url() : self::event_url( $result['event_id'] ) );
	}

	public static function handle_update_event() {
		$in = Forms::post();
		Forms::verify( 'wpcap_update_event' );
		Forms::finish( self::update_event( $in ), __( 'Event aktualisiert.', 'pass-captain' ), self::event_url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_save_occurrence() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_occurrence' );
		$result = self::save_occurrence( $in );
		$message = __( 'Termin gespeichert.', 'pass-captain' );
		if ( ! is_wp_error( $result ) && $result['rescheduled'] ) {
			$message .= ' ' . __( 'Der Termin hat verkaufte Tickets; die Verschiebung wurde gemeldet.', 'pass-captain' );
			// Reschedule mails run as a bulk run (Notifications/BulkRuns); its progress is shown on the occurrence screen.
			$runs = BulkRuns::for_object( 'notify', (int) $result['occurrence_id'], 1 );
			$summary = $runs ? (array) BulkRuns::summary( $runs[0] ) : array();
			if ( $summary ) {
				$total = (int) ( $summary['mails_total'] ?? 0 );
				$waiting = (int) ( $summary['mails_waiting'] ?? 0 );
				$failed = (int) ( $summary['mails_failed'] ?? 0 );
				$message .= self::notification_progress( array( 'sent' => max( 0, $total - $waiting - $failed ), 'queued' => $waiting, 'failed' => $failed ) );
			}
		}
		$back = Forms::int( $in, 'occurrence_id' ) ? self::url( array( 'view' => 'occurrence', 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) ) : self::event_url( Forms::int( $in, 'event_id' ) );
		Forms::finish( $result, $message, is_wp_error( $result ) ? $back : self::url( array( 'view' => 'occurrence', 'occurrence_id' => $result['occurrence_id'] ) ), $back );
	}

	/** " N versendet, M in der Warteschlange" for a notice (Communications::progress() counters). */
	private static function notification_progress( array $progress ) {
		$total = array_sum( array_map( 'intval', $progress ) );
		if ( ! $total ) { return ''; }
		$pending = (int) ( $progress['queued'] ?? 0 ) + (int) ( $progress['sending'] ?? 0 );
		/* translators: 1: mails sent, 2: mails waiting in the queue, 3: failed mails */
		return ' ' . sprintf( __( 'Benachrichtigungen: %1$d versendet, %2$d werden im Hintergrund versendet, %3$d fehlgeschlagen (Systemstatus → E-Mail-Queue).', 'pass-captain' ), (int) ( $progress['sent'] ?? 0 ), $pending, (int) ( $progress['failed'] ?? 0 ) );
	}

	public static function handle_cancel_occurrence() {
		$in = Forms::post();
		Forms::verify( 'wpcap_cancel_occurrence' );
		$result = self::cancel_occurrence( $in );
		$message = __( 'Termin abgesagt.', 'pass-captain' );
		if ( ! is_wp_error( $result ) && ! empty( $result['summary'] ) ) {
			$s = $result['summary'];
			/* translators: 1: cancelled open orders, 2: revoked tickets, 3: refunds, 4: refunds open/failed, 5: notified holders */
			$message = sprintf( __( 'Termin abgesagt: %1$d offene Bestellungen storniert, %2$d Tickets widerrufen, %3$d Erstattungen ausgeführt, %4$d Erstattungen offen, %5$d Personen benachrichtigt.', 'pass-captain' ), $s['cancelled_orders'], $s['revoked'], $s['refunds'], $s['refund_errors'], $s['notified'] );
		}
		if ( ! is_wp_error( $result ) ) { $message .= self::progress_note( $result ); }
		$back = self::url( array( 'view' => 'cancel_occurrence', 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) );
		Forms::finish( $result, $message, is_wp_error( $result ) ? $back : self::url( array( 'view' => 'occurrence', 'occurrence_id' => $result['occurrence_id'] ) ), $back );
	}

	public static function handle_save_ticket_type() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_ticket_type' );
		$result = self::save_ticket_type( $in );
		$back = Forms::int( $in, 'ticket_type_id' ) ? self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ) ) ) : self::url( array( 'view' => 'occurrence', 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) );
		Forms::finish( $result, self::applied_notice( __( 'Ticketart gespeichert.', 'pass-captain' ), $result ), is_wp_error( $result ) ? $back : self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $result['ticket_type_id'] ) ), $back );
	}

	public static function handle_save_participant_field() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_participant_field' );
		$result = self::save_participant_field( $in );
		$type_id = is_wp_error( $result ) ? Forms::int( $in, 'ticket_type_id' ) : $result['ticket_type_id'];
		Forms::finish( $result, self::applied_notice( __( 'Teilnehmerfeld gespeichert.', 'pass-captain' ), $result ), self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $type_id ) ) );
	}

	public static function handle_participant_field_status() {
		$in = Forms::post();
		Forms::verify( 'wpcap_participant_field_status' );
		$result = self::set_participant_field_status( $in );
		$type_id = is_wp_error( $result ) ? Forms::int( $in, 'ticket_type_id' ) : $result['ticket_type_id'];
		Forms::finish( $result, __( 'Teilnehmerfeld aktualisiert.', 'pass-captain' ), self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $type_id ) ) );
	}

	public static function handle_assign_staff() {
		$in = Forms::post();
		Forms::verify( 'wpcap_assign_staff' );
		$event_id = Forms::int( $in, 'event_id' );
		Forms::finish( self::assign_staff( $in ), __( 'Zugriff zugewiesen.', 'pass-captain' ), $event_id ? self::event_url( $event_id ) : self::url() );
	}

	public static function handle_remove_staff() {
		$in = Forms::post();
		Forms::verify( 'wpcap_remove_staff' );
		Forms::finish( self::remove_staff( $in ), __( 'Zugriff entfernt.', 'pass-captain' ), self::event_url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_cancel_event() {
		$in = Forms::post();
		$event_id = Forms::int( $in, 'event_id' );
		Forms::verify( 'wpcap_cancel_event_' . $event_id );
		$result = self::cancel_event( $in );
		/* translators: %d: notified buyers */
		$message = is_wp_error( $result ) ? '' : sprintf( __( 'Event abgesagt. %d Käufer wurden benachrichtigt.', 'pass-captain' ), (int) ( $result['notified_count'] ?? 0 ) ) . self::notification_progress( (array) ( $result['notifications'] ?? array() ) );
		if ( ! is_wp_error( $result ) && ! empty( $result['revoked'] ) ) {
			$message .= ' ' . ( ! empty( $result['auto_refund'] )
				/* translators: 1: automatic refunds, 2: failed refunds */
				? sprintf( __( '%1$d Bestellungen wurden automatisch erstattet, %2$d Erstattungen sind offen (siehe Erstattungen). WooCommerce-Bestellungen bitte in WooCommerce erstatten.', 'pass-captain' ), (int) $result['refunds'], (int) $result['refund_errors'] )
				: __( 'Bezahlte Tickets bleiben erstattungsfähig: bitte über Bestellung → Erstatten erstatten.', 'pass-captain' ) );
		}
		if ( ! is_wp_error( $result ) ) { $message .= self::progress_note( $result ); }
		$back = self::url( array( 'view' => 'cancel_event', 'event_id' => $event_id ) );
		Forms::finish( $result, $message, is_wp_error( $result ) ? $back : self::event_url( $event_id ), $back );
	}

	/** Note about an unfinished (background) or resumed cancellation run (A428). */
	private static function progress_note( array $result ) {
		if ( isset( $result['complete'] ) && ! $result['complete'] ) {
			return ' ' . __( 'Die Absage ist noch nicht abgeschlossen und läuft im Hintergrund weiter; den Fortschritt siehst du unten. Ein erneuter Klick setzt sie sofort fort.', 'pass-captain' );
		}
		return ! empty( $result['resumed'] ) ? ' ' . __( 'Die Absage wurde fortgesetzt.', 'pass-captain' ) : '';
	}

	/** admin-post: continues a cancellation or notification run now (A428). */
	public static function handle_resume_bulk_run() {
		$in = Forms::post();
		$run_id = Forms::int( $in, 'run_id' );
		Forms::verify( 'wpcap_resume_bulk_run_' . $run_id );
		$result = self::resume_bulk_run( $in );
		$url = self::url();
		if ( ! is_wp_error( $result ) ) {
			$url = 'event_cancel' === $result['kind'] ? self::event_url( $result['object_id'] ) : self::url( array( 'view' => 'occurrence', 'occurrence_id' => $result['object_id'] ) );
		}
		Forms::finish( $result, ! is_wp_error( $result ) && $result['complete'] ? __( 'Vorgang abgeschlossen.', 'pass-captain' ) : __( 'Vorgang fortgesetzt; er läuft im Hintergrund weiter.', 'pass-captain' ), $url );
	}

	/** Continues a run (capability and event scope of its event/occurrence are checked). */
	public static function resume_bulk_run( array $in ) {
		$run = BulkRuns::get( Forms::int( $in, 'run_id' ) );
		if ( ! $run ) { return Forms::error( 'wpcap_run_missing', __( 'Vorgang nicht gefunden.', 'pass-captain' ), 404 ); }
		$allowed = 'event_cancel' === $run->kind ? Access::can_access_event( (int) $run->object_id, self::CAP ) : Access::can_access_occurrence( (int) $run->object_id, self::CAP );
		if ( ! $allowed ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$run = BulkRuns::drive( $run );
		return array( 'run_id' => (int) $run->run_id, 'kind' => (string) $run->kind, 'object_id' => (int) $run->object_id, 'complete' => 'complete' === $run->status );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 );
		}
		$view = Forms::query_arg( 'view' );
		echo '<div class="wrap wpcap-admin">';
		if ( 'event' === $view ) {
			self::render_event( absint( $_GET['event_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'occurrence' === $view ) {
			self::render_occurrence( absint( $_GET['occurrence_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'cancel_event' === $view ) {
			self::render_cancel_event( absint( $_GET['event_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
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
		echo '<p><a href="' . esc_url( self::event_url( (int) $occ->event_id ) ) . '">' . esc_html__( '← Zum Event', 'pass-captain' ) . '</a></p>';
		/* translators: 1: event name, 2: date */
		echo '<h1>' . esc_html( sprintf( __( 'Termin absagen: %1$s, %2$s', 'pass-captain' ), $event ? $event->name : '', Forms::datetime( $occ->starts_at, $occ->timezone ) ) ) . '</h1>';
		Forms::render_notices();
		if ( 'cancelled' === $occ->status ) {
			echo '<p>' . esc_html__( 'Dieser Termin ist bereits abgesagt.', 'pass-captain' ) . '</p>';
			BulkRuns::render_progress( array( BulkRuns::occurrence_run( (int) $occ->occurrence_id ) ) );
			return;
		}
		$pv = self::cancellation_preview( (int) $occ->occurrence_id );
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Die Absage kann nicht rückgängig gemacht werden. Folgen:', 'pass-captain' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
		/* translators: %d: tickets */
		echo '<li>' . esc_html( sprintf( __( '%d gültige Tickets werden widerrufen.', 'pass-captain' ), $pv['tickets'] ) ) . '</li>';
		/* translators: %d: orders */
		echo '<li>' . esc_html( sprintf( __( '%d unbezahlte Bestellungen werden storniert, ihre Reservierungen freigegeben.', 'pass-captain' ), $pv['pending_orders'] ) ) . '</li>';
		echo '<li>' . esc_html( $pv['auto_refund']
			/* translators: %d: orders */
			? sprintf( __( '%d bezahlte Bestellungen werden automatisch erstattet (Freigaberegeln gelten).', 'pass-captain' ), $pv['paid_orders'] )
			/* translators: %d: orders */
			: sprintf( __( '%d bezahlte Bestellungen werden nicht automatisch erstattet; die Tickets bleiben erstattungsfähig (Bestellung → Erstatten).', 'pass-captain' ), $pv['paid_orders'] ) ) . '</li>';
		if ( $pv['woo_orders'] ) {
			/* translators: %d: orders */
			echo '<li>' . esc_html( sprintf( __( '%d WooCommerce-Bestellungen müssen in WooCommerce erstattet werden.', 'pass-captain' ), $pv['woo_orders'] ) ) . '</li>';
		}
		/* translators: %d: recipients */
		echo '<li>' . esc_html( sprintf( __( '%d Personen erhalten die Absage-Mail.', 'pass-captain' ), $pv['recipients'] ) ) . '</li>';
		foreach ( $pv['passes'] as $pass ) {
			/* translators: 1: pass name, 2: tickets, 3: effect */
			echo '<li>' . esc_html( sprintf( __( 'Pass „%1$s“ (%2$d Tickets): %3$s', 'pass-captain' ), (string) ( $pass['name'] ?? '' ), (int) ( $pass['tickets'] ?? 0 ), (string) ( $pass['effect'] ?? '' ) ) ) . '</li>';
		}
		echo '</ul></div>';
		Forms::open_form( 'wpcap_cancel_occurrence' );
		Forms::hidden( 'occurrence_id', (int) $occ->occurrence_id );
		echo '<table class="form-table"><tbody><tr><th><label for="wpcap-cancel-reason">' . esc_html__( 'Grund der Absage', 'pass-captain' ) . '</label></th><td><input id="wpcap-cancel-reason" name="reason" class="regular-text" maxlength="255" required> <p class="description">' . esc_html__( 'Wird im Protokoll gespeichert.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Bestätigung', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Ich habe die Folgen geprüft und möchte den Termin absagen.', 'pass-captain' ) . '</label></td></tr></tbody></table>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Termin endgültig absagen', 'pass-captain' ) . '</button></p></form>';
	}

	/** Confirmation page of an event cancellation (A267): consequences, reason, explicit confirmation. */
	private static function render_cancel_event( $event_id ) {
		$event = Queries::event( $event_id );
		if ( ! $event || ! Access::can_access_event( (int) $event->event_id, self::CAP ) ) { self::denied(); return; }
		echo '<p><a href="' . esc_url( self::event_url( (int) $event->event_id ) ) . '">' . esc_html__( '← Zum Event', 'pass-captain' ) . '</a></p>';
		/* translators: %s: event name */
		echo '<h1>' . esc_html( sprintf( __( 'Event absagen: %s', 'pass-captain' ), $event->name ) ) . '</h1>';
		Forms::render_notices();
		if ( 'published' !== $event->status ) {
			echo '<p>' . esc_html( 'cancelled' === $event->status ? __( 'Dieses Event ist bereits abgesagt.', 'pass-captain' ) : __( 'Nur veröffentlichte Events können abgesagt werden.', 'pass-captain' ) ) . '</p>';
			BulkRuns::render_progress( array( BulkRuns::event_run( (int) $event->event_id ) ) );
			return;
		}
		$pv = self::event_cancellation_preview( (int) $event->event_id );
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Die Absage kann nicht rückgängig gemacht werden. Folgen:', 'pass-captain' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
		/* translators: %d: occurrences */
		echo '<li>' . esc_html( sprintf( __( '%d Termine werden abgesagt.', 'pass-captain' ), $pv['occurrences'] ) ) . '</li>';
		/* translators: %d: tickets */
		echo '<li>' . esc_html( sprintf( __( '%d gültige Tickets werden widerrufen.', 'pass-captain' ), $pv['tickets'] ) ) . '</li>';
		/* translators: %d: orders */
		echo '<li>' . esc_html( sprintf( __( '%d unbezahlte Bestellungen werden storniert, ihre Reservierungen freigegeben.', 'pass-captain' ), $pv['pending_orders'] ) ) . '</li>';
		echo '<li>' . esc_html( $pv['auto_refund']
			/* translators: %d: orders */
			? sprintf( __( '%d bezahlte Bestellungen werden automatisch erstattet (Freigaberegeln gelten).', 'pass-captain' ), $pv['paid_orders'] )
			/* translators: %d: orders */
			: sprintf( __( '%d bezahlte Bestellungen werden nicht automatisch erstattet; die Tickets bleiben erstattungsfähig (Bestellung → Erstatten).', 'pass-captain' ), $pv['paid_orders'] ) ) . '</li>';
		if ( $pv['woo_orders'] ) {
			/* translators: %d: orders */
			echo '<li>' . esc_html( sprintf( __( '%d WooCommerce-Bestellungen müssen in WooCommerce erstattet werden.', 'pass-captain' ), $pv['woo_orders'] ) ) . '</li>';
		}
		/* translators: %d: recipients */
		echo '<li>' . esc_html( sprintf( __( '%d Personen erhalten die Absage-Mail des Events.', 'pass-captain' ), $pv['recipients'] ) ) . '</li>';
		echo '<li>' . esc_html__( 'Das Event kann danach nicht mehr bearbeitet werden.', 'pass-captain' ) . '</li>';
		echo '</ul></div>';
		Forms::open_form( 'wpcap_cancel_event', 'wpcap_cancel_event_' . (int) $event->event_id );
		Forms::hidden( 'event_id', (int) $event->event_id );
		echo '<table class="form-table"><tbody><tr><th><label for="wpcap-cancel-event-reason">' . esc_html__( 'Grund der Absage', 'pass-captain' ) . '</label></th><td><input id="wpcap-cancel-event-reason" name="reason" class="regular-text" maxlength="255" required> <p class="description">' . esc_html__( 'Wird im Protokoll gespeichert.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Bestätigung', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Ich habe die Folgen geprüft und möchte das ganze Event absagen.', 'pass-captain' ) . '</label></td></tr></tbody></table>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Event endgültig absagen', 'pass-captain' ) . '</button></p></form>';
	}

	private static function denied() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Nicht gefunden oder keine Berechtigung für dieses Event.', 'pass-captain' ) . '</p></div>';
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Zur Eventliste', 'pass-captain' ) . '</a></p>';
	}

	private static function status_options() {
		return array( 'draft' => __( 'Entwurf', 'pass-captain' ), 'published' => __( 'Veröffentlicht', 'pass-captain' ) );
	}

	private static function render_list() {
		$scope = Queries::event_ids_for( self::CAP );
		$page = Forms::current_page();
		$data = Queries::events_overview( $scope, $page, 50 );
		$figures = Queries::event_figures( array_map( static function ( $row ) { return (int) $row->event_id; }, $data['rows'] ) );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'pass-captain' ); ?></h1>
		<hr class="wp-header-end">
		<?php Forms::render_notices(); ?>
		<?php if ( 1 === $page ) { Dashboard::render_summary(); } ?>
		<?php GettingStarted::render(); ?>
		<table class="widefat striped wpcap-events-list">
			<caption class="screen-reader-text"><?php esc_html_e( 'Events mit nächstem Termin, Auslastung und Umsatz', 'pass-captain' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Event', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Nächster Termin', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Ort', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Termine', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Verkauft / Kapazität', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Umsatz', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Noch keine Events angelegt.', 'pass-captain' ); ?></td></tr>
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
							echo esc_html( sprintf( __( 'Kein kommender Termin (zuletzt %s)', 'pass-captain' ), Forms::datetime( $shown->starts_at, $shown->timezone ) ) );
						} else {
							echo '—';
						}
						?>
					</td>
					<td><?php echo esc_html( $shown ? $shown->location : '' ); ?></td>
					<td><?php echo esc_html( (string) (int) $event->occurrence_count ); ?></td>
					<td><?php echo esc_html( self::figures_sold_label( $fig ) ); ?></td>
					<td><?php echo esc_html( self::figures_revenue_label( $fig ) ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $event->status, 'event' ) ); ?></td>
					<td>
						<a class="button" href="<?php echo esc_url( self::event_url( $event->event_id ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'pass-captain' ); ?></a>
						<?php if ( 'published' === $event->status ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'cancel_event', 'event_id' => $event->event_id ) ) ); ?>"><?php esc_html_e( 'Event absagen …', 'pass-captain' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php Forms::pagination( $data['total'], $page, 50, self::url() ); ?>

		<h2 id="wpcap-create-event"><?php esc_html_e( 'Event anlegen', 'pass-captain' ); ?></h2>
		<p><?php esc_html_e( 'Neue Events starten als Entwurf. Zeiten werden in der gewählten Zeitzone eingegeben.', 'pass-captain' ); ?></p>
		<?php
		// A297: after a validation error the form shows the input again and marks the field.
		$old = Forms::old( 'wpcap_create_event' );
		$v = static function ( $key, $default ) use ( $old ) { return Forms::old_value( $old, $key, $default ); };
		$bad = static function ( $key ) use ( $old ) { return Forms::invalid_attr( $old, $key ); };
		Forms::open_form( 'wpcap_create_event' );
		?>
			<table class="form-table"><tbody>
			<tr><th><label for="wpcap-name"><?php esc_html_e( 'Eventname', 'pass-captain' ); ?></label></th><td><input id="wpcap-name" name="name" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $v( 'name', '' ) ); ?>"<?php echo $bad( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
			<tr><th><label for="wpcap-description"><?php esc_html_e( 'Beschreibung', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-description" name="description" class="large-text" rows="4"><?php echo esc_textarea( $v( 'description', '' ) ); ?></textarea></td></tr>
			<tr><th><label for="wpcap-start"><?php esc_html_e( 'Beginn', 'pass-captain' ); ?></label></th><td><input id="wpcap-start" name="starts_at" type="datetime-local" required value="<?php echo esc_attr( $v( 'starts_at', '' ) ); ?>"<?php echo $bad( 'starts_at' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <?php Forms::timezone_select( 'timezone', $v( 'timezone', wp_timezone_string() ), '', 'aria-label="' . esc_attr__( 'Zeitzone', 'pass-captain' ) . '"' . $bad( 'timezone' ) ); ?></td></tr>
			<tr><th><label for="wpcap-end"><?php esc_html_e( 'Ende (optional)', 'pass-captain' ); ?></label></th><td><input id="wpcap-end" name="ends_at" type="datetime-local" value="<?php echo esc_attr( $v( 'ends_at', '' ) ); ?>"<?php echo $bad( 'ends_at' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
			<tr><th><label for="wpcap-repeat"><?php esc_html_e( 'Terminserie', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'repeat_frequency', array( 'none' => __( 'Einmalig', 'pass-captain' ), 'weekly' => __( 'Wöchentlich', 'pass-captain' ) ), $v( 'repeat_frequency', 'none' ), 'wpcap-repeat' ); ?> <label><?php esc_html_e( 'Anzahl Termine (max. 12)', 'pass-captain' ); ?> <input type="number" name="repeat_count" min="1" max="12" value="<?php echo esc_attr( $v( 'repeat_count', '1' ) ); ?>"></label><?php if ( self::series_available() ) : ?><p class="description"><?php esc_html_e( 'Einfache wöchentliche Wiederholung. Andere Rhythmen, Ausnahmen, mehr als 12 Termine und spätere Änderungen der ganzen Serie: nach dem Anlegen im Event „Serientermine“ verwenden.', 'pass-captain' ); ?></p><?php endif; ?></td></tr>
			<tr><th><label for="wpcap-location"><?php esc_html_e( 'Ort', 'pass-captain' ); ?></label></th><td><input id="wpcap-location" name="location" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $v( 'location', '' ) ); ?>"></td></tr>
			<tr><th><label for="wpcap-event-capacity"><?php esc_html_e( 'Kontingent pro Termin', 'pass-captain' ); ?></label></th><td><input id="wpcap-event-capacity" name="event_capacity" type="number" min="0" value="<?php echo esc_attr( $v( 'event_capacity', '0' ) ); ?>"> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-type-name"><?php esc_html_e( 'Ticketart', 'pass-captain' ); ?></label></th><td><input id="wpcap-type-name" name="ticket_name" type="text" value="<?php echo esc_attr( $v( 'ticket_name', 'Standard' ) ); ?>" required maxlength="190"<?php echo $bad( 'ticket_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
			<tr><th><label for="wpcap-price"><?php esc_html_e( 'Preis (brutto)', 'pass-captain' ); ?></label></th><td><input id="wpcap-price" name="price" type="text" inputmode="decimal" value="<?php echo esc_attr( $v( 'price', '0,00' ) ); ?>" required size="10"<?php echo $bad( 'price' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <?php Forms::select( 'currency', array_combine( Localization::currencies(), Localization::currencies() ), $v( 'currency', 'EUR' ), '', 'aria-label="' . esc_attr__( 'Währung', 'pass-captain' ) . '"' . $bad( 'currency' ) ); ?></td></tr>
			<tr><th><label for="wpcap-tax"><?php esc_html_e( 'Steuersatz in %', 'pass-captain' ); ?></label></th><td><input id="wpcap-tax" name="tax_rate" type="text" inputmode="decimal" value="<?php echo esc_attr( $v( 'tax_rate', '0' ) ); ?>" size="6"<?php echo $bad( 'tax_rate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <span class="description"><?php esc_html_e( 'z. B. 19 oder 7,7', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-type-capacity"><?php esc_html_e( 'Ticketkontingent', 'pass-captain' ); ?></label></th><td><input id="wpcap-type-capacity" name="ticket_capacity" type="number" min="0" value="<?php echo esc_attr( $v( 'ticket_capacity', '0' ) ); ?>"></td></tr>
			<tr><th><label for="wpcap-status"><?php esc_html_e( 'Status', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'status', self::status_options(), $v( 'status', 'draft' ), 'wpcap-status' ); ?></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Event speichern', 'pass-captain' ) ); ?>
		</form>
		<?php
		OrganizerTools::render_import();
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

	/** Publishing checklist above the event data (A293): what is missing before the event is bookable. */
	private static function render_publish_checklist( $event ) {
		if ( 'cancelled' === $event->status ) { return; }
		$issues = self::publish_issues( (int) $event->event_id );
		if ( ! $issues ) { return; }
		$published = 'published' === $event->status;
		echo '<div class="notice ' . ( $published ? 'notice-warning' : 'notice-info' ) . ' inline wpcap-publish-checklist"><p><strong>' . esc_html( $published ? __( 'Veröffentlicht, aber noch nicht buchbar:', 'pass-captain' ) : __( 'Vor dem Veröffentlichen erledigen:', 'pass-captain' ) ) . '</strong></p><ul class="ul-disc">';
		foreach ( $issues as $key => $message ) {
			echo '<li class="wpcap-publish-' . esc_attr( $key ) . '">' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	private static function render_event( $event_id ) {
		$event = Queries::event( $event_id );
		if ( ! $event || ! Access::can_access_event( $event_id, self::CAP ) ) { self::denied(); return; }
		$editable = 'cancelled' !== $event->status;
		// A476: one page of occurrences (default: the page with the next date), sold units of the page
		// in one query instead of one query per occurrence.
		$occ_page = Queries::occurrence_page( $event_id, Forms::query_arg( 'paged', '' ) !== '' ? Forms::current_page() : 0 );
		$occurrences = $occ_page['rows'];
		$sold = Queries::sold_for_occurrences( array_map( static function ( $o ) { return (int) $o->occurrence_id; }, $occurrences ) );
		$last = Queries::last_occurrence( $event_id );
		$default_tz = $last ? $last->timezone : wp_timezone_string();
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( '← Zur Eventliste', 'pass-captain' ); ?></a></p>
		<h1><?php echo esc_html( $event->name ); ?> <span class="subtitle"><?php echo esc_html( Forms::status_label( $event->status, 'event' ) ); ?></span></h1>
		<?php $wpcap_event_url = 'published' === $event->status ? \PassCaptain\Frontend\EventPage::url( $event->slug ) : ''; ?>
		<?php if ( '' !== $wpcap_event_url ) : ?><p><?php esc_html_e( 'Eventseite:', 'pass-captain' ); ?> <a href="<?php echo esc_url( $wpcap_event_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $wpcap_event_url ); ?></a></p><?php endif; ?>
		<?php Forms::render_notices(); ?>
		<?php self::render_publish_checklist( $event ); ?>

		<h2><?php esc_html_e( 'Eventdaten', 'pass-captain' ); ?></h2>
		<?php if ( $editable ) : ?>
			<?php
			$old = Forms::old( 'wpcap_update_event', array( 'event_id' => (int) $event->event_id ) ); // A297.
			Forms::open_form( 'wpcap_update_event' );
			Forms::hidden( 'event_id', $event->event_id );
			?>
			<table class="form-table"><tbody>
				<tr><th><label for="wpcap-ev-name"><?php esc_html_e( 'Eventname', 'pass-captain' ); ?></label></th><td><input id="wpcap-ev-name" name="name" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( Forms::old_value( $old, 'name', $event->name ) ); ?>"<?php echo Forms::invalid_attr( $old, 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
				<tr><th><label for="wpcap-ev-desc"><?php esc_html_e( 'Beschreibung', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-ev-desc" name="description" class="large-text" rows="5"><?php echo esc_textarea( Forms::old_value( $old, 'description', (string) $event->description ) ); ?></textarea></td></tr>
				<tr><th><label for="wpcap-ev-status"><?php esc_html_e( 'Status', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'status', self::status_options(), Forms::old_value( $old, 'status', $event->status ), 'wpcap-ev-status' ); ?></td></tr>
				<?php if ( Revisions::ready() ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Änderungsmitteilungen', 'pass-captain' ); ?></th><td><?php Forms::hidden( 'notify_changes_shown', 1 ); ?><label><input type="checkbox" name="notify_changes" value="1"<?php checked( 1 === (int) ( $event->notify_changes ?? 1 ) ); ?>> <?php esc_html_e( 'Ticketinhaber:innen per E-Mail informieren, wenn sich Zeit, Ort oder Einlass eines Termins ändern (mit aktualisierter Kalenderdatei)', 'pass-captain' ); ?></label><p class="description"><?php esc_html_e( 'Absagen werden unabhängig davon immer mitgeteilt.', 'pass-captain' ); ?></p></td></tr>
				<?php endif; ?>
				<tr><th><label for="wpcap-ev-image"><?php esc_html_e( 'Bild (URL, optional)', 'pass-captain' ); ?></label></th><td><input id="wpcap-ev-image" name="image_url" type="url" class="large-text" maxlength="500" value="<?php echo esc_attr( (string) ( $event->image_url ?? '' ) ); ?>" aria-describedby="wpcap-ev-image-desc"><p class="description" id="wpcap-ev-image-desc"><?php esc_html_e( 'Für die Eventseite, Vorschauen in sozialen Netzwerken und Suchmaschinen (mind. 1200 px breit empfohlen).', 'pass-captain' ); ?></p></td></tr>
				<tr><th><label for="wpcap-ev-category"><?php esc_html_e( 'Kategorie (optional)', 'pass-captain' ); ?></label></th><td><input id="wpcap-ev-category" name="category" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( (string) ( $event->category ?? '' ) ); ?>"></td></tr>
				<?php $wpcap_policy = \PassCaptain\Sales\SelfService::policy( $event ); ?>
				<tr><th><?php esc_html_e( 'Stornoanfragen im Bestellportal', 'pass-captain' ); ?></th><td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Stornoanfragen im Bestellportal', 'pass-captain' ); ?></legend>
					<label><input type="checkbox" name="refund_requests" value="1"<?php checked( ! empty( $wpcap_policy ) ); ?>> <?php esc_html_e( 'Käufer dürfen Tickets selbst zur Erstattung anfragen', 'pass-captain' ); ?></label><br>
					<label><?php esc_html_e( 'bis Stunden vor Beginn', 'pass-captain' ); ?> <input name="refund_hours_before" type="number" min="0" max="8760" style="width:6em" value="<?php echo esc_attr( (string) ( $wpcap_policy['hours_before'] ?? 48 ) ); ?>"></label>
					<label><?php esc_html_e( 'Bearbeitungsgebühr in % des Ticketwerts', 'pass-captain' ); ?> <input name="refund_fee_percent" type="text" inputmode="decimal" style="width:5em" value="<?php echo esc_attr( Forms::percent_input( (int) ( $wpcap_policy['fee_bp'] ?? 0 ) ) ); ?>"></label>
					<p class="description"><?php esc_html_e( 'Die Anfrage läuft über die normale Erstattung (Freigabe ab Schwellenwert); die Gebühr wird vom Erstattungsbetrag abgezogen und in der Rechnungskorrektur ausgewiesen.', 'pass-captain' ); ?></p>
				</fieldset></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Event aktualisieren', 'pass-captain' ) ); ?>
			</form>
			<?php if ( 'published' === $event->status ) : ?>
				<p><a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'cancel_event', 'event_id' => $event->event_id ) ) ); ?>"><?php esc_html_e( 'Event absagen …', 'pass-captain' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Dieses Event wurde abgesagt und kann nicht mehr bearbeitet werden.', 'pass-captain' ); ?></p>
		<?php endif; ?>

		<h2 id="wpcap-occurrences"><?php esc_html_e( 'Termine', 'pass-captain' ); ?></h2>
		<?php if ( $occ_page['pages'] > 1 ) { Forms::pagination( $occ_page['total'], $occ_page['page'], $occ_page['per_page'], self::event_url( $event_id ) ); } ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Beginn', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Ende', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Ort', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Verkauft / Kontingent', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $occurrences ) : ?><tr><td colspan="6"><?php esc_html_e( 'Keine Termine.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $occurrences as $occ ) : ?>
				<tr>
					<td><?php echo esc_html( Forms::datetime( $occ->starts_at, $occ->timezone ) ); ?><?php $doors = \PassCaptain\DoorsOpen::label( $occ->doors_open_at ?? null, $occ->timezone, $occ->starts_at ); ?><?php if ( '' !== $doors ) : ?><br><span class="description"><?php echo esc_html( $doors ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( $occ->ends_at ? Forms::datetime( $occ->ends_at, $occ->timezone, false ) : '—' ); ?></td>
					<td><?php echo esc_html( $occ->location ); ?></td>
					<td><?php echo esc_html( ( $sold[ (int) $occ->occurrence_id ] ?? 0 ) . ' / ' . ( (int) $occ->capacity ? (int) $occ->capacity : '∞' ) ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $occ->status, 'occurrence' ) ); ?></td>
					<td>
						<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'occurrence', 'occurrence_id' => $occ->occurrence_id ) ) ); ?>"><?php esc_html_e( 'Bearbeiten & Ticketarten', 'pass-captain' ); ?></a>
						<?php if ( $editable && 'cancelled' !== $occ->status ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'cancel_occurrence', 'occurrence_id' => $occ->occurrence_id ) ) ); ?>"><?php esc_html_e( 'Absagen …', 'pass-captain' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $occ_page['pages'] > 1 ) { Forms::pagination( $occ_page['total'], $occ_page['page'], $occ_page['per_page'], self::event_url( $event_id ) ); } ?>

		<?php if ( \PassCaptain\Consents::ready() && Access::can_access_event( $event_id, OrdersPage::CAP ) ) : ?>
			<p><a class="button" href="<?php echo esc_url( \PassCaptain\Consents::export_url( $event_id ) ); ?>"><?php esc_html_e( 'Einwilligungen exportieren (CSV)', 'pass-captain' ); ?></a> <span class="description"><?php esc_html_e( 'Alle Erteilungen und Widerrufe mit Textfassung, Quelle und aktuellem Stand; der Export wird im Audit-Protokoll vermerkt.', 'pass-captain' ); ?></span></p>
		<?php endif; ?>

		<?php if ( $editable ) : ?>
			<?php if ( self::series_available() ) : ?>
				<p><a class="button" href="<?php echo esc_url( \PassCaptain\Series\AdminPage::url( array( 'event_id' => (int) $event->event_id ) ) ); ?>"><?php esc_html_e( 'Serientermine anlegen oder bearbeiten', 'pass-captain' ); ?></a> <span class="description"><?php esc_html_e( 'Mehrere Termine nach Regel mit Ticketarten, Teilnehmerfeldern und Einlassregeln eines Vorlagentermins.', 'pass-captain' ); ?></span></p>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Termin hinzufügen', 'pass-captain' ); ?></h3>
			<?php self::occurrence_form( null, (int) $event->event_id, $default_tz ); ?>
		<?php endif; ?>

		<?php
		/**
		 * Further sections of the event edit screen (e.g. price rules, buyer limits, tax country:
		 * Sales\AdminSections). Callbacks check their own capabilities.
		 *
		 * @param object $event    Event row.
		 * @param bool   $editable False for cancelled events.
		 */
		do_action( 'wpcap_admin_event_sections', $event, $editable );
		?>
		<?php self::render_runs_for_event( $event ); ?>

		<?php self::render_staff( $event ); ?>
		<?php
	}

	/** Progress of the event's cancellation run and of single occurrence cancellations (A428). */
	private static function render_runs_for_event( $event ) {
		$runs = array( BulkRuns::event_run( (int) $event->event_id ) );
		// The most recently cancelled occurrences of the whole event, not only of the shown page (A476).
		foreach ( Queries::cancelled_occurrence_ids( (int) $event->event_id ) as $occurrence_id ) {
			$runs[] = BulkRuns::occurrence_run( $occurrence_id );
		}
		BulkRuns::render_progress( $runs );
	}

	private static function occurrence_form( $occ, $event_id, $default_tz ) {
		$tz = $occ ? $occ->timezone : $default_tz;
		// A297: input of a failed save of exactly this form (new date or this date) is shown again.
		$old = Forms::old( 'wpcap_save_occurrence', array( 'event_id' => (int) $event_id, 'occurrence_id' => $occ ? (int) $occ->occurrence_id : 0 ) );
		$tz = Forms::old_value( $old, 'timezone', $tz );
		Forms::open_form( 'wpcap_save_occurrence' );
		Forms::hidden( 'event_id', $event_id );
		Forms::hidden( 'occurrence_id', $occ ? $occ->occurrence_id : 0 );
		?>
		<table class="form-table"><tbody>
			<tr><th><label for="wpcap-occ-start"><?php esc_html_e( 'Beginn (Ortszeit)', 'pass-captain' ); ?></label></th><td><input id="wpcap-occ-start" name="starts_at" type="datetime-local" required value="<?php echo esc_attr( Forms::old_value( $old, 'starts_at', $occ ? Forms::utc_to_local( $occ->starts_at, $tz ) : '' ) ); ?>"<?php echo Forms::invalid_attr( $old, 'starts_at' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>>
				<?php if ( $occ ) : ?>
					<input type="hidden" name="shift_sales_windows_shown" value="1">
					<p><label><input type="checkbox" name="shift_sales_windows" value="1"<?php checked( Forms::old_flag( $old, 'shift_sales_windows', true ) ); ?>> <?php esc_html_e( 'Verkaufszeiträume der Ticketarten mit dem Beginn verschieben (gleicher Abstand in Ortszeit)', 'pass-captain' ); ?></label></p>
				<?php endif; ?>
			</td></tr>
			<tr><th><label for="wpcap-occ-end"><?php esc_html_e( 'Ende (optional)', 'pass-captain' ); ?></label></th><td><input id="wpcap-occ-end" name="ends_at" type="datetime-local" value="<?php echo esc_attr( Forms::old_value( $old, 'ends_at', $occ ? Forms::utc_to_local( $occ->ends_at, $tz ) : '' ) ); ?>"<?php echo Forms::invalid_attr( $old, 'ends_at' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
			<tr><th><label for="wpcap-occ-doors"><?php esc_html_e( 'Einlass ab (optional)', 'pass-captain' ); ?></label></th><td><input id="wpcap-occ-doors" name="doors_open_at" type="datetime-local" aria-describedby="wpcap-occ-doors-help" value="<?php echo esc_attr( Forms::old_value( $old, 'doors_open_at', $occ && ! empty( $occ->doors_open_at ) ? Forms::utc_to_local( $occ->doors_open_at, $tz ) : '' ) ); ?>"<?php echo Forms::invalid_attr( $old, 'doors_open_at' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <span class="description" id="wpcap-occ-doors-help"><?php esc_html_e( 'Ortszeit. Vorher wird am Einlass niemand eingelassen, auch wenn eine Einlassregel früher öffnet. Leer = kein eigener Einlassbeginn.', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-occ-tz"><?php esc_html_e( 'Zeitzone', 'pass-captain' ); ?></label></th><td><?php Forms::timezone_select( 'timezone', $tz, 'wpcap-occ-tz', 'aria-label="' . esc_attr__( 'Zeitzone', 'pass-captain' ) . '"' . Forms::invalid_attr( $old, 'timezone' ) ); ?></td></tr>
			<tr><th><label for="wpcap-occ-loc"><?php esc_html_e( 'Ort', 'pass-captain' ); ?></label></th><td><input id="wpcap-occ-loc" name="location" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( Forms::old_value( $old, 'location', $occ ? $occ->location : '' ) ); ?>"></td></tr>
			<?php if ( \PassCaptain\Tax\Profiles::ready() ) : ?>
			<tr><th><label for="wpcap-occ-country"><?php esc_html_e( 'Veranstaltungsland', 'pass-captain' ); ?></label></th><td><input id="wpcap-occ-country" name="venue_country" type="text" size="3" maxlength="2" pattern="[A-Za-z]{2}" style="text-transform:uppercase" aria-describedby="wpcap-occ-country-help" value="<?php echo esc_attr( Forms::old_value( $old, 'venue_country', $occ ? (string) ( $occ->venue_country ?? '' ) : '' ) ); ?>"> <span class="description" id="wpcap-occ-country-help"><?php echo esc_html( sprintf( /* translators: %s: seller country code from the settings */ __( 'ISO-Code, z. B. AT. Leistungsort für die Umsatzsteuer auf Eintrittskarten (Steuerprofile). Leer = Land des Rechnungsstellers (%s).', 'pass-captain' ), \PassCaptain\Tax\Profiles::seller_country() ) ); ?></span></td></tr>
			<?php endif; ?>
			<tr><th><label for="wpcap-occ-cap"><?php esc_html_e( 'Plätze des Termins', 'pass-captain' ); ?></label></th><td><input id="wpcap-occ-cap" name="capacity" type="number" min="0" value="<?php echo esc_attr( Forms::old_value( $old, 'capacity', (string) ( $occ ? (int) $occ->capacity : 0 ) ) ); ?>"> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'pass-captain' ); ?></span></td></tr>
			<?php if ( $occ ) : ?>
			<tr><th scope="row"><?php esc_html_e( 'Status', 'pass-captain' ); ?></th><td><?php echo esc_html( Forms::status_label( $occ->status, 'occurrence' ) ); ?>
				<?php if ( 'cancelled' === $occ->status ) : ?>
					<p class="description"><?php echo esc_html( self::reactivation_message() ); ?></p>
				<?php else : ?>
					<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'cancel_occurrence', 'occurrence_id' => $occ->occurrence_id ) ) ); ?>"><?php esc_html_e( 'Termin absagen …', 'pass-captain' ); ?></a>
					<p class="description"><?php esc_html_e( 'Absagen nur über die Bestätigungsseite: Sie zeigt die Folgen und fragt nach dem Grund.', 'pass-captain' ); ?></p>
				<?php endif; ?>
			</td></tr>
			<?php endif; ?>
		</tbody></table>
		<?php if ( $occ && Queries::issued_ticket_count_for_occurrence( $occ->occurrence_id ) ) : ?>
			<p class="description"><?php esc_html_e( 'Für diesen Termin sind bereits Tickets ausgestellt. Eine Änderung des Beginns wird als Terminverschiebung gemeldet.', 'pass-captain' ); ?></p>
		<?php endif; ?>
		<?php submit_button( $occ ? __( 'Termin speichern', 'pass-captain' ) : __( 'Termin hinzufügen', 'pass-captain' ) ); ?>
		</form>
		<?php
	}

	/** Labels of the event-team capabilities. */
	public static function staff_cap_labels() {
		return array(
			'wpcap_checkin' => __( 'Einlass/Scanner', 'pass-captain' ),
			'wpcap_manage_events' => __( 'Event verwalten', 'pass-captain' ),
			'wpcap_manage_orders' => __( 'Bestellungen/Kasse', 'pass-captain' ),
			'wpcap_view_reports' => __( 'Berichte', 'pass-captain' ),
		);
	}

	private static function render_staff( $event ) {
		$staff = Queries::staff_for_event( $event->event_id );
		$cap_labels = self::staff_cap_labels();
		?>
		<h2><?php esc_html_e( 'Personal und Zugriff', 'pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Benutzer', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Recht', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $staff ) : ?><tr><td colspan="3"><?php esc_html_e( 'Noch kein Personal zugewiesen.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $staff as $row ) : ?>
				<tr>
					<td><?php echo esc_html( Forms::user_label( $row->user_id ) ); ?></td>
					<td><?php echo esc_html( $cap_labels[ $row->capability ] ?? $row->capability ); ?></td>
					<td>
						<?php Forms::open_form( 'wpcap_remove_staff', null, 'style="display:inline" data-wpcap-confirm="' . esc_attr__( 'Person aus dem Personal dieses Events entfernen?', 'pass-captain' ) . '"' ); Forms::hidden( 'event_id', $event->event_id ); Forms::hidden( 'user_id', $row->user_id ); Forms::hidden( 'capability', $row->capability ); ?>
						<button class="button-link-delete button-link" type="submit"><?php esc_html_e( 'Entfernen', 'pass-captain' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Zugriff zuweisen', 'pass-captain' ); ?></h3>
		<?php Forms::open_form( 'wpcap_assign_staff' ); Forms::hidden( 'event_id', $event->event_id ); ?>
		<label><?php esc_html_e( 'Benutzer (ID, Login oder E-Mail)', 'pass-captain' ); ?> <input type="text" name="user" required></label>
		<label><?php esc_html_e( 'Recht', 'pass-captain' ); ?> <?php Forms::select( 'capability', $cap_labels, 'wpcap_checkin' ); ?></label>
		<button class="button" type="submit"><?php esc_html_e( 'Zugriff zuweisen', 'pass-captain' ); ?></button>
		<p class="description"><?php esc_html_e( 'Einlassrechte werden direkt vergeben. Für alle anderen Rechte braucht der Benutzer eine passende Rolle (z. B. Pass Captain Veranstaltungsleitung).', 'pass-captain' ); ?></p>
		</form>
		<p><a href="<?php echo esc_url( AssignmentsPage::url( (int) $event->event_id ) ); ?>"><?php esc_html_e( 'Engere Zuweisungen: Termine, Einlassbereiche, Leistungen', 'pass-captain' ); ?></a></p>
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
		<h1><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Termin %s', 'pass-captain' ), Forms::datetime( $occ->starts_at, $occ->timezone ) ) ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $editable ) { self::occurrence_form( $occ, (int) $occ->event_id, $occ->timezone ); } ?>

		<h2><?php esc_html_e( 'Ticketarten', 'pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Preis', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Steuer', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Verkauft / Kontingent', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Max. pro Bestellung', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Verkaufszeitraum', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $types ) : ?><tr><td colspan="7"><?php esc_html_e( 'Noch keine Ticketarten.', 'pass-captain' ); ?></td></tr><?php endif; ?>
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
			<h3><?php esc_html_e( 'Ticketart hinzufügen', 'pass-captain' ); ?></h3>
			<?php self::ticket_type_form( null, $occ ); ?>
		<?php endif; ?>
		<?php
		/**
		 * Further sections of the occurrence screen (e.g. complimentary tickets, duplicate:
		 * Admin\OrganizerTools). Callbacks check their own capabilities.
		 *
		 * @param object $occ      Occurrence row.
		 * @param bool   $editable False for cancelled events.
		 */
		do_action( 'wp_pass_captain_admin_occurrence_sections_v1', $occ, $editable );
		SecurityScreens::render_occurrence_staff( $occ );
		BulkRuns::render_progress( array_merge( array( BulkRuns::occurrence_run( (int) $occ->occurrence_id ) ), BulkRuns::for_object( 'notify', (int) $occ->occurrence_id, 5 ) ) );
		ChangeHistory::render( ChangeHistory::for_occurrence( (int) $occ->occurrence_id ), (string) $occ->timezone, 'wpcap-occurrence-history' );
	}

	private static function ticket_type_form( $type, $occ ) {
		// A297: input of a failed save of exactly this form (new type or this type) is shown again.
		$old = Forms::old( 'wpcap_save_ticket_type', array( 'occurrence_id' => (int) $occ->occurrence_id, 'ticket_type_id' => $type ? (int) $type->ticket_type_id : 0 ) );
		$v = static function ( $key, $default ) use ( $old ) { return Forms::old_value( $old, $key, $default ); };
		$bad = static function ( $key ) use ( $old ) { return Forms::invalid_attr( $old, $key ); };
		$currency = $v( 'currency', $type ? $type->currency : 'EUR' );
		Forms::open_form( 'wpcap_save_ticket_type' );
		Forms::hidden( 'occurrence_id', $occ->occurrence_id );
		Forms::hidden( 'ticket_type_id', $type ? $type->ticket_type_id : 0 );
		if ( $type ) {
			// A531: a failed save shows the form again with the revision it was opened with – except after
			// the conflict itself: the person has seen the warning, saving again is a deliberate overwrite.
			Forms::hidden( 'revision', $old && 'revision' !== ( $old['field'] ?? '' ) ? (string) $v( 'revision', self::type_revision( $type ) ) : self::type_revision( $type ) );
		}
		?>
		<table class="form-table"><tbody>
			<tr><th><label for="wpcap-tt-name"><?php esc_html_e( 'Name', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-name" name="name" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $v( 'name', $type ? $type->name : '' ) ); ?>"<?php echo $bad( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
			<tr><th><label for="wpcap-tt-price"><?php esc_html_e( 'Preis (brutto)', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-price" name="price" type="text" inputmode="decimal" required size="10" value="<?php echo esc_attr( $v( 'price', Forms::money_input( $type ? $type->price_minor : 0, Forms::currency_decimals( $currency ) ) ) ); ?>"<?php echo $bad( 'price' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <?php Forms::select( 'currency', array_combine( Localization::currencies(), Localization::currencies() ), $currency, '', 'aria-label="' . esc_attr__( 'Währung', 'pass-captain' ) . '"' . $bad( 'currency' ) ); ?> <span class="description"><?php esc_html_e( 'z. B. 12,50', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-tax"><?php esc_html_e( 'Steuersatz in %', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-tax" name="tax_rate" type="text" inputmode="decimal" size="6" value="<?php echo esc_attr( $v( 'tax_rate', Forms::percent_input( $type ? $type->tax_rate_bp : 0 ) ) ); ?>"<?php echo $bad( 'tax_rate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <span class="description"><?php esc_html_e( 'Im Preis enthaltene Umsatzsteuer, z. B. 19 oder 7,7', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-taxclass"><?php esc_html_e( 'Steuerklasse (optional)', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-taxclass" name="tax_class" type="text" size="16" maxlength="64" pattern="[a-z0-9_-]*" aria-describedby="wpcap-tt-taxclass-help" value="<?php echo esc_attr( $v( 'tax_class', $type ? (string) ( $type->tax_class ?? '' ) : '' ) ); ?>"> <span class="description" id="wpcap-tt-taxclass-help"><?php esc_html_e( 'z. B. culture. Gibt es für das Veranstaltungsland ein aktives Steuerprofil dieser Klasse, gilt dessen Satz; sonst der Steuersatz oben.', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-cap"><?php esc_html_e( 'Kontingent dieser Ticketart', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-cap" name="capacity" type="number" min="0" value="<?php echo esc_attr( $v( 'capacity', (string) ( $type ? (int) $type->capacity : 0 ) ) ); ?>"<?php echo $bad( 'capacity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-max"><?php esc_html_e( 'Max. pro Bestellung', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-max" name="max_per_order" type="number" min="1" max="100" value="<?php echo esc_attr( $v( 'max_per_order', (string) ( $type ? (int) $type->max_per_order : 10 ) ) ); ?>"<?php echo $bad( 'max_per_order' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>></td></tr>
			<tr><th><label for="wpcap-tt-ss"><?php esc_html_e( 'Verkaufsstart', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-ss" name="sales_start" type="datetime-local" value="<?php echo esc_attr( $v( 'sales_start', $type ? Forms::utc_to_local( $type->sales_start, $occ->timezone ) : '' ) ); ?>"<?php echo $bad( 'sales_start' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <span class="description"><?php echo esc_html( sprintf( /* translators: %s: timezone */ __( 'Ortszeit %s; leer = sofort', 'pass-captain' ), $occ->timezone ) ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-se"><?php esc_html_e( 'Verkaufsende', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-se" name="sales_end" type="datetime-local" value="<?php echo esc_attr( $v( 'sales_end', $type ? Forms::utc_to_local( $type->sales_end, $occ->timezone ) : '' ) ); ?>"<?php echo $bad( 'sales_end' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <span class="description"><?php esc_html_e( 'leer = bis Terminbeginn; ein Verkaufsende nach Beginn (mehrtägige Termine) gilt online höchstens bis Terminende', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-desc"><?php esc_html_e( 'Beschreibung', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-tt-desc" name="description" class="large-text" rows="2" maxlength="1000" aria-describedby="wpcap-tt-desc-hint"><?php echo esc_textarea( $type ? (string) ( $type->description ?? '' ) : '' ); ?></textarea><p class="description" id="wpcap-tt-desc-hint"><?php esc_html_e( 'Kurzer Hinweis unter der Ticketart, z. B. „inkl. Garderobe“ oder „nur mit Schülerausweis“.', 'pass-captain' ); ?></p></td></tr>
			<tr><th><label for="wpcap-tt-pos"><?php esc_html_e( 'Reihenfolge', 'pass-captain' ); ?></label></th><td><input id="wpcap-tt-pos" name="position" type="number" min="0" max="65535" value="<?php echo esc_attr( (string) ( $type ? (int) ( $type->position ?? 0 ) : 0 ) ); ?>"> <span class="description"><?php esc_html_e( 'Kleinere Zahlen stehen auf der Eventseite weiter oben.', 'pass-captain' ); ?></span></td></tr>
			<tr><th><label for="wpcap-tt-attname"><?php esc_html_e( 'Name je Ticket', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'attendee_name', array( 'optional' => __( 'Optional abfragen', 'pass-captain' ), 'required' => __( 'Pflichtangabe (personalisiertes Ticket)', 'pass-captain' ), 'off' => __( 'Nicht abfragen', 'pass-captain' ) ), $type ? \PassCaptain\Orders::attendee_name_mode( $type->attendee_name ?? '' ) : 'optional', 'wpcap-tt-attname' ); ?></td></tr>
			<?php
			$checks = \PassCaptain\AdmissionChecks::for_type( $type ? $type : 0 );
			if ( $old ) {
				$checks = array( 'id_check' => Forms::old_flag( $old, 'id_check', false ), 'min_age' => (int) $v( 'min_age', '0' ), 'staff_note' => $v( 'staff_note', '' ) ) + $checks;
			}
			?>
			<tr><th><?php esc_html_e( 'Einlasskontrolle', 'pass-captain' ); ?></th><td>
				<label><input type="checkbox" name="id_check" value="1" <?php echo $checks['id_check'] ? 'checked' : ''; ?>> <?php esc_html_e( 'Ausweis prüfen', 'pass-captain' ); ?></label><br>
				<label><?php esc_html_e( 'Mindestalter', 'pass-captain' ); ?> <input name="min_age" type="number" min="0" max="99" class="small-text" value="<?php echo esc_attr( $checks['min_age'] ? (string) $checks['min_age'] : '' ); ?>"></label><br>
				<label><?php esc_html_e( 'Hinweis für das Einlasspersonal', 'pass-captain' ); ?> <input name="staff_note" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $checks['staff_note'] ); ?>"></label>
				<p class="description"><?php esc_html_e( 'Wird beim Scannen (Web-Scanner, App, Offline-Scanner) angezeigt; Mindestalter und Ausweispflicht sehen Käufer auch beim Ticketkauf. Der Scan wird dadurch nicht abgelehnt.', 'pass-captain' ); ?></p>
			</td></tr>
			<?php if ( \PassCaptain\ParticipantFields::name_switch_ready() ) : ?>
			<tr><th><?php esc_html_e( 'Teilnehmername', 'pass-captain' ); ?></th><td><label><input type="checkbox" name="attendee_name_required" value="1" <?php checked( $old ? Forms::old_flag( $old, 'attendee_name_required', false ) : ( $type && ! empty( $type->attendee_name_required ) ) ); ?>> <?php esc_html_e( 'Name für jedes Ticket verlangen', 'pass-captain' ); ?></label></td></tr>
			<?php endif; ?>
			<tr><th><label for="wpcap-tt-status"><?php esc_html_e( 'Status', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'status', array( 'active' => __( 'Aktiv', 'pass-captain' ), 'paused' => __( 'Pausiert', 'pass-captain' ) ), $v( 'status', $type ? $type->status : 'active' ), 'wpcap-tt-status' ); ?></td></tr>
			<tr><th><label for="wpcap-tt-vis"><?php esc_html_e( 'Sichtbarkeit', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'visibility', array( 'public' => __( 'Öffentlich', 'pass-captain' ), 'code' => __( 'Nur mit Freischaltcode', 'pass-captain' ), 'hidden' => __( 'Versteckt (nur Kasse, Freikarten)', 'pass-captain' ) ), \PassCaptain\Sales\Visibility::of( $type ), 'wpcap-tt-vis', 'aria-describedby="wpcap-tt-vis-desc"' ); ?>
				<p><label for="wpcap-tt-codes"><?php esc_html_e( 'Freischaltcodes (einer pro Zeile)', 'pass-captain' ); ?></label><br><textarea id="wpcap-tt-codes" name="access_codes" rows="3" cols="30" class="code"><?php echo esc_textarea( $type ? implode( "\n", \PassCaptain\Sales\Visibility::codes( $type ) ) : '' ); ?></textarea></p>
				<p class="description" id="wpcap-tt-vis-desc"><?php esc_html_e( 'Codes schalten die Ticketart im Rabattcode-Feld oder per Link ?wpcap_code=CODE frei (ohne Rabatt). Ein Kampagnencode, der nur für diese Ticketart gilt, schaltet sie ebenfalls frei (mit Rabatt). Der Server prüft die Freischaltung bei jeder Bestellung.', 'pass-captain' ); ?></p></td></tr>
			<?php $wpcap_siblings = $type ? count( self::sibling_types( (int) $occ->event_id, (int) $occ->occurrence_id, (string) $type->name ) ) : count( self::upcoming_occurrences( (int) $occ->event_id, (int) $occ->occurrence_id ) ); ?>
			<?php if ( $wpcap_siblings ) : ?>
			<tr><th><?php esc_html_e( 'Weitere Termine', 'pass-captain' ); ?></th><td>
				<label><input type="checkbox" name="apply_all" value="1"> <?php echo esc_html( $type ? sprintf( /* translators: %d: number of other upcoming dates */ _n( 'Auch für die gleichnamige Ticketart an %d weiteren kommenden Termin übernehmen', 'Auch für die gleichnamige Ticketart an %d weiteren kommenden Terminen übernehmen', $wpcap_siblings, 'pass-captain' ), $wpcap_siblings ) : sprintf( /* translators: %d: number of other upcoming dates */ _n( 'Auch an %d weiteren kommenden Termin anlegen', 'Auch an %d weiteren kommenden Terminen anlegen', $wpcap_siblings, 'pass-captain' ), $wpcap_siblings ) ); ?></label>
				<p class="description"><?php echo esc_html( $type ? __( 'Name, Preis, Währung, Steuer, Höchstmenge, Status und Einlasskontrolle werden übernommen; Kontingent und Verkaufszeitraum bleiben je Termin. Termine, für die eine Prüfung fehlschlägt, werden übersprungen und genannt.', 'pass-captain' ) : __( 'Termine, die schon eine Ticketart dieses Namens haben, werden übersprungen. Der Verkaufszeitraum wird mit dem Termin verschoben.', 'pass-captain' ) ); ?></p>
			</td></tr>
			<?php endif; ?>
			<?php if ( $type && Queries::type_has_orders( (int) $type->ticket_type_id ) ) : $wpcap_sold = Queries::sold_for_type( (int) $type->ticket_type_id ); ?>
			<tr><th><?php esc_html_e( 'Bereits verkauft', 'pass-captain' ); ?></th><td>
				<p class="description" id="wpcap-tt-sold-note"><strong><?php echo esc_html( sprintf( /* translators: %d: sold tickets */ _n( '%d Ticket verkauft.', '%d Tickets verkauft.', $wpcap_sold, 'pass-captain' ), $wpcap_sold ) ); ?></strong> <?php esc_html_e( 'Änderungen an Preis, Steuersatz oder Steuerklasse gelten nur für neue Bestellungen; bestehende Bestellungen, Tickets, Rechnungen und Erstattungen bleiben unverändert.', 'pass-captain' ); ?></p>
				<label><input type="checkbox" name="confirm_sold_change" value="1" aria-describedby="wpcap-tt-sold-note"<?php echo $bad( 'confirm_sold_change' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>> <?php esc_html_e( 'Preis-/Steueränderung trotz verkaufter Tickets übernehmen', 'pass-captain' ); ?></label>
			</td></tr>
			<?php endif; ?>
		</tbody></table>
		<?php submit_button( $type ? __( 'Ticketart speichern', 'pass-captain' ) : __( 'Ticketart hinzufügen', 'pass-captain' ) ); ?>
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
		$type_labels = self::field_type_labels();
		$type_url = self::url( array( 'view' => 'ticket_type', 'ticket_type_id' => $ticket_type_id ) );
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'view' => 'occurrence', 'occurrence_id' => $type->occurrence_id ) ) ); ?>"><?php echo esc_html( '← ' . ( $event ? $event->name . ', ' : '' ) . Forms::datetime( $occ->starts_at, $occ->timezone ) ); ?></a></p>
		<h1><?php echo esc_html( sprintf( /* translators: %s: ticket type name */ __( 'Ticketart: %s', 'pass-captain' ), $type->name ) ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $editable ) { self::ticket_type_form( $type, $occ ); } ?>

		<h2><?php esc_html_e( 'Teilnehmerfelder', 'pass-captain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Nur Daten abfragen, die für die Veranstaltung erforderlich sind. Zweck und Aufbewahrungsdauer werden Käufern angezeigt.', 'pass-captain' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Technischer Schlüssel', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Bezeichnung', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Typ', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Pflicht', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Zweck', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aufbewahrung (Tage)', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $fields ) : ?><tr><td colspan="8"><?php esc_html_e( 'Keine Teilnehmerfelder.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $fields as $field ) : ?>
				<tr>
					<td><code><?php echo esc_html( $field->field_key ); ?></code></td>
					<td><?php echo esc_html( $field->label ); ?></td>
					<td><?php echo esc_html( ( $type_labels[ $field->field_type ] ?? $field->field_type ) . ( ! empty( $field->consent ) ? ' · ' . __( 'Einwilligung', 'pass-captain' ) : '' ) . ( 'order' === ( $field->scope ?? 'ticket' ) ? ' · ' . __( 'je Bestellung', 'pass-captain' ) : '' ) . ( isset( $field->version ) && (int) $field->version > 1 ? ' · v' . (int) $field->version : '' ) ); ?></td>
					<td><?php echo $field->required ? esc_html__( 'Ja', 'pass-captain' ) : esc_html__( 'Nein', 'pass-captain' ); ?></td>
					<td><?php echo esc_html( $field->purpose ); ?></td>
					<td><?php echo esc_html( (string) (int) $field->retention_days ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $field->status ) ); ?></td>
					<td>
						<?php if ( $editable ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'field_id', $field->field_id, $type_url ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'pass-captain' ); ?></a>
						<?php Forms::open_form( 'wpcap_participant_field_status', null, 'style="display:inline"' ); Forms::hidden( 'field_id', $field->field_id ); Forms::hidden( 'ticket_type_id', $ticket_type_id ); Forms::hidden( 'status', 'active' === $field->status ? 'inactive' : 'active' ); ?>
						<button class="button" type="submit"><?php echo 'active' === $field->status ? esc_html__( 'Deaktivieren', 'pass-captain' ) : esc_html__( 'Aktivieren', 'pass-captain' ); ?></button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $editable ) : ?>
			<h3><?php echo $edit_field ? esc_html__( 'Teilnehmerfeld bearbeiten', 'pass-captain' ) : esc_html__( 'Teilnehmerfeld hinzufügen', 'pass-captain' ); ?></h3>
			<?php Forms::open_form( 'wpcap_save_participant_field' ); Forms::hidden( 'ticket_type_id', $ticket_type_id ); Forms::hidden( 'field_id', $edit_field ? $edit_field->field_id : 0 ); ?>
			<table class="form-table"><tbody>
				<tr><th><label for="wpcap-pf-key"><?php esc_html_e( 'Technischer Schlüssel', 'pass-captain' ); ?></label></th><td>
					<?php if ( $edit_field ) : ?><code><?php echo esc_html( $edit_field->field_key ); ?></code> <span class="description"><?php esc_html_e( 'Der Schlüssel kann nicht geändert werden.', 'pass-captain' ); ?></span>
					<?php else : ?><input id="wpcap-pf-key" name="field_key" type="text" required maxlength="64" pattern="[a-z0-9_]+"> <span class="description"><?php esc_html_e( 'Technischer Name, z. B. company (a-z, 0-9, _)', 'pass-captain' ); ?></span><?php endif; ?>
				</td></tr>
				<tr><th><label for="wpcap-pf-label"><?php esc_html_e( 'Bezeichnung', 'pass-captain' ); ?></label></th><td><input id="wpcap-pf-label" name="label" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $edit_field ? $edit_field->label : '' ); ?>"></td></tr>
				<tr><th><label for="wpcap-pf-type"><?php esc_html_e( 'Typ', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'field_type', $type_labels, $edit_field ? $edit_field->field_type : 'text', 'wpcap-pf-type' ); ?></td></tr>
				<?php if ( ! \PassCaptain\ParticipantFields::ready() ) : ?>
				<tr><th><label for="wpcap-pf-options"><?php esc_html_e( 'Auswahlmöglichkeiten', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-pf-options" name="options" class="large-text" rows="4" aria-describedby="wpcap-pf-options-desc"><?php echo esc_textarea( $edit_field ? (string) ( $edit_field->options ?? '' ) : '' ); ?></textarea><p class="description" id="wpcap-pf-options-desc"><?php esc_html_e( 'Nur für „Auswahlliste“ und „Optionsfelder“: eine Auswahlmöglichkeit pro Zeile (mindestens zwei).', 'pass-captain' ); ?></p></td></tr>
				<?php else : ?>
				<tr><th><label for="wpcap-pf-options"><?php esc_html_e( 'Auswahlmöglichkeiten', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-pf-options" name="options" rows="4" class="regular-text" aria-describedby="wpcap-pf-options-help"><?php echo esc_textarea( $edit_field ? implode( "\n", \PassCaptain\ParticipantFields::options( $edit_field ) ) : '' ); ?></textarea>
					<p class="description" id="wpcap-pf-options-help"><?php esc_html_e( 'Nur für Auswahl und Mehrfachauswahl: eine Möglichkeit je Zeile (bei Mehrfachauswahl ohne Komma).', 'pass-captain' ); ?></p></td></tr>
				<tr><th><label for="wpcap-pf-help"><?php esc_html_e( 'Hilfetext', 'pass-captain' ); ?></label></th><td><input id="wpcap-pf-help" name="help_text" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $edit_field ? (string) ( $edit_field->help_text ?? '' ) : '' ); ?>"></td></tr>
				<tr><th><label for="wpcap-pf-scope"><?php esc_html_e( 'Abfrage', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'scope', array( 'ticket' => __( 'Für jedes Ticket', 'pass-captain' ), 'order' => __( 'Einmal je Bestellung', 'pass-captain' ) ), $edit_field ? (string) ( $edit_field->scope ?? 'ticket' ) : 'ticket', 'wpcap-pf-scope' ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Pflichtfeld', 'pass-captain' ); ?></th><td><label><input type="checkbox" name="required" value="1" <?php checked( $edit_field && $edit_field->required ); ?>> <?php esc_html_e( 'Erforderlich', 'pass-captain' ); ?></label></td></tr>
				<?php if ( \PassCaptain\Consents::ready() ) : ?>
				<tr><th><?php esc_html_e( 'Einwilligung', 'pass-captain' ); ?></th><td><label><input type="checkbox" name="consent" value="1" aria-describedby="wpcap-pf-consent-help" <?php checked( $edit_field && ! empty( $edit_field->consent ) ); ?>> <?php esc_html_e( 'Als Einwilligung protokollieren und Widerruf ermöglichen', 'pass-captain' ); ?></label>
					<p class="description" id="wpcap-pf-consent-help"><?php esc_html_e( 'Nur für Checkboxen. Jede Erteilung und jeder Widerruf wird mit Textfassung, Zeitpunkt und Quelle protokolliert; Teilnehmende können in der Bestellübersicht und auf der Ticketseite widerrufen. Zweck ist Pflicht. Eine Änderung von Bezeichnung oder Zweck gilt als neue Textfassung.', 'pass-captain' ); ?></p></td></tr>
				<?php endif; ?>
				<tr><th><label for="wpcap-pf-consent"><?php esc_html_e( 'Einwilligungstext', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-pf-consent" name="consent_text" class="large-text" rows="3" maxlength="2000" aria-describedby="wpcap-pf-consent-desc"><?php echo esc_textarea( $edit_field ? (string) ( $edit_field->consent_text ?? '' ) : '' ); ?></textarea><p class="description" id="wpcap-pf-consent-desc"><?php esc_html_e( 'Nur für den Typ „Einwilligung“: Text, dem zugestimmt wird. Text, Version, Zeitpunkt und Quelle werden je Ticket protokolliert; Käufer können im Bestellportal widerrufen.', 'pass-captain' ); ?></p></td></tr>
				<tr><th><label for="wpcap-pf-consent-version"><?php esc_html_e( 'Version des Einwilligungstexts', 'pass-captain' ); ?></label></th><td><input id="wpcap-pf-consent-version" name="consent_version" type="text" maxlength="32" pattern="[A-Za-z0-9._\-]{1,32}" value="<?php echo esc_attr( $edit_field ? (string) ( $edit_field->consent_version ?? '' ) : '1' ); ?>"></td></tr>
				<tr><th><label for="wpcap-pf-purpose"><?php esc_html_e( 'Zweck', 'pass-captain' ); ?></label></th><td><input id="wpcap-pf-purpose" name="purpose" type="text" class="regular-text" maxlength="190" value="<?php echo esc_attr( $edit_field ? $edit_field->purpose : '' ); ?>"></td></tr>
				<?php if ( \PassCaptain\ParticipantFields::locks_ready() ) : ?>
				<tr><th><label for="wpcap-pf-self"><?php esc_html_e( 'Selbst ändern', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'self_service', array( 'edit' => __( 'Teilnehmende dürfen ändern', 'pass-captain' ), 'locked' => __( 'Gesperrt (nur durch das Team)', 'pass-captain' ) ), $edit_field ? (string) ( $edit_field->self_service ?? 'edit' ) : 'edit', 'wpcap-pf-self' ); ?>
					<label for="wpcap-pf-edit-hours"><?php esc_html_e( 'bis Stunden vor Beginn', 'pass-captain' ); ?></label> <input id="wpcap-pf-edit-hours" name="edit_hours" type="number" min="0" max="8760" class="small-text" aria-describedby="wpcap-pf-edit-hours-help" value="<?php echo esc_attr( $edit_field ? (string) (int) ( $edit_field->edit_hours ?? 0 ) : '0' ); ?>">
					<p class="description" id="wpcap-pf-edit-hours-help"><?php esc_html_e( 'Gilt im Bestellportal und auf der Ticketseite. 0 = keine eigene Frist (es gilt die allgemeine Änderungsfrist). Das Team kann im Admin immer ändern.', 'pass-captain' ); ?></p></td></tr>
				<?php endif; ?>
				<tr><th><label for="wpcap-pf-ret"><?php esc_html_e( 'Aufbewahrung (Tage)', 'pass-captain' ); ?></label></th><td><input id="wpcap-pf-ret" name="retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( $edit_field ? (int) $edit_field->retention_days : 365 ); ?>"></td></tr>
				<?php $wpcap_siblings = count( self::sibling_types( (int) $type->event_id, (int) $type->occurrence_id, (string) $type->name ) ); ?>
				<?php if ( $wpcap_siblings ) : ?>
				<tr><th><?php esc_html_e( 'Weitere Termine', 'pass-captain' ); ?></th><td><label><input type="checkbox" name="apply_all" value="1"> <?php echo esc_html( sprintf( /* translators: %d: number of other upcoming dates */ _n( 'Auch bei der gleichnamigen Ticketart an %d weiteren kommenden Termin anlegen bzw. ändern', 'Auch bei der gleichnamigen Ticketart an %d weiteren kommenden Terminen anlegen bzw. ändern', $wpcap_siblings, 'pass-captain' ), $wpcap_siblings ) ); ?></label></td></tr>
				<?php endif; ?>
				<tr><th><label for="wpcap-pf-pos"><?php esc_html_e( 'Reihenfolge', 'pass-captain' ); ?></label></th><td><input id="wpcap-pf-pos" name="position" type="number" min="0" value="<?php echo esc_attr( $edit_field ? (int) $edit_field->position : count( $fields ) ); ?>"></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Feld speichern', 'pass-captain' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
