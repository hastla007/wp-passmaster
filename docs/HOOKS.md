# Interne Entwickler-Hooks

Alle `do_action()`- und `apply_filters()`-Aufrufe in `src/` (Stand 2026-11-13), mit Argumenten.
**Namensregel (A104):** Alle Hooks beginnen mit `wpcap_`. Ausnahme sind die versionierten
Integrationsverträge zwischen Plugins mit dem Muster `wp_pass_captain_*_v1` (Gutscheinanbieter,
Ticketdienst, Check-in-Dienst, Gutschein-Tickets storniert, Wallet-Anbieter, Connectoren und
Connector-Fehler): andere Plugins rufen sie unter diesem Namen auf, die Versionsnummer gehört zum
Vertrag, eine neue Fassung bekommt `_v2`. Die übrigen sind stabile interne Erweiterungspunkte.

**Umbenannte Hooks (seit 1.5.0):** Die alten Namen funktionieren weiter – Callbacks darauf laufen nach
dem neuen Hook und WordPress meldet sie bei aktivem `WP_DEBUG` als veraltet
(`apply_filters_deprecated`). Bitte auf den neuen Namen umstellen; die Zuordnung steht in
`PassCaptain\Hooks::RENAMED`.

| Alter Name (veraltet) | Neuer Name |
|---|---|
| `wp_pass_captain_process_job_v1` | `wpcap_process_job` |
| `wp_pass_captain_email_template_v1` | `wpcap_email_template` |
| `wp_pass_captain_event_card_html` | `wpcap_event_card_html` |
| `wp_pass_captain_currencies` | `wpcap_currencies` |

Shortcodes gibt es ebenfalls mit Präfix `wpcap_`: `[wpcap_events]`, `[wpcap_scanner]`,
`[wpcap_calendar]`, `[wpcap_passes]`; die bisherigen `[wp_pass_captain_events]`,
`[wp_pass_captain_scanner]` und `[wp_pass_captain_calendar]` bleiben gültig. Externe Systeme sollten statt PHP-Hooks die signierten
[ausgehenden Webhooks](WEBHOOKS.md) verwenden.

Neue Hooks bitte hier ergänzen. Prüfen lässt sich die Liste mit
`grep -rnE "(do_action|apply_filters)\(" src --include=*.php`.

## Actions

| Hook | Argumente | Ort / Zeitpunkt |
|---|---|---|
| `wpcap_order_creating` | `int $order_id, array $selected, array $payload, array $internal` | `Orders::create()` innerhalb der Bestell-Transaktion (Ticketarten/Termine gesperrt). `\RuntimeException('code')` bricht ab; Meldung über `wpcap_checkout_error_messages`. `$selected`: Zeilen mit `type`, `event_id`, `quantity`, `line_total`; `$payload`: öffentliche Checkout-Felder (siehe `wpcap_checkout_payload_keys`); `$internal`: vertrauenswürdige Adapter-Optionen (`payment_provider`, `external`, …). |
| `wpcap_capacity_released` | `int $order_id, string $reason, array $details` | Kontingent einer Bestellung zählt nicht mehr: `expired` (`expire_holds`), `cancelled` (`cancel_order`), `refunded` (`mark_refunded`), `tickets_revoked` (`revoke_tickets`, `$details`: `ticket_type_id`, `quantity`, `reason`), `event_cancelled`, `payment_unallocated` (verspätete Zahlung ohne Kontingent). Kern-Kontingente und Serien-Pools werden aus Bestellungen/Reservierungen abgeleitet und brauchen den Hook nicht; er ist für Module mit eigenen Zählern oder Caches. |
| `wpcap_capacity_increased` | `int $ticket_type_id, int $occurrence_id` | Von Modulen/Admin auszulösen, wenn Kontingent erhöht oder freigegeben wurde (eines der Argumente darf 0 sein). Die Warteliste bietet die neuen Plätze dann automatisch an (siehe [WAITLIST.md](WAITLIST.md)). |
| `wpcap_waitlist_offered` | `int $waitlist_id, int $order_id` | Nach einem Wartelistenangebot samt Reservierung (Platzhalter-Bestellung mit `payment_provider` `waitlist_offer`) und Versand der Angebotsmail. |
| `wpcap_mail_before_send` | `array $prepared, array $message` | Unmittelbar vor `wp_mail()` jeder Kunden-Mail des zentralen Mailers; `$prepared`: `to`, `subject`, `body` (inkl. Layout), `headers`, `attachments`, `alt_body` (Textversion bei HTML-Mails, sonst `null`). |
| `wpcap_mail_suppressed` | `string $to, string $type` | `Mailer::deliver()`: Kunden-Mail an eine Platzhalteradresse (Kasse, Gästeliste oder Partnerverkauf ohne Käuferadresse) wird nicht versendet; `$type` ist der Mail-Typ. |
| `wpcap_order_paid` | `int $order_id` | Nach der Zahlungsbestätigung (auch bei kostenlosen Bestellungen), immer **vor** der Ticketausgabe; wer die Tickets braucht, nutzt `wpcap_tickets_issued`. |
| `wpcap_order_cancelled` | `int $order_id` | `Orders::cancel_order()`, beim Ablauf von Reservierungen (`expire_holds`) und für offene Bestellungen bei der Event-Absage (`cancel_event`). |
| `wpcap_order_refunded` | `int $order_id, string $reason` | `Orders::mark_refunded()` nach dem Commit: Bestellung vollständig erstattet (native Erstattung, WooCommerce oder Dashboard des Zahlungsanbieters); offene Tickets sind widerrufen. |
| `wp_pass_captain_voucher_tickets_revoked_v1` | `array $info` (`redemption_key`, `order_public_id`, `remaining`, `revoked`, `reason`) | Tickets einer Gutschein-Umwandlung wurden in Pass Captain storniert oder erstattet (Bestellung storniert/erstattet, Termin/Event abgesagt) – nicht bei `revoke_tickets()` durch das Gutscheinsystem selbst. Feuert bei jeder Änderung; `remaining` zählt noch gültige oder bereits eingecheckte Tickets. Der Gutscheinanbieter kann den Gutschein damit zurückgeben (idempotent behandeln). |
| `wpcap_ticket_created` | `int $ticket_id, object $order, object $line, int $index` | Innerhalb der Ausgabe-Transaktion, je Ticket (z. B. Sitzplatz zuweisen). Klartext-Code nicht speichern. |
| `wpcap_tickets_issued` | `int $order_id, int $count` | Nach dem Commit der Ticketausgabe, vor dem Versand der Ticket-E-Mail. |
| `wpcap_ticket_revoked` | `int $ticket_id, string $reason` | Nach dem Widerruf: Admin (`TicketsPage`) und über `Orders::fire_revoked()` (Erstattung `refund_{id}`, Stornierung `order_cancelled`, Terminabsage `occurrence_cancelled`, Event-Absage `event_cancelled`, Vollerstattung). |
| `wpcap_ticket_code_rotated` | `int $ticket_id, string $code, string $reason` | Neuer Ticketcode (`transfer`, `resend`, `assign`). `$code` ist der Klartext-Code – nur flüchtig verwenden, nie speichern oder loggen. |
| `wpcap_ticket_checked_in` | `int $ticket_id, array $context` | Nach erfolgreichem Einlass (`Checkin`). `$context`: u. a. `source`, `device_id`. |
| `wpcap_ticket_checked_out` | `int $ticket_id, array $context` | Nach einem Auslass (Mehrfacheinlass/Bereiche). |
| `wpcap_refund_completed` | `int $refund_id, int $order_id` | Erstattung über das Plugin erfolgreich ausgeführt (auch Teilerstattung). |
| `wpcap_refund_rejected` | `int $refund_id, int $order_id` | Eine auf Freigabe wartende Erstattung (`pending_approval`) wurde von einer freigabeberechtigten Person abgelehnt (`Refunds::reject()`). |
| `wpcap_pass_day_refund_failed` | `int $order_id, int $ticket_type_id, int $occurrence_id, int $amount_minor` | Pass-Modul: Die anteilige Erstattung für einen abgesagten Termin eines Passes (`refund_per_day_minor` × Anzahl) konnte nicht ausgeführt werden (z. B. WooCommerce-Bestellung – dort erstatten, oder Fehler/Freigabe ausstehend). |
| `wpcap_occurrence_rescheduled` | `int $occurrence_id, string $old_starts_at, string $new_starts_at` | Admin: Startzeit eines Termins mit ausgegebenen Tickets geändert (UTC `Y-m-d H:i:s`). |
| `wpcap_occurrence_cancelled` | `int $occurrence_id, int $issued` | Admin: Termin abgesagt (`$issued` = Anzahl ausgegebener Tickets). Kern-Folgen in `OccurrenceCancellation` (Priorität 10); das SMS-Modul hängt sich mit Priorität 5 davor. |
| `wpcap_occurrence_cancelled_woocommerce_order` | `int $order_id, int $occurrence_id` | Je bezahlter WooCommerce-Bestellung eines abgesagten Termins (Erstattung erfolgt in WooCommerce). |
| `wp_pass_captain_connector_error_v1` | `string $connector_key, string $event_name, string $message` | Ein Connector hat bei einem Ereignis eine Exception geworfen. |

