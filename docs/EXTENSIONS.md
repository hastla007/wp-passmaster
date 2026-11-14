# Erweiterungen und Add-ons

Pass Captain ist als Kern-Plugin vollständig frei (GPL-2.0-or-later) und hängt von keinem externen
Lizenzserver ab. Premiumfunktionen gehören in ein **getrenntes Add-on-Plugin**, das nur über die hier
beschriebenen Erweiterungspunkte andockt (Entscheidung vom 13.11.2026, siehe
[FREE-PREMIUM.md](FREE-PREMIUM.md)). Das Kern-Plugin enthält keinen Lizenzclient, keine Rechteprüfung,
keinen eigenen Updater und keinen kommerziellen Feature-Schalter; der Wächtertest
`tests/guard/no-license-code.php` sichert das in der CI ab.

Grundsätze für Add-ons:

- Nur öffentliche Hooks, Verträge und Klassen dieser Seite und aus [HOOKS.md](HOOKS.md) verwenden, keine
  direkten Schreibzugriffe auf `wpcap_*`-Tabellen. Zahlungs- und Ticketzustände ändern sich ausschließlich über
  `Orders::confirm_external_payment()`, `Orders::cancel_order()` und die Dienste unten.
- Hooks mit Suffix `_v1` sind versionierte Verträge: inkompatible Änderungen bekommen einen neuen Namen
  (`_v2`), der alte bleibt mindestens eine Hauptversion lang erhalten. Hooks mit Präfix `wpcap_` sind stabile
  Erweiterungspunkte; Änderungen stehen im `CHANGELOG.md`.
- Fällt das Add-on, seine Lizenz oder ein externer Dienst aus, dürfen Tickets, Einlass und Daten des Kerns
  nicht gesperrt werden. Das Add-on schaltet dann nur seine eigenen Funktionen ab.
- Ladezeitpunkt: Der Kern registriert seine Module auf `plugins_loaded` (Priorität 10). Filter wie
  `wpcap_modules`, `wpcap_payment_gateways` oder `wpcap_sms_providers` registriert das Add-on deshalb direkt
  beim Laden seiner Hauptdatei oder spätestens auf `plugins_loaded` mit Priorität < 10.
- Mindestversion des Kerns prüfen: `defined( 'WPCAP_VERSION' ) && version_compare( WPCAP_VERSION, 'x.y', '>=' )`;
  sonst mit Admin-Hinweis aussteigen.

## Übersicht der Andockpunkte

| Bereich | Andockpunkt | Art | Ort im Code |
|---|---|---|---|
| Admin-Navigation | `wpcap_modules` → Methode `admin_pages()` | Filter + Modulvertrag | `src/Modules.php`, `src/Admin/Menu.php` |
| Admin-Formulare | `wpcap_modules` → Methode `admin_handlers()` | Modulvertrag (`admin-post`-Aktionen) | `src/Admin/Menu.php` |
| Einstellungen | `wpcap_modules` → Methode `settings_defaults()`, lesen über `PassCaptain\Settings::get()` | Modulvertrag | `src/Settings.php` |
| Eigene Tabellen | `wpcap_modules` → Methode `schema( $prefix, $charset )` | Modulvertrag (dbDelta) | `src/Modules.php`, `src/Installer.php` |
| Hooks registrieren | `wpcap_modules` → Methode `register()` | Modulvertrag | `src/Modules.php` |
| Zahlungsadapter | `wpcap_payment_gateways` + `PassCaptain\Payments\GatewayInterface` | Filter + Interface | `src/Payments/Registry.php` |
| SMS-Adapter | `wpcap_sms_providers` + `PassCaptain\Sms\ProviderInterface` | Filter + Interface | `src/Sms/Service.php` |
| Wallet-Anbieter | `wp_pass_captain_wallet_provider_v1` + `PassCaptain\Wallet\ProviderInterface` | Versionierter Filter | `src/Wallet/Bridge.php` |
| Gutschein-Anbieter | `wp_pass_captain_voucher_provider_v1` + `PassCaptain\Integrations\Vouchers\ProviderInterface` | Versionierter Filter | `src/Integrations/Vouchers/Bridge.php` |
| Scanner (fremde Geräte/Apps) | `wp_pass_captain_checkin_service_v1` (Dienst abrufen), Mobile-REST-API | Versionierter Dienst | `src/Integrations/Vouchers/CheckinService.php`, [MOBILE-API.md](MOBILE-API.md) |
| Ticketausgabe von außen | `wp_pass_captain_ticket_service_v1` (Dienst abrufen) | Versionierter Dienst | `src/Integrations/Vouchers/TicketService.php` |
| Connectoren (CRM, Kalender, Buchhaltung) | `wp_pass_captain_connectors_v1` + `PassCaptain\Integrations\ConnectorInterface` | Versionierter Filter | `src/Integrations/ConnectorRegistry.php` |
| Hintergrundjobs | `PassCaptain\Jobs::enqueue()` + `wp_pass_captain_process_job_v1` | Versionierter Filter | `src/Jobs.php` |
| E-Mail-Typen | `wpcap_mail_types`, `wpcap_mail`, `wpcap_mail_before_send` | Filter/Action | `src/Mail/Types.php`, `src/Mail/Mailer.php` |
| Checkout-Felder | `wpcap_checkout_form_fields`, `wpcap_checkout_payload_keys`, `wpcap_order_creating` | Action/Filter | `templates/event-card.php`, `src/Orders.php` |
| Preise und Kontingente | `wpcap_ticket_unit_price`, `wpcap_ticket_price_label`, `wpcap_reserve_capacity`, `wpcap_ticket_availability` | Filter | `src/Orders.php` |
| Lebenszyklus-Ereignisse | `wpcap_order_paid`, `wpcap_tickets_issued`, `wpcap_ticket_checked_in`, `wpcap_refund_completed` u. a. | Actions | siehe [HOOKS.md](HOOKS.md) |
| Externe Systeme ohne PHP | Signierte ausgehende Webhooks | HTTP | [WEBHOOKS.md](WEBHOOKS.md) |
| Berichte | eigene Seite über `admin_pages()`; Daten über `PassCaptain\Reports\Data` (intern, nicht versioniert) | – | `src/Reports/Data.php` |
| Exporte | eigene Seite/Aktion über `admin_pages()`/`admin_handlers()` | – | – |

