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
| `order.refunded` | `wpcap_refund_completed` (Erstattung über das Plugin, auch Teilerstattung) | Bestellung + `refund` |
| `ticket.issued` | `wpcap_tickets_issued` – ein Ereignis je Ticket | Ticket |
| `ticket.checked_in` | `wpcap_ticket_checked_in` – jeder Einlass ein eigenes Ereignis (Mehrfacheinlass) | Ticket + `checked_in_at`, `source` |
| `ticket.revoked` | `wpcap_ticket_revoked` (Admin, Erstattung, Stornierung, Absage) | Ticket + `reason` |
| `ticket.exchanged` | `wpcap_ticket_exchanged` (Umtausch abgeschlossen: Admin, Portal, Kasse) | neues Ticket + `previous_ticket_id` (öffentliche ID des alten Tickets) + `exchange` (`from_ticket_type_id`, `to_ticket_type_id`, `channel`, `currency`, `difference_minor`, `fee_minor`, `refund_minor`, `due_minor`) |
| `occurrence.rescheduled` | `wpcap_occurrence_rescheduled` | Termin + `previous_starts_at` |
| `occurrence.cancelled` | `wpcap_occurrence_cancelled` | Termin + `issued_tickets` |

Hinweis: Eine Vollerstattung, die direkt im Dashboard eines Zahlungsanbieters ausgelöst wird
(`Orders::mark_refunded()`), erzeugt `ticket.revoked` für die betroffenen Tickets, aber derzeit kein
`order.refunded` (dafür fehlt im Kern ein Hook).

## Payload (API-Version `2026-11-01`)

```json
{
  "id": "evt_3f1c0d6e9a5b47c2b1d0e8f7a6c5b4d3",
  "type": "order.paid",
  "api_version": "2026-11-01",
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
- Beträge sind Ganzzahlen in der kleinsten Währungseinheit (`total_minor`), Zeitpunkte ISO 8601 in UTC
  (`2026-11-20T18:30:00Z`).
- Payloads enthalten nur IDs und Status. Keine Namen, Telefonnummern, Ticketcodes oder Antworten aus
  Teilnehmerfeldern.

### Objekte

**Bestellung** (`object: "order"`): `id` (öffentliche ID), `status`, `payment_status`, `currency`,
`total_minor`, `refunded_minor`, `payment_provider`, `ticket_count`.
PII (opt-in): `customer_email`.
Bei `order.refunded` zusätzlich `refund`: `{ "id": int, "amount_minor": int, "currency": "EUR",
"ticket_ids": [öffentliche Ticket-IDs] }`.

**Ticket** (`object: "ticket"`): `id` (öffentliche Ticket-ID, nicht der Ticketcode), `order_id`
(öffentliche Bestell-ID), `ticket_type_id`, `occurrence_id`, `event_id`, `status`
(`valid`, `checked_in`, `revoked`), `refunded` (bool).
PII (opt-in, nur wenn gesetzt): `attendee_email`.
`ticket.checked_in`: zusätzlich `checked_in_at`, `source` (`web`, `app`, …).
`ticket.revoked`: zusätzlich `reason` (bereinigter Schlüssel, z. B. `refund_12`, `occurrence_cancelled`).

**Termin** (`object: "occurrence"`): `id`, `event_id`, `starts_at`, `ends_at` (oder `null`), `timezone`,
`status`. `occurrence.rescheduled`: `previous_starts_at`; `occurrence.cancelled`: `issued_tickets`.

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
  Zustellungen bleiben erhalten und werden nach der Reaktivierung fortgesetzt.
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
  1–365 Tage; Cron `wpcap_process_jobs`). Ausstehende Zustellungen bleiben erhalten.
- Der WordPress-Datenlöscher „Pass Captain Webhook-Protokoll“ entfernt eine E-Mail-Adresse aus allen
  gespeicherten Payloads, auch aus noch ausstehenden.

Secrets werden im Klartext in der Datenbank gespeichert, weil sie zum Signieren benötigt werden.
