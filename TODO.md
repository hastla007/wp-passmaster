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

- [ ] Sitzplan-REST-Grundlage, versionierte Layouts und temporäre atomare Platz-Holds sind vorhanden; Admin-Editor, Checkout-Verknüpfung und Verkaufs-Commit fehlen.
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

- [ ] Käuferportal/Gastlinks und einmalige Ticketübertragung sind vorhanden; vollständige Selbstbedienung für Nachsendung und Teilnehmerdaten fehlt.
- [x] Einmalige Ticketübertragung vor Veranstaltungsbeginn mit Tokenrotation und Widerruf des alten Codes ist umgesetzt; ausbauen für konfigurierbare Fristen/Regeln.
- [ ] Eventabsage, Refunds und Widerruf haben Grundabläufe; Terminverschiebung und vollständige Status-/Sonderfallregeln, insbesondere Refund nach Check-in, sind offen.
- [ ] Idempotenz ist in vielen Bestell-/Zahlungspfaden vorhanden; vollständige End-to-End-Abnahme für Storno, Widerruf, Neuausgabe und alle Refundkombinationen fehlt.
- [ ] Teilnehmerfeld-Datenschema ist angelegt; Formular-Editor, Checkout-Erfassung, Export, Zugriffsschutz, Änderungsfristen und Löschregeln fehlen.
- [ ] E-Mail-Vorlagen und Eventabsage-Benachrichtigung sind vorhanden; Vorlagen/Benachrichtigungen für Verschiebung, Refund und Übertragung vervollständigen.

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
- [ ] Check-in-Korrekturen sind grundlegend protokollierbar; getrennte Check-out-Bedienung und vollständige Rollenprüfung/Abnahme ergänzen.
- [ ] Geräte-Token und Offline-Synchronisierung sind vorhanden; Zuweisung nach Einlassbereich und vollständige Admin-Konfliktansicht fehlen.
- [ ] Doppelte Einlösungen aus unabhängig offline arbeitenden Scannern erkennen, protokollieren und einer Admin-Klärung zuführen.
- [ ] POS-REST-API kann Verkäufe über gemeinsames Kontingent anlegen; Bedienoberfläche, Zahlungsarten, fiskalische Belege und Druckworkflow fehlen.
- [ ] Ticket-, Badge-, Namensschild- und Armbandvorlagen sowie Teilnehmerlisten-Druck/Export ergänzen.
- [ ] Kalender, E-Mail-Erinnerungen und Nachrichtengrundlagen sind vorhanden; vollständiger iCalendar-Download und Änderungsbenachrichtigungen pro Ereignis abnehmen.
- [ ] SMS-Einstellungen/Queue-Grundlage sind vorhanden; Anbieteradapter und vollständige Einwilligungs-, Kosten- und Zustellstatusabläufe fehlen.
- [ ] Weitere Zahlungsanbieter nach Zielland, Währung, Gebühren und Händlerverfügbarkeit priorisieren und über die Payment-Adapter anbinden.
- [x] Webhook- und Entwickler-Hook-Verträge sind versioniert dokumentiert; aktuelle Abdeckung ist in `docs/WEBHOOKS.md` und `docs/HOOKS.md` aufgeführt.
- [ ] Einzelne Integrations-/Lasttests für diese Flows existieren; vollständige End-to-End-Abnahmesuite einschließlich Offlinekonflikten, Slot-Kollisionen und realem POS fehlt.

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

## Experten-Review, zweite Runde (12.11.2026, Branch `claude/youthful-bardeen-7ejd7a`)

Fünf parallele Prüfungen (Sicherheit, Geld/Buchungen, Steuer/Recht/Datenschutz, WooCommerce/Zusammenspiel, Bedienung/Betrieb) gegen den Stand nach dem Merge von `main`. Jeder Befund wurde im Code nachvollzogen; Punkte mit [Entscheidung] brauchen Steuerberatung, Rechtsberatung oder eine Produktentscheidung. Bereits in den Abschnitten oben gelistete Befunde sind hier nicht wiederholt.

### Sofort behoben

- [x] Webscanner: 5xx-, Retry- und Nonce-Fehler erscheinen gelb „Systemfehler/Sitzung abgelaufen – erneut scannen“ statt „Kein Einlass“ (986caf3).

### Sicherheit

