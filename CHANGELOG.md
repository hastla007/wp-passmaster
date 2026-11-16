# Änderungsprotokoll

## Unveröffentlicht

- **Behoben (Audit): Umstieg auf Multisite konnte Daten von Subsites löschen.** Die Pause von „Beim Löschen des Plugins alle Daten entfernen“ galt nur für die Site, deren Admin-Bereich aufgerufen wurde; die `uninstall.php` der alten Kopie läuft aber über alle Sites. Jetzt wird bei jedem Laden von Pass Captain (auch Cron und WP-CLI) netzwerkweit pausiert und danach wiederhergestellt. Außerdem planen fehlende Cron-Ereignisse sich beim nächsten Aufruf selbst neu ein (`Plugin::ensure_events()`), statt nur bei Aktivierung oder Upgrade. Neuer CI-Test `tests/wp-matrix/rename-takeover-ms.sh`; der Einzelsite-Test führt jetzt die echte `uninstall.php` der alten Kopie aus.

- **Neuer Name „Pass Captain“, Slug `pass-captain` (für WordPress.org).** Bisher „WP Pass Captain“ mit Slug, Ordner und Textdomain `wp-pass-captain` – WordPress.org erlaubt kein „WP“ am Namensanfang, und Sprachpakete brauchen Textdomain = Slug. Geändert: Plugin-Name, Ordner/Hauptdatei `pass-captain/pass-captain.php`, Textdomain und Sprachdateien `pass-captain`, REST-Namensraum `/wp-json/pass-captain/v1/`, Admin-Seiten `page=pass-captain…`, Release-ZIP `pass-captain.zip`; die Partner-Plugins heißen in der Oberfläche „Voucher Captain“ und „Affiliate Captain“. Unverändert: Daten (`wpcap_*`), Rollen, Hooks/Shortcodes, Blocknamen. Kompatibilität (`src/LegacySlug.php`): der alte REST-Namensraum funktioniert weiter (Zahlungs- und SMS-Webhooks, bereits ausgegebene Wallet-Pässe, Apps; Antwort mit `Deprecation`/`Link`, Twilio-Signatur gegen die aufgerufene URL), alte Admin-Links leiten um, eine installierte alte Kopie wird beim Aktivieren abgeschaltet (kein Doppelladen), und solange ihr Ordner existiert, ist die Datenlöschung beim Deinstallieren pausiert, damit das Löschen der alten Kopie keine Daten entfernt. Neuer CI-Test `tests/wp-matrix/rename-takeover.sh` (echte Website mit Vorgänger-Release und Daten → Umstieg). Anleitung: docs/RENAME.md.

