# Betrieb und Wiederherstellung

## Vor einem Upgrade

1. Upgrade zuerst in einer Staging-Site mit derselben PHP-, WordPress-, Datenbank- und Integrationsversion einspielen.
2. Dateien und vollständige WordPress-Datenbank unmittelbar vor dem Upgrade sichern. Die Präfix-Tabellen `*_wpcap_*` enthalten Aufträge, Reservierungen, Tickets, Einlassprotokolle und weitere Plugin-Daten.
3. Sicherungszeitpunkt, Plugin-Version und Schema-Version notieren. Die Migration v3 nutzt `dbDelta` und ergänzt Tabellen und Spalten; sie löscht keine Plugin-Tabellen.
4. Nach dem Upgrade Datenbankverbindung, öffentliche Events, Checkout, Ticketmail, Check-in und geplante Jobs in der Staging-Site prüfen.

## Schema-Upgrade nach einem Update

Das Datenbank-Upgrade (`dbDelta` für alle Kern- und Modultabellen) läuft nicht mehr bei jedem Seitenaufruf:

- **Admin-Aufrufe** (`admin_init`), **WP-Cron** und **WP-CLI** vergleichen die gespeicherte Schema-Version (`wpcap_db_version`, Kernversion plus Hash der Modul-Schemata) und führen das Upgrade aus. Es läuft hinter einer Datenbanksperre (`GET_LOCK`, je Site); parallele Aufrufe ohne Sperre überspringen es und arbeiten mit dem bisherigen Schema weiter. Die Sperre endet automatisch, wenn der Prozess abbricht.
- **Frontend- und REST-Aufrufe** prüfen nur einen günstigen Fingerabdruck (Schema-/Plugin-Version und Modulliste, Option `wpcap_schema_fingerprint`). Weicht er ab, wird einmalig das Cron-Ereignis `wpcap_run_upgrade` eingeplant. Nur wenn dieses Ereignis länger als fünf Minuten überfällig ist (WP-Cron läuft nicht), übernimmt ein einzelner Frontend-Aufruf das Upgrade hinter derselben Sperre.
- Bis zum Upgrade läuft der neue Code gegen das alte Schema. Das ist unkritisch, weil Migrationen nur Tabellen, Spalten und Indizes ergänzen; nach einem Update trotzdem zeitnah den Admin-Bereich öffnen oder `wp cron event run wpcap_run_upgrade` ausführen.

## Hintergrundverarbeitung (WP-Cron)

E-Mail-Warteschlange (`wpcap_process_messages`, zusammen mit SMS), Jobs und Webhooks (`wpcap_process_jobs`, `wpcap_webhooks_dispatch`) laufen alle fünf Minuten. Jeder Lauf arbeitet mit einem gemeinsamen **Zeitbudget von 20 Sekunden** (höchstens `max_execution_time` − 15 s; Filter `wpcap_cron_time_budget`) und bis zu 500 E-Mails (Filter `wpcap_message_batch_size`). Das Budget wird vor jeder Entnahme geprüft, sodass kein Eintrag reserviert und dann liegen gelassen wird; bricht PHP während eines Eintrags ab, wird die Reservierung beim Beenden freigegeben. Bleibt ein Rückstand, plant der Lauf sofort einen Folgelauf ein – über Action Scheduler (`as_enqueue_async_action`, Gruppe `wp-pass-captain`), wenn vorhanden (z. B. mit WooCommerce), sonst als einzelnes WP-Cron-Ereignis.

WP-Cron startet nur bei Seitenaufrufen. Für verlässliche Zustellung (Ticketmails, Erinnerungen, Webhooks) WP-Cron durch einen System-Cronjob ersetzen:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```sh
# crontab des Webserver-Benutzers: jede Minute fällige Ereignisse ausführen
* * * * * cd /pfad/zu/wordpress && wp cron event run --due-now --quiet
# ohne WP-CLI:
* * * * * curl -fsS -o /dev/null "https://example.org/wp-cron.php?doing_wp_cron"
```

