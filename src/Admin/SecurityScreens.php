<?php
/**
 * Admin screens and actions of the access protection (W9): Audit-Log viewer, time-limited support
 * access, active staff sessions, per-case unmasking of personal data for support and date-level
 * (occurrence) staff assignments.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Security\DataPolicy;
use PassCaptain\Security\Sessions;
use PassCaptain\Security\SupportAccess;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SecurityScreens {
	const SUPPORT_SLUG = 'wpcap-support-access';
	const UNMASK_ACTION = 'wpcap_pii_unmask';
	const GRANT_ACTION = 'wpcap_support_grant';
	const REVOKE_ACTION = 'wpcap_support_revoke';
	const ASSIGN_OCC_ACTION = 'wpcap_assign_occurrence_staff';
	const REMOVE_OCC_ACTION = 'wpcap_remove_occurrence_staff';

	/** Capabilities that can be assigned per date (admission-related only). */
	const OCCURRENCE_CAPS = array( 'wpcap_checkin' );

	/** admin-post action => handler. */
	public static function handlers() {
		return array(
			AuditLogPage::EXPORT_ACTION => array( AuditLogPage::class, 'handle_export' ),
			self::UNMASK_ACTION => array( self::class, 'handle_unmask' ),
			self::GRANT_ACTION => array( self::class, 'handle_grant' ),
			self::REVOKE_ACTION => array( self::class, 'handle_revoke' ),
			self::ASSIGN_OCC_ACTION => array( self::class, 'handle_assign_occurrence' ),
			self::REMOVE_OCC_ACTION => array( self::class, 'handle_remove_occurrence' ),
		);
	}

	/** Submenu pages: [slug, title, capability, callback]. */
	public static function pages() {
		return array(
			array( AuditLogPage::SLUG, __( 'Audit-Log', 'pass-captain' ), AuditLogPage::menu_cap(), array( AuditLogPage::class, 'render' ) ),
			array( self::SUPPORT_SLUG, __( 'Supportzugriff', 'pass-captain' ), 'manage_options', array( self::class, 'render_support' ) ),
		);
	}

	/* ------------------------------------------------------------------ unmask */

	public static function handle_unmask() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( self::UNMASK_ACTION . '_' . $order_id );
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$result = DataPolicy::unmask( $order_id, Forms::text( $in, 'reason' ) );
		Forms::finish( $result, __( 'Daten für 15 Minuten freigegeben. Der Zugriff wurde protokolliert.', 'pass-captain' ), OrdersPage::order_url( $order_id ) );
	}

	/* ------------------------------------------------------------------ support access */

	public static function handle_grant() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::GRANT_ACTION );
		$in = Forms::post();
		$user = self::find_user( $in['user'] ?? '' );
		$caps = isset( $in['caps'] ) && is_array( $in['caps'] ) ? array_map( 'sanitize_key', $in['caps'] ) : array();
		$result = $user ? SupportAccess::grant( (int) $user->ID, Forms::int( $in, 'hours' ), Forms::text( $in, 'reason' ), $caps ) : Forms::error( 'wpcap_support_user', __( 'Benutzer nicht gefunden.', 'pass-captain' ) );
		Forms::finish( $result, __( 'Supportzugriff vergeben.', 'pass-captain' ), Forms::page_url( self::SUPPORT_SLUG ) );
	}

	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::REVOKE_ACTION );
		$result = SupportAccess::revoke( Forms::int( Forms::post(), 'grant_id' ) );
		Forms::finish( $result, __( 'Supportzugriff widerrufen.', 'pass-captain' ), Forms::page_url( self::SUPPORT_SLUG ) );
	}

	/** User by id, login or e-mail. */
	private static function find_user( $ref ) {
		$ref = is_scalar( $ref ) ? trim( (string) $ref ) : '';
		if ( '' === $ref || ! function_exists( 'get_user_by' ) ) { return null; }
		if ( ctype_digit( $ref ) ) { return get_user_by( 'id', (int) $ref ) ?: null; }
		if ( is_email( $ref ) ) { return get_user_by( 'email', $ref ) ?: null; }
		return get_user_by( 'login', sanitize_text_field( $ref ) ) ?: null;
	}

	public static function render_support() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		SupportAccess::expire_due();
		$labels = array( 'wpcap_manage_orders' => __( 'Bestellungen ansehen/bearbeiten', 'pass-captain' ), 'wpcap_checkin' => _x( 'Einlass', 'admission (check-in area of work)', 'pass-captain' ), 'wpcap_view_reports' => __( 'Berichte', 'pass-captain' ) );
		$status = array( 'active' => __( 'Aktiv', 'pass-captain' ), 'expired' => __( 'Abgelaufen', 'pass-captain' ), 'revoked' => __( 'Widerrufen', 'pass-captain' ) );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Supportzugriff', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Zeitlich begrenzte Rechte für Supportfälle. Vergabe, Widerruf und Ablauf werden im Audit-Log protokolliert; abgelaufene Zugriffe enden automatisch.', 'pass-captain' ) . '</p>';
		echo '<h2>' . esc_html__( 'Zugriff vergeben', 'pass-captain' ) . '</h2>';
		Forms::open_form( self::GRANT_ACTION );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="wpcap-sa-user">' . esc_html__( 'Benutzer (ID, Login oder E-Mail)', 'pass-captain' ) . '</label></th><td><input id="wpcap-sa-user" type="text" name="user" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Rechte', 'pass-captain' ) . '</th><td>';
		foreach ( $labels as $cap => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="caps[]" value="' . esc_attr( $cap ) . '"' . ( 'wpcap_manage_orders' === $cap ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th><label for="wpcap-sa-hours">' . esc_html__( 'Dauer (Stunden)', 'pass-captain' ) . '</label></th><td><input id="wpcap-sa-hours" type="number" name="hours" min="1" max="' . esc_attr( (string) (int) \PassCaptain\Security\Policy::get( 'support_access_max_hours' ) ) . '" value="4" required></td></tr>';
		echo '<tr><th><label for="wpcap-sa-reason">' . esc_html__( 'Grund / Supportfall', 'pass-captain' ) . '</label></th><td><input id="wpcap-sa-reason" type="text" name="reason" class="regular-text" required minlength="5" maxlength="190"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Zugriff vergeben', 'pass-captain' ) );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Vergebene Zugriffe', 'pass-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th>' . esc_html__( 'Rechte', 'pass-captain' ) . '</th><th>' . esc_html__( 'Grund', 'pass-captain' ) . '</th><th>' . esc_html__( 'Gültig bis (UTC)', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$grants = SupportAccess::recent( 50 );
		if ( ! $grants ) { echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Supportzugriffe.', 'pass-captain' ) . '</td></tr>'; }
		foreach ( $grants as $grant ) {
			echo '<tr><td>' . esc_html( Forms::user_label( (int) $grant->user_id ) ) . '</td><td>' . esc_html( implode( ', ', array_map( static function ( $c ) use ( $labels ) { return $labels[ $c ] ?? $c; }, explode( ',', (string) $grant->capabilities ) ) ) ) . '</td><td>' . esc_html( (string) $grant->reason ) . '</td><td>' . esc_html( (string) $grant->expires_at ) . '</td><td>' . esc_html( $status[ $grant->status ] ?? $grant->status ) . '</td><td>';
			if ( 'active' === $grant->status ) {
				Forms::open_form( self::REVOKE_ACTION, null, 'style="display:inline"' );
				Forms::hidden( 'grant_id', (int) $grant->grant_id );
				echo '<button class="button-link button-link-delete" type="submit">' . esc_html__( 'Widerrufen', 'pass-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_sessions();
		echo '</div>';
	}

	/** Staff accounts with sessions on several devices (shared-account indicator). */
	private static function render_sessions() {
		if ( ! function_exists( 'get_users' ) || ! class_exists( '\\WP_Session_Tokens' ) ) { return; }
		$users = get_users( array( 'role__in' => array( 'wpcap_checkin_staff', 'wpcap_box_office', 'wpcap_partner' ), 'number' => 200, 'fields' => array( 'ID' ) ) );
		echo '<h2>' . esc_html__( 'Gleichzeitige Anmeldungen von Einlass-/Kassenkonten', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Konten mit aktiven Sitzungen auf mehreren Geräten oder in mehreren Netzen. Gemeinsam genutzte Konten sind organisatorisch untersagt; mit der Einstellung „nur eine aktive Anmeldung“ werden sie technisch verhindert.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th>' . esc_html__( 'Sitzungen', 'pass-captain' ) . '</th><th>' . esc_html__( 'Geräte/Netze', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( (array) $users as $user ) {
			$sessions = (array) \WP_Session_Tokens::get_instance( (int) $user->ID )->get_all();
			$devices = array_unique( array_map( array( Sessions::class, 'fingerprint' ), array_filter( $sessions, 'is_array' ) ) );
			if ( count( $devices ) < 2 ) { continue; }
			++$shown;
			echo '<tr><td>' . esc_html( Forms::user_label( (int) $user->ID ) ) . '</td><td>' . count( $sessions ) . '</td><td>' . count( $devices ) . '</td></tr>';
		}
		if ( ! $shown ) { echo '<tr><td colspan="3">' . esc_html__( 'Keine gleichzeitigen Anmeldungen.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------ occurrence staff */

	/**
	 * Assigns check-in access for one date (occurrence) to a user.
	 *
	 * @return array|\WP_Error
	 */
	public static function assign_occurrence( array $in ) {
		if ( ! current_user_can( EventsPage::CAP ) ) { return Forms::forbidden(); }
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		$capability = Forms::choice( $in, 'capability', self::OCCURRENCE_CAPS, 'wpcap_checkin' );
		$occ = Queries::occurrence( $occurrence_id );
		$user = self::find_user( $in['user'] ?? ( $in['user_id'] ?? '' ) );
		if ( ! $occ || ! $user ) { return Forms::error( 'wpcap_staff_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $occ->event_id, EventsPage::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( method_exists( $user, 'add_cap' ) ) { $user->add_cap( $capability ); }
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (occurrence_id,event_id,user_id,capability) VALUES (%d,%d,%d,%s)', $wpdb->prefix . 'wpcap_occurrence_staff', $occurrence_id, (int) $occ->event_id, (int) $user->ID, $capability ) );
		Audit::record( 'occurrence_staff_assigned', 'occurrence', $occurrence_id, array( 'event_id' => (int) $occ->event_id, 'user_id' => (int) $user->ID, 'capability' => $capability ) );
		return array( 'occurrence_id' => $occurrence_id, 'user_id' => (int) $user->ID );
	}

	/** @return array|\WP_Error */
	public static function remove_occurrence( array $in ) {
		if ( ! current_user_can( EventsPage::CAP ) ) { return Forms::forbidden(); }
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		$user_id = Forms::int( $in, 'user_id' );
		$capability = Forms::choice( $in, 'capability', self::OCCURRENCE_CAPS, 'wpcap_checkin' );
		$occ = Queries::occurrence( $occurrence_id );
		if ( ! $occ || ! $user_id ) { return Forms::error( 'wpcap_staff_invalid', __( 'Zuweisung ungültig.', 'pass-captain' ) ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $occ->event_id, EventsPage::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wpcap_occurrence_staff', array( 'occurrence_id' => $occurrence_id, 'user_id' => $user_id, 'capability' => $capability ), array( '%d', '%d', '%s' ) );
		EventsPage::maybe_revoke_checkin_cap( $user_id );
		Audit::record( 'occurrence_staff_removed', 'occurrence', $occurrence_id, array( 'event_id' => (int) $occ->event_id, 'user_id' => $user_id, 'capability' => $capability ) );
		return array( 'occurrence_id' => $occurrence_id );
	}

	private static function occurrence_url( $occurrence_id ) {
		return Forms::page_url( EventsPage::SLUG, array( 'view' => 'occurrence', 'occurrence_id' => absint( $occurrence_id ) ) );
	}

	public static function handle_assign_occurrence() {
		$in = Forms::post();
		Forms::verify( self::ASSIGN_OCC_ACTION );
		Forms::finish( self::assign_occurrence( $in ), __( 'Terminzugriff zugewiesen.', 'pass-captain' ), self::occurrence_url( Forms::int( $in, 'occurrence_id' ) ) );
	}

	public static function handle_remove_occurrence() {
		$in = Forms::post();
		Forms::verify( self::REMOVE_OCC_ACTION );
		Forms::finish( self::remove_occurrence( $in ), __( 'Terminzugriff entfernt.', 'pass-captain' ), self::occurrence_url( Forms::int( $in, 'occurrence_id' ) ) );
	}

	/** Staff of one date. */
	public static function occurrence_staff( $occurrence_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id,capability FROM %i WHERE occurrence_id=%d ORDER BY user_id', $wpdb->prefix . 'wpcap_occurrence_staff', absint( $occurrence_id ) ) );
	}

	/** Section on the occurrence screen (EventsPage::render_occurrence). */
	public static function render_occurrence_staff( $occ ) {
		$staff = self::occurrence_staff( (int) $occ->occurrence_id );
		echo '<h2>' . esc_html__( 'Einlasspersonal nur für diesen Termin', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Zusätzlich zum Eventpersonal: diese Personen dürfen nur an diesem Termin einchecken, auschecken und die Teilnehmerliste (Basis) sehen.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Benutzer', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $staff ) { echo '<tr><td colspan="2">' . esc_html__( 'Kein terminbezogenes Personal.', 'pass-captain' ) . '</td></tr>'; }
		foreach ( $staff as $row ) {
			echo '<tr><td>' . esc_html( Forms::user_label( (int) $row->user_id ) ) . '</td><td>';
			Forms::open_form( self::REMOVE_OCC_ACTION, null, 'style="display:inline"' );
			Forms::hidden( 'occurrence_id', (int) $occ->occurrence_id );
			Forms::hidden( 'user_id', (int) $row->user_id );
			Forms::hidden( 'capability', (string) $row->capability );
			echo '<button class="button-link button-link-delete" type="submit">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		Forms::open_form( self::ASSIGN_OCC_ACTION );
		Forms::hidden( 'occurrence_id', (int) $occ->occurrence_id );
		echo '<p><label>' . esc_html__( 'Benutzer (ID, Login oder E-Mail)', 'pass-captain' ) . ' <input type="text" name="user" required></label> <button class="button" type="submit">' . esc_html__( 'Für diesen Termin zuweisen', 'pass-captain' ) . '</button></p></form>';
	}
}