### Auditbefunde Runde 4–6 (Arbeitsliste A263–)
- Druck- und Belegseiten, Ticketseite, Bestellportal, Warteliste und Rechnungsansicht nennen ihre tatsächliche Sprache (`lang` aus der aktuellen Sprache, `dir="rtl"` bei Rechts-nach-links-Sprachen) statt fest „de“. (A320)
- Monatskalender: Termine stehen am Tag und mit der Uhrzeit ihrer eigenen Zeitzone (mit Zonenkürzel bei abweichender Zone); kein „Vorheriger Monat“ im aktuellen Monat, Link „Aktueller Monat“, Markierung für heute. (A313)
- Eventliste: eine Karte mit einem Bestellformular je Event, weitere Termine per `?wpcap_occurrence=` auswählbar; Kalender-, .ics- und alte Anker-Links landen immer auf einer Karte; `dates="each"`/„Jeden Termin als eigene Karte“ für die bisherige Darstellung. (A342)
- Ticketarten mit Bestellungen: Preis-, Steuersatz- und Steuerklassenänderungen nur mit Bestätigung; das Formular zeigt die verkauften Tickets und dass die Änderung nur für neue Bestellungen gilt. (A334)
- Serientermine übernehmen aus dem Vorlagentermin auch Teilnehmerfelder, Einlassprüfung, Einlassregel und Einlassbereiche. (A332)
- Ticketarten und Teilnehmerfelder lassen sich „auf alle kommenden Termine“ des Events anwenden (mit denselben Prüfungen je Termin). (A333)
- Ein PDF-Weg für alles: Ticket-PDFs in Mail, Portal und Admin nutzen die Ticketvorlage des Events, alle PDFs betten eine Unicode-Schrift (DejaVu, nur genutzte Zeichen) ein – Namen in Polnisch, Tschechisch, Türkisch, Griechisch, Kyrillisch u. a. werden korrekt gedruckt; dokumentierter Rückfall auf Helvetica. (A330)
- WooCommerce zeigt Terminzeiten (Danke-Seite, Mein Konto, Bestell-Metabox, Produktauswahl) in der Zeitzone des Termins mit Zonenangabe. (A276)
- Block-Editor-Texte und block.json sind übersetzbar: wp-i18n-Abhängigkeit, Skriptübersetzungen aus dem Plugin-Ordner, JSON-Kataloge per `wp i18n make-json` im Build. (A275)
- Die öffentliche Eventliste ruft die Mollie-API nicht mehr selbst auf: Methodenliste aus dem Cache, Nachladen im Hintergrund, fehlgeschlagene Abfragen werden 5 Minuten gemerkt. (A282)
- Deinstallation mit Datenlöschung entfernt die WooCommerce-Verknüpfungen (Bestellungen, Positionen, Produkte); veraltete Verknüpfungen nach einer Neuinstallation werden erkannt und nie auf fremde Bestellungen angewendet. (A284)
- Mit HyperDB/LudicrousDB laufen Transaktionen und sperrende Lesezugriffe auf dem Primärserver (kein Doppelverkauf über Replikate); Cluster-Voraussetzungen dokumentiert. (A285)
- Die Offline-Scanner-App wird vom eigenen Ursprung der Website ausgeliefert und funktioniert auch, wenn Plugin-Assets über ein CDN kommen. (A286)
- Netzwerkweite Aktivierung richtet nur die aktuelle Site sofort ein, alle weiteren im Hintergrund in Stapeln (kein Timeout bei großen Netzwerken). (A287)
- Hinweise des Plugins erscheinen nur auf den Pass-Captain-Seiten, lassen sich je Benutzer ausblenden und stehen im Website-Zustand; Add-on-Hinweise nur über `wpcap_addon_notices`, dezent und wegklickbar. (A301)
- Inline-JavaScript und -CSS laufen über `wp_add_inline_script`/`wp_add_inline_style` bzw. `wp_get_inline_script_tag()` (CSP-Nonce möglich, Plugin Check). (A302)
- Mails kommen auch ohne installiertes WordPress-Sprachpaket in der Sprache der Bestellung, sofern das Plugin die Übersetzung mitbringt. (A306)
- Die Bestätigung nach der Rückkehr von der Zahlungsseite erscheint auch in Themes ohne `wp_body_open` und bietet einen sicheren Weg zum Bestellportal (neuer Bestelllink per E-Mail). (A307)
- WordPress Plugin Check läuft in CI gegen das gebaute ZIP; neue Fehler lassen den Build scheitern (Baseline für den Altbestand), SVN-Deploy und Assets als Release-Entscheidung dokumentiert. (A305)
- Mollie-Webhook: Das Limit je IP greift vor jeder Datenbankabfrage; Zahlungs-IDs werden per Primärschlüssel bzw. neuem Index `provider_ref` gesucht statt per Full Scan über alle Bestellungen (466 ms → 0,3 ms bei 200.000 Bestellungen; Schema 3.10.23). (A431)
- Admin-Ticketliste: Letzter Check-in nur noch für die Tickets der angezeigten Seite; bei 170.000 Tickets 15 ms statt 2,5 s. (A432)
- Check-in-Protokoll: Zählung und Seite ohne Joins über den neuen Index `created`, Ergebnisfilter als feste Liste (Filter `wpcap_checkin_log_results`). (A433)
- Umsatzbericht, Buchhaltungs- und DATEV-Export, Termin- und Wiederverkäufer-Abrechnung lesen Bestellungen blockweise statt mehrerer Abfragen je Bestellung; der Export wird zeilenweise gestreamt (Buchhaltungsexport für einen Monat: 27 s → 2,4 s). (A434)
- Veranstalter-Abrechnung: Entwürfe lesen nur noch nicht abgerechnete Verkäufe und Erstattungen (33 s → 5 s). (A435)
- Webhook-Protokoll: Aufräumen stündlich in Stapeln zu 1.000 Zeilen mit Zeitbudget über den neuen Index `cleanup` statt alle 5 Minuten per Full Scan. (A437)
- Lasttest `tests/load/history.php`: Bestand mit Tickets, Check-ins, Zustellungen, Erstattungen und Abrechnungen sowie Messungen für Admin-Listen, Berichte, Exporte, Abrechnungen und Massenoperationen. (A438)
- Berichte, No-Show- und Teilnehmerliste zählen Einlässe nach derselben Regel: Die Einlasskurve beginnt mit dem Einlassbeginn, zählt zurückgenommene Scans nicht mehr und schneidet nichts mehr ab. (A571)
- Die Check-in-Quote im Bericht zählt zurückgenommene Einlässe nicht mehr mit und stimmt jetzt mit der No-Show-Liste überein. (A572)
- Pass-Inhaber erscheinen in Teilnehmer- und No-Show-Listen, Check-in-Quote und Einlasskurve jedes verknüpften Termins und nicht mehr auf einem nicht verknüpften Ankertermin (auch in den blockweise gelesenen Listen). (A573)
- Partner-API: `since` liefert bei Tickets alle Änderungen (Widerruf, Erstattung, Übertragung, Teilnehmerdaten) über das neue Feld `updated_at`; der neue Scope `attendees:details` liefert Teilnehmernamen und Antworten (Schema 3.10.22). (A574)
- Personalisierung: Eine Namensänderung auf eine andere Person zählt als Inhaberwechsel, jede Namensänderung ist begrenzt (Tippfehler bleiben korrigierbar); die Zähler liegen am Ticket statt nur im Audit-Log. (A569)
- Nach einer Übertragung pflegt die neue Inhaberin Name und Angaben selbst über ihren Ticketlink (bis zur Änderungsfrist, Code bleibt). (A570)
- Eine beim Checkout eingetragene Teilnehmer-E-Mail sperrt den PDF-Download im Bestellportal nicht mehr; Portal, Erinnerungen und Wallet-Links richten sich danach, wer den aktuellen Code erhalten hat. (A581)
- Ticketmails werden mit den gespeicherten Codes wiederholt statt mit neuen Codes; im Admin gibt es „Gespeicherte Codes erneut senden“, ohne bisherige Codes zu entwerten. (A588)
- Eventkarten aus Seitencache/CDN laden den aktuellen Kaufzustand (`GET /checkout/live`, no-store): Mengenlimits, ausverkaufte Ticketarten, „Nur noch … Plätze“, Badges, Zahlungsarten und Warteliste werden nach dem Laden umgeschaltet. (A456)
- Nach einer AGB-Änderung lädt die Kaufseite die aktuellen Rechtstexte per `/checkout/options` direkt in die Karte und lässt sie erneut bestätigen, statt wegen der gecachten Seite dauerhaft mit 409 abzulehnen. (A457)
- Zeitabhängige Preise (Preisstufen, Abendkasse) erscheinen schon vor der Bestellung mit Regulärpreis auf der Karte; bei „Preis geändert“ wird auch der Preis neben der Ticketart aktualisiert. (A458)
- Stornoanfrage im Bestellportal funktioniert auch für angemeldete Käufer:innen (benutzerunabhängiger Formularnachweis statt WordPress-Nonce). (A459)
- Scanner-Kopplung und Hilfscode am Einlass hinter gemeinsamer IP (Venue-NAT) nicht mehr gedeckelt: je IP zählen nur Fehlversuche, Hilfscodes je Gerät bzw. Benutzer. (A460)
- Apple-Wallet-Webservice und Wallet-Links antworten bei Ratenlimit mit 429 und Retry-After statt 401/404; Limits je Seriennummer bzw. Gerät, je IP nur Fehlversuche. (A461)
- Wartelisten-Angebot, Abmeldung und „Jetzt bezahlen“ mit an den Link gebundenem Formularnachweis statt kurzlebigem Nonce; Weiterleitungen no-store. (A462)
- Warteliste mit Double-Opt-in: Eintragungen werden erst über den Bestätigungslink in der E-Mail wirksam, erhalten vorher keine Angebote und werden nach 48 Stunden (einstellbar) gelöscht; der Bestätigungszeitpunkt wird gespeichert (Schema 3.10.21). (A564)
- Wartelisten-Angebot und -Mails nennen Termin mit Zeitzone und Ort, die Angebotsseite zeigt Bedingungen und Verbraucherinformationen mit Zustimmung wie der Checkout, und Wartende werden über verschobene Termine oder einen neuen Ort informiert. (A565)
- Google Wallet erhält Ticketdaten erst, wenn die Käuferin den Speichern-Link benutzt; fehlende Objekte werden nie automatisch angelegt, ungültige oder gelöschte Tickets verlieren bei Google Barcode, Namen und Platz. (A566)
- Der Vorschlag für die Datenschutzerklärung nennt die eingestellten Löschfristen und formuliert abgeschaltete Fristen eigens. (A567)
- Jede Fassung von AGB und Verbraucherinformationen wird unveränderlich archiviert, die Bestellansicht zeigt den Text der akzeptierten Fassung, und die Ticketmail enthält sie über den neuen Platzhalter `{contract_texts}`. (A568)
- E-Mail-Felder sind typsicher: Listen oder Objekte statt Text führen nicht mehr zu einem anonymen Fatal Error; `/checkout` prüft `email` als Text. (A492)
- Gültige E-Mail-Adressen über 190 Zeichen werden bei Checkout, Übertragung, Zuweisung, Warteliste und Servicebuchung mit einer klaren Meldung abgelehnt statt bezahlter Bestellung ohne Tickets bzw. endlosem 503; nicht passende Datenbankwerte im Checkout sind ein endgültiger Fehler. (A493)
- Freitexte werden nach Zeichen statt Bytes gekürzt (`Text::limit`/`Text::cut`) – lange Texte mit Umlauten lassen sich wieder speichern. (A494)
- CSV-Exporte quoten nach RFC 4180 ohne Backslash-Escape, Formelzellen über `\"` sind ausgeschlossen; Buchhaltungs- und DATEV-Export nutzen denselben geschützten CSV-Schreiber (auch Sicherheitsaudit 14.11. #5). (A495)
- Personenwechsel über die Teilnehmerzuweisung ist bei offener Erstattung gesperrt; Stornierungsanfragen aus dem Bestellportal für übertragene Tickets werden abgelehnt und nie ausgezahlt. (A496)
- Namen mit „<“ oder „&“ erscheinen in Textmails, Betreff, CSV, Kalender, Ticket-PDF, Namensschildern und Wallet-Pässen als Klartext statt als `&lt;`/`&amp;`. (A497)
- Tests prüfen Eingaben gegen das Verhalten von echtem WordPress (portierte Bereinigungsfunktionen mit CI-Abgleich, Feldprüfung wie `wpdb`, Fuzzing aller öffentlichen Eingänge); ungültige Eingaben in optionalen E-Mail-Feldern werden jetzt abgelehnt statt still verworfen. (A498)
- Die öffentliche Teilnehmerzuweisung hat ein Eingabeschema, ein Rate-Limit je Client und Bestellung, kürzt Namen auf 190 Zeichen und lehnt Namen mit Web-Adresse ab. (A635)
- Scanner: Alte Codes übertragener, neu zugewiesener, erneut zugesendeter oder neu ausgestellter Tickets melden „Code ersetzt“ mit Grund und Zeitpunkt statt „Ticket nicht gefunden“; widerrufene Tickets nennen den Grund, Offline-Pakete kennzeichnen ersetzte Codes (Schema 3.10.20). (A543)
- Scanner: Manuelle Suche nach Bestellnummer sowie – je nach Berechtigung – Teilnehmername und E-Mail mit „Einchecken“ nach denselben Regeln wie ein Scan; die Offline-Scanner-App sucht offline die Namen im Prüfpaket. (A544)
- Scanner: Check-in-Zähler „Eingecheckt 120 / 300“ für den gewählten Termin im Web-Scanner und in der Offline-Scanner-App, offline mit Stand und noch nicht hochgeladenen Einlässen. (A546)
- Scanner: „Rückgängig“ 30 Sekunden nach einem Einlass mit Pflicht-Grund im Web-Scanner und in der Offline-Scanner-App; die Rücknahme verlangt jetzt auch im Admin und in der App-API einen Grund. (A547)
- Offline-Scanner: Ergebnis im Vollbild mit Uhrzeit und Kurzcode, nach einer Haltezeit automatisch zurück auf „Bereit“, größere Schrift. (A548)
- Offline-Scanner: Als API-Adresse werden aus Link, Kopplungs-QR und Speicher nur noch die App-API der eigenen Website übernommen. (A649)
- Zugriffsbereiche des Personals (Spielorte/Veranstalter) gelten jetzt je Site eines Netzwerks; das Update übernimmt den bisherigen Bereich auf jede Site der Person (Schema 3.10.19), der Kassen-Hinweis wird je Site ausgeblendet. (A470)
- Event-Team, Einlassbereiche und Terminzuweisungen nehmen nur noch Mitglieder der eigenen Site auf, für Veranstaltungsleitungen nur Personen mit Pass-Captain-Rolle; unbekannte, fremde und rollenlose Konten erhalten dieselbe Meldung. (A471, A636)
- Web-Scanner: Offline-Warteschlange sowie Einlassbereich- und Terminauswahl werden je Site gespeichert – auf Subdirectory-Netzwerken gehen keine Offline-Scans mehr an die falsche Site. (A472)
- Offline-Scanner-App: eigener Datenbestand und eigene Kopplung je Site; der Start vom Homescreen öffnet die zuletzt genutzte Site. (A473)
- Google Wallet: Klassen- und Objekt-IDs bleiben nach einem Domainwechsel gleich, Aktualisierungen nutzen die gespeicherten IDs, nicht gefundene Objekte werden protokolliert. (A475)
- Event-Detailseite zeigt Termine seitenweise (50, Start beim nächsten Termin) mit einer einzigen Umsatzabfrage; Terminauswahlen sind auf 200 Termine begrenzt und lassen sich mit „Termine ab“ verschieben. (A476)
- Teilnehmer- und No-Show-Listen werden blockweise gelesen und als CSV bzw. Druckansicht gestreamt – auch bei Großevents ohne hohen Speicherbedarf. (A477)
- Gründe, Notizen, Referenzen und Audit-Details werden zeichenweise statt byteweise gekürzt; eine CI-Prüfung (`tests/utf8-substr-check.php`) verhindert Rückfälle. (A478)
- Neue Cross-Site-Tests für Subdirectory-Netzwerke: Zugriffsbereich, Personalzuweisung und Scanner-Speicher. (A479)
- Mitgliedspässe: Gleichzeitige Scans überschreiten das Nutzungskontingent nicht mehr – der Pass wird vor dem Zählen gesperrt. (A480)
- Mitgliedspässe: Kasse und Support können Pässe nur noch beantragen; eine zweite Person mit dem Freikarten-Recht gibt frei, erst dann wird der Code versendet; neue Admin-Seite „Mitgliedspässe“. (A481)
- Mitgliedspässe: Die Einlösung ist an einen stattfindenden Termin am selben Tag gebunden, beachtet Einlasszeit und Kapazität sowie ein optionales Kontingent und erscheint in der Einlassstatistik. (A482)
- Käuferlimit: Plus-Adressen aller Domains (name+1@…) und Gmail-Punkte zählen als ein Käufer (abschaltbar), bestehende Zählungen werden übernommen. (A484)
- Saalplan: Rollstuhlplätze werden nicht mehr automatisch vergeben; neue Begleitplätze nur zusammen mit einem Rollstuhlplatz. (A485)
- Saalplan: Die Verfügbarkeit sitzplatzgebundener Ticketarten richtet sich nach den freien passenden Plätzen, mit Warnung beim Verknüpfen. (A486)
- Saalplan: Eine neue Version ohne zugeordneten Bereich stellt verknüpfte Termine nicht mehr still um, sondern bricht mit Hinweis auf die betroffenen Ticketarten ab. (A487)
- Kasse: Erstattungen von Kassenbestellungen aus der Verwaltung werden als offene Kassen-Rückzahlung vorgemerkt und erst bei der Auszahlung in die Schicht der auszahlenden Kassierkraft gebucht. (A488)
- Kasse: Zählung und Schichtabschluss warten auf laufende Verkäufe; ein Verkauf in eine gezählte oder geschlossene Schicht wird ohne Tickets storniert. (A489)
- Veranstalter-Abrechnung: Provision, Ticketgebühr und Erstattungsregel gelten ab dem Änderungszeitpunkt, frühere Verkäufe und ihre Korrekturen behalten die alten Konditionen (Schema 3.10.18). (A491)
- Wird ein Einzeltermin verschoben, wandern die Verkaufszeiträume seiner Ticketarten um denselben Abstand in Ortszeit mit (abwählbar); Zeiträume, die danach vorbei wären oder erst nach dem Termin begännen, bleiben unverändert und werden in der Speichermeldung genannt. (A555)
- Serien: „Verkaufsstart N Tage vor Beginn“ und Verkaufsfenster nach Vorlage rechnen in Ortstagen und behalten über die Zeitumstellung ihre Uhrzeit. (A556)
- Erinnerungen nennen in `{hours}` die tatsächliche Restzeit beim Versand statt der Erinnerungsstufe; die Einplanung erfasst alle fälligen Termine statt nur der ersten 200. (A558)
- Der Checkout ist an den angezeigten Terminstand gebunden: Wurde der Termin inzwischen verschoben oder der Preis geändert, wird die Bestellung mit dem neuen Stand abgelehnt (409), das Formular zeigt ihn an und erneutes Absenden bestätigt ihn. (A560)
- REST `/events` liefert Zeiten zusätzlich als ISO 8601 mit Zeitzonen-Offset, Phase und Revision des Termins sowie je Ticketart Verkaufsstatus, Verkaufsfenster und Verfügbarkeit; die Antwort wird nicht zwischengespeichert (Vertrag public 1.1). (A561)
- Zentraler Lebenszyklus für Termine (`Occurrence::phase()`, Aktion `wpcap_occurrence_phase_changed`): Beginn, Ende und Absage werden gemeldet, die Eventliste wird aus Seiten-Caches entfernt und die Warteliste sofort geschlossen; Apple-Wallet-Ablauf und Offline-Scanner-Terminliste nutzen das effektive Terminende. (A562)
- Blöcke „Pass Captain Events“ und „Pass Captain Kalender“ übernehmen auf der Website eigene CSS-Klassen, Ausrichtung (weit/volle Breite), Abstände und `theme.json`-Blockstile. (A449)
- Bestell- und Wartelistenformular senden ohne JavaScript nichts mehr (POST-Formular, Absenden erst nach Laden des Skripts) – E-Mail-Adresse und Teilnehmerdaten landen nie in der URL. (A450)
- Die Vorschau der Blöcke im Editor ist nicht mehr bedienbar: kein versehentliches Bestellen oder Wegnavigieren aus dem Editor. (A451)
- Kalender: Monatsnavigation funktioniert in Widgets, Template-Teilen und Archiven, und mehrere Kalender auf einer Seite blättern unabhängig voneinander. (A452)
- Events-Block: Die Auswahl listet alle Events, auch neben Events mit sehr vielen Terminen; eine nicht mehr verfügbare gespeicherte Auswahl wird als solche angezeigt statt als „Alle Events“. (A453)
- Editor-Skripte der Blöcke werden nach einem Update zuverlässig neu geladen (Inhalts-Hash als Version, CI-Prüfung). (A454)
- Automatische Tests für alle Blöcke: Blockvalidierung, Rendern in Widgets/Template-Teilen und Editor-Skripte im Iframe-Editor. (A455)
- Zwei Eventkarten desselben Termins auf einer Seite haben eigene Ids; der Rechnungs-Schalter und die Fehlermeldungen wirken nur noch in der eigenen Karte, der Kalender-Anker steht an der ersten Karte. (A308)
- Der Checkout zeigt „Vielen Dank“ nur noch bei einer gültigen Bestellantwort; bei einer Cache-, Firewall- oder Proxy-Seite bleibt der Bestellschlüssel erhalten, erneutes Absenden legt keine zweite Bestellung an. (A309)
- Zurück von Stripe/PayPal/Mollie (auch aus dem bfcache oder nach Neuladen) zeigt die offene Bestellung mit „Zahlung fortsetzen“; unverändertes erneutes Absenden setzt dieselbe Bestellung fort statt eine zweite anzulegen. (A310)
- Web-Scanner und Offline-Scanner: Doppeltes Antippen von „Kamera starten“ öffnet keinen zweiten, unsichtbar weiterlaufenden Kamera-Stream mehr. (A316)
- Wartelisten-Angebot: Annehmen und Ablehnen leiten auf eine Ergebnisseite mit Bestellnummer und Zahlungsstand; Doppelklick, Neuladen oder der erneut geöffnete Mail-Link zeigen diese Seite statt „Angebot nicht verfügbar“. (A317)
- Nach der Zahlung kehren Käufer:innen auf die Kaufseite zurück statt auf die Startseite; der Hinweis nennt die Bestellnummer und bietet nach einem Abbruch „Zahlung fortsetzen“ oder „Bestellung stornieren“ (Plätze sofort frei). (A554)
- Der Checkout schickt die angezeigten Preise mit; ist ein Preis inzwischen gestiegen (z. B. Frühbucher abgelaufen), wird die Bestellung mit Hinweis auf den neuen Preis abgelehnt statt still teurer berechnet. (A576)
- Eine verspätete Zahlung für eine abgelaufene Bestellung mit Gutschein stellt erst Tickets aus, wenn der Gutschein erneut reserviert werden konnte; sonst wird die Bestellung zur Prüfung markiert (bezahlt, ohne Tickets). Die Bestellansicht weist auf den freigegebenen Gutschein hin. (A632)
- Offene Überweisungsbestellungen halten zusammen höchstens einen einstellbaren Anteil (Standard 50 %, mindestens 10 Tickets) je Ticketart und Termin; geprüft atomar beim Checkout. (A634)
- Kasse: Ein erneut abgesendeter Verkauf prüft den Eventbereich der Person erneut, auch über die alte Route `/pos/sales`. (A637)
- Öffentlicher Checkout: Idempotenzschlüssel brauchen mindestens 16 Zeichen, ein Wiederholversuch mit anderer Zahlart wird abgelehnt, und Aufräum-Stornos treffen nur unbezahlte Bestellungen. (A639)
- Externe Erstattungen und Rückbuchungen einer anderen Zahlung derselben Bestellung (z. B. einer Doppelzahlung) werden nicht mehr auf die Bestellung gebucht und widerrufen keine Tickets. (A640)
- PayPal zieht eine freigegebene Zahlung nur noch für eine bekannte, noch zahlbare Bestellung ein; Stripe-Sitzungen werden nur über `metadata.wpcap_order` zugeordnet. (A641)
- Scheitert der Start einer Zahlung, sehen Käufer:innen nur eine allgemeine Meldung mit Fehler-ID und den Code `wpcap_payment_start_failed`; Anbieterfehler stehen bereinigt im Support-Protokoll und im Audit-Log. (A642)
- Erstattungen prüfen Berechtigung und Zwei-Faktor-Pflicht vor einem wiederholten Idempotenzschlüssel; neue Option „Vier-Augen-Prinzip auch für Freigebende“ (Standard aus). (A643)
- Wallet-Pässe werden aktualisiert, wenn sich Ort, Ende oder Einlass eines Termins ändern; der Einlass steht im Pass. (A559)
- Wallet-Pässe folgen Änderungen von Event- und Ticketartnamen sowie Korrekturen des Teilnehmernamens im Admin und im Bestellportal. (A582)
- Google Wallet: Die Ticketmail enthält einen kurzen Link; Klasse und Objekte werden erst beim Klick angelegt, der Speichern-Link enthält nur noch Objekt-IDs (unter 1.800 Zeichen). (A583)
- Wallet: Nach einer Salt- bzw. Schlüsseländerung werden Pässe nicht mehr ungültig, sondern behalten ihren Stand; Site Health und ein Admin-Hinweis warnen und empfehlen `WPCAP_WALLET_ENCRYPTION_KEY`. (A584)
- Apple Wallet: Ein abgelaufenes Zertifikat gilt als nicht konfiguriert; 30 Tage vor Ablauf warnen Site Health und ein Admin-Hinweis. (A585)
- Ticketcodes werden verschlüsselt gespeichert (Schema 3.10.17, abschaltbar per `wpcap_store_ticket_codes`): Wiederholte PDF-Downloads im Bestellportal machen keine Codes mehr ungültig; neuer Admin-Knopf „Ticket-PDF herunterladen“ je Bestellung oder Ticket. (A586)
- Erinnerungen gehen in der Sprache der Bestellung (eigene Texte je Sprache möglich) und enthalten mit `{ticket_links}` die persönlichen Ticketlinks. (A587)
- Große Bestellungen: Ticket-PDFs werden zu je 50 Tickets geteilt und nur bis 7 MB angehängt; ab 11 Tickets gibt es „Alle hinzufügen“-Links für Apple und Google Wallet statt einer Datei je Ticket. (A580)
- Ticketmail: PDF, Rechnung, Kalenderdatei und Wallet-Texte erscheinen in der Sprache der Bestellung; Wallet-Pässe werden auch später in dieser Sprache gebaut. (A474)
- Nach einem Ticketumtausch erhält das neue Ticket eigene Wallet-Pässe; die Umtauschmail enthält die Wallet-Links. (A490)
- Checkliste „Erste Schritte“: Die Links zu Zahlung, Rechtstexten und Rechnungssteller öffnen den passenden Einstellungs-Reiter; eine unvollständige Überweisung führt direkt in ihren Unterbereich. (A290)
- Checkliste „Erste Schritte“: WooCommerce zählt nur noch als Zahlungsweg, wenn ein veröffentlichtes Produkt mit einer aktiven Ticketart verknüpft ist. (A291)
- Rechtstexte: Der Checklisten-Schritt ist erst mit „Zustimmung im Checkout verlangen“ erledigt; eine verlangte Zustimmung ohne AGB-Text löst Warnungen in Checkliste, Reiter „Rechtstexte“ und beim Speichern aus. (A289)
- Checkliste „Erste Schritte“: neuer Schritt „Verkaufsseite anlegen“ (Entwurf mit `[wpcap_events]` per Knopfdruck, „Auf Website ansehen“); der Einlass-Schritt nennt Web-Scanner und Offline-Scanner. (A292)
- Verkaufsliste: Termine vor Verkaufsbeginn zeigen „Vorverkauf ab …“ statt zu verschwinden, eine leere Liste zeigt einen Hinweis; Eventseite und Veröffentlichen nennen fehlenden Termin, fehlende Ticketart, Verkaufszeitraum und fehlende Zahlungsart. (A293)
- Ohne verfügbare Zahlungsart zeigt die Eventkarte „Online-Verkauf derzeit nicht möglich“ statt eines Formulars, das erst beim Absenden scheitert; Administratoren sehen „Keine verfügbare Zahlungsart für …“. (A294)
- Stripe/PayPal: Die Einstellungen listen die nötigen Webhook-Ereignisse, nennen fehlende Zugangsdaten mit deutschen Feldnamen und bieten „Verbindung testen“ (nur lesend). (A295)
- Käufer-Mails: neue Einstellungen „Absendername“ (Standard: Rechnungssteller bzw. Website-Name) und „Antwort an“ (Standard: Administrator-E-Mail). (A296)
- Admin-Formulare (Event, Eventdaten, Termin, Ticketart) behalten nach einem Fehler die Eingaben, die Meldung nennt das betroffene Feld, und das Feld wird markiert. (A297)
- Parallele Wiederholungen eines Scans mit demselben Idempotenzschlüssel buchen nur noch einen Einlass; Offline-Uploads des Web-Scanners tragen immer einen Schlüssel. (A589)
- „Check-in zurücknehmen“ ist für erstattete Tickets und Tickets stornierter Bestellungen gesperrt, beachtet die Bereichsbindung von Personal und Gerät (Admin, App, Einlass-Engine) und protokolliert Gerät und Bereich; erstattete Tickets lassen nie mehr ein. (A590, A633)
- Gästelisten-„Angekommen“ checkt per Ticket-ID ein und lässt die gemailten Ticketcodes unverändert, auch wenn der Einlass abgelehnt wird; Einlassbereich wählbar. (A591)
- Offline-Uploads: Quelle nie vom Client, zurückdatierte Einlässe immer zur Prüfung erfasst, Kapazitätsausnahme nur für gekoppelte Geräte, Geräte ohne Events scannen nichts; der Web-Check-in übernimmt `source` nicht mehr vom Client. (A592, A647)
- Ab Einlassbeginn oder wenn der Code in einem Offline-Paket steckt, können Käufer:innen keine neuen Ticketcodes mehr erzeugen (erneut senden, PDF, Übertragung, Teilnehmerwechsel; Filter `wpcap_self_service_rotation_lock`). (A593)
- Regressionstests für parallele Wiederholungen, Client-Zeit und -Quelle sowie Statuswechsel außerhalb des Check-ins. (A594)
- Offline-Scanner und Web-Scanner behalten Scans, die der Server wegen eines vorübergehenden Fehlers nicht buchen konnte, und laden sie erneut hoch (`retryable`). (A345)
- Neues Support-Protokoll (Betrieb → Support-Protokoll): Fehler, Warnungen und Hinweise mit Fehler-ID, Bezug zu Bestellung/Ticket/Termin, geschwärzt, Rotation nach 30 Tagen; Debug-Modus per `WPCAP_DEBUG` oder Einstellung (endet nach 24 Stunden; Schema 3.10.16). (A327)
- Unerwartete Checkout-Fehler werden mit Klasse, Datei:Zeile und Datenbankfehler protokolliert; Käufer:innen erhalten eine Fehler-ID statt einer stummen Absage. (A322)
- Abgelehnte und abgestürzte Zahlungs-Webhooks werden je Anbieter gezählt und sofort im Website-Zustand gemeldet (Absturz kritisch, mit Fehler-ID). (A323)
- Zahlungsstart-Fehler zeigen Käufer:innen einen neutralen Hinweis mit Fehler-ID; der Text des Anbieters steht im Support-Protokoll, bei Häufung erscheint ein Admin-Hinweis. (A324)
- Dead Letters nennen den echten Grund (Fehler des Handlers, `wp_mail`-Fehler, fehlende Vorlage) statt eines immer gleichen Texts. (A325)
- Fehlgeschlagene Jobs und E-Mails zeigen in der Betriebsübersicht ihre Bestellung, ihr Ticket und ihren Termin mit Link. (A326)
- Bestellungen haben einen Abschnitt „Verlauf“ mit Audit, Zahlungsereignissen, E-Mails, Hintergrundjobs und Support-Protokoll; jede Plugin-Mail wird protokolliert. (A328)
- Neuer Abschnitt „Pass Captain – Support“ im Website-Zustand als Support-Export (Version, Schema, Module, Zahlungsarten mit Modus, WooCommerce, Währungen, Sprache, Reservierungen, letzte Fehler). (A329)
- „Tickets erneut senden“ im Admin lässt an andere Personen übertragene Tickets standardmäßig unverändert; sie werden nur bei bewusster Auswahl neu ausgestellt. Wiederholungsversand, WooCommerce-Bestellaktion und Voucher-Captain-Neuversand behalten diesen Umfang. (A335)
- Bestellungen sind im Admin über Rechnungs- und Teilnehmernamen auffindbar; die Bestellliste zeigt die kurze Bestellnummer „AB12-CD34“ wie in den Mails. (A336)
- Bezahlte Bestellungen ohne Tickets („Prüfung nötig“) sind im Admin sichtbar: Zähler am Menüpunkt Bestellungen, Hinweis mit Link auf die gefilterte Liste, Bestell-Link in der Alarm-Mail. (A337)
- Tickets lassen sich im Admin von Hand einchecken (gleiche Regeln wie ein Scan, mit Einlassbereich); die neue Seite „Web-Scanner“ verlinkt den Browser-Scanner bzw. legt die Scanner-Seite an. (A338)
- Umbuchen auf einen anderen Termin direkt aus der Ticketliste und über eine Ticketsuche auf der Umtausch-Seite. (A339)
- Erstatten zeigt Betrag und Erstattungsweg vorab und in der Bestätigung; „Alle offenen Tickets“ sperrt die Einzelauswahl sichtbar statt sie still zu überstimmen. (A340)
- Wartelisten-Admin mit Spalte „Termin“ und verständlichen Bezeichnungen „Platz in der Warteschlange“ und „Anmeldung Nr.“. (A341)
- Die Admin-Ticketsuche findet die angezeigte Bestellnummer „AB12-CD34“, den Hilfscode und den vollständigen Ticketcode. (A545)
- Theme-Overrides der Eventkarte im früheren Ordner `wp-pass-captain/` werden weiter verwendet (neuer Ordner `pass-captain/` hat Vorrang), mit Veraltet-Meldung und ausblendbarem Admin-Hinweis. (A659)
- Ausstehende Folgeläufe der alten Action-Scheduler-Gruppe `wp-pass-captain` werden erkannt (kein Doppeleintrag), einmalig in die Gruppe `pass-captain` übernommen und beim Deaktivieren mit entfernt. (A660)
- Neuer CI-Wächtertest `tests/license-guard.php`: schlägt bei Lizenz- oder Updater-Code in ausgelieferten Dateien des Kern-Plugins fehl. (A661)
- Neues Funktionsinventar `docs/FREE-PREMIUM.md`: alle ausgelieferten Module und Bereiche als „Kern, dauerhaft frei“, per Konsistenzprüfung gegen die Modulregistrierung abgesichert. (A662)
- Dokumentation (EXTENSIONS.md, Roadmap §49, RELEASE.md) auf „Kern frei, Premium im separaten Add-on“ umgestellt. (A665)
- Die noch nicht angebundenen Fiskal-Adapter (fiskaltrust, efsta) sind bis zum Fiskalisierungskern nicht mehr im Plugin-ZIP und werden in readme.txt nicht mehr als Modul beworben. (A666)
- Ein dauerhaft fehlschlagendes Datenbank-Update läuft nicht mehr bei jedem Aufruf erneut: Wartezeit ab 5 Minuten mit Verdopplung bis 6 Stunden, Knopf „jetzt erneut versuchen“, `wp pass-captain upgrade`; nach fehlgeschlagener Erstinstallation werden keine Cron-Ereignisse eingeplant. (A466)
- Nach einem Rollback auf eine ältere Version und erneutem Update werden die Kontingentzähler neu aufgebaut; bis zur Prüfung entscheiden wieder die Summenabfragen – kein Überverkauf durch Verkäufe der alten Version. (A464)
- Das Standard-Einlassfenster bleibt für bestehende Installationen (bis 1.4.0) ausgeschaltet, ein Admin-Hinweis erklärt die neue Einstellung. (A465)
- Die Deinstallation löscht Optionen über die Options-API und leert den Objekt-Cache (mit Redis/Memcached blieb sonst z. B. die Schemaversion stehen). (A467)
- Der Neuaufbau der Kontingentzähler beim Upgrade ist ein fortsetzbarer Job mit Cursor (höchstens 5 s im Upgrade-Request, Rest per Cron). (A468)
- `wp pass-captain secrets rollback-config` gibt die verschlüsselt gespeicherten Zugangsdaten als wp-config-Konstanten aus, damit Zahlungsarten nach einem Rollback auf 1.4.0 eingerichtet bleiben. (A463)
- Der Rollback-Drill prüft Zugangsdaten, Kontingentzähler nach Verkäufen im Altcode, Einstellungen und Standardwerte und sichert nur noch die Tabellen der eigenen Test-Installation. (A469)
- Checkout: Die Ansage der Teilnehmerblöcke liest bei Mengenänderungen nicht mehr das ganze Formular vor; stattdessen ein kurzer Hinweis „Weiter unten: Angaben für N Teilnehmende.“ (A311)
- Saalplan: Der Tastaturfokus bleibt bei der Aktualisierung alle 30 Sekunden erhalten und springt nach „Entfernen“ zum nächsten Platz. (A312)
- Kasse: Termin- und Kassenauswahl laden die Seite nicht mehr bei jeder Pfeiltaste neu; gewählt wird mit „Anzeigen“. (A314)
- Dunkle Themes: Kalender, Fehlertexte und Hinweise sind auf dunklem Grund lesbar (Farben paarweise, mindestens 4,5:1, optional dunkler Farbsatz über `.wpcap-dark`). (A318)
- Offline-Scanner: Statusmarken „offline“, OK und Warnung mit ausreichendem Kontrast (mindestens 4,5:1). (A319)
- Checkout: Teilnehmerangaben gehen beim Korrigieren der Ticketmenge nicht mehr verloren. (A549)
- Checkout: Teilnehmerblöcke sind nach jeder Mengenänderung fortlaufend nummeriert. (A550)
- Checkout: Fehlermeldungen zu Teilnehmerfeldern nennen die gemeinte Teilnehmer:in, und Serverfehler markieren genau das betroffene Feld. (A551)
- Checkout: Hinweis „Meintest du …?“ bei vertippter E-Mail-Domain, einmaliger Halt vor dem Absenden und Hinweis auf der Bestätigung, falls die Adresse falsch ist (Filter `wpcap_checkout_email_domains`). (A553)
- Stripe wird nur noch angeboten, wenn der Secret Key zum eingestellten Modus (Test/Live) passt; Webhooks des anderen Modus bleiben wirkungslos, und der Testmodus ist im Admin und im Checkout („– Testmodus“) gekennzeichnet. Bestehende Installationen mit Live-Schlüssel und unverändertem Standard werden beim Upgrade auf „Live“ gestellt (Schema 3.10.15). (A274)
- Bezahlte Bestellungen ohne Tickets werden automatisch nachbearbeitet: Ein stündlicher Lauf plant für sie einen erneuten Ausstellungsversuch ein, auch wenn der Prozess nach dem Verbuchen der Zahlung abgebrochen ist. (A343)
- WooCommerce: Scheitern Zahlungsbestätigung oder Erstattung an einer ausgelasteten Datenbank, werden sie per Hintergrundjob automatisch wiederholt statt nur notiert. (A344)
- PayPal: Nach der Rückkehr heißt es nur noch „es wurde nichts abgebucht“, wenn PayPal die Zahlung eindeutig abgelehnt hat; bei unklarem Ausgang erscheint „wird noch verarbeitet“. (A349)
- Mollie: Klarna und Riverty werden nicht mehr angeboten; eine dennoch autorisierte Zahlung wird für zahlbare Bestellungen automatisch eingezogen statt nie abgeschlossen zu werden. (A351)
- Bestellungen „Bezahlt, ohne Kontingent“ lassen sich auf der Bestellseite über den Zahlungsanbieter erstatten oder als „Extern erstattet“ abschließen (mit Begründung, Recht „Erstattungen freigeben“). (A358)
- Wird einer Person eine Rolle entzogen oder wird sie gelöscht, nimmt Pass Captain automatisch Einlass-Teamzuweisungen, die per Zuweisung vergebene Einlassberechtigung, Scanner-Geräte, offene Kopplungscodes und Einlassbereiche zurück; eine verbleibende Rolle behält ihre Rechte (Filter `wpcap_revoke_access_on_role_change`). (A277)
- Entwickler-API-Keys funktionieren nur noch, solange der Inhaber existiert und das Recht seines Event-Team-Eintrags weiterhin hat; bei Rollenentzug oder Löschung werden seine Keys widerrufen (auch Sicherheitsaudit 14.11. #6). (A278)
- Falsche Buchungstokens der Service-Selbstbedienung sperren nur noch den ratenden Client (10 je Stunde) und eine Buchung höchstens kurz (50 Fehlversuche in 10 Minuten) statt die echten Kund:innen eine Stunde auszusperren. (A279)
- IPv6-Clients zählen in Ratenlimits je /64-Netz, bei Checkout, Holds und Warteliste zusätzlich je /48-Netz, damit Adresswechsel die Limits nicht mehr umgehen. (A280)
- Hilfscode-Eingaben prüfen zuerst die Grenzen je Benutzer und Client; die Website-Grenze verbrauchen nur unbekannte Codes, sodass ein einzelnes Scanner-Konto die Hilfscode-Eingabe der anderen nicht mehr sperrt. (A281)
- Die letzten Zähler auf Transient-Basis (Überweisungsbestellungen je Client, Entwickler-API-Ratenlimit, Partner-API-Sperre, Partnercode-Fehlversuche) zählen jetzt atomar über das gemeinsame Ratenlimit. (A288)
- Service-Zeitfenster sind jetzt für Kund:innen buchbar: Shortcode `[wpcap_service_booking event="ID"]` bzw. Block „Pass Captain Terminbuchung“ mit Wochenansicht und freien Plätzen. Kostenpflichtige Termine laufen über den normalen Checkout, kostenlose werden sofort bestätigt; danach gibt es einen persönlichen Link zum Umbuchen und Stornieren. Die Admin-Seite „Service-Zeitfenster“ zeigt den Shortcode und „Auf der Website ansehen“. (A268)
- Automatische Erstattungen bei Termin-, Event- und Pass-Absagen laufen als Systemerstattung – unabhängig von Rechten und Zwei-Faktor-Anmeldung der absagenden Person und auch für bereits eingelassene Tickets; gescheiterte Erstattungen werden als Hintergrundjob mit demselben Schlüssel wiederholt. (A354)
- Pass-Absagen lesen die Ticket-IDs je Bestellung einzeln; große Bestellungen werden nicht mehr wegen einer abgeschnittenen ID-Liste falsch oder gar nicht erstattet. (A436)
- Termine lassen sich nur noch über die Bestätigungsseite mit Folgen, Grund und Bestätigung absagen; das Statusfeld im Terminformular entfällt. (A266)
- „Event absagen“ führt auf eine eigene Bestätigungsseite (Folgen, Grund, Pflicht-Checkbox) statt auf eine Browser-Rückfrage. (A267)
- Abgesagte Termine können nicht mehr auf „Geplant“ zurückgesetzt werden; der Server lehnt das mit einer klaren Meldung ab. (A356)
- Termin- und Eventabsagen sowie Verschiebungsmitteilungen laufen als wiederaufnehmbare Hintergrundläufe in Stapeln, mit Erstattungen als Jobs, Mails über die Warteschlange und Fortschrittsanzeige; ein zweiter Klick setzt eine unterbrochene Absage fort, jede Person erhält ihre Mitteilung genau einmal (Schema 3.10.13). (A428)
- Wartelisteneinträge abgesagter, begonnener oder nicht mehr verkaufter Termine werden geschlossen (optional mit Mail „Warteliste beendet“); das Bestellportal zeigt nur noch Einträge buchbarer Termine. (A557)
- Mehrtägige Termine: zentrale Definition von „läuft/vorbei“ (Ende bzw. Beginn + einstellbare Standarddauer, Vorgabe 6 h). Ein Verkaufsende nach Beginn verlängert den Online-Verkauf bis höchstens Terminende; Eventliste, REST `/events` und die Terminlisten von Web-Scanner und App zeigen laufende Termine bis zu ihrem Ende. (A535)
- Warteliste: Angebote auch für Ticketarten mit Pflicht-Teilnehmerfeldern; die Angaben werden auf der Angebotsseite erfasst und bei der Annahme geprüft. (A536)
- Warteliste: Ein nicht anbietbarer Eintrag (Käuferlimit, Anzahl über dem Höchstwert, Teilnehmerdaten) blockiert die Schlange nicht mehr, sondern wird mit Grund als „Nicht anbietbar“ markiert und kann wieder aufgenommen werden; das Käuferlimit wird schon beim Eintragen geprüft (Schema 3.10.14). (A563)
- Volle Erstattung im Anbieter-Dashboard: Schlägt das Verbuchen fehl, bleibt die Bestellung erstattet und der Anbieter stellt das Ereignis erneut zu, bis Erstattungszeile, Betrag und Rechnungskorrektur gebucht sind. (A264)
- Stripe: Das Ereignis einer vollen Erstattung, die Pass Captain selbst gerade auszahlt, bucht keine zusätzliche Erstattung über 0 € mit Rechnungskorrektur mehr. (A346)
- Stripe: Teilerstattungen aus dem Dashboard werden kumulativ abgeglichen (Summe aller externen Erstattungen minus bereits gebucht) statt über die „neueste Erstattung“ – keine doppelten oder fehlenden Buchungen mehr. (A347)
- Erstattungen: Zeitüberschreitung, Verbindungsfehler, 5xx oder 409 beim Zahlungsanbieter gelten als „Ausgang unklar“ statt „fehlgeschlagen“; die Erstattung wird über das Merkmal `wpcap_refund` per Webhook oder Abgleich-Job geklärt und nie doppelt ausgezahlt. (A272)
- Erstattungen mit unklarem Ausgang erscheinen unter Betrieb und in der Bestellung und lassen sich per Anbieter-Abfrage oder manueller Bestätigung (mit Begründung, protokolliert) klären. (A350)
- Beim Zahlungsanbieter ausstehende Erstattungen werden nachverfolgt; scheitert eine verbuchte Erstattung nachträglich, wird sie storniert (Betrag, Storno-Beleg zur Rechnungskorrektur, Admin-Mail) und kann erneut ausgezahlt werden (Schema 3.10.11). (A348)
- Die Kapazitätsprüfung im Checkout liest nur noch Reservierungen, die noch zählen (Index `counted_type`, Schema 3.10.12), statt der ganzen Reservierungshistorie unter den Kapazitätssperren (bei 500.000 Reservierungen ≈ 300 ms → 0,6 ms je Prüfung). (A429)
- Der Kapazitäts-Abgleich alle fünf Minuten nutzt nur noch Indexbereiche und eine gruppierte Summe statt doppelter korrelierter SUMs (14,9 s → 62 ms je Lauf bei 500.000 Reservierungen) und findet jetzt auch alte, noch zählende Reservierungen. (A430)
- Abgeschlossene Reservierungen, erledigte und verworfene Jobs, Dead Letters, abgelaufene Sitzplatz-Holds und verarbeitete Zahlungsereignisse werden stündlich nach 30/30/180/30/90 Tagen blockweise gelöscht (Filter `wpcap_privacy_retention_days`, `wpcap_cleanup_budget`). (A444)
- Der Stapeldruck mit neuen Ticketcodes läuft als Hintergrundjob in Blöcken und schreibt eine private Datei; die neuen Codes gelten erst ab dem ersten Herunterladen. Abbruch, Zeitüberschreitung oder Speichermangel machen kein Ticket mehr ungültig. (A427)
- Beim Stapeldruck mit neuen Codes behalten übertragene oder anderen Teilnehmenden zugeordnete Tickets ihren gültigen Code; sie werden ohne QR-Code gedruckt. (A630)
- Ticketseite, Bestellportal, Kundenrechnung (HTML/PDF), Wartelisten-Angebot und -Abmeldung, Zahlungsrückkehr und die Web-Scanner-Seite werden nie mehr von Seitencaches oder CDNs gespeichert – auch nicht ihre 404-Antworten (`DONOTCACHEPAGE`, `nocache_headers()`, Action `wpcap_page_no_store`). (A283, A508)
- Seiten mit Event-Liste oder Kalender werden bei Änderungen an Events, Terminen, Ticketarten, Preisen, Verkaufszeiträumen und Rechtstexten, bei „ausverkauft“/„wieder verfügbar“ sowie zu Verkaufsstart und -ende automatisch aus dem Seitencache entfernt: neue Action `wpcap_purge_page_cache` mit Adaptern für WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Cloudflare (APO) und optional Varnish. (A446)
- Betriebsdoku „Seitencache und CDN“ mit Ausschlussregeln je Produkt, nie zu cachenden Pfaden und WAF-/Bot-Schutz-Ausnahmen für Zahlungs-Webhooks und Apple Wallet. (A509)
- Eine verspätete Zahlung prüft Termin- und Eventstatus sowie das Terminende erneut; abgesagte oder vergangene Termine führen zu „Bezahlt, ohne Kontingent“ mit Admin-Hinweis statt zu gültigen Tickets. Terminabsagen stornieren auch abgelaufene Bestellungen; optional (`late_payment_auto_refund`) werden solche Zahlungen automatisch erstattet. (A273)
- Rabattcodes werden bei verspäteter Zahlung unter Sperre erneut eingelöst; ist das Limit inzwischen ausgeschöpft, wird die Bestellung zur Klärung markiert. (A353)
- Das Käuferlimit wird bei verspäteter Zahlung erneut unter Sperre geprüft und lässt sich nicht mehr über abgelaufene Reservierungen umgehen (Filter `wpcap_order_recovering`). (A483)
- Die DSGVO-Löschung storniert offene Bestellungen vorher und stellt Bestellungen in Zahlungsklärung bis zur Klärung zurück; spätere Zahlungen erzeugen keine Tickets für Platzhalteradressen. (A355)
- Die Stripe-Zahlungssitzung endet mit der Reservierung (mindestens 31 Minuten); verzögerte Zahlarten (Stripe SEPA u. a., Mollie-Überweisung) verlängern die Reservierung wie eine Überweisung bzw. werden nur bei ausreichender Reservierung angeboten. (A577)
- Eine freigegebene Erstattung eines inzwischen umgetauschten Tickets wird nicht mehr ausgeführt (kein Geld zurück bei weiter gültigem neuem Ticket, keine Überbuchung). Umtausch, Widerruf und Übertragung sind gesperrt, solange für das Ticket eine Erstattung offen ist. (A537)
- Widerruf mit Platzfreigabe und anschließende Erstattung geben den Platz nicht mehr doppelt frei; jedes Ticket gibt seinen Platz höchstens einmal frei (`wpcap_tickets.capacity_released`, Schema 3.10.10). (A538)
- Nach Widerruf oder Umtausch erstattet eine Erstattung nur noch den Wert der erstatteten Tickets; die Bestellung wird „erstattet“, sobald kein gültiges oder erstattbares Ticket übrig ist. (A357)
- Der Umtausch eines Tickets gibt nur noch eine Einheit an das Kontingent zurück statt aller Einheiten der Bestellung. (A575)
- Teilerstattungen und Admin-Widerruf mit Platzfreigabe informieren Warteliste und Kontingente (`wpcap_capacity_released` mit Ticketart und Menge). (A578)
- Neue Diagnose `capacity_ticket_drift` (`wp pass-captain doctor`) erkennt Bestellzeilen, die weniger Plätze zählen als gültige Tickets. (A579)
- Überweisung wird nur noch angeboten, wenn Empfänger und IBAN oder ein Zahlungshinweis hinterlegt sind. Neue Installationen starten ohne aktive Zahlungsart; bestehende behalten „Überweisung“ (Schema 3.10.9) und erhalten bei fehlender Bankverbindung einen Admin-Hinweis und den Status „(nicht vollständig konfiguriert)“. Käufer:innen sehen nie mehr eine Überweisungsaufforderung ohne Empfänger und IBAN; Bezeichnung im Checkout jetzt „Überweisung“. (A265)
- readme.txt beschreibt im Abschnitt „External services“ alle externen Dienste (Zahlungsanbieter, SMS, Apple/Google Wallet, Fiskalisierung, Webhooks) mit Zweck, Zeitpunkt, übertragenen Daten und Links zu Bedingungen und Datenschutz; eine Konsistenzprüfung verlangt für jeden Host im Code einen Eintrag. (A269)
- tweetnacl wird mit lesbarem Quelltext (`nacl-fast.js`) und Quellangabe samt Prüfsummen (`SOURCE.txt`) ausgeliefert. (A270)
- Speichern eines Veranstalters löst keine Event-Verknüpfungen mehr: Das Formular übermittelt nur hinzugefügte und gelöste Events (seitenweise Liste, Suche nach Name/ID, ID-Feld), statt alle Verknüpfungen zu löschen und nur die ersten 500 neu anzulegen. Event-Auswahllisten im Admin behalten das gewählte Event auch jenseits von 500 Einträgen und weisen auf gekürzte Listen hin. (A447)
- Umhängen eines Events zu einem anderen Veranstalter führt nicht mehr zur doppelten Auszahlung: Abgerechnete Positionen sind plattformweit gesperrt, Event-Zuordnungen werden mit Gültigkeitszeitraum gespeichert, Verkäufe gehören dem Veranstalter zum Bestellzeitpunkt, Erstattungen und Korrekturen dem, der den Verkauf abgerechnet hat. Das Update (Schema 3.10.8) übernimmt bestehende Zuordnungen und markiert bereits doppelt abgerechnete Positionen im Audit-Log. (A448)
- Web-Scanner leert das Codefeld bei jedem Scan sofort, verwirft Scans während der Sperrzeit sichtbar und sendet Eingaben mit mehreren Ticket-Codes nie; der Server lehnt solche Eingaben mit `wpcap_ticket_ambiguous` ab, statt den ersten Code einzulassen (auch PWA). (A271)
- Nur JSON-Fehler des Plugins (`wpcap_*`) erscheinen als „KEIN EINLASS“; WAF-, Proxy- oder Login-Seiten, Antworten ohne JSON und fremde Fehlercodes sind in Web-Scanner und PWA eine gelbe Systemwarnung. Eine HTML-Antwort löscht keine Offline-Scans mehr. (A315)
- Offline-Scanner-PWA: Ein im Bild bleibender Code wird nicht nach 3 s erneut geprüft (gleitendes Doppelscan-Fenster wie im Web-Scanner). (A539)
- Web-Scanner: Anfragen brechen nach 5 s ab; danach folgt die Offline-Entscheidung statt eines hängenden „…“. (A540)
- Web-Scanner: Ein nach Netzabbruch offline gespeicherter Scan übernimmt den Idempotenzschlüssel des Online-Versuchs – kein Schein-Konflikt beim Abgleich. (A541)
- Web-Scanner offline: Ein auf diesem Gerät bereits offline eingelassener Code wird rot mit Uhrzeit abgewiesen statt erneut „Nach Sichtprüfung einlassen“ anzubieten. (A542)

### Auditbefunde Runde 3 (Arbeitsliste A001–)
- Umtausch im Bestellportal: übertragene Tickets kann nur noch der Veranstalter umtauschen (kein Umtausch samt Erstattung durch den ursprünglichen Käufer); das Umtausch-Formular funktioniert für alle Tickets einer Bestellung.
- Verspätete Zahlung: vorübergehende Datenbank-Sperren gelten nicht mehr als „ausverkauft“ – der Webhook wird erneut zugestellt. Bestellungen „Prüfung nötig“ haben den Knopf „Zuteilung erneut versuchen“.
- Pässe mit „bei Absage widerrufen“: Käufer:innen erhalten die Absage-Mail; ohne automatische Erstattung wird die Erstattung als offen gemeldet und bleibt in der Bestellung möglich.
- Umtausch und Buchhaltung (Schema 3.8.1): bezahlte Bestellpositionen bleiben für Rechnung, Berichte, Abrechnungen, Z-Bericht und Affiliate-Provision unverändert (`orig_*`-Spalten); keine Doppelzählung umgezogener Einheiten, Zusatzbestellungen ohne zweite Ticketgebühr. Der Ticketwert ändert sich nicht durch andere Umtausche derselben Ticketart und berücksichtigt Mehrzweckgutscheine. Umtausch zwischen unterschiedlichen Steuersätzen ist gesperrt.
- Überweisung: Die Reservierung endet spätestens mit Beginn des Termins (Checkout, Warteliste, Umtausch-Aufpreis). Neue Einstellung „Überweisung nur bis … Stunden vor Beginn anbieten“ (`manual_cutoff_hours`, Standard 0); innerhalb dieser Frist wird die Überweisung auf der Eventkarte ausgeblendet und im Checkout abgelehnt.
- Eventkarte: Ausverkaufte Ticketarten bieten ein Wartelisten-Formular (Ticketart, Anzahl, E-Mail) an; ist ein Termin ganz ausverkauft, wird statt des Bestellformulars nur „ausverkauft“ und die Warteliste gezeigt. Abschaltbar mit dem Filter `wpcap_waitlist_public_enabled`.
- Rabattcodes für ein bestimmtes Event oder eine Ticketart mindern nur die passenden Positionen; die Umsatzsteuer wird je Position auf den eigenen rabattierten Betrag berechnet und der Positionsrabatt gespeichert (`wpcap_order_items.discount_minor`). Rechnung, Berichte, Abrechnungen und Affiliate-Provision nutzen dieselbe Aufteilung (`OrderLines::after_discount()`), auch die bisher unterschiedliche Rundung der Rechnung entfällt.
- Kassen-Umtausch: Der Aufpreis wird als Kassenverkauf mit Belegnummer in der offenen Schicht gebucht (Z-Bericht, spätere Erstattungen als Auszahlung); bar/Karte nur mit geöffneter Schicht.
- Käuferlimit gilt auch beim Umtausch im Bestellportal und über die Partner-API (Umtausch durch Personal bleibt ausgenommen).
- Freikarten-Rechte: Kulanz-Umtausch ohne Aufpreis und die Kassen-Zahlart „Freikarte“ verlangen `wpcap_approve_comps` (wie die Gästelisten-Freigabe); ohne das Recht wird die Option nicht angezeigt.
- Blindzählung: Kassierer sehen Soll und Differenz erst nach dem Schließen, zählen nur einmal (Nachzählung durch Kassenverwaltung/Finanz) und schließen ohne Differenzgrund; der Z-Bericht listet alle Zählungen mit Differenz.
- Bestellportal: Die Teilnehmer-E-Mail ist optional; eine Namens- oder Angabenkorrektur ohne E-Mail behält den Ticketcode.
- Bestellportal und Bestellmail zeigen die bestellten Positionen (Event, Ticketart, Termin, Menge, Preis nach Rabatt); neuer Platzhalter `{order_items}` in der Vorlage „Bestellzugang“.
- Portal-Umtausch mit Aufpreis per Überweisung: Betrag, IBAN, Verwendungszweck und Frist werden direkt angezeigt; nach dem Neuladen steht beim Ticket „Umtausch wartet auf Zahlung“ statt eines neuen Formulars.
- Admin-Bestellansicht: Positionen wie bezahlt (passend zur Rechnung) mit Spalte „Umgebucht (Stk.)“ für Umtausche; Zusatzbestellungen tragen einen Hinweis mit Link zur ursprünglichen Bestellung.
- Terminauswahl an Kasse, Druck, Gästeliste und Abholliste: nur geplante Termine veröffentlichter Events; laufender/nächster Termin ist vorausgewählt, vergangene stehen danach mit „(vergangen)“ (an der Kasse gar nicht).
- Rabattcodes: neue Admin-Seite „Rabattcodes“ (anlegen, Nutzung und Rabattsumme, aktivieren/deaktivieren; Event-Zuständige für ihre Events, Codes für alle Events nur Administration). Das Feld „Rabattcode“ erscheint nur bei aktivem Code; die Preisvorschau zeigt den Rabatt (`POST /campaigns/preview`, unverbindlich, ratenbegrenzt). Behoben: die REST-Antwort beim Anlegen lieferte die ID des Audit-Eintrags statt des Codes.
- Ticket-Erstattung bei zielgerichtetem Rabatt: Betrag und USt aus dem Positionsbrutto nach dem eigenen Rabatt (ein Rabatt für ein anderes Event mindert die Erstattung nicht mehr).
- Erstattungsmail je Zahlart: bei Überweisung „erstatten wir … per Überweisung“ mit Bitte um IBAN bzw. maskierter IBAN, bar an der Kasse „ausgezahlt“, Karte „zurückgebucht“; kurze Bestellnummer statt 32-stelliger ID (auch als Überschrift der Admin-Bestellansicht). Neue Platzhalter `{refund_intro}`, `{refund_method_note}`. Käufer:innen mit Überweisung können im Bestellportal eine Bankverbindung für Erstattungen hinterlegen (IBAN-Prüfsumme; Admin-Anzeige; Datenschutz-Export/-Löschung; Schema 3.8.2).
- Terminabsage: eigene Bestätigungsseite mit den Folgen (Tickets, unbezahlte/bezahlte Bestellungen, automatische Erstattung, WooCommerce, Empfänger, Pässe), Pflichtgrund (im Protokoll) und Bestätigung; danach eine Zusammenfassung (storniert, widerrufen, erstattet, offen, benachrichtigt). Die Auslastung eines Termins zählt Pässe mit, die an einem anderen Termin verankert sind (Filter `wpcap_occurrence_sold`, Vorschau-Filter `wpcap_occurrence_cancellation_preview`).
- Betragserstattung bei Mehrzweckgutschein: Die USt wird anteilig zur vollen Gegenleistung (Bar + Gutschein) berechnet, nicht gegen den Barrest.
- Rechnung nach Teilerstattung: Erstattungen, die vor der Rechnung abgeschlossen wurden, erhalten beim Ausstellen ihre Rechnungskorrektur.
- Wiederverkäufer-Abrechnung: keine USt auf die Vergütung bei Kleinunternehmer-Wiederverkäufern (mit Hinweis) und Reverse Charge bei Sitz in einem anderen EU-Staat; der Beleg ist standardmäßig die Rechnung des Wiederverkäufers (Filter `wpcap_reseller_statement_document` für eine Gutschrift der Plattform – Entscheidung offen, siehe Steuerfragen).
- Serien-Umtausch nur zwischen Events desselben Verkäufers (Plattform bzw. derselbe Veranstalter mit demselben Verkaufsmodell).
- Veranstalter-Abrechnung: Ein eingelöster Einzweckgutschein mindert die Auszahlung nicht mehr – sein Wert steht als eigene Zeile „Einzweckgutschein (Forderung gegen den Gutscheinverkäufer)“ in der Abrechnung.
- Datenschutz Käuferlimit: Käuferschlüssel als HMAC mit Seitengeheimnis (einmalige Migration beim Schema-Update baut die Zeilen aus den Bestellungen neu auf); eigener Datenschutz-Export und -Löschung; Löschung nach Ende des letzten Termins + Aufbewahrungsfrist der Teilnehmerdaten. Neuer Filter `wpcap_privacy_retention_steps` für Löschschritte von Modulen.
- Datenschutz Gästelisten: Name, E-Mail, Firma und Notiz (auch abgelehnter/stornierter Einträge) werden nach Terminende + Teilnehmer-Aufbewahrungsfrist geleert; Anzahl und Status bleiben. Hinweis im Notizfeld: keine Gesundheitsdaten.
- Datenschutz Warteliste: E-Mail und Hash eines Eintrags werden 30 Tage nach dem Termin entfernt (Schritt `waitlist` in `wpcap_privacy_retention_days`); Frist in Datenschutztext und OPERATIONS.md.
- Partner-API: Wiederverkäufer lesen Bestellungen, Tickets und Check-ins nur ihrer eigenen Verkäufe (keine E-Mails anderer Käufer der verknüpften Events).
- Datenschutztext-Vorschlag: beschreibt die zurückgestellte Löschung bei kommenden Terminen richtig und enthält – sobald genutzt – Abschnitte zu Gästelisten (Art. 14), Wartelisten-Angeboten, Höchstmenge pro Person, Abendkasse/Rechnung auf Firma, Ticketumtausch (inkl. Bankverbindung) und Partnerverkauf; OPERATIONS.md entsprechend korrigiert.
- Gutschein-Schnittstelle: Das dokumentierte Feld `treatment` (`payment`/`single`/`discount`) wird ausgewertet; Doku der Standardregel korrigiert.
- Hooks: `wpcap_order_paid` feuert auch bei kostenlosen Bestellungen vor der Ticketausgabe (danach `wpcap_tickets_issued`); das Webhook-Ereignis `order.paid` meldet als `ticket_count` mindestens die bestellte Menge.
- Leistung: Eventliste mit allen Modulen braucht eine konstante Zahl von Abfragen (Request-Caches für Pässe, Kontingente, Käuferlimits und Saalplan-Verknüpfungen; neue Aktion `wpcap_availability_flushed` setzt sie nach Schreibzugriffen zurück) – im Test 14 statt 117 Abfragen bei 12 Terminen.
- Leistung im Checkout: Pässe und Serienpools prüfen die Kapazität über die gepflegten Zähler (`CapacityCounters::used_for_types()`) statt über Summen aller Bestellungen; Partnerkontingente entfernen Nutzungszeilen beendeter Bestellungen.
- Zähler-Abgleich: Sperre gegen parallele Läufe, Zeitbudget, nur kürzlich geänderte Bestellungen und laufende/künftige Termine, Bestätigungsprüfung vor dem Alarm; `--all` für den vollständigen Abgleich.
- Release: Tag-Pushes und manuelle Läufe veröffentlichen nur Commits, die auf `main` liegen und deren CI-Lauf erfolgreich war (`build/release-guard.sh`, getestet in CI).
- Installation/Update: Nach `dbDelta` wird geprüft, ob alle Tabellen und Spalten existieren; sonst bleiben Versionsstand und Fingerprint unverändert, der Fehler wird protokolliert und Administratoren sehen einen Hinweis.
- Zahlungs-Webhooks: Ein Datenbankfehler beim Annehmen eines Ereignisses gilt nicht mehr als „Duplikat“, sondern führt zu 503 (der Anbieter stellt erneut zu).
- Ticketausgabe: Datenbankfehler beim Sperren, Anlegen, Teilnehmerdaten-Leeren oder COMMIT rollen die Ausgabe zurück (keine Ticketmail ohne Tickets, Teilnehmerdaten bleiben für den nächsten Versuch erhalten).
- Ticketmail: Scheitert der Versand, wird ein Wiederholungsjob (neue Codes, Backoff) geplant; die Bestellansicht zeigt den offenen bzw. endgültig gescheiterten Versand.
- Erstattungen: Datenbankschritte (Anlage, Freigabe, Start, Verbuchung, externe Erstattungen) werden geprüft; nach erfolgreicher Auszahlung wird zuerst die Provider-Referenz gespeichert und die Verbuchung in einer Transaktion abgeschlossen. Scheitert sie, bleibt die Erstattung „processing“ und ein Reparaturjob bucht sie ohne zweite Auszahlung nach; hängende Erstattungen ohne Referenz werden zur manuellen Prüfung markiert.
- Queue-Worker (Jobs, Nachrichtenqueue): Transaktionsstart, Claim-Update (genau eine Zeile) und COMMIT werden geprüft; ohne dauerhaften Claim läuft kein Handler bzw. wird keine Mail versendet (keine Doppelausführung).
- Sammel-Nachsendung: Codewechsel pro Bestellung serialisiert (Zeilensperre) und atomar (alle oder keiner, Rowcount geprüft); Rotations-Hooks erst nach dem COMMIT; scheitert der Versand, wird automatisch ein Wiederholungsjob geplant.
- Stornierung: jeder DB-Schritt (Transaktion, Reservierungen, Ticketwiderruf, Bestellstatus, COMMIT) wird geprüft; bei Fehler wird zurückgerollt, ein retrybarer Fehler geliefert (Zahlungs-Webhook wird erneut zugestellt, Partner-API, Gutschein-Widerruf und Gästeliste melden ihn weiter) und ein Wiederholungsjob geplant, der nur greift, solange sich der Zahlungsstatus nicht geändert hat.
- Als erstattet markieren (`mark_refunded`): in einer Transaktion mit Zeilensperre, jeder Schritt geprüft; bei Fehler Rollback, retrybarer Fehler (Webhook wird erneut zugestellt, Partner-API meldet ihn) und Reparaturjob, da das Geld bereits zurückgezahlt ist.
- Offline-Scanner (PWA): Oberfläche, Statusmeldungen, Fehler und Datumsformat sind übersetzbar (Katalog `i18n.js`, Deutsch/Englisch, offline verfügbar); die Sprache kommt aus dem WordPress-Benutzerprofil (`?lang=`), wird auf dem Gerät gemerkt, sonst Browsersprache; `html lang` wird korrekt gesetzt. Neuer CI-Test prüft Vollständigkeit der Kataloge und die Sprachwahl.
- Kassen-Storno, Partner-API-Storno und Umtausch erkennen Nutzung auch bei gültig gebliebenen Wiedereinlass-, Mehrfach- und Pass-Tickets (Check-in-Historie ohne Rücknahme, Einlassbuchungen) über das gemeinsame `Orders::used_ticket_ids()`.
- Mails an Platzhalteradressen (Kasse/Gästeliste/Partner ohne Käufer-E-Mail, reservierte `.invalid`-Domains) werden zentral im Mailer unterdrückt – auch wenn ein SMTP-Plugin `wp_mail()` ersetzt; für solche Bestellungen wird kein Ticket-PDF mehr erzeugt. Neuer Hook `wpcap_mail_suppressed`.
- Einlass: Ablehnungen wegen Einlasszeit, Tagesgültigkeit oder falschem Termin nennen den Termin, für den das Ticket gilt; der Web-Scanner bietet eine Terminauswahl (berechtigte Termine von gestern bis +7 Tage, auf dem Gerät gemerkt), die online und bei der Offline-Synchronisierung mitgeschickt wird.
- Events-Shortcode/-Block: Attribute `event`, `occurrence` und `limit` (`[wp_pass_captain_events event="12"]`); der Block bietet im Editor Auswahllisten für Event und Termin (mit Datum und ID, damit gleichnamige Events unterscheidbar sind).
- Zahlentexte mit Einheit (Stunden, Minuten, Tage, Tickets) verwenden Singular/Plural (`_n()`), z. B. „bis 1 Stunde vor Beginn“ statt „1 Stunden“; ein Test verhindert Rückfälle.
- Admin-Auswahllisten: gleichnamige Events werden mit erstem Termin und ID unterschieden („Konzert (12.05.2027 20:00 Europe/Vienna, #14)“), eindeutige Namen bleiben unverändert.
- Formulare ohne rohe IDs: Veranstalter-Preisliste und -Kontingente (Termin, Ticketart, Kontingent), Kasse „Nur für Event“ und Gästeliste „Freigabe nur durch“ bieten Auswahllisten; die Tabellen zeigen Namen statt „#ID“.
- Veranstalter speichern: fehlende Rechnungsangaben werden nur noch einmal (im Formular) angezeigt statt zusätzlich als Hinweis nach dem Speichern.
- Pass-Tageserstattungen (abgesagter Termin) weisen die USt zum Satz der Pass-Zeile aus statt zum Durchschnittssatz der Bestellung.
- Kassen-Freikarten (`pos_free`) gelten wie Gästelisten-Freikarten als Freikarten: nicht im Umsatz/verkauften Tickets, keine Ticketgebühr in Veranstalter-Abrechnungen (separat ausgewiesen).
- Kasse: Stornos/Erstattungen von Verkäufen „Rechnung“/„Freikarte“ ohne offene Schicht gehen nicht mehr verloren, sondern werden vorgemerkt und beim Öffnen der nächsten Schicht (gleiche Währung) ins Journal und damit in Z-Bericht und Grand Total gebucht.
- Rechnungsdatenpflicht ab Schwelle (Standard 250 €) gilt jetzt auch für Kassenverkäufe „Zahlung auf Rechnung“, die Partner-API (neues optionales Feld `billing`, sonst `422 wpcap_partner_billing_required`) und Aufpreis-Bestellungen beim Umtausch; Umtausch im Admin und im Bestellportal bietet dafür die einklappbaren Rechnungsfelder.
- Datenschutz: gespeicherte Partner-Ticketcodes werden beim Widerruf eines Tickets, bei der Anonymisierung der Bestellung (neue Action `wpcap_privacy_order_anonymized`) und 30 Tage nach Terminende (Löschlauf, Schritt `partner_ticket_codes`) gelöscht.
- Datenschutz-Export: Warteliste mit Event-/Terminname, Anzahl, Angebot und Angebotsbestellung; Tickets mit Umtauschverlauf (von/nach, Differenz, Gebühr, Status); Bestellungen mit Verkaufskanal des Veranstalters/Wiederverkäufers (Name, Kanal, Referenz).
- Gästelisten: Begleittickets tragen neutral „Begleitung“ statt „Begleitung von <Name des Gastes>“ (Filter `wpcap_guestlist_companion_name`).
- Kassenbelege (Verkauf, Storno/Auszahlung) nennen die Kassierkraft standardmäßig als „Bediener <Nr.>“; Initialen oder vollständiger Name sind in den Kasseneinstellungen wählbar (`boxoffice_receipt_cashier`). Z-Berichte (intern) behalten den Namen.
- Veranstalter-Kontakte: WordPress-Datenexport und -Löschung für Ansprechpersonen (Name, E-Mail, Telefon; abgeschlossene Abrechnungen bleiben als Beleg); Auszahlungsdaten werden im Formular nur maskiert angezeigt (leeres Feld = unverändert, eigene Löschoption).
- Kontingent im Admin erhöht (Termin oder Ticketart, auch Ticketart wieder aktiv): `wpcap_capacity_increased` wird sofort ausgelöst, Wartelisten-Angebote gehen ohne Warten auf den stündlichen Lauf raus.
- Cron: Deaktivierung und Deinstallation entfernen alle Plugin-Hooks aus einer zentralen Liste (`Plugin::cron_hooks()`, jetzt inkl. Webhook-Versand, Kapazitätsabgleich und Warteliste); die Warteliste läuft im gemeinsamen Zeitbudget und setzt bei Bedarf in einem Folgelauf fort.
- Kassen-Datenmigration läuft unter der Upgrade-Sperre des Installers (keine doppelte Migration paralleler Requests); offene Altschichten werden je in einer Transaktion übernommen, bei Fehlern im nächsten Lauf erneut.
- Vorübergehende Datenbankfehler (Deadlock, Lock-Timeout) liefern überall einheitlich HTTP 503 mit `retryable: true` über die gemeinsame Fehlerfabrik `Errors::busy()` (vorher teils ohne Status oder ohne `retryable`).
- Partner-API-Storno: „Veranstaltung hat begonnen“ berücksichtigt bei Pässen alle verknüpften Termine (nicht nur den Anker-Termin); neuer Filter `wpcap_order_started`.
- Umtausch im Bestellportal: Herabstufungen mit Erstattung werden bei Partner-, Kassen-, WooCommerce- und Freikarten-Bestellungen abgelehnt (Geld liegt nicht bei der Plattform) und an die Verkaufsstelle verwiesen; Filter `wpcap_exchange_platform_collected`.
- Hook-Referenz `docs/HOOKS.md` vollständig: 85 fehlende Hooks ergänzt (neue Abschnitte u. a. Kasse, Kontingente, Kapazitätszähler, Rechnungsdaten, WooCommerce, Umtausch, Einlass), Tabelle aller Cron-Hooks; ein Test gleicht Dokumentation und Code in beide Richtungen ab.
- Alte REST-Route `POST /pos/sales` (veraltet) bucht über das Kassenmodul: offene Schicht, lückenlose Belegnummer, Journal und Z-Bericht sowie die Regeln für Bar/Karte gelten auch dort.
- Nachsenden im Bestellportal erneuert nur die Codes der eigenen Tickets; übertragene Tickets behalten ihren Code (der Käufer kann den QR-Code der Empfängerin nicht mehr ungültig machen). Die Nachsendung durch das Team bleibt vollständig.
- Gästelisten: Vier-Augen-Prinzip für Freikarten – wer einen Eintrag beantragt, kann ihn nicht selbst freigeben (Administratoren ausgenommen; Einstellung `guestlist_four_eyes`, Standard an).
- Pässe mit zwei Vorstellungen am selben Tag: Der Scan bindet bevorzugt an eine Vorstellung, für die das Ticket noch nicht eingelassen wurde – die Abendvorstellung ist nach der Matinee nicht mehr fälschlich „Tageskontingent erreicht“.
- Kasse: Blindzählung wird erklärt – im Kassenformular (gilt für Kassierkräfte; Kassenverwaltung/Finanzrecht sehen den Soll-Bestand immer) und in der Schichtansicht für Vorgesetzte.
- Z-Bericht: unbekannte Berichtsnummer liefert „nicht gefunden“ (404) statt „keine Berechtigung“; Ausgabe-Funktionen prüfen auf fehlende Berichte (keine PHP-Warnungen).
- Umtausch-Abschluss sperrt Original- und Aufpreisbestellung (aufsteigend) vor Ticketarten und Terminen – gleiche Sperrreihenfolge wie Stornierung/Zählerabgleich, kein Deadlock bei gleichzeitiger Stornierung der Aufpreisbestellung.
- Kapazitätsprüfung mit Zählern zieht alle noch gezählten, aber nicht mehr gültigen Reservierungen ab (abgelaufen, freigegeben, umgewandelt – noch nicht synchronisiert), nicht nur abgelaufene „aktive“; kein falsches „ausverkauft“ im Grenzfall.
- Datenschutz-Löschung (auch sofort, Filter `wpcap_privacy_defer_upcoming`): widerrufene Tickets geben ihre Plätze frei (über `Orders::revoke_tickets()`: Kontingent, Zähler, `wpcap_capacity_released`).
- Module einzeln abschaltbar (Konstante `WPCAP_DISABLED_MODULES`, Filter `wpcap_module_enabled`): ein abgeschaltetes Modul registriert keine Hooks, Seiten und Aktionen; Tabellen und Einstellungen bleiben.
- Gästelisten: WordPress-Datenexport und -Löschung für Gäste (Name, E-Mail, Firma, Notiz; Zählwerte und Status bleiben für Berichte).
- Datenschutz-Löschung storniert laufende Wartelisten-Angebote (Platzhalter-Bestellung) sofort – der Platz geht an die nächste Person statt bis zum Ablauf blockiert zu sein.
- Umtausch übernimmt Antworten auf Zusatzfelder ins neue Ticket, soweit die Ziel-Ticketart dasselbe Feld (gleicher Schlüssel) abfragt.
- Neues Webhook-Ereignis `ticket.exchanged` (neues Ticket, vorheriges Ticket, Ticketarten, Kanal und Beträge des Umtauschs).
- Umtausch bei WooCommerce-Bestellungen: die Differenz wird als „in WooCommerce erstatten“ geführt (Notiz an der Woo-Bestellung, Hinweis in der Umtauschliste, Action `wpcap_exchange_refund_external`) statt als fehlgeschlagene Erstattung.
- Veranstalter-Abrechnung: selbst vereinnahmte Erlöse aus eigenen API-Verkäufen werden automatisch von der Auszahlung abgezogen (Gebühren fallen weiter an); negativer Saldo wird nie ausgezahlt, sondern in die nächste Abrechnung vorgetragen (oder per Einstellung sofort als Forderung ausgewiesen). Abrechnungsbeleg zeigt Zwischensumme, Abzug, Saldovortrag; die Wiederverkäufer-Aufstellung markiert diese Beträge als „wird verrechnet“.
- Hook `wpcap_reserve_capacity` erhält zusätzlich Käufer-E-Mail, Benutzer-ID und die Kanaloptionen (`internal`) im Kontext.
- Saalplan-Editor: alle Texte (Werkzeuge, Hilfe, Bühne, Kapazität, Farbe, Löschen …) übersetzbar; ein Test verhindert fest codierte Texte.
- Datenbank: Index auf `wallet_registrations.push_token` als Präfixindex (passt in 767 Byte für ältere MySQL/MariaDB); Anforderungen in `docs/OPERATIONS.md` dokumentiert, Test prüft alle Schemata.
- Doku: neues `docs/BETREIBERPFLICHTEN.md` mit Vorlagen für Betreiberpflichten (keine Rechtsberatung): Art.-14-Information für Gästelisten und Vernichtung der Abholliste, Rolle der Veranstalter/Wiederverkäufer, Kassen-Schichtdaten mit Mitbestimmung und Z-Bericht-Aufbewahrung, AV-Vertrag Fiskalanbieter, Auskunft ohne E-Mail, Verarbeitungsverzeichnis.
- Gästelisten-Löschlauf ersetzt nach der Frist auch die Gast-E-Mail an der Freikartenbestellung und leert Teilnehmernamen der Tickets.
- Erstattungsbelege heißen durchgängig „Rechnungskorrektur“ statt „Gutschrift“ (Export, Berichte, Bestellansicht, Mail); neues Standardpräfix `RK` für neue Installationen, bestehende behalten ihr Präfix (ohne gespeicherte Einstellung wird `GS` festgeschrieben); Selbstfakturierungs-Gutschriften (`GU`) unverändert.
- Rechnungskorrekturen schlüsseln Netto/USt/Brutto je Steuersatz auf (Druckansicht, PDF, Buchhaltungsexport mit einer Zeile je Satz); Aufteilung nach erstatteten Positionen, gespeichert in `wpcap_credit_notes.rates` (Schema 3.8.3), ältere Belege werden identisch berechnet.
- Umsatzbericht: Auswertung je Verkaufskanal (Kasse, Veranstalter/Wiederverkäufer-API, WooCommerce-Shop, Online) statt nur Kasse/Online; Filter `wpcap_report_channel`.
- Mehrzweckgutscheine: Berichte, Veranstalter- und Wiederverkäufer-Abrechnungen lesen Erstattungen aus dem gespeicherten Erstattungsplan (Bar- plus Gutscheinteil, volle USt) – eine voll erstattete Bestellung über 30,00 € mit 15,00 € Gutschein zeigt jetzt 0,00 € statt 15,00 € Restumsatz.
- Einzweckgutscheine: Veranstalter-Abrechnung nutzt den gespeicherten Gutscheinwert (`face_value_minor`) für Ticketdienst-Einlösungen (bisher 0,00 €) und bucht bei Erstattungen den zurückgegebenen Gutscheinteil als eigene Zeile „Einzweckgutschein zurückgegeben (Erstattung)“ zurück.
- Doku: docs/TESTING.md aktualisiert (Check-in-Deadlock als behoben mit aktuellen Messwerten, verpflichtender CI-Lastschritt, Testpräfixe für parallele Läufe).
- Neuer CI-Schritt `tests/consistency.php`: Plugin-Version, `WPCAP_VERSION`, readme (Stable tag, Requires, Changelog), composer und CHANGELOG müssen übereinstimmen, Doku-Links müssen existieren; readme-Changelog um 1.2.0–1.4.0 ergänzt.
- Termine haben ein optionales Feld „Einlass ab“ (Schema 3.8.4); Scanner und Check-in lehnen Tickets davor mit Hinweis auf die Einlasszeit ab (A092).
- Einlassbereiche haben eine optionale Kapazität; ist sie erreicht, meldet der Scanner `area_full` statt einzulassen; Filter `wpcap_admission_area_capacity` (A093).
- Ticketarten können „Ausweis prüfen“, ein Mindestalter und einen Hinweis für das Einlasspersonal tragen (Schema 3.8.5); der Scanner zeigt diese Hinweise beim Einlass deutlich an (A094).
- Neue No-Show-Liste pro Termin (gültige, nicht eingelassene Tickets) mit CSV-Export (A095).
- Kundenmails mit eingebautem Standardtext (alle Mail-Typen und Erinnerungen) werden jetzt als HTML-Mail im Layout versendet; die Textversion entspricht wortgleich der bisherigen Textmail. Eigene Vorlagen behalten ihr Format; der neue Filter `wpcap_mail_default_format` schaltet bei Bedarf auf reine Textmails zurück (A101).
- Ortszeiten, die es wegen der Zeitumstellung nicht gibt (z. B. 28.03.2027 02:30), werden im Termin- und Eventformular mit Hinweis auf die nächste gültige Zeit abgelehnt; doppelte Ortszeiten (z. B. 31.10.2027 02:30) verwenden die erste (Sommerzeit) und zeigen einen Hinweis. Wöchentliche Wiederholungen und der Seriengenerator melden betroffene Termine (A102).
- Hook-Namen vereinheitlicht: alle internen Hooks heißen `wpcap_*` (`wpcap_process_job`, `wpcap_email_template`, `wpcap_event_card_html`, `wpcap_currencies`); die alten Namen funktionieren weiter und werden als veraltet gemeldet. Nur die versionierten Plugin-Verträge `wp_pass_captain_*_v1` behalten ihren Namen. Shortcodes zusätzlich als `[wpcap_events]`, `[wpcap_scanner]`, `[wpcap_calendar]`; docs/HOOKS.md beschreibt die Namensregel (A104).
- Reproduzierbarer Release-Build: gleicher Commit ergibt ein byte-identisches ZIP (feste Dateizeiten aus `SOURCE_DATE_EPOCH`, einheitliche Rechte, sortierte Einträge, fester Autoloader-Suffix); `wp-pass-captain.zip.sha256` und `build-info.txt` werden mit veröffentlicht, CI prüft die Reproduzierbarkeit, docs/OPERATIONS.md beschreibt den Abgleich ZIP ↔ Commit (A112).
- Abrechnungsblatt pro Termin (Door Deal) unter Berichte: Druckansicht und CSV mit Verkäufen je Ticketart und Kanal, Freikarten getrennt, USt je Satz, Erstattungen, Kasse je Zahlungsart (Bar/Karte/Rechnung), Check-ins und No-Shows sowie Deal-Block (Fixbetrag, dann Anteil % vom Netto-Rest; Werte werden nicht gespeichert); Zugriff eventbezogen wie Berichte, jeder Abruf im Audit-Log (A097).
- Buchhaltungsexport rechnet Zeitraum und Belegdatum in der Zeitzone der Website statt UTC und enthält zusätzlich „Verkauf ohne Rechnung“ und „Erstattung ohne Rechnung“ (z. B. Kasse, Partner-API, automatische Rechnungen aus) je Steuersatz, sodass er mit dem Umsatzbericht abstimmt; Export-Link auf der Berichtsseite, Spalten in docs/OPERATIONS.md dokumentiert (A098).
- Upgrade, Code-Rollback und Restore gegen echtes WordPress/MariaDB erprobt (1.4.0 ↔ aktuell, `tests/restore/drill.sh`, Ergebnisse in docs/OPERATIONS.md). Behoben: nach einem Rollback und erneutem Upgrade liefen einmalige Migrationen erneut (z. B. wurde das Rechnungskorrektur-Präfix einer Neuinstallation auf „GS“ umgestellt); `wpcap_db_version_max` merkt sich jetzt die höchste je gelaufene Schemaversion (A113).
- Admin übersichtlicher: das Pass-Captain-Menü ist nach Bereichen gruppiert (Events & Programm, Verkauf, Einlass, Finanzen & Auswertung, Kommunikation, Einstellungen & Integrationen; URLs und Berechtigungen unverändert). Die Eventliste zeigt eine aus den Daten abgeleitete, pro Benutzer ausblendbare „Erste Schritte“-Checkliste (Zahlungsart, Rechtstexte, Rechnungssteller, Event, Termin, Ticketart, Veröffentlichung, Scanner-Gerät, Testbestellung) sowie je Event Verkauft/Kapazität mit Auslastung und Umsatz – ohne Abfrage pro Zeile (A088).
- Einstellungen in Reitern (Allgemein, Einlass, Zahlungsarten, Rechtstexte, Rechnungen); Speichern eines Reiters setzt andere Reiter nicht mehr zurück. Zahlungsarten mit Übersicht und eigenem Bereich je Anbieter (Überweisung, Stripe, PayPal; Mollie verlinkt). AGB und Verbraucherinformationen je Sprache (Polylang/WPML/Website-Sprache, Rückfall auf die Standardsprache); Checkout, `/checkout/options` und Mails (`{terms_text}`, `{consumer_info_text}`) verwenden die Sprache des Käufers, gespeichert wird die AGB-Version der angezeigten Fassung, veraltete Zustimmungen werden abgelehnt (A099).
- Zugangsdaten von Stripe, PayPal, Mollie, SMS (Twilio/HTTP) und Wallet werden nicht mehr im Klartext in `wpcap_settings` gespeichert, sondern authentifiziert verschlüsselt (sodium secretbox) in `wpcap_secrets`. Der Schlüssel kommt aus `WPCAP_SECRETS_KEY` (sonst aus den WordPress-Salts abgeleitet, mit Admin-Hinweis). Bestehende Werte werden automatisch migriert, Schlüsselwechsel über `WPCAP_SECRETS_KEY_PREVIOUS`. Bei nicht entschlüsselbaren Werten gilt der Dienst als nicht eingerichtet (fail closed); Formulare zeigen nur „endet auf …“ und „Gespeicherten Wert löschen“ (A191).
- Automatisierter Nachweis, dass gespeicherte Zugangsdaten weder in Optionen, Audit-Logs, Admin-HTML (alle Reiter), Website-Zustand/Diagnose, REST-Routen, Datenschutz-Export, Fehlermeldungen, PHP-Fehlerlog noch in Plugin-Tabellen im Klartext auftauchen; Schlüsselverlust und erneute Eingabe getestet (A192).
- Web-Scanner zeigt Ergebnisse wie die Offline-App: Vollbild in Grün/Rot, unterscheidbare Töne (abschaltbar) und Vibration, „Erstmals eingelassen“ bzw. „Bereits eingelassen um … (Bereich)“ sowie Ausweis-/Altershinweis. Jedes Ticket hat zusätzlich einen 8-stelligen Hilfscode (z. B. K7QM-4XWA) auf Ticketseite, PDF, Mail und Druck, den das Einlasspersonal eintippen kann, wenn der QR-Code nicht lesbar ist – nur online, gedrosselt (Filter `wpcap_help_code_rate_limits`), nur als Hash gespeichert und bei jeder Code-Erneuerung ersetzt (Schema 3.8.6) (A089).
- Eventkarte kompakter: Kopf mit Termin, Ort, Einlass, Preis „ab …“ und Verfügbarkeit; weitere Termine und mehr als vier Ticketarten einklappbar (ohne JS). Barrierefreier Mengen-Stepper (−/+, Ansage per aria-live, beachtet Höchstmengen und Käuferlimits). Badges „Nächster Termin“, „Ausverkauft“, „Wenige Plätze“, „Abgesagt“, „Verschoben“ und „Einlass ab …“ auf Karte und im Kalender; Schwellen per Filter `wpcap_event_card_thresholds` (A100).
- Regelmäßige Lasttests: wöchentlicher Workflow mit den Profilen default/heavy/big gegen MariaDB 11.4 und MySQL 8.4 sowie REST-Lasttest gegen ein frisches WordPress; Ergebnisse werden gegen Baselines (`tests/load/baselines/`) geprüft und als Artefakte aufbewahrt, Referenzwerte in docs/TESTING.md. Checkout-Fehler enthalten zusätzlich `data.reason` (z. B. `sold_out`) für sprachunabhängige Auswertung (A121).
- Offline-Scanner (PWA): automatisierte Installations-Abnahme in Chromium Desktop und Android-Emulation (Manifest, Installierbarkeit, Service Worker, Offline-Start, Cache-Wechsel; CI-Job `pwa`) und dokumentierte Geräte-/Browser-Matrix mit manuellem Abnahmeablauf in docs/OFFLINE-SCANNER.md (A120).
- Verbindliche Release-Gates (docs/RELEASE.md): neben den CI-Prüfungen veröffentlicht der Release-Workflow nur noch mit vollständigem Abnahmeprotokoll `docs/release-acceptance/v<Version>.md` (Installation/Upgrade/Restore, manuelle Mobil-/Desktop-Abnahme der kritischen Abläufe, fachliche Freigabe); Vorlage und Prüfskript `build/release-acceptance.sh` mit Tests (A126).
- Übersetzungen vollständig: alle 3234 Texte in Englisch, Spanisch, Französisch, Italienisch und Niederländisch übersetzt bzw. geprüft (630 veraltete Vorschläge einzeln korrigiert, 471 neue Texte); Platzhalter in Hinweistexten übersetzbar, gemischte „Sie“-Anrede in Kassen- und Abrechnungstexten auf „du“ vereinheitlicht, Kontext für mehrdeutige Begriffe. Neue CI-Prüfung `tests/i18n-check.php` (POT passt zum Code, keine fehlenden/fuzzy Einträge, Platzhalter und HTML-Tags identisch, `.mo` aktuell); Ablauf und Glossar in docs/TRANSLATIONS.md (A107).
- Neue CI-Matrix gegen echtes WordPress 6.4, 6.8 und die neueste Version: Aktivierung, Admin-Seiten, REST, Deaktivierung, erneute Aktivierung, Deinstallation mit/ohne Datenlöschung, keine PHP-Meldungen des Plugins, dazu Upgrade-/Rollback-/Restore-Drill gegen das vorherige Release (A125).
- WooCommerce-Adapter gegen echt installiertes WooCommerce getestet (8.2.0 und neueste Version, jeweils HPOS und klassischer Bestellspeicher): Reservierung, Ticketausgabe, Teilerstattung, Stornierung, Ausverkauft (A127).
- Rabattcodes: einstellbare Stapelregeln – höchstens 1–5 Codes je Bestellung (Standard 3), „nacheinander anwenden“ (bisheriges Verhalten) oder „nur den günstigsten Code“, einzelne Codes als „nicht kombinierbar“ anlegbar (Schema 3.9.1); Filter `wpcap_campaign_stacking` (A117).
- Datenschutz-Fristen je Datenart einstellbar (Einstellungen → Allgemein → Datenschutz): Pseudonymisierung unbezahlter Bestellungen, Nachrichteninhalte und -protokoll, Personal an Check-ins, Audit-Log, Warteliste, Partner-Ticketcodes. Neu und optional (Standard: nie): Löschfrist für Rechnungen, Rechnungskorrekturen und Kassenschichten (Z-Bericht, Buchungen, Belege) in Jahren ab Ende des Ausstellungsjahres; Käuferdaten der betroffenen Bestellungen werden pseudonymisiert, eine Rechnung bleibt, solange eine Korrektur noch in der Frist ist; Filter `wpcap_financial_retention_years` (A123).
- Bestellportal-Selbstbedienung: Tickets einzeln oder gesammelt als PDF herunterladen (nur gültige, eigene Tickets; stornierte/erstattete gekennzeichnet; ohne gespeicherten Code mit neuem Ticketcode nach Bestätigung). Stornierungen mit optionalem Grund anfragen – die Anfrage landet immer in „Erstattungen zur Freigabe“ (Einstellung „Stornierungsanfragen im Bestellportal bis … Stunden vor Beginn“, Standard aus; nicht für Freikarten und WooCommerce). Neue E-Mails „Stornierungsanfrage eingegangen/abgelehnt“; Ticketseite mit „Neuen Bestelllink per E-Mail senden“ (A118).
- Audit-Log für Personendaten: Ansichten von Bestellungen, Tickets, Teilnehmer- und Gästelisten werden entprellt protokolliert (je Person, Objekt und Filter höchstens alle 10 Minuten), alle Exporte und Drucklisten mit Personendaten sowie REST-Lesezugriffe ebenso; Läufe der WordPress-Datenschutzwerkzeuge, Änderungen an Plugin-Rollen und -Rechten sowie Korrekturen (neu: Teilnehmerkorrektur im Ticket-Admin, Grund Pflicht) mit maskiertem Vorher/Nachher-Diff; neue Seite „Audit-Log“ mit Filtern (A122, A148).
- Teilnehmerfelder können als Einwilligung markiert werden: Erteilung und Widerruf werden mit Textfassung (Hash), Sprache, Zeitpunkt und Quelle protokolliert (E-Mail/IP nur als Hash). Selbst-Widerruf in Bestellportal und Ticketseite, Widerruf durch die Verwaltung in der Bestellung, CSV-Export je Event (mit Audit-Eintrag), Hooks `wpcap_consent_granted`/`wpcap_consent_withdrawn`; Datenexport enthält den Verlauf, die Löschung entfernt den Personenbezug und behält den minimalen Nachweis (A116).
- Schema 3.9.5 (Stornierungsanfragen, Einwilligungsnachweise).
- Getrennte Änderungsverläufe je Termin und je Serie: Kapazität, Beginn/Ende, Ort, Status, Ticketpreise und Verkaufsfenster sowie Preisregeln, Kontingente und Seriendefinition werden lesbar als „alt → neu“ mit Zeitpunkt und Benutzer im Admin angezeigt (A114).
- Terminserien lassen sich nach dem Anlegen bearbeiten (Regel, Ausnahmen, Uhrzeit, Standardkontingent, Verkaufsfenster, Saalplan) – mit Vorschau der hinzugefügten, geänderten und entfernten Termine. Änderungen gelten nur für künftige Termine, ausverkaufte und abgesagte nur auf ausdrücklichen Wunsch; Termine mit verkauften Tickets werden nie gelöscht, sondern markiert und können über „Termin absagen“ abgesagt werden. Preisregeln sind ebenfalls bearbeitbar; Hook `wpcap_series_updated`, Doku docs/SERIES.md, Schema 3.9.6 (A128).
- Erstattung bereits eingelassener Tickets nur noch mit ausdrücklicher Ausnahme: Grund und Freigabeberechtigung nötig, die Erstattung braucht dann immer eine zweite Freigabe, die Ausnahme steht im Erstattungsgrund; Terminabsagen (System) und die Kasse (eigene Ausnahmeprüfung) bleiben möglich. Ein rückgängig gemachter Check-in zählt nicht als Einlass (A131).
- Steuerprofile je Land und Steuerklasse gelten jetzt für Bestellungen: Leistungsort ist das Veranstaltungsland des Termins (Standard: Land des Rechnungsstellers); Ticketarten mit Steuerklasse verwenden den Satz des am Verkaufstag aktiven, geprüften Profils, sonst wie bisher ihren eigenen Satz. Jede Bestellposition speichert Quelle, Profil, Version und Land, die Bestellansicht zeigt sie an. Neue Seite „Steuerprüfung“ (Finanzen): Entwürfe, Aktivieren nur mit Finanzrechten nach dem Vier-Augen-Prinzip, keine überlappenden aktiven Profile, Außerkraftsetzen, alles im Audit-Log; WooCommerce verwendet weiter seine eigenen Steuern. Schema 3.9.7 (A115).
- Zugriff und Datenschutz: Mitarbeitende lassen sich im WordPress-Profil zusätzlich auf Spielorte und/oder Veranstalter beschränken; Bestellungen, Tickets, Check-ins, Kasse, Exporte, Berichte und REST-Routen prüfen das termingenau. Personenbezogene Daten werden je Rolle minimiert (Einlass: Teilnehmername nur mit „Teilnehmernamen beim Scannen anzeigen“, Finanzen: Käufer- und Rechnungsdaten ohne Teilnehmerangaben, Berichte: nur Summen; Filter `wpcap_pii_can_view`), Matrix in docs/SECURITY.md. Ein Test stellt sicher, dass jede REST-Route eine Berechtigungsprüfung hat oder ausdrücklich öffentlich ist (A119, A146).
- MySQL: Eine vom Server zurückgerollte Transaktion (z. B. Deadlock-Opfer) wird jetzt auch unter MySQL erkannt (Savepoint-Prüfung; bisher nur MariaDB über `@@in_transaction`), sodass keine halben Bestellungen entstehen; Integrationstests laufen in CI zusätzlich gegen MySQL 8.4.
- MySQL 8: Beim Umtausch wurden Antworten auf Zusatzfelder nicht übernommen, weil die Abfrage den in MySQL reservierten Alias `of` verwendete (MariaDB erlaubt ihn); behoben, durch den neuen MySQL-CI-Lauf gefunden.
- Statische Prüfungen in CI: PHPCS mit WordPress Coding Standards (Sicherheit, Datenbank, i18n) und PHPCompatibility (PHP 8.2+) sowie `composer audit`; behoben: unbereinigte Request-Methode/Eingaben (neuer Helfer `Http::method()`), Token-Bereinigung, fehlende und falsch zugeordnete Übersetzerkommentare (A173, teilweise).
- JavaScript: ESLint über alle eigenen Skripte und Logiktests der Offline-App (Signaturprüfung der Prüfpakete, Geräte-/Formatbindung, Offline-Einlassentscheidung) mit `node:test`, beides in CI (A174).
- Neue Admin-Seite „Betrieb“: Hintergrundjobs, E-Mail-Warteschlange und Webhook-Zustellungen je Status, Rückstand und Wartezeit des ältesten fälligen Eintrags sowie alle endgültig fehlgeschlagenen Einträge (ohne Inhalte, nur Feldnamen und maskierte Empfänger); fehlgeschlagene Einträge einzeln oder gesammelt „erneut versuchen“ oder „verwerfen“, beides im Audit-Log (A163).
- Kalender: Jeder Termin hat eine Revision, die bei Änderungen an Beginn, Ende, Einlass, Ort, Veranstaltungsland oder Status steigt; die .ics-Dateien tragen sie als SEQUENCE (mit LAST-MODIFIED und DTSTAMP), sodass Kalenderprogramme Einträge aktualisieren; abgesagte Termine als STATUS:CANCELLED (mit Organizer-Adresse über `wpcap_calendar_organizer` als METHOD:CANCEL). Neue Mail „Termindetails geändert“ (`occurrence_changed`) bei Orts- oder Detailänderungen – bei Zeit und Ort genau eine kombinierte Mail, höchstens einmal je Revision, mit aktualisierter .ics; abschaltbar je Event; .ics-Ausgabe gegen RFC 5545 (Faltung, Escaping, CRLF) getestet (A140).
- Neue Seite „Entwickler-API“: Schlüssel auflisten, anlegen (einmalige Anzeige des Geheimnisses), Ablauf und Ratenlimit ändern, widerrufen – mit Audit-Log; Ratenlimit je Schlüssel (Standard 600/min, Filter `wpcap_developer_key_rate_limit`), feste Zuordnung Route → Scope, neuer Scope `slots.read`; Widerruf von Schlüsseln robuster (A161).
- Warteliste: Jede Wartelisten-Mail enthält einen persönlichen Link zum Austragen (Bestätigung per POST, kein Austragen schon beim Aufruf des Links, Rate-Limit, Audit); ein offenes Angebot wird dabei zurückgezogen und dem nächsten Eintrag angeboten. Austragen auch im Bestellportal. Annahme, Ablehnung, Austragen und Ablauf eines Angebots sind gegeneinander abgesichert (Sperren in einer Transaktion), damit ein Platz nie doppelt vergeben wird; neues Lasttest-Szenario `waitlist`; Schema 3.10.1 (A158).
- Entwickler-API: Ist das Ratenlimit eines API-Keys erreicht, antwortet die API jetzt mit 429 `wpcap_rate_limited` und `Retry-After` statt mit 401/403 (Befund aus der Übersetzungsprüfung); Übersetzungen (en, es, fr, it, nl) für alle neuen Texte ergänzt (A107).
- CI: Integrations- und Lasttests laufen jetzt über PHP 8.2–8.5 und MariaDB 10.6/10.11/11.4/11.8 sowie MySQL 8.0/8.4; freigegebene Versionen dokumentiert (docs/OPERATIONS.md) (A175, A176).
- CI: Das gebaute Release-ZIP wird je WordPress-Version in eine frische Installation installiert, aktiviert und deinstalliert; Entwicklungsdateien im ZIP lassen den Lauf scheitern (`tests/wp-matrix/zip-install.sh`) (A178). WooCommerce-Matrix (A177) und reproduzierbarer Build (A179) waren bereits umgesetzt und sind abgehakt.
- CI: Secret-Scan (`tests/secret-scan.php`) für alle Dateien im Repository und jede Datei im Release-ZIP, `npm audit` neben `composer audit`, Dependabot für Composer, npm und GitHub Actions (A195).
- Berichte und Buchhaltungsexport fachlich abgenommen: Erstattungen werden in Bericht, Abrechnungsblatt und Rechnungskorrektur identisch auf Positionen und Steuersätze verteilt (keine USt mehr auf 0-%-Positionen im Bericht); Umsatzbericht mit Aufstellung je Währung, „Netto nach Erstattung“ und rechnerischer Abstimmung; Buchhaltungsexport mit festen, dokumentierten Spalten, Lückenprüfung der Belegnummern und neuem Buchungsstapel im DATEV-Format (Kontenzuordnung über `wpcap_datev_accounts`, Standard SKR03) (A129).
- SMS: Segmentzählung (GSM-7 160/153, UCS-2 70/67), Kosten je Nachricht (Anbieterpreis oder Preis je Segment und Zielland), Monatsbudget mit Warnung und harter Grenze (zurückgehaltene SMS freigeben), Kostenbericht im Admin, signierte Zustellstatus- und Eingangs-Callbacks für den generischen HTTP-Anbieter (HMAC-SHA256), Einwilligungen mit Quelle und Widerrufsquelle sowie Sperrliste nach STOP (Aufhebung mit START); Schema 3.10.2 (A141).
- Zustandsautomaten für Bestellung, Ticket, Reservierung, Zahlung, Erstattung, Gutschrift, Warteliste, Job, Umtausch und Servicebuchung dokumentiert (docs/STATE-MACHINES.md) und zentral mit bedingten Updates erzwungen; doppelte und out-of-order Zahlungsereignisse (Erstattung vor Zahlung, Zahlung nach Storno/Ablauf, zweite Zahlung, Chargeback nach Erstattung) für Stripe, PayPal, Mollie und WooCommerce systematisch getestet; Chargebacks bei Stripe/PayPal werden verbucht, Mollie-Teilerstattungen nicht mehr doppelt (A184).
- Ratenlimits sind jetzt über alle PHP-Worker und Webserver atomar (Datenbankzähler `wpcap_rate_limits` bzw. `wp_cache_incr` mit persistentem Objekt-Cache) und gelten auch für Checkout, Servicebuchung, Warteliste und Sitzplätze; neue Einstellung „Vertrauenswürdige Proxys“: X-Forwarded-For/Forwarded (rechteste nicht vertrauenswürdige Adresse) oder CF-Connecting-IP werden nur von dort ausgewertet, sonst immer REMOTE_ADDR; Hosting-Empfehlungen in docs/SECURITY.md; Schema 3.10.3 (A186).
- Upgrade-Tests: Jeder frühere Stand (Tags v*, Stand vor der letzten Schemaänderung) wird mit dem echten dbDelta auf das aktuelle Schema gehoben und mit einer Neuinstallation verglichen; geprüft werden Datenerhalt, einmalige Migrationen, ein Abbruch mitten im Upgrade und zwei parallele Upgrade-Prozesse (CI). Behoben: Upgrades übernahmen geänderte Indizes nicht und ließen abgelöste Indizes stehen (`type_order`, `push_token` ohne Präfixlänge) – Schema 3.10.4 gleicht Indizes an (A181).
- Automatische Prüfung (CI), dass alle SQL-Abfragen Tabellen über `$wpdb` mit Site-Präfix ansprechen, kein `wp_` fest verdrahten und nicht direkt auf WooCommerce-Bestelltabellen zugreifen; Integrationstest mit zwei Sites nebeneinander. Behoben: Auf Multisite lieferten Verfügbarkeit, Steuerprofil-Cache und Schema-Bereitschaft nach `switch_to_blog()` noch Werte der vorherigen Site (A182).
- Servicebuchungen: Puffer vorher/danach werden beim Anlegen, Verschieben und Erzeugen von Zeitfenstern sowie bei jeder Reservierung erzwungen; Zeitfenster-Serien nach Wochentagen; Leistungserbringer lassen sich Ressourcen zuordnen und können zur selben Zeit nicht doppelt gebucht werden (feste Sperrreihenfolge, keine Doppelbuchung bei parallelen Anfragen) (A133).
- Servicebuchungen: Buchungsschluss, maximale Vorausbuchung, Höchstzahl offener Buchungen pro Person sowie Umbuchungs- und Stornofristen je Ressource, serverseitig bei Reservierung, Bestätigung, Umbuchung und Stornierung geprüft (A134).
- Servicebuchungen vollständig: Bestätigung mit Zahlung über den regulären Checkout, atomare Umbuchung, Stornierung mit automatischer Erstattung innerhalb der Frist, E-Mails an Kund:innen, Team und Leistungserbringer, Selbstbedienung im Bestellportal und per Buchungstoken, Buchungsaktionen im Admin; parallele Reservierungen/Umbuchungen nachweislich ohne Doppelbuchung; Schema 3.10.5 (A135).
- Neue Rolle „Pass Captain Leistungserbringer“: Ein Leistungserbringer-Datensatz mit verknüpftem WordPress-Benutzer gibt genau die zugeordneten Ressourcen frei; unter „Meine Leistungen“ Reservierungen bestätigen und bestätigte Buchungen ab Beginn als „nicht erschienen“ markieren (neuer Status `no_show`). Rollen-/Capability-Matrix in docs/SECURITY.md (A143).
- Neue Seite „Zuweisungen“: Team-Mitglieder je Recht auf einzelne Termine beschränken, Einlassbereiche zuweisen, Leistungserbringer je Ressource im Überblick; Scanner, Bestellungen, Listen, Exporte und Berichte beachten die Terminbeschränkung serverseitig (A144).
- Automatischer Abdeckungstest für alle REST-, admin-post- und AJAX-Einstiegspunkte (ohne Recht abgewiesen, fremde Objekte abgewiesen, objektbezogene Prüfung im Codepfad); admin-post-Handler prüfen das Recht vor Validierung und Objektsuche, Berichte lehnen Filter auf fremde Events ab, zugeordnete Leistungserbringer-Datensätze sind nur mit Verwaltungsrecht für alle betroffenen Events änderbar; Schema 3.10.6 (A145, deckt A185 und A193 ab).
- Fault-Injection-Tests für Deadlock, Lock-Timeout, Verbindungsabbruch und COMMIT-Fehler über Checkout, Zahlungs-Webhook, Check-in, Erstattung, Wartelisten-Annahme und Job-Entnahme; dabei behoben: wiederholbare 503-Fehler statt „ausverkauft/nicht gefunden“, keine verlorenen Zahlungen bei DB-Fehlern im Webhook (parallele Zustellung → 503 `wpcap_webhook_in_progress`), Erstattungen werden ganz oder gar nicht und nie doppelt verbucht, keine halb ausgestellten Tickets, keine Unterzählung der Kontingentzähler; Erkennung stiller Wiederverbindungen von `wpdb` und Sicherheitsnetz-Job `ticket_issue_retry` (A183).
- Hintergrundjobs: abstürzende Handler und abgestürzte Worker führen zu Backoff und nach 8 Läufen zuverlässig zum Dead Letter (Audit, Hook `wpcap_job_dead_letter`, Betriebsseite); nur der Lauf mit dem Claim schließt ab; keine doppelte Ticket-Mail nach einem Worker-Absturz; Garantien dokumentiert und mit parallelen Worker-Prozessen getestet (A188).
- Zeitabhängige Regeln nutzen eine zentrale, testbare Uhr (`PassCaptain\Clock`, Filter `wpcap_now`); Tests für Sommerzeitumstellungen (Wien, New York) bei Reservierungen, Verkaufsfenstern, Abendkasse, Erinnerungen, Servicebuchungen, Warteliste, ICS und Berichten. Behoben: Zeitfenster-Serien verloren in der doppelten Herbststunde ein Zeitfenster; der REST-Eventbericht und die Standardzeiträume der Berichte nutzten UTC- statt Ortstage (A199).
- Geldbeträge je Währung über `PassCaptain\Money` (Nachkommastellen nach ISO 4217, Filter `wpcap_currency_decimals`) in Anzeige, Mails, PDFs, CSV-/DATEV-Export, Fiskal- und Zahlungsbeträgen statt festem „/100“; Eigenschaftstests mit festem Seed (EUR, JPY, KWD, BHD). Behoben: Preiseingabe bei Dreidezimalwährungen, verlorener Rundungsrest der Rabattverteilung, Erstattungsaufteilung konnte eine Zeile über mehrere Erstattungen um 1 Cent überschreiten, USt reiner Betragserstattungen jetzt anteilig am offenen Betrag, Fixbetrag des Abrechnungsblatts in der Terminwährung (A200).
- Schnittstellenverträge: Vertrags-Fixtures für öffentliche REST-, Partner-, Entwickler-, App- und Offline-Scanner-API sowie Voucher-Captain-Bridge und Affiliate Captain mit Kompatibilitätstests; Versionsangabe per Header `X-WPCAP-Contract` (andere Hauptversion → 400); Ausnahmen, Timeouts und ungültige Antworten des Gutschein-Anbieters ohne halb gebuchte Bestellung; Versionsregel in docs/CONTRACTS.md (A203).
- Datendiagnose: `wp pass-captain doctor` und Abschnitt „Datenkonsistenz“ auf der Seite „Betrieb“ finden Tickets ohne bzw. mit beendeter Bestellung, bezahlte Bestellungen ohne Tickets, zu hohe und doppelte Erstattungen, hängende Claims, abgelaufene Reservierungen, Zählerdrift, Nummernlücken, widersprüchliche Servicebuchungen und Audit-Lücken; nur lesend mit Vorschau, eindeutige Reparaturen werden auditiert (A204).
- Berichts-Caches werden bei jeder Änderung an Bestellungen, Zahlungen, Erstattungen, Check-ins, Ticketarten, Terminen oder Events sofort invalidiert (Generationsschlüssel je Site und Event, TTL nur als Sicherheitsnetz); der REST-Eventbericht liefert keine bis zu 5 Minuten alten Zahlen mehr; Schema 3.10.7: Indizes für Erstattungen und Gutschriften je Zeitraum (A201).
- Alle ausgehenden HTTP-Aufrufe (Webhooks, Fiskal-Anbieter, SMS, Wallet, Zahlungsanbieter) laufen über einen zentralen Guard: nur HTTPS, keine privaten/internen/Metadaten-Adressen nach DNS-Auflösung (IPv4/IPv6), Schutz gegen DNS-Rebinding, keine Weiterleitungen, Zeit- und Größenlimits, geschwärzte Fehlermeldungen (A202).
- Sicherheit: Zentraler Redaction-Filter entfernt Zugangsdaten, Signaturgeheimnisse, Schlüssel, Karten- und IBAN-Daten aus Fehlertexten, REST-Fehlerantworten, Audit-Log, Fehlerspalten der Warteschlangen, Admin-Hinweisen, Mails und dem PHP-Fehlerprotokoll (Filter `wpcap_redact_values`); unbehandelte Ausnahmen in REST-Routen liefern 500 mit Referenz statt Stack-Trace; REST-Eingaben mit falschem Typ oder negativen Anzahlen werden mit 4xx abgelehnt (eine negative Anzahl ergab bisher 1 Ticket) (A194).
- Datenschutz: Export und Löschung mit sehr vielen Bestellungen einer Adresse geprüft und korrigiert – mehrere Pässe einer Person werden wieder gelöscht (UNIQUE-Konflikt), Hinweise und Admin-Mail einer Löschanfrage nur noch einmal statt je Seite, eigene Exportgruppen, vollständiges Einlassprotokoll, SMS-Exporter/-Löscher seitenweise; Teilnehmerangaben an WooCommerce-Bestellungen werden exportiert und gelöscht; Multisite je Site getestet (A196).
- Arbeitsliste: A187 (geplante Lasttests mit Referenzwerten), A189 (Betriebsübersicht), A190 (Restore-/Upgrade-Übung) und A198 (A11y im Release-Prozess) gegen den Code geprüft und abgehakt – bereits mit A121, A163/A164, A113/A125 und A155 umgesetzt.
- Neue Seite „Service-Zeitfenster“: Ressourcen und buchbare Zeitfenster je Event anlegen, bearbeiten, schließen und ohne Buchungen löschen; Kapazität nicht unter gebuchte Plätze, Uhrzeit gebuchter Zeitfenster bleibt fest; Buchungsliste je Event/Zeitfenster (A157).
- Schema 3.10.0.
- Website-Zustand von WordPress prüft WP-Cron (überfällige Ereignisse, `DISABLE_WP_CRON` ohne externen Cronjob), Rückstände und Dead Letters, fehlgeschlagene Plugin-E-Mails, SMS-Fehler und ausbleibende Zahlungs-Webhooks; Abschnitt im Bericht-Reiter, Hinweis auf den Plugin-Seiten bei kritischen Befunden, Schwellwerte über `wpcap_health_thresholds`; Doku in OPERATIONS.md (A164).
- Übersetzungsprüfung: CI verlangt aktuelle POT und konsistente Platzhalter, vollständige Übersetzungen sind Release-Gate.

### Kasse und Zahlungen (Experten-Review)
- Abendkasse: In Deutschland und Österreich sind Bar und Karte gesperrt, solange keine zertifizierte Kassenlösung (TSE/RKSV) angebunden ist; Administratoren können bestätigen, dass diese Verkäufe in einer eigenen zertifizierten Kasse erfasst werden.
- Erstattungen im Stripe-/PayPal-/Mollie-Dashboard und Rückbuchungen werden wie jede Erstattung gebucht (Erstattungszeile, Rechnungskorrektur, Gutscheinanteil, Partnerabrechnung).
- Fehlgeschlagene Rückbuchungen des Gutscheinanteils werden als Job wiederholt; endgültige Fehler gehen per Mail an den Admin.
- Manuelle Zahlung (Überweisung, Haltezeit 72 h): höchstens 2 offene unbezahlte Bestellungen je E-Mail-Adresse und 5 je Besucher innerhalb der Haltezeit (Filter `wpcap_manual_open_orders_per_email`, `wpcap_manual_orders_per_client`), damit niemand anonym Kontingente blockiert.
- Umsatzsteuer von Teilerstattungen rabattierter Bestellungen korrigiert; Partnerabrechnung zählt bei Mehrzweckgutscheinen Bar- und Gutscheinanteil der Erstattung; Provisionsrechnung einer Kleinunternehmer-Plattform ohne Umsatzsteuer mit Hinweis.

### Zusammenspiel mit WP Voucher Captain
- Ticketdienst: `get()` liefert zusätzlich Event, Ticketart, Beginn (Ortszeit) und Empfänger einer Umwandlung (für „2 Tickets für … am …, gesendet an …“ im Kundenkonto); neues `resend_tickets()` sendet die Tickets einer Umwandlung erneut (neue Codes, höchstens 3-mal pro Stunde wie im Bestellportal).
- Wird eine Ticketart umbenannt, merkt sich Pass Captain den alten Namen je Event: Gutscheine „Kunde wählt den Termin“, die für „Stehplatz“ verkauft wurden, finden die Termine auch nach der Umbenennung.
- Ein Codefeld im Checkout: „Gutschein- oder Rabattcode“ statt zwei Feldern. Bekannte Kampagnencodes werden als Rabatt angewendet, alles andere als Gutschein (nur wenn ein Gutschein-Plugin verbunden ist; sonst bleibt das Feld „Rabattcode“). Die API nimmt weiterhin `campaign_codes` und `voucher_code` an.

### Rechnungen
- Plattformbetreiber als Kleinunternehmer: neue Einstellungen „Kleinunternehmer“ und „Kleinunternehmer-Hinweis“ (Rechnungssteller). Rechnungen der Plattform tragen den Hinweis (Standard nach Land: DE § 19 UStG, AT § 6 Abs. 1 Z 27 UStG) in HTML und PDF; Rechnungen mit Umsatzsteuer werden dann verweigert (§ 14c UStG). Der Standardtext für Veranstalter-Kleinunternehmer richtet sich jetzt ebenfalls nach dem Land.

### Web-Scanner
- Großes Ergebnisbanner mit Symbol und Text (Farbe ist nicht das einzige Signal), Töne und Vibration für Einlass/kein Einlass/Achtung, eigene kurze Live-Region für Screenreader.
- Kamera stoppen, Licht (wo die Kamera es kann), Bildschirm bleibt beim Scannen an; Modusleiste „Modus: Einlass/Auslass“ (Auslass andersfarbig); Schaltflächen mindestens 48 px.
- Ein QR-Code, der im Bild bleibt, wird erst wieder gesendet, nachdem er 3 s aus dem Bild war – „bereits verwendet“ überschreibt das grüne Ergebnis nicht mehr.
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
