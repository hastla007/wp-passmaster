<?php
/**
 * Registry and shared helpers of the admin screens for features that used to be REST-only:
 * developer API keys, service resources/slots, waitlists, campaigns and member passes.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FeatureScreens {
	/** admin-post action => handler. */
	public static function handlers() {
		return array(
			ApiKeysPage::ACTION => array( ApiKeysPage::class, 'handle_action' ),
			ServicesPage::ACTION => array( ServicesPage::class, 'handle_action' ),
			WaitlistsPage::ACTION => array( WaitlistsPage::class, 'handle_action' ),
			WaitlistsPage::EXPORT_ACTION => array( WaitlistsPage::class, 'handle_export' ),
			CampaignsPage::ACTION => array( CampaignsPage::class, 'handle_action' ),
			CampaignsPage::CSV_ACTION => array( CampaignsPage::class, 'handle_codes_csv' ),
			MembershipsPage::ACTION => array( MembershipsPage::class, 'handle_action' ),
		);
	}

	/** Submenu pages: [slug, title, capability, callback]. */
	public static function pages() {
		return array(
			array( CampaignsPage::SLUG, __( 'Kampagnen', 'pass-captain' ), CampaignsPage::CAP, array( CampaignsPage::class, 'render' ) ),
			array( WaitlistsPage::SLUG, __( 'Wartelisten', 'pass-captain' ), WaitlistsPage::CAP, array( WaitlistsPage::class, 'render' ) ),
			array( ServicesPage::SLUG, __( 'Ressourcen & Zeitfenster', 'pass-captain' ), ServicesPage::CAP, array( ServicesPage::class, 'render' ) ),
			array( MembershipsPage::SLUG, __( 'Mitgliedspässe', 'pass-captain' ), MembershipsPage::CAP, array( MembershipsPage::class, 'render' ) ),
			array( ApiKeysPage::SLUG, __( 'Entwickler-API-Keys', 'pass-captain' ), ApiKeysPage::CAP, array( ApiKeysPage::class, 'render' ) ),
		);
	}

	/** Site timezone used for date inputs that are not bound to an occurrence. */
	public static function site_timezone() {
		return Scope::site_timezone();
	}

	/**
	 * Optional local datetime input → UTC.
	 *
	 * @return string|null|false '' when empty, UTC string when valid, null when invalid.
	 */
	public static function optional_local( array $in, $key, $timezone ) {
		$raw = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
		if ( '' === $raw ) { return ''; }
		return Forms::local_to_utc( $raw, $timezone );
	}

	/** Small inline POST form with one submit button. */
	public static function button( $action, array $fields, $label, $confirm = '', $class = 'button button-small' ) {
		Forms::open_form( $action, null, 'style="display:inline"' );
		foreach ( $fields as $name => $value ) { Forms::hidden( $name, $value ); }
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . ( '' !== $confirm ? ' data-wpcap-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	/** Ticket type id => "Event – Termin – Ticketart" for the given events (null = all). */
	public static function ticket_type_options( $event_ids ) {
		global $wpdb;
		$scope = Queries::scope_sql( $event_ids, 'e.event_id' );
		$out = array();
		$rows = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.name,o.starts_at,o.timezone,e.name AS event_name FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE 1=1{$scope} ORDER BY e.name,o.starts_at,tt.name LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope built from absint ids.
		foreach ( $rows as $row ) {
			$out[ (int) $row->ticket_type_id ] = $row->event_name . ' – ' . Forms::datetime( $row->starts_at, $row->timezone, false ) . ' – ' . $row->name;
		}
		return $out;
	}

	/** Event id a ticket type belongs to (0 = unknown). */
	public static function event_of_ticket_type( $ticket_type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $ticket_type_id ) ) );
	}

	/** Labelled form-table row around a callback that prints the control. */
	public static function row( $id, $label, callable $control, $description = '' ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		$control();
		if ( '' !== $description ) { echo '<p class="description" id="' . esc_attr( $id ) . '-desc">' . esc_html( $description ) . '</p>'; }
		echo '</td></tr>';
	}

	/** Labelled text-like input row. */
	public static function input_row( $id, $name, $label, $value, $type = 'text', $attrs = '', $description = '' ) {
		self::row(
			$id,
			$label,
			static function () use ( $id, $name, $value, $type, $attrs, $description ) {
				echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( '' !== $description ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : '' ) . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs are literals from this plugin.
			},
			$description
		);
	}
}
