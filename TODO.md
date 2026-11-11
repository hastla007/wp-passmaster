# TODO – Ticket Captain

Dieses Backlog begleitet `CONCEPT.md`. Ticket Captain und Voucher Captain bleiben getrennte WordPress-Plugins mit eigenen Plugin-Einträgen, Updates, Admin-Menüs und Daten. Die Bridge verbindet Funktionen, nicht die Pluginverwaltung.

## P0 – Repository und Plugin-Grundlage

- [ ] Produkt- und Repo-Namen festlegen: Ticket Captain, Slug `wp-passmaster`, Textdomain, Namespace, Datenbankpräfix und Konstanten.
- [ ] Architekturentscheidungen für Mindestversionen, Event-CPT-Redaktionsfelder vs. Custom Tables, Zahlungsstatus und Adapterschnittstellen festhalten.
- [ ] Plugin-Bootstrap, PSR-4-Autoloading, Aktivierung/Deaktivierung, capabilities, REST-Grundlage und Adminnavigation erstellen.
- [ ] Composer-Abhängigkeiten, Lizenzinventar, reproduzierbaren Build und installierbares Release-ZIP einrichten.
- [ ] Schema mit Versionsmigrationen und Upgrade-/Rollback-Verhalten erstellen.
- [ ] Plugin muss ohne WooCommerce und ohne Voucher Captain aktivierbar und funktionsfähig sein.

## P0 – Event- und Verkaufsgrundlage

- [ ] Events, einzelne Termine/Occurrences, Zeitzonen, Veranstaltungsorte, Status und Wiederholungstermine modellieren.
- [ ] Ticketkategorien mit Preis, Währung, Steuerklasse, Verkaufsfenster, Kontingent und Mengenlimit implementieren.
- [ ] Öffentliche Eventseite mit Gutenberg-Block/Shortcode und barrierearmer Ticket-Auswahl bereitstellen.
- [ ] Native Bestellung, Käuferdaten, Positionen und nachvollziehbare Statusmaschine erstellen.
- [ ] Checkout-Felder pro Event/Ticketkategorie mit Datenminimierung und klarer Löschregel unterstützen.
- [ ] Promo-Codes für Events/Tickettypen mit Limit, Ablauf und Stapelregeln spezifizieren und implementieren.

## P0 – Reservierung, Zahlung und Ausgabe

- [ ] Atomare zeitlich begrenzte Reservierungen für Mengen implementieren; Standard 10 Minuten konfigurierbar.
- [ ] Bestand aus verkauften Einheiten und aktiven Holds berechnen; parallele Überverkäufe verhindern.
- [ ] Timeout-, Checkout-Abbruch- und verspätete Zahlungsbestätigung behandeln, ohne stilles Überbuchen.
- [ ] Payment-Gateway-Vertrag und zuerst ausgewählte PSP-Adapter (Stripe/PayPal nach priorisiertem Umfang) erstellen.
- [ ] Signaturen und Idempotenz für Payment-Webhooks implementieren; Kartendaten nicht selbst speichern.
- [ ] Bestellpositionen, Steuer-/Preis-Snapshots und Teil-/Vollrefund-Regeln speichern.
- [ ] Pro gekaufter Einheit idempotent ein eigenes Ticket erzeugen; Bestellung, Position und Quelle verknüpfen.

## P0 – QR-Ticket und Online-Check-in

- [ ] Zufällige Ticketkennung und signierte Version des QR-Payloads nach Konzept umsetzen; keine PII oder fortlaufenden internen IDs in den QR schreiben.
- [ ] Ticketvorlage, QR-PDF, E-Mail-Zustellung und Teilnehmeransicht in „Mein Konto“ bereitstellen.
- [ ] Partnerrollen, Event-/Standortzuweisung und minimale Berechtigungen implementieren.
- [ ] Mobilfreundliche Online-Scanner-PWA mit Kamerascan und manueller Codeeingabe bauen.
- [ ] Ticketprüfung und Check-in atomar ausführen; Doppel-Scan mit Status und Zeitstempel ablehnen.
- [ ] Check-in-, Refund-, Widerrufs- und Admin-Auditprotokoll umsetzen.
- [ ] Admin-Suche, Nachsendung, Widerruf und Fehler-/Retry-Ansicht bereitstellen.

## P0 – Datenschutz und Releasefähigkeit

