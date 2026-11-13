# Änderungsprotokoll

## Unveröffentlicht

### Auditbefunde Runde 3 (Arbeitsliste A001–)
- Umtausch im Bestellportal: übertragene Tickets kann nur noch der Veranstalter umtauschen (kein Umtausch samt Erstattung durch den ursprünglichen Käufer); das Umtausch-Formular funktioniert für alle Tickets einer Bestellung.
- Verspätete Zahlung: vorübergehende Datenbank-Sperren gelten nicht mehr als „ausverkauft“ – der Webhook wird erneut zugestellt. Bestellungen „Prüfung nötig“ haben den Knopf „Zuteilung erneut versuchen“.
- Pässe mit „bei Absage widerrufen“: Käufer:innen erhalten die Absage-Mail; ohne automatische Erstattung wird die Erstattung als offen gemeldet und bleibt in der Bestellung möglich.
- Umtausch und Buchhaltung (Schema 3.8.0): bezahlte Bestellpositionen bleiben für Rechnung, Berichte, Abrechnungen, Z-Bericht und Affiliate-Provision unverändert (`orig_*`-Spalten); keine Doppelzählung umgezogener Einheiten, Zusatzbestellungen ohne zweite Ticketgebühr. Der Ticketwert ändert sich nicht durch andere Umtausche derselben Ticketart und berücksichtigt Mehrzweckgutscheine. Umtausch zwischen unterschiedlichen Steuersätzen ist gesperrt.
- Überweisung: Die Reservierung endet spätestens mit Beginn des Termins (Checkout, Warteliste, Umtausch-Aufpreis). Neue Einstellung „Überweisung nur bis … Stunden vor Beginn anbieten“ (`manual_cutoff_hours`, Standard 0); innerhalb dieser Frist wird die Überweisung auf der Eventkarte ausgeblendet und im Checkout abgelehnt.

### Zusammenspiel mit WP Voucher Captain
- Gemeinsamer Scanner: neuer Dienst `wp_pass_captain_checkin_service_v1` (`Integrations\Vouchers\CheckinService`) – `recognizes()`, `preview()`, `check_in()` mit denselben Regeln, Sperren und Audit wie der eigene Web-Scanner; Recht `wpcap_checkin` wird im Dienst geprüft. Der Voucher-Captain-Scanner erkennt damit auch Pass-Captain-Tickets; Gutschein-Einlösung und Ticket-Check-in bleiben getrennte Aktionen.
- Einstellungen zeigen den Status der Integration (Voucher Captain aktiv/Version, Gutscheine im Checkout, Gutschein → Ticket, gemeinsamer Scanner) mit Links; im Menü stehen „Gemeinsamer Scanner ↗“ und „WP Voucher Captain ↗“, wenn Voucher Captain aktiv ist.
- Werden Tickets einer Gutschein-Umwandlung in Pass Captain storniert oder erstattet (Bestellung storniert/erstattet, Termin oder Event abgesagt), meldet Pass Captain das über die neue Action `wp_pass_captain_voucher_tickets_revoked_v1` (Einlöse-Schlüssel, verbleibende Tickets). WP Voucher Captain stellt den Gutschein damit wieder her. Ein Widerruf durch das Gutscheinsystem selbst wird nicht zurückgemeldet.

### Gutscheine im Checkout
- Erstattung, während die Gutschein-Belastung noch wiederholt wird: Der Gutschein-Anteil wird vorgemerkt und direkt nach der erfolgreichen Belastung erstattet. Ist die Bestellung bis dahin vollständig erstattet oder storniert, wird der Gutschein freigegeben statt belastet.
- Hat Voucher Captain eine Reservierung bereits freigegeben (z. B. abgelaufen während einer Überweisung), wird der Gutschein mit dem gespeicherten Angebot neu reserviert und belastet, statt die Bestellung als „nicht belastbar“ zu melden. Die Betragsprüfung bei der Neureservierung nutzt `applied_minor`.
- Gutschein anwenden ist atomar: Bestellung und Positionssteuern werden in einer Transaktion und nur bei unveränderter, offener Bestellung geschrieben; andernfalls wird die Reservierung freigegeben. Ein Wiederholungsversuch mit demselben Schlüssel wendet einen noch nicht gespeicherten Gutschein nach.
- Checkout: Nach einer Ablehnung (Gutschein, Zahlungsstart) beginnt das erneute Absenden einen neuen Bestellversuch; ein abgebrochener Versuch wird nicht mehr als „Bestellung angelegt“ gemeldet (409 `wpcap_checkout_closed`).

### Einlass
- Standard-Einlassfenster für Ticketarten ohne eigene Einlassregel: Einlass ab 2 Stunden vor Beginn bis zum Ende des Termins (ohne Ende: bis Ende des Veranstaltungstags). Einstellbar unter „Einlass / Scanner-Geräte“ (`default_entry_window`, `entry_opens_before_minutes`, `entry_closes_after_minutes`); Ticketarten mit eigener Einlassregel bleiben unverändert.
- Web-Scanner offline: Codes werden nicht mehr ungeprüft vorgemerkt. Angezeigt wird „NICHT GEPRÜFT“; erst nach der ausdrücklichen Entscheidung „Nach Sichtprüfung einlassen“ wird der Scan für die spätere Prüfung gespeichert. Ungültige Codes werden sofort abgelehnt.

### Datenschutz
- Löschanfragen für Bestellungen mit gültigen Tickets für kommende Termine werden zurückgestellt (Art. 17 Abs. 3 DSGVO, Vertragserfüllung): Die Tickets bleiben gültig, der Admin wird per E-Mail informiert, und nach dem letzten Termin wird die Bestellung automatisch anonymisiert (stündlicher Lauf, Option `wpcap_deferred_erasures`, Filter `wpcap_privacy_defer_upcoming`). Bisher wurden solche Tickets sofort entwertet.

