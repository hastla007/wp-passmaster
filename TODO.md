# TODO – Ticket Captain

Dieses Backlog begleitet `CONCEPT.md`. Ticket Captain und Voucher Captain bleiben getrennte WordPress-Plugins mit eigenen Plugin-Einträgen, Updates, Admin-Menüs und Daten. Die Bridge verbindet Funktionen, nicht die Pluginverwaltung.

## P0 – Repository und Plugin-Grundlage

- [x] Produkt- und Repo-Namen festlegen: Ticket Captain, Slug `wp-passmaster`, Textdomain, Namespace, Datenbankpräfix und Konstanten. — erledigt: Name „WP Pass Captain“ (statt „Ticket Captain“), Repo `wp-passmaster`, Plugin-Slug und Textdomain `wp-pass-captain`, Namespace `PassCaptain`, Tabellenpräfix `wpcap_`, Konstanten `WPCAP_*` (wp-pass-captain.php, composer.json)
- [ ] Architekturentscheidungen für Mindestversionen, Event-CPT-Redaktionsfelder vs. Custom Tables, Zahlungsstatus und Adapterschnittstellen festhalten. — teilweise: Mindestversionen im Plugin-Header (WP 6.4, PHP 8.2, WC 8.2), Custom Tables (src/Installer.php), Bestell-/Zahlungsstatus in `wpcap_orders`, Adapter-Interfaces (src/Payments/GatewayInterface.php, src/Integrations/Vouchers/ProviderInterface.php, src/Integrations/ConnectorInterface.php) / offen: schriftlich festgehaltene Architekturentscheidungen (CONCEPT.md nennt noch `wp_etp_*`-Tabellen und optionalen CPT)
- [x] Plugin-Bootstrap, PSR-4-Autoloading, Aktivierung/Deaktivierung, capabilities, REST-Grundlage und Adminnavigation erstellen. — erledigt: wp-pass-captain.php (PSR-4 per Composer, Fallback-Autoloader), src/Plugin.php (Aktivierung/Deaktivierung, Multisite), src/Installer.php (Rollen/Capabilities), src/Rest/Controller.php, src/Admin/Menu.php
- [ ] Composer-Abhängigkeiten, Lizenzinventar, reproduzierbaren Build und installierbares Release-ZIP einrichten. — teilweise: composer.json/composer.lock, build/release.sh (git archive + .distignore), CI-Job „package“ mit ZIP-Artefakt / offen: Lizenzinventar der Abhängigkeiten (endroid/qr-code, bacon, dasprid, jsQR, tweetnacl)
- [x] Schema mit Versionsmigrationen und Upgrade-/Rollback-Verhalten erstellen. — erledigt: src/Installer.php (DB_VERSION 3.5.0, dbDelta plus Modul-Schemata, Upgrade hinter GET_LOCK), Rollback per Backup/Restore in docs/OPERATIONS.md; Tests zz_phase1_arch.php, boxoffice_migration.php
- [x] Plugin muss ohne WooCommerce und ohne Voucher Captain aktivierbar und funktionsfähig sein. — erledigt: WooCommerce- und Voucher-Adapter greifen nur bei aktivem Plugin (src/Integrations/*); Kern-Tests in tests/integration/run.php laufen ohne beide

## P0 – Event- und Verkaufsgrundlage

- [x] Events, einzelne Termine/Occurrences, Zeitzonen, Veranstaltungsorte, Status und Wiederholungstermine modellieren. — erledigt: Tabellen `wpcap_events`/`wpcap_occurrences` (Zeitzone, Ort als Textfeld, Status), Serientermine (src/Series/Generator.php); Tests admin.php, series.php
- [x] Ticketkategorien mit Preis, Währung, Steuerklasse, Verkaufsfenster, Kontingent und Mengenlimit implementieren. — erledigt: `wpcap_ticket_types` (Preis, Währung, tax_class/tax_rate_bp, sales_start/sales_end, capacity, max_per_order), src/Admin/EventsPage.php; Test admin.php
- [x] Öffentliche Eventseite mit Gutenberg-Block/Shortcode und barrierearmer Ticket-Auswahl bereitstellen. — erledigt: Shortcode `[wp_pass_captain_events]` und Block src/blocks/events (src/PublicEvents.php, templates/event-card.php); Tests zz_phase1_ux.php, tests/a11y/run.js und keyboard.js
- [x] Native Bestellung, Käuferdaten, Positionen und nachvollziehbare Statusmaschine erstellen. — erledigt: src/Orders.php (`wpcap_orders`/`wpcap_order_items`, Status pending_payment/complete/cancelled/refunded/expired/needs_review, Audit order_created); Test run.php
- [x] Checkout-Felder pro Event/Ticketkategorie mit Datenminimierung und klarer Löschregel unterstützen. — erledigt: Teilnehmerfelder je Ticketart mit Zweck und Aufbewahrungsfrist (`wpcap_participant_fields`, src/Admin/EventsPage.php), Löschlauf src/Privacy/Retention.php; Tests run.php, admin.php, zz_phase1_privacy.php
- [x] Promo-Codes für Events/Tickettypen mit Limit, Ablauf und Stapelregeln spezifizieren und implementieren. — erledigt: src/Campaigns.php (atomares Nutzungslimit, starts_at/ends_at, Event-/Tickettyp-Bereich, bis zu 3 Codes nach Priorität); Tests zz_voucher_purpose.php, zz_phase1_ux.php

## P0 – Reservierung, Zahlung und Ausgabe

- [x] Atomare zeitlich begrenzte Reservierungen für Mengen implementieren; Standard 10 Minuten konfigurierbar. — erledigt: src/Orders.php (Holds in Transaktion mit Zeilensperren), Einstellung `hold_minutes` (Standard 10); Test run.php
- [x] Bestand aus verkauften Einheiten und aktiven Holds berechnen; parallele Überverkäufe verhindern. — erledigt: Orders::availability() und Kapazitätsprüfung aus bezahlten Positionen plus aktiven Holds; Tests run.php (20 parallele Käufer auf 5 Plätze), tests/load/run.php
- [x] Timeout-, Checkout-Abbruch- und verspätete Zahlungsbestätigung behandeln, ohne stilles Überbuchen. — erledigt: Orders::expire_holds(), Orders::confirm_external_payment() mit Neuprüfung → needs_review statt Überbuchung; Tests run.php, zz_phase1_flows.php
- [x] Payment-Gateway-Vertrag und zuerst ausgewählte PSP-Adapter (Stripe/PayPal nach priorisiertem Umfang) erstellen. — erledigt: src/Payments/GatewayInterface.php, StripeGateway.php, PayPalGateway.php (dazu Mollie und Überweisung); Test payments.php
- [x] Signaturen und Idempotenz für Payment-Webhooks implementieren; Kartendaten nicht selbst speichern. — erledigt: src/Payments/Webhooks.php, verify_webhook() je Anbieter, Duplikaterkennung über `wpcap_payment_events`, keine Kartendaten (gehostete Zahlungsseiten); Tests payments.php, tests/load/run.php
- [x] Bestellpositionen, Steuer-/Preis-Snapshots und Teil-/Vollrefund-Regeln speichern. — erledigt: `wpcap_order_items` (Stückpreis, tax_rate_bp, tax_minor, refunded_quantity), price_snapshot, src/Refunds.php (Teil-/Vollerstattung); Tests run.php, finance.php, admin.php
- [x] Pro gekaufter Einheit idempotent ein eigenes Ticket erzeugen; Bestellung, Position und Quelle verknüpfen. — erledigt: Orders::issue_and_email() unter Zeilensperre, Ticket mit order_id/ticket_type_id, Quelle über payment_provider bzw. Woo-Meta; Test run.php („exactly one ticket per unit after repeated paid hooks“)

## P0 – QR-Ticket und Online-Check-in

- [ ] Zufällige Ticketkennung und signierte Version des QR-Payloads nach Konzept umsetzen; keine PII oder fortlaufenden internen IDs in den QR schreiben. — teilweise: zufälliger 256-Bit-Code ohne PII, nur SHA-256-Hash gespeichert (src/Orders.php, src/TicketPage.php) / offen: signiertes, versioniertes QR-Format mit Schlüsselrotation (CONCEPT.md 15.5, `TCAP:v1:…:<signature>`)
- [ ] Ticketvorlage, QR-PDF, E-Mail-Zustellung und Teilnehmeransicht in „Mein Konto“ bereitstellen. — teilweise: Vorlagen (src/Printing/Templates.php), QR-PDF (src/TicketPdf.php), Ticket-Mail mit PDF-Anhang, Ticketseite und Bestellportal (src/OrderPortal.php), WooCommerce „Mein Konto“; Tests print.php, mail_templates.php / offen: native Teilnehmeransicht für eingeloggte Nutzer ohne WooCommerce
- [x] Partnerrollen, Event-/Standortzuweisung und minimale Berechtigungen implementieren. — erledigt: Rollen in src/Installer.php (u. a. wpcap_partner, wpcap_checkin_staff), Eventzuweisung `wpcap_event_staff` (Admin/EventsPage.php::assign_staff), Einlassbereiche (src/Admission/Areas.php); Tests admin.php, admission.php, zz_phase1_security.php
- [x] Mobilfreundliche Online-Scanner-PWA mit Kamerascan und manueller Codeeingabe bauen. — erledigt: Web-Scanner `[wp_pass_captain_scanner]` (assets/scanner.js: BarcodeDetector/jsQR, manuelle Eingabe, Ergebnisbanner, Kamera stoppen, Licht, Modusleiste) und installierbare Offline-PWA (assets/offline-scanner/); Tests offline_scanner.php, tests/a11y
- [x] Ticketprüfung und Check-in atomar ausführen; Doppel-Scan mit Status und Zeitstempel ablehnen. — erledigt: src/Checkin.php (Ticketzeile FOR UPDATE, Ergebnis already_checked_in mit Zeitstempel im Protokoll); Tests admission.php („exactly one of 6 concurrent scans admits“), tests/load/run.php
- [x] Check-in-, Refund-, Widerrufs- und Admin-Auditprotokoll umsetzen. — erledigt: src/Audit.php (ticket_checked_in, checkin_undone, refund_*, ticket_revoked, settings_updated u. a.), Einlassprotokoll `wpcap_checkins`
- [ ] Admin-Suche, Nachsendung, Widerruf und Fehler-/Retry-Ansicht bereitstellen. — teilweise: Ticket-/Bestellsuche, „Tickets erneut senden“, Widerruf (src/Admin/TicketsPage.php, OrdersPage.php), Filter needs_review, Erstattungsfehler, Webhook-„Erneut senden“ / offen: Fehler-/Retry-Ansicht für Mail-Warteschlange und Jobs

## P0 – Datenschutz und Releasefähigkeit

- [x] WordPress-Datenschutzexport und Löschschnittstellen einbinden. — erledigt: src/Privacy.php (Exporter/Eraser, zurückgestellte Löschung bei kommenden Terminen); Test zz_phase1_privacy.php
- [x] Aufbewahrungsfristen je Datenart konfigurieren; Teilnehmerdaten getrennt von Finanzbelegen behandeln. — erledigt: src/Privacy/Retention.php (Fristen je Datenart, Einstellungen und Filter `wpcap_privacy_retention_days`, Finanzbelege ausgenommen), docs/OPERATIONS.md; Test zz_phase1_privacy.php
- [x] Nonces, capabilities, Rate-Limits, REST-Validierung, Escaping und Logbereinigung umsetzen. — erledigt: Nonce-/Capability-Prüfungen in Admin und REST, src/RateLimit.php, REST-Validierung (src/Rest/Controller.php), Escaping, IP-Hashes, Audit-Löschfrist; Tests admin.php, zz_phase1_security.php
- [ ] Unit-, Datenbank-, REST-, Checkout-, Zahlungs-, Refund- und PWA-Tests gemäß Konzept einrichten. — teilweise: Integrationstests gegen MariaDB für DB, REST, Checkout, Zahlung, Erstattung und Scanner-Server (tests/integration, 1452 Checks), Last- und a11y-Tests / offen: eigenständige Unit-Tests und Funktionstests der Scanner-PWA im Browser
- [ ] Sauberen Build, Aktivierung, Upgrade-Migration, Deaktivierung und Deinstallation prüfen. — teilweise: ZIP-Build per build/release.sh in CI, Upgrade-Sperre getestet (zz_phase1_arch.php), Deaktivierung und uninstall.php mit Opt-in-Datenlöschung / offen: dokumentierte Prüfung von Aktivierung, Upgrade, Deaktivierung und Deinstallation in sauberer WordPress-Installation
- [ ] Release-Gates aus `CONCEPT.md` automatisieren und manuelle Mobile-/Desktop-Abnahme dokumentieren. — teilweise: CI (Syntax, Integrations-, Lasttest, ZIP) und Release nur nach grüner CI (.github/workflows/ci.yml, release.yml) / offen: übrige Gates aus CONCEPT.md automatisieren und manuelle Mobile-/Desktop-Abnahme dokumentieren

## P1 – Voucher-Captain-Integration

- [x] Gemeinsame versionierte Bridge-Schnittstelle, Capability-Erkennung und Compatibility-Matrix implementieren. *(`wp_pass_captain_voucher_provider_v1`, `wp_pass_captain_ticket_service_v1`; Voucher Captains CI prüft gegen `main` und den Release-Branch)*
- [x] Voucher Captain als Checkout-Zahlungs-/Rabattquelle nur nach Quote und Regeln integrieren.
- [x] VCAP-Guthaben mit Quote/Reserve/Commit/Release/Refund nutzen; keine direkten Tabellenzugriffe.
- [x] VCAP-Gutschein nach erfolgreicher Einlösung mit definierter Ticket-/Eventzielzuordnung genau einmal in ein Ticket umwandeln (`wp_pass_captain_ticket_service_v1`).
- [x] Timeout, Retry, doppelte und parallele Requests sowie Teilrefunds zwischen beiden Plugins nachvollziehbar abbilden. *(Idempotenz-Schlüssel, Job `voucher_commit`, anteilige Rückgabe bei Ticket-Erstattung, Audit-Log)*
- [x] Optionalen Scanner-Handler registrieren und gemeinsamen Scanner aus beiden Admin-Menüs verlinken. *(`wp_pass_captain_checkin_service_v1`; Pass-Captain-Tickets am 64-stelligen Code erkannt)*
- [x] Beide Plugins behalten unabhängige Menüs, Plugin-Einträge und Solo-Betrieb.

## P1 – Optionaler WooCommerce-Adapter

- [x] WooCommerce als optionale Abhängigkeit über Adapter statt Core-Abhängigkeit anschließen. — erledigt: src/Integrations/WooCommerce/OrderAdapter.php (nur bei aktivem WooCommerce), docs/WOOCOMMERCE.md; Tests run.php, woocommerce.php
- [x] HPOS und Order-CRUD verwenden; direkte Bestelltabellenzugriffe ausschließen. — erledigt: HPOS-Kompatibilität deklariert (OrderAdapter::declare_compatibility), nur wc_get_order()/wc_get_orders() und Meta-CRUD
- [x] Classic Checkout und Checkout Blocks unterstützen, sofern für die erste WooCommerce-Adapterversion zugesagt. — erledigt: Classic Checkout und Blocks/Store API (OrderAdapter.php, BlocksIntegration.php, assets/woocommerce/); Tests woocommerce.php, woocommerce_pricing.php
- [ ] Bestellstatus, 0-Euro-Bestellung, Wiederholungsereignisse, Erstattung und Kurs-/Ticketprodukte integrieren. — teilweise: Statushooks idempotent, on-hold/failed/cancelled, Nachnahme, Teil-/Vollerstattung, Ticketprodukte (OrderAdapter.php; Tests run.php, woocommerce.php) / offen: ausdrücklich getestete 0-Euro-Bestellung, Kurs-/Leistungsprodukte über WooCommerce
- [ ] Adaptertests mit WooCommerce-Versionen und HPOS-Modi in die CI aufnehmen. — teilweise: Adaptertests mit WooCommerce-Attrappen laufen in CI / offen: Tests gegen echte WooCommerce-Versionen und beide Speichermodi (HPOS/klassisch)

## P2 – Erweiterte Eventfunktionen

- [x] Sitzpläne und grafischen Seat-Map-Editor mit versionierten Layouts und atomaren Platzreservierungen ergänzen. — erledigt: Modul Saalpläne (src/Seating/*, SVG-Editor assets/seating, versionierte Layouts, src/Seating/Reservations.php); Test seating.php
- [x] Serientermine mit eigener Kapazität, Preisfenstern und Sitzplanverknüpfung ausbauen. — erledigt: Modul Serientermine (src/Series/Generator.php, Pools.php, Pricing.php, Saalplan-Version je Serie); Tests series.php, series_pricing.php
- [x] Apple Wallet und Google Wallet über Betreiberkonten und signierte Passes ergänzen. — erledigt: src/WalletPasses/* (Apple signierte Pässe mit Webservice/APNs, Google Wallet), docs/WALLET.md; Test wallet.php
- [ ] Steuer-/Rechnungsmodul anhand juristischer und steuerlicher Spezifikation umsetzen. — teilweise: Rechnungen/Rechnungskorrekturen mit USt je Satz, Verkaufsmodelle Eigenhändler/Vermittler, Kleinunternehmer-Einstellung (src/Invoices.php, src/Partners/SalesModel.php; Tests zz_billing.php, zz_sales_models.php) / offen: juristische und steuerliche Spezifikation bzw. Freigabe
- [x] Buchhaltungsexport, Korrekturbelege und Umsatz-/Check-in-Berichte ergänzen. — erledigt: CSV-Buchhaltungsexport, Gutschriften/Rechnungskorrekturen (src/Invoices.php), Modul Berichte (src/Reports/*); Tests finance.php, reports.php
- [x] Offline-Check-in nur mit expliziter Konfliktlogik und sichtbarem Doppel-Scan-Risiko freigeben. — erledigt: Offline-Freigabe je Event mit Doppelscan-Regel und max. Offline-Dauer (src/OfflineScanner/Rules.php), Konfliktliste (src/Admission/Conflicts.php), docs/OFFLINE-SCANNER.md; Test offline_scanner.php

## P2 – Entscheidungen vor Produktivstart

- [ ] Startländer festlegen (AT/DE/CH) und Rechts-/Steuerprüfungen je Land beauftragen.
- [ ] Erste PSPs und Zahlungsmethoden verbindlich priorisieren. — teilweise: umgesetzt sind Stripe, PayPal, Mollie und Überweisung / offen: verbindliche, dokumentierte Priorisierung
- [x] MVP-Entscheidung treffen: native Rechnungs-PDFs oder zunächst Buchhaltungsexport. — erledigt: beides umgesetzt – Rechnungs-PDFs (src/Printing/InvoicePdf.php) und CSV-Buchhaltungsexport (src/Invoices.php)
- [x] Festlegen, ob Seat Map im ersten marktfähigen Release enthalten ist. — erledigt: Saalpläne sind seit 0.9.0 als Modul enthalten (CHANGELOG.md)
- [x] Regeln für Übertragung, Rückerstattung nach Check-in und abgesagte Events festlegen. — erledigt: Übertragung einmalig vor Beginn (Orders::transfer_ticket, Filter `wpcap_max_holder_changes`), eingecheckte Tickets bleiben bei Erstattung eingecheckt, Absage mit optionaler Auto-Erstattung (src/OccurrenceCancellation.php, `auto_refund_cancelled`)

## Abnahmekriterien

- [x] Eigenständiger Ticketkauf funktioniert ohne WooCommerce und erzeugt genau ein Ticket je Einheit. — erledigt: Test run.php („free native order issues tickets immediately“, „exactly one ticket per unit after repeated paid hooks“)
- [x] Reservierung verhindert Überverkauf auch bei parallelen Checkouts. — erledigt: Test run.php (20 parallele Käufer auf 5 Plätze), tests/load/run.php
- [x] Online-Check-in lehnt einen bereits verwendeten Einmalpass atomar ab. — erledigt: Test admission.php („single entry: exactly one of 6 concurrent scans admits“)
- [x] Ticket Captain funktioniert ohne Voucher Captain; Voucher Captain funktioniert ohne Ticket Captain. *(CI beider Plugins läuft ohne das andere)*
- [x] Gemeinsame Installation aktiviert die definierte Bridge, ohne separate Plugin-Einträge oder Admin-Menüs zusammenzulegen.
- [x] Gutschein-zu-Ticket-Einlösung und Ticket-Rückerstattung sind idempotent und revisionsfähig.
- [ ] Release-Gates aus Konzept sind bestanden und dokumentiert. — teilweise: CI-Gates (Tests, Lasttest, ZIP-Build) automatisiert / offen: übrige Gates aus CONCEPT.md bestanden und dokumentiert

## P1 – Ticketverwaltung nach dem Kauf

- [x] Selbstbedienungsfunktionen für berechtigte Käufer ergänzen: Ticket erneut senden, erlaubte Teilnehmerdaten ändern und Ticket übertragen. — erledigt: Bestellportal src/OrderPortal.php (erneut senden, Teilnehmerdaten bis `attendee_edit_hours`, Übertragung), src/TicketAssignments.php; Tests zz_phase1_flows.php, zz_phase1_security.php, zz_integration.php
- [ ] Übertragung deaktivierbar/begrenzbar machen, vollständig protokollieren, altes QR-Token widerrufen und neues Ticket-Token ausgeben. — teilweise: Übertragung protokolliert (Audit ticket_transferred), alter Code ungültig, neuer Code per Mail, Limit 1 bzw. Filter `wpcap_max_holder_changes` / offen: Übertragung je Event/Veranstalter abschaltbar und Limit per Einstellung
- [x] Statusübergänge für Eventabsage, Terminverschiebung, Widerruf, Teil-/Vollererstattung und Erstattung nach Check-in definieren und implementieren. — erledigt: Terminabsage/-verschiebung (src/OccurrenceCancellation.php, `wpcap_occurrence_rescheduled`), Widerruf, Teil-/Vollerstattung inkl. eingecheckter Tickets (src/Refunds.php); Tests admin.php, run.php
- [x] Ticketstorno, Widerruf und erneute Ausgabe idempotent gestalten; Zahlungs-, Bestell- und Ticketstatus dürfen nicht auseinanderlaufen. — erledigt: idempotente Erstattung, Storno und Neuausstellung mit Sperren (src/Refunds.php, Orders::cancel_order, Orders::reissue_ticket); Tests admin.php, zz_phase1_security.php, zz_phase1_flows.php
- [x] Pflicht-/optionale Teilnehmerfelder pro Tickettyp mit Export, Zugriffsschutz, Änderungsfrist und Löschregel verwalten. — erledigt: Teilnehmerfelder je Tickettyp (Pflicht/optional, Zweck, Aufbewahrung), rollenbasierter Export src/Printing/AttendeeList.php, Änderungsfrist `attendee_edit_hours`, Löschlauf src/Privacy/Retention.php; Tests admin.php, print.php, zz_phase1_privacy.php
- [x] Käufer und betroffene Teilnehmer bei Terminänderung, Absage, Erstattung und Ticketübertragung über konfigurierbare Vorlagen informieren. — erledigt: E-Mail-Vorlagen (src/Mail/*: occurrence_rescheduled, occurrence_cancelled, event_cancelled, refund_confirmation, ticket_transfer_*), src/Notifications.php; Test mail_templates.php

## P1 – Bootsschul- und Servicebuchungen

- [ ] Buchbare Leistungen mit Zeitfenstern, Dauer, Pufferzeit, Zeitzone, verfügbaren Ressourcen/Partnern und Kapazität modellieren. — teilweise: Ressourcen mit Kapazität und Puffer, Slots mit Start/Ende (`wpcap_resources`, `wpcap_service_slots`, REST in src/Rest/Controller.php) / offen: Leistungen mit Dauer, Zeitzone, Partnerzuordnung und Admin-Oberfläche
- [x] Verfügbarkeitsprüfung und Reservierung für parallele Slot-Buchungen atomar umsetzen. — erledigt: src/ServiceBookings.php (Slot FOR UPDATE, Kapazitätsprüfung, Idempotenz-Schlüssel); ohne eigenen Nebenläufigkeitstest
- [ ] Buchungsschluss, Vorlaufzeiten, maximale Buchungen und Stornierungs-/Umbuchungsfristen konfigurierbar machen. — teilweise: nur Buchung bis Slotbeginn und max. 20 Plätze je Buchung (src/ServiceBookings.php) / offen: konfigurierbarer Buchungsschluss, Vorlaufzeit, Höchstbuchungen, Storno-/Umbuchungsfristen
- [ ] Umbuchung und Stornierung mit Zahlungs-/Gutscheinstatus, Kapazitätsfreigabe, Benachrichtigung und Audit-Log koordinieren.
- [ ] No-show-Regeln und manuelle Partnerbestätigung als optionale Richtlinien abbilden.
- [ ] Warteliste für ausgebuchte Slots mit befristetem Angebot, Annahmefrist und nachvollziehbarer Reihenfolge ergänzen. — teilweise: Warteliste mit befristetem Angebot und Reihenfolge für Ticketarten (src/Waitlists.php; Test run.php) / offen: Warteliste für Service-Slots
- [ ] Service-Pass-Regeln getrennt von Eventticket-Regeln abnehmen: terminfrei/terminpflichtig, einmalige/mehrfache Nutzung und Ablauf. — teilweise: Mitgliedspässe mit Gültigkeit und Nutzungslimits gesamt/pro Tag (src/Memberships.php, `wpcap_pass_rules`) / offen: terminpflichtige Pässe und Abnahmetests

## P2 – Einlass-, Vor-Ort- und Veranstalter-Erweiterungen

- [x] Admission-Policy je Ticket unterstützen: einmaliger Eintritt, täglicher Eintritt, mehrtägige Nutzung, Mehrfachzulassung und optionaler Wiedereinlass. — erledigt: src/Admission/Policies.php und Engine.php (einmalig, täglich, mehrtägig, mehrfach, Wiedereinlass); Test admission.php
- [x] Mehrtagestickets mit Gültigkeitsfenstern und eigenständigem Check-in-Status pro Veranstaltungstag ergänzen. — erledigt: Policies mit Tagen/Zeitfenstern, Einträge je lokalem Veranstaltungstag (`wpcap_admission_entries`); Test admission.php
- [x] Check-in rückgängig machen sowie Check-in und Check-out getrennt protokollieren; nur berechtigte Rollen dürfen Status ändern. — erledigt: Check-in rückgängig (src/Admin/TicketsPage.php, Audit checkin_undone), Check-out getrennt protokolliert (checked_out), Recht `wpcap_checkin` mit Eventscope; Tests admin.php, admission.php
- [x] Online- und Offline-Scanner Geräten/Einlassbereichen zuweisen; Synchronisationsstatus und Konflikte sichtbar machen. — erledigt: Geräte-/Bereichszuweisung (src/Mobile/Devices.php, src/Admission/Areas.php, src/OfflineScanner/Assignments.php), Gerätestatus mit letzter Synchronisierung und offenen Konflikten; Tests admission.php, offline_scanner.php
- [x] Doppelte Einlösungen aus unabhängig offline arbeitenden Scannern erkennen, protokollieren und einer Admin-Klärung zuführen. — erledigt: src/Admission/Conflicts.php und ConflictsPage.php (Klärung mit Notiz und Audit); Tests admission.php, offline_scanner.php
- [x] Optionales POS/Box Office für Verkäufe vor Ort mit Zahlungsarten, manueller Ticketvergabe, Belegdaten, Druck und gemeinsamem Kontingent implementieren. — erledigt: Modul Kasse (src/BoxOffice/*: Zahlarten, Bons, 80-mm-Druck, Kassensitzungen, gemeinsames Kontingent); Test boxoffice.php
- [x] Ticket-, Badge-, Namensschild- und Armbandvorlagen sowie Teilnehmerlisten-Druck/Export ergänzen. — erledigt: src/Printing/Templates.php (Ticket, Badge, Namensschild, Armband), src/Printing/AttendeeList.php (Druck/CSV); Test print.php
- [x] Eventkalender und iCalendar-Download sowie Erinnerungs- und Änderungsnachrichten bereitstellen. — erledigt: Modul Kalender (src/Calendar/Ics.php, Shortcode/Block, iCal-Feed), Erinnerungen (src/Modules/Reminders.php), Änderungsmails; Tests calendar.php, reminders.php
- [x] SMS-Anbieteradapter mit Einwilligungs-/Zweckdokumentation, Kostenhinweis und Zustellstatus ergänzen. — erledigt: src/Sms/* (Twilio, HTTP), Einwilligungen mit Zweck (`wpcap_sms_consents`), Kostenhinweis, Zustellstatus (src/Modules/Sms.php); Test sms.php
- [x] Weitere Zahlungsanbieter nach Zielland, Währung, Gebühren und Händlerverfügbarkeit priorisieren und über die Payment-Adapter anbinden. — erledigt: Mollie als weiterer Adapter mit Zahlarten je Betrag/Währung/Land (src/Mollie/MollieGateway.php, src/Modules/Mollie.php); Test mollie.php
- [x] Öffentliche Webhooks und Entwickler-Hooks für Event-, Ticket-, Bestell-, Refund- und Check-in-Änderungen versioniert dokumentieren. — erledigt: docs/WEBHOOKS.md (signierte, versionierte Webhooks, src/Webhooks/Dispatcher.php), docs/HOOKS.md; Test webhooks.php
- [ ] Abnahmetests für Ticketübertragung, Absage, Mehrtages-Einlass, Wiedereinlass, Offline-Konflikte, Slot-Kollisionen, POS-Bestandsabgleich und Erstattungen ergänzen. — teilweise: Tests für Übertragung, Absage, Mehrtages-Einlass, Wiedereinlass, Offline-Konflikte, Kasse und Erstattungen (zz_phase1_flows.php, admin.php, admission.php, offline_scanner.php, boxoffice.php) / offen: Slot-Kollisionen bei Servicebuchungen

## P0 – Rollen, Eventscoping und Zugriffsschutz

- [x] Eigene Ticket-Captain-Capabilities und Rollenpakete implementieren; WordPress-Administrator bleibt technische Vollzugriffsrolle. — erledigt: src/Installer.php::register_roles() (wpcap_*-Capabilities und Rollenpakete; Administrator erhält alle Rechte)
- [x] Rollen für Ticket-Administration, Veranstaltungsleitung, Partner/Leistungserbringer, Vor-Ort-Kasse, Einlass, Finanzbuchhaltung und Support definieren. — erledigt: Rollen wpcap_ticket_admin, wpcap_event_manager, wpcap_partner (Veranstalter), wpcap_box_office, wpcap_checkin_staff, wpcap_finance, wpcap_support (src/Installer.php)
- [ ] Nutzerzuweisungen nach Event, Termin, Einlassbereich oder Partnerleistung speichern; keine implizite globale Berechtigung aus einer operativen Rolle ableiten. — teilweise: Zuweisung je Event (`wpcap_event_staff`, ohne implizite Rechtevergabe), Einlassbereich (`wpcap_admission_area_staff`) und Veranstalter (`wpcap_partner_users`); Tests admin.php, admission.php / offen: Zuweisung je Termin und je Partnerleistung
- [x] Serverseitige Objektberechtigung in Admin, REST, Kundenkonto, PWA, Exporten und Integrationsdiensten durchsetzen. — erledigt: Access::can_access_event() und Reports\Scope in Admin/REST, Bestellportal per Token, Gerätetokens für PWA/App, rollenbasierter Teilnehmerexport, Prüfung in src/Integrations/Vouchers/CheckinService.php; Tests admin.php, admission.php, partners.php
- [x] Käuferzugriff auf eigene Bestellungen/Tickets begrenzen; sichere, ablaufende und widerrufbare Gastzugriffe implementieren. — erledigt: Bestellzugang per Zufallstoken (nur Hash gespeichert, 30 Tage gültig, Orders::rotate_access_link); Test zz_phase1_security.php
- [ ] Datenfelder je Rolle minimieren: Einlass und Partner erhalten keine Zahlungsdaten; Finanzrollen keine unnötigen Teilnehmerzusatzdaten; Support maskierte/fallbezogene PII. — teilweise: Einlass/Kasse sehen in der Teilnehmerliste nur Name/Ticketart/Status (src/Printing/AttendeeList.php), Partner-API liefert E-Mails nur mit attendees:read, Finanzdaten nur mit `wpcap_view_finance` / offen: maskierte, fallbezogene PII für Support und Feldminimierung für Finanzrollen
- [ ] Erstattungs- und Sammelaktionen nach Capability, Eventscope, Betragsschwelle und optionaler Zweitfreigabe absichern. — teilweise: Erstattungen mit Capability, kumulierter Betragsschwelle und Zweitfreigabe (src/Refunds.php; Tests admin.php, zz_phase1_security.php) / offen: Schwelle/Zweitfreigabe für Sammelaktionen (z. B. Terminabsage mit Auto-Erstattung)
- [ ] Audit-Log für Rollenänderungen, Exporte, PII-Zugriffe, Erstattungen, Teilnehmeränderungen, Ticketübertragungen und Check-in-Korrekturen ergänzen. — teilweise: Audit für Personalzuweisungen, Exporte, Erstattungen, Teilnehmeränderungen, Übertragungen und Check-in-Korrekturen (src/Audit.php) / offen: WordPress-Rollenänderungen und PII-Ansichten im Admin
- [x] Gemeinsame Konten ausschließen; Scannerzugänge kurzlebig, eventbezogen und widerrufbar machen. — erledigt: personengebundene Zuweisungen; Gerätetokens ablaufend, eventgebunden, widerrufbar, nur als Hash gespeichert (src/Mobile/Devices.php, src/Admin/DevicesPage.php); Test mobile.php
- [ ] Mehr-Faktor-Authentifizierung für administrative und finanzielle Rollen unterstützen; zeitlich begrenzten Supportzugriff protokollieren. — teilweise: optionale 2FA-Pflicht für Erstattungen (`require_2fa_for_refunds`, Refunds::user_has_2fa) / offen: 2FA für Admin-/Finanzrollen allgemein und zeitlich begrenzter, protokollierter Supportzugriff

## P0 – EU-Zielmarkt und Plattform-Kompatibilität

- [x] EU als Zielmarkt und Länder-/Währungs-/Sprachkonfiguration in Konzept und Einstellungen festhalten. — erledigt: CONCEPT.md „EU-Zielmarkt“, Einstellung seller_country, Währung je Ticketart, Übersetzungen (de, en_US, fr_FR, it_IT, es_ES, nl_NL)
- [ ] Release-CI gegen WordPress 7.1.4, optional WooCommerce 11.2.2, PHP 8.4 (8.4.27 am Stichtag), MySQL 8.4 LTS und MariaDB 11.4 LTS einrichten. — teilweise: CI mit PHP 8.2/8.4 und MariaDB 11.4 (.github/workflows/ci.yml) / offen: echtes WordPress 7.1.4, WooCommerce 11.2.2 und MySQL 8.4 LTS in der CI
- [ ] Yoast SEO 28.8 nur als optionale Kompatibilitätskonfiguration prüfen; keine Laufzeitabhängigkeit einführen.
- [ ] Aktuelle Test-Baseline von den separat festgelegten Mindestversionen trennen und pro Release aktualisieren. — teilweise: Mindestversionen (Plugin-Header/readme.txt: WP 6.4, PHP 8.2, WC 8.2) getrennt von „Tested up to“ und Test-Baseline in CONCEPT.md / offen: Aktualisierung je Release als festen Schritt verankern
- [ ] Testmatrix für neuere stabile PHP-, WordPress- und WooCommerce-Versionen als Vorwärtskompatibilität pflegen.
- [ ] EU-Steuerfälle, OSS, Leistungsort, gemischte Steuersätze, Währungen und Erstattungsbelege mit fachlicher Steuerberatung für die unterstützten Länder spezifizieren. — teilweise: technisch gemischte Steuersätze je Position, Währung je Ticketart, Rechnungskorrekturen, Reverse Charge bei Veranstalter-Provisionen (docs/SALES-MODELS.md) / offen: fachliche Spezifikation mit Steuerberatung, OSS und Leistungsort beim Ticketverkauf
- [ ] Zahlungsarten nach Verfügbarkeit pro PSP, Land, Währung und Transaktionstyp konfigurieren. — teilweise: Mollie-Zahlarten nach Betrag/Währung und Rechnungsland, Anbieter je Währung (src/Modules/Mollie.php, is_available) / offen: Konfiguration je Transaktionstyp und für Stripe-/PayPal-Zahlarten
- [ ] Verbraucherinformationen und Storno-/Erstattungsbedingungen je unterstütztem Land rechtlich prüfen lassen.
- [ ] Übersetzbarkeit, Datenschutzfristen/Auskunft/Löschung und Barrierefreiheit als Release-Gates ergänzen. — teilweise: Übersetzungen (6 Sprachen, Platzhalterprüfung), Datenschutztests in CI (zz_phase1_privacy.php), a11y-Tests (tests/a11y) / offen: a11y- und Übersetzungsprüfung als automatisches Release-Gate
