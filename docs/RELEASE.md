# Release-Prozess und Freigabe

Verbindliche Checkliste für jede Version (auch Patch-Releases). Ein Release wird erst veröffentlicht, wenn
**alle** automatischen Gates grün sind und die manuelle Abnahme unterschrieben ist. Testdetails:
[TESTING.md](TESTING.md); Plattformmatrix: [COMPATIBILITY.md](COMPATIBILITY.md); Support- und
Versionsregeln: [SUPPORT.md](SUPPORT.md).

## Release-Gates (verbindlich)

Ein Release (`v<Version>`) wird vom Workflow `.github/workflows/release.yml` nur veröffentlicht, wenn **alle**
folgenden Gates erfüllt sind. Die automatischen Gates erzwingt GitHub Actions, das manuelle Gate erzwingt
`build/release-acceptance.sh` (Abnahmeprotokoll muss vollständig im Release-Commit liegen).

| Gate | Wie geprüft | Erzwungen durch |
|---|---|---|
| Syntax, Composer, JS, Konsistenz von Version/readme/CHANGELOG/Doku-Links | `ci.yml` Job `php` (PHP 8.2 und 8.4) | CI grün auf `main` |
| Integrationstests (Bestellungen, Zahlungen, Erstattungen, Check-in, Datenschutz, Module) | `ci.yml` Job `integration` | CI grün |
| Nebenläufigkeit/Last (Smoke) | `ci.yml` Job `integration`, `tests/load/run.php --profile=ci` | CI grün |
| Barrierefreiheit: axe-core WCAG 2.2 A/AA ohne ernste/kritische Befunde, Tastatur-Durchlauf | `wp-matrix.yml` Job `accessibility` (`tests/a11y/run.js --fail-on=serious`, `keyboard.js`) | Release-Commit auf `main` muss grün sein |
| Manuelle Screenreader- und Zoom-Abnahme (NVDA/VoiceOver, 200 %/400 %) | Checkliste | Abnahmeprotokoll |
| PWA installierbar, Offline-Start | `ci.yml` Job `pwa` | CI grün |
| Reproduzierbares ZIP mit Prüfsumme | `ci.yml` Job `package`, `tests/reproducible-build.sh` | CI grün |
| Abhängigkeiten ohne bekannte Lücken (Composer, npm ≥ high), keine Secrets im Repository | `ci.yml` Job `php`: `composer audit`, `npm audit`, `tests/secret-scan.php`; Dependabot (`.github/dependabot.yml`) | CI grün |
| Paketprüfung: keine Secrets im ZIP (inkl. mitgeliefertem vendor/), keine Entwicklungsdateien, Installation in frischem WordPress | `ci.yml` Job `package` (`tests/secret-scan.php --zip=…`), `wp-matrix.yml` (`tests/wp-matrix/zip-install.sh`) | CI grün |
| Integrationssuite über PHP 8.2–8.5 und MySQL/MariaDB Minimum–aktuell | `ci.yml` Job `integration` (Matrix, siehe OPERATIONS.md „Datenbank- und PHP-Freigaben“) | CI grün |
| Kein Lizenz-/Updater-Code im Kern (kein `LicenseClient`, `wplc/v1`, `Update URI`, Update-Transient-/`plugins_api`-Filter, `allows_entitlement`) | `ci.yml` Job `php`: `tests/license-guard.php --self-test` (nur ausgelieferte Dateien, siehe TESTING.md) | CI grün |
| WordPress „Plugin Check“ (PCP 2.2.0, Kategorie `plugin_repo`) ohne neue Fehler gegenüber der Baseline | `wp-matrix.yml` Job `plugin-check`: gebautes ZIP in einem Wegwerf-WordPress, `wp plugin check pass-captain --format=json --categories=plugin_repo`, Abgleich mit `tests/plugin-check/compare.php` | CI grün |
| Nur getestete Commits von `main` | `build/release-guard.sh` | Release-Workflow |
| Übersetzungen vollständig (keine fehlenden/fuzzy Einträge) | `php tests/i18n-check.php` | Release-Workflow |
| Plugin-Version = Tag | Schritt „Check plugin version matches the tag“ | Release-Workflow |
| Last-/Regressionsgrenzen (default/heavy/big, REST, MariaDB + MySQL) | `load.yml` (wöchentlich) | im Abnahmeprotokoll verlinkt |
| Upgrade/Rollback/Restore mit echten Daten | `tests/restore/drill.sh` (siehe OPERATIONS.md) | Abnahmeprotokoll |
| Manuelle Abnahme Mobil/Desktop: Checkout, Zustellung, QR-Scan, Doppel-Scan, Erstattung, Support | Checkliste | Abnahmeprotokoll |
| Fachliche Freigabe: Rechtstexte, Steuern, Zahlarten, Fristen, Übersetzungen | Checkliste | Abnahmeprotokoll |

