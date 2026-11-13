# Release-Abnahme vX.Y.Z

Kopie dieser Datei als `docs/release-acceptance/vX.Y.Z.md` anlegen, jeden Punkt prüfen und abhaken.
Der Release-Workflow veröffentlicht nur, wenn diese Datei existiert, alle Kästchen abgehakt sind und
die Kopfangaben ausgefüllt sind (`build/release-acceptance.sh`). Nicht zutreffende Punkte mit `[x]`
abhaken und „entfällt: Grund“ dahinterschreiben.

Version: X.Y.Z
Datum:
Geprüft von:
Commit:

## Automatisch (Link zum grünen CI-Lauf des Release-Commits)

- [ ] CI „WordPress plugin checks“ grün (PHP 8.2/8.4, Integration, Last-CI, PWA, Paket) – Link:
- [ ] Letzter wöchentlicher Lasttest (`load.yml`) grün oder Abweichung begründet – Link:
- [ ] Reproduzierbarer Build: Prüfsumme in `build-info.txt` notiert:

## Installation und Upgrade (saubere WordPress-Installation, aktuelle WP-Version)

- [ ] Aktivieren, Deaktivieren, erneut Aktivieren ohne Fehler/Warnungen (WP_DEBUG an)
- [ ] Upgrade von der Vorversion mit echten Daten (`tests/restore/drill.sh`) – alle Prüfungen „ok“
- [ ] Deinstallation mit und ohne „Daten beim Löschen entfernen“
- [ ] Läuft ohne WooCommerce und ohne Voucher Captain; mit WooCommerce (HPOS an) Checkout und Erstattung geprüft

## Kritische Abläufe manuell (je einmal Mobilgerät und Desktop)

- [ ] Checkout (kostenlos und bezahlt, Testmodus des Zahlungsanbieters), Ticketmail kommt an
- [ ] Ticketseite/PDF/Wallet öffnen, Hilfscode sichtbar
- [ ] QR-Scan Web-Scanner und Offline-App, Doppel-Scan, Hilfscode-Eingabe
- [ ] Offline-App: Installation und Offline-Einlass nach `docs/OFFLINE-SCANNER.md` (Gerätematrix aktualisiert)
- [ ] Erstattung (Teil und voll), Rechnungskorrektur geprüft
- [ ] Kasse: Verkauf, Schichtabschluss
- [ ] Supportansicht: Bestellung suchen, Tickets neu senden

## Fachliche Freigabe (Betreiberkontext)

- [ ] Rechtstexte (AGB, Verbraucherinformationen, Datenschutz) je Sprache aktuell
- [ ] Steuersätze und Rechnungsangaben geprüft
- [ ] Zahlungsarten im Live-Modus konfiguriert, Webhooks erreichbar
- [ ] Aufbewahrungs- und Löschfristen eingestellt
- [ ] Übersetzungen: neue Texte geprüft (`php tests/i18n-check.php` grün)
