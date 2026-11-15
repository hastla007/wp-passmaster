# Tests und Qualitätstore

Dieses Dokument beschreibt alle Testebenen von Pass Captain: was sie prüfen, wie man sie lokal startet und
was die CI (`.github/workflows/*.yml`) davon automatisch ausführt. Welche Tests vor einem Release verbindlich
grün sein müssen, steht in [RELEASE.md](RELEASE.md); die unterstützten Plattformen in
[COMPATIBILITY.md](COMPATIBILITY.md).

## Überblick

| Ebene | Werkzeug / Einstieg | Lokal | CI (Workflow, Job) |
|---|---|---|---|
| Syntax PHP/JS, Composer-Metadaten | `php -l`, `node --check`, `composer validate` | ja | `ci.yml` `php` (PHP 8.2/8.3/8.4), blockierend |
| Statische Analyse + Versionskonsistenz | phpcs (Baseline), PHPStan (Baseline), `composer audit`, `build/check-versions.sh` | ja | `ci.yml` `static`, blockierend |
| Lizenz-Wächter | `tests/guard/no-license-code.php` | ja | `ci.yml` `license-guard` und `package`, blockierend |
| Secret-Scan, Abhängigkeitsprüfung | gitleaks, `dependency-review-action` | ja (gitleaks) | `ci.yml` `secrets` und `package`, blockierend |
| Integrationssuite (echte DB) | `tests/integration/run.php` | ja | `ci.yml` `integration`: PHP 8.2/8.3/8.4 × MariaDB 10.6/11.4, MySQL 8.0/8.4, blockierend |
| Last-Smoke (Nebenläufigkeit) | `tests/load/run.php --profile=ci` | ja | `ci.yml` `integration`, gleiche Matrix, blockierend |
| Lasttests `default`/`heavy` | `tests/load/run.php` | ja | `load.yml` wöchentlich + manuell, MariaDB 11.4 / MySQL 8.4 |
| Lasttest über REST | `tests/load/http.php` | ja (Wegwerf-WordPress) | nein |
| Reproduzierbares ZIP, Paketinhalt | `build/release.sh`, `build/ci-install-test.sh` (statisch) | ja | `ci.yml` `package`, blockierend |
| Installationstest des ZIPs | `build/ci-install-test.sh <zip>` + `build/ci-smoke.php` | ja (Wegwerf-DB) | `ci.yml` `install-test` (WP aktuell/6.4, WooCommerce aktuell/8.2), blockierend |
| Update von 1.4.0 mit Daten | `WPCAP_IT_UPGRADE_FROM=<commit> build/ci-install-test.sh <zip>` + `build/ci-data.php` | ja (Wegwerf-DB) | `ci.yml` `install-test`, Bein „Update from 1.4.0“, blockierend |
| Deinstallation (Daten behalten / entfernen) | `build/ci-install-test.sh` (Schritt 5) | ja (Wegwerf-DB) | `ci.yml` `install-test`, alle Beine, blockierend |
| Restore-Probe (Backup → Zerstörung → Restore → Diagnose) | `tests/drills/restore-drill.sh` | ja (Wegwerf-DB) | `drills.yml` wöchentlich + manuell, MariaDB 11.4 / MySQL 8.0 / 8.4 |
| WordPress.org Plugin Check | `wordpress/plugin-check-action` | – | `ci.yml` `plugin-check`, blockierend |
| Release-Gate-Skript | `tests/release/verify-release.test.sh` | ja | **nein** (nur das Skript selbst läuft in `release.yml`) |
| JS-Unit-Tests | `tests/js` (`npm test`) | ja | `ci.yml` `php` (Bein PHP 8.4), blockierend |
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