## Module: Admin-Navigation, Einstellungen, Tabellen

Ein Add-on bringt seine Funktionen als **Modulklasse** mit und hängt sie über den Filter `wpcap_modules`
an die Liste der Kernmodule (`src/Modules/*.php`). Jede Methode ist optional:

| Methode | Rückgabe | Wirkung |
|---|---|---|
| `register()` | – | Wird auf `plugins_loaded` aufgerufen; hier eigene Hooks registrieren. |
| `schema( $prefix, $charset )` | `string[]` CREATE-TABLE-Anweisungen (dbDelta-Format) | Tabellen werden mit dem Kernschema angelegt und aktualisiert; Änderungen werden über einen Hash erkannt. |
| `settings_defaults()` | `array` Schlüssel → Standardwert | Wird in `Settings::defaults()` übernommen; Werte liest das Add-on mit `Settings::get( $key )`. Modul-Einstellungen erscheinen **nicht** auf der Kern-Einstellungsseite, sondern werden auf der eigenen Seite des Moduls bearbeitet (`Settings::update()`). |
| `admin_pages()` | Liste von `[ slug, title, capability, callable ]` | Unterseiten im Menü **Pass Captain** (vor „Einstellungen“). |
| `admin_handlers()` | `array` `admin-post`-Aktion → callable | Formular-Handler (`admin_post_{action}`). Nonce und Rechte prüft der Handler selbst. |

```php
add_filter( 'wpcap_modules', static function ( array $classes ) {
	$classes[] = \MyAddon\Module::class;
	return $classes;
} );
```

Der Menü-Slug des Kerns ist `wp-pass-captain` (`PassCaptain\Admin\Menu::SLUG`). Seiten-Slugs des Add-ons
sollten ein eigenes Präfix tragen; Präfix `wpcap` lädt die kleinen Admin-Stilkorrekturen des Kerns mit.

## Zahlungsadapter

Der Filter `wpcap_payment_gateways` erhält die Liste der eingebauten Adapter (`manual`, `stripe`, `paypal`,
mit Modulen auch `mollie` und die Kassen-Zahlarten) und nimmt weitere Objekte mit
`PassCaptain\Payments\GatewayInterface` auf: `id()`, `title()`, `is_available( $currency )`,
`create_payment( $order, $items )`, `verify_webhook( WP_REST_Request )`, `supports_refunds()`,
`refund( $order, $amount_minor, $idempotency_key )`.

- Aktivierung über die Kern-Einstellung „Zahlungsarten“ (`enabled_providers`); dort erscheinen alle
  registrierten Adapter automatisch.
- Webhooks des Anbieters laufen über `POST /wp-json/wp-pass-captain/v1/payments/{id}/webhook`; der Kern
  dedupliziert Ereignisse (`wpcap_payment_events`) und bestätigt Zahlungen nur bei passendem Betrag und
  passender Währung.
- Weitere Filter: `wpcap_payment_return_url`.

## SMS- und Wallet-Anbieter

