=== Pass Captain ===
Contributors: hastla007
Tags: events, tickets, check-in, booking, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.4.0
WC requires at least: 8.2
WC tested up to: 11.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Eigenständige Event-, Ticket- und Einlassverwaltung für WordPress – mit optionaler WooCommerce-Anbindung.

== Description ==

Pass Captain verwaltet Veranstaltungen, Termine, Ticketarten, Bestellungen, Tickets und den Einlass direkt in WordPress. WooCommerce ist optional und keine Voraussetzung für den Kern.

* Events als Einzeltermin oder Serie, Ausgabe per Shortcode `[wpcap_events]` oder Block.
* Bestellungen mit zeitlich begrenzter Reservierung; kostenlose Bestellungen stellen Tickets sofort aus.
* Zahlungen manuell, über Stripe, PayPal, Mollie oder den WooCommerce-Adapter (HPOS und Block-Checkout).
* Tickets mit QR-Code, PDF und Wallet-Pass; Scanner per Shortcode `[wpcap_scanner]`, Mobil-API und Offline-Scanner.
* Rechnungen und Gutschriften, Erstattungen mit Freigabe, Berichte, Wartelisten, Kampagnencodes, Mitgliedspässe.
* E-Mail-Vorlagen je Sprache, Erinnerungen per E-Mail und SMS, Webhooks für externe Systeme.
* Datenschutz-Export und -Löschung über die WordPress-Werkzeuge; Multisite-fähig (Daten je Site getrennt).

Daten werden beim Löschen des Plugins nur entfernt, wenn „Beim Löschen des Plugins alle Daten entfernen“ in den Einstellungen aktiviert ist.

== Installation ==

1. ZIP unter Plugins → Installieren → Plugin hochladen einspielen und aktivieren.
2. Unter Pass Captain → Einstellungen Verkäuferdaten, Zahlungsarten und Rechtstexte hinterlegen.
3. Für zuverlässigen Mail-, SMS- und Webhook-Versand WP-Cron durch einen System-Cronjob auslösen (siehe „Betrieb“).

== External services ==

Pass Captain funktioniert ohne externe Dienste. Die folgenden Dienste werden nur angesprochen, wenn der Betreiber der Website sie in den Einstellungen aktiviert und mit eigenen Zugangsdaten einrichtet. Alle Verbindungen gehen vom Server der Website aus (über WordPress-HTTP-API bzw. cURL, nur HTTPS, nur zu den genannten Hosts), sofern nicht anders angegeben. Es gibt keine Telemetrie und keine Aufrufe an Server des Plugin-Autors.

= Stripe (Zahlungen) =

Wofür: Online-Zahlung per Karte und weiteren Zahlarten über Stripe Checkout sowie Erstattungen.
Wann: Wenn Käufer im Checkout „Stripe“ wählen (Anlegen einer Checkout-Session), bei Erstattungen und beim Abgleich eingehender Stripe-Webhooks (Abruf von Zahlung/Erstattung). Der Käufer wird zur Zahlung auf die Seite von Stripe weitergeleitet.
Daten: Betrag und Währung, Kurzbeschreibung der Bestellung (Event- und Ticketart-Namen), Bestellreferenz, E-Mail-Adresse des Käufers, Rücksprung-Adressen der Website; bei Erstattungen Betrag und Referenz. Host: api.stripe.com.
Bedingungen: https://stripe.com/legal/ssa – Datenschutz: https://stripe.com/privacy

= PayPal (Zahlungen) =

Wofür: Online-Zahlung über PayPal sowie Erstattungen.
Wann: Wenn Käufer im Checkout „PayPal“ wählen (Anlegen und Abschließen der PayPal-Bestellung), bei Erstattungen und zur Prüfung eingehender PayPal-Webhooks (verify-webhook-signature). Der Käufer wird zur Zahlung auf die Seite von PayPal weitergeleitet.
Daten: Client-ID/Secret (Anmeldung), Betrag und Währung, Kurzbeschreibung der Bestellung, Bestellreferenz, Rücksprung-Adressen; bei Erstattungen Betrag und Referenz. Hosts: api-m.paypal.com bzw. api-m.sandbox.paypal.com.
Bedingungen: https://www.paypal.com/legalhub/paypal/useragreement-full – Datenschutz: https://www.paypal.com/legalhub/paypal/privacy-full

= Mollie (Zahlungen, Modul) =

Wofür: Online-Zahlung über Mollie (z. B. EPS, iDEAL, Karte) sowie Erstattungen.
Wann: Wenn Käufer im Checkout „Mollie“ wählen (Anlegen der Zahlung), beim Abruf des Zahlungsstatus nach Mollie-Webhooks, beim Laden der verfügbaren Zahlarten im Admin und bei Erstattungen.
Daten: Betrag und Währung, Beschreibung „Bestellung <Referenz>“, Bestellreferenz, Rücksprung- und Webhook-Adresse der Website; bei Erstattungen Betrag und Referenz. Host: api.mollie.com.
Bedingungen: https://www.mollie.com/user-agreement – Datenschutz: https://www.mollie.com/privacy

