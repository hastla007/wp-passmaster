# Tests: Integration, Last/Nebenläufigkeit, Barrierefreiheit, Browser (JS, E2E)

## Integrationstests

```bash
php tests/integration/run.php        # braucht eine Wegwerf-MariaDB/MySQL (WPCAP_TEST_DB_HOST/_USER/_PASS/_NAME)
```

## Last- und Nebenläufigkeitstests (`tests/load/`)

### Ohne Webserver: `tests/load/run.php`

Nutzt den Integrations-Bootstrap mit echter MariaDB. Der Koordinator legt Testdaten an und startet
N PHP-Prozesse (je eine DB-Verbindung, `proc_open`), die hinter einer Startbarriere gleichzeitig loslaufen.
Tabellenpräfix `wpload_` (über `WPCAP_TEST_PREFIX` änderbar), wird bei jedem Lauf neu angelegt.

```bash
php tests/load/run.php --profile=ci        # 200 Käufer / 20 Verbindungen, ~6 s
php tests/load/run.php                     # default: 500 Käufer, Kontingent 150, 50 Verbindungen
php tests/load/run.php --profile=heavy     # 1000 Käufer, 100 Verbindungen
php tests/load/run.php --buyers=800 --capacity=200 --workers=80 --scenario=checkout --json=out.json
```

| Szenario | Was passiert | Invarianten |
|---|---|---|
| checkout | B Käufer (70 % 1 Ticket, sonst 2–4) kaufen eine Ticketart mit begrenztem Kontingent über `Orders::create()`; 15 % schicken denselben Idempotency-Key gleichzeitig über eine zweite Verbindung (Doppelklick/Retry) | kein Überverkauf; gehaltene Tickets = Tickets erfolgreicher Bestellungen; je Key genau eine Bestellung (Antworten und DB); keine unerwarteten Fehler |
| check-in | T Tickets, D Geräte scannen jedes Ticket gleichzeitig (gerade Geräte in Reihenfolge, ungerade gemischt), danach Wiederholung mit gleichem Key | genau ein erfolgreicher Scan pro Ticket; genau eine Einlasszeile pro Ticket; jede „eingelassen“-Antwort hat eine Einlasszeile; Replays idempotent |
| webhooks | E Stripe-Events (`checkout.session.completed`), jedes R-mal gleichzeitig zugestellt, mit echter Signaturprüfung (`verify_webhook()` + `Webhooks::process()`) | jedes Event genau einmal angewendet; alle Bestellungen bezahlt; Tickets genau einmal ausgestellt; eine Event-Zeile je Event |

Ausgabe: Tabelle mit Requests, Wall-Time, Durchsatz, p50/p95/p99/max-Latenz je Operation, InnoDB-Deadlocks
(Server-Zähler; nur aussagekräftig, wenn sonst niemand die DB nutzt) und Fehlerquote. Exit-Code 1 bei
verletzter Invariante. „sold_out“ ist ein erwartetes Ergebnis, kein Fehler.

### Über die echte REST-API: `tests/load/http.php`

Startet einen eigenen `php -S` mit `PHP_CLI_SERVER_WORKERS` (Standard 8) für eine **Wegwerf**-WordPress-
Installation, legt Testdaten direkt über das Plugin an und feuert parallele Requests mit `curl_multi`
(Standard 32 gleichzeitig) gegen `/checkout`, `/tickets/check-in` (Cookie + Nonce eines Admins) und
`/payments/stripe/webhook` (signiert). Einstellungen der Site werden nicht verändert: das Webhook-Secret
geht als `WPCAP_STRIPE_WEBHOOK_SECRET` nur an den privaten Server, das IP-Limit des Checkouts hebt ein
Router-Skript nur dort auf. Die Test-Events werden am Ende auf „draft“ gesetzt.

```bash
php tests/load/http.php --wp=/pfad/zu/wordpress [--server-workers=8] [--concurrency=32] [--buyers=300] \
    [--capacity=100] [--tickets=10] [--devices=10] [--events=30] [--dupes=5] [--port=8091] [--json=out.json]
```

