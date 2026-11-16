<?php
/**
 * Admin screen "Ressourcen & Zeitfenster": bookable resources per event (name, capacity, timezone,
 * organizer, price, default duration, buffers and booking rules), single slots, a weekly slot
 * generator, the bookings of a slot with staff actions (confirm/decline, cancel with or without
 * refund, rebook, check-in, no-show) and the slot waitlist. Users linked to an organizer (partners
 * module) see and manage only that organizer's resources. Booking logic: ServiceBookings.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\ServiceWaitlist;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServicesPage {
	const SLUG = 'wpcap-services';
	const ACTION = 'wpcap_service_action';
	const CAP = 'wpcap_manage_events';

	/** E-mail as the current user may see it (masked for the support profile, DataPolicy). */
	private static function email( $email ) {
		return \PassCaptain\Security\DataPolicy::masks_pii() ? \PassCaptain\Security\DataPolicy::mask_email( (string) $email ) : (string) $email;
	}

	private static function can_event( $event_id ) {
		return $event_id && Access::can_access_event( $event_id, self::CAP );
	}

	/** Whether the current user may open the screen: capability, or linked to an organizer. */
	public static function can_use() {
		return current_user_can( self::CAP ) || (bool) ServiceResources::user_partner_ids();
	}

	/** Resource the current user may manage (event staff or its organizer), or a WP_Error. */
	private static function managed_resource( $resource_id ) {
		$resource = ServiceResources::resource( $resource_id );
		if ( ! $resource ) { return Forms::error( 'wpcap_resource_missing', __( 'Ressource nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! ServiceResources::can_manage( $resource, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Ressource.', 'pass-captain' ) ); }
		return $resource;
	}

	/** Slot the current user may manage, or a WP_Error. */
	private static function managed_slot( $slot_id ) {
		$slot = ServiceResources::slot( $slot_id );
		if ( ! $slot ) { return Forms::error( 'wpcap_service_slot_missing', __( 'Zeitfenster nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! ServiceResources::can_manage( ServiceResources::resource( (int) $slot->resource_id ), self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Zeitfenster.', 'pass-captain' ) ); }
		return $slot;
	}

	/** Booking the current user may manage, or a WP_Error. */
	private static function managed_booking( $booking_id ) {
		$booking = ServiceBookings::get( $booking_id );
		if ( ! $booking ) { return Forms::error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), 404 ); }
		$slot = self::managed_slot( (int) $booking->slot_id );
		return is_wp_error( $slot ) ? $slot : $booking;
	}

	/** Resource fields of the form (rules as nested array). */
	private static function resource_data( array $in ) {
		$rules = array();
		foreach ( array( 'cutoff_minutes', 'opens_days', 'max_per_customer', 'rebook_deadline_hours', 'cancel_deadline_hours', 'confirmation_hours', 'noshow_grace_minutes', 'noshow_block_after', 'noshow_block_days', 'waitlist_offer_minutes' ) as $key ) {
			if ( isset( $in[ 'rule_' . $key ] ) && '' !== (string) $in[ 'rule_' . $key ] ) { $rules[ $key ] = Forms::int( $in, 'rule_' . $key ); }
		}
		foreach ( array( 'rebook_deadline', 'cancel_deadline' ) as $deadline ) {
			if ( isset( $rules[ $deadline . '_hours' ] ) ) { $rules[ $deadline . '_minutes' ] = $rules[ $deadline . '_hours' ] * 60; unset( $rules[ $deadline . '_hours' ] ); }
		}
		foreach ( array( 'max_period' => ServiceResources::LIMIT_PERIODS, 'price_policy' => ServiceResources::PRICE_POLICIES, 'noshow_policy' => ServiceResources::NOSHOW_POLICIES ) as $key => $allowed ) {
			if ( isset( $in[ 'rule_' . $key ] ) ) { $rules[ $key ] = Forms::choice( $in, 'rule_' . $key, $allowed, ServiceResources::default_rules()[ $key ] ); }
		}
		if ( isset( $in['rule_noshow_fee'] ) ) { $rules['noshow_fee_minor'] = max( 0, (int) Forms::parse_money( (string) $in['rule_noshow_fee'] ) ); }
		if ( isset( $in['rules_present'] ) ) {
			$rules['requires_confirmation'] = Forms::flag( $in, 'rule_requires_confirmation' ) ? 1 : 0;
			$rules['waitlist_enabled'] = Forms::flag( $in, 'rule_waitlist_enabled' ) ? 1 : 0;
		}
		$data = array( 'name' => Forms::text( $in, 'name' ), 'capacity' => Forms::int( $in, 'capacity', 1 ), 'timezone' => Forms::text( $in, 'timezone', 64 ), 'status' => Forms::choice( $in, 'status', ServiceResources::RESOURCE_STATUSES, 'active' ), 'rules' => $rules );
		foreach ( array( 'default_duration', 'buffer_before', 'buffer_after', 'ticket_type_id' ) as $key ) {
			if ( isset( $in[ $key ] ) ) { $data[ $key ] = Forms::int( $in, $key ); }
		}
		return $data;
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
		if ( ! self::can_use() ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = Forms::int( $in, 'id' );
		switch ( $do ) {
			case 'save_resource':
				$data = self::resource_data( $in );
				if ( $id ) {
					$resource = self::managed_resource( $id );
					if ( is_wp_error( $resource ) ) { return $resource; }
					// Only event staff/admins assign organizers; organizer users keep theirs.
					if ( isset( $in['partner_id'] ) && self::can_event( (int) $resource->event_id ) ) { $data['partner_id'] = Forms::int( $in, 'partner_id' ); }
				} else {
					$data['event_id'] = Forms::int( $in, 'event_id' );
					if ( ! self::can_event( $data['event_id'] ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
					if ( isset( $in['partner_id'] ) && '' !== (string) $in['partner_id'] ) { $data['partner_id'] = Forms::int( $in, 'partner_id' ); }
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
			case 'booking_confirm':
			case 'booking_decline':
			case 'booking_cancel':
			case 'booking_rebook':
			case 'booking_check_in':
			case 'booking_no_show':
				$booking = self::managed_booking( $id );
				if ( is_wp_error( $booking ) ) { return $booking; }
				if ( 'booking_rebook' === $do ) {
					$target = self::managed_slot( Forms::int( $in, 'slot_id' ) );
					if ( is_wp_error( $target ) ) { return $target; }
				}
				$actor = self::can_event( (int) ServiceBookings::resource_of( $booking )->event_id ) ? 'admin' : 'partner';
				switch ( $do ) {
					case 'booking_confirm':
						$result = ServiceBookings::decide( $id, 'confirm' );
						break;
					case 'booking_decline':
						$result = ServiceBookings::decide( $id, 'decline', Forms::text( $in, 'reason' ) );
						break;
					case 'booking_cancel':
						$result = ServiceBookings::cancel( $id, array( 'actor' => $actor, 'refund' => ! Forms::flag( $in, 'no_refund' ), 'note' => Forms::text( $in, 'reason' ) ) );
						break;
					case 'booking_rebook':
						$result = ServiceBookings::rebook( $id, Forms::int( $in, 'slot_id' ), array( 'actor' => $actor, 'idempotency_key' => 'admin-' . get_current_user_id() . '-' . Forms::int( $in, 'slot_id' ) . '-' . (int) $booking->rebook_count ) );
						break;
					case 'booking_check_in':
						$result = ServiceBookings::check_in( $id );
						break;
					default:
						$result = ServiceBookings::mark_no_show( $id );
				}
				return is_wp_error( $result ) ? $result : $result + array( 'return_slot' => (int) $booking->slot_id );
			case 'waitlist_remove':
				$entry = ServiceWaitlist::get( $id );
				$slot = $entry ? self::managed_slot( (int) $entry->slot_id ) : Forms::error( 'wpcap_waitlist_missing', __( 'Wartelisteneintrag nicht gefunden.', 'pass-captain' ), 404 );
				if ( is_wp_error( $slot ) ) { return $slot; }
				$removed = ServiceWaitlist::remove( $id );
				return is_wp_error( $removed ) ? $removed : array( 'return_slot' => (int) $entry->slot_id );
			case 'waitlist_offer':
				$slot = self::managed_slot( $id );
				if ( is_wp_error( $slot ) ) { return $slot; }
				return array( 'offered' => ServiceWaitlist::offer_next( $id ), 'return_slot' => $id );
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
		} elseif ( is_array( $result ) && ! empty( $result['return_slot'] ) ) {
			$url = Forms::page_url( self::SLUG, array( 'slot_id' => (int) $result['return_slot'] ) );
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
		$error_url = 'update_slot' === $do ? Forms::page_url( self::SLUG, array( 'slot_id' => Forms::int( $in, 'id' ) ) ) : ( Forms::int( $in, 'return_slot' ) ? Forms::page_url( self::SLUG, array( 'slot_id' => Forms::int( $in, 'return_slot' ) ) ) : '' );
		Forms::finish( $result, $message, $url, $error_url );
	}

	/** Organizer name of an event when the partners module is active ('' otherwise). */
	public static function partner_name( $event_id, $partner_id = 0 ) {
		if ( ! ServiceResources::partners_enabled() ) { return ''; }
		$partner_id = $partner_id ? (int) $partner_id : \PassCaptain\Partners\Repository::partner_of_event( $event_id );
		$partner = $partner_id ? \PassCaptain\Partners\Repository::get( $partner_id ) : null;
		return $partner ? (string) $partner->name : '';
	}

	public static function render() {
		if ( ! self::can_use() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Ressourcen & Zeitfenster', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		if ( current_user_can( ServiceSlotsPage::CAP ) ) {
			echo '<p><a href="' . esc_url( Forms::page_url( ServiceSlotsPage::SLUG ) ) . '">' . esc_html__( 'Leistungserbringer und Zeitfenster je Ressource zuordnen (Service-Zeitfenster)', 'pass-captain' ) . '</a></p>';
		}
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
		$resources = ServiceResources::resources( $scope, $event_id, ServiceResources::user_partner_ids() );
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
				$partner = self::partner_name( (int) $r->event_id, (int) $r->partner_id );
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
		FeatureScreens::input_row( 'wpcap-svc-duration', 'default_duration', __( 'Standarddauer (Minuten)', 'pass-captain' ), $resource ? (int) $resource->default_duration : 60, 'number', 'min="5" max="1440"', __( 'Vorbelegung für den Wochengenerator.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-svc-buffer-before', 'buffer_before', __( 'Puffer vorher (Minuten)', 'pass-captain' ), $resource ? (int) $resource->buffer_before : 0, 'number', 'min="0" max="' . (int) ServiceResources::MAX_BUFFER . '"' );
		FeatureScreens::input_row( 'wpcap-svc-buffer-after', 'buffer_after', __( 'Puffer nachher (Minuten)', 'pass-captain' ), $resource ? (int) $resource->buffer_after : 0, 'number', 'min="0" max="' . (int) ServiceResources::MAX_BUFFER . '"', __( 'Zeitfenster derselben Ressource dürfen sich inklusive Puffer nicht überschneiden (z. B. Bootsübergabe, Reinigung).', 'pass-captain' ) );
		$event_for_types = $resource ? (int) $resource->event_id : (int) $event_id;
		$types = array( '0' => __( 'Kostenlos (ohne Bestellung)', 'pass-captain' ) );
		foreach ( ( $event_for_types ? Queries::occurrences_for_event( $event_for_types ) : array() ) as $occ ) {
			foreach ( Queries::ticket_types_for_occurrence( (int) $occ->occurrence_id ) as $tt ) { $types[ (string) (int) $tt->ticket_type_id ] = $tt->name . ' – ' . Forms::money( (int) $tt->price_minor, (string) $tt->currency ); }
		}
		$type_id = $resource ? (string) (int) $resource->ticket_type_id : '0';
		FeatureScreens::row( 'wpcap-svc-type', __( 'Preis (Ticketart)', 'pass-captain' ), static function () use ( $types, $type_id ) { Forms::select( 'ticket_type_id', $types, $type_id, 'wpcap-svc-type', 'aria-describedby="wpcap-svc-type-desc"' ); }, __( 'Bezahlte Buchungen laufen über eine Bestellung dieser Ticketart (Zahlung, Erstattung, Gutschein).', 'pass-captain' ) );
		if ( ServiceResources::partners_enabled() && ( ! $resource || self::can_event( (int) $resource->event_id ) ) ) {
			$partners = array( '0' => __( '— kein Veranstalter —', 'pass-captain' ) );
			foreach ( \PassCaptain\Partners\Repository::all() as $partner ) { $partners[ (string) (int) $partner->partner_id ] = (string) $partner->name; }
			$partner_id = $resource ? (string) (int) $resource->partner_id : '';
			if ( ! $resource ) { $partners = array( '' => __( 'Wie das Event', 'pass-captain' ) ) + $partners; }
			FeatureScreens::row( 'wpcap-svc-partner', __( 'Veranstalter / Anbieter', 'pass-captain' ), static function () use ( $partners, $partner_id ) { Forms::select( 'partner_id', $partners, $partner_id, 'wpcap-svc-partner', 'aria-describedby="wpcap-svc-partner-desc"' ); }, __( 'Verknüpfte Benutzer:innen des Veranstalters sehen und verwalten nur ihre Ressourcen.', 'pass-captain' ) );
		}
		echo '</tbody></table>';
		self::render_rules_fields( $resource ? ServiceResources::rules( $resource ) : ServiceResources::default_rules() );
		submit_button( $resource ? __( 'Ressource speichern', 'pass-captain' ) : __( 'Ressource anlegen', 'pass-captain' ) );
		echo '</form>';
	}

	/** Booking rule fields (Buchungsregeln) of the resource form. */
	private static function render_rules_fields( array $rules ) {
		echo '<h3>' . esc_html__( 'Buchungsregeln', 'pass-captain' ) . '</h3><table class="form-table"><tbody>';
		Forms::hidden( 'rules_present', 1 );
		FeatureScreens::input_row( 'wpcap-rule-cutoff', 'rule_cutoff_minutes', __( 'Buchungsschluss (Minuten vor Beginn)', 'pass-captain' ), (int) $rules['cutoff_minutes'], 'number', 'min="0"' );
		FeatureScreens::input_row( 'wpcap-rule-opens', 'rule_opens_days', __( 'Vorlauf: buchbar ab (Tage vor Beginn)', 'pass-captain' ), (int) $rules['opens_days'], 'number', 'min="0" max="3650"', __( '0 = unbegrenzt.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-rule-max', 'rule_max_per_customer', __( 'Höchstens Plätze pro Person', 'pass-captain' ), (int) $rules['max_per_customer'], 'number', 'min="0" max="1000"', __( '0 = unbegrenzt.', 'pass-captain' ) );
		$period = $rules['max_period'];
		FeatureScreens::row( 'wpcap-rule-period', __( 'Zeitraum der Höchstgrenze', 'pass-captain' ), static function () use ( $period ) { Forms::select( 'rule_max_period', array( 'day' => __( 'pro Tag', 'pass-captain' ), 'week' => __( 'pro Woche', 'pass-captain' ), 'month' => __( 'pro Monat', 'pass-captain' ), 'total' => __( 'alle künftigen Termine', 'pass-captain' ) ), $period, 'wpcap-rule-period' ); } );
		FeatureScreens::input_row( 'wpcap-rule-rebook', 'rule_rebook_deadline_hours', __( 'Umbuchungsfrist (Stunden vor Beginn)', 'pass-captain' ), intdiv( (int) $rules['rebook_deadline_minutes'], 60 ), 'number', 'min="0"' );
		FeatureScreens::input_row( 'wpcap-rule-cancel', 'rule_cancel_deadline_hours', __( 'Stornierungsfrist (Stunden vor Beginn)', 'pass-captain' ), intdiv( (int) $rules['cancel_deadline_minutes'], 60 ), 'number', 'min="0"', __( 'Bis dahin können Kund:innen selbst stornieren und erhalten eine Erstattung.', 'pass-captain' ) );
		$policy = $rules['price_policy'];
		FeatureScreens::row( 'wpcap-rule-price', __( 'Umbuchung bei Preisunterschied', 'pass-captain' ), static function () use ( $policy ) { Forms::select( 'rule_price_policy', array( 'same_price' => __( 'Nur auf Zeitfenster zum gleichen Preis', 'pass-captain' ), 'refund_difference' => __( 'Günstigere Ziele erlauben, Differenz erstatten', 'pass-captain' ) ), $policy, 'wpcap-rule-price' ); } );
		$confirm = (int) $rules['requires_confirmation'];
		FeatureScreens::row( 'wpcap-rule-confirm', __( 'Manuelle Bestätigung', 'pass-captain' ), static function () use ( $confirm ) { echo '<label for="wpcap-rule-confirm"><input type="checkbox" id="wpcap-rule-confirm" name="rule_requires_confirmation" value="1"' . ( $confirm ? ' checked' : '' ) . '> ' . esc_html__( 'Bezahlte Buchungen müssen vom Anbieter bestätigt werden (Ablehnung = automatische Erstattung)', 'pass-captain' ) . '</label>'; } );
		FeatureScreens::input_row( 'wpcap-rule-confirm-hours', 'rule_confirmation_hours', __( 'Bestätigungsfrist (Stunden)', 'pass-captain' ), (int) $rules['confirmation_hours'], 'number', 'min="1" max="720"' );
		$noshow = $rules['noshow_policy'];
		FeatureScreens::row( 'wpcap-rule-noshow', __( 'No-Show-Regel', 'pass-captain' ), static function () use ( $noshow ) { Forms::select( 'rule_noshow_policy', array( 'none' => __( 'Keine', 'pass-captain' ), 'mark' => __( 'Ohne Check-in nach Ende als „nicht erschienen“ markieren', 'pass-captain' ), 'forfeit' => __( 'Markieren, Anspruch verfällt, Gebühr vermerken', 'pass-captain' ) ), $noshow, 'wpcap-rule-noshow' ); } );
		FeatureScreens::input_row( 'wpcap-rule-grace', 'rule_noshow_grace_minutes', __( 'Karenz nach Ende (Minuten)', 'pass-captain' ), (int) $rules['noshow_grace_minutes'], 'number', 'min="0"' );
		FeatureScreens::input_row( 'wpcap-rule-fee', 'rule_noshow_fee', __( 'No-Show-Gebühr', 'pass-captain' ), Forms::money_input( (int) $rules['noshow_fee_minor'] ), 'text', 'inputmode="decimal"', __( 'Wird an der Buchung vermerkt und in der Mail genannt; der Einzug erfolgt außerhalb von Pass Captain.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-rule-block', 'rule_noshow_block_after', __( 'Sperre nach No-Shows', 'pass-captain' ), (int) $rules['noshow_block_after'], 'number', 'min="0" max="100"', __( '0 = nie; sonst keine neuen Buchungen nach so vielen No-Shows im Rückblickzeitraum.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-rule-block-days', 'rule_noshow_block_days', __( 'Rückblickzeitraum (Tage)', 'pass-captain' ), (int) $rules['noshow_block_days'], 'number', 'min="1" max="3650"' );
		$waitlist = (int) $rules['waitlist_enabled'];
		FeatureScreens::row( 'wpcap-rule-waitlist', __( 'Warteliste', 'pass-captain' ), static function () use ( $waitlist ) { echo '<label for="wpcap-rule-waitlist"><input type="checkbox" id="wpcap-rule-waitlist" name="rule_waitlist_enabled" value="1"' . ( $waitlist ? ' checked' : '' ) . '> ' . esc_html__( 'Warteliste für ausgebuchte Zeitfenster anbieten', 'pass-captain' ) . '</label>'; } );
		FeatureScreens::input_row( 'wpcap-rule-offer', 'rule_waitlist_offer_minutes', __( 'Annahmefrist eines Angebots (Minuten)', 'pass-captain' ), (int) $rules['waitlist_offer_minutes'], 'number', 'min="5" max="10080"' );
		echo '</tbody></table>';
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
		$partner = self::partner_name( (int) $resource->event_id, (int) $resource->partner_id );
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
		FeatureScreens::input_row( 'wpcap-gen-duration', 'duration', __( 'Dauer in Minuten', 'pass-captain' ), (string) (int) $resource->default_duration, 'number', 'min="5" max="1440" required' );
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
			echo '<tr><td>' . esc_html( Forms::datetime( $slot->starts_at, $tz, false ) ) . '</td><td>' . esc_html( Forms::datetime( $slot->ends_at, $tz, false ) ) . '</td><td>' . (int) $slot->booked . ' / ' . (int) $slot->capacity . '</td><td>' . esc_html( 'open' === $slot->status ? _x( 'Offen', 'status of a service slot: bookable', 'pass-captain' ) : __( 'Geschlossen', 'pass-captain' ) ) . '</td><td><a class="button button-small" href="' . esc_url( Forms::page_url( self::SLUG, array( 'slot_id' => (int) $slot->slot_id ) ) ) . '">' . esc_html__( 'Bearbeiten & Buchungen', 'pass-captain' ) . '</a> ';
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
		if ( ! $bookings ) {
			echo '<p>' . esc_html__( 'Keine Buchungen für dieses Zeitfenster.', 'pass-captain' ) . '</p>';
		} else {
			$targets = array();
			foreach ( ServiceResources::slots( $slot->resource_id ) as $other ) {
				if ( (int) $other->slot_id !== (int) $slot->slot_id && 'open' === $other->status && $other->starts_at > current_time( 'mysql', true ) ) { $targets[ (string) (int) $other->slot_id ] = Forms::datetime( $other->starts_at, $tz, false ) . ' (' . ( (int) $other->capacity - (int) $other->booked ) . ')'; }
			}
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Buchung', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Anzahl', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Betrag', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ), __( 'Angelegt', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $bookings as $b ) {
				$state = \PassCaptain\Services\Portal::status_label( $b->status );
				if ( 'held' === $b->status && $b->expires_at ) { $state .= ' · ' . sprintf( /* translators: %s: time */ __( 'bis %s', 'pass-captain' ), Forms::datetime( $b->expires_at, $tz, false ) ); }
				if ( 'pending_confirmation' === $b->status && $b->confirm_by ) { $state .= ' · ' . sprintf( /* translators: %s: time */ __( 'Entscheidung bis %s', 'pass-captain' ), Forms::datetime( $b->confirm_by, $tz, false ) ); }
				if ( '' !== (string) $b->cancel_reason ) { $state .= ' (' . ServiceBookings::reason_label( $b->cancel_reason ) . ')'; }
				if ( (int) $b->fee_minor ) { $state .= ' · ' . sprintf( /* translators: %s: amount */ __( 'Gebühr %s', 'pass-captain' ), Forms::money( (int) $b->fee_minor, (string) $b->currency ) ); }
				if ( (int) $b->rebook_count ) { $state .= ' · ' . sprintf( /* translators: %d: count */ _n( '%d× umgebucht', '%d× umgebucht', (int) $b->rebook_count, 'pass-captain' ), (int) $b->rebook_count ); }
				echo '<tr><td>#' . (int) $b->booking_id . '</td><td>' . esc_html( self::email( $b->customer_email ) ) . '</td><td>' . (int) $b->quantity . '</td><td>' . esc_html( $state ) . '</td><td>' . esc_html( (int) $b->amount_minor ? Forms::money( (int) $b->amount_minor, (string) $b->currency ) : '—' ) . '</td><td>' . ( (int) $b->order_id ? '#' . (int) $b->order_id : '—' ) . '</td><td>' . esc_html( Forms::datetime( $b->created_at, $tz, false ) ) . '</td><td>';
				$base = array( 'id' => (int) $b->booking_id, 'return_slot' => (int) $slot->slot_id );
				if ( 'pending_confirmation' === $b->status ) {
					FeatureScreens::button( self::ACTION, $base + array( 'do' => 'booking_confirm' ), __( 'Bestätigen', 'pass-captain' ), '', 'button button-small button-primary' );
					FeatureScreens::button( self::ACTION, $base + array( 'do' => 'booking_decline' ), __( 'Ablehnen & erstatten', 'pass-captain' ), __( 'Buchung ablehnen? Der Betrag wird automatisch erstattet.', 'pass-captain' ) );
				}
				if ( 'confirmed' === $b->status ) {
					FeatureScreens::button( self::ACTION, $base + array( 'do' => 'booking_check_in' ), __( 'Check-in', 'pass-captain' ) );
					FeatureScreens::button( self::ACTION, $base + array( 'do' => 'booking_no_show' ), __( 'Nicht erschienen', 'pass-captain' ), __( 'Als „nicht erschienen“ markieren?', 'pass-captain' ) );
				}
				if ( in_array( $b->status, ServiceBookings::REBOOKABLE, true ) && $targets ) {
					Forms::open_form( self::ACTION, null, 'style="display:inline-block"' );
					Forms::hidden( 'do', 'booking_rebook' );
					foreach ( $base as $k => $v ) { Forms::hidden( $k, $v ); }
					echo '<label class="screen-reader-text" for="wpcap-rebook-' . (int) $b->booking_id . '">' . esc_html__( 'Neues Zeitfenster', 'pass-captain' ) . '</label>';
					Forms::select( 'slot_id', $targets, '', 'wpcap-rebook-' . (int) $b->booking_id );
					echo ' <button type="submit" class="button button-small">' . esc_html__( 'Umbuchen', 'pass-captain' ) . '</button></form> ';
				}
				if ( in_array( $b->status, ServiceBookings::CANCELLABLE, true ) ) {
					FeatureScreens::button( self::ACTION, $base + array( 'do' => 'booking_cancel' ), __( 'Stornieren & erstatten', 'pass-captain' ), __( 'Buchung stornieren? Bezahlte Buchungen werden erstattet.', 'pass-captain' ), 'button button-small button-link-delete' );
					FeatureScreens::button( self::ACTION, $base + array( 'do' => 'booking_cancel', 'no_refund' => 1 ), __( 'Ohne Erstattung stornieren', 'pass-captain' ), __( 'Buchung ohne Erstattung stornieren?', 'pass-captain' ), 'button button-small button-link-delete' );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		self::render_waitlist( $slot, $tz );
	}

	/** Waitlist of a slot (queue order) with remove and manual offer. */
	private static function render_waitlist( $slot, $tz ) {
		$entries = ServiceWaitlist::entries( $slot->slot_id );
		echo '<h3>' . esc_html__( 'Warteliste', 'pass-captain' ) . '</h3>';
		if ( ! $entries ) { echo '<p>' . esc_html__( 'Niemand auf der Warteliste.', 'pass-captain' ) . '</p>'; return; }
		$labels = array( 'unconfirmed' => __( 'Unbestätigt', 'pass-captain' ), 'waiting' => __( 'Wartend', 'pass-captain' ), 'offered' => __( 'Angebot offen', 'pass-captain' ), 'accepted' => __( 'Angenommen', 'pass-captain' ), 'declined' => __( 'Abgelehnt', 'pass-captain' ), 'expired' => __( 'Abgelaufen', 'pass-captain' ), 'left' => __( 'Ausgetragen', 'pass-captain' ) );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Position', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Plätze', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Angebot gültig bis', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $e ) {
			echo '<tr><td>' . (int) $e->position . '</td><td>' . esc_html( self::email( $e->email ) ) . '</td><td>' . (int) $e->quantity . '</td><td>' . esc_html( $labels[ $e->status ] ?? $e->status ) . '</td><td>' . esc_html( 'offered' === $e->status && $e->offer_expires_at ? Forms::datetime( $e->offer_expires_at, $tz, false ) : '—' ) . '</td><td>';
			FeatureScreens::button( self::ACTION, array( 'do' => 'waitlist_remove', 'id' => (int) $e->entry_id, 'return_slot' => (int) $slot->slot_id ), __( 'Entfernen', 'pass-captain' ), __( 'Diesen Eintrag von der Warteliste entfernen?', 'pass-captain' ), 'button button-small button-link-delete' );
			echo '</td></tr>';
		}
		echo '</tbody></table><p>';
		FeatureScreens::button( self::ACTION, array( 'do' => 'waitlist_offer', 'id' => (int) $slot->slot_id ), __( 'Freie Plätze jetzt anbieten', 'pass-captain' ), '', 'button' );
		echo '</p>';
	}
}
