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
- [ ] **B4 · `product_key` festlegen.** Der Wert muss exakt dem Produktschlüssel im Katalog des Lizenzservers entsprechen (Vorschlag `pass`). Tarife: Pro 1, Business 5, Agency 25 Live-Websites.
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

Diese Aufgaben gehören seit der Entscheidung vom 13.11.2026 ins Premium-Add-on, nicht mehr in dieses Plugin. Sie sind oben im Abschnitt „Kern-Plugin frei, Premium als getrenntes Add-on“ unter B5 (Rechte), B6 (Ablauf und Ausfall), B7 (Updater) und B10 (Tests) aufgegangen.

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


## Sicherheitsaudit – main (ZIP-Stand 2026-11-14)

Quelltext-Audit nach der Methodik des Cloudflare-`security-audit`-Skills: acht parallele Prüfbereiche (REST-Autorisierung, Checkout/Bestand, öffentliche Seiten/XSS, Zahlungen/Webhooks, Scanner/Mobile/Wallet, Partner-API/Webhooks/SMS, wp-admin, Datenlebenszyklus/WooCommerce/CI plus Muster-Sweep), danach unabhängige Gegenprüfung jedes Kandidaten. Es wurde nur gelesen; kein Plugin-Code wurde ausgeführt, keine Laufzeit- oder Exploit-Tests. „BESTÄTIGT“ heißt daher: Der Pfad ist im Quelltext vollständig nachvollzogen. Dieser Durchlauf ersetzt nicht den offenen Punkt „Unabhängigen Sicherheitsreview/Penetrationstest durchführen“.

Abgleich mit dem bestehenden Backlog: Bereits erfasst und hier nicht wiederholt sind Klartext-Secrets in `wpcap_settings`, Rate-Limits hinter Proxy/CDN und nicht atomare Transient-Zähler, SSRF-/HTTP-Absicherung ausgehender Aufrufe, Release-Workflow ohne CI-Bindung, Permission-Matrix, Dependency-/Secret-Scanning und Admin-Oberfläche für Entwickler-API-Schlüssel. Die Punkte unten sind neu oder konkretisieren einen bisher nur allgemein formulierten Punkt (jeweils vermerkt).

Ohne Befund geprüft: SQL-Injection (alle Abfragen prepared oder Integer-Listen), XSS in PHP-Ausgabe und JavaScript, Open Redirects, Token-Erzeugung und -Vergleich (`random_bytes`, nur Hashes, `hash_equals`), Webhook-Signaturen von Stripe/PayPal/Mollie/Twilio (fail-closed), Betrags- und Währungsprüfung vor „bezahlt“, Refund-Obergrenzen, Partner-API-Mandantentrennung, Gerätekopplung, Offline-Paket-Signaturen, Wallet-Pass-Authentifizierung, Nonce- und Capability-Prüfungen aller Admin-Handler, WooCommerce-Preisbindung, eingebettete Zugangsdaten.

### Bestätigte Befunde

