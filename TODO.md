# TODO – Pass Captain

Dieses Backlog beschreibt den Abgleich mit dem aktuellen Stand von `main` (Plugin 1.4.0 plus die unveröffentlichten Änderungen laut `CHANGELOG.md`, Stand 2026-11-14). Häkchen bedeuten: Der beschriebene Umfang ist im Code und/oder in den automatisierten Prüfungen vorhanden. Teilumsetzungen bleiben offen und nennen den Restumfang. Voucher Captain bleibt eine optionale, separat installierbare Integration.

## P0 – Repository und Plugin-Grundlage

- [x] Produkt- und Repo-Namen festlegen: **Pass Captain** (umbenannt von „WP Pass Captain“, siehe A7b), Plugin-Slug/Ordner `pass-captain`, Repository `wp-passmaster`, Textdomain `pass-captain`, Namespace `PassCaptain` und Plugin-Konstanten `WPCAP_*`. REST-Namensraum `wp-pass-captain/v1`, Blocknamen und Shortcodes behalten bewusst die alten Namen.
- [x] Architekturentscheidungen für Mindestversionen, Custom Tables, Zahlungsstatus und Adapter festhalten (PHP 8.2+, WordPress 6.4+, WooCommerce optional; Details in README, `docs/` und Schema).
- [x] Plugin-Bootstrap, Composer-Autoloading, Aktivierungs-/Deaktivierungshooks, Capabilities, REST-Grundlage und Adminnavigation erstellen.
- [x] Composer-Abhängigkeiten, Lizenzdokumentation und installierbares Release-ZIP einrichten (CI-Build vorhanden).
- [x] Versioniertes Schema und Upgrade-Migrationen erstellen; Migration läuft kontrolliert im Admin, per Cron oder WP-CLI.
- [ ] Rollback-/Restore-Verhalten mit echten Datenbankständen erproben und dokumentieren.
- [x] Plugin muss ohne WooCommerce und ohne Voucher Captain aktivierbar und funktionsfähig sein; beide Integrationen bleiben optional.

## P0 – Event- und Verkaufsgrundlage

- [x] Events und Occurrences mit Zeitzonen, Veranstaltungsorten und Status modellieren.
- [x] Tickettypen mit Preis, Währung, Kontingent und Mengenlimits implementieren.
- [ ] Steuersatz je Ticketart (`tax_rate_bp`) fließt bereits in Positions-Snapshots, Rechnungen und Gutschriften ein. Offen: die versionierten Länder-/Steuerprofile (`wpcap_tax_profiles`, bisher nur als REST-Entwurf) an Bestellungen anbinden und geprüfte länderspezifische Verkaufs-/Steuerregeln hinterlegen.
- [x] Öffentliche Eventseite als Gutenberg-Block und Shortcode mit Ticket-Auswahl bereitstellen; öffentliche Tastatur-/A11y-Prüfungen sind vorhanden.
- [x] Native Bestellungen mit Käuferdaten, Positionen, Statusübergängen und Idempotenz erstellen.
- [ ] Teilnehmerfelder: Feld-Editor je Ticketart (Admin „Events“; Typen Text/E-Mail/Textbereich/Checkbox, Zweck, Aufbewahrungsdauer), Checkout-Erfassung mit Pflicht- und E-Mail-Validierung (`Orders`), Änderung im Bestellportal mit Frist `attendee_edit_hours`, rollenbeschränkte Teilnehmerliste/CSV (`Printing/AttendeeList`), Datenschutzexport/-löschung und fristgesteuerte Löschung (`Privacy/Retention`) sind umgesetzt. Offen: eigene Einwilligungssteuerung für Teilnehmerfelder (protokollierte Einwilligung mit Text/Version/Zeitpunkt und Widerruf); bisher nur über Pflicht-Checkbox-Felder abbildbar.
- [x] Kampagnencodes mit Prozent-/Festbetragsrabatt, Event-/Ticket-Scope, Priorität, globalem Nutzungslimit und Preis-Snapshot implementieren.
- [ ] Checkout-Oberfläche (`assets/checkout.js`), Verkaufsfenster je Ticketart, `max_per_order` sowie Kampagnen-Zeitfenster mit höchstens drei Codes sind vorhanden. Offen: Käuferlimits über mehrere Bestellungen (je E-Mail/Konto), konfigurierbare Stapelregeln für Kampagnen und eine Admin-Oberfläche für Kampagnen (derzeit nur REST).

## P0 – Reservierung, Zahlung und Ausgabe

- [x] Atomare, zeitlich begrenzte Reservierungen für Mengen implementieren; die Standard-Haltezeit beträgt zehn Minuten.
- [x] Bestand aus Verkäufen und aktiven Holds berechnen; Nebenläufigkeits-/Lasttests belegen, dass Checkout-Käufe das Kontingent nicht überverkaufen.
- [x] Abgelaufene Holds und Checkout-Abbrüche geben Bestand frei; Zahlungsereignisse werden idempotent verarbeitet.
- [ ] Anbieter-Sandbox- und Wiederherstellungstests für verspätete/mehrdeutige Live-Zahlungsereignisse abschließen.
- [x] Payment-Gateway-Vertrag sowie Adapter für manuelle Bestätigung, Stripe, PayPal und Mollie (optionales Modul `Modules/Mollie`) implementieren.
- [x] Signaturprüfung und Idempotenz für Zahlungs-Webhooks implementieren; Kartendaten werden nicht selbst gespeichert.
- [x] Preis-/Positions-Snapshots und Refund-Status im Bestellablauf speichern.
- [ ] Bruttosteuer je Position wird berechnet, bei Refunds entstehen Rechnungskorrekturen (`Invoices::credit_note_for_refund`). Offen: rechtliche Prüfung der Gutschriften/Korrekturbelege sowie alle Teil-/Vollrefund-Sonderfälle je Land.
- [x] Pro gekaufter Einheit idempotent ein Ticket erzeugen und mit Bestellung/Position verknüpfen.

## P0 – QR-Ticket und Online-Check-in

