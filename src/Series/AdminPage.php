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
			$message = sprintf( __( '%1$d Termine angelegt, %2$d bereits vorhandene und %3$d vergangene übersprungen.', 'pass-captain' ), count( $result['created'] ), $result['skipped_existing'], $result['skipped_past'] );
		}
		Forms::finish( $result, $message, self::url( array( 'event_id' => absint( $in['event_id'] ?? 0 ) ) ) );
	}

	public static function render() {
		if ( ! current_user_can( Generator::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin wpcap-series-admin"><h1>' . esc_html__( 'Serientermine', 'pass-captain' ) . '</h1>';
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
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-series-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label> ';
		Forms::select( 'event_id', array( '' => '—' ) + $events, $event_id, 'wpcap-series-event' );
		echo ' <button class="button">' . esc_html__( 'Auswählen', 'pass-captain' ) . '</button></form>';
		if ( ! $event_id || ! isset( $events[ $event_id ] ) || ! Access::can_access_event( $event_id, Generator::CAP ) ) { echo '</div>'; return; }
		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		}
		self::render_form( $event_id, $in );
		if ( is_array( $preview ) ) { self::render_preview( $preview, $in ); }
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

	private static function render_form( $event_id, array $in ) {
		echo '<form method="post" action="' . esc_url( self::url( array( 'event_id' => $event_id ) ) ) . '" class="wpcap-series-form">';
		wp_nonce_field( self::NONCE );
		Forms::hidden( 'event_id', $event_id );
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
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary" name="wpcap_series_preview" value="1">' . esc_html__( 'Vorschau anzeigen', 'pass-captain' ) . '</button></p></form>';
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
			if ( $item['shifted'] ) { $status .= ' · ' . __( 'wegen Zeitumstellung verschoben', 'pass-captain' ); }
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
		submit_button( sprintf( __( '%d Termine anlegen', 'pass-captain' ), $new ) );
		echo '</form>';
	}

	private static function render_existing( $event_id ) {
		$series = Generator::for_event( $event_id );
		if ( ! $series ) { return; }
		echo '<h2>' . esc_html__( 'Gespeicherte Serien', 'pass-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Regel', 'pass-captain' ) . '</th><th>' . esc_html__( 'Termine', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zuletzt ausgeführt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Preise & Kontingente', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $row ) {
			$definition = json_decode( $row->definition, true );
			$rule = $definition['rule'] ?? array();
			$summary = ( $rule['freq'] ?? '' ) . ' · ' . ( $rule['time'] ?? '' ) . ' ' . ( $rule['timezone'] ?? '' ) . ( isset( $rule['until'] ) ? ' · ≤ ' . $rule['until'] : '' ) . ( isset( $rule['count'] ) ? ' · ' . $rule['count'] . '×' : '' );
			echo '<tr><td>' . (int) $row->series_id . '</td><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $summary ) . '</td><td>' . (int) $row->occurrences . '</td><td>' . esc_html( Forms::datetime( $row->updated_at, 'UTC' ) ) . '</td><td><a href="' . esc_url( self::url( array( 'event_id' => $event_id, 'series_id' => (int) $row->series_id ) ) . '#wpcap-series-rules' ) . '">' . esc_html__( 'Preisstufen und Kontingente', 'pass-captain' ) . '</a></td></tr>';
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

	public static function handle_rule_delete() {
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
				return sprintf( __( 'ab %d Stunden vor Beginn', 'pass-captain' ), (int) $rule->hours_before );
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

		echo '<h3>' . esc_html__( 'Preisregeln', 'pass-captain' ) . '</h3>';
		$rules = Pricing::for_series( $series_id );
		if ( $rules ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Art', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bedingung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Preis', 'pass-captain' ) . '</th><th>' . esc_html__( 'Priorität', 'pass-captain' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				echo '<tr><td>' . esc_html( $rule->label ) . '</td><td>' . esc_html( $kinds[ $rule->kind ] ?? $rule->kind ) . '</td><td>' . esc_html( '' !== (string) $rule->type_name ? $rule->type_name : __( 'Alle', 'pass-captain' ) ) . '</td><td>' . esc_html( self::rule_condition( $rule, $timezone ) ) . '</td><td>' . esc_html( self::rule_price( $rule, $currency ) ) . '</td><td>' . (int) $rule->priority . '</td><td>';
				Forms::open_form( 'wpcap_series_rule_delete', self::NONCE );
				Forms::hidden( 'rule_id', (int) $rule->rule_id );
				Forms::hidden( 'series_id', (int) $series_id );
				/* translators: %s: rule label or kind */
				echo '<button type="submit" class="button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Preisregel „%s“ entfernen', 'pass-captain' ), '' !== (string) $rule->label ? $rule->label : ( $kinds[ $rule->kind ] ?? '' ) ) ) . '">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch keine Preisregeln – es gilt der Preis der Ticketart.', 'pass-captain' ) . '</p>';
		}

		echo '<h4>' . esc_html__( 'Preisregel hinzufügen', 'pass-captain' ) . '</h4>';
		Forms::open_form( 'wpcap_series_rule_save', self::NONCE, 'class="wpcap-series-rule-form"' );
		Forms::hidden( 'series_id', (int) $series_id );
		$row = static function ( $id, $label, $callback, $help = '' ) {
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			$callback();
			if ( $help ) { echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>'; }
			echo '</td></tr>';
		};
		echo '<table class="form-table" role="presentation"><tbody>';
		$row( 'wpcap-rule-label', __( 'Bezeichnung (öffentlich, optional)', 'pass-captain' ), static function () { echo '<input id="wpcap-rule-label" name="label" type="text" class="regular-text" maxlength="190">'; }, __( 'Wird neben dem Preis angezeigt, z. B. „Frühbucher“.', 'pass-captain' ) );
		$row( 'wpcap-rule-kind', __( 'Art', 'pass-captain' ), static function () use ( $kinds ) { Forms::select( 'kind', $kinds, 'early_bird', 'wpcap-rule-kind' ); } );
		$row( 'wpcap-rule-type', __( 'Ticketart', 'pass-captain' ), static function () use ( $type_names ) { Forms::select( 'type_name', $type_names, '', 'wpcap-rule-type' ); } );
		$row( 'wpcap-rule-mode', __( 'Preis', 'pass-captain' ), static function () use ( $currency ) {
			Forms::select( 'price_mode', array( 'fixed' => sprintf( /* translators: %s: currency */ __( 'Fester Preis (brutto, %s)', 'pass-captain' ), $currency ), 'percent' => __( 'Prozentuale Änderung', 'pass-captain' ) ), 'fixed', 'wpcap-rule-mode' );
			echo ' <label class="screen-reader-text" for="wpcap-rule-amount">' . esc_html__( 'Betrag oder Prozent', 'pass-captain' ) . '</label><input id="wpcap-rule-amount" name="amount" type="text" inputmode="decimal" size="10" required aria-describedby="wpcap-rule-mode-help">';
		}, __( 'Fester Preis z. B. 19,90; prozentual z. B. -20 (20 % günstiger) oder 15 (15 % teurer).', 'pass-captain' ) );
		/* translators: %s: timezone */
		$row( 'wpcap-rule-until', __( 'Frühbucher bis', 'pass-captain' ), static function () { echo '<input id="wpcap-rule-until" name="until" type="datetime-local">'; }, sprintf( __( 'Nur für Frühbucher. Ortszeit %s; gilt für Buchungen vor diesem Zeitpunkt.', 'pass-captain' ), $timezone ) );
		$row( 'wpcap-rule-hours', __( 'Last-Minute ab Stunden vor Beginn', 'pass-captain' ), static function () { echo '<input id="wpcap-rule-hours" name="hours_before" type="number" min="1" max="1440">'; } );
		$row( 'wpcap-rule-pct', __( 'Nachfragestufe ab % verkauft', 'pass-captain' ), static function () { echo '<input id="wpcap-rule-pct" name="sold_pct" type="number" min="1" max="100">'; }, __( 'Verkauft = bezahlte und aktuell reservierte Tickets der Ticketart (bzw. des Termins, wenn die Ticketart kein eigenes Kontingent hat). Mehrere Stufen sind möglich; die höchste erreichte gilt.', 'pass-captain' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</legend>';
		foreach ( self::weekday_names() as $num => $name ) {
			echo '<label style="margin-right:1em"><input type="checkbox" name="weekdays[]" value="' . (int) $num . '"> ' . esc_html( $name ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Nur für Wochentags-/Zeitfensterpreise; maßgeblich ist der Beginn des Termins in Ortszeit.', 'pass-captain' ) . '</p></fieldset></td></tr>';
		$row( 'wpcap-rule-from', __( 'Zeitfenster Beginn von–bis', 'pass-captain' ), static function () {
			echo '<input id="wpcap-rule-from" name="time_from" type="time"> – <label class="screen-reader-text" for="wpcap-rule-to">' . esc_html__( 'bis', 'pass-captain' ) . '</label><input id="wpcap-rule-to" name="time_to" type="time">';
		}, __( '„von“ inklusive, „bis“ exklusive. Leer = ganzer Tag.', 'pass-captain' ) );
		$row( 'wpcap-rule-priority', __( 'Priorität (optional)', 'pass-captain' ), static function () { echo '<input id="wpcap-rule-priority" name="priority" type="number" min="-9999" max="9999">'; }, __( 'Leer = Standard (Last-Minute 40, Nachfrage 30, Frühbucher 20, Wochentag 10).', 'pass-captain' ) );
		echo '</tbody></table>';
		submit_button( __( 'Preisregel hinzufügen', 'pass-captain' ) );
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