### WP Affiliate Captain
- Ohne Marketing-Einwilligung bleibt der Partnerlink der laufenden Shop-Sitzung erhalten (er setzt kein Marketing-Cookie); nur der Empfehlungs-Cookie braucht die Einwilligung „marketing“. Bisher ging bei abgelehntem Marketing auch die cookie-freie Zuordnung verloren. Der Filter `wpcap_affiliate_tracking_allowed` erhält als zweites Argument die Methode (`cookie`, `link`, `app`).
- Provisionsbasis bei Mehrzweckgutscheinen (Zahlungsmittel): Abgezogen wird nur noch der USt-Anteil des tatsächlich bezahlten Betrags. Bisher wurde die volle USt der Position vom reduzierten Zahlbetrag abgezogen, die Basis war dadurch zu niedrig (Beispiel 119 € Ticket, 15 € Gutschein: 85,00 € statt 87,39 €).

## 1.4.0 – 2026-11-12

### Verkaufsmodelle: Eigenhändler oder Vermittler
- Verkaufsmodell je Veranstalter: „Eigenhändler“ (Standard, Verkauf im eigenen Namen) oder „Vermittler“ (Verkauf im Namen und für Rechnung des Veranstalters) mit Rechnungsangaben (rechtlicher Name, Land, Steuernummer, USt-IdNr.-Prüfdatum, Rechnungspräfix, Kleinunternehmer-Hinweis).
- Vermittler: Kundenrechnungen und Rechnungskorrekturen im Namen des Veranstalters mit eigenem lückenlosem Nummernkreis je Veranstalter und Hinweis „Vermittelt durch …“.
- Bestellungen mit Tickets verschiedener Rechnungssteller werden im Checkout abgelehnt; bestehende gemischte Bestellungen erhalten eine klare Fehlermeldung statt einer fehlerhaften Rechnung.
- Veranstalter-Abrechnung wird je nach Modell zur „Provisionsrechnung“ (inkl. Reverse Charge für Veranstalter im EU-Ausland) oder zu „Gutschrift“ (eigener Nummernkreis GU) plus Rechnung über Plattformgebühren mit Verrechnung.
- Plattform-Einstellungen: Steuernummer und Land des Rechnungsstellers; Buchhaltungsexport mit Spalten Verkaufsmodell und Rechnungssteller. Siehe docs/SALES-MODELS.md.

### Rechnung auf Firma/Adresse
- Checkout, Kasse und Bestellportal: optionale Rechnungsangaben (Firma, Name, Anschrift, Land, UID-Nummer, eigene Referenz) mit Prüfung (UID-Format je EU-Land, PLZ-Format für AT/DE/CH/LI/NL/PL) und barrierefreien Fehlermeldungen; Korrektur im Bestellportal bis zur Rechnungsausstellung.
- Name und Anschrift sind ab einem Bestellbetrag über 250 € Pflicht (Einstellung, 0 = aus; deckt DE § 33 UStDV und AT § 11 UStG ab).
- Rechnungen und Rechnungskorrekturen (HTML und PDF) enthalten die Empfängerangaben; WooCommerce-Rechnungsdaten (inkl. UID aus gängigen Meta-Feldern) werden übernommen; Bestelldetails im Backend zeigen sie.
- Rechnungsdaten sind im Datenschutz-Export enthalten und werden bei Löschanfragen entfernt; ausgestellte Rechnungen behalten ihre Empfängerangaben. Datenbankschema 3.5.0.

### Gutscheine: Einzweck und Mehrzweck
- Unterscheidung Einzweck-/Mehrzweckgutschein (Art. 30a/30b MwStSystRL, § 3 Abs. 13–15 UStG, § 4 Abs. 9 öUStG); die Art kommt aus WP Voucher Captain, dem Filter `wpcap_voucher_purpose` oder der Einstellung „Gutscheine standardmäßig als Mehrzweckgutschein behandeln“ (Standard: Mehrzweck).
- Mehrzweckgutscheine sind ein Zahlungsmittel: Ticketpreis und Umsatzsteuer bleiben voll, nur der Zahlbetrag sinkt (bisher wurde die USt zu niedrig ausgewiesen). Einzweckgutscheine bleiben wie bisher.
- Rechnungen mit Abschnitt „Zahlungen“ (Gutschein mit maskiertem Code, bezahlter Restbetrag); Bestelldetails und Bestellportal zeigen Gesamtbetrag, Gutscheinanteil und Zahlbetrag.
- Erstattungen werden anteilig nach Ticketwert auf Zahlungsanbieter und Gutschein aufgeteilt; Rechnungskorrekturen umfassen beide Teile mit voller USt.
- Ticketdienst (Gutschein → Tickets): Mehrzweckgutscheine stellen Tickets zum vollen Preis mit USt aus; bei Einzweckgutscheinen wird der Gutscheinwert für Abrechnungen gespeichert. Siehe docs/VOUCHERS.md.

## 1.3.0 – 2026-11-12

Korrekturen und Lücken aus einer Experten-Prüfung (Sicherheit, Ticketing-Betrieb, UX, Steuer, Datenschutz, Architektur).

### Sicherheit und Erstattungen
- Bereits übertragene Tickets können über den Bestelllink des Käufers nicht mehr neu zugewiesen werden; ein Personenwechsel per Zuweisung ist pro Ticket nur einmal möglich (Filter `wpcap_max_holder_changes`), und danach ist keine weitere Übertragung mehr möglich.
- Erstattungen sperren die Bestellung; Tickets mit wartender oder laufender Erstattung können nicht erneut erstattet werden; vor jeder Auszahlung wird erneut geprüft (sonst Fehlschlag ohne Zahlungsanbieter-Aufruf); die Freigabegrenze gilt für die Summe aller Erstattungen einer Bestellung.
- PDF-Rechnungsdownload erstellt neue Rechnungsnummern nur mit gültigem Nonce (CSRF).
- Ticket-PDFs und Apple-Wallet-Dateien werden in privaten temporären Verzeichnissen erzeugt und sofort gelöscht.
- Beim Entfernen der letzten Check-in-Zuweisung wird das Recht `wpcap_checkin` wieder entzogen.

