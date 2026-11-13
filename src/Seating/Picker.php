<?php
/** Seat picker in the public checkout form (action wpcap_checkout_form_fields). */
namespace PassCaptain\Seating;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Picker {
	public static function register() {
		add_action( 'wpcap_checkout_form_fields', array( __CLASS__, 'render' ), 10, 2 );
	}

	public static function i18n() {
		return array(
			'title' => __( 'Sitzplätze wählen', 'pass-captain' ),
			'loading' => __( 'Saalplan wird geladen …', 'pass-captain' ),
			'loadFailed' => __( 'Der Saalplan konnte nicht geladen werden. Bitte lade die Seite neu.', 'pass-captain' ),
			'free' => __( 'frei', 'pass-captain' ),
			'taken' => __( 'belegt', 'pass-captain' ),
			'selected' => __( 'ausgewählt', 'pass-captain' ),
			'accessible' => __( 'rollstuhlgerecht', 'pass-captain' ),
			'listToggle' => __( 'Plätze als Liste anzeigen', 'pass-captain' ),
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
		);
	}

	public static function render( $event, $types = array() ) {
		$occurrence_id = absint( is_object( $event ) ? ( $event->occurrence_id ?? 0 ) : 0 );
		if ( ! $occurrence_id || ! Layouts::link( $occurrence_id ) || ! Layouts::type_zones( $occurrence_id ) ) { return; }
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'wpcap-seating-picker', WPCAP_URL . 'assets/seating/picker.js', array(), WPCAP_VERSION, true );
			wp_enqueue_style( 'wpcap-seating-picker', WPCAP_URL . 'assets/seating/picker.css', array(), WPCAP_VERSION );
		}
		$url = rest_url( Rest::NS . '/seating/occurrences/' . $occurrence_id );
		$id = 'wpcap-seats-' . $occurrence_id;
		echo '<fieldset class="wpcap-seating" id="' . esc_attr( $id ) . '" data-occurrence="' . esc_attr( (string) $occurrence_id ) . '" data-url="' . esc_attr( esc_url_raw( $url ) ) . '" data-i18n="' . esc_attr( wp_json_encode( self::i18n() ) ) . '">';
		echo '<legend>' . esc_html__( 'Sitzplätze wählen', 'pass-captain' ) . '</legend>';
		echo '<p class="wpcap-seating-status" role="status" aria-live="polite">' . esc_html__( 'Saalplan wird geladen …', 'pass-captain' ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'Für die Sitzplatzwahl wird JavaScript benötigt.', 'pass-captain' ) . '</p></noscript>';
		echo '</fieldset>';
	}
}
