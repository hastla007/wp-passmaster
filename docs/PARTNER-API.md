# Veranstalter, Partner-API und Veranstalter-Abrechnungen

> **Bezeichnung:** In der Oberfläche heißen diese Organisationen **Veranstalter** (früher „Partner“), damit sie nicht mit den Affiliate-Partnern von WP Affiliate Captain verwechselt werden. Technische Bezeichner bleiben unverändert: REST-Pfade `/wp-json/wp-pass-captain/v1/partner/…`, Header `X-WPCAP-Partner-API`, Fehlercodes `wpcap_partner_*`, Tabellen, Optionen und die Rolle (Slug). Bestehende Installationen behalten den gespeicherten Rollennamen „Pass Captain Partner“; neue Installationen legen „Pass Captain Veranstalter“ an. Im Text unten steht „Partner“ für diese Veranstalter.

Partner sind Organisationen (Veranstalter oder Wiederverkäufer), denen Events zugeordnet werden.
Für sie gibt es:

- **Stammdaten und Provisionsmodell**: Pass Captain → Veranstalter (Capability `manage_options`),
- **Partner-API**: lesender REST-Zugriff mit eigenen, eingeschränkten API-Keys,
- **Partner-Abrechnungen**: monatliche Abrechnungen mit Belegnummer (Pass Captain → Veranstalter-Abrechnungen).

Code: `src/Modules/Partners.php`, `src/Partners/`.

## Partner anlegen

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
| Status | `inaktiv` sperrt alle API-Keys des Partners (403). |
| Events | Ein Event gehört höchstens einem Partner. |
| WordPress-Benutzer | IDs, Logins oder E-Mails. Diese Benutzer (typisch Rolle „Pass Captain Partner“) sehen unter *Veranstalter-Abrechnungen* nur die **abgeschlossenen** Abrechnungen ihres Partners, ausschließlich lesend (Ansicht/Druck, CSV). |

Alle Änderungen werden im Audit-Log protokolliert (`partner_created`, `partner_updated`,
`partner_events_set`, `partner_users_set`).

## API-Keys

Keys werden auf der Detailseite eines Partners erzeugt.

- Format: `wpcap_pk_<12 Hex-Zeichen Präfix>_<64 Hex-Zeichen Secret>`.
- Der vollständige Key wird **nur einmal** nach dem Erzeugen angezeigt. Gespeichert werden nur das
  Präfix und der SHA-256-Hash des Secrets (256 Bit Zufall).
- **Scopes** (mindestens einer):

| Scope | Zugriff |
|---|---|
| `events:read` | Events, Termine, Ticketarten, verkaufte Menge |
| `orders:read` | Bestellungen mit den Positionen der Partner-Events – ohne E-Mail |
| `tickets:read` | Tickets – ohne E-Mail |
| `checkins:read` | Check-ins (ohne Mitarbeiter-IDs) |
| `reports:read` | Umsatz-/USt-/Auslastungsbericht (wie Modul „Berichte“) |
| `settlements:read` | abgeschlossene und ausgezahlte Abrechnungen |
| `attendees:read` | **Personenbezogene Daten**: ergänzt `customer_email` (Bestellungen) und `attendee_email` (Tickets). Nur vergeben, wenn eine Rechtsgrundlage und ggf. ein AV-Vertrag besteht. |

  Schreibende Scopes (z. B. `orders:write`) gibt es derzeit nicht.
- **Event-Einschränkung** (optional): nur Events des Partners. Ohne Auswahl gilt der Key für alle
  aktuellen und künftigen Events des Partners. Wird ein Event vom Partner gelöst, verliert jeder
  Key sofort den Zugriff. Event-beschränkte Keys können keine Abrechnungen abrufen (diese umfassen
  alle Events des Partners).
- **IP-Allowlist** (optional): einzelne Adressen oder CIDR-Bereiche, IPv4 und IPv6, z. B.
  `203.0.113.10`, `198.51.100.0/24`, `2001:db8::/32`. Maßgeblich ist `REMOTE_ADDR`; hinter einem
  Reverse Proxy die echte Client-Adresse über den Filter `wpcap_partner_client_ip` liefern.
- **Ablaufdatum** (optional), **Zuletzt genutzt** (minutengenau), **Widerrufen** (sofort wirksam).
- **Ratenlimit**: Anfragen pro Minute und Key (Standard 120, einstellbar unter Veranstalter →
  Einstellungen bzw. je Key). Überschreitung: `429`.