### Abläufe
- Eventabsage läuft pro Termin über die reguläre Terminabsage (automatische Erstattung, Module werden informiert, eine Absage-E-Mail je Käufer); durch Absage widerrufene, bezahlte Tickets bleiben erstattungsfähig.
- Gruppenbuchungen: Höchstmenge pro Position richtet sich nach „Max. pro Bestellung“ (statt fest 10, Obergrenze 100).
- Neue Einstellung „Einlass-Tageswechsel“ für Festivals und Nachtveranstaltungen.
- Einzelne Tickets können neu ausgestellt werden; „Tickets erneut senden“ lässt eingecheckte Tickets unverändert.
- Abgelaufene Reservierungen werden einzeln verarbeitet; eine zwischenzeitlich bezahlte Bestellung wird nicht mehr fälschlich storniert.
- Checkout-Ratenbegrenzung hinter Proxy/CDN über den Filter `wpcap_client_ip`.

### Kundenerlebnis
- Bestätigung nach der Bestellung mit kurzer Bestellnummer, Betrag und bei Überweisung Empfänger, IBAN, BIC, Verwendungszweck und Zahlungsfrist – auch in der Bestell-E-Mail (neue Einstellungen für Bankdaten, Platzhalter `{payment_instructions}`, `{order_reference}`).
- Barrierefreie Checkout-Fehler: Fehlerübersicht mit Links, Meldungen direkt am Feld, auch für Serverfehler.
- Bestellübersicht: kurze Bestellnummer, „Tickets erneut senden“, Sicherheitsabfrage vor dem Übertragen, Zahlungsinformationen bei offener Überweisung.
- Eventkarte: klare Reihenfolge und Beschriftung, Datumsformat der Website, Zeitzone nur bei Abweichung, Preise mit Währungssymbol.

### Steuer und Belege
- Rechnungen mit Netto, USt. und Brutto je Steuersatz; Korrekturbelege heißen „Rechnungskorrektur“; Belegnummern-Jahr nach Ortszeit.
- Kasse: deutlicher Hinweis, dass sie keine Registrierkasse nach RKSV (AT) bzw. KassenSichV/TSE (DE) ist.

### Datenschutz
- Täglicher Löschlauf setzt Aufbewahrungsfristen durch (Teilnehmerangaben, Offline-Namen, Wallet-Registrierungen, unbezahlte Bestellungen, versandte Nachrichten, Einlassprotokoll, Audit-Log); Finanzdaten bleiben.
- Datenschutz-Löschung und -Export vollständig; Tickets werden über den Ticketdienst widerrufen (Wallet, Offline-Pakete, Webhooks informiert); Rechnungen bleiben gemäß Aufbewahrungspflicht. Behoben: Löschung schlug bei Bestellungen mit mehreren Tickets fehl.
- Vorschlag für die Datenschutzerklärung je nach aktivierten Diensten.
- Affiliate-Cookie nur nach Einwilligung (WP Consent API); Web-Scanner-Offline-Warteschlange je Benutzer, 24 Stunden.
- IP-Hashes mit seitenspezifischem Schlüssel.

### Betrieb und Release
- Datenbank-Upgrade nicht mehr bei jedem Seitenaufruf, sondern im Admin/Cron/WP-CLI hinter einer Sperre (Schema 3.4.0 mit neuen Indizes).
- Mail-Warteschlange mit Zeitbudget (bis 500 Nachrichten pro Lauf, sofortige Folge-Läufe); Jobs, Webhooks und SMS mit Zeitbudget.
- Multisite: Tabellen gelöschter Sites werden entfernt.
- Release nur nach erfolgreicher CI; ZIP aus `git archive` mit `.distignore`; neue `readme.txt`; vollständiger Plugin-Header.

## 1.2.0 – 2026-11-12

### Gutschein in Ticket umwandeln (Voucher Captain)
- Neuer Dienst `wp_pass_captain_ticket_service_v1` (Klasse `Integrations\Vouchers\TicketService`): `issue_tickets( source, idempotency_key, target, recipient )` stellt für einen eingelösten Gutschein Tickets einer Ticketart aus – als kostenlose Bestellung „bezahlt per Gutschein“ über den normalen Bestellweg (Kontingent, Teilnehmerfelder, Ticket-Mail). Gleicher Schlüssel ⇒ gleiche Bestellung und Tickets; ausverkauft oder ungültig ⇒ Fehler ohne Tickets; vorübergehende Fehler sind wiederholbar (`retryable`).
- `revoke_tickets( idempotency_key, reason )` storniert die Tickets einer Umwandlung (eingecheckte bleiben gültig), `get()` liefert den Stand, `targets()` die künftigen Ticketarten für Auswahllisten. Jede Umwandlung steht im Audit-Log (`voucher_tickets_issued`) mit Gutschein-Referenz.
- Gutscheine für einen frei wählbaren Termin: Ziel `event_id` (optional `ticket_name`) statt einer festen Ticketart; `options()` liefert die wählbaren künftigen Termine, `events()` die Veranstaltungen für Auswahllisten. `issue_tickets()` prüft, dass die gewählte Ticketart zu einem künftigen Termin der Veranstaltung (und zum Ticketnamen) gehört.
- Ausverkaufte Termine werden nicht mehr angeboten (`options()` berücksichtigt die Ticketanzahl des Gutscheins, `targets()` liefert `available`). `fields( ticket_type_id )` nennt die Teilnehmerfelder, damit das Einlöseformular sie abfragen kann.

