<?php
/**
 * Admin screen "Serientermine": define a recurrence, preview it and generate occurrences; edit a
 * stored series with a preview of the effects (A128); price rules, pools and the series' change
 * history (A114).
 */
namespace PassCaptain\Series;

use PassCaptain\Access;
use PassCaptain\Admin\ChangeHistory;
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
			$message = sprintf( __( '%1$d Termine angelegt, %2$d bereits vorhandene und %3$d vergangene übersprungen.', 'pass-captain' ), count( $result['created'] ), $result['skipped_existing'], $result['skipped_past'] );
		}
		Forms::finish( $result, $message, self::url( array( 'event_id' => absint( $in['event_id'] ?? 0 ) ) ) );
	}

	/** Edit options (A128): apply to sold-out / cancelled occurrences only when chosen explicitly. */
	private static function edit_options( array $in ) {
		return array( 'include_sold_out' => Forms::flag( $in, 'include_sold_out' ), 'include_cancelled' => Forms::flag( $in, 'include_cancelled' ) );
	}

	public static function handle_update() {
		Forms::verify( self::NONCE );
		$post = Forms::post();
		$series_id = Forms::int( $post, 'series_id' );
		$in = self::input( $post );
		$result = Generator::update( $series_id, $in, self::edit_options( $post ), is_scalar( $post['fingerprint'] ?? null ) ? (string) $post['fingerprint'] : '' );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			/* translators: 1: added, 2: changed, 3: deleted, 4: kept and flagged, 5: skipped */
			$message = sprintf( __( 'Serie geändert: %1$d Termine hinzugefügt, %2$d geändert, %3$d entfernt, %4$d mit Verkäufen behalten und markiert, %5$d übersprungen.', 'pass-captain' ), count( $result['added'] ), count( $result['changed'] ), count( $result['deleted'] ), count( $result['flagged'] ), $result['skipped'] );
		}
		Forms::finish( $result, $message, self::rules_url( $series_id ), self::url( array( 'event_id' => self::series_event( $series_id ), 'series_id' => $series_id, 'edit' => 1 ) ) . '#wpcap-series-edit' );
	}

	public static function render() {
		if ( ! current_user_can( Generator::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin wpcap-series-admin"><h1>' . esc_html__( 'Serientermine', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$events = Queries::event_options( Queries::event_ids_for( Generator::CAP ) );
		$in = array();
		$preview = null;
		$plan = null;
		$edit_id = 0;
		$options = array();
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_series_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
			check_admin_referer( self::NONCE );
			$in = self::input( Forms::post() );
			$preview = Generator::preview( $in );
		} elseif ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_series_edit_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
			check_admin_referer( self::NONCE );
			$post = Forms::post();
			$edit_id = Forms::int( $post, 'series_id' );
			$in = self::input( $post );
			$in['event_id'] = (string) self::series_event( $edit_id );
			$options = self::edit_options( $post );
			$plan = Generator::plan_update( $edit_id, $in, $options );
		} elseif ( Forms::query_arg( 'edit' ) && absint( $_GET['series_id'] ?? 0 ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$series = Pricing::series( absint( $_GET['series_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $series ) {
				$edit_id = (int) $series->series_id;
				$definition = json_decode( (string) $series->definition, true );
				$in = Generator::definition_input( is_array( $definition ) ? $definition : array() );
				$in['event_id'] = (string) (int) $series->event_id;
			}
		}
		$event_id = absint( $in['event_id'] ?? ( $_GET['event_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-series-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( '' => '—' ) + $events, $event_id, 'wpcap-series-event' );
		echo ' <button class="button">' . esc_html__( 'Auswählen', 'pass-captain' ) . '</button></form>';
		if ( ! $event_id || ! isset( $events[ $event_id ] ) || ! Access::can_access_event( $event_id, Generator::CAP ) ) { echo '</div>'; return; }
		foreach ( array( $preview, $plan ) as $maybe_error ) {
			if ( is_wp_error( $maybe_error ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $maybe_error->get_error_message() ) . '</p></div>'; }
		}
		if ( $edit_id ) {
			/* translators: %d: series number */
			echo '<h2 id="wpcap-series-edit">' . esc_html( sprintf( __( 'Serie #%d bearbeiten', 'pass-captain' ), $edit_id ) ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Änderungen gelten nur für künftige Termine. Vergangene Termine bleiben unverändert, ausverkaufte und abgesagte nur, wenn du sie unten ausdrücklich einbeziehst. Termine mit verkauften Tickets werden nie gelöscht: Fallen sie aus der Regel, bleiben sie bestehen und werden markiert – absagen kannst du sie über „Termin absagen“. Vor dem Speichern siehst du eine Vorschau.', 'pass-captain' ) . '</p>';
			echo '<p><a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => $edit_id ) ) . '#wpcap-series-rules' ) . '">' . esc_html__( 'Bearbeiten abbrechen', 'pass-captain' ) . '</a></p>';
			self::render_form( $event_id, $in, $edit_id, $options );
			if ( is_array( $plan ) ) { self::render_edit_preview( $plan, $in ); }
		} else {
			self::render_form( $event_id, $in );
			if ( is_array( $preview ) ) { self::render_preview( $preview, $in ); }
		}
		self::render_existing( $event_id );
		$series_id = absint( $_GET['series_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $series_id ) { self::render_rules( $event_id, $series_id ); }
		echo '</div>';
	}

	private static function value( array $in, $key, $default = '' ) {
		return isset( $in[ $key ] ) && ! is_array( $in[ $key ] ) ? (string) $in[ $key ] : $default;
	}

	private static function weekday_names() {
		return array( 1 => __( 'Montag', 'pass-captain' ), 2 => __( 'Dienstag', 'pass-captain' ), 3 => __( 'Mittwoch', 'pass-captain' ), 4 => __( 'Donnerstag', 'pass-captain' ), 5 => __( 'Freitag', 'pass-captain' ), 6 => __( 'Samstag', 'pass-captain' ), 7 => __( 'Sonntag', 'pass-captain' ) );
	}

	private static function render_form( $event_id, array $in, $series_id = 0, array $options = array() ) {
		$action_args = $series_id ? array( 'event_id' => $event_id, 'series_id' => $series_id, 'edit' => 1 ) : array( 'event_id' => $event_id );
		echo '<form method="post" action="' . esc_url( self::url( $action_args ) . ( $series_id ? '#wpcap-series-edit-preview' : '' ) ) . '" class="wpcap-series-form">';
		wp_nonce_field( self::NONCE );
		Forms::hidden( 'event_id', $event_id );
		if ( $series_id ) { Forms::hidden( 'series_id', (int) $series_id ); }
		$occurrences = array( '0' => __( '— keine Ticketarten kopieren —', 'pass-captain' ) );
		foreach ( Queries::occurrences_for_event( $event_id ) as $occ ) {
			$count = count( Queries::ticket_types_for_occurrence( $occ->occurrence_id ) );
			/* translators: 1: date, 2: number of ticket types */
			$occurrences[ (int) $occ->occurrence_id ] = sprintf( __( '%1$s (%2$d Ticketarten)', 'pass-captain' ), Forms::datetime( $occ->starts_at, $occ->timezone ), $count );
		}
		$versions = array( '0' => __( '— ohne Saalplan —', 'pass-captain' ) );
		if ( class_exists( '\\PassCaptain\\Seating\\Layouts' ) ) {
			foreach ( \PassCaptain\Seating\Layouts::versions_for_event( $event_id ) as $version ) {
				/* translators: 1: name, 2: version */
				$versions[ (int) $version->version_id ] = sprintf( __( '%1$s – Version %2$d', 'pass-captain' ), $version->name, (int) $version->version );
			}
		}
		$freqs = array( 'weekly' => __( 'Wöchentlich an Wochentagen', 'pass-captain' ), 'daily' => __( 'Täglich', 'pass-captain' ), 'monthly_day' => __( 'Monatlich am Tag X', 'pass-captain' ), 'monthly_nth' => __( 'Monatlich am n-ten Wochentag', 'pass-captain' ), 'dates' => __( 'Datumsliste', 'pass-captain' ) );
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
		$row( 'wpcap-series-name', __( 'Bezeichnung (optional)', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-name', 'name', self::value( $in, 'name' ), 'text', 'class="regular-text" maxlength="190"' ); } );
		$row( 'wpcap-series-template', __( 'Vorlage für Ticketarten', 'pass-captain' ), static function () use ( $occurrences, $in ) { Forms::select( 'template_occurrence_id', $occurrences, self::value( $in, 'template_occurrence_id', '0' ), 'wpcap-series-template' ); }, __( 'Preis, USt., Kontingent, Höchstmenge und Verkaufszeitraum (relativ zum Beginn) werden kopiert.', 'pass-captain' ) );
		$row( 'wpcap-series-freq', __( 'Wiederholung', 'pass-captain' ), static function () use ( $freqs, $in ) { Forms::select( 'freq', $freqs, self::value( $in, 'freq', 'weekly' ), 'wpcap-series-freq' ); } );
		$row( 'wpcap-series-interval', __( 'Intervall', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-interval', 'interval', self::value( $in, 'interval', '1' ), 'number', 'min="1" max="52"' ); }, __( 'Jeden n-ten Tag / jede n-te Woche / jeden n-ten Monat.', 'pass-captain' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</legend>';
		$selected_days = array_map( 'intval', (array) ( $in['weekdays'] ?? array() ) );
		foreach ( self::weekday_names() as $num => $name ) {
			echo '<label style="margin-right:1em"><input type="checkbox" name="weekdays[]" value="' . (int) $num . '"' . checked( in_array( $num, $selected_days, true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		$row( 'wpcap-series-month-day', __( 'Tag im Monat', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-month-day', 'month_day', self::value( $in, 'month_day' ), 'number', 'min="1" max="31"' ); }, __( 'Monate ohne diesen Tag (z. B. 31.) werden ausgelassen.', 'pass-captain' ) );
		$row( 'wpcap-series-nth', __( 'n-ter Wochentag', 'pass-captain' ), static function () use ( $in ) {
			Forms::select( 'nth', array( '1' => __( 'erster', 'pass-captain' ), '2' => __( 'zweiter', 'pass-captain' ), '3' => __( 'dritter', 'pass-captain' ), '4' => __( 'vierter', 'pass-captain' ), '5' => __( 'fünfter', 'pass-captain' ), '-1' => __( 'letzter', 'pass-captain' ) ), self::value( $in, 'nth', '1' ), 'wpcap-series-nth' );
			echo ' ';
			Forms::select( 'nth_weekday', self::weekday_names(), self::value( $in, 'nth_weekday', '5' ), '', 'aria-label="' . esc_attr__( 'Wochentag', 'pass-captain' ) . '"' );
		} );
		$row( 'wpcap-series-dates', __( 'Datumsliste', 'pass-captain' ), static function () use ( $in ) { echo '<textarea id="wpcap-series-dates" name="dates" rows="3" cols="40">' . esc_textarea( self::value( $in, 'dates' ) ) . '</textarea>'; }, __( 'Nur für „Datumsliste“: ein Datum je Zeile (TT.MM.JJJJ oder JJJJ-MM-TT).', 'pass-captain' ) );
		$row( 'wpcap-series-start', __( 'Erster Tag', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-start', 'start_date', self::value( $in, 'start_date' ), 'date' ); } );
		$row( 'wpcap-series-time', __( 'Beginn (Ortszeit)', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-time', 'time', self::value( $in, 'time', '19:00' ), 'time', 'required' ); }, __( 'Die Uhrzeit bleibt bei Sommer-/Winterzeitwechsel gleich. Fällt sie in die ausgelassene Stunde, wird sie um eine Stunde verschoben (in der Vorschau markiert).', 'pass-captain' ) );
		$row( 'wpcap-series-duration', __( 'Dauer (Minuten)', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-duration', 'duration_minutes', self::value( $in, 'duration_minutes', '120' ), 'number', 'min="0"' ); } );
		$row( 'wpcap-series-tz', __( 'Zeitzone', 'pass-captain' ), static function () use ( $in ) { Forms::timezone_select( 'timezone', self::value( $in, 'timezone', 'Europe/Vienna' ), 'wpcap-series-tz' ); } );
		$row( 'wpcap-series-until', __( 'Ende am (inklusive)', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-until', 'until', self::value( $in, 'until' ), 'date' ); } );
		$row( 'wpcap-series-count', __( 'oder Anzahl Termine', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-count', 'count', self::value( $in, 'count' ), 'number', 'min="1" max="200"' ); }, __( 'Höchstens 200. Ausnahmen zählen nicht mit.', 'pass-captain' ) );
		$row( 'wpcap-series-exclusions', __( 'Ausnahmen', 'pass-captain' ), static function () use ( $in ) { echo '<textarea id="wpcap-series-exclusions" name="exclusions" rows="3" cols="40">' . esc_textarea( self::value( $in, 'exclusions' ) ) . '</textarea>'; }, __( 'Daten ohne Termin, ein Datum je Zeile.', 'pass-captain' ) );
		$row( 'wpcap-series-location', __( 'Ort (leer = wie Vorlage)', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-location', 'location', self::value( $in, 'location' ), 'text', 'class="regular-text" maxlength="190"' ); } );
		$row( 'wpcap-series-capacity', __( 'Terminkontingent (0 = wie Vorlage)', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-capacity', 'capacity', self::value( $in, 'capacity', '0' ), 'number', 'min="0"' ); } );
		$row( 'wpcap-series-sales-start', __( 'Verkaufsstart: Tage vor Beginn', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-sales-start', 'sales_start_days_before', self::value( $in, 'sales_start_days_before' ), 'number', 'min="0"' ); }, __( 'Leer = relativ wie in der Vorlage.', 'pass-captain' ) );
		$row( 'wpcap-series-sales-end', __( 'Verkaufsende: Minuten vor Beginn', 'pass-captain' ), static function () use ( $text, $in ) { $text( 'wpcap-series-sales-end', 'sales_end_minutes_before', self::value( $in, 'sales_end_minutes_before' ), 'number', 'min="0"' ); }, __( 'Z. B. 120 = Verkaufsende 2 Stunden vor Beginn. Leer = relativ wie in der Vorlage.', 'pass-captain' ) );
		$row( 'wpcap-series-seats', __( 'Saalplan', 'pass-captain' ), static function () use ( $versions, $in ) { Forms::select( 'seat_version_id', $versions, self::value( $in, 'seat_version_id', '0' ), 'wpcap-series-seats' ); }, __( 'Bereichszuordnung der Ticketarten wird von der Vorlage übernommen.', 'pass-captain' ) );
		if ( $series_id ) {
			echo '<tr><th scope="row">' . esc_html__( 'Ausnahmen beim Übernehmen', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Ausnahmen beim Übernehmen', 'pass-captain' ) . '</legend>';
			echo '<label><input type="checkbox" name="include_sold_out" value="1"' . checked( ! empty( $options['include_sold_out'] ), true, false ) . '> ' . esc_html__( 'Ausverkaufte Termine einbeziehen', 'pass-captain' ) . '</label><br>';
			echo '<label><input type="checkbox" name="include_cancelled" value="1"' . checked( ! empty( $options['include_cancelled'] ), true, false ) . '> ' . esc_html__( 'Abgesagte Termine einbeziehen (sie bleiben abgesagt, nur ihre Daten werden angepasst)', 'pass-captain' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Ohne Häkchen bleiben ausverkaufte und abgesagte Termine unverändert.', 'pass-captain' ) . '</p></fieldset></td></tr>';
		}
		echo '</tbody></table>';
		if ( $series_id ) {
			echo '<p class="submit"><button type="submit" class="button button-primary" name="wpcap_series_edit_preview" value="1">' . esc_html__( 'Auswirkungen prüfen', 'pass-captain' ) . '</button></p></form>';
			return;
		}
		echo '<p class="submit"><button type="submit" class="button button-primary" name="wpcap_series_preview" value="1">' . esc_html__( 'Vorschau anzeigen', 'pass-captain' ) . '</button></p></form>';
	}

	/** Labels of the planned actions of a series change. */
	private static function edit_action_labels() {
		return array(
			'add' => __( 'Neu – wird angelegt', 'pass-captain' ),
			'change' => __( 'Wird geändert', 'pass-captain' ),
			'delete' => __( 'Nicht mehr in der Regel – wird gelöscht (keine Verkäufe, keine Verknüpfungen)', 'pass-captain' ),
			'flag' => __( 'Nicht mehr in der Regel – bleibt bestehen und wird markiert', 'pass-captain' ),
			'unflag' => __( 'Wieder in der Regel – Markierung wird aufgehoben', 'pass-captain' ),
			'skip' => __( 'Übersprungen', 'pass-captain' ),
			'conflict' => __( 'Konflikt – wird nicht geändert', 'pass-captain' ),
		);
	}

	/** Explanation of a planned row (reason, changes old → new, side effects). */
	public static function edit_row_lines( array $row, $timezone ) {
		$lines = array();
		switch ( $row['reason'] ?? '' ) {
			case 'cancelled':
				$lines[] = __( 'Abgesagt – nur mit „Abgesagte Termine einbeziehen“.', 'pass-captain' );
				break;
			case 'sold_out':
				$lines[] = __( 'Ausverkauft – nur mit „Ausverkaufte Termine einbeziehen“.', 'pass-captain' );
				break;
			case 'capacity_below_sold':
				/* translators: %d: sold tickets */
				$lines[] = sprintf( _n( 'Das neue Kontingent liegt unter dem bereits verkauften %d Ticket.', 'Das neue Kontingent liegt unter den bereits verkauften %d Tickets.', (int) ( $row['sold'] ?? 0 ), 'pass-captain' ), (int) ( $row['sold'] ?? 0 ) );
				break;
			case 'exists':
				$lines[] = __( 'Zu dieser Zeit gibt es bereits einen Termin dieses Events.', 'pass-captain' );
				break;
			case 'cancelled_outside':
				$lines[] = __( 'Abgesagt und nicht mehr in der Regel – bleibt unverändert.', 'pass-captain' );
				break;
			case 'sold':
				$sold = max( (int) ( $row['sold'] ?? 0 ), (int) ( $row['issued'] ?? 0 ) );
				/* translators: %d: sold tickets */
				$lines[] = sprintf( _n( '%d verkauftes Ticket. Der Termin wird nicht gelöscht; absagen kannst du ihn über „Termin absagen“ (mit Erstattung und Benachrichtigung).', '%d verkaufte Tickets. Der Termin wird nicht gelöscht; absagen kannst du ihn über „Termin absagen“ (mit Erstattung und Benachrichtigung).', $sold, 'pass-captain' ), $sold );
				break;
			case 'linked':
				/* translators: %s: list of data tables */
				$lines[] = sprintf( __( 'Verknüpfte Daten vorhanden (%s) – der Termin wird nicht gelöscht.', 'pass-captain' ), implode( ', ', (array) ( $row['references'] ?? array() ) ) );
				break;
		}
		$labels = ChangeHistory::field_labels();
		foreach ( $row['changes'] as $field => $pair ) {
			if ( 'add' === $row['action'] ) {
				/* translators: 1: field, 2: value */
				$lines[] = sprintf( __( '%1$s: %2$s', 'pass-captain' ), $labels[ $field ] ?? $field, ChangeHistory::format_value( $field, $pair[1], array( 'timezone' => $timezone ) ) );
				continue;
			}
			/* translators: 1: field, 2: old value, 3: new value */
			$lines[] = sprintf( __( '%1$s: %2$s → %3$s', 'pass-captain' ), $labels[ $field ] ?? $field, ChangeHistory::format_value( $field, $pair[0], array( 'timezone' => $timezone ) ), ChangeHistory::format_value( $field, $pair[1], array( 'timezone' => $timezone ) ) );
		}
		if ( in_array( $row['action'], array( 'change', 'skip', 'conflict' ), true ) ) {
			if ( ! empty( $row['sales'] ) ) { $lines[] = __( 'Verkaufszeitraum der Ticketarten wird angepasst.', 'pass-captain' ); }
			if ( ! empty( $row['seat'] ) ) { $lines[] = __( 'Saalplan-Verknüpfung wird umgestellt (verkaufte Plätze bleiben geschützt).', 'pass-captain' ); }
		}
		if ( ! empty( $row['reschedule'] ) ) {
			/* translators: %d: issued tickets */
			$lines[] = sprintf( _n( 'Terminverschiebung: Die Inhaber von %d Ticket werden benachrichtigt.', 'Terminverschiebung: Die Inhaber von %d Tickets werden benachrichtigt.', (int) $row['issued'], 'pass-captain' ), (int) $row['issued'] );
		}
		if ( in_array( $row['action'], array( 'add', 'change' ), true ) ) {
			if ( 'gap' === ( $row['item']['dst'] ?? '' ) ) { $lines[] = __( 'wegen Zeitumstellung verschoben (die Uhrzeit gibt es an diesem Tag nicht)', 'pass-captain' ); }
			if ( 'ambiguous' === ( $row['item']['dst'] ?? '' ) ) { $lines[] = __( 'Uhrzeit wegen Zeitumstellung doppelt – erste (Sommerzeit) verwendet', 'pass-captain' ); }
		}
		return $lines;
	}

	/** A128: preview of a series change and the confirmation form. */
	private static function render_edit_preview( array $plan, array $in ) {
		$timezone = (string) $plan['definition']['rule']['timezone'];
		$counts = $plan['counts'];
		echo '<h2 id="wpcap-series-edit-preview">' . esc_html__( 'Vorschau der Änderungen', 'pass-captain' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Seriendefinition (alt → neu)', 'pass-captain' ) . '</h3>';
		if ( $plan['changes'] ) {
			$labels = ChangeHistory::field_labels();
			echo '<ul style="list-style:disc;margin-left:2em">';
			foreach ( $plan['changes'] as $field => $pair ) {
				/* translators: 1: field, 2: old value, 3: new value */
				echo '<li>' . esc_html( sprintf( __( '%1$s: %2$s → %3$s', 'pass-captain' ), $labels[ $field ] ?? $field, ChangeHistory::format_value( $field, $pair[0], array( 'timezone' => $timezone ) ), ChangeHistory::format_value( $field, $pair[1], array( 'timezone' => $timezone ) ) ) ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'Die Definition ist unverändert.', 'pass-captain' ) . '</p>';
		}
		/* translators: 1: added, 2: changed, 3: deleted, 4: kept and flagged, 5: skipped or conflicts, 6: past, 7: unchanged */
		echo '<p><strong>' . esc_html( sprintf( __( 'Auswirkung: %1$d neue, %2$d geänderte und %3$d gelöschte Termine; %4$d Termine mit Verkäufen oder Verknüpfungen bleiben bestehen und werden markiert; %5$d übersprungen. %6$d vergangene und %7$d unveränderte Termine bleiben unberührt.', 'pass-captain' ), $counts['add'], $counts['change'], $counts['delete'], $counts['flag'], $counts['skip'] + $counts['conflict'], $counts['past'], $counts['unchanged'] ) ) . '</strong></p>';
		if ( $plan['rows'] ) {
			$actions = self::edit_action_labels();
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Datum', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Termin', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Details', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $plan['rows'] as $row ) {
				$lines = self::edit_row_lines( $row, $timezone );
				$when = strtotime( $row['date'] );
				echo '<tr><td>' . esc_html( gmdate( 'd.m.Y', $when ) . ' (' . self::weekday_names()[ (int) gmdate( 'N', $when ) ] . ')' ) . '</td><td>';
				if ( $row['occurrence_id'] ) {
					echo '<a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\EventsPage::SLUG, array( 'view' => 'occurrence', 'occurrence_id' => (int) $row['occurrence_id'] ) ) ) . '">#' . (int) $row['occurrence_id'] . '</a>';
				} else {
					echo '—';
				}
				echo '</td><td>' . esc_html( $actions[ $row['action'] ] ?? $row['action'] ) . '</td><td>';
				echo $lines ? '<ul style="margin:0">' . implode( '', array_map( static function ( $line ) { return '<li>' . esc_html( $line ) . '</li>'; }, $lines ) ) . '</ul>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per line.
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		foreach ( $plan['dst_notes'] as $note ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $note ) . '</p></div>';
		}
		if ( ! $plan['changes'] && ! array_sum( array_intersect_key( $counts, array_flip( array( 'add', 'change', 'delete', 'flag', 'unflag' ) ) ) ) ) { return; }
		Forms::open_form( 'wpcap_series_update', self::NONCE );
		Forms::hidden( 'series_id', (int) $plan['series']->series_id );
		Forms::hidden( 'fingerprint', $plan['fingerprint'] );
		foreach ( $plan['options'] as $key => $on ) {
			if ( $on ) { Forms::hidden( $key, '1' ); }
		}
		foreach ( $in as $key => $value ) {
			foreach ( (array) $value as $single ) {
				echo '<input type="hidden" name="' . esc_attr( is_array( $value ) ? $key . '[]' : $key ) . '" value="' . esc_attr( (string) $single ) . '">';
			}
		}
		submit_button( __( 'Änderungen übernehmen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function render_preview( array $preview, array $in ) {
		$items = $preview['items'];
		$new = count( array_filter( $items, static function ( $item ) { return ! $item['exists'] && ! $item['past']; } ) );
		echo '<h2>' . esc_html__( 'Vorschau', 'pass-captain' ) . '</h2>';
		/* translators: 1: total, 2: new */
		echo '<p>' . esc_html( sprintf( __( '%1$d Termine laut Regel, davon %2$d neu.', 'pass-captain' ), count( $items ), $new ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Datum', 'pass-captain' ) . '</th><th>' . esc_html__( 'Beginn (Ortszeit)', 'pass-captain' ) . '</th><th>' . esc_html__( 'UTC', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$status = $item['past'] ? __( 'Vergangen – wird übersprungen', 'pass-captain' ) : ( $item['exists'] ? __( 'Existiert bereits – wird übersprungen', 'pass-captain' ) : __( 'Neu', 'pass-captain' ) );
			if ( $item['shifted'] ) { $status .= ' · ' . __( 'wegen Zeitumstellung verschoben (die Uhrzeit gibt es an diesem Tag nicht)', 'pass-captain' ); }
			if ( 'ambiguous' === ( $item['dst'] ?? '' ) ) { $status .= ' · ' . __( 'Uhrzeit wegen Zeitumstellung doppelt – erste (Sommerzeit) verwendet', 'pass-captain' ); }
			echo '<tr><td>' . esc_html( gmdate( 'd.m.Y', strtotime( $item['date'] ) ) ) . ' (' . esc_html( self::weekday_names()[ (int) gmdate( 'N', strtotime( $item['date'] ) ) ] ) . ')</td><td>' . esc_html( $item['local'] . ' (UTC' . $item['offset'] . ')' ) . '</td><td>' . esc_html( $item['starts_at'] ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
		}
		echo '</tbody></table>';
		foreach ( Generator::dst_notes( $items, (string) ( $preview['definition']['rule']['timezone'] ?? '' ) ) as $note ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $note ) . '</p></div>';
		}
		if ( ! $new ) { return; }
		Forms::open_form( 'wpcap_series_create', self::NONCE );
		foreach ( $in as $key => $value ) {
			foreach ( (array) $value as $single ) {
				echo '<input type="hidden" name="' . esc_attr( is_array( $value ) ? $key . '[]' : $key ) . '" value="' . esc_attr( (string) $single ) . '">';
			}
		}
		/* translators: %d: number of occurrences */
		submit_button( sprintf( __( '%d Termine anlegen', 'pass-captain' ), $new ) );
		echo '</form>';
	}

	private static function render_existing( $event_id ) {
		$series = Generator::for_event( $event_id );
		if ( ! $series ) { return; }
		echo '<h2>' . esc_html__( 'Gespeicherte Serien', 'pass-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Regel', 'pass-captain' ) . '</th><th>' . esc_html__( 'Termine', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zuletzt ausgeführt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $row ) {
			$definition = json_decode( $row->definition, true );
			$rule = $definition['rule'] ?? array();
			$summary = ( $rule['freq'] ?? '' ) . ' · ' . ( $rule['time'] ?? '' ) . ' ' . ( $rule['timezone'] ?? '' ) . ( isset( $rule['until'] ) ? ' · ≤ ' . $rule['until'] : '' ) . ( isset( $rule['count'] ) ? ' · ' . $rule['count'] . '×' : '' );
			echo '<tr><td>' . (int) $row->series_id . '</td><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $summary ) . '</td><td>' . (int) $row->occurrences . '</td><td>' . esc_html( Forms::datetime( $row->updated_at, 'UTC' ) ) . '</td><td><a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => (int) $row->series_id ) ) . '#wpcap-series-rules' ) . '">' . esc_html__( 'Preisstufen, Kontingente und Verlauf', 'pass-captain' ) . '</a> | <a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => (int) $row->series_id, 'edit' => 1 ) ) . '#wpcap-series-edit' ) . '">' . esc_html__( 'Serie bearbeiten', 'pass-captain' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------------------------------
	 * Price tiers and quota pools.
	 * ------------------------------------------------------------------------------------------- */

	private static function rules_url( $series_id ) {
		global $wpdb;
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_series WHERE series_id=%d", absint( $series_id ) ) );
		return self::url( array( 'event_id' => $event_id, 'series_id' => absint( $series_id ) ) ) . '#wpcap-series-rules';
	}

	public static function handle_rule_save() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$series_id = Forms::int( $in, 'series_id' );
		$result = Pricing::create( $series_id, $in );
		Forms::finish( $result, __( 'Preisregel gespeichert.', 'pass-captain' ), self::rules_url( $series_id ) );
	}

	public static function handle_rule_update() {
		Forms::require_any_cap( array( Generator::CAP ) );
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$series_id = Forms::int( $in, 'series_id' );
		$result = Pricing::update( Forms::int( $in, 'rule_id' ), $in );
		Forms::finish( $result, __( 'Preisregel geändert.', 'pass-captain' ), self::rules_url( $series_id ), self::url( array( 'event_id' => self::series_event( $series_id ), 'series_id' => $series_id, 'edit_rule' => Forms::int( $in, 'rule_id' ) ) ) . '#wpcap-rule-form' );
	}

	private static function series_event( $series_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_series WHERE series_id=%d", absint( $series_id ) ) );
	}

	public static function handle_rule_delete() {
		Forms::require_any_cap( array( Generator::CAP ) );
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$result = Pricing::delete( Forms::int( $in, 'rule_id' ) );
		Forms::finish( $result, __( 'Preisregel entfernt.', 'pass-captain' ), self::rules_url( Forms::int( $in, 'series_id' ) ) );
	}

	public static function handle_pool_save() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$series_id = Forms::int( $in, 'series_id' );
		$result = Pools::create( $series_id, $in );
		Forms::finish( $result, __( 'Kontingent gespeichert.', 'pass-captain' ), self::rules_url( $series_id ) );
	}

	public static function handle_pool_update() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$result = Pools::update_capacity( Forms::int( $in, 'pool_id' ), Forms::int( $in, 'capacity' ) );
		Forms::finish( $result, __( 'Kontingent geändert.', 'pass-captain' ), self::rules_url( Forms::int( $in, 'series_id' ) ) );
	}

	public static function handle_pool_delete() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$result = Pools::delete( Forms::int( $in, 'pool_id' ) );
		Forms::finish( $result, __( 'Kontingent entfernt.', 'pass-captain' ), self::rules_url( Forms::int( $in, 'series_id' ) ) );
	}

	/** Human-readable condition of a rule. */
	private static function rule_condition( $rule, $timezone ) {
		switch ( $rule->kind ) {
			case 'early_bird':
				/* translators: %s: local date/time */
				return sprintf( __( 'Buchung vor %s', 'pass-captain' ), Forms::datetime( $rule->until_at, $timezone ) );
			case 'last_minute':
				/* translators: %d: hours */
				return sprintf( _n( 'ab %d Stunde vor Beginn', 'ab %d Stunden vor Beginn', (int) $rule->hours_before, 'pass-captain' ), (int) $rule->hours_before);
			case 'demand':
				/* translators: %d: percent */
				return sprintf( __( 'ab %d %% verkauft', 'pass-captain' ), (int) $rule->sold_pct );
		}
		$names = self::weekday_names();
		$days = '' === (string) $rule->weekdays ? array() : array_map( static function ( $d ) use ( $names ) { return $names[ (int) $d ] ?? $d; }, explode( ',', (string) $rule->weekdays ) );
		$parts = array();
		if ( $days ) { $parts[] = implode( ', ', $days ); }
		if ( '' !== (string) $rule->time_from ) { $parts[] = $rule->time_from . '–' . $rule->time_to . ' ' . __( '(Ortszeit Beginn)', 'pass-captain' ); }
		return implode( ' · ', $parts );
	}

	private static function rule_price( $rule, $currency ) {
		if ( 'percent' === $rule->price_mode ) {
			return ( (int) $rule->amount > 0 ? '+' : '−' ) . Forms::percent_input( abs( (int) $rule->amount ) ) . ' %';
		}
		return Forms::money( (int) $rule->amount, $currency );
	}

	private static function render_rules( $event_id, $series_id ) {
		$series = Pricing::series( $series_id );
		if ( ! $series || (int) $series->event_id !== (int) $event_id ) { return; }
		$timezone = $series->timezone;
		$currency = $series->currency;
		$kinds = Pricing::kind_labels();
		$type_names = array( '' => __( 'Alle Ticketarten', 'pass-captain' ) );
		foreach ( Pricing::type_names( $series_id ) as $name ) { $type_names[ $name ] = $name; }
		/* translators: %s: series name or number */
		echo '<h2 id="wpcap-series-rules">' . esc_html( sprintf( __( 'Preisstufen und Kontingente: %s', 'pass-captain' ), '' !== (string) $series->name ? $series->name : '#' . (int) $series->series_id ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Preisregeln gelten serverseitig für alle Termine dieser Serie (Online-Checkout und Kasse). Von mehreren passenden Regeln gewinnt die mit der höchsten Priorität. WooCommerce verwendet weiterhin seine eigenen Produktpreise; Kontingente gelten dort trotzdem.', 'pass-captain' ) . '</p>';

		echo '<p><a class="button" href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => (int) $series_id, 'edit' => 1 ) ) . '#wpcap-series-edit' ) . '">' . esc_html__( 'Seriendefinition bearbeiten', 'pass-captain' ) . '</a> <a href="#wpcap-series-history">' . esc_html__( 'Änderungsverlauf', 'pass-captain' ) . '</a></p>';
		self::render_flagged( $series_id );

		echo '<h3>' . esc_html__( 'Preisregeln', 'pass-captain' ) . '</h3>';
		$rules = Pricing::for_series( $series_id );
		$edit_rule = null;
		$edit_rule_id = absint( $_GET['edit_rule'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $rules ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Art', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bedingung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Preis', 'pass-captain' ) . '</th><th>' . esc_html__( 'Priorität', 'pass-captain' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				if ( (int) $rule->rule_id === $edit_rule_id ) { $edit_rule = $rule; }
				$rule_name = '' !== (string) $rule->label ? $rule->label : ( $kinds[ $rule->kind ] ?? '' );
				echo '<tr><td>' . esc_html( $rule->label ) . '</td><td>' . esc_html( $kinds[ $rule->kind ] ?? $rule->kind ) . '</td><td>' . esc_html( '' !== (string) $rule->type_name ? $rule->type_name : __( 'Alle', 'pass-captain' ) ) . '</td><td>' . esc_html( self::rule_condition( $rule, $timezone ) ) . '</td><td>' . esc_html( self::rule_price( $rule, $currency ) ) . '</td><td>' . (int) $rule->priority . '</td><td>';
				/* translators: %s: rule label or kind */
				echo '<a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => (int) $series_id, 'edit_rule' => (int) $rule->rule_id ) ) . '#wpcap-rule-form' ) . '" aria-label="' . esc_attr( sprintf( __( 'Preisregel „%s“ bearbeiten', 'pass-captain' ), $rule_name ) ) . '">' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</a> ';
				Forms::open_form( 'wpcap_series_rule_delete', self::NONCE, 'style="display:inline"' );
				Forms::hidden( 'rule_id', (int) $rule->rule_id );
				Forms::hidden( 'series_id', (int) $series_id );
				/* translators: %s: rule label or kind */
				echo '<button type="submit" class="button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Preisregel „%s“ entfernen', 'pass-captain' ), $rule_name ) ) . '">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch keine Preisregeln – es gilt der Preis der Ticketart.', 'pass-captain' ) . '</p>';
		}

		// A128: the same form edits an existing rule (prefilled); the change is logged with old → new.
		$v = array( 'label' => '', 'kind' => 'early_bird', 'type_name' => '', 'price_mode' => 'fixed', 'amount' => '', 'until' => '', 'hours_before' => '', 'sold_pct' => '', 'weekdays' => array(), 'time_from' => '', 'time_to' => '', 'priority' => '' );
		if ( $edit_rule ) {
			$v = array(
				'label' => (string) $edit_rule->label,
				'kind' => (string) $edit_rule->kind,
				'type_name' => (string) $edit_rule->type_name,
				'price_mode' => (string) $edit_rule->price_mode,
				'amount' => 'percent' === $edit_rule->price_mode ? ( (int) $edit_rule->amount < 0 ? '-' : '' ) . Forms::percent_input( abs( (int) $edit_rule->amount ) ) : Forms::money_input( (int) $edit_rule->amount, Forms::currency_decimals( $currency ) ),
				'until' => $edit_rule->until_at ? Forms::utc_to_local( $edit_rule->until_at, $timezone ) : '',
				'hours_before' => (int) $edit_rule->hours_before ? (string) (int) $edit_rule->hours_before : '',
				'sold_pct' => (int) $edit_rule->sold_pct ? (string) (int) $edit_rule->sold_pct : '',
				'weekdays' => '' === (string) $edit_rule->weekdays ? array() : array_map( 'intval', explode( ',', (string) $edit_rule->weekdays ) ),
				'time_from' => (string) $edit_rule->time_from,
				'time_to' => (string) $edit_rule->time_to,
				'priority' => (string) (int) $edit_rule->priority,
			);
			if ( ! isset( $type_names[ $v['type_name'] ] ) ) { $type_names[ $v['type_name'] ] = $v['type_name']; }
		}
		echo '<h4 id="wpcap-rule-form">' . esc_html( $edit_rule ? __( 'Preisregel bearbeiten', 'pass-captain' ) : __( 'Preisregel hinzufügen', 'pass-captain' ) ) . '</h4>';
		Forms::open_form( $edit_rule ? 'wpcap_series_rule_update' : 'wpcap_series_rule_save', self::NONCE, 'class="wpcap-series-rule-form"' );
		Forms::hidden( 'series_id', (int) $series_id );
		if ( $edit_rule ) { Forms::hidden( 'rule_id', (int) $edit_rule->rule_id ); }
		$row = static function ( $id, $label, $callback, $help = '' ) {
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			$callback();
			if ( $help ) { echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>'; }
			echo '</td></tr>';
		};
		echo '<table class="form-table" role="presentation"><tbody>';
		$row( 'wpcap-rule-label', __( 'Bezeichnung (öffentlich, optional)', 'pass-captain' ), static function () use ( $v ) { echo '<input id="wpcap-rule-label" name="label" type="text" class="regular-text" maxlength="190" value="' . esc_attr( $v['label'] ) . '">'; }, __( 'Wird neben dem Preis angezeigt, z. B. „Frühbucher“.', 'pass-captain' ) );
		$row( 'wpcap-rule-kind', __( 'Art', 'pass-captain' ), static function () use ( $kinds, $v ) { Forms::select( 'kind', $kinds, $v['kind'], 'wpcap-rule-kind' ); } );
		$row( 'wpcap-rule-type', __( 'Ticketart', 'pass-captain' ), static function () use ( $type_names, $v ) { Forms::select( 'type_name', $type_names, $v['type_name'], 'wpcap-rule-type' ); } );
		$row( 'wpcap-rule-mode', __( 'Preis', 'pass-captain' ), static function () use ( $currency, $v ) {
			Forms::select( 'price_mode', array( 'fixed' => sprintf( /* translators: %s: currency */ __( 'Fester Preis (brutto, %s)', 'pass-captain' ), $currency ), 'percent' => __( 'Prozentuale Änderung', 'pass-captain' ) ), $v['price_mode'], 'wpcap-rule-mode' );
			echo ' <label class="screen-reader-text" for="wpcap-rule-amount">' . esc_html__( 'Betrag oder Prozent', 'pass-captain' ) . '</label><input id="wpcap-rule-amount" name="amount" type="text" inputmode="decimal" size="10" required aria-describedby="wpcap-rule-mode-help" value="' . esc_attr( $v['amount'] ) . '">';
		}, __( 'Fester Preis z. B. 19,90; prozentual z. B. -20 (20 % günstiger) oder 15 (15 % teurer).', 'pass-captain' ) );
		/* translators: %s: timezone */
		$row( 'wpcap-rule-until', __( 'Frühbucher bis', 'pass-captain' ), static function () use ( $v ) { echo '<input id="wpcap-rule-until" name="until" type="datetime-local" value="' . esc_attr( $v['until'] ) . '">'; }, sprintf( __( 'Nur für Frühbucher. Ortszeit %s; gilt für Buchungen vor diesem Zeitpunkt.', 'pass-captain' ), $timezone ) );
		$row( 'wpcap-rule-hours', __( 'Last-Minute ab Stunden vor Beginn', 'pass-captain' ), static function () use ( $v ) { echo '<input id="wpcap-rule-hours" name="hours_before" type="number" min="1" max="1440" value="' . esc_attr( $v['hours_before'] ) . '">'; } );
		$row( 'wpcap-rule-pct', __( 'Nachfragestufe ab % verkauft', 'pass-captain' ), static function () use ( $v ) { echo '<input id="wpcap-rule-pct" name="sold_pct" type="number" min="1" max="100" value="' . esc_attr( $v['sold_pct'] ) . '">'; }, __( 'Verkauft = bezahlte und aktuell reservierte Tickets der Ticketart (bzw. des Termins, wenn die Ticketart kein eigenes Kontingent hat). Mehrere Stufen sind möglich; die höchste erreichte gilt.', 'pass-captain' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</legend>';
		foreach ( self::weekday_names() as $num => $name ) {
			echo '<label style="margin-right:1em"><input type="checkbox" name="weekdays[]" value="' . (int) $num . '"' . checked( in_array( (int) $num, $v['weekdays'], true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Nur für Wochentags-/Zeitfensterpreise; maßgeblich ist der Beginn des Termins in Ortszeit.', 'pass-captain' ) . '</p></fieldset></td></tr>';
		$row( 'wpcap-rule-from', __( 'Zeitfenster Beginn von–bis', 'pass-captain' ), static function () use ( $v ) {
			echo '<input id="wpcap-rule-from" name="time_from" type="time" value="' . esc_attr( $v['time_from'] ) . '"> – <label class="screen-reader-text" for="wpcap-rule-to">' . esc_html__( 'bis', 'pass-captain' ) . '</label><input id="wpcap-rule-to" name="time_to" type="time" value="' . esc_attr( $v['time_to'] ) . '">';
		}, __( '„von“ inklusive, „bis“ exklusive. Leer = ganzer Tag.', 'pass-captain' ) );
		$row( 'wpcap-rule-priority', __( 'Priorität (optional)', 'pass-captain' ), static function () use ( $v ) { echo '<input id="wpcap-rule-priority" name="priority" type="number" min="-9999" max="9999" value="' . esc_attr( $v['priority'] ) . '">'; }, __( 'Leer = Standard (Last-Minute 40, Nachfrage 30, Frühbucher 20, Wochentag 10).', 'pass-captain' ) );
		echo '</tbody></table>';
		submit_button( $edit_rule ? __( 'Preisregel speichern', 'pass-captain' ) : __( 'Preisregel hinzufügen', 'pass-captain' ) );
		if ( $edit_rule ) { echo '<p><a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => (int) $series_id ) ) . '#wpcap-series-rules' ) . '">' . esc_html__( 'Bearbeiten abbrechen', 'pass-captain' ) . '</a></p>'; }
		echo '</form>';

		echo '<h3>' . esc_html__( 'Gemeinsame Kontingente', 'pass-captain' ) . '</h3>';
		$pools = Pools::overview( $series_id );
		if ( $pools ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th>' . esc_html__( 'Belegt / Kontingent', 'pass-captain' ) . '</th><th>' . esc_html__( 'Kontingent ändern', 'pass-captain' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
			foreach ( $pools as $pool ) {
				echo '<tr><td>' . esc_html( $pool->name ) . '</td><td>' . esc_html( '' !== (string) $pool->type_name ? $pool->type_name : __( 'Alle', 'pass-captain' ) ) . '</td><td>' . (int) $pool->used . ' / ' . (int) $pool->capacity . '</td><td>';
				Forms::open_form( 'wpcap_series_pool_update', self::NONCE );
				Forms::hidden( 'pool_id', (int) $pool->pool_id );
				Forms::hidden( 'series_id', (int) $series_id );
				$id = 'wpcap-pool-cap-' . (int) $pool->pool_id;
				echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Neues Kontingent', 'pass-captain' ) . '</label><input id="' . esc_attr( $id ) . '" name="capacity" type="number" min="1" value="' . (int) $pool->capacity . '"> <button type="submit" class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></form></td><td>';
				Forms::open_form( 'wpcap_series_pool_delete', self::NONCE );
				Forms::hidden( 'pool_id', (int) $pool->pool_id );
				Forms::hidden( 'series_id', (int) $series_id );
				/* translators: %s: pool name */
				echo '<button type="submit" class="button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Kontingent „%s“ entfernen', 'pass-captain' ), $pool->name ) ) . '">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Keine gemeinsamen Kontingente – es gelten nur die Kontingente der Termine und Ticketarten.', 'pass-captain' ) . '</p>';
		}
		echo '<h4>' . esc_html__( 'Kontingent hinzufügen', 'pass-captain' ) . '</h4>';
		Forms::open_form( 'wpcap_series_pool_save', self::NONCE );
		Forms::hidden( 'series_id', (int) $series_id );
		echo '<table class="form-table" role="presentation"><tbody>';
		$row( 'wpcap-pool-name', __( 'Name', 'pass-captain' ), static function () { echo '<input id="wpcap-pool-name" name="name" type="text" class="regular-text" maxlength="190">'; } );
		$row( 'wpcap-pool-type', __( 'Ticketart', 'pass-captain' ), static function () use ( $type_names ) { Forms::select( 'type_name', $type_names, '', 'wpcap-pool-type' ); }, __( 'z. B. „Student“ für „max. 200 Studierendentickets über die ganze Serie“.', 'pass-captain' ) );
		$row( 'wpcap-pool-capacity', __( 'Kontingent (alle Termine zusammen)', 'pass-captain' ), static function () { echo '<input id="wpcap-pool-capacity" name="capacity" type="number" min="1" required>'; } );
		echo '</tbody></table>';
		submit_button( __( 'Kontingent hinzufügen', 'pass-captain' ) );
		echo '</form>';

		self::render_price_preview( $series_id, $currency );
		ChangeHistory::render( ChangeHistory::for_series( $series_id ), $timezone, 'wpcap-series-history' );
	}

	/** A128: occurrences kept although they fell out of the rule (sold tickets or linked data). */
	private static function render_flagged( $series_id ) {
		$flagged = Generator::flagged( $series_id );
		if ( ! $flagged ) { return; }
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Termine außerhalb der Serienregel', 'pass-captain' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Diese Termine passen nach einer Änderung nicht mehr zur Regel. Sie wurden nicht gelöscht, weil es Verkäufe oder verknüpfte Daten gibt. Prüfe sie und sage sie bei Bedarf über „Termin absagen“ ab (mit Erstattung und Benachrichtigung).', 'pass-captain' ) . '</p><ul style="list-style:disc;margin-left:2em">';
		foreach ( $flagged as $occ ) {
			$sold = Queries::sold_for_occurrence( $occ->occurrence_id );
			/* translators: 1: date, 2: sold tickets */
			echo '<li><a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\EventsPage::SLUG, array( 'view' => 'occurrence', 'occurrence_id' => (int) $occ->occurrence_id ) ) ) . '">' . esc_html( sprintf( __( '%1$s (%2$d verkauft)', 'pass-captain' ), Forms::datetime( $occ->starts_at, $occ->timezone ), $sold ) ) . '</a>';
			if ( 'cancelled' !== $occ->status ) {
				echo ' – <a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\EventsPage::SLUG, array( 'view' => 'cancel_occurrence', 'occurrence_id' => (int) $occ->occurrence_id ) ) ) . '">' . esc_html__( 'Termin absagen', 'pass-captain' ) . '</a>';
			} else {
				echo ' – ' . esc_html__( 'abgesagt', 'pass-captain' );
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Effective prices and availability of the next occurrences right now. */
	private static function render_price_preview( $series_id, $currency ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.*,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_series_occurrences so INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=so.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id WHERE so.series_id=%d AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at,tt.ticket_type_id LIMIT 30", absint( $series_id ), current_time( 'mysql', true ) ) );
		if ( ! $rows ) { return; }
		echo '<h3>' . esc_html__( 'Aktuelle Preise der nächsten Termine', 'pass-captain' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Termin', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th>' . esc_html__( 'Grundpreis', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktueller Preis', 'pass-captain' ) . '</th><th>' . esc_html__( 'Verfügbar', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $type ) {
			$price = \PassCaptain\Orders::unit_price( $type );
			$label = \PassCaptain\Orders::price_label( $type );
			$available = \PassCaptain\Orders::availability( $type->ticket_type_id );
			echo '<tr><td>' . esc_html( Forms::datetime( $type->starts_at, $type->timezone ) ) . '</td><td>' . esc_html( $type->name ) . '</td><td>' . esc_html( Forms::money( $type->price_minor, $type->currency ) ) . '</td><td>' . esc_html( Forms::money( $price, $type->currency ) . ( '' !== $label ? ' · ' . $label : '' ) ) . '</td><td>' . esc_html( null === $available ? '∞' : (string) $available ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
