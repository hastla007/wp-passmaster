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

1. **Angabe von Voucher Captain**: Das Vertragsfeld `treatment` hat Vorrang: `payment` = Mehrzweckgutschein, `single` = Einzweckgutschein, `discount` = Rabattcode (siehe unten). Sonst: Liefert das Angebot (`quote()`), die Reservierung oder beim Ticketdienst `$source` ein Feld `purpose`, `voucher_purpose`, `voucher_type`, `tax_type`, `vat_type` oder `tax_treatment` (auch verschachtelt unter `voucher`), wird es ausgewertet. Erkannt werden `single`/`multi`, `single_purpose`/`multi_purpose`, `SPV`/`MPV`, `Einzweck(gutschein)`/`Mehrzweck(gutschein)`, für Rabattcodes `discount`/`promotion`/`Rabatt(code)`/`Preisnachlass` sowie die Wahrheitswerte `single_purpose`, `is_single_purpose`, `multi_purpose`, `is_multi_purpose`. Andere Werte (z. B. `voucher_type: value`) werden ignoriert.
2. **Filter** `wpcap_voucher_purpose( $purpose, $voucher, $context )`: erhält die Angabe aus Schritt 1 (oder `null`) und kann sie festlegen oder überschreiben, z. B. nach Code-Präfix oder Veranstaltung.
3. **Einstellung** *Pass Captain → Einstellungen → Gutscheine → „Gutscheine standardmäßig als Mehrzweckgutschein behandeln“* (`voucher_default_purpose`, Standard `multi`).
4. **Prüfung des Einzweckgutscheins**: Ein Einzweckgutschein ist nur einer, wenn Ort der Leistung und geschuldete
   Steuer beim Verkauf feststehen (Art. 30a MwStSystRL). Liefert Voucher Captain zum Einzweckgutschein die
   Umsatzsteuer des Verkaufs (`sale_tax` = `rate_bp` in Basispunkten und `country`, im Angebot, in der Reservierung
   oder beim Ticketdienst in `$source`), gilt er nur für Tickets mit genau diesem Satz, deren Leistungsort im selben
   Land liegt (Land der Bestellzeile = Veranstaltungsland des Termins, sonst Land des Rechnungsstellers; Filter
   `wpcap_voucher_tax_country( $country, $context )`). Weicht der Satz einer Ticketposition oder das Land ab, wird
   die Einlösung als **Mehrzweckgutschein** (Zahlungsmittel, USt bei Einlösung) gebucht; die Bestellung vermerkt
   `purpose_changed` = `single_rate` bzw. `single_country`. Servicegebühr-Zeilen zählen nicht mit. Ohne `sale_tax`
   bleibt es bei der Angabe aus Schritt 1–3.

**Rabattcodes** (`discount`): Aktionscodes von Voucher Captain sind keine Gutscheine im Sinne des
Umsatzsteuerrechts (für sie wurde nichts bezahlt), sondern ein Preisnachlass. Sie mindern Preis und Umsatzsteuer wie
ein Einzweckgutschein, auf der Rechnung steht aber nur „Eingelöster Rabattcode …: als Preisnachlass berücksichtigt“ –
**nicht** „Umsatzsteuer bereits beim Verkauf abgerechnet“. Sie begründen keine Forderung gegen einen
Gutscheinverkäufer (keine Zeile in der Veranstalter-Abrechnung; `TicketService::settlement_value_minor()` = 0) und
werden wie Einzweckgutscheine abgelehnt, sobald ein Fiskalbeleg (möglicherweise) übermittelt wurde.

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

**Rechnung erst nach der Erstattung** (z. B. `auto_invoice` aus, Rechnung später angefordert): Die Rechnung weist wie immer das volle ursprüngliche Entgelt aus; jede schon abgeschlossene Erstattung ohne Korrekturbeleg erhält beim Ausstellen ihren Korrekturbeleg (`Invoices::issue_for_order()`, dieselben Beträge wie bei einer Erstattung nach der Rechnung, beim Mehrzweckgutschein Bar- und Gutscheinteil mit voller USt). Rechnung minus Korrekturen ergibt das Entgelt nach den Erstattungen. Eine Rechnung über den verminderten Betrag gibt es bewusst nicht: Rechnungen sind unveränderlich, und Erstattungen werden immer über Korrekturbelege abgebildet – so bleiben Belegexport, Berichte und Erstattungen nach der Rechnung einheitlich.

