# Betrieb und Wiederherstellung

## Vor einem Upgrade

1. Upgrade zuerst in einer Staging-Site mit derselben PHP-, WordPress-, Datenbank- und Integrationsversion einspielen.
2. Dateien und vollständige WordPress-Datenbank unmittelbar vor dem Upgrade sichern. Die Präfix-Tabellen `*_wpcap_*` enthalten Aufträge, Reservierungen, Tickets, Einlassprotokolle und weitere Plugin-Daten.
3. Sicherungszeitpunkt, Plugin-Version und Schema-Version notieren. Die Migration v3 nutzt `dbDelta` und ergänzt Tabellen und Spalten; sie löscht keine Plugin-Tabellen.
4. Nach dem Upgrade Datenbankverbindung, öffentliche Events, Checkout, Ticketmail, Check-in und geplante Jobs in der Staging-Site prüfen.

## Datenbank-Anforderungen

InnoDB mit `utf8mb4`. Jede Indexspalte bleibt unter 767 Byte (höchstens 191 Zeichen, sonst Präfixindex), zusammengesetzte Indizes unter 3072 Byte – das Schema ist damit auch ohne `innodb_large_prefix` anlegbar. Ein Test prüft das für alle Tabellen.

## Datenbank- und PHP-Freigaben

Freigegeben ist nur, was die CI bei jedem Push mit der vollständigen Integrationssuite und dem Last-/Nebenläufigkeitstest (`tests/load/run.php --profile=ci`) prüft (`.github/workflows/ci.yml`, Job `integration`):

| PHP | Datenbank | Rolle |
| --- | --- | --- |
| 8.2 | MariaDB 10.6 | Minimum PHP + Minimum MariaDB |
| 8.2 | MySQL 8.0 | Minimum PHP + Minimum MySQL |
| 8.3 | MariaDB 10.11 | LTS |
| 8.3 | MySQL 8.4 | LTS |
| 8.4 | MariaDB 11.4 | LTS |
| 8.4 | MySQL 8.4 | LTS |
| 8.5 | MariaDB 11.8 | neueste PHP- und MariaDB-LTS |

- **Unterstützt:** PHP 8.2–8.5, MySQL 8.0 und 8.4, MariaDB 10.6 bis 11.8. Ältere Versionen (MySQL 5.7, MariaDB < 10.6) werden nicht getestet und sind nicht freigegeben, auch wenn das Schema dort anlegbar wäre; Transaktionserkennung (`@@in_transaction` bzw. Savepoint-Probe), Sperrverhalten und Deadlock-Wiederholungen sind nur auf den getesteten Versionen abgesichert.
- **Echte WordPress-/WooCommerce-Installationen** prüft zusätzlich `.github/workflows/wp-matrix.yml` (WordPress 6.4/6.8/aktuell, WooCommerce 8.2.0/aktuell × HPOS/Beitragsspeicher, Release-ZIP in frischer Installation) gegen MariaDB 11.4; wöchentliche Lastgrenzen gegen MariaDB und MySQL in `load.yml`.
- **Pro Release** wird die Matrix des Release-Commits im Abnahmeprotokoll verlinkt (`docs/release-acceptance/`); eine neue Datenbank- oder PHP-Version gilt erst als freigegeben, wenn sie in diese Matrix aufgenommen wurde und grün ist.

## Schema-Upgrade nach einem Update

Das Datenbank-Upgrade (`dbDelta` für alle Kern- und Modultabellen) läuft nicht mehr bei jedem Seitenaufruf:

- **Admin-Aufrufe** (`admin_init`), **WP-Cron** und **WP-CLI** vergleichen die gespeicherte Schema-Version (`wpcap_db_version`, Kernversion plus Hash der Modul-Schemata) und führen das Upgrade aus. Es läuft hinter einer Datenbanksperre (`GET_LOCK`, je Site); parallele Aufrufe ohne Sperre überspringen es und arbeiten mit dem bisherigen Schema weiter. Die Sperre endet automatisch, wenn der Prozess abbricht.
- **Frontend- und REST-Aufrufe** prüfen nur einen günstigen Fingerabdruck (Schema-/Plugin-Version und Modulliste, Option `wpcap_schema_fingerprint`). Weicht er ab, wird einmalig das Cron-Ereignis `wpcap_run_upgrade` eingeplant. Nur wenn dieses Ereignis länger als fünf Minuten überfällig ist (WP-Cron läuft nicht), übernimmt ein einzelner Frontend-Aufruf das Upgrade hinter derselben Sperre.
- Bis zum Upgrade läuft der neue Code gegen das alte Schema. Das ist unkritisch, weil Migrationen nur Tabellen, Spalten und Indizes ergänzen; nach einem Update trotzdem zeitnah den Admin-Bereich öffnen oder `wp cron event run wpcap_run_upgrade` ausführen.
- `dbDelta` ergänzt nur. Was es nicht kann, erledigt das Upgrade selbst (Schema 3.10.4, `Installer::upgrade_indexes()`): Ein Index, dessen Spalten, Präfixlänge oder Eindeutigkeit sich geändert hat, wird in einem einzigen `ALTER TABLE` gelöscht und neu angelegt (schlägt das fehl, z. B. weil ein neuer eindeutiger Index an doppelten Daten scheitert, bleibt der alte Index unverändert); entfernte Indizes (`Installer::OBSOLETE_INDEXES`, bisher `wpcap_order_items.type_order`, abgelöst durch `capacity_sum`) werden gelöscht. Von Betreibern selbst angelegte Indizes bleiben unberührt. Änderungen von NULL/NOT NULL übernehmen die Datenmigrationen der Module (z. B. Kasse).
- Bricht ein Upgrade ab (Fehler, Zeitüberschreitung, Prozessende), bleibt `wpcap_db_version` auf der alten Version und die Sperre wird freigegeben; der nächste Admin-/Cron-Aufruf setzt es fort. Alle Schritte sind wiederholbar, einmalige Datenmigrationen laufen trotzdem nur einmal (geprüft in `tests/upgrade/run.php`, siehe TESTING.md).

## Datenbankfehler: Deadlock, Lock-Timeout, Verbindungsabbruch (A183)

Checkout, Zahlungsbestätigung (Webhook, manuelle Zahlung, Warteliste), Ticketausgabe, Check-in und
Erstattung prüfen jedes Statement ihrer Transaktion. Scheitert eines (Deadlock 1213, Lock-Wartezeit 1205,
Verbindungsabbruch 2006/2013, gescheitertes COMMIT), wird zurückgerollt und der Aufrufer erhält einen
wiederholbaren Fehler (HTTP 503, `retryable: true`; Zahlungsanbieter erhalten 5xx und stellen erneut zu) –
nie „ausverkauft“, „nicht gefunden“ oder „Ticket bereits verwendet“. Wiederholungen sind idempotent
(Idempotenzschlüssel, erneute Zustellung). Trifft eine Zustellung eines Zahlungsereignisses ein, während
dasselbe Ereignis noch verarbeitet wird (oder eine abgebrochene Verarbeitung es noch hält), antwortet das
Plugin mit 503 `wpcap_webhook_in_progress` statt vorzeitig mit „duplicate“; die spätere Zustellung findet
es erledigt bzw. übernimmt es nach 10 Minuten.

Sonderfall Verbindungsabbruch mit Wiederverbindung: WordPress' `wpdb` verbindet bei „MySQL server has
gone away“ still neu und wiederholt das Statement – ab dann laufen die Statements ohne Transaktion
(Autocommit), die vorherigen sind zurückgerollt. Die Pfade erkennen das am ersten Schreibzugriff ihrer
Transaktion (Bestellzeile, Zahlungsstatus, Ticketstatus bzw. Check-in-Zeile, erstes Ticket, Erstattung):
existiert er nach dem COMMIT-Versuch, ist alles ab dort geschrieben und der Vorgang vollständig; sonst
werden die ohne Transaktion geschriebenen Zeilen entfernt (Checkout: Bestellung, Positionen,
Reservierungen – Hook `wpcap_capacity_released` mit `checkout_aborted`; Ticketausgabe: Tickets,
Teilnehmerdaten – Hook `wpcap_tickets_discarded`; Check-in: Protokollzeile und Anwesenheit) und die
Kontingentzähler der betroffenen Termine aus den Quelltabellen neu berechnet. Ein COMMIT, das `wpdb` auf
der neuen Sitzung wiederholt, meldet Erfolg, ohne etwas zu schreiben – deshalb wird nach jedem COMMIT
einmal per Primärschlüssel nachgesehen. Erstattungen verbuchen Tickets einzeln idempotent
(Ticket-Kennzeichen und erstattete Positionsmenge in einem Statement) und setzen Status „abgeschlossen“
und erstatteten Betrag gemeinsam erst, wenn alle Tickets verbucht sind; eine abgebrochene Verbuchung
bleibt „processing“ und wird von `repair_stuck()` (Cron) ohne zweite Auszahlung fertig gebucht.

Bekannte Zwischenzustände (werden automatisch aufgelöst): Eine bezahlte Bestellung, deren Ticketausgabe
scheiterte, bekommt ihre Tickets beim nächsten Versuch – erneute Webhook-Zustellung, gleicher
Checkout-Schlüssel, erneute Bestätigung – und spätestens über den Job `ticket_issue_retry` (nach einer
Minute, mit Backoff bis zum Dead-Letter). Lief nach einer Wiederverbindung nur die Umwandlung der
Reservierung, ist die Bestellung wie eine mit abgelaufener Reservierung: die erneut zugestellte Zahlung
wird als verspätete Zahlung mit Kontingentprüfung verbucht, ein Wartelistenangebot läuft ab und geht an
den nächsten Eintrag. Kontingentzähler sind nie niedriger als die Summen aus Bestellungen und
Reservierungen (kein Überverkauf); eine Abweichung nach oben korrigiert spätestens der Abgleich im Cron.

## Hintergrundverarbeitung (WP-Cron)

E-Mail-Warteschlange (`wpcap_process_messages`, zusammen mit SMS), Jobs und Webhooks (`wpcap_process_jobs`, `wpcap_webhooks_dispatch`) laufen alle fünf Minuten. Jeder Lauf arbeitet mit einem gemeinsamen **Zeitbudget von 20 Sekunden** (höchstens `max_execution_time` − 15 s; Filter `wpcap_cron_time_budget`) und bis zu 500 E-Mails (Filter `wpcap_message_batch_size`). Das Budget wird vor jeder Entnahme geprüft, sodass kein Eintrag reserviert und dann liegen gelassen wird; bricht PHP während eines Eintrags ab, wird die Reservierung beim Beenden freigegeben. Bleibt ein Rückstand, plant der Lauf sofort einen Folgelauf ein – über Action Scheduler (`as_enqueue_async_action`, Gruppe `pass-captain`), wenn vorhanden (z. B. mit WooCommerce), sonst als einzelnes WP-Cron-Ereignis.

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

Prüfen, ob der externe Cronjob greift: Unter **Werkzeuge → Website-Zustand** meldet die Prüfung „geplante Aufgaben“ den letzten Lauf der Warteschlangen; mit `wp cron event list --fields=hook,next_run_relative | grep wpcap_` sind überfällige Ereignisse sofort zu sehen.

