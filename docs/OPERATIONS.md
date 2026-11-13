# Betrieb und Wiederherstellung

## Vor einem Upgrade

1. Upgrade zuerst in einer Staging-Site mit derselben PHP-, WordPress-, Datenbank- und Integrationsversion einspielen.
2. Dateien und vollständige WordPress-Datenbank unmittelbar vor dem Upgrade sichern. Die Präfix-Tabellen `*_wpcap_*` enthalten Aufträge, Reservierungen, Tickets, Einlassprotokolle und weitere Plugin-Daten.
3. Sicherungszeitpunkt, Plugin-Version und Schema-Version notieren. Die Migration v3 nutzt `dbDelta` und ergänzt Tabellen und Spalten; sie löscht keine Plugin-Tabellen.
4. Nach dem Upgrade Datenbankverbindung, öffentliche Events, Checkout, Ticketmail, Check-in und geplante Jobs in der Staging-Site prüfen.

## Datenbank-Anforderungen

InnoDB mit `utf8mb4`. Jede Indexspalte bleibt unter 767 Byte (höchstens 191 Zeichen, sonst Präfixindex), zusammengesetzte Indizes unter 3072 Byte – damit läuft das Schema auch auf älteren MySQL-/MariaDB-Versionen ohne `innodb_large_prefix` (MySQL ab 5.6, MariaDB ab 10.1; empfohlen MySQL 8 / MariaDB 10.6+). Ein Test prüft das für alle Tabellen.

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

Prüfen, ob der externe Cronjob greift: Unter **Werkzeuge → Website-Zustand** meldet die Prüfung „geplante Aufgaben“ den letzten Lauf der Warteschlangen; mit `wp cron event list --fields=hook,next_run_relative | grep wpcap_` sind überfällige Ereignisse sofort zu sehen.

## Betriebsüberwachung (Website-Zustand)

Das Plugin trägt eigene Prüfungen in **Werkzeuge → Website-Zustand** ein (Site Health, A164), Abzeichen „WP Pass Captain“. Ist eine Prüfung **kritisch**, zeigt jede Pass-Captain-Admin-Seite Administratorinnen zusätzlich einen Hinweis mit Link zur Betriebsübersicht (Ergebnis fünf Minuten zwischengespeichert, Transient `wpcap_health_critical`).

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

Der Reiter **Bericht** des Website-Zustands enthält den Abschnitt „WP Pass Captain – Betrieb“ (DISABLE_WP_CRON, letzter und nächster Lauf der Cron-Hooks, Einträge je Status und Rückstand der Warteschlangen, Mail-/SMS-Fehler, letzter Zahlungs-Webhook je Anbieter) – praktisch für Support-Anfragen.

### Betriebsübersicht: Rückstände und Dead Letters (A163)

**Pass Captain → Einstellungen & Integrationen → Betrieb** (nur `manage_options`) zeigt die Ergebnisse der Prüfungen und je Warteschlange – Hintergrundjobs (`wpcap_jobs`), E-Mail-Warteschlange (`wpcap_message_queue`), Webhook-Zustellungen (`wpcap_webhook_deliveries`, falls das Modul installiert ist) –:

- Einträge je Status, Anzahl fälliger Einträge und Wartezeit des ältesten,
- die endgültig fehlgeschlagenen Einträge (Jobs `dead_letter`, E-Mails und Webhooks `failed`, neueste 50) mit Typ, Versuchen, letztem Fehler (E-Mail-Adressen maskiert) und nur den **Feldnamen** des Inhalts (bei Webhooks die von `data.object`); E-Mail-Empfänger erscheinen maskiert. Inhalte, Betreffzeilen und Secrets werden nie angezeigt.

Aktionen je Zeile oder als Mehrfachaktion (ausgewählte oder alle fehlgeschlagenen Einträge, höchstens 500):

- **Erneut versuchen**: Status wieder wartend, Versuche 0, fällig sofort, letzter Fehler gelöscht; danach wird die Verarbeitung angestoßen (sofortiger Folgelauf über Action Scheduler bzw. WP-Cron, ohne dass die Admin-Seite selbst wartet). Webhooks laufen über die normale erneute Zustellung (personenbezogene Daten werden aus aktuellen Daten neu aufgebaut). E-Mails, deren Inhalt der Datenschutz-Löschlauf bereits entfernt hat, werden nicht erneut versendet.
- **Verwerfen**: Status `cancelled` (endgültig; wird mit den normalen Aufbewahrungsfristen entfernt).

Beide Aktionen sind nonce-geschützt und landen im Audit-Log (`operations_retry`, `operations_discard`, Objekttyp `queue_jobs`/`queue_messages`/`queue_webhooks`, Details: Warteschlange, Anzahl, IDs).