## Ablauf

1. Version anheben (Plugin-Header, `WPCAP_VERSION`, readme „Stable tag“, CHANGELOG-Abschnitt) – `tests/consistency.php` prüft das.
2. `docs/release-acceptance/TEMPLATE.md` nach `docs/release-acceptance/v<Version>.md` kopieren, alle Punkte prüfen, abhaken, Datum, Prüfer und Commit eintragen.
3. Protokoll mit dem Release-Commit auf `main` mergen. Nach grüner CI veröffentlicht der Workflow das Release mit ZIP, `.sha256` und `build-info.txt`.

1. **Vorbereiten** (Release-Branch oder PR auf `main`): Version in Plugin-Header (`Version`), `WPCAP_VERSION`,
   `readme.txt` (`Stable tag`, `Changelog`, `Upgrade Notice`) und neuester Abschnitt in `CHANGELOG.md`
   setzen; `Tested up to` / `WC tested up to` und die Basislinie in [COMPATIBILITY.md](COMPATIBILITY.md)
   aktualisieren. Lokal: `bash build/check-versions.sh vX.Y.Z`. Nach jeder Veröffentlichung im Bein
   „Update from …“ des Jobs `install-test` (`ci.yml`, `upgrade_from`) den Release-Commit der gerade
   veröffentlichten Version eintragen, damit das nächste Release den Update-Pfad von seiner Vorgängerversion prüft.
2. **Manuelle Gates** (unten) gegen genau den Commit ausführen, der veröffentlicht werden soll, und im
   Freigabeprotokoll festhalten.
3. **Merge auf `main`**. `ci.yml` läuft für den Merge-Commit.
4. **Veröffentlichen** über `.github/workflows/release.yml`: automatisch nach erfolgreicher CI für einen
   Push auf `main` (Tag `v<Version>` wird auf genau dem getesteten Commit angelegt, falls das Release noch
   nicht existiert), durch einen Tag-Push `vX.Y.Z` oder manuell (`workflow_dispatch`) von `main`.
5. **Nachbereiten:** Download mit `sha256sum -c pass-captain.zip.sha256` prüfen, WordPress.org-SVN
   aktualisieren (manueller Schritt, kein Workflow), Freigabeprotokoll ablegen.

## Automatische Gates

Der Release-Workflow bricht ab, wenn eines davon nicht erfüllt ist:

| Gate | Wo erzwungen |
|---|---|
| Commit liegt auf `main` (Vorfahre von `origin/main`) | `release.yml` → `build/verify-release.sh` |
| CI (`ci.yml`) für **genau diesen SHA** erfolgreich abgeschlossen, kein fehlgeschlagener Lauf; laufende CI wird abgewartet | `build/verify-release.sh` (Actions-API) |
| Tag = Header `Version` = `WPCAP_VERSION` = `Stable tag` (außer Pre-Release-Tags) | `build/verify-release.sh` |
| CHANGELOG, `composer.json`, `Requires at least`/`Requires PHP` konsistent | `build/check-versions.sh` (in `release.yml` und `ci.yml` `static`) |
| Reproduzierbares ZIP; SHA-256 identisch mit dem ZIP, das die CI für diesen SHA gebaut und getestet hat | `release.yml` + `ci.yml` `package` |
| Veröffentlichung von `pass-captain.zip` und `pass-captain.zip.sha256`; Tag zeigt beim Veröffentlichen noch auf den geprüften Commit | `release.yml` |

Über „CI grün für den SHA“ sind zusätzlich verbindlich (alle Jobs in `ci.yml` blockierend):

- Syntax PHP/JS (PHP 8.2/8.3/8.4), Composer-Metadaten;
- statische Analyse ohne neue Befunde (phpcs, PHPStan), `composer audit`;
- Lizenz-Wächter (Quellbaum und ZIP), Secret-Scan (Historie und ZIP), Dependency Review bei PRs;
- Integrationssuite und **Last-Smoke** (`--profile=ci`) auf PHP 8.2/8.3/8.4 × MariaDB 10.6/11.4 und
  MySQL 8.0/8.4;
