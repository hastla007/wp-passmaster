<?php
/**
 * Admin screen "Wartelisten": entries of an event (optionally one occurrence) in queue order,
 * manual "Angebot senden" to the next waiting person of a ticket type (Waitlists::offer_next),
 * removing entries and an audited CSV export.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Reports\Scope;
use PassCaptain\Security\DataPolicy;
use PassCaptain\Waitlists;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WaitlistsPage {
	const SLUG = 'wpcap-waitlists';
	const ACTION = 'wpcap_waitlist_action';
	const EXPORT_ACTION = 'wpcap_waitlist_export';
	const CAP = 'wpcap_manage_orders';

	/** E-mail as the current user may see it (masked for the support profile, DataPolicy). */
	private static function email( $email ) {
		return DataPolicy::masks_pii() ? DataPolicy::mask_email( (string) $email ) : (string) $email;
	}

	/** Labels of the event waitlist statuses shown here (double opt-in sign-ups wait as `pending`, Waitlists A564). */
	public static function status_labels() {
		// Merged Waitlists (statuses of the Arbeitsliste line: `pending` instead of `unconfirmed`, plus paid/declined/…).
		$labels = array( 'pending' => __( 'Unbestätigt (Bestätigungslink offen)', 'pass-captain' ), 'waiting' => __( 'Wartet', 'pass-captain' ), 'offered' => __( 'Angebot gesendet', 'pass-captain' ), 'accepted' => __( 'Angenommen', 'pass-captain' ), 'expired' => __( 'Angebot abgelaufen', 'pass-captain' ) );
		foreach ( array( 'paid', 'declined', 'skipped', 'removed', 'closed', 'blocked' ) as $status ) { $labels[ $status ] = Waitlists::status_label( $status ); }
		$labels['unconfirmed'] = $labels['pending'];
		return $labels;
	}

	/** Entries of the screen and the export: removed entries (kept until retention deletes them) are left out. */
	private static function listed_entries( $event_id, $occurrence_id ) {
		return array_values( array_filter( (array) Waitlists::entries( $event_id, $occurrence_id ), static function ( $e ) { return 'removed' !== $e->status; } ) );
	}

	/**
	 * Executes an admin action (testable without a request).
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		switch ( $do ) {
			case 'offer':
				$event_id = Forms::int( $in, 'event_id' );
				$type_id = Forms::int( $in, 'ticket_type_id' );
				if ( ! $event_id || FeatureScreens::event_of_ticket_type( $type_id ) !== $event_id || ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Ticketart.', 'pass-captain' ) ); }
				$result = Waitlists::offer_next( $event_id, $type_id );
				if ( ! is_wp_error( $result ) ) {
					Audit::record( 'waitlist_offer_sent', 'waitlist', (int) $result['waitlist_id'], array( 'event_id' => $event_id, 'ticket_type_id' => $type_id, 'position' => (int) $result['position'], 'expires_at' => $result['expires_at'] ) );
				}
				return $result;
			case 'remove':
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT waitlist_id,event_id FROM %i WHERE waitlist_id=%d', $wpdb->prefix . 'wpcap_waitlist', Forms::int( $in, 'id' ) ) );
				if ( ! $row ) { return Forms::error( 'wpcap_waitlist_missing', __( 'Wartelisteneintrag nicht gefunden.', 'pass-captain' ), 404 ); }
				if ( ! Access::can_access_event( (int) $row->event_id, self::CAP ) ) { return Forms::forbidden(); }
				return Waitlists::remove( (int) $row->waitlist_id );
		}
		return Forms::error( 'wpcap_waitlist_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		$event_id = is_array( $result ) && ! empty( $result['event_id'] ) ? (int) $result['event_id'] : Forms::int( $in, 'event_id' );
		$args = array_filter( array( 'event_id' => $event_id, 'occurrence_id' => Forms::int( $in, 'occurrence_id' ) ) );
		$message = 'offer' === $do && is_array( $result )
			/* translators: %d: queue position */
			? sprintf( __( 'Angebot an Position %d gesendet. Es ist 24 Stunden gültig und reserviert noch kein Ticket.', 'pass-captain' ), (int) $result['position'] )
			: __( 'Eintrag entfernt.', 'pass-captain' );
		Forms::finish( $result, $message, Forms::page_url( self::SLUG, $args ) );
	}

	/**
	 * CSV export of an event's waitlist (capability-checked, audited).
	 *
	 * @return string|\WP_Error CSV text.
	 */
	public static function export( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		if ( ! current_user_can( self::CAP ) || ! $event_id || ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden(); }
		$labels = self::status_labels();
		$rows = array( array( __( 'Position', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'Termin', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Eingetragen (UTC)', 'pass-captain' ), __( 'Angebot gültig bis (UTC)', 'pass-captain' ) ) );
		$entries = self::listed_entries( $event_id, $occurrence_id );
		foreach ( $entries as $e ) {
			$rows[] = array( (int) $e->position, self::email( $e->email ), (string) $e->ticket_name, Forms::datetime( $e->starts_at, $e->timezone ), $labels[ $e->status ] ?? $e->status, (string) $e->created_at, (string) $e->offer_expires_at );
		}
		Audit::record( 'waitlist_exported', 'event', $event_id, array( 'occurrence_id' => $occurrence_id, 'rows' => count( $entries ) ) );
		return Scope::csv( $rows );
	}

	public static function export_url( $event_id, $occurrence_id = 0 ) {
		return wp_nonce_url( add_query_arg( array_filter( array( 'action' => self::EXPORT_ACTION, 'event_id' => (int) $event_id, 'occurrence_id' => (int) $occurrence_id ) ), admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
	}

	public static function handle_export() {
		check_admin_referer( self::EXPORT_ACTION );
		$in = array( 'event_id' => absint( $_GET['event_id'] ?? 0 ), 'occurrence_id' => absint( $_GET['occurrence_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$csv = self::export( $in );
		if ( is_wp_error( $csv ) ) { wp_die( esc_html( $csv->get_error_message() ), '', array( 'response' => 403 ) ); }
		Scope::send_download( $csv, 'warteliste-event-' . (int) $in['event_id'] . '.csv', 'text/csv; charset=utf-8' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Wartelisten', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$events = Queries::event_options( Queries::event_ids_for( self::CAP ) );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( ! isset( $events[ $event_id ] ) || ! Access::can_access_event( $event_id, self::CAP ) ) { $event_id = 0; }
		$occurrence_id = $event_id ? absint( Forms::query_arg( 'occurrence_id', 0 ) ) : 0;
		$occurrences = array();
		if ( $event_id ) {
			foreach ( Queries::occurrences_for_event( $event_id ) as $o ) { $occurrences[ (int) $o->occurrence_id ] = Forms::datetime( $o->starts_at, $o->timezone ); }
		}
		if ( ! isset( $occurrences[ $occurrence_id ] ) ) { $occurrence_id = 0; }
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-wl-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( '' => '—' ) + $events, $event_id, 'wpcap-wl-event' );
		if ( $occurrences ) {
			echo ' <label for="wpcap-wl-occurrence">' . esc_html__( 'Termin', 'pass-captain' ) . '</label> ';
			Forms::select( 'occurrence_id', array( '' => __( 'Alle Termine', 'pass-captain' ) ) + $occurrences, $occurrence_id, 'wpcap-wl-occurrence' );
		}
		echo ' <button type="submit" class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button></form>';
		if ( ! $event_id ) { echo '<p>' . esc_html__( 'Bitte ein Event wählen.', 'pass-captain' ) . '</p></div>'; return; }
		$entries = self::listed_entries( $event_id, $occurrence_id );
		echo '<p>' . esc_html__( '„Angebot senden“ schreibt die nächste wartende Person einer Ticketart per E-Mail an. Das Angebot ist 24 Stunden gültig; erst bei Annahme wird eine Bestellung mit Reservierung angelegt.', 'pass-captain' ) . ' <a class="button" href="' . esc_url( self::export_url( $event_id, $occurrence_id ) ) . '">' . esc_html__( 'CSV exportieren', 'pass-captain' ) . '</a></p>';
		if ( ! $entries ) { echo '<p>' . esc_html__( 'Keine Einträge auf der Warteliste.', 'pass-captain' ) . '</p></div>'; return; }
		$groups = array();
		foreach ( $entries as $e ) { $groups[ (int) $e->ticket_type_id ][] = $e; }
		$labels = self::status_labels();
		foreach ( $groups as $type_id => $rows ) {
			$first = $rows[0];
			$waiting = count( array_filter( $rows, static function ( $r ) { return 'waiting' === $r->status; } ) );
			echo '<h2>' . esc_html( ( '' !== (string) $first->ticket_name ? $first->ticket_name : '#' . $type_id ) . ' – ' . Forms::datetime( $first->starts_at, $first->timezone ) ) . '</h2><p>';
			/* translators: %d: number of waiting people */
			echo esc_html( sprintf( _n( '%d Person wartet.', '%d Personen warten.', $waiting, 'pass-captain' ), $waiting ) ) . ' ';
			if ( $waiting ) {
				FeatureScreens::button( self::ACTION, array( 'do' => 'offer', 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id ), __( 'Angebot an nächste Person senden', 'pass-captain' ), '', 'button button-primary' );
			}
			echo '</p><table class="widefat striped"><caption class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: ticket type */ __( 'Warteliste %s', 'pass-captain' ), $first->ticket_name ) ) . '</caption><thead><tr>';
			foreach ( array( __( 'Position', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Eingetragen', 'pass-captain' ), __( 'Angebot gültig bis', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $e ) {
				echo '<tr><td>' . (int) $e->position . '</td><td>' . esc_html( self::email( $e->email ) ) . '</td><td>' . esc_html( $labels[ $e->status ] ?? $e->status ) . '</td><td>' . esc_html( Forms::datetime( $e->created_at, $e->timezone, false ) ) . '</td><td>' . esc_html( 'offered' === $e->status ? Forms::datetime( $e->offer_expires_at, $e->timezone, false ) : '—' ) . '</td><td>';
				FeatureScreens::button( self::ACTION, array( 'do' => 'remove', 'id' => (int) $e->waitlist_id, 'event_id' => $event_id, 'occurrence_id' => $occurrence_id ), __( 'Entfernen', 'pass-captain' ), __( 'Diesen Eintrag von der Warteliste entfernen?', 'pass-captain' ), 'button button-small button-link-delete' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
