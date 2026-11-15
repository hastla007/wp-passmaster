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
| Admin-Navigation | `wpcap_modules` → Methode `admin_pages()` oder `wp_pass_captain_admin_menu_v1` | Filter + Modulvertrag / versionierter Filter | `src/Modules.php`, `src/Admin/Menu.php` |
| Admin-Formulare | `wpcap_modules` → Methode `admin_handlers()` | Modulvertrag (`admin-post`-Aktionen) | `src/Admin/Menu.php` |
| Einstellungen | `wpcap_modules` → `settings_defaults()` oder `wp_pass_captain_settings_defaults_v1`; Felder auf der Kern-Einstellungsseite über `wp_pass_captain_settings_sections_v1`; lesen über `PassCaptain\Settings::get()` | Modulvertrag / versionierte Filter | `src/Settings.php`, `src/Admin/SettingsPage.php` |
| Eigene Tabellen | `wpcap_modules` → Methode `schema( $prefix, $charset )` | Modulvertrag (dbDelta) | `src/Modules.php`, `src/Installer.php` |
| Hooks registrieren | `wpcap_modules` → Methode `register()` | Modulvertrag | `src/Modules.php` |
| Zahlungsadapter | `wpcap_payment_gateways` + `PassCaptain\Payments\GatewayInterface` | Filter + Interface | `src/Payments/Registry.php` |
| SMS-Adapter | `wpcap_sms_providers` + `PassCaptain\Sms\ProviderInterface` | Filter + Interface | `src/Sms/Service.php` |
| Wallet-Anbieter | `wp_pass_captain_wallet_provider_v1` + `PassCaptain\Wallet\ProviderInterface` | Versionierter Filter | `src/Wallet/Bridge.php` |
| Gutschein-Anbieter | `wp_pass_captain_voucher_provider_v1` + `PassCaptain\Integrations\Vouchers\ProviderInterface` | Versionierter Filter | `src/Integrations/Vouchers/Bridge.php` |
| Scanner-Adapter (fremde Codes im Kern-Scanner) | `wp_pass_captain_scanner_adapters_v1` | Versionierter Filter (Registry) | `src/ScannerAdapters.php`, `src/Checkin.php` |
| Scanner (fremde Geräte/Apps) | `wp_pass_captain_checkin_service_v1` (Dienst abrufen), Mobile-REST-API | Versionierter Dienst | `src/Integrations/Vouchers/CheckinService.php`, [MOBILE-API.md](MOBILE-API.md) |
| Ticketausgabe von außen | `wp_pass_captain_ticket_service_v1` (Dienst abrufen) | Versionierter Dienst | `src/Integrations/Vouchers/TicketService.php` |
| Connectoren (CRM, Kalender, Buchhaltung) | `wp_pass_captain_connectors_v1` + `PassCaptain\Integrations\ConnectorInterface` | Versionierter Filter | `src/Integrations/ConnectorRegistry.php` |
| Hintergrundjobs | `PassCaptain\Jobs::enqueue()` + `wp_pass_captain_process_job_v1` | Versionierter Filter | `src/Jobs.php` |
| E-Mail-Typen | `wpcap_mail_types`, `wpcap_mail`, `wpcap_mail_before_send` | Filter/Action | `src/Mail/Types.php`, `src/Mail/Mailer.php` |
| Checkout-Felder | `wpcap_checkout_form_fields`, `wpcap_checkout_payload_keys`, `wpcap_order_creating` | Action/Filter | `templates/event-card.php`, `src/Orders.php` |
| Preise und Kontingente | `wpcap_ticket_unit_price`, `wpcap_ticket_price_label`, `wpcap_reserve_capacity`, `wpcap_ticket_availability` | Filter | `src/Orders.php` |
| Lebenszyklus-Ereignisse | `wpcap_order_paid`, `wpcap_tickets_issued`, `wpcap_ticket_checked_in`, `wpcap_refund_completed` u. a. | Actions | siehe [HOOKS.md](HOOKS.md) |
| Externe Systeme ohne PHP | Signierte ausgehende Webhooks; HTTP/JSON-Connector mit Feldzuordnung; Kalender-Abo-Feeds | HTTP | [WEBHOOKS.md](WEBHOOKS.md), [Mitgelieferte Connectoren](#mitgelieferte-connectoren) |
| Berichte | `wp_pass_captain_report_data_v1`, `wp_pass_captain_report_metrics_v1`, `wp_pass_captain_report_tabs_v1` | Versionierte Filter | `src/Reports/Data.php`, `src/Reports/Page.php` |
| Exporte | `wp_pass_captain_export_rows_v1` (Spalten/Zeilen), `wp_pass_captain_export_types_v1` (weitere Exporte) | Versionierte Filter | `src/Reports/Exports.php`, `src/Invoices.php` |
| Protokoll | `wpcap_log` (Action), `wpcap_log_to_error_log` | Action/Filter | `src/Log.php` |
| Admin-Menü fertig (Add-on-Seiten) | `wp_pass_captain_admin_menu_built_v1` | Versionierte Action | `src/Admin/Menu.php` |
| Rechnungen, Korrekturen, Abrechnungen | `wp_pass_captain_invoice_created_v1`, `PassCaptain\Api\Invoices::get()` / `wpcap_get_invoice()`, `wp_pass_captain_invoice_mail_attachments_v1`, `wp_pass_captain_invoice_links_v1` | Action, Lese-API, Filter | `src/Api/Invoices.php` |
| White Label | `wp_pass_captain_brand_name_v1`, `wp_pass_captain_brand_logo_v1`, `wp_pass_captain_admin_menu_title_v1`, `wp_pass_captain_admin_menu_icon_v1` | Versionierte Filter | `src/Brand.php` |
| Zugangsschranke vor dem Kauf (Warteraum) | `wp_pass_captain_checkout_allowed_v1` | Versionierter Filter | `src/Sales/CheckoutGate.php`, `src/Integrations/WooCommerce/CheckoutGate.php` |
| API-Scopes für eigene REST-Routen | `wp_pass_captain_api_scopes_v1`, `PassCaptain\Api\Auth::request_can()` / `wpcap_api_request_can()` | Filter + Prüf-API | `src/DeveloperKeys.php`, `src/Api/Auth.php` |

## Module: Admin-Navigation, Einstellungen, Tabellen

Ein Add-on bringt seine Funktionen als **Modulklasse** mit und hängt sie über den Filter `wpcap_modules`
an die Liste der Kernmodule (statische Liste `Modules::MANIFEST`, eine Klasse je Datei in `src/Modules/`;
Kernmodule werden erst geladen, wenn eine ihrer Methoden gebraucht wird). Add-on-Module werden mit
`method_exists()` geprüft. `admin_handlers()` wird nur in Admin-Anfragen (`is_admin()`, z. B.
`admin-post.php`) abgefragt. Jede Methode ist optional:

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

### Ohne Modulklasse: Menü und Einstellungen

Für Add-ons ohne Modulklasse gibt es versionierte Filter:

```php
// Unterseite im Menü „Pass Captain“ (vor „Einstellungen“); Kern-Slugs werden ignoriert.
add_filter( 'wp_pass_captain_admin_menu_v1', static function ( array $pages ) {
	$pages[] = array( 'myaddon-dashboard', __( 'Pro-Übersicht', 'myaddon' ), 'manage_options', array( \MyAddon\Dashboard::class, 'render' ) );
	return $pages;
} );

// Eigene Einstellungen: Standardwerte registrieren …
add_filter( 'wp_pass_captain_settings_defaults_v1', static function ( array $defaults ) {
	return $defaults + array( 'myaddon_mode' => 'basic', 'myaddon_limit' => 10 );
} );
// … und als Abschnitt auf der Kern-Einstellungsseite bearbeiten (gespeichert mit dem Kernformular).
add_filter( 'wp_pass_captain_settings_sections_v1', static function ( array $sections ) {
	$sections['myaddon'] = array(
		'title'  => __( 'Mein Add-on', 'myaddon' ),
		'fields' => array(
			'myaddon_mode'  => array( 'type' => 'choice', 'label' => __( 'Modus', 'myaddon' ), 'options' => array( 'basic' => 'Basic', 'pro' => 'Pro' ) ),
			'myaddon_limit' => array( 'type' => 'int', 'label' => __( 'Limit', 'myaddon' ), 'min' => 1, 'max' => 100, 'sanitize' => static fn( $v ) => $v > 50 ? new WP_Error( 'x', __( 'Höchstens 50.', 'myaddon' ) ) : $v ),
		),
	);
	return $sections;
} );
```

Feldtypen: `int`, `bool`, `choice`, `text`, `textarea`, `html`, `money`, `secret`. Ein Abschnitt mit der ID eines
Kernabschnitts ergänzt dort nur neue Felder; Kernfelder können weder entfernt noch umtypisiert werden.

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

**Scanner-Adapter-Registry.** Codes, die kein Pass-Captain-Ticket sind (z. B. Karten eines fremden
Zutrittssystems), kann ein Add-on in den Kern-Scannern (Web-Scanner, Mobile-API – alles, was über
`Checkin::perform()`/`Checkin::preview()` läuft) selbst behandeln:

```php
add_filter( 'wp_pass_captain_scanner_adapters_v1', static function ( array $adapters ) {
	$adapters['myaddon_cards'] = array(
		'label'      => __( 'Mitgliedskarten', 'myaddon' ),
		'recognizes' => static fn( string $code ) => str_starts_with( $code, 'MC-' ),
		'check_in'   => static fn( string $code, array $context ) => array( 'status' => 'checked_in', 'label' => '…' ), // oder WP_Error
		'preview'    => static fn( string $code, array $context ) => array( 'status' => 'valid' ),                   // optional
		'capability' => 'wpcap_checkin',                                                                              // optional
	);
	return $adapters;
} );
```

Kern-Tickets haben immer Vorrang: Ein Adapter wird nur für Codes gefragt, die kein Ticket sind; der erste
Adapter, dessen `recognizes` `true` liefert, übernimmt. Rückgaben sind ein Array mit `status` oder ein
`WP_Error`; Exceptions werden zu einem Fehler (`wpcap_scanner_adapter_failed`). Das Ergebnis trägt `adapter`
(ID). Das Recht `capability` (Standard `wpcap_checkin`) prüft der Kern vor dem Aufruf.

Ein Add-on, das einen eigenen Scanner mitbringt, nutzt umgekehrt den Check-in-Dienst des Kerns statt eigener
Prüflogik:

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

Der Kern liefert seine Domänenereignisse über die Job-Queue an alle freigegebenen Connectoren
(`PassCaptain\Integrations\ConnectorEvents`, Jobtyp `connector_delivery`). Je Ereignis und Connector entsteht
ein eigener Job: Ein ausfallender Connector wird mit Backoff wiederholt (nach acht Versuchen `dead_letter`),
ohne dass die anderen das Ereignis doppelt erhalten. Solange kein Connector freigegeben ist, wird nichts
eingeplant.

| Ereignis (`$event_name`) | Auslöser (Action) | `data` |
|---|---|---|
| `order_paid` | `wpcap_order_paid` | Bestellung: `order_id`, `public_id`, `status`, `payment_status`, `payment_provider`, `currency`, `total_minor`, `refunded_minor`, `created_at` |
| `order_refunded` | `wpcap_order_refunded` | Bestellung + `reason` |
| `order_cancelled` | `wpcap_order_cancelled` | Bestellung |
| `refund_completed` | `wpcap_refund_completed` | `refund_id`, `order_id`, `amount_minor`, `tax_minor`, `currency`, `ticket_ids` |
| `tickets_issued` | `wpcap_tickets_issued` | Bestellung + `issued_count`, `tickets` (`ticket_id`, `public_id`, `ticket_type_id`, `status`) |
| `ticket_checked_in` | `wpcap_ticket_checked_in` | `ticket_id`, `public_id`, `order_id`, `ticket_type_id`, `occurrence_id`, `source`, `device_id`, `area_id` |
| `occurrence_rescheduled` | `wpcap_occurrence_rescheduled` | Termin (`occurrence_id`, `event_id`, `starts_at`, `timezone`, `status`) + `old_starts_at`, `new_starts_at` |
| `occurrence_cancelled` | `wpcap_occurrence_cancelled` | Termin + `issued_tickets` |

`$payload` = `[ 'version' => 1, 'event', 'occurred_at' (ISO 8601), 'site', 'data' ]`. Payloads enthalten nur
Kennungen und Zustände – keine Namen, E-Mail-Adressen oder Ticketcodes. `$idempotency_key` ist je Ereignis und
Connector stabil (`sha256( Ereignisschlüssel . '|' . Connector-Schlüssel )`) und bleibt bei Wiederholungen
gleich; der Connector dedupliziert damit. `deliver()` gilt als erfolgreich, wenn es weder `false` noch ein
`WP_Error` zurückgibt und keine Exception wirft; sonst wird der Job wiederholt und
`wp_pass_captain_connector_error_v1` ausgelöst.

### Mitgelieferte Connectoren

Das Modul `Connectors` (Admin-Seite **Konnektoren**, Recht `manage_options`, Aktion `wpcap_connector_action`)
bringt zwei konkrete Adapter auf diesem Vertrag mit.

#### HTTP/JSON-Connector

Für CRM, Zapier, Make, n8n oder eigene Dienste; beliebig viele Endpunkte (`wpcap_connector_endpoints`). Jeder
aktive Endpunkt ist ein eigener Connector mit dem Schlüssel `http_json_{id}`, wird über
`wp_pass_captain_connectors_v1` registriert und beim Speichern in `wpcap_enabled_connectors` ein- bzw.
ausgetragen. Zustellung also über die Job-Queue (ein Job je Ereignis und Endpunkt, Backoff, nach acht Versuchen
`dead_letter`); nicht gewählte Ereignisse werden ohne Anfrage quittiert.

- **Ziel:** nur HTTPS über `Security\OutboundHttp` (öffentliche Adressen, keine Weiterleitungen, Prüfung beim
  Speichern und vor jedem Versand, Antwort max. 64 KB, Timeout 10 s). Erfolg = HTTP 2xx.
- **Authentifizierung:** ein frei benannter Header (Standard `Authorization`, reservierte Header wie `Host`,
  `Content-Type`, `Idempotency-Key` sind gesperrt); der Wert (z. B. `Bearer …`) liegt im Vault (Kontext
  `connector_http_secret`) und wird nicht wieder angezeigt.
- **Anfrage:** `POST` mit `Content-Type: application/json`, `Idempotency-Key`, `X-PassCaptain-Event` und dem Body

  ```json
  { "contract_version": 1, "event": "order_paid", "occurred_at": "2026-11-14T10:00:00+00:00",
    "idempotency_key": "…64 hex…", "data": { … } }
  ```

- **Feldzuordnung:** ohne Zuordnung ist `data` die Kern-Payload (nur Kennungen und Zustände, siehe Tabelle oben).
  Mit Zuordnung enthält `data` genau die gewählten Felder unter den gewählten JSON-Schlüsseln (Punkt =
  Verschachtelung, z. B. `customer.email`; max. 50 Zeilen). Felder: Bestellung (`order.id`, `order.status`,
  `order.payment_status`, `order.payment_provider`, `order.currency`, `order.total_minor`, `order.refunded_minor`,
  `order.locale`, `order.created_at`, `order.customer_email`*), Event/Termin (`event.id`, `event.name`,
  `occurrence.id`, `occurrence.starts_at`, `occurrence.timezone`, `occurrence.location`, `occurrence.status`),
  Ticket/Teilnehmer (`ticket.id`, `ticket.status`, `ticket.type`, `ticket.type_id`, `attendee.name`*,
  `attendee.email`*), Erstattung (`refund.amount_minor`, `refund.currency`) und Check-in (`checkin.source`,
  `checkin.area_id`). Ticket- und Teilnehmerfelder stehen je Ticket in `data.tickets[]` (bei
  `ticket_checked_in` nur das gescannte Ticket, bei `refund_completed` die erstatteten, sonst alle Tickets der
  Bestellung); Event/Termin bei Bestellereignissen = Termin des ersten Tickets. Filter
  `wp_pass_captain_http_connector_data_v1`.
- **Personenbezogene Daten (*)** werden nur gesendet, wenn der Endpunkt das Opt-in „Personenbezogene Daten“ hat;
  ohne Opt-in werden diese Zeilen beim Versand verworfen (die Admin-Seite weist beim Speichern darauf hin).
- **Idempotenz und Protokoll:** `wpcap_connector_deliveries` je Endpunkt und Idempotency-Key mit Status
  `delivered`, `retrying` (die Job-Queue wiederholt), `failed` (Versuche aufgebraucht), Versuchen, HTTP-Status und
  redigierter Fehlermeldung. Ein bereits zugestelltes Ereignis wird ohne zweite Anfrage quittiert (Job-Neustart,
  doppelte Einplanung). Das Protokoll speichert nur die Kern-Payload, nie den gemappten Body; „Jetzt wiederholen“
  sendet erneut mit demselben Schlüssel. Aufbewahrung 30 Tage (Filter `wpcap_connector_log_days`).
- **Test senden:** synthetisches Ereignis `test` (keine gespeicherten Daten, nur die Zuordnung als Beispiel),
  ebenfalls protokolliert.

#### Kalender-Abo-Feeds (iCalendar)

Geheime Abo-URLs `?wpcap_ics=feed&token=…` (auch als `webcal://`) für **ein Event**, **einen Veranstalter**
(Partner, alle seine Events) oder **alle Events** – für Kalender-Apps, CRM oder Intranet
(`PassCaptain\Integrations\CalendarFeeds`, Tabellen `wpcap_calendar_feeds`, `wpcap_calendar_sequences`).

- Token: 32 Zufallsbytes (64 Hex), gespeichert nur als SHA-256, einmalig angezeigt. „Neue URL“ (Rotation) und
  „Widerrufen“ machen die bisherige URL sofort ungültig (404). Audit: `calendar_feed_created`, `_rotated`,
  `_revoked`.
- Inhalt: Termine veröffentlichter und abgesagter Events ab 30 Tagen in der Vergangenheit (max. 1000) als
  `VEVENT` über `Calendar\Ics` (UTC, stabile UID je Termin, `REFRESH-INTERVAL` 60 Minuten). Entwürfe nie, keine
  Käufer- oder Teilnehmerdaten.
- Aktualisierung: jeder Eintrag hat `SEQUENCE` und `LAST-MODIFIED`. Die Sequenz steigt bei
  `wpcap_occurrence_rescheduled`/`wpcap_occurrence_cancelled` und immer dann, wenn ein Abruf geänderte Daten
  (Beginn, Ende, Ort, Status, Titel) erkennt; abgesagte Termine/Events erscheinen als `STATUS:CANCELLED`.

## Hintergrundjobs

Hintergrundadapter können idempotente Jobs über `PassCaptain\Jobs::enqueue()` einplanen. Ein Handler
registriert einen Filter auf `wp_pass_captain_process_job_v1` und gibt `true` zurück, wenn der Job erfolgreich
und wiederholbar verarbeitet wurde; andernfalls wird er mit Backoff erneut versucht und nach acht Versuchen
als `dead_letter` markiert.

## Berichte und Exporte

```php
// Eigene Kennzahl berechnen (Zeitraum/Event aus $filters, erlaubte Events aus $scope) …
add_filter( 'wp_pass_captain_report_data_v1', static function ( array $report, array $filters, $scope ) {
	$report['extra']['myaddon_upsells'] = \MyAddon\Stats::upsells( $filters, $scope );
	return $report;
}, 10, 3 );
// … in der Übersichtstabelle anzeigen,
add_filter( 'wp_pass_captain_report_metrics_v1', static function ( array $rows, array $report ) {
	$rows[ __( 'Zusatzverkäufe', 'myaddon' ) ] = (string) ( $report['extra']['myaddon_upsells'] ?? 0 );
	return $rows;
}, 10, 2 );
// … als eigener Reiter,
add_filter( 'wp_pass_captain_report_tabs_v1', static function ( array $tabs ) {
	$tabs['myaddon'] = array( 'label' => __( 'Pro', 'myaddon' ), 'capability' => 'wpcap_view_finance', 'callback' => array( \MyAddon\Reports::class, 'render_tab' ) );
	return $tabs;
} );
// … als Spalte im Berichts-CSV oder Buchhaltungsexport,
add_filter( 'wp_pass_captain_export_rows_v1', static function ( array $rows, string $type ) {
	if ( 'accounting' === $type ) { foreach ( $rows as $i => $row ) { $rows[ $i ][] = 0 === $i ? 'Kostenstelle' : '4711'; } }
	return $rows;
}, 10, 2 );
// … oder als eigener Export (Schaltfläche auf der Berichtsseite, Nonce und Recht prüft der Kern).
add_filter( 'wp_pass_captain_export_types_v1', static function ( array $types ) {
	$types['myaddon_attendees'] = array( 'label' => __( 'Teilnehmerliste (Pro)', 'myaddon' ), 'capability' => 'wpcap_view_reports', 'rows' => array( \MyAddon\Export::class, 'rows' ) );
	return $types;
} );
```

Alle CSV-Zellen laufen bei der Ausgabe durch den Formelschutz (`Reports\Scope::csv_cell()`).
`PassCaptain\Reports\Data` und `PassCaptain\Reports\Scope` selbst bleiben interne Klassen ohne Versionszusage;
Add-ons verwenden die Filter.

## Pass Captain Pro / Add-on-Schnittstellen

Andockpunkte für das Premium-Add-on „Pass Captain Pro“ (eigenes Repository). Der Kern implementiert die
Premiumfunktionen **nicht** (keine E-Rechnung, kein Warteraum, kein White Label, keine schreibenden API-Routen) –
nur stabile, getestete Schnittstellen (`tests/integration/modules/zzzzz_x5_addon_hooks.php`). Ohne Callbacks
verhält sich der Kern unverändert. Alle Hooks: [HOOKS.md](HOOKS.md).

| Vorgeschlagener Name | Umsetzung im Kern |
|---|---|
| `wp_pass_captain_admin_menu_built_v1( $parent_slug )` | unverändert übernommen |
| `…_invoice_created_v1( $invoice_id, $type )` | `wp_pass_captain_invoice_created_v1( int $id, string $type, array $context )` |
| `PassCaptain\Api\Invoices::get( $id )`, `wpcap_get_invoice( $id )` | beide, mit optionalem zweitem Argument `$type` (`invoice`, `correction`, `settlement`) |
| `…_invoice_mail_attachments_v1` | `wp_pass_captain_invoice_mail_attachments_v1( array $files, int $id, string $type, array $context )` |
| `…_invoice_links_v1` | `wp_pass_captain_invoice_links_v1( array $links, int $id, string $type, array $context )` |
| `…_brand_name_v1`, `…_brand_logo_v1` | `wp_pass_captain_brand_name_v1( $name, $context )`, `wp_pass_captain_brand_logo_v1( $url, $context )` |
| Filter für Titel und Icon des Admin-Menüs | `wp_pass_captain_admin_menu_title_v1`, `wp_pass_captain_admin_menu_icon_v1` |
| `…_checkout_allowed_v1( true, $context )` | `wp_pass_captain_checkout_allowed_v1( true, array $context )` |
| `…_api_scopes_v1` | `wp_pass_captain_api_scopes_v1( array $scopes )` |
| `PassCaptain\Api\Auth::request_can( $r, $scope )`, `wpcap_api_request_can()` | beide, mit optionalem drittem Argument `$event_id` |

### Admin-Menü

```php
add_action( 'wp_pass_captain_admin_menu_built_v1', static function ( string $parent_slug ) {
	add_submenu_page( $parent_slug, __( 'Pro', 'pc-pro' ), __( 'Pro', 'pc-pro' ), 'manage_options', 'pcpro-dashboard', array( \PcPro\Dashboard::class, 'render' ) );
} );
```

Feuert am Ende des Kernmenüs (Hook `admin_menu`) mit dem Slug `wp-pass-captain`, unabhängig von Modulen; die
Seiten stehen nach „Einstellungen“. Wer seine Seite vor „Einstellungen“ braucht, nutzt weiterhin
`wp_pass_captain_admin_menu_v1` (siehe oben).

### Rechnungen: Ereignis, Lese-API, Anhänge, Links (E-Rechnung XRechnung/ZUGFeRD)

```php
// Nach jedem neuen Beleg (Commit erfolgt): z. B. XRechnung erzeugen und ablegen.
add_action( 'wp_pass_captain_invoice_created_v1', static function ( int $id, string $type, array $context ) {
	$doc = PassCaptain\Api\Invoices::get( $id, $type ); // oder wpcap_get_invoice( $id, $type )
	if ( $doc ) { \PcPro\EInvoice::store( $doc ); }
}, 10, 3 );

// XML an die Ticket-Mail (Rechnung) bzw. die Erstattungsbestätigung (Rechnungskorrektur) anhängen.
add_filter( 'wp_pass_captain_invoice_mail_attachments_v1', static function ( array $files, int $id, string $type, array $context ) {
	$file = PassCaptain\Api\Invoices::temp_file( 'xrechnung-' . $id . '.xml', \PcPro\EInvoice::xml( $id, $type ) );
	if ( '' !== $file ) { $files[] = $file; }
	return $files;
}, 10, 4 );

// Download-Link neben „Rechnung anzeigen“ (Admin, Rechnungsansicht, Bestellportal, Abrechnung).
add_filter( 'wp_pass_captain_invoice_links_v1', static function ( array $links, int $id, string $type, array $context ) {
	$args = array( 'pcpro_xml' => $id, 'type' => $type );
	if ( 'customer' === $context['audience'] ) {
		$args += array( 'order' => $context['order_public_id'], 'access' => $context['access_token'] ); // Prüfung: Api\Invoices::for_order_access()
	}
	$links[] = array( 'label' => __( 'E-Rechnung (XML)', 'pc-pro' ), 'url' => add_query_arg( $args, home_url( '/' ) ), 'download' => true );
	return $links;
}, 10, 4 );
```

Belegarten: `invoice` (Kundenrechnung, ID = `invoice_id`), `correction` (Rechnungskorrektur zu einer Erstattung,
ID = `credit_note_id`), `settlement` (abgeschlossene Veranstalter-Abrechnung, ID = `settlement_id`; Entwürfe sind
keine Belege, `get()` liefert `null`). Der Download-Handler des Add-ons prüft selbst: im Admin mit
`Api\Invoices::current_user_can_view( $id, $type )`, beim Käufer mit `Api\Invoices::for_order_access( $public_id, $token )`
(liefert die Rechnungs-ID oder 0).

Anhänge sind **temporäre Dateien**: Der Kern löscht sie nach dem Versand (`temp_file()` legt sie in einem nicht
erratbaren privaten Verzeichnis an, das spätestens am Ende der Anfrage entfernt wird). Ticket-Mails tragen
Add-on-Anhänge nur, wenn die Bestellung beim Versand bereits eine Rechnung hat (mit „Rechnung automatisch erstellen“
bei bezahlten Bestellungen immer). Fehlerhafte Callbacks von `…_invoice_created_v1` werden protokolliert und brechen
die Belegerstellung nicht ab.

`Api\Invoices::get()` liefert (Vertragsversion 1; neue Schlüssel können hinzukommen, bestehende ändern sich nicht):

| Schlüssel | Inhalt |
|---|---|
| `version`, `id`, `type`, `number`, `status` | `1`, Beleg-ID, Belegart, Belegnummer, `issued` bzw. `finalized`/`paid` |
| `date`, `issued_at` | Belegdatum `Y-m-d` (Website-Zeitzone), Zeitpunkt ISO 8601 UTC |
| `currency`, `currency_decimals` | ISO-Code, Nachkommastellen (alle Beträge sind Ganzzahlen in Kleinsteinheiten, z. B. Cent) |
| `seller`, `buyer` (, `agent`) | Partei: `name`, `company`, `address_lines[]`, `street`, `postcode`, `city`, `country`, `vat_id`, `tax_number`, `email`, `small_business`. Der Rechnungssteller hat eine Freitext-Anschrift (nur `address_lines`); der Käufer die strukturierten Rechnungsangaben. `agent` = Plattform bei Vermittler-Rechnungen (sonst `null`). |
| `lines[]` | `position`, `description`, `quantity`, `unit_gross_minor`, `discount_minor`, `net_minor`, `vat_rate` (Prozent, float), `vat_minor`, `gross_minor` |
| `vat_breakdown[]` | je Steuersatz `vat_rate`, `net_minor`, `vat_minor`, `gross_minor` |
| `totals` | `net_minor`, `vat_minor`, `gross_minor`, `discount_minor`, `voucher_payments_minor` (Mehrzweckgutscheine), `paid_minor`, `due_minor` |
| `references` | `order_id`, `order_public_id`, `buyer_reference` (Bestell-/Referenznummer des Käufers, z. B. Leitweg-ID), `terms_version`; Korrektur zusätzlich `invoice_id`, `invoice_number`, `invoice_date`, `refund_id`, `reason`; Abrechnung `settlement_id`, `partner_id`, `period`, `payment_reference` |
| `notes[]`, `sales_model` | Hinweise des Belegs (Kleinunternehmer, Vermittlung, Leistungsort, Reverse Charge), Verkaufsmodell |
| `corrections[]` | (Rechnung) IDs der Rechnungskorrekturen |
| `lines_allocated` | (Korrektur) `true`, wenn der Korrekturbetrag auf mehrere Steuersätze anteilig verteilt wurde |
| `settlement` | (Abrechnung) `document_type`, `reverse_charge`, `ticket_revenue_gross_minor`, `ticket_revenue_vat_minor`, `payout_minor`, `self_billing` (Gutschrift bei Eigenhändler-Veranstaltern: `number`, `seller`, `buyer`, `vat_breakdown`, `totals`, `reverse_charge`, `small_business`, sonst `null`) |

Korrekturen tragen negative Beträge; ihre Positionen sind je Steuersatz aus den erstatteten Tickets abgeleitet.
Abrechnungen beschreiben die Rechnung über Plattformgebühren (Provision, Ticketgebühren) von der Plattform an den
Veranstalter.

### White Label

```php
add_filter( 'wp_pass_captain_brand_name_v1', static fn( string $name, string $context ) => 'Tixx', 10, 2 );
add_filter( 'wp_pass_captain_brand_logo_v1', static fn( string $url, string $context ) => 'https://example.com/tixx.png', 10, 2 );
add_filter( 'wp_pass_captain_admin_menu_icon_v1', static fn() => 'data:image/svg+xml;base64,' . base64_encode( $svg ) );
```

Wirkung: Admin-Hauptmenü (Titel und Icon, auch Netzwerk-Admin), alle übersetzten Texte der Textdomain mit
„Pass Captain“ (Admin-Hinweise, Admin-Mails, Einstellungen, Systemstatus-Widget, Web-Scanner-Überschrift,
Offline-Scanner-Texte – über die gettext-Filter, ohne geänderte Quelltexte), Datenschutz-Leitfaden, Offline-Scanner
(Seitentitel, Logo in der Kopfzeile, Web-App-Manifest über `GET /app/offline/manifest`), Web-Scanner-Shortcode,
Bestellportal und Ticketseite (Logo), PDF-Metadaten (Producer). Kunden-Mails nennen kein Produkt: Absendername und
Fußzeile kommen aus dem Website-Titel bzw. dem Mail-Layout. Bereits vorhandene Filter für den Rest:

| Ort | Filter / Einstellung |
|---|---|
| Absendername und Name in Kunden-Mails | `wpcap_sender_name` |
| Mail-Layout (Logo, Farbe, Fußzeile) | Einstellung „E-Mail-Vorlagen → HTML-Layout“, Filter `wpcap_mail_layout_html` |
| Wallet-Pässe (Apple/Google) | Einstellung `wallet_organization_name`, `wpcap_wallet_apple_pass_json`, `wpcap_wallet_apple_images`, `wpcap_wallet_google_class`, `wpcap_wallet_google_object` |

Nicht umbenannt werden technische Kennungen (Textdomain, Menü-Slug `wp-pass-captain`, REST-Namensraum, iCalendar-PRODID,
WP-CLI-Befehle, Fehlerprotokoll-Präfix) und die mitgelieferten Icons des Offline-Scanners. Ein Logo im Offline-Scanner
muss von derselben Herkunft kommen (CSP `img-src 'self'`).

### Zugangsschranke vor dem Kauf (virtueller Warteraum)

```php
add_filter( 'wp_pass_captain_checkout_allowed_v1', static function ( $allowed, array $context ) {
	if ( true !== $allowed || ! \PcPro\WaitingRoom::active_for( $context['occurrence_ids'] ) ) { return $allowed; }
	if ( \PcPro\WaitingRoom::admitted( $context['request'] ) ) { return true; }
	return new WP_Error( 'pcpro_waiting_room', __( 'Du bist in der Warteschlange – bitte gleich erneut versuchen.', 'pc-pro' ), array( 'status' => 429, 'retry_after' => 30 ) );
}, 10, 2 );
```

Gefragt wird bei: nativem Checkout (REST `POST /checkout` und jedem öffentlichen `Orders::create()`), Kunden-
Servicebuchungen (`POST /service-slots/{id}/bookings`, Buchungen mit Mitgliedspass), WooCommerce „In den Warenkorb“
(klassisch und Store API), Absenden des klassischen Woo-Checkouts und Store-API-Checkout (vor der Reservierung).
**Nicht** gefragt bei Kasse/Box Office, Admin-Aktionen, Wartelisten-Angeboten (das Kontingent ist schon reserviert),
Gutschein-Umwandlung, WooCommerce-Bestellverarbeitung nach dem Checkout (Zahlung, Statuswechsel, im Admin angelegte
Bestellungen) und Wiederholungen bereits angelegter Bestellungen (gleicher Idempotency-Key). Der Fehler erreicht den
Käufer mit Status (Standard 503) und Meldung; `retry_after` wird bei REST-Antworten zum Header `Retry-After`.
Exceptions des Callbacks lassen den Kauf durch (fail open) – ein ausgefallenes Add-on blockiert keinen Verkauf.
Kontext-Schlüssel siehe [HOOKS.md](HOOKS.md).

### Eigene API-Scopes und Prüfung von Entwickler-API-Keys

```php
add_filter( 'wp_pass_captain_api_scopes_v1', static function ( array $scopes ) {
	$scopes['orders.write'] = __( 'Bestellungen anlegen (orders.write)', 'pc-pro' );
	return $scopes;
} );

add_action( 'rest_api_init', static function () {
	register_rest_route( 'pc-pro/v1', '/events/(?P<event_id>\d+)/orders', array(
		'methods'             => 'POST',
		'callback'            => array( \PcPro\Api::class, 'create_order' ),
		// Pflicht: eigene permission_callback; prüft Key, Scope, Event, Ablauf/Widerruf und Ratenlimit wie die Kernrouten.
		'permission_callback' => static fn( WP_REST_Request $r ) => PassCaptain\Api\Auth::request_can( $r, 'orders.write' ),
	) );
} );
```

`request_can( $request, $scope, $event_id = null )` liefert `true` oder einen `WP_Error`: 400 ohne Event
(`$event_id` oder Parameter `event_id`), 401 fehlender/ungültiger/widerrufener/abgelaufener Key, 403 anderes Event,
fehlender oder unbekannter Scope bzw. kein Zugriff der Key-Inhaberin auf das Event, 429 Ratenlimit (je Key und
Minute gemeinsam mit den Kernrouten; `retry_after` und Header `Retry-After`). Danach liefert
`Auth::key_for( $request )` den Key (`api_key_id`, `user_id` der Inhaberin, `event_id`, `scopes`) – z. B. für
eigene Rechteprüfungen. Neue Scopes erscheinen auf der Seite „Entwickler-API-Keys“; entfernt das Add-on einen Scope,
gelten Keys dafür nicht mehr.

Ein Key handelt als seine Inhaberin und gilt nur, solange sie das Recht noch hat: Administratoren immer; sonst muss
die Rolle die Capability tragen **und** die Person für genau diese Capability dem Event zugewiesen sein. Lese-Scopes
(`*.read`) akzeptieren `wpcap_manage_events` oder `wpcap_view_reports`, alle anderen Scopes (z. B. `orders.write`)
nur `wpcap_manage_events`. Wird die Person gelöscht oder von der Site entfernt, widerruft der Kern ihre Keys und
entfernt ihre Event-/Terminzuweisungen; bei einem Rollenwechsel werden die Keys widerrufen, die die neue Rolle
nicht mehr nutzen darf (Audit `developer_key_revoked` mit `reason`).

### Kartenterminals an der Kasse (Box Office)

Der Kern enthält **keinen** Terminal-Anbieter (Stripe Terminal, SumUp, …); ein Add-on registriert Geräte über einen
versionierten Vertrag (Version 1). Ohne registriertes Gerät ist die Kasse unverändert.

```php
final class PcPro_Terminal implements PassCaptain\BoxOffice\DeviceGatewayInterface {
	public function label(): string { return 'Terminal Eingang'; }
	// Startet die Zahlung; idempotent je $sale['sale_key'] (64 hex). Fehler nur, wenn nichts gestartet wurde.
	public function start( array $sale ) { return array( 'reference' => 'pi_123', 'status' => 'pending' ); }
	// pending|succeeded|failed|cancelled, optional provider_reference und message.
	public function poll( string $reference ) { return array( 'status' => 'succeeded', 'provider_reference' => 'ch_456' ); }
	// true oder WP_Error (z. B. Karte schon vorgelegt – der Verkauf bleibt dann offen).
	public function cancel( string $reference ) { return true; }
}
add_filter( 'wp_pass_captain_pos_payment_devices_v1', static function ( array $devices, array $context ) {
	$devices['pcpro_entrance'] = new PcPro_Terminal(); // oder array( 'label', 'start', 'poll', 'cancel', 'registers' => array( '23|BOX-01' ) )
	return $devices;
}, 10, 2 );

// Webhook des Anbieters (Signatur vorher selbst prüfen):
$result = PassCaptain\BoxOffice\Service::confirm_device_payment( $sale_key_or_order_id, 'ch_456' );
// gleichwertig: wpcap_confirm_pos_payment( $order_id, 'ch_456' ); → true|WP_Error
```

| Schritt | Verhalten |
|---|---|
| Angebot | Zahlart `card_device` („Karte am Terminal (automatisch)“) mit Geräteauswahl nur, wenn für die Sitzung ein Gerät registriert ist; nur für vom Kassenregister erlaubte Register, optional je Gerät auf `registers` beschränkt. |
| Start | Der Verkauf wird wie jeder Kassenverkauf als `pending` mit Bestellung und 15-Minuten-Reservierung angelegt (idempotent je Formularschlüssel), dann genau einmal `start()` aufgerufen; erneutes Absenden startet keine zweite Zahlung. Action `…_started_v1`. Antwortet `start()` sofort `succeeded`, wird direkt gebucht. |
| Warten | Die Kasse zeigt „Am Terminal bezahlen …“ (Live-Region, Abbrechen-Knopf) und fragt alle 2 s `admin-ajax.php?action=wpcap_pos_device_status` ab (Nonce je Verkauf, Recht `wpcap_manage_orders`, nur Kassierer des Verkaufs oder Administrator). Ohne JavaScript: „Status aktualisieren“ und Abbrechen per admin-post. „Nachbuchen“ und Kassenabschluss sind gesperrt. |
| Erfolg | `poll()` liefert `succeeded` oder das Add-on ruft `confirm_device_payment()`: genau ein Aufruf gewinnt den Übergang, die Bestellung wird mit `pos:card_device:<Referenz>` bestätigt (Referenz an der Bestellung) und über denselben Weg wie Bar/Karte gebucht. Action `…_completed_v1`. |
| Fiskalisierung | Fiskalbelege entstehen erst nach der Zahlung (Zahlart `card`); Tickets und Bonnummer erst nach bestätigtem Fiskalbeleg („Verkauf stoppen“). Bis dahin meldet die Kasse „bezahlt, noch nicht gebucht“ und jede Abfrage bzw. „Nachbuchen“ versucht die Buchung erneut; die Sitzung lässt sich nicht abschließen. |
| Fehler, Abbruch, Zeitüberschreitung | `failed`/`cancelled` vom Gerät, Abbrechen durch den Kassierer (`cancel()`) oder keine Antwort nach `wp_pass_captain_pos_device_timeout_v1` (Standard 180 s, 30–840 s; das Gerät wird dann abgebrochen): Verkauf `failed`, Bestellung storniert, Kontingent frei, der Kassierer erfasst den Verkauf neu (gleiche oder andere Zahlart). Action `…_failed_v1`. Fehler/Exceptions von `poll()` gelten als „noch offen“. |
| Doppelte und späte Bestätigungen | Wiederholte Bestätigung mit bekannter Referenz (Start- oder Anbieterreferenz): `true`, keine zweite Buchung. Andere Referenz nach bestätigter Zahlung: `WP_Error` 409 `wpcap_pos_device_conflict` (zweite Zahlung am Terminal erstatten). Bestätigung nach Fehler/Abbruch/Zeitüberschreitung: 409 `wpcap_pos_device_not_pending`, nie gebucht (Zahlung am Terminal erstatten). Unbekannter Verkauf 404, leere Referenz 400. |
| Storno | Wie bei Karte über den Zahlungsadapter `pos_card_device` (bleibt registriert, auch ohne Add-on); das Geld wird am Terminal gutgeschrieben. |

Audit-Log: `pos_device_payment_started`, `…_confirmed` (mit Quelle `poll`, `webhook`, `start`), `…_failed`,
`…_conflict`, `…_late`. `confirm_device_payment()` prüft keine Benutzerrechte (Webhook-Kontext) – das Add-on muss
die Herkunft (Signatur) prüfen, bevor es bestätigt.

## WP Affiliate Captain

`src/Integrations/AffiliateCaptain/Adapter.php` verbindet Pass Captain mit WP Affiliate Captain über dessen öffentlichen Vertrag v1 (`wpac_record_sale()`, `wpac_record_refund()`, `wpac_resolve_partner_code()`, `wpac_current_touch()`). Kein Zugriff auf Tabellen von Affiliate Captain; ohne Affiliate Captain oder bei anderer Vertrags-Hauptversion ist der Adapter inaktiv.

| Pass Captain | Affiliate Captain |
|---|---|
| `wpcap_order_creating` | Partnercode prüfen, Referral (Code, Link/Cookie, Kanal) im `price_snapshot` speichern |
| `wpcap_order_paid` | Verkauf `source = pass-captain`, eine Position je Bestellzeile, SKU `PASS-E{Event}-T{Ticketart}` (Regeln z. B. `PASS-E12-*`), Basis = bezahlter Anteil ohne USt, `hold_until` = Terminende |
| `wpcap_refund_completed` | anteilige Umkehr für die erstatteten Tickets (Nettobetrag der Erstattung) |
| `wpcap_order_refunded` / `wpcap_order_cancelled` | Umkehr des Rests (nur wenn ein Verkauf gemeldet wurde) |
| Bestellungen über WooCommerce | werden nicht gemeldet – Affiliate Captain erfasst die Woo-Bestellung selbst |

Ergebnisse stehen im Audit-Log (`affiliate_sale`, `affiliate_refund`, `…_queued`, `…_failed`). Schlägt das Melden eines Verkaufs fehl (Fehler oder Exception von Affiliate Captain), plant der Adapter genau einen Job `affiliate_sale_retry` je Bestellung (Idempotenzschlüssel = Bestell-ID); Zahlung und Tickets bleiben unberührt. Der Job meldet den Verkauf erneut (doppelte Meldungen verhindern der gespeicherte `affiliate_lines`-Schnappschuss und die Idempotenz von Affiliate Captain) und ist erledigt, sobald gemeldet, bereits gemeldet oder nicht mehr meldbar; solange Affiliate Captain inaktiv ist oder weiter scheitert, wiederholt die Job-Queue mit Backoff und legt ihn nach 8 Versuchen als Dead-Letter auf die Systemstatus-Seite. Filter siehe `docs/HOOKS.md`.