### Veranstalter statt „Partner“
- „Partner“ wird für Veranstalter nicht mehr verwendet: Der Rollenname „Pass Captain Partner“ wird beim Update automatisch in „Pass Captain Veranstalter“ umbenannt (ein vom Betreiber geänderter Name bleibt; die Umbenennung läuft erst bei `init`, damit Übersetzungen nicht zu früh geladen werden). Die Doku spricht durchgehend von Veranstaltern, Veranstalter-API und Veranstalter-Abrechnungen; „Partner“ meint nur noch die Affiliate-Partner von WP Affiliate Captain. REST-Pfade, Header, Fehlercodes, Tabellen und der Rollen-Slug bleiben unverändert.

## 1.1.0 – 2026-11-12

### Sprachen
- Neue Übersetzungen: Französisch (fr_FR), Italienisch (it_IT), Spanisch (es_ES) und Niederländisch (nl_NL), jeweils vollständig (1839 Texte); Englisch ergänzt. Platzhalter automatisch geprüft.

### WooCommerce: Serien-Preisstufen im Shop
- Neue Einstellung „Pass-Captain-Preise (z. B. Serien-Preisstufen) im WooCommerce-Warenkorb verwenden“ (WooCommerce → Einstellungen → Produkte, standardmäßig aus): Preise verknüpfter Ticketprodukte kommen aus Pass Captain (Frühbucher-, Last-Minute-, Nachfrage- und Wochentagsstufen) – im klassischen Warenkorb, Mini-Warenkorb, Checkout sowie im Warenkorb- und Checkout-Block.
- Produktseite und Warenkorb zeigen den wirksamen Preis mit Stufenbezeichnung und „statt“-Preis; bei netto gepflegten Shoppreisen wird mit dem Steuersatz des Produkts umgerechnet.
- Ändert sich der Preis zwischen Warenkorb und Bestellung (z. B. Frühbucherfrist abgelaufen), wird neu berechnet, ein Hinweis angezeigt und das Absenden einmalig angehalten.
- Neuer Filter `wpcap_woocommerce_use_core_prices`.

### E-Mail-Vorlagen
- Neu: E-Mail-Vorlagen (Pass Captain → E-Mail-Vorlagen): Betreff und Text aller Kunden-Mails (Tickets, Neuversand, Bestellzugang, Übertragung, Zuweisung, Erstattung, Absagen, Terminänderung, Warteliste, Mitgliedspass) je Sprache anpassbar – mit Platzhalter-Übersicht, Live-Vorschau, „Testmail senden“ und „Auf Standard zurücksetzen“. Siehe docs/MAIL-TEMPLATES.md.
- Optionaler HTML-Modus mit schlichtem Layout (Logo, Akzentfarbe, Fußzeile) und automatisch mitgesendeter Textversion.
- Kunden-Mails werden in der Sprache der Bestellung gesendet (Vorlage der Sprache → Standardsprache → eingebauter Text).
- Zentraler Mailer `PassCaptain\Mail\Mailer` mit `wpcap_mail_types`, `wpcap_mail`, `wpcap_mail_before_send`, `wpcap_mail_layout_html`; `wpcap_ticket_mail` und `wpcap_ticket_code_mail_section` funktionieren unverändert. Ohne eigene Vorlagen bleiben alle Texte gleich.

### WP Affiliate Captain
- Oberfläche: Das Modul „Partner“ (Veranstalter/Wiederverkäufer mit Abrechnungen) heißt jetzt **Veranstalter** bzw. **Veranstalter-Abrechnungen**, damit es nicht mit den Affiliate-Partnern verwechselt wird (de und en_US: „Organizer“). REST-Pfade, Header, Fehlercodes, Tabellen und Rollen-Slug bleiben unverändert.
- Optionaler Adapter `src/Integrations/AffiliateCaptain/Adapter.php` für WP Affiliate Captain (Vertrag v1). Aktiv nur, wenn Affiliate Captain mit kompatibler Vertragsversion läuft; nutzt ausschließlich vorhandene Hooks, keine Kernänderungen.
- Bezahlte Bestellungen (Online-Checkout, Kasse, Abendkasse, Warteliste) werden als Verkauf `pass-captain` gemeldet; Basis je Position ist der tatsächlich bezahlte Betrag (nach Kampagnenrabatt und eingelöstem Gutschein) ohne die gespeicherte USt. Provision bleibt bis nach dem Termin gesperrt.
- Erstattungen (auch Teilerstattungen und Erstattungen abgesagter Termine) kehren den Nettoanteil der erstatteten Tickets um; Vollerstattung und Storno bezahlter Bestellungen kehren den Rest um. WooCommerce-Bestellungen werden nicht gemeldet – die erfasst Affiliate Captain selbst.
- Checkout: optionales Feld „Partnercode“ (geprüft, Fehlversuche je Client begrenzt); Partnerlink/Referral-Cookie wird mit der Bestellung gespeichert. Kasse und Abendkasse übernehmen nie das Referral der Mitarbeitenden.

### Gutscheine (Voucher Captain)
- Verspätete Zahlung nach Ablauf: War die Gutschein-Reservierung beim Ablauf der Bestellung schon freigegeben, wird der Gutschein bei der verspäteten Zahlung mit dem gespeicherten Angebot neu reserviert und belastet. Bisher wurde das Ticket mit Rabatt ausgestellt, ohne den Gutschein zu belasten.
- Fehler beim Belasten des Gutscheins werden nicht mehr nur protokolliert: Vorübergehende Fehler (Status ≥ 500, `vcap_temporary_failure`, Anbieter nicht aktiv) werden über die Job-Warteschlange (`voucher_commit`) mit denselben Schlüsseln wiederholt. Endgültige Fehler und der letzte Versuch markieren die Bestellung (`commit_failed` im Gutschein-Snapshot, Hinweis in der Bestellansicht, Audit-Eintrag, E-Mail an den Admin). Die Tickets bleiben gültig.
- `Checkout::commit()` gibt `true` (erledigt) oder `false` (Wiederholung geplant) zurück; neu: `Checkout::handle_job()` und `Checkout::voucher_state()`.

