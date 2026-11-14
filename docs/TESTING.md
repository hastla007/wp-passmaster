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
| WordPress.org Plugin Check | `wordpress/plugin-check-action` | – | `ci.yml` `plugin-check`, blockierend |
| Release-Gate-Skript | `tests/release/verify-release.test.sh` | ja | **nein** (nur das Skript selbst läuft in `release.yml`) |
| JS-Unit-Tests | `tests/js` (`npm test`) | ja | **nein** (Einstieg `tests/ci/browser-tests.sh js` vorhanden) |
| Barrierefreiheit (Gate) | `tests/a11y/gate.sh` | ja (Wegwerf-WordPress) | **nein** (Einstieg `tests/ci/browser-tests.sh a11y` vorhanden) |
| Browser-E2E (Chromium) | `tests/e2e/run.sh` | ja (Wegwerf-WordPress) | **nein** (Einstieg `tests/ci/browser-tests.sh e2e` vorhanden) |

**Wichtig:** Die Browser-Suiten (JS, a11y, E2E) und der Shell-Test des Release-Gates sind derzeit in keinem
Workflow eingebunden. Sie sind deshalb vor jedem Release manuell auszuführen und im Freigabeprotokoll zu
vermerken ([RELEASE.md](RELEASE.md)). Ein CI-Job mit `tests/ci/browser-tests.sh all` ist vorbereitet, aber
noch nicht angelegt.

Stand 14.11.2026 (lokal, PHP 8.3, MariaDB 10.11): Integration **2303/2303**, JS **56/56**, E2E **11/11**
(Chromium, WooCommerce 11.3 für den Blocks-Test), Release-Gate-Test **28/28**, a11y-Gate bestanden (53 Seiten,
0 Verstöße), Lizenz-Wächter: 235 ausgelieferte Dateien ohne Befund.

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
```

Variablen: `WPCAP_TEST_DB_HOST`, `_USER`, `_PASS`, `_NAME` (Standard `127.0.0.1`/`wp`/`wp`/`wp`). Ausgabe
`ok`/`FAIL` je Prüfung, am Ende `N passed, M failed`; Exit-Code ≠ 0 bei Fehlern.

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
  Datenbank MariaDB 11.4. Variablen: `WPCAP_IT_DB_*`, `WPCAP_IT_WP_VERSION`, `WPCAP_IT_WC_VERSION`,
  `WPCAP_IT_WP_CORE_DIR`/`WPCAP_IT_WC_ZIP` (lokale Quellen), `WPCAP_IT_KEEP=1`.
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
WPCAP_E2E_BROWSER=firefox tests/e2e/run.sh              # andere Engine (Browser vorher installieren)
```

| Datei | Ablauf |
|---|---|
| `checkout.test.js` | Bezahlte Bestellung über Fake-Zahlung → 2 Tickets, Namen im Portal; kostenlose Bestellung; Fehlerzusammenfassung |
| `portal.test.js` | Teilnehmerzuweisung, erneuter Versand mit Dialog, einmalige Übertragung |
| `scanner.test.js` | Kamera verweigert → Meldung, manueller Code checkt ein, zweiter Scan abgelehnt; Fake-Kamera → Video läuft |
| `offline-scanner.test.js` | PWA: Kopplung, signiertes Paket, Scan offline → lokale Entscheidung + Warteschlange, Doppelscan abgelehnt, Sync nach Reconnect |
| `woocommerce-blocks.test.js` | Blocks-Checkout mit Teilnehmer-Fieldset, ungültige E-Mail blockiert, Angaben am Woo-Auftrag (ohne `WPCAP_WOO_ZIP` übersprungen) |

## Ausstehend

- **CI-Einbindung** von `tests/ci/browser-tests.sh all` (JS, a11y, E2E) und `tests/release/verify-release.test.sh`.
- **Browser:** E2E läuft nur mit **Chromium**. Firefox und WebKit (`WPCAP_E2E_BROWSER`; Fake-Kamera-Prüfung
  dort übersprungen) sowie echte Geräte **iOS Safari** und **Android Chrome** (Kamera-Berechtigung,
  BarcodeDetector vs. jsQR, PWA-Installation, Offline-Modus, Hintergrund-Sync) – manuell oder über einen
  Gerätedienst.
- **Echte Zahlungsanbieter:** Stripe, PayPal und Mollie sind nur gegen nachgebaute APIs getestet; Abnahme gegen
  die Sandboxes (Zahlung, Webhook, Teil-/Vollerstattung) ist manuell ([RELEASE.md](RELEASE.md)).
- **WooCommerce-Checkout E2E in CI:** Der Blocks-Test läuft nur lokal mit `WPCAP_WOO_ZIP`; der klassische
  Checkout und ein Durchlauf in einem echten Shop (Theme, Zahlungs-Plugins) fehlen.
- **Weitere Dienste:** Apple/Google Wallet, Twilio, Fiskaldienste nur mit Fakes getestet.
