# Ticketumtausch (Umtausch / Upgrade)

Modul `PassCaptain\Modules\Exchange` (`src/Modules/Exchange.php`, Dienste in `src/Exchange/`).
Admin: **Pass Captain → Umtausch** (Capability `wpcap_manage_orders`, eventbezogen über `Access`) und
Abschnitt „Umtausch“ in der Bestelldetailansicht. Käufer:innen: Bestellportal (persönlicher Link).
Abendkasse: öffentlicher Dienst `Exchange\Service::box_office_exchange()`.

Ein gültiges Ticket (nicht eingecheckt, nicht erstattet, Bestellung bezahlt) wird in eine andere
Ticketart und/oder einen anderen Termin **desselben Events** getauscht – Mitarbeitende dürfen auch in
andere Events **derselben Serie** tauschen (`allow_series`). Das alte Ticket wird widerrufen
(`wpcap_ticket_revoked`, Grund `exchange`), das neue bekommt einen neuen Code und wird per Mail
`ticket_exchanged` verschickt. Das neue Ticket gehört immer zur **ursprünglichen Bestellung**.

## Ablauf

1. **Prüfung**: Ticketstatus (auch Einlass-Scans bei Mehrfacheintritt), Bestellung bezahlt, Ziel aktiv,
   Termin geplant und in der Zukunft (Abendkasse: auch nach Beginn), gleiche Währung, gleiches Event
   bzw. Serie, Regeln der Selbstbedienung (siehe unten).
2. **Sperren und Kapazität** (eine Transaktion, wie der Checkout): Bestellung → Ticket → Ticketarten
   (nach ID) → Termine (nach ID) → alte Bestellposition, jeweils `FOR UPDATE`. Danach wird **zuerst die
   Einheit des alten Tickets freigegeben** und dann `Orders::reserve_exchange_capacity()` aufgerufen:
   Filter `wpcap_reserve_capacity` mit `phase = 'exchange'` (Serienkontingente, Kontingente/Sperren,
   weitere Module) und die Kernprüfung von Ticketart- und Terminkapazität. Ein Umtausch innerhalb eines
   ausverkauften Termins funktioniert deshalb, ein ausverkauftes Ziel wird abgelehnt
   (`sold_out`, `occurrence_sold_out`, Modulcodes).
3. **Preis**: Zielpreis über `Orders::unit_price()` zum Umtauschzeitpunkt (Serien-Preisstufen gelten).
   Wert des alten Tickets = was eine Erstattung auszahlen würde (Positionsanteil nach Rabatten) bzw. bei
   einem bereits getauschten Ticket der beim Umtausch gespeicherte Wert (`new_value_minor`).
4. **Ergebnis**:
   - *Nichts zu zahlen* (gleicher Preis, günstiger, Kulanz): sofortiger Umtausch.
   - *Aufpreis und/oder Gebühr*: **Zusatzbestellung** (siehe unten); das neue Ticket kommt erst nach
     Zahlung, bis dahin bleibt das alte Ticket gültig.
5. **Sitzplätze**: Hat die Ziel-Ticketart einen Saalplan, wird der beste freie Platz zugewiesen oder der
   von Mitarbeitenden gewählte (`seat_id`; Auswahl im Admin). Der Platz wird in derselben Transaktion
   über die Saalplan-API (`Seating\Reservations`) belegt; der alte Platz wird mit dem Widerruf frei.

## Geld

| Fall | Ergebnis |
|---|---|
| Ziel teurer | Zusatzbestellung über die Differenz (+ Gebühr). Kulanz (`waive`, nur Mitarbeitende): ohne Aufpreis und Gebühr. |
| gleicher Preis | ohne Gebühr sofort; mit Gebühr Zusatzbestellung über die Gebühr. |
| Ziel günstiger, Regel `refund` | Teilerstattung der Differenz über `Refunds::request_amount()` (Freigaberegeln/Schwelle gelten; Selbstbedienung zählt nie als Freigabe). Eine Gebühr wird mit der Erstattung verrechnet (höchstens bis zur Differenz). |
| Ziel günstiger, Regel `none` | keine Erstattung; das neue Ticket behält den bisherigen Wert (spätere Erstattung/Umtausch rechnet damit). Eine Gebühr wird per Zusatzbestellung berechnet. |

**Zusatzbestellung**: eigene Bestellung (gleiche E-Mail, Sprache, Rechnungsdaten) mit Position
„Ziel-Ticketart, Menge 1, Preis = Differenz“ und – bei Gebühr – einer Gebührenposition mit Menge 0
(kein Kontingent). Sie hält das Ziel mit einer normalen Reservierung (`hold_minutes`, bei Überweisung
`manual_hold_hours`); Ablauf über den normalen Cron (`Orders::expire_holds()`) gibt das Ziel frei und
setzt den Umtausch auf `expired`. Zahlung über die normalen Wege (Webhook, „Als bezahlt markieren“,
Abendkasse); verspätete Zahlung nutzt die Kern-Wiederherstellung (Kapazität wird neu geprüft).
Verknüpfung: Spalte `supplementary_order_id` in `wpcap_ticket_exchanges` und `price_snapshot.exchange`
der Zusatzbestellung. Für Zusatzbestellungen stellt der Kern keine Tickets aus
(`wpcap_issue_order_lines`); nach Zahlung wandert die Einheit in die ursprüngliche Bestellung (die
Zusatzposition zählt danach nicht mehr) – das Kontingent ist zu jedem Zeitpunkt genau einmal belegt.

