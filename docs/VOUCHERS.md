# Gutscheine: Einzweck- und Mehrzweckgutscheine

Pass Captain löst Gutscheine von **Voucher Captain** an zwei Stellen ein:

- **Checkout** (`src/Integrations/Vouchers/Checkout.php`): Der Kunde gibt im Ticket-Checkout einen Gutscheincode ein; der Gutschein deckt einen Teil oder den ganzen Betrag.
- **Ticketdienst** (`src/Integrations/Vouchers/TicketService.php`, Filter `wp_pass_captain_ticket_service_v1`): Voucher Captain tauscht einen Gutschein direkt in Tickets um („Gutschein für 2 Tickets“).

Wie die Einlösung umsatzsteuerlich behandelt wird, hängt von der **Art des Gutscheins** ab (EU-Mehrwertsteuer-Systemrichtlinie Art. 30a/30b, Deutschland § 3 Abs. 13–15 UStG, Österreich § 4 Abs. 9 UStG).

> Diese Seite beschreibt das Verhalten der Software. Ob ein Gutschein ein Einzweck- oder Mehrzweckgutschein ist, entscheidet der Betreiber – im Zweifel mit der Steuerberatung.

## Die beiden Arten

| | Einzweckgutschein (`single`) | Mehrzweckgutschein (`multi`, Standard) |
|---|---|---|
| Definition | Ort der Leistung **und** geschuldete Umsatzsteuer stehen beim Verkauf des Gutscheins fest (z. B. „1 Ticket für das Konzert am 12.12. in Wien“ eines österreichischen Veranstalters). | Alles andere, insbesondere Wertgutscheine („50 € für alle Veranstaltungen“), Gutscheine für Leistungen mit unterschiedlichen Steuersätzen oder Ländern. |
| Umsatzsteuer | Beim **Verkauf des Gutscheins** (durch den Gutscheinverkäufer). | Bei der **Einlösung**, auf das volle Entgelt. Der Gutscheinverkauf selbst ist nicht steuerbar. |
| Einlösung in Pass Captain | **Preisnachlass**: Rechnungsbetrag und Umsatzsteuer werden gemindert (Rabattspalte). | **Zahlungsmittel**: Ticketpreis und Umsatzsteuer bleiben voll; der Gutschein steht auf der Rechnung unter „Zahlungen“. |
| Ticketdienst | Tickets zu 0 € / 0 € USt; der Gutscheinwert wird für Abrechnungen gespeichert. | Tickets zum vollen Preis mit voller USt, bezahlt durch den Gutschein. |

## Wie die Art bestimmt wird

1. **Angabe von Voucher Captain**: Das Vertragsfeld `treatment` hat Vorrang: `payment` = Mehrzweckgutschein, `single` oder `discount` = Preisminderung (Einzweck). Sonst: Liefert das Angebot (`quote()`), die Reservierung oder beim Ticketdienst `$source` ein Feld `purpose`, `voucher_purpose`, `voucher_type`, `tax_type`, `vat_type` oder `tax_treatment` (auch verschachtelt unter `voucher`), wird es ausgewertet. Erkannt werden `single`/`multi`, `single_purpose`/`multi_purpose`, `SPV`/`MPV`, `Einzweck(gutschein)`/`Mehrzweck(gutschein)` sowie die Wahrheitswerte `single_purpose`, `is_single_purpose`, `multi_purpose`, `is_multi_purpose`. Andere Werte (z. B. `voucher_type: value`) werden ignoriert.
2. **Filter** `wpcap_voucher_purpose( $purpose, $voucher, $context )`: erhält die Angabe aus Schritt 1 (oder `null`) und kann sie festlegen oder überschreiben, z. B. nach Code-Präfix oder Veranstaltung.
3. **Einstellung** *Pass Captain → Einstellungen → Gutscheine → „Gutscheine standardmäßig als Mehrzweckgutschein behandeln“* (`voucher_default_purpose`, Standard `multi`).

Die ermittelte Art wird in der Bestellung gespeichert (`orders.voucher_snapshot`, Feld `purpose`) und gilt für Rechnung, Erstattungen und Abrechnung dieser Bestellung. Bestellungen aus älteren Versionen ohne `purpose` werden weiter als Einzweckgutschein (Preisnachlass) behandelt – so wurden sie gebucht.

```php
// Beispiel: Gutscheine, die im Ticketdienst in Tickets der Ticketart 42 (ein bestimmtes Inlandskonzert)
// getauscht werden, sind Einzweckgutscheine; alles andere folgt Voucher Captain bzw. der Einstellung.
add_filter( 'wpcap_voucher_purpose', function ( $purpose, $voucher, $context ) {
	if ( 'ticket_service' === ( $context['source'] ?? '' ) && 42 === (int) ( $context['ticket_type_id'] ?? 0 ) ) {
		return 'single';
	}
	return $purpose;
}, 10, 3 );
```

