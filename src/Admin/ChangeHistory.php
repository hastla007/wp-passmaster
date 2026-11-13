<?php
/**
 * A114: readable change histories per occurrence and per series, built from the audit log.
 *
 * Writers store a `changes` map (field => [old, new], raw values) in the audit details; this class
 * computes such diffs, reads the entries of one occurrence (including its ticket types) or one
 * series, and renders them as "Feld: alt → neu" lines in the admin. Values are formatted when they
 * are shown (dates in the occurrence/series timezone, money in the stored currency), so the stored
 * history stays language-neutral. Entries follow the audit retention (Datenschutz → Aufbewahrung).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ChangeHistory {
	const LIMIT = 100;

	/** Normalised scalar form used for comparisons (null/'' equal, arrays joined). */
	private static function scalar( $value ) {
		if ( is_array( $value ) ) { return implode( ',', array_map( 'strval', $value ) ); }
		if ( is_bool( $value ) ) { return $value ? '1' : '0'; }
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Field-by-field diff of two flat arrays (only keys present in $after).
	 *
	 * @return array field => array( old, new ) for changed fields.
	 */
	public static function diff( array $before, array $after ) {
		$out = array();
		foreach ( $after as $key => $new ) {
			$old = $before[ $key ] ?? null;
			if ( self::scalar( $old ) !== self::scalar( $new ) ) { $out[ $key ] = array( $old, $new ); }
		}
		return $out;
	}

	/** Audit entries of an occurrence and its ticket types, newest first. */
	public static function for_occurrence( $occurrence_id, $limit = self::LIMIT ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$type_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence_id ) ) );
		$types = $type_ids ? " OR (object_type='ticket_type' AND object_id IN (" . implode( ',', $type_ids ) . '))' : '';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_audit_logs WHERE (object_type='occurrence' AND object_id=%d)$types ORDER BY audit_id DESC LIMIT %d", $occurrence_id, max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** Audit entries of a series (definition, price rules, pools, generation runs), newest first. */
	public static function for_series( $series_id, $limit = self::LIMIT ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE object_type='series' AND object_id=%d ORDER BY audit_id DESC LIMIT %d", absint( $series_id ), max( 1, (int) $limit ) ) );
	}

	public static function action_labels() {
		return array(
			'occurrence_created' => __( 'Termin angelegt', 'wp-pass-captain' ),
			'occurrence_updated' => __( 'Termin geändert', 'wp-pass-captain' ),
			'occurrence_cancelled' => __( 'Termin abgesagt', 'wp-pass-captain' ),
			'occurrence_rescheduled' => __( 'Termin verschoben', 'wp-pass-captain' ),
			'occurrence_change_notified' => __( 'Ticketinhaber:innen über Änderung informiert', 'wp-pass-captain' ),
			'occurrence_deleted' => __( 'Termin entfernt (nicht mehr in der Serienregel)', 'wp-pass-captain' ),
			'occurrence_flagged' => __( 'Termin außerhalb der Serienregel markiert', 'wp-pass-captain' ),
			'occurrence_unflagged' => __( 'Markierung aufgehoben', 'wp-pass-captain' ),
			'ticket_type_created' => __( 'Ticketart angelegt', 'wp-pass-captain' ),
			'ticket_type_updated' => __( 'Ticketart geändert', 'wp-pass-captain' ),
			'seat_map_linked' => __( 'Saalplan verknüpft', 'wp-pass-captain' ),
			'seat_map_unlinked' => __( 'Saalplan entfernt', 'wp-pass-captain' ),
			'series_generated' => __( 'Termine erzeugt', 'wp-pass-captain' ),
			'series_updated' => __( 'Seriendefinition geändert', 'wp-pass-captain' ),
			'series_price_rule_created' => __( 'Preisregel hinzugefügt', 'wp-pass-captain' ),
			'series_price_rule_updated' => __( 'Preisregel geändert', 'wp-pass-captain' ),
			'series_price_rule_deleted' => __( 'Preisregel entfernt', 'wp-pass-captain' ),
			'series_pool_created' => __( 'Kontingent hinzugefügt', 'wp-pass-captain' ),
			'series_pool_updated' => __( 'Kontingent geändert', 'wp-pass-captain' ),
			'series_pool_deleted' => __( 'Kontingent entfernt', 'wp-pass-captain' ),
		);
	}

	public static function field_labels() {
		return array(
			'starts_at' => __( 'Beginn', 'wp-pass-captain' ),
			'ends_at' => __( 'Ende', 'wp-pass-captain' ),
			'doors_open_at' => __( 'Einlass ab', 'wp-pass-captain' ),
			'timezone' => __( 'Zeitzone', 'wp-pass-captain' ),
			'location' => __( 'Ort', 'wp-pass-captain' ),
			'capacity' => __( 'Kontingent', 'wp-pass-captain' ),
			'status' => __( 'Status', 'wp-pass-captain' ),
			'name' => __( 'Name', 'wp-pass-captain' ),
			'price_minor' => __( 'Preis', 'wp-pass-captain' ),
			'currency' => __( 'Währung', 'wp-pass-captain' ),
			'tax_rate_bp' => __( 'Steuersatz', 'wp-pass-captain' ),
			'max_per_order' => __( 'Max. pro Bestellung', 'wp-pass-captain' ),
			'sales_start' => __( 'Verkaufsstart', 'wp-pass-captain' ),
			'sales_end' => __( 'Verkaufsende', 'wp-pass-captain' ),
			'seat_version_id' => __( 'Saalplan-Version', 'wp-pass-captain' ),
			'template_occurrence_id' => __( 'Vorlagentermin', 'wp-pass-captain' ),
			'sales_start_days_before' => __( 'Verkaufsstart: Tage vor Beginn', 'wp-pass-captain' ),
			'sales_end_minutes_before' => __( 'Verkaufsende: Minuten vor Beginn', 'wp-pass-captain' ),
			'freq' => __( 'Wiederholung', 'wp-pass-captain' ),
			'interval' => __( 'Intervall', 'wp-pass-captain' ),
			'weekdays' => __( 'Wochentage', 'wp-pass-captain' ),
			'month_day' => __( 'Tag im Monat', 'wp-pass-captain' ),
			'nth' => __( 'n-ter Wochentag', 'wp-pass-captain' ),
			'nth_weekday' => __( 'Wochentag', 'wp-pass-captain' ),
			'dates' => __( 'Datumsliste', 'wp-pass-captain' ),
			'start_date' => __( 'Erster Tag', 'wp-pass-captain' ),
			'time' => __( 'Uhrzeit (Ortszeit)', 'wp-pass-captain' ),
			'duration_minutes' => __( 'Dauer (Minuten)', 'wp-pass-captain' ),
			'until' => __( 'Ende am', 'wp-pass-captain' ),
			'count' => __( 'Anzahl Termine', 'wp-pass-captain' ),
			'exclusions' => __( 'Ausnahmen', 'wp-pass-captain' ),
			'label' => __( 'Bezeichnung', 'wp-pass-captain' ),
			'type_name' => __( 'Ticketart', 'wp-pass-captain' ),
			'kind' => __( 'Art', 'wp-pass-captain' ),
			'priority' => __( 'Priorität', 'wp-pass-captain' ),
			'until_at' => __( 'Frühbucher bis', 'wp-pass-captain' ),
			'hours_before' => __( 'Last-Minute ab Stunden vor Beginn', 'wp-pass-captain' ),
			'sold_pct' => __( 'Nachfragestufe ab % verkauft', 'wp-pass-captain' ),
			'time_from' => __( 'Zeitfenster von', 'wp-pass-captain' ),
			'time_to' => __( 'Zeitfenster bis', 'wp-pass-captain' ),
			'price_mode' => __( 'Preisart', 'wp-pass-captain' ),
			'amount' => __( 'Preis', 'wp-pass-captain' ),
			'flag' => __( 'Markierung', 'wp-pass-captain' ),
		);
	}

	private static function weekday_list( $value ) {
		$names = array( 1 => __( 'Mo', 'wp-pass-captain' ), 2 => __( 'Di', 'wp-pass-captain' ), 3 => __( 'Mi', 'wp-pass-captain' ), 4 => __( 'Do', 'wp-pass-captain' ), 5 => __( 'Fr', 'wp-pass-captain' ), 6 => __( 'Sa', 'wp-pass-captain' ), 7 => __( 'So', 'wp-pass-captain' ) );
		$days = is_array( $value ) ? $value : ( '' === (string) $value ? array() : explode( ',', (string) $value ) );
		return implode( ', ', array_map( static function ( $d ) use ( $names ) { return $names[ (int) $d ] ?? (string) $d; }, $days ) );
	}

	/**
	 * Formats one stored value for display.
	 *
	 * @param array $context timezone, currency, price_mode.
	 */
	public static function format_value( $field, $value, array $context = array() ) {
		$timezone = (string) ( $context['timezone'] ?? 'UTC' );
		if ( null === $value || '' === $value || array() === $value ) {
			return in_array( $field, array( 'capacity' ), true ) ? __( 'unbegrenzt', 'wp-pass-captain' ) : '—';
		}
		switch ( $field ) {
			case 'starts_at':
			case 'ends_at':
			case 'doors_open_at':
			case 'sales_start':
			case 'sales_end':
			case 'until_at':
				return Forms::datetime( (string) $value, $timezone, false );
			case 'capacity':
				return (int) $value > 0 ? (string) (int) $value : __( 'unbegrenzt', 'wp-pass-captain' );
			case 'status':
				return Forms::status_label( (string) $value );
			case 'price_minor':
				return Forms::money( (int) $value, (string) ( $context['currency'] ?? 'EUR' ) );
			case 'amount':
				if ( 'percent' === ( $context['price_mode'] ?? 'fixed' ) ) {
					return ( (int) $value > 0 ? '+' : '−' ) . Forms::percent_input( abs( (int) $value ) ) . ' %';
				}
				return Forms::money( (int) $value, (string) ( $context['currency'] ?? 'EUR' ) );
			case 'tax_rate_bp':
				return Forms::percent_input( (int) $value ) . ' %';
			case 'weekdays':
			case 'nth_weekday':
				return self::weekday_list( $value );
			case 'dates':
			case 'exclusions':
				$dates = is_array( $value ) ? $value : explode( ',', (string) $value );
				return implode( ', ', array_map( static function ( $d ) { $t = strtotime( (string) $d ); return $t ? gmdate( 'd.m.Y', $t ) : (string) $d; }, $dates ) );
			case 'start_date':
			case 'until':
				$t = strtotime( (string) $value );
				return $t ? gmdate( 'd.m.Y', $t ) : (string) $value;
			case 'kind':
				$kinds = class_exists( '\\PassCaptain\\Series\\Pricing' ) ? \PassCaptain\Series\Pricing::kind_labels() : array();
				return $kinds[ $value ] ?? (string) $value;
			case 'price_mode':
				return 'percent' === $value ? __( 'Prozentuale Änderung', 'wp-pass-captain' ) : __( 'Fester Preis', 'wp-pass-captain' );
			case 'flag':
				return 'outside_rule' === $value ? __( 'außerhalb der Serienregel', 'wp-pass-captain' ) : (string) $value;
			case 'freq':
				$freqs = array( 'weekly' => __( 'Wöchentlich an Wochentagen', 'wp-pass-captain' ), 'daily' => __( 'Täglich', 'wp-pass-captain' ), 'monthly_day' => __( 'Monatlich am Tag X', 'wp-pass-captain' ), 'monthly_nth' => __( 'Monatlich am n-ten Wochentag', 'wp-pass-captain' ), 'dates' => __( 'Datumsliste', 'wp-pass-captain' ) );
				return $freqs[ $value ] ?? (string) $value;
			case 'seat_version_id':
			case 'template_occurrence_id':
				return (int) $value ? '#' . (int) $value : '—';
		}
		return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
	}

	/**
	 * Readable lines of an entry: "Feld: alt → neu" per change, plus context lines.
	 *
	 * @param object $row      Audit row.
	 * @param string $timezone Timezone for dates.
	 * @return string[]
	 */
	public static function lines( $row, $timezone ) {
		$details = json_decode( (string) $row->details, true );
		$details = is_array( $details ) ? $details : array();
		$labels = self::field_labels();
		$context = array( 'timezone' => $timezone, 'currency' => (string) ( $details['currency'] ?? 'EUR' ), 'price_mode' => (string) ( $details['price_mode'] ?? 'fixed' ) );
		$lines = array();
		$changes = is_array( $details['changes'] ?? null ) ? $details['changes'] : array();
		// Legacy occurrence_updated entries (before A114) only carried the start.
		if ( ! $changes && 'occurrence_updated' === $row->action && isset( $details['old_starts_at'], $details['new_starts_at'] ) && $details['old_starts_at'] !== $details['new_starts_at'] ) {
			$changes = array( 'starts_at' => array( $details['old_starts_at'], $details['new_starts_at'] ) );
		}
		foreach ( $changes as $field => $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) ) { continue; }
			$old_context = $context;
			$new_context = $context;
			if ( 'amount' === $field && isset( $changes['price_mode'] ) ) {
				$old_context['price_mode'] = (string) $changes['price_mode'][0];
				$new_context['price_mode'] = (string) $changes['price_mode'][1];
			}
			if ( 'price_minor' === $field && isset( $changes['currency'] ) ) {
				$old_context['currency'] = (string) $changes['currency'][0];
				$new_context['currency'] = (string) $changes['currency'][1];
			}
			/* translators: 1: field, 2: old value, 3: new value */
			$lines[] = sprintf( __( '%1$s: %2$s → %3$s', 'wp-pass-captain' ), $labels[ $field ] ?? $field, self::format_value( $field, $pair[0], $old_context ), self::format_value( $field, $pair[1], $new_context ) );
		}
		if ( is_array( $details['rule'] ?? null ) ) {
			foreach ( $details['rule'] as $field => $value ) {
				if ( null === $value || '' === $value || ( 0 === $value && ! in_array( $field, array( 'amount', 'priority' ), true ) ) ) { continue; }
				/* translators: 1: field, 2: value */
				$lines[] = sprintf( __( '%1$s: %2$s', 'wp-pass-captain' ), $labels[ $field ] ?? $field, self::format_value( $field, $value, array( 'price_mode' => (string) ( $details['rule']['price_mode'] ?? 'fixed' ) ) + $context ) );
			}
		}
		if ( isset( $details['name'] ) && in_array( $row->action, array( 'series_pool_created', 'series_pool_deleted', 'ticket_type_created' ), true ) ) {
			/* translators: %s: name */
			$lines[] = sprintf( __( 'Name: %s', 'wp-pass-captain' ), (string) $details['name'] );
		}
		if ( 'series_pool_created' === $row->action && isset( $details['capacity'] ) ) {
			/* translators: %d: capacity */
			$lines[] = sprintf( __( 'Kontingent: %d', 'wp-pass-captain' ), (int) $details['capacity'] );
		}
		if ( 'occurrence_created' === $row->action && isset( $details['starts_at'] ) ) {
			/* translators: %s: start */
			$lines[] = sprintf( __( 'Beginn: %s', 'wp-pass-captain' ), self::format_value( 'starts_at', $details['starts_at'], $context ) );
		}
		if ( ! empty( $details['series_id'] ) && in_array( $row->action, array( 'occurrence_created', 'occurrence_updated', 'occurrence_flagged', 'occurrence_unflagged' ), true ) ) {
			/* translators: %d: series id */
			$lines[] = sprintf( __( 'durch Serie #%d', 'wp-pass-captain' ), (int) $details['series_id'] );
		}
		if ( ! empty( $details['rescheduled'] ) ) {
			$lines[] = __( 'Als Terminverschiebung gemeldet (Ticketinhaber werden benachrichtigt).', 'wp-pass-captain' );
		}
		if ( isset( $details['reason'] ) && '' !== (string) $details['reason'] ) {
			/* translators: %s: reason */
			$lines[] = sprintf( __( 'Grund: %s', 'wp-pass-captain' ), (string) $details['reason'] );
		}
		if ( 'series_generated' === $row->action ) {
			/* translators: 1: created, 2: skipped existing, 3: skipped past */
			$lines[] = sprintf( __( '%1$d angelegt, %2$d bereits vorhanden, %3$d vergangen', 'wp-pass-captain' ), (int) ( $details['created'] ?? 0 ), (int) ( $details['skipped_existing'] ?? 0 ), (int) ( $details['skipped_past'] ?? 0 ) );
		}
		if ( 'series_updated' === $row->action && is_array( $details['effects'] ?? null ) ) {
			$e = $details['effects'];
			/* translators: 1: added, 2: changed, 3: deleted, 4: kept and flagged, 5: skipped */
			$lines[] = sprintf( __( 'Auswirkung: %1$d hinzugefügt, %2$d geändert, %3$d entfernt, %4$d mit Verkäufen behalten und markiert, %5$d übersprungen', 'wp-pass-captain' ), (int) ( $e['add'] ?? 0 ), (int) ( $e['change'] ?? 0 ), (int) ( $e['delete'] ?? 0 ), (int) ( $e['flag'] ?? 0 ), (int) ( $e['skip'] ?? 0 ) + (int) ( $e['conflict'] ?? 0 ) );
		}
		if ( 'ticket_type' === $row->object_type && isset( $details['type_name'] ) ) {
			/* translators: %s: ticket type name */
			array_unshift( $lines, sprintf( __( 'Ticketart „%s“', 'wp-pass-captain' ), (string) $details['type_name'] ) );
		}
		return $lines;
	}

	/** Renders a history table (or a short note when empty). */
	public static function render( array $rows, $timezone, $heading_id = '' ) {
		echo '<h2' . ( '' !== $heading_id ? ' id="' . esc_attr( $heading_id ) . '"' : '' ) . '>' . esc_html__( 'Änderungsverlauf', 'wp-pass-captain' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Noch keine Änderungen protokolliert.', 'wp-pass-captain' ) . '</p>';
			return;
		}
		$actions = self::action_labels();
		echo '<table class="widefat striped wpcap-change-history"><thead><tr><th scope="col">' . esc_html__( 'Zeitpunkt', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Benutzer', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Änderung', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Details (alt → neu)', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$lines = self::lines( $row, $timezone );
			echo '<tr><td>' . esc_html( Forms::datetime( $row->created_at, $timezone ) ) . '</td><td>' . esc_html( Forms::user_label( $row->user_id ) ) . '</td><td>' . esc_html( $actions[ $row->action ] ?? $row->action ) . '</td><td>';
			echo $lines ? '<ul style="margin:0">' . implode( '', array_map( static function ( $line ) { return '<li>' . esc_html( $line ) . '</li>'; }, $lines ) ) . '</ul>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per line.
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		/* translators: %d: maximum number of entries */
		echo '<p class="description">' . esc_html( sprintf( __( 'Die letzten %d Einträge. Einträge werden nach der Aufbewahrungsfrist des Protokolls gelöscht.', 'wp-pass-captain' ), self::LIMIT ) ) . '</p>';
	}
}