Auf Multisite-Netzwerken muss der Cronjob jede Site auslösen, z. B. `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`.

## Multisite: Site löschen und Plugin deinstallieren

- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben; Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites.

## Wiederherstellung

Dateien und Datenbank als zusammengehörigen Stand wiederherstellen. Ein Datenbank-Restore allein kann mit neuerem Plugin-Code inkompatibel sein. Für eine Netzwerksite gehören auch Netzwerkaktivierung und Site-Präfixe in den Wiederherstellungsplan. Nach einem Restore Cron-Ereignisse kontrollieren; WordPress-Cron benötigt Seitenaufrufe oder einen externen Cron-Trigger.

Auftrags- und Ticketdaten nicht selektiv aus mehreren Sicherungszeitpunkten zusammenführen. Zahlungsanbieter und optionale Integrationen müssen separat auf denselben externen Transaktionsstand abgeglichen werden.

## Release-Kandidat

Vor einer stabilen Version müssen Upgrade und Restore mit realistischen Datenbankständen, Eventänderungen, doppelten Webhooks, E-Mail-Ausfällen, hoher Scanrate und Multisite geprüft werden. Der ZIP-Build (`bash build/release.sh`) exportiert den committeten Stand per `git archive` (Standard `HEAD`, anders mit `WPCAP_BUILD_REF=<ref>`), installiert dort die Composer-Abhängigkeiten ohne Dev-Pakete und lässt alles weg, was in `.distignore` steht (Tests, Doku, Markdown außer `readme.txt`, `.po`-Quellen, Test-/CI-Dateien der Abhängigkeiten). Nicht committete Änderungen landen also nie im Paket. Der Release-Workflow veröffentlicht nur nach erfolgreicher CI auf `main` (genau der getestete Commit), bei Tags `v*` oder manuell von `main`.

## Kasse: keine Registrierkasse

Die Kasse (Menü **Pass Captain → Kasse**) bucht Verkäufe vor Ort als Bestellungen, erzeugt Belege mit fortlaufender Nummer und einen Kassenabschluss. Sie ist **keine** Registrierkasse im fiskalrechtlichen Sinn:

- **Österreich (RKSV):** Bareinnahmen (dazu zählen auch Karten- und Wallet-Zahlungen vor Ort) erfordern ab den gesetzlichen Umsatzgrenzen eine Registrierkasse mit Sicherheitseinrichtung (Signaturerstellungseinheit), registriert bei FinanzOnline, inkl. Start-, Monats- und Jahresbeleg.
- **Deutschland (KassenSichV, § 146a AO):** Elektronische Aufzeichnungssysteme mit Kassenfunktion benötigen eine zertifizierte technische Sicherheitseinrichtung (TSE, BSI TR-03153) und sind dem Finanzamt zu melden; Belege müssen die TSE-Daten enthalten.

Die Kasse des Plugins signiert keine Belege und hat keine TSE-/Signaturkarten-Anbindung. Ist das Land des Rechnungsstellers DE oder AT, sind **Bar und Karte deshalb gesperrt** (Fehler `wpcap_pos_fiscal_required`); Freikarte und Rechnung bleiben möglich. Werden diese Verkäufe nachweislich zusätzlich in einer eigenen zertifizierten Kasse erfasst, kann ein Administrator Bar/Karte in den Belegeinstellungen der Kasse freigeben (Einstellung `boxoffice_cash_confirmed`, wird im Audit-Log protokolliert). Eine künftige Fiskal-Anbindung meldet sich über den Filter `wpcap_boxoffice_fiscal_ready`.

Für Bar- und Kartenzahlungen vor Ort deshalb eine zertifizierte Kasse verwenden und das Plugin nur für Ticketausgabe und Einlass nutzen, oder die Rechtslage mit der Steuerberatung klären. Der Hinweis auf der Kassenseite kann pro Benutzer ausgeblendet werden; der Link lässt sich über den Filter `wpcap_boxoffice_fiscal_docs_url` ändern.

## Datenschutz: Löschfristen, Auskunft und Löschung

