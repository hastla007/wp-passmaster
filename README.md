# WP Pass Captain

Eigenständiges WordPress-Plugin für Event-, Termin-, Ticket- und Einlassverwaltung. WooCommerce und Voucher Captain sind optionale Integrationen und keine Voraussetzung für den Kern.

## Funktionen

- Events als Einzeltermin oder wöchentliche Serie mit bis zu 12 getrennten Terminen im Admin anlegen.
- Events als Shortcode `[wp_pass_captain_events]` oder Gutenberg-Block ausgeben.
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
- Wartelisten nehmen Einträge auf und können zeitlich begrenzte Angebote senden. Ein Angebot hält noch keine Ticketkapazität.
- Rollenbeschränkte Veranstaltungsberichte zeigen bezahlte Bestellungen, verkaufte Tickets, Bruttosummen pro Währung und Einlasszahlen.
- Eventgebundene Read-only-API-Schlüssel, übersetzte Eventkarten, site-getrennte Multisite-Migrationen und ein generischer Job-Queue-Grundbaustein sind verfügbar.
- Länder-/Steuerprofile werden ausschließlich als Entwürfe gespeichert. Eine geprüfte Steuerberechnung ist nicht enthalten.

## Entwicklung

PHP 8.2+, Composer und WordPress erforderlich. `composer install` lädt die QR-Code-Abhängigkeit. Das Plugin kann ohne generierten Composer-Autoloader gestartet werden; QR-Bilder erscheinen erst mit installierten Abhängigkeiten.

Plugin-Version `0.3.0` nutzt Datenbankschema `3.0.0`. Das Upgrade erfolgt über `dbDelta` beim Laden des Plugins. Neue REST-Routen decken Kampagnen, Wartelisten, Eventberichte, Übersetzungen und eventgebundene Entwickler-Schlüssel ab. WordPress-Schreibzugriffe brauchen eine eingeloggte WP-Session mit REST-Nonce; Entwickler-Schlüssel sind nur für dokumentierte Read-only-Scopes vorgesehen.

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

## Noch offen

Viele Schritte 21–50 sind Backend-Grundlagen. Checkout-/Admin-Oberflächen, PSP-/Voucher-Orchestrierung, Checkout-verknüpfte Sitzplatz- und Servicebuchungen, echte Wallet-Anbieter, geprüfte Steuerberechnung und PDF-Rechnungen fehlen. Wartelistenangebote reservieren keine Kapazität; Teilnehmerfelder sind noch nicht im Checkout. Entwickler-API-Schlüssel haben noch kein zusätzliches eigenes Rate-Limit; externe Connectoren müssen separat implementiert und aktiviert werden. Last-, Restore-, Sicherheits- und Barrierefreiheitsprüfungen sind noch nicht erfolgt. Es gibt noch keinen reproduzierbaren 1.0-Release-Prozess. Siehe Roadmaps und `docs/` für die Grenzen der einzelnen Grundlagen.
