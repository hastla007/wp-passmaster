# TODO – WP Pass Captain

Dieses Backlog beschreibt den Abgleich mit dem aktuellen Stand von `main` (Plugin 1.4.0). Häkchen bedeuten: Der beschriebene Umfang ist im Code und/oder in den automatisierten Prüfungen vorhanden. Teilumsetzungen bleiben offen und nennen den Restumfang. Voucher Captain bleibt eine optionale, separat installierbare Integration.

## P0 – Repository und Plugin-Grundlage

- [x] Produkt- und Repo-Namen festlegen: **WP Pass Captain**, Slug `wp-passmaster`, Textdomain `wp-pass-captain`, Namespace und Plugin-Konstanten sind festgelegt.
- [x] Architekturentscheidungen für Mindestversionen, Custom Tables, Zahlungsstatus und Adapter festhalten (PHP 8.2+, WordPress 6.4+, WooCommerce optional; Details in README, `docs/` und Schema).
- [x] Plugin-Bootstrap, Composer-Autoloading, Aktivierungs-/Deaktivierungshooks, Capabilities, REST-Grundlage und Adminnavigation erstellen.
- [x] Composer-Abhängigkeiten, Lizenzdokumentation und installierbares Release-ZIP einrichten (CI-Build vorhanden).
- [ ] Reproduzierbaren Release-Build mit dokumentiertem Abgleich der Artefakte absichern.
- [x] Versioniertes Schema und Upgrade-Migrationen erstellen; Migration läuft kontrolliert im Admin, per Cron oder WP-CLI.
- [ ] Rollback-/Restore-Verhalten mit echten Datenbankständen erproben und dokumentieren.
- [x] Plugin muss ohne WooCommerce und ohne Voucher Captain aktivierbar und funktionsfähig sein; beide Integrationen bleiben optional.

## P0 – Event- und Verkaufsgrundlage

- [x] Events und Occurrences mit Zeitzonen, Veranstaltungsorten und Status modellieren.
- [ ] Terminserien vollständig mit individueller Kapazität, Preisfenstern und getrennten Änderungsverläufen ausbauen.
- [x] Tickettypen mit Preis, Währung, Kontingent und Mengenlimits implementieren.
- [ ] Steuerklassen und geprüfte länderspezifische Verkaufs-/Steuerregeln an Bestellungen anbinden.
- [x] Öffentliche Eventseite als Gutenberg-Block und Shortcode mit Ticket-Auswahl bereitstellen; öffentliche Tastatur-/A11y-Prüfungen sind vorhanden.
- [x] Native Bestellungen mit Käuferdaten, Positionen, Statusübergängen und Idempotenz erstellen.
- [ ] Teilnehmerfelder sind als Datenmodell angelegt; Formular-Editor, Checkout-Erfassung, Feldvalidierung, Export/Löschung und Einwilligungssteuerung fehlen noch.
- [x] Kampagnencodes mit Prozent-/Festbetragsrabatt, Event-/Ticket-Scope, Priorität, globalem Nutzungslimit und Preis-Snapshot implementieren.
- [ ] Checkout-Oberfläche, Ablaufregeln, Käuferlimits und konfigurierbare Stapelregeln vervollständigen.

## P0 – Reservierung, Zahlung und Ausgabe

- [x] Atomare, zeitlich begrenzte Reservierungen für Mengen implementieren; die Standard-Haltezeit beträgt zehn Minuten.
- [x] Bestand aus Verkäufen und aktiven Holds berechnen; Nebenläufigkeits-/Lasttests belegen, dass Checkout-Käufe das Kontingent nicht überverkaufen.
- [x] Abgelaufene Holds und Checkout-Abbrüche geben Bestand frei; Zahlungsereignisse werden idempotent verarbeitet.
- [ ] Anbieter-Sandbox- und Wiederherstellungstests für verspätete/mehrdeutige Live-Zahlungsereignisse abschließen.
- [x] Payment-Gateway-Vertrag sowie Adapter für manuelle Bestätigung, Stripe und PayPal implementieren.
- [x] Signaturprüfung und Idempotenz für Zahlungs-Webhooks implementieren; Kartendaten werden nicht selbst gespeichert.
- [x] Preis-/Positions-Snapshots und Refund-Status im Bestellablauf speichern.
- [ ] Steuerberechnung, rechtlich geprüfte Gutschriften und alle Teil-/Vollrefund-Sonderfälle je Land vervollständigen.
- [x] Pro gekaufter Einheit idempotent ein Ticket erzeugen und mit Bestellung/Position verknüpfen.

## P0 – QR-Ticket und Online-Check-in

