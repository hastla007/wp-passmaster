# Zustandsautomaten

Dieses Dokument beschreibt alle Entitäten mit Status: erlaubte Übergänge, wer oder was sie auslöst,
und die Endzustände. Es beschreibt außerdem, wie doppelte und in falscher Reihenfolge eintreffende
Zahlungsereignisse (Webhooks) behandelt werden.

## Grundregeln

- **Bedingte Updates.** Jeder Statuswechsel ist ein bedingtes `UPDATE … WHERE id=? AND status IN
  (<erlaubte Quellzustände>)`. Ein veralteter Lesestand, ein doppeltes oder ein verspätetes Ereignis
  ändert dann keine Zeile; der Aufrufer behandelt „0 Zeilen“ als „nicht anwendbar“, nie als Erfolg.
- **Zentrale Tabellen.** `PassCaptain\StateMachine::TRANSITIONS` enthält die Übergänge für Bestellung,
  Erstattung, Job, Umtausch und Zahlungsereignis. `StateMachine::transition()` schreibt den
  bedingten Wechsel, `StateMachine::source_sql()` liefert die Bedingung für eigene Abfragen, und
  `StateMachine::allowed()` prüft einen Übergang vorab. Ticket, Reservierung, Warteliste und
  Servicebuchung haben nur wenige, feste Übergänge; dort steht die Bedingung direkt im jeweiligen
  `UPDATE`.
- **Sperren.** Wechsel, die Geld oder Kontingent betreffen, laufen zusätzlich unter `SELECT … FOR UPDATE`
  auf der Bestellung (Reihenfolge: Bestellung → Ticketarten → Termine). Die Bedingung im `UPDATE` ist die
  zweite Sicherung, falls ein Aufrufer ohne Sperre schreibt.
- **Endzustände** haben keine ausgehenden Übergänge. Ein Ereignis für eine Entität in einem Endzustand
  wird protokolliert (`Audit`) und gegebenenfalls dem Admin gemeldet, ändert aber nichts.

## Bestellung (`wpcap_orders.status` / `payment_status`)

Die beiden Spalten wechseln immer gemeinsam:

| `status` | `payment_status` | Bedeutung |
|---|---|---|
| `pending_payment` | `pending` | Angelegt, Kontingent durch eine Reservierung gehalten, Zahlung offen. |
| `complete` | `paid` | Bezahlt (oder kostenlos), Tickets ausgestellt. Teilerstattungen ändern nur `refunded_minor`. |
| `needs_review` | `paid_unallocated` | Zahlung kam nach Ablauf der Reservierung, und es ist kein Kontingent mehr frei. Es gibt keine Tickets; ein Admin muss den Fall klären. |
| `expired` | `expired` | Die Reservierung ist abgelaufen, ohne dass eine Zahlung kam. |
| `cancelled` | `cancelled` | Storniert. Reservierungen sind freigegeben, gültige Tickets widerrufen. |
| `refunded` | `refunded` | Vollständig erstattet, gültige Tickets widerrufen. **Endzustand.** |

| Von → nach | Auslöser |
|---|---|
| `pending_payment` → `complete` | Zahlungsbestätigung: Webhook `paid` (Stripe, PayPal, Mollie), PayPal-/Mollie-Rückkehr, manuelle Zahlungsbestätigung im Admin, WooCommerce `processing`/`completed`, Kasse, Partner-API. Kostenlose Bestellungen entstehen direkt als `complete`. |
| `pending_payment`/`expired` → `needs_review` | Späte Zahlung ohne freies Kontingent (`Orders::confirm_external_payment()`). Der Admin bekommt eine Mail. |
| `pending_payment` → `expired` | Cron `wpcap_cleanup_reservations` (`Orders::expire_holds()`): Die Reservierung ist abgelaufen, und die Zahlung ist noch offen. |
| `expired` → `complete` | Späte Zahlung, und das Kontingent reicht noch (erneute Prüfung unter Sperre). |
| `needs_review` → `complete` | Admin-Aktion „Tickets erneut ausstellen“ (WooCommerce) oder manuelle Bestätigung, nachdem Kontingent frei geworden ist. Wiederholte Zahlungs-Webhooks lösen das nie aus. |
| `pending_payment`/`expired`/`needs_review`/`complete` → `cancelled` | Fehlgeschlagene Zahlung (nur solange `pending`), Storno im Admin, WooCommerce `cancelled` (oder `failed`, solange unbezahlt), Neuversuch im WooCommerce-Checkout, Umtausch (ersetzter oder geschlossener Aufpreis), Job `order_cancel_retry`. |
| `complete`/`needs_review`/`cancelled` → `refunded` | Volle Erstattung: native Erstattung (`Refunds`), externe Erstattung (Anbieter-Dashboard, Chargeback, WooCommerce, Partner-API), Job `order_refund_mark_retry`. |