### Datenbank
- MySQL: Die Prüfung „Transaktion noch offen?“ fragt `@@in_transaction` nur noch bei MariaDB ab (einmal pro Anfrage per `VERSION()` erkannt). Unter MySQL gibt es diese Variable nicht; bisher schrieb jede Bestellung dort die Zeile „Unknown system variable 'in_transaction'“ ins Fehlerprotokoll. Das Verhalten unter MySQL bleibt gleich (Zustand unbekannt).

## 1.0.0 – 2026-11-11

### Serien: Preisstufen und Kontingente
- Serientermine: Preisstufen je Serie – Frühbucherpreis (bis Datum/Uhrzeit in Ortszeit), Last-Minute-Preis (ab X Stunden vor Beginn), nachfrageabhängige Stufen (ab X % verkauft) sowie Wochentags-/Zeitfensterpreise; fester Preis oder prozentuale Änderung, optional nur für eine Ticketart, mit Prioritäten.
- Gemeinsame Kontingente (Pools) über alle Termine einer Serie, für alle oder eine Ticketart; race-frei in der Bestell-Transaktion gesperrt, automatische Freigabe bei Ablauf, Storno und Erstattung.
- Eventseite, Checkout, Kasse, öffentliche REST-Eventliste und Mollie-Zahlartenauswahl nutzen den tatsächlich berechneten Preis (mit Stufenbezeichnung und „statt“-Preis).
- Neue Entwickler-Hooks: `wpcap_ticket_unit_price`, `wpcap_ticket_price_label`, `wpcap_reserve_capacity`, `wpcap_ticket_availability`, `wpcap_capacity_released`.
- Bestellungen sperren Ticketarten und Termine in fester Reihenfolge vor der Kontingentprüfung (auch bei verspäteter Zahlung).

### Partner-Zugang und Abrechnung
- Neues Modul „Partner“: Veranstalter/Wiederverkäufer mit Rechnungsdaten, Provisionsmodell (Prozent vom Netto und/oder feste Gebühr pro Ticket, USt auf die Plattformgebühr, Erstattungsregel), Zuordnung von Events und Benutzern.
- Partner-API (`/wp-json/wp-pass-captain/v1/partner/…`, nur lesend) mit API-Keys: Secret nur einmal sichtbar und nur als Hash gespeichert, Scopes, Event-Einschränkung, IP-Allowlist, Ablaufdatum, Widerruf, Ratenlimit, Sperre nach Fehlversuchen; E-Mail-Adressen nur mit `attendees:read`. Siehe docs/PARTNER-API.md.
- Monatliche Partner-Abrechnungen (Entwurf → abgeschlossen mit lückenloser Nummer → ausgezahlt), unveränderlich nach Abschluss; spätere Erstattungen/Nachzahlungen als Korrektur bzw. Nachtrag in der nächsten Periode; Druckansicht und CSV.

### Offline-Scanner (PWA)
- Offline-Scanner als installierbare Web-App: gekoppelte Geräte laden pro Termin ein Ed25519-signiertes Prüfpaket (nur SHA-256-Hashes der Ticketcodes, Status, Tickettyp, erlaubte Bereiche), lassen ohne Netz ein und synchronisieren später; Konflikte werden in der App angezeigt.
- Offline-Scannen muss pro Event freigegeben werden: maximale Offline-Dauer, Regel für Doppelscans („zur Prüfung“ oder „erste Synchronisierung gewinnt“), optional Teilnehmernamen.
- Admin-Seite „Offline-Scanner“: Freigabe und Regeln, Geräte-Zuweisung je Event oder Termin, Schlüsselrotation mit Übergangsfrist.
- App-API `GET /app/offline/keys`, `/app/offline/occurrences`, `/app/offline/package` (Voll- und Delta-Pakete inkl. widerrufener Tickets). Siehe docs/OFFLINE-SCANNER.md.
- Offline-Scans nach Ablauf der maximalen Offline-Dauer erscheinen als Einlass-Konflikt „Offline-Dauer überschritten“.

### Last- und Barrierefreiheitstests
- Lasttests (`tests/load/run.php`, `tests/load/http.php`): parallele Käufer mit begrenztem Kontingent, gleichzeitige Check-ins desselben Tickets von mehreren Geräten und Webhook-Bursts mit Duplikaten; prüfen Überverkauf, Idempotenz und Fehlerquote, messen p50/p95/p99. Das kleine Profil läuft in CI als Regressionstest.
- Barrierefreiheitstests (`tests/a11y/`): axe-core + Playwright für Checkout, Ticketseite, Bestellportal, Warteliste, Scanner und zentrale Admin-Seiten sowie ein Tastatur-Durchlauf des Checkouts. Siehe docs/TESTING.md.
- Barrierefreiheit: gültiges `lang`-Attribut (`de-DE`), Beschriftungen für Filter, Zeitzone, Währung, Erstattungs-Checkboxen und Kassenmengen, besserer Kontrast für Codes in Links, größere Klickflächen bei den Zahlarten, korrekte Überschriftenebenen (Eventkarte per Filter `wpcap_event_card_heading_level`, Sitzplatzauswahl); Tastaturfokus bleibt nach Checkout-Absenden und im Scanner erhalten.