**Täglicher Löschlauf** (`wpcap_privacy_retention`, WordPress-Cron, Stapel zu 500 Zeilen, Zeitbudget 20 s; der nächste Lauf setzt fort). Bezugspunkt ist das Terminende (`ends_at`, sonst Beginn + 1 Tag):

| Daten | Frist | Wirkung |
|---|---|---|
| Teilnehmerangaben (Zusatzfelder) | Einstellung „Teilnehmerdaten nach dem Event löschen nach“; bei 0 die Frist des Felds | gelöscht |
| Teilnehmername/-E-Mail (Zuordnungen, Tickets), Namen in Offline-Paketen, Apple-Wallet-Geräteregistrierungen | Einstellung; bei 0 365 Tage (`wpcap_attendee_default_retention_days`) | geleert/gelöscht; Ticket, Bestellung und Einlassprotokoll bleiben |
| Teilnehmerangaben unbezahlter (abgelaufener/stornierter) Bestellungen | 1 Tag | `attendee_data` geleert |
| Käufer-E-Mail nie bezahlter Bestellungen (ohne Ticket, ohne Rechnung) | 90 Tage | durch Platzhalter `deleted+…@invalid.example` ersetzt |
| Versandte/fehlgeschlagene Nachrichten der Warteschlange | 30 Tage / 365 Tage | Empfänger und Inhalt geleert / Zeile gelöscht |
| Einlassprotokoll: Mitarbeitende und Gerät | 365 Tage | `user_id`/`device_id` = 0 (Zeit, Ergebnis, Ticket bleiben) |
| Audit-Log | 730 Tage | gelöscht |

Alle Fristen außer den Teilnehmerfristen lassen sich mit `wpcap_privacy_retention_days` ändern oder abschalten (siehe [HOOKS.md](HOOKS.md)). Das Audit-Log erhält je Lauf nur die Anzahlen (`privacy_retention`). Nicht erfasst: Bestellungen, Positionen, Rechnungen, Gutschriften und Erstattungen (gesetzliche Aufbewahrung), Bestellungen in WooCommerce (eigene Fristen des Shops), Daten bei Zahlungsanbietern, Partnern und Webhook-Empfängern. Webhook-Zustellungen haben eine eigene Frist (Modul Webhooks).

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste und Terminbuchungen. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten und Terminbuchungen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Sperr-Push-Nachricht). Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Hat die Person Tickets für kommende Termine, weist die Löschung darauf hin; Erstattung oder Storno müssen Sie selbst veranlassen.

**Pflichten des Betreibers:**

- WordPress-Cron zuverlässig auslösen (siehe oben), sonst greifen die Fristen nicht.
- Den Vorschlag unter **Einstellungen → Datenschutz → Leitfaden** prüfen, an Verantwortlichen, Verträge und Abläufe anpassen und in die Datenschutzerklärung übernehmen; nach Aktivieren weiterer Dienste (Zahlungsanbieter, SMS, Wallet, Partner, Webhooks, Affiliate) erneut prüfen.
- Auftragsverarbeitungsverträge mit Hosting, E-Mail-Versand, SMS-Anbieter und gegebenenfalls Partnern bzw. Webhook-Empfängern schließen; Übermittlungen in die USA (Stripe, PayPal, Twilio, Apple, Google) dokumentieren.
- Affiliate-Tracking: Mit dem Plugin „WP Consent API“ und einem kompatiblen Cookie-Banner wird der Empfehlungs-Cookie nur mit Einwilligung „marketing“ ausgewertet; der Partnerlink der laufenden Shop-Sitzung (ohne Marketing-Cookie) zählt auch ohne diese Einwilligung. Ohne Consent-API muss der Cookie-Banner das Setzen des Affiliate-Cookies selbst blockieren, oder der Filter `wpcap_affiliate_tracking_allowed` wird an den eigenen Consent-Manager angebunden.
- Web-Scanner auf geteilten Geräten: vor Schichtwechsel synchronisieren, nach Schichtende Browser schließen bzw. Website-Daten löschen (Offline-Scans liegen bis zu 24 Stunden im Browser).
- Gesetzliche Aufbewahrung von Rechnungen und Buchungsdaten (7 Jahre § 132 BAO, 10 Jahre § 147 AO/§ 257 HGB) überwachen und danach manuell löschen; das Plugin löscht Finanzdaten nicht automatisch.
- Partner-API-Schlüssel mit `attendees:read` nur mit Rechtsgrundlage vergeben; gelöschte Adressen liefert die API als `null`.

