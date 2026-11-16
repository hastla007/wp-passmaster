# Pass Captain – nächste 10 Umsetzungsschritte

Ziel ist ein eigenständig nutzbarer MVP für Events, Tickets und Online-Einlass. WooCommerce und Voucher Captain bleiben optionale Integrationen. Die Statusangaben wurden am 2026-11-14 mit `main` (Plugin 1.4.0 plus unveröffentlichte Änderungen) abgeglichen; das detaillierte Backlog steht in [TODO.md](TODO.md). WordPress-Abnahme steht noch aus.

## 1. Plugin-Grundgerüst — umgesetzt, WP-Abnahme offen

Plugin-Einstieg, Composer-Namespace, Aktivierungs-/Deaktivierungshooks und Admin-Menü sind angelegt. Plugin-Name und Textdomain lauten **Pass Captain** beziehungsweise `pass-captain` (umbenannt von „WP Pass Captain“; REST-Namensraum `wp-pass-captain/v1`, Blöcke und Shortcodes behalten bewusst die alten Namen).

## 2. Datenbankbasis und Berechtigungen — MVP angelegt

Versioniertes Schema für Events, Termine, Tickettypen, Bestellungen, Reservierungen, Tickets, Check-ins, Eventpersonal und Auditlogs; eigene Rollen und Capabilities; Einlasszugriff wird pro Event geprüft.

## 3. Events und Termine — umgesetzt

Admin-Formular für Events mit Termin, Zeitzone, Ort, Kapazität und Status. Events und Termine lassen sich vollständig bearbeiten und verschieben (mit Benachrichtigung und Audit-Log).

## 4. Ticketkategorien und Kontingente — Grundablauf angelegt

Eine Kategorie pro neuem Event mit Preis, Währung, Mengenlimit und Kontingent; Bestandsprüfung berücksichtigt Verkäufe und aktive Holds. Inzwischen sind mehrere Ticketarten pro Termin mit eigenem Steuersatz möglich; länderspezifische Steuerprofile fehlen noch.

## 5. Öffentliche Eventseite und Auswahl — MVP angelegt

Shortcode `[wp_pass_captain_events]`, dynamischer Gutenberg-Block und öffentliche REST-Eventliste.

## 6. Native Bestellung und Checkout — MVP angelegt

Öffentliche Bestellung mit E-Mail, Positionen, eigener Status und Idempotency-Key. Kostenlose Bestellungen geben Tickets aus; bezahlte Bestellungen laufen über die Payment-Adapter (Stripe, PayPal, Mollie) oder eine manuelle Bestätigung.

## 7. Reservierung — umgesetzt

Zehnminütige atomare Holds mit Datenbanktransaktionen und stündlicher Ablaufbereinigung. Signierte, idempotente PSP-Webhooks sind vorhanden; Sandbox-Abnahme verspäteter/mehrdeutiger Live-Ereignisse steht aus.

## 8. Zahlung und Ticketzustellung — umgesetzt, PSP-Live-Abnahme offen

Zahlungsadaptervertrag für manuelle Bestätigung; zufällige 256-Bit-Ticketcodes, gehashte Speicherung, E-Mail und private Druckseite mit optionalem QR-SVG. Online-Zahlung über Stripe, PayPal und Mollie sowie native Ticket-PDF (Mail-Anhang) sind inzwischen vorhanden.

## 9. Online-Check-in — umgesetzt

Kamera-QR-Scan und manuelle Codeeingabe, serverseitige Eventzuweisung, atomarer Einmal-Check-in und Auditprotokoll. Ein Offline-Scanner (PWA mit signierten Paketen und Konfliktklärung) ist inzwischen vorhanden.

## 10. Datenschutz, Qualität und Release — teilweise angelegt

WordPress-Export/-Löschung, CI-Syntaxprüfungen und ZIP-Artefakt-Workflow sind vorhanden. CI führt PHP-Lint, Integrations- und Lasttests (MariaDB) und Plugin Check aus; `composer.lock` ist vorhanden. Reproduzierbarkeit des Builds und eine echte WordPress-/WooCommerce-Versionsmatrix stehen aus.

## Danach

Die Schritte 11–30 mit Integrationen und Produkt-Ausbau stehen in [ROADMAP_11_30.md](ROADMAP_11_30.md).
