# Veranstalter, Veranstalter-API und Veranstalter-Abrechnungen

> **Bezeichnung:** Diese Organisationen heißen **Veranstalter**; „Partner“ meint in den Captain-Plugins nur noch die Affiliate-Partner von Affiliate Captain. Technische Bezeichner bleiben aus Kompatibilitätsgründen unverändert: REST-Pfade `/wp-json/pass-captain/v1/partner/…`, Header `X-WPCAP-Partner-API`, Fehlercodes `wpcap_partner_*`, Tabellen, Optionen und der Rollen-Slug `wpcap_partner`. Der Rollenname „Pass Captain Partner“ wird beim Update automatisch in „Pass Captain Veranstalter“ umbenannt (ein selbst vergebener Name bleibt).

Veranstalter sind Organisationen (Veranstaltende oder Wiederverkäufer), denen Events zugeordnet werden.
Für sie gibt es:

- **Stammdaten und Provisionsmodell**: Pass Captain → Veranstalter (Capability `manage_options`),
- **Veranstalter-API**: lesender REST-Zugriff mit eigenen, eingeschränkten API-Keys,
- **Verkauf über die API** (Veranstalter und Wiederverkäufer): Reservierungen und Verkäufe mit
  Zahlung beim Verkäufer, Preisliste, Kontingente, Storno – siehe [Verkauf über die API](#verkauf-über-die-api),
- **Veranstalter-Abrechnungen**: monatliche Abrechnungen mit Belegnummer (Pass Captain → Veranstalter-Abrechnungen).

Code: `src/Modules/Partners.php`, `src/Partners/`.

## Veranstalter anlegen

| Feld | Bedeutung |
|---|---|
| Name, Ansprechperson, Kontakt-E-Mail, Telefon | Kontaktdaten |
| Rechnungsanschrift, USt-IdNr., Auszahlung | erscheinen auf der Abrechnung (beim Abschließen als Schnappschuss gespeichert) |
| Währung | Nur Bestellungen in dieser Währung werden abgerechnet. |
| Provision in % | Prozentsatz vom **Netto**-Ticketumsatz (nach Rabatt, ohne USt). |
| Feste Gebühr pro Ticket | Nettobetrag je verkauftem Ticket. |
| USt-Satz auf die Plattformgebühr | z. B. 19 % oder 0 % (Reverse Charge) – wird auf Provision + Gebühren erhoben. |
| Bei Erstattungen | `reverse_all`: Provision und Ticketgebühr werden storniert · `keep_fixed_fee`: nur die Provision wird storniert · `keep_all`: beide bleiben bestehen. |
| Abrechnung ab | Bestellungen vor diesem Datum werden nie abgerechnet (verhindert, dass Altdaten in die erste Abrechnung fallen). Standard: Monatserster beim Anlegen. |
| Status | `inaktiv` sperrt alle API-Keys des Veranstalters (403). |
| Events | Ein Event gehört höchstens einem Veranstalter. |
| Rolle | `organizer` (Veranstalter, verkauft Ticketarten der eigenen Events) oder `reseller` (Wiederverkäufer, verkauft die Ticketarten seiner Preisliste – auch Events anderer Veranstalter). |
| Name des Verkaufskanals | erscheint als `channel` an jeder API-Bestellung (z. B. „Reseller-Portal“). |
| Reservierungsdauer | Haltezeit von API-Reservierungen in Minuten (1–60, Standard 15). |
| Stornofenster | Stunden nach der Bestätigung, in denen bezahlte API-Bestellungen über die API storniert werden dürfen (0 = nie). |
| Ohne Käufer-E-Mail | erlaubt Verkäufe mit `anonymous_buyer` (Tickets nur an den Verkäufer). |
| Wiederverkäufer-Provision / -Gebühr | Vergütung des Verkäufers in der Wiederverkäufer-Abrechnung (% vom Netto, Nettobetrag je Ticket; USt-Satz wie „USt-Satz auf die Plattformgebühr“). |
| WordPress-Benutzer | IDs, Logins oder E-Mails. Diese Benutzer (typisch Rolle „Pass Captain Veranstalter“) sehen unter *Veranstalter-Abrechnungen* nur die **abgeschlossenen** Abrechnungen ihres Veranstalters, ausschließlich lesend (Ansicht/Druck, CSV). |

Alle Änderungen werden im Audit-Log protokolliert (`partner_created`, `partner_updated`,
`partner_events_set`, `partner_users_set`).

## API-Keys

Keys werden auf der Detailseite eines Veranstalters erzeugt.

- Format: `wpcap_pk_<12 Hex-Zeichen Präfix>_<64 Hex-Zeichen Secret>`.
- Der vollständige Key wird **nur einmal** nach dem Erzeugen angezeigt. Gespeichert werden nur das
  Präfix und der SHA-256-Hash des Secrets (256 Bit Zufall).
- **Scopes** (mindestens einer):

| Scope | Zugriff |
|---|---|
| `events:read` | Events, Termine, Ticketarten, verkaufte Menge |
| `orders:read` | Bestellungen mit den Positionen der Veranstalter-Events – ohne E-Mail |
| `tickets:read` | Tickets – ohne E-Mail |
| `checkins:read` | Check-ins (ohne Mitarbeiter-IDs) |
| `reports:read` | Umsatz-/USt-/Auslastungsbericht (wie Modul „Berichte“) |
| `settlements:read` | abgeschlossene und ausgezahlte Abrechnungen |
| `attendees:read` | **Personenbezogene Daten**: ergänzt `customer_email` (Bestellungen) und `attendee_email` (Tickets) sowie `buyer_email` bei eigenen API-Bestellungen. Nur vergeben, wenn eine Rechtsgrundlage und ggf. ein AV-Vertrag besteht. Wiederverkäufer lesen über `/partner/orders`, `/partner/tickets` und `/partner/checkins` nur ihre eigenen Verkäufe (`wpcap_partner_orders`), nie Online-, Kassen- oder fremde Partnerkäufe der verknüpften Events. |
| `orders:write` | **schreibend**: Katalog lesen, Reservierungen/Verkäufe anlegen und bestätigen, eigene API-Bestellungen lesen |
| `orders:cancel` | **schreibend**: eigene API-Bestellungen stornieren (Reservierung freigeben, bezahlte im Stornofenster erstatten) |
| `tickets:issue` | **schreibend**: Ticket-Codes eigener API-Bestellungen erhalten und erneut abrufen – nur zusammen mit `orders:write` |
- **Event-Einschränkung** (optional): nur Events des Veranstalters. Ohne Auswahl gilt der Key für alle
  aktuellen und künftigen Events des Veranstalters. Wird ein Event vom Veranstalter gelöst, verliert jeder
  Key sofort den Zugriff. Event-beschränkte Keys können keine Abrechnungen abrufen (diese umfassen
  alle Events des Veranstalters).
- **Ticketart-Einschränkung** (optional, nur Verkauf): Ticketart-IDs aus dem Sortiment. Wiederverkäufer
  können Keys außerdem auf Events ihrer Preisliste beschränken.
- **IP-Allowlist** (optional): einzelne Adressen oder CIDR-Bereiche, IPv4 und IPv6, z. B.
  `203.0.113.10`, `198.51.100.0/24`, `2001:db8::/32`. Maßgeblich ist `REMOTE_ADDR`; hinter einem
  Reverse Proxy die echte Client-Adresse über den Filter `wpcap_partner_client_ip` liefern.
- **Ablaufdatum** (optional), **Zuletzt genutzt** (minutengenau), **Widerrufen** (sofort wirksam).
- **Ratenlimit**: Anfragen pro Minute und Key (Standard 120, einstellbar unter Veranstalter →
  Einstellungen bzw. je Key). Überschreitung: `429`. Schreibende Aufrufe (Anlegen, Bestätigen, Stornieren,
  Code-Abruf) haben zusätzlich ein eigenes Limit (Standard 30/Minute, ebenfalls global oder je Key).
- **Sperre bei Fehlversuchen**: Nach 20 fehlgeschlagenen Anmeldungen (fehlender, ungültiger,
  abgelaufener oder widerrufener Key) innerhalb von 10 Minuten wird die Client-Adresse für
  10 Minuten gesperrt (`429`, auch für gültige Keys).

Audit-Log: `partner_key_created`, `partner_key_revoked` und `partner_key_auth_failed` mit
`reason` = `missing`, `malformed`, `invalid`, `revoked`, `expired`, `partner_inactive`,
`ip_not_allowed`, `scope_missing`, `rate_limited` (höchstens einmal pro Minute und Key),
`event_not_allowed`, `event_restricted_settlements`, `write_rate_limited`. Jeder schreibende Aufruf
(auch abgelehnte) erzeugt zusätzlich `partner_api_write` (siehe unten). Protokolliert werden Key-ID, Veranstalter, Route und
die gekürzte Client-Adresse (IPv4 /24, IPv6 /48) – nie das Secret.

## Authentifizierung

```http
GET /wp-json/pass-captain/v1/partner/me
Authorization: Bearer wpcap_pk_3f9a0c1b2d4e_8c1f…
```

Hinweis: Manche Apache/CGI-Setups entfernen den `Authorization`-Header; dann z. B.
`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in der `.htaccess` ergänzen.

Fehler (WordPress-REST-Format `{code, message, data: {status}}`):

| Status | Code | Ursache |
|---|---|---|
| 401 | `wpcap_partner_unauthorized` | Key fehlt, ist ungültig, abgelaufen oder widerrufen |
| 403 | `wpcap_partner_forbidden` | Scope fehlt, IP nicht erlaubt, Veranstalter inaktiv |
| 403 | `wpcap_partner_event_forbidden` | `event_id` außerhalb der erlaubten Events |
| 403 | `wpcap_partner_settlements_restricted` | Abrechnungen mit event-beschränktem Key |
| 429 | `wpcap_partner_rate_limited` / `wpcap_partner_locked` | Ratenlimit / Sperre nach Fehlversuchen |

Alle Antworten tragen `Cache-Control: no-store`. Beträge sind ganzzahlige Minor Units (Cent),
Zeitstempel ISO 8601 in UTC.

## Endpunkte

Alle unter `/wp-json/pass-captain/v1`, lesend (`GET`). Schreibende Endpunkte: siehe
[Verkauf über die API](#verkauf-über-die-api).

| Route | Scope | Parameter |
|---|---|---|
| `/partner/me` | – (jeder gültige Key) | – (enthält Rolle, Verkaufskanal und Ticketart-Einschränkung des Keys) |
| `/partner/events` | `events:read` | `event_id` |
| `/partner/events/{event_id}` | `events:read` | – |
| `/partner/orders` | `orders:read` | `event_id`, `payment_status`, `since` (bezieht sich auf `updated_at`), `page`, `per_page` (max. 100) |
| `/partner/tickets` | `tickets:read` | `event_id`, `occurrence_id`, `status`, `since` (`issued_at`), `page`, `per_page` |
| `/partner/checkins` | `checkins:read` | `event_id`, `occurrence_id`, `since`, `page`, `per_page` |
| `/partner/reports` | `reports:read` | `from`, `to` (`YYYY-MM-DD`, Zeitzone der Website), `event_id` |
| `/partner/settlements` | `settlements:read` | – |
| `/partner/settlements/{id}` | `settlements:read` | – (inkl. Positionen) |

Listen enthalten `page`, `per_page` und `total`.

Beispiel `/partner/orders`:

```json
{
  "orders": [
    {
      "id": "6f1c…",
      "status": "complete",
      "payment_status": "paid",
      "currency": "EUR",
      "payment_provider": "stripe",
      "created_at": "2026-10-15T12:00:00Z",
      "updated_at": "2026-10-15T12:01:10Z",
      "lines": [
        { "event_id": 12, "occurrence_id": 30, "ticket_type_id": 81, "quantity": 2, "refunded_quantity": 0,
          "unit_price_minor": 1190, "line_total_minor": 2380, "tax_rate_bp": 1900, "tax_minor": 380 }
      ]
    }
  ],
  "page": 1, "per_page": 50, "total": 1
}
```

Positionen anderer Veranstalter bzw. nicht freigegebener Events werden aus Bestellungen herausgefiltert.
`customer_email` erscheint nur mit `attendees:read`. Gelöschte oder anonymisierte Adressen (Platzhalter `…@invalid.example` nach einer Datenschutzlöschung, Kassenverkäufe ohne E-Mail) und leere Teilnehmeradressen werden als `null` ausgegeben.

## Verkauf über die API

Veranstalter (Rolle `organizer`) und Wiederverkäufer (Rolle `reseller`) können mit den schreibenden
Scopes selbst verkaufen. Der Verkäufer **kassiert selbst** (Zahlungsart `partner`); Pass Captain hält
die Plätze, stellt die Tickets aus und führt Kapazität, Kontingente, Webhooks und Abrechnungen.

### Sortiment und Preise

- **Veranstalter** dürfen alle Ticketarten ihrer eigenen Events verkaufen.
- **Wiederverkäufer** dürfen nur die Ticketarten ihrer **Preisliste** verkaufen (Detailseite → „Preisliste
  und Sortiment“), auch Events anderer Veranstalter.
- Preis je Ticketart: leer = Standardpreis der Ticketart (inkl. Preisstufen über
  `wpcap_ticket_unit_price`), sonst ein fester Preis **brutto** oder **netto** (zzgl. USt der Ticketart,
  kaufmännisch gerundet). Der Client kann keine Preise übergeben.
- Ein Key kann das Sortiment weiter auf Events bzw. Ticketarten einschränken.

`GET /partner/catalog` (`orders:write`) liefert die verkaufbaren Ticketarten mit `price_minor`,
`price_source` (`partner`|`core`), `available` (Restmenge inkl. Partner-Kontingent; `null` = unbegrenzt)
und `on_sale`.

### Kontingente

Je Termin (optional je Ticketart) kann eine **Höchstmenge** für den Verkäufer festgelegt werden
(„Kontingente“ auf der Detailseite). Gezählt werden bezahlte Tickets (abzüglich Erstattungen) und
aktive Reservierungen des Verkäufers. Die Prüfung läuft in der Bestell-Transaktion
(`wpcap_order_creating`) mit Zeilensperre auf das Kontingent – parallele Anfragen können es nie
überschreiten. Optional wird aus einem **Hold des Moduls „Kontingente“** verkauft (Hold-ID; die Quellen
des Holds leer lassen oder `partner` eintragen): der Verkauf verbraucht dann den Hold statt freier
Plätze. Eine Bestellung kann nur aus einem Hold verkaufen.

### Ablauf

**A) Reservieren, dann bestätigen** (z. B. Warenkorb beim Wiederverkäufer):

```bash
curl -X POST https://example.com/wp-json/pass-captain/v1/partner/orders \
  -H "Authorization: Bearer wpcap_pk_3f9a0c1b2d4e_8c1f…" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: shop-4711-a" \
  -d '{
        "mode": "reserve",
        "items": [ { "ticket_type_id": 81, "quantity": 2 } ],
        "buyer": { "email": "kundin@example.org" },
        "attendees": [ { "ticket_type_id": 81, "name": "Ada Lovelace" } ],
        "external_reference": "SHOP-4711"
      }'
