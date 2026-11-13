<?php
/** Admin screen "Saalpläne": layout list, graphical editor, versions and occurrence links. */
namespace PassCaptain\Seating;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-seating';

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Request logic (testable without rendering)
	 * ------------------------------------------------------------------------------------------- */

	public static function create( array $in ) {
		return Layouts::create( Forms::int( $in, 'event_id' ), Forms::text( $in, 'name' ) );
	}

	/** Saves the draft and optionally publishes it (do=publish). */
	public static function save( array $in ) {
		$layout_id = Forms::int( $in, 'layout_id' );
		$draft = isset( $in['draft'] ) && is_string( $in['draft'] ) ? json_decode( $in['draft'], true ) : null;
		$result = Layouts::save_draft( $layout_id, $draft, Forms::int( $in, 'revision' ), Forms::text( $in, 'name' ) );
		if ( is_wp_error( $result ) || 'publish' !== Forms::choice( $in, 'do', array( 'save', 'publish' ), 'save' ) ) { return $result; }
		$published = Layouts::publish( $layout_id, Forms::flag( $in, 'upgrade_links' ) );
		if ( is_wp_error( $published ) ) { return $published; }
		return array_merge( $result, $published, array( 'published' => true ) );
	}

	/** Links an occurrence: version_id, zones[ticket_type_id][] = zone/category keys. */
	public static function link( array $in ) {
		$zones = $in['zones'] ?? array();
		$map = array();
		if ( is_array( $zones ) ) {
			foreach ( $zones as $type_id => $keys ) {
				$map[ absint( $type_id ) ] = is_array( $keys ) ? array_values( array_filter( array_map( 'sanitize_key', array_filter( $keys, 'is_scalar' ) ) ) ) : array();
			}
		}
		return Layouts::link_occurrence( Forms::int( $in, 'occurrence_id' ), Forms::int( $in, 'version_id' ), $map );
	}

	public static function handle_create() {
		Forms::verify( 'wpcap_seating_create' );
		$result = self::create( Forms::post() );
		Forms::finish( $result, __( 'Saalplan angelegt.', 'pass-captain' ), is_wp_error( $result ) ? self::url() : self::url( array( 'layout_id' => $result['layout_id'] ) ) );
	}

	public static function handle_save() {
		$in = Forms::post();
		$layout_id = Forms::int( $in, 'layout_id' );
		Forms::verify( 'wpcap_seating_save_' . $layout_id );
		$result = self::save( $in );
		$message = __( 'Entwurf gespeichert.', 'pass-captain' );
		if ( ! is_wp_error( $result ) && ! empty( $result['published'] ) ) {
			/* translators: 1: version, 2: number of occurrences */
			$message = sprintf( __( 'Version %1$d veröffentlicht; %2$d verknüpfte Termine verwenden jetzt diese Version.', 'pass-captain' ), (int) $result['version'], count( $result['upgraded'] ) );
		}
		Forms::finish( $result, $message, self::url( array( 'layout_id' => $layout_id ) ) );
	}

	public static function handle_link() {
		$in = Forms::post();
		Forms::verify( 'wpcap_seating_link' );
		Forms::finish( self::link( $in ), __( 'Saalplan mit dem Termin verknüpft.', 'pass-captain' ), self::url( array( 'layout_id' => Forms::int( $in, 'layout_id' ) ) ) );
	}

	public static function handle_unlink() {
		$in = Forms::post();
		Forms::verify( 'wpcap_seating_unlink' );
		Forms::finish( Layouts::unlink_occurrence( Forms::int( $in, 'occurrence_id' ) ), __( 'Verknüpfung entfernt.', 'pass-captain' ), self::url( array( 'layout_id' => Forms::int( $in, 'layout_id' ) ) ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------------------------------- */

	public static function enqueue( $hook = '' ) {
		if ( false === strpos( (string) $hook, self::SLUG ) || ! absint( $_GET['layout_id'] ?? 0 ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		wp_enqueue_script( 'wpcap-seating-editor', WPCAP_URL . 'assets/seating/editor.js', array(), WPCAP_VERSION, true );
		wp_enqueue_style( 'wpcap-seating-editor', WPCAP_URL . 'assets/seating/editor.css', array(), WPCAP_VERSION );
	}

	public static function render() {
		if ( ! current_user_can( Layouts::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin wpcap-seating-admin">';
		Forms::render_notices();
		$layout_id = absint( $_GET['layout_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $layout_id ) {
			self::render_editor( $layout_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list() {
		echo '<h1>' . esc_html__( 'Saalpläne', 'pass-captain' ) . '</h1>';
		$events = Queries::event_options( Queries::event_ids_for( Layouts::CAP ) );
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'Keine Events verfügbar.', 'pass-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'pass-captain' ) . '</th><th>' . esc_html__( 'Saalplan', 'pass-captain' ) . '</th><th>' . esc_html__( 'Veröffentlichte Version', 'pass-captain' ) . '</th><th>' . esc_html__( 'Geändert', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$any = false;
		foreach ( $events as $event_id => $name ) {
			foreach ( Layouts::for_event( $event_id ) as $layout ) {
				$any = true;
				echo '<tr><td>' . esc_html( $name ) . '</td><td><a href="' . esc_url( self::url( array( 'layout_id' => (int) $layout->layout_id ) ) ) . '">' . esc_html( $layout->name ) . '</a></td><td>' . ( $layout->published_version ? (int) $layout->published_version : esc_html__( '— (nur Entwurf)', 'pass-captain' ) ) . '</td><td>' . esc_html( Forms::datetime( $layout->updated_at, wp_timezone_string() ) ) . '</td></tr>';
			}
		}
		if ( ! $any ) { echo '<tr><td colspan="4">' . esc_html__( 'Noch keine Saalpläne angelegt.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Neuer Saalplan', 'pass-captain' ) . '</h2>';
		Forms::open_form( 'wpcap_seating_create' );
		echo '<p><label for="wpcap-seating-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', $events, '', 'wpcap-seating-event' );
		echo '</p><p><label for="wpcap-seating-name">' . esc_html__( 'Name', 'pass-captain' ) . '</label> <input id="wpcap-seating-name" name="name" type="text" class="regular-text" required maxlength="190"></p>';
		submit_button( __( 'Saalplan anlegen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function render_editor( $layout_id ) {
		$layout = Layouts::get( $layout_id );
		if ( ! $layout || ! Access::can_access_event( $layout->event_id, Layouts::CAP ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nicht gefunden oder keine Berechtigung für dieses Event.', 'pass-captain' ) . '</p></div>';
			return;
		}
		$event = Queries::event( $layout->event_id );
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Alle Saalpläne', 'pass-captain' ) . '</a></p>';
		/* translators: 1: layout, 2: event */
		echo '<h1>' . esc_html( sprintf( __( 'Saalplan „%1$s“ – %2$s', 'pass-captain' ), $layout->name, $event ? $event->name : '' ) ) . '</h1>';
		$locked = array();
		foreach ( Layouts::locked_seats( $layout->layout_id ) as $key => $seat ) {
			$locked[ $key ] = array( 'kind' => $seat->kind, 'row' => $seat->row_label, 'label' => $seat->seat_label, 'zone' => $seat->zone, 'category' => $seat->category, 'x' => (float) $seat->pos_x, 'y' => (float) $seat->pos_y, 'status' => $seat->status );
		}
		$i18n = array(
			'row' => __( 'Reihe', 'pass-captain' ),
			'table' => __( 'Tisch', 'pass-captain' ),
			'block' => __( 'Block', 'pass-captain' ),
			'standing' => __( 'Stehbereich', 'pass-captain' ),
			'locked' => __( 'Verkauft/reserviert – nicht änderbar', 'pass-captain' ),
			'lockedDelete' => __( 'Verkaufte oder reservierte Plätze können nicht gelöscht oder verschoben werden.', 'pass-captain' ),
			'nothingSelected' => __( 'Nichts ausgewählt. Klicke einen Platz oder eine Gruppe an (Umschalt+Klick für Mehrfachauswahl).', 'pass-captain' ),
			'selected' => __( 'Ausgewählt', 'pass-captain' ),
			'unsaved' => __( 'Ungespeicherte Änderungen', 'pass-captain' ),
			'confirmDelete' => __( 'Auswahl wirklich löschen?', 'pass-captain' ),
			'zone' => __( 'Bereich', 'pass-captain' ),
			'category' => __( 'Kategorie', 'pass-captain' ),
			'none' => __( '— keine —', 'pass-captain' ),
			'seats' => __( 'Plätze', 'pass-captain' ),
			'zones' => __( 'Bereiche', 'pass-captain' ),
			'categories' => __( 'Kategorien', 'pass-captain' ),
			'standingAreas' => __( 'Stehbereiche', 'pass-captain' ),
			'tools' => __( 'Werkzeuge', 'pass-captain' ),
			'map' => __( 'Saalplan', 'pass-captain' ),
			'add' => __( 'Hinzufügen', 'pass-captain' ),
			'defaults' => __( 'Standard für neue Plätze', 'pass-captain' ),
			'spacing' => __( 'Abstand', 'pass-captain' ),
			'startNumber' => __( 'ab Nr.', 'pass-captain' ),
			'rows' => __( 'Reihen', 'pass-captain' ),
			'capacity' => __( 'Kapazität', 'pass-captain' ),
			'color' => __( 'Farbe', 'pass-captain' ),
			'remove' => __( 'Entfernen', 'pass-captain' ),
			'name' => __( 'Name', 'pass-captain' ),
			'stage' => __( 'Bühne', 'pass-captain' ),
			'width' => __( 'Breite', 'pass-captain' ),
			'height' => __( 'Höhe', 'pass-captain' ),
			'apply' => __( 'Übernehmen', 'pass-captain' ),
			'delete' => __( 'Löschen', 'pass-captain' ),
			'seat' => __( 'Platz', 'pass-captain' ),
			'renumber' => __( 'Neu nummerieren ab (leer = nein)', 'pass-captain' ),
			'accessible' => __( 'rollstuhlgerecht', 'pass-captain' ),
			/* translators: %s: list name (zone or category) */
			'addNamed' => __( '%s hinzufügen', 'pass-captain' ),
			'help' => __( 'Klicken wählt einen Platz, Umschalt+Klick erweitert die Auswahl, Doppelklick wählt die ganze Gruppe. Ziehen oder Pfeiltasten verschieben (Umschalt: 1 px), Entf löscht, Esc hebt die Auswahl auf.', 'pass-captain' ),
		);
		echo '<div id="wpcap-seat-editor" class="wpcap-seat-editor" data-draft="' . esc_attr( $layout->draft ) . '" data-locked="' . esc_attr( wp_json_encode( (object) $locked ) ) . '" data-i18n="' . esc_attr( wp_json_encode( $i18n ) ) . '">';
		echo '<noscript><p>' . esc_html__( 'Der grafische Editor benötigt JavaScript.', 'pass-captain' ) . '</p></noscript></div>';
		Forms::open_form( 'wpcap_seating_save', 'wpcap_seating_save_' . (int) $layout->layout_id, 'id="wpcap-seat-editor-form"' );
		Forms::hidden( 'layout_id', (int) $layout->layout_id );
		Forms::hidden( 'revision', (int) $layout->draft_revision );
		echo '<input type="hidden" name="draft" id="wpcap-seat-editor-draft" value="' . esc_attr( $layout->draft ) . '">';
		echo '<p><label for="wpcap-seat-layout-name">' . esc_html__( 'Name', 'pass-captain' ) . '</label> <input id="wpcap-seat-layout-name" name="name" type="text" class="regular-text" value="' . esc_attr( $layout->name ) . '" maxlength="190"></p>';
		echo '<p><label><input type="checkbox" name="upgrade_links" value="1" checked> ' . esc_html__( 'Beim Veröffentlichen verknüpfte Termine auf die neue Version umstellen', 'pass-captain' ) . '</label></p>';
		echo '<p class="submit"><button type="submit" class="button" name="do" value="save">' . esc_html__( 'Entwurf speichern', 'pass-captain' ) . '</button> <button type="submit" class="button button-primary" name="do" value="publish">' . esc_html__( 'Speichern und als neue Version veröffentlichen', 'pass-captain' ) . '</button></p>';
		echo '</form>';
		if ( $locked ) {
			/* translators: %d: number of seats */
			echo '<p class="description">' . esc_html( sprintf( __( '%d Plätze sind verkauft oder reserviert und gesperrt (Position, Bezeichnung und Bereich bleiben unverändert).', 'pass-captain' ), count( $locked ) ) ) . '</p>';
		}
		self::render_versions( $layout );
		self::render_links( $layout );
	}

	private static function render_versions( $layout ) {
		echo '<h2>' . esc_html__( 'Versionen', 'pass-captain' ) . '</h2>';
		$versions = Layouts::versions( $layout->layout_id );
		if ( ! $versions ) { echo '<p>' . esc_html__( 'Noch keine Version veröffentlicht.', 'pass-captain' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Version', 'pass-captain' ) . '</th><th>' . esc_html__( 'Plätze', 'pass-captain' ) . '</th><th>' . esc_html__( 'Veröffentlicht', 'pass-captain' ) . '</th><th>' . esc_html__( 'Von', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $versions as $version ) {
			echo '<tr><td>' . (int) $version->version . '</td><td>' . (int) $version->seat_count . '</td><td>' . esc_html( Forms::datetime( $version->created_at, wp_timezone_string() ) ) . '</td><td>' . esc_html( Forms::user_label( $version->created_by ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_links( $layout ) {
		global $wpdb;
		echo '<h2>' . esc_html__( 'Termine verknüpfen', 'pass-captain' ) . '</h2>';
		$versions = Layouts::versions_for_event( $layout->event_id );
		if ( ! $versions ) { echo '<p>' . esc_html__( 'Veröffentliche zuerst eine Version.', 'pass-captain' ) . '</p>'; return; }
		$version_options = array();
		$keys = array();
		foreach ( $versions as $version ) {
			/* translators: 1: layout name, 2: version */
			$version_options[ (int) $version->version_id ] = sprintf( __( '%1$s – Version %2$d', 'pass-captain' ), $version->name, (int) $version->version );
		}
		$latest = Layouts::version( (int) $versions[0]->version_id );
		$definition = json_decode( $latest->definition, true );
		foreach ( array( 'zones' => __( 'Bereich', 'pass-captain' ), 'categories' => __( 'Kategorie', 'pass-captain' ) ) as $list => $label ) {
			foreach ( (array) ( $definition[ $list ] ?? array() ) as $item ) { $keys[ $item['key'] ] = $label . ': ' . $item['name']; }
		}
		foreach ( Queries::occurrences_for_event( $layout->event_id ) as $occurrence ) {
			$link = Layouts::link( $occurrence->occurrence_id );
			$mapping = Layouts::type_zones( $occurrence->occurrence_id );
			echo '<div class="wpcap-seating-link"><h3>' . esc_html( Forms::datetime( $occurrence->starts_at, $occurrence->timezone ) ) . ( $occurrence->location ? ' · ' . esc_html( $occurrence->location ) : '' ) . '</h3>';
			Forms::open_form( 'wpcap_seating_link' );
			Forms::hidden( 'layout_id', (int) $layout->layout_id );
			Forms::hidden( 'occurrence_id', (int) $occurrence->occurrence_id );
			$select_id = 'wpcap-seating-version-' . (int) $occurrence->occurrence_id;
			echo '<p><label for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Saalplan-Version', 'pass-captain' ) . '</label> ';
			Forms::select( 'version_id', $version_options, $link ? (int) $link->version_id : (int) $versions[0]->version_id, $select_id );
			echo '</p><table class="widefat"><thead><tr><th>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th>' . esc_html__( 'Erlaubte Bereiche/Kategorien (leer = ohne Sitzplatz)', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( Queries::ticket_types_for_occurrence( $occurrence->occurrence_id ) as $type ) {
				echo '<tr><td>' . esc_html( $type->name ) . '</td><td>';
				foreach ( $keys as $key => $label ) {
					$checked = in_array( $key, $mapping[ (int) $type->ticket_type_id ] ?? array(), true );
					echo '<label class="wpcap-seating-zone"><input type="checkbox" name="zones[' . (int) $type->ticket_type_id . '][]" value="' . esc_attr( $key ) . '"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label> ';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			submit_button( $link ? __( 'Verknüpfung aktualisieren', 'pass-captain' ) : __( 'Verknüpfen', 'pass-captain' ), 'secondary', 'submit', false );
			echo '</form>';
			if ( $link ) {
				$claimed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Layouts::table( 'claims' ) . ' WHERE occurrence_id=%d AND active_key IS NOT NULL', (int) $occurrence->occurrence_id ) );
				/* translators: %d: seats */
				echo '<p>' . esc_html( sprintf( __( 'Verknüpft · %d Plätze reserviert/verkauft', 'pass-captain' ), $claimed ) ) . '</p>';
				if ( ! $claimed ) {
					Forms::open_form( 'wpcap_seating_unlink' );
					Forms::hidden( 'layout_id', (int) $layout->layout_id );
					Forms::hidden( 'occurrence_id', (int) $occurrence->occurrence_id );
					submit_button( __( 'Verknüpfung entfernen', 'pass-captain' ), 'link-delete', 'submit', false );
					echo '</form>';
				}
			}
			echo '</div>';
		}
	}
}