- [ ] Tickets verwenden zufällige 256-Bit-Codes; gespeichert wird nur deren Hash, und der QR enthält keine PII oder fortlaufende Datenbank-ID. Ein separates signiertes QR-Payload ist nicht umgesetzt; Sicherheits- und Interoperabilitätsentscheidung dafür dokumentieren.
- [x] Ticketlink/QR, E-Mail-Zustellung, private Druckansicht, native Ticket-PDF als Mail-Anhang (`TicketPdf`, Filter `wpcap_attach_ticket_pdf`) und Bestell-/Ticketportal mit Teilnehmer-Selbstbedienung (`OrderPortal`) sind umgesetzt.
- [x] Mobilfreundlichen Online-Scanner mit Kamera-QR-Scan und manueller Codeeingabe bereitstellen.
- [ ] Vollständige installierbare PWA-Abnahme für Browser/Endgeräte dokumentieren.
- [x] Check-in-Nebenläufigkeitsfehler behoben: Ticketzeile wird gesperrt, sämtliche Schreibvorgänge/Commit werden geprüft und der Check-in-Lasttest ist ein verpflichtender CI-Schritt (zuletzt erfolgreicher main-CI-Lauf #492).
- [x] Auditprotokolle für Check-ins, Bestellungen, Zahlungs-/Refund- und Adminaktionen sind implementiert.
- [ ] Audit-Log deckt bereits Exporte (Berichte, Buchhaltung, Teilnehmerlisten), Event-/Bereichspersonal-Zuweisungen, Ticketübertragungen, Teilnehmerzuweisungen, Check-in-Rücknahmen und Erstattungen ab. Offen: Änderungen an WordPress-Rollen/Capabilities, lesende PII-Zugriffe (Bestell-/Ticketdetailansichten) und eine Admin-Ansicht für das Audit-Log.
- [x] Admin-Bestell-/Ticketansichten, Ticketnachsendung, Widerruf und Fehler-/Retry-Abläufe bereitstellen; Restfälle im echten WordPress-Betrieb abnehmen.

## P0 – Datenschutz und Releasefähigkeit

- [x] WordPress-Datenschutzexport und Löschschnittstellen einbinden.
- [x] Datenschutzfristen: tägliche Durchsetzung (`Privacy/Retention`) mit getrennten Fristen für Teilnehmerantworten (Einstellung `attendee_retention_days` oder Frist je Feld), Teilnehmerkontaktdaten, unbezahlte Bestellungen, Queue-Inhalte, Check-in-Personaldaten und Audit-Log (per Filter anpassbar); Finanzbelege bleiben unberührt, Löschanfragen bei kommenden Terminen werden zurückgestellt.
- [x] Nonces, Capabilities, REST-Validierung, Escaping, Rate-Limits und Bereinigung sensibler Logdaten sind im Code abgedeckt.
- [ ] Unabhängigen Sicherheitsreview/Penetrationstest mit priorisiertem Befundregister, Fixes und Wiederholungstest abschließen; `docs/SECURITY.md` und Meldungsprozess daran anpassen.
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
- [x] Ticketprodukte, Bestellstatus, kostenlose Bestellungen, wiederholte Hooks und Refund-/Stornoereignisse integrieren. Mehrverkäufer-Warenkörbe werden in WooCommerce weder aufgeteilt noch abgelehnt (siehe „Fiskalisierung und Multi-Seller“ und „Multi-Seller-Geschäftsabläufe“).

## P2 – Erweiterte Eventfunktionen

- [x] Sitzpläne: versionierte Layouts mit grafischem Admin-Editor „Saalpläne“ (`Seating/AdminPage`, `assets/seating/editor.js`), Verknüpfung mit Terminen und Zonen je Ticketart, Platzwahl im nativen Checkout (`Seating/Picker`, `assets/seating/picker.js`), atomare Platz-Holds, Verkaufs-Commit bei Zahlung (`Reservations::on_order_paid`), Freigabe bei Storno/Widerruf/Refund sowie Platzangabe in Mail und Ticket. WooCommerce zeigt keine Platzwahl, sondern weist die besten freien Plätze automatisch zu.
- [x] Terminserien: Regeln täglich/wöchentlich/monatlich/Datumsliste mit Ausnahmen und Sommerzeitbehandlung (`Series/Rules`), Admin „Serientermine“ mit Vorschau (`Series/AdminPage`), individuelle Kapazität, relative Verkaufsfenster, Sitzplanverknüpfung, gemeinsame Kontingente (`Series/Pools`) und Preisregeln (`Series/Pricing`); einzelne Termine bleiben im Event-Admin editierbar, Änderungen werden je Termin im Audit-Log protokolliert.
- [ ] Apple-Wallet- (.pkpass mit Signatur, APNs-Updates, PassKit-Webservice) und Google-Wallet-Erzeugung (Klassen/Objekte, Speichern-Link, REST-Updates) sind umgesetzt (`src/WalletPasses/*`, `docs/WALLET.md`). Offen: Betreiberkonten und Zertifikate einrichten, Live-Abnahme mit Apple/Google, Wallet-Links im Bestellportal und Pässe für vor der Modulaktivierung ausgestellte Tickets.
- [ ] Rechnungen und Rechnungskorrekturen mit lückenlosen Nummernkreisen, USt aus Positions-Snapshots, Rechnungs-PDF (`Printing/InvoicePdf`), Vermittler-Modell und Buchhaltungsexport sind umgesetzt; rechtliche/steuerliche Freigabe der Belege je Land fehlt.
- [ ] Berichte (`Reports/*`): Umsatz brutto/netto/USt je Event, Termin, Ticketart, Zahlungsanbieter und Tag mit Zeitraumfilter, Erstattungen, Auslastung, Check-in-Quoten, Kasse-/Online-Anteil und rollenbeschränktem CSV-Export; Buchhaltungsexport, Gutschriften und Teilnehmerliste/CSV sind vorhanden. Offen: Vergleichszeiträume, Kampagnenauswertung und fachliche Abnahme der Zahlen.
- [x] Offline-Scanner: signierte Offline-Pakete (Ed25519) mit lokaler Prüfung (`OfflineScanner/Package`), Gerätezuweisung je Event/Termin (`OfflineScanner/Assignments`) und Einlassbereich, Opt-in je Event mit Duplikatrichtlinie (`OfflineScanner/Rules`), Batch-Synchronisierung und Konfliktseite „Einlass-Konflikte“ (`Admission/ConflictsPage`). Offlinebetrieb bleibt prinzipbedingt nicht konfliktfrei: Doppel-Einlässe werden erst beim Sync erkannt und zur Klärung vorgelegt.

## P2 – Entscheidungen vor Produktivstart

- [ ] Startländer festlegen (AT/DE/CH) und Rechts-/Steuerprüfungen je Land beauftragen.
- [x] Erste Online-PSPs sind Stripe und PayPal, ergänzt um Mollie; weitere Anbieter/Länder-Zahlungsarten bleiben separat zu priorisieren.
- [x] MVP-Entscheidung native Rechnungs-PDFs oder Buchhaltungsexport: beides ist umgesetzt (`Printing/InvoicePdf`, Buchhaltungsexport in `Invoices`).
- [ ] Sitzpläne sind umgesetzt (siehe oben); festlegen, ob die Seat Map im ersten marktfähigen Release freigegeben oder als experimentell gekennzeichnet wird.

## Abnahmekriterien

- [x] Native Bestellungen funktionieren ohne WooCommerce; je gekaufter Einheit wird idempotent ein Ticket erzeugt.
- [x] Nebenläufigkeitstests belegen, dass parallele Checkouts das Kontingent nicht überverkaufen.
- [x] Einmal-Check-in lehnt doppelte Scans atomar ab; der verpflichtende CI-Nebenläufigkeitstest besteht. Zusätzliche Heavy-/REST-Profile bleiben als Härtung offen.
- [x] Pass Captain funktioniert ohne Voucher Captain; Voucher Captain funktioniert ohne Pass Captain. *(CI beider Plugins läuft ohne das andere)*
- [x] Gemeinsame Installation aktiviert die definierte Bridge, ohne separate Plugin-Einträge oder Admin-Menüs zusammenzulegen.
- [x] Gutschein-zu-Ticket-Einlösung und Ticket-Rückerstattung sind idempotent und revisionsfähig.
- [ ] Release-Gates aus Konzept sind bestanden und dokumentiert.

## P1 – Ticketverwaltung nach dem Kauf

- [x] Käuferportal/Gastlinks mit Selbstbedienung: erneuter Versand (höchstens dreimal pro Stunde), Teilnehmerzuweisung mit Zusatzfeldern und Änderungsfrist, einmalige Übertragung und Rechnungsangaben (`OrderPortal`).
- [x] Einmalige Ticketübertragung vor Veranstaltungsbeginn mit Tokenrotation und Widerruf des alten Codes ist umgesetzt; ausbauen für konfigurierbare Fristen/Regeln.
- [ ] Eventabsage, Terminabsage mit automatischen Erstattungen (`OccurrenceCancellation`), Terminverschiebung mit Benachrichtigung (E-Mail, SMS, Webhook, Wallet) sowie Refunds und Widerruf sind umgesetzt. Offen: Regeln für Refund nach Check-in, Wiederzulassung nach Widerruf und alle Statusfolgen festlegen und umsetzen.
- [ ] Idempotenz ist in vielen Bestell-/Zahlungspfaden vorhanden; vollständige End-to-End-Abnahme für Storno, Widerruf, Neuausgabe und alle Refundkombinationen fehlt.
- [x] E-Mail-Vorlagen mit Admin-Editor (`Mail/Types`, `Mail/Admin`) für Tickets, Nachsendung, Bestellzugang, Übertragung (Absender/Empfänger), Teilnehmerzuweisung, Erstattungsbestätigung, Event-/Terminabsage, Terminverschiebung, Warteliste und Mitgliedspass.

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
- [x] Einlassregeln und Status pro Veranstaltungstag sind im Backend vorhanden, Admin-Oberfläche „Einlassregeln“ (`Admission/RulesPage`) ebenfalls; echte Mehrtagesticket-Abnahme fehlt.
- [ ] Check-in-Rücknahme mit Grund und Audit (`TicketsPage::undo_checkin`) und Check-out im Web-Scanner (`Checkin::check_out`) sind umgesetzt; vollständige Rollenprüfung und Praxisabnahme stehen aus.
- [x] Geräte-Token, Gerätezuweisung nach Event/Termin und Einlassbereich sowie Admin-Konfliktansicht „Einlass-Konflikte“ sind umgesetzt.
- [x] Doppelte Einlösungen aus unabhängig offline arbeitenden Scannern werden beim Sync erkannt, protokolliert und je Richtlinie (`review`/`first_sync_wins`) einer Admin-Klärung zugeführt (`OfflineScanner/Sync`, `Admission/Conflicts`).
- [ ] Kasse (`BoxOffice/Page`, `assets/boxoffice/pos.js`): Kassensitzungen, Schnellverkauf über das gemeinsame Kontingent, Zahlarten bar/Karte/Freikarte/Rechnung, lückenlose Belegnummern, Belegdruck, Ticketdruck als PDF, Storno und Kassenabschluss sind umgesetzt. Offen: fiskalische Belege (RKSV/TSE u. a.); die Kasse zeigt ausdrücklich den Hinweis, dass sie keine Registrierkasse ist. Fiskaladapter siehe Abschnitt „Fiskalisierung und Multi-Seller“.
- [x] Ticket-, Badge-, Namensschild- und Armbandvorlagen als PDF (`Printing/Templates`, `Printing/PdfWriter`, Modul `PrintTemplates`) sowie Teilnehmerlisten-Druck/CSV mit rollenabhängiger Datenminimierung (`Printing/AttendeeList`).
- [x] Eventkalender (Block/Shortcode), iCalendar-Download je Termin und Event-Feeds (`Calendar/Ics`, RFC 5545), `.ics`-Anhang in der Ticketmail, E-Mail-Erinnerungen (`Modules/Reminders`) und Benachrichtigungen bei Terminverschiebung/-absage.
- [ ] SMS: Twilio- und generischer HTTP-Adapter, protokollierte Opt-in-Einwilligung mit Widerruf, Zustellstatus-Callback (Twilio, signaturgeprüft) und Schutz gegen Kostentreiberei sind umgesetzt (`Modules/Sms`, `src/Sms/*`). Offen: Kostenerfassung/Budgetgrenzen je Nachricht, Nummernverifikation und Live-Abnahme mit echtem Anbieterkonto.
- [ ] Weitere Zahlungsanbieter nach Zielland, Währung, Gebühren und Händlerverfügbarkeit priorisieren und über die Payment-Adapter anbinden (vorhanden: manuelle Bestätigung, Stripe, PayPal, Mollie).
- [x] Webhook- und Entwickler-Hook-Verträge sind versioniert dokumentiert; aktuelle Abdeckung ist in `docs/WEBHOOKS.md` und `docs/HOOKS.md` aufgeführt.
- [ ] Einzelne Integrations-/Lasttests für diese Flows existieren; vollständige End-to-End-Abnahmesuite einschließlich Offlinekonflikten, Slot-Kollisionen und realem POS fehlt.

## P0 – Rollen, Eventscoping und Zugriffsschutz

- [x] Eigene Capabilities (`wpcap_manage_events`, `wpcap_manage_orders`, `wpcap_checkin`, `wpcap_view_reports`, `wpcap_refund`, `wpcap_approve_refunds`, `wpcap_view_finance`) und Rollenpakete sind implementiert (`Installer::register_roles`); WordPress-Administrator bleibt technische Vollzugriffsrolle.
- [x] Rollen für Ticket-Administration, Veranstaltungsleitung, Veranstalter/Partner, Vor-Ort-Kasse, Einlass, Finanzbuchhaltung und Support sind definiert.
- [ ] Nutzerzuweisungen je Event (`wpcap_event_staff`, `Access::can_access_event`), je Einlassbereich (Bereichspersonal) und je Partner (Partner-Nutzer) sind umgesetzt; operative Rollen erhalten ohne Zuweisung keinen Eventzugriff. Offen: Zuweisung auf Terminebene.
- [ ] Serverseitige Objektberechtigung in Admin, REST, Kundenkonto, PWA, Exporten und Integrationsdiensten durchsetzen.
- [x] Käuferzugriff über gehashte, nach 30 Tagen ablaufende Gastlinks, die per Neuausgabe (`Orders::rotate_access_link`) widerrufen werden; Portal, REST, Rechnungs-PDF und Übertragung prüfen Token und Ablauf.
- [ ] Datenminimierung teilweise umgesetzt: Teilnehmerliste je Rolle (voll/basis), Teilnehmernamen in Scanner und Offline-Paketen nur per Einstellung, maskierte E-Mail im Portal. Offen: Zahlungsdaten konsequent für Einlass/Partner ausblenden, Finanzrollen ohne unnötige Teilnehmerzusatzdaten, maskierte/fallbezogene PII für Support.
- [x] Erstattungen sind nach Capability, Event-Scope, kumulativer Betragsschwelle mit Zweitfreigabe durch eine andere Person und optionaler 2FA-Pflicht (`require_2fa_for_refunds`) abgesichert (`Refunds`).
- [ ] Scannerzugänge sind kurzlebig, eventbezogen und widerrufbar (Geräte-Pairing mit Ablauf, Event-/Bereichsbindung, Widerruf; `Mobile/Devices`). Offen: gemeinsame Benutzerkonten organisatorisch/technisch ausschließen.
- [ ] 2FA-Pflicht ist für Erstattungen optional einstellbar (Two-Factor-Plugin oder Filter `wpcap_user_has_2fa`). Offen: MFA für weitere administrative und finanzielle Aktionen sowie protokollierter, zeitlich begrenzter Supportzugriff.

## P0 – EU-Zielmarkt und Plattform-Kompatibilität

- [x] EU ist als Zielmarkt festgehalten; Währungs-/Locale-Grundlagen und Übersetzungen sind vorhanden.
- [ ] Länderbezogene Steuer-/Rechtsprofile fachlich prüfen und aktivieren.
- [x] CI prüft PHP 8.2/8.4, Syntax, Composer, Integrationstests und ZIP-Build; Integration läuft mit MariaDB.
- [x] Keine Yoast-Laufzeitabhängigkeit; optionale Kompatibilität kann separat geprüft werden.
- [ ] Kompatibilitäts- und Test-Baseline pro Release dokumentieren und aktuell halten.
- [ ] EU-Steuerfälle, OSS, Leistungsort, gemischte Steuersätze, Währungen und Erstattungsbelege mit fachlicher Steuerberatung für die unterstützten Länder spezifizieren.
- [ ] Zahlungsanbieter und Währungen sind konfigurierbar; verfügbare Zahlungsmethoden pro Land/PSP/Transaktionstyp sowie Live-Akzeptanz fehlen.
- [ ] Verbraucherinformationen und Storno-/Erstattungsbedingungen je unterstütztem Land rechtlich prüfen lassen.


## Fiskalisierung und Multi-Seller — nur teilweise in `main`

In `main` liegen bisher nur drei Dateien aus dem Fiskalisierungsumfang: der gemeinsame HTTP-Transport `src/Fiscalization/Providers/HttpClient.php` (Commit `f6e43f3`) sowie die Adapter `FiskaltrustAdapter` (`096209d`) und `EfstaEfrAdapter` (`253ee63`). Sie implementieren `PassCaptain\Fiscalization\AdapterInterface`, das in `main` **nicht** existiert, und werden von keiner Stelle aufgerufen; sie sind damit derzeit toter Code ohne Funktion. Der seller-aware Fiskalisierungskern aus [Draft-PR #14](https://github.com/hastla007/wp-passmaster/pull/14) – `AdapterInterface`, `Registry`, `CountryPolicy`, `Credentials`, `ProviderHttp`, `ReceiptRenderer`, `ArchiveExporter`, Modul `Fiscalization`, `BoxOffice/TerminalRegistry`, fiskaly-Adapter (SIGN AT, SIGN DE, Unified) sowie die Anbindung in Kasse, `Orders` und WooCommerce-`OrderAdapter` – ist nicht integriert. Multi-Seller in `main`: Verkaufsmodelle Eigenhändler/Vermittler je Veranstalter (`Partners/SalesModel`), der native Checkout lehnt Bestellungen mit verschiedenen Rechnungsstellern ab, Veranstalter-Abrechnungen (`Partners/Settlements`); eine WooCommerce-Korbaufteilung fehlt.

- [ ] PR #14 mit dem aktuellen `main` abgleichen (Konflikt mit den bereits gemergten Adaptern und `HttpClient` gegenüber `ProviderHttp` auflösen), nach bestandener CI integrieren – oder bis dahin die verwaisten Adapter aus `main` entfernen.
- [ ] Multi-Seller-Profile, WooCommerce-Korbaufteilung sowie Anbieterwahl fiskaly/efsta/fiskaltrust in `main` übernehmen und auf realen POS-/WooCommerce-Installationen abnehmen.
- [ ] Länder-/Anbieter-/Kanal-Konfiguration für AT, DE und HU mit echten Anbieter-Sandboxen testen und rechtlich/fachlich freigeben.
- [ ] Refunds, Teilrefunds, Eventabsagen und gebrauchte Tickets providerseitig ausführen; im Draft werden die meisten Fälle noch manuell abgeglichen. Der efsta-AT/DE-Einmal-Vollstorno-Pfad ist eng begrenzt und braucht Sandbox-Abnahme.
- [ ] Gesetzliche Länderanforderungen wie Signatur/TSE, Belegausgabe, vorgeschriebene Exporte und Hardwaretopologie fachlich prüfen. Adapter-Routing ist keine Zertifizierung oder Rechtsfreigabe.

## Kern-Plugin frei, Premium als getrenntes Add-on (Entscheidung 13.11.2026)

**Entscheidung:** Pass Captain bleibt als Kern-Plugin vollständig kostenlos, ohne Lizenzprüfung und ohne gesperrte Funktionen, und wird so für WordPress.org geeignet. Premiumfunktionen kommen in ein getrenntes Add-on-Plugin, das über den eigenen Shop verkauft und aktualisiert wird. Nur das Add-on spricht mit dem Lizenzserver WP License Captain.

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

- [ ] **B1 · Repository und Plugin-Grundgerüst.** Neues Repository (Arbeitstitel `pass-captain-pro`) mit eigenem Slug, eigener Textdomain und eigenem Namespace. Plugin-Header: `Requires Plugins: pass-captain`, `Update URI` auf den eigenen Shop (damit WordPress das Add-on nie mit einem WordPress.org-Plugin verwechselt), `Requires PHP: 8.2`. Beim Laden die Mindestversion des Kern-Plugins prüfen und sonst mit Admin-Hinweis sauber aussteigen.
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

- [x] Bestehende Events und Termine sind im Admin vollständig bearbeitbar (`EventsPage::update_event`, `EventsPage::save_occurrence`): Verschieben löst `wpcap_occurrence_rescheduled` mit Benachrichtigungen aus, die Kapazität darf nicht unter die Verkäufe sinken, jede Änderung wird mit Alt-/Neuwert im Audit-Log protokolliert.
- [ ] Admin-Oberflächen für Serientermine (`Series/AdminPage`), Einlassregeln/Bereiche (`Admission/RulesPage`), Saalpläne, Kasse, Druck, Partner und Offline-Geräte sind vorhanden. Offen: Service-Ressourcen/-Zeitfenster, Kampagnencodes, Wartelisten, Mitgliedschaften/Saisonpässe und Entwickler-API-Schlüssel (bisher nur per REST bzw. Code).
- [ ] Event-Warteliste: Eintrag mit Reihenfolge und Rate-Limits, manuell per REST ausgelöstes Angebot mit 24-Stunden-Token und Annahme, die eine Bestellung mit Hold anlegt (`Waitlists`). Offen: frei werdende Kapazität automatisch erkennen und Angebote auslösen, Kapazität beim Angebot atomar reservieren, Ablehnung/Abmeldung, Admin-Ansicht und Tests paralleler Annahmen.
- [ ] Automatische Preisregeln (Frühbucher, Last-Minute, Nachfrage, Wochentag/Zeitfenster) sind für Serientermine umgesetzt (`Series/Pricing`; höchste Priorität gewinnt, Preis-Snapshot in der Bestellposition). Offen: Preisregeln für Einzeltermine außerhalb von Serien, Käuferlimits und konfigurierbare Stapelbarkeit mit Kampagnencodes.
- [ ] Entwickler-API-Schlüssel: Ausgabe mit einmaliger Anzeige, Hash-Speicherung, Event-Bindung, Scopes `events.read`/`reports.read`, Ablauf, Widerruf, festes Ratenlimit (600/min) und Audit sind per REST umgesetzt (`DeveloperKeys`). Offen: Admin-Oberfläche, konfigurierbare Ratenlimits und weitere Read-only-Endpunkte.
- [ ] Multisite: Netzwerkaktivierung, Tabellen für neue Sites (`wp_initialize_site`) und Tabellenlöschung beim Löschen einer Site (`wpmu_drop_tables`) sind umgesetzt (`src/Plugin.php`). Offen: Netzwerkverwaltung, netzwerkweite Berichte und Cross-Site-Tests für Isolation, Aktivierung, Löschung und Migration.

### Hintergrunddienste, Übersetzungen und Integrationen

- [ ] Job-Queue mit Idempotenz, Retry-Backoff und Dead-Letter (`Jobs`) hat Handler u. a. für Wallet-Synchronisierung und Voucher-Commit; Erinnerungen laufen über die Nachrichtenqueue. Offen: Admin-Ansicht für Rückstände, Fehlversuche und Dead-Letter-Jobs mit kontrolliertem Wiederholen.
- [ ] Betriebsüberwachung für WP-Cron/Action Scheduler, Mail-, SMS-, Webhook- und Job-Rückstände einrichten; ausbleibende Ausführung oder dauerhaft fehlerhafte Jobs müssen erkennbar sein.
- [ ] Übersetzungen (en_US, es_ES, fr_FR, it_IT, nl_NL), E-Mails im bei der Bestellung erkannten Locale und übersetzte Eventinhalte sind vorhanden. Offen: ausdrückliche Käufer-Sprachauswahl, Übersetzungsverwaltung im Admin und automatische Vollständigkeitsprüfung der Strings für alle Sprachen.
- [ ] Versionierte Connector-Verträge durch konkret unterstützte CRM-, Kalender- oder Buchhaltungsadapter, Mapping-Oberflächen und getestete Live-Synchronisierung ergänzen.
- [ ] Native Scanner-Apps selbst entwickeln oder explizit als separates Produkt/Projekt ausweisen; die Mobile-REST-API allein ist keine Scanner-App.

### Abnahme, Barrierefreiheit und Releasebetrieb

- [ ] Veralteten Fehlerbericht in docs/TESTING.md aktualisieren: Der Bericht nennt noch den behobenen Check-in-Deadlock und continue-on-error, obwohl der aktuelle CI-Workflow den Lasttest verpflichtend ausführt.
- [ ] Prozess für Sicherheitsmeldungen, Incident-Behandlung, Abhängigkeitsupdates und regelmäßige WordPress-/WooCommerce-Kompatibilitätsprüfungen festlegen.
- [ ] Release- und Supportmodell für stabile Versionen festlegen: unterstützte PHP/WP/WooCommerce-Matrix, Rückwärtskompatibilität, Supportkanal, Patchfristen und Ende des Supports.
- [ ] Plugin-Metadaten und Funktionsdokumentation vor jedem Release mit dem Code abgleichen. Aktuell widersprechen sich README und Backlog z. B. beim Stand der Voucher-Checkout-Integration und der Zahlungsanbieter.

### Multi-Seller-Geschäftsabläufe

- [ ] Gemischte Verkäufer in einer Bestellung lehnt der native Checkout ab (`Partners/SalesModel`). Offen: gemischte WooCommerce-Warenkörbe aufteilen oder ablehnen und verbindliche Regeln für Rabatte, Gebühren, Versand und Rundungsdifferenzen je Verkäufer mit reproduzierbaren Tests festlegen.
- [ ] Erstattungen werden in der Veranstalter-Abrechnung anteilig den Positionen zugeordnet (`Partners/Settlements`). Offen: Teilrefunds, Betrag-only-Erstattungen, gebrauchte Tickets und Veranstaltungsausfälle mit Zahlungs- und Fiskalanbieterstatus abstimmen.
- [x] Verkaufsmodelle Eigenhändler/Vermittler je Veranstalter mit Rechnungsaussteller, monatlicher Abrechnung (Gutschrift bzw. Provisionsrechnung mit Verrechnung) und Auszahlung außerhalb des Plugins sind umgesetzt und in `docs/SALES-MODELS.md` dokumentiert; die rechtliche Prüfung je Land bleibt eine separate Freigabe.

### Lizenzserver und Premium-Updates

Diese Aufgaben gehören seit der Entscheidung vom 13.11.2026 ins Premium-Add-on, nicht mehr in dieses Plugin. Sie sind oben im Abschnitt „Kern-Plugin frei, Premium als getrenntes Add-on“ unter B5 (Rechte), B6 (Ablauf und Ausfall), B7 (Updater) und B10 (Tests) aufgegangen.

## Technische Abnahmekriterien für eine nahezu vollständige 1.0

Diese Punkte zielen auf technische Robustheit und reproduzierbare Releasequalität. Sie ergänzen die Feature-Arbeiten oben; offene Rechts-/Steuerfreigaben und Anbieter-Zertifizierungen bleiben separate Freigaben.

### CI, statische Qualität und Artefakt

- [ ] PHP_CodeSniffer mit WordPress Coding Standards, statische PHP-Analyse und Composer-Sicherheitsprüfung in CI einführen; Fehler müssen den Build blockieren.
- [ ] JavaScript-Syntax-/Lint-Prüfung auf alle eigenen JS-Dateien erweitern (einschließlich WooCommerce-Blocks, Admin, Offline-Scanner und Worker); zusätzlich relevante JS-Logik mit automatisierten Tests abdecken.
- [ ] PHP-Integrationssuite über die unterstützten PHP-Versionen laufen lassen; derzeit ist die Syntaxmatrix breiter als die Datenbank-Integration.
- [ ] Reale CI-Matrix für WordPress (Mindest- und aktuelle Version) sowie festgelegte MySQL- und MariaDB-Minimum-/aktuelle Versionen ergänzen; dokumentieren, welche Kombinationen tatsächlich freigegeben sind. Derzeit laufen die Integrationstests nur mit PHP 8.4, MariaDB 11.4 und einer minimalen WordPress-Laufzeitnachbildung (`tests/integration/bootstrap.php`); die PHP-Matrix 8.2/8.4 gilt nur für Syntax und Composer.
- [ ] WooCommerce-Tests als echte Installation für Mindest- und aktuelle Version sowie HPOS an/aus ausführen; Checkout Classic und Blocks in der Matrix behalten. Bisher prüft die Suite WooCommerce- und HPOS-Verhalten gegen nachgebildete WooCommerce-APIs.
- [ ] Erzeugtes Release-ZIP in CI entpacken und in einer frischen WordPress-Installation installieren/aktivieren; enthaltene Dateien, Autoloader, Assets und Abwesenheit von Tests/Entwicklungsdateien prüfen.
- [ ] Reproduzierbarkeit des ZIP-Builds technisch prüfen und den Abgleich der Artefakte dokumentieren: derselbe Commit und dieselben Abhängigkeiten müssen denselben Inhalt/Prüfsummen ergeben; Release-Commit und veröffentlichtes Paket abgleichen.
- [ ] Versions-, Changelog-, Plugin-Header-, Composer- und Stable-tag-Konsistenz vor Release automatisch prüfen.

### Datenbank, Zustandsautomaten und Fehlerpfade

- [ ] Upgrade-Integrationstests für jede unterstützte Vorgängerversion mit realistischen Daten durchführen; Migration mehrfach, parallel und nach simuliertem Prozessabbruch sicher wiederholen.
- [ ] Tabellenpräfixe, Fremdbezüge und Datenisolation automatisiert für Einzelinstallation, HPOS und Multisite prüfen; keine direkte WooCommerce-Tabellenabfrage zulassen.
- [ ] Kritische Abläufe bei DB-Deadlock, Lock-Timeout, Verbindungsabbruch und fehlgeschlagenem Commit systematisch fault-injection-testen; keine Erfolgsantwort darf ohne persistierten Zustand ausgegeben werden.
- [ ] Bestell-, Zahlungs-, Ticket-, Refund- und Check-in-Zustandsautomaten mit erlaubten/unerlaubten Übergängen dokumentieren und gegen doppelte, verspätete und out-of-order Events testen.
- [ ] REST-Endpunkte negativ testen: Gast, falsche Rolle, falscher Eventscope, widerrufener/abgelaufener Schlüssel, wiederholter Request und manipulierter Eingabekörper dürfen keine unberechtigte Änderung bewirken.
- [ ] Rate-Limits hinter Proxy/CDN und bei mehreren PHP-Workern validieren; dokumentieren, dass Plugin-Transients allein kein verteilter Schutz sind, und eine verlässliche Hosting-Option empfehlen.

### Last, Hintergrundjobs und Wiederanlauf

- [ ] Default-/Heavy-Checkout-, Check-in- und Webhook-Lastprofile sowie REST-Lasttests als geplante CI-/Releaseprüfung regelmäßig auf MySQL und MariaDB ausführen und Referenzwerte für Latenz, Fehlerquote und Deadlocks sowie Regressionen dokumentieren.
- [ ] Job-/Mail-/Webhook-Worker durch Fehler- und Neustartszenarien prüfen: abgelaufene Claims, Retry-Backoff, Dead-Letter, doppelte Ausführung und Wiederaufnahme müssen idempotent bleiben.
- [ ] System-Cron-Ausfall, verzögerte Queue, E-Mail-Transportfehler und zu lange Jobs sichtbar machen; Admin muss Rückstand, letzten erfolgreichen Lauf und nächste Aktion erkennen können.
- [ ] Restore-/Upgrade- und Multisite-Notfallübung mit realistischen Ticket-, Bestell-, Check-in-, Refund- und Jobdaten durchführen; nach Restore Duplikate oder verwaiste Reservierungen ausschließen und Zahlungsanbieter sowie externe Integrationen auf konsistente Transaktionsstände abgleichen.

### Sicherheit, Datenschutz und UI-Abnahme

- [ ] Stripe-/PayPal- und andere Anbieter-Geheimnisse aus der Klartextspeicherung in der allgemeinen WordPress-Option `wpcap_settings` entfernen; verschlüsselten Credential-Vault oder externen Secret Store mit Schlüssel außerhalb der Datenbank festlegen. Bestehende Werte sicher migrieren und Rotation, Widerruf sowie Ausfall/Wiederherstellung fail-closed behandeln. *(Heute schon möglich: Konstanten/Umgebungsvariablen `WPCAP_*` über `Settings::secret()`; Rückfall bleibt der Klartext in `wpcap_settings`.)*
- [ ] Automatisiert nachweisen, dass Provider-Secrets nach Speichern/Migration nicht im Klartext in Optionen, REST-Antworten, Admin-HTML, Audit-Logs, Fehlern oder Paketdateien auftauchen; Schlüsselverlust und erneute Eingabe testen.
- [ ] Automatisierte Permission-Matrix für alle Admin-/REST-/Partner-/Käuferaktionen etablieren und sicherstellen, dass jede Route explizit geschützt oder bewusst öffentlich ist.
- [ ] Secret-Redaction und Eingabevalidierung unter Fehlerbedingungen testen; API-Schlüssel, Ticketcodes, PII und Providerantworten dürfen nicht in Logs, REST-Fehlern oder CI-Artefakten erscheinen.
- [ ] Dependency-/Secret-Scanning und Prüfung des erzeugten Pakets auf bekannte Schwachstellen bzw. eingebettete Geheimnisse als CI-Gates ergänzen.
- [ ] Datenschutzexport, Löschung und Retention automatisiert mit großen Datenmengen, Multisite, kommenden Events, gültigen Tickets, Finanzbelegen und WooCommerce-Daten testen.
- [ ] Browser-End-to-End-Matrix für aktuelle Chrome/Firefox/Safari sowie iOS/Android durchführen: Checkout, Kameraerlaubnis/Abbruch, QR-Fallback, Scanner, Bestellportal und WooCommerce Blocks.
- [ ] A11y-Prüfungen als Release-Gate in den Releaseprozess integrieren und bekannte Admin-Befunde beheben (Labels an Filter-Selects, Kontrast von Code-/Linktexten, ausreichend große Checkbox-Ziele); danach Tastatur, 200%-Zoom, Screenreader, Kontrast und Fehlermeldungen manuell abnehmen.
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

- [x] [P1][BESTÄTIGT] `.github/workflows/release.yml:30–45,76–99` — Ein gepushter `v*`-Tag löst den Release-Job ohne Prüfung eines erfolgreichen CI-Laufs für denselben SHA und ohne Prüfung der Abstammung von `main` aus; der Workflow checkt den Tag-Commit aus und veröffentlicht daraus ein ZIP. **Mögliche Auswirkung:** ungeprüfter oder fremder Stand kann als offizielles Release veröffentlicht werden. **Maßnahme:** Tag-Releases nur nach erfolgreichen Pflichtchecks für exakt denselben SHA und validierter Release-/Main-Abstammung zulassen; negative Workflow-Tests für fehlende/fehlgeschlagene Checks ergänzen. **Behoben (2026-11-14):** `build/verify-release.sh` prüft Tag/Version, Abstammung von `main` und grüne CI für exakt denselben SHA; Negativtests in `tests/release/verify-release.test.sh`.
- [x] [P1][BESTÄTIGT] `src/Installer.php:658–670` — Rückgaben/Fehler von `dbDelta()` werden nicht geprüft; Datenbankversion und Schema-Fingerprint werden anschließend trotzdem fortgeschrieben. **Mögliche Auswirkung:** Nach einem partiellen Schemafehler gilt das Upgrade als abgeschlossen und automatische Wiederholungen können ausbleiben; spätere Funktionen treffen auf fehlende Tabellen/Spalten. **Maßnahme:** Schemaänderungen und resultierende Tabellen/Spalten verifizieren, Version/Fingerprint erst nach vollständigem Erfolg speichern, Fehler protokollieren und Wiederholung ermöglichen; Fehler-Injection-/Retry-Integrationstest ergänzen. **Behoben (2026-11-14):** Tabellen/Spalten werden nach `dbDelta()` verifiziert; Version/Fingerprint nur bei Erfolg, sonst `wpcap_schema_error` + Admin-Hinweis und Wiederholung; Fault-Injection-Test.
- [x] [P1][BESTÄTIGT] `src/Payments/Webhooks.php:46–49` — `INSERT IGNORE` unterscheidet nicht zwischen `0` (bereits beanspruchtes Ereignis) und `false` (Datenbankfehler); beide Fälle werden als `duplicate` zurückgegeben. **Mögliche Auswirkung:** Ein Zahlungsanbieter erhält bei temporärem Datenbankfehler eine Erfolgs-/Duplikatantwort, obwohl Zahlung und Ticketverarbeitung nicht abgeschlossen wurden, und kann den Webhook-Retry einstellen. **Maßnahme:** Duplicate und SQL-Fehler getrennt behandeln; bei DB-Fehler retrybaren Fehler/HTTP 5xx liefern; Duplicate-, Fehler- und Retrypfade testen. **Behoben (2026-11-14):** DB-Fehler beim Claim liefert retrybaren Fehler (HTTP 500), nur `0` gilt als Duplikat; Tests in `zz_order_delivery_faults.php`.
- [x] [P1][BESTÄTIGT] `src/Orders.php:1083–1124` und `329–344` — Ticket-Insert, Teilnehmerzuweisungen/-antworten, Update der Bestell-Teilnehmerdaten sowie Transaktionsstart/Commit werden nicht durchgängig auf Fehler geprüft; Teilnehmerdaten auf Auftragsebene werden gelöscht, sobald mindestens ein Ticket ausgegeben wurde. **Mögliche Auswirkung:** bezahlte Bestellung kann unvollständige Tickets/Teilnehmerdaten hinterlassen oder die einzige noch vorhandene Teilnehmerdatenkopie verlieren, während der Zahlungsablauf weiterläuft. **Maßnahme:** kritische DB-Schreibvorgänge und Transaktionsresultate prüfen; bei Fehler vollständig zurückrollen und retrybaren Fehler melden; Teilnehmerdaten erst nach nachgewiesener Persistierung aller Zuordnungen löschen; Fehler-Injection-Test ergänzen. **Behoben (2026-11-14):** Alle Schreibvorgänge der Ticketausgabe geprüft, vollständiger Rollback, Retry-Job `ticket_issue`; Teilnehmerkopie wird erst nach vollständiger Persistierung gelöscht; Webhook antwortet 5xx bei fehlenden Tickets.
- [x] [P2][BESTÄTIGT] `src/Orders.php:1125–1158` und `src/Mail/Mailer.php:132–150` — Der Rückgabewert von `Mailer::deliver()` wird im Ticket-Ausgabeablauf ignoriert; Ticketcodes werden nur beim Erzeugen an die E-Mail übergeben und in der Datenbank gehasht gespeichert. **Mögliche Auswirkung:** Bei Mailfehler oder Abbruch nach Datenbank-Commit bleibt die bezahlte Bestellung mit gültigen Tickets bestehen, aber es gibt keinen automatischen Versand-Retry; die manuelle Nachsendung benötigt einen Eingriff und rotiert die Codes. **Maßnahme:** Ticketzustellung als dauerhaften Outbox-/Retry-Status speichern, Versand unabhängig von neu erzeugten Tickets wiederholbar machen und Fehler im Admin sichtbar anzeigen; Mailfehler- und Crash-nach-Commit-Tests ergänzen. **Behoben (2026-11-14):** Zustell-Outbox über die Jobtabelle (`ticket_delivery`) mit automatischem Retry/Backoff/Dead-Letter; Status in der Bestellansicht sichtbar.
- [x] [P2][BESTÄTIGT] `src/Refunds.php:142–159,174–196` — Mehrere Datenbankoperationen beim Setzen auf `processing` und beim Verbuchen eines erfolgreichen Provider-Refunds werden nicht auf Fehler geprüft; die externe Rückzahlung kann vor lokaler Finalisierung erfolgt sein. **Mögliche Auswirkung:** Zahlungsanbieter und Plugin können unterschiedliche Refund-/Ticket-/Bestellstände aufweisen; ein Refund kann in `processing` hängen oder als lokal unvollständig erscheinen. **Maßnahme:** persistente Refund-Zustandsmaschine mit Fehlerprüfung und Reconciliation/Retry nach externem Provider-Erfolg einführen; Fehler zwischen Provideraufruf und jedem lokalen Schreibschritt simulieren und idempotente Reparatur testen. **Behoben (2026-11-14):** Claim als bedingtes Einzeilen-Update; lokale Verbuchung in einer geprüften Transaktion; vom Anbieter ausgezahlte, nicht verbuchte Refunds werden idempotent per `Refunds::reconcile()` nachgezogen (Cron + Erstattungsseite).

### Verdachtsfälle

- Im geprüften Umfang wurde kein zusätzlicher Verdachtsfall aufgenommen. Vermutungen ohne belastbaren Codebeleg wurden nicht als Befund gewertet.


## Folgereview – zusätzliche bestätigte Befunde (Plugin-Code @ 14522c7)

Diese Befunde kamen aus der erneuten Prüfung mit fünf Perspektiven. Der neueste Commit `b022c2b` vor diesem Eintrag änderte nur diese TODO-Datei, nicht den Plugin-Code.

### Bestätigte Befunde

- [x] [P2][BESTÄTIGT] `src/Jobs.php:35–46`; `src/Communications.php:45–58` — Queue-Worker prüfen Transaktionsstart, bedingtes Claim-Update, betroffene Zeilen und Commit nicht, bevor der Job-Handler bzw. Mailversand ausgeführt wird. **Mögliche Auswirkung:** Bei DB-/Claim-Fehlern kann ein Eintrag in der Queue bleiben und parallel oder später erneut verarbeitet werden; Mails oder externe Job-Nebeneffekte können doppelt ausgelöst werden. **Maßnahme:** Verarbeitung nur bei erfolgreichem Claim mit genau einer aktualisierten Zeile und bestätigtem Commit starten; sonst rollbacken und retrybar abbrechen; Fehler-Injection- und Parallel-Worker-Tests ergänzen. **Behoben (2026-11-14):** Handler/Mailversand erst nach Einzeilen-Claim und erfolgreichem Commit; Fault-Injection- und Parallel-Worker-Test (6 Prozesse).
- [x] [P2][BESTÄTIGT] `src/Orders.php:1212–1245` — Sammel-Nachsendung rotiert Ticketcodes einzeln und versendet erst nach Abschluss aller Rotationen; Updates sind nicht serialisiert und ein Update mit null betroffenen Zeilen gilt nicht als Fehler. **Mögliche Auswirkung:** Bei Teilausfall können bereits alte Codes ungültig sein, ohne dass die Nachricht versendet wird; parallele Nachsendungen können Links mit inzwischen ersetzten Codes zustellen. **Maßnahme:** Rotation und Zustellung als serialisierten, wiederaufnehmbaren Vorgang mit Status/Outbox implementieren; Update-/Rowcountfehler erkennen und Teilfehler reparierbar machen; parallele Nachsendungen und Fehler nach jeder Rotation testen. **Behoben (2026-11-14):** Pro Empfänger eine Transaktion mit Zeilensperre und Hash-Bedingung; Commit erst nach erfolgreichem Versand, sonst Rollback.
- [x] [P2][BESTÄTIGT] `src/Orders.php:739–760`; Aufruf `src/Payments/Webhooks.php:128–138` — `cancel_order()` ignoriert DB-Fehler bei Transaktion, Reservierungsfreigabe, Ticketwiderruf, Bestellstatus und Commit und meldet dennoch Erfolg; der Webhook kann als verarbeitet markiert werden. **Mögliche Auswirkung:** Bestellung, Reservierungen und Tickets können auseinanderlaufen, während der PSP einen 2xx erhält und den fehlgeschlagenen Vorgang nicht erneut sendet. **Maßnahme:** jede kritische Mutation und Commit prüfen, bei Fehler rollbacken und retrybaren Fehler zurückgeben, damit die Webhook-Zustellung wiederholt wird; Fehler-Injection-Test für den ganzen Stornopfad ergänzen. **Behoben (2026-11-14):** Jeder Schritt geprüft, Rollback und retrybarer Fehler (503); Webhook gibt Claim frei und antwortet 5xx.
- [x] [P2][BESTÄTIGT] `src/Orders.php:784–799`; Aufruf `src/Payments/Webhooks.php:140–149` — `mark_refunded()` prüft Freigabe, Ticketwiderruf und Bestellupdate nicht auf Fehler, bevor der externe Vollrefund-Webhook als verarbeitet gilt. **Mögliche Auswirkung:** Provider-Refund und lokaler Ticket-/Bestellstatus können auseinanderlaufen; der Webhook-Retry wird durch die Erfolgsantwort unterdrückt. **Maßnahme:** DB-Ergebnisse prüfen und Fehler retrybar weitergeben; externen Vollrefund-Zustand bei wiederholten Events idempotent abgleichen; Fehlerfälle testen. **Behoben (2026-11-14):** Eigene Transaktion mit `FOR UPDATE`, alle Schritte geprüft, wiederholte Events idempotent.
- [x] [P2][BESTÄTIGT] `assets/offline-scanner/index.html:2,16–75`; `assets/offline-scanner/app.js:96–120,169–172,378–420,530–659` — Offline-Scanner-Texte, Statusmeldungen und Datumsformat sind auf Deutsch fest codiert (`lang="de"`), obwohl Übersetzungskataloge Sprachfassungen für mindestens Teile derselben Texte enthalten; die PWA lädt diese Kataloge nicht. **Mögliche Auswirkung:** Einlasskräfte anderer Sprachumgebungen erhalten deutsche Bedien-/Fehlermeldungen; Screenreader sprechen die Inhalte mit falscher Sprache aus. **Maßnahme:** Locale-abhängige PWA-Übersetzungsressource für UI, Status, Fehler und Datum implementieren, `html lang` korrekt setzen und automatisierte Sprach-/Screenreader-Checks ergänzen. **Behoben (2026-11-14):** Übersetzungstabelle über `GET /app/offline/i18n` (offline in IndexedDB), `t()`-Lookup, `html lang` und `Intl.DateTimeFormat`; Übersetzungen für 5 Sprachen.

### Verdachtsfälle

- In dieser Folgerunde wurde kein zusätzlicher Verdachtsfall aufgenommen.

## Restpunkte aus der Behebung der Auditbefunde (2026-11-14)

- [ ] Zahlt der Anbieter aus und schlägt selbst das Speichern der Provider-Referenz fehl, bleibt der Refund in `processing` ohne Referenz und braucht manuelle Prüfung; Admin-Ansicht/Reconciliation dafür ergänzen.
- [ ] Bei dauerhaftem Schemafehler wiederholt jede Admin-/Cron-Anfrage `dbDelta()` ohne Backoff; Backoff ergänzen.
- [ ] `Refunds.php` und der WooCommerce-Adapter ignorieren den neuen Fehler-Rückgabewert von `Orders::mark_refunded()`; `Refunds::record_external()` und die abschließenden Statusupdates in Jobs/Communications sind noch ungeprüft (Fallback: 15-Minuten-Stale-Lock).
- [ ] Seltener Fall: Schlägt das Abschließen des Outbox-Jobs nach erfolgreicher Mail fehl, versendet der Retry eine zweite Mail mit neuen Codes.
- [ ] Offline-Scanner: `manifest.json` bleibt deutsch (statische Datei); PWA nutzt die Site-Sprache statt der Gerätesprache; `docs/OFFLINE-SCANNER.md` um den i18n-Endpunkt ergänzen.