- [ ] Tickets verwenden zufällige 256-Bit-Codes; gespeichert wird nur deren Hash, und der QR enthält keine PII oder fortlaufende Datenbank-ID. Ein separates signiertes QR-Payload ist nicht umgesetzt; Sicherheits- und Interoperabilitätsentscheidung dafür dokumentieren.
- [ ] Ticketlink/QR, E-Mail-Zustellung, private Druckansicht und Bestell-/Ticketportal sind vorhanden; native PDF-Erzeugung und vollständige Teilnehmer-Selbstbedienung fehlen.
- [ ] Rollen und eventbezogene Zuweisungen sind vorhanden; granulare Scopes für Standorte/Partner, Finanzrollen, PII-Minimierung und vollständige Admin-/REST-Abdeckung ergänzen.
- [x] Mobilfreundlichen Online-Scanner mit Kamera-QR-Scan und manueller Codeeingabe bereitstellen.
- [ ] Vollständige installierbare PWA-Abnahme für Browser/Endgeräte dokumentieren.
- [x] Check-in-Nebenläufigkeitsfehler behoben: Ticketzeile wird gesperrt, sämtliche Schreibvorgänge/Commit werden geprüft und der Check-in-Lasttest ist ein verpflichtender CI-Schritt (zuletzt erfolgreicher main-CI-Lauf #492).
- [ ] Zusätzlich Default-/Heavy-Profile und den REST-Lasttest regelmäßig auf MySQL/MariaDB ausführen; Regressionen und Durchsatzänderungen dokumentieren.
- [x] Auditprotokolle für Check-ins, Bestellungen, Zahlungs-/Refund- und Adminaktionen sind implementiert.
- [ ] Audit-Abdeckung für alle PII-Zugriffe, Exporte, Rollenänderungen und Korrekturen vervollständigen.
- [x] Admin-Bestell-/Ticketansichten, Ticketnachsendung, Widerruf und Fehler-/Retry-Abläufe bereitstellen; Restfälle im echten WordPress-Betrieb abnehmen.

## P0 – Datenschutz und Releasefähigkeit

- [x] WordPress-Datenschutzexport und Löschschnittstellen einbinden.
- [ ] Datenschutzexport/-löschung ist vorhanden; konfigurierbare Fristen je Datenart und getrennte Behandlung von Teilnehmerdaten/Finanzbelegen fehlen.
- [x] Nonces, Capabilities, REST-Validierung, Escaping, Rate-Limits und Bereinigung sensibler Logdaten sind im Code abgedeckt.
- [ ] Unabhängigen Sicherheitsreview/Penetrationstest durchführen.
- [x] Datenbank-/Integrationstests für Checkout, Zahlungs-Webhooks, Refunds, WooCommerce, REST und Scanner-Grundabläufe sowie Last- und A11y-Testwerkzeuge einrichten.
- [ ] PWA-/Endgeräte-, reale PSP-Sandbox- und vollständige Refund-End-to-End-Abnahmen in CI/Release-Gates ergänzen.
- [x] CI baut und prüft ein installierbares ZIP; Aktivierungs-/Deaktivierungshooks und Migrationen sind vorhanden.
- [ ] Aktivierung, Update, Restore und Deinstallation in realen WordPress-Versionen durchtesten.
- [ ] Automatisierte Integration-, Syntax-, Last- und A11y-Prüfungen existieren; verbindliche Release-Gates sowie manuelle Mobile-/Desktop-Abnahme sind noch nicht vollständig dokumentiert.

## P1 – Voucher-Captain-Integration

- [x] Gemeinsame versionierte Bridge-Schnittstelle, Capability-Erkennung und Compatibility-Matrix implementieren. *(`wp_pass_captain_voucher_provider_v1`, `wp_pass_captain_ticket_service_v1`; Voucher Captains CI prüft gegen `main` und den Release-Branch)*
- [x] Voucher Captain als Checkout-Zahlungs-/Rabattquelle nur nach Quote und Regeln integrieren.
- [x] VCAP-Guthaben mit Quote/Reserve/Commit/Release/Refund nutzen; keine direkten Tabellenzugriffe.
- [x] VCAP-Gutschein nach erfolgreicher Einlösung mit definierter Ticket-/Eventzielzuordnung genau einmal in ein Ticket umwandeln (`wp_pass_captain_ticket_service_v1`).
- [x] Timeout, Retry, doppelte und parallele Requests sowie Teilrefunds zwischen beiden Plugins nachvollziehbar abbilden. *(Idempotenz-Schlüssel, Job `voucher_commit`, anteilige Rückgabe bei Ticket-Erstattung, Audit-Log)*
- [x] Optionalen Scanner-Handler registrieren und gemeinsamen Scanner aus beiden Admin-Menüs verlinken. *(`wp_pass_captain_checkin_service_v1`; Pass-Captain-Tickets am 64-stelligen Code erkannt)*
- [x] Beide Plugins behalten unabhängige Menüs, Plugin-Einträge und Solo-Betrieb.

## P1 – Optionaler WooCommerce-Adapter

- [x] WooCommerce als optionale Integration über einen Adapter anschließen; der Plugin-Kern bleibt ohne WooCommerce aktivierbar.
- [x] WooCommerce CRUD-APIs und HPOS-kompatible Bestellzugriffe verwenden.
- [x] Classic Checkout und WooCommerce Checkout Blocks unterstützen.
- [x] Ticketprodukte, Bestellstatus, kostenlose Bestellungen, wiederholte Hooks und Refund-/Stornoereignisse integrieren; Mehrfachverkäufer-Aufteilung für Warenkorbpositionen ist in Arbeit/PR #14 und noch nicht in `main`.
- [ ] WooCommerce-Integrations- und HPOS-Verhalten wird in der Test-Suite geprüft; echte Version-/HPOS-Matrix gegen eine installierte WooCommerce-Umgebung fehlt.

## P2 – Erweiterte Eventfunktionen

- [x] Sitzplan-REST-Grundlage, versionierte Layouts und temporäre atomare Platz-Holds sind vorhanden; Admin-Editor, Checkout-Verknüpfung und Verkaufs-Commit fehlen.
- [ ] Wöchentliche Terminserien werden erstellt; individuelle Kapazitäten/Preisfenster, Editierbarkeit und Sitzplanverknüpfung fehlen.
- [ ] Wallet-Providervertrag/Pass-Metadaten sind vorhanden; Apple-/Google-Pass-Erzeugung, Betreiberkonten, Zertifikate und Live-Abnahme fehlen.
- [ ] Rechnungsnummern und Draft-Rechnungen mit Käufer-/Positionssnapshot sind vorhanden; Steuerberechnung steht noch nicht bereit und rechtliche/steuerliche Freigabe je Land fehlt.
- [ ] Basisberichte für Verkäufe und Check-ins sind vorhanden; Buchhaltungsexporte und Korrekturbelege fehlen, Berichte brauchen Erweiterung/Abnahme.
- [ ] Offline-Queue und Batch-Synchronisierung mit Konfliktantworten sind vorhanden; Gerätezuweisung, lokale Berechtigungsprüfung und garantierte globale Einmaligkeit fehlen. Nicht als konfliktfreien Offlinebetrieb freigeben.

## P2 – Entscheidungen vor Produktivstart

- [ ] Startländer festlegen (AT/DE/CH) und Rechts-/Steuerprüfungen je Land beauftragen.
- [x] Erste Online-PSPs sind Stripe und PayPal; weitere Anbieter/Länder-Zahlungsarten bleiben separat zu priorisieren.
- [ ] MVP-Entscheidung treffen: native Rechnungs-PDFs oder zunächst Buchhaltungsexport.
- [ ] Festlegen, ob Seat Map im ersten marktfähigen Release enthalten ist.
- [ ] Einmalige Ticketübertragung und Eventabsage sind teilweise umgesetzt; vollständige Regeln für Refund nach Check-in, Terminverschiebung, Wiederzulassung und alle Statusfolgen festlegen.

## Abnahmekriterien

- [x] Native Bestellungen funktionieren ohne WooCommerce; je gekaufter Einheit wird idempotent ein Ticket erzeugt.
- [x] Nebenläufigkeitstests belegen, dass parallele Checkouts das Kontingent nicht überverkaufen.
- [x] Einmal-Check-in lehnt doppelte Scans atomar ab; der verpflichtende CI-Nebenläufigkeitstest besteht. Zusätzliche Heavy-/REST-Profile bleiben als Härtung offen.
- [x] Ticket Captain funktioniert ohne Voucher Captain; Voucher Captain funktioniert ohne Ticket Captain. *(CI beider Plugins läuft ohne das andere)*
- [x] Gemeinsame Installation aktiviert die definierte Bridge, ohne separate Plugin-Einträge oder Admin-Menüs zusammenzulegen.
- [x] Gutschein-zu-Ticket-Einlösung und Ticket-Rückerstattung sind idempotent und revisionsfähig.
- [ ] Release-Gates aus Konzept sind bestanden und dokumentiert.

## P1 – Ticketverwaltung nach dem Kauf

- [x] Käuferportal/Gastlinks und einmalige Ticketübertragung sind vorhanden; vollständige Selbstbedienung für Nachsendung und Teilnehmerdaten fehlt.
- [x] Einmalige Ticketübertragung vor Veranstaltungsbeginn mit Tokenrotation und Widerruf des alten Codes ist umgesetzt; ausbauen für konfigurierbare Fristen/Regeln.
- [ ] Eventabsage, Refunds und Widerruf haben Grundabläufe; Terminverschiebung und vollständige Status-/Sonderfallregeln, insbesondere Refund nach Check-in, sind offen.
- [ ] Idempotenz ist in vielen Bestell-/Zahlungspfaden vorhanden; vollständige End-to-End-Abnahme für Storno, Widerruf, Neuausgabe und alle Refundkombinationen fehlt.
- [x] Teilnehmerfeld-Datenschema ist angelegt; Formular-Editor, Checkout-Erfassung, Export, Zugriffsschutz, Änderungsfristen und Löschregeln fehlen.
- [x] E-Mail-Vorlagen und Eventabsage-Benachrichtigung sind vorhanden; Vorlagen/Benachrichtigungen für Verschiebung, Refund und Übertragung vervollständigen.

## P1 – Bootsschul- und Servicebuchungen

- [ ] Ressourcen-, Zeitfenster- und Buchungshold-Grundlagen mit Zeitzone/Kapazität sind vorhanden; Dauer/Puffer und Partnerverwaltung fehlen.
- [x] Kapazitätsgesperrte, idempotente Buchungsholds für Service-Zeitfenster sind als REST-Grundlage vorhanden.
- [ ] Buchungsschluss, Vorlauf, Maximalbuchungen und Umbuchungs-/Stornierungsfristen fehlen.
- [ ] Umbuchungs-/Stornierungs-End-to-End-Abläufe mit Zahlung, Gutschein, Bestand, Benachrichtigung und Audit-Log fehlen.
- [ ] No-show-Regeln und manuelle Partnerbestätigung als optionale Richtlinien abbilden.
- [ ] Warteliste für ausgebuchte Slots mit befristetem Angebot, Annahmefrist und nachvollziehbarer Reihenfolge ergänzen.
- [ ] Service-Pass-Regeln getrennt von Eventticket-Regeln abnehmen: terminfrei/terminpflichtig, einmalige/mehrfache Nutzung und Ablauf.

## P2 – Einlass-, Vor-Ort- und Veranstalter-Erweiterungen

- [x] Admission-Policies für einmaligen, täglichen und mehrtägigen Eintritt sowie begrenzte Mehrfachzulassung/Wiedereinlass im Backend umgesetzt.
- [x] Einlassregeln und Status pro Veranstaltungstag sind im Backend vorhanden; Admin-Oberfläche und echte Mehrtagesticket-Abnahme fehlen.
- [x] Check-in-Korrekturen sind grundlegend protokollierbar; getrennte Check-out-Bedienung und vollständige Rollenprüfung/Abnahme ergänzen.
- [x] Geräte-Token und Offline-Synchronisierung sind vorhanden; Zuweisung nach Einlassbereich und vollständige Admin-Konfliktansicht fehlen.
- [x] Doppelte Einlösungen aus unabhängig offline arbeitenden Scannern erkennen, protokollieren und einer Admin-Klärung zuführen.
- [ ] POS-REST-API kann Verkäufe über gemeinsames Kontingent anlegen; Bedienoberfläche, Zahlungsarten, fiskalische Belege und Druckworkflow fehlen.
- [x] Ticket-, Badge-, Namensschild- und Armbandvorlagen sowie Teilnehmerlisten-Druck/Export ergänzen.
- [ ] Kalender, E-Mail-Erinnerungen und Nachrichtengrundlagen sind vorhanden; vollständiger iCalendar-Download und Änderungsbenachrichtigungen pro Ereignis abnehmen.
- [ ] SMS-Einstellungen/Queue-Grundlage sind vorhanden; Anbieteradapter und vollständige Einwilligungs-, Kosten- und Zustellstatusabläufe fehlen.
- [ ] Weitere Zahlungsanbieter nach Zielland, Währung, Gebühren und Händlerverfügbarkeit priorisieren und über die Payment-Adapter anbinden.
- [x] Webhook- und Entwickler-Hook-Verträge sind versioniert dokumentiert; aktuelle Abdeckung ist in `docs/WEBHOOKS.md` und `docs/HOOKS.md` aufgeführt.
- [ ] Einzelne Integrations-/Lasttests für diese Flows existieren; vollständige End-to-End-Abnahmesuite einschließlich Offlinekonflikten, Slot-Kollisionen und realem POS fehlt.

## P0 – Rollen, Eventscoping und Zugriffsschutz

- [x] Eigene Ticket-Captain-Capabilities und Rollenpakete implementieren; WordPress-Administrator bleibt technische Vollzugriffsrolle.
- [ ] Rollen für Ticket-Administration, Veranstaltungsleitung, Partner/Leistungserbringer, Vor-Ort-Kasse, Einlass, Finanzbuchhaltung und Support definieren.
- [ ] Nutzerzuweisungen nach Event, Termin, Einlassbereich oder Partnerleistung speichern; keine implizite globale Berechtigung aus einer operativen Rolle ableiten.
- [ ] Serverseitige Objektberechtigung in Admin, REST, Kundenkonto, PWA, Exporten und Integrationsdiensten durchsetzen.
- [x] Käuferzugriff auf eigene Bestellungen/Tickets begrenzen; sichere, ablaufende und widerrufbare Gastzugriffe implementieren.
- [ ] Datenfelder je Rolle minimieren: Einlass und Partner erhalten keine Zahlungsdaten; Finanzrollen keine unnötigen Teilnehmerzusatzdaten; Support maskierte/fallbezogene PII.
- [ ] Erstattungs- und Sammelaktionen nach Capability, Eventscope, Betragsschwelle und optionaler Zweitfreigabe absichern.
- [ ] Audit-Log für Rollenänderungen, Exporte, PII-Zugriffe, Erstattungen, Teilnehmeränderungen, Ticketübertragungen und Check-in-Korrekturen ergänzen.
- [ ] Gemeinsame Konten ausschließen; Scannerzugänge kurzlebig, eventbezogen und widerrufbar machen.
- [ ] Mehr-Faktor-Authentifizierung für administrative und finanzielle Rollen unterstützen; zeitlich begrenzten Supportzugriff protokollieren.

## P0 – EU-Zielmarkt und Plattform-Kompatibilität

- [x] EU ist als Zielmarkt festgehalten; Währungs-/Locale-Grundlagen und Übersetzungen sind vorhanden.
- [ ] Länderbezogene Steuer-/Rechtsprofile fachlich prüfen und aktivieren.
- [x] CI prüft PHP 8.2/8.4, Syntax, Composer, Integrationstests und ZIP-Build; Integration läuft mit MariaDB.
- [ ] Reale CI-Matrix für WordPress/WooCommerce/MySQL-Versionen ergänzen; aktuelle Mock-/MariaDB-Tests sind keine echte Plattformmatrix.
- [x] Keine Yoast-Laufzeitabhängigkeit; optionale Kompatibilität kann separat geprüft werden.
- [ ] Kompatibilitäts- und Test-Baseline pro Release dokumentieren und aktuell halten.
- [ ] PHP-Matrix in CI ist vorhanden; echte WordPress-/WooCommerce-Versionsmatrix fehlt.
- [ ] EU-Steuerfälle, OSS, Leistungsort, gemischte Steuersätze, Währungen und Erstattungsbelege mit fachlicher Steuerberatung für die unterstützten Länder spezifizieren.
- [ ] Zahlungsanbieter und Währungen sind konfigurierbar; verfügbare Zahlungsmethoden pro Land/PSP/Transaktionstyp sowie Live-Akzeptanz fehlen.
- [ ] Verbraucherinformationen und Storno-/Erstattungsbedingungen je unterstütztem Land rechtlich prüfen lassen.
- [ ] Übersetzungen, Datenschutzexport/-löschung und A11y-Prüfwerkzeuge sind vorhanden; Datenschutzfristen, vollständige Admin-A11y und manuelle Screenreader-Abnahme als Release-Gates ergänzen.


## Fiskalisierung und Multi-Seller — nicht in `main`

Der seller-aware Fiskalisierungskern liegt derzeit in [Draft-PR #14](https://github.com/hastla007/wp-passmaster/pull/14); der Branch ist nicht in `main` integriert. Die dortigen Häkchen gelten deshalb nicht als erledigte Funktion der aktuellen Hauptversion.

- [ ] PR #14 mit dem aktuellen `main` abgleichen, Konflikte lösen und nach bestandener CI integrieren.
- [ ] Im Draft vorhandene Multi-Seller-Profile, WooCommerce-Korbaufteilung sowie Anbieterwahl fiskaly/efsta/fiskaltrust in `main` übernehmen und auf realen POS-/WooCommerce-Installationen abnehmen.
- [ ] Länder-/Anbieter-/Kanal-Konfiguration für AT, DE und HU mit echten Anbieter-Sandboxen testen und rechtlich/fachlich freigeben.
- [ ] Refunds, Teilrefunds, Eventabsagen und gebrauchte Tickets providerseitig ausführen; im Draft werden die meisten Fälle noch manuell abgeglichen. Der efsta-AT/DE-Einmal-Vollstorno-Pfad ist eng begrenzt und braucht Sandbox-Abnahme.
- [ ] Gesetzliche Länderanforderungen wie Signatur/TSE, Belegausgabe, vorgeschriebene Exporte und Hardwaretopologie fachlich prüfen. Adapter-Routing ist keine Zertifizierung oder Rechtsfreigabe.

## Kommerzielle Lizenzierung und Updates — offene PRs

Diese Änderungen liegen ebenfalls in offenen PRs und sind noch nicht Teil von `main`.

- [ ] WP License Captain Aktivierung für WP Pass Captain integrieren (PR #20).
- [ ] Premium-Updates mit Prüfsummenprüfung integrieren (PR #21).
- [ ] Fail-closed Entitlement-Prüfung integrieren (PR #22).
- [ ] Signierten Offline-Entitlement-Cache integrieren (PR #23).
- [ ] PRs gegen den aktuellen `main`-Stand zusammenführen, End-to-End testen und erst danach als Lizenz-/Updatefunktion ausweisen.

## Zusätzlich entdeckte offene Aufgaben aus Code, Roadmaps und Betriebsdokumentation

Diese Punkte waren im vorherigen Abgleich noch nicht als eigene Aufgaben erfasst. Sie ergänzen die bestehenden Backlog-Punkte, statt deren erledigte Grundlagen zurückzunehmen.

### Kernprodukt und Admin-Bedienung

- [ ] Bestehende Events und Termine vollständig bearbeiten, verschieben und versioniert ändern; derzeit ist die Eventverwaltung laut Roadmap beim Bearbeiten noch eingeschränkt.
- [ ] Für derzeit überwiegend per REST nutzbare Module passende Admin-Bedienoberflächen ergänzen: Serientermine, Einlassregeln/Areas, Service-Zeitfenster und Entwickler-API-Schlüssel.
- [ ] Event-Warteliste vervollständigen: frei werdende Kapazität automatisch und atomar reservieren, Angebote auslösen, Annahme/Ablehnung/Abmeldung unterstützen und parallele Annahmen testen.
- [ ] Automatische Preisregeln für Frühbucher, Last-Minute und Nachfrage mit Regeln zu Käuferlimits, Stapelbarkeit, Verkaufsfenstern und nachvollziehbaren Preis-Snapshots fertigstellen.
- [ ] Veranstalterberichte um Zeitraum-/Vergleichsfilter, Bestand, Erstattungen, Kampagnen und Teilnehmerdaten erweitern; rollenbeschränkte CSV-Exporte ergänzen.
- [ ] Entwickler-API-Schlüssel administrierbar machen: erstellen, einmalig anzeigen, widerrufen, Ablauf/Ratenlimits verwalten und die vorgesehenen Read-only-Endpunkte erweitern.
- [ ] Multisite-Netzwerkverwaltung und netzwerkweite Berichte ergänzen; Site-Isolation, Aktivierung, Löschung und Migration durch Cross-Site-Tests absichern.

### Hintergrunddienste, Übersetzungen und Integrationen

- [ ] Job-Queue-Handler für geplante Nachrichten, Erinnerungen und sonstige Jobs vervollständigen; Admin-Ansicht für Rückstände, Fehlversuche und Dead-Letter-Jobs mit kontrolliertem Wiederholen ergänzen.
- [ ] Betriebsüberwachung für WP-Cron/Action Scheduler, Mail-, SMS-, Webhook- und Job-Rückstände einrichten; ausbleibende Ausführung oder dauerhaft fehlerhafte Jobs müssen erkennbar sein.
- [ ] Käufer-Sprachauswahl sowie Übersetzungen für E-Mails, Tickets und Käuferansichten ergänzen; Übersetzungsverwaltung im Admin und vollständige String-Prüfung für alle unterstützten Sprachen hinzufügen.
- [ ] Versionierte Connector-Verträge durch konkret unterstützte CRM-, Kalender- oder Buchhaltungsadapter, Mapping-Oberflächen und getestete Live-Synchronisierung ergänzen.
- [ ] Native Scanner-Apps selbst entwickeln oder explizit als separates Produkt/Projekt ausweisen; die Mobile-REST-API allein ist keine Scanner-App.

### Abnahme, Barrierefreiheit und Releasebetrieb

- [ ] Bekannte Admin-Barrierefreiheitsbefunde beheben: Labels an Filter-Selects, Kontrast von Code-/Linktexten und ausreichend große Checkbox-Ziele; anschließend Tastatur-, Zoom- und Screenreader-Prüfung wiederholen.
- [ ] Veralteten Fehlerbericht in docs/TESTING.md aktualisieren: Der Bericht nennt noch den behobenen Check-in-Deadlock und continue-on-error, obwohl der aktuelle CI-Workflow den Lasttest verpflichtend ausführt.
- [ ] Upgrade-, Wiederherstellungs- und Multisite-Notfallübungen mit realistischen Testdaten durchführen; Zahlungsanbieter und externe Integrationen danach auf konsistente Transaktionsstände abgleichen.
- [ ] Prozess für Sicherheitsmeldungen, Incident-Behandlung, Abhängigkeitsupdates und regelmäßige WordPress-/WooCommerce-Kompatibilitätsprüfungen festlegen.
- [ ] Release- und Supportmodell für stabile Versionen festlegen: unterstützte PHP/WP/WooCommerce-Matrix, Rückwärtskompatibilität, Supportkanal, Patchfristen und Ende des Supports.
- [ ] Plugin-Metadaten und Funktionsdokumentation vor jedem Release mit dem Code abgleichen. Aktuell widersprechen sich README und Backlog z. B. beim Stand der Voucher-Checkout-Integration und der Zahlungsanbieter.

### Multi-Seller-Geschäftsabläufe

- [ ] Für gemischte WooCommerce-Warenkörbe verbindliche Regeln für Rabatte, Gebühren, Versand und Rundungsdifferenzen je Verkäufer festlegen und durch reproduzierbare Tests absichern.
- [ ] Refunds pro Verkäufer und Belegposition zuordnen; Teilrefunds, Betrag-only-Erstattungen, gebrauchte Tickets und Veranstaltungsausfälle mit Zahlungs- und Fiskalanbieterstatus abstimmen.
- [ ] Entscheiden und dokumentieren, wie Gelder an mehrere Verkäufer ausgezahlt oder außerhalb des Plugins abgerechnet werden; Verkäuferrolle, Rechnungsaussteller, Zahlungsfluss und Haftung müssen zusammenpassen.

### Lizenzserver und Premium-Updates

- [ ] Lizenz-PRs #20–23 einzeln und gemeinsam testen: Aktivierung/Deaktivierung, falsches Produkt oder Site, abgelaufene/widerrufene Lizenz, API-Ausfall, Cache-Manipulation und erneute Aktivierung.
- [ ] Feature-Gates tatsächlich mit signierten Entitlements verbinden; ein vorhandener Prüfhelfer oder Cache schaltet noch keine Premium-Funktion frei.
- [ ] Premium-Updatepakete nur von erlaubten HTTPS-Hosts annehmen, SHA-256 vor Installation prüfen und Ablauf/Wiederholung des Download-Grants testen.
## Technische Abnahmekriterien für eine nahezu vollständige 1.0

Diese Punkte zielen auf technische Robustheit und reproduzierbare Releasequalität. Sie ergänzen die Feature-Arbeiten oben; offene Rechts-/Steuerfreigaben und Anbieter-Zertifizierungen bleiben separate Freigaben.

### CI, statische Qualität und Artefakt

- [ ] PHP_CodeSniffer mit WordPress Coding Standards, statische PHP-Analyse und Composer-Sicherheitsprüfung in CI einführen; Fehler müssen den Build blockieren.
- [ ] JavaScript-Syntax-/Lint-Prüfung auf alle eigenen JS-Dateien erweitern (einschließlich WooCommerce-Blocks, Admin, Offline-Scanner und Worker); zusätzlich relevante JS-Logik mit automatisierten Tests abdecken.
- [ ] PHP-Integrationssuite über die unterstützten PHP-Versionen laufen lassen; derzeit ist die Syntaxmatrix breiter als die Datenbank-Integration.
- [ ] Datenbankmatrix auf festgelegte unterstützte MySQL- und MariaDB-Minimum-/aktuelle Versionen erweitern; dokumentieren, welche Kombinationen tatsächlich freigegeben sind.
- [ ] WooCommerce-Tests als echte Installation für Mindest- und aktuelle Version sowie HPOS an/aus ausführen; Checkout Classic und Blocks in der Matrix behalten.
- [ ] Erzeugtes Release-ZIP in CI entpacken und in einer frischen WordPress-Installation installieren/aktivieren; enthaltene Dateien, Autoloader, Assets und Abwesenheit von Tests/Entwicklungsdateien prüfen.
- [ ] Reproduzierbarkeit des ZIP-Builds technisch prüfen: derselbe Commit und dieselben Abhängigkeiten müssen denselben Inhalt/Prüfsummen ergeben; Release-Commit und veröffentlichtes Paket abgleichen.
- [ ] Versions-, Changelog-, Plugin-Header-, Composer- und Stable-tag-Konsistenz vor Release automatisch prüfen.

### Datenbank, Zustandsautomaten und Fehlerpfade

- [ ] Upgrade-Integrationstests für jede unterstützte Vorgängerversion mit realistischen Daten durchführen; Migration mehrfach, parallel und nach simuliertem Prozessabbruch sicher wiederholen.
- [ ] Tabellenpräfixe, Fremdbezüge und Datenisolation automatisiert für Einzelinstallation, HPOS und Multisite prüfen; keine direkte WooCommerce-Tabellenabfrage zulassen.
- [ ] Kritische Abläufe bei DB-Deadlock, Lock-Timeout, Verbindungsabbruch und fehlgeschlagenem Commit systematisch fault-injection-testen; keine Erfolgsantwort darf ohne persistierten Zustand ausgegeben werden.
- [ ] Bestell-, Zahlungs-, Ticket-, Refund- und Check-in-Zustandsautomaten mit erlaubten/unerlaubten Übergängen dokumentieren und gegen doppelte, verspätete und out-of-order Events testen.
- [ ] REST-Endpunkte negativ testen: Gast, falsche Rolle, falscher Eventscope, widerrufener/abgelaufener Schlüssel, wiederholter Request und manipulierter Eingabekörper dürfen keine unberechtigte Änderung bewirken.
- [ ] Rate-Limits hinter Proxy/CDN und bei mehreren PHP-Workern validieren; dokumentieren, dass Plugin-Transients allein kein verteilter Schutz sind, und eine verlässliche Hosting-Option empfehlen.

### Last, Hintergrundjobs und Wiederanlauf

- [ ] Heavy-Checkout-, Check-in- und Webhook-Lastprofile sowie REST-Lasttests als geplante CI-/Releaseprüfung ausführen und Referenzwerte für Latenz, Fehlerquote und Deadlocks pflegen.
- [ ] Job-/Mail-/Webhook-Worker durch Fehler- und Neustartszenarien prüfen: abgelaufene Claims, Retry-Backoff, Dead-Letter, doppelte Ausführung und Wiederaufnahme müssen idempotent bleiben.
- [ ] System-Cron-Ausfall, verzögerte Queue, E-Mail-Transportfehler und zu lange Jobs sichtbar machen; Admin muss Rückstand, letzten erfolgreichen Lauf und nächste Aktion erkennen können.
- [ ] Restore-/Upgrade-Übung mit Ticket-, Bestell-, Check-in-, Refund- und Jobdaten durchführen und nach Restore Duplikate oder verwaiste Reservierungen ausschließen.

### Sicherheit, Datenschutz und UI-Abnahme

- [ ] Stripe-/PayPal- und andere Anbieter-Geheimnisse aus der Klartextspeicherung in der allgemeinen WordPress-Option `wpcap_settings` entfernen; verschlüsselten Credential-Vault oder externen Secret Store mit Schlüssel außerhalb der Datenbank festlegen. Bestehende Werte sicher migrieren und Rotation, Widerruf sowie Ausfall/Wiederherstellung fail-closed behandeln.
- [ ] Automatisiert nachweisen, dass Provider-Secrets nach Speichern/Migration nicht im Klartext in Optionen, REST-Antworten, Admin-HTML, Audit-Logs, Fehlern oder Paketdateien auftauchen; Schlüsselverlust und erneute Eingabe testen.
- [ ] Automatisierte Permission-Matrix für alle Admin-/REST-/Partner-/Käuferaktionen etablieren und sicherstellen, dass jede Route explizit geschützt oder bewusst öffentlich ist.
- [ ] Secret-Redaction und Eingabevalidierung unter Fehlerbedingungen testen; API-Schlüssel, Ticketcodes, PII und Providerantworten dürfen nicht in Logs, REST-Fehlern oder CI-Artefakten erscheinen.
- [ ] Dependency-/Secret-Scanning und Prüfung des erzeugten Pakets auf bekannte Schwachstellen bzw. eingebettete Geheimnisse als CI-Gates ergänzen.
- [ ] Datenschutzexport, Löschung und Retention automatisiert mit großen Datenmengen, Multisite, kommenden Events, gültigen Tickets, Finanzbelegen und WooCommerce-Daten testen.
- [ ] Browser-End-to-End-Matrix für aktuelle Chrome/Firefox/Safari sowie iOS/Android durchführen: Checkout, Kameraerlaubnis/Abbruch, QR-Fallback, Scanner, Bestellportal und WooCommerce Blocks.
- [ ] A11y-Prüfungen in Releaseprozess integrieren und bekannte Admin-Befunde beheben; danach Tastatur, 200%-Zoom, Screenreader, Kontrast und Fehlermeldungen manuell abnehmen.
- [ ] Unabhängigen Sicherheitsreview mit priorisiertem Befundregister, Fixes und Wiederholungstest abschließen; SECURITY.md und Meldungsprozess daran anpassen.
### Technische Randfälle, die noch separat abgesichert werden müssen

- [ ] Zeitzonen- und Sommerzeitwechsel deterministisch testen: Terminserien, Verkaufsfenster, Reservierungsablauf, Einlass-Tagesgrenzen, Erinnerungen und Service-Slots bei DST-Sprung und Mitternacht.
- [ ] Geldberechnungen mit Eigenschafts-/Randfalltests absichern: Null-/Dreidezimalwährungen, Rundung je Position vs. Gesamtbetrag, Rabatte, Steuern, Gebühren, Refunds und WooCommerce-Summen müssen centgenau abstimmbar sein.
- [ ] Für Berichts-Caches Invalidierungsregeln bei Bestellung, Refund, Check-in, Eventänderung und Löschung festlegen; Cache-Verhalten mit großen Datenmengen und SQL-Abfrageplänen testen.
- [ ] Ausgehende HTTP-Aufrufe absichern und testen: HTTPS/Loopback-Ausnahmen, DNS-/Redirect-Verhalten, SSRF-Schutz, Timeouts, Antwortgrößen, JSON-Validierung und Redaction; lokale Fiskalservices müssen weiter gezielt erreichbar bleiben.
- [ ] Bridge- und REST-Verträge mit automatisierten Kompatibilitätstests absichern: unterstützte Versionen, unbekannte/neue Felder, inkompatible Versionen, doppelte Requests und Netzwerk-Timeouts.
- [ ] Diagnose-/Reconciliation-Prüfung für inkonsistente Daten ergänzen: offene Holds, bezahlte Bestellungen ohne Tickets, Tickets ohne gültige Bestellung, doppelte Refunds, hängende Job-Claims und nicht abgeschlossene Migrationen auffinden; Reparaturen zunächst mit Vorschau/Audit ausführen.

## Auditbefunde – main @ 14522c7 (2026-11-12)

Die folgenden Punkte wurden beim statischen Code-Review bestätigt. Laufzeit- und Fehler-Injection-Tests dazu stehen noch aus.

### Bestätigte Befunde

- [ ] [P1][BESTÄTIGT] `.github/workflows/release.yml:30–45,76–99` — Ein gepushter `v*`-Tag löst den Release-Job ohne Prüfung eines erfolgreichen CI-Laufs für denselben SHA und ohne Prüfung der Abstammung von `main` aus; der Workflow checkt den Tag-Commit aus und veröffentlicht daraus ein ZIP. **Mögliche Auswirkung:** ungeprüfter oder fremder Stand kann als offizielles Release veröffentlicht werden. **Maßnahme:** Tag-Releases nur nach erfolgreichen Pflichtchecks für exakt denselben SHA und validierter Release-/Main-Abstammung zulassen; negative Workflow-Tests für fehlende/fehlgeschlagene Checks ergänzen.
- [ ] [P1][BESTÄTIGT] `src/Installer.php:658–670` — Rückgaben/Fehler von `dbDelta()` werden nicht geprüft; Datenbankversion und Schema-Fingerprint werden anschließend trotzdem fortgeschrieben. **Mögliche Auswirkung:** Nach einem partiellen Schemafehler gilt das Upgrade als abgeschlossen und automatische Wiederholungen können ausbleiben; spätere Funktionen treffen auf fehlende Tabellen/Spalten. **Maßnahme:** Schemaänderungen und resultierende Tabellen/Spalten verifizieren, Version/Fingerprint erst nach vollständigem Erfolg speichern, Fehler protokollieren und Wiederholung ermöglichen; Fehler-Injection-/Retry-Integrationstest ergänzen.
- [ ] [P1][BESTÄTIGT] `src/Payments/Webhooks.php:46–49` — `INSERT IGNORE` unterscheidet nicht zwischen `0` (bereits beanspruchtes Ereignis) und `false` (Datenbankfehler); beide Fälle werden als `duplicate` zurückgegeben. **Mögliche Auswirkung:** Ein Zahlungsanbieter erhält bei temporärem Datenbankfehler eine Erfolgs-/Duplikatantwort, obwohl Zahlung und Ticketverarbeitung nicht abgeschlossen wurden, und kann den Webhook-Retry einstellen. **Maßnahme:** Duplicate und SQL-Fehler getrennt behandeln; bei DB-Fehler retrybaren Fehler/HTTP 5xx liefern; Duplicate-, Fehler- und Retrypfade testen.
- [ ] [P1][BESTÄTIGT] `src/Orders.php:1083–1124` und `329–344` — Ticket-Insert, Teilnehmerzuweisungen/-antworten, Update der Bestell-Teilnehmerdaten sowie Transaktionsstart/Commit werden nicht durchgängig auf Fehler geprüft; Teilnehmerdaten auf Auftragsebene werden gelöscht, sobald mindestens ein Ticket ausgegeben wurde. **Mögliche Auswirkung:** bezahlte Bestellung kann unvollständige Tickets/Teilnehmerdaten hinterlassen oder die einzige noch vorhandene Teilnehmerdatenkopie verlieren, während der Zahlungsablauf weiterläuft. **Maßnahme:** kritische DB-Schreibvorgänge und Transaktionsresultate prüfen; bei Fehler vollständig zurückrollen und retrybaren Fehler melden; Teilnehmerdaten erst nach nachgewiesener Persistierung aller Zuordnungen löschen; Fehler-Injection-Test ergänzen.
- [ ] [P2][BESTÄTIGT] `src/Orders.php:1125–1158` und `src/Mail/Mailer.php:132–150` — Der Rückgabewert von `Mailer::deliver()` wird im Ticket-Ausgabeablauf ignoriert; Ticketcodes werden nur beim Erzeugen an die E-Mail übergeben und in der Datenbank gehasht gespeichert. **Mögliche Auswirkung:** Bei Mailfehler oder Abbruch nach Datenbank-Commit bleibt die bezahlte Bestellung mit gültigen Tickets bestehen, aber es gibt keinen automatischen Versand-Retry; die manuelle Nachsendung benötigt einen Eingriff und rotiert die Codes. **Maßnahme:** Ticketzustellung als dauerhaften Outbox-/Retry-Status speichern, Versand unabhängig von neu erzeugten Tickets wiederholbar machen und Fehler im Admin sichtbar anzeigen; Mailfehler- und Crash-nach-Commit-Tests ergänzen.
- [ ] [P2][BESTÄTIGT] `src/Refunds.php:142–159,174–196` — Mehrere Datenbankoperationen beim Setzen auf `processing` und beim Verbuchen eines erfolgreichen Provider-Refunds werden nicht auf Fehler geprüft; die externe Rückzahlung kann vor lokaler Finalisierung erfolgt sein. **Mögliche Auswirkung:** Zahlungsanbieter und Plugin können unterschiedliche Refund-/Ticket-/Bestellstände aufweisen; ein Refund kann in `processing` hängen oder als lokal unvollständig erscheinen. **Maßnahme:** persistente Refund-Zustandsmaschine mit Fehlerprüfung und Reconciliation/Retry nach externem Provider-Erfolg einführen; Fehler zwischen Provideraufruf und jedem lokalen Schreibschritt simulieren und idempotente Reparatur testen.

### Verdachtsfälle

- Im geprüften Umfang wurde kein zusätzlicher Verdachtsfall aufgenommen. Vermutungen ohne belastbaren Codebeleg wurden nicht als Befund gewertet.


## Folgereview – zusätzliche bestätigte Befunde (Plugin-Code @ 14522c7)

Diese Befunde kamen aus der erneuten Prüfung mit fünf Perspektiven. Der neueste Commit `b022c2b` vor diesem Eintrag änderte nur diese TODO-Datei, nicht den Plugin-Code.

### Bestätigte Befunde

- [ ] [P2][BESTÄTIGT] `src/Jobs.php:35–46`; `src/Communications.php:45–58` — Queue-Worker prüfen Transaktionsstart, bedingtes Claim-Update, betroffene Zeilen und Commit nicht, bevor der Job-Handler bzw. Mailversand ausgeführt wird. **Mögliche Auswirkung:** Bei DB-/Claim-Fehlern kann ein Eintrag in der Queue bleiben und parallel oder später erneut verarbeitet werden; Mails oder externe Job-Nebeneffekte können doppelt ausgelöst werden. **Maßnahme:** Verarbeitung nur bei erfolgreichem Claim mit genau einer aktualisierten Zeile und bestätigtem Commit starten; sonst rollbacken und retrybar abbrechen; Fehler-Injection- und Parallel-Worker-Tests ergänzen.
- [ ] [P2][BESTÄTIGT] `src/Orders.php:1212–1245` — Sammel-Nachsendung rotiert Ticketcodes einzeln und versendet erst nach Abschluss aller Rotationen; Updates sind nicht serialisiert und ein Update mit null betroffenen Zeilen gilt nicht als Fehler. **Mögliche Auswirkung:** Bei Teilausfall können bereits alte Codes ungültig sein, ohne dass die Nachricht versendet wird; parallele Nachsendungen können Links mit inzwischen ersetzten Codes zustellen. **Maßnahme:** Rotation und Zustellung als serialisierten, wiederaufnehmbaren Vorgang mit Status/Outbox implementieren; Update-/Rowcountfehler erkennen und Teilfehler reparierbar machen; parallele Nachsendungen und Fehler nach jeder Rotation testen.
- [ ] [P2][BESTÄTIGT] `src/Orders.php:739–760`; Aufruf `src/Payments/Webhooks.php:128–138` — `cancel_order()` ignoriert DB-Fehler bei Transaktion, Reservierungsfreigabe, Ticketwiderruf, Bestellstatus und Commit und meldet dennoch Erfolg; der Webhook kann als verarbeitet markiert werden. **Mögliche Auswirkung:** Bestellung, Reservierungen und Tickets können auseinanderlaufen, während der PSP einen 2xx erhält und den fehlgeschlagenen Vorgang nicht erneut sendet. **Maßnahme:** jede kritische Mutation und Commit prüfen, bei Fehler rollbacken und retrybaren Fehler zurückgeben, damit die Webhook-Zustellung wiederholt wird; Fehler-Injection-Test für den ganzen Stornopfad ergänzen.
- [ ] [P2][BESTÄTIGT] `src/Orders.php:784–799`; Aufruf `src/Payments/Webhooks.php:140–149` — `mark_refunded()` prüft Freigabe, Ticketwiderruf und Bestellupdate nicht auf Fehler, bevor der externe Vollrefund-Webhook als verarbeitet gilt. **Mögliche Auswirkung:** Provider-Refund und lokaler Ticket-/Bestellstatus können auseinanderlaufen; der Webhook-Retry wird durch die Erfolgsantwort unterdrückt. **Maßnahme:** DB-Ergebnisse prüfen und Fehler retrybar weitergeben; externen Vollrefund-Zustand bei wiederholten Events idempotent abgleichen; Fehlerfälle testen.
- [ ] [P2][BESTÄTIGT] `assets/offline-scanner/index.html:2,16–75`; `assets/offline-scanner/app.js:96–120,169–172,378–420,530–659` — Offline-Scanner-Texte, Statusmeldungen und Datumsformat sind auf Deutsch fest codiert (`lang="de"`), obwohl Übersetzungskataloge Sprachfassungen für mindestens Teile derselben Texte enthalten; die PWA lädt diese Kataloge nicht. **Mögliche Auswirkung:** Einlasskräfte anderer Sprachumgebungen erhalten deutsche Bedien-/Fehlermeldungen; Screenreader sprechen die Inhalte mit falscher Sprache aus. **Maßnahme:** Locale-abhängige PWA-Übersetzungsressource für UI, Status, Fehler und Datum implementieren, `html lang` korrekt setzen und automatisierte Sprach-/Screenreader-Checks ergänzen.

### Verdachtsfälle

- In dieser Folgerunde wurde kein zusätzlicher Verdachtsfall aufgenommen.

## Ergänzungen – Phase 3 und Expertenprüfung (Branch `claude/woocommerce-ticket-plugin-8q4m8q`, 2026-11-12)

### Phase 3 – im Branch umgesetzt, noch nicht in `main`

Diese Funktionen sind im Branch implementiert und getestet, aber noch nicht gemergt. Sie gelten erst nach Merge, CI und Abnahme als erledigt.

- [ ] Gästelisten und Freikarten (Freigabe-Workflow, Kontingent, Abholliste, Einlass nach Namen, Freikarten nicht im Umsatz) integrieren und abnehmen.
- [ ] Reservierte Kontingente/Sperren inkl. Sitzplatz-Sperren und Limit pro Käufer (race-frei) integrieren und abnehmen.
- [ ] Kassen, Schichten, Übergabe, Blindzählung, Kartenterminal-Abgleich und verketteter Z-Bericht integrieren; Z-Bericht-Hooks mit der Fiskalisierung (PR #14) verbinden.
- [ ] Ticketumtausch/Upgrade (Aufpreis-Zusatzbestellung, Differenzerstattung, Gebühr, Portal-Selbstbedienung) integrieren und abnehmen.
- [ ] Automatische Wartelisten-Angebote mit Inventar-Reservierung integrieren und parallele Annahmen auf echter Installation testen.
- [ ] Festival-/Mehrtagespässe über mehrere Termine integrieren und mit realem Mehrtages-Einlass abnehmen.
- [ ] Verkauf durch Veranstalter/Wiederverkäufer über die Partner-API (Schreibrechte, reservieren/bestätigen, Partner-Kontingente, Wiederverkäufer-Abrechnung) fertigstellen und integrieren.
- [ ] Leistung für Vorverkäufe mit 20.000+ Tickets (Zählerspalten mit Abgleich, Eventliste ohne N+1, Lastprofil „big“) fertigstellen, messen und Referenzwerte in docs/TESTING.md eintragen.

### Offene Nacharbeiten aus Phase 3

- [ ] Datenschutz-Export/-Löschung für Gästelisten-Einträge (`wpcap_guest_entries`) ergänzen.
- [ ] Datenschutz-Löschung soll laufende Wartelisten-Angebote (Platzhalter-Bestellung) sofort freigeben statt bis zum Ablauf zu halten.
- [ ] `wpcap_waitlist_process` beim Deaktivieren des Plugins abmelden.
- [ ] Wartelisten-Bestellungen unterstützen keine Rabattcodes, Gutscheine und Rechnungsdaten – entscheiden und ggf. ergänzen.
- [ ] Umtausch: Antworten auf Zusatzfelder ins neue Ticket übernehmen; eigenes Webhook-Ereignis `ticket.exchanged`; Differenzerstattung bei WooCommerce-Bestellungen automatisieren oder klar im Admin führen.
- [ ] Core-Hook `wpcap_reserve_capacity` um `$context['internal']`, Käufer-E-Mail und Benutzer-ID erweitern (Allocations/Käuferlimit prüfen derzeit in `wpcap_order_creating`).
- [ ] Neue Hooks dokumentieren (`wpcap_capacity_increased`, `wpcap_allocation_*`, `wpcap_boxoffice_*`, `wpcap_ticket_exchanged`, Pass-Filter) in docs/HOOKS.md.
- [ ] Pässe: Online-Verkauf endet mit Beginn des Verkaufstermins; „N aus M“ offline nur näherungsweise; nach Terminabsagen keine Neuprüfung der Restanzahl – fachlich entscheiden.
- [ ] Kasse: Bar-/Kartenerstattungen (auch automatische bei Eventabsage) benötigen eine offene Schicht – Betriebsablauf festlegen und im Admin sichtbar machen.

### Steuer und Recht – Fragen an die Steuerberatung (Details in docs/SALES-MODELS.md, docs/VOUCHERS.md, docs/GUEST-LISTS.md)

- [ ] Eigenhändler: Basis der Gutschrift (voller Ticketumsatz plus Gebührenrechnung vs. Umsatz abzüglich Provision) bestätigen.
- [ ] Leistungsort bei Events im Ausland und Reverse Charge nach Sitz des Veranstalters vs. Veranstaltungsort klären; ggf. Registrierungspflichten.
- [ ] UID-Prüfung: genügt formale Prüfung oder ist qualifizierte VIES-Bestätigung vor Reverse Charge Pflicht?
- [ ] Negative Abrechnungsmonate (Erstattungen > Verkäufe) und Kleinunternehmer-Fälle festlegen.
- [ ] E-Rechnungspflicht (XRechnung/ZUGFeRD) für B2B-Belege an Veranstalter und Firmenkunden in DE umsetzen, sobald der Umfang bestätigt ist.
- [ ] Präfix „GS“ und Exportbezeichnung „Gutschrift“ für Rechnungskorrekturen umbenennen, um Verwechslung mit Selbstfakturierungs-Gutschriften (GU) zu vermeiden.
- [ ] Rechnungskorrekturen mit Aufschlüsselung je Steuersatz (Netto/USt/Brutto) prüfen und ggf. ergänzen.
- [ ] Freikarten gegen Sponsoring/Medienleistungen (tauschähnlicher Umsatz) und Freikarten für Personal bewerten.
- [ ] Mehrzweckgutscheine: Erstattungszeilen (`wpcap_refunds`) enthalten nur den Baranteil – Berichte/Abrechnungen auf Gutschrift-Beträge bzw. `refund_plans` umstellen.
- [ ] Einzweckgutscheine aus dem Ticketdienst: Veranstalter-Abrechnung soll den gespeicherten Gutscheinwert (`face_value_minor`) berücksichtigen.
- [ ] Regionale Abgaben (Vergnügungssteuer, Kulturförderabgabe) und Nicht-EUR-Währungen (EUR-Gegenwert der USt) abbilden oder ausschließen.
- [ ] Affiliate-Provision bei Mehrzweckgutscheinen: Basis nur Baranteil oder voller Preis entscheiden (PR #19).

### UX- und Barrierefreiheitsbefunde aus der Expertenprüfung (noch offen)

- [ ] Admin-Menü gruppieren (Verkaufen, Einlass, Kommunikation, Einstellungen) und „Erste Schritte“-Checkliste für neue Veranstalter ergänzen; Eventliste mit Verkauft/Kapazität/Umsatz.
- [ ] Web-Scanner-Ergebnis wie in der Offline-PWA (großes farbiges Ergebnis, Ton/Vibration, Zeit und Ort der Erstnutzung) und kurzer, eintippbarer Hilfscode (nur für Personal, gedrosselt).
- [ ] Saalplan mobil nutzbar machen (Mindestgröße der Sitze, Zoom/Pan, Legende mit Form statt nur Farbe, Liste als Standard auf schmalen Bildschirmen).
- [ ] Ticketseite und Ticket-PDF um Teilnehmername, Veranstaltungsadresse, Einlasszeit, Bestellnummer und Wallet-Button ergänzen; PDF-Dateiname lesbar.
- [ ] Einlasszeit („Doors“) als eigenes Feld am Termin; Zutritt vor Einlass verhindern.
- [ ] Kapazität pro Einlassbereich (Stehbereich) am Einlass durchsetzen.
- [ ] Alters-/Ausweispflicht pro Ticketart mit Hinweis im Scanner.
- [ ] No-Show-Liste/Export pro Termin.
- [ ] Getrennte Kontingente pro Verkaufskanal (online, Kasse, WooCommerce, Partner) und Kanal-Auswertung.
- [ ] Abrechnungsblatt pro Vorstellung für Spielstätte/Künstler (Kapazität, Freikarten, Besucher, Door Deal).
- [ ] Buchhaltungsexport um nicht fakturierte Online-/Kassenverkäufe ergänzen und Tagesgrenzen in Ortszeit statt UTC.
- [ ] Einstellungsseite in Tabs aufteilen; Online- und Kassen-Zahlarten trennen; Rechtstexte mit Editor und je Sprache.
- [ ] Eventkarte kompakt (Zusammenfassung + „Tickets“-Button), Mengen-Stepper, Kalender mit Verfügbarkeits-Badges und Link zum nächsten Termin.
- [ ] HTML-Mail-Layout als Standard passend zu WooCommerce prüfen.
- [ ] Nicht vorhandene/mehrdeutige Ortszeiten bei Sommerzeitwechsel im Admin anzeigen statt still verschieben.

### Architektur und Wartbarkeit (noch offen)

- [ ] Gebündelte Bibliotheken (`endroid/qr-code`, `bacon`) mit Strauss/PHP-Scoper präfixieren, um Konflikte mit anderen Plugins zu vermeiden.
- [ ] Hook-Präfixe vereinheitlichen (`wp_pass_captain_*_v1` vs. `wpcap_*`) und eine stabile Liste öffentlicher Hooks festlegen.
- [ ] Index-Längen > 767 Bytes (`wallet_registrations.device_serial`, `payment_events.provider_event`, `member_passes.member_status`) kürzen oder Mindestanforderungen dokumentieren.
- [ ] Fest codierte deutsche JS-Texte im Saalplan-Editor übersetzbar machen; Umstieg auf englische Quelltexte für translate.wordpress.org prüfen.
- [ ] Kassen-Warnhinweis „keine Registrierkasse“ ausblenden, sobald ein Fiskal-Adapter aktiv und eingerichtet ist (nach Merge von PR #14).
- [ ] Übersetzungen fr_FR, it_IT, es_ES, nl_NL muttersprachlich gegenlesen lassen; Platzhalter in Hilfetexten (`{Termin-ID}` u. a.) übersetzbar machen.

## Auditbefunde Runde 3 – Branch `claude/woocommerce-ticket-plugin-8q4m8q` @ a54e21c (2026-11-13)

Fünf Prüfungen (Sicherheit, Abläufe E2E, Steuer/Finanzen, Datenschutz, Architektur/QA), alle Befunde auf echter WP-7.1-/WC-11.3-Installation bzw. mit Skripten nachgestellt. Integrationssuite zum Prüfzeitpunkt: 1986 bestanden, 0 fehlgeschlagen. Überschneidungen sind zusammengeführt (Steuer-P1 zum Umtausch ↔ Ablauf-P2 Positionsanzeige).

### Sicherheit (Prüfung 1)

- [x] [P1][BESTÄTIGT] `src/Exchange/Portal.php:51–66`; `src/Exchange/Service.php:151–199,373,700–708` — Portal-Umtausch prüft nicht, ob das Ticket bereits übertragen wurde (`transfer_count`, `attendee_email`): Der ursprüngliche Käufer kann ein übertragenes 50-€-Ticket auf 10 € herabstufen und erhält 40 € automatisch erstattet (nachgestellt: `completed`, `refund_minor=4000`, altes Ticket `revoked`). **Mögliche Auswirkung:** Betrug beim Weiterverkauf; Käuferin erhält ohne Zustimmung ein schlechteres Ticket. **Maßnahme:** Übertragene/zugewiesene Tickets im Portal nur durch die aktuelle Inhaberin umtauschen lassen oder sperren; Erstattung bei geänderter Inhaberschaft nur durch Personal; Negativtest.
- [ ] [P2][BESTÄTIGT] `src/Allocations/BuyerLimits.php:123ff`; `src/Exchange/Service.php:373ff`; `src/Orders.php:671` — Käuferlimit greift nur in `wpcap_order_creating`, nicht beim Umtausch (nachgestellt: VIP-Limit 1, drei Umtausche Standard→VIP ergeben 4 VIP-Tickets). **Mögliche Auswirkung:** Hamsterkäufe knapper Ticketarten. **Maßnahme:** Limit auch in `phase=exchange` mit derselben Sperrlogik durchsetzen; Test.
- [ ] [P2][BESTÄTIGT] `src/Exchange/AdminPage.php:43–57`; `src/BoxOffice/Service.php:36,316–322` — Gratis-Upgrade (`waive`) und Kassen-Zahlart `free` brauchen nur `wpcap_manage_orders`, nicht `wpcap_approve_comps` (nachgestellt: Support tauscht 10 € → 500 € ohne Aufpreis; Kasse verkauft 10 × 200 € als Freikarte). **Mögliche Auswirkung:** Freikarten in beliebiger Höhe ohne Vier-Augen-Prinzip. **Maßnahme:** An `wpcap_approve_comps` binden, optional Schwelle/Freigabe; Rechte-Matrix-Test.
- [ ] [P2][BESTÄTIGT] `src/BoxOffice/Shifts.php:299–352`; `src/BoxOffice/ShiftPage.php:244–256` — Blindzählung umgehbar: Soll wird nach erster Zählung angezeigt, „Neu zählen“ unbegrenzt, Z-Bericht zeigt nur letzte Zählung. **Mögliche Auswirkung:** Fehlbeträge lassen sich verschleiern. **Maßnahme:** Soll erst nach Abschluss zeigen oder Nachzählung nur mit Finanzrecht; alle Zählungen im Z-Bericht; Test.
- [ ] [P3][BESTÄTIGT] `src/BoxOffice/Service.php:459–471` (auch `src/Partners/Sales.php:730`) — Storno erkennt Nutzung nur an `status=checked_in`; Wiedereinlass-/Mehrfach-/Pass-Tickets bleiben `valid` und werden ohne Override erstattet. **Maßnahme:** Nutzung über `wpcap_checkins`/`wpcap_admission_entries` wie `Exchange\Service::was_admitted()` prüfen.

Verdachtsfälle (nicht end-to-end bestätigt):
- [ ] [VERDACHT] `src/Partners/Sales.php:728–731` — Partner-Storno: Prüfung „Termin begonnen“ schützt bei Pässen mit anderem Anker-Termin wohl nicht.
- [ ] [VERDACHT] `src/Exchange/Service.php:700–708` — Portal-Herabstufung bei Partner-/Kassenbestellungen bucht Erstattung über Geld, das die Plattform nie erhielt.
- [ ] [VERDACHT] `src/Rest/Controller.php` `pos_sale` — alte Route `/pos/sales` umgeht Schicht, Belegnummer und Z-Bericht.
- [ ] [VERDACHT] `src/Orders.php:1500ff` — Portal-Nachsendung erneuert auch Codes übertragener Tickets (Käufer kann QR-Code der Empfängerin ungültig machen).
- [ ] [VERDACHT] `src/GuestLists/Service.php:93–98,305` — Freikarten-Antrag und Freigabe durch dieselbe Person möglich.

### Abläufe Ende-zu-Ende (Prüfung 2, Browser/WP-CLI/REST auf WP 7.1 + WC 11.3)

- [x] [P1][BESTÄTIGT] `src/Exchange/Portal.php:151–153` — Umtausch-Skript wird nur nach dem ersten Ticket ausgegeben; Formulare weiterer Tickets senden nativ per GET (Startseite `/?ticket_type_id=…`, nichts passiert). **Mögliche Auswirkung:** Selbstbedienungs-Umtausch nur für das erste Ticket. **Maßnahme:** Skript einmal ausgeben bzw. Event-Delegation; E2E-Test mit mehreren Tickets.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:242–253,589,612` — `ticket_value()` mittelt `line_total/qty` je Ticketart; nach Umtausch in dieselbe Art sinkt der Wert der übrigen Tickets (Erstattung 26,56 € statt 58,10 €). **Mögliche Auswirkung:** Zu niedrige Erstattungen. **Maßnahme:** Wert je Ticket als Snapshot speichern; Regressionstest „Upgrade, dann Rückstufung“.
- [x] [P1][BESTÄTIGT] `src/Passes/Cancellation.php:112–124` — Absage eines Pass-Tags mit `on_cancel=revoke` widerruft alle Pässe ohne Mail; bei `auto_refund_cancelled=0` weder Erstattung noch Hinweis. **Mögliche Auswirkung:** Bezahlte Pässe still ungültig, Abweisung am Einlass. **Maßnahme:** Absage-Mail; Bestellungen als „Erstattung offen“ führen und im Admin zeigen.
- [ ] [P1][BESTÄTIGT] `src/Rest/Controller.php:279`; `src/Waitlists.php:462`; `src/Exchange/Service.php:804` — Haltezeit bei Überweisung (72 h) nicht auf Terminbeginn begrenzt („Zahlbar bis 16.11.“ für Event am 13.11.). **Mögliche Auswirkung:** Plätze bis nach dem Event blockiert, Tickets nachträglich ausgestellt. **Maßnahme:** min(Einstellung, Beginn − Puffer); Überweisung kurz vor Beginn ausblenden.
- [ ] [P1][BESTÄTIGT] `templates/event-card.php:29–31` — Ausverkaufte Karten zeigen weiterhin das Checkout-Formular und keinen Wartelisten-Eintrag (nur per REST). **Mögliche Auswirkung:** Warteliste für Käufer:innen nicht erreichbar. **Maßnahme:** Wartelisten-Formular einblenden, Checkout bei komplett ausverkauft ausblenden.
- [ ] [P2][BESTÄTIGT] `src/OrderPortal.php:84` — Teilnehmer-E-Mail im Portal Pflicht (im Checkout optional); Namenskorrektur ohne E-Mail abgelehnt, mit E-Mail rotiert der Code. **Maßnahme:** E-Mail optional; Name/Antworten ohne Codewechsel speichern.
- [ ] [P2][BESTÄTIGT] `src/OrderPortal.php:81`; `src/Mail/Types.php:156` — Offene Bestellungen zeigen in Portal und Bestellmail keine Positionen, nur Betrag und Bankdaten. **Maßnahme:** Positionsübersicht (Event, Termin, Art, Menge, Preis, Rabatt).
- [ ] [P2][BESTÄTIGT] `src/Exchange/Portal.php:170` — Nach Upgrade mit Aufpreis keine Bankdaten/Betrag/Verwendungszweck im Portal; offener Umtausch nach Neuladen unsichtbar, Button erneut angeboten. **Maßnahme:** Bankdaten anzeigen; offenen Umtausch mit Link zur Zusatzbestellung darstellen.
- [ ] [P2][BESTÄTIGT] `src/Exchange/Service.php:589,605–612`; Admin-Bestellansicht — Nach Umtausch widersprüchliche Positionen (2 × 49,90 = 105,34), Zusatzbestellung „Erstattet 1“ bei 0,00 €, Abweichung von bereits ausgestellter Rechnung. **Maßnahme:** Umtausch als eigene Bewegungen (siehe Steuer-P1); Anzeige „Umgebucht“.
- [ ] [P2][BESTÄTIGT] `src/Printing/Page.php:29`; `src/BoxOffice/Page.php:96–101` — Terminauswahl der Kasse/Gästeliste/Abholliste enthält Vergangenes, Entwürfe, Abgesagtes und wählt den ältesten. **Mögliche Auswirkung:** Verkauf/Einlass auf falschen Termin. **Maßnahme:** Standard = nächster/laufender Termin; Rest ausblenden oder gruppieren.
- [ ] [P2][BESTÄTIGT] `src/Rest/Controller.php:142` — Rabattcodes nur per REST anlegbar, keine Adminseite; Feld „Rabattcode“ immer sichtbar; Preisvorschau ignoriert den Code. **Maßnahme:** Kampagnen-Adminseite, Feld nur bei aktiven Codes, Live-Vorschau.
- [ ] [P2][BESTÄTIGT] `src/Mail/Types.php:263` — Erstattungsmail sagt „erstattet“ auch bei Überweisung (Geld noch nicht unterwegs), Käufer-IBAN wird nicht erfasst; Mail/Admin zeigen 32-stellige Hex-ID statt Bestellnummer. **Maßnahme:** Text je Zahlart, IBAN-Abfrage, überall kurze Bestellnummer.
- [ ] [P2][BESTÄTIGT] `src/Admin/EventsPage.php`; `src/OccurrenceCancellation.php:85` — Terminabsage nur mit `confirm()`, ohne Grund, Folgenvorschau und Zusammenfassung; Auslastung zählt Pässe nicht. **Maßnahme:** Bestätigungsseite mit Folgen, Summary-Notice.
- [ ] [P3][BESTÄTIGT] `src/BoxOffice/Service.php:277` — Kassenverkäufe ohne E-Mail senden Ticketmail an `boxoffice+…@invalid.example`. **Maßnahme:** Versand an Platzhalter unterdrücken.
- [ ] [P3][BESTÄTIGT] `src/Checkin.php:364` — Ticket für anderen Tag: „zu dieser Uhrzeit nicht möglich“ ohne Gültigkeit; Web-Scanner ohne Terminauswahl. **Maßnahme:** Gültigkeit nennen, Terminauswahl.
- [ ] [P3][BESTÄTIGT] `src/PublicEvents.php:91–96` u. a. — Shortcode/Block ohne Event-/Termin-Attribut; „bis 1 Stunden“; gleichnamige Events ohne Datum in Dropdowns; Kassen-/Partnerformulare verlangen rohe IDs; Partner-Rechnungshinweis doppelt. **Maßnahme:** Attribute `event`/`occurrence`, Auswahllisten, Texte korrigieren.

Verdachtsfälle:
- [ ] [VERDACHT] Pass mit zwei Terminen am selben Tag: Web-Scanner bindet an Betriebstag, zweiter Scan „Tageskontingent erreicht“ – Abendshow vermutlich nicht betretbar.
- [ ] [VERDACHT] Blindzählung wirkt nur für Kassierer ohne Manager-/Finanzrecht; UI erklärt das nicht.
- [ ] [VERDACHT] `src/BoxOffice/ZReport.php` `rows()` ohne Null-Prüfung (Warnings bei ungültiger ID).

Fehlende Abläufe: Wartelisten-Eintrag für Käufer:innen; Rabattcode-Verwaltung; Ticketarten je Termin kopieren/Vorlagen; Erstattung per Überweisung mit IBAN und Status „ausgezahlt“; Gutschrift/Rechnungskorrektur in Admin und Portal verlinken; Ticketmail mit Portal-Link, Bestellnummer und Teilnehmernamen, Umtauschmail mit PDF; Verkaufsschluss für Überweisung; Absage-Vorschau; Terminauswahl im Web-Scanner. Nicht geprüft: Offline-PWA, Wartelisten-Fristablauf, Woo-Classic-Checkout, Serien-Preisstufe und Rabatt in Woo.

### Steuer und Finanzen (Prüfung 3)

- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:580–591,601–612`; `src/Partners/Settlements.php:108–126`; `src/Reports/Data.php:139–150` — Umtausch verändert bereits abgerechnete Bestellpositionen: umgezogene Einheit bekommt neue `item_id` und erscheint erneut als `late_sale`; bei Herabstufung wird die Zielposition gekürzt und die Erstattung zusätzlich abgezogen; Umtauschgebühr in keiner Position; Zusatzbestellung zählt Menge 1 (Ticketgebühr doppelt). Beispiel: 30.000 Cent abgerechnet bei 17.000 vereinnahmt. **Mögliche Auswirkung:** Doppelte Auszahlung/Gutschrift an Veranstalter, falsche Provision, nachträglich veränderte Berichte geschlossener Zeiträume. **Maßnahme:** Positionen nach Zahlung unveränderlich halten, Umtausch als eigene Buchungszeilen (Abgang/Zugang/Gebühr), Abrechnung/Berichte umstellen, Abstimmungstest „Abrechnung = Zahlungen − Erstattungen“.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:242–253` — `ticket_value()` nutzt `total_minor/subtotal`; bei Mehrzweckgutschein ist `total_minor` nur der Barrest → voll per Gutschein bezahltes Ticket hat Wert 0 (Upgrade kostet vollen Zielpreis, Downgrade kostet statt zu erstatten). **Mögliche Auswirkung:** Kunden zahlen zu viel, doppelter USt-Ausweis. **Maßnahme:** Wert aus `Checkout::gross_minor()`/Positionsbrutto nach Rabatt; Gutscheinanteil über `refund_plan`.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:580–591`; `src/Invoices.php:70,318` — Umtausch in Ticketart mit anderem Steuersatz schreibt Positions-USt um, `orders.tax_minor` und Rechnung bleiben; später ausgestellte Rechnung ist widersprüchlich (Kopf 909 vs. Zeilen 1.287 Cent). **Mögliche Auswirkung:** Unrichtiger Steuerausweis (§ 14c UStG), Abweichung USt-Voranmeldung/Berichte. **Maßnahme:** Steuersatzwechsel nur mit Rechnungskorrektur + neuem Beleg oder sperren; `tax_minor` konsistent; Rechnungskopf aus Zeilen.
- [ ] [P1][BESTÄTIGT] `src/Orders.php:196–212` vs. `src/Campaigns.php:31–51` — Zielgerichtete Kampagnenrabatte (Ticketart/Event) werden anteilig auf alle Positionen verteilt (Beispiel: USt 1.932 statt 1.742 Cent, fremde Position um 25 € gekürzt). **Mögliche Auswirkung:** Falsche USt je Satz, Rabatte eines Events zulasten anderer Veranstalter. **Maßnahme:** Zeilenverteilung aus `Campaigns::calculate()` übernehmen und je Position (`discount_minor`) speichern.
- [ ] [P1][BESTÄTIGT] `src/Exchange/Service.php:879–890`; `src/BoxOffice/Shifts.php:611–620` — Kassen-Umtausch bestätigt Zusatzbestellung ohne Kassenverkauf, Beleg und Schichtbuchung; spätere Erstattungen werden nicht gebucht. **Mögliche Auswirkung:** Bareinnahmen fehlen im Z-Bericht/Kassen-Soll; Einzelaufzeichnungs- und Belegpflicht (§ 146/146a AO, § 131/132a BAO) verletzt. **Maßnahme:** Aufpreis/Gebühr als Kassenverkauf mit Belegnummer in offene Schicht buchen; Erstattungen ebenso.
- [ ] [P2][BESTÄTIGT] `src/Refunds.php:513–537,539–558` — `tax_for()` teilt USt nach Rabatt durch Brutto vor Rabatt (Beispiel: 10,67 € statt 13,33 €); betrifft auch `record_external()`. **Maßnahme:** `line_shares()` mit Positionsbrutto nach Rabatt; Eigenschaftstest „Summe Korrekturen = Rechnung je Satz“.
- [ ] [P2][BESTÄTIGT] `src/Refunds.php:550–551` — Betragserstattung bei Mehrzweckgutschein rechnet volle USt gegen Barrest (3,33 € statt 1,67 €). **Mögliche Auswirkung:** Zu hohe USt-Minderung. **Maßnahme:** Basis `Checkout::gross_minor()` und Sätze der betroffenen Positionen.
- [ ] [P2][BESTÄTIGT] `src/Invoices.php:18,31–91` — Rechnung nach Teilerstattung weist volles Entgelt ohne Korrektur aus (Korrekturen nur, wenn Rechnung vor Erstattung existierte; `auto_invoice` standardmäßig aus). **Mögliche Auswirkung:** § 14c UStG, überhöhter Export. **Maßnahme:** Beim Ausstellen Korrekturbelege für frühere Erstattungen erzeugen oder Rechnung auf Entgelt nach Erstattungen begrenzen.
- [ ] [P2][BESTÄTIGT] `src/Partners/Statement.php:124–133` — Wiederverkäufer-Abrechnung ignoriert Reverse Charge und Kleinunternehmer, ohne Belegnummer. **Maßnahme:** Logik aus `SalesModel::reverse_charge()`/`small_business`; Beleg festlegen (Gutschrift mit Nummernkreis oder Rechnung des Wiederverkäufers).
- [ ] [P2][BESTÄTIGT] `src/Exchange/Service.php:170–175` — Serien-Umtausch prüft nicht denselben Veranstalter/dasselbe Verkaufsmodell. **Mögliche Auswirkung:** Sperre gegen gemischte Verkäufer umgangen; Rechnung von A für Leistung von B. **Maßnahme:** Umtausch zwischen verschiedenen Partnern/Verkaufsmodellen sperren.
- [ ] [P2][BESTÄTIGT] `src/Integrations/Vouchers/Checkout.php:121–131` — Einzweckgutschein im Checkout als `discount_minor` gebucht → Veranstalter-Abrechnung/Gutschrift sinken um den Gutscheinwert. **Maßnahme:** Gutscheinwert getrennt führen, in Abrechnung als Forderung gegen den Gutscheinverkäufer ausweisen.
- [ ] [P3][BESTÄTIGT] `src/Invoices.php:73–83` vs. `src/Orders.php:203–206`, `src/Reports/Data.php:241–252` — Unterschiedliche Rabatt-Rundung (floor kumulativ vs. intdiv + Rest). **Maßnahme:** Gemeinsamer Allokator bzw. gespeicherter Zeilenrabatt.
- [ ] [P3][BESTÄTIGT] `src/Passes/Cancellation.php:93–96`; `src/Refunds.php:550–551` — Pass-Tageserstattungen mit Durchschnitts-USt der Bestellung statt Pass-Satz. **Maßnahme:** `tax_minor` mit Pass-Satz übergeben.
- [ ] [P3][BESTÄTIGT] `src/Reports/Data.php:58`; `src/Partners/Settlements.php:113–120` — Kassen-Freikarten (`pos_free`) zählen als verkauft, Ticketgebühr fällt an. **Maßnahme:** `pos_free` in `comp_providers()` aufnehmen oder dokumentieren.
- [ ] [P3][BESTÄTIGT] `src/BoxOffice/Shifts.php:621–626` — Stornos von Kassenverkäufen „Rechnung“/„Freikarte“ ohne offene Schicht werden nie gebucht; Grand Total ohne Storno. **Maßnahme:** Nachbuchungs-Warteschlange für nächste Schicht.
- [ ] [P3][BESTÄTIGT] `src/Orders.php:198`; `src/BoxOffice/Service.php:276–280` — Rechnungsdatenpflicht über Schwelle greift nicht bei Kasse („Zahlung auf Rechnung“), Partner-API und Zusatzbestellungen. **Maßnahme:** `Billing::required_for` auch dort erzwingen.

Fragen an die Steuerberatung (neu):

- [ ] Leistungszeitpunkt bei Vorverkauf: Gutschrift/Provisionsrechnung ordnen nach Bestelldatum; Anzahlungsabrechnung (§ 13 Abs. 1 Nr. 1a S. 4 UStG / § 19 Abs. 2 Z 1 lit. a UStG AT) korrekt bezeichnet oder nach Veranstaltung/Vereinnahmung trennen?
- [ ] Umtauschgebühr: Nebenleistung zum Ticket oder eigenständige Leistung zum Regelsatz? Beleg bei Verrechnung mit Erstattung?
- [ ] Umtausch mit Steuersatzwechsel oder in anderes Event: Rechnungsberichtigung + neue Rechnung oder Storno und Neuverkauf?
- [ ] Partner-API-Verkäufe im Eigenhändler-Modell (Wiederverkäufer kassiert): zweite Kommissionsstufe? Wer belegt die Wiederverkäufer-Vergütung?
- [ ] Zielgerichtete Rabatte bei gemischten Steuersätzen: zwingend der rabattierten Leistung zuordnen?

### Datenschutz (Prüfung 4)

- [ ] [P2][BESTÄTIGT] `src/Modules/Allocations.php:103–108`; `src/Allocations/BuyerLimits.php:105–110,157–181` — `buyer_key` in `wpcap_alloc_buyers` ist ungesalzenes `sha256('email:'…)`/`sha256('user:'…)`, dazu Bestell-IDs in `holdings`; Tabelle weder exportiert, gelöscht noch von der Retention erfasst. **Mögliche Auswirkung:** Re-Identifizierung anonymisierter Bestellungen über bekannte E-Mail (Art. 17, Art. 5 Abs. 1 lit. e). **Maßnahme:** `hash_hmac` mit `wp_salt()` (Migration), Eraser entfernt Zeilen/Bestell-IDs, Löschung nach Ende des letzten Termins.
- [ ] [P2][BESTÄTIGT] `src/Modules/GuestLists.php:73–94`; `src/Privacy/Retention.php:72` — Keine Löschfrist für `wpcap_guest_entries` (Name, E-Mail, Firma, Notiz, auch abgelehnte/stornierte). **Mögliche Auswirkung:** Unbegrenzte Speicherung, Notizen evtl. Gesundheitsdaten (Art. 9). **Maßnahme:** Retention-Schritt nach Terminende + `attendee_retention_days` (personenbezogene Felder leeren, Zählwerte behalten); Hinweis im Notizfeld.
- [ ] [P2][BESTÄTIGT] `src/Waitlists.php:58–105`; `src/Privacy/Retention.php:72` — Wartelisteneinträge werden nach dem Event nie gelöscht/anonymisiert; keine Frist in PolicyText/OPERATIONS. **Mögliche Auswirkung:** Verstoß gegen Speicherbegrenzung. **Maßnahme:** Pseudonymisieren/löschen X Tage nach Termin-/Eventende; Frist dokumentieren.
- [ ] [P2][BESTÄTIGT] `src/Partners/Api.php:176–232`; `src/Partners/Keys.php:133–137` — Lesende Partner-Endpunkte filtern nur nach verknüpften Events, nicht nach Rolle/eigenem Kanal: ein Wiederverkäufer mit `attendees:read` sieht E-Mails aller Käufer des Events (online, Kasse, andere Partner). **Mögliche Auswirkung:** Unzulässige Übermittlung an Dritte (Art. 5, 6). **Maßnahme:** Für `role=reseller` auf eigene `wpcap_partner_orders` beschränken oder `attendees:read` sperren; Admin-Warnung; Test.
- [ ] [P2][BESTÄTIGT] `src/Privacy/PolicyText.php:105`; `docs/OPERATIONS.md:78` — Texte beschreiben sofortige Ungültigkeit künftiger Tickets bei Löschung, tatsächlich wird zurückgestellt (`wpcap_privacy_defer_upcoming`, `src/Privacy.php:278–284`); Abschnitte zu Gästelisten (Art. 14), Warteliste mit Auto-Angebot, Käuferlimits, Kasse/Rechnung auf Firma, Umtausch, Partnerverkauf fehlen. **Mögliche Auswirkung:** Unrichtige/unvollständige Information (Art. 12–14). **Maßnahme:** Text korrigieren, Abschnitte modulabhängig ergänzen, Rolle der Partner benennen.
- [ ] [P3][BESTÄTIGT] `src/Modules/Partners.php:223–229`; `src/Partners/Sales.php:858–878` — `wpcap_partner_ticket_codes` bleiben nach Löschung/Widerruf und Retention bestehen. **Maßnahme:** Bei `wpcap_ticket_revoked`/Privacy-Löschung und nach Terminende + Frist löschen.
- [ ] [P3][BESTÄTIGT] `src/Privacy.php:172–180` — Export ohne Wartelisten-Menge/Termin/Angebot, ohne `wpcap_ticket_exchanges` und Partner-Verkaufsdaten. **Maßnahme:** Exporter ergänzen.
- [ ] [P3][BESTÄTIGT] `src/GuestLists/Service.php:390` — Begleittickets tragen den Namen des Gastes („Begleitung von …“). **Maßnahme:** Neutral „Begleitung“ oder per Filter abschaltbar.
- [ ] [P3][BESTÄTIGT] `src/BoxOffice/Service.php:624` — Kundenbelege zeigen den Klarnamen der Kassierkraft. **Maßnahme:** Kürzel/Bediener-ID, Klarname per Einstellung.
- [ ] [P3][BESTÄTIGT] `src/Partners/Repository.php:58–97`; `src/Partners/Settlements.php:472` — Kontaktpersonen und IBAN der Partner im Klartext, dauerhaft, nicht exportiert/gelöscht. **Maßnahme:** In Exporter/Eraser aufnehmen (Snapshot ausgenommen), IBAN maskiert anzeigen.


### Architektur und QA (Prüfung 5)

- [x] [P1][BESTÄTIGT] `src/Orders.php:964–983`; `src/Payments/Webhooks.php:123–124` — Bei verspäteter Zahlung wird ein vorübergehendes `db_busy` (Modul-Filter oder `capacity_error()`) wie „ausverkauft“ behandelt: Bestellung `needs_review`/`paid_unallocated`, Webhook gilt als verarbeitet (nachgestellt: 50 Plätze frei, trotzdem 0 Tickets). Erneuter Versuch im Admin nur für `manual`, Erstattung verlangt `paid`. **Mögliche Auswirkung:** Bezahlte Bestellungen ohne Tickets bei Stripe, PayPal, Mollie, Partner, Kasse und Warteliste; falsche Admin-Mail „kein Kontingent“. **Maßnahme:** Wiederholbare Fehler → `payment_busy()` (Webhook wird erneut zugestellt); Admin-Aktion „Zuteilung erneut versuchen“ für `needs_review`; Fault-Injection-Test.
- [ ] [P2][BESTÄTIGT] `src/Integrations/Vouchers/ProviderInterface.php:8–12` vs. `src/Integrations/Vouchers/Checkout.php:441–486` — Dokumentiertes Feld `treatment` (`payment`/`single`/`discount`) wird nicht ausgewertet; alle Werte ergeben `multi`. **Mögliche Auswirkung:** Falsche USt-Behandlung bei vertragstreuen Anbietern. **Maßnahme:** `treatment` auswerten oder Docblock korrigieren; Vertragstest.
- [ ] [P2][BESTÄTIGT] `src/Orders.php:338–339` vs. `996–997` — `wpcap_order_paid` feuert bei bezahlten Bestellungen vor, bei kostenlosen nach der Ticketausgabe (Webhook `order.paid` mit `ticket_count` 0 bzw. N). **Maßnahme:** Einheitliche Reihenfolge; eigener Hook `wpcap_tickets_issued`; in HOOKS.md dokumentieren.
- [ ] [P2][BESTÄTIGT] `src/Series/Pools.php:126–208`; `src/Passes/Capacity.php:44–238`; `src/Partners/Sales.php:173–200` — Serienpools, Pässe und Partnerkontingente summieren weiter über `order_items`/`reservations` statt über Zähler; Availability-Filter je Ticketart mit eigenen Abfragen (mit Modulen: 3 Termine 28, 12 Termine 100, 48 Termine 388 Abfragen; ohne Module konstant 8). **Mögliche Auswirkung:** Große Vorverkäufe mit Pässen/Pools/Wiederverkäufern serialisieren wieder; Eventliste wird mit jedem Termin langsamer. **Maßnahme:** Gepflegte Zähler in den Modulen, Batch-Filter `wpcap_ticket_availability_many`, Test auf konstante Abfragezahl.
- [ ] [P2][BESTÄTIGT] `src/CapacityCounters.php:441–480` — `reconcile()` alle 5 Minuten ohne Lock und Zeitbudget über den gesamten Bestand; Fenster zwischen `COMMIT` und `sync_order_now()` erzeugt Fehlalarme. **Maßnahme:** Auf künftige/kürzlich geänderte Termine beschränken, `GET_LOCK`, Alarm erst nach bestätigter zweiter Prüfung.
- [ ] [P3][BESTÄTIGT] `src/Admin/EventsPage.php:191,291` — Kontingenterhöhung im Admin feuert kein `wpcap_capacity_increased`; Wartelisten-Angebote erst nach stündlichem Sweep. **Maßnahme:** Hook nach Erhöhung auslösen.
- [ ] [P3][BESTÄTIGT] `src/Plugin.php:134` — Deaktivierung meldet `wpcap_webhooks_dispatch` (und Fortsetzungsläufe aus `Jobs/TimeBudget`) nicht ab; `Waitlists::run_due` läuft außerhalb des Zeitbudgets. **Maßnahme:** Abzumeldende Hooks aus zentraler Konstante; `run_due` mit `TimeBudget`.
- [ ] [P3][BESTÄTIGT] `src/Modules/BoxOffice.php:29–70`; `src/BoxOffice/Registers.php:148–158` — Kassen-Migration mit eigener Versionsoption auf `init` ohne Upgrade-Lock, offene Schichten außerhalb einer Transaktion. **Mögliche Auswirkung:** Doppelte „Kasse N (übernommen)“; `SHOW COLUMNS` bei jedem Request bis zum Upgrade. **Maßnahme:** In `Installer::install()` unter Lock ziehen.
- [ ] [P3][BESTÄTIGT] `src/Exchange/Service.php:525`; `src/Allocations/Holds.php:122`; `src/Passes/Capacity.php:183` — `db_busy` uneinheitlich (409 ohne `retryable`, 503 ohne `retryable`). **Maßnahme:** Gemeinsame Fehlerfabrik `Errors::busy()` (503, `retryable=true`).
- [ ] [P3][BESTÄTIGT] `docs/HOOKS.md` — Etwa 70 von 145 Hooks undokumentiert (22 nirgends), Dokument nennt Nicht-Hooks (`wpcap_jobs`, `wpcap_message_queue`). **Maßnahme:** Aus dem Code erzeugen bzw. per CI abgleichen.

Verdachtsfälle:
- [ ] [VERDACHT] `src/Exchange/Service.php:949–993` — Sperrreihenfolge in `finalize` (Typen/Termine → Zusatzbestellung) umgekehrt zu `sync_order_now()` (Bestellung → Typen); Deadlock bei gleichzeitiger Stornierung denkbar.
- [ ] [VERDACHT] `src/Orders.php:524–557` — `capacity_error_counted` zieht nur `active`-Holds ab; bereits `expired`, aber nicht synchronisierte Holds blockieren im Grenzfall die eigene späte Zahlung.
- [ ] [VERDACHT] `src/Partners/Statement.php` vs. `src/Partners/Settlements.php` — zwei Abrechnungswege mit eigener Zuordnung; Verrechnung bei selbst verkaufenden Veranstaltern nur manuell.
- [ ] [VERDACHT] Datenschutz-Löschung widerruft Tickets ohne Kontingentfreigabe (anders als `Orders::revoke_tickets`).
- [ ] [VERDACHT] `Modules::register_all` — Module nicht einzeln abschaltbar; Filterlast auch ohne Nutzung.

Fehlende Tests: Fault-Injection `db_busy`/Deadlock in Modul-Filtern (späte Zahlung, Umtausch, `Refunds::execute`); modulübergreifende Nebenläufigkeit (Pass + Hold + Partnerkontingent + Käuferlimit gegen Umtausch); parallele Wartelisten-Annahmen und Gästelisten-Quote; Abfrage-Obergrenze mit allen Modulen; Upgrade-Pfad großer Bestand; Gutschein-Vertragstest `treatment`.

### Betreiberpflichten (für docs/OPERATIONS.md)

- [ ] Art.-14-Information für Gästelisten; Abholliste nach dem Event vernichten.
- [ ] Rolle der Partner festlegen (eigener Verantwortlicher oder Auftragsverarbeitung) und dokumentieren.
- [ ] Kassen-Schichtdaten: Mitbestimmung (§ 96/96a ArbVG, § 87 Abs. 1 Nr. 6 BetrVG); Z-Berichte aufbewahrungspflichtig.
- [ ] AV-Vertrag mit dem Fiskalanbieter; manueller Auskunftsablauf für Bestellungen ohne E-Mail; Verarbeitungsverzeichnis erweitern.
