# WooCommerce-Adapter

Der Adapter ist optional und wird nur geladen, wenn WooCommerce aktiv ist. Er nutzt ausschließlich die CRUD-APIs von WooCommerce (`wc_get_order()`, Bestell-/Produktmetadaten) und ist mit HPOS und dem klassischen Bestellspeicher sowie mit Cart/Checkout Blocks kompatibel deklariert. WP Pass Captain greift nie direkt auf WooCommerce-Tabellen zu.

## Einrichtung

1. In WP Pass Captain Event, Termin und Ticketkategorie mit Kontingent anlegen.
2. In WooCommerce ein (virtuelles) Produkt anlegen und im Tab „Allgemein“ unter **Pass Captain Ticketkategorie** die Kategorie wählen. Bei variablen Produkten erben Varianten die Kategorie des Elternprodukts.
3. Den Preis in WooCommerce pflegen. Kontingent, Verkaufsfenster und Höchstmenge je Bestellung kommen aus WP Pass Captain. Die WooCommerce-Lagerverwaltung für Ticketprodukte nicht zusätzlich aktivieren, damit es nur eine Quelle für das Kontingent gibt.

## Ablauf

| WooCommerce | WP Pass Captain |
|---|---|
| In den Warenkorb / Menge ändern / Kasse | Prüfung von Verkaufsstatus, Verkaufsfenster, Höchstmenge, Restkontingent und – bei Saalplänen – freien passenden Plätzen (nicht sperrend). Im Warenkorb-Block (Store API) wird die Höchstmenge der Mengenauswahl begrenzt und `update-item` über dem Kontingent abgelehnt |
| Bestellung angelegt, vor der Zahlung (`woocommerce_checkout_order_processed`, `woocommerce_store_api_checkout_order_processed`) | Bestellung mit Reservierung anlegen (atomar, mit Zeilensperren). Kein Kontingent → Checkout bricht vor der Zahlung ab |
| Erneuter Checkout derselben offenen Bestellung | vorherige unbezahlte Reservierung wird storniert, neue angelegt |
| `processing` / `completed` / `payment_complete` | Reservierung umwandeln, je Einheit genau ein Ticket ausstellen, Tickets und persönlichen Bestelllink mailen |
| `processing` bei Nachnahme (`cod`) | noch keine Tickets; Reservierung auf 14 Tage verlängern (Filter `wpcap_woocommerce_deferred_hold_minutes`). Ausgestellt wird erst bei `completed`. Liste der Zahlarten: Filter `wpcap_woocommerce_issue_on_completed_gateways` (Standard `array( 'cod' )`) |
| Zahlung nach Ablauf der Reservierung | Kontingent erneut prüfen; reicht es nicht, wird die Bestellung `needs_review` / `paid_unallocated`, es werden keine Tickets ausgestellt und der Admin wird per E-Mail informiert |
| `on-hold` (z. B. Überweisung) | Reservierung auf 3 Tage verlängern (Filter `wpcap_woocommerce_on_hold_minutes`) |
| `failed` / `cancelled` | Reservierung freigeben, gültige Tickets widerrufen. Kommt später doch eine Zahlung, wird neu reserviert |
| Erstattung (`woocommerce_order_refunded`) | je WooCommerce-Erstattung genau einmal: Erstattung im Pass-Captain-Erstattungsjournal buchen (Zeile in `wpcap_refunds`, erstatteter Betrag der Bestellung, Tickets als erstattet markiert und – sofern gültig – widerrufen, Kontingent frei, Gutschrift, `wpcap_refund_completed`). Mengen wählen die Tickets (gültige zuerst), der Betrag ist der Brutto-Erstattungsbetrag der Ticketpositionen; reine Betragserstattungen werden anteilig den Tickets zugerechnet. Benötigt `Refunds::record_external()` im Kern; ohne diese Methode werden wie bisher nur die erstatteten Tickets widerrufen |
| `refunded` | offene WooCommerce-Erstattungen zuerst buchen (WooCommerce setzt den Status vor `woocommerce_order_refunded`), dann alle noch gültigen Tickets widerrufen, Kontingent freigeben |

Eingecheckte Tickets werden bei Storno oder Erstattung nicht verändert und bleiben als Einlasshistorie erhalten; die Bestellnotiz weist darauf hin.

### Serien-Preisstufen und gemeinsame Kontingente

WooCommerce behält seine eigenen Produktpreise: Der Adapter übergibt die WooCommerce-Zeilenpreise als vertrauenswürdige `unit_prices`, die Vorrang vor dem Filter `wpcap_ticket_unit_price` haben. Preisstufen des Moduls „Serientermine“ (Frühbucher, Last-Minute, Nachfrage, Wochentag/Zeitfenster) wirken daher nur im eigenen Checkout und an der Kasse; abweichende Preise in WooCommerce bitte dort pflegen (z. B. Sale-Preis mit Zeitraum). Gemeinsame Kontingente einer Serie (Pools) gelten dagegen auch für WooCommerce: Warenkorbprüfung über `Orders::availability()`, Reservierung und verspätete Zahlung über den Kern-Hook `wpcap_reserve_capacity`. Der Adapter selbst wurde dafür nicht geändert.

Die Reservierungsdauer entspricht mindestens der WooCommerce-Einstellung „Lagerbestand halten (Minuten)“ (Standard 60) und lässt sich mit dem Filter `wpcap_woocommerce_hold_minutes` ändern.

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

- Kampagnencodes von WP Pass Captain gelten nicht im WooCommerce-Checkout; Rabatte laufen dort über WooCommerce-Gutscheine. Gespeichert wird der tatsächlich gezahlte Bruttobetrag je Ticket.
- Teilerstattungen nur über einen Betrag ohne Mengenangabe widerrufen keine Tickets (der Betrag wird gebucht). Für einen Widerruf die Menge in der Erstattung angeben.
- Erstattete (widerrufene) Tickets geben ihr Kontingent frei; eingecheckte Tickets bleiben eingecheckt und werden nur als erstattet markiert.
- Getestet mit den Integrationstests in `tests/integration/` (MariaDB plus WooCommerce-Attrappen). Eine Abnahme in einer echten WooCommerce-Installation mit Zahlungsanbietern steht aus.
