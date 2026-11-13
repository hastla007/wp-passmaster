<?php
/**
 * Admin page "Einlassregeln": admission policy and entry window per ticket type, entrance areas
 * per event with ticket type restrictions and staff assignment.
 */
namespace PassCaptain\Admission;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RulesPage {
	const SLUG = 'wpcap-admission';
	const CAP = 'wpcap_manage_events';

	/** Ticket type with occurrence/event context. */
	private static function type_context( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.name,tt.occurrence_id,o.event_id,o.timezone FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", absint( $ticket_type_id ) ) );
	}

	private static function can( $event_id ) {
		return current_user_can( self::CAP ) && Access::can_access_event( (int) $event_id, self::CAP );
	}

	/**
	 * Saves policy, entry window and area restriction of a ticket type.
	 * policy "default" removes the explicit policy (single entry / legacy day rules).
	 */
	public static function save_policy( array $in ) {
		$type = self::type_context( Forms::int( $in, 'ticket_type_id' ) );
		if ( ! $type ) { return Forms::error( 'wpcap_ticket_type_missing', __( 'Tickettyp nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can( $type->event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$policy = Forms::choice( $in, 'policy', array_merge( array( 'default' ), Policies::TYPES ), '' );
		if ( '' === $policy ) { return Forms::error( 'wpcap_admission_policy_invalid', __( 'Unbekannte Einlassregel.', 'pass-captain' ) ); }
		if ( 'default' === $policy ) {
			Policies::delete( $type->ticket_type_id );
			$saved = null;
		} else {
			$saved = Policies::save( $type->ticket_type_id, array(
				'policy' => $policy,
				'max_entries' => Forms::int( $in, 'max_entries' ),
				'valid_dates' => Forms::textarea( $in, 'valid_dates', 10000 ),
				'opens_before_minutes' => Forms::text( $in, 'opens_before_minutes', 10 ),
				'closes_after_minutes' => Forms::text( $in, 'closes_after_minutes', 10 ),
				'day_from' => Forms::text( $in, 'day_from', 5 ),
				'day_until' => Forms::text( $in, 'day_until', 5 ),
			) );
			if ( is_wp_error( $saved ) ) { return $saved; }
		}
		$areas = Areas::set_type_areas( $type->ticket_type_id, $type->event_id, Forms::ids( $in, 'area_ids' ) );
		Audit::record( 'admission_policy_saved', 'ticket_type', (int) $type->ticket_type_id, array( 'event_id' => (int) $type->event_id, 'policy' => $policy, 'area_ids' => $areas ) );
		return array( 'ticket_type_id' => (int) $type->ticket_type_id, 'event_id' => (int) $type->event_id, 'policy' => $saved, 'area_ids' => $areas );
	}

	public static function save_area( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! $event_id || ! Queries::event( $event_id ) ) { return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can( $event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$id = Areas::save( $event_id, Forms::text( $in, 'name' ), Forms::int( $in, 'area_id' ) );
		if ( is_wp_error( $id ) ) { return $id; }
		Audit::record( 'admission_area_saved', 'admission_area', $id, array( 'event_id' => $event_id ) );
		return array( 'area_id' => $id, 'event_id' => $event_id );
	}

	public static function set_area_status( array $in ) {
		$area = Areas::get( Forms::int( $in, 'area_id' ) );
		if ( ! $area ) { return Forms::error( 'wpcap_area_missing', __( 'Bereich nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can( $area->event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		Areas::set_status( $area->area_id, Forms::choice( $in, 'status', array( 'active', 'archived' ), 'archived' ) );
		return array( 'area_id' => (int) $area->area_id, 'event_id' => (int) $area->event_id );
	}

	private static function find_user( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref || ! function_exists( 'get_user_by' ) ) { return null; }
		if ( ctype_digit( $ref ) ) { return get_user_by( 'id', absint( $ref ) ) ?: null; }
		if ( is_email( $ref ) ) { return get_user_by( 'email', $ref ) ?: null; }
		return get_user_by( 'login', sanitize_text_field( $ref ) ) ?: null;
	}

	/** Assigns a staff member to an area (restricts their scanning for this event to their areas). */
	public static function add_staff( array $in ) {
		$area = Areas::get( Forms::int( $in, 'area_id' ) );
		if ( ! $area ) { return Forms::error( 'wpcap_area_missing', __( 'Bereich nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can( $area->event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$user = self::find_user( $in['user'] ?? '' );
		if ( ! $user ) { return Forms::error( 'wpcap_staff_invalid', __( 'Benutzer nicht gefunden.', 'pass-captain' ) ); }
		Areas::add_staff( $area->area_id, $user->ID );
		Audit::record( 'admission_area_staff_added', 'admission_area', (int) $area->area_id, array( 'event_id' => (int) $area->event_id, 'user_id' => (int) $user->ID ) );
		return array( 'area_id' => (int) $area->area_id, 'event_id' => (int) $area->event_id, 'user_id' => (int) $user->ID );
	}

	public static function remove_staff( array $in ) {
		$area = Areas::get( Forms::int( $in, 'area_id' ) );
		if ( ! $area ) { return Forms::error( 'wpcap_area_missing', __( 'Bereich nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can( $area->event_id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$user_id = Forms::int( $in, 'user_id' );
		Areas::remove_staff( $area->area_id, $user_id );
		Audit::record( 'admission_area_staff_removed', 'admission_area', (int) $area->area_id, array( 'event_id' => (int) $area->event_id, 'user_id' => $user_id ) );
		return array( 'area_id' => (int) $area->area_id, 'event_id' => (int) $area->event_id );
	}

	/** Module settings (manage_options): max. age of offline scans evaluated at device time. */
	public static function save_settings( array $in ) {
		if ( ! current_user_can( 'manage_options' ) ) { return Forms::forbidden(); }
		$hours = min( 168, Forms::int( $in, 'offline_max_age_hours', 12 ) );
		\PassCaptain\Settings::update( array( 'admission' => array_merge( Policies::settings(), array( 'offline_max_age_hours' => $hours ) ) ) );
		Audit::record( 'admission_settings_saved', 'settings', 0, array( 'offline_max_age_hours' => $hours ) );
		return array( 'offline_max_age_hours' => $hours );
	}

	public static function handle_save_settings() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_save_settings' );
		Forms::finish( self::save_settings( $in ), __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	/* ---- admin-post wrappers ---- */

	private static function back( $result, array $in ) {
		$event_id = is_array( $result ) && ! empty( $result['event_id'] ) ? (int) $result['event_id'] : Forms::int( $in, 'event_id' );
		return Forms::page_url( self::SLUG, $event_id ? array( 'event_id' => $event_id ) : array() );
	}

	public static function handle_save_policy() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_save_policy_' . Forms::int( $in, 'ticket_type_id' ) );
		$result = self::save_policy( $in );
		Forms::finish( $result, __( 'Einlassregel gespeichert.', 'pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_save_area() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_save_area' );
		$result = self::save_area( $in );
		Forms::finish( $result, __( 'Einlassbereich gespeichert.', 'pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_area_status() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_area_status_' . Forms::int( $in, 'area_id' ) );
		$result = self::set_area_status( $in );
		Forms::finish( $result, __( 'Einlassbereich aktualisiert.', 'pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_add_staff() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_add_staff_' . Forms::int( $in, 'area_id' ) );
		$result = self::add_staff( $in );
		Forms::finish( $result, __( 'Personal dem Bereich zugewiesen.', 'pass-captain' ), self::back( $result, $in ) );
	}

	public static function handle_remove_staff() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_remove_staff_' . Forms::int( $in, 'area_id' ) . '_' . Forms::int( $in, 'user_id' ) );
		$result = self::remove_staff( $in );
		Forms::finish( $result, __( 'Zuweisung entfernt.', 'pass-captain' ), self::back( $result, $in ) );
	}

	/* ---- rendering ---- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$events = Queries::event_options( Queries::event_ids_for( array( self::CAP ) ) );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( $event_id && ! isset( $events[ $event_id ] ) ) { $event_id = 0; }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Einlassregeln', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Event wählen …', 'pass-captain' ) ) + $events, $event_id ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Anzeigen', 'pass-captain' ); ?></button>
		</form>
		<?php if ( $event_id ) { self::render_event( $event_id ); } ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<h2><?php esc_html_e( 'Offline-Scans', 'pass-captain' ); ?></h2>
			<?php Forms::open_form( 'wpcap_admission_save_settings' ); ?>
				<p><label><?php esc_html_e( 'Gerätezeit von Offline-Scans höchstens so viele Stunden zurück berücksichtigen (ältere werden zum Zeitpunkt des Uploads geprüft)', 'pass-captain' ); ?> <input type="number" name="offline_max_age_hours" min="0" max="168" style="width:6em" value="<?php echo esc_attr( (string) Policies::settings()['offline_max_age_hours'] ); ?>"></label>
				<button class="button"><?php esc_html_e( 'Speichern', 'pass-captain' ); ?></button></p>
			</form>
		<?php endif; ?>
		</div>
		<?php
	}

	private static function render_event( $event_id ) {
		$areas = Areas::for_event( $event_id, true );
		$active = array_filter( $areas, static function ( $a ) { return 'active' === $a->status; } );
		$labels = Policies::labels();
		?>
		<h2><?php esc_html_e( 'Einlassbereiche', 'pass-captain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Bereiche (z. B. „Eingang Nord“, „VIP“) begrenzen, wo Tickettypen eingelassen werden. Personal mit Bereichszuweisung darf für dieses Event nur in seinen Bereichen scannen; Geräte werden beim Koppeln einem Bereich zugeordnet.', 'pass-captain' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Bereich', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Personal', 'pass-captain' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $areas as $area ) : ?>
			<tr>
				<td><?php Forms::open_form( 'wpcap_admission_save_area' ); Forms::hidden( 'event_id', $event_id ); Forms::hidden( 'area_id', $area->area_id ); ?><input type="text" name="name" maxlength="190" value="<?php echo esc_attr( $area->name ); ?>"> <button class="button"><?php esc_html_e( 'Umbenennen', 'pass-captain' ); ?></button></form></td>
				<td><?php echo esc_html( 'active' === $area->status ? __( 'aktiv', 'pass-captain' ) : __( 'archiviert', 'pass-captain' ) ); ?></td>
				<td>
					<?php foreach ( Areas::staff( $area->area_id ) as $user_id ) : ?>
						<?php Forms::open_form( 'wpcap_admission_remove_staff', 'wpcap_admission_remove_staff_' . $area->area_id . '_' . $user_id, 'style="display:inline"' ); Forms::hidden( 'area_id', $area->area_id ); Forms::hidden( 'user_id', $user_id ); ?><?php echo esc_html( Forms::user_label( $user_id ) ); ?> <button class="button-link" aria-label="<?php esc_attr_e( 'Zuweisung entfernen', 'pass-captain' ); ?>">×</button></form><br>
					<?php endforeach; ?>
					<?php Forms::open_form( 'wpcap_admission_add_staff', 'wpcap_admission_add_staff_' . $area->area_id ); Forms::hidden( 'area_id', $area->area_id ); ?><input type="text" name="user" placeholder="<?php esc_attr_e( 'Login oder E-Mail', 'pass-captain' ); ?>"> <button class="button"><?php esc_html_e( 'Zuweisen', 'pass-captain' ); ?></button></form>
				</td>
				<td><?php Forms::open_form( 'wpcap_admission_area_status', 'wpcap_admission_area_status_' . $area->area_id ); Forms::hidden( 'area_id', $area->area_id ); Forms::hidden( 'status', 'active' === $area->status ? 'archived' : 'active' ); ?><button class="button"><?php echo esc_html( 'active' === $area->status ? __( 'Archivieren', 'pass-captain' ) : __( 'Reaktivieren', 'pass-captain' ) ); ?></button></form></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $areas ) : ?><tr><td colspan="4"><?php esc_html_e( 'Keine Bereiche – Tickets gelten an jedem Eingang.', 'pass-captain' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php Forms::open_form( 'wpcap_admission_save_area' ); Forms::hidden( 'event_id', $event_id ); ?>
			<p><label><?php esc_html_e( 'Neuer Bereich', 'pass-captain' ); ?> <input type="text" name="name" maxlength="190" required></label> <button class="button button-primary"><?php esc_html_e( 'Anlegen', 'pass-captain' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Regeln pro Tickettyp', 'pass-captain' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Zeiten gelten in der Zeitzone des Termins. Einlassfenster: Minuten vor Beginn bzw. nach Ende (leer = unbegrenzt, 0 = genau ab Beginn/bis Ende). Tägliche Einlasszeit optional (HH:MM). Ohne eigene Regel gilt einmaliger Einlass bzw. die Tagesregeln des Termins.', 'pass-captain' ); ?></p>
		<?php foreach ( Queries::occurrences_for_event( $event_id ) as $occ ) : ?>
			<h3><?php echo esc_html( Forms::datetime( $occ->starts_at, $occ->timezone ) . ( $occ->ends_at ? ' – ' . Forms::datetime( $occ->ends_at, $occ->timezone, false ) : '' ) ); ?></h3>
			<?php foreach ( Queries::ticket_types_for_occurrence( $occ->occurrence_id ) as $type ) :
				$policy = Policies::for_type( $type->ticket_type_id );
				$type_areas = Areas::type_area_ids( $type->ticket_type_id );
				?>
				<?php Forms::open_form( 'wpcap_admission_save_policy', 'wpcap_admission_save_policy_' . $type->ticket_type_id, 'class="wpcap-admission-policy" style="border:1px solid #dcdcde;padding:8px 12px;margin:8px 0;background:#fff"' ); Forms::hidden( 'ticket_type_id', $type->ticket_type_id ); Forms::hidden( 'event_id', $event_id ); ?>
					<p><strong><?php echo esc_html( $type->name ); ?></strong>
					<?php Forms::select( 'policy', array( 'default' => __( 'Standard (einmaliger Einlass / Tagesregeln des Termins)', 'pass-captain' ) ) + $labels, $policy ? $policy['policy'] : 'default' ); ?>
					<label><?php esc_html_e( 'Max. Einlässe', 'pass-captain' ); ?> <input type="number" name="max_entries" min="0" max="1000" style="width:6em" value="<?php echo esc_attr( $policy ? (string) $policy['max_entries'] : '0' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Gültige Tage (Mehrtagesticket)', 'pass-captain' ); ?><br><textarea name="valid_dates" rows="2" cols="50" placeholder="2026-12-01..2026-12-03, 2026-12-05"><?php echo esc_textarea( $policy ? implode( ', ', $policy['valid_dates'] ) : '' ); ?></textarea></label></p>
					<p><label><?php esc_html_e( 'Einlass ab (Min. vor Beginn)', 'pass-captain' ); ?> <input type="number" name="opens_before_minutes" style="width:6em" value="<?php echo esc_attr( $policy && null !== $policy['opens_before_minutes'] ? (string) $policy['opens_before_minutes'] : '' ); ?>"></label>
					<label><?php esc_html_e( 'bis (Min. nach Ende)', 'pass-captain' ); ?> <input type="number" name="closes_after_minutes" style="width:6em" value="<?php echo esc_attr( $policy && null !== $policy['closes_after_minutes'] ? (string) $policy['closes_after_minutes'] : '' ); ?>"></label>
					<label><?php esc_html_e( 'täglich von', 'pass-captain' ); ?> <input type="time" name="day_from" value="<?php echo esc_attr( $policy ? (string) $policy['day_from'] : '' ); ?>"></label>
					<label><?php esc_html_e( 'bis', 'pass-captain' ); ?> <input type="time" name="day_until" value="<?php echo esc_attr( $policy ? (string) $policy['day_until'] : '' ); ?>"></label></p>
					<?php if ( $active ) : ?>
						<p><?php esc_html_e( 'Nur in diesen Bereichen (keine Auswahl = alle):', 'pass-captain' ); ?>
						<?php foreach ( $active as $area ) : ?><label style="margin-right:1em"><input type="checkbox" name="area_ids[]" value="<?php echo esc_attr( $area->area_id ); ?>" <?php checked( in_array( (int) $area->area_id, $type_areas, true ) ); ?>> <?php echo esc_html( $area->name ); ?></label><?php endforeach; ?></p>
					<?php endif; ?>
					<p><button class="button button-primary"><?php esc_html_e( 'Speichern', 'pass-captain' ); ?></button></p>
				</form>
			<?php endforeach; ?>
		<?php endforeach; ?>
		<?php
	}
}
