# Conversion-Messung ohne Tracker

Pass Captain lädt **keinen** Tracker und setzt keine Cookies für Marketing. Der eigene Checkout löst
stattdessen datensparsame JavaScript-Ereignisse aus (`assets/tracking.js`, wird mit der Eventliste und der
Eventseite geladen). Die Website entscheidet – nach Einwilligung, wo nötig –, ob sie diese Ereignisse an
ein Analysewerkzeug weitergibt.

## Ereignis `wpcap:track`

Auf `document`, `event.detail`:

| Feld | Inhalt |
|---|---|
| `event` | `view_item` (Ticketauswahl angezeigt), `add_to_cart` / `remove_from_cart` (Menge geändert, Differenz), `begin_checkout` (Bestellung wird abgesendet), `purchase` (Bestellung ohne Weiterleitung abgeschlossen) |
| `currency` | ISO-Währung der Ticketart |
| `value` | Summe in Währungseinheiten (bei `purchase` der Bestellbetrag nach Rabatt) |
| `items` | `[{ item_id (Ticketart-ID), item_name, price, quantity }]` |
| `transaction_id` | nur `purchase`: kurze Bestellnummer (`3F2A-9C1E`), nicht die Bestell-ID des Portallinks |

Keine E-Mail-Adressen, Namen, Teilnehmerdaten oder Zugangstokens. Bei Zahlungen mit Weiterleitung
(Stripe, PayPal, Mollie) gibt es `begin_checkout`, aber kein `purchase` im Browser.

Beispiel (Weitergabe an einen vorhandenen Tag-Manager, erst nach Einwilligung):

```js
document.addEventListener('wpcap:track', function (e) {
	if (!window.dataLayer || !window.myConsent || !window.myConsent.statistics) return;
	window.dataLayer.push({ event: e.detail.event, ecommerce: { currency: e.detail.currency, value: e.detail.value, items: e.detail.items, transaction_id: e.detail.transaction_id } });
});
```

## Herkunft (UTM/Referrer) an der Bestellung

`utm_source`, `utm_medium` und `utm_campaign` der Einstiegsseite sowie der Host der verweisenden Seite
werden für die Browsersitzung gemerkt (`sessionStorage`) und mit der Bestellung gesendet. Der Server
speichert nur diese vier Schlüssel, reduziert auf `a–z 0–9 _ . -` und 64 Zeichen, als
`source=…;medium=…;campaign=…;ref=…` in `orders.attribution` (nur öffentlicher Checkout, nicht Kasse oder
Adapter), zeigt sie in der Bestelldetailansicht („Herkunft“) und löscht sie nach 90 Tagen
(`Privacy\Retention`, Schritt `attribution`, Filter `wpcap_privacy_retention_days`).

Ein Einbettungs-Widget (iFrame mit Höhenanpassung) ist nicht Teil des Kerns.
