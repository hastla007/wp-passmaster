<?php
/**
 * Admin page "Pässe": create pass ticket types, choose the included occurrences and limits,
 * pass quotas per occurrence, sales/availability and the usage report (entries per day).
 *
 * The action methods take unslashed POST arrays and return a result or a WP_Error; the handle_*()
 * wrappers add nonce checks and redirects.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-passes';

	/* ---------------------------------------------------------------------------------------------
	 * Actions.
	 * ------------------------------------------------------------------------------------------- */

	/** Options shared by create() and save(). */
	private static function options( array $in ) {
		$refund = isset( $in['refund_per_day'] ) && '' !== trim( (string) $in['refund_per_day'] ) ? Forms::parse_money( $in['refund_per_day'] ) : 0;
		if ( null === $refund ) { return Forms::error( 'wpcap_pass_refund', __( 'Bitte einen gültigen Betrag für die Teilerstattung angeben.', 'pass-captain' ) ); }
		return array(
			'occurrence_ids' => Forms::ids( $in, 'occurrence_ids' ),
			'entry_mode' => Forms::choice( $in, 'entry_mode', Definitions::MODES, 'once' ),
			'max_occurrences' => Forms::int( $in, 'max_occurrences' ),
			'area_ids' => Forms::ids( $in, 'area_ids' ),
			'refund_per_day_minor' => $refund,
			'on_cancel' => Forms::choice( $in, 'on_cancel', Definitions::ON_CANCEL, 'keep' ),
		);
	}

	public static function create( array $in ) {
		if ( ! current_user_can( Definitions::CAP ) ) { return Forms::forbidden(); }
		$options = self::options( $in );
		if ( is_wp_error( $options ) ) { return $options; }
		$price = Forms::parse_money( $in['price'] ?? '' );
		if ( null === $price ) { return Forms::error( 'wpcap_pass_price', __( 'Bitte einen gültigen Preis angeben.', 'pass-captain' ) ); }
		return Definitions::create( array_merge( $options, array(
			'occurrence_id' => Forms::int( $in, 'anchor_id' ),
			'name' => Forms::text( $in, 'name' ),
			'price_minor' => $price,
			'currency' => Forms::text( $in, 'currency', 3 ) ?: 'EUR',
			'capacity' => Forms::int( $in, 'capacity' ),
			'max_per_order' => Forms::int( $in, 'max_per_order', 10 ),
			'tax_rate_bp' => Forms::parse_percent_bp( $in['tax_rate'] ?? '0' ) ?? 0,
		) ) );
	}

	public static function save( array $in ) {
		if ( ! current_user_can( Definitions::CAP ) ) { return Forms::forbidden(); }
		$options = self::options( $in );
		if ( is_wp_error( $options ) ) { return $options; }
		return Definitions::save( Forms::int( $in, 'ticket_type_id' ), $options );
	}

	public static function remove( array $in ) {
		if ( ! current_user_can( Definitions::CAP ) ) { return Forms::forbidden(); }
		return Definitions::remove( Forms::int( $in, 'ticket_type_id' ) );
	}

	public static function quota( array $in ) {
		if ( ! current_user_can( Definitions::CAP ) ) { return Forms::forbidden(); }
		return Definitions::set_quota( Forms::int( $in, 'occurrence_id' ), Forms::int( $in, 'pass_quota' ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * admin-post handlers.
	 * ------------------------------------------------------------------------------------------- */

	private static function back( $type_id = 0 ) {
		return Forms::page_url( self::SLUG, $type_id ? array( 'ticket_type_id' => (int) $type_id ) : array() );
	}

	public static function handle_create() {
		Forms::verify( 'wpcap_pass_create' );
		$result = self::create( Forms::post() );
		Forms::finish( $result, __( 'Pass angelegt.', 'pass-captain' ), self::back( is_wp_error( $result ) ? 0 : (int) $result ) );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_pass_save' );
		$in = Forms::post();
		Forms::finish( self::save( $in ), __( 'Pass gespeichert.', 'pass-captain' ), self::back( Forms::int( $in, 'ticket_type_id' ) ) );
	}

	public static function handle_remove() {
		Forms::verify( 'wpcap_pass_remove' );
		$in = Forms::post();
		Forms::finish( self::remove( $in ), __( 'Die Ticketart ist kein Pass mehr.', 'pass-captain' ), self::back() );
	}

	public static function handle_quota() {
		Forms::verify( 'wpcap_pass_quota' );
		$in = Forms::post();
		Forms::finish( self::quota( $in ), __( 'Pass-Kontingent gespeichert.', 'pass-captain' ), self::back( Forms::int( $in, 'ticket_type_id' ) ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------------------------------- */

	/** Upcoming occurrences the user may manage (selection lists). */
	private static function selectable_occurrences() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.capacity,o.status,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.starts_at >= %s ORDER BY o.starts_at,o.occurrence_id LIMIT 500", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		return array_values( array_filter( (array) $rows, static function ( $row ) { return Access::can_access_event( (int) $row->event_id, Definitions::CAP ); } ) );
	}

	private static function occurrence_label( $occ ) {
		return $occ->event_name . ' – ' . Forms::datetime( $occ->starts_at, $occ->timezone ) . ( 'cancelled' === $occ->status ? ' ' . __( '(abgesagt)', 'pass-captain' ) : '' );
	}

	public static function render() {
		if ( ! current_user_can( Definitions::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Pässe', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$type_id = Forms::query_arg( 'ticket_type_id', 0 );
		$type = $type_id ? Definitions::type_row( $type_id ) : null;
		if ( $type && Definitions::is_pass( $type_id ) && Access::can_access_event( (int) $type->event_id, Definitions::CAP ) ) {
			self::render_pass( $type );
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview() {
		echo '<p>' . esc_html__( 'Ein Pass (Festival-/Mehrtagespass) ist eine Ticketart, die auf einem Termin verkauft wird und für mehrere Termine gilt – desselben Events oder verschiedener Events. Jeder verkaufte Pass belegt in jedem enthaltenen Termin einen Platz. Bei „N aus M“ wählt der Gast beim Einlass bis zu N der M Termine.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Pass', 'pass-captain' ), __( 'Verkauft auf', 'pass-captain' ), __( 'Gültig für', 'pass-captain' ), __( 'Einlass', 'pass-captain' ), __( 'Verkauft', 'pass-captain' ), __( 'Verfügbar', 'pass-captain' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$shown = 0;
		foreach ( Definitions::all_ids() as $type_id ) {
			$type = Definitions::type_row( $type_id );
			if ( ! $type || ! Access::can_access_event( (int) $type->event_id, Definitions::CAP ) ) { continue; }
			$config = Definitions::get( $type_id );
			$available = Orders::availability( $type_id );
			++$shown;
			echo '<tr><td><a href="' . esc_url( self::back( $type_id ) ) . '"><strong>' . esc_html( $type->name ) . '</strong></a></td>';
			echo '<td>' . esc_html( $type->event_name . ' – ' . Forms::datetime( $type->starts_at, $type->timezone ) ) . '</td>';
			echo '<td>' . esc_html( Display::dates( $type_id ) ) . '</td>';
			echo '<td>' . esc_html( self::mode_label( $config ) ) . '</td>';
			echo '<td>' . esc_html( (string) Definitions::sold_units( $type_id ) ) . '</td>';
			echo '<td>' . esc_html( null === $available ? __( 'unbegrenzt', 'pass-captain' ) : (string) $available ) . '</td></tr>';
		}
		if ( ! $shown ) { echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Pässe.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';

		$occurrences = self::selectable_occurrences();
		echo '<h2>' . esc_html__( 'Neuer Pass', 'pass-captain' ) . '</h2>';
		if ( ! $occurrences ) {
			echo '<p>' . esc_html__( 'Keine kommenden Termine.', 'pass-captain' ) . '</p>';
			return;
		}
		Forms::open_form( 'wpcap_pass_create' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-pass-name">' . esc_html__( 'Name', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-pass-name" name="name" required maxlength="190" placeholder="' . esc_attr__( 'z. B. Festivalpass', 'pass-captain' ) . '"></td></tr>';
		$options = array();
		foreach ( $occurrences as $occ ) { $options[ (int) $occ->occurrence_id ] = self::occurrence_label( $occ ); }
		echo '<tr><th scope="row"><label for="wpcap-pass-anchor">' . esc_html__( 'Verkauft auf Termin', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'anchor_id', $options, 0, 'wpcap-pass-anchor' );
		echo '<p class="description">' . esc_html__( 'Der Pass erscheint als Ticketart dieses Termins (z. B. erster Festivaltag oder ein eigener Termin „Festivalpass“). Online endet der Verkauf mit dessen Beginn.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pass-price">' . esc_html__( 'Preis', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-pass-price" name="price" required class="small-text" inputmode="decimal"> <input type="text" name="currency" value="EUR" maxlength="3" class="small-text" aria-label="' . esc_attr__( 'Währung', 'pass-captain' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pass-tax">' . esc_html__( 'USt.-Satz (%)', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-pass-tax" name="tax_rate" value="0" class="small-text" inputmode="decimal"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pass-cap">' . esc_html__( 'Anzahl Pässe', 'pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-pass-cap" name="capacity" value="0" class="small-text"><p class="description">' . esc_html__( '0 = nur durch die Kapazität der Termine begrenzt.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pass-max">' . esc_html__( 'Max. pro Bestellung', 'pass-captain' ) . '</label></th><td><input type="number" min="1" max="100" id="wpcap-pass-max" name="max_per_order" value="10" class="small-text"></td></tr>';
		self::render_options( null, $occurrences );
		echo '</tbody></table>';
		submit_button( __( 'Pass anlegen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function mode_label( $config ) {
		$label = 'reentry' === $config->entry_mode ? __( 'Wiedereinlass je Termin', 'pass-captain' ) : __( 'Ein Einlass je Termin', 'pass-captain' );
		if ( $config->max_occurrences > 0 ) {
			/* translators: 1: usable dates, 2: included dates */
			$label .= ', ' . sprintf( __( '%1$d aus %2$d Terminen', 'pass-captain' ), $config->max_occurrences, count( $config->occurrence_ids ) );
		}
		return $label;
	}

	/** Option rows (occurrences, entry mode, N of M, areas, cancellation). */
	private static function render_options( $config, array $occurrences ) {
		global $wpdb;
		$selected = $config ? $config->occurrence_ids : array();
		echo '<tr><th scope="row">' . esc_html__( 'Gültig für Termine', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Gültig für Termine', 'pass-captain' ) . '</legend>';
		$listed = array();
		foreach ( $occurrences as $occ ) {
			$listed[] = (int) $occ->occurrence_id;
			echo '<label><input type="checkbox" name="occurrence_ids[]" value="' . esc_attr( (int) $occ->occurrence_id ) . '"' . checked( in_array( (int) $occ->occurrence_id, $selected, true ), true, false ) . '> ' . esc_html( self::occurrence_label( $occ ) ) . '</label><br>';
		}
		foreach ( array_diff( $selected, $listed ) as $id ) {
			// Past or foreign linked dates stay linked.
			echo '<input type="hidden" name="occurrence_ids[]" value="' . esc_attr( (int) $id ) . '">';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pass-mode">' . esc_html__( 'Einlass je Termin', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'entry_mode', array( 'once' => __( 'Einmal pro Termin', 'pass-captain' ), 'reentry' => __( 'Wiedereinlass (Auslass, dann erneuter Einlass)', 'pass-captain' ) ), $config ? $config->entry_mode : 'once', 'wpcap-pass-mode' );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pass-n">' . esc_html__( 'Nutzbare Termine (N aus M)', 'pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-pass-n" name="max_occurrences" class="small-text" value="' . esc_attr( $config ? (int) $config->max_occurrences : 0 ) . '"><p class="description">' . esc_html__( 'z. B. 3 für „3 aus 5 Tagen“; 0 = alle Termine. Plätze werden trotzdem in allen Terminen reserviert.', 'pass-captain' ) . '</p></td></tr>';
		$event_ids = array();
		foreach ( $occurrences as $occ ) { $event_ids[ (int) $occ->event_id ] = $occ->event_name; }
		$areas = $event_ids ? $wpdb->get_results( "SELECT area_id,event_id,name FROM {$wpdb->prefix}wpcap_admission_areas WHERE status='active' AND event_id IN (" . implode( ',', array_map( 'intval', array_keys( $event_ids ) ) ) . ') ORDER BY event_id,name' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( $areas ) {
			echo '<tr><th scope="row">' . esc_html__( 'Einlassbereiche', 'pass-captain' ) . '</th><td>';
			foreach ( $areas as $area ) {
				echo '<label><input type="checkbox" name="area_ids[]" value="' . esc_attr( (int) $area->area_id ) . '"' . checked( $config && in_array( (int) $area->area_id, $config->area_ids, true ), true, false ) . '> ' . esc_html( $event_ids[ (int) $area->event_id ] . ': ' . $area->name ) . '</label><br>';
			}
			echo '<p class="description">' . esc_html__( 'Ohne Auswahl für ein Event gilt der Pass dort an jedem Eingang.', 'pass-captain' ) . '</p></td></tr>';
		}
		echo '<tr><th scope="row"><label for="wpcap-pass-cancel">' . esc_html__( 'Absage eines Termins', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'on_cancel', array( 'keep' => __( 'Pass bleibt für die übrigen Termine gültig', 'pass-captain' ), 'revoke' => __( 'Ganzen Pass widerrufen (und ggf. erstatten)', 'pass-captain' ) ), $config ? $config->on_cancel : 'keep', 'wpcap-pass-cancel' );
		echo '<p><label>' . esc_html__( 'Teilerstattung pro abgesagtem Termin', 'pass-captain' ) . ' <input type="text" name="refund_per_day" class="small-text" inputmode="decimal" value="' . esc_attr( $config && $config->refund_per_day_minor ? Forms::money_input( $config->refund_per_day_minor ) : '' ) . '"></label></p>';
		echo '<p class="description">' . esc_html__( 'Leer = keine Teilerstattung. Gilt nur, wenn der Pass gültig bleibt; WooCommerce-Bestellungen bitte in WooCommerce erstatten.', 'pass-captain' ) . '</p></td></tr>';
	}

	private static function render_pass( $type ) {
		$type_id = (int) $type->ticket_type_id;
		$config = Definitions::get( $type_id );
		echo '<p><a href="' . esc_url( self::back() ) . '">&larr; ' . esc_html__( 'Alle Pässe', 'pass-captain' ) . '</a></p>';
		echo '<h2>' . esc_html( $type->name ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: 1: event, 2: date */ __( 'Verkauft auf: %1$s am %2$s', 'pass-captain' ), $type->event_name, Forms::datetime( $type->starts_at, $type->timezone ) ) ) . '</p>';
		$available = Orders::availability( $type_id );
		echo '<p>' . esc_html( sprintf( /* translators: 1: sold, 2: available */ __( 'Verkauft/reserviert: %1$d · verfügbar: %2$s', 'pass-captain' ), Definitions::sold_units( $type_id ), null === $available ? __( 'unbegrenzt', 'pass-captain' ) : (string) $available ) ) . '</p>';

		$usage = array();
		foreach ( Admission::usage( $type_id ) as $row ) { $usage[ $row['occurrence_id'] ][] = $row; }
		echo '<h3>' . esc_html__( 'Termine, Kapazität und Einlässe', 'pass-captain' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Termin', 'pass-captain' ), __( 'Freie Plätze', 'pass-captain' ), __( 'Pass-Kontingent', 'pass-captain' ), __( 'Einlässe (Tag: Pässe / Einlässe)', 'pass-captain' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Definitions::linked_occurrences( $type_id ) as $occ ) {
			$left = Capacity::occurrence_remaining( $occ );
			$days = array();
			foreach ( $usage[ (int) $occ->occurrence_id ] ?? array() as $row ) { $days[] = $row['service_date'] . ': ' . $row['tickets'] . ' / ' . $row['entries']; }
			echo '<tr><td>' . esc_html( self::occurrence_label( $occ ) ) . '</td><td>' . esc_html( null === $left ? __( 'unbegrenzt', 'pass-captain' ) : (string) $left ) . '</td><td>';
			Forms::open_form( 'wpcap_pass_quota', 'wpcap_pass_quota', 'class="wpcap-inline"' );
			Forms::hidden( 'occurrence_id', (int) $occ->occurrence_id );
			Forms::hidden( 'ticket_type_id', $type_id );
			$id = 'wpcap-pass-quota-' . (int) $occ->occurrence_id;
			echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Max. Pässe an diesem Termin (0 = ohne)', 'pass-captain' ) . '</label><input type="number" min="0" id="' . esc_attr( $id ) . '" name="pass_quota" class="small-text" value="' . esc_attr( Definitions::quota( (int) $occ->occurrence_id ) ) . '"> <button class="button" type="submit">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></form></td>';
			echo '<td>' . esc_html( $days ? implode( ', ', $days ) : '–' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Pass-Kontingent: höchstens so viele Pässe (aller Pass-Arten) zählen an diesem Termin; 0 = nur die Kapazität des Termins begrenzt.', 'pass-captain' ) . '</p>';

		echo '<h3>' . esc_html__( 'Einstellungen', 'pass-captain' ) . '</h3>';
		Forms::open_form( 'wpcap_pass_save' );
		Forms::hidden( 'ticket_type_id', $type_id );
		echo '<table class="form-table"><tbody>';
		self::render_options( $config, self::selectable_occurrences() );
		echo '</tbody></table>';
		submit_button( __( 'Pass speichern', 'pass-captain' ) );
		echo '</form>';
		if ( ! Definitions::sold_units( $type_id ) ) {
			Forms::open_form( 'wpcap_pass_remove' );
			Forms::hidden( 'ticket_type_id', $type_id );
			echo '<p><button class="button" type="submit">' . esc_html__( 'In normale Ticketart umwandeln', 'pass-captain' ) . '</button></p></form>';
		}
	}
}
