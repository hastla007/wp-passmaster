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
- [Schritte 31–50](ROADMAP_31_50.md)

## Noch offen

Es gibt noch keine PSP-Anbindung, keine integrierte Gutscheinberechnung oder -einlösung, keine WooCommerce-Blocks-spezifische Ticketbestandsanzeige und keine vollständige Rückerstattungs-/Umbuchungsorchestrierung. Die WooCommerce-Status-Synchronisierung stellt keine Erstattung beim Zahlungsanbieter aus. Siehe [Schritte 11–30](ROADMAP_11_30.md) für den Status.
