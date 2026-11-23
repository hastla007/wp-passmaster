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
auf MariaDB 12.3 und MySQL 9.7; Details in [TESTING.md](TESTING.md#restore-probe-testsdrillsrestore-drillsh)):
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

## Datenbank-Anforderungen

InnoDB mit `utf8mb4`. Jede Indexspalte bleibt unter 767 Byte (höchstens 191 Zeichen, sonst Präfixindex), zusammengesetzte Indizes unter 3072 Byte – das Schema ist damit auch ohne `innodb_large_prefix` anlegbar. Ein Test prüft das für alle Tabellen.

### Replikate und Cluster (A285)

Verkauf, Zahlung, Einlass und Warteschlangen verlassen sich auf Transaktionen mit Zeilensperren
(`SELECT … FOR UPDATE`) und benannte Sperren (`GET_LOCK`). Datenbank-Drop-ins mit Lese-Replikaten
(**HyperDB**, **LudicrousDB** und kompatible) schicken jedes `SELECT` an ein Replikat – auch die sperrenden
Lesezugriffe, die dann ohne Transaktion und ohne Sperre auf evtl. veralteten Daten liefen (Doppelverkauf,
doppelte Zahlung möglich). Das Plugin erkennt solche Drop-ins (`send_reads_to_masters()` bzw.
`send_reads_to_primaries()`) und schaltet über den Filter `query` alle weiteren Lesezugriffe der Anfrage auf
den Primärserver, sobald `START TRANSACTION`/`BEGIN`, ein sperrendes `SELECT` (`FOR UPDATE`, `FOR SHARE`,
`LOCK IN SHARE MODE`) oder `GET_LOCK()` kommt (`PassCaptain\DbReplication`). Reine Leseseiten (Eventliste)
nutzen bis dahin weiter die Replikate.

Die Namen der `GET_LOCK`-Sperren gelten serverweit, nicht je Datenbank. Sie enthalten deshalb einen Hash aus
Tabellenpräfix und `DB_NAME` (`PassCaptain\DbLock`, Upgrade-Sperre `Installer::lock_name()`), sodass mehrere
Installationen auf einem Datenbankserver – auch mit demselben Präfix `wp_` – sich nicht gegenseitig sperren.

Voraussetzungen im Cluster:

- Das Drop-in muss den WordPress-Filter `query` vor der Verteilung anwenden (HyperDB und LudicrousDB tun das).
  Andere Lastverteiler (ProxySQL, MaxScale, Cloud-Proxys) müssen Anfragen innerhalb einer Transaktion und
  `SELECT … FOR UPDATE`/`GET_LOCK` an den Primärserver leiten (bei ProxySQL `transaction_persistent=1` und
  eine Query-Regel für `FOR UPDATE`; MaxScale readwritesplit macht das standardmäßig).
- Nur **ein** schreibender Knoten: Galera/MariaDB-Cluster bzw. Group Replication im Multi-Primary-Betrieb sind
  nicht unterstützt (Sperren gelten nur auf dem eigenen Knoten); Schreibzugriffe auf einen Knoten bündeln.
- Replikationsverzögerung: Anzeigen (z. B. Restkontingent auf der Eventkarte) können kurz veraltet sein;
  maßgeblich ist immer die Prüfung in der Transaktion auf dem Primärserver.

## Datenbank- und PHP-Freigaben

Freigegeben ist nur, was die CI bei jedem Push mit der vollständigen Integrationssuite und dem Last-/Nebenläufigkeitstest (`tests/load/run.php --profile=ci`) prüft (`.github/workflows/ci.yml`, Job `integration`):

| PHP | Datenbank | Rolle |
| --- | --- | --- |
| 8.5 | MariaDB 12.3 | Minimum PHP + Minimum MariaDB |
| 8.5 | MySQL 9.7 | Minimum PHP + Minimum MySQL |
| 8.6 | MariaDB 12.3, MySQL 9.7 | experimentell (nicht blockierend) |

- **Zu alte PHP- oder WordPress-Version** (unter PHP 8.5 bzw. WordPress 7.0, z. B. nach einem PHP-Downgrade beim Hosting): Das Plugin lädt nicht und zeigt Administratoren nur einen Hinweis; die Website läuft weiter (A511). Composers eigene Plattformprüfung (`vendor/composer/platform_check.php`, beendet jede Anfrage mit einem Fatal Error) ist abgeschaltet (`config.platform-check: false`), der Release-Build bricht ab, falls sie doch im ZIP landen würde.
- **Unterstützt (MINVER-1, 17.11.2026, einheitlich für alle Captain-Plugins):** PHP 8.5+, WordPress 7.0+, WooCommerce 11.2.2+, MySQL 9.7+, MariaDB 12.3+. Ältere Datenbankserver werden nicht gesperrt, „Website-Zustand“ zeigt aber eine Warnung (`wpcap_db_server`); sie werden nicht getestet und sind nicht freigegeben; Transaktionserkennung (`@@in_transaction` bzw. Savepoint-Probe), Sperrverhalten und Deadlock-Wiederholungen sind nur auf den getesteten Versionen abgesichert.
- **Echte WordPress-/WooCommerce-Installationen** prüft zusätzlich `.github/workflows/wp-matrix.yml` (WordPress 7.0/aktuell, WooCommerce 11.2.2/aktuell × HPOS/Beitragsspeicher, Release-ZIP in frischer Installation) gegen MariaDB 12.3; wöchentliche Lastgrenzen gegen MariaDB und MySQL in `load.yml`.
- **Pro Release** wird die Matrix des Release-Commits im Abnahmeprotokoll verlinkt (`docs/release-acceptance/`); eine neue Datenbank- oder PHP-Version gilt erst als freigegeben, wenn sie in diese Matrix aufgenommen wurde und grün ist.

## Schema-Upgrade nach einem Update

Das Datenbank-Upgrade (`dbDelta` für alle Kern- und Modultabellen) läuft nicht mehr bei jedem Seitenaufruf:

- **Admin-Aufrufe** (`admin_init`), **WP-Cron** und **WP-CLI** vergleichen die gespeicherte Schema-Version (`wpcap_db_version`, Kernversion plus Hash der Modul-Schemata) und führen das Upgrade aus. Es läuft hinter einer Datenbanksperre (`GET_LOCK`, je Site); parallele Aufrufe ohne Sperre überspringen es und arbeiten mit dem bisherigen Schema weiter. Auch die Aktivierung installiert hinter dieser Sperre; sie wartet bis zu 20 Sekunden (`wpcap_activation_lock_wait`) und überlässt das Schema sonst dem laufenden Upgrade (A512). Die Sperre endet automatisch, wenn der Prozess abbricht.
- **Frontend- und REST-Aufrufe** prüfen nur einen günstigen Fingerabdruck (Schema-/Plugin-Version und Modulliste, Option `wpcap_schema_fingerprint`). Weicht er ab, wird einmalig das Cron-Ereignis `wpcap_run_upgrade` eingeplant. Nur wenn dieses Ereignis länger als fünf Minuten überfällig ist (WP-Cron läuft nicht), übernimmt ein einzelner Frontend-Aufruf das Upgrade hinter derselben Sperre.
- Bis zum Upgrade läuft der neue Code gegen das alte Schema. Das ist unkritisch, weil Migrationen nur Tabellen, Spalten und Indizes ergänzen; nach einem Update trotzdem zeitnah den Admin-Bereich öffnen oder `wp cron event run wpcap_run_upgrade` ausführen.
- **Fehlgeschlagenes Upgrade:** Version und Fingerabdruck werden nur nach vollständigem Erfolg gespeichert; die Fehlerdetails stehen in der Option `wpcap_schema_error`, im PHP-Fehlerprotokoll und als Admin-Hinweis. Wiederholungen laufen mit **Backoff** (1, 5, 15, danach alle 60 Minuten; Zähler `attempts`, Zeitpunkt `next_retry` in der Option), damit eine defekte Datenbank nicht bei jedem Admin- oder Cron-Aufruf erneut `dbDelta` ausführt. Eine neue Plugin-/Schema-Version wird sofort versucht. Nach Behebung der Ursache unter **Pass Captain → Systemstatus → Datenbankschema → „Jetzt erneut versuchen“** sofort wiederholen (ignoriert den Backoff, läuft hinter derselben Sperre) oder per `wp pass-captain diagnose --repair=schema`.
- Die `CREATE TABLE`-Anweisungen (Kern, Verkaufsschema, Module) liefert `Installer::schema_statements()`; `install()` enthält nur noch den Ablauf (Sperre, Vorarbeiten, `dbDelta`, Prüfung, Datenmigrationen, Version). Die REST-Routen des Kerns registriert `Rest\Controller::register_routes()` über je eine Methode pro Ressourcengruppe in unveränderter Reihenfolge (A401).
- `dbDelta` ergänzt nur. Was es nicht kann, erledigt das Upgrade selbst (Schema 3.10.4, `Installer::upgrade_indexes()`): Ein Index, dessen Spalten, Präfixlänge oder Eindeutigkeit sich geändert hat, wird in einem einzigen `ALTER TABLE` gelöscht und neu angelegt (schlägt das fehl, z. B. weil ein neuer eindeutiger Index an doppelten Daten scheitert, bleibt der alte Index unverändert); entfernte Indizes (`Installer::OBSOLETE_INDEXES`, bisher `wpcap_order_items.type_order`, abgelöst durch `capacity_sum`) werden gelöscht. Von Betreibern selbst angelegte Indizes bleiben unberührt. Änderungen von NULL/NOT NULL übernehmen die Datenmigrationen der Module (z. B. Kasse).
- Bricht ein Upgrade ab (Fehler, Zeitüberschreitung, Prozessende), bleibt `wpcap_db_version` auf der alten Version und die Sperre wird freigegeben; der nächste Admin-/Cron-Aufruf setzt es fort. Alle Schritte sind wiederholbar, einmalige Datenmigrationen laufen trotzdem nur einmal (geprüft in `tests/upgrade/run.php`, siehe TESTING.md).
- **Dauerhaft fehlschlagendes Upgrade (A466):** Fehlen nach `dbDelta` Tabellen oder Spalten (z. B. fehlt dem Datenbankbenutzer das Recht `ALTER`/`CREATE`) oder fehlt bzw. weicht ein deklarierter Index ab (z. B. `UNIQUE KEY` über doppelte Zeilen, Sperr-Zeitüberschreitung beim `ALTER`; Eintrag „Tabelle: index Name“, A513), speichert das Plugin den Fehlversuch in `wpcap_schema_error` (fehlende Objekte, Datenbankfehler, Zielversion, Anzahl der Versuche, nächster Versuch; Audit-Eintrag `schema_upgrade_failed` je Versuch) und wartet mit dem nächsten automatischen Versuch: 5 Minuten, je weiterem Fehlversuch doppelt so lange, höchstens 6 Stunden (Filter `wpcap_schema_retry_delay`). In dieser Zeit führen weder Admin- noch Cron- noch Frontend-Aufrufe `dbDelta` erneut aus; das Cron-Ereignis `wpcap_run_upgrade` wird auf den nächsten Versuch gelegt. Bricht ein Upgrade mehrmals hintereinander ab, ohne fertig zu werden (Zeitlimit, Fatal Error; Option `wpcap_schema_attempt`), gilt ab dem zweiten Abbruch dieselbe Wartezeit. Sofort erneut versuchen: Knopf „Datenbank-Update jetzt erneut versuchen“ im Admin-Hinweis (nur `manage_options`, Audit `schema_upgrade_retried`), `wp pass-captain upgrade` oder Deaktivieren/Aktivieren. Eine neue Plugin-Version (andere Zielversion) versucht es sofort. Schlägt schon die Erstinstallation fehl, werden keine wiederkehrenden Cron-Ereignisse eingeplant.

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

E-Mail-Warteschlange (`wpcap_process_messages`, zusammen mit SMS), Jobs und Webhooks (`wpcap_process_jobs`, `wpcap_webhooks_dispatch`) laufen alle fünf Minuten. Jeder Lauf arbeitet mit einem gemeinsamen **Zeitbudget von 20 Sekunden** (höchstens `max_execution_time` − 15 s; Filter `wpcap_cron_time_budget`) und bis zu 500 E-Mails (Filter `wpcap_message_batch_size`). Das Budget wird vor jeder Entnahme geprüft, sodass kein Eintrag reserviert und dann liegen gelassen wird; bricht PHP während eines Eintrags ab, wird die Reservierung beim Beenden freigegeben. Bleibt ein Rückstand, plant der Lauf sofort einen Folgelauf ein – über Action Scheduler (`as_enqueue_async_action`, Gruppe `wp-pass-captain`), wenn vorhanden (z. B. mit WooCommerce), sonst als einzelnes WP-Cron-Ereignis. Bei Action Scheduler zählt nur eine *wartende* (pending) Fortsetzung als schon geplant; die gerade laufende verhindert die nächste nicht.

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

**Fehlerhafte Handler:** Wirft ein Job-Handler, eine Mail-Vorlage, ein SMS-Anbieter oder ein Webhook-Listener eine Exception, fängt der Worker sie ab: der Eintrag wird mit Backoff (1, 2, 4 … Minuten) erneut versucht, nach den maximalen Versuchen `dead_letter`/`failed`, die (redigierte) Meldung steht als letzter Fehler im Systemstatus und im Log (`queue_handler_exception`). Auch eine bei Fatal Error/Zeitlimit freigegebene Reservierung wartet den Backoff ab und bekommt den Fehlertext – ein „giftiger“ Eintrag blockiert die übrigen nicht mehr.

**Abgelaufene Reservierungen** gibt `Orders::expire_holds()` stündlich (`wpcap_cleanup_reservations`) **und** im 5-Minuten-Lauf `wpcap_process_jobs` frei (höchstens das halbe Zeitbudget, Folgelauf bei vollem Stapel; Stapelgröße Filter `wpcap_expire_holds_batch`, Standard 200). Damit werden Kontingent und limitierte Rabattcodes binnen Minuten wieder verfügbar. Jede Bestellung läuft in einer eigenen kurzen Transaktion (Bestellzeile, dann ihre Reservierungen per ID); Datenbankfehler werden protokolliert (`expire_holds_failed`) und beim nächsten Lauf wiederholt.

**Lange Vorgänge im Hintergrund:**

- **Termin absagen:** Alle gültigen Tickets werden sofort mit gebündelten UPDATEs widerrufen – noch bevor Geld fließt. Danach laufen die Folgearbeiten als Job-Pakete (`occurrence_cancel_hooks` je 200 Tickets: Wallet, Webhooks, SMS, Sitzplätze; `occurrence_cancel_refunds` je 5 Bestellungen, mit Zeitbudget-Prüfung je Bestellung – der Rest wird als neuer Job eingereiht; `occurrence_cancel_notify` je 100 Empfänger, die je eine eigene Queue-Mail mit Dedupe-Schlüssel bekommen).
- **Terminverschiebung / Event-Absage:** Mails je Empfänger über die E-Mail-Queue (siehe docs/MAIL-TEMPLATES.md), Fortschritt in der Admin-Meldung.
- **Datenschutz-Aufbewahrung:** Reicht das Zeitbudget nicht, plant der Lauf sofort eine Fortsetzung und beginnt beim nächsten Mal mit dem unfertigen Schritt (Option `wpcap_retention_next_step`); Heartbeat `retention` (Intervall 1 Tag) im Systemstatus. Die ersten Pakete laufen noch im Admin-Request (bis 20 s), der Rest über die Job-Queue; die Rückmeldung nennt die Zahl der offenen Pakete, den Fortschritt zeigt der Systemstatus. Ein Abbruch an beliebiger Stelle hinterlässt nie gültige Tickets mit erstattetem Geld.
- **Erinnerungen:** Der Lauf besucht alle Termine im Erinnerungsfenster seitenweise (keine feste Grenze von 200 Terminen mehr), prüft vorhandene Erinnerungen je Termin mit einer Abfrage und hält das gemeinsame Zeitbudget ein; reicht es nicht, merkt er sich die Position (`wpcap_reminder_cursor`) und plant einen Folgelauf.
- **Mollie einrichten (A299):** Über **Einstellungen → Zahlungsarten → Mollie** (Unternavigation und Link „Mollie einrichten“ in der Übersicht). Die Mollie-Seite (`admin.php?page=wpcap-mollie`) hat keinen eigenen Menüpunkt mehr, bleibt aber erreichbar (Lesezeichen, Links); das Menü markiert dort „Einstellungen“. Weitere Fälle solcher Seiten stehen in `Admin\Menu::linked_pages()`.
- **Mollie-Zahlungsarten:** Die Liste wird je Währung und Rechnungsland eine Stunde gecacht und lokal nach den Mindest-/Höchstbeträgen gefiltert (für Nicht-EUR-Währungen je Betrag, weil Mollie die Grenzen in EUR liefert). Der Abruf hat 3 s Timeout; Fehler werden 5 Minuten gecacht, damit die Event-Seite bei einer Mollie-Störung nicht bei jedem Aufruf wartet.
- **CSV-Exporte** (Audit-Log, Fiskal-Archiv) werden seitenweise gelesen und über einen temporären Stream ausgeliefert – ohne Zeilenlimit und ohne den ganzen Export im Speicher. Der Finanzbericht umfasst höchstens 366 Tage; längere Zeiträume werden mit Hinweis gekürzt.

## Datenbank: Transaktions-Isolation, Sperren und Deadlocks

- **Isolation:** Die Kontingentprüfung liest verkaufte und reservierte Tickets in einer Transaktion, nachdem Ticketart und Termin gesperrt sind. Das ist nur unter REPEATABLE READ exakt. Pass Captain setzt deshalb vor jeder eigenen Bestell-, Zahlungs- und Aufräum-Transaktion `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` (gilt nur für diese Transaktion) und liest verkauft + reserviert jeweils in **einem** Statement. Ein Server mit `transaction_isolation = READ-COMMITTED` (verbreiteter WooCommerce-Tuning-Tipp) bleibt so korrekt; der Systemstatus zeigt die Isolation des Servers an und warnt bei einem anderen Wert. Erweiterungen, die über `wpcap_reserve_capacity` eigene Kontingente prüfen, lesen innerhalb dieser Transaktion; eigene Transaktionen sollten ebenfalls REPEATABLE READ setzen.
- **Sperr-Wartezeit:** Checkout und Zahlungsbestätigung setzen für ihre Dauer `innodb_lock_wait_timeout` der Sitzung auf 5 s (Filter `wp_pass_captain_lock_wait_timeout_v1`) und stellen danach den vorherigen Wert wieder her.
- **Deadlocks/Lock-Wait-Timeouts:** Der Checkout wiederholt die Transaktion bis zu zweimal mit kurzer Zufallspause (der Idempotency-Key macht das sicher) und antwortet erst danach mit HTTP 503, `retryable: true` und `Retry-After: 2`. Eine Zahlungsbestätigung antwortet mit 503 + `Retry-After`; der Zahlungsanbieter stellt den Webhook erneut zu.
- **Sperrreihenfolge:** Checkout: Ticketarten → Termine → Kontingent-Pools → Sitzplätze. Verspätete Zahlung: Bestellung → Ticketarten → Termine → Pools/Sitzplätze → Reservierungen (per Primärschlüssel, keine geteilten Sperren). Hold-Aufräumen: Bestellung → Reservierungen.
- **Verspätete Zahlung** (Reservierung abgelaufen): Tickets werden nur ausgestellt, wenn Ticketart aktiv, Termin nicht abgesagt und nicht vorbei, Kontingent, Sitzplätze (erneut belegt in derselben Transaktion), Käuferlimit und Rabattcode noch passen – sonst `needs_review` / `paid_unallocated` mit Admin-Mail. PayPal zieht nach Ablauf der Reservierung kein Geld mehr ein („Reservierung abgelaufen, keine Zahlung eingezogen“). Verzögerte Zahlarten halten die Reservierung höchstens bis zum Beginn des Termins. Eine Terminabsage storniert auch abgelaufene Bestellungen mit bereits gestarteter Anbieter-Zahlung.

## Rate-Limits ohne persistenten Object-Cache

Ohne persistenten Object-Cache zählen alle Plugin-Limits (Checkout, Servicebuchungen, Wartelisten, Sitzplatz-Holds, Partner- und Entwickler-API, Kopplung, Portal, SMS) in der Tabelle `wpcap_rate_limits` (seit Schema 3.16.0): eine Zeile je Zähler (`bucket_key` = HMAC des Zählernamens, `window_start`, `hits`, `expires_at`), hochgezählt mit einem einzigen atomaren `INSERT … ON DUPLICATE KEY UPDATE`. Parallele PHP-Worker können das Limit dadurch nicht mehr überholen; die Optionstabelle bleibt frei von Transient-Zeilen je IP. Die Tabelle ist klein (Zeilen leben nur bis zum Fensterende) und wird vom täglichen Löschlauf in Stapeln bereinigt; bei sehr vielen Zugriffen von wechselnden Adressen wächst sie bis dahin um eine Zeile je Adresse und Fenster. Mit persistentem Object-Cache (Redis/Memcached) bleibt es bei `wp_cache_incr()` – die Tabelle wird dann nicht benutzt. Fehlt die Tabelle (Update, Schema-Upgrade noch nicht gelaufen), zählen die Limits übergangsweise wie früher in Transients. Zum Zurücksetzen aller Zähler (z. B. nach einem Test) genügt `DELETE FROM wp_wpcap_rate_limits`.

## Eventseiten: Shortcode/Block, Seiten-Cache und Vorverkaufsstart

`[wp_pass_captain_events]` bzw. der Block „Pass Captain Events“ kennen die Attribute `event` (nur dieses Event),
`occurrence` (direkt die Ticketauswahl dieses Termins), `limit` (1–200, Standard 50) und
`layout="auto|list|cards"`. `auto` (Standard) zeigt bis zu drei Termine mit vollständigem Ticketformular, mehr als
kompakte Terminliste mit „Tickets“-Button (Filter `wpcap_events_auto_list_threshold`); Serien mit mehr als drei Terminen
bekommen eine Datumsauswahl. Das Formular lädt erst die Einzelansicht `?wpcap_occ={Termin-ID}` derselben Seite – eine
Seite mit 60 Terminen enthält damit kein einziges Formular. Kalendereinträge verlinken auf diese Einzelansicht: auf die
in „Kalender“ eingetragene Seite, sonst automatisch auf die erste veröffentlichte Seite mit dem Shortcode/Block (ohne
`occurrence`-Attribut; 24 h zwischengespeichert, beim Speichern eines Beitrags verworfen), ohne solche Seite auf der
Kalenderseite selbst.

Eventseiten dürfen vom Seiten-Cache/CDN gecacht werden: Sie enthalten für Gäste keine Nonce (die REST-Nonce wird nur
angemeldeten Käufern ausgeliefert, damit Käuferlimits „pro Konto“ greifen). Verfügbarkeit und Verkaufsfenster
aktualisiert `assets/checkout.js` live über `GET /wp-json/pass-captain/v1/checkout/live?occurrences=…` (je Termin: Preis,
Verfügbarkeit, Verkaufsfenster). Für eigene Integrationen bleibt `GET /wp-json/pass-captain/v1/availability?types=1,2,3`
als schlanker Abruf je Ticketart erhalten (öffentlich, keine personenbezogenen Daten,
`Cache-Control: public, max-age=5, stale-while-revalidate=10`, 120 Abrufe je Minute und Client-Netz; N11). Aktualisiert wird kurz nach dem Laden, genau zum nächsten Verkaufsstart (mit bis zu 2 s Streuung) und danach alle 20 s
(Filter `wpcap_availability_poll_seconds`, 15–60 s), nur solange die Seite sichtbar ist. Ticketarten vor ihrem
Verkaufsstart stehen mit „Verkauf ab …“ bzw. Countdown auf der Seite und werden zum Start freigeschaltet. Ausverkaufte
Ticketarten bieten „Auf die Warteliste“ an (Double-Opt-in; abschaltbar mit `wpcap_waitlist_frontend`). Wer den Seiten-
Cache gezielt leeren will, hängt sich an `wp_pass_captain_availability_changed_v1` (siehe [HOOKS.md](HOOKS.md)); den
Endpunkt selbst sollte ein CDN höchstens 5 s cachen.

Nach einer gehosteten Zahlung (Stripe, PayPal, Mollie) kehrt der Käufer auf die Seite zurück, auf der er bestellt hat
(nur dieselbe Website). Seiten mit `?wpcap_payment=…` werden nie gecacht (`DONOTCACHEPAGE`, `nocache_headers()`); der
Hinweis erscheint über `wp_body_open`, bei Themes ohne diesen Hook am Anfang des Inhalts. Nach Abbruch oder
fehlgeschlagener Zahlung bietet er „Erneut bezahlen“ (neue Zahlungsseite für dieselbe Bestellung, solange die
Reservierung läuft) und „Reservierung freigeben“ (sofortige Stornierung der unbezahlten Bestellung; eine offene
Stripe-Checkout-Session wird vorher beendet). Beide Aktionen sind über das Rückkehr-Token `wpcap_rt` der Rückkehr-URL
berechtigt. Hinweis: Mollie-Zahlungen lassen sich nicht immer serverseitig abbrechen – kommt nach der Freigabe doch
eine Zahlung an, greift der bestehende Ablauf für Zahlungen ohne Kontingent.

### Begriffe und Theme-Vorlagen (A690)

**Begriffe** (Einstellungen → Allgemein → Begriffe): Statt „Teilnehmende“ zeigen Eventseiten, Checkout, Bestellportal,
Ticketseite und E-Mails „Gäste“ oder „Besucher:innen“ (Vorgabe) bzw. eigene Wörter. Die Vorgaben ersetzen die
deutschen Wortformen der Plugin-Texte einschließlich der Fälle mit Artikel („den Teilnehmenden“ → „den Gästen“,
„Name der Teilnehmer:in“ → „Name des Gastes“) und der zusammengesetzten Wörter („Teilnehmerdaten“ → „Gästedaten“).
**Eigene Ersetzungen** („Alt = Neu“, eine je Zeile, höchstens 60) ergänzen die Vorgabe und haben Vorrang; mit
„Nur eigene Ersetzungen“ gelten nur sie – so auch für andere Sprachen („attendee = guest“). Ersetzt werden ganze
Wörter in den übersetzten Texten des Plugins, die längsten zuerst und in einem Durchgang. Nicht betroffen: die
Verwaltung (Filter `wpcap_terms_in_admin`), Texte aus der Datenbank (Eventnamen, eigene Mailvorlagen, Feldnamen) und
Theme-Vorlagen. Für Entwickler: Filter `wpcap_terms_map`.

**Theme-Vorlagen:** Neben `event-card.php`, `event-single.php`, `ticket-page.php`, `order-portal.php`,
`ticket-recovery.php` und `email-layout.php` lassen sich jetzt auch `calendar-list.php` und `calendar-month.php`
(Kalender-Shortcode/-Block), `passes.php` (Pässe-Shortcode) und `service-booking.php` (Servicebuchung) im Ordner
`<theme>/pass-captain/` überschreiben (Child-Theme vor Eltern-Theme). Die Vorlage erhält die Variablen aus
`docs/HOOKS.md` (`wpcap_template_page`) und `$default_html` (die Ausgabe des Plugins, z. B. zum Umschließen) und
escaped selbst.

### Sammel-Ein- und -Auslass und Paketablauf der Geräte (A688)

**Ticketliste (Tickets):** Tickets, die die Person einlassen darf (gültig oder eingecheckt), haben ein
Auswahlfeld. „Ausgewählte Tickets einchecken/auschecken“ (optional mit Einlassbereich) → **Weiter zur
Bestätigung** bucht noch nichts: Über der Liste steht „N Tickets einchecken?“ mit den Ticketnummern und den Knöpfen
„Ja, einchecken“ und „Abbrechen“. Erst die Bestätigung führt den Lauf aus (`Admission\BulkCheckin`, höchstens 100
Tickets, Filter `wpcap_bulk_checkin_max`). Jedes Ticket wird wie ein Scan geprüft (Termin, Bereich, Einlassregel,
Kapazität; Protokoll mit Quelle `admin`). Danach zeigt ein **Ergebnisbericht** je Ticket „erledigt“, „bereits im
Zielzustand“, den Ablehnungsgrund oder „nicht verbucht – erneut versuchen“ (vorübergehender Datenbankfehler) und die
Summen. **Wiederholen** („Nicht verbuchte Tickets erneut versuchen“) nutzt denselben Sammelschlüssel: Je Ticket gilt
der Idempotenzschlüssel `admin:bulk:<Schlüssel>:<Ticket>`, ein bereits gebuchtes Ticket wird nie doppelt eingelassen,
und ein doppelt abgeschickter Bestätigungsknopf bucht nichts zweimal. Abgelehnte Tickets (z. B. Einlassfenster noch
nicht offen) bleiben im Bericht; sie werden später mit einer neuen Auswahl erneut versucht. Auswahl und Bericht
gehören der angemeldeten Person und verfallen nach einer Stunde. Dieselbe Logik bietet die App über
`POST /app/scan/batch` (siehe [MOBILE-API.md](MOBILE-API.md)).

**Scanner-Geräte:** Die Geräteliste hat die Spalte **Offline-Paket**: „gültig bis …“ (das nächste Ablaufdatum eines
noch gültigen Pakets: letzter Abruf plus „maximale Offline-Dauer“ des Events – derselbe Zeitpunkt, der als
`expires_at` im signierten Paket steht), „abgelaufen am … – vor dem Einsatz neu laden“ oder „kein Paket geladen“
(Gerät für Offline zugewiesen, aber noch nie abgerufen). Unter **Offline-Scanner** zeigt die Gerätetabelle je
Zuweisung zusätzlich „Paket gültig bis“.

## Bestellsuche im Admin

Die Suche unter **Pass Captain → Bestellungen** nutzt die Indizes der Bestelltabelle (PERF-15):

- **Vollständige E-Mail-Adresse** (`anna@example.com`): exakter Treffer (Index `customer_email`; Groß-/Kleinschreibung egal).
- **Anfang einer Adresse oder Bestellnummer** (`anna`, `anna@exa`, `3F2A-9C1E`): Präfixsuche (`anna%`), ebenfalls über den Index.
- **Domain** mit führendem `@` (`@example.com`): Suche am Ende der Adresse – durchsucht alle Bestellungen und ist bei sehr großen Tabellen langsamer.
- Teile aus der Mitte einer Adresse (`nna@`) werden nicht mehr gefunden; dafür bitte den Anfang der Adresse oder die Domain eingeben.

## Systemstatus, Diagnose und Monitoring

**Pass Captain → Systemstatus** (Capability `manage_options`) ist die einzige Status-Seite (N04) mit den Reitern
*Übersicht* (dieser Abschnitt) und *Betrieb* (siehe „Betriebsübersicht“ unten; die frühere Seite
`admin.php?page=wpcap-operations` leitet dorthin weiter). Der Reiter *Übersicht* zeigt den Hintergrundbetrieb auf einen Blick:

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
- Die Übersicht zeigt zusätzlich die Termine der nächsten 30 Tage je Site und ist in Seiten zu 20 Sites geteilt (die Zustandsprüfung läuft nur für die angezeigten Sites).
- Der Netzwerkbericht (CSV, eine Zeile je Site und Währung) wird auf der Hauptsite protokolliert (`network_report_exported`) und fällt unter die 2FA-Kategorie „Exporte“.
- **Netzwerkbericht für einen Zeitraum (A162):** Von/Bis (höchstens 366 Tage, Standard die letzten 30 Tage) je Site und Währung: bezahlte Bestellungen und Tickets nach Bestelldatum, Brutto, abgeschlossene Erstattungen nach Abschlussdatum und Netto – jeweils in der Zeitzone der Site, ohne Freikarten (`wpcap_comp_payment_providers`). Summen nur je Währung (nie gemischt). Die Seite zeigt die Sites der aktuellen Übersichtsseite, die CSV (`edit.php?action=wpcap_network_report&from=…&to=…`, protokolliert mit Zeitraum) alle Sites inklusive Summenzeilen. Abweichungen zum Umsatzbericht einer Site: dieser verteilt Rabatte und Erstattungen auf Positionen und filtert nach Event-Berechtigungen; der Netzwerkbericht summiert Bestellbeträge.
- Isolation: jede Zahl wird innerhalb von `switch_to_blog()` mit dem Präfix der Site gelesen; Tabellen verschiedener Sites werden nie gemeinsam abgefragt. Sites ohne Plugin-Tabellen erscheinen als „nicht installiert“. Große Netzwerke können die Site-Liste mit dem Filter `wpcap_network_site_ids` begrenzen.

Prüfen, ob der externe Cronjob greift: Unter **Werkzeuge → Website-Zustand** meldet die Prüfung „geplante Aufgaben“ den letzten Lauf der Warteschlangen; mit `wp cron event list --fields=hook,next_run_relative | grep wpcap_` sind überfällige Ereignisse sofort zu sehen.

### Garantien bei Worker-Fehlern und Neustarts (A188)

| Fall | Verhalten |
|---|---|
| Zwei Worker gleichzeitig (überlappende Cron-Läufe, Action Scheduler, System-Cron) | Ein Job wird per Zeilensperre und bedingtem Statuswechsel genau einem Worker zugeteilt; der Handler läuft erst, wenn diese Zuteilung committet ist. Ist das Ergebnis des COMMIT unklar, läuft er nicht (die Zuteilung wird nach 15 Minuten zurückgeholt). Getestet mit vier parallelen Prozessen. |
| Handler scheitert (`WP_Error`, `false`, Exception) | Erneut eingeplant nach 2, 4, 8 … Minuten (`Jobs::backoff_seconds()`: 60 s · 2^Läufe, höchstens 1 Tag); letzter Fehler in `last_error` (eine Zeile, E-Mail-Adressen maskiert). |
| Endgültig gescheitert | Nach 8 Läufen (`Jobs::MAX_ATTEMPTS`) Status `dead_letter`: Audit `job_dead_letter`, Hook `wpcap_job_dead_letter`, Liste unter **Betrieb** (erneut versuchen/verwerfen) und Warnung im Website-Zustand. Ein Dead Letter läuft nie von selbst wieder. |
| Worker stirbt während eines Jobs (Fatal Error, Zeitlimit) | Die Zuteilung wird beim Beenden des PHP-Prozesses zurückgegeben – mit Backoff und dem Vermerk „Worker … beendet“ –, nicht sofort erneut gestartet. |
| Worker wird hart beendet (kill, Server-Neustart) | Nach 15 Minuten holt der nächste Lauf die Zuteilung zurück (Backoff, Vermerk „zurückgeholt“). Ein Job, dessen letzter erlaubter Lauf so endete, wird beim nächsten Lauf zum Dead Letter statt endlos neu zu starten. |
| Worker stirbt nach der Wirkung, vor „erledigt“ | Der Job läuft erneut: Ausführung ist **mindestens einmal**. Die eingebauten Jobs sind idempotent: `ticket_mail_retry` erkennt eine seit dem Einplanen erfolgreich versendete Ticket-Mail (Audit `tickets_resent`) und sendet nicht noch einmal (die versendeten Codes bleiben gültig); `ticket_issue_retry`, `order_cancel_retry`, `order_refund_mark_retry`, `refund_reconcile`, `voucher_commit` und `wallet_sync` prüfen den aktuellen Zustand. Eigene Handler (Hook `wpcap_process_job`) müssen ebenso idempotent sein; `$job_id` hilft, eine Wiederholung zu erkennen. Geht nur die Datenbankverbindung beim Abschluss verloren, wird der Abschluss einmal auf der neuen Verbindung wiederholt. |
| Bezahlt, aber die Ticketausstellung lief nie (Prozess nach dem Verbuchen der Zahlung beendet, Job nicht einplanbar, `ticket_issue_retry` als Dead Letter) | Der stündliche Lauf `wpcap_cleanup_reservations` sucht bezahlte Bestellungen ohne Ticket, deren Zahlung 10 Minuten bis 2 Tage zurückliegt (`Orders::sweep_unissued()`), und plant je Bestellung **einmal** einen `ticket_issue_retry`-Job ein (Audit `ticket_issue_swept`). Gilt für jeden Zahlungsweg (Webhook, Rückkehrseite, WooCommerce, Partner, manuelle Bestätigung). Zusätzlich versucht jeder Lauf der Job-Warteschlange fehlende Tickets bezahlter Bestellungen der letzten 2 Tage sofort auszustellen (`Orders::reconcile_issuance()`, auch bei nur teilweise ausgestellten Tickets); eine Bestellung, die er nicht abschließen kann, bekommt denselben einmaligen `ticket_issue_retry`-Job (nicht, solange schon ein Ausstellungs-Job offen ist) und wird danach nicht erneut aufgegriffen. Ältere oder danach noch offene Fälle meldet die Diagnose „Bezahlte Bestellungen ohne Tickets“ (A343). |
| E-Mail-Warteschlange | Ebenfalls mindestens einmal: stirbt der Worker zwischen `wp_mail()` und dem Vermerk „gesendet“, wird die Mail erneut versendet (inhaltsgleich). |
| Webhook-Zustellungen | Mindestens einmal; Empfänger erkennen Wiederholungen an `X-WPCAP-Event-Id` bzw. `X-WPCAP-Delivery` (siehe [WEBHOOKS.md](WEBHOOKS.md)). |

## Seitencache und CDN

Pass Captain funktioniert hinter Seitencaches (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache),
Cloudflare APO und Varnish. Zwei Regeln halten die Ausgabe richtig: persönliche Seiten werden **nie** gecacht,
und Seiten mit Event-Liste oder Kalender werden bei jeder Änderung **gezielt geleert** (A283, A446, A508).

### Was das Plugin selbst tut

- **Persönliche Seiten** setzen vor jeder Ausgabe `DONOTCACHEPAGE`, senden `nocache_headers()`
  (`Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`) und lösen
  `litespeed_control_set_nocache` aus – auch in ihren 404-Zweigen (`PageCache::no_store()`, Action
  `wpcap_page_no_store`): Ticketseite, Bestellportal, Rechnung (HTML und PDF, auch Fehlerantworten),
  Wartelisten-Angebot und -Abmeldung samt Ergebnisseiten und deren 303-Weiterleitungen, Rückkehr von der
  Zahlungsseite und die Seite mit dem Web-Scanner-Shortcode. Formulare dieser Seiten tragen keine kurzlebigen
  Nonces mehr, sondern an den Link gebundene Nachweise (A459, A462) – eine lange offene Seite bleibt benutzbar. Für Rückkehrhinweis und Scanner greift das bereits auf `template_redirect`
  (Priorität 0), also bevor das Theme Header sendet.
- **Purge bei Änderungen:** Jede Änderung, die eine öffentliche Seite verändert, löst am Requestende die Action
  `wpcap_purge_page_cache( $event_id, $urls, $context )` aus – je Event einmal: Event angelegt/geändert/abgesagt,
  Termin angelegt/geändert/verschoben/abgesagt, Ticketart (Preis, Verkaufszeitraum, Status, Kontingent),
  Pässe, Serientermine, Übersetzungen, ausverkauft bzw. wieder verfügbar (Kapazitätszähler kreuzen 0, frei
  gewordene Plätze) und geänderte Rechtstexte (`$event_id` 0 = alle Seiten mit Liste).
- **Welche Seiten:** Ein Index (Option `wpcap_page_cache_index`, beim ersten Gebrauch aus allen
  veröffentlichten Beiträgen aufgebaut, danach bei `save_post` über `has_shortcode()`/`has_block()` gepflegt)
  kennt alle Beiträge mit `[wpcap_events]`/`[wp_pass_captain_events]`, `[wpcap_calendar]`/
  `[wp_pass_captain_calendar]` und den Blöcken `pass-captain/events`/`pass-captain/calendar` (auch unter den
  früheren Namen `wp-pass-captain/…`, A303). Geleert werden die
  Beiträge mit `event`/`occurrence`-Attribut des Events bzw. eines seiner Termine sowie **alle** Beiträge mit
  vollständiger Liste oder Kalender. Listen in Templates, Template-Parts, synchronisierten Mustern oder
  Widgets sieht der Index nicht – solche URLs mit dem Filter `wpcap_page_cache_urls` ergänzen.
- **Verkaufsstart und -ende:** Für jede Ticketart plant das Plugin einmalige Cron-Ereignisse
  `wpcap_page_cache_sales_window` zum Verkaufsstart und eine Sekunde nach Verkaufsende (je Event die nächsten
  50 Zeitpunkte, danach die folgenden). Damit das pünktlich passiert, WP-Cron per System-Cron jede Minute
  ausführen (siehe [Hintergrundverarbeitung](#hintergrundverarbeitung-wp-cron)). Bis dahin zeigt eine
  gecachte Seite den alten Stand; der Checkout prüft Verkaufszeitraum und Verfügbarkeit aber immer serverseitig.
- **Live-Zustand der Eventkarten (A456, A458):** Zwischen zwei Purges (und bei CDNs ohne Purge-Adapter) kann
  die Karte aus dem Cache kommen. Das Checkout-Skript fragt deshalb nach dem Laden (und nach „Zurück“ aus dem
  bfcache) einmal `GET /wp-json/pass-captain/v1/checkout/live?occurrences=…` ab – öffentliche Daten,
  `Cache-Control: no-store`, je Client 120 Anfragen pro Minute (Filter `wpcap_checkout_live_limit`) – und
  schaltet damit Mengenlimits, ausverkaufte Ticketarten, die aktuellen (zeitabhängigen) Preise samt
  Regulärpreis und Preisstufe, „Nur noch … Plätze“, Badges, Zahlungsarten, das Bestellformular bzw. den
  Hinweis „ausverkauft“/„Online-Verkauf derzeit nicht möglich“ und das Wartelistenformular um. Die Karte
  enthält dafür immer das vollständige Formular, das Mengenfeld jeder Ticketart, beide Hinweise und das
  Wartelistenformular – jeweils mit `hidden`, wo sie nicht gelten. Ohne Antwort (offline, 429, HTML einer
  Firewall) bleibt die Karte, wie sie gerendert wurde. Diese Route darf ein CDN/WAF nie cachen (wie den
  ganzen Namespace `/wp-json/pass-captain/`).
- **Geänderte Rechtstexte (A457):** Bestätigt jemand auf einer gecachten Seite die alte Fassung, lehnt der
  Checkout mit 409 `wpcap_terms_changed` ab (in `data.terms_version` die aktuelle Version). Das Skript lädt
  die Texte dann über `GET /checkout/options` (`terms_html`, `consumer_info_html`, `no-store`) in die Karte,
  nimmt das Häkchen zurück und lässt die aktuelle Fassung bestätigen – ein Neuladen würde nur die gecachte
  Seite zeigen. Meldet der Live-Zustand schon beim Laden eine neuere Version, werden die Texte sofort
  ersetzt. Eigene Theme-Vorlagen brauchen dafür die Container `.wpcap-legal[data-legal="terms"]` bzw.
  `[data-legal="consumer_info"]` mit `.wpcap-legal-text` (siehe `templates/event-card.php`); ohne sie bleibt
  es bei der Serverprüfung.

### Mitgelieferte Purge-Adapter

Jeder Adapter läuft nur, wenn sein Plugin aktiv ist (Filter `wpcap_page_cache_adapters` zum Abschalten):

| Produkt | Purge | Nötige Ausnahmen |
|---|---|---|
| **WP Rocket** | `rocket_clean_post()` je Beitrag, `rocket_clean_files()` für alle URLs | „Niemals cachen (URLs)“: `/wp-json/pass-captain/(.*)`; „Niemals cachen (Query-Strings)“: `wpcap_ticket`, `wpcap_order`, `wpcap_access`, `wpcap_invoice`, `wpcap_invoice_pdf`, `wpcap_waitlist_accept`, `wpcap_waitlist_result`, `wpcap_waitlist_unsubscribe`, `wpcap_waitlist_confirm`, `wpcap_payment`, `wpcap_paypal_return`, `wpcap_mollie_return`. Die Scanner-Seite in „Niemals cachen (URLs)“ eintragen. Unter „Datei-Optimierung“ `assets/checkout.js` und `assets/scanner.js` nicht verzögern/kombinieren, falls der Checkout sonst erst nach einer Interaktion startet. |
| **LiteSpeed Cache** | `litespeed_purge_post` je Beitrag, `litespeed_purge_url` je URL | Cache → Ausschließen: „URIs nicht cachen“ `/wp-json/pass-captain/` und die Scanner-Seite; „Query-Strings nicht cachen“ wie bei WP Rocket. LiteSpeed beachtet `DONOTCACHEPAGE` und `litespeed_control_set_nocache` bereits; die Einträge schützen zusätzlich vor ESI/Crawler-Vorwärmung. |
| **W3 Total Cache** | `w3tc_flush_post()` je Beitrag, `w3tc_flush_url()` je URL | Page Cache: „Seiten mit Query-Strings nicht cachen“ einschalten oder die Parameter oben unter „Abgelehnte Query-Strings“ eintragen; „Nie folgende Seiten cachen“: `wp-json/pass-captain/*` und die Scanner-Seite. |
| **WP Super Cache** | `wpsc_delete_url_cache()` je URL | „Seiten mit GET-Parametern nicht cachen“ einschalten; unter „Abgelehnte URL-Zeichenketten“ `wpcap_` und `/wp-json/`. |
| **Cloudflare APO** (offizielles Cloudflare-Plugin) | `CF\WordPress\Hooks::purgeCacheByRelevantURLs()` für die Beiträge, unsere URLs über dessen Filter `cloudflare_purge_by_url` | APO überspringt den Cache bei Query-Strings nur, wenn sie nicht ignoriert werden – keine Cache-Regel „Ignore query string“ für die Website anlegen. Cache-Regel „Bypass cache“ für `/wp-json/pass-captain/*` und die Scanner-Seite. Ohne das offizielle Plugin leert Pass Captain Cloudflare nicht; dann eine eigene Funktion an `wpcap_purge_page_cache` hängen (API „Purge Files by URL“). |
| **Varnish** (generisch, aus) | Je URL eine `PURGE`-Anfrage über den OutboundHttp-Guard, einzuschalten mit dem Filter `wpcap_page_cache_varnish` (siehe unten) | In der VCL `pass` für `req.url ~ "^/wp-json/pass-captain/"`, für die Query-Parameter oben (`req.url ~ "[?&]wpcap_"`) und die Scanner-Seite; Antworten mit `Cache-Control: private/no-store` nie speichern; `PURGE` nur aus dem eigenen Netz erlauben (ACL). |

Varnish einschalten (Must-Use-Plugin oder Theme):

```php
add_filter( 'wpcap_page_cache_varnish', static function ( $config ) {
	return array( 'enabled' => true, 'endpoint' => 'http://127.0.0.1:6081' ) + $config;
} );
```

Ohne `endpoint` geht die Anfrage an die öffentliche URL selbst (nur HTTPS, öffentliche Adresse – Regeln des
Guards). Mit `endpoint` geht sie an diesen Ursprung mit dem `Host`-Header der Seite; der Ursprung wird dem
Guard dafür ausdrücklich als Ausnahme übergeben. Eigene Header (z. B. ein Purge-Token) und `BAN` statt
`PURGE` über `wpcap_page_cache_varnish_request`.

Andere Caches/CDNs (Nginx FastCGI Cache, Fastly, Bunny, Kinsta, WP Engine …) hängen sich an die Action:

```php
add_action( 'wpcap_purge_page_cache', static function ( $event_id, $urls, $context ) {
	foreach ( $urls as $url ) { my_cdn_purge( $url ); }
}, 20, 3 );
```

### Pfade und Parameter, die nie gecacht werden dürfen

| Seite | Pfad / Parameter |
|---|---|
| Ticketseite | `/?wpcap_ticket=<64 Hex>` |
| Bestellportal | `/?wpcap_order=<id>&wpcap_access=<64 Hex>` |
| Rechnung (Käufer) | `/?wpcap_invoice=<id>&wpcap_access=…`, PDF `/?wpcap_invoice_pdf=<id>&wpcap_access=…` |
| Warteliste | Angebot `/?wpcap_waitlist_accept=<id>&token=…`, Ergebnis `/?wpcap_waitlist_result=<id>&wl=…`, Abmeldung `/?wpcap_waitlist_unsubscribe=<id>&token=…`, Bestätigung (Double-Opt-in) `/?wpcap_waitlist_confirm=<id>&token=…`, Formulare an `wp-admin/admin-post.php` |
| Rückkehr von der Zahlung | Checkout-Seite (sonst Startseite) mit `?wpcap_payment=<status>&order=<id>&wpcap_rk=…`, PayPal `?wpcap_paypal_return=1…`, Mollie `?wpcap_mollie_return=1…`; Aktionen „Zahlung fortsetzen“/„Bestellung stornieren“ an `wp-admin/admin-post.php` (`action=wpcap_payment_return`) |
| Web-Scanner | Seite mit `[wp_pass_captain_scanner]`/`[wpcap_scanner]` |
| REST-API | `/wp-json/pass-captain/v1/*` (ohne Permalinks `/?rest_route=/pass-captain/v1/…`) – Checkout, Verfügbarkeit, Scanner-App, Wallet, Webhooks |

`/?wpcap_ics=…` (Kalenderdatei) darf gecacht werden; sie ändert sich mit dem Termin (Revision).

### WAF, Bot-Schutz und Ratenbegrenzung

Zahlungsanbieter und Apple-Geräte rufen die Website ohne Browser, ohne Cookies und ohne JavaScript auf.
Cloudflare „Bot Fight Mode“/„Super Bot Fight Mode“, „Under Attack“, JS-/Managed-Challenges, Wordfence-
oder Sucuri-Firewalls und Ratenlimits des Hosters blockieren sie sonst (Folge: bezahlte Bestellungen bleiben
offen, Wallet-Pässe aktualisieren sich nicht). Ausnahmen (Cloudflare: WAF → Custom Rule „Skip“ für alle
Sicherheitsfunktionen inkl. Bot Fight Mode – der klassische Bot Fight Mode lässt sich nicht per Regel
umgehen und muss dann aus sein; Super Bot Fight Mode „Verified bots: Allow“ reicht nicht):

| Endpunkt | Aufrufer |
|---|---|
| `POST /wp-json/pass-captain/v1/payments/stripe/webhook`, `…/paypal/webhook`, `…/mollie/webhook` | Stripe, PayPal, Mollie (Signatur bzw. Rückfrage beim Anbieter prüft die Echtheit) |
| `/wp-json/pass-captain/v1/wallet/apple/v1/devices/…`, `…/wallet/apple/v1/passes/…`, `…/wallet/apple/v1/log` (POST, GET, DELETE) | Apple Wallet Web Service (Geräte, Header `Authorization: ApplePass …`) |
| `/wp-json/pass-captain/v1/sms/{twilio,http}/{status,inbound}` | SMS-Gateway (falls genutzt) |
| `/wp-json/pass-captain/v1/app/*`, `/admission/*` | Scanner-App und Offline-Scanner (eigene Tokens) |

Diese Pfade außerdem nicht cachen, keine Länder-Sperren darauf anwenden und keine Umschreibungen des
Antwortinhalts (Cloudflare Email Obfuscation, Rocket Loader, HTML-Minify) für sie aktivieren. Die Ratenbegrenzung des Plugins selbst sieht die echte Client-IP nur mit korrekt eingerichteten
vertrauenswürdigen Proxys (siehe [SECURITY.md](SECURITY.md#ratenlimits-hinter-proxys-und-mit-mehreren-workern)).

### Prüfen

- Ticket- oder Portallink in einem privaten Fenster zweimal laden: Antwort-Header `Cache-Control` enthält
  `no-store`, Cache-Header des Produkts (`x-wp-rocket`, `x-litespeed-cache`, `cf-cache-status`, `x-varnish`
  bzw. `Age`) zeigen keinen Treffer.
- Preis einer Ticketart ändern, danach die Event-Seite ohne Anmeldung laden: der neue Preis erscheint sofort.
- In **Werkzeuge → Website-Zustand** muss WP-Cron laufen, sonst kommen Purges zu Verkaufsstart/-ende zu spät.

## Betriebsüberwachung (Website-Zustand)

Das Plugin trägt eigene Prüfungen in **Werkzeuge → Website-Zustand** ein (Site Health, A164), Abzeichen „Pass Captain“. Ist eine Prüfung **kritisch**, zeigt jede Pass-Captain-Admin-Seite Administratorinnen zusätzlich einen Hinweis mit Link zur Betriebsübersicht (Ergebnis fünf Minuten zwischengespeichert, Transient `wpcap_health_critical`).

| Prüfung | empfohlen | kritisch |
|---|---|---|
| WP-Cron (`wpcap_cron`) | ein Plugin-Cron-Ereignis (z. B. `wpcap_process_jobs`, `wpcap_process_messages`, `wpcap_privacy_retention`) ist ≥ `cron_late_minutes` (15) überfällig; ein wiederkehrender Hook ist gar nicht eingeplant (Plugin einmal deaktivieren/aktivieren) | ein Ereignis ist ≥ `cron_critical_minutes` (60) überfällig; oder `DISABLE_WP_CRON` ist gesetzt und die Warteschlangen liefen seit ≥ 60 Minuten nicht (Heuristik: kein externer Cronjob) |
| Warteschlangen (`wpcap_queues`) | Jobs, E-Mails oder Webhook-Zustellungen: ≥ `dead_letters` (1) endgültig fehlgeschlagen, ≥ `backlog_items` (200) fällig oder ältester fälliger Eintrag ≥ `backlog_age_minutes` (30) | ≥ `dead_letters_critical` (25) fehlgeschlagen oder ältester fälliger Eintrag ≥ `backlog_critical_minutes` (120) |
| E-Mail (`wpcap_mail`) | ≥ `mail_failures` (1) fehlgeschlagene Plugin-Mails in `mail_window_hours` (24) | ≥ `mail_failures_critical` (10) |
| SMS (`wpcap_sms`, nur mit aktivem SMS-Modul und Anbieter) | ≥ `sms_failures` (1) SMS mit Status `failed`/`undelivered` in 24 h | ≥ `sms_failures_critical` (10) |
| Zahlungs-Webhooks (`wpcap_payment_webhooks`) | ein aktiver Zahlungsanbieter mit Webhooks (ohne `manual`) hatte in `payment_webhook_hours` (48) Bestellungen, aber keine Webhook-Benachrichtigung (Tabelle `wpcap_payment_events`) | – |
| Fehlerhafte Zahlungs-Webhooks (`wpcap_webhook_errors`, A323) | ≥ `webhook_failures` (1) abgelehnte (Signatur, Nachricht, Prüfung beim Anbieter) oder gescheiterte Webhook-Benachrichtigungen in `mail_window_hours` (24) – sofort, nicht erst nach 48 h | ≥ `webhook_exceptions_critical` (1) Absturz bei der Verarbeitung oder ≥ `webhook_failures_critical` (10) Fehler |
| Zahlungsstart (`wpcap_payment_start`, A324) | ≥ `payment_start_failures` (1) fehlgeschlagener Zahlungsstart in `payment_start_window_minutes` (60) | ≥ `payment_start_failures_critical` (5) – „Häufung“, erscheint als Admin-Hinweis |
| Öffentliche REST-Schnittstelle (`wpcap_rest_anonymous`, A375) | Eigener Abruf von `pass-captain/v1/events` ohne Cookie scheitert (Loopback-Fehler, andere Antwort) | HTTP 401/403 – REST für Gäste gesperrt (Checkout, Portal, Scanner betroffen); Ergebnis 6 h zwischengespeichert (`wpcap_health_rest_probe_ttl`) |
| Proxy (`wpcap_proxy`, A375) | Weiterleitungs-Header oder private Absenderadresse ohne vertrauenswürdige Proxys (aktuelle Anfrage oder Checkout der letzten 7 Tage) | – |

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
- *Fehlerhafte Webhooks und Zahlungsstarts* (A323, A324): Optionen `wpcap_ops_webhook_failures` und `wpcap_ops_payment_start_failures` (7 Tage, höchstens 200 Einträge): Zeit, Anbieter, Art (`signature`, `payload`, `verify`, `processing`, `exception`, `start`), Fehlercode, maskierte Meldung, Fehler-ID und Anzahl. Gleichartige Fehler innerhalb einer Minute werden zusammengezählt (`n`), damit eine Flut ungültiger Anfragen weder die Option noch das Support-Protokoll aufbläht; ins Support-Protokoll kommt nur der erste Fehler je Minute. Ein Absturz und das Erreichen der kritischen Zahlungsstart-Schwelle löschen den Zwischenspeicher des Admin-Hinweises, der Hinweis erscheint also sofort.

Der Reiter **Bericht** des Website-Zustands enthält den Abschnitt „Pass Captain – Betrieb“ (DISABLE_WP_CRON, letzter und nächster Lauf der Cron-Hooks, Einträge je Status und Rückstand der Warteschlangen, Mail-/SMS-Fehler, letzter Zahlungs-Webhook je Anbieter, fehlerhafte Webhooks und Zahlungsstarts) und den Abschnitt **„Pass Captain – Support“** (A329) als Support-Export: Plugin-Version, gespeicherte und erwartete Schemaversion, Schema-Fingerabdruck, Fehler des letzten Schema-Updates (`wpcap_schema_error`), Module (abgeschaltete markiert), aktive Zahlungsarten mit Modus (Live/Test/nicht eingerichtet – aus dem Präfix des Schlüssels bzw. `paypal_mode`, nie der Schlüssel selbst), WooCommerce-Version und -Währung, Währungen aktiver Ticketarten, Sprache und Zeitzone, offene und abgelaufene Reservierungen, Debug-Modus, PHP-/Datenbankversion und die letzten fünf Fehler des Support-Protokolls mit Fehler-ID. „Website-Informationen in die Zwischenablage kopieren“ überträgt beides; Zugangsdaten, Ticketcodes und Kundendaten sind nicht enthalten. Weitere Felder: Filter `wpcap_support_info`.

## Support-Protokoll (A327)

Fehler, Warnungen und Hinweise des Plugins landen im **Support-Protokoll** (Tabelle `wpcap_logs`, Schema 3.10.16, Klasse `PassCaptain\Log`). Vor dem Datenbank-Update und wenn das Schreiben scheitert, gehen die Einträge ins PHP-Fehlerprotokoll (`debug.log` mit `WP_DEBUG_LOG`); Einträge der Stufe `error` und `critical` stehen immer zusätzlich dort.

- **Eintrag**: Zeit, Stufe (`debug` < `info` < `notice` < `warning` < `error` < `critical`), Bereich (`checkout`, `payment`, `webhook`, `job`, `mail`, `rest`, …), **Fehler-ID** (12 Hex-Zeichen), Request-ID (gleich für alle Einträge eines Aufrufs), Bestellung/Ticket/Termin und ein strukturierter Kontext. Ausnahmen werden mit Klasse, Code, Datei:Zeile und dem letzten Datenbankfehler gespeichert, nie mit Stacktrace.
- **Keine Geheimnisse, Codes oder vollen Personendaten**: Meldung und Kontext laufen durch `Redact` (Secrets, Karten, IBAN, E-Mail-Adressen); Kontextschlüssel, die Ticketcodes, Zugangslinks, Tokens, Namen, Adressen oder Telefonnummern tragen können, werden verworfen.
- **Fehler-ID für Käufer:innen**: Unerwartete Checkout-Fehler (A322), fehlgeschlagene Zahlungsstarts (A324) und unerwartete Fehler der REST-Schnittstelle zeigen einen neutralen Text mit Fehler-ID (`data.reference`). Mit dieser ID findet der Support den Eintrag unter **Betrieb → Support-Protokoll** (Suche nach Fehler-ID, Bestellung, Bereich, Mindeststufe). Belegte Ergebnisse (ausverkauft, Verkauf geschlossen, …) bekommen keine Fehler-ID und erscheinen nur im Debug-Modus; eine überlastete Datenbank ist eine Warnung mit dem Datenbankfehler.
- **Debug-Modus**: Ohne ihn werden Einträge ab `info` gespeichert. **Einstellungen → Support und Fehlersuche → Fehlersuche-Protokoll** speichert zusätzlich `debug`-Einträge und schaltet sich 24 Stunden nach dem Einschalten selbst ab (zum Verlängern aus- und wieder einschalten). Die Konstante `WPCAP_DEBUG` in `wp-config.php` hat Vorrang (`true`: immer an, `false`: immer aus). Feiner: Filter `wpcap_log_min_level`.
- **Was protokolliert wird**: Checkout-Abbrüche (A322), abgelehnte und abgestürzte Zahlungs-Webhooks (A323), Zahlungsstart-Fehler mit dem Text des Anbieters (A324), gescheiterte Jobläufe und Dead Letters (A325), endgültig gescheiterte E-Mails der Warteschlange, **jede Plugin-E-Mail** mit Typ, Empfängerzahl und Ergebnis (gesendet/nicht gesendet, A328), Ausnahmen der REST-Callbacks.
- **Rotation**: Der stündliche Aufräumlauf (`wpcap_cleanup_reservations`) löscht Einträge älter als 30 Tage (Filter `wpcap_log_retention_days`), `debug`- und `info`-Einträge (z. B. das Mail-Protokoll) schon nach 7 Tagen, und hält höchstens 20 000 Einträge. Die Datenschutz-Löschung einer Bestellung entfernt auch ihre Einträge.
- **Weiterleiten**: Die Action `wpcap_log` erhält jeden gespeicherten (bereits geschwärzten) Eintrag, z. B. für ein externes Log-System.

### Verlauf einer Bestellung (A328)

Die Bestellansicht hat den Abschnitt **„Verlauf“**: Audit-Einträge der Bestellung und ihrer Tickets, Zahlungsereignisse (`wpcap_payment_events`), E-Mails der Warteschlange (mit Versandzeit `sent_at`), Hintergrundjobs (mit letztem Fehler) und die Einträge des Support-Protokolls (mit Fehler-ID, darunter jede versendete oder gescheiterte Plugin-E-Mail), neueste zuerst. Die Bestell-ID ist die Korrelations-ID: Schema 3.10.16 speichert sie in `wpcap_message_queue.order_id` und `wpcap_jobs.order_id`; ältere Jobs werden über ihren Inhalt (`"order_id":N`) gefunden. Empfänger, Inhalte und Ticketcodes erscheinen nicht.

### Betriebsübersicht: Rückstände und Dead Letters (A163)

**Pass Captain → Systemstatus → Reiter Betrieb** (Bereich „Einstellungen & Integrationen“, nur `manage_options`; früher eigene Seite `wpcap-operations`) zeigt die Ergebnisse der Prüfungen und je Warteschlange – Hintergrundjobs (`wpcap_jobs`), E-Mail-Warteschlange (`wpcap_message_queue`), Webhook-Zustellungen (`wpcap_webhook_deliveries`, falls das Modul installiert ist), SMS-Warteschlange (`wpcap_sms_messages`, falls das SMS-Modul installiert ist, A417) –:

- Einträge je Status, Anzahl fälliger Einträge und Wartezeit des ältesten,
- die endgültig fehlgeschlagenen Einträge (Jobs `dead_letter`, E-Mails und Webhooks `failed`, SMS `failed`, `held` – wegen des Monatsbudgets zurückgehalten, mit Verfallsdatum – und `unknown` – Versandergebnis unklar, A416; neueste 50) mit Typ, Versuchen, **Bezug** (A326: Bestellung, Ticket, Termin, Event, Erstattung oder Lauf als Wert mit Link – nur diese Ganzzahl-IDs werden aus dem Inhalt gelesen, bei Jobs und E-Mails zusätzlich die gespeicherte Bestell-ID), letztem Fehler (A325: der Grund des Handlers – sein `WP_Error`, die Ausnahme mit Fehler-ID oder der `wp_mail`-Fehler des Laufs; bei E-Mails der Fehler des Mailers bzw. die fehlende Vorlage) (Secrets, Karten- und IBAN-Daten entfernt, E-Mail-Adressen maskiert, siehe SECURITY.md „Fehlerausgaben ohne Secrets“) und nur den **Feldnamen** des Inhalts (bei Webhooks die von `data.object`); E-Mail-Empfänger erscheinen maskiert. Inhalte, Betreffzeilen und Secrets werden nie angezeigt.

Aktionen je Zeile oder als Mehrfachaktion (ausgewählte oder alle fehlgeschlagenen Einträge, höchstens 500):

- **Erneut versuchen**: Status wieder wartend, Versuche 0, fällig sofort, letzter Fehler gelöscht; danach wird die Verarbeitung angestoßen (sofortiger Folgelauf über Action Scheduler bzw. WP-Cron, ohne dass die Admin-Seite selbst wartet). Webhooks laufen über die normale erneute Zustellung (personenbezogene Daten werden aus aktuellen Daten neu aufgebaut). E-Mails, deren Inhalt der Datenschutz-Löschlauf bereits entfernt hat, werden nicht erneut versendet. SMS werden beim Versand erneut gegen Einwilligung, Sperrliste und Budget geprüft; bei `unknown` vorher im Anbieterkonto nachsehen, ob die SMS schon angekommen ist.
- **Verwerfen**: Status `cancelled` (endgültig; wird mit den normalen Aufbewahrungsfristen entfernt).

Beide Aktionen sind nonce-geschützt und landen im Audit-Log (`operations_retry`, `operations_discard`, Objekttyp `queue_jobs`/`queue_messages`/`queue_webhooks`/`queue_sms`, Details: Warteschlange, Anzahl, IDs).

### Erstattungen mit unklarem Ausgang (A350)

Die Seite **Betrieb** listet Erstattungen, deren Anbieter-Aufruf ohne eindeutige Antwort endete
(Zeitüberschreitung, Verbindungs- oder Serverfehler, 409) oder abbrach. Sie werden automatisch beim
Zahlungsanbieter abgeglichen (Job `refund_reconcile`, Webhooks) und nie ein zweites Mal ausgezahlt.
Je Zeile: **Beim Anbieter prüfen** (sofortiger Abgleich) und **Manuell bestätigen** (ausgezahlt /
nicht ausgezahlt, Begründung Pflicht, Recht `wpcap_approve_refunds`). Dieselben Aktionen stehen in der
Erstattungstabelle der Bestellung. Alles wird im Audit-Log protokolliert. Ablauf und Zustände:
[STATE-MACHINES.md](STATE-MACHINES.md#erstattung-mit-unklarem-ausgang-a272-a350).

### Datenkonsistenz: Diagnose und Reparatur (A204)

`PassCaptain\Operations\Doctor` sucht widersprüchliche Daten. Die Prüfung ändert nichts. Reparaturen gibt es nur, wo der richtige Zustand eindeutig ist und der Plugin-Code ihn selbst hergestellt hätte. Jede Reparatur hat eine Vorschau (Dry Run, nichts wird geschrieben), und jede ausgeführte Reparatur wird auditiert.

| Prüfung (`id`) | Findet | Reparatur |
|---|---|---|
| `tickets_without_order` (kritisch) | gültige/eingecheckte Tickets, deren Bestellung fehlt | – (manuell prüfen) |
| `tickets_of_closed_orders` (kritisch) | gültige Tickets stornierter, erstatteter oder abgelaufener Bestellungen sowie erstattete (`refunded=1`), aber noch gültige Tickets | `revoke_closed_order_tickets`: sperrt sie (`Orders::revoke_ids()`, `wpcap_ticket_revoked`, Audit `doctor_tickets_revoked` je Bestellung) |
| `paid_orders_without_tickets` (kritisch) | bezahlte, abgeschlossene Bestellungen mit offenen Positionen ohne ein einziges Ticket, älter als 10 Minuten | – (Tickets in der Bestellung erneut senden) |
| `refunds_exceed_payment` (kritisch) | Summe der abgeschlossenen/laufenden Erstattungen größer als der bezahlte Betrag (`total_minor`, ohne Gutscheinanteil) | – |
| `duplicate_provider_refunds` (kritisch) | dieselbe Anbieter-Referenz (`provider_reference`) beim selben Zahlungsanbieter mehrfach verbucht | – |
| `stale_queue_claims` | Jobs `running`, E-Mails und SMS `sending`, Webhook-Zustellungen `delivering` seit über 15 Minuten | `requeue_stale_claims`: zurück auf wartend, Claim gelöscht (wie die Worker selbst; nur noch hängende Einträge); SMS werden `unknown` statt wartend (A416: kein Doppelversand) |
| `expired_holds` | aktive Ticket- und Sitzplatz-Reservierungen nach Ablauf, reservierte Servicebuchungen ohne Bestellung nach Ablauf, unbezahlte Bestellungen ohne gültige Reservierung | `release_expired_holds`: der Cron-Ablauf `Orders::expire_holds()` (Zähler, Bestellung `expired`, Kampagnen, Hooks) plus Sitzplatz- und Servicebuchungs-Reservierungen |
| `capacity_counter_drift` | Kontingentzähler ≠ Summe aus Bestellungen/Reservierungen (`CapacityCounters::drift()`) | `rebuild_counters`: `CapacityCounters::reconcile( true )` |
| `capacity_ticket_drift` | Bestellzeilen zählen weniger Plätze (`quantity − refunded_quantity`) als gültige, nicht erstattete Tickets derselben Bestellung und Ticketart (`CapacityCounters::ticket_drift()`): ein Platz wurde doppelt freigegeben, die Ticketart kann überverkauft werden | – (manuell prüfen) |
| `document_number_gaps` | Lücken in Rechnungs- und Korrekturnummern des laufenden und des Vorjahres (`Invoices::document_gaps()`) | – (Nummern werden nie nachträglich vergeben; Lücke dokumentieren) |
| `service_bookings` | aktive Buchungen beendeter oder fehlender Bestellungen; noch reservierte (`held`, ältere Zeilen `pending_payment`) Buchungen bezahlter Bestellungen; nach Ablauf der Reservierung bezahlt; mit Grund `capacity_lost` stornierte Buchungen ohne Erstattung | `sync_service_bookings`: nur die eindeutigen Fälle – Buchungen beendeter Bestellungen freigeben (`ServiceBookings::release_order()`), Buchungen bezahlter Bestellungen mit noch gültiger Reservierung bestätigen (`confirm_paid_order()`). Die anderen beiden Fälle bleiben für eine Person. |
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

- **Rufnummern** (A604): `Sms\Service::normalize_phone()` bringt Eingaben in E.164 (`+49151…`): Leerzeichen, Bindestriche, Punkte, Schrägstriche und Klammern fallen weg, `00` wird `+`, eine führende `0` bekommt die „Standard-Ländervorwahl“. Die in Klammern geschriebene Verkehrsausscheidungsziffer nach der Ländervorwahl entfällt mit: `+49 (0)151 2345678` und `+49 (0151) 2345678` ergeben `+491512345678` (vorher fälschlich `+4901512345678`). Eine `0` ohne Klammern bleibt (Italien: `+39 06 …`).
- **Segmente**: `Sms\Segments::count()` zählt nach GSM 03.38. Texte nur aus dem GSM-7-Alphabet (inkl. Erweiterungstabelle `€ [ ] { } \ ^ ~ |`, deren Zeichen zwei Stellen belegen) passen mit 160 Zeichen in eine SMS, längere Texte werden in Teile zu 153 Zeichen zerlegt. Ein einziges anderes Zeichen (z. B. `ł`, `ő`, Emoji) schaltet die ganze Nachricht auf UCS-2: 70 Zeichen, sonst 67 je Teil (Emojis zählen doppelt). Escape-Sequenzen und Surrogatpaare werden nicht auf zwei Teile verteilt. Segmente und Kodierung stehen beim Einreihen an der Nachricht; die Admin-Seite zeigt sie für die Absage- und Verschiebungsvorlagen an.
- **Kosten je Nachricht**: Beim Versand werden Zielland (längste passende Vorwahl aus der Preistabelle bzw. den erlaubten Vorwahlen), Segmente und Kosten gespeichert (`cost`, `cost_currency`, `cost_source`, `sent_at`). Meldet der Anbieter einen Preis – in der Versandantwort (`price`/`currency`, bei Twilio `price`/`price_unit` sowie `num_segments`) oder im Status-Callback (Twilio `Price`/`PriceUnit`, HTTP `price`/`currency`) –, gilt dieser (`cost_source` `provider`), sonst Segmente × „Preis je Segment und Land“ bzw. Standardpreis (`configured`). Ohne Preis bleibt die Spalte leer (`none`) und zählt nicht zum Budget. Preise in einer anderen Währung als `sms.currency` werden nur über `wpcap_sms_convert_price` umgerechnet; sonst bleibt der hinterlegte Preis maßgeblich (Twilio meldet meist USD).
- **Monatsbudget** (Kalendermonat UTC, Summe der Kosten in der Abrechnungswährung): Ab „Warnung ab (Prozent)“ (Standard 80 %) einmal pro Monat Audit-Eintrag `sms_budget_warning`, Mail an die Administrationsadresse und Action `wpcap_sms_budget_warning`. Mit **harter Grenze** (Standard an) wird eine SMS, deren geschätzte Kosten das Budget überschreiten würden, nicht gesendet, sondern mit Status `held` zurückgehalten (Audit `sms_budget_stop`, Mail einmal pro Monat). Nach einer Budgeterhöhung stellt „Zurückgehaltene SMS freigeben“ sie wieder in die Warteschlange; nicht freigegebene verfallen nach 7 Tagen (`skipped`, `budget_exceeded`, Audit-Eintrag `sms_held_expired` mit den Nachrichten-IDs, A417). Bis dahin stehen sie mit Verfallsdatum unter **Betrieb → Warteschlangen → SMS-Warteschlange** (einzeln erneut versuchen oder verwerfen); der Budgethinweis nennt das Verfallsdatum der ältesten. Widerruf und Ende der Einwilligung überspringen auch zurückgehaltene SMS.
- **Kostenbericht**: Die SMS-Seite zeigt den Budgetstand, die letzten 12 Monate (SMS, Segmente, Kosten, davon vom Anbieter gemeldet, ohne Preis) und den laufenden Monat nach Ländervorwahl und Anlass.
- **Zustellstatus**: Twilio meldet an `/wp-json/pass-captain/v1/sms/twilio/status` (geprüft mit `X-Twilio-Signature`). Der generische HTTP-Anbieter meldet an `/sms/http/status` (JSON oder Formular; Felder `reference` = `wpcap-sms-{id}` oder `id`/`message_id`, `status`, optional `error_code`, `price`, `currency`). Er muss den Rohinhalt mit dem „Signaturschlüssel für Rückmeldungen“ (`sms_http_webhook_secret`) per HMAC-SHA256 signieren und die Signatur (hex oder Base64, optional `sha256=`) im Header `X-Signature` (einstellbar) senden; ohne Schlüssel lehnt der Endpunkt jede Meldung ab (403). Endgültige Zustände werden nicht auf `sent` zurückgestuft.
- **Kein Doppelversand nach Zeitüberschreitung (A416)**: Endet ein Versandaufruf ohne klare Antwort (Zeitüberschreitung, abgebrochene Verbindung, HTTP 5xx oder 2xx ohne Nachrichten-ID) oder bleibt eine Nachricht nach einem Absturz länger als 15 Minuten in `sending`, wird sie bei Twilio nicht erneut gesendet (die Messages-API kennt keinen Idempotenzschlüssel), sondern erhält den Status `unknown`. Nach zwei Minuten fragt der nächste Lauf Twilio (Nachrichtenliste an die Nummer vom eingestellten Absender, gleicher Text, seit dem Versuch): gefunden → `sent` (bzw. der gemeldete Endstatus) mit der SID; sicher nicht vorhanden → wieder in die Warteschlange; Abfrage fehlgeschlagen → neuer Versuch nach 15 Minuten. Nur Verbindungsfehler vor dem Absenden (DNS, Verbindungsaufbau, vom Ausgangsfilter abgelehnt) werden wie bisher wiederholt. Eine Zustellmeldung klärt eine `unknown`-Nachricht ebenfalls. Der generische HTTP-Anbieter sendet jede Anfrage mit dem Header `Idempotency-Key` (Nachrichtenreferenz) und wiederholt deshalb weiter – der Dienst muss diesen Header auswerten. Eigene Adapter können `idempotent(): bool` und `find_sent( $to, $body, $since )` anbieten (siehe `Sms\TwilioProvider`); ohne beides bleiben unklare Nachrichten `unknown`, bis sie unter **Betrieb → Warteschlangen** erneut versucht oder verworfen werden.
- **Schutz gegen wiederholte Rückmeldungen (A366)**: Sendet der HTTP-Anbieter im Header `X-Timestamp` (einstellbar, „Zeitstempel-Header“) einen Unix-Zeitstempel in Sekunden, muss die Signatur über `{Zeitstempel}.{Rohinhalt}` gebildet sein, und die Meldung wird nur bei höchstens 5 Minuten Abweichung von der Serverzeit angenommen (sonst 403 `wpcap_sms_stale`). Mit „Zeitstempel verlangen“ (`sms.http_require_timestamp`) werden Meldungen ohne Zeitstempel abgelehnt – empfohlen, sobald der Anbieter ihn sendet. Jede angenommene Meldung wird genau einmal verarbeitet: eine identische Wiederholung (gleicher Zeitstempel und Inhalt; ohne Zeitstempel 7 Tage lang) beantwortet der Endpunkt mit `200 {"received": true, "duplicate": true}` ohne Wirkung, ebenso eine eingehende SMS mit bereits verarbeiteter `message_id`/`id` des Anbieters. Eingehende Twilio-SMS (Signatur ohne Zeitstempel) werden ebenfalls nur einmal verarbeitet; so kann ein mitgeschnittenes START eine spätere Abmeldung (STOP) nicht aufheben.
- **Einwilligung und Abmeldung**: Jede Einwilligung speichert Nummer, Zweck, Textversion, Quelle (`source`, im Checkout `checkout`) und Zeitpunkt; ein Widerruf Zeitpunkt und Quelle (`withdrawn_source`: `admin`, `inbound_stop`, `privacy_erasure` …). Eine eingehende SMS mit STOP (auch STOPP, ABMELDEN, ENDE, UNSUBSCRIBE …) über Twilio (`/sms/twilio/inbound`) oder den HTTP-Anbieter (`/sms/http/inbound`, Felder `from`, `text`, gleiche Signatur) widerruft alle Einwilligungen der Nummer, löscht die Nummer und setzt sie als SHA-256-Hash auf die Sperrliste `wpcap_sms_optouts`. Neue Einwilligungen für diese Nummer lehnt der Checkout ab (`sms_opted_out`), bis sie START (UNSTOP, ANMELDEN) sendet; alte Einwilligungen leben dadurch nicht wieder auf.

## Einrichtung: Checkliste „Erste Schritte“ (A088, A289–A292)

Die Eventliste zeigt Administratoren eine Checkliste, deren Schritte allein aus den gespeicherten Daten
abgeleitet werden (`Admin\GettingStarted`; Filter `wpcap_getting_started_items`). Offene Schritte
verlinken direkt auf den passenden Einstellungs-Reiter (`?tab=payments|legal|invoices`, bei aktivierter,
aber unvollständiger Überweisung auf deren Unterbereich).

- **Zahlungsart einrichten**: eine aktivierte Online-Zahlungsart mit vollständigen Zugangsdaten, eine
  konfigurierte Überweisung oder WooCommerce **mit mindestens einem veröffentlichten Produkt, das mit einer
  aktiven Ticketkategorie verknüpft ist** (ein aktives WooCommerce allein verkauft keine Tickets).
- **Rechtstexte hinterlegen**: AGB/Teilnahmebedingungen und Verbraucherinformationen **und** „Zustimmung im
  Checkout verlangen“. Ohne diese Option zeigt der Checkout die AGB nicht an. Ist die Zustimmung aktiv, der
  AGB-Text der Standardsprache aber leer, warnen Checkliste, Reiter „Rechtstexte“ und das Speichern.
  Jede Fassung, die in Kraft war, wird unverändert archiviert (Tabelle `wpcap_legal_versions`, A568;
  Kennung = Hash über beide Texte, wie `terms_version` der Bestellung): beim Speichern, beim Schema-Upgrade
  und mit jeder Bestellung, deren Fassung noch fehlt. Die Bestellansicht zeigt den Text der Fassung der
  Bestellung, der Reiter „Rechtstexte je Sprache“ die zuletzt archivierten Fassungen. Die Standard-Ticketmail
  enthält diese Texte über `{contract_texts}`; eigene Vorlagen müssen den Platzhalter selbst aufnehmen.
- **Verkaufsseite anlegen**: eine veröffentlichte Seite oder ein Beitrag mit `[wpcap_events]`
  (`[wp_pass_captain_events]`), dem Block „Pass Captain Events“ oder dem Kalender. „Verkaufsseite als Entwurf
  anlegen“ erzeugt die Seite „Tickets“ mit dem Shortcode und öffnet sie im Editor; erst nach dem
  Veröffentlichen ist der Schritt erledigt. Erledigt verlinkt er „Auf Website ansehen“; „alles bereit für den
  Verkauf“ erscheint nur mit Verkaufsseite. Die Seitenerkennung wird zehn Minuten zwischengespeichert.
- **Einlass vorbereiten**: ein gekoppeltes Scanner-Gerät oder eine veröffentlichte Seite mit
  `[wpcap_scanner]`. Unter **Scanner-Geräte → Ohne App scannen** stehen Web-Scanner (Shortcode) und, mit dem
  Modul Offline-Scanner, der Link zur installierbaren Web-App.

### Veröffentlichungs-Checkliste und Verkaufsliste (A293)

Ein veröffentlichtes Event erscheint in `[wpcap_events]` nur mit einem geplanten, kommenden Termin und
einer aktiven Ticketart im Verkaufszeitraum. Die Eventseite im Admin nennt über den Eventdaten, was fehlt
(`EventsPage::publish_issues()`: kein kommender Termin, keine aktive Ticketart, Vorverkauf noch nicht
begonnen bzw. Verkaufszeitraum beendet, keine verfügbare Zahlungsart je Währung); beim Veröffentlichen
erscheinen dieselben Punkte als Warnung. Auf der Website bekommt ein Termin, dessen Ticketarten alle erst
später in den Verkauf gehen, eine Karte „Vorverkauf ab …“ (Filter `wpcap_presale_card_html`); bleibt nach
dem Filtern nichts übrig, steht dort „Aktuell sind keine Veranstaltungen verfügbar.“ statt einer leeren Seite.

**Keine verfügbare Zahlungsart (A294):** Ist für die Währung eines Termins keine Zahlungsart aktiviert und
vollständig eingerichtet, zeigt die Eventkarte die Ticketarten mit Preisen, aber statt des Bestellformulars
„Online-Verkauf derzeit nicht möglich“ (Variable `$providers` im Template leer). Administratoren sehen auf dem
Dashboard und den Plugin-Seiten den Hinweis „Keine verfügbare Zahlungsart für …“ mit Link zu den
Zahlungsarten, solange ein veröffentlichtes Event einen kommenden Termin in dieser Währung hat. Shops, die
über verknüpfte WooCommerce-Produkte verkaufen, erhalten den Hinweis nicht.

## Stripe und PayPal einrichten: Webhook und Verbindungstest (A295)

Unter **Einstellungen → Zahlungsarten → Stripe bzw. PayPal** stehen die Webhook-URL der Website
(`/wp-json/pass-captain/v1/payments/{stripe|paypal}/webhook`), die beim Anbieter auszuwählenden Ereignisse
(`StripeGateway::WEBHOOK_EVENTS`, `PayPalGateway::WEBHOOK_EVENTS`) und – solange etwas fehlt – die fehlenden
Felder mit Namen (Stripe: geheimer API-Schlüssel und Webhook-Signaturgeheimnis; PayPal: Client-ID,
Client-Geheimnis und Webhook-ID). Erst wenn alle gesetzt sind, wird die Zahlungsart im Checkout angeboten.

**Verbindung testen** (nach dem Speichern) prüft mit den gespeicherten Zugangsdaten, ohne etwas zu ändern:

- Stripe: `GET /v1/account` (Schlüssel gültig), `GET /v1/webhook_endpoints` – gibt es einen aktiven Endpunkt
  mit genau dieser URL und allen Ereignissen (oder `*`)? Fehlt das Recht zum Lesen der Endpunkte
  (eingeschränkter Schlüssel), meldet der Test das als Hinweis.
- PayPal: Zugangstoken im eingestellten Modus (Sandbox/Live), `GET /v1/notifications/webhooks/{Webhook-ID}` –
  stimmt die URL, sind alle Ereignisse abonniert? Eine unbekannte Webhook-ID deutet meist auf eine
  Verwechslung von Sandbox und Live oder eine andere App hin.

Jeder Befund erscheint als eigene Meldung; der Test wird im Audit-Log (`payment_connection_tested`, nur
Anbieter und Ergebnis) protokolliert.

## Überweisung (manuelle Zahlung) einrichten (A265)

Die Zahlungsart „Überweisung“ (`manual`) ist bei neuen Installationen **nicht** aktiviert. Sie wird im
Checkout (auch in Wartelisten-Angeboten) erst angeboten, wenn sie unter **Einstellungen → Zahlungen →
Überweisung** aktiviert **und** vollständig konfiguriert ist (`ManualGateway::is_available()`):

- **Bankverbindung**: IBAN und Empfänger (Feld „Empfänger“, leer = Name des Rechnungsstellers), oder
- ein **Zahlungshinweis** (Freitext, z. B. für eine andere Zahlungsabsprache).

Käufer sehen eine Überweisungsaufforderung (Empfänger, IBAN, BIC, Verwendungszweck, Frist) nur mit
Empfänger **und** IBAN. Ist nur ein Zahlungshinweis hinterlegt, zeigen Bestätigungsseite, Bestellportal
und Mail `{payment_instructions}` nur Betrag, Frist und diesen Hinweis. Ein Checkout mit der unvollständigen
Zahlungsart wird vor dem Anlegen der Bestellung abgelehnt (`wpcap_provider_unavailable`, keine Plätze
blockiert).

Installationen von vor Schema 3.10.9, die die Zahlungsarten nie gespeichert hatten, behalten
„Überweisung“ als aktivierte Zahlungsart (`Installer::pin_legacy_manual_provider()`). Fehlt dort die
Bankverbindung, zeigt der Zahlungen-Tab „(nicht vollständig konfiguriert)“ und Administratoren sehen auf
dem Dashboard und den Plugin-Seiten einen Hinweis mit Link zur Einrichtung.

## Vorverkaufs-/Servicegebühr (A425)

Standardmäßig aus. Unter **Einstellungen → Allgemein → Servicegebühr** gibt es eine Gebühr **je Ticket** und
eine **je Bestellung** (Bruttobeträge); im Ticketart-Formular lässt sich die Gebühr je Ticket abweichend
festlegen (leer = Einstellung, 0 = keine; Spalte `wpcap_ticket_types.service_fee_minor`, Schema 3.18.3).

- **Wo sie gilt:** nur im eigenen Online-Checkout und nur für kostenpflichtige Positionen. Keine Gebühr bei
  kostenlosen Bestellungen, Positionen, die nach Rabatt nichts kosten, Kasse, WooCommerce, Partner-API,
  Admin-Reservierungen, Freikarten, Wartelisten-Angeboten und Servicebuchungen. Filter `wpcap_service_fee`
  ändert sie je Bestellung.
- **Bestellung:** eigene Zeilen in `wpcap_order_items` mit `fee_kind` `ticket` bzw. `order` und Menge 0
  (kein Einlass, kein Kontingent). `subtotal_minor` und `total_minor` der Bestellung enthalten die Gebühr,
  `price_snapshot.service_fee` friert die Beträge ein; Rabatte mindern sie nicht.
- **Steuer (Einstellung „Steuer der Servicegebühr“, Entscheidung vom 23.11.2026):**
  - **„wie das Ticket (Nebenleistung)“** (Standard, bisheriges Verhalten): gleicher Satz und gleiche
    Steuerprofil-Spalten wie die zugehörige Ticketposition; die Gebühr je Bestellung folgt der ersten
    kostenpflichtigen Position.
  - **„eigenes Steuerprofil“**: Die Gebührenzeilen nehmen das aktive Steuerprofil der eingestellten
    **Steuerklasse der Servicegebühr** (Standard `service`; Auswahl aus den Steuerklassen unter Verkauf →
    Steuerprofile) für denselben Leistungsort und dasselbe Datum wie ihre Ticketposition (Eventland bzw.
    Veranstaltungsort, Leistungs-/Verkaufsdatum; `Sales\TaxProfiles` und `Tax\Profiles` wie bei Tickets). Ohne
    passendes aktives Profil gilt der **Steuersatz der Servicegebühr ohne passendes Steuerprofil** (Quelle
    `setting`, Admin: „Steuersatz der Servicegebühr (Einstellung)“), ist er leer, der Satz der Ticketposition.
    Satz, Profil-ID, Version und Profil-Snapshot stehen auf der Gebührenzeile (`tax_rate_bp`, `tax_source`,
    `tax_profile_id`, `line_snapshot.tax`); `price_snapshot.service_fee.tax` nennt den Modus.
  - Die Einstellung gilt für neue Bestellungen; bestehende behalten ihre Zeilen. Rechnung (Positionen und
    USt-Aufstellung je Satz), Rechnungskorrektur (Aufteilung je Satz: die Gebühr je Ticket eines erstatteten
    Tickets wird mit dem Satz der Gebührenzeile gebucht), Fiskalbeleg (Gebührenpositionen mit ihrem Satz),
    Bericht (Steuersätze, „davon Servicegebühren“) und Buchhaltungsexport folgen der Gebührenzeile. Die
    Belegzeile je Ticketart (`wpcap_fiscal_document_items`, eine Zeile je Ticketart) trägt weiter den Satz der
    Ticketposition und die Summen inkl. Gebühr.
  - Filter `wpcap_service_fee_tax` ändert das eigene Steuerprofil je Gebührenzeile.
- **Preisangabe (Entscheidung der Inhaberin vom 23.11.2026; DE PAngV § 3, AT PrAG, EU-Verbraucherrecht):**
  Die Gebühr **je Ticket** ist in jedem Preis enthalten, den Käufer vor dem Kauf sehen: Preis je Ticketart auf
  Eventkarte/Eventseite („26,50 € … inkl. 1,50 € Servicegebühr“), Preis im Kartenkopf („ab …“), Live-Stand
  (`checkout/live`), Preis in 409-Meldungen „Preis geändert“ (A560/A576, `prices_formatted`/`formatted`),
  JSON-LD-Angebote der Eventseite und REST `/events` (`display_price_minor`). Die Gebühr **je Bestellung**
  steht deutlich neben den Preisen („zzgl. 2,00 € Bearbeitungsgebühr je Bestellung“ im Kartenkopf und über den
  Ticketarten) und ist in jeder Summe vor dem Bestellbutton enthalten (Schätzung „Summe: … inkl. 3,00 €
  Servicegebühr, inkl. 2,00 € Bearbeitungsgebühr je Bestellung“ und Summe neben dem Button). Kostenlose
  Ticketarten bleiben „kostenlos“. Der Preisvergleich des Checkouts (`data-price`, `expected_price_minor`,
  `price_minor` im Live-Stand und in `/events`) bleibt der Ticketpreis ohne Gebühr; REST `/events` liefert
  zusätzlich `service_fee_minor` und `order_fee_minor` (Vertrag `public` 1.2). Listen- und Kalenderansicht
  zeigen keine Preise; WooCommerce-Produktpreise bleiben unverändert, weil dort keine Gebühr anfällt.
  Filter `wpcap_service_fee_shown` gleicht die Anzeige an, wenn `wpcap_service_fee` die Gebühr je Bestellung
  ändert.
- **Anzeige nach dem Kauf:** Bestellübersicht in Mail und Bestellportal, Admin-Bestellansicht und Rechnung als eigene Position
  („Servicegebühr (Ticketart)“ mit Anzahl Tickets, „Servicegebühr je Bestellung“); Bericht: „davon
  Servicegebühren“ je Währung (Teil des Bruttoumsatzes). Fiskalisierung: die Gebühren stehen als eigene
  Positionen auf dem Beleg des Verkäufers ihrer Ticketposition (Belegsumme = Bestellsumme); die Belegzeile je
  Ticketart (`wpcap_fiscal_document_items`) enthält ihre Beträge.
- **Erstattung:** nach den bestehenden Regeln (`Refunds::calculate()`): ein erstattetes Ticket nimmt seine
  Gebühr je Ticket mit, die Gebühr je Bestellung geht mit dem letzten Ticket zurück (Vollerstattung =
  Bestellsumme). Wer die Gebühr einbehalten will, nutzt den Abzug der Erstattung (`deduct_minor`).
- **Rechtlicher Hinweis:** Die Umsetzung folgt den Entscheidungen der Inhaberin vom 23.11.2026. Die rechtliche
  und steuerliche Einordnung (Preisangabe, Nebenleistung oder eigene Leistung, Erstattungspflicht,
  Zurechnung zum Betreiber) ist **keine Rechts- oder Steuerberatung** und sollte vor dem Einsatz von einer
  Steuerberaterin bzw. einem Steuerberater (und für die Preisangabe ggf. rechtlich) bestätigt werden.

## Zahlungsanbieter: Test- und Live-Modus (A274)

- **Stripe**: Der Modus (Einstellungen → Zahlungen → Stripe, Standard „Test“) muss zum Secret Key passen:
  „Live“ verlangt `sk_live_…`/`rk_live_…`, „Test“ verlangt `sk_test_…`/`rk_test_…`. Passt der Schlüssel nicht
  (oder ist es kein Secret Key, z. B. `pk_…`), wird Stripe im Checkout **nicht** angeboten
  (`StripeGateway::is_available()`); beim Speichern und im Stripe-Abschnitt erscheint eine Fehlermeldung.
  So kann ein Testschlüssel bei „Live“ keine Tickets ohne echte Zahlung ausgeben.
- **Stripe-Webhooks** tragen `livemode`. Ein Ereignis des anderen Modus (Testereignis im Live-Betrieb und
  umgekehrt) wird bestätigt (200), bewirkt aber nichts und wird als `stripe_webhook_mode_mismatch` im
  Audit-Log vermerkt.
- **PayPal** läuft standardmäßig gegen die Sandbox (`paypal_mode` = „sandbox“); „Live“ nutzt die Live-API.
- **Mollie** erkennt den Modus am API-Schlüssel (`test_…`/`live_…`).
- **Mollie-Methodenauswahl (A282):** Die öffentliche Eventliste ruft die Mollie-API nie selbst auf. Die
  Auswahl der Zahlungsmethode auf der Eventkarte kommt aus dem Cache (eine Stunde je Betrag/Währung/Land);
  fehlt er, lädt das einmalige Cron-Ereignis `wpcap_mollie_refresh_methods` die Liste im Hintergrund
  (Zeitlimit 5 s), bis dahin wählt der Käufer die Methode auf der Mollie-Seite. Eine fehlgeschlagene Abfrage
  wird 5 Minuten gemerkt, damit ein Mollie-Ausfall nicht jeden Checkout um das Zeitlimit verzögert.
- Im **Testmodus** zeigt der Abschnitt des Anbieters einen Hinweis, und Käufer sehen den Zusatz
  „– Testmodus“ (PayPal: „– Testmodus (Sandbox)“) am Namen der Zahlungsart im Checkout.

Installationen von vor Schema 3.10.15 mit Live-Schlüssel und dem nie geänderten Standard „Test“ werden beim
Upgrade auf „Live“ umgestellt (`Installer::align_legacy_stripe_mode()`, Audit `stripe_mode_aligned`), weil die
Einstellung bis dahin wirkungslos war. Ein Testschlüssel bei „Live“ bleibt unverändert und wird als Fehler
angezeigt.

## Multisite: Site löschen und Plugin deinstallieren

- **Netzwerkweite Aktivierung (A287):** Im Aktivierungs-Request wird nur die aktuelle Site eingerichtet. Alle anderen Sites richten sich beim nächsten eigenen Admin-, Cron- oder WP-CLI-Aufruf selbst ein (wie nach einem Update, siehe Schema-Upgrade) und zusätzlich im Hintergrund: Das einmalige Cron-Ereignis `wpcap_network_install` der aktivierenden Site arbeitet die Sites in Stapeln ab (höchstens 25 Sites bzw. 15 Sekunden je Lauf, Filter `wpcap_network_install_batch`/`wpcap_network_install_budget`), plant sich bis zum Ende neu ein und merkt den Fortschritt in der Netzwerkoption `wpcap_network_install`. Vor jedem Stapel verlegt derselbe Lauf bei bis zu 500 weiteren Sites mit Plugin-Daten nur die Zustimmung „Daten beim Löschen entfernen“ aus `wpcap_settings` in die Option `wpcap_uninstall_policy` (Filter `wpcap_network_install_policy_batch`), damit die `uninstall.php` einer alten Kopie „wp-pass-captain“ sie auf keiner Site mehr findet – auch auf Sites, die ihr Einrichtungsstapel noch nicht erreicht hat. Jede Site läuft hinter ihrer eigenen Upgrade-Sperre, eine schon eingerichtete Site wird übersprungen. Neue Sites werden beim Anlegen eingerichtet (`wp_initialize_site`). Sofort alle Sites einrichten: `wp site list --field=url | xargs -I{} wp pass-captain upgrade --url={}`. Die Deinstallation geht weiterhin alle Sites in einem Aufruf durch (bricht sie ab, einfach erneut deinstallieren; jeder Schritt ist wiederholbar).
- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Jede Site hat eigene Tabellen (`{präfix}wpcap_*`) und eigene Optionen; das Plugin greift nur über `$wpdb->prefix` bzw. `$wpdb->…`-Tabellen zu (statisch geprüft, siehe TESTING.md „Tabellenpräfixe und Isolation“). Zwischenspeicher je Anfrage (Verfügbarkeit, Steuerprofile, Schema-Bereitschaft, Schema-Version) gelten nur für die Site, für die sie gefüllt wurden; nach `switch_to_blog()` werden sie neu aufgebaut (Aktion `switch_blog` und Präfixvergleich).
- Die Deinstallation mit „Daten beim Löschen entfernen“ löscht Optionen und Transienten über die Options-API (`delete_option()`/`delete_transient()` je Name) statt per SQL und leert die Options-Caches (`alloptions`, `notoptions`) sowie die Cache-Gruppen `wpcap` und `wpcap_rate`, sofern der Objekt-Cache Gruppen leeren kann (A467). Vorher hielt ein persistenter Objekt-Cache z. B. `wpcap_db_version` weiter vor; eine spätere Neuinstallation legte dann keine Tabellen an.
- Mit „Daten beim Löschen entfernen“ und aktivem WooCommerce entfernt die Deinstallation außerdem die Verknüpfungen in WooCommerce über die CRUD-API (HPOS und Beitragsspeicher): `_wpcap_core_order_id`, `_wpcap_checkout_attempt`, `_wpcap_refund_processed`, `_wpcap_attendees`, `_wpcap_deferred_note` an Bestellungen, `_wpcap_ticket_type_id` an Bestellpositionen und Produkten (`Integrations\WooCommerce\MetaCleanup`, Zeitbudget 20 s; A284). Was dabei liegen bleibt (WooCommerce inaktiv, sehr viele Bestellungen), ist unschädlich: Eine Verknüpfung gilt nur, wenn die Pass-Captain-Bestellung nachweislich für diese WooCommerce-Bestellung angelegt wurde (Idempotenzschlüssel = Schlüssel-Hash aus WooCommerce-Bestellnummer und Versuch). Nach einer Neuinstallation zeigen alte Bestellungen also nie auf neue, fremde Bestellungen (Storno, Erstattung, Notizen).
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben – Sites ohne Plugin-Daten (keine `wpcap_*`-Tabellen, keine Version, keine Einstellungen) zählen dabei als zustimmend (A514); Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites. Entfernt werden alle Plugin-Capabilities aus `PassCaptain\Capabilities::all()` – die Rollenrechte und die Modulrechte `wpcap_approve_comps` (Gästelisten/Freikarten) , `wpcap_boxoffice_override` (Kasse) und `wpcap_read_holder_email` (E-Mail des Ticketinhabers im Lesedienst für Connectoren) – an allen Rollen und Benutzern (A372). Unabhängig von der Zustimmung entfernt die Deinstallation die Netzwerk-Optionen `wpcap_*` (`sitemeta`, z. B. `wpcap_network_install`, `wpcap_legacy_copy_seen`) und auf jeder Site die Action-Scheduler-Aktionen der Gruppe `pass-captain` (die Gruppe `wp-pass-captain` nur, wenn die alte Kopie nicht mehr installiert ist; A514).
- Zugriffsbereiche des Personals (Spielorte/Veranstalter, A470) und das Ausblenden des Kassen-Hinweises sind Benutzeroptionen **je Site** (`{präfix}wpcap_access_scope`, `{präfix}wpcap_pos_fiscal_notice_dismissed`). Ein Bereich, der auf Site 1 gesetzt wird, schränkt dieselbe Person auf Site 2 nicht ein. Nach dem Update auf Schema 3.10.19 hat jede Site eine Kopie des früheren netzwerkweiten Bereichs; bitte je Site prüfen (Partner-IDs sind Site-lokal).
- Datenschutz-Auskunft und -Löschung (Werkzeuge → Personenbezogene Daten exportieren/löschen) laufen **je Site**: Exporter und Löscher lesen und ändern nur die Tabellen der Site, auf der die Anfrage bearbeitet wird. Hat eine Person auf mehreren Sites gekauft, muss die Anfrage auf jeder Site gestellt werden. Netzwerkweite Benutzer-Metadaten des Plugins (Hinweis-Schalter, Event-Zuständigkeit des Personals) enthalten keine Kundendaten und bleiben unberührt (getestet in `tests/integration/modules/zz_a196.php`).

- **Netzwerkweite Aktivierung (A287):** Im Aktivierungs-Request wird nur die aktuelle Site eingerichtet. Alle anderen Sites richten sich beim nächsten eigenen Admin-, Cron- oder WP-CLI-Aufruf selbst ein (wie nach einem Update, siehe Schema-Upgrade) und zusätzlich im Hintergrund: Das einmalige Cron-Ereignis `wpcap_network_install` der aktivierenden Site arbeitet die Sites in Stapeln ab (höchstens 25 Sites bzw. 15 Sekunden je Lauf, Filter `wpcap_network_install_batch`/`wpcap_network_install_budget`), plant sich bis zum Ende neu ein und merkt den Fortschritt in der Netzwerkoption `wpcap_network_install`. Vor jedem Stapel verlegt derselbe Lauf bei bis zu 500 weiteren Sites mit Plugin-Daten nur die Zustimmung „Daten beim Löschen entfernen“ aus `wpcap_settings` in die Option `wpcap_uninstall_policy` (Filter `wpcap_network_install_policy_batch`), damit die `uninstall.php` einer alten Kopie „wp-pass-captain“ sie auf keiner Site mehr findet – auch auf Sites, die ihr Einrichtungsstapel noch nicht erreicht hat. Jede Site läuft hinter ihrer eigenen Upgrade-Sperre, eine schon eingerichtete Site wird übersprungen. Neue Sites werden beim Anlegen eingerichtet (`wp_initialize_site`). Sofort alle Sites einrichten: `wp site list --field=url | xargs -I{} wp pass-captain upgrade --url={}`. Die Deinstallation geht weiterhin alle Sites in einem Aufruf durch (bricht sie ab, einfach erneut deinstallieren; jeder Schritt ist wiederholbar).
- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben (Sites ganz ohne Plugin-Daten zählen als zustimmend); Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites.
- Große Netzwerke: `uninstall.php` bittet PHP um mehr Zeit (`set_time_limit(0)`, wo erlaubt) und läuft weiter, wenn der Browser die Verbindung schließt. Bricht der Webserver trotzdem ab, behält WordPress die Plugin-Dateien und meldet den Fehler; erneutes Löschen wiederholt die Deinstallation. Jeder Schritt ist idempotent, bereits bereinigte Sites haben keine Daten mehr und werden übersprungen – die Wiederholung macht dort weiter, wo der Abbruch war. Ohne Zeitlimit: `wp plugin deactivate pass-captain --network`, dann `wp plugin uninstall pass-captain`.
- Getestet in CI mit 30 Sites (`build/ci-multisite-test.sh`): Netzwerkaktivierung, Site löschen, Deinstallation mit gemischtem Opt-in.

## Große Events: Listen im Admin

- **Termine langer Serien (A476):** Die Event-Detailseite zeigt 50 Termine je Seite (Filter
  `wpcap_admin_occurrences_per_page`) und öffnet mit der Seite des nächsten Termins; die verkauften Plätze der
  Seite kommen aus einer Abfrage (vorher eine Umsatzabfrage je Termin). Terminauswahlen und Formulare je Termin
  (Zuweisungen, Einlassregeln, Offline-Scanner, Serienvorlage, Saalplan-Verknüpfung, Gästelisten) zeigen höchstens
  200 Termine (Filter `wpcap_admin_occurrence_choices`): die nächsten, aufgefüllt mit den zuletzt vergangenen;
  bereits zugewiesene Termine immer. Darunter steht „x von y Terminen werden angezeigt“ mit dem Feld
  **Termine ab**, das das Fenster auf einen Tag verschiebt.
- **Teilnehmer- und No-Show-Liste (A477):** Druckansicht und CSV lesen die Tickets blockweise (500 je Block,
  Filter `wpcap_attendee_list_chunk`; Reihenfolge wie bisher nach Name, dann Ticket) und schreiben jeden Block
  sofort aus (gestreamt, Ausgabepuffer werden vorher geleert). Teilnehmerantworten und Einlassprotokoll werden
  je Block nachgeladen, Summen („11 Tickets“, „9 von 11 gültigen Tickets nicht eingelassen“) kommen aus
  `COUNT`-Abfragen. Der Speicherbedarf hängt damit nicht mehr von der Größe des Events ab. Die Druckansicht
  bleibt eine einzige HTML-Seite: Bei zehntausenden Tickets ist für den Ausdruck die CSV-Datei oder der
  Stapeldruck (Hintergrundjob) die bessere Wahl.

## Datenschutz-Auskunft und -Löschung mit großen Datenmengen

WordPress ruft Exporter und Löscher seitenweise auf (eine AJAX-Anfrage je Seite), bis `done` gemeldet wird. Die Seiten des Plugins sind begrenzt, damit jede Anfrage auch bei Tausenden Bestellungen einer Adresse in wenigen Sekunden fertig ist:

| Werkzeug | je Seite |
|---|---|
| Exporter „Pass Captain Bestellungen und Tickets“ | je Liste höchstens 50 Einträge: Bestellungen, Tickets (gekauft oder als Teilnehmer), Pässe, Wartelisteneinträge, Terminbuchungen (eigene Gruppen); `done` erst, wenn alle Listen erschöpft sind. Das Einlassprotokoll eines Tickets ist vollständig (keine Kürzung). |
| Löscher „Pass Captain Bestellungen und Tickets“ | 50 Bestellungen und 200 Tickets anderer Käufer, auf denen die Adresse als Teilnehmer steht; zurückgestellte Bestellungen (kommende Termine) werden übersprungen. Hinweise an die Administration und die Mail „Löschanfrage zurückgestellt“ kommen einmal am Ende mit den Gesamtzahlen, nicht je Seite. |
| SMS-Einwilligungen (Exporter und Löscher) | 100 Einwilligungen |

Der tägliche Löschlauf arbeitet in Batches von höchstens 500 Zeilen und mit Zeitbudget (Filter `wpcap_privacy_retention_budget`); ein nicht fertiger Lauf wird beim nächsten Mal fortgesetzt. Getestet mit 2000 Bestellungen, 6700 Check-ins, 120 Wartelisteneinträgen und 300 SMS-Einwilligungen einer Adresse (`tests/integration/modules/zz_a196.php`).

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

Ohne `WPCAP_SECRETS_KEY` wird der Schlüssel aus den WordPress-Salts abgeleitet (`wp_salt('auth')` und `AUTH_KEY`); Administrator:innen sehen dann einen Hinweis – nur auf den Pass-Captain-Seiten, je Benutzer:in wegklickbar („Hinweis für mich ausblenden“, Benutzer-Meta `wpcap_dismissed_notices`) und dauerhaft als Empfehlung unter **Werkzeuge → Website-Zustand** (Test `wpcap_secrets_key`; A301). Nicht entschlüsselbare Zugangsdaten bleiben als Fehler auf den Plugin-Seiten und im Dashboard sichtbar, bis sie behoben sind (kritisch im Website-Zustand). Nachteil: Werden die Salts erneuert (z. B. nach einem Sicherheitsvorfall), sind die gespeicherten Zugangsdaten nicht mehr lesbar. Wird `WPCAP_SECRETS_KEY` nachträglich gesetzt, bleiben die bisherigen Werte lesbar und werden beim nächsten Admin-/Cron-Aufruf automatisch mit dem neuen Schlüssel neu verschlüsselt.

Vorrang beim Lesen: Konstante `WPCAP_<NAME>` (z. B. `WPCAP_STRIPE_SECRET_KEY`) > gleichnamige Umgebungsvariable > verschlüsselter Wert. Wer Zugangsdaten ganz außerhalb der Datenbank halten will, setzt die Konstanten; das Formular zeigt sie dann nur als „gesetzt über …“ an.

**Formulare:** Gespeicherte Werte werden nie angezeigt, nur „Gespeichert (verschlüsselt), endet auf …abcd“ (letzte vier Zeichen, nur bei Werten ab 12 Zeichen). Leeres Feld = Wert bleibt unverändert, die Checkbox „Gespeicherten Wert löschen“ entfernt ihn. Das Audit-Protokoll enthält nur die Namen geänderter Einstellungen, nie Werte.

**Migration:** Nach dem Update verschiebt der erste Aufruf (Frontend, Admin, Cron oder WP-CLI) vorhandene Klartextwerte hinter der Upgrade-Sperre (`GET_LOCK`, wie das Schema-Upgrade) in den verschlüsselten Speicher und entfernt sie aus `wpcap_settings` (Audit-Eintrag `secrets_moved_to_store` beim Schema-Upgrade über `Settings::migrate_secrets_to_store()`, sonst `secrets_migrated`; nur Namen). Der Schritt ist idempotent und unabhängig von der Schema-Version (Option `wpcap_secrets_version`). Taucht später erneut ein Klartextwert in `wpcap_settings` auf (z. B. nach einem Downgrade auf eine ältere Plugin-Version und erneutem Speichern), wird er beim nächsten Aufruf wieder übernommen und entfernt. Ältere Datenbanksicherungen enthalten die Zugangsdaten weiterhin im Klartext – Sicherungen von vor dem Update entsprechend schützen oder Zugangsdaten beim Anbieter erneuern.

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

## Downgrade (ältere Plugin-Version über neuerer Datenbank)

Die gespeicherte Schema-Version (`wpcap_db_version`) wird per `version_compare` mit `Installer::DB_VERSION`
verglichen. Stammt die Datenbank von einer neueren Version (z. B. nach dem Zurückspielen einer älteren
Plugin-Version ohne Datenbank-Restore), führt das Plugin **kein** Upgrade aus und schreibt die Version nicht zurück;
die Option `wpcap_schema_newer` enthält die neuere Version, Admin-Hinweis und Systemstatus melden „Datenbank stammt
von neuerer Version – Restore erforderlich“. Abhilfe: die neuere Plugin-Version wieder installieren oder die
Datensicherung von vor dem Update zusammen mit der passenden Plugin-Version wiederherstellen. Sobald Code und
Datenbank wieder zusammenpassen, verschwindet die Option.

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
| Stripe | `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`, `charge.refund.updated` (bzw. `refund.updated`/`refund.failed`), `charge.dispute.created`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`, `charge.dispute.closed` |
| PayPal | `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.DECLINED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`, `CUSTOMER.DISPUTE.CREATED` |
| Mollie | keine Auswahl nötig: Mollie ruft den Webhook bei jeder Statusänderung einer Zahlung auf (inkl. Erstattung und Chargeback) |

Erstattungen und Chargebacks, die beim Anbieter ausgelöst werden, verbucht Pass Captain über `Refunds::record_external()` (Gutschrift, Abrechnungsabzug, Fiskal-Korrekturaufgabe). Ist unter der Bestellsperre kein Betrag (und kein Ticket) mehr offen, wird nichts geschrieben (keine Erstattung über 0,00).

**Stripe-Teilerstattungen aus dem Dashboard:** Bei `charge.refunded` liest Pass Captain alle Erstattungen
der Zahlung (seitenweise) und verbucht jede ohne Plugin-Kennzeichen (`metadata.wpcap_refund`) einzeln unter
ihrer Erstattungs-ID – auch wenn Webhooks verspätet, in anderer Reihenfolge oder als Wiederholung ankommen.

**Nachträglich fehlgeschlagene Erstattungen:** Stripe meldet eine Erstattung oft zuerst als `pending`
(verbucht) und kann sie Tage später scheitern lassen (z. B. Karte gekündigt); Mollie führt Erstattungen
mit Status `failed`. Diese Ereignisse werden als `refund_failed` normalisiert: Die Erstattung wird über die
Anbieter-Referenz gefunden und auf „fehlgeschlagen“ gesetzt (`error_message=provider_refund_failed`, der
nächste Versuch nutzt einen neuen Idempotenzschlüssel), der erstattete Betrag der Bestellung wird
zurückgenommen (eine voll erstattete Bestellung wird wieder „bezahlt“), die Rechnungskorrektur wird als
storniert gekennzeichnet (Druckansicht/PDF; Belegexport: Zeile „Storno Gutschrift“; Zahlungsexport:
„Erstattung fehlgeschlagen“), die Admin-Adresse erhält eine Mail und der Fall steht unter *Systemstatus →
Zahlungen zur Klärung*. Widerrufene Tickets bleiben gesperrt (Plätze können schon weiterverkauft sein).
Grenze: Mollie-Erstattungen aus dem Dashboard werden kumulativ verbucht und sind keiner einzelnen
Buchung zuzuordnen; scheitert eine solche, bitte manuell korrigieren.

**Zahlungen zur Klärung (Systemstatus):** Doppelzahlungen (`double_payment`), Zahlungen auf stornierte oder
abgelaufene Bestellungen (`paid_for_closed_order`) und fehlgeschlagene Anbieter-Erstattungen werden je
Anbieter-Referenz einmal in `wpcap_payment_cases` erfasst (Diagnose `payment_cases`). Dort „Beim Anbieter
erstatten“ (Idempotenzschlüssel `wpcap-case-{id}`) oder „Als erledigt markieren“. Mit der Einstellung
*Erstattungen → Doppelzahlungen … automatisch erstatten* (Standard aus) erstattet ein Job solche Zahlungen
sofort. Erstattungs-Webhooks zu einer solchen Zahlung werden nie auf der Bestellung verbucht.

**Storno/Ablauf annulliert offene Zahlungen:** Wird eine unbezahlte Bestellung storniert oder läuft sie ab
(`wpcap_order_cancelled`), plant `Payments\Reconcile` einen Job, der die offene Zahlung beim Anbieter
annulliert: Stripe Checkout Session `expire`, Mollie `DELETE` (nur wenn `isCancelable`), PayPal nichts
(eine geschlossene Bestellung wird nie eingezogen). Nicht bei Stornos, die der Anbieter selbst ausgelöst hat.

**Verzögerte Zahlarten:** Mollie-Überweisung und SEPA-Lastschrift (`open`/`pending`) sowie PayPal-Captures
im Status `PENDING` (`PAYMENT.CAPTURE.PENDING`, Rückkehr/Webhook mit ausstehendem Capture) gelten als
`payment_pending`: Die Reservierung wird verlängert (Filter `wp_pass_captain_delayed_payment_hold_minutes_v1`,
Standard 7 Tage). Bei der Mollie-Überweisung wird das Fälligkeitsdatum (`dueDate`, die von Mollie dafür
vorgesehene Angabe; `expiresAt` ist bei Mollie nur ein Antwortfeld) auf das Ende der Reservierung gesetzt –
bei vorab gewählter Überweisung schon beim Anlegen, sonst per `PATCH` nach dem `open`-Webhook.

**Klarna/Riverty über Mollie:** Diese Methoden autorisieren nur. Meldet Mollie `authorized`, zieht Pass
Captain den vollen Betrag sofort ein (`POST /v2/payments/{id}/captures`, Idempotenzschlüssel
`wpcap-capture-{id}`); erst der folgende `paid`-Webhook bestätigt die Bestellung. Schlägt das Einziehen
fehl, antwortet der Webhook mit 5xx und Mollie wiederholt ihn. Entfernen der Methoden aus der Auswahl
allein würde nicht genügen: Ohne Methodenbeschränkung kann man sie auf der Mollie-Seite trotzdem wählen.

**Status-Abfrage bei fehlendem Webhook:** Alle 5 Minuten (Job-Lauf) fragt `Payments\Reconcile` bis zu 20
unbezahlte Bestellungen mit Anbieter-Referenz, die älter als `payment_poll_after_minutes` (Standard 15) und
höchstens 2 Tage alt sind, beim Anbieter ab (Stripe Checkout Session/PaymentIntent, Mollie Payment, PayPal
Order – eine freigegebene PayPal-Bestellung wird dabei eingezogen) und spielt das Ergebnis über
`Webhooks::process()` ein (Stripe: synthetische Ereignis-ID `poll-{referenz}-{typ}`; Mollie/PayPal: dieselbe
ID wie der Webhook). Ein später eintreffender Webhook ist dann wirkungslos (`already_paid`/`duplicate`).
Einen Import von Auszahlungs-/Gebührenberichten (Settlements) gibt es bewusst nicht: Für den Ticketstatus
reicht die Abfrage der einzelnen Zahlung; Gebühren und Auszahlungen sind Buchhaltungsdaten, die in den
Berichten des Anbieters bleiben und über den Zahlungsexport (Bestell-ID, Zahlungsreferenz) abgeglichen werden.

Lehnt der Anbieter eine im Plugin ausgelöste Erstattung endgültig ab (z. B. Stripe „Insufficient available balance“, HTTP 4xx), steht sie auf „fehlgeschlagen“; nach Behebung der Ursache (Guthaben aufgeladen) einfach erneut ausführen – der nächste Versuch nutzt einen neuen Idempotenzschlüssel (`wpcap-refund-{id}-{n}`). Bei Zeitüberschreitung oder 5xx bleibt der Schlüssel gleich, damit eine womöglich doch ausgeführte Erstattung nicht doppelt ausgezahlt wird. Eine eröffnete Zahlungsanfechtung (Dispute) erzeugt eine Admin-Mail; gebucht wird erst, wenn das Geld tatsächlich abgezogen wird.

## Terminverschiebung: Erstattungsantrag im Bestellportal

Wird ein Termin verschoben (`wpcap_occurrence_rescheduled`), merkt sich Pass Captain den Zeitpunkt
(`wpcap_occurrences.rescheduled_at`). Käufer:innen, die **vor** der Verschiebung bestellt haben, sehen
im Bestellportal den Kasten „Termin verschoben“ mit dem Button **„Erstattung beantragen“** – bis zur
Frist (Beginn des neuen Termins minus *Einstellungen → Erstattungen → Antragsfrist*, Standard 0 Stunden).
Der Antrag (`POST /pass-captain/v1/orders/{id}/refund-request`, Token wie die übrigen Portal-Aktionen
per `X-WPCAP-Access-Token`; je Client 10, je Bestellung 3 Anfragen pro Stunde) legt über
`Refunds::request()` eine Erstattung an, die **immer** auf Freigabe wartet (*Erstattungen*); erst die
Freigabe zahlt aus und sperrt die Tickets. Ein erneuter Antrag erzeugt keine zweite Erstattung.

Die Verschiebungs-Mail enthält `{refund_note}` (Text unter *Einstellungen → Erstattungen*, Platzhalter
`{refund_deadline}`), die SMS den Platzhalter `{refund_note}` (kurzer Hinweis mit Frist). Ist der Antrag
ausgeschaltet (`reschedule_refund_request`), steht in der Mail wie bisher „Bei Fragen zu Rückgabe oder
Erstattung wende dich bitte an den Veranstalter.“ und die SMS bleibt ohne Zusatz. Ob und wann bei einer
Verschiebung ein Rücktritts- bzw. Erstattungsanspruch besteht, hängt vom Vertrag und vom anwendbaren Recht
ab; die Texte sind Vorschläge und keine Rechtsberatung.

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

Erweiterung 16.11.2026 (A469): Der Drill prüft auch Zustände außerhalb des Schemas (Szenario 3; Szenario 1
und 2 ergänzt) – Zahlungs-Zugangsdaten, Kontingentzähler, Einstellungen und geänderte Standardwerte:

| Schritt | Ergebnis |
|---|---|
| 1.4.0 mit Stripe-Schlüsseln (Klartext in den Einstellungen) → neue Version | Schlüssel im verschlüsselten Speicher, Stripe weiter verfügbar; Einlassfenster für die Bestandsinstallation aus, Admin-Hinweis (A465) |
| Neue Version mit Stripe-Schlüsseln → `wp pass-captain secrets rollback-config` → Rollback auf 1.4.0 | Stripe in 1.4.0 über die Konstanten verfügbar; ohne Konstanten nicht (A463) |
| 1.4.0 verkauft 30 Tickets, Bestellungen drei Tage zurückdatiert (außerhalb des Zwei-Tage-Fensters des Abgleich-Crons) → neue Version | Zähler neu aufgebaut, keine Abweichung, Entscheidungen über SUM bis zur Prüfung, Verfügbarkeit = Kapazität − alle bezahlten Tickets, ein Ticket mehr als frei wird abgelehnt; nach dem Abgleich `ready`, Rest verkaufbar (A464) |
| Konstanten wieder entfernt | Stripe aus dem verschlüsselten Speicher; kein Schemafehler, Cron-Ereignisse eingeplant |

Mit dem Stand vor diesen Korrekturen schlug der Drill fehl: 41 verkaufte Tickets bei Kapazität 40 nach Rollback
und erneutem Update, keine Stripe-Schlüssel in 1.4.0, Einlassfenster nach dem Update ohne Hinweis aktiv.

Regeln für den Betrieb:

- **Zugangsdaten vor einem Rollback auf eine Version ohne verschlüsselten Speicher (bis 1.4.0, A463):** Diese
  Versionen lesen Zahlungs-, SMS- und Wallet-Zugangsdaten nur aus Konstanten `WPCAP_<NAME>`, Umgebungsvariablen
  oder Klartext in `wpcap_settings` – den verschlüsselten Speicher kennen sie nicht. Ohne Vorbereitung gelten
  nach dem Rollback alle Zahlungsarten als nicht eingerichtet. Vorher `wp pass-captain secrets rollback-config`
  ausführen (fragt nach, `--yes` überspringt die Frage; Audit-Eintrag `secrets_exported_for_rollback` nur mit
  Namen): Es gibt die gespeicherten Werte als `define()`-Zeilen für `wp-config.php` aus. Die Ausgabe enthält
  Klartext – nicht weitergeben, Terminal-Verlauf beachten. Beide Versionen lesen die Konstanten zuerst. Nach dem
  erneuten Update die Zeilen wieder entfernen; der verschlüsselte Speicher bleibt unverändert. Ohne WP-CLI: die
  Zugangsdaten nach dem Rollback in den Einstellungen der alten Version neu eingeben – beim erneuten Update
  werden sie automatisch wieder verschlüsselt übernommen. Ein dauerhafter Klartext-Fallback in der Datenbank ist
  bewusst nicht eingebaut.
- **Kontingentzähler nach einem Rollback:** werden beim erneuten Update automatisch neu aufgebaut (siehe
  „Kontingentzähler“, A464); bis zur Prüfung ist der Checkout langsamer.
- **Geänderte Standardwerte:** Ein neuer Standardwert, der das Verhalten bestehender Seiten ändern würde, gilt
  nur für Neuinstallationen. Beispiel Einlassfenster (A465): Installationen, die von Schema 3.5.0 oder älter
  (bis 1.4.0) kommen und die Einlass-Einstellungen nie gespeichert haben, behalten „kein Einlassfenster“
  (`default_entry_window` = 0 wird gespeichert, Admin-Hinweis bis zum Ausblenden oder Einschalten).
- **Rollback nur des Codes** (Plugin-ZIP der Vorversion einspielen) ist innerhalb der getesteten Spanne möglich: Schemaänderungen sind rein additiv, der ältere Code ignoriert neue Tabellen/Spalten. Daten, die nur die neue Version kennt (z. B. neue Felder), bleiben erhalten, werden vom alten Code aber nicht gepflegt. Danach möglichst bald wieder vorwärts aktualisieren.
- **Restore** immer als Paar aus Datenbank- und Plugin-Stand. Eine ältere Sicherung unter neuerem Code ist unkritisch (Upgrade läuft automatisch); eine neuere Sicherung unter älterem Code entspricht einem Code-Rollback (s. o.).
- Den Drill vor einem Release selbst wiederholen:

```bash
bash tests/restore/drill.sh /pfad/zu/wordpress /pfad/alt/pass-captain /pfad/neu/pass-captain /tmp/drill
```

Das Skript sichert vorher alle Tabellen mit dem Präfix der Test-Installation, arbeitet mit WP-CLI und `mariadb-dump` und stellt diese Tabellen und den Plugin-Link am Ende wieder her – **nur auf einer Test-Installation ausführen**, es löscht während des Laufs alle Plugin-Tabellen. Tabellen anderer Präfixe in derselben Datenbank werden weder gesichert noch überschrieben (vorher wurde die ganze Datenbank zurückgespielt). Für Szenario 3 legt es vorübergehend `wp-content/mu-plugins/wpcap-drill-rollback-secrets.php` an (Konstanten aus `rollback-config`) und entfernt die Datei wieder.

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

## Saalpläne: Rollstuhl- und Begleitplätze (A485)

- Im Saalplan-Editor wird ein Platz als **rollstuhlgerecht** (♿) oder als **Begleitplatz** (+, nur
  zusammen mit einem Rollstuhlplatz) markiert; beides zugleich geht nicht. Spalte
  `wpcap_seating_seats.is_companion` (Schema 3.10.18); Versionen, die vor dem Update veröffentlicht
  wurden, haben keine Begleitplätze.
- **Automatische Platzvergabe** (Kasse, WooCommerce, Adapter, Neuvergabe nach später Zahlung)
  vergibt Begleitplätze nie und Rollstuhlplätze nur, wenn der Filter
  `wpcap_seating_auto_assign_accessible` es erlaubt – dann erst nach allen anderen Plätzen. Sind nur
  noch solche Plätze frei, meldet die automatische Vergabe „keine passenden Sitzplätze“.
- In der Sitzplatzwahl kann jede Person einen Rollstuhlplatz wählen (Selbstauskunft). Ein
  Begleitplatz wird nur zusammen mit einem Rollstuhlplatz derselben Bestellung verkauft (höchstens
  ein Begleitplatz je Rollstuhlplatz, Fehler `seat_companion_only`). Wer Rollstuhlplätze nur nach
  Rücksprache vergeben will, legt sie in einen eigenen Bereich und ordnet ihn einer eigenen,
  nicht öffentlich verkauften Ticketart zu.

## Saalpläne: altes Sitzplan-API entfernt (A529)

Die Endpunkte `GET /events/{id}/seats`, `POST /events/{id}/seat-maps` und `POST /events/{id}/seat-holds` (Klasse
`PassCaptain\Seats`) stammten aus einem frühen Entwurf ohne Kaufpfad: Jede:r konnte öffentlich Plätze reservieren,
die sich nicht kaufen ließen, und jeder Abruf schrieb in die Datenbank. Sie sind entfernt (Antwort 404).
Sitzplatzwahl und Verkauf laufen über das Modul Saalpläne (`GET /seating/occurrences/{id}`, Checkout mit
Platzauswahl). Die Tabellen `wpcap_seat_maps`, `wpcap_seats` und `wpcap_seat_holds` bleiben für bestehende Daten;
alte Reservierungen laufen ab und werden vom Löschlauf entfernt.

## Saalpläne: Stehbereiche (A528)

- Ein Stehbereich mit Kapazität *n* wird beim Veröffentlichen zu *n* Stehplätzen mit den Schlüsseln
  `{Schlüssel des Bereichs}-1` … `-n` (Bereichsschlüssel auf 54 Zeichen gekürzt). Hat ein Sitzplatz oder ein
  Stehplatz eines anderen Bereichs denselben Schlüssel, lehnen Speichern und Veröffentlichen den Plan mit
  `wpcap_seating_standing_key` ab und nennen den Schlüssel – bisher fiel der Stehplatz still weg, sodass
  weniger Stehplätze verkauft wurden als eingestellt.

## Saalpläne: Verfügbarkeit und neue Versionen (A486, A487)

- **Verfügbarkeit:** Ist eine Ticketart eines Termins Bereichen/Kategorien des Saalplans
  zugeordnet, zeigen Eventkarte, Warenkorb und REST höchstens die freien passenden Plätze an
  (Plätze ohne aktive Reservierung oder Verkauf, auch Kontingent-Sperren), egal wie groß das
  Kontingent der Ticketart ist (Filter `wpcap_ticket_availability`, nur senkend). Ticketarten ohne
  Zuordnung sind vom Saalplan nicht begrenzt.
- **Verknüpfen:** Übersteigt das Kontingent einer Ticketart (bzw. des Termins) die Zahl der
  zugeordneten Plätze, oder passt eine zugeordnete Ticketart auf keinen Platz, erscheint nach dem
  Verknüpfen ein Warnhinweis.
- **Neue Version veröffentlichen:** Mit „verknüpfte Termine auf die neue Version umstellen“ wird
  die Veröffentlichung abgebrochen (`wpcap_seating_mapping_lost`), wenn in der neuen Version ein
  Bereich/eine Kategorie fehlt, der eine Ticketart eines verknüpften Termins zugeordnet ist, oder
  keiner ihrer Plätze mehr existiert; die Meldung nennt die Ticketarten. Dann entweder die Bereiche im
  Entwurf wiederherstellen oder ohne Umstellung veröffentlichen und jeden Termin danach mit neuer
  Zuordnung verknüpfen.
- **Laden im Checkout (A392):** Schlägt das erste Laden des Saalplans fehl (Netz, Ratenbegrenzung,
  Serverfehler), versucht der Platzwähler (`assets/seating/picker.js`) es nach 5, 10, 20 und dann
  alle 30 Sekunden erneut und baut den Plan beim ersten erfolgreichen Abruf auf – ein Neuladen der
  Seite ist nicht nötig. Ein fehlgeschlagener Abruf, während der Plan schon angezeigt wird, lässt
  ihn unverändert.
- **Abgesagte und vergangene Termine (A610):** `GET /seating/occurrences/{id}` antwortet für einen
  abgesagten Termin (oder ein abgesagtes Event) bzw. einen, dessen Online-Verkauf vorbei ist
  (begonnen, keine Ticketart mehr im Verkauf), mit HTTP 200 und `{occurrence_id, occurrence_status:
  "cancelled"|"ended", sales_open: false, message}` statt 404 – ohne Plan. Buchbare Termine tragen
  `occurrence_status: "scheduled"` und `sales_open: true`. Der Platzwähler zeigt dann den Grund statt
  „Laden fehlgeschlagen“, entfernt Plan und Auswahl, sperrt Mengenfelder und Bestellknopf, blockiert das
  Absenden und fragt nicht weiter nach. Termine unveröffentlichter Events und Termine ohne Saalplan
  bleiben 404; `Reservations::availability()` (WooCommerce, Umtausch) liefert für solche Termine
  weiterhin keinen Plan.

## Kasse: keine Registrierkasse

Die Kasse (Menü **Pass Captain → Kasse**) bucht Verkäufe vor Ort als Bestellungen, erzeugt Belege mit fortlaufender Nummer und einen Kassenabschluss. Sie ist **keine** Registrierkasse im fiskalrechtlichen Sinn:

- **Österreich (RKSV):** Bareinnahmen (dazu zählen auch Karten- und Wallet-Zahlungen vor Ort) erfordern ab den gesetzlichen Umsatzgrenzen eine Registrierkasse mit Sicherheitseinrichtung (Signaturerstellungseinheit), registriert bei FinanzOnline, inkl. Start-, Monats- und Jahresbeleg.
- **Deutschland (KassenSichV, § 146a AO):** Elektronische Aufzeichnungssysteme mit Kassenfunktion benötigen eine zertifizierte technische Sicherheitseinrichtung (TSE, BSI TR-03153) und sind dem Finanzamt zu melden; Belege müssen die TSE-Daten enthalten.

Die Kasse des Plugins signiert keine Belege und hat keine TSE-/Signaturkarten-Anbindung. Ist das Land des Rechnungsstellers DE oder AT, sind **Bar und Karte deshalb gesperrt** (Fehler `wpcap_pos_fiscal_required`); Freikarte und Rechnung bleiben möglich. Werden diese Verkäufe nachweislich zusätzlich in einer eigenen zertifizierten Kasse erfasst, kann ein Administrator Bar/Karte in den Belegeinstellungen der Kasse freigeben (Einstellung `boxoffice_cash_confirmed`, wird im Audit-Log protokolliert). Eine künftige Fiskal-Anbindung meldet sich über den Filter `wpcap_boxoffice_fiscal_ready`.

Für Bar- und Kartenzahlungen vor Ort deshalb eine zertifizierte Kasse verwenden und das Plugin nur für Ticketausgabe und Einlass nutzen, oder die Rechtslage mit der Steuerberatung klären. Der Hinweis auf der Kassenseite kann pro Benutzer ausgeblendet werden; der Link lässt sich über den Filter `wpcap_boxoffice_fiscal_docs_url` ändern.

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

## Ticket-PDF und Druck: Größe, Zeichensatz

- Alle PDFs (Ticket-Anhang, Stapeldruck, Namensschilder, Rechnungen) werden mit komprimierten Inhaltsströmen (FlateDecode) geschrieben; QR-Module werden zeilenweise zu Rechtecken zusammengefasst und jede fertige Seite sofort serialisiert (Stapeldruck mit QR: seitenweise in eine Temp-Datei). Messung (Ticketmail mit 500 Tickets, gleiche Maschine): klassischer Code vorher 10,2 MB PDF / 38 MB Speicher, nachher 0,75 MB / 7 MB; signierter QR vorher 30,4 MB / 108 MB und 2039 Abfragen, nachher 1,6 MB / 8,5 MB und 1040 Abfragen. Die Laufzeit bestimmt weiterhin die QR-Kodierung (BaconQrCode).
- Ticketmail: Bei mehr als 50 Tickets oder einem PDF über 5 MB wird kein PDF angehängt; die Mail enthält einen Hinweis auf die Ticketlinks (Filter `wp_pass_captain_ticket_pdf_limits_v1`). Wallet-Pässe: bei mehreren Tickets ein Sammel-Link statt eines `.pkpass` je Ticket (siehe [WALLET.md](WALLET.md)).
- Die PDF-Schrift ist Helvetica (WinAnsi). Zeichen außerhalb davon (Kyrillisch, CJK, Ł, ć …) werden in lateinische Buchstaben umgeschrieben (Intl-Transliterator, sonst iconv; „Привет Łódź“ → „Privet Lodz“) statt als „?“ zu erscheinen. Eine eingebettete Unicode-Schrift (CIDFontType2/Identity-H mit Subsetting) ist nicht enthalten: sie bräuchte einen TrueType-Parser/Subsetter und eine mitgelieferte OFL-Schriftdatei mehrerer MB.
- Ticket-PDFs der Mail enthalten den Teilnehmernamen (`ticket_assignments`), brechen lange Eventnamen auf zwei Zeilen um, kürzen andere Zeilen und tragen `/Title` und `/Lang` (Sprache der Bestellung).

## Datenschutz: Löschfristen, Auskunft und Löschung

**Täglicher Löschlauf** (`wpcap_privacy_retention`, WordPress-Cron, Stapel zu 500 Zeilen, Zeitbudget 20 s; der nächste Lauf setzt fort). Bezugspunkt ist das effektive Terminende (`ends_at`, sonst Beginn + Standarddauer, `Occurrence::end_sql()`, A606):

| Daten | Frist | Wirkung |
|---|---|---|
| Teilnehmerangaben (Zusatzfelder) | Einstellung „Teilnehmerdaten nach dem Event löschen nach“; bei 0 die Frist des Felds | gelöscht |
| Teilnehmername/-E-Mail (Zuordnungen, Tickets), Namen in Offline-Paketen | Einstellung; bei 0 365 Tage (`wpcap_attendee_default_retention_days`) | geleert; Wallet-Pässe werden aktualisiert (Google: Name entfernt, Apple: Push, das Gerät lädt den Pass ohne Namen); Ticket, Bestellung und Einlassprotokoll bleiben |
| Apple-Wallet-Geräteregistrierungen | wie Teilnehmername + 1 Tag (nach dem Push) | gelöscht |
| Noch nicht zugeordnete Teilnehmerangaben bezahlter Bestellungen (`attendee_data`) | letzter Termin der Bestellung + Teilnehmerfrist | geleert |
| Teilnehmerangaben unbezahlter (abgelaufener/stornierter) Bestellungen | 1 Tag | `attendee_data` geleert |
| Käufer-E-Mail nie bezahlter Bestellungen (ohne Ticket, ohne Rechnung) | 90 Tage (Einstellung) | durch Platzhalter `deleted+…@invalid.example` ersetzt |
| Versandte/fehlgeschlagene Nachrichten der Warteschlange | 30 Tage / 365 Tage (Einstellungen) | Empfänger und Inhalt geleert / Zeile gelöscht |
| Einlassprotokoll: Mitarbeitende und Gerät | 365 Tage (Einstellung) | `user_id`/`device_id` = 0 (Zeit, Ergebnis, Ticket bleiben). Der Lauf liest über `KEY created` ab einem gespeicherten Stand (`wpcap_retention_checkin_staff_cursor`) nur neu fällige Einträge; nachträglich mit altem Datum eingefügte Einträge erfasst der wöchentliche Volldurchlauf (A439) |
| Audit-Log (inkl. Protokoll der Zugriffe auf Personendaten, Exporte, Rollenänderungen und Korrekturen, siehe [SECURITY.md](SECURITY.md#audit-log-zugriffe-auf-personenbezogene-daten); einsehbar unter Pass Captain → Audit-Log) | 730 Tage (Einstellung) | gelöscht |
| Wartelisteneinträge | 30 Tage nach Terminende (Einstellung; bei eventweiten Einträgen nach dem letzten Termin; `wpcap_privacy_retention_days`, Schritt `waitlist`) | E-Mail geleert, Hash ersetzt; Status bleibt (offene Angebote nicht) |
| Gästelisten-Einträge | wie Teilnehmername/-E-Mail | Name, E-Mail, Firma, Notiz geleert; Anzahl/Status bleiben |
| Käuferlimit-Zähler | wie Teilnehmername/-E-Mail (nach dem letzten Termin des Events) | gelöscht |
| Ticketcodes von Partnerverkäufen | 30 Tage nach Terminende (Einstellung) | gelöscht |
| Rechnungen mit Positionen, Rechnungskorrekturen; Kassenschichten mit Z-Bericht, Zählungen, Buchungen, Übergaben und Verkaufsbelegen | Einstellung „Rechnungen und Rechnungskorrekturen löschen nach (Jahren)“, Standard 0 = nie; Frist ab Ende des Ausstellungs- bzw. Abschlussjahres (Kalenderjahr der Website-Zeitzone, Stichtag 1. Januar 00:00 Ortszeit, A605); eine Rechnung bleibt, solange eine ihrer Korrekturen noch in der Frist ist (`wpcap_financial_retention_years`) | gelöscht; Käufer-E-Mail, Rechnungsanschrift und Bestelllink der betroffenen Bestellungen pseudonymisiert, Beträge bleiben |
| Käufer-E-Mail, Benutzerverknüpfung und Rechnungsanschrift nie bezahlter Bestellungen (ohne Ticket, ohne Rechnung) | 90 Tage | E-Mail durch Platzhalter `deleted+…@invalid.example` ersetzt, `billing` geleert |
| Käuferdaten aller Bestellungen nach der gesetzlichen Frist | Einstellung „Käuferdaten in Bestellungen entfernen nach“ (Standard 10 Jahre ab Ende des Bestelljahres; AT: 7 Jahre genügen) | E-Mail (Platzhalter), Benutzerverknüpfung, Bestell-Link, `billing`, `attendee_data` der Bestellung; Käufer-E-Mail und Teilnehmerdaten der Ticketzuordnungen; E-Mail und Verwaltungslink der Terminbuchungen. **Nicht verändert:** Beträge, Positionen, Erstattungen, Rechnungen (samt `buyer_snapshot`), Gutschriften, Kassen- und Fiskalbelege |
| Event- und Termin-Wartelisten | Einstellung „Wartelisteneinträge … anonymisieren nach“ (Standard 30 Tage) nach Terminende bzw. Zeitfensterende; Einträge ohne Termin nach dem letzten Termin des Events | E-Mail durch Platzhalter ersetzt, Tokens geleert, offene Einträge `expired` |
| Unbestätigte Wartelisten-Anmeldungen (Double-Opt-in, beide Wartelisten) | sobald der Bestätigungslink abgelaufen ist (48 Stunden) | Eintrag gelöscht (Schritt `unconfirmed_waitlists`) |
| Terminbuchungen, die abgelaufen, storniert oder abgelehnt sind | 90 Tage (`service_holds`) | E-Mail durch Platzhalter ersetzt, Verwaltungslink ungültig |
| Terminbuchungen ohne Bestellung (Passbuchungen) | Zeitfensterende + Teilnehmerfrist | wie oben |
| Mitgliedspässe | Einstellung „Mitgliedspässe … anonymisieren nach“ (Standard 365 Tage) nach `valid_until` | E-Mail durch Platzhalter ersetzt, Passcode ungültig |
| Abgeschlossene SMS (`sms_messages`) | 30 Tage (`sms_content`) | Text und Fehlermeldung geleert |
| SMS-Nummernbestätigungen (`sms_verifications`) | 1 Tag (SMS-Worker) | Zeile gelöscht (Nummer, Code-/Token-Hash) |
| Einlassprotokoll, Einlassbuchungen (`admission_entries`) und Einlasskonflikte: Mitarbeitende und Gerät | 365 Tage (`checkin_staff`) | `user_id`/`device_id` (bei Konflikten auch Erst-Scan, Bearbeiter und Notiz) = 0/leer; Zeit, Ergebnis, Ticket bleiben |
| Bezeichnungen widerrufener/abgelaufener Scanner-Geräte und abgelaufener Kopplungscodes | 365 Tage (`checkin_staff`) | `label` geleert |
| Audit-Log | 730 Tage | gelöscht |
| Abgeschlossene Reservierungen (umgewandelt/abgelaufen/freigegeben) von Bestellungen, die nicht mehr auf Zahlung warten | 30 Tage nach Ablauf | gelöscht (Housekeeping) |
| Erledigte/abgebrochene Hintergrundjobs (`complete`/`cancelled`; Dead Letters bleiben) | 30 Tage | gelöscht (Housekeeping) |
| Abgelaufene Rate-Limit-Zähler (`wpcap_rate_limits`, nur HMAC-Schlüssel, keine Adressen) | nach Fensterende, beim nächsten Lauf (`rate_limits`, nicht abschaltbar) | gelöscht in Stapeln (`DELETE … LIMIT`, Index `expires_at`) |
| Idempotenz-Belegungen der Check-ins (`wpcap_checkin_claims`: Benutzer-ID, Schlüssel-Hash, Ticket-ID) | nach 7 Tagen (`checkin_claims`, nicht abschaltbar) | gelöscht in Stapeln (Index `created_at`); spätere Wiederholungen beantwortet weiter das Check-in-Protokoll |

**Stündliche Aufräumung abgeschlossener Zeilen** (A444, `Privacy\Retention::housekeeping()` im Cron
`wpcap_cleanup_reservations`, Stapel zu 500 Zeilen, Zeitbudget 10 s, Filter `wpcap_cleanup_budget`; der nächste
Lauf setzt fort). Gelöscht wird nur, was kein Ablauf mehr liest; die Fristen ändert der Filter
`wpcap_privacy_retention_days` (Schrittname, `0` = behalten):

| Tabelle | Schritt (Standard) | Gelöscht |
|---|---|---|
| `wpcap_reservations` | `reservations` (30 Tage nach `expires_at`) | Status `converted`/`expired`/`released` mit `counted_qty=0`; nicht, solange die Bestellung noch auf Zahlung wartet |
| `wpcap_jobs` | `jobs` (30) / `dead_jobs` (180) | erledigte (`complete`) und verworfene (`cancelled`) Jobs / Dead Letters; danach ist ihr Idempotenz-Schlüssel wieder frei |
| `wpcap_seat_holds` | `seat_holds` (30 nach `expires_at`) | `expired`/`released`; verkaufte Plätze (`sold`) bleiben |
| `wpcap_payment_events` | `payment_events` (90 nach `updated_at`) | `processed` und liegengebliebene `processing`; Anbieter stellen nach wenigen Tagen nicht mehr zu, der Duplikatschutz braucht sie nicht mehr |
| `wpcap_logs` | eigener Schritt `Log::purge()` (30 Tage, Filter `wpcap_log_retention_days`; höchstens 20 000 Einträge) | Einträge des Support-Protokolls (A327); die Löschung einer Bestellung (Datenschutz) entfernt ihre Einträge sofort |

Alle Abfragen sind Indexbereiche (`expiry`, `due_jobs`, `cleanup` auf `wpcap_payment_events` ab Schema 3.10.12).
Messung: 457.106 alte Reservierungen in zwei Läufen (≈ 23.000 Zeilen/s), siehe
[TESTING.md](TESTING.md#historie-reservierungen-index-und-aufräumen-a429-a430-a444).

Alle Fristen stehen unter **Einstellungen → Allgemein → Datenschutz** (0 = Schritt aus, bei Partner-Ticketcodes: direkt nach dem Termin) und lassen sich zusätzlich mit `wpcap_privacy_retention_days` ändern (siehe [HOOKS.md](HOOKS.md)) (A123). Das Audit-Log erhält je Lauf nur die Anzahlen (`privacy_retention`). Nicht erfasst: Bestellungen, Positionen und Erstattungen (Rechnungen/Korrekturen nur mit eingestellter Belegfrist, siehe oben), Bestellungen in WooCommerce (eigene Fristen des Shops), Daten bei Zahlungsanbietern, Partnern und Webhook-Empfängern. Webhook-Zustellungen haben eine eigene Frist (Modul Webhooks).

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste und Terminbuchungen. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Umgekehrt enthält die Auskunft des Käufers für Tickets, die er übertragen hat (`transfer_count` > 0, A651), nur die Weitergabe selbst (Ticket, Event, von ihm eingetragene Empfängeradresse) und die Einwilligungsnachweise aus der Zeit davor – nicht Name, Zusatzangaben, eigene Einwilligungen, Einlassprotokoll und Wallet-Pässe der neuen Inhaberin; diese stehen in deren eigener Auskunft. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten und Terminbuchungen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Sperr-Push-Nachricht). Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Bestellungen mit Tickets für kommende Termine werden zurückgestellt (`wpcap_privacy_defer_upcoming`, Standard an): Die Tickets bleiben gültig, die Bestellung wird automatisch nach dem letzten Termin anonymisiert; die Löschung meldet die Anzahl. Soll sofort widerrufen werden, den Filter auf `false` setzen bzw. vorher erstatten/stornieren. **Offene Zahlungen (A355):** Unbezahlte Bestellungen der Adresse (`pending` oder `expired` – eine Zahlungsseite kann noch bezahlt werden) werden vor der Anonymisierung storniert (Grund `privacy_erasure`, Reservierung frei); eine danach eintreffende Zahlung trifft auf eine stornierte Bestellung, stellt keine Tickets an die Platzhalteradresse aus und wird dem Admin gemeldet (bzw. mit `late_payment_auto_refund` erstattet). Bezahlte Bestellungen ohne Tickets in Klärung (`needs_review`/`paid_unallocated`) und Bestellungen, deren Storno gerade nicht gelingt, werden zurückgestellt und nach der Klärung (Zuteilung, Erstattung) vom stündlichen Lauf anonymisiert; die Löschung meldet sie gesondert, der Admin bekommt die Mail „Löschanfrage wartet auf Zahlungsklärung“. Eigener Export/Löschung zusätzlich: Käuferlimit-Zähler (`pass-captain-buyer-limits`).
**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste und Terminbuchungen. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten und Terminbuchungen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Sperr-Push-Nachricht; vorgemerkt in der Option `wpcap_privacy_wallet_purges`, sodass der tägliche Löschlauf sie auch dann entfernt, wenn eine Deaktivierung das Cron-Ereignis verworfen hat – A510). Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Bestellungen mit Tickets für kommende Termine werden zurückgestellt (`wpcap_privacy_defer_upcoming`, Standard an): Die Tickets bleiben gültig, die Bestellung wird automatisch nach dem letzten Termin anonymisiert; die Löschung meldet die Anzahl. Soll sofort widerrufen werden, den Filter auf `false` setzen bzw. vorher erstatten/stornieren. **Offene Zahlungen (A355):** Unbezahlte Bestellungen der Adresse (`pending` oder `expired` – eine Zahlungsseite kann noch bezahlt werden) werden vor der Anonymisierung storniert (Grund `privacy_erasure`, Reservierung frei); eine danach eintreffende Zahlung trifft auf eine stornierte Bestellung, stellt keine Tickets an die Platzhalteradresse aus und wird dem Admin gemeldet (bzw. mit `late_payment_auto_refund` erstattet). Bezahlte Bestellungen ohne Tickets in Klärung (`needs_review`/`paid_unallocated`) und Bestellungen, deren Storno gerade nicht gelingt, werden zurückgestellt und nach der Klärung (Zuteilung, Erstattung) vom stündlichen Lauf anonymisiert; die Löschung meldet sie gesondert, der Admin bekommt die Mail „Löschanfrage wartet auf Zahlungsklärung“. Eigener Export/Löschung zusätzlich: Käuferlimit-Zähler (`pass-captain-buyer-limits`).

**Pflichten des Betreibers:**

- WordPress-Cron zuverlässig auslösen (siehe oben), sonst greifen die Fristen nicht.
- Den Vorschlag unter **Einstellungen → Datenschutz → Leitfaden** prüfen, an Verantwortlichen, Verträge und Abläufe anpassen und in die Datenschutzerklärung übernehmen; nach Aktivieren weiterer Dienste (Zahlungsanbieter, SMS, Wallet, Partner, Webhooks, Affiliate) erneut prüfen.
- Auftragsverarbeitungsverträge mit Hosting, E-Mail-Versand, SMS-Anbieter und gegebenenfalls Partnern bzw. Webhook-Empfängern schließen; Übermittlungen in die USA (Stripe, PayPal, Twilio, Apple, Google) dokumentieren.
- Affiliate-Tracking: Mit dem Plugin „WP Consent API“ und einem kompatiblen Cookie-Banner wird der Empfehlungs-Cookie nur mit Einwilligung „marketing“ ausgewertet; der Partnerlink der laufenden Shop-Sitzung (ohne Marketing-Cookie) zählt auch ohne diese Einwilligung. Ohne Consent-API gilt der Cookie als nicht eingewilligt und wird nicht ausgewertet; blockiert Ihr Cookie-Banner den Affiliate-Cookie bis zur Einwilligung, binden Sie den Filter `wpcap_affiliate_tracking_allowed` an Ihren Consent-Manager an (oder geben ihn frei).
- SMS-Einwilligung: Standardmäßig muss die Mobilnummer im Checkout mit einem per SMS gesendeten 6-stelligen Code bestätigt werden (Einstellung *SMS → Nummer per Code bestätigen*, Double-Opt-in). Nachweis je Einwilligung: Zeitpunkt der Einwilligung, Textversion und Zeitpunkt der Code-Bestätigung (`verified_at`, Audit-Eintrag `sms_consent_verified`); der Datenexport zeigt den Bestätigungsstatus. Einwilligungen von vor dem Update bleiben gültig und sind als „unverifiziert (Altbestand)“ gekennzeichnet (Übersicht auf der SMS-Seite). Kosten: Jeder Code ist eine SMS; Limits je Nummer, IP und Site siehe [SECURITY.md](SECURITY.md#rate-limits-und-client-adresse) – zusätzlich ein Ausgabenlimit beim SMS-Anbieter setzen. Wird die Bestätigung ausgeschaltet, gelten neue Einwilligungen als „unverifiziert (ohne Code erfasst)“.
- Rechnungen enthalten die Käufer-E-Mail im `buyer_snapshot` (bleibt bis zur manuellen Löschung nach Ablauf der Aufbewahrungsfrist). Ob die E-Mail auf einer Rechnung zwingend ist, ist eine Rechtsfrage (Pflichtangaben nach § 14 UStG / § 11 UStG-AT verlangen sie nicht); eine Option, sie künftig in einem getrennten, löschbaren Feld zu führen, ist offen (Audit PRIV-9).
- Web-Scanner auf geteilten Geräten: vor Schichtwechsel synchronisieren, nach Schichtende Browser schließen bzw. Website-Daten löschen (Offline-Scans liegen bis zu 24 Stunden im Browser).
- Gesetzliche Aufbewahrung von Rechnungen und Buchungsdaten (7 Jahre § 132 BAO, 10 Jahre § 147 AO/§ 257 HGB) überwachen und danach manuell löschen; das Plugin entfernt nach der eingestellten Frist nur die Käuferdaten aus Bestellungen, Tickets und Terminbuchungen, nicht Rechnungen, Gutschriften oder Fiskalbelege. In Österreich kann die Einstellung auf 7 Jahre gesenkt werden; laufende Außenprüfungen oder Verfahren verlängern die Frist (dann vorübergehend erhöhen).
- „Beim Löschen des Plugins alle Daten entfernen“ löscht auch Rechnungen, Gutschriften, Kassen- und Fiskalbelege und lässt sich nur mit der Eingabe „LÖSCHEN“ aktivieren: vorher Rechnungen und das Fiskal-Archiv exportieren.
- Partner-API-Schlüssel mit `attendees:read` nur mit Rechtsgrundlage vergeben; gelöschte Adressen liefert die API als `null`. Abrufe von Bestellungen und Tickets mit E-Mail-Adressen werden im Audit-Log protokolliert (`partner_api_pii_read`: Schlüssel, Route, Seite, Anzahl; höchstens einmal je Schlüssel, Route und Seite pro Stunde).
- Support-Rolle: E-Mail-Adressen auf Wartelisten (Bildschirm und CSV) und in Terminbuchungen werden wie in Bestellungen maskiert (Richtlinie „Support sieht personenbezogene Daten maskiert“).
- Gesetzliche Aufbewahrung von Rechnungen und Buchungsdaten (7 Jahre § 132 BAO, 10 Jahre § 147 AO/§ 257 HGB) überwachen und danach manuell löschen; das Plugin löscht Finanzdaten nicht automatisch.
- Partner-API-Schlüssel mit `attendees:read` nur mit Rechtsgrundlage vergeben; gelöschte Adressen liefert die API als `null`.
- Vorlagen für Gästelisten-Information (Art. 14 DSGVO), Rolle der Veranstalter, Kassen-Schichtdaten (Mitbestimmung, Z-Bericht-Aufbewahrung), AV-Vertrag Fiskalanbieter, Auskunft ohne E-Mail und Verarbeitungsverzeichnis: [BETREIBERPFLICHTEN.md](BETREIBERPFLICHTEN.md).

Die übrigen Fristen lassen sich mit `wpcap_privacy_retention_days` ändern oder abschalten (siehe [HOOKS.md](HOOKS.md)). Das Audit-Log erhält je Lauf nur die Anzahlen (`privacy_retention`). Nicht erfasst: Rechnungen, Gutschriften, Kassen- und Fiskalbelege (gesetzliche Aufbewahrung; nach Fristablauf, unter Berücksichtigung laufender Prüfungen, löschen Sie diese selbst), Kassensitzungen der Abendkasse samt Notizen (Kassenbuch), Bestellungen in WooCommerce (eigene Fristen des Shops; die Pass-Captain-Teilnehmerkopie `_wpcap_attendees` wird bei stornierten Bestellungen sofort, bei fehlgeschlagenen nach 7 Tagen entfernt), Daten bei Zahlungsanbietern, Partnern und Webhook-Empfängern. Webhook-Zustellungen haben eine eigene Frist (Modul Webhooks); ausstehende Zustellungen deaktivierter Webhooks werden nach dieser Frist ohne personenbezogene Daten abgebrochen.

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Benutzerverknüpfung, Erstattungen samt Grund, Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste, Terminbuchungen (mit Stornogrund), E-Mail-Benachrichtigungen (Vorlage, Status, Zeit), SMS samt Text sowie Ansprechpartner-Angaben bei Partnern und Verkäufern. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Käufer erhalten die Angaben, die sie selbst eingegeben haben (Name, Zusatzfelder), aber keine Einwilligungen anderer Personen: Einwilligungsnachweise gehören der Person, die einwilligt (bzw. für die eingewilligt wurde), und werden nur mit deren Anfrage exportiert oder gelöscht. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten, SMS-Texte, Verwaltungslinks, Benutzerverknüpfung, Ansprechpartner-Angaben und Terminbuchungen, aktualisiert Wallet-Pässe ohne Namen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Push-Nachricht) – auch wenn nur die Teilnehmerangaben einer Person aus fremden Bestellungen entfernt werden. Gültige Mitgliedspässe werden wie Bestellungen mit kommenden Terminen bis zum Ende der Gültigkeit zurückgestellt und danach automatisch anonymisiert. Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Hat die Person Tickets für kommende Termine, weist die Löschung darauf hin; Erstattung oder Storno müssen Sie selbst veranlassen.

## Kasse: Kassen, Schichten, Übergabe und Z-Bericht

Die Kasse arbeitet mit **Kassen** (Schubladen) und **Schichten**. Sie ersetzt keine Registrierkasse im fiskalrechtlichen Sinn (siehe oben), macht den Bargeld- und Kartenabgleich aber nachvollziehbar.

**Kassen** (Pass Captain → Kasse → Kassen, nur Administratoren): Name, Standort, optional ein Event (dann verkauft die Kasse nur dessen Termine), Währung und Blindzählung. Kassen werden nicht gelöscht, nur deaktiviert, damit ihre Z-Berichte vollständig bleiben. Eine Kasse hat höchstens eine offene Schicht.

**Schicht öffnen** (Reiter „Verkauf“ ohne offene Schicht): Kasse wählen, Wechselgeld zählen (Betrag oder Stückelung nach Scheinen und Münzen). Weitere Kassierer können der Schicht hinzugefügt werden (Reiter „Schicht“); jede Person arbeitet in höchstens einer offenen Schicht. Verkäufe werden der Schicht zugeordnet, in der die Person arbeitet.

**Wiederholtes Absenden** eines Verkaufs (gleicher Formularschlüssel, auch über die alte Route `POST /pos/sales`) liefert den bestehenden Verkauf bzw. bucht ihn fertig – nur für dieselbe Person und nur, solange sie noch Zugriff auf Event und Termin des Verkaufs hat (A637); sonst `wpcap_forbidden` (403).

**Einlagen und Entnahmen** (Reiter „Schicht“): immer mit Betrag und Grund; sie verändern den Soll-Bestand.

**Zählung und Abschluss** (Reiter „Schicht“):

1. *Zählung abgeben*: gezählter Bargeldbestand (Betrag oder Stückelung) und die Summe laut Kartenterminal (Tagesabschluss des Terminals; leer lassen, wenn kein Terminal genutzt wurde). Bei **Blindzählung** sehen Kassierer Soll-Bestand und Differenz erst im Z-Bericht nach dem Schließen; sie geben genau eine Zählung ab, schließen ohne Differenzgrund, und eine Nachzählung kann nur die Kassenverwaltung (`manage_options`) oder `wpcap_view_finance` durchführen. Administratoren und Personen mit `wpcap_view_finance` sehen den Soll-Bestand immer. Ab der Zählung sind keine Verkäufe und Buchungen in der Schicht mehr möglich. Eine Neuzählung wird als weiterer Eintrag gespeichert; für den Abschluss zählt die letzte, der Z-Bericht listet alle Zählungen mit ihrer Differenz.
2. *Schicht abschließen*: Weichen Bargeld oder Kartenterminal vom Soll ab, ist ein Grund Pflicht. Optional **Übergabe** an die nächste Person mit dem Betrag, der in der Kasse bleibt; der Rest gilt als abgeschöpft (Tresor/Bank). Der Abschluss erzeugt den **Z-Bericht**.

Offene Stornos, die auf Freigabe warten, und bezahlte, noch nicht gebuchte Verkäufe verhindern Zählung und Abschluss (zuerst freigeben/ablehnen bzw. „Nachbuchen“).

**Übergabe annehmen**: Die empfangende Person sieht die Übergabe auf dem Reiter „Verkauf“, zählt das Geld (bei Blindzählung ohne den übergebenen Betrag zu sehen) und öffnet damit die nächste Schicht. Eine Abweichung braucht einen Grund; übergebende und empfangende Person, Betrag und Differenz werden gespeichert und protokolliert (Audit-Log `pos_handover_created`, `pos_handover_accepted`). Solange eine Übergabe wartet, kann niemand anderes die Kasse öffnen; Administratoren und die übergebende Person können die Übergabe mit Grund stornieren (Reiter „Kassen“).

**Z-Bericht** (Reiter „Schichten & Z-Berichte“, als druckbares HTML – im Browser „Als PDF speichern“ – und als CSV): fortlaufende, lückenlose Z-Nummer je Kasse, Kassen-ID und -Name, Öffnungs- und Schließzeit, Kassierer, Summen je Zahlart (Verkäufe, Stornos/Erstattungen, netto), je Steuersatz (netto, USt., brutto; nach Abzug der Erstattungen), Anzahl Verkäufe, Stornos, Erstattungen und Freikarten, Bargeld (Anfangsbestand, Barumsatz, Auszahlungen, Einlagen, Entnahmen, Korrekturen, Soll, gezählt, Differenz, Stückelung, Übergabe, Abschöpfung), Kartenterminal (Soll, Terminal-Summe, Differenz), Grund der Differenz, alle Buchungen der Schicht, Nettoumsatz und der kumulierte **Grand Total** der Kasse. Z-Berichte, Zählungen und Buchungen werden nur eingefügt, nie geändert. Jeder Z-Bericht trägt eine Prüfsumme und die Prüfsumme seines Vorgängers; die Seite zeigt je Kasse, ob die Kette lückenlos und unverändert ist. Ein Fehler in einem abgeschlossenen Z-Bericht wird als **Korrektur** (Administratoren) in die offene Schicht derselben Kasse gebucht und erscheint im nächsten Z-Bericht.

**Storno und Erstattung von Kassenverkäufen**:

- Storno ist auch nach dem Schichtabschluss möglich (Belegsuche auf dem Reiter „Verkauf“), ganz oder für einzelne Tickets. Die Auszahlung wird mit eigenem Stornobeleg (Belegnummernkreis der Kasse) als negative Buchung in die Schicht des Verkaufs gebucht, solange sie offen ist, sonst in die offene Schicht der handelnden Person oder der Kasse des Verkaufs.
- Bar- und Kartenauszahlungen an der Kasse (Storno) brauchen eine offene Schicht der handelnden Person.
- **Erstattungen aus der Verwaltung** (Bestellung, automatische Erstattung bei Terminabsage) bewegen an
  keiner Kasse Geld (A488); ein an der Kasse beantragter Storno wird auch nach späterer Freigabe dort
  ausgezahlt (Schicht des Verkaufs bzw. wie beim Storno). Hat die handelnde Person selbst eine offene
  Schicht, wird die Auszahlung dort gebucht; sonst wird sie als **offene Kassen-Rückzahlung** vorgemerkt
  (Tabelle `wpcap_boxoffice_payouts`, Schema 3.10.18, Audit `pos_payout_open`) – nie in die Schicht
  einer anderen Kassierkraft. Die Erstattung selbst ist abgeschlossen (Tickets widerrufen, Plätze frei).
  Auf dem Reiter „Schicht“ listet „Offene Kassen-Rückzahlungen“ diese Beträge; „Auszahlen“ bucht sie
  mit Auszahlungsbeleg in die eigene offene Schicht (nur Kassierkräfte der Schicht, je Erstattung
  einmal, Audit `pos_payout_paid`). Freikarten- und Rechnungs-Erstattungen werden wie bisher in die
  nächste offene Schicht gebucht.
- **Zählen/Abschließen während eines Verkaufs** (A489): Ein Verkauf prüft unter der Schichtsperre,
  dass die Schicht noch offen ist, bevor die Zahlung bestätigt und Tickets ausgestellt werden; sonst
  wird die Bestellung ohne Tickets storniert. Solange ein Verkauf der Schicht noch gebucht wird
  (höchstens 2 Minuten, Zahlung noch nicht bestätigt), lehnen Zählung und Abschluss mit
  `wpcap_pos_sale_in_progress` ab. Beim Abschluss werden unvollständige Verkäufe als fehlgeschlagen
  markiert und ihre unbestätigten Bestellungen storniert – kein Ticket bleibt ohne Beleg und
  Z-Bericht.
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
protokolliert (`accounting_exported`, mit der Zahl der gesendeten Zeilen).

**Große Zeiträume (A434)**: Export und Buchungsstapel werden Zeile für Zeile an den Browser gestreamt
(`Invoices::export_stream()`), Bestellungen ohne Rechnung in Blöcken zu 1.000 mit ihren Positionen,
Verkäufer-Kontext und Umtauschkennzeichen gelesen – einige Abfragen je Block statt mehrerer je Bestellung.
Umsatzbericht, Termin-Abrechnung und Wiederverkäufer-Abrechnung arbeiten ebenso blockweise
(`Reports\Data::order_blocks()`). Messwerte: docs/TESTING.md, Abschnitt „Historie: Admin-Listen, Berichte und
Aufräumen“.

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
| `Rechnungskorrektur` (Beschreibung `Storno Erstattung`) | Storno-Beleg einer Rechnungskorrektur, deren Erstattung beim Zahlungsanbieter nachträglich scheiterte (A348): Belegnummer `reversal_number`, Bezug = Nummer der Korrektur, Beträge positiv | Storno |
| `Erstattung ohne Rechnung` (Beschreibung `Storno Erstattung ohne Rechnung …`) | dasselbe für Bestellungen ohne Rechnung (positiv); die ursprüngliche Erstattungszeile bleibt an ihrem Tag | Storno |

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
- Konten: Einstellung „Kontenrahmen für den DATEV-Buchungsstapel“ (Einstellungen → Rechnungen): SKR03 (Standard) – 19 % → 8400, 7 % → 8300, andere Sätze → 8200, Geldkonto 1360 (Geldtransit); SKR04 – 4400, 4300, 4200, Geldkonto 1460. Ein eigenes Geldkonto (4–8 Ziffern) ersetzt den Geldtransit;
  darüber hinaus anpassbar über den Filter `wpcap_datev_accounts` (z. B. SKR04, Geldkonto je Zahlungsanbieter, BU-Schlüssel
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

### Einlass: Check-in-Quote, Einlasskurve, Teilnehmer- und No-Show-Liste (A571–A573)

Bericht (Termin-Tabelle, CSV, Abrechnungsblatt, Partner-API `/partner/reports`), No-Show-Liste und
Teilnehmerliste verwenden dieselbe Regel (`Reports\Attendance`):

- **Tickets eines Termins**: Tickets der Ticketarten des Termins; Pässe (Modul Pässe) auf **jedem verknüpften
  Termin** – auch Termine anderer Events –, auf ihrem Ankertermin (Verkaufstermin) nur, wenn er selbst verknüpft ist.
- **Eingelassen**: Status „Eingecheckt“ oder mehr „checked_in“- als „undone“-Einträge im Check-in-Protokoll
  (wiederholbare Einlassregeln behalten den Status „Gültig“); ein Pass, wenn er an **diesem** Termin einen nicht
  zurückgenommenen Einlass hat. Zurückgenommene Einlässe zählen also weder in der Quote noch in der Kurve, und
  „Gültig − Eingecheckt“ im Bericht ist genau die Zahl der Zeilen der No-Show-Liste.
- **Einlasskurve** („Check-ins pro Stunde“): nur wirksame Einlässe (jede Rücknahme hebt den letzten offenen
  Einlass des Tickets auf, z. B. einen Testscan beim Aufbau). Die Achse beginnt mit dem Einlassbeginn
  (Einlass ab, sonst Beginn minus Einlassfenster, sonst Terminbeginn), sobald es ab dann Einlässe gibt; nichts
  wird abgeschnitten (früher: höchstens 72 Stunden ab dem ersten Scan). Leere Stunden zwischen zwei Balken werden
  bis 24 Stunden aufgefüllt, längere Lücken bleiben offen.
- Die Teilnehmerliste zeigt Pässe und Tickets mit wiederholbarer Einlassregel als „Eingecheckt“, sobald sie an
  diesem Termin eingelassen wurden, und die Teilnehmerfelder aller aufgeführten Ticketarten. Der Stapeldruck von
  Tickets/Badges bleibt bei den Ticketarten des Termins (Pässe werden mit ihrem Ankertermin gedruckt).

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

**Schema 3.10.18** (A491, Konditionen mit Gültigkeit): neue Tabelle `wpcap_partner_terms_history`.
Ändert sich beim Speichern eines Veranstalters Provision, Ticketgebühr oder Erstattungsregel, werden
die bisherigen Konditionen „von Beginn an“ (nur beim ersten Mal) und die neuen ab dem Speicherzeitpunkt
festgehalten (Audit `partner_terms_changed`, Verlauf auf der Veranstalter-Seite). Die Abrechnung nimmt
je Position die Konditionen, die beim Bestelldatum galten (`Repository::terms_at()`); Erstattungen und
Korrekturen kehren genau die beim Verkauf berechnete Provision/Gebühr um. Ohne Verlaufszeilen gelten die
aktuellen Konditionen für alle Verkäufe (bisheriges Verhalten). Nicht versioniert sind weiterhin:
Verkaufsmodell (`own_name`/`agent`, gilt beim Abschließen für die ganze Abrechnung), USt.-Satz auf die
Plattformgebühr, Wiederverkäufer-Konditionen (`reseller_*`), Stammdaten wie Name/Adresse/USt-IdNr. (werden
beim Abschließen als Momentaufnahme gespeichert) und Ticketart-/Bereichszuordnungen im Saalplan (Saalplan-
Versionen sind unveränderlich, siehe A487).

**Schema 3.10.8** (A448): neue Tabelle `wpcap_partner_event_history` (Zuordnung Event ↔ Veranstalter mit
Gültigkeitszeitraum), `wpcap_partner_settlement_lines` bekommt `UNIQUE source_key` statt
`partner_source (partner_id,source_key)` sowie `order_id` und `partner_event (partner_id,event_id)` – die
Abrechnung liest bereits abgerechnete Positionen je Bestellung statt der ganzen Historie des Veranstalters.
Die Datenmigration (`Modules\Partners::migrate()`, Option `wpcap_partners_migration`) läuft nach dem
Schema-Update unter der Upgrade-Sperre: Zuordnungen „von Beginn an“ für bestehende Verknüpfungen,
Gutschein-Sperrschlüssel mit Veranstalter-ID, Doppelabrechnungen als `dup:<line_id>` markiert (Audit
`partner_settlement_duplicates`), dann Indextausch. Scheitert der Indextausch, bleibt die Option
unverändert und die Migration läuft beim nächsten Aufruf erneut; die Doppelprüfung der Abrechnung ist davon
unabhängig plattformweit.

**Veranstalter-Abrechnung über lange Historie (A435)**: Entwurf und Abschluss lesen nur noch offene Quellen –
in SQL begrenzt: Bestellungen mit einer Position der Events des Veranstalters (über Termine und Ticketarten,
nicht über alle Bestellungen), deren Verkauf noch niemand abgerechnet hat (`source_key = 'sale:<Position>'`
über den Unique-Index), abgeschlossene Erstattungen, für die dieser Veranstalter noch keine abgeschlossene
Zeile hat, und Gästelisten-Bestellungen nur aus dem Abrechnungsmonat (sie werden nur gezählt). Verarbeitet
wird in Blöcken zu 1.000 Bestellungen (Positionen, Sperrschlüssel, Selbstinkasso, Umtausch- und
Gutscheinkennzeichen je Block). Bereits abgerechnete Monate werden also nicht bei jedem Entwurf erneut
gelesen; Bestellungen, deren Positionen niemand abrechnet (z. B. nach einer Umbuchung ins Event eines
anderen Veranstalters), bleiben Kandidaten, erzeugen aber keine Zeilen. Messwerte: docs/TESTING.md.

**Schema 3.10.9** (A265, keine Tabellenänderung): Neue Installationen starten ohne aktive Zahlungsart. Beim Update
behalten Installationen, die ihre Zahlungsarten nie gespeichert haben, „Überweisung“ als ausdrücklich gespeicherten Wert
(`Installer::pin_legacy_manual_provider()`), siehe „Überweisung (manuelle Zahlung) einrichten“.

**Event-Auswahllisten** im Admin zeigen höchstens 500 Events (`Queries::EVENT_OPTIONS_LIMIT`, alphabetisch).
Ist die Liste gekürzt, erscheint ein Hinweis; ein gewähltes oder gespeichertes Event (z. B. `&event_id=…` in
der Adresse) steht immer in der Liste, sodass ein Filter oder Formular nie still auf ein anderes Event
springt. Die Veranstalterseite nutzt statt einer Liste eine Suche mit Seitenblättern (A447).

## PDF-Erzeugung, Schriften und Druckvorlagen (A330)

Alle PDFs – Ticket-PDF der Ticketmail, Download im Bestellportal und im Admin („Ticket-PDF“), Drucklayouts
(Ticket, Badge, Namensschild, Einlassband, auch als Stapeldruck) und Rechnungen – entstehen mit einer Engine
(`Printing\PdfWriter`). Das Ticket-PDF nutzt das Layout „Ticket (A4)“ und damit die unter **Druck & Listen** für das
Event gewählte Ticketvorlage (sonst die Standardvorlage); dazu kommen je Seite der Name der Website als Kopfzeile, der
vollständige Code unter dem QR-Code, der Hilfscode mit Erklärung und eine Fußzeile (Standard „Dieses Ticket ist nur
einmal gültig …“, je Ticketart/Pass über den Filter `wpcap_ticket_pdf_footer`). Der Teilnehmername aus dem Checkout
steht auf dem Ticket-PDF der Mail.

**Schrift und Zeichen:** Text wird als UTF-8 mit der mitgelieferten Schrift DejaVu Sans (normal und fett,
`assets/fonts/`, Bitstream-Vera-Lizenz) gesetzt; jedes PDF bettet nur die verwendeten Zeichen ein (Subset, typisch
15–35 KB), Text bleibt kopier- und durchsuchbar. Abgedeckt sind u. a. alle europäischen Lateinschriften (Polnisch,
Tschechisch, Türkisch, Rumänisch …), Griechisch, Kyrillisch, Armenisch, Georgisch, Hebräisch und Arabisch.
**Grenzen:** Chinesisch, Japanisch, Koreanisch, indische und südostasiatische Schriften enthält DejaVu nicht – sie
erscheinen als leeres Kästchen; dafür eine TrueType-Schrift mit diesen Zeichen über `wpcap_pdf_fonts` einbinden.
Rechts-nach-links-Schriften werden in logischer Reihenfolge und Arabisch ohne kontextabhängige Buchstabenformen
gesetzt (kein Bidi/Shaping). **Rückfall:** Fehlt die Schriftdatei oder ist sie unbrauchbar, nutzen die PDFs wie
früher Helvetica (WinAnsi); Zeichen außerhalb von Windows-1252 werden transkribiert, wo möglich („ł“ → „l“), sonst
als „?“ gedruckt. Ein Stapeldruck, der vor dem Update begonnen wurde, wird in seiner Kodierung (Helvetica) fertig
gerendert; neue Stapel merken sich die verwendeten Zeichen je Block in einer Datei `batch-….glyphs` neben dem PDF
(wird mit dem Fertigstellen gelöscht).

### Ticketdesign: Logo, Farben, Startvorlagen (A685)

Jede Druckvorlage (Druck & Listen → Vorlagen) hat unter **Gestaltung** drei Felder; sie gelten für den Stapeldruck
und – bei der Ticketvorlage – auch für die Ticket-PDFs in Mail, Bestellportal und Admin:

- **Logo** (Mediathek-ID eines JPEG- oder PNG-Bildes, höchstens 1 MB): auf dem Ticket oben links (bis 60 × 16 mm),
  auf dem Badge oben mittig, auf Namensschildern oben links klein und auf dem Einlassband rechts neben der
  Klebelasche. Das Bild wird je Seite eingebettet (JPEG unverändert, PNG ohne Transparenz mit seinen komprimierten
  Daten; PNG mit Transparenz oder Palette wird mit GD auf Weiß gerechnet). Ein Bild, das nicht gelesen werden kann,
  wird beim Speichern abgelehnt; fehlt es später (gelöscht), druckt die Vorlage ohne Logo. Eigene Quelle: Filter
  `wpcap_print_logo_file`.
- **Akzentfarbe** (fette Zeilen) und **Textfarbe** (übrige Zeilen) als `#RRGGBB`, leer = Schwarz. Beide brauchen auf
  Weiß einen Kontrast von mindestens **4,5:1** (WCAG 2.2, 1.4.3); hellere Farben lehnt das Speichern mit dem
  gemessenen Wert ab. Ebenso abgelehnt wird Schrift unter 6 pt. QR-Codes bleiben immer schwarz auf Weiß.
- Gespeichert wird die Gestaltung als Kopfzeilen der Vorlage (`@logo|123`, `@accent|#1f3a68`, `@text|#1a1a1a`); sie
  werden nie gedruckt, Vorlagen ohne diese Zeilen drucken unverändert.

**Barrierearme Startvorlagen** (Formular „Als neue Vorlage anlegen“): Ticket „Klar“ (Schwarz auf Weiß, ab 13 pt),
Ticket „Großdruck“ (alle Angaben ab 16 pt, Kernangaben fett), Ticket „Akzent“ (Dunkelblau, Kontrast über 10:1, Platz
für das Logo), Badge „Name groß“ (26 pt) und Namensschild „Name groß“ (20 pt). Alle Ticket-Startvorlagen drucken den
Hilfscode unter dem QR-Code. Die angelegte Vorlage lässt sich danach wie jede andere bearbeiten und einem Event
zuordnen.

### Teilnehmerfelder, Etikettenformate, Sortierung und Vorschau (A686)

- **Teilnehmerfeld-Mapping:** `{field:<Schlüssel>}` druckt die Antwort auf das Teilnehmerfeld mit diesem Schlüssel
  (z. B. `{field:firma}`, `{field:funktion}`); die Hilfe unter dem Vorlagentext listet die Felder der eigenen Events.
  Einwilligungen werden nie gedruckt. Gilt für Stapeldruck (auch im Hintergrund), Ticket-PDFs der Mail und Vorschau.
- **Weitere Formate** neben Ticket, Badge, Namensschild (10/A4) und Einlassband: Etiketten 70 × 37 mm (24 pro A4,
  z. B. Avery 3474), Etiketten 99,1 × 38,1 mm (14 pro A4, z. B. Avery L7163) – beide ohne Rahmen für vorgestanzte
  Bögen – und vier A6-Badges pro A4-Bogen mit Schnittrahmen. Beim Drucken „tatsächliche Größe“ (100 %) wählen.
  Hintergrund-Stapel rendern je Lauf ganze Bögen (Blockgröße auf ein Vielfaches der Etiketten je Seite gerundet).
- **Sortierung** des Stapeldrucks: Name der Teilnehmenden (Standard, wie bisher), Ticketart und dann Name,
  Bestellung oder Ticketnummer. Ein Hintergrund-Stapel hält die Reihenfolge beim Anlegen fest. Der Rückfall ohne
  Stapeltabellen (neue Codes in Teilen) bleibt bei der Ticketnummer, damit die Teile lückenlos aufeinander folgen.
- **Vorschau:** „Vorschau (PDF)“ in der Vorlagenliste bzw. „Vorschau (PDF, ohne Speichern)“ im Bearbeitungsformular
  zeigt das Layout mit Beispieldaten (Etikettenbögen: ein voller Bogen). Der Beispielcode ist kein Ticketcode; es
  werden keine Tickets gelesen und keine Codes erzeugt. Nur Eventverwaltung, für globale Vorlagen und die Vorlagen
  eigener Events.

## Stapeldruck mit neuen Ticketcodes (Hintergrundjob, A427)

Ticketcodes sind nur als Hash gespeichert; ein Stapeldruck mit QR-Code braucht deshalb neue Codes. Früher wurden
alle Codes eines Termins vor dem Rendern ersetzt und das PDF im Admin-Request im Speicher gebaut – ein Timeout
oder Speichermangel (ab etwa 2.500 Tickets) machte alle Tickets ungültig, ohne dass es ein PDF mit den neuen Codes
gab. Jetzt (`Printing\BatchJob`, Tabelle `wpcap_print_batches`):

1. **Anlegen** (Druck & Listen → Stapeldruck): Reihenfolge, Ticket-IDs und der aktuelle Code-Hash werden in einer
   privaten Datei festgehalten; an den Tickets ändert sich nichts. Höchstens 20.000 Tickets je Stapel
   (`wpcap_print_batch_max`).
2. **Rendern** im Job `print_batch` (WP-Cron `wpcap_process_jobs`, der erste Block noch im Request,
   `wpcap_print_batch_inline_budget`, 3 s): je Lauf `wpcap_print_batch_block` Tickets (200, Vielfaches von 10)
   mit frischen Codes; die Seiten werden an die Datei angehängt, der bestätigte Stand (Bytes, Seiten) steht in der
   Tabelle – ein abgebrochener Lauf wird ab dort wiederholt. Die neuen Codes stehen nur im PDF und in einer
   privaten Begleitdatei. Der letzte Lauf schreibt den PDF-Abschluss → Status „fertig“.
3. **Herunterladen**: Der erste Download aktiviert die neuen Codes in einer Transaktion – nur für Tickets, die noch
   gültig sind und denselben Code wie beim Anlegen haben (sonst „übersprungen“, der gedruckte Code gilt dann
   nicht; die Liste zeigt die Anzahl) – und liefert dann die Datei. Erst ab hier sind die alten Codes ungültig.
   Hilfscodes, Wallet-Pässe und Partner-Codes folgen per Job `print_batch_notify` (`wpcap_ticket_code_rotated`,
   Grund `print`); der gedruckte Hilfscode gilt, sobald dieser Job gelaufen ist (in der Regel Sekunden).
   Weitere Downloads liefern dieselbe Datei ohne Änderung.

Abbrechen (Knopf in der Liste), Fehler oder ein nie heruntergeladener Stapel ändern **kein** Ticket. Tickets, die
jemand anderes als der Käufer hält (übertragen oder einer anderen Teilnehmer-E-Mail zugeordnet), bekommen keinen
neuen Code: Sie werden ohne QR-Code gedruckt, ihr Inhaber behält den gültigen Code (A630). Stapel ohne neue Codes
bis 300 Tickets (`wpcap_print_batch_sync_limit`) werden wie bisher sofort geliefert, größere ebenfalls im
Hintergrund. Dateien liegen in `uploads/pass-captain-private/` (zufällige Namen, `.htaccess` mit „Require all
denied“; bei nginx eine eigene `deny`-Regel setzen oder per `wpcap_print_batch_dir` ein Verzeichnis außerhalb
des Webroots wählen; bei mehreren Webservern ein gemeinsames Verzeichnis) und werden 7 Tage nach der letzten
Änderung gelöscht (`wpcap_print_batch_keep_days`, stündlicher Cron); ein seit einem Tag hängender Stapel wird als
fehlgeschlagen beendet. Audit: `print_batch_created`, `print_batch_delivered`, `ticket_codes_rotated`,
`print_batch_cancelled`, `print_batch_failed`.

## Bestellungen und Tickets im Admin

### Tickets erneut senden (A335)

„Tickets erneut senden“ (Bestelldetail) bietet zwei Varianten (A588): **„Gespeicherte Codes erneut senden“**
(vorausgewählt) verschickt den aktuellen Code jedes Tickets noch einmal, sofern er verschlüsselt gespeichert ist
(`TicketCodes`, A586, oder Wallet-Modul) – nichts wird ungültig, das ist der Weg für „Mail nicht angekommen“;
Tickets ohne gespeicherten Code (vor Schema 3.10.17 ausgestellt, Speicherung abgeschaltet) erhalten dabei einen
neuen Code. **„Neue Codes ausstellen“** macht die bisherigen Codes und Ausdrucke dieser Tickets ungültig (z. B.
wenn ein Code weitergegeben wurde); ohne Angabe (`codes`) gilt diese Variante. Standard beim Umfang ist **„Nur nicht
übertragene Tickets“**: Tickets, die die Käufer:in an eine andere Person übertragen hat (`transfer_count > 0`),
behalten ihren Code – sonst würde der Neuversand den QR-Code der Empfänger:in entwerten. Nur mit der bewusst
gewählten Option **„Auch übertragene Tickets“** bekommen auch sie neue Codes; die Empfänger:innen erhalten
diese an ihre eigene Adresse. Sind alle gültigen Tickets übertragen, lehnt der Standard ab und verweist auf
diese Option bzw. auf **Tickets → Neu ausstellen** für einzelne Tickets. Die Meldung nennt, wie viele Tickets
neu gesendet wurden und wie viele ihren Code behalten. Scheitert die Mail, behält der automatische
Wiederholungsjob (`ticket_mail_retry`, Payload `{"order_id":…,"skip_transferred":true}`) diesen Umfang.
Bestellportal, WooCommerce-Bestellaktion und der Voucher-Captain-Neuversand nehmen übertragene Tickets
ebenfalls aus.

**Doppelklick (A533):** Das Formular trägt einen Formularschlüssel (`request_key`). Er wird unter der Zeilensperre
der Bestellung geprüft und als Audit-Eintrag `tickets_resend_request` vermerkt; ein zweites Absenden desselben
Formulars (Doppelklick, erneutes Senden der Seite) ändert nichts und meldet `wpcap_resend_duplicate` – die Codes
der ersten Mail bleiben gültig. Ein fehlgeschlagener Versuch verbraucht den Schlüssel nicht; ein neu geladenes
Formular sendet erneut.

**Zustellung und Wiederherstellung (A588):** Die Codes der ersten Ticketmail werden beim Versand verschlüsselt
gespeichert. Meldet `wp_mail()` einen Fehler, plant Pass Captain `ticket_mail_retry` (Audit
`ticket_mail_failed`); der Wiederholungsjob sendet **dieselben** gespeicherten Codes (kein neuer Code, auch nach
einem erneuten Versand, der nur einen Teil der Empfänger:innen erreicht hat). Die Anhänge der ersten Mail sind
auf `wpcap_ticket_mail_attachment_budget` begrenzt (A580); alle Tickets bleiben über die Links, das Bestellportal
(PDF ohne neuen Code) und „Ticket-PDF herunterladen“ im Admin erreichbar. Nicht erkannt werden Zustellfehler, die
erst nach `wp_mail()` auftreten (Bounces): Pass Captain hat keinen Rückkanal vom Mailserver. Meldet eine
Käufer:in, dass nichts ankam, „Gespeicherte Codes erneut senden“ verwenden oder das Ticket-PDF herunterladen.

### Bestellliste (A411)

Die Bestellliste ist eine WordPress-Listentabelle (`Admin\OrdersListTable`, Zellen, Filter und Aktionen in
`Admin\OrdersPage`):
- **Ansichten** über der Tabelle: Alle, Zahlung offen, Bezahlt, Zu klären (`needs_review`), Storniert – Event-Filter
  und Suche bleiben dabei erhalten.
- **Sortieren** nach Datum (Standard: neueste zuerst) und Summe, auf- und absteigend (`orderby=date|total`,
  `order=asc|desc`; andere Werte werden ignoriert).
- **Sammelaktion „Zahlung bestätigen“**: bestätigt die Überweisung aller markierten offenen Bestellungen mit
  Zahlart Überweisung (höchstens 100 je Aufruf) wie die Einzelaktion im Bestelldetail – mit derselben
  Rechte- und Zugriffsprüfung je Bestellung, Audit-Eintrag und Ticketversand. Andere Zahlarten, bereits bezahlte,
  stornierte oder abgelaufene Bestellungen werden übersprungen; die Hinweise nennen bestätigte, übersprungene und
  fehlgeschlagene Bestellungen.
- **Bildschirmoptionen**: „Bestellungen pro Seite“ (1–200, Standard 20, je Benutzer:in, `wpcap_orders_per_page`).
- **Leerzustand**: „Keine Bestellungen gefunden.“ mit „Filter zurücksetzen“, solange ein Filter aktiv ist (der Link
  steht auch neben dem Filterformular).

Ticket- und Eventliste folgen in einem weiteren Schritt (A411 offen für diese Listen).

### Bestellungen und Tickets finden (A336, A545)

Die Bestellliste zeigt die kurze Bestellnummer aus Mails, Bestellportal und Zahlungsreferenz („AB12-CD34“;
die volle ID steht im Tooltip und im Bestelldetail) und unter der E-Mail den Namen bzw. die Firma aus den
Rechnungsdaten. Die Suche findet Bestellungen über
- die Bestellnummer – auch klein, ohne Bindestrich, mit Leerzeichen oder als „Bestellung AB12-CD34“ –,
- einen Teil der Käufer-E-Mail,
- Vor-/Nachname oder Firma der Rechnungsdaten (jedes Wort muss vorkommen, Umlaute werden gefunden),
- Teilnehmername und Teilnehmer-E-Mail und
- eine Ticketnummer der Bestellung (mindestens 8 Zeichen).

Die Ticketsuche (**Tickets**) akzeptiert zusätzlich die kurze Bestellnummer, den **Hilfscode** unter dem
QR-Code (z. B. `K7QM-4XWA`, Groß-/Kleinschreibung und Bindestrich egal) sowie den vollständigen Ticketcode oder
Ticketlink. Hilfscode-Suchen zählen gegen dieselben Grenzen wie am Scanner (`wpcap_help_code_rate_limits`);
ist die Grenze erreicht, sagt die Seite das. Namen und E-Mails sind nur suchbar, wenn die Person sie laut
PII-Matrix im Klartext sehen darf (`wpcap_pii_can_view`), und alle Treffer bleiben auf den Zugriffsbereich der
Person beschränkt.

Die Ticketsuche sucht jedes Feld über seinen Index statt mit `LIKE '%…%'` über alle Tabellen (A441): Ticket- und
Bestellnummern sowie E-Mail-Adressen (Käufer, Ticket, Zuweisung) vom **Anfang** an (z. B. `anna@` oder die
vollständige Adresse; ein Domainteil wie `example.com` allein findet nichts mehr), Teilnehmernamen ab dem Anfang
des Namens oder eines Wortes (Vor- oder Nachname, Index `attendee_name` auf `wpcap_ticket_assignments`, Schema
3.10.26). Je Feld werden höchstens 1000 Treffer gelesen; darüber zeigt die Seite einen Hinweis, die Suche genauer
zu fassen.

### Bezahlt ohne Ticket: „Prüfung nötig“ (A337)

Kommt eine Zahlung erst nach Ablauf der Reservierung und ist kein Kontingent mehr frei (oder der Termin
abgesagt/vorbei), wird die Bestellung `needs_review` / `paid_unallocated` und es entstehen keine Tickets.
Solche Bestellungen sind im Admin sichtbar:
- Der Menüpunkt **Bestellungen** trägt einen Zähler (nur für Personen mit `wpcap_manage_orders`, gezählt im
  eigenen Zugriffsbereich wie die Bestellliste).
- Auf den Plugin-Seiten und im Dashboard erscheint ein Hinweis mit Link auf die gefilterte Liste
  (`admin.php?page=wpcap-orders&status=needs_review`); auf dieser Liste selbst nicht.
- Die Alarm-Mail an die Admin-Adresse enthält den direkten Link auf das Bestelldetail.

Im Bestelldetail stehen „Zuteilung erneut versuchen“ (nach Freigabe von Kontingent) bzw. die Erstattung.

### Einchecken im Admin und Web-Scanner (A338)

**Tickets → Einchecken** checkt ein gültiges Ticket ohne Scan ein (z. B. wenn der Code nicht lesbar ist und
kein Hilfscode zur Hand ist). Es gelten dieselben Regeln wie am Scanner (`Checkin::perform_ticket()`): Recht
`wpcap_checkin` mit Event-/Termin-Zuweisung und Zugriffsbereich, Terminabsage, Einlassfenster und -regeln,
Einlassbereich (Auswahl, wenn das Event Bereiche hat; zugewiesene Bereiche der Person gelten) und
Bereichskapazität. Das Formular trägt einen Idempotenzschlüssel; ein doppelter Klick checkt nicht zweimal
ein. Der Check-in steht im Protokoll mit Quelle `admin` und im Audit-Log mit `via: manual`.

Der Web-Scanner ist eine normale Seite mit dem Shortcode `[wpcap_scanner]` (Anmeldung mit
`wpcap_checkin` nötig, nie gecacht). **Einlass → Web-Scanner** verlinkt alle Seiten mit dem Shortcode
(„Web-Scanner öffnen“, auch als Knopf auf den Seiten Tickets und Check-ins). Gibt es keine, kann eine
Person mit `publish_pages` dort die Seite „Einlass-Scanner“ anlegen lassen (Audit `scanner_page_created`);
eigene Scanner-Seiten lassen sich über den Filter `wpcap_scanner_pages` ergänzen.

### Erstatten im Bestelldetail (A340)

Das Erstattungsformular zeigt vor dem Absenden, was passiert:
- **Erstattungsbetrag** live zur Auswahl (je Ticket aus derselben Berechnung wie die Erstattung selbst,
  `Refunds::preview()`; laufende oder wartende Erstattungen sind abgezogen) und bei „Alle offenen Tickets“
  der Restbetrag der Bestellung, der auch direkt an der Option steht.
- **Weg**: automatisch über den Zahlungsanbieter zurück auf das Zahlungsmittel, oder – bei Überweisung bzw.
  Anbietern ohne Erstattungsschnittstelle – „nicht automatisch“ mit dem hinterlegten Konto (maskierte IBAN),
  falls vorhanden.
- Ein Hinweis, wenn die Freigabegrenze eine zweite Person verlangt.

Die Bestätigung nennt Betrag, Ticketzahl und Weg. „Alle offenen Tickets“ hakt alle Tickets sichtbar an und
sperrt die Einzelauswahl; abgewählt kehrt die vorherige Auswahl zurück. Kommen trotzdem (z. B. ohne
JavaScript) „Alle“ und einzelne Tickets zusammen an, lehnt der Server ab (`wpcap_refund_ambiguous`), statt
still alles zu erstatten. Die Erfolgsmeldung nennt den Betrag.

## Kontingentzähler (große Vorverkäufe)

Seit Schema 3.7.0 führen Ticketarten und Termine `sold_qty` (bezahlte, nicht erstattete Einheiten) und
`held_qty` (Reservierungen mit Status `active`); jede Bestellzeile/Reservierung merkt sich in `counted_qty`,
was sie beiträgt. Die Kapazitätsprüfung liest die gesperrten Zähler statt alle Bestellzeilen zu summieren;
Reservierungen, die noch zählen, aber nicht mehr halten (abgelaufen, freigegeben oder umgewandelt und noch nicht
synchronisiert), zieht sie über den Index `counted_type (ticket_type_id, counted_qty)` mit `counted_qty>0` ab –
je Ticketart bzw. für die Ticketarten des Termins als Bereich, nie über die Reservierungshistorie (Schema 3.10.12,
A429; vorher Full Scan, bei 500.000 Reservierungen ≈ 300 ms je Prüfung unter den Kapazitätssperren, siehe
[TESTING.md](TESTING.md#historie-reservierungen-index-und-aufräumen-a429-a430-a444)).

- **Zustand** (Option `wpcap_capacity_counters`): Neuinstallation sofort `ready`. Beim Upgrade baut der
  Installer die Zähler je Termin unter Zeilensperren aus den Bestellungen neu auf (`building`) und entscheidet
  danach weiter mit den alten SUM-Abfragen (`verifying`), bis ein Abgleich frühestens 10 Minuten später
  (Filter `wpcap_capacity_verify_after`) keine Abweichung findet → `ready`. In dieser Zeit ist der Checkout
  langsam wie vorher; Upgrades nicht während eines Vorverkaufs einspielen. Filter
  `wpcap_capacity_counters_enabled` (`false`) erzwingt den SUM-Pfad.
- **Neuaufbau als fortsetzbarer Job (A468):** Der Neuaufbau läuft nicht in einem Durchgang über die ganze
  Historie im Upgrade-Request. Termine werden in ID-Reihenfolge hinter einem Cursor neu aufgebaut (Option
  `wpcap_capacity_rebuild`: Cursor, Anlass, Start – unabhängig von der Schema-Version); der Upgrade-Request
  arbeitet höchstens 5 s daran (Filter `wpcap_capacity_rebuild_budget`), den Rest erledigt der Abgleich-Cron
  `wpcap_reconcile_capacity` (Folgelauf sofort eingeplant, je Lauf das Cron-Zeitbudget, dieselbe Sperre wie der
  Abgleich). Bricht ein Lauf ab, setzt der nächste beim gespeicherten Cursor fort; auch ein früher
  abgebrochenes Upgrade, das in `building` stehen geblieben ist, wird so fertig. Während `building` pflegen alle
  Schreibvorgänge die Zähler weiter, Entscheidungen laufen über die SUM-Abfragen. Audit-Einträge
  `capacity_counters_rebuild_started` (Anlass `upgrade`/`rollback`) und `capacity_counters_rebuilt` (Dauer).
  `wp pass-captain reconcile-capacity --all` erledigt einen laufenden Neuaufbau in einem Durchgang.
- **Nach einem Code-Rollback (A464):** Ältere Versionen (bis 1.4.0 kennen die Zähler gar nicht) verkaufen,
  erstatten und lassen Reservierungen ablaufen, ohne die Zähler zu pflegen. Der Abgleich-Cron sieht nur die
  letzten zwei Tage und hätte ältere Verkäufe nie gefunden; die Zähler hätten nach dem erneuten Update weiter
  „ready“ gemeldet und zu wenig Verkäufe gezählt (Überverkauf). Erkennt das Upgrade, dass seit der höchsten je
  gelaufenen Schemaversion ältere Plugin-Versionen liefen (`wpcap_db_version` < `wpcap_db_version_max`,
  `Installer::rolled_back()`), baut es die Zähler deshalb immer neu auf und entscheidet bis zum sauberen Abgleich
  wieder mit den SUM-Abfragen.
- **Abgleich**: Cron `wpcap_reconcile_capacity` alle fünf Minuten (räumt zuerst abgelaufene Reservierungen
  auf) oder `wp pass-captain reconcile-capacity`. Er synchronisiert Zeilen, deren `counted_qty` nicht zum
  Bestellstatus passt (ein Writer hat nicht synchronisiert), und repariert Zähler, die nicht der Summe ihrer
  Zeilen entsprechen. Jeder Fund: Audit-Eintrag `capacity_counter_drift`, Eintrag im PHP-Error-Log, Action
  `wpcap_capacity_drift` (Bericht) und höchstens stündlich eine Mail an die Admin-Adresse (Filter
  `wpcap_capacity_drift_alert`). Ein Drift-Fund bedeutet: irgendwo ändert Code Bestellungen, Zeilen oder
  Reservierungen direkt in der Datenbank.
  Der Cron-Lauf läuft nie doppelt (`GET_LOCK`), hat ein Zeitbudget (10 s, `wpcap_capacity_reconcile_budget`)
  und prüft nur in den letzten zwei Tagen geänderte Bestellungen (Index `updated` auf `wpcap_orders`), aktive
  Reservierungen (Index `expiry`), Reservierungen laufender/künftiger Termine mit `counted_qty>0` (Index
  `counted_type`) und die Zähler laufender/künftiger Termine (je 500 Ticketarten eine gruppierte Summe über
  `capacity_sum` bzw. `counted_type`, Vergleich in PHP; keine korrelierten Unterabfragen, A430). Jede Abfrage
  ist ein Indexbereich; die Laufzeit hängt nicht von der Historie ab; eine
  scheinbare Abweichung wird nach 0,3 s erneut geprüft (Fenster zwischen COMMIT und Zählersync), erst dann
  gilt sie als Drift. `wp pass-captain reconcile-capacity --all` prüft den gesamten Bestand ohne Zeitlimit.
- **Eigene Writer** (Plugins, Skripte): nach Änderungen an `wpcap_orders` (Zahl-/Status), `wpcap_order_items`
  oder `wpcap_reservations` `\PassCaptain\CapacityCounters::sync_order_now( $order_id )` aufrufen; Ticketarten nur
  mit `CapacityCounters::move_type()` auf einen anderen Termin verschieben. Wer Kontingent *hinzufügt* (bezahlt
  setzen, Reservierung anlegen), muss innerhalb derselben Transaktion `CapacityCounters::sync_order()` aufrufen,
  nachdem Bestellung, dann Ticketarten, dann Termine (jeweils nach ID) gesperrt sind.
- **Prüfen**: `CapacityCounters::drift()` liefert Zähler, die von den SUM-Abfragen abweichen (leer = in Ordnung).
  `CapacityCounters::ticket_drift()` prüft zusätzlich die Bestellzeilen gegen die Tickets: jedes gültige oder
  eingecheckte, nicht erstattete Ticket einer bezahlten Bestellung braucht eine gezählte Einheit
  (`quantity − refunded_quantity`) seiner Bestellung und Ticketart. Weniger Einheiten als Tickets heißt: ein
  Platz wurde doppelt freigegeben, die Ticketart kann überverkauft werden (Doctor-Prüfung `capacity_ticket_drift`).
- **Freigabe je Ticket höchstens einmal** (Schema 3.10.10, A537/A538/A579): `wpcap_tickets.capacity_released`
  hält fest, dass ein Ticket seinen Platz zurückgegeben hat. Alle Abläufe nutzen dieselbe Regel
  (`PassCaptain\CapacityRelease`): Erstattung (nativ, extern, WooCommerce, Partner) und Widerruf mit
  Platzfreigabe (Admin, `Orders::revoke_tickets()`) zählen eine Einheit als erstattet und markieren das Ticket in
  einer Anweisung; ein bereits markiertes Ticket gibt nie eine zweite Einheit frei. Der Umtausch nimmt die
  Einheit aus der alten Position und markiert das alte Ticket; Storno und vollständige Erstattung der Bestellung
  markieren alle Tickets. Eine Absage des Termins widerruft die Tickets, ohne den Platz freizugeben – sie bleiben
  erstattbar, die Erstattung gibt den Platz dann frei. Teilfreigaben melden `wpcap_capacity_released` mit
  `ticket_type_id` und `quantity` (Warteliste, Kontingente). Beim Upgrade werden bereits erstattete Tickets
  einmalig markiert.
- **Erstattung, Umtausch, Widerruf, Übertragung**: Solange für ein Ticket eine Erstattung wartet, freigegeben ist
  oder läuft, werden Umtausch, Widerruf und Übertragung abgelehnt (`refund_open` bzw.
  `wpcap_ticket_refund_open`). Bei der Ausführung muss jedes Ticket der Erstattung noch gültig oder eingecheckt
  sein oder wegen einer Terminabsage widerrufen (`Orders::refundable_tickets()`); sonst schlägt sie ohne
  Auszahlung fehl (`wpcap_refund_conflict`). Ob eine Erstattung „vollständig“ ist, entscheidet der Ticketwert:
  Tickets, die widerrufen, aber nicht erstattet wurden, behalten ihren Anteil; die Bestellung gilt als erstattet,
  sobald kein gültiges, eingechecktes oder noch erstattbares Ticket übrig ist (widerrufene und umgetauschte
  Tickets halten sie nicht offen).
- **Stornierungsanfrage und Personenwechsel** (A496): Auch der Personenwechsel über die Teilnehmerzuweisung im
  Bestellportal (neuer Code) wird bei offener Erstattung abgelehnt (`wpcap_ticket_refund_open`); Namen und Angaben
  derselben Person bleiben korrigierbar. Eine Stornierungsanfrage aus dem Bestellportal (`source = portal`) für ein
  inzwischen übertragenes Ticket wird unter der Bestellsperre abgelehnt (`wpcap_refund_ticket_transferred`), und
  ihre Ausführung scheitert ohne Auszahlung, falls ein Ticket doch übertragen wurde (`wpcap_refund_conflict`) – die
  neue Inhaberin behält das Ticket, die Käuferin bekommt kein Geld dafür. Eine vom Personal angelegte Erstattung
  eines übertragenen Tickets bleibt möglich (bewusste Entscheidung, z. B. Kulanz).
- **Verfügbarkeitsanzeige**: `Orders::availability()` ist pro Request gemerkt (jeder Schreibzugriff auf eine
  Plugin-Tabelle verwirft das) und liegt 10 s im Objekt-Cache (Filter `wpcap_availability_cache_ttl`, 0–60,
  0 = aus); Requests, die selbst geschrieben haben, lesen den Objekt-Cache nicht. Kaufentscheidungen nutzen nie
  den Cache. Event-Liste und REST-`/events` laden alle Termine mit konstanter Abfragezahl
  (`Orders::availability_many()`, `PublicEvents::listing_data()`).

### Erstattung nach Einlass (A131)

Tickets, die bereits zum Einlass genutzt wurden (Status „eingecheckt“, ein nicht zurückgenommener Check-in oder ein Eintritt im Einlassmodul), werden in der Bestellansicht nur erstattet, wenn eine Person mit `wpcap_approve_refunds` im Feld „Ausnahme für bereits eingelassene Tickets“ eine Begründung angibt. Die Erstattung landet dann immer in „Erstattungen zur Freigabe“ (Vier-Augen-Prinzip), Grund und Ausnahme stehen im Erstattungsgrund und im Audit-Log. Automatische Erstattungen bei Terminabsage sowie Kassen-Stornos (mit eigener Ausnahme an der Kasse) sind davon nicht betroffen; das Bestellportal bietet eingelassene Tickets gar nicht zur Stornierung an.

### Ticketart nach Verkaufsstart ändern (A334)

Hat eine Ticketart bereits Bestellungen (bezahlt oder offen), zeigt ihr Formular „Bereits verkauft: N Tickets
verkauft“ und den Hinweis, dass Änderungen an Preis, Steuersatz oder Steuerklasse nur für neue Bestellungen
gelten – bestehende Bestellungen, Tickets, Rechnungen und Erstattungen behalten Preis und Steuer ihrer Bestellung
(Preis-Snapshot). Eine solche Änderung wird nur mit dem Häkchen „Preis-/Steueränderung trotz verkaufter Tickets
übernehmen“ (`confirm_sold_change`) gespeichert, sonst abgelehnt (`wpcap_type_sold_change`, 409; das Formular
zeigt die Eingaben wieder an). Nach dem Speichern wiederholt eine Warnung, was die Änderung nicht betrifft; die
Änderung steht wie bisher im Termin-Verlauf (A114). Name, Kontingent, Verkaufsfenster und Status lassen sich
ohne Bestätigung ändern; die Währung bleibt nach der ersten Bestellung gesperrt.

### Gleichzeitiges Bearbeiten einer Ticketart (A531)

Das Formular trägt eine Revision der bearbeitbaren Felder (Name, Preis, Währung, Steuer, Kontingent, Höchstmenge,
Verkaufszeitraum, Status, Einlasskontrolle; nicht die Verkaufszähler). Hat jemand die Ticketart gespeichert, nachdem
das Formular geöffnet wurde, lehnt das Speichern mit `wpcap_type_changed` ab, statt die andere Änderung zu
überschreiben; das Formular zeigt die eigenen Eingaben wieder, ein erneutes Speichern übernimmt sie dann bewusst.
Prüfungen (Währungssperre, Kontingent ≥ verkauft, Bestätigung nach Verkaufsstart) und Speichern laufen unter der
Zeilensperre der Ticketart, die auch jeder Verkauf nimmt – ein gleichzeitiger Kauf kann das Kontingent zwischen
Prüfung und Speichern nicht mehr unter die verkaufte Menge drücken. Hält ein Verkauf die Sperre zu lange, meldet
das Speichern `wpcap_type_busy`. Aufrufe ohne Revision (Übernahme auf weitere Termine, Integrationen) werden nur
unter der Sperre geprüft.

### Ticketaktionen melden nur geprüfte Schreibvorgänge (A532)

„Widerrufen“, „Check-in zurücknehmen“ und „Teilnehmerdaten korrigieren“ (Ticketliste, Web-Scanner, App) prüfen jeden
Schreibschritt und das COMMIT. Schlägt einer fehl (Sperr-Timeout, Verbindungsabbruch), wird zurückgerollt und ein
Fehler gemeldet (`wpcap_db_busy` bzw. `wpcap_correction_failed`); Erfolgsmeldung, Audit-Eintrag und Folgeaktionen
(Wallet, Webhooks, Wartelisten) gibt es nur nach einem bestätigten COMMIT.

### Ticketarten und Teilnehmerfelder auf alle Termine anwenden; Serien (A333)

Hat das Event weitere kommende Termine, bietet das Ticketart-Formular „Auch für die gleichnamige Ticketart an N
weiteren kommenden Terminen übernehmen“ (`apply_all`): Name, Preis, Währung, Steuer, Höchstmenge, Status und
Einlasskontrolle gehen an die Ticketart gleichen (bisherigen) Namens aller kommenden, nicht abgesagten Termine;
Kontingent und Verkaufszeitraum bleiben je Termin. Eine neue Ticketart wird so an jedem kommenden Termin ohne
gleichnamige Ticketart angelegt, ihr Verkaufszeitraum mit dem Termin verschoben. Jeder Termin durchläuft dieselben
Prüfungen wie ein einzelnes Speichern (Währungssperre, Kontingent ≥ verkauft, Bestätigung nach Verkaufsstart A334);
scheitert eine, wird der Termin übersprungen und in der Warnung genannt („Nicht übernommen für …“). Teilnehmerfelder
haben dieselbe Option; die Felder der anderen Termine werden über ihren Schlüssel gefunden (sonst angelegt).
Vergangene und abgesagte Termine bleiben unverändert.

Wiederkehrende Termine: Das Formular „Event anlegen“ behält die einfache wöchentliche Wiederholung (bis 12
Termine). Ist das Modul „Serientermine“ aktiv, weisen das Formular und die Eventseite („Serientermine anlegen
oder bearbeiten“) darauf hin – für andere Rhythmen, Ausnahmen, mehr Termine, Vorlagen mit Teilnehmerfeldern und
Einlassregeln (A332) und spätere Änderungen der ganzen Serie.

### Termin- und Eventabsage (A266, A267, A356)

Termine und ganze Events werden nur über eine **Bestätigungsseite** abgesagt: „Absagen …“ beim Termin (`view=cancel_occurrence`) bzw. „Event absagen …“ in der Eventliste und auf der Eventseite (`view=cancel_event`). Die Seite nennt die Folgen (Termine, zu widerrufende Tickets, zu stornierende offene Bestellungen, automatische oder offene Erstattungen, WooCommerce-Bestellungen, Empfänger der Absage-Mail), verlangt einen **Grund** (Audit-Log) und eine **Bestätigung per Checkbox**; ohne beides lehnt der Server ab (`wpcap_cancel_reason`, `wpcap_cancel_confirm`).

Das Bearbeitungsformular eines Termins hat kein Statusfeld mehr. Ein Status „Abgesagt“ über `save_occurrence` wird abgelehnt (`wpcap_occurrence_cancel_flow`). Ein abgesagter Termin kann **nicht wieder auf „Geplant“** gesetzt werden (`wpcap_occurrence_reactivate`, HTTP 409): seine Tickets sind widerrufen bzw. erstattet und die Inhaber informiert. Stattdessen einen neuen Termin anlegen. Ort, Zeiten usw. eines abgesagten Termins lassen sich weiter korrigieren; der Status bleibt „Abgesagt“.

### Absagen und Verschiebungen als wiederaufnehmbare Läufe (A428)

Termin- und Eventabsagen sowie Verschiebungs-/Änderungsmitteilungen laufen nicht mehr in einer einzigen Admin-Anfrage, sondern als **Lauf** (`BulkRuns`, Tabelle `wpcap_bulk_runs`, Schema 3.10.13) in Schritten zu je `wpcap_bulk_run_batch_size` Zeilen:

- **Terminabsage** (`occurrence_cancel`): Status „Abgesagt“ sofort (eine Anweisung) → offene Bestellungen stornieren → je bezahlter nativer Bestellung einen Erstattungsjob `cancellation_refund` einplanen (Ticket-IDs vor dem Widerruf festgehalten) → gültige Tickets stapelweise widerrufen, die Absage-Mail ihrer Inhaber wird *vor* dem Widerruf in die Nachrichtenwarteschlange gelegt → Modulschritt `wpcap_occurrence_cancellation_step` (Pässe) → fällige Erstattungsjobs ausführen → Mails versenden.
- **Eventabsage** (`event_cancel`): Event und Termine sofort „Abgesagt“ → Event-Mail je Käufer:in bezahlter/offener Bestellungen einreihen (bevor sich Bestellungen ändern) → je Termin `wpcap_occurrence_cancelled` (jeweils ein eigener Terminlauf ohne Termin-Mail) → Terminläufe abschließen → Sicherheitsnetz (offene Bestellungen, gültige Tickets) → `wpcap_capacity_released` → Mails.
- **Verschiebung/Änderung** (`notify`): alle Inhaber gültiger Tickets einreihen → versenden.

Jede Anfrage arbeitet so lange, wie das Zeitbudget (`Jobs\TimeBudget`, Standard 20 s) reicht; der Rest läuft über den Job `bulk_run` im Hintergrund weiter. Bricht eine Anfrage ab (Zeitlimit, Fehler), bleiben bereits widerrufene Tickets widerrufen, eingereihte Mails werden vom Nachrichten-Worker zugestellt, und ein **zweiter Klick** auf „Termin endgültig absagen“ bzw. „Event absagen“ (oder „Jetzt fortsetzen“ in der Fortschrittsanzeige) setzt den Lauf sofort fort, statt nichts zu tun. Erstattungen sind über ihren Schlüssel idempotent, Mails über einen Dedupe-Schlüssel je Lauf und Empfänger: niemand erhält eine Mail doppelt, keine Bestellung wird doppelt erstattet. Es arbeitet immer nur eine Anfrage an einem Lauf (Sperre `locked_until`, wird bei Abbruch freigegeben bzw. läuft nach Budget + 2 Minuten ab).

Die **Fortschrittsanzeige** auf der Event- und der Terminseite („Absagen und Benachrichtigungen“) zeigt je Lauf den aktuellen Schritt, stornierte Bestellungen, widerrufene Tickets, ausgeführte/wiederholte/fehlgeschlagene Erstattungen und versendete/fehlgeschlagene Mails. Abgeschlossene Läufe stehen im Audit-Log (`occurrence_cancellation_processed`, `event_cancellation_processed`, `occurrence_notification_processed`) und lösen `wpcap_bulk_run_completed` aus. Vor dem Schema-Upgrade läuft eine Absage wie bisher vollständig in der Anfrage.

### Automatische Erstattung bei Absagen (A354, A436)

Ist „Bei Absage automatisch erstatten“ (`auto_refund_cancelled`) aktiv, erstattet eine Termin- oder Eventabsage bezahlte native Bestellungen als **Systemerstattung**: unabhängig von Rechten und Zwei-Faktor-Anmeldung der Person, die absagt (bzw. des Cron-Workers), und einschließlich bereits eingelassener Tickets – die Käufer:innen haben für einen Termin bezahlt, der nicht stattfindet. Die Freigabeschwelle (`refund_approval_threshold`) gilt weiter; solche Erstattungen warten in „Erstattungen zur Freigabe“. Dasselbe gilt für Pässe mit `on_cancel = revoke` und die Teilerstattung pro Tag.

Scheitert eine solche Erstattung (z. B. Anbieter nicht erreichbar, eine andere Erstattung derselben Tickets wartet noch auf Freigabe), wird sie als Hintergrundjob `cancellation_refund` erneut versucht – mit dem Backoff der Jobs und demselben Idempotenzschlüssel, also nie doppelt. Nach `Jobs::MAX_ATTEMPTS` Läufen steht der Job als Dead Letter in der Betriebsübersicht. Ist nichts mehr zu erstatten (Bestellung inzwischen erstattet/storniert, Tickets erstattet), endet der Job ohne Fehler; Audit `cancellation_refund_retry_scheduled` bzw. `cancellation_refund_retried`.

Die Ticket-IDs einer Pass-Absage werden je Bestellung einzeln gelesen (keine `GROUP_CONCAT`-Liste, die bei vielen Tickets an `group_concat_max_len` abgeschnitten würde).

## Blöcke „Pass Captain Events“ und „Pass Captain Kalender“

Die Blöcke heißen seit 1.5.0 `pass-captain/events` und `pass-captain/calendar` (A303). Die früheren Namen `wp-pass-captain/events` und `wp-pass-captain/calendar` stehen in bereits gespeicherten Beiträgen; sie bleiben als ausgeblendete Aliase registriert, werden auf der Website unverändert ausgegeben und beim Öffnen des Beitrags im Block-Editor automatisch auf den neuen Namen umgestellt (gespeichert wird er mit dem nächsten Speichern; manuell auch über „Umwandeln in“). Shortcodes `[wpcap_events]` und `[wpcap_calendar]` liefern dieselbe Ausgabe; die früheren `[wp_pass_captain_events]`/`[wp_pass_captain_calendar]` funktionieren weiter und werden bei aktivem `WP_DEBUG` als veraltet gemeldet.

- **Block-Unterstützungen (A449):** Beide Blöcke unterstützen eigene CSS-Klassen, die Ausrichtung „weit“/„volle Breite“ und Außen-/Innenabstand. Auf der Website trägt das äußere Element die Block-Wrapper-Attribute (`wp-block-pass-captain-events` bzw. `wp-block-pass-captain-calendar`, bei noch nicht umgestellten Beiträgen `wp-block-wp-pass-captain-…`, eigene Klasse, `alignwide`/`alignfull`, Abstände) zusätzlich zu `wpcap-events` bzw. `wpcap-calendar wpcap-calendar-{month|list}`; Blockstile aus `theme.json` (`styles.blocks["pass-captain/events"]`) greifen damit auch auf der Website. Die Shortcodes geben weiterhin nur die eigenen Klassen aus.

- **Monatsnavigation (A452):** Die Links „Vorheriger/Nächster Monat“ bauen auf der aufgerufenen URL auf (nicht auf dem Permalink des globalen Beitrags, der in Widgets, Template-Teilen, Archiven und Query-Loops falsch ist) und ersetzen nur den eigenen Parameter; alle anderen Parameter bleiben erhalten. Jeder Monatskalender einer Seite hat einen eigenen Parameter in Ausgabereihenfolge: `wpcap_month` (erster, wie bisher), `wpcap_month_2`, `wpcap_month_3` … – zwei Kalender blättern unabhängig. Die Links springen zur Navigation des jeweiligen Kalenders (`#wpcap-cal-nav-{n}`). Pfade, die mit `//` oder `/\` beginnen, werden auf einen lokalen Pfad reduziert. In der REST-Vorschau des Editors dient der Permalink des bearbeiteten Beitrags als Basis.

- **Eine Karte je Event, Terminauswahl (A342):** Shortcode und Events-Block zeigen je Event **eine** Karte mit **einem** Bestellformular statt eines vollständigen Formulars je Termin. Die Karte zeigt den mit `?wpcap_occurrence={Termin-ID}` angefragten Termin, sonst den ersten buchbaren (sonst den ersten mit „Vorverkauf ab …“); „Weitere Termine“ verlinken auf dieselbe Seite mit `?wpcap_occurrence=` und dem Anker `#wpcap-occ-{id}` (funktioniert ohne JavaScript). Ein angefragter Termin wird auch jenseits von `limit` geladen. Damit ältere Links (`#wpcap-occ-{id}` ohne Parameter, z. B. aus importierten .ics-Dateien) nicht ins Leere zeigen, trägt die Karte leere Anker (`span.wpcap-occ-anchor`) für die übrigen gelisteten Termine. Kalender (Liste/Monat) und .ics-`URL` verlinken mit `?wpcap_occurrence=`; ohne eingetragene Ticketseite auf die aufgerufene Seite. Die bisherige Darstellung (eine Karte je Termin) gibt es mit `dates="each"` bzw. im Block mit „Jeden Termin als eigene Karte“ (`perDate`); bei `occurrence="…"` ändert sich nichts. Eigene Theme-Vorlagen sollten für die Terminlinks `$card['dates'][…]['url']` statt `#wpcap-occ-{id}` verwenden.

- **Eindeutige Anker über mehrere Listen (A504, erweitert A308):** Erscheint ein Termin in mehreren Listen einer Seite (Shortcode plus Seitenleisten-Widget, Archiv, Karte je Event und Karte je Termin), vergibt eine gemeinsame Liste je Seite (`Frontend\EventCard::claim_anchor()`) den Anker `#wpcap-occ-{id}` genau einmal – ob als Kartenanker (Kalender-Modul), als leerer Terminanker einer Eventkarte oder an einer Karte „Vorverkauf ab …“ (die dafür `data-occurrence` trägt). Ein zweiter Platzwähler desselben Termins heißt `wpcap-seats-{id}-i2`. Rendert ein Theme oder SEO-Plugin den Inhalt vorab ein weiteres Mal und verwirft ihn, fehlt der Anker in der Ausgabe; dann setzt `checkout.js` ihn an die erste Karte des Termins ohne ID und scrollt dorthin, wenn die URL ihn verlangt.

- **Zeitzonen und Navigation im Monatskalender (A313):** Jeder Termin steht am Tag und mit der Uhrzeit seiner eigenen Zeitzone – derselben, die Eventkarte, Ticket, Mail und .ics zeigen (ein 23:30-Termin in New York steht am 10., nicht am 11. wie in der Site-Zeitzone Wien). Weicht der UTC-Versatz der Terminzone zu diesem Zeitpunkt von dem der Site ab, steht das Zonenkürzel hinter der Uhrzeit („23:30 EST“). Monat, „heute“ (`aria-current="date"`, Klasse `wpcap-cal-today`) und Navigation richten sich nach der Site-Zeitzone. Da nur kommende Termine erscheinen, fehlt „Vorheriger Monat“ im aktuellen (und in früheren) Monaten; jeder andere Monat hat den Link „Aktueller Monat“ (`.wpcap-cal-current`). In der schmalen Ansicht (bis 600 px) ersetzt seit A394 eine Liste die Tabelle (`.wpcap-cal-agenda`): Monat als Titel, je Tag mit Terminen ein Listeneintrag mit Wochentag, Tag und Monat („Montag, 16. November“) und den Terminlinks; die Tabelle bleibt für breite Ansichten.

- **Bestell- und Wartelistenformular ohne JavaScript (A450):** Beide Formulare der Eventkarte (`templates/event-card.php`) sind `method="post" action="#"`, ihr Absende-Button ist `disabled data-wpcap-requires-js` und wird erst von `assets/checkout.js` freigegeben, nachdem die Absende-Handler hängen. Ohne JavaScript (oder bevor das Skript geladen ist) wird nichts gesendet – E-Mail-Adresse und Teilnehmerdaten landen nie per GET in der URL, in Server-Logs oder im Referrer; ein `<noscript>`-Hinweis erklärt das. Eigene Kopien des Templates im Theme (`<theme>/pass-captain/event-card.php`) sollten Formular-Attribute und Button entsprechend übernehmen.

- **Vorschau im Editor (A451):** Die serverseitig gerenderte Vorschau (ServerSideRender) der Blöcke Events, Kalender und Terminbuchung liegt in `wp.components.Disabled`: Bestellformular, Monatsnavigation und Links lassen sich im Editor weder bedienen noch fokussieren (kein versehentliches Bestellen oder Wegnavigieren aus dem Editor); die Einstellungen in der Seitenleiste bleiben bedienbar.

- **Event- und Terminauswahl im Events-Block (A453):** Die Auswahl listet je veröffentlichtem Event mit kommenden Terminen einen Eintrag (bis 1.000 Events, nach nächstem Termin sortiert) und je Event die nächsten 100 Termine (`PublicEvents::BLOCK_CHOICE_EVENTS`/`BLOCK_CHOICE_DATES`); ein Event mit sehr vielen Terminen verdrängt keine anderen Events mehr. Bei gekürzter Terminliste nennt die Seitenleiste „Angezeigt werden die nächsten X von Y Terminen“. Ist das gespeicherte Event nicht mehr in der Liste (Entwurf, keine kommenden Termine), zeigt die Auswahl „Event #X (nicht mehr verfügbar)“ statt still „Alle Events“; ebenso „Termin #X (nicht mehr verfügbar)“ bzw. „Termin #X“ (wenn die Terminliste gekürzt ist). Die Website zeigt für ein solches Event weiterhin „Aktuell sind keine Veranstaltungen verfügbar.“

- **Event-Auswahl und Anzahl im Kalender-Block (A502):** Statt eines Zahlenfelds „Nur Event-ID“ wählt der Kalender-Block das Event aus einer Liste (veröffentlichte Events mit kommenden Terminen, Beschriftung wie im Events-Block mit nächstem Termin und ID, Inline-Daten `WPCAP_BLOCK_CALENDAR`); ein gespeichertes Event, das nicht mehr in der Liste steht, erscheint als „Event #X (nicht mehr verfügbar)“. In der Listenansicht gibt es das Attribut `limit` (1–200, Standard 50) wie beim Shortcode.

- **Suchbegriffe, Vorschau und Umwandlung aus Shortcodes (A501):** Die drei Blöcke haben Suchbegriffe (`keywords`, z. B. „Tickets“, „Kalender“, „Termin“) und ein `example` für die Vorschau im Block-Inserter. Ein Shortcode-Block (oder eingefügter Shortcode) `[wpcap_events]`/`[wp_pass_captain_events]`, `[wpcap_calendar]`/`[wp_pass_captain_calendar]` bzw. `[wpcap_service_booking]` lässt sich in den passenden Block umwandeln („Umwandeln in …“); `event`, `occurrence`, `dates="each"`, `view`, `limit`, `resource` werden übernommen. Ein Events-Shortcode mit eigenem `limit` (der Block zeigt immer bis zu 50 Termine) wird nicht angeboten, damit nichts verloren geht.

- **Cache-Busting der Editor-Skripte (A454):** Die Version der Editor-Skripte ist der Inhalts-Hash der Datei; nach einem Plugin-Update lädt der Editor das neue Skript statt einer zwischengespeicherten alten Fassung (siehe [RELEASE.md](RELEASE.md)).

- **RSS-Feed und REST-Ausgabe (A499):** Im Feed (`is_feed()`) und in REST-Antworten (`content.rendered` von `/wp/v2/posts`, Headless-Frontends, Apps) geben Events-Shortcode/-Block kein Bestellformular mehr aus, sondern eine schlanke Liste `ul.wpcap-events-list`: je Karte Eventname als Link auf die Karte im Beitrag (`?wpcap_occurrence={id}#wpcap-occ-{id}` am Permalink), Termin, Ort und ggf. „Vorverkauf ab …“ – ohne Skript, ohne IDs. Der Kalender zeigt dort die Listenansicht (keine Tabelle, keine Monatsnavigation), Links ebenfalls zum Permalink. Die Blockvorschau im Editor (REST-Block-Renderer) bleibt vollständig. Filter `wpcap_slim_output` (`bool $slim, string $what` = `events`|`calendar`) schaltet das um.

- **Ungültige Shortcode-Attribute (A503):** `event`/`occurrence` müssen Zahlen sein, `limit` eine positive Zahl, `dates` leer oder `each`, beim Kalender `view` `month` oder `list`. Ein ungültiger Wert fällt nicht mehr still auf „alle Events“ zurück: `event="sommerfest"` zeigt keine Termine („Aktuell sind keine Veranstaltungen verfügbar.“), ein ungültiges `limit`/`dates`/`view` den Standard. Wer Beiträge bearbeiten darf (`edit_posts`), sieht über der Ausgabe einen Hinweis (`p.wpcap-shortcode-notice`) mit Shortcode, Attribut und Wert – auch für eine Event-/Termin-ID, die es nicht (mehr) veröffentlicht gibt; Besucher, Feeds und REST-Ausgaben sehen ihn nie. Der Attribut-Filter läuft unter dem verwendeten Namen (`shortcode_atts_wpcap_events`, `shortcode_atts_wpcap_calendar`) und zusätzlich wie bisher unter dem langen Namen (`shortcode_atts_wp_pass_captain_events` bzw. `…_calendar`).

- **Kostenlose Tickets (A409):** Ticketarten mit Preis 0 heißen auf der Karte „kostenlos“ statt „0,00 €“ (Ticketart, Preiszeile im Kopf, Live-Stand; `Frontend\Format::price()`). Bietet die Karte nur kostenlose Ticketarten an – bzw. sind alle gewählten Ticketarten kostenlos –, heißt der Knopf „Kostenlos anmelden“, sonst „Zahlungspflichtig bestellen“ (Button-Lösung, § 312j BGB). Beide Texte stehen als `data-label-paid`/`data-label-free` am Knopf; `checkout.js` schaltet bei jeder Mengenänderung und bei neuen Live-Preisen um. Eigene Theme-Vorlagen übernehmen die Attribute, sonst bleibt der Text der Vorlage.

- **Stylesheets im `<head>` und im Editor (A391):** `wpcap-public` (`assets/public.css`) und `wpcap-calendar` (`assets/calendar/calendar.css`) werden auf `init` (Priorität 5) registriert und stehen in `block.json` als `style` (Events- bzw. Kalender-Block; Terminbuchung als `editorStyle`). WordPress lädt sie dadurch im `<head>` von Seiten mit dem Block und im Editor-Canvas – die Vorschau sieht aus wie auf der Website. Enthält der Inhalt eines einzelnen Beitrags/einer Seite einen Plugin-Shortcode (`[wpcap_events]`, `[wpcap_calendar]`, `[wpcap_passes]`, `[wpcap_scanner]` und die `wp_pass_captain_*`-Namen), wird das Stylesheet auf `wp_enqueue_scripts` eingebunden (`PublicEvents::enqueue_shortcode_styles()`); Shortcodes in Widgets, Template-Teilen oder Archiven binden es wie bisher beim Rendern ein.

## Servicebuchungen (Ressourcen, Zeitfenster, Leistungserbringer)

Service-Zeitfenster (Bootsschule, Werkstatt, Beratung) werden unter **Events → Service-Zeitfenster** je Event verwaltet; Kund:innen buchen sie auf einer Seite mit dem Shortcode `[wpcap_service_booking]` bzw. dem Block „Pass Captain Terminbuchung“ (siehe unten) oder direkt über die REST-API (`POST /service-slots/{slot_id}/bookings`).

### Buchungsseite für Kund:innen (A268)

**Einrichten**: Shortcode `[wpcap_service_booking event="12"]` (optional `resource="34"` für nur eine Ressource) oder Block **Pass Captain Terminbuchung** (`pass-captain/service-booking`; Event und Ressource in der Seitenleiste wählen; Ausrichtung breit/volle Breite und Abstände über die Block-Einstellungen) in eine Seite einfügen. Die Admin-Seite „Service-Zeitfenster“ zeigt den Shortcode des gewählten Events und – sobald eine veröffentlichte Seite ihn enthält – den Link **Auf der Website ansehen** (Filter `wpcap_service_booking_page_url`).

**Ablauf für Kund:innen**:

1. **Termin wählen**: Gibt es mehrere aktive Ressourcen, wählt die Person zuerst das Angebot (Name, Preis „pro Platz“ oder „kostenlos“, Stornofrist und Buchungsschluss). Darunter stehen die freien Zeitfenster einer Woche (Montag bis Sonntag in der Zeitzone des Events), nach Tagen gruppiert, mit freien Plätzen; „Vorherige/Nächste Woche“ blättert (nicht vor die aktuelle Woche, nicht hinter das letzte offene Zeitfenster der Ressource). Start ist die erste Woche mit einem freien Zeitfenster. Dazu die Anzahl Plätze (höchstens freie Plätze, Kapazität der Ressource, „max. pro Bestellung“ der Preis-Ticketart und 20).
2. **Deine Angaben**: E-Mail-Adresse; bei kostenpflichtigen Ressourcen zusätzlich Zahlungsart, optional Rechnungsdaten (Pflicht ab der Schwelle `billing_required_threshold_minor`) und – falls eingestellt – die Bedingungen. Der Button heißt dann „Zahlungspflichtig buchen“.
3. **Buchen**: Die Seite reserviert das Zeitfenster (`POST /service-slots/{id}/bookings`, Reservierung + persönlicher Buchungszugang) und bestätigt es (`POST /service-bookings/{id}/confirm`). Kostenlose Ressourcen sind damit bestätigt; kostenpflichtige laufen über den normalen Checkout (`ServiceBookingFlow::checkout()`): die Bestätigung zeigt Bestellnummer und Betrag und entweder den Button **Weiter zur Zahlung** (Stripe, PayPal, Mollie – kein automatischer Seitenwechsel, damit der Verwaltungslink vorher sichtbar ist) oder die Überweisungsdaten. Nach Zahlungseingang wird die Buchung bestätigt (Mail). Ist das Zeitfenster inzwischen voll, lädt die Woche neu und die Person wählt ein anderes. Schlägt nur die Bestätigung fehl (z. B. Bedingungen nicht bestätigt), nutzt der nächste Versuch dieselbe Reservierung; wird ein anderes Zeitfenster gewählt, gibt die Seite die alte Reservierung frei.
4. **Bestätigung und Verwaltung**: Die Bestätigung enthält den Link **Termin ansehen, umbuchen oder stornieren** (`<Seite>#wpcap-booking=<id>-<token>`). Der Buchungszugang steht nur im URL-Fragment: Browser senden ihn weder an den Server noch im Referer, die Seite schickt ihn nur im JSON-Body (Lesen per `POST` mit `X-HTTP-Method-Override: GET`). Unter dem Link zeigt derselbe Block die Buchung (Termin, Plätze, Status) mit Umbuchen (passende freie Zeitfenster, solange die Umbuchungsfrist läuft; nicht bei offener Zahlung) und Stornieren (mit Rückfrage; bestätigte Buchungen nur bis zur Stornofrist, bezahlte werden erstattet). Bei kostenlosen Buchungen enthält auch die Bestätigungsmail diesen Link (nur, wenn die Seite veröffentlicht ist, nicht passwortgeschützt ist und den Shortcode/Block enthält; nie in der Team-Mail); bezahlte Buchungen verwaltet die Person zusätzlich im Bestellportal.

**Technik**: `Frontend\ServiceBooking` rendert die Ressourcen und die erste Woche serverseitig; `assets/service-booking.js` lädt weitere Wochen über `GET /events/{id}/service-slots?resource=<id>&week=<Montag Y-m-d>` (neue, optionale Parameter; ohne sie liefert die Route wie bisher die nächsten 100 buchbaren Zeitfenster). Skript und `public.css` werden nur auf Seiten geladen, auf denen der Shortcode/Block ein Buchungsformular ausgibt (nicht bei den Hinweisen „nicht buchbar“, „ausgebucht“). Formulare sind `method="post"` (nie personenbezogene Daten in der URL). Ohne JavaScript erscheint ein Hinweis, dass es zum Buchen nötig ist. Texte: Textdomain `pass-captain`, die Skript-Texte kommen aus dem lokalisierten Katalog `WPCAP_SERVICE_BOOKING.i18n`; Datums- und Zeitangaben im Skript formatiert `Intl.DateTimeFormat` in Sprache der Seite und Zeitzone des Events.

**Barrierefreiheit**: Schritte als Abschnitte mit Überschrift (Ebene per `wpcap_service_booking_heading_level`), Ressourcen und Zeitfenster als Radiogruppen mit sichtbaren Beschriftungen, Wochenwechsel und Ladezustand über eine Live-Region angesagt, Blättern-Buttons mit `aria-disabled` (der Fokus bleibt), Fehlerzusammenfassung mit Sprunglinks und Feldfehlern (`aria-invalid`, `aria-describedby`), Fokus auf die Überschrift des nächsten Schritts, die Bestätigung bzw. die Buchungsansicht. Mobil: Zeitfenster einspaltig, Zielgrößen ≥ 44 px. Die a11y-Prüfung (`tests/a11y`) enthält die Seite „Terminbuchung“ (Formular und Schritt 2) und die Tastaturprüfung des Ablaufs.

**Grenzen**: Teilnehmerfelder der Preis-Ticketart werden nicht abgefragt (Ticketart ohne Pflicht-Teilnehmerfelder verwenden); Rabatt-/Gutscheincodes nur über die REST-API (`code`). Je Wochenabfrage höchstens 100 Zeitfenster.

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

Statusfolge einer Buchung (ein gemeinsamer Buchungsablauf mit [SERVICE-BOOKINGS.md](SERVICE-BOOKINGS.md)): `held` (Reservierung, 10 Min.; mit verknüpfter, noch unbezahlter Bestellung angezeigt als `pending_payment`) → `confirmed` (bzw. `pending_confirmation`, wenn der Anbieter bestätigen muss); daneben `expired` (Reservierung bzw. unbezahlte Bestellung abgelaufen), `cancelled` (auch bezahlt, aber Termin inzwischen vergeben: Grund `capacity_lost`, Erstattung) sowie `no_show` (bestätigte Buchung, Gast nicht erschienen; nur ab Beginn des Zeitfensters, A143). Platz belegen `confirmed`/`pending_confirmation` sowie `held` bis `expires_at`. Ältere Buchungen mit dem früheren Status `pending_payment` werden wie `held` behandelt.

- **Buchungszugang**: `POST /service-slots/{id}/bookings` liefert neben `booking_id` einen persönlichen `token` (64 Hex-Zeichen; gespeichert wird nur der SHA-256-Hash, Schema 3.10.4). Damit arbeiten `GET /service-bookings/{id}` (Status, Regeln, Umbuchungsziele, keine Daten anderer), `POST …/confirm`, `…/rebook` (`slot_id`) und `…/cancel`. Nach 10 falschen Tokens je Client und Stunde antwortet die API diesem Client mit 429, nach 50 falschen Tokens je Buchung in 10 Minuten (von beliebigen Clients) für den Rest dieser 10 Minuten auch der Buchung (A279, Filter `wpcap_service_booking_token_limits`). Die Datenschutz-Löschung entfernt den Token.
- **Bestätigen ohne Preis**: Ressourcen ohne Ticketart sind kostenlos; die Buchung wird sofort bestätigt.
- **Bestätigen mit Zahlung**: Hat die Ressource eine Ticketart (Feld „Preis (Ticketart)“, nur Ticketarten desselben Events), läuft die Zahlung über den normalen Checkout (`Rest\Controller::checkout()`: Zahlungsarten, AGB, Rabatt-/Gutscheincode, Rechnungsdaten, Zugangslink zum Bestellportal). `confirm` nimmt dafür `provider`, `accept_terms`, `terms_version`, `locale`, `billing`, `code` entgegen und liefert die Bestelldaten samt Zahlungsweiterleitung zurück. Die Bestellung wird innerhalb ihrer Transaktion mit der Buchung verknüpft (`pending_payment`, Reservierung = Bestellreservierung, höchstens bis zum Beginn); ist die Reservierung schon abgelaufen oder bereits verknüpft, wird die Bestellung abgebrochen – eine Buchung hat nie zwei Bestellungen. Die Ticketart ist nur Preisposition: ihr Verkaufszeitraum und der Beginn ihres Termins gelten nicht (wohl aber ein beendeter Termin), Überweisung wird bis zum Beginn des Zeitfensters angeboten. Die ausgestellten Tickets dienen als Beleg.
- **Zahlung**: `wpcap_order_paid` bestätigt die Buchung. Kommt die Zahlung nach Ablauf der Reservierung, wird unter den Sperren erneut geprüft (Platz, Puffer, Leistungserbringer); ist der Termin inzwischen vergeben, wird die Buchung storniert (Grund `capacity_lost`, Zahlung ohne Termin), ihre Tickets automatisch erstattet (Systemerstattung, ggf. mit Freigabe; die Tickets werden dabei widerrufen) und Kund:in und Team werden informiert. Läuft eine unbezahlte Bestellung ab oder wird sie storniert, wird die Buchung `expired` bzw. `cancelled`; wird eine bezahlte Bestellung anderswo storniert oder erstattet (Bestellansicht, Anbieter-Dashboard), wird die Buchung storniert.
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
  und das Ende des Abendkassenverkaufs (Ende des Termins, ohne Ende nach der Standarddauer, siehe unten) zählen Sekunden,
  nicht Uhrzeiten. „24 h vor Sonntag 10:00 MESZ“ ist Samstag 09:00 MEZ; ein Halt von 10 Minuten ab
  02:55 MESZ endet um 02:05 MEZ.
- **Erinnerungen** werden je Termin, Startzeit, Stufe und Empfänger:in genau einmal eingeplant
  (Dedupe-Schlüssel mit der UTC-Startzeit). In der doppelten Stunde (Herbst) gibt es daher keinen zweiten
  Versand, und ein Versandzeitpunkt, der als Ortszeit in die fehlende Stunde (Frühjahr) fiele, wird nicht
  übersprungen. `{hours}` nennt die tatsächliche Restzeit beim Versand (gerundet, mindestens 1), nicht die
  Stufe: Wer 5 Stunden vor Beginn kauft, bekommt die 24-h-Erinnerung mit „in 5 Stunden“ (A558). Die
  Einplanung geht alle fälligen Termine in Blöcken von 200 durch (nach Beginn und ID, höchstens 25 Blöcke
  je Lauf; ein unvollständiger Lauf merkt sich die Stelle in `wpcap_reminders_cursor` und setzt im nächsten
  Lauf fort), statt nur die ersten 200 zu sehen.
- **Kalendertage statt Dauern** gelten für Verkaufsfenster, die sich auf den Terminbeginn beziehen:
  „Verkaufsstart N Tage vor Beginn“ von Serien zählt Ortstage (Beginn 19:00 MESZ, 14 Tage vorher =
  19:00 MEZ), Verkaufsfenster nach Vorlage behalten ihre Ortsuhrzeit (A556); „Verkaufsende N Minuten vor
  Beginn“ bleibt eine echte Dauer. Wird ein Einzeltermin verschoben, verschiebt die Verwaltung die
  Verkaufsfenster seiner Ticketarten um denselben Abstand in Ortszeit (Kästchen „Verkaufszeiträume der
  Ticketarten mit dem Beginn verschieben“, vorbelegt; Audit `ticket_type_updated` mit
  `reason: occurrence_rescheduled`). Ein Fenster, das danach schon vorbei wäre oder erst nach dem
  (effektiven) Terminende begänne, bleibt unverändert; die Speichermeldung nennt die Ticketart zur
  Prüfung (A555). Rechenhilfen: `Occurrence::local_shift()`, `Occurrence::local_days_before()`.
  Empfänger:in ist, wer den aktuellen Ticketcode hat (A581): die Teilnehmer-E-Mail erst nach
  einer Übertragung, Zuweisung an diese Adresse oder einem erneuten Versand dorthin – eine beim Checkout
  eingetragene Teilnehmer-E-Mail allein nicht (die Ticketmail ging an die Käuferin).
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

### Laufende und beendete Termine, mehrtägige Termine (A535)

Wann ein Termin begonnen hat, läuft oder vorbei ist, entscheidet zentral `PassCaptain\Occurrence`:
Das **effektive Ende** ist `ends_at`, ohne Ende Beginn + Standarddauer (Einstellung „Standarddauer von
Terminen ohne Ende“, `occurrence_default_duration_hours`, Vorgabe 6 h; Filter
`wpcap_occurrence_default_duration`). PHP (`Occurrence::effective_end()`, `is_running()`, `is_over()`)
und SQL (`Occurrence::end_sql()`) rechnen gleich. Es gilt für:

- **Online-Verkauf** (Checkout, WooCommerce, Wartelistenangebote): Ohne Verkaufsende endet er beim
  Beginn. Ein Verkaufsende **nach** dem Beginn verlängert ihn bis zu diesem Verkaufsende, höchstens bis
  zum effektiven Ende – so bleiben z. B. Tagestickets für Tag 2 und 3 eines Festivals verkäuflich.
  Liegt das Verkaufsende nach dem Terminende, warnt die Verwaltung beim Speichern von Ticketart oder
  Termin (gespeichert wird trotzdem; online verkauft wird nur bis zum Ende).
- **Abendkasse**: bis zum effektiven Ende.
- **Öffentliche Eventliste** (Shortcode/Block, REST `/events`): kommende Termine und laufende Termine,
  von denen noch eine Ticketart online verkauft wird (nur diese Ticketarten erscheinen).
- **Terminauswahl von Web-Scanner und App** (`/app/occurrences`): laufende Termine (mehrtägige bis zu
  ihrem Ende), kommende (Web-Scanner: eine Woche voraus) und solche, die vor weniger als 24 h endeten.
  Einlass an Tag 2/3 ist damit weiter mit Terminprüfung möglich. Dasselbe effektive Ende gilt für die
  Terminliste des Offline-Scanners und das Ablaufdatum von Apple-Wallet-Pässen (Ende + 12 h, A606).
  Seit A606 rechnen auch die Aufbewahrungsfristen (Datenschutz-Löschlauf, Warteliste, Gästelisten,
  Käuferlimits, Partner-Ticketcodes), die Spätzahlungsprüfung (`Orders::occurrence_end()`) und der
  Abgleich der Kapazitätszähler mit diesem effektiven Ende statt mit „Beginn + 1 Tag“; die Frist von
  N Tagen beginnt also bei Terminen ohne Ende mit Beginn + Standarddauer. Einzige Ausnahme: die
  Anzeigedauer von Kalenderdateien ohne Ende (`DTEND`, 2 h, `wpcap_calendar_default_duration`) – sie
  entscheidet nicht, ob ein Termin vorbei ist.

### Lebenszyklus eines Termins (A562)

`Occurrence::phase( $termin, $jetzt )` liefert die Phase: `upcoming` (vor Beginn), `running` (begonnen,
nicht vorbei), `over` (nach dem effektiven Ende) oder `cancelled`. Jeder Übergang löst
`wpcap_occurrence_phase_changed` ( Termin-ID, neue Phase, alte Phase, Kontext ) aus:

- **Zeitliche Übergänge** meldet `Occurrence::sweep_phases()` auf `wpcap_process_jobs` (alle 5 Minuten):
  Termine, deren Beginn oder effektives Ende seit dem letzten Lauf (Option
  `wpcap_occurrence_phase_sweep`) verstrichen ist, höchstens 500 je Lauf; nach einem Cron-Ausfall bis zu
  7 Tage rückwirkend (dann kann eine Phase übersprungen werden, z. B. `upcoming` → `over`).
- **Verwaltung**: Ein Speichern, das den Termin in eine andere Phase verschiebt (z. B. ein laufender Termin
  in die Zukunft → `upcoming`), meldet sofort; eine Absage meldet `cancelled`.
- **Reaktionen im Kern**: Seiten mit der Eventliste werden aus Seiten-Caches entfernt (der Termin
  verschwindet bzw. zeigt nur noch Ticketarten im Verkauf); offene Wartelisteneinträge eines begonnenen
  oder beendeten Termins werden sofort geschlossen (außer bei Ticketarten, die nach Beginn weiter online
  verkauft werden, A535). Scanner-Listen, Wallet-Pässe und Löschläufe können sich anhängen; Handler müssen
  idempotent sein (ein Übergang kann selten doppelt gemeldet werden).

### Checkout an den angezeigten Stand gebunden (A560)

Das Checkout-Formular schickt je Position die Revision des angezeigten Termins (`occurrence_revision`,
aus `data-revision` der Eventkarte) und den angezeigten Stückpreis (`expected_price_minor`, aus
`data-price`) mit. `Orders::create()` vergleicht beides unter der Terminsperre: Wurde der Termin
inzwischen verschoben (Revision geändert: Beginn, Ende, Einlass, Ort, Land, Status) oder gilt ein anderer
Preis, wird die Bestellung mit `409` abgelehnt (`data.reason` `occurrence_changed` bzw. `price_changed`,
`data.state`: Revision, Beginn als ISO 8601 mit Offset und als Anzeige, Ort, aktuelle Preise je
Ticketart). Es wird nichts reserviert. Das Formular übernimmt den neuen Stand (Datum auf der Karte,
Preise, Revision) und zeigt die Meldung; erneutes Absenden bestätigt den neuen Stand. Aufrufer ohne
diese Felder (API-Clients, WooCommerce, Kasse, Wartelistenangebote) sind nicht gebunden. Die Felder
werden nie als Preis verwendet.

### REST `/events`: Zeiten mit Offset, Verkaufsstatus (A561)

Neben `starts_at`/`ends_at` (UTC, „Y-m-d H:i:s“, unverändert) liefert jeder Termin `start`, `end`,
`effective_end` als ISO 8601 mit dem Offset seiner Zeitzone (z. B. `2027-07-01T20:00:00+02:00`),
`phase` (A562) und `revision` (A560). Jede Ticketart hat `sales_status` (`on_sale`, `not_started`,
`ended`, `sold_out`), `sales_start`/`sales_end` (ISO 8601 oder `null`), `online_until` (Ende des
Online-Verkaufs nach Termin-Phase) und `available` (verbleibende Stück, `null` = unbegrenzt). Die Antwort
trägt `Cache-Control: no-store`. Vertrag `public` 1.1. Seit 1.2 (A425) zusätzlich je Ticketart
`service_fee_minor` (Servicegebühr je Ticket im eigenen Checkout), `display_price_minor` (anzuzeigender Preis
inkl. dieser Gebühr) und `order_fee_minor` (Bearbeitungsgebühr je Bestellung, nicht bei kostenlosen
Bestellungen); `price_minor` bleibt der Ticketpreis.

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
- **Gültigkeit von Rabattcodes** (A616): Das Admin-Formular nimmt Ortszeit der Website; die REST-Route
  `POST /events/{id}/campaigns` nimmt `starts_at`/`ends_at` als Ortszeit der Website (`JJJJ-MM-TT HH:MM`),
  als ISO 8601 mit Zeitzone (`2026-12-24T18:00:00+01:00`, `…Z`) oder als reines Datum – dann gilt der Code ab
  Tagesbeginn bzw. bis Tagesende (23:59:59 Ortszeit). Gespeichert wird UTC (`Campaigns::window_bound()`). Ein
  unlesbarer Wert oder ein Ende vor dem Beginn ergibt 400 `wpcap_campaign_dates`, statt einen unbegrenzten
  Code oder einen um Mitternacht UTC ablaufenden Code anzulegen.
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

## Checkout im Browser: unklare Antworten und „Zurück“ von der Zahlungsseite (A309, A310)

- **Idempotenzschlüssel**: Das Formular schickt je Bestellversuch einen Schlüssel mit. Er gehört zum
  Formularinhalt (Vergleich über einen Hash): Wird dasselbe Formular unverändert erneut abgeschickt,
  setzt der Server dieselbe Bestellung fort (bei Stripe/Mollie mit der noch offenen Zahlungssitzung,
  bei PayPal über die PayPal-Request-Id); mit geänderter Auswahl entsteht ein neuer Versuch.
- **Kalendereintrag in der Bestätigung** (A600): Die Bestätigung nach dem Bestellen (abgeschlossen oder
  wartend auf Zahlung) verlinkt sofort die .ics-Datei des bestellten Termins („Termin in den Kalender eintragen
  (.ics)“, `?wpcap_ics=occurrence&id={Termin}`, Konfiguration `WPCAP_CHECKOUT.icsBase`); ohne Kalender-Modul
  entfällt der Link. Einen Ticket- bzw. Bestellportal-Link zeigt die Seite bewusst nicht (der Link ist ein
  Zugangsschlüssel und kommt nur per E-Mail).
  Der Server prüft das selbst (A420, Schema 3.10.25): Zur Bestellung wird ein Fingerabdruck der Anfrage
  gespeichert (`wpcap_orders.request_hash`: E-Mail, Ticketpositionen je Ticketart, Kampagnen- und
  Gutscheincode). Kommt derselbe Schlüssel mit anderem Inhalt, antwortet er mit 409
  `wpcap_idempotency_conflict` (`reason: idempotency_key_reused`) statt die erste Bestellung zurückzugeben;
  das Formular verwirft dann den Schlüssel. Bestellungen von vor dem Update (ohne Fingerabdruck) und
  Bestellungen von Kanälen (WooCommerce, Kasse, Partner) werden wie bisher wiederverwendet.
- **Eingaben** (A421, A534): Die öffentlichen Schreibrouten (Checkout, Bestellportal, Servicebuchung,
  Rabattvorschau, Warteliste) haben ein `args`-Schema; WordPress lehnt falsch typisierte Werte schon vor dem
  Callback mit 400 `rest_invalid_param` ab. Mengen und IDs sind ganze Zahlen ab 1 – `-2` oder `2.5` werden
  abgelehnt, nicht stillschweigend zu `2`. Zustimmungen (`accept_terms`, `sms_consent`) gelten nur bei
  `true`, `1`, `"1"`, `"true"`, `"on"` oder `"yes"` (`Http::accepted()`); `"false"`, `"0"` oder `"no"` sind keine
  Zustimmung.
- **Was als Erfolg gilt** (A309): nur eine JSON-Antwort mit Bestellnummer (`reference`) und `status`.
  Eine HTML-Seite (Seitencache, WAF/Bot-Schutz, Proxy-Fehler 502/504) oder eine abgeschnittene Antwort
  zeigt kein „Vielen Dank“, sondern den Hinweis, das Postfach zu prüfen oder das Formular unverändert erneut
  abzuschicken; der Schlüssel bleibt, damit keine zweite Bestellung entsteht. Ein Fehler der Plugin-API
  (JSON mit `code`/`message`, z. B. „ausverkauft“) beendet den Versuch, der nächste ist neu.
- **Zurück von Stripe/PayPal/Mollie** (A310): Nach der Weiterleitung behält das Formular den Schlüssel;
  der `sessionStorage` des Tabs merkt sich Bestellnummer, Zahlungslink, Schlüssel und das Reservierungsende
  (keine E-Mail-Adresse, keine Namen; der Formularinhalt nur als Hash). Kommt die Käuferin per „Zurück“
  (bfcache, `pageshow` mit `persisted`) oder nach einem Neuladen zurück, zeigt die Karte „Deine Bestellung …
  wartet noch auf die Zahlung“ mit „Zahlung fortsetzen“. Nach der Rückkehr mit `wpcap_payment=success`,
  `pending` oder `cancelled` und nach Ablauf der Reservierung ist der Eintrag weg. Ein zweites Absenden,
  während die erste Anfrage noch läuft, wird ignoriert.
- **Preis-Snapshot** (A576): Das Formular schickt die angezeigten Stückpreise der gewählten Ticketarten mit
  (`expected_prices`: `{ "<Ticketart-Id>": <Nebeneinheiten> }`). Ist ein Stückpreis inzwischen höher
  (Frühbucher abgelaufen, Nachfragestufe erreicht, Seite aus dem Cache), lehnt `Orders::create()` ab:
  HTTP 409, Code `wpcap_price_changed`, `data.reason` `price_changed`, `data.field` `items`,
  `data.prices` je Ticketart `shown_minor`, `unit_minor`, `formatted` – es wird nichts gebucht. Die Karte
  übernimmt die neuen Preise, zeigt die Meldung, und der nächste Klick bestellt zum neuen Preis. Ein
  niedrigerer Preis wird ohne Rückfrage berechnet. Ohne `expected_prices` (eigene API-Clients) und für
  vertrauenswürdige Kanäle mit eigenem Preis (WooCommerce, Kasse, Partner) gilt die Prüfung nicht.
- **Rückkehr von Stripe/PayPal/Mollie** (A554): Das Formular schickt seine Seiten-URL als `return_url`;
  angenommen werden nur http(s)-URLs mit dem Host der Website (ohne Benutzerdaten, ohne Fragment, ohne
  Rückkehr-Parameter), sonst gilt die Startseite. Erfolgs-, Abbruch- und Rückkehr-URLs (auch die PayPal- und
  Mollie-Rückkehr mit Capture) liegen auf dieser Seite. Der Hinweis nennt die Bestellnummer. Nach einem Abbruch
  (`cancel`) oder Fehlschlag (`failed`) bietet er, solange die Reservierung läuft, „Zahlung fortsetzen“ (die
  noch offene Sitzung derselben Bestellung bzw. eine neue für sie – nie eine zweite Bestellung) und
  „Bestellung stornieren“: Die Bestellung wird storniert (`buyer_cancelled_on_return`, Audit
  `order_cancelled_by_buyer`), die Plätze sind sofort frei, eine offene Stripe-Sitzung wird beendet
  (`expire`), eine offene Mollie-Zahlung abgebrochen; danach `wpcap_payment=cancelled`. Berechtigt sind die
  Aktionen über `wpcap_rk`, einen mit dem WordPress-Salt gebildeten Schlüssel der Bestellung, der nur in den
  Rückkehr-URLs steht. Ohne Aktion verfällt die Reservierung wie bisher von selbst. Die Ausgabe des Hinweises
  hängt an `wp_body_open`.
- **Rückkehr-Hinweis ohne `wp_body_open` und Weg zum Bestellportal** (A307): Themes ohne `wp_body_open` (viele
  klassische Themes) zeigen den Hinweis über den Filter `the_content` (Hauptabfrage, in der Schleife) vor dem
  Seiteninhalt bzw. vor der Eventliste (`[wpcap_events]`/Block) – je Anfrage genau einmal. Der Hinweis erklärt
  den persönlichen Bestelllink aus der E-Mail und bietet „Link zur Bestellung erneut per E-Mail senden“: Mit dem
  Schlüssel `wpcap_rk` der Rückkehr-URL wird ein neuer Zugangslink an die E-Mail-Adresse der Bestellung gesendet
  (`Orders::rotate_access_link()`, der bisherige Link wird ungültig; höchstens 2 je Bestellung und Stunde, 10 je
  Client; Audit `order_access_link_resent`), danach bestätigt die Seite die maskierte Adresse
  (`wpcap_portal=sent`). Der Zugangslink selbst erscheint nie auf der Seite, weil Rückkehr-URLs in
  Browserverlauf, Proxys oder Screenshots landen können. WooCommerce-Bestellungen bekommen den Knopf nicht.

## Teilnehmerfelder: Feldtypen, Ebene, Teilnehmername und Versionen (A552, A680)

Teilnehmerfelder werden je Ticketart unter **Events → Termin → Ticketart → Teilnehmerfelder** angelegt
(`PassCaptain\ParticipantFields`, Schema 3.10.27). Derselbe Feldvertrag gilt im eigenen Checkout, an der
Abendkasse, in beiden WooCommerce-Checkouts, beim Annehmen eines Wartelistenangebots, auf der Ticketseite und im
Bestellportal.

- **Feldtypen:** Text, E-Mail, mehrzeiliger Text, Checkbox, **Auswahl**, **Mehrfachauswahl**, **Telefon**,
  **Datum** und **Zahl**. Auswahl und Mehrfachauswahl brauchen mindestens eine Auswahlmöglichkeit (eine je Zeile,
  höchstens 50, doppelte werden zusammengefasst; bei der Mehrfachauswahl ohne Komma). Der Server nimmt nur
  angebotene Möglichkeiten an; eine Mehrfachauswahl wird als „A, B“ in der Reihenfolge der Möglichkeiten
  gespeichert. Telefon: Ziffern, Leerzeichen und `+ ( ) / - .`, 5–30 Zeichen, mindestens 5 Ziffern. Datum:
  `JJJJ-MM-TT`. Zahl: ganze Zahl oder Dezimalzahl (Komma wird zum Punkt). Ungültige Eingaben werden abgelehnt
  (`wpcap_attendee_field_invalid` mit Ticketart, Position und Feldschlüssel), nie still als leer gespeichert.
- **Hilfetext:** steht unter dem Feld (mit `aria-describedby` verbunden), zusätzlich zum Zweck.
- **Ebene:** „Für jedes Ticket“ oder „Einmal je Bestellung“. Ein Feld der Bestellebene wird je Ticketart einmal
  abgefragt (im ersten Teilnehmerblock der Ticketart) und an jedem Ticket dieser Art gespeichert – Exporte,
  Aufbewahrungsfristen und Löschung arbeiten deshalb unverändert je Ticket. Eine Einwilligung ist immer je
  Ticketperson und kann nicht auf Bestellebene liegen.
- **Teilnehmername je Ticket:** Schalter „Name für jedes Ticket verlangen“ an der Ticketart
  (`attendee_name_required`, Filter `wpcap_attendee_name_required`). Dann braucht jedes Ticket einen Namen
  (`wpcap_attendee_field_required`, `field_key` `__name`) – auch wenn sonst keine Teilnehmerfelder bestehen; der
  WooCommerce-Checkout fragt die Angaben dann ebenfalls ab. Korrekturen einzelner Angaben auf der Ticketseite und
  aufgeschobene Wartelistenangaben sind davon ausgenommen.
- **„Meine Angaben übernehmen“:** Der erste Teilnehmerblock des Checkouts hat einen Knopf, der E-Mail und
  Rechnungsname der Käuferin bzw. des Käufers in den Block kopiert. API-Clients setzen je Teilnehmer
  `same_as_buyer: true`; leere Name-/E-Mail-Felder werden dann aus der Käufer-E-Mail und dem Rechnungsnamen gefüllt.
- **Versionen:** Ändern sich Bezeichnung, Typ, Auswahlmöglichkeiten, Pflicht oder Ebene, steigt die Version des
  Feldes (Spalte `version`, in der Liste als „v2“ usw.). Das Audit-Protokoll (`participant_field_updated`) hält
  jede Fassung mit Version fest, und jede Antwort speichert die Version, unter der sie gegeben wurde
  (`wpcap_participant_answers.field_version`; 0 = vor Schema 3.10.27). Hilfetext, Zweck, Reihenfolge und
  Aufbewahrung ändern die Version nicht.

### Fehlende Teilnehmerangaben nachfordern (A682)

Fehlen an einem gültigen Ticket noch Pflichtangaben – der Name bei „Name für jedes Ticket verlangen“ oder ein
Pflichtfeld ohne Antwort, z. B. nach einem Wartelistenangebot, einer Bestellung ohne Teilnehmerdaten oder einem
später angelegten Pflichtfeld –, nennt Pass Captain sie gezielt je Ticket (`ParticipantFields::missing()`;
Einwilligungen werden nie nachgefordert):

- **Ticket-E-Mail:** unter dem betroffenen Ticket „Noch fehlende Angaben: Name, T-Shirt – bitte über „Ticket
  öffnen“ ergänzen.“ (die Ticketseite hat das Formular „Teilnehmerdaten“). Audit `attendee_data_missing` und Aktion
  `wpcap_attendee_data_missing` (nur Ticket-IDs und Feldbezeichnungen).
- **Bestellansicht im Admin:** Spalte „Angaben“ zeigt „Fehlt: …“ je Ticket; unter „Aktionen“ steht
  **„Fehlende Teilnehmerangaben anfordern“** (Recht `wpcap_manage_orders` für die Bestellung). Die Käuferin bzw. der
  Käufer erhält die Mail `attendee_data_request` mit der Liste je Ticket und einem **neuen persönlichen Bestelllink**
  (der bisherige Bestelllink wird ungültig); höchstens einmal je Stunde und Bestellung (`wpcap_attendee_request_recent`,
  429), protokolliert als `attendee_data_requested` ohne Personendaten. Ohne Lücken: `wpcap_attendee_data_complete`.
- **Bestellportal:** je Ticket der Hinweis „Für dieses Ticket fehlen noch: …“ über dem Formular „Teilnehmer zuweisen“.

### Selbstbedienung je Teilnehmerfeld: gesperrte Felder und eigene Fristen (A683)

Je Teilnehmerfeld lässt sich festlegen, ob Käufer:innen (Bestellportal, „Teilnehmer zuweisen“) und Ticketinhaber:innen
(Ticketseite, „Teilnehmerdaten“) es selbst ändern dürfen (Spalten `self_service` `edit`|`locked` und `edit_hours`,
Schema 3.10.27):

- **Gesperrt (nur durch das Team):** Das Feld wird in beiden Formularen nur angezeigt („(nicht mehr änderbar)“).
- **Eigene Frist:** „bis Stunden vor Beginn“ (0 = keine eigene Frist, höchstens 8760). Danach verhält sich das Feld wie
  gesperrt; die allgemeine Frist (`attendee_edit_hours`, Standard 24 h) schließt weiterhin das ganze Formular.
- Der Server prüft unabhängig vom Formular: Ein geänderter Wert eines gesperrten Feldes wird mit
  `wpcap_attendee_field_locked` (403, `field_key`) abgelehnt und nichts wird gespeichert; unverändert oder gar nicht
  gesendete gesperrte Felder behalten ihren Wert, die übrigen Angaben werden gespeichert. Einwilligungen haben ihren
  eigenen Ablauf (Widerruf jederzeit). Korrekturen durch das Team im Admin sind davon nicht betroffen.

## Captain-Suite: Status der Verträge und Provider (A670, A678)

**Pass Captain → Systemstatus → Betrieb → Captain-Suite** (`Admin\SuiteStatus`, Recht `manage_options`) zeigt je Vertrag, den der
Kern anbietet (`workflow`, `review`; [CONTRACTS.md](CONTRACTS.md)):

- **Status:** „Vertrag verfügbar – kein Plugin registriert“, „Vertrag verfügbar – Provider aktiv (spricht x.y.z)“
  oder „Inkompatibel – der Provider spricht 2.x, der Kern bietet 1.x an; er wird nicht aufgerufen“. Die Anzeige ist
  reine Diagnose: der Kern prüft keine Lizenzen oder Rechte eines Suite-Plugins.
- **Letzte angenommene Zustellung**, **ausstehende / fehlgeschlagene** Ereignisse der Outbox und der **letzte Fehler**
  (Fehlercode mit Zeitpunkt) je Vertrag.
- **Ohne Provider:** was dann passiert – Workflow: Erinnerungen und Mails versendet Pass Captain selbst, Commands
  stehen nicht zur Verfügung; Bewertungen: keine Einladungen, Teilnahmen werden nur festgehalten. Ereignisse warten
  30 Tage in der Outbox und erreichen ein später installiertes Plugin (Replay).
- **Letzte Workflow-Commands** (Command, Ergebnis, Zeit – ohne Argumente) und die **Auswahl der übergebenen
  optionalen Mails** (A677) mit den Zählern „übergeben“ und „nativ versendet (Rückfall)“ samt letztem Grund.