**Berichte und Abrechnungen** (Veranstalter- und Wiederverkäufer-Abrechnungen) lesen die Erstattungsbeträge aus dem gespeicherten Plan (`refund_plans`, `Checkout::refund_amounts()`), nicht aus der reinen Barerstattung:

- **Mehrzweckgutschein**: Verkäufe zählen mit dem vollen Entgelt (bar + Gutschein); Erstattungen ziehen Bar- **und** Gutscheinteil mit der vollen USt ab – dieselben Zahlen wie die Rechnungskorrektur (`Checkout::credit_note_amounts()`). Im Beispiel sinkt der Umsatz um 10,00 € (1,67 € USt), nicht nur um 5,00 €.
- **Einzweckgutschein**: Verkauf und Erstattung zählen jeweils nur den Betrag nach dem Gutschein; die Veranstalter-Abrechnung bucht den zurückgegebenen Gutscheinteil als eigene Zeile „Einzweckgutschein zurückgegeben (Erstattung)“ gegen die Forderung gegen den Gutscheinverkäufer.


## Ticketdienst (Gutschein → Tickets)

- **Mehrzweckgutschein**: Die Bestellung enthält die Tickets zum Listenpreis – oder, wenn Voucher Captain `amount_minor` (eingelöster Gutscheinwert) übergibt, zu diesem Betrag als Entgelt – mit voller USt. Der offene Betrag ist 0, `amount_paid_by_voucher` = Bruttobetrag. Rechnungen zeigen den Gutschein als Zahlung; Berichte und Abrechnungen den Umsatz.
- **Einzweckgutschein**: Tickets zu 0 € / 0 € USt (die Steuer wurde beim Gutscheinverkauf abgeführt). Der Gutscheinwert (`amount_minor`/`value_minor` aus `$source`, sonst Listenpreis × Menge) steht im Snapshot unter `face_value_minor`; `TicketService::settlement_value_minor( $order_id )` liefert ihn für Veranstalter-Abrechnungen.
- Für diese Bestellungen belastet Voucher Captain den Gutschein selbst (Snapshot-Status `external`); Pass Captain bucht bei Erstattungen nichts automatisch auf den Gutschein zurück – der Widerruf läuft über `revoke_tickets()`.

## Abgleich für das Gutscheinsystem (`Reconciliation`, Vertrag v1)

`PassCaptain\Integrations\Vouchers\Reconciliation::orders( array $args )` listet – nur lesend – die Bestellungen mit Gutschein: im Ticket-Checkout (teilweise) per Gutschein bezahlte und über den Ticketdienst umgewandelte. Voucher Captain gleicht damit seine Einlösungen ab, ohne die Tabellen von Pass Captain zu lesen.

- **Version**: `Reconciliation::VERSION` (seit 1.6.0, Erweiterungsvertrag 1.4.0: `1`). Vor dem Aufruf prüfen: `defined( 'PassCaptain\Integrations\Vouchers\Reconciliation::VERSION' ) && Reconciliation::VERSION >= 1`. Spätere Versionen fügen nur Felder hinzu; eine geänderte Bedeutung bekommt eine neue Version.
- **Argumente**: `limit` (1–500, Standard 100), `order` (`asc` Standard, `desc` = neueste zuerst), `after_id` / `before_id` (Cursor: `order_id` der letzten Zeile übergeben), `redemption_key` (nur Bestellungen mit genau diesem Einlöse-Schlüssel, Checkout oder Ticketdienst; keine Platzhalter).
- **Zeilen**: `order_id`, `public_id`, `status`, `payment_status`, `payment_provider`, `provider_reference`, `currency`, `exchange_key` (Einlöse-Schlüssel einer Ticketdienst-Bestellung, sonst leer), `voucher` (`null` oder: `source` `checkout`/`ticket_service`, `redemption_key` (Checkout: wie von Voucher Captain bei `commit()`/`reserve()` geliefert), `status`, `purpose` (`single`/`multi`/`discount`), `purpose_changed`, `amount_minor`, `amount_paid_by_voucher`, `refunded_minor` – wie im Snapshot gespeichert), `created_at`, `updated_at` (UTC, `Y-m-d H:i:s`). Angebot, Reservierung und Erstattungspläne des Snapshots bleiben intern.
- **Rechte**: PHP-API ohne eigene Rechteprüfung; der Aufrufer zeigt die Daten nur berechtigten Nutzern (Voucher Captain: Berichtsrecht).