= Twilio (SMS) =

Wofür: Versand von SMS (z. B. Erinnerungen, Benachrichtigungen), wenn als SMS-Anbieter „Twilio“ gewählt ist.
Wann: Beim Abarbeiten der SMS-Warteschlange (WP-Cron).
Daten: Telefonnummer des Empfängers, SMS-Text (kann Event, Termin und Bestellreferenz enthalten), Absenderkennung, Rückruf-Adresse für den Zustellstatus; Anmeldung mit Account-SID und Auth-Token. Host: api.twilio.com.
Bedingungen: https://www.twilio.com/en-us/legal/tos – Datenschutz: https://www.twilio.com/en-us/legal/privacy

= SMS-HTTP-Gateway (SMS) =

Wofür: Versand von SMS über ein beliebiges HTTP-Gateway, wenn als SMS-Anbieter „HTTP“ gewählt ist.
Wann: Beim Abarbeiten der SMS-Warteschlange (WP-Cron).
Daten: Telefonnummer des Empfängers, SMS-Text und Referenz im vom Betreiber festgelegten Format, an die vom Betreiber eingetragene Adresse (mit dessen Zugangsdaten). Es gelten die Bedingungen und die Datenschutzerklärung des vom Betreiber gewählten Anbieters.

= Apple Push Notification service / Apple Wallet (Wallet-Pässe, Modul) =

Wofür: Aktualisierung von Apple-Wallet-Tickets auf den Geräten der Käufer (z. B. bei Terminänderung oder Absage).
Wann: Wenn sich ein Ticket mit Apple-Wallet-Pass ändert und ein Gerät den Pass für Updates registriert hat. Die Geräte rufen den aktualisierten Pass danach selbst bei der Website ab (die Website ist der Web-Service des Passes).
Daten: Push-Token des Geräts und Pass-Typ-Kennung (Topic) mit leerer Nachricht; Anmeldung mit dem Zertifikat bzw. Schlüssel des Betreibers. Hosts: api.push.apple.com bzw. api.sandbox.push.apple.com.
Bedingungen: https://developer.apple.com/support/terms/ – Datenschutz: https://www.apple.com/legal/privacy/

= Google Wallet (Wallet-Pässe, Modul) =

Wofür: Ausgabe und Aktualisierung von Tickets in Google Wallet.
Wann: Wenn ein Käufer „Zu Google Wallet hinzufügen“ nutzt, sowie bei Änderung, Absage oder Widerruf eines Tickets bzw. Termins mit Google-Wallet-Pass. Für die Anmeldung wird ein Zugriffstoken bei Google OAuth (oauth2.googleapis.com) angefordert; die Pässe werden über walletobjects.googleapis.com angelegt und geändert. Der Link „Zu Google Wallet hinzufügen“ öffnet im Browser des Käufers pay.google.com mit einem signierten Token.
Daten: Event-Name, Datum, Ort, Ticketart, Ticket-Code (QR-Inhalt), Ticketstatus und – falls erfasst – Name des Teilnehmers; für OAuth eine mit dem Dienstkonto des Betreibers signierte Anfrage (Dienstkonto-E-Mail, Scope).
Bedingungen: https://developers.google.com/terms – Datenschutz: https://policies.google.com/privacy

= fiskaltrust und efsta (Fiskalisierung der Kasse, Modul) =

Wofür: Signierung bzw. Registrierung von Kassenbelegen der Abendkasse nach nationalen Fiskalvorschriften.
Wann: Nur wenn in der Kasse eine Fiskalisierung mit fiskaltrust (POS System API) oder efsta (Cloud-EFR) eingerichtet ist: beim Verbindungstest, beim Abschluss eines Kassenbelegs und beim Abfragen seines Status. Eine lokale efsta-EFR (127.0.0.1) ist kein externer Dienst.
Daten: Belegdaten (Positionen, Beträge, Steuersätze, Zahlart, Belegnummer, Zeitpunkt), Kassen-ID und Zugangsdaten des Betreibers; keine Käuferdaten. Endpunkt laut Konfiguration (z. B. possystem-api-sandbox.fiskaltrust.eu).
fiskaltrust: https://fiskaltrust.eu (Bedingungen und Datenschutz laut Vertrag mit fiskaltrust) – efsta: https://www.efsta.eu (Bedingungen und Datenschutz laut Vertrag mit efsta)

= Ausgehende Webhooks (vom Betreiber eingetragene Adressen) =