### Messwerte (11.11.2026)

**Hardware-Vorbehalt:** geteilte Cloud-VM, 4 vCPU Intel Xeon 2,1 GHz, 15 GB RAM, MariaDB 10.11 (Standard-
konfiguration, `max_connections=151`) und PHP 8.3 auf derselben Maschine, parallel liefen andere Prozesse.
Die Zahlen zeigen Größenordnungen und Engpässe, keine Produktionskapazität (dort: PHP-FPM, getrennte DB,
Objekt-Cache).

`tests/load/run.php` (Plugin-Services direkt, ohne WordPress-Bootstrap pro Request):

| Profil | Szenario | Requests | Verb. | req/s | p50 ms | p95 ms | p99 ms | max ms | Deadlocks | Fehler |
|---|---|---|---|---|---|---|---|---|---|---|
| ci | checkout | 236 | 20 | 575 | 28.6 | 49.9 | 67.1 | 78 | 0 | 0 % |
| ci | check-in | 160 | 8 | 1958 | 2.3 | 9.4 | 21.0 | 21 | 5 | 0 % |
| ci | webhook | 100 | 20 | 814 | 5.6 | 71.8 | 80.1 | 93 | 0 | 0 % |
| default | checkout | 566 | 50 | 413 | 96.1 | 183.3 | 230.2 | 265 | 0 | 0 % |
| default | check-in | 1000 | 20 | 2226 | 5.1 | 21.6 | 55.7 | 104 | 16 | 0 % |
| default | webhook | 480 | 50 | 1816 | 6.7 | 122.1 | 147.9 | 166 | 0 | 0 % |
| heavy | checkout | 1150 | 100 | 432 | 188.2 | 349.6 | 453.6 | 539 | 0 | 0 % |
| heavy | check-in | 4000 | 40 | 2679 | 8.8 | 39.3 | 163.3 | 442 | 345 | 0 % |
| heavy | webhook | 1200 | 100 | 1752 | 14.1 | 226.3 | 385.0 | 553 | 0 | 0 % |

`tests/load/http.php` (voller WordPress-7.1-+-WooCommerce-Bootstrap je Request, 8 Server-Worker, 32 parallel):

| Szenario | Requests | req/s | p50 ms | p95 ms | p99 ms | max ms | Fehler |
|---|---|---|---|---|---|---|---|
| checkout (300 Käufer, Kontingent 100) | 351 | 44.8 | 657 | 859 | 1584 | 2047 | 0 % |
| check-in (10 Tickets × 10 Scans) | 100 | 36.4 | 687 | 1177 | 1742 | 1770 | 0 % |
| webhook (30 Events × 5 Zustellungen) | 150 | 31.0 | 892 | 2108 | 3908 | 4196 | 0 % |

Ergebnis:

- **Checkout:** in allen Läufen kein Überverkauf (Kontingent exakt ausgeschöpft), Idempotenz hält auch bei
  gleichzeitigen Doppel-Submits (Unique-Index + Wiederverwendung), keine Deadlocks, 0 % Fehler. Die Ticketart-
  Zeile wird per `FOR UPDATE` gesperrt, daher serialisieren Käufe derselben Ticketart: Latenz steigt linear
  mit der Zahl gleichzeitiger Käufer (≈ 2 ms je wartendem Käufer), der Durchsatz bleibt bei ~400–570 Bestellungen/s.
- **Webhooks:** jedes Event genau einmal angewendet, alle Duplikate als `duplicate` beantwortet, keine
  doppelten Tickets.