**Rechnungen**: Die Zusatzbestellung bekommt eine normale Rechnung („Aufpreis Umtausch A → B“,
„Umtauschgebühr“ über `wpcap_invoice_items`). Erstattete Differenzen erzeugen über den bestehenden
Ablauf eine Rechnungskorrektur zur Rechnung der ursprünglichen Bestellung. Ein Umtausch ohne
Geldbewegung ändert bestehende Rechnungen nicht.

**Spätere Erstattung**: Wird ein getauschtes Ticket erstattet (z. B. Terminabsage), werden die dafür
bezahlten Aufpreise (ganze Umtauschkette) automatisch als Betragserstattung der Zusatzbestellungen
angestoßen; die Gebühr bleibt einbehalten (Filter `wpcap_exchange_refund_fee`).

## Bestellpositionen

Eine Einheit wandert innerhalb der ursprünglichen Bestellung: alte Position `quantity − 1` (inkl.
Anteil an Betrag und USt.), Zielposition `+ 1` mit dem übertragenen Wert (bei Erstattung abzüglich der
erstatteten Differenz). Damit stimmen Kapazität, Serienkontingente und spätere Erstattungen.

**Buchhaltung sieht die Positionen wie bezahlt** (Schema 3.8.0, `PassCaptain\OrderLines`): Vor der
ersten Änderung einer bezahlten Position werden Menge, Betrag und USt. in `orig_quantity`,
`orig_line_total_minor`, `orig_tax_minor` festgehalten; vom Umtausch neu angelegte Positionen haben
dort `0`. Rechnungen, Berichte, Veranstalter-Abrechnung, Wiederverkäufer-Abrechnung, Z-Bericht,
Affiliate-Provision und die REST-Verkaufsstatistik lesen diese Werte. So gilt
„Abrechnung = Zahlungen − Erstattungen“: die ursprüngliche Position bleibt unverändert, der Aufpreis
steht in der Zusatzbestellung (dort ohne Ticketzählung und ohne Ticketgebühr), eine erstattete
Differenz als Erstattung. Umgezogene Einheiten erscheinen nicht erneut als (Nach-)Verkauf.

**Wert eines Tickets** (`Service::ticket_value()`): Anteil seiner bezahlten Position nach
Bestellrabatten, gerechnet auf die volle Gegenleistung inklusive Mehrzweckgutschein
(`Checkout::gross_minor()`); für ein getauschtes Ticket der beim Umtausch gespeicherte Wert.

**Steuersatz**: Ein Umtausch zwischen Ticketarten mit unterschiedlichem Steuersatz ist gesperrt
(`wpcap_exchange_tax_rate`) – dafür Ticket erstatten und neu verkaufen (Rechnungskorrektur und neuer
Beleg).

## Selbstbedienung (Bestellportal)

Einstellungen global (Admin → Umtausch) und pro Event überschreibbar (`wpcap_exchange_policies`):

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `enabled` | 1 | Umtausch durch Mitarbeitende erlaubt |
| `portal_enabled` | 0 | Käufer:innen dürfen im Portal tauschen (nur gleiches Event) |
| `portal_hours` | 24 | bis X Stunden vor Beginn (alter **und** neuer Termin) |
| `portal_max_per_ticket` | 1 | höchstens N Umtausche je Ticket (über die ganze Kette) |
| `refund_policy` | `refund` | günstigeres Ziel: `refund` oder `none` |
| `fee_minor` | 0 | Umtauschgebühr brutto (USt.-Satz der Ziel-Ticketart) |
| `allow_series` | 1 | Mitarbeitende dürfen innerhalb der Serie tauschen (nur global) |

REST: `POST /wp-pass-captain/v1/orders/{public_id}/exchange` mit `access_token`, `ticket_public_id`,
`ticket_type_id`, `idempotency_key`, optional `provider`. Antwort: `status` (`completed` |
`pending_payment`), Beträge, `message`, bei Aufpreis `payment` (`redirect_url` bzw. `instructions`).
Rate-Limit 10/h je Bestellung (`wpcap_exchange_portal_rate_limit`). Kulanz und Regel-Overrides sind im
Portal nicht möglich. Für den Aufpreis bekommt die Käufer:in zusätzlich den persönlichen Link der
Zusatzbestellung (mit Zahlungsinformationen bei Überweisung).

## Abendkasse

