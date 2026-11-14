# Kern frei, Premium im Add-on

Stand: 14.11.2026 (Plugin-Version 1.4.0 plus unveröffentlichte Änderungen laut `CHANGELOG.md`).
Grundlage: Entscheidung vom 13.11.2026 in `TODO.md`, Abschnitt „Kern-Plugin frei, Premium als getrenntes Add-on“.

## Regeln

1. **Pass Captain ist vollständig frei.** Das Kern-Plugin steht unter GPL-2.0-or-later, enthält keine
   Lizenzprüfung, keinen Lizenzschlüssel, keine Testphase, keine gesperrten oder ausgegrauten Funktionen
   und keine Updates von fremden Servern (WordPress.org-Richtlinien 5 und 8). Updates kommen ausschließlich
   über WordPress.org.
2. **Bereits ausgelieferte Funktionen bleiben dauerhaft frei.** Jede Funktion, die in einer veröffentlichten
   Version des Kerns enthalten ist, wird nie nachträglich gesperrt, eingeschränkt, hinter eine Lizenz gelegt
   oder in das Add-on verschoben. Das gilt auch für Grenzwerte (z. B. Anzahl Events, Tickets, Geräte,
   Termine): Der Kern führt keine künstlichen Mengengrenzen ein.
3. **Premium heißt: neue Funktionen im getrennten Add-on.** Premiumfunktionen werden im Add-on neu gebaut,
   nicht aus dem Kern herausgeschnitten. Das Add-on dockt nur über die dokumentierten Erweiterungspunkte an
   (siehe [EXTENSIONS.md](EXTENSIONS.md) und [HOOKS.md](HOOKS.md)) und spricht als einziges mit dem
   Lizenzserver.
4. **Der Kern funktioniert allein.** Ohne Add-on und ohne Netzwerkzugriff laufen alle Kernabläufe. Fällt
   das Add-on oder seine Lizenz weg, bleiben Daten, Tickets und Einlass des Kerns unberührt.
5. **Keine Werbung, die Funktionen vortäuscht.** Hinweise auf das Add-on höchstens dezent, wegklickbar und
   nur auf den eigenen Adminseiten; keine Menüpunkte, Schalter oder Felder, die erst nach Kauf funktionieren
   (Richtlinie 11).
6. **Technisch abgesichert.** Der Wächtertest `tests/guard/no-license-code.php` läuft in jeder CI und schlägt
   fehl, sobald im ausgelieferten Kern Lizenzclient, Rechteprüfung, `Update URI`-Header oder eigene
   Update-Hooks auftauchen. Diese Datei wird bei jeder neuen Kernfunktion ergänzt.

## Funktionsinventar: Kern, dauerhaft frei

Alle folgenden Funktionen sind Teil des Kern-Plugins und bleiben dauerhaft frei. „Modul“ bezeichnet eine
Klasse in `src/Modules/`; Module sind Bestandteil des Kerns, nicht des Add-ons.

### Events, Termine und Tickets

| Funktion | Ort im Code | Status |
|---|---|---|
| Events als Einzeltermin oder Serie anlegen, bearbeiten, absagen; Termine verschieben und absagen (mit Benachrichtigung, optional automatischer Erstattung) | `Admin/EventsPage`, `OccurrenceCancellation` | Kern, dauerhaft frei |
| Ticketarten mit Preis, Kontingent, Verkaufsfenster, Höchstmenge pro Bestellung, USt-Satz | `Admin/EventsPage`, `Orders` | Kern, dauerhaft frei |
| Teilnehmerfelder je Ticketart (Pflicht/optional), Teilnehmerdaten je Ticket | `Admin/EventsPage`, `TicketAssignments` | Kern, dauerhaft frei |
| Serientermine (täglich, wöchentlich, monatlich, Datumsliste; Ausnahmen, Vorschau, zeitzonensicher) | Modul `Series`, `Series/Generator`, `Series/Rules` | Kern, dauerhaft frei |
| Serien-Preisstufen (Frühbucher, Last-Minute, Nachfrage, Wochentag/Zeitfenster) und gemeinsame Serien-Kontingente | `Series/Pricing`, `Series/Pools` | Kern, dauerhaft frei |
| Saalpläne: SVG-Editor, versionierte Layouts, barrierearme Platzwahl, atomare Platzreservierung | Modul `Seating`, `Seating/*`, `Seats` | Kern, dauerhaft frei |
| Service-Zeitfenster (Buchung mit Kontingent) | `ServiceBookings` | Kern, dauerhaft frei |
| Mitgliedspässe mit Nutzungslimits | `Memberships` | Kern, dauerhaft frei |
| Übersetzte Eventinhalte | `Localization` | Kern, dauerhaft frei |
| Ausgabe per Shortcode `[wp_pass_captain_events]` und Block; Eventkarte im Theme überschreibbar (`templates/event-card.php`) | `PublicEvents`, `src/blocks/events` | Kern, dauerhaft frei |
| Kalender: Shortcode `[wp_pass_captain_calendar]`, Block, iCal-Download und -Feed, `.ics` in der Ticket-Mail | Modul `Calendar`, `Calendar/Ics`, `src/blocks/calendar` | Kern, dauerhaft frei |

