# Entwickler-Hooks: öffentliche Add-on-API und interne Hooks

Alle `do_action()`- und `apply_filters()`-Aufrufe in `src/` (Stand 2026-11-16), mit Argumenten.

**Öffentlich oder intern (A663):** Nur die Hooks im Abschnitt
[Öffentliche Add-on-API](#öffentliche-add-on-api) sind ein versionierter Vertrag, auf den sich das
Premium-Add-on `pass-captain-pro` und andere Plugins verlassen dürfen. Alle übrigen Hooks dieser Datei sind
**interne** Erweiterungspunkte: stabil gemeint, aber sie können sich in einer Minor-Version ändern (Argumente,
Zeitpunkt) und werden ohne Übergangsfrist umbenannt. Externe Systeme sollten statt PHP-Hooks die signierten
[Webhooks](WEBHOOKS.md) oder den Workflow-Providervertrag ([CONTRACTS.md](CONTRACTS.md)) verwenden.
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
| `wp_pass_captain_event_card_html` | `wpcap_event_card_html` |
| `wp_pass_captain_currencies` | `wpcap_currencies` |

Shortcodes gibt es ebenfalls mit Präfix `wpcap_`: `[wpcap_events]`, `[wpcap_scanner]`,
`[wpcap_calendar]`, `[wpcap_passes]`; die bisherigen `[wp_pass_captain_events]`,
`[wp_pass_captain_scanner]` und `[wp_pass_captain_calendar]` bleiben gültig und werden seit 1.5.0 bei aktivem
`WP_DEBUG` als veraltet gemeldet (A303, `PassCaptain\LegacyNames`). Blöcke: `pass-captain/events`,
`pass-captain/calendar`, `pass-captain/service-booking` (frühere Namen `wp-pass-captain/*` als Aliase, siehe RENAME.md). Externe Systeme sollten statt PHP-Hooks die signierten
[ausgehenden Webhooks](WEBHOOKS.md) verwenden.

Lesende Funktionen ohne Hook (Lesefassade `wpcap_get_order_state()`, `wpcap_get_ticket_state()`,
`wpcap_get_refund_state()`, `wpcap_get_occurrence_state()`, `wpcap_get_event_state()`, seit 1.5.0 mit Vertrag 1.3.0)
stehen mit ihren Rückgabeschlüsseln in [EXTENSIONS.md](EXTENSIONS.md#lesefassade-für-zustände); sie liefern den
Zustand zu den IDs, die die Actions unten übergeben.

Neue Hooks bitte hier ergänzen. Prüfen lässt sich die Liste mit
`grep -rnE "(do_action|apply_filters)\(" src --include=*.php`. Für die versionierten `_v1`-Hooks prüft der
Vertragstest `tests/integration/modules/hooks_contract.php`, dass jeder hier dokumentierte Hook im Code
vorkommt und umgekehrt; für alle Hooks mit Präfix `wpcap_`/`wp_pass_captain_` (auch die nicht versionierten)
prüft er zusätzlich per Tokenizer, dass sie hier stehen und dass die Zahl der Argumente in der Spalte
„Argumente“ zu den Aufrufstellen passt (keine Aufrufstelle übergibt mehr; die längste genau so viele). Öffentliche
Hooks mit Präfix `wp_pass_captain_` tragen immer das Suffix `_v1` (außer den veralteten Namen unten).

Alle `do_action()`- und `apply_filters()`-Aufrufe in `src/` und `templates/` (Stand 2026-11-14), mit Argumenten.
Welche davon ein Add-on als Andockpunkt nutzt (Admin-Navigation, Einstellungen, Zahlungs-, SMS-, Wallet- und
Scanner-Adapter, Connectoren), beschreibt [EXTENSIONS.md](EXTENSIONS.md).
Hooks mit Suffix `_v1` sind versionierte Verträge für Drittanbieter; die übrigen sind stabile interne
Erweiterungspunkte. Externe Systeme sollten statt PHP-Hooks die signierten
[ausgehenden Webhooks](WEBHOOKS.md) verwenden.

## Öffentliche Add-on-API

Vertrag `addon`, Version **1.0.0** (`PassCaptain\PublicApi::VERSION`, Tabelle in [CONTRACTS.md](CONTRACTS.md)).
Versionsregel: Eine Minor-Version fügt nur Hooks oder Argumente am Ende hinzu. Innerhalb einer Hauptversion
wird ein öffentlicher Hook nie entfernt, umbenannt oder mit anderen Argumenttypen aufgerufen; ein Hook, der
wegfallen soll, bleibt mindestens eine Minor-Version lang als veraltet bestehen (`apply_filters_deprecated`,
Hinweis bei `WP_DEBUG`) und verschwindet erst mit der nächsten Hauptversion – angekündigt in `CHANGELOG.md`.

**Einstieg:** Das Add-on hängt sich beim Laden seiner Hauptdatei an `wpcap_loaded` (bzw. prüft
`did_action( 'wpcap_loaded' )`) und vergleicht die übergebene Version mit
`PassCaptain\PublicApi::compatible( '1.0' )`. Ist der Kern nicht aktiv oder die Hauptversion anders, steigt
das Add-on mit einem Admin-Hinweis aus. Der Kern selbst kennt das Add-on nicht, prüft keine Lizenzen und
funktioniert ohne es vollständig ([FREE-PREMIUM.md](FREE-PREMIUM.md)).

**Zahlungs- und Scanner-Adapter** docken über die bestehenden Verträge an: Zahlungsarten über
`wpcap_payment_gateways` (`PassCaptain\Payments\GatewayInterface`), fremde Scanner über den Check-in-Dienst
`wp_pass_captain_checkin_service_v1` und die Einlass-Ereignisse `wpcap_ticket_checked_in`/`_checked_out`.

`tests/consistency.php` gleicht diese Tabelle mit `PublicApi::HOOKS` und dem Code ab: Jeder Eintrag muss in
`src/` ausgelöst werden und hier stehen, und jede Zeile hier muss in der Liste stehen.

| Hook | Typ | Seit | Bereich | Zweck (Details in den Tabellen unten) |
|---|---|---|---|---|
| `wpcap_loaded` | Action | 1.0 | Start | Kern ist geladen (Ende von `Plugin::run()`, auf `plugins_loaded`). Argument: `string $api_version` (`PublicApi::VERSION`). Feuert einmal je Request. |
| `wpcap_admin_pages` | Filter | 1.0 | Admin | Eigene Seiten im Pass-Captain-Menü: Liste von `['slug', 'title', 'capability', 'callback', 'area']` (`area` aus `Admin\Menu::groups()`, Standard `settings`). Slugs von Kern- und Modulseiten werden nicht überschrieben, ungültige Einträge ignoriert. |
| `wpcap_settings_tabs` | Filter | 1.0 | Einstellungen | Eigene Tabs unter **Einstellungen**: `id => ['title', 'render' => callable( string $tab ), 'capability']` (Standard `manage_options`). Der Tab rendert eigenen Inhalt und ein eigenes Formular (eigener Nonce, eigene Option); das Kernformular speichert ihn nie. Kern-Tabs können nicht ersetzt werden. |
| `wpcap_checkout_validate` | Filter | 1.0 | Checkout | Eigene Checkout-Regeln vor der Bestell-Transaktion (`WP_Error` lehnt ab). |
| `wpcap_order_created` | Action | 1.0 | Bestellungen | Neue Bestellung nach dem Commit. |
| `wpcap_order_paid` | Action | 1.0 | Bestellungen | Zahlung bestätigt (vor der Ticketausgabe). |
| `wpcap_order_cancelled` | Action | 1.0 | Bestellungen | Bestellung storniert/abgelaufen. |
| `wpcap_order_refunded` | Action | 1.0 | Bestellungen | Bestellung vollständig erstattet. |
| `wpcap_refund_completed` | Action | 1.0 | Bestellungen | Erstattung (auch Teilerstattung) ausgeführt. |
| `wpcap_refund_reversed` | Action | 1.0 | Bestellungen | Verbuchte Erstattung beim Anbieter gescheitert und storniert. |
| `wpcap_tickets_issued` | Action | 1.0 | Tickets | Tickets einer Bestellung ausgestellt (nach dem Commit). |
| `wpcap_ticket_revoked` | Action | 1.0 | Tickets | Ticket widerrufen. |
| `wpcap_ticket_transferred` | Action | 1.0 | Tickets | Ticket an eine andere Person übertragen (Portal oder Inhaberwechsel). |
| `wpcap_event_cancelled` | Action | 1.0 | Tickets | Ganzes Event abgesagt. |
| `wpcap_ticket_checked_in` | Action | 1.0 | Scanner | Einlass erfolgreich. |
| `wpcap_ticket_checked_out` | Action | 1.0 | Scanner | Auslass. |
| `wpcap_occurrence_changed` | Action | 1.0 | Tickets | Kalenderrelevante Angabe eines Termins geändert. |
| `wpcap_occurrence_cancelled` | Action | 1.0 | Tickets | Termin abgesagt. |
| `wpcap_ticket_pdf_data` | Filter | 1.0 | Tickets | Anzeigedaten des PDF-Tickets (Code fest). |
| `wpcap_mail_recipients` | Filter | 1.0 | E-Mail | Empfänger (to/cc/bcc) einer Kunden-Mail. |
| `wpcap_rest_prepare_event` | Filter | 1.0 | REST | Zusatzfelder je Event in `GET /events` (nur additiv). |
| `wpcap_rest_prepare_order` | Filter | 1.0 | REST | Zusatzfelder in `GET /orders/{id}` (nur additiv). |
| `wpcap_rest_prepare_developer_event` | Filter | 1.0 | REST | Zusatzfelder in `GET /developer/events/{id}` (nur additiv). |
| `wpcap_report_data` | Filter | 1.0 | Berichte | Eigene Kennzahlen im Bericht der Berichtsseite (`$report, $filters, $scope`); nur neue Schlüssel werden übernommen. |
| `wpcap_reports_render` | Action | 1.0 | Berichte | Eigene Abschnitte am Ende der Berichtsseite (`$report, $filters, $scope`); der Callback escaped selbst und prüft eigene Rechte. |
| `wpcap_export_formats` | Filter | 1.0 | Exporte | Weitere Formate des Buchhaltungsexports: `id => ['label', 'rows' => callable( $from, $to ): array[]]` (erste Zeile = Kopf). Schaltfläche auf der Berichtsseite; Download als UTF-8-CSV (Semikolon) mit derselben Berechtigung, Nonce und Audit-Eintrag (`accounting_exported`, `format` = id) wie der eingebaute Export; Formelzeichen am Zellanfang werden entschärft. `csv` und `datev` sind reserviert. |
| `wpcap_payment_gateways` | Filter | 1.0 | Zahlungen | Zahlungsadapter registrieren (`GatewayInterface`). |
| `wp_pass_captain_checkin_service_v1` | Filter | 1.0 | Scanner | Check-in-Dienst für fremde Scanner ([VOUCHERS.md](VOUCHERS.md)). |

Nicht enthalten ist ein Gebühren-Filter `wpcap_order_fees` (A331): Bestellgebühren brauchen ein eigenes
Datenmodell (eigene Rechnungszeile, USt-Behandlung, Erstattung, Preisangabe im Checkout) – das ist eine offene
Produktentscheidung.

## Actions

| Hook | Argumente | Ort / Zeitpunkt |
|---|---|---|
| `wpcap_order_creating` | `int $order_id, array $selected, array $payload, array $internal` | `Orders::create()` innerhalb der Bestell-Transaktion (Ticketarten/Termine gesperrt). `\RuntimeException('code')` bricht ab; Meldung über `wpcap_checkout_error_messages`. `$selected`: Zeilen mit `type`, `event_id`, `quantity`, `line_total`, `discount_minor` (Kampagnenrabatt der Zeile), `tax_rate_bp`, `tax_minor`; `$payload`: öffentliche Checkout-Felder (siehe `wpcap_checkout_payload_keys`); `$internal`: vertrauenswürdige Adapter-Optionen (`payment_provider`, `external`, `fiscal_location_id`, `fiscal_register_id`, `fiscal_payment_method`, `fiscal_extra_items`, …). |
| `wpcap_capacity_released` | `int $order_id, string $reason, array $details` | Kontingent einer Bestellung zählt nicht mehr: `expired` (`expire_holds`), `cancelled` (`cancel_order`), `refunded` (`mark_refunded`), `tickets_revoked` (`revoke_tickets` und Admin-Widerruf mit Platzfreigabe, `$details`: `ticket_type_id`, `quantity`, `reason`), `partial_refund` (Teilerstattung, nativ oder extern, `$details`: `ticket_type_id`, `quantity`, `refund_id`), `exchange` (Umtausch, `$details`: `ticket_type_id`, `quantity`, `exchange_id`), `event_cancelled`, `payment_unallocated` (verspätete Zahlung ohne Kontingent), `checkout_aborted` (A183: die Datenbankverbindung riss während des Checkouts ab, die danach ohne Transaktion geschriebenen Zeilen der Bestellung wurden wieder entfernt – die Bestellung existiert nicht mehr). Kern-Kontingente und Serien-Pools werden aus Bestellungen/Reservierungen abgeleitet und brauchen den Hook nicht; er ist für Module mit eigenen Zählern oder Caches. **Teilfreigabe** (A578/A579): Nennen `$details` `ticket_type_id` und `quantity`, wurden nur diese Plätze frei – einmal je Ticketart, nach dem Commit, über `CapacityRelease::announce()`; ohne diese Angaben zählt die ganze Bestellung nicht mehr. Jedes Ticket gibt seinen Platz höchstens einmal frei (`wpcap_tickets.capacity_released`), der Hook meldet also nie dieselbe Einheit zweimal. |
| `wpcap_capacity_increased` | `int $ticket_type_id, int $occurrence_id` | Von Modulen/Admin auszulösen, wenn Kontingent erhöht oder freigegeben wurde (eines der Argumente darf 0 sein). Die Warteliste bietet die neuen Plätze dann automatisch an (siehe [WAITLIST.md](WAITLIST.md)). |
| `wpcap_listing_occurrences` | `int[] $occurrence_ids, int[] $event_ids` | Shortcode/Block `[wpcap_events]`, sobald die Termine der Liste feststehen (vor den Karten). Module laden ihre Daten genau für diese Termine/Events in einer Abfrage vor (Saalplan-Verknüpfungen `Seating\Layouts::prime_links()`, Käuferlimits `Allocations\BuyerLimits::prime()`) statt je Karte oder für die ganze Historie (A376). |
| `wpcap_waitlist_offered` | `int $waitlist_id, int $order_id` | Nach einem Wartelistenangebot samt Reservierung (Platzhalter-Bestellung mit `payment_provider` `waitlist_offer`) und Versand der Angebotsmail. |
| `wpcap_waitlist_blocked` | `int $waitlist_id, string $reason` | Ein Wartelisteneintrag konnte nicht angeboten werden und ist jetzt `blocked` (`$reason` `buyer_limit`, `quantity_limit`, `attendee_data`); die Schlange läuft mit dem nächsten Eintrag weiter (A563). |
| `wpcap_waitlist_confirmed` | `int $waitlist_id` | A564: Eine Wartelisten-Eintragung wurde über den Link der Bestätigungsmail bestätigt (Double-Opt-in); der Eintrag ist jetzt `waiting`, `confirmed_at` gesetzt (siehe [WAITLIST.md](WAITLIST.md)). |
| `wpcap_privacy_attendees_cleared` | `int[] $ticket_ids` | A566: Der Aufbewahrungslauf hat Name/E-Mail der Teilnehmenden dieser Tickets geleert. Module, die die Angaben anderswo hinterlegt haben, entfernen sie dort (das Wallet-Modul leert den Namen in gespeicherten Google-Wallet-Objekten). |
| `wpcap_waitlist_unsubscribed` | `int $waitlist_id, string $previous, string $source` | Nachdem sich jemand selbst von der Warteliste ausgetragen hat (`$source` `link` = persönlicher Link aus der Wartelisten-Mail, `portal` = Bestellportal). Der Eintrag ist `removed`, die E-Mail-Adresse gelöscht; `$previous` `offered` heißt, ein offenes Angebot wurde zurückgezogen und die Reservierung freigegeben (siehe [WAITLIST.md](WAITLIST.md)). |
| `wpcap_mail_sender` | `array $sender` | Absender von Käufer-Mails (A296): `name` (Anzeigename, Standard Einstellung „Absendername“ → Rechnungssteller → Website-Name) und `reply_to` (Standard „Antwort an“ → Administrator-E-Mail; ungültige Adressen werden verworfen). |
| `wpcap_mail_before_send` | `array $prepared, array $message` | Unmittelbar vor `wp_mail()` jeder Kunden-Mail des zentralen Mailers; `$prepared`: `to`, `subject`, `body` (inkl. Layout), `headers`, `attachments`, `alt_body` (Textversion bei HTML-Mails, sonst `null`). |
| `wpcap_mail_suppressed` | `string $to, string $type` | `Mailer::deliver()`: Kunden-Mail an eine Platzhalteradresse (Kasse, Gästeliste oder Partnerverkauf ohne Käuferadresse) wird nicht versendet; `$type` ist der Mail-Typ. |
| `wpcap_before_payment_confirm` | `int $order_id` | Zu Beginn von `Orders::confirm_external_payment()`, **außerhalb** jeder Transaktion und bei jeder (auch wiederholten) Bestätigung. Module bereiten vor, was die Prüfung einer späten Zahlung braucht und nicht unter Zeilensperren geht – z. B. reserviert Voucher Captain einen beim Ablauf freigegebenen Gutschein erneut (A632). Handler müssen idempotent sein und den Bestellstatus selbst prüfen. |
| `wpcap_order_created` | `int $order_id, array $context` | A331: Neue Bestellung **nach dem Commit** (einmal je Bestellung, nie für einen wiederverwendeten Idempotenzschlüssel), vor der Zahlung einer kostenlosen Bestellung und vor jeder Mail. `$context`: `source` (`native`, `woocommerce`, `pos`, …), `external` (Kanal mit eigener Zahlung), `pending_payment`, `total_minor`, `currency`. Für Folgeaktionen außerhalb der Transaktion (anders als `wpcap_order_creating`, das abbrechen kann). Handler dürfen keine Ausnahme werfen. |
| `wpcap_order_paid` | `int $order_id` | **Nach dem Commit** der Zahlungsbestätigung (auch bei kostenlosen Bestellungen), immer **vor** der Ticketausgabe; wer die Tickets braucht, nutzt `wpcap_tickets_issued`. |
| `wpcap_order_cancelled` | `int $order_id, string $reason, string $payment_status` (die letzten beiden nur aus `cancel_order()`, seit 1.5.0) | **Nach dem Commit:** `Orders::cancel_order()`, beim Ablauf von Reservierungen (`expire_holds`) und für offene Bestellungen bei der Event-Absage (`cancel_event`). `$reason` kann Freitext sein (Admin, Partner-API, Ticketdienst `wp_pass_captain_ticket_service_v1`); feste Werte und Präfixe unter [Suite-Katalog](#suite-katalog-seit-welcher-version-zeitpunkt-grund). |
| `wpcap_order_refunded` | `int $order_id, string $reason` | `Orders::mark_refunded()` nach dem Commit: Bestellung vollständig erstattet (native Erstattung, WooCommerce oder Dashboard des Zahlungsanbieters); offene Tickets sind widerrufen. |
| `wp_pass_captain_voucher_tickets_revoked_v1` | `array $info` (`redemption_key`, `order_public_id`, `remaining`, `revoked`, `reason`) | Tickets einer Gutschein-Umwandlung wurden in Pass Captain storniert oder erstattet (Bestellung storniert/erstattet, Termin/Event abgesagt) – nicht bei `revoke_tickets()` durch das Gutscheinsystem selbst. Feuert bei jeder Änderung; `remaining` zählt noch gültige oder bereits eingecheckte Tickets. Der Gutscheinanbieter kann den Gutschein damit zurückgeben (idempotent behandeln). |
| `wpcap_ticket_created` | `int $ticket_id, object $order, object $line, int $index` | Innerhalb der Ausgabe-Transaktion, je Ticket (z. B. Sitzplatz zuweisen). Klartext-Code nicht speichern. |
| `wpcap_tickets_issued` | `int $order_id, int $count` | Nach dem Commit der Ticketausgabe, vor dem Versand der Ticket-E-Mail. |
| `wpcap_attendee_data_missing` | `int $order_id, array $missing` | A682: Tickets wurden mit fehlenden Teilnehmerangaben ausgestellt (`ticket_id => Bezeichnungen`, keine Werte); die Ticket-E-Mail nennt die Lücken je Ticket. |
| `wpcap_log` | `array $entry` (`created_at`, `level`, `channel`, `reference`, `request_id`, `order_id`, `ticket_id`, `occurrence_id`, `message`, `context` als JSON), `string $event, string $message, array $context` (geschwärzt) | A327: Ein Eintrag wurde ins Support-Protokoll geschrieben (bereits geschwärzt) – z. B. an ein externes Log-System weiterleiten. Siehe [OPERATIONS.md](OPERATIONS.md#support-protokoll-a327). |
| `wpcap_log_event` | `string $level, string $event, string $message, array $context` | Derselbe Eintrag wie `wpcap_log` in der früheren Argumentreihenfolge: jeder Eintrag des zentralen Loggers `PassCaptain\Log` (`error`/`warning`, z. B. `refund_db_error`, `order_refund_state_pending`, `queue_status_update_failed`, `queue_handler_exception`, `ticket_issue_failed`, `order_state_db_error`, `webhook_db_error`, `refund_failed`, `voucher_commit_failed`, `woocommerce_refund_sync_failed`, `ticket_rotation_conflict`, `schema_upgrade_failed`). Nachricht und `$context` sind redigiert (`Security\Redact`). Der Eintrag steht zusätzlich im Audit-Log (Aktion = `$event`); scheitert dieser Insert, landet er redigiert im PHP-Fehlerprotokoll. |
| `wpcap_payment_start_failed` | `int $order_id, string $provider, WP_Error $error, string $reference` | A324: Ein Zahlungsstart ist beim Anbieter gescheitert (Checkout, Wartelisten-Angebot, Umtausch). Käufer:innen sahen einen neutralen Text mit Fehler-ID `$reference`; `$error` ist der ungeschwärzte Anbieterfehler – nie an Käufer:innen ausgeben. |
| `wpcap_job_dead_letter` | `int $job_id, string $job_type, string $error` | A188: Ein Hintergrundjob ist nach `Jobs::MAX_ATTEMPTS` (8) Läufen endgültig gescheitert (`dead_letter`; auch wenn der letzte Lauf durch einen abgestürzten Worker endete). `$error`: letzter Fehler, E-Mail-Adressen maskiert, höchstens 500 Zeichen. Zusätzlich Audit-Eintrag `job_dead_letter`; sichtbar unter Betrieb und im Website-Zustand. |
| `wpcap_tickets_discarded` | `int[] $ticket_ids, int $order_id` | A183: Eine Ticketausgabe ist gescheitert, nachdem die Datenbankverbindung abgerissen war und WordPress neu verbunden hatte; Teile davon liefen ohne Transaktion und wurden wieder entfernt (Codes wurden nie versendet). Module machen hier rückgängig, was sie in `wpcap_ticket_created` geschrieben haben (der Sitzplan gibt die Plätze an die Reservierungen der Bestellung zurück). Die nächste Ausgabe stellt die Tickets vollständig neu aus. |
| `wpcap_ticket_revoked` | `int $ticket_id, string $reason` | **Nach dem Commit** des Widerrufs; `$reason` ist beim Admin-Widerruf Freitext (feste Werte und Präfixe unter [Suite-Katalog](#suite-katalog-seit-welcher-version-zeitpunkt-grund)): Admin (`TicketsPage`) und über `Orders::fire_revoked()` (Erstattung `refund_{id}`, Stornierung `order_cancelled`, Terminabsage `occurrence_cancelled`, Event-Absage `event_cancelled`, Vollerstattung). |
| `wpcap_ticket_transferred` | `int $ticket_id, array $context` (`order_id`, `source` `portal`/`assign`, `holder_changes` = Übertragungen + Inhaberwechsel bisher) | A674: Ein Ticket gehört nach dem Commit einer anderen Person – Übertragung im Bestellportal (`Orders::transfer_ticket()`) oder Inhaberwechsel bei der Zuweisung (`TicketAssignments`). Ohne Code und ohne Adressen; der neue Code kommt über `wpcap_ticket_code_rotated`. |
| `wpcap_ticket_code_rotated` | `int $ticket_id, string $code, string $reason` | Neuer Ticketcode (`transfer`, `resend`, `assign`, `portal_pdf` – PDF-Download im Bestellportal ohne bekannten Code). `$code` ist der Klartext-Code – nur flüchtig verwenden, nie speichern oder loggen. |
| `wpcap_ticket_code_issued` | `int $ticket_id, string $code, string $reason` | Ein **neues** Ticket hat außerhalb der Ticketmail seinen ersten Code bekommen (`exchange`: Umtausch, vor der Umtauschmail), A490. `$code` ist Klartext – nie unverschlüsselt speichern. Das Wallet-Modul legt damit die Pässe an; die Mail ergänzt sie über `wpcap_ticket_code_mail_section`. |
| `wpcap_consent_granted` | `array $record` | Eine Einwilligung (Teilnehmerfeld mit „Einwilligung“) wurde erteilt, nach dem Commit: Checkout/Kasse/API/WooCommerce mit der Ticketausgabe, Selbstbedienung in Bestellübersicht oder Ticketseite. `$record`: `log_id`, `ticket_id`, `ticket_public_id`, `order_id`, `field_id`, `field_key`, `text_version` (SHA-256 des gezeigten Texts), `source`, `locale`, `created_at` (UTC), `email` (Teilnehmer, sonst Käufer; `null` bei Platzhaltern). Nicht beim Ticketumtausch (Einwilligung wird übernommen). Siehe [BETREIBERPFLICHTEN.md](BETREIBERPFLICHTEN.md#5-einwilligungen-in-teilnehmerfeldern-nachweis-widerruf-löschung). |
| `wpcap_consent_withdrawn` | `array $record` | Eine Einwilligung wurde widerrufen oder beendet (gleicher `$record`): `source` `portal`/`ticket_page` (Selbstbedienung), `admin`, `transfer`, `holder_change` (`email` = bisherige Person), `privacy_erasure` (`email` = `null`). Integrationen (z. B. Newsletter) setzen den Widerruf damit um. |
| `wpcap_consent_withdrawn` | `int $consent_id, object $consent` | Eine protokollierte Einwilligung eines Teilnehmerfelds wurde widerrufen (`Sales\Consents::withdraw()`, Quelle z. B. `portal`) oder ist durch einen Inhaberwechsel des Tickets beendet (`withdrawn_source` `holder_changed`, bei Zuweisung an eine andere Person oder Übertragung); Datensatz bleibt mit Zeitpunkt erhalten, die Antwort ist gelöscht. |
| `wpcap_ticket_checked_in` | `int $ticket_id, array $context` | **Nach dem Commit** eines erfolgreichen Einlasses (`Checkin`). `$context`: u. a. `source` (`web`, `app`, `offline`, `admin` beim Einchecken aus der Ticketliste, A338), `device_id`, `checkin_id` (Zeile im Einlassprotokoll, A675: stabile Kennung des Einlasses für die Deduplizierung). |
| `wpcap_ticket_checked_out` | `int $ticket_id, array $context` | Nach einem Auslass (Mehrfacheinlass/Bereiche); `$context` wie beim Einlass, mit `checkin_id` des Auslasses. |
| `wpcap_response_end` | – | `Http::end()`: Eine Antwort, die das Plugin selbst ausgibt (CSV-/PDF-Download, druckbare Seite), ist vollständig gesendet; direkt danach endet der Request (`exit`). Für Protokollierung und Tests. |
| `wpcap_service_booking_status_changed` | `int $booking_id, string $status, string $previous` | Eine Servicebuchung wurde von der Veranstaltungsleitung oder einem Leistungserbringer der Ressource bestätigt (`confirmed`, vorher `held`) oder als nicht erschienen markiert (`no_show`, vorher `confirmed`, erst ab Beginn des Zeitfensters) (A143, `ServiceBookings::set_booking_status()`). |
| `wpcap_refund_completed` | `int $refund_id, int $order_id` | Erstattung über das Plugin erfolgreich ausgeführt (auch Teilerstattung). |
| `wpcap_refund_reversed` | `int $refund_id, int $order_id` | Eine verbuchte Erstattung ist beim Zahlungsanbieter nachträglich gescheitert und wurde storniert (A348, `Refunds::reverse()`): Status `reversed`, erstatteter Betrag der Bestellung reduziert, Rechnungskorrektur storniert; die Tickets bleiben widerrufen. |
| `wpcap_refund_rejected` | `int $refund_id, int $order_id` | Eine auf Freigabe wartende Erstattung (`pending_approval`) wurde von einer freigabeberechtigten Person abgelehnt (`Refunds::reject()`). |
| `wpcap_portal_refund_requested` | `int $refund_id, int $order_id` | Käufer:in hat im Bestellportal eine Stornierung angefragt (A118, `PortalSelfService::request_refund()`): Erstattung mit `source` `portal` im Status `pending_approval`, nach Bestätigungsmail und Audit-Eintrag `refund_requested_by_customer`. Ausgezahlt wird erst nach der Freigabe (`wpcap_refund_completed`). |
| `wpcap_pass_day_refund_failed` | `int $order_id, int $ticket_type_id, int $occurrence_id, int $amount_minor` | Pass-Modul: Die anteilige Erstattung für einen abgesagten Termin eines Passes (`refund_per_day_minor` × Anzahl) konnte nicht ausgeführt werden (z. B. WooCommerce-Bestellung – dort erstatten, oder Fehler/Freigabe ausstehend). |
| `wpcap_occurrence_rescheduled` | `int $occurrence_id, string $old_starts_at, string $new_starts_at` | Admin: Startzeit eines Termins mit ausgegebenen Tickets geändert (UTC `Y-m-d H:i:s`). **Nach dem Commit** (Autocommit-UPDATE); scheitert das UPDATE, feuert die Action nicht (PC-6). |
| `wpcap_occurrence_changed` | `int $occurrence_id, array $changes, int $revision` | Admin/Serientermine: kalenderrelevante Angabe eines Termins geändert (A140) – Beginn, Ende, Einlass, Ort, Veranstaltungsland oder Status. `$changes`: Feld => `[alt, neu]` (Zeiten in UTC); `$revision`: neue Revision = `SEQUENCE` der .ics (0 vor Schema 3.9.9). Läuft nach `wpcap_occurrence_rescheduled`; die Kernbenachrichtigung sendet daraus genau eine Mail je Revision. |
| `wpcap_event_updated` | `int $event_id, array $changes` | Admin (`EventsPage::update_event()`): Name oder Status eines Events wurde geändert (nur bei tatsächlicher Änderung), A582. `$changes`: Feld => `[alt, neu]`. Das Wallet-Modul aktualisiert damit die Pässe und Google-Klassen. |
| `wpcap_ticket_type_updated` | `int $ticket_type_id, array $changes` | Admin (`EventsPage::save_ticket_type()`): bestehende Ticketart mit Änderungen gespeichert (Name, Preis, Kontingent, Verkaufszeitraum …), A582. `$changes`: Feld => `[alt, neu]`. |
| `wpcap_ticket_attendee_updated` | `int $ticket_id, string $source` | Teilnehmername/-angaben eines Tickets geändert, Code unverändert (`assign`: Bestellportal, A582; `ticket_page`: aktuelle Inhaberin über den Ticketlink, A570) – auch ein Personenwechsel nur über den Namen (A569). Ein Wechsel der E-Mail-Adresse feuert stattdessen `wpcap_ticket_code_rotated`; Korrekturen im Ticket-Admin feuern `wpcap_record_corrected`. |
| `wpcap_ticket_type_copied` | `int $template_type_id, int $new_type_id, array $context` | A332: Eine Ticketart des Vorlagentermins wurde auf einen neuen Serientermin kopiert (samt Teilnehmerfeldern, Einlassprüfung, Einlassregel und -bereichen), innerhalb der Transaktion der Serie. `$context`: `occurrence_id`, `series_id`, `source` (`series`). Erweiterungen kopieren hier eigene Einstellungen je Ticketart; eine Exception rollt die ganze Serie zurück. |
| `wpcap_series_updated` | `int $series_id, array $result` | Modul Serientermine: geänderte Seriendefinition übernommen (A128). `$result`: `added`, `changed`, `deleted`, `flagged` (Termin-IDs), `skipped`, `seat_failed`. Verschobene Termine mit Tickets lösen vorher je `wpcap_occurrence_rescheduled` aus. |
| `wpcap_purge_page_cache` | `int $event_id, string[] $urls, array $context` | A446: Seiten, die ein Event zeigen, müssen aus Seitencache und CDN entfernt werden. Feuert einmal je Event und Request am Requestende (`shutdown`), nachdem ein Event, Termin, eine Ticketart (Preis, Verkaufszeitraum, Status, Kontingent), eine Übersetzung oder der Verfügbarkeitszustand (ausverkauft ↔ wieder verfügbar) gespeichert wurde, sowie zu Verkaufsstart und -ende (Cron `wpcap_page_cache_sales_window`). `$event_id` 0 = alle Seiten mit Event-Liste (Rechtstexte geändert). `$urls`: Permalinks der Beiträge mit Shortcode/Block für dieses Event bzw. einen seiner Termine und aller Beiträge mit vollständiger Liste oder Kalender (Filter `wpcap_page_cache_urls`). `$context`: `post_ids`, `reasons` (`event`, `occurrence`, `ticket_type`, `availability`, `sales_window`, `legal`, `series`, `translation`), `occurrence_ids`, `events` (weitere Events, die ein 0-Purge abdeckt). Die mitgelieferten Adapter (WP Rocket, LiteSpeed, W3TC, WP Super Cache, Cloudflare, Varnish) hängen mit Priorität 10; siehe [OPERATIONS.md](OPERATIONS.md#seitencache-und-cdn). |
| `wpcap_page_no_store` | `string $context` | A283/A508: Eine persönliche Seite wird gesendet und ist per `DONOTCACHEPAGE` und `nocache_headers()` vom Caching ausgenommen (`PageCache::no_store()`): `ticket`, `portal`, `invoice`, `invoice_pdf`, `waitlist_offer`, `waitlist_unsubscribe`, `waitlist_confirm`, `waitlist_result`, `payment_return`, `scanner`. Für zusätzliche Header eigener Proxys (z. B. `Surrogate-Control`). |
| `wpcap_occurrence_phase_changed` | `int $occurrence_id, string $new, string $old, array $context` | Lebenszyklus eines Termins (A562): Phase `upcoming`, `running`, `over` oder `cancelled` gewechselt (`Occurrence::phase()`). Zeitliche Übergänge aus `Occurrence::sweep_phases()` auf `wpcap_process_jobs` (bis zu 5 Min. später; nach langem Cron-Ausfall kann eine Phase übersprungen werden), Verwaltungsänderungen und Absagen sofort. `$context`: `source` (`sweep`, `admin`, `cancel`), `event_id`. Kern: Seiten-Cache der Eventliste (Priorität 10), Warteliste schließt begonnene Termine (30). Handler idempotent halten. |
| `wpcap_event_cancelled` | `int $event_id, array $context` (`occurrence_ids`, `run_id`) | A674: `Orders::cancel_event()` nach dem Commit der Absage (Event und Termine `cancelled`), bevor der Absagelauf die Termine einzeln absagt (je Termin danach `wpcap_occurrence_cancelled`). |
| `wpcap_event_cancelled` | `int $event_id` | `Orders::cancel_event()` nach Widerruf, Benachrichtigung und Audit-Eintrag. Fiskalisierung: Korrekturaufgabe je bestätigtem Beleg mit Tickets des Events. |
| `wpcap_attendance_confirmed` | `int $ticket_id, array $context` (`method`, `source`) | A676: Eine zulässige Teilnahmebestätigung ohne Scan (Command `attendance.confirm`), nach dem Audit-Eintrag. |
| `wpcap_checkin_undone` | `int $ticket_id, array $context` (`source`) | A667: Ein Einlass wurde zurückgenommen (Admin, Scanner, App; nach dem Commit). |
| `wpcap_workflow_event_recorded` | `array $envelope` | A673: Ein Ereignis des Workflow-Vertrags wurde in die Outbox geschrieben (einmal je Ereignis-ID, Envelope ohne `sequence`). Intern, z. B. für Protokolle. |
| `wpcap_occurrence_cancelled` | `int $occurrence_id, int $issued` | Admin: Termin abgesagt (`$issued` = Anzahl ausgegebener Tickets). **Nach dem Commit** des Statuswechsels; scheitert das UPDATE oder hat eine parallele Anfrage schon abgesagt, feuert die Action in dieser Anfrage nicht (PC-6). Kern-Folgen in `OccurrenceCancellation` (Priorität 10); das SMS-Modul hängt sich mit Priorität 5 davor. |
| `wpcap_occurrence_cancelled_woocommerce_order` | `int $order_id, int $occurrence_id` | Je bezahlter WooCommerce-Bestellung eines abgesagten Termins (Erstattung erfolgt in WooCommerce). |
| `wpcap_cancellation_refund_retried` | `array $payload, string $outcome` | Eine fehlgeschlagene Absage-Erstattung (Termin- oder Pass-Absage) wurde im Hintergrundjob `cancellation_refund` erneut versucht und ist erledigt: `$outcome` `done` (ausgezahlt/beim Anbieter), `open` (wartet auf Freigabe bzw. abgelehnt) oder `final` (nichts mehr zu erstatten). `$payload` enthält `order_id`, `occurrence_id`, `mode` (`tickets`/`amount`) und `key`. |
| `wpcap_occurrence_cancellation_step` | `int $occurrence_id, int $run_id` | Schritt der wiederaufnehmbaren Terminabsage (A428, `BulkRuns`) für Module, nachdem der Kern Tickets widerrufen und Erstattungen eingeplant hat (z. B. Pässe). Muss idempotent sein: Ein abgebrochener Lauf führt den Schritt erneut aus. Mails über `BulkRuns::queue_mail( $run_id, … )`. |
| `wpcap_bulk_run_completed` | `int $run_id, string $kind, int $object_id, array $summary` | Ein Absage- oder Benachrichtigungslauf (`occurrence_cancel`, `event_cancel`, `notify`) hat alle Schritte erledigt (A428). `$summary`: `cancelled_orders`, `revoked`, `refunds`, `refund_errors`, `notified`, … |
| `wp_pass_captain_connector_error_v1` | `string $connector_key, string $event_name, string $message` | Ein Connector hat bei einem Ereignis eine Exception geworfen oder `false`/`WP_Error` geliefert (die Zustellung wird über die Job-Queue wiederholt, siehe [EXTENSIONS.md](EXTENSIONS.md#connectoren)). |
| `wpcap_process_fiscal_order` | `int $order_id` | Cron-Ereignis (`wp_schedule_single_event`) für die automatische Wiederholung offener Fiskalbelege mit wachsendem Abstand; ruft `Orders::issue_and_email()` (Fiskal-Prüfung vor der Ausgabe). Beim Deinstallieren abgemeldet. |
| `wpcap_fiscal_terminal_failure` | `array $details` (`document_id`, `order_id`, `seller_id`, `provider_id`, `error_code`, `permanent`) | Ein Fiskalbeleg ist endgültig fehlgeschlagen (dauerhafter Fehler oder 8 Versuche); Tickets bleiben gesperrt, Admin-Mail und Log-Eintrag `fiscal_document_terminal_failure`. |
| `wpcap_alert` | `string $topic`, `string $subject`, `string $body`, `array $context` | Ein Betriebsalarm wurde ausgelöst (`Alerts::raise()`, A400), auch wenn `wpcap_alert_send` die Mail zurückhält – für Chat-, Ticket- oder Monitoring-Systeme. |
| `wpcap_woocommerce_non_ticket_refund` | `int[] $order_ids, int $refund_id, string $currency, array $lines` | WooCommerce-Erstattung von Nicht-Ticket-Positionen (Waren, Gebühren, Versand); `$order_ids` = alle Pass-Captain-Bestellungen der WooCommerce-Bestellung (bei Aufteilung je Rechnungssteller), `$lines` = `line_key`, `quantity`, `amount_minor`, `tax_minor` (leerer `line_key` = reine Betragserstattung). |
| `wpcap_admin_event_sections` | `object $event, bool $editable` | Admin „Events“ → Event bearbeiten, nach den Terminen: weitere Abschnitte (Steuerland, Preisregeln, Käuferlimits aus `Sales\AdminSections`). Callbacks prüfen ihre Rechte selbst. |
| `wp_pass_captain_availability_changed_v1` | `int[] $ticket_type_ids, string $reason` | Verfügbarkeit von Ticketarten hat sich geändert – für Purge-Adapter (Seiten-Cache, CDN) gecachter Eventseiten (`Frontend\Availability`). `$reason`: `reserved` (neue Reservierung über den öffentlichen Checkout, nach dem Commit) oder der Grund aus `wpcap_capacity_released` (`expired`, `cancelled`, `refunded`, `tickets_revoked`, `event_cancelled`, `payment_unallocated`) bzw. `refunded` nach `wpcap_refund_completed`. Kein Ereignis zum Verkaufsstart/-ende (zeitgesteuert): Adapter sollten dafür selbst planen; die Seiten aktualisieren sich ohnehin über `GET /checkout/live` (eigenes Kassenskript) bzw. `GET /availability` (öffentlicher Endpunkt je Ticketart für eigene Themes und Integrationen). |
| `wpcap_checkout_form_fields` | `object $event, array $types` | Template `templates/event-card.php` innerhalb des Checkout-Formulars (eigene Felder ausgeben; JS-Ereignis `wpcap:checkout-payload` ergänzt den Payload). |
| `wp_pass_captain_admin_menu_built_v1` | `string $parent_slug` (`wp-pass-captain`) | Ende von `Admin\Menu::add_menus()` (Hook `admin_menu`), nachdem alle Kernseiten registriert sind: Add-ons hängen eigene Unterseiten mit `add_submenu_page( $parent_slug, … )` an – unabhängig von Modulen und der Reihenfolge der `admin_menu`-Callbacks (die Seiten stehen nach den Kernseiten). |
| `wp_pass_captain_admin_occurrence_sections_v1` | `object $occurrence`, `bool $editable` (false bei abgesagtem Event) | Weitere Abschnitte der Terminseite (Events → Termin) nach den Ticketarten, z. B. „Tickets ausstellen“ und „Termin duplizieren“ (`Admin\OrganizerTools`). Callbacks prüfen ihre Rechte selbst. |
| `wp_pass_captain_invoice_created_v1` | `int $id, string $type, array $context` | Nach dem Commit eines Belegs, genau einmal je Beleg: `invoice` (`Invoices::issue_for_order()`, `$id` = invoice_id, Kontext `order_id`, `number`), `correction` (Rechnungskorrektur zu einer Erstattung, `$id` = credit_note_id, Kontext `invoice_id`, `refund_id`, `order_id`, `number`), `settlement` (Veranstalter-Abrechnung abgeschlossen, `$id` = settlement_id, Kontext `partner_id`, `period`, `number`, `self_billing_number`). Daten über `PassCaptain\Api\Invoices::get( $id, $type )`. Exceptions der Callbacks werden protokolliert (`invoice_created_hook_failed`) und brechen den Kern nicht ab. |
| `wp_pass_captain_pos_device_payment_started_v1` | `int $sale_id, string $device_id, string $reference, array $sale` | Kasse: Eine Kartenterminal-Zahlung wurde am Gerät gestartet (nach `DeviceGatewayInterface::start()`, genau einmal je Verkauf). `$sale` = Start-Daten (`version`, `sale_key`, `sale_id`, `order_id`, `order_public_id`, `amount_minor`, `currency`, `description`, `device_id`, `session_id`, `location_id`, `register_id`, `cashier_id`). Siehe [EXTENSIONS.md](EXTENSIONS.md#kartenterminals-an-der-kasse-box-office). |
| `wp_pass_captain_pos_device_payment_completed_v1` | `int $sale_id, int $order_id, string $provider_reference, string $device_id` | Kasse: Die Terminalzahlung ist bestätigt (Abfrage oder `Service::confirm_device_payment()`), genau einmal je Verkauf – auch wenn die Buchung danach noch auf den Fiskalbeleg wartet. |
| `wp_pass_captain_pos_device_payment_failed_v1` | `int $sale_id, string $status, string $message, string $device_id` | Kasse: Die Terminalzahlung endete ohne Zahlung; `$status` `failed` (Gerät/Start), `cancelled` (Kassierer), `timeout` oder `amount_mismatch` (das Gerät meldet einen anderen belasteten Betrag bzw. eine andere Währung als die Bestellung, Vertrag 1.1 – nicht gebucht, am Terminal erstatten). Der Verkauf ist `failed`, die Bestellung storniert (Kontingent frei). |
| `wp_pass_captain_qr_key_rotated_v1` | `array $result` (`key_id`, `previous`, `previous_valid_until`, `revoked`) | Nach `Security\SignedQr::rotate()` (nicht beim ersten Schlüssel). Bei `revoked` = true stellt das Wallet-Modul alle aktiven Pässe kommender Termine per `wallet_sync`-Job neu aus (neuer Barcode, Schlüssel-ID im Fingerprint). |

Außerdem feuert das Template `templates/event-card.php` innerhalb des Checkout-Formulars die Action
`wpcap_checkout_form_fields` (`object $event, array $types`), an die sich Module zum Ausgeben eigener Felder
hängen (Sitzplatzwahl, SMS, Mollie, Partnercode, Käuferlimits); das JS-Ereignis `wpcap:checkout-payload`
ergänzt den Payload. Sie steht hier im Fließtext, weil sie nicht in `src/`, sondern im Template ausgelöst wird.

Die Terminbuchung (A268) löst nach erfolgreicher Buchung das JS-Ereignis `wpcap:service-booking-complete`
auf `document` aus (`detail`: `root`, `booking_id`, `status` – `confirmed` oder `pending_payment` –, `order`
mit `public_id`, `reference`, `payment` bei kostenpflichtigen Ressourcen), z. B. für Analytics ohne personenbezogene Daten.

Nachgeladene Eventkarten (A500): `assets/checkout.js` richtet Bestell- und Wartelistenformulare, die nach dem
Laden in die Seite kommen (AJAX-Filter, Endlos-Scrollen, Popups, Page-Builder), selbst ein – über einen
`MutationObserver` und für Elementor über `elementorFrontend.hooks` (`frontend/element_ready/global`).
Eigene Skripte können das ausdrücklich anstoßen: `window.WPCAP.init( element )` oder das Ereignis
`document.dispatchEvent( new CustomEvent( 'wpcap:init', { detail: { root: element } } ) )`. Jedes Formular
wird nur einmal eingerichtet (Stepper, Hinweise, Rechnungsfelder, offene Zahlung, Freigabe des Absende-Knopfs,
Live-Stand); `init()` gibt die Zahl der neu eingerichteten Formulare zurück.

Ids der Eventkarte (A308): Das Template holt die Id-Basis des Formulars über
`\PassCaptain\Frontend\EventCard::form_id( $event->occurrence_id )`. Die erste Karte eines Termins auf der
Seite behält `wpcap-{Termin}`, jede weitere (zweiter Shortcode, Widget, Archiv) bekommt
`wpcap-{Termin}-i{n}`; alle Feld-Ids hängen daran (`{Basis}-{Ticketart}`, `{Basis}-email`,
`{Basis}-billing-fields` …). Filter auf `wpcap_event_card_html` ermitteln die Basis der Karte mit
`EventCard::form_id_in( $html )` statt sie aus der Termin-Id zu bauen; der Kalender-Anker
`wpcap-occ-{Termin}` steht nur an der ersten Karte. Eigene Theme-Templates sollten `form_id()` ebenfalls
verwenden; `checkout.js` sucht Rechnungsfelder und Fehlermeldungen ohnehin nur innerhalb des eigenen Formulars.

Live-Zustand der Eventkarte (A456–A458): `checkout.js` schaltet die Karte nach `GET /checkout/live` um und
braucht dafür in eigenen Theme-Templates dieselben Bausteine wie `templates/event-card.php`: `.wpcap-type[data-type]`
mit `.wpcap-type-price`, `.wpcap-price-label`, `.wpcap-base-price`, `.wpcap-qty-box` (Mengenfeld immer
ausgeben) und `.wpcap-soldout`; `.wpcap-buy` um das Bestellformular, `.wpcap-soldout-note`, `.wpcap-no-payment`,
`.wpcap-provider-slot` um die Zahlungsarten, das Wartelistenformular `.wpcap-waitlist-form[data-occurrence]`
mit allen Ticketarten im `select[name="ticket_type_id"]` (`option[data-max]`) und die Rechtstexte
`.wpcap-legal[data-legal="terms|consumer_info"] .wpcap-legal-text`. Fehlt ein Baustein, bleibt dieser Teil
im gerenderten (ggf. gecachten) Zustand; der Server prüft beim Bestellen ohnehin alles.


### Suite-Katalog: seit welcher Version, Zeitpunkt, Grund

Die Actions, die andere Captain-Plugins (Workflow Captain, Spezifikation `SPEC-suite-events.md`, Abschnitt 4.2)
als Ereignisse des Kerns nutzen, mit der **Plugin-Version, seit der sie feuern** (PC-1). Die Spalte „Seit“ der
[Öffentlichen Add-on-API](#öffentliche-add-on-api) nennt dagegen die Version des Add-on-Vertrags (1.0). Ermittelt
aus den Release-Ständen der Versionsgeschichte (0.9.0, 1.0.0, 1.1.0, 1.3.0, 1.4.0, 1.5.0); vor 0.9.0 gab es kein
Release mit diesen Hooks.

„Nach dem Commit“ heißt: Wenn die Action feuert, sind die Zeilen, die das Ereignis beschreiben, dauerhaft
geschrieben; ein Callback sieht sie auch aus einer anderen Datenbankverbindung, und ein Fehler im Callback macht
nichts rückgängig (PC-2). Wo die Schreibung ohne Transaktion läuft (Autocommit), feuert die Action erst, wenn das
UPDATE gelungen ist.

| Action | Seit | Zeitpunkt | Änderungen |
|---|---|---|---|
| `wpcap_order_creating` | 0.9.0 | **innerhalb** der Bestell-Transaktion (kann abbrechen) | – |
| `wpcap_order_created` | 1.5.0 | nach dem Commit | – |
| `wpcap_order_paid` | 0.9.0 | nach dem Commit der Zahlungsbestätigung (bei kostenlosen Bestellungen nach dem Commit der Bestellung), vor der Ticketausgabe | – |
| `wpcap_order_cancelled` | 0.9.0 | nach dem Commit der Stornierung bzw. des Reservierungsablaufs | **1.5.0:** zweites und drittes Argument (Grund, bisheriger Zahlungsstatus) aus `Orders::cancel_order()`; beim Ablauf einer Reservierung weiterhin nur die Bestell-ID (Commit `b6ef0b4`) |
| `wpcap_order_refunded` | 0.9.0 | nach dem Commit | – |
| `wpcap_refund_completed` | 0.9.0 | nach dem Commit der Verbuchung | – |
| `wpcap_refund_rejected` | 0.9.0 | nach dem Statuswechsel (Autocommit, nur wenn das UPDATE eine Zeile traf) | – |
| `wpcap_refund_reversed` | 1.5.0 | nach dem Commit | – |
| `wpcap_ticket_created` | 0.9.0 | **innerhalb** der Ausgabe-Transaktion | – |
| `wpcap_tickets_issued` | 0.9.0 | nach dem Commit, vor der Ticketmail | – |
| `wpcap_ticket_revoked` | 0.9.0 | nach dem Commit des Widerrufs | – |
| `wpcap_ticket_code_rotated` | 0.9.0 | nach dem Commit der Neuerzeugung | – |
| `wpcap_ticket_transferred` | 1.5.0 | nach dem Commit | – |
| `wpcap_ticket_exchanged` | 1.5.0 | nach dem Commit des Umtauschs | – |
| `wpcap_ticket_checked_in` | 0.9.0 | nach dem Commit des Einlasses | – |
| `wpcap_ticket_checked_out` | 0.9.0 | nach dem Commit des Auslasses | – |
| `wpcap_occurrence_rescheduled` | 0.9.0 | nach dem gelungenen UPDATE des Termins (Autocommit) | **1.5.0 (PC-6):** feuert nicht mehr, wenn das UPDATE scheitert |
| `wpcap_occurrence_changed` | 1.5.0 | nach dem gelungenen UPDATE des Termins | – |
| `wpcap_occurrence_cancelled` | 0.9.0 | nach dem gelungenen Statuswechsel auf `cancelled` (Autocommit; Sammelabsage: nach dem Commit des Schritts) | **1.5.0 (PC-6):** feuert nicht, wenn das UPDATE scheitert, und nur in der Anfrage, die den Status tatsächlich geändert hat |
| `wpcap_event_cancelled` | 1.5.0 | nach dem Commit der Absage | die Arbeitslisten-Linie übergibt zusätzlich `array $context` (`occurrence_ids`, `run_id`) |
| `wpcap_consent_granted` | 1.5.0 | nach dem Commit | – |
| `wpcap_consent_withdrawn` | 1.5.0 | nach dem Commit | – |
| `wpcap_mail_before_send` | 1.1.0 | unmittelbar vor `wp_mail()` | – |

**Grund (`$reason`) bei `wpcap_order_cancelled` und `wpcap_ticket_revoked` (PC-3).** Der Wert ist eine
Zeichenkette, **kein geschlossener Satz von Schlüsseln**: Er kann Freitext sein und darf nicht ungeprüft als
Schlüssel, Dateiname oder HTML verwendet werden.

- **Freitext:** Admin-Stornierung einer Bestellung (Feld „Grund“, bis 255 Zeichen; leer → `admin_cancel`),
  Admin-Widerruf eines Tickets (Pflichtfeld „Grund“, bis 255 Zeichen), Partner-API (`partner_api: <Text des
  Partners>`, bis 190 Zeichen) und bei `wpcap_order_cancelled` jeder Text, den ein Gutscheinanbieter über den
  Ticketdienst `wp_pass_captain_ticket_service_v1` an `revoke_tickets( $key, $reason )` übergibt (leer →
  `voucher_revoked`). Ein Wiederholungsjob der Stornierung übergibt den Grund des ersten Versuchs.
- **Feste Werte und Präfixe bei `wpcap_order_cancelled`:** `admin_cancel`, `voucher_rejected`, `voucher_revoked`,
  `provider_unavailable_for_currency`, `payment_start_failed`, `buyer_cancelled_on_return`, `buyer_released`,
  `occurrence_cancelled`, `event_cancelled`, `guestlist_cancelled`, `privacy_erasure`, `pos_session_closed`,
  `partner_api`; Präfixe `{Zahlungsanbieter}_payment_failed`, `{Zahlungsanbieter}_refund_unpaid`,
  `pos_device_…`, `waitlist_…` (`waitlist_offer_mail_failed`, `waitlist_offer_withdrawn`, `waitlist_declined`,
  `waitlist_unsubscribed`, `waitlist_removed`, `waitlist_skipped`, `waitlist_closed`), `woocommerce_…`
  (`woocommerce_checkout_retry`, `woocommerce_split_failed`, `woocommerce_order_trash`,
  `woocommerce_order_delete`, `woocommerce_refunded_unpaid`, `woocommerce_woocommerce_order_status_…`),
  `exchange_…` (`exchange_superseded`, `exchange_original_closed`, `exchange_ticket_revoked`,
  `exchange_ticket_checked_in`) und `service_…` (`service_booking_cancelled`, `service_supplement_cancelled`,
  `service_supplement_refused`, `service_supplement_dropped`). Beim Ablauf einer Reservierung gibt es kein
  zweites Argument.
- **Feste Werte und Präfixe bei `wpcap_ticket_revoked`:** `order_cancelled` (auch wenn die Stornierung einen
  Freitext-Grund hatte), `occurrence_cancelled`, `event_cancelled`, `exchange`, `doctor`, `privacy_erasure`,
  `refunded`; Präfixe `refund_{refund_id}` (Erstattung über den Kern), `woocommerce_refund_{id}`,
  `service_booking_{id}`, `service_rebook_{id}`; bei einer Vollerstattung der Grund von
  `wpcap_order_refunded` (`native_refund_{id}`, `{Quelle}_refund_{id}`, `{Zahlungsanbieter}_chargeback`,
  `{Zahlungsanbieter}_dashboard_refund`, `woocommerce_refunded`, `partner_api`, `late_payment_auto_refund`,
  `unallocated_refunded`, `unallocated_refunded_externally`, `refund_reconcile`).

Die WP-Cron-Hooks des Plugins stehen unter [Cron-Hooks](#cron-hooks).

### Cron-Ereignisse

WP-Cron-Hooks des Plugins (`Plugin::CRON_HOOKS`; Deaktivierung und Deinstallation melden alle ab). Eigene Callbacks
können sich anhängen, z. B. für Überwachung; die Verarbeitung selbst steckt in den Kern-Callbacks.

| Hook | Argumente | Ort / Zeitpunkt |
|---|---|---|
| `wpcap_cleanup_reservations` | – | Stündlich: abgelaufene Reservierungen freigeben (`Orders::expire_holds()`). |
| `wpcap_process_messages` | – | Alle 5 Minuten: Nachrichtenwarteschlange (E-Mails, Erinnerungen). |
| `wpcap_process_jobs` | – | Alle 5 Minuten: Hintergrundjobs (`Jobs`), Ticketausgabe-Wiederholungen. |
| `wpcap_privacy_purge_wallet_registrations` | `int[] $ticket_ids` | Einmalig eine Stunde nach dem Löschen bzw. Anonymisieren von Tickets: Wallet-Geräteregistrierungen dieser Tickets entfernen (Apple-Geräte bekommen vorher noch den Push für den widerrufenen Pass). |
| `wpcap_run_upgrade` | – | Einmaliges Ereignis für ein aufgeschobenes Schema-Upgrade (Frontend-Anfrage nach Update, verzögerte Netzwerkaktivierung, `Installer::UPGRADE_HOOK`). |

### JavaScript-Ereignisse (Frontend)

| Ereignis | Detail | Wann |
|---|---|---|
| `wpcap:track` (auf `document`) | `{ event, currency, value, items[{item_id, item_name, price, quantity}], transaction_id? }` | Datensparsame Commerce-Ereignisse `view_item`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase` (assets/tracking.js, ohne personenbezogene Daten). Siehe [TRACKING.md](TRACKING.md). |
| `wpcap:checkout-payload`, `wpcap:checkout-complete` | siehe assets/checkout.js | Vor dem Absenden (Module ergänzen Felder) bzw. nach erfolgreicher Bestellung ohne Weiterleitung. |

## Filter und themenbezogene Actions

Actions in diesen Tabellen sind mit „(Action)“ gekennzeichnet.

### Checkout und Bestellungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_checkout_validate` | `true|WP_Error $result` (true), `array $context` (`email`, `items` Ticketart-ID => Menge, `payload` öffentliche Checkout-Felder, `source`, `external`) | A331: Eigene Checkout-Regeln vor der Bestell-Transaktion (nichts ist gesperrt oder geschrieben). Ein `WP_Error` lehnt den Checkout mit genau diesem Fehler ab (Status 400, wenn der Fehler keinen nennt); jeder andere Wert lässt ihn durch. Läuft nicht für wiederverwendete Idempotenzschlüssel. Regeln, die Zeilensperren brauchen, gehören in `wpcap_order_creating`. |
| `wpcap_checkout_payload_keys` | `string[] $keys` | Zusätzliche öffentliche Checkout-Felder durchlassen (z. B. `sms_phone`, `sms_consent`, `mollie_method`). |
| `wpcap_checkout_error_messages` | `array $messages` (Code → Text) | Meldungen zu Abbruch-Codes aus `wpcap_order_creating`. |
| `wpcap_order_recovering` | `null|WP_Error $result, int $order_id, array $demand, array $context` | Späte Zahlung (`Orders::confirm_external_payment()`, Reservierung abgelaufen): läuft in der Transaktion nach Kontingent- und Rabattcode-Prüfung (Gegenstück zu `wpcap_order_creating`). Module prüfen Regeln je Bestellung erneut, die sich seit Ablauf geändert haben können (z. B. Käuferlimit), mit eigenen Zeilensperren. `$demand`: Ticketart-ID → Menge; `$context` wie bei `wpcap_reserve_capacity` (`phase` = `recover`). `WP_Error` → Bestellung `needs_review`/`paid_unallocated` (Code als Grund); `Errors::busy()` → Rollback, der Anbieter stellt erneut zu. |
| `wpcap_legal_default_locale` | `string $locale` | Sprache der Standard-Rechtstexte (`terms_text`, `consumer_info_text`). Standard: Standardsprache von Polylang (`pll_default_language`) bzw. WPML (`wpml_default_language`), sonst `get_locale()`. Texte weiterer Sprachen stehen in `legal_texts`; Käufer ohne eigene Fassung sehen die Standardtexte. |
| `wpcap_legal_locales` | `string[] $locales, string $default` | Sprachen, für die unter **Einstellungen → Rechtstexte** eigene Rechtstexte angeboten werden (Standard: Standardsprache, Sprachen von Polylang/WPML, installierte Sprachpakete, bereits gepflegte Sprachen). |
| `wpcap_affiliate_partner_field` | `bool $show` (true) | Partnercode-Feld im Checkout (nur mit WP Affiliate Captain). |
| `wpcap_affiliate_tracking_allowed` | `bool $allowed`, `string $method` | Ob die Empfehlung aus `wpac_current_touch()` verwendet werden darf. Cookie (`cookie`): mit WP Consent API `wp_has_consent( 'marketing' )`, ohne Consent-API `false` (keine nachweisbare Einwilligung; Freigabe über diesen Filter, wenn der Cookie-Banner den Cookie blockiert). Partnerlink der Shop-Sitzung (`link`, `app`, kein Marketing-Cookie): standardmäßig `true`. Ein eingegebener Partnercode ist immer erlaubt. |
| `wpcap_affiliate_hold_until` | `int $timestamp, object $order` | Sperrfrist der Provision (Standard: Ende des letzten gebuchten Termins). |
| `wpcap_affiliate_net_minor` | `int $net, object $line, object $order` | Provisionsbasis je Bestellzeile. |
| `wpcap_affiliate_sale_payload` | `array $payload, object $order` | Verkaufsdaten an Affiliate Captain. |
| `wpcap_affiliate_skip_channels` | `string[] $channels` (`woocommerce`) | Kanäle/Zahlungsanbieter, die nicht gemeldet werden. |
| `wpcap_checkout_ip_limit` | `int $limit` (30) | Checkouts je IP und Stunde. |
| `wpcap_checkout_ip_limit` | `int $limit` (30) | Angelegte Bestellungen je Client-Netz (IPv4-Adresse bzw. IPv6-/64) und Stunde (`Sales\CheckoutThrottle`). Gezählt werden nur tatsächlich angelegte Bestellungen, nicht Versuche, die z. B. an „ausverkauft“ scheitern. Zusätzlich gelten 8 Bestellungen je Netz und normalisierter E-Mail und Stunde. |
| `wpcap_checkout_live_limit` | `int $limit` (120) | Abrufe des Live-Zustands der Eventkarten (`GET /checkout/live`, A456) je Client und Minute; darüber 429 und die Karte bleibt im gerenderten Zustand. |
| `wpcap_financial_retention_years` | `int $years` (Einstellung „Rechnungen und Rechnungskorrekturen löschen nach (Jahren)“, 0 = nie) | `Privacy\Retention`: Aufbewahrung von Rechnungen, Rechnungskorrekturen und Kassenschichten (Z-Berichte, Buchungen, Belege) in Jahren ab Ende des Ausstellungsjahres. |
| `wpcap_campaign_stacking` | `string $mode` (`cumulative` oder `best`, aus der Einstellung „Mehrere Rabattcodes“), `object[] $campaigns`, `array $selected` | `Campaigns::calculate()` bei mehr als einem Code: `cumulative` wendet die Codes nach Priorität nacheinander auf den Restbetrag an, `best` nur den Code mit dem höchsten Rabatt. Nicht kombinierbare Codes (`stacking` = `exclusive`) werden vorher abgelehnt. |
| `wpcap_tax_profiles_apply` | `bool $apply` (Standard: `true` außer für `woocommerce`), `string $source` (Verkaufskanal, z. B. `native`, `pos`, `partner`, `waitlist`, `exchange`), `object $type` | Ob Länder-Steuerprofile (A115, `Tax\Profiles::resolve()`) für den Verkauf einer Ticketart mit Steuerklasse gelten. `false` = Steuersatz der Ticketart. WooCommerce rechnet mit den eigenen Steuereinstellungen. Aufgerufen in `Orders::create()` (Bestell-Transaktion), beim Ticketumtausch und in den Katalogen von Kasse und Partner-API. |
| `wpcap_ticket_unit_price` | `int $price_minor, object $type, array $context` | Brutto-Einzelpreis einer Ticketart, nur serverseitig (`Orders::unit_price()`). `$context`: `occurrence` (Zeile), `quantity` (Menge der Ticketart in der Bestellung, 1 zur Anzeige), `now` (UTC `Y-m-d H:i:s`), `phase` (`create`/`display`/`cart`), `source` (`woocommerce` im Woo-Warenkorb). In `Orders::create()` innerhalb der Bestell-Transaktion nach der Kontingentprüfung. **Vorrang:** vertrauenswürdige `unit_prices` aus `$internal` (WooCommerce, kostenlose Kassenverkäufe) ersetzen den Preis ohne Filter; sonst gilt gespeicherter Preis + Filter (öffentlicher Checkout, Warteliste, Kasse ohne Preisvorgabe). Kundeneingaben erreichen weder Filter noch Override. Geänderte Zeilen stehen im `price_snapshot` unter `unit_price_adjustments`. Automatische Preisregeln (Event- und Serienregeln, `Sales\PriceRules`, Priorität 10) hängen sich hier ein; die siegreiche Regel steht zusätzlich im `line_snapshot.price` der Bestellposition. |
| `wpcap_woocommerce_use_core_prices` | `bool $enabled, WC_Product $product, int $ticket_type_id` | Ob Pass-Captain-Preise (inkl. `wpcap_ticket_unit_price`, z. B. Serien-Preisstufen) für dieses Ticketprodukt im Woo-Warenkorb gelten; nur wirksam, wenn die globale Option (WooCommerce → Einstellungen → Produkte) aktiv ist. Die Reservierung übernimmt weiterhin die Woo-Zeilensummen als vertrauenswürdigen Override. |
| `wpcap_ticket_price_label` | `string $label, object $type, array $context` | Öffentliche Bezeichnung des aktuell geltenden Preises (z. B. „Frühbucher“), `Orders::price_label()`; Event-Karte und Serien-Admin. |
| `wpcap_reserve_capacity` | `true|WP_Error $result, array $demand, array $context` (`phase`, `order_id`, `types`, `occurrences`, `now`, `source`, `email`, `user_id`, `internal`) | Zusätzliche gemeinsame Kontingente (z. B. Serien-Pools). Läuft in `Orders::create()` und bei verspäteter Zahlung (`confirm_external_payment`) in der Transaktion, **nachdem** Ticketarten und Termine (in dieser Reihenfolge, je nach ID) gesperrt sind und **bevor** der Kern erstmals konsistent liest. `$demand`: Ticketart-ID → Menge; `$context`: `phase` (`create`/`recover`), `order_id` (bei `recover` aus der Zählung auszunehmen, sonst 0), `types`/`occurrences` (gesperrte Zeilen nach ID), `now`, `source`. Eigene Zeilen zuerst mit sperrenden Lesezugriffen (`FOR UPDATE`/`LOCK IN SHARE MODE`, feste Reihenfolge) sperren, erst danach Belegung lesen – sonst ist der Snapshot veraltet. `WP_Error` lehnt ab (Code über `wpcap_checkout_error_messages`, sonst die Meldung des Fehlers; bei `recover` → `needs_review`). |
| `wpcap_ticket_availability` | `int|null $remaining, object $type, object $occurrence` | Restmenge in `Orders::availability()` (Anzeige, Warenkorbprüfung, Kasse). Nur verkleinern; `null` = unbegrenzt. Serien-Pools hängen sich hier ein. |
| `wpcap_order_portal_sections` | `string $html, object $order, string $access_token` | Zusätzliche Abschnitte im Bestellportal (vor den Rechnungsdaten); Rückgabe ist bereits escaptes HTML. Servicebuchungen (Umbuchen/Stornieren) hängen sich hier ein, siehe `docs/SERVICE-BOOKINGS.md`. |
| `wpcap_waitlist_offer_hours` | `int $hours` (Einstellung `waitlist_offer_hours`, Standard 24) | Annahmefrist automatischer und manueller Event-Wartelistenangebote; das Ergebnis wird auf 1–720 Stunden begrenzt. |
| `wpcap_ops_bulk_retry_cap` | `int $cap` (100) | Höchstzahl der Einträge, die „Alle erneut versuchen“ im Systemstatus je Klick und Warteschlange neu einreiht (1–500). |
| `wpcap_service_booking_ip_limit` | `int $limit` (40) | Buchungsversuche je IP. |
| `wpcap_service_booking_changed` (Action) | `int $booking_id, string $change, array $context` | Nach dem Commit einer Änderung an einer Servicebuchung (`ServiceBookingFlow::notify()`, A135): `confirmed`, `rebooked`, `cancelled`, `unallocated` (Zahlung nach Ablauf der Reservierung, Zeitfenster vergeben, Erstattung angestoßen). `$context`: `previous` (Buchungszeile vorher), `refund` (Erstattungszeile, `WP_Error` oder `null`), `actor`. Läuft vor den Mails. |
| `wpcap_service_booking_mail` | `array\|false $mail` (`to`, `subject`, `body`), `string $change`, `object $booking`, `string $audience` (`customer`/`staff`) | Kunden- bzw. Team-Mail zu einer Servicebuchung anpassen; `false` unterdrückt sie. |
| `wpcap_service_booking_staff_recipients` | `string[] $emails` (Website-Admin plus aktive Leistungserbringer der Ressource mit E-Mail), `object $booking`, `string $change` | Empfänger der Team-Mail zu einer Servicebuchung. |
| `wpcap_service_booking_rebooking` (Action) | `int $booking_id, int $target_slot_id` | In `ServiceBookings::rebook()` innerhalb der Transaktion nach allen Prüfungen, bevor die Buchung verschoben wird (Ressourcen, Leistungserbringer, beide Zeitfenster und die Buchung sind gesperrt). Für Erweiterungen und deterministische Wettlauf-Tests; keine eigene Transaktion starten. |
| `wpcap_service_booking_heading_level` | `int $level` (2), `int $event_id` | Überschriftenebene der Terminbuchung (`[wpcap_service_booking]` / Block `pass-captain/service-booking`, A268): Eventname auf dieser Ebene, Schritte eine, Tage zwei Ebenen darunter (2–5). |
| `wpcap_service_booking_page_url` | `string $url` (erste veröffentlichte Seite mit Shortcode/Block für das Event oder `''`), `int $event_id` | Link „Auf der Website ansehen“ auf der Admin-Seite „Service-Zeitfenster“ (A268). |
| `wpcap_checkout_billing_enabled` | `bool $enabled` (true), `object\|null $event` (`null` in der Terminbuchung) | Abschnitt „Rechnung mit Firmen-/Adressdaten“ im Checkout-Formular (`templates/event-card.php`) und in der Terminbuchung für kostenpflichtige Ressourcen anzeigen. |
| `wpcap_developer_key_rate_limit` | `int $limit` (eigenes Limit des Schlüssels, sonst 600), `int $api_key_id`, `int $own` (gespeichertes Limit, 0 = Standard) | Anfragen pro Minute für einen Entwickler-API-Schlüssel (`DeveloperKeys::rate_limit()`, A161); Rückgabe mindestens 1. |
| `wpcap_seating_availability_limit` | `int $limit` (120) | Abrufe der Sitzplatzverfügbarkeit je Client und Minute. |
| `wpcap_seating_auto_assign_accessible` | `bool $allow` (false), `int $occurrence_id` | Ob die automatische Platzvergabe Rollstuhlplätze nutzen darf (dann nach allen anderen Plätzen); Begleitplätze vergibt sie nie (A485). Siehe [OPERATIONS.md](OPERATIONS.md#saalpläne-rollstuhl--und-begleitplätze-a485). |
| `wpcap_now` | `int $timestamp` (`time()`) | Aktuelle Zeit der Geschäftsregeln (`PassCaptain\Clock`): Reservierungen, Verkaufsfenster, Erinnerungen, Servicebuchungsfristen, Wartelistenangebote, Kalender-DTSTAMP, Standardzeiträume der Berichte (Tests/Simulation, A199). |
| `wpcap_checkin_now` | `int $timestamp` | Zeitpunkt für Einlassentscheidungen (Tests/Simulation); Ausgangswert ist `Clock::now()`. |
| `wpcap_checkin_log_results` | `string[] $results` (`Checkin::LOG_RESULTS`) | Ergebnisse, nach denen das Check-in-Protokoll (**Check-ins**) gefiltert werden kann. Feste Liste statt `SELECT DISTINCT` über das ganze Protokoll (A433); eigene Ergebniswerte hier ergänzen. |
| `wpcap_user_has_2fa` | `bool $has, int $user_id` | Zwei-Faktor-Status für Erstattungen. |
| `wpcap_portal_refund_request_allowed` | `bool $allowed` (`true`), `object $order` | Ob die Käufer:in dieser Bestellung im Bestellportal eine Stornierung anfragen darf – nach den Kernregeln (Einstellung `portal_refund_request_hours` > 0, bezahlt, keine WooCommerce-Bestellung, keine Freikarte). |
| `wpcap_portal_refund_rate_limit` | `int $limit` (5), `object $order` | Stornierungsanfragen im Bestellportal je Bestellung und Stunde (zusätzlich 20 je Client). |
| `wpcap_voucher_purpose` | `string|null $purpose, array $voucher, array $context` | Art eines Gutscheins: `single` (Einzweckgutschein, USt beim Gutscheinverkauf; Einlösung = Preisnachlass) oder `multi` (Mehrzweckgutschein, Einlösung = Zahlungsmittel, USt auf den vollen Preis). `$purpose` ist die Angabe aus den Voucher-Captain-Daten (oder `null`); `$voucher`: Anbieterdaten (Checkout: `quote`, `reservation`; Ticketdienst: `$source`); `$context`: `source` (`checkout`/`ticket_service`), `order_id`, `code_hint`, `ticket_type_id`, `quantity`. Rückgabe `null`/unbekannt → Einstellung `voucher_default_purpose` (Standard `multi`). Siehe docs/VOUCHERS.md. |
| `wpcap_woocommerce_hold_minutes` | `int $minutes` | Reservierung bei WooCommerce-Checkout. |
| `wpcap_woocommerce_on_hold_minutes` | `int $minutes` (3 Tage) | Reservierung für WooCommerce-Bestellungen „in Wartestellung“. |
| `wpcap_max_line_quantity` | `int $limit` (100) | Absolute Obergrenze der Menge je Bestellzeile in `Orders::create()`, geprüft vor dem Sperren (Schutz gegen absurde Mengen); `max_per_order` der Ticketart gilt zusätzlich. |
| `wpcap_client_ip` | `string $ip` (Ergebnis von `ClientIp::get()`), `array $server` (Server-Variablen des Requests) | Client-IP für Ratenbegrenzungen (`Orders::client_ip()`). Für Proxys genügt in der Regel die Einstellung „Vertrauenswürdige Proxys“ (siehe `docs/SECURITY.md`); dieser Filter bleibt für Sonderfälle. Einen Header nur übernehmen, wenn die direkte Gegenstelle der vertrauenswürdige Proxy ist; ungültige Rückgaben fallen auf das `ClientIp`-Ergebnis zurück. |
| `wpcap_client_ip` | `string $ip, array $server` | Client-IP für Ratenbegrenzungen (Standard `REMOTE_ADDR`, bzw. Header eines vertrauenswürdigen Proxys, siehe `wpcap_trusted_proxies`). Ungültige Rückgaben fallen auf die erkannte Adresse zurück. |
| `wpcap_trusted_proxies` | `string[] $proxies` (Einstellung `trusted_proxies` bzw. Konstante `WPCAP_TRUSTED_PROXIES`) | IP-Adressen/CIDR-Bereiche, deren Weiterleitungs-Header ausgewertet wird (`ClientIp::get()`). Leer = immer `REMOTE_ADDR`. Ungültige Einträge werden ignoriert. |
| `wpcap_trusted_proxies` | `string[] $proxies` | Vertrauenswürdige Reverse Proxies/CDN (IP oder CIDR); nur von ihnen wird der eingestellte Client-IP-Header ausgewertet (docs/SECURITY.md). |
| `wpcap_client_ip_header` | `string $header` (`x-forwarded-for`, `forwarded` oder `cf-connecting-ip`) | Header mit der Client-Adresse hinter einem vertrauenswürdigen Proxy. Bei `X-Forwarded-For`/`Forwarded` gilt die rechteste nicht vertrauenswürdige Adresse. |
| `wpcap_rate_limit_backend` | `string $backend` (`cache` mit persistentem Objekt-Cache, sonst `db`) | Zähler von `RateLimit::allow()`: `cache` (`wp_cache_incr`, nur mit gemeinsamem Redis/Memcached atomar), `db` (Tabelle `wpcap_rate_limits`, atomar) oder `transient` (nicht atomar, nur als Rückfall). |
| `wpcap_service_booking_token_limits` | `array $limits` (`client` 10, `client_window` 3600, `booking` 50, `booking_window` 600) | Grenzen für falsche Buchungstokens der Service-Selbstbedienung (`ServiceBookingFlow::verify_token()`): je Client und zusätzlich kurz je Buchung (A279). |
| `wpcap_rate_limit_ipv6_prefix` | `int $bits` (64) | Präfixlänge, unter der IPv6-Clients in Ratenlimits als ein Client zählen (`ClientIp::limit_key()`, 32–128, A280). |
| `wpcap_rate_limit_ipv6_network_factor` | `int $factor` (4) | Vielfaches des Limits, das ein ganzes IPv6-/48-Netz bei Kontingent belegenden Anfragen (Checkout, Sitz-/Service-Holds, Warteliste; `RateLimit::allow_client()`) zusammen nutzen darf (A280). |
| `wpcap_ticket_recover_client_limit` | `int $limit` (5) | Anfragen „Ticketlinks per E-Mail anfordern“ (`TicketRecovery`, A622) je Client und Stunde. |
| `wpcap_alert_recipients` | `string[] $recipients`, `string $topic`, `array $context` | Empfänger eines Betriebsalarms (`Alerts::raise()`, A400; Standard: Einstellung „Betriebsalarme an“, sonst Administrator-E-Mail). Themen u. a. `refund_approval`, `payment_review`, `paid_without_tickets`, `webhook_disabled`, `fiscal_failure`, `sms_budget`. |
| `wpcap_alert_send` | `bool $send` (true), `string $topic`, `array $context` | `false` hält die Alarm-Mail zurück (z. B. bei Weiterleitung über `wpcap_alert` an ein Chat-/Ticketsystem); der Alarm wird trotzdem im Systemstatus gespeichert. |
| `wp_pass_captain_ticket_read_v1` | `array $data`, `object $row` | Lesemodell eines Tickets für Suite-Plugins (`wpcap_get_ticket()`, A709); nur Schlüssel ergänzen, nie personenbezogene Daten. |
| `wpcap_dashboard_widget_upcoming` | `int $count` (5) | Anzahl der nächsten Termine im Dashboard-Widget „Pass Captain: Überblick“ (A615, 1–20). |
| `wpcap_config` | `int $value`, `string $key` | Laufzeitwerte (A408, `Config::get()`, auf Grenzen begrenzt): `jobs_max_attempts` (8, 1–50), `jobs_stale_minutes` (15, 2–240), `http_timeout` (15 s, 3–60, Zahlungsanbieter/Google Wallet), `refund_wait_minutes` (10, 1–240, Wartezeit bei Erstattungen in Bearbeitung), `webhook_lock_minutes` (10, 1–60, Übernahme hängender Zahlungs-Webhooks). |
| `wpcap_scanner_group_max` | `int $max` (20) | Anzahl weiterer Tickets derselben Bestellung, die der Scanner nach einem Einlass zum Gruppen-Check-in anbietet (A596, 0 = aus, höchstens 100). |
| `wpcap_template_page` | `string $html`, `string $name`, `array $vars` | Ganze Seite bzw. Mail-Layout nach einem möglichen Theme-Override (A407). Theme-Vorlagen in `<theme>/pass-captain/`: `ticket-page.php` (`$ticket`, `$holder`, `$default_html`), `order-portal.php` (`$order`, `$reference`, `$default_html`), `ticket-recovery.php` (`$notice`, `$step`, `$default_html`), `email-layout.php` (`$content`, `$subject`, `$site`, `$logo`, `$color`, `$footer`, `$dir`, `$lang`, `$default_html`); die Vorlage escaped selbst. |
| `wpcap_settings_rich_editor` | `bool $enabled` (true), `string $key` | HTML-Einstellungen (Rechtstexte) mit dem WordPress-Editor statt einfachem Textfeld (A298). |
| `wpcap_help_links` | `array $links` (Beschriftung => URL) | Links in der Hilfe-Seitenleiste der Plugin-Seiten (A379), z. B. eine öffentliche Dokumentation. |
| `wpcap_dashboard_widget_ttl` | `int $seconds` (300) | Cache-Dauer der Zahlen des Dashboard-Widgets (A615). |
| `wpcap_bank_girocode` | `bool $show` (true), `object|null $order` | GiroCode (EPC-QR) der Überweisungsdaten in Checkout und Bestellportal anzeigen (A601; nur EUR mit IBAN und Betrag); `false` blendet ihn aus. |
| `wpcap_checkout_error_field` | `string $field, WP_Error $error` | Formularfeld (`data.field`), dem ein Checkout-Fehler zugeordnet wird (z. B. `email`, `campaign_code`, `voucher_code`); leer = keinem Feld. |
| `wpcap_checkout_email_domains` | `string[] $domains` | Verbreitete Mail-Domains, mit denen das Checkout-Formular die Domain der Käufer-E-Mail vergleicht (Tippfehler-Hinweis „Meintest du …?“, A553). Einträge werden kleingeschrieben, ungültige verworfen; leere Liste = kein Hinweis. |
| `wpcap_manual_open_orders_per_email` | `int $limit` (2) | Offene, unbezahlte Überweisungsbestellungen (`manual`) je E-Mail-Adresse; darüber lehnt der Checkout mit HTTP 429 ab. |
| `wpcap_manual_orders_per_client` | `int $limit` (5) | Überweisungsbestellungen je Client-IP (IPv6 je /64, dazu das /48-Netz mit dem Vierfachen) in einem festen Fenster von der Länge der Reservierungszeit (mindestens eine Stunde); atomar über `RateLimit::allow_client()` (A288). |
| `wpcap_manual_hold_min_units` | `int $units` (10) | Untergrenze der Obergrenze für unbezahlte Überweisungsbestellungen (A634): Offene `manual`-Bestellungen dürfen je Ticketart bzw. Termin mit Kapazität zusammen höchstens `manual_hold_max_percent` (Standard 50 %) der Kapazität halten, mindestens aber diese Anzahl. |
| `wpcap_campaign_preview_rate_limit` | `int $limit` (30) | Unverbindliche Rabattvorschauen (`Campaigns::preview()`) je Client-IP in 10 Minuten. |
| `wpcap_campaign_code_conflict_limit` | `int $limit` (10) | A650: Versuche je Person und Stunde, einen bereits vergebenen Rabattcode anzulegen, bevor mit 429 abgelehnt wird (Administratoren unbegrenzt). |
| `wpcap_expire_holds_batch` | `int $limit` (200) | Bestellungen, deren Reservierungen ein Lauf von `Orders::expire_holds()` höchstens ablaufen lässt. |
| `wpcap_issue_order_lines` | `object[] $lines, object $order` | Bestellzeilen (mit Ticketart-, Termin- und Eventspalten), die bei der Ausgabe Tickets erhalten; läuft in der Ausgabe-Transaktion mit gesperrter Bestellung. Module entfernen Zeilen ohne eigenen Einlass (z. B. die Aufpreis-Bestellung eines Umtauschs). |
| `wpcap_max_holder_changes` | `int $max` (1), `int $ticket_id` | Höchstzahl der Inhaberwechsel eines Tickets: Zuweisung an eine andere E-Mail-Adresse **oder** (A569) Namensänderung auf eine andere Person (Bestellportal, Ticketseite; eine Übertragung zählt mit). Darüber hinaus `wpcap_assignment_limit`; eine Übertragung wird ab dieser Zahl abgelehnt (`wpcap_transfer_holder_changed`). Gezählt in `wpcap_tickets.holder_changes` (Schema 3.10.22). |
| `wpcap_max_holder_changes` | `int $max` (1), `int $ticket_id` | Wie oft der Inhaber eines Tickets per Zuweisung wechseln darf; zählt auch für die Übertragung. |
| `wpcap_max_attendee_name_changes` | `int $max` (3), `int $ticket_id` | A569: Höchstzahl der Änderungen des Teilnehmernamens eines Tickets (auch Korrekturen) über Bestellportal und Ticketseite, danach `wpcap_assignment_name_limit`; Korrekturen im Ticket-Admin sind nicht begrenzt. Gezählt in `wpcap_tickets.name_changes`. |
| `wpcap_attendee_name_correction_distance` | `int $distance` (2) | A569: Änderungen (Levenshtein-Distanz der normalisierten Namen – Kleinschreibung, Leerzeichen, Wortreihenfolge egal), bis zu denen eine Namensänderung als Korrektur derselben Person gilt und keinen Inhaberwechsel zählt; ein ergänzter oder weggelassener Namensteil gilt immer als Korrektur. |
| `wpcap_refundable_tickets` | `object[] $rows` (`ticket_id`, `ticket_type_id`), `int $order_id` | Erstattbare Tickets einer Bestellung (nicht erstattet und gültig, eingecheckt oder wegen Termin-/Eventabsage widerrufen). |
| `wpcap_order_started` | `bool $started, int $order_id, string $now` (UTC `Y-m-d H:i:s`) | Ob ein Termin der Bestellung bereits begonnen hat (`Partners\Sales::order_started()`, Stornoprüfung der Partner-API). Das Pass-Modul liefert `true`, wenn ein verknüpfter Termin eines Passes der Bestellung begonnen hat. |
| `wpcap_guestlist_max_tickets_per_entry` | `int $max` (20, 1–100) | Höchstzahl Tickets je Gästelisteneintrag (Gast plus Begleitungen). |
| `wpcap_portal_resend_limit` | `int $limit` (3) | Neuversand der Tickets einer Gutschein-Umwandlung (`TicketService::resend_tickets()`) je Bestellung und Stunde; die Codes werden dabei erneuert. |
| `wpcap_portal_assign_rate_limit` | `int $limit` (20), `object $order` | Teilnehmerzuweisungen über die Route `/orders/{id}/assign` je Bestellung und Stunde (nur mit gültigem Bestelllink gezählt; zusätzlich 30 je Client und Stunde), A635. |
| `wpcap_attendee_name_has_link` | `bool $found`, `string $name` | Ob ein Teilnehmername der Zuweisung im Bestellportal eine Web-Adresse enthält (`scheme://`, `www.`, `host.tld/…`); dann 400 `name_link` (A635). |
| `wpcap_checkout_attempt_factor` | `int $factor` (4) | Checkout-Versuche (auch gescheiterte) je Netz bzw. Netz + E-Mail und Stunde als Vielfaches der Bestell-Limits; atomar gezählt, Obergrenze auch für parallele Anfragen. |
| `wpcap_checkout_unpaid_per_network` | `int $limit` (10) | Unbezahlte Bestellungen (Reservierungen), die ein Client-Netz innerhalb einer Haltezeit (höchstens einer Stunde) anlegen darf. |
| `wpcap_checkout_proxy_suspect` | `string $kind, array $server` | Erkennung „hinter Proxy/CDN ohne eingetragene vertrauenswürdige Proxys“: `private` (private/Loopback-`REMOTE_ADDR`; die Limits je Netz entfallen, es gilt nur Netz + E-Mail), `header` (Weiterleitungs-Header von öffentlicher Adresse; Limits je Netz werden mit `wpcap_checkout_proxy_limit_factor` angehoben) oder `''`. Systemstatus warnt in beiden Fällen. |
| `wpcap_checkout_proxy_limit_factor` | `int $factor` (10) | Anhebung der Checkout-Limits je Netz, wenn Anfragen einen Weiterleitungs-Header ohne konfigurierte vertrauenswürdige Proxys tragen. |
| `wp_pass_captain_waitlist_ip_limit_v1` | `int $limit` (60) | Event-Wartelisten-Anmeldungen je Client-IP und Stunde über alle Events (zusätzlich zu 5 je Adresse und Event sowie 20 je IP und Event); mindestens 1. |
| `wpcap_secret_settings` | `string[] $keys` | Einstellungen, die verschlüsselt gespeichert und nur über `Settings::secret()` gelesen werden. |
| `wpcap_outbound_resolve` | `null $ips, string $host` | Liefert die Adressen eines Hosts für die SSRF-Prüfung (Tests/eigene Resolver); `null` = DNS. |
| `wpcap_fiscal_allow_local_service` | `bool $allow, string $url` | Erlaubt HTTP zu lokalen Fiskaldiensten (Loopback/privat); Standard: Einstellung `fiscal_allow_local_service`. |
| `wpcap_order_fiscal_ready` | `bool $ready, int $order_id` | `Orders::issue_and_email()` vor der Ausgabe: `false` hält die Tickets zurück (Fiskalisierungsmodul, bis jeder Beleg der Bestellung bestätigt ist); die Ausgabe gilt dann als aufgeschoben, nicht als Fehler. |
| `wpcap_fiscal_adapters` | `array $adapters` | Fiskaladapter (`Fiscalization\AdapterInterface`, Vertragsversion 2); eingebaute Adapter (fiskaly, efsta, fiskaltrust) mit Priorität 5. |
| `wpcap_fiscal_credentials` | `array $credentials, int $seller_id, string $provider_id` | Zugangsdaten aus einer vertrauenswürdigen Integration zur Laufzeit (überschreibt gleichnamige Werte aus dem Tresor); nie loggen. |
| `wpcap_fiscal_profile_scope` | `array $scope, string $channel, array $internal, int $order_id` | Standort/Kasse (`location_id`, `register_id`) für die Profilauswahl; Standard: Kassensitzung bzw. 0/leer. Kundendaten werden nie verwendet. |
| `wpcap_woocommerce_fiscal_item_seller` | `array $assignment, WC_Order_Item $item, string $kind, ?WC_Product $product, WC_Order $order` | Rechtsträger (`seller_id`, `seller_mode`) einer Nicht-Ticket-Position (`product`, `fee`, `shipping`) für Fiskalbelege. |
| `wpcap_wallet_google_token_uris` | `string[] $uris` | Erlaubte OAuth-Token-Endpunkte für das Google-Wallet-Servicekonto. |
| `wpcap_wallet_inline_limit` | `int $limit` | Wie viele geänderte Tickets am Ende einer Web-Anfrage sofort an Apple/Google gepusht werden (A374). Standard `0`: nur als Job einreihen, der Worker pusht. |
| `wpcap_error_log` | `bool $send, string $line` | `false` unterdrückt die (bereits redigierte) Error-Log-Zeile. |
| `wpcap_pre_send_download` | `null $pre, string\|resource $body, string $filename, string $type` | Nicht-`null` übernimmt die Auslieferung eines Admin-Downloads (CSV/PDF). Gestreamte Exporte (Audit-Log, Fiskal-Archiv) übergeben statt des Strings einen zurückgespulten Stream. |
| `wp_pass_captain_delayed_payment_hold_minutes_v1` | `int $minutes` (10080 = 7 Tage), `object $order`, `string $provider` | Wie lange die Reservierung einer Bestellung gehalten wird, sobald eine verzögerte Zahlart gestartet wurde (Stripe-Session abgeschlossen mit `payment_status=unpaid`, z. B. SEPA-Lastschrift; Mollie-Überweisung/Lastschrift `open`/`pending`, auch das Fälligkeitsdatum `dueDate` der Mollie-Überweisung; PayPal-Capture `PENDING`; Mollie-Autorisierung bis zum Capture). – höchstens bis zum Beginn des frühesten Termins der Bestellung. Eine bereits abgelaufene Reservierung wird nicht wiederbelebt; die spätere Zahlung läuft dann über die Nachprüfung verspäteter Zahlungen (Verkaufszustand: Ticketart aktiv, Termin nicht abgesagt und nicht vorbei; Kontingent, Sitzplätze, Käuferlimit, Rabattcode). |
| `wpcap_paypal_capture_hold_minutes` | `int $minutes` (15) | Um so viele Minuten wird die aktive Reservierung vor einem PayPal-Capture (Rückkehr bzw. APPROVED-Webhook) verlängert, damit die Bestätigung danach sie noch findet. Ohne aktive Reservierung wird nicht gecaptured. |
| `wp_pass_captain_lock_wait_timeout_v1` | `int $seconds` (5) | Sekunden, die ein Checkout (`Orders::create()`) bzw. eine Zahlungsbestätigung (`Orders::confirm_external_payment()`) auf eine Zeilensperre wartet (`innodb_lock_wait_timeout` der Sitzung, danach wird der vorherige Wert wiederhergestellt). Deadlocks und Lock-Wait-Timeouts wiederholt der Checkout intern bis zu zweimal, danach antwortet er mit 503 `retryable` und `Retry-After`. `0` lässt die Servereinstellung unverändert. |
| `wp_pass_captain_refund_failed_v1` | `int $refund_id`, `int $order_id` | Action: Eine bereits verbuchte Erstattung ist beim Zahlungsanbieter nachträglich fehlgeschlagen (Stripe `refund.failed`/`charge.refund.updated`, Mollie-Erstattung `failed`). Die Erstattung steht auf `failed`, der erstattete Betrag der Bestellung ist zurückgenommen, die Rechnungskorrektur storniert (`Refunds::provider_failed()`); z. B. für Abrechnungs- oder Fiskal-Add-ons. |
| `wpcap_checkout_buyer_name` | `bool $enabled` (true), `object $event` | Ob die Eventkarte das optionale Feld „Dein Name“ zeigt (gespeichert als `wpcap_orders.customer_name`). |
| `wpcap_waitlist_frontend` | `bool $enabled` (true), `object $event, object\|null $type` | Ob ausverkaufte Ticketarten auf der Eventkarte „Auf die Warteliste“ anbieten (`POST /events/{id}/waitlist`, Double-Opt-in-Hinweis); `$type` ist `null` für die Formular-Einstellung (Live-Umschaltung auf „ausverkauft“). |
| `wpcap_events_auto_list_threshold` | `int $max` (3) | Bis zu so vielen Terminen zeigt `[wp_pass_captain_events]` mit `layout="auto"` (Standard) die vollständigen Ticketformulare, darüber die kompakte Terminliste mit „Tickets“-Button. |
| `wpcap_availability_poll_seconds` | `int $seconds` (20, erlaubt 15–60) | Abfrageintervall der Live-Verfügbarkeit in `assets/checkout.js`, solange die Seite sichtbar ist (zusätzlich genau zum nächsten Verkaufsstart). |
| `wpcap_order_access_days` | `int $days` (30) | A424: Gültigkeit des persönlichen Bestelllinks in Tagen ab jetzt, mindestens aber so viele Tage nach dem Ende des letzten Termins der Bestellung. |
| `wpcap_availability_rate_limit` | `int $per_minute` (120) | Anfragen an `GET /pass-captain/v1/availability` (öffentlicher Endpunkt je Ticketart; das eigene Kassenskript fragt `GET /checkout/live`) je Client-Netz und Minute (429 mit `Retry-After: 30`). |
| `wpcap_availability_cache_control` | `string $header` (`public, max-age=5, s-maxage=5, stale-while-revalidate=10`) | `Cache-Control` der Verfügbarkeitsantwort (z. B. `no-store` ohne CDN). |
| `wpcap_vat_id_validate` | `true|WP_Error|false $result, string $vat_id, array $billing` | Zusätzliche Prüfung der UID-Nummer (z. B. VIES-Abfrage durch ein Add-on). Der Kern selbst fragt keinen externen Dienst ab. |
| `wpcap_refuse_mixed_seller_orders` | `bool $refuse` (true), `int $order_id` | Bestellungen mit Tickets verschiedener Rechnungssteller (Verkaufsmodelle) im Checkout ablehnen. |
| `wpcap_admission_day_cutoff_hour` | `int $hour, array $context` (`occurrence_id`, `event_id`) | Einlass-Tageswechsel (0–12 Uhr Ortszeit) für tägliche Einlassregeln; Standard aus der Einstellung `admission_day_cutoff_hour`. |
| `wpcap_offline_refresh_interval` | `int $seconds` (10) | Mindestabstand zweier Snapshot-Aktualisierungen eines Termins für den Offline-Scanner. |
| `wpcap_offline_scanner_locale` | `string $locale` | Website-Sprache, in der der Offline-Scanner seine Texte ausliefert, wenn das Gerät keine unterstützte Sprache anfragt (Standard `determine_locale()`). |
| `wpcap_offline_scanner_locales` | `string[] $locales` | Sprachen, in denen `GET /app/offline/i18n?locale=` die Texte ausliefern kann (Standard: `de_DE`, mitgelieferte Übersetzungen in `languages/`, installierte Sprachpakete, Website-Sprache). |
| `wpcap_offline_scanner_strings` | `array $strings` (deutscher Quelltext → Übersetzung), `string $locale` | Übersetzungstabelle des Offline-Scanners. |
| `wpcap_privacy_defer_upcoming` | `bool $defer` (true), `int $order_id`, `string $kind` (nur bei Pässen: `member_pass`), `int $pass_id` | Löschanfragen für Bestellungen mit gültigen Tickets für kommende Termine bzw. für gültige Mitgliedspässe (`$order_id` = 0) zurückstellen (Art. 17 Abs. 3 DSGVO) statt Tickets/Pässe sofort zu entwerten. |
| `wp_pass_captain_checkout_allowed_v1` | `true $allowed, array $context` → `true|WP_Error` | Zugangsschranke vor öffentlichen Käufen (z. B. virtueller Warteraum, `PassCaptain\Sales\CheckoutGate`): nativer Checkout (`POST /checkout` und jedes `Orders::create()` ohne Kanal-Namensraum und ohne vertrauenswürdige Adapter-Optionen, vor der Bestell-Transaktion; Wiederholungen mit bekanntem Idempotency-Key werden nicht gefragt), Kunden-Servicebuchungen (`ServiceBookings::reserve()` mit `actor` `customer`), WooCommerce (In den Warenkorb klassisch und Store API, Absenden des klassischen Checkouts, Store-API-Checkout vor der Reservierung). **Nicht** für Kasse, Admin, Wartelisten-Angebote, Gutschein-Umwandlung, WooCommerce-Bestellverarbeitung nach dem Checkout. `$context`: `version` (1), `source` (`native`, `service_booking`, `service_pass_booking`, `woocommerce`, `woocommerce_store_api`), `phase` (`checkout`, `cart`, `booking`), `items` (Ticketart-ID → Menge), `ticket_type_ids`, `occurrence_ids`, `event_ids`, `slot_id`, `email`, `idempotency_key`, `request` (`WP_REST_Request` oder `null`). Ein `WP_Error` erreicht den Käufer: Status aus `status` (Standard 503), `retry_after` (Sekunden) wird bei REST-Antworten zum Header `Retry-After`, in der Store API Teil der Fehlerdaten. Andere Rückgaben als `true` gelten als Ablehnung (503). Exceptions: fail open (protokolliert, Kauf läuft weiter). |
| `wpcap_tax_profile_date` | `string $date, object $type, object $occurrence, string $order_date` | Stichtag (`Y-m-d`) für die Auswahl der Steuerprofil-Version einer Position; Standard: lokales Datum des Terminbeginns in der Termin-Zeitzone (Leistungszeitpunkt), `$order_date` = lokales Bestelldatum (docs/TAX-PROFILES.md). |
| `wpcap_buyer_limit_email` | `string $email` | Normalisierte E-Mail für Käuferlimits und Checkout-Ratenlimits (Standard: kleingeschrieben, getrimmt, Aliase zusammengefasst – siehe `wpcap_buyer_email_aliases`). Die Datenbankabfrage der Käuferlimits kennt nur die Standard-Normalisierung. |
| `wpcap_buyer_email_aliases` | `bool $fold` (true) | Ob E-Mail-Aliase zusammengefasst werden: `+tag` im lokalen Teil entfällt, bei `gmail.com`/`googlemail.com` entfallen Punkte und die Domain wird `gmail.com`. `false` = nur Kleinschreibung. |
| `wpcap_buyer_limit_lock_wait` | `int $seconds` (10) | Wartezeit auf die Käufer-Sperre (`GET_LOCK`) parallel laufender Bestellungen derselben Person; danach 503 „Gerade ist sehr viel los“. |
| `wpcap_checkout_user_roles` | `string[] $roles, int $user_id` | Rollen der angemeldeten Person für die Freigabe des Auslass-Scans (Check-out, Richtlinie „Check-out-Rollen“ `checkout_roles`); Administratoren sind immer freigegeben. |
| `wpcap_woocommerce_mixed_seller_policy` | `string $policy` (`reject`/`split`) | Umgang mit WooCommerce-Warenkörben mit Tickets verschiedener Rechnungssteller; Standard aus der Option `wpcap_woocommerce_mixed_sellers` (`reject`). Siehe docs/WOOCOMMERCE.md, „Mehrere Rechnungssteller“. |
| `wpcap_woocommerce_refund_checked_in` | `bool $allowed, WC_Order|null $wc_order` | Ob WooCommerce-Erstattungen mit Menge eingecheckte Tickets erstatten dürfen; Standard aus der Kerneinstellung `refund_after_checkin` (falls vorhanden), sonst Option `wpcap_woocommerce_refund_checked_in` (`no`). |

### Zahlungen

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_payment_gateways` | `GatewayInterface[] $gateways` | Weitere Zahlungsadapter registrieren (z. B. Mollie). |
| `wpcap_payment_settings_url` | `string $url` (''), `string $provider_id` | Einstellungsseite eines Zahlungsadapters ohne eigenen Abschnitt im Reiter **Einstellungen → Zahlungsarten**; der Reiter verlinkt sie in seiner Unternavigation und in der Übersicht (Mollie: eigene Modulseite). |
| `wpcap_payment_return_url` | `string $url, string $status, string $public_id, string $provider` | Rückkehr-URL nach der Zahlungsseite (`success`, `pending`, `failed`, `cancel`, nach Storno durch die Käuferin `cancelled`; `$provider` dann `return`). Basis ist seit A554 die Seite, auf der der Checkout lief (`return_url` des Formulars, nur URLs dieser Website), sonst die Startseite; die URL trägt `wpcap_rk` (Schlüssel für „Zahlung fortsetzen“/„Bestellung stornieren“), der beim Filtern erhalten bleiben muss. |
| `wpcap_payment_return_url` | `string $url, string $status, string $public_id, string $provider` | Rückkehr-URL nach der Zahlungsseite (`success`, `pending`, `failed`, `cancel`). Standard: die Seite, auf der bestellt wurde (Checkout-Feld `return_to`, nur dieselbe Website), mit `?wpcap_payment=…&order=…&wpcap_rt=…` (Rückkehr-Token für „Erneut bezahlen“/„Reservierung freigeben“, `Payments\ReturnNotice`). |
| `wpcap_stripe_session_params` | `array $params, array $order, array $items` | Parameter der Stripe Checkout Session. |
| `wpcap_paypal_order_body` | `array $body, array $order, array $items` | Body der PayPal-Order. |
| `wpcap_mollie_webhook_ip_limit` | `int $limit` (120) | Mollie-Webhook-Aufrufe je IP und Minute (darüber HTTP 429). Zählt jeden Aufruf mit gültig aussehender ID, auch unbekannte, und wird vor jeder Datenbankabfrage geprüft (A431). |
| `wpcap_mollie_webhook_global_limit` | `int $limit` (1200) | Mollie-Webhook-Abfragen bekannter Zahlungen insgesamt je Minute (unbekannte IDs zählen nicht, damit sie echte Webhooks nicht blockieren). |
| `wpcap_mollie_payment_body` | `array $body, array $order, array $items` | Body von `POST /v2/payments` (Mollie). |
| `wpcap_bank_transfer_details` | `array $details, object $order` | Überweisungsdaten im Checkout, Bestellportal und in Mails (`reference`, `remittance`, `amount_minor`, `currency`, `amount`, `recipient`, `iban`, `bic`, `due_at`, `due_date`, `instructions`, `transfer`). Ohne Empfänger und IBAN (auch nach dem Filter) werden `recipient`, `iban`, `bic` und `remittance` geleert und `transfer` ist `false`: Käufer sehen dann nur Betrag, Frist und Zahlungshinweis. |
| `wpcap_bank_transfer_details` | `array $details` (`reference`, `remittance`, `amount_minor`, `currency`, `amount`, `recipient`, `iban`, `bic`, `due_at`, `due_date`, `instructions`), `object $order` | Zahlungsangaben für Überweisung in Bestätigung, Bestellportal und Bestell-Mail. |
| `wpcap_send_refund_mail` | `bool $send, int $refund_id, object $order` | Ob die Erstattungsbestätigung an den Käufer geht (Standard: ja, außer bei WooCommerce-Bestellungen oder ohne gültige E-Mail-Adresse). |

### E-Mails, Tickets, Darstellung

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_ticket_mail` | `array $mail` (`subject`, `body`, `attachments`), `object $order, array $issued` | Ticket-E-Mail anpassen. `$issued` je Ticket: `ticket_id`, `ticket_type_id`, `occurrence_id`, `token` (Klartext, nur hier), `event`, `ticket`, `starts_at`, `timezone`, `location`. `subject`/`body` sind bereits aus der (ggf. angepassten) Vorlage `tickets` gerendert; im HTML-Modus wird angehängter Klartext automatisch maskiert und umbrochen. Angehängte Dateien werden nach dem Versand gelöscht. Das Kalender-Modul hängt hier `.ics`-Dateien an (Priorität 20). |
| `wpcap_attach_ticket_pdf` | `bool $attach, int $order_id` | PDF-Tickets anhängen. |
| `wpcap_ticket_pdf_footer` | `string $footer, array $ticket` | A330: Fußzeile einer Seite des Ticket-PDFs (Mail, Bestellportal, Admin), z. B. Bedingungen je Ticketart oder Pass. `$ticket`: Angaben des Tickets (`event`, `ticket`, `when`, `occurrence_id`, `ticket_type_id` …, ohne Code). Standard: „Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.“; `''` = keine Fußzeile. |
| `wpcap_pdf_compress` | `bool $compress` (true) | A618: Seiteninhalte der PDFs (Tickets, Rechnungen, Listen) mit FlateDecode komprimieren; `false` z. B. zur Fehlersuche im PDF. QR-Codes werden je Zeile als zusammenhängende Rechtecke gezeichnet. |
| `wpcap_pdf_fonts` | `array $paths` (`regular`, `bold`) | A330: TrueType-Schriften (.ttf mit glyf-Konturen) aller PDFs (Tickets, Rechnungen, Drucklayouts); Standard: mitgelieferte DejaVu Sans / DejaVu Sans Bold. Z. B. eine Schrift mit CJK-Zeichen. OpenType/CFF (.otf) und Sammlungen (.ttc) werden nicht unterstützt; ohne fett-Schnitt wird die Normalschrift mit Kontur fett gezeichnet. Ist keine Schrift nutzbar, gelten Helvetica/WinAnsi mit Transkription. |
| `wpcap_ticket_pdf_data` | `array $ticket` (`event`, `ticket`, `when`, `location`, `seat`, `attendee`, `ticket_id`, `token`, `help_code`), `array $meta` (`title`, `footer`) | A331: Anzeigedaten einer Seite des PDF-Tickets (Mail-Anhang, Bestellportal, Admin). Zusätzliche Schlüssel stehen danach dem Layout-Filter zur Verfügung. `token` (Ticketcode) und `help_code` bleiben immer wie ausgestellt – ein Filter kann sie nicht ändern. |
| `wpcap_mail_recipients` | `array $recipients` (`to` als String oder Liste, `cc` [], `bcc` []), `array $message` (u. a. `type`, `order_id`, `locale`) | A331: Empfänger einer Kunden-Mail des zentralen Mailers, z. B. Kopie an den Veranstalter (`bcc`). Ungültige Adressen und Platzhalteradressen werden verworfen, höchstens 10 je Feld; ohne gültiges `to` bleibt der ursprüngliche Empfänger. `cc`/`bcc` werden zu Mail-Headern. Personendaten nur mit Rechtsgrundlage weitergeben. |
| `wpcap_ticket_pdf_chunk_size` | `int $size` (50) | Tickets je PDF-Anhang der Ticketmail; größere Bestellungen erhalten mehrere PDFs (A580). |
| `wpcap_date_format` | `string $format`, `string $locale` (Sprache der Mail bzw. des Belegs, `''` = Website-Sprache) | A384: Datumsformat (PHP `date()`) von Mails, Tickets und Belegen (`Frontend\Format::date_format()`). Standard: Website-Einstellung, in einer anderen Sprache das Format des Plugin-Katalogs (`_x( 'd.m.Y', 'date format' )`). |
| `wpcap_time_format` | `string $format`, `string $locale` | A384: Uhrzeitformat dazu (`Format::time_format()`, Katalog: `_x( 'H:i', 'time format' )`). |
| `wpcap_ticket_resend_attachments` | `string[] $files` (Ticket-PDF), `object $order`, `array $tickets` (ticket_id, occurrence_id, token, event, ticket …) | A621: Anhänge einer erneut gesendeten Ticketmail (Neuversand, Wiederholung): Kalender-Modul hängt `.ics` an, Wallet die `.pkpass`. |
| `wpcap_mail_attachment_ttl` | `int $seconds` (6 h) | A626: so lange bleiben temporäre Mail-Anhänge (Ticket-/Rechnungs-PDF, `.pkpass`, `.ics`) nach `wp_mail()` liegen, damit Mail-Warteschlangen-Plugins sie später noch senden können; danach löscht sie die stündliche Bereinigung. `0` = sofort nach dem Versand löschen. |
| `wpcap_ticket_mail_attachment_budget` | `int $bytes` (7 MB), `object\|null $order` | Höchstgröße aller Anhänge der Ticketmail vor der Kodierung; weitere PDF-Teile und `.pkpass`-Dateien entfallen dann (Hinweis bzw. Link in der Mail), A580. |
| `wpcap_mail_types` | `array $types` | Registry der Kunden-Mails (`PassCaptain\Mail\Types`): eigene Typen ergänzen oder Labels/Standardtexte ändern (Kern-Typen können nicht entfernt werden). Siehe [MAIL-TEMPLATES.md](MAIL-TEMPLATES.md). |
| `wpcap_mail` | `array $message, string $to` | Jede Kunden-Mail des zentralen Mailers vor dem Versand (`type`, `subject`, `body`, `format`, `attachments`, `locale`, optional `headers`; bei eingebauten HTML-Texten zusätzlich `text_body`/`text_for` – die Textversion gilt nur, solange `body` unverändert bleibt). |
| `wpcap_mail_default_format` | `string $format, string $type` | Format der eingebauten Standardtexte ohne eigene Vorlage: `html` (Standard, mit Layout und Textversion) oder `text`; `$type` ist der Mail-Typ bzw. `reminder` für Erinnerungen. Gespeicherte Vorlagen behalten ihr Format. |
| `wpcap_mail_rtl` | `bool $rtl`, `string $locale` | A522: ob eine Mail von rechts nach links gesetzt wird (`dir="rtl"`); Standard nach `Mail\Layout::RTL_LANGUAGES`. |
| `wpcap_mail_layout_html` | `string $html, string $content, string $subject` | Fertiges HTML-Dokument des E-Mail-Layouts (HTML-Vorlagen). |
| `wpcap_mail_test_recipient` | `string $email, WP_User|null $user` | Empfänger der „Testmail senden“-Funktion (Standard: Adresse der angemeldeten Person). |
| `wpcap_ticket_code_mail_section` | `string $section, int $ticket_id` | Zusatztext je Ticket in Übertragungs-, Zuweisungs- und Neuversand-Mails (z. B. Wallet-Links; nach einem Inhaberwechsel der Einwilligungslink `?wpcap_consent=` für offene Einwilligungen). |
| `wpcap_strict_csp` | `string $policy` | Strikte Content-Security-Policy der vollständig vom Plugin ausgegebenen Seiten (Ticketseite, Rechnung, Einwilligungsseite), z. B. um die Origin eines fremd gehosteten Markenlogos zu `img-src` hinzuzufügen. Zeilenumbrüche werden entfernt, leere Rückgabe = Standard (docs/SECURITY.md). |
| `wpcap_ticket_seat_label` | `string $label, int $ticket_id, array $ticket` | Sitzplatz-/Bereichsbezeichnung für Wallet-Pässe und Druckvorlagen. |
| `wpcap_email_template` | `array|null $template, string $template_key, array $payload, string $recipient` | Vorlage für Nachrichten aus `wpcap_message_queue`; Rückgabe `['subject' => …, 'body' => …]`, optional `format` und `locale` (Sprache der Mail/des Layouts). Nur dieser Filter erzeugt die Mail: Liefert niemand eine Vorlage, wird die Nachricht nicht gesendet (Fehler „Vorlage fehlt“); die gespeicherte Nutzlast dient seit A652 nie als Vorlage. Erinnerungen nutzen den Schlüssel `wpcap_reminder` (A587: Sprache aus `payload.locale`, `$recipient` für `{ticket_links}`). |
| `wpcap_event_card_html` | `string $html, object $event, array $types` | HTML einer Event-Karte im Shortcode `[wpcap_events]` (alias `[wp_pass_captain_events]`) (das Kalender-Modul ergänzt den Anker `id="wpcap-occ-{Termin}"`). |
| `wpcap_event_card_heading_level` | `int $level` | Überschriftenebene (2–6) des Eventnamens in Eventkarten und Karten „Vorverkauf ab …“ (Standard 2). |
| `wpcap_slim_output` | `bool $slim, string $what` (`events`, `calendar`) | A499: schlanke Ausgabe von Events-Shortcode/-Block (Terminliste mit Links statt Bestellformular) und Kalender (Listenansicht); Standard: in Feeds und REST-Antworten außer der Blockvorschau des Editors. |
| `wpcap_passes_heading_level` | `int $level` (Standard: `wpcap_event_card_heading_level` bzw. Attribut `heading`) | A397: Überschriftenebene (2–6) der Pässe im Shortcode `[wpcap_passes]`. |
| `wpcap_passes_buy_url` | `string $url, object $type` (Ticketart des Passes mit `occurrence_id`, `event_name`, …) | A397: Kauflink eines Passes in `[wpcap_passes]` (Standard: Einstellung „Seite mit dem Ticket-Shortcode“ des Kalenders mit `?wpcap_occurrence={Verkaufstermin}#wpcap-occ-{id}`; leer = kein Link). |
| `wpcap_presale_card_html` | `string $html, object $event, string $sales_start` | HTML der Karte „Vorverkauf ab …“ im Shortcode `[wpcap_events]` für einen Termin, dessen Ticketarten alle erst später in den Verkauf gehen (`$sales_start` = frühester Verkaufsbeginn, UTC). |
| `wpcap_event_card_thresholds` | `array $thresholds` (`few_left` = 10, `rescheduled_days` = 0, `visible_types` = 4) | `Frontend\EventCard`: Schwellen für Eventkarte und Kalender – Badge „Wenige Plätze“ ab höchstens `few_left` freien Plätzen (0 = nie), „Verschoben“ bis `rescheduled_days` Tage nach der Verschiebung (0 = bis zum Termin), Ticketarten vor dem Einklappen („Weitere Ticketarten“). |
| `wpcap_occurrence_badges` | `array $badges` (je `key`, `label`), `object $occurrence`, `int\|null\|false $available`, `array $context` (`next`, `doors`, `view`: `card`, `dates`, `calendar`) | Badges eines Termins auf Eventkarte, Terminliste und Kalender (`next`, `cancelled`, `rescheduled`, `soldout`, `few_left`, `doors`); `$available` = freie Plätze (`null` unbegrenzt, `false` unbekannt). |
| `wpcap_waitlist_public_enabled` | `bool $enabled` (true), `object $event` (Listenzeile des Termins) | Ob die Eventkarte das Wartelistenformular anbietet (öffentlich eintragen); die Karte enthält es seit A456 immer (verborgen, solange nichts ausverkauft ist), der Live-Zustand `GET /checkout/live` meldet `waitlist`/`waitlist_types` nach demselben Filter. |
| `wpcap_event_card_quantity_max` | `int $max, object $type, object $event` | Höchstmenge einer Ticketart im Mengenfeld/Stepper der Eventkarte (kann nur gesenkt werden; das Kontingent-Modul setzt das Käuferlimit je Ticketart). |
| `wpcap_event_card_max_total` | `int $max` (0 = ohne), `object $event, array $types` | Höchstmenge aller Ticketarten zusammen auf der Eventkarte (`data-max-total`, vom Stepper und der Formularprüfung beachtet; das Kontingent-Modul setzt das Käuferlimit je Event). |
| `wpcap_calendar_show_cancelled` | `bool $show` (Standard `true`) | Kalender-Shortcode/-Block: abgesagte kommende Termine ohne Link mit Badge „Abgesagt“ anzeigen. |
| `wpcap_currencies` | `string[] $currencies` | Wählbare Währungen (Standard EUR, USD, CHF, GBP, CAD, AUD); auch Währungen ohne (JPY) oder mit drei Nachkommastellen (KWD, BHD) sind möglich, siehe `PassCaptain\Money`. |
| `wpcap_currency_decimals` | `int $decimals` (ISO 4217: 0, 2 oder 3), `string $currency` | Nachkommastellen der Nebeneinheit einer Währung (`Money::decimals()`, 0–4) für Eingabe, Anzeige, CSV/DATEV, Fiskal- und Zahlungsbeträge. Nur für Währungen ändern, die die ISO-Liste nicht kennt, nie für Währungen mit Daten (A200). |
| `wpcap_sender_name` | `string $name` | Name in der Kundenkommunikation (Website-Titel, sonst der Hostname). |
| `wpcap_currency_symbols` | `array $symbols` (ISO-Code → Symbol) | Währungssymbole für die Anzeige; unbekannte Währungen erscheinen als ISO-Code. |
| `wpcap_order_portal_ticket_html` | `string $html` (`''`), `object $ticket, object $order, string $token` | Zusätzliches HTML je Ticket im Bestellportal (z. B. Umtausch-Formular); der Callback muss selbst escapen. |
| `wpcap_ticket_known_code` | `string $code` (`''`), `int $ticket_id` | Klartext-Code, den ein Modul für den **aktuellen** Ticketcode noch kennt (Kern `TicketCodes`, A586, Priorität 5; Wallet-Modul: verschlüsselt gespeicherter Code). Der Ticket-PDF-Download im Bestellportal verwendet ihn statt einen neuen Code auszugeben; ein Wert, der nicht zum gespeicherten Hash passt, wird ignoriert. Nur flüchtig verwenden. |
| `wpcap_store_ticket_codes` | `bool $store` (`true`) | A586: aktuellen Ticketcode verschlüsselt speichern (`wpcap_tickets.code_secret`), damit PDFs ohne neuen Code möglich sind; `false` = nur Hash wie vor 3.10.17. |
| `wpcap_portal_pdf_rate_limit` | `int $limit` (20), `object $order` | Ticket-PDF-Downloads im Bestellportal je Bestellung und Stunde (zusätzlich 60 je Client). |
| `wpcap_portal_pdf_max_tickets` | `int $max` (50, 1–200) | A627: höchstens so viele Tickets je PDF im Bestellportal; größere Bestellungen bekommen einen Download je Teil („Tickets 1–50 von 120“, Formularwert `all-2` …). |
| `wp_pass_captain_ticket_pdf_limits_v1` | `array $limits` (`max_tickets` = 50, `max_bytes` = 5 MB), `object|null $order` | Grenzen für den PDF-Anhang der Ticket-E-Mail. Darüber (mehr Tickets oder größere Datei) wird kein PDF angehängt; die Mail enthält dann einen Hinweis auf die Ticketlinks. `0` = keine Grenze. |
| `wp_pass_captain_print_batch_limit_v1` | `int $limit` (500) | Höchstzahl der Tickets, die ein Stapeldruck mit QR-Codes neu codiert (größere Termine in Teilen, Feld „Ab Ticket Nr.“). |
| `wp_pass_captain_email_template_v1` | `array|null $template, string $template_key, array $payload, string $recipient` | Vorlage für Nachrichten aus `wpcap_message_queue`; Rückgabe `['subject' => …, 'body' => …]`, optional `format` (`text`/`html`), `locale` und `headers` (einzeilige Header, z. B. `List-Unsubscribe`). Erinnerungen nutzen den Schlüssel `wpcap_reminder` (mit Abmeldelink und One-Click-Header). `$recipient` (Empfängeradresse) ab R3. Ein Filter, der eine Exception wirft, hält die Warteschlange nicht mehr an: die Nachricht wird mit Backoff erneut versucht. |
| `wp_pass_captain_ticket_mail_inline_max_v1` | `int $max` (10), `int $order_id` | Bis zu dieser Ticketzahl wird die Ticket-Mail im bestätigenden Request versendet; größere Bestellungen (und jede Bestellung aus einem Zahlungs-Webhook) liefert der Outbox-Job sofort im Hintergrund aus (neue Codes, keine Klartext-Codes in der Datenbank). |
| `wp_pass_captain_ticket_pdf_max_tickets_v1` | `int $max` (200), `int $order_id` | Höchstzahl Tickets im PDF-Anhang einer Ticket-Mail (Erstversand, Zustell-Retry, Neuversand, Neuausstellung); darüber nur Links. |
| `wp_pass_captain_ticket_mail_list_max_v1` | `int $max` (25), `int $order_id` | Ist das PDF mit allen Tickets angehängt, listet der Mailtext nur so viele Tickets plus Hinweis (Gmail kürzt ab ~102 KB). Ohne PDF bleibt die Liste vollständig. |
| `wp_pass_captain_event_card_html_v1` | `string $html, object $event, array $types` | HTML einer Event-Karte im Shortcode `[wp_pass_captain_events]` (das Kalender-Modul ergänzt den Anker `id="wpcap-occ-{Termin}"`). |
| `wp_pass_captain_currencies_v1` | `string[] $currencies` | Wählbare Währungen. |
| `wpcap_buyer_locales` | `array $locales` (Locale → Name) | Sprachen, die Käufer im Checkout wählen können (Quellsprache `de_DE` plus jeder mitgelieferte Katalog in `languages/`); ungültige Einträge werden verworfen. |
| `wp_pass_captain_brand_name_v1` | `string $name` (`Pass Captain`), `string $context` | White Label: Produktname, wo er Nutzern erscheint. Kontexte: `admin_menu` (Hauptmenü), `admin` (Datenschutz-Leitfaden u. a.), `scanner` (Web-Scanner), `offline_scanner` (PWA-Titel, Manifest, Texte), `portal`, `ticket_page`, `pdf` (PDF-Metadaten „Producer“), `text` (übersetzte Texte der Textdomain mit „Pass Captain“, z. B. Admin-Hinweise, Admin-Mails, Web-Scanner-Überschrift, Offline-Scanner-Texte – ersetzt über die gettext-Filter der Domain). Reiner Text, max. 100 Zeichen; leer = Standard. |
| `wp_pass_captain_brand_logo_v1` | `string $url` (`''`), `string $context` | White Label: Logo (http(s)-URL oder Pfad ab `/`) für `scanner`, `offline_scanner` (nur gleiche Herkunft, CSP `img-src 'self'`), `portal`, `ticket_page`. Ohne Logo (Standard) bleibt die Ausgabe unverändert. Rechnungen zeigen den Rechnungssteller und kein Produktlogo. |
| `wp_pass_captain_admin_menu_title_v1` | `string $title` (Brand-Name, Kontext `admin_menu`) | Titel des Kern-Hauptmenüs (auch Netzwerk-Admin). |
| `wp_pass_captain_admin_menu_icon_v1` | `string $icon` (`dashicons-tickets-alt`) | Icon des Kern-Hauptmenüs: Dashicons-Klasse, http(s)-Bild-URL, `data:image/svg+xml;base64,…` oder `none`; Ungültiges fällt auf den Standard zurück. |
| `wp_pass_captain_invoice_mail_attachments_v1` | `string[] $files, int $id, string $type, array $context` | Zusätzliche Anhänge der Mail, die einen Beleg begleitet: Ticket-Mail einer Bestellung mit Rechnung (`invoice`, Kontext `mail` = `tickets`, `order_id`) und Erstattungsbestätigung mit Rechnungskorrektur (`correction`, Kontext `mail` = `refund_confirmation`, `order_id`, `refund_id`). Nur lesbare Dateien werden übernommen; der Kern **löscht sie nach dem Versand** – nur temporäre Dateien übergeben (`PassCaptain\Api\Invoices::temp_file( $name, $inhalt )`). |
| `wp_pass_captain_invoice_links_v1` | `array $links, int $id, string $type, array $context` | Zusätzliche Links/Downloads an einem Beleg, Einträge `label`, `url` (http(s) oder ab `/`), optional `download` (bool); Ungültiges wird verworfen, Ausgabe escaped. Orte (`$context['location']`): `admin_order` (Bestelldetails im Admin), `invoice_view` (HTML-Rechnungsansicht, Admin und Käufer), `portal` (Bestellportal), `settlement_view` (Abrechnung). `$context`: `audience` (`admin`/`customer`), `order_id`, `order_public_id`, `access_token` (nur `customer`; prüfen mit `Api\Invoices::for_order_access()`), `partner_id` (Abrechnung). |

### Kalender, Erinnerungen, SMS, Webhooks

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_calendar_checkout_url` | `string $url, int $occurrence_id` | Link eines Kalendereintrags (Standard: Einzelansicht `?wpcap_occ={id}#wpcap-occ-{id}` auf der Einstellung „Seite mit dem Ticket-Shortcode“, sonst auf der automatisch gefundenen veröffentlichten Seite mit `[wp_pass_captain_events]`/Block). |
| `wpcap_occurrence_default_duration` | `int $seconds` (Einstellung `occurrence_default_duration_hours`, Vorgabe 6 h) | Dauer eines Termins ohne Ende: ab wann er als vorbei gilt (Online- und Abendkassenverkauf, Eventliste, Terminlisten von Scanner und App; `PassCaptain\Occurrence`, A535). Gilt global (auch in SQL), ohne Terminbezug. |
| `wpcap_calendar_default_duration` | `int $seconds` (7200), `object $occurrence` | Dauer für `DTEND`, wenn der Termin kein Ende hat. |
| `wpcap_calendar_organizer` | `string $email` (Standard `''`) | E-Mail-Adresse für `ORGANIZER` in .ics-Dateien (A140). Mit Adresse wird die Datei eines einzelnen abgesagten Termins als `METHOD:CANCEL` ausgeliefert (RFC 5546 verlangt dafür einen Organizer), sonst `METHOD:PUBLISH` mit `STATUS:CANCELLED`. |
| `wpcap_sms_providers` | `ProviderInterface[] $providers` | Weitere SMS-Adapter (`PassCaptain\Sms\ProviderInterface`; optional `idempotent()` und `find_sent()` für unklare Versandergebnisse, A416). |
| `wpcap_sms_number_daily_limit` | `int $limit` (3) | SMS-Einwilligungen je Mobilnummer in 24 Stunden (Missbrauchsschutz; eine Bestätigung per SMS-Code ist nicht implementiert). |
| `wpcap_sms_ip_hourly_limit` | `int $limit` (10) | SMS-Einwilligungen je Client-IP und Stunde. |
| `wpcap_sms_skip_reason` | `string $reason, object $message` | Grund, eine fällige SMS nicht zu senden (leer = senden). Erinnerungs-SMS werden so bei ungültigen Tickets übersprungen. |
| `wpcap_sms_convert_price` | `float\|null $converted` (`null`), `float $amount, string $from, string $to` | Rechnet einen vom SMS-Anbieter gemeldeten Preis in eine andere Währung (z. B. Twilio `USD`) in die Abrechnungswährung (`sms.currency`) um (A141). `null` = nicht umrechenbar; dann gilt der hinterlegte Preis je Segment. |
| `wpcap_sms_http_status_map` | `array $map` | Statuswerte des generischen HTTP-Status-Callbacks (kleingeschrieben) => `delivered`, `undelivered`, `failed` oder `sent` (Standard u. a. `DELIVRD`, `UNDELIV`, `EXPIRED`, `REJECTD`). |
| `wpcap_sms_budget_warning` *(Action)* | `string $level, array $status` | SMS-Monatsbudget: `warn` (Warnschwelle `sms.budget_warn_percent` erreicht) bzw. `stop` (harte Grenze hat eine SMS zurückgehalten), je Kalendermonat (UTC) höchstens einmal, nach Audit-Eintrag und Admin-Mail. `$status`: `month`, `currency`, `budget`, `spent`, `percent`, `warning`, `exhausted`. |
| `wpcap_sms_opted_out` *(Action)* | `string $source, int $withdrawn` | Eine Mobilnummer hat sich abgemeldet (`inbound_stop`: STOP per SMS über Twilio oder den HTTP-Anbieter); `$withdrawn` = widerrufene Einwilligungen. Die Nummer steht danach (als SHA-256-Hash) auf der Sperrliste. |
| `wpcap_webhook_payload_object` | `array $object, string $type` | `data.object` eines ausgehenden Webhooks (keine PII ergänzen). |
| `wpcap_webhooks_max_attempts` | `int $attempts` (8) | Zustellversuche je Webhook-Ereignis. |
| `wpcap_webhooks_disable_after` | `int $failures` (20) | Fehlversuche in Folge, nach denen ein Abonnement deaktiviert wird. |
| `wpcap_webhook_purge_budget` | `float $seconds` (5) | Zeitbudget eines stündlichen Laufs, der abgeschlossene Zustellungen nach der Aufbewahrungsfrist in Stapeln zu 1.000 Zeilen löscht (`Dispatcher::purge_old()`, A437); der Rest folgt im nächsten Lauf. |
| `wpcap_signed_qr_window` | `int[] $window` (`[nicht vor, gültig bis]`, Unix-Sekunden), `object $ticket` | Gültigkeitszeitraum signierter QR-Codes (Standard: ein Jahr vor Beginn bis einen Tag nach dem letzten gültigen Zeitpunkt). Siehe [SECURITY.md](SECURITY.md#signierte-qr-codes-pc1). |
| `wpcap_sms_otp_limits` | `array $limits` (`number_hour` 3, `number_day` 5, `ip_hour` 5, `site_day` 500, `confirm_ip_hour` 30) | Kosten-/Missbrauchsschutz der Nummernbestätigung (PRIV-16): Codes je Nummer pro Stunde/Tag, je Client-IP pro Stunde, je Site pro Tag, Bestätigungsversuche je IP pro Stunde. Werte < 1 werden 1. |
| `wpcap_sms_otp_body` | `string $body, string $code` | Text der SMS mit dem Bestätigungscode; enthält der Text den Code nicht, wird er vorangestellt. |
| `wp_pass_captain_http_connector_data_v1` | `array $data, int $endpoint_id, string $event, array $core_data` | `data` eines HTTP/JSON-Connectors nach der Feldzuordnung (nur wenn eine Zuordnung konfiguriert ist). Personenbezogene Felder sind ohne Opt-in bereits entfernt – hier keine ergänzen. Siehe [EXTENSIONS.md](EXTENSIONS.md#http-json-connector). |
| `wpcap_connector_log_days` | `int $days` (30) | Aufbewahrung zugestellter/fehlgeschlagener Einträge im Zustellprotokoll der HTTP/JSON-Connectors. |

### Datenschutz

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_privacy_retention_days` | `int $days, string $step` | Fristen des täglichen Löschlaufs (`0` = Schritt aus): `unpaid_attendee_data` (1), `unpaid_customer_email` (90), `message_content` (30), `message_rows` (365), `checkin_staff` (365), `audit_logs` (730), `waitlist` (30, Tage nach dem Termin), `partner_ticket_codes` (30, Tage nach Terminende); die Fristen erscheinen so auch im Vorschlag für die Datenschutzerklärung (A567); stündliche Aufräumung (A444): `reservations` (30), `jobs` (30, erledigte/verworfene Jobs), `dead_jobs` (180), `seat_holds` (30), `payment_events` (90). |
| `wpcap_privacy_retention_days` | `int $days, string $step` | Fristen des täglichen Löschlaufs (`0` = Schritt aus): `unpaid_attendee_data` (1), `unpaid_customer_email` (90, auch Rechnungsanschrift), `message_content` (30), `message_rows` (365), `sms_content` (30), `checkin_staff` (365, auch Einlassbuchungen, -konflikte und Gerätebezeichnungen), `audit_logs` (730), `service_holds` (90), `waitlists` (Einstellung, 30), `member_passes` (Einstellung, 365), `woocommerce_failed_attendees` (7), `reservations` (30, abgeschlossene Reservierungen), `jobs` (30, erledigte Hintergrundjobs). Sonderfall `order_years`: Wert in **Jahren** (Einstellung, 10) für die Käuferdaten aller Bestellungen nach der gesetzlichen Frist. |
| `wpcap_attendee_default_retention_days` | `int $days` (365) | Frist für Teilnehmername/-E-Mail nach Terminende, wenn die Einstellung „Teilnehmerdaten löschen nach“ 0 ist (Antworten folgen dann der Frist des Felds). |
| `wpcap_guestlist_companion_name` | `string $name, object $entry, int $index` | Name auf Begleittickets einer Gästeliste (Standard neutral „Begleitung“, z. B. `'Begleitung von ' . $entry->name`). |
| `wpcap_privacy_order_anonymized` (Action) | `int $order_id` | Eine Bestellung wurde anonymisiert (Datenschutz-Löschung); Module löschen ihre Daten zur Bestellung (z. B. gespeicherte Partner-Ticketcodes). |
| `wpcap_privacy_unpaid_attendee_data_cleared` (Action) | `int[] $order_ids` | Der Löschlauf (Schritt `unpaid_attendee_data`) hat die Teilnehmerangaben dieser nie bezahlten Bestellungen entfernt (ein Batch); Integrationen löschen ihre Kopien (z. B. `_wpcap_attendees` an der WooCommerce-Bestellung). |
| `wpcap_print_batch_background` | `bool $background` (true) | Stapeldrucke laufen als Hintergrund-Stapel (A427); `false` behält den synchronen Druck (Audit R2, Codes mit der fertigen Datei festgeschrieben). |
| `wpcap_print_batch_block` | `int $tickets` (200) | Tickets je Lauf des Stapeldruck-Jobs (A427; auf ein Vielfaches von 10 aufgerundet). |
| `wpcap_attendee_list_chunk` | `int $tickets` (500) | A477: Tickets je Block der Teilnehmer- und No-Show-Liste (Druckansicht und CSV werden blockweise gelesen und gestreamt; 1–5000). |
| `wpcap_print_batch_max` | `int $tickets` (20000) | Höchstzahl Tickets eines Stapeldrucks. |
| `wpcap_print_batch_sync_limit` | `int $tickets` (300) | Stapel ohne neue Codes bis zu dieser Größe werden sofort geliefert, größere im Hintergrund. |
| `wpcap_print_batch_inline_budget` | `float $seconds` (3) | Renderzeit im Admin-Request direkt nach dem Anlegen (kleine Stapel sind sofort fertig). |
| `wpcap_print_batch_keep_days` | `int $days` (7) | Aufbewahrung von Stapeldrucken und ihren Dateien nach der letzten Änderung. |
| `wpcap_print_logo_file` | `string $file`, `int $attachment_id` | A685: lokale Datei des Logos einer Druckvorlage (`@logo|<Mediathek-ID>`; Standard `get_attached_file()`); `''` = kein Logo. JPEG oder PNG, höchstens 1 MB. |
| `wpcap_print_batch_dir` | `string $dir` (`uploads/pass-captain-private`) | Privates Verzeichnis der Stapeldruck-Dateien. |
| `wpcap_privacy_retention_budget` | `float $seconds` (20) | Zeitbudget eines Löschlaufs; der nächste Lauf setzt fort. |
| `wpcap_cleanup_budget` | `float $seconds` (10) | Zeitbudget der stündlichen Aufräumung abgeschlossener Zeilen (`Privacy\Retention::housekeeping()`, A444); der nächste Lauf setzt fort. |
| `wpcap_privacy_policy_recipients` | `array $recipients` | Welche Empfänger der Datenschutztext-Vorschlag nennt (`stripe`, `paypal`, `mollie`, `twilio`, `sms_http`, `apple`, `google`, `partners`, `webhooks`, `affiliate`, `woocommerce` → bool). |
| `wpcap_privacy_policy_content` | `string $html, array $recipients` | HTML des Datenschutztext-Vorschlags. |
| `wpcap_data_profile` | `string $profile, int $user_id` | Datenprofil der angemeldeten Person für Masken und Exporte (`full`, `operations`, `support`, `finance`, `partner`, `checkin`, `none`; `Security\DataPolicy::profile()`); unbekannte Werte fallen auf das berechnete Profil zurück. |
| `wpcap_scanner_queue_max_hours` | `int $hours` (24, 1–168) | Wie lange der Web-Scanner Offline-Scans im Browser aufbewahrt. |
| `wpcap_scanner_search_limit` | `int $per_minute` (30) | A544: Suchanfragen pro Minute und Person (bzw. Gerät) in der manuellen Suche des Web-Scanners und der App; darüber HTTP 429. |
| `wpcap_scanner_undo_seconds` | `int $seconds` (120, mind. 10) | A547: Wie lange nach dem eigenen Einlass der Web-Scanner ihn über `/scanner/undo` zurücknehmen darf (der Knopf „Rückgängig“ erscheint 30 s). |
| `wpcap_privacy_defer_upcoming` | `bool $defer` (true), `int $order_id` | Bei einer Löschanfrage Bestellungen mit Tickets für kommende Termine zurückstellen (Anonymisierung automatisch nach dem letzten Termin) statt die Tickets zu widerrufen (Art. 17 Abs. 3 lit. b DSGVO). |
| `wpcap_audit_action_labels` | `array $labels` (Aktion => Name) | A413: lesbare Namen der Aktionen in der Audit-Log-Ansicht; Aktionen ohne Namen erscheinen mit ihrem Code. |
| `wpcap_pii_audit_window` | `int $seconds` (600) | Zeitfenster, in dem wiederholte Ansichten/Lesezugriffe derselben Person auf dasselbe Objekt bzw. dieselbe Liste mit denselben Filtern nur einmal ins Audit-Log kommen (`0` = jeden Aufruf protokollieren); siehe [SECURITY.md](SECURITY.md#audit-log-zugriffe-auf-personenbezogene-daten). |
| `wpcap_pii_audit_rest_routes` | `array $routes` | REST-Routen (Regex → `[Ansicht, Objekttyp, ID-Parameter]`), deren erfolgreiche GET-Antworten als `pii_api_read` protokolliert werden (Warteliste, Gästeliste, Offline-Paket, Partner-API Bestellungen/Tickets/Check-ins). |
| `wpcap_redact_values` | `string[] $values` | Zusätzliche geheime Werte (ab 8 Zeichen), die aus Fehlertexten, Protokollen, Audit-Details und REST-Fehlerantworten entfernt werden; vorbelegt mit allen Zugangsdaten aus `Secrets::KEYS` und den Webhook-Signaturgeheimnissen, siehe [SECURITY.md](SECURITY.md#fehlerausgaben-ohne-secrets-redaction). |
| `wpcap_record_corrected` (Action) | `string $object_type, int $object_id, array $changes` | Personal hat personenbezogene Daten korrigiert (Teilnehmerkorrektur im Ticket-Admin); `$changes`: Feldname → maskiertes `from`/`to`, Audit-Eintrag `record_corrected`. |
| `wpcap_privacy_retention_steps` | `array $counts, int $now, float $deadline` | Eigene Schritte des täglichen Löschlaufs anhängen (Gästelisten, Warteliste, Käuferlimits …): Anzahl je Schrittname ergänzen, `$deadline` (`microtime()`-Grenze) beachten. |

### Wallet

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_wallet_apple_pass_json` | `array $json, array $ticket` | `pass.json` des Apple-Wallet-Passes. |
| `wpcap_wallet_apple_locations` | `array $locations, array $ticket` | Orte für Sperrbildschirm-Hinweise (max. 10). |
| `wpcap_wallet_apple_images` | `array $images` | Bilder des Passes (Dateiname → Bytes). |
| `wpcap_wallet_apns_client` | `ApnsClient|null $client` | Eigener APNs-Client (Tests). |
| `wpcap_wallet_google_locales` | `string[] $locales` (mitgelieferte Kataloge) | A518: Sprachen, für die die gemeinsame Google-Wallet-Klasse übersetzte Plugin-Texte (`translatedValues`) erhält. |
| `wpcap_wallet_google_class` | `array $class, array $ticket` | Google-Wallet-EventTicketClass. |
| `wpcap_wallet_mail_inline_limit` | `int $limit` (10) | Bis zu dieser Ticketanzahl enthält die Ticketmail Wallet-Pässe/-Links je Ticket; darüber „Alle hinzufügen“-Links je Paket (A580). |
| `wpcap_wallet_batch_size` | `int $size, string $provider` | Tickets je „Alle hinzufügen“-Link: Apple `.pkpasses` (Standard und Maximum 10), Google-Speichern-Link mit Objekt-IDs (Standard und Maximum 8, gemessen ~1.500 Zeichen), A580/A583. |
| `wpcap_wallet_health_issues` | `array $issues` | Befunde des Site-Health-Tests „Pass Captain Wallet“ und des Admin-Hinweises (A584/A585): Liste aus `key`, `status` (`critical`/`recommended`), `message`. |
| `wpcap_wallet_rate_limits` | `array $limits` (`serial` 120, `device` 120, `failures` 60, `download` 60, `google` 30, `window` 60 Sekunden) | Ratenlimits des Apple-Wallet-Webservices und der Wallet-Links (A461): je Seriennummer (authentifizierte Geräteanfragen), je Geräte-ID (Seriennummernliste), je Client-Adresse nur Fehlversuche, Download-/Paket- und Google-Speichern-Links je Client. Darüber HTTP 429 mit `Retry-After`. Siehe [WALLET.md](WALLET.md#passkit-webservice). |
| `wpcap_wallet_google_object` | `array $object, array $ticket` | Google-Wallet-EventTicketObject. |

### Administration

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_admin_pages` | `array $pages` (Einträge `['slug', 'title', 'capability', 'callback', 'area']`) | Öffentliche Add-on-API: eigene Seiten im Pass-Captain-Menü (`area` aus `Admin\Menu::groups()`, Standard `settings`); Slugs des Kerns werden ignoriert. |
| `wpcap_settings_tabs` | `array $tabs` (`id => ['title', 'render', 'capability']`) | Öffentliche Add-on-API: eigene Tabs unter **Einstellungen** (`render( string $tab )`, Standardrecht `manage_options`). |
| `wpcap_admin_occurrences_per_page` | `int $count` (50) | A476: Termine je Seite auf der Event-Detailseite (1–500; ohne `paged` öffnet die Seite mit dem nächsten Termin). |
| `wpcap_admin_occurrence_choices` | `int $count` (200) | A476: Höchstzahl Termine in Terminauswahlen und Formularen je Termin (Zuweisungen, Einlassregeln, Offline-Scanner, Serienvorlage, Saalplan, Gästelisten); mit „Termine ab“ verschiebbar. |
| `wpcap_addon_notices` | `array[] $notices` (leer) | Hinweise des optionalen Add-ons (TODO A6, A301): je Eintrag `id`, `message` (Text, kein HTML), optional `url`, `link`, `version`. `Admin\Notices` zeigt höchstens drei, nur auf Pass-Captain-Seiten, nur für `manage_options`, immer je Benutzer:in wegklickbar (eine neue `version` zeigt ihn erneut). |
| `wpcap_schema_retry_delay` | `int $seconds`, `int $attempts` | Wartezeit bis zum nächsten automatischen Versuch eines fehlgeschlagenen Schema-Upgrades (A466; Standard 5 Minuten, je Fehlversuch verdoppelt, höchstens 6 Stunden). Admin-, Cron- und Frontend-Aufrufe warten so lange, WP-CLI (`wp pass-captain upgrade`) und der Knopf im Admin-Hinweis versuchen es sofort. |
| `wpcap_bulk_run_batch_size` | `int $size` | Zeilen je Schritt eines Absage-/Benachrichtigungslaufs (Standard 200, 1–1000; A428). |
| `wpcap_bulk_mail` | `array\|null $spec, array $payload` | Baut eine in einem Lauf (A428) eingereihte Mail eines Moduls beim Versand: `['type' => Mailtyp, 'vars' => Platzhalter]` (optional `ics` => Termin-ID). Das Pass-Modul baut so `pass_kept`/`pass_revoked`. |
| `wpcap_admin_order_detail` (Action) | `object $order, array $tickets` | Zusätzliche Abschnitte auf der Bestelldetailseite im Admin vor den Erstattungen (z. B. Ticketumtausch). |
| `wpcap_occurrence_sold` | `int $sold, int $occurrence_id` | Im Admin angezeigte verkaufte Einheiten eines Termins (bezahlt, nicht erstattet); Module zählen eigene Einheiten hinzu (z. B. an anderen Terminen verankerte Pässe). |
| `wpcap_event_figures` | `array $figures, int[] $event_ids` | Kennzahlen der Eventliste im Admin, je Event `sold` (bezahlt, nicht erstattet), `capacity` (`null` = unbegrenzt) und `revenue` (Währung => Minor Units); Module passen sie gesammelt an (keine Abfrage pro Zeile). |
| `wpcap_getting_started_items` | `array $items, array $state` | Schritte der „Erste Schritte“-Checkliste auf der Eventliste (`key`, `label`, `description`, `done`, `url`, optional `view_url` = Link „Auf Website ansehen“ eines erledigten Schritts); `$state` aus `GettingStarted::state()` (u. a. `require_terms`, `woocommerce` = verknüpftes Ticketprodukt, `sales_page_id`, `scanner_page_id`). `done` aus Daten ableiten, nicht manuell abhaken. |
| `wpcap_admin_remember_input_actions` | `string[] $actions` | admin-post-Aktionen, deren Eingaben nach einem Validierungsfehler für die erneute Anzeige gespeichert werden (A297; Standard: `wpcap_create_event`, `wpcap_update_event`, `wpcap_save_occurrence`, `wpcap_save_ticket_type`). Nur Formulare eintragen, die `Forms::old()` auswerten; keine Formulare mit personenbezogenen Daten. |
| `wpcap_admin_menu_group` | `string $group, string $slug` | Bereich einer Unterseite im Pass-Captain-Menü (`events`, `sales`, `admission`, `finance`, `communication`, `settings`); Module können ihn auch als 5. Element in `admin_pages()` angeben. |
| `wpcap_occurrence_cancellation_preview` | `array $effects` (leer), `int $occurrence_id` | Weitere Folgen einer Terminabsage für die Vorschau im Admin, je Eintrag `[Name, Tickets, Wirkung]` (z. B. verknüpfte Pässe). |
| `wpcap_access_scope` | `array $scope, int $user_id` | Zugriffsbereich einer Nicht-Administratorin (A119, `Access::scope()`): `['locations' => string[], 'partners' => int[]|null]`; leere Ortsliste und `partners` `null` = keine Einschränkung. Wirkt in Admin-Seiten, admin-post-Handlern, REST-Routen, Exporten und Berichten. |
| `wpcap_revoke_access_on_role_change` | `bool $revoke` (true), `int $user_id, string[] $removed_roles` | Ob beim Entzug einer Rolle Event-Teams, Scanner-Geräte, Kopplungscodes, Einlassbereiche und Entwickler-API-Keys der Person zurückgenommen werden (`UserAccess`, A277/A278); `false` z. B. für Rollenverwaltungen, die Rollen in zwei Schritten tauschen. Beim Löschen eines Benutzers gilt der Filter nicht. |
| `wpcap_pii_matrix` | `array $matrix` | PII-Matrix Profil (`full`, `finance`, `door`, `service`, `reports`) → Feld (`buyer_name`, `buyer_email`, `buyer_phone`, `billing`, `attendee_name`, `attendee_email`, `attendee_answers`) → `true`, `false` oder `'scanner_show_attendee'` (siehe [SECURITY.md](SECURITY.md#pii-je-rolle)). |
| `wpcap_pii_can_view` | `bool $allowed, string $field, int $user_id, string[] $profiles` | Ob eine Person ein personenbezogenes Feld im Klartext sieht (`Pii::can_view()`). |
| `wpcap_pii_mask` | `string $masked, string $field, string $value` | Maskierte Darstellung eines Felds (Standard: E-Mail `a***@e***.de`, Namen als Initialen, sonst leer). |

### Kontingente und Käuferlimits

| Hook | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_allocation_limit_exempt_sources` | `string[] $sources` (`pos`, `guestlist`, `admin`) | Bestellquellen, für die Käuferlimits nicht gelten. |
| `wpcap_allocation_buyer_limit_applies` | `bool $applies, string $source, array $selected, array $internal` | Ob das Käuferlimit für diese Bestellung gilt (Standard: nicht für ausgenommene Quellen); läuft in `wpcap_order_creating` und bei später Zahlung in `wpcap_order_recovering` (dann `$selected` leer, `$internal` = `phase` `recover`, `order_id`; `$source` aus der Zahlart, Kasse `pos_*` → `pos`, Gästeliste `comp` → `guestlist`). |
| `wpcap_allocation_normalize_gmail` | `bool $normalize` (Einstellung `buyer_limit_gmail_dots`, Standard an) | Gmail-Adressen für die Käuferidentität normalisieren (Punkte und `+Zusatz` entfernen, `googlemail.com` → `gmail.com`). Den `+Zusatz` aller anderen Domains entfernt die Einstellung `buyer_limit_strip_plus` (A484). |
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
| `wpcap_capacity_rebuild_budget` | `float $seconds` (5.0) | Zeit, die der Upgrade-Request selbst am Neuaufbau der Zähler arbeitet (Upgrade, Rollback; A464/A468); den Rest übernimmt der Cron `wpcap_reconcile_capacity` ab dem gespeicherten Cursor (Option `wpcap_capacity_rebuild`). 0 = nur ein Termin im Upgrade-Request. |
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
| `wpcap_woocommerce_collect_attendees` | `bool $collect, int $type_id` | Ob der WooCommerce-Checkout Teilnehmerdaten je Ticket abfragt (Standard: nur wenn Teilnehmerfelder konfiguriert sind oder der Teilnehmername verlangt wird, A552). |
| `wpcap_attendee_name_required` | `bool $required, int $type_id` | A552: Ob jedes Ticket der Ticketart einen Teilnehmernamen braucht (Standard: Schalter „Name für jedes Ticket verlangen“ der Ticketart). |
| `wpcap_woocommerce_vat_meta_keys` | `string[] $keys, WC_Order $wc_order` | Bestell-Metaschlüssel, aus denen die UID-Nummer gelesen wird (erster nicht leerer Wert). |
| `wpcap_woocommerce_billing` | `array $billing, WC_Order $wc_order` | Aus der WooCommerce-Bestellung übernommene Rechnungsdaten (`company`, `first_name`, `last_name`, `street`, `postcode`, `city`, `country`, `vat_id`, `po_number`). |
| `wpcap_woocommerce_hold_fulfilment` | `bool $hold` (false), `WC_Order $wc_order` | Ticketausgabe einer bezahlten Bestellung zurückhalten (z. B. Voucher Captain bei nicht belastbarer Geschenkkarte); die Reservierung bleibt, Tickets folgen nach erneutem Statuswechsel. |
| `wpcap_woocommerce_deferred_hold_minutes` | `int $minutes` (14 Tage), `WC_Order $wc_order` | Verlängerte Reservierung, solange die Ausgabe zurückgehalten oder bis „abgeschlossen“ verschoben wird. |
| `wpcap_woocommerce_issue_on_completed_gateways` | `string[] $gateways` (`cod`), `WC_Order $wc_order` | Zahlungsarten, bei denen Tickets erst im Status „abgeschlossen“ ausgegeben werden („in Bearbeitung“ heißt dort nicht bezahlt). Wird auch für die Admin-Warnung zu „Kauf auf Rechnung“-Zahlarten ausgewertet (A654; dann mit einer ungespeicherten Bestellung, die nur die Zahlart trägt). |

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
| `wpcap_scanner_stats_cache_ttl` | `int $seconds, int $occurrence_id` | Sekunden, die die terminweiten Werte von `GET /app/occurrences/{id}/stats` im Objekt-Cache (Gruppe `wpcap`) liegen (Standard 10, 0 = aus, höchstens 60; A440). |
| `wpcap_ticket_valid_for_occurrence` | `array $found, array $context, int $at` | Ticket an einen anderen Termin als den seiner Ticketart binden (Mehrtages-/Festivalpässe). Rückgabe `$found` mit `occurrence_id`, `event_id`, `event_name`, `starts_at`, `ends_at`, `timezone`, `occurrence_status`, `event_status`; `$context`: `event_ids`, `occurrence_id`, `area_id`. |
| `wpcap_admission_policy` | `array|null $policy, int $ticket_type_id` | Wirksame Einlassregel einer Ticketart (`null` = einmaliger Einlass); eigene `policy`-Werte (z. B. `pass`) über `wpcap_admission_evaluate` auswerten, alle Schlüssel von `normalize()` angeben. |
| `wpcap_admission_evaluate` | `string|null $result` (null), `array $policy, array $found, object|null $state, int $at` | Eigene Einlassentscheidung unter der Ticket-Zeilensperre: Sperrcode oder `''` (einlassen); `null` überlässt die Entscheidung den eingebauten Regeln. |
| `wpcap_admission_day_cutoff_hour` | `int $hour` (Einstellung `admission_day_cutoff_hour`), `array $context` (`occurrence_id`, `event_id`) | Stunde (0–12), bis zu der ein Einlass noch zum Vortag zählt (Tages- und Mehrtagestickets). |
| `wpcap_ticket_allowed_areas` | `int[] $area_ids, array $found` | Bereiche, in die ein Ticket eingelassen wird (leer = alle); z. B. Bereiche des gescannten Events bei Mehrtagespässen. |
| `wpcap_admission_area_capacity` | `int $capacity` (Einstellung am Bereich, 0 = unbegrenzt), `int $area_id, array $found` | Kapazität eines Einlassbereichs (Personen gleichzeitig drinnen) für einen Scan; bei Erreichen lehnt der Check-in mit `area_full` ab. |
| `wpcap_help_code_rate_limits` | `array $limits` (`user` 30, `client` 60, `site` 600, `window` 600 Sekunden) | Ratenbegrenzung der Hilfscode-Eingaben (`HelpCode::resolve()`) je WordPress-Benutzer bzw. gekoppeltem Scannergerät, je Client-IP und je Website im Zeitfenster; je Benutzer/Gerät zählt jede Eingabe, auch Treffer; die Grenzen je Client-IP und Website werden erst danach geprüft und nur von unbekannten Codes verbraucht (A281, A460: Venue-NAT). Darüber HTTP 429 (`wpcap_help_code_rate_limited`, `result: rate_limited`). Siehe [SECURITY.md](SECURITY.md#hilfscode-für-den-einlass). |
| `wpcap_app_pair_rate_limits` | `array $limits` (`failures` 10, `requests` 200, `window` 3600 Sekunden) | Ratenlimits von `POST /app/pair` je Client-IP (A460): fehlgeschlagene Kopplungen (unbekannter, benutzter, abgelaufener Code) und Versuche insgesamt; darüber HTTP 429 `wpcap_pair_rate`. Siehe [MOBILE-API.md](MOBILE-API.md). |
| `wpcap_scanner_pages` | `array[] $pages` (`id`, `title`, `url`) | Seiten mit dem Web-Scanner (Shortcode `[wpcap_scanner]`), die die Admin-Seite **Web-Scanner** verlinkt (A338); z. B. für einen Scanner in einem eigenen Template. Einträge ohne `url` werden ignoriert. |
| `wpcap_self_service_rotation_lock` | `string $reason` (`admission_open`, `offline_package` oder `''`), `int $ticket_id` | Sperre für neue Ticketcodes per Käufer-Selbstbedienung (Portal erneut senden/PDF, Übertragung, Teilnehmerwechsel) ab Einlassbeginn bzw. solange der Code in einem Offline-Paket steckt (A593); `''` erlaubt die Rotation. Personal-Aktionen sind nie gesperrt. Siehe [SECURITY.md](SECURITY.md#keine-code-rotation-durch-käufer-während-des-einlasses-a593). |
| `wpcap_offline_refresh_interval` | `int $seconds` (10) | Mindestabstand zweier Snapshot-Aktualisierungen eines Termins für den Offline-Scanner. |
| `wpcap_offline_live_tickets` | `object[] $live, int $occurrence_id` | Ticketzeilen des Offline-Pakets (`ticket_id`, `token_hash`, `status`, `ticket_type_id`, `attendee_name`, `occurrence_status`, `event_status`); Module ergänzen hier gültige Tickets anderer Termine oder entfernen Zeilen. |
| `wpcap_offline_package_types` | `array $types, object $occurrence` | Ticketart-Beschreibungen des Offline-Pakets (`id`, `name`, `areas`, `policy`, `multi`) für zusätzlich ausgelieferte Tickets. |
| `wpcap_member_pass_occurrence_quota` | `int $quota` (0 = kein eigenes Kontingent), `int $occurrence_id, int $ticket_type_id` | Höchstzahl der Mitgliedspass-Einlässe je Termin (A482); darüber `wpcap_pass_occurrence_full`. Unabhängig davon lässt ein voller Termin keine Mitglieder ein. Siehe [PASSES.md](PASSES.md#mitgliedspässe-kern-srcmembershipsphp). |
| `wpcap_member_pass_redeemed` (Action) | `int $pass_id, int $pass_use_id, int $occurrence_id, int $ticket_type_id` | Nach dem Commit einer Mitgliedspass-Einlösung (A482). |
| `wpcap_offline_scanner_app_base` | `string $url` (`home_url('/wpcap-offline-scanner/app/')`, bei einfachen Permalinks `…/index.php/wpcap-offline-scanner/app/`), `bool $pretty` | Adresse, unter der die Offline-Scanner-App ausgeliefert wird (A286). Muss auf dem Ursprung der REST-API liegen und auf `/app/` enden; die Route `Pwa::maybe_serve()` bedient nur die eigene Standardadresse. |

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
| `wpcap_report_data` | `array $report, array $filters, array|null $scope` | Öffentliche Add-on-API: eigene Kennzahlen im Bericht der Berichtsseite; nur neue Schlüssel werden übernommen. |
| `wpcap_reports_render` (Action) | `array $report, array $filters, array|null $scope` | Öffentliche Add-on-API: eigene Abschnitte am Ende der Berichtsseite; der Callback escaped selbst und prüft eigene Rechte. |
| `wpcap_export_formats` | `array $formats` (je ID `label` und `rows`) | Öffentliche Add-on-API: weitere Formate des Buchhaltungsexports, `rows( $from, $to )` liefert die Zeilen (erste Zeile = Kopf), Schaltfläche auf der Berichtsseite. |
| `wpcap_partner_client_ip` | `string $ip` (`ClientIp::get()`) | Client-Adresse für Partner-API-Schlüssel (vertrauenswürdige Proxys sind bereits aufgelöst). |
| `wpcap_refuse_mixed_seller_orders` | `bool $refuse` (true), `int $order_id` | Bestellungen ablehnen, die Rechnungen verschiedener Verkäufer bräuchten (Prüfung in `wpcap_order_creating`, Abbruchcode `mixed_sellers`). |
| `wpcap_reseller_statement_document` | `string $document` (`reseller_invoice`), `object $partner` | Beleg der Vergütung in der Reseller-Abrechnung: `reseller_invoice` (Reseller stellt Rechnung) oder `self_billing` (Gutschrift der Plattform). |
| `wpcap_report_channel` | `string $channel, string $provider` | Verkaufskanal einer Bestellung im Umsatzbericht (`pos`, `partner`, `woocommerce`, `online`) anhand des Zahlungsanbieters; unbekannte Werte zählen als `online`. |
| `wpcap_report_cache_ttl` | `int $seconds` (300, 0–900) | Lebensdauer gecachter Berichte (`Reports\Cache`, z. B. REST `/reports/events/{id}`); nur Sicherheitsnetz, Änderungen invalidieren sofort über Generationsschlüssel. 0 = kein Cache. Siehe [OPERATIONS.md](OPERATIONS.md#berichts-caches-und-invalidierung-a201). |
| `wpcap_report_cache_invalidate` (Action) | `int $event_id` (0 = ganze Site) | Eigene Writer (eigene Tabellen, Importe außerhalb von `$wpdb`) verwerfen gecachte Berichte eines Events bzw. der Site. |
| `wpcap_comp_payment_providers` | `string[] $providers` (`comp`, `pos_free`) | Zahlungsanbieter von Freikarten; deren Tickets zählen in Berichten nicht als Verkauf. |
| `wpcap_datev_accounts` | `array $accounts` (`revenue` Satz in Basispunkten => Konto, `revenue_default`, `bu_keys` Satz => BU-Schlüssel, `contra` Geldkonto, `contra_by_provider` Zahlungsanbieter => Konto) | Kontenzuordnung des Buchungsstapels im DATEV-Format (Standard SKR03: 8400/8300/8200 gegen 1360); siehe [OPERATIONS.md](OPERATIONS.md#buchungsstapel-datev-format). |

### Versionierte Verträge und Infrastruktur

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wpcap_loaded` (Action) | `string $api_version` (`PublicApi::VERSION`) | Öffentliche Add-on-API: Kern ist geladen (Ende von `Plugin::run()`, auf `plugins_loaded`); feuert einmal je Request. |
| `wpcap_activation_lock_wait` | `int $seconds` (20) | So lange wartet die Aktivierung auf die Upgrade-Sperre, wenn gerade ein anderes Schema-Upgrade derselben Site läuft; danach überlässt sie das Schema diesem Lauf (A512). |
| `wpcap_network_install_batch` | `int $sites` (25) | Höchstzahl der Sites je Lauf des Cron-Ereignisses `wpcap_network_install` nach netzwerkweiter Aktivierung (A287). |
| `wpcap_network_install_policy_batch` | `int $sites` (500) | Höchstzahl der Sites je Lauf von `wpcap_network_install`, deren Lösch-Zustimmung vor ihrer Einrichtung nach `wpcap_uninstall_policy` verlegt wird (Schutz vor der `uninstall.php` der alten Kopie). |
| `wpcap_network_install_budget` | `int $seconds` (15) | Zeitbudget je Lauf von `wpcap_network_install`; danach plant sich der Lauf neu ein (A287). |
| `wpcap_modules` | `string[] $classes` | Liste der Modulklassen (`src/Modules`). |
| `wp_pass_captain_review_provider_v1` | `object|null $provider, int $major` (1) | A667: Review-Provider registrieren (Vertrag `review` 1.0.0, [CONTRACTS.md](CONTRACTS.md#review-teilnahmevertrag-v1)): Objekt mit `receive( array $envelope )` und optional `contract_version()`. |
| `wpcap_review_no_show_grace_hours` | `int $hours` (6) | A669: Stunden nach Terminende, bis ein nicht eingelassenes Ticket als `no_show` ausgeschlossen wird. |
| `wp_pass_captain_workflow_provider_v1` | `object|null $provider, int $major` (1) | A673: Workflow-Provider registrieren (Vertrag `workflow` 1.0.0, [CONTRACTS.md](CONTRACTS.md#workflow-providervertrag-v1)): Objekt mit `receive( array $envelope )` und optional `contract_version()`. |
| `wpcap_workflow_event_data` | `array $data, string $type` | A673: Daten eines Workflow-Ereignisses, bevor die Feld-Allowlist greift – Felder außerhalb der Allowlist (und Codes/Personendaten) werden danach verworfen. |
| `wpcap_workflow_mail_handoff_types` | `array $types` (Typ => Bezeichnung) | A677: Optionale Mailtypen, die an den Workflow-Provider übergeben werden können (Standard: `queue_wpcap_reminder`); Ticket-, Zahlungs-, Erstattungs- und andere Pflichtmails werden wieder entfernt. |
| `wpcap_workflow_max_attempts` | `int $attempts` (8) | A673: Zustellversuche je Outbox-Ereignis an den Provider, danach `failed`. |
| `wpcap_workflow_retention_days` | `int $days` (30, 1–365) | A675: Aufbewahrung der Outbox-Ereignisse (auch ausstehender); danach gelöscht (Cron `wpcap_process_jobs`). |
| `wpcap_rest_prepare_event`, `wpcap_rest_prepare_order`, `wpcap_rest_prepare_developer_event` | `array $item`, dazu `object $row, WP_REST_Request $request` (Event der Liste `GET /events`) bzw. `WP_REST_Request $request` (`GET /orders/{id}`, `GET /developer/events/{id}`) | A331: Felder an ein REST-Element anhängen. **Nur additiv:** Felder des Vertrags (`tests/contracts/`) kann ein Filter weder entfernen, umbenennen noch ändern – übernommen werden nur neue Schlüssel. Keine Personendaten oder Codes ergänzen (die Routen sind teils öffentlich). |
| `wp_pass_captain_connectors_v1` | `ConnectorInterface[] $connectors` | Connectoren registrieren. |
| `wpcap_process_job` | `bool $handled, string $job_type, array $payload, int $job_id` | Hintergrundjobs aus `wpcap_jobs` verarbeiten (`true` = erledigt; `WP_Error`, `false` oder eine Exception = gescheitert, erneuter Versuch mit Backoff, nach 8 Läufen `dead_letter`). Ein gescheiterter Lauf sollte ein `WP_Error` liefern: Code und Text werden `last_error` und damit der Grund des Dead Letters (A325); bei `false` steht dort nur „ohne Fehlerangabe“ (plus ein `wp_mail`-Fehler des Laufs). Einträge ins Support-Protokoll und Mails während des Laufs werden der Bestellung/dem Ticket/Termin aus `order_id`/`ticket_id`/`occurrence_id` des Inhalts zugeordnet. Ausführung **mindestens einmal**: Handler müssen idempotent sein – stirbt der Worker nach der Wirkung, aber vor dem Abschluss, läuft der Job erneut (`$job_id` erkennt die Wiederholung). Siehe [OPERATIONS.md](OPERATIONS.md#hintergrundverarbeitung-wp-cron). |
| `wp_pass_captain_voucher_provider_v1` | `ProviderInterface|null $provider, int $version` | Gutschein-Anbieter. `commit()`-Fehler mit Status ≥ 500 oder Code `vcap_temporary_failure` werden über den Job `voucher_commit` wiederholt, alle anderen markieren die bezahlte Bestellung zur Prüfung. Das Angebot (`quote()`) kann `treatment` liefern: `payment` (Mehrzweckgutschein – USt bleibt, Gutschein erscheint als Zahlung auf der Rechnung, Gutschriften umfassen den Gutscheinanteil), `single` (Einzweckgutschein – USt bereits beim Verkauf abgeführt) oder `discount` (Standard – Preisnachlass). |
| `wp_pass_captain_ticket_service_v1` | `TicketService|null $service, int $version` | Dienst für die Rückrichtung: ein eingelöster Gutschein wird in Tickets umgewandelt (`issue_tickets`, `revoke_tickets`, `get`, `targets`, `options`, `events`); Ziel ist eine feste Ticketart oder eine Veranstaltung mit frei wählbarem Termin. Idempotent pro Schlüssel; Fehler tragen `retryable`. |
| `wp_pass_captain_checkin_service_v1` | `CheckinService|null $service, int $version` | Check-in-Dienst für einen fremden Scanner (gemeinsamer Scanner): `recognizes`, `preview`, `check_in`; prüft das Recht `wpcap_checkin`. |
| `wp_pass_captain_wallet_provider_v1` | `ProviderInterface|null $provider, string $provider_key, int $version` | Wallet-Anbieter (Bridge). |
| `wpcap_module_enabled` | `bool $enabled, string $short_name, string $class` | Ob ein Modul (`Sms`, `Wallet`, …) seine Hooks, Admin-Seiten und Admin-Aktionen registriert (Standard `true`; alternativ Konstante `WPCAP_DISABLED_MODULES` als Komma-Liste oder Einstellung „Funktionen abschalten“ = `disabled_modules`, A299). Tabellen und Einstellungen bleiben erhalten. |
| `wpcap_cron_time_budget` | `float $seconds` (20) | Zeitbudget der Cron-Läufe `wpcap_process_messages`, `wpcap_process_jobs`, `wpcap_webhooks_dispatch` und `wpcap_waitlist_process` (begrenzt auf `max_execution_time` − 15 s, mindestens 1 s); Rückstände laufen sofort weiter. |
| `wpcap_message_rate_per_minute` | `int $rate` (Einstellung „Geplante Mails je Minute“, 0 = unbegrenzt) | A623: höchstens so viele Mails aus der Warteschlange je Minute (Limit des Mail-Anbieters); der Rest folgt im nächsten Lauf. |
| `wpcap_mail_limit_error` | `bool $hit`, `string $reason` | A623: ob ein Versandfehler ein Anbieterlimit ist (Rate, Kontingent, SMTP 421/450/451/452, 4.7.x). Solche Mails werden in 15 Minuten erneut versucht, ohne einen der 5 Versuche zu verbrauchen (höchstens 24-mal). |
| `wpcap_message_batch_size` | `int $size` (500) | Nachrichten aus der Queue je Lauf von `wpcap_process_messages`. |
| `wpcap_waitlist_join_error` | `WP_Error|null $error` (null), `array $context` (`event_id`, `occurrence_id`, `ticket_type_id`, `email`, `quantity`) | Lehnt einen Wartelisteneintrag ab, der nie angeboten werden könnte (WP_Error zurückgeben). Das Modul Kontingente prüft so das Käuferlimit (A563). |
| `wpcap_waitlist_double_opt_in` | `bool $required` (true), `array $context` (`event_id`, `occurrence_id`, `ticket_type_id`, `email`, `quantity`) | A564: Ob eine Wartelisten-Eintragung erst über den Link der Bestätigungsmail wirksam wird (nur bei aktiver Einstellung `waitlist.double_opt_in`). `false` nur zurückgeben, wenn die Adresse anderweitig geprüft ist. Die Antwort des Endpunkts folgt dieser Entscheidung (`confirmation_required`). |
| `wpcap_waitlist_process_delay` | `int $seconds` (60) | Verzögerung, bevor freigewordene Plätze der Warteliste angeboten werden (mehrere Freigaben in einem Lauf). |
| `wpcap_log_min_level` | `string $level` (`debug` im Debug-Modus, sonst `info`) | A327: Niedrigste Stufe, die das Support-Protokoll speichert (`debug`, `info`, `notice`, `warning`, `error`, `critical`). |
| `wpcap_log_retention_days` | `int $days` (30) | A327: Aufbewahrung der Einträge des Support-Protokolls in Tagen (mindestens 1). |
| `wpcap_support_info` | `array $fields` (Schlüssel => `label`, `value`) | A329: Felder des Abschnitts „Pass Captain – Support“ im Website-Zustand (Support-Export). Nie Secrets, Ticketcodes oder Personendaten ergänzen. |
| `wpcap_health_thresholds` | `array $thresholds` | Schwellwerte der Website-Zustand-Prüfungen (A164): `cron_late_minutes` (15), `cron_critical_minutes` (60), `backlog_items` (200), `backlog_age_minutes` (30), `backlog_critical_minutes` (120), `dead_letters` (1), `dead_letters_critical` (25), `mail_window_hours` (24), `mail_failures` (1), `mail_failures_critical` (10), `sms_failures` (1), `sms_failures_critical` (10), `payment_webhook_hours` (48), `webhook_failures` (1), `webhook_failures_critical` (10), `webhook_exceptions_critical` (1), `payment_start_window_minutes` (60), `payment_start_failures` (1), `payment_start_failures_critical` (5). `0` schaltet eine Anzahl-Schwelle ab. Siehe [OPERATIONS.md](OPERATIONS.md#betriebsüberwachung-website-zustand). |
| `wpcap_health_rest_probe_ttl` | `int $seconds` | Cache-Dauer der Website-Zustand-Prüfung „öffentliche REST-Schnittstelle ohne Anmeldung“ (A375), Standard 6 Stunden, mindestens 60 Sekunden. |
| `wpcap_outbound_http_exceptions` | `string[] $origins` ([]), `string $context` | Zusätzliche Ausnahmen des Ausgangs-Guards (`OutboundHttp`): exakte Origins `schema://host:port`, für die HTTP bzw. eine private Adresse erlaubt ist (z. B. efsta EFR auf einem Kassenrechner im LAN, Kontext `fiscal`). Keine Platzhalter. Siehe [SECURITY.md](SECURITY.md#ausgehende-http-aufrufe-a202). |
| `wpcap_outbound_resolve` | `string[]|null $ips` (null = System-DNS), `string $host` | Ersetzt die DNS-Auflösung des Guards (Tests, Split-Horizon). Alle gelieferten Adressen müssen öffentlich sein; verbunden wird mit der ersten geprüften Adresse. |
| `wpcap_outbound_allow_unpinned` | `bool $allow` (false), `string $context` | Erlaubt Anfragen ohne cURL-Transport, bei denen die geprüfte Adresse nicht festgelegt werden kann (Schutz gegen DNS-Rebinding entfällt). Nicht empfohlen. |
| `wpcap_outbound_can_pin` | `bool $can_pin` (cURL mit `CURLOPT_RESOLVE` vorhanden) | Ob der Transport die geprüfte Adresse festlegen kann (Diagnose/Tests). |
| `wpcap_outbound_http_blocked` (Action) | `string $code, string $url` (geschwärzt), `string $context` | Der Guard hat eine ausgehende Anfrage abgelehnt (`wpcap_http_insecure`, `wpcap_http_host_blocked`, `wpcap_http_host_not_allowed`, `wpcap_http_dns`, `wpcap_http_url_invalid`, `wpcap_http_unpinned`); für Logs/Monitoring. |
| `wpcap_page_cache_urls` | `string[] $urls, int $event_id, int[] $post_ids` | A446: URLs, die `wpcap_purge_page_cache` für ein Event (0 = alle) mitgibt; z. B. Archiv- oder Landingpages ergänzen, die die Liste über ein Template oder Muster einbinden (der Index sieht nur Beitragsinhalte). |
| `wpcap_page_cache_adapters` | `string[] $adapters` (`wp_rocket`, `litespeed`, `w3tc`, `wp_super_cache`, `cloudflare`, `varnish`), `int $event_id` | A446: Mitgelieferte Purge-Adapter; jeder läuft nur, wenn sein Plugin aktiv ist. Einen entfernen, um diesen Cache selbst zu leeren. |
| `wpcap_page_cache_varnish` | `array $config` (`enabled` false, `endpoint` '', `method` `PURGE`, `headers`, `timeout` 3) | A446: Generischer Varnish-/HTTP-Purge, standardmäßig aus. Ohne `endpoint` geht je URL eine `PURGE`-Anfrage an die URL selbst (nur HTTPS/öffentlich, OutboundHttp-Guard); mit `endpoint` (z. B. `http://127.0.0.1:6081`) an diesen Ursprung mit dem `Host`-Header der Seite – der Ursprung wird dem Guard als ausdrückliche Ausnahme übergeben. |
| `wpcap_page_cache_varnish_request` | `array $args` (`method`, `headers`, `timeout`), `string $url, string $target` | A446: Eine einzelne Purge-Anfrage anpassen (z. B. `X-Purge-Token`, `BAN`); leeres Array überspringt sie. |
| `wpcap_db_tracks_in_transaction` | `bool $tracks` | Test-Naht: ob der Datenbankserver `@@in_transaction` meldet (Standard aus `SELECT VERSION()`); `false` erzwingt den Markierungs-Pfad von `Orders::in_transaction()`. |
| `wpcap_webhook_claim_wait_ms` | `int $milliseconds` (3000) | Wie lange ein Zahlungs-Webhook auf eine parallel laufende Verarbeitung desselben Ereignisses wartet, bevor er den Anbieter um Wiederholung bittet (`Payments\Webhooks`). |
| `wpcap_network_site_ids` | `int[] $site_ids` | Websites der Netzwerk-Übersicht (Multisite), z. B. um sehr große Netzwerke einzuschränken. |
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
| `wp_pass_captain_api_scopes_v1` | `array $scopes` (Scope → Label) | Zusätzliche Scopes der Entwickler-API-Keys (z. B. `orders.write`); erscheinen auf der Seite „Entwickler-API“ und in `POST /developer/keys`. Format `bereich.aktion` (Kleinbuchstaben, Ziffern, `_`); Kern-Scopes (`events.read`, `reports.read`) können nicht ersetzt werden. Prüfung in Add-on-Routen mit `PassCaptain\Api\Auth::request_can( $request, $scope[, $event_id] )`. |
| `wp_pass_captain_pos_payment_devices_v1` | `array $devices` (ID → `DeviceGatewayInterface` oder `label`, `start`, `poll`, `cancel`, optional `registers`), `array $context` (`version`, `session_id`, `location_id`, `register_id`) | Kartenterminals der Kasse registrieren (Vertrag `PassCaptain\BoxOffice\DeviceGatewayInterface`, Version 1). IDs `[a-z0-9_-]{1,40}`; ungültige Einträge werden verworfen, `registers` (Liste `standort_id|kassen_id`) beschränkt ein Gerät auf Register. Nur für Register, die das Kassenregister erlaubt. Die Zahlart „Karte am Terminal (automatisch)“ (`card_device`) erscheint nur, wenn mindestens ein Gerät registriert ist; ohne Gerät bleibt die Kasse unverändert. Exceptions im Filter: keine Geräte. Siehe [EXTENSIONS.md](EXTENSIONS.md#kartenterminals-an-der-kasse-box-office). |
| `wp_pass_captain_pos_device_timeout_v1` | `int $seconds` (180), `string $device_id` | Wie lange eine Terminalzahlung offen bleiben darf, bevor die Kasse sie am Gerät abbricht und den Verkauf freigibt (`timeout`); begrenzt auf 30–840 Sekunden (unter der 15-Minuten-Reservierung). |
| `wpcap_log_to_error_log` | `bool $enabled, string $level, string $event` | Ob Einträge von `PassCaptain\Log` zusätzlich ins PHP-Fehlerprotokoll gehen (Standard: nur mit `WP_DEBUG` und `WP_DEBUG_LOG`). |

### Veraltete Namen (Rückwärtskompatibilität)

Diese Hooks feuern weiterhin (über `Hooks::legacy_filter()` → `apply_filters_deprecated()`, WordPress meldet
die Nutzung mit `WP_DEBUG`), und zwar nach dem neuen Namen mit dessen Ergebnis und nur, wenn ein Callback darauf
registriert ist. Neue Add-ons nutzen den neuen Namen (siehe Tabelle „Umbenannte Hooks“ oben).

| Filter | Wert, Argumente | Zweck |
|---|---|---|
| `wp_pass_captain_process_job_v1` | `bool|WP_Error $handled, string $job_type, array $payload, int $job_id` | Veraltet, neuer Name `wpcap_process_job`. Hintergrundjobs aus `wpcap_jobs` verarbeiten (`true` = erledigt). `false` oder ein `WP_Error` = erneut versuchen (mit Backoff, nach 8 Versuchen `dead_letter`); die Meldung eines `WP_Error` erscheint als letzter Fehler auf der Systemstatus-Seite. Fremde Job-Typen den Wert unverändert zurückgeben. |
| `wp_pass_captain_email_template_v1` | `wpcap_email_template` |
| `wp_pass_captain_event_card_html` | `string $html, object $event, array $types` | Veraltet, neuer Name `wpcap_event_card_html` (versionierter Vertrag: `wp_pass_captain_event_card_html_v1`). |
| `wp_pass_captain_currencies` | `string[] $currencies` | Veraltet, neuer Name `wpcap_currencies` (versionierter Vertrag: `wp_pass_captain_currencies_v1`). |

## Cron-Hooks

WP-Cron-Ereignisse, die das Plugin selbst plant. Sie werden nicht per `do_action()` im Code ausgelöst,
sondern von WP-Cron; Module hängen sich mit `add_action()` an.

| Hook | Planung | Zweck / angehängte Callbacks |
|---|---|---|
| `wpcap_cleanup_reservations` | stündlich (`Plugin::schedule_events()`) | Abgelaufene Reservierungen (`Orders::expire_holds`), zurückgestellte Datenschutz-Löschungen (30), hängende Erstattungen (40), Rate-Limit-Zeilen (50), abgeschlossene Reservierungen/Jobs/Sitzplatz-Holds/Zahlungsereignisse (60, `Privacy\Retention::housekeeping()`, A444); Module: Sitzplatz-Reservierungen (20), automatische Hold-Freigabe und Wartelisten-Bereinigung (30), abgeschlossene Webhook-Zustellungen (60, `Webhooks\Dispatcher::purge_old()`, A437). |
| `wpcap_process_messages` | alle 5 Minuten (`wpcap_five_minutes`) | Nachrichten-Queue (`Communications::process_due`); Erinnerungen planen sich mit Priorität 1 ein, Bereinigung mit 5, SMS-Versand mit 20. |
| `wpcap_process_jobs` | alle 5 Minuten (`wpcap_five_minutes`) | Hintergrundjobs (`Jobs::process_due`, Tabelle `wpcap_jobs`); Webhook-Zustellung und Warteliste mit Priorität 20 (die Webhook-Bereinigung läuft seit A437 stündlich mit `wpcap_cleanup_reservations`). |
| `wpcap_workflow_dispatch` | einmalig 5 s nach einem neuen Outbox-Ereignis, nur wenn ein Workflow-Provider registriert ist (`Workflow\Outbox`) | Sofortige Zustellung an den Workflow-Provider. |
| `wpcap_webhooks_dispatch` | einmalig 5 s nach neuen Webhook-Ereignissen (`Webhooks\Dispatcher`) | Sofortige Zustellung ausgehender Webhooks. |
| `wpcap_reconcile_capacity` | alle 5 Minuten (`CapacityCounters::schedule()`) | Abgleich der Kapazitätszähler mit Bestellungen und Holds (siehe Kapazitätszähler). |
| `wpcap_run_upgrade` | einmalig, wenn im Frontend ein Schema-Update ansteht (`Installer::UPGRADE_HOOK`) | Verzögertes Datenbank-Upgrade (`Plugin::run_deferred_upgrade`). |
| `wpcap_privacy_retention` | täglich (`Privacy\Retention::schedule()`) | Löschlauf mit den Fristen aus `wpcap_privacy_retention_days`. |
| `wpcap_privacy_purge_wallet_registrations` | einmalig eine Stunde nach einer Datenschutz-Löschung, Argument: Ticket-IDs | Apple-Wallet-Geräteregistrierungen löschen, nachdem der Widerrufs-Push zugestellt wurde. |
| `wpcap_waitlist_process` | einmalig zum Fälligkeitszeitpunkt (`Waitlists::queue()`, Verzögerung `wpcap_waitlist_process_delay`) | Freigewordene Plätze der Warteliste anbieten. |
| `wpcap_page_cache_sales_window` | einmalig zu Verkaufsstart und eine Sekunde nach Verkaufsende jeder Ticketart (`PageCache::schedule_sales_windows()`, je Event die nächsten 50 Zeitpunkte), Argumente: Event-ID, Zeitpunkt | A446: Seiten des Events aus dem Seitencache entfernen (`wpcap_purge_page_cache`, Grund `sales_window`); plant danach die folgenden Zeitpunkte. |
| `wpcap_mollie_refresh_methods` | einmalig sofort, wenn die Eventliste eine nicht zwischengespeicherte Mollie-Methodenliste braucht (`MollieGateway::REFRESH_HOOK`), Argumente: Betrag (`"25.00"`), Währung | A282: Methodenliste im Hintergrund laden und eine Stunde cachen (`MollieGateway::refresh_methods()`); die Eventkarte ruft die Mollie-API nie selbst auf. |
| `wpcap_network_install` | einmalig nach netzwerkweiter Aktivierung auf der aktivierenden Site, dann alle 5 s bis alle Sites besucht sind (`NetworkInstall::HOOK`) | A287: Sites stapelweise einrichten (`NetworkInstall::run()`, Filter `wpcap_network_install_batch` (25 Sites) und `wpcap_network_install_budget` (15 s)). |

Laufen nach Ende des Zeitbudgets noch Einträge auf, plant `Jobs\TimeBudget::continue_later()` einen sofortigen
Folgelauf desselben Hooks (Action Scheduler, sonst WP-Cron mit Argument `continue`).
