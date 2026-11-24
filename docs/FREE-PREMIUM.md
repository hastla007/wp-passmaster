# Kern frei, Premium im Add-on

Stand: 14.11.2026 (Plugin-Version 1.4.0 plus unveröffentlichte Änderungen laut `CHANGELOG.md`).
Grundlage: Entscheidung vom 13.11.2026 in `TODO.md`, Abschnitt „Kern-Plugin frei, Premium als getrenntes Add-on“.

**Entscheidung vom 13.11.2026** (TODO.md, „Kern-Plugin frei, Premium als getrenntes Add-on“): Pass Captain
bleibt als Kern-Plugin vollständig kostenlos (GPL-2.0-or-later), ohne Lizenzprüfung und ohne gesperrte
Funktionen – geeignet für WordPress.org. Premiumfunktionen gibt es nur in einem **getrennten Add-on**
`pass-captain-pro` mit eigenem Shop, eigenem Updater und eigenem Lizenzschlüssel; **nur das Add-on** spricht mit
dem Lizenzserver WP License Captain.

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
   Updates des Kerns kommen ausschließlich von WordPress.org. Dass alle Kernabläufe ohne Add-on und ohne
   Netzwerkzugriff laufen und der Kern nie einen Lizenzserver aufruft, prüft
   `tests/integration/modules/zz_a664.php` (A664, [TESTING.md](TESTING.md#kern-läuft-allein-a664)).
4. **Keine Köder im Kern** (WordPress.org-Richtlinien 5 und 11): keine Menüpunkte, Schalter oder Felder, die
   sichtbar sind, aber erst nach einem Kauf funktionieren; Hinweise auf das Add-on höchstens dezent,
   wegklickbar und nur auf den eigenen Adminseiten. Technisch nur über den Filter `wpcap_addon_notices`:
   `PassCaptain\Admin\Notices` gibt solche Hinweise als schlichten Text (`notice-info`) nur auf Pass-Captain-Seiten
   und nur für Administrator:innen aus, höchstens drei, jeder je Benutzer:in wegklickbar (A301, TODO A6).
   Eigene Hinweise des Kerns (Empfehlungen) folgen derselben Regel.
   **Hinweiszeilen (SET-PC-06, Entscheidung E1 = (a), S1, E8/S5 vom 23.11.2026):** Erlaubt ist genau eine
   statische Textliste in `src/Admin/ProHints.php` – je verkaufter Pro-Funktion eine graue Textzeile am Ende
   eines Einstellungsreiters (Kennung, Reiter, Titel, Text, Link), ohne Feld, ohne Schalter, ohne Funktions-
   und ohne Lizenzcode, je Person ausblendbar. Meldet das Add-on ein Feld mit derselben Kennung an, ersetzt
   das Feld die Zeile. Die Liste bleibt leer, bis eine Funktion verkauft wird. Der Lizenz-Wächter bleibt
   unverändert streng.
5. **Inventar aktuell halten:** Jedes neue Modul unter `src/Modules/` und jeder neue Bereich unter `src/` wird
   hier eingetragen. `tests/consistency.php` schlägt fehl, wenn eine Modul-ID aus der Modulregistrierung
   (`PassCaptain\Modules::classes()`, Dateien `src/Modules/*.php`) oder ein Ordner unter `src/` fehlt.

## Inventar: Module

Module sind die optionalen Funktionsbausteine unter `src/Modules/` (siehe `src/Modules.php`). Alle sind
standardmäßig aktiv; Betreiber können einzelne unter Einstellungen → Allgemein → Funktionen („Funktionen abschalten“), über `WPCAP_DISABLED_MODULES` oder den Filter
`wpcap_module_enabled` abschalten – das ist eine Betreiberentscheidung, keine Lizenzgrenze. „Seit“ nennt das
erste Release, das das Modul enthielt („nächstes Release“: in `main`, noch nicht veröffentlicht).

| Modul-ID | Funktion | Seit | Status |
|---|---|---|---|
| `Admission` | Einlassregeln je Ticketart (einmalig, Tages-/Mehrtagesticket, Mehrfacheinlass, Wiedereinlass), Einlassfenster, Check-out und Anwesenheit, Einlassbereiche, Gerätesynchronisation, Einlass-Konflikte | ≤ 1.1.0 | Kern, dauerhaft frei |
| `Allocations` | Kontingente (Holds/Kills) je Termin oder Ticketart, Sitzplatz-Holds, Kaufgrenzen je Käufer ([ALLOCATIONS.md](ALLOCATIONS.md)) | nächstes Release | Kern, dauerhaft frei |
| `BoxOffice` | Kasse: Kassen, Schichten, Übergabe, Z-Berichte, Schnellverkauf, Belege, Storno (keine Registrierkasse, siehe OPERATIONS.md) | ≤ 1.1.0 | Kern, dauerhaft frei |
| `FormCaptain` | Optionaler Adapter zu Form Captain: Formular je Event, signierter Event-Kontext, ausdrückliche Übergabe freigegebener Felder als Anfrage (nie Buchung/Zahlung); ohne Form Captain inaktiv ([EXTENSIONS.md](EXTENSIONS.md)) | 1.5.0 | Kern, dauerhaft frei |
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
| `Connectors` | Generischer HTTP/JSON-Connector mit Feldzuordnung (CRM, Zapier, Make, n8n), iCal-Abo-Feeds | nächstes Release | Kern, dauerhaft frei |
| `Fiscalization` | Fiskalisierung je Verkäufer (fiskaly, efsta, fiskaltrust), Belege vor der Ticketausgabe, Abgleich | nächstes Release | Kern, dauerhaft frei |
| `SalesRules` | Verkaufsregeln: Preisregeln, Käuferlimits, Stapelregeln, Steuerprofile je Bestellung | nächstes Release | Kern, dauerhaft frei |
| `Services` | Servicebuchungen: Ressourcen, Zeitfenster, Puffer, Fristen, Umbuchung mit Nachzahlung, No-show, Warteliste | nächstes Release | Kern, dauerhaft frei |
| `Webhooks` | Ausgehende, signierte Webhooks mit Warteschlange und Wiederholung ([WEBHOOKS.md](WEBHOOKS.md)) | ≤ 1.1.0 | Kern, dauerhaft frei |

## Inventar: Kernbereiche

Alles außerhalb der Module gehört zum Grundgerüst und ist ebenfalls **Kern, dauerhaft frei**.

| Bereich | Inhalt | Status |
|---|---|---|
| Events, Termine, Ticketarten | `src/Occurrence.php`, `src/OccurrenceCancellation.php`, `src/DoorsOpen.php`, `src/Admin/` (Events, Termine, Ticketarten, Einstellungen, Audit-Log, Geräte, Entwicklerschlüssel), `src/Calendar/` (Revisionen, ICS) | Kern, dauerhaft frei |
| Checkout und Bestellungen | `src/Orders.php`, `src/OrderLines.php`, `src/ParticipantFields.php` (Teilnehmerfeld-Vertrag), `src/PublicEvents.php`, `src/Frontend/` (Eventkarte, Service-Buchung), `src/blocks/` (Blöcke Events, Kalender, Service-Buchung), Reservierungen, Kapazitätszähler (`src/CapacityCounters.php`, `src/CapacityRelease.php`) | Kern, dauerhaft frei |
| Zahlungen | `src/Payments/` (manuell/Überweisung, Stripe, PayPal, Registry), `src/Mollie/` (Gateway des Moduls `Mollie`), Erstattungen mit Freigabe (`src/Refunds.php`), Auszahlungskonto (`src/PayoutAccount.php`), Servicegebühr (`src/ServiceFee.php`) | Kern, dauerhaft frei |
| Querschnitt (zusammengeführter Stand) | `src/Api/` (Lese-API für Add-ons), `src/Ops/` (Heartbeats, Systemstatus), `src/Orders/` (Bestellportal-Zugang; `src/Orders/CheckoutState.php`: interner Zustand eines Checkout-Versuchs, @internal, A401), `src/Sales/` (Checkout-Gate, Drosselung, Sichtbarkeit, Freikarten, Self-Service, Einwilligungen), `src/Security/` (Vault, Redaction, Step-up, private Seiten, signierte QR-Codes), `src/Services/` (Servicebuchungen), `src/Brand.php`, `src/DateInput.php`, `src/DbSession.php`, `src/Diagnostics.php`, `src/DisplayBatch.php`, `src/LegacyCopy.php`, `src/RescheduleRefunds.php`, `src/ScannerAdapters.php`, `src/ServiceResources.php`, `src/ServiceWaitlist.php`, `src/TempFiles.php`, `src/TicketTypeCopier.php`, `src/functions.php` | Kern, dauerhaft frei |
| Tickets und Einlass | `src/TicketPage.php`, `src/TicketRecovery.php` (Ticketlinks per E-Mail anfordern), `src/TicketPdf.php`, `src/QrCode.php`, `src/Checkin.php`, `src/CodeHistory.php` (ersetzte Codes, Widerrufsgründe), `src/ScannerDesk.php` (Rückgängig, Zähler und Suche am Scanner), `src/AdmissionChecks.php`, `src/Admission/`, `src/TicketAssignments.php`, `src/StaffAssignments.php`, `src/Mobile/` (Mobil-API, Geräte), `src/OfflineScanner/` | Kern, dauerhaft frei |
| Rechnungen, Steuern, Verkaufsmodelle | `src/Invoices.php`, `src/Billing.php`, `src/Money.php`, `src/Tax/`, Verkaufsmodelle Eigenhändler/Vermittler ([SALES-MODELS.md](SALES-MODELS.md)) | Kern, dauerhaft frei |
| Kasse, Kontingente, Umtausch, Pässe, Gästelisten | `src/BoxOffice/`, `src/Allocations/`, `src/Exchange/`, `src/Passes/`, `src/GuestLists/`, `src/Seats.php`, `src/Seating/`, `src/Series/` | Kern, dauerhaft frei |
| Kommunikation | `src/Mail/` (Versand, Vorlagen, Layout), `src/Sms/`, `src/Reminders/`, `src/Notifications.php`, `src/Communications.php`, `src/Consents.php`, `src/Webhooks/`, `src/Review/` (Review-Teilnahmevertrag, Kontakt-Resolver), `src/Suite/` (gemeinsame Vertragsmechanik der Captain-Suite und Ereignis-Outbox des Review-Vertrags; kein Workflow-Vertrag, PC-7) | Kern, dauerhaft frei |
| Wallet | `src/Wallet/`, `src/WalletPasses/` (Apple, Google) | Kern, dauerhaft frei |
| Berichte und Druck | `src/Reports/`, `src/Printing/` | Kern, dauerhaft frei |
| Wartelisten, Kampagnen, Mitgliedschaften, Service-Buchungen | `src/Waitlists.php`, `src/Waitlists/`, `src/Campaigns.php`, `src/Memberships.php`, `src/ServiceBookings.php`, `src/ServiceBookingFlow.php`, `src/ServiceProviders.php` | Kern, dauerhaft frei |
| Partner und Integrationen | `src/Partners/`, `src/Integrations/` (WooCommerce, Affiliate Captain, Voucher Captain, Form Captain, Connector-Vertrag) | Kern, dauerhaft frei |
| Bestellportal und Selbstbedienung | `src/OrderPortal.php`, `src/PortalSelfService.php`, `src/OrderLinkRequest.php` (Bestelllinks per E-Mail anfordern) | Kern, dauerhaft frei |
| Datenschutz und Sicherheit | `src/Privacy.php`, `src/Privacy/` (Aufbewahrung, Zugriffsprotokoll), `src/Pii.php`, `src/Redact.php`, `src/Secrets.php`, `src/Security/OneTimeSecret.php` (einmal angezeigte Schlüssel, verschlüsselt), `src/Access.php`, `src/UserAccess.php` (Rechte-Rücknahme bei Rollenentzug), `src/TicketCodes.php` (verschlüsselt gespeicherte Ticketcodes), `src/RateLimit.php`, `src/ClientIp.php`, `src/DeveloperKeys.php`, `src/Audit.php`, `src/Alerts.php` (Betriebsalarme an einstellbaren Empfänger), `src/Config.php` (Laufzeitwerte per Filter) | Kern, dauerhaft frei |
| Betrieb | `src/Operations/` (Doctor, Monitor, Warteschlangen, Website-Zustand), `src/Jobs.php`, `src/Log.php` (Support-Protokoll), `src/Jobs/`, `src/BulkRuns.php`, `src/PageCache.php`, `src/Installer.php`, `src/Rest/` (REST-API), `src/Http.php`, `src/OutboundHttp.php`, `src/Localization.php`, `src/LegacySlug.php` (Umbenennung), `src/LegacyNames.php` (frühere Block- und Shortcode-Namen) |
| Grundgerüst | `src/Plugin.php`, `src/Admin.php`, `src/Settings.php`, `src/SettingsSchema.php` (Einstellungs-Register, SET-PC-01), `src/EventOverrides.php` (Einstellungen je Event übersteuern: Ticket-PDF, Erinnerungen; SET-PC-33/-34), `src/SettingsManager.php` (Änderungsprotokoll, Zurücksetzen, Export/Import, SET-PC-10/-11/-13), `src/CountryPresets.php` (Länderprofile DE/AT/CH/EU/OTHER als Vorschlag, SET-PC-02), `src/LegalVersions.php` (archivierte Fassungen der Rechtstexte), `src/Modules.php` (Modulregistrierung), `src/Hooks.php`, `src/Contracts.php` (Vertragsversionen), `src/StateMachine.php`, `src/Clock.php`, `src/Errors.php`, `src/HelpCode.php`, `src/Text.php` (UTF-8-sicheres Kürzen), `src/Terms.php` (konfigurierbare Begriffe, A690), `src/DbReplication.php` (Replikat-Drop-ins, A285), `src/DbLock.php` (benannte Sperren je Installation, A364), `src/Capabilities.php` (alle Plugin-Capabilities, A372), `src/NetworkInstall.php` (Netzwerkaktivierung, A287), `src/Assets.php` (Inline-Skripte über die WordPress-APIs, A302), `src/PublicApi.php` (öffentliche Add-on-Hooks) | Kern, dauerhaft frei | Kern, dauerhaft frei |

## Nicht ausgeliefert

Derzeit nichts: Die Fiskalisierung (`src/Fiscalization/`, Adapter fiskaltrust/efsta) ist seit dem Merge von PR #14 Teil des Kerns und dauerhaft frei (A666).

## Premium-Add-on `pass-captain-pro`

Eigenes Repository, eigener Slug, eigene Textdomain, eigener Namespace; Plugin-Header mit `Requires Plugins`
(Kern) und `Update URI` auf den eigenen Shop. Das Add-on enthält Lizenzaktivierung, Rechteprüfung (Schlüssel
mit Präfix `pass.`), signierten Offline-Cache und den Updater. Fehlt ein Recht, ist nur die jeweilige
Add-on-Funktion aus; der Kern bleibt unberührt. Welche neuen Funktionen dort entstehen, ist eine
Produktentscheidung – keine davon darf eine Funktion aus dem Inventar oben ersetzen oder sperren.

### Connectoren BoatingSchool und LearnBoating (Entscheidung 17, endgültig 16.11.2026)

Die Connectoren zu den Kursplattformen BoatingSchool und LearnBoating sind **eigene Projektprodukte** des
Inhabers: je Connector ein eigenes Plugin mit **eigenem Produktschlüssel** (= Ordner = Textdomain) und eigenem
Präfix, ein nicht verkauftes Produkt auf dem Lizenzserver des Inhabers, Code im jeweiligen Projekt-Repository,
getrennt installiert, nur mit **gültiger Lizenz** (fail-closed: ohne Lizenz keine neue Synchronisierung). Sie sind
**nicht Teil von Pass Captain Pro**; eine Pro-Lizenz ist nicht nötig, eine externe Kundenfreigabe auch nicht
(eigene Projekte). Lizenzclient, Rechteprüfung und Updater liegen im Connector, nie im Kern. Der Kern liefert nur den
freien, versionierten Connector-Vertrag (`PassCaptain\Integrations\ConnectorInterface`,
`wp_pass_captain_connectors_v1`, Opt-in `wpcap_enabled_connectors`, geschaltet über `wpcap_set_connector_enabled()` seit Vertrag 1.4.0, siehe [HOOKS.md](HOOKS.md); seit Vertrag 1.4.0 auch Lesedienst `wpcap_get_ticket_details()` und Protokolleintrag `wpcap_connector_audit()`, siehe [EXTENSIONS.md](EXTENSIONS.md#connectoren-boatingschool-und-learnboating-eigene-projektprodukte)) und prüft keine
Lizenz. Läuft die Lizenz ab, stoppt nur die weitere Synchronisierung; Tickets und Kurszugänge bleiben gültig.
Aufgaben: TODO.md Abschnitt C, ARBEITSLISTE.md Phase 13 (A701–A708).

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
| Ausgabe per Shortcode `[wpcap_events]` und Block; Eventkarte im Theme überschreibbar (`templates/event-card.php`) | `PublicEvents`, `src/blocks/events` | Kern, dauerhaft frei |
| Konfigurierbare Begriffe („Gäste“, „Besucher:innen“ oder eigene Ersetzungen statt „Teilnehmende“) und weitere Theme-Vorlagen (Kalender, Pässe, Servicebuchung; A690) | `Terms`, `PublicEvents::page()` | Kern, dauerhaft frei |
| Kalender: Shortcode `[wp_pass_captain_calendar]`, Block, iCal-Download und -Feed, `.ics` in der Ticket-Mail | Modul `Calendar`, `Calendar/Ics`, `src/blocks/calendar` | Kern, dauerhaft frei |
| Eventseite pro Event mit teilbarer Adresse `/{basis}/{slug}/`, Bild, Kategorie, Open Graph und schema.org/Event (JSON-LD mit Angeboten, Verfügbarkeit, Status) | `Frontend/EventPage`, `templates/event-single.php` | Kern, dauerhaft frei |
| Event und Termin duplizieren (inkl. Ticketarten, Teilnehmerfelder, Verkäufer, Einlassregeln, Preisregeln, Käuferlimits); Serien übernehmen dieselben Angaben | `Admin/Duplicator`, `TicketTypeCopier`, `Series/Generator` | Kern, dauerhaft frei |
| CSV-Import von Events, Terminen und Ticketarten (Prüflauf, Entwürfe) | `Admin/Import` | Kern, dauerhaft frei |
| Startseite mit Kennzahlen und Einrichtungs-Checkliste, gruppiertes Menü, „Funktionen“ zum Ausblenden ungenutzter Menüpunkte | `Admin/Dashboard`, `Admin/Menu` | Kern, dauerhaft frei |

### Checkout, Bestellungen und Zahlungen

| Funktion | Ort im Code | Status |
|---|---|---|
| Eigener Checkout mit mehreren Ticketarten, zeitlich begrenzter Reservierung, Verbraucherinformationen und gespeicherter Bedingungsversion | `Orders`, `Rest/Controller`, `templates/event-card.php` | Kern, dauerhaft frei |
| Kostenlose Bestellungen mit sofortiger Ticketausgabe | `Orders` | Kern, dauerhaft frei |
| Vorverkaufs-/Servicegebühr je Ticket (Einstellung oder je Ticketart) und je Bestellung, standardmäßig aus; im angezeigten Preis enthalten (Gebühr je Bestellung daneben), eigene Position in Mails, Rechnung und Bericht; Steuer wie das Ticket oder eigenes Steuerprofil; Erstattung bei Absage immer, auf Käuferwunsch nach Einstellung; in Partnerabrechnungen Umsatz des Betreibers (Weitergabe einstellbar) | `ServiceFee`, `Orders`, `Refunds`, `Partners\Settlements` | Kern, dauerhaft frei |
| Rechnungsangaben (Firma, Anschrift, UID-Prüfung, Pflicht ab Schwellenwert) | `Billing` | Kern, dauerhaft frei |
| Zahlung manuell / per Überweisung (Bankdaten, Verwendungszweck, Zahlungsfrist) | `Payments/ManualGateway`, `Payments/BankTransfer` | Kern, dauerhaft frei |
| Stripe Checkout, PayPal (Orders v2) und Mollie mit signierten, idempotenten Webhooks und Erstattung über den Anbieter | `Payments/StripeGateway`, `Payments/PayPalGateway`, Modul `Mollie` | Kern, dauerhaft frei |
| Kampagnencodes (Prozent/Festbetrag, Bereich, atomares Nutzungslimit, Mindestmenge/Gruppenrabatt, automatische Rabatte ohne Code, Limit je Käufer, Einzelcodes in Serie mit CSV-Export) | `Campaigns`, `Admin/CampaignsPage` | Kern, dauerhaft frei |
| Versteckte Ticketarten und Freischaltcodes (öffentlich / nur mit Code / versteckt, serverseitig geprüft) | `Sales/Visibility`, `Orders` | Kern, dauerhaft frei |
| Freikarten und Gästeliste ohne Kasse (Kontingent gilt, Audit, Berichtsfilter) | `Sales/Comps`, `Admin/OrganizerTools` | Kern, dauerhaft frei |
| Stornoanfrage im Bestellportal nach Eventregel (Frist, Gebühr) mit Freigabe; Erstattungsvorschau, Abzug und Höchstbetrag | `Sales/SelfService`, `Refunds`, `Admin/OrdersPage` | Kern, dauerhaft frei |
| Bestellsuche nach Nummer, E-Mail, Teilnehmer-/Rechnungsname und Ticket-ID; Bestellungs-CSV | `Admin/Queries`, `Admin/OrdersPage` | Kern, dauerhaft frei |
| Datensparsame JS-Ereignisse für Conversion-Messung (ohne Tracker) und UTM-/Referrer-Herkunft an der Bestellung mit Löschfrist | `assets/tracking.js`, `Orders`, `Privacy/Retention` | Kern, dauerhaft frei |
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
| Fiskalisierung je Rechtsträger: Länder-/Kanalprofile mit Prüfvermerk, Verkäufer je Ticketart und WooCommerce-Position, unveränderliche Belegschnappschüsse je Bestellung und Verkäufer, Ticketausgabe erst nach bestätigtem Beleg (Ausfallregel „Verkauf stoppen“), Wiederholungen mit Statusabfrage, Korrekturaufgaben bei Erstattung/Storno, Kassenregister-Verzeichnis, CSV-Archivexport; Adapter fiskaly (SIGN AT, SIGN DE, Unified API), efsta EFR, fiskaltrust (keine Zertifizierung, Anbieterverträge und Abnahme beim Betreiber) | Modul `Fiscalization`, `Fiscalization/*`, `BoxOffice/TerminalRegistry` | Kern, dauerhaft frei |

### Integrationen

| Funktion | Ort im Code | Status |
|---|---|---|
| WooCommerce-Adapter (HPOS, Classic- und Block-Checkout, Reservierung vor Zahlung, Ticketausgabe, Storno/Erstattung, Teilnehmerfelder, Serien-Preise im Warenkorb) | `Integrations/WooCommerce/*` | Kern, dauerhaft frei |
| WP Voucher Captain: Gutscheine im Checkout, Gutschein → Ticket, gemeinsamer Scanner | `Integrations/Vouchers/*` | Kern, dauerhaft frei |
| WP Voucher Captain: lesender Abgleich der Gutschein-Bestellungen (`Reconciliation::orders()`) | `Integrations/Vouchers/Reconciliation` | Kern, dauerhaft frei |
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

Das Add-on „Pass Captain Pro“ (Ordner, Slug und Textdomain `pass-captain-pro`, Repository
`hastla007/captain-pro`) enthält ausschließlich **neue** Funktionen, die im Kern nie ausgeliefert wurden, dazu
Lizenzseite, Rechteprüfung, Offline-Cache und Updater. Gebaut sind dort fünf Funktionen: E-Rechnung,
Kartenterminal, White Label, virtueller Warteraum und schreibende API. Sie brauchen Pass Captain 1.5.0
([EXTENSIONS.md](EXTENSIONS.md#versionen-der-erweiterungspunkte)). Welche Funktionen dazukommen, wird im
Add-on-Repository festgelegt – nicht in dieser Datei.
Bevor eine Funktion dort geplant wird, ist hier zu prüfen, dass sie nicht schon im Inventar oben steht.

Aus dem Audit Runde 2 bewusst **nicht** im Kern gebaut (Kandidaten für das Add-on, Andockpunkte vorhanden):
Embed-Widget/iFrame (dockt an `wpcap:track` und den Shortcode an), Migration bestehender Tickets mit
Fremdcodes (eigener Importer neben `Admin/Import`), Kombi-/Paket-Tickets, Resale-Börse und Abos mit
Platzbindung. Der Sonderfall „Erstattung bei verschobenem Termin“ nutzt
`Sales\SelfService::request( …, array( 'waive_policy' => true ) )`.