### Checkout, Bestellungen und Zahlungen

| Funktion | Ort im Code | Status |
|---|---|---|
| Eigener Checkout mit mehreren Ticketarten, zeitlich begrenzter Reservierung, Verbraucherinformationen und gespeicherter Bedingungsversion | `Orders`, `Rest/Controller`, `templates/event-card.php` | Kern, dauerhaft frei |
| Kostenlose Bestellungen mit sofortiger Ticketausgabe | `Orders` | Kern, dauerhaft frei |
| Rechnungsangaben (Firma, Anschrift, UID-Prüfung, Pflicht ab Schwellenwert) | `Billing` | Kern, dauerhaft frei |
| Zahlung manuell / per Überweisung (Bankdaten, Verwendungszweck, Zahlungsfrist) | `Payments/ManualGateway`, `Payments/BankTransfer` | Kern, dauerhaft frei |
| Stripe Checkout, PayPal (Orders v2) und Mollie mit signierten, idempotenten Webhooks und Erstattung über den Anbieter | `Payments/StripeGateway`, `Payments/PayPalGateway`, Modul `Mollie` | Kern, dauerhaft frei |
| Kampagnencodes (Prozent/Festbetrag, Bereich, atomares Nutzungslimit) | `Campaigns` | Kern, dauerhaft frei |
| Wartelisten mit befristeten Angeboten und direkter Bestellung bei Annahme | `Waitlists` | Kern, dauerhaft frei |
| Bestellportal für Käufer: Tickets ansehen, erneut senden, übertragen, Teilnehmer zuweisen, Rechnungsdaten korrigieren | `OrderPortal`, `TicketPage`, `TicketAssignments` | Kern, dauerhaft frei |
| Erstattungen positionsbezogen, idempotent, mit Zweitfreigabe ab Schwellenwert und optionaler Zwei-Faktor-Pflicht | `Refunds`, `Admin/RefundsPage` | Kern, dauerhaft frei |
| Admin: Bestellungen (Details, Bezahlt markieren, Storno, Erstattung, Freigaben), Tickets (Suche, Widerruf, Neuausstellung, Check-in rückgängig) | `Admin/OrdersPage`, `Admin/TicketsPage` | Kern, dauerhaft frei |
| Abendkasse / Vor-Ort-Verkauf: gemeinsames Kontingent, Zahlarten, lückenlose Bons, 80-mm-Druck, Kassensitzungen, Storno mit Freigabe | Modul `BoxOffice`, `BoxOffice/*` | Kern, dauerhaft frei |

### Tickets, Einlass und Scanner

| Funktion | Ort im Code | Status |
|---|---|---|
| Tickets mit zufälligem 256-Bit-Code (nur Hash gespeichert), QR-Code, PDF-Anhang | `Orders`, `QrCode`, `TicketPdf` | Kern, dauerhaft frei |
| Ticketübertragung und Teilnehmerwechsel mit Begrenzung | `Orders`, `TicketAssignments` | Kern, dauerhaft frei |
| Web-Scanner per Shortcode `[wp_pass_captain_scanner]` (BarcodeDetector, Fallback jsQR) mit Offline-Warteschlange | `Checkin`, `assets/scanner.js` | Kern, dauerhaft frei |
| Einlasspersonal je Event, Check-in-Protokoll | `Access`, `Admin/CheckinsPage` | Kern, dauerhaft frei |
| Einlassregeln (einmalig, täglich, mehrtägig, mehrfach, Wiedereinlass), Einlassfenster, Check-out, Einlassbereiche, Einlass-Tageswechsel, Klärungsliste für Konflikte | Modul `Admission`, `Admission/*` | Kern, dauerhaft frei |
| Mobile Scanner-API mit Gerätekopplung per QR und widerrufbaren Gerätetokens; Admin-Seite „Scanner-Geräte“ | `Mobile/Api`, `Mobile/Devices`, `Admin/DevicesPage` | Kern, dauerhaft frei |
| Offline-Scanner (PWA) mit Ed25519-signierten Prüfpaketen, Delta-Paketen, Schlüsselrotation und Konfliktabgleich | Modul `OfflineScanner`, `OfflineScanner/*` | Kern, dauerhaft frei |

### Kommunikation

| Funktion | Ort im Code | Status |
|---|---|---|
| Zentraler Mailer mit E-Mail-Vorlagen je Sprache, HTML-Layout, Vorschau und Testmail | Modul `MailTemplates`, `Mail/*` | Kern, dauerhaft frei |
| Nachrichtenwarteschlange mit Zeitbudget und geplanten Nachrichten | `Communications`, `Notifications` | Kern, dauerhaft frei |
| Erinnerungen zu mehreren Zeitpunkten vor Beginn | Modul `Reminders`, `Reminders/Placeholders` | Kern, dauerhaft frei |
| SMS über Twilio oder generischen HTTP-Anbieter, nur mit Einwilligung, mit Zustellstatus | Modul `Sms`, `Sms/*` | Kern, dauerhaft frei |
| Apple Wallet (signierte Pässe, Update-Webservice, APNs-Push) und Google Wallet (Speichern-Link, Status-Updates) | Modul `Wallet`, `WalletPasses/*`, `Wallet/Bridge` | Kern, dauerhaft frei |
| Signierte, versionierte ausgehende Webhooks mit Wiederholungen | Modul `Webhooks`, `Webhooks/Dispatcher` | Kern, dauerhaft frei |

