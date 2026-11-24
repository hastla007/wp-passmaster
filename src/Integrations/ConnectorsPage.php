<?php
/**
 * Admin page "Konnektoren" (manage_options): HTTP/JSON connectors (target, auth header, events, field
 * mapping with PII opt-in, test send, delivery log with retry) and calendar subscription feeds.
 * All changes go through the admin-post action wpcap_connector_action (do = save, enable, disable,
 * delete, test, retry, feed_create, feed_rotate, feed_revoke).
 */
namespace PassCaptain\Integrations;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Integrations\HttpJson\Endpoints;
use PassCaptain\Security\Vault;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class ConnectorsPage {
	const SLUG = 'wpcap-connectors';
	const CAP = 'manage_options';
	/** Mapping rows shown in the form (filled rows plus empty ones). */
	const FORM_ROWS = 8;

	/** Mapping rows from the form: mapping_source[] / mapping_key[]. */
	private static function mapping_input( array $in ) {
		$sources = (array) ( $in['mapping_source'] ?? array() );
		$keys = (array) ( $in['mapping_key'] ?? array() );
		$rows = array();
		foreach ( $sources as $i => $source ) {
			$rows[] = array( 'source' => is_scalar( $source ) ? (string) $source : '', 'key' => is_scalar( $keys[ $i ] ?? '' ) ? sanitize_text_field( (string) ( $keys[ $i ] ?? '' ) ) : '' );
		}
		return $rows;
	}

	/** Runs one admin action (capability first). @return array|\WP_Error */
	public static function action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = absint( $in['id'] ?? 0 );
		switch ( $do ) {
			case 'save':
				$result = Endpoints::save( array(
					'name' => (string) ( $in['name'] ?? '' ),
					'url' => is_scalar( $in['url'] ?? '' ) ? (string) ( $in['url'] ?? '' ) : '',
					'auth_header' => is_scalar( $in['auth_header'] ?? '' ) ? (string) ( $in['auth_header'] ?? '' ) : '',
					'secret' => is_scalar( $in['secret'] ?? '' ) ? (string) ( $in['secret'] ?? '' ) : '',
					'clear_secret' => Forms::flag( $in, 'clear_secret' ),
					'events' => array_map( 'strval', (array) ( $in['events'] ?? array() ) ),
					'mapping' => self::mapping_input( $in ),
					'include_pii' => Forms::flag( $in, 'include_pii' ),
					'status' => Forms::flag( $in, 'active' ) ? 'active' : 'disabled',
				), $id );
				if ( is_array( $result ) && $result['pii_dropped'] ) {
					$result['warning'] = __( 'Personenbezogene Felder in der Zuordnung werden ohne Opt-in nicht gesendet.', 'pass-captain' );
				}
				return $result;
			case 'enable':
			case 'disable':
				return Endpoints::set_status( $id, 'enable' === $do ) ? array( 'endpoint_id' => $id ) : self::missing();
			case 'delete':
				return Endpoints::delete( $id ) ? array( 'deleted' => $id ) : self::missing();
			case 'test':
				return Endpoints::test_send( $id );
			case 'retry':
				$r = Endpoints::retry( $id );
				return is_wp_error( $r ) ? $r : array( 'delivery_id' => $id );
			case 'feed_create':
				$scope = Forms::choice( $in, 'scope', CalendarFeeds::SCOPES, 'all' );
				return CalendarFeeds::create( $scope, 'event' === $scope ? Forms::int( $in, 'event_id' ) : Forms::int( $in, 'partner_id' ), Forms::text( $in, 'label' ) );
			case 'feed_rotate':
				return CalendarFeeds::rotate( $id );
			case 'feed_revoke':
				return CalendarFeeds::revoke( $id ) ? array( 'feed_id' => $id ) : self::missing();
		}
		return self::missing();
	}

	private static function missing() {
		return Forms::error( 'wpcap_connector_action', __( 'Unbekannte Aktion oder Eintrag nicht gefunden.', 'pass-captain' ), 404 );
	}

	private static function reveal_key() {
		return 'wpcap_feed_reveal_' . get_current_user_id();
	}

	public static function handle_action() {
		Forms::verify( 'wpcap_connector_action' );
		$in = Forms::post();
		$result = self::action( $in );
		if ( is_array( $result ) && ! empty( $result['token'] ) ) {
			// The feed URL is shown once on the next page view; kept encrypted until then.
			set_transient( self::reveal_key(), Vault::encrypt( (string) $result['url'], 'feed_reveal' ), 5 * MINUTE_IN_SECONDS );
		}
		$messages = array(
			'test' => is_array( $result ) ? sprintf( /* translators: %d: HTTP status code */ __( 'Testereignis gesendet (HTTP %d).', 'pass-captain' ), (int) ( $result['response_code'] ?? 0 ) ) : '',
			'retry' => __( 'Zustellung erfolgreich wiederholt.', 'pass-captain' ),
			'feed_create' => __( 'Kalender-Abo angelegt.', 'pass-captain' ),
			'feed_rotate' => __( 'Neue Abo-URL erzeugt; die bisherige URL ist ungültig.', 'pass-captain' ),
			'feed_revoke' => __( 'Kalender-Abo widerrufen.', 'pass-captain' ),
		);
		Forms::finish( $result, $messages[ sanitize_key( $in['do'] ?? '' ) ] ?? __( 'Gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	private static function button( $action, $id, $label, $confirm = '' ) {
		Forms::open_form( 'wpcap_connector_action', null, 'style="display:inline"' );
		Forms::hidden( 'do', $action );
		Forms::hidden( 'id', $id );
		echo '<button type="submit" class="button button-small"' . ( '' !== $confirm ? ' data-wpcap-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	private static function status_label( $status ) {
		$labels = array(
			'delivered' => __( 'zugestellt', 'pass-captain' ),
			'retrying' => __( 'wird wiederholt', 'pass-captain' ),
			'failed' => __( 'fehlgeschlagen', 'pass-captain' ),
			'pending' => __( 'ausstehend', 'pass-captain' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Konnektoren', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$stored = get_transient( self::reveal_key() );
		if ( is_string( $stored ) && '' !== $stored ) {
			delete_transient( self::reveal_key() );
			$url = (string) Vault::decrypt( $stored, 'feed_reveal' );
			if ( '' !== $url ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Abo-URL – jetzt kopieren, sie wird nicht noch einmal angezeigt:', 'pass-captain' ) . ' <code>' . esc_html( $url ) . '</code><br><code>' . esc_html( (string) preg_replace( '#^https?://#', 'webcal://', $url ) ) . '</code></p></div>';
			}
		}
		self::render_endpoints();
		self::render_log();
		self::render_feeds();
		echo '</div>';
	}

	private static function render_endpoints() {
		$endpoints = Endpoints::all();
		$labels = Endpoints::event_labels();
		echo '<h2>' . esc_html__( 'HTTP/JSON-Connectors', 'pass-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sendet gewählte Ereignisse als JSON-POST an einen HTTPS-Endpunkt (z. B. CRM, Zapier, Make, n8n). Zustellung über die Hintergrund-Warteschlange mit Wiederholungen; jede Zustellung trägt einen gleichbleibenden Idempotency-Key. Ohne Feldzuordnung werden nur IDs und Status gesendet.', 'pass-captain' ) . '</p>';
		if ( $endpoints ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ziel-URL', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ereignisse', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $endpoints as $e ) {
				$events = array_map( static function ( $ev ) use ( $labels ) { return $labels[ $ev ] ?? $ev; }, $e->events );
				echo '<tr><td>' . esc_html( $e->name ) . ( $e->include_pii ? '<br><em>' . esc_html__( 'mit personenbezogenen Daten', 'pass-captain' ) . '</em>' : '' ) . '</td><td><code>' . esc_html( $e->url ) . '</code></td><td>' . esc_html( implode( ', ', $events ) ) . '</td><td>' . esc_html( 'active' === $e->status ? __( 'aktiv', 'pass-captain' ) : __( 'deaktiviert', 'pass-captain' ) ) . '</td><td>';
				echo '<a class="button button-small" href="' . esc_url( Forms::page_url( self::SLUG, array( 'edit' => $e->endpoint_id ) ) ) . '">' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</a> ';
				self::button( 'test', $e->endpoint_id, __( 'Test senden', 'pass-captain' ) );
				self::button( 'active' === $e->status ? 'disable' : 'enable', $e->endpoint_id, 'active' === $e->status ? __( 'Deaktivieren', 'pass-captain' ) : __( 'Aktivieren', 'pass-captain' ) );
				self::button( 'delete', $e->endpoint_id, __( 'Löschen', 'pass-captain' ), __( 'Connector und Zustellprotokoll löschen?', 'pass-captain' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$edit = Endpoints::get( absint( Forms::query_arg( 'edit', 0 ) ) );
		self::render_form( $edit );
	}

	private static function render_form( $edit ) {
		$sources = array( '' => __( '— Feld wählen —', 'pass-captain' ) );
		foreach ( Endpoints::sources() as $key => $def ) { $sources[ $key ] = $def[0] . ( $def[1] ? ' ' . __( '(personenbezogen)', 'pass-captain' ) : '' ); }
		echo '<h3>' . esc_html( $edit ? __( 'Connector bearbeiten', 'pass-captain' ) : __( 'Neuer Connector', 'pass-captain' ) ) . '</h3>';
		Forms::open_form( 'wpcap_connector_action' );
		Forms::hidden( 'do', 'save' );
		Forms::hidden( 'id', $edit ? $edit->endpoint_id : 0 );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-cn-name">' . esc_html__( 'Name', 'pass-captain' ) . '</label></th><td><input id="wpcap-cn-name" name="name" class="regular-text" required value="' . esc_attr( $edit ? $edit->name : '' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-cn-url">' . esc_html__( 'Ziel-URL (HTTPS)', 'pass-captain' ) . '</label></th><td><input type="url" id="wpcap-cn-url" name="url" class="large-text" required pattern="https://.+" value="' . esc_attr( $edit ? $edit->url : '' ) . '"><p class="description">' . esc_html__( 'Nur öffentliche HTTPS-Ziele; interne Adressen und Weiterleitungen werden abgelehnt.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-cn-header">' . esc_html__( 'Auth-Header', 'pass-captain' ) . '</label></th><td><input id="wpcap-cn-header" name="auth_header" value="' . esc_attr( $edit ? $edit->auth_header : 'Authorization' ) . '"> ';
		echo '<label for="wpcap-cn-secret" class="screen-reader-text">' . esc_html__( 'Header-Wert', 'pass-captain' ) . '</label><input type="password" id="wpcap-cn-secret" name="secret" autocomplete="new-password" class="regular-text" placeholder="' . esc_attr( $edit && '' !== (string) $edit->secret ? Vault::mask( Endpoints::secret( $edit ) ) : __( 'z. B. Bearer …', 'pass-captain' ) ) . '">';
		if ( $edit && '' !== (string) $edit->secret ) { echo ' <label><input type="checkbox" name="clear_secret" value="1"> ' . esc_html__( 'Header-Wert entfernen', 'pass-captain' ) . '</label>'; }
		echo '<p class="description">' . esc_html__( 'Der Header-Wert wird verschlüsselt gespeichert und nicht wieder angezeigt; leer lassen, um ihn zu behalten.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Ereignisse', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Ereignisse', 'pass-captain' ) . '</legend>';
		foreach ( Endpoints::event_labels() as $key => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="events[]" value="' . esc_attr( $key ) . '"' . checked( $edit ? in_array( $key, $edit->events, true ) : 'order_paid' === $key, true, false ) . '> ' . esc_html( $label ) . ' <code>' . esc_html( $key ) . '</code></label>';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Feldzuordnung', 'pass-captain' ) . '</th><td><p class="description">' . esc_html__( 'Welches Feld unter welchem JSON-Schlüssel in „data“ gesendet wird (Punkt für Verschachtelung, z. B. customer.email). Ticket- und Teilnehmerfelder landen je Ticket in „data.tickets“. Leer: nur IDs und Status.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat" style="max-width:48rem"><thead><tr><th scope="col">' . esc_html__( 'Feld', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'JSON-Schlüssel', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$rows = $edit ? $edit->mapping : array();
		$row_count = max( self::FORM_ROWS, count( $rows ) + 3 );
		for ( $i = 0; $i < $row_count; $i++ ) {
			$row = $rows[ $i ] ?? array( 'source' => '', 'key' => '' );
			echo '<tr><td>';
			/* translators: %d: row number */
			Forms::select( 'mapping_source[]', $sources, $row['source'], '', Forms::aria_label( sprintf( __( 'Feld, Zeile %d', 'pass-captain' ), $i + 1 ) ) );
			/* translators: %d: row number */
			echo '</td><td><input name="mapping_key[]" value="' . esc_attr( $row['key'] ) . '" pattern="[A-Za-z_][A-Za-z0-9_.]*" ' . Forms::aria_label( sprintf( __( 'JSON-Schlüssel, Zeile %d', 'pass-captain' ), $i + 1 ) ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aria_label() escapes.
		}
		echo '</tbody></table></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Personenbezogene Daten', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="include_pii" value="1"' . checked( $edit && $edit->include_pii, true, false ) . '> ' . esc_html__( 'E-Mail-Adressen und Teilnehmernamen senden (Opt-in; Auftragsverarbeitung mit dem Empfänger prüfen)', 'pass-captain' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="active" value="1"' . checked( ! $edit || 'active' === $edit->status, true, false ) . '> ' . esc_html__( 'Aktiv', 'pass-captain' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Connector speichern', 'pass-captain' ) );
		echo '</form>';
	}

	private static function render_log() {
		$rows = Endpoints::deliveries( 0, 50 );
		$endpoints = Endpoints::all();
		echo '<h2>' . esc_html__( 'Zustellprotokoll', 'pass-captain' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Noch keine Zustellungen.', 'pass-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Connector', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ereignis', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Versuche', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Antwort', 'pass-captain' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( Forms::datetime( $row->updated_at, wp_timezone_string() ) ) . '</td><td>' . esc_html( isset( $endpoints[ (int) $row->endpoint_id ] ) ? $endpoints[ (int) $row->endpoint_id ]->name : '#' . (int) $row->endpoint_id ) . '</td><td><code>' . esc_html( $row->event ) . '</code></td><td>' . esc_html( self::status_label( $row->status ) ) . '</td><td>' . (int) $row->attempts . '</td><td>' . ( (int) $row->response_code ? 'HTTP ' . (int) $row->response_code : '—' ) . ( '' !== (string) $row->last_error ? '<br><small>' . esc_html( $row->last_error ) . '</small>' : '' ) . '</td><td>';
			if ( in_array( $row->status, array( 'retrying', 'failed' ), true ) && 'test' !== $row->event ) { self::button( 'retry', $row->delivery_id, __( 'Jetzt wiederholen', 'pass-captain' ) ); }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_feeds() {
		$feeds = CalendarFeeds::all();
		$scopes = CalendarFeeds::scope_labels();
		$events = Queries::event_options( null );
		$partners = array();
		if ( class_exists( '\\PassCaptain\\Partners\\Repository' ) ) {
			foreach ( (array) \PassCaptain\Partners\Repository::all() as $partner ) { $partners[ (int) $partner->partner_id ] = (string) $partner->name; }
		}
		echo '<h2>' . esc_html__( 'Kalender-Abos (iCalendar)', 'pass-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Geheime Abo-URLs für Kalender-Apps, CRM oder Intranet. Verschobene und abgesagte Termine werden beim nächsten Abruf aktualisiert. Enthält nur Eventdaten, keine Käufer- oder Teilnehmerdaten. Entwürfe werden nie ausgegeben.', 'pass-captain' ) . '</p>';
		if ( $feeds ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">ID</th><th scope="col">' . esc_html__( 'Umfang', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Letzter Abruf', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $feeds as $feed ) {
				$scope = $scopes[ $feed->scope ] ?? $feed->scope;
				if ( 'event' === $feed->scope ) { $scope .= ': ' . ( $events[ (int) $feed->scope_id ] ?? '#' . (int) $feed->scope_id ); }
				if ( 'partner' === $feed->scope ) { $scope .= ': ' . ( $partners[ (int) $feed->scope_id ] ?? '#' . (int) $feed->scope_id ); }
				echo '<tr><td>' . (int) $feed->feed_id . '</td><td>' . esc_html( $scope ) . '</td><td>' . esc_html( $feed->label ) . '</td><td>' . esc_html( $feed->last_access_at ? Forms::datetime( $feed->last_access_at, wp_timezone_string() ) : '—' ) . '</td><td>' . esc_html( 'active' === $feed->status ? __( 'aktiv', 'pass-captain' ) : __( 'widerrufen', 'pass-captain' ) ) . '</td><td>';
				if ( 'active' === $feed->status ) {
					self::button( 'feed_rotate', $feed->feed_id, __( 'Neue URL', 'pass-captain' ), __( 'Neue Abo-URL erzeugen? Die bisherige URL funktioniert dann nicht mehr.', 'pass-captain' ) );
					self::button( 'feed_revoke', $feed->feed_id, __( 'Widerrufen', 'pass-captain' ), __( 'Kalender-Abo widerrufen?', 'pass-captain' ) );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h3>' . esc_html__( 'Neues Kalender-Abo', 'pass-captain' ) . '</h3>';
		Forms::open_form( 'wpcap_connector_action' );
		Forms::hidden( 'do', 'feed_create' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-feed-scope">' . esc_html__( 'Umfang', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'scope', $scopes, 'all', 'wpcap-feed-scope' );
		echo '</td></tr><tr><th scope="row"><label for="wpcap-feed-event">' . esc_html__( 'Event', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'event_id', array( 0 => '—' ) + $events, 0, 'wpcap-feed-event' );
		echo '</td></tr><tr><th scope="row"><label for="wpcap-feed-partner">' . esc_html__( 'Veranstalter', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'partner_id', array( 0 => '—' ) + $partners, 0, 'wpcap-feed-partner' );
		echo '</td></tr><tr><th scope="row"><label for="wpcap-feed-label">' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</label></th><td><input id="wpcap-feed-label" name="label" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Abo-URL erzeugen', 'pass-captain' ) );
		echo '</form>';
	}
}
