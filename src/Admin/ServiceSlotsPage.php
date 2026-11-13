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
use PassCaptain\ServiceProviders;

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
		$data = array( 'name' => Forms::text( $in, 'name', 190 ), 'capacity' => Forms::int( $in, 'capacity', 1 ), 'buffer_before' => Forms::int( $in, 'buffer_before' ), 'buffer_after' => Forms::int( $in, 'buffer_after' ), 'duration_minutes' => Forms::text( $in, 'duration_minutes', 5 ), 'lead_minutes' => self::lead_minutes( $in ), 'advance_days' => Forms::text( $in, 'advance_days', 5 ), 'max_per_customer' => Forms::text( $in, 'max_per_customer', 5 ), 'rebook_hours' => Forms::text( $in, 'rebook_hours', 5 ), 'cancel_hours' => Forms::text( $in, 'cancel_hours', 5 ), 'status' => Forms::choice( $in, 'status', array( 'active', 'inactive' ), '' ) );
		if ( array_key_exists( 'ticket_type_id', $in ) ) { $data['ticket_type_id'] = Forms::int( $in, 'ticket_type_id' ); }
		$resource_id = Forms::int( $in, 'resource_id' );
		return $resource_id ? ServiceBookings::update_resource( $resource_id, $data ) : ServiceBookings::create_resource( Forms::int( $in, 'event_id' ), $data );
	}

	/** Booking cutoff from the form: value + unit (minutes/hours/days) → minutes ('' = unchanged). */
	private static function lead_minutes( array $in ) {
		$value = Forms::text( $in, 'lead_value', 6 );
		if ( '' === $value ) { return ''; }
		$factor = array( 'minutes' => 1, 'hours' => 60, 'days' => 1440 );
		return (string) ( absint( $value ) * $factor[ Forms::choice( $in, 'lead_unit', array_keys( $factor ), 'hours' ) ] );
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

	/** Generates a slot series for a resource; form dates/times are local (event timezone). */
	public static function generate_slots( array $in ) {
		$resource = ServiceBookings::resource( Forms::int( $in, 'resource_id' ) );
		if ( ! $resource || ! Access::can_access_event( (int) $resource->event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Ressource.', 'pass-captain' ) ); }
		return ServiceBookings::generate_slots( (int) $resource->resource_id, array( 'date_from' => Forms::text( $in, 'date_from', 10 ), 'date_to' => Forms::text( $in, 'date_to', 10 ), 'time_from' => Forms::text( $in, 'time_from', 5 ), 'time_to' => Forms::text( $in, 'time_to', 5 ), 'weekdays' => Forms::ids( $in, 'weekdays' ), 'duration' => Forms::text( $in, 'duration', 5 ), 'capacity' => Forms::text( $in, 'capacity', 5 ), 'timezone' => self::timezone( (int) $resource->event_id ) ) );
	}

	/** Creates (no provider_id) or updates a service provider. */
	public static function save_provider( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		return ServiceProviders::save( array( 'name' => Forms::text( $in, 'name', 190 ), 'email' => Forms::text( $in, 'email', 190 ), 'user_id' => Forms::int( $in, 'user_id' ), 'partner_id' => Forms::int( $in, 'partner_id' ), 'status' => Forms::choice( $in, 'status', array( 'active', 'inactive' ), '' ) ), Forms::int( $in, 'provider_id' ) );
	}

	public static function handle_generate_slots() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_slots_generate' );
		$result = self::generate_slots( $in );
		/* translators: 1: created slots, 2: skipped slots */
		$message = is_array( $result ) ? sprintf( __( '%1$d Zeitfenster angelegt, %2$d übersprungen (Überschneidung oder Vergangenheit).', 'pass-captain' ), $result['created'], $result['skipped'] ) : '';
		Forms::finish( is_array( $result ) ? true : $result, $message, self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_save_provider() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_provider_save_' . Forms::int( $in, 'provider_id' ) );
		Forms::finish( self::save_provider( $in ), __( 'Leistungserbringer gespeichert.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_assign_providers() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_resource_providers_' . Forms::int( $in, 'resource_id' ) );
		Forms::finish( ServiceProviders::assign( Forms::int( $in, 'resource_id' ), Forms::ids( $in, 'provider_ids' ) ), __( 'Leistungserbringer der Ressource gespeichert.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	/**
	 * Staff action on a booking (A135): confirm (held), cancel (refund optional), rebook (any open
	 * slot of the event; deadlines do not apply to staff, capacity/buffers/providers/window do).
	 */
	public static function booking_action( array $in ) {
		$booking = ServiceBookings::booking( Forms::int( $in, 'booking_id' ) );
		if ( ! $booking ) { return Forms::error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $booking->event_id, self::CAP ) ) { return Forms::forbidden(); }
		$op = Forms::choice( $in, 'op', array( 'confirm', 'cancel', 'rebook' ), '' );
		if ( 'confirm' === $op ) { return ServiceBookings::confirm( (int) $booking->booking_id, array( 'actor' => 'admin' ) ); }
		if ( 'cancel' === $op ) { return ServiceBookings::cancel( (int) $booking->booking_id, array( 'actor' => 'admin', 'refund' => Forms::flag( $in, 'refund' ), 'reason' => Forms::text( $in, 'reason', 190 ) ) ); }
		if ( 'rebook' === $op ) { return ServiceBookings::rebook( (int) $booking->booking_id, Forms::int( $in, 'slot_id' ), array( 'actor' => 'admin' ) ); }
		return Forms::error( 'wpcap_service_op', __( 'Unbekannte Aktion.', 'pass-captain' ) );
	}

	public static function handle_booking_action() {
		$in = Forms::post();
		Forms::verify( 'wpcap_service_booking_action_' . Forms::int( $in, 'booking_id' ) );
		$result = self::booking_action( $in );
		$message = __( 'Buchung aktualisiert.', 'pass-captain' );
		if ( is_array( $result ) && is_wp_error( $result['refund'] ?? null ) ) {
			/* translators: %s: error message */
			$message = sprintf( __( 'Buchung storniert, die Erstattung ist fehlgeschlagen: %s', 'pass-captain' ), $result['refund']->get_error_message() );
		}
		Forms::finish( is_wp_error( $result ) ? $result : true, $message, self::url( Forms::int( $in, 'event_id' ) ) . '#wpcap-service-bookings' );
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
			'cancelled' => __( 'Storniert', 'pass-captain' ),
			'pending_payment' => __( 'Zahlung offen', 'pass-captain' ),
			'unallocated' => __( 'Bezahlt, Termin vergeben (Erstattung)', 'pass-captain' ),
		);
		return $labels[ $status ] ?? Forms::status_label( $status );
	}

	/** Ticket types of the event as price options: id => "Name (Preis)". */
	private static function ticket_types( $event_id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.name,tt.price_minor,tt.currency FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d ORDER BY o.starts_at,tt.ticket_type_id LIMIT 200", absint( $event_id ) ) ) as $type ) {
			$out[ (int) $type->ticket_type_id ] = $type->name . ' (' . Forms::money( (int) $type->price_minor, (string) $type->currency ) . ')';
		}
		return $out;
	}

	/** Resource fields (shared by the create and edit forms). */
	private static function resource_fields( $resource = null, array $types = array() ) {
		echo '<p><label>' . esc_html__( 'Name', 'pass-captain' ) . ' <input type="text" name="name" maxlength="190" required value="' . esc_attr( $resource ? (string) $resource->name : '' ) . '" placeholder="' . esc_attr__( 'z. B. Workshopraum 1', 'pass-captain' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Plätze je Zeitfenster', 'pass-captain' ) . ' <input type="number" name="capacity" min="1" max="100" class="small-text" value="' . esc_attr( $resource ? (string) (int) $resource->capacity : '1' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Puffer vorher (Min.)', 'pass-captain' ) . ' <input type="number" name="buffer_before" min="0" max="1440" class="small-text" value="' . esc_attr( $resource ? (string) (int) $resource->buffer_before : '0' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Puffer danach (Min.)', 'pass-captain' ) . ' <input type="number" name="buffer_after" min="0" max="1440" class="small-text" value="' . esc_attr( $resource ? (string) (int) $resource->buffer_after : '0' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Dauer je Zeitfenster (Min.)', 'pass-captain' ) . ' <input type="number" name="duration_minutes" min="5" max="1440" class="small-text" value="' . esc_attr( (string) (int) ( $resource->duration_minutes ?? 60 ) ) . '"></label>';
		if ( ServiceBookings::rules_ready() ) {
			$rules = $resource ? ServiceBookings::rules( $resource ) : array_fill_keys( array_keys( ServiceBookings::RULES ), 0 );
			$lead = $rules['lead_minutes'];
			$unit = $lead && 0 === $lead % 1440 ? 'days' : ( 0 === $lead % 60 ? 'hours' : 'minutes' );
			$lead_value = array( 'days' => $lead / 1440, 'hours' => $lead / 60, 'minutes' => $lead )[ $unit ];
			echo '</p><fieldset><legend>' . esc_html__( 'Buchungsregeln', 'pass-captain' ) . '</legend><p>';
			echo '<label>' . esc_html__( 'Buchungsschluss vor Beginn', 'pass-captain' ) . ' <input type="number" name="lead_value" min="0" class="small-text" value="' . esc_attr( (string) (int) $lead_value ) . '"></label> ';
			Forms::select( 'lead_unit', array( 'minutes' => __( 'Minuten', 'pass-captain' ), 'hours' => __( 'Stunden', 'pass-captain' ), 'days' => __( 'Tage', 'pass-captain' ) ), $unit, '', 'aria-label="' . esc_attr__( 'Einheit Buchungsschluss', 'pass-captain' ) . '"' );
			echo ' <label>' . esc_html__( 'Höchstens Tage im Voraus', 'pass-captain' ) . ' <input type="number" name="advance_days" min="0" max="730" class="small-text" value="' . esc_attr( (string) $rules['advance_days'] ) . '"></label> ';
			echo '<label>' . esc_html__( 'Max. offene Buchungen pro Person', 'pass-captain' ) . ' <input type="number" name="max_per_customer" min="0" max="100" class="small-text" value="' . esc_attr( (string) $rules['max_per_customer'] ) . '"></label> ';
			echo '<label>' . esc_html__( 'Umbuchung bis (Std. vor Beginn)', 'pass-captain' ) . ' <input type="number" name="rebook_hours" min="0" max="8760" class="small-text" value="' . esc_attr( (string) $rules['rebook_hours'] ) . '"></label> ';
			echo '<label>' . esc_html__( 'Stornierung bis (Std. vor Beginn)', 'pass-captain' ) . ' <input type="number" name="cancel_hours" min="0" max="8760" class="small-text" value="' . esc_attr( (string) $rules['cancel_hours'] ) . '"></label></p>';
			echo '<p class="description">' . esc_html__( '0 heißt: keine Einschränkung bzw. bis zum Beginn. Die Regeln gelten serverseitig für Reservierung, Bestätigung, Umbuchung und Stornierung durch Kund:innen; das Team kann Fristen in der Buchungsliste übergehen.', 'pass-captain' ) . '</p></fieldset><p>';
		}
		if ( ServiceBookings::flow_ready() && $types ) {
			echo '<label>' . esc_html__( 'Preis (Ticketart)', 'pass-captain' ) . ' ';
			Forms::select( 'ticket_type_id', array( 0 => __( '– kostenlos –', 'pass-captain' ) ) + $types, (string) (int) ( $resource->ticket_type_id ?? 0 ) );
			echo '</label> ';
		}
		if ( $resource ) {
			echo ' <label>' . esc_html__( 'Status', 'pass-captain' ) . ' ';
			Forms::select( 'status', array( 'active' => self::status_label( 'active' ), 'inactive' => self::status_label( 'inactive' ) ), (string) $resource->status );
			echo '</label>';
		}
		echo '</p>';
	}

	/** Provider list with edit forms and the create form (A133). */
	private static function render_providers( array $providers, $event_id ) {
		if ( ! ServiceBookings::providers_ready() ) { return; }
		echo '<h2 id="wpcap-service-providers">' . esc_html__( 'Leistungserbringer', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Personen oder Partnerfirmen, die eine Leistung erbringen (z. B. Ausbilder:in, Skipper:in). Ordne sie den Ressourcen zu; dann können sie nicht doppelt gebucht werden.', 'pass-captain' ) . '</p>';
		$partners = array( 0 => __( '– kein Partner –', 'pass-captain' ) );
		if ( \PassCaptain\Modules::enabled( \PassCaptain\Modules\Partners::class ) ) {
			foreach ( (array) \PassCaptain\Partners\Repository::all() as $partner ) { $partners[ (int) $partner->partner_id ] = (string) $partner->name; }
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $providers as $provider ) {
			$pid = (int) $provider->provider_id;
			echo '<tr><td>' . esc_html( $provider->name ) . '</td><td>' . esc_html( (string) $provider->email ) . '</td><td>' . esc_html( self::status_label( $provider->status ) ) . '</td><td><details><summary>' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</summary>';
			Forms::open_form( 'wpcap_service_provider_save', 'wpcap_service_provider_save_' . $pid );
			Forms::hidden( 'provider_id', $pid );
			Forms::hidden( 'event_id', $event_id );
			self::provider_fields( $provider, $partners );
			echo '<p><button class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form></details></td></tr>';
		}
		if ( ! $providers ) { echo '<tr><td colspan="4">' . esc_html__( 'Noch keine Leistungserbringer.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table><h3>' . esc_html__( 'Leistungserbringer hinzufügen', 'pass-captain' ) . '</h3>';
		Forms::open_form( 'wpcap_service_provider_save', 'wpcap_service_provider_save_0' );
		Forms::hidden( 'event_id', $event_id );
		self::provider_fields( null, $partners );
		submit_button( __( 'Leistungserbringer anlegen', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function provider_fields( $provider, array $partners ) {
		echo '<p><label>' . esc_html__( 'Name', 'pass-captain' ) . ' <input type="text" name="name" maxlength="190" required value="' . esc_attr( $provider ? (string) $provider->name : '' ) . '"></label> ';
		echo '<label>' . esc_html__( 'E-Mail', 'pass-captain' ) . ' <input type="email" name="email" maxlength="190" value="' . esc_attr( $provider ? (string) $provider->email : '' ) . '"></label> ';
		echo '<label>' . esc_html__( 'WordPress-Benutzer-ID', 'pass-captain' ) . ' <input type="number" name="user_id" min="0" class="small-text" value="' . esc_attr( $provider ? (string) (int) $provider->user_id : '0' ) . '"></label> ';
		if ( count( $partners ) > 1 ) {
			echo '<label>' . esc_html__( 'Partner', 'pass-captain' ) . ' ';
			Forms::select( 'partner_id', $partners, $provider ? (string) (int) $provider->partner_id : '0' );
			echo '</label> ';
		}
		if ( $provider ) {
			echo '<label>' . esc_html__( 'Status', 'pass-captain' ) . ' ';
			Forms::select( 'status', array( 'active' => self::status_label( 'active' ), 'inactive' => self::status_label( 'inactive' ) ), (string) $provider->status );
			echo '</label>';
		}
		echo '</p>';
	}

	/** Form "Zeitfenster-Serie erzeugen" (A133): weekdays, date range, daily from–to, duration. */
	private static function render_series_form( array $active, $event_id ) {
		echo '<h3>' . esc_html__( 'Zeitfenster-Serie erzeugen', 'pass-captain' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Legt an den gewählten Wochentagen Zeitfenster der angegebenen Dauer an; zwischen zwei Zeitfenstern bleibt der Puffer der Ressource (danach + vorher) frei. Überschneidungen mit bestehenden Zeitfenstern werden übersprungen.', 'pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_service_slots_generate' );
		Forms::hidden( 'event_id', $event_id );
		echo '<p><label>' . esc_html__( 'Ressource', 'pass-captain' ) . ' ';
		Forms::select( 'resource_id', $active, '' );
		echo '</label> <label>' . esc_html__( 'Von (Datum)', 'pass-captain' ) . ' <input type="date" name="date_from" required></label> ';
		echo '<label>' . esc_html__( 'Bis (Datum)', 'pass-captain' ) . ' <input type="date" name="date_to" required></label> ';
		echo '<label>' . esc_html__( 'Täglich ab', 'pass-captain' ) . ' <input type="time" name="time_from" required></label> ';
		echo '<label>' . esc_html__( 'bis', 'pass-captain' ) . ' <input type="time" name="time_to" required></label> ';
		echo '<label>' . esc_html__( 'Dauer (Min.)', 'pass-captain' ) . ' <input type="number" name="duration" min="5" max="1440" class="small-text" placeholder="' . esc_attr__( 'wie Ressource', 'pass-captain' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Plätze', 'pass-captain' ) . ' <input type="number" name="capacity" min="1" max="100" class="small-text" placeholder="' . esc_attr__( 'wie Ressource', 'pass-captain' ) . '"></label></p>';
		$days = array( 1 => __( 'Mo', 'pass-captain' ), 2 => __( 'Di', 'pass-captain' ), 3 => __( 'Mi', 'pass-captain' ), 4 => __( 'Do', 'pass-captain' ), 5 => __( 'Fr', 'pass-captain' ), 6 => __( 'Sa', 'pass-captain' ), 7 => __( 'So', 'pass-captain' ) );
		echo '<fieldset><legend>' . esc_html__( 'Wochentage', 'pass-captain' ) . '</legend>';
		foreach ( $days as $number => $label ) {
			echo '<label><input type="checkbox" name="weekdays[]" value="' . esc_attr( (string) $number ) . '"' . ( $number <= 5 ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '</fieldset>';
		submit_button( __( 'Serie erzeugen', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** Action forms of one booking row (only for bookings that still occupy places). */
	private static function booking_actions( $booking, $event_id, array $targets ) {
		$status = ServiceBookings::booking_status( $booking );
		if ( (int) $booking->order_id ) { echo '<a href="' . esc_url( Forms::page_url( 'wpcap-orders', array( 'order_id' => (int) $booking->order_id ) ) ) . '">' . esc_html__( 'Bestellung', 'pass-captain' ) . '</a> '; }
		if ( ! in_array( $status, array( 'held', 'pending_payment', 'confirmed' ), true ) ) { return; }
		$bid = (int) $booking->booking_id;
		$open = static function ( $op ) use ( $bid, $event_id ) {
			Forms::open_form( 'wpcap_service_booking_action', 'wpcap_service_booking_action_' . $bid );
			Forms::hidden( 'booking_id', $bid );
			Forms::hidden( 'event_id', $event_id );
			Forms::hidden( 'op', $op );
		};
		if ( 'held' === $status ) {
			$open( 'confirm' );
			echo '<button class="button-link">' . esc_html__( 'Bestätigen', 'pass-captain' ) . '</button></form>';
		}
		unset( $targets[ (int) $booking->slot_id ] );
		if ( $targets ) {
			echo '<details><summary>' . esc_html__( 'Umbuchen', 'pass-captain' ) . '</summary>';
			$open( 'rebook' );
			echo '<label class="screen-reader-text" for="wpcap-rebook-' . esc_attr( (string) $bid ) . '">' . esc_html__( 'Neues Zeitfenster', 'pass-captain' ) . '</label>';
			Forms::select( 'slot_id', $targets, '', 'wpcap-rebook-' . $bid );
			echo ' <button class="button">' . esc_html__( 'Umbuchen', 'pass-captain' ) . '</button></form></details>';
		}
		echo '<details><summary>' . esc_html__( 'Stornieren', 'pass-captain' ) . '</summary>';
		$open( 'cancel' );
		echo '<p><label>' . esc_html__( 'Grund', 'pass-captain' ) . ' <input type="text" name="reason" maxlength="190"></label></p>';
		if ( 'confirmed' === $status && (int) $booking->order_id ) { echo '<p><label><input type="checkbox" name="refund" value="1" checked> ' . esc_html__( 'Zahlung vollständig erstatten', 'pass-captain' ) . '</label></p>'; }
		echo '<p class="description">' . esc_html__( 'Fristen gelten für das Team nicht. Kund:in und Team werden per E-Mail informiert.', 'pass-captain' ) . '</p><button class="button">' . esc_html__( 'Buchung stornieren', 'pass-captain' ) . '</button></form></details>';
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
		$types = self::ticket_types( $event_id );
		$resources = ServiceBookings::resources( $event_id );
		$slots = ServiceBookings::slots( $event_id );

		echo '<h2>' . esc_html__( 'Ressourcen', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Plätze je Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Puffer vorher/danach (Min.)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Leistungserbringer', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$providers = ServiceProviders::all();
		foreach ( $resources as $resource ) {
			$rid = (int) $resource->resource_id;
			$assigned = ServiceProviders::for_resource( $rid );
			echo '<tr><td>' . esc_html( $resource->name ) . '</td><td>' . esc_html( (string) (int) $resource->capacity ) . '</td><td>' . esc_html( (int) $resource->buffer_before . ' / ' . (int) $resource->buffer_after ) . '</td><td>' . esc_html( $assigned ? implode( ', ', array_column( $assigned, 'name' ) ) : '–' ) . '</td><td>' . esc_html( (string) (int) $resource->slot_count ) . '</td><td>' . esc_html( self::status_label( $resource->status ) ) . '</td><td>';
			echo '<details><summary>' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</summary>';
			Forms::open_form( 'wpcap_service_resource_save', 'wpcap_service_resource_save_' . $rid );
			Forms::hidden( 'resource_id', $rid );
			Forms::hidden( 'event_id', $event_id );
			self::resource_fields( $resource, $types );
			echo '<p><button class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form></details>';
			if ( $providers ) {
				echo '<details><summary>' . esc_html__( 'Leistungserbringer zuordnen', 'pass-captain' ) . '</summary>';
				Forms::open_form( 'wpcap_service_resource_providers', 'wpcap_service_resource_providers_' . $rid );
				Forms::hidden( 'resource_id', $rid );
				Forms::hidden( 'event_id', $event_id );
				$assigned_ids = array_map( 'intval', array_column( $assigned, 'provider_id' ) );
				echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Leistungserbringer', 'pass-captain' ) . '</legend>';
				foreach ( $providers as $provider ) {
					echo '<label><input type="checkbox" name="provider_ids[]" value="' . esc_attr( (string) (int) $provider->provider_id ) . '"' . ( in_array( (int) $provider->provider_id, $assigned_ids, true ) ? ' checked' : '' ) . '> ' . esc_html( $provider->name . ( 'active' === $provider->status ? '' : ' (' . self::status_label( 'inactive' ) . ')' ) ) . '</label><br>';
				}
				echo '</fieldset><p class="description">' . esc_html__( 'Eine Buchung belegt auch die zugeordneten Leistungserbringer: Sie können zur selben Zeit (inklusive Puffer) nicht über eine andere Ressource gebucht werden.', 'pass-captain' ) . '</p>';
				echo '<p><button class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form></details>';
			}
			Forms::open_form( 'wpcap_service_resource_delete', 'wpcap_service_resource_delete_' . $rid, 'onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Ressource mit allen Zeitfenstern löschen? Das geht nur, solange es keine Buchungen gibt.', 'pass-captain' ) . '"' );
			Forms::hidden( 'resource_id', $rid );
			Forms::hidden( 'event_id', $event_id );
			echo '<button class="button-link button-link-delete">' . esc_html__( 'Löschen', 'pass-captain' ) . '</button></form></td></tr>';
		}
		if ( ! $resources ) { echo '<tr><td colspan="7">' . esc_html__( 'Noch keine Ressourcen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Ressource hinzufügen', 'pass-captain' ) . '</h3>';
		Forms::open_form( 'wpcap_service_resource_save', 'wpcap_service_resource_save_0' );
		Forms::hidden( 'event_id', $event_id );
		self::resource_fields( null, $types );
		submit_button( __( 'Ressource anlegen', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';
		self::render_providers( $providers, $event_id );

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
			self::render_series_form( $active, $event_id );
		}

		$bookings = ServiceBookings::bookings( $event_id, $slot_filter );
		if ( $bookings ) { \PassCaptain\Privacy\AccessLog::list_view( 'service_bookings', array( 'event_id' => $event_id, 'slot' => $slot_filter ), count( $bookings ), 'event', $event_id ); }
		echo '<h2 id="wpcap-service-bookings">' . esc_html__( 'Buchungen', 'pass-captain' ) . '</h2>';
		if ( $slot_filter ) {
			echo '<p><a href="' . esc_url( self::url( $event_id ) . '#wpcap-service-bookings' ) . '">' . esc_html__( 'Alle Buchungen des Events anzeigen', 'pass-captain' ) . '</a></p>';
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Buchung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ressource', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Plätze', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gebucht am', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$targets = array();
		foreach ( $slots as $slot ) {
			if ( 'open' === $slot->status && $slot->starts_at > current_time( 'mysql', true ) ) { $targets[ (int) $slot->slot_id ] = $slot->resource_name . ' · ' . Forms::datetime( $slot->starts_at, $zone, false ) . ' (' . ( (int) $slot->capacity - (int) $slot->booked ) . ')'; }
		}
		foreach ( $bookings as $booking ) {
			echo '<tr><td>#' . esc_html( (string) (int) $booking->booking_id ) . '</td><td>' . esc_html( $booking->resource_name ) . '</td><td>' . esc_html( Forms::datetime( $booking->starts_at, $zone, false ) . ' – ' . Forms::utc_to_local( $booking->ends_at, $zone, 'H:i' ) ) . '</td><td>' . esc_html( Pii::show( 'buyer_email', (string) $booking->customer_email ) ) . '</td><td>' . esc_html( (string) (int) $booking->quantity ) . '</td><td>' . esc_html( self::status_label( ServiceBookings::booking_status( $booking ) ) ) . '</td><td>' . esc_html( Forms::datetime( $booking->created_at, $zone, false ) ) . '</td><td>';
			self::booking_actions( $booking, $event_id, $targets );
			echo '</td></tr>';
		}
		if ( ! $bookings ) { echo '<tr><td colspan="8">' . esc_html__( 'Keine Buchungen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