### Belege, Berichte und Exporte

| Funktion | Ort im Code | Status |
|---|---|---|
| Rechnungen und Rechnungskorrekturen (HTML/PDF) mit transaktionaler, lückenloser Nummer; CSV-Buchhaltungsexport | `Invoices`, `Printing/InvoicePdf` | Kern, dauerhaft frei |
| Einzweck-/Mehrzweckgutscheine steuerlich korrekt behandeln | `Orders`, `Integrations/Vouchers/Checkout` | Kern, dauerhaft frei |
| Druckvorlagen (Ticket, Badge, Namensschild, Armband), Sammeldruck, Teilnehmerliste (Druck/CSV, rollenbasiert) | Modul `PrintTemplates`, `Printing/*` | Kern, dauerhaft frei |
| Berichte: Umsatz mit USt je Satz und Erstattungen, Auslastung, Einlassquote und -verlauf, Kasse vs. online; CSV und SVG-Diagramme; rollenbeschränkt | Modul `Reports`, `Reports/*` | Kern, dauerhaft frei |
| Veranstalter (Eigenhändler/Vermittler), Veranstalter-Abrechnungen, Provisionsrechnungen/Gutschriften | Modul `Partners`, `Partners/*` | Kern, dauerhaft frei |
| Partner-API (nur lesend) mit API-Keys, Scopes, IP-Allowlist, Ablauf, Widerruf | `Partners/Api`, `Partners/Keys` | Kern, dauerhaft frei |
| Eventgebundene Read-only-Entwickler-Schlüssel | `DeveloperKeys` | Kern, dauerhaft frei |
| Länder-/Steuerprofile als Entwürfe | `Installer` (Datenmodell) | Kern, dauerhaft frei |

### Integrationen

| Funktion | Ort im Code | Status |
|---|---|---|
| WooCommerce-Adapter (HPOS, Classic- und Block-Checkout, Reservierung vor Zahlung, Ticketausgabe, Storno/Erstattung, Teilnehmerfelder, Serien-Preise im Warenkorb) | `Integrations/WooCommerce/*` | Kern, dauerhaft frei |
| WP Voucher Captain: Gutscheine im Checkout, Gutschein → Ticket, gemeinsamer Scanner | `Integrations/Vouchers/*` | Kern, dauerhaft frei |
| WP Affiliate Captain: Verkäufe und Erstattungen melden, Partnercode im Checkout | `Integrations/AffiliateCaptain/Adapter` | Kern, dauerhaft frei |
| Connector-Vertrag v1 und generische Job-Queue für eigene Adapter | `Integrations/ConnectorRegistry`, `Jobs` | Kern, dauerhaft frei |
| Fiskal-Adapter (fiskaltrust, efsta) als Bausteine; derzeit nicht im Ablauf verdrahtet | `Fiscalization/Providers/*` | Kern, dauerhaft frei |

### Datenschutz, Sicherheit und Betrieb

| Funktion | Ort im Code | Status |
|---|---|---|
| Datenschutz-Export und -Löschung über die WordPress-Werkzeuge, zurückgestellte Löschung bei kommenden Terminen | `Privacy` | Kern, dauerhaft frei |
| Täglicher Löschlauf mit Aufbewahrungsfristen; Vorschlag für die Datenschutzerklärung | `Privacy/Retention`, `Privacy/PolicyText` | Kern, dauerhaft frei |
| Rollen und Rechte (Ticket-Administration, Veranstaltungsleitung, Kasse, Einlass, Veranstalter, Finanzbuchhaltung, Support) | `Access`, `Installer` | Kern, dauerhaft frei |
| Audit-Log, Ratenbegrenzung | `Audit`, `RateLimit` | Kern, dauerhaft frei |
| Datenbank-Upgrade hinter Sperre (Admin, Cron, WP-CLI); Multisite mit getrennten Daten je Site | `Installer`, `Plugin` | Kern, dauerhaft frei |
| Übersetzungen de (Ausgangssprache), en_US, fr_FR, it_IT, es_ES, nl_NL | `languages/` | Kern, dauerhaft frei |

## Premium-Add-on

Das Add-on (Arbeitstitel `wp-pass-captain-pro`, eigenes Repository) enthält ausschließlich **neue**
Funktionen, die im Kern nie ausgeliefert wurden, dazu Lizenzseite, Rechteprüfung, Offline-Cache und
Updater. Welche Funktionen das sind, wird im Add-on-Repository festgelegt (TODO B5) – nicht in dieser Datei.
Bevor eine Funktion dort geplant wird, ist hier zu prüfen, dass sie nicht schon im Inventar oben steht.
