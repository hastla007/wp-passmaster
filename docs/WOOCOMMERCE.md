# WooCommerce-Adapter

Der Adapter ist optional und wird nur geladen, wenn WooCommerce aktiv ist. Er nutzt ausschließlich die CRUD-APIs von WooCommerce (`wc_get_order()`, Bestell-/Produktmetadaten) und ist mit HPOS und dem klassischen Bestellspeicher sowie mit Cart/Checkout Blocks kompatibel deklariert. Pass Captain greift nie direkt auf WooCommerce-Tabellen zu.

## Einrichtung

1. In Pass Captain Event, Termin und Ticketkategorie mit Kontingent anlegen.
2. In WooCommerce ein (virtuelles) Produkt anlegen und im Tab „Allgemein“ unter **Pass Captain Ticketkategorie** die Kategorie wählen. Bei variablen Produkten erben Varianten die Kategorie des Elternprodukts.
3. Den Preis in WooCommerce pflegen – oder unter **WooCommerce → Einstellungen → Produkte → Pass Captain** „Pass-Captain-Preise (z. B. Serien-Preisstufen) im WooCommerce-Warenkorb verwenden“ aktivieren (siehe unten). Kontingent, Verkaufsfenster und Höchstmenge je Bestellung kommen aus Pass Captain. Die WooCommerce-Lagerverwaltung für Ticketprodukte nicht zusätzlich aktivieren, damit es nur eine Quelle für das Kontingent gibt.

## Ablauf

| WooCommerce | Pass Captain |
|---|---|
| In den Warenkorb / Menge ändern / Kasse | Prüfung von Verkaufsstatus, Verkaufsfenster, Höchstmenge, Restkontingent und – bei Saalplänen – freien passenden Plätzen (nicht sperrend). Im Warenkorb-Block (Store API) wird die Höchstmenge der Mengenauswahl begrenzt und `update-item` über dem Kontingent abgelehnt |
| Bestellung angelegt, vor der Zahlung (`woocommerce_checkout_order_processed`, `woocommerce_store_api_checkout_order_processed`) | Bestellung mit Reservierung anlegen (atomar, mit Zeilensperren). Kein Kontingent → Checkout bricht vor der Zahlung ab |
| Erneuter Checkout derselben offenen Bestellung | vorherige unbezahlte Reservierung wird storniert, neue angelegt |
| `processing` / `completed` / `payment_complete` | Reservierung umwandeln, je Einheit genau ein Ticket ausstellen, Tickets und persönlichen Bestelllink mailen |
| `processing` bei Nachnahme (`cod`) | noch keine Tickets; Reservierung auf 14 Tage verlängern (Filter `wpcap_woocommerce_deferred_hold_minutes`). Ausgestellt wird erst bei `completed`. Liste der Zahlarten: Filter `wpcap_woocommerce_issue_on_completed_gateways` (Standard `array( 'cod' )`) |
| Zahlung nach Ablauf der Reservierung | Kontingent erneut prüfen; reicht es nicht, wird die Bestellung `needs_review` / `paid_unallocated`, es werden keine Tickets ausgestellt und der Admin wird per E-Mail informiert |
| `on-hold` (z. B. Überweisung) | Reservierung auf 3 Tage verlängern (Filter `wpcap_woocommerce_on_hold_minutes`) |
| `failed` / `cancelled` | Reservierung freigeben, gültige Tickets widerrufen. Kommt später doch eine Zahlung, wird neu reserviert |
| Erstattung (`woocommerce_order_refunded`) | je WooCommerce-Erstattung genau einmal: Erstattung im Pass-Captain-Erstattungsjournal buchen (Zeile in `wpcap_refunds`, erstatteter Betrag der Bestellung, Tickets als erstattet markiert und – sofern gültig – widerrufen, Kontingent frei, Gutschrift, `wpcap_refund_completed`). Mengen wählen die Tickets (gültige zuerst, eingecheckte nur wenn erlaubt), der Betrag ist der Brutto-Erstattungsbetrag der Ticketpositionen; reine Betragserstattungen werden anteilig den Tickets zugerechnet. Aufgeteilte Bestellungen: je Rechnungssteller, siehe „Mehrere Rechnungssteller im Warenkorb“. Benötigt `Refunds::record_external()` im Kern; ohne diese Methode werden wie bisher nur die erstatteten Tickets widerrufen |
| `refunded` | offene WooCommerce-Erstattungen zuerst buchen (WooCommerce setzt den Status vor `woocommerce_order_refunded`), dann alle noch gültigen Tickets widerrufen, Kontingent freigeben |