- [ ] WordPress-Datenschutzexport und Löschschnittstellen einbinden.
- [ ] Aufbewahrungsfristen je Datenart konfigurieren; Teilnehmerdaten getrennt von Finanzbelegen behandeln.
- [ ] Nonces, capabilities, Rate-Limits, REST-Validierung, Escaping und Logbereinigung umsetzen.
- [ ] Unit-, Datenbank-, REST-, Checkout-, Zahlungs-, Refund- und PWA-Tests gemäß Konzept einrichten.
- [ ] Sauberen Build, Aktivierung, Upgrade-Migration, Deaktivierung und Deinstallation prüfen.
- [ ] Release-Gates aus `CONCEPT.md` automatisieren und manuelle Mobile-/Desktop-Abnahme dokumentieren.

## P1 – Voucher-Captain-Integration

- [ ] Gemeinsame versionierte Bridge-Schnittstelle, Capability-Erkennung und Compatibility-Matrix implementieren.
- [ ] Voucher Captain als Checkout-Zahlungs-/Rabattquelle nur nach Quote und Regeln integrieren.
- [ ] VCAP-Guthaben mit Quote/Reserve/Commit/Release/Refund nutzen; keine direkten Tabellenzugriffe.
- [ ] VCAP-Gutschein nach erfolgreicher Einlösung mit definierter Ticket-/Eventzielzuordnung genau einmal in ein Ticket umwandeln.
- [ ] Timeout, Retry, doppelte und parallele Requests sowie Teilrefunds zwischen beiden Plugins nachvollziehbar abbilden.
- [ ] Optionalen Scanner-Handler für `TCAP:` registrieren und gemeinsamen Scanner aus beiden Admin-Menüs verlinken.
- [ ] Beide Plugins behalten unabhängige Menüs, Plugin-Einträge und Solo-Betrieb.

## P1 – Optionaler WooCommerce-Adapter

- [ ] WooCommerce als optionale Abhängigkeit über Adapter statt Core-Abhängigkeit anschließen.
- [ ] HPOS und Order-CRUD verwenden; direkte Bestelltabellenzugriffe ausschließen.
- [ ] Classic Checkout und Checkout Blocks unterstützen, sofern für die erste WooCommerce-Adapterversion zugesagt.
- [ ] Bestellstatus, 0-Euro-Bestellung, Wiederholungsereignisse, Erstattung und Kurs-/Ticketprodukte integrieren.
- [ ] Adaptertests mit WooCommerce-Versionen und HPOS-Modi in die CI aufnehmen.

## P2 – Erweiterte Eventfunktionen

- [ ] Sitzpläne und grafischen Seat-Map-Editor mit versionierten Layouts und atomaren Platzreservierungen ergänzen.
- [ ] Serientermine mit eigener Kapazität, Preisfenstern und Sitzplanverknüpfung ausbauen.
- [ ] Apple Wallet und Google Wallet über Betreiberkonten und signierte Passes ergänzen.
- [ ] Steuer-/Rechnungsmodul anhand juristischer und steuerlicher Spezifikation umsetzen.
- [ ] Buchhaltungsexport, Korrekturbelege und Umsatz-/Check-in-Berichte ergänzen.
- [ ] Offline-Check-in nur mit expliziter Konfliktlogik und sichtbarem Doppel-Scan-Risiko freigeben.

## P2 – Entscheidungen vor Produktivstart

- [ ] Startländer festlegen (AT/DE/CH) und Rechts-/Steuerprüfungen je Land beauftragen.
- [ ] Erste PSPs und Zahlungsmethoden verbindlich priorisieren.
- [ ] MVP-Entscheidung treffen: native Rechnungs-PDFs oder zunächst Buchhaltungsexport.
- [ ] Festlegen, ob Seat Map im ersten marktfähigen Release enthalten ist.
- [ ] Regeln für Übertragung, Rückerstattung nach Check-in und abgesagte Events festlegen.

## Abnahmekriterien

- [ ] Eigenständiger Ticketkauf funktioniert ohne WooCommerce und erzeugt genau ein Ticket je Einheit.
- [ ] Reservierung verhindert Überverkauf auch bei parallelen Checkouts.
- [ ] Online-Check-in lehnt einen bereits verwendeten Einmalpass atomar ab.
- [ ] Ticket Captain funktioniert ohne Voucher Captain; Voucher Captain funktioniert ohne Ticket Captain.
- [ ] Gemeinsame Installation aktiviert die definierte Bridge, ohne separate Plugin-Einträge oder Admin-Menüs zusammenzulegen.
- [ ] Gutschein-zu-Ticket-Einlösung und Ticket-Rückerstattung sind idempotent und revisionsfähig.
- [ ] Release-Gates aus Konzept sind bestanden und dokumentiert.
