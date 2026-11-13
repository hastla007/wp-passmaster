# Release-Gates (verbindlich)

Ein Release (`v<Version>`) wird vom Workflow `.github/workflows/release.yml` nur veröffentlicht, wenn **alle**
folgenden Gates erfüllt sind. Die automatischen Gates erzwingt GitHub Actions, das manuelle Gate erzwingt
`build/release-acceptance.sh` (Abnahmeprotokoll muss vollständig im Release-Commit liegen).

| Gate | Wie geprüft | Erzwungen durch |
|---|---|---|
| Syntax, Composer, JS, Konsistenz von Version/readme/CHANGELOG/Doku-Links | `ci.yml` Job `php` (PHP 8.2 und 8.4) | CI grün auf `main` |
| Integrationstests (Bestellungen, Zahlungen, Erstattungen, Check-in, Datenschutz, Module) | `ci.yml` Job `integration` | CI grün |
| Nebenläufigkeit/Last (Smoke) | `ci.yml` Job `integration`, `tests/load/run.php --profile=ci` | CI grün |
| PWA installierbar, Offline-Start | `ci.yml` Job `pwa` | CI grün |
| Reproduzierbares ZIP mit Prüfsumme | `ci.yml` Job `package`, `tests/reproducible-build.sh` | CI grün |
| Nur getestete Commits von `main` | `build/release-guard.sh` | Release-Workflow |
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
