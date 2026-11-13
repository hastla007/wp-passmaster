# Tests: Integration, Last/Nebenläufigkeit, Barrierefreiheit

## Integrationstests

```bash
php tests/integration/run.php        # braucht eine Wegwerf-MariaDB/MySQL (WPCAP_TEST_DB_HOST/_USER/_PASS/_NAME)
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
- **Check-in (behoben):** In der Messung vom 11.11.2026 liefen gleichzeitige Erst-Einlässe *verschiedener*
  Tickets in einen InnoDB-Gap-Lock-Deadlock auf `wpcap_admission_state`, und `Checkin::perform()` meldete trotz
  Rollback „eingelassen“. Behoben (Commit 53633f6): `Admission\Engine::state_for_update()` liest den Zustand
  ohne `FOR UPDATE` (die Ticketzeile ist bereits gesperrt), Datenbankfehler brechen den Einlass mit einem
  retrybaren Fehler ab. Seitdem in jedem `--profile=ci`-Lauf: 0 Deadlocks, je Ticket genau ein Einlass
  (13.11.2026: 3 von 3 Läufen, `admitted` 10 / `rejected_duplicate` 140 / `replayed` 10). Der CI-Schritt
  „Concurrency/load smoke test“ ist verpflichtend (kein `continue-on-error`).

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
  Wartelistenangebot und Annahmeseite, Scanner (angemeldet, auch mit Ergebnis), Admin: Events, Event-Detail,
  Bestellungen, Bestelldetail, Check-ins, Box Office, Einstellungen (nur `#wpbody-content`). Ausgabe nach
  Impact, JSON-Bericht in `tests/a11y/report/`. Seiten mit HTTP ≠ 200 gelten als Fehler (nicht geprüft).
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
Abfragezahl (3 und 12 Termine: je 8 Abfragen ohne Modulfilter) und REST-`/events` (4 Abfragen).
Der Test-`$wpdb` zählt dafür `num_queries` und ruft wie WordPress den Filter `query` auf.

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
  erneutes Upgrade, Restore einer alten Sicherung (siehe OPERATIONS.md).

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

Noch offen: PHPStan mit WordPress-Stubs (in dieser Umgebung nicht installierbar, weil Composer keine GitHub-Downloads erhält; sollte in CI mit Baseline nachgezogen werden).

## JavaScript: Lint und Logiktests (A174)

```bash
npm ci
npm run lint:js   # ESLint (empfohlene Regeln) über alle eigenen Skripte in assets/, src/blocks/ und tests/
npm run test:js   # node:test – Logik der Offline-App (Code-Normalisierung, Paketsignatur/Format/Gerät, Offline-Einlassentscheidung)
```

Die Offline-App wird dazu in einer Node-Sandbox mit minimalen Browser-Attrappen geladen (`tests/js/offline-scanner.test.js`) und über `window.wpcapOffline` geprüft: manipulierte Pakete, fremde Geräte, unbekannte/abgelaufene Schlüssel, Doppel-Einlass, Mehrfacheinlass, Bereichsbeschränkung, Einlass-ab-Zeit und abgelaufene Pakete. Beides läuft im CI-Job `php`. Globale Daten aus `wp_localize_script()` sind in `eslint.config.mjs` als `readonly` deklariert – neue lokalisierte Objekte dort eintragen.
