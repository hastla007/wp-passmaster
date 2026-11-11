# WP Pass Captain

Eigenständiges WordPress-Plugin für Event-, Termin-, Ticket- und Einlassverwaltung. WooCommerce und Voucher Captain sind optionale Integrationen und keine Voraussetzung für den Kern.

## Erste MVP-Funktionen

- Events mit einem Termin und einer Ticketkategorie im Admin anlegen.
- Events als Shortcode `[wp_pass_captain_events]` oder Gutenberg-Block ausgeben.
- Bestellungen mit zehnminütiger Reservierung anlegen; kostenlose Bestellungen stellen Tickets direkt aus.
- Bezahlte Bestellungen werden in diesem Stand außerhalb des Plugins abgewickelt und anschließend manuell bestätigt.
- Ticketlink enthält einen zufälligen 256-Bit-Code; nur dessen SHA-256-Hash wird für die Prüfung gespeichert.
- Einlasspersonal eventbezogen zuweisen und den Scanner über `[wp_pass_captain_scanner]` bereitstellen.
- WordPress-Datenschutzexport/-löschung und ein CI-/ZIP-Build sind vorbereitet.

## Entwicklung

PHP 8.2+, Composer und WordPress erforderlich. `composer install` lädt die QR-Code-Abhängigkeit. Das Plugin kann ohne generierten Composer-Autoloader gestartet werden; QR-Bilder erscheinen erst mit installierten Abhängigkeiten.

```sh
composer install
bash build/release.sh
```

Der CI-Workflow führt Composer-Metadatenprüfung, PHP-Syntaxprüfung und JavaScript-Syntaxprüfung aus und erzeugt ein installierbares ZIP als Workflow-Artefakt.

## Roadmaps und Entwurfsdokumente

- [Nächste 10 Schritte](ROADMAP.md)
- [Schritte 11–30](ROADMAP_11_30.md)
- [Produkt- und Architekturkonzept](CONCEPT.md)
- [Umsetzungs-Backlog](TODO.md)

## Umfangsgrenzen

Dieser MVP enthält noch keine PSP-Anbindung, native PDF-Erzeugung, Rückerstattungsabläufe, vollständige Bearbeitung bestehender Events, WooCommerce-/Voucher-Captain-Bridge, Offline-Scanner oder Rechts-/Steuerkonfiguration. Die Roadmap führt diese Funktionen weiter.