Eingecheckte Tickets werden bei Storno oder Erstattung nicht verändert und bleiben als Einlasshistorie erhalten; die Bestellnotiz weist darauf hin. Erstattungen mit Menge, die eingecheckte Tickets bräuchten, lehnt WooCommerce standardmäßig schon beim Anlegen ab (siehe unten).

### Serien-Preisstufen und gemeinsame Kontingente

**Standard (Einstellung aus):** WooCommerce behält seine eigenen Produktpreise. Preisstufen des Moduls „Serientermine“ (Frühbucher, Last-Minute, Nachfrage, Wochentag/Zeitfenster) wirken dann nur im eigenen Checkout und an der Kasse.

**Pass-Captain-Preise im Warenkorb (Opt-in):** Die globale Einstellung **WooCommerce → Einstellungen → Produkte → Pass Captain → „Pass-Captain-Preise (z. B. Serien-Preisstufen) im WooCommerce-Warenkorb verwenden“** (Option `wpcap_woocommerce_core_prices`, Standard `no`) ersetzt für alle verknüpften Ticketprodukte den WooCommerce-Preis durch `Orders::unit_price()` – den gespeicherten Preis der Ticketkategorie plus Filter `wpcap_ticket_unit_price` (also jede aktive Serien-Preisstufe oder eigene Preislogik). Einzelne Produkte lassen sich mit dem Filter `wpcap_woocommerce_use_core_prices` ( `bool $enabled, WC_Product $product, int $ticket_type_id` ) ausnehmen oder einschließen. Der im WooCommerce-Produkt gepflegte Preis wird dann im Warenkorb ignoriert (für Feeds/Sortierung am besten gleich dem Ticketpreis setzen).

