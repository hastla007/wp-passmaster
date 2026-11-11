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

## P1 – Ticketverwaltung nach dem Kauf

- [ ] Selbstbedienungsfunktionen für berechtigte Käufer ergänzen: Ticket erneut senden, erlaubte Teilnehmerdaten ändern und Ticket übertragen.
- [ ] Übertragung deaktivierbar/begrenzbar machen, vollständig protokollieren, altes QR-Token widerrufen und neues Ticket-Token ausgeben.
- [ ] Statusübergänge für Eventabsage, Terminverschiebung, Widerruf, Teil-/Vollererstattung und Erstattung nach Check-in definieren und implementieren.
- [ ] Ticketstorno, Widerruf und erneute Ausgabe idempotent gestalten; Zahlungs-, Bestell- und Ticketstatus dürfen nicht auseinanderlaufen.
- [ ] Pflicht-/optionale Teilnehmerfelder pro Tickettyp mit Export, Zugriffsschutz, Änderungsfrist und Löschregel verwalten.
- [ ] Käufer und betroffene Teilnehmer bei Terminänderung, Absage, Erstattung und Ticketübertragung über konfigurierbare Vorlagen informieren.

## P1 – Bootsschul- und Servicebuchungen

- [ ] Buchbare Leistungen mit Zeitfenstern, Dauer, Pufferzeit, Zeitzone, verfügbaren Ressourcen/Partnern und Kapazität modellieren.
- [ ] Verfügbarkeitsprüfung und Reservierung für parallele Slot-Buchungen atomar umsetzen.
- [ ] Buchungsschluss, Vorlaufzeiten, maximale Buchungen und Stornierungs-/Umbuchungsfristen konfigurierbar machen.
- [ ] Umbuchung und Stornierung mit Zahlungs-/Gutscheinstatus, Kapazitätsfreigabe, Benachrichtigung und Audit-Log koordinieren.
- [ ] No-show-Regeln und manuelle Partnerbestätigung als optionale Richtlinien abbilden.
- [ ] Warteliste für ausgebuchte Slots mit befristetem Angebot, Annahmefrist und nachvollziehbarer Reihenfolge ergänzen.
- [ ] Service-Pass-Regeln getrennt von Eventticket-Regeln abnehmen: terminfrei/terminpflichtig, einmalige/mehrfache Nutzung und Ablauf.

## P2 – Einlass-, Vor-Ort- und Veranstalter-Erweiterungen

- [ ] Admission-Policy je Ticket unterstützen: einmaliger Eintritt, täglicher Eintritt, mehrtägige Nutzung, Mehrfachzulassung und optionaler Wiedereinlass.
- [ ] Mehrtagestickets mit Gültigkeitsfenstern und eigenständigem Check-in-Status pro Veranstaltungstag ergänzen.
- [ ] Check-in rückgängig machen sowie Check-in und Check-out getrennt protokollieren; nur berechtigte Rollen dürfen Status ändern.
- [ ] Online- und Offline-Scanner Geräten/Einlassbereichen zuweisen; Synchronisationsstatus und Konflikte sichtbar machen.
- [ ] Doppelte Einlösungen aus unabhängig offline arbeitenden Scannern erkennen, protokollieren und einer Admin-Klärung zuführen.
- [ ] Optionales POS/Box Office für Verkäufe vor Ort mit Zahlungsarten, manueller Ticketvergabe, Belegdaten, Druck und gemeinsamem Kontingent implementieren.
- [ ] Ticket-, Badge-, Namensschild- und Armbandvorlagen sowie Teilnehmerlisten-Druck/Export ergänzen.
- [ ] Eventkalender und iCalendar-Download sowie Erinnerungs- und Änderungsnachrichten bereitstellen.
- [ ] SMS-Anbieteradapter mit Einwilligungs-/Zweckdokumentation, Kostenhinweis und Zustellstatus ergänzen.
- [ ] Weitere Zahlungsanbieter nach Zielland, Währung, Gebühren und Händlerverfügbarkeit priorisieren und über die Payment-Adapter anbinden.
- [ ] Öffentliche Webhooks und Entwickler-Hooks für Event-, Ticket-, Bestell-, Refund- und Check-in-Änderungen versioniert dokumentieren.
- [ ] Abnahmetests für Ticketübertragung, Absage, Mehrtages-Einlass, Wiedereinlass, Offline-Konflikte, Slot-Kollisionen, POS-Bestandsabgleich und Erstattungen ergänzen.

