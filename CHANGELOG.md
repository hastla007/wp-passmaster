# Änderungsprotokoll

## Unveröffentlicht

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