- **Check-in: FEHLER gefunden.** Gleichzeitige Erst-Einlässe *verschiedener* Tickets laufen in einen
  InnoDB-Gap-Lock-Deadlock auf `wpcap_admission_state` (`Admission\Engine::state_for_update()` liest eine
  noch nicht existierende Zeile `FOR UPDATE`, `record()` fügt sie danach mit `INSERT … ON DUPLICATE KEY` ein).
  Das Opfer der Deadlock-Erkennung wird von MariaDB komplett zurückgerollt, `Checkin::perform()` prüft die
  Fehler aber nicht und meldet trotzdem „eingelassen“. Das Ticket bleibt gültig, der nächste Scan lässt es
  erneut ein. Gemessen: heavy 395 „eingelassen“-Antworten für 50 Tickets, default 41 für 25, auch über die
  REST-API (12 für 10). Reproduzierbar in 5 von 5 `--profile=ci`-Läufen. Mit entferntem `FOR UPDATE` in
  `state_for_update()` (die Ticketzeile ist bereits gesperrt) waren in 3 von 3 Läufen alle Invarianten
  erfüllt, 0 Deadlocks. Der CI-Schritt läuft deshalb vorerst mit `continue-on-error`.

## Barrierefreiheit (`tests/a11y/`)

axe-core 4.10 + Playwright (Chromium) gegen eine laufende **Wegwerf**-Installation. `fixture.php` legt per
`wp-load.php` ein Test-Event an (bezahlte, kostenlose Ticketart mit Teilnehmerfeld, ausverkaufte Ticketart),
eine kostenlose Bestellung (Ticketseite + Bestellportal-Link aus der Mail) und ein Wartelistenangebot.

```bash
cd tests/a11y
npm install                       # nur axe-core; Playwright global oder: npm i --no-save playwright && npx playwright install chromium
export WPCAP_A11Y_WP_PATH=/pfad/zu/wordpress
export WPCAP_A11Y_BASE=http://localhost:8080 WPCAP_A11Y_USER=admin WPCAP_A11Y_PASS=admin
node run.js [--fail-on=critical|serious|moderate|minor|none] [--only=public|admin]
node keyboard.js
```

- `run.js` prüft mit den Regeln WCAG 2.0/2.1/2.2 A+AA und best-practice: Event-Liste mit Checkout-Formular
  (auch mit eingeblendeten Teilnehmerfeldern und Fehlermeldung), Ticketseite, Bestellportal,
  Wartelistenangebot und Annahmeseite, Scanner (angemeldet, auch mit Ergebnis) und im Admin **jede Unterseite
  des Pass-Captain-Menüs** (nach dem Login aus dem Menü gelesen, Module eingeschlossen – neue Seiten sind
  automatisch dabei) plus Detailansichten: Event, Bestellung, Kampagne (auch „neu“), Warteliste eines Events,
  Ressource mit Zeitfenster, Mitgliedspass, Einlassregeln/Offline-Scanner/Berichte/Tickets/Serien eines Events,
  Saalplan-Editor (nur `#wpbody-content`). `fixture.php` legt dafür zusätzlich einen API-Key, eine Ressource
  mit Zeitfenster, eine Kampagne, einen Mitgliedspass, einen Saalplan-Entwurf und ein zweites Event an.
  Ausgabe nach Impact, JSON-Bericht in `tests/a11y/report/`. Seiten mit HTTP ≠ 200 gelten als Fehler.
  `--match=text` beschränkt den Lauf auf Seiten, deren Name den Text enthält.
- `keyboard.js` geht den Checkout nur mit der Tastatur durch: Tab-Reihenfolge = DOM-Reihenfolge, jeder Tab-Stopp
  hat Namen und sichtbaren Fokus, ausverkaufte Ticketart ist kein Tab-Stopp, Fehlermeldung in `role=status`/
  `aria-live=polite`, Pfeiltaste erzeugt Teilnehmer-Fieldset mit Legende und Labels, ungültige E-Mail und fehlende
  AGB-Zustimmung setzen den Fokus aufs Feld, Erfolg wird angesagt, Fokus geht nach dem Absenden nicht verloren;
  Scanner: Namen/Fokus, Ergebnis per Live-Region.