Wofür: Benachrichtigung externer Systeme des Betreibers (z. B. CRM, Buchhaltung) über Ereignisse.
Wann: Wenn ein abonniertes Ereignis eintritt (z. B. Bestellung bezahlt, Ticket ausgestellt oder eingelöst, Erstattung), über die Webhook-Warteschlange (WP-Cron).
Daten: Ereignistyp und betroffenes Objekt (IDs, Status, Beträge, Zeitpunkte), signiert mit HMAC-SHA256; E-Mail-Adresse oder Name nur, wenn der Betreiber das für das Abonnement ausdrücklich einschaltet. Ziel ist allein die vom Betreiber eingetragene HTTPS-Adresse; es gelten Bedingungen und Datenschutzerklärung des jeweiligen Empfängers.

= Keine externen Aufrufe =

Die Anbindungen an Affiliate Captain, Voucher Captain und WooCommerce laufen innerhalb derselben WordPress-Installation über PHP-Hooks; Pass Captain sendet dafür nichts an fremde Server. Alle Skripte, Schriften und Bibliotheken (auch für Scanner und Offline-Scanner) werden mit dem Plugin ausgeliefert und nicht von CDNs geladen.

== Third-party libraries ==

* tweetnacl-js 1.0.3 (The Unlicense), https://github.com/dchest/tweetnacl-js – der Offline-Scanner lädt `assets/vendor/tweetnacl/nacl-fast.min.js`; der lesbare Quelltext `nacl-fast.js` derselben Version liegt daneben, Version, Herkunft und Prüfsummen stehen in `assets/vendor/tweetnacl/SOURCE.txt`.
* jsQR (Apache-2.0), https://github.com/cozmo/jsQR – `assets/vendor/jsqr/jsQR.js` (unminifiziert).
* PHP-Bibliotheken über Composer im Ordner `vendor/` (Quelltext unverändert enthalten).

== Frequently Asked Questions ==

= Brauche ich WooCommerce? =

Nein. WooCommerce ist eine optionale Integration; Checkout, Zahlung und Tickets funktionieren auch ohne.

= Wie laufen Datenbank-Upgrades nach einem Update? =

Das Schema-Upgrade läuft im Admin-Bereich, per WP-Cron oder WP-CLI – geschützt durch eine Datenbanksperre, damit parallele Aufrufe es nicht mehrfach starten. Frontend-Aufrufe planen das Upgrade nur ein.

= Ich nutze noch „WP Pass Captain“ (bis 1.4) – wie steige ich um? =

Das Plugin heißt für WordPress.org jetzt „Pass Captain“ (Slug `pass-captain`). Neue Version installieren und aktivieren: Die alte Kopie wird automatisch abgeschaltet, alle Daten bleiben erhalten, alte Webhook- und API-Adressen funktionieren weiter. Danach die alte Kopie löschen – die Daten bleiben auch dann erhalten. Details: docs/RENAME.md im Repository.

= Betrieb =

Warteschlangen (E-Mails, SMS, Jobs, Webhooks) werden über WP-Cron mit einem Zeitbudget von etwa 20 Sekunden pro Lauf abgearbeitet; bleibt ein Rückstand, folgt sofort ein weiterer Lauf. Ist Action Scheduler (z. B. mit WooCommerce) vorhanden, werden Folgeläufe darüber eingeplant. Für Shops mit viel Verkehr empfiehlt sich `define( 'DISABLE_WP_CRON', true );` in der wp-config.php und ein System-Cronjob, der jede Minute `wp cron event run --due-now` ausführt.

== Changelog ==

= 1.4.0 =
* Verkaufsmodelle Eigenhändler/Vermittler, Rechnung auf Firma/Adresse, Einzweck- und Mehrzweckgutscheine. Vollständige Liste: CHANGELOG.md im Repository.

= 1.3.0 =
* Sicherheit und Erstattungen, Abläufe, Kundenerlebnis, Steuer und Belege, Datenschutz, Betrieb und Release. Vollständige Liste: CHANGELOG.md im Repository.

= 1.2.0 =
* Gutschein in Ticket umwandeln (Voucher Captain), Veranstalter statt „Partner“. Vollständige Liste: CHANGELOG.md im Repository.

= 1.1.0 =
* Übersetzungen (fr_FR, it_IT, es_ES, nl_NL, en_US), Serien-Preisstufen im WooCommerce-Warenkorb, E-Mail-Vorlagen und Anbindung an Affiliate Captain. Vollständige Liste: CHANGELOG.md im Repository.

== Upgrade Notice ==

= 1.5.0 =
Neuer Name „Pass Captain“ (früher „WP Pass Captain“). Beim Aktivieren wird die alte Kopie abgeschaltet; Daten, Webhooks und API-Adressen bleiben erhalten.
