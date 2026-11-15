# Interne Entwickler-Hooks

Alle `do_action()`- und `apply_filters()`-Aufrufe in `src/` und `templates/` (Stand 2026-11-14), mit Argumenten.
Welche davon ein Add-on als Andockpunkt nutzt (Admin-Navigation, Einstellungen, Zahlungs-, SMS-, Wallet- und
Scanner-Adapter, Connectoren), beschreibt [EXTENSIONS.md](EXTENSIONS.md).
Hooks mit Suffix `_v1` sind versionierte Verträge für Drittanbieter; die übrigen sind stabile interne
Erweiterungspunkte. Externe Systeme sollten statt PHP-Hooks die signierten
[ausgehenden Webhooks](WEBHOOKS.md) verwenden.

Neue Hooks bitte hier ergänzen. Prüfen lässt sich die Liste mit
`grep -rnE "(do_action|apply_filters)\(" src --include=*.php`. Für die versionierten `_v1`-Hooks prüft der
Vertragstest `tests/integration/modules/hooks_contract.php`, dass jeder hier dokumentierte Hook im Code
vorkommt und umgekehrt; für alle Hooks mit Präfix `wpcap_`/`wp_pass_captain_` (auch die nicht versionierten)
prüft er zusätzlich per Tokenizer, dass sie hier stehen und dass die Zahl der Argumente in der Spalte
„Argumente“ zu den Aufrufstellen passt (keine Aufrufstelle übergibt mehr; die längste genau so viele). Öffentliche
Hooks mit Präfix `wp_pass_captain_` tragen immer das Suffix `_v1` (außer den veralteten Namen unten).

## Actions

