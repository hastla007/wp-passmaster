# Release-Gates (verbindlich)

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

### WordPress.org-Pflichtangaben (A269, A270)

- **Kern frei, Premium im separaten Add-on** (Entscheidung 13.11.2026, [FREE-PREMIUM.md](FREE-PREMIUM.md)): Das Release des Kern-Plugins enthält keine Lizenzaktivierung, keine Rechteprüfung und keinen eigenen Updater; Updates kommen von WordPress.org. Premiumfunktionen werden als eigenes Plugin `pass-captain-pro` mit eigenem Release-Prozess, eigenem Shop und eigenem Updater veröffentlicht – nur das Add-on spricht mit WP License Captain. Vor jedem Release: `tests/license-guard.php` grün (CI), `docs/FREE-PREMIUM.md` vollständig (`tests/consistency.php`), `readme.txt` ohne Aussage, die eine Lizenz oder das Add-on voraussetzt.

- **External services**: `readme.txt` beschreibt im Abschnitt `== External services ==` jeden externen Dienst (Zweck, Zeitpunkt, gesendete Daten, Links zu Bedingungen und Datenschutz). `tests/consistency.php` schlägt fehl, wenn ein `https://`-Host aus einer ausgelieferten Datei in `src/` (`.distignore` beachtet, `tests/shipped-files.php`) dort nicht genannt ist – wer eine neue Anbindung einbaut, ergänzt den Abschnitt (reine Doku-Links/Beispiele stehen in `$local_hosts`). Nicht ausgelieferter Code wird auch nicht beworben: Die Fiskal-Adapter unter `src/Fiscalization/` sind bis zum Merge des Fiskalisierungskerns (PR #14) per `.distignore` aus dem ZIP genommen (A666), und `readme.txt` nennt fiskaltrust/efsta genau dann, wenn sie ausgeliefert werden; `tests/wp-matrix/zip-install.sh` prüft, dass sie nicht im ZIP liegen. Mit PR #14 die Regel in `.distignore` entfernen und den Eintrag unter External services wiederherstellen (siehe Git-Historie von `readme.txt`).
- **Minifizierte Bibliotheken**: Neben jeder `.min.js` liegt der lesbare Quelltext derselben Version und eine Quellangabe als `.txt` (Markdown wird über `.distignore` ausgefiltert). Für tweetnacl: `assets/vendor/tweetnacl/nacl-fast.js` und `SOURCE.txt` (Version, URL, Lizenz, SHA-256 beider Dateien – von `tests/consistency.php` geprüft); `tests/wp-matrix/zip-install.sh` prüft, dass beide im ZIP liegen.

Fehlt das Protokoll oder ist ein Punkt offen, bricht der Release-Workflow mit einer Meldung ab, welche Angabe fehlt; CI auf `main` bleibt davon unberührt. Frühere Releases (bis 1.4.0) wurden vor Einführung dieses Gates veröffentlicht.
