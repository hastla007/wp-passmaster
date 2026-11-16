# Pass Captain

Eigenständiges WordPress-Plugin für Event-, Termin-, Ticket- und Einlassverwaltung. WooCommerce und Voucher Captain sind optionale Integrationen und keine Voraussetzung für den Kern.

## Kern frei, Premium im Add-on

Pass Captain ist als Kern-Plugin vollständig kostenlos (GPL-2.0-or-later) und für WordPress.org vorgesehen: keine Lizenzaktivierung, kein Lizenzschlüssel, keine gesperrten Funktionen, Updates nur über WordPress.org. Bereits ausgelieferte Funktionen bleiben dauerhaft frei. Premiumfunktionen entstehen ausschließlich in einem getrennten Add-on-Plugin, das als einziges mit dem Lizenzserver spricht und über die dokumentierten Erweiterungspunkte andockt. Funktionsinventar und Regeln: [docs/FREE-PREMIUM.md](docs/FREE-PREMIUM.md); Andockpunkte für Add-ons: [docs/EXTENSIONS.md](docs/EXTENSIONS.md). Der Wächtertest `tests/guard/no-license-code.php` lässt die CI fehlschlagen, sobald Lizenz- oder Updatercode im Kern auftaucht.

## Funktionen

**Events, Tickets, Checkout**

- Events als Einzeltermin oder Serie (Serientermine mit eigenen Preisstufen), Ausgabe per Shortcode
  `[wp_pass_captain_events]` bzw. Block, Kalender per `[wp_pass_captain_calendar]`, Block und iCal-Feed.
- Bestellungen mit Reservierung (Standard 10 Minuten, einstellbar) und Idempotency-Key; kostenlose
  Bestellungen stellen Tickets direkt aus. Zahlung per Überweisung/manueller Bestätigung, Stripe, PayPal,
  Mollie oder über WooCommerce.
- Tickets mit zufälligem 256-Bit-Code (gespeichert wird nur der SHA-256-Hash), QR-Code, PDF, Apple/Google
  Wallet; Bestellportal für Käufer (Teilnehmerzuweisung, einmalige Übertragung, erneuter Versand, Rechnungsdaten,
  Widerruf von Einwilligungen).
- Saalpläne mit Platzwahl, Teilnehmerfelder (auch protokollierte Einwilligungen), Kasse vor Ort (keine
  Registrierkasse, siehe [docs/OPERATIONS.md](docs/OPERATIONS.md)), Druck & Listen.
