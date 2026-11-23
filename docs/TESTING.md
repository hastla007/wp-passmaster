# Tests und Qualitätstore

Dieses Dokument beschreibt alle Testebenen von Pass Captain: was sie prüfen, wie man sie lokal startet und
was die CI (`.github/workflows/*.yml`) davon automatisch ausführt. Welche Tests vor einem Release verbindlich
grün sein müssen, steht in [RELEASE.md](RELEASE.md); die unterstützten Plattformen in
[COMPATIBILITY.md](COMPATIBILITY.md).

## Überblick

| Ebene | Werkzeug / Einstieg | Lokal | CI (Workflow, Job) |
|---|---|---|---|
| Syntax PHP/JS, Composer-Metadaten | `php -l`, `node --check`, `composer validate` | ja | `ci.yml` `php` (PHP 8.5 blockierend, 8.6 experimentell) |
| Statische Analyse + Versionskonsistenz | phpcs (Baseline), PHPStan (Baseline), `composer audit`, `build/check-versions.sh` | ja | `ci.yml` `static`, blockierend |
| Lizenz-Wächter | `tests/guard/no-license-code.php` | ja | `ci.yml` `license-guard` und `package`, blockierend |
| Secret-Scan, Abhängigkeitsprüfung | gitleaks, `dependency-review-action` | ja (gitleaks) | `ci.yml` `secrets` und `package`, blockierend |
| Integrationssuite (echte DB) | `tests/integration/run.php` | ja | `ci.yml` `integration`: PHP 8.5 × MariaDB 12.3, MySQL 9.7 blockierend; PHP 8.6 × beide experimentell |
| Last-Smoke (Nebenläufigkeit) | `tests/load/run.php --profile=ci` | ja | `ci.yml` `integration`, gleiche Matrix, blockierend |
| Lasttests `default`/`heavy` | `tests/load/run.php` | ja | `load.yml` wöchentlich + manuell, MariaDB 12.3 / MySQL 9.7 |
| Lasttest über REST | `tests/load/http.php` | ja (Wegwerf-WordPress) | nein |
| Reproduzierbares ZIP, Paketinhalt | `build/release.sh`, `build/ci-install-test.sh` (statisch) | ja | `ci.yml` `package`, blockierend |
| Installationstest des ZIPs | `build/ci-install-test.sh <zip>` + `build/ci-smoke.php` | ja (Wegwerf-DB) | `ci.yml` `install-test` (WP aktuell/7.0, WooCommerce aktuell/11.2.2), blockierend |
| Update von 1.4.0 mit Daten | `WPCAP_IT_UPGRADE_FROM=<commit> build/ci-install-test.sh <zip>` + `build/ci-data.php` | ja (Wegwerf-DB) | `ci.yml` `install-test`, Bein „Update from 1.4.0“, blockierend |
| Deinstallation (Daten behalten / entfernen) | `build/ci-install-test.sh` (Schritt 5) | ja (Wegwerf-DB) | `ci.yml` `install-test`, alle Beine, blockierend |
| Multisite: Netzwerkaktivierung (30 Sites), Site löschen, Deinstallation mit gemischtem Opt-in | `build/ci-multisite-test.sh <zip>` + `build/ci-multisite.php` | ja (Wegwerf-DB) | `ci.yml` `install-test`, Bein „Multisite (30 sites)“, blockierend |
| Restore-Probe (Backup → Zerstörung → Restore → Diagnose) | `tests/drills/restore-drill.sh` | ja (Wegwerf-DB) | `drills.yml` wöchentlich + manuell, MariaDB 12.3 / MySQL 9.7 |
| WordPress.org Plugin Check | Plugin Check (neueste Version) gegen das ZIP, Abgleich mit `tests/plugin-check/baseline.json` (Frühwarnung für neue Prüfungen; Release-Gate mit gepinnter Version: `wp-matrix.yml` `plugin-check`) | – | `ci.yml` `plugin-check`, blockierend für Fehler außerhalb der Baseline |
| Release-Gate-Skript | `tests/release/verify-release.test.sh` | ja | **nein** (nur das Skript selbst läuft in `release.yml`) |
| JS-Unit-Tests | `tests/js` (`npm test`) | ja | `ci.yml` `php` (PHP 8.5), blockierend |
| Barrierefreiheit (Gate) | `tests/a11y/gate.sh` | ja (Wegwerf-WordPress) | `ci.yml` `browser` (Bein chromium), blockierend |
| Browser-E2E Desktop | `tests/e2e/run.sh` (`WPCAP_E2E_BROWSER`) | ja (Wegwerf-WordPress) | `ci.yml` `browser`: Chromium (+ WooCommerce Blocks), Firefox, WebKit, blockierend |
| Browser-E2E Mobil-Emulation | `WPCAP_E2E_DEVICE="Pixel 7"` / `"iPhone 14"` | ja | `ci.yml` `browser`: Pixel 7 (Chromium), iPhone 14 (WebKit) – Checkout, Portal, Offline-Kopplung, blockierend |

Nicht in CI: der Shell-Test des Release-Gates (`tests/release/verify-release.test.sh`), der erweiterte Lauf
`run-slow.sh` und alles mit echten Geräten, Zahlungsanbietern oder Screenreadern ([RELEASE.md](RELEASE.md)).

Stand 14.11.2026 (lokal, PHP 8.3, MariaDB 10.11): Integration **2303/2303**, JS **56/56**, E2E **11/11**
(Chromium, WooCommerce 11.3 für den Blocks-Test), Release-Gate-Test **28/28**, a11y-Gate bestanden (53 Seiten,
0 Verstöße), Lizenz-Wächter: 235 ausgelieferte Dateien ohne Befund.

Stand 14.11.2026, Browser-Matrix und Betriebsproben (lokal, WordPress 7.1, Playwright 1.63; Firefox 155 und
WebKit 26 aus dem offiziellen Playwright-Image über `playwright run-server`): E2E Chromium/Firefox/WebKit je
**10/10** (ohne WooCommerce-Blocks), Pixel 7 und iPhone 14 je **8/8**; Installationstest mit Update von 1.4.0
und Deinstallation bestanden; Restore-Probe bestanden auf MariaDB 10.11, MySQL 8.0 und 8.4.

## Gemeinsame Voraussetzungen

- PHP 8.5+ mit `mysqli`, `mbstring`, `sodium`, `gd`, `zip`, `openssl`; `composer install` (inkl. Dev-Paketen
  für die statische Analyse).
- Eine **Wegwerf**-Datenbank (MariaDB oder MySQL). Lokal z. B.:

  ```bash
  service mariadb start                     # falls nicht gestartet
  # Benutzer wp/wp mit Rechten auf die Testdatenbanken, Host 127.0.0.1
  ```

- Für Browser-Suiten zusätzlich Node ≥ 20, Playwright + Chromium (wird bei Bedarf von
  `tests/ci/browser-tests.sh` installiert) und Git-Zugriff auf github.com für den WordPress-Core
  (oder `WPCAP_WP_CORE=/pfad/zu/wp-core`).

## Integrationssuite (`tests/integration/`)

Läuft gegen eine echte Datenbank mit nachgebauter WordPress-, WooCommerce- und Anbieter-API
(`bootstrap.php`); die Module unter `tests/integration/modules/` (53 Dateien) decken Bestellungen,
Reservierungen, Zahlungen/Webhooks, Erstattungen, WooCommerce-Adapter, Servicebuchungen, Wartelisten,
Preisregeln, Käuferlimits, Steuerprofile, Vault/Redaktion, Berechtigungen aller REST-Routen und
Admin-Aktionen (`zz_w5_permissions.php`), Multisite, Datenschutz u. a. ab. Tabellen werden mit dem Präfix
`wptest_` bei jedem Lauf neu angelegt.

```bash
php tests/integration/run.php
WPCAP_TEST_DB_NAME=wp_w13 php tests/integration/run.php   # eigene DB, z. B. für parallele Arbeitskopien
tests/integration/run-slow.sh                             # erweiterter Lauf (WPCAP_TEST_SLOW=1), einige Minuten
```

Stand 13.11.2026: rund 2.280 Prüfungen, 0 Fehler (CI: Pflichtschritt). Mehrere Läufe parallel auf derselben
Datenbank brauchen getrennte Tabellenpräfixe: `WPCAP_TEST_PREFIX=wpx1_ php tests/integration/run.php`.
Zusätzlich in CI: `node tests/offline-scanner-i18n.js` (Übersetzungskataloge der Offline-PWA) und
`bash tests/release-guard.sh`.

Die Modultests setzen Ratenlimits zurück, indem sie die Transients leeren. Deshalb läuft `RateLimit` in
der Suite auf dem Backend `transient` (`WPCAP_TEST_RATE_BACKEND`, Standard `transient`).
`modules/zz_a186.php` prüft die atomaren Backends `db` und `cache`. Dazu startet der Test parallele
PHP-Prozesse (`modules/a186/ratelimit_child.php` mit `WPCAP_TEST_RATE_BACKEND=db`) und prüft, dass das
Limit genau eingehalten wird. Außerdem prüft er die Auswertung der Proxy-Header. `modules/zz_a184.php`
spielt doppelte und in falscher Reihenfolge eintreffende Zahlungsereignisse für alle Zahlungsadapter
durch (siehe `docs/STATE-MACHINES.md`).

Variablen: `WPCAP_TEST_DB_HOST`, `_USER`, `_PASS`, `_NAME` (Standard `127.0.0.1`/`wp`/`wp`/`wp`). Ausgabe
`ok`/`FAIL` je Prüfung, am Ende `N passed, M failed`; Exit-Code ≠ 0 bei Fehlern.

`run-slow.sh` setzt `WPCAP_TEST_SLOW=1`: alle Positionen der Fault-Matrizen statt 24, Datenschutz-Volumentest mit
5.000 statt 400 Bestellungen, zehnfache Zahl der Geld-Eigenschaftsfälle und 300 statt 60 Zufallsfolgen der
Zustandsautomaten. Geeignet als nächtlicher Lauf; derzeit in keinem Workflow eingebunden.

### Robustheit, Zustandsautomaten und Randfälle (`tests/integration/modules/zzzz_w6_*.php`)