## Beispiel: 3 Tickets à 10,00 € (20 % USt), Gutschein über 15,00 €

### Mehrzweckgutschein

| | Betrag |
|---|---|
| 3 × Ticket (brutto) | 30,00 € |
| darin Umsatzsteuer 20 % | 5,00 € |
| **Rechnungsbetrag** | **30,00 €** |
| *Zahlungen:* Bezahlt mit Gutschein …1234 (Mehrzweckgutschein) | −15,00 € |
| Restbetrag (bezahlt) | 15,00 € |

- Der Zahlungsanbieter (Stripe, PayPal, Mollie, Überweisung) erhält nur **15,00 €** (offener Betrag).
- Gespeichert: `orders.tax_minor` = 500, Zeilen-USt unverändert, `discount_minor` unverändert, `orders.total_minor` = 1500 (**offener Betrag**, den alle Zahlungsanbieter, Webhook-Betragsprüfungen und Erstattungsgrenzen verwenden); im Voucher-Snapshot `amount_paid_by_voucher` = 1500, `gross_minor` = 3000, `amount_due_minor` = 1500.
- Berichte und Veranstalter-Abrechnungen rechnen mit den Bestellzeilen (Zwischensumme − Rabatt) und zeigen daher den vollen Ticketumsatz von 30,00 € inkl. 5,00 € USt.
- Die Rechnung weist die volle Umsatzsteuer aus; der Abschnitt **„Zahlungen“** (HTML-Druckansicht und PDF) nennt den Gutschein mit maskiertem Code (nur die letzten vier Zeichen) und den bezahlten Restbetrag.

### Einzweckgutschein

| | Betrag |
|---|---|
| 3 × Ticket, Rabatt 15,00 € | 15,00 € |
| darin Umsatzsteuer 20 % | 2,50 € |
| **Rechnungsbetrag** | **15,00 €** |

Die Rechnung enthält einen Hinweis „Eingelöster Einzweckgutschein …1234: Die Umsatzsteuer wurde bereits beim Verkauf des Gutscheins abgerechnet …“. Die 2,50 € USt betreffen nur den bar bezahlten Teil.

Der Gutschein wird auf die Bestellzeilen aufgeteilt (anteilig zu ihrem Betrag nach Kampagnenrabatten, `voucher_lines` im Voucher-Snapshot) und beim Einzweckgutschein zum Zeilenrabatt addiert (`price_snapshot.line_discounts`, `discount_minor`); die Zeilen-USt wird auf dem verminderten Zeilenbetrag neu berechnet. Rechnung, Berichte, Veranstalter-Abrechnung, Erstattungen und der Fiskalbeleg verwenden damit die Beträge nach dem Gutschein, auch zusammen mit Kampagnencodes.

**Fiskalbeleg**: Der Beleg entsteht schon beim Anlegen der Bestellung. Solange er noch nicht übermittelt wurde (Status `pending`/`configuration_required`, kein Versuch), werden seine Positionen, Summen und der Snapshot beim Einlösen eines Einzweckgutscheins neu berechnet. Wurde er bereits (möglicherweise) übermittelt, wird der Einzweckgutschein abgelehnt (`wpcap_voucher_fiscal_locked`) – ein übermittelter Beleg kann nicht mehr geändert werden. Mehrzweckgutscheine ändern den Preis nicht und sind davon nicht betroffen.

## Erstattungen

Erstattungen werden **anteilig** aufgeteilt:

- **Barteil**: aus `Refunds::calculate()`: der bezahlte Wert der erstatteten Tickets (Zeilenbetrag nach Rabatten), skaliert auf den offenen Betrag (beim Mehrzweckgutschein der bar bezahlte Anteil), über den Zahlungsanbieter.
- **Gutscheinteil**: der Anteil der erstatteten Tickets an der Gutschein-Aufteilung `voucher_lines` (`Checkout::refund_plan()`); die Erstattung, mit der die letzten Tickets erstattet werden, erhält den Rest. Bar- und Gutscheinteil zusammen ergeben höchstens den bezahlten Wert der Tickets, auch mit Kampagnenrabatt. Er wird über `ProviderInterface::refund()` auf den Gutschein zurückgebucht (Schlüssel `wpcap-refund-{id}`, idempotent).
- **Rechnungskorrektur** (Mehrzweckgutschein): umfasst Bar- **und** Gutscheinteil sowie die volle Umsatzsteuer der erstatteten Tickets; alle Korrekturen zusammen ergeben genau die Rechnung. Beim Einzweckgutschein entspricht die Korrektur wie bisher der Barerstattung.

