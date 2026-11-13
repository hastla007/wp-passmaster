# WP Pass Captain

Eigenständiges WordPress-Plugin für Event-, Termin-, Ticket- und Einlassverwaltung. WooCommerce und Voucher Captain sind optionale Integrationen und keine Voraussetzung für den Kern.

## Funktionen

- Events als Einzeltermin oder wöchentliche Serie mit bis zu 12 getrennten Terminen im Admin anlegen.
- Events als Shortcode `[wp_pass_captain_events]` (optional `event="ID"`, `occurrence="ID"`, `limit="50"`) oder Gutenberg-Block (mit Event-/Terminauswahl) ausgeben.
- Bestellungen mit zehnminütiger Reservierung anlegen; kostenlose Bestellungen stellen Tickets direkt aus.
- Bezahlte Bestellungen können manuell bestätigt oder über den optionalen WooCommerce-Adapter synchronisiert werden.
- Ticketlink enthält einen zufälligen 256-Bit-Code; nur dessen SHA-256-Hash wird für die Prüfung gespeichert.
- Einlasspersonal eventbezogen zuweisen und den Scanner über `[wp_pass_captain_scanner]` bereitstellen.
- WordPress-Datenschutzexport/-löschung und ein CI-/ZIP-Build sind vorbereitet.
- Käuferlinks verfallen nach 30 Tagen; ein gültiges Ticket kann vor Veranstaltungsbeginn einmalig übertragen werden.
- Ein versionierter Voucher-Captain-Provider-Vertrag ist vorhanden; Voucher-Reservierung und Einlösung sind noch nicht im Checkout orchestriert.
- WooCommerce bleibt optional und wird nur über CRUD-APIs angebunden (HPOS und klassischer Bestellspeicher). Ticketprodukte reservieren Kontingent bereits beim Woo-Checkout (Classic und Blocks), stellen nach Zahlung genau ein Ticket je Einheit aus und widerrufen Tickets bei Storno und (Teil-)Erstattung. Details: [docs/WOOCOMMERCE.md](docs/WOOCOMMERCE.md).
- Veröffentliche Events können abgesagt werden; Käufer werden benachrichtigt und unbezahlte Reservierungen aufgehoben.
- REST-Grundlagen für Sitzpläne, Service-Slots, POS-Verkäufe, Offline-Scan-Synchronisierung, Einlassregeln pro Tag und Teilnehmerzuweisung sind enthalten.
- Transaktionale Rechnungsnummern, geplanter Mailversand und Mitgliedspässe mit Nutzungslimits sind als Basismodule verfügbar.
- Eventkarten können über `templates/event-card.php` im Theme überschrieben werden. Währungen und Wallet-Provider sind erweiterbar.
- Kampagnencodes können Prozent- oder Festbetragsrabatte mit Event-/Ticketbereich und atomarem globalem Nutzungsmaximum anwenden; angewandte Preiswerte werden im Auftrag gespeichert.
- Wartelisten nehmen Einträge auf (ohne zu verraten, ob eine Adresse schon eingetragen ist) und senden zeitlich begrenzte Angebote. Wer ein Angebot annimmt, bekommt direkt eine Bestellung mit Reservierung, sofern das Ticket noch verfügbar ist.
- Rollenbeschränkte Veranstaltungsberichte zeigen bezahlte Bestellungen, verkaufte Tickets, Bruttosummen pro Währung und Einlasszahlen.
- Eventgebundene Read-only-API-Schlüssel, übersetzte Eventkarten, site-getrennte Multisite-Migrationen und ein generischer Job-Queue-Grundbaustein sind verfügbar.
- Länder-/Steuerprofile werden ausschließlich als Entwürfe gespeichert. Eine geprüfte Steuerberechnung ist nicht enthalten.

## Entwicklung

PHP 8.2+, Composer und WordPress erforderlich. `composer install` lädt die QR-Code-Abhängigkeit. Das Plugin kann ohne generierten Composer-Autoloader gestartet werden; QR-Bilder erscheinen erst mit installierten Abhängigkeiten.

Das aktuelle Datenbankschema ist `3.5.0` (plus Modul-Schemata; Plugin-Version `1.4.0` nutzt `3.5.0`). Das Upgrade erfolgt über `dbDelta` im Admin-Bereich, per WP-Cron oder WP-CLI hinter einer Datenbanksperre, nicht bei jedem Frontend-Aufruf (Details und System-Cron-Empfehlung: [docs/OPERATIONS.md](docs/OPERATIONS.md)). Neue REST-Routen decken Kampagnen, Wartelisten, Eventberichte, Übersetzungen und eventgebundene Entwickler-Schlüssel ab. WordPress-Schreibzugriffe brauchen eine eingeloggte WP-Session mit REST-Nonce; Entwickler-Schlüssel sind nur für dokumentierte Read-only-Scopes vorgesehen.

