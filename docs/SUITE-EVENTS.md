# Ereignisse des Kerns für andere Captain-Plugins (Ist-Stand)

Stand: 25.11.2026, Plugin 1.7.0 (Erweiterungsvertrag `WPCAP_CONTRACT_VERSION` 1.5.0, siehe
[CONTRACTS.md](CONTRACTS.md)). Dieses Dokument beschreibt, was Pass Captain **heute** aussendet. Die gemeinsame
Spezifikation der Suite liegt in Workflow Captain (`wp-workflowmaster`, `docs/SPEC-suite-events.md`,
1.0.0-draft.13); Pass Captain liefert dafür nach Entscheidung PC-7 keinen eigenen Workflow-Vertrag, sondern die
`wpcap_*`-Actions, die Lesefassade `wpcap_get_*_state()` und die Aktionsfunktionen (`src/Api/Actions.php`).

Der Kern sendet seine Ereignisse immer und ohne Bedingung. Er prüft nicht, ob ein anderes Plugin oder ein
Add-on aktiv oder lizenziert ist; ob ein Empfänger ein Ereignis verarbeiten darf, entscheidet der Empfänger.

## Drei Wege, auf denen der Kern Ereignisse ausgibt

| Weg | Für wen | Zustellung | Versionierung | Beschreibung |
|---|---|---|---|---|
| PHP-Actions (`wpcap_*`, `wp_pass_captain_*_v1`) | Code auf derselben Website | synchron im selben Request, ohne Wiederholung; eine Ausnahme im Callback trifft den auslösenden Ablauf | `_v1` im Namen bei den versionierten Hooks; `wpcap_*` gelten als stabil, Änderungen stehen im `CHANGELOG.md` | [HOOKS.md](HOOKS.md) |
| Connector-Ereignisse (`PassCaptain\Integrations\ConnectorInterface`) | PHP-Adapter auf derselben Website, die der Betreiber freigeschaltet hat | über die Job-Queue, ein Job je Ereignis und Connector, Wiederholung mit Backoff, nach acht Versuchen `dead_letter`; stabiler Idempotenzschlüssel je Ereignis und Connector | Vertrag `connector` 1.2.0 (Payload `contract_version`), Payload-Format `version` = 1 | [EXTENSIONS.md](EXTENSIONS.md#connectoren) |
| Ausgehende Webhooks | externe Systeme über HTTPS | Tabelle `wpcap_webhook_deliveries`, Wiederholung mit Backoff bis acht Versuche, Signatur `X-WPCAP-Signature`; Reihenfolge nicht garantiert | `api_version` im Umschlag (`2026-11-01`) | [WEBHOOKS.md](WEBHOOKS.md) |

Einen Eingang für Aktionen anderer Plugins gibt es als fünf öffentliche PHP-Funktionen mit den Rechten des Kerns
(`wpcap_resend_ticket_mail()`, `wpcap_revoke_ticket()`, `wpcap_cancel_event()`, `wpcap_reschedule_occurrence()`,
`wpcap_confirm_attendance()`, [CONTRACTS.md](CONTRACTS.md#öffentliche-aktionsfunktionen-pc-7)) – ohne Warteschlange
und ohne Umschlag. Einen Workflow-Vertrag, eine Workflow-Outbox oder eine Übergabe von Mails gibt es im Kern nicht
(PC-7).

## PHP-Actions nach dem fachlichen Ereignis

Alle Actions übergeben interne Datenbank-IDs. Öffentliche IDs (`public_id`) und den Zustand von Bestellung, Ticket,
Erstattung, Termin und Event lädt der Empfänger seit Vertragsversion 1.3.0 über die Lesefassade
`wpcap_get_*_state()` / `PassCaptain\Api\ReadFacade` (ohne personenbezogene Daten und Codes, siehe
[EXTENSIONS.md](EXTENSIONS.md#lesefassade-für-zustände)); ein Ticket oder eine Bestellung mit Termin, Event, Inhaber
und Check-ins seit Vertragsversion 1.4.0 über `wpcap_get_ticket_details()` / `wpcap_get_order_ticket_details()`
([Lesedienst für Connectoren](EXTENSIONS.md#lesedienst-für-connectoren)). Seit welcher Plugin-Version jede Action feuert, ob
sie nach dem Commit feuert und welche Werte `$reason` bei `wpcap_order_cancelled` und `wpcap_ticket_revoked`
annehmen kann (auch Freitext), steht in [HOOKS.md](HOOKS.md#suite-katalog-seit-welcher-version-zeitpunkt-grund).

| Fachliches Ereignis | Action | Argumente | Zeitpunkt | Personenbezogene Daten oder Codes |
|---|---|---|---|---|
| Bestellung bezahlt | `wpcap_order_paid` | `int $order_id` | nach der Zahlungsbestätigung, bei kostenlosen Bestellungen nach der Ticketausgabe | nein |
| Bestellung storniert | `wpcap_order_cancelled` | `int $order_id, string $reason, string $previous_payment_status` (beim Ablauf einer Reservierung nur `$order_id`) | nach der Stornierung | nein |
| Bestellung vollständig erstattet | `wpcap_order_refunded` | `int $order_id, string $reason` | nach dem Widerruf der Tickets | nein |
| Erstattung ausgeführt (auch Teilerstattung) | `wpcap_refund_completed` | `int $refund_id, int $order_id` | nach der Verbuchung | nein |
| Erstattung abgelehnt | `wpcap_refund_rejected` | `int $refund_id, int $order_id` | bei der Freigabe | nein |
| Ticket erzeugt | `wpcap_ticket_created` | `int $ticket_id, object $order, object $line, int $index` | **innerhalb** der Ausgabe-Transaktion, je Ticket | **ja:** `$order` ist die Bestellzeile mit `customer_email` und Käufername |
| Tickets ausgegeben | `wpcap_tickets_issued` | `int $order_id, int $count` | nach dem Commit, vor der Ticketmail | nein |
| Ticket widerrufen | `wpcap_ticket_revoked` | `int $ticket_id, string $reason` | nach dem Widerruf | nein |
| Ticketcode neu erzeugt (Übertragung, Neuversand, Zuweisung) | `wpcap_ticket_code_rotated` | `int $ticket_id, string $code, string $reason` | nach der Neuerzeugung | **ja: `$code` ist der Ticketcode im Klartext.** Für Suite-Ereignisse ungeeignet |
| Einlass | `wpcap_ticket_checked_in` | `int $ticket_id, array $context` (`source`, `device_id`, `area_id`, `idempotency_key`) | nach erfolgreichem Einlass, bei Mehrfacheinlass je Einlass | nein |
| Auslass | `wpcap_ticket_checked_out` | `int $ticket_id, array $context` | nach dem Auslass | nein |
| Termin verschoben | `wpcap_occurrence_rescheduled` | `int $occurrence_id, string $old_starts_at, string $new_starts_at` (UTC) | Admin ändert die Startzeit eines Termins mit ausgegebenen Tickets, nach dem gelungenen UPDATE | nein |
| Termin abgesagt | `wpcap_occurrence_cancelled` | `int $occurrence_id, int $issued` | nach dem gelungenen Statuswechsel, einmal je Termin | nein |
| Event abgesagt | `wpcap_event_cancelled` | `int $event_id, array $context` (`occurrence_ids`, `run_id`) | einmal je Absage, nachdem alle Termine abgesagt und alle Tickets widerrufen sind (PC-9) | nein |
| Einwilligung widerrufen | `wpcap_consent_withdrawn` | `int $consent_id, object $consent` | nach dem Widerruf | **ja:** `$consent` ist die Einwilligungszeile einer Person |
| Beleg erzeugt | `wp_pass_captain_invoice_created_v1` | `int $id, string $type, array $context` | nach dem Commit, einmal je Beleg | nein (IDs und Belegnummer) |
| Kunden-Mail wird gesendet | `wpcap_mail_before_send` | `array $prepared, array $message` | unmittelbar vor `wp_mail()` | **ja:** Empfängeradresse, Mailtext, Anhänge (Ticket-PDF mit Code) |
| Bestellung wird angelegt | `wpcap_order_creating` | `int $order_id, array $selected, array $payload, array $internal` | **innerhalb** der Bestell-Transaktion | **ja:** `$payload` enthält die Eingaben des Käufers |

Es gibt kein eigenes Ereignis „Teilnahme bestätigt“ oder „Erlebnis abgeschlossen“. Der nächstliegende Nachweis
ist der Einlass (`wpcap_ticket_checked_in`); ein Ereignis zum Terminende sendet der Kern nicht.

## Connector-Ereignisse (Vertrag 1.2.0, Payload-Version 1)

Quelle: `src/Integrations/ConnectorEvents.php`. Payloads tragen nur IDs und Zustände, keine Namen,
E-Mail-Adressen, Ticket- oder Gutscheincodes; ein Grund ist immer ein Code (Freitext wird `other`).

| Ereignis | Ausgelöst durch | Felder in `data` |
|---|---|---|
| `order_paid` | `wpcap_order_paid` | `order_id`, `public_id`, `status`, `payment_status`, `payment_provider`, `currency`, `total_minor`, `refunded_minor`, `created_at` |
| `order_cancelled` | `wpcap_order_cancelled` | wie `order_paid`, zusätzlich `reason` (Code, `expired` beim Reservierungsablauf) |
| `order_refunded` | `wpcap_order_refunded` | wie `order_paid`, zusätzlich `reason` (Code) |
| `refund_completed` | `wpcap_refund_completed` | `refund_id`, `order_id`, `amount_minor`, `tax_minor`, `currency`, `ticket_ids` (interne IDs) |
| `tickets_issued` | `wpcap_tickets_issued` | wie `order_paid`, zusätzlich `issued_count`, `tickets[]` mit `ticket_id`, `public_id`, `ticket_type_id`, `status` |
| `voucher_redeemed` | `wpcap_tickets_issued` einer Gutschein-Umwandlung (Ticketdienst v1) | wie `order_paid`, zusätzlich `voucher_provider`, `voucher_id`, `voucher_purpose`, `voucher_amount_minor`, `tickets[]` |
| `ticket_revoked` | `wpcap_ticket_revoked` | `ticket_id`, `public_id`, `order_id`, `ticket_type_id`, `occurrence_id`, `status`, `reason` (Code) |
| `ticket_checked_in` | `wpcap_ticket_checked_in` | `ticket_id`, `public_id`, `order_id`, `ticket_type_id`, `occurrence_id`, `source`, `device_id`, `area_id` |
| `occurrence_rescheduled` | `wpcap_occurrence_rescheduled` | `occurrence_id`, `event_id`, `starts_at`, `timezone`, `status`, `old_starts_at`, `new_starts_at` |
| `occurrence_cancelled` | `wpcap_occurrence_cancelled` | `occurrence_id`, `event_id`, `starts_at`, `timezone`, `status`, `issued_tickets` |
| `ticket_checked_out` | `wpcap_ticket_checked_out` (seit 1.2.0, je Auslass) | `ticket_id`, `public_id`, `order_id`, `ticket_type_id`, `occurrence_id`, `source`, `device_id`, `area_id` |
| `event_cancelled` | `wpcap_event_cancelled` (seit 1.2.0; jeder Termin sendet zusätzlich `occurrence_cancelled`) | `event_id`, `status`, `occurrence_ids` |
| `attendance_confirmed` | erster `wpcap_ticket_checked_in` eines Tickets oder `wpcap_attendance_confirmed` (seit 1.2.0, einmal je Ticket) | `ticket_id`, `public_id`, `order_id`, `ticket_type_id`, `occurrence_id`, `method`, `source` |
| `ticket_transferred` | `wpcap_ticket_transferred` (seit 1.2.0) | `ticket_id`, `public_id`, `order_id`, `ticket_type_id`, `occurrence_id`, `source`, `holder_changes` |

Der mitgelieferte HTTP/JSON-Connector baut daraus eine frei zuordenbare Nachricht. Seine Feldliste kennzeichnet
`order.customer_email`, `attendee.name` und `attendee.email` als personenbezogen; sie werden nur gesendet, wenn
der Betreiber sie ausdrücklich zuordnet.

## Ausgehende Webhooks (API-Version `2026-11-01`)

Quelle: `src/Webhooks/Dispatcher.php`, `src/Modules/Webhooks.php`. Umschlag: `id` (`evt_…`, je fachlichem
Ereignis stabil), `type`, `api_version`, `created`, `data.object`.

| Typ | Ausgelöst durch | Felder in `data.object` | Personenbezogene Daten |
|---|---|---|---|
| `order.paid`, `order.cancelled` | `wpcap_order_paid`, `wpcap_order_cancelled` | `id` (öffentliche Bestell-ID), `status`, `payment_status`, `currency`, `total_minor`, `refunded_minor`, `payment_provider`, `ticket_count` | nur mit Opt-in des Abonnements: `customer_email` |
| `order.refunded` | `wpcap_refund_completed` (je Erstattung) und `wpcap_order_refunded` (Vollerstattung außerhalb des eigenen Ablaufs: WooCommerce, Anbieter-Dashboard) | Bestellung, zusätzlich `refund`: `id`, `amount_minor`, `currency`, `ticket_ids` (öffentliche IDs) bzw. `id: null`, `source`, `full: true` | wie oben |
| `ticket.issued` | `wpcap_tickets_issued`, ein Ereignis je Ticket | `id` (öffentliche Ticket-ID, nicht der Code), `order_id`, `ticket_type_id`, `occurrence_id`, `event_id`, `status`, `refunded` | nur mit Opt-in: `attendee_email` |
| `ticket.checked_in` | `wpcap_ticket_checked_in` | Ticket, zusätzlich `checked_in_at`, `source` | wie oben |
| `ticket.revoked` | `wpcap_ticket_revoked` | Ticket, zusätzlich `reason` | wie oben |
| `occurrence.rescheduled` | `wpcap_occurrence_rescheduled` | `id`, `event_id`, `starts_at`, `ends_at`, `timezone`, `status`, `previous_starts_at` | nein |
| `occurrence.cancelled` | `wpcap_occurrence_cancelled` | wie oben, zusätzlich `issued_tickets` | nein |
| `occurrence.changed` | `wpcap_occurrence_changed` (A674, je Revision) | Termin, zusätzlich `changed_fields`, `revision` | nein |
| `event.cancelled` | `wpcap_event_cancelled` (A674; jeder Termin sendet zusätzlich `occurrence.cancelled`) | `id`, `status`, `occurrence_ids` | nein |
| `ticket.checked_out` | `wpcap_ticket_checked_out` (A674, je Auslass) | Ticket, zusätzlich `checked_out_at`, `source` | wie `ticket.issued` |
| `attendance.confirmed` | `wpcap_ticket_checked_in` beim ersten Einlass (A674) oder `wpcap_attendance_confirmed` (PASS-WF-02), zusammen einmal je Ticket | Ticket, zusätzlich `confirmed_at`, `method`, `source` | wie `ticket.issued` |
| `ticket.transferred` | `wpcap_ticket_transferred` (A674) | Ticket, zusätzlich `source`, `holder_changes` | wie `ticket.issued` |
| `ticket.exchanged` | `wpcap_ticket_exchanged` | Ticket, zusätzlich Umtauschbeträge (`currency`, `difference_minor`, `fee_minor`, `refund_minor`, `due_minor`) | wie `ticket.issued` |
| `refund.reversed` | `wpcap_refund_reversed` (A674/A675: gebuchte Erstattung beim Anbieter gescheitert und storniert) | Bestellung, zusätzlich `refund` mit `reversed: true` | wie `order.paid` |
| `consent.granted` | `wpcap_consent_granted` (A612) | Einwilligung ohne Personendaten, E-Mail nur mit `include_pii` | wie `order.paid` |
| `consent.withdrawn` | `wpcap_consent_withdrawn` (A612: Widerruf, Inhaberwechsel) | wie `consent.granted` | wie `order.paid` |

Kein Webhook enthält einen Ticketcode, einen Namen oder Antworten aus Teilnehmerfeldern. Auslass,
Event-Absage, Teilnahmebestätigung und Ticketübertragung gibt es seit Connector-Vertrag 1.2.0 als Webhook und als
Connector-Ereignis; für die Code-Rotation (`wpcap_ticket_code_rotated`) gibt es weder ein Connector-Ereignis noch einen Webhook.

## Was vor einer Anbindung festzulegen ist

Diese Punkte gehören in **eine** gemeinsame Spezifikation der Suite. Ihr Ort ist eine offene Entscheidung
(`TODO.md`, E-02). Bis sie vorliegt, baut dieses Repository keinen Workflow-, Review- oder Form-Adapter.

1. **Ereignisnamen:** eine Schreibweise für alle Plugins (heute drei: `wpcap_order_paid`, `order_paid`, `order.paid`).
2. **Umschlagfelder:** Ereignis-ID, Zeitpunkt, Schema-Version, Quelle, Mandant/Website, Idempotenzschlüssel.
3. **Transport:** PHP-Action im selben Request, Job-Queue des Kerns oder HTTP; damit auch, was bei einer Ausnahme im Empfänger geschieht.
4. **Versionierung:** wie ein unverträglich geändertes Ereignis heißt und wie lange das alte weiterläuft.
5. **Wiederholungen:** wer wiederholt (Sender oder Empfänger), wie oft, und wer dedupliziert.
6. **IDs:** interne Datenbank-IDs oder öffentliche IDs; heute nutzen PHP-Actions und Connector-Ereignisse interne, Webhooks öffentliche.
7. **Personenbezogene Daten:** ob ein Ereignis sie je enthalten darf oder ob der Empfänger sie über einen eigenen, berechtigten Abruf lädt.
8. **Übergabe der Zuständigkeit:** welche Abläufe (zum Beispiel Erinnerungen) ein anderes Plugin übernehmen darf, wie die Übergabe protokolliert und zurückgenommen wird.
9. **Lizenzfrage:** welches Add-on welches Recht prüft. Der Kern prüft keines (`docs/FREE-PREMIUM.md`).