- [ ] [P1][BESTÄTIGT] `src/Orders.php:161–171,503–510,248–273` — Die Termin-Kapazität wird pro Bestellposition geprüft, nicht pro Bestellung: `capacity_error()` rechnet nur mit der Menge der jeweiligen Position, und die Reservierungen aller Positionen werden erst nach der Schleife geschrieben. Die Late-Payment-Prüfung (`:684–704`) summiert dagegen korrekt. **Mögliche Auswirkung:** Eine einzige anonyme Bestellung mit mehreren Tickettypen desselben Termins erhält bis zu Restkapazität × Anzahl Typen gültige Tickets (z. B. 8 Restplätze, 3 Typen → 24 Tickets); bei kostenlosen Events ohne Kosten. Die Aussagen „Checkout-Käufe überverkaufen das Kontingent nicht“ (P0 Reservierung, Abnahmekriterien) gelten nur für parallele Bestellungen, nicht für diesen Fall. **Maßnahme:** In `create()` die Nachfrage je `occurrence_id` summieren und die Terminprüfung einmal je Termin mit der Summe ausführen; Tickettyp-Prüfung je Position beibehalten; Integrationstest mit Mehrtypen-Bestellung an der Kapazitätsgrenze ergänzen.
- [ ] [P1][BESTÄTIGT] `src/Integrations/Vouchers/Checkout.php:48,104,118,271–283,150–263`; `src/Payments/Webhooks.php:97,113,121`; `src/Orders.php:657–725,1026–1036,1049–1075`; `src/Admin/OrdersPage.php:389–392,471` — Läuft eine Bestellung mit angewendetem Gutschein ab, wird die Gutscheinreservierung freigegeben, `total_minor` bleibt aber reduziert. Eine verspätete Zahlung über den reduzierten Betrag (Stripe-Session lebt 40 min bei 30 min Hold; verzögerte Zahlarten; manuelle Bestätigung einer abgelaufenen Bestellung) besteht die Betragsprüfung, die Bestellung wird bezahlt und Tickets werden ausgegeben; erst danach scheitert `reserve_again` und es gibt nur `commit_failed` plus Admin-Mail. Die Bestellansicht zeigt bei abgelaufenen Bestellungen den reduzierten Betrag ohne Hinweis auf den freigegebenen Gutschein. **Mögliche Auswirkung:** Gutscheinwert wird doppelt genutzt (voll auf einer zweiten Bestellung und als Rabatt auf der ersten); begrenzt auf den Gutscheinwert je Bestellung, nachträglich erkennbar. Ob der Fehlschlag gemeldet wird oder still bleibt, hängt vom Verhalten von Voucher Captains `reserve()` mit veraltetem Quote ab (außerhalb dieses Repos, noch zu prüfen). Konkretisiert „Wiederherstellungstests für verspätete Zahlungsereignisse“. **Maßnahme:** In `Checkout::release()` `total_minor` (samt Steuer/Rabatt) auf den Bruttobetrag zurücksetzen, sodass eine verspätete reduzierte Zahlung an der Betragsprüfung scheitert; alternativ den Gutschein vor dem Bezahlt-Setzen erneut reservieren und bei Fehlschlag ohne Ticketausgabe nach `needs_review`/`paid_unallocated` leiten. Test: abgelaufene Gutscheinbestellung + späte Zahlung (Webhook und manuell).
- [ ] [P1][BESTÄTIGT] `src/Admin/TicketsPage.php:17–26,144–156,192–196`; `src/Admin/Queries.php:289–310` — Nutzer, die nur `wpcap_checkin` besitzen (Rollen Einlass und Partner sowie jedes Konto mit Einlasszuweisung), sehen auf der Tickets-Seite Käufer-E-Mail, Teilnehmername und Teilnehmer-E-Mail aller Tickets ihrer Events und können per Freitext nach E-Mail-Fragmenten suchen. Scanner (`src/Checkin.php:378`), Mobile-API (`docs/MOBILE-API.md:136`), Teilnehmerliste (`src/Printing/AttendeeList.php:15–39`) und CONCEPT (`:546,:554,:556`) schließen genau diese Daten für Einlasspersonal aus; die Einstellung `scanner_show_attendee` wird hier nicht beachtet. **Mögliche Auswirkung:** Die niedrigste Staff-Stufe, auch temporäre Türkräfte, kann alle Käufer- und Teilnehmeradressen der zugewiesenen Events auslesen. Konkretisiert „Datenfelder je Rolle minimieren“. **Maßnahme:** In `TicketsPage::render` E-Mails nur mit `wpcap_manage_orders`/`wpcap_manage_events` ausgeben, Namen für reine Check-in-Nutzer nur bei `scanner_show_attendee`; in `Queries::search_tickets` E-Mail-/Namens-`LIKE` und die E-Mail-Spalten für Check-in-only-Events weglassen; Rollen-Negativtest ergänzen.
- [ ] [P2][BESTÄTIGT] `src/Mobile/Api.php:38,145–162`; `src/Admin/TicketsPage.php:80–106,133–137`; `src/Admission/Engine.php:203–222` — „Einlass zurücknehmen“ prüft nur den Eventbezug. Die Bereichsbindung von Personal und Geräten (`Checkin::deny` → `Areas::check`), die für Check-in und Check-out gilt, wird weder in der App-Route noch im Admin-Handler `wpcap_undo_checkin` angewendet; es gibt keine Beschränkung auf eigene Scans, eigenes Gerät oder ein Zeitfenster, und `device_id`/`area_id` werden beim Undo nicht protokolliert. **Mögliche Auswirkung:** Auf Bereich A beschränktes Personal kann Einlässe anderer Bereiche desselben Events zurücksetzen; ein Einmal-Ticket wird wieder gültig (Pass-back). Im Admin genügt die Ticket-ID, in der App wird der Ticketcode benötigt. **Maßnahme:** Bereichsregel in den gemeinsamen Senken (`Engine::undo_last`, `TicketsPage::undo_checkin`) und in `Api::undo` durchsetzen; Undo durch Türpersonal optional auf eigene/letzte Scans in kurzem Zeitfenster begrenzen; Gerät und Bereich im Undo-Eintrag speichern.
- [ ] [P2][BESTÄTIGT] `src/Reports/Scope.php:61–66,73` — `fputcsv()` wird mit Backslash als Escape-Zeichen aufgerufen. Ein Teilnehmername wie `x\";=1+1;\"` beginnt nicht mit einem Formelzeichen, passiert `csv_cell()` und wird so geschrieben, dass Excel/LibreOffice das Feld vorzeitig schließen und `=1+1` als eigene Zelle lesen. Betroffen sind Teilnehmerlisten-CSV (`src/Printing/Page.php:145–153`) sowie Berichts- und Partnerabrechnungs-CSV; `src/Invoices.php:375` übergibt bereits `''` und ist nicht betroffen. **Mögliche Auswirkung:** Anonyme Käufer können Formelzellen in Exporte einschleusen, die Mitarbeitende öffnen (Zugriff auf Nachbarzellen mit fremden Teilnehmerdaten, Hyperlink-/DDE-Varianten). PHP-Verhalten laut Dokumentation, nicht ausgeführt. **Maßnahme:** In `Scope::csv()` `''` als Escape-Argument übergeben; `Invoices::export_rows` auf `Scope::csv()` umstellen; Test mit Backslash-Anführungszeichen-Eingabe.
- [ ] [P2][BESTÄTIGT] `src/DeveloperKeys.php:29–30` — `authorize()` prüft für den Schlüsselinhaber nur `manage_options` oder eine Zeile in `wpcap_event_staff`, nicht die Rollen-Capability und nicht, ob das Konto noch existiert. `Access::can_access_event` verlangt beides. Es gibt keine `deleted_user`-/`set_user_role`-Hooks, die Staff-Zeilen oder Schlüssel aufräumen, und `expires_at` ist optional. **Mögliche Auswirkung:** Ein ausgeschiedener oder herabgestufter Nutzer behält über seinen Schlüssel dauerhaft Lesezugriff auf Eventdaten (auch Entwürfe) und Umsatz-/Check-in-Berichte dieses Events. Ergänzt „Entwickler-API-Schlüssel administrierbar machen“. **Maßnahme:** In `authorize()` Existenz des Nutzers und die zum Scope passende Rollen-Capability prüfen; Schlüssel und Staff-Zeilen bei `deleted_user`/`set_user_role` widerrufen bzw. löschen; Test mit herabgestuftem und gelöschtem Inhaber.
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
- [ ] [P3][HÄRTUNG] `src/Modules/Partners.php:155`; `src/Partners/Settlements.php:98,452` — Die Sperre gegen Doppelabrechnung gilt je (Partner, Quelle); nach Umhängen eines Events auf einen anderen Veranstalter können bereits abgerechnete Verkäufe erneut abgerechnet werden. Sperre partnerübergreifend je Quelle führen.
- [ ] [P3][HÄRTUNG] `.github/workflows/ci.yml`, `release.yml`; `build/release.sh:26` — Aktionen per Commit-SHA pinnen, `permissions: contents: read` und `persist-credentials: false` setzen, `composer install --no-scripts --no-plugins`, Prüfsumme/Attestierung für das ZIP veröffentlichen (ergänzt den bestehenden Release-Workflow-Befund).
- [ ] [P3][HÄRTUNG] WooCommerce — Drittanbieter-Gateways („Kauf auf Rechnung“), die vor Geldeingang `processing` setzen, lösen die Ticketausgabe aus, sofern sie nicht im Filter `wpcap_woocommerce_issue_on_completed_gateways` stehen; in `docs/WOOCOMMERCE.md` deutlich dokumentieren.
- [ ] [P3][HÄRTUNG] `src/Fiscalization/Providers/*` — Derzeit nicht erreichbar (kein Aufrufer). Vor der Integration von PR #14: `HttpClient` auf HTTPS und `wp_safe_remote_request` umstellen und den stillen Sandbox-Fallback in `FiskaltrustAdapter.php:11,21,78` entfernen.

### Offen / nicht abgedeckt

- [ ] Verhalten von Voucher Captain (`release()`, `reserve()` mit veraltetem Quote) für den Gutschein-Befund im anderen Repository prüfen.
- [ ] Laufzeitnachweise für die bestätigten Befunde als Integrationstests ergänzen (Mehrtypen-Überbuchung, späte Gutscheinzahlung, CSV-Escape, Undo außerhalb des Bereichs, Rollen-Negativtests für die Tickets-Seite).
- [ ] Nicht geprüft: Vendor-Bibliotheken (`tweetnacl`, `jsQR`), `composer.lock` auf bekannte Schwachstellen, `assets/seating/editor.js` nur auf HTML-Senken gegrept, PayPal-/Mollie-Zahlungslebensdauern, GitHub-Branch-Protection.