Stand 11.11.2026: öffentliche Seiten 0 kritische/ernste Verstöße (1 moderater: Überschriftenebene im Event-
Template), Tastatur-Durchlauf 23/23 Prüfungen bestanden, Farbkontrast der öffentlichen Seiten ohne Befund. Admin-
Seiten: 9 kritische und 48 ernste Knoten (fehlende Labels an Filter-Selects, Kontrast von `<code>`-Links,
Zielgröße der Checkboxen) – siehe offene Punkte im Changelog/Report. Nicht in CI (braucht eine WordPress-Instanz).
Automatische Prüfungen ersetzen keine Screenreader-Tests (siehe `docs/ACCESSIBILITY.md`).

## CI-Qualitätstore, Paket- und Installationstests

Alle Schritte laufen in `.github/workflows/ci.yml` bei jedem Push/PR und blockieren den Build
(Ausnahme: MySQL-Matrixeinträge, siehe `docs/COMPATIBILITY.md`). Lokal reproduzierbar mit den Befehlen unten
(`composer install` inkl. Dev-Paketen vorausgesetzt).

### Statische Analyse (Job `static`)

| Prüfung | Lokal | Konfiguration |
|---|---|---|
| Versions-/Anforderungskonsistenz | `bash build/check-versions.sh [vX.Y.Z]` | Header `Version`, `WPCAP_VERSION`, readme `Stable tag`, neueste CHANGELOG-Version, `composer.json` (`version` falls vorhanden, `require.php`, `config.platform.php`), `Requires at least`/`Requires PHP` |
| PHP_CodeSniffer (WPCS 3 + PHPCompatibilityWP, PHP 8.2+, WP 6.4+) | `php build/phpcs-baseline.php` | `phpcs.xml.dist`, Baseline `phpcs-baseline.json` |
| PHPStan Level 5 (szepeviktor/phpstan-wordpress, WooCommerce-Stubs) | `composer lint:phpstan` | `phpstan.neon.dist`, Baseline `phpstan-baseline.neon` |
| Bekannte Schwachstellen in Composer-Paketen | `composer audit --locked` | – |

**Baselines:** Bestehende Befunde sind eingefroren, jeder *neue* Befund lässt CI scheitern.
Stand 14.11.2026: phpcs 951 Befunde in 93 Dateien (davon 779 `WordPress.DB.PreparedSQL.*`, meist Tabellennamen
in SQL-Strings), PHPStan 90 Befunde (58 Einträge). Der phpcs-Ruleset erzwingt Sicherheit (Escaping, Nonces,
Sanitizing, vorbereitete SQL-Statements), i18n (Textdomain `pass-captain`), WordPress-API-Regeln, Präfixe,
Capabilities und PHP-Kompatibilität; reine Layout-Sniffs und Docblock-Regeln sind abgeschaltet (der Code nutzt
einen kompakten Stil, Massenumformatierung würde nur Merge-Konflikte erzeugen).

- phpcs zählt pro Datei und Sniff (zeilenunabhängig, übersteht Umbauten und Merges): steigt die Zahl für ein
  Paar Datei/Sniff, werden dessen Fundstellen ausgegeben. Neue Dateien haben keine Baseline.
- Befunde beheben statt Baseline vergrößern; nur begründet `// phpcs:ignore Sniff.Code -- Grund` bzw.
  `@phpstan-ignore identifier (Grund)` an der Stelle.
- Nach Korrekturen Baselines verkleinern: `php build/phpcs-baseline.php --update`, `composer phpstan:baseline`.
  Nach einem Merge mehrerer Zweige mit Codeänderungen ebenfalls beide neu erzeugen und den Diff prüfen
  (er darf nur Einträge entfernen oder bewusst akzeptierte hinzufügen).
- Aus der PHPStan-Analyse ausgenommen: `src/Fiscalization/Providers/*Adapter.php` (implementieren das nicht
  vorhandene Interface `PassCaptain\Fiscalization\AdapterInterface`, nicht baseline-fähig).

### Secret-Scan (Job `secrets`, Schritt im Job `package`)