- **Berechnung nur serverseitig:** Grundlage sind ausschließlich die verknüpfte Ticketkategorie und die Warenkorbmenge der Kategorie (Kontext `source` = `woocommerce`, `phase` = `cart`, Produktanzeige `display` mit Menge 1). Der Preis wird bei jedem Laden des Warenkorbs und vor jeder Summenberechnung (`woocommerce_cart_loaded_from_session`, `woocommerce_before_calculate_totals` → `set_price()`) neu ermittelt – im klassischen Warenkorb, Mini-Warenkorb, Checkout und in der Store API (Warenkorb-/Checkout-Block).
- **Steuern:** Pass-Captain-Preise sind Bruttopreise. Bei „Preise inkl. Steuer eingeben“ (oder ohne Steuerberechnung) wird der Bruttopreis direkt gesetzt. Werden Preise in WooCommerce **ohne** Steuer eingegeben, rechnet der Adapter mit den WooCommerce-Basissteuersätzen der Steuerklasse des Produkts auf netto zurück (zusammengesetzte Sätze wie WooCommerce; ohne passenden Satz mit dem Steuersatz der Ticketkategorie). Annahme: Kundschaft am Shop-Standort zahlt damit genau den Pass-Captain-Bruttopreis (Rundung auf 6 Nachkommastellen netto); bei abweichenden Steuersätzen anderer Länder rechnet WooCommerce wie bei jedem Nettopreis.
- **Währung:** Weicht die Währung der Ticketkategorie von der Shopwährung ab, bleibt der WooCommerce-Preis (keine Umrechnung).
- **Anzeige:** Produktseite, Shop-Listen, Produkt-Blöcke und `price_html` der Store API zeigen den wirksamen Preis mit Stufenbezeichnung und – wenn günstiger – „statt“ regulärem Ticketpreis (`woocommerce_get_price_html`). Klassischer Warenkorb und Mini-Warenkorb zeigen Einzelpreis und „statt“ (`woocommerce_cart_item_price`), die Stufe steht als Positionsangabe „Preisstufe: …“ (`woocommerce_get_item_data`, auch im Warenkorb-/Checkout-Block). Für den Block-Warenkorb setzt der Adapter zusätzlich regulären und Angebotspreis der Warenkorbposition, sodass dort „Vorheriger Preis“ und „Spare …“ erscheinen. Nicht angepasst wird das `prices`-Objekt der Store-API-*Produkt*-Endpunkte (`/wc/store/v1/products`); JS-Produktlisten, die nur dieses Objekt nutzen, zeigen weiter den WooCommerce-Preis.
- **Preisänderung zwischen Warenkorb und Bestellung** (z. B. Frühbucherfrist abgelaufen, Nachfragestufe erreicht): Der beim Hinzufügen gezeigte Einzelpreis wird an der Warenkorbposition gemerkt (`wpcap_seen_unit_minor`). Ändert er sich, zeigen klassischer Warenkorb und Checkout-Seite einmalig einen Hinweis „Der Ticketpreis für „…“ hat sich geändert: jetzt X statt bisher Y.“. Beim Absenden der Bestellung (klassisch und Block) bricht eine Änderung den Checkout **einmal** mit dieser Meldung ab und aktualisiert die Summe – niemand zahlt einen Preis, den er nicht gesehen hat; erneutes Absenden bestellt zum neuen Preis. Im Block-Warenkorb aktualisieren sich die Summen ohne zusätzliche Meldung.
- **Reservierung:** unverändert übergibt der Adapter die WooCommerce-Zeilensummen als vertrauenswürdige `unit_prices`/`line_totals`, sodass die Pass-Captain-Bestellung exakt der WooCommerce-Bestellung entspricht (inklusive Gutscheinen). Der Filter `wpcap_ticket_unit_price` läuft bei der Reservierung daher nicht erneut.

Gemeinsame Kontingente einer Serie (Pools) gelten in beiden Fällen auch für WooCommerce: Warenkorbprüfung über `Orders::availability()`, Reservierung und verspätete Zahlung über den Kern-Hook `wpcap_reserve_capacity`.

Die Reservierungsdauer entspricht mindestens der WooCommerce-Einstellung „Lagerbestand halten (Minuten)“ (Standard 60) und lässt sich mit dem Filter `wpcap_woocommerce_hold_minutes` ändern.

## Mehrere Rechnungssteller im Warenkorb

Rechnungssteller („Verkäufer“) eines Tickets ist der Vermittler-Veranstalter des Events, sonst die Plattform (Events ohne Veranstalter und Eigenhändler-Veranstalter; siehe [SALES-MODELS.md](SALES-MODELS.md)). Eine Pass-Captain-Bestellung hat immer genau einen Rechnungssteller. Für WooCommerce-Warenkörbe mit Tickets verschiedener Rechnungssteller gilt die Einstellung **WooCommerce → Einstellungen → Produkte → „Pass Captain – mehrere Rechnungssteller“ → Gemischte Warenkörbe** (Option `wpcap_woocommerce_mixed_sellers`, Filter `wpcap_woocommerce_mixed_seller_policy`):

| Richtlinie | Verhalten |
|---|---|
| **Ablehnen** (`reject`, Standard) | „In den Warenkorb“ lehnt ein Ticket eines anderen Rechnungsstellers mit einer Meldung ab, die beide Rechnungssteller nennt. Warenkorb und Kasse prüfen zusätzlich den ganzen Warenkorb, die Reservierung lehnt gemischte Bestellungen als letzte Stufe ab (`wpcap_mixed_sellers`, kein Kontingent wird gebunden, keine Zahlung). Andere Produkte und Tickets desselben Rechnungsstellers sind nicht betroffen. |
| **Aufteilen** (`split`) | Die WooCommerce-Bestellung ist erlaubt. Beim Reservieren entsteht je Rechnungssteller eine eigene Pass-Captain-Bestellung (alle oder keine: scheitert eine, werden die übrigen sofort storniert). Jede wird von ihrem Rechnungssteller in dessen Nummernkreis abgerechnet, hat eigene Tickets, eigene Ticket-Mail und eigenen Bestelllink. |

