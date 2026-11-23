<?php
/** Seat picker in the public checkout form (action wpcap_checkout_form_fields). */
namespace PassCaptain\Seating;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Picker {
	/** Pickers rendered per occurrence on this page (A504: unique ids). */
	private static $rendered = array();

	public static function register() {
		add_action( 'wpcap_checkout_form_fields', array( __CLASS__, 'render' ), 10, 2 );
	}

	public static function i18n() {
		return array(
			'title' => __( 'Sitzplätze wählen', 'pass-captain' ),
			'loading' => __( 'Saalplan wird geladen …', 'pass-captain' ),
			'loadFailed' => __( 'Der Saalplan konnte nicht geladen werden. Wir versuchen es automatisch erneut.', 'pass-captain' ),
			'free' => __( 'frei', 'pass-captain' ),
			'taken' => __( 'belegt', 'pass-captain' ),
			'selected' => __( 'ausgewählt', 'pass-captain' ),
			'accessible' => __( 'rollstuhlgerecht', 'pass-captain' ),
			'companion' => __( 'Begleitplatz (nur mit Rollstuhlplatz)', 'pass-captain' ),
			'listToggle' => __( 'Plätze als Liste', 'pass-captain' ),
			// A090: map/list switch, seat size, fit and the legend of the seat marks.
			'view' => __( 'Ansicht', 'pass-captain' ),
			'viewMap' => __( 'Saalplan', 'pass-captain' ),
			'viewList' => __( 'Liste', 'pass-captain' ),
			'fit' => __( 'Ganzer Saal', 'pass-captain' ),
			'seatLarge' => __( 'Große Sitze', 'pass-captain' ),
			/* translators: %d: minimum seat size in pixels */
			'seatLargeOn' => __( 'Große Sitze (mindestens %d px) eingeschaltet.', 'pass-captain' ),
			/* translators: %d: minimum seat size in pixels */
			'seatLargeOff' => __( 'Normale Sitzgröße (mindestens %d px).', 'pass-captain' ),
			'companionShort' => __( 'Begleitplatz', 'pass-captain' ),
			'yourSeats' => __( 'Deine Plätze', 'pass-captain' ),
			'none' => __( 'Noch keine Plätze gewählt.', 'pass-captain' ),
			'remove' => __( 'Entfernen', 'pass-captain' ),
			'ticketType' => __( 'Ticketart', 'pass-captain' ),
			'noType' => __( 'Für diesen Platz ist keine der gewählten Ticketarten erhältlich.', 'pass-captain' ),
			/* translators: 1: ticket type, 2: selected seats, 3: ordered quantity */
			'countMismatch' => __( 'Für „%1$s“ hast du %2$d Plätze gewählt, aber %3$d Tickets bestellt.', 'pass-captain' ),
			'seatTaken' => __( 'Ein gewählter Platz wurde inzwischen vergeben und entfernt. Bitte wähle neu.', 'pass-captain' ),
			/* translators: %s: seat label */
			'added' => __( '%s ausgewählt.', 'pass-captain' ),
			/* translators: %s: seat label */
			'removed' => __( '%s entfernt.', 'pass-captain' ),
			'help' => __( 'Mit den Pfeiltasten zwischen Plätzen wechseln, mit Leertaste oder Enter auswählen.', 'pass-captain' ),
			'legend' => __( 'Legende', 'pass-captain' ),
			'standing' => __( 'Stehplatz', 'pass-captain' ),
			'zoomIn' => __( 'Saalplan vergrößern', 'pass-captain' ),
			'zoomOut' => __( 'Saalplan verkleinern', 'pass-captain' ),
			/* translators: %d: zoom in percent */
			'zoomLevel' => __( 'Zoom %d %%', 'pass-captain' ),
			'best' => __( 'Beste verfügbare Plätze', 'pass-captain' ),
			/* translators: %s: seat labels */
			'bestDone' => __( 'Vorgeschlagen: %s. Du kannst die Auswahl im Plan oder in der Liste ändern.', 'pass-captain' ),
			'bestNone' => __( 'Bitte wähle zuerst die Anzahl der Tickets – oder es sind keine passenden Plätze mehr frei.', 'pass-captain' ),
			'listHint' => __( 'Auf kleinen Bildschirmen zeigen wir zuerst die Platzliste. Den Saalplan erreichst du über „Saalplan“; dort mit zwei Fingern zoomen.', 'pass-captain' ),
			// A610: fallback when the answer for a closed date carries no message of its own.
			'closed' => __( 'Für diesen Termin können keine Plätze mehr gebucht werden.', 'pass-captain' ),
		);
	}

	public static function render( $event, $types = array() ) {
		$occurrence_id = absint( is_object( $event ) ? ( $event->occurrence_id ?? 0 ) : 0 );
		if ( $occurrence_id && \PassCaptain\DisplayBatch::occurrence( $occurrence_id ) ) {
			// Event page (display batch): which occurrences have a seat map, in one query for all cards.
			$linked = \PassCaptain\DisplayBatch::memo( 'seating_links', static function () {
				global $wpdb;
				$ids = array_keys( \PassCaptain\DisplayBatch::occurrences() );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
				return array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE occurrence_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Layouts::table( 'links' ) ), $ids ) ) ) ) );
			} );
			if ( ! isset( $linked[ $occurrence_id ] ) ) { return; }
		}
		if ( ! $occurrence_id || ! Layouts::link( $occurrence_id ) || ! Layouts::type_zones( $occurrence_id ) ) { return; }
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'wpcap-seating-picker', WPCAP_URL . 'assets/seating/picker.js', array(), WPCAP_VERSION, true );
			wp_enqueue_style( 'wpcap-seating-picker', WPCAP_URL . 'assets/seating/picker.css', array(), WPCAP_VERSION );
		}
		$url = rest_url( Rest::NS . '/seating/occurrences/' . $occurrence_id );
		// A504: a second card of the same date on the page gets its own id.
		self::$rendered[ $occurrence_id ] = ( self::$rendered[ $occurrence_id ] ?? 0 ) + 1;
		$id = 'wpcap-seats-' . $occurrence_id . ( self::$rendered[ $occurrence_id ] > 1 ? '-i' . self::$rendered[ $occurrence_id ] : '' );
		echo '<fieldset class="wpcap-seating" id="' . esc_attr( $id ) . '" data-occurrence="' . esc_attr( (string) $occurrence_id ) . '" data-url="' . esc_attr( esc_url_raw( $url ) ) . '" data-i18n="' . esc_attr( wp_json_encode( self::i18n() ) ) . '">';
		echo '<legend>' . esc_html__( 'Sitzplätze wählen', 'pass-captain' ) . '</legend>';
		echo '<p class="wpcap-seating-status" role="status" aria-live="polite">' . esc_html__( 'Saalplan wird geladen …', 'pass-captain' ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'Für die Sitzplatzwahl wird JavaScript benötigt.', 'pass-captain' ) . '</p></noscript>';
		echo '</fieldset>';
	}
}