### Korrekturen
- Behoben (gefunden durch einen sporadischen CI-Fehler): Wurde die Bestell-Transaktion serverseitig zurückgerollt (Deadlock/Lock-Timeout), liefen die restlichen Anweisungen ohne Sperre im Autocommit weiter – im Extremfall Überverkauf. Bestellanlage und Zahlungsbestätigung prüfen jetzt jede Anweisung und den Transaktionsstatus und melden dann einen wiederholbaren Fehler (503); das Aufräumen abgelaufener Reservierungen innerhalb der Sperre (Deadlock-Quelle) entfällt.
- Behoben (gefunden durch Lasttest): Gleichzeitige Check-ins verschiedener Tickets konnten einen Datenbank-Deadlock auslösen; der Scanner meldete dann „eingelassen“, obwohl die Buchung zurückgerollt war, und das Ticket blieb gültig. Die Admission-Zeile wird nicht mehr per Gap-Lock gesperrt, und jeder Schreibschritt wird geprüft; bei einem Fehler wird zurückgerollt und ein wiederholbarer Fehler (503) gemeldet.
- Erstattungen enthalten die Umsatzsteuer der erstatteten Positionen (vorher anteilig über die ganze Bestellung, falsch bei gemischten Steuersätzen); wirkt auf Gutschriften, Berichte und Partner-Abrechnungen.

## 0.9.0 – 2026-11-11

### Abnahmetest in echtem WordPress/WooCommerce
- Ticket-Mails zeigen die Startzeit in der Zeitzone des Termins (vorher UTC mit lokalem Zonennamen).
- Kundenkommunikation nutzt den Seitennamen statt „WP Pass Captain“; Status werden übersetzt angezeigt.
- Kundenportal: Namens-/Angabenkorrektur behält den Ticketcode; neue Inhaber:in erhält einen neuen Code, Käufer:in wird informiert.
- Sitzplatz auf PDF, Ticketseite und im Portal; Reservierungsdauer in Stunden/Tagen.
- Bestelldetail: Rechnung erstellen/anzeigen, Gutschriften; Einstellungsseite ohne Rohschlüssel.
- Erstattungsbestätigung per Mail; Rollen werden erst bei `init` registriert (kein Textdomain-Hinweis).
- Abendkasse: Migration für `order_id NULL`, Teilnehmerfelder je Ticket, kein Beleg für ungebuchte Verkäufe.
- WooCommerce: Teilnehmerfelder im klassischen und Block-Checkout, Teil-Erstattungen mit Buchung und Gutschrift (`Refunds::record_external()`), Nachnahme stellt erst bei „Abgeschlossen“ aus, Mengenbegrenzung in der Store API.

### P2: Erweiterte Eventfunktionen, Einlass, Vor-Ort-Verkauf

Alle P2-Funktionen sind Module (`src/Modules/`) mit eigenen Tabellen, Einstellungen und Admin-Seiten unter **Pass Captain**.

- **Saalpläne:** grafischer SVG-Editor (Reihen, Blöcke, Tische, Stehbereiche, Zonen, Rollstuhlplätze), versionierte Layouts mit Schutz verkaufter Plätze, barrierearme Platzwahl im Checkout, atomare Platzreservierung, Platz auf Ticket und in der Mail.
- **Serientermine:** täglich, wöchentlich (Wochentage), monatlich (Tag / n-ter Wochentag), Datumsliste; zeitzonen- und sommerzeitsicher, mit Ausnahmen, Vorschau und idempotenter Erzeugung inkl. Ticketkategorien und Verkaufsfenstern.
- **Einlass:** Regeln pro Ticketkategorie (einmalig, täglich, mehrtägig, mehrfach, Wiedereinlass), Einlasszeitfenster, Check-out, Einlassbereiche für Ticketkategorien, Personal und Geräte, Klärungsliste für Offline-Doppeleinlässe, Gerätestatus. Mobile-API 1.1.
- **Abendkasse:** Verkauf mit gemeinsamem Kontingent, Zahlarten, lückenlose Bons, 80-mm-Druck, Kassensitzungen, Storno mit Freigabe; Verkauf auch nach Beginn bis Terminende.
- **Druck:** PDF-Vorlagen für Ticket, Badge, Namensschild, Armband; Sammeldruck; Teilnehmerliste (Druck/CSV, rollenbasiert); Rechnungs- und Gutschrift-PDF.
- **Berichte:** Umsatz mit USt pro Satz und Erstattungen, Auslastung, Einlassquote und -verlauf, Kasse vs. online; CSV und SVG-Diagramme.
- **Wallet:** Apple Wallet (signierte Pässe, Update-Webservice, APNs-Push) und Google Wallet (Speichern-Link, Status-Updates).
- **Kalender:** Shortcode/Block, iCal-Download und -Feed, `.ics` in der Ticket-Mail.
- **Erinnerungen:** mehrere Zeitpunkte vor Beginn, über die Nachrichtenwarteschlange, mit Vorlagen.
- **SMS:** Twilio und generischer HTTP-Anbieter, nur mit dokumentierter Einwilligung, Zustellstatus.
- **Mollie:** weiterer Zahlungsanbieter (SEPA, Klarna, EPS, iDEAL, Bancontact u. a. je nach Land/Währung).
- **Webhooks:** signierte, versionierte ausgehende Webhooks mit Wiederholungen; `docs/WEBHOOKS.md`, alle Entwickler-Hooks in `docs/HOOKS.md`.
- **Kern:** Modulsystem; neue Hooks `wpcap_order_creating`, `wpcap_ticket_created`, `wpcap_ticket_mail`, `wpcap_ticket_code_rotated`, `wpcap_order_refunded`, `wpcap_ticket_checked_out` u. a.; alle Widerrufswege lösen `wpcap_ticket_revoked` aus.
- **Abendkasse – Korrekturen:** Bestehende Installationen werden migriert (`wpcap_boxoffice_sales.order_id` wird `NULL`-fähig, alte `0`-Einträge werden `NULL`, verwaiste Verkaufs-Reservierungen gelöscht; dbDelta ändert `NULL`/`NOT NULL` nicht). Bisher blockierte ein fehlgeschlagener Verkauf jeden weiteren mit „Duplicate entry '0' for key 'order_id'“. Belege gibt es nur noch für gebuchte Verkäufe.
- **Abendkasse – Teilnehmerangaben:** Das Verkaufsformular zeigt je Ticket Name, E-Mail und die Teilnehmerfelder der Ticketart (gleiche Definition wie im Online-Checkout); Pflichtfelder (z. B. „Firma“) lassen sich damit erfüllen. Für Ticketarten mit Saalplan weist die Kasse die besten freien Plätze automatisch zu und sagt das im Formular an.