Beide Checkout-Wege setzen die Richtlinie durch:

| Prüfung | Klassischer Warenkorb/Checkout | Warenkorb-/Checkout-Block (Store API) |
|---|---|---|
| In den Warenkorb | `woocommerce_add_to_cart_validation` | `woocommerce_store_api_validate_add_to_cart` (RouteException `wpcap_mixed_sellers`, HTTP 400) |
| Warenkorb und Kasse | `woocommerce_check_cart_items` | `woocommerce_store_api_cart_errors` |
| Bestellung anlegen (Reservierung) | `woocommerce_checkout_order_processed` (Exception) | `woocommerce_store_api_checkout_order_processed` (RouteException, HTTP 409) |

### Verbindliche Aufteilungsregeln

Die Regeln gelten für jede WooCommerce-Bestellung, auch mit nur einem Rechnungssteller (ein Topf). Implementierung: `Integrations/WooCommerce/SellerSplit::allocate()`.

1. **Ticketpositionen und Gutscheine:** Betrag je Position = WooCommerce-Zeilensumme nach Gutscheinen plus Zeilensteuer. Gutscheine aller Art (auch „Fester Warenkorbrabatt“) verteilt WooCommerce selbst auf die Positionen; Pass Captain übernimmt diese Verteilung unverändert und verteilt Gutscheinbeträge nie neu.
2. **Negative Gebühren** (Warenkorbrabatte als Gebührenzeile): werden auf die Töpfe „Tickets je Rechnungssteller“ und „Shop“ (Nicht-Ticket-Produkte) im Verhältnis ihrer Bruttobeträge verteilt, danach innerhalb eines Rechnungsstellers auf seine Ticketpositionen (nach Ticketart-ID) im Verhältnis der Zeilenbeträge. Ein Rabatt über der Summe der Töpfe wird auf diese Summe begrenzt.
3. **Positive Gebühren** (Service-, Zahlungsgebühren) und **Versand** gehören dem Shopbetreiber: Sie bleiben in WooCommerce und sind weder Teil einer Pass-Captain-Bestellung noch einer Rechnung eines Veranstalters. Tickets sind virtuell und tragen keinen Versand.
4. **Nicht-Ticket-Produkte** bleiben wie bisher in WooCommerce.
5. **Rundung:** jede Verteilung nach dem Verfahren der größten Reste in Cent (bzw. kleinster Währungseinheit): alle Anteile abrunden, die restlichen Einheiten einzeln an die größten Nachkommareste; bei Gleichstand gewinnt der frühere Topf (Plattform vor Veranstaltern nach Partner-ID, Shop zuletzt; Positionen nach Ticketart-ID). Die Anteile ergeben immer exakt den verteilten Betrag; das Ergebnis ist reproduzierbar.
6. **Steuern:** Die USt einer Position berechnet der Kern aus dem zugeteilten Bruttobetrag und dem Steuersatz der Ticketart; gemischte Steuersätze bleiben je Position erhalten.

Beispiel (in den Integrationstests nachgerechnet): Plattform 10,70 €, Veranstalter A 71,40 €, Veranstalter B 30,00 €, ein Fanartikel 10,00 €, Rabatt −10,00 €, Servicegebühr 2,50 €, Versand 4,90 € (Summe 119,50 €). Rabattanteile 0,87 / 5,85 / 2,46 / Shop 0,82 → Pass-Captain-Bestellungen 9,83 € / 65,55 € / 27,54 €; Shop 16,58 € (10,00 − 0,82 + 2,50 + 4,90).

