# Kern frei, Premium im Add-on

**Entscheidung vom 13.11.2026** (TODO.md, „Kern-Plugin frei, Premium als getrenntes Add-on“): Pass Captain
bleibt als Kern-Plugin vollständig kostenlos (GPL-2.0-or-later), ohne Lizenzprüfung und ohne gesperrte
Funktionen – geeignet für WordPress.org. Premiumfunktionen gibt es nur in einem **getrennten Add-on**
`pass-captain-pro` mit eigenem Shop, eigenem Updater und eigenem Lizenzschlüssel; **nur das Add-on** spricht mit
dem Lizenzserver WP License Captain.

## Regeln

1. **Alles, was das Kern-Plugin heute ausliefert, ist „Kern, dauerhaft frei“.** Es wird nie nachträglich
   gesperrt, eingeschränkt (Mengen-, Zeit- oder Funktionslimits) oder ins Add-on verschoben. Das gilt für jede
   Funktion im Inventar unten, auch für die, die erst mit dem nächsten Release erscheinen.
2. **Das Add-on fügt nur hinzu.** Premiumfunktionen werden im Add-on neu gebaut und docken über die
   öffentlichen Hooks und Adapter-Verträge an ([HOOKS.md](HOOKS.md), [CONTRACTS.md](CONTRACTS.md),
   [EXTENSIONS.md](EXTENSIONS.md)). Läuft eine Add-on-Lizenz ab oder fällt der Lizenzserver aus, betrifft das
   nur Add-on-Funktionen; Tickets, Einlass und alle Kernabläufe laufen weiter.