```

Antwort `201`:

```json
{
  "order": {
    "id": "6f1c0e…", "mode": "reserve", "status": "pending_payment", "payment_status": "pending",
    "currency": "EUR", "total_minor": 4000, "tax_minor": 639, "refunded_minor": 0,
    "external_reference": "SHOP-4711", "channel": "Reseller-Portal", "anonymous_buyer": false,
    "hold_expires_at": "2026-11-12T14:35:00Z", "created_at": "2026-11-12T14:20:00Z",
    "confirmed_at": null, "cancelled_at": null,
    "lines": [ { "event_id": 12, "occurrence_id": 30, "ticket_type_id": 81, "ticket_type": "Parkett",
                 "quantity": 2, "refunded_quantity": 0, "unit_price_minor": 2000, "line_total_minor": 4000,
                 "tax_rate_bp": 1900, "tax_minor": 639 } ]
  },
  "tickets": [],
  "reused": false
}
```

Die Plätze sind bis `hold_expires_at` reserviert (Reservierungsdauer des Veranstalters, höchstens
60 Minuten). Nach erfolgreicher Zahlung beim Verkäufer:

```bash
curl -X POST https://example.com/wp-json/pass-captain/v1/partner/orders/6f1c0e…/confirm \
  -H "Authorization: Bearer wpcap_pk_…" -H "Content-Type: application/json" \
  -d '{ "payment_reference": "PSP-998877" }'