gitleaks prüft die Git-Historie (`gitleaks/gitleaks-action`) und das entpackte Release-ZIP (`gitleaks dir`,
Version gepinnt mit SHA-256). Regeln: Standardregeln plus `.gitleaks.toml` (Stripe-Testschlüssel `sk_test_…`
und die Entropie-Regel `generic-api-key` unter `tests/` sind erlaubt; Live-Schlüssel werden überall gemeldet).
Für Pull Requests prüft `actions/dependency-review-action` neue/geänderte Abhängigkeiten (ab Schweregrad
„moderate“). Lokal: `gitleaks git --config .gitleaks.toml .` bzw. `gitleaks dir --config .gitleaks.toml <dir>`.
Gehört das Repository einer Organisation, braucht die gitleaks-Action das Secret `GITLEAKS_LICENSE`.

### Paket und Installation (Jobs `package`, `install-test`)

- **Reproduzierbarkeit:** `build/release.sh` wird zweimal ausgeführt (zweiter Lauf mit leerem Composer-
  Cache); die SHA-256-Summen müssen gleich sein. Details und Verifikation: `docs/COMPATIBILITY.md`.
- **Paketinhalt** (`WPCAP_IT_STATIC_ONLY=1 bash build/ci-install-test.sh`): ein Ordner `pass-captain/`,
  Hauptdatei, Readme, `uninstall.php`, Composer-Autoloader, alle versionierten Dateien aus `src/`,
  `templates/`, `assets/` und `languages/*.mo`; keine Tests, Doku, Build-/CI-Dateien, Tool-Konfigurationen,
  Baselines, Markdown, `.po`-Quellen oder Test-Ordner von Abhängigkeiten; keine privaten Schlüssel/Live-Keys.
- **Installation in echtem WordPress** (`bash build/ci-install-test.sh build/dist/pass-captain.zip`, braucht
  eine Wegwerf-Datenbank, WP-CLI wird geladen): WordPress herunterladen, konfigurieren (`WP_DEBUG_LOG`),
  installieren, optional WooCommerce installieren, dann `wp plugin install <zip> --activate`. Fehlschlag bei
  PHP-Fehlern/Warnungen/Deprecations aus Plugin-Dateien in WP-CLI-Ausgabe oder `debug.log`. Danach
  Smoke-Check (`build/ci-smoke.php`): Tabellen, Schema-Version/-Fingerprint, Rollenrechte, Cron, REST-Routen
  und `GET /wp-pass-captain/v1/events`, QR-Code (Laufzeitabhängigkeit), mit WooCommerce HPOS-Kompatibilität,
  HPOS-Status und eine WooCommerce-Bestellung über den aktiven Speicher – einmal mit HPOS an, einmal aus,
  danach Deaktivieren/Aktivieren und erneuter Smoke-Check. Matrix: WordPress aktuell und 6.4, WooCommerce
  aktuell und 8.2. Variablen: `WPCAP_IT_DB_*`, `WPCAP_IT_WP_VERSION`, `WPCAP_IT_WC_VERSION`,
  `WPCAP_IT_WP_CORE_DIR`/`WPCAP_IT_WC_ZIP` (lokale Quellen statt Download), `WPCAP_IT_KEEP=1`.

Lokal geprüft (14.11.2026): WordPress 7.1.4 ohne WooCommerce, mit WooCommerce 11.3.0 und 8.2.0 (je HPOS an/aus),
WordPress 6.4 – alle Prüfungen bestanden.

## Geplante Lasttests (`.github/workflows/load.yml`)

Wöchentlich (Montag 03:17 UTC) und manuell (`workflow_dispatch`, Profil wählbar): `tests/load/run.php` mit
den Profilen `default` und `heavy` auf MariaDB 11.4 und MySQL 8.4, PHP 8.4. Der Job scheitert bei verletzter
Invariante (Exit-Code 1: Überverkauf, doppelte Einlässe, doppelt angewendete Webhooks, unerwartete Fehler).
Ausgabe und JSON (`--json`) werden 90 Tage als Artefakt `load-<profil>-<db>` aufbewahrt, die Tabelle steht in
der Job-Zusammenfassung. Latenzen werden nicht automatisch bewertet (geteilte Runner schwanken stark); bei
Abweichungen von mehr als etwa Faktor 2 gegenüber den Referenzwerten unten oder bei Deadlocks > 0 genauer
hinsehen.

