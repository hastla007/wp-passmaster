# WooCommerce-Adapter

Der Adapter ist optional und wird nur geladen, wenn WooCommerce aktiv ist. Er nutzt ausschließlich die CRUD-APIs von WooCommerce (`wc_get_order()`, Bestell-/Produktmetadaten) und ist mit HPOS und dem klassischen Bestellspeicher sowie mit Cart/Checkout Blocks kompatibel deklariert. WP Pass Captain greift nie direkt auf WooCommerce-Tabellen zu.

## Einrichtung

1. In WP Pass Captain Event, Termin und Ticketkategorie mit Kontingent anlegen.
2. In WooCommerce ein (virtuelles) Produkt anlegen und im Tab „Allgemein“ unter **Pass Captain Ticketkategorie** die Kategorie wählen. Bei variablen Produkten erben Varianten die Kategorie des Elternprodukts.
3. Den Preis in WooCommerce pflegen. Kontingent, Verkaufsfenster und Höchstmenge je Bestellung kommen aus WP Pass Captain. Die WooCommerce-Lagerverwaltung für Ticketprodukte nicht zusätzlich aktivieren, damit es nur eine Quelle für das Kontingent gibt.

## Ablauf

| WooCommerce | WP Pass Captain |
|---|---|
| In den Warenkorb / Menge ändern / Kasse | Prüfung von Verkaufsstatus, Verkaufsfenster, Höchstmenge und Restkontingent (nicht sperrend) |
| Bestellung angelegt, vor der Zahlung (`woocommerce_checkout_order_processed`, `woocommerce_store_api_checkout_order_processed`) | Bestellung mit Reservierung anlegen (atomar, mit Zeilensperren). Kein Kontingent → Checkout bricht vor der Zahlung ab |
| Erneuter Checkout derselben offenen Bestellung | vorherige unbezahlte Reservierung wird storniert, neue angelegt |
| `processing` / `completed` / `payment_complete` | Reservierung umwandeln, je Einheit genau ein Ticket ausstellen, Tickets und persönlichen Bestelllink mailen |
| Zahlung nach Ablauf der Reservierung | Kontingent erneut prüfen; reicht es nicht, wird die Bestellung `needs_review` / `paid_unallocated`, es werden keine Tickets ausgestellt und der Admin wird per E-Mail informiert |
| `on-hold` (z. B. Überweisung) | Reservierung auf 3 Tage verlängern (Filter `wpcap_woocommerce_on_hold_minutes`) |
| `failed` / `cancelled` | Reservierung freigeben, gültige Tickets widerrufen. Kommt später doch eine Zahlung, wird neu reserviert |
| Teilerstattung mit Mengen | erstattete Anzahl gültiger Tickets der Kategorie widerrufen (je Erstattung nur einmal) |
| `refunded` | alle noch gültigen Tickets widerrufen, Kontingent freigeben |

Eingecheckte Tickets werden bei Storno oder Erstattung nicht verändert und bleiben als Einlasshistorie erhalten; die Bestellnotiz weist darauf hin.

Die Reservierungsdauer entspricht mindestens der WooCommerce-Einstellung „Lagerbestand halten (Minuten)“ (Standard 60) und lässt sich mit dem Filter `wpcap_woocommerce_hold_minutes` ändern.

## Anzeige und Verwaltung

- Danke-Seite und „Mein Konto → Bestellungen“ zeigen die Tickets mit Status. Die Ticket-Links mit QR-Code kommen per E-Mail, weil nur Hashes der Ticketcodes gespeichert werden.
- Die Bestellansicht im Admin hat eine Meta-Box „Pass Captain Tickets“ und die Bestellaktionen **Tickets erneut senden (neue Codes)** und **Tickets erneut ausstellen** (z. B. nachdem eine `needs_review`-Bestellung durch Freigabe von Kontingent geklärt wurde). Beide erfordern die Berechtigung `wpcap_manage_orders`.

## Grenzen

- Kampagnencodes von WP Pass Captain gelten nicht im WooCommerce-Checkout; Rabatte laufen dort über WooCommerce-Gutscheine. Gespeichert wird der tatsächlich gezahlte Bruttobetrag je Ticket.
- Teilerstattungen nur über einen Betrag ohne Mengenangabe widerrufen keine Tickets. Für einen Widerruf die Menge in der Erstattung angeben.
- Teilweise erstattete Tickets geben ihr Kontingent nicht frei (wie im Kern). Erst eine Vollerstattung gibt es frei.
- Getestet mit den Integrationstests in `tests/integration/` (MariaDB plus WooCommerce-Attrappen). Eine Abnahme in einer echten WooCommerce-Installation mit Zahlungsanbietern steht aus.
