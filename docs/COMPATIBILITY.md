# Kompatibilität und Release-Artefakt

## Unterstützte Plattformen

| Komponente | Freigegeben | In CI geprüft | Hinweis |
|---|---|---|---|
| PHP | 8.5 | 8.5 (Syntax, Integration, Last-Smoke); 8.6 experimentell (nicht blockierend) | Minimum 8.5 (`Requires PHP`, `composer.json`, eigene Ladeprüfung) – darunter lädt das Plugin nicht |
| WordPress | 7.0 bis 7.1 | 7.0 (Minimum) und aktuelle Version (ZIP-Installationstest) | `Requires at least: 7.0` (WordPress 6.8 wirft unter PHP 8.5 Core-Deprecations), `Tested up to: 7.1` |
| WooCommerce (optional) | 11.2.2 bis 11.3 | 11.2.2 (Minimum; 11.2.0/11.2.1 übernehmen Daten eines früheren Store-API-Checkouts im selben Prozess, woocommerce#70637) und aktuelle Version, jeweils HPOS an und aus | `WC requires at least` / `WC tested up to` im Plugin-Header |
| MariaDB | 12.3 | 12.3 × PHP 8.5 (8.6 experimentell) | Minimum 12.3; ältere Server: nur Warnung in „Website-Zustand“, keine Sperre |
| MySQL | 9.7 | 9.7 × PHP 8.5 (8.6 experimentell) | Minimum 9.7; ältere Server: nur Warnung in „Website-Zustand“, keine Sperre; siehe unten |

**Freigegeben** heißt: Die Kombination läuft in jeder CI-Ausführung blockierend (Integrationssuite, Last-Smoke-
Test `--profile=ci`, bei WordPress/WooCommerce zusätzlich der Installationstest des Release-ZIPs). Andere
Versionen dazwischen werden erwartet zu funktionieren, sind aber nicht einzeln getestet.

### MySQL: Transaktionsstatus ohne `@@in_transaction`

MySQL kennt `@@in_transaction` nicht. Damit ein serverseitiger Rollback innerhalb der Bestelltransaktion
(Deadlock-Opfer) trotzdem erkannt wird, schreibt `Orders::begin()` unter MySQL eine Markierungszeile in eine
verbindungslokale temporäre InnoDB-Tabelle (`{prefix}wpcap_trx_marker`); ein Rollback entfernt sie, und
`Orders::in_transaction()` meldet die Transaktion als verloren. Ohne das Recht `CREATE TEMPORARY TABLES` fällt
das Plugin auf die übrigen geprüften Statements und den COMMIT zurück. Seit 17.11.2026 (MINVER-1) ist MySQL 9.7
die freigegebene MySQL-Version.

MariaDB ab 11.6.2 (also auch 12.3) schaltet `innodb_snapshot_isolation` standardmäßig ein: Ein sperrender Lesezugriff
(`SELECT … FOR UPDATE`) auf eine Zeile, die eine andere Anfrage nach dem ersten Lesen der Transaktion geändert hat,
bricht dann mit Fehler 1020 ab und rollt die Transaktion zurück. Pass Captain schaltet die Einstellung deshalb für
seine Verbindung aus (`DbSession::classic_locking_reads()`: beim Start und vor jeder Bestandstransaktion; nur auf
MariaDB, fehlt die Variable, passiert nichts) – wie Voucher Captain. Test: `tests/integration/modules/zz_minver.php`.

### Matrix ändern

- PHP/DB: `.github/workflows/ci.yml`, Job `integration` (`matrix.php`, `matrix.db`).
- WordPress/WooCommerce: Job `install-test` (`matrix.include`, Variablen `WPCAP_IT_WP_VERSION`,
  `WPCAP_IT_WC_VERSION` von `build/ci-install-test.sh`).
- Bei einer neuen Mindestversion auch Plugin-Header, `readme.txt`, `composer.json` (`require.php`,
  `config.platform.php`), die eigene PHP-Prüfung `version_compare( PHP_VERSION, '8.5', '<' )` am Anfang von `pass-captain.php` (die
  Composer-Plattformprüfung `vendor/composer/platform_check.php` wird wegen `config.platform-check: false` nicht
  ausgeliefert) und `phpcs.xml.dist` (`testVersion`, `minimum_wp_version`) anpassen –
  `build/check-versions.sh` prüft die Übereinstimmung von Header, Readme und Composer.

### Fremd-Plugins und Multisite

- **QR-Codes:** Die SVG wird aus der Modulmatrix von `bacon/bacon-qr-code` (`Encoder::encode()`, gleich in Bacon 2.x
  und 3.x) selbst gezeichnet (`QrCode::svg()`); `endroid/qr-code` wird nicht mehr mitgeliefert. Ein anderes Plugin,
  das endroid 4.x/5.x früher lädt, kann den Ticket- und Kopplungs-QR-Code daher nicht mehr brechen. Die
  Vendor-Namespaces sind nicht präfixiert (kein Strauss/PHP-Scoper im Build): Lädt ein Fremd-Plugin eine
  inkompatible Bacon-Version (1.x) zuerst, meldet die Systemstatus-Seite das mit Pfad (Fehler, wenn kein QR-Code
  entsteht; Warnung, wenn die fremde Kopie funktioniert).
- **Altkopie „wp-pass-captain“:** Das Daten-Opt-in für die Deinstallation liegt seit DB 3.14.0 in der Option
  `wpcap_uninstall_policy`; der alte Schlüssel `wpcap_settings[delete_data_on_uninstall]`, den die `uninstall.php` der
  Altkopie auf allen Sites liest, steht immer auf 0. Die Altkopie lässt sich neben „Pass Captain“ nicht mehr
  aktivieren (kein „Aktivieren“-Link, Aktivierungsanfrage wird vor dem Laden abgelehnt; per WP-CLI greift nur die
  Übernahme beim nächsten Request). Im Request der Übernahme lädt WordPress die Altkopie noch einmal nach dieser
  Kopie (die Plugin-Liste wurde vorher gelesen): `PassCaptain\LegacyCopy` blendet genau die Warnungen „Constant
  WPCAP_… already defined“ während des Ladens der Altdatei aus, `Plugin::run()` und `TicketPage::register()` sind
  idempotent – Hooks und Cron-Callbacks werden nicht doppelt registriert.
- **Netzwerkaktivierung:** höchstens 25 Sites bzw. 15 Sekunden werden in der Aktivierungsanfrage installiert, die
  übrigen per Upgrade-Cron-Ereignis auf der jeweiligen Site (ihr Daten-Opt-in wird sofort migriert).
- **Audit auf fremden Sites:** Netzwerkweite Hooks (z. B. Rollenwechsel nach `switch_to_blog()`) schreiben kein
  Audit, wenn die Site keine Plugin-Tabellen hat.
- **WooCommerce:** HPOS und Checkout-Blöcke kompatibel; der neue Produkt-Block-Editor ist als inkompatibel
  deklariert (das Ticketkategorie-Feld ist ein klassisches Produktdatenfeld).

## Test-Basislinie je Release

Für jedes Release wird hier festgehalten, gegen welche konkreten Versionen es getestet wurde. „aktuell“ in der
CI-Matrix ist eine bewegliche Angabe; diese Tabelle hält den Stand zum Release-Zeitpunkt fest. Die
Basislinie ist Teil des Freigabeprotokolls ([RELEASE.md](RELEASE.md)); Support-Zusagen: [SUPPORT.md](SUPPORT.md).

| Release | PHP (CI) | WordPress | WooCommerce | Datenbanken (CI) | Browser-Suiten (manuell) | Stand |
|---|---|---|---|---|---|---|
| 1.5.0 | 8.2, 8.3, 8.4 | 6.4 und 7.1.4 | 8.2.0 und 11.3.0, HPOS an/aus | MariaDB 10.6, 11.4; MySQL 8.0, 8.4 | Chromium (a11y-Gate, E2E inkl. WooCommerce 11.3 Blocks) | 14.11.2026 |

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
