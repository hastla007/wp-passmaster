<?php
/**
 * Admin page "Meine Leistungen" (A143) for service providers (capability wpcap_service_bookings,
 * role "Pass Captain Leistungserbringer"): the slots and bookings of the resources their provider
 * record (linked through its WordPress user) is assigned to, with "Bestätigen" and "Nicht
 * erschienen" per booking. Nothing else of the event is shown.
 * Every action re-checks the assignment on the server (ServiceBookings::set_booking_status()).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Pii;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceProviders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MyServicesPage {
	const SLUG = 'wpcap-my-services';
	const CAP = ServiceProviders::USER_CAP;
	/** admin-post action shared with the "Service-Zeitfenster" page. */
	const STATUS_ACTION = 'wpcap_service_booking_status';

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Confirms a booking or marks it as no-show ($in: booking_id, status). */
	public static function set_status( array $in ) {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'wpcap_manage_events' ) ) { return Forms::forbidden(); }
		return ServiceBookings::set_booking_status( Forms::int( $in, 'booking_id' ), Forms::choice( $in, 'status', ServiceBookings::STAFF_STATUSES, '' ) );
	}

	public static function handle_status() {
		$in = Forms::post();
		Forms::verify( self::STATUS_ACTION . '_' . Forms::int( $in, 'booking_id' ) );
		$return = self::url();
		$message = 'no_show' === Forms::text( $in, 'status', 20 ) ? __( 'Buchung als nicht erschienen markiert.', 'pass-captain' ) : __( 'Buchung bestätigt.', 'pass-captain' );
		Forms::finish( self::set_status( $in ), $message, $return );
	}

	/** Status label of a booking (incl. no_show). */
	public static function status_label( $status ) {
		$labels = array(
			'held' => __( 'Reserviert', 'pass-captain' ),
			'confirmed' => __( 'Bestätigt', 'pass-captain' ),
			'expired' => __( 'Abgelaufen', 'pass-captain' ),
			'no_show' => __( 'Nicht erschienen', 'pass-captain' ),
			'cancelled' => __( 'Storniert', 'pass-captain' ),
			'pending_payment' => __( 'Zahlung offen', 'pass-captain' ),
			'unallocated' => __( 'Bezahlt, Termin vergeben (Erstattung)', 'pass-captain' ),
			'open' => __( 'Buchbar', 'pass-captain' ),
			'closed' => __( 'Geschlossen', 'pass-captain' ),
		);
		return $labels[ $status ] ?? Forms::status_label( $status );
	}

	/**
	 * Buttons "Bestätigen" (active hold of a free resource) and "Nicht erschienen" (confirmed booking
	 * whose slot has started) – only the transitions ServiceBookings::set_booking_status() allows.
	 *
	 * @param object $booking Booking row (booking_id, status, expires_at, starts_at, ticket_type_id).
	 */
	public static function status_buttons( $booking ) {
		$status = ServiceBookings::booking_status( $booking );
		$actions = array();
		if ( 'held' === $status && ! (int) ( $booking->ticket_type_id ?? 0 ) ) { $actions['confirmed'] = __( 'Bestätigen', 'pass-captain' ); }
		if ( 'confirmed' === $status && (string) $booking->starts_at <= current_time( 'mysql', true ) ) { $actions['no_show'] = __( 'Nicht erschienen', 'pass-captain' ); }
		foreach ( $actions as $target => $label ) {
			Forms::open_form( self::STATUS_ACTION, self::STATUS_ACTION . '_' . (int) $booking->booking_id, 'style="display:inline"' );
			Forms::hidden( 'booking_id', (int) $booking->booking_id );
			Forms::hidden( 'status', $target );
			echo '<button class="button button-small">' . esc_html( $label ) . '</button></form> ';
		}
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$resources = ServiceBookings::provider_resources();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Meine Leistungen', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Hier siehst du die Zeitfenster und Buchungen der Leistungen, die dir zugewiesen sind. Bestätige Buchungen oder markiere Gäste ab Beginn des Zeitfensters als nicht erschienen.', 'pass-captain' ) . '</p>';
		if ( ! $resources ) {
			echo '<p>' . esc_html__( 'Dir ist noch keine Leistung zugewiesen. Die Veranstaltungsleitung verknüpft dich unter „Service-Zeitfenster“ als Leistungserbringer mit Ressourcen.', 'pass-captain' ) . '</p></div>';
			return;
		}
		$ids = array_map( static function ( $r ) { return (int) $r->resource_id; }, $resources );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$slots = ServiceBookings::slots_of_resources( $ids, $since );
		$slot_filter = absint( Forms::query_arg( 'slot' ) );
		$bookings = ServiceBookings::bookings_of_resources( $ids, $slot_filter, $since );
		$zones = array();
		$zone = static function ( $event_id ) use ( &$zones ) {
			if ( ! isset( $zones[ $event_id ] ) ) { $zones[ $event_id ] = ServiceSlotsPage::timezone( $event_id ); }
			return $zones[ $event_id ];
		};

		echo '<h2>' . esc_html__( 'Zugewiesene Leistungen', 'pass-captain' ) . '</h2><ul>';
		foreach ( $resources as $resource ) {
			echo '<li>' . esc_html( $resource->name . ' – ' . $resource->event_name ) . ( 'active' !== $resource->status ? ' (' . esc_html__( 'deaktiviert', 'pass-captain' ) . ')' : '' ) . '</li>';
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Leistung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Beginn', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ende', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gebucht / Plätze', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $slots as $slot ) {
			$tz = $zone( (int) $slot->event_id );
			echo '<tr><td><a href="' . esc_url( self::url( array( 'slot' => (int) $slot->slot_id ) ) . '#wpcap-my-bookings' ) . '">' . esc_html( $slot->resource_name ) . '</a></td><td>' . esc_html( Forms::datetime( $slot->starts_at, $tz ) ) . '</td><td>' . esc_html( Forms::datetime( $slot->ends_at, $tz ) ) . '</td><td>' . esc_html( (int) $slot->booked . ' / ' . (int) $slot->capacity ) . '</td><td>' . esc_html( self::status_label( (string) $slot->status ) ) . '</td></tr>';
		}
		if ( ! $slots ) { echo '<tr><td colspan="5">' . esc_html__( 'Keine anstehenden Zeitfenster.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';

		if ( $bookings ) { \PassCaptain\Privacy\AccessLog::list_view( 'service_bookings_provider', array( 'slot' => $slot_filter ), count( $bookings ), 'user', get_current_user_id() ); }
		echo '<h2 id="wpcap-my-bookings">' . esc_html__( 'Buchungen', 'pass-captain' ) . '</h2>';
		if ( $slot_filter ) { echo '<p><a href="' . esc_url( self::url() . '#wpcap-my-bookings' ) . '">' . esc_html__( 'Alle Buchungen anzeigen', 'pass-captain' ) . '</a></p>'; }
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Buchung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Leistung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Plätze', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $bookings as $booking ) {
			$tz = $zone( (int) $booking->event_id );
			echo '<tr><td>#' . esc_html( (string) (int) $booking->booking_id ) . '</td><td>' . esc_html( $booking->resource_name ) . '</td><td>' . esc_html( Forms::datetime( $booking->starts_at, $tz, false ) . ' – ' . Forms::utc_to_local( $booking->ends_at, $tz, 'H:i' ) ) . '</td><td>' . esc_html( Pii::show( 'buyer_email', (string) $booking->customer_email ) ) . '</td><td>' . esc_html( (string) (int) $booking->quantity ) . '</td><td>' . esc_html( self::status_label( ServiceBookings::booking_status( $booking ) ) ) . '</td><td>';
			self::status_buttons( $booking );
			echo '</td></tr>';
		}
		if ( ! $bookings ) { echo '<tr><td colspan="7">' . esc_html__( 'Keine Buchungen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
