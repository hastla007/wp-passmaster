=== WP Pass Captain ===
Contributors: hastla007
Tags: events, tickets, check-in, booking, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.0
WC requires at least: 8.2
WC tested up to: 11.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Eigenständige Event-, Ticket- und Einlassverwaltung für WordPress – mit optionaler WooCommerce-Anbindung.

== Description ==

WP Pass Captain verwaltet Veranstaltungen, Termine, Ticketarten, Bestellungen, Tickets und den Einlass direkt in WordPress. WooCommerce ist optional und keine Voraussetzung für den Kern.

* Events als Einzeltermin oder Serie, Ausgabe per Shortcode `[wp_pass_captain_events]` oder Block.
* Bestellungen mit zeitlich begrenzter Reservierung; kostenlose Bestellungen stellen Tickets sofort aus.
* Zahlungen manuell, über Stripe, PayPal, Mollie oder den WooCommerce-Adapter (HPOS und Block-Checkout).
* Tickets mit QR-Code, PDF und Wallet-Pass; Scanner per Shortcode `[wp_pass_captain_scanner]`, Mobil-API und Offline-Scanner.
* Rechnungen und Gutschriften, Erstattungen mit Freigabe, Berichte, Wartelisten, Kampagnencodes, Mitgliedspässe.
* E-Mail-Vorlagen je Sprache, Erinnerungen per E-Mail und SMS, Webhooks für externe Systeme.
* Datenschutz-Export und -Löschung über die WordPress-Werkzeuge; Multisite-fähig (Daten je Site getrennt).

Daten werden beim Löschen des Plugins nur entfernt, wenn „Daten beim Löschen entfernen“ in den Einstellungen aktiviert ist.

== Installation ==

1. ZIP unter Plugins → Installieren → Plugin hochladen einspielen und aktivieren.
2. Unter Pass Captain → Einstellungen Verkäuferdaten, Zahlungsarten und Rechtstexte hinterlegen.
3. Für zuverlässigen Mail-, SMS- und Webhook-Versand WP-Cron durch einen System-Cronjob auslösen (siehe „Betrieb“).

== Frequently Asked Questions ==

= Brauche ich WooCommerce? =

Nein. WooCommerce ist eine optionale Integration; Checkout, Zahlung und Tickets funktionieren auch ohne.

= Wie laufen Datenbank-Upgrades nach einem Update? =

Das Schema-Upgrade läuft im Admin-Bereich, per WP-Cron oder WP-CLI – geschützt durch eine Datenbanksperre, damit parallele Aufrufe es nicht mehrfach starten. Frontend-Aufrufe planen das Upgrade nur ein.

= Betrieb =

Warteschlangen (E-Mails, SMS, Jobs, Webhooks) werden über WP-Cron mit einem Zeitbudget von etwa 20 Sekunden pro Lauf abgearbeitet; bleibt ein Rückstand, folgt sofort ein weiterer Lauf. Ist Action Scheduler (z. B. mit WooCommerce) vorhanden, werden Folgeläufe darüber eingeplant. Für Shops mit viel Verkehr empfiehlt sich `define( 'DISABLE_WP_CRON', true );` in der wp-config.php und ein System-Cronjob, der jede Minute `wp cron event run --due-now` ausführt.

== Changelog ==

= 1.1.0 =
* Übersetzungen (fr_FR, it_IT, es_ES, nl_NL, en_US), Serien-Preisstufen im WooCommerce-Warenkorb, E-Mail-Vorlagen und Anbindung an WP Affiliate Captain. Vollständige Liste: CHANGELOG.md im Repository.