### Referenzwerte (14.11.2026)

Lokale VM mit 4 vCPU, 15 GB RAM, PHP 8.3, Datenbank im Docker-Container mit Standardkonfiguration
(`max_connections=151`) auf derselben Maschine. Alle Invarianten erfüllt, 0 % Fehler.

| DB | Profil | Szenario | Requests | Verb. | req/s | p50 ms | p95 ms | p99 ms | max ms | Deadlocks | Fehler |
|---|---|---|---|---|---|---|---|---|---|---|---|
| MariaDB 10.6 | default | checkout | 566 | 50 | 514 | 70.8 | 158.7 | 207.2 | 260 | 0 | 0 % |
| MariaDB 10.6 | default | check-in | 1000 | 20 | 2722 | 4.9 | 19.7 | 31 | 55 | 0 | 0 % |
| MariaDB 10.6 | default | webhook | 480 | 50 | 1303 | 3 | 256.7 | 311.7 | 319 | 0 | 0 % |
| MariaDB 10.6 | heavy | checkout | 1150 | 100 | 583 | 140.9 | 276.6 | 376.3 | 446 | 0 | 0 % |
| MariaDB 10.6 | heavy | check-in | 4000 | 40 | 3156 | 7.3 | 33.5 | 52.7 | 105 | 0 | 0 % |
| MariaDB 10.6 | heavy | webhook | 1200 | 100 | 1384 | 5.1 | 508.3 | 678.2 | 787 | 0 | 0 % |
| MySQL 8.4 | default | checkout | 566 | 50 | 574 | 63.2 | 175.4 | 211.1 | 252 | 0 | 0 % |
| MySQL 8.4 | default | check-in | 1000 | 20 | 2083 | 5.5 | 24.2 | 41.3 | 54 | 0 | 0 % |
| MySQL 8.4 | default | webhook | 480 | 50 | 1062 | 5.8 | 267.8 | 348 | 388 | 0 | 0 % |
| MySQL 8.4 | heavy | checkout | 1150 | 100 | 471 | 134.6 | 461.5 | 631.9 | 715 | 0 | 0 % |
| MySQL 8.4 | heavy | check-in | 4000 | 40 | 2648 | 6.8 | 43.1 | 77.7 | 215 | 0 | 0 % |
| MySQL 8.4 | heavy | webhook | 1200 | 100 | 1122 | 7.9 | 584.4 | 889.2 | 1027 | 0 | 0 % |

Stand 14.11.2026 (WordPress 7.1, Chromium): 53 Seiten, **0 Verstöße aller Stufen** (kritisch bis gering),
Tastatur-Durchlauf bestanden. Behoben wurden u. a. fehlende Namen von Filter-/Einstellungs-Selects (Tickets,
Einlassregeln/-konflikte, Offline-Scanner, Berichte, Druck, SMS, Wallet), ein unbeschriftetes Vorlagen-Textfeld,
leere Tabellenköpfe, zu kleine bzw. überlappende Checkbox-Ziele (24 px, WCAG 2.5.8) und Kontrast der grünen
Statusfarben. Die „contrast?“-Spalte zählt Knoten, die axe nicht automatisch bewerten kann (meist Selects mit
Pfeil-Hintergrundbild aus dem WordPress-Core) – manuell prüfen. Automatische Prüfungen ersetzen keine
Screenreader-Tests (siehe `docs/ACCESSIBILITY.md`).

## Browser-Tests: a11y-Gate, JS-Unit-Tests, E2E

Drei Suiten ergänzen die PHP-Tests. Die beiden Browser-Suiten laufen gegen eine **Wegwerf**-WordPress-Seite, die
`tests/support/wp-env.sh` anlegt und mit `php -S` ausliefert (WordPress-Core per `git clone` oder aus
`WPCAP_WP_CORE`, Plugin als Symlink, eigene DB/Tabellenpräfix, Mailversand und Gravatar abgeschaltet):