- Paketinhalt und **Installationstest** des ZIPs (WordPress aktuell und 6.4, WooCommerce aktuell und 8.2,
  HPOS an/aus);
- **Update von der Vorgängerversion** (derzeit 1.4.0, aus ihrem Release-Commit gebaut) mit Daten: Übernahme der
  Aktivierung, Schema-Upgrade auf die aktuelle `DB_VERSION`, Daten unverändert und konsistent, keine PHP-Fehler,
  Löschen der alten Kopie entfernt keine Daten (`install-test`, Bein „Update from 1.4.0“);
- **Deinstallation**: Daten bleiben mit Standardeinstellungen erhalten und werden nach Neuinstallation
  wiedergefunden; mit „Daten beim Löschen entfernen“ bleibt nichts übrig (alle `install-test`-Beine);
- **JS-Unit-Tests** (`php`, PHP 8.4), **a11y-Gate** (axe über alle Admin-Screens + Tastatur, Chromium) und
  **Browser-E2E** in Chromium, Firefox und WebKit (Checkout, Portal, Web-Scanner inkl. Fake-Kamera,
  Offline-PWA; WooCommerce Blocks in Chromium) sowie Mobil-Emulation Pixel 7 und iPhone 14 für Checkout,
  Portal und Offline-Kopplung (`browser`);
- **Plugin Check** (WordPress.org) gegen die ZIP-Dateien.

## Geplante und manuelle Gates (nicht im CI-Lauf des Commits, Pflicht vor jedem Release)

Ergebnis mit Commit-SHA bzw. Lauf-URL im Protokoll festhalten:

- [ ] **Lasttest** `default` (bei Änderungen an Checkout, Einlass, Webhooks oder Schema zusätzlich `heavy`):
      letzter Lauf von `load.yml` nicht älter als eine Woche und grün, oder manuell auslösen; Latenzen mit den
      Referenzwerten in [TESTING.md](TESTING.md) vergleichen.
- [ ] **Restore-Probe**: letzter Lauf von `drills.yml` (MariaDB 11.4, MySQL 8.0/8.4) nicht älter als eine Woche
      und grün, bei Änderungen an Schema, Jobs, Ausstellung oder Diagnose für den Release-Commit manuell
      auslösen (`workflow_dispatch`); lokal `tests/drills/restore-drill.sh`.
- [ ] **Release-Gate-Test**: `bash tests/release/verify-release.test.sh` (nur bei Änderungen an
      `build/verify-release.sh` oder `release.yml` zwingend).

Seit 14.11.2026 automatisch in `ci.yml` und daher nicht mehr manuell: a11y-Gate, Browser-E2E (jetzt drei
Engines + Mobil-Emulation, inkl. WooCommerce Blocks), JS-Unit-Tests, Update von der Vorgängerversion,
Deinstallation.

## Manuelle Abnahme

Gegen ein Staging-System mit dem Release-ZIP (nicht dem Arbeitsverzeichnis):

**Browser und Geräte** (Engines Chromium/Firefox/WebKit und Mobil-Emulation laufen automatisch, siehe oben)

- [ ] Desktop-Stichprobe in echten Browsern, die CI nicht abdeckt: Safari (macOS) und Edge – Checkout,
      Ticketseite und Admin-Screens (Admin wird automatisch nur in Chromium geprüft).
- [ ] Mobil, echte Geräte: iOS Safari und Android Chrome – Checkout, Ticketseite/Wallet, Web-Scanner mit
      echter Kamera und echtem QR-Code (Kamera-Berechtigung, BarcodeDetector bzw. jsQR-Fallback),
      Offline-Scanner-PWA (Installation, Offline-Scan, Sync, Hintergrund).
- [ ] Scanner-Hardware: mindestens ein Handscanner (Tastatur-Emulation) und ein Gerät mit Kopplung über
      die Mobile-API; Offline-Konflikt mit zwei Geräten.

**Barrierefreiheit**

- [ ] Screenreader: NVDA + Firefox oder JAWS + Chrome (Windows) und VoiceOver (iOS/macOS) für Checkout,
      Fehlermeldungen, Scanner-Ergebnis; Zoom 200 %; siehe [ACCESSIBILITY.md](ACCESSIBILITY.md).

**Zahlungen** (Sandbox-Konten der Anbieter)