Nicht erlaubt sind unter anderem `pending_payment`/`expired` → `refunded` (es wurde nichts bezahlt;
`Orders::mark_refunded()` liefert `wpcap_order_state` und plant keinen Neuversuch) und alles aus
`refunded` heraus. Eine bezahlte Bestellung kann nicht mehr auf `expired` wechseln.

## Ticket (`wpcap_tickets.status`)

| Von → nach | Auslöser |
|---|---|
| (neu) → `valid` | Ausstellung nach Zahlung (`Orders::issue_and_email()`, genau einmal je Einheit), Umtausch, Kasse. |
| `valid` → `checked_in` | Einlass (`Checkin`, bedingtes Update `status='valid'`). |
| `checked_in` → `valid` | Einlass im Admin zurücknehmen (bedingtes Update `status='checked_in'`). |
| `valid` → `revoked` | Storno, volle oder Teil-Erstattung, Umtausch (altes Ticket), Eventabsage, Chargeback. Eingecheckte Tickets werden nie widerrufen; sie bleiben als Nachweis. |

`revoked` ist ein **Endzustand**. Die Flagge `refunded` (0/1) ist unabhängig vom Status: Ein Ticket wird
genau einmal erstattet. Übertragung und Neuausstellung erneuern nur den Code, der Status bleibt `valid`.

## Reservierung (`wpcap_reservations.status`)

| Von → nach | Auslöser |
|---|---|
| (neu) → `active` | Checkout (`Orders::create()`), Umtausch mit Aufpreis, Wartelisten-Angebot. |
| `active` → `converted` | Zahlung bestätigt. |
| `expired`/`released` → `converted` | Späte Zahlung mit genug Kontingent. |
| `active` → `expired` | Cron, wenn `expires_at` überschritten ist, oder späte Zahlung ohne Kontingent. |
| `active` → `released` | Storno oder volle Erstattung der Bestellung. |

`converted` ist ein **Endzustand**. Kontingent zählt nur `active` mit `expires_at` in der Zukunft.

## Zahlung und Zahlungsereignisse

Der Zahlungszustand steht in `payment_status` der Bestellung (siehe oben). Jedes eingehende
Anbieter-Ereignis wird in `wpcap_payment_events` genau einmal angewendet:

| Von → nach | Auslöser |
|---|---|
| (neu) → `processing` | `INSERT IGNORE` je (Anbieter, Ereignis-ID). Existiert die Zeile schon, ist das Ergebnis `duplicate`. |
| `processing` → `processed` | Ereignis vollständig angewendet. |
| `processing` → (gelöscht) | Vorübergehender Fehler: Die Zeile wird freigegeben, der Anbieter bekommt 5xx und stellt erneut zu. Eine Zeile, die nach einem Absturz länger als 10 Minuten in `processing` hängt, wird übernommen. |

`processed` ist ein **Endzustand**.

## Erstattung (`wpcap_refunds.status`)

| Von → nach | Auslöser |
|---|---|
| (neu) → `pending_approval` | Antrag über der Freigabeschwelle (`refund_approval_threshold`). |
| (neu) → `approved` | Antrag unter der Schwelle. |
| (neu) → `completed` | Externe Erstattung (`Refunds::record_external()`): Das Geld ist schon zurück. |
| `pending_approval` → `approved`/`rejected` | Zweite berechtigte Person (Vier-Augen-Prinzip). |
| `approved`/`failed` → `processing` | `Refunds::execute()` direkt vor dem Anbieter-Aufruf. |
| `approved`/`failed` → `failed` | Erneute Prüfung unter Sperre schlägt fehl, zum Beispiel weil die Tickets schon erstattet sind. |
| `processing` → `failed` | Der Anbieter lehnt ab; es wurde nichts ausgezahlt. Ein Neuversuch ist möglich. |
| `processing` → `completed` | Lokale Buchung nach der Auszahlung. Hängt sie, schließt Cron `repair_stuck()` sie ab, ohne ein zweites Mal auszuzahlen. |