Die WooCommerce-Bestellung speichert die erste Pass-Captain-Bestellung in `_wpcap_core_order_id`, bei Aufteilung alle in `_wpcap_core_orders` (Rechnungssteller → Bestellung) und je eine Zeile `_wpcap_split_core_id`, die Aufteilung als Schnappschuss in `_wpcap_seller_allocation`. Bestellnotizen, Meta-Box „Pass Captain Tickets“ und Kundenansicht zeigen alle Teilbestellungen mit Rechnungssteller.

### Erstattungen je Rechnungssteller und Position

| WooCommerce-Erstattung | Zuordnung |
|---|---|
| Positionen mit Menge | Jede erstattete Ticketposition gehört zur Pass-Captain-Bestellung, die die Ticketart enthält. Die Menge wählt dort Tickets (gültige zuerst, dann wegen Absage widerrufene, eingecheckte zuletzt und nur wenn erlaubt; jeweils neueste zuerst), der Brutto-Erstattungsbetrag der Position geht an diese Bestellung. Erstattete Gebühren und Versand bleiben beim Shop. |
| Nur Betrag | Ticketanteil = Betrag × (Summe der Pass-Captain-Bestellungen / WooCommerce-Gesamtsumme), verteilt auf die Teilbestellungen im Verhältnis ihres noch erstattbaren Betrags (größte Reste, Gleichstand nach Reihenfolge der Rechnungssteller). Es werden keine Tickets widerrufen. |
| Letzte Erstattung (Bestellung vollständig erstattet) | jede Teilbestellung erhält alle verbleibenden (erlaubten) Tickets und ihren Restbetrag und ist danach genau erstattet. |

Jede Teilbestellung bucht über `Refunds::record_external()` (idempotent je WooCommerce-Erstattung und Teilbestellung); die Rechnungskorrektur stellt der Rechnungssteller der Teilbestellung aus. Beträge werden auf den Restbetrag der Teilbestellung begrenzt. Schlägt eine Teilbuchung mit einem vorübergehenden Datenbankfehler fehl, wiederholt der Job `woocommerce_refund_sync` die ganze Erstattung; bereits gebuchte Teilbestellungen werden dabei übersprungen.

**Eingecheckte Tickets:** Standard ist Ablehnen. Eine Erstattung mit Menge, die nur mit eingecheckten Tickets erfüllt werden könnte, wird schon beim Anlegen in WooCommerce abgelehnt (`woocommerce_create_refund`, vor der Auszahlung über den Zahlungsanbieter) – mit Hinweis auf die Einstellung. Eine reine Betragserstattung bleibt möglich. Übersteuerung durch Administratoren: Einstellung **„Eingecheckte Tickets erstatten“** (Option `wpcap_woocommerce_refund_checked_in`) bzw. Filter `wpcap_woocommerce_refund_checked_in`; gibt es im Kern die Einstellung `refund_after_checkin`, hat sie Vorrang. Wird eine Erstattung an der Prüfung vorbei angelegt (z. B. programmatisch), bucht Pass Captain den Betrag, markiert eingecheckte Tickets aber nicht als erstattet und vermerkt das in der Bestellnotiz.

**Veranstaltungsabsagen:** Der Kern widerruft die Tickets des abgesagten Termins; die WooCommerce-Bestellung erhält eine Notiz mit der betroffenen Teilbestellung und dem Rechnungssteller. Die anschließende WooCommerce-Erstattung dieser Positionen (mit Menge) erstattet genau die widerrufenen Tickets dieser Teilbestellung.

**Veranstalter-Abrechnungen:** Da jede Teilbestellung nur einen Rechnungssteller hat, erscheinen Verkäufe und Erstattungen in der Abrechnung des jeweiligen Veranstalters (`Partners/Settlements`). Erstattungen nach einem abgeschlossenen Monat werden in der nächsten Abrechnung als Zeile „Korrektur Erstattung (Vorperiode)“ geführt.

## Angaben zu Teilnehmenden und Saalpläne