### Scanner-App-API und Einschränkungen aufgehoben

- **Mobile Scanner-API** (`/wp-json/wp-pass-captain/v1/app/`): Gerätekopplung per Einmal-QR, Bearer-Gerätetokens (ablaufend, eventgebunden, widerrufbar, nur als Hash gespeichert), Prüfen, Einlass mit Idempotenz-Schlüssel, Rücknahme, Offline-Synchronisierung mit Konfliktkennzeichnung, Termin-Statistik. Admin-Seite **Scanner-Geräte**. Spezifikation in `docs/MOBILE-API.md`.
- **Gemeinsamer Check-in-Dienst** für Web, App und Offline-Sync. Abgelehnte Zweitscans werden als `already_checked_in` protokolliert statt als `checked_in`; das hatte die Einlasszahlen in Berichten erhöht.
- **Kamera-Scan auf iOS/Safari/Firefox** über das mitgelieferte jsQR, falls keine native Barcode-Erkennung vorhanden ist.
- **Absage einzelner Termine**: offene Bestellungen werden storniert, Tickets widerrufen, Inhaber informiert; optional automatische Erstattung bezahlter Bestellungen (`auto_refund_cancelled`). WooCommerce-Bestellungen erhalten eine Notiz.
- **Englische Übersetzung** (`en_US`) und ein abhängigkeitsfreier `.mo`-Compiler (`build/compile-mo.php`).
- Schema 3.3.0 (Geräte-Kopplungen, Gerätefelder, Check-in-Quelle/Idempotenz).

### Verkaufsfähigkeit (Blocker und P1)

- **Online-Zahlung:** Stripe Checkout und PayPal (Orders v2) mit signierten, idempotenten Webhooks, Erstattung über den Anbieter und Hinweis nach Rückkehr vom Zahlungsanbieter. Webhook-URLs: `/wp-json/wp-pass-captain/v1/payments/stripe/webhook` und `…/paypal/webhook`. Tickets werden nur bei passendem Betrag und Währung ausgestellt.
- **Admin-Oberfläche:** Events, Termine, Ticketkategorien, Teilnehmerfelder, Personal, Bestellungen (Details, Bezahlt-markieren, Storno, Erstattung, Freigaben), Tickets (Suche, Widerruf, Check-in rückgängig), Check-in-Protokoll und Einstellungen.
- **Checkout:** mehrere Ticketkategorien pro Bestellung, Teilnehmerfelder je Ticket, Zahlungsartwahl, Rabatt- und Gutscheincode, Verbraucherinformationen und Pflicht-Bestätigung der Bedingungen; die akzeptierte Textversion wird gespeichert.
- **Erstattungen:** positionsbezogen, idempotent, mit Zweitfreigabe ab Schwellenwert und optional verpflichtender Zwei-Faktor-Anmeldung.
- **Steuern und Belege:** USt-Satz je Ticketkategorie, Berechnung je Position aus Bruttopreisen, Rechnungen mit USt und Verkäuferdaten, Gutschriften je Erstattung, Druckansicht, CSV-Buchhaltungsexport.
- **Voucher Captain:** Gutschein-Einlösung im Checkout (Angebot, Reservierung, Verbuchung, Freigabe, anteilige Rückbuchung).
- **Self-Service und Kommunikation:** Teilnehmerdaten bis zu einer Frist änderbar, Benachrichtigung bei Terminverschiebung, PDF-Tickets als E-Mail-Anhang.
- **Rollen:** Ticket-Administration, Veranstaltungsleitung, Vor-Ort-Kasse, Einlass, Partner, Finanzbuchhaltung, Support.
- **Betrieb:** `uninstall.php` (Daten bleiben standardmäßig erhalten), Übersetzungsvorlage `languages/wp-pass-captain.pot`, Schema 3.2.0.

### Fehlerbehebungen nach Code-Review

- Warteliste: Die Annahme eines Angebots legt direkt eine Bestellung mit Reservierung an. Ist das Ticket inzwischen weg, bleibt das Angebot bis zum Ablauf offen. Die Anmeldung antwortet immer gleich und verrät nicht mehr, ob eine Adresse schon eingetragen ist; neue Einträge erhalten eine Bestätigungsmail mit Position. Angebotsablauf in der Mail in Ortszeit.
- Manuelle Zahlungsbestätigung nach Ablauf der 10-Minuten-Reservierung prüft das Kontingent erneut, statt die Bestätigung abzulehnen.
- Checkout und Servicebuchungen haben zusätzlich ein Limit pro IP, damit wechselnde E-Mail-Adressen das Limit nicht umgehen.
- Gutenberg-Block hat ein Editor-Skript (Vorschau über ServerSideRender) und erscheint im Inserter.
- `composer.lock` (Plattform PHP 8.2) für reproduzierbare Release-Builds.