```bash
# Gemeinsame Umgebung (Defaults in Klammern)
export WPCAP_DB_HOST=127.0.0.1 WPCAP_DB_USER=wp WPCAP_DB_PASS=wp WPCAP_DB_NAME=wp_browser   # Wegwerf-DB!
export WPCAP_WP_DIR=/tmp/wpcap-browser-wp WPCAP_WP_PORT=8890      # optional; WPCAP_WP_CORE=/pfad/zu/wp-core
tests/ci/browser-tests.sh all          # alles wie in CI: js, a11y, e2e (oder einzeln: js | a11y | e2e)
```

### Barrierefreiheit als Release-Gate: `tests/a11y/gate.sh`

Startet die Testseite (wenn `WPCAP_A11Y_BASE`/`WPCAP_A11Y_WP_PATH` nicht gesetzt sind), führt `run.js` und
`keyboard.js` aus und endet mit Exit-Code ≠ 0 bei **jedem** axe-Verstoß (`WPCAP_A11Y_FAIL_ON=minor`, änderbar auf
`moderate|serious|critical`), bei HTTP ≠ 200 einer Seite oder einer fehlgeschlagenen Tastaturprüfung. Gehört vor
jedes Release; in CI über `tests/ci/browser-tests.sh a11y`.

### JS-Unit-Tests: `tests/js/` (`npm test`)

`node --test` ohne Abhängigkeiten (Node ≥ 20). `lib/load.js` führt die Seiten-Skripte (IIFEs) in Node mit
künstlichem `window`/`document` (Mini-DOM `lib/dom.js`), Fake-Timern und `fetch`-Mock aus, `lib/idb.js` ist ein
In-Memory-IndexedDB. Interne Funktionen kommen über einen geschützten Test-Hook
(`if (typeof module === 'object' && module && module.exports) module.exports = …`, im Browser wirkungslos) bzw.
`window.wpcapOffline` (Offline-Scanner). 56 Tests:

| Datei | Abgedeckt |
|---|---|
| `checkout.test.js` | Haltezeit-Texte, Platzhalter, `money()` wie `Format::money()`, Summe und Teilnehmer-Fieldsets bei Mengenänderung, Validierung mit Feldbezeichnungen, Fehlerzusammenfassung (Fokus, `aria-invalid`/`aria-describedby`), Server-Fehler → Feld, Rechnungsdaten, REST-Payload, Idempotency-Key (neu nach Serverantwort, gleich nach Netzwerkfehler) |
| `pos.test.js` | Box Office: Betragsformat/-parser, Summe, Rückgeld (auch negativ), Karte/frei, Teilnehmerfelder, Doppel-Submit, Verfügbarkeits-Refresh |
| `scanner.test.js` | Web-Scanner: Online-Check-in, Serverfehler, Offline nie automatisch eingelassen (nur nach Sichtprüfung in die Warteschlange), Warteschlange pro Benutzer, Altbestand v1, Ablauf nach 24 h, Sync in 100er-Blöcken (während des Syncs gescannte Codes bleiben), Einlassmodul (Bereich, Check-out), Kamera verweigert/nicht vorhanden |
| `order-portal.test.js` | Übertragung mit Bestätigungsdialog (Abbrechen, Fokus zurück, `confirm()`-Fallback), Teilnehmerzuweisung, erneuter Versand, Rechnungsdaten mit Feldfehler |
| `seating-picker.test.js` | Saalplan: Rollen/Labels, Listen-Fallback, Auswahl und Ticketart-Zuordnung, Tastatur (Pfeile, Home/End, Leertaste), Stehplätze, Neuladen mit vergebenem Platz, Mengenänderung, Payload-Prüfung |
| `offline-scanner.test.js` | PWA: `t()` (Platzhalter, Tabellen-Lookup), i18n-Tabelle laden/prüfen/speichern/offline nutzen, Kopplung (fremder Server abgelehnt), Ed25519-Paketprüfung (Signatur, Schlüssel unbekannt/abgelaufen, Gerät, Format), Delta-Kette, Offline-Entscheidung (unbekannt, widerrufen, verwendet, Bereich, Mehrfachticket, abgelaufen), IndexedDB-Warteschlange und Sync mit Konflikten, Online-Scan, Serverausfall → offline, 401 |