- [ ] Sicherheit: Inventar-Blockade durch anonyme Bestellungen mit manueller Zahlung (72 h Hold, 30 Bestellungen/h je IP) verhindern: kurzer Erst-Hold bis E-Mail-Bestätigung bzw. Zahlungsstart, Obergrenze offener Holds je Event, Bot-Schutz, Admin-Massenfreigabe.
- [ ] Warteliste: Eintrag erst nach Double-Opt-in aktiv (`Waitlists::join`), Missbrauchsgrenze je E-Mail.
- [ ] Portal-Nachsendung (`Orders::resend_tickets`) darf übertragene Tickets (`transfer_count > 0`) nicht rotieren.
- [ ] `rest_pre_serve_request`-Rohausgabe (WalletPasses/WebService.php) auf die eigenen Wallet-Routen beschränken.
- [ ] Fiskal-`HttpClient` nur HTTPS und keine privaten/Loopback-Ziele (außer ausdrücklich erlaubten lokalen Diensten).

### Geld, Buchungen und Nebenläufigkeit

- [ ] Geld (hoch): Provider-Refunds aus dem Stripe-/PayPal-Dashboard (Webhooks.php `refunded`) buchen nur `mark_refunded()` – keine Refund-Zeile, keine Rechnungskorrektur, kein Gutschein-Rückfluss, Partner wird voll ausbezahlt. Über `Refunds::record_external()` buchen (Schlüssel je Provider-Refund-ID, Teilbeträge); Test: Dashboard-Refund erzeugt genau eine Refund-Zeile.
- [ ] Geld (hoch): Partnerabrechnung bei Mehrzweckgutschein – Refund-Zeilen enthalten nur den Baranteil; mit `Checkout::credit_note_amounts()` (Bar + Gutschein) abrechnen. Test 2×50, Gutschein 60, Vollerstattung → Auszahlung 0.
- [ ] Geld: `Refunds::tax_for()` wendet Rabatt doppelt an (Zeilenbrutto vor, Steuer nach Rabatt); rabattierten Zeilenbrutto als Basis nehmen. Test 50-%-Kampagne, 1 von 2 Tickets → USt 3,99 statt 2,00.
- [ ] Geld (hoch): Fehlgeschlagene Gutschein-Rückbuchung (`Checkout::refund`) geht verloren – als Job mit gleichem Schlüssel wiederholen, offen markieren, Admin benachrichtigen.
- [ ] `voucher_snapshot` nur unter Zeilensperre bzw. mit Versionsprüfung ändern (commit/release/refund/refund_plan); Race „Ablauf vs. Spätzahlung“ testen.
- [ ] Kampagnennutzung bei Spätzahlung nach Hold-Ablauf erneut zählen bzw. bei erschöpftem Limit `needs_review`.
- [ ] Erstattungs-/Freigabe-Mails ohne fest codiertes `/100` (Währungen ohne Nachkommastellen).
- [ ] Abgleichbericht: VC-Einlösungen vs. PC-Snapshots (committed/released/refunded) und offene Gutschein-Rückbuchungen.

### Steuer, Recht und Datenschutz (DE/AT)