- Sicherheit: Der öffentliche Checkout übernimmt nur noch `email`, `items`, `idempotency_key` und `campaign_codes`. Vorher ließen sich über die REST-API Preise auf 0 setzen oder Kontingente unbegrenzt blockieren.
- Idempotency-Keys von Kasse (POS) und WooCommerce sind vom öffentlichen Checkout getrennt; gleichzeitige Anfragen mit demselben Key liefern die bestehende Bestellung statt eines Fehlers.
- POS-Verkäufe melden den tatsächlichen Bestellstatus und unterliegen nicht mehr dem anonymen Checkout-Limit.
- Eventbezogene Rechte (Event verwalten, Bestellungen/Kasse, Berichte) lassen sich zuweisen; vorher erhielt die Veranstaltungsleitung überall 403.
- Datenschutz-Löschung: keine übersprungenen Datensätze ab der 51. Bestellung mehr, und Löschanfragen von Teilnehmenden verändern nicht mehr die Bestellung anderer Käufer.
- Scanner: Kamera-Scan funktioniert (vorher Fehler beim Start), Scans bei Netzwerkfehlern werden zwischengespeichert, keine verlorenen Scans während der Synchronisierung, kein Doppelscan desselben QR-Codes.
- Teilweise erstattete und widerrufene Tickets geben ihr Kontingent frei (neue Spalte `refunded_quantity`, Schema 3.1.0).
- WooCommerce: exakte Positionssummen statt gerundeter Stückpreise, Nachkommastellen nach Währung statt Anzeigeeinstellung, Admin-Hinweis zu `needs_review` nur einmal, persönlicher Bestelllink nur bei der ersten Ausstellung.
- Rechnungsnummern werden in derselben Transaktion wie die Rechnung vergeben (keine Lücken bei Abbruch).
- Eventabsagen gehen nur an Käufer mit bezahlter oder offener Bestellung.
- Cron-Ereignisse werden auch nach Plugin-Updates eingeplant; Admin zeigt Startzeiten in der Event-Zeitzone; Seat-Map- und Übersetzungs-Endpunkte korrigiert.

### WooCommerce-Adapter

- WooCommerce-Produkte werden über eine Auswahlliste mit einer Ticketkategorie verknüpft; die Kategorie wird zusätzlich an der Bestellposition gespeichert.
- Warenkorb und Kasse (Classic und Blocks/Store API) prüfen Verkaufsstatus, Verkaufsfenster, Höchstmenge und Restkontingent.
- Beim Anlegen der Woo-Bestellung wird das Kontingent vor der Zahlung reserviert (mindestens so lange wie „Lagerbestand halten“ in WooCommerce). Ist kein Kontingent mehr frei, bricht der Checkout vor der Zahlung ab.
- Nach Zahlung wird genau ein Ticket je Einheit ausgestellt, auch bei wiederholten Status-Hooks. Spät eintreffende Zahlungen prüfen das Kontingent erneut; ohne freien Platz wird die Bestellung als `needs_review` markiert und der Admin benachrichtigt, statt zu überbuchen.
- „In Wartestellung“ verlängert die Reservierung, „Fehlgeschlagen“/„Storniert“ gibt sie frei und widerruft gültige Tickets, Teil- und Vollerstattungen widerrufen die erstatteten Tickets (eingecheckte Tickets bleiben als Historie erhalten).
- Tickets werden auf der Danke-Seite, in „Mein Konto“ und in einer Meta-Box der Bestellung angezeigt; Bestellaktionen „Tickets erneut senden“ und „Tickets erneut ausstellen“.
- Behoben: Kostenpflichtige Bestellungen schlugen immer fehl, weil `$wpdb->last_error` mit `0` statt mit einem leeren String verglichen wurde.
- Behoben: Syntaxfehler in `src/Campaigns.php`, der jeden Checkout abbrechen ließ.
- Ticketausgabe läuft unter einer Zeilensperre der Bestellung, damit parallele Zahlungsbestätigungen keine doppelten Tickets erzeugen.
- Integrationstests gegen MariaDB (inkl. 20 paralleler Käufe auf 5 Plätze) und eigener CI-Job.
- Behoben: Ticketkategorien mit Pflicht-Teilnehmerfeldern ließen sich über WooCommerce nicht kaufen („Bitte „Firma“ für jedes Ticket ausfüllen.“ ohne Eingabefeld). Klassischer Checkout und Checkout-Block fragen die Felder jetzt je Ticket ab und geben sie an die Bestellung weiter.
- Saalplan-Kategorien: der Warenkorb prüft freie passende Plätze (WooCommerce vergibt die besten freien Plätze automatisch).
- Behoben: WooCommerce-Erstattungen werden über das Erstattungsjournal gebucht (Erstattungszeile, erstatteter Betrag, Gutschrift), sobald der Kern `Refunds::record_external()` bereitstellt; vorher wurden nur Tickets widerrufen.
- Behoben: Bei Nachnahme wurden Tickets schon bei „In Bearbeitung“ ausgestellt; jetzt erst bei „Abgeschlossen“ (Filter `wpcap_woocommerce_issue_on_completed_gateways`).
- Behoben: Die Store API (Warenkorb-Block) nahm Mengen über dem Kontingent an; Mengenauswahl und `update-item` sind jetzt begrenzt.

## 0.3.0 – Plattformgrundlagen

- Kampagnencodes mit Prozent- und Festbetrag-Rabatt, Event-/Ticketbereich, Priorität und atomarem globalem Nutzungslimit.
- Wartelistenreihenfolge mit befristeter Angebotsannahme; das Angebot reserviert keine Ticketkapazität.
- Rollenbeschränkter Umsatz-/Einlassbericht, eventgebundene Entwickler-Schlüssel mit Read-only-Scopes und Übersetzungen für Eventinhalte.
- Datenmodell für Länder-/Steuerprofile (Entwurfsstatus), Integrationskonfiguration und generische Hintergrundjobs.
- Multisite-Installation legt Tabellen und Cron-Ereignisse getrennt je Site an.
- Betriebs-, Sicherheits-, Barrierefreiheits- und Erweiterungsdokumentation sowie zusätzliche Indizes und öffentliche Fokuszustände.

Diese Version ist ein Entwicklungsstand. Externe Prüfungen und eine Freigabe für produktive Steuer-, Sicherheits- oder Barrierefreiheitsanforderungen stehen aus.