`completed` und `rejected` sind **Endzustände**. Gutschriften (Credit Notes) entstehen nur für
`completed`.

## Gutschrift (`wpcap_credit_notes`)

Gutschriften haben keinen Status. Sie werden nur angelegt, nie geändert: höchstens eine je Erstattung
(`UNIQUE refund_id`), mit einer fortlaufenden Nummer (`UNIQUE credit_note_number`). Sie entstehen,
wenn die Erstattung `completed` ist und die Bestellung eine Rechnung hat. Ein doppeltes
`wpcap_refund_completed` erzeugt keine zweite Gutschrift.

## Warteliste (`wpcap_waitlist.status`)

`waiting` → `offered` → `accepted` → `paid`. Ein Angebot kann zurück auf `waiting` gehen, wenn es nicht
zugestellt werden konnte. Die Endzustände sind `expired`, `declined`, `skipped` und `removed`. Alle
Übergänge sind bedingte Updates auf Status und Angebots-Token. Details: [WAITLIST.md](WAITLIST.md).

## Job (`wpcap_jobs.status`)

| Von → nach | Auslöser |
|---|---|
| (neu) → `queued` | `Jobs::enqueue()`, idempotent je Schlüssel. |
| `queued` → `running` | Claim in `Jobs::process_due()`: Zeilensperre plus bedingtes Update, `locked_at` ist der Claim-Stempel. |
| `running` → `complete` | Ein Handler hat den Job bestätigt. Nur der Lauf, der den Claim noch hält (`status='running' AND locked_at=<eigener Stempel>`), darf ihn abschließen. |
| `running` → `queued` | Kein Handler hat bestätigt (Backoff), das Zeitbudget ist erschöpft, oder ein Claim ist nach 15 Minuten verwaist. |
| `running` → `dead_letter` | Nach 8 Versuchen. |
| `dead_letter` → `queued` | Admin: „Erneut versuchen“ (Operations → Warteschlangen). |

`complete` ist ein **Endzustand**.

## Umtausch (`wpcap_ticket_exchanges.status`)

| Von → nach | Auslöser |
|---|---|
| (neu) → `completed` | Umtausch ohne Aufpreis, direkt unter Sperre. |
| (neu) → `pending_payment` | Umtausch mit Aufpreis; eine Aufpreis-Bestellung reserviert das neue Kontingent. |
| `pending_payment` → `completed` | Aufpreis bezahlt (`wpcap_order_paid` → `Service::finalize()`). |
| `pending_payment` → `expired`/`cancelled` | Die Aufpreis-Bestellung ist abgelaufen oder storniert, das alte Ticket wurde widerrufen oder eingecheckt, oder ein neuer Umtausch hat diesen ersetzt. |
| `expired`/`cancelled` → `completed` | Der Aufpreis wurde doch noch bezahlt (späte Zahlung), und das alte Ticket ist noch gültig. |
| `pending_payment`/`expired`/`cancelled` → `needs_review` | Der Aufpreis ist bezahlt, aber das alte Ticket ist nicht mehr umtauschbar. Der Admin bekommt eine Mail. |

`completed` und `needs_review` sind **Endzustände**. Details: [EXCHANGE.md](EXCHANGE.md).

## Servicebuchung (`wpcap_service_bookings.status`)

Alle Übergänge laufen unter den Sperren aus `ServiceBookings::lock()` (Ressourcen → Leistungserbringer →
Zeitfenster → Buchung) und als bedingtes Update auf den erwarteten Ausgangsstatus.