- [ ] Kasse: Bar/Karte für DE/AT sperren, solange kein Fiskalanbieter (TSE/RKSV) aktiv ist; die Fiskal-Adapter implementieren ein nicht vorhandenes `Fiscalization\AdapterInterface` (toter Code). Beleg im Vermittlermodell mit Veranstalter als Aussteller und Kleinunternehmer-Hinweis.
- [ ] Provisionsrechnung (Settlements.php:273): Plattform-Kleinunternehmer berücksichtigen (0 % USt + Hinweis, kein § 14c).
- [ ] Gutschrift: Reverse Charge nach Veranstaltungsort statt Sitz des Veranstalters ermitteln; Veranstaltungsland am Event speichern. [Entscheidung]
- [ ] Kleinunternehmer (Plattform/Veranstalter): 0 % schon beim Speichern der Ticketart bzw. im Checkout erzwingen, nicht erst bei der Rechnung (sonst bezahlte Bestellung ohne Rechnung).
- [ ] Rechnungskorrekturen je Steuersatz aufschlüsseln (Datenmodell, PDF, CSV) und Hinweise (Kleinunternehmer, Vermittler) wie auf der Rechnung zeigen.
- [ ] Rechnung nach Teilerstattung (auto_invoice aus): Erstattungen berücksichtigen bzw. Korrekturbelege nachträglich erzeugen.
- [ ] Rechnungsstellung erst bei vollständigen Plattform-Stammdaten (Name, Anschrift, St.-Nr./USt-IdNr.) erlauben.
- [ ] Checkout: Vertragspartner (Veranstalter im Vermittlermodell) anzeigen, Datenschutzlink, AGB-/Verbraucherinfo-Versionen archivieren und in der Bestellbestätigung mitsenden (§ 312f BGB).
- [ ] XRechnung/ZUGFeRD für B2B-Rechnungen in DE (Ausstellungspflicht ab 2027/2028).
- [ ] Ländersteuerprofile (`tax_profiles`, bisher nur geschrieben) tatsächlich anwenden; OSS- und ZM-Auswertung.
- [ ] Finanzbelege nach Fristende sperren bzw. löschen (DE seit 2025: 8 Jahre für Buchungsbelege, AT 7 Jahre); Text „10 Jahre § 147 AO“ in Privacy.php prüfen. [Entscheidung]
- [ ] Reverse Charge nur nach qualifizierter USt-IdNr.-Prüfung (BZSt/VIES) und mit eigener USt-IdNr. der Plattform.
- [ ] Rundung: Rabattverteilung je Steuersatz in Rechnung und Checkout vereinheitlichen; Kassenbeleg rechnet netto vom Brutto vor Rabatt; CSV nennt Erstattungen „Gutschrift“ (Präfix GS) – in DE der Selbstfakturierung vorbehalten.
- [ ] Einzweckgutschein: Steuersatz und Land des Verkaufs aus VC an PC übergeben; bei abweichendem Satz/Land als Mehrzweck behandeln; Rabattaktionen nicht mit „USt bereits beim Verkauf abgerechnet“ beschriften. [Entscheidung]

### WooCommerce und Plugin-Zusammenspiel

- [ ] Fähigkeit `wpcap_woocommerce_hold_fulfilment` versioniert anbieten und in VC prüfen (Hinweis, wenn PC zu alt – mit PC main werden Tickets trotz Fehlbetrag ausgestellt); E2E-Test Fehlbetrag mit beiden Plugins; CI-Matrix auf existierende PC-Refs inkl. Arbeits-/Release-Branch umstellen (der Ref `claude/woocommerce-ticket-plugin-8q4m8q` existiert nicht mehr).
- [ ] Eine einheitliche ISO-4217-Nachkommastellen-Tabelle (KWD/BHD/OMR = 3) für Forms, OrderAdapter, CartPricing und Rechnungen.

### Bedienung, Barrierefreiheit und Betrieb

- [ ] Admin „Käufer-E-Mail ändern“ und „Neuen Bestelllink senden“; öffentliches Formular „Tickets/Bestelllink anfordern“; abgelaufener Portallink zeigt Hilfeseite statt leerem 404.
- [ ] Event-/Terminabsage als Hintergrundjob mit Fortschritt und Wiederaufnahme; Sammel-Erstattung für abgesagte Termine.
- [ ] Abgebrochene Zahlung führt zurück zur Eventseite mit „Erneut bezahlen“ statt zur Startseite.
- [ ] Shortcode/Block `[wp_pass_captain_events]` mit Attribut `event`/`occurrence` für Eventseiten.
- [ ] Webscanner zeigt Teilnehmername (Einstellung „Teilnehmernamen anzeigen“) und bei „bereits verwendet“ Zeitpunkt/Eingang/Personal des Ersteinlasses.
- [ ] Rückgängig-Check-in nur mit Grund und eigener Berechtigung (Mobile/Api.php, TicketsPage.php); getrennte Rollen für Einlass/Einlösen/Rückgängig.
- [ ] Wartelisten-Anmeldung im Frontend (bisher nur REST); Ticket-PDF und .ics im Bestellportal; Storno-/Erstattungsanfrage durch Kunden.
- [ ] Teilnehmerformular im Portal für übertragene Tickets ausblenden; feste Datumsformate „d.m.Y H:i“ auf Website-Format umstellen; „Sie“-Reste in Kundentexten (Billing.php, Datenschutztext) auf „du“.
- [ ] Website-Zustand-Prüfungen (Cron, Action Scheduler, Mail) und Protokoll fehlgeschlagener Mails (`wp_mail_failed`).
