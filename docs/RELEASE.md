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

Fehlt das Protokoll oder ist ein Punkt offen, bricht der Release-Workflow mit einer Meldung ab, welche Angabe fehlt; CI auf `main` bleibt davon unberührt. Frühere Releases (bis 1.4.0) wurden vor Einführung dieses Gates veröffentlicht.