## Kasse: Kassen, Schichten, Übergabe und Z-Bericht

Die Kasse arbeitet mit **Kassen** (Schubladen) und **Schichten**. Sie ersetzt keine Registrierkasse im fiskalrechtlichen Sinn (siehe oben), macht den Bargeld- und Kartenabgleich aber nachvollziehbar.

**Kassen** (Pass Captain → Kasse → Kassen, nur Administratoren): Name, Standort, optional ein Event (dann verkauft die Kasse nur dessen Termine), Währung und Blindzählung. Kassen werden nicht gelöscht, nur deaktiviert, damit ihre Z-Berichte vollständig bleiben. Eine Kasse hat höchstens eine offene Schicht.

**Schicht öffnen** (Reiter „Verkauf“ ohne offene Schicht): Kasse wählen, Wechselgeld zählen (Betrag oder Stückelung nach Scheinen und Münzen). Weitere Kassierer können der Schicht hinzugefügt werden (Reiter „Schicht“); jede Person arbeitet in höchstens einer offenen Schicht. Verkäufe werden der Schicht zugeordnet, in der die Person arbeitet.

**Einlagen und Entnahmen** (Reiter „Schicht“): immer mit Betrag und Grund; sie verändern den Soll-Bestand.

**Zählung und Abschluss** (Reiter „Schicht“):

1. *Zählung abgeben*: gezählter Bargeldbestand (Betrag oder Stückelung) und die Summe laut Kartenterminal (Tagesabschluss des Terminals; leer lassen, wenn kein Terminal genutzt wurde). Bei **Blindzählung** sehen Kassierer Soll-Bestand und Differenz erst im Z-Bericht nach dem Schließen; sie geben genau eine Zählung ab, schließen ohne Differenzgrund, und eine Nachzählung kann nur die Kassenverwaltung (`manage_options`) oder `wpcap_view_finance` durchführen. Administratoren und Personen mit `wpcap_view_finance` sehen den Soll-Bestand immer. Ab der Zählung sind keine Verkäufe und Buchungen in der Schicht mehr möglich. Eine Neuzählung wird als weiterer Eintrag gespeichert; für den Abschluss zählt die letzte, der Z-Bericht listet alle Zählungen mit ihrer Differenz.
2. *Schicht abschließen*: Weichen Bargeld oder Kartenterminal vom Soll ab, ist ein Grund Pflicht. Optional **Übergabe** an die nächste Person mit dem Betrag, der in der Kasse bleibt; der Rest gilt als abgeschöpft (Tresor/Bank). Der Abschluss erzeugt den **Z-Bericht**.

Offene Stornos, die auf Freigabe warten, und bezahlte, noch nicht gebuchte Verkäufe verhindern Zählung und Abschluss (zuerst freigeben/ablehnen bzw. „Nachbuchen“).

**Übergabe annehmen**: Die empfangende Person sieht die Übergabe auf dem Reiter „Verkauf“, zählt das Geld (bei Blindzählung ohne den übergebenen Betrag zu sehen) und öffnet damit die nächste Schicht. Eine Abweichung braucht einen Grund; übergebende und empfangende Person, Betrag und Differenz werden gespeichert und protokolliert (Audit-Log `pos_handover_created`, `pos_handover_accepted`). Solange eine Übergabe wartet, kann niemand anderes die Kasse öffnen; Administratoren und die übergebende Person können die Übergabe mit Grund stornieren (Reiter „Kassen“).

