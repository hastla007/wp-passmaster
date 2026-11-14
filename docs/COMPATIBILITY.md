# Kompatibilität und Release-Artefakt

## Unterstützte Plattformen

| Komponente | Freigegeben | In CI geprüft | Hinweis |
|---|---|---|---|
| PHP | 8.2, 8.3, 8.4 | 8.2, 8.3, 8.4 (Syntax, Integration, Last-Smoke) | Minimum 8.2 (`Requires PHP`, `composer.json`) |
| WordPress | 6.4 bis 7.1 | 6.4 (Minimum) und aktuelle Version (ZIP-Installationstest) | `Requires at least: 6.4`, `Tested up to: 7.1` |
| WooCommerce (optional) | 8.2 bis 11.3 | 8.2 (Minimum) und aktuelle Version, jeweils HPOS an und aus | `WC requires at least` / `WC tested up to` im Plugin-Header |
| MariaDB | 10.6, 11.4 | 10.6 und 11.4 × PHP 8.2/8.3/8.4 | Minimum 10.6 (älteste LTS mit Support) |
| MySQL | 8.0, 8.4 | 8.0 und 8.4 × PHP 8.2/8.3/8.4 | siehe unten |

**Freigegeben** heißt: Die Kombination läuft in jeder CI-Ausführung blockierend (Integrationssuite, Last-Smoke-
Test `--profile=ci`, bei WordPress/WooCommerce zusätzlich der Installationstest des Release-ZIPs). Andere
Versionen dazwischen werden erwartet zu funktionieren, sind aber nicht einzeln getestet.

### MySQL: Transaktionsstatus ohne `@@in_transaction`

MySQL kennt `@@in_transaction` nicht. Damit ein serverseitiger Rollback innerhalb der Bestelltransaktion
(Deadlock-Opfer) trotzdem erkannt wird, schreibt `Orders::begin()` unter MySQL eine Markierungszeile in eine
verbindungslokale temporäre InnoDB-Tabelle (`{prefix}wpcap_trx_marker`); ein Rollback entfernt sie, und
`Orders::in_transaction()` meldet die Transaktion als verloren. Ohne das Recht `CREATE TEMPORARY TABLES` fällt
das Plugin auf die übrigen geprüften Statements und den COMMIT zurück. Stand 14.11.2026 laufen MySQL 8.0 und
8.4 mit allen Integrationstests und dem Last-Smoke-Test grün und sind freigegeben.

### Matrix ändern

- PHP/DB: `.github/workflows/ci.yml`, Job `integration` (`matrix.php`, `matrix.db`).
- WordPress/WooCommerce: Job `install-test` (`matrix.include`, Variablen `WPCAP_IT_WP_VERSION`,
  `WPCAP_IT_WC_VERSION` von `build/ci-install-test.sh`).
- Bei einer neuen Mindestversion auch Plugin-Header, `readme.txt`, `composer.json` (`require.php`,
  `config.platform.php`) und `phpcs.xml.dist` (`testVersion`, `minimum_wp_version`) anpassen –
  `build/check-versions.sh` prüft die Übereinstimmung von Header, Readme und Composer.

## Test-Basislinie je Release

Für jedes Release wird hier festgehalten, gegen welche konkreten Versionen es getestet wurde. „aktuell“ in der
CI-Matrix ist eine bewegliche Angabe; diese Tabelle hält den Stand zum Release-Zeitpunkt fest. Die
Basislinie ist Teil des Freigabeprotokolls ([RELEASE.md](RELEASE.md)); Support-Zusagen: [SUPPORT.md](SUPPORT.md).

| Release | PHP (CI) | WordPress | WooCommerce | Datenbanken (CI) | Browser-Suiten (manuell) | Stand |
|---|---|---|---|---|---|---|
| 1.4.0 + unveröffentlichte Änderungen (`main`) | 8.2, 8.3, 8.4 | 6.4 und 7.1.4 | 8.2.0 und 11.3.0, HPOS an/aus | MariaDB 10.6, 11.4; MySQL 8.0, 8.4 | Chromium (a11y-Gate, E2E inkl. WooCommerce 11.3 Blocks) | 14.11.2026 |

**Je Release aktualisieren** (Teil von Schritt 1 in [RELEASE.md](RELEASE.md)):

1. Aktuelle WordPress- und WooCommerce-Versionen ermitteln; neue Hauptversionen zuerst lokal mit
   `build/ci-install-test.sh` (`WPCAP_IT_WP_VERSION`, `WPCAP_IT_WC_VERSION`) und der Integrationssuite prüfen.
2. Erst nach grünem Lauf `Tested up to` (`readme.txt`) und `WC tested up to` (Plugin-Header) anheben und die
   Tabelle „Unterstützte Plattformen“ oben anpassen.
3. Neue PHP- oder Datenbankversionen zuerst mit `experimental: true` in die Matrix des Jobs `integration`
   aufnehmen; nach mindestens einem grünen Release-Zyklus freigeben (`experimental: false`) und hier eintragen.
4. Konkrete Versionen aus dem CI-Lauf des Release-Commits (Job-Logs `install-test`, `integration`) und das
   Ergebnis der manuellen Browser-Suiten als neue Zeile in die Tabelle oben eintragen; ältere Zeilen bleiben
   als Historie stehen.

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
