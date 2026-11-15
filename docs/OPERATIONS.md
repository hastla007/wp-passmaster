# Betrieb und Wiederherstellung

## Backup/Restore und Upgrade

**Was gesichert werden muss** (immer als ein zusammengehöriger Stand):

- die vollständige WordPress-Datenbank – Plugin-Daten liegen in den Tabellen `{präfix}wpcap_*` (Bestellungen,
  Reservierungen, Tickets, Einlassprotokolle, Servicebuchungen, Wartelisten, Rechnungen, Audit-Log …) und in
  Optionen `wpcap_*` (Einstellungen inkl. verschlüsselter Zugangsdaten, Schema-Version);
- `wp-config.php` bzw. die Umgebung mit `PASS_CAPTAIN_SECRET_KEY` (und gegebenenfalls
  `PASS_CAPTAIN_SECRET_KEY_PREVIOUS`) sowie den Salts `AUTH_KEY`/`SECURE_AUTH_KEY`: **ohne den Vault-Schlüssel
  sind die gespeicherten Anbieter-Secrets im Backup nicht mehr lesbar** ([SECURITY.md](SECURITY.md#zugangsdaten-secrets-vault));
  Schlüssel getrennt von den Datenbank-Backups aufbewahren;
- Plugin- und Theme-Dateien (eigene Template-Überschreibungen), bei WooCommerce dessen Daten (gleiche Datenbank).

Das Plugin legt keine eigenen Dateien im Upload-Verzeichnis ab.

**Upgrade-Ablauf:**

1. Upgrade zuerst in einer Staging-Site mit derselben PHP-, WordPress-, Datenbank- und Integrationsversion
   einspielen ([COMPATIBILITY.md](COMPATIBILITY.md)).
2. Vorher prüfen: *Pass Captain → Systemstatus* ohne kritische Probleme, `wp pass-captain diagnose --strict`
   ohne Befund, Queues leer oder klein. Nicht während eines laufenden Einlasses oder Vorverkaufsstarts
   upgraden; Offline-Scanner vorher synchronisieren.
3. Unmittelbar vor dem Upgrade Datenbank und Dateien sichern (siehe oben); Zeitpunkt, Plugin-Version und
   Schema-Version (Systemstatus → Datenbankschema bzw. Option `wpcap_db_version`) notieren.
4. Plugin aktualisieren. Das Schema-Upgrade läuft beim nächsten Admin-Aufruf, per Cron oder sofort mit
   `wp cron event run wpcap_run_upgrade` (Details im nächsten Abschnitt). Migrationen ergänzen nur Tabellen,
   Spalten und Indizes (`dbDelta`), sie löschen keine Plugin-Daten.
5. Danach prüfen: Systemstatus → Datenbankschema ohne Fehler, Worker-Heartbeats aktuell,
   `wp pass-captain status --strict` und `wp pass-captain diagnose --strict` ohne Befund; Stichprobe
   öffentliche Events, Checkout (kostenlos und bezahlt), Ticketmail, Check-in, bei WooCommerce eine
   Testbestellung.
6. Erst dann in Produktion wiederholen.

**Restore** (z. B. fehlgeschlagenes Upgrade; ein Downgrade des Plugins ohne Restore ist nicht vorgesehen):

1. Site in den Wartungsmodus, WP-Cron/System-Cron anhalten, damit keine Mails, Webhooks oder Erstattungen
   aus einem Zwischenstand verschickt werden.
2. Datenbank **und** Plugin-Dateien der gesicherten Version gemeinsam zurückspielen – ein Datenbank-Restore
   allein kann mit neuerem Plugin-Code inkompatibel sein; dazu denselben Vault-Schlüssel in `wp-config.php`.
   Auf Multisite gehören Netzwerkaktivierung und Site-Präfixe in den Plan.
3. Auftrags- und Ticketdaten nicht selektiv aus mehreren Sicherungszeitpunkten zusammenführen.
4. Cron wieder einschalten, `wp pass-captain diagnose --strict` ausführen, sichere Reparaturen mit
   `--repair-all`, übrige Befunde manuell klären.
5. Zahlungen, Erstattungen und Webhooks, die nach dem Sicherungszeitpunkt beim Anbieter eingegangen sind,
   abgleichen (Anbieter-Dashboard; Webhooks erneut zustellen lassen); nach dem Sicherungszeitpunkt
   ausgestellte Tickets sind im Restore nicht enthalten. Offline-Scanner neu synchronisieren bzw. Pakete neu
   laden.

Die Restore-Probe ist Teil der Release-Abnahme ([RELEASE.md](RELEASE.md)) und sollte auch im Betrieb
regelmäßig (z. B. vierteljährlich) auf einer Staging-Site geübt werden.

**Automatisierte Restore-Probe** (`tests/drills/restore-drill.sh`, wöchentlich in `.github/workflows/drills.yml`
auf MariaDB 11.4, MySQL 8.0 und 8.4; Details in [TESTING.md](TESTING.md#restore-probe-testsdrillsrestore-drillsh)):
Sie spielt genau diesen Ablauf in einer Wegwerf-Installation durch – Daten inklusive Arbeit, die beim Backup
„unterwegs“ ist (bezahlte Bestellung, deren Ticketausstellung abbrach; hängender Job-Claim; nicht bereinigte
Reservierung), Dump mit `--single-transaction`, Schreibvorgänge nach dem Backup, Zerstörung, Restore, dann
Schritt 4 (`diagnose --repair-all`, Cron, `diagnose --strict`) – und prüft, dass

- der Datenstand exakt dem Backup entspricht und Ticketcodes von vor dem Backup wieder gelten,
- die Diagnose nach dem Restore dieselben Befunde wie vor dem Backup zeigt und nach der Reparatur keine mehr,
- keine doppelten Tickets, Einlässe, Erstattungen oder Job-Schlüssel, keine verwaisten Reservierungen, keine
  bezahlte Bestellung ohne Tickets und keine gültigen Tickets unbezahlter Bestellungen entstehen,
- ein erneuter Lauf der wartenden Jobs und Reparaturen (auch nach einem zweiten Restore desselben Dumps)
  nichts mehr ändert und niemandem eine zweite Mail schickt.

Für die eigene Staging-Probe eignet sich dasselbe Skript gegen eine **leere Wegwerf-Datenbank**
(`WPCAP_DRILL_DB_NAME=…`; es löscht alle Tabellen darin); `wp pass-captain diagnose --strict` und
`wp eval-file build/ci-data.php invariants` (aus dem Repository, nicht im Plugin-ZIP) lassen sich auch nach einem
echten Restore auf Staging ausführen. Was die automatische Probe nicht ersetzt: Restore der Plugin-Dateien und von
`wp-config.php`/Vault-Schlüssel, Abgleich mit dem Zahlungsanbieter (Schritt 5) und Offline-Scanner.

## Schema-Upgrade nach einem Update

Das Datenbank-Upgrade (`dbDelta` für alle Kern- und Modultabellen) läuft nicht mehr bei jedem Seitenaufruf:

- **Admin-Aufrufe** (`admin_init`), **WP-Cron** und **WP-CLI** vergleichen die gespeicherte Schema-Version (`wpcap_db_version`, Kernversion plus Hash der Modul-Schemata) und führen das Upgrade aus. Es läuft hinter einer Datenbanksperre (`GET_LOCK`, je Site); parallele Aufrufe ohne Sperre überspringen es und arbeiten mit dem bisherigen Schema weiter. Die Sperre endet automatisch, wenn der Prozess abbricht.
- **Frontend- und REST-Aufrufe** prüfen nur einen günstigen Fingerabdruck (Schema-/Plugin-Version und Modulliste, Option `wpcap_schema_fingerprint`). Weicht er ab, wird einmalig das Cron-Ereignis `wpcap_run_upgrade` eingeplant. Nur wenn dieses Ereignis länger als fünf Minuten überfällig ist (WP-Cron läuft nicht), übernimmt ein einzelner Frontend-Aufruf das Upgrade hinter derselben Sperre.
- Bis zum Upgrade läuft der neue Code gegen das alte Schema. Das ist unkritisch, weil Migrationen nur Tabellen, Spalten und Indizes ergänzen; nach einem Update trotzdem zeitnah den Admin-Bereich öffnen oder `wp cron event run wpcap_run_upgrade` ausführen.
- **Fehlgeschlagenes Upgrade:** Version und Fingerabdruck werden nur nach vollständigem Erfolg gespeichert; die Fehlerdetails stehen in der Option `wpcap_schema_error`, im PHP-Fehlerprotokoll und als Admin-Hinweis. Wiederholungen laufen mit **Backoff** (1, 5, 15, danach alle 60 Minuten; Zähler `attempts`, Zeitpunkt `next_retry` in der Option), damit eine defekte Datenbank nicht bei jedem Admin- oder Cron-Aufruf erneut `dbDelta` ausführt. Eine neue Plugin-/Schema-Version wird sofort versucht. Nach Behebung der Ursache unter **Pass Captain → Systemstatus → Datenbankschema → „Jetzt erneut versuchen“** sofort wiederholen (ignoriert den Backoff, läuft hinter derselben Sperre) oder per `wp pass-captain diagnose --repair=schema`.

## Hintergrundverarbeitung (WP-Cron)

E-Mail-Warteschlange (`wpcap_process_messages`, zusammen mit SMS), Jobs und Webhooks (`wpcap_process_jobs`, `wpcap_webhooks_dispatch`) laufen alle fünf Minuten. Jeder Lauf arbeitet mit einem gemeinsamen **Zeitbudget von 20 Sekunden** (höchstens `max_execution_time` − 15 s; Filter `wpcap_cron_time_budget`) und bis zu 500 E-Mails (Filter `wpcap_message_batch_size`). Das Budget wird vor jeder Entnahme geprüft, sodass kein Eintrag reserviert und dann liegen gelassen wird; bricht PHP während eines Eintrags ab, wird die Reservierung beim Beenden freigegeben. Bleibt ein Rückstand, plant der Lauf sofort einen Folgelauf ein – über Action Scheduler (`as_enqueue_async_action`, Gruppe `wp-pass-captain`), wenn vorhanden (z. B. mit WooCommerce), sonst als einzelnes WP-Cron-Ereignis.

**Lange Vorgänge im Hintergrund:**

- **Termin absagen:** Alle gültigen Tickets werden sofort mit gebündelten UPDATEs widerrufen – noch bevor Geld fließt. Danach laufen die Folgearbeiten als Job-Pakete (`occurrence_cancel_hooks` je 200 Tickets: Wallet, Webhooks, SMS, Sitzplätze; `occurrence_cancel_refunds` je 20 Bestellungen; `occurrence_cancel_notify` je 100 Empfänger). Die ersten Pakete laufen noch im Admin-Request (bis 20 s), der Rest über die Job-Queue; die Rückmeldung nennt die Zahl der offenen Pakete, den Fortschritt zeigt der Systemstatus. Ein Abbruch an beliebiger Stelle hinterlässt nie gültige Tickets mit erstattetem Geld.
- **Erinnerungen:** Der Lauf besucht alle Termine im Erinnerungsfenster seitenweise (keine feste Grenze von 200 Terminen mehr), prüft vorhandene Erinnerungen je Termin mit einer Abfrage und hält das gemeinsame Zeitbudget ein; reicht es nicht, merkt er sich die Position (`wpcap_reminder_cursor`) und plant einen Folgelauf.
- **Mollie-Zahlungsarten:** Die Liste wird je Währung und Rechnungsland eine Stunde gecacht und lokal nach den Mindest-/Höchstbeträgen gefiltert (für Nicht-EUR-Währungen je Betrag, weil Mollie die Grenzen in EUR liefert). Der Abruf hat 3 s Timeout; Fehler werden 5 Minuten gecacht, damit die Event-Seite bei einer Mollie-Störung nicht bei jedem Aufruf wartet.
- **CSV-Exporte** (Audit-Log, Fiskal-Archiv) werden seitenweise gelesen und über einen temporären Stream ausgeliefert – ohne Zeilenlimit und ohne den ganzen Export im Speicher. Der Finanzbericht umfasst höchstens 366 Tage; längere Zeiträume werden mit Hinweis gekürzt.

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

Auf Multisite-Netzwerken muss der Cronjob jede Site auslösen, z. B. `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`. Systemstatus und `wp pass-captain status`/`diagnose` arbeiten je Site (`--url=`); den Zustand aller Sites zeigt die Netzwerkübersicht (siehe „Multisite“).

## Rate-Limits ohne persistenten Object-Cache

Ohne persistenten Object-Cache zählen alle Plugin-Limits (Checkout, Servicebuchungen, Wartelisten, Sitzplatz-Holds, Partner- und Entwickler-API, Kopplung, Portal, SMS) in der Tabelle `wpcap_rate_limits` (seit Schema 3.16.0): eine Zeile je Zähler (`bucket_key` = HMAC des Zählernamens, `window_start`, `hits`, `expires_at`), hochgezählt mit einem einzigen atomaren `INSERT … ON DUPLICATE KEY UPDATE`. Parallele PHP-Worker können das Limit dadurch nicht mehr überholen; die Optionstabelle bleibt frei von Transient-Zeilen je IP. Die Tabelle ist klein (Zeilen leben nur bis zum Fensterende) und wird vom täglichen Löschlauf in Stapeln bereinigt; bei sehr vielen Zugriffen von wechselnden Adressen wächst sie bis dahin um eine Zeile je Adresse und Fenster. Mit persistentem Object-Cache (Redis/Memcached) bleibt es bei `wp_cache_incr()` – die Tabelle wird dann nicht benutzt. Fehlt die Tabelle (Update, Schema-Upgrade noch nicht gelaufen), zählen die Limits übergangsweise wie früher in Transients. Zum Zurücksetzen aller Zähler (z. B. nach einem Test) genügt `DELETE FROM wp_wpcap_rate_limits`.

## Bestellsuche im Admin

Die Suche unter **Pass Captain → Bestellungen** nutzt die Indizes der Bestelltabelle (PERF-15):

- **Vollständige E-Mail-Adresse** (`anna@example.com`): exakter Treffer (Index `customer_email`; Groß-/Kleinschreibung egal).
- **Anfang einer Adresse oder Bestellnummer** (`anna`, `anna@exa`, `3F2A-9C1E`): Präfixsuche (`anna%`), ebenfalls über den Index.
- **Domain** mit führendem `@` (`@example.com`): Suche am Ende der Adresse – durchsucht alle Bestellungen und ist bei sehr großen Tabellen langsamer.
- Teile aus der Mitte einer Adresse (`nna@`) werden nicht mehr gefunden; dafür bitte den Anfang der Adresse oder die Domain eingeben.

## Systemstatus, Diagnose und Monitoring

**Pass Captain → Systemstatus** (Capability `manage_options`) zeigt den Hintergrundbetrieb auf einen Blick:

- **WP-Cron und Worker:** je Plugin-Hook (`wpcap_process_jobs`, `wpcap_process_messages`, `wpcap_cleanup_reservations`, `wpcap_privacy_retention`) den nächsten geplanten Lauf und den **letzten erfolgreichen Lauf**. Der Webhook-Versand (`wpcap_webhooks_dispatch`) wird nicht als Cron-Ereignis gelistet, sondern über seinen Heartbeat und die Zustellungs-Queue überwacht. Jeder Worker (Jobs, E-Mails, Webhooks, SMS, Reservierungs-Bereinigung) schreibt am Ende seines Laufs einen Heartbeat (Optionen `wpcap_heartbeat_<worker>`, Zeit und Anzahl verarbeiteter Einträge). Ein Heartbeat älter als vier Intervalle (20 Minuten bei den Fünf-Minuten-Queues, 4 Stunden bei der Bereinigung) gilt als veraltet; mit `DISABLE_WP_CRON` wird dann ausdrücklich auf den fehlenden externen Cron-Job hingewiesen. Nicht geplante oder um mehr als 10 Minuten überfällige Ereignisse werden ebenfalls markiert.
- **Warteschlangen:** offene Einträge nach Status für Job-Queue (`wpcap_jobs`, zusätzlich nach Job-Typ), E-Mail-Queue, Webhook-Zustellungen und SMS-Queue; überfällige Einträge (fällig seit über 30 Minuten) und lang laufende Claims (`locked_at` älter als 5 Minuten). Die Worker geben Claims nach 15 Minuten selbst zurück – ältere Claims bedeuten, dass seither kein Worker lief.
- **E-Mail-Versandfehler:** die häufigsten `last_error`-Werte der E-Mail-Queue.
- **Fehlgeschlagene Einträge** (Jobs `dead_letter`, E-Mails/Webhooks/SMS `failed`) mit letztem Fehler und den Aktionen **„Erneut versuchen“** (Versuche zurückgesetzt, sofort fällig; Webhooks über die reguläre Neuzustellung) und **„Verwerfen“** (bleibt als `cancelled` bzw. `skipped` in der Historie). Beide Aktionen prüfen Nonce und Capability, wirken nur auf Einträge, die noch fehlgeschlagen sind (kein doppeltes Einreihen), und landen im Audit-Log (`ops_queue_retry`, `ops_queue_discard`). E-Mail-Empfänger werden maskiert angezeigt.
  **„Alle erneut versuchen“** je Warteschlange reiht die fehlgeschlagenen Einträge (älteste zuerst) wie einzeln neu
  ein – höchstens 100 pro Klick (Filter `wpcap_ops_bulk_retry_cap`, 1–500); der Hinweis nennt die noch
  verbleibenden. Nonce je Warteschlange, `manage_options`, Audit je Eintrag plus Zusammenfassung
  (`ops_queue_retry_bulk`).
- **Erstattungen zur manuellen Prüfung:** Erstattungen, die seit über 15 Minuten in `processing` ohne Anbieter-Referenz stehen (Abbruch während des Anbieteraufrufs – unklar, ob ausgezahlt wurde). Im Konto des Zahlungsanbieters prüfen, dann **„Manuell als erledigt markieren“** (optional mit Anbieter-Referenz; die Erstattung wird ohne erneuten Anbieteraufruf verbucht, Tickets werden widerrufen) oder **„Als fehlgeschlagen markieren“** (nichts ausgezahlt; die Erstattung kann aus der Bestellung erneut ausgeführt werden). Audit-Eintrag `refund_manual_resolution`.
- **Datenbankschema:** aktueller Stand, Fehlerdetails, Zahl der Fehlversuche und nächster automatischer Versuch; „Jetzt erneut versuchen“.

Das **Dashboard-Widget** „Pass Captain: Systemstatus“ (nur für `manage_options`) listet die kritischen Probleme
(dieselbe zwischengespeicherte Liste wie der Hinweis) mit Link zum Systemstatus.

Solange kritische Probleme bestehen, erscheint auf den Plugin-Seiten und im Dashboard ein **Admin-Hinweis** (für `manage_options`, 5 Minuten zwischengespeichert, nach jeder Aktion neu berechnet) und der Menüpunkt zeigt ihre Anzahl.

**Diagnose und Abgleich** („Diagnose ausführen“ auf der Statusseite oder `wp pass-captain diagnose`) prüft auf inkonsistente Daten und zeigt je Prüfung Anzahl, Beispiele und die empfohlene Maßnahme. Die Prüfung selbst ändert nichts (Vorschau); Reparaturen gibt es nur, wo sie gefahrlos wiederholbar sind, und jede wird im Audit-Log (`ops_diagnostics_repair`) protokolliert:

| Prüfung | Automatische Reparatur |
| --- | --- |
| Abgelaufene, aber offene Reservierungen, Sitzplatz-Holds und offene Bestellungen (älter als 65 Minuten) | Ja: Kontingente freigeben, Bestellungen ablaufen lassen (wie die stündliche Bereinigung) |
| Bezahlte Bestellungen ohne (alle) Tickets (älter als 10 Minuten) | Ja: je Bestellung ein `ticket_issue`-Job (nicht, solange einer offen ist); erzeugt nur fehlende Tickets |
| Gültige Tickets ohne gültige (bezahlte) Bestellung | Nein: Bestellung prüfen, Tickets widerrufen |
| Doppelte Erstattungen zur selben Anbieter-Referenz | Nein: beim Anbieter prüfen, manuell korrigieren |
| Hängende Claims (Jobs, E-Mails, Webhooks, SMS, älter als 15 Minuten) | Ja: zurück in die Queue |
| Ausgezahlte, aber nicht verbuchte Erstattungen | Ja: `Refunds::reconcile()` (kein Anbieteraufruf) |
| Erstattungen in `processing` ohne Anbieter-Referenz | Nein: manuelle Entscheidung auf der Statusseite |
| Schemafehler / nicht abgeschlossene Migration | Ja: Upgrade sofort wiederholen |

WP-CLI:

```sh
wp pass-captain status [--format=json] [--strict]          # Queues, Cron-Health, Probleme
wp pass-captain diagnose [--format=json] [--strict]        # alle Prüfungen (nur lesen)
wp pass-captain diagnose --repair=stale_claims,expired_holds
wp pass-captain diagnose --repair-all                      # alle sicheren Reparaturen
```

Mit `--strict` endet der Befehl mit Exit-Code 1, sobald ein kritisches Problem (status) bzw. ein Befund (diagnose) vorliegt – geeignet für einen Monitoring-Check oder eine Cron-Mail.

**Worauf alarmieren** (z. B. `wp pass-captain status --strict` alle 15 Minuten):

- Kein Worker-Heartbeat für Jobs oder E-Mails seit über 20 Minuten, insbesondere mit `DISABLE_WP_CRON` → externer Cron läuft nicht; Ticketmails und Erinnerungen bleiben liegen.
- Überfällige Einträge (fällig seit über 30 Minuten) in Job- oder E-Mail-Queue → Worker kommen nicht nach oder brechen ab (PHP-Fehlerprotokoll, `max_execution_time`).
- Neue `dead_letter`-Jobs oder `failed`-E-Mails → meist Mailversand (SMTP, Absender, Limits) oder Ticketzustellung (`ticket_delivery`) gescheitert.
- Ausgezahlte, aber nicht verbuchte oder in `processing` hängende Erstattungen → Geld und Buchung laufen auseinander.
- `wpcap_schema_error` gesetzt → Migration fehlgeschlagen.
- Befunde der Diagnose „Bezahlte Bestellungen ohne Tickets“ oder „Gültige Tickets ohne gültige Bestellung“ (z. B. täglich `wp pass-captain diagnose --strict`).

Fehlgeschlagene Webhook-Zustellungen sind nur eine Warnung (externes Zielsystem); sie werden nach wiederholten Fehlern ohnehin automatisch deaktiviert.

## Multisite: Netzwerkübersicht und Isolation

- Bei Netzwerkaktivierung gibt es in der Netzwerkverwaltung die Seite *Pass Captain* (Capability `manage_network_options`): je Site Events, Bestellungen, bezahlte Bestellungen und Umsatz der letzten 30 Tage (abzüglich Erstattungen, je Währung) sowie der Zustand aus dem Systemstatus (Fehler/Warnungen, Link zur Statusseite der Site).
- Der Netzwerkbericht (CSV, eine Zeile je Site und Währung) wird auf der Hauptsite protokolliert (`network_report_exported`) und fällt unter die 2FA-Kategorie „Exporte“.
- Isolation: jede Zahl wird innerhalb von `switch_to_blog()` mit dem Präfix der Site gelesen; Tabellen verschiedener Sites werden nie gemeinsam abgefragt. Sites ohne Plugin-Tabellen erscheinen als „nicht installiert“. Große Netzwerke können die Site-Liste mit dem Filter `wpcap_network_site_ids` begrenzen.

## Multisite: Site löschen und Plugin deinstallieren

- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben; Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites.

## Release-Build

Der ZIP-Build (`bash build/release.sh`) exportiert den committeten Stand per `git archive` (Standard `HEAD`,
anders mit `WPCAP_BUILD_REF=<ref>`), installiert die Composer-Abhängigkeiten ohne Dev-Pakete und lässt alles
weg, was in `.distignore` steht (Tests, Doku, Markdown außer `readme.txt`, `.po`-Quellen, Test-/CI-Dateien der
Abhängigkeiten). Nicht committete Änderungen landen nie im Paket. Der Build ist reproduzierbar; mit dem Release
wird `pass-captain.zip.sha256` veröffentlicht (Prüfung und Nachbau:
[COMPATIBILITY.md](COMPATIBILITY.md#release-artefakt-prüfen)). Freigabe-Gates und Abnahme (Upgrade/Restore
mit realistischen Datenständen, doppelte Webhooks, E-Mail-Ausfälle, hohe Scanrate, Multisite):
[RELEASE.md](RELEASE.md).

## Webhooks der Zahlungsanbieter einrichten

Pass Captain wertet nur die Ereignisse aus, die beim Anbieter für den Webhook-Endpunkt abonniert sind. Fehlt ein Ereignis, bleiben z. B. Dashboard-Erstattungen oder Chargebacks unverbucht und die Tickets gültig.

| Anbieter | Zu abonnierende Ereignisse |
|---|---|
| Stripe | `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`, `charge.dispute.closed` |
| PayPal | `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DECLINED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`, `CUSTOMER.DISPUTE.CREATED` |
| Mollie | keine Auswahl nötig: Mollie ruft den Webhook bei jeder Statusänderung einer Zahlung auf (inkl. Erstattung und Chargeback) |

Erstattungen und Chargebacks, die beim Anbieter ausgelöst werden, verbucht Pass Captain über `Refunds::record_external()` (Gutschrift, Abrechnungsabzug, Fiskal-Korrekturaufgabe). Ist unter der Bestellsperre kein Betrag (und kein Ticket) mehr offen, wird nichts geschrieben (keine Erstattung über 0,00).

Lehnt der Anbieter eine im Plugin ausgelöste Erstattung endgültig ab (z. B. Stripe „Insufficient available balance“, HTTP 4xx), steht sie auf „fehlgeschlagen“; nach Behebung der Ursache (Guthaben aufgeladen) einfach erneut ausführen – der nächste Versuch nutzt einen neuen Idempotenzschlüssel (`wpcap-refund-{id}-{n}`). Bei Zeitüberschreitung oder 5xx bleibt der Schlüssel gleich, damit eine womöglich doch ausgeführte Erstattung nicht doppelt ausgezahlt wird. Eine eröffnete Zahlungsanfechtung (Dispute) erzeugt eine Admin-Mail; gebucht wird erst, wenn das Geld tatsächlich abgezogen wird.

## Kasse: keine Registrierkasse

Die Kasse (Menü **Pass Captain → Kasse**) bucht Verkäufe vor Ort als Bestellungen, erzeugt Belege mit fortlaufender Nummer und einen Kassenabschluss. Sie ist **keine** Registrierkasse im fiskalrechtlichen Sinn:

- **Österreich (RKSV):** Bareinnahmen (dazu zählen auch Karten- und Wallet-Zahlungen vor Ort) erfordern ab den gesetzlichen Umsatzgrenzen eine Registrierkasse mit Sicherheitseinrichtung (Signaturerstellungseinheit), registriert bei FinanzOnline, inkl. Start-, Monats- und Jahresbeleg.
- **Deutschland (KassenSichV, § 146a AO):** Elektronische Aufzeichnungssysteme mit Kassenfunktion benötigen eine zertifizierte technische Sicherheitseinrichtung (TSE, BSI TR-03153) und sind dem Finanzamt zu melden; Belege müssen die TSE-Daten enthalten.

Die Kasse des Plugins signiert selbst keine Belege und hat keine eigene TSE-/Signaturkarten-Anbindung. Optional kann das Modul **Fiskalisierung** (Pass Captain → Fiskalisierung, [fiscalization-adapters.md](fiscalization-adapters.md)) jeden Verkauf vor der Ticketausgabe über einen vom Betreiber gewählten Anbieter (fiskaly, efsta, fiskaltrust) belegen lassen: je Rechtsträger ein Länder-/Kanalprofil mit Prüfvermerk, Kassenregister unter „Kassen- und Belegeinstellungen“ (`Standort-ID|Kassen-ID|Anzeigename`); solange der Anbieter den Beleg nicht bestätigt, bleibt der Verkauf offen (keine Bonnummer, keine Tickets, „Nachbuchen“ nach Behebung). Das Modul ist kein zertifiziertes Produkt; Anbietervertrag, Sandbox-Abnahme und rechtliche Prüfung liegen beim Betreiber. Für Bar- und Kartenzahlungen vor Ort deshalb eine zertifizierte Kasse verwenden und das Plugin nur für Ticketausgabe und Einlass nutzen, oder die Rechtslage mit der Steuerberatung klären. Der Hinweis auf der Kassenseite kann pro Benutzer ausgeblendet werden; der Link lässt sich über den Filter `wpcap_boxoffice_fiscal_docs_url` ändern.

### Erstattungen von Kassenverkäufen und Kassenbestand

- **Storno** (Kasse, nur bis zum Kassenabschluss): mindert die Sitzung des Verkaufs um den Betrag der Storno-Erstattung (nach einer Teilerstattung nur den Rest).
- **Erstattung über die Erstattungsseite** (z. B. ein Ticket eines Kassenverkaufs): wird einer Kassensitzung zugeordnet und mindert dort Netto-Umsatz der Zahlart und – bei Bar – den erwarteten Kassenbestand. Die Sitzungsübersicht zeigt die Spalte „Erstattet“ und die einzelnen Erstattungen mit Belegnummer.
  - Ist die Sitzung des Verkaufs noch offen, wird die Erstattung dort verbucht.
  - Ist sie bereits abgeschlossen, wird die Erstattung in der **aktuell offenen Sitzung desselben Kassenregisters** (gleiche Standort- und Kassen-ID) verbucht – das Bargeld wird also aus dieser Kasse ausgezahlt. Der Abschluss der alten Sitzung bleibt unverändert.
  - Gibt es keine offene Sitzung dieses Registers (oder hatte der Verkauf kein Register), wird die Erstattung ohne Sitzung erfasst (Audit `pos_refund_unassigned`). Das Bargeld dann außerhalb der Kasse (z. B. aus dem Tresor, per Überweisung) auszahlen und im Kassenbuch vermerken; der erwartete Bestand keiner Sitzung ändert sich.
- Erstattungen von Karten- und Terminalverkäufen erscheinen ebenfalls unter „Erstattet“, ändern aber den Bargeldbestand nicht.

### Kartenterminal meldet einen anderen Betrag

Meldet ein Terminal-Add-on (Vertrag 1.1) einen belasteten Betrag oder eine Währung, die nicht zur Bestellung passt, wird der Verkauf nicht gebucht (`amount_mismatch`, Bestellung storniert) und die Admin-Adresse erhält die Mail „Terminalzahlung prüfen“. Die Belastung am Terminal erstatten und den Verkauf neu erfassen.

## Datenschutz: Löschfristen, Auskunft und Löschung

**Täglicher Löschlauf** (`wpcap_privacy_retention`, WordPress-Cron, Stapel zu 500 Zeilen, Zeitbudget 20 s; der nächste Lauf setzt fort). Bezugspunkt ist das Terminende (`ends_at`, sonst Beginn + 1 Tag):

| Daten | Frist | Wirkung |
|---|---|---|
| Teilnehmerangaben (Zusatzfelder) | Einstellung „Teilnehmerdaten nach dem Event löschen nach“; bei 0 die Frist des Felds | gelöscht |
| Teilnehmername/-E-Mail (Zuordnungen, Tickets), Namen in Offline-Paketen | Einstellung; bei 0 365 Tage (`wpcap_attendee_default_retention_days`) | geleert; Wallet-Pässe werden aktualisiert (Google: Name entfernt, Apple: Push, das Gerät lädt den Pass ohne Namen); Ticket, Bestellung und Einlassprotokoll bleiben |
| Apple-Wallet-Geräteregistrierungen | wie Teilnehmername + 1 Tag (nach dem Push) | gelöscht |
| Noch nicht zugeordnete Teilnehmerangaben bezahlter Bestellungen (`attendee_data`) | letzter Termin der Bestellung + Teilnehmerfrist | geleert |
| Teilnehmerangaben unbezahlter (abgelaufener/stornierter) Bestellungen | 1 Tag | `attendee_data` geleert |
| Käufer-E-Mail, Benutzerverknüpfung und Rechnungsanschrift nie bezahlter Bestellungen (ohne Ticket, ohne Rechnung) | 90 Tage | E-Mail durch Platzhalter `deleted+…@invalid.example` ersetzt, `billing` geleert |
| Käuferdaten aller Bestellungen nach der gesetzlichen Frist | Einstellung „Käuferdaten in Bestellungen entfernen nach“ (Standard 10 Jahre ab Ende des Bestelljahres; AT: 7 Jahre genügen) | E-Mail (Platzhalter), Benutzerverknüpfung, Bestell-Link, `billing`, `attendee_data` der Bestellung; Käufer-E-Mail und Teilnehmerdaten der Ticketzuordnungen; E-Mail und Verwaltungslink der Terminbuchungen. **Nicht verändert:** Beträge, Positionen, Erstattungen, Rechnungen (samt `buyer_snapshot`), Gutschriften, Kassen- und Fiskalbelege |
| Event- und Termin-Wartelisten | Einstellung „Wartelisteneinträge … anonymisieren nach“ (Standard 30 Tage) nach Terminende bzw. Zeitfensterende; Einträge ohne Termin nach dem letzten Termin des Events | E-Mail durch Platzhalter ersetzt, Tokens geleert, offene Einträge `expired` |
| Unbestätigte Wartelisten-Anmeldungen (Double-Opt-in, beide Wartelisten) | sobald der Bestätigungslink abgelaufen ist (48 Stunden) | Eintrag gelöscht (Schritt `unconfirmed_waitlists`) |
| Terminbuchungen, die abgelaufen, storniert oder abgelehnt sind | 90 Tage (`service_holds`) | E-Mail durch Platzhalter ersetzt, Verwaltungslink ungültig |
| Terminbuchungen ohne Bestellung (Passbuchungen) | Zeitfensterende + Teilnehmerfrist | wie oben |
| Mitgliedspässe | Einstellung „Mitgliedspässe … anonymisieren nach“ (Standard 365 Tage) nach `valid_until` | E-Mail durch Platzhalter ersetzt, Passcode ungültig |
| Versandte/fehlgeschlagene Nachrichten der Warteschlange | 30 Tage / 365 Tage | Empfänger und Inhalt geleert / Zeile gelöscht |
| Abgeschlossene SMS (`sms_messages`) | 30 Tage (`sms_content`) | Text und Fehlermeldung geleert |
| Einlassprotokoll, Einlassbuchungen (`admission_entries`) und Einlasskonflikte: Mitarbeitende und Gerät | 365 Tage (`checkin_staff`) | `user_id`/`device_id` (bei Konflikten auch Erst-Scan, Bearbeiter und Notiz) = 0/leer; Zeit, Ergebnis, Ticket bleiben |
| Bezeichnungen widerrufener/abgelaufener Scanner-Geräte und abgelaufener Kopplungscodes | 365 Tage (`checkin_staff`) | `label` geleert |
| Audit-Log | 730 Tage | gelöscht |
| Abgeschlossene Reservierungen (umgewandelt/abgelaufen/freigegeben) von Bestellungen, die nicht mehr auf Zahlung warten | 30 Tage nach Ablauf | gelöscht (Housekeeping) |
| Erledigte/abgebrochene Hintergrundjobs (`complete`/`cancelled`; Dead Letters bleiben) | 30 Tage | gelöscht (Housekeeping) |
| Abgelaufene Rate-Limit-Zähler (`wpcap_rate_limits`, nur HMAC-Schlüssel, keine Adressen) | nach Fensterende, beim nächsten Lauf (`rate_limits`, nicht abschaltbar) | gelöscht in Stapeln (`DELETE … LIMIT`, Index `expires_at`) |

Die übrigen Fristen lassen sich mit `wpcap_privacy_retention_days` ändern oder abschalten (siehe [HOOKS.md](HOOKS.md)). Das Audit-Log erhält je Lauf nur die Anzahlen (`privacy_retention`). Nicht erfasst: Rechnungen, Gutschriften, Kassen- und Fiskalbelege (gesetzliche Aufbewahrung; nach Fristablauf, unter Berücksichtigung laufender Prüfungen, löschen Sie diese selbst), Kassensitzungen der Abendkasse samt Notizen (Kassenbuch), Bestellungen in WooCommerce (eigene Fristen des Shops; die Pass-Captain-Teilnehmerkopie `_wpcap_attendees` wird bei stornierten Bestellungen sofort, bei fehlgeschlagenen nach 7 Tagen entfernt), Daten bei Zahlungsanbietern, Partnern und Webhook-Empfängern. Webhook-Zustellungen haben eine eigene Frist (Modul Webhooks); ausstehende Zustellungen deaktivierter Webhooks werden nach dieser Frist ohne personenbezogene Daten abgebrochen.

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Benutzerverknüpfung, Erstattungen samt Grund, Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste, Terminbuchungen (mit Stornogrund), E-Mail-Benachrichtigungen (Vorlage, Status, Zeit), SMS samt Text sowie Ansprechpartner-Angaben bei Partnern und Verkäufern. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Käufer erhalten die Angaben, die sie selbst eingegeben haben (Name, Zusatzfelder), aber keine Einwilligungen anderer Personen: Einwilligungsnachweise gehören der Person, die einwilligt (bzw. für die eingewilligt wurde), und werden nur mit deren Anfrage exportiert oder gelöscht. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten, SMS-Texte, Verwaltungslinks, Benutzerverknüpfung, Ansprechpartner-Angaben und Terminbuchungen, aktualisiert Wallet-Pässe ohne Namen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Push-Nachricht) – auch wenn nur die Teilnehmerangaben einer Person aus fremden Bestellungen entfernt werden. Gültige Mitgliedspässe werden wie Bestellungen mit kommenden Terminen bis zum Ende der Gültigkeit zurückgestellt und danach automatisch anonymisiert. Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Hat die Person Tickets für kommende Termine, weist die Löschung darauf hin; Erstattung oder Storno müssen Sie selbst veranlassen.

**Pflichten des Betreibers:**

- WordPress-Cron zuverlässig auslösen (siehe oben), sonst greifen die Fristen nicht.
- Den Vorschlag unter **Einstellungen → Datenschutz → Leitfaden** prüfen, an Verantwortlichen, Verträge und Abläufe anpassen und in die Datenschutzerklärung übernehmen; nach Aktivieren weiterer Dienste (Zahlungsanbieter, SMS, Wallet, Partner, Webhooks, Affiliate) erneut prüfen.
- Auftragsverarbeitungsverträge mit Hosting, E-Mail-Versand, SMS-Anbieter und gegebenenfalls Partnern bzw. Webhook-Empfängern schließen; Übermittlungen in die USA (Stripe, PayPal, Twilio, Apple, Google) dokumentieren.
- Affiliate-Tracking: Mit dem Plugin „WP Consent API“ und einem kompatiblen Cookie-Banner wird der Empfehlungs-Cookie nur mit Einwilligung „marketing“ ausgewertet; der Partnerlink der laufenden Shop-Sitzung (ohne Marketing-Cookie) zählt auch ohne diese Einwilligung. Ohne Consent-API gilt der Cookie als nicht eingewilligt und wird nicht ausgewertet; blockiert Ihr Cookie-Banner den Affiliate-Cookie bis zur Einwilligung, binden Sie den Filter `wpcap_affiliate_tracking_allowed` an Ihren Consent-Manager an (oder geben ihn frei).
- SMS-Einwilligung: Die Mobilnummer wird im Checkout ohne Bestätigungscode (kein Double-Opt-in) gespeichert. Für SMS mit reinem Vertragsbezug (Absage, Verschiebung) ist das vertretbar; wer weitere SMS-Zwecke ergänzt, sollte eine Verifizierung per Code vorsehen (offen, siehe Audit PRIV-16).
- Rechnungen enthalten die Käufer-E-Mail im `buyer_snapshot` (bleibt bis zur manuellen Löschung nach Ablauf der Aufbewahrungsfrist). Ob die E-Mail auf einer Rechnung zwingend ist, ist eine Rechtsfrage (Pflichtangaben nach § 14 UStG / § 11 UStG-AT verlangen sie nicht); eine Option, sie künftig in einem getrennten, löschbaren Feld zu führen, ist offen (Audit PRIV-9).
- Web-Scanner auf geteilten Geräten: vor Schichtwechsel synchronisieren, nach Schichtende Browser schließen bzw. Website-Daten löschen (Offline-Scans liegen bis zu 24 Stunden im Browser).
- Gesetzliche Aufbewahrung von Rechnungen und Buchungsdaten (7 Jahre § 132 BAO, 10 Jahre § 147 AO/§ 257 HGB) überwachen und danach manuell löschen; das Plugin entfernt nach der eingestellten Frist nur die Käuferdaten aus Bestellungen, Tickets und Terminbuchungen, nicht Rechnungen, Gutschriften oder Fiskalbelege. In Österreich kann die Einstellung auf 7 Jahre gesenkt werden; laufende Außenprüfungen oder Verfahren verlängern die Frist (dann vorübergehend erhöhen).
- „Beim Löschen des Plugins alle Daten entfernen“ löscht auch Rechnungen, Gutschriften, Kassen- und Fiskalbelege und lässt sich nur mit der Eingabe „LÖSCHEN“ aktivieren: vorher Rechnungen und das Fiskal-Archiv exportieren.
- Partner-API-Schlüssel mit `attendees:read` nur mit Rechtsgrundlage vergeben; gelöschte Adressen liefert die API als `null`. Abrufe von Bestellungen und Tickets mit E-Mail-Adressen werden im Audit-Log protokolliert (`partner_api_pii_read`: Schlüssel, Route, Seite, Anzahl; höchstens einmal je Schlüssel, Route und Seite pro Stunde).
- Support-Rolle: E-Mail-Adressen auf Wartelisten (Bildschirm und CSV) und in Terminbuchungen werden wie in Bestellungen maskiert (Richtlinie „Support sieht personenbezogene Daten maskiert“).