### Garantien bei Worker-Fehlern und Neustarts (A188)

| Fall | Verhalten |
|---|---|
| Zwei Worker gleichzeitig (überlappende Cron-Läufe, Action Scheduler, System-Cron) | Ein Job wird per Zeilensperre und bedingtem Statuswechsel genau einem Worker zugeteilt; der Handler läuft erst, wenn diese Zuteilung committet ist. Ist das Ergebnis des COMMIT unklar, läuft er nicht (die Zuteilung wird nach 15 Minuten zurückgeholt). Getestet mit vier parallelen Prozessen. |
| Handler scheitert (`WP_Error`, `false`, Exception) | Erneut eingeplant nach 2, 4, 8 … Minuten (`Jobs::backoff_seconds()`: 60 s · 2^Läufe, höchstens 1 Tag); letzter Fehler in `last_error` (eine Zeile, E-Mail-Adressen maskiert). |
| Endgültig gescheitert | Nach 8 Läufen (`Jobs::MAX_ATTEMPTS`) Status `dead_letter`: Audit `job_dead_letter`, Hook `wpcap_job_dead_letter`, Liste unter **Betrieb** (erneut versuchen/verwerfen) und Warnung im Website-Zustand. Ein Dead Letter läuft nie von selbst wieder. |
| Worker stirbt während eines Jobs (Fatal Error, Zeitlimit) | Die Zuteilung wird beim Beenden des PHP-Prozesses zurückgegeben – mit Backoff und dem Vermerk „Worker … beendet“ –, nicht sofort erneut gestartet. |
| Worker wird hart beendet (kill, Server-Neustart) | Nach 15 Minuten holt der nächste Lauf die Zuteilung zurück (Backoff, Vermerk „zurückgeholt“). Ein Job, dessen letzter erlaubter Lauf so endete, wird beim nächsten Lauf zum Dead Letter statt endlos neu zu starten. |
| Worker stirbt nach der Wirkung, vor „erledigt“ | Der Job läuft erneut: Ausführung ist **mindestens einmal**. Die eingebauten Jobs sind idempotent: `ticket_mail_retry` erkennt eine seit dem Einplanen erfolgreich versendete Ticket-Mail (Audit `tickets_resent`) und sendet nicht noch einmal (die versendeten Codes bleiben gültig); `ticket_issue_retry`, `order_cancel_retry`, `order_refund_mark_retry`, `voucher_commit` und `wallet_sync` prüfen den aktuellen Zustand. Eigene Handler (Hook `wpcap_process_job`) müssen ebenso idempotent sein; `$job_id` hilft, eine Wiederholung zu erkennen. Geht nur die Datenbankverbindung beim Abschluss verloren, wird der Abschluss einmal auf der neuen Verbindung wiederholt. |
| E-Mail-Warteschlange | Ebenfalls mindestens einmal: stirbt der Worker zwischen `wp_mail()` und dem Vermerk „gesendet“, wird die Mail erneut versendet (inhaltsgleich). |
| Webhook-Zustellungen | Mindestens einmal; Empfänger erkennen Wiederholungen an `X-WPCAP-Event-Id` bzw. `X-WPCAP-Delivery` (siehe [WEBHOOKS.md](WEBHOOKS.md)). |

## Betriebsüberwachung (Website-Zustand)

Das Plugin trägt eigene Prüfungen in **Werkzeuge → Website-Zustand** ein (Site Health, A164), Abzeichen „Pass Captain“. Ist eine Prüfung **kritisch**, zeigt jede Pass-Captain-Admin-Seite Administratorinnen zusätzlich einen Hinweis mit Link zur Betriebsübersicht (Ergebnis fünf Minuten zwischengespeichert, Transient `wpcap_health_critical`).

| Prüfung | empfohlen | kritisch |
|---|---|---|
| WP-Cron (`wpcap_cron`) | ein Plugin-Cron-Ereignis (z. B. `wpcap_process_jobs`, `wpcap_process_messages`, `wpcap_privacy_retention`) ist ≥ `cron_late_minutes` (15) überfällig; ein wiederkehrender Hook ist gar nicht eingeplant (Plugin einmal deaktivieren/aktivieren) | ein Ereignis ist ≥ `cron_critical_minutes` (60) überfällig; oder `DISABLE_WP_CRON` ist gesetzt und die Warteschlangen liefen seit ≥ 60 Minuten nicht (Heuristik: kein externer Cronjob) |
| Warteschlangen (`wpcap_queues`) | Jobs, E-Mails oder Webhook-Zustellungen: ≥ `dead_letters` (1) endgültig fehlgeschlagen, ≥ `backlog_items` (200) fällig oder ältester fälliger Eintrag ≥ `backlog_age_minutes` (30) | ≥ `dead_letters_critical` (25) fehlgeschlagen oder ältester fälliger Eintrag ≥ `backlog_critical_minutes` (120) |
| E-Mail (`wpcap_mail`) | ≥ `mail_failures` (1) fehlgeschlagene Plugin-Mails in `mail_window_hours` (24) | ≥ `mail_failures_critical` (10) |
| SMS (`wpcap_sms`, nur mit aktivem SMS-Modul und Anbieter) | ≥ `sms_failures` (1) SMS mit Status `failed`/`undelivered` in 24 h | ≥ `sms_failures_critical` (10) |
| Zahlungs-Webhooks (`wpcap_payment_webhooks`) | ein aktiver Zahlungsanbieter mit Webhooks (ohne `manual`) hatte in `payment_webhook_hours` (48) Bestellungen, aber keine Webhook-Benachrichtigung (Tabelle `wpcap_payment_events`) | – |

Alle Schwellwerte ändert der Filter `wpcap_health_thresholds` (Array, siehe [HOOKS.md](HOOKS.md)); `0` schaltet eine Anzahl-Schwelle ab:

```php
add_filter( 'wpcap_health_thresholds', static function ( $t ) {
	$t['cron_late_minutes']  = 30;  // Hosting mit 15-Minuten-Cron
	$t['dead_letters']       = 5;   // einzelne Fehlschläge nicht melden
	return $t;
} );
```

**Woher die Signale kommen:**

- *Cron-Lauf*: `wpcap_process_jobs` und `wpcap_process_messages` vermerken ihren Start in der Option `wpcap_ops_heartbeat` (höchstens ein Schreibzugriff pro Minute). Überfällige Ereignisse stammen aus der WP-Cron-Liste (`_get_cron_array()`).
- *Mail-Fehler*: Der zentrale Mailer (`Mail\Mailer::deliver()`, alle Kunden-Mails und die E-Mail-Warteschlange) zählt Fälle, in denen `wp_mail()` `false` liefert, in der Option `wpcap_ops_mail_failures` (7 Tage, höchstens 100 Einträge). Gespeichert werden nur Zeit, Mail-Typ, Fehlercode und die Fehlermeldung mit maskierten E-Mail-Adressen – keine Empfänger, keine Inhalte. Mails anderer Plugins zählen nicht.
- *SMS*: Status der Tabelle `wpcap_sms_messages` (Rückmeldungen des Anbieters).
- *Zahlungs-Webhooks*: Zeitpunkt des letzten Eintrags je Anbieter in `wpcap_payment_events`.

Der Reiter **Bericht** des Website-Zustands enthält den Abschnitt „Pass Captain – Betrieb“ (DISABLE_WP_CRON, letzter und nächster Lauf der Cron-Hooks, Einträge je Status und Rückstand der Warteschlangen, Mail-/SMS-Fehler, letzter Zahlungs-Webhook je Anbieter) – praktisch für Support-Anfragen.

### Betriebsübersicht: Rückstände und Dead Letters (A163)

**Pass Captain → Einstellungen & Integrationen → Betrieb** (nur `manage_options`) zeigt die Ergebnisse der Prüfungen und je Warteschlange – Hintergrundjobs (`wpcap_jobs`), E-Mail-Warteschlange (`wpcap_message_queue`), Webhook-Zustellungen (`wpcap_webhook_deliveries`, falls das Modul installiert ist) –:

- Einträge je Status, Anzahl fälliger Einträge und Wartezeit des ältesten,
- die endgültig fehlgeschlagenen Einträge (Jobs `dead_letter`, E-Mails und Webhooks `failed`, neueste 50) mit Typ, Versuchen, letztem Fehler (Secrets, Karten- und IBAN-Daten entfernt, E-Mail-Adressen maskiert, siehe SECURITY.md „Fehlerausgaben ohne Secrets“) und nur den **Feldnamen** des Inhalts (bei Webhooks die von `data.object`); E-Mail-Empfänger erscheinen maskiert. Inhalte, Betreffzeilen und Secrets werden nie angezeigt.

Aktionen je Zeile oder als Mehrfachaktion (ausgewählte oder alle fehlgeschlagenen Einträge, höchstens 500):

- **Erneut versuchen**: Status wieder wartend, Versuche 0, fällig sofort, letzter Fehler gelöscht; danach wird die Verarbeitung angestoßen (sofortiger Folgelauf über Action Scheduler bzw. WP-Cron, ohne dass die Admin-Seite selbst wartet). Webhooks laufen über die normale erneute Zustellung (personenbezogene Daten werden aus aktuellen Daten neu aufgebaut). E-Mails, deren Inhalt der Datenschutz-Löschlauf bereits entfernt hat, werden nicht erneut versendet.
- **Verwerfen**: Status `cancelled` (endgültig; wird mit den normalen Aufbewahrungsfristen entfernt).

Beide Aktionen sind nonce-geschützt und landen im Audit-Log (`operations_retry`, `operations_discard`, Objekttyp `queue_jobs`/`queue_messages`/`queue_webhooks`, Details: Warteschlange, Anzahl, IDs).

### Datenkonsistenz: Diagnose und Reparatur (A204)

`PassCaptain\Operations\Doctor` sucht widersprüchliche Daten. Die Prüfung ändert nichts. Reparaturen gibt es nur, wo der richtige Zustand eindeutig ist und der Plugin-Code ihn selbst hergestellt hätte. Jede Reparatur hat eine Vorschau (Dry Run, nichts wird geschrieben), und jede ausgeführte Reparatur wird auditiert.