Außerdem feuert das Template `templates/event-card.php` innerhalb des Checkout-Formulars die Action
`wpcap_checkout_form_fields` (`object $event, array $types`), an die sich Module zum Ausgeben eigener Felder
hängen (Sitzplatzwahl, SMS, Mollie, Partnercode, Käuferlimits); das JS-Ereignis `wpcap:checkout-payload`
ergänzt den Payload. Sie steht hier im Fließtext, weil sie nicht in `src/`, sondern im Template ausgelöst wird.

Die WP-Cron-Hooks des Plugins stehen unter [Cron-Hooks](#cron-hooks).

## Filter und themenbezogene Actions

Actions in diesen Tabellen sind mit „(Action)“ gekennzeichnet.

### Checkout und Bestellungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_checkout_payload_keys` | `string[] $keys` | Zusätzliche öffentliche Checkout-Felder durchlassen (z. B. `sms_phone`, `sms_consent`, `mollie_method`). |
| `wpcap_checkout_error_messages` | `array $messages` (Code → Text) | Meldungen zu Abbruch-Codes aus `wpcap_order_creating`. |
| `wpcap_legal_default_locale` | `string $locale` | Sprache der Standard-Rechtstexte (`terms_text`, `consumer_info_text`). Standard: Standardsprache von Polylang (`pll_default_language`) bzw. WPML (`wpml_default_language`), sonst `get_locale()`. Texte weiterer Sprachen stehen in `legal_texts`; Käufer ohne eigene Fassung sehen die Standardtexte. |
| `wpcap_legal_locales` | `string[] $locales, string $default` | Sprachen, für die unter **Einstellungen → Rechtstexte** eigene Rechtstexte angeboten werden (Standard: Standardsprache, Sprachen von Polylang/WPML, installierte Sprachpakete, bereits gepflegte Sprachen). |
| `wpcap_affiliate_partner_field` | `bool $show` (true) | Partnercode-Feld im Checkout (nur mit WP Affiliate Captain). |
| `wpcap_affiliate_tracking_allowed` | `bool $allowed`, `string $method` | Ob die Empfehlung aus `wpac_current_touch()` verwendet werden darf. Cookie (`cookie`): mit WP Consent API `wp_has_consent( 'marketing' )`, ohne Consent-API `true`. Partnerlink der Shop-Sitzung (`link`, `app`, kein Marketing-Cookie): standardmäßig `true`. Ein eingegebener Partnercode ist immer erlaubt. |
| `wpcap_affiliate_hold_until` | `int $timestamp, object $order` | Sperrfrist der Provision (Standard: Ende des letzten gebuchten Termins). |
| `wpcap_affiliate_net_minor` | `int $net, object $line, object $order` | Provisionsbasis je Bestellzeile. |
| `wpcap_affiliate_sale_payload` | `array $payload, object $order` | Verkaufsdaten an WP Affiliate Captain. |
| `wpcap_affiliate_skip_channels` | `string[] $channels` (`woocommerce`) | Kanäle/Zahlungsanbieter, die nicht gemeldet werden. |
| `wpcap_checkout_ip_limit` | `int $limit` (30) | Checkouts je IP und Stunde. |
| `wpcap_ticket_unit_price` | `int $price_minor, object $type, array $context` | Brutto-Einzelpreis einer Ticketart, nur serverseitig (`Orders::unit_price()`). `$context`: `occurrence` (Zeile), `quantity` (Menge der Ticketart in der Bestellung, 1 zur Anzeige), `now` (UTC `Y-m-d H:i:s`), `phase` (`create`/`display`/`cart`), `source` (`woocommerce` im Woo-Warenkorb). In `Orders::create()` innerhalb der Bestell-Transaktion nach der Kontingentprüfung. **Vorrang:** vertrauenswürdige `unit_prices` aus `$internal` (WooCommerce, kostenlose Kassenverkäufe) ersetzen den Preis ohne Filter; sonst gilt gespeicherter Preis + Filter (öffentlicher Checkout, Warteliste, Kasse ohne Preisvorgabe). Kundeneingaben erreichen weder Filter noch Override. Geänderte Zeilen stehen im `price_snapshot` unter `unit_price_adjustments`. Serien-Preisstufen hängen sich hier ein. |
| `wpcap_woocommerce_use_core_prices` | `bool $enabled, WC_Product $product, int $ticket_type_id` | Ob Pass-Captain-Preise (inkl. `wpcap_ticket_unit_price`, z. B. Serien-Preisstufen) für dieses Ticketprodukt im Woo-Warenkorb gelten; nur wirksam, wenn die globale Option (WooCommerce → Einstellungen → Produkte) aktiv ist. Die Reservierung übernimmt weiterhin die Woo-Zeilensummen als vertrauenswürdigen Override. |
| `wpcap_ticket_price_label` | `string $label, object $type, array $context` | Öffentliche Bezeichnung des aktuell geltenden Preises (z. B. „Frühbucher“), `Orders::price_label()`; Event-Karte und Serien-Admin. |
| `wpcap_reserve_capacity` | `true|WP_Error $result, array $demand, array $context` (`phase`, `order_id`, `types`, `occurrences`, `now`, `source`, `email`, `user_id`, `internal`) | Zusätzliche gemeinsame Kontingente (z. B. Serien-Pools). Läuft in `Orders::create()` und bei verspäteter Zahlung (`confirm_external_payment`) in der Transaktion, **nachdem** Ticketarten und Termine (in dieser Reihenfolge, je nach ID) gesperrt sind und **bevor** der Kern erstmals konsistent liest. `$demand`: Ticketart-ID → Menge; `$context`: `phase` (`create`/`recover`), `order_id` (bei `recover` aus der Zählung auszunehmen, sonst 0), `types`/`occurrences` (gesperrte Zeilen nach ID), `now`, `source`. Eigene Zeilen zuerst mit sperrenden Lesezugriffen (`FOR UPDATE`/`LOCK IN SHARE MODE`, feste Reihenfolge) sperren, erst danach Belegung lesen – sonst ist der Snapshot veraltet. `WP_Error` lehnt ab (Code über `wpcap_checkout_error_messages`, sonst die Meldung des Fehlers; bei `recover` → `needs_review`). |
| `wpcap_ticket_availability` | `int|null $remaining, object $type, object $occurrence` | Restmenge in `Orders::availability()` (Anzeige, Warenkorbprüfung, Kasse). Nur verkleinern; `null` = unbegrenzt. Serien-Pools hängen sich hier ein. |
| `wpcap_service_booking_ip_limit` | `int $limit` (40) | Buchungsversuche je IP. |
| `wpcap_seating_availability_limit` | `int $limit` (120) | Abrufe der Sitzplatzverfügbarkeit je Client und Minute. |
| `wpcap_checkin_now` | `int $timestamp` | Zeitpunkt für Einlassentscheidungen (Tests/Simulation). |
| `wpcap_user_has_2fa` | `bool $has, int $user_id` | Zwei-Faktor-Status für Erstattungen. |
| `wpcap_voucher_purpose` | `string|null $purpose, array $voucher, array $context` | Art eines Gutscheins: `single` (Einzweckgutschein, USt beim Gutscheinverkauf; Einlösung = Preisnachlass) oder `multi` (Mehrzweckgutschein, Einlösung = Zahlungsmittel, USt auf den vollen Preis). `$purpose` ist die Angabe aus den WP-Voucher-Captain-Daten (oder `null`); `$voucher`: Anbieterdaten (Checkout: `quote`, `reservation`; Ticketdienst: `$source`); `$context`: `source` (`checkout`/`ticket_service`), `order_id`, `code_hint`, `ticket_type_id`, `quantity`. Rückgabe `null`/unbekannt → Einstellung `voucher_default_purpose` (Standard `multi`). Siehe docs/VOUCHERS.md. |
| `wpcap_woocommerce_hold_minutes` | `int $minutes` | Reservierung bei WooCommerce-Checkout. |
| `wpcap_woocommerce_on_hold_minutes` | `int $minutes` (3 Tage) | Reservierung für WooCommerce-Bestellungen „in Wartestellung“. |
| `wpcap_max_line_quantity` | `int $limit` (100) | Absolute Obergrenze der Menge je Bestellzeile in `Orders::create()`, geprüft vor dem Sperren (Schutz gegen absurde Mengen); `max_per_order` der Ticketart gilt zusätzlich. |
| `wpcap_client_ip` | `string $ip` (`REMOTE_ADDR`), `array $server` (`$_SERVER`) | Client-IP für Ratenbegrenzungen (`Orders::client_ip()`). Hinter einem Proxy nur dann einen Header übernehmen, wenn `$ip` der vertrauenswürdige Proxy ist; ungültige Rückgaben fallen auf `REMOTE_ADDR` zurück. |
| `wpcap_checkout_error_field` | `string $field, WP_Error $error` | Formularfeld (`data.field`), dem ein Checkout-Fehler zugeordnet wird (z. B. `email`, `campaign_code`, `voucher_code`); leer = keinem Feld. |
| `wpcap_manual_open_orders_per_email` | `int $limit` (2) | Offene, unbezahlte Überweisungsbestellungen (`manual`) je E-Mail-Adresse; darüber lehnt der Checkout mit HTTP 429 ab. |
| `wpcap_manual_orders_per_client` | `int $limit` (5) | Überweisungsbestellungen je Client-IP innerhalb der Reservierungszeit (mindestens eine Stunde). |
| `wpcap_campaign_preview_rate_limit` | `int $limit` (30) | Unverbindliche Rabattvorschauen (`Campaigns::preview()`) je Client-IP in 10 Minuten. |
| `wpcap_expire_holds_batch` | `int $limit` (200) | Bestellungen, deren Reservierungen ein Lauf von `Orders::expire_holds()` höchstens ablaufen lässt. |
| `wpcap_issue_order_lines` | `object[] $lines, object $order` | Bestellzeilen (mit Ticketart-, Termin- und Eventspalten), die bei der Ausgabe Tickets erhalten; läuft in der Ausgabe-Transaktion mit gesperrter Bestellung. Module entfernen Zeilen ohne eigenen Einlass (z. B. die Aufpreis-Bestellung eines Umtauschs). |
| `wpcap_max_holder_changes` | `int $max` (1), `int $ticket_id` | Anzahl Inhaberwechsel per Zuweisung, ab der eine Übertragung des Tickets abgelehnt wird (`wpcap_transfer_holder_changed`). |
| `wpcap_refundable_tickets` | `object[] $rows` (`ticket_id`, `ticket_type_id`), `int $order_id` | Erstattbare Tickets einer Bestellung (nicht erstattet und gültig, eingecheckt oder wegen Termin-/Eventabsage widerrufen). |
| `wpcap_order_started` | `bool $started, int $order_id, string $now` (UTC `Y-m-d H:i:s`) | Ob ein Termin der Bestellung bereits begonnen hat (`Partners\Sales::order_started()`, Stornoprüfung der Partner-API). Das Pass-Modul liefert `true`, wenn ein verknüpfter Termin eines Passes der Bestellung begonnen hat. |
| `wpcap_guestlist_max_tickets_per_entry` | `int $max` (20, 1–100) | Höchstzahl Tickets je Gästelisteneintrag (Gast plus Begleitungen). |
| `wpcap_portal_resend_limit` | `int $limit` (3) | Neuversand der Tickets einer Gutschein-Umwandlung (`TicketService::resend_tickets()`) je Bestellung und Stunde; die Codes werden dabei erneuert. |

### Zahlungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_payment_gateways` | `GatewayInterface[] $gateways` | Weitere Zahlungsadapter registrieren (z. B. Mollie). |
| `wpcap_payment_settings_url` | `string $url` (''), `string $provider_id` | Einstellungsseite eines Zahlungsadapters ohne eigenen Abschnitt im Reiter **Einstellungen → Zahlungsarten**; der Reiter verlinkt sie in seiner Unternavigation und in der Übersicht (Mollie: eigene Modulseite). |
| `wpcap_payment_return_url` | `string $url, string $status, string $public_id, string $provider` | Rückkehr-URL nach der Zahlungsseite (`success`, `pending`, `failed`, `cancel`). |
| `wpcap_stripe_session_params` | `array $params, array $order, array $items` | Parameter der Stripe Checkout Session. |
| `wpcap_paypal_order_body` | `array $body, array $order, array $items` | Body der PayPal-Order. |
| `wpcap_mollie_webhook_ip_limit` | `int $limit` (120) | Mollie-Webhook-Abfragen bekannter Zahlungen je IP und Minute (darüber HTTP 429). |
| `wpcap_mollie_webhook_global_limit` | `int $limit` (1200) | Mollie-Webhook-Abfragen insgesamt je Minute. |
| `wpcap_mollie_payment_body` | `array $body, array $order, array $items` | Body von `POST /v2/payments` (Mollie). |
| `wpcap_bank_transfer_details` | `array $details, object $order` | Überweisungsdaten im Bestellportal und in Mails (`reference`, `remittance`, `amount_minor`, `currency`, `amount`, `recipient`, `iban`, `bic`, `due_at`, `due_date`, `instructions`). |
| `wpcap_send_refund_mail` | `bool $send, int $refund_id, object $order` | Ob die Erstattungsbestätigung an den Käufer geht (Standard: ja, außer bei WooCommerce-Bestellungen oder ohne gültige E-Mail-Adresse). |

### E-Mails, Tickets, Darstellung

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_ticket_mail` | `array $mail` (`subject`, `body`, `attachments`), `object $order, array $issued` | Ticket-E-Mail anpassen. `$issued` je Ticket: `ticket_id`, `ticket_type_id`, `occurrence_id`, `token` (Klartext, nur hier), `event`, `ticket`, `starts_at`, `timezone`, `location`. `subject`/`body` sind bereits aus der (ggf. angepassten) Vorlage `tickets` gerendert; im HTML-Modus wird angehängter Klartext automatisch maskiert und umbrochen. Angehängte Dateien werden nach dem Versand gelöscht. Das Kalender-Modul hängt hier `.ics`-Dateien an (Priorität 20). |
| `wpcap_attach_ticket_pdf` | `bool $attach, int $order_id` | PDF-Tickets anhängen. |
| `wpcap_mail_types` | `array $types` | Registry der Kunden-Mails (`PassCaptain\Mail\Types`): eigene Typen ergänzen oder Labels/Standardtexte ändern (Kern-Typen können nicht entfernt werden). Siehe [MAIL-TEMPLATES.md](MAIL-TEMPLATES.md). |
| `wpcap_mail` | `array $message, string $to` | Jede Kunden-Mail des zentralen Mailers vor dem Versand (`type`, `subject`, `body`, `format`, `attachments`, `locale`, optional `headers`; bei eingebauten HTML-Texten zusätzlich `text_body`/`text_for` – die Textversion gilt nur, solange `body` unverändert bleibt). |
| `wpcap_mail_default_format` | `string $format, string $type` | Format der eingebauten Standardtexte ohne eigene Vorlage: `html` (Standard, mit Layout und Textversion) oder `text`; `$type` ist der Mail-Typ bzw. `reminder` für Erinnerungen. Gespeicherte Vorlagen behalten ihr Format. |
| `wpcap_mail_layout_html` | `string $html, string $content, string $subject` | Fertiges HTML-Dokument des E-Mail-Layouts (HTML-Vorlagen). |
| `wpcap_mail_test_recipient` | `string $email, WP_User|null $user` | Empfänger der „Testmail senden“-Funktion (Standard: Adresse der angemeldeten Person). |
| `wpcap_ticket_code_mail_section` | `string $section, int $ticket_id` | Zusatztext je Ticket in Übertragungs-, Zuweisungs- und Neuversand-Mails (z. B. Wallet-Links). |
| `wpcap_ticket_seat_label` | `string $label, int $ticket_id, array $ticket` | Sitzplatz-/Bereichsbezeichnung für Wallet-Pässe und Druckvorlagen. |
| `wpcap_email_template` | `array|null $template, string $template_key, array $payload` | Vorlage für Nachrichten aus `wpcap_message_queue`; Rückgabe `['subject' => …, 'body' => …]`. Erinnerungen nutzen den Schlüssel `wpcap_reminder`. |
| `wpcap_event_card_html` | `string $html, object $event, array $types` | HTML einer Event-Karte im Shortcode `[wpcap_events]` (alias `[wp_pass_captain_events]`) (das Kalender-Modul ergänzt den Anker `id="wpcap-occ-{Termin}"`). |
| `wpcap_currencies` | `string[] $currencies` | Wählbare Währungen. |
| `wpcap_sender_name` | `string $name` | Name in der Kundenkommunikation (Website-Titel, sonst der Hostname). |
| `wpcap_currency_symbols` | `array $symbols` (ISO-Code → Symbol) | Währungssymbole für die Anzeige; unbekannte Währungen erscheinen als ISO-Code. |
| `wpcap_order_portal_ticket_html` | `string $html` (`''`), `object $ticket, object $order, string $token` | Zusätzliches HTML je Ticket im Bestellportal (z. B. Umtausch-Formular); der Callback muss selbst escapen. |

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
| `wpcap_privacy_retention_days` | `int $days, string $step` | Fristen des täglichen Löschlaufs (`0` = Schritt aus): `unpaid_attendee_data` (1), `unpaid_customer_email` (90), `message_content` (30), `message_rows` (365), `checkin_staff` (365), `audit_logs` (730), `partner_ticket_codes` (30, Tage nach Terminende). |
| `wpcap_attendee_default_retention_days` | `int $days` (365) | Frist für Teilnehmername/-E-Mail nach Terminende, wenn die Einstellung „Teilnehmerdaten löschen nach“ 0 ist (Antworten folgen dann der Frist des Felds). |
| `wpcap_guestlist_companion_name` | `string $name, object $entry, int $index` | Name auf Begleittickets einer Gästeliste (Standard neutral „Begleitung“, z. B. `'Begleitung von ' . $entry->name`). |
| `wpcap_privacy_order_anonymized` (Action) | `int $order_id` | Eine Bestellung wurde anonymisiert (Datenschutz-Löschung); Module löschen ihre Daten zur Bestellung (z. B. gespeicherte Partner-Ticketcodes). |
| `wpcap_privacy_retention_budget` | `float $seconds` (20) | Zeitbudget eines Löschlaufs; der nächste Lauf setzt fort. |
| `wpcap_privacy_policy_recipients` | `array $recipients` | Welche Empfänger der Datenschutztext-Vorschlag nennt (`stripe`, `paypal`, `mollie`, `twilio`, `sms_http`, `apple`, `google`, `partners`, `webhooks`, `affiliate`, `woocommerce` → bool). |
| `wpcap_privacy_policy_content` | `string $html, array $recipients` | HTML des Datenschutztext-Vorschlags. |
| `wpcap_scanner_queue_max_hours` | `int $hours` (24, 1–168) | Wie lange der Web-Scanner Offline-Scans im Browser aufbewahrt. |
| `wpcap_privacy_defer_upcoming` | `bool $defer` (true), `int $order_id` | Bei einer Löschanfrage Bestellungen mit Tickets für kommende Termine zurückstellen (Anonymisierung automatisch nach dem letzten Termin) statt die Tickets zu widerrufen (Art. 17 Abs. 3 lit. b DSGVO). |
| `wpcap_privacy_retention_steps` | `array $counts, int $now, float $deadline` | Eigene Schritte des täglichen Löschlaufs anhängen (Gästelisten, Warteliste, Käuferlimits …): Anzahl je Schrittname ergänzen, `$deadline` (`microtime()`-Grenze) beachten. |

### Wallet

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_wallet_apple_pass_json` | `array $json, array $ticket` | `pass.json` des Apple-Wallet-Passes. |
| `wpcap_wallet_apple_locations` | `array $locations, array $ticket` | Orte für Sperrbildschirm-Hinweise (max. 10). |
| `wpcap_wallet_apple_images` | `array $images` | Bilder des Passes (Dateiname → Bytes). |
| `wpcap_wallet_apns_client` | `ApnsClient|null $client` | Eigener APNs-Client (Tests). |
| `wpcap_wallet_google_class` | `array $class, array $ticket` | Google-Wallet-EventTicketClass. |
| `wpcap_wallet_google_object` | `array $object, array $ticket` | Google-Wallet-EventTicketObject. |

### Administration

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_admin_order_detail` (Action) | `object $order, array $tickets` | Zusätzliche Abschnitte auf der Bestelldetailseite im Admin vor den Erstattungen (z. B. Ticketumtausch). |
| `wpcap_occurrence_sold` | `int $sold, int $occurrence_id` | Im Admin angezeigte verkaufte Einheiten eines Termins (bezahlt, nicht erstattet); Module zählen eigene Einheiten hinzu (z. B. an anderen Terminen verankerte Pässe). |
| `wpcap_event_figures` | `array $figures, int[] $event_ids` | Kennzahlen der Eventliste im Admin, je Event `sold` (bezahlt, nicht erstattet), `capacity` (`null` = unbegrenzt) und `revenue` (Währung => Minor Units); Module passen sie gesammelt an (keine Abfrage pro Zeile). |
| `wpcap_getting_started_items` | `array $items, array $state` | Schritte der „Erste Schritte“-Checkliste auf der Eventliste (`key`, `label`, `description`, `done`, `url`); `$state` aus `GettingStarted::state()`. `done` aus Daten ableiten, nicht manuell abhaken. |
| `wpcap_admin_menu_group` | `string $group, string $slug` | Bereich einer Unterseite im Pass-Captain-Menü (`events`, `sales`, `admission`, `finance`, `communication`, `settings`); Module können ihn auch als 5. Element in `admin_pages()` angeben. |
| `wpcap_occurrence_cancellation_preview` | `array $effects` (leer), `int $occurrence_id` | Weitere Folgen einer Terminabsage für die Vorschau im Admin, je Eintrag `[Name, Tickets, Wirkung]` (z. B. verknüpfte Pässe). |

### Kontingente und Käuferlimits

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_allocation_limit_exempt_sources` | `string[] $sources` (`pos`, `guestlist`, `admin`) | Bestellquellen, für die Käuferlimits nicht gelten. |
| `wpcap_allocation_buyer_limit_applies` | `bool $applies, string $source, array $selected, array $internal` | Ob das Käuferlimit für diese Bestellung gilt (Standard: nicht für ausgenommene Quellen); läuft in `wpcap_order_creating`. |
| `wpcap_allocation_normalize_gmail` | `bool $normalize` (false) | Gmail-Adressen für die Käuferidentität normalisieren (Punkte und `+Zusatz` entfernen, `googlemail.com` → `gmail.com`). |
| `wpcap_allocation_buyer_email` | `string $email` | Normalisierte E-Mail-Adresse, aus der der Käuferschlüssel für Limits gebildet wird. |
| `wpcap_allocation_trusted_sources` | `string[] $sources` (`pos`, `guestlist`, `admin`) | Quellen, die reservierte Kontingente (Holds) verbrauchen dürfen und nicht an sie gebunden sind. |
| `wpcap_allocation_hold_for_source` | `int $hold_id` (0), `string $source, array $selected, array $internal` | Hold, den eine vertrauenswürdige Quelle ohne `allocation_id` verbraucht (z. B. Gästeliste → „Künstler“); 0 = keiner. |
| `wpcap_allocation_released` (Action) | `int $hold_id, int $units, string $reason` | Nach dem Commit von `Holds::release()`: Ein Hold hat Einheiten an den freien Verkauf zurückgegeben (`$reason` z. B. `manual`, `auto`). |

### Kapazitätszähler und Verfügbarkeit

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_capacity_counters_enabled` | `bool $enabled` (true) | Ob Kontingententscheidungen und Verfügbarkeit die Zählerspalten nutzen (nur im Zustand `ready`); `false` = SUM-Abfragen. |
| `wpcap_capacity_reconcile_budget` | `float $seconds` (10.0) | Zeitbudget eines Abgleichlaufs per Cron (`wpcap_reconcile_capacity`); `--all` per WP-CLI nutzt 3600 s. |
| `wpcap_capacity_reconcile_confirm_us` | `int $microseconds` (300000) | Wartezeit, nach der abweichende Bestellungen erneut geprüft werden, bevor sie als Drift gelten (laufende Schreibvorgänge). |
| `wpcap_capacity_verify_after` | `int $seconds` (600) | Dauer ohne Drift im Zustand `verifying`, nach der die Zähler auf `ready` wechseln. |
| `wpcap_capacity_drift` (Action) | `array $report` (`resynced_orders`, `resynced_rows`, `repaired_occurrences`, `orders`, `occurrences`) | Zähler wichen von Bestellungen/Holds ab und wurden korrigiert (ein Ablauf änderte Daten ohne `CapacityCounters::sync_order()`). |
| `wpcap_capacity_drift_alert` | `bool $send` (true), `array $report` | Ob bei Drift eine E-Mail an die Admin-Adresse geht (höchstens einmal pro Stunde). |
| `wpcap_availability_cache_ttl` | `int $seconds` (10, 0–60) | Objekt-Cache-Dauer der Verfügbarkeit in `Orders::availability()`; 0 = kein Cache. |
| `wpcap_availability_flushed` (Action) | – | Der Verfügbarkeits-Memo des Requests wurde geleert (nach Schreibzugriffen auf Plugin-Tabellen oder Zähleränderungen); Module leeren ihre Request-Caches (Holds, Pässe, Sitzplätze, Limits). |

### Rechnungsdaten

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_billing_countries` | `array $countries` (ISO-Code → Name) | Auswählbare Länder im Rechnungsformular (leere Rückgabe → AT/DE). |
| `wpcap_billing_default_country` | `string $country` | Vorausgewähltes Land (Standard aus UID des Verkäufers, WooCommerce-Shopland oder Locale, sonst `DE`); muss in der Länderliste stehen. |
| `wpcap_vat_id_patterns` | `array $patterns` (Präfix → Regex) | Formatprüfung von UID-Nummern je Länderpräfix. |
| `wpcap_vat_id_validate` | `true|WP_Error $valid, string $vat_id, array $billing` | Optionale Online-Prüfung der UID (z. B. VIES); `true` akzeptiert, `false` oder `WP_Error` (Meldung wird angezeigt) lehnt ab. Standard: keine externe Abfrage. |
| `wpcap_billing_required_threshold` | `int $minor` (Einstellung `billing_required_threshold_minor`), `string $currency` | Bestellsumme, ab der im öffentlichen Checkout Rechnungsdaten Pflicht sind (0 = aus); je Währung überschreibbar. |
| `wpcap_invoice_items` | `object[] $items, object $order` | Rechnungszeilen einer Bestellung (Zeilen mit `name`, `event_name`, `starts_at`, `timezone`), z. B. Umtauschgebühren ergänzen. |

### WooCommerce

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_woocommerce_collect_attendees` | `bool $collect, int $type_id` | Ob der WooCommerce-Checkout Teilnehmerdaten je Ticket abfragt (Standard: nur wenn Teilnehmerfelder konfiguriert sind). |
| `wpcap_woocommerce_vat_meta_keys` | `string[] $keys, WC_Order $wc_order` | Bestell-Metaschlüssel, aus denen die UID-Nummer gelesen wird (erster nicht leerer Wert). |
| `wpcap_woocommerce_billing` | `array $billing, WC_Order $wc_order` | Aus der WooCommerce-Bestellung übernommene Rechnungsdaten (`company`, `first_name`, `last_name`, `street`, `postcode`, `city`, `country`, `vat_id`, `po_number`). |
| `wpcap_woocommerce_hold_fulfilment` | `bool $hold` (false), `WC_Order $wc_order` | Ticketausgabe einer bezahlten Bestellung zurückhalten (z. B. Voucher Captain bei nicht belastbarer Geschenkkarte); die Reservierung bleibt, Tickets folgen nach erneutem Statuswechsel. |
| `wpcap_woocommerce_deferred_hold_minutes` | `int $minutes` (14 Tage), `WC_Order $wc_order` | Verlängerte Reservierung, solange die Ausgabe zurückgehalten oder bis „abgeschlossen“ verschoben wird. |
| `wpcap_woocommerce_issue_on_completed_gateways` | `string[] $gateways` (`cod`), `WC_Order $wc_order` | Zahlungsarten, bei denen Tickets erst im Status „abgeschlossen“ ausgegeben werden („in Bearbeitung“ heißt dort nicht bezahlt). |

### Umtausch

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_exchange_money` | `array $money, object $ticket, object $target_type, array $args` | Beträge eines Umtauschs vor dem Speichern (`old_value_minor`, `new_price_minor`, `difference_minor`, `fee_minor`, `refund_minor`, `due_minor`, `supplement_minor`, `new_value_minor`). |
| `wpcap_exchange_portal_rate_limit` | `int $limit` (10) | Umtauschanfragen im Bestellportal je Bestellung und Stunde. |
| `wpcap_exchange_refund_fee` | `bool $refund_fee` (false), `array $exchange` | Bei Erstattung des umgetauschten Tickets auch die Umtauschgebühr aus der Aufpreis-Bestellung erstatten (`true`); Standard: Gebühr wird einbehalten. |
| `wpcap_exchange_refund_external` (Action) | `int $exchange_id, int $order_id, int $amount_minor, string $currency` | Die Differenz eines Umtauschs muss außerhalb von Pass Captain erstattet werden (WooCommerce-Bestellung); der WooCommerce-Adapter schreibt dazu eine Notiz an die Woo-Bestellung. |
| `wpcap_exchange_platform_collected` | `bool $collected, object $order` | Ob die Plattform die Zahlung der Bestellung selbst erhalten hat (Standard: nicht bei `partner`, `woocommerce`, `comp` und `pos_*`); ohne das lehnt der Umtausch im Bestellportal Rückzahlungen ab (`refund_offline`). |
| `wpcap_ticket_exchanged` (Action) | `int $old_ticket_id, int $new_ticket_id, int $exchange_id, array $exchange` | Ticket umgetauscht: altes Ticket widerrufen (Grund `exchange`), neues in derselben Bestellung ausgegeben. |

### Einlass und Offline-Scanner

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_ticket_valid_for_occurrence` | `array $found, array $context, int $at` | Ticket an einen anderen Termin als den seiner Ticketart binden (Mehrtages-/Festivalpässe). Rückgabe `$found` mit `occurrence_id`, `event_id`, `event_name`, `starts_at`, `ends_at`, `timezone`, `occurrence_status`, `event_status`; `$context`: `event_ids`, `occurrence_id`, `area_id`. |
| `wpcap_admission_policy` | `array|null $policy, int $ticket_type_id` | Wirksame Einlassregel einer Ticketart (`null` = einmaliger Einlass); eigene `policy`-Werte (z. B. `pass`) über `wpcap_admission_evaluate` auswerten, alle Schlüssel von `normalize()` angeben. |
| `wpcap_admission_evaluate` | `string|null $result` (null), `array $policy, array $found, object|null $state, int $at` | Eigene Einlassentscheidung unter der Ticket-Zeilensperre: Sperrcode oder `''` (einlassen); `null` überlässt die Entscheidung den eingebauten Regeln. |
| `wpcap_admission_day_cutoff_hour` | `int $hour` (Einstellung `admission_day_cutoff_hour`), `array $context` (`occurrence_id`, `event_id`) | Stunde (0–12), bis zu der ein Einlass noch zum Vortag zählt (Tages- und Mehrtagestickets). |
| `wpcap_ticket_allowed_areas` | `int[] $area_ids, array $found` | Bereiche, in die ein Ticket eingelassen wird (leer = alle); z. B. Bereiche des gescannten Events bei Mehrtagespässen. |
| `wpcap_admission_area_capacity` | `int $capacity` (Einstellung am Bereich, 0 = unbegrenzt), `int $area_id, array $found` | Kapazität eines Einlassbereichs (Personen gleichzeitig drinnen) für einen Scan; bei Erreichen lehnt der Check-in mit `area_full` ab. |
| `wpcap_help_code_rate_limits` | `array $limits` (`user` 30, `client` 60, `site` 600, `window` 600 Sekunden) | Ratenbegrenzung der Hilfscode-Eingaben (`HelpCode::resolve()`) je WordPress-Benutzer bzw. Scannergerät-Benutzer, je Client-IP und je Website im Zeitfenster; jede Eingabe zählt, auch Treffer. Darüber HTTP 429 (`wpcap_help_code_rate_limited`, `result: rate_limited`). Siehe [SECURITY.md](SECURITY.md#hilfscode-für-den-einlass). |
| `wpcap_offline_refresh_interval` | `int $seconds` (10) | Mindestabstand zweier Snapshot-Aktualisierungen eines Termins für den Offline-Scanner. |
| `wpcap_offline_live_tickets` | `object[] $live, int $occurrence_id` | Ticketzeilen des Offline-Pakets (`ticket_id`, `token_hash`, `status`, `ticket_type_id`, `attendee_name`, `occurrence_status`, `event_status`); Module ergänzen hier gültige Tickets anderer Termine oder entfernen Zeilen. |
| `wpcap_offline_package_types` | `array $types, object $occurrence` | Ticketart-Beschreibungen des Offline-Pakets (`id`, `name`, `areas`, `policy`, `multi`) für zusätzlich ausgelieferte Tickets. |

### Kasse

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_boxoffice_denominations` | `int[] $list, string $currency` | Scheine und Münzen (Minor-Einheiten) für die Bargeldzählung. |
| `wpcap_boxoffice_fiscal_ready` | `bool $ready` (false), `string $country` | `true`, wenn eine Fiskal-Integration die Belege signiert; sonst sind Bar- und Kartenverkäufe in DE/AT gesperrt, solange der Betreiber keine eigene zertifizierte Kasse bestätigt hat. |
| `wpcap_boxoffice_fiscal_docs_url` | `string $url` | Linkziel des Hinweises zur Registrierkassenpflicht. |
| `wpcap_boxoffice_can_close_shift` | `true|WP_Error $veto, object $shift, object $count` | Schichtabschluss verhindern (`WP_Error`); `$count` ist die Schlusszählung. |
| `wpcap_boxoffice_shift_opened` (Action) | `int $shift_id, int $register_id` | Nach dem Commit: Schicht mit gezähltem Wechselgeld eröffnet. |
| `wpcap_boxoffice_shift_closed` (Action) | `int $z_id, object $z, object $shift` | Schicht geschlossen und Z-Bericht geschrieben (nur einfügen, Hash-Kette); `$z->totals_data` enthält die dekodierten Summen – z. B. zum Signieren/Exportieren. |
| `wpcap_boxoffice_handover_created` (Action) | `int $handover_id` | Beim Schichtabschluss wurde eine Bargeldübergabe an die nächste Kassierperson angelegt. |
| `wpcap_boxoffice_handover_accepted` (Action) | `int $handover_id` | Die Übergabe wurde beim Eröffnen der nächsten Schicht angenommen. |
| `wpcap_boxoffice_cash_movement` (Action) | `int $movement_id, object $movement` | Kassenbewegung ohne Erstattung gebucht (Einlage, Entnahme, Korrektur). |
| `wpcap_boxoffice_refund_booked` (Action) | `int $movement_id, int $refund_id, object $movement` | Auszahlung einer Erstattung/Stornierung an der Kasse gebucht. |
| `wpcap_boxoffice_zreport_html_extra` | `string $html` (`''`), `object $z` | Zusätzliches HTML im Z-Bericht (z. B. Signaturdaten); der Callback muss selbst escapen. |

### Partner, Verkaufsmodelle und Berichte

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_partner_client_ip` | `string $ip` (`REMOTE_ADDR`) | Client-Adresse für Partner-API-Schlüssel (Proxys hier auflösen). |
| `wpcap_refuse_mixed_seller_orders` | `bool $refuse` (true), `int $order_id` | Bestellungen ablehnen, die Rechnungen verschiedener Verkäufer bräuchten (Prüfung in `wpcap_order_creating`, Abbruchcode `mixed_sellers`). |
| `wpcap_reseller_statement_document` | `string $document` (`reseller_invoice`), `object $partner` | Beleg der Vergütung in der Reseller-Abrechnung: `reseller_invoice` (Reseller stellt Rechnung) oder `self_billing` (Gutschrift der Plattform). |
| `wpcap_report_channel` | `string $channel, string $provider` | Verkaufskanal einer Bestellung im Umsatzbericht (`pos`, `partner`, `woocommerce`, `online`) anhand des Zahlungsanbieters; unbekannte Werte zählen als `online`. |
| `wpcap_comp_payment_providers` | `string[] $providers` (`comp`, `pos_free`) | Zahlungsanbieter von Freikarten; deren Tickets zählen in Berichten nicht als Verkauf. |

### Versionierte Verträge und Infrastruktur

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_modules` | `string[] $classes` | Liste der Modulklassen (`src/Modules`). |
| `wp_pass_captain_connectors_v1` | `ConnectorInterface[] $connectors` | Connectoren registrieren. |
| `wpcap_process_job` | `bool $handled, string $job_type, array $payload, int $job_id` | Hintergrundjobs aus `wpcap_jobs` verarbeiten (`true` = erledigt). |
| `wp_pass_captain_voucher_provider_v1` | `ProviderInterface|null $provider, int $version` | Gutschein-Anbieter. `commit()`-Fehler mit Status ≥ 500 oder Code `vcap_temporary_failure` werden über den Job `voucher_commit` wiederholt, alle anderen markieren die bezahlte Bestellung zur Prüfung. Das Angebot (`quote()`) kann `treatment` liefern: `payment` (Mehrzweckgutschein – USt bleibt, Gutschein erscheint als Zahlung auf der Rechnung, Gutschriften umfassen den Gutscheinanteil), `single` (Einzweckgutschein – USt bereits beim Verkauf abgeführt) oder `discount` (Standard – Preisnachlass). |
| `wp_pass_captain_ticket_service_v1` | `TicketService|null $service, int $version` | Dienst für die Rückrichtung: ein eingelöster Gutschein wird in Tickets umgewandelt (`issue_tickets`, `revoke_tickets`, `get`, `targets`, `options`, `events`); Ziel ist eine feste Ticketart oder eine Veranstaltung mit frei wählbarem Termin. Idempotent pro Schlüssel; Fehler tragen `retryable`. |
| `wp_pass_captain_checkin_service_v1` | `CheckinService|null $service, int $version` | Check-in-Dienst für einen fremden Scanner (gemeinsamer Scanner): `recognizes`, `preview`, `check_in`; prüft das Recht `wpcap_checkin`. |
| `wp_pass_captain_wallet_provider_v1` | `ProviderInterface|null $provider, string $provider_key, int $version` | Wallet-Anbieter (Bridge). |
| `wpcap_module_enabled` | `bool $enabled, string $short_name, string $class` | Ob ein Modul (`Sms`, `Wallet`, …) seine Hooks, Admin-Seiten und Admin-Aktionen registriert (Standard `true`; alternativ Konstante `WPCAP_DISABLED_MODULES` als Komma-Liste). Tabellen und Einstellungen bleiben erhalten. |
| `wpcap_cron_time_budget` | `float $seconds` (20) | Zeitbudget der Cron-Läufe `wpcap_process_messages`, `wpcap_process_jobs`, `wpcap_webhooks_dispatch` und `wpcap_waitlist_process` (begrenzt auf `max_execution_time` − 15 s, mindestens 1 s); Rückstände laufen sofort weiter. |
| `wpcap_message_batch_size` | `int $size` (500) | Nachrichten aus der Queue je Lauf von `wpcap_process_messages`. |
| `wpcap_waitlist_process_delay` | `int $seconds` (60) | Verzögerung, bevor freigewordene Plätze der Warteliste angeboten werden (mehrere Freigaben in einem Lauf). |

## Cron-Hooks

WP-Cron-Ereignisse, die das Plugin selbst plant. Sie werden nicht per `do_action()` im Code ausgelöst,
sondern von WP-Cron; Module hängen sich mit `add_action()` an.

| Hook | Planung | Zweck / angehängte Callbacks |
|---|---|---|
| `wpcap_cleanup_reservations` | stündlich (`Plugin::schedule_events()`) | Abgelaufene Reservierungen (`Orders::expire_holds`), zurückgestellte Datenschutz-Löschungen (30), hängende Erstattungen (40); Module: Sitzplatz-Reservierungen (20), automatische Hold-Freigabe und Wartelisten-Bereinigung (30). |
| `wpcap_process_messages` | alle 5 Minuten (`wpcap_five_minutes`) | Nachrichten-Queue (`Communications::process_due`); Erinnerungen planen sich mit Priorität 1 ein, Bereinigung mit 5, SMS-Versand mit 20. |
| `wpcap_process_jobs` | alle 5 Minuten (`wpcap_five_minutes`) | Hintergrundjobs (`Jobs::process_due`, Tabelle `wpcap_jobs`); Webhook-Zustellung und Warteliste mit Priorität 20, Webhook-Bereinigung mit 25. |
| `wpcap_webhooks_dispatch` | einmalig 5 s nach neuen Webhook-Ereignissen (`Webhooks\Dispatcher`) | Sofortige Zustellung ausgehender Webhooks. |
| `wpcap_reconcile_capacity` | alle 5 Minuten (`CapacityCounters::schedule()`) | Abgleich der Kapazitätszähler mit Bestellungen und Holds (siehe Kapazitätszähler). |
| `wpcap_run_upgrade` | einmalig, wenn im Frontend ein Schema-Update ansteht (`Installer::UPGRADE_HOOK`) | Verzögertes Datenbank-Upgrade (`Plugin::run_deferred_upgrade`). |
| `wpcap_privacy_retention` | täglich (`Privacy\Retention::schedule()`) | Löschlauf mit den Fristen aus `wpcap_privacy_retention_days`. |
| `wpcap_privacy_purge_wallet_registrations` | einmalig eine Stunde nach einer Datenschutz-Löschung, Argument: Ticket-IDs | Apple-Wallet-Geräteregistrierungen löschen, nachdem der Widerrufs-Push zugestellt wurde. |
| `wpcap_waitlist_process` | einmalig zum Fälligkeitszeitpunkt (`Waitlists::queue()`, Verzögerung `wpcap_waitlist_process_delay`) | Freigewordene Plätze der Warteliste anbieten. |

Laufen nach Ende des Zeitbudgets noch Einträge auf, plant `Jobs\TimeBudget::continue_later()` einen sofortigen
Folgelauf desselben Hooks (Action Scheduler, sonst WP-Cron mit Argument `continue`).