Beispiel (alle Bestellungen seitenweise):

```php
use PassCaptain\Integrations\Vouchers\Reconciliation;
$after = 0;
do {
	$rows = Reconciliation::orders( array( 'after_id' => $after, 'limit' => 500 ) );
	foreach ( $rows as $row ) { /* vergleichen */ }
	$after = $rows ? end( $rows )['order_id'] : 0;
} while ( count( $rows ) === 500 );
```

## Pflichten des Betreibers

- **Art festlegen**: Für jede Gutscheinart prüfen, ob Ort der Leistung und Steuersatz beim Verkauf feststehen. Veranstaltungen in mehreren Ländern oder mit unterschiedlichen Steuersätzen sprechen für Mehrzweckgutscheine. Die Art möglichst in Voucher Captain hinterlegen; die Standardeinstellung ist nur der Rückfall.
- **Umgebuchte Einzweckgutscheine** (`purpose_changed`): Wurde ein als Einzweckgutschein verkaufter Gutschein für Tickets mit anderem Steuersatz oder Leistungsort eingelöst, war er umsatzsteuerlich von Anfang an ein Mehrzweckgutschein. Pass Captain versteuert dann die Einlösung; die beim Gutscheinverkauf ausgewiesene Umsatzsteuer muss der Gutscheinverkäufer berichtigen (Rechnung über den Gutschein korrigieren). Das klärt der Betreiber mit der Steuerberatung.
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

### Gleichzeitige Vorgänge

Der Voucher-Snapshot wird nur mit Versionsprüfung geschrieben (Vergleich mit dem gelesenen Stand im selben `UPDATE`, Zähler `rev`); ein Schreibvorgang auf veraltetem Stand wird verworfen und mit dem aktuellen Stand wiederholt. Anbieteraufrufe laufen nie in einer Datenbanktransaktion; stattdessen „beansprucht“ der Vorgang den Snapshot (`claim`: Vorgang, Token, Zeit), bevor er Voucher Captain aufruft:

- **Belasten, Freigeben, erneut Reservieren** schließen sich gegenseitig aus. Die Freigabe beansprucht den Snapshot im selben atomaren `UPDATE` mit der Bedingung „Bestellung nicht bezahlt“.
- **Ablauf gegen späte Zahlung**: Trifft die Zahlung ein, während der Ablauf den Gutschein freigibt, werden die Tickets ausgestellt, die Belastung wartet (Job `voucher_commit`, eine Minute) und reserviert den freigegebenen Gutschein danach neu und belastet ihn genau einmal. Eine Freigabe nach einer Belastung gibt es nicht.
- **Erstattungen**: Der Gutscheinanteil wird je Erstattung genau einmal verbucht, auch wenn Hook und Wiederholungsjob gleichzeitig laufen (der Anbieter erstattet je Schlüssel einmal). Der Erstattungsplan (`refund_plans`) wird mit Versionsprüfung gespeichert; der zuerst gespeicherte Plan einer Erstattung gilt.
- Ein abgebrochener Vorgang (Absturz) blockiert nicht dauerhaft: Ein `claim` älter als 120 Sekunden (`Checkout::CLAIM_TTL`) wird vom nächsten Versuch übernommen.

## Gutschein-Abgleich

Abschnitt „Gutschein-Abgleich“ auf der Seite **Betrieb** (Systemstatus), auf Knopfdruck, nur lesend (`ReconciliationReport::report()`): Zusammenfassung je Snapshot-Status (Anzahl, eingelöster und zurückgebuchter Betrag) und Abweichungskandidaten je Bestellung:

| Code | Bedeutung |
|---|---|
| `reserved_paid` | Bezahlt, Gutschein nur reserviert (Belastung steht aus oder hängt) |
| `reserved_closed` | Bestellung beendet, Reservierung nicht freigegeben |
| `reserved_stale` | Unbezahlt und älter als 24 Stunden, Reservierung offen |
| `paid_released` | Bezahlt, Gutschein freigegeben – Rabatt ohne Belastung |
| `commit_failed` | Belastung endgültig fehlgeschlagen |
| `committed_unpaid` | Gutschein belastet, Bestellung nicht bezahlt |
| `reversal_failed` | Gutschein-Rückbuchung fehlgeschlagen (`failed_refund_ids`) |
| `reversal_open` | Abgeschlossene Erstattung mit Gutscheinanteil, noch nicht zurückgebucht |
| `reversal_waiting` | Rückbuchung wartet auf die Belastung (`pending_refund_ids`) |
| `refund_over` | Mehr zurückgebucht als eingelöst |
| `claim_stale` | Abgebrochener Vorgang (`claim` abgelaufen) |
| `provider_missing`, `provider_status`, `provider_refunded` | Nur mit Daten von Voucher Captain: dort unbekannt, anderer Status, anderer Rückbuchungsbetrag |

