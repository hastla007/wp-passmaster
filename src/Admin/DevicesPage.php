<?php
/**
 * Scanner devices for the mobile app: create a one-time pairing QR, list and revoke devices.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Admission\Areas;
use PassCaptain\Mobile\Devices;
use PassCaptain\QrCode;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DevicesPage {
	const SLUG = 'wpcap-devices';
	const CAP = 'wpcap_checkin';

	/** Transient key holding the freshly created pairing (shown once). */
	private static function pairing_key() { return 'wpcap_pairing_' . get_current_user_id(); }

	/** Creates a pairing for the current user. */
	public static function create_pairing( array $in ) {
		$events = isset( $in['event_ids'] ) && is_array( $in['event_ids'] ) ? $in['event_ids'] : array();
		$result = Devices::create_pairing( $events, Forms::text( $in, 'label', 190 ), Forms::int( $in, 'hours' ), Forms::int( $in, 'area_id' ) );
		if ( ! is_wp_error( $result ) ) {
			set_transient( self::pairing_key(), $result, Devices::PAIRING_MINUTES * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	public static function revoke_device( array $in ) {
		return Devices::revoke( Forms::int( $in, 'device_id' ) );
	}

	public static function handle_create_pairing() {
		$in = Forms::post();
		Forms::verify( 'wpcap_create_pairing' );
		Forms::finish( self::create_pairing( $in ), __( 'Kopplungscode erstellt. Jetzt mit der App scannen.', 'wp-pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function handle_revoke_device() {
		$in = Forms::post();
		Forms::verify( 'wpcap_revoke_device_' . Forms::int( $in, 'device_id' ) );
		Forms::finish( self::revoke_device( $in ), __( 'Gerät widerrufen.', 'wp-pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		global $wpdb;
		$scope = Queries::event_ids_for( array( self::CAP ) );
		$events = $wpdb->get_results( "SELECT event_id,name FROM {$wpdb->prefix}wpcap_events WHERE status='published'" . Queries::scope_sql( $scope, 'event_id' ) . ' ORDER BY name LIMIT 200' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope_sql builds an integer list.
		$pairing = get_transient( self::pairing_key() );
		$devices = Devices::list_for_current_user();
		$names = array();
		foreach ( $events as $event ) { $names[ (int) $event->event_id ] = $event->name; }
		$max = max( 1, (int) \PassCaptain\Settings::get( 'device_max_hours' ) );
		$areas = Areas::selectable( null === $scope ? null : $scope );
		$status = Areas::device_status( array_map( static function ( $d ) { return (int) $d->device_id; }, $devices ) );
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Scanner-Geräte', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<p><?php esc_html_e( 'Koppelt eine Scanner-App mit deinem Konto. Das Gerät erhält einen persönlichen, ablaufenden Zugang nur für die gewählten Events und kann jederzeit widerrufen werden.', 'wp-pass-captain' ); ?></p>
		<?php if ( is_array( $pairing ) ) : $qr = QrCode::data_uri( $pairing['qr_payload'] ); ?>
			<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Kopplungscode (nur einmal gültig):', 'wp-pass-captain' ); ?></strong> <code><?php echo esc_html( implode( '-', str_split( $pairing['code'], 4 ) ) ); ?></code><br><?php echo esc_html( sprintf( /* translators: %s: time */ __( 'Gültig bis %s.', 'wp-pass-captain' ), Forms::datetime( $pairing['expires_at'], wp_timezone_string() ) ) ); ?></p>
			<?php if ( $qr ) : ?><p><img src="<?php echo esc_attr( $qr ); ?>" width="220" height="220" alt="<?php esc_attr_e( 'Kopplungs-QR-Code', 'wp-pass-captain' ); ?>"></p><?php else : ?><p><?php esc_html_e( 'QR-Bibliothek nicht installiert – den Code in der App manuell eingeben.', 'wp-pass-captain' ); ?></p><?php endif; ?></div>
			<?php delete_transient( self::pairing_key() ); ?>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Neues Gerät koppeln', 'wp-pass-captain' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpcap_create_pairing"><?php wp_nonce_field( 'wpcap_create_pairing' ); ?>
			<fieldset><legend><?php esc_html_e( 'Events', 'wp-pass-captain' ); ?></legend>
				<?php foreach ( $events as $event ) : ?><label style="display:block"><input type="checkbox" name="event_ids[]" value="<?php echo esc_attr( $event->event_id ); ?>"> <?php echo esc_html( $event->name ); ?></label><?php endforeach; ?>
				<?php if ( ! $events ) : ?><p><?php esc_html_e( 'Keine veröffentlichten Events mit Einlassberechtigung.', 'wp-pass-captain' ); ?></p><?php endif; ?>
			</fieldset>
			<?php if ( $areas ) : ?><p><label><?php esc_html_e( 'Einlassbereich', 'wp-pass-captain' ); ?> <select name="area_id"><option value="0"><?php esc_html_e( 'Kein fester Bereich', 'wp-pass-captain' ); ?></option><?php foreach ( $areas as $area ) : ?><option value="<?php echo esc_attr( $area->area_id ); ?>"><?php echo esc_html( $area->event_name . ' – ' . $area->name ); ?></option><?php endforeach; ?></select></label></p><?php endif; ?>
			<p><label><?php esc_html_e( 'Gerätename', 'wp-pass-captain' ); ?> <input type="text" name="label" maxlength="190" placeholder="<?php esc_attr_e( 'z. B. Einlass Nord', 'wp-pass-captain' ); ?>"></label>
			<label><?php esc_html_e( 'Gültig (Stunden)', 'wp-pass-captain' ); ?> <input type="number" name="hours" min="1" max="<?php echo esc_attr( $max ); ?>" value="<?php echo esc_attr( $max ); ?>"></label></p>
			<?php submit_button( __( 'Kopplungs-QR erstellen', 'wp-pass-captain' ), 'primary', 'submit', false ); ?>
		</form>
		<h2><?php esc_html_e( 'Gekoppelte Geräte', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Gerät', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Person', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Events', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Bereich', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Zuletzt aktiv', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Letzte Synchronisierung', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Offene Konflikte', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $devices as $device ) :
			$active = ! $device->revoked_at && ( ! $device->expires_at || $device->expires_at > current_time( 'mysql', true ) );
			$sync = $status[ (int) $device->device_id ];
			$event_names = array_map( static function ( $id ) use ( $names ) { return $names[ $id ] ?? '#' . $id; }, Devices::event_ids( $device ) );
			?>
			<tr><td><?php echo esc_html( ( $device->label ?: __( 'Ohne Namen', 'wp-pass-captain' ) ) . ( $device->platform ? ' · ' . $device->platform : '' ) . ( $device->app_version ? ' ' . $device->app_version : '' ) ); ?></td><td><?php echo esc_html( $device->display_name ); ?></td><td><?php echo esc_html( implode( ', ', $event_names ) ); ?></td><td><?php echo esc_html( $sync['area_name'] ?: '—' ); ?></td><td><?php echo esc_html( $device->last_seen_at ? Forms::datetime( $device->last_seen_at, wp_timezone_string() ) : '—' ); ?></td><td><?php echo esc_html( $sync['last_sync_at'] ? Forms::datetime( $sync['last_sync_at'], wp_timezone_string() ) . ' (' . sprintf( /* translators: %d: number of scans */ _n( '%d Scan', '%d Scans', $sync['last_sync_scans'], 'wp-pass-captain' ), $sync['last_sync_scans'] ) . ')' : '—' ); ?></td><td><?php if ( $sync['open_conflicts'] ) : ?><a href="<?php echo esc_url( Forms::page_url( \PassCaptain\Admission\ConflictsPage::SLUG ) ); ?>"><span class="awaiting-mod"><?php echo esc_html( (string) $sync['open_conflicts'] ); ?></span></a><?php else : ?>0<?php endif; ?></td><td><?php echo esc_html( $device->revoked_at ? __( 'widerrufen', 'wp-pass-captain' ) : ( $active ? __( 'aktiv bis', 'wp-pass-captain' ) . ' ' . ( $device->expires_at ? Forms::datetime( $device->expires_at, wp_timezone_string() ) : '—' ) : __( 'abgelaufen', 'wp-pass-captain' ) ) ); ?></td><td><?php if ( $active ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpcap_revoke_device"><input type="hidden" name="device_id" value="<?php echo esc_attr( $device->device_id ); ?>"><?php wp_nonce_field( 'wpcap_revoke_device_' . $device->device_id ); ?><button class="button"><?php esc_html_e( 'Widerrufen', 'wp-pass-captain' ); ?></button></form><?php endif; ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $devices ) : ?><tr><td colspan="9"><?php esc_html_e( 'Noch keine Geräte gekoppelt.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<p><?php echo esc_html( __( 'API-Basis für Apps:', 'wp-pass-captain' ) . ' ' . rest_url( 'wp-pass-captain/v1/app/' ) ); ?></p>
		</div>
		<?php
	}
}
