# Interne Entwickler-Hooks

Alle `do_action()`- und `apply_filters()`-Aufrufe in `src/` (Stand 2026-11-11), mit Argumenten.
Hooks mit Suffix `_v1` sind versionierte Verträge für Drittanbieter; die übrigen sind stabile interne
Erweiterungspunkte. Externe Systeme sollten statt PHP-Hooks die signierten
[ausgehenden Webhooks](WEBHOOKS.md) verwenden.

Neue Hooks bitte hier ergänzen. Prüfen lässt sich die Liste mit
`grep -rnE "(do_action|apply_filters)\(" src --include=*.php`.

## Actions

| Hook | Argumente | Ort / Zeitpunkt |
|---|---|---|
| `wpcap_order_creating` | `int $order_id, array $selected, array $payload, array $internal` | `Orders::create()` innerhalb der Bestell-Transaktion (Ticketarten/Termine gesperrt). `\RuntimeException('code')` bricht ab; Meldung über `wpcap_checkout_error_messages`. `$selected`: Zeilen mit `type`, `event_id`, `quantity`, `line_total`; `$payload`: öffentliche Checkout-Felder (siehe `wpcap_checkout_payload_keys`); `$internal`: vertrauenswürdige Adapter-Optionen (`payment_provider`, `external`, …). |
| `wpcap_capacity_released` | `int $order_id, string $reason, array $details` | Kontingent einer Bestellung zählt nicht mehr: `expired` (`expire_holds`), `cancelled` (`cancel_order`), `refunded` (`mark_refunded`), `tickets_revoked` (`revoke_tickets`, `$details`: `ticket_type_id`, `quantity`, `reason`), `event_cancelled`, `payment_unallocated` (verspätete Zahlung ohne Kontingent). Kern-Kontingente und Serien-Pools werden aus Bestellungen/Reservierungen abgeleitet und brauchen den Hook nicht; er ist für Module mit eigenen Zählern oder Caches. |
| `wpcap_mail_before_send` | `array $prepared, array $message` | Unmittelbar vor `wp_mail()` jeder Kunden-Mail des zentralen Mailers; `$prepared`: `to`, `subject`, `body` (inkl. Layout), `headers`, `attachments`, `alt_body` (Textversion bei HTML-Mails, sonst `null`). |
| `wpcap_order_paid` | `int $order_id` | Nach Zahlungsbestätigung bzw. bei kostenlosen Bestellungen nach Ausgabe der Tickets. |
| `wpcap_order_cancelled` | `int $order_id` | `Orders::cancel_order()` und beim Ablauf von Reservierungen (`expire_holds`). |
| `wpcap_ticket_created` | `int $ticket_id, object $order, object $line, int $index` | Innerhalb der Ausgabe-Transaktion, je Ticket (z. B. Sitzplatz zuweisen). Klartext-Code nicht speichern. |
| `wpcap_tickets_issued` | `int $order_id, int $count` | Nach dem Commit der Ticketausgabe, vor dem Versand der Ticket-E-Mail. |
| `wpcap_ticket_revoked` | `int $ticket_id, string $reason` | Nach dem Widerruf: Admin (`TicketsPage`) und über `Orders::fire_revoked()` (Erstattung `refund_{id}`, Stornierung `order_cancelled`, Terminabsage `occurrence_cancelled`, Event-Absage `event_cancelled`, Vollerstattung). |
| `wpcap_ticket_code_rotated` | `int $ticket_id, string $code, string $reason` | Neuer Ticketcode (`transfer`, `resend`, `assign`). `$code` ist der Klartext-Code – nur flüchtig verwenden, nie speichern oder loggen. |
| `wpcap_ticket_checked_in` | `int $ticket_id, array $context` | Nach erfolgreichem Einlass (`Checkin`). `$context`: u. a. `source`, `device_id`. |
| `wpcap_ticket_checked_out` | `int $ticket_id, array $context` | Nach einem Auslass (Mehrfacheinlass/Bereiche). |
| `wpcap_refund_completed` | `int $refund_id, int $order_id` | Erstattung über das Plugin erfolgreich ausgeführt (auch Teilerstattung). |
| `wpcap_occurrence_rescheduled` | `int $occurrence_id, string $old_starts_at, string $new_starts_at` | Admin: Startzeit eines Termins mit ausgegebenen Tickets geändert (UTC `Y-m-d H:i:s`). |
| `wpcap_occurrence_cancelled` | `int $occurrence_id, int $issued` | Admin: Termin abgesagt (`$issued` = Anzahl ausgegebener Tickets). Kern-Folgen in `OccurrenceCancellation` (Priorität 10); das SMS-Modul hängt sich mit Priorität 5 davor. |
| `wpcap_occurrence_cancelled_woocommerce_order` | `int $order_id, int $occurrence_id` | Je bezahlter WooCommerce-Bestellung eines abgesagten Termins (Erstattung erfolgt in WooCommerce). |
| `wp_pass_captain_connector_error_v1` | `string $connector_key, string $event_name, string $message` | Ein Connector hat bei einem Ereignis eine Exception geworfen. |
| `wpcap_checkout_form_fields` | `object $event, array $types` | Template `templates/event-card.php` innerhalb des Checkout-Formulars (eigene Felder ausgeben; JS-Ereignis `wpcap:checkout-payload` ergänzt den Payload). |