| Prüfung (`id`) | Findet | Reparatur |
|---|---|---|
| `tickets_without_order` (kritisch) | gültige/eingecheckte Tickets, deren Bestellung fehlt | – (manuell prüfen) |
| `tickets_of_closed_orders` (kritisch) | gültige Tickets stornierter, erstatteter oder abgelaufener Bestellungen sowie erstattete (`refunded=1`), aber noch gültige Tickets | `revoke_closed_order_tickets`: sperrt sie (`Orders::revoke_ids()`, `wpcap_ticket_revoked`, Audit `doctor_tickets_revoked` je Bestellung) |
| `paid_orders_without_tickets` (kritisch) | bezahlte, abgeschlossene Bestellungen mit offenen Positionen ohne ein einziges Ticket, älter als 10 Minuten | – (Tickets in der Bestellung erneut senden) |
| `refunds_exceed_payment` (kritisch) | Summe der abgeschlossenen/laufenden Erstattungen größer als der bezahlte Betrag (`total_minor`, ohne Gutscheinanteil) | – |
| `duplicate_provider_refunds` (kritisch) | dieselbe Anbieter-Referenz (`provider_reference`) beim selben Zahlungsanbieter mehrfach verbucht | – |
| `stale_queue_claims` | Jobs `running`, E-Mails `sending`, Webhook-Zustellungen `delivering` seit über 15 Minuten | `requeue_stale_claims`: zurück auf wartend, Claim gelöscht (wie die Worker selbst; nur noch hängende Einträge) |
| `expired_holds` | aktive Ticket- und Sitzplatz-Reservierungen nach Ablauf, reservierte Servicebuchungen ohne Bestellung nach Ablauf, unbezahlte Bestellungen ohne gültige Reservierung | `release_expired_holds`: der Cron-Ablauf `Orders::expire_holds()` (Zähler, Bestellung `expired`, Kampagnen, Hooks) plus Sitzplatz- und Servicebuchungs-Reservierungen |
| `capacity_counter_drift` | Kontingentzähler ≠ Summe aus Bestellungen/Reservierungen (`CapacityCounters::drift()`) | `rebuild_counters`: `CapacityCounters::reconcile( true )` |
| `document_number_gaps` | Lücken in Rechnungs- und Korrekturnummern des laufenden und des Vorjahres (`Invoices::document_gaps()`) | – (Nummern werden nie nachträglich vergeben; Lücke dokumentieren) |
| `service_bookings` | aktive Buchungen beendeter oder fehlender Bestellungen; `pending_payment` bei bezahlter Bestellung; nach Ablauf der Reservierung bezahlt; „ohne Platz“ (`unallocated`) ohne Erstattung | `sync_service_bookings`: nur die eindeutigen Fälle – Buchungen beendeter Bestellungen freigeben (`ServiceBookings::release_order()`), Buchungen bezahlter Bestellungen mit noch gültiger Reservierung bestätigen (`confirm_paid_order()`). Die anderen beiden Fälle bleiben für eine Person. |
| `audit_gaps` | abgeschlossene Erstattungen ohne `refund_completed`, Rechnungen ohne `invoice_issued`, Korrekturen ohne `credit_note_issued` (nur innerhalb der Aufbewahrungsfrist des Audit-Logs, ab dem ältesten Eintrag) | – |

Die Funde sind nach Neuigkeit sortiert (neueste zuerst). Jeder Fund hat einen Schlüssel wie `ticket:12`, `order:5`, `jobs:7`, `booking:3`. Über diese Schlüssel lässt sich eine Reparatur eingrenzen. `release_expired_holds` und `rebuild_counters` arbeiten immer auf dem ganzen Bestand, wie der Cron. Eine Reparatur ändert höchstens 500 Objekte je Lauf. Sie prüft jede Bedingung unmittelbar vor der Änderung erneut, ein zweiter Lauf ändert deshalb nichts mehr.

**WP-CLI** (auch für das Monitoring: Exit-Code 1, sobald etwas gefunden wird):

```bash
wp pass-captain doctor                                   # alle Prüfungen, nur lesen
wp pass-captain doctor --check=expired_holds,stale_queue_claims --format=json
wp pass-captain doctor --repair=release_expired_holds    # Vorschau (Dry Run), nichts wird geändert
wp pass-captain doctor --repair=release_expired_holds --apply
wp pass-captain doctor --repair=revoke_closed_order_tickets --keys=ticket:12,ticket:13 --apply
```

**Admin**: Auf der Seite **Betrieb** gibt es den Abschnitt „Datenkonsistenz“. Die Prüfungen laufen nur auf Knopfdruck („Prüfung ausführen“), nicht bei jedem Seitenaufruf. Angezeigt werden Status, Anzahl, bis zu zehn Beispiele mit Hinweis sowie je Reparatur eine **Vorschau**. Aus der Vorschau startet ein Formular mit Nonce und Rückfrage die Reparatur. Nötig ist dafür `manage_options`.

**Audit**: Jede ausgeführte Reparatur schreibt `doctor_repair` (Objekttyp `system`, Details: `repair`, `source` `cli`/`admin`, Anzahl, bis zu 100 Schlüssel, Kennzahlen). Dazu kommen die Einträge der wiederverwendeten Abläufe (`doctor_tickets_revoked`, `service_booking_released`, `service_booking_confirmed`, `capacity_counter_drift`).

## SMS: Segmente, Kosten, Budget, Zustellstatus und Abmeldung (A141)

Einstellungen unter **Pass Captain → SMS**; Tabellen `wpcap_sms_consents`, `wpcap_sms_messages`, `wpcap_sms_optouts` (Schema 3.10.2, Prüfung `Sms\Service::ready()`; vor dem Schema-Upgrade läuft der Versand ohne Kostenerfassung und Sperrliste weiter).

- **Segmente**: `Sms\Segments::count()` zählt nach GSM 03.38. Texte nur aus dem GSM-7-Alphabet (inkl. Erweiterungstabelle `€ [ ] { } \ ^ ~ |`, deren Zeichen zwei Stellen belegen) passen mit 160 Zeichen in eine SMS, längere Texte werden in Teile zu 153 Zeichen zerlegt. Ein einziges anderes Zeichen (z. B. `ł`, `ő`, Emoji) schaltet die ganze Nachricht auf UCS-2: 70 Zeichen, sonst 67 je Teil (Emojis zählen doppelt). Escape-Sequenzen und Surrogatpaare werden nicht auf zwei Teile verteilt. Segmente und Kodierung stehen beim Einreihen an der Nachricht; die Admin-Seite zeigt sie für die Absage- und Verschiebungsvorlagen an.
- **Kosten je Nachricht**: Beim Versand werden Zielland (längste passende Vorwahl aus der Preistabelle bzw. den erlaubten Vorwahlen), Segmente und Kosten gespeichert (`cost`, `cost_currency`, `cost_source`, `sent_at`). Meldet der Anbieter einen Preis – in der Versandantwort (`price`/`currency`, bei Twilio `price`/`price_unit` sowie `num_segments`) oder im Status-Callback (Twilio `Price`/`PriceUnit`, HTTP `price`/`currency`) –, gilt dieser (`cost_source` `provider`), sonst Segmente × „Preis je Segment und Land“ bzw. Standardpreis (`configured`). Ohne Preis bleibt die Spalte leer (`none`) und zählt nicht zum Budget. Preise in einer anderen Währung als `sms.currency` werden nur über `wpcap_sms_convert_price` umgerechnet; sonst bleibt der hinterlegte Preis maßgeblich (Twilio meldet meist USD).
- **Monatsbudget** (Kalendermonat UTC, Summe der Kosten in der Abrechnungswährung): Ab „Warnung ab (Prozent)“ (Standard 80 %) einmal pro Monat Audit-Eintrag `sms_budget_warning`, Mail an die Administrationsadresse und Action `wpcap_sms_budget_warning`. Mit **harter Grenze** (Standard an) wird eine SMS, deren geschätzte Kosten das Budget überschreiten würden, nicht gesendet, sondern mit Status `held` zurückgehalten (Audit `sms_budget_stop`, Mail einmal pro Monat). Nach einer Budgeterhöhung stellt „Zurückgehaltene SMS freigeben“ sie wieder in die Warteschlange; nicht freigegebene verfallen nach 7 Tagen (`skipped`, `budget_exceeded`). Widerruf und Ende der Einwilligung überspringen auch zurückgehaltene SMS.
- **Kostenbericht**: Die SMS-Seite zeigt den Budgetstand, die letzten 12 Monate (SMS, Segmente, Kosten, davon vom Anbieter gemeldet, ohne Preis) und den laufenden Monat nach Ländervorwahl und Anlass.
- **Zustellstatus**: Twilio meldet an `/wp-json/pass-captain/v1/sms/twilio/status` (geprüft mit `X-Twilio-Signature`). Der generische HTTP-Anbieter meldet an `/sms/http/status` (JSON oder Formular; Felder `reference` = `wpcap-sms-{id}` oder `id`/`message_id`, `status`, optional `error_code`, `price`, `currency`). Er muss den Rohinhalt mit dem „Signaturschlüssel für Rückmeldungen“ (`sms_http_webhook_secret`) per HMAC-SHA256 signieren und die Signatur (hex oder Base64, optional `sha256=`) im Header `X-Signature` (einstellbar) senden; ohne Schlüssel lehnt der Endpunkt jede Meldung ab (403). Endgültige Zustände werden nicht auf `sent` zurückgestuft.
- **Einwilligung und Abmeldung**: Jede Einwilligung speichert Nummer, Zweck, Textversion, Quelle (`source`, im Checkout `checkout`) und Zeitpunkt; ein Widerruf Zeitpunkt und Quelle (`withdrawn_source`: `admin`, `inbound_stop`, `privacy_erasure` …). Eine eingehende SMS mit STOP (auch STOPP, ABMELDEN, ENDE, UNSUBSCRIBE …) über Twilio (`/sms/twilio/inbound`) oder den HTTP-Anbieter (`/sms/http/inbound`, Felder `from`, `text`, gleiche Signatur) widerruft alle Einwilligungen der Nummer, löscht die Nummer und setzt sie als SHA-256-Hash auf die Sperrliste `wpcap_sms_optouts`. Neue Einwilligungen für diese Nummer lehnt der Checkout ab (`sms_opted_out`), bis sie START (UNSTOP, ANMELDEN) sendet; alte Einwilligungen leben dadurch nicht wieder auf.

## Multisite: Site löschen und Plugin deinstallieren

- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Jede Site hat eigene Tabellen (`{präfix}wpcap_*`) und eigene Optionen; das Plugin greift nur über `$wpdb->prefix` bzw. `$wpdb->…`-Tabellen zu (statisch geprüft, siehe TESTING.md „Tabellenpräfixe und Isolation“). Zwischenspeicher je Anfrage (Verfügbarkeit, Steuerprofile, Schema-Bereitschaft, Schema-Version) gelten nur für die Site, für die sie gefüllt wurden; nach `switch_to_blog()` werden sie neu aufgebaut (Aktion `switch_blog` und Präfixvergleich).
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben; Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites.

## Zugangsdaten / Secrets

Zugangsdaten von Zahlungs- und Versanddiensten liegen **nicht** im Klartext in der allgemeinen Option `wpcap_settings`, sondern verschlüsselt in der Option `wpcap_secrets` (nicht autoloaded). Betroffen sind:

| Einstellung | Dienst |
|---|---|
| `stripe_secret_key`, `stripe_webhook_secret` | Stripe |
| `paypal_client_secret` | PayPal |
| `mollie_api_key` | Mollie |
| `twilio_auth_token`, `sms_http_secret`, `sms_http_webhook_secret` | SMS (Twilio, HTTP-Anbieter, Signaturschlüssel der HTTP-Rückmeldungen) |
| `apple_pass_cert`, `apple_pass_key`, `apple_pass_key_password`, `apple_wwdr_cert`, `google_wallet_service_account` | Wallet |

Kennungen ohne Geheimnischarakter (PayPal-Client-ID und Webhook-ID, Twilio Account SID) bleiben in `wpcap_settings`. Webhook-Signaturgeheimnisse der ausgehenden Webhooks stehen in der eigenen Tabelle `*_wpcap_webhook_subscriptions`, nicht in den Einstellungen.

