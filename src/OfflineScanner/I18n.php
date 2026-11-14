<?php
/**
 * Translations of the offline scanner PWA.
 *
 * The PWA (assets/offline-scanner/) is a static page, so it cannot call __() itself. It fetches
 * GET /app/offline/i18n (public: the pairing screen needs the texts before a device token exists),
 * keeps the table in IndexedDB for offline use and looks texts up by their German source string
 * (= the msgid in languages/pass-captain.pot). Missing keys fall back to German.
 *
 * Every key used by app.js (t('…')) and index.html (data-i18n*) must be listed here; the
 * integration test tests/integration/modules/offline_scanner.php enforces that.
 */
namespace PassCaptain\OfflineScanner;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class I18n {
	/** Format version of the table (lets the app ignore incompatible cached tables). */
	const VERSION = 1;

	/** WordPress locale the table is translated for (e.g. "de_DE", "en_US"). */
	public static function locale() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return (string) apply_filters( 'wpcap_offline_scanner_locale', (string) $locale );
	}

	/** BCP 47 language tag for <html lang> and Intl (e.g. "de-DE"); "de-DE" when unusable. */
	public static function lang( $locale ) {
		$tag = str_replace( '_', '-', (string) $locale );
		return preg_match( '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $tag ) ? $tag : 'de-DE';
	}

	/** German source string => translation (text domain pass-captain). */
	public static function strings() {
		return array(
			// Static page (index.html).
			'Offline-Scanner' => __( 'Offline-Scanner', 'pass-captain' ),
			'Noch nicht hochgeladene Offline-Scans' => __( 'Noch nicht hochgeladene Offline-Scans', 'pass-captain' ),
			/* translators: %d: number of scans not uploaded yet */
			'%d offen' => __( '%d offen', 'pass-captain' ),
			'Gerät koppeln' => __( 'Gerät koppeln', 'pass-captain' ),
			'Im WordPress-Admin unter „Pass Captain → Scanner-Geräte“ einen Kopplungscode für die gewünschten Events erstellen und hier eingeben. Danach unter „Offline-Scanner“ das Gerät dem Event zuweisen.' => __( 'Im WordPress-Admin unter „Pass Captain → Scanner-Geräte“ einen Kopplungscode für die gewünschten Events erstellen und hier eingeben. Danach unter „Offline-Scanner“ das Gerät dem Event zuweisen.', 'pass-captain' ),
			'Kopplungscode' => __( 'Kopplungscode', 'pass-captain' ),
			'Gerätename' => __( 'Gerätename', 'pass-captain' ),
			'z. B. Einlass Nord' => __( 'z. B. Einlass Nord', 'pass-captain' ),
			'Koppeln' => __( 'Koppeln', 'pass-captain' ),
			'Server:' => __( 'Server:', 'pass-captain' ),
			'Termin' => __( 'Termin', 'pass-captain' ),
			'Einlassbereich' => __( 'Einlassbereich', 'pass-captain' ),
			'Paket aktualisieren' => __( 'Paket aktualisieren', 'pass-captain' ),
			'Jetzt synchronisieren' => __( 'Jetzt synchronisieren', 'pass-captain' ),
			'Bereit' => __( 'Bereit', 'pass-captain' ),
			'Kamera starten' => __( 'Kamera starten', 'pass-captain' ),
			'Ticketcode manuell' => __( 'Ticketcode manuell', 'pass-captain' ),
			'64-stelliger Code oder Ticket-Link' => __( '64-stelliger Code oder Ticket-Link', 'pass-captain' ),
			'Prüfen' => __( 'Prüfen', 'pass-captain' ),
			'Konflikte nach Synchronisierung' => __( 'Konflikte nach Synchronisierung', 'pass-captain' ),
			'Letzte Scans' => __( 'Letzte Scans', 'pass-captain' ),
			'Gerät' => __( 'Gerät', 'pass-captain' ),
			'Gerät entkoppeln' => __( 'Gerät entkoppeln', 'pass-captain' ),
			// Scan results.
			'Einlass OK' => __( 'Einlass OK', 'pass-captain' ),
			'Bereits verwendet' => __( 'Bereits verwendet', 'pass-captain' ),
			'Widerrufen' => __( 'Widerrufen', 'pass-captain' ),
			'Unbekanntes Ticket' => __( 'Unbekanntes Ticket', 'pass-captain' ),
			'Falsches Event' => __( 'Falsches Event', 'pass-captain' ),
			'Falscher Termin' => __( 'Falscher Termin', 'pass-captain' ),
			'Falscher Einlassbereich' => __( 'Falscher Einlassbereich', 'pass-captain' ),
			'Termin abgesagt' => __( 'Termin abgesagt', 'pass-captain' ),
			'Außerhalb des Einlasszeitraums' => __( 'Außerhalb des Einlasszeitraums', 'pass-captain' ),
			'Heute nicht gültig' => __( 'Heute nicht gültig', 'pass-captain' ),
			'Tageskontingent erreicht' => __( 'Tageskontingent erreicht', 'pass-captain' ),
			'Einlasslimit erreicht' => __( 'Einlasslimit erreicht', 'pass-captain' ),
			'Außerhalb des Einlassfensters' => __( 'Außerhalb des Einlassfensters', 'pass-captain' ),
			'Bereits drinnen' => __( 'Bereits drinnen', 'pass-captain' ),
			'Kein Einlasszugriff' => __( 'Kein Einlasszugriff', 'pass-captain' ),
			'Prüfpaket abgelaufen' => __( 'Prüfpaket abgelaufen', 'pass-captain' ),
			'Kein gültiges Prüfpaket' => __( 'Kein gültiges Prüfpaket', 'pass-captain' ),
			'Kein Ticketcode erkannt' => __( 'Kein Ticketcode erkannt', 'pass-captain' ),
			'Einlass OK (offline)' => __( 'Einlass OK (offline)', 'pass-captain' ),
			'Mehrfachticket – Prüfung beim Sync' => __( 'Mehrfachticket – Prüfung beim Sync', 'pass-captain' ),
			'Kein Termin gewählt' => __( 'Kein Termin gewählt', 'pass-captain' ),
			'Fehler' => __( 'Fehler', 'pass-captain' ),
			'online' => __( 'online', 'pass-captain' ),
			'offline' => __( 'offline', 'pass-captain' ),
			// Package verification.
			'Unbekannter Signaturschlüssel' => __( 'Unbekannter Signaturschlüssel', 'pass-captain' ),
			'Signaturschlüssel abgelaufen' => __( 'Signaturschlüssel abgelaufen', 'pass-captain' ),
			'Signatur ungültig' => __( 'Signatur ungültig', 'pass-captain' ),
			'Unbekanntes Paketformat' => __( 'Unbekanntes Paketformat', 'pass-captain' ),
			'Schlüssel-ID passt nicht' => __( 'Schlüssel-ID passt nicht', 'pass-captain' ),
			'Paket gehört zu einem anderen Gerät' => __( 'Paket gehört zu einem anderen Gerät', 'pass-captain' ),
			'Paket passt nicht zum Termin' => __( 'Paket passt nicht zum Termin', 'pass-captain' ),
			'Delta-Kette unterbrochen' => __( 'Delta-Kette unterbrochen', 'pass-captain' ),
			'Delta passt nicht zum gespeicherten Paket' => __( 'Delta passt nicht zum gespeicherten Paket', 'pass-captain' ),
			// Package status.
			'Keine zugewiesenen Termine' => __( 'Keine zugewiesenen Termine', 'pass-captain' ),
			'Noch kein Prüfpaket geladen.' => __( 'Noch kein Prüfpaket geladen.', 'pass-captain' ),
			/* translators: %s: verification error */
			'Prüfpaket ungültig: %s' => __( 'Prüfpaket ungültig: %s', 'pass-captain' ),
			'Prüfpaket abgelaufen – bitte online aktualisieren.' => __( 'Prüfpaket abgelaufen – bitte online aktualisieren.', 'pass-captain' ),
			/* translators: 1: number of tickets, 2: signing key id, 3: date and time the package was issued */
			'%1$d Tickets im Paket · signiert (Ed25519, Schlüssel %2$s) · Stand %3$s' => __( '%1$d Tickets im Paket · signiert (Ed25519, Schlüssel %2$s) · Stand %3$s', 'pass-captain' ),
			/* translators: 1: expiry date and time, 2: duplicate scan policy */
			'Gültig bis %1$s · Doppelscans: %2$s' => __( 'Gültig bis %1$s · Doppelscans: %2$s', 'pass-captain' ),
			'erste Synchronisierung gewinnt' => __( 'erste Synchronisierung gewinnt', 'pass-captain' ),
			'zur Prüfung' => __( 'zur Prüfung', 'pass-captain' ),
			'Kein Bereich' => __( 'Kein Bereich', 'pass-captain' ),
			// Log and conflicts.
			'wartet auf Sync' => __( 'wartet auf Sync', 'pass-captain' ),
			'Konflikt' => __( 'Konflikt', 'pass-captain' ),
			'synchronisiert' => __( 'synchronisiert', 'pass-captain' ),
			'Offline-Dauer überschritten' => __( 'Offline-Dauer überschritten', 'pass-captain' ),
			'automatisch geklärt' => __( 'automatisch geklärt', 'pass-captain' ),
			/* translators: %d: conflict id */
			'zur Prüfung (#%d)' => __( 'zur Prüfung (#%d)', 'pass-captain' ),
			'Keine Konflikte.' => __( 'Keine Konflikte.', 'pass-captain' ),
			// Network, camera, pairing, boot.
			'Online' => __( 'Online', 'pass-captain' ),
			'Offline' => __( 'Offline', 'pass-captain' ),
			'Kamera stoppen' => __( 'Kamera stoppen', 'pass-captain' ),
			'Kamera nicht verfügbar' => __( 'Kamera nicht verfügbar', 'pass-captain' ),
			'Gerät nicht mehr gekoppelt' => __( 'Gerät nicht mehr gekoppelt', 'pass-captain' ),
			'Token abgelaufen oder widerrufen. Bitte neu koppeln.' => __( 'Token abgelaufen oder widerrufen. Bitte neu koppeln.', 'pass-captain' ),
			'Der Kopplungscode gehört zu einem anderen Server.' => __( 'Der Kopplungscode gehört zu einem anderen Server.', 'pass-captain' ),
			'Offline-Scanner (PWA)' => __( 'Offline-Scanner (PWA)', 'pass-captain' ),
			'Gerät entkoppeln? Nicht synchronisierte Scans gehen verloren.' => __( 'Gerät entkoppeln? Nicht synchronisierte Scans gehen verloren.', 'pass-captain' ),
			'Browser nicht unterstützt' => __( 'Browser nicht unterstützt', 'pass-captain' ),
			'Benötigt HTTPS, WebCrypto und IndexedDB.' => __( 'Benötigt HTTPS, WebCrypto und IndexedDB.', 'pass-captain' ),
			'Gekoppelt' => __( 'Gekoppelt', 'pass-captain' ),
			'Jetzt im Admin unter „Offline-Scanner“ dem Event zuweisen.' => __( 'Jetzt im Admin unter „Offline-Scanner“ dem Event zuweisen.', 'pass-captain' ),
			'Kopplung fehlgeschlagen' => __( 'Kopplung fehlgeschlagen', 'pass-captain' ),
		);
	}

	/**
	 * Table served to the PWA.
	 *
	 * @return array{v:int,locale:string,lang:string,strings:array<string,string>}
	 */
	public static function table() {
		$locale = self::locale();
		$strings = apply_filters( 'wpcap_offline_scanner_strings', self::strings(), $locale );
		$out = array();
		foreach ( (array) $strings as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) && '' !== (string) $value ) { $out[ $key ] = (string) $value; }
		}
		return array( 'v' => self::VERSION, 'locale' => $locale, 'lang' => self::lang( $locale ), 'strings' => $out );
	}
}
