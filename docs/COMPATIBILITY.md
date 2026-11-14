# Kompatibilität und Release-Artefakt

## Unterstützte Plattformen

| Komponente | Freigegeben | In CI geprüft | Hinweis |
|---|---|---|---|
| PHP | 8.2, 8.3, 8.4 | 8.2, 8.3, 8.4 (Syntax, Integration, Last-Smoke) | Minimum 8.2 (`Requires PHP`, `composer.json`) |
| WordPress | 6.4 bis 7.1 | 6.4 (Minimum) und aktuelle Version (ZIP-Installationstest) | `Requires at least: 6.4`, `Tested up to: 7.1` |
| WooCommerce (optional) | 8.2 bis 11.3 | 8.2 (Minimum) und aktuelle Version, jeweils HPOS an und aus | `WC requires at least` / `WC tested up to` im Plugin-Header |
| MariaDB | 10.6, 11.4 | 10.6 und 11.4 × PHP 8.2/8.3/8.4 | Minimum 10.6 (älteste LTS mit Support) |
| MySQL | – (noch nicht freigegeben) | 8.0 und 8.4 × PHP 8.2/8.3/8.4, *experimental* | siehe unten |

**Freigegeben** heißt: Die Kombination läuft in jeder CI-Ausführung blockierend (Integrationssuite, Last-Smoke-
Test `--profile=ci`, bei WordPress/WooCommerce zusätzlich der Installationstest des Release-ZIPs). Andere
Versionen dazwischen werden erwartet zu funktionieren, sind aber nicht einzeln getestet.

### MySQL: getestet, nicht freigegeben

MySQL 8.0 und 8.4 laufen in der Matrix mit `continue-on-error` (Ergebnis sichtbar, blockiert nicht). Stand
14.11.2026 schlagen dort 2 von 1670 Integrationstests fehl: Nach einem serverseitigen Rollback innerhalb der
Bestelltransaktion (Deadlock-Opfer, simuliert in `tests/integration/modules/zz_integration.php`) erkennt
`Orders::in_transaction()` den Verlust nur unter MariaDB (`@@in_transaction`); unter MySQL laufen die
restlichen Statements im Autocommit weiter. Last-Smoke-Test und alle übrigen Tests sind grün. MySQL wird
freigegeben (Matrixeintrag `experimental: false`), sobald dieser Pfad auch unter MySQL erkannt wird.

### Matrix ändern

- PHP/DB: `.github/workflows/ci.yml`, Job `integration` (`matrix.php`, `matrix.db`).
- WordPress/WooCommerce: Job `install-test` (`matrix.include`, Variablen `WPCAP_IT_WP_VERSION`,
  `WPCAP_IT_WC_VERSION` von `build/ci-install-test.sh`).
- Bei einer neuen Mindestversion auch Plugin-Header, `readme.txt`, `composer.json` (`require.php`,
  `config.platform.php`) und `phpcs.xml.dist` (`testVersion`, `minimum_wp_version`) anpassen –
  `build/check-versions.sh` prüft die Übereinstimmung von Header, Readme und Composer.

## Release-Artefakt prüfen

Der ZIP-Build ist reproduzierbar: derselbe Commit mit demselben `composer.lock` und derselben Composer-
Version (CI und Release-Workflow: 2.8.12) ergibt byte-identische ZIPs. `build/release.sh` setzt dafür alle
Zeitstempel auf die Commit-Zeit (`SOURCE_DATE_EPOCH`, Standard: Committer-Datum des Refs), normalisiert
Rechte (0644/0755), sortiert die Einträge, rechnet in UTC und lässt Zusatzattribute weg (`zip -X`).

- CI (`package`) baut zweimal (der zweite Lauf mit geleertem Composer-Cache) und bricht ab, wenn die
  SHA-256-Summen abweichen.
- Der Release-Workflow baut erneut und veröffentlicht nur, wenn die Summe exakt der des ZIPs entspricht,
  das die CI für denselben Commit gebaut und getestet hat. Mit dem Release werden `pass-captain.zip` und
  `pass-captain.zip.sha256` hochgeladen.

Download prüfen:

```bash
sha256sum -c pass-captain.zip.sha256          # im Verzeichnis mit beiden Dateien
```

Unabhängig nachbauen (gleiche Composer-Version, z. B. `composer self-update 2.8.12`):

```bash
git clone https://github.com/hastla007/wp-passmaster && cd wp-passmaster
WPCAP_BUILD_REF=v1.4.0 bash build/release.sh  # SOURCE_DATE_EPOCH = Commit-Zeit des Tags
cat build/dist/pass-captain.zip.sha256        # muss der veröffentlichten Summe entsprechen
```

Weichen die Summen ab, mit `unzip -Zv` beider Dateien vergleichen (typisch: andere Composer-Version →
andere `vendor/composer/*`-Dateien, oder ein anderer `composer.lock`).