Hat eine Ticketkategorie Teilnehmerfelder, fragt der Checkout je Ticket Name, E-Mail (beide optional) und die Felder ab; Pflichtfelder werden geprüft, bevor reserviert wird.

- **Klassischer Checkout** (Shortcode): Felder nach den Bestellnotizen (`woocommerce_after_order_notes`), Prüfung in `woocommerce_after_checkout_validation`.
- **Checkout-Block:** ein kleiner innerer Block im Schritt „Kontaktinformationen“ (`assets/woocommerce/attendees.js`, ohne Build). Der Warenkorb liefert die nötigen Felder über die Store API (`extensions.wpcap.attendee_types`), die Antworten gehen als Checkout-Erweiterungsdaten (`extensions.wpcap.attendees`, JSON) zurück. Fehlende Pflichtangaben sperren „Bestellung aufgeben“ über den Validierungs-Store von WooCommerce; der Server prüft erneut.

Die Antworten liegen bis zur Ticketausstellung als Bestellmeta `_wpcap_attendees` an der WooCommerce-Bestellung und werden danach dort gelöscht (sie sind dann am Ticket gespeichert). Mit dem Filter `wpcap_woocommerce_collect_attendees` ( `bool $collect, int $type_id` ) lassen sich Name/E-Mail auch für Kategorien ohne Felder abfragen.

Ticketkategorien mit Saalplan sind über WooCommerce kaufbar: WooCommerce zeigt keine Platzwahl, der Kern vergibt beim Reservieren automatisch die besten freien Plätze der zugeordneten Zonen. Der Warenkorb prüft vorab, ob genug passende Plätze frei sind.

## Anzeige und Verwaltung

- Danke-Seite und „Mein Konto → Bestellungen“ zeigen die Tickets mit Status. Die Ticket-Links mit QR-Code kommen per E-Mail, weil nur Hashes der Ticketcodes gespeichert werden.
- Die Bestellansicht im Admin hat eine Meta-Box „Pass Captain Tickets“ und die Bestellaktionen **Tickets erneut senden (neue Codes)** und **Tickets erneut ausstellen** (z. B. nachdem eine `needs_review`-Bestellung durch Freigabe von Kontingent geklärt wurde). Beide erfordern die Berechtigung `wpcap_manage_orders`.

## Grenzen

- Kampagnencodes von Pass Captain gelten nicht im WooCommerce-Checkout; Rabatte laufen dort über WooCommerce-Gutscheine. Gespeichert wird der tatsächlich gezahlte Bruttobetrag je Ticket.
- Teilerstattungen nur über einen Betrag ohne Mengenangabe widerrufen keine Tickets (der Betrag wird gebucht). Für einen Widerruf die Menge in der Erstattung angeben.
- Erstattete (widerrufene) Tickets geben ihr Kontingent frei; eingecheckte Tickets bleiben eingecheckt und werden nur als erstattet markiert, wenn die Erstattung eingecheckter Tickets erlaubt ist.
- Bei aufgeteilten Bestellungen erhält die Kundschaft je Rechnungssteller eine eigene Ticket-Mail, einen eigenen Bestelllink und eine eigene Rechnung. Gebühren und Versand stellt der Shop selbst in Rechnung (außerhalb von Pass Captain).
- Bei aktivierten Pass-Captain-Preisen gelten WooCommerce-Angebotspreise (auch zeitgesteuerte) für Ticketprodukte nicht; Rabatte über Preisstufen oder WooCommerce-Gutscheine abbilden.
- Getestet mit den Integrationstests in `tests/integration/` (MariaDB plus WooCommerce-Attrappen). Pass-Captain-Preise im Warenkorb (Frühbucherstufe, Ablauf der Stufe zwischen Warenkorb und Bestellung, Preise inkl./exkl. Steuer) wurden zusätzlich mit WordPress 7.1 und WooCommerce 11.3 im klassischen und im Block-Warenkorb/-Checkout geprüft. Eine Abnahme mit echten Zahlungsanbietern steht aus.
