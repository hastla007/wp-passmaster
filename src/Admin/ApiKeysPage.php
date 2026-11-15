<?php
/**
 * Admin screen "Entwickler-API-Keys": create event-bound read-only keys (secret shown once),
 * list them with prefix, scopes, last use, expiry, rate limit and status, change the rate limit, revoke.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\DeveloperKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ApiKeysPage {
	const SLUG = 'wpcap-api-keys';
	const ACTION = 'wpcap_api_key_action';
	const CAP = 'wpcap_manage_events';

	/** Transient holding the freshly created key of the current user (shown once). */
	public static function new_key_transient() {
		return 'wpcap_api_key_new_' . get_current_user_id();
	}

	/** Core scopes plus the scopes of add-ons (wp_pass_captain_api_scopes_v1). */
	public static function scope_labels() {
		return DeveloperKeys::scopes();
	}

	/**
	 * Executes an admin action (testable without a request).
	 *
	 * @return array|int|true|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = Forms::int( $in, 'id' );
		switch ( $do ) {
			case 'create':
				$event_id = Forms::int( $in, 'event_id' );
				if ( ! $event_id || ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
				$expires = FeatureScreens::optional_local( $in, 'expires_at', FeatureScreens::site_timezone() );
				if ( null === $expires || ( '' !== $expires && $expires <= current_time( 'mysql', true ) ) ) {
					return Forms::error( 'wpcap_api_key_expiry', __( 'Ablaufdatum muss in der Zukunft liegen.', 'pass-captain' ) );
				}
				$scopes = array_values( array_intersect( array_map( 'strval', array_filter( (array) ( $in['scopes'] ?? array() ), 'is_scalar' ) ), array_keys( DeveloperKeys::scopes() ) ) );
				$rate = Forms::int( $in, 'rate_limit', DeveloperKeys::DEFAULT_RATE_LIMIT );
				if ( $rate < 1 || $rate > DeveloperKeys::MAX_RATE_LIMIT ) {
					/* translators: %d: maximum */
					return Forms::error( 'wpcap_api_key_rate', sprintf( __( 'Das Ratenlimit muss zwischen 1 und %d Anfragen pro Minute liegen.', 'pass-captain' ), DeveloperKeys::MAX_RATE_LIMIT ) );
				}
				$result = DeveloperKeys::issue( $event_id, $scopes, Forms::text( $in, 'label' ), $expires, $rate );
				if ( ! is_wp_error( $result ) ) { set_transient( self::new_key_transient(), $result['key'], 5 * MINUTE_IN_SECONDS ); }
				return $result;
			case 'rate_limit':
				$rate = Forms::int( $in, 'rate_limit' );
				if ( $rate < 1 || $rate > DeveloperKeys::MAX_RATE_LIMIT ) {
					/* translators: %d: maximum */
					return Forms::error( 'wpcap_api_key_rate', sprintf( __( 'Das Ratenlimit muss zwischen 1 und %d Anfragen pro Minute liegen.', 'pass-captain' ), DeveloperKeys::MAX_RATE_LIMIT ) );
				}
				return DeveloperKeys::set_rate_limit( $id, $rate );
			case 'revoke':
				return DeveloperKeys::revoke( $id );
		}
		return Forms::error( 'wpcap_api_key_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$do = sanitize_key( $in['do'] ?? '' );
		$messages = array( 'create' => __( 'API-Key erstellt. Bitte jetzt kopieren – er wird nur einmal angezeigt.', 'pass-captain' ), 'rate_limit' => __( 'Ratenlimit gespeichert.', 'pass-captain' ), 'revoke' => __( 'API-Key widerrufen.', 'pass-captain' ) );
		Forms::finish( self::admin_action( $in ), $messages[ $do ] ?? '', Forms::page_url( self::SLUG ) );
	}

	/** Keys visible to the current user: administrators see all, others their own keys of events in scope. */
	public static function keys() {
		global $wpdb;
		$scope = Queries::event_ids_for( self::CAP );
		$where = Queries::scope_sql( $scope, 'k.event_id' );
		if ( null !== $scope ) { $where .= $wpdb->prepare( ' AND k.user_id=%d', get_current_user_id() ); }
		return $wpdb->get_results( "SELECT k.api_key_id,k.user_id,k.public_prefix,k.event_id,k.scopes,k.label,k.rate_limit,k.last_used_at,k.expires_at,k.revoked_at,k.created_at,e.name AS event_name FROM {$wpdb->prefix}wpcap_api_keys k LEFT JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=k.event_id WHERE 1=1{$where} ORDER BY k.revoked_at IS NULL DESC,k.created_at DESC LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope from absint ids, user id prepared.
	}

	public static function status_of( $key ) {
		if ( $key->revoked_at ) { return 'revoked'; }
		if ( $key->expires_at && $key->expires_at <= current_time( 'mysql', true ) ) { return 'expired'; }
		return 'active';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$tz = FeatureScreens::site_timezone();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Entwickler-API-Keys', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Lesende API-Keys für ein einzelnes Event, z. B. für eigene Dashboards. Der Key wird im Header X-WPCAP-Key gesendet. Gespeichert wird nur ein Hash; der vollständige Key wird nach dem Erstellen genau einmal angezeigt.', 'pass-captain' ) . '</p>';
		$new = get_transient( self::new_key_transient() );
		if ( is_string( $new ) && '' !== $new ) {
			delete_transient( self::new_key_transient() );
			echo '<div class="notice notice-warning"><p><label for="wpcap-new-api-key"><strong>' . esc_html__( 'Neuer API-Key (wird nur dieses eine Mal angezeigt):', 'pass-captain' ) . '</strong></label><br><input type="text" id="wpcap-new-api-key" class="large-text code" readonly value="' . esc_attr( $new ) . '" onfocus="this.select()"></p></div>';
		}
		self::render_form( $tz );
		self::render_list( $tz );
		echo '</div>';
	}

	private static function render_form( $tz ) {
		$events = Queries::event_options( Queries::event_ids_for( self::CAP ) );
		echo '<h2>' . esc_html__( 'Neuen API-Key erstellen', 'pass-captain' ) . '</h2>';
		if ( ! $events ) { echo '<p>' . esc_html__( 'Es gibt keine Events, für die du Keys erstellen darfst.', 'pass-captain' ) . '</p>'; return; }
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'create' );
		echo '<table class="form-table"><tbody>';
		FeatureScreens::input_row( 'wpcap-ak-label', 'label', __( 'Name', 'pass-captain' ), '', 'text', 'class="regular-text" maxlength="190" required' );
		FeatureScreens::row( 'wpcap-ak-event', __( 'Event', 'pass-captain' ), static function () use ( $events ) { Forms::select( 'event_id', $events, '', 'wpcap-ak-event', 'required' ); } );
		echo '<tr><th scope="row">' . esc_html__( 'Berechtigungen', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Berechtigungen', 'pass-captain' ) . '</legend>';
		foreach ( self::scope_labels() as $scope => $label ) {
			$id = 'wpcap-ak-scope-' . str_replace( '.', '-', $scope );
			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="scopes[]" value="' . esc_attr( $scope ) . '"' . ( 'events.read' === $scope ? ' checked="checked"' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset></td></tr>';
		/* translators: %s: timezone */
		FeatureScreens::input_row( 'wpcap-ak-expires', 'expires_at', __( 'Gültig bis (optional)', 'pass-captain' ), '', 'datetime-local', '', sprintf( __( 'Leer lassen für unbegrenzte Gültigkeit. Zeitzone: %s', 'pass-captain' ), $tz ) );
		FeatureScreens::input_row( 'wpcap-ak-rate', 'rate_limit', __( 'Ratenlimit (Anfragen pro Minute)', 'pass-captain' ), (string) DeveloperKeys::DEFAULT_RATE_LIMIT, 'number', 'min="1" max="' . (int) DeveloperKeys::MAX_RATE_LIMIT . '" required' );
		echo '</tbody></table>';
		submit_button( __( 'API-Key erstellen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function render_list( $tz ) {
		$keys = self::keys();
		echo '<h2>' . esc_html__( 'Vorhandene API-Keys', 'pass-captain' ) . '</h2>';
		if ( ! $keys ) { echo '<p>' . esc_html__( 'Noch keine API-Keys.', 'pass-captain' ) . '</p>'; return; }
		$labels = self::scope_labels();
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'pass-captain' ), __( 'Präfix', 'pass-captain' ), __( 'Event', 'pass-captain' ), __( 'Berechtigungen', 'pass-captain' ), __( 'Ratenlimit / Min.', 'pass-captain' ), __( 'Zuletzt benutzt', 'pass-captain' ), __( 'Gültig bis', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $keys as $key ) {
			$status = self::status_of( $key );
			$scopes = json_decode( (string) $key->scopes, true );
			$scope_text = implode( ', ', array_map( static function ( $s ) use ( $labels ) { return $labels[ $s ] ?? $s; }, is_array( $scopes ) ? $scopes : array() ) );
			$rate = (int) $key->rate_limit ?: DeveloperKeys::DEFAULT_RATE_LIMIT;
			echo '<tr><td>' . esc_html( '' !== (string) $key->label ? $key->label : '—' ) . '<br><small>' . esc_html( Forms::user_label( $key->user_id ) ) . '</small></td><td><code>' . esc_html( $key->public_prefix ) . '.…</code></td><td>' . esc_html( (string) ( $key->event_name ?? '#' . (int) $key->event_id ) ) . '</td><td>' . esc_html( $scope_text ) . '</td><td>';
			if ( 'revoked' !== $status ) {
				$rid = 'wpcap-ak-rate-' . (int) $key->api_key_id;
				Forms::open_form( self::ACTION, null, 'style="display:inline"' );
				Forms::hidden( 'do', 'rate_limit' );
				Forms::hidden( 'id', (int) $key->api_key_id );
				echo '<label class="screen-reader-text" for="' . esc_attr( $rid ) . '">' . esc_html__( 'Ratenlimit (Anfragen pro Minute)', 'pass-captain' ) . '</label><input type="number" id="' . esc_attr( $rid ) . '" name="rate_limit" min="1" max="' . (int) DeveloperKeys::MAX_RATE_LIMIT . '" value="' . (int) $rate . '" style="width:7em"> <button type="submit" class="button button-small">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></form>';
			} else {
				echo (int) $rate;
			}
			echo '</td><td>' . esc_html( $key->last_used_at ? Forms::datetime( $key->last_used_at, $tz ) : __( 'nie', 'pass-captain' ) ) . '</td><td>' . esc_html( $key->expires_at ? Forms::datetime( $key->expires_at, $tz ) : __( 'unbegrenzt', 'pass-captain' ) ) . '</td><td>' . esc_html( Forms::status_label( $status ) ) . '</td><td>';
			if ( 'revoked' !== $status ) {
				FeatureScreens::button( self::ACTION, array( 'do' => 'revoke', 'id' => (int) $key->api_key_id ), __( 'Widerrufen', 'pass-captain' ), __( 'Diesen API-Key endgültig widerrufen?', 'pass-captain' ), 'button button-small button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