**Verschlüsselung:** `sodium_crypto_secretbox` (XSalsa20-Poly1305, authentifiziert), je Wert eine zufällige Nonce; der Name der Einstellung ist mitverschlüsselt, Werte lassen sich also nicht zwischen Einstellungen vertauschen. Der Schlüssel steht nicht in der Datenbank:

```php
// wp-config.php – empfohlen: eigener Schlüssel, mindestens 32 zufällige Zeichen
define( 'WPCAP_SECRETS_KEY', '…' ); // z. B. erzeugt mit: php -r 'echo bin2hex(random_bytes(32));'
```

Ohne `WPCAP_SECRETS_KEY` wird der Schlüssel aus den WordPress-Salts abgeleitet (`wp_salt('auth')` und `AUTH_KEY`); Administrator:innen sehen dann einen Hinweis. Nachteil: Werden die Salts erneuert (z. B. nach einem Sicherheitsvorfall), sind die gespeicherten Zugangsdaten nicht mehr lesbar. Wird `WPCAP_SECRETS_KEY` nachträglich gesetzt, bleiben die bisherigen Werte lesbar und werden beim nächsten Admin-/Cron-Aufruf automatisch mit dem neuen Schlüssel neu verschlüsselt.

Vorrang beim Lesen: Konstante `WPCAP_<NAME>` (z. B. `WPCAP_STRIPE_SECRET_KEY`) > gleichnamige Umgebungsvariable > verschlüsselter Wert. Wer Zugangsdaten ganz außerhalb der Datenbank halten will, setzt die Konstanten; das Formular zeigt sie dann nur als „gesetzt über …“ an.

**Formulare:** Gespeicherte Werte werden nie angezeigt, nur „Gespeichert (verschlüsselt), endet auf …abcd“ (letzte vier Zeichen, nur bei Werten ab 12 Zeichen). Leeres Feld = Wert bleibt unverändert, die Checkbox „Gespeicherten Wert löschen“ entfernt ihn. Das Audit-Protokoll enthält nur die Namen geänderter Einstellungen, nie Werte.

**Migration:** Nach dem Update verschiebt der erste Aufruf (Frontend, Admin, Cron oder WP-CLI) vorhandene Klartextwerte hinter der Upgrade-Sperre (`GET_LOCK`, wie das Schema-Upgrade) in den verschlüsselten Speicher und entfernt sie aus `wpcap_settings` (Audit-Eintrag `secrets_migrated`, nur Namen). Der Schritt ist idempotent und unabhängig von der Schema-Version (Option `wpcap_secrets_version`). Taucht später erneut ein Klartextwert in `wpcap_settings` auf (z. B. nach einem Downgrade auf eine ältere Plugin-Version und erneutem Speichern), wird er beim nächsten Aufruf wieder übernommen und entfernt. Ältere Datenbanksicherungen enthalten die Zugangsdaten weiterhin im Klartext – Sicherungen von vor dem Update entsprechend schützen oder Zugangsdaten beim Anbieter erneuern.

**Fail closed:** Lässt sich ein gespeicherter Wert nicht entschlüsseln (Schlüssel geändert oder fehlt, Salts erneuert, Datenbank manipuliert), gilt der Dienst als nicht eingerichtet (z. B. erscheint Stripe nicht im Checkout, Webhooks werden mangels Signaturgeheimnis abgelehnt). Ein Admin-Hinweis nennt die betroffenen Einstellungen; die Felder zeigen „nicht entschlüsselbar – bitte neu eingeben“. Werte werden dabei nie protokolliert. Website-Zustand → Info zeigt im Abschnitt „Pass Captain – Zugangsdaten“ je Einstellung nur die Quelle (Konstante, verschlüsselt gespeichert, nicht entschlüsselbar, nicht gesetzt).

**Schlüsselwechsel (Rotation) ohne WP-CLI:**

1. Neuen Schlüssel erzeugen und in `wp-config.php` als `WPCAP_SECRETS_KEY` eintragen, den bisherigen Wert gleichzeitig als `WPCAP_SECRETS_KEY_PREVIOUS`:
   ```php
   define( 'WPCAP_SECRETS_KEY', '…neu…' );
   define( 'WPCAP_SECRETS_KEY_PREVIOUS', '…alt…' );
   ```
   Beim Wechsel von den Salts auf einen eigenen Schlüssel ist kein `…_PREVIOUS` nötig.
2. Eine Admin-Seite aufrufen (oder auf den nächsten Cron-Lauf warten): alle Werte werden mit dem neuen Schlüssel neu verschlüsselt (Audit-Eintrag `secrets_rewrapped`). Bis dahin bleiben sie mit dem alten Schlüssel lesbar.
3. Kontrollieren, dass kein Hinweis „nicht entschlüsselbar“ mehr erscheint, dann `WPCAP_SECRETS_KEY_PREVIOUS` entfernen.

Ist der alte Schlüssel verloren, die betroffenen Zugangsdaten beim Anbieter neu erzeugen (widerrufen) und in den Einstellungen neu eingeben. Bei Verdacht auf Abfluss von Datenbank **und** `wp-config.php` reicht ein Schlüsselwechsel nicht: die Zugangsdaten beim Anbieter widerrufen und neu eintragen.

**Wiederherstellung:** Eine Datenbanksicherung ist nur zusammen mit demselben `WPCAP_SECRETS_KEY` (bzw. denselben Salts) nutzbar. Den Schlüssel getrennt von der Datenbanksicherung, aber mit ihr auffindbar aufbewahren (z. B. Passwortmanager).

## Wiederherstellung

Dateien und Datenbank als zusammengehörigen Stand wiederherstellen. Ein Datenbank-Restore allein kann mit neuerem Plugin-Code inkompatibel sein. Für eine Netzwerksite gehören auch Netzwerkaktivierung und Site-Präfixe in den Wiederherstellungsplan. Nach einem Restore Cron-Ereignisse kontrollieren; WordPress-Cron benötigt Seitenaufrufe oder einen externen Cron-Trigger.

Auftrags- und Ticketdaten nicht selektiv aus mehreren Sicherungszeitpunkten zusammenführen. Zahlungsanbieter und optionale Integrationen müssen separat auf denselben externen Transaktionsstand abgeglichen werden.

### Rollback und Restore – erprobt (A113)

Am 13.11.2026 gegen WordPress 7.1.4 mit WooCommerce 11.3 und MariaDB geprüft (`tests/restore/drill.sh`, Release 1.4.0 = Schema 3.5.0 ↔ aktueller Stand = Schema 3.8.5, 84 → 107 Tabellen):

| Schritt | Ergebnis |
|---|---|
| 1.4.0 installieren, Bestellungen/Tickets/Check-in anlegen, Sicherung (`mariadb-dump`) | – |
| Code auf neue Version, Upgrade | Daten unverändert (Prüfsumme über Events, Termine, Ticketarten, Bestellungen, Tickets, Check-ins), neues Schema vollständig, Verkauf funktioniert |
| **Code-Rollback** auf 1.4.0 bei neuem Schema | keine Tabellen/Spalten entfernt, Daten unverändert, 1.4.0 verkauft weiter (neue Spalten haben Standardwerte) |
| wieder auf neue Version | Daten unverändert, Schema aktuell |
| **Restore** der 1.4.0-Sicherung unter neuer Version | exakt die gesicherten Daten, beim nächsten Admin-/Cron-Aufruf auf das volle Schema gebracht, Verkauf funktioniert |
| Neuinstallation neue Version → Rollback → wieder neue Version | Einstellungen unverändert |

Gefundener und behobener Fehler: Nach einem Rollback schreibt der ältere Code seine (niedrigere) Schemaversion zurück. Beim erneuten Upgrade liefen dadurch einmalige Datenmigrationen noch einmal, z. B. wurde bei einer Neuinstallation (Präfix „RK“) das alte Rechnungskorrektur-Präfix „GS“ festgeschrieben. Jetzt merkt sich `wpcap_db_version_max` die höchste je gelaufene Schemaversion; einmalige Migrationen vergleichen dagegen (`Installer::migration_baseline()`).

Regeln für den Betrieb:

- **Rollback nur des Codes** (Plugin-ZIP der Vorversion einspielen) ist innerhalb der getesteten Spanne möglich: Schemaänderungen sind rein additiv, der ältere Code ignoriert neue Tabellen/Spalten. Daten, die nur die neue Version kennt (z. B. neue Felder), bleiben erhalten, werden vom alten Code aber nicht gepflegt. Danach möglichst bald wieder vorwärts aktualisieren.
- **Restore** immer als Paar aus Datenbank- und Plugin-Stand. Eine ältere Sicherung unter neuerem Code ist unkritisch (Upgrade läuft automatisch); eine neuere Sicherung unter älterem Code entspricht einem Code-Rollback (s. o.).
- Den Drill vor einem Release selbst wiederholen:

```bash
bash tests/restore/drill.sh /pfad/zu/wordpress /pfad/alt/pass-captain /pfad/neu/pass-captain /tmp/drill
```

Das Skript sichert die Datenbank vorher, arbeitet mit WP-CLI und `mariadb-dump` und stellt Datenbank und Plugin-Link am Ende wieder her – **nur auf einer Test-Installation ausführen**, es löscht während des Laufs alle Plugin-Tabellen.

## Release-Kandidat

Vor einer stabilen Version müssen Upgrade und Restore mit realistischen Datenbankständen, Eventänderungen, doppelten Webhooks, E-Mail-Ausfällen, hoher Scanrate und Multisite geprüft werden. Der ZIP-Build (`bash build/release.sh`) exportiert den committeten Stand per `git archive` (Standard `HEAD`, anders mit `WPCAP_BUILD_REF=<ref>`), installiert dort die Composer-Abhängigkeiten ohne Dev-Pakete und lässt alles weg, was in `.distignore` steht (Tests, Doku, Markdown außer `readme.txt`, `.po`-Quellen, Test-/CI-Dateien der Abhängigkeiten). Nicht committete Änderungen landen also nie im Paket. Der Build ist reproduzierbar: Dateizeiten werden auf die Commit-Zeit gesetzt (`SOURCE_DATE_EPOCH`), Rechte vereinheitlicht (644/755), Einträge sortiert und ohne Zusatzattribute gepackt, der Autoloader-Suffix ist fest. Neben dem ZIP entstehen `pass-captain.zip.sha256` und `build-info.txt` (Commit, Zeitstempel, Prüfsumme, Prüfsumme der `composer.lock`); beide hängen auch am GitHub-Release. CI prüft mit `tests/reproducible-build.sh`, dass zwei Builds identisch sind.

**Abgleich veröffentlichtes ZIP ↔ Commit:** Release-ZIP und `.sha256` herunterladen, dann im Repository

```bash
WPCAP_BUILD_REF=v1.5.0 bash build/release.sh
```

```bash
sha256sum build/dist/pass-captain.zip
```

