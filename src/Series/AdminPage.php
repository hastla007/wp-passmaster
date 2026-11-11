<?php
/** Admin screen "Serientermine": define a recurrence, preview it and generate occurrences. */
namespace PassCaptain\Series;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-series';
	const NONCE = 'wpcap_series';

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Form fields of a definition (raw, for re-rendering and hidden fields). */
	private static function fields() {
		return array( 'event_id', 'name', 'template_occurrence_id', 'freq', 'interval', 'weekdays', 'month_day', 'nth', 'nth_weekday', 'dates', 'start_date', 'time', 'duration_minutes', 'timezone', 'until', 'count', 'exclusions', 'location', 'capacity', 'sales_start_days_before', 'sales_end_minutes_before', 'seat_version_id' );
	}

	private static function input( array $in ) {
		$out = array();
		foreach ( self::fields() as $field ) {
			if ( ! isset( $in[ $field ] ) ) { continue; }
			$value = $in[ $field ];
			$out[ $field ] = is_array( $value ) ? array_values( array_filter( $value, 'is_scalar' ) ) : ( is_scalar( $value ) ? ( in_array( $field, array( 'dates', 'exclusions' ), true ) ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value ) ) : '' );
		}
		return $out;
	}

	public static function handle_create() {
		Forms::verify( self::NONCE );
		$in = self::input( Forms::post() );
		$result = Generator::create( $in );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			/* translators: 1: created, 2: skipped existing, 3: skipped past */
			$message = sprintf( __( '%1$d Termine angelegt, %2$d bereits vorhandene und %3$d vergangene übersprungen.', 'wp-pass-captain' ), count( $result['created'] ), $result['skipped_existing'], $result['skipped_past'] );
		}
		Forms::finish( $result, $message, self::url( array( 'event_id' => absint( $in['event_id'] ?? 0 ) ) ) );
	}

	public static function render() {
		if ( ! current_user_can( Generator::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin wpcap-series-admin"><h1>' . esc_html__( 'Serientermine', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		$events = Queries::event_options( Queries::event_ids_for( Generator::CAP ) );
		$in = array();
		$preview = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['wpcap_series_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
			check_admin_referer( self::NONCE );
			$in = self::input( Forms::post() );
			$preview = Generator::preview( $in );
		}
		$event_id = absint( $in['event_id'] ?? ( $_GET['event_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-series-event">' . esc_html__( 'Event', 'wp-pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( '' => '—' ) + $events, $event_id, 'wpcap-series-event' );
		echo ' <button class="button">' . esc_html__( 'Auswählen', 'wp-pass-captain' ) . '</button></form>';
		if ( ! $event_id || ! isset( $events[ $event_id ] ) || ! Access::can_access_event( $event_id, Generator::CAP ) ) { echo '</div>'; return; }
		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		}
		self::render_form( $event_id, $in );
		if ( is_array( $preview ) ) { self::render_preview( $preview, $in ); }
		self::render_existing( $event_id );
		echo '</div>';
	}

	private static function value( array $in, $key, $default = '' ) {
		return isset( $in[ $key ] ) && ! is_array( $in[ $key ] ) ? (string) $in[ $key ] : $default;
	}

	private static function weekday_names() {
		return array( 1 => __( 'Montag', 'wp-pass-captain' ), 2 => __( 'Dienstag', 'wp-pass-captain' ), 3 => __( 'Mittwoch', 'wp-pass-captain' ), 4 => __( 'Donnerstag', 'wp-pass-captain' ), 5 => __( 'Freitag', 'wp-pass-captain' ), 6 => __( 'Samstag', 'wp-pass-captain' ), 7 => __( 'Sonntag', 'wp-pass-captain' ) );
	}

	private static function render_form( $event_id, array $in ) {
		echo '<form method="post" action="' . esc_url( self::url( array( 'event_id' => $event_id ) ) ) . '" class="wpcap-series-form">';
		wp_nonce_field( self::NONCE );
		Forms::hidden( 'event_id', $event_id );
		$occurrences = array( '0' => __( '— keine Ticketarten kopieren —', 'wp-pass-captain' ) );
		foreach ( Queries::occurrences_for_event( $event_id ) as $occ ) {
			$count = count( Queries::ticket_types_for_occurrence( $occ->occurrence_id ) );
			/* translators: 1: date, 2: number of ticket types */
			$occurrences[ (int) $occ->occurrence_id ] = sprintf( __( '%1$s (%2$d Ticketarten)', 'wp-pass-captain' ), Forms::datetime( $occ->starts_at, $occ->timezone ), $count );
		}
		$versions = array( '0' => __( '— ohne Saalplan —', 'wp-pass-captain' ) );
		if ( class_exists( '\\PassCaptain\\Seating\\Layouts' ) ) {
			foreach ( \PassCaptain\Seating\Layouts::versions_for_event( $event_id ) as $version ) {
				/* translators: 1: name, 2: version */
				$versions[ (int) $version->version_id ] = sprintf( __( '%1$s – Version %2$d', 'wp-pass-captain' ), $version->name, (int) $version->version );
			}
		}
		$freqs = array( 'weekly' => __( 'Wöchentlich an Wochentagen', 'wp-pass-captain' ), 'daily' => __( 'Täglich', 'wp-pass-captain' ), 'monthly_day' => __( 'Monatlich am Tag X', 'wp-pass-captain' ), 'monthly_nth' => __( 'Monatlich am n-ten Wochentag', 'wp-pass-captain' ), 'dates' => __( 'Datumsliste', 'wp-pass-captain' ) );
		$row = static function ( $id, $label, $callback, $help = '' ) {
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			$callback();
			if ( $help ) { echo '<p class="description">' . esc_html( $help ) . '</p>'; }
			echo '</td></tr>';
		};
		$text = static function ( $id, $name, $value, $type = 'text', $extra = '' ) {
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" ' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- literal attributes.
		};
		echo '<table class="form-table" role="presentation"><tbody>';
		$row( 'wpcap-series-name', __( 'Bezeichnung (optional)', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-name', 'name', self::value( $in, 'name' ), 'text', 'class="regular-text" maxlength="190"' ); } );
		$row( 'wpcap-series-template', __( 'Vorlage für Ticketarten', 'wp-pass-captain' ), static function () use ( $occurrences, $in ) { Forms::select( 'template_occurrence_id', $occurrences, self::value( $in, 'template_occurrence_id', '0' ), 'wpcap-series-template' ); }, __( 'Preis, USt., Kontingent, Höchstmenge und Verkaufszeitraum (relativ zum Beginn) werden kopiert.', 'wp-pass-captain' ) );
		$row( 'wpcap-series-freq', __( 'Wiederholung', 'wp-pass-captain' ), static function () use ( $freqs, $in ) { Forms::select( 'freq', $freqs, self::value( $in, 'freq', 'weekly' ), 'wpcap-series-freq' ); } );
		$row( 'wpcap-series-interval', __( 'Intervall', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-interval', 'interval', self::value( $in, 'interval', '1' ), 'number', 'min="1" max="52"' ); }, __( 'Jeden n-ten Tag / jede n-te Woche / jeden n-ten Monat.', 'wp-pass-captain' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Wochentage', 'wp-pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Wochentage', 'wp-pass-captain' ) . '</legend>';
		$selected_days = array_map( 'intval', (array) ( $in['weekdays'] ?? array() ) );
		foreach ( self::weekday_names() as $num => $name ) {
			echo '<label style="margin-right:1em"><input type="checkbox" name="weekdays[]" value="' . (int) $num . '"' . checked( in_array( $num, $selected_days, true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		$row( 'wpcap-series-month-day', __( 'Tag im Monat', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-month-day', 'month_day', self::value( $in, 'month_day' ), 'number', 'min="1" max="31"' ); }, __( 'Monate ohne diesen Tag (z. B. 31.) werden ausgelassen.', 'wp-pass-captain' ) );
		$row( 'wpcap-series-nth', __( 'n-ter Wochentag', 'wp-pass-captain' ), static function () use ( $in ) {
			Forms::select( 'nth', array( '1' => __( 'erster', 'wp-pass-captain' ), '2' => __( 'zweiter', 'wp-pass-captain' ), '3' => __( 'dritter', 'wp-pass-captain' ), '4' => __( 'vierter', 'wp-pass-captain' ), '5' => __( 'fünfter', 'wp-pass-captain' ), '-1' => __( 'letzter', 'wp-pass-captain' ) ), self::value( $in, 'nth', '1' ), 'wpcap-series-nth' );
			echo ' ';
			Forms::select( 'nth_weekday', self::weekday_names(), self::value( $in, 'nth_weekday', '5' ), '', 'aria-label="' . esc_attr__( 'Wochentag', 'wp-pass-captain' ) . '"' );
		} );
		$row( 'wpcap-series-dates', __( 'Datumsliste', 'wp-pass-captain' ), static function () use ( $in ) { echo '<textarea id="wpcap-series-dates" name="dates" rows="3" cols="40">' . esc_textarea( self::value( $in, 'dates' ) ) . '</textarea>'; }, __( 'Nur für „Datumsliste“: ein Datum je Zeile (TT.MM.JJJJ oder JJJJ-MM-TT).', 'wp-pass-captain' ) );
		$row( 'wpcap-series-start', __( 'Erster Tag', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-start', 'start_date', self::value( $in, 'start_date' ), 'date' ); } );
		$row( 'wpcap-series-time', __( 'Beginn (Ortszeit)', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-time', 'time', self::value( $in, 'time', '19:00' ), 'time', 'required' ); }, __( 'Die Uhrzeit bleibt bei Sommer-/Winterzeitwechsel gleich. Fällt sie in die ausgelassene Stunde, wird sie um eine Stunde verschoben (in der Vorschau markiert).', 'wp-pass-captain' ) );
		$row( 'wpcap-series-duration', __( 'Dauer (Minuten)', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-duration', 'duration_minutes', self::value( $in, 'duration_minutes', '120' ), 'number', 'min="0"' ); } );
		$row( 'wpcap-series-tz', __( 'Zeitzone', 'wp-pass-captain' ), static function () use ( $in ) { Forms::timezone_select( 'timezone', self::value( $in, 'timezone', 'Europe/Vienna' ), 'wpcap-series-tz' ); } );
		$row( 'wpcap-series-until', __( 'Ende am (inklusive)', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-until', 'until', self::value( $in, 'until' ), 'date' ); } );
		$row( 'wpcap-series-count', __( 'oder Anzahl Termine', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-count', 'count', self::value( $in, 'count' ), 'number', 'min="1" max="200"' ); }, __( 'Höchstens 200. Ausnahmen zählen nicht mit.', 'wp-pass-captain' ) );
		$row( 'wpcap-series-exclusions', __( 'Ausnahmen', 'wp-pass-captain' ), static function () use ( $in ) { echo '<textarea id="wpcap-series-exclusions" name="exclusions" rows="3" cols="40">' . esc_textarea( self::value( $in, 'exclusions' ) ) . '</textarea>'; }, __( 'Daten ohne Termin, ein Datum je Zeile.', 'wp-pass-captain' ) );
		$row( 'wpcap-series-location', __( 'Ort (leer = wie Vorlage)', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-location', 'location', self::value( $in, 'location' ), 'text', 'class="regular-text" maxlength="190"' ); } );
		$row( 'wpcap-series-capacity', __( 'Terminkontingent (0 = wie Vorlage)', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-capacity', 'capacity', self::value( $in, 'capacity', '0' ), 'number', 'min="0"' ); } );
		$row( 'wpcap-series-sales-start', __( 'Verkaufsstart: Tage vor Beginn', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-sales-start', 'sales_start_days_before', self::value( $in, 'sales_start_days_before' ), 'number', 'min="0"' ); }, __( 'Leer = relativ wie in der Vorlage.', 'wp-pass-captain' ) );
		$row( 'wpcap-series-sales-end', __( 'Verkaufsende: Minuten vor Beginn', 'wp-pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-sales-end', 'sales_end_minutes_before', self::value( $in, 'sales_end_minutes_before' ), 'number', 'min="0"' ); }, __( 'Z. B. 120 = Verkaufsende 2 Stunden vor Beginn. Leer = relativ wie in der Vorlage.', 'wp-pass-captain' ) );
		$row( 'wpcap-series-seats', __( 'Saalplan', 'wp-pass-captain' ), static function () use ( $versions, $in ) { Forms::select( 'seat_version_id', $versions, self::value( $in, 'seat_version_id', '0' ), 'wpcap-series-seats' ); }, __( 'Bereichszuordnung der Ticketarten wird von der Vorlage übernommen.', 'wp-pass-captain' ) );
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary" name="wpcap_series_preview" value="1">' . esc_html__( 'Vorschau anzeigen', 'wp-pass-captain' ) . '</button></p></form>';
	}

	private static function render_preview( array $preview, array $in ) {
		$items = $preview['items'];
		$new = count( array_filter( $items, static function ( $item ) { return ! $item['exists'] && ! $item['past']; } ) );
		echo '<h2>' . esc_html__( 'Vorschau', 'wp-pass-captain' ) . '</h2>';
		/* translators: 1: total, 2: new */
		echo '<p>' . esc_html( sprintf( __( '%1$d Termine laut Regel, davon %2$d neu.', 'wp-pass-captain' ), count( $items ), $new ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Datum', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Beginn (Ortszeit)', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'UTC', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$status = $item['past'] ? __( 'Vergangen – wird übersprungen', 'wp-pass-captain' ) : ( $item['exists'] ? __( 'Existiert bereits – wird übersprungen', 'wp-pass-captain' ) : __( 'Neu', 'wp-pass-captain' ) );
			if ( $item['shifted'] ) { $status .= ' · ' . __( 'wegen Zeitumstellung verschoben', 'wp-pass-captain' ); }
			echo '<tr><td>' . esc_html( gmdate( 'd.m.Y', strtotime( $item['date'] ) ) ) . ' (' . esc_html( self::weekday_names()[ (int) gmdate( 'N', strtotime( $item['date'] ) ) ] ) . ')</td><td>' . esc_html( $item['local'] . ' (UTC' . $item['offset'] . ')' ) . '</td><td>' . esc_html( $item['starts_at'] ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! $new ) { return; }
		Forms::open_form( 'wpcap_series_create', self::NONCE );
		foreach ( $in as $key => $value ) {
			foreach ( (array) $value as $single ) {
				echo '<input type="hidden" name="' . esc_attr( is_array( $value ) ? $key . '[]' : $key ) . '" value="' . esc_attr( (string) $single ) . '">';
			}
		}
		/* translators: %d: number of occurrences */
		submit_button( sprintf( __( '%d Termine anlegen', 'wp-pass-captain' ), $new ) );
		echo '</form>';
	}

	private static function render_existing( $event_id ) {
		$series = Generator::for_event( $event_id );
		if ( ! $series ) { return; }
		echo '<h2>' . esc_html__( 'Gespeicherte Serien', 'wp-pass-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Bezeichnung', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Regel', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Termine', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Zuletzt ausgeführt', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $row ) {
			$definition = json_decode( $row->definition, true );
			$rule = $definition['rule'] ?? array();
			$summary = ( $rule['freq'] ?? '' ) . ' · ' . ( $rule['time'] ?? '' ) . ' ' . ( $rule['timezone'] ?? '' ) . ( isset( $rule['until'] ) ? ' · ≤ ' . $rule['until'] : '' ) . ( isset( $rule['count'] ) ? ' · ' . $rule['count'] . '×' : '' );
			echo '<tr><td>' . (int) $row->series_id . '</td><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $summary ) . '</td><td>' . (int) $row->occurrences . '</td><td>' . esc_html( Forms::datetime( $row->updated_at, 'UTC' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