- [ ] Stripe, PayPal, Mollie: Zahlung, Abbruch, verspätete Zahlung nach Reservierungsablauf, Webhook-
      Zustellung (auch doppelt), Voll- und Teilerstattung, Erstattung nach Check-in gemäß Einstellung.
- [ ] Banküberweisung/manuelle Bestätigung, kostenlose Bestellung, Kasse.

**WooCommerce** (echter Shop mit typischem Theme und Zahlungs-Plugin)

- [ ] Classic-Checkout und Blocks-Checkout: Reservierung, Teilnehmerangaben, Ticketausgabe nach Zahlung,
      Storno, (Teil-)Erstattung; HPOS an und aus.
- [ ] Gemischter Warenkorb mehrerer Rechnungssteller (Richtlinie `reject` und `split`).

**Upgrade und Wiederherstellung** (Update von der Vorgängerversion, Deinstallation und Restore-Probe laufen
automatisch, siehe oben)

- [ ] Staging-Upgrade mit einer Kopie des Produktionsbestands (Datenvolumen, Servicebuchungen, Wartelisten,
      WooCommerce-Bestellungen, eigene Template-Überschreibungen): Schema-Upgrade ohne Fehler (Systemstatus →
      Datenbankschema), Queues laufen, Checkout und Check-in funktionieren.
- [ ] Multisite: Netzwerkaktivierung, Netzwerkübersicht, Upgrade aller Sites.
- [ ] Übersetzungen: `.mo`-Dateien aktuell, Stichprobe en_US.

## Freigabeprotokoll (Vorlage)

```text
Release:            vX.Y.Z
Commit (SHA):       ________________________________________
CI-Lauf (URL):      ________________________________________
ZIP sha256:         ________________________________________
Vorgängerversion für Upgrade-Test: vX.Y.Z

Automatische Gates (CI grün für SHA, inkl. a11y,
  E2E-Browsermatrix, Update, Deinstallation):     [ ] ja
Lasttest / Restore-Probe (Lauf-URL, Datum):      [ ] / [ ]
Browser Safari+Edge / echte Mobilgeräte / Scanner: [ ] / [ ] / [ ]
Screenreader:                                    [ ]
PSP-Sandbox Stripe / PayPal / Mollie:            [ ] / [ ] / [ ]
WooCommerce Classic / Blocks (echter Shop):      [ ] / [ ]
Staging-Upgrade mit Produktionskopie:            [ ]

Bekannte Einschränkungen / Abweichungen:
- ...

Freigegeben von (Name, Rolle, Datum):  ______________________
Zweite Person (Vier-Augen-Prinzip):    ______________________
```

Entscheidung offen: wer Releases freigeben darf (Rolle/Personen) und ob das Vier-Augen-Prinzip für
Patch-Releases gilt.

### WordPress.org-Pflichtangaben (A269, A270)

- **Kern frei, Premium im separaten Add-on** (Entscheidung 13.11.2026, [FREE-PREMIUM.md](FREE-PREMIUM.md)): Das Release des Kern-Plugins enthält keine Lizenzaktivierung, keine Rechteprüfung und keinen eigenen Updater; Updates kommen von WordPress.org. Premiumfunktionen werden als eigenes Plugin `pass-captain-pro` mit eigenem Release-Prozess, eigenem Shop und eigenem Updater veröffentlicht – nur das Add-on spricht mit WP License Captain. Vor jedem Release: `tests/license-guard.php` grün (CI), `docs/FREE-PREMIUM.md` vollständig (`tests/consistency.php`), `readme.txt` ohne Aussage, die eine Lizenz oder das Add-on voraussetzt.

