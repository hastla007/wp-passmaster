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
- WooCommerce nutzt CRUD und Bestellmetadaten und bleibt optional. Woo Classic und Blocks folgen dem normalen Woo-Checkout.
- Veröffentliche Events können abgesagt werden; Käufer werden benachrichtigt und unbezahlte Reservierungen aufgehoben.
- REST-Grundlagen für Sitzpläne, Service-Slots, POS-Verkäufe, Offline-Scan-Synchronisierung, Einlassregeln pro Tag und Teilnehmerzuweisung sind enthalten.
- Transaktionale Rechnungsnummern, geplanter Mailversand und Mitgliedspässe mit Nutzungslimits sind als Basismodule verfügbar.
- Eventkarten können über `templates/event-card.php` im Theme überschrieben werden. Währungen und Wallet-Provider sind erweiterbar.

## Entwicklung

PHP 8.2+, Composer und WordPress erforderlich. `composer install` lädt die QR-Code-Abhängigkeit. Das Plugin kann ohne generierten Composer-Autoloader gestartet werden; QR-Bilder erscheinen erst mit installierten Abhängigkeiten.

Plugin-Version `0.2.0` nutzt Datenbankschema `2.0.0`. Das Upgrade erfolgt über `dbDelta` beim Laden des Plugins. Für neue Integrationen stehen unter `/wp-json/wp-pass-captain/v1/` Routen für Sitzpläne, Ressourcen/Slots, POS-Verkäufe, Admission Days, Passausgabe und Offline-Synchronisierung bereit; geschützte Routen brauchen eine eingeloggte WP-Session mit REST-Nonce.

```sh
composer install
bash build/release.sh
```

Der CI-Workflow führt Composer-Metadatenprüfung, PHP-Syntaxprüfung und JavaScript-Syntaxprüfung aus und erzeugt ein installierbares ZIP als Workflow-Artefakt.

## Roadmaps und Entwurfsdokumente

- [Nächste 10 Schritte](ROADMAP.md)
- [Schritte 11–30](ROADMAP_11_30.md)
- [Schritte 31–50](ROADMAP_31_50.md)

## Noch offen

Mehrere der Schritte 21–35 sind Backend-Grundlagen und benötigen noch Admin-/Checkout-Oberflächen oder externe Anbieter: Sitzplatz-/Servicebuchungen sind noch nicht in die Ticketzahlung integriert, Offline-Scans können geräteübergreifend kollidieren, Apple/Google Wallet sind nur Adapterverträge, Rechnungen enthalten keine Steuerberechnung oder PDF, und konfigurierbare Teilnehmerfelder werden noch nicht im Checkout abgefragt. PSP, Voucher-Checkout und vollständige Erstattungs-/Umbuchungsorchestrierung fehlen ebenfalls. Siehe Roadmaps für den Status je Schritt.
