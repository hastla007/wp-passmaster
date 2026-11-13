<?php
/**
 * Admin screen "Warteliste": entries per event with queue rank, status and offer deadline, staff
 * actions (offer, skip, remove, priority, process now) and the automatic offer settings.
 */
namespace PassCaptain\Waitlists;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Settings;
use PassCaptain\Waitlists;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const PAGE = 'wpcap-waitlist';
	const CAP = 'wpcap_manage_orders';
	const SETTINGS_CAP = 'manage_options';
	const ACTION_ENTRY = 'wpcap_waitlist_entry';
	const ACTION_SETTINGS = 'wpcap_waitlist_settings';

	/**
	 * Staff action on one entry (or the event): offer, skip, remove, priority, process.
	 *
	 * @return array|\WP_Error
	 */
	public static function entry_action( array $in ) {
		$op = Forms::choice( $in, 'op', array( 'offer', 'skip', 'remove', 'priority', 'process' ), '' );
		$event_id = Forms::int( $in, 'event_id' );
		if ( 'process' === $op ) {
			if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden(); }
			$made = Waitlists::process_event_now( $event_id );
			/* translators: %d: number of offers */
			return array( 'message' => sprintf( _n( '%d Angebot versendet.', '%d Angebote versendet.', $made, 'pass-captain' ), $made ) );
		}
		$entry = Waitlists::get( Forms::int( $in, 'waitlist_id' ) );
		if ( ! $entry ) { return Forms::error( 'wpcap_waitlist_missing', __( 'Eintrag nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $entry->event_id, self::CAP ) ) { return Forms::forbidden(); }
		switch ( $op ) {
			case 'offer':
				$result = Waitlists::offer( (int) $entry->waitlist_id );
				return is_wp_error( $result ) ? $result : array( 'message' => __( 'Angebot versendet; die Tickets sind reserviert.', 'pass-captain' ) );
			case 'skip':
			case 'remove':
				$result = Waitlists::close( (int) $entry->waitlist_id, 'remove' === $op ? 'removed' : 'skipped' );
				return is_wp_error( $result ) ? $result : array( 'message' => 'remove' === $op ? __( 'Eintrag entfernt.', 'pass-captain' ) : __( 'Eintrag übersprungen.', 'pass-captain' ) );
			case 'priority':
				Waitlists::set_priority( (int) $entry->waitlist_id, Forms::int( $in, 'priority' ) );
				return array( 'message' => __( 'Priorität gespeichert.', 'pass-captain' ) );
		}
		return Forms::error( 'wpcap_waitlist_op', __( 'Unbekannte Aktion.', 'pass-captain' ) );
	}

	public static function handle_entry() {
		Forms::verify( self::ACTION_ENTRY );
		$in = Forms::post();
		$result = self::entry_action( $in );
		Forms::finish( $result, is_array( $result ) ? (string) ( $result['message'] ?? '' ) : '', Forms::page_url( self::PAGE, array( 'event_id' => Forms::int( $in, 'event_id' ) ) ) );
	}

	/** Saves the settings. */
	public static function save_settings( array $in ) {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) { return Forms::forbidden(); }
		$values = array(
			'auto_offers' => Forms::flag( $in, 'auto_offers' ) ? 1 : 0,
			'offer_hours' => max( 1, min( 336, Forms::int( $in, 'offer_hours', 24 ) ) ),
			'max_offers_per_run' => max( 1, min( 100, Forms::int( $in, 'max_offers_per_run', 10 ) ) ),
			'strict_fifo' => Forms::flag( $in, 'strict_fifo' ) ? 1 : 0,
		);
		Settings::update( array( 'waitlist' => $values ) );
		return array( 'saved' => $values );
	}

	public static function handle_settings() {
		Forms::verify( self::ACTION_SETTINGS );
		Forms::finish( self::save_settings( Forms::post() ), __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$events = Queries::event_options( Queries::event_ids_for( self::CAP ) );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( $event_id && ! isset( $events[ $event_id ] ) ) { $event_id = 0; }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Warteliste', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label for="wpcap-wl-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( 0 => __( '– Event wählen –', 'pass-captain' ) ) + $events, $event_id, 'wpcap-wl-event' );
		echo ' <button class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button></form>';
		if ( $event_id ) { self::render_entries( $event_id ); }
		if ( current_user_can( self::SETTINGS_CAP ) ) { self::render_settings(); }
		echo '</div>';
	}

	private static function action_button( $op, $entry, $label, $extra = '' ) {
		Forms::open_form( self::ACTION_ENTRY, null, 'style="display:inline"' );
		Forms::hidden( 'op', $op );
		Forms::hidden( 'event_id', (int) $entry->event_id );
		Forms::hidden( 'waitlist_id', (int) $entry->waitlist_id );
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below.
		echo '<button class="button button-small">' . esc_html( $label ) . '</button></form> ';
	}

	private static function render_entries( $event_id ) {
		$rows = Waitlists::entries( $event_id );
		Forms::open_form( self::ACTION_ENTRY, null, 'style="margin:1em 0"' );
		Forms::hidden( 'op', 'process' );
		Forms::hidden( 'event_id', $event_id );
		echo '<button class="button">' . esc_html__( 'Freie Plätze jetzt anbieten', 'pass-captain' ) . '</button></form>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine Einträge für dieses Event.', 'pass-captain' ) . '</p>';
			return;
		}
		\PassCaptain\Privacy\AccessLog::view( 'waitlist', 'event', (int) $event_id, array( 'rows' => count( $rows ) ) );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Rang', 'pass-captain' ), __( 'Position', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Anzahl', 'pass-captain' ), __( 'Priorität', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Reserviert bis', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$open = in_array( $row->status, array( 'waiting', 'offered', 'accepted' ), true );
			echo '<tr><td>' . esc_html( $row->queue_rank ? (string) $row->queue_rank : '–' ) . '</td><td>' . esc_html( (string) (int) $row->position ) . '</td><td>' . esc_html( (string) $row->ticket_name ) . '</td><td>' . esc_html( (string) $row->email ) . '</td><td>' . esc_html( (string) (int) $row->quantity ) . '</td><td>' . esc_html( (string) (int) $row->priority ) . '</td><td>' . esc_html( Waitlists::status_label( $row->status ) ) . '</td><td>' . esc_html( in_array( $row->status, array( 'offered', 'accepted' ), true ) && $row->offer_expires_at ? Forms::datetime( $row->offer_expires_at, wp_timezone_string() ) : '–' ) . '</td><td>' . esc_html( $row->order_public_id ? substr( (string) $row->order_public_id, 0, 8 ) : '–' ) . '</td><td>';
			if ( 'waiting' === $row->status ) {
				self::action_button( 'offer', $row, __( 'Jetzt anbieten', 'pass-captain' ) );
				$field = '<input type="number" name="priority" value="' . esc_attr( (string) (int) $row->priority ) . '" min="-1000" max="1000" style="width:5em" aria-label="' . esc_attr__( 'Priorität', 'pass-captain' ) . '"> ';
				self::action_button( 'priority', $row, __( 'Priorität setzen', 'pass-captain' ), $field );
			}
			if ( $open ) {
				self::action_button( 'skip', $row, __( 'Überspringen', 'pass-captain' ) );
				self::action_button( 'remove', $row, __( 'Entfernen', 'pass-captain' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_settings() {
		$c = Waitlists::config();
		echo '<h2>' . esc_html__( 'Einstellungen', 'pass-captain' ) . '</h2>';
		Forms::open_form( self::ACTION_SETTINGS );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Automatische Angebote', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="auto_offers" value="1"' . checked( ! empty( $c['auto_offers'] ), true, false ) . '> ' . esc_html__( 'Frei werdende Tickets automatisch der Warteliste anbieten (Reservierung, Storno, Erstattung, abgelaufene Angebote)', 'pass-captain' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-wl-hours">' . esc_html__( 'Gültigkeit eines Angebots (Stunden)', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-wl-hours" name="offer_hours" min="1" max="336" value="' . esc_attr( (string) $c['offer_hours'] ) . '"><p class="description">' . esc_html__( 'So lange sind die Tickets für die Person reserviert (höchstens bis zum Beginn des Termins).', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-wl-max">' . esc_html__( 'Höchstens Angebote pro Durchlauf', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-wl-max" name="max_offers_per_run" min="1" max="100" value="' . esc_attr( (string) $c['max_offers_per_run'] ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Strikte Reihenfolge', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="strict_fifo" value="1"' . checked( ! empty( $c['strict_fifo'] ), true, false ) . '> ' . esc_html__( 'Nicht vorziehen: Passt die gewünschte Anzahl des nächsten Eintrags nicht, wird gewartet statt kleinere Einträge dahinter zu bedienen.', 'pass-captain' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Speichern', 'pass-captain' ) );
		echo '</form>';
	}
}