| Hook | Argumente | Ort / Zeitpunkt |
|---|---|---|
| `wpcap_order_creating` | `int $order_id, array $selected, array $payload, array $internal` | `Orders::create()` innerhalb der Bestell-Transaktion (Ticketarten/Termine gesperrt). `\RuntimeException('code')` bricht ab; Meldung über `wpcap_checkout_error_messages`. `$selected`: Zeilen mit `type`, `event_id`, `quantity`, `line_total`, `discount_minor` (Kampagnenrabatt der Zeile), `tax_rate_bp`, `tax_minor`; `$payload`: öffentliche Checkout-Felder (siehe `wpcap_checkout_payload_keys`); `$internal`: vertrauenswürdige Adapter-Optionen (`payment_provider`, `external`, `fiscal_location_id`, `fiscal_register_id`, `fiscal_payment_method`, `fiscal_extra_items`, …). |
| `wpcap_capacity_released` | `int $order_id, string $reason, array $details` | Kontingent einer Bestellung zählt nicht mehr: `expired` (`expire_holds`), `cancelled` (`cancel_order`), `refunded` (`mark_refunded`), `tickets_revoked` (`revoke_tickets`, `$details`: `ticket_type_id`, `quantity`, `reason`), `event_cancelled`, `payment_unallocated` (verspätete Zahlung ohne Kontingent). Kern-Kontingente und Serien-Pools werden aus Bestellungen/Reservierungen abgeleitet und brauchen den Hook nicht; er ist für Module mit eigenen Zählern oder Caches. |
| `wpcap_mail_before_send` | `array $prepared, array $message` | Unmittelbar vor `wp_mail()` jeder Kunden-Mail des zentralen Mailers; `$prepared`: `to`, `subject`, `body` (inkl. Layout), `headers`, `attachments`, `alt_body` (Textversion bei HTML-Mails, sonst `null`). |
| `wpcap_order_paid` | `int $order_id` | Nach Zahlungsbestätigung bzw. bei kostenlosen Bestellungen nach Ausgabe der Tickets. |
| `wpcap_order_cancelled` | `int $order_id, string $reason, string $previous_payment_status` | `Orders::cancel_order()` (alle drei Argumente; `$previous_payment_status` = Zahlungsstatus vor der Stornierung), beim Ablauf von Reservierungen (`expire_holds`, nur `$order_id`) und für offene Bestellungen bei der Event-Absage (`cancel_event`). Das Fiskalisierungsmodul legt damit Korrekturaufgaben (`void` / `refund_or_correction`) an bestätigten Belegen an. |
| `wpcap_event_cancelled` | `int $event_id` | `Orders::cancel_event()` nach Widerruf, Benachrichtigung und Audit-Eintrag. Fiskalisierung: Korrekturaufgabe je bestätigtem Beleg mit Tickets des Events. |
| `wpcap_process_fiscal_order` | `int $order_id` | Cron-Ereignis (`wp_schedule_single_event`) für die automatische Wiederholung offener Fiskalbelege mit wachsendem Abstand; ruft `Orders::issue_and_email()` (Fiskal-Prüfung vor der Ausgabe). Beim Deinstallieren abgemeldet. |
| `wpcap_fiscal_terminal_failure` | `array $details` (`document_id`, `order_id`, `seller_id`, `provider_id`, `error_code`, `permanent`) | Ein Fiskalbeleg ist endgültig fehlgeschlagen (dauerhafter Fehler oder 8 Versuche); Tickets bleiben gesperrt, Admin-Mail und Log-Eintrag `fiscal_document_terminal_failure`. |
| `wpcap_woocommerce_non_ticket_refund` | `int[] $order_ids, int $refund_id, string $currency, array $lines` | WooCommerce-Erstattung von Nicht-Ticket-Positionen (Waren, Gebühren, Versand); `$order_ids` = alle Pass-Captain-Bestellungen der WooCommerce-Bestellung (bei Aufteilung je Rechnungssteller), `$lines` = `line_key`, `quantity`, `amount_minor`, `tax_minor` (leerer `line_key` = reine Betragserstattung). |
| `wp_pass_captain_voucher_tickets_revoked_v1` | `array $info` (`redemption_key`, `order_public_id`, `remaining`, `revoked`, `reason`) | Tickets einer Gutschein-Umwandlung wurden in Pass Captain storniert oder erstattet (Bestellung storniert/erstattet, Termin/Event abgesagt) – nicht bei `revoke_tickets()` durch das Gutscheinsystem selbst. Feuert bei jeder Änderung; `remaining` zählt noch gültige oder bereits eingecheckte Tickets. Der Gutscheinanbieter kann den Gutschein damit zurückgeben (idempotent behandeln). |
| `wpcap_ticket_created` | `int $ticket_id, object $order, object $line, int $index` | Innerhalb der Ausgabe-Transaktion, je Ticket (z. B. Sitzplatz zuweisen). Klartext-Code nicht speichern. |
| `wpcap_tickets_issued` | `int $order_id, int $count` | Nach dem Commit der Ticketausgabe, vor dem Versand der Ticket-E-Mail. |
| `wpcap_ticket_revoked` | `int $ticket_id, string $reason` | Nach dem Widerruf: Admin (`TicketsPage`) und über `Orders::fire_revoked()` (Erstattung `refund_{id}`, Stornierung `order_cancelled`, Terminabsage `occurrence_cancelled`, Event-Absage `event_cancelled`, Vollerstattung). |
| `wpcap_ticket_code_rotated` | `int $ticket_id, string $code, string $reason` | Neuer Ticketcode (`transfer`, `resend`, `assign`). `$code` ist der Klartext-Code – nur flüchtig verwenden, nie speichern oder loggen. |
| `wpcap_ticket_checked_in` | `int $ticket_id, array $context` | Nach erfolgreichem Einlass (`Checkin`). `$context`: u. a. `source`, `device_id`. |
| `wpcap_ticket_checked_out` | `int $ticket_id, array $context` | Nach einem Auslass (Mehrfacheinlass/Bereiche). |
| `wpcap_refund_completed` | `int $refund_id, int $order_id` | Erstattung über das Plugin erfolgreich ausgeführt (auch Teilerstattung). |
| `wpcap_refund_rejected` | `int $refund_id, int $order_id` | Eine wartende Erstattung wurde bei der Freigabe abgelehnt (die Kasse gibt damit gesperrte Bons frei). |
| `wpcap_order_refunded` | `int $order_id, string $reason` | `Orders::mark_refunded()`: Bestellung vollständig erstattet (eigene Erstattung, WooCommerce oder Anbieter-Dashboard), nach dem Widerruf der Tickets. |
| `wpcap_occurrence_rescheduled` | `int $occurrence_id, string $old_starts_at, string $new_starts_at` | Admin: Startzeit eines Termins mit ausgegebenen Tickets geändert (UTC `Y-m-d H:i:s`). |
| `wpcap_occurrence_cancelled` | `int $occurrence_id, int $issued` | Admin: Termin abgesagt (`$issued` = Anzahl ausgegebener Tickets). Kern-Folgen in `OccurrenceCancellation` (Priorität 10); das SMS-Modul hängt sich mit Priorität 5 davor. |
| `wpcap_occurrence_cancelled_woocommerce_order` | `int $order_id, int $occurrence_id` | Je bezahlter WooCommerce-Bestellung eines abgesagten Termins (Erstattung erfolgt in WooCommerce). |
| `wp_pass_captain_connector_error_v1` | `string $connector_key, string $event_name, string $message` | Ein Connector hat bei einem Ereignis eine Exception geworfen oder `false`/`WP_Error` geliefert (die Zustellung wird über die Job-Queue wiederholt, siehe [EXTENSIONS.md](EXTENSIONS.md#connectoren)). |
| `wpcap_log` | `string $level, string $event, string $message, array $context` | Jeder Eintrag des zentralen Loggers `PassCaptain\Log` (`error`/`warning`, z. B. `refund_db_error`, `order_refund_state_pending`, `queue_status_update_failed`, `queue_handler_exception`, `ticket_issue_failed`, `order_state_db_error`, `webhook_db_error`, `refund_failed`, `voucher_commit_failed`, `woocommerce_refund_sync_failed`, `ticket_rotation_conflict`, `schema_upgrade_failed`). Nachricht und `$context` sind redigiert (`Security\Redact`). Der Eintrag steht zusätzlich im Audit-Log (Aktion = `$event`); scheitert dieser Insert, landet er redigiert im PHP-Fehlerprotokoll. |
| `wpcap_admin_event_sections` | `object $event, bool $editable` | Admin „Events“ → Event bearbeiten, nach den Terminen: weitere Abschnitte (Steuerland, Preisregeln, Käuferlimits aus `Sales\AdminSections`). Callbacks prüfen ihre Rechte selbst. |
| `wpcap_consent_withdrawn` | `int $consent_id, object $consent` | Eine protokollierte Einwilligung eines Teilnehmerfelds wurde widerrufen (`Sales\Consents::withdraw()`, Quelle z. B. `portal`) oder ist durch einen Inhaberwechsel des Tickets beendet (`withdrawn_source` `holder_changed`, bei Zuweisung an eine andere Person oder Übertragung); Datensatz bleibt mit Zeitpunkt erhalten, die Antwort ist gelöscht. |
| `wpcap_checkout_form_fields` | `object $event, array $types` | Template `templates/event-card.php` innerhalb des Checkout-Formulars (eigene Felder ausgeben; JS-Ereignis `wpcap:checkout-payload` ergänzt den Payload). |
| `wp_pass_captain_admin_menu_built_v1` | `string $parent_slug` (`wp-pass-captain`) | Ende von `Admin\Menu::add_menus()` (Hook `admin_menu`), nachdem alle Kernseiten registriert sind: Add-ons hängen eigene Unterseiten mit `add_submenu_page( $parent_slug, … )` an – unabhängig von Modulen und der Reihenfolge der `admin_menu`-Callbacks (die Seiten stehen nach den Kernseiten). |
| `wp_pass_captain_admin_occurrence_sections_v1` | `object $occurrence`, `bool $editable` (false bei abgesagtem Event) | Weitere Abschnitte der Terminseite (Events → Termin) nach den Ticketarten, z. B. „Tickets ausstellen“ und „Termin duplizieren“ (`Admin\OrganizerTools`). Callbacks prüfen ihre Rechte selbst. |
| `wp_pass_captain_invoice_created_v1` | `int $id, string $type, array $context` | Nach dem Commit eines Belegs, genau einmal je Beleg: `invoice` (`Invoices::issue_for_order()`, `$id` = invoice_id, Kontext `order_id`, `number`), `correction` (Rechnungskorrektur zu einer Erstattung, `$id` = credit_note_id, Kontext `invoice_id`, `refund_id`, `order_id`, `number`), `settlement` (Veranstalter-Abrechnung abgeschlossen, `$id` = settlement_id, Kontext `partner_id`, `period`, `number`, `self_billing_number`). Daten über `PassCaptain\Api\Invoices::get( $id, $type )`. Exceptions der Callbacks werden protokolliert (`invoice_created_hook_failed`) und brechen den Kern nicht ab. |
| `wp_pass_captain_pos_device_payment_started_v1` | `int $sale_id, string $device_id, string $reference, array $sale` | Kasse: Eine Kartenterminal-Zahlung wurde am Gerät gestartet (nach `DeviceGatewayInterface::start()`, genau einmal je Verkauf). `$sale` = Start-Daten (`version`, `sale_key`, `sale_id`, `order_id`, `order_public_id`, `amount_minor`, `currency`, `description`, `device_id`, `session_id`, `location_id`, `register_id`, `cashier_id`). Siehe [EXTENSIONS.md](EXTENSIONS.md#kartenterminals-an-der-kasse-box-office). |
| `wp_pass_captain_pos_device_payment_completed_v1` | `int $sale_id, int $order_id, string $provider_reference, string $device_id` | Kasse: Die Terminalzahlung ist bestätigt (Abfrage oder `Service::confirm_device_payment()`), genau einmal je Verkauf – auch wenn die Buchung danach noch auf den Fiskalbeleg wartet. |
| `wp_pass_captain_pos_device_payment_failed_v1` | `int $sale_id, string $status, string $message, string $device_id` | Kasse: Die Terminalzahlung endete ohne Zahlung; `$status` `failed` (Gerät/Start), `cancelled` (Kassierer), `timeout` oder `amount_mismatch` (das Gerät meldet einen anderen belasteten Betrag bzw. eine andere Währung als die Bestellung, Vertrag 1.1 – nicht gebucht, am Terminal erstatten). Der Verkauf ist `failed`, die Bestellung storniert (Kontingent frei). |

Cron-Actions, an die sich Module hängen: `wpcap_process_messages` (alle 5 Minuten, Nachrichten-Queue;
Erinnerungen planen sich mit Priorität 1 ein, Bereinigung mit 5, SMS-Versand mit 20),
`wpcap_process_jobs` (alle 5 Minuten; Webhook-Zustellung mit Priorität 20),
`wpcap_cleanup_reservations` (stündlich) und `wpcap_webhooks_dispatch` (einmalig nach neuen
Webhook-Ereignissen).

### Cron-Ereignisse

WP-Cron-Hooks des Plugins (`Plugin::CRON_HOOKS`; Deaktivierung und Deinstallation melden alle ab). Eigene Callbacks
können sich anhängen, z. B. für Überwachung; die Verarbeitung selbst steckt in den Kern-Callbacks.

| Hook | Argumente | Ort / Zeitpunkt |
|---|---|---|
| `wpcap_cleanup_reservations` | – | Stündlich: abgelaufene Reservierungen freigeben (`Orders::expire_holds()`). |
| `wpcap_process_messages` | – | Alle 5 Minuten: Nachrichtenwarteschlange (E-Mails, Erinnerungen). |
| `wpcap_process_jobs` | – | Alle 5 Minuten: Hintergrundjobs (`Jobs`), Ticketausgabe-Wiederholungen. |
| `wpcap_webhooks_dispatch` | – | Ausgehende Webhooks zustellen (`Webhooks\Dispatcher`). |
| `wpcap_privacy_retention` | – | Täglicher Löschlauf (`Privacy\Retention`). |
| `wpcap_privacy_purge_wallet_registrations` | `int[] $ticket_ids` | Einmalig eine Stunde nach dem Löschen bzw. Anonymisieren von Tickets: Wallet-Geräteregistrierungen dieser Tickets entfernen (Apple-Geräte bekommen vorher noch den Push für den widerrufenen Pass). |
| `wpcap_run_upgrade` | – | Einmaliges Ereignis für ein aufgeschobenes Schema-Upgrade (Frontend-Anfrage nach Update, verzögerte Netzwerkaktivierung, `Installer::UPGRADE_HOOK`). |

### JavaScript-Ereignisse (Frontend)

| Ereignis | Detail | Wann |
|---|---|---|
| `wpcap:track` (auf `document`) | `{ event, currency, value, items[{item_id, item_name, price, quantity}], transaction_id? }` | Datensparsame Commerce-Ereignisse `view_item`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase` (assets/tracking.js, ohne personenbezogene Daten). Siehe [TRACKING.md](TRACKING.md). |
| `wpcap:checkout-payload`, `wpcap:checkout-complete` | siehe assets/checkout.js | Vor dem Absenden (Module ergänzen Felder) bzw. nach erfolgreicher Bestellung ohne Weiterleitung. |

## Filter

### Checkout und Bestellungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_checkout_payload_keys` | `string[] $keys` | Zusätzliche öffentliche Checkout-Felder durchlassen (z. B. `sms_phone`, `sms_consent`, `mollie_method`). |
| `wpcap_checkout_error_messages` | `array $messages` (Code → Text) | Meldungen zu Abbruch-Codes aus `wpcap_order_creating`. |
| `wpcap_affiliate_partner_field` | `bool $show` (true) | Partnercode-Feld im Checkout (nur mit WP Affiliate Captain). |
| `wpcap_affiliate_tracking_allowed` | `bool $allowed`, `string $method` | Ob die Empfehlung aus `wpac_current_touch()` verwendet werden darf. Cookie (`cookie`): mit WP Consent API `wp_has_consent( 'marketing' )`, ohne Consent-API `false` (keine nachweisbare Einwilligung; Freigabe über diesen Filter, wenn der Cookie-Banner den Cookie blockiert). Partnerlink der Shop-Sitzung (`link`, `app`, kein Marketing-Cookie): standardmäßig `true`. Ein eingegebener Partnercode ist immer erlaubt. |
| `wpcap_affiliate_hold_until` | `int $timestamp, object $order` | Sperrfrist der Provision (Standard: Ende des letzten gebuchten Termins). |
| `wpcap_affiliate_net_minor` | `int $net, object $line, object $order` | Provisionsbasis je Bestellzeile. |
| `wpcap_affiliate_sale_payload` | `array $payload, object $order` | Verkaufsdaten an WP Affiliate Captain. |
| `wpcap_affiliate_skip_channels` | `string[] $channels` (`woocommerce`) | Kanäle/Zahlungsanbieter, die nicht gemeldet werden. |
| `wpcap_checkout_ip_limit` | `int $limit` (30) | Angelegte Bestellungen je Client-Netz (IPv4-Adresse bzw. IPv6-/64) und Stunde (`Sales\CheckoutThrottle`). Gezählt werden nur tatsächlich angelegte Bestellungen, nicht Versuche, die z. B. an „ausverkauft“ scheitern. Zusätzlich gelten 8 Bestellungen je Netz und normalisierter E-Mail und Stunde. |
| `wpcap_checkout_attempt_factor` | `int $factor` (4) | Checkout-Versuche (auch gescheiterte) je Netz bzw. Netz + E-Mail und Stunde als Vielfaches der Bestell-Limits; atomar gezählt, Obergrenze auch für parallele Anfragen. |
| `wpcap_checkout_unpaid_per_network` | `int $limit` (10) | Unbezahlte Bestellungen (Reservierungen), die ein Client-Netz innerhalb einer Haltezeit (höchstens einer Stunde) anlegen darf. |
| `wpcap_checkout_proxy_suspect` | `string $kind, array $server` | Erkennung „hinter Proxy/CDN ohne eingetragene vertrauenswürdige Proxys“: `private` (private/Loopback-`REMOTE_ADDR`; die Limits je Netz entfallen, es gilt nur Netz + E-Mail), `header` (Weiterleitungs-Header von öffentlicher Adresse; Limits je Netz werden mit `wpcap_checkout_proxy_limit_factor` angehoben) oder `''`. Systemstatus warnt in beiden Fällen. |
| `wpcap_checkout_proxy_limit_factor` | `int $factor` (10) | Anhebung der Checkout-Limits je Netz, wenn Anfragen einen Weiterleitungs-Header ohne konfigurierte vertrauenswürdige Proxys tragen. |
| `wp_pass_captain_waitlist_ip_limit_v1` | `int $limit` (60) | Event-Wartelisten-Anmeldungen je Client-IP und Stunde über alle Events (zusätzlich zu 5 je Adresse und Event sowie 20 je IP und Event); mindestens 1. |
| `wpcap_client_ip` | `string $ip, array $server` | Client-IP für Ratenbegrenzungen (Standard `REMOTE_ADDR`, bzw. Header eines vertrauenswürdigen Proxys, siehe `wpcap_trusted_proxies`). Ungültige Rückgaben fallen auf die erkannte Adresse zurück. |
| `wpcap_trusted_proxies` | `string[] $proxies` | Vertrauenswürdige Reverse Proxies/CDN (IP oder CIDR); nur von ihnen wird der eingestellte Client-IP-Header ausgewertet (docs/SECURITY.md). |
| `wpcap_secret_settings` | `string[] $keys` | Einstellungen, die verschlüsselt gespeichert und nur über `Settings::secret()` gelesen werden. |
| `wpcap_outbound_resolve` | `null $ips, string $host` | Liefert die Adressen eines Hosts für die SSRF-Prüfung (Tests/eigene Resolver); `null` = DNS. |
| `wpcap_fiscal_allow_local_service` | `bool $allow, string $url` | Erlaubt HTTP zu lokalen Fiskaldiensten (Loopback/privat); Standard: Einstellung `fiscal_allow_local_service`. |
| `wpcap_order_fiscal_ready` | `bool $ready, int $order_id` | `Orders::issue_and_email()` vor der Ausgabe: `false` hält die Tickets zurück (Fiskalisierungsmodul, bis jeder Beleg der Bestellung bestätigt ist); die Ausgabe gilt dann als aufgeschoben, nicht als Fehler. |
| `wpcap_fiscal_adapters` | `array $adapters` | Fiskaladapter (`Fiscalization\AdapterInterface`, Vertragsversion 2); eingebaute Adapter (fiskaly, efsta, fiskaltrust) mit Priorität 5. |
| `wpcap_fiscal_credentials` | `array $credentials, int $seller_id, string $provider_id` | Zugangsdaten aus einer vertrauenswürdigen Integration zur Laufzeit (überschreibt gleichnamige Werte aus dem Tresor); nie loggen. |
| `wpcap_fiscal_profile_scope` | `array $scope, string $channel, array $internal, int $order_id` | Standort/Kasse (`location_id`, `register_id`) für die Profilauswahl; Standard: Kassensitzung bzw. 0/leer. Kundendaten werden nie verwendet. |
| `wpcap_woocommerce_fiscal_item_seller` | `array $assignment, WC_Order_Item $item, string $kind, ?WC_Product $product, WC_Order $order` | Rechtsträger (`seller_id`, `seller_mode`) einer Nicht-Ticket-Position (`product`, `fee`, `shipping`) für Fiskalbelege. |
| `wpcap_wallet_google_token_uris` | `string[] $uris` | Erlaubte OAuth-Token-Endpunkte für das Google-Wallet-Servicekonto. |
| `wpcap_error_log` | `bool $send, string $line` | `false` unterdrückt die (bereits redigierte) Error-Log-Zeile. |
| `wpcap_pre_send_download` | `null $pre, string\|resource $body, string $filename, string $type` | Nicht-`null` übernimmt die Auslieferung eines Admin-Downloads (CSV/PDF). Gestreamte Exporte (Audit-Log, Fiskal-Archiv) übergeben statt des Strings einen zurückgespulten Stream. |
| `wp_pass_captain_delayed_payment_hold_minutes_v1` | `int $minutes` (10080 = 7 Tage), `object $order`, `string $provider` | Wie lange die Reservierung einer Bestellung gehalten wird, sobald eine verzögerte Zahlart gestartet wurde (Stripe-Session abgeschlossen mit `payment_status=unpaid`, z. B. SEPA-Lastschrift; Mollie-Überweisung/Lastschrift `open`/`pending`, auch das Fälligkeitsdatum `dueDate` der Mollie-Überweisung; PayPal-Capture `PENDING`; Mollie-Autorisierung bis zum Capture). – höchstens bis zum Beginn des frühesten Termins der Bestellung. Eine bereits abgelaufene Reservierung wird nicht wiederbelebt; die spätere Zahlung läuft dann über die Nachprüfung verspäteter Zahlungen (Verkaufszustand: Ticketart aktiv, Termin nicht abgesagt und nicht vorbei; Kontingent, Sitzplätze, Käuferlimit, Rabattcode). |
| `wp_pass_captain_lock_wait_timeout_v1` | `int $seconds` (5) | Sekunden, die ein Checkout (`Orders::create()`) bzw. eine Zahlungsbestätigung (`Orders::confirm_external_payment()`) auf eine Zeilensperre wartet (`innodb_lock_wait_timeout` der Sitzung, danach wird der vorherige Wert wiederhergestellt). Deadlocks und Lock-Wait-Timeouts wiederholt der Checkout intern bis zu zweimal, danach antwortet er mit 503 `retryable` und `Retry-After`. `0` lässt die Servereinstellung unverändert. |
| `wp_pass_captain_refund_failed_v1` | `int $refund_id`, `int $order_id` | Action: Eine bereits verbuchte Erstattung ist beim Zahlungsanbieter nachträglich fehlgeschlagen (Stripe `refund.failed`/`charge.refund.updated`, Mollie-Erstattung `failed`). Die Erstattung steht auf `failed`, der erstattete Betrag der Bestellung ist zurückgenommen, die Rechnungskorrektur storniert (`Refunds::provider_failed()`); z. B. für Abrechnungs- oder Fiskal-Add-ons. |
| `wpcap_max_line_quantity` | `int $cap` (100) | Absolute Obergrenze je Bestellzeile vor jeder Sperre (die eigentliche Grenze ist „Max. pro Bestellung“ der Ticketart). |
| `wpcap_checkout_error_field` | `string $field, WP_Error $error` | Feld, dem eine Checkout-Fehlermeldung zugeordnet wird (barrierefreie Fehler am Feld; leer = allgemeine Meldung). |
| `wpcap_checkout_billing_enabled` | `bool $enabled` (true), `object $event` | Ob die Eventkarte den Abschnitt „Rechnungsangaben“ zeigt (`templates/event-card.php`). |
| `wpcap_billing_countries` | `array $countries` (Code → Name) | Wählbare Länder der Rechnungsangaben. |
| `wpcap_billing_default_country` | `string $code` | Vorausgewähltes Land (Standard aus Shop-/Website-Sprache). |
| `wpcap_billing_required_threshold` | `int $minor, string $currency` | Bestellbetrag, ab dem Name und Anschrift Pflicht sind (Einstellung `billing_required_threshold_minor`, 0 = aus). |
| `wpcap_vat_id_patterns` | `array $patterns` (Länderpräfix → Regex ohne Begrenzer) | Formatprüfung der UID-Nummer je Land. |
| `wpcap_vat_id_validate` | `true|WP_Error|false $result, string $vat_id, array $billing` | Zusätzliche Prüfung der UID-Nummer (z. B. VIES-Abfrage durch ein Add-on). Der Kern selbst fragt keinen externen Dienst ab. |
| `wpcap_refuse_mixed_seller_orders` | `bool $refuse` (true), `int $order_id` | Bestellungen mit Tickets verschiedener Rechnungssteller (Verkaufsmodelle) im Checkout ablehnen. |
| `wpcap_max_holder_changes` | `int $max` (1), `int $ticket_id` | Wie oft der Inhaber eines Tickets per Zuweisung wechseln darf; zählt auch für die Übertragung. |
| `wpcap_refundable_tickets` | `array $rows` (`ticket_id`, `ticket_type_id`), `int $order_id` | Erstattungsfähige Tickets einer Bestellung. |
| `wpcap_send_refund_mail` | `bool $send, int $refund_id, object $order` | Erstattungs-Mail an den Käufer (Standard: nicht bei WooCommerce-Bestellungen). |
| `wpcap_portal_resend_limit` | `int $limit` (3) | „Tickets erneut senden“ im Bestellportal je Bestellung und Stunde. |
| `wpcap_expire_holds_batch` | `int $limit` (200) | Abgelaufene Reservierungen je Lauf von `expire_holds`. |
| `wpcap_admission_day_cutoff_hour` | `int $hour, array $context` (`occurrence_id`, `event_id`) | Einlass-Tageswechsel (0–12 Uhr Ortszeit) für tägliche Einlassregeln; Standard aus der Einstellung `admission_day_cutoff_hour`. |
| `wpcap_offline_refresh_interval` | `int $seconds` (10) | Mindestabstand zweier Snapshot-Aktualisierungen eines Termins für den Offline-Scanner. |
| `wpcap_offline_scanner_locale` | `string $locale` | Website-Sprache, in der der Offline-Scanner seine Texte ausliefert, wenn das Gerät keine unterstützte Sprache anfragt (Standard `determine_locale()`). |
| `wpcap_offline_scanner_locales` | `string[] $locales` | Sprachen, in denen `GET /app/offline/i18n?locale=` die Texte ausliefern kann (Standard: `de_DE`, mitgelieferte Übersetzungen in `languages/`, installierte Sprachpakete, Website-Sprache). |
| `wpcap_offline_scanner_strings` | `array $strings` (deutscher Quelltext → Übersetzung), `string $locale` | Übersetzungstabelle des Offline-Scanners. |
| `wpcap_partner_client_ip` | `string $ip` | Client-IP für Partner-API (IP-Allowlist, Ratenlimit); Proxies hier auflösen. |
| `wpcap_woocommerce_billing` | `array $billing, WC_Order $wc_order` | Aus WooCommerce übernommene Rechnungsangaben. |
| `wpcap_woocommerce_vat_meta_keys` | `string[] $keys, WC_Order $wc_order` | Meta-Felder, in denen die UID-Nummer einer Woo-Bestellung gesucht wird. |
| `wpcap_woocommerce_collect_attendees` | `bool $collect, int $type_id` | Teilnehmerfelder im Woo-Checkout je Ticketart abfragen. |
| `wpcap_woocommerce_issue_on_completed_gateways` | `string[] $gateways` (`cod`), `WC_Order $wc_order` | Zahlarten, bei denen Tickets erst bei „Abgeschlossen“ statt „In Bearbeitung“ ausgestellt werden. |
| `wpcap_woocommerce_deferred_hold_minutes` | `int $minutes` (14 Tage), `WC_Order $wc_order` | Reservierung für Woo-Bestellungen mit aufgeschobener Ausgabe (z. B. Nachnahme). |
| `wpcap_privacy_defer_upcoming` | `bool $defer` (true), `int $order_id`, `string $kind` (nur bei Pässen: `member_pass`), `int $pass_id` | Löschanfragen für Bestellungen mit gültigen Tickets für kommende Termine bzw. für gültige Mitgliedspässe (`$order_id` = 0) zurückstellen (Art. 17 Abs. 3 DSGVO) statt Tickets/Pässe sofort zu entwerten. |
| `wp_pass_captain_checkout_allowed_v1` | `true $allowed, array $context` → `true|WP_Error` | Zugangsschranke vor öffentlichen Käufen (z. B. virtueller Warteraum, `PassCaptain\Sales\CheckoutGate`): nativer Checkout (`POST /checkout` und jedes `Orders::create()` ohne Kanal-Namensraum und ohne vertrauenswürdige Adapter-Optionen, vor der Bestell-Transaktion; Wiederholungen mit bekanntem Idempotency-Key werden nicht gefragt), Kunden-Servicebuchungen (`ServiceBookings::reserve()` mit `actor` `customer`), WooCommerce (In den Warenkorb klassisch und Store API, Absenden des klassischen Checkouts, Store-API-Checkout vor der Reservierung). **Nicht** für Kasse, Admin, Wartelisten-Angebote, Gutschein-Umwandlung, WooCommerce-Bestellverarbeitung nach dem Checkout. `$context`: `version` (1), `source` (`native`, `service_booking`, `service_pass_booking`, `woocommerce`, `woocommerce_store_api`), `phase` (`checkout`, `cart`, `booking`), `items` (Ticketart-ID → Menge), `ticket_type_ids`, `occurrence_ids`, `event_ids`, `slot_id`, `email`, `idempotency_key`, `request` (`WP_REST_Request` oder `null`). Ein `WP_Error` erreicht den Käufer: Status aus `status` (Standard 503), `retry_after` (Sekunden) wird bei REST-Antworten zum Header `Retry-After`, in der Store API Teil der Fehlerdaten. Andere Rückgaben als `true` gelten als Ablehnung (503). Exceptions: fail open (protokolliert, Kauf läuft weiter). |
| `wpcap_ticket_unit_price` | `int $price_minor, object $type, array $context` | Brutto-Einzelpreis einer Ticketart, nur serverseitig (`Orders::unit_price()`). `$context`: `occurrence` (Zeile), `quantity` (Menge der Ticketart in der Bestellung, 1 zur Anzeige), `now` (UTC `Y-m-d H:i:s`), `phase` (`create`/`display`/`cart`), `source` (`woocommerce` im Woo-Warenkorb). In `Orders::create()` innerhalb der Bestell-Transaktion nach der Kontingentprüfung. **Vorrang:** vertrauenswürdige `unit_prices` aus `$internal` (WooCommerce, kostenlose Kassenverkäufe) ersetzen den Preis ohne Filter; sonst gilt gespeicherter Preis + Filter (öffentlicher Checkout, Warteliste, Kasse ohne Preisvorgabe). Kundeneingaben erreichen weder Filter noch Override. Geänderte Zeilen stehen im `price_snapshot` unter `unit_price_adjustments`. Automatische Preisregeln (Event- und Serienregeln, `Sales\PriceRules`, Priorität 10) hängen sich hier ein; die siegreiche Regel steht zusätzlich im `line_snapshot.price` der Bestellposition. |
| `wpcap_tax_profile_date` | `string $date, object $type, object $occurrence, string $order_date` | Stichtag (`Y-m-d`) für die Auswahl der Steuerprofil-Version einer Position; Standard: lokales Datum des Terminbeginns in der Termin-Zeitzone (Leistungszeitpunkt), `$order_date` = lokales Bestelldatum (docs/TAX-PROFILES.md). |
| `wpcap_buyer_limit_email` | `string $email` | Normalisierte E-Mail für Käuferlimits und Checkout-Ratenlimits (Standard: kleingeschrieben, getrimmt, Aliase zusammengefasst – siehe `wpcap_buyer_email_aliases`). Die Datenbankabfrage der Käuferlimits kennt nur die Standard-Normalisierung. |
| `wpcap_buyer_email_aliases` | `bool $fold` (true) | Ob E-Mail-Aliase zusammengefasst werden: `+tag` im lokalen Teil entfällt, bei `gmail.com`/`googlemail.com` entfallen Punkte und die Domain wird `gmail.com`. `false` = nur Kleinschreibung. |
| `wpcap_buyer_limit_lock_wait` | `int $seconds` (10) | Wartezeit auf die Käufer-Sperre (`GET_LOCK`) parallel laufender Bestellungen derselben Person; danach 503 „Gerade ist sehr viel los“. |
| `wpcap_woocommerce_use_core_prices` | `bool $enabled, WC_Product $product, int $ticket_type_id` | Ob Pass-Captain-Preise (inkl. `wpcap_ticket_unit_price`, z. B. Serien-Preisstufen) für dieses Ticketprodukt im Woo-Warenkorb gelten; nur wirksam, wenn die globale Option (WooCommerce → Einstellungen → Produkte) aktiv ist. Die Reservierung übernimmt weiterhin die Woo-Zeilensummen als vertrauenswürdigen Override. |
| `wpcap_ticket_price_label` | `string $label, object $type, array $context` | Öffentliche Bezeichnung des aktuell geltenden Preises (z. B. „Frühbucher“), `Orders::price_label()`; Event-Karte und Serien-Admin. |
| `wpcap_reserve_capacity` | `true|WP_Error $result, array $demand, array $context` | Zusätzliche gemeinsame Kontingente (z. B. Serien-Pools). Läuft in `Orders::create()` und bei verspäteter Zahlung (`confirm_external_payment`) in der Transaktion, **nachdem** Ticketarten und Termine (in dieser Reihenfolge, je nach ID) gesperrt sind und **bevor** der Kern erstmals konsistent liest. `$demand`: Ticketart-ID → Menge; `$context`: `phase` (`create`/`recover`), `order_id` (bei `recover` aus der Zählung auszunehmen, sonst 0), `types`/`occurrences` (gesperrte Zeilen nach ID), `now`, `source`. Eigene Zeilen zuerst mit sperrenden Lesezugriffen (`FOR UPDATE`/`LOCK IN SHARE MODE`, feste Reihenfolge) sperren, erst danach Belegung lesen – sonst ist der Snapshot veraltet. `WP_Error` lehnt ab (Code über `wpcap_checkout_error_messages`, sonst die Meldung des Fehlers; bei `recover` → `needs_review`). |
| `wpcap_ticket_availability` | `int|null $remaining, object $type, object $occurrence` | Restmenge in `Orders::availability()` (Anzeige, Warenkorbprüfung, Kasse). Nur verkleinern; `null` = unbegrenzt. Serien-Pools hängen sich hier ein. |
| `wpcap_order_portal_sections` | `string $html, object $order, string $access_token` | Zusätzliche Abschnitte im Bestellportal (vor den Rechnungsdaten); Rückgabe ist bereits escaptes HTML. Servicebuchungen (Umbuchen/Stornieren) hängen sich hier ein, siehe `docs/SERVICE-BOOKINGS.md`. |
| `wpcap_waitlist_offer_hours` | `int $hours` (Einstellung `waitlist_offer_hours`, Standard 24) | Annahmefrist automatischer und manueller Event-Wartelistenangebote; das Ergebnis wird auf 1–720 Stunden begrenzt. |
| `wpcap_ops_bulk_retry_cap` | `int $cap` (100) | Höchstzahl der Einträge, die „Alle erneut versuchen“ im Systemstatus je Klick und Warteschlange neu einreiht (1–500). |
| `wpcap_service_booking_ip_limit` | `int $limit` (40) | Buchungsversuche je IP. |
| `wpcap_seating_availability_limit` | `int $limit` (120) | Abrufe der Sitzplatzverfügbarkeit je Client und Minute. |
| `wpcap_checkin_now` | `int $timestamp` | Zeitpunkt für Einlassentscheidungen (Tests/Simulation). |
| `wpcap_checkout_user_roles` | `string[] $roles, int $user_id` | Rollen der angemeldeten Person für die Freigabe des Auslass-Scans (Check-out, Richtlinie „Check-out-Rollen“ `checkout_roles`); Administratoren sind immer freigegeben. |
| `wpcap_user_has_2fa` | `bool $has, int $user_id` | Zwei-Faktor-Status für Erstattungen. |
| `wpcap_voucher_purpose` | `string|null $purpose, array $voucher, array $context` | Art eines Gutscheins: `single` (Einzweckgutschein, USt beim Gutscheinverkauf; Einlösung = Preisnachlass) oder `multi` (Mehrzweckgutschein, Einlösung = Zahlungsmittel, USt auf den vollen Preis). `$purpose` ist die Angabe aus den WP-Voucher-Captain-Daten (oder `null`); `$voucher`: Anbieterdaten (Checkout: `quote`, `reservation`; Ticketdienst: `$source`); `$context`: `source` (`checkout`/`ticket_service`), `order_id`, `code_hint`, `ticket_type_id`, `quantity`. Rückgabe `null`/unbekannt → Einstellung `voucher_default_purpose` (Standard `multi`). Siehe docs/VOUCHERS.md. |
| `wpcap_woocommerce_hold_minutes` | `int $minutes` | Reservierung bei WooCommerce-Checkout. |
| `wpcap_woocommerce_on_hold_minutes` | `int $minutes` (3 Tage) | Reservierung für WooCommerce-Bestellungen „in Wartestellung“. |
| `wpcap_woocommerce_mixed_seller_policy` | `string $policy` (`reject`/`split`) | Umgang mit WooCommerce-Warenkörben mit Tickets verschiedener Rechnungssteller; Standard aus der Option `wpcap_woocommerce_mixed_sellers` (`reject`). Siehe docs/WOOCOMMERCE.md, „Mehrere Rechnungssteller“. |
| `wpcap_woocommerce_refund_checked_in` | `bool $allowed, WC_Order|null $wc_order` | Ob WooCommerce-Erstattungen mit Menge eingecheckte Tickets erstatten dürfen; Standard aus der Kerneinstellung `refund_after_checkin` (falls vorhanden), sonst Option `wpcap_woocommerce_refund_checked_in` (`no`). |

### Zahlungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_payment_gateways` | `GatewayInterface[] $gateways` | Weitere Zahlungsadapter registrieren (z. B. Mollie). |
| `wpcap_bank_transfer_details` | `array $details` (`reference`, `remittance`, `amount_minor`, `currency`, `amount`, `recipient`, `iban`, `bic`, `due_at`, `due_date`, `instructions`), `object $order` | Zahlungsangaben für Überweisung in Bestätigung, Bestellportal und Bestell-Mail. |
| `wpcap_payment_return_url` | `string $url, string $status, string $public_id, string $provider` | Rückkehr-URL nach der Zahlungsseite (`success`, `pending`, `failed`, `cancel`). |
| `wpcap_stripe_session_params` | `array $params, array $order, array $items` | Parameter der Stripe Checkout Session. |
| `wpcap_paypal_order_body` | `array $body, array $order, array $items` | Body der PayPal-Order. |
| `wpcap_mollie_webhook_ip_limit` | `int $limit` (120) | Mollie-Webhook-Abfragen bekannter Zahlungen je IP und Minute (darüber HTTP 429). |
| `wpcap_mollie_webhook_global_limit` | `int $limit` (1200) | Mollie-Webhook-Abfragen insgesamt je Minute. |
| `wpcap_mollie_payment_body` | `array $body, array $order, array $items` | Body von `POST /v2/payments` (Mollie). |

### E-Mails, Tickets, Darstellung

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_ticket_mail` | `array $mail` (`subject`, `body`, `attachments`), `object $order, array $issued` | Ticket-E-Mail anpassen. `$issued` je Ticket: `ticket_id`, `ticket_type_id`, `occurrence_id`, `token` (Klartext, nur hier), `event`, `ticket`, `starts_at`, `timezone`, `location`. `subject`/`body` sind bereits aus der (ggf. angepassten) Vorlage `tickets` gerendert; im HTML-Modus wird angehängter Klartext automatisch maskiert und umbrochen. Angehängte Dateien werden nach dem Versand gelöscht. Das Kalender-Modul hängt hier `.ics`-Dateien an (Priorität 20). |
| `wpcap_attach_ticket_pdf` | `bool $attach, int $order_id` | PDF-Tickets anhängen. |
| `wpcap_mail_types` | `array $types` | Registry der Kunden-Mails (`PassCaptain\Mail\Types`): eigene Typen ergänzen oder Labels/Standardtexte ändern (Kern-Typen können nicht entfernt werden). Siehe [MAIL-TEMPLATES.md](MAIL-TEMPLATES.md). |
| `wpcap_mail` | `array $message, string $to` | Jede Kunden-Mail des zentralen Mailers vor dem Versand (`type`, `subject`, `body`, `format`, `attachments`, `locale`, optional `headers`). |
| `wpcap_mail_layout_html` | `string $html, string $content, string $subject` | Fertiges HTML-Dokument des E-Mail-Layouts (HTML-Vorlagen). |
| `wpcap_mail_test_recipient` | `string $email, WP_User|null $user` | Empfänger der „Testmail senden“-Funktion (Standard: Adresse der angemeldeten Person). |
| `wpcap_ticket_code_mail_section` | `string $section, int $ticket_id` | Zusatztext je Ticket in Übertragungs-, Zuweisungs- und Neuversand-Mails (z. B. Wallet-Links; nach einem Inhaberwechsel der Einwilligungslink `?wpcap_consent=` für offene Einwilligungen). |
| `wpcap_strict_csp` | `string $policy` | Strikte Content-Security-Policy der vollständig vom Plugin ausgegebenen Seiten (Ticketseite, Rechnung, Einwilligungsseite), z. B. um die Origin eines fremd gehosteten Markenlogos zu `img-src` hinzuzufügen. Zeilenumbrüche werden entfernt, leere Rückgabe = Standard (docs/SECURITY.md). |
| `wpcap_ticket_seat_label` | `string $label, int $ticket_id, array $ticket` | Sitzplatz-/Bereichsbezeichnung für Wallet-Pässe und Druckvorlagen. |
| `wp_pass_captain_email_template_v1` | `array|null $template, string $template_key, array $payload, string $recipient` | Vorlage für Nachrichten aus `wpcap_message_queue`; Rückgabe `['subject' => …, 'body' => …]`, optional `format` (`text`/`html`), `locale` und `headers` (einzeilige Header, z. B. `List-Unsubscribe`). Erinnerungen nutzen den Schlüssel `wpcap_reminder` (mit Abmeldelink und One-Click-Header). `$recipient` (Empfängeradresse) ab R3. Ein Filter, der eine Exception wirft, hält die Warteschlange nicht mehr an: die Nachricht wird mit Backoff erneut versucht. |
| `wp_pass_captain_ticket_mail_inline_max_v1` | `int $max` (10), `int $order_id` | Bis zu dieser Ticketzahl wird die Ticket-Mail im bestätigenden Request versendet; größere Bestellungen (und jede Bestellung aus einem Zahlungs-Webhook) liefert der Outbox-Job sofort im Hintergrund aus (neue Codes, keine Klartext-Codes in der Datenbank). |
| `wp_pass_captain_ticket_pdf_max_tickets_v1` | `int $max` (200), `int $order_id` | Höchstzahl Tickets im PDF-Anhang einer Ticket-Mail (Erstversand, Zustell-Retry, Neuversand, Neuausstellung); darüber nur Links. |
| `wp_pass_captain_ticket_mail_list_max_v1` | `int $max` (25), `int $order_id` | Ist das PDF mit allen Tickets angehängt, listet der Mailtext nur so viele Tickets plus Hinweis (Gmail kürzt ab ~102 KB). Ohne PDF bleibt die Liste vollständig. |
| `wp_pass_captain_event_card_html_v1` | `string $html, object $event, array $types` | HTML einer Event-Karte im Shortcode `[wp_pass_captain_events]` (das Kalender-Modul ergänzt den Anker `id="wpcap-occ-{Termin}"`). |
| `wp_pass_captain_currencies_v1` | `string[] $currencies` | Wählbare Währungen. |
| `wpcap_buyer_locales` | `array $locales` (Locale → Name) | Sprachen, die Käufer im Checkout wählen können (Quellsprache `de_DE` plus jeder mitgelieferte Katalog in `languages/`); ungültige Einträge werden verworfen. |
| `wpcap_currency_symbols` | `array $symbols` (Code → Symbol) | Währungssymbole in Preisanzeigen (PHP und Checkout-JS). |
| `wpcap_event_card_heading_level` | `int $level` (2, erlaubt 2–6) | Überschriftenebene der Eventkarte (Barrierefreiheit im Theme-Kontext). |
| `wpcap_sender_name` | `string $name` | Name in der Kundenkommunikation (Website-Titel, sonst Hostname). |
| `wp_pass_captain_brand_name_v1` | `string $name` (`Pass Captain`), `string $context` | White Label: Produktname, wo er Nutzern erscheint. Kontexte: `admin_menu` (Hauptmenü), `admin` (Datenschutz-Leitfaden u. a.), `scanner` (Web-Scanner), `offline_scanner` (PWA-Titel, Manifest, Texte), `portal`, `ticket_page`, `pdf` (PDF-Metadaten „Producer“), `text` (übersetzte Texte der Textdomain mit „Pass Captain“, z. B. Admin-Hinweise, Admin-Mails, Web-Scanner-Überschrift, Offline-Scanner-Texte – ersetzt über die gettext-Filter der Domain). Reiner Text, max. 100 Zeichen; leer = Standard. |
| `wp_pass_captain_brand_logo_v1` | `string $url` (`''`), `string $context` | White Label: Logo (http(s)-URL oder Pfad ab `/`) für `scanner`, `offline_scanner` (nur gleiche Herkunft, CSP `img-src 'self'`), `portal`, `ticket_page`. Ohne Logo (Standard) bleibt die Ausgabe unverändert. Rechnungen zeigen den Rechnungssteller und kein Produktlogo. |
| `wp_pass_captain_admin_menu_title_v1` | `string $title` (Brand-Name, Kontext `admin_menu`) | Titel des Kern-Hauptmenüs (auch Netzwerk-Admin). |
| `wp_pass_captain_admin_menu_icon_v1` | `string $icon` (`dashicons-tickets-alt`) | Icon des Kern-Hauptmenüs: Dashicons-Klasse, http(s)-Bild-URL, `data:image/svg+xml;base64,…` oder `none`; Ungültiges fällt auf den Standard zurück. |
| `wp_pass_captain_invoice_mail_attachments_v1` | `string[] $files, int $id, string $type, array $context` | Zusätzliche Anhänge der Mail, die einen Beleg begleitet: Ticket-Mail einer Bestellung mit Rechnung (`invoice`, Kontext `mail` = `tickets`, `order_id`) und Erstattungsbestätigung mit Rechnungskorrektur (`correction`, Kontext `mail` = `refund_confirmation`, `order_id`, `refund_id`). Nur lesbare Dateien werden übernommen; der Kern **löscht sie nach dem Versand** – nur temporäre Dateien übergeben (`PassCaptain\Api\Invoices::temp_file( $name, $inhalt )`). |
| `wp_pass_captain_invoice_links_v1` | `array $links, int $id, string $type, array $context` | Zusätzliche Links/Downloads an einem Beleg, Einträge `label`, `url` (http(s) oder ab `/`), optional `download` (bool); Ungültiges wird verworfen, Ausgabe escaped. Orte (`$context['location']`): `admin_order` (Bestelldetails im Admin), `invoice_view` (HTML-Rechnungsansicht, Admin und Käufer), `portal` (Bestellportal), `settlement_view` (Abrechnung). `$context`: `audience` (`admin`/`customer`), `order_id`, `order_public_id`, `access_token` (nur `customer`; prüfen mit `Api\Invoices::for_order_access()`), `partner_id` (Abrechnung). |
| `wpcap_boxoffice_fiscal_docs_url` | `string $url` | Ziel des Hinweises „keine Registrierkasse“ auf der Kassenseite. |

### Kalender, Erinnerungen, SMS, Webhooks

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_calendar_checkout_url` | `string $url, int $occurrence_id` | Link eines Kalendereintrags (Standard: Einstellung „Seite mit dem Ticket-Shortcode“ + `#wpcap-occ-{id}`). |
| `wpcap_calendar_default_duration` | `int $seconds` (7200), `object $occurrence` | Dauer für `DTEND`, wenn der Termin kein Ende hat. |
| `wpcap_signed_qr_window` | `int[] $window` (`[nicht vor, gültig bis]`, Unix-Sekunden), `object $ticket` | Gültigkeitszeitraum signierter QR-Codes (Standard: ein Jahr vor Beginn bis einen Tag nach dem letzten gültigen Zeitpunkt). Siehe [SECURITY.md](SECURITY.md#signierte-qr-codes-pc1). |
| `wpcap_sms_providers` | `ProviderInterface[] $providers` | Weitere SMS-Adapter (`PassCaptain\Sms\ProviderInterface`). |
| `wpcap_sms_number_daily_limit` | `int $limit` (3) | SMS-Einwilligungen je Mobilnummer in 24 Stunden (Missbrauchsschutz, zusätzlich zur Bestätigung per Code). |
| `wpcap_sms_otp_limits` | `array $limits` (`number_hour` 3, `number_day` 5, `ip_hour` 5, `site_day` 500, `confirm_ip_hour` 30) | Kosten-/Missbrauchsschutz der Nummernbestätigung (PRIV-16): Codes je Nummer pro Stunde/Tag, je Client-IP pro Stunde, je Site pro Tag, Bestätigungsversuche je IP pro Stunde. Werte < 1 werden 1. |
| `wpcap_sms_otp_body` | `string $body, string $code` | Text der SMS mit dem Bestätigungscode; enthält der Text den Code nicht, wird er vorangestellt. |
| `wpcap_sms_ip_hourly_limit` | `int $limit` (10) | SMS-Einwilligungen je Client-IP und Stunde. |
| `wpcap_sms_skip_reason` | `string $reason, object $message` | Grund, eine fällige SMS nicht zu senden (leer = senden). Erinnerungs-SMS werden so bei ungültigen Tickets übersprungen. |
| `wpcap_webhook_payload_object` | `array $object, string $type` | `data.object` eines ausgehenden Webhooks (keine PII ergänzen). |
| `wpcap_webhooks_max_attempts` | `int $attempts` (8) | Zustellversuche je Webhook-Ereignis. |
| `wpcap_webhooks_disable_after` | `int $failures` (20) | Fehlversuche in Folge, nach denen ein Abonnement deaktiviert wird. |
| `wp_pass_captain_http_connector_data_v1` | `array $data, int $endpoint_id, string $event, array $core_data` | `data` eines HTTP/JSON-Connectors nach der Feldzuordnung (nur wenn eine Zuordnung konfiguriert ist). Personenbezogene Felder sind ohne Opt-in bereits entfernt – hier keine ergänzen. Siehe [EXTENSIONS.md](EXTENSIONS.md#http-json-connector). |
| `wpcap_connector_log_days` | `int $days` (30) | Aufbewahrung zugestellter/fehlgeschlagener Einträge im Zustellprotokoll der HTTP/JSON-Connectors. |

### Datenschutz

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_privacy_retention_days` | `int $days, string $step` | Fristen des täglichen Löschlaufs (`0` = Schritt aus): `unpaid_attendee_data` (1), `unpaid_customer_email` (90, auch Rechnungsanschrift), `message_content` (30), `message_rows` (365), `sms_content` (30), `checkin_staff` (365, auch Einlassbuchungen, -konflikte und Gerätebezeichnungen), `audit_logs` (730), `service_holds` (90), `waitlists` (Einstellung, 30), `member_passes` (Einstellung, 365), `woocommerce_failed_attendees` (7), `reservations` (30, abgeschlossene Reservierungen), `jobs` (30, erledigte Hintergrundjobs). Sonderfall `order_years`: Wert in **Jahren** (Einstellung, 10) für die Käuferdaten aller Bestellungen nach der gesetzlichen Frist. |
| `wpcap_attendee_default_retention_days` | `int $days` (365) | Frist für Teilnehmername/-E-Mail nach Terminende, wenn die Einstellung „Teilnehmerdaten löschen nach“ 0 ist (Antworten folgen dann der Frist des Felds). |
| `wpcap_privacy_retention_budget` | `float $seconds` (20) | Zeitbudget eines Löschlaufs; der nächste Lauf setzt fort. |
| `wpcap_privacy_policy_recipients` | `array $recipients` | Welche Empfänger der Datenschutztext-Vorschlag nennt (`stripe`, `paypal`, `mollie`, `twilio`, `sms_http`, `apple`, `google`, `partners`, `webhooks`, `affiliate`, `woocommerce` → bool). |
| `wpcap_privacy_policy_content` | `string $html, array $recipients` | HTML des Datenschutztext-Vorschlags. |
| `wpcap_data_profile` | `string $profile, int $user_id` | Datenprofil der angemeldeten Person für Masken und Exporte (`full`, `operations`, `support`, `finance`, `partner`, `checkin`, `none`; `Security\DataPolicy::profile()`); unbekannte Werte fallen auf das berechnete Profil zurück. |
| `wpcap_scanner_queue_max_hours` | `int $hours` (24, 1–168) | Wie lange der Web-Scanner Offline-Scans im Browser aufbewahrt. |

### Wallet

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_wallet_apple_pass_json` | `array $json, array $ticket` | `pass.json` des Apple-Wallet-Passes. |
| `wpcap_wallet_apple_locations` | `array $locations, array $ticket` | Orte für Sperrbildschirm-Hinweise (max. 10). |
| `wpcap_wallet_apple_images` | `array $images` | Bilder des Passes (Dateiname → Bytes). |
| `wpcap_wallet_apns_client` | `ApnsClient|null $client` | Eigener APNs-Client (Tests). |
| `wpcap_wallet_google_class` | `array $class, array $ticket` | Google-Wallet-EventTicketClass. |
| `wpcap_wallet_google_object` | `array $object, array $ticket` | Google-Wallet-EventTicketObject. |

### Versionierte Verträge und Infrastruktur

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_modules` | `string[] $classes` | Liste der Modulklassen (`src/Modules`). Hauptandockpunkt für Add-ons: Modulklassen liefern Admin-Seiten, Formular-Handler, Einstellungen und Tabellen (siehe [EXTENSIONS.md](EXTENSIONS.md)). |
| `wpcap_cron_time_budget` | `float $seconds` (20) | Zeitbudget je Cron-Lauf für Nachrichten, Jobs, Webhooks und SMS (gekappt auf `max_execution_time` − 15 s). |
| `wpcap_message_batch_size` | `int $limit` (500) | Nachrichten je Lauf der Nachrichtenwarteschlange. |
| `wpcap_db_tracks_in_transaction` | `bool $tracks` | Test-Naht: ob der Datenbankserver `@@in_transaction` meldet (Standard aus `SELECT VERSION()`); `false` erzwingt den Markierungs-Pfad von `Orders::in_transaction()`. |
| `wpcap_webhook_claim_wait_ms` | `int $milliseconds` (3000) | Wie lange ein Zahlungs-Webhook auf eine parallel laufende Verarbeitung desselben Ereignisses wartet, bevor er den Anbieter um Wiederholung bittet (`Payments\Webhooks`). |
| `wpcap_network_site_ids` | `int[] $site_ids` | Websites der Netzwerk-Übersicht (Multisite), z. B. um sehr große Netzwerke einzuschränken. |
| `wp_pass_captain_connectors_v1` | `ConnectorInterface[] $connectors` | Connectoren registrieren. |
| `wp_pass_captain_process_job_v1` | `bool|WP_Error $handled, string $job_type, array $payload, int $job_id` | Hintergrundjobs aus `wpcap_jobs` verarbeiten (`true` = erledigt). `false` oder ein `WP_Error` = erneut versuchen (mit Backoff, nach 8 Versuchen `dead_letter`); die Meldung eines `WP_Error` erscheint als letzter Fehler auf der Systemstatus-Seite. Fremde Job-Typen den Wert unverändert zurückgeben. |
| `wp_pass_captain_voucher_provider_v1` | `ProviderInterface|null $provider, int $version` | Gutschein-Anbieter. `commit()`-Fehler mit Status ≥ 500 oder Code `vcap_temporary_failure` werden über den Job `voucher_commit` wiederholt, alle anderen markieren die bezahlte Bestellung zur Prüfung. Das Angebot (`quote()`) kann `treatment` liefern: `payment` (Mehrzweckgutschein – USt bleibt, Gutschein erscheint als Zahlung auf der Rechnung, Gutschriften umfassen den Gutscheinanteil), `single` (Einzweckgutschein – USt bereits beim Verkauf abgeführt) oder `discount` (Standard – Preisnachlass). |
| `wp_pass_captain_ticket_service_v1` | `TicketService|null $service, int $version` | Dienst für die Rückrichtung: ein eingelöster Gutschein wird in Tickets umgewandelt (`issue_tickets`, `revoke_tickets`, `get`, `targets`, `options`, `events`); Ziel ist eine feste Ticketart oder eine Veranstaltung mit frei wählbarem Termin. Idempotent pro Schlüssel; Fehler tragen `retryable`. |
| `wp_pass_captain_checkin_service_v1` | `CheckinService|null $service, int $version` | Check-in-Dienst für einen fremden Scanner (gemeinsamer Scanner): `recognizes`, `preview`, `check_in`; prüft das Recht `wpcap_checkin`. |
| `wp_pass_captain_wallet_provider_v1` | `ProviderInterface|null $provider, string $provider_key, int $version` | Wallet-Anbieter (Bridge). |
| `wp_pass_captain_settings_defaults_v1` | `array $defaults` (Schlüssel → Standardwert) | Einstellungen eines Add-ons ohne Modulklasse. Nur neue Schlüssel werden übernommen; Kern- und Modulwerte bleiben. Lesen mit `Settings::get()`. |
| `wp_pass_captain_settings_sections_v1` | `array $sections` (ID → `title`, `fields`) | Abschnitte und Felder der Kern-Einstellungsseite. Feldtypen `int` (`min`, `max`), `bool`, `choice` (`options`), `text`, `textarea`, `html`, `money`, `secret`; optional `description`, `sanitize` (callable `( $value, $current )` → Wert oder `WP_Error`). Der Schlüssel muss in den Standardwerten existieren. Kernfelder können nicht entfernt oder umtypisiert werden. |
| `wp_pass_captain_admin_menu_v1` | `array $pages` (Einträge `[ slug, title, capability, callable ]`) | Unterseiten im Menü **Pass Captain** für Add-ons ohne Modulklasse (vor „Einstellungen“); Slugs des Kerns werden ignoriert. |
| `wp_pass_captain_report_data_v1` | `array $report, array $filters, array|null $scope` | Daten des Berichts (`Reports\Data::build()`); eigene Kennzahlen unter `extra` ablegen. `$scope`: erlaubte Event-IDs oder `null` (alle). |
| `wp_pass_captain_report_metrics_v1` | `array $rows` (Label → Text), `array $report, array $filters` | Zeilen der Übersichtstabelle im Bericht (werden escaped ausgegeben). |
| `wp_pass_captain_report_tabs_v1` | `array $tabs` (ID → `label`, `callback`, optional `capability`) | Zusätzliche Reiter auf der Berichtsseite; `callback( $report, $filters, $scope )` gibt den Inhalt (escaped) aus. |
| `wp_pass_captain_export_rows_v1` | `array $rows, string $type, array $context` | Zeilen jedes CSV-Exports (erste Zeile = Kopf) vor der Ausgabe, z. B. für zusätzliche Spalten. `$type`: `report` (Kontext `report`), `accounting` (Belegexport, Kontext `from`, `to`), `accounting_ledger` (Zahlungen/Erstattungen unabhängig von Rechnungen, Kontext `from`, `to`) oder die ID eines zusätzlichen Exports (Kontext `filters`). Zellen laufen bei der Ausgabe durch den Formelschutz. |
| `wp_pass_captain_checkout_legal_html_v1` | `string $html, object $occurrence, object[] $types` | HTML der Hinweise unter dem Bestellbutton der Eventkarte (Widerrufsrecht-Hinweis nach `Frontend\LegalNotice`, Datenschutz-Link auf `get_privacy_policy_url()`), z. B. um sie zu ergänzen oder auszublenden. Rückgabe wird unverändert ausgegeben (selbst escapen). |
| `wp_pass_captain_export_types_v1` | `array $types` (ID → `label`, `rows`, optional `capability`, `filename`) | Zusätzliche Exporte als Schaltflächen auf der Berichtsseite; `rows( $filters, $scope )` liefert die Zeilen. Standardrecht `wpcap_view_reports`. |
| `wp_pass_captain_scanner_adapters_v1` | `array $adapters` (ID → `label`, `recognizes`, `check_in`, optional `preview`, `capability`) | Scanner-Adapter für Codes, die kein Pass-Captain-Ticket sind (siehe [EXTENSIONS.md](EXTENSIONS.md#scanner)). |
| `wp_pass_captain_api_scopes_v1` | `array $scopes` (Scope → Label) | Zusätzliche Scopes der Entwickler-API-Keys (z. B. `orders.write`); erscheinen auf der Seite „Entwickler-API-Keys“ und in `POST /developer/keys`. Format `bereich.aktion` (Kleinbuchstaben, Ziffern, `_`); Kern-Scopes (`events.read`, `reports.read`) können nicht ersetzt werden. Prüfung in Add-on-Routen mit `PassCaptain\Api\Auth::request_can( $request, $scope[, $event_id] )`. |
| `wp_pass_captain_pos_payment_devices_v1` | `array $devices` (ID → `DeviceGatewayInterface` oder `label`, `start`, `poll`, `cancel`, optional `registers`), `array $context` (`version`, `session_id`, `location_id`, `register_id`) | Kartenterminals der Kasse registrieren (Vertrag `PassCaptain\BoxOffice\DeviceGatewayInterface`, Version 1). IDs `[a-z0-9_-]{1,40}`; ungültige Einträge werden verworfen, `registers` (Liste `standort_id|kassen_id`) beschränkt ein Gerät auf Register. Nur für Register, die das Kassenregister erlaubt. Die Zahlart „Karte am Terminal (automatisch)“ (`card_device`) erscheint nur, wenn mindestens ein Gerät registriert ist; ohne Gerät bleibt die Kasse unverändert. Exceptions im Filter: keine Geräte. Siehe [EXTENSIONS.md](EXTENSIONS.md#kartenterminals-an-der-kasse-box-office). |
| `wp_pass_captain_pos_device_timeout_v1` | `int $seconds` (180), `string $device_id` | Wie lange eine Terminalzahlung offen bleiben darf, bevor die Kasse sie am Gerät abbricht und den Verkauf freigibt (`timeout`); begrenzt auf 30–840 Sekunden (unter der 15-Minuten-Reservierung). |
| `wpcap_log_to_error_log` | `bool $enabled, string $level, string $event` | Ob Einträge von `PassCaptain\Log` zusätzlich ins PHP-Fehlerprotokoll gehen (Standard: nur mit `WP_DEBUG` und `WP_DEBUG_LOG`). |

### Veraltete Namen (Rückwärtskompatibilität)

Diese Hooks feuern weiterhin (über `apply_filters_deprecated()`, WordPress meldet die Nutzung mit `WP_DEBUG`),
und zwar vor dem neuen Namen mit demselben Wert. Neue Add-ons nutzen den versionierten Namen.

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wp_pass_captain_currencies` | `string[] $currencies` | Veraltet seit 1.5.0, ersetzt durch `wp_pass_captain_currencies_v1`. |
| `wp_pass_captain_event_card_html` | `string $html, object $event, array $types` | Veraltet seit 1.5.0, ersetzt durch `wp_pass_captain_event_card_html_v1`. |