## P0 – Rollen, Eventscoping und Zugriffsschutz

- [ ] Eigene Ticket-Captain-Capabilities und Rollenpakete implementieren; WordPress-Administrator bleibt technische Vollzugriffsrolle.
- [ ] Rollen für Ticket-Administration, Veranstaltungsleitung, Partner/Leistungserbringer, Vor-Ort-Kasse, Einlass, Finanzbuchhaltung und Support definieren.
- [ ] Nutzerzuweisungen nach Event, Termin, Einlassbereich oder Partnerleistung speichern; keine implizite globale Berechtigung aus einer operativen Rolle ableiten.
- [ ] Serverseitige Objektberechtigung in Admin, REST, Kundenkonto, PWA, Exporten und Integrationsdiensten durchsetzen.
- [ ] Käuferzugriff auf eigene Bestellungen/Tickets begrenzen; sichere, ablaufende und widerrufbare Gastzugriffe implementieren.
- [ ] Datenfelder je Rolle minimieren: Einlass und Partner erhalten keine Zahlungsdaten; Finanzrollen keine unnötigen Teilnehmerzusatzdaten; Support maskierte/fallbezogene PII.
- [ ] Erstattungs- und Sammelaktionen nach Capability, Eventscope, Betragsschwelle und optionaler Zweitfreigabe absichern.
- [ ] Audit-Log für Rollenänderungen, Exporte, PII-Zugriffe, Erstattungen, Teilnehmeränderungen, Ticketübertragungen und Check-in-Korrekturen ergänzen.
- [ ] Gemeinsame Konten ausschließen; Scannerzugänge kurzlebig, eventbezogen und widerrufbar machen.
- [ ] Mehr-Faktor-Authentifizierung für administrative und finanzielle Rollen unterstützen; zeitlich begrenzten Supportzugriff protokollieren.

## P0 – EU-Zielmarkt und Plattform-Kompatibilität

- [ ] EU als Zielmarkt und Länder-/Währungs-/Sprachkonfiguration in Konzept und Einstellungen festhalten.
- [ ] Release-CI gegen WordPress 7.1.4, optional WooCommerce 11.2.2, PHP 8.4 (8.4.27 am Stichtag), MySQL 8.4 LTS und MariaDB 11.4 LTS einrichten.
- [ ] Yoast SEO 28.8 nur als optionale Kompatibilitätskonfiguration prüfen; keine Laufzeitabhängigkeit einführen.
- [ ] Aktuelle Test-Baseline von den separat festgelegten Mindestversionen trennen und pro Release aktualisieren.
- [ ] Testmatrix für neuere stabile PHP-, WordPress- und WooCommerce-Versionen als Vorwärtskompatibilität pflegen.
- [ ] EU-Steuerfälle, OSS, Leistungsort, gemischte Steuersätze, Währungen und Erstattungsbelege mit fachlicher Steuerberatung für die unterstützten Länder spezifizieren.
- [ ] Zahlungsarten nach Verfügbarkeit pro PSP, Land, Währung und Transaktionstyp konfigurieren.
- [ ] Verbraucherinformationen und Storno-/Erstattungsbedingungen je unterstütztem Land rechtlich prüfen lassen.
- [ ] Übersetzbarkeit, Datenschutzfristen/Auskunft/Löschung und Barrierefreiheit als Release-Gates ergänzen.