3. **Kein Lizenz- oder Updater-Code im Kern:** kein `LicenseClient`, keine Route `wplc/v1`, kein Header
   `Update URI`, keine eigenen Filter auf `pre_set_site_transient_update_plugins` oder `plugins_api`, keine
   Rechteabfragen wie `allows_entitlement`. Erzwungen durch `tests/license-guard.php` in der CI (nur
   ausgelieferte Dateien, siehe [TESTING.md](TESTING.md#kein-lizenz--oder-updater-code-im-kern-a661)).
   Updates des Kerns kommen ausschließlich von WordPress.org.
4. **Keine Köder im Kern** (WordPress.org-Richtlinien 5 und 11): keine Menüpunkte, Schalter oder Felder, die
   sichtbar sind, aber erst nach einem Kauf funktionieren; Hinweise auf das Add-on höchstens dezent,
   wegklickbar und nur auf den eigenen Adminseiten.
5. **Inventar aktuell halten:** Jedes neue Modul unter `src/Modules/` und jeder neue Bereich unter `src/` wird
   hier eingetragen. `tests/consistency.php` schlägt fehl, wenn eine Modul-ID aus der Modulregistrierung
   (`PassCaptain\Modules::classes()`, Dateien `src/Modules/*.php`) oder ein Ordner unter `src/` fehlt.

## Inventar: Module

Module sind die optionalen Funktionsbausteine unter `src/Modules/` (siehe `src/Modules.php`). Alle sind
standardmäßig aktiv; Betreiber können einzelne über `WPCAP_DISABLED_MODULES` oder den Filter
`wpcap_module_enabled` abschalten – das ist eine Betreiberentscheidung, keine Lizenzgrenze. „Seit“ nennt das
erste Release, das das Modul enthielt („nächstes Release“: in `main`, noch nicht veröffentlicht).

| Modul-ID | Funktion | Seit | Status |
|---|---|---|---|
| `Admission` | Einlassregeln je Ticketart (einmalig, Tages-/Mehrtagesticket, Mehrfacheinlass, Wiedereinlass), Einlassfenster, Check-out und Anwesenheit, Einlassbereiche, Gerätesynchronisation, Einlass-Konflikte | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Allocations` | Kontingente (Holds/Kills) je Termin oder Ticketart, Sitzplatz-Holds, Kaufgrenzen je Käufer ([ALLOCATIONS.md](ALLOCATIONS.md)) | nächstes Release | Kern, dauerhaft frei |
| `BoxOffice` | Kasse: Kassen, Schichten, Übergabe, Z-Berichte, Schnellverkauf, Belege, Storno (keine Registrierkasse, siehe OPERATIONS.md) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Calendar` | Eventkalender (Shortcode/Block, Monats- und Listenansicht), iCalendar-Downloads und -Feeds | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Exchange` | Ticketumtausch in einen anderen Termin/eine andere Ticketart: Admin, Bestellportal, Kasse ([EXCHANGE.md](EXCHANGE.md)) | nächstes Release | Kern, dauerhaft frei |
| `GuestLists` | Gästelisten und Freikarten, Scanner-Abfrage ([GUEST-LISTS.md](GUEST-LISTS.md)) | nächstes Release | Kern, dauerhaft frei |
| `MailTemplates` | E-Mail-Vorlagen-Editor je Sprache ([MAIL-TEMPLATES.md](MAIL-TEMPLATES.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Mollie` | Zahlungen und Erstattungen über Mollie, Zahlartauswahl im Checkout | ≤ 1.1.0 | Kern, dauerhaft frei |
| `OfflineScanner` | Installierbarer Offline-Scanner (PWA) mit Schlüsseln, Zuweisungen und Synchronisation ([OFFLINE-SCANNER.md](OFFLINE-SCANNER.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Partners` | Veranstalter/Partner, Partner-API-Schlüssel, Verkaufs-API, monatliche Abrechnungen ([PARTNER-API.md](PARTNER-API.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Passes` | Festival- und Mehrterminpässe über mehrere Termine/Events ([PASSES.md](PASSES.md)) | nächstes Release | Kern, dauerhaft frei |
| `PrintTemplates` | Druckvorlagen (Ticket, Badge, Namensschild, Armband), Teilnehmer- und No-Show-Listen, Rechnungs-PDFs | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Reminders` | Erinnerungen X Stunden vor Beginn (mehrere Zeitpunkte, E-Mail/SMS) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Reports` | Berichte: Umsatz, USt, Erstattungen, Auslastung, Check-in, Kanäle, Abrechnung je Termin | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Seating` | Saalplan-Editor mit Versionen, Sitzplatzwahl im Checkout, atomare Sitz-Holds | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Series` | Serientermine (täglich, wöchentlich, monatlich, Datumslisten), Serienpreise, Kontingent-Pools ([SERIES.md](SERIES.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Sms` | SMS-Benachrichtigungen mit Opt-in, Einwilligungsnachweis, Opt-out (STOP/START), Twilio und HTTP-Gateway | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Waitlist` | Wartelisten mit automatischen Angeboten bei frei werdenden Plätzen ([WAITLIST.md](WAITLIST.md)) | nächstes Release | Kern, dauerhaft frei |
| `Wallet` | Apple Wallet (PassKit-Webservice, APNs-Updates) und Google Wallet ([WALLET.md](WALLET.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Webhooks` | Ausgehende, signierte Webhooks mit Warteschlange und Wiederholung ([WEBHOOKS.md](WEBHOOKS.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |

## Inventar: Kernbereiche

Alles außerhalb der Module gehört zum Grundgerüst und ist ebenfalls **Kern, dauerhaft frei**.

| Bereich | Inhalt | Status |
|---|---|---|
| Events, Termine, Ticketarten | `src/Occurrence.php`, `src/OccurrenceCancellation.php`, `src/DoorsOpen.php`, `src/Admin/` (Events, Termine, Ticketarten, Einstellungen, Audit-Log, Geräte, Entwicklerschlüssel), `src/Calendar/` (Revisionen, ICS) | Kern, dauerhaft frei |
| Checkout und Bestellungen | `src/Orders.php`, `src/OrderLines.php`, `src/PublicEvents.php`, `src/Frontend/` (Eventkarte, Service-Buchung), `src/blocks/` (Blöcke Events, Kalender, Service-Buchung), Reservierungen, Kapazitätszähler (`src/CapacityCounters.php`, `src/CapacityRelease.php`) | Kern, dauerhaft frei |
| Zahlungen | `src/Payments/` (manuell/Überweisung, Stripe, PayPal, Registry), `src/Mollie/` (Gateway des Moduls `Mollie`), Erstattungen mit Freigabe (`src/Refunds.php`), Auszahlungskonto (`src/PayoutAccount.php`) | Kern, dauerhaft frei |
| Tickets und Einlass | `src/TicketPage.php`, `src/TicketPdf.php`, `src/QrCode.php`, `src/Checkin.php`, `src/AdmissionChecks.php`, `src/Admission/`, `src/TicketAssignments.php`, `src/StaffAssignments.php`, `src/Mobile/` (Mobil-API, Geräte), `src/OfflineScanner/` | Kern, dauerhaft frei |
| Rechnungen, Steuern, Verkaufsmodelle | `src/Invoices.php`, `src/Billing.php`, `src/Money.php`, `src/Tax/`, Verkaufsmodelle Eigenhändler/Vermittler ([SALES-MODELS.md](SALES-MODELS.md)) | Kern, dauerhaft frei |
| Kasse, Kontingente, Umtausch, Pässe, Gästelisten | `src/BoxOffice/`, `src/Allocations/`, `src/Exchange/`, `src/Passes/`, `src/GuestLists/`, `src/Seats.php`, `src/Seating/`, `src/Series/` | Kern, dauerhaft frei |
| Kommunikation | `src/Mail/` (Versand, Vorlagen, Layout), `src/Sms/`, `src/Reminders/`, `src/Notifications.php`, `src/Communications.php`, `src/Consents.php`, `src/Webhooks/` | Kern, dauerhaft frei |
| Wallet | `src/Wallet/`, `src/WalletPasses/` (Apple, Google) | Kern, dauerhaft frei |
| Berichte und Druck | `src/Reports/`, `src/Printing/` | Kern, dauerhaft frei |
| Wartelisten, Kampagnen, Mitgliedschaften, Service-Buchungen | `src/Waitlists.php`, `src/Waitlists/`, `src/Campaigns.php`, `src/Memberships.php`, `src/ServiceBookings.php`, `src/ServiceBookingFlow.php`, `src/ServiceProviders.php` | Kern, dauerhaft frei |
| Partner und Integrationen | `src/Partners/`, `src/Integrations/` (WooCommerce, Affiliate Captain, Voucher Captain, Connector-Vertrag) | Kern, dauerhaft frei |
| Bestellportal und Selbstbedienung | `src/OrderPortal.php`, `src/PortalSelfService.php` | Kern, dauerhaft frei |
| Datenschutz und Sicherheit | `src/Privacy.php`, `src/Privacy/` (Aufbewahrung, Zugriffsprotokoll), `src/Pii.php`, `src/Redact.php`, `src/Secrets.php`, `src/Access.php`, `src/UserAccess.php` (Rechte-Rücknahme bei Rollenentzug), `src/RateLimit.php`, `src/ClientIp.php`, `src/DeveloperKeys.php`, `src/Audit.php` | Kern, dauerhaft frei |
| Betrieb | `src/Operations/` (Doctor, Monitor, Warteschlangen, Website-Zustand), `src/Jobs.php`, `src/Log.php` (Support-Protokoll), `src/Jobs/`, `src/BulkRuns.php`, `src/PageCache.php`, `src/Installer.php`, `src/Rest/` (REST-API), `src/Http.php`, `src/OutboundHttp.php`, `src/Localization.php`, `src/LegacySlug.php` (Umbenennung) |
| Grundgerüst | `src/Plugin.php`, `src/Admin.php`, `src/Settings.php`, `src/Modules.php` (Modulregistrierung), `src/Hooks.php`, `src/Contracts.php` (Vertragsversionen), `src/StateMachine.php`, `src/Clock.php`, `src/Errors.php`, `src/HelpCode.php` | Kern, dauerhaft frei | Kern, dauerhaft frei |

## Nicht ausgeliefert

| Bereich | Stand |
|---|---|
| `src/Fiscalization/` (Adapter fiskaltrust, efsta) | Liegt im Repository, wird aber bis zum Merge des Fiskalisierungskerns (PR #14) per `.distignore` **nicht** ausgeliefert und in `readme.txt` nicht beworben (A666): Das Interface `PassCaptain\Fiscalization\AdapterInterface` fehlt, nichts lädt die Adapter. **Offene Entscheidung:** Ob die Fiskalisierung mit PR #14 in den Kern kommt (dann „Kern, dauerhaft frei“) oder als Premiumfunktion im Add-on entsteht, ist noch festzulegen – sie war nie Teil eines Releases. |

## Premium-Add-on `pass-captain-pro`

Eigenes Repository, eigener Slug, eigene Textdomain, eigener Namespace; Plugin-Header mit `Requires Plugins`
(Kern) und `Update URI` auf den eigenen Shop. Das Add-on enthält Lizenzaktivierung, Rechteprüfung (Schlüssel
mit Präfix `pass.`), signierten Offline-Cache und den Updater. Fehlt ein Recht, ist nur die jeweilige
Add-on-Funktion aus; der Kern bleibt unberührt. Welche neuen Funktionen dort entstehen, ist eine
Produktentscheidung – keine davon darf eine Funktion aus dem Inventar oben ersetzen oder sperren.
