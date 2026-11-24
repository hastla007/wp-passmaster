# Ausgehende Webhooks

Pass Captain kann Ereignisse als signierte HTTP-POST-Anfragen an externe Systeme senden
(Buchhaltung, CRM, Zutrittskontrolle, Automatisierung). Verwaltung: **Pass Captain → Webhooks**
(Capability `manage_options`).

## Abonnements

| Feld | Beschreibung |
|---|---|
| URL | Nur `https://` zu öffentlichen Hosts (kein `localhost`, keine privaten IP-Adressen, keine Zugangsdaten in der URL). Zustellung über den Ausgangs-Guard `OutboundHttp` ([SECURITY.md](SECURITY.md#ausgehende-http-aufrufe-a202)): bei jeder Zustellung erneute DNS-Prüfung (alle Adressen öffentlich, auch IPv6), Verbindung auf die geprüfte Adresse festgelegt (DNS-Rebinding), ohne Weiterleitungen, Timeout 10 s, Antwort höchstens 64 KB; Fehlertexte im Protokoll ohne Geheimnisse. |
| Ereignisse | Auswahl aus der Liste unten. |
| Secret | Pro Abonnement erzeugt (`whsec_…`, 48 Hex-Zeichen), im Admin anzeigbar und erneuerbar („Secret erneuern“). |
| Personenbezogene Daten | Standard: aus. Nur wenn aktiviert, enthalten Payloads die unten als *PII (opt-in)* markierten Felder. |

## Ereignisse

| Typ | Auslöser (interner Hook) | `data.object` |
|---|---|---|
| `order.paid` | `wpcap_order_paid` | Bestellung |
| `order.cancelled` | `wpcap_order_cancelled` (manuell, abgelaufene Reservierung, fehlgeschlagene Zahlung) | Bestellung |
| `order.refunded` | `wpcap_refund_completed` (Erstattung über das Plugin, auch Teilerstattung) und `wpcap_order_refunded` (Vollerstattung außerhalb des eigenen Ablaufs: WooCommerce, Dashboard des Zahlungsanbieters) | Bestellung + `refund` |
| `refund.reversed` | `wpcap_refund_reversed` (verbuchte Erstattung beim Anbieter nachträglich gescheitert und storniert; Tickets bleiben widerrufen) | Bestellung + `refund` mit `reversed: true` |
| `ticket.issued` | `wpcap_tickets_issued` – ein Ereignis je Ticket | Ticket |
| `consent.granted` | `wpcap_consent_granted` (A612: Einwilligung erteilt – Checkout, Portal, Teilnehmerlink, Admin) | Einwilligung: `consent_id`, `ticket_public_id`, `order_public_id`, `field_id`, `field_key`, `text_version`, `source`, `at`, `state`; E-Mail nur mit `include_pii` |
| `consent.withdrawn` | `wpcap_consent_withdrawn` (A612: Widerruf oder Ende durch Inhaberwechsel) | wie `consent.granted`, `state` `withdrawn` |
| `ticket.checked_in` | `wpcap_ticket_checked_in` – jeder Einlass ein eigenes Ereignis (Mehrfacheinlass); derselbe Einlass doppelt gemeldet bleibt ein Ereignis | Ticket + `checked_in_at`, `source` |
| `ticket.checked_out` | `wpcap_ticket_checked_out` – jeder Auslass ein Ereignis | Ticket + `checked_out_at`, `source` |
| `attendance.confirmed` | erster gültiger Einlass eines Tickets oder zulässige Teilnahmebestätigung ohne Scan (`wpcap_confirm_attendance()`) – höchstens einmal je Ticket, je nachdem, was zuerst geschieht | Ticket + `confirmed_at`, `method` (`check_in`, bzw. `manual`, `list`, `session`, `online`), `source` (`api` bei der Bestätigung ohne Scan) |
| `ticket.transferred` | `wpcap_ticket_transferred` (Übertragung im Bestellportal, Inhaberwechsel bei der Zuweisung, „Inhaber ändern“ im Admin) | Ticket + `source` (`portal`, `assign`, `admin`), `holder_changes` |
| `ticket.revoked` | `wpcap_ticket_revoked` (Admin, Erstattung, Stornierung, Absage) | Ticket + `reason` |
| `ticket.exchanged` | `wpcap_ticket_exchanged` (Umtausch abgeschlossen: Admin, Portal, Kasse) | neues Ticket + `previous_ticket_id` (öffentliche ID des alten Tickets) + `exchange` (`from_ticket_type_id`, `to_ticket_type_id`, `channel`, `currency`, `difference_minor`, `fee_minor`, `refund_minor`, `due_minor`) |
| `occurrence.rescheduled` | `wpcap_occurrence_rescheduled` | Termin + `previous_starts_at` |
| `occurrence.changed` | `wpcap_occurrence_changed` (Beginn, Ende, Einlass, Ort, Land oder Status geändert) – einmal je Revision | Termin + `changed_fields` (Feldnamen), `revision` |
| `occurrence.cancelled` | `wpcap_occurrence_cancelled` | Termin + `issued_tickets` |
| `event.cancelled` | `wpcap_event_cancelled` (ganzes Event abgesagt; jeder Termin sendet zusätzlich `occurrence.cancelled`) | Event |

Eine Vollerstattung außerhalb des Plugins (`Orders::mark_refunded()`: WooCommerce, Dashboard des
Zahlungsanbieters) erzeugt `order.refunded` mit `refund.kind = "full"` und `ticket.revoked` für die
betroffenen Tickets. Erstattungen über das Plugin senden `order.refunded` je Erstattung; die zugehörige
Vollerstattungsmeldung wird dann nicht doppelt gesendet.

Die `id` eines Ereignisses ist stabil: Sie entsteht aus Typ und fachlicher Kennung (Bestellung, Ticket,
Erstattung, Einlasszeile, Revision), ein doppelt feuernder interner Hook erzeugt dieselbe `id`. Eine eigene
Ereignis-Outbox für Workflows gibt es im Kern nicht (PC-7, [CONTRACTS.md](CONTRACTS.md#kein-workflow-vertrag-im-kern-pc-7)).

Hinweis: Erstattungen, die direkt im Dashboard eines Zahlungsanbieters ausgelöst werden, sowie
Chargebacks (Stripe-Dispute verloren bzw. Geld abgezogen, PayPal `PAYMENT.CAPTURE.REVERSED`, Mollie-Chargeback)
werden über `Refunds::record_external()` als Erstattung verbucht (Gutschrift, Abrechnungsabzug). Eine
Vollerstattung (`Orders::mark_refunded()`) erzeugt `ticket.revoked` für die betroffenen Tickets und über den Hook
`wpcap_order_refunded` genau ein `order.refunded` je Bestellung (Ereignisschlüssel `order-full|{order_id}`); dort
ist `refund` = `{ "id": null, "source": "…", "full": true }`. Erstattungen über das Plugin selbst senden
`order.refunded` je Erstattung und werden hier nicht doppelt gemeldet (Test:
`tests/integration/modules/zz_integration.php`).

## Payload (API-Version `2026-11-01`)

```json
{
  "id": "evt_3f1c0d6e9a5b47c2b1d0e8f7a6c5b4d3",
  "type": "order.paid",
  "api_version": "2026-11-01",
  "schema_version": "1.0",
  "created": 1794470400,
  "data": {
    "object": {
      "object": "order",
      "id": "9b1e…32 Hex-Zeichen (öffentliche Bestell-ID)",
      "status": "complete",
      "payment_status": "paid",
      "currency": "EUR",
      "total_minor": 5000,
      "refunded_minor": 0,
      "payment_provider": "stripe",
      "ticket_count": 2
    }
  }
}
```

- `id` ist pro fachlichem Ereignis stabil (gleiches Ereignis = gleiche ID in allen Abonnements und bei
  erneuter Zustellung). Empfänger sollten anhand der ID deduplizieren.
- **Versionierung** (Vertrag `webhooks`, Tabelle in [CONTRACTS.md](CONTRACTS.md)): `api_version` (Datum) wechselt
  nur bei inkompatiblen Änderungen; `schema_version` (A674) zählt je Ereignistyp, eine Minor-Stufe fügt nur
  Felder hinzu. Stand: alle Typen `1.0`, `order.refunded` `1.1` (Felder `refund.kind`, `refund.source`,
  `refund.lines`, A675). Bestehende Felder werden nicht entfernt oder umbenannt; Empfänger ignorieren
  unbekannte Felder.
- Beträge sind Ganzzahlen in der kleinsten Währungseinheit (`total_minor`), Zeitpunkte ISO 8601 in UTC
  (`2026-11-20T18:30:00Z`).
- Payloads enthalten nur IDs und Status. Keine Namen, Telefonnummern, Ticketcodes oder Antworten aus
  Teilnehmerfeldern.

### Objekte

**Bestellung** (`object: "order"`): `id` (öffentliche ID), `status`, `payment_status`, `currency`,
`total_minor`, `refunded_minor`, `payment_provider`, `ticket_count`.
PII (opt-in): `customer_email`.
Bei `order.refunded` zusätzlich `refund`: `{ "id": int, "amount_minor": int, "currency": "EUR",
"ticket_ids": [öffentliche Ticket-IDs] }`; bei einer Vollerstattung außerhalb des Plugins
`{ "id": null, "source": string, "full": true }`.
"ticket_ids": [öffentliche Ticket-IDs], "kind": "tickets", "source": "admin", "lines": [{ "line_id": int,
"ticket_type_id": int, "quantity": int }] }` – die Erstattung je Bestellung, Bestellzeile und Ticket (A675).
`kind`: `tickets` (genau diese Tickets), `amount` (Betrag ohne Tickets), `full` (Vollerstattung außerhalb des
Plugins; dann `id: null`, `full: true`, `source` = Herkunft, `ticket_ids`/`lines` = alle Tickets und Zeilen,
kein `amount_minor`). `refund.reversed`: dasselbe `refund`-Objekt mit `reversed: true`.

**Ticket** (`object: "ticket"`): `id` (öffentliche Ticket-ID, nicht der Ticketcode), `order_id`
(öffentliche Bestell-ID), `ticket_type_id`, `occurrence_id`, `event_id`, `status`
(`valid`, `checked_in`, `revoked`), `refunded` (bool).
PII (opt-in, nur wenn gesetzt): `attendee_email`.
`ticket.checked_in`: zusätzlich `checked_in_at`, `source` (`web`, `app`, …).
`ticket.revoked`: zusätzlich `reason` (bereinigter Schlüssel, z. B. `refund_12`, `occurrence_cancelled`).
`ticket.checked_out`: `checked_out_at`, `source`. `attendance.confirmed`: `confirmed_at`, `method`, `source`.
`ticket.transferred`: `source`, `holder_changes` (die neue Inhaberadresse ist nur mit Opt-in als
`attendee_email` enthalten).

**Termin** (`object: "occurrence"`): `id`, `event_id`, `starts_at`, `ends_at` (oder `null`), `timezone`,
`status`. `occurrence.rescheduled`: `previous_starts_at`; `occurrence.cancelled`: `issued_tickets`;
`occurrence.changed`: `changed_fields` (z. B. `["starts_at","location"]`), `revision`.

**Event** (`object: "event"`, nur `event.cancelled`): `id`, `status`, `occurrence_ids`.

Der Filter `wpcap_webhook_payload_object` (`$object, $type`) kann `data.object` ergänzen; dabei bitte
keine personenbezogenen Daten hinzufügen.

## Header und Signatur

| Header | Inhalt |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `Pass-Captain-Webhooks/2026-11-01` |
| `X-WPCAP-Event` | Ereignistyp |
| `X-WPCAP-Event-Id` | Ereignis-ID (`evt_…`) |
| `X-WPCAP-Delivery` | Zustell-ID (je Abonnement) |
| `X-WPCAP-Signature` | `t={Unix-Zeit},v1={Hex-HMAC}` |

`v1 = HMAC-SHA256(secret, "{t}.{roher Body}")` – wie bei Stripe. Prüfung beim Empfänger:

```php
$body = file_get_contents( 'php://input' );
parse_str( str_replace( ',', '&', $_SERVER['HTTP_X_WPCAP_SIGNATURE'] ?? '' ), $sig );
$expected = hash_hmac( 'sha256', $sig['t'] . '.' . $body, $secret );
$valid = isset( $sig['t'], $sig['v1'] ) && abs( time() - (int) $sig['t'] ) <= 300 && hash_equals( $expected, $sig['v1'] );
```

Den Body vor der Prüfung nicht umformatieren (JSON nicht neu kodieren). Nachrichten älter als
5 Minuten verwerfen (Replay-Schutz). Bei der Secret-Rotation gilt sofort das neue Secret.

## Zustellung, Wiederholungen, Deaktivierung

- Jedes Ereignis wird je Abonnement in die Tabelle `{prefix}wpcap_webhook_deliveries` geschrieben
  (eindeutig je Abonnement und Ereignis-ID) und vom Cron `wpcap_process_jobs` (alle 5 Minuten)
  verarbeitet; zusätzlich wird direkt nach dem Einfügen ein einmaliges Cron-Ereignis
  `wpcap_webhooks_dispatch` geplant (best effort).
- Erfolg: jede Antwort mit HTTP 2xx. Alles andere (inkl. Timeout/Netzwerkfehler, protokolliert als
  Code 0) ist ein Fehlversuch.
- Wiederholung mit exponentiellem Backoff: 60 s · 2^(Versuch−1), höchstens 6 h. Nach 8 Versuchen
  (`wpcap_webhooks_max_attempts`) wird die Zustellung als `failed` markiert.
- Nach 20 Fehlversuchen in Folge (`wpcap_webhooks_disable_after`) wird das Abonnement deaktiviert
  (`disabled_reason = failures`) und die Website-Administration per E-Mail informiert. Ausstehende
  Zustellungen bleiben erhalten und werden nach der Reaktivierung fortgesetzt, sofern diese innerhalb
  der Aufbewahrungsfrist des Zustellprotokolls erfolgt (siehe Datenschutz).
- Das Zustellprotokoll im Admin zeigt Status, Versuche, HTTP-Code, Fehlertext und Zeitpunkte;
  „Erneut senden“ stellt eine Zustellung mit neuem Versuchsbudget wieder ein. Antwortinhalte werden
  nicht gespeichert.
- Die Reihenfolge der Zustellung ist nicht garantiert; Empfänger sollten Ereignisse idempotent und
  reihenfolgeunabhängig verarbeiten (z. B. den aktuellen Status über `status`-Felder übernehmen).

## Datenschutz

Standardmäßig werden keine personenbezogenen Daten übertragen. Das Opt-in „Personenbezogene Daten“
pro Abonnement fügt ausschließlich `customer_email` (Bestellungen) und `attendee_email` (Tickets) hinzu
und sollte nur mit Rechtsgrundlage und Auftragsverarbeitungsvertrag aktiviert werden.

- Sobald eine Zustellung abgeschlossen ist (`succeeded` oder endgültig `failed`), werden diese Felder aus
  dem gespeicherten Payload entfernt (`pii_purged = 1`).
- „Erneut senden“ baut die Opt-in-Felder aus den aktuellen Daten neu auf. Gelöschte oder anonymisierte
  Adressen werden nicht wieder übertragen; ist der Payload nicht mehr lesbar, wird die erneute Zustellung
  abgelehnt.
- Abgeschlossene Zustellungen werden nach 30 Tagen gelöscht (einstellbar auf der Webhook-Seite,
  1–365 Tage; Cron `wpcap_process_jobs`). Ausstehende Zustellungen aktiver Abonnements bleiben erhalten;
  ausstehende Zustellungen deaktivierter oder gelöschter Abonnements werden nach derselben Frist ohne
  personenbezogene Daten abgebrochen (`cancelled`, `last_error = subscription_inactive`) und danach gelöscht.
  1–365 Tage; stündlicher Cron `wpcap_cleanup_reservations`, in Stapeln zu 1.000 Zeilen über den Index
  `cleanup (status, updated_at)` mit 5 s Zeitbudget je Lauf, Filter `wpcap_webhook_purge_budget`; ein
  großer Rückstand wird über die folgenden Läufe abgebaut, A437). Ausstehende Zustellungen bleiben erhalten.
- Der WordPress-Datenlöscher „Pass Captain Webhook-Protokoll“ entfernt eine E-Mail-Adresse aus allen
  gespeicherten Payloads, auch aus noch ausstehenden.

Secrets werden im Klartext in der Datenbank gespeichert, weil sie zum Signieren benötigt werden.
