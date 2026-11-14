# Release-Prozess und Freigabe

Verbindliche Checkliste für jede Version (auch Patch-Releases). Ein Release wird erst veröffentlicht, wenn
**alle** automatischen Gates grün sind und die manuelle Abnahme unterschrieben ist. Testdetails:
[TESTING.md](TESTING.md); Plattformmatrix: [COMPATIBILITY.md](COMPATIBILITY.md); Support- und
Versionsregeln: [SUPPORT.md](SUPPORT.md).

## Ablauf

1. **Vorbereiten** (Release-Branch oder PR auf `main`): Version in Plugin-Header (`Version`), `WPCAP_VERSION`,
   `readme.txt` (`Stable tag`, `Changelog`, `Upgrade Notice`) und neuester Abschnitt in `CHANGELOG.md`
   setzen; `Tested up to` / `WC tested up to` und die Basislinie in [COMPATIBILITY.md](COMPATIBILITY.md)
   aktualisieren. Lokal: `bash build/check-versions.sh vX.Y.Z`.
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
- **Plugin Check** (WordPress.org) gegen die ZIP-Dateien.

## Manuelle Gates (nicht in CI, Pflicht vor jedem Release)

Diese Suiten existieren, laufen aber in keinem Workflow (Stand 14.11.2026). Ergebnis mit Commit-SHA im
Protokoll festhalten:

- [ ] **a11y-Gate**: `tests/a11y/gate.sh` – 0 Verstöße, alle Seiten HTTP 200, Tastaturprüfung bestanden.
- [ ] **Browser-E2E** (Chromium): `WPCAP_WOO_ZIP=… tests/e2e/run.sh` – alle Tests grün, inkl.
      WooCommerce-Blocks.
- [ ] **JS-Unit-Tests**: `cd tests/js && npm test`.
- [ ] **Release-Gate-Test**: `bash tests/release/verify-release.test.sh` (nur bei Änderungen an
      `build/verify-release.sh` oder `release.yml` zwingend).
- [ ] **Lasttest** `default` (bei Änderungen an Checkout, Einlass, Webhooks oder Schema zusätzlich `heavy`):
      letzter Lauf von `load.yml` nicht älter als eine Woche und grün, oder manuell auslösen; Latenzen mit den
      Referenzwerten in [TESTING.md](TESTING.md) vergleichen.

## Manuelle Abnahme

Gegen ein Staging-System mit dem Release-ZIP (nicht dem Arbeitsverzeichnis):

**Browser und Geräte**

- [ ] Desktop: aktuelles Chrome, Firefox, Safari, Edge – Checkout, Bestellportal, Ticketseite, Admin.
- [ ] Mobil: iOS Safari und Android Chrome – Checkout, Ticketseite/Wallet, Web-Scanner (Kamera-Berechtigung,
      BarcodeDetector bzw. jsQR-Fallback), Offline-Scanner-PWA (Installation, Offline-Scan, Sync).
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

**Upgrade und Wiederherstellung**

- [ ] Upgrade von der **vorherigen veröffentlichten Version** mit realistischem Datenbestand (Bestellungen,
      Tickets, Einlässe, Servicebuchungen, Wartelisten): Schema-Upgrade ohne Fehler (Systemstatus →
      Datenbankschema), Queues laufen, Checkout und Check-in funktionieren.
- [ ] **Backup/Restore-Probe**: Sicherung vor dem Upgrade zurückspielen (Dateien + Datenbank als ein Stand,
      [OPERATIONS.md](OPERATIONS.md#backuprestore-und-upgrade)), danach erneut upgraden;
      `wp pass-captain diagnose --strict` ohne Befund.
- [ ] Multisite: Netzwerkaktivierung, Netzwerkübersicht, Upgrade aller Sites.
- [ ] Übersetzungen: `.mo`-Dateien aktuell, Stichprobe en_US.

## Freigabeprotokoll (Vorlage)

```text
Release:            vX.Y.Z
Commit (SHA):       ________________________________________
CI-Lauf (URL):      ________________________________________
ZIP sha256:         ________________________________________
Vorgängerversion für Upgrade-Test: vX.Y.Z

Automatische Gates (CI grün für SHA):            [ ] ja
a11y-Gate / E2E / JS / Lasttest:                 [ ] / [ ] / [ ] / [ ]   (Datum, Ausführende)
Browser Desktop / Mobil / Scanner:               [ ] / [ ] / [ ]
Screenreader:                                    [ ]
PSP-Sandbox Stripe / PayPal / Mollie:            [ ] / [ ] / [ ]
WooCommerce Classic / Blocks (echter Shop):      [ ] / [ ]
Upgrade + Backup/Restore-Probe:                  [ ]

Bekannte Einschränkungen / Abweichungen:
- ...

Freigegeben von (Name, Rolle, Datum):  ______________________
Zweite Person (Vier-Augen-Prinzip):    ______________________
```

Entscheidung offen: wer Releases freigeben darf (Rolle/Personen) und ob das Vier-Augen-Prinzip für
Patch-Releases gilt.
