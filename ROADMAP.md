# Pass Captain – nächste 10 Umsetzungsschritte

Ziel ist ein eigenständig nutzbarer MVP für Events, Tickets und Online-Einlass. WooCommerce und Voucher Captain bleiben optionale Integrationen. Statusangaben beschreiben den aktuellen Branch; WordPress-Abnahme steht noch aus.

## 1. Plugin-Grundgerüst — umgesetzt, WP-Abnahme offen

Plugin-Einstieg, Composer-Namespace, Aktivierungs-/Deaktivierungshooks und Admin-Menü sind angelegt. Plugin-Name und Textdomain lauten **Pass Captain** beziehungsweise `pass-captain`.

## 2. Datenbankbasis und Berechtigungen — MVP angelegt

Versioniertes Schema für Events, Termine, Tickettypen, Bestellungen, Reservierungen, Tickets, Check-ins, Eventpersonal und Auditlogs; eigene Rollen und Capabilities; Einlasszugriff wird pro Event geprüft.

## 3. Events und Termine — Grundablauf angelegt

Admin-Formular für Events mit Termin, Zeitzone, Ort, Kapazität und Status. Bestehende Events lassen sich in dieser Etappe noch nicht vollständig bearbeiten.

## 4. Ticketkategorien und Kontingente — Grundablauf angelegt

Eine Kategorie pro neuem Event mit Preis, Währung, Mengenlimit und Kontingent; Bestandsprüfung berücksichtigt Verkäufe und aktive Holds. Steuerklassifikation und mehrere Kategorien pro Termin folgen später.

## 5. Öffentliche Eventseite und Auswahl — MVP angelegt

Shortcode `[wp_pass_captain_events]`, dynamischer Gutenberg-Block und öffentliche REST-Eventliste.

## 6. Native Bestellung und Checkout — MVP angelegt

Öffentliche Bestellung mit E-Mail, Positionen, eigener Status und Idempotency-Key. Kostenlose Bestellungen geben Tickets aus; bezahlte Bestellungen benötigen eine externe Zahlung und manuelle Bestätigung.

## 7. Reservierung — MVP angelegt

Zehnminütige atomare Holds mit Datenbanktransaktionen und stündlicher Ablaufbereinigung. PSP-Webhooks und automatische Zahlungswiederherstellung fehlen noch.

## 8. Zahlung und Ticketzustellung — teilweise umgesetzt

Zahlungsadaptervertrag für manuelle Bestätigung; zufällige 256-Bit-Ticketcodes, gehashte Speicherung, E-Mail und private Druckseite mit optionalem QR-SVG. Online-PSP-Anbindung und native PDF-Datei fehlen noch.

## 9. Online-Check-in — Scanner-MVP angelegt

Kamera-QR-Scan und manuelle Codeeingabe, serverseitige Eventzuweisung, atomarer Einmal-Check-in und Auditprotokoll. Offlinebetrieb fehlt.

## 10. Datenschutz, Qualität und Release — teilweise angelegt

WordPress-Export/-Löschung, CI-Syntaxprüfungen und ZIP-Artefakt-Workflow sind vorhanden. PHP-Lint ließ sich lokal mangels PHP-Laufzeit nicht ausführen; CI-Abnahme, Composer-Lockfile, vollständige Integrationstests und Reproduzierbarkeit stehen aus.

## Danach

Die Schritte 11–30 mit Integrationen und Produkt-Ausbau stehen in [ROADMAP_11_30.md](ROADMAP_11_30.md).