Beispiel oben, ein Ticket wird erstattet: 5,00 € an den Zahlungsanbieter, 5,00 € zurück auf den Gutschein, Rechnungskorrektur über 10,00 € (darin 1,67 € USt). Bei vollständig per Gutschein bezahlten Bestellungen erstattet der Zahlungsanbieter 0 € und der ganze Wert geht auf den Gutschein zurück.

Reine Betragserstattungen (ohne Tickets) werden nur aus dem Barteil gezahlt.

**Veranstalter-Abrechnung**: Verkäufe zählen beim Mehrzweckgutschein mit dem vollen Entgelt (bar + Gutschein); Erstattungen ziehen daher die Beträge der Rechnungskorrektur ab (Bar- **und** Gutscheinteil, `Checkout::credit_note_amounts()`). Beim Einzweckgutschein zählen Verkauf und Erstattung jeweils nur den Betrag nach dem Gutschein.

Berichte, Veranstalter-Abrechnungen und Wiederverkäufer-Abrechnungen lesen die Erstattungsbeträge aus dem gespeicherten Plan (`refund_plans`, `Checkout::refund_amounts()`), nicht aus der reinen Barerstattung: Beim Mehrzweckgutschein zählt die Erstattung Bar- und Gutscheinteil mit der vollen USt (dieselben Zahlen wie die Rechnungskorrektur); im Beispiel sinkt der Umsatz um 10,00 € (1,67 € USt), nicht nur um 5,00 €. Beim Einzweckgutschein bucht die Veranstalter-Abrechnung den zurückgegebenen Gutscheinteil als eigene Zeile „Einzweckgutschein zurückgegeben (Erstattung)“ gegen die Forderung gegen den Gutscheinverkäufer.

<!-- MERGE-CHECK: beide Absätze stammen aus den zwei Entwicklungssträngen (credit_note_amounts() vs. refund_amounts()); nach dem Zusammenführen von src/Integrations/Vouchers/Checkout.php und den Abrechnungen auf das tatsächliche Verhalten angleichen. -->

## Ticketdienst (Gutschein → Tickets)

- **Mehrzweckgutschein**: Die Bestellung enthält die Tickets zum Listenpreis – oder, wenn Voucher Captain `amount_minor` (eingelöster Gutscheinwert) übergibt, zu diesem Betrag als Entgelt – mit voller USt. Der offene Betrag ist 0, `amount_paid_by_voucher` = Bruttobetrag. Rechnungen zeigen den Gutschein als Zahlung; Berichte und Abrechnungen den Umsatz.
- **Einzweckgutschein**: Tickets zu 0 € / 0 € USt (die Steuer wurde beim Gutscheinverkauf abgeführt). Der Gutscheinwert (`amount_minor`/`value_minor` aus `$source`, sonst Listenpreis × Menge) steht im Snapshot unter `face_value_minor`; `TicketService::settlement_value_minor( $order_id )` liefert ihn für Veranstalter-Abrechnungen.
- Für diese Bestellungen belastet Voucher Captain den Gutschein selbst (Snapshot-Status `external`); Pass Captain bucht bei Erstattungen nichts automatisch auf den Gutschein zurück – der Widerruf läuft über `revoke_tickets()`.

## Pflichten des Betreibers

- **Art festlegen**: Für jede Gutscheinart prüfen, ob Ort der Leistung und Steuersatz beim Verkauf feststehen. Veranstaltungen in mehreren Ländern oder mit unterschiedlichen Steuersätzen sprechen für Mehrzweckgutscheine. Die Art möglichst in Voucher Captain hinterlegen; die Standardeinstellung ist nur der Rückfall.
- **Gutscheinverkauf buchen**: Einzweckgutscheine sind beim Verkauf umsatzsteuerpflichtig (Rechnung über den Gutschein mit USt); Mehrzweckgutscheine nicht (Beleg ohne USt-Ausweis, z. B. als Zahlungsmittel/Anzahlung).
- **Einlösung buchen**: Mehrzweckgutscheine erst bei der Einlösung als Umsatz versteuern (Rechnung von Pass Captain); den Gutscheinbetrag gegen das Gutschein-Verbindlichkeitskonto verbuchen. Einzweckgutscheine: keine erneute Umsatzsteuer.
- **Verfallene Gutscheine**: Nicht eingelöste Mehrzweckgutscheine sind in der Regel kein steuerbarer Umsatz; Einzweckgutscheine wurden bereits versteuert. Die Behandlung klärt der Betreiber mit der Steuerberatung.
- **Abrechnung mit Veranstaltern**: Bei Einzweckgutscheinen, die über den Ticketdienst eingelöst werden, enthalten die Bestellzeilen 0 €; die Veranstalter-Abrechnung führt den gespeicherten Gutscheinwert (`face_value_minor`) als Zeile „Einzweckgutschein (Forderung gegen den Gutscheinverkäufer)“ ohne USt und kehrt ihn bei Erstattungen anteilig (nach Ticketanzahl) wieder um. Die Forderung zwischen Gutscheinverkäufer und Plattform ist gesondert auszugleichen.
- **Bestehende Bestellungen** werden nicht umgebucht.

