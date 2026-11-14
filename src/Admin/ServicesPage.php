<?php
/**
 * Admin screen "Ressourcen & Zeitfenster": bookable resources per event (name, capacity, timezone,
 * organizer from the partners module), single slots, a simple weekly slot generator and the
 * bookings of a slot. Booking rules themselves live in ServiceBookings.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\ServiceResources;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServicesPage {
	const SLUG = 'wpcap-services';
	const ACTION = 'wpcap_service_action';
	const CAP = 'wpcap_manage_events';

	private static function can_event( $event_id ) {
		return $event_id && Access::can_access_event( $event_id, self::CAP );
	}

	/** Resource the current user may manage, or a WP_Error. */
	private static function managed_resource( $resource_id ) {
		$resource = ServiceResources::resource( $resource_id );
		if ( ! $resource ) { return Forms::error( 'wpcap_resource_missing', __( 'Ressource nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can_event( (int) $resource->event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Ressource.', 'pass-captain' ) ); }
		return $resource;
	}

	/** Slot the current user may manage, or a WP_Error. */
	private static function managed_slot( $slot_id ) {
		$slot = ServiceResources::slot( $slot_id );
		if ( ! $slot ) { return Forms::error( 'wpcap_service_slot_missing', __( 'Zeitfenster nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can_event( (int) $slot->event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Zeitfenster.', 'pass-captain' ) ); }
		return $slot;
	}

	/** Start/end inputs (local time of the resource) → UTC pair or WP_Error. */
	private static function range( array $in, $resource ) {
		$tz = ServiceResources::timezone_of( $resource );
		$starts = Forms::local_to_utc( $in['starts_at'] ?? '', $tz );
		$ends = Forms::local_to_utc( $in['ends_at'] ?? '', $tz );
		if ( null === $starts || null === $ends || $ends <= $starts ) { return Forms::error( 'wpcap_service_slot_invalid', __( 'Zeitfenster ist ungültig: Das Ende muss nach dem Beginn liegen.', 'pass-captain' ) ); }
		return array( $starts, $ends );
	}

	/**
	 * Executes an admin action (testable without a request).
	 *
	 * @return mixed|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = Forms::int( $in, 'id' );
		switch ( $do ) {
			case 'save_resource':
				$data = array( 'name' => Forms::text( $in, 'name' ), 'capacity' => Forms::int( $in, 'capacity', 1 ), 'timezone' => Forms::text( $in, 'timezone', 64 ), 'status' => Forms::choice( $in, 'status', ServiceResources::RESOURCE_STATUSES, 'active' ) );
				if ( $id ) {
					$resource = self::managed_resource( $id );
					if ( is_wp_error( $resource ) ) { return $resource; }
				} else {
					$data['event_id'] = Forms::int( $in, 'event_id' );
					if ( ! self::can_event( $data['event_id'] ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
				}
				$saved = ServiceResources::save_resource( $data, $id );
				return is_wp_error( $saved ) ? $saved : array( 'resource_id' => $saved );
			case 'create_slot':
				$resource = self::managed_resource( Forms::int( $in, 'resource_id' ) );
				if ( is_wp_error( $resource ) ) { return $resource; }
				$range = self::range( $in, $resource );
				if ( is_wp_error( $range ) ) { return $range; }
				$slot_id = ServiceResources::create_slot( (int) $resource->resource_id, $range[0], $range[1], Forms::int( $in, 'capacity' ) );
				return is_wp_error( $slot_id ) ? $slot_id : array( 'slot_id' => $slot_id, 'resource_id' => (int) $resource->resource_id );
			case 'generate_slots':
				$resource = self::managed_resource( Forms::int( $in, 'resource_id' ) );
				if ( is_wp_error( $resource ) ) { return $resource; }
				$result = ServiceResources::generate_weekly(
					(int) $resource->resource_id,
					array( 'start_date' => Forms::text( $in, 'start_date', 10 ), 'until_date' => Forms::text( $in, 'until_date', 10 ), 'weekdays' => Forms::ids( $in, 'weekdays' ), 'time' => Forms::text( $in, 'time', 5 ), 'duration' => Forms::int( $in, 'duration' ), 'capacity' => Forms::int( $in, 'capacity' ) )
				);
				return is_wp_error( $result ) ? $result : $result + array( 'resource_id' => (int) $resource->resource_id );
			case 'update_slot':
				$slot = self::managed_slot( $id );
				if ( is_wp_error( $slot ) ) { return $slot; }
				$range = self::range( $in, ServiceResources::resource( $slot->resource_id ) );
				if ( is_wp_error( $range ) ) { return $range; }
				$updated = ServiceResources::update_slot( $id, $range[0], $range[1], Forms::int( $in, 'capacity', 1 ), Forms::choice( $in, 'status', ServiceResources::SLOT_STATUSES, 'open' ) );
				return is_wp_error( $updated ) ? $updated : array( 'slot_id' => $updated, 'resource_id' => (int) $slot->resource_id );
			case 'delete_slot':
				$slot = self::managed_slot( $id );
				if ( is_wp_error( $slot ) ) { return $slot; }
				$deleted = ServiceResources::delete_slot( $id );
				return is_wp_error( $deleted ) ? $deleted : array( 'resource_id' => (int) $slot->resource_id );
		}
		return Forms::error( 'wpcap_service_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		$resource_id = is_array( $result ) && ! empty( $result['resource_id'] ) ? (int) $result['resource_id'] : Forms::int( $in, 'resource_id' );
		if ( 'update_slot' === $do && is_array( $result ) ) {
			$url = Forms::page_url( self::SLUG, array( 'slot_id' => (int) $result['slot_id'] ) );
		} else {
			$url = Forms::page_url( self::SLUG, $resource_id ? array( 'resource_id' => $resource_id ) : array() );
		}
		$message = __( 'Gespeichert.', 'pass-captain' );
		if ( 'generate_slots' === $do && is_array( $result ) ) {
			/* translators: 1: created slots, 2: skipped existing slots */
			$message = sprintf( __( '%1$d Zeitfenster erzeugt, %2$d bereits vorhandene übersprungen.', 'pass-captain' ), count( $result['created'] ), (int) $result['skipped'] );
		} elseif ( 'delete_slot' === $do ) {
			$message = __( 'Zeitfenster gelöscht.', 'pass-captain' );
		}
		$error_url = 'update_slot' === $do ? Forms::page_url( self::SLUG, array( 'slot_id' => Forms::int( $in, 'id' ) ) ) : '';
		Forms::finish( $result, $message, $url, $error_url );
	}

	/** Organizer name of an event when the partners module is active ('' otherwise). */
	public static function partner_name( $event_id ) {
		if ( ! in_array( 'PassCaptain\\Modules\\Partners', \PassCaptain\Modules::classes(), true ) || ! class_exists( '\\PassCaptain\\Partners\\Repository' ) ) { return ''; }
		$partner_id = \PassCaptain\Partners\Repository::partner_of_event( $event_id );
		$partner = $partner_id ? \PassCaptain\Partners\Repository::get( $partner_id ) : null;
		return $partner ? (string) $partner->name : '';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Ressourcen & Zeitfenster', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$slot_id = absint( Forms::query_arg( 'slot_id', 0 ) );
		$resource_id = absint( Forms::query_arg( 'resource_id', 0 ) );
		if ( $slot_id ) {
			self::render_slot( $slot_id );
		} elseif ( $resource_id ) {
			self::render_resource( $resource_id );
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview() {
		$scope = Queries::event_ids_for( self::CAP );
		$events = Queries::event_options( $scope );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( $event_id && ! isset( $events[ $event_id ] ) ) { $event_id = 0; }
		echo '<p>' . esc_html__( 'Ressourcen sind buchbare Dinge oder Personen (z. B. Räume, Guides, Geräte) eines Events. Für jede Ressource werden Zeitfenster mit eigener Kapazität angelegt, die Kundinnen und Kunden buchen können.', 'pass-captain' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-svc-filter">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( '' => __( 'Alle Events', 'pass-captain' ) ) + $events, $event_id, 'wpcap-svc-filter' );
		echo ' <button type="submit" class="button">' . esc_html__( 'Filtern', 'pass-captain' ) . '</button></form>';
		$resources = ServiceResources::resources( $scope, $event_id );
		echo '<h2>' . esc_html__( 'Ressourcen', 'pass-captain' ) . '</h2>';
		if ( ! $resources ) {
			echo '<p>' . esc_html__( 'Noch keine Ressourcen.', 'pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Name', 'pass-captain' ), __( 'Event', 'pass-captain' ), __( 'Veranstalter', 'pass-captain' ), __( 'Zeitzone', 'pass-captain' ), __( 'Kapazität', 'pass-captain' ), __( 'Zeitfenster', 'pass-captain' ), __( 'Status', 'pass-captain' ) ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $resources as $r ) {
				$partner = self::partner_name( (int) $r->event_id );
				echo '<tr><td><a href="' . esc_url( Forms::page_url( self::SLUG, array( 'resource_id' => (int) $r->resource_id ) ) ) . '"><strong>' . esc_html( $r->name ) . '</strong></a></td><td>' . esc_html( $r->event_name ) . '</td><td>' . esc_html( '' !== $partner ? $partner : '—' ) . '</td><td>' . esc_html( ServiceResources::timezone_of( $r ) ) . '</td><td>' . (int) $r->capacity . '</td><td>' . (int) $r->slot_count . '</td><td>' . esc_html( Forms::status_label( $r->status ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $events ) { self::render_resource_form( null, $events, $event_id ); }
	}

	private static function render_resource_form( $resource, array $events = array(), $event_id = 0 ) {
		echo '<h2>' . esc_html( $resource ? __( 'Ressource bearbeiten', 'pass-captain' ) : __( 'Neue Ressource', 'pass-captain' ) ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save_resource' );
		Forms::hidden( 'id', $resource ? (int) $resource->resource_id : 0 );
		echo '<table class="form-table"><tbody>';
		if ( ! $resource ) {
			FeatureScreens::row( 'wpcap-svc-event', __( 'Event', 'pass-captain' ), static function () use ( $events, $event_id ) { Forms::select( 'event_id', $events, $event_id, 'wpcap-svc-event', 'required' ); } );
		}
		FeatureScreens::input_row( 'wpcap-svc-name', 'name', __( 'Name', 'pass-captain' ), $resource ? $resource->name : '', 'text', 'class="regular-text" maxlength="190" required' );
		FeatureScreens::input_row( 'wpcap-svc-capacity', 'capacity', __( 'Standard-Kapazität je Zeitfenster', 'pass-captain' ), $resource ? (int) $resource->capacity : 1, 'number', 'min="1" max="' . (int) ServiceResources::MAX_CAPACITY . '" required' );
		$tz = $resource ? (string) $resource->timezone : '';
		FeatureScreens::row(
			'wpcap-svc-timezone',
			__( 'Zeitzone', 'pass-captain' ),
			static function () use ( $tz ) {
				$zones = timezone_identifiers_list();
				Forms::select( 'timezone', array( '' => __( 'Wie das Event', 'pass-captain' ) ) + array_combine( $zones, $zones ), $tz, 'wpcap-svc-timezone', 'aria-describedby="wpcap-svc-timezone-desc"' );
			},
			__( 'Zeitfenster werden in dieser Zeitzone eingegeben und angezeigt.', 'pass-captain' )
		);
		$status = $resource ? (string) $resource->status : 'active';
		FeatureScreens::row( 'wpcap-svc-status', __( 'Status', 'pass-captain' ), static function () use ( $status ) { Forms::select( 'status', array( 'active' => __( 'Aktiv (buchbar)', 'pass-captain' ), 'inactive' => __( 'Deaktiviert', 'pass-captain' ) ), $status, 'wpcap-svc-status' ); } );
		echo '</tbody></table>';
		submit_button( $resource ? __( 'Ressource speichern', 'pass-captain' ) : __( 'Ressource anlegen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function weekday_names() {
		return array( 1 => __( 'Montag', 'pass-captain' ), 2 => __( 'Dienstag', 'pass-captain' ), 3 => __( 'Mittwoch', 'pass-captain' ), 4 => __( 'Donnerstag', 'pass-captain' ), 5 => __( 'Freitag', 'pass-captain' ), 6 => __( 'Samstag', 'pass-captain' ), 7 => __( 'Sonntag', 'pass-captain' ) );
	}

	private static function render_resource( $resource_id ) {
		$resource = self::managed_resource( $resource_id );
		echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'Alle Ressourcen', 'pass-captain' ) . '</a></p>';
		if ( is_wp_error( $resource ) ) { echo '<p>' . esc_html( $resource->get_error_message() ) . '</p>'; return; }
		$tz = ServiceResources::timezone_of( $resource );
		$event = Queries::event( $resource->event_id );
		$partner = self::partner_name( (int) $resource->event_id );
		echo '<h2>' . esc_html( $resource->name ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: event, 2: timezone */ __( 'Event: %1$s · Zeitzone: %2$s', 'pass-captain' ), $event ? $event->name : '#' . (int) $resource->event_id, $tz ) ) . ( '' !== $partner ? ' · ' . esc_html( sprintf( /* translators: %s: organizer */ __( 'Veranstalter: %s', 'pass-captain' ), $partner ) ) : '' ) . '</p>';
		self::render_slots( $resource, $tz );

		echo '<h2>' . esc_html__( 'Einzelnes Zeitfenster anlegen', 'pass-captain' ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'create_slot' );
		Forms::hidden( 'resource_id', (int) $resource->resource_id );
		echo '<table class="form-table"><tbody>';
		/* translators: %s: timezone */
		FeatureScreens::input_row( 'wpcap-slot-start', 'starts_at', __( 'Beginn', 'pass-captain' ), '', 'datetime-local', 'required', sprintf( __( 'Ortszeit %s', 'pass-captain' ), $tz ) );
		FeatureScreens::input_row( 'wpcap-slot-end', 'ends_at', __( 'Ende', 'pass-captain' ), '', 'datetime-local', 'required' );
		FeatureScreens::input_row( 'wpcap-slot-capacity', 'capacity', __( 'Kapazität', 'pass-captain' ), (int) $resource->capacity, 'number', 'min="1" max="' . (int) ServiceResources::MAX_CAPACITY . '"' );
		echo '</tbody></table>';
		submit_button( __( 'Zeitfenster anlegen', 'pass-captain' ), 'secondary' );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Wöchentliche Zeitfenster erzeugen', 'pass-captain' ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'generate_slots' );
		Forms::hidden( 'resource_id', (int) $resource->resource_id );
		echo '<table class="form-table"><tbody>';
		FeatureScreens::input_row( 'wpcap-gen-start', 'start_date', __( 'Erster Tag', 'pass-captain' ), '', 'date', 'required' );
		FeatureScreens::input_row( 'wpcap-gen-until', 'until_date', __( 'Letzter Tag', 'pass-captain' ), '', 'date', 'required', __( 'Höchstens ein Jahr; bereits vorhandene Zeitfenster mit gleichem Beginn werden übersprungen.', 'pass-captain' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</legend>';
		foreach ( self::weekday_names() as $num => $name ) {
			echo '<label for="wpcap-gen-wd-' . (int) $num . '" style="margin-right:1em"><input type="checkbox" id="wpcap-gen-wd-' . (int) $num . '" name="weekdays[]" value="' . (int) $num . '"> ' . esc_html( $name ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		/* translators: %s: timezone */
		FeatureScreens::input_row( 'wpcap-gen-time', 'time', __( 'Uhrzeit', 'pass-captain' ), '', 'time', 'required', sprintf( __( 'Ortszeit %s', 'pass-captain' ), $tz ) );
		FeatureScreens::input_row( 'wpcap-gen-duration', 'duration', __( 'Dauer in Minuten', 'pass-captain' ), '60', 'number', 'min="5" max="1440" required' );
		FeatureScreens::input_row( 'wpcap-gen-capacity', 'capacity', __( 'Kapazität', 'pass-captain' ), (int) $resource->capacity, 'number', 'min="1" max="' . (int) ServiceResources::MAX_CAPACITY . '"' );
		echo '</tbody></table>';
		submit_button( __( 'Zeitfenster erzeugen', 'pass-captain' ), 'secondary' );
		echo '</form>';

		self::render_resource_form( $resource );
	}

	private static function render_slots( $resource, $tz ) {
		$slots = ServiceResources::slots( $resource->resource_id );
		echo '<h3>' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</h3>';
		if ( ! $slots ) { echo '<p>' . esc_html__( 'Noch keine Zeitfenster.', 'pass-captain' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Beginn', 'pass-captain' ), __( 'Ende', 'pass-captain' ), __( 'Gebucht / Kapazität', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $slots as $slot ) {
			echo '<tr><td>' . esc_html( Forms::datetime( $slot->starts_at, $tz, false ) ) . '</td><td>' . esc_html( Forms::datetime( $slot->ends_at, $tz, false ) ) . '</td><td>' . (int) $slot->booked . ' / ' . (int) $slot->capacity . '</td><td>' . esc_html( 'open' === $slot->status ? __( 'Offen', 'pass-captain' ) : __( 'Geschlossen', 'pass-captain' ) ) . '</td><td><a class="button button-small" href="' . esc_url( Forms::page_url( self::SLUG, array( 'slot_id' => (int) $slot->slot_id ) ) ) . '">' . esc_html__( 'Bearbeiten & Buchungen', 'pass-captain' ) . '</a> ';
			if ( ! (int) $slot->booked ) {
				FeatureScreens::button( self::ACTION, array( 'do' => 'delete_slot', 'id' => (int) $slot->slot_id ), __( 'Löschen', 'pass-captain' ), __( 'Dieses Zeitfenster löschen?', 'pass-captain' ), 'button button-small button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_slot( $slot_id ) {
		$slot = self::managed_slot( $slot_id );
		if ( is_wp_error( $slot ) ) {
			echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'Alle Ressourcen', 'pass-captain' ) . '</a></p><p>' . esc_html( $slot->get_error_message() ) . '</p>';
			return;
		}
		$resource = ServiceResources::resource( $slot->resource_id );
		$tz = ServiceResources::timezone_of( $resource );
		echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG, array( 'resource_id' => (int) $slot->resource_id ) ) ) . '">&larr; ' . esc_html( sprintf( /* translators: %s: resource */ __( 'Zurück zu %s', 'pass-captain' ), $slot->resource_name ) ) . '</a></p>';
		echo '<h2>' . esc_html( sprintf( /* translators: 1: resource, 2: start */ __( 'Zeitfenster %1$s, %2$s', 'pass-captain' ), $slot->resource_name, Forms::datetime( $slot->starts_at, $tz ) ) ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'update_slot' );
		Forms::hidden( 'id', (int) $slot->slot_id );
		echo '<table class="form-table"><tbody>';
		/* translators: %s: timezone */
		FeatureScreens::input_row( 'wpcap-slot-start', 'starts_at', __( 'Beginn', 'pass-captain' ), Forms::utc_to_local( $slot->starts_at, $tz ), 'datetime-local', 'required', sprintf( __( 'Ortszeit %s', 'pass-captain' ), $tz ) );
		FeatureScreens::input_row( 'wpcap-slot-end', 'ends_at', __( 'Ende', 'pass-captain' ), Forms::utc_to_local( $slot->ends_at, $tz ), 'datetime-local', 'required' );
		FeatureScreens::input_row( 'wpcap-slot-capacity', 'capacity', __( 'Kapazität', 'pass-captain' ), (int) $slot->capacity, 'number', 'min="1" max="' . (int) ServiceResources::MAX_CAPACITY . '" required' );
		$status = (string) $slot->status;
		FeatureScreens::row( 'wpcap-slot-status', __( 'Status', 'pass-captain' ), static function () use ( $status ) { Forms::select( 'status', array( 'open' => __( 'Offen (buchbar)', 'pass-captain' ), 'closed' => __( 'Geschlossen', 'pass-captain' ) ), $status, 'wpcap-slot-status' ); } );
		echo '</tbody></table>';
		submit_button( __( 'Zeitfenster speichern', 'pass-captain' ) );
		echo '</form>';

		$bookings = ServiceResources::bookings( $slot->slot_id );
		echo '<h3>' . esc_html__( 'Buchungen', 'pass-captain' ) . '</h3>';
		if ( ! $bookings ) { echo '<p>' . esc_html__( 'Keine Buchungen für dieses Zeitfenster.', 'pass-captain' ) . '</p>'; return; }
		$labels = array( 'held' => __( 'Reserviert', 'pass-captain' ), 'confirmed' => __( 'Bestätigt', 'pass-captain' ), 'expired' => __( 'Abgelaufen', 'pass-captain' ), 'cancelled' => __( 'Storniert', 'pass-captain' ) );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Buchung', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Anzahl', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Reserviert bis', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ), __( 'Angelegt', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $bookings as $b ) {
			echo '<tr><td>#' . (int) $b->booking_id . '</td><td>' . esc_html( $b->customer_email ) . '</td><td>' . (int) $b->quantity . '</td><td>' . esc_html( $labels[ $b->status ] ?? $b->status ) . '</td><td>' . esc_html( 'held' === $b->status && $b->expires_at ? Forms::datetime( $b->expires_at, $tz, false ) : '—' ) . '</td><td>' . ( (int) $b->order_id ? '#' . (int) $b->order_id : '—' ) . '</td><td>' . esc_html( Forms::datetime( $b->created_at, $tz, false ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