- `wpcap_sms_providers`: Objekte mit `PassCaptain\Sms\ProviderInterface` (`id()`, `title()`,
  `is_configured()`, `send( $to, $body, $reference )`).
- `wp_pass_captain_wallet_provider_v1`: `( $provider, $provider_key, $version )`, Rückgabe eines Objekts mit
  `PassCaptain\Wallet\ProviderInterface` (`create_pass()`, `update_pass()`, `revoke_pass()`). Der Kern
  registriert seinen eigenen Anbieter mit Priorität 5.

## Scanner

Es gibt keine Registry für Scanner-Adapter. Ein Add-on, das einen eigenen Scanner mitbringt, nutzt den
Check-in-Dienst des Kerns statt eigener Prüflogik:

```php
$service = apply_filters( 'wp_pass_captain_checkin_service_v1', null, 1 );
if ( $service && $service->recognizes( $code ) ) {
	$result = $service->check_in( $code, $idempotency_key ); // prüft das Recht wpcap_checkin selbst
}
```

Externe Geräte und Apps verwenden die Mobile-API (`/wp-json/wp-pass-captain/v1/app/`, siehe
[MOBILE-API.md](MOBILE-API.md)) und den Offline-Scanner ([OFFLINE-SCANNER.md](OFFLINE-SCANNER.md)).
Ereignisse: `wpcap_ticket_checked_in`, `wpcap_ticket_checked_out`.

## Connectoren

Der Connector-Vertrag `PassCaptain\Integrations\ConnectorInterface` (`key()`, `version()`,
`capabilities()`, `deliver( $event_name, $payload, $idempotency_key )`) ist versioniert. Adapter werden über
`wp_pass_captain_connectors_v1` registriert und nur ausgeführt, wenn ihr Schlüssel in der site-lokalen Option
`wpcap_enabled_connectors` freigegeben wurde. Fehler meldet `wp_pass_captain_connector_error_v1`.

Hinweis: `ConnectorRegistry::dispatch()` wird derzeit von keinem Kernablauf aufgerufen. Bis das verdrahtet
ist, hängen sich Connectoren direkt an die Lebenszyklus-Actions (z. B. `wpcap_order_paid`) und rufen
`ConnectorRegistry::dispatch()` dort selbst auf, oder sie nutzen die ausgehenden Webhooks.

## Hintergrundjobs

Hintergrundadapter können idempotente Jobs über `PassCaptain\Jobs::enqueue()` einplanen. Ein Handler
registriert einen Filter auf `wp_pass_captain_process_job_v1` und gibt `true` zurück, wenn der Job erfolgreich
und wiederholbar verarbeitet wurde; andernfalls wird er mit Backoff erneut versucht und nach acht Versuchen
als `dead_letter` markiert.

## Berichte und Exporte

Für Berichte und Exporte gibt es noch keine eigenen Hooks. Ein Add-on legt dafür eine eigene Unterseite über
`admin_pages()` und eigene Download-Aktionen über `admin_handlers()` an und prüft die Kernrechte
(`wpcap_view_reports` für Berichte). `PassCaptain\Reports\Data` und `PassCaptain\Reports\Scope` sind interne
Klassen ohne Versionszusage.

## WP Affiliate Captain

`src/Integrations/AffiliateCaptain/Adapter.php` verbindet Pass Captain mit WP Affiliate Captain über dessen öffentlichen Vertrag v1 (`wpac_record_sale()`, `wpac_record_refund()`, `wpac_resolve_partner_code()`, `wpac_current_touch()`). Kein Zugriff auf Tabellen von Affiliate Captain; ohne Affiliate Captain oder bei anderer Vertrags-Hauptversion ist der Adapter inaktiv.

| Pass Captain | Affiliate Captain |
|---|---|
| `wpcap_order_creating` | Partnercode prüfen, Referral (Code, Link/Cookie, Kanal) im `price_snapshot` speichern |
| `wpcap_order_paid` | Verkauf `source = pass-captain`, eine Position je Bestellzeile, SKU `PASS-E{Event}-T{Ticketart}` (Regeln z. B. `PASS-E12-*`), Basis = bezahlter Anteil ohne USt, `hold_until` = Terminende |
| `wpcap_refund_completed` | anteilige Umkehr für die erstatteten Tickets (Nettobetrag der Erstattung) |
| `wpcap_order_refunded` / `wpcap_order_cancelled` | Umkehr des Rests (nur wenn ein Verkauf gemeldet wurde) |
| Bestellungen über WooCommerce | werden nicht gemeldet – Affiliate Captain erfasst die Woo-Bestellung selbst |

Ergebnisse stehen im Audit-Log (`affiliate_sale`, `affiliate_refund`, `…_queued`, `…_failed`). Filter siehe `docs/HOOKS.md`.