## Abgelaufene Bestellungen und verspätete Zahlungen (A632)

Läuft eine unbezahlte Bestellung ab oder wird sie storniert, wird die Gutscheinreservierung freigegeben (`status` `released`); der offene Betrag (`orders.total_minor`) behält den Gutscheinabzug, die Bestellansicht weist darauf hin und nennt den Betrag ohne Gutschein. Kommt danach doch eine Zahlung (Webhook, PayPal-/Mollie-Rückkehr, manuelle Bestätigung, „Zuteilung erneut versuchen“):

1. Vor der Buchung und außerhalb jeder Transaktion (`wpcap_before_payment_confirm`) wird der Gutschein mit dem gespeicherten Angebot erneut reserviert (Schlüssel `wpcap-order-late-{public_id}`, bei jeder weiteren Runde mit Zähler `-2`, `-3` …; Snapshot `late_reservation`, `late_rounds`).
2. Gelingt das nicht (Gutschein verbraucht, storniert, anderer Betrag, Voucher Captain nicht erreichbar), bleibt der Snapshot `released` und die Prüfung der späten Zahlung (`wpcap_order_recovering`) leitet die Bestellung auf `needs_review`/`paid_unallocated` (Grund `voucher_released`): **keine Tickets**, Admin-Mail, mit `late_payment_auto_refund` automatische Erstattung. Ein Fehlschlag wird als `voucher_reserve_again_failed` protokolliert.
3. Scheitert die späte Zahlung aus einem anderen Grund (z. B. ausverkauft), wird ein erneut reservierter Gutschein wieder freigegeben; ein späteres „Zuteilung erneut versuchen“ reserviert ihn neu.

Erst nach dem Bezahlt-Setzen wird der Gutschein wie gewohnt belastet (`commit`). Ein Gutschein, den Voucher Captain selbst (TTL) freigegeben hat, wird wie bisher beim Belasten erneut reserviert.

## Technische Felder (Voucher-Snapshot `orders.voucher_snapshot`)

| Feld | Bedeutung |
|---|---|
| `purpose` | `single` oder `multi` |
| `amount_minor` | Eingelöster Gutscheinbetrag (Ticketdienst/Einzweck: Gutscheinwert) |
| `amount_paid_by_voucher` | Mit Mehrzweckgutschein bezahlter Betrag (Einzweck: 0) |
| `gross_minor`, `gross_tax_minor` | Bruttobetrag und USt der Bestellung vor der Einlösung |
| `amount_due_minor` | Offener Betrag für den Zahlungsanbieter (= `orders.total_minor`) |
| `face_value_minor`, `face_value_source` | Einzweck über den Ticketdienst: Gutscheinwert für Abrechnungen (`voucher` oder `list_price`) |
| `status` | `reserved`, `committed`, `released`, `commit_failed`, `external` (Ticketdienst) |
| `voucher_lines` | Aufteilung des Gutscheins je Ticketart (anteilig zum Zeilenbetrag nach Kampagnenrabatten); Grundlage des Gutscheinteils von Erstattungen |
| `refund_plans` | Je Erstattung: `voucher_minor`, `cash_minor`, `tax_minor` (volle USt, nur Mehrzweck), `tickets` |

Hilfsfunktionen: `Checkout::purpose()`, `Checkout::voucher_payment_minor( $order )`, `Checkout::gross_minor( $order )` (offener Betrag + Gutscheinzahlung), `Checkout::invoice_payments( $order )`, `Checkout::credit_note_amounts( $refund_id )`, `Invoices::payment_summary( $invoice, $snapshot )`.

## Vertrag und Fehlerverhalten

Versionsregel, optionale Methode `contract_version()` des Anbieters, Pflicht- und optionale Felder sowie das Verhalten bei Ausnahmen, Timeouts und ungültigen Antworten (kein halb gebuchter Zustand, Freigabe über den Idempotenzschlüssel) stehen in [CONTRACTS.md](CONTRACTS.md).
