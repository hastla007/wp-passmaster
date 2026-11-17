# Release-Prozess und Freigabe

Verbindliche Checkliste für jede Version (auch Patch-Releases). Ein Release wird erst veröffentlicht, wenn
**alle** automatischen Gates grün sind und die manuelle Abnahme unterschrieben ist. Testdetails:
[TESTING.md](TESTING.md); Plattformmatrix: [COMPATIBILITY.md](COMPATIBILITY.md); Support- und
Versionsregeln: [SUPPORT.md](SUPPORT.md).

## Ablauf

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

- Syntax PHP/JS (PHP 8.5, 8.6 experimentell), Composer-Metadaten;
- statische Analyse ohne neue Befunde (phpcs, PHPStan), `composer audit`;
- Lizenz-Wächter (Quellbaum und ZIP), Secret-Scan (Historie und ZIP), Dependency Review bei PRs;
- Integrationssuite und **Last-Smoke** (`--profile=ci`) auf PHP 8.5 × MariaDB 12.3 und MySQL 9.7
  (PHP 8.6 experimentell);
- Paketinhalt und **Installationstest** des ZIPs (WordPress aktuell und 7.0, WooCommerce aktuell und 11.2,
  HPOS an/aus);
- **Update von der Vorgängerversion** (derzeit 1.4.0, aus ihrem Release-Commit gebaut) mit Daten: Übernahme der
  Aktivierung, Schema-Upgrade auf die aktuelle `DB_VERSION`, Daten unverändert und konsistent, keine PHP-Fehler,
  Löschen der alten Kopie entfernt keine Daten (`install-test`, Bein „Update from 1.4.0“);
- **Deinstallation**: Daten bleiben mit Standardeinstellungen erhalten und werden nach Neuinstallation
  wiedergefunden; mit „Daten beim Löschen entfernen“ bleibt nichts übrig (alle `install-test`-Beine);
- **JS-Unit-Tests** (`php`, PHP 8.5), **a11y-Gate** (axe über alle Admin-Screens + Tastatur, Chromium) und
  **Browser-E2E** in Chromium, Firefox und WebKit (Checkout, Portal, Web-Scanner inkl. Fake-Kamera,
  Offline-PWA; WooCommerce Blocks in Chromium) sowie Mobil-Emulation Pixel 7 und iPhone 14 für Checkout,
  Portal und Offline-Kopplung (`browser`);
- **Plugin Check** (WordPress.org) gegen die ZIP-Dateien.

## Geplante und manuelle Gates (nicht im CI-Lauf des Commits, Pflicht vor jedem Release)

Ergebnis mit Commit-SHA bzw. Lauf-URL im Protokoll festhalten:

- [ ] **Lasttest** `default` (bei Änderungen an Checkout, Einlass, Webhooks oder Schema zusätzlich `heavy`):
      letzter Lauf von `load.yml` nicht älter als eine Woche und grün, oder manuell auslösen; Latenzen mit den
      Referenzwerten in [TESTING.md](TESTING.md) vergleichen.
- [ ] **Restore-Probe**: letzter Lauf von `drills.yml` (MariaDB 12.3, MySQL 9.7) nicht älter als eine Woche
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