Cron-Actions, an die sich Module hängen: `wpcap_process_messages` (alle 5 Minuten, Nachrichten-Queue;
Erinnerungen planen sich mit Priorität 1 ein, Bereinigung mit 5, SMS-Versand mit 20),
`wpcap_process_jobs` (alle 5 Minuten; Webhook-Zustellung mit Priorität 20),
`wpcap_cleanup_reservations` (stündlich) und `wpcap_webhooks_dispatch` (einmalig nach neuen
Webhook-Ereignissen).

## Filter

### Checkout und Bestellungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_checkout_payload_keys` | `string[] $keys` | Zusätzliche öffentliche Checkout-Felder durchlassen (z. B. `sms_phone`, `sms_consent`, `mollie_method`). |
| `wpcap_checkout_error_messages` | `array $messages` (Code → Text) | Meldungen zu Abbruch-Codes aus `wpcap_order_creating`. |
| `wpcap_affiliate_partner_field` | `bool $show` (true) | Partnercode-Feld im Checkout (nur mit WP Affiliate Captain). |
| `wpcap_affiliate_tracking_allowed` | `bool $allowed` | Ob der Empfehlungs-Cookie/-Link (`wpac_current_touch()`) gelesen werden darf. Standard: mit WP Consent API `wp_has_consent( 'marketing' )`, ohne Consent-API `true`. Ein eingegebener Partnercode ist immer erlaubt. |
| `wpcap_affiliate_hold_until` | `int $timestamp, object $order` | Sperrfrist der Provision (Standard: Ende des letzten gebuchten Termins). |
| `wpcap_affiliate_net_minor` | `int $net, object $line, object $order` | Provisionsbasis je Bestellzeile. |
| `wpcap_affiliate_sale_payload` | `array $payload, object $order` | Verkaufsdaten an WP Affiliate Captain. |
| `wpcap_affiliate_skip_channels` | `string[] $channels` (`woocommerce`) | Kanäle/Zahlungsanbieter, die nicht gemeldet werden. |
| `wpcap_checkout_ip_limit` | `int $limit` (30) | Checkouts je IP und Stunde. |
| `wpcap_ticket_unit_price` | `int $price_minor, object $type, array $context` | Brutto-Einzelpreis einer Ticketart, nur serverseitig (`Orders::unit_price()`). `$context`: `occurrence` (Zeile), `quantity` (Menge der Ticketart in der Bestellung, 1 zur Anzeige), `now` (UTC `Y-m-d H:i:s`), `phase` (`create`/`display`/`cart`), `source` (`woocommerce` im Woo-Warenkorb). In `Orders::create()` innerhalb der Bestell-Transaktion nach der Kontingentprüfung. **Vorrang:** vertrauenswürdige `unit_prices` aus `$internal` (WooCommerce, kostenlose Kassenverkäufe) ersetzen den Preis ohne Filter; sonst gilt gespeicherter Preis + Filter (öffentlicher Checkout, Warteliste, Kasse ohne Preisvorgabe). Kundeneingaben erreichen weder Filter noch Override. Geänderte Zeilen stehen im `price_snapshot` unter `unit_price_adjustments`. Serien-Preisstufen hängen sich hier ein. |
| `wpcap_woocommerce_use_core_prices` | `bool $enabled, WC_Product $product, int $ticket_type_id` | Ob Pass-Captain-Preise (inkl. `wpcap_ticket_unit_price`, z. B. Serien-Preisstufen) für dieses Ticketprodukt im Woo-Warenkorb gelten; nur wirksam, wenn die globale Option (WooCommerce → Einstellungen → Produkte) aktiv ist. Die Reservierung übernimmt weiterhin die Woo-Zeilensummen als vertrauenswürdigen Override. |
| `wpcap_ticket_price_label` | `string $label, object $type, array $context` | Öffentliche Bezeichnung des aktuell geltenden Preises (z. B. „Frühbucher“), `Orders::price_label()`; Event-Karte und Serien-Admin. |
| `wpcap_reserve_capacity` | `true|WP_Error $result, array $demand, array $context` | Zusätzliche gemeinsame Kontingente (z. B. Serien-Pools). Läuft in `Orders::create()` und bei verspäteter Zahlung (`confirm_external_payment`) in der Transaktion, **nachdem** Ticketarten und Termine (in dieser Reihenfolge, je nach ID) gesperrt sind und **bevor** der Kern erstmals konsistent liest. `$demand`: Ticketart-ID → Menge; `$context`: `phase` (`create`/`recover`), `order_id` (bei `recover` aus der Zählung auszunehmen, sonst 0), `types`/`occurrences` (gesperrte Zeilen nach ID), `now`, `source`. Eigene Zeilen zuerst mit sperrenden Lesezugriffen (`FOR UPDATE`/`LOCK IN SHARE MODE`, feste Reihenfolge) sperren, erst danach Belegung lesen – sonst ist der Snapshot veraltet. `WP_Error` lehnt ab (Code über `wpcap_checkout_error_messages`, sonst die Meldung des Fehlers; bei `recover` → `needs_review`). |
| `wpcap_ticket_availability` | `int|null $remaining, object $type, object $occurrence` | Restmenge in `Orders::availability()` (Anzeige, Warenkorbprüfung, Kasse). Nur verkleinern; `null` = unbegrenzt. Serien-Pools hängen sich hier ein. |
| `wpcap_service_booking_ip_limit` | `int $limit` (40) | Buchungsversuche je IP. |
| `wpcap_seating_availability_limit` | `int $limit` (120) | Abrufe der Sitzplatzverfügbarkeit je Client und Minute. |
| `wpcap_checkin_now` | `int $timestamp` | Zeitpunkt für Einlassentscheidungen (Tests/Simulation). |
| `wpcap_user_has_2fa` | `bool $has, int $user_id` | Zwei-Faktor-Status für Erstattungen. |
| `wpcap_woocommerce_hold_minutes` | `int $minutes` | Reservierung bei WooCommerce-Checkout. |
| `wpcap_woocommerce_on_hold_minutes` | `int $minutes` (3 Tage) | Reservierung für WooCommerce-Bestellungen „in Wartestellung“. |

### Zahlungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_payment_gateways` | `GatewayInterface[] $gateways` | Weitere Zahlungsadapter registrieren (z. B. Mollie). |
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
| `wpcap_ticket_code_mail_section` | `string $section, int $ticket_id` | Zusatztext je Ticket in Übertragungs-, Zuweisungs- und Neuversand-Mails (z. B. Wallet-Links). |
| `wpcap_ticket_seat_label` | `string $label, int $ticket_id, array $ticket` | Sitzplatz-/Bereichsbezeichnung für Wallet-Pässe und Druckvorlagen. |
| `wp_pass_captain_email_template_v1` | `array|null $template, string $template_key, array $payload` | Vorlage für Nachrichten aus `wpcap_message_queue`; Rückgabe `['subject' => …, 'body' => …]`. Erinnerungen nutzen den Schlüssel `wpcap_reminder`. |
| `wp_pass_captain_event_card_html` | `string $html, object $event, array $types` | HTML einer Event-Karte im Shortcode `[wp_pass_captain_events]` (das Kalender-Modul ergänzt den Anker `id="wpcap-occ-{Termin}"`). |
| `wp_pass_captain_currencies` | `string[] $currencies` | Wählbare Währungen. |

### Kalender, Erinnerungen, SMS, Webhooks

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_calendar_checkout_url` | `string $url, int $occurrence_id` | Link eines Kalendereintrags (Standard: Einstellung „Seite mit dem Ticket-Shortcode“ + `#wpcap-occ-{id}`). |
| `wpcap_calendar_default_duration` | `int $seconds` (7200), `object $occurrence` | Dauer für `DTEND`, wenn der Termin kein Ende hat. |
| `wpcap_sms_providers` | `ProviderInterface[] $providers` | Weitere SMS-Adapter (`PassCaptain\Sms\ProviderInterface`). |
| `wpcap_sms_number_daily_limit` | `int $limit` (3) | SMS-Einwilligungen je Mobilnummer in 24 Stunden (Missbrauchsschutz; eine Bestätigung per SMS-Code ist nicht implementiert). |
| `wpcap_sms_ip_hourly_limit` | `int $limit` (10) | SMS-Einwilligungen je Client-IP und Stunde. |
| `wpcap_sms_skip_reason` | `string $reason, object $message` | Grund, eine fällige SMS nicht zu senden (leer = senden). Erinnerungs-SMS werden so bei ungültigen Tickets übersprungen. |
| `wpcap_webhook_payload_object` | `array $object, string $type` | `data.object` eines ausgehenden Webhooks (keine PII ergänzen). |
| `wpcap_webhooks_max_attempts` | `int $attempts` (8) | Zustellversuche je Webhook-Ereignis. |
| `wpcap_webhooks_disable_after` | `int $failures` (20) | Fehlversuche in Folge, nach denen ein Abonnement deaktiviert wird. |