| Modul | Inhalt |
|---|---|
| `zzzz_w6_faults.php` | Fault-Matrix: jede SQL-Anweisung von Checkout, kostenlosem Checkout, Zahlungs-Webhook, manueller Zahlung, Storno, nativer/externer Erstattung, Check-in (Einfach-/Mehrfacheinlass), Offline-Sync und Kassenverkauf scheitert einmal – als Fehler, Deadlock 1213 (Server-Rollback), Lock-Wait-Timeout 1205 und Verbindungsabbruch 2013. Invarianten: keine Erfolgsantwort ohne persistierten Zustand, kein Halbzustand, sofortiger Retry bestätigt nichts Unpersistiertes, Recovery + Retry endet im fehlerfreien Ergebnis. Standard: 24 Positionen je Ablauf, `WPCAP_TEST_SLOW=1`: alle. |
| `zzzz_w6_state_machines.php` | Zustandsautomaten aus [STATE-MACHINES.md](STATE-MACHINES.md): doppelte, verspätete und out-of-order Ereignisse; seeded Zufallsfolgen prüfen jeden Übergang gegen die erlaubten Übergänge. |
| `zzzz_w6_workers.php` | Jobs, E-Mail-Queue, Webhook-Zustellung: abgelaufene Claims, Absturz im Handler (Kindprozess), Poison-Items, Backoff, Dead-Letter, Duplikate, dauerhafter Claim. |
| `zzzz_w6_upgrade.php` | Upgrade von 1.0/1.2- und 1.3-Schema (`tests/integration/fixtures/schema-*.sql`) mit Daten: wiederholt, parallel (2 Prozesse), nach Prozessabbruch; WordPress-ähnliches `dbDelta()` im Kindprozess (`workers/w6_upgrade_child.php`). |
| `zzzz_w6_isolation.php` | Statische Prüfung: keine direkten WooCommerce-/HPOS-/postmeta-Abfragen, kein fester oder netzwerkweiter Tabellenpräfix; zwei Sites mit eigenem Präfix ohne Übersprechen (auch Request-Caches). |
| `zzzz_w6_time.php` | Europe/Vienna 29.03./25.10.2026 und Mitternacht: Serien, Verkaufsfenster, Hold-Ablauf, Einlass-Tagesgrenze, Erinnerungen, Service-Slots – mit PHP-Standardzeitzone UTC und Europe/Vienna. |
| `zzzz_w6_money.php` | Seeded Eigenschaftstests: exakte Aufteilung, JPY/EUR/KWD, Rabatte (auch ticketartgebunden), Steuern, Rechnungen, Teil-Erstattungen, WooCommerce-Summen – centgenau. |
| `zzzz_w6_report_cache.php` | Invalidierung des Berichts-Caches bei Zahlung, Check-in, Erstattung, Storno, Event-/Termin-/Ticketartänderung. |
| `zzzz_w6_contracts.php` | Vertragskompatibilität: Voucher-Bridge v1, Affiliate-Adapter 1.x, Scanner-API (unbekannte/fehlende Felder, Versionen, Timeouts, Duplikate). |
| `zzzzz_x2_payments.php` | Zahlungs-/Erstattungs-Restpunkte: WooCommerce-Erstattungsspiegel und Affiliate-Verkauf mit Retry-Job (idempotent, Dead-Letter, Systemstatus), Währungen mit 0/3 Nachkommastellen in Berichts-, Buchhaltungs-CSV, Fiskal-Client und Stripe (3-stellige Beträge nur in Zehnerschritten), Käuferlimit-Prüfung bei verspäteter Zahlung, verschlüsselte Auszahlungsdaten der Veranstalter (Migration, Maskierung, Export nur für Finanzen). |
| `zzzzz_x5_addon_hooks.php` | Add-on-Schnittstellen ([EXTENSIONS.md](EXTENSIONS.md#pass-captain-pro--add-on-schnittstellen)): Admin-Menü-Action, White Label (Name, Logo, Menü, gettext, Offline-Scanner-Manifest), Rechnungs-Action und Lese-API (Rechnung, Korrektur, Abrechnung), Mail-Anhänge und Links, Checkout-Schranke (nativ, REST, Servicebuchung, WooCommerce, Ausnahmen, fail open), API-Scopes und `Api\Auth::request_can()` (401/403/429); jeweils auch: ohne Callback unverändert. |
| `zzzzzz_y1_security.php` | Audit-Panel 2026-11-15, Sicherheit & Zugriff: Scope-Matrix aller schreibenden, per Capability geschützten Routen mit Objekt-IDs (Personal von Event A gegen IDs von Event B, neue Routen ohne Fall scheitern), Passregeln REST/Admin, CSV-Injection, Entwickler-Keys an Rolle gebunden und bei Löschung/Rollenwechsel widerrufen, Checkout-Antwort ohne IDs, Wartelisten-Limits (IPv6 /64), Header privater Seiten, Array-Parameter, Check-in-Replay ohne Zugriff. |
| `zzzz_w6_privacy_volume.php` | Export/Löschung/Retention mit 400 (langsam: 5.000) Bestellungen, kommenden Terminen, Rechnungen, WooCommerce-Bestellungen und zweiter Site; Zeitbudget, Finanzbelege bleiben. |
| `zzzzzz_z4_csp.php` | Audit-Panel 2026-11-15, Paket Z4 (FE-8): strikte CSP der vollständig vom Plugin ausgegebenen Seiten (Ticketseite, Rechnungen, Einwilligungsseite; Filter, Header-Injection), keine Inline-Event-Handler in `src/`, Druck-Buttons über `assets/print.js`, Admin-Bestätigungen über `data-wpcap-confirm`. |
| `zzzzzz_z4_sms_otp.php` | Audit-Panel 2026-11-15, Paket Z4 (PRIV-16): SMS-Opt-in nur mit per Code bestätigter Nummer – Code-Anforderung (Nummernprüfung, Same-Origin, Limits je Nummer/IP/Site, Wartezeit), nur Hashes gespeichert, 5 Versuche, Ablauf 10 min, einmalige Verwendung im Checkout, Nachweis (verified_at, Audit), unbestätigte Opt-ins ohne SMS, Altbestand gültig, Export, Löschung nach einem Tag, Upgrade. |
| `zzzzzzz_r8_abuse_arch.php` | Audit Runde 2, Paket R8: Checkout-Limit hinter Proxy/CDN (Erkennung, Aussetzen/Anheben, Systemstatus), Rückbuchung bei „ausverkauft“, IPv6-/64, unbezahlte Bestellungen je Netz, E-Mail-Aliase (PHP und SQL), Übernahme der Altkopie im selben Request, Downgrade-Erkennung, Deinstallation großer Netzwerke, `Orders\PortalAccess`. Der Hook-Vertrag mit Argumentzahl steht in `hooks_contract.php`. |
| `zzzzzzz_r4_scanner.php` | Audit Runde 2, Paket R4 (Experten #6/#3): atomare Idempotenzschlüssel (parallele Prozesse über `workers/r4_worker.php`: Mehrfachticket zählt 1 Einlass, Einzelticket-Retry bekommt das Original-OK, Offline-Upload ohne Scheinkonflikt; Replay bevorzugt den Einlass; Claim-Tabelle und Bereinigung), Mobile-API-Retry als HTTP 503, Undo nur mit Schlüssel (idempotent), Undo-Zeitfenster/-Rollen/eigenes Gerät, Konfliktbericht (Doppel-Einlässe geräteübergreifend, Undos pro Ticket), `first_sync_wins` nur bei gleichem Gerät, Rückdatierung ohne Einlassregel, Web-Upload höchstens 1 h, Offline-Paket Format 2 (Zähler je Ticketart, Kompaktdelta) und gzip. |
| `zzzzzz_y4_privacy.php` | Audit-Panel 2026-11-15, Paket Y4 (Datenschutz): Fristlöschung nach gesetzlicher Frist, Wartelisten, Terminbuchungen, Pässe, SMS, Einlass, Gerätebezeichnungen; Rechnungsanschrift unbezahlter Bestellungen; Einwilligungen nur für die betroffene Person; Wallet-Aktualisierung ohne Namen; Maskierung der Wartelisten für Support; WooCommerce-Teilnehmerkopie; schlüsselbasierte Rate-Limit-Hashes; Redaktion von Telefon/IBAN/IP; Exporter-/Löscherlücken; Affiliate-Cookie ohne Consent-API; Deinstallations-Bestätigung; Webhook-Altzustellungen; Partner-API-Audit. |

Gemeinsamer Fehler-Proxy für `$wpdb`: `tests/integration/lib/fault_wpdb.php` (`wpcap_faults()`,
`->fail( $regex, $skip, $times, $kind )`, `->fail_nth( $n, $kind )`, `wpcap_faults_restore()`).

### Eingabe↔Schema-Verträge wie in echtem WordPress (A498)

Die Testlaufzeit bildet die Stellen nach, an denen sich WordPress anders verhält als einfache Stubs:

- **Bereinigung:** `sanitize_text_field()`, `sanitize_textarea_field()`, `sanitize_key()`, `sanitize_email()`,
  `is_email()`, `wp_strip_all_tags()` und `wp_check_invalid_utf8()` kommen aus `tests/integration/wp-sanitize.php`
  – portiert aus WordPress (ein einzelnes „<“ wird `&lt;`, ungültiges UTF-8 wird `''`, Prozent-Oktette und
  Zeilenumbrüche verschwinden, `sanitize_email( 'kein-mail' )` ist `''`, `is_email()` kennt keine Längengrenze,
  ein Array in `sanitize_email()` ist ein `TypeError`). `php tests/integration/sanitize-parity.php` vergleicht
  jede Funktion auf einem Fuzz-Korpus mit dem echten WordPress (`WPCAP_TEST_WP_DIR` oder der Download von
  `tests/upgrade`); in CI läuft der Vergleich nach den Upgrade-Tests mit `--require`.
- **Datenbank:** `Test_WPDB::insert()`/`update()` lehnen wie `wpdb::process_fields()` jeden `%s`-Wert ab, der kein
  gültiges UTF-8 oder länger als seine Spalte ist (`varchar`: Zeichen, `text`: Bytes), mit der Fehlermeldung von
  WordPress – unabhängig vom SQL-Modus (MySQL/MariaDB ohne Strict-Modus würden still kürzen). Ein Statement mit
  ungültigem UTF-8 wird wie in `wpdb::query()` gar nicht gesendet. Abgelehnte Felder sammelt
  `$GLOBALS['wpcap_test_field_errors']`, wenn ein Test es als Array setzt.
- **Fuzzing:** `modules/zz_a498.php` schickt Listen, Objekte, Zahlen, Wahrheitswerte, `null`, 191/300 Zeichen,
  70 000 Bytes, ungültiges UTF-8, ein 4-Byte-Zeichen an der 190er-Grenze, „<“, Entities und Prozent-Oktette
  durch alle öffentlichen Eingänge (Checkout direkt und per REST, Kampagnenvorschau, Übertragung,
  Zuweisung, Stornierungsanfrage, Rechnungsdaten, Warteliste, Servicebuchung) und verlangt: keine PHP-Warnung,
  kein 5xx, kein Wert, der nicht ins Schema passt, erreicht die Datenbank.

### Fault-Injection: Deadlock, Lock-Timeout, Verbindungsabbruch, COMMIT-Fehler (A183)

Das `$wpdb` der Integrationstests (`tests/integration/bootstrap.php`, `Test_WPDB`) kann einzelne
Statements gezielt scheitern lassen, je Test umschaltbar:

```php
wpcap_test_fault( '/^INSERT INTO \S+wpcap_tickets/', 'deadlock', array( 'skip' => 1 ) ); // zweites Ticket-INSERT
$result = PassCaptain\Orders::create( … );
$fired  = wpcap_test_faults_clear(); // Statements, die gescheitert sind
```

| Art | Verhalten wie der Server |
|---|---|
| `deadlock` (1213) | Statement scheitert, die ganze Transaktion wird zurückgerollt. |
| `lock_timeout` (1205) | Nur das Statement scheitert, die Transaktion läuft weiter (`innodb_rollback_on_timeout=OFF`); mit `rollback => true` wie `ON`. |
| `lost` (2013) | Verbindung bricht während des Statements ab: Transaktion zurückgerollt, Sperren frei, Statement scheitert; das nächste läuft auf einer neuen Sitzung (Autocommit). Mit `executed => true` lief das Statement noch (z. B. ein COMMIT, dessen Antwort verloren ging). |
| `gone_away` (2006) | Wie WordPress' `wpdb`: neu verbinden und das Statement auf der neuen Sitzung wiederholen – es gelingt, aber außerhalb der verlorenen Transaktion. |

`tests/integration/modules/zz_a183.php` fährt damit die kritischen Pfade systematisch ab: Checkout
(bezahlt mit Reservierung, kostenlos mit Ticketausgabe), Zahlungs-Webhook (aktive und abgelaufene
Reservierung), Check-in, Erstattung, Wartelisten-Annahme und Job-Entnahme. Je Fehlerart läuft der Pfad
einmal pro Statement, das er ausführt, und genau dieses Statement scheitert (Sperrfehler nur bei
schreibenden und sperrenden Statements und COMMIT, Verbindungsabbruch bei jedem). Danach muss gelten:
keine offene Transaktion, nichts halb geschrieben (Bestellung mit allen Positionen und Reservierungen,
Tickets vollständig oder gar nicht, Erstattung ganz oder gar nicht verbucht), kein Kontingentzähler
unter seinen Quellsummen (sonst Überverkauf), ein Fehler an den Aufrufer ist wiederholbar (503,
`retryable`) statt „ausverkauft“/„nicht gefunden“; eine Wiederholung ohne Fehler (gleicher
Idempotenzschlüssel, erneute Webhook-Zustellung, Folgelauf der Jobs) erreicht denselben Endzustand wie ein
ungestörter Lauf, genau einmal. Zusätzlich je Pfad: jeder COMMIT geht durch, aber seine Antwort geht
verloren. Laufzeit des Moduls etwa 40 Sekunden; `A183_VERBOSE=1` listet jede Verletzung einzeln.

### Worker-Fehler und Neustarts (A188)

`tests/integration/modules/zz_a188.php` prüft Backoff-Zeiten, Dead Letter nach dem letzten Lauf
(Audit, Hook, Betriebsseite), abstürzende Handler, vier parallele Worker auf denselben Jobs, einen Worker,
der nach der Wirkung und vor dem Abschluss stirbt, und den Neustart nach einem Absturz (Zuteilungen
älter als 15 Minuten). Die parallelen bzw. sterbenden Worker sind eigene PHP-Prozesse
(`tests/integration/job_worker.php race|die`).

Zeitabhängige Regeln testen die Module ohne Warten: Sie setzen die Uhr über den Filter `wpcap_now`
(`PassCaptain\Clock`, im Test `$GLOBALS['wpcap_test_filters']['wpcap_now'] = $timestamp`). `modules/zz_a199.php`
prüft so die Sommerzeitumstellungen in Europe/Vienna und America/New_York (siehe docs/OPERATIONS.md,
„Zeit, Zeitzonen und Sommerzeit“).

**Grep-Regel (A607):** Geschäftsregeln lesen die Zeit nur über `Clock::now()`/`Clock::mysql()`, nie über
`time()` oder `current_time( 'mysql', true )`. `modules/zz_a607.php` prüft das: Dateien außerhalb von
`modules/zz_a607/time-allowlist.txt` (noch nicht umgestellte Dateien) dürfen beides nicht verwenden; wer
eine Datei umstellt, streicht sie aus der Liste (der Test meldet Einträge, die nicht mehr nötig sind).

### Blöcke und Block-Editor (A455)

- **Editor-Skripte** (`tests/js/blocks.test.js`, `npm run test:js`): lädt `src/blocks/*/editor.js` mit einer Attrappe der `wp.*`-Pakete (`createElement` baut einen einfachen Baum) und prüft je Block den registrierten Namen gegen `block.json`, dass jedes verwendete Attribut deklariert ist und `save()` `null` liefert (dynamischer Block), die Vorschau in `Disabled` (A451), die Event-/Terminauswahl einschließlich nicht mehr gelisteter Auswahl (A453) sowie die Tauglichkeit für den Iframe-Editor: kein Zugriff auf `document`, nur `window.wp` und `window.WPCAP_BLOCK_*`, genau ein Element mit den Props aus `useBlockProps()` und die Vorschau darin.
- **Blockvalidierung und Rendern** (`modules/zz_a455.php`): jedes Verzeichnis unter `src/blocks/` hat ein gültiges `block.json` mit gespeichertem Namen (RENAME.md), `apiVersion` 3 (der Editor wird nur als Iframe geladen, wenn alle Blöcke v3 sind), Titel/Kategorie/Icon/Textdomain, vorhandene `render.php`/`editor.js`/`editor.asset.php`, gültige Attributtypen und passende Standardwerte, `supports.html = false` und eine Registrierung per `register_block_type()`. Gerendert wird ohne globalen Beitrag und ohne Request-URL mit leeren bzw. veralteten Attributen – so wie in Widget-Bereichen und Template-Teilen.
- **Website-Ausgabe** (`modules/zz_a449.php`, `zz_a452.php`): Block-Wrapper-Attribute am äußeren Element; Monatsnavigation aus der Request-URL mit eigenem Parameter je Kalender.
- **Asset-Versionen** (`tests/consistency.php`, A454): Version = Inhalts-Hash von `editor.js`, Abhängigkeiten decken alle `window.wp.*`-Pakete ab.
- **Öffentliche Add-on-API** (`tests/consistency.php`, A663): Jeder Hook in `PassCaptain\PublicApi::HOOKS` wird in `src/` verwendet und steht mit dem richtigen Typ in der Tabelle „Öffentliche Add-on-API“ von `docs/HOOKS.md`; die Tabelle nennt keinen Hook außerhalb der Liste, und die Version stimmt mit `docs/CONTRACTS.md` überein. Verhalten der Hooks: `tests/integration/modules/zz_a331.php`, `zz_a663.php`.

Offen (Produktentscheidung, A455): eigene Event-URLs bzw. Event-Einzelseiten mit Theme-Template gibt es nicht; Events erscheinen nur über Shortcode oder Block auf normalen Seiten. Ob ein öffentlicher Beitragstyp/Rewrite für Events kommt, ist zu entscheiden (Auswirkungen auf SEO, Seitencache-Purge `wpcap_purge_page_cache`, Kalender-Links und Übersetzungen).

Wartelisten-Eintragungen brauchen im Plugin standardmäßig die Double-Opt-in-Bestätigung (A564). Der
Test-Bootstrap setzt `$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = false`, damit die
Suites, die nicht davon handeln, direkt eintragen; `modules/zz_a564_a565.php` hebt das auf und prüft den
Bestätigungsablauf. Die a11y-Fixture schaltet die Bestätigung per `add_filter()` ab.

## Upgrade-Tests je Vorgängerversion (`tests/upgrade/`, A181)

```bash
php tests/upgrade/run.php   # Wegwerf-Datenbank, Git-Historie mit Tags, wp-cli (oder WPCAP_TEST_WP_DIR=/pfad/zu/wordpress)
```

Die normalen Integrationstests können kein Upgrade prüfen (ihr `dbDelta`-Ersatz legt Tabellen neu an). Dieser
eigene Lauf verwendet das echte `dbDelta()` aus einem WordPress-Download (`wp core download` nach
`tests/upgrade/.wordpress/`, Version über `WPCAP_TEST_WP_VERSION`), Optionen in einer Datenbanktabelle (wie
WordPress mit Cache je Prozess, damit parallele Prozesse sie teilen) und eigene Tabellenpräfixe
(`WPCAP_UPGRADE_PREFIX`, Standard `wpupg_`, plus Kürzel je Szenario; vorher und nachher gelöscht,
`WPCAP_UPGRADE_KEEP=1` behält sie).

Vorgängerstände: jedes Git-Tag `v*`, der Commit vor der letzten Änderung von `Installer::DB_VERSION` auf der
eigenen Linie (`--first-parent`; ist diese Änderung ein Merge, zusätzlich die Spitze jeder hineingemergten
Linie, z. B. Schema 3.10.27 des „theirs“-Strangs von 1.5) und weitere Refs aus `WPCAP_UPGRADE_REFS`
(kommagetrennt). Jeder Stand wird von seinem **eigenen** Code angelegt
(`git archive` des Tags, dessen Installer und Test-Bootstrap-Verzeichnis; `tests/upgrade/legacy.php`), jede Tabelle mit
drei Zeilen gefüllt (alle Spalten belegt, dazu eine bezahlte Bestellung und eine aktive Reservierung), in den
Einstellungen ein Klartext-Zugangsdatum und kein Gutschriftpräfix. Dann führt `tests/upgrade/worker.php`
`Installer::maybe_upgrade( 'cron' )` des aktuellen Codes aus. Geprüft wird je Stand:

- Tabellen, Spalten (Typ, NULL, Vorgabe, Extra, Kollation) und Indizes gleichen einer Neuinstallation
  (`information_schema`; die Spaltenreihenfolge zählt nicht, `dbDelta` hängt neue Spalten hinten an);
- Schema-Version und `wpcap_db_version_max` aktuell, Version genau einmal geschrieben;
- alle vorhandenen Zeilen unverändert (alte Spalten; ausgenommen der abgeleitete Cache `wpcap_alloc_buyers`
  und die Minutenzähler `wpcap_rate_limits`). Gewollte Umwandlungen werden in ihrer neuen Form verglichen:
  in place verschlüsselte Spalten (`ENCRYPTED_COLUMNS`: Webhook-Secrets, Partner-Auszahlungsdaten) müssen
  Chiffrat sein und zum alten Wert entschlüsseln (`worker.php decrypt`), umbenannte Spalten
  (`RENAMED_COLUMNS`: Kassen-`register_id` → `fiscal_register_id` der Audit-Linie) gleichen dem neuen Namen;
- einmalige Datenmigrationen genau einmal (Protokoll aller Optionsschreibvorgänge): Gutschriftpräfix „GS“
  festgeschrieben (Stände vor 3.8.3 und Stände der Audit-Linie), Zugangsdaten in den Tresor verschoben (genau
  ein Audit-Eintrag `secrets_moved_to_store` oder `secrets_migrated`, nur Namen), Kontingentzähler neu
  aufgebaut und ohne Abweichung, Kassen-, Partnerabrechnungs- (A448) und Käuferlimit-Migration;
- ein zweiter Upgrade-Lauf ändert nichts (`SHOW CREATE TABLE`, Optionen).

Zusätzlich mit dem ältesten Tag: Abbruch mitten im Schema (Ausnahme bei der dritten DDL-Anweisung) und mitten
in den Datenmigrationen (vor dem Schreiben des Tresors) – die Sperre ist danach frei, die Version unverändert,
der nächste Lauf vervollständigt das Upgrade (der Zählerneuaufbau läuft dann zweimal, er ist idempotent);
sowie zwei gleichzeitig gestartete Upgrade-Prozesse (eigene Datenbankverbindungen, verlangsamte DDL): genau
einer führt das Upgrade aus, der andere überspringt es ohne Fehler, während das Upgrade läuft.

Gefundene und behobene Fehler (13.11.2026): Upgrades von 1.1.0/1.2.0 behielten den abgelösten Index
`wpcap_order_items.type_order`, alle Stände bis 1.4.0 den vollen Index auf `wallet_registrations.push_token`
statt des Präfixindex (191) – `dbDelta` ändert und löscht keine Indizes. Behoben mit
`Installer::upgrade_indexes()` (Schema 3.10.4, siehe OPERATIONS.md). Lauf lokal (MariaDB, WordPress 7.1.4):
123 Prüfungen, 0 Fehler, 15–25 Sekunden. Nach dem Merge der beiden 1.5-Linien (16.11.2026, Schema 3.18.0):
Upgrades von der Audit-Linie (3.17.7) behielten die Vorgabe 600 für `wpcap_api_keys.rate_limit` (neu 0 =
Standardlimit), weil `dbDelta` Vorgaben nur in Anführungszeichen vergleicht – behoben mit `DEFAULT '0'`.
146 Prüfungen, 0 Fehler (Tags 1.1.0–1.4.0, Audit-Linie 3.17.7, „theirs“-Linie 3.10.27). CI: Pflichtschritt im Job `integration` (alle DB-/PHP-Kombinationen,
volle Git-Historie).

## Kern läuft allein (A664)

`tests/integration/modules/zz_a664.php` (Teil der Integrationstests): Der ausgelieferte Kern kennt das Add-on
nicht – kein Code (Zeichenketten, Klassen- oder Funktionsnamen; Kommentare zählen nicht) verweist auf
`pass-captain-pro`, `PassCaptainPro`, WP License Captain oder `wplc`, und der Plugin-Header verlangt kein
anderes Plugin (`Requires Plugins`). Danach laufen die Kernabläufe ohne Add-on und **ohne Netzwerk** (jede
ausgehende Anfrage wird über `pre_http_request` protokolliert und abgelehnt): kostenloser und kostenpflichtiger
Checkout, Zahlungsbestätigung mit Ticketausgabe, Mail und PDF, Übertragung, Erstattung, Check-in, Terminänderung,
Event-Absage, Jobs, Nachrichten-Queue, Webhooks und Workflow-Outbox (ohne Provider). Der Test verlangt, dass
dabei keine einzige HTTP-Anfrage entsteht – also auch keine an einen Lizenzserver.

## Kein Lizenz- oder Updater-Code im Kern (A661)

**Wächtertest** (CI-Job `php`, nach der Konsistenzprüfung):

```bash
php tests/license-guard.php --self-test
```

Das Kern-Plugin bleibt frei (Entscheidung vom 13.11.2026, siehe [FREE-PREMIUM.md](FREE-PREMIUM.md)). Der Test
schlägt fehl, sobald in einer **ausgelieferten** Datei auftaucht: der Lizenzclient `LicenseClient`, die Route
`wplc/v1`, ein Plugin-Header `Update URI`, Updater-Hooks als Zeichenkette (`pre_set_site_transient_update_plugins`,
`site_transient_update_plugins`, `plugins_api`, `plugins_api_result`, `update_plugins_{host}`) oder
`allows_entitlement`. „Ausgeliefert“ bestimmt `tests/shipped-files.php` wie `build/release.sh`: Dateien, die
git kennt (versioniert oder neu, nicht ignoriert), abzüglich `.distignore` (rsync-Semantik), dazu aus `vendor/`
nur die Laufzeitpakete aus `composer.lock` (`packages`, nicht `packages-dev`). `tests/`, `docs/`, Markdown und
Entwicklungsabhängigkeiten werden also nicht durchsucht – dort darf das Add-on beschrieben werden.
`--self-test` prüft vorher an einem Beispielbaum, dass jede Regel anschlägt und die Ausschlüsse greifen.
Lizenzprüfung, Rechte und Updater gehören ins Add-on `pass-captain-pro`.

## Tabellenpräfixe und Isolation (A182)

**Statische Prüfung** (CI-Job `php`, vor PHPCS):

```bash
php tests/sql-prefix-check.php --self-test   # src/, templates/, uninstall.php, pass-captain.php
```

Setzt jede PHP-Zeichenkette mit dem Tokenizer zusammen (Interpolationen und angehängte Ausdrücke gelten als
dynamisch) und prüft in SQL-Zeichenketten:

- an Tabellenstellen (nach `FROM`, `JOIN`, `INTO`, `UPDATE`, `TABLE`, `DESCRIBE`) steht ein dynamischer Name
  (`{$wpdb->prefix}wpcap_…`, `{$wpdb->posts}`, `$table`, Platzhalter) oder eine Unterabfrage, nie ein
  wörtlicher Tabellenname;
- kein fest verdrahtetes `wp_`;
- keine direkten Abfragen auf WooCommerce-Bestelltabellen: `wc_orders`, `wc_orders_meta`, `wc_order_*`,
  `woocommerce_order_items`/`_itemmeta` und Beiträge vom Typ `shop_order*` (auch als Argument eines
  `$wpdb->…()`-Aufrufs oder als bloßer Tabellenname wie in `$wpdb->prefix . 'wc_orders'`). Bestellungen laufen
  über die WooCommerce-CRUD-API (HPOS und Beitragsspeicher, siehe WOOCOMMERCE.md).

Ausnahmen nur über die Allow-List `WPCAP_SQL_ALLOW` im Skript (Datei => Teiltext => Begründung; derzeit leer).
`--self-test` prüft die Regeln vorab an eingebauten Positiv- und Negativbeispielen. Stand 13.11.2026: 227
Dateien, rund 1 600 SQL-Zeichenketten, keine Befunde.

**Integrationstest** `tests/integration/modules/zz_a182.php`: zwei Sites nebeneinander mit den Präfixen
`<p>` und `<p>2_` (wie `wp_` und `wp_2_` im Netzwerk), gleichem Schema und **gleichen IDs** für Event, Termin
und Ticketart. Der Seitenwechsel bildet `switch_to_blog()` nach (Präfix, Optionen und Transients je Site,
Aktion `switch_blog`), ohne Caches des Plugins zu leeren. Geprüft: Verfügbarkeit, Veranstaltungsland
(Steuer), Schema-Bereitschaft, Upgrade-Sperre und Schema-Version je Site; Bestellung, Zahlung, Tickets und
Einlass nur auf der eigenen Site (fremder Ticketcode unbekannt, fremde Bestell-ID nicht sichtbar); gleicher
Idempotenzschlüssel auf beiden Sites ergibt zwei Bestellungen; Einstellungen, Audit-Log und Job-Warteschlange
je Site; Erstattung auf A ändert B nicht; Kontingentzähler beider Sites ohne Abweichung; beim Löschen einer Site
werden nur deren Tabellen entfernt.

Gefundene und behobene Fehler: Der Verfügbarkeits-Zwischenspeicher je Anfrage (`Orders::availability()`), der
Steuerprofil-Cache (`Tax\Profiles`, u. a. Veranstaltungsland je Termin-ID) und die Schema-Bereitschaft
(`OrderLines::ready()`, `Tax\Profiles::ready()`) waren nicht an die Site gebunden – nach `switch_to_blog()`
lieferten sie Werte der vorherigen Site für dieselbe ID.
A517 ergänzt die übrigen Anfrage-Caches ohne Präfix im Schlüssel (Teamzuweisungen `StaffAssignments`, Ressourcen von
Leistungserbringern, aktive Rabattcodes `Campaigns`, Hilfscodes, archivierte Rechtstexte, Serienpreise,
Partnerkonditionen, Umtausch-Nachbuchungen): `Plugin::site_cache_flushers()` leert sie bei `switch_blog`
(`modules/zz_a517.php`). Ein neuer solcher Cache gehört in diese Liste.

## Multisite: Cross-Site-Tests (A479)

Ein Subdirectory-Netzwerk (`example.org/a/`, `example.org/b/`) teilt Benutzer, Origin und Browserspeicher.
Die Integrationstests simulieren es mit einer zweiten Site als Tabellenpräfix `<präfix>n2_` (gleiches Schema per
`CREATE TABLE … LIKE`) und Attrappen in `tests/integration/bootstrap.php`: `$GLOBALS['wpcap_test_multisite']`
(`is_multisite()`), `$GLOBALS['wpcap_test_blog_id']` (`get_current_blog_id()`), `$GLOBALS['wpcap_test_blog_members']`
(`is_user_member_of_blog()`), `$GLOBALS['wpcap_test_blog_prefixes']` (`$wpdb->get_blog_prefix()`),
`get_user_option()`/`update_user_option()` mit Site-Präfix und `$GLOBALS['wpcap_test_user_caps']` (`user_can()` je
Benutzer). Abgedeckt:

| Pfad | Test |
|---|---|
| Zugriffsbereich je Site, Upgrade-Kopie des netzwerkweiten Bereichs (A470) | `modules/zz_a470.php` |
| Personalzuweisung nur für Site-Mitglieder mit Pass-Captain-Rolle, einheitliche Ablehnung (A471, A636) | `modules/zz_a471.php` |
| Speicherschlüssel des Web-Scanners je Site/Benutzer (A472) | `modules/zz_a479.php` |
| Offline-Warteschlange zweier Sites in einem `localStorage` (A472) | `tests/js/scanner.test.js` |
| Offline-PWA: Datenbank und Kopplung je API-Basis, Altbestand (A473) | `tests/js/offline-scanner-sites.test.js` |
| Tabellen, Caches und Optionen zweier Sites (A182) | `modules/zz_a182.php` |

## Last- und Nebenläufigkeitstests (`tests/load/`)
### Ohne Webserver: `tests/load/run.php`

Nutzt den Integrations-Bootstrap mit echter Datenbank. Der Koordinator legt Testdaten an und startet
N PHP-Prozesse (je eine DB-Verbindung, `proc_open`), die hinter einer Startbarriere gleichzeitig loslaufen.
Tabellenpräfix `wpload_` (über `WPCAP_TEST_PREFIX` änderbar), wird bei jedem Lauf neu angelegt.

```bash
php tests/load/run.php --profile=ci        # 200 Käufer / 20 Verbindungen, ~10 s (CI-Smoke)
php tests/load/run.php                     # default: 500 Käufer, Kontingent 150, 50 Verbindungen
php tests/load/run.php --profile=heavy     # 1000 Käufer, 100 Verbindungen
php tests/load/run.php --buyers=800 --capacity=200 --workers=80 --scenario=checkout --json=out.json
php tests/load/run.php --profile=ci --scenario=mixed --isolation=READ-COMMITTED   # Sitzungen unter READ COMMITTED
```

| Szenario | Was passiert | Invarianten |
|---|---|---|
| checkout | B Käufer (70 % 1 Ticket, sonst 2–4) kaufen eine Ticketart mit begrenztem Kontingent über `Orders::create()`; 15 % schicken denselben Idempotency-Key gleichzeitig über eine zweite Verbindung (Doppelklick/Retry) | kein Überverkauf; gehaltene Tickets = Tickets erfolgreicher Bestellungen; je Key genau eine Bestellung; keine unerwarteten Fehler |
| check-in | T Tickets, D Geräte scannen jedes Ticket gleichzeitig (gerade Geräte in Reihenfolge, ungerade gemischt), danach Wiederholung mit gleichem Key | genau ein erfolgreicher Scan und genau eine Einlasszeile pro Ticket; jede „eingelassen“-Antwort hat eine Einlasszeile; Replays idempotent |
| webhooks | E Stripe-Events (`checkout.session.completed`), jedes R-mal gleichzeitig zugestellt, mit echter Signaturprüfung | jedes Event genau einmal angewendet; alle Bestellungen bezahlt; Tickets genau einmal ausgestellt; eine Event-Zeile je Event |
| mixed | Eine Ticketart: Checkout-Ansturm, pünktliche Zahlungs-Webhooks, **verspätete** Webhooks von Bestellungen mit abgelaufener Reservierung (`--late`, `--ontime`) und Hold-Aufräumläufe gleichzeitig | bezahlt + aktive Reservierungen ≤ Kontingent; gültige Tickets = bezahlte Menge; jeder verspätete Webhook endgültig beantwortet (bezahlt oder `needs_review`); keine Fehler (Deadlocks dürfen nicht als 409/503 beim Käufer ankommen) |
| seats | Saalplan als einzige Grenze: Käufer konkurrieren um (begehrte) Plätze, verspätete Webhooks holen abgelaufene Sitz-Reservierungen zurück, Sitz- und Hold-Aufräumen laufen mit | jedes gültige Ticket hat einen eigenen Sitz; aktive Claims ≤ Sitze; keine verwaisten Claims; keine Fehler |

`--isolation=READ-COMMITTED` (oder `READ-UNCOMMITTED`, `SERIALIZABLE`) setzt die Sitzungs-Isolation jeder Worker-Verbindung; CI fährt `mixed` und `seats` zusätzlich unter READ COMMITTED. Deadlocks zählt der Lauf auf MySQL über `information_schema.INNODB_METRICS` (`lock_deadlocks`), auf MariaDB über `Innodb_deadlocks`. Ein abgestürzter Worker bzw. ein Vorgang ohne Ergebnis zählt als Fehler.

Ausgabe: Requests, Wall-Time, Durchsatz, p50/p95/p99/max-Latenz je Operation, InnoDB-Deadlocks
(Server-Zähler, MySQL und MariaDB; nur aussagekräftig, wenn sonst niemand die DB nutzt) und Fehlerquote. **Exit-Code 1 bei
verletzter Invariante.** „sold_out“ ist ein erwartetes Ergebnis, kein Fehler.

Der früher gefundene Check-in-Fehler (Gap-Lock-Deadlock auf `wpcap_admission_state`, falsche
„eingelassen“-Antworten) ist behoben: `Admission\Engine` liest den Einlasszustand ohne zusätzliches
`FOR UPDATE`, weil die Ticketzeile bereits gesperrt ist. Der Last-Smoke in CI ist seitdem blockierend
(kein `continue-on-error`) und dient als Regressionstest.

**CI:** `ci.yml`, Job `integration`, bei jedem Push/PR mit `--profile=ci` auf allen 12 Kombinationen
PHP × Datenbank. **Geplant:** `load.yml` montags 03:17 UTC und manuell (`workflow_dispatch`, Profil
`all|default|heavy`) mit `default` und `heavy` auf MariaDB 12.3 und MySQL 9.7, PHP 8.5 (Referenzwerte unten noch von MariaDB 10.6/MySQL 8.4, beim nächsten Lauf ersetzen). Der Job scheitert nur
bei verletzter Invariante; Ausgabe und JSON liegen 90 Tage als Artefakt `load-<profil>-<db>` vor, die Tabelle
steht in der Job-Zusammenfassung. Latenzen werden nicht automatisch bewertet (geteilte Runner schwanken):
bei Abweichungen über etwa Faktor 2 gegenüber den Referenzwerten oder Deadlocks > 0 genauer hinsehen.

#### Referenzwerte (14.11.2026)

Lokale VM mit 4 vCPU, 15 GB RAM, PHP 8.3, Datenbank im Docker-Container mit Standardkonfiguration
(`max_connections=151`) auf derselben Maschine. Alle Invarianten erfüllt, 0 % Fehler. Die Zahlen zeigen
Größenordnungen und Engpässe, keine Produktionskapazität.

| DB | Profil | Szenario | Requests | Verb. | req/s | p50 ms | p95 ms | p99 ms | max ms | Deadlocks |
|---|---|---|---|---|---|---|---|---|---|---|
| MariaDB 10.6 | default | checkout | 566 | 50 | 514 | 70.8 | 158.7 | 207.2 | 260 | 0 |
| MariaDB 10.6 | default | check-in | 1000 | 20 | 2722 | 4.9 | 19.7 | 31 | 55 | 0 |
| MariaDB 10.6 | default | webhook | 480 | 50 | 1303 | 3 | 256.7 | 311.7 | 319 | 0 |
| MariaDB 10.6 | heavy | checkout | 1150 | 100 | 583 | 140.9 | 276.6 | 376.3 | 446 | 0 |
| MariaDB 10.6 | heavy | check-in | 4000 | 40 | 3156 | 7.3 | 33.5 | 52.7 | 105 | 0 |
| MariaDB 10.6 | heavy | webhook | 1200 | 100 | 1384 | 5.1 | 508.3 | 678.2 | 787 | 0 |
| MySQL 8.4 | default | checkout | 566 | 50 | 574 | 63.2 | 175.4 | 211.1 | 252 | 0 |
| MySQL 8.4 | default | check-in | 1000 | 20 | 2083 | 5.5 | 24.2 | 41.3 | 54 | 0 |
| MySQL 8.4 | default | webhook | 480 | 50 | 1062 | 5.8 | 267.8 | 348 | 388 | 0 |
| MySQL 8.4 | heavy | checkout | 1150 | 100 | 471 | 134.6 | 461.5 | 631.9 | 715 | 0 |
| MySQL 8.4 | heavy | check-in | 4000 | 40 | 2648 | 6.8 | 43.1 | 77.7 | 215 | 0 |
| MySQL 8.4 | heavy | webhook | 1200 | 100 | 1122 | 7.9 | 584.4 | 889.2 | 1027 | 0 |

Beobachtung: Die Ticketart-Zeile wird beim Kauf per `FOR UPDATE` gesperrt; Käufe derselben Ticketart
serialisieren, die Latenz steigt etwa linear mit der Zahl gleichzeitiger Käufer, der Durchsatz bleibt stabil.

### Über die echte REST-API: `tests/load/http.php`

Startet einen eigenen `php -S` mit `PHP_CLI_SERVER_WORKERS` (Standard 8) für eine **Wegwerf**-WordPress-
Installation, legt Testdaten über das Plugin an und feuert parallele Requests mit `curl_multi` (Standard 32)
gegen `/checkout`, `/tickets/check-in` (Cookie + Nonce eines Admins) und `/payments/stripe/webhook` (signiert).
Einstellungen der Site werden nicht verändert (Webhook-Secret nur als `WPCAP_STRIPE_WEBHOOK_SECRET` für den
privaten Server, IP-Limit per Router-Skript nur dort aufgehoben); Test-Events werden am Ende auf „draft“
gesetzt. Nicht in CI.

```bash
php tests/load/http.php --wp=/pfad/zu/wordpress [--server-workers=8] [--concurrency=32] [--buyers=300] \
    [--capacity=100] [--tickets=10] [--devices=10] [--events=30] [--dupes=5] [--port=8091] [--json=out.json]
```

Größenordnung (11.11.2026, voller WordPress-+-WooCommerce-Bootstrap je Request, 8 Worker): 30–45 req/s,
p95 0,9–2,1 s, 0 % Fehler. Der Engpass ist der WordPress-Bootstrap, nicht das Plugin.

## Statische Analyse (Job `static`)

| Prüfung | Lokal | Konfiguration |
|---|---|---|
| Versions-/Anforderungskonsistenz | `bash build/check-versions.sh [vX.Y.Z]` | Header `Version`, `WPCAP_VERSION`, readme `Stable tag`, neueste CHANGELOG-Version, `composer.json` (`require.php`, `config.platform.php`), `Requires at least`/`Requires PHP` |
| PHP_CodeSniffer (WPCS 3 + PHPCompatibilityWP, PHP 8.2+, WP 6.4+) | `php build/phpcs-baseline.php` | `phpcs.xml.dist`, Baseline `phpcs-baseline.json` |
| PHPStan Level 5 (phpstan-wordpress, WooCommerce-Stubs) | `composer lint:phpstan` | `phpstan.neon.dist`, Baseline `phpstan-baseline.neon` |
| Bekannte Schwachstellen in Composer-Paketen | `composer audit --locked` | – |

**Baselines:** Bestehende Befunde sind eingefroren, jeder *neue* Befund lässt CI scheitern. Stand der
Baseline-Dateien 14.11.2026: phpcs 1047 Befunde in 105 Dateien (davon 880 `WordPress.DB.PreparedSQL.*`,
meist Tabellennamen in SQL-Strings), PHPStan 97 Befunde in 63 Einträgen. Der phpcs-Ruleset erzwingt
Sicherheit (Escaping, Nonces, Sanitizing, vorbereitete SQL-Statements), i18n (Textdomain `pass-captain`),
WordPress-API-Regeln, Präfixe, Capabilities und PHP-Kompatibilität; reine Layout- und Docblock-Sniffs sind
abgeschaltet.

- phpcs zählt pro Datei und Sniff (zeilenunabhängig): steigt die Zahl für ein Paar Datei/Sniff, werden dessen
  Fundstellen ausgegeben. Neue Dateien haben keine Baseline.
- Befunde beheben statt Baseline vergrößern; nur begründet `// phpcs:ignore Sniff.Code -- Grund` bzw.
  `@phpstan-ignore identifier (Grund)` an der Stelle.
- Nach Korrekturen und nach Merges mehrerer Zweige Baselines neu erzeugen und den Diff prüfen (er darf nur
  Einträge entfernen oder bewusst akzeptierte hinzufügen): `php build/phpcs-baseline.php --update`,
  `composer phpstan:baseline`.
- Aus PHPStan ausgenommen: `src/Fiscalization/Providers/*Adapter.php` (implementieren das nicht vorhandene
  Interface `PassCaptain\Fiscalization\AdapterInterface`).

## Lizenz-Wächter (Job `license-guard`)

Ergebnis:

- **Checkout:** in allen Läufen kein Überverkauf (Kontingent exakt ausgeschöpft), Idempotenz hält auch bei
  gleichzeitigen Doppel-Submits (Unique-Index + Wiederverwendung), keine Deadlocks, 0 % Fehler. Die Ticketart-
  Zeile wird per `FOR UPDATE` gesperrt, daher serialisieren Käufe derselben Ticketart: Latenz steigt linear
  mit der Zahl gleichzeitiger Käufer (≈ 2 ms je wartendem Käufer), der Durchsatz bleibt bei ~400–570 Bestellungen/s.
- **Webhooks:** jedes Event genau einmal angewendet, alle Duplikate als `duplicate` beantwortet, keine
  doppelten Tickets.
- **Check-in (behoben):** In der Messung vom 11.11.2026 liefen gleichzeitige Erst-Einlässe *verschiedener*
  Tickets in einen InnoDB-Gap-Lock-Deadlock auf `wpcap_admission_state`, und `Checkin::perform()` meldete trotz
  Rollback „eingelassen“. Behoben (Commit 53633f6): `Admission\Engine::state_for_update()` liest den Zustand
  ohne `FOR UPDATE` (die Ticketzeile ist bereits gesperrt), Datenbankfehler brechen den Einlass mit einem
  retrybaren Fehler ab. Seitdem in jedem `--profile=ci`-Lauf: 0 Deadlocks, je Ticket genau ein Einlass
  (13.11.2026: 3 von 3 Läufen, `admitted` 10 / `rejected_duplicate` 140 / `replayed` 10). Der CI-Schritt
  „Concurrency/load smoke test“ ist verpflichtend (kein `continue-on-error`).

Der Kern bleibt frei (Entscheidung 13.11.2026, [FREE-PREMIUM.md](FREE-PREMIUM.md)). Der Wächter scheitert,
sobald Lizenzclient, Rechteprüfung, ein `Update URI`-Header oder eigene Update-Hooks in ausgelieferten Dateien
auftauchen. Er läuft gegen den Quellbaum (Job `license-guard`, mit Selbsttest) und gegen das entpackte ZIP
(Job `package`).

```bash
php tests/guard/no-license-code.php --self-test     # jede Regel erkennt ein eingeschleustes Muster
php tests/guard/no-license-code.php [verzeichnis]
```

## Barrierefreiheit (`tests/a11y/`)

axe-core 4.10 + Playwright (Chromium) gegen eine laufende **Wegwerf**-Installation. `fixture.php` legt per
`wp-load.php` ein Test-Event an (bezahlte, kostenlose Ticketart mit Teilnehmerfeld, ausverkaufte Ticketart),
eine kostenlose Bestellung (Ticketseite + Bestellportal-Link aus der Mail) und ein Wartelistenangebot.

```bash
php tests/guard/no-license-code.php --self-test     # jede Regel erkennt ein eingeschleustes Muster
php tests/guard/no-license-code.php [verzeichnis]
```

## Secret-Scan (Job `secrets`, Schritt in `package`)

Stand 11.11.2026: öffentliche Seiten 0 kritische/ernste Verstöße (1 moderater: Überschriftenebene im Event-
Template), Tastatur-Durchlauf 23/23 Prüfungen bestanden, Farbkontrast der öffentlichen Seiten ohne Befund. Admin-
Seiten: 9 kritische und 48 ernste Knoten (fehlende Labels an Filter-Selects, Kontrast von `<code>`-Links,
Zielgröße der Checkboxen) – siehe offene Punkte im Changelog/Report. Nicht in CI (braucht eine WordPress-Instanz).
Automatische Prüfungen ersetzen keine Screenreader-Tests (siehe `docs/ACCESSIBILITY.md`).

gitleaks prüft die Git-Historie (`gitleaks/gitleaks-action`) und das entpackte Release-ZIP (`gitleaks dir`,
Version 8.28.0, per SHA-256 gepinnt). Regeln: Standardregeln plus `.gitleaks.toml` (Stripe-Testschlüssel
`sk_test_…` und `generic-api-key` unter `tests/` erlaubt; Live-Schlüssel werden überall gemeldet). Für Pull
Requests prüft `actions/dependency-review-action` neue/geänderte Abhängigkeiten (ab „moderate“). Lokal:
`gitleaks git --config .gitleaks.toml .` bzw. `gitleaks dir --config .gitleaks.toml <dir>`. Gehört das
Repository einer Organisation, braucht die gitleaks-Action das Secret `GITLEAKS_LICENSE`.

## Paket und Installation (Jobs `package`, `install-test`, `plugin-check`)

- **Reproduzierbarkeit:** `build/release.sh` wird zweimal ausgeführt (zweiter Lauf mit leerem Composer-Cache,
  Composer exakt 2.8.12); die SHA-256-Summen müssen gleich sein, `pass-captain.zip.sha256` wird geprüft.
  Details: [COMPATIBILITY.md](COMPATIBILITY.md#release-artefakt-prüfen).
- **Paketinhalt** (`WPCAP_IT_STATIC_ONLY=1 bash build/ci-install-test.sh build/dist/pass-captain.zip`): ein
  Ordner `pass-captain/`, Hauptdatei, Readme, `uninstall.php`, Composer-Autoloader, alle versionierten Dateien
  aus `src/`, `templates/`, `assets/`, `languages/*.mo`; keine Tests, Doku, Build-/CI-Dateien, Baselines,
  Markdown, `.po`-Quellen, Test-Ordner von Abhängigkeiten, privaten Schlüssel oder Live-Keys (`.distignore`).
- **Installation in echtem WordPress** (`bash build/ci-install-test.sh build/dist/pass-captain.zip`, braucht
  eine Wegwerf-DB, lädt WP-CLI): WordPress installieren (`WP_DEBUG_LOG`), optional WooCommerce, dann
  `wp plugin install <zip> --activate`. Fehlschlag bei PHP-Fehlern/Warnungen/Deprecations aus Plugin-Dateien.
  Smoke-Check `build/ci-smoke.php`: Tabellen, Schema-Version/-Fingerprint, Rollenrechte, Cron, REST-Routen und
  `GET /pass-captain/v1/events` (auch über den alten Namensraum `/wp-pass-captain/v1/events`), QR-Code; mit WooCommerce HPOS-Kompatibilität und eine Woo-Bestellung über den
  aktiven Speicher – mit HPOS an und aus, danach Deaktivieren/Aktivieren und erneuter Smoke-Check.
  CI-Matrix: WordPress aktuell und 7.0, WooCommerce aktuell und 11.2.2, Update von 1.4.0, Multisite – alle mit
  PHP 8.5, Datenbank MariaDB 12.3. Variablen: `WPCAP_IT_DB_*`, `WPCAP_IT_WP_VERSION`,
  `WPCAP_IT_WC_VERSION`, `WPCAP_IT_WP_CORE_DIR`/`WPCAP_IT_WC_ZIP` (lokale Quellen), `WPCAP_IT_UPGRADE_FROM`/
  `WPCAP_IT_UPGRADE_FROM_ZIP`, `WPCAP_IT_SKIP_UNINSTALL=1`, `WPCAP_IT_KEEP=1`.
- **Update-Pfad** (`WPCAP_IT_UPGRADE_FROM=<git-ref>`, in CI der Release-Commit von 1.4.0 `90c972a`): das ZIP der
  Vorversion wird aus diesem Commit gebaut (wie dessen `release.sh`: `git archive`, `composer --no-dev`,
  `.distignore`; bis 1.4 als `wp-pass-captain/wp-pass-captain.php`), installiert und aktiviert;
  `build/ci-data.php seed` legt mit der alten Version Daten an (kostenlose und bezahlte Bestellung, Tickets,
  Check-in, externe Erstattung, offene Reservierung, geplanter Job), „Daten beim Löschen entfernen“ wird
  eingeschaltet. Danach `wp plugin install <neues zip> --activate`: die neue Kopie übernimmt die Aktivierung (genau
  ein Eintrag in `active_plugins`, Deaktivieren funktioniert), Schema auf aktueller `DB_VERSION` (Smoke-Check),
  keine PHP-Fehler, Geschäftsdaten unverändert (Snapshot von zehn Tabellen) und konsistent (`invariants`);
  das Upgrade hat das Opt-in nach `wpcap_uninstall_policy` verschoben und den alten Schlüssel auf 0 gesetzt; das
  Löschen der alten Kopie entfernt keine Daten.
- **Deinstallation** (jedes Bein): mit Standardeinstellungen `wp plugin deactivate` + `wp plugin uninstall` →
  alle Tabellen, Einstellungen, Schema-Version und Rollen bleiben, nur die Cron-Ereignisse sind weg; erneutes
  Installieren findet die Daten unverändert wieder. Mit eingeschaltetem Daten-Opt-in (`wpcap_uninstall_policy=delete`, die Einstellung „Daten beim Löschen entfernen“) bleiben keine Tabellen,
  Optionen/Transients `wpcap_*`, Rollen, Administrator-Rechte, User-Meta oder Cron-Ereignisse übrig
  (`uninstall.php`).
- **Plugin Check:** das offizielle WordPress.org-Werkzeug (`wordpress/plugin-check-action@v1`) prüft genau die
  Dateien des CI-ZIPs.

## Release-Gate-Skript (`tests/release/verify-release.test.sh`)

Testet `build/verify-release.sh` (wird von `release.yml` vor jedem Build ausgeführt: Tag = Header-Version =
`WPCAP_VERSION` = Stable tag, Commit auf `main`, CI für genau diesen SHA erfolgreich) mit einem Wegwerf-Origin
und gefälschten Actions-API-Antworten. Braucht `git` und `jq`, kein Netz:

```bash
bash tests/release/verify-release.test.sh
```

## Browser-Suiten: JS-Unit-Tests, a11y-Gate, E2E

Gemeinsamer Einstieg `tests/ci/browser-tests.sh [js|a11y|e2e|all]`. a11y und E2E laufen gegen eine
**Wegwerf**-WordPress-Seite, die `tests/support/wp-env.sh` anlegt und mit `php -S` ausliefert
(WordPress-Core per `git clone` oder aus `WPCAP_WP_CORE`, Plugin als Symlink, eigene DB/Präfix, Mailversand und
Gravatar abgeschaltet):

```bash
export WPCAP_DB_HOST=127.0.0.1 WPCAP_DB_USER=wp WPCAP_DB_PASS=wp WPCAP_DB_NAME=wp_browser   # Wegwerf-DB!
export WPCAP_WP_DIR=/tmp/wpcap-browser-wp WPCAP_WP_PORT=8890      # optional
tests/ci/browser-tests.sh all
```

### JS-Unit-Tests (`tests/js/`, `npm test`)

`node --test` ohne Abhängigkeiten (Node ≥ 20). `lib/load.js` führt die Seiten-Skripte in Node mit Mini-DOM
(`lib/dom.js`), Fake-Timern, `fetch`-Mock und In-Memory-IndexedDB (`lib/idb.js`) aus.

| Datei | Abgedeckt |
|---|---|
| `checkout.test.js` | Haltezeit-Texte, `money()` wie `Format::money()`, Summe und Teilnehmer-Fieldsets, Validierung, Fehlerzusammenfassung (Fokus, ARIA), Server-Fehler → Feld, Rechnungsdaten, REST-Payload, Idempotency-Key |
| `pos.test.js` | Kasse: Betragsformat/-parser, Summe, Rückgeld, Karte/frei, Teilnehmerfelder, Doppel-Submit, Verfügbarkeits-Refresh |
| `scanner.test.js` | Web-Scanner: Online-Check-in, Serverfehler, Offline nie automatisch eingelassen, Warteschlange pro Benutzer, Ablauf nach 24 h, Sync in 100er-Blöcken, Einlassmodul, Kamera verweigert/fehlt, 5-s-Timeout mit Zustand `pending` und Schlüsselübernahme in die Warteschlange, Teilnehmername, Töne (abschaltbar), Taschenlampe/Dauerfokus |
| `order-portal.test.js` | Übertragung mit Bestätigungsdialog, Teilnehmerzuweisung, erneuter Versand, Rechnungsdaten |
| `seating-picker.test.js` | Saalplan: Rollen/Labels, Listen-Fallback, Auswahl, Tastatur, Stehplätze, Mengenänderung, Payload |
| `csp.test.js` | FE-8: Admin-Bestätigungsdialoge (`data-wpcap-confirm` an Buttons/Formularen, Abbrechen verhindert das Absenden), Auto-Submit, Select-on-Focus, Druck-Buttons und Auto-Druck (`assets/print.js`); statische Prüfung: keine Inline-Event-Handler in `src/` und `assets/` |
| `sms-checkout.test.js` | PRIV-16: SMS-Opt-in mit Bestätigungscode – Code anfordern/bestätigen, Fehlertexte, Enter im Codefeld, Token im Payload, Nummernwechsel verwirft die Bestätigung, ohne Bestätigung keine Bestellung mit Opt-in, Einstellung aus |
| `offline-scanner.test.js` | PWA: i18n, Kopplung, Ed25519-Paketprüfung, Delta-Kette, Offline-Entscheidung, IndexedDB-Warteschlange und Sync mit Konflikten, Serverausfall, 401; „Prüfe …“ → Ergebnis → „Bereit“, 10-s-Sperre, gelb „Eben hier eingelassen“, DB-Retry (503) → offline, lokale Einlasszähler (Tages-/Mehrfach-/Wiedereinlass), Servicetag, Paketformat 2, Kompaktdelta nach 30 Deltas, 120-s-Timeout und Fortschritt, Töne, Taschenlampe |

### Barrierefreiheit (`tests/a11y/`, Gate `gate.sh`)

axe-core 4.10 + Playwright (Chromium). `fixture.php` legt Testdaten an (Event mit bezahlter, kostenloser und
ausverkaufter Ticketart, Bestellung, Wartelistenangebot, API-Key, Ressource mit Zeitfenster, Kampagne,
Mitgliedspass, Saalplan-Entwurf).

- `run.js`: WCAG 2.0/2.1/2.2 A+AA und best-practice auf öffentlichen Seiten (Event-Liste mit Checkout auch mit
  Teilnehmerfeldern und Fehlermeldung, Ticketseite, Bestellportal, Wartelistenangebot, Scanner) und **jeder
  Unterseite des Pass-Captain-Menüs** (aus dem Menü gelesen, neue Seiten automatisch dabei) plus
  Detailansichten. JSON-Bericht in `tests/a11y/report/`; HTTP ≠ 200 gilt als Fehler; `--match=text` filtert.
- `keyboard.js`: Checkout und Scanner nur mit der Tastatur (Tab-Reihenfolge, Namen, sichtbarer Fokus,
  Live-Regionen, Fokus bei Fehlern und nach dem Absenden).
- `gate.sh` startet die Testseite (falls `WPCAP_A11Y_BASE`/`WPCAP_A11Y_WP_PATH` nicht gesetzt), führt beide aus
  und endet mit Exit-Code ≠ 0 bei **jedem** Verstoß (`WPCAP_A11Y_FAIL_ON=minor`, änderbar auf
  `moderate|serious|critical`), HTTP ≠ 200 oder fehlgeschlagener Tastaturprüfung.

```bash
tests/a11y/gate.sh                                   # oder: tests/ci/browser-tests.sh a11y
# gegen eine bestehende Wegwerf-Seite:
WPCAP_A11Y_WP_PATH=/pfad/zu/wordpress WPCAP_A11Y_BASE=http://localhost:8080 \
WPCAP_A11Y_USER=admin WPCAP_A11Y_PASS=admin node tests/a11y/run.js [--fail-on=…] [--only=public|admin]
```

Stand 14.11.2026 (WordPress 7.1, Chromium): 53 Seiten, 0 Verstöße aller Stufen, Tastatur-Durchlauf bestanden.
Die „contrast?“-Spalte zählt Knoten, die axe nicht bewerten kann (meist Core-Selects) – manuell prüfen.
Automatische Prüfungen ersetzen keine Screenreader-Tests ([ACCESSIBILITY.md](ACCESSIBILITY.md)).

### Browser-E2E (`tests/e2e/`, `run.sh`)

Playwright + `node:test`, Screenshots nur bei Fehlschlag (`tests/e2e/artifacts/`). `helper.php` legt
serverseitig Kopplungscodes, Zuweisungen und Termine an; `mu-plugin/wpcap-e2e.php` bietet nur auf der Testseite
eine Fake-Zahlungsseite (Cookie `wpcap_e2e=1`).

```bash
tests/e2e/run.sh                                        # startet die Testseite selbst
WPCAP_WOO_ZIP=/pfad/woocommerce.zip tests/e2e/run.sh    # + WooCommerce-Blocks auf einer zweiten Testseite
WPCAP_E2E_BROWSER=firefox tests/e2e/run.sh              # andere Engine (npx playwright install --with-deps firefox)
WPCAP_E2E_BROWSER=webkit WPCAP_E2E_DEVICE="iPhone 14" tests/e2e/run.sh checkout.test.js portal.test.js offline-scanner.test.js
```

**Engines und Geräte.** `WPCAP_E2E_BROWSER=chromium|firefox|webkit`; `WPCAP_E2E_DEVICE` übernimmt einen
Playwright-Geräteeintrag (Viewport, User-Agent, Touch, `isMobile`, Pixeldichte) und muss zur Engine passen
(„Pixel 7“ → chromium, „iPhone 14“ → webkit). Unter Mobil-Emulation prüfen Checkout, Portal und PWA zusätzlich,
dass die Seite bei Gerätebreite nicht horizontal scrollt (`assertFitsViewport`). Emulation ersetzt keine echten
Geräte (Kamera, PWA-Installation, Hintergrund-Sync bleiben manuell).

**Fake-Kamera je Engine** (Scanner-Test „Kamera erlaubt“ läuft auf allen drei):

| Engine | ohne Berechtigung | „erlaubt“ |
|---|---|---|
| Chromium | `--use-fake-device-for-media-stream`, ohne Freigabe `NotAllowedError` | `grantPermissions(['camera'])` |
| WebKit | Mock-Kamera vorhanden, ohne Freigabe `NotAllowedError` | `grantPermissions(['camera'])` |
| Firefox | keine Kamera (`NotFoundError` → Meldung „keine Kamera“); `grantPermissions('camera')` wird nicht unterstützt | zweite Firefox-Instanz mit `media.navigator.streams.fake` + `media.navigator.permission.disabled` (browserweit, daher eigene Instanz) |

**Browser aus einem Container** (wenn `npx playwright install` lokal nicht möglich ist): Die Tests laufen auf
dem Host (PHP-Helfer, Testseite), nur der Browser im offiziellen Image, das sich per Host-Netz mit der Testseite
verbindet. `--unsafe` erlaubt die Startoptionen der Tests (Chromium-Flags, Firefox-Prefs); nur lokal verwenden:

```bash
docker run --rm --network host mcr.microsoft.com/playwright:v1.63.0-noble \
  npx -y playwright@1.63.0 run-server --port 3000 --host 127.0.0.1 --unsafe
WPCAP_E2E_WS_ENDPOINT=ws://127.0.0.1:3000/ WPCAP_E2E_BROWSER=webkit tests/e2e/run.sh
```

**CI** (`ci.yml`, Job `browser`, Matrix): `chromium` (a11y-Gate + alle E2E inkl. WooCommerce-Blocks gegen
echtes WooCommerce), `firefox`, `webkit` (alle E2E außer WooCommerce-Blocks, der ohne `WPCAP_WOO_ZIP`
übersprungen wird), `Pixel 7, chromium` und `iPhone 14, webkit` (Checkout, Portal, Offline-Kopplung).
Playwright ist gepinnt (`PLAYWRIGHT_VERSION`), Browser kommen aus `npx playwright install --with-deps <browser>`.
Screenshots fehlgeschlagener Tests als Artefakt `e2e-artifacts-<bein>`.

| Datei | Ablauf |
|---|---|
| `checkout.test.js` | Bezahlte Bestellung über Fake-Zahlung → 2 Tickets, Namen im Portal; kostenlose Bestellung; Fehlerzusammenfassung |
| `portal.test.js` | Teilnehmerzuweisung, erneuter Versand mit Dialog, einmalige Übertragung |
| `scanner.test.js` | Video vor dem Start verborgen; Kamera verweigert/fehlt → Meldung, manueller Code checkt ein, zweiter Scan abgelehnt; Fake-Kamera → Video läuft (alle Engines) |
| `offline-scanner.test.js` | PWA: Kopplung, signiertes Paket, Scan offline → lokale Entscheidung + Warteschlange, Doppelscan abgelehnt (gelb „Eben hier eingelassen“), Sync nach Reconnect |
| `woocommerce-blocks.test.js` | Blocks-Checkout mit Teilnehmer-Fieldset, ungültige E-Mail blockiert, Angaben am Woo-Auftrag (ohne `WPCAP_WOO_ZIP` übersprungen) |

## Restore-Probe (`tests/drills/restore-drill.sh`)

Automatisierte Backup/Restore-Probe aus [OPERATIONS.md](OPERATIONS.md#backuprestore-und-upgrade) gegen eine
Wegwerf-WordPress-Installation mit dem Plugin aus dem Arbeitsverzeichnis (Symlink, WP-CLI). **Alle Tabellen der
angegebenen Datenbank werden gelöscht.**

1. Daten: `build/ci-data.php seed` (Bestellungen, Tickets, Check-in, Erstattung, offene Reservierung, Job) und
   `tests/drills/drill.php inflight` – Arbeit, die beim Backup „unterwegs“ ist: bezahlte Bestellung, deren
   Ausstellung nach dem Zahlungs-Commit abbrach (Ausstellungs-Job wartet), eine mit abgebrochenem Worker
   (hängender Claim), eine abgelaufene, nicht bereinigte Reservierung. Diagnose meldet genau diese Befunde.
2. Backup: Dump der ganzen Datenbank (`--single-transaction`) und Snapshot der Geschäftsdaten.
3. Schreibvorgänge nach dem Backup (Bestellung, Erstattung, Check-in, Job-Lauf), dann Zerstörung (`DROP TABLE`
   Tickets, `TRUNCATE` Bestellungen, Schema-Version gelöscht).
4. Restore in die geleerte Datenbank: Snapshot gleich, Ticketcodes von vor dem Backup gültig, Diagnose wie vor
   dem Backup.
5. Wiederherstellung wie dokumentiert: `wp pass-captain diagnose --repair-all`, Cron (`wpcap_process_jobs`,
   `wpcap_cleanup_reservations`), `wp pass-captain diagnose --strict` ohne Befund, Invarianten
   (`build/ci-data.php invariants`: keine doppelten Codes/Einlässe/Erstattungen/Job-Schlüssel, keine Waisen,
   keine bezahlte Bestellung ohne Tickets, keine gültigen Tickets unbezahlter Bestellungen, kein Überverkauf).
6. Idempotenz: Reparatur und Cron erneut, und nach einem zweiten Restore desselben Dumps zweimal – keine
   Datenänderung, keine weitere Mail (Mails werden per mu-plugin gezählt, nie verschickt), jede abgebrochene
   Bestellung hat genau ihre Ticketzahl.

```bash
WPCAP_DRILL_DB_NAME=wp_drill tests/drills/restore-drill.sh                 # lokaler Server, mariadb-dump/mysqldump
WPCAP_DRILL_DB_PORT=3391 WPCAP_DRILL_CLIENT_PORT=3306 \
WPCAP_DRILL_DUMP="docker exec -i mysql84 mysqldump" WPCAP_DRILL_MYSQL="docker exec -i mysql84 mysql" \
  tests/drills/restore-drill.sh                                              # Werkzeuge aus dem DB-Container
```

Variablen: `WPCAP_DRILL_DB_HOST/_PORT/_USER/_PASS/_NAME`, `WPCAP_DRILL_DUMP`, `WPCAP_DRILL_MYSQL`,
`WPCAP_DRILL_CLIENT_HOST/_PORT` (Sicht aus dem Container), `WPCAP_DRILL_WP_CORE` oder `WPCAP_DRILL_WP_VERSION`,
`WPCAP_DRILL_WP_CLI`, `WPCAP_DRILL_WORK`, `WPCAP_DRILL_KEEP=1`. Laufzeit lokal etwa eine Minute.

**CI:** `drills.yml` montags 04:41 UTC und manuell auf MariaDB 11.4, MySQL 8.0 und 8.4; Dump und Import mit den
Werkzeugen des Datenbank-Containers (Client = Server-Version). Bei Fehlschlag Protokoll, Snapshots und
`debug.log` als Artefakt.

## Ausstehend

- **Nächtlicher erweiterter Lauf** `tests/integration/run-slow.sh` (W6) ist in keinem Workflow eingebunden.
- **CI-Einbindung** von `tests/release/verify-release.test.sh`.
- **Echte Geräte:** E2E läuft in Chromium, Firefox und WebKit sowie mit Pixel-7-/iPhone-14-Emulation; echte
  Geräte **iOS Safari** und **Android Chrome** (Kamera mit echtem QR-Code, BarcodeDetector vs. jsQR,
  PWA-Installation, Hintergrund-Sync) bleiben manuell oder über einen Gerätedienst. Der QR-Decoder selbst wird
  in keiner Engine mit einem echten Kamerabild geprüft (Fake-Kameras liefern ein Testbild).
- **a11y-Gate** nur in Chromium (axe-Ergebnisse sind engine-unabhängig; Tastaturverhalten in Firefox/Safari
  deckt die Screenreader-Abnahme ab).
- **Echte Zahlungsanbieter:** Stripe, PayPal und Mollie sind nur gegen nachgebaute APIs getestet; Abnahme gegen
  die Sandboxes (Zahlung, Webhook, Teil-/Vollerstattung) ist manuell ([RELEASE.md](RELEASE.md)).
- **WooCommerce-Checkout E2E:** Der Blocks-Test läuft in CI (Bein chromium) gegen das aktuelle WooCommerce; der
  klassische Checkout und ein Durchlauf in einem echten Shop (Theme, Zahlungs-Plugins) fehlen.
- **Update-Pfad** nur von 1.4.0 ohne WooCommerce und ohne Multisite; Updates mit WooCommerce-Bestellungen und
  über mehrere Versionen deckt die Integrationssuite mit Schema-Fixtures ab (`zzzz_w6_upgrade.php`).
- **Weitere Dienste:** Apple/Google Wallet, Twilio, Fiskaldienste nur mit Fakes getestet.

## Große Vorverkäufe: Kontingentzähler (Profil `big`, 12.11.2026)

```bash
php tests/load/run.php --profile=big            # 20.000 bereits verkaufte Tickets, 1000 Käufer, 100 Verbindungen
php tests/load/run.php --profile=big --sold=50000 --buyers=800 --pay-rate=0.5
WPCAP_LOAD_COUNTERS=0 php tests/load/run.php --profile=big   # SUM-Rückfallpfad (Zustand „verifying“ nach Upgrade)
```

Szenario `onsale`: ein Termin mit 20.000 per Multi-Row-INSERT angelegten bezahlten Tickets (≈ 0,5 s, danach
`CapacityCounters::rebuild_all()` wie bei einem Upgrade), Ticketart- und Terminkontingent binden beide, eine
zweite Ticketart teilt sich den Termin. 1000 Käufer (70 % 1 Ticket, sonst 2–4; 15 % Doppel-Submit) lesen zuerst
`Orders::availability()` (wie die Warenkorbprüfung), legen dann über `Orders::create()` an; 30 % bezahlen sofort
(`confirm_external_payment()`). Invarianten: kein Überverkauf (Ticketart und Termin), Kontingent ausgeschöpft,
eine Bestellung je Key, keine unerwarteten Fehler, Zähler = Summen der Quelltabellen (`CapacityCounters::drift()`).

Gleiche Maschine wie oben (4 vCPU, 15 GB, MariaDB 10.11, PHP 8.3), je zwei Läufe, Werte des ersten Laufs:

| Stand | req/s | Wall s | p50 ms | p95 ms | p99 ms | max ms | availability() p50 / p95 ms | Zahlung p50 / p95 ms | Fehler |
|---|---|---|---|---|---|---|---|---|---|
| vorher (SUM unter Sperre) | 37,7 | 30,6 | 2881 | 3098 | 4299 | 4571 | 51,5 / 1847 | 4,7 / 8,3 | 0 % |
| nachher (Zähler) | 483,8 | 2,4 | 189 | 400 | 453 | 595 | 0,83 / 69,5 | 194 / 221 | 0 % |
| nachher, 2. Lauf | 485,2 | 2,4 | 188 | 415 | 494 | 605 | 0,82 / 66,9 | 195 / 235 | 0 % |
| SUM-Rückfall („verifying“) | 34,6 | 33,3 | 2611 | 5397 | 6091 | 6518 | 46,1 / 1192 | 2619 / 3077 | 0 % |

- Vorher kostete jede Kapazitätsprüfung vier SUM-Abfragen über alle verkauften Zeilen, während die Zeilen von
  Ticketart und Termin gesperrt waren: alle Käufer eines Termins warteten hintereinander (≈ 25 ms je Bestellung).
  Mit Zählern sind es zwei Primärschlüssel-Zeilen plus eine Indexabfrage über bereits abgelaufene, noch nicht
  aufgeräumte Reservierungen – die Sperrdauer hängt nicht mehr von der Zahl verkaufter Tickets ab.
- Die Zahlungsbestätigung sperrt jetzt ebenfalls Ticketart und Termin (die Einheiten wechseln im selben
  Commit von „reserviert“ nach „verkauft“) und reiht sich daher hinter laufende Bestellungen ein: p50 194 ms statt
  5 ms im Lastspitzen-Szenario. Im SUM-Rückfall direkt nach einem Upgrade wartet sie hinter langsamen
  Bestellungen (p95 ≈ 5 s) – Upgrades daher nicht während eines laufenden Vorverkaufs einspielen.
- Profil `ci` mit Zählern: checkout 861 req/s, p50 13,8 ms, p95 47,8 ms (vorher 575 req/s, 28,6/49,9 ms);
  check-in und webhook unverändert, alle Invarianten grün.

Integrationstests: `tests/integration/run.php` prüft nach jeder Testdatei, dass `sold_qty`/`held_qty` aller
Ticketarten und Termine den SUM-Abfragen entsprechen (bei Abweichung FAIL und Reparatur per `reconcile()`, damit
die nächste Datei für sich bewertet wird). `modules/p3_performance.php` prüft Zähler = SUM nach Anlage,
Gratisbestellung, abgelehnter Bestellung, Zahlung, Ablauf, verspäteter Zahlung (mit und ohne freies Kontingent),
Storno (offen/bezahlt), Teil- und Vollerstattung, Widerruf, Umtausch (gleichpreisig, mit Aufpreis-Reservierung
und Abschluss nach Zahlung); Reconcile repariert verpasste Writer und manipulierte Zähler (mit Alarm);
Upgrade-Pfad (Neuaufbau, „verifying“ → „ready“); Memo-/Objekt-Cache-Invalidierung; Event-Liste mit konstanter
Abfragezahl (3 und 12 Termine: je 8 Abfragen ohne Modulfilter) und REST-`/events` (höchstens 7 Abfragen, mit Verfügbarkeit je Ticketart).
Der Test-`$wpdb` zählt dafür `num_queries` und ruft wie WordPress den Filter `query` auf.

## Historie: Reservierungen, Index und Aufräumen (A429, A430, A444)

Datenbestand (`php tests/load/history.php seed`, Präfix `wphist_`, danach `drop`; Messung `measure`/`raw`): 500.000 Reservierungen aus
200 Tagen (85 % `converted`, 10 % `expired`, 5 % `released`, alle `counted_qty=0`), davon 202.777 auf einer
künftigen Ticketart (Vorverkauf seit Monaten), 300 aktive Holds; 500.000 Bestellungen mit je einer Zeile,
60 Termine (10 künftig) mit 180 Ticketarten. Gleiche Maschine wie oben (MariaDB 10.11, PHP 8.3), beste von
fünf Messungen:

| Abfrage | vorher | nachher |
|---|---|---|
| Kapazitätsprüfung Ticketart (abgelaufene Holds abziehen) | 68,5 ms, `type=ALL`, 498.199 Zeilen | 0,5 ms, `range` auf `counted_type`, 121 Zeilen |
| Kapazitätsprüfung Termin | 233 ms, `ref` auf `inventory` (alle Holds der Ticketarten) | 0,4 ms, `range` auf `counted_type` (Ticketarten per IN-Liste) |
| `Orders::capacity_error()` gesamt (Ticketart + Termin, unter den Sperren) | ≈ 300 ms | 0,6 ms |
| `CapacityCounters::used_for_types()` | 72 ms | 0,4 ms |
| Reconcile-Cron (`reconcile()`) | 14,9 s (Zeilen-Kandidaten 3,1 s Full Scan, korrelierte SUMs 2 × 5,7 s) | 62 ms |
| `reconcile( true )` (`--all`) | 22,6 s | 3,9 s |

Indexaufbau beim Upgrade auf diesem Bestand (`ADD KEY counted_type`, `ADD KEY updated`, `capacity_sum` neu):
zusammen ≈ 3 s. `modules/zz_a429_a430.php` legt 6.000 umgewandelte Reservierungen auf eine Ticketart und prüft per
`EXPLAIN` jeder vom Checkout bzw. vom Reconcile-Lauf ausgeführten Abfrage auf `wpcap_reservations`, dass keine
die Tabelle oder einen ganzen Index liest, dazu die Drift-Erkennung (alte, noch zählende Holds; aktive, nicht
zählende Holds; manipulierte Zähler).

Aufräumung (`php tests/load/history.php cleanup`, `Retention::housekeeping()` mit 10 s Budget je Lauf, wie der
stündliche Cron): von 467.500 Reservierungen (nach einem unterbrochenen Vorlauf) 457.106 ältere als 30 Tage in zwei Läufen gelöscht (239.000 bzw. 218.106 Zeilen
je 10 s), danach 86.000 erledigte Jobs (von 100.000; 13.593 jüngere bleiben), 57.547 abgelaufene Sitzplatz-Holds
(verkaufte bleiben) und 55.913 Zahlungsereignisse älter als 90 Tage – zusammen ≈ 22 s. Jede Auswahlabfrage ist
ein Indexbereich (`expiry`, `due_jobs`, `cleanup`). `modules/zz_a444.php` prüft, was bleibt (aktive und noch
zählende Holds, Holds offener Bestellungen, junge Zeilen, wartende/laufende Jobs, verkaufte Plätze), Stapel über
500 Zeilen, Zeitbudget 0 und das Abschalten eines Schritts per Filter. Danach `php tests/load/history.php drop`.

## Historie: Admin-Listen, Berichte und Aufräumen (A431–A435, A437, A438)

Datenbestand: `WPP6_N=200000 php tests/load/history.php seed`, danach `seed-admin` (Präfix z. B.
`WPCAP_TEST_PREFIX=wpu9h_`; am Ende `drop`): 200.000 Bestellungen (Verkaufshistorie bis gestern, 30 s Abstand),
169.745 Tickets, 85.432 Check-ins (jeder vergangene Termin, jeder zehnte Scan zusätzlich abgelehnt), 169.745
Webhook-Zustellungen (99.038 älter als 30 Tage), 5.143 abgeschlossene Erstattungen, 30 % Mollie-Zahlungen mit
Referenz in der Bestellung, ein Veranstalter mit allen Verkäufen bis Vormonatsbeginn abgerechnet (60.891
Abrechnungszeilen), 19.970 Wiederverkäufer-API-Bestellungen. Messung `admin` (mit `purge` auch das Aufräumen),
`reports` und `bulk`; MariaDB 10.11, PHP 8.3, Maschine mit paralleler Last (Werte schwanken um ±30 %).
Indexaufbau beim Upgrade auf diesem Bestand: `provider_ref` 0,8 s, Check-in-Indizes 0,2 s, `cleanup` 0,35 s.

| Vorgang | vorher | nachher |
|---|---|---|
| Mollie-Webhook, unbekannte bzw. alte Zahlungs-ID (A431) | 466 / 411 ms, `ALL` auf `wpcap_orders` | 0,3 ms, Primärschlüssel + `provider_ref` |
| Ticketliste Seite 1 / 50 (A432) | 2.494 / 2.475 ms | 15 ms (4 Abfragen) |
| Ticketliste Status „eingecheckt“ | 1.753 ms | 12–19 ms |
| Check-in-Protokoll Seite 1 / 100 (A433) | 132 / 181 ms | 7–11 / 8–12 ms |
| Check-in-Protokoll Event + Ergebnis + 30 Tage | 33 ms | 3–4 ms |
| Ergebnisfilter des Protokolls | 46 ms (`SELECT DISTINCT`) | keine Abfrage |
| Umsatzbericht Oktober, 78.000 Bestellungen (A434) | 12,9 s, 78.355 Abfragen, 169 MB | 3,9–5,7 s, 403 Abfragen, 39 MB |
| Buchhaltungsexport Oktober | 27,1 s, 156.678 Abfragen, 242 MB | 2,4–3,9 s, 477 Abfragen; gestreamt 2,3 s, 38 MB |
| Buchungsstapel (DATEV) Oktober | 25,6 s, 156.676 Abfragen, 240 MB | 2,6–3,9 s, 475 Abfragen, 57 MB |
| Termin-Abrechnung (80.000 Tickets) | 11,5 s, 49.176 Abfragen, 161 MB | 3,6–6,2 s, 497 Abfragen, 40 MB |
| Wiederverkäufer-Abrechnung (9.199 Zeilen, ohne warmen Cache) | 8.949 Abfragen | 21 Abfragen |
| Veranstalter-Abrechnung laufender Monat, 114.000 offene Zeilen (A435) | 33,1 s, 220.136 Abfragen, 423 MB | 5,0–5,5 s, 764 Abfragen, 138 MB |
| Webhook-Aufräumen ohne fällige Zeilen (A437) | 335 ms, `ALL` über 168.882 Zeilen, alle 5 min | 10 ms, `range` auf `cleanup`, stündlich |
| Webhook-Aufräumen, 99.038 fällige Zeilen | ein `DELETE` (548 ms, alle Zeilen in einer Transaktion) | 100 × `DELETE … LIMIT 1000`, 2,2 s im 5-s-Budget |

Verbleibend: Der Umsatzbericht liest für die Auslastungstabelle weiterhin alle Positionen der Ticketarten mit
Verkäufen im Zeitraum (Lebenszeit-Verkäufe je Ticketart, jetzt ein `GROUP BY` statt zwei korrelierter Summen je
Ticketart; ≈ 1,6 s auf diesem Bestand). Die Veranstalter-Abrechnung hält die Zeilen des Ergebnisses selbst im
Speicher (114.000 Zeilen ≈ 138 MB).

Massenoperationen (`bulk`, verändert den Bestand): Stapeldruck ohne neue Codes für einen Termin mit 1.109
Tickets – Anlegen 189 ms, Rendern 6 Blöcke (Testumgebung ohne echte PDF-Schriften, daher nur Ablaufwerte).
Absage des Termins mit 79.880 Tickets: Admin-Anfrage im Zeitbudget 20,7 s (73.074 Abfragen; CLI-Budget
`wpcap_cron_time_budget` 20 s), danach 20 Job-Läufe in 372 s (385.552 Abfragen, ≈ 5 je Ticket) bis alle Tickets
widerrufen sind; die 79.880 Absage-Mails liegen danach in der Nachrichtenwarteschlange (8.000 im Lauf selbst
versendet, der Rest über `wpcap_process_messages`).

Regressionstests mit `EXPLAIN` bzw. Abfragezählung (laufen in der normalen Suite):
`modules/zz_a431.php` (Mollie: Plan der Abfragen, IP-Limit vor der Datenbank), `zz_a432_a433.php` (3.000 Tickets,
6.000 Check-ins: keine korrelierte Unterabfrage, Seite über Primärschlüssel bzw. `created` ohne Filesort, Zählung
ohne Joins), `zz_a434.php` (1.200 Bestellungen: Bericht, Export, Stapel, Termin- und Wiederverkäufer-Abrechnung mit
höchstens 60 Abfragen, gestreamter Export = `export_rows()`), `zz_a435.php` (1.500 abgerechnete und 32 offene
Bestellungen: die abgerechneten werden nicht gelesen), `zz_a437.php` (Stapel, Zeitbudget, `range` auf `cleanup`).

## Regelmäßige Lasttests und Regressionsgrenzen (A121)

Der Workflow `.github/workflows/load.yml` läuft jeden Montag 03:17 UTC und manuell (`workflow_dispatch`):

- `tests/load/run.php` mit den Profilen `default`, `heavy` und `big` jeweils gegen **MariaDB 11.4** und **MySQL 8.4**,
- `tests/load/http.php` (echte REST-API) gegen ein frisch installiertes WordPress (neueste Version) mit dem Plugin.

Jedes Ergebnis wird mit `tests/load/compare.php` gegen `tests/load/baselines/<profil>.json` geprüft: Invarianten-Verletzungen, Fehlerquote > 0 oder ein p95 über der Grenze lassen den Lauf fehlschlagen; ein fehlendes Szenario ebenso. Die JSON-Ergebnisse hängen als Artefakte am Lauf (Verlauf über die Actions-Historie).

Referenzlauf 13.11.2026 (lokal, MariaDB 11, 4 vCPU), Grenze = 4 × gemessenes p95:

| Profil | Szenario | p95 gemessen | Grenze |
|---|---|---|---|
| default | Checkout / Check-in / Webhook | 134 / 15 / 243 ms | 550 / 100 / 1000 ms |
| heavy | Checkout / Check-in / Webhook | 249 / 21 / 401 ms | 1000 / 100 / 1650 ms |
| big | Vorverkaufsstart (Anlegen + Bezahlen) | 1965 ms | 7900 ms |
| http | Checkout / Check-in / Webhook (REST) | 672 / 898 / 972 ms | 2700 / 3600 / 3900 ms |

Nach einer gewollten Änderung (z. B. zusätzliche Prüfung im Checkout) die Grenzen in der Baseline anpassen und hier begründen. Hinweis: Der REST-Lasttest hebt auf seinem privaten Testserver die Schutzgrenzen pro Client (`wpcap_checkout_ip_limit`, `wpcap_manual_orders_per_client`, `wpcap_manual_open_orders_per_email`) auf, weil alle Anfragen von 127.0.0.1 kommen, und verwendet je Lauf eigene Käuferadressen. Ausverkauft-Antworten erkennt er sprachunabhängig am neuen Feld `data.reason` der Checkout-Fehler.

## Echte WordPress-Versionen (A125)

Workflow `.github/workflows/wp-matrix.yml` (jeder Push/PR, wöchentlich, manuell) installiert WordPress
6.4 (Mindestversion, PHP 8.2), 6.8 (PHP 8.3) und die neueste Version (PHP 8.4) mit MariaDB 11.4 und führt aus:

- `tests/wp-matrix/lifecycle.sh`: Aktivierung (Schema, DB-Version, Fingerprint), öffentliche REST-Route,
  Admin-Seiten (Events, Bestellungen, Check-ins, Einstellungen) als eingeloggter Admin, Deaktivierung
  (Daten bleiben, Cron-Ereignisse entfernt), erneute Aktivierung, Deinstallation ohne und mit
  „Daten beim Löschen entfernen“ (Tabellen, Optionen, Rollen), keine PHP-Fehler/-Warnungen/-Deprecations
  des Plugins im `debug.log`.
- `tests/restore/drill.sh` mit dem vorherigen Release und dem aktuellen Commit: Upgrade, Code-Rollback,
  erneutes Upgrade, Restore einer alten Sicherung, dazu Zustände außerhalb des Schemas (A469,
  `tests/restore/lifecycle.php`): Stripe-Schlüssel über Upgrade und Rollback (`rollback-config`), Verkäufe im
  alten Code mit drei Tage zurückdatierten Bestellungen und danach Kapazität ohne Überverkauf,
  Standard-Einlassfenster (siehe OPERATIONS.md). Gesichert und zurückgespielt werden nur die Tabellen des
  Präfixes der Test-Installation.
- `lifecycle.sh` prüft außerdem, dass die Deinstallation im selben Request keine Plugin-Option im
  Options-Cache zurücklässt (A467, steht für einen persistenten Objekt-Cache).

Lokal (nur Test-Installation!): `WPCAP_LIFECYCLE_HTTP=1 bash tests/wp-matrix/lifecycle.sh /pfad/zu/wp /pfad/zum/plugin`
(mit laufendem Webserver für die Site-URL; ohne `WPCAP_LIFECYCLE_HTTP` entfallen REST- und Admin-Seitenprüfung).
Ergebnis 13.11.2026 lokal gegen WordPress 7.1.4: alle 21 Prüfungen bestanden.

## Barrierefreiheit in CI (A155)

Job `accessibility` in `.github/workflows/wp-matrix.yml`: echtes WordPress (de_DE) mit dem Plugin, `tests/a11y/run.js --fail-on=serious` (axe-core, WCAG 2.2 A/AA + best practice) und `tests/a11y/keyboard.js`; der axe-Bericht hängt als Artefakt am Lauf. Lauf 13.11.2026 lokal: 0 kritische/ernste Befunde auf allen 15 Seiten (behoben: Touch-Ziel der Zusammenklapp-Zeile „Nur einzelne Tickets stornieren“ an der Kasse < 24 px), Tastatur-Durchlauf vollständig grün (angepasst an die −/+-Mengenknöpfe der Eventkarte und das Wartelisten-Formular am Kartenende). Manuelle Screenreader-/Zoom-Abnahme ist Teil des Release-Abnahmeprotokolls (docs/RELEASE.md).

Hinweis: Wiederholte lokale Läufe gegen dieselbe Installation können die Checkout-Ratenbegrenzung (30 je IP und Stunde) erreichen – dann `wp transient delete --all`.

## Statische Prüfungen (A173)

`composer install` (mit Entwicklungsabhängigkeiten) bringt PHP_CodeSniffer mit den WordPress Coding Standards und PHPCompatibilityWP mit. Regeln: `phpcs.xml.dist` – Sicherheit (Escaping, Nonces, Eingabebereinigung), Datenbank, i18n (Textdomain, Übersetzerkommentare), veraltete WordPress-Funktionen, PHP 8.2+-Kompatibilität.

```bash
vendor/bin/phpcs -q --report=summary
```

CI (`ci.yml`, Job `php`) bricht bei **Fehlern** ab; Warnungen werden angezeigt. Stand 13.11.2026: 0 Fehler. Die rund 1 580 Warnungen stammen fast vollständig aus `WordPress.DB.PreparedSQL` für interpolierte Tabellennamen (`{$wpdb->prefix}wpcap_…`) und Integer-Listen – sie werden schrittweise abgebaut; neue Abfragen sollen Werte immer über `$wpdb->prepare()` übergeben. Zusätzlich prüft `composer audit` die Abhängigkeiten auf bekannte Sicherheitslücken.

**UTF-8-sicheres Kürzen (A478):**

```bash
php tests/utf8-substr-check.php --self-test   # src/
```

Text wird mit `PassCaptain\Text::cut( $text, $zeichen )` gekürzt (Zeichen statt Bytes, ungültiges UTF-8 wird
ersetzt). Ein byteweises `substr()` kann ein Mehrbyte-Zeichen halbieren: `wp_json_encode()` liefert dann `false`
(leere Audit-Details), `esc_html()` einen leeren Text, und eine Datenbank im Strict-Modus lehnt den ganzen
Schreibvorgang ab („Incorrect string value“). Die Prüfung meldet jedes `substr( $x, 0, N )`, dessen erstes
Argument Text ist (`sanitize_text_field()`, `sanitize_textarea_field()`, `__()`, `Redact::text()`,
`get_error_message()`, Variablen wie `$reason`, `$note`, `$message`) oder das auf 100 und mehr Bytes kürzt.
Ausnahmen (ASCII-Werte mit Byte-Grenze, z. B. HTTP-Header `Idempotency-Key`) stehen mit Begründung in
`WPCAP_SUBSTR_ALLOW`. Läuft im CI-Job `php`.

Noch offen: PHPStan mit WordPress-Stubs (in dieser Umgebung nicht installierbar, weil Composer keine GitHub-Downloads erhält; sollte in CI mit Baseline nachgezogen werden).

## JavaScript: Lint und Logiktests (A174)

```bash
npm ci
npm run lint:js   # ESLint (empfohlene Regeln) über alle eigenen Skripte in assets/, src/blocks/ und tests/
npm run test:js   # node:test – Logik der Offline-App (Code-Normalisierung, Paketsignatur/Format/Gerät, Offline-Einlassentscheidung)
```

Die Offline-App wird dazu in einer Node-Sandbox mit minimalen Browser-Attrappen geladen (`tests/js/offline-scanner.test.js`) und über `window.wpcapOffline` geprüft: manipulierte Pakete, fremde Geräte, unbekannte/abgelaufene Schlüssel, Doppel-Einlass, Mehrfacheinlass, Bereichsbeschränkung, Einlass-ab-Zeit und abgelaufene Pakete. Beides läuft im CI-Job `php`. Globale Daten aus `wp_localize_script()` sind in `eslint.config.mjs` als `readonly` deklariert – neue lokalisierte Objekte dort eintragen.
## Zwei-Plugin-Test mit Form Captain (`tests/form-captain/`)

Prüft den Form-Captain-Adapter gegen das echte Form Captain (Signatur, Zweckregistrierung, Einsendung). Nutzt die
WordPress-Testbibliothek, PHPUnit und die Testkonfiguration eines Form-Captain-Checkouts mit `vendor/`; braucht
eine Wegwerf-Datenbank (`WP_TESTS_DB_NAME`, `_USER`, `_PASSWORD`, `_HOST`, Standard `wp`/`wp`@`localhost`).

```bash
FORM_CAPTAIN_DIR=/pfad/zu/wp-formmaster WP_TESTS_DB_NAME=wp_passfc_tests \
  php "$FORM_CAPTAIN_DIR/vendor/bin/phpunit" -c tests/form-captain/phpunit.xml
```

Abgedeckt: Zweck `pass.event_registration` registriert, nur veröffentlichte Formulare zuordenbar, Einsendung mit
verifiziertem Eventkontext erzeugt keine Bestellung/Reservierung/Ticket, manipulierte Event-ID →
`context_signature_invalid`, abgesagtes Event → `context_ref_rejected`, Event-Karte mit Formular und gültigem
Kontext. Übergabe (PASS-FC-05): Connector bis zum Opt-in deaktiviert (Job pausiert), Job im Worker legt genau eine
Anfrage „zu prüfen“ an, Wiederholung liefert dieselbe `remote_id`, Einwilligungswiderruf bzw. Entry-Löschung entfernt
die offene Anfrage. Nicht in CI (braucht einen Form-Captain-Checkout).
