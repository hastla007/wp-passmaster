<?php
/**
 * Admin page "Offline-Scanner": per-event opt-in and rules, device assignment for offline use,
 * signing key status and rotation (manage_options), link to the installable PWA scanner.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-offline-scanner';
	const CAP = 'wpcap_manage_events';

	private static function can( $event_id ) {
		return current_user_can( self::CAP ) && Access::can_access_event( (int) $event_id, self::CAP );
	}

	/**
	 * URL of the PWA; the API base is passed so the app works in subdirectory installs, the language
	 * (WordPress user locale) so the app speaks it – it remembers it for offline starts.
	 */
	public static function app_url() {
		$base = defined( 'WPCAP_URL' ) ? WPCAP_URL : '';
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		return $base . 'assets/offline-scanner/index.html?api=' . rawurlencode( rest_url( 'wp-pass-captain/v1/app/' ) ) . '&lang=' . rawurlencode( strtolower( substr( (string) $locale, 0, 2 ) ) );
	}

	public static function save_rules( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! $event_id || ! Queries::event( $event_id ) ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can( $event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		$rules = Rules::save( $event_id, array(
			'enabled' => Forms::flag( $in, 'enabled' ),
			'max_offline_hours' => Forms::int( $in, 'max_offline_hours', 12 ),
			'duplicate_policy' => Forms::choice( $in, 'duplicate_policy', Rules::POLICIES, '' ),
			'include_attendee' => Forms::flag( $in, 'include_attendee' ),
		) );
		if ( is_wp_error( $rules ) ) { return $rules; }
		$out = array( 'event_id' => $event_id, 'rules' => $rules );
		if ( $rules['include_attendee'] && ! Settings::get( 'scanner_show_attendee' ) ) {
			$out['warning'] = __( 'Teilnehmernamen werden erst übertragen, wenn „Namen im Scanner anzeigen“ in den Einstellungen aktiviert ist.', 'wp-pass-captain' );
		}
		return $out;
	}

	public static function assign( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! $event_id || ! Queries::event( $event_id ) ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can( $event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		return Assignments::assign( Forms::int( $in, 'device_id' ), $event_id, Forms::int( $in, 'occurrence_id' ) );
	}

	public static function unassign( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! self::can( $event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		return Assignments::unassign( Forms::int( $in, 'device_id' ), $event_id, Forms::int( $in, 'occurrence_id' ) );
	}

	public static function rotate_key( array $in = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) { return Forms::forbidden(); }
		return Keys::rotate( 'manual' );
	}

	/* ---- admin-post wrappers ---- */

	private static function back( $result, array $in ) {
		$event_id = is_array( $result ) && ! empty( $result['event_id'] ) ? (int) $result['event_id'] : Forms::int( $in, 'event_id' );
		return Forms::page_url( self::SLUG, $event_id ? array( 'event_id' => $event_id ) : array() );
	}

	public static function handle_save_rules() {
		$in = Forms::post();
		Forms::verify( 'wpcap_offline_save_rules_' . Forms::int( $in, 'event_id' ) );
		$result = self::save_rules( $in );
		Forms::finish( $result, __( 'Offline-Regeln gespeichert.', 'wp-pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_assign() {
		$in = Forms::post();
		Forms::verify( 'wpcap_offline_assign_' . Forms::int( $in, 'event_id' ) );
		$result = self::assign( $in );
		Forms::finish( $result, __( 'Gerät zugewiesen.', 'wp-pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_unassign() {
		$in = Forms::post();
		Forms::verify( 'wpcap_offline_unassign_' . Forms::int( $in, 'device_id' ) . '_' . Forms::int( $in, 'event_id' ) . '_' . Forms::int( $in, 'occurrence_id' ) );
		$result = self::unassign( $in );
		Forms::finish( $result, __( 'Zuweisung entfernt.', 'wp-pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_rotate_key() {
		$in = Forms::post();
		Forms::verify( 'wpcap_offline_rotate_key' );
		Forms::finish( self::rotate_key( $in ), __( 'Neuer Signaturschlüssel erzeugt. Der bisherige öffentliche Schlüssel bleibt bis zum Ablauf seiner Pakete gültig.', 'wp-pass-captain' ), Forms::page_url( self::SLUG, array( 'event_id' => Forms::int( $in, 'event_id' ) ) ) );
	}

	/* ---- rendering ---- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$events = Queries::event_options( Queries::event_ids_for( array( self::CAP ) ) );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( $event_id && ! isset( $events[ $event_id ] ) ) { $event_id = 0; }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Offline-Scanner', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<p><?php esc_html_e( 'Der Offline-Scanner ist eine installierbare Web-App (PWA). Für freigegebene Events lädt ein gekoppeltes und zugewiesenes Gerät ein signiertes Prüfpaket (nur Hashes der Ticketcodes) und kann damit ohne Netz einlassen. Scans werden später über die Offline-Synchronisierung hochgeladen; Konflikte erscheinen unter „Einlass-Konflikte“.', 'wp-pass-captain' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( self::app_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Offline-Scanner öffnen', 'wp-pass-captain' ); ?></a>
		<a class="button" href="<?php echo esc_url( Forms::page_url( \PassCaptain\Admin\DevicesPage::SLUG ) ); ?>"><?php esc_html_e( 'Gerät koppeln', 'wp-pass-captain' ); ?></a></p>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Event wählen …', 'wp-pass-captain' ) ) + $events, $event_id ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Anzeigen', 'wp-pass-captain' ); ?></button>
		</form>
		<?php if ( $event_id ) { self::render_event( $event_id ); } ?>
		<?php if ( current_user_can( 'manage_options' ) ) { self::render_keys( $event_id ); } ?>
		</div>
		<?php
	}

	private static function render_event( $event_id ) {
		$rules = Rules::get( $event_id );
		$occurrences = Queries::occurrences_for_event( $event_id );
		$occ_labels = array( 0 => __( 'Alle Termine', 'wp-pass-captain' ) );
		foreach ( $occurrences as $occ ) { $occ_labels[ (int) $occ->occurrence_id ] = Forms::datetime( $occ->starts_at, $occ->timezone ); }
		$assigned = Assignments::for_event( $event_id );
		$candidates = Assignments::candidates( $event_id );
		$show_names = (bool) Settings::get( 'scanner_show_attendee' );
		?>
		<h2><?php esc_html_e( 'Freigabe und Regeln', 'wp-pass-captain' ); ?></h2>
		<?php Forms::open_form( 'wpcap_offline_save_rules', 'wpcap_offline_save_rules_' . $event_id ); Forms::hidden( 'event_id', $event_id ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Offline-Scannen', 'wp-pass-captain' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $rules['enabled'] ); ?>> <?php esc_html_e( 'Für dieses Event erlauben (zugewiesene Geräte erhalten signierte Prüfpakete)', 'wp-pass-captain' ); ?></label></td></tr>
				<tr><th scope="row"><label for="wpcap-offline-hours"><?php esc_html_e( 'Maximale Offline-Dauer (Stunden)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-offline-hours" type="number" name="max_offline_hours" min="1" max="<?php echo esc_attr( (string) Rules::MAX_HOURS ); ?>" value="<?php echo esc_attr( (string) $rules['max_offline_hours'] ); ?>" style="width:6em">
					<p class="description"><?php esc_html_e( 'Ein Prüfpaket läuft nach dieser Zeit ab; ohne Aktualisierung lässt das Gerät dann niemanden mehr offline ein. Hochgeladene Offline-Scans, die älter sind, werden zur Prüfung markiert.', 'wp-pass-captain' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Doppelscans auf mehreren Geräten', 'wp-pass-captain' ); ?></th><td><?php foreach ( Rules::policy_labels() as $key => $label ) : ?><label style="display:block"><input type="radio" name="duplicate_policy" value="<?php echo esc_attr( $key ); ?>" <?php checked( $rules['duplicate_policy'], $key ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Ticketdaten im Paket', 'wp-pass-captain' ); ?></th><td><p><?php esc_html_e( 'Immer: SHA-256-Hash des Ticketcodes, Status, Tickettyp, erlaubte Bereiche.', 'wp-pass-captain' ); ?></p>
					<label><input type="checkbox" name="include_attendee" value="1" <?php checked( $rules['include_attendee'] ); ?>> <?php esc_html_e( 'Teilnehmernamen mitsenden', 'wp-pass-captain' ); ?></label>
					<?php if ( ! $show_names ) : ?><p class="description"><?php esc_html_e( 'Wirkt nur, wenn „Namen im Scanner anzeigen“ in den Einstellungen aktiv ist (derzeit aus).', 'wp-pass-captain' ); ?></p><?php endif; ?></td></tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Speichern', 'wp-pass-captain' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Geräte für den Offline-Einsatz', 'wp-pass-captain' ); ?></h2>
		<?php if ( ! $rules['enabled'] ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Offline-Scannen ist für dieses Event nicht freigegeben – zugewiesene Geräte erhalten keine Pakete.', 'wp-pass-captain' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Gerät', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Termin', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Letztes Paket', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $assigned as $row ) :
			$active = ! $row->revoked_at && ( ! $row->expires_at || $row->expires_at > current_time( 'mysql', true ) );
			?>
			<tr><td><?php echo esc_html( ( $row->label ?: __( 'Ohne Namen', 'wp-pass-captain' ) ) . ' (#' . (int) $row->device_id . ')' ); ?></td>
			<td><?php echo esc_html( $occ_labels[ (int) $row->occurrence_id ] ?? '#' . (int) $row->occurrence_id ); ?></td>
			<td><?php echo esc_html( $row->last_package_at ? Forms::datetime( $row->last_package_at, wp_timezone_string() ) : '—' ); ?></td>
			<td><?php echo esc_html( $row->revoked_at ? __( 'widerrufen', 'wp-pass-captain' ) : ( $active ? __( 'aktiv', 'wp-pass-captain' ) : __( 'abgelaufen', 'wp-pass-captain' ) ) ); ?></td>
			<td><?php Forms::open_form( 'wpcap_offline_unassign', 'wpcap_offline_unassign_' . (int) $row->device_id . '_' . $event_id . '_' . (int) $row->occurrence_id ); Forms::hidden( 'event_id', $event_id ); Forms::hidden( 'device_id', $row->device_id ); Forms::hidden( 'occurrence_id', $row->occurrence_id ); ?><button class="button"><?php esc_html_e( 'Entfernen', 'wp-pass-captain' ); ?></button></form></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $assigned ) : ?><tr><td colspan="5"><?php esc_html_e( 'Noch keine Geräte zugewiesen.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php if ( $candidates ) : ?>
			<?php Forms::open_form( 'wpcap_offline_assign', 'wpcap_offline_assign_' . $event_id ); Forms::hidden( 'event_id', $event_id ); ?>
				<p><label><?php esc_html_e( 'Gerät', 'wp-pass-captain' ); ?> <select name="device_id"><?php foreach ( $candidates as $device ) : ?><option value="<?php echo esc_attr( $device->device_id ); ?>"><?php echo esc_html( ( $device->label ?: __( 'Ohne Namen', 'wp-pass-captain' ) ) . ' · ' . Forms::user_label( (int) $device->user_id ) . ' (#' . (int) $device->device_id . ')' ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Termin', 'wp-pass-captain' ); ?> <?php Forms::select( 'occurrence_id', $occ_labels, 0 ); ?></label>
				<button class="button button-primary"><?php esc_html_e( 'Zuweisen', 'wp-pass-captain' ); ?></button></p>
			</form>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Keine aktiven, für dieses Event gekoppelten Geräte. Zuerst unter „Scanner-Geräte“ einen Kopplungscode für dieses Event erstellen und im Offline-Scanner eingeben.', 'wp-pass-captain' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private static function render_keys( $event_id ) {
		$keys = Keys::public_keys();
		?>
		<h2><?php esc_html_e( 'Signaturschlüssel (Ed25519)', 'wp-pass-captain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Prüfpakete werden mit diesem Schlüssel signiert. Der geheime Schlüssel verlässt den Server nie. Nach einer Rotation bleibt der bisherige öffentliche Schlüssel gültig, bis alle damit signierten Pakete abgelaufen sind.', 'wp-pass-captain' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Schlüssel-ID', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Öffentlicher Schlüssel', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Gültig bis', 'wp-pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $keys as $key ) : ?>
			<tr><td><code><?php echo esc_html( $key['key_id'] ); ?></code></td><td><code style="font-size:11px"><?php echo esc_html( $key['public_key'] ); ?></code></td><td><?php echo esc_html( 'active' === $key['status'] ? __( 'aktiv', 'wp-pass-captain' ) : __( 'in Übergangsfrist', 'wp-pass-captain' ) ); ?></td><td><?php echo esc_html( $key['valid_until'] ? Forms::datetime( gmdate( 'Y-m-d H:i:s', strtotime( $key['valid_until'] ) ), wp_timezone_string() ) : '—' ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php Forms::open_form( 'wpcap_offline_rotate_key', 'wpcap_offline_rotate_key', 'onsubmit="return confirm(\'' . esc_js( __( 'Neuen Signaturschlüssel erzeugen?', 'wp-pass-captain' ) ) . '\')"' ); Forms::hidden( 'event_id', $event_id ); ?>
			<p><button class="button"><?php esc_html_e( 'Schlüssel rotieren', 'wp-pass-captain' ); ?></button></p>
		</form>
		<?php
	}
}
