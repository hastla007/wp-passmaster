<?php
/**
 * Translations of the offline scanner PWA.
 *
 * The PWA (assets/offline-scanner/) is a static page, so it cannot call __() itself. It fetches
 * GET /app/offline/i18n (public: the pairing screen needs the texts before a device token exists),
 * keeps the table in IndexedDB for offline use and looks texts up by their German source string
 * (= the msgid in languages/pass-captain.pot). Missing keys fall back to German.
 *
 * Device language: the app sends its preferred languages (navigator.languages) as
 * `?locale=fr-CH,fr,en`. The first one with a translation (bundled .mo file, installed language pack
 * or the site locale) wins – exact locale first, then the language (fr → fr_FR); without a match the
 * site locale is used.
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

	/**
	 * Locales the table can be served in: German (source texts), every bundled translation in
	 * /languages, installed language packs and the site locale (filter wpcap_offline_scanner_locales).
	 *
	 * @return string[] WordPress locales, e.g. de_DE, en_US.
	 */
	public static function supported_locales() {
		$locales = array( 'de_DE' );
		$dirs = array( WPCAP_PATH . 'languages' );
		if ( defined( 'WP_LANG_DIR' ) ) { $dirs[] = WP_LANG_DIR . '/plugins'; }
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '/pass-captain-*.mo' ) as $file ) {
				if ( preg_match( '/pass-captain-([a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2})\.mo$/', (string) $file, $m ) ) { $locales[] = $m[1]; }
			}
		}
		$locales[] = self::locale();
		$locales = apply_filters( 'wpcap_offline_scanner_locales', $locales );
		$out = array();
		foreach ( (array) $locales as $locale ) {
			$locale = \PassCaptain\Mail\Mailer::normalize_locale( (string) $locale );
			if ( '' !== $locale && ! in_array( $locale, $out, true ) ) { $out[] = $locale; }
		}
		return $out;
	}

	/**
	 * First supported locale for a list of preferred languages (BCP 47 tags or WordPress locales,
	 * comma separated, best first, optional ";q=" weights are ignored). '' when none matches.
	 */
	public static function match_locale( $requested ) {
		$supported = self::supported_locales();
		$by_language = array();
		foreach ( $supported as $locale ) {
			$language = strtolower( strtok( $locale, '_' ) );
			if ( ! isset( $by_language[ $language ] ) ) { $by_language[ $language ] = $locale; }
		}
		foreach ( array_slice( explode( ',', \PassCaptain\Text::cut( (string) $requested, 200 ) ), 0, 10 ) as $tag ) {
			$tag = trim( (string) preg_replace( '/;.*$/', '', $tag ) );
			if ( ! preg_match( '/^([A-Za-z]{2,3})(?:[-_]([A-Za-z0-9]{2,8}))?(?:[-_][A-Za-z0-9]{2,8})*$/', $tag, $m ) ) { continue; }
			$language = strtolower( $m[1] );
			if ( ! empty( $m[2] ) ) {
				$exact = $language . '_' . ( 2 === strlen( $m[2] ) ? strtoupper( $m[2] ) : $m[2] );
				if ( in_array( $exact, $supported, true ) ) { return $exact; }
			}
			if ( isset( $by_language[ $language ] ) ) { return $by_language[ $language ]; }
		}
		return '';
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
			'QR-Signatur ungültig' => __( 'QR-Signatur ungültig', 'pass-captain' ),
			'Einlass OK (offline)' => __( 'Einlass OK (offline)', 'pass-captain' ),
			'Bitte erneut scannen' => __( 'Bitte erneut scannen', 'pass-captain' ),
			'Prüfe …' => __( 'Prüfe …', 'pass-captain' ),
			/* translators: %d: seconds since this device admitted the ticket */
			'Eben hier eingelassen (vor %d s)' => __( 'Eben hier eingelassen (vor %d s)', 'pass-captain' ),
			/* translators: %d: kilobytes received so far */
			'Prüfpaket wird geladen … %d kB' => __( 'Prüfpaket wird geladen … %d kB', 'pass-captain' ),
			'Ton an' => __( 'Ton an', 'pass-captain' ),
			'Ton aus' => __( 'Ton aus', 'pass-captain' ),
			'Taschenlampe' => __( 'Taschenlampe', 'pass-captain' ),
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
			/* translators: %1: time of the earlier admission on this device */
			'Auf diesem Gerät eingelassen um %1' => __( 'Auf diesem Gerät eingelassen um %1', 'pass-captain' ),
			/* translators: %1: scanner device or staff member who admitted first */
			'durch %1' => __( 'durch %1', 'pass-captain' ),
			'Alle Scans sind synchronisiert. Gerät jetzt entkoppeln?' => __( 'Alle Scans sind synchronisiert. Gerät jetzt entkoppeln?', 'pass-captain' ),
			/* translators: %1$d: number of scans not yet synchronised (used twice) */
			'%1$d Scans sind noch nicht synchronisiert und gingen beim Entkoppeln verloren. Bitte zuerst mit Internetverbindung synchronisieren. Zum Entkoppeln trotzdem die Zahl %1$d eingeben:' => __( '%1$d Scans sind noch nicht synchronisiert und gingen beim Entkoppeln verloren. Bitte zuerst mit Internetverbindung synchronisieren. Zum Entkoppeln trotzdem die Zahl %1$d eingeben:', 'pass-captain' ),
			'Browser nicht unterstützt' => __( 'Browser nicht unterstützt', 'pass-captain' ),
			'Benötigt HTTPS, WebCrypto und IndexedDB.' => __( 'Benötigt HTTPS, WebCrypto und IndexedDB.', 'pass-captain' ),
			'Gekoppelt' => __( 'Gekoppelt', 'pass-captain' ),
			'Freigegebene Events werden geladen. Fehlt ein Event, im Admin unter „Offline-Scanner“ zuweisen.' => __( 'Freigegebene Events werden geladen. Fehlt ein Event, im Admin unter „Offline-Scanner“ zuweisen.', 'pass-captain' ),
			'Kopplung fehlgeschlagen' => __( 'Kopplung fehlgeschlagen', 'pass-captain' ),
			// Results, help codes, overlay (A089, A271, A315, A543, A548).
			'Erstmals eingelassen' => __( 'Erstmals eingelassen', 'pass-captain' ),
			/* translators: 1: number of admissions, 2: date and time of the first admission */
			'Einlass Nr. %1 – zuerst eingelassen um %2' => __( 'Einlass Nr. %1 – zuerst eingelassen um %2', 'pass-captain' ),
			/* translators: %1: date and time of the first admission */
			'Bereits eingelassen um %1' => __( 'Bereits eingelassen um %1', 'pass-captain' ),
			'Per Hilfscode geprüft' => __( 'Per Hilfscode geprüft', 'pass-captain' ),
			'Hilfscode nur online' => __( 'Hilfscode nur online', 'pass-captain' ),
			'Hilfscodes können nur online geprüft werden. Bitte QR-Code scannen oder den langen Ticket-Code eingeben.' => __( 'Hilfscodes können nur online geprüft werden. Bitte QR-Code scannen oder den langen Ticket-Code eingeben.', 'pass-captain' ),
			'Systemfehler – Ticket nicht geprüft' => __( 'Systemfehler – Ticket nicht geprüft', 'pass-captain' ),
			'Bitte erneut scannen.' => __( 'Bitte erneut scannen.', 'pass-captain' ),
			'Mehrere Ticketcodes erkannt' => __( 'Mehrere Ticketcodes erkannt', 'pass-captain' ),
			'Nichts geprüft. Bitte jedes Ticket einzeln scannen.' => __( 'Nichts geprüft. Bitte jedes Ticket einzeln scannen.', 'pass-captain' ),
			'Code ersetzt' => __( 'Code ersetzt', 'pass-captain' ),
			'Bereich voll' => __( 'Bereich voll', 'pass-captain' ),
			'Zu viele Hilfscode-Eingaben' => __( 'Zu viele Hilfscode-Eingaben', 'pass-captain' ),
			'Antippen zum Schließen' => __( 'Antippen zum Schließen', 'pass-captain' ),
			'Ticket-Code, Ticket-Link oder Hilfscode (z. B. K7QM-4XWA)' => __( 'Ticket-Code, Ticket-Link oder Hilfscode (z. B. K7QM-4XWA)', 'pass-captain' ),
			'Offline-Scanner · Pass Captain' => __( 'Offline-Scanner · Pass Captain', 'pass-captain' ),
			// Manual search (A544).
			'Ticket suchen' => __( 'Ticket suchen', 'pass-captain' ),
			'Name, E-Mail oder Bestellnummer' => __( 'Name, E-Mail oder Bestellnummer', 'pass-captain' ),
			'Suchen' => __( 'Suchen', 'pass-captain' ),
			/* translators: %1: order number */
			'Bestellung %1' => __( 'Bestellung %1', 'pass-captain' ),
			'bereits eingecheckt' => __( 'bereits eingecheckt', 'pass-captain' ),
			'widerrufen/erstattet' => __( 'widerrufen/erstattet', 'pass-captain' ),
			'Kein Ticket gefunden.' => __( 'Kein Ticket gefunden.', 'pass-captain' ),
			'Einchecken' => __( 'Einchecken', 'pass-captain' ),
			'Bitte mindestens 3 Zeichen eingeben.' => __( 'Bitte mindestens 3 Zeichen eingeben.', 'pass-captain' ),
			'Offline ist nur die Namenssuche möglich, und nur wenn das Prüfpaket Namen enthält.' => __( 'Offline ist nur die Namenssuche möglich, und nur wenn das Prüfpaket Namen enthält.', 'pass-captain' ),
			'Weitere Treffer – bitte genauer suchen.' => __( 'Weitere Treffer – bitte genauer suchen.', 'pass-captain' ),
			'Manuell (Suche)' => __( 'Manuell (Suche)', 'pass-captain' ),
			'Nur online möglich.' => __( 'Nur online möglich.', 'pass-captain' ),
			// Check-in counter (A546).
			'laut Prüfpaket' => __( 'laut Prüfpaket', 'pass-captain' ),
			/* translators: %1: date and time of the server count */
			'Stand %1' => __( 'Stand %1', 'pass-captain' ),
			/* translators: 1: admitted tickets, 2: admissible tickets */
			'Eingecheckt: %1 / %2' => __( 'Eingecheckt: %1 / %2', 'pass-captain' ),
			// Undo (A547).
			'Rückgängig' => __( 'Rückgängig', 'pass-captain' ),
			'Grund der Rücknahme' => __( 'Grund der Rücknahme', 'pass-captain' ),
			'Einlass zurücknehmen' => __( 'Einlass zurücknehmen', 'pass-captain' ),
			'Abbrechen' => __( 'Abbrechen', 'pass-captain' ),
			'Fehlscan (falsches Ticket gescannt)' => __( 'Fehlscan (falsches Ticket gescannt)', 'pass-captain' ),
			'Doppelt gescannt' => __( 'Doppelt gescannt', 'pass-captain' ),
			'Gast hat den Einlass nicht betreten' => __( 'Gast hat den Einlass nicht betreten', 'pass-captain' ),
			'Falscher Termin oder Bereich' => __( 'Falscher Termin oder Bereich', 'pass-captain' ),
			'– Grund wählen –' => __( '– Grund wählen –', 'pass-captain' ),
			'Anderer Grund …' => __( 'Anderer Grund …', 'pass-captain' ),
			/* translators: %1: seconds left to undo the admission */
			'Rückgängig (%1 s)' => __( 'Rückgängig (%1 s)', 'pass-captain' ),
			'Bitte einen Grund wählen oder eingeben.' => __( 'Bitte einen Grund wählen oder eingeben.', 'pass-captain' ),
			'Synchronisierung läuft – bitte gleich erneut versuchen.' => __( 'Synchronisierung läuft – bitte gleich erneut versuchen.', 'pass-captain' ),
			'Einlass zurückgenommen' => __( 'Einlass zurückgenommen', 'pass-captain' ),
			'Offline-Einlass verworfen – er wird nicht hochgeladen.' => __( 'Offline-Einlass verworfen – er wird nicht hochgeladen.', 'pass-captain' ),
			'Rückgängig nicht möglich' => __( 'Rückgängig nicht möglich', 'pass-captain' ),
			'Das Ticket ist wieder gültig.' => __( 'Das Ticket ist wieder gültig.', 'pass-captain' ),
			'zurückgenommen' => __( 'zurückgenommen', 'pass-captain' ),
			'Du darfst keine Check-ins zurücknehmen. Bitte die Einlassleitung fragen.' => __( 'Du darfst keine Check-ins zurücknehmen. Bitte die Einlassleitung fragen.', 'pass-captain' ),
			// Check-out (direction "out").
			'Scan-Richtung' => __( 'Scan-Richtung', 'pass-captain' ),
			'Einlass' => _x( 'Einlass', 'scanner direction: entry (opposite of exit)', 'pass-captain' ),
			'Auslass (Check-out)' => __( 'Auslass (Check-out)', 'pass-captain' ),
			'Bereit (Auslass)' => __( 'Bereit (Auslass)', 'pass-captain' ),
			'Ausgecheckt' => __( 'Ausgecheckt', 'pass-captain' ),
			'Ausgecheckt (offline)' => __( 'Ausgecheckt (offline)', 'pass-captain' ),
			'Nicht eingecheckt' => __( 'Nicht eingecheckt', 'pass-captain' ),
			'Check-out nicht erlaubt' => __( 'Check-out nicht erlaubt', 'pass-captain' ),
			'Check-out vorgemerkt' => __( 'Check-out vorgemerkt', 'pass-captain' ),
			'Prüfung beim Sync' => __( 'Prüfung beim Sync', 'pass-captain' ),
			'Auf diesem Gerät bereits ausgecheckt' => __( 'Auf diesem Gerät bereits ausgecheckt', 'pass-captain' ),
			'Wiedereinlass nach Check-out – Prüfung beim Sync' => __( 'Wiedereinlass nach Check-out – Prüfung beim Sync', 'pass-captain' ),
			/* translators: %s: reason the server gave for refusing an offline check-out (e.g. "Nicht eingecheckt") */
			'Check-out abgelehnt: %s' => __( 'Check-out abgelehnt: %s', 'pass-captain' ),
		);
	}

	/**
	 * Table served to the PWA.
	 *
	 * @param string $requested Preferred languages of the device (see match_locale()); '' = site locale.
	 * @return array{v:int,locale:string,lang:string,strings:array<string,string>,supported:string[]}
	 */
	public static function table( $requested = '' ) {
		$site = self::locale();
		$matched = '' !== (string) $requested ? self::match_locale( $requested ) : '';
		$locale = '' !== $matched ? $matched : $site;
		$build = static function () use ( $locale ) {
			$strings = apply_filters( 'wpcap_offline_scanner_strings', self::strings(), $locale );
			$out = array();
			foreach ( (array) $strings as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) && '' !== (string) $value ) { $out[ $key ] = (string) $value; }
			}
			return $out;
		};
		// Another locale than the site's: translate in that locale (switch_to_locale), then restore.
		$strings = $locale === $site ? $build() : \PassCaptain\Mail\Mailer::with_locale( $locale, $build );
		return array(
			'v'         => self::VERSION,
			'locale'    => $locale,
			'lang'      => self::lang( $locale ),
			'strings'   => $strings,
			'supported' => array_map( array( __CLASS__, 'lang' ), self::supported_locales() ),
			// White label (wp_pass_captain_brand_name_v1 / _logo_v1, context offline_scanner): title, logo, manifest.
			'brand'     => \PassCaptain\Brand::data( 'offline_scanner' ),
		);
	}
}