```sh
composer install
bash build/release.sh
```

Integrationstests für Bestellungen, Reservierungen und den WooCommerce-Adapter laufen gegen eine echte, wegwerfbare MySQL-/MariaDB-Datenbank (Zugang über `WPCAP_TEST_DB_HOST`, `WPCAP_TEST_DB_USER`, `WPCAP_TEST_DB_PASS`, `WPCAP_TEST_DB_NAME`; Standard `127.0.0.1`/`wp`/`wp`/`wp`). Die Tests legen die Tabellen mit dem Präfix `wptest_` neu an:

```sh
php tests/integration/run.php
```

Der CI-Workflow führt Composer-Metadatenprüfung, PHP-Syntaxprüfung und JavaScript-Syntaxprüfung aus und erzeugt ein installierbares ZIP als Workflow-Artefakt.

## Roadmaps und Entwurfsdokumente

- [Nächste 10 Schritte](ROADMAP.md)
- [Schritte 11–30](ROADMAP_11_30.md)
- [Schritte 31–50](ROADMAP_31_50.md)

## Module (P2)

Unter **Pass Captain** stehen zusätzlich: E-Mail-Vorlagen, Saalpläne, Serientermine, Einlassregeln, Einlass-Konflikte, Kasse, Druck & Listen, Berichte, Wallet, Webhooks, Offline-Scanner, Veranstalter und Veranstalter-Abrechnungen sowie Einstellungen für Kalender, Erinnerungen, SMS und Mollie. Shortcodes: `[wp_pass_captain_events]`, `[wp_pass_captain_calendar]`, `[wp_pass_captain_scanner]`. Dokumentation: [docs/WALLET.md](docs/WALLET.md), [docs/WEBHOOKS.md](docs/WEBHOOKS.md), [docs/HOOKS.md](docs/HOOKS.md), [docs/MOBILE-API.md](docs/MOBILE-API.md), [docs/OFFLINE-SCANNER.md](docs/OFFLINE-SCANNER.md), [docs/PARTNER-API.md](docs/PARTNER-API.md), [docs/TESTING.md](docs/TESTING.md), [docs/MAIL-TEMPLATES.md](docs/MAIL-TEMPLATES.md).

## Scanner und Mobile-App

- Web-Scanner per Shortcode `[wp_pass_captain_scanner]`. Die Kamera nutzt die native BarcodeDetector-API und fällt sonst (iOS/Safari, Firefox) auf das mitgelieferte jsQR (Apache-2.0, `assets/vendor/jsqr/`) zurück.
- Native Scanner-Apps koppeln sich per QR-Code unter **Pass Captain → Scanner-Geräte** und nutzen die REST-API unter `/wp-json/wp-pass-captain/v1/app/` mit ablaufenden, eventgebundenen und widerrufbaren Gerätetokens. Spezifikation: [docs/MOBILE-API.md](docs/MOBILE-API.md).

## Sprachen

Ausgangssprache ist Deutsch. Vollständige Übersetzungen liegen für Englisch (en_US), Französisch (fr_FR), Italienisch (it_IT), Spanisch (es_ES) und Niederländisch (nl_NL) in `languages/`. Weitere Sprachen entstehen aus der Vorlage `languages/wp-pass-captain.pot`. Kompilieren ohne gettext-Tools:

```sh
php build/compile-mo.php languages/wp-pass-captain-xx_XX.po
```

## Noch offen

- Abnahme in einer echten WordPress-/WooCommerce-Installation sowie gegen die echten Stripe- und PayPal-Sandboxes (getestet ist gegen MariaDB mit nachgebauter WordPress-, WooCommerce- und Anbieter-API).
- Rechtliche und steuerliche Prüfung von Rechnungen, Gutschriften, Verbraucherinformationen und Steuersätzen je Land.
- Native Scanner-Apps selbst (die Server-API ist fertig, siehe docs/MOBILE-API.md, inkl. geplanter Erweiterungen).
- Abnahme der P2-Module gegen echte Dienste (Apple/Google Wallet, Twilio, Mollie) und im Browser (Saalplan-Editor, Platzwahl, Kasse).
- P3 laut Konzept: echtes Offline-Prüfpaket, externe Scanner-Apps, Veranstalter-Abrechnungen; Last-, Sicherheits- und Barrierefreiheitstests.
