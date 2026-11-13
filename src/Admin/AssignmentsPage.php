<?php
/**
 * Admin page "Zuweisungen" (A144): narrower staff assignments of an event below the event team –
 * per occurrence (Termin) and capability, per entry area (admission module) and per service
 * (Leistung: the A133 provider records assigned to each resource; managed on the page
 * "Service-Zeitfenster"). The event team itself stays on the event page ("Personal und Zugriff").
 * Users need wpcap_manage_events for the event; occurrence assignments additionally need it for the
 * occurrence. Logic and audit: StaffAssignments, Admission\RulesPage, ServiceProviders.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Privacy;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceProviders;
use PassCaptain\StaffAssignments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AssignmentsPage {
	const SLUG = 'wpcap-assignments';
	const CAP = 'wpcap_manage_events';

	public static function url( $event_id = 0 ) {
		return Forms::page_url( self::SLUG, $event_id ? array( 'event_id' => (int) $event_id ) : array() );
	}

	/** Whether entry areas can be assigned (admission module on and its tables present). */
	public static function areas_available() {
		return \PassCaptain\Modules::enabled( \PassCaptain\Modules\Admission::class ) && Privacy::has_table( 'wpcap_admission_area_staff' );
	}

	/**
	 * Adds an occurrence assignment ($in: scope, object_id, user, capability).
	 *
	 * @return int|\WP_Error
	 */
	public static function add( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$scope = Forms::choice( $in, 'scope', array_keys( StaffAssignments::SCOPES ), '' );
		if ( '' === $scope ) { return Forms::error( 'wpcap_assignment_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) ); }
		return StaffAssignments::add( $scope, Forms::int( $in, 'object_id' ), (string) ( $in['user'] ?? '' ), Forms::text( $in, 'capability', 64 ) );
	}

	/** Removes an occurrence assignment ($in: assignment_id). */
	public static function remove( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		return StaffAssignments::remove( Forms::int( $in, 'assignment_id' ) );
	}

	/** Assigns a user to an entry area ($in: area_id, user); requires the admission module. */
	public static function add_area( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		if ( ! self::areas_available() ) { return Forms::error( 'wpcap_assignment_invalid', __( 'Einlassbereiche sind nicht verfügbar.', 'pass-captain' ) ); }
		return \PassCaptain\Admission\RulesPage::add_staff( $in );
	}

	/** Removes a user from an entry area ($in: area_id, user_id). */
	public static function remove_area( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		if ( ! self::areas_available() ) { return Forms::error( 'wpcap_assignment_invalid', __( 'Einlassbereiche sind nicht verfügbar.', 'pass-captain' ) ); }
		return \PassCaptain\Admission\RulesPage::remove_staff( $in );
	}

	public static function handle_add() {
		$in = Forms::post();
		Forms::verify( 'wpcap_assignment_add_' . Forms::int( $in, 'event_id' ) );
		Forms::finish( self::add( $in ), __( 'Zuweisung gespeichert.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_remove() {
		$in = Forms::post();
		Forms::verify( 'wpcap_assignment_remove_' . Forms::int( $in, 'assignment_id' ) );
		Forms::finish( self::remove( $in ), __( 'Zuweisung entfernt.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_add_area() {
		$in = Forms::post();
		Forms::verify( 'wpcap_assignment_area_add_' . Forms::int( $in, 'area_id' ) );
		Forms::finish( self::add_area( $in ), __( 'Zuweisung gespeichert.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	public static function handle_remove_area() {
		$in = Forms::post();
		Forms::verify( 'wpcap_assignment_area_remove_' . Forms::int( $in, 'area_id' ) . '_' . Forms::int( $in, 'user_id' ) );
		Forms::finish( self::remove_area( $in ), __( 'Zuweisung entfernt.', 'pass-captain' ), self::url( Forms::int( $in, 'event_id' ) ) );
	}

	/** Remove button of an occurrence assignment. */
	private static function remove_button( $assignment, $event_id ) {
		Forms::open_form( 'wpcap_assignment_remove', 'wpcap_assignment_remove_' . (int) $assignment->assignment_id, 'style="display:inline"' );
		Forms::hidden( 'assignment_id', (int) $assignment->assignment_id );
		Forms::hidden( 'event_id', (int) $event_id );
		echo '<button class="button-link button-link-delete">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form>';
	}

	/** Label of an occurrence: local start and location. */
	private static function occurrence_label( $occurrence ) {
		$zone = Forms::is_valid_timezone( (string) $occurrence->timezone ) ? (string) $occurrence->timezone : 'UTC';
		return Forms::datetime( $occurrence->starts_at, $zone, false ) . ( '' !== trim( (string) $occurrence->location ) ? ' – ' . $occurrence->location : '' ) . ( 'cancelled' === $occurrence->status ? ' (' . __( 'abgesagt', 'pass-captain' ) . ')' : '' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$event_id = absint( Forms::query_arg( 'event_id' ) );
		$events = Queries::event_options( Queries::event_ids_for( array( self::CAP ) ), array( $event_id ) );
		$events_note = Queries::event_options_note();
		if ( ! $event_id && 1 === count( $events ) ) { $event_id = (int) array_key_first( $events ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Zuweisungen', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Das Event-Team (Event-Seite, „Personal und Zugriff“) bestimmt, wer an einem Event mitarbeitet. Hier schränkst du Personen enger ein: auf einzelne Termine je Recht, auf Einlassbereiche und – für Leistungserbringer – auf einzelne Leistungen. Ohne engere Zuweisung gilt das ganze Event.', 'pass-captain' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-assignments-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( 0 => __( '– Event wählen –', 'pass-captain' ) ) + $events, (string) $event_id, 'wpcap-assignments-event' );
		echo ' <button class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button></form>' . $events_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note.
		if ( ! $event_id ) {
			echo $events ? '' : '<p>' . esc_html__( 'Es gibt noch kein Event, das du verwalten darfst.', 'pass-captain' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			echo '</div>';
			return;
		}
		if ( ! isset( $events[ $event_id ] ) || ! Access::can_access_event( $event_id, self::CAP ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) . '</p></div></div>';
			return;
		}
		$cap_labels = EventsPage::staff_cap_labels();
		$team = Queries::staff_for_event( $event_id );
		$members = array();
		foreach ( $team as $row ) { $members[ (int) $row->user_id ][] = (string) $row->capability; }
		$occurrences = array();
		foreach ( Queries::occurrences_for_event( $event_id ) as $occurrence ) {
			if ( Access::can_access_occurrence( (int) $occurrence->occurrence_id, self::CAP ) ) { $occurrences[ (int) $occurrence->occurrence_id ] = $occurrence; }
		}
		$assignments = array( 'occurrence' => array() );
		foreach ( StaffAssignments::for_event( $event_id ) as $row ) { $assignments[ $row->scope ][] = $row; }

		// Event team overview (read-only) with the narrowing per capability.
		echo '<h2>' . esc_html__( 'Event-Team', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Person', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Recht', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Termine', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $team as $row ) {
			$limited = array();
			foreach ( $assignments['occurrence'] as $a ) {
				if ( (int) $a->user_id === (int) $row->user_id && $a->capability === $row->capability ) { $limited[] = isset( $occurrences[ (int) $a->object_id ] ) ? self::occurrence_label( $occurrences[ (int) $a->object_id ] ) : '#' . (int) $a->object_id; }
			}
			echo '<tr><td>' . esc_html( Forms::user_label( (int) $row->user_id ) ) . '</td><td>' . esc_html( $cap_labels[ $row->capability ] ?? $row->capability ) . '</td><td>' . esc_html( $limited ? implode( '; ', $limited ) : __( 'alle Termine', 'pass-captain' ) ) . '</td></tr>';
		}
		if ( ! $team ) { echo '<tr><td colspan="3">' . esc_html__( 'Noch kein Personal zugewiesen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( EventsPage::event_url( $event_id ) ) . '">' . esc_html__( 'Event-Team bearbeiten', 'pass-captain' ) . '</a></p>';

		// Occurrence assignments.
		echo '<h2>' . esc_html__( 'Termine', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Eine Person mit mindestens einer Terminzuweisung für ein Recht erreicht mit diesem Recht nur noch die zugewiesenen Termine (Scannen, Bestellungen, Listen, Exporte, Berichte).', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Person', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Recht', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Termin', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $assignments['occurrence'] as $a ) {
			echo '<tr><td>' . esc_html( Forms::user_label( (int) $a->user_id ) ) . '</td><td>' . esc_html( $cap_labels[ $a->capability ] ?? $a->capability ) . '</td><td>' . esc_html( isset( $occurrences[ (int) $a->object_id ] ) ? self::occurrence_label( $occurrences[ (int) $a->object_id ] ) : '#' . (int) $a->object_id ) . '</td><td>';
			if ( isset( $occurrences[ (int) $a->object_id ] ) ) { self::remove_button( $a, $event_id ); }
			echo '</td></tr>';
		}
		if ( ! $assignments['occurrence'] ) { echo '<tr><td colspan="4">' . esc_html__( 'Keine Terminzuweisungen – das Team arbeitet an allen Terminen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( $members && $occurrences ) {
			echo '<h3>' . esc_html__( 'Termin zuweisen', 'pass-captain' ) . '</h3>';
			Forms::open_form( 'wpcap_assignment_add', 'wpcap_assignment_add_' . $event_id );
			Forms::hidden( 'event_id', $event_id );
			Forms::hidden( 'scope', 'occurrence' );
			$people = array();
			foreach ( array_keys( $members ) as $uid ) { $people[ $uid ] = Forms::user_label( $uid ); }
			$occ_options = array();
			foreach ( $occurrences as $oid => $occurrence ) { $occ_options[ $oid ] = self::occurrence_label( $occurrence ); }
			echo '<p><label for="wpcap-assign-user">' . esc_html__( 'Person', 'pass-captain' ) . '</label> ';
			Forms::select( 'user', $people, '', 'wpcap-assign-user' );
			echo ' <label for="wpcap-assign-cap">' . esc_html__( 'Recht', 'pass-captain' ) . '</label> ';
			Forms::select( 'capability', $cap_labels, 'wpcap_checkin', 'wpcap-assign-cap' );
			echo ' <label for="wpcap-assign-occ">' . esc_html__( 'Termin', 'pass-captain' ) . '</label> ';
			Forms::select( 'object_id', $occ_options, '', 'wpcap-assign-occ' );
			echo ' <button class="button">' . esc_html__( 'Zuweisen', 'pass-captain' ) . '</button></p></form>';
		}

		// Entry areas (admission module).
		if ( self::areas_available() ) {
			$areas = \PassCaptain\Admission\Areas::for_event( $event_id );
			echo '<h2>' . esc_html__( 'Einlassbereiche', 'pass-captain' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Wer einem oder mehreren Bereichen zugewiesen ist, kann für dieses Event nur dort scannen (Web-Scanner, Scanner-App, Gerätekopplung).', 'pass-captain' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Bereich', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Personen', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $areas as $area ) {
				$aid = (int) $area->area_id;
				echo '<tr><td>' . esc_html( $area->name ) . '</td><td>';
				foreach ( \PassCaptain\Admission\Areas::staff( $aid ) as $uid ) {
					echo '<div>' . esc_html( Forms::user_label( $uid ) ) . ' ';
					Forms::open_form( 'wpcap_assignment_area_remove', 'wpcap_assignment_area_remove_' . $aid . '_' . (int) $uid, 'style="display:inline"' );
					Forms::hidden( 'area_id', $aid );
					Forms::hidden( 'user_id', (int) $uid );
					Forms::hidden( 'event_id', $event_id );
					echo '<button class="button-link button-link-delete">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form></div>';
				}
				Forms::open_form( 'wpcap_assignment_area_add', 'wpcap_assignment_area_add_' . $aid );
				Forms::hidden( 'area_id', $aid );
				Forms::hidden( 'event_id', $event_id );
				echo '<label class="screen-reader-text" for="wpcap-area-user-' . esc_attr( (string) $aid ) . '">' . esc_html__( 'Person (Login oder E-Mail)', 'pass-captain' ) . '</label><input type="text" id="wpcap-area-user-' . esc_attr( (string) $aid ) . '" name="user" placeholder="' . esc_attr__( 'Login oder E-Mail', 'pass-captain' ) . '"> <button class="button">' . esc_html__( 'Zuweisen', 'pass-captain' ) . '</button></form></td></tr>';
			}
			if ( ! $areas ) { echo '<tr><td colspan="2">' . esc_html__( 'Dieses Event hat keine Einlassbereiche.', 'pass-captain' ) . '</td></tr>'; }
			echo '</tbody></table>';
		}

		// Services: provider records of each resource (A133); a record linked to a WordPress user with
		// the role "Pass Captain Leistungserbringer" gives that person access to the resource.
		$resources = ServiceBookings::resources( $event_id );
		echo '<h2>' . esc_html__( 'Leistungen', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Leistungserbringer mit verknüpftem WordPress-Benutzer (Rolle „Pass Captain Leistungserbringer“) sehen nur die Zeitfenster und Buchungen der Ressourcen, denen sie zugeordnet sind.', 'pass-captain' ) . ' <a href="' . esc_url( ServiceSlotsPage::url( $event_id ) . '#wpcap-service-providers' ) . '">' . esc_html__( 'Leistungserbringer verwalten und zuordnen', 'pass-captain' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Leistung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Leistungserbringer', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $resources as $resource ) {
			$names = array();
			foreach ( ServiceProviders::for_resource( (int) $resource->resource_id ) as $provider ) {
				$names[] = $provider->name . ( (int) $provider->user_id ? ' – ' . Forms::user_label( (int) $provider->user_id ) : '' ) . ( 'active' === $provider->status ? '' : ' (' . __( 'deaktiviert', 'pass-captain' ) . ')' );
			}
			echo '<tr><td>' . esc_html( $resource->name ) . '</td><td>' . esc_html( $names ? implode( '; ', $names ) : '–' ) . '</td></tr>';
		}
		if ( ! $resources ) { echo '<tr><td colspan="2">' . esc_html__( 'Dieses Event hat keine Leistungen (Service-Zeitfenster).', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