ausführen und die Summe mit der veröffentlichten `.sha256` bzw. `build-info.txt` vergleichen. Gleiche Summe = das ZIP stammt genau aus diesem Commit (gleiche Composer-Version vorausgesetzt; die CI nutzt Composer 2 aus `shivammathur/setup-php`). Der Release-Workflow veröffentlicht nur nach erfolgreicher CI auf `main` (genau der getestete Commit), bei Tags `v*` oder manuell von `main`.

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
| Käufer-E-Mail nie bezahlter Bestellungen (ohne Ticket, ohne Rechnung) | 90 Tage (Einstellung) | durch Platzhalter `deleted+…@invalid.example` ersetzt |
| Versandte/fehlgeschlagene Nachrichten der Warteschlange | 30 Tage / 365 Tage (Einstellungen) | Empfänger und Inhalt geleert / Zeile gelöscht |
| Einlassprotokoll: Mitarbeitende und Gerät | 365 Tage (Einstellung) | `user_id`/`device_id` = 0 (Zeit, Ergebnis, Ticket bleiben) |
| Audit-Log (inkl. Protokoll der Zugriffe auf Personendaten, Exporte, Rollenänderungen und Korrekturen, siehe [SECURITY.md](SECURITY.md#audit-log-zugriffe-auf-personenbezogene-daten); einsehbar unter Pass Captain → Audit-Log) | 730 Tage (Einstellung) | gelöscht |
| Wartelisteneinträge | 30 Tage nach Terminende (Einstellung; bei eventweiten Einträgen nach dem letzten Termin; `wpcap_privacy_retention_days`, Schritt `waitlist`) | E-Mail geleert, Hash ersetzt; Status bleibt (offene Angebote nicht) |
| Gästelisten-Einträge | wie Teilnehmername/-E-Mail | Name, E-Mail, Firma, Notiz geleert; Anzahl/Status bleiben |
| Käuferlimit-Zähler | wie Teilnehmername/-E-Mail (nach dem letzten Termin des Events) | gelöscht |
| Ticketcodes von Partnerverkäufen | 30 Tage nach Terminende (Einstellung) | gelöscht |
| Rechnungen mit Positionen, Rechnungskorrekturen; Kassenschichten mit Z-Bericht, Zählungen, Buchungen, Übergaben und Verkaufsbelegen | Einstellung „Rechnungen und Rechnungskorrekturen löschen nach (Jahren)“, Standard 0 = nie; Frist ab Ende des Ausstellungs- bzw. Abschlussjahres; eine Rechnung bleibt, solange eine ihrer Korrekturen noch in der Frist ist (`wpcap_financial_retention_years`) | gelöscht; Käufer-E-Mail, Rechnungsanschrift und Bestelllink der betroffenen Bestellungen pseudonymisiert, Beträge bleiben |

Alle Fristen stehen unter **Einstellungen → Allgemein → Datenschutz** (0 = Schritt aus, bei Partner-Ticketcodes: direkt nach dem Termin) und lassen sich zusätzlich mit `wpcap_privacy_retention_days` ändern (siehe [HOOKS.md](HOOKS.md)) (A123). Das Audit-Log erhält je Lauf nur die Anzahlen (`privacy_retention`). Nicht erfasst: Bestellungen, Positionen und Erstattungen (Rechnungen/Korrekturen nur mit eingestellter Belegfrist, siehe oben), Bestellungen in WooCommerce (eigene Fristen des Shops), Daten bei Zahlungsanbietern, Partnern und Webhook-Empfängern. Webhook-Zustellungen haben eine eigene Frist (Modul Webhooks).

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste und Terminbuchungen. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten und Terminbuchungen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Sperr-Push-Nachricht). Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Bestellungen mit Tickets für kommende Termine werden zurückgestellt (`wpcap_privacy_defer_upcoming`, Standard an): Die Tickets bleiben gültig, die Bestellung wird automatisch nach dem letzten Termin anonymisiert; die Löschung meldet die Anzahl. Soll sofort widerrufen werden, den Filter auf `false` setzen bzw. vorher erstatten/stornieren. Eigener Export/Löschung zusätzlich: Käuferlimit-Zähler (`pass-captain-buyer-limits`).

**Pflichten des Betreibers:**

- WordPress-Cron zuverlässig auslösen (siehe oben), sonst greifen die Fristen nicht.
- Den Vorschlag unter **Einstellungen → Datenschutz → Leitfaden** prüfen, an Verantwortlichen, Verträge und Abläufe anpassen und in die Datenschutzerklärung übernehmen; nach Aktivieren weiterer Dienste (Zahlungsanbieter, SMS, Wallet, Partner, Webhooks, Affiliate) erneut prüfen.
- Auftragsverarbeitungsverträge mit Hosting, E-Mail-Versand, SMS-Anbieter und gegebenenfalls Partnern bzw. Webhook-Empfängern schließen; Übermittlungen in die USA (Stripe, PayPal, Twilio, Apple, Google) dokumentieren.
- Affiliate-Tracking: Mit dem Plugin „WP Consent API“ und einem kompatiblen Cookie-Banner wird der Empfehlungs-Cookie nur mit Einwilligung „marketing“ ausgewertet; der Partnerlink der laufenden Shop-Sitzung (ohne Marketing-Cookie) zählt auch ohne diese Einwilligung. Ohne Consent-API muss der Cookie-Banner das Setzen des Affiliate-Cookies selbst blockieren, oder der Filter `wpcap_affiliate_tracking_allowed` wird an den eigenen Consent-Manager angebunden.
- Web-Scanner auf geteilten Geräten: vor Schichtwechsel synchronisieren, nach Schichtende Browser schließen bzw. Website-Daten löschen (Offline-Scans liegen bis zu 24 Stunden im Browser).
- Gesetzliche Aufbewahrung von Rechnungen und Buchungsdaten (7 Jahre § 132 BAO, 10 Jahre § 147 AO/§ 257 HGB) überwachen und danach manuell löschen; das Plugin löscht Finanzdaten nicht automatisch.
- Partner-API-Schlüssel mit `attendees:read` nur mit Rechtsgrundlage vergeben; gelöschte Adressen liefert die API als `null`.
- Vorlagen für Gästelisten-Information (Art. 14 DSGVO), Rolle der Veranstalter, Kassen-Schichtdaten (Mitbestimmung, Z-Bericht-Aufbewahrung), AV-Vertrag Fiskalanbieter, Auskunft ohne E-Mail und Verarbeitungsverzeichnis: [BETREIBERPFLICHTEN.md](BETREIBERPFLICHTEN.md).

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

## Abrechnungsblatt pro Termin (Door Deal)

Pass Captain → Berichte → „Abrechnungsblatt pro Termin“: Termin wählen, optional **Fixbetrag** und
**Anteil %** eingeben, dann *Druckansicht* (A4, im Browser drucken oder als PDF speichern) oder *CSV-Export*
(`admin-post.php?action=wpcap_settlement_sheet&occurrence_id=…&fixed=…&share=…&format=html|csv`). In der
Druckansicht lassen sich Fixbetrag und Anteil ändern und neu berechnen; die Werte werden **nicht
gespeichert**. Zugriff wie Berichte: Administratoren und `wpcap_view_finance` für alle Termine,
`wpcap_view_reports` nur für zugewiesene Events. Jeder Abruf wird protokolliert (`settlement_sheet_html`,
`settlement_sheet_csv`).

Das Blatt umfasst den ganzen Termin (kein Datumsfilter) und rechnet wie der Umsatzbericht (Rabattverteilung,
Umtausch, Mehrzweckgutscheine, Erstattungen):

- Verkäufe je Ticketart und Verkaufskanal (Kasse, Veranstalter/Wiederverkäufer-API, WooCommerce, online):
  verkauft, erstattet, Tickets netto, Brutto, Erstattungen, Umsatz nach Erstattung, davon USt, Netto; Summen je
  Kanal und gesamt;
- Umsatzsteuer je Satz (nach Erstattungen);
- Kasse (Abendkasse) je Zahlungsart (Bar, Karte, Rechnung);
- Freikarten (Gästeliste, Kassen-Freikarte) je Ticketart, getrennt und nicht im Umsatz;
- Besucher: Kapazität, gültige Tickets (inkl. Freikarten), eingecheckt, No-Shows (gültig − eingecheckt);
- Deal: vom Netto-Umsatz nach Erstattungen wird zuerst der Fixbetrag abgezogen, vom Rest erhält der
  Künstler/Veranstalter den Anteil (kaufmännisch gerundet), der Rest verbleibt beim Haus. Beispiel: Netto
  60,00 €, Fixbetrag 10,00 €, Anteil 70 % → Bemessungsgrundlage 50,00 €, Künstler 35,00 €, Haus 25,00 €.

## Buchhaltungsexport

Pass Captain → Berichte → „Buchhaltungsexport“ (nur `manage_options` und `wpcap_view_finance`; Zeitraum des
Berichts) bzw. `admin-post.php?action=wpcap_export_accounting&from=YYYY-MM-DD&to=YYYY-MM-DD&_wpnonce=…`.
CSV mit Semikolon, UTF-8 mit BOM, Beträge mit Punkt als Dezimaltrennzeichen. Jeder Export wird
protokolliert (`accounting_exported`).

**Steuersätze**: Rechnungen, Rechnungskorrekturen, Export und Berichte verwenden den Satz, der beim Verkauf
auf der Bestellposition gespeichert wurde – aus der Ticketart oder aus einem geprüften Länder-Steuerprofil
(Pass Captain → Steuerprüfung, Schema 3.9.2). Profiländerungen wirken nur auf neue Bestellungen; die
Bestellansicht zeigt je Position Quelle, Profilversion und Leistungsort. Details: docs/SALES-MODELS.md,
Abschnitt „Länder-Steuerprofile“.

**Tagesgrenzen**: `from`/`to` sind Kalendertage in der **Zeitzone der Website** (Einstellungen → Allgemein,
`timezone_string`; ohne Angabe UTC), jeweils von 00:00 bis 24:00 Ortszeit. Auch `Belegdatum` ist das
Datum in Ortszeit. Eine Rechnung von 00:30 Uhr am 1. eines Monats (MEZ) gehört damit in den neuen Monat,
wie Rechnungsnummernjahr und Umsatzbericht. Ohne Parameter: aktueller Monat bis heute (Ortszeit).

**Zeilen** (Spalte `Belegart`):

| Belegart | Inhalt | Datum |
|---|---|---|
| `Rechnung` | eine Zeile je Rechnungsposition | Ausstellung |
| `Rechnungskorrektur` | eine Zeile je Steuersatz einer Rechnungskorrektur (negativ) | Ausstellung |
| `Verkauf ohne Rechnung` | bezahlte Bestellungen ohne Rechnung (automatische Rechnungen aus, Kassenbelege, Partner-API, WooCommerce): eine Zeile je Bestellung und Steuersatz, Beträge nach Rabatt | Bestelldatum |
| `Erstattung ohne Rechnung` | abgeschlossene Erstattungen solcher Bestellungen, eine Zeile je Steuersatz (negativ; Mehrzweckgutscheine mit Gutscheinanteil und voller USt wie im Bericht) | Abschluss der Erstattung |

Freikarten (Zahlungsanbieter aus `wpcap_comp_payment_providers`, z. B. `comp`, `pos_free`) sind keine
Verkäufe und erscheinen nicht. Eine Bestellung gilt als „ohne Rechnung“, solange für sie keine Rechnung
existiert; wird sie später fakturiert, erscheint sie ab dann nur noch mit ihrer Rechnung (am Tag der
Ausstellung). Bereits übergebene Exporte eines Zeitraums daher nicht nach nachträglicher Fakturierung erneut
buchen.

**Spalten** (feste Reihenfolge A–O wie unten; die Spalten sind eine stabile Schnittstelle – neue Spalten
werden nur am Ende angefügt, vorhandene nie umbenannt oder verschoben; Konstante `Invoices::EXPORT_COLUMNS`):

| Spalte | Inhalt |
|---|---|
| `Belegart` | siehe oben |
| `Belegnummer` | Rechnungs- bzw. Korrekturnummer; leer bei Verkäufen/Erstattungen ohne Rechnung (Bestellnummer in `Bestellung`) |
| `Belegdatum` | Datum in Ortszeit (YYYY-MM-DD) |
| `Bestellung` | öffentliche Bestellnummer |
| `Bezug` | bei Rechnungskorrekturen die korrigierte Rechnungsnummer |
| `Beschreibung` | Positionstext; bei Zeilen ohne Rechnung „Tickets ohne Rechnung (Kanal)“ bzw. „Erstattung ohne Rechnung (Kanal)“ mit dem Verkaufskanal des Berichts (Kasse, Veranstalter/Wiederverkäufer (API), WooCommerce-Shop, Online/sonstige) |
| `Menge` | Anzahl (Rechnungsposition bzw. Tickets je Steuersatz; leer bei Korrekturen/Erstattungen) |
| `Netto`, `USt-Satz`, `USt`, `Brutto` | Beträge der Zeile; Satz in Prozent (z. B. `19.00`); Korrekturen und Erstattungen negativ |
| `Waehrung` | ISO-Währung |
| `Zahlungsart` | Zahlungsanbieter der Bestellung (z. B. `stripe`, `pos_cash`, `pos_card`, `partner`, `woocommerce`) |
| `Verkaufsmodell` | `Eigenhaendler` oder `Vermittler` (siehe [SALES-MODELS.md](SALES-MODELS.md)); bei Zeilen ohne Rechnung der Rechnungssteller, den eine Rechnung hätte (leer bei Bestellungen mit mehreren Rechnungsstellern) |
| `Rechnungssteller` | Name des Rechnungsstellers |

**Vorzeichen**: Rechnungen und Verkäufe ohne Rechnung sind positiv, Rechnungskorrekturen (Teil- und
Vollkorrektur/Storno) und Erstattungen ohne Rechnung negativ – in `Netto`, `USt` und `Brutto` gleichermaßen.
Je Zeile gilt `Netto + USt = Brutto`. Eine Rechnungskorrektur nennt in `Bezug` immer die korrigierte Rechnung;
ihre Zeilen je Steuersatz ergeben zusammen exakt den Korrekturbetrag (Aufteilung wie im Bericht, siehe unten).

**Währungen**: Jede Bestellung hat genau eine Währung (Ticketarten mit unterschiedlicher Währung können nicht
gemeinsam bestellt werden); `Waehrung` steht in jeder Zeile. Summen nur innerhalb einer Währung bilden
(`Invoices::journal_totals()` liefert Netto/USt/Brutto je Währung und Steuersatz).

**Belegnummern**: Rechnungen und Rechnungskorrekturen werden je Nummernkreis (Präfix und Jahr) lückenlos
innerhalb der Belegtransaktion vergeben; ein abgebrochener Beleg gibt seine Nummer mit dem Rollback zurück.
Die Berichtsseite prüft die Nummernkreise der Jahre des Zeitraums und warnt bei Lücken
(`Invoices::document_gaps()`, z. B. nach manuellem Löschen in der Datenbank).

**Abstimmung mit dem Umsatzbericht**: Die Summe `Brutto` (bzw. `USt`) aller Zeilen eines Zeitraums entspricht
„Umsatz nach Erstattungen“ (bzw. „davon USt“) des Berichts für denselben Zeitraum, sofern Rechnungen am Tag
der Bestellung und Rechnungskorrekturen am Tag der Erstattung ausgestellt wurden (automatische Rechnungen) –
auch je Währung und je Steuersatz: Bericht und Rechnungskorrektur teilen eine Erstattung mit derselben Rechnung
auf Positionen und Steuersätze auf (`Invoices::refund_line_parts()`: Ticket-Erstattungen nach dem bezahlten
Positionswert nach Rabatt, Betragserstattungen über alle Positionen; USt nach der Positions-USt, 0-%-Positionen
erhalten keine USt). Abweichungen entstehen nur durch später ausgestellte Belege (Rechnung/Korrektur an einem
anderen Tag).

### Buchungsstapel (DATEV-Format)

Pass Captain → Berichte → „Buchungsstapel … (DATEV-Format, CSV)“ bzw. derselbe Aufruf wie der
Buchhaltungsexport mit `&format=datev`. Gleiche Berechtigung, gleicher Zeitraum, protokolliert als
`accounting_exported` mit `format=datev`. Der Stapel wird aus den Zeilen des Buchhaltungsexports gebildet und
stimmt daher immer mit ihm überein.

- Eine Buchung je Beleg (bei Zeilen ohne Rechnung: je Bestellung bzw. Erstattung), Steuersatz und Währung;
  Betrag brutto auf das Erlöskonto des Satzes (Automatikkonto) gegen das Geldkonto.
- Spalten: `Umsatz (ohne Soll/Haben-Kz)` (immer positiv, Komma als Dezimaltrennzeichen),
  `Soll/Haben-Kennzeichen` (`S` = Verkauf, `H` = Rechnungskorrektur/Erstattung), `WKZ Umsatz`, `Konto`
  (Geldkonto), `Gegenkonto (ohne BU-Schlüssel)` (Erlöskonto), `BU-Schlüssel`, `Belegdatum` (`TTMM`),
  `Belegfeld 1` (Beleg- bzw. Bestellnummer), `Belegfeld 2` (korrigierte Rechnung), `Buchungstext`.
- Konten: Standard SKR03 – 19 % → 8400, 7 % → 8300, andere Sätze → 8200, Geldkonto 1360 (Geldtransit);
  anpassbar über den Filter `wpcap_datev_accounts` (z. B. SKR04, Geldkonto je Zahlungsanbieter, BU-Schlüssel
  je Satz).
- Belege im Vermittlermodell sind Umsatz des Veranstalters und werden nicht gebucht (Provision über die
  Partnerabrechnung).
- Kodierung Windows-1252 ohne BOM, Semikolon. Der Stapel enthält **keinen** EXTF-Vorlaufsatz; er ist für den
  ASCII-/CSV-Import mit festgelegtem Wirtschaftsjahr gedacht. Da `Belegdatum` keine Jahreszahl trägt, den
  Zeitraum innerhalb eines Wirtschaftsjahres wählen. Fremdwährungsbeträge werden ohne Kurs übergeben.
  Kontenzuordnung und Import vor dem produktiven Einsatz mit der Steuerberatung abstimmen.

### Abnahme der Berichte

`Reports\Data::reconcile()` prüft einen Bericht rechnerisch; die Berichtsseite zeigt einen Fehlerhinweis, wenn
eine Prüfung fehlschlägt:

- je Zeile: `Netto = Brutto − USt`, `Umsatz nach Erstattung = Brutto − Erstattet`,
  `USt nach Erstattung = USt − Erstattet USt`, `Netto nach Erstattung = Umsatz nach Erstattung − USt nach Erstattung`;
- jede Gruppierung (Event, Termin, Ticketart, Zahlungsanbieter, Tag, Steuersatz, Kanal) ergibt in allen
  Betragsspalten die Gesamtsumme;
- die Währungen ergeben die Gesamtsumme, die Steuersätze jeder Währung deren Summe.

Bei mehreren Währungen im Zeitraum zeigt die Seite zusätzlich „Umsatz nach Währung“ (je Satz und Summe);
die Gesamtsummen sind dann nicht addierbar. Der CSV-Export des Berichts enthält die Spalte
„Netto nach Erstattung“ und den Abschnitt „Währung / USt-Satz“.

### Berichts-Caches und Invalidierung (A201)

**Bestand** (alle Caches mit Berichts- oder Summenwerten):

| Cache | Wo | Regel |
|---|---|---|
| REST `GET /reports/events/{id}` (Verkäufe je Währung, Check-ins) | Transient `wpcap_rep_<hash>` über `Reports\Cache::remember()` | Generationsschlüssel je Site und Event (unten), TTL 5 Min. nur als Sicherheitsnetz |
| Berichtsseite (`Reports\Data::build()`), Abrechnungsblatt (`Reports\Settlement`), CSV/DATEV-Exporte | – | **nicht gecacht**, jede Anzeige rechnet frisch |
| Verfügbarkeitsanzeige (`Orders::availability()`) | Request-Memo + 10 s Objekt-Cache | siehe [Kontingentzähler](#kontingentzähler-große-vorverkäufe): Memo verfällt bei jedem Schreibzugriff, Kaufentscheidungen nutzen nie den Cache |
| Kontingentzähler (`sold_qty`/`held_qty`) | Tabellenspalten | kein Cache, transaktional gepflegt und abgeglichen |
| Website-Zustand-Hinweis | Transient, 5 Min. | Betriebsstatus, keine Berichtszahlen; „Erneut prüfen“ verwirft ihn |
| Statische Memos (`Secrets`, `Passes\Definitions`, `Series\Pricing`, `Mail\Types`, `Privacy`) | pro Request | Konfiguration, keine Summen |

**Schlüssel**: `wpcap_rep_` + Hash aus Tabellenpräfix (Site), Blog-ID, Berichtsname, Event-ID, Parametern
(Zeitraum) und zwei Generations-Tokens: der Site-Generation und der Event-Generation (Event-Berichte) bzw.
der „alle Events“-Generation (siteweite Berichte). Ein Token ist ein Zufallswert in einem eigenen Transient
(`wpcap_rgen_site`, `wpcap_rgen_e<ID>`, `wpcap_rgen_all`, 1 Tag); eine Änderung setzt einen neuen Token, alte
Einträge werden nie mehr gelesen und laufen über ihre TTL aus. Ein verdrängter Token führt nur zu Cache-Misses.

**Invalidierung**: Der `query`-Filter sieht jeden Schreibzugriff (INSERT/UPDATE/DELETE/REPLACE) auf die
Berichtstabellen `events`, `occurrences`, `ticket_types`, `orders`, `order_items`, `refunds`, `checkins`,
`tickets`, `invoices`, `credit_notes` – unabhängig davon, welcher Code schreibt (Checkout, Zahlung, Erstattung,
Check-in, Kasse, Admin-Bearbeitung, Module):

- Die IDs der Anweisung (`event_id`, `occurrence_id`, `ticket_type_id`, `order_id`, `item_id`, `ticket_id`,
  `refund_id`, `invoice_id`, `checkin_id`, `credit_note_id` in SET/WHERE bzw. in den VALUES-Zeilen) werden
  **vor** der Ausführung auf Event-IDs aufgelöst – gelöschte Zeilen und verschobene Ticketarten zählen damit
  für das alte *und* das neue Event.
- Ohne bestimmbare Zeilen (keine ID, Unterabfrage, `OR`, `INSERT … SELECT`) wird die **Site-Generation**
  erhöht (alle Berichte der Site).
- Ein bloßes INSERT in `orders`, `invoices` oder `events` ändert keine Zahl (erst Bestellzeilen, Gutschriften,
  Termine tun das) und erhöht nichts.
- Erhöht wird **nach dem COMMIT** (bzw. nach einem Autocommit-Schreibzugriff): beim nächsten Statement, vor
  einem gecachten Lesen und spätestens bei `shutdown`. Ein paralleler Request kann so keinen noch nicht
  festgeschriebenen Stand unter dem neuen Token cachen.
- Wer außerhalb von `$wpdb` oder in eigene Tabellen schreibt, ruft `Reports\Cache::invalidate_event( $id )`
  (0 = Site) oder die Action `wpcap_report_cache_invalidate` auf.
- Lebensdauer: Filter `wpcap_report_cache_ttl` (300 s, höchstens 900 s, 0 = kein Cache).

Ergebnis: Nach einer Bestellung, Zahlung, Erstattung, einem Check-in oder einer Änderung an Ticketart, Termin
oder Event liefert der nächste Abruf frische Zahlen; Änderungen an Event A lassen den Cache von Event B stehen.

**Abfragepläne** (EXPLAIN mit 500 Events, 2 000 Terminen, 6 000 Ticketarten, 300 000 Bestellungen, 400 000
Zeilen, 600 000 Tickets, 480 000 Check-ins, 15 000 Erstattungen, 7 500 Gutschriften): REST-Verkäufe
(`occurrences.event_id` → `ticket_types.occurrence_id` → `order_items.ticket_type_id` → `orders` PK, ~4 ms),
REST-Check-ins (`event_result_created`), Bestellungen je Zeitraum (`paid_created`), Zeilen je Bestellung,
Termin-Auslastung, Einlass-Histogramm und Abrechnungsblatt nutzen vorhandene Indizes. Erstattungen je
Zeitraum (`status='completed' AND updated_at …`) und Gutschriften je Zeitraum (`issued_at …`) lasen dagegen die
ganze Tabelle; Schema 3.10.7 ergänzt `wpcap_refunds.status_updated (status,updated_at)` und
`wpcap_credit_notes.issued_at` (gelesene Zeilen 14 546 → 638 bzw. 7 471 → 638 für einen Monat). Die Indizes
legt das Upgrade über `dbDelta` an; `Installer::upgrade_indexes()` gleicht sie bei späteren Änderungen ab.

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
  Der Cron-Lauf läuft nie doppelt (`GET_LOCK`), hat ein Zeitbudget (10 s, `wpcap_capacity_reconcile_budget`)
  und prüft nur in den letzten zwei Tagen geänderte Bestellungen sowie laufende/künftige Termine; eine
  scheinbare Abweichung wird nach 0,3 s erneut geprüft (Fenster zwischen COMMIT und Zählersync), erst dann
  gilt sie als Drift. `wp pass-captain reconcile-capacity --all` prüft den gesamten Bestand ohne Zeitlimit.
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

### Erstattung nach Einlass (A131)

Tickets, die bereits zum Einlass genutzt wurden (Status „eingecheckt“, ein nicht zurückgenommener Check-in oder ein Eintritt im Einlassmodul), werden in der Bestellansicht nur erstattet, wenn eine Person mit `wpcap_approve_refunds` im Feld „Ausnahme für bereits eingelassene Tickets“ eine Begründung angibt. Die Erstattung landet dann immer in „Erstattungen zur Freigabe“ (Vier-Augen-Prinzip), Grund und Ausnahme stehen im Erstattungsgrund und im Audit-Log. Automatische Erstattungen bei Terminabsage sowie Kassen-Stornos (mit eigener Ausnahme an der Kasse) sind davon nicht betroffen; das Bestellportal bietet eingelassene Tickets gar nicht zur Stornierung an.

## Servicebuchungen (Ressourcen, Zeitfenster, Leistungserbringer)

Service-Zeitfenster (Bootsschule, Werkstatt, Beratung) werden unter **Events → Service-Zeitfenster** je Event verwaltet; Kund:innen reservieren sie über `POST /service-slots/{slot_id}/bookings`.

### Dauer, Puffer und Leistungserbringer (A133)

- **Dauer und Puffer**: Jede Ressource hat eine Standarddauer (`duration_minutes`, 5–1440 Min.) sowie *Puffer vorher* und *Puffer danach* (0–1440 Min., z. B. Auf- und Abriggen). Ein Zeitfenster belegt die Ressource von *Beginn − Puffer vorher* bis *Ende + Puffer danach*. Zwei Zeitfenster derselben Ressource dürfen sich in diesem gepufferten Bereich nicht überschneiden: Anlegen, Verschieben und Wiederöffnen eines Zeitfensters werden sonst abgelehnt (`wpcap_service_slot_overlap`); geschlossene Zeitfenster ohne Buchung zählen nicht.
- **Serie erzeugen**: „Zeitfenster-Serie erzeugen“ legt an gewählten Wochentagen zwischen zwei Daten Zeitfenster der Standarddauer (oder einer eigenen Dauer) an; zwischen zwei Zeitfenstern bleiben *Puffer danach + Puffer vorher* frei. Kandidaten in der Vergangenheit oder mit Überschneidung werden übersprungen (höchstens 500 je Aufruf, Audit `service_slots_generated`).
- **Leistungserbringer**: Personen oder Partnerfirmen (optional mit WordPress-Benutzer und Partner aus dem Partner-Modul), die einer oder mehreren Ressourcen zugeordnet werden (Audit `service_provider_created/updated`, `service_resource_providers_set`). Eine Buchung belegt die Ressource *und* ihre aktiven Leistungserbringer.
- **Zugang für Leistungserbringer (A143)**: Ist ein aktiver Leistungserbringer mit einem WordPress-Benutzer verknüpft, der die Rolle „Pass Captain Leistungserbringer“ (`wpcap_service_bookings`) hat, sieht diese Person unter **Pass Captain → Meine Leistungen** genau die Zeitfenster und Buchungen der Ressourcen, denen der Datensatz zugeordnet ist, und kann aktive Reservierungen kostenloser Ressourcen bestätigen sowie bestätigte Buchungen ab Beginn als „nicht erschienen“ markieren. Verknüpfen und Zuordnen darf nur, wer `wpcap_manage_events` für das Event hat (auditiert). Details: [SECURITY.md](SECURITY.md#rollen-und-capabilities).
- **Prüfung bei der Reservierung**: Eine Reservierung wird abgelehnt, wenn ein anderes Zeitfenster derselben Ressource (`wpcap_slot_resource_busy`) oder einer Ressource mit demselben aktiven Leistungserbringer (`wpcap_slot_provider_busy`) im gepufferten Bereich aktive Buchungen hat (bestätigt, oder reserviert und nicht abgelaufen). Jede Seite nutzt dabei die Puffer ihrer eigenen Ressource. Die öffentliche Liste `GET /events/{id}/service-slots` zeigt nur Zeitfenster mit freien Plätzen ohne solche Konflikte und liefert `available` mit.
- **Zuordnung ändern**: Einen Leistungserbringer einer Ressource zuzuordnen, der dadurch doppelt gebucht wäre (gebuchtes Zeitfenster dieser Ressource überschneidet ein gebuchtes einer anderen seiner Ressourcen), wird mit `wpcap_provider_double_booked` abgelehnt. Inaktive Leistungserbringer blockieren nichts.
- **Sperren**: Reservierungen und Zuordnungen sperren in fester Reihenfolge Ressourcen, dann Leistungserbringer, dann Zeitfenster (jeweils nach ID) und lesen erst danach (`ServiceBookings::lock()`). Parallele Anfragen auf dieselbe Ressource oder denselben Leistungserbringer laufen dadurch nacheinander; wer auf eine Sperre wartet und abbricht, erhält einen wiederholbaren Fehler (503, `retryable`).

### Buchungsregeln je Ressource (A134)

Unter „Buchungsregeln“ einer Ressource (Schema 3.10.3; 0 = keine Einschränkung bzw. bis zum Beginn):

| Regel | Spalte | Wirkung |
|---|---|---|
| Buchungsschluss vor Beginn | `lead_minutes` (0–43200) | Reservieren, Bestätigen und Umbuchen auf ein Zeitfenster nur, wenn es mindestens so weit in der Zukunft beginnt (`wpcap_booking_cutoff`). |
| Höchstens Tage im Voraus | `advance_days` (0–730) | Zeitfenster, die später beginnen, sind noch nicht buchbar (`wpcap_booking_too_early`). |
| Max. offene Buchungen pro Person | `max_per_customer` (0–100) | Aktive Buchungen (reserviert, Zahlung offen, bestätigt) einer E-Mail-Adresse (Groß-/Kleinschreibung egal) auf noch nicht beendeten Zeitfenstern der Ressource (`wpcap_booking_customer_limit`). Abgelaufene und stornierte zählen nicht; beim Bestätigen und Umbuchen zählt die eigene Buchung nicht doppelt. |
| Umbuchung bis (Std. vor Beginn) | `rebook_hours` (0–8760) | Kund:innen können bis zu dieser Frist vor Beginn des *bisherigen* Termins umbuchen (`wpcap_booking_rebook_deadline`). |
| Stornierung bis (Std. vor Beginn) | `cancel_hours` (0–8760) | Kund:innen können eine bestätigte Buchung bis zu dieser Frist stornieren (`wpcap_booking_cancel_deadline`); eine noch nicht bestätigte Reservierung können sie immer freigeben. |

Alle Regeln prüft der Server unter den Sperren aus A133 (`ServiceBookings::rule_error()`, `rebook_deadline_error()`, `cancel_deadline_error()`), also auch bei parallelen Anfragen derselben Person. Die öffentliche Zeitfensterliste zeigt nur Zeitfenster im Buchungsfenster. Das Team (`actor` `admin`, Recht `wpcap_manage_events` für das Event) ist an Umbuchungs- und Stornofristen nicht gebunden, wohl aber an Buchungsfenster, Kapazität, Puffer und Leistungserbringer des Ziel-Zeitfensters.

### Ablauf: Bestätigen, Bezahlen, Umbuchen, Stornieren (A135)

Statusfolge einer Buchung: `held` (Reservierung, 10 Min.) → `pending_payment` (Bestellung angelegt, Zahlung offen) → `confirmed`; daneben `expired` (Reservierung bzw. unbezahlte Bestellung abgelaufen), `unallocated` (bezahlt, aber Termin inzwischen vergeben) und `cancelled` sowie `no_show` (bestätigte Buchung, Gast nicht erschienen; nur ab Beginn des Zeitfensters, A143). Platz belegen `confirmed` sowie `held`/`pending_payment` bis `expires_at`.

- **Buchungszugang**: `POST /service-slots/{id}/bookings` liefert neben `booking_id` einen persönlichen `token` (64 Hex-Zeichen; gespeichert wird nur der SHA-256-Hash, Schema 3.10.4). Damit arbeiten `GET /service-bookings/{id}` (Status, Regeln, Umbuchungsziele, keine Daten anderer), `POST …/confirm`, `…/rebook` (`slot_id`) und `…/cancel`. Nach 10 falschen Tokens je Buchung und Stunde antwortet die Buchung mit 429. Die Datenschutz-Löschung entfernt den Token.
- **Bestätigen ohne Preis**: Ressourcen ohne Ticketart sind kostenlos; die Buchung wird sofort bestätigt.
- **Bestätigen mit Zahlung**: Hat die Ressource eine Ticketart (Feld „Preis (Ticketart)“, nur Ticketarten desselben Events), läuft die Zahlung über den normalen Checkout (`Rest\Controller::checkout()`: Zahlungsarten, AGB, Rabatt-/Gutscheincode, Rechnungsdaten, Zugangslink zum Bestellportal). `confirm` nimmt dafür `provider`, `accept_terms`, `terms_version`, `locale`, `billing`, `code` entgegen und liefert die Bestelldaten samt Zahlungsweiterleitung zurück. Die Bestellung wird innerhalb ihrer Transaktion mit der Buchung verknüpft (`pending_payment`, Reservierung = Bestellreservierung, höchstens bis zum Beginn); ist die Reservierung schon abgelaufen oder bereits verknüpft, wird die Bestellung abgebrochen – eine Buchung hat nie zwei Bestellungen. Die Ticketart ist nur Preisposition: ihr Verkaufszeitraum und der Beginn ihres Termins gelten nicht (wohl aber ein beendeter Termin), Überweisung wird bis zum Beginn des Zeitfensters angeboten. Die ausgestellten Tickets dienen als Beleg.
- **Zahlung**: `wpcap_order_paid` bestätigt die Buchung. Kommt die Zahlung nach Ablauf der Reservierung, wird unter den Sperren erneut geprüft (Platz, Puffer, Leistungserbringer); ist der Termin inzwischen vergeben, wird die Buchung `unallocated` (Zahlung ohne Termin) und die Bestellung nach der Ticketausgabe automatisch erstattet (Systemerstattung, ggf. mit Freigabe; die Tickets werden dabei widerrufen) und Kund:in und Team werden informiert. Läuft eine unbezahlte Bestellung ab oder wird sie storniert, wird die Buchung `expired` bzw. `cancelled`; wird eine bezahlte Bestellung anderswo storniert oder erstattet (Bestellansicht, Anbieter-Dashboard), wird die Buchung storniert.
- **Umbuchen**: atomar in einer Transaktion (beide Ressourcen, deren Leistungserbringer, beide Zeitfenster, die Buchung gesperrt). Geprüft werden Ereignis, Ziel offen und in der Zukunft, Buchungsfenster/Limit (A134), freie Plätze, Puffer und Leistungserbringer (die eigene Buchung zählt nicht mit). Bezahlte bzw. zu bezahlende Buchungen nur auf Ressourcen mit derselben Ticketart (gleicher Preis); Bestellung und Tickets bleiben unverändert.
- **Stornieren**: Plätze sofort frei. Offene Bestellung → wird storniert. Bezahlte Buchung → volle Erstattung über die Erstattungsfunktion (Quelle `service_booking`; Kund:innen innerhalb der Stornofrist immer, das Team optional und nur mit Erstattungsrecht). Nach der Frist können Kund:innen weder stornieren noch umbuchen; das Team schon.
- **Benachrichtigungen**: Bei Bestätigung, Umbuchung (mit bisherigem Termin), Stornierung (mit Erstattungshinweis) und nicht zuordenbarer Zahlung erhalten Kund:in und Team (Website-Admin und aktive Leistungserbringer der Ressource mit E-Mail) eine Mail. Anpassbar über `wpcap_service_booking_mail` und `wpcap_service_booking_staff_recipients`; `wpcap_service_booking_changed` meldet jede Änderung (siehe [HOOKS.md](HOOKS.md)).
- **Bestellportal**: Die Karte „Deine Termine“ zeigt aktive, noch nicht beendete Buchungen mit der E-Mail-Adresse der Bestellung – mit Umbuchen (nur passende freie Zeitfenster) und Stornieren, solange die Fristen laufen (POST mit Nonce je Bestellung, danach Weiterleitung mit Ergebnis).
- **Team**: In der Buchungsliste der Seite „Service-Zeitfenster“ lassen sich Buchungen bestätigen, umbuchen und stornieren (mit/ohne Erstattung), Link zur Bestellung.
- **Parallelität**: Reservieren, Bestätigen, Umbuchen und Stornieren sperren Ressourcen → Leistungserbringer → Zeitfenster → Buchung (`ServiceBookings::lock()`/`begin()`) und lesen erst danach. Tests: parallele Prozesse (ein Platz, gemeinsamer Leistungserbringer, Umbuchung gegen Reservierungen) und ein deterministischer Ablauf, bei dem eine Reservierung während der Umbuchungstransaktion (`wpcap_service_booking_rebooking`) auf deren Sperren wartet (`tests/integration/modules/zz_a135.php`).

## Zeit, Zeitzonen und Sommerzeit (A199)

Alle Zeitpunkte werden in UTC gespeichert und verglichen; Ortszeit entsteht nur bei Eingabe, Anzeige und
Tagesgrenzen. Die aktuelle Zeit für Geschäftsregeln liefert `PassCaptain\Clock` (`Clock::now()`,
`Clock::mysql()`, `Clock::today( $zeitzone )`); der Filter `wpcap_now` macht sie für Tests und Simulationen
austauschbar. Regeln bei der Zeitumstellung:

- **Fristen sind echte Dauern.** Reservierungen (Halten), Wartelistenangebote (`offer_hours`), Erinnerungen
  („48 h vor Beginn“), Buchungsschluss/Vorausbuchung/Umbuchungs- und Stornofrist von Servicebuchungen
  und das Ende des Abendkassenverkaufs (Ende des Termins, ohne Ende 6 h nach Beginn) zählen Sekunden,
  nicht Uhrzeiten. „24 h vor Sonntag 10:00 MESZ“ ist Samstag 09:00 MEZ; ein Halt von 10 Minuten ab
  02:55 MESZ endet um 02:05 MEZ.
- **Erinnerungen** werden je Termin, Startzeit, Stufe und Empfänger:in genau einmal eingeplant
  (Dedupe-Schlüssel mit der UTC-Startzeit). In der doppelten Stunde (Herbst) gibt es daher keinen zweiten
  Versand, und ein Versandzeitpunkt, der als Ortszeit in die fehlende Stunde (Frühjahr) fiele, wird nicht
  übersprungen.
- **Eingegebene Ortszeiten**: Eine Zeit in der fehlenden Stunde wird beim Termin abgelehnt (mit
  Vorschlag), bei Verkaufsfenstern um die Lücke nach vorn verschoben (02:30 → 03:30); eine doppelte Zeit
  nimmt die erste (Sommerzeit). Zeitfenster-Serien von Servicebuchungen (`generate_slots`) beginnen eine
  doppelte Öffnungszeit beim ersten und enden eine doppelte Schließzeit beim zweiten Vorkommen; die
  Fenster folgen im Abstand echter Minuten (Herbst: eine Stunde mehr Zeitfenster, Frühjahr: eine weniger).
- **Kalenderdateien (ICS)** schreiben alle Zeiten in UTC (`…Z`), ohne `TZID`/`VTIMEZONE`.
- **Berichte** (Umsatzbericht, Buchhaltungsexport, REST-Eventbericht) nutzen Kalendertage in der
  Zeitzone der Website; der Frühjahrstag hat 23, der Herbsttag 25 Stunden. Eine Buchung um 00:30 Ortszeit
  gehört zum neuen Tag. Auch die Standardzeiträume („bis heute“) richten sich nach dem Ortsdatum.

Die Tests dazu (`tests/integration/modules/zz_a199.php`) stellen die Uhr über `wpcap_now` auf die
Umstellungen in Europe/Vienna und America/New_York, statt zu warten.

## Geldbeträge, Rundung und Währungen (A200)

Beträge werden als Ganzzahlen in der **Nebeneinheit der Bestellwährung** gespeichert (EUR: Cent, JPY: Yen
ohne Nachkommastellen, KWD/BHD: Fils = 1/1000). Die Nachkommastellen je Währung liefert
`PassCaptain\Money::decimals()` nach ISO 4217 (Filter `wpcap_currency_decimals`); alle Umrechnungen für
Anzeige (Mails, PDFs, Belege, Admin), CSV, DATEV-Buchungsstapel, Fiskal-Schnittstellen und
Zahlungsanbieter laufen über `Money::to_decimal()`, `Money::to_minor()`, `Money::number()` und
`Money::format()` – nie über ein festes „/100“. Die Währungsliste ist über `wpcap_currencies` erweiterbar.
Im Preisfeld der Ticketart gilt bei Währungen mit drei Nachkommastellen „12,345“ als 12,345 (nicht 12.345).

Rundungsregeln (alle in Ganzzahlen, ohne Gleitkomma):

- **Zeile**: Bruttobetrag = Preis × Menge (oder der vom Kanal gelieferte Zeilenbetrag); Summe der Zeilen =
  Zwischensumme.
- **Rabatt**: Jeder Rabattcode verteilt seinen Betrag anteilig (abgerundet) auf die passenden Zeilen; den
  Rundungsrest nimmt die letzte passende Zeile, was sie nicht aufnehmen kann, die ersten Zeilen mit Platz.
  Die Zeilenrabatte ergeben genau den Rabatt; eine Zeile wird nie negativ. Für ältere Bestellungen ohne
  gespeicherten Zeilenrabatt gilt dieselbe Verteilung (`OrderLines::after_discount()`).
- **Umsatzsteuer**: je Zeile aus dem Brutto nach Rabatt, kaufmännisch gerundet
  (`Orders::gross_tax()`: Brutto × Satz / (10000 + Satz), ab ,5 aufgerundet). Die Summe der Zeilen ergibt
  die Steuer der Bestellung, je Steuersatz und insgesamt.
- **Erstattung**: Ticket-Erstattungen nehmen den Anteil der Tickets am bezahlten Betrag, eine Erstattung des
  Rests den ganzen offenen Betrag und die ganze offene Steuer; Teilbeträge in beliebig vielen Schritten
  enden daher genau bei 0. Mehr als der offene Betrag wird abgelehnt.
- **Aufteilung von Erstattungen** auf Steuersätze und Zeilen (Rechnungskorrekturen, Umsatzbericht,
  Abrechnungsblatt; `Invoices::refund_line_parts()`): größter Rest, Gleichstand an die frühere Zeile.
  Frühere Erstattungen derselben Bestellung werden zuerst verteilt; keine Zeile erhält über alle
  Erstattungen mehr Brutto oder Steuer, als sie bezahlt bzw. getragen hat, und die Teile ergeben immer
  genau Betrag und Steuer der Erstattung.
- **Berichte mit mehreren Währungen**: Beträge verschiedener Währungen werden nicht addiert; maßgeblich ist
  der Abschnitt je Währung. Die gemeinsamen Abschnitte im CSV verwenden die Nachkommastellen der ersten
  Währung.

Hinweis: Der Schwellenwert „Name und Anschrift ab Bestellbetrag“ (`billing_required_threshold_minor`) ist
ein Betrag in Nebeneinheiten und wird nicht umgerechnet; für Währungen mit anderer Nebeneinheit über den
Filter `wpcap_billing_required_threshold` je Währung setzen.

Die Eigenschaftstests (`tests/integration/modules/zz_a200.php`, fester Seed) prüfen diese Regeln mit
zufälligen Bestellungen, Rabatten, Steuersätzen und Erstattungsschritten in EUR, JPY, KWD und BHD.