```php
PassCaptain\Exchange\Service::box_office_exchange( $ticket_id_or_public_id, $target_type_id, array(
	'pos_method' => 'cash',          // cash|card|free|invoice → Zahlungsanbieter pos_<method>
	'idempotency_key' => $form_key,
	'seat_id' => 0,                  // 0 = bester Platz
	'waive' => false,
) );
```

Ein Aufpreis wird an der Kasse kassiert und sofort bestätigt (`Orders::confirm_external_payment()`);
das neue Ticket entsteht im selben Aufruf. Kapazitätsquelle `pos` (vertrauenswürdig für Kontingente).

## Idempotenz und Nebenläufigkeit

- `idempotency_key` je Ticket (eindeutiger Index); eine Wiederholung liefert denselben Umtausch
  (`reused`). Ohne Schlüssel liefert ein erneutes Absenden eines offenen Aufpreis-Umtauschs mit
  gleichem Ziel denselben Umtausch; ein anderes Ziel wird abgelehnt (`exchange_pending`).
- Je Ticket höchstens ein offener Umtausch (eindeutiger Index `pending_ticket_id`), serialisiert über
  die Ticket-Zeilensperre. Zwei Umtausche auf den letzten Platz: genau einer gewinnt, die anderen
  erhalten `sold_out` und behalten ihr Ticket.
- Check-in, Widerruf oder Storno/Erstattung des alten Tickets bzw. der ursprünglichen Bestellung
  bricht einen offenen Aufpreis-Umtausch ab (Zusatzbestellung wird storniert). Wird trotzdem bezahlt,
  obwohl das alte Ticket nicht mehr gültig ist, wird der Umtausch `needs_review` und der Betreiber per
  Mail informiert.

## Status (`wpcap_ticket_exchanges.status`)

`completed`, `pending_payment`, `expired` (Reservierung abgelaufen), `cancelled`, `needs_review`.
Jede Zeile enthält Kanal (`admin`, `portal`, `pos`, `api`), Beträge (Wert alt, Zielpreis, Differenz,
Gebühr, Erstattung, Zahlbetrag, neuer Wert), Kulanz, Erstattungs-ID/-Status, Sitzplatz, Notiz,
ausführende Person und Zeitpunkte. Audit: `ticket_exchanged`, `ticket_exchange_requested`,
`ticket_exchange_expired`, `ticket_exchange_cancelled`, `ticket_exchange_review`,
`ticket_exchange_refund_failed`, `ticket_exchange_supplement_refund`.

## Hooks

| Hook | Art | Zweck |
|---|---|---|
| `wpcap_ticket_exchanged( $old_ticket_id, $new_ticket_id, $exchange_id, $exchange )` | Action | nach erfolgreichem Umtausch |
| `wpcap_ticket_revoked( $ticket_id, 'exchange' )` | Action | altes Ticket (Webhook `ticket.revoked`, Wallet, Saalplan) |
| `wpcap_tickets_issued( $order_id, 1 )` | Action | neues Ticket (Webhook `ticket.issued`) |
| `wpcap_ticket_created` | Action | in der Transaktion, wie bei der Ausstellung (Sitzplatz) |
| `wpcap_reserve_capacity` (`phase = exchange`) | Filter | Modulkapazität; Kontext `exchange` = Ticket, Bestellung, alte Ticketart; `source` = Kanal |
| `wpcap_exchange_money` | Filter | Beträge vor dem Speichern ändern |
| `wpcap_exchange_refund_fee` | Filter | Gebühr bei späterer Erstattung mit erstatten |
| `wpcap_issue_order_lines` | Filter (Kern) | Positionen, für die der Kern Tickets ausstellt |
| `wpcap_invoice_items` | Filter (Kern) | Rechnungspositionen einer Bestellung |
| `wpcap_order_portal_ticket_html` | Filter (Kern) | zusätzliches HTML je Ticket im Bestellportal |
| `wpcap_admin_order_detail` | Action (Kern) | zusätzliche Abschnitte in der Bestelldetailansicht |

Webhooks: Abonnenten erhalten `ticket.revoked` (Grund `exchange`), `ticket.issued` und für
Zusatzbestellungen `order.paid`/`order.refunded`.

## Grenzen

- Nur Bestellungen mit nativer Zahlung bzw. Abendkasse; WooCommerce-Erstattungen laufen in WooCommerce
  (`Refunds::request_amount()` lehnt sie ab – der Umtausch erfolgt, die Erstattung muss dort gebucht
  werden; Hinweis im Ergebnis `warning`).
- Antworten auf Teilnehmerfelder der alten Ticketart werden nicht übernommen (Name/E-Mail der
  Zuweisung schon).
- Eine Zusatzbestellung, deren Aufpreis vollständig erstattet wurde, steht auf „Erstattet“, auch wenn
  eine Gebühr einbehalten wurde (`refunded_minor` zeigt den tatsächlichen Betrag).