| Von → nach | Auslöser |
|---|---|
| (neu) → `held` | `reserve()`: Zeitfenster mit Ablaufzeit, idempotent je Schlüssel; Kapazität, Puffer, Leistungserbringer, Buchungsschluss/-vorlauf und Höchstzahl je Person geprüft. |
| `held` → `expired` | Ablaufzeit überschritten (nachgezogen bei der nächsten Buchung des Zeitfensters). |
| `held` → `confirmed` | `confirm()` einer kostenlosen Buchung (Kund:in mit Buchungstoken oder Team). |
| `held` → `pending_payment` | `confirm()` einer kostenpflichtigen Buchung: Bestellung über den regulären Checkout, in derselben Transaktion verknüpft (nie zwei Bestellungen). |
| `pending_payment`/`held`/`expired` → `confirmed` | Bestellung bezahlt (`confirm_paid_order()`); bei verspäteter Zahlung nach erneuter Prüfung von Kapazität, Puffern und Leistungserbringern. |
| `pending_payment`/`held`/`expired` → `unallocated` | Bestellung bezahlt, das Zeitfenster ist inzwischen belegt; die Bestellung wird nach Ausstellung der Tickets automatisch erstattet (`refund_unallocated()`). |
| `held`/`pending_payment` → `expired` | Bestellung abgelaufen. |
| `held`/`pending_payment`/`confirmed` → `cancelled` | Bestellung storniert oder anderweitig erstattet; `cancel()` durch Kund:in (innerhalb der Stornofrist) oder Team, bezahlt mit voller Erstattung über `Refunds` (Quelle `service_booking`). |
| `confirmed` → `confirmed` (anderes Zeitfenster) | `rebook()`: atomar, beide Ressourcen und Zeitfenster gesperrt; innerhalb der Umbuchungsfrist, bei Bestellungen nur auf eine Ressource mit derselben Ticketart. |

**Endzustände:** `cancelled`, `expired` (eine abgelaufene Buchung wird nur noch durch eine verspätete Zahlung
`confirmed` oder `unallocated`) und `unallocated`. Fristen, Limits und Benachrichtigungen: OPERATIONS.md.

## Doppelte und in falscher Reihenfolge eintreffende Zahlungsereignisse

Die Adapter übersetzen Anbieter-Ereignisse in ein normalisiertes Ereignis (`paid`, `failed`,
`refunded`, `ignored`; Felder siehe `GatewayInterface::verify_webhook()`). `Webhooks::process()` wendet
es abhängig vom aktuellen Zahlungszustand der Bestellung an:

| Ereignis \ `payment_status` | `pending` | `expired` | `paid` | `paid_unallocated` | `cancelled` | `refunded` |
|---|---|---|---|---|---|---|
| `paid` (gleicher Zahlungsversuch) | `paid` | `paid` (späte Zahlung) oder `needs_review` | `already_paid` | `needs_review` (keine Änderung) | `paid_for_closed_order` + Admin-Mail | `paid_for_closed_order` + Admin-Mail |
| `paid` (anderer Zahlungsversuch) | `paid` | wie oben | `double_payment` + Admin-Mail | `double_payment` + Admin-Mail | `paid_for_closed_order` + Admin-Mail | `paid_for_closed_order` + Admin-Mail |
| `failed` | `cancelled` (älterer Versuch: `stale_attempt`) | `ignored` | `ignored` | `ignored` | `ignored` | `ignored` |
| `refunded` (Erstattung) | **5xx**: zurückgestellt, siehe unten | `refund_for_unpaid_order` | `partial_refund` oder `refunded` | voll: `refunded`; teilweise: `needs_review` + Admin-Mail | `refund_for_unpaid_order` | `refunded` (keine Änderung) |
| `refunded` mit `chargeback` | wie Erstattung | `refund_for_unpaid_order` | wie Erstattung, Grund `<anbieter>_chargeback` | wie Erstattung | `refund_for_unpaid_order` | `chargeback_after_refund` + Admin-Mail |
| gleiche Ereignis-ID erneut | `duplicate` | `duplicate` | `duplicate` | `duplicate` | `duplicate` | `duplicate` |

Zu einzelnen Fällen:

- **Ein Betrag passt nicht** zu Summe oder Währung der Bestellung: Ergebnis `amount_mismatch`, keine
  Tickets, Admin-Mail.
- **Erstattung vor Zahlung.** Das Ereignis wird nicht verbucht. Der Anbieter bekommt 5xx
  (`wpcap_refund_before_payment`) und stellt später erneut zu; bis dahin ist die Zahlung bestätigt.
  Stripe stellt bis zu 3 Tage erneut zu, PayPal bis zu 25 Mal.
  Mollie schickt keine Ereignisse, sondern nur die Zahlungs-ID; abgefragt wird der aktuelle Stand. Hat
  die Zahlung beim ersten Abruf schon eine Erstattung oder einen Chargeback, wird zuerst die Zahlung
  angewendet. Danach antwortet das Plugin mit 503 (`wpcap_webhook_redeliver`, Feld `redeliver`), und
  der erneute Aufruf verbucht die Erstattung.
