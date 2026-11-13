<?php
/**
 * Admin page "Rabattcodes": create, list (usage statistics), activate/deactivate discount codes.
 * Event-scoped staff with wpcap_manage_events manage the codes of their events; codes for all
 * events need manage_options.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Campaigns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CampaignsPage {
	const SLUG = 'wpcap-campaigns';
	const CAP = 'wpcap_manage_events';

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Whether the current user may manage codes of an event (0 = all events). */
	public static function can_manage( $event_id ) {
		$event_id = absint( $event_id );
		return $event_id ? Access::can_access_event( $event_id, self::CAP ) : current_user_can( 'manage_options' );
	}

	/**
	 * Creates a code from the admin form.
	 *
	 * @param array $in code, name, kind (percent|fixed), value (percent or money), currency, event_id,
	 *                  ticket_type_id, usage_limit, starts_at, ends_at (local, site time zone), priority.
	 * @return array|\WP_Error
	 */
	public static function create( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! self::can_manage( $event_id ) ) {
			return Forms::forbidden( $event_id ? __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) : __( 'Codes für alle Events kann nur die Administration anlegen.', 'pass-captain' ) );
		}
		$type_id = Forms::int( $in, 'ticket_type_id' );
		if ( $type_id ) {
			$type = Queries::ticket_type( $type_id );
			if ( ! $type || (int) $type->event_id !== $event_id ) {
				return Forms::error( 'wpcap_campaign_scope', __( 'Die Ticketart gehört nicht zu diesem Event.', 'pass-captain' ) );
			}
		}
		$kind = Forms::choice( $in, 'kind', array( 'percent', 'fixed' ), 'percent' );
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['currency'] ?? 'EUR' ) ) );
		$value = 'percent' === $kind ? Forms::parse_percent_bp( $in['value'] ?? '' ) : Forms::parse_money( $in['value'] ?? '', Forms::currency_decimals( $currency ) );
		if ( null === $value || false === $value || $value <= 0 ) {
			return Forms::error( 'wpcap_campaign_invalid', __( 'Bitte einen gültigen Rabattwert angeben.', 'pass-captain' ) );
		}
		$starts = trim( (string) ( $in['starts_at'] ?? '' ) );
		$ends = trim( (string) ( $in['ends_at'] ?? '' ) );
		$tz = ( '' !== $starts || '' !== $ends ) ? wp_timezone_string() : 'UTC';
		$result = Campaigns::create( array(
			'code' => (string) ( $in['code'] ?? '' ),
			'name' => Forms::text( $in, 'name' ),
			'kind' => $kind,
			'value' => (int) $value,
			'currency' => $currency,
			'event_id' => $event_id,
			'ticket_type_id' => $type_id,
			'usage_limit' => Forms::int( $in, 'usage_limit' ),
			'priority' => Forms::int( $in, 'priority', 100 ),
			'stacking' => Forms::flag( $in, 'exclusive' ) ? 'exclusive' : 'combinable',
			'starts_at' => '' !== $starts ? Forms::local_to_utc( $starts, $tz ) : '',
			'ends_at' => '' !== $ends ? Forms::local_to_utc( $ends, $tz ) : '',
		) );
		return is_wp_error( $result ) ? Forms::error( $result->get_error_code(), $result->get_error_message() ) : $result;
	}

	/** Activates/deactivates a code. @return true|\WP_Error */
	public static function set_status( array $in ) {
		$campaign = Campaigns::get( Forms::int( $in, 'campaign_id' ) );
		if ( ! $campaign ) { return Forms::error( 'wpcap_campaign_missing', __( 'Rabattcode nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can_manage( (int) $campaign->event_id ) ) { return Forms::forbidden(); }
		return Campaigns::set_status( (int) $campaign->campaign_id, Forms::choice( $in, 'status', array( 'active', 'inactive' ), 'inactive' ) );
	}

	public static function handle_create() {
		Forms::verify( 'wpcap_campaign_create' );
		Forms::finish( self::create( Forms::post() ), __( 'Rabattcode angelegt.', 'pass-captain' ), self::url() );
	}

	public static function handle_status() {
		$in = Forms::post();
		Forms::verify( 'wpcap_campaign_status_' . Forms::int( $in, 'campaign_id' ) );
		Forms::finish( self::set_status( $in ), __( 'Status gespeichert.', 'pass-captain' ), self::url() );
	}

	private static function value_label( $row ) {
		return 'percent' === $row->kind ? Forms::percent_input( (int) $row->discount_value ) . ' %' : Forms::money( (int) $row->discount_value, (string) $row->currency );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$scope = Queries::event_ids_for( array( self::CAP ) );
		$tz = wp_timezone_string();
		echo '<div class="wrap"><h1>' . esc_html__( 'Rabattcodes', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Kunden geben den Code im Checkout ein. Das Feld „Rabattcode“ erscheint nur, wenn für das Event ein aktiver Code existiert. Ein Code für eine Ticketart oder ein Event mindert nur diese Positionen.', 'pass-captain' ) . '</p>';

		$rows = Campaigns::all( $scope );
		echo '<h2>' . esc_html__( 'Vorhandene Codes', 'pass-captain' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Noch keine Rabattcodes.', 'pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'pass-captain' ) . '</th><th>' . esc_html__( 'Name', 'pass-captain' ) . '</th><th>' . esc_html__( 'Gilt für', 'pass-captain' ) . '</th><th>' . esc_html__( 'Rabatt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zeitraum', 'pass-captain' ) . '</th><th>' . esc_html__( 'Nutzung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Rabatt gesamt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$applies = (int) $row->event_id ? (string) $row->event_name . ( (int) $row->ticket_type_id ? ' – ' . (string) $row->ticket_name : '' ) : __( 'alle Events', 'pass-captain' );
				$window = ( $row->starts_at ? Forms::datetime( $row->starts_at, $tz, false ) : '…' ) . ' – ' . ( $row->ends_at ? Forms::datetime( $row->ends_at, $tz, false ) : '…' );
				$usage = (int) $row->used_count . ' / ' . ( (int) $row->usage_limit ? (int) $row->usage_limit : '∞' );
				echo '<tr><td><code>' . esc_html( $row->code ) . '</code></td><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $applies ) . '</td><td>' . esc_html( self::value_label( $row ) ) . ( 'exclusive' === ( $row->stacking ?? 'combinable' ) ? ' <small>(' . esc_html__( 'nicht kombinierbar', 'pass-captain' ) . ')</small>' : '' ) . '</td><td>' . esc_html( $window ) . '</td><td>' . esc_html( $usage ) . ' <small>(' . esc_html( sprintf( /* translators: %d: paid orders */ __( '%d bezahlt', 'pass-captain' ), (int) $row->orders_committed ) ) . ')</small></td><td>' . esc_html( Forms::money( (int) $row->discount_committed, (string) $row->currency ) ) . '</td><td>' . esc_html( 'active' === $row->status ? __( 'aktiv', 'pass-captain' ) : __( 'inaktiv', 'pass-captain' ) ) . '</td><td>';
				if ( self::can_manage( (int) $row->event_id ) ) {
					Forms::open_form( 'wpcap_campaign_status', 'wpcap_campaign_status_' . (int) $row->campaign_id );
					Forms::hidden( 'campaign_id', (int) $row->campaign_id );
					Forms::hidden( 'status', 'active' === $row->status ? 'inactive' : 'active' );
					echo '<button class="button button-small">' . esc_html( 'active' === $row->status ? __( 'Deaktivieren', 'pass-captain' ) : __( 'Aktivieren', 'pass-captain' ) ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Neuen Code anlegen', 'pass-captain' ) . '</h2>';
		Forms::open_form( 'wpcap_campaign_create' );
		$events = Queries::event_options( $scope );
		if ( current_user_can( 'manage_options' ) ) { $events = array( 0 => __( 'Alle Events', 'pass-captain' ) ) + $events; }
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="wpcap-c-code">' . esc_html__( 'Code', 'pass-captain' ) . '</label></th><td><input id="wpcap-c-code" name="code" required maxlength="32" pattern="[A-Za-z0-9_-]{3,32}" style="text-transform:uppercase"> <span class="description">' . esc_html__( '3–32 Zeichen: Buchstaben, Ziffern, - und _', 'pass-captain' ) . '</span></td></tr>';
		echo '<tr><th><label for="wpcap-c-name">' . esc_html__( 'Name (intern)', 'pass-captain' ) . '</label></th><td><input id="wpcap-c-name" name="name" maxlength="190"></td></tr>';
		echo '<tr><th><label for="wpcap-c-kind">' . esc_html__( 'Art', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'kind', array( 'percent' => __( 'Prozent', 'pass-captain' ), 'fixed' => __( 'Fester Betrag', 'pass-captain' ) ), 'percent', 'wpcap-c-kind' );
		echo ' <label for="wpcap-c-value">' . esc_html__( 'Wert', 'pass-captain' ) . '</label> <input id="wpcap-c-value" name="value" inputmode="decimal" size="8" required> <label for="wpcap-c-cur">' . esc_html__( 'Währung', 'pass-captain' ) . '</label> <input id="wpcap-c-cur" name="currency" value="EUR" size="4" maxlength="3"></td></tr>';
		echo '<tr><th><label for="wpcap-c-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'event_id', $events, (int) array_key_first( $events ), 'wpcap-c-event' );
		echo ' <label for="wpcap-c-type">' . esc_html__( 'Ticketart-ID (optional)', 'pass-captain' ) . '</label> <input id="wpcap-c-type" name="ticket_type_id" type="number" min="0" size="6"></td></tr>';
		echo '<tr><th><label for="wpcap-c-limit">' . esc_html__( 'Max. Nutzungen', 'pass-captain' ) . '</label></th><td><input id="wpcap-c-limit" name="usage_limit" type="number" min="0" value="0"> <span class="description">' . esc_html__( '0 = unbegrenzt', 'pass-captain' ) . '</span></td></tr>';
		echo '<tr><th><label for="wpcap-c-start">' . esc_html__( 'Gültig ab / bis', 'pass-captain' ) . '</label></th><td><input id="wpcap-c-start" name="starts_at" type="datetime-local"> – <input id="wpcap-c-end" name="ends_at" type="datetime-local" aria-label="' . esc_attr__( 'Gültig bis', 'pass-captain' ) . '"> <span class="description">' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Ortszeit %s; leer = unbegrenzt', 'pass-captain' ), $tz ) ) . '</span></td></tr>';
		echo '<tr><th><label for="wpcap-c-prio">' . esc_html__( 'Reihenfolge', 'pass-captain' ) . '</label></th><td><input id="wpcap-c-prio" name="priority" type="number" min="0" max="1000" value="100"> <span class="description">' . esc_html__( 'Bei mehreren Codes wird der niedrigere Wert zuerst angewendet.', 'pass-captain' ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Kombinierbar', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="exclusive" value="1"> ' . esc_html__( 'Nicht mit anderen Rabattcodes kombinierbar (gilt nur allein)', 'pass-captain' ) . '</label></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Code anlegen', 'pass-captain' ) . '</button></p></form></div>';
	}
}