- PHP 8.2+ mit `mysqli`, `mbstring`, `sodium`, `gd`, `zip`, `openssl`; `composer install` (inkl. Dev-Paketen
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
| `zzzz_w6_privacy_volume.php` | Export/Löschung/Retention mit 400 (langsam: 5.000) Bestellungen, kommenden Terminen, Rechnungen, WooCommerce-Bestellungen und zweiter Site; Zeitbudget, Finanzbelege bleiben. |

Gemeinsamer Fehler-Proxy für `$wpdb`: `tests/integration/lib/fault_wpdb.php` (`wpcap_faults()`,
`->fail( $regex, $skip, $times, $kind )`, `->fail_nth( $n, $kind )`, `wpcap_faults_restore()`).

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
```

| Szenario | Was passiert | Invarianten |
|---|---|---|
| checkout | B Käufer (70 % 1 Ticket, sonst 2–4) kaufen eine Ticketart mit begrenztem Kontingent über `Orders::create()`; 15 % schicken denselben Idempotency-Key gleichzeitig über eine zweite Verbindung (Doppelklick/Retry) | kein Überverkauf; gehaltene Tickets = Tickets erfolgreicher Bestellungen; je Key genau eine Bestellung; keine unerwarteten Fehler |
| check-in | T Tickets, D Geräte scannen jedes Ticket gleichzeitig (gerade Geräte in Reihenfolge, ungerade gemischt), danach Wiederholung mit gleichem Key | genau ein erfolgreicher Scan und genau eine Einlasszeile pro Ticket; jede „eingelassen“-Antwort hat eine Einlasszeile; Replays idempotent |
| webhooks | E Stripe-Events (`checkout.session.completed`), jedes R-mal gleichzeitig zugestellt, mit echter Signaturprüfung | jedes Event genau einmal angewendet; alle Bestellungen bezahlt; Tickets genau einmal ausgestellt; eine Event-Zeile je Event |

Ausgabe: Requests, Wall-Time, Durchsatz, p50/p95/p99/max-Latenz je Operation, InnoDB-Deadlocks
(Server-Zähler; nur aussagekräftig, wenn sonst niemand die DB nutzt) und Fehlerquote. **Exit-Code 1 bei
verletzter Invariante.** „sold_out“ ist ein erwartetes Ergebnis, kein Fehler.

Der früher gefundene Check-in-Fehler (Gap-Lock-Deadlock auf `wpcap_admission_state`, falsche
„eingelassen“-Antworten) ist behoben: `Admission\Engine` liest den Einlasszustand ohne zusätzliches
`FOR UPDATE`, weil die Ticketzeile bereits gesperrt ist. Der Last-Smoke in CI ist seitdem blockierend
(kein `continue-on-error`) und dient als Regressionstest.

**CI:** `ci.yml`, Job `integration`, bei jedem Push/PR mit `--profile=ci` auf allen 12 Kombinationen
PHP × Datenbank. **Geplant:** `load.yml` montags 03:17 UTC und manuell (`workflow_dispatch`, Profil
`all|default|heavy`) mit `default` und `heavy` auf MariaDB 11.4 und MySQL 8.4, PHP 8.4. Der Job scheitert nur
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

Der Kern bleibt frei (Entscheidung 13.11.2026, [FREE-PREMIUM.md](FREE-PREMIUM.md)). Der Wächter scheitert,
sobald Lizenzclient, Rechteprüfung, ein `Update URI`-Header oder eigene Update-Hooks in ausgelieferten Dateien
auftauchen. Er läuft gegen den Quellbaum (Job `license-guard`, mit Selbsttest) und gegen das entpackte ZIP
(Job `package`).

```bash
php tests/guard/no-license-code.php --self-test     # jede Regel erkennt ein eingeschleustes Muster
php tests/guard/no-license-code.php [verzeichnis]
```

## Secret-Scan (Job `secrets`, Schritt in `package`)

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
  `GET /wp-pass-captain/v1/events`, QR-Code; mit WooCommerce HPOS-Kompatibilität und eine Woo-Bestellung über den
  aktiven Speicher – mit HPOS an und aus, danach Deaktivieren/Aktivieren und erneuter Smoke-Check.
  CI-Matrix: WordPress aktuell (PHP 8.4) und 6.4 (PHP 8.2), WooCommerce aktuell (PHP 8.4) und 8.2.0 (PHP 8.2),
  Update von 1.4.0 (PHP 8.4), Datenbank MariaDB 11.4. Variablen: `WPCAP_IT_DB_*`, `WPCAP_IT_WP_VERSION`,
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
  das Löschen der alten Kopie entfernt keine Daten.
- **Deinstallation** (jedes Bein): mit Standardeinstellungen `wp plugin deactivate` + `wp plugin uninstall` →
  alle Tabellen, Einstellungen, Schema-Version und Rollen bleiben, nur die Cron-Ereignisse sind weg; erneutes
  Installieren findet die Daten unverändert wieder. Mit `delete_data_on_uninstall=1` bleiben keine Tabellen,
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
(`lib/dom.js`), Fake-Timern, `fetch`-Mock und In-Memory-IndexedDB (`lib/idb.js`) aus. 56 Tests:

| Datei | Abgedeckt |
|---|---|
| `checkout.test.js` | Haltezeit-Texte, `money()` wie `Format::money()`, Summe und Teilnehmer-Fieldsets, Validierung, Fehlerzusammenfassung (Fokus, ARIA), Server-Fehler → Feld, Rechnungsdaten, REST-Payload, Idempotency-Key |
| `pos.test.js` | Kasse: Betragsformat/-parser, Summe, Rückgeld, Karte/frei, Teilnehmerfelder, Doppel-Submit, Verfügbarkeits-Refresh |
| `scanner.test.js` | Web-Scanner: Online-Check-in, Serverfehler, Offline nie automatisch eingelassen, Warteschlange pro Benutzer, Ablauf nach 24 h, Sync in 100er-Blöcken, Einlassmodul, Kamera verweigert/fehlt |
| `order-portal.test.js` | Übertragung mit Bestätigungsdialog, Teilnehmerzuweisung, erneuter Versand, Rechnungsdaten |
| `seating-picker.test.js` | Saalplan: Rollen/Labels, Listen-Fallback, Auswahl, Tastatur, Stehplätze, Mengenänderung, Payload |
| `offline-scanner.test.js` | PWA: i18n, Kopplung, Ed25519-Paketprüfung, Delta-Kette, Offline-Entscheidung, IndexedDB-Warteschlange und Sync mit Konflikten, Serverausfall, 401 |

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
| `offline-scanner.test.js` | PWA: Kopplung, signiertes Paket, Scan offline → lokale Entscheidung + Warteschlange, Doppelscan abgelehnt, Sync nach Reconnect |
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