- **Erstattung einer nie verbuchten Zahlung** (Bestellung `cancelled`/`expired`, zum Beispiel nach
  `paid_for_closed_order`): Es gibt keine Erstattungszeile, keine Gutschrift und keinen
  Statuswechsel. Das wird nur protokolliert (`refund_for_unpaid_order`).
- **Kumulative Beträge (Mollie).** Mollie meldet die Summe aller externen Erstattungen und Chargebacks
  einer Zahlung. Verbucht wird nur der noch nicht verbuchte Rest dieser Zahlung (`cumulative`). Ein
  älterer Stand nach einem neueren ergibt `already_booked`.
- **Chargebacks** werden wie eine externe Erstattung verbucht (Tickets widerrufen, Gutschrift):
  Stripe `charge.dispute.funds_withdrawn`, PayPal `PAYMENT.CAPTURE.REVERSED`, Mollie
  `_embedded.chargebacks`/`amountChargedBack` (ohne zurückgenommene). Ein Chargeback nach einer
  vollen Erstattung bucht nichts. Er wird dem Admin gemeldet, damit er beim Anbieter widerspricht.
- **Wettlauf zwischen Fehlschlag und Zahlung.** `failed` storniert nur, solange die Zahlung unter der
  Zeilensperre noch `pending` ist (`Orders::cancel_order( …, array( 'pending' ) )`).

Empfohlene Webhook-Ereignisse: Stripe `checkout.session.completed`,
`checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`,
`checkout.session.expired`, `charge.refunded`, `charge.dispute.funds_withdrawn`. PayPal
`CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`,
`PAYMENT.CAPTURE.DECLINED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`. Mollie setzt die
Webhook-URL je Zahlung selbst.

### WooCommerce

Bei WooCommerce bestimmt der Status der WooCommerce-Bestellung, was passiert:

| WooCommerce-Status | Pass-Captain-Bestellung |
|---|---|
| `processing`/`completed` (mehrfach) | Einmal `complete`, Tickets genau einmal. Bei Zahlung nach Lieferung erst bei `completed`. |
| `on-hold` | Die Reservierung wird verlängert. |
| `failed` | `cancelled`, aber nur solange unbezahlt. Nach der Zahlung bleiben die Tickets gültig, und die WooCommerce-Bestellung bekommt eine Notiz. |
| `cancelled` | `cancelled`, gültige Tickets werden widerrufen. |
| `processing` nach `cancelled` | Neue Reservierung und Ausstellung. Die Zahlung in WooCommerce gilt. |
| `refunded` vor einer Zahlung | `cancelled`, ohne Erstattung zu buchen. |
| `refunded` (mehrfach) | Einmal `refunded`. Teil-Erstattungen werden je WooCommerce-Erstattung genau einmal verbucht. |
| `processing` nach `refunded` | Nichts. `refunded` ist ein Endzustand; die Bestellung bekommt eine Notiz. |

Chargebacks kommen bei WooCommerce über das Zahlungs-Plugin an, als WooCommerce-Erstattung oder
Statuswechsel.

## Tests

`tests/integration/modules/zz_a184.php` spielt alle Fälle durch: Stripe, PayPal und Mollie über
`Webhooks::handle()` mit echter Adapter-Normalisierung, WooCommerce über die Status-Hooks. Die Fälle
sind doppeltes `paid`, zweiter Zahlungsversuch, Erstattung vor Zahlung, Zahlung nach Storno und deren
Erstattung, Zahlung nach Ablauf, Fehlschlag nach Zahlung, Chargeback nach voller Erstattung,
Chargeback einer bezahlten Bestellung, doppelte oder ältere Teil-Erstattung und `needs_review` mit
Wiederholungen. Der Test prüft außerdem, dass jeder Zustand aus `StateMachine::TRANSITIONS` hier
dokumentiert ist. Weitere Fälle stehen in `tests/integration/payments.php`, `modules/mollie.php` und
`modules/zz_integration.php`.