**Z-Bericht** (Reiter „Schichten & Z-Berichte“, als druckbares HTML – im Browser „Als PDF speichern“ – und als CSV): fortlaufende, lückenlose Z-Nummer je Kasse, Kassen-ID und -Name, Öffnungs- und Schließzeit, Kassierer, Summen je Zahlart (Verkäufe, Stornos/Erstattungen, netto), je Steuersatz (netto, USt., brutto; nach Abzug der Erstattungen), Anzahl Verkäufe, Stornos, Erstattungen und Freikarten, Bargeld (Anfangsbestand, Barumsatz, Auszahlungen, Einlagen, Entnahmen, Korrekturen, Soll, gezählt, Differenz, Stückelung, Übergabe, Abschöpfung), Kartenterminal (Soll, Terminal-Summe, Differenz), Grund der Differenz, alle Buchungen der Schicht, Nettoumsatz und der kumulierte **Grand Total** der Kasse. Z-Berichte, Zählungen und Buchungen werden nur eingefügt, nie geändert. Jeder Z-Bericht trägt eine Prüfsumme und die Prüfsumme seines Vorgängers; die Seite zeigt je Kasse, ob die Kette lückenlos und unverändert ist. Ein Fehler in einem abgeschlossenen Z-Bericht wird als **Korrektur** (Administratoren) in die offene Schicht derselben Kasse gebucht und erscheint im nächsten Z-Bericht.

**Storno und Erstattung von Kassenverkäufen**:

- Storno ist auch nach dem Schichtabschluss möglich (Belegsuche auf dem Reiter „Verkauf“), ganz oder für einzelne Tickets. Die Auszahlung wird mit eigenem Stornobeleg (Belegnummernkreis der Kasse) als negative Buchung in die Schicht des Verkaufs gebucht, solange sie offen ist, sonst in die offene Schicht der handelnden Person oder der Kasse des Verkaufs.
- Bar- und Kartenauszahlungen brauchen eine offene Schicht. Das gilt auch für Erstattungen über die Bestellung oder automatische Erstattungen (z. B. Terminabsage): Ohne offene Schicht schlägt die Erstattung fehl und kann nach dem Öffnen einer Schicht erneut ausgeführt werden. Freikarten- und Rechnungs-Erstattungen werden gebucht, wenn eine Schicht offen ist.
- Eingecheckte Tickets werden an der Kasse nur mit der Berechtigung `wpcap_boxoffice_override` (oder als Administrator) und Angabe eines Grunds storniert; der Grund steht auf der Buchung, im Stornobeleg und im Audit-Log (`pos_storno_override`).

**Aktualisierung bestehender Installationen**: Kassensitzungen aus früheren Versionen werden einmalig der Standardkasse „Hauptkasse“ zugeordnet (Option `wpcap_boxoffice_migration` = 2). Abgeschlossene Sitzungen erhalten in der Reihenfolge ihres Abschlusses je einen Z-Bericht (Herkunft „übernommen“, Werte aus der gespeicherten Sitzung, Grand Total läuft weiter). Offene Sitzungen laufen als Schicht weiter; gab es mehrere, erhält jede weitere eine eigene Kasse „Kasse n (übernommen)“. Verkäufe und Sitzungsdaten bleiben unverändert.

**Schnittstellen für Fiskalisierung und Integrationen** (alle nach dem Speichern ausgelöst):

| Hook | Art | Argumente |
|---|---|---|
| `wpcap_boxoffice_shift_opened` | Action | Schicht-ID, Kassen-ID |
| `wpcap_boxoffice_can_close_shift` | Filter | `true` oder `WP_Error` (Abschluss verhindern), Schicht, letzte Zählung |
| `wpcap_boxoffice_shift_closed` | Action | Z-ID, Z-Bericht (mit `totals_data`: Zahlarten, Steuersätze, Zähler, Bargeld, Karte, Buchungen), Schicht |
| `wpcap_boxoffice_cash_movement` | Action | Buchungs-ID, Buchung (Einlage, Entnahme, Korrektur) |
| `wpcap_boxoffice_refund_booked` | Action | Buchungs-ID, Erstattungs-ID, Buchung (Storno/Erstattung mit USt. je Satz) |
| `wpcap_boxoffice_handover_created` / `_accepted` | Action | Übergabe-ID |
| `wpcap_boxoffice_zreport_html_extra` | Filter | zusätzliches (escaptes) HTML am Ende des Z-Berichts, Z-Bericht |
| `wpcap_boxoffice_denominations` | Filter | Stückelung in kleinster Einheit, Währung |