```

Antwort `200`: Bestellung mit `payment_status: "paid"` und die Tickets:

```json
{
  "order": { "id": "6f1c0e…", "payment_status": "paid", "status": "complete", "…": "…" },
  "tickets": [
    { "id": "a1b2…", "ticket_type_id": 81, "ticket_type": "Parkett", "event_id": 12, "occurrence_id": 30,
      "status": "valid", "refunded": false,
      "code": "9f86d081884c7d65…", "url": "https://example.com/?wpcap_ticket=9f86d081884c7d65…" }
  ]
}
```

`code`/`url` nur mit `tickets:issue`. Eine abgelaufene Reservierung kann nicht bestätigt werden
(`409 wpcap_partner_hold_expired`) – dann eine neue Bestellung anlegen. Bestätigen ist idempotent.

**B) Direktverkauf** (`"mode": "sell"`): Anlegen und Bestätigen in einem Aufruf; optional
`payment_reference`. Antwort `201` mit Tickets.

**Käufer ohne E-Mail** (`"anonymous_buyer": true`, kein `buyer`): nur wenn beim Veranstalter „Ohne
Käufer-E-Mail“ aktiv ist und der Key `tickets:issue` hat. Die Bestellung erhält eine
Platzhalteradresse `partner+…@invalid.example`; es werden keine Mails versendet, die Tickets gehen nur an
den Verkäufer. Mit Käufer-E-Mail erhält die Käuferin zusätzlich die normale Ticket-Mail.

**Rechnungsdaten (`billing`, optional):** `{ "first_name", "last_name", "street", "postcode", "city", "country" (ISO-2), optional "company", "vat_id", "po_number" }`. Über der Schwelle „Name und Anschrift ab Bestellbetrag“ (Einstellungen, Standard 250 €; DE Kleinbetragsrechnung) ist das Objekt Pflicht, sonst `422 wpcap_partner_billing_required`.

### Idempotenz

`POST /partner/orders` verlangt den Header **`Idempotency-Key`** (8–100 Zeichen `A–Z a–z 0–9 . _ : -`).
Schlüssel gelten je Veranstalter. Eine Wiederholung mit demselben Schlüssel und identischem Body liefert
die bestehende Bestellung (`200`, `"reused": true`, beim Direktverkauf inkl. Tickets); derselbe Schlüssel
mit anderem Body wird mit `422 wpcap_partner_idempotency_mismatch` abgelehnt.

### Weitere Endpunkte

| Route | Methode | Scope | Zweck |
|---|---|---|---|
| `/partner/catalog` | GET | `orders:write` | Sortiment, Preise, Restmengen |
| `/partner/orders` | POST | `orders:write` | Reservierung/Direktverkauf anlegen |
| `/partner/orders/{id}` | GET | `orders:write` | eigene API-Bestellung (ohne Codes) |
| `/partner/orders/{id}/confirm` | POST | `orders:write` | Zahlung bestätigen → Tickets |
| `/partner/orders/{id}/cancel` | POST | `orders:cancel` | Reservierung freigeben / bezahlte Bestellung stornieren |
| `/partner/orders/{id}/tickets` | GET | `tickets:issue` | Ticket-Codes erneut abrufen |
| `/partner/sales/statement?period=YYYY-MM` | GET | `settlements:read` | Wiederverkäufer-Abrechnung des Monats |

`{id}` ist die öffentliche Bestell-ID (32 Hex-Zeichen). Bestellungen anderer Verkäufer oder außerhalb der
Key-Einschränkung sind `404`. Bodies sind JSON-Objekte; Ganzzahlen als JSON-Zahlen. Unbekannte Felder
werden abgelehnt (`400 wpcap_partner_invalid_field`).

### Stornieren

```bash
curl -X POST https://example.com/wp-json/pass-captain/v1/partner/orders/6f1c0e…/cancel \
  -H "Authorization: Bearer wpcap_pk_…" -H "Content-Type: application/json" \
  -d '{ "reason": "Kunde storniert" }'