Der Anbieter-Vertrag v1 hat keine Abfrage einzelner Einlösungen. Voucher Captains Stand wird deshalb nur verglichen, wenn jemand den optionalen Filter `wpcap_voucher_reconcile_states` beantwortet (siehe [HOOKS.md](HOOKS.md)); sonst zeigt der Abgleich die Pass-Captain-Sicht und die daraus erkennbaren Kandidaten. Bestellungen des Ticketdienstes (`external`) werden nur auf Pass-Captain-Seite geprüft.

## Technische Felder (Voucher-Snapshot `orders.voucher_snapshot`)

| Feld | Bedeutung |
|---|---|
| `purpose` | `single`, `multi` oder `discount` (Rabattcode) |
| `purpose_changed` | `single_rate` / `single_country`: als Einzweckgutschein gemeldet, wegen abweichender USt des Verkaufs als Mehrzweckgutschein gebucht |
| `amount_minor` | Eingelöster Gutscheinbetrag (Ticketdienst/Einzweck: Gutscheinwert) |
| `amount_paid_by_voucher` | Mit Mehrzweckgutschein bezahlter Betrag (Einzweck: 0) |
| `gross_minor`, `gross_tax_minor` | Bruttobetrag und USt der Bestellung vor der Einlösung |
| `amount_due_minor` | Offener Betrag für den Zahlungsanbieter (= `orders.total_minor`) |
| `face_value_minor`, `face_value_source` | Einzweck über den Ticketdienst: Gutscheinwert für Abrechnungen (`voucher` oder `list_price`) |
| `status` | `reserved`, `committed`, `released`, `commit_failed`, `external` (Ticketdienst) |
| `voucher_lines` | Aufteilung des Gutscheins je Ticketart (anteilig zum Zeilenbetrag nach Kampagnenrabatten); Grundlage des Gutscheinteils von Erstattungen |
| `refund_plans` | Je Erstattung: `voucher_minor`, `cash_minor`, `tax_minor` (volle USt, nur Mehrzweck), `tickets` |
| `refund_ids`, `failed_refund_ids`, `pending_refund_ids` | Erstattungen mit verbuchtem, fehlgeschlagenem bzw. auf die Belastung wartendem Gutscheinanteil |
| `rev` | Versionszähler, steigt mit jedem Schreibvorgang (Versionsprüfung) |
| `claim` | Laufender Anbietervorgang (`op`: `commit`, `release`, `reserve`; `token`; `at`), siehe „Gleichzeitige Vorgänge“ |

Hilfsfunktionen: `Checkout::purpose()`, `Checkout::voucher_payment_minor( $order )`, `Checkout::gross_minor( $order )` (offener Betrag + Gutscheinzahlung), `Checkout::invoice_payments( $order )`, `Checkout::credit_note_amounts( $refund_id )`, `Invoices::payment_summary( $invoice, $snapshot )`.

## Vertrag und Fehlerverhalten

Versionsregel, optionale Methode `contract_version()` des Anbieters, Pflicht- und optionale Felder sowie das Verhalten bei Ausnahmen, Timeouts und ungültigen Antworten (kein halb gebuchter Zustand, Freigabe über den Idempotenzschlüssel) stehen in [CONTRACTS.md](CONTRACTS.md).

## Wartelistenangebote

Seit der Entscheidung vom 25.11.2026 lassen sich Gutscheine auch bei der Annahme eines bezahlten Wartelistenangebots
einlösen (Feld „Gutschein- oder Rabattcode“ der Angebotsseite, REST `voucher_code` bzw. `code`). Der Ablauf ist derselbe
wie im Checkout (`Checkout::apply()` nach der Annahme, Commit nach der Zahlung, Freigabe bei Ablauf/Ablehnung,
anteilige Rückgabe bei Erstattung); scheitert die Annahme danach, wird die Reservierung freigegeben und das Angebot
ist wieder zum Angebotspreis offen. Details: docs/WAITLIST.md.