### Datenschutz

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_privacy_retention_days` | `int $days, string $step` | Fristen des täglichen Löschlaufs (`0` = Schritt aus): `unpaid_attendee_data` (1), `unpaid_customer_email` (90), `message_content` (30), `message_rows` (365), `checkin_staff` (365), `audit_logs` (730). |
| `wpcap_attendee_default_retention_days` | `int $days` (365) | Frist für Teilnehmername/-E-Mail nach Terminende, wenn die Einstellung „Teilnehmerdaten löschen nach“ 0 ist (Antworten folgen dann der Frist des Felds). |
| `wpcap_privacy_retention_budget` | `float $seconds` (20) | Zeitbudget eines Löschlaufs; der nächste Lauf setzt fort. |
| `wpcap_privacy_policy_recipients` | `array $recipients` | Welche Empfänger der Datenschutztext-Vorschlag nennt (`stripe`, `paypal`, `mollie`, `twilio`, `sms_http`, `apple`, `google`, `partners`, `webhooks`, `affiliate`, `woocommerce` → bool). |
| `wpcap_privacy_policy_content` | `string $html, array $recipients` | HTML des Datenschutztext-Vorschlags. |
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
| `wpcap_modules` | `string[] $classes` | Liste der Modulklassen (`src/Modules`). |
| `wp_pass_captain_connectors_v1` | `ConnectorInterface[] $connectors` | Connectoren registrieren. |
| `wp_pass_captain_process_job_v1` | `bool $handled, string $job_type, array $payload, int $job_id` | Hintergrundjobs aus `wpcap_jobs` verarbeiten (`true` = erledigt). |
| `wp_pass_captain_voucher_provider_v1` | `ProviderInterface|null $provider, int $version` | Gutschein-Anbieter. `commit()`-Fehler mit Status ≥ 500 oder Code `vcap_temporary_failure` werden über den Job `voucher_commit` wiederholt, alle anderen markieren die bezahlte Bestellung zur Prüfung. |
| `wp_pass_captain_ticket_service_v1` | `TicketService|null $service, int $version` | Dienst für die Rückrichtung: ein eingelöster Gutschein wird in Tickets umgewandelt (`issue_tickets`, `revoke_tickets`, `get`, `targets`, `options`, `events`); Ziel ist eine feste Ticketart oder eine Veranstaltung mit frei wählbarem Termin. Idempotent pro Schlüssel; Fehler tragen `retryable`. |
| `wp_pass_captain_wallet_provider_v1` | `ProviderInterface|null $provider, string $provider_key, int $version` | Wallet-Anbieter (Bridge). |