## Multisite: Site löschen und Plugin deinstallieren

- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben; Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites.

## Zugangsdaten / Secrets

Zugangsdaten von Zahlungs- und Versanddiensten liegen **nicht** im Klartext in der allgemeinen Option `wpcap_settings`, sondern verschlüsselt in der Option `wpcap_secrets` (nicht autoloaded). Betroffen sind:

| Einstellung | Dienst |
|---|---|
| `stripe_secret_key`, `stripe_webhook_secret` | Stripe |
| `paypal_client_secret` | PayPal |
| `mollie_api_key` | Mollie |
| `twilio_auth_token`, `sms_http_secret` | SMS (Twilio, HTTP-Anbieter) |
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

**Fail closed:** Lässt sich ein gespeicherter Wert nicht entschlüsseln (Schlüssel geändert oder fehlt, Salts erneuert, Datenbank manipuliert), gilt der Dienst als nicht eingerichtet (z. B. erscheint Stripe nicht im Checkout, Webhooks werden mangels Signaturgeheimnis abgelehnt). Ein Admin-Hinweis nennt die betroffenen Einstellungen; die Felder zeigen „nicht entschlüsselbar – bitte neu eingeben“. Werte werden dabei nie protokolliert. Website-Zustand → Info zeigt im Abschnitt „WP Pass Captain – Zugangsdaten“ je Einstellung nur die Quelle (Konstante, verschlüsselt gespeichert, nicht entschlüsselbar, nicht gesetzt).

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
bash tests/restore/drill.sh /pfad/zu/wordpress /pfad/alt/wp-pass-captain /pfad/neu/wp-pass-captain /tmp/drill
```

Das Skript sichert die Datenbank vorher, arbeitet mit WP-CLI und `mariadb-dump` und stellt Datenbank und Plugin-Link am Ende wieder her – **nur auf einer Test-Installation ausführen**, es löscht während des Laufs alle Plugin-Tabellen.

## Release-Kandidat

Vor einer stabilen Version müssen Upgrade und Restore mit realistischen Datenbankständen, Eventänderungen, doppelten Webhooks, E-Mail-Ausfällen, hoher Scanrate und Multisite geprüft werden. Der ZIP-Build (`bash build/release.sh`) exportiert den committeten Stand per `git archive` (Standard `HEAD`, anders mit `WPCAP_BUILD_REF=<ref>`), installiert dort die Composer-Abhängigkeiten ohne Dev-Pakete und lässt alles weg, was in `.distignore` steht (Tests, Doku, Markdown außer `readme.txt`, `.po`-Quellen, Test-/CI-Dateien der Abhängigkeiten). Nicht committete Änderungen landen also nie im Paket. Der Build ist reproduzierbar: Dateizeiten werden auf die Commit-Zeit gesetzt (`SOURCE_DATE_EPOCH`), Rechte vereinheitlicht (644/755), Einträge sortiert und ohne Zusatzattribute gepackt, der Autoloader-Suffix ist fest. Neben dem ZIP entstehen `wp-pass-captain.zip.sha256` und `build-info.txt` (Commit, Zeitstempel, Prüfsumme, Prüfsumme der `composer.lock`); beide hängen auch am GitHub-Release. CI prüft mit `tests/reproducible-build.sh`, dass zwei Builds identisch sind.

**Abgleich veröffentlichtes ZIP ↔ Commit:** Release-ZIP und `.sha256` herunterladen, dann im Repository

```bash
WPCAP_BUILD_REF=v1.5.0 bash build/release.sh
```

```bash
sha256sum build/dist/wp-pass-captain.zip
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

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste und Terminbuchungen. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten und Terminbuchungen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Sperr-Push-Nachricht). Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Bestellungen mit Tickets für kommende Termine werden zurückgestellt (`wpcap_privacy_defer_upcoming`, Standard an): Die Tickets bleiben gültig, die Bestellung wird automatisch nach dem letzten Termin anonymisiert; die Löschung meldet die Anzahl. Soll sofort widerrufen werden, den Filter auf `false` setzen bzw. vorher erstatten/stornieren. Eigener Export/Löschung zusätzlich: Käuferlimit-Zähler (`wp-pass-captain-buyer-limits`).

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

**Spalten**:

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

**Abstimmung mit dem Umsatzbericht**: Die Summe `Brutto` (bzw. `USt`) aller Zeilen eines Zeitraums entspricht
„Umsatz nach Erstattungen“ (bzw. „davon USt“) des Berichts für denselben Zeitraum, sofern Rechnungen am Tag
der Bestellung und Rechnungskorrekturen am Tag der Erstattung ausgestellt wurden (automatische Rechnungen).
Abweichungen entstehen nur durch später ausgestellte Belege (Rechnung/Korrektur an einem anderen Tag).

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