- **Verkaufsregeln:** automatische Preisregeln (Frühbucher, Last-Minute, Nachfrage, Wochentag/Zeitfenster),
  Kampagnencodes mit Stapelregeln und Gesamtrabatt-Deckel, **Käuferlimits** je Person atomar über alle
  Bestellungen ([docs/SALES-MODELS.md](docs/SALES-MODELS.md#verkaufsregeln-im-checkout)).
- **Steuerprofile:** versionierte Länder-/Steuerklassen-Profile (Entwurf → aktiv → archiviert), Auswahl je
  Bestellposition nach Steuerland des Events, Snapshot in der Position für Rechnung und Erstattung
  ([docs/TAX-PROFILES.md](docs/TAX-PROFILES.md)). Steuersätze liefert das Plugin nicht mit; sie sind fachlich zu
  prüfen.
- Rechnungen und Gutschriften mit Nummernkreisen, Verkaufsmodelle Eigenhändler/Vermittler je Veranstalter mit
  Monatsabrechnung ([docs/SALES-MODELS.md](docs/SALES-MODELS.md)); Gutscheine von WP Voucher Captain im
  Checkout und als Ticketumtausch, Einzweck-/Mehrzweckgutscheine ([docs/VOUCHERS.md](docs/VOUCHERS.md)).
- Erstattungen (auch Teilerstattungen) mit optionaler Zweitfreigabe und 2FA, Regel für Erstattung nach Check-in,
  Terminabsage mit automatischer Erstattung.

**Wartelisten und Servicebuchungen**

- Event-Wartelisten mit **Automatik**: frei werdende Plätze werden der Reihe nach als zeitlich begrenzte
  Angebote vergeben; wer annimmt, erhält direkt eine Bestellung mit Reservierung. Eintragen verrät nicht, ob eine
  Adresse schon eingetragen ist.
- **Servicebuchungen** (Kurse, Verleih, Bootsschule): Ressourcen mit Zeitfenstern, Dauer/Puffer/Kollisionen,
  **Buchungsregeln** je Ressource, Umbuchung, Stornierung mit Fristen, No-Show, Bestätigung durch den Anbieter, Warteliste
  für ausgebuchte Zeitfenster und Service-Pässe ([docs/SERVICE-BOOKINGS.md](docs/SERVICE-BOOKINGS.md)).
- Mitgliedspässe mit Nutzungslimits.

**Einlass**

- Web-Scanner `[wp_pass_captain_scanner]`, Scanner-Apps über die Mobile-API mit Gerätekopplung, installierbarer
  Offline-Scanner mit signierten Paketen ([docs/MOBILE-API.md](docs/MOBILE-API.md),
  [docs/OFFLINE-SCANNER.md](docs/OFFLINE-SCANNER.md)).
- Einlassregeln je Tag/Bereich, Check-out und Wiedereinlass, Einlass-Konflikte; Einlasspersonal je Event oder
  nur für einen Termin.

**WooCommerce (optional)**

- Anbindung nur über CRUD-APIs (HPOS und klassischer Bestellspeicher), Classic- und Blocks-Checkout:
  Kontingent wird beim Woo-Checkout reserviert, nach Zahlung genau ein Ticket je Einheit, Widerruf bei Storno
  und (Teil-)Erstattung.
- **Mehrere Rechnungssteller** im Warenkorb: Richtlinie „ablehnen“ oder „aufteilen“ (eine Pass-Captain-Bestellung
  je Rechnungssteller, Erstattung je Rechnungssteller und Position) ([docs/WOOCOMMERCE.md](docs/WOOCOMMERCE.md)).

**Kommunikation und Integrationen**

- E-Mail-Vorlagen je Sprache, Erinnerungen per E-Mail und SMS (Twilio, generischer HTTP-Anbieter), signierte
  Webhooks, Partner-API für Veranstalter, eventgebundene Read-only-Entwickler-Schlüssel, Berichte und
  CSV-Exporte, WP Affiliate Captain.

**Sicherheit, Datenschutz, Betrieb**

- **Secrets-Vault:** Anbieter-Zugangsdaten verschlüsselt (libsodium) in der Datenbank, Schlüssel außerhalb
  der Datenbank (`wp-config.php` oder Umgebung), Schlüsselwechsel ohne Datenverlust; zentrale Redaktion von Logs und Fehlern; SSRF-Schutz für
  ausgehende Verbindungen ([docs/SECURITY.md](docs/SECURITY.md)).
- Rollen mit Eventscoping und Datenminimierung je Rolle, Step-up-2FA je Kategorie, zeitlich begrenzter
  Supportzugriff, **Audit-Log** mit Ansicht, Filtern und CSV-Export.
- **Systemstatus und Diagnose:** Cron-/Worker-Heartbeats, Warteschlangen, fehlgeschlagene Einträge mit
  „Erneut versuchen“/„Verwerfen“, hängende Erstattungen, Schemafehler mit Backoff; Abgleichsprüfungen mit sicheren
  Reparaturen; WP-CLI `wp pass-captain status|diagnose` ([docs/OPERATIONS.md](docs/OPERATIONS.md)).
- **Multisite:** Daten je Site getrennt, Netzwerkübersicht mit Kennzahlen und Zustand aller Sites.
- Datenschutzexport/-löschung über die WordPress-Werkzeuge, automatische Löschfristen, Vorschlag für die
  Datenschutzerklärung.
- Datenbanken: **MariaDB 10.6/11.4 und MySQL 8.0/8.4** ([docs/COMPATIBILITY.md](docs/COMPATIBILITY.md)).

Eventkarten lassen sich über `templates/event-card.php` im Theme überschreiben; Währungen, Zahlungs-, SMS-,
Wallet- und Gutscheinanbieter sind über dokumentierte Hooks erweiterbar ([docs/EXTENSIONS.md](docs/EXTENSIONS.md),
[docs/HOOKS.md](docs/HOOKS.md)).

## Entwicklung

PHP 8.2+, Composer und WordPress erforderlich. `composer install` lädt die QR-Code-Abhängigkeit. Das Plugin kann ohne generierten Composer-Autoloader gestartet werden; QR-Bilder erscheinen erst mit installierten Abhängigkeiten.

Das aktuelle Kern-Datenbankschema ist `3.9.0` (plus Modul-Schemata). Das Upgrade erfolgt über `dbDelta` im Admin-Bereich, per WP-Cron oder WP-CLI hinter einer Datenbanksperre, nicht bei jedem Frontend-Aufruf (Details, Backup/Restore und System-Cron-Empfehlung: [docs/OPERATIONS.md](docs/OPERATIONS.md)). WordPress-Schreibzugriffe über REST brauchen eine eingeloggte WP-Session mit REST-Nonce; Entwickler-Schlüssel sind nur für dokumentierte Read-only-Scopes vorgesehen.

```sh
composer install
bash build/release.sh
```

Integrationstests laufen gegen eine echte, wegwerfbare MySQL-/MariaDB-Datenbank (Zugang über `WPCAP_TEST_DB_HOST`, `WPCAP_TEST_DB_USER`, `WPCAP_TEST_DB_PASS`, `WPCAP_TEST_DB_NAME`; Standard `127.0.0.1`/`wp`/`wp`/`wp`). Die Tests legen die Tabellen mit dem Präfix `wptest_` neu an:

```sh
php tests/integration/run.php
```

Die CI (`.github/workflows/ci.yml`) prüft Syntax, Coding-Standard und PHPStan gegen Baselines, `composer audit`, Secrets, den Lizenz-Wächter, die Integrationssuite und einen Last-Smoke-Test auf PHP 8.2–8.4 × MariaDB/MySQL, baut ein reproduzierbares ZIP, installiert es in echtem WordPress (mit und ohne WooCommerce) und prüft es mit dem offiziellen [Plugin Check](https://github.com/WordPress/plugin-check-action). Alle Testebenen, auch Lasttests, Barrierefreiheit und Browser-E2E: [docs/TESTING.md](docs/TESTING.md). Release-Gates: [docs/RELEASE.md](docs/RELEASE.md); Support- und Versionsregeln: [docs/SUPPORT.md](docs/SUPPORT.md).

## Roadmaps und Entwurfsdokumente

- [Nächste 10 Schritte](ROADMAP.md)
- [Schritte 11–30](ROADMAP_11_30.md)
- [Schritte 31–50](ROADMAP_31_50.md)
- [Kern frei, Premium im Add-on](docs/FREE-PREMIUM.md)

## Module

Unter **Pass Captain** stehen zusätzlich: E-Mail-Vorlagen, Saalpläne, Serientermine, Einlassregeln, Einlass-Konflikte, Kasse, Druck & Listen, Berichte, Wallet, Webhooks, Offline-Scanner, Servicebuchungen, Steuerprofile, Veranstalter und Veranstalter-Abrechnungen, Systemstatus, Audit-Log, Supportzugriff sowie Einstellungen für Kalender, Erinnerungen, SMS und Mollie. Shortcodes: `[wp_pass_captain_events]`, `[wp_pass_captain_calendar]`, `[wp_pass_captain_scanner]`.

Dokumentation: [WALLET](docs/WALLET.md), [WEBHOOKS](docs/WEBHOOKS.md), [SUITE-EVENTS](docs/SUITE-EVENTS.md), [HOOKS](docs/HOOKS.md), [EXTENSIONS](docs/EXTENSIONS.md), [MOBILE-API](docs/MOBILE-API.md), [OFFLINE-SCANNER](docs/OFFLINE-SCANNER.md), [PARTNER-API](docs/PARTNER-API.md), [MAIL-TEMPLATES](docs/MAIL-TEMPLATES.md), [SERVICE-BOOKINGS](docs/SERVICE-BOOKINGS.md), [SALES-MODELS](docs/SALES-MODELS.md), [TAX-PROFILES](docs/TAX-PROFILES.md), [VOUCHERS](docs/VOUCHERS.md), [WOOCOMMERCE](docs/WOOCOMMERCE.md), [SECURITY](docs/SECURITY.md), [OPERATIONS](docs/OPERATIONS.md), [ACCESSIBILITY](docs/ACCESSIBILITY.md), [TESTING](docs/TESTING.md), [COMPATIBILITY](docs/COMPATIBILITY.md), [RELEASE](docs/RELEASE.md), [SUPPORT](docs/SUPPORT.md).

## Scanner und Mobile-App

- Web-Scanner per Shortcode `[wp_pass_captain_scanner]`. Die Kamera nutzt die native BarcodeDetector-API und fällt sonst (iOS/Safari, Firefox) auf das mitgelieferte jsQR (Apache-2.0, `assets/vendor/jsqr/`) zurück.
- Native Scanner-Apps koppeln sich per QR-Code unter **Pass Captain → Scanner-Geräte** und nutzen die REST-API unter `/wp-json/wp-pass-captain/v1/app/` mit ablaufenden, eventgebundenen und widerrufbaren Gerätetokens. Spezifikation: [docs/MOBILE-API.md](docs/MOBILE-API.md).

## Sprachen

Ausgangssprache ist Deutsch. Vollständige Übersetzungen liegen für Englisch (en_US), Französisch (fr_FR), Italienisch (it_IT), Spanisch (es_ES) und Niederländisch (nl_NL) in `languages/`. Weitere Sprachen entstehen aus der Vorlage `languages/pass-captain.pot`. Kompilieren ohne gettext-Tools:

```sh
# Vorlage neu erzeugen (WP-CLI)
wp i18n make-pot . languages/pass-captain.pot --slug=pass-captain --domain=pass-captain --exclude=vendor,tests,assets/vendor,node_modules,build,docs,languages --skip-block-json
# Vorlage in eine Sprache übernehmen (ersetzt msgmerge), übersetzen, kompilieren
php build/merge-po.php languages/pass-captain.pot languages/pass-captain-xx_XX.po
php build/compile-mo.php languages/pass-captain-xx_XX.po
# Vollständigkeit und Platzhalter prüfen (läuft auch in der CI)
php build/check-i18n.php
```

## Noch offen

- Abnahme gegen die echten Sandboxes von Stripe, PayPal und Mollie sowie in einem echten WooCommerce-Shop (Classic und Blocks); getestet ist gegen nachgebaute WordPress-, WooCommerce- und Anbieter-APIs, die Browser-Tests laufen gegen eine Wegwerf-WordPress-Seite.
- Browser-Tests mit Firefox, WebKit und echten iOS-/Android-Geräten; Screenreader-Prüfung ([docs/TESTING.md](docs/TESTING.md#ausstehend)).
- Rechtliche und steuerliche Prüfung von Rechnungen, Gutschriften, Verbraucherinformationen, Steuerprofilen und Verkaufsmodellen je Land.
- Native Scanner-Apps selbst (die Server-API ist fertig, siehe [docs/MOBILE-API.md](docs/MOBILE-API.md)).
- Abnahme gegen echte Dienste: Apple/Google Wallet, Twilio, Fiskaldienste.
- Unabhängige Sicherheitsprüfung ([docs/SECURITY.md](docs/SECURITY.md)).
