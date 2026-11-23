# Release- und Supportmodell

Punkte, die der Betreiber/Maintainer noch bestätigen muss, sind mit **Entscheidung offen:** markiert.

## Unterstützte Plattformen

Maßgeblich ist die Matrix in [COMPATIBILITY.md](COMPATIBILITY.md) (Stand Version 1.4.0):

| Komponente | Unterstützt |
|---|---|
| PHP | 8.5 (8.6 experimentell getestet) |
| WordPress | 7.0 bis 7.1 |
| WooCommerce (optional) | 11.2.2 bis 11.3, HPOS und klassischer Bestellspeicher, Classic- und Blocks-Checkout |
| Datenbank | MariaDB 12.3+, MySQL 9.7+ (ältere Server: nur Warnung, nicht unterstützt) |
| Multisite | ja (Daten je Site getrennt, Netzwerkübersicht) |

„Unterstützt“ heißt: in jeder CI-Ausführung blockierend getestet. Versionen dazwischen sollten funktionieren,
Fehlerberichte dazu werden angenommen. Andere Datenbanken (z. B. SQLite-Drop-in, Percona-Varianten) und PHP
< 8.5 werden nicht unterstützt (das Plugin lädt darunter nicht).

**Anhebung von Mindestversionen** nur in einer Minor- oder Major-Version, angekündigt im CHANGELOG mindestens
eine Minor-Version vorher. Faustregel: eine PHP-Version wird nicht mehr unterstützt, wenn sie upstream keine
Sicherheitsupdates mehr bekommt; WordPress und WooCommerce folgen den eigenen Mindestanforderungen der jeweils
aktuellen WooCommerce-Version. Entscheidung offen: Mindest-WordPress-Regel (z. B. „aktuelle minus drei
Hauptversionen“).

## Versionierung

Pass Captain folgt [Semantic Versioning](https://semver.org/lang/de/) `MAJOR.MINOR.PATCH`:

- **PATCH** (1.4.1): Fehler- und Sicherheitskorrekturen, keine neuen Einstellungen, keine Schemaänderung
  außer zur Fehlerbehebung.
- **MINOR** (1.5.0): neue Funktionen, neue Hooks/Routen, additive Schemaänderungen (Tabellen, Spalten,
  Indizes; `dbDelta` löscht nichts), Anhebung von Mindestversionen mit Vorankündigung.
- **MAJOR** (2.0.0): Entfernen oder inkompatibles Ändern öffentlicher Schnittstellen (siehe unten).

Datenbank-Schemaversion (`Installer::DB_VERSION`) und Plugin-Version sind unabhängig; Upgrades laufen
automatisch und vorwärts ([OPERATIONS.md](OPERATIONS.md)). Ein Downgrade ist nicht vorgesehen – nur über
Restore der Sicherung vor dem Upgrade.

## Rückwärtskompatibilität

Als **öffentliche Schnittstelle** gelten:

- versionierte Hooks und Verträge mit Suffix `_v1` (z. B. `wp_pass_captain_wallet_provider_v1`,
  `wp_pass_captain_ticket_service_v1`, `wp_pass_captain_admin_menu_v1`; Liste in [EXTENSIONS.md](EXTENSIONS.md)
  und [HOOKS.md](HOOKS.md)) samt der dort genannten Interfaces;
- der REST-Namespace `pass-captain/v1` (der alte Namensraum `wp-pass-captain/v1` wird dorthin weitergeleitet, [RENAME.md](RENAME.md); öffentliche Routen, Mobile-API, Partner-API; [MOBILE-API.md](MOBILE-API.md),
  [PARTNER-API.md](PARTNER-API.md)) und die Webhook-Nutzlasten ([WEBHOOKS.md](WEBHOOKS.md));
- Shortcodes `[wpcap_events]`, `[wpcap_calendar]`, `[wpcap_scanner]` (frühere `[wp_pass_captain_*]` als veraltete Aliase), Blöcke `pass-captain/*` (frühere `wp-pass-captain/*` als Aliase),
  überschreibbare Templates in `templates/`;
- dokumentierte Filter/Actions mit Präfix `wpcap_` in [HOOKS.md](HOOKS.md).

Zusagen:

- `_v1`-Verträge und `pass-captain/v1` (samt der Weiterleitung von `wp-pass-captain/v1`) werden innerhalb einer Major-Version **nicht inkompatibel
  geändert**: Felder und Parameter kommen nur hinzu, Bedeutung und Typen bleiben. Inkompatible Änderungen
  bekommen einen neuen Namen (`_v2`, `pass-captain/v2`); der alte bleibt mindestens bis zur nächsten
  Major-Version erhalten und wird vorher im CHANGELOG als veraltet markiert.
- `wpcap_`-Hooks sind stabil; Änderungen stehen im CHANGELOG, Entfernen nur mit Ankündigung eine
  Minor-Version vorher.
- **Nicht** öffentlich: Tabellen `*_wpcap_*` und ihre Spalten, interne Klassen/Methoden ohne Erwähnung in
  EXTENSIONS.md, Admin-HTML und CSS-Klassen, Optionsnamen. Add-ons schreiben nicht direkt in Plugin-Tabellen.

## Supportkanal

- **GitHub Issues** des Repositories für Fehler, Fragen und Funktionswünsche (mit Version, PHP/WP/
  WooCommerce/DB-Version und Auszug aus *Pass Captain → Systemstatus* bzw. `wp pass-captain status
  --format=json`; keine Kundendaten oder Schlüssel posten).
- Sicherheitslücken nur privat über GitHub Security Advisories ([SECURITY.md](SECURITY.md#sicherheitsprozess)).
- Entscheidung offen: Support-Forum auf WordPress.org nach Veröffentlichung dort; kommerzieller Support/
  Antwortzeiten (gehört gegebenenfalls zum Premium-Add-on, nicht zum Kern).

## Patch- und Supportzeiträume

| Version | Fehlerkorrekturen | Sicherheitskorrekturen |
|---|---|---|
| aktuelle Minor (z. B. 1.4.x) | ja | ja |
| vorherige Minor (z. B. 1.3.x) | nur kritische Fehler (Datenverlust, Zahlungen, Einlass) | ja |
| ältere | nein | nein – Upgrade erforderlich |

- Sicherheitsfixes kritisch/hoch: Patch-Release in der Regel innerhalb von 14 Tagen nach Bestätigung
  (Zielwerte in [SECURITY.md](SECURITY.md#reaktionszeiten-zielwerte)).
- Bei einer neuen Major-Version erhält die letzte Minor der alten Major-Version Sicherheitsfixes für
  Entscheidung offen: 6 oder 12 Monate.
- Entscheidung offen: Release-Kadenz für Minor-Versionen (z. B. quartalsweise).

## Ende des Supports

- Das Supportende einer Minor-Version beginnt mit dem Erscheinen der übernächsten Minor-Version (mit 1.6.0
  endet der Support für 1.4.x).
- Das Ende der Unterstützung einer PHP-, WordPress-, WooCommerce- oder Datenbankversion wird im CHANGELOG
  und in der `readme.txt` (Upgrade Notice) mindestens eine Minor-Version vorher angekündigt; ab dann wird
  sie aus der CI-Matrix entfernt.
- Nach Supportende bleiben Releases auf GitHub verfügbar; es gibt keine Backports.