```

- **Reservierung** (unbezahlt): wird freigegeben (`"released": true`).
- **Bezahlte Bestellung**: nur innerhalb des Stornofensters ab Bestätigung, vor Veranstaltungsbeginn und
  wenn kein Ticket eingecheckt ist. Es wird eine Erstattung über den vollen Restbetrag als *extern
  erstattet* erfasst (der Verkäufer zahlt das Geld selbst zurück), alle Tickets werden gesperrt, die
  Bestellung ist `refunded` (Antwort mit `refund.id`, `amount_minor`, `tax_minor`).
- Wiederholte Aufrufe sind idempotent (`"already_cancelled": true`).

### Fehlercodes (schreibende Endpunkte)

| Status | Code | Ursache |
|---|---|---|
| 400 | `wpcap_partner_idempotency_key` | Header `Idempotency-Key` fehlt/ungültig |
| 400 | `wpcap_partner_invalid_body` | Body ist kein JSON-Objekt |
| 400 | `wpcap_partner_invalid_field` | unbekannte Felder (`data.fields`) |
| 400 | `wpcap_partner_invalid_mode` / `_items` / `_buyer` / `_attendees` / `_reference` | ungültige Eingabe (`data.field`) |
| 400 | `wpcap_partner_order_invalid` | von der Bestelllogik abgelehnt, z. B. Pflichtfelder der Teilnehmenden (`data.reason`) |
| 400 | `wpcap_partner_allocation_mixed` | Positionen aus verschiedenen Holds |
| 400 | `wpcap_partner_invalid_billing` | ungültige Rechnungsdaten in `billing` |
| 422 | `wpcap_partner_billing_required` | Bestellbetrag über der Rechnungsdaten-Schwelle (Einstellung, Standard 250 €) ohne `billing` |
| 403 | `wpcap_partner_forbidden` | Scope fehlt (z. B. `orders:write`) |
| 403 | `wpcap_partner_type_forbidden` | Ticketart nicht im Sortiment bzw. außerhalb der Key-Einschränkung |
| 403 | `wpcap_partner_anonymous_forbidden` | Verkauf ohne E-Mail nicht freigegeben bzw. ohne `tickets:issue` |
| 404 | `wpcap_partner_order_missing` | Bestellung unbekannt oder gehört einem anderen Verkäufer |
| 409 | `wpcap_partner_order_rejected` | ausverkauft, Kontingent erschöpft, Verkauf geschlossen … (Meldung im Klartext; `data.retryable` bei Überlast, dann Status 503) |
| 409 | `wpcap_partner_hold_expired` | Reservierung abgelaufen |
| 409 | `wpcap_partner_order_state` / `wpcap_partner_confirm_failed` | Status erlaubt die Aktion nicht |
| 409 | `wpcap_partner_cancel_not_allowed` / `_window_closed` / `_started` / `_checked_in` | Storno nicht (mehr) möglich |
| 422 | `wpcap_partner_idempotency_mismatch` | Schlüssel mit anderem Body wiederverwendet |
| 429 | `wpcap_partner_rate_limited` | Ratenlimit (Lesen oder Schreiben) |

### Sicherheit und Datenschutz

- Jeder schreibende Aufruf – erfolgreich oder abgelehnt – wird als `partner_api_write` protokolliert
  (`action` = `create` | `confirm` | `cancel` | `tickets_download`, `result`, Fehlercode, Key, Veranstalter,
  gekürzte IP). Keine E-Mail-Adressen, Idempotenzschlüssel oder Secrets im Log.
- Webhooks laufen wie gewohnt (`order.paid`, `ticket.issued`, `order.cancelled`, `order.refunded`,
  `ticket.revoked`).
- E-Mail-Adressen erscheinen in Antworten nur mit `attendees:read`.
- **Ticket-Codes**: Pass Captain speichert Codes sonst nur als Hash. Für API-Bestellungen ist der
  Verkäufer Inhaber der Tickets („holder of record“); die Codes werden deshalb bei der Ausstellung
  zusätzlich **verschlüsselt** gespeichert (libsodium secretbox, Schlüssel aus dem WordPress-Salt
  `AUTH_KEY` bzw. der Konstante `WPCAP_PARTNER_CODES_KEY`) und können mit `tickets:issue` ohne Rotation
  erneut abgerufen werden. Ein Datenbank-Abzug allein verrät die Codes nicht. Rotiert das Team einen
  Code (Neuversand, Druck, Neuausstellung), wird die gespeicherte Kopie aktualisiert; nach einer
  Übertragung oder Zuweisung an eine andere Person liefert die API `code: null`. Keys mit
  `tickets:issue` daher wie Zahlungsdaten schützen (IP-Allowlist empfohlen).
- Wiederverkäufer haben über die lesenden Endpunkte keinen Zugriff auf Bestellungen fremder Events.

### Abrechnung von API-Verkäufen

- **Veranstalter-Abrechnung**: enthält wie bisher **alle** bezahlten Verkäufe der eigenen Events – auch
  die eines Wiederverkäufers. Provision und Gebühren des Veranstalters gelten unverändert.
- **Wiederverkäufer-Abrechnung** (Detailseite des Veranstalters/Wiederverkäufers, CSV, API
  `/partner/sales/statement`): listet je Monat die API-Verkäufe des Verkäufers (nach Bestätigung) und deren
  Erstattungen (nach Abschluss), mit Vergütung = Provision % vom Netto + Gebühr je Ticket (bei
  Erstattungen storniert) zzgl. USt; *An die Plattform abzuführen* = vereinnahmt brutto nach
  Erstattungen − Vergütung brutto. Bei Veranstaltern, die selbst verkaufen, ist die Vergütung
  normalerweise 0 – der Betrag zeigt dann, was der Veranstalter selbst kassiert hat; er wird
  automatisch mit der Veranstalter-Abrechnung verrechnet (`netted_in_settlement: true`). Die Aufstellung
  wird bei jedem Aufruf berechnet (keine Belegnummer).
- **Automatische Verrechnung**: Eigene API-Verkäufe eines Veranstalters (Zahlungsanbieter `partner`,
  Verkäufer = derselbe Veranstalter) zählen in der Veranstalter-Abrechnung für Provision und Gebühren,
  werden aber als *von dir selbst vereinnahmte Ticketerlöse* (nach Erstattungen) von der Auszahlung
  abgezogen. Ergibt sich ein negativer Saldo, wird nichts ausgezahlt: Standard ist der Vortrag in die
  nächste Abrechnung (*Saldovortrag aus der vorigen Abrechnung*), alternativ (Einstellungen →
  „Negativer Saldo …“) eine sofortige *Forderung an Veranstalter*. Felder in `totals`:
  `payout_before_netting`, `collected_self`, `carry_in`, `balance`, `payout`, `carry_out`,
  `due_from_partner`, `negative_balance_mode`.

## Abrechnungen

### Zeitraum und Inhalt

Eine Abrechnung gilt für einen Kalendermonat (Zeitzone der Website). Sie enthält alle **noch nicht
abgerechneten** Positionen der Veranstalter-Events bis zum Monatsende (ab „Abrechnung ab“):

| Art | Inhalt |
|---|---|
| Verkauf (`sale`) | Bestellposition einer bezahlten (oder später erstatteten) Bestellung aus dem Monat |
| Nachtrag Verkauf (`late_sale`) | Position einer Bestellung aus einem früheren Monat, die dort noch nicht abgerechnet war (z. B. später bezahlt) |
| Erstattung (`refund`) | abgeschlossene Erstattung einer Bestellung aus dem Monat |
| Korrektur Erstattung (`correction`) | abgeschlossene Erstattung einer Bestellung aus einem früheren Monat |

Beträge stammen aus den Bestell-Schnappschüssen (brutto inkl. USt, Rabatt wie im Checkout auf die
Positionen verteilt); Erstattungen werden wie im Berichtsmodul auf die Positionen verteilt (USt laut
Erstattung). Bestellungen anderer Währungen werden ignoriert.

### Berechnung

Je Position: `Netto = Brutto − USt`, `Provision = round(Netto × Provisionssatz)` (kaufmännisch,
symmetrisch für negative Beträge), `Ticketgebühr = Tickets × Gebühr`. Bei Erstattungen gemäß
Erstattungsregel storniert oder beibehalten.

```
Ticketumsatz brutto nach Erstattungen  = Σ Brutto (Verkäufe − Erstattungen)
Plattformgebühr netto                  = Σ Provision + Σ Ticketgebühr
USt auf Plattformgebühr                = round(Plattformgebühr netto × USt-Satz Gebühr)
Auszahlungsbetrag                      = Ticketumsatz brutto − Plattformgebühr brutto
```

Ein negativer Auszahlungsbetrag wird als „Forderung an Veranstalter“ ausgewiesen. Die USt des
Ticketumsatzes wird je Steuersatz ausgewiesen.

**Verkaufsmodell** (je Veranstalter, siehe [SALES-MODELS.md](SALES-MODELS.md)): Beim Modell
*Vermittler* ist die Abrechnung eine **Provisionsrechnung** (Nummer `PA-…`) mit informativer Aufstellung
der vereinnahmten Ticketerlöse; bei Reverse Charge (Veranstalter im EU-Ausland mit USt-IdNr.) entfällt die
USt auf die Plattformgebühr. Beim Modell *Eigenhändler* (Standard) entsteht ein Beleg mit **Teil A
Gutschrift** (eigene Serie `credit-self`, z. B. `GU-2026-000001`), **Teil B Rechnung über
Plattformgebühren** (`PA-…`) und **Teil C Verrechnung**; dann gilt
`Auszahlungsbetrag = Gutschrift brutto − Plattformgebühr brutto` (Gutschrift = Netto-Ticketumsatz + USt je
Satz; ohne USt bei Kleinunternehmern oder Reverse Charge). Die API liefert zusätzlich `document_type`
(`commission_invoice` | `self_billing` | leer für Altbelege), `sales_model` und `self_billing_number`.

### Status

1. **Entwurf** – „Entwurf erstellen“ (Veranstalter + Monat). Jederzeit „Neu berechnen“ oder löschen.
   Veranstalter-Benutzer und die API sehen Entwürfe nicht.
2. **Abgeschlossen** – „Abschließen“ ist erst nach Monatsende möglich und nur, wenn kein Entwurf
   eines früheren Monats offen ist. Der Entwurf wird neu berechnet, erhält eine lückenlose Nummer
   (Serie `partner`, Präfix einstellbar, z. B. `PA-2026-000001`), Veranstalter- und Verkäuferdaten
   werden als Schnappschuss gespeichert, und jede Position wird per eindeutigem Schlüssel je
   Veranstalter gesperrt (Datenbank-Unique-Index) – keine Position kann zweimal abgerechnet werden.
3. **Ausgezahlt** – „Als ausgezahlt markieren“ mit optionaler Zahlungsreferenz.

Abgeschlossene/ausgezahlte Abrechnungen sind unveränderlich (keine Neuberechnung, kein Löschen, keine
Abrechnung für frühere Monate mehr). Spätere Erstattungen und Nachzahlungen erscheinen automatisch in
der nächsten Abrechnung als Korrektur bzw. Nachtrag mit Ursprungsperiode.

Ausgaben: druckbare HTML-Ansicht („Drucken oder als PDF speichern“) und CSV (Semikolon, UTF-8 mit BOM,
formelsicher) mit allen Positionen, USt nach Satz und Summen.

Audit-Log: `partner_settlement_created`, `partner_settlement_finalized`, `partner_settlement_paid`,
`partner_settlement_deleted`.

### Berechtigungen

| Aktion | Wer |
|---|---|
| Veranstalter, Events, Benutzer, API-Keys | `manage_options` |
| Abrechnungen erstellen, neu berechnen, abschließen, auszahlen, löschen (Entwurf) | `manage_options` oder `wpcap_view_finance` |
| Eigene abgeschlossene Abrechnungen ansehen | dem Veranstalter zugeordnete WordPress-Benutzer |

Die Texte und die rechtliche Eignung der Abrechnung (z. B. als Gutschrift im Sinne des UStG) sind
vom Betreiber für sein Land zu prüfen.
