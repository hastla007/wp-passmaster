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


## P1 – Form Captain und Review Captain Integration (neu, 15.11.2026)

- [ ] **Form Captain:** Öffentliche, versionierte Formularreferenz/Einbettung integrieren; Eventspezifische Formulare in Event-Admin auswählen und veröffentlichen. Ein Formular-Entry darf nie automatisch Reservierung, Zahlung oder Ticket erzeugen.
  - *Stand 16.11.2026 (aus wp-formmaster P8-05, Branch `claude/sleepy-ptolemy-n32guj`):* Form Captain Free liefert jetzt Contract 1.1.0 (`wp-formmaster/docs/spec/api-v1.md`, `docs/spec/suite-kontext.md`). Für den Adapter stehen bereit: `form_captain_get_form_v1()`, `form_captain_get_render_v1()`, Block `form-captain/form` (Attribut `formId` = öffentliche ID), `form_captain_register_context_purpose_v1()` und `form_captain_sign_context_v1()` für den signierten Kontext `_fc_ctx`, Formular-Einstellung `accepted_context_purposes`. Konkrete Schritte:
  - [x] **PASS-FC-01** Kontext-Zweck `pass.event_registration` registrieren (`ref_types: ['event']`, Ausgabeweg `public_endpoint`, weil Eventseiten gecacht werden; `issue_check`: Event existiert, ist veröffentlicht und nicht abgesagt). Nur nach `form_captain_loaded_v1`; ohne Form Captain oder bei inkompatibler Contract-Major nichts registrieren und im Admin melden.
    - *Erledigt 16.11.2026:* `src/Integrations/FormCaptain/Adapter.php` (registriert über das Modul `src/Modules/FormCaptain.php`). Registrierung nach `form_captain_loaded_v1` (auch wenn der Hook schon gelaufen ist, `did_action`), wegen des übersetzten Labels spätestens auf `init`; Definition: Eigentümer `pass`, Contract `^1.0`, TTL 12 h / 24 h, nicht einweg, `issue_check` = `submit_check` (Event veröffentlicht, mindestens ein nicht abgesagter Termin, Formular ist das dem Event zugeordnete), `marks_verified`. Ohne Form Captain inaktiv; andere Contract-Major oder abgelehnte Registrierung → Hinweis nur im Abschnitt „Formular (Form Captain)“ der Event-Seite. Tests: `tests/integration/modules/form_captain.php` (Abschnitte „without Form Captain“, „contract version“), `tests/form-captain/EventRegistrationTest.php::test_purpose_is_registered_after_form_captain_loaded`.
  - [x] **PASS-FC-02** Event-Admin: Formular wählen (nur veröffentlichte Formulare, deren `accepted_context_purposes` den Zweck enthält), speichern als Event-Meta über die Pass-API, nicht über Form-Tabellen.
    - *Erledigt 16.11.2026:* `src/Integrations/FormCaptain/EventForms.php`, eigene Tabelle `wpcap_event_forms` (Modul-Schema), Abschnitt über die neue Action `wpcap_event_admin_sections` in `src/Admin/EventsPage.php` (einzige Änderung an bestehendem Code), admin-post `wpcap_form_captain_event_form` mit Nonce, Recht `wpcap_manage_events` + Event-Scope, Audit `event_form_assigned`/`event_form_removed`. Der Vertrag hat keine Listenfunktion: Eingabe der Formular-ID oder öffentlichen ID, Prüfung über `form_captain_get_form_v1()`, nur Status `published`. Statt die Formular-Einstellung zu ändern, nimmt der Adapter den Zweck für zugeordnete Formulare über `form_captain_accepted_context_purposes_v1` an (Contract 1.1.0). Tests: `form_captain.php` (Abschnitte „event form assignment“, „accepted purposes“), `EventRegistrationTest::test_only_published_forms_can_be_assigned`.
  - [x] **PASS-FC-03** Eventseite: Formular über `form_captain_get_render_v1()` bzw. den Block mit Kontextbezug `event:<id>` ausgeben; ohne Auswahl nichts ausgeben.
    - *Erledigt 16.11.2026:* `Adapter::append_to_card()` am Filter `wp_pass_captain_event_card_html` (in der Event-Karte nach dem Checkout-Formular, je Event und Seite einmal). Übergibt `context_ref` (`pass.event_registration`/`event`/`<id>`) für die no-store-Token-Route und als Rückfall einen beim Rendern signierten Kontext mit 24 h Laufzeit (nicht personenbezogen, nicht einweg, daher cachebar). Hinweis an Form Captain: `ContractApi::render()` reicht `context_ref` derzeit nicht an den Renderer durch (nur `context`, `title`), deshalb trägt bis dahin der Rückfallkontext. Tests: `form_captain.php` (Abschnitt „event page“), `EventRegistrationTest::test_event_card_shows_the_form_with_context`.
  - [x] **PASS-FC-04** Abgrenzung testen: Einsendung erzeugt keine Reservierung, Zahlung oder Ticket; manipulierte Event-ID im Browser wird von Form Captain abgelehnt (`context_signature_invalid`), abgesagtes Event liefert `context_ref_rejected`.
    - *Erledigt 16.11.2026:* Gegen das echte Form Captain (Zwei-Plugin-Test, `docs/TESTING.md`): `EventRegistrationTest::test_submission_with_verified_event_context_books_nothing` (Einsendung `created`, Entry-Kontext `event:<id>`, Bestellungen/Reservierungen/Tickets unverändert), `::test_tampered_event_id_is_rejected` (`detail_code` `context_signature_invalid`), `::test_cancelled_event_is_rejected` (`context_ref_rejected` beim Absenden und Signieren). Der Adapter registriert keine Formularaktion und keinen Listener auf Entry-Ereignisse (`form_captain.php`, Abschnitt „a submission never books“).
  - [ ] **PASS-FC-05** Optionale explizite Übergabe (zweiter Bullet unten) erst nach Freigabe des Inhabers; dann als Formularaktion über `form_captain_register_action_v1()` mit Feld-Allowlist und Idempotency-Key.
    - *Offen (16.11.2026):* bewusst nicht gebaut, wartet auf die Freigabe des Inhabers. Der Adapter aus PASS-FC-01..04 übergibt keine Daten an Pass Captain.
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