- **External services**: `readme.txt` beschreibt im Abschnitt `== External services ==` jeden externen Dienst (Zweck, Zeitpunkt, gesendete Daten, Links zu Bedingungen und Datenschutz). `tests/consistency.php` schlägt fehl, wenn ein `https://`-Host aus einer ausgelieferten Datei in `src/` (`.distignore` beachtet, `tests/shipped-files.php`) dort nicht genannt ist – wer eine neue Anbindung einbaut, ergänzt den Abschnitt (reine Doku-Links/Beispiele stehen in `$local_hosts`). Nicht ausgelieferter Code wird auch nicht beworben: Die Fiskal-Adapter unter `src/Fiscalization/` sind bis zum Merge des Fiskalisierungskerns (PR #14) per `.distignore` aus dem ZIP genommen (A666), und `readme.txt` nennt fiskaltrust/efsta genau dann, wenn sie ausgeliefert werden; `tests/wp-matrix/zip-install.sh` prüft, dass sie nicht im ZIP liegen. Mit PR #14 die Regel in `.distignore` entfernen und den Eintrag unter External services wiederherstellen (siehe Git-Historie von `readme.txt`).
- **Block-Editor-Skripte (A454)**: `src/blocks/*/editor.asset.php` trägt als `version` den Inhalts-Hash von `editor.js` (erste 20 Hex-Stellen von MD5, LF-Zeilenenden) – WordPress hängt ihn als `?ver=` an, so laden Browser und CDN nach einem Update das geänderte Skript. Wer `editor.js` ändert, trägt den Hash neu ein; `tests/consistency.php` schlägt sonst fehl und nennt den erwarteten Wert. Die Prüfung verlangt außerdem, dass `dependencies` jedes verwendete `window.wp.*`-Paket nennt (`wp.serverSideRender` → `wp-server-side-render`).
- **Minifizierte Bibliotheken**: Neben jeder `.min.js` liegt der lesbare Quelltext derselben Version und eine Quellangabe als `.txt` (Markdown wird über `.distignore` ausgefiltert). Für tweetnacl: `assets/vendor/tweetnacl/nacl-fast.js` und `SOURCE.txt` (Version, URL, Lizenz, SHA-256 beider Dateien – von `tests/consistency.php` geprüft); `tests/wp-matrix/zip-install.sh` prüft, dass beide im ZIP liegen.

### Plugin Check (A305)

Der Job `plugin-check` (`wp-matrix.yml`) baut das ZIP mit `build/release.sh`, installiert es zusammen mit
Plugin Check (Version fest in `PCP_VERSION`) in einem Wegwerf-WordPress und führt die statischen Prüfungen der
Kategorie `plugin_repo` aus. Gezählt werden nur Befunde vom Typ ERROR, je Datei und Prüfcode. Die Baseline
`tests/plugin-check/baseline.json` hält den Altbestand der ersten Messung (Stand 16.11.2026: 886 Fehler, davon
650× `WordPress.DB.PreparedSQL.NotPrepared` und 211× `PluginCheck.Security.DirectDB.UnescapedDBParameter` für
Tabellennamen/zusammengesetzte SQL-Teile, außerdem Dateisystem-/cURL-Funktionen, `strip_tags`, ein
`array_is_list()`-Fehlalarm (PHP-8.1-Funktion) und `readme_description_non_official_language` (deutsche Readme,
Entscheidung A304)). Neue Fehler – neue Datei, neuer Code oder mehr vom selben – lassen den Job scheitern; sinkt
die Zahl, meldet der Job das und die Baseline wird mit
`php tests/plugin-check/compare.php <ergebnis> --write tests/plugin-check/baseline.json` gesenkt. Vor einer
Einreichung bei WordPress.org muss die Baseline leer sein (Fehler beheben oder begründet per `phpcs:ignore`
ausnehmen). Lokal: Plugin Check in einem Test-WordPress installieren, ZIP installieren, denselben Befehl ausführen.

### WordPress.org-SVN, Assets und Screenshots (Release-Entscheidung, offen)

Noch **nicht** umgesetzt und bewusst nicht automatisiert, weil es eine Release-Entscheidung ist: Veröffentlichung
im WordPress.org-SVN (`trunk/`, `tags/<Version>/`, `assets/`), etwa per `10up/action-wordpress-plugin-deploy` mit
den Secrets `SVN_USERNAME`/`SVN_PASSWORD`, erst nach Freigabe des Plugins durch das WordPress.org-Review-Team. Dafür
fehlen: Entscheidung zu Quellsprache/Readme (A304), ein Ordner `.wordpress-org/` mit Banner (`banner-772x250.png`,
`banner-1544x500.png`), Icon (`icon-128x128.png`, `icon-256x256.png` oder `icon.svg`) und Screenshots
(`screenshot-1.png` …, passend zu `== Screenshots ==` in `readme.txt`), sowie die Festlegung, ob GitHub-Releases
und SVN-Tags aus demselben ZIP entstehen (empfohlen: der Deploy-Job nutzt das ZIP von `build/release.sh`).

Fehlt das Protokoll oder ist ein Punkt offen, bricht der Release-Workflow mit einer Meldung ab, welche Angabe fehlt; CI auf `main` bleibt davon unberührt. Frühere Releases (bis 1.4.0) wurden vor Einführung dieses Gates veröffentlicht.