- **Sperre bei Fehlversuchen**: Nach 20 fehlgeschlagenen Anmeldungen (fehlender, ungültiger,
  abgelaufener oder widerrufener Key) innerhalb von 10 Minuten wird die Client-Adresse für
  10 Minuten gesperrt (`429`, auch für gültige Keys).

Audit-Log: `partner_key_created`, `partner_key_revoked` und `partner_key_auth_failed` mit
`reason` = `missing`, `malformed`, `invalid`, `revoked`, `expired`, `partner_inactive`,
`ip_not_allowed`, `scope_missing`, `rate_limited` (höchstens einmal pro Minute und Key),
`event_not_allowed`, `event_restricted_settlements`. Protokolliert werden Key-ID, Partner, Route und
die gekürzte Client-Adresse (IPv4 /24, IPv6 /48) – nie das Secret.

## Authentifizierung

```http
GET /wp-json/wp-pass-captain/v1/partner/me
Authorization: Bearer wpcap_pk_3f9a0c1b2d4e_8c1f…
```

Hinweis: Manche Apache/CGI-Setups entfernen den `Authorization`-Header; dann z. B.
`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in der `.htaccess` ergänzen.

Fehler (WordPress-REST-Format `{code, message, data: {status}}`):

| Status | Code | Ursache |
|---|---|---|
| 401 | `wpcap_partner_unauthorized` | Key fehlt, ist ungültig, abgelaufen oder widerrufen |
| 403 | `wpcap_partner_forbidden` | Scope fehlt, IP nicht erlaubt, Partner inaktiv |
| 403 | `wpcap_partner_event_forbidden` | `event_id` außerhalb der erlaubten Events |
| 403 | `wpcap_partner_settlements_restricted` | Abrechnungen mit event-beschränktem Key |
| 429 | `wpcap_partner_rate_limited` / `wpcap_partner_locked` | Ratenlimit / Sperre nach Fehlversuchen |

Alle Antworten tragen `Cache-Control: no-store`. Beträge sind ganzzahlige Minor Units (Cent),
Zeitstempel ISO 8601 in UTC.

## Endpunkte

Alle unter `/wp-json/wp-pass-captain/v1`, nur `GET`.

| Route | Scope | Parameter |
|---|---|---|
| `/partner/me` | – (jeder gültige Key) | – |
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

Positionen anderer Partner bzw. nicht freigegebener Events werden aus Bestellungen herausgefiltert.
`customer_email` erscheint nur mit `attendees:read`. Gelöschte oder anonymisierte Adressen (Platzhalter `…@invalid.example` nach einer Datenschutzlöschung, Kassenverkäufe ohne E-Mail) und leere Teilnehmeradressen werden als `null` ausgegeben.

## Abrechnungen

### Zeitraum und Inhalt

Eine Abrechnung gilt für einen Kalendermonat (Zeitzone der Website). Sie enthält alle **noch nicht
abgerechneten** Positionen der Partner-Events bis zum Monatsende (ab „Abrechnung ab“):

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

Ein negativer Auszahlungsbetrag wird als „Forderung an Partner“ ausgewiesen. Die USt des
Ticketumsatzes wird je Steuersatz ausgewiesen.

### Status

1. **Entwurf** – „Entwurf erstellen“ (Partner + Monat). Jederzeit „Neu berechnen“ oder löschen.
   Partner-Benutzer und die API sehen Entwürfe nicht.
2. **Abgeschlossen** – „Abschließen“ ist erst nach Monatsende möglich und nur, wenn kein Entwurf
   eines früheren Monats offen ist. Der Entwurf wird neu berechnet, erhält eine lückenlose Nummer
   (Serie `partner`, Präfix einstellbar, z. B. `PA-2026-000001`), Partner- und Verkäuferdaten
   werden als Schnappschuss gespeichert, und jede Position wird per eindeutigem Schlüssel je
   Partner gesperrt (Datenbank-Unique-Index) – keine Position kann zweimal abgerechnet werden.
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
| Partner, Events, Benutzer, API-Keys | `manage_options` |
| Abrechnungen erstellen, neu berechnen, abschließen, auszahlen, löschen (Entwurf) | `manage_options` oder `wpcap_view_finance` |
| Eigene abgeschlossene Abrechnungen ansehen | dem Partner zugeordnete WordPress-Benutzer |

Die Texte und die rechtliche Eignung der Abrechnung (z. B. als Gutschrift im Sinne des UStG) sind
vom Betreiber für sein Land zu prüfen.