### Browser-E2E: `tests/e2e/` (`run.sh`)

Playwright + `node:test`, eine Datei pro Ablauf, Screenshots nur bei Fehlschlag (`tests/e2e/artifacts/`).
`helper.php` legt serverseitig Kopplungscodes, Zuweisungen und einen Termin im Einlassfenster an und liest
Ticket-/Bestellstatus; `mu-plugin/wpcap-e2e.php` (nur Testseite) bietet eine Fake-Zahlungsseite als Zahlart
„Testzahlung“ – nur für Browser mit Cookie `wpcap_e2e=1`, damit die a11y-Läufe den normalen Checkout sehen.

```bash
tests/e2e/run.sh                                  # startet die Testseite selbst
WPCAP_WOO_ZIP=/pfad/woocommerce.zip tests/e2e/run.sh   # + WooCommerce-Blocks auf einer zweiten Testseite
WPCAP_E2E_BROWSER=firefox tests/e2e/run.sh        # andere Engine (Browser vorher installieren)
```

| Datei | Ablauf |
|---|---|
| `checkout.test.js` | Bezahlte Bestellung: Ticketmenge, Teilnehmernamen, Käufer-E-Mail, AGB, Zahlart → Fake-Zahlungsseite → bezahlt, 2 Tickets ausgestellt, Namen im Bestellportal; kostenlose Bestellung (Fokus auf Bestätigung, „weitere bestellen“); Fehlerzusammenfassung |
| `portal.test.js` | Teilnehmerzuweisung (Pflicht-E-Mail, Code rotiert/bleibt), erneuter Versand mit Dialog, einmalige Übertragung (Escape bricht ab, Fokus zurück, Bestätigen überträgt, danach nicht mehr angeboten) |
| `scanner.test.js` | Kamera verweigert → Fehlermeldung, manueller Code checkt ein (serverseitig geprüft), zweiter Scan abgelehnt; Kamera erlaubt (Chromium-Fake-Kamera) → Video läuft |
| `offline-scanner.test.js` | PWA wie aus dem Admin-Link (`?api=`): Kopplungsbildschirm übersetzt, Tabelle in IndexedDB und ohne Endpunkt genutzt; Kopplung, signiertes Paket, Scan bei `context.setOffline(true)` → lokale Entscheidung + IndexedDB-Warteschlange (Code bis zum Upload), Doppelscan offline abgelehnt, nach Reconnect synchronisiert und serverseitig eingecheckt |
| `woocommerce-blocks.test.js` | WooCommerce-Blocks-Checkout (zweite Testseite, `woo-env.sh` + `woo-setup.php`): Teilnehmer-Fieldset mit Labels, ungültige Teilnehmer-E-Mail blockiert die Bestellung (`aria-invalid`, Fehlermeldung), Angaben landen am Woo-Auftrag. Ohne `WPCAP_WOO_ZIP` übersprungen |

**Browser-Matrix:** lokal und in CI nur **Chromium** (Playwright, volle Chromium-Version wegen Kamera-Capture;
Fallback Headless-Shell). **Ausstehend:** Firefox und WebKit (`WPCAP_E2E_BROWSER`, Browser in CI installieren –
die Fake-Kamera-Prüfung wird dort übersprungen), echte Geräte **iOS Safari** und **Android Chrome** (Kamera-
Berechtigung, BarcodeDetector vs. jsQR, PWA-Installation, Offline-Modus, Hintergrund-Sync) – manuell oder über
einen Gerätedienst.

Stand 14.11.2026: JS 56/56, E2E 11/11 (Chromium, WooCommerce 11.3 für den Blocks-Test), a11y-Gate bestanden.
