<?php
/**
 * Admin page "Service-Zeitfenster" (A157): resources (rooms, staff, equipment) and bookable time
 * slots per event – create, edit capacity/time, close, delete without bookings – plus the booking
 * list. Users need wpcap_manage_events for the event (Access, incl. location/partner scope).
 * Logic, validation and audit: ServiceBookings (shared with the REST routes).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Pii;
use PassCaptain\ServiceBookings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceSlotsPage {
	const SLUG = 'wpcap-service-slots';
	const CAP = 'wpcap_manage_events';

	public static function url( $event_id = 0, array $args = array() ) {
		return Forms::page_url( self::SLUG, ( $event_id ? array( 'event_id' => (int) $event_id ) : array() ) + $args );
	}

	/** Timezone of the event (first occurrence), else the site timezone, else UTC. */
	public static function timezone( $event_id ) {
		global $wpdb;
		$zone = (string) $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at LIMIT 1", absint( $event_id ) ) );
		if ( ! Forms::is_valid_timezone( $zone ) ) { $zone = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC'; }
		return Forms::is_valid_timezone( $zone ) ? $zone : 'UTC';
	}

	/** Form times (local, event timezone) → ServiceBookings input (UTC). */
	private static function slot_input( array $in, $event_id ) {
		$zone = self::timezone( $event_id );
		$start = Forms::local_to_utc( Forms::text( $in, 'starts_at', 20 ), $zone );
		$end = Forms::local_to_utc( Forms::text( $in, 'ends_at', 20 ), $zone );
		if ( null === $start || null === $end ) { return Forms::error( 'wpcap_service_slot_invalid', __( 'Zeitfenster ist ungültig.', 'pass-captain' ) ); }
		return array( 'starts_at' => $start, 'ends_at' => $end, 'capacity' => Forms::text( $in, 'capacity', 5 ), 'status' => Forms::choice( $in, 'status', array( 'open', 'closed' ), '' ) );
	}

	/** Creates (no resource_id) or updates a resource. */
	public static function save_resource( array $in ) {
		$data = array( 'name' => Forms::text( $in, 'name', 190 ), 'capacity' => Forms::int( $in, 'capacity', 1 ), 'buffer_before' => Forms::int( $in, 'buffer_before' ), 'buffer_after' => Forms::int( $in, 'buffer_after' ), 'status' => Forms::choice( $in, 'status', array( 'active', 'inactive' ), '' ) );
		$resource_id = Forms::int( $in, 'resource_id' );
		return $resource_id ? ServiceBookings::update_resource( $resource_id, $data ) : ServiceBookings::create_resource( Forms::int( $in, 'event_id' ), $data );
	}

	/** Creates (no slot_id) or updates a slot. */
	public static function save_slot( array $in ) {
		$slot_id = Forms::int( $in, 'slot_id' );
		if ( $slot_id ) {
			$slot = ServiceBookings::slot( $slot_id );
			if ( ! $slot ) { return Forms::error( 'wpcap_service_not_found', __( 'Ressource oder Zeitfenster nicht gefunden.', 'pass-captain' ), 404 ); }
			if ( ! Access::can_access_event( (int) $slot->event_id, self::CAP ) ) { return Forms::forbidden(); }
			$data = self::slot_input( $in, (int) $slot->event_id );
			return is_wp_error( $data ) ? $data : ServiceBookings::update_slot( $slot_id, $data );
		}
		$resource = ServiceBookings::resource( Forms::int( $in, 'resource_id' ) );
		if ( ! $resource || ! Access::can_access_event( (int) $resource->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Ressource.', 'pass-captain' ) ); }
		$data = self::slot_input( $in, (int) $resource->event_id );
		return is_wp_error( $data ) ? $data : ServiceBookings::create_slot( (int) $resource->resource_id, $data );
	}

	public static function handle_save_resource() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_resource_save_' . Forms::int( $in, 'resource_id' ) );
		Forms::finish( self::save_resource( $in ), __( 'Ressource gespeichert.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_delete_resource() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_resource_delete_' . Forms::int( $in, 'resource_id' ) );
		Forms::finish( ServiceBookings::delete_resource( Forms::int( $in, 'resource_id' ) ), __( 'Ressource mit ihren Zeitfenstern gelöscht.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_save_slot() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_slot_save_' . Forms::int( $in, 'slot_id' ) );
		Forms::finish( self::save_slot( $in ), __( 'Zeitfenster gespeichert.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_delete_slot() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_slot_delete_' . Forms::int( $in, 'slot_id' ) );
		Forms::finish( ServiceBookings::delete_slot( Forms::int( $in, 'slot_id' ) ), __( 'Zeitfenster gelöscht.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	private static function status_label( $status ) {
		$labels = array(
			'active' => __( 'Aktiv', 'pass-captain' ),
			'inactive' => __( 'Deaktiviert', 'pass-captain' ),
			'open' => __( 'Buchbar', 'pass-captain' ),
			'closed' => __( 'Geschlossen', 'pass-captain' ),
			'held' => __( 'Reserviert', 'pass-captain' ),
			'confirmed' => __( 'Bestätigt', 'pass-captain' ),
			'expired' => __( 'Abgelaufen', 'pass-captain' ),
		);
		return $labels[ $status ] ?? Forms::status_label( $status );
	}

	/** Resource fields (shared by the create and edit forms). */
	private static function resource_fields( $resource = null ) {
		echo '<p><label>' . esc_html__( 'Name', 'pass-captain' ) . ' <input type="text" name="name" maxlength="190" required value="' . esc_attr( $resource ? (string) $resource->name : '' ) . '" placeholder="' . esc_attr__( 'z. B. Workshopraum 1', 'pass-captain' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Plätze je Zeitfenster', 'pass-captain' ) . ' <input type="number" name="capacity" min="1" max="100" class="small-text" value="' . esc_attr( $resource ? (string) (int) $resource->capacity : '1' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Puffer vorher (Min.)', 'pass-captain' ) . ' <input type="number" name="buffer_before" min="0" max="1440" class="small-text" value="' . esc_attr( $resource ? (string) (int) $resource->buffer_before : '0' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Puffer danach (Min.)', 'pass-captain' ) . ' <input type="number" name="buffer_after" min="0" max="1440" class="small-text" value="' . esc_attr( $resource ? (string) (int) $resource->buffer_after : '0' ) . '"></label>';
		if ( $resource ) {
			echo ' <label>' . esc_html__( 'Status', 'pass-captain' ) . ' ';
			Forms::select( 'status', array( 'active' => self::status_label( 'active' ), 'inactive' => self::status_label( 'inactive' ) ), (string) $resource->status );
			echo '</label>';
		}
		echo '</p>';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$events = Queries::event_options( Queries::event_ids_for( array( self::CAP ) ) );
		$event_id = absint( Forms::query_arg( 'event_id' ) );
		if ( ! $event_id && 1 === count( $events ) ) { $event_id = (int) array_key_first( $events ); }
		$slot_filter = absint( Forms::query_arg( 'slot' ) );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Service-Zeitfenster', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Ressourcen sind Räume, Personen oder Geräte eines Events. Für jede Ressource legst du buchbare Zeitfenster mit einer Platzzahl an; Kund:innen reservieren sie über die öffentliche Buchungsschnittstelle. Zeitfenster mit Buchungen können nicht gelöscht, aber geschlossen werden.', 'pass-captain' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-service-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( 0 => __( '– Event wählen –', 'pass-captain' ) ) + $events, (string) $event_id, 'wpcap-service-event' );
		echo ' <button class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button></form>';
		if ( ! $event_id ) {
			echo $events ? '' : '<p>' . esc_html__( 'Es gibt noch kein Event, das du verwalten darfst.', 'pass-captain' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			echo '</div>';
			return;
		}
		if ( ! isset( $events[ $event_id ] ) || ! Access::can_access_event( $event_id, self::CAP ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) . '</p></div></div>';
			return;
		}
		$zone = self::timezone( $event_id );
		$resources = ServiceBookings::resources( $event_id );
		$slots = ServiceBookings::slots( $event_id );

		echo '<h2>' . esc_html__( 'Ressourcen', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Plätze je Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Puffer vorher/danach (Min.)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $resources as $resource ) {
			$rid = (int) $resource->resource_id;
			echo '<tr><td>' . esc_html( $resource->name ) . '</td><td>' . esc_html( (string) (int) $resource->capacity ) . '</td><td>' . esc_html( (int) $resource->buffer_before . ' / ' . (int) $resource->buffer_after ) . '</td><td>' . esc_html( (string) (int) $resource->slot_count ) . '</td><td>' . esc_html( self::status_label( $resource->status ) ) . '</td><td>';
			echo '<details><summary>' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</summary>';
			Forms::open_form( 'wpcap_service_resource_save', 'wpcap_service_resource_save_' . $rid );
			Forms::hidden( 'resource_id', $rid );
			Forms::hidden( 'event_id', $event_id );
			self::resource_fields( $resource );
			echo '<p><button class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form></details>';
			Forms::open_form( 'wpcap_service_resource_delete', 'wpcap_service_resource_delete_' . $rid, 'onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Ressource mit allen Zeitfenstern löschen? Das geht nur, solange es keine Buchungen gibt.', 'pass-captain' ) . '"' );
			Forms::hidden( 'resource_id', $rid );
			Forms::hidden( 'event_id', $event_id );
			echo '<button class="button-link button-link-delete">' . esc_html__( 'Löschen', 'pass-captain' ) . '</button></form></td></tr>';
		}
		if ( ! $resources ) { echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Ressourcen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Ressource hinzufügen', 'pass-captain' ) . '</h3>';
		Forms::open_form( 'wpcap_service_resource_save', 'wpcap_service_resource_save_0' );
		Forms::hidden( 'event_id', $event_id );
		self::resource_fields();
		submit_button( __( 'Ressource anlegen', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</h2>';
		/* translators: %s: timezone */
		echo '<p class="description">' . esc_html( sprintf( __( 'Alle Zeiten in %s.', 'pass-captain' ), $zone ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Ressource', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Beginn', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ende', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gebucht / Plätze', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $slots as $slot ) {
			$sid = (int) $slot->slot_id;
			echo '<tr><td>' . esc_html( $slot->resource_name ) . '</td><td>' . esc_html( Forms::datetime( $slot->starts_at, $zone, false ) ) . '</td><td>' . esc_html( Forms::datetime( $slot->ends_at, $zone, false ) ) . '</td><td>' . esc_html( (int) $slot->booked . ' / ' . (int) $slot->capacity ) . '</td><td>' . esc_html( self::status_label( $slot->status ) ) . '</td><td>';
			echo '<details><summary>' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</summary>';
			Forms::open_form( 'wpcap_service_slot_save', 'wpcap_service_slot_save_' . $sid );
			Forms::hidden( 'slot_id', $sid );
			Forms::hidden( 'event_id', $event_id );
			$locked = (int) $slot->booked > 0 ? ' readonly' : '';
			echo '<p><label>' . esc_html__( 'Beginn', 'pass-captain' ) . ' <input type="datetime-local" name="starts_at" required value="' . esc_attr( Forms::utc_to_local( $slot->starts_at, $zone ) ) . '"' . $locked . '></label> '; // phpcs:ignore WordPress.Security.EscapeOutput -- $locked is a literal.
			echo '<label>' . esc_html__( 'Ende', 'pass-captain' ) . ' <input type="datetime-local" name="ends_at" required value="' . esc_attr( Forms::utc_to_local( $slot->ends_at, $zone ) ) . '"' . $locked . '></label> '; // phpcs:ignore WordPress.Security.EscapeOutput -- $locked is a literal.
			echo '<label>' . esc_html__( 'Plätze', 'pass-captain' ) . ' <input type="number" name="capacity" min="' . esc_attr( (string) max( 1, (int) $slot->booked ) ) . '" max="100" class="small-text" value="' . esc_attr( (string) (int) $slot->capacity ) . '"></label> ';
			echo '<label>' . esc_html__( 'Status', 'pass-captain' ) . ' ';
			Forms::select( 'status', array( 'open' => self::status_label( 'open' ), 'closed' => self::status_label( 'closed' ) ), (string) $slot->status );
			echo '</label></p>';
			if ( $locked ) { echo '<p class="description">' . esc_html__( 'Das Zeitfenster hat Buchungen; Beginn und Ende sind fest.', 'pass-captain' ) . '</p>'; }
			echo '<p><button class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form></details>';
			if ( (int) $slot->booking_rows ) {
				echo '<a href="' . esc_url( self::url( $event_id, array( 'slot' => $sid ) ) . '#wpcap-service-bookings' ) . '">' . esc_html__( 'Buchungen', 'pass-captain' ) . '</a>';
			} else {
				Forms::open_form( 'wpcap_service_slot_delete', 'wpcap_service_slot_delete_' . $sid );
				Forms::hidden( 'slot_id', $sid );
				Forms::hidden( 'event_id', $event_id );
				echo '<button class="button-link button-link-delete">' . esc_html__( 'Löschen', 'pass-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		if ( ! $slots ) { echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Zeitfenster.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		$active = array();
		foreach ( $resources as $resource ) {
			if ( 'active' === $resource->status ) { $active[ (int) $resource->resource_id ] = $resource->name; }
		}
		echo '<h3>' . esc_html__( 'Zeitfenster hinzufügen', 'pass-captain' ) . '</h3>';
		if ( ! $active ) {
			echo '<p>' . esc_html__( 'Lege zuerst eine aktive Ressource an.', 'pass-captain' ) . '</p>';
		} else {
			Forms::open_form( 'wpcap_service_slot_save', 'wpcap_service_slot_save_0' );
			Forms::hidden( 'event_id', $event_id );
			echo '<p><label>' . esc_html__( 'Ressource', 'pass-captain' ) . ' ';
			Forms::select( 'resource_id', $active, '' );
			echo '</label> <label>' . esc_html__( 'Beginn', 'pass-captain' ) . ' <input type="datetime-local" name="starts_at" required></label> ';
			echo '<label>' . esc_html__( 'Ende', 'pass-captain' ) . ' <input type="datetime-local" name="ends_at" required></label> ';
			echo '<label>' . esc_html__( 'Plätze', 'pass-captain' ) . ' <input type="number" name="capacity" min="1" max="100" class="small-text" placeholder="' . esc_attr__( 'wie Ressource', 'pass-captain' ) . '"></label></p>';
			submit_button( __( 'Zeitfenster anlegen', 'pass-captain' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		$bookings = ServiceBookings::bookings( $event_id, $slot_filter );
		if ( $bookings ) { \PassCaptain\Privacy\AccessLog::list_view( 'service_bookings', array( 'event_id' => $event_id, 'slot' => $slot_filter ), count( $bookings ), 'event', $event_id ); }
		echo '<h2 id="wpcap-service-bookings">' . esc_html__( 'Buchungen', 'pass-captain' ) . '</h2>';
		if ( $slot_filter ) {
			echo '<p><a href="' . esc_url( self::url( $event_id ) . '#wpcap-service-bookings' ) . '">' . esc_html__( 'Alle Buchungen des Events anzeigen', 'pass-captain' ) . '</a></p>';
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Buchung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ressource', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Plätze', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gebucht am', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $bookings as $booking ) {
			echo '<tr><td>#' . esc_html( (string) (int) $booking->booking_id ) . '</td><td>' . esc_html( $booking->resource_name ) . '</td><td>' . esc_html( Forms::datetime( $booking->starts_at, $zone, false ) . ' – ' . Forms::utc_to_local( $booking->ends_at, $zone, 'H:i' ) ) . '</td><td>' . esc_html( Pii::show( 'buyer_email', (string) $booking->customer_email ) ) . '</td><td>' . esc_html( (string) (int) $booking->quantity ) . '</td><td>' . esc_html( self::status_label( ServiceBookings::booking_status( $booking ) ) ) . '</td><td>' . esc_html( Forms::datetime( $booking->created_at, $zone, false ) ) . '</td></tr>';
		}
		if ( ! $bookings ) { echo '<tr><td colspan="7">' . esc_html__( 'Keine Buchungen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
