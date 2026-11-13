<?php
/**
 * Admin page "Entwickler-API" (A161): list developer API keys, create one (label, event, scopes,
 * expiry, rate limit), show the secret exactly once, change expiry and rate limit, revoke.
 * Administrators only (manage_options); every action is nonce-checked and recorded in the audit log
 * by DeveloperKeys. Scopes and route map: DeveloperKeys::scopes() / ROUTES, docs/SECURITY.md.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\DeveloperKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DeveloperKeysPage {
	const SLUG = 'wpcap-developer-keys';
	const CAP = 'manage_options';

	/** Transient holding the freshly issued key of the current user (shown once, then deleted). */
	private static function secret_key() { return 'wpcap_devkey_once_' . get_current_user_id(); }

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Site timezone for the expiry fields (named zone, else UTC). */
	public static function timezone() {
		$zone = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
		return Forms::is_valid_timezone( $zone ) ? $zone : 'UTC';
	}

	/** Local datetime-local input → UTC 'Y-m-d H:i:s' ('' = none); WP_Error when unreadable. */
	private static function expiry( array $in ) {
		$local = Forms::text( $in, 'expires_at', 20 );
		if ( '' === $local ) { return ''; }
		$utc = Forms::local_to_utc( $local, self::timezone() );
		return null === $utc ? Forms::error( 'wpcap_api_key_expiry', __( 'Ablaufdatum muss in der Zukunft liegen.', 'pass-captain' ) ) : $utc;
	}

	/** Creates a key from the form; the secret is kept for one page view. */
	public static function create( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$expiry = self::expiry( $in );
		if ( is_wp_error( $expiry ) ) { return $expiry; }
		// Scopes contain dots (events.read): passed as strings, DeveloperKeys keeps only known ones.
		$scopes = isset( $in['scopes'] ) && is_array( $in['scopes'] ) ? array_values( array_filter( $in['scopes'], 'is_string' ) ) : array();
		$result = DeveloperKeys::issue( Forms::int( $in, 'event_id' ), $scopes,Forms::text( $in, 'label', 190 ), $expiry, Forms::int( $in, 'rate_limit' ) );
		if ( ! is_wp_error( $result ) ) {
			set_transient( self::secret_key(), array( 'key' => $result['key'], 'api_key_id' => $result['api_key_id'] ), 10 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	public static function revoke( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		return DeveloperKeys::revoke( Forms::int( $in, 'api_key_id' ) );
	}

	public static function update( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$expiry = self::expiry( $in );
		if ( is_wp_error( $expiry ) ) { return $expiry; }
		return DeveloperKeys::update( Forms::int( $in, 'api_key_id' ), $expiry, Forms::int( $in, 'rate_limit' ) );
	}

	public static function handle_create() {
		$in = Forms::post();
		Forms::verify( 'wpcap_developer_key_create' );
		Forms::finish( self::create( $in ), __( 'API-Key angelegt. Kopiere ihn jetzt – er wird nur dieses eine Mal angezeigt.', 'pass-captain' ), self::url() );
	}

	public static function handle_revoke() {
		$in = Forms::post();
		Forms::verify( 'wpcap_developer_key_revoke_' . Forms::int( $in, 'api_key_id' ) );
		Forms::finish( self::revoke( $in ), __( 'API-Key widerrufen. Anfragen damit werden ab sofort abgelehnt.', 'pass-captain' ), self::url() );
	}

	public static function handle_update() {
		$in = Forms::post();
		Forms::verify( 'wpcap_developer_key_update_' . Forms::int( $in, 'api_key_id' ) );
		Forms::finish( self::update( $in ), __( 'Ablauf und Ratenlimit gespeichert.', 'pass-captain' ), self::url() );
	}

	private static function status_label( $status ) {
		$labels = array(
			'active' => __( 'Aktiv', 'pass-captain' ),
			'expired' => __( 'Abgelaufen', 'pass-captain' ),
			'revoked' => __( 'Widerrufen', 'pass-captain' ),
		);
		return $labels[ $status ] ?? (string) $status;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$zone = self::timezone();
		$filter = Forms::query_arg( 'status' );
		$filter = in_array( $filter, array( 'active', 'expired', 'revoked' ), true ) ? $filter : '';
		$keys = DeveloperKeys::all( $filter );
		$scopes = DeveloperKeys::scopes();
		$preselect = absint( Forms::query_arg( 'event_id', 0 ) );
		$events = Queries::event_options( Queries::event_ids_for( array( 'wpcap_manage_events' ) ), array( $preselect ) );
		$events_note = Queries::event_options_note();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Entwickler-API', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Entwickler-API-Schlüssel geben externen Systemen lesenden Zugriff auf genau ein Event. Jede Anfrage sendet den Schlüssel im Header X-WPCAP-Key. Der Schlüssel gilt nur, solange die Person, die ihn angelegt hat, für das Event berechtigt ist und alle Termine des Events in ihrem Standort- oder Partnerbereich liegen.', 'pass-captain' ) . '</p>';

		$once = get_transient( self::secret_key() );
		if ( is_array( $once ) && ! empty( $once['key'] ) ) {
			delete_transient( self::secret_key() );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Neuer API-Key – wird nur jetzt angezeigt:', 'pass-captain' ) . '</strong></p>';
			echo '<p><input type="text" class="large-text code" readonly onfocus="this.select()" aria-label="' . esc_attr__( 'Neuer API-Key', 'pass-captain' ) . '" value="' . esc_attr( (string) $once['key'] ) . '"></p>';
			echo '<p>' . esc_html__( 'Gespeichert wird nur ein Hash. Geht der Schlüssel verloren, widerrufe ihn und lege einen neuen an.', 'pass-captain' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Neuen API-Key anlegen', 'pass-captain' ) . '</h2>';
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'Es gibt noch kein Event, für das du API-Keys anlegen darfst.', 'pass-captain' ) . '</p>';
		} else {
			Forms::open_form( 'wpcap_developer_key_create' );
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th scope="row"><label for="wpcap-key-label">' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-key-label" name="label" maxlength="190" class="regular-text" placeholder="' . esc_attr__( 'z. B. Ticketstatistik Website', 'pass-captain' ) . '"></td></tr>';
			echo '<tr><th scope="row"><label for="wpcap-key-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label></th><td>';
			Forms::select( 'event_id', $events, $preselect ? (string) $preselect : '', 'wpcap-key-event' );
			echo '<p class="description">' . esc_html__( 'Der Schlüssel funktioniert nur für dieses Event.', 'pass-captain' ) . '</p>' . $events_note . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note.
			echo '<tr><th scope="row">' . esc_html__( 'Scopes', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Scopes', 'pass-captain' ) . '</legend>';
			foreach ( $scopes as $scope => $label ) {
				echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"> <code>' . esc_html( $scope ) . '</code> – ' . esc_html( $label ) . '</label>';
			}
			echo '</fieldset></td></tr>';
			/* translators: %s: timezone */
			$zone_hint = sprintf( __( 'Leer = unbefristet. Zeitzone: %s', 'pass-captain' ), $zone );
			echo '<tr><th scope="row"><label for="wpcap-key-expires">' . esc_html__( 'Läuft ab am', 'pass-captain' ) . '</label></th><td><input type="datetime-local" id="wpcap-key-expires" name="expires_at"><p class="description">' . esc_html( $zone_hint ) . '</p></td></tr>';
			/* translators: %d: default requests per minute */
			$rate_hint = sprintf( __( 'Anfragen pro Minute. Leer = Standard (%d).', 'pass-captain' ), DeveloperKeys::DEFAULT_RATE_LIMIT );
			echo '<tr><th scope="row"><label for="wpcap-key-rate">' . esc_html__( 'Ratenlimit', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-key-rate" name="rate_limit" min="1" max="' . esc_attr( (string) DeveloperKeys::MAX_RATE_LIMIT ) . '" class="small-text"><p class="description">' . esc_html( $rate_hint ) . '</p></td></tr>';
			echo '</tbody></table>';
			submit_button( __( 'API-Key anlegen', 'pass-captain' ), 'primary', 'submit', false );
			echo '</form>';
		}

		echo '<h2>' . esc_html__( 'API-Keys', 'pass-captain' ) . '</h2>';
		$views = array( '' => __( 'Alle', 'pass-captain' ), 'active' => __( 'Aktiv', 'pass-captain' ), 'expired' => __( 'Abgelaufen', 'pass-captain' ), 'revoked' => __( 'Widerrufen', 'pass-captain' ) );
		$links = array();
		foreach ( $views as $key => $label ) {
			$links[] = $key === $filter ? '<strong>' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( self::url( '' === $key ? array() : array( 'status' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<p class="subsubsub">' . implode( ' | ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Präfix', 'pass-captain' ), __( 'Bezeichnung', 'pass-captain' ), __( 'Angelegt von', 'pass-captain' ), __( 'Scopes', 'pass-captain' ), __( 'Event', 'pass-captain' ), __( 'Angelegt', 'pass-captain' ), __( 'Zuletzt genutzt', 'pass-captain' ), __( 'Läuft ab', 'pass-captain' ), __( 'Ratenlimit', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $keys as $key ) {
			$status = DeveloperKeys::status( $key );
			$id = (int) $key->api_key_id;
			$event = $key->event_name ? $key->event_name . ' (#' . (int) $key->event_id . ')' : '#' . (int) $key->event_id;
			/* translators: %d: requests per minute */
			$rate = sprintf( __( '%d/min', 'pass-captain' ), DeveloperKeys::rate_limit( $key ) );
			if ( (int) ( $key->rate_limit ?? 0 ) <= 0 ) {
				$rate .= ' ' . __( '(Standard)', 'pass-captain' );
			}
			echo '<tr><td><code>' . esc_html( $key->public_prefix ) . '…</code></td><td>' . esc_html( '' !== (string) $key->label ? $key->label : '—' ) . '</td><td>' . esc_html( Forms::user_label( $key->user_id ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', DeveloperKeys::key_scopes( $key ) ) ) . '</td><td>' . esc_html( $event ) . '</td>';
			echo '<td>' . esc_html( Forms::datetime( $key->created_at, $zone, false ) ) . '</td><td>' . esc_html( $key->last_used_at ? Forms::datetime( $key->last_used_at, $zone, false ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $key->expires_at ? Forms::datetime( $key->expires_at, $zone, false ) : __( 'unbefristet', 'pass-captain' ) ) . '</td><td>' . esc_html( $rate ) . '</td><td>' . esc_html( self::status_label( $status ) ) . '</td><td>';
			if ( 'revoked' !== $status ) {
				echo '<details><summary>' . esc_html__( 'Ablauf und Ratenlimit ändern', 'pass-captain' ) . '</summary>';
				Forms::open_form( 'wpcap_developer_key_update', 'wpcap_developer_key_update_' . $id );
				Forms::hidden( 'api_key_id', $id );
				echo '<p><label>' . esc_html__( 'Läuft ab am', 'pass-captain' ) . ' <input type="datetime-local" name="expires_at" value="' . esc_attr( Forms::utc_to_local( $key->expires_at, $zone ) ) . '"></label><br><span class="description">' . esc_html__( 'Leer = unbefristet.', 'pass-captain' ) . '</span></p>';
				echo '<p><label>' . esc_html__( 'Ratenlimit (Anfragen pro Minute)', 'pass-captain' ) . ' <input type="number" name="rate_limit" min="1" max="' . esc_attr( (string) DeveloperKeys::MAX_RATE_LIMIT ) . '" class="small-text" value="' . esc_attr( (int) ( $key->rate_limit ?? 0 ) > 0 ? (string) (int) $key->rate_limit : '' ) . '"></label></p>';
				echo '<p><button class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form></details>';
				Forms::open_form( 'wpcap_developer_key_revoke', 'wpcap_developer_key_revoke_' . $id, 'onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'API-Key wirklich widerrufen? Das lässt sich nicht rückgängig machen.', 'pass-captain' ) . '"' );
				Forms::hidden( 'api_key_id', $id );
				echo '<button class="button-link button-link-delete">' . esc_html__( 'Widerrufen', 'pass-captain' ) . '</button></form>';
			} else {
				echo esc_html( Forms::datetime( $key->revoked_at, $zone, false ) );
			}
			echo '</td></tr>';
		}
		if ( ! $keys ) { echo '<tr><td colspan="11">' . esc_html__( 'Keine API-Keys vorhanden.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Endpunkte und Scopes', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Endpunkt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Scope', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( DeveloperKeys::ROUTES as $route => $scope ) {
			echo '<tr><td><code>' . esc_html( str_replace( ' ', ' /wp-json/pass-captain/v1', $route ) ) . '</code></td><td><code>' . esc_html( $scope ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		/* translators: %d: default requests per minute */
		echo '<p class="description">' . esc_html( sprintf( __( 'Standard-Ratenlimit: %d Anfragen pro Minute und Schlüssel (Filter wpcap_developer_key_rate_limit). Über dem Limit antwortet die API mit 429 und Retry-After.', 'pass-captain' ), DeveloperKeys::DEFAULT_RATE_LIMIT ) ) . '</p>';
		echo '</div>';
	}
}
