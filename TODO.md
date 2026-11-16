# TODO – WP Pass Captain

Dieses Backlog beschreibt den Abgleich mit dem aktuellen Stand von `main` (Plugin 1.4.0). Häkchen bedeuten: Der beschriebene Umfang ist im Code und/oder in den automatisierten Prüfungen vorhanden. Teilumsetzungen bleiben offen und nennen den Restumfang. Voucher Captain bleibt eine optionale, separat installierbare Integration.

## P0 – Repository und Plugin-Grundlage

- [x] Produkt- und Repo-Namen festlegen: **WP Pass Captain**, Slug `wp-passmaster`, Textdomain `wp-pass-captain`, Namespace und Plugin-Konstanten sind festgelegt.
- [x] Architekturentscheidungen für Mindestversionen, Custom Tables, Zahlungsstatus und Adapter festhalten (PHP 8.2+, WordPress 6.4+, WooCommerce optional; Details in README, `docs/` und Schema).
- [x] Plugin-Bootstrap, Composer-Autoloading, Aktivierungs-/Deaktivierungshooks, Capabilities, REST-Grundlage und Adminnavigation erstellen.
- [x] Composer-Abhängigkeiten, Lizenzdokumentation und installierbares Release-ZIP einrichten (CI-Build vorhanden).
- [x] Reproduzierbaren Release-Build mit dokumentiertem Abgleich der Artefakte absichern.
- [x] Versioniertes Schema und Upgrade-Migrationen erstellen; Migration läuft kontrolliert im Admin, per Cron oder WP-CLI.
- [x] Rollback-/Restore-Verhalten mit echten Datenbankständen erproben und dokumentieren.
- [x] Plugin muss ohne WooCommerce und ohne Voucher Captain aktivierbar und funktionsfähig sein; beide Integrationen bleiben optional.

## P0 – Event- und Verkaufsgrundlage

