<?php
/**
 * Settings of the access-protection features (W9): audit of read access, data minimisation, staff
 * sessions, MFA for sensitive actions, support access, check-out/re-entry, check-in undo, refunds after check-in,
 * the buyer language selection, the event waitlist offer window and the waitlist double opt-in.
 *
 * The keys are registered through the public settings filters (wp_pass_captain_settings_defaults_v1,
 * wp_pass_captain_settings_sections_v1), so they appear on the settings page next to the core fields.
 * get() falls back to the defaults below when the filter was not registered (e.g. very early calls).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Policy {
	/** Sensitive action categories that can require two-factor authentication (setting mfa_<category>). */
	const MFA_CATEGORIES = array( 'settings', 'secrets', 'exports', 'roles', 'bulk', 'api_keys' );

	const DEFAULTS = array(
		'audit_read_access'          => 1,
		'audit_read_limit_per_hour'  => 300,
		'support_mask_pii'           => 1,
		'staff_single_session'       => 0,
		'mfa_settings'               => 0,
		'mfa_secrets'                => 0,
		'mfa_exports'                => 0,
		'mfa_roles'                  => 0,
		'mfa_bulk'                   => 0,
		'mfa_api_keys'               => 0,
		'support_access_max_hours'   => 24,
		'checkout_roles'             => '',
		'undo_window_minutes'        => 5,
		'undo_roles'                 => '',
		'reentry_after_checkout'     => 0,
		'max_reentries'              => 0,
		'refund_after_checkin'       => 'allow',
		'buyer_language_selector'    => 1,
		'waitlist_offer_hours'       => 24,
		'waitlist_double_opt_in'     => 1,
	);

	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_filter( 'wp_pass_captain_settings_defaults_v1', array( __CLASS__, 'defaults' ) );
		add_filter( 'wp_pass_captain_settings_sections_v1', array( __CLASS__, 'sections' ) );
		Settings::flush();
	}

	/** @param array $defaults Add-on defaults. */
	public static function defaults( $defaults ) {
		return ( is_array( $defaults ) ? $defaults : array() ) + self::DEFAULTS;
	}

	/** Value of one of the keys above (falls back to the default). */
	public static function get( $key ) {
		$value = Settings::get( $key );
		return null === $value ? ( self::DEFAULTS[ $key ] ?? null ) : $value;
	}

	/** Settings page fields (appended to the core sections "Sicherheit", "Einlass", "Erstattungen"). */
	public static function sections( $sections ) {
		$sections = is_array( $sections ) ? $sections : array();
		$mfa_labels = array(
			'settings' => __( 'Einstellungen ändern', 'pass-captain' ),
			'secrets'  => __( 'Zugangsdaten/Secrets ändern', 'pass-captain' ),
			'exports'  => __( 'Exporte und Downloads (CSV, Teilnehmerlisten, Audit-Log)', 'pass-captain' ),
			'roles'    => __( 'Rollen- und Personalzuweisungen, Supportzugriff', 'pass-captain' ),
			'bulk'     => __( 'Massenaktionen (Absagen, Stapeldruck, Warteschlangen)', 'pass-captain' ),
			'api_keys' => __( 'API-Schlüssel erstellen oder widerrufen', 'pass-captain' ),
		);
		$access = array(
			'audit_read_access' => array( 'type' => 'bool', 'label' => __( 'Lesende Zugriffe auf personenbezogene Daten protokollieren', 'pass-captain' ), 'description' => __( 'Bestelldetails, Ticketsuche, Teilnehmerlisten, Exporte und Personal-REST-Abrufe werden zusammengefasst im Audit-Log erfasst (je Person und Objekt höchstens alle 15 Minuten).', 'pass-captain' ) ),
			'audit_read_limit_per_hour' => array( 'type' => 'int', 'label' => __( 'Höchstzahl protokollierter Lesezugriffe je Person und Stunde', 'pass-captain' ), 'min' => 10, 'max' => 100000, 'description' => __( 'Schutz gegen ein volles Audit-Log: Darüber hinaus wird je Person und Stunde nur noch ein Sammeleintrag geschrieben.', 'pass-captain' ) ),
			'support_mask_pii' => array( 'type' => 'bool', 'label' => __( 'Support sieht personenbezogene Daten maskiert (Freigabe je Fall, protokolliert)', 'pass-captain' ), 'description' => __( 'Personen mit der Support-Rolle sehen Namen, E-Mail und Telefon gekürzt. Für einen einzelnen Fall können sie die Daten mit Begründung freischalten; das wird protokolliert.', 'pass-captain' ) ),
			'staff_single_session' => array( 'type' => 'bool', 'label' => __( 'Einlass-/Kassenkonten: nur eine aktive Anmeldung erlauben', 'pass-captain' ), 'description' => __( 'Bei einer neuen Anmeldung werden andere Sitzungen desselben Kontos beendet. Gleichzeitige Anmeldungen von verschiedenen Geräten werden immer im Audit-Log vermerkt.', 'pass-captain' ) ),
			'support_access_max_hours' => array( 'type' => 'int', 'label' => __( 'Supportzugriff: maximale Dauer (Stunden)', 'pass-captain' ), 'min' => 1, 'max' => 168, 'description' => __( 'Ein befristeter Supportzugang endet spätestens nach dieser Zeit automatisch (1–168 Stunden).', 'pass-captain' ) ),
		);
		foreach ( $mfa_labels as $category => $label ) {
			/* translators: %s: action category */
			$access[ 'mfa_' . $category ] = array( 'type' => 'bool', 'label' => sprintf( __( 'Zwei-Faktor-Anmeldung verlangen für: %s', 'pass-captain' ), $label ), 'description' => __( 'Vor dieser Aktion muss die Person ihren zweiten Faktor erneut bestätigen (gilt einige Minuten). Empfohlen für alle Konten mit Verwaltungsrechten.', 'pass-captain' ) );
		}
		$sections['security'] = array( 'title' => __( 'Sicherheit', 'pass-captain' ), 'fields' => $access );
		$sections['scanner'] = array(
			'title' => __( 'Einlass / Scanner-Geräte', 'pass-captain' ),
			'fields' => array(
				'checkout_roles' => array( 'type' => 'text', 'label' => __( 'Check-out nur für diese Rollen (Rollen-Slugs, kommagetrennt)', 'pass-captain' ), 'description' => __( 'Leer = alle Personen mit Einlassrecht für den Termin. Beispiel: wpcap_event_manager,wpcap_box_office. Administratoren dürfen immer.', 'pass-captain' ), 'sanitize' => array( __CLASS__, 'sanitize_roles' ) ),
				'undo_window_minutes' => array( 'type' => 'int', 'label' => __( 'Check-in zurücknehmen: Zeitfenster für Einlasspersonal (Minuten)', 'pass-captain' ), 'description' => __( 'Personal ohne Bestellrechte darf nur eigene Scans (eigenes Konto oder eigenes Gerät) innerhalb dieses Zeitfensters zurücknehmen; 0 = gar nicht. Administratoren, Personal mit Bestellrechten und die Rollen unten dürfen immer.', 'pass-captain' ), 'min' => 0, 'max' => 1440 ),
				'undo_roles' => array( 'type' => 'text', 'label' => __( 'Check-ins ohne Zeitfenster zurücknehmen dürfen diese Rollen (Rollen-Slugs, kommagetrennt)', 'pass-captain' ), 'description' => __( 'Beispiel: wpcap_event_manager. Leer = nur Administratoren und Personal mit Bestellrechten.', 'pass-captain' ), 'sanitize' => array( __CLASS__, 'sanitize_roles' ) ),
				'reentry_after_checkout' => array( 'type' => 'bool', 'label' => __( 'Wiedereinlass nach Check-out erlauben (Ticketarten ohne eigene Einlassregel)', 'pass-captain' ), 'description' => __( 'Wer beim Verlassen ausgecheckt wurde, darf mit demselben Ticket wieder hinein. Ticketarten mit eigener Einlassregel folgen ihrer Regel.', 'pass-captain' ) ),
				'max_reentries' => array( 'type' => 'int', 'label' => __( 'Maximale Wiedereinlässe je Ticket (0 = unbegrenzt)', 'pass-captain' ), 'min' => 0, 'max' => 1000, 'description' => __( 'Begrenzt, wie oft ein Ticket nach einem Check-out erneut eingelassen wird. 0 bedeutet ohne Begrenzung.', 'pass-captain' ) ),
			),
		);
		$sections['refunds'] = array(
			'title' => __( 'Erstattungen', 'pass-captain' ),
			'fields' => array(
				'refund_after_checkin' => array(
					'type' => 'choice',
					'label' => __( 'Erstattung bereits eingecheckter Tickets', 'pass-captain' ),
					'options' => array(
						'allow' => __( 'Erlauben', 'pass-captain' ),
						'approval' => __( 'Nur mit Zweitfreigabe', 'pass-captain' ),
						'deny' => __( 'Nicht erlauben', 'pass-captain' ),
					),
					'description' => __( 'Gilt für manuelle Erstattungen und automatische Erstattungen bei Terminabsage. Eingecheckte Tickets bleiben als Einlass-Historie erhalten.', 'pass-captain' ),
				),
			),
		);
		$sections['general'] = array(
			'title' => __( 'Allgemein / Reservierung', 'pass-captain' ),
			'fields' => array(
				'buyer_language_selector' => array( 'type' => 'bool', 'label' => __( 'Sprachauswahl im Checkout und im Bestellportal anzeigen', 'pass-captain' ), 'description' => __( 'Käufer können die Sprache von Checkout, Bestellportal und E-Mails selbst wählen. Angeboten werden nur Sprachen, in die Pass Captain übersetzt ist.', 'pass-captain' ) ),
				'waitlist_offer_hours' => array( 'type' => 'int', 'label' => __( 'Event-Warteliste: Annahmefrist eines Angebots (Stunden)', 'pass-captain' ), 'description' => __( 'So lange ist ein freigewordenes Ticket für die Person auf der Warteliste reserviert, bevor es der nächsten angeboten wird (1–720 Stunden).', 'pass-captain' ), 'min' => 1, 'max' => 720 ),
				'waitlist_double_opt_in' => array( 'type' => 'bool', 'label' => __( 'Wartelisten: Eintragung per E-Mail bestätigen lassen (Double-Opt-in)', 'pass-captain' ), 'description' => __( 'Neue Einträge in Event- und Zeitfenster-Wartelisten gelten erst nach einem Klick auf den Bestätigungslink (48 Stunden gültig). Unbestätigte Einträge bekommen keine Position und keine Angebote und werden nach Ablauf gelöscht.', 'pass-captain' ) ),
			),
		);
		return $sections;
	}

	/** Comma separated role slugs. */
	public static function sanitize_roles( $value ) {
		$roles = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $value ) ) ) );
		return implode( ',', array_unique( $roles ) );
	}

	/** Whether 2FA is required for a sensitive action category. */
	public static function mfa_required( $category ) {
		return in_array( $category, self::MFA_CATEGORIES, true ) && (bool) self::get( 'mfa_' . $category );
	}
}