## Kontingentzähler (große Vorverkäufe)

Seit Schema 3.7.0 führen Ticketarten und Termine `sold_qty` (bezahlte, nicht erstattete Einheiten) und
`held_qty` (Reservierungen mit Status `active`); jede Bestellzeile/Reservierung merkt sich in `counted_qty`,
was sie beiträgt. Die Kapazitätsprüfung liest die gesperrten Zähler statt alle Bestellzeilen zu summieren;
abgelaufene, noch nicht aufgeräumte Reservierungen zieht sie über einen kleinen Indexbereich ab.

- **Zustand** (Option `wpcap_capacity_counters`): Neuinstallation sofort `ready`. Beim Upgrade baut der
  Installer die Zähler je Termin unter Zeilensperren aus den Bestellungen neu auf (`building`) und entscheidet
  danach weiter mit den alten SUM-Abfragen (`verifying`), bis ein Abgleich frühestens 10 Minuten später
  (Filter `wpcap_capacity_verify_after`) keine Abweichung findet → `ready`. In dieser Zeit ist der Checkout
  langsam wie vorher; Upgrades nicht während eines Vorverkaufs einspielen. Filter
  `wpcap_capacity_counters_enabled` (`false`) erzwingt den SUM-Pfad.
- **Abgleich**: Cron `wpcap_reconcile_capacity` alle fünf Minuten (räumt zuerst abgelaufene Reservierungen
  auf) oder `wp pass-captain reconcile-capacity`. Er synchronisiert Zeilen, deren `counted_qty` nicht zum
  Bestellstatus passt (ein Writer hat nicht synchronisiert), und repariert Zähler, die nicht der Summe ihrer
  Zeilen entsprechen. Jeder Fund: Audit-Eintrag `capacity_counter_drift`, Eintrag im PHP-Error-Log, Action
  `wpcap_capacity_drift` (Bericht) und höchstens stündlich eine Mail an die Admin-Adresse (Filter
  `wpcap_capacity_drift_alert`). Ein Drift-Fund bedeutet: irgendwo ändert Code Bestellungen, Zeilen oder
  Reservierungen direkt in der Datenbank.
- **Eigene Writer** (Plugins, Skripte): nach Änderungen an `wpcap_orders` (Zahl-/Status), `wpcap_order_items`
  oder `wpcap_reservations` `\PassCaptain\CapacityCounters::sync_order_now( $order_id )` aufrufen; Ticketarten nur
  mit `CapacityCounters::move_type()` auf einen anderen Termin verschieben. Wer Kontingent *hinzufügt* (bezahlt
  setzen, Reservierung anlegen), muss innerhalb derselben Transaktion `CapacityCounters::sync_order()` aufrufen,
  nachdem Bestellung, dann Ticketarten, dann Termine (jeweils nach ID) gesperrt sind.
- **Prüfen**: `CapacityCounters::drift()` liefert Zähler, die von den SUM-Abfragen abweichen (leer = in Ordnung).
- **Verfügbarkeitsanzeige**: `Orders::availability()` ist pro Request gemerkt (jeder Schreibzugriff auf eine
  Plugin-Tabelle verwirft das) und liegt 10 s im Objekt-Cache (Filter `wpcap_availability_cache_ttl`, 0–60,
  0 = aus); Requests, die selbst geschrieben haben, lesen den Objekt-Cache nicht. Kaufentscheidungen nutzen nie
  den Cache. Event-Liste und REST-`/events` laden alle Termine mit konstanter Abfragezahl
  (`Orders::availability_many()`, `PublicEvents::listing_data()`).