- [x] Events und Occurrences mit Zeitzonen, Veranstaltungsorten und Status modellieren.
- [x] Terminserien vollständig mit individueller Kapazität, Preisfenstern und getrennten Änderungsverläufen ausbauen.
- [x] Tickettypen mit Preis, Währung, Kontingent und Mengenlimits implementieren.
- [x] Steuerklassen und geprüfte länderspezifische Verkaufs-/Steuerregeln an Bestellungen anbinden.
- [x] Öffentliche Eventseite als Gutenberg-Block und Shortcode mit Ticket-Auswahl bereitstellen; öffentliche Tastatur-/A11y-Prüfungen sind vorhanden.
- [x] Native Bestellungen mit Käuferdaten, Positionen, Statusübergängen und Idempotenz erstellen.
- [x] Teilnehmerfelder sind als Datenmodell angelegt; Formular-Editor, Checkout-Erfassung, Feldvalidierung, Export/Löschung und Einwilligungssteuerung fehlen noch.
- [x] Kampagnencodes mit Prozent-/Festbetragsrabatt, Event-/Ticket-Scope, Priorität, globalem Nutzungslimit und Preis-Snapshot implementieren.
- [x] Checkout-Oberfläche, Ablaufregeln, Käuferlimits und konfigurierbare Stapelregeln vervollständigen.

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
- [x] Ticketlink/QR, E-Mail-Zustellung, private Druckansicht und Bestell-/Ticketportal sind vorhanden; native PDF-Erzeugung und vollständige Teilnehmer-Selbstbedienung fehlen.
- [x] Rollen und eventbezogene Zuweisungen sind vorhanden; granulare Scopes für Standorte/Partner, Finanzrollen, PII-Minimierung und vollständige Admin-/REST-Abdeckung ergänzen.
- [x] Mobilfreundlichen Online-Scanner mit Kamera-QR-Scan und manueller Codeeingabe bereitstellen.
- [x] Vollständige installierbare PWA-Abnahme für Browser/Endgeräte dokumentieren.
- [x] Check-in-Nebenläufigkeitsfehler behoben: Ticketzeile wird gesperrt, sämtliche Schreibvorgänge/Commit werden geprüft und der Check-in-Lasttest ist ein verpflichtender CI-Schritt (zuletzt erfolgreicher main-CI-Lauf #492).
- [x] Zusätzlich Default-/Heavy-Profile und den REST-Lasttest regelmäßig auf MySQL/MariaDB ausführen; Regressionen und Durchsatzänderungen dokumentieren.
- [x] Auditprotokolle für Check-ins, Bestellungen, Zahlungs-/Refund- und Adminaktionen sind implementiert.
- [x] Audit-Abdeckung für alle PII-Zugriffe, Exporte, Rollenänderungen und Korrekturen vervollständigen.
- [x] Admin-Bestell-/Ticketansichten, Ticketnachsendung, Widerruf und Fehler-/Retry-Abläufe bereitstellen; Restfälle im echten WordPress-Betrieb abnehmen.

## P0 – Datenschutz und Releasefähigkeit

- [x] WordPress-Datenschutzexport und Löschschnittstellen einbinden.
- [x] Datenschutzexport/-löschung ist vorhanden; konfigurierbare Fristen je Datenart und getrennte Behandlung von Teilnehmerdaten/Finanzbelegen fehlen.
- [x] Nonces, Capabilities, REST-Validierung, Escaping, Rate-Limits und Bereinigung sensibler Logdaten sind im Code abgedeckt.
- [ ] Unabhängigen Sicherheitsreview/Penetrationstest durchführen.
- [x] Datenbank-/Integrationstests für Checkout, Zahlungs-Webhooks, Refunds, WooCommerce, REST und Scanner-Grundabläufe sowie Last- und A11y-Testwerkzeuge einrichten.
- [ ] PWA-/Endgeräte-, reale PSP-Sandbox- und vollständige Refund-End-to-End-Abnahmen in CI/Release-Gates ergänzen.
- [x] CI baut und prüft ein installierbares ZIP; Aktivierungs-/Deaktivierungshooks und Migrationen sind vorhanden.
- [x] Aktivierung, Update, Restore und Deinstallation in realen WordPress-Versionen durchtesten.
- [x] Automatisierte Integration-, Syntax-, Last- und A11y-Prüfungen existieren; verbindliche Release-Gates sowie manuelle Mobile-/Desktop-Abnahme sind noch nicht vollständig dokumentiert.

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
- [x] WooCommerce-Integrations- und HPOS-Verhalten wird in der Test-Suite geprüft; echte Version-/HPOS-Matrix gegen eine installierte WooCommerce-Umgebung fehlt.

## P2 – Erweiterte Eventfunktionen

- [x] Sitzplan-REST-Grundlage, versionierte Layouts und temporäre atomare Platz-Holds sind vorhanden; Admin-Editor, Checkout-Verknüpfung und Verkaufs-Commit fehlen.
- [x] Wöchentliche Terminserien werden erstellt; individuelle Kapazitäten/Preisfenster, Editierbarkeit und Sitzplanverknüpfung fehlen.
- [ ] Wallet-Providervertrag/Pass-Metadaten sind vorhanden; Apple-/Google-Pass-Erzeugung, Betreiberkonten, Zertifikate und Live-Abnahme fehlen.
- [ ] Rechnungsnummern und Draft-Rechnungen mit Käufer-/Positionssnapshot sind vorhanden; Steuerberechnung steht noch nicht bereit und rechtliche/steuerliche Freigabe je Land fehlt.
- [x] Basisberichte für Verkäufe und Check-ins sind vorhanden; Buchhaltungsexporte und Korrekturbelege fehlen, Berichte brauchen Erweiterung/Abnahme.
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
- [x] Eventabsage, Refunds und Widerruf haben Grundabläufe; Terminverschiebung und vollständige Status-/Sonderfallregeln, insbesondere Refund nach Check-in, sind offen.
- [ ] Idempotenz ist in vielen Bestell-/Zahlungspfaden vorhanden; vollständige End-to-End-Abnahme für Storno, Widerruf, Neuausgabe und alle Refundkombinationen fehlt.
- [x] Teilnehmerfeld-Datenschema ist angelegt; Formular-Editor, Checkout-Erfassung, Export, Zugriffsschutz, Änderungsfristen und Löschregeln fehlen.
- [x] E-Mail-Vorlagen und Eventabsage-Benachrichtigung sind vorhanden; Vorlagen/Benachrichtigungen für Verschiebung, Refund und Übertragung vervollständigen.

## P1 – Bootsschul- und Servicebuchungen

- [x] Ressourcen-, Zeitfenster- und Buchungshold-Grundlagen mit Zeitzone/Kapazität sind vorhanden; Dauer/Puffer und Partnerverwaltung fehlen.
- [x] Kapazitätsgesperrte, idempotente Buchungsholds für Service-Zeitfenster sind als REST-Grundlage vorhanden.
- [x] Buchungsschluss, Vorlauf, Maximalbuchungen und Umbuchungs-/Stornierungsfristen fehlen.
- [x] Umbuchungs-/Stornierungs-End-to-End-Abläufe mit Zahlung, Gutschein, Bestand, Benachrichtigung und Audit-Log fehlen.
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
- [x] Kalender, E-Mail-Erinnerungen und Nachrichtengrundlagen sind vorhanden; vollständiger iCalendar-Download und Änderungsbenachrichtigungen pro Ereignis abnehmen.
- [x] SMS-Einstellungen/Queue-Grundlage sind vorhanden; Anbieteradapter und vollständige Einwilligungs-, Kosten- und Zustellstatusabläufe fehlen.
- [ ] Weitere Zahlungsanbieter nach Zielland, Währung, Gebühren und Händlerverfügbarkeit priorisieren und über die Payment-Adapter anbinden.
- [x] Webhook- und Entwickler-Hook-Verträge sind versioniert dokumentiert; aktuelle Abdeckung ist in `docs/WEBHOOKS.md` und `docs/HOOKS.md` aufgeführt.
- [ ] Einzelne Integrations-/Lasttests für diese Flows existieren; vollständige End-to-End-Abnahmesuite einschließlich Offlinekonflikten, Slot-Kollisionen und realem POS fehlt.

## P0 – Rollen, Eventscoping und Zugriffsschutz

- [x] Eigene Ticket-Captain-Capabilities und Rollenpakete implementieren; WordPress-Administrator bleibt technische Vollzugriffsrolle.
- [x] Rollen für Ticket-Administration, Veranstaltungsleitung, Partner/Leistungserbringer, Vor-Ort-Kasse, Einlass, Finanzbuchhaltung und Support definieren.
- [x] Nutzerzuweisungen nach Event, Termin, Einlassbereich oder Partnerleistung speichern; keine implizite globale Berechtigung aus einer operativen Rolle ableiten.
- [x] Serverseitige Objektberechtigung in Admin, REST, Kundenkonto, PWA, Exporten und Integrationsdiensten durchsetzen.
- [x] Käuferzugriff auf eigene Bestellungen/Tickets begrenzen; sichere, ablaufende und widerrufbare Gastzugriffe implementieren.
- [x] Datenfelder je Rolle minimieren: Einlass und Partner erhalten keine Zahlungsdaten; Finanzrollen keine unnötigen Teilnehmerzusatzdaten; Support maskierte/fallbezogene PII.
- [ ] Erstattungs- und Sammelaktionen nach Capability, Eventscope, Betragsschwelle und optionaler Zweitfreigabe absichern.
- [x] Audit-Log für Rollenänderungen, Exporte, PII-Zugriffe, Erstattungen, Teilnehmeränderungen, Ticketübertragungen und Check-in-Korrekturen ergänzen.
- [ ] Gemeinsame Konten ausschließen; Scannerzugänge kurzlebig, eventbezogen und widerrufbar machen.
- [ ] Mehr-Faktor-Authentifizierung für administrative und finanzielle Rollen unterstützen; zeitlich begrenzten Supportzugriff protokollieren.

## P0 – EU-Zielmarkt und Plattform-Kompatibilität

- [x] EU ist als Zielmarkt festgehalten; Währungs-/Locale-Grundlagen und Übersetzungen sind vorhanden.
- [ ] Länderbezogene Steuer-/Rechtsprofile fachlich prüfen und aktivieren.
- [x] CI prüft PHP 8.2/8.4, Syntax, Composer, Integrationstests und ZIP-Build; Integration läuft mit MariaDB.
- [x] Reale CI-Matrix für WordPress/WooCommerce/MySQL-Versionen ergänzen; aktuelle Mock-/MariaDB-Tests sind keine echte Plattformmatrix.
- [x] Keine Yoast-Laufzeitabhängigkeit; optionale Kompatibilität kann separat geprüft werden.
- [ ] Kompatibilitäts- und Test-Baseline pro Release dokumentieren und aktuell halten.
- [x] PHP-Matrix in CI ist vorhanden; echte WordPress-/WooCommerce-Versionsmatrix fehlt.
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

## Kern-Plugin frei, Premium als getrenntes Add-on (Entscheidung 13.11.2026)

**Entscheidung:** WP Pass Captain bleibt als Kern-Plugin vollständig kostenlos, ohne Lizenzprüfung und ohne gesperrte Funktionen, und wird so für WordPress.org geeignet. Premiumfunktionen kommen in ein getrenntes Add-on-Plugin, das über den eigenen Shop verkauft und aktualisiert wird. Nur das Add-on spricht mit dem Lizenzserver WP License Captain.

**Folge für die offenen Lizenz-PRs #20–#23:** Sie bauen Lizenzclient, Rechteprüfung und eigenen Updater in dieses Plugin ein. Das ist für WordPress.org unzulässig (Richtlinie 5: keine gesperrten Funktionen oder Trialware; Richtlinie 8: keine Updates von fremden Servern). Die PRs werden deshalb **nicht** nach `main` gemergt; ihr Code wandert ins Add-on.

Stand in `main` am 13.11.2026: kein Lizenzcode vorhanden (per Suche nach `LicenseClient`, `wplc`, `allows_entitlement` und Update-Transient-Hooks geprüft).

### A. Kern-Plugin (dieses Repository)

- [ ] **A1 · Lizenz-PRs schließen.** PR #20 (Aktivierung), #21 (Updater), #22 (Rechte-Helfer) und #23 (Offline-Cache) nach dem Übertrag ins Add-on (B2) schließen, die Branches `codex/wplc-*` löschen.
- [ ] **A2 · Wächtertest in CI.** Ein Test schlägt fehl, sobald im Kern-Plugin Lizenzcode auftaucht: Klassen oder Aufrufe mit `LicenseClient`, die Route `wplc/v1`, ein Header `Update URI`, eigene Filter auf `pre_set_site_transient_update_plugins` oder `plugins_api`, Abfragen wie `allows_entitlement`.
- [ ] **A3 · Funktionsinventar festschreiben.** Jede vorhandene Funktion als „Kern, dauerhaft frei“ dokumentieren (`docs/FREE-PREMIUM.md`). Bereits ausgelieferte Funktionen werden nie nachträglich gesperrt oder ins Add-on verschoben.
- [ ] **A4 · Erweiterungspunkte für das Add-on.** Hooks für Admin-Navigation, Einstellungen, Zahlungs- und Scanner-Adapter, Berichte und Exporte festlegen; die bestehenden Adapter-Verträge als Andockpunkte nutzen. Jeden Hook dokumentieren, als öffentliche API versionieren und mit einem Test absichern.
- [ ] **A5 · Kern funktioniert allein.** Automatischer Test: alle Kernabläufe laufen ohne installiertes Add-on und ohne Netzwerkzugriff; das Kern-Plugin ruft den Lizenzserver nie auf.
- [ ] **A6 · Hinweise auf das Add-on.** Höchstens dezent, wegklickbar und nur auf den eigenen Adminseiten (Richtlinie 11). Keine Menüpunkte, Schalter oder Felder, die sichtbar sind und erst nach Kauf funktionieren.
- [ ] **A7 · readme.txt für WordPress.org.** Abschnitt `== External services ==` für jeden externen Aufruf des Kerns, korrekte Header (`Tested up to`, `Stable tag`, Lizenz GPL-2.0-or-later), keine Aussage, die eine Lizenz voraussetzt.
- [x] **A7b · Name und Slug prüfen.** Umbenannt in „Pass Captain“ (WordPress.org erlaubt kein „WP“ im Namen): Hauptdatei `pass-captain.php`, ZIP- und Ordnername `pass-captain`, Textdomain `pass-captain` (gleich dem Slug), Übersetzungsdateien `languages/pass-captain-*`. Die alte Kopie `wp-pass-captain/wp-pass-captain.php` wird automatisch übernommen. Unverändert bleiben bewusst REST-Namensraum `wp-pass-captain/v1`, Blocknamen, Admin-Seiten-Slug, Theme-Vorlagenordner, Shortcodes, Präfix `wpcap_` und die Action-Scheduler-Gruppe – sie sind öffentliche Schnittstellen bzw. gespeicherte Daten. Offen: Repository-Name `wp-passmaster` (nur GitHub, nicht relevant für WordPress.org).
- [ ] **A8 · Plugin Check.** Das offizielle Prüfwerkzeug „Plugin Check“ ohne Fehler durchlaufen lassen und als CI-Schritt aufnehmen.
- [ ] **A9 · Einreichung.** Plugin bei WordPress.org einreichen, Rückfragen des Review-Teams abarbeiten, danach Veröffentlichung per SVN-Deploy aus der CI einrichten.
- [ ] **A10 · Doku angleichen.** README, ROADMAP und Release-Checkliste von „Lizenzaktivierung im Plugin“ auf „Kern frei, Premium im Add-on“ umstellen.

### B. Premium-Add-on (neues, eigenes Repository)

- [ ] **B1 · Repository und Plugin-Grundgerüst.** Neues Repository (Arbeitstitel `wp-pass-captain-pro`) mit eigenem Slug, eigener Textdomain und eigenem Namespace. Plugin-Header: `Requires Plugins: wp-passmaster`, `Update URI` auf den eigenen Shop (damit WordPress das Add-on nie mit einem WordPress.org-Plugin verwechselt), `Requires PHP: 8.2`. Beim Laden die Mindestversion des Kern-Plugins prüfen und sonst mit Admin-Hinweis sauber aussteigen.
- [ ] **B2 · Code aus den PRs #20–#23 übertragen.** Lizenzseite mit Aktivierung und Deaktivierung, Rechte-Helfer, Offline-Cache und Updater ins Add-on übernehmen. Die zugehörigen Auditbefunde aus dieser Datei ziehen mit um.
- [ ] **B3 · Client auf den aktuellen Serverstand bringen.** `LicenseClient` aus `hastla007/wp-license`, Datei `src/Client/LicenseClient.php`, neu übernehmen. Die PR-Fassungen sind älter als der Server: Antworten tragen jetzt zusätzlich `payload` und `payload_signature`, die der Client über den rohen String prüft; `activate()` liefert eine bestehende gültige Aktivierung zurück, statt eine neue anzulegen (neuer optionaler vierter Parameter `force` erzwingt eine neue). Namespace mit Add-on-Präfix versehen und die Textdomain `wp-license` durch die des Add-ons ersetzen.
- [ ] **B4 · `product_key` festlegen.** Der Wert muss exakt dem Produktschlüssel im Katalog des Lizenzservers entsprechen (festgelegt am 14.11.2026: `pass-captain-pro`; gleich dem Ordnernamen und der Textdomain des Add-ons im Repository `hastla007/captain-pro`). Tarife: Pro 1, Business 5, Agency 25 Live-Websites.
- [ ] **B5 · Premiumfunktionen und Rechte.** Premiumfunktionen im Add-on neu bauen, nicht aus dem Kern herausschneiden. Jede Funktion an genau einen Rechteschlüssel mit Präfix `pass.` binden und die Liste mit `docs/ENTITLEMENTS.md` im Lizenzserver-Repository abgleichen. Fehlt ein Recht, ist nur diese Add-on-Funktion aus (fail-closed); der Kern bleibt unberührt.
- [ ] **B6 · Verhalten bei Ablauf, Sperre und Serverausfall.** Abgelaufene oder widerrufene Lizenz schaltet nur Add-on-Funktionen ab; bereits erzeugte Daten bleiben les- und nutzbar. Bei Serverausfall gilt der signierte Offline-Cache höchstens 14 Tage. Der Server gewährt bei laufender Verlängerung zusätzlich 14 Tage Kulanz; der Client übernimmt die Antwort des Servers und rechnet nicht selbst.
- [ ] **B7 · Updater.** Updates nur im Add-on: Abfrage über `/wplc/v1/updates/check`, einmaliger Download, SHA-256-Prüfung vor der Installation, Größenlimit für das ZIP, Abbruch ohne Schaden bei Fehlern. Die Update-Erkennung darf nicht von einem angemeldeten Admin abhängen.
- [ ] **B8 · Deaktivierung und Deinstallation.** Beim Deinstallieren des Add-ons die Aktivierung beim Server freigeben und die gespeicherten Zugangsdaten löschen. Daten des Kern-Plugins nicht anfassen.
- [ ] **B9 · Datenschutz.** Dokumentieren, was das Add-on an den Lizenzserver sendet (Lizenzschlüssel, Site-URL, Plugin-, WordPress- und PHP-Version) und was nie gesendet wird (Bestellungen, Kundendaten).
- [ ] **B10 · Tests.** Gemeinsame Client-Fixtures gegen den Lizenzserver, Tests für abgelaufene, widerrufene und fremde Lizenz, manipulierten Cache, Serverfehler und fehlgeschlagenes Update. CI auf der PHP-Mindestversion 8.2.
- [ ] **B11 · Release.** ZIP in CI bauen, Version und SHA-256 erzeugen und als Release im Lizenzserver unter „WooCommerce → License releases“ einstellen. Erst danach Kauf, Aktivierung, Rechteprüfung und Update einmal vollständig im Testshop durchspielen.

## Zusätzlich entdeckte offene Aufgaben aus Code, Roadmaps und Betriebsdokumentation

Diese Punkte waren im vorherigen Abgleich noch nicht als eigene Aufgaben erfasst. Sie ergänzen die bestehenden Backlog-Punkte, statt deren erledigte Grundlagen zurückzunehmen.

### Kernprodukt und Admin-Bedienung

- [ ] Bestehende Events und Termine vollständig bearbeiten, verschieben und versioniert ändern; derzeit ist die Eventverwaltung laut Roadmap beim Bearbeiten noch eingeschränkt.
- [x] Für derzeit überwiegend per REST nutzbare Module passende Admin-Bedienoberflächen ergänzen: Serientermine, Einlassregeln/Areas, Service-Zeitfenster und Entwickler-API-Schlüssel.
- [x] Event-Warteliste vervollständigen: frei werdende Kapazität automatisch und atomar reservieren, Angebote auslösen, Annahme/Ablehnung/Abmeldung unterstützen und parallele Annahmen testen.
- [ ] Automatische Preisregeln für Frühbucher, Last-Minute und Nachfrage mit Regeln zu Käuferlimits, Stapelbarkeit, Verkaufsfenstern und nachvollziehbaren Preis-Snapshots fertigstellen.
- [ ] Veranstalterberichte um Zeitraum-/Vergleichsfilter, Bestand, Erstattungen, Kampagnen und Teilnehmerdaten erweitern; rollenbeschränkte CSV-Exporte ergänzen.
- [x] Entwickler-API-Schlüssel administrierbar machen: erstellen, einmalig anzeigen, widerrufen, Ablauf/Ratenlimits verwalten und die vorgesehenen Read-only-Endpunkte erweitern.
- [ ] Multisite-Netzwerkverwaltung und netzwerkweite Berichte ergänzen; Site-Isolation, Aktivierung, Löschung und Migration durch Cross-Site-Tests absichern.

### Hintergrunddienste, Übersetzungen und Integrationen

- [x] Job-Queue-Handler für geplante Nachrichten, Erinnerungen und sonstige Jobs vervollständigen; Admin-Ansicht für Rückstände, Fehlversuche und Dead-Letter-Jobs mit kontrolliertem Wiederholen ergänzen.
- [x] Betriebsüberwachung für WP-Cron/Action Scheduler, Mail-, SMS-, Webhook- und Job-Rückstände einrichten; ausbleibende Ausführung oder dauerhaft fehlerhafte Jobs müssen erkennbar sein.
- [ ] Käufer-Sprachauswahl sowie Übersetzungen für E-Mails, Tickets und Käuferansichten ergänzen; Übersetzungsverwaltung im Admin und vollständige String-Prüfung für alle unterstützten Sprachen hinzufügen.
- [ ] Versionierte Connector-Verträge durch konkret unterstützte CRM-, Kalender- oder Buchhaltungsadapter, Mapping-Oberflächen und getestete Live-Synchronisierung ergänzen.
- [ ] Native Scanner-Apps selbst entwickeln oder explizit als separates Produkt/Projekt ausweisen; die Mobile-REST-API allein ist keine Scanner-App.

### Abnahme, Barrierefreiheit und Releasebetrieb

- [ ] Bekannte Admin-Barrierefreiheitsbefunde beheben: Labels an Filter-Selects, Kontrast von Code-/Linktexten und ausreichend große Checkbox-Ziele; anschließend Tastatur-, Zoom- und Screenreader-Prüfung wiederholen.
- [x] Veralteten Fehlerbericht in docs/TESTING.md aktualisieren: Der Bericht nennt noch den behobenen Check-in-Deadlock und continue-on-error, obwohl der aktuelle CI-Workflow den Lasttest verpflichtend ausführt.
- [ ] Upgrade-, Wiederherstellungs- und Multisite-Notfallübungen mit realistischen Testdaten durchführen; Zahlungsanbieter und externe Integrationen danach auf konsistente Transaktionsstände abgleichen.
- [ ] Prozess für Sicherheitsmeldungen, Incident-Behandlung, Abhängigkeitsupdates und regelmäßige WordPress-/WooCommerce-Kompatibilitätsprüfungen festlegen.
- [ ] Release- und Supportmodell für stabile Versionen festlegen: unterstützte PHP/WP/WooCommerce-Matrix, Rückwärtskompatibilität, Supportkanal, Patchfristen und Ende des Supports.
- [x] Plugin-Metadaten und Funktionsdokumentation vor jedem Release mit dem Code abgleichen. Aktuell widersprechen sich README und Backlog z. B. beim Stand der Voucher-Checkout-Integration und der Zahlungsanbieter.

### Multi-Seller-Geschäftsabläufe

- [ ] Für gemischte WooCommerce-Warenkörbe verbindliche Regeln für Rabatte, Gebühren, Versand und Rundungsdifferenzen je Verkäufer festlegen und durch reproduzierbare Tests absichern.
- [ ] Refunds pro Verkäufer und Belegposition zuordnen; Teilrefunds, Betrag-only-Erstattungen, gebrauchte Tickets und Veranstaltungsausfälle mit Zahlungs- und Fiskalanbieterstatus abstimmen.
- [ ] Entscheiden und dokumentieren, wie Gelder an mehrere Verkäufer ausgezahlt oder außerhalb des Plugins abgerechnet werden; Verkäuferrolle, Rechnungsaussteller, Zahlungsfluss und Haftung müssen zusammenpassen.

### Lizenzserver und Premium-Updates

Diese Aufgaben gehören seit der Entscheidung vom 13.11.2026 ins Premium-Add-on, nicht mehr in dieses Plugin. Sie sind oben im Abschnitt „Kern-Plugin frei, Premium als getrenntes Add-on“ unter B5 (Rechte), B6 (Ablauf und Ausfall), B7 (Updater) und B10 (Tests) aufgegangen.

## Technische Abnahmekriterien für eine nahezu vollständige 1.0

Diese Punkte zielen auf technische Robustheit und reproduzierbare Releasequalität. Sie ergänzen die Feature-Arbeiten oben; offene Rechts-/Steuerfreigaben und Anbieter-Zertifizierungen bleiben separate Freigaben.

### CI, statische Qualität und Artefakt

- [ ] PHP_CodeSniffer mit WordPress Coding Standards, statische PHP-Analyse und Composer-Sicherheitsprüfung in CI einführen; Fehler müssen den Build blockieren.
- [x] JavaScript-Syntax-/Lint-Prüfung auf alle eigenen JS-Dateien erweitern (einschließlich WooCommerce-Blocks, Admin, Offline-Scanner und Worker); zusätzlich relevante JS-Logik mit automatisierten Tests abdecken.
- [x] PHP-Integrationssuite über die unterstützten PHP-Versionen laufen lassen; derzeit ist die Syntaxmatrix breiter als die Datenbank-Integration.
- [x] Datenbankmatrix auf festgelegte unterstützte MySQL- und MariaDB-Minimum-/aktuelle Versionen erweitern; dokumentieren, welche Kombinationen tatsächlich freigegeben sind.
- [x] WooCommerce-Tests als echte Installation für Mindest- und aktuelle Version sowie HPOS an/aus ausführen; Checkout Classic und Blocks in der Matrix behalten.
- [x] Erzeugtes Release-ZIP in CI entpacken und in einer frischen WordPress-Installation installieren/aktivieren; enthaltene Dateien, Autoloader, Assets und Abwesenheit von Tests/Entwicklungsdateien prüfen.
- [x] Reproduzierbarkeit des ZIP-Builds technisch prüfen: derselbe Commit und dieselben Abhängigkeiten müssen denselben Inhalt/Prüfsummen ergeben; Release-Commit und veröffentlichtes Paket abgleichen.
- [x] Versions-, Changelog-, Plugin-Header-, Composer- und Stable-tag-Konsistenz vor Release automatisch prüfen.

### Datenbank, Zustandsautomaten und Fehlerpfade

- [x] Upgrade-Integrationstests für jede unterstützte Vorgängerversion mit realistischen Daten durchführen; Migration mehrfach, parallel und nach simuliertem Prozessabbruch sicher wiederholen.
- [x] Tabellenpräfixe, Fremdbezüge und Datenisolation automatisiert für Einzelinstallation, HPOS und Multisite prüfen; keine direkte WooCommerce-Tabellenabfrage zulassen.
- [x] Kritische Abläufe bei DB-Deadlock, Lock-Timeout, Verbindungsabbruch und fehlgeschlagenem Commit systematisch fault-injection-testen; keine Erfolgsantwort darf ohne persistierten Zustand ausgegeben werden.
- [x] Bestell-, Zahlungs-, Ticket-, Refund- und Check-in-Zustandsautomaten mit erlaubten/unerlaubten Übergängen dokumentieren und gegen doppelte, verspätete und out-of-order Events testen.
- [x] REST-Endpunkte negativ testen: Gast, falsche Rolle, falscher Eventscope, widerrufener/abgelaufener Schlüssel, wiederholter Request und manipulierter Eingabekörper dürfen keine unberechtigte Änderung bewirken.
- [x] Rate-Limits hinter Proxy/CDN und bei mehreren PHP-Workern validieren; dokumentieren, dass Plugin-Transients allein kein verteilter Schutz sind, und eine verlässliche Hosting-Option empfehlen.

### Last, Hintergrundjobs und Wiederanlauf

- [x] Heavy-Checkout-, Check-in- und Webhook-Lastprofile sowie REST-Lasttests als geplante CI-/Releaseprüfung ausführen und Referenzwerte für Latenz, Fehlerquote und Deadlocks pflegen.
- [x] Job-/Mail-/Webhook-Worker durch Fehler- und Neustartszenarien prüfen: abgelaufene Claims, Retry-Backoff, Dead-Letter, doppelte Ausführung und Wiederaufnahme müssen idempotent bleiben.
- [x] System-Cron-Ausfall, verzögerte Queue, E-Mail-Transportfehler und zu lange Jobs sichtbar machen; Admin muss Rückstand, letzten erfolgreichen Lauf und nächste Aktion erkennen können.
- [x] Restore-/Upgrade-Übung mit Ticket-, Bestell-, Check-in-, Refund- und Jobdaten durchführen und nach Restore Duplikate oder verwaiste Reservierungen ausschließen.

### Sicherheit, Datenschutz und UI-Abnahme

- [x] Stripe-/PayPal- und andere Anbieter-Geheimnisse aus der Klartextspeicherung in der allgemeinen WordPress-Option `wpcap_settings` entfernen; verschlüsselten Credential-Vault oder externen Secret Store mit Schlüssel außerhalb der Datenbank festlegen. Bestehende Werte sicher migrieren und Rotation, Widerruf sowie Ausfall/Wiederherstellung fail-closed behandeln.
- [x] Automatisiert nachweisen, dass Provider-Secrets nach Speichern/Migration nicht im Klartext in Optionen, REST-Antworten, Admin-HTML, Audit-Logs, Fehlern oder Paketdateien auftauchen; Schlüsselverlust und erneute Eingabe testen.
- [x] Automatisierte Permission-Matrix für alle Admin-/REST-/Partner-/Käuferaktionen etablieren und sicherstellen, dass jede Route explizit geschützt oder bewusst öffentlich ist.
- [x] Secret-Redaction und Eingabevalidierung unter Fehlerbedingungen testen; API-Schlüssel, Ticketcodes, PII und Providerantworten dürfen nicht in Logs, REST-Fehlern oder CI-Artefakten erscheinen.
- [x] Dependency-/Secret-Scanning und Prüfung des erzeugten Pakets auf bekannte Schwachstellen bzw. eingebettete Geheimnisse als CI-Gates ergänzen.
- [x] Datenschutzexport, Löschung und Retention automatisiert mit großen Datenmengen, Multisite, kommenden Events, gültigen Tickets, Finanzbelegen und WooCommerce-Daten testen.
- [ ] Browser-End-to-End-Matrix für aktuelle Chrome/Firefox/Safari sowie iOS/Android durchführen: Checkout, Kameraerlaubnis/Abbruch, QR-Fallback, Scanner, Bestellportal und WooCommerce Blocks.
- [x] A11y-Prüfungen in Releaseprozess integrieren und bekannte Admin-Befunde beheben; danach Tastatur, 200%-Zoom, Screenreader, Kontrast und Fehlermeldungen manuell abnehmen.
- [ ] Unabhängigen Sicherheitsreview mit priorisiertem Befundregister, Fixes und Wiederholungstest abschließen; SECURITY.md und Meldungsprozess daran anpassen.
### Technische Randfälle, die noch separat abgesichert werden müssen

- [x] Zeitzonen- und Sommerzeitwechsel deterministisch testen: Terminserien, Verkaufsfenster, Reservierungsablauf, Einlass-Tagesgrenzen, Erinnerungen und Service-Slots bei DST-Sprung und Mitternacht.
- [x] Geldberechnungen mit Eigenschafts-/Randfalltests absichern: Null-/Dreidezimalwährungen, Rundung je Position vs. Gesamtbetrag, Rabatte, Steuern, Gebühren, Refunds und WooCommerce-Summen müssen centgenau abstimmbar sein.
- [x] Für Berichts-Caches Invalidierungsregeln bei Bestellung, Refund, Check-in, Eventänderung und Löschung festlegen; Cache-Verhalten mit großen Datenmengen und SQL-Abfrageplänen testen.
- [x] Ausgehende HTTP-Aufrufe absichern und testen: HTTPS/Loopback-Ausnahmen, DNS-/Redirect-Verhalten, SSRF-Schutz, Timeouts, Antwortgrößen, JSON-Validierung und Redaction; lokale Fiskalservices müssen weiter gezielt erreichbar bleiben.
- [x] Bridge- und REST-Verträge mit automatisierten Kompatibilitätstests absichern: unterstützte Versionen, unbekannte/neue Felder, inkompatible Versionen, doppelte Requests und Netzwerk-Timeouts.
- [x] Diagnose-/Reconciliation-Prüfung für inkonsistente Daten ergänzen: offene Holds, bezahlte Bestellungen ohne Tickets, Tickets ohne gültige Bestellung, doppelte Refunds, hängende Job-Claims und nicht abgeschlossene Migrationen auffinden; Reparaturen zunächst mit Vorschau/Audit ausführen.

## Auditbefunde – main @ 14522c7 (2026-11-12)

Die folgenden Punkte wurden beim statischen Code-Review bestätigt. Laufzeit- und Fehler-Injection-Tests dazu stehen noch aus.

### Bestätigte Befunde

- [x] [P1][BESTÄTIGT] `.github/workflows/release.yml:30–45,76–99` — Ein gepushter `v*`-Tag löst den Release-Job ohne Prüfung eines erfolgreichen CI-Laufs für denselben SHA und ohne Prüfung der Abstammung von `main` aus; der Workflow checkt den Tag-Commit aus und veröffentlicht daraus ein ZIP. **Mögliche Auswirkung:** ungeprüfter oder fremder Stand kann als offizielles Release veröffentlicht werden. **Maßnahme:** Tag-Releases nur nach erfolgreichen Pflichtchecks für exakt denselben SHA und validierter Release-/Main-Abstammung zulassen; negative Workflow-Tests für fehlende/fehlgeschlagene Checks ergänzen.
- [x] [P1][BESTÄTIGT] `src/Installer.php:658–670` — Rückgaben/Fehler von `dbDelta()` werden nicht geprüft; Datenbankversion und Schema-Fingerprint werden anschließend trotzdem fortgeschrieben. **Mögliche Auswirkung:** Nach einem partiellen Schemafehler gilt das Upgrade als abgeschlossen und automatische Wiederholungen können ausbleiben; spätere Funktionen treffen auf fehlende Tabellen/Spalten. **Maßnahme:** Schemaänderungen und resultierende Tabellen/Spalten verifizieren, Version/Fingerprint erst nach vollständigem Erfolg speichern, Fehler protokollieren und Wiederholung ermöglichen; Fehler-Injection-/Retry-Integrationstest ergänzen.
- [x] [P1][BESTÄTIGT] `src/Payments/Webhooks.php:46–49` — `INSERT IGNORE` unterscheidet nicht zwischen `0` (bereits beanspruchtes Ereignis) und `false` (Datenbankfehler); beide Fälle werden als `duplicate` zurückgegeben. **Mögliche Auswirkung:** Ein Zahlungsanbieter erhält bei temporärem Datenbankfehler eine Erfolgs-/Duplikatantwort, obwohl Zahlung und Ticketverarbeitung nicht abgeschlossen wurden, und kann den Webhook-Retry einstellen. **Maßnahme:** Duplicate und SQL-Fehler getrennt behandeln; bei DB-Fehler retrybaren Fehler/HTTP 5xx liefern; Duplicate-, Fehler- und Retrypfade testen.
- [x] [P1][BESTÄTIGT] `src/Orders.php:1083–1124` und `329–344` — Ticket-Insert, Teilnehmerzuweisungen/-antworten, Update der Bestell-Teilnehmerdaten sowie Transaktionsstart/Commit werden nicht durchgängig auf Fehler geprüft; Teilnehmerdaten auf Auftragsebene werden gelöscht, sobald mindestens ein Ticket ausgegeben wurde. **Mögliche Auswirkung:** bezahlte Bestellung kann unvollständige Tickets/Teilnehmerdaten hinterlassen oder die einzige noch vorhandene Teilnehmerdatenkopie verlieren, während der Zahlungsablauf weiterläuft. **Maßnahme:** kritische DB-Schreibvorgänge und Transaktionsresultate prüfen; bei Fehler vollständig zurückrollen und retrybaren Fehler melden; Teilnehmerdaten erst nach nachgewiesener Persistierung aller Zuordnungen löschen; Fehler-Injection-Test ergänzen.
- [x] [P2][BESTÄTIGT] `src/Orders.php:1125–1158` und `src/Mail/Mailer.php:132–150` — Der Rückgabewert von `Mailer::deliver()` wird im Ticket-Ausgabeablauf ignoriert; Ticketcodes werden nur beim Erzeugen an die E-Mail übergeben und in der Datenbank gehasht gespeichert. **Mögliche Auswirkung:** Bei Mailfehler oder Abbruch nach Datenbank-Commit bleibt die bezahlte Bestellung mit gültigen Tickets bestehen, aber es gibt keinen automatischen Versand-Retry; die manuelle Nachsendung benötigt einen Eingriff und rotiert die Codes. **Maßnahme:** Ticketzustellung als dauerhaften Outbox-/Retry-Status speichern, Versand unabhängig von neu erzeugten Tickets wiederholbar machen und Fehler im Admin sichtbar anzeigen; Mailfehler- und Crash-nach-Commit-Tests ergänzen.
- [x] [P2][BESTÄTIGT] `src/Refunds.php:142–159,174–196` — Mehrere Datenbankoperationen beim Setzen auf `processing` und beim Verbuchen eines erfolgreichen Provider-Refunds werden nicht auf Fehler geprüft; die externe Rückzahlung kann vor lokaler Finalisierung erfolgt sein. **Mögliche Auswirkung:** Zahlungsanbieter und Plugin können unterschiedliche Refund-/Ticket-/Bestellstände aufweisen; ein Refund kann in `processing` hängen oder als lokal unvollständig erscheinen. **Maßnahme:** persistente Refund-Zustandsmaschine mit Fehlerprüfung und Reconciliation/Retry nach externem Provider-Erfolg einführen; Fehler zwischen Provideraufruf und jedem lokalen Schreibschritt simulieren und idempotente Reparatur testen.

### Verdachtsfälle

- Im geprüften Umfang wurde kein zusätzlicher Verdachtsfall aufgenommen. Vermutungen ohne belastbaren Codebeleg wurden nicht als Befund gewertet.


## Folgereview – zusätzliche bestätigte Befunde (Plugin-Code @ 14522c7)

Diese Befunde kamen aus der erneuten Prüfung mit fünf Perspektiven. Der neueste Commit `b022c2b` vor diesem Eintrag änderte nur diese TODO-Datei, nicht den Plugin-Code.

### Bestätigte Befunde

- [x] [P2][BESTÄTIGT] `src/Jobs.php:35–46`; `src/Communications.php:45–58` — Queue-Worker prüfen Transaktionsstart, bedingtes Claim-Update, betroffene Zeilen und Commit nicht, bevor der Job-Handler bzw. Mailversand ausgeführt wird. **Mögliche Auswirkung:** Bei DB-/Claim-Fehlern kann ein Eintrag in der Queue bleiben und parallel oder später erneut verarbeitet werden; Mails oder externe Job-Nebeneffekte können doppelt ausgelöst werden. **Maßnahme:** Verarbeitung nur bei erfolgreichem Claim mit genau einer aktualisierten Zeile und bestätigtem Commit starten; sonst rollbacken und retrybar abbrechen; Fehler-Injection- und Parallel-Worker-Tests ergänzen.
- [x] [P2][BESTÄTIGT] `src/Orders.php:1212–1245` — Sammel-Nachsendung rotiert Ticketcodes einzeln und versendet erst nach Abschluss aller Rotationen; Updates sind nicht serialisiert und ein Update mit null betroffenen Zeilen gilt nicht als Fehler. **Mögliche Auswirkung:** Bei Teilausfall können bereits alte Codes ungültig sein, ohne dass die Nachricht versendet wird; parallele Nachsendungen können Links mit inzwischen ersetzten Codes zustellen. **Maßnahme:** Rotation und Zustellung als serialisierten, wiederaufnehmbaren Vorgang mit Status/Outbox implementieren; Update-/Rowcountfehler erkennen und Teilfehler reparierbar machen; parallele Nachsendungen und Fehler nach jeder Rotation testen.
- [x] [P2][BESTÄTIGT] `src/Orders.php:739–760`; Aufruf `src/Payments/Webhooks.php:128–138` — `cancel_order()` ignoriert DB-Fehler bei Transaktion, Reservierungsfreigabe, Ticketwiderruf, Bestellstatus und Commit und meldet dennoch Erfolg; der Webhook kann als verarbeitet markiert werden. **Mögliche Auswirkung:** Bestellung, Reservierungen und Tickets können auseinanderlaufen, während der PSP einen 2xx erhält und den fehlgeschlagenen Vorgang nicht erneut sendet. **Maßnahme:** jede kritische Mutation und Commit prüfen, bei Fehler rollbacken und retrybaren Fehler zurückgeben, damit die Webhook-Zustellung wiederholt wird; Fehler-Injection-Test für den ganzen Stornopfad ergänzen.
- [x] [P2][BESTÄTIGT] `src/Orders.php:784–799`; Aufruf `src/Payments/Webhooks.php:140–149` — `mark_refunded()` prüft Freigabe, Ticketwiderruf und Bestellupdate nicht auf Fehler, bevor der externe Vollrefund-Webhook als verarbeitet gilt. **Mögliche Auswirkung:** Provider-Refund und lokaler Ticket-/Bestellstatus können auseinanderlaufen; der Webhook-Retry wird durch die Erfolgsantwort unterdrückt. **Maßnahme:** DB-Ergebnisse prüfen und Fehler retrybar weitergeben; externen Vollrefund-Zustand bei wiederholten Events idempotent abgleichen; Fehlerfälle testen.
- [x] [P2][BESTÄTIGT] `assets/offline-scanner/index.html:2,16–75`; `assets/offline-scanner/app.js:96–120,169–172,378–420,530–659` — Offline-Scanner-Texte, Statusmeldungen und Datumsformat sind auf Deutsch fest codiert (`lang="de"`), obwohl Übersetzungskataloge Sprachfassungen für mindestens Teile derselben Texte enthalten; die PWA lädt diese Kataloge nicht. **Mögliche Auswirkung:** Einlasskräfte anderer Sprachumgebungen erhalten deutsche Bedien-/Fehlermeldungen; Screenreader sprechen die Inhalte mit falscher Sprache aus. **Maßnahme:** Locale-abhängige PWA-Übersetzungsressource für UI, Status, Fehler und Datum implementieren, `html lang` korrekt setzen und automatisierte Sprach-/Screenreader-Checks ergänzen.

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

- [x] Datenschutz-Export/-Löschung für Gästelisten-Einträge (`wpcap_guest_entries`) ergänzen.
- [x] Datenschutz-Löschung soll laufende Wartelisten-Angebote (Platzhalter-Bestellung) sofort freigeben statt bis zum Ablauf zu halten.
- [x] `wpcap_waitlist_process` beim Deaktivieren des Plugins abmelden.
- [ ] Wartelisten-Bestellungen unterstützen keine Rabattcodes, Gutscheine und Rechnungsdaten – entscheiden und ggf. ergänzen.
- [x] Umtausch: Antworten auf Zusatzfelder ins neue Ticket übernehmen; eigenes Webhook-Ereignis `ticket.exchanged`; Differenzerstattung bei WooCommerce-Bestellungen automatisieren oder klar im Admin führen.
- [x] Core-Hook `wpcap_reserve_capacity` um `$context['internal']`, Käufer-E-Mail und Benutzer-ID erweitern (Allocations/Käuferlimit prüfen derzeit in `wpcap_order_creating`).
- [x] Neue Hooks dokumentieren (`wpcap_capacity_increased`, `wpcap_allocation_*`, `wpcap_boxoffice_*`, `wpcap_ticket_exchanged`, Pass-Filter) in docs/HOOKS.md.
- [ ] Pässe: Online-Verkauf endet mit Beginn des Verkaufstermins; „N aus M“ offline nur näherungsweise; nach Terminabsagen keine Neuprüfung der Restanzahl – fachlich entscheiden.
- [ ] Kasse: Bar-/Kartenerstattungen (auch automatische bei Eventabsage) benötigen eine offene Schicht – Betriebsablauf festlegen und im Admin sichtbar machen.

### Steuer und Recht – Fragen an die Steuerberatung (Details in docs/SALES-MODELS.md, docs/VOUCHERS.md, docs/GUEST-LISTS.md)

- [ ] Eigenhändler: Basis der Gutschrift (voller Ticketumsatz plus Gebührenrechnung vs. Umsatz abzüglich Provision) bestätigen.
- [ ] Leistungsort bei Events im Ausland und Reverse Charge nach Sitz des Veranstalters vs. Veranstaltungsort klären; ggf. Registrierungspflichten.
- [ ] UID-Prüfung: genügt formale Prüfung oder ist qualifizierte VIES-Bestätigung vor Reverse Charge Pflicht?
- [ ] Negative Abrechnungsmonate (Erstattungen > Verkäufe) und Kleinunternehmer-Fälle festlegen.
- [ ] E-Rechnungspflicht (XRechnung/ZUGFeRD) für B2B-Belege an Veranstalter und Firmenkunden in DE umsetzen, sobald der Umfang bestätigt ist.
- [x] Präfix „GS“ und Exportbezeichnung „Gutschrift“ für Rechnungskorrekturen umbenennen, um Verwechslung mit Selbstfakturierungs-Gutschriften (GU) zu vermeiden.
- [x] Rechnungskorrekturen mit Aufschlüsselung je Steuersatz (Netto/USt/Brutto) prüfen und ggf. ergänzen.
- [ ] Freikarten gegen Sponsoring/Medienleistungen (tauschähnlicher Umsatz) und Freikarten für Personal bewerten.
- [x] Mehrzweckgutscheine: Erstattungszeilen (`wpcap_refunds`) enthalten nur den Baranteil – Berichte/Abrechnungen auf Gutschrift-Beträge bzw. `refund_plans` umstellen.
- [x] Einzweckgutscheine aus dem Ticketdienst: Veranstalter-Abrechnung soll den gespeicherten Gutscheinwert (`face_value_minor`) berücksichtigen.
- [ ] Regionale Abgaben (Vergnügungssteuer, Kulturförderabgabe) und Nicht-EUR-Währungen (EUR-Gegenwert der USt) abbilden oder ausschließen.
- [ ] Affiliate-Provision bei Mehrzweckgutscheinen: Basis nur Baranteil oder voller Preis entscheiden (PR #19).

### UX- und Barrierefreiheitsbefunde aus der Expertenprüfung (noch offen)

- [x] Admin-Menü gruppieren (Verkaufen, Einlass, Kommunikation, Einstellungen) und „Erste Schritte“-Checkliste für neue Veranstalter ergänzen; Eventliste mit Verkauft/Kapazität/Umsatz.
- [x] Web-Scanner-Ergebnis wie in der Offline-PWA (großes farbiges Ergebnis, Ton/Vibration, Zeit und Ort der Erstnutzung) und kurzer, eintippbarer Hilfscode (nur für Personal, gedrosselt).
- [ ] Saalplan mobil nutzbar machen (Mindestgröße der Sitze, Zoom/Pan, Legende mit Form statt nur Farbe, Liste als Standard auf schmalen Bildschirmen).
- [ ] Ticketseite und Ticket-PDF um Teilnehmername, Veranstaltungsadresse, Einlasszeit, Bestellnummer und Wallet-Button ergänzen; PDF-Dateiname lesbar.
- [x] Einlasszeit („Doors“) als eigenes Feld am Termin; Zutritt vor Einlass verhindern.
- [x] Kapazität pro Einlassbereich (Stehbereich) am Einlass durchsetzen.
- [x] Alters-/Ausweispflicht pro Ticketart mit Hinweis im Scanner.
- [x] No-Show-Liste/Export pro Termin.
- [x] Getrennte Kontingente pro Verkaufskanal (online, Kasse, WooCommerce, Partner) und Kanal-Auswertung.
- [x] Abrechnungsblatt pro Vorstellung für Spielstätte/Künstler (Kapazität, Freikarten, Besucher, Door Deal).
- [x] Buchhaltungsexport um nicht fakturierte Online-/Kassenverkäufe ergänzen und Tagesgrenzen in Ortszeit statt UTC.
- [x] Einstellungsseite in Tabs aufteilen; Online- und Kassen-Zahlarten trennen; Rechtstexte mit Editor und je Sprache.
- [x] Eventkarte kompakt (Zusammenfassung + „Tickets“-Button), Mengen-Stepper, Kalender mit Verfügbarkeits-Badges und Link zum nächsten Termin.
- [x] HTML-Mail-Layout als Standard passend zu WooCommerce prüfen.
- [x] Nicht vorhandene/mehrdeutige Ortszeiten bei Sommerzeitwechsel im Admin anzeigen statt still verschieben.

### Architektur und Wartbarkeit (noch offen)

- [ ] Gebündelte Bibliotheken (`endroid/qr-code`, `bacon`) mit Strauss/PHP-Scoper präfixieren, um Konflikte mit anderen Plugins zu vermeiden.
- [x] Hook-Präfixe vereinheitlichen (`wp_pass_captain_*_v1` vs. `wpcap_*`) und eine stabile Liste öffentlicher Hooks festlegen.
- [x] Index-Längen > 767 Bytes (`wallet_registrations.device_serial`, `payment_events.provider_event`, `member_passes.member_status`) kürzen oder Mindestanforderungen dokumentieren.
- [x] Fest codierte deutsche JS-Texte im Saalplan-Editor übersetzbar machen; Umstieg auf englische Quelltexte für translate.wordpress.org prüfen.
- [ ] Kassen-Warnhinweis „keine Registrierkasse“ ausblenden, sobald ein Fiskal-Adapter aktiv und eingerichtet ist (nach Merge von PR #14).
- [ ] Übersetzungen fr_FR, it_IT, es_ES, nl_NL muttersprachlich gegenlesen lassen; Platzhalter in Hilfetexten (`{Termin-ID}` u. a.) übersetzbar machen.

## Auditbefunde Runde 3 – Branch `claude/woocommerce-ticket-plugin-8q4m8q` @ a54e21c (2026-11-13)

Fünf Prüfungen (Sicherheit, Abläufe E2E, Steuer/Finanzen, Datenschutz, Architektur/QA), alle Befunde auf echter WP-7.1-/WC-11.3-Installation bzw. mit Skripten nachgestellt. Integrationssuite zum Prüfzeitpunkt: 1986 bestanden, 0 fehlgeschlagen. Überschneidungen sind zusammengeführt (Steuer-P1 zum Umtausch ↔ Ablauf-P2 Positionsanzeige).

### Sicherheit (Prüfung 1)

- [x] [P1][BESTÄTIGT] `src/Exchange/Portal.php:51–66`; `src/Exchange/Service.php:151–199,373,700–708` — Portal-Umtausch prüft nicht, ob das Ticket bereits übertragen wurde (`transfer_count`, `attendee_email`): Der ursprüngliche Käufer kann ein übertragenes 50-€-Ticket auf 10 € herabstufen und erhält 40 € automatisch erstattet (nachgestellt: `completed`, `refund_minor=4000`, altes Ticket `revoked`). **Mögliche Auswirkung:** Betrug beim Weiterverkauf; Käuferin erhält ohne Zustimmung ein schlechteres Ticket. **Maßnahme:** Übertragene/zugewiesene Tickets im Portal nur durch die aktuelle Inhaberin umtauschen lassen oder sperren; Erstattung bei geänderter Inhaberschaft nur durch Personal; Negativtest.
- [x] [P2][BESTÄTIGT] `src/Allocations/BuyerLimits.php:123ff`; `src/Exchange/Service.php:373ff`; `src/Orders.php:671` — Käuferlimit greift nur in `wpcap_order_creating`, nicht beim Umtausch (nachgestellt: VIP-Limit 1, drei Umtausche Standard→VIP ergeben 4 VIP-Tickets). **Mögliche Auswirkung:** Hamsterkäufe knapper Ticketarten. **Maßnahme:** Limit auch in `phase=exchange` mit derselben Sperrlogik durchsetzen; Test.
- [x] [P2][BESTÄTIGT] `src/Exchange/AdminPage.php:43–57`; `src/BoxOffice/Service.php:36,316–322` — Gratis-Upgrade (`waive`) und Kassen-Zahlart `free` brauchen nur `wpcap_manage_orders`, nicht `wpcap_approve_comps` (nachgestellt: Support tauscht 10 € → 500 € ohne Aufpreis; Kasse verkauft 10 × 200 € als Freikarte). **Mögliche Auswirkung:** Freikarten in beliebiger Höhe ohne Vier-Augen-Prinzip. **Maßnahme:** An `wpcap_approve_comps` binden, optional Schwelle/Freigabe; Rechte-Matrix-Test.
- [x] [P2][BESTÄTIGT] `src/BoxOffice/Shifts.php:299–352`; `src/BoxOffice/ShiftPage.php:244–256` — Blindzählung umgehbar: Soll wird nach erster Zählung angezeigt, „Neu zählen“ unbegrenzt, Z-Bericht zeigt nur letzte Zählung. **Mögliche Auswirkung:** Fehlbeträge lassen sich verschleiern. **Maßnahme:** Soll erst nach Abschluss zeigen oder Nachzählung nur mit Finanzrecht; alle Zählungen im Z-Bericht; Test.
- [x] [P3][BESTÄTIGT] `src/BoxOffice/Service.php:459–471` (auch `src/Partners/Sales.php:730`) — Storno erkennt Nutzung nur an `status=checked_in`; Wiedereinlass-/Mehrfach-/Pass-Tickets bleiben `valid` und werden ohne Override erstattet. **Maßnahme:** Nutzung über `wpcap_checkins`/`wpcap_admission_entries` wie `Exchange\Service::was_admitted()` prüfen.

Verdachtsfälle (nicht end-to-end bestätigt):
- [x] [VERDACHT] `src/Partners/Sales.php:728–731` — Partner-Storno: Prüfung „Termin begonnen“ schützt bei Pässen mit anderem Anker-Termin wohl nicht.
- [x] [VERDACHT] `src/Exchange/Service.php:700–708` — Portal-Herabstufung bei Partner-/Kassenbestellungen bucht Erstattung über Geld, das die Plattform nie erhielt.
- [x] [VERDACHT] `src/Rest/Controller.php` `pos_sale` — alte Route `/pos/sales` umgeht Schicht, Belegnummer und Z-Bericht.
- [x] [VERDACHT] `src/Orders.php:1500ff` — Portal-Nachsendung erneuert auch Codes übertragener Tickets (Käufer kann QR-Code der Empfängerin ungültig machen).
- [x] [VERDACHT] `src/GuestLists/Service.php:93–98,305` — Freikarten-Antrag und Freigabe durch dieselbe Person möglich.

### Abläufe Ende-zu-Ende (Prüfung 2, Browser/WP-CLI/REST auf WP 7.1 + WC 11.3)

- [x] [P1][BESTÄTIGT] `src/Exchange/Portal.php:151–153` — Umtausch-Skript wird nur nach dem ersten Ticket ausgegeben; Formulare weiterer Tickets senden nativ per GET (Startseite `/?ticket_type_id=…`, nichts passiert). **Mögliche Auswirkung:** Selbstbedienungs-Umtausch nur für das erste Ticket. **Maßnahme:** Skript einmal ausgeben bzw. Event-Delegation; E2E-Test mit mehreren Tickets.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:242–253,589,612` — `ticket_value()` mittelt `line_total/qty` je Ticketart; nach Umtausch in dieselbe Art sinkt der Wert der übrigen Tickets (Erstattung 26,56 € statt 58,10 €). **Mögliche Auswirkung:** Zu niedrige Erstattungen. **Maßnahme:** Wert je Ticket als Snapshot speichern; Regressionstest „Upgrade, dann Rückstufung“.
- [x] [P1][BESTÄTIGT] `src/Passes/Cancellation.php:112–124` — Absage eines Pass-Tags mit `on_cancel=revoke` widerruft alle Pässe ohne Mail; bei `auto_refund_cancelled=0` weder Erstattung noch Hinweis. **Mögliche Auswirkung:** Bezahlte Pässe still ungültig, Abweisung am Einlass. **Maßnahme:** Absage-Mail; Bestellungen als „Erstattung offen“ führen und im Admin zeigen.
- [x] [P1][BESTÄTIGT] `src/Rest/Controller.php:279`; `src/Waitlists.php:462`; `src/Exchange/Service.php:804` — Haltezeit bei Überweisung (72 h) nicht auf Terminbeginn begrenzt („Zahlbar bis 16.11.“ für Event am 13.11.). **Mögliche Auswirkung:** Plätze bis nach dem Event blockiert, Tickets nachträglich ausgestellt. **Maßnahme:** min(Einstellung, Beginn − Puffer); Überweisung kurz vor Beginn ausblenden.
- [x] [P1][BESTÄTIGT] `templates/event-card.php:29–31` — Ausverkaufte Karten zeigen weiterhin das Checkout-Formular und keinen Wartelisten-Eintrag (nur per REST). **Mögliche Auswirkung:** Warteliste für Käufer:innen nicht erreichbar. **Maßnahme:** Wartelisten-Formular einblenden, Checkout bei komplett ausverkauft ausblenden.
- [x] [P2][BESTÄTIGT] `src/OrderPortal.php:84` — Teilnehmer-E-Mail im Portal Pflicht (im Checkout optional); Namenskorrektur ohne E-Mail abgelehnt, mit E-Mail rotiert der Code. **Maßnahme:** E-Mail optional; Name/Antworten ohne Codewechsel speichern.
- [x] [P2][BESTÄTIGT] `src/OrderPortal.php:81`; `src/Mail/Types.php:156` — Offene Bestellungen zeigen in Portal und Bestellmail keine Positionen, nur Betrag und Bankdaten. **Maßnahme:** Positionsübersicht (Event, Termin, Art, Menge, Preis, Rabatt).
- [x] [P2][BESTÄTIGT] `src/Exchange/Portal.php:170` — Nach Upgrade mit Aufpreis keine Bankdaten/Betrag/Verwendungszweck im Portal; offener Umtausch nach Neuladen unsichtbar, Button erneut angeboten. **Maßnahme:** Bankdaten anzeigen; offenen Umtausch mit Link zur Zusatzbestellung darstellen.
- [x] [P2][BESTÄTIGT] `src/Exchange/Service.php:589,605–612`; Admin-Bestellansicht — Nach Umtausch widersprüchliche Positionen (2 × 49,90 = 105,34), Zusatzbestellung „Erstattet 1“ bei 0,00 €, Abweichung von bereits ausgestellter Rechnung. **Maßnahme:** Umtausch als eigene Bewegungen (siehe Steuer-P1); Anzeige „Umgebucht“.
- [x] [P2][BESTÄTIGT] `src/Printing/Page.php:29`; `src/BoxOffice/Page.php:96–101` — Terminauswahl der Kasse/Gästeliste/Abholliste enthält Vergangenes, Entwürfe, Abgesagtes und wählt den ältesten. **Mögliche Auswirkung:** Verkauf/Einlass auf falschen Termin. **Maßnahme:** Standard = nächster/laufender Termin; Rest ausblenden oder gruppieren.
- [x] [P2][BESTÄTIGT] `src/Rest/Controller.php:142` — Rabattcodes nur per REST anlegbar, keine Adminseite; Feld „Rabattcode“ immer sichtbar; Preisvorschau ignoriert den Code. **Maßnahme:** Kampagnen-Adminseite, Feld nur bei aktiven Codes, Live-Vorschau.
- [x] [P2][BESTÄTIGT] `src/Mail/Types.php:263` — Erstattungsmail sagt „erstattet“ auch bei Überweisung (Geld noch nicht unterwegs), Käufer-IBAN wird nicht erfasst; Mail/Admin zeigen 32-stellige Hex-ID statt Bestellnummer. **Maßnahme:** Text je Zahlart, IBAN-Abfrage, überall kurze Bestellnummer.
- [x] [P2][BESTÄTIGT] `src/Admin/EventsPage.php`; `src/OccurrenceCancellation.php:85` — Terminabsage nur mit `confirm()`, ohne Grund, Folgenvorschau und Zusammenfassung; Auslastung zählt Pässe nicht. **Maßnahme:** Bestätigungsseite mit Folgen, Summary-Notice.
- [x] [P3][BESTÄTIGT] `src/BoxOffice/Service.php:277` — Kassenverkäufe ohne E-Mail senden Ticketmail an `boxoffice+…@invalid.example`. **Maßnahme:** Versand an Platzhalter unterdrücken.
- [x] [P3][BESTÄTIGT] `src/Checkin.php:364` — Ticket für anderen Tag: „zu dieser Uhrzeit nicht möglich“ ohne Gültigkeit; Web-Scanner ohne Terminauswahl. **Maßnahme:** Gültigkeit nennen, Terminauswahl.
- [x] [P3][BESTÄTIGT] `src/PublicEvents.php:91–96` u. a. — Shortcode/Block ohne Event-/Termin-Attribut; „bis 1 Stunden“; gleichnamige Events ohne Datum in Dropdowns; Kassen-/Partnerformulare verlangen rohe IDs; Partner-Rechnungshinweis doppelt. **Maßnahme:** Attribute `event`/`occurrence`, Auswahllisten, Texte korrigieren.

Verdachtsfälle:
- [x] [VERDACHT] Pass mit zwei Terminen am selben Tag: Web-Scanner bindet an Betriebstag, zweiter Scan „Tageskontingent erreicht“ – Abendshow vermutlich nicht betretbar.
- [x] [VERDACHT] Blindzählung wirkt nur für Kassierer ohne Manager-/Finanzrecht; UI erklärt das nicht.
- [x] [VERDACHT] `src/BoxOffice/ZReport.php` `rows()` ohne Null-Prüfung (Warnings bei ungültiger ID).

Fehlende Abläufe: Wartelisten-Eintrag für Käufer:innen; Rabattcode-Verwaltung; Ticketarten je Termin kopieren/Vorlagen; Erstattung per Überweisung mit IBAN und Status „ausgezahlt“; Gutschrift/Rechnungskorrektur in Admin und Portal verlinken; Ticketmail mit Portal-Link, Bestellnummer und Teilnehmernamen, Umtauschmail mit PDF; Verkaufsschluss für Überweisung; Absage-Vorschau; Terminauswahl im Web-Scanner. Nicht geprüft: Offline-PWA, Wartelisten-Fristablauf, Woo-Classic-Checkout, Serien-Preisstufe und Rabatt in Woo.

### Steuer und Finanzen (Prüfung 3)

- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:580–591,601–612`; `src/Partners/Settlements.php:108–126`; `src/Reports/Data.php:139–150` — Umtausch verändert bereits abgerechnete Bestellpositionen: umgezogene Einheit bekommt neue `item_id` und erscheint erneut als `late_sale`; bei Herabstufung wird die Zielposition gekürzt und die Erstattung zusätzlich abgezogen; Umtauschgebühr in keiner Position; Zusatzbestellung zählt Menge 1 (Ticketgebühr doppelt). Beispiel: 30.000 Cent abgerechnet bei 17.000 vereinnahmt. **Mögliche Auswirkung:** Doppelte Auszahlung/Gutschrift an Veranstalter, falsche Provision, nachträglich veränderte Berichte geschlossener Zeiträume. **Maßnahme:** Positionen nach Zahlung unveränderlich halten, Umtausch als eigene Buchungszeilen (Abgang/Zugang/Gebühr), Abrechnung/Berichte umstellen, Abstimmungstest „Abrechnung = Zahlungen − Erstattungen“.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:242–253` — `ticket_value()` nutzt `total_minor/subtotal`; bei Mehrzweckgutschein ist `total_minor` nur der Barrest → voll per Gutschein bezahltes Ticket hat Wert 0 (Upgrade kostet vollen Zielpreis, Downgrade kostet statt zu erstatten). **Mögliche Auswirkung:** Kunden zahlen zu viel, doppelter USt-Ausweis. **Maßnahme:** Wert aus `Checkout::gross_minor()`/Positionsbrutto nach Rabatt; Gutscheinanteil über `refund_plan`.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:580–591`; `src/Invoices.php:70,318` — Umtausch in Ticketart mit anderem Steuersatz schreibt Positions-USt um, `orders.tax_minor` und Rechnung bleiben; später ausgestellte Rechnung ist widersprüchlich (Kopf 909 vs. Zeilen 1.287 Cent). **Mögliche Auswirkung:** Unrichtiger Steuerausweis (§ 14c UStG), Abweichung USt-Voranmeldung/Berichte. **Maßnahme:** Steuersatzwechsel nur mit Rechnungskorrektur + neuem Beleg oder sperren; `tax_minor` konsistent; Rechnungskopf aus Zeilen.
- [x] [P1][BESTÄTIGT] `src/Orders.php:196–212` vs. `src/Campaigns.php:31–51` — Zielgerichtete Kampagnenrabatte (Ticketart/Event) werden anteilig auf alle Positionen verteilt (Beispiel: USt 1.932 statt 1.742 Cent, fremde Position um 25 € gekürzt). **Mögliche Auswirkung:** Falsche USt je Satz, Rabatte eines Events zulasten anderer Veranstalter. **Maßnahme:** Zeilenverteilung aus `Campaigns::calculate()` übernehmen und je Position (`discount_minor`) speichern.
- [x] [P1][BESTÄTIGT] `src/Exchange/Service.php:879–890`; `src/BoxOffice/Shifts.php:611–620` — Kassen-Umtausch bestätigt Zusatzbestellung ohne Kassenverkauf, Beleg und Schichtbuchung; spätere Erstattungen werden nicht gebucht. **Mögliche Auswirkung:** Bareinnahmen fehlen im Z-Bericht/Kassen-Soll; Einzelaufzeichnungs- und Belegpflicht (§ 146/146a AO, § 131/132a BAO) verletzt. **Maßnahme:** Aufpreis/Gebühr als Kassenverkauf mit Belegnummer in offene Schicht buchen; Erstattungen ebenso.
- [x] [P2][BESTÄTIGT] `src/Refunds.php:513–537,539–558` — `tax_for()` teilt USt nach Rabatt durch Brutto vor Rabatt (Beispiel: 10,67 € statt 13,33 €); betrifft auch `record_external()`. **Maßnahme:** `line_shares()` mit Positionsbrutto nach Rabatt; Eigenschaftstest „Summe Korrekturen = Rechnung je Satz“.
- [x] [P2][BESTÄTIGT] `src/Refunds.php:550–551` — Betragserstattung bei Mehrzweckgutschein rechnet volle USt gegen Barrest (3,33 € statt 1,67 €). **Mögliche Auswirkung:** Zu hohe USt-Minderung. **Maßnahme:** Basis `Checkout::gross_minor()` und Sätze der betroffenen Positionen.
- [x] [P2][BESTÄTIGT] `src/Invoices.php:18,31–91` — Rechnung nach Teilerstattung weist volles Entgelt ohne Korrektur aus (Korrekturen nur, wenn Rechnung vor Erstattung existierte; `auto_invoice` standardmäßig aus). **Mögliche Auswirkung:** § 14c UStG, überhöhter Export. **Maßnahme:** Beim Ausstellen Korrekturbelege für frühere Erstattungen erzeugen oder Rechnung auf Entgelt nach Erstattungen begrenzen.
- [x] [P2][BESTÄTIGT] `src/Partners/Statement.php:124–133` — Wiederverkäufer-Abrechnung ignoriert Reverse Charge und Kleinunternehmer, ohne Belegnummer. **Maßnahme:** Logik aus `SalesModel::reverse_charge()`/`small_business`; Beleg festlegen (Gutschrift mit Nummernkreis oder Rechnung des Wiederverkäufers).
- [x] [P2][BESTÄTIGT] `src/Exchange/Service.php:170–175` — Serien-Umtausch prüft nicht denselben Veranstalter/dasselbe Verkaufsmodell. **Mögliche Auswirkung:** Sperre gegen gemischte Verkäufer umgangen; Rechnung von A für Leistung von B. **Maßnahme:** Umtausch zwischen verschiedenen Partnern/Verkaufsmodellen sperren.
- [x] [P2][BESTÄTIGT] `src/Integrations/Vouchers/Checkout.php:121–131` — Einzweckgutschein im Checkout als `discount_minor` gebucht → Veranstalter-Abrechnung/Gutschrift sinken um den Gutscheinwert. **Maßnahme:** Gutscheinwert getrennt führen, in Abrechnung als Forderung gegen den Gutscheinverkäufer ausweisen.
- [x] [P3][BESTÄTIGT] `src/Invoices.php:73–83` vs. `src/Orders.php:203–206`, `src/Reports/Data.php:241–252` — Unterschiedliche Rabatt-Rundung (floor kumulativ vs. intdiv + Rest). **Maßnahme:** Gemeinsamer Allokator bzw. gespeicherter Zeilenrabatt.
- [x] [P3][BESTÄTIGT] `src/Passes/Cancellation.php:93–96`; `src/Refunds.php:550–551` — Pass-Tageserstattungen mit Durchschnitts-USt der Bestellung statt Pass-Satz. **Maßnahme:** `tax_minor` mit Pass-Satz übergeben.
- [x] [P3][BESTÄTIGT] `src/Reports/Data.php:58`; `src/Partners/Settlements.php:113–120` — Kassen-Freikarten (`pos_free`) zählen als verkauft, Ticketgebühr fällt an. **Maßnahme:** `pos_free` in `comp_providers()` aufnehmen oder dokumentieren.
- [x] [P3][BESTÄTIGT] `src/BoxOffice/Shifts.php:621–626` — Stornos von Kassenverkäufen „Rechnung“/„Freikarte“ ohne offene Schicht werden nie gebucht; Grand Total ohne Storno. **Maßnahme:** Nachbuchungs-Warteschlange für nächste Schicht.
- [x] [P3][BESTÄTIGT] `src/Orders.php:198`; `src/BoxOffice/Service.php:276–280` — Rechnungsdatenpflicht über Schwelle greift nicht bei Kasse („Zahlung auf Rechnung“), Partner-API und Zusatzbestellungen. **Maßnahme:** `Billing::required_for` auch dort erzwingen.

Fragen an die Steuerberatung (neu):

- [ ] Leistungszeitpunkt bei Vorverkauf: Gutschrift/Provisionsrechnung ordnen nach Bestelldatum; Anzahlungsabrechnung (§ 13 Abs. 1 Nr. 1a S. 4 UStG / § 19 Abs. 2 Z 1 lit. a UStG AT) korrekt bezeichnet oder nach Veranstaltung/Vereinnahmung trennen?
- [ ] Umtauschgebühr: Nebenleistung zum Ticket oder eigenständige Leistung zum Regelsatz? Beleg bei Verrechnung mit Erstattung?
- [ ] Umtausch mit Steuersatzwechsel oder in anderes Event: Rechnungsberichtigung + neue Rechnung oder Storno und Neuverkauf?
- [ ] Partner-API-Verkäufe im Eigenhändler-Modell (Wiederverkäufer kassiert): zweite Kommissionsstufe? Wer belegt die Wiederverkäufer-Vergütung?
- [ ] Zielgerichtete Rabatte bei gemischten Steuersätzen: zwingend der rabattierten Leistung zuordnen?

### Datenschutz (Prüfung 4)

- [x] [P2][BESTÄTIGT] `src/Modules/Allocations.php:103–108`; `src/Allocations/BuyerLimits.php:105–110,157–181` — `buyer_key` in `wpcap_alloc_buyers` ist ungesalzenes `sha256('email:'…)`/`sha256('user:'…)`, dazu Bestell-IDs in `holdings`; Tabelle weder exportiert, gelöscht noch von der Retention erfasst. **Mögliche Auswirkung:** Re-Identifizierung anonymisierter Bestellungen über bekannte E-Mail (Art. 17, Art. 5 Abs. 1 lit. e). **Maßnahme:** `hash_hmac` mit `wp_salt()` (Migration), Eraser entfernt Zeilen/Bestell-IDs, Löschung nach Ende des letzten Termins.
- [x] [P2][BESTÄTIGT] `src/Modules/GuestLists.php:73–94`; `src/Privacy/Retention.php:72` — Keine Löschfrist für `wpcap_guest_entries` (Name, E-Mail, Firma, Notiz, auch abgelehnte/stornierte). **Mögliche Auswirkung:** Unbegrenzte Speicherung, Notizen evtl. Gesundheitsdaten (Art. 9). **Maßnahme:** Retention-Schritt nach Terminende + `attendee_retention_days` (personenbezogene Felder leeren, Zählwerte behalten); Hinweis im Notizfeld.
- [x] [P2][BESTÄTIGT] `src/Waitlists.php:58–105`; `src/Privacy/Retention.php:72` — Wartelisteneinträge werden nach dem Event nie gelöscht/anonymisiert; keine Frist in PolicyText/OPERATIONS. **Mögliche Auswirkung:** Verstoß gegen Speicherbegrenzung. **Maßnahme:** Pseudonymisieren/löschen X Tage nach Termin-/Eventende; Frist dokumentieren.
- [x] [P2][BESTÄTIGT] `src/Partners/Api.php:176–232`; `src/Partners/Keys.php:133–137` — Lesende Partner-Endpunkte filtern nur nach verknüpften Events, nicht nach Rolle/eigenem Kanal: ein Wiederverkäufer mit `attendees:read` sieht E-Mails aller Käufer des Events (online, Kasse, andere Partner). **Mögliche Auswirkung:** Unzulässige Übermittlung an Dritte (Art. 5, 6). **Maßnahme:** Für `role=reseller` auf eigene `wpcap_partner_orders` beschränken oder `attendees:read` sperren; Admin-Warnung; Test.
- [x] [P2][BESTÄTIGT] `src/Privacy/PolicyText.php:105`; `docs/OPERATIONS.md:78` — Texte beschreiben sofortige Ungültigkeit künftiger Tickets bei Löschung, tatsächlich wird zurückgestellt (`wpcap_privacy_defer_upcoming`, `src/Privacy.php:278–284`); Abschnitte zu Gästelisten (Art. 14), Warteliste mit Auto-Angebot, Käuferlimits, Kasse/Rechnung auf Firma, Umtausch, Partnerverkauf fehlen. **Mögliche Auswirkung:** Unrichtige/unvollständige Information (Art. 12–14). **Maßnahme:** Text korrigieren, Abschnitte modulabhängig ergänzen, Rolle der Partner benennen.
- [x] [P3][BESTÄTIGT] `src/Modules/Partners.php:223–229`; `src/Partners/Sales.php:858–878` — `wpcap_partner_ticket_codes` bleiben nach Löschung/Widerruf und Retention bestehen. **Maßnahme:** Bei `wpcap_ticket_revoked`/Privacy-Löschung und nach Terminende + Frist löschen.
- [x] [P3][BESTÄTIGT] `src/Privacy.php:172–180` — Export ohne Wartelisten-Menge/Termin/Angebot, ohne `wpcap_ticket_exchanges` und Partner-Verkaufsdaten. **Maßnahme:** Exporter ergänzen.
- [x] [P3][BESTÄTIGT] `src/GuestLists/Service.php:390` — Begleittickets tragen den Namen des Gastes („Begleitung von …“). **Maßnahme:** Neutral „Begleitung“ oder per Filter abschaltbar.
- [x] [P3][BESTÄTIGT] `src/BoxOffice/Service.php:624` — Kundenbelege zeigen den Klarnamen der Kassierkraft. **Maßnahme:** Kürzel/Bediener-ID, Klarname per Einstellung.
- [x] [P3][BESTÄTIGT] `src/Partners/Repository.php:58–97`; `src/Partners/Settlements.php:472` — Kontaktpersonen und IBAN der Partner im Klartext, dauerhaft, nicht exportiert/gelöscht. **Maßnahme:** In Exporter/Eraser aufnehmen (Snapshot ausgenommen), IBAN maskiert anzeigen.


### Architektur und QA (Prüfung 5)

- [x] [P1][BESTÄTIGT] `src/Orders.php:964–983`; `src/Payments/Webhooks.php:123–124` — Bei verspäteter Zahlung wird ein vorübergehendes `db_busy` (Modul-Filter oder `capacity_error()`) wie „ausverkauft“ behandelt: Bestellung `needs_review`/`paid_unallocated`, Webhook gilt als verarbeitet (nachgestellt: 50 Plätze frei, trotzdem 0 Tickets). Erneuter Versuch im Admin nur für `manual`, Erstattung verlangt `paid`. **Mögliche Auswirkung:** Bezahlte Bestellungen ohne Tickets bei Stripe, PayPal, Mollie, Partner, Kasse und Warteliste; falsche Admin-Mail „kein Kontingent“. **Maßnahme:** Wiederholbare Fehler → `payment_busy()` (Webhook wird erneut zugestellt); Admin-Aktion „Zuteilung erneut versuchen“ für `needs_review`; Fault-Injection-Test.
- [x] [P2][BESTÄTIGT] `src/Integrations/Vouchers/ProviderInterface.php:8–12` vs. `src/Integrations/Vouchers/Checkout.php:441–486` — Dokumentiertes Feld `treatment` (`payment`/`single`/`discount`) wird nicht ausgewertet; alle Werte ergeben `multi`. **Mögliche Auswirkung:** Falsche USt-Behandlung bei vertragstreuen Anbietern. **Maßnahme:** `treatment` auswerten oder Docblock korrigieren; Vertragstest.
- [x] [P2][BESTÄTIGT] `src/Orders.php:338–339` vs. `996–997` — `wpcap_order_paid` feuert bei bezahlten Bestellungen vor, bei kostenlosen nach der Ticketausgabe (Webhook `order.paid` mit `ticket_count` 0 bzw. N). **Maßnahme:** Einheitliche Reihenfolge; eigener Hook `wpcap_tickets_issued`; in HOOKS.md dokumentieren.
- [x] [P2][BESTÄTIGT] `src/Series/Pools.php:126–208`; `src/Passes/Capacity.php:44–238`; `src/Partners/Sales.php:173–200` — Serienpools, Pässe und Partnerkontingente summieren weiter über `order_items`/`reservations` statt über Zähler; Availability-Filter je Ticketart mit eigenen Abfragen (mit Modulen: 3 Termine 28, 12 Termine 100, 48 Termine 388 Abfragen; ohne Module konstant 8). **Mögliche Auswirkung:** Große Vorverkäufe mit Pässen/Pools/Wiederverkäufern serialisieren wieder; Eventliste wird mit jedem Termin langsamer. **Maßnahme:** Gepflegte Zähler in den Modulen, Batch-Filter `wpcap_ticket_availability_many`, Test auf konstante Abfragezahl. — *Teil Listing erledigt (Request-Caches, 14 statt 117 Abfragen bei 12 Terminen, Test mit Obergrenze); Teil Checkout siehe nächster Punkt.*
- [x] [P2] `src/Series/Pools.php:126–208`; `src/Passes/Capacity.php:44–238`; `src/Partners/Sales.php:173–200` — Checkout-Kapazitätsprüfung der Module (Pässe, Serienpools, Partnerkontingente) über gepflegte Zähler (CapacityCounters je Ticketart/Termin bzw. eigene Zähler) statt SUM über order_items/reservations unter Sperren; Nebenläufigkeitstest mit allen Modulen.
- [x] [P2][BESTÄTIGT] `src/CapacityCounters.php:441–480` — `reconcile()` alle 5 Minuten ohne Lock und Zeitbudget über den gesamten Bestand; Fenster zwischen `COMMIT` und `sync_order_now()` erzeugt Fehlalarme. **Maßnahme:** Auf künftige/kürzlich geänderte Termine beschränken, `GET_LOCK`, Alarm erst nach bestätigter zweiter Prüfung.
- [x] [P3][BESTÄTIGT] `src/Admin/EventsPage.php:191,291` — Kontingenterhöhung im Admin feuert kein `wpcap_capacity_increased`; Wartelisten-Angebote erst nach stündlichem Sweep. **Maßnahme:** Hook nach Erhöhung auslösen.
- [x] [P3][BESTÄTIGT] `src/Plugin.php:134` — Deaktivierung meldet `wpcap_webhooks_dispatch` (und Fortsetzungsläufe aus `Jobs/TimeBudget`) nicht ab; `Waitlists::run_due` läuft außerhalb des Zeitbudgets. **Maßnahme:** Abzumeldende Hooks aus zentraler Konstante; `run_due` mit `TimeBudget`.
- [x] [P3][BESTÄTIGT] `src/Modules/BoxOffice.php:29–70`; `src/BoxOffice/Registers.php:148–158` — Kassen-Migration mit eigener Versionsoption auf `init` ohne Upgrade-Lock, offene Schichten außerhalb einer Transaktion. **Mögliche Auswirkung:** Doppelte „Kasse N (übernommen)“; `SHOW COLUMNS` bei jedem Request bis zum Upgrade. **Maßnahme:** In `Installer::install()` unter Lock ziehen.
- [x] [P3][BESTÄTIGT] `src/Exchange/Service.php:525`; `src/Allocations/Holds.php:122`; `src/Passes/Capacity.php:183` — `db_busy` uneinheitlich (409 ohne `retryable`, 503 ohne `retryable`). **Maßnahme:** Gemeinsame Fehlerfabrik `Errors::busy()` (503, `retryable=true`).
- [x] [P3][BESTÄTIGT] `docs/HOOKS.md` — Etwa 70 von 145 Hooks undokumentiert (22 nirgends), Dokument nennt Nicht-Hooks (`wpcap_jobs`, `wpcap_message_queue`). **Maßnahme:** Aus dem Code erzeugen bzw. per CI abgleichen.

Verdachtsfälle:
- [x] [VERDACHT] `src/Exchange/Service.php:949–993` — Sperrreihenfolge in `finalize` (Typen/Termine → Zusatzbestellung) umgekehrt zu `sync_order_now()` (Bestellung → Typen); Deadlock bei gleichzeitiger Stornierung denkbar.
- [x] [VERDACHT] `src/Orders.php:524–557` — `capacity_error_counted` zieht nur `active`-Holds ab; bereits `expired`, aber nicht synchronisierte Holds blockieren im Grenzfall die eigene späte Zahlung.
- [x] [VERDACHT] `src/Partners/Statement.php` vs. `src/Partners/Settlements.php` — zwei Abrechnungswege mit eigener Zuordnung; Verrechnung bei selbst verkaufenden Veranstaltern nur manuell.
- [x] [VERDACHT] Datenschutz-Löschung widerruft Tickets ohne Kontingentfreigabe (anders als `Orders::revoke_tickets`).
- [x] [VERDACHT] `Modules::register_all` — Module nicht einzeln abschaltbar; Filterlast auch ohne Nutzung.

Fehlende Tests: Fault-Injection `db_busy`/Deadlock in Modul-Filtern (späte Zahlung, Umtausch, `Refunds::execute`); modulübergreifende Nebenläufigkeit (Pass + Hold + Partnerkontingent + Käuferlimit gegen Umtausch); parallele Wartelisten-Annahmen und Gästelisten-Quote; Abfrage-Obergrenze mit allen Modulen; Upgrade-Pfad großer Bestand; Gutschein-Vertragstest `treatment`.

### Betreiberpflichten (für docs/OPERATIONS.md)

- [x] Art.-14-Information für Gästelisten; Abholliste nach dem Event vernichten.
- [x] Rolle der Partner festlegen (eigener Verantwortlicher oder Auftragsverarbeitung) und dokumentieren.
- [x] Kassen-Schichtdaten: Mitbestimmung (§ 96/96a ArbVG, § 87 Abs. 1 Nr. 6 BetrVG); Z-Berichte aufbewahrungspflichtig.
- [x] AV-Vertrag mit dem Fiskalanbieter; manueller Auskunftsablauf für Bestellungen ohne E-Mail; Verarbeitungsverzeichnis erweitern.

## Experten-Review, zweite Runde (12.11.2026, Branch `claude/youthful-bardeen-7ejd7a`)

Fünf parallele Prüfungen (Sicherheit, Geld/Buchungen, Steuer/Recht/Datenschutz, WooCommerce/Zusammenspiel, Bedienung/Betrieb) gegen den Stand nach dem Merge von `main`. Jeder Befund wurde im Code nachvollzogen; Punkte mit [Entscheidung] brauchen Steuerberatung, Rechtsberatung oder eine Produktentscheidung. Bereits in den Abschnitten oben gelistete Befunde sind hier nicht wiederholt.

### Sofort behoben

- [x] Webscanner: 5xx-, Retry- und Nonce-Fehler erscheinen gelb „Systemfehler/Sitzung abgelaufen – erneut scannen“ statt „Kein Einlass“ (986caf3).

### Sicherheit

- [ ] Sicherheit: Inventar-Blockade durch anonyme Bestellungen mit manueller Zahlung (72 h Hold, 30 Bestellungen/h je IP) verhindern: kurzer Erst-Hold bis E-Mail-Bestätigung bzw. Zahlungsstart, Obergrenze offener Holds je Event, Bot-Schutz, Admin-Massenfreigabe. — *teilweise erledigt (4afd2cd): max. 2 offene manuelle Bestellungen je E-Mail, 5 je Besucher in der Haltezeit; offen: kurzer Erst-Hold bis E-Mail-Bestätigung, Obergrenze je Event, Bot-Schutz, Massenfreigabe.*
- [ ] Warteliste: Eintrag erst nach Double-Opt-in aktiv (`Waitlists::join`), Missbrauchsgrenze je E-Mail.
- [ ] Portal-Nachsendung (`Orders::resend_tickets`) darf übertragene Tickets (`transfer_count > 0`) nicht rotieren.
- [ ] `rest_pre_serve_request`-Rohausgabe (WalletPasses/WebService.php) auf die eigenen Wallet-Routen beschränken.
- [ ] Fiskal-`HttpClient` nur HTTPS und keine privaten/Loopback-Ziele (außer ausdrücklich erlaubten lokalen Diensten).

### Geld, Buchungen und Nebenläufigkeit

- [x] Geld (hoch): Provider-Refunds aus dem Stripe-/PayPal-Dashboard (Webhooks.php `refunded`) buchen nur `mark_refunded()` – keine Refund-Zeile, keine Rechnungskorrektur, kein Gutschein-Rückfluss, Partner wird voll ausbezahlt. Über `Refunds::record_external()` buchen (Schlüssel je Provider-Refund-ID, Teilbeträge); Test: Dashboard-Refund erzeugt genau eine Refund-Zeile. — *erledigt (36a8192): Buchung über `Refunds::record_external()`, Test in payments.php.*
- [x] Geld (hoch): Partnerabrechnung bei Mehrzweckgutschein – Refund-Zeilen enthalten nur den Baranteil; mit `Checkout::credit_note_amounts()` (Bar + Gutschein) abrechnen. Test 2×50, Gutschein 60, Vollerstattung → Auszahlung 0. — *erledigt (a868a40), Test zz_voucher_purpose.php.*
- [x] Geld: `Refunds::tax_for()` wendet Rabatt doppelt an (Zeilenbrutto vor, Steuer nach Rabatt); rabattierten Zeilenbrutto als Basis nehmen. Test 50-%-Kampagne, 1 von 2 Tickets → USt 3,99 statt 2,00. — *erledigt (a868a40), Test zz_integration.php.*
- [x] Geld (hoch): Fehlgeschlagene Gutschein-Rückbuchung (`Checkout::refund`) geht verloren – als Job mit gleichem Schlüssel wiederholen, offen markieren, Admin benachrichtigen. — *erledigt (36a8192): Job `voucher_refund`, Admin-Mail bei endgültigem Fehler.*
- [ ] `voucher_snapshot` nur unter Zeilensperre bzw. mit Versionsprüfung ändern (commit/release/refund/refund_plan); Race „Ablauf vs. Spätzahlung“ testen.
- [ ] Kampagnennutzung bei Spätzahlung nach Hold-Ablauf erneut zählen bzw. bei erschöpftem Limit `needs_review`.
- [ ] Erstattungs-/Freigabe-Mails ohne fest codiertes `/100` (Währungen ohne Nachkommastellen).
- [ ] Abgleichbericht: VC-Einlösungen vs. PC-Snapshots (committed/released/refunded) und offene Gutschein-Rückbuchungen.

### Steuer, Recht und Datenschutz (DE/AT)

- [ ] Kasse: Bar/Karte für DE/AT sperren, solange kein Fiskalanbieter (TSE/RKSV) aktiv ist; die Fiskal-Adapter implementieren ein nicht vorhandenes `Fiscalization\AdapterInterface` (toter Code). Beleg im Vermittlermodell mit Veranstalter als Aussteller und Kleinunternehmer-Hinweis. — *teilweise erledigt (e44b8ac): Bar/Karte in DE/AT gesperrt, Admin-Bestätigung für eigene zertifizierte Kasse; offen: Fiskal-Kern/Adapter-Interface, Beleg im Vermittlermodell mit Veranstalter als Aussteller.*
- [x] Provisionsrechnung (Settlements.php:273): Plattform-Kleinunternehmer berücksichtigen (0 % USt + Hinweis, kein § 14c). — *erledigt (a868a40).*
- [ ] Gutschrift: Reverse Charge nach Veranstaltungsort statt Sitz des Veranstalters ermitteln; Veranstaltungsland am Event speichern. [Entscheidung]
- [ ] Kleinunternehmer (Plattform/Veranstalter): 0 % schon beim Speichern der Ticketart bzw. im Checkout erzwingen, nicht erst bei der Rechnung (sonst bezahlte Bestellung ohne Rechnung).
- [x] Rechnungskorrekturen je Steuersatz aufschlüsseln (Datenmodell, PDF, CSV) und Hinweise (Kleinunternehmer, Vermittler) wie auf der Rechnung zeigen.
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

## Audit Runde 4 – 5 + 5 Experten und Eigen-Audit (13.11.2026, Snapshots 4d82df1 / 82f927d)

Einzelaufgaben mit Nummern in ARBEITSLISTE.md (Phase 10). Sortiert nach Kritikalität.

- [x] [KRITISCH] Umstieg auf Multisite konnte Subsite-Daten löschen und Cron abschalten — src/LegacySlug.php (watch_legacy_copy), alte uninstall.php (A263, R1 Produkt/Core)
- [x] [KRITISCH] Volle externe Erstattung bei Fehler im Erstattungsbuch trotzdem als verarbeitet markiert — src/Payments/Webhooks.php ~213–229 (apply, refunded) (A264, R1 Core)
- [x] [KRITISCH] Werkseinstellung „Überweisung“ ohne Bankverbindung verkauft — src/Settings.php:54, src/Payments/ManualGateway.php:14, BankTransfer.php:56, assets/checkout.js:384 (A265, R1 UX)
- [x] [KRITISCH] Terminabsage über Status-Dropdown umgeht Bestätigungsseite — src/Admin/EventsPage.php:915, :189, :235 (save_occurrence → wpcap_occurrence_cancelled) (A266, R1 UX)
- [x] [KRITISCH] „Event absagen“ in der Eventliste nur mit window.confirm — src/Admin/EventsPage.php:778–781, handle_cancel_event :638 (A267, R1 UX)
- [x] [KRITISCH] Service-Zeitfenster für Kunden nicht buchbar (kein Shortcode/Block) — src/Admin/ServiceSlotsPage.php:330, Rest/Controller.php:122–149, Plugin.php:73 (A268, R1 UX)
- [x] [KRITISCH] readme.txt ohne Abschnitt „External services“ — readme.txt (A269, R1 Release)
- [x] [KRITISCH] Minifiziertes tweetnacl ohne mitgelieferten Quellverweis — assets/vendor/tweetnacl/nacl-fast.min.js, .distignore:43 (*.md) (A270, R1 Release)
- [x] [KRITISCH] Web-Scanner hängt nächsten Scan an alten Code an – Einlass für falsches Ticket — assets/scanner.js:381, 413–419, 426, 430 (submitToken) (A271, R2 Frontend)
- [x] [KRITISCH] Unklarer Ausgang eines Erstattungsaufrufs als „fehlgeschlagen“ gespeichert → doppelte Erstattung — src/Refunds.php:305–311 (execute), StripeGateway.php:114–140, PayPalGateway.php:178–195, MollieGateway.php:278–296 (A272, R2 API)
- [x] [KRITISCH] Späte Zahlung stellt gültige Tickets für abgesagte oder vergangene Termine aus — src/Orders.php:1031–1078 (confirm_external_payment), OccurrenceCancellation.php:54 (A273, R2 Eigen)
- [ ] [WICHTIG] Einstellung „Stripe-Modus Test/Live“ wirkungslos — src/Settings.php:58, SettingsPage.php:110, StripeGateway.php (A274, R1 Core)
- [ ] [WICHTIG] Block-Editor-Texte und block.json nie übersetzt — src/blocks/*/editor.js, block.json, ci.yml (--skip-block-json) (A275, R1 Core)
- [ ] [WICHTIG] WooCommerce zeigt Terminzeiten in Site- statt Termin-Zeitzone — src/Integrations/WooCommerce/OrderAdapter.php:1068, :122 (A276, R1 Core)
- [x] [WICHTIG] Ehemaliges Einlasspersonal behält Scanner-Zugang nach Rollenentzug — src/Admin/EventsPage.php:499 (assign_staff add_cap), Mobile/Devices.php:137, Access.php:41 (A277, R1 Security)
- [x] [WICHTIG] Entwickler-API-Keys prüfen globale Berechtigung des Inhabers nicht — src/DeveloperKeys.php:153–163 (authorize) (A278, R1 Security)
- [x] [WICHTIG] Aussperr-DoS bei Servicebuchungen über fortlaufende IDs — src/ServiceBookingFlow.php:218–229 (verify_token) (A279, R1 Security)
- [x] [WICHTIG] IPv6-Clients umgehen IP-Ratenlimits (/128 statt /64) — src/ClientIp.php:33–63, RateLimit.php:41, Orders.php:438 (A280, R1 Security)
- [x] [WICHTIG] Ein Scanner-Account sperrt Hilfscode-Eingabe für die ganze Site — src/HelpCode.php:184–186 (resolve) (A281, R1 Security)
- [ ] [WICHTIG] Mollie-API-Aufruf beim Rendern der öffentlichen Eventliste — src/Modules/Mollie.php:94–106, MollieGateway.php:155–169, :309 (A282, R1 Architektur)
- [x] [WICHTIG] Ticketseite und Bestellportal ohne DONOTCACHEPAGE/nocache_headers — src/TicketPage.php:50–53, OrderPortal.php:38–41 (A283, R1 Architektur)
- [ ] [WICHTIG] uninstall.php entfernt WooCommerce-_wpcap_*-Metadaten nicht — uninstall.php:17–50, OrderAdapter.php:38–44, :721–760 (A284, R1 Architektur)
- [ ] [WICHTIG] DB-Drop-ins mit Replikaten (HyperDB/LudicrousDB) nicht berücksichtigt — src/Orders.php:1573, Communications.php:46, RateLimit.php:89 (151× FOR UPDATE) (A285, R1 Architektur)
- [ ] [WICHTIG] Offline-Scanner-PWA funktioniert nicht bei Assets auf CDN-Domain — src/OfflineScanner/AdminPage.php:27–30, assets/offline-scanner/app.js:187–195 (A286, R1 Architektur)
- [ ] [WICHTIG] Netzwerkaktivierung installiert alle Sites synchron — src/Plugin.php:116–131 (activate), :40–46, uninstall.php:53–60 (A287, R1 Architektur/Release)
- [x] [WICHTIG] Verbleibende nicht-atomare Transient-Zähler (A186 unvollständig) — src/Rest/Controller.php:417 (manual_hold_limit), DeveloperKeys.php:168, ServiceBookingFlow.php:222, Partners/Keys.php:237, AffiliateCaptain/Adapter.php:144 (A288, R1 Architektur/Security)
- [ ] [WICHTIG] AGB nur mit „Zustimmung verlangen“ sichtbar, Checkliste meldet trotzdem erledigt — templates/event-card.php:161–168, Settings.php:68, GettingStarted.php:49/107 (A289, R1 UX)
- [ ] [WICHTIG] Checklisten-Links landen auf falschem Settings-Tab — src/Admin/GettingStarted.php:83 (A290, R1 UX)
- [ ] [WICHTIG] Checkliste wertet „WooCommerce aktiv“ als Zahlung eingerichtet — src/Admin/GettingStarted.php:48, :85, :91 (A291, R1 UX)
- [ ] [WICHTIG] „Alles bereit“ ohne Verkaufsseite; Shortcodes/Scanner/PWA im Admin nicht erwähnt — src/Admin/GettingStarted.php:95–159, DevicesPage.php:65 (A292, R1 UX)
- [ ] [WICHTIG] Veröffentlichtes Event verschwindet still, Seite leer (auch „Vorverkauf ab“) — src/PublicEvents.php:166–177, :244–248, EventsPage.php:128–158 (A293, R1 UX)
- [ ] [WICHTIG] Fehlende Zahlungsart fällt erst beim Absenden auf — templates/event-card.php:148–156, Rest/Controller.php:318–322 (A294, R1 UX)
- [ ] [WICHTIG] Webhook-Einrichtung ohne Ereignisliste, Feldhinweise, Verbindungstest — src/Admin/SettingsPage.php:479, StripeGateway.php:31–35, :179–200, PayPalGateway.php:249–288 (A295, R1 UX)
- [ ] [WICHTIG] Kein Absender/Antwortadresse für Käufer-Mails — src/Mail/Mailer.php:215–229 (A296, R1 UX)
- [ ] [WICHTIG] Fehlerhafte Admin-Formulare verwerfen alle Eingaben, generische Meldung — src/Admin/EventsPage.php:39–41, Forms.php:384–392 (A297, R1 UX)
- [ ] [WICHTIG] A099 nur teilweise: Rechtstexte ohne Editor, „Zahlung vor Ort“ im Online-Checkout — src/Admin/SettingsPage.php:603, ManualGateway.php:12 (A298, R1 UX)
- [ ] [WICHTIG] 36 Unterseiten, Module nicht per Oberfläche abschaltbar, doppelte Wege — src/Admin/Menu.php:197–226, Modules.php:60–63 (A299, R1 UX)
- [ ] [WICHTIG] Versionen und Readme widersprechen sich (A170 abgehakt) — pass-captain.php:7/56, readme.txt:7/45/69, CHANGELOG.md:3 (A300, R1 Release)
- [ ] [WICHTIG] Nicht wegklickbarer Secrets-Hinweis auf allen Admin-Seiten — src/Secrets.php:59, :435–437 (A301, R1 Release)
- [ ] [WICHTIG] Inline-<script>/<style> per echo — src/Admin/Menu.php:88/91/263, BoxOffice/Page.php:146, Mail/Admin.php:290, OrderPortal.php:141, Exchange/Portal.php:204 (A302, R1 Release)
- [ ] [WICHTIG] Präfix wp_ in Shortcodes/Hooks und Block-Namespace wp-pass-captain/* (A104 abgehakt) — src/Plugin.php:73/75, Modules/Calendar.php:24, WalletPasses/Service.php:20, Vouchers/TicketService.php, block.json (A303, R1 Release)
- [ ] [WICHTIG] Deutsche Quellsprache vs. translate.wordpress.org (Entscheidung) — languages/pass-captain.pot, readme.txt (A304, R1 Release)
- [ ] [WICHTIG] Plugin Check (PCP) nicht in CI, kein wp.org-SVN-Deploy/Assets/Screenshots — .github/workflows/ci.yml, release.yml (A305, R1 Release)
- [ ] [WICHTIG] Mails in Käufersprache nur mit installiertem Core-Sprachpaket — src/Mail/Mailer.php:340–349 (switch_to_locale) (A306, R1 Release)
- [ ] [WICHTIG] Zahlungsrückkehr-Bestätigung nur über wp_body_open, kein Portal-Link — src/Payments/ReturnNotice.php:9, :24–29, assets/checkout.js:381 (A307, R1 Release)
- [ ] [WICHTIG] Doppelte IDs bei zwei Karten desselben Termins; Rechnungs-Schalter steuert falsche Karte — templates/event-card.php:12/102/108, assets/checkout.js:350 (A308, R2 Frontend)
- [ ] [WICHTIG] Checkout zeigt „Vielen Dank“ bei Nicht-JSON-Antwort, Zahlungsweiterleitung verloren — assets/checkout.js:516–535 (A309, R2 Frontend)
- [ ] [WICHTIG] Zurück aus Stripe/PayPal (bfcache) erzeugt zweite Bestellung — assets/checkout.js:521–531 (A310, R2 Frontend)
- [ ] [WICHTIG] Teilnehmerbereich als Live-Region liest bei jeder Mengenänderung alles vor — templates/event-card.php:83, assets/checkout.js:44–67 (A311, R2 Frontend)
- [ ] [WICHTIG] Saalplan: Fokusverlust alle 30 s und beim Entfernen — assets/seating/picker.js:34, 215–255 (A312, R2 Frontend)
- [ ] [WICHTIG] Monatskalender: Tag in Site-Zeitzone, Uhrzeit/Datum in Termin-Zeitzone; Navigation — src/Modules/Calendar.php:233–273 (A313, R2 Frontend/UX)
- [ ] [WICHTIG] Kasse: Termin-Auswahl sendet Formular bei jeder Pfeiltaste — src/BoxOffice/Page.php:104, ShiftPage.php:316 (A314, R2 Frontend)
- [x] [WICHTIG] WAF-/HTML-Fehler zeigt am Web-Scanner „KEIN EINLASS“ — assets/scanner.js:401–419, offline-scanner/app.js:494–499 (A315, R2 Frontend)
- [ ] [WICHTIG] Kamera-Start doppelt angetippt: erster Stream bleibt an — assets/scanner.js:504–520, offline-scanner/app.js:623–633 (A316, R2 Frontend)
- [ ] [WICHTIG] Wartelisten-Angebotsseite ohne Doppelklick-/Reload-Schutz — src/Waitlists.php:1059, 1067ff. (handle_accept_request) (A317, R2 Frontend)
- [ ] [WICHTIG] Dunkle Themes: Kontrast 1,1–2,6:1 (Kalender, Fehlertexte) — assets/calendar/calendar.css, assets/public.css (A318, R2 Frontend)
- [ ] [WICHTIG] Kontrast PWA-Status unter 4,5:1 — assets/offline-scanner/app.css:8, 34 (A319, R2 Frontend)
- [ ] [WICHTIG] Druck-/Belegseiten mit festem lang="de" — src/Printing/AttendeeList.php:74, NoShowList.php:62, GuestLists/Page.php:158, BoxOffice/ZReport.php:439/466, Service.php:736, Reports/Settlement.php:297 (A320, R2 Frontend)
- [ ] [WICHTIG] A11y-/E2E-Tests decken reale Bedingungen nicht ab — tests/a11y/run.js, keyboard.js (A321, R2 Frontend)
- [ ] [WICHTIG] Checkout-Fehler ohne Spur verschluckt — src/Orders.php:319–347 (catch Throwable) (A322, R2 Support)
- [ ] [WICHTIG] Webhook-Abstürze und Signaturfehler hinterlassen keine Spur — src/Payments/Webhooks.php:18–22, :71–73 (A323, R2 Support)
- [ ] [WICHTIG] Zahlungsstart-Fehler mit Rohtext an Käufer statt an Admin — src/Rest/Controller.php:373–375, StripeGateway.php:286/293, PayPalGateway.php:364/370/412 (A324, R2 Support)
- [ ] [WICHTIG] Grund für Dead Letter geht verloren (immer gleicher Text) — src/Jobs.php:59, Orders.php:1687–1706, Communications.php:67 (A325, R2 Support)
- [ ] [WICHTIG] Dead Letters keiner Bestellung zuordenbar — src/Operations/Queues.php:146, 162–174, Admin/OperationsPage.php:132 (A326, R2 Support)
- [ ] [WICHTIG] Kein zentraler Logger, kein Debug-Modus — src/CapacityCounters.php:546 (einziges error_log), Monitor.php:17 (A327, R2 Support)
- [ ] [WICHTIG] Bestellung ohne Zeitleiste und Korrelations-ID; Mail-Erfolg nicht protokolliert — src/Admin/OrdersPage.php:409–540, Orders.php:1675 (A328, R2 Support)
- [ ] [WICHTIG] Website-Zustand-Info taugt nicht als Support-Export — src/Operations/SiteHealth.php:357ff. (A329, R2 Support)
- [ ] [WICHTIG] Zwei PDF-Engines; Mail-PDF zerstört Nicht-Latin-1-Zeichen, ignoriert Druckvorlagen — src/TicketPdf.php:97/116–119, Printing/PdfWriter.php:152–154 (A330, R2 Support)
- [ ] [WICHTIG] Wichtige Erweiterungspunkte fehlen — src/Orders.php:314/689, Mail/Mailer.php:183–185, TicketPage.php:58 (A331, R2 Support)
- [ ] [WICHTIG] Serien-Vorlage kopiert Teilnehmerfelder und Einlassprüfungen nicht — src/Series/Generator.php:272–286, Series/AdminPage.php:166 (A332, R2 UX)
- [ ] [WICHTIG] Zwei konkurrierende Wege für Terminserien; Ticketart/Feld nicht auf alle Termine anwendbar — src/Admin/EventsPage.php:799, :983–1015 (A333, R2 UX)
- [ ] [WICHTIG] Ticketart nach Verkaufsstart ohne Warnung änderbar (Preis/Steuer) — src/Admin/EventsPage.php:1017–1043 (A334, R2 UX)
- [ ] [WICHTIG] „Tickets erneut senden“ (Admin) entwertet auch übertragene Tickets — src/Admin/OrdersPage.php:555, Orders.php:1780–1806 (A335, R2 UX)
- [ ] [WICHTIG] Bestellung nicht per Name findbar; Bestellnummer Liste ≠ Mail — src/Admin/Queries.php:346, OrdersPage.php:275/292 (A336, R2 UX)
- [ ] [WICHTIG] Bezahlt ohne Ticket (needs_review) im Admin unsichtbar — src/Orders.php:1069–1076, Admin/Menu.php:205 (A337, R2 UX)
- [ ] [WICHTIG] Manueller Check-in aus dem Admin nicht möglich, Web-Scanner nicht auffindbar — src/Admin/TicketsPage.php:262–288, PublicEvents.php:377 (A338, R2 UX)
- [ ] [WICHTIG] Umbuchen auf anderen Termin nur über versteckten Pfad — src/Exchange/AdminPage.php:117–167 (A339, R2 UX)
- [ ] [WICHTIG] Erstatten ohne Betragsvorschau, „Alle“ überstimmt Einzelauswahl still — src/Admin/OrdersPage.php:478/511/525 (A340, R2 UX)
- [ ] [WICHTIG] Wartelisten-Admin ohne Terminspalte, unklare „Rang/Position“ — src/Waitlists/AdminPage.php:116–120, Waitlists.php:989 (A341, R2 UX)
- [ ] [WICHTIG] Frontend: pro Termin vollständiges Checkout-Formular; Kalender-Anker auf nicht gerenderte Karten — src/PublicEvents.php:147–190, templates/event-card.php, Calendar/Ics.php:150 (A342, R2 UX)
- [ ] [WICHTIG] Bezahlte Bestellung ohne Tickets wird nie automatisch nachbearbeitet — src/Orders.php:1089–1092, :1002–1005 (confirm_external_payment), Webhooks.php:121/142–147 (A343, R2 API)
- [ ] [WICHTIG] WooCommerce: Zahlungsbestätigung und Teilerstattung ohne Wiederholung bei „busy“ — src/Integrations/WooCommerce/OrderAdapter.php:669–674, :797–803 (A344, R2 API)
- [ ] [WICHTIG] Offline-Scanner: Serverfehler löscht offline erfolgten Einlass endgültig — src/Mobile/Api.php:188–199 (sync), assets/offline-scanner/app.js:529–535, Checkin.php:295 (A345, R2 API)
- [x] [WICHTIG] Stripe: Vollerstattungs-Webhook während eigener Erstattung → Erstattung über 0 € mit Gutschrift — src/Payments/StripeGateway.php:218–221, Webhooks.php:212–229, Refunds.php:436–455 (A346, R2 API)
- [x] [WICHTIG] Stripe-Teilerstattungen über „neueste Erstattung“ statt Ereignis-Erstattung — src/Payments/StripeGateway.php:223–233 (A347, R2 API)
- [x] [WICHTIG] Später scheiternde Erstattungen beim Anbieter nie erkannt — src/Payments/StripeGateway.php:136, :197–203; MollieGateway.php:255–263; PayPalGateway.php:191 (A348, R2 API)
- [ ] [WICHTIG] PayPal-Rückkehr meldet „nichts abgebucht“, obwohl abgebucht sein kann — src/Payments/PayPalGateway.php:108–116, :136–147, ReturnNotice.php:17 (A349, R2 API)
- [x] [WICHTIG] Hängende Erstattungen ohne Referenz weder sichtbar noch auflösbar — src/Refunds.php:276–280, :389–391 (repair_stuck), Operations/Queues.php, OrdersPage.php:465 (A350, R2 API)
- [ ] [WICHTIG] Mollie Klarna/Riverty: authorized wird nie gecaptured (PLAUSIBEL) — src/Modules/Mollie.php:22, MollieGateway.php:222–251 (A351, R2 API)
- [ ] [WICHTIG] Kein Abgleich-Job Anbieter ↔ lokaler Stand (processing/failed/pending) — Payments/*, Refunds.php (A352, R2 API)
- [x] [WICHTIG] Rabattcode-Limit durch späte Zahlung umgangen — src/Orders.php:1559 (expire_holds → release_for_order), Campaigns.php:162 (commit_for_order) (A353, R2 Eigen)
- [x] [WICHTIG] Auto-Erstattung bei Terminabsage scheitert bei jedem eingecheckten Ticket bzw. Rolle ohne Recht/2FA — src/OccurrenceCancellation.php:122, Passes/Cancellation.php:165 (A354, R2 Eigen)
- [x] [WICHTIG] DSGVO-Löschung anonymisiert Bestellung mit offener Zahlung – spätere Tickets an Platzhalter — src/Privacy.php:296–307, :403 (A355, R2 Eigen)
- [x] [WICHTIG] Abgesagter Termin lässt sich wieder auf „Geplant“ setzen; widerrufene Tickets blockieren Kontingent — src/Admin/EventsPage.php:190, :918 (A356, R2 Eigen)
- [x] [WICHTIG] Nach Widerruf/Umtausch zahlt Erstattung zu viel aus, Bestellung wird nie „erstattet“ — src/Refunds.php:587–589 (calculate), :357–360, :486 (A357, R2 Eigen)
- [ ] [WICHTIG] „Bezahlt, ohne Kontingent“ weder erstattbar noch abschließbar — src/Refunds.php:48/172, Admin/OrdersPage.php:80 (A358, R2 Eigen)
- [ ] [OPTIMIERUNG] Toter Code mit festem EUR auf jedem Frontend-Request — src/PublicEvents.php:10–11 (A359, R1 Core)
- [ ] [OPTIMIERUNG] Events-Block: Abhängigkeiten wp-components/wp-i18n fehlen, Attribute number statt integer — src/blocks/events/editor.asset.php:4, block.json (A360, R1 Core)
- [ ] [OPTIMIERUNG] PHP 8.5: curl_close() deprecated; APNs umgeht WP-HTTP-Proxy-Konstanten — src/WalletPasses/ApnsClient.php:55 (A361, R1 Core/Architektur)
- [ ] [OPTIMIERUNG] Rollennamen übersetzt in der DB gespeichert — src/Installer.php:1104–1124, :1080 (A362, R1 Core)
- [ ] [OPTIMIERUNG] Falscher Übersetzerkommentar — src/Orders.php:1076 (confirm_external_payment) (A363, R1 Core)
- [ ] [OPTIMIERUNG] Wartelisten-Queue ohne Lock; GET_LOCK-Namen nicht je Installation eindeutig — src/Waitlists.php:173–184, :207–208, :247–248, Allocations/BuyerLimits.php:145 (A364, R1 Core)
- [ ] [OPTIMIERUNG] Web-Scanner-REST gibt interne IDs fremder Events zurück — src/Checkin.php:356–361, Rest/Controller.php:907–915 (A365, R1 Security)
- [ ] [OPTIMIERUNG] SMS-HTTP-Callbacks ohne Replay-Schutz — src/Sms/HttpProvider.php:101–107, Sms/Service.php:481 (A366, R1 Security)
- [ ] [OPTIMIERUNG] ABSPATH-Guard fehlt in ausgelieferten PHP-Dateien — src/Integrations/ConnectorInterface.php, src/blocks/*/editor.asset.php (A367, R1 Security)
- [ ] [OPTIMIERUNG] Einlassbereiche auf jeder Frontend-Seite für Check-in-Nutzer abgefragt — src/Modules/Admission.php:22, Admission/Rest.php:82–87 (A368, R1 Architektur)
- [ ] [OPTIMIERUNG] Erstattungs-Badge startet volle Abfrage auf jeder Admin-Seite; Index auf refunds.status fehlt — src/Admin/Menu.php:198, Queries.php:474–482 (A369, R1 Architektur)
- [ ] [OPTIMIERUNG] Öffentliche REST-GETs mit Token ohne no-store — src/Rest/Controller.php:34–38, :469–479, ServiceBookingFlow.php:233–265 (A370, R1 Architektur)
- [ ] [OPTIMIERUNG] Globaler CSS-Selektor [aria-invalid] mit !important — assets/public.css:5 (A371, R1 Architektur)
- [ ] [OPTIMIERUNG] Deinstallation lässt wpcap_approve_comps zurück — src/Modules/GuestLists.php:131–141, uninstall.php:41 (A372, R1 Architektur)
- [ ] [OPTIMIERUNG] Ticketausstellung/PDF/Mail synchron im Webhook; ServiceBookingFlow per wp_mail — src/Payments/Webhooks.php:121, Orders.php:1569–1680, ServiceBookingFlow.php:190/205 (A373, R1 Architektur)
- [ ] [OPTIMIERUNG] Wallet-Push inline auf shutdown (bis 20 Tickets) — src/WalletPasses/Service.php:315–331 (A374, R1 Architektur)
- [ ] [OPTIMIERUNG] Website-Zustand ohne Test für anonymes REST und Proxy-Fehlkonfiguration — src/Operations/SiteHealth.php:80–88 (A375, R1 Architektur)
- [ ] [OPTIMIERUNG] Saalplan-Verknüpfungen und Käuferlimits laden ganze Historie je Listing — src/Seating/Layouts.php:371, Allocations/BuyerLimits.php:51 (A376, R1 Architektur)
- [ ] [OPTIMIERUNG] Uneinheitliche Begriffe (Veranstalter/Partner, Ticketkategorie/-art, Benutzer-ID-Feld) — src/Partners/AdminPage.php:224, ServiceSlotsPage.php:227/253, EventsPage.php:801–804 (A377, R1 UX)
- [ ] [OPTIMIERUNG] Tab „Allgemein“ überladen — src/Admin/SettingsPage.php:36–200 (A378, R1 UX)
- [ ] [OPTIMIERUNG] Keine Hilfe-Tabs, Verweise auf nicht ausgelieferte docs/ und privates Repo, kein Action-Link — src/Admin/SettingsPage.php:156/184, Partners/AdminPage.php:475, BoxOffice/Page.php:261 (A379, R1 UX)
- [ ] [OPTIMIERUNG] DATEV-Export ohne Kontenrahmen-Einstellung (SKR03 fest) — src/Invoices.php:790–805, Reports/Page.php:135 (A380, R1 UX)
- [ ] [OPTIMIERUNG] Destruktive Aktionen ohne Bestätigung (Zeitfenster löschen, Personal entfernen) — src/Admin/ServiceSlotsPage.php:412–415, EventsPage.php:945 (A381, R1 UX)
- [ ] [OPTIMIERUNG] load_plugin_textdomain für wp.org überflüssig (PCP) — pass-captain.php:83 (A382, R1 Release)
- [ ] [OPTIMIERUNG] Plurale mit „(e)/(s)“ und zusammengesetzte Sätze — src/PublicEvents.php:50/103/106, OrderAdapter.php:915, GuestLists/Page.php:123, Modules/Sms.php:473, TicketPage.php:58 (A383, R1 Release)
- [ ] [OPTIMIERUNG] Feste deutsche Datumsformate (44× d.m.Y) in Mails/Belegen — src/Notifications.php:133, Orders.php:1759, Reminders/Placeholders.php:35, Printing/InvoicePdf.php:34 (A384, R1 Release)
- [ ] [OPTIMIERUNG] Readme-Cron-Empfehlung für Multisite falsch — readme.txt:49–51 (A385, R1 Release)
- [ ] [OPTIMIERUNG] Update URI / Slug- und Konto-Sicherung vor Verbreitung — pass-captain.php Header (A386, R1 Release)
- [ ] [OPTIMIERUNG] Lizenz-/Premium-Updater-Pläne (A225–A236) widersprechen wp.org-Guidelines 5/8 — Planung (A387, R1 Release)
- [ ] [OPTIMIERUNG] Offline-Scanner-PWA nur de/en — assets/offline-scanner/i18n.js:12 (A388, R1 Release)
- [ ] [OPTIMIERUNG] Readme-Changelog ohne Inhalt, Umbenennung fehlt — readme.txt:53–66 (A389, R1 Release)
- [ ] [OPTIMIERUNG] Kein RTL (dir, logische CSS-Eigenschaften) — src/TicketPage.php:58, OrderPortal.php:116, Waitlists.php:1064, Invoices.php:536, public.css, picker.css (A390, R2 Frontend)
- [ ] [OPTIMIERUNG] CSS erst beim Rendern; Block-Vorschau im Editor ungestylt — src/PublicEvents.php:162, Modules/Calendar.php:148, blocks/*/block.json (A391, R2 Frontend)
- [ ] [OPTIMIERUNG] Saalplan heilt sich nach fehlgeschlagenem Erstladen nicht — assets/seating/picker.js:42–55 (A392, R2 Frontend)
- [ ] [OPTIMIERUNG] Kalender-Links: sichtbarer Text nicht im zugänglichen Namen — src/Modules/Calendar.php:266–268 (A393, R2 Frontend)
- [ ] [OPTIMIERUNG] Monatskalender mobil ohne Datumskontext, Tabellensemantik verloren — assets/calendar/calendar.css (max-width 600px) (A394, R2 Frontend)
- [ ] [OPTIMIERUNG] Fokusverlust nach asynchronen Portal-/Wartelisten-Aktionen — src/OrderPortal.php:145ff., assets/checkout.js:571 (A395, R2 Frontend)
- [ ] [OPTIMIERUNG] Admin-Menü-Überschriften im Farbschema „Hell“ unlesbar — src/Admin/Menu.php:93 (A396, R2 Frontend)
- [ ] [OPTIMIERUNG] [wpcap_passes] ohne Stylesheet, Kauflink, Überschriftenebene — src/Passes/Display.php:141–156 (A397, R2 Frontend)
- [ ] [OPTIMIERUNG] PWA viewport-fit=cover ohne Safe-Area-Abstände — assets/offline-scanner/index.html:5, app.css (A398, R2 Frontend)
- [ ] [OPTIMIERUNG] Nur ein WP-CLI-Befehl — src/CapacityCounters.php:71 (A399, R2 Support)
- [ ] [OPTIMIERUNG] Admin-Alarme per direktem wp_mail an admin_email — src/Payments/Webhooks.php:238, Orders.php:1076, Refunds.php:131/135/220, u. a. (12 Stellen) (A400, R2 Support)
- [ ] [OPTIMIERUNG] Riesenmethoden (Installer::install ~810, Orders::create ~344, register_routes ~222 Zeilen) — src/Installer.php:17, Orders.php:23, Rest/Controller.php:20 (A401, R2 Support)
- [ ] [OPTIMIERUNG] Statische Klassen, keine definierte öffentliche API — 206/224 Klassen (A402, R2 Support)
- [ ] [OPTIMIERUNG] Mehrere Geldformatierer (A200 zentralisiert – Rest prüfen, Box-Office-JS 2 Dezimalen) — src/Modules/Sms.php:476, assets/boxoffice/pos.js, shift.js (A403, R2 Support)
- [ ] [OPTIMIERUNG] Toter Code — src/Refunds.php:656 (tax_for_tickets), OfflineScanner/Assignments.php:75, BoxOffice/Service.php:490, GuestLists/Service.php:539 (A404, R2 Support)
- [ ] [OPTIMIERUNG] Filter wpcap_client_ip wirkt nur auf Orders::client_ip — src/Orders.php:440, RateLimit.php:42 (A405, R2 Support)
- [ ] [OPTIMIERUNG] Drei Template-Hooks nicht in HOOKS.md; kein CI-Abgleich — templates/event-card.php:13/22/85 (A406, R2 Support)
- [ ] [OPTIMIERUNG] Nur event-card.php per Theme überschreibbar — src/PublicEvents.php:179 (A407, R2 Support)
- [ ] [OPTIMIERUNG] Hart codierte Laufzeitwerte (Timeouts, Backoff, Claims, Limits, Service-Hold 10 min, A4, Farben) — src/Payments/Webhooks.php:52, Jobs.php:27/58/59, Communications.php:67, Orders.php:96, Rest/Controller.php:324/902/505/525/537, u. a. (A408, R2 Support)
- [ ] [OPTIMIERUNG] „Zahlungspflichtig bestellen“ und 0,00 € bei kostenlosen Tickets — templates/event-card.php:169 (A409, R2 UX)
- [ ] [OPTIMIERUNG] Wartelisten-Anmeldung nennt keine Frist; max vom ersten Typ — templates/event-card.php:177/190 (A410, R2 UX)
- [ ] [OPTIMIERUNG] Listen ohne WP_List_Table (Bulk, Sortierung, Views, Bildschirmoptionen) — src/Admin/OrdersPage.php:257/289, TicketsPage.php:207, EventsPage.php:732 (A411, R2 UX)
- [ ] [OPTIMIERUNG] Umtausch-Verlauf in UTC, Rohwerte — src/Exchange/AdminPage.php:138 (A412, R2 UX)
- [ ] [OPTIMIERUNG] Audit-Log: Filter UTC, Anzeige Ortszeit, Rohcodes, keine Links — src/Admin/AuditLogPage.php:39–40, :104–115 (A413, R2 UX)
- [ ] [OPTIMIERUNG] Tickets-Seite verliert Filter/Seite nach Aktionen — src/Admin/TicketsPage.php:157–160 (A414, R2 UX)
- [ ] [OPTIMIERUNG] Unklare Labels (Abgesagt/Storniert, Provider-Keys, „Schlüssel“, Kontingente, Grund-Feld, Checklisten-Links) — src/Admin/Forms.php:501, OrdersPage.php:298/370, EventsPage.php:803/807/1105, TicketsPage.php:260, GettingStarted.php:129–143 (A415, R2 UX)
- [ ] [OPTIMIERUNG] SMS: Doppelversand bei Timeout/Absturz — src/Sms/TwilioProvider.php:44–67, Sms/Service.php:312/354 (A416, R2 API)
- [ ] [OPTIMIERUNG] SMS-Warteschlange fehlt unter Betrieb/Warteschlangen; held verfällt still — src/Operations/Queues.php:29–61, Sms/Service.php:314 (A417, R2 API)
- [ ] [OPTIMIERUNG] PayPal capturet per Webhook auch stornierte Bestellungen — src/Payments/PayPalGateway.php:249–257 (A418, R2 API)
- [ ] [OPTIMIERUNG] Gewonnener Stripe-Chargeback (funds_reinstated) nicht ausgewertet — src/Payments/StripeGateway.php:197–203 (A419, R2 API)
- [ ] [OPTIMIERUNG] Checkout-Idempotenz vergleicht Warenkorb-Inhalt nicht — src/Orders.php:56–58, assets/checkout.js:472 (A420, R2 API)
- [ ] [OPTIMIERUNG] REST-Eingaben fast ohne Schema; negative/gebrochene Mengen still umgedeutet — src/Rest/Controller.php (3/42 Routen mit args), Orders.php:72–73 (absint) (A421, R2 API/Eigen)
- [ ] [OPTIMIERUNG] Wallet-Sync „alles oder nichts“, 429 nicht gesondert — src/WalletPasses/Service.php:321–388 (A422, R2 API)
- [ ] [OPTIMIERUNG] Google-Wallet-Token nicht an Zugangsdaten gebunden — src/WalletPasses/GoogleProvider.php:124–136 (A423, R2 API)
- [ ] [OPTIMIERUNG] Fehlend: Rechnung/Bestelllink erneut senden; Portalzugang nur 30 Tage — src/Mail/Types.php, Orders.php:241, OrderPortal.php:137 (A424, R2 Eigen)
- [ ] [OPTIMIERUNG] Fehlend: Vorverkaufs-/Servicegebühr — src/Orders.php:178–203 (A425, R2 Eigen)
- [ ] [OPTIMIERUNG] Fehlend: Event/Termin duplizieren — src/Admin/Menu.php:14–60 (A426, R2 Eigen)

## Audit Runde 4 – Nachtrag Datenbank & Performance (13.11.2026)

- [x] [KRITISCH] Massen-PDF „mit neuen Codes“ entwertet Tickets bei Timeout/Speichermangel — src/Printing/Templates.php:318–348, PdfWriter.php:119, Printing/Page.php:143 (A427, R2 DB/Performance)
- [x] [KRITISCH] Termin-/Eventabsage und Verschiebung synchron im Admin-Request, nicht wiederaufnehmbar — src/OccurrenceCancellation.php:42–134, Admin/EventsPage.php:283–298, Passes/Cancellation.php:105–150, Notifications.php:72–82/190–205 (A428, R2 DB/Performance)
- [x] [KRITISCH] Checkout-Kapazitätsprüfung scannt alle historischen Reservierungen unter Kapazitäts-Locks — src/Orders.php:600, :613 (capacity_error_counted), CapacityCounters.php:132 (A429, R2 DB/Performance)
- [x] [WICHTIG] Kapazitäts-Reconcile-Cron alle 5 min über gesamte Reservierungshistorie — src/CapacityCounters.php:486–516 (A430, R2 DB/Performance)
- [ ] [WICHTIG] Mollie-Webhook macht vor dem Rate-Limit einen Full Scan auf wpcap_orders — src/Mollie/MollieGateway.php:128–131, :190–194 (A431, R2 DB/Performance)
- [ ] [WICHTIG] Admin-Ticketliste wertet last_checkin_at für alle Tickets aus — src/Admin/Queries.php:434 (search_tickets) (A432, R2 DB/Performance)
- [ ] [WICHTIG] Check-in-Protokoll ohne passende Indizes — src/Admin/Queries.php:447–470 (A433, R2 DB/Performance)
- [ ] [WICHTIG] Umsatzbericht, Buchhaltungs-/DATEV-Export, Abrechnungen mit N+1 und alles im Speicher — src/Reports/Data.php:163–181, Invoices.php:700–716, Reports/Settlement.php:158, Partners/Statement.php:46 (A434, R2 DB/Performance)
- [ ] [WICHTIG] Partner-Abrechnung verarbeitet jedes Mal die gesamte Historie — src/Partners/Settlements.php:105–130 (A435, R2 DB/Performance)
- [x] [WICHTIG] GROUP_CONCAT-Abschneidung bei der Pass-Absage — src/Passes/Cancellation.php:119, :165 (A436, R2 DB/Performance)
- [ ] [WICHTIG] Webhook-Aufräumjob alle 5 min Full Scan, DELETE ohne LIMIT — src/Webhooks/Dispatcher.php:302–305, Modules/Webhooks.php:31 (A437, R2 DB/Performance)
- [ ] [WICHTIG] Lasttests ohne Historie und ohne Admin-/Massenoperationen — tests/load (A438, R2 DB/Performance)
- [ ] [OPTIMIERUNG] Retention-Schritt checkin_staff ohne Index — src/Privacy/Retention.php:345 (A439, R2 DB/Performance)
- [ ] [OPTIMIERUNG] Statistik-Endpunkt der Scanner-App zählt bei jedem Aufruf alles — src/Mobile/Api.php:100–105 (A440, R2 DB/Performance)
- [ ] [OPTIMIERUNG] Ticketsuche mit sechs LIKE %x% über 6-Tabellen-Join — src/Admin/Queries.php:416–433 (A441, R2 DB/Performance)
- [ ] [OPTIMIERUNG] Bestellportal: eine Antworten-Query pro Ticket — src/OrderPortal.php:76–83 (A442, R2 DB/Performance)
- [ ] [OPTIMIERUNG] Offline-Paket: Throttle-Prüfung vor dem Lock — src/OfflineScanner/Package.php:57–100 (A443, R2 DB/Performance)
- [x] [OPTIMIERUNG] Tabellen ohne Aufräumung (reservations, jobs, seat_holds, payment_events) — src/Privacy/Retention.php (A444, R2 DB/Performance)
- [ ] [OPTIMIERUNG] WooCommerce-Absagenotiz sucht je Bestellung per meta_value (PLAUSIBEL O(n²)) — src/Integrations/WooCommerce/OrderAdapter.php:948–953 (A445, R2 DB/Performance)

## Audit Runde 5 – Lifecycle, Editor, Multisite/i18n, Caching/CDN, QA, Eigen-Audit (13.11.2026, Snapshot ccf2530)

- [x] [KRITISCH] Vorverkaufsstart und Eventänderungen werden nie aus dem Seitencache entfernt (kein Purge) — src/PublicEvents.php:154, :244, Modules/Calendar.php:153/219 (A446, R3 Caching)
- [x] [KRITISCH] Speichern eines Veranstalters löst still alle Event-Verknüpfungen jenseits der ersten 500 Events — src/Partners/AdminPage.php:313–317, Admin/Queries.php:288–292 (LIMIT 500), Partners/Repository.php:263–276 (set_events) (A447, R3 Multisite/i18n/Skalierung)
- [x] [KRITISCH] Event-Umhängen zwischen Veranstaltern führt zur doppelten Auszahlung — src/Partners/Settlements.php:105 (compute), Partners/Repository.php:263 (set_events) (A448, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Block-Unterstützungen fehlen auf der Website (keine get_block_wrapper_attributes) — src/blocks/events/render.php:4, calendar/render.php:6, block.json (A449, R3 Editor)
- [ ] [WICHTIG] Bestell-/Wartelistenformular ohne method/action: ohne JS personenbezogene Daten per GET in der URL — templates/event-card.php:51, :174, assets/checkout.js:450/548 (A450, R3 Editor)
- [ ] [WICHTIG] ServerSideRender-Vorschau interaktiv statt in Disabled — src/blocks/events/editor.js:20, calendar/editor.js:29 (A451, R3 Editor)
- [ ] [WICHTIG] Kalender-Monatsnavigation nutzt get_permalink() des globalen Posts; zwei Kalender blättern gemeinsam — src/Modules/Calendar.php:245, :153 (A452, R3 Editor)
- [ ] [WICHTIG] Event-Auswahl im Block auf 500 Termine begrenzt; gespeicherte Auswahl irreführend als „Alle Events“ — src/PublicEvents.php:339 (block_choices), blocks/events/editor.js:11–16 (A453, R3 Editor)
- [ ] [WICHTIG] Editor-Skripte ohne Cache-Busting (Versionsnummer nicht erhöht) — src/blocks/*/editor.asset.php:5 (A454, R3 Editor)
- [ ] [WICHTIG] Keine Editor-/Builder-Tests; keine eigenen Event-URLs/Templates — tests/ (A455, R3 Editor)
- [ ] [WICHTIG] Verfügbarkeit/Kaufzustand nur statisch im HTML, nie nachgeladen (Warteliste fehlt, veraltete Knappheitsangaben) — templates/event-card.php:21/75/141/173, PublicEvents.php:173, EventCard.php (A456, R3 Caching)
- [ ] [WICHTIG] Nach AGB-Änderung ist jede gecachte Kaufseite blockiert (409, Reload hilft nicht) — templates/event-card.php:162, Orders.php:64–67 (A457, R3 Caching)
- [ ] [WICHTIG] Zeitabhängige Preise still neu berechnet, Käufer sieht Abweichung erst beim Anbieter (PAngV/§312j) — src/PublicEvents.php:280, Series/Pricing.php:257–266, assets/checkout.js:502–533 (A458, R3 Caching)
- [ ] [WICHTIG] Stornoanfrage im Portal scheitert immer für angemeldete Käufer (Nonce/Cookie) — src/PortalSelfService.php:557, :458, OrderPortal.php:144 (A459, R3 Caching)
- [ ] [WICHTIG] Scanner-Kopplung und Hilfscode am Einlass je öffentlicher IP limitiert (Venue-NAT) — src/Mobile/Api.php:50, HelpCode.php:186 (A460, R3 Caching)
- [ ] [WICHTIG] Apple-Wallet-Webservice antwortet bei Ratenlimit mit 401 statt 429 — src/WalletPasses/WebService.php:53, :65 (A461, R3 Caching)
- [ ] [WICHTIG] Token-Seiten ohne DONOTCACHEPAGE; Angebots-Nonce kürzer als Angebot (erweitert A283) — src/Waitlists.php:83/870/889/1053/1075, Printing/InvoicePdf.php:288, Payments/ReturnNotice.php:23–29 (A462, R3 Caching)
- [ ] [WICHTIG] Rollback auf 1.4.0 legt alle Zahlungs-Zugangsdaten still (Secrets-Vault) — src/Secrets.php:270–293, docs/OPERATIONS.md:269–278 (A463, R3 Lifecycle)
- [ ] [WICHTIG] Rollback und erneutes Update: Kapazitätszähler bleiben falsch → Überverkauf — src/CapacityCounters.php:154–158, :519–520 (A464, R3 Lifecycle)
- [ ] [WICHTIG] Neuer Standard Einlassfenster gilt nach Update ohne Hinweis/Pinning — src/Settings.php:111, Admission/Engine.php:86–99, Checkin.php:140/214 (A465, R3 Lifecycle)
- [ ] [WICHTIG] Dauerhaft fehlschlagendes Schema-Upgrade läuft endlos und teuer — src/Installer.php:797–803, :1017–1050, Plugin.php:18 (A466, R3 Lifecycle)
- [ ] [WICHTIG] Deinstallation löscht Optionen per Roh-SQL, persistenter Objekt-Cache bleibt — uninstall.php:36 (A467, R3 Lifecycle)
- [ ] [WICHTIG] rebuild_all() der Kapazitätszähler synchron im Upgrade-Request über die gesamte Historie — src/CapacityCounters.php:608–615, Installer.php:809 (A468, R3 Lifecycle)
- [ ] [WICHTIG] Rollback-Drill prüft keine Zustände außerhalb des Schemas (Secrets, Zähler, Optionen, Standardwerte) — tests/restore/drill.sh (A469, R3 Lifecycle)
- [ ] [WICHTIG] Zugriffs-Scope als netzwerkweite User-Meta mit Site-lokalen IDs — src/Access.php:30/125/170–181, Admin/UserScope.php:26–72, BoxOffice/Page.php:271 (A470, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Eventleitung holt beliebige Netzwerk-Benutzer in die Site (add_cap) und verrät Kontoexistenz — src/Admin/EventsPage.php:482–505, StaffAssignments.php:51–57, Admission/RulesPage.php:87–97 (A471, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Offline-Warteschlange des Web-Scanners zwischen Subdirectory-Sites geteilt → Scans gehen verloren — assets/scanner.js:15–35, :321–345, Privacy.php:104–106 (A472, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Offline-PWA mit einem Datenbestand pro Origin für alle Sites — assets/offline-scanner/app.js:19, :45–53, :672–731, OfflineScanner/AdminPage.php:27–31 (A473, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Ticket-Mail mischt Sprachen: PDF, Rechnung, Wallet-Text in Request- statt Käufersprache — src/Orders.php:1788–1806, WalletPasses/Service.php:183–199, Printing/InvoicePdf.php:293–306, AppleProvider.php:61–80 (A474, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Google-Wallet-IDs hängen an home_url(): Domainwechsel trennt Pässe still ab — src/WalletPasses/GoogleProvider.php:26–28, :113–117, Service.php:340–345 (A475, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Event-Detailseite rendert alle Termine ungepaginiert mit Umsatz-Query je Termin; Terminlisten ohne Grenze — src/Admin/EventsPage.php:834–875, AssignmentsPage.php:131, Admission/RulesPage.php:228, OfflineScanner/AdminPage.php:130, Series/AdminPage.php:142 (A476, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Teilnehmer-/No-Show-Liste baut Großevents komplett im Speicher (IN mit 100k IDs) — src/Printing/AttendeeList.php:34–63, Printing/Page.php:151–170 (A477, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Byteweises substr auf UTF-8 leert Audit-Details und lässt DB-Schreibvorgänge scheitern — src/Admin/AuditLogPage.php:71–77, Refunds.php:114/211/263/333 (A478, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Keine Cross-Site-Tests für Zugriff, Scanner-Speicher, Benutzerzuweisung — tests/ (A479, R3 Multisite/i18n/Skalierung)
- [ ] [WICHTIG] Mitgliedspass: uses_total/uses_per_day bei gleichzeitigen Scans überschritten — src/Memberships.php:46, :54–55 (redeem) (A480, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Kassen-/Support-Rolle können unbegrenzte Gratis-Mitgliedspässe ausstellen — src/Rest/Controller.php:630, :566 (A481, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Mitgliedspass-Einlösung ignoriert Kapazität, Terminstatus, Einlassstatistik — src/Memberships.php:51 (A482, R3 Eigen (Deep Dive 2))
- [x] [WICHTIG] Käuferlimit mit verspäteter Zahlung umgehbar (erweitert A353) — src/Allocations/BuyerLimits.php:217, :375 (A483, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Käuferlimit per Plus-Adresse umgehbar — src/Allocations/BuyerLimits.php:96 (normalize_email) (A484, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Rollstuhlplätze werden bei automatischer Platzvergabe zuerst vergeben; keine Begleitplätze — src/Seating/Reservations.php:229 (free_seats) (A485, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Verfügbarkeit sitzplatzgebundener Ticketarten ignoriert den Saalplan — src/Modules/Seating.php:27–29 (A486, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Neue Saalplan-Version ohne Zone macht Ticketart still unverkäuflich — src/Seating/Layouts.php:525 (publish/upgrade_links) (A487, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Backoffice-/Absage-Erstattungen von Kassenbestellungen in fremde Kassenschicht gebucht — src/BoxOffice/Shifts.php:622, :646, OccurrenceCancellation.php:121 (A488, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Schicht wird während laufendem Verkauf geschlossen: Tickets ohne Beleg und Z-Bericht — src/BoxOffice/Shifts.php:420 (close), Service.php:372/445 (A489, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Nach Umtausch kein Wallet-Pass mehr (erweitert A091) — src/WalletPasses/Service.php:98, :210, Exchange/Service.php:715/851 (A490, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Stammdaten ohne zeitliche Versionierung (Veranstalter-Zuordnung, Konditionen, Zonen) — Partners/, Seating/ (A491, R3 Eigen (Deep Dive 2))
- [ ] [WICHTIG] Anonyme Fatal Errors durch Array statt String in E-Mail-Feldern (erweitert A421; gegen A194-Stand prüfen) — src/Rest/Controller.php:31, Orders.php:25/510/1461, Waitlists.php:98, ServiceBookings.php:26, Partners/Sales.php:534–538 (A492, R3 QA)
- [ ] [WICHTIG] Überlange gültige Teilnehmer-E-Mail → bezahlte Bestellung dauerhaft ohne Tickets; Käufer >190 Zeichen endlos 503 — src/Orders.php:509–515, :374–377, :1750, :263–266, Installer.php:202/525 (A493, R3 QA)
- [ ] [WICHTIG] Byte-Kürzung von Freitext erzeugt ungültiges UTF-8 – Speichern dauerhaft unmöglich — src/Refunds.php:114/211/263/514, PortalSelfService.php:422–424, Partners/Repository.php:123/143/202, Partners/Sales.php:724 (A494, R3 QA)
- [ ] [WICHTIG] CSV-Formel-Injection trotz csv_cell(), weil fputcsv-Escape Backslash ist — src/Reports/Scope.php:78 (A495, R3 QA)
- [ ] [WICHTIG] Ticketübertragung trotz offener Stornierungsanfrage: Empfänger verliert Ticket, Käufer bekommt Geld — src/Orders.php:1472–1488, TicketAssignments.php:22–35 (A496, R3 QA)
- [ ] [WICHTIG] Namen mit „<“ und „&“ erscheinen als Entities in ICS, PDF, Wallet, Text-Mails, Betreff, CSV — src/Admin/Forms.php:289–292, Calendar/Ics.php:127, Mail/Types.php:340 (A497, R3 QA)
- [ ] [WICHTIG] Längen-/Typverträge Eingabe↔Schema ungetestet; Test-wpdb und Sanitize-Stubs bilden echtes WP nicht ab — tests/integration/bootstrap.php (A498, R3 QA)
- [ ] [OPTIMIERUNG] Shortcode/Block geben im RSS-Feed und REST content.rendered das Bestellformular aus — src/PublicEvents.php:147ff., Modules/Calendar.php:149ff. (A499, R3 Editor)
- [ ] [OPTIMIERUNG] checkout.js initialisiert nur beim Laden; per AJAX eingefügte Formulare unvollständig — assets/checkout.js:200–201, :449 (A500, R3 Editor)
- [ ] [OPTIMIERUNG] Blöcke ohne keywords, example, Shortcode-Transform — src/blocks/*/block.json (A501, R3 Editor)
- [ ] [OPTIMIERUNG] Kalender-Block: Event-ID als Zahlenfeld, limit nicht einstellbar — src/blocks/calendar/editor.js:21–26, block.json:10–13 (A502, R3 Editor)
- [ ] [OPTIMIERUNG] Ungültige Shortcode-Attribute fallen still auf „alle Events“; shortcode_atts-Filter-Tag immer Altname — src/PublicEvents.php:148–152 (A503, R3 Editor)
- [ ] [OPTIMIERUNG] Doppelte IDs auch durch Sidebar/Archiv/doppeltes the_content (erweitert A308) — templates/event-card.php:12, Modules/Calendar.php:131 (A504, R3 Editor)
- [ ] [OPTIMIERUNG] Öffentliche REST-GETs mit Live-Daten ohne Cache-Control (erweitert A370) — src/Rest/Controller.php:21/107/122/176, WalletPasses/WebService.php:83 (A505, R3 Caching)
- [ ] [OPTIMIERUNG] Offline-PWA ohne Asset-Versionierung — assets/offline-scanner/sw.js:8, index.html:13/80–82 (A506, R3 Caching)
- [ ] [OPTIMIERUNG] Einmalige Geheimnisse (API-Key, Kopplungscode) hängen an Transient zwischen POST und Redirect — src/Admin/DeveloperKeysPage.php:51/107, Partners/AdminPage.php:113/487, Admin/DevicesPage.php:28/54 (A507, R3 Caching)
- [x] [OPTIMIERUNG] Web-Scanner-Shortcode-Seite ohne DONOTCACHEPAGE — src/PublicEvents.php:378–394 (A508, R3 Caching)
- [x] [OPTIMIERUNG] Betriebsdoku ohne CDN-/WAF-/Seitencache-Ausnahmeregeln — docs/OPERATIONS.md, SECURITY.md:66, readme.txt (A509, R3 Caching)
- [ ] [OPTIMIERUNG] Deaktivierung verwirft geplante DSGVO-Löschung von Wallet-Registrierungen — src/Privacy.php:482–484, Plugin.php:169–178 (A510, R3 Lifecycle)
- [ ] [OPTIMIERUNG] Kein eigener PHP-/WP-Versionsguard; Composer-Platform-Check legt Site lahm — pass-captain.php:61–63, composer.json, build/release.sh:38 (A511, R3 Lifecycle)
- [ ] [OPTIMIERUNG] Aktivierung installiert ohne Upgrade-Lock — src/Plugin.php:121–137, Installer.php:1073 (A512, R3 Lifecycle)
- [ ] [OPTIMIERUNG] Schema-Prüfung erkennt fehlgeschlagene Index-Änderungen nicht — src/Installer.php:794–806, :955–958 (A513, R3 Lifecycle)
- [ ] [OPTIMIERUNG] Multisite-Uninstall löscht netzwerkweite User-Meta praktisch nie; Reste in sitemeta/Action Scheduler — uninstall.php:59–67, LegacySlug.php:36 (A514, R3 Lifecycle)
- [ ] [OPTIMIERUNG] Mehrdeutige Kurz-Strings ohne _x-Kontext („Einlass“, „Offen“, Genus-Status) — src/Notifications.php:165, Admin/Menu.php:139, PublicEvents.php:114, Admin/Forms.php:504–515 (A515, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Scanner zeigen Terminzeiten in Gerätezeitzone statt Terminzeitzone — assets/offline-scanner/app.js:103–106/358/386, assets/scanner.js:100–108 (A516, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Request-Caches bei switch_blog nicht zurückgesetzt (A182 unvollständig) — src/StaffAssignments.php:35–37/82–89, ServiceProviders.php:41–44, Campaigns.php:215–224 (A517, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Google Wallet meldet alle Texte als Deutsch — src/WalletPasses/GoogleProvider.php:34, :53 (A518, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Massenmails wählen Empfängersprache per MAX(locale) — src/Notifications.php:119–123 (A519, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Bericht sortiert Namen mit strcmp (Umlaute, Groß/Klein) — src/Reports/Data.php:208 (A520, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Serien-Übersicht zeigt rohe Regelwerte — src/Series/AdminPage.php:368 (A521, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] HTML-Mails ohne Leserichtung (erweitert A390) — src/Mail/Layout.php:55, Mail/Blocks.php:101 (A522, R3 Multisite/i18n/Skalierung)
- [ ] [OPTIMIERUNG] Konditionen-/Verkaufsmodell-Änderungen wirken rückwirkend auf offene Abrechnungsposten — src/Partners/Settlements.php:196–200 (A523, R3 Eigen (Deep Dive 2))
- [ ] [OPTIMIERUNG] Mitgliedspass-Laufzeit endet bei reinem Datum um 00:00 UTC; uses_per_day=0 unmöglich — src/Memberships.php:28, :50 (A524, R3 Eigen (Deep Dive 2))
- [ ] [OPTIMIERUNG] Warteschlange verschobener Kassen-Stornos als Option ohne Sperre (PLAUSIBEL) — src/BoxOffice/Shifts.php:693 (A525, R3 Eigen (Deep Dive 2))
- [ ] [OPTIMIERUNG] Fehlend: anteilige Erstattung von Pässen nach Teilnutzung — src/Refunds.php:78–93 (A526, R3 Eigen (Deep Dive 2))
- [ ] [OPTIMIERUNG] Fehlend: Kasse ohne Netz unbenutzbar — assets/boxoffice/pos.js, BoxOffice/Service.php:263 (A527, R3 Eigen (Deep Dive 2))
- [ ] [OPTIMIERUNG] Saalplan: Stehplätze verschwinden still bei Schlüsselkollision — src/Seating/Layouts.php:311–312 (A528, R3 QA)
- [ ] [OPTIMIERUNG] Halbfertiges Alt-Sitzplan-API ohne Kaufpfad (öffentliche Holds, UPDATE bei jedem GET) — src/Rest/Controller.php:107–121/654–667/829–851/889–897, Seats.php:8 (A529, R3 QA)
- [ ] [OPTIMIERUNG] Connector-Vertrag ist tot (dispatch nie aufgerufen, Tabelle ungenutzt; erweitert A166) — src/Integrations/ConnectorRegistry.php:8, Installer.php:681 (A530, R3 QA)
- [ ] [OPTIMIERUNG] Lost Update/Rennen beim Bearbeiten von Ticketarten — src/Admin/EventsPage.php:353–386 (A531, R3 QA)
- [ ] [OPTIMIERUNG] Admin-Aktionen melden Erfolg ohne geprüfte Schreibvorgänge (Widerruf, Check-in zurücknehmen) — src/Admin/TicketsPage.php:53–55, :96–105 (A532, R3 QA)
- [ ] [OPTIMIERUNG] Doppelklick „Tickets erneut senden“ rotiert Codes zweimal (ergänzt A335) — src/Admin/OrdersPage.php:89–95 (A533, R3 QA)
- [ ] [OPTIMIERUNG] accept_terms: "false" gilt als Zustimmung — src/Orders.php:43, Rest/Controller.php:711 (A534, R3 QA)

## Audit Runde 6 – Ticketing: Transaktionen, Ticket-Sicherheit, Dokumente/Mail/Wallet, Zeit/Caching, Käufer-UX/Einlass, Eigen-Audit (13.11.2026, Snapshot a1b11e5)

- [x] [KRITISCH] Mehrtägige Termine: Online-Verkauf endet beim Beginn, Web-Scanner und App verlieren den Termin nach 24 h — src/Orders.php:152–154, PublicEvents.php:156/362–364, Mobile/Api.php:77 (A535, R4 Zeit/Caching/Lebenszyklus)
- [x] [KRITISCH] Warteliste bietet bei Ticketarten mit Pflicht-Teilnehmerfeld nie etwas an — src/Waitlists.php:324, :269, Orders.php:87 (A536, R4 Eigen (Deep Dive 3))
- [x] [KRITISCH] Freigegebene Erstattung nach Umtausch: Geld zurück, neues Ticket gültig, Ticketart überverkauft — src/Refunds.php:602–616 (execution_conflict), :380–391, Exchange/Service.php:167/459 (A537, R4 Transaktionen/Concurrency)
- [x] [KRITISCH] Widerruf mit Kapazitätsfreigabe und anschließende Erstattung geben den Platz doppelt frei — src/Admin/TicketsPage.php:52–54, Refunds.php:388–390, Orders.php:1332 (A538, R4 Transaktionen/Concurrency)
- [x] [WICHTIG] PWA-Kamera meldet nach 3 s „Bereits verwendet“ für gerade eingelassenen Gast — assets/offline-scanner/app.js:466/476, tick() :647ff. (A539, R4 Käufer-UX/Einlass)
- [x] [WICHTIG] Web-Scanner ohne Request-Timeout, hängt bei Funkloch auf „…“ — assets/scanner.js:388–395 (A540, R4 Käufer-UX/Einlass)
- [x] [WICHTIG] Web-Scanner: Netzabbruch mitten im Scan → Schein-Konflikt beim Abgleich — assets/scanner.js:390, :396–399, enqueue :290–292 (A541, R4 Käufer-UX/Einlass)
- [x] [WICHTIG] Web-Scanner offline: derselbe Code auf demselben Gerät beliebig oft einlassbar — assets/scanner.js:290–318 (A542, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] Übertragene/neu ausgestellte Tickets melden „nicht gefunden“ statt Grund; „Widerrufen“ ohne Grund — src/Checkin.php:93, :428, Orders.php:1459–1508/2028/2087, TicketAssignments.php:70 (A543, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] Am Scanner keine manuelle Suche (Name/E-Mail/Bestellnummer), auch offline nicht — assets/scanner.js, PublicEvents.php:377–395, offline-scanner/index.html:55–58, OfflineScanner/Package.php:136–138 (A544, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] Admin-Ticketsuche findet angezeigte Bestellnummer „AB12-CD34“ und Hilfscodes nicht (erweitert A336) — src/Admin/Queries.php:418–423, Frontend/Format.php:39–41 (A545, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] Kein Check-in-Zähler (eingecheckt/gesamt) in Web-Scanner und PWA — assets/scanner.js, offline-scanner/app.js:374–392, Mobile/Api.php:92 (A546, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] „Rückgängig“ für Fehl-Check-in fehlt am Scanner; Grund im Admin optional — assets/scanner.js, offline-scanner/app.js, Mobile/Api.php:38/146, Admin/TicketsPage.php:282 (A547, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] PWA: Ergebnis bleibt unbegrenzt stehen, kein Vollbild, kleine Schrift — assets/offline-scanner/app.js:164–171, app.css:23–29, index.html:44–47 (A548, R4 Käufer-UX/Einlass)
- [ ] [WICHTIG] Teilnehmerdaten gehen beim Korrigieren der Menge verloren — assets/checkout.js:40–52, :431–437 (A549, R4 Käufer-UX/Checkout)
- [ ] [WICHTIG] Nummerierung der Teilnehmerblöcke nach Mengenänderung falsch — assets/checkout.js:52–54 (A550, R4 Käufer-UX/Checkout)
- [ ] [WICHTIG] Fehlermeldungen nennen nicht, welche Teilnehmer:in gemeint ist — assets/checkout.js:217–222, :313–316, Orders.php:477ff. (A551, R4 Käufer-UX/Checkout)
- [ ] [WICHTIG] Teilnehmerfelder nur Text/E-Mail/Textfeld/Checkbox; Name je Ticket nicht erzwingbar; kein „wie Käufer:in“ — src/Admin/EventsPage.php:25, checkout.js:33–60, Orders.php:510–517 (A552, R4 Käufer-UX/Checkout)
- [ ] [WICHTIG] Kein Schutz gegen Tippfehler in der Käufer-E-Mail — templates/event-card.php:79–82, Rest/Controller.php:392, checkout.js:386/406 (A553, R4 Käufer-UX/Checkout)
- [ ] [WICHTIG] Rückkehr von Stripe/PayPal/Mollie auf Startseite; Abbruch hält Plätze, Neuversuch erzeugt zweite Bestellung (erweitert A307) — src/Payments/StripeGateway.php:65–66/340–342, PayPalGateway.php:62/466, MollieGateway.php:339, ReturnNotice.php:13–29 (A554, R4 Käufer-UX/Checkout)
- [ ] [WICHTIG] Verschobener Einzeltermin: Verkaufsfenster bleiben auf alten Zeiten — src/Admin/EventsPage.php:213–241, :340–347 (A555, R4 Zeit/Caching/Lebenszyklus)
- [ ] [WICHTIG] Serien: „Verkaufsstart N Tage vor Beginn“ über DST 1 h daneben — src/Series/Generator.php:221–223, :653–655 (A556, R4 Zeit/Caching/Lebenszyklus)
- [x] [WICHTIG] Wartelisten-Einträge abgesagter/vergangener Termine bleiben dauerhaft „wartet“ — src/Waitlists.php:45–51, :911–918 (A557, R4 Zeit/Caching/Lebenszyklus)
- [ ] [WICHTIG] Erinnerungs-Mail nennt bei späten Käufern falsche Restzeit; LIMIT 200 verzögert — src/Modules/Reminders.php:72–79, :101, Reminders/Placeholders.php:16 (A558, R4 Zeit/Caching/Lebenszyklus)
- [ ] [WICHTIG] Wallet-Pässe bei Änderung von Ort/Ende/Einlass nicht aktualisiert — src/WalletPasses/Service.php:23–24, Notifications.php:52–60 (A559, R4 Zeit/Caching/Lebenszyklus)
- [ ] [WICHTIG] Checkout nicht an angezeigten Terminstand gebunden – nach Verschiebung still neuer Termin gekauft — src/Rest/Controller.php:317, assets/checkout.js:502–533 (A560, R4 Zeit/Caching/Lebenszyklus)
- [ ] [WICHTIG] REST /events liefert UTC ohne Zonenkennung neben Termin-Timezone, ohne Verkaufsstatus — src/Rest/Controller.php:242–290 (A561, R4 Zeit/Caching/Lebenszyklus)
- [ ] [WICHTIG] Keine zentrale Lebenszyklus-Instanz für Termine — src/ (A562, R4 Zeit/Caching/Lebenszyklus)
- [x] [WICHTIG] Ein nicht anbietbarer Eintrag blockiert die ganze Warteliste; Käuferlimit beim Eintragen ungeprüft — src/Waitlists.php:267–270, :92ff. (A563, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Warteliste ohne E-Mail-Bestätigung, Angebote halten Kontingent bis 24 h (erweitert A279/A280) — src/Waitlists.php:92–143, :307–355 (A564, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Angebotsseite/-mail ohne Termin, Ort und Rechtstexte; Wartende bei Absage/Verschiebung nicht informiert — src/Waitlists.php:1069–1088, Mail/Types.php:398–412 (A565, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Google Wallet: Ticketdaten gehen ohne Zutun des Käufers an Google; Löschung lässt Namen dort — src/WalletPasses/GoogleProvider.php:98–109, Service.php:97–117/276–283, Privacy/PolicyText.php (A566, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Datenschutzerklärung-Vorschlag nennt feste Fristen statt der eingestellten (erweitert A030) — src/Privacy/PolicyText.php:79/118/119, Settings.php:77–84, Retention.php:58–75 (A567, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Nachweis der akzeptierten AGB-Fassung nicht möglich; Bestätigungsmail ohne Vertragstexte (§312f BGB) — src/Settings.php:275–278, Admin/SettingsPage.php:378, Mail/Types.php:132 (A568, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Personalisierung über reine Namensänderung unbegrenzt umgehbar; Zähler im Audit-Log — src/TicketAssignments.php:27–36 (A569, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Nach Übertragung kann niemand außer Verwaltung personalisieren — src/Orders.php:1502–1503, TicketAssignments.php:30–34, TicketPage.php (A570, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Einlasskurve schneidet 72 h nach erstem Scan ab, zählt zurückgenommene Scans — src/Reports/Data.php:374 (A571, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Check-in-Quote im Bericht widerspricht No-Show-Liste (undone) — src/Reports/Data.php:322, Printing/NoShowList.php:27 (A572, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Pass-Inhaber fehlen in Listen/Berichten verknüpfter Termine; No-Shows des Ankertermins falsch — src/Printing/Templates.php:289, NoShowList.php:27, Reports/Data.php:320–362 (A573, R4 Eigen (Deep Dive 3))
- [ ] [WICHTIG] Partner-API: since-Abruf übersieht Widerruf/Erstattung/Übertragung; keine Namen/Antworten — src/Partners/Api.php:238, Installer.php:195–217 (A574, R4 Eigen (Deep Dive 3))
- [x] [WICHTIG] Umtausch eines Tickets gibt das gesamte Kontingent der Bestellung zurück — src/Exchange/Service.php:754, Allocations/Holds.php:300–303 (A575, R4 Transaktionen/Concurrency)
- [ ] [WICHTIG] Checkout ohne Preisprüfung: Käufer zahlt mehr als angezeigt — src/Orders.php:191–200, Series/Pricing.php:270ff., assets/checkout.js:84 (A576, R4 Transaktionen/Concurrency)
- [x] [WICHTIG] Stripe-Sitzung lebt 10 min länger als die Reservierung → bezahlt ohne Ticket — src/Payments/StripeGateway.php:319–325, Rest/Controller.php:324 (A577, R4 Transaktionen/Concurrency)
- [x] [WICHTIG] Teilerstattung und Admin-Widerruf informieren Warteliste und Kontingente nicht — src/Refunds.php:418–428, Admin/TicketsPage.php:56–60 (A578, R4 Transaktionen/Concurrency)
- [x] [WICHTIG] Keine Invariante „pro Ticket höchstens einmal freigegeben“; drift() erkennt falsche Bestellzeilen nicht — src/Refunds.php, Exchange, TicketsPage, CapacityCounters::drift (A579, R4 Transaktionen/Concurrency)
- [ ] [WICHTIG] Ticketmail ohne Größengrenze: 26–40 MB bei 1.000 Tickets, Gmail kürzt ab ~22 Tickets mit Wallet — src/Orders.php:1797–1823, WalletPasses/Service.php:183–199, TicketPdf.php:86–113 (A580, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Teilnehmer-E-Mail aus Checkout: Ticket nur an Käufer, Portal verweigert dann PDF (held_elsewhere) — src/Orders.php:377, :1813, PortalSelfService.php:107, Modules/Reminders.php:94 (A581, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Wallet-Pässe folgen Ort, Endzeit, Event-/Ticketname, Namenskorrektur nicht — src/WalletPasses/Service.php:22–28, Notifications.php:56, TicketAssignments.php:70–90 (A582, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Google-Speichern-Link je Ticket mit vollem Objekt im JWT (>1.800 Zeichen) — src/WalletPasses/GoogleProvider.php:77–89, Service.php:162 (A583, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Salt-Rotation macht alle Wallet-Pässe ungültig (voided), ohne Warnung — src/WalletPasses/Store.php:16–20, Service.php:81, AppleProvider.php:64, GoogleProvider.php:62 (A584, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Abgelaufenes Apple-Zertifikat wird weiter verwendet, nur Hinweis auf Wallet-Seite — src/WalletPasses/Credentials.php:203–231, AdminPage.php:135–138 (A585, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Erneuter Portal-Download ohne Wallet-Modul rotiert jedes Mal die Codes; kein Admin-PDF je Bestellung/Ticket (ergänzt A207) — src/PortalSelfService.php:139–144/186–200, Orders.php:1960ff/2058ff (A586, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Erinnerungen nur in Website-Sprache und ohne Ticketlink — src/Modules/Reminders.php:37–46, :98, :141–155 (A587, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Zustellung/Wiederherstellung hängt am Klartext-Code der ersten Mail (blinder Fleck) — Orders, PortalSelfService, WalletPasses (A588, R4 Dokumente/Mail/Wallet)
- [ ] [WICHTIG] Gleicher Idempotency-Key bei parallelem Retry mehrfach eingelöst; offline-sync ohne Key — src/Checkin.php:166–179, Installer.php:231 (Index nicht UNIQUE), Rest/Controller.php:606–619 (A589, R4 Ticket-Sicherheit)
- [ ] [WICHTIG] „Check-in zurücknehmen“ macht erstattete Tickets wieder einlassfähig — src/Admin/TicketsPage.php:82–108, Mobile/Api.php:159, Checkin.php:87–115, Refunds.php:384–393 (A590, R4 Ticket-Sicherheit)
- [ ] [WICHTIG] Gästelisten-„Angekommen“ zerstört bei abgelehntem Check-in die Ticketcodes — src/GuestLists/Service.php:646–651 (A591, R4 Ticket-Sicherheit)
- [ ] [WICHTIG] Offline-Upload mit frei gewähltem scanned_at umgeht Einlassfenster und Bereichskapazität — src/Admission/Rest.php:62–70, Mobile/Api.php:184–186, Checkin.php:39–46/224/236, OfflineScanner/Sync.php:250, Rest/Controller.php:925 (A592, R4 Ticket-Sicherheit)
- [ ] [WICHTIG] Käufer-Code-Rotationen während des Einlasses erzeugen „offline gültig, online ungültig“ (erweitert A211) — src/Rest/Controller.php:503–521, PortalSelfService.php:150ff., Orders.php:1481 (A593, R4 Ticket-Sicherheit)
- [ ] [WICHTIG] Einlass-Garantien nur im Normalpfad getestet — tests/ (A594, R4 Ticket-Sicherheit)
- [ ] [OPTIMIERUNG] Doppelscan-Anzeige ohne Gerät/Person, nicht in Ortszeit; PWA offline ohne Zeit (erweitert A089) — src/Checkin.php:468–486, scanner.js:101–108, app.js:103–106/173–181 (A595, R4 Käufer-UX/Einlass)
- [ ] [OPTIMIERUNG] Keine Gruppen-Check-in-Funktion — assets/scanner.js, offline-scanner/app.js, Checkin.php (A596, R4 Käufer-UX/Einlass)
- [ ] [OPTIMIERUNG] Kopplung für Aushilfen umständlich (QR in PWA nicht nutzbar, maxlength 40, zweiter Admin-Schritt) — src/Admin/DevicesPage.php:66–68, Mobile/Devices.php:19/62/189–194, offline-scanner/index.html:26, app.js:672–751 (A597, R4 Käufer-UX/Einlass)
- [ ] [OPTIMIERUNG] PWA „Gerät entkoppeln“ trotz nicht synchronisierter Einlässe (erweitert A345) — assets/offline-scanner/app.js:696–702 (A598, R4 Käufer-UX/Einlass)
- [ ] [OPTIMIERUNG] Ungültige Teilnehmer-E-Mail serverseitig still verworfen — src/Orders.php:515–516 (A599, R4 Käufer-UX/Checkout)
- [ ] [OPTIMIERUNG] Bestätigung ohne Kalendereintrag/Ticketlink — assets/checkout.js:368–429 (A600, R4 Käufer-UX/Checkout)
- [ ] [OPTIMIERUNG] Überweisungsseite ohne GiroCode und Kopier-Knöpfe — assets/checkout.js:389–401, Payments/BankTransfer.php:38–59 (A601, R4 Käufer-UX/Checkout)
- [ ] [OPTIMIERUNG] Mobile: Summe weit vom Kaufknopf, street-address auf einzeiligem Feld — templates/event-card.php:78/94/169, checkout.js:58 (A602, R4 Käufer-UX/Checkout)
- [ ] [OPTIMIERUNG] Browser-Autofill trägt Käufernamen in alle Teilnehmerblöcke (PLAUSIBEL) — assets/checkout.js:58 (A603, R4 Käufer-UX/Checkout)
- [ ] [OPTIMIERUNG] SMS-Nummer „+49 (0)151 …“ wird zu falscher Nummer — src/Sms/Service.php:83–91 (A604, R4 Käufer-UX/Checkout)
- [ ] [OPTIMIERUNG] Löschfrist für Finanzbelege nach UTC-Jahr statt lokalem Rechnungsjahr (GoBD, ein Jahr zu früh) — src/Privacy/Retention.php:88–92 (A605, R4 Zeit/Caching/Lebenszyklus)
- [ ] [OPTIMIERUNG] „Termin vorbei“ ohne ends_at sechsfach unterschiedlich definiert — src/Orders.php:152, Calendar/Ics.php:126, AppleProvider.php:63, Retention.php:138, Waitlists.php:67, CapacityCounters.php:549, OfflineScanner/Assignments.php:98 (A606, R4 Zeit/Caching/Lebenszyklus)
- [ ] [OPTIMIERUNG] Weitere Geschäftsregeln umgehen Clock::now() (erweitert A199) — src/Exchange/Service.php:184/206/876, PortalSelfService.php:306, OrderPortal.php:130, TicketAssignments.php:24, Seats.php:36, Seating/Reservations.php:124, Passes/Admission.php:41, Mobile/Api.php:186, EventCard.php:143, Admin/Queries.php:89 (A607, R4 Zeit/Caching/Lebenszyklus)
- [ ] [OPTIMIERUNG] Mails mischen Zeitzonen (Frist in Site-Zone ohne Label) — src/Payments/BankTransfer.php:54, Waitlists.php:343/1074 (A608, R4 Zeit/Caching/Lebenszyklus)
- [ ] [OPTIMIERUNG] Offline-scanned_at ohne Offset als UTC gelesen (PLAUSIBEL) — src/Mobile/Api.php:185–186, OfflineScanner/Sync.php:44, Admission/Rest.php:67 (A609, R4 Zeit/Caching/Lebenszyklus)
- [ ] [OPTIMIERUNG] Saalplan bei abgesagtem/vergangenem Termin: nur „Laden fehlgeschlagen“, Formular aktiv — src/Seating/Reservations.php:442, Seating/Rest.php:25–27, assets/seating/picker.js:55 (A610, R4 Zeit/Caching/Lebenszyklus)
- [ ] [OPTIMIERUNG] Teilnehmer-Export unvollständig (Check-in-Zeit, Sitz, Termin, Bereich, Status bei Pässen) — src/Printing/AttendeeList.php:44–60 (A611, R4 Eigen (Deep Dive 3))
- [ ] [OPTIMIERUNG] Webhooks ohne Einwilligungsstand/Consent-Ereignisse — src/Webhooks/Dispatcher.php:18, Consents.php:383–386 (A612, R4 Eigen (Deep Dive 3))
- [ ] [OPTIMIERUNG] Umsatzbericht nach Bestell- statt Zahlungsdatum — src/Reports/Data.php:165 (A613, R4 Eigen (Deep Dive 3))
- [ ] [OPTIMIERUNG] Alters-/Ausweishinweis und Inhabername nur auf Eventkarte — templates/event-card.php:64, TicketPage.php (A614, R4 Eigen (Deep Dive 3))
- [ ] [OPTIMIERUNG] Fehlend: Admin-Dashboard-Widget — — (A615, R4 Eigen (Deep Dive 3))
- [ ] [OPTIMIERUNG] Rabattcode-Gültigkeit per REST ohne Prüfung und Zeitzone — src/Rest/Controller.php:681–692, Campaigns.php:47/183 (A616, R4 Transaktionen/Concurrency)
- [ ] [OPTIMIERUNG] Nachfragestufe mit einer großen Bestellung umgehbar — src/Series/Pricing.php, Orders.php:191 (A617, R4 Transaktionen/Concurrency)
- [ ] [OPTIMIERUNG] Ticket-PDF unkomprimiert (~21 KB je Ticket, Faktor 7 möglich) — src/TicketPdf.php:49–55, :98 (A618, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Alter Google-Link legt nach Rotation ACTIVE-Pass mit ungültigem Code an — src/WalletPasses/GoogleProvider.php:111–114, Service.php:216/379 (A619, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Pass-Bau ohne Cache (Bilder, Signatur, Credentials je Abruf) — src/WalletPasses/Service.php:398–419, WebService.php:112–124, AppleProvider.php:119–148 (A620, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Neuversand-/Retry-Mail ohne PDF, ICS, .pkpass — src/Orders.php:2036–2041, :2091–2094 (A621, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Übertragungsempfänger ohne Wiederherstellungsweg — src/Orders.php:1509–1524, TicketPage.php:252–258 (A622, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Erinnerungsversand ohne Drosselung; Anbieterlimits → Mails endgültig verloren; kein List-Unsubscribe — src/Communications.php:39–80 (A623, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Keine Bounce-Verarbeitung (ergänzt A328) — src/Mail/Mailer.php:321–350 (A624, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] HTML-Layout: Outlook ohne MSO-Tabelle, kein Dark Mode, Wallet-Links als lange URLs — src/Mail/Layout.php:167–175, WalletPasses/Service.php:158–163, Mailer.php:449–455 (A625, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Anhänge sofort nach wp_mail gelöscht – Mail-Queue-Plugins senden ohne Anhang (PLAUSIBEL) — src/Orders.php:1820–1823, Modules/Calendar.php:96, Mailer.php:332–347 (A626, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Portal-Download „alle“ ohne Obergrenze (1.000 Rotationen, 20 MB je Klick) — src/PortalSelfService.php:162–166 (A627, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Pass-Design: Logo mit doppeltem Namen, Umlaute entfernt, Google ohne Logo — src/WalletPasses/Archive.php:285, AppleProvider.php:87/125, GoogleProvider.php:43–58 (A628, R4 Dokumente/Mail/Wallet)
- [ ] [OPTIMIERUNG] Lost Update bei Offline-Signaturschlüsseln — src/OfflineScanner/Keys.php:178–233 (A629, R4 Ticket-Sicherheit)
- [x] [OPTIMIERUNG] Massendruck mit neuen Codes entwertet übertragene/zugewiesene Tickets ohne Nachricht (erweitert A335/A427) — src/Printing/Templates.php:318–348 (A630, R4 Ticket-Sicherheit)
- [ ] [OPTIMIERUNG] Abfotografierter QR-Code kann Bestelllink des Käufers rotieren — src/TicketPage.php:34–39, PortalSelfService.php:588–606 (A631, R4 Ticket-Sicherheit)


## P1 – Form Captain und Review Captain Integration (neu, 15.11.2026)

- [ ] **Form Captain:** Öffentliche, versionierte Formularreferenz/Einbettung integrieren; Eventspezifische Formulare in Event-Admin auswählen und veröffentlichen. Ein Formular-Entry darf nie automatisch Reservierung, Zahlung oder Ticket erzeugen.
- [ ] Bei expliziter Übergabe nur freigegebene, validierte Felder plus Formular-/Entry-ID verarbeiten; keine direkten Tabellenzugriffe. Event-/Buchungsdaten und Ticket-Teilnehmerfelder bleiben in Pass Captain.
- [ ] **Review Captain:** Versionierten `experience_completed`-/Teilnahmevertrag ergänzen, der tatsächlichen Check-in bzw. eine zulässige Teilnahmebestätigung sowie Event-ID, stabile Quellreferenz, Zeitpunkt und Sprache signalisiert. Vorhandene interne Hooks nicht ungeprüft als öffentlichen Vertrag verwenden.
- [ ] Einladungskontakt über einen autorisierten Resolver bereitstellen; Ereignisse enthalten standardmäßig keine E-Mail/Personendaten. Opt-out, Einladungsberechtigung, Frequenzlimit und Versand bleiben Review-Captain-Aufgabe.
- [ ] Refund, Storno, Eventabsage, No-show, Tickettransfer und Wiederholung idempotent abbilden; keine Einladung bei abgesagtem/erstattetem oder nicht bestätigtem Besuch.
- [ ] Review Captain Pro Adapter/Entitlement, Inkompatibilitätsdiagnose und Wiederholungs-/Fehlerstatus in Pass Captain Admin ergänzen; Free-Kern bleibt ohne harte Abhängigkeit.
- [ ] Affiliate Captain nur für spätere aggregierte Qualitätsberichte vorsehen; Review-Klicks/-Bewertungen erzeugen keine Partnerprovision.
- [ ] E2E-Matrix für Ticketkauf → Check-in/Teilnahme → Review-Anfrage und die Ausschlüsse (Refund, Absage, No-show, Opt-out, Duplikat, fehlendes Plugin) ergänzen.
- **Abnahme:** Ein bestätigtes Erlebnis erzeugt maximal eine zulässige Anfrage; generische Formulareinsendungen und Affiliate-Provisionsereignisse lösen keine Einladung aus.

## P1 – Workflow Captain Provider-Vertrag

- [ ] PASS-WF-01 – Öffentlichen Providervertrag v1 festlegen: SemVer, Event-Envelope, Feld-Allowlist, Capability, Entitlement, Fehlercodes und Deprecation-Frist; keine privaten Klassen oder Tabellen.
- [ ] PASS-WF-02 – Post-Commit-Events für Bestellung bezahlt/storniert/erstattet, Ticket erstellt/widerrufen/transferiert, Check-in/out, Event abgesagt/verschoben und Teilnahme bestätigt veröffentlichen; stabile IDs, Zeitstempel und Schema-Version, keine Codes oder Teilnehmer-PII.
- [ ] PASS-WF-03 – Ausgabe-Semantik pro Ticket mit stabiler Ticket-ID definieren; Refunds nach Order, Line und Ticket eindeutig abbilden; fehlenden order.refunded Pfad schließen und Outbox/Dedupe gegen Mehrfachhooks ergänzen.
- [ ] PASS-WF-04 – Sichere v1-Commands für ausgewählte Aktionen bereitstellen: Mail erneut senden, Ticket widerrufen, Event absagen/verschieben und zulässige Teilnahme bestätigen. Capability, Allowlist, Idempotency-Key und Audit; keine Zahlung, Erstattung oder Ticketcode-Ausgabe.
- [ ] PASS-WF-05 – Ownership für optionale Erinnerungen/Mails mit expliziter, protokollierter Übergabe ergänzen. Zahlung, Reservierung, Ausgabe, Refund und Einlass bleiben Pass Captain-eigene Abläufe.
- [ ] PASS-WF-06 – Adminstatus für Providervertrag, Workflow-Übergaben, letzte Eventannahme und Fehler ergänzen; bei fehlendem/inkompatiblem Workflow auf native Abläufe zurückfallen.
- [ ] PASS-WF-07 – Vertragsfixtures und E2E für PII-Redaction, HPOS/Classic, Multisite, Replay, Duplikate, Workflow fehlt/deaktiviert, Fachplugin-Pro-Recht fehlt/abgelaufen und Contract-Major ergänzen.
- Abnahme: Workflow nutzt nur Pass-Services; jede Zahlung/Refund erzeugt eine fachliche Änderung und kritische Kernabläufe bleiben unabhängig.

## P1/P2 – Funktionsausbau nach dem neutralen Funktionsabgleich

Diese Aufgaben konkretisieren noch offene oder bisher nur grob beschriebene Bereiche der bestehenden Pass-To-do-Liste. Sie ergänzen die vorhandene Ticket-, WooCommerce-, POS-, Scanner- und Teilnehmergrundlage; sie ersetzen keine Kernabläufe und begründen keine direkte Abhängigkeit von externen Wettbewerbsprodukten.

### P1 – Teilnehmerdaten, Checkout und Self-Service

- [ ] **PASS-FE-01 – Teilnehmerfeld-Editor und Feldvertrag fertigstellen.** Feldtypen, Beschriftung, Hilfetext, Pflichtfeld, Sortierung, Geltungsbereich und Status versioniert speichern. Mindestens Text, mehrzeiliger Text, E-Mail, Telefon, Auswahl, Mehrfachauswahl, Datum und Einwilligung unterstützen; Felder je Bestellung, Tickettyp oder einzelner Ticketperson unterscheiden. Vor Implementierung Datensparsamkeit und Export-/Löschsemantik je Feld festlegen.
- [ ] **PASS-FE-02 – Teilnehmerfelder durch beide Woo-Checkouts führen.** Classic Checkout und Checkout Blocks/Store API mit demselben serverseitigen Schema, Validierung und Fehlertext anbinden. Manipulierte, unbekannte oder nicht freigegebene Felder serverseitig zurückweisen; Eingaben atomar und idempotent zur richtigen Bestellposition und Ticketperson speichern. HPOS und Gast-Checkout einschließen.
- [ ] **PASS-FE-03 – Ticketkäufer- und Teilnehmerdaten getrennt verwalten.** Bei Gruppenbestellungen den Käufer nicht automatisch als Teilnehmer jedes Tickets behandeln. Zuordnung pro Ticket anzeigen, fehlende Teilnehmerangaben vor Versand gezielt nachfordern und einen berechtigten Wechsel des Teilnehmers auditieren.
- [ ] **PASS-FE-04 – Teilnehmer-Self-Service ausbauen.** Im Kundenkonto bzw. über kurzlebige, zweckgebundene Links erlaubte Teilnehmerfelder ändern, Ticket erneut senden und zugelassene Teilnehmerübertragung ausführen. Fristen und gesperrte Felder konfigurieren; kein Zugriff allein über eine erratbare Bestell- oder Ticket-ID. Änderungen nach Check-in, Refund oder Eventbeginn nach fachlichen Regeln blockieren oder zur Prüfung stellen.
- [ ] **PASS-FE-05 – Teilnehmerverwaltung und Exporte vervollständigen.** Nach Event, Termin, Tickettyp und Status suchen/filtern/sortieren; Rollenrechte je PII-Feld, Audit und CSV-Formel-Schutz umsetzen. Datenschutzexport, Löschung, Aufbewahrung, Finanzbelege und Anonymisierung getrennt behandeln; Teilnehmerfelder dürfen nie ungefiltert in Scanner- oder Workflow-Payloads gelangen.
- **Abnahme Teilnehmerdaten:** Pflichtfeld-/Fehlerpfade funktionieren in Classic und Blocks; Käuferdaten und Teilnehmende sind eindeutig getrennt; Replay erzeugt keine Dubletten; Export, Korrektur, Widerruf und Löschung sind nachvollziehbar.

### P1 – Tickets, PDF und Vor-Ort-Druck

- [ ] **PASS-FE-06 – Sichere Ticket-PDFs einführen.** Serverseitige PDF-Erzeugung mit QR-Code, Event/Termin, Tickettyp, Sitzplatz, Teilnehmerbezeichnung und lokalisiertem Datum implementieren. PDF nur mit Zugriffsnachweis ausliefern, Codes nie in Logs schreiben und Nachsendung ohne unbeabsichtigte Code-Rotation ermöglichen.
- [ ] **PASS-FE-07 – Vorlagen und Ticketdesigns bereitstellen.** Mit mehreren barrierearmen Startvorlagen sowie einem dokumentierten Template-/Block-System beginnen. Logo, Farben, Feldsichtbarkeit, Mehrsprachigkeit und rechtlich erforderliche Hinweise konfigurierbar machen; QR-/Barcode-Kontrast und Druckränder automatisiert und manuell prüfen.
- [ ] **PASS-FE-08 – Badges, Namensschilder und Armbänder ergänzen.** Vorlagen für gängige A4-/Etikettenformate und Batchdruck mit Event-/Terminfilter, Teilnehmerfeld-Mapping, Sortierung und Vorschau bauen. Nur freigegebene Daten drucken; Druck- und Exportaktionen rollenbasiert protokollieren.
- **Abnahme Druck:** PDF und Listen stimmen mit Ticketstatus, Refund, Sitzplatz und Teilnehmeränderung überein; Gastlinks leaken keine Daten; Vorlagen funktionieren auf typischen Desktop- und Mobilbrowsern und auf Schwarz-Weiß-Druckern.

### P1 – Scannerbetrieb und native Mobile-Apps

- [ ] **PASS-FE-09 – Mobile-API als vollständigen App-Vertrag spezifizieren.** Gerätebindung, kurzlebige/widerrufbare Tokens, Event-/Bereichsrechte, minimale Teilnehmeranzeige, Check-in/out, Suche, Batchaktionen, Offlinepakete, Cursor, Idempotenz, Konfliktantworten und Mindest-Core-Version dokumentieren. API-Änderungen versioniert und rückwärtskompatibel ausliefern.
- [ ] **PASS-FE-10 – iOS- und Android-Scanner-App bauen oder als eigenes Releaseprojekt freigeben.** Pairing per Admin-QR, sichere Tokenablage, Kamera-/Bluetooth-Scanner, Eventauswahl, Namens-/Ticket-ID-Suche, Filter, schnelles wiederholtes Scannen, zugängliche Statusanzeige und Datenschutzmodus implementieren. Keine Ticketcodes oder Teilnehmerdaten in Crashreports/Telemetrie aufnehmen.
- [ ] **PASS-FE-11 – Offline-Check-in konfliktfest abnehmen.** Signiertes Paket, begrenzte Gültigkeit, lokale Idempotency-IDs, Zeitabweichung, Widerruf und Synchronisierung nach Netzrückkehr prüfen. Parallel-Scans auf mehreren Geräten als Konflikt erfassen; kein Check-in still verlieren oder als global eindeutig bewerben, solange Geräte offline voneinander getrennt sind. Admin erhält Konfliktliste, Vorschau und auditierte Auflösung.
- [ ] **PASS-FE-12 – Scanner-Betrieb für Eventteams vervollständigen.** Geräte einem Event und optional einem Einlassbereich zuweisen; Berechtigungen widerrufen, Geräte-/Syncstatus, letzte erfolgreiche Verbindung, Paketablauf und offene Konflikte anzeigen. Massen-Check-in/-Check-out nur mit Bestätigung, Ergebnisbericht und idempotentem Retry erlauben.
- [ ] **PASS-FE-13 – App-Sprachen und Geräte-QA vorbereiten.** App, Fehler, Datums-/Zeitzonenformat und Screenreader-Sprache aus dem Locale ableiten; mindestens Deutsch und Englisch abnehmen. iOS/Android-Minimumversion, Tablet-Layout, Kameraerlaubnis, Bluetooth-Scanner, schlechtes Netz, Gerätewechsel und App-Neustart dokumentieren.
- **Abnahme Mobile:** Online- und Offlineabläufe sind reproduzierbar; Doppel- und Konfliktscans sind sichtbar; entzogenes Gerät kann online nicht weiterarbeiten; Berechtigungen und Logs legen keine unnötigen Teilnehmerdaten offen.

### P1 – POS und Buchungsablauf

- [ ] **PASS-FE-14 – POS-Oberfläche auf der vorhandenen REST-/Servicegrundlage fertigstellen.** Event/Termin wählen, Tickettypen und Bestand anzeigen, Warenkorb/Käufer erfassen, Verkauf anlegen, Zahlung auswählen, Tickets zustellen/drucken sowie Storno/Refund anzeigen. Gemeinsame Bestände mit Webcheckout atomar reservieren; kein separater POS-Ledger und keine direkten WooCommerce-Tabellenzugriffe.
- [ ] **PASS-FE-15 – Terminal- und Fiskalpfade Ende-zu-Ende integrieren.** Bestehende Gateway- und Fiskalverträge nutzen; Zahlungsstart, Abbruch, Timeout, späte Bestätigung, Erstattung, Beleg und Bestellnotiz sichtbar verarbeiten. Unklare Zahlung darf keinen zweiten Verkauf auslösen; Terminal- und Fiskalfehler erhalten einen wiederaufnehmbaren Prüfstatus.
- [ ] **PASS-FE-16 – Offline-POS gesondert spezifizieren.** Vor Bau festlegen, ob offline nur Check-ins oder auch Verkäufe/Erstattungen zulässig sind. Für Verkäufe wären lokale Bestandsreservierung, Zahlungsbeleg, Konfliktabgleich, doppelte Verarbeitung und Fiskalpflichten zu lösen; bis zur Freigabe keine Offline-Verkaufszusage darstellen.
- [ ] **PASS-FE-17 – Buchungen und Service-Slots auf Self-Service-Lücken prüfen.** Verfügbarkeit, Kapazität, Puffer, Zeitzone, Warteliste, Umbuchung/Storno, Erinnerungen und Doppelbuchung über Checkout, Kundenkonto und Admin gemeinsam abbilden. Vorhandene Service-Slots weiterverwenden und mit WooCommerce-Bestand/Bestellung konsistent halten.
- **Abnahme POS/Buchung:** Gleichzeitiger Web-/POS-Verkauf überschreitet Kapazität nicht; Zahlung, Refund, Ticket und Fiskalbeleg bleiben abstimmbar; Slots blockieren keine ungültigen oder doppelten Buchungen.

### P2 – Direkte Integrationen und Follow-up

- [ ] **PASS-FE-18 – Zoom-Adapter evaluieren und bei Nachfrage bauen.** Meeting/Webinar je Event, Veranstaltungstag oder Buchung zuordnen oder anlegen; Registrierung erst nach bezahlter Bestellung über den offiziellen API-Vertrag durchführen. Refund, Storno, Umbuchung, API-Quota, Zugangsdatenrotation und Löschwunsch berücksichtigen; Zugangsdaten und Teilnahmelinks aus Logs und allgemeinen Events ausschließen.
- [ ] **PASS-FE-19 – Mailchimp-Adapter evaluieren und bei Nachfrage bauen.** Audience, Tags und Feldmapping je Event/Termin konfigurieren; Teilnehmer nur bei belegter, zweckgebundener Einwilligung synchronisieren. Abmeldung/Einwilligungswiderruf, Löschung, Deduplizierung, API-Fehler, Rate-Limit und erneute Zustellung synchronisieren. Externe Synchronisierung bleibt deaktivierbar und beeinflusst Ticketkauf nicht.
- [ ] **PASS-FE-20 – Workflow Captain für Automationen ergänzen.** Teilnahme-/Check-in-, Eventabsage, Terminänderung, Ticketstatus und zulässige Teilnehmeränderung über den öffentlichen versionierten Providervertrag veröffentlichen. Reminders, Follow-ups und interne Benachrichtigungen können Workflow Captain übernehmen, wenn Betreiber dies explizit aktiviert; native Defaults, Consent, Refunds und Pass-Geschäftsregeln bleiben geschützt.
- [ ] **PASS-FE-21 – Kalender- und Eventkommunikation vervollständigen.** iCalendar-Download und Aktualisierungen bei Zeit-/Ortänderung, Absage und Umbuchung abnehmen; Zeitzone, Serientermine, mehrtägige Events und Widerruf alter Termine korrekt darstellen. Erinnerungen müssen nach Refund, Absage, Opt-out oder Terminwechsel aktualisiert bzw. gestoppt werden.
- [ ] **PASS-FE-22 – Übersetzbare Begriffe und Veranstaltungsvorlagen bereitstellen.** Betreiber kann Bezeichnungen wie Teilnehmende/Gäste/Schüler, Datum, Tickettexte und Eventseiten-Terminologie konfigurieren; Vorlagen bleiben übersetzbar und kollidieren nicht mit WooCommerce-Checkout- oder Theme-Templates.
- **Abnahme Integrationen:** Direkte Adapter sind optional, nutzerseitig einwilligungs- und datensparsam, versioniert sowie wiederholbar; bei fehlendem Connector oder API-Ausfall bleiben Verkauf, Ausgabe, Einlass und Erstattung funktionsfähig.

### Produkt- und Releaseentscheidung

- [ ] **PASS-FE-23 – Free/Pro-Zuordnung pro Funktionspaket bestätigen.** Grundlegende Teilnehmerfelder, Ticketzugriff, Check-in und Datenschutz als Teil eines vollständigen Ticketkerns bewerten; native Apps, erweiterte Layouts, POS-Integrationen, Drittanbieteradapter und priorisierter Betrieb anhand Kosten/Nachfrage zuordnen. Keine bereits ausgelieferte Kernfunktion nachträglich sperren.
- [ ] **PASS-FE-24 – Preis und Site-Limits nach dem tatsächlichen Launchumfang festlegen.** Vor Shopfreigabe den dann lieferbaren Funktionsumfang gegen die 1/3/5-Site-Staffel, Supportaufwand, externe Providerkosten, 14-Tage-Erstattungsregeln und Lizenzlimits in WP License Captain abgleichen. Unfertige App-/POS-/Adapterfunktionen nicht als enthaltene Launchleistung bewerben.
- [ ] **PASS-FE-25 – Wettbewerbsfähigkeits- und Release-Matrix ergänzen.** Funktionen marktüblicher Ticket- und Eventlösungen gegen Pass Captain Core und Pro regelmäßig abgleichen; je Abweichung Status „vorhanden“, „teilweise“, „nicht geplant“ oder konkretes Releaseziel dokumentieren. End-to-End-Matrix umfasst Blocks/Classic, HPOS, Mobilgeräte, Multisite, mehrere Scanner, Offlinekonflikte, Druck, POS, Refund und fehlende Drittanbieter.
- **Gesamtabnahme:** Pass Captain weist für alle wesentlichen Eventtage-Abläufe eine geprüfte Alternative oder eine ausdrücklich dokumentierte Produktgrenze auf; Releaseumfang, Pro-Preis und Supportversprechen beziehen sich auf tatsächlich ausgelieferte Funktionen.


## Sicherheitsaudit – main (ZIP-Stand 2026-11-14)

Quelltext-Audit nach der Methodik des Cloudflare-`security-audit`-Skills: acht parallele Prüfbereiche (REST-Autorisierung, Checkout/Bestand, öffentliche Seiten/XSS, Zahlungen/Webhooks, Scanner/Mobile/Wallet, Partner-API/Webhooks/SMS, wp-admin, Datenlebenszyklus/WooCommerce/CI plus Muster-Sweep), danach unabhängige Gegenprüfung jedes Kandidaten. Es wurde nur gelesen; kein Plugin-Code wurde ausgeführt, keine Laufzeit- oder Exploit-Tests. „BESTÄTIGT“ heißt daher: Der Pfad ist im Quelltext vollständig nachvollzogen. Dieser Durchlauf ersetzt nicht den offenen Punkt „Unabhängigen Sicherheitsreview/Penetrationstest durchführen“.

Abgleich mit dem bestehenden Backlog: Bereits erfasst und hier nicht wiederholt sind Klartext-Secrets in `wpcap_settings`, Rate-Limits hinter Proxy/CDN und nicht atomare Transient-Zähler, SSRF-/HTTP-Absicherung ausgehender Aufrufe, Release-Workflow ohne CI-Bindung, Permission-Matrix, Dependency-/Secret-Scanning und Admin-Oberfläche für Entwickler-API-Schlüssel. Die Punkte unten sind neu oder konkretisieren einen bisher nur allgemein formulierten Punkt (jeweils vermerkt).

Ohne Befund geprüft: SQL-Injection (alle Abfragen prepared oder Integer-Listen), XSS in PHP-Ausgabe und JavaScript, Open Redirects, Token-Erzeugung und -Vergleich (`random_bytes`, nur Hashes, `hash_equals`), Webhook-Signaturen von Stripe/PayPal/Mollie/Twilio (fail-closed), Betrags- und Währungsprüfung vor „bezahlt“, Refund-Obergrenzen, Partner-API-Mandantentrennung, Gerätekopplung, Offline-Paket-Signaturen, Wallet-Pass-Authentifizierung, Nonce- und Capability-Prüfungen aller Admin-Handler, WooCommerce-Preisbindung, eingebettete Zugangsdaten.

### Bestätigte Befunde

- [x] [P1][BESTÄTIGT] `src/Orders.php:161–171,503–510,248–273` — Die Termin-Kapazität wird pro Bestellposition geprüft, nicht pro Bestellung: `capacity_error()` rechnet nur mit der Menge der jeweiligen Position, und die Reservierungen aller Positionen werden erst nach der Schleife geschrieben. Die Late-Payment-Prüfung (`:684–704`) summiert dagegen korrekt. **Mögliche Auswirkung:** Eine einzige anonyme Bestellung mit mehreren Tickettypen desselben Termins erhält bis zu Restkapazität × Anzahl Typen gültige Tickets (z. B. 8 Restplätze, 3 Typen → 24 Tickets); bei kostenlosen Events ohne Kosten. Die Aussagen „Checkout-Käufe überverkaufen das Kontingent nicht“ (P0 Reservierung, Abnahmekriterien) gelten nur für parallele Bestellungen, nicht für diesen Fall. **Maßnahme:** In `create()` die Nachfrage je `occurrence_id` summieren und die Terminprüfung einmal je Termin mit der Summe ausführen; Tickettyp-Prüfung je Position beibehalten; Integrationstest mit Mehrtypen-Bestellung an der Kapazitätsgrenze ergänzen. — _erledigt im Branch (Orders: Termin-Kapazität je Bestellung summiert, Commit 751d80c)_
- [ ] [P1][BESTÄTIGT] `src/Integrations/Vouchers/Checkout.php:48,104,118,271–283,150–263`; `src/Payments/Webhooks.php:97,113,121`; `src/Orders.php:657–725,1026–1036,1049–1075`; `src/Admin/OrdersPage.php:389–392,471` — Läuft eine Bestellung mit angewendetem Gutschein ab, wird die Gutscheinreservierung freigegeben, `total_minor` bleibt aber reduziert. Eine verspätete Zahlung über den reduzierten Betrag (Stripe-Session lebt 40 min bei 30 min Hold; verzögerte Zahlarten; manuelle Bestätigung einer abgelaufenen Bestellung) besteht die Betragsprüfung, die Bestellung wird bezahlt und Tickets werden ausgegeben; erst danach scheitert `reserve_again` und es gibt nur `commit_failed` plus Admin-Mail. Die Bestellansicht zeigt bei abgelaufenen Bestellungen den reduzierten Betrag ohne Hinweis auf den freigegebenen Gutschein. **Mögliche Auswirkung:** Gutscheinwert wird doppelt genutzt (voll auf einer zweiten Bestellung und als Rabatt auf der ersten); begrenzt auf den Gutscheinwert je Bestellung, nachträglich erkennbar. Ob der Fehlschlag gemeldet wird oder still bleibt, hängt vom Verhalten von Voucher Captains `reserve()` mit veraltetem Quote ab (außerhalb dieses Repos, noch zu prüfen). Konkretisiert „Wiederherstellungstests für verspätete Zahlungsereignisse“. **Maßnahme:** In `Checkout::release()` `total_minor` (samt Steuer/Rabatt) auf den Bruttobetrag zurücksetzen, sodass eine verspätete reduzierte Zahlung an der Betragsprüfung scheitert; alternativ den Gutschein vor dem Bezahlt-Setzen erneut reservieren und bei Fehlschlag ohne Ticketausgabe nach `needs_review`/`paid_unallocated` leiten. Test: abgelaufene Gutscheinbestellung + späte Zahlung (Webhook und manuell).
- [x] [P1][BESTÄTIGT] `src/Admin/TicketsPage.php:17–26,144–156,192–196`; `src/Admin/Queries.php:289–310` — Nutzer, die nur `wpcap_checkin` besitzen (Rollen Einlass und Partner sowie jedes Konto mit Einlasszuweisung), sehen auf der Tickets-Seite Käufer-E-Mail, Teilnehmername und Teilnehmer-E-Mail aller Tickets ihrer Events und können per Freitext nach E-Mail-Fragmenten suchen. Scanner (`src/Checkin.php:378`), Mobile-API (`docs/MOBILE-API.md:136`), Teilnehmerliste (`src/Printing/AttendeeList.php:15–39`) und CONCEPT (`:546,:554,:556`) schließen genau diese Daten für Einlasspersonal aus; die Einstellung `scanner_show_attendee` wird hier nicht beachtet. **Mögliche Auswirkung:** Die niedrigste Staff-Stufe, auch temporäre Türkräfte, kann alle Käufer- und Teilnehmeradressen der zugewiesenen Events auslesen. Konkretisiert „Datenfelder je Rolle minimieren“. **Maßnahme:** In `TicketsPage::render` E-Mails nur mit `wpcap_manage_orders`/`wpcap_manage_events` ausgeben, Namen für reine Check-in-Nutzer nur bei `scanner_show_attendee`; in `Queries::search_tickets` E-Mail-/Namens-`LIKE` und die E-Mail-Spalten für Check-in-only-Events weglassen; Rollen-Negativtest ergänzen. — _erledigt (A119, src/Pii.php: Einlass-Profil sieht keine Käuferdaten)_
- [ ] [P2][BESTÄTIGT] `src/Mobile/Api.php:38,145–162`; `src/Admin/TicketsPage.php:80–106,133–137`; `src/Admission/Engine.php:203–222` — „Einlass zurücknehmen“ prüft nur den Eventbezug. Die Bereichsbindung von Personal und Geräten (`Checkin::deny` → `Areas::check`), die für Check-in und Check-out gilt, wird weder in der App-Route noch im Admin-Handler `wpcap_undo_checkin` angewendet; es gibt keine Beschränkung auf eigene Scans, eigenes Gerät oder ein Zeitfenster, und `device_id`/`area_id` werden beim Undo nicht protokolliert. **Mögliche Auswirkung:** Auf Bereich A beschränktes Personal kann Einlässe anderer Bereiche desselben Events zurücksetzen; ein Einmal-Ticket wird wieder gültig (Pass-back). Im Admin genügt die Ticket-ID, in der App wird der Ticketcode benötigt. **Maßnahme:** Bereichsregel in den gemeinsamen Senken (`Engine::undo_last`, `TicketsPage::undo_checkin`) und in `Api::undo` durchsetzen; Undo durch Türpersonal optional auf eigene/letzte Scans in kurzem Zeitfenster begrenzen; Gerät und Bereich im Undo-Eintrag speichern.
- [ ] [P2][BESTÄTIGT] `src/Reports/Scope.php:61–66,73` — `fputcsv()` wird mit Backslash als Escape-Zeichen aufgerufen. Ein Teilnehmername wie `x\";=1+1;\"` beginnt nicht mit einem Formelzeichen, passiert `csv_cell()` und wird so geschrieben, dass Excel/LibreOffice das Feld vorzeitig schließen und `=1+1` als eigene Zelle lesen. Betroffen sind Teilnehmerlisten-CSV (`src/Printing/Page.php:145–153`) sowie Berichts- und Partnerabrechnungs-CSV; `src/Invoices.php:375` übergibt bereits `''` und ist nicht betroffen. **Mögliche Auswirkung:** Anonyme Käufer können Formelzellen in Exporte einschleusen, die Mitarbeitende öffnen (Zugriff auf Nachbarzellen mit fremden Teilnehmerdaten, Hyperlink-/DDE-Varianten). PHP-Verhalten laut Dokumentation, nicht ausgeführt. **Maßnahme:** In `Scope::csv()` `''` als Escape-Argument übergeben; `Invoices::export_rows` auf `Scope::csv()` umstellen; Test mit Backslash-Anführungszeichen-Eingabe. — _siehe Arbeitsliste A495_
- [ ] [P2][BESTÄTIGT] `src/DeveloperKeys.php:29–30` — `authorize()` prüft für den Schlüsselinhaber nur `manage_options` oder eine Zeile in `wpcap_event_staff`, nicht die Rollen-Capability und nicht, ob das Konto noch existiert. `Access::can_access_event` verlangt beides. Es gibt keine `deleted_user`-/`set_user_role`-Hooks, die Staff-Zeilen oder Schlüssel aufräumen, und `expires_at` ist optional. **Mögliche Auswirkung:** Ein ausgeschiedener oder herabgestufter Nutzer behält über seinen Schlüssel dauerhaft Lesezugriff auf Eventdaten (auch Entwürfe) und Umsatz-/Check-in-Berichte dieses Events. Ergänzt „Entwickler-API-Schlüssel administrierbar machen“. **Maßnahme:** In `authorize()` Existenz des Nutzers und die zum Scope passende Rollen-Capability prüfen; Schlüssel und Staff-Zeilen bei `deleted_user`/`set_user_role` widerrufen bzw. löschen; Test mit herabgestuftem und gelöschtem Inhaber. — _siehe Arbeitsliste A278_
- [ ] [P2][BESTÄTIGT] `src/Rest/Controller.php:245–251`; `src/Settings.php:45–47`; `src/Orders.php:27,59–70,81–93,262–268`; `src/Campaigns.php:57–66` — Mit dem standardmäßig aktiven manuellen Gateway hält eine anonyme Checkout-Anfrage bis zu 10 Positionen × `max_per_order` für 72 Stunden ohne Zahlung; begrenzt nur durch 30 Anfragen/Stunde je IP. Es gibt keine Obergrenze für offene unbezahlte Bestellungen je E-Mail, IP oder Event; limitierte Kampagnencodes werden durch solche Bestellungen ebenfalls 72 Stunden belegt. `docs/SECURITY.md` verweist auf Hosting-Rate-Limits, nennt diese Verstärkung aber nicht. **Mögliche Auswirkung:** Kleine und mittlere Events lassen sich mit wenigen Anfragen dauerhaft unverkäuflich halten. Konkretisiert „Käuferlimits“. **Maßnahme:** Offene manuelle Bestellungen bzw. gehaltene Menge je E-Mail/IP/Event begrenzen oder manuelle Bestellungen zunächst kurz halten und erst nach Klick auf den gemailten Zugangslink auf `manual_hold_hours` verlängern; Einschränkung in `docs/SECURITY.md` benennen.
- [ ] [P3][BESTÄTIGT] `src/Rest/Controller.php:50–54,422–425`; `src/TicketAssignments.php:13,47,73,78` — Die öffentliche Zuweisungsroute hat kein `args`-Schema und kein Rate-Limit (anders als Nachsendung und Rechnungsdaten). Der Teilnehmername wird nur per `sanitize_text_field` bereinigt, ohne Längenbegrenzung, und unverändert in die Text-Mail an eine frei wählbare Adresse gesetzt. **Mögliche Auswirkung:** Mit einem (bei kostenlosen Tickets gratis erhältlichen) Bestelllink lässt sich je Ticket einmal ein beliebig langer Text samt URL von der Absenderdomain des Betreibers verschicken; keine Header- oder Zeileninjektion. **Maßnahme:** Namen auf 190 Zeichen kürzen (wie im WooCommerce-Pfad), optional URLs ablehnen, `RateLimit::allow` und REST-`args` mit `maxLength` ergänzen.
- [ ] [P3][BESTÄTIGT] `src/Admin/EventsPage.php:360–388` — Ein Event-Manager kann über die Staff-Zuweisung jedem auflösbaren WordPress-Konto (ID, Login oder E-Mail) dauerhaft die Nutzer-Capability `wpcap_checkin` geben; die Antwort unterscheidet bekannte von unbekannten Konten. Die Selbstzuweisung von Bestell-/Berichtsrechten im eigenen Event ist laut CONCEPT und CHANGELOG gewollte Delegation und kein Befund. **Mögliche Auswirkung:** Unaufgeforderte Capability auf fremden Konten (ohne Staff-Zeile ohne Datenzugriff) und Konten-Existenz-Orakel für Nicht-Admins. **Maßnahme:** Zielkonten für Nicht-Admins einschränken (z. B. vorhandene Pass-Captain-Rolle oder `list_users`), einheitliche Fehlermeldung; in CONCEPT festhalten, dass „Event verwalten“ die Delegation im eigenen Event einschließt.

### Härtung (kein Grenzübertritt nachgewiesen)

- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:445–466` — `pos_sale` bestätigt bei wiederverwendetem Idempotenzschlüssel die bestehende Bestellung ohne erneute Eventprüfung; bei `reused` `Scope::can_order()` prüfen.
- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:427–435` — `issue_invoice` lässt eine leere Eventmenge passieren (fail-open, derzeit nicht erreichbar); bei leerer Menge ablehnen. Ebenso `src/Checkin.php:306,331`: Gerätebeschränkung wird bei leerem `event_ids` übersprungen.
- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:240–311,272,284,290` — Öffentlicher Checkout mit wiederverwendetem Idempotenzschlüssel: Anbieterwechsel beim Retry erzeugt eine Zahlung, die kein Webhook zuordnet (bezahlt ohne Tickets); `cancel_order` kann eine inzwischen bezahlte Bestellung treffen. Anbieterabweichung ablehnen, nur „pending“ stornieren, Mindestlänge für Schlüssel erzwingen.
- [ ] [P3][HÄRTUNG] `src/Payments/Webhooks.php:140–148` — `refunded`-Ereignisse sind nicht an die aktuelle `provider_reference` gebunden; die Erstattung einer Doppelzahlung markiert die ganze Bestellung als erstattet und widerruft Tickets. `is_current_attempt` auch hier anwenden.
- [ ] [P3][HÄRTUNG] `src/Payments/PayPalGateway.php:132–136,249–257` — Capture erfolgt, bevor geprüft ist, dass die PayPal-Order zu einer bekannten, noch zahlbaren Bestellung gehört; Reihenfolge umdrehen. `src/Payments/StripeGateway.php:183`: Fallback auf `client_reference_id` entfernen, nur `metadata.wpcap_order` verwenden.
- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:291`; `src/Payments/PayPalGateway.php:169`; `src/Payments/StripeGateway.php:282–284`; `src/Mollie/MollieGateway.php:202` — Fehlertexte der Zahlungsanbieter und interne Ergebnislabels werden an anonyme Aufrufer durchgereicht; generische Meldung liefern, Details nur loggen.
- [ ] [P3][HÄRTUNG] `src/Refunds.php:34–38,69,106` — Idempotenz-Treffer wird vor `check_actor` zurückgegeben; Nutzer mit `wpcap_approve_refunds` führen eigene Erstattungen über der Schwelle ohne zweite Person aus. Prüfung vorziehen und Vier-Augen-Regel für Freigebende klären.
- [ ] [P3][HÄRTUNG] `src/Waitlists.php:15–18`; `src/ServiceBookings.php:15–18`; `src/Modules/Sms.php:178–187`; `src/Mobile/Api.php:50` — Limiter zählen vor der Validierung auf fremdsteuerbare Schlüssel (E-Mail, Telefonnummer, gemeinsame IP): Dritte können eine Adresse/Nummer für Warteliste, Buchung oder SMS-Opt-in bzw. die Gerätekopplung zeitweise sperren. Erst nach Validierung zählen bzw. nur bezahlte Bestellungen werten.
- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:356–366` — `order_status` nimmt das Zugangstoken als GET-Parameter (Server-/Proxy-Logs) und ruft `hash_equals` ohne `(string)`-Cast auf einem möglichen NULL-Hash auf (HTTP 500 unter PHP 8). Token per Header/POST annehmen und casten.
- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:516–529,730–738`; `src/Seats.php:36` — Die alten Routen `/events/{id}/seat-holds` sind nicht mit Bestellungen verknüpft und erlauben anonymes Blockieren von Legacy-Sitzen; ältere veröffentlichte Sitzplanversionen werden nie zurückgezogen. Routen entfernen oder schützen.
- [ ] [P3][HÄRTUNG] `src/Rest/Controller.php:760` — `source` beim Check-in kommt vom Client; auf `web` festlegen.
- [ ] [P3][HÄRTUNG] `src/WalletPasses/Store.php:135` — `register_device` aktualisiert `push_token` für alle Zeilen einer `device_library_id`, obwohl der Aufrufer nur für eine Seriennummer authentifiziert ist; auf diese Seriennummer beschränken. `src/WalletPasses/WebService.php:159–169`: `serve_raw` hängt global an `rest_pre_serve_request` und reagiert auf den Datenschlüssel `__wpcap_raw`; durch eigene Response-Klasse oder Routenprüfung ersetzen. `Store::key()` nicht gleichzeitig für Secretbox und Download-HMAC verwenden.
- [ ] [P3][HÄRTUNG] `assets/offline-scanner/app.js:624–634` — `?api=` wird übernommen, wenn es same-origin ist; zusätzlich verlangen, dass der Pfad auf `/wp-pass-captain/v1/app/` endet.
- [ ] [P3][HÄRTUNG] `src/Admin/Queries.php:230–237,347–355` — Bestelllisten zeigen Bestellungen mit Positionen aus mehreren Events, sobald eines im Scope liegt (E-Mail, Summe, fremder Eventname sichtbar); nur Bestellungen listen, deren Events alle im Scope sind. `src/Campaigns.php:104`: global eindeutige Codes verraten per 409 fremde Kampagnen.
- [ ] [P3][HÄRTUNG] `src/Privacy.php:154–162,195–236` — Der Datenschutzexport eines Käufers enthält Check-in-Protokoll und Wallet-Seriennummern auch für bereits übertragene Tickets; bei `transfer_count > 0` weglassen. `_wpcap_attendees`-Meta an nie ausgegebenen WooCommerce-Bestellungen wird weder von der Retention noch vom Löscher erfasst.
- [ ] [P3][HÄRTUNG] `src/TicketAssignments.php:67–81`; `src/Orders.php:903–917`; `src/Communications.php:52–53` — Die Mail an den vorherigen Inhaber erhält dieselben Variablen einschließlich `ticket_link` mit neuem Code (Standardvorlagen nutzen ihn nicht); eigenes Variablen-Array ohne Link bauen. Queue-Fallback, der die gespeicherte Nutzlast als Vorlage verwendet, entfernen.
- [x] [P3][HÄRTUNG] `src/Modules/Partners.php:155`; `src/Partners/Settlements.php:98,452` — Die Sperre gegen Doppelabrechnung gilt je (Partner, Quelle); nach Umhängen eines Events auf einen anderen Veranstalter können bereits abgerechnete Verkäufe erneut abgerechnet werden. Sperre partnerübergreifend je Quelle führen. — _erledigt (A448, plattformweite Abrechnungssperre)_
- [ ] [P3][HÄRTUNG] `.github/workflows/ci.yml`, `release.yml`; `build/release.sh:26` — Aktionen per Commit-SHA pinnen, `permissions: contents: read` und `persist-credentials: false` setzen, `composer install --no-scripts --no-plugins`, Prüfsumme/Attestierung für das ZIP veröffentlichen (ergänzt den bestehenden Release-Workflow-Befund).
- [ ] [P3][HÄRTUNG] WooCommerce — Drittanbieter-Gateways („Kauf auf Rechnung“), die vor Geldeingang `processing` setzen, lösen die Ticketausgabe aus, sofern sie nicht im Filter `wpcap_woocommerce_issue_on_completed_gateways` stehen; in `docs/WOOCOMMERCE.md` deutlich dokumentieren.
- [ ] [P3][HÄRTUNG] `src/Fiscalization/Providers/*` — Derzeit nicht erreichbar (kein Aufrufer). Vor der Integration von PR #14: `HttpClient` auf HTTPS und `wp_safe_remote_request` umstellen und den stillen Sandbox-Fallback in `FiskaltrustAdapter.php:11,21,78` entfernen.

### Offen / nicht abgedeckt

- [ ] Verhalten von Voucher Captain (`release()`, `reserve()` mit veraltetem Quote) für den Gutschein-Befund im anderen Repository prüfen.
- [ ] Laufzeitnachweise für die bestätigten Befunde als Integrationstests ergänzen (Mehrtypen-Überbuchung, späte Gutscheinzahlung, CSV-Escape, Undo außerhalb des Bereichs, Rollen-Negativtests für die Tickets-Seite).
- [ ] Nicht geprüft: Vendor-Bibliotheken (`tweetnacl`, `jsQR`), `composer.lock` auf bekannte Schwachstellen, `assets/seating/editor.js` nur auf HTML-Senken gegrept, PayPal-/Mollie-Zahlungslebensdauern, GitHub-Branch-Protection.
